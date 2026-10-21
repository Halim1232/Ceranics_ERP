<?php
include('../auth.php'); require_page_access();
include('../config.php');
try { include_once('../Settings/settings_lib.php'); } catch (Throwable $e) {}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }
function has_col($conn, $t, $c) {
    try { $q = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . esc($conn, $c) . "'"); return $q && mysqli_num_rows($q) > 0; }
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
function n2($v) { return number_format((float)$v, 2); }

// ---------- ফিল্টার ----------
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
$f_branch = (int)($_GET['branch_id'] ?? 0);
$f_wh     = (int)($_GET['warehouse_id'] ?? 0);
if ($b = current_branch()) {
    $f_branch = (int)$b['id'];
    if ($f_wh > 0) {
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_warehouses WHERE id=$f_wh AND branch_id=$f_branch"));
        if (!(int)$c['c']) $f_wh = 0;
    }
}
$group    = trim($_GET['group'] ?? '');
$category = trim($_GET['category'] ?? '');
$brand    = trim($_GET['brand'] ?? '');
$size     = trim($_GET['size'] ?? '');
$color    = trim($_GET['color'] ?? '');
$q        = trim($_GET['q'] ?? '');
$hide0    = !isset($_GET['from']) || isset($_GET['hide0']);   // প্রথমবার ডিফল্ট চেকড
$view     = (($_GET['view'] ?? '') === 'ledger') ? 'ledger' : 'summary';
$pid      = (int)($_GET['product_id'] ?? 0);

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

// ---------- লেজারের তারিখ কলাম (না থাকলে GRN / Challan থেকে তারিখ) ----------
$dcol = null;
foreach (['txn_date', 'voucher_date', 'trans_date', 'entry_date', 'created_at', 'date'] as $c) { if (has_col($conn, 'erp_stock_ledger', $c)) { $dcol = $c; break; } }
$hasTr = false;
try { $tq2 = mysqli_query($conn, "SHOW TABLES LIKE 'erp_stock_transfer'"); $hasTr = ($tq2 && mysqli_num_rows($tq2) > 0); } catch (Throwable $e) {}
$hasAdj = false;
try { $tq = mysqli_query($conn, "SHOW TABLES LIKE 'erp_stock_adjustment'"); $hasAdj = ($tq && mysqli_num_rows($tq) > 0); } catch (Throwable $e) {}
if ($dcol) { $dateExpr = "DATE(s.`$dcol`)"; $joins = ''; }
else {
    $dateExpr = "DATE(COALESCE(g.grn_date, dc.challan_date" . ($hasAdj ? ", aj.adj_date" : "") . ($hasTr ? ", tr.transfer_date" : "") . "))";
    $joins = " LEFT JOIN erp_purchase_grn g ON s.voucher_type = 'PURCHASE' AND g.grn_no = s.voucher_no
               LEFT JOIN erp_delivery_challan_master dc ON s.voucher_type IN ('SALES','DELIVERY') AND dc.challan_no = s.voucher_no"
           . ($hasAdj ? " LEFT JOIN erp_stock_adjustment aj ON s.voucher_type IN ('ADJ_OVER','ADJ_SHORT','ADJ_DAMAGE') AND aj.adj_no = s.voucher_no" : "")
           . ($hasTr ? " LEFT JOIN erp_stock_transfer tr ON s.voucher_type IN ('TRANSFER_IN','TRANSFER_OUT') AND tr.transfer_no = s.voucher_no" : "");
}

// ---------- হেডিং (প্রিন্ট) ----------
$co = []; $br = null;
try { $co = company_info($conn); if ($branch_name !== '') $br = branch_info($conn, $branch_name); } catch (Throwable $e) {}
$head_name = trim($br['branch_name'] ?? '') ?: (trim($co['company_name'] ?? '') ?: 'UTTARA BUILDERS');
$head_phone = trim($br['phone'] ?? '');

$qs = $_GET; unset($qs['view'], $qs['product_id']);

$rows = []; $G = ['open' => 0, 'in' => 0, 'out' => 0, 'close' => 0, 'val' => 0, 'box' => 0, 'pcs' => 0];
$prod = null; $ledger = []; $L = ['open' => 0, 'in' => 0, 'out' => 0, 'close' => 0];

if ($view === 'summary') {
    $where = ['1=1'];
    foreach ([['p.group_name', $group], ['p.category_name', $category], ['p.brand_name', $brand], ['p.size_name', $size], ['p.color_name', $color]] as $e) {
        if ($e[1] !== '') $where[] = $e[0] . " = '" . esc($conn, $e[1]) . "'";
    }
    if ($q !== '') { $l = esc($conn, $q); $where[] = "(p.item_name LIKE '%$l%' OR p.item_code LIKE '%$l%')"; }

    $trCase = ($f_wh <= 0 && $f_branch <= 0) ? "s.voucher_type IN ('TRANSFER_IN','TRANSFER_OUT')" : "0";   // সব ওয়্যারহাউস দেখলে ট্রান্সফার Received/Issued এ ধরা হয় না
    $sql = "SELECT p.id, p.item_code, p.item_name, p.size_name, p.color_name, p.brand_name, p.unit_name, p.cost_price,
                   p.box_to_pcs, p.ratio_pcs, p.ratio_output,
                   COALESCE(SUM(CASE WHEN x.d IS NOT NULL AND x.d < '$from' THEN x.net ELSE 0 END), 0) AS opening,
                   COALESCE(SUM(CASE WHEN x.d IS NULL OR (x.d >= '$from' AND x.d <= '$to') THEN x.qin ELSE 0 END), 0) AS qin,
                   COALESCE(SUM(CASE WHEN x.d IS NULL OR (x.d >= '$from' AND x.d <= '$to') THEN x.qout ELSE 0 END), 0) AS qout,
                   (SELECT l.rate FROM erp_stock_ledger l WHERE l.product_id = p.id AND l.voucher_type = 'PURCHASE' AND l.sqft_in > 0 ORDER BY l.id DESC LIMIT 1) AS last_rate
            FROM erp_products p
            LEFT JOIN (SELECT s.product_id, (s.sqft_in - s.sqft_out) AS net, IF($trCase, 0, GREATEST(s.sqft_in, 0)) AS qin,
                              IF($trCase, 0, s.sqft_out + GREATEST(-s.sqft_in, 0)) AS qout, $dateExpr AS d
                       FROM erp_stock_ledger s $joins WHERE 1=1 $whCond) x ON x.product_id = p.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id ORDER BY p.item_name ASC";
    $res = mysqli_query($conn, $sql);
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $open = round((float)$r['opening'], 2); $in = round((float)$r['qin'], 2); $out = round((float)$r['qout'], 2);
        if ($hide0 && abs($open) < 0.005 && abs($in) < 0.005 && abs($out) < 0.005) continue;
        $close = round($open + $in - $out, 2);
        $rate = ($r['last_rate'] !== null && (float)$r['last_rate'] > 0) ? (float)$r['last_rate'] : (float)$r['cost_price'];
        list($box, $loose) = box_loose($close, $r);
        $r += ['open' => $open, 'in' => $in, 'out' => $out, 'close' => $close, 'rate' => $rate, 'val' => $close * $rate, 'bx' => $box, 'ls' => $loose];
        $rows[] = $r;
        $G['open'] += $open; $G['in'] += $in; $G['out'] += $out; $G['close'] += $close; $G['val'] += $r['val']; $G['box'] += $box; $G['pcs'] += $loose;
    }
} else {
    $st = $conn->prepare("SELECT id, item_code, item_name, size_name, color_name, brand_name, unit_name, box_to_pcs, ratio_pcs, ratio_output FROM erp_products WHERE id = ?");
    $st->bind_param("i", $pid); $st->execute();
    $prod = $st->get_result()->fetch_assoc(); $st->close();
    if ($prod) {
        $lr = mysqli_query($conn, "SELECT s.id, s.voucher_type, s.voucher_no, s.sqft_in, s.sqft_out, $dateExpr AS d
                                   FROM erp_stock_ledger s $joins WHERE s.product_id = $pid $whCond ORDER BY s.id ASC");
        $all = [];
        while ($lr && $x = mysqli_fetch_assoc($lr)) {
            $in = (float)$x['sqft_in']; $out = (float)$x['sqft_out'];
            $x['qin'] = max($in, 0); $x['qout'] = $out + max(-$in, 0); $x['net'] = $in - $out;
            if ($x['d'] !== null && $x['d'] < $from) { $L['open'] += $x['net']; continue; }
            if ($x['d'] !== null && $x['d'] > $to) continue;
            $all[] = $x;
        }
        usort($all, function ($a, $b) { return [$a['d'] ?? '', (int)$a['id']] <=> [$b['d'] ?? '', (int)$b['id']]; });
        $bal = $L['open'];
        foreach ($all as $x) {
            $bal += $x['net']; $x['bal'] = $bal; $L['in'] += $x['qin']; $L['out'] += $x['qout'];
            $ledger[] = $x;
        }
        $L['close'] = $bal;
        list($lbox, $lloose) = box_loose($L['close'], $prod);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inventory</title>
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
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 7px 8px; border: 1px solid #dee2e6; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .grid-table tbody tr.lnk:hover td { background: #eef6ff; cursor: pointer; }
    .r { text-align: right; } .c { text-align: center; }
    .neg { color: #d32f2f !important; }
    .open-row td { background: #fff3e0 !important; font-weight: bold; }
    .total td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .note { font-size: 11px; color: #777; margin-top: 6px; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print { .filter-panel, .btn, .no-print { display: none !important; } .print-head { display: block; } body { background: #fff; padding: 0; } }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;"><?php echo h($head_name); ?></h2>
    <?php if ($head_phone !== '') echo '<div style="font-size:12px;color:#555;">Phone: ' . h($head_phone) . '</div>'; ?>
    <div style="font-weight:bold;"><?php echo $view === 'ledger' ? 'Stock Ledger' : 'Inventory Summary'; ?>: <?php echo date('d-M-Y', strtotime($from)); ?> to <?php echo date('d-M-Y', strtotime($to)); ?></div>
    <div style="font-size:12px;color:#1b5e20;font-weight:bold;"><?php echo h($scope_label); ?></div>
</div>

<?php if ($view === 'summary') { ?>

<div class="cards">
    <div class="card"><div class="t">Items</div><div class="v"><?php echo count($rows); ?></div></div>
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Opening (SFT)</div><div class="v" style="color:#ef6c00;"><?php echo n2($G['open']); ?></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="t">Received (SFT)</div><div class="v" style="color:#0288d1;"><?php echo n2($G['in']); ?></div></div>
    <div class="card" style="border-left-color:#d81b60;"><div class="t">Issued (SFT)</div><div class="v" style="color:#d81b60;"><?php echo n2($G['out']); ?></div></div>
    <div class="card" style="border-left-color:#2e7d32;"><div class="t">Closing (SFT)</div><div class="v" style="color:#2e7d32;"><?php echo n2($G['close']); ?></div></div>
    <div class="card" style="border-left-color:#5e35b1;"><div class="t">Stock Value (BDT)</div><div class="v" style="color:#5e35b1;"><?php echo n2($G['val']); ?></div></div>
</div>

<div class="title-row">
    <div class="grid-title">Inventory Summary <span style="font-size:12px;color:#555;text-transform:none;">— <?php echo h($scope_label); ?></span></div>
    <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<form method="GET" class="filter-panel" id="fForm">
    <div class="filter-grid">
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
        <div class="fg"><label>Product Group</label><select name="group" class="fc"><option value="">All Group</option><?php echo opts($conn, 'erp_product_groups', 'group_name', $group); ?></select></div>
    </div>
    <div class="filter-grid" style="margin-bottom:0;">
        <div class="fg"><label>Category</label><select name="category" class="fc"><option value="">All Category</option><?php echo opts($conn, 'erp_product_categories', 'category_name', $category); ?></select></div>
        <div class="fg"><label>Brand</label><select name="brand" class="fc"><option value="">All Brand</option><?php echo opts($conn, 'erp_product_brands', 'brand_name', $brand); ?></select></div>
        <div class="fg"><label>Size</label><select name="size" class="fc"><option value="">All Size</option><?php echo opts($conn, 'erp_product_sizes', 'size_name', $size); ?></select></div>
        <div class="fg"><label>Color</label><select name="color" class="fc"><option value="">All Color</option><?php echo opts($conn, 'erp_product_colors', 'color_name', $color); ?></select></div>
        <div class="fg"><label>Product Search</label><input type="text" name="q" class="fc" placeholder="Name / Code.." value="<?php echo h($q); ?>"></div>
    </div>
    <div style="margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
        <a class="btn btn-clear" href="inventory.php">Clear</a>
        <label style="font-size:12px; display:flex; gap:5px; align-items:center;"><input type="checkbox" name="hide0" value="1" <?php echo $hide0 ? 'checked' : ''; ?>> লেনদেন/স্টক নেই এমন পণ্য লুকাও</label>
    </div>
</form>

<table class="grid-table">
    <thead><tr>
        <th class="c" style="width:38px;">SL</th><th>Brand</th><th>Product Name</th><th>Size</th><th>Color</th>
        <th class="r">Opening</th><th class="r">Received</th><th class="r">Issued</th><th class="r">Closing (SFT)</th>
        <th class="r">Box</th><th class="r">Pcs</th><th class="r">Rate</th><th class="r">Value (BDT)</th>
    </tr></thead>
    <tbody>
    <?php if ($rows) { $sl = 1; foreach ($rows as $r) {
        $lnk = 'inventory.php?' . http_build_query($qs + ['view' => 'ledger', 'product_id' => (int)$r['id']]); ?>
        <tr class="lnk" onclick="location.href='<?php echo h($lnk); ?>'" title="Stock Ledger দেখতে ক্লিক করুন">
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo h($r['brand_name'] ?: '-'); ?></td>
            <td style="color:#4050b3; font-weight:bold;"><?php echo h($r['item_name']); ?></td>
            <td><?php echo h($r['size_name'] ?: '-'); ?></td>
            <td><?php echo h($r['color_name'] ?: '-'); ?></td>
            <td class="r"><?php echo n2($r['open']); ?></td>
            <td class="r"><?php echo n2($r['in']); ?></td>
            <td class="r"><?php echo n2($r['out']); ?></td>
            <td class="r <?php echo $r['close'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo n2($r['close']); ?></td>
            <td class="r"><?php echo (int)$r['bx']; ?></td>
            <td class="r"><?php echo (int)$r['ls']; ?></td>
            <td class="r"><?php echo n2($r['rate']); ?></td>
            <td class="r <?php echo $r['val'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo n2($r['val']); ?></td>
        </tr>
    <?php } ?>
        <tr class="total">
            <td colspan="5" class="c">Grand Total</td>
            <td class="r"><?php echo n2($G['open']); ?></td><td class="r"><?php echo n2($G['in']); ?></td><td class="r"><?php echo n2($G['out']); ?></td>
            <td class="r"><?php echo n2($G['close']); ?></td><td class="r"><?php echo (int)$G['box']; ?></td><td class="r"><?php echo (int)$G['pcs']; ?></td>
            <td></td><td class="r"><?php echo n2($G['val']); ?></td>
        </tr>
    <?php } else { echo '<tr><td colspan="13" class="c" style="padding:15px;color:#777;">কোনো পণ্য পাওয়া যায়নি।</td></tr>'; } ?>
    </tbody>
</table>
<div class="note">Closing = Opening + Received − Issued। Received/Issued এর মধ্যে রিটার্ন ও এডজাস্টমেন্ট এন্ট্রিও ধরা হয়। <?php echo $dcol ? '' : 'লেজারে তারিখ কলাম নেই, তাই GRN/Challan এর তারিখ ব্যবহার হয়েছে; তারিখ নেই এমন এন্ট্রি (যেমন রিটার্ন) চলতি সময়ের মুভমেন্টে ধরা হয়েছে।'; ?></div>

<?php } else { ?>

<div class="title-row">
    <div class="grid-title">Stock Ledger <span style="font-size:12px;color:#555;text-transform:none;">— <?php echo h($scope_label); ?></span></div>
    <div style="display:flex; gap:8px;">
        <a class="btn btn-back" href="inventory.php?<?php echo h(http_build_query($qs)); ?>"><i class="fa-solid fa-arrow-left"></i> Back to Summary</a>
        <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<?php if (!$prod) { echo '<div class="filter-panel">পণ্য পাওয়া যায়নি।</div>'; } else { ?>
<div class="filter-panel" style="line-height:1.7;">
    <b style="font-size:15px;color:#2e7d32;"><?php echo h($prod['item_name']); ?></b>
    <span style="color:#777;">(<?php echo h($prod['item_code']); ?>)</span><br>
    Size: <b><?php echo h($prod['size_name'] ?: '-'); ?></b> &nbsp;|&nbsp; Color: <b><?php echo h($prod['color_name'] ?: '-'); ?></b> &nbsp;|&nbsp; Brand: <b><?php echo h($prod['brand_name'] ?: '-'); ?></b>
    &nbsp;|&nbsp; Period: <b><?php echo date('d-M-Y', strtotime($from)); ?> – <?php echo date('d-M-Y', strtotime($to)); ?></b>
</div>

<div class="cards">
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Opening (SFT)</div><div class="v" style="color:#ef6c00;"><?php echo n2($L['open']); ?></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="t">Received (SFT)</div><div class="v" style="color:#0288d1;"><?php echo n2($L['in']); ?></div></div>
    <div class="card" style="border-left-color:#d81b60;"><div class="t">Issued (SFT)</div><div class="v" style="color:#d81b60;"><?php echo n2($L['out']); ?></div></div>
    <div class="card"><div class="t">Closing (SFT)</div><div class="v <?php echo $L['close'] < 0 ? 'neg' : ''; ?>" style="color:#2e7d32;"><?php echo n2($L['close']); ?></div><div style="font-size:12px;color:#666;"><?php echo (int)$lbox; ?> Box, <?php echo (int)$lloose; ?> Pcs</div></div>
</div>

<table class="grid-table">
    <thead><tr><th class="c" style="width:40px;">SL</th><th style="width:100px;">Date</th><th>Type</th><th>Voucher No</th><th class="r">In (SFT)</th><th class="r">Out (SFT)</th><th class="r">Balance (SFT)</th></tr></thead>
    <tbody>
        <tr class="open-row"><td colspan="6">Opening Balance (<?php echo date('d-M-Y', strtotime($from)); ?> এর আগ পর্যন্ত)</td><td class="r"><?php echo n2($L['open']); ?></td></tr>
        <?php $sl = 1; foreach ($ledger as $x) { ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo $x['d'] ? date('d-M-Y', strtotime($x['d'])) : '-'; ?></td>
            <td><?php echo h(vt_label($x['voucher_type'])); ?></td>
            <td style="font-weight:bold;color:#2b579a;"><?php echo h($x['voucher_no']); ?></td>
            <td class="r" style="color:#28a745;"><?php echo $x['qin'] > 0 ? n2($x['qin']) : ''; ?></td>
            <td class="r" style="color:#d32f2f;"><?php echo $x['qout'] > 0 ? n2($x['qout']) : ''; ?></td>
            <td class="r <?php echo $x['bal'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo n2($x['bal']); ?></td>
        </tr>
        <?php } if (!$ledger) echo '<tr><td colspan="7" class="c" style="padding:15px;color:#777;">এই সময়ে কোনো মুভমেন্ট নেই।</td></tr>'; ?>
        <tr class="total"><td colspan="4" class="r">Total / Closing</td><td class="r"><?php echo n2($L['in']); ?></td><td class="r"><?php echo n2($L['out']); ?></td><td class="r"><?php echo n2($L['close']); ?></td></tr>
    </tbody>
</table>
<?php } ?>

<?php } ?>
</body>
</html>