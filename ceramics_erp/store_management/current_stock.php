<?php
include('../auth.php'); require_page_access();
include('../config.php');
try { include_once('../Settings/settings_lib.php'); } catch (Throwable $e) {}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// ---- Branch / Warehouse scope (khali = shob ek sathe) ----
$f_branch = (int)($_GET['branch_id'] ?? 0);
$f_wh     = (int)($_GET['warehouse_id'] ?? 0);
if ($b = current_branch()) {
    $f_branch = (int)$b['id'];
    if ($f_wh > 0) {
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_warehouses WHERE id=$f_wh AND branch_id=$f_branch"));
        if (!(int)$c['c']) $f_wh = 0;
    }
}
$view_wh  = (($_GET['view'] ?? '') === 'wh');          // Warehouse-wise (prottek warehouse e alada row)

$whCond = '';
if ($f_wh > 0)          $whCond = " AND s.warehouse_id = $f_wh";
elseif ($f_branch > 0)  $whCond = " AND s.warehouse_id IN (SELECT id FROM erp_warehouses WHERE branch_id = $f_branch)";

$branch_list = []; $wh_list = [];
try {
    $bq = mysqli_query($conn, "SELECT id, branch_name FROM erp_branches WHERE status='Active' ORDER BY branch_name");
    while ($bq && $b = mysqli_fetch_assoc($bq)) $branch_list[] = $b;
    $wq = mysqli_query($conn, "SELECT id, warehouse_name FROM erp_warehouses WHERE status='Active'" . ($f_branch > 0 ? " AND branch_id = $f_branch" : "") . " ORDER BY warehouse_name");
    while ($wq && $w = mysqli_fetch_assoc($wq)) $wh_list[] = $w;
} catch (Throwable $e) { /* erp_warehouses nai */ }

$scope_label = 'All Branch / All Warehouse'; $branch_name = '';
if ($f_wh > 0) { foreach ($wh_list as $w) if ((int)$w['id'] === $f_wh) $scope_label = 'Warehouse: ' . $w['warehouse_name']; }
if ($f_branch > 0) {
    foreach ($branch_list as $b) if ((int)$b['id'] === $f_branch) { $branch_name = $b['branch_name']; if ($f_wh <= 0) $scope_label = 'Branch: ' . $b['branch_name']; }
}
if ($view_wh) $scope_label .= ' (Warehouse-wise)';

// ---- Query: stock_value.php er sathe ek-i received/delivery hishab ----
$calc = "COALESCE(SUM(CASE WHEN s.sqft_in > 0 AND s.voucher_type <> 'SALES_RETURN' THEN s.sqft_in ELSE 0 END), 0)
               - COALESCE(SUM(CASE WHEN s.voucher_type = 'PUR_RETURN' THEN s.sqft_out ELSE 0 END), 0) AS total_received,
               COALESCE(SUM(CASE WHEN s.voucher_type <> 'PUR_RETURN' THEN s.sqft_out ELSE 0 END), 0) + COALESCE(SUM(CASE WHEN s.sqft_in < 0 THEN -s.sqft_in ELSE 0 END), 0)
               - COALESCE(SUM(CASE WHEN s.voucher_type = 'SALES_RETURN' THEN s.sqft_in ELSE 0 END), 0) AS total_delivery,
               COALESCE(SUM(s.sqft_in - s.sqft_out), 0) AS stock_sft";
$cols = "p.id, p.item_name, p.size_name, p.color_name, p.brand_name, p.group_name, p.category_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output";

if ($view_wh) {
    $sql = "SELECT $cols, s.warehouse_id, COALESCE(w.warehouse_name, '(No Warehouse)') AS wh_name, $calc
            FROM erp_products p
            JOIN erp_stock_ledger s ON p.id = s.product_id$whCond
            LEFT JOIN erp_warehouses w ON w.id = s.warehouse_id
            GROUP BY p.id, s.warehouse_id
            ORDER BY p.item_name ASC, wh_name ASC";
} else {
    $sql = "SELECT $cols, '' AS wh_name, $calc
            FROM erp_products p
            LEFT JOIN erp_stock_ledger s ON p.id = s.product_id$whCond
            GROUP BY p.id
            ORDER BY p.item_name ASC";
}
$res = mysqli_query($conn, $sql);

$rows = [];
$g = ['rcv' => 0, 'dlv' => 0, 'box' => 0, 'pcs' => 0, 'qty' => 0];
while ($res && $r = mysqli_fetch_assoc($res)) {
    $stock  = round((float)$r['stock_sft'], 2);
    $boxPcs = (int)$r['box_to_pcs'];
    $ratioP = (float)$r['ratio_pcs'];
    $ratioO = (float)$r['ratio_output'];
    $perPcs = ($ratioP > 0 && $ratioO > 0) ? $ratioO / $ratioP : 0;

    $box = 0; $loose = 0;
    if ($perPcs > 0 && $boxPcs > 0) {
        $totalPcs = (int)round(abs($stock) / $perPcs);
        $box = intdiv($totalPcs, $boxPcs); $loose = $totalPcs % $boxPcs;
        if ($stock < 0) { $box = -$box; $loose = -$loose; }
    }
    $r['stock'] = $stock; $r['box'] = $box; $r['loose'] = $loose;
    $rows[] = $r;
    $g['rcv'] += (float)$r['total_received']; $g['dlv'] += (float)$r['total_delivery'];
    $g['box'] += $box; $g['pcs'] += $loose; $g['qty'] += $stock;
}

function opts($conn, $table, $col) {
    $o = '';
    try {
        $q = mysqli_query($conn, "SELECT DISTINCT $col FROM $table WHERE $col != '' ORDER BY $col");
        while ($q && $x = mysqli_fetch_assoc($q)) { $v = h($x[$col]); $o .= "<option value='$v'>$v</option>"; }
    } catch (Throwable $e) {}
    return $o;
}

// ---- print heading: branch-wise ----
$co = []; $br = null;
try { $co = company_info($conn); if ($branch_name !== '') $br = branch_info($conn, $branch_name); } catch (Throwable $e) {}
$head_name = trim($br['branch_name'] ?? '') ?: (trim($co['company_name'] ?? '') ?: 'UTTARA BUILDERS');
$head_phone = trim($br['phone'] ?? '');
$colspan = $view_wh ? 6 : 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Current Stock Register</title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 10px; margin: 0; }
    .cards { display: flex; gap: 20px; margin-bottom: 15px; }
    .card { flex: 1; background: #fff; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 20px; font-weight: bold; margin-top: 5px; }
    .grid-title { font-size: 15px; font-weight: bold; color: #2e7d32; text-transform: uppercase; }
    .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 12px; flex-wrap: wrap; }
    .title-tools { display: flex; align-items: center; gap: 16px; font-size: 12px; font-weight: bold; }
    .title-tools label { display: flex; align-items: center; gap: 5px; cursor: pointer; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px 15px; margin-bottom: 10px; }
    .form-group { display: flex; flex-direction: column; gap: 3px; }
    .form-group label { font-size: 11px; font-weight: bold; }
    .form-control { width: 100%; box-sizing: border-box; height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 8px; border: 1px solid #dee2e6; background: #fff; }
    .grid-table tr:nth-child(even) td { background: #f8fafc; }
    .r { text-align: right; }
    .neg { color: #d32f2f !important; }
    .total td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .btn-print { padding: 6px 15px; font-weight: bold; border: none; background: #dc3545; color: #fff; cursor: pointer; border-radius: 4px; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print { .filter-panel, .btn-print, .title-tools { display: none; } .print-head { display: block; } }
</style>
</head>
<body>
<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;"><?php echo h($head_name); ?></h2>
    <?php if ($head_phone !== '') echo '<div style="font-size:12px;color:#555;">Phone: ' . h($head_phone) . '</div>'; ?>
    <div style="font-weight:bold;">Current Stock Register</div>
    <div style="font-size:13px;color:#1b5e20;font-weight:bold;"><?php echo h($scope_label); ?></div>
</div>

<div class="cards">
    <div class="card" style="border-left:4px solid #6a1b9a;"><div class="t">Items</div><div class="v" id="card_items" style="color:#6a1b9a;"><?php echo count($rows); ?></div></div>
    <div class="card" style="border-left:4px solid #0056b3;"><div class="t">Total Stock QTY (SFT)</div><div class="v" id="card_qty" style="color:#0056b3;"><?php echo number_format($g['qty'], 2); ?></div></div>
    <div class="card" style="border-left:4px solid #2e7d32;"><div class="t">Total Box</div><div class="v" id="card_box" style="color:#2e7d32;"><?php echo h($g['box']); ?></div></div>
    <div class="card" style="border-left:4px solid #ef6c00;"><div class="t">Loose PCS</div><div class="v" id="card_pcs" style="color:#ef6c00;"><?php echo h($g['pcs']); ?></div></div>
</div>

<div class="title-row">
    <div class="grid-title">Current Stock Register <span style="font-size:12px;color:#555;text-transform:none;">— <?php echo h($scope_label); ?></span></div>
    <div class="title-tools">
        <label><input type="checkbox" id="chk_wh" <?php echo $view_wh ? 'checked' : ''; ?> onchange="goScope(false)"> Warehouse-wise</label>
        <label><input type="checkbox" id="chk_hide0" onchange="runFilter()"> Zero stock লুকাও</label>
        <button type="button" class="btn-print" onclick="window.print()">Print</button>
    </div>
</div>

<div class="filter-panel">
    <!-- Line 1 (5 ta) -->
    <div class="filter-grid">
        <div class="form-group"><label>Branch</label>
            <select id="flt_branch" class="form-control" onchange="goScope(true)">
                <option value="0">All Branch</option>
                <?php foreach ($branch_list as $b) echo '<option value="' . (int)$b['id'] . '"' . ($f_branch === (int)$b['id'] ? ' selected' : '') . '>' . h($b['branch_name']) . '</option>'; ?>
            </select></div>
        <div class="form-group"><label>Warehouse</label>
            <select id="flt_wh" class="form-control" onchange="goScope(false)">
                <option value="0">All Warehouse</option>
                <?php foreach ($wh_list as $w) echo '<option value="' . (int)$w['id'] . '"' . ($f_wh === (int)$w['id'] ? ' selected' : '') . '>' . h($w['warehouse_name']) . '</option>'; ?>
            </select></div>
        <div class="form-group"><label>Product Group</label><select id="flt_group" class="form-control" onchange="runFilter()"><option value="">All Group</option><?php echo opts($conn, 'erp_product_groups', 'group_name'); ?></select></div>
        <div class="form-group"><label>Category</label><select id="flt_cat" class="form-control" onchange="runFilter()"><option value="">All Category</option><?php echo opts($conn, 'erp_product_categories', 'category_name'); ?></select></div>
        <div class="form-group"><label>Brand</label><select id="flt_brand" class="form-control" onchange="runFilter()"><option value="">All Brand</option><?php echo opts($conn, 'erp_product_brands', 'brand_name'); ?></select></div>
    </div>
    <!-- Line 2 (5 ta) -->
    <div class="filter-grid" style="margin-bottom:0;">
        <div class="form-group"><label>Color</label><select id="flt_color" class="form-control" onchange="runFilter()"><option value="">All Color</option><?php echo opts($conn, 'erp_product_colors', 'color_name'); ?></select></div>
        <div class="form-group"><label>Size</label><select id="flt_size" class="form-control" onchange="runFilter()"><option value="">All Size</option><?php echo opts($conn, 'erp_product_sizes', 'size_name'); ?></select></div>
        <div class="form-group"><label>QTY Filter</label>
            <select id="flt_cond" class="form-control" onchange="toggleBetween(); runFilter();">
                <option value="NoFilter">No Filter</option>
                <option value="EqualTo">Equal (=)</option>
                <option value="NotEqualTo">Not Equal (!=)</option>
                <option value="GreaterThan">Greater (&gt;)</option>
                <option value="LessThan">Less (&lt;)</option>
                <option value="GreaterThanOrEqualTo">Greater or Equal (&gt;=)</option>
                <option value="LessThanOrEqualTo">Less or Equal (&lt;=)</option>
                <option value="Between">Between</option>
                <option value="NotBetween">Not Between</option>
            </select></div>
        <div class="form-group"><label id="lbl1">QTY Value</label>
            <div style="display:flex; gap:5px;">
                <input type="number" id="flt_v1" class="form-control" oninput="runFilter()" step="any">
                <input type="number" id="flt_v2" class="form-control" oninput="runFilter()" step="any" placeholder="To" style="display:none;">
            </div></div>
        <div class="form-group"><label>Product Search</label><input type="text" id="flt_search" class="form-control" oninput="runFilter()" placeholder="Search product name.."></div>
    </div>
</div>

<table class="grid-table" id="stockTable">
    <thead>
        <tr>
            <th style="width:35px; text-align:center;">SL</th><th>Brand</th><th>Product Name</th><th>Size</th><th>Color</th>
            <?php if ($view_wh) echo '<th>Warehouse</th>'; ?>
            <th class="r">Received QTY</th><th class="r">Delivery QTY</th><th class="r">Total Box</th><th class="r">Loose PCS</th><th class="r">Stock (QTY)</th>
        </tr>
    </thead>
    <tbody>
    <?php $sl = 1; foreach ($rows as $r) { ?>
        <tr class="stock-row"
            data-group="<?php echo h($r['group_name'] ?? ''); ?>" data-cat="<?php echo h($r['category_name'] ?? ''); ?>"
            data-brand="<?php echo h($r['brand_name'] ?? ''); ?>" data-size="<?php echo h($r['size_name'] ?? ''); ?>"
            data-color="<?php echo h($r['color_name'] ?? ''); ?>" data-name="<?php echo h($r['item_name']); ?>"
            data-qty="<?php echo h((string)$r['stock']); ?>"
            data-rcv="<?php echo (float)$r['total_received']; ?>" data-dlv="<?php echo (float)$r['total_delivery']; ?>"
            data-box="<?php echo (int)$r['box']; ?>" data-loose="<?php echo (int)$r['loose']; ?>">
            <td class="sl" style="text-align:center;"><?php echo $sl++; ?></td>
            <td><?php echo h($r['brand_name'] ?: '-'); ?></td>
            <td style="color:#4050b3; font-weight:bold;"><?php echo h($r['item_name']); ?></td>
            <td><?php echo h($r['size_name'] ?: '-'); ?></td>
            <td><?php echo h($r['color_name'] ?: '-'); ?></td>
            <?php if ($view_wh) echo '<td style="color:#2e7d32;font-weight:600;">' . h($r['wh_name']) . '</td>'; ?>
            <td class="r"><?php echo number_format($r['total_received'], 2); ?></td>
            <td class="r"><?php echo number_format($r['total_delivery'], 2); ?></td>
            <td class="r"><?php echo h($r['box']); ?></td>
            <td class="r"><?php echo h($r['loose']); ?></td>
            <td class="r <?php echo $r['stock'] < 0 ? 'neg' : ''; ?>" style="font-weight:bold;"><?php echo number_format($r['stock'], 2); ?><?php if ($r['stock'] < 0) echo ' <small>(শর্টেজ)</small>'; ?></td>
        </tr>
    <?php } ?>
        <tr class="total" id="totalRow">
            <td colspan="<?php echo $colspan; ?>" style="text-align:center;">Grand Total</td>
            <td class="r" id="t_rcv"><?php echo number_format($g['rcv'], 2); ?></td>
            <td class="r" id="t_dlv"><?php echo number_format($g['dlv'], 2); ?></td>
            <td class="r" id="t_box"><?php echo h($g['box']); ?></td>
            <td class="r" id="t_pcs"><?php echo h($g['pcs']); ?></td>
            <td class="r" id="t_qty"><?php echo number_format($g['qty'], 2); ?></td>
        </tr>
    </tbody>
</table>

<script>
var FIELDS = ['flt_group','flt_cat','flt_brand','flt_color','flt_size','flt_cond','flt_v1','flt_v2','flt_search'];
var SKEY = 'currentStockFilters';

function norm(s) { return (s == null ? '' : String(s)).replace(/\s+/g, ' ').trim().toLowerCase(); }
function fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function toggleBetween() {
    var c = document.getElementById("flt_cond").value, two = (c === "Between" || c === "NotBetween");
    document.getElementById("flt_v2").style.display = two ? "block" : "none";
    document.getElementById("lbl1").textContent = two ? "From / To Value" : "QTY Value";
    document.getElementById("flt_v1").placeholder = two ? "From" : "";
    if (!two) document.getElementById("flt_v2").value = "";
}
function saveFilters() {
    try {
        var o = {}; FIELDS.forEach(function(id) { o[id] = document.getElementById(id).value; });
        o.hide0 = document.getElementById('chk_hide0').checked ? 1 : 0;
        sessionStorage.setItem(SKEY, JSON.stringify(o));
    } catch (e) {}
}
function loadFilters() {
    try {
        var o = JSON.parse(sessionStorage.getItem(SKEY) || '{}');
        FIELDS.forEach(function(id) { if (o[id] !== undefined) document.getElementById(id).value = o[id]; });
        document.getElementById('chk_hide0').checked = !!o.hide0;
    } catch (e) {}
}
// Branch/Warehouse/View server-side, tai page reload hoy; baki filter save thake
function goScope(branchChanged) {
    saveFilters();
    var b = document.getElementById('flt_branch').value;
    var w = branchChanged ? 0 : document.getElementById('flt_wh').value;
    var v = document.getElementById('chk_wh').checked ? 'wh' : '';
    window.location.href = window.location.pathname + '?branch_id=' + b + '&warehouse_id=' + w + (v ? '&view=wh' : '');
}

function runFilter() {
    var f = {
        group: norm(document.getElementById("flt_group").value), cat: norm(document.getElementById("flt_cat").value),
        brand: norm(document.getElementById("flt_brand").value), size: norm(document.getElementById("flt_size").value),
        color: norm(document.getElementById("flt_color").value)
    };
    var txt = norm(document.getElementById("flt_search").value), cond = document.getElementById("flt_cond").value;
    var v1 = parseFloat(document.getElementById("flt_v1").value), v2 = parseFloat(document.getElementById("flt_v2").value);
    var hide0 = document.getElementById('chk_hide0').checked;
    var T = { rcv: 0, dlv: 0, box: 0, pcs: 0, qty: 0, n: 0 }, sl = 1;

    document.querySelectorAll(".stock-row").forEach(function(row) {
        var ok = true;
        for (var k in f) { if (f[k] && norm(row.getAttribute("data-" + k)) !== f[k]) ok = false; }
        if (txt && norm(row.getAttribute("data-name")).indexOf(txt) === -1) ok = false;
        var q = Math.round((parseFloat(row.getAttribute("data-qty")) || 0) * 100) / 100;
        if (hide0 && Math.abs(q) < 0.005) ok = false;
        if (cond !== "NoFilter" && !isNaN(v1)) {
            if (cond === "EqualTo" && q !== v1) ok = false;
            else if (cond === "NotEqualTo" && q === v1) ok = false;
            else if (cond === "GreaterThan" && q <= v1) ok = false;
            else if (cond === "LessThan" && q >= v1) ok = false;
            else if (cond === "GreaterThanOrEqualTo" && q < v1) ok = false;
            else if (cond === "LessThanOrEqualTo" && q > v1) ok = false;
            else if (cond === "Between" && !isNaN(v2) && (q < v1 || q > v2)) ok = false;
            else if (cond === "NotBetween" && !isNaN(v2) && (q >= v1 && q <= v2)) ok = false;
        }
        row.style.display = ok ? "" : "none";
        if (ok) {
            row.querySelector(".sl").textContent = sl++; T.n++;
            T.rcv += parseFloat(row.getAttribute("data-rcv")) || 0; T.dlv += parseFloat(row.getAttribute("data-dlv")) || 0;
            T.box += parseInt(row.getAttribute("data-box")) || 0;   T.pcs += parseInt(row.getAttribute("data-loose")) || 0;
            T.qty += parseFloat(row.getAttribute("data-qty")) || 0;
        }
    });
    document.getElementById("t_rcv").textContent = fmt(T.rcv); document.getElementById("t_dlv").textContent = fmt(T.dlv);
    document.getElementById("t_box").textContent = T.box;      document.getElementById("t_pcs").textContent = T.pcs;
    document.getElementById("t_qty").textContent = fmt(T.qty);
    document.getElementById("card_items").textContent = T.n;   document.getElementById("card_qty").textContent = fmt(T.qty);
    document.getElementById("card_box").textContent = T.box;   document.getElementById("card_pcs").textContent = T.pcs;
    saveFilters();
}
loadFilters(); toggleBetween(); runFilter();
</script>
</body>
</html>