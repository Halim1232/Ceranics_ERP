<?php
include('../auth.php'); require_page_access();
include('../config.php');
include('../balance_functions.php');   // supplier_balance() — ভাউচার/জার্নালসহ চলতি পাওনা (GRN/পেমেন্ট ভাউচার স্ক্রিনের সাথে একই হিসাব)

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// ---------- ফিল্টার ----------
$showIn = $_GET['show'] ?? 'due';
$show = in_array($showIn, ['due', 'advance', 'all'], true) ? $showIn : 'due';   // ডিফল্ট: শুধু পাওনা
$sort = (($_GET['sort'] ?? 'balance') === 'name') ? 'name' : 'balance';
$q    = trim($_GET['q'] ?? '');

// ---------- সাপ্লায়ার তালিকা ----------
$types = ""; $params = []; $where = "";
if ($q !== '') {
    $where = "WHERE (supplier_name LIKE ? OR supplier_code LIKE ? OR address LIKE ? OR mobile_no LIKE ?)";
    $types = "ssss"; $like = '%' . $q . '%'; $params = [$like, $like, $like, $like];
}
if (current_branch()) {   // ব্রাঞ্চ ইউজার: শুধু যে সাপ্লায়ারের এই ব্রাঞ্চে GRN আছে
    $bc = "id IN (SELECT supplier_id FROM erp_purchase_grn WHERE 1=1" . branch_sql('branch_name') . ")";
    $where = ($where === '') ? "WHERE $bc" : $where . " AND $bc";
}
$stmt = $conn->prepare("SELECT id, supplier_code, supplier_name, address, mobile_no FROM erp_suppliers $where ORDER BY supplier_name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// শেষ GRN-এর তারিখ (একবারেই সবার)
$last = [];
$lq = mysqli_query($conn, "SELECT supplier_id, MAX(grn_date) AS d FROM erp_purchase_grn WHERE 1=1" . branch_sql('branch_name') . " GROUP BY supplier_id");
while ($lq && $x = mysqli_fetch_assoc($lq)) $last[(int)$x['supplier_id']] = $x['d'];

$rows = [];
$total = 0;
while ($r = $res->fetch_assoc()) {
    $bal = round((float)supplier_balance($conn, (int)$r['id']), 2);   // + = আমরা দেবো, − = অগ্রিম দেওয়া আছে
    if ($show == 'due'     && $bal <= 0.005)  continue;
    if ($show == 'advance' && $bal >= -0.005) continue;
    $r['bal'] = $bal;
    $r['last_date'] = $last[(int)$r['id']] ?? null;
    $rows[] = $r;
    $total += $bal;
}
$stmt->close();

if ($sort == 'name') usort($rows, function ($a, $b) { return strcasecmp($a['supplier_name'], $b['supplier_name']); });
else usort($rows, function ($a, $b) use ($show) { return $show == 'advance' ? $a['bal'] <=> $b['bal'] : $b['bal'] <=> $a['bal']; });

$showLabel = ['due' => 'Payable (Due)', 'advance' => 'Advance Paid (Debit Balance)', 'all' => 'All'][$show];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Creditor List</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #333; }
    .cards { display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 140px; background: #fff; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 18px; font-weight: bold; margin-top: 4px; }
    .title { font-size: 15px; font-weight: bold; color: #2e7d32; margin: 10px 0 8px; text-transform: uppercase; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 15px; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .btn { padding: 6px 15px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; height: 30px; font-size: 12px; }
    .btn-show { background: #2e7d32; } .btn-clear { background: #6c757d; } .btn-print { background: #dc3545; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #999; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #999; }
    .grid-table td { padding: 5px 8px; border: 1px solid #ccc; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .r { text-align: right; } .c { text-align: center; }
    .grand td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print {
        .filter-panel { display: none; }
        body { background: #fff; padding: 0; }
        .print-head { display: block; }
        tr { break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;">UTTARA BUILDERS</h2>
    <div style="font-weight:bold;">Creditor List (<?php echo h($showLabel); ?>) as on <?php echo date('d-M-Y'); ?></div>
    <?php if ($q !== '') echo '<div style="font-size:12px;color:#555;">Search: ' . h($q) . '</div>'; ?>
</div>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Show</label>
            <select name="show" class="fc" autocomplete="off">
                <option value="due" <?php if ($show == 'due') echo 'selected'; ?>>Payable (Due)</option>
                <option value="advance" <?php if ($show == 'advance') echo 'selected'; ?>>Advance Paid</option>
                <option value="all" <?php if ($show == 'all') echo 'selected'; ?>>All Suppliers</option>
            </select></div>
        <div class="fg"><label>Sort By</label>
            <select name="sort" class="fc" autocomplete="off">
                <option value="balance" <?php if ($sort == 'balance') echo 'selected'; ?>>Balance (High to Low)</option>
                <option value="name" <?php if ($sort == 'name') echo 'selected'; ?>>Supplier Name</option>
            </select></div>
        <div class="fg"><label>Supplier</label><input type="text" name="q" class="fc" autocomplete="off" placeholder="Name / Code / Address / Mobile" value="<?php echo h($q); ?>"></div>
        <div class="fg" style="flex-direction:row; gap:6px; align-items:flex-end;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <button type="button" class="btn btn-clear" onclick="location.href='?'">Clear</button>
            <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</form>

<div class="cards">
    <div class="card"><div class="t">Suppliers</div><div class="v"><?php echo count($rows); ?></div></div>
    <div class="card" style="border-left-color:#d32f2f;"><div class="t">Net Payable (Balance)</div><div class="v" style="color:#d32f2f;"><?php echo number_format($total, 2); ?></div></div>
</div>

<div class="title">Creditor List - <?php echo h($showLabel); ?></div>

<table class="grid-table">
    <thead>
        <tr>
            <th class="c" style="width:45px;">SL No</th><th style="width:90px;">Code</th><th>Supplier Name</th><th>Address</th><th style="width:110px;">Mobile</th>
            <th class="r" style="width:140px;">Balance</th><th class="c" style="width:95px;">Last GRN</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rows) { $sl = 1; foreach ($rows as $r) { ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo h($r['supplier_code']); ?></td>
            <td style="font-weight:bold;color:#2b579a;"><?php echo h($r['supplier_name']); ?></td>
            <td><?php echo h($r['address']); ?></td>
            <td><?php echo h($r['mobile_no']); ?></td>
            <td class="r" style="font-weight:bold;color:<?php echo $r['bal'] > 0 ? '#d32f2f' : '#28a745'; ?>;"><?php echo number_format($r['bal'], 2); ?><?php echo $r['bal'] < 0 ? ' (Adv)' : ''; ?></td>
            <td class="c"><?php echo $r['last_date'] ? date('d-M-Y', strtotime($r['last_date'])) : '-'; ?></td>
        </tr>
    <?php } ?>
        <tr class="grand">
            <td colspan="5" class="c">Grand Total</td>
            <td class="r"><?php echo number_format($total, 2); ?></td>
            <td></td>
        </tr>
    <?php } else { ?>
        <tr><td colspan="7" class="c" style="padding:15px;color:#777;">এই ফিল্টারে কোনো সাপ্লায়ার পাওয়া যায়নি।</td></tr>
    <?php } ?>
    </tbody>
</table>

</body>
</html>