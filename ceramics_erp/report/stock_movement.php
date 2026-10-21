<?php
// report/stock_movement.php -- Stock Movement Register
// Summary : product-wise  Opening + (Purchase / Sales Return / Other In) - (Delivery / Purchase Return / Other Out) = Closing
// Details : voucher-wise movement (date, type, voucher no, party, in/out, product-wise running balance)
// Source  : erp_stock_ledger (+ GRN / Challan / Return / Adjustment / Transfer er tarikh ar party)
include('../auth.php'); require_page_access();
include('../config.php');
try { include_once('../Settings/settings_lib.php'); } catch (Throwable $e) {}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }
function n2($v) { return number_format((float)$v, 2); }
function has_col($conn, $t, $c) {
    try { $q = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . esc($conn, $c) . "'"); return $q && mysqli_num_rows($q) > 0; }
    catch (Throwable $e) { return false; }
}
function has_table($conn, $t) {
    try { $q = mysqli_query($conn, "SHOW TABLES LIKE '" . esc($conn, $t) . "'"); return $q && mysqli_num_rows($q) > 0; }
    catch (Throwable $e) { return false; }
}
function opts($conn, $table, $col, $sel) {
    $o = '';
    try {
        $q = mysqli_query($conn, "SELECT DISTINCT $col FROM $table WHERE $col != '' ORDER BY $col");
        while ($q && $x = mysqli_fetch_assoc($q)) {
            $v = h($x[$col]);
            $o .= "<option value=\"$v\"" . (trim($sel) !== '' && strcasecmp(trim($sel), trim($x[$col])) === 0 ? ' selected' : '') . ">$v</option>";
        }
    } catch (Throwable $e) {}
    return $o;
}
function box_loose($stock, $r) {
    $bp = (int)$r['box_to_pcs']; $rp = (float)$r['ratio_pcs']; $ro = (float)$r['ratio_output'];
    $per = ($rp > 0 && $ro > 0) ? $ro / $rp : 0;
    if ($per <= 0 || $bp <= 0) return [0, 0];
    $tp = (int)round(abs($stock) / $per);
    $box = intdiv($tp, $bp); $loose = $tp % $bp;
    return $stock < 0 ? [-$box, -$loose] : [$box, $loose];
}
function vt_label($t) {
    $m = ['PURCHASE' => 'Purchase (GRN)', 'SALES' => 'Delivery', 'DELIVERY' => 'Delivery', 'SALES_RETURN' => 'Sales Return', 'PUR_RETURN' => 'Purchase Return',
          'ADJ_OVER' => 'Stock Over Adjust', 'ADJ_SHORT' => 'Stock Short Adjust', 'ADJ_DAMAGE' => 'Product Damage',
          'TRANSFER_IN' => 'Transfer In', 'TRANSFER_OUT' => 'Transfer Out'];
    return $m[$t] ?? $t;
}

// Type filter group => voucher_type list
$TYPE_GROUPS = [
    ''         => ['All Types', []],
    'purchase' => ['Purchase (GRN)', ['PURCHASE']],
    'sales'    => ['Delivery (Sales)', ['DELIVERY', 'SALES']],
    'sret'     => ['Sales Return', ['SALES_RETURN']],
    'pret'     => ['Purchase Return', ['PUR_RETURN']],
    'adj'      => ['Adjustment / Damage', ['ADJ_OVER', 'ADJ_SHORT', 'ADJ_DAMAGE']],
    'transfer' => ['Stock Transfer', ['TRANSFER_IN', 'TRANSFER_OUT']],
];

// ---------- ফিল্টার ----------
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($to < $from) { $t = $from; $from = $to; $to = $t; }
$f_branch = (int)($_GET['branch_id'] ?? 0);
$f_wh     = (int)($_GET['warehouse_id'] ?? 0);
$group    = trim($_GET['group'] ?? '');
$category = trim($_GET['category'] ?? '');
$brand    = trim($_GET['brand'] ?? '');
$size     = trim($_GET['size'] ?? '');
$color    = trim($_GET['color'] ?? '');
$q        = trim($_GET['q'] ?? '');
$tkey     = $_GET['type'] ?? '';
if (!isset($TYPE_GROUPS[$tkey])) $tkey = '';
$typeVts  = $TYPE_GROUPS[$tkey][1];
$view     = (($_GET['view'] ?? '') === 'details') ? 'details' : 'summary';
$pid      = (int)($_GET['product_id'] ?? 0);
$hide0    = !isset($_GET['from']) || isset($_GET['hide0']);

// ---------- ওয়্যারহাউস স্কোপ ----------
$hasWh = has_col($conn, 'erp_stock_ledger', 'warehouse_id');
$whCond = '';
if ($hasWh) {
    if ($f_wh > 0)         $whCond = " AND s.warehouse_id = $f_wh";
    elseif ($f_branch > 0) $whCond = " AND s.warehouse_id IN (SELECT id FROM erp_warehouses WHERE branch_id = $f_branch)";
}
$branch_list = []; $wh_list = [];
try {
    $bq = mysqli_query($conn, "SELECT id, branch_name FROM erp_branches WHERE status='Active' ORDER BY branch_name");
    while ($bq && $b = mysqli_fetch_assoc($bq)) $branch_list[] = $b;
    $wq = mysqli_query($conn, "SELECT id, warehouse_name FROM erp_warehouses WHERE status='Active'" . ($f_branch > 0 ? " AND branch_id = $f_branch" : "") . " ORDER BY warehouse_name");
    while ($wq && $w = mysqli_fetch_assoc($wq)) $wh_list[] = $w;
} catch (Throwable $e) {}
$scope_label = 'All Branch / All Warehouse'; $branch_name = '';
if ($f_wh > 0) { foreach ($wh_list as $w) if ((int)$w['id'] === $f_wh) $scope_label = 'Warehouse: ' . $w['warehouse_name']; }
if ($f_branch > 0) { foreach ($branch_list as $b) if ((int)$b['id'] === $f_branch) { $branch_name = $b['branch_name']; if ($f_wh <= 0) $scope_label = 'Branch: ' . $b['branch_name']; } }

// ---------- ভাউচারের তারিখ ও পার্টি (GRN / Challan / Return / Adjustment / Transfer) ----------
$joins = " LEFT JOIN erp_purchase_grn g ON s.voucher_type = 'PURCHASE' AND g.grn_no = s.voucher_no
           LEFT JOIN erp_suppliers gsu ON gsu.id = g.supplier_id
           LEFT JOIN erp_delivery_challan_master dc ON s.voucher_type IN ('DELIVERY','SALES') AND dc.challan_no = s.voucher_no
           LEFT JOIN erp_customers dcu ON dcu.id = dc.customer_id";
$dparts = ['g.grn_date', 'dc.challan_date']; $pparts = ['gsu.supplier_name', 'dcu.customer_name'];
if (has_table($conn, 'erp_sales_return')) {
    $joins .= " LEFT JOIN erp_sales_return sr ON s.voucher_type = 'SALES_RETURN' AND sr.return_no = s.voucher_no
                LEFT JOIN erp_customers srcu ON srcu.id = sr.customer_id";
    $dparts[] = 'sr.return_date'; $pparts[] = 'srcu.customer_name';
}
if (has_table($conn, 'erp_purchase_return')) {
    $joins .= " LEFT JOIN erp_purchase_return pr ON s.voucher_type = 'PUR_RETURN' AND pr.return_no = s.voucher_no
                LEFT JOIN erp_suppliers prsu ON prsu.id = pr.supplier_id";
    $dparts[] = 'pr.return_date'; $pparts[] = 'prsu.supplier_name';
}
if (has_table($conn, 'erp_stock_adjustment')) {
    $joins .= " LEFT JOIN erp_stock_adjustment aj ON s.voucher_type IN ('ADJ_OVER','ADJ_SHORT','ADJ_DAMAGE') AND aj.adj_no = s.voucher_no";
    $dparts[] = 'aj.adj_date';
}
if (has_table($conn, 'erp_stock_transfer')) {
    $joins .= " LEFT JOIN erp_stock_transfer tr ON s.voucher_type IN ('TRANSFER_IN','TRANSFER_OUT') AND tr.transfer_no = s.voucher_no";
    $dparts[] = 'tr.transfer_date';
}
$dateExpr  = "DATE(COALESCE(" . implode(', ', $dparts) . ", s.created_at))";
$partyExpr = "COALESCE(" . implode(', ', $pparts) . ")";

// ---------- পণ্য ফিল্টার ----------
$where = ['1=1'];
foreach ([['p.group_name', $group], ['p.category_name', $category], ['p.brand_name', $brand], ['p.size_name', $size], ['p.color_name', $color]] as $e) {
    if ($e[1] !== '') $where[] = $e[0] . " = '" . esc($conn, $e[1]) . "'";
}
if ($q !== '') { $l = esc($conn, $q); $where[] = "(p.item_name LIKE '%$l%' OR p.item_code LIKE '%$l%')"; }
if ($pid > 0) $where[] = "p.id = $pid";
$whereSql = implode(' AND ', $where);

// ---------- হেডিং (প্রিন্ট) ----------
$co = []; $br = null;
try { $co = company_info($conn); if ($branch_name !== '' && function_exists('branch_info')) $br = branch_info($conn, $branch_name); } catch (Throwable $e) {}
$head_name  = trim($br['branch_name'] ?? '') ?: (trim($co['company_name'] ?? '') ?: 'UTTARA BUILDERS');
$head_phone = trim($br['phone'] ?? '');

$qs = $_GET; unset($qs['view'], $qs['product_id']);
$err = '';
$G = ['open' => 0, 'in' => 0, 'out' => 0, 'close' => 0];
$rows = []; $det = []; $prodInfo = null; $truncated = false; $T = ['in' => 0, 'out' => 0];

try {
if ($view === 'summary') {
    $sql = "SELECT p.id, p.item_code, p.item_name, p.size_name, p.color_name, p.brand_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output, x.vt,
                   COALESCE(SUM(CASE WHEN x.d < '$from' THEN x.net END), 0) AS op,
                   COALESCE(SUM(CASE WHEN x.d >= '$from' AND x.d <= '$to' THEN x.qin END), 0) AS qin,
                   COALESCE(SUM(CASE WHEN x.d >= '$from' AND x.d <= '$to' THEN x.qout END), 0) AS qout
            FROM erp_products p
            LEFT JOIN (SELECT s.product_id, s.voucher_type AS vt, (s.sqft_in - s.sqft_out) AS net,
                              GREATEST(s.sqft_in, 0) AS qin, s.sqft_out + GREATEST(-s.sqft_in, 0) AS qout, $dateExpr AS d
                       FROM erp_stock_ledger s $joins WHERE 1=1 $whCond) x ON x.product_id = p.id
            WHERE $whereSql
            GROUP BY p.id, x.vt
            ORDER BY p.item_name ASC, p.id ASC";
    $res = mysqli_query($conn, $sql);
    $P = [];
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $id = (int)$r['id'];
        if (!isset($P[$id])) $P[$id] = $r + ['open' => 0, 'net' => 0, 'pur' => 0, 'sret' => 0, 'oin' => 0, 'dlv' => 0, 'pret' => 0, 'oout' => 0];
        $P[$id]['open'] += (float)$r['op'];
        $vt = (string)$r['vt'];
        if ($vt === '') continue;
        $qi = (float)$r['qin']; $qo = (float)$r['qout'];
        $P[$id]['net'] += $qi - $qo;                                    // asol closing er jonno (type filter er age)
        if ($typeVts && !in_array($vt, $typeVts, true)) continue;
        if ($vt === 'PURCHASE')          $P[$id]['pur']  += $qi; elseif ($vt === 'SALES_RETURN') $P[$id]['sret'] += $qi; else $P[$id]['oin']  += $qi;
        if ($vt === 'DELIVERY' || $vt === 'SALES') $P[$id]['dlv'] += $qo; elseif ($vt === 'PUR_RETURN') $P[$id]['pret'] += $qo; else $P[$id]['oout'] += $qo;
    }
    foreach ($P as $r) {
        $open = round($r['open'], 2);
        $tin = round($r['pur'] + $r['sret'] + $r['oin'], 2); $tout = round($r['dlv'] + $r['pret'] + $r['oout'], 2);
        if ($hide0 && abs($open) < 0.005 && abs($tin) < 0.005 && abs($tout) < 0.005 && abs($r['net']) < 0.005) continue;
        $close = round($open + $r['net'], 2);
        list($bx, $ls) = box_loose($close, $r);
        $r['open'] = $open; $r['tin'] = $tin; $r['tout'] = $tout; $r['close'] = $close; $r['bx'] = $bx; $r['ls'] = $ls;
        $rows[] = $r;
        $G['open'] += $open; $G['in'] += $tin; $G['out'] += $tout; $G['close'] += $close;
    }
} else {
    // শুরুর ব্যালেন্স (প্রতি পণ্য)
    $open = [];
    $oq = mysqli_query($conn, "SELECT s.product_id, SUM(s.sqft_in - s.sqft_out) AS o
        FROM erp_stock_ledger s JOIN erp_products p ON p.id = s.product_id $joins
        WHERE $whereSql $whCond AND $dateExpr < '$from' GROUP BY s.product_id");
    while ($oq && $x = mysqli_fetch_assoc($oq)) $open[(int)$x['product_id']] = (float)$x['o'];

    $lim = 5000;
    $lr = mysqli_query($conn, "SELECT s.id, s.product_id, s.voucher_type AS vt, s.voucher_no, s.sqft_in, s.sqft_out, $dateExpr AS d, $partyExpr AS party,
            p.item_name, p.size_name, p.color_name, " . ($hasWh ? "w.warehouse_name" : "''") . " AS wh
        FROM erp_stock_ledger s JOIN erp_products p ON p.id = s.product_id
        " . ($hasWh ? "LEFT JOIN erp_warehouses w ON w.id = s.warehouse_id" : "") . " $joins
        WHERE $whereSql $whCond AND $dateExpr BETWEEN '$from' AND '$to'
        ORDER BY d ASC, s.id ASC LIMIT " . ($lim + 1));
    $all = [];
    while ($lr && $x = mysqli_fetch_assoc($lr)) $all[] = $x;
    if (count($all) > $lim) { array_pop($all); $truncated = true; }

    $bal = $open;
    foreach ($all as $x) {
        $p = (int)$x['product_id'];
        $in = (float)$x['sqft_in']; $out = (float)$x['sqft_out'];
        $x['qin'] = max($in, 0); $x['qout'] = $out + max(-$in, 0);
        $bal[$p] = ($bal[$p] ?? 0) + $in - $out;
        $x['bal'] = $bal[$p];
        if ($typeVts && !in_array($x['vt'], $typeVts, true)) continue;
        $det[] = $x;
        $T['in'] += $x['qin']; $T['out'] += $x['qout'];
    }
    if ($pid > 0) {
        $st = $conn->prepare("SELECT item_code, item_name, size_name, color_name, brand_name FROM erp_products WHERE id = ?");
        $st->bind_param("i", $pid); $st->execute(); $prodInfo = $st->get_result()->fetch_assoc(); $st->close();
        $G['open'] = $open[$pid] ?? 0;
    }
}
} catch (Throwable $e) { $err = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stock Movement Register</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 10px; margin: 0; color: #333; }
    .cards { display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 150px; background: #fff; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 19px; font-weight: bold; margin-top: 4px; }
    .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 10px; flex-wrap: wrap; }
    .grid-title { font-size: 15px; font-weight: bold; color: #2e7d32; text-transform: uppercase; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px 15px; margin-bottom: 10px; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .btn { padding: 6px 15px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; height: 30px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .btn-show { background: #2e7d32; } .btn-clear { background: #6c757d; } .btn-print { background: #dc3545; } .btn-back { background: #2b579a; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #cddc39; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 6px 8px; border: 1px solid #dee2e6; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .grid-table tbody tr.lnk:hover td { background: #eef6ff; cursor: pointer; }
    .r { text-align: right; } .c { text-align: center; }
    .neg { color: #d32f2f !important; }
    .in { color: #28a745; } .out { color: #d32f2f; }
    .open-row td { background: #fff3e0 !important; font-weight: bold; }
    .total td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; background: #e3f2fd; color: #0d47a1; white-space: nowrap; }
    .tag.o { background: #ffebee; color: #b71c1c; } .tag.i { background: #e8f5e9; color: #1b5e20; }
    .note { font-size: 11px; color: #777; margin-top: 6px; }
    .err { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
    .warn { background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; font-size: 12px; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print { .filter-panel, .btn, .no-print { display: none !important; } .print-head { display: block; } body { background: #fff; padding: 0; } }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;"><?php echo h($head_name); ?></h2>
    <?php if ($head_phone !== '') echo '<div style="font-size:12px;color:#555;">Phone: ' . h($head_phone) . '</div>'; ?>
    <div style="font-weight:bold;">Stock Movement Register (<?php echo $view === 'details' ? 'Details' : 'Summary'; ?>): <?php echo date('d-M-Y', strtotime($from)); ?> to <?php echo date('d-M-Y', strtotime($to)); ?></div>
    <div style="font-size:12px;color:#1b5e20;font-weight:bold;"><?php echo h($scope_label); ?><?php if ($tkey !== '') echo ' | ' . h($TYPE_GROUPS[$tkey][0]); ?></div>
</div>

<?php if ($err !== '') echo '<div class="err">ত্রুটি: ' . h($err) . '</div>'; ?>

<div class="title-row">
    <div class="grid-title">Stock Movement Register <span style="font-size:12px;color:#555;text-transform:none;">— <?php echo h($scope_label); ?></span></div>
    <div style="display:flex; gap:8px;">
        <?php if ($view === 'details' && $pid > 0) { ?>
            <a class="btn btn-back" href="stock_movement.php?<?php echo h(http_build_query($qs)); ?>"><i class="fa-solid fa-arrow-left"></i> Back to Summary</a>
        <?php } ?>
        <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Report Type</label>
            <select name="view" class="fc">
                <option value="summary" <?php if ($view === 'summary') echo 'selected'; ?>>Summary (Product-wise)</option>
                <option value="details" <?php if ($view === 'details') echo 'selected'; ?>>Details (Voucher-wise)</option>
            </select></div>
        <div class="fg"><label>From Date</label><input type="date" name="from" class="fc" value="<?php echo h($from); ?>"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to" class="fc" value="<?php echo h($to); ?>"></div>
        <div class="fg"><label>Branch</label>
            <select name="branch_id" class="fc" onchange="this.form.warehouse_id.value='0'; this.form.submit();">
                <option value="0">All Branch</option>
                <?php foreach ($branch_list as $b) echo '<option value="' . (int)$b['id'] . '"' . ($f_branch === (int)$b['id'] ? ' selected' : '') . '>' . h($b['branch_name']) . '</option>'; ?>
            </select></div>
        <div class="fg"><label>Warehouse</label>
            <select name="warehouse_id" class="fc">
                <option value="0">All Warehouse</option>
                <?php foreach ($wh_list as $w) echo '<option value="' . (int)$w['id'] . '"' . ($f_wh === (int)$w['id'] ? ' selected' : '') . '>' . h($w['warehouse_name']) . '</option>'; ?>
            </select></div>
    </div>
    <div class="filter-grid">
        <div class="fg"><label>Movement Type</label>
            <select name="type" class="fc">
                <?php foreach ($TYPE_GROUPS as $k => $tg) echo '<option value="' . h($k) . '"' . ($tkey === (string)$k ? ' selected' : '') . '>' . h($tg[0]) . '</option>'; ?>
            </select></div>
        <div class="fg"><label>Product Group</label><select name="group" class="fc"><option value="">All Group</option><?php echo opts($conn, 'erp_product_groups', 'group_name', $group); ?></select></div>
        <div class="fg"><label>Category</label><select name="category" class="fc"><option value="">All Category</option><?php echo opts($conn, 'erp_product_categories', 'category_name', $category); ?></select></div>
        <div class="fg"><label>Brand</label><select name="brand" class="fc"><option value="">All Brand</option><?php echo opts($conn, 'erp_product_brands', 'brand_name', $brand); ?></select></div>
        <div class="fg"><label>Size</label><select name="size" class="fc"><option value="">All Size</option><?php echo opts($conn, 'erp_product_sizes', 'size_name', $size); ?></select></div>
    </div>
    <div class="filter-grid" style="margin-bottom:0;">
        <div class="fg"><label>Color</label><select name="color" class="fc"><option value="">All Color</option><?php echo opts($conn, 'erp_product_colors', 'color_name', $color); ?></select></div>
        <div class="fg" style="grid-column: span 2;"><label>Product Search</label><input type="text" name="q" class="fc" placeholder="Name / Code.." value="<?php echo h($q); ?>"></div>
        <div class="fg" style="justify-content:flex-end;"><label style="font-weight:normal; display:flex; gap:5px; align-items:center;"><input type="checkbox" name="hide0" value="1" <?php echo $hide0 ? 'checked' : ''; ?>> লেনদেন/স্টক নেই এমন পণ্য লুকাও</label></div>
        <div class="fg" style="flex-direction:row; gap:6px; align-items:flex-end;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <a class="btn btn-clear" href="stock_movement.php">Clear</a>
        </div>
    </div>
    <?php if ($pid > 0) echo '<input type="hidden" name="product_id" value="' . $pid . '">'; ?>
</form>

<?php if ($view === 'summary') { ?>

<div class="cards">
    <div class="card"><div class="t">Items</div><div class="v"><?php echo count($rows); ?></div></div>
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Opening (SFT)</div><div class="v" style="color:#ef6c00;"><?php echo n2($G['open']); ?></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="t">Total In (SFT)</div><div class="v" style="color:#0288d1;"><?php echo n2($G['in']); ?></div></div>
    <div class="card" style="border-left-color:#d81b60;"><div class="t">Total Out (SFT)</div><div class="v" style="color:#d81b60;"><?php echo n2($G['out']); ?></div></div>
    <div class="card"><div class="t">Closing (SFT)</div><div class="v" style="color:#2e7d32;"><?php echo n2($G['close']); ?></div></div>
</div>

<?php if ($tkey !== '') echo '<div class="warn"><b>' . h($TYPE_GROUPS[$tkey][0]) . '</b> ফিল্টার চালু: In/Out শুধু এই ধরনের মুভমেন্ট দেখাচ্ছে। Closing = Opening + সব মুভমেন্ট (ফিল্টারের আগের আসল হিসাব), তাই Opening + In − Out এর সাথে নাও মিলতে পারে।</div>'; ?>

<?php
$S = ['open' => 0, 'pur' => 0, 'sret' => 0, 'oin' => 0, 'tin' => 0, 'dlv' => 0, 'pret' => 0, 'oout' => 0, 'tout' => 0, 'close' => 0, 'bx' => 0, 'ls' => 0];
?>
<table class="grid-table">
    <thead>
        <tr>
            <th class="c" rowspan="2" style="width:38px;">SL</th><th rowspan="2">Product Name</th><th rowspan="2">Size</th><th rowspan="2">Color</th>
            <th class="r" rowspan="2">Opening</th>
            <th class="c" colspan="4">In (SFT)</th>
            <th class="c" colspan="4">Out (SFT)</th>
            <th class="r" rowspan="2">Closing (SFT)</th><th class="r" rowspan="2">Box</th><th class="r" rowspan="2">Pcs</th>
        </tr>
        <tr>
            <th class="r">Purchase</th><th class="r">Sales Ret.</th><th class="r">Adj/Trf In</th><th class="r">Total In</th>
            <th class="r">Delivery</th><th class="r">Pur. Ret.</th><th class="r">Adj/Dmg/Trf Out</th><th class="r">Total Out</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rows) { $sl = 1; foreach ($rows as $r) {
        foreach (['open', 'pur', 'sret', 'oin', 'tin', 'dlv', 'pret', 'oout', 'tout', 'close', 'bx', 'ls'] as $k) $S[$k] += $r[$k];
        $lnk = 'stock_movement.php?' . http_build_query($qs + ['view' => 'details', 'product_id' => (int)$r['id']]); ?>
        <tr class="lnk" onclick="location.href='<?php echo h($lnk); ?>'" title="Voucher-wise মুভমেন্ট দেখতে ক্লিক করুন">
            <td class="c"><?php echo $sl++; ?></td>
            <td style="color:#4050b3; font-weight:bold;"><?php echo h($r['item_name']); ?></td>
            <td><?php echo h($r['size_name'] ?: '-'); ?></td>
            <td><?php echo h($r['color_name'] ?: '-'); ?></td>
            <td class="r"><?php echo n2($r['open']); ?></td>
            <td class="r"><?php echo $r['pur'] > 0 ? n2($r['pur']) : ''; ?></td>
            <td class="r"><?php echo $r['sret'] > 0 ? n2($r['sret']) : ''; ?></td>
            <td class="r"><?php echo $r['oin'] > 0 ? n2($r['oin']) : ''; ?></td>
            <td class="r in" style="font-weight:bold;"><?php echo n2($r['tin']); ?></td>
            <td class="r"><?php echo $r['dlv'] > 0 ? n2($r['dlv']) : ''; ?></td>
            <td class="r"><?php echo $r['pret'] > 0 ? n2($r['pret']) : ''; ?></td>
            <td class="r"><?php echo $r['oout'] > 0 ? n2($r['oout']) : ''; ?></td>
            <td class="r out" style="font-weight:bold;"><?php echo n2($r['tout']); ?></td>
            <td class="r <?php echo $r['close'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo n2($r['close']); ?></td>
            <td class="r"><?php echo (int)$r['bx']; ?></td>
            <td class="r"><?php echo (int)$r['ls']; ?></td>
        </tr>
    <?php } ?>
        <tr class="total">
            <td colspan="4" class="c">Grand Total</td>
            <td class="r"><?php echo n2($S['open']); ?></td>
            <td class="r"><?php echo n2($S['pur']); ?></td><td class="r"><?php echo n2($S['sret']); ?></td><td class="r"><?php echo n2($S['oin']); ?></td><td class="r"><?php echo n2($S['tin']); ?></td>
            <td class="r"><?php echo n2($S['dlv']); ?></td><td class="r"><?php echo n2($S['pret']); ?></td><td class="r"><?php echo n2($S['oout']); ?></td><td class="r"><?php echo n2($S['tout']); ?></td>
            <td class="r"><?php echo n2($S['close']); ?></td><td class="r"><?php echo (int)$S['bx']; ?></td><td class="r"><?php echo (int)$S['ls']; ?></td>
        </tr>
    <?php } else { echo '<tr><td colspan="16" class="c" style="padding:15px;color:#777;">এই ফিল্টারে কোনো মুভমেন্ট পাওয়া যায়নি।</td></tr>'; } ?>
    </tbody>
</table>
<div class="note">Closing = Opening + In − Out। সারিতে ক্লিক করলে ওই পণ্যের ভাউচার ধরে মুভমেন্ট দেখা যাবে। সব ওয়্যারহাউস একসাথে দেখলে Stock Transfer এর In ও Out সমান হয়ে মোট ব্যালেন্সে প্রভাব ফেলে না।</div>

<?php } else { ?>

<?php if ($prodInfo) { ?>
<div class="filter-panel" style="line-height:1.7;">
    <b style="font-size:15px;color:#2e7d32;"><?php echo h($prodInfo['item_name']); ?></b>
    <span style="color:#777;">(<?php echo h($prodInfo['item_code']); ?>)</span><br>
    Size: <b><?php echo h($prodInfo['size_name'] ?: '-'); ?></b> &nbsp;|&nbsp; Color: <b><?php echo h($prodInfo['color_name'] ?: '-'); ?></b> &nbsp;|&nbsp; Brand: <b><?php echo h($prodInfo['brand_name'] ?: '-'); ?></b>
    &nbsp;|&nbsp; Period: <b><?php echo date('d-M-Y', strtotime($from)); ?> – <?php echo date('d-M-Y', strtotime($to)); ?></b>
</div>
<?php } ?>

<div class="cards">
    <div class="card"><div class="t">Vouchers / Lines</div><div class="v"><?php echo count($det); ?></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="t">Total In (SFT)</div><div class="v" style="color:#0288d1;"><?php echo n2($T['in']); ?></div></div>
    <div class="card" style="border-left-color:#d81b60;"><div class="t">Total Out (SFT)</div><div class="v" style="color:#d81b60;"><?php echo n2($T['out']); ?></div></div>
    <div class="card"><div class="t">Net Movement (SFT)</div><div class="v <?php echo ($T['in'] - $T['out']) < 0 ? 'neg' : ''; ?>" style="color:#2e7d32;"><?php echo n2($T['in'] - $T['out']); ?></div></div>
</div>

<?php if ($truncated) echo '<div class="warn">৫০০০টির বেশি লাইন, শুধু প্রথম ৫০০০টি দেখানো হয়েছে। তারিখ বা পণ্য ফিল্টার দিয়ে পরিসর ছোট করুন।</div>'; ?>
<?php if ($tkey !== '') echo '<div class="warn"><b>' . h($TYPE_GROUPS[$tkey][0]) . '</b> ফিল্টার চালু: শুধু এই ধরনের লাইন দেখাচ্ছে। Balance কলাম সব মুভমেন্ট ধরে আসল ব্যালেন্স।</div>'; ?>

<table class="grid-table">
    <thead><tr>
        <th class="c" style="width:40px;">SL</th><th style="width:95px;">Date</th><th>Type</th><th>Voucher No</th><th>Party</th>
        <th>Product</th><th>Warehouse</th><th class="r">In (SFT)</th><th class="r">Out (SFT)</th><th class="r">Balance (SFT)</th>
    </tr></thead>
    <tbody>
        <?php if ($pid > 0) { ?>
        <tr class="open-row"><td colspan="9">Opening Balance (<?php echo date('d-M-Y', strtotime($from)); ?> এর আগ পর্যন্ত)</td><td class="r"><?php echo n2($G['open']); ?></td></tr>
        <?php } ?>
        <?php if ($det) { $sl = 1; foreach ($det as $x) { $isIn = $x['qin'] > 0 && $x['qout'] <= 0; ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo $x['d'] ? date('d-M-Y', strtotime($x['d'])) : '-'; ?></td>
            <td><span class="tag <?php echo $isIn ? 'i' : 'o'; ?>"><?php echo h(vt_label($x['vt'])); ?></span></td>
            <td style="font-weight:bold;color:#2b579a;"><?php echo h($x['voucher_no']); ?></td>
            <td><?php echo h($x['party'] ?: '-'); ?></td>
            <td><b style="color:#4050b3;"><?php echo h($x['item_name']); ?></b> <small style="color:#777;"><?php echo h(trim($x['size_name'] . ' ' . $x['color_name'])); ?></small></td>
            <td><?php echo h($x['wh'] ?: '-'); ?></td>
            <td class="r in"><?php echo $x['qin'] > 0 ? n2($x['qin']) : ''; ?></td>
            <td class="r out"><?php echo $x['qout'] > 0 ? n2($x['qout']) : ''; ?></td>
            <td class="r <?php echo $x['bal'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo n2($x['bal']); ?></td>
        </tr>
        <?php } } else echo '<tr><td colspan="10" class="c" style="padding:15px;color:#777;">এই সময়ে কোনো মুভমেন্ট নেই।</td></tr>'; ?>
        <tr class="total"><td colspan="7" class="r">Total</td><td class="r"><?php echo n2($T['in']); ?></td><td class="r"><?php echo n2($T['out']); ?></td>
            <td class="r"><?php echo $pid > 0 ? n2($G['open'] + $T['in'] - $T['out']) : ''; ?></td></tr>
    </tbody>
</table>
<div class="note">Balance প্রতিটি পণ্যের নিজস্ব চলমান ব্যালেন্স (Opening + এ পর্যন্ত In − Out)। একটি পণ্য সিলেক্ট করে দেখলে Opening ও Closing মিলিয়ে নেওয়া সবচেয়ে সহজ।</div>

<?php } ?>
</body>
</html>