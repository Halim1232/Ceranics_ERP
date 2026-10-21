<?php
include('../auth.php'); require_page_access();
include('../config.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function opts($conn, $sql, $col) {
    $o = '';
    $q = mysqli_query($conn, $sql);
    while ($q && $x = mysqli_fetch_assoc($q)) {
        if (trim((string)$x[$col]) === '') continue;
        $o .= '<option value="' . h($x[$col]) . '">';
    }
    return $o;
}

// ---------- ফিল্টার ----------
$view = (($_GET['view'] ?? 'summary') === 'details') ? 'details' : 'summary';   // ডিফল্ট: Summary
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$branch   = trim($_GET['branch']   ?? '');
if ($b = current_branch()) $branch = $b['name'];
$group    = trim($_GET['group']    ?? '');
$category = trim($_GET['category'] ?? '');
$brand    = trim($_GET['brand']    ?? '');
$size     = trim($_GET['size']     ?? '');
$product  = trim($_GET['product']  ?? '');
$supplier = trim($_GET['supplier'] ?? '');
$supplier_id = (int)($_GET['supplier_id'] ?? 0);

// সাপ্লায়ার লেবেল: নাম [ ঠিকানা | মোবাইল ]
function sup_label($name, $addr, $phone) {
    $parts = array_filter([trim((string)$addr), trim((string)$phone)]);
    return $name . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '');
}
if ($supplier_id > 0) {
    $sr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_name, address, mobile_no FROM erp_suppliers WHERE id=$supplier_id"));
    if ($sr) $supplier = sup_label($sr['supplier_name'], $sr['address'], $sr['mobile_no']);
    else $supplier_id = 0;
}

$where  = ["l.voucher_type = 'PURCHASE'", "g.grn_date BETWEEN ? AND ?"];
$types  = "ss";
$params = [$from, $to];
foreach ([['g.branch_name', $branch], ['p.group_name', $group], ['p.category_name', $category], ['p.brand_name', $brand], ['p.size_name', $size]] as $e) {
    if ($e[1] !== '') { $where[] = $e[0] . " = ?"; $types .= "s"; $params[] = $e[1]; }
}
if ($product !== '') { $where[] = "p.item_name LIKE ?"; $types .= "s"; $params[] = '%' . $product . '%'; }
if ($supplier_id > 0) {
    $where[] = "s.id = ?"; $types .= "i"; $params[] = $supplier_id;
} elseif ($supplier !== '') {
    $where[] = "(s.supplier_name LIKE ? OR s.address LIKE ? OR s.mobile_no LIKE ?)";
    $types .= "sss"; $like = '%' . $supplier . '%'; array_push($params, $like, $like, $like);
}

$sql = "SELECT g.id AS grn_id, g.grn_date, g.grn_no, g.labor_charge, g.discount_amount, s.supplier_name,
               p.item_name, p.color_name, p.category_name, p.brand_name, p.size_name, p.unit_name,
               l.box_in AS box_qty, l.pcs_in AS pcs_qty, l.sqft_in AS qty, l.rate AS price, (l.sqft_in * l.rate) AS amount
        FROM erp_stock_ledger l
        JOIN erp_purchase_grn g ON l.voucher_no = g.grn_no
        JOIN erp_products p ON l.product_id = p.id
        LEFT JOIN erp_suppliers s ON g.supplier_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY g.grn_date ASC, g.id ASC, l.id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// GRN অনুযায়ী গ্রুপ করা
$grn = [];
while ($r = $res->fetch_assoc()) {
    $id = (int)$r['grn_id'];
    if (!isset($grn[$id])) {
        $grn[$id] = ['date' => $r['grn_date'], 'no' => $r['grn_no'], 'sup' => $r['supplier_name'] ?? 'N/A',
                     'labor' => (float)$r['labor_charge'], 'disc' => (float)$r['discount_amount'],
                     'items' => 0, 'qty' => 0, 'lines' => []];
    }
    $grn[$id]['lines'][] = $r;
    $grn[$id]['items'] += (float)$r['amount'];
    $grn[$id]['qty']   += (float)$r['qty'];
}
$stmt->close();

$G = ['qty' => 0, 'items' => 0, 'labor' => 0, 'bill' => 0, 'disc' => 0, 'net' => 0];
foreach ($grn as &$v) {
    $v['bill'] = $v['items'] + $v['labor'];      // Total Bill = পণ্যের মোট + লেবার/অন্যান্য (ডিসকাউন্টের আগে)
    $v['net']  = $v['bill'] - $v['disc'];        // Total Purchase = ডিসকাউন্টের পরে
    $G['qty'] += $v['qty'];   $G['items'] += $v['items']; $G['labor'] += $v['labor'];
    $G['bill'] += $v['bill']; $G['disc'] += $v['disc'];   $G['net']   += $v['net'];
}
unset($v);

$active = [];
foreach (['Branch' => $branch, 'Group' => $group, 'Category' => $category, 'Brand' => $brand, 'Size' => $size, 'Product' => $product, 'Supplier' => $supplier] as $k => $x) {
    if ($x !== '') $active[] = "$k: $x";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Report</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #333; }
    .cards { display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 140px; background: #fff; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 18px; font-weight: bold; margin-top: 4px; }
    .title { font-size: 15px; font-weight: bold; color: #2e7d32; margin: 10px 0 8px; text-transform: uppercase; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px 15px; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .btn { padding: 6px 15px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; height: 30px; font-size: 12px; }
    .btn-show { background: #2e7d32; } .btn-clear { background: #6c757d; } .btn-print { background: #dc3545; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #999; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #999; }
    .grid-table td { padding: 5px 8px; border: 1px solid #ccc; vertical-align: top; }
    .grid-table.summary tbody tr:nth-child(even) td { background: #f8fafc; }
    .r { text-align: right; } .c { text-align: center; }
    .inv-total td { background: #f1f5f9; font-weight: bold; border-top: 1px solid #666; border-bottom: 2px solid #666; }
    .charges div { display: flex; justify-content: space-between; gap: 10px; font-size: 12px; }
    .charges b { font-weight: 700; }
    .grand td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print {
        .filter-panel { display: none; }
        body { background: #fff; padding: 0; }
        .print-head { display: block; }
        tbody.inv-block { break-inside: avoid; page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;">UTTARA BUILDERS</h2>
    <div style="font-weight:bold;">Purchase Report (<?php echo $view == 'details' ? 'Details' : 'Summary'; ?>): <?php echo date('d-M-Y', strtotime($from)); ?> to <?php echo date('d-M-Y', strtotime($to)); ?></div>
    <?php if ($active) echo '<div style="font-size:12px;color:#555;">' . h(implode(' | ', $active)) . '</div>'; ?>
</div>

<datalist id="dl_branch"><?php echo opts($conn, "SELECT DISTINCT branch_name FROM erp_branches WHERE status='Active' ORDER BY branch_name", 'branch_name'); ?></datalist>
<datalist id="dl_group"><?php echo opts($conn, "SELECT DISTINCT group_name FROM erp_product_groups ORDER BY group_name", 'group_name'); ?></datalist>
<datalist id="dl_category"><?php echo opts($conn, "SELECT DISTINCT category_name FROM erp_product_categories ORDER BY category_name", 'category_name'); ?></datalist>
<datalist id="dl_brand"><?php echo opts($conn, "SELECT DISTINCT brand_name FROM erp_product_brands ORDER BY brand_name", 'brand_name'); ?></datalist>
<datalist id="dl_size"><?php echo opts($conn, "SELECT DISTINCT size_name FROM erp_product_sizes ORDER BY size_name", 'size_name'); ?></datalist>
<datalist id="dl_product"><?php echo opts($conn, "SELECT DISTINCT item_name FROM erp_products ORDER BY item_name", 'item_name'); ?></datalist>
<datalist id="dl_supplier">
<?php
$sq = mysqli_query($conn, "SELECT id, supplier_name, address, mobile_no FROM erp_suppliers ORDER BY supplier_name ASC");
while ($sq && $s = mysqli_fetch_assoc($sq)) {
    echo '<option data-id="' . (int)$s['id'] . '" value="' . h(sup_label($s['supplier_name'], $s['address'], $s['mobile_no'])) . '">';
}
?>
</datalist>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Report Type</label>
            <select name="view" class="fc">
                <option value="summary" <?php if ($view == 'summary') echo 'selected'; ?>>Summary</option>
                <option value="details" <?php if ($view == 'details') echo 'selected'; ?>>Details</option>
            </select></div>
        <div class="fg"><label>From Date</label><input type="date" name="from" class="fc" value="<?php echo h($from); ?>"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to" class="fc" value="<?php echo h($to); ?>"></div>
        <div class="fg"><label>Branch</label><input type="text" name="branch" class="fc" list="dl_branch" autocomplete="off" placeholder="All Branch" value="<?php echo h($branch); ?>"></div>
        <div class="fg"><label>Product Group</label><input type="text" name="group" class="fc" list="dl_group" autocomplete="off" placeholder="All Group" value="<?php echo h($group); ?>"></div>
        <div class="fg"><label>Product Category</label><input type="text" name="category" class="fc" list="dl_category" autocomplete="off" placeholder="All Category" value="<?php echo h($category); ?>"></div>
        <div class="fg"><label>Product Brand</label><input type="text" name="brand" class="fc" list="dl_brand" autocomplete="off" placeholder="All Brand" value="<?php echo h($brand); ?>"></div>
        <div class="fg"><label>Product Size</label><input type="text" name="size" class="fc" list="dl_size" autocomplete="off" placeholder="All Size" value="<?php echo h($size); ?>"></div>
        <div class="fg"><label>Product</label><input type="text" name="product" class="fc" list="dl_product" autocomplete="off" placeholder="All Product" value="<?php echo h($product); ?>"></div>
        <div class="fg"><label>Supplier</label><input type="text" name="supplier" id="supplier_search" class="fc" list="dl_supplier" autocomplete="off" placeholder="Name / Address / Mobile" value="<?php echo h($supplier); ?>" oninput="syncSupplier()">
            <input type="hidden" name="supplier_id" id="supplier_id" value="<?php echo $supplier_id ? $supplier_id : ''; ?>"></div>
        <div class="fg" style="flex-direction:row; gap:6px; align-items:flex-end;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <button type="button" class="btn btn-clear" onclick="clearFilters()">Clear</button>
            <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</form>

<div class="cards">
    <div class="card"><div class="t">GRN</div><div class="v"><?php echo count($grn); ?></div></div>
    <div class="card" style="border-left-color:#6a1b9a;"><div class="t">Total QTY (SFT)</div><div class="v" style="color:#6a1b9a;"><?php echo number_format($G['qty'], 2); ?></div></div>
    <div class="card" style="border-left-color:#0056b3;"><div class="t">Total Bill</div><div class="v" style="color:#0056b3;"><?php echo number_format($G['bill'], 2); ?></div></div>
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Discount</div><div class="v" style="color:#ef6c00;"><?php echo number_format($G['disc'], 2); ?></div></div>
    <div class="card" style="border-left-color:#28a745;"><div class="t">Net Purchase</div><div class="v" style="color:#28a745;"><?php echo number_format($G['net'], 2); ?></div></div>
</div>

<div class="title">Purchase Report - <?php echo $view == 'details' ? 'Details' : 'Summary'; ?></div>

<?php if ($view == 'summary') { ?>
<!-- ================= SUMMARY ================= -->
<table class="grid-table summary">
    <thead>
        <tr>
            <th class="c" style="width:50px;">SL No</th><th style="width:100px;">Date</th><th>Supplier</th><th>GRN Number</th>
            <th class="r" style="width:130px;">Total Bill</th><th class="r" style="width:120px;">Discount</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($grn) { $sl = 1; foreach ($grn as $v) { ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo date('d-M-Y', strtotime($v['date'])); ?></td>
            <td><?php echo h($v['sup']); ?></td>
            <td style="font-weight:bold;color:#2b579a;"><?php echo h($v['no']); ?></td>
            <td class="r" style="font-weight:bold;"><?php echo number_format($v['bill'], 2); ?></td>
            <td class="r" style="color:#d32f2f;"><?php echo number_format($v['disc'], 2); ?></td>
        </tr>
    <?php } ?>
        <tr class="grand">
            <td colspan="4" class="c">Grand Total</td>
            <td class="r"><?php echo number_format($G['bill'], 2); ?></td>
            <td class="r"><?php echo number_format($G['disc'], 2); ?></td>
        </tr>
    <?php } else { ?>
        <tr><td colspan="6" class="c" style="padding:15px;color:#777;">এই ফিল্টারে কোনো ক্রয় পাওয়া যায়নি।</td></tr>
    <?php } ?>
    </tbody>
</table>

<?php } else { ?>
<!-- ================= DETAILS ================= -->
<table class="grid-table">
    <thead>
        <tr>
            <th class="c" style="width:38px;">SN</th><th style="width:90px;">Date</th><th style="width:190px;">Supplier</th>
            <th>Product</th><th>Category</th><th>Brand</th><th>Size</th><th style="width:105px;">Box / Pcs</th>
            <th class="r" style="width:110px;">Qty</th><th class="r" style="width:70px;">Rate</th><th class="r" style="width:100px;">Amount</th>
        </tr>
    </thead>
    <?php if ($grn) { $sn = 1; foreach ($grn as $v) { $n = count($v['lines']) + 1; $first = true; ?>
    <tbody class="inv-block">
        <?php foreach ($v['lines'] as $l) { ?>
        <tr>
            <?php if ($first) { ?>
            <td class="c" rowspan="<?php echo $n; ?>"><?php echo $sn++; ?></td>
            <td rowspan="<?php echo $n; ?>"><?php echo date('d-M-Y', strtotime($v['date'])); ?></td>
            <td rowspan="<?php echo $n; ?>"><?php echo h($v['sup']); ?>, Ref :<b><?php echo h($v['no']); ?></b></td>
            <?php $first = false; } ?>
            <td style="font-weight:bold;color:#4050b3;"><?php echo h($l['item_name']); ?></td>
            <td><?php echo h($l['category_name'] ?: '-'); ?></td>
            <td><?php echo h($l['brand_name'] ?: '-'); ?></td>
            <td><?php echo h($l['size_name'] ?: '-'); ?></td>
            <td><?php echo (int)$l['box_qty']; ?> Crtn ,<?php echo (int)$l['pcs_qty']; ?> PCS</td>
            <td class="r"><?php echo number_format($l['qty'], 4); ?> <?php echo h($l['unit_name'] ?: 'SFT'); ?></td>
            <td class="r"><?php echo number_format($l['price'], 2); ?></td>
            <td class="r"><?php echo number_format($l['amount'], 2); ?></td>
        </tr>
        <?php } ?>
        <!-- চার্জ ব্লক: Total Item Purchase / Labor / Discount -->
        <tr>
            <td colspan="5"></td>
            <td colspan="3" class="charges">
                <div><span>Total Item Purchase</span><b><?php echo number_format($v['items'], 2); ?></b></div>
                <div><span>Labor / Other (+)</span><b><?php echo number_format($v['labor'], 2); ?></b></div>
                <div><span>Discount (-)</span><b><?php echo number_format($v['disc'], 2); ?></b></div>
            </td>
        </tr>
        <tr class="inv-total">
            <td colspan="3"></td>
            <td colspan="5" class="r">Total GRN Qty :</td>
            <td class="r"><?php echo number_format($v['qty'], 2); ?> SFT</td>
            <td class="r">Total Purchase :</td>
            <td class="r"><?php echo number_format($v['net'], 2); ?></td>
        </tr>
    </tbody>
    <?php } ?>
    <tbody>
        <tr class="grand">
            <td colspan="8" class="r">Running Total :</td>
            <td class="r"><?php echo number_format($G['qty'], 2); ?> SFT</td>
            <td class="r">Total Purchase :</td>
            <td class="r"><?php echo number_format($G['net'], 2); ?></td>
        </tr>
    </tbody>
    <?php } else { ?>
    <tbody><tr><td colspan="11" class="c" style="padding:15px;color:#777;">এই ফিল্টারে কোনো ক্রয় পাওয়া যায়নি।</td></tr></tbody>
    <?php } ?>
</table>
<?php } ?>

<script>
// তালিকা থেকে সিলেক্ট করলে supplier_id সেট হবে; হাতে বদলালে মুছে যাবে
function syncSupplier() {
    var val = document.getElementById('supplier_search').value;
    var opts = document.getElementById('dl_supplier').options;
    var idBox = document.getElementById('supplier_id');
    idBox.value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { idBox.value = opts[i].getAttribute('data-id'); break; }
    }
}
function clearFilters() {
    var t = new Date(), d = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    var v = document.querySelector('select[name=view]').value;
    location.href = '?view=' + v + '&from=' + d + '&to=' + d;
}
</script>
</body>
</html>