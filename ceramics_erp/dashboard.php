<?php
include('auth.php');
include('config.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function rows($conn, $sql) {
    $out = [];
    try { $r = mysqli_query($conn, $sql); while ($r && $x = mysqli_fetch_assoc($r)) $out[] = $x; }
    catch (Throwable $e) { /* টেবিল না থাকলে ফাঁকা দেখাবে */ }
    return $out;
}
function box_pcs($sft, $r) {
    $bp = (int)$r['box_to_pcs']; $rp = (float)$r['ratio_pcs']; $ro = (float)$r['ratio_output'];
    $per = ($rp > 0 && $ro > 0) ? $ro / $rp : 0;
    if ($per <= 0 || $bp <= 0) return '';
    $tp = (int)round(abs($sft) / $per);
    return intdiv($tp, $bp) . ' Box ' . ($tp % $bp) . ' Pcs';
}

// ---- লেভেল ১: আজকের সেল ----
$s = rows($conn, "SELECT COALESCE(SUM(LEAST(x.paid, x.total)),0) AS cash_sale,
        COALESCE(SUM(GREATEST(x.total - x.paid,0)),0) AS due_sale,
        COALESCE(SUM(x.total),0) AS total_sale
    FROM (SELECT IFNULL(t.items,0) + m.labor_charge - m.discount_amount AS total, m.payment_amount AS paid
          FROM sales_invoice_master m
          LEFT JOIN (SELECT invoice_id, SUM(total_qty*rate) AS items FROM sales_invoice_details GROUP BY invoice_id) t
                 ON t.invoice_id = m.invoice_id
          WHERE m.invoice_date = CURDATE()) x");
$sale = $s[0] ?? ['cash_sale' => 0, 'due_sale' => 0, 'total_sale' => 0];

// ---- লেভেল ২: ফান্ড ----
$fund = ['CASH' => 0, 'BANK' => 0, 'MFS' => 0];
foreach (rows($conn, "SELECT a.account_type, SUM(a.opening_balance + IFNULL(f.bal,0)) AS balance
    FROM erp_fund_accounts a
    LEFT JOIN (SELECT account_id, SUM(IF(txn_type='IN', amount, -amount)) AS bal FROM erp_fund_ledger GROUP BY account_id) f
           ON f.account_id = a.id
    WHERE a.status = 'Active' GROUP BY a.account_type") as $f) {
    $fund[$f['account_type']] = (float)$f['balance'];
}

// ---- লেভেল ৪: Top Selling ----
$top = rows($conn, "SELECT p.item_name, p.size_name,
        SUM(d.total_qty) AS sold_sft, SUM(d.total_qty * d.rate) AS sold_amount
    FROM sales_invoice_details d
    JOIN sales_invoice_master m ON m.invoice_id = d.invoice_id
    JOIN erp_products p ON p.id = d.product_id
    WHERE m.invoice_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    GROUP BY p.id, p.item_name, p.size_name ORDER BY sold_sft DESC LIMIT 10");

// ---- লেভেল ৪: Delivery Pending ----
$pend = rows($conn, "SELECT m.invoice_no, m.invoice_date, c.customer_name, p.item_name,
        p.box_to_pcs, p.ratio_pcs, p.ratio_output,
        d.total_qty - IFNULL(dl.delivered,0) AS pending_sft
    FROM sales_invoice_details d
    JOIN sales_invoice_master m ON m.invoice_id = d.invoice_id
    JOIN erp_customers c ON c.id = m.customer_id
    JOIN erp_products p ON p.id = d.product_id
    LEFT JOIN (SELECT dm.invoice_no, dd.product_id, SUM(dd.total_qty) AS delivered
               FROM erp_delivery_challan_details dd
               JOIN erp_delivery_challan_master dm ON dm.id = dd.challan_id
               GROUP BY dm.invoice_no, dd.product_id) dl
           ON dl.invoice_no = m.invoice_no AND dl.product_id = d.product_id
    WHERE m.status IN ('Undelivered','Partial') AND d.total_qty - IFNULL(dl.delivered,0) > 0.01
    ORDER BY m.invoice_date ASC, m.invoice_id ASC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
    body { margin: 0; padding: 16px; background: #f4f6f9; color: #263238; }
    .title { font-size: 20px; font-weight: 700; }
    .sub { font-size: 13px; color: #78909c; margin: 4px 0 12px; }
    .sec { font-size: 12px; font-weight: 700; color: #2e7d32; text-transform: uppercase; margin: 16px 0 6px; }
    .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .card { background: #fff; padding: 14px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: center; gap: 14px; border-left: 4px solid #ccc; }
    .ico { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 17px; }
    .lbl { font-size: 11px; font-weight: 700; color: #78909c; text-transform: uppercase; }
    .val { font-size: 20px; font-weight: 700; margin-top: 3px; }
    .box { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 12px; }
    #stockQ { width: 100%; height: 38px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
    #stockQ:focus { border-color: #2e7d32; }
    .stock-wrap { max-height: 190px; overflow-y: auto; margin-top: 8px; border: 1px solid #cddc39; }
.stock-wrap th { position: sticky; top: 0; z-index: 1; }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    th { background: #dbebe7; text-align: left; padding: 7px; border: 1px solid #cddc39; }
    td { padding: 7px; border: 1px solid #e3e8ec; }
    .r { text-align: right; }
    .empty { text-align: center; color: #999; padding: 14px; }
    @media (max-width: 900px) { .cards, .two { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="title">Dashboard Overview</div>
<div class="sub">আজকের তারিখ: <?php echo date('d-M-Y'); ?></div>

<!-- লেভেল ১ -->
<div class="cards">
    <div class="card" style="border-left-color:#2e7d32;"><div class="ico" style="background:#2e7d32;"><i class="fa fa-money-bill-wave"></i></div><div><div class="lbl">Cash Sale (আজ)</div><div class="val"><?php echo number_format($sale['cash_sale'], 2); ?></div></div></div>
    <div class="card" style="border-left-color:#ef6c00;"><div class="ico" style="background:#ef6c00;"><i class="fa fa-hand-holding-dollar"></i></div><div><div class="lbl">Due Sale (আজ)</div><div class="val"><?php echo number_format($sale['due_sale'], 2); ?></div></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="ico" style="background:#0288d1;"><i class="fa fa-chart-line"></i></div><div><div class="lbl">Total Sale (আজ)</div><div class="val"><?php echo number_format($sale['total_sale'], 2); ?></div></div></div>
</div>

<!-- লেভেল ২ -->
<div class="sec">Fund Balance</div>
<div class="cards">
    <div class="card" style="border-left-color:#43a047;"><div class="ico" style="background:#43a047;"><i class="fa fa-wallet"></i></div><div><div class="lbl">Cash in Hand</div><div class="val"><?php echo number_format($fund['CASH'], 2); ?></div></div></div>
    <div class="card" style="border-left-color:#5e35b1;"><div class="ico" style="background:#5e35b1;"><i class="fa fa-building-columns"></i></div><div><div class="lbl">Bank Balance</div><div class="val"><?php echo number_format($fund['BANK'], 2); ?></div></div></div>
    <div class="card" style="border-left-color:#d81b60;"><div class="ico" style="background:#d81b60;"><i class="fa fa-mobile-screen"></i></div><div><div class="lbl">MFS Balance</div><div class="val"><?php echo number_format($fund['MFS'], 2); ?></div></div></div>
</div>

<!-- লেভেল ৩ -->
<div class="sec">Stock Search</div>
<div class="box">
    <input type="text" id="stockQ" placeholder="পণ্যের নাম বা কোড লিখুন..." oninput="stockSearch()" autocomplete="off">
    <div class="stock-wrap">
        <table>
            <thead><tr><th>Product</th><th>Size</th><th>Color</th><th class="r">Stock (SFT)</th><th class="r">Box</th><th class="r">Pcs</th></tr></thead>
            <tbody id="stockResult"></tbody>
        </table>
    </div>
</div>

<!-- লেভেল ৪ -->
<div class="two">
    <div>
        <div class="sec">Top Selling Products (চলতি মাস)</div>
        <div class="box"><table>
            <thead><tr><th>SL</th><th>Product</th><th class="r">Sold (SFT)</th><th class="r">Amount (BDT)</th></tr></thead>
            <tbody>
            <?php if ($top) { $i = 1; foreach ($top as $t) { ?>
                <tr><td><?php echo $i++; ?></td>
                    <td><b><?php echo h($t['item_name']); ?></b> <small><?php echo h($t['size_name']); ?></small></td>
                    <td class="r"><?php echo number_format($t['sold_sft'], 2); ?></td>
                    <td class="r"><?php echo number_format($t['sold_amount'], 2); ?></td></tr>
            <?php } } else echo '<tr><td colspan="4" class="empty">এই মাসে কোনো বিক্রি নেই।</td></tr>'; ?>
            </tbody></table></div>
    </div>
    <div>
        <div class="sec">Delivery Pending List</div>
        <div class="box" style="max-height:420px; overflow-y:auto;"><table>
            <thead><tr><th>Invoice</th><th>Customer</th><th>Product</th><th class="r">Pending</th></tr></thead>
            <tbody>
            <?php if ($pend) { foreach ($pend as $p) { ?>
                <tr><td><b style="color:#2b579a;"><?php echo h($p['invoice_no']); ?></b><br><small><?php echo date('d-M-y', strtotime($p['invoice_date'])); ?></small></td>
                    <td><?php echo h($p['customer_name']); ?></td>
                    <td><?php echo h($p['item_name']); ?></td>
                    <td class="r"><b style="color:#d32f2f;"><?php echo number_format($p['pending_sft'], 2); ?> SFT</b><br><small><?php echo h(box_pcs($p['pending_sft'], $p)); ?></small></td></tr>
            <?php } } else echo '<tr><td colspan="4" class="empty">কোনো ডেলিভারি বাকি নেই।</td></tr>'; ?>
            </tbody></table></div>
    </div>
</div>

<script>
var stTimer;
function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
function stockSearch() {
    clearTimeout(stTimer);
    stTimer = setTimeout(function () {
        var q = document.getElementById('stockQ').value.trim();
        var body = document.getElementById('stockResult');
        fetch('ajax_stock_search.php?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (list) {
            if (!Array.isArray(list) || !list.length) { body.innerHTML = '<tr><td colspan="6" class="empty">কিছু পাওয়া যায়নি</td></tr>'; return; }
            body.innerHTML = list.map(function (p) {
                var st = p.negative ? ' style="color:#d32f2f;font-weight:700;"' : ' style="color:#2e7d32;font-weight:700;"';
                var tag = p.negative ? ' <small style="color:#d32f2f;">(শর্টেজ)</small>' : '';
                return '<tr>'
                     + '<td><b>' + esc(p.name) + '</b>' + tag + '</td>'
                     + '<td>' + esc(p.size) + '</td>'
                     + '<td>' + esc(p.color) + '</td>'
                     + '<td class="r"' + st + '>' + esc(p.sft) + '</td>'
                     + '<td class="r"' + st + '>' + esc(p.box) + '</td>'
                     + '<td class="r"' + st + '>' + esc(p.pcs) + '</td></tr>';
            }).join('');
          })
          .catch(function () { body.innerHTML = '<tr><td colspan="6" class="empty">সার্চ ব্যর্থ হয়েছে</td></tr>'; });
    }, 300);
}
stockSearch();   // পেজ খুললেই তালিকা লোড হবে
</script>
</body>
</html>