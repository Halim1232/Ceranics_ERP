<?php
include('auth.php');
include('config.php');
if (file_exists(__DIR__ . '/balance_functions.php')) include_once(__DIR__ . '/balance_functions.php');   // customer_prev_balance(), supplier_balance()

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function rows($conn, $sql) {
    $out = [];
    try { $r = mysqli_query($conn, $sql); while ($r && $x = mysqli_fetch_assoc($r)) $out[] = $x; }
    catch (Throwable $e) { /* টেবিল না থাকলে ফাঁকা দেখাবে */ }
    return $out;
}
function one($conn, $sql, $def = []) { $r = rows($conn, $sql); return $r[0] ?? $def; }
function box_pcs($sft, $r) {
    $bp = (int)$r['box_to_pcs']; $rp = (float)$r['ratio_pcs']; $ro = (float)$r['ratio_output'];
    $per = ($rp > 0 && $ro > 0) ? $ro / $rp : 0;
    if ($per <= 0 || $bp <= 0) return '';
    $tp = (int)round(abs($sft) / $per);
    return intdiv($tp, $bp) . ' Box ' . ($tp % $bp) . ' Pcs';
}
// 1,22,500 (ভারতীয়/বাংলাদেশি গ্রুপিং); পূর্ণসংখ্যা হলে দশমিক নেই
function bd($n) {
    $n = round((float)$n, 2); $neg = $n < 0; $n = abs($n);
    $dec = (abs($n - round($n)) > 0.004) ? 2 : 0;
    $parts = explode('.', number_format($n, $dec, '.', ''));
    $int = $parts[0];
    if (strlen($int) > 3) { $rest = substr($int, 0, -3); $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest); $int = $rest . ',' . substr($int, -3); }
    return ($neg ? '−' : '') . $int . (isset($parts[1]) ? '.' . $parts[1] : '');
}
function pct($part, $whole) { return $whole > 0.004 ? (int)round($part / $whole * 100) : 0; }

// আদায়ের তারিখের কলাম না থাকলে বানিয়ে নেয় (Debtor List থেকে তারিখ বসে)
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM erp_customers LIKE 'next_collect_date'");
    if ($cc && mysqli_num_rows($cc) == 0) mysqli_query($conn, "ALTER TABLE erp_customers ADD COLUMN next_collect_date DATE NULL DEFAULT NULL");
} catch (Throwable $e) {}

$MS = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";

// ---- Sales Report (আজ + চলতি মাস) ----
$sale = one($conn, "SELECT
        COALESCE(SUM(IF(x.d = CURDATE(), LEAST(x.paid, x.total), 0)),0) AS cash_today,
        COALESCE(SUM(IF(x.d = CURDATE(), GREATEST(x.total - x.paid, 0), 0)),0) AS due_today,
        COALESCE(SUM(IF(x.d = CURDATE(), x.total, 0)),0) AS total_today,
        COALESCE(SUM(x.total),0) AS total_month
    FROM (SELECT m.invoice_date AS d, IFNULL(t.items,0) + m.labor_charge - m.discount_amount AS total, m.payment_amount AS paid
          FROM sales_invoice_master m
          LEFT JOIN (SELECT invoice_id, SUM(total_qty*rate) AS items FROM sales_invoice_details GROUP BY invoice_id) t ON t.invoice_id = m.invoice_id
          WHERE m.invoice_date >= $MS AND m.invoice_date <= CURDATE()) x",
    ['cash_today' => 0, 'due_today' => 0, 'total_today' => 0, 'total_month' => 0]);

// ---- Purchase Report (আজ + চলতি মাস) — GRN তালিকার একই হিসাব ----
$pur = one($conn, "SELECT
        COALESCE(SUM(IF(x.d = CURDATE(), LEAST(x.paid, x.total), 0)),0) AS cash_today,
        COALESCE(SUM(IF(x.d = CURDATE(), GREATEST(x.total - x.paid, 0), 0)),0) AS due_today,
        COALESCE(SUM(IF(x.d = CURDATE(), x.total, 0)),0) AS total_today,
        COALESCE(SUM(x.total),0) AS total_month
    FROM (SELECT g.grn_date AS d, IFNULL(t.items,0) + g.labor_charge - g.discount_amount AS total, g.payment_amount AS paid
          FROM erp_purchase_grn g
          LEFT JOIN (SELECT voucher_no, SUM(IF(total_value > 0, total_value, sqft_in * rate)) AS items
                     FROM erp_stock_ledger WHERE voucher_type = 'PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
          WHERE g.grn_date >= $MS AND g.grn_date <= CURDATE()) x",
    ['cash_today' => 0, 'due_today' => 0, 'total_today' => 0, 'total_month' => 0]);

// ---- Income / Expenses (হিসাব বিভাগের জার্নাল থেকে; Sales Revenue 4001 ও COGS 5001 বাদে — P&L এর মতো) ----
$ie = one($conn, "SELECT
        COALESCE(SUM(IF(m.voucher_date = CURDATE() AND a.account_type = 'Income',  d.credit - d.debit, 0)),0) AS inc_today,
        COALESCE(SUM(IF(m.voucher_date = CURDATE() AND a.account_type = 'Expense', d.debit - d.credit, 0)),0) AS exp_today,
        COALESCE(SUM(IF(a.account_type = 'Income',  d.credit - d.debit, 0)),0) AS inc_month,
        COALESCE(SUM(IF(a.account_type = 'Expense', d.debit - d.credit, 0)),0) AS exp_month
    FROM erp_journal_details d
    JOIN erp_journal_master m ON m.id = d.journal_id
    JOIN erp_chart_of_accounts a ON a.id = d.account_id
    WHERE m.voucher_date >= $MS AND m.voucher_date <= CURDATE()
      AND a.account_type IN ('Income','Expense') AND a.account_code NOT IN ('4001','5001')",
    ['inc_today' => 0, 'exp_today' => 0, 'inc_month' => 0, 'exp_month' => 0]);

// ---- Liabilities: আজকের কালেকশন / পেমেন্ট (ভাউচার) ----
$cp = one($conn, "SELECT
        COALESCE(SUM(IF(ref_type = 'COLLECTION' AND txn_type = 'IN', amount, 0)),0) AS coll,
        COALESCE(SUM(IF(ref_type = 'PAYMENT' AND txn_type = 'OUT' AND ref_no NOT LIKE 'GRN%', amount, 0)),0) AS pay
    FROM erp_fund_ledger WHERE txn_date = CURDATE()", ['coll' => 0, 'pay' => 0]);

// ---- মোট পাওনা (Receivable) ও দেনা (Payable): Debtor List / Creditor এর একই ফাংশন ----
$recv = 0; $payb = 0; $cust_bal = [];
try {
    if (function_exists('customer_prev_balance')) {
        $q = mysqli_query($conn, "SELECT id FROM erp_customers");
        while ($q && $c = mysqli_fetch_assoc($q)) {
            $b = (float)customer_prev_balance($conn, (int)$c['id']);
            if ($b > 0.005) { $recv += $b; $cust_bal[(int)$c['id']] = $b; }
        }
    }
    if (function_exists('supplier_balance')) {
        $q = mysqli_query($conn, "SELECT id FROM erp_suppliers");
        while ($q && $s = mysqli_fetch_assoc($q)) {
            $b = (float)supplier_balance($conn, (int)$s['id']);
            if ($b > 0.005) $payb += $b;
        }
    }
} catch (Throwable $e) {}

// ---- Available Amount (ফান্ড) ----
$fund = ['CASH' => 0, 'BANK' => 0, 'MFS' => 0];
foreach (rows($conn, "SELECT a.account_type, SUM(a.opening_balance + IFNULL(f.bal,0)) AS balance
    FROM erp_fund_accounts a
    LEFT JOIN (SELECT account_id, SUM(IF(txn_type='IN', amount, -amount)) AS bal FROM erp_fund_ledger GROUP BY account_id) f
           ON f.account_id = a.id
    WHERE a.status = 'Active' GROUP BY a.account_type") as $f) {
    $fund[$f['account_type']] = (float)$f['balance'];
}
$fund_total = $fund['CASH'] + $fund['BANK'] + $fund['MFS'];

$sms_balance = 0;   // আপাতত শূন্য; SMS সেবা যুক্ত হলে এখানে আসবে

// ---- Top Selling (চলতি মাস) ----
$top = rows($conn, "SELECT p.item_name, p.size_name, SUM(d.total_qty) AS sold_sft, SUM(d.total_qty * d.rate) AS sold_amount
    FROM sales_invoice_details d
    JOIN sales_invoice_master m ON m.invoice_id = d.invoice_id
    JOIN erp_products p ON p.id = d.product_id
    WHERE m.invoice_date >= $MS
    GROUP BY p.id, p.item_name, p.size_name ORDER BY sold_sft DESC LIMIT 5");
$top_max = $top ? max(array_map(function ($t) { return (float)$t['sold_sft']; }, $top)) : 0;

// ---- Delivery Pending ----
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

// ---- Today's Alerts & Reminders ----
$alerts = [];
// ১) কাস্টমারের আদায়ের তারিখ (Debtor List থেকে বসানো) — আজ বা ওভারডিউ, বাকি থাকলে
foreach (rows($conn, "SELECT id, customer_name, next_collect_date, DATEDIFF(CURDATE(), next_collect_date) AS late
        FROM erp_customers WHERE next_collect_date IS NOT NULL AND next_collect_date <= CURDATE() ORDER BY next_collect_date ASC") as $c) {
    $b = $cust_bal[(int)$c['id']] ?? 0;
    if ($b <= 0.005) continue;
    $late = (int)$c['late'];
    $alerts[] = [
        'color' => $late > 0 ? '#c62828' : '#e65100', 'order' => $late > 0 ? 0 : 1,
        'title' => $late > 0 ? 'আদায় ওভারডিউ (' . $late . ' দিন)' : 'আজ আদায়ের তারিখ',
        'text'  => $c['customer_name'] . ' — বাকি ' . bd($b),
        'sub'   => 'আদায়ের তারিখ: ' . date('d-M-Y', strtotime($c['next_collect_date'])),
    ];
}
// ২) নেগেটিভ স্টক
foreach (rows($conn, "SELECT p.item_name, SUM(l.sqft_in - l.sqft_out) AS st
        FROM erp_stock_ledger l JOIN erp_products p ON p.id = l.product_id
        GROUP BY p.id, p.item_name HAVING st < -0.005 ORDER BY st ASC LIMIT 5") as $n) {
    $alerts[] = ['color' => '#c62828', 'order' => 2, 'title' => 'নেগেটিভ স্টক',
                 'text' => $n['item_name'] . ' (ঘাটতি ' . number_format(abs($n['st']), 2) . ' SFT)', 'sub' => 'নতুন Purchase / GRN দরকার'];
}
// ৩) ৩ দিনের বেশি ডেলিভারি বাকি (ইনভয়েস ধরে একবার)
$seen = [];
foreach ($pend as $p) {
    if (isset($seen[$p['invoice_no']])) continue;
    $days = (int)floor((time() - strtotime($p['invoice_date'])) / 86400);
    if ($days > 3) {
        $seen[$p['invoice_no']] = true;
        $alerts[] = ['color' => '#1565c0', 'order' => 3, 'title' => 'ডেলিভারি পেন্ডিং ' . $days . ' দিন',
                     'text' => $p['invoice_no'] . ' (' . $p['customer_name'] . ')', 'sub' => 'কাস্টমারকে ফোন করুন'];
    }
}
usort($alerts, function ($a, $b) { return $a['order'] <=> $b['order']; });

$cash_pct_s = pct($sale['cash_today'], $sale['total_today']);
$cash_pct_p = pct($pur['cash_today'], $pur['total_today']);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
    body { margin: 0; padding: 20px 24px 28px; background: #f1f5f2; color: #1f2933; font-size: 14px; }
    .head { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
    .title { font-size: 24px; font-weight: 700; color: #1b5e20; margin: 0; }
    .cap { font-size: 11.5px; color: #52606d; }
    .r3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
    .r2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
    .card { background: #fff; border: 1px solid #dbe4de; border-top: 4px solid var(--c); border-radius: 10px; padding: 14px 16px 12px; box-shadow: 0 1px 3px rgba(20,40,30,.07); }
    .ch { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .ic { width: 34px; height: 34px; border-radius: 9px; background: var(--c); display: flex; align-items: center; justify-content: center; flex: none; color: #fff; font-size: 15px; }
    .ch h3 { margin: 0; font-size: 15px; letter-spacing: .2px; }
    .ch small { display: block; color: #52606d; font-size: 11.5px; font-weight: 400; }
    .m { display: flex; justify-content: space-between; align-items: baseline; padding: 5px 0; border-bottom: 1px dashed #e3e9e5; }
    .m:last-child { border-bottom: 0; }
    .m span { color: #52606d; }
    .m b { font-variant-numeric: tabular-nums; }
    .hl { background: #f3f8f4; border-radius: 8px; padding: 8px 10px; margin: 6px 0; display: flex; justify-content: space-between; align-items: baseline; }
    .hl b { font-size: 22px; color: var(--c); }
    .bar { height: 7px; border-radius: 4px; background: #e3e9e5; overflow: hidden; margin: 6px 0 2px; }
    .bar i { display: block; height: 100%; background: var(--c); }
    .neg { color: #b42318; } .pos { color: #1e6b30; }
    .scroll { overflow: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: #e4efe7; text-align: left; padding: 7px 9px; font-size: 12.5px; border-bottom: 1px solid #c5d6ca; position: sticky; top: 0; z-index: 1; }
    td { padding: 7px 9px; border-bottom: 1px solid #edf1ee; }
    .r { text-align: right; font-variant-numeric: tabular-nums; }
    .empty { text-align: center; color: #888; padding: 14px; }
    #stockQ { width: 100%; padding: 9px 12px; border: 1px solid #9fb3a5; border-radius: 8px; font-size: 14px; margin: 4px 0 8px; outline: none; }
    #stockQ:focus { border-color: #2e7d32; }
    .stock-wrap { max-height: 230px; overflow-y: auto; border: 1px solid #dbe4de; border-radius: 6px; }
    .btn { background: #455a64; color: #fff; border: 0; border-radius: 6px; padding: 9px 16px; font-weight: 600; font-size: 13px; opacity: .6; cursor: not-allowed; }
    .al { display: flex; gap: 10px; padding: 9px 0; border-bottom: 1px solid #edf1ee; align-items: flex-start; }
    .al:last-child { border-bottom: 0; }
    .dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex: none; }
    @media (max-width: 900px) { .r3, .r2 { grid-template-columns: minmax(0, 1fr); } }
</style>
</head>
<body>

<div class="head">
    <div><h1 class="title">Dashboard Overview</h1><div class="cap">আজকের তারিখ: <?php echo date('d-M-Y'); ?></div></div>
    <div class="cap">শেষ আপডেট: <?php echo date('h:i A'); ?></div>
</div>

<div class="r3">
    <div class="card" style="--c:#2e7d32">
        <div class="ch"><div class="ic"><i class="fa-solid fa-chart-line"></i></div><h3>Sales Report<small>বিক্রয় প্রতিবেদন</small></h3></div>
        <div class="hl"><span>Sales Today</span><b><?php echo bd($sale['total_today']); ?></b></div>
        <div class="bar"><i style="width:<?php echo $cash_pct_s; ?>%"></i></div>
        <div class="cap" style="margin-bottom:4px"><?php echo $sale['total_today'] > 0.004 ? 'নগদ ' . $cash_pct_s . '% · বাকি ' . (100 - $cash_pct_s) . '%' : 'আজ কোনো বিক্রি নেই'; ?></div>
        <div class="m"><span>Cash</span><b><?php echo bd($sale['cash_today']); ?></b></div>
        <div class="m"><span>Due</span><b class="neg"><?php echo bd($sale['due_today']); ?></b></div>
        <div class="m"><span>This Month Total</span><b><?php echo bd($sale['total_month']); ?></b></div>
    </div>

    <div class="card" style="--c:#1565c0">
        <div class="ch"><div class="ic"><i class="fa-solid fa-cart-shopping"></i></div><h3>Purchase Report<small>ক্রয় প্রতিবেদন</small></h3></div>
        <div class="hl"><span>Purchase Today</span><b><?php echo bd($pur['total_today']); ?></b></div>
        <div class="bar"><i style="width:<?php echo $cash_pct_p; ?>%"></i></div>
        <div class="cap" style="margin-bottom:4px"><?php echo $pur['total_today'] > 0.004 ? 'নগদ ' . $cash_pct_p . '% · বাকি ' . (100 - $cash_pct_p) . '%' : 'আজ কোনো ক্রয় নেই'; ?></div>
        <div class="m"><span>Cash</span><b><?php echo bd($pur['cash_today']); ?></b></div>
        <div class="m"><span>Due</span><b class="neg"><?php echo bd($pur['due_today']); ?></b></div>
        <div class="m"><span>This Month Total</span><b><?php echo bd($pur['total_month']); ?></b></div>
    </div>

    <div class="card" style="--c:#6a1b9a">
        <div class="ch"><div class="ic"><i class="fa-solid fa-coins"></i></div><h3>Income / Expenses Report<small>আয়-ব্যয় প্রতিবেদন</small></h3></div>
        <div class="m"><span>Income Today</span><b class="pos"><?php echo bd($ie['inc_today']); ?></b></div>
        <div class="m"><span>Expenses Today</span><b class="neg"><?php echo bd($ie['exp_today']); ?></b></div>
        <div class="m"><span>This Month Total Income</span><b class="pos"><?php echo bd($ie['inc_month']); ?></b></div>
        <div class="m"><span>This Month Total Expenses</span><b class="neg"><?php echo bd($ie['exp_month']); ?></b></div>
    </div>
</div>

<div class="r3">
    <div class="card" style="--c:#e65100">
        <div class="ch"><div class="ic"><i class="fa-solid fa-hand-holding-dollar"></i></div><h3>Liabilities (Due)<small>পাওনা ও দেনা</small></h3></div>
        <div class="m"><span>Collection Today</span><b class="pos"><?php echo bd($cp['coll']); ?></b></div>
        <div class="m"><span>Payment Today</span><b class="neg"><?php echo bd($cp['pay']); ?></b></div>
        <div class="m"><span>Total Receivable Due</span><b><?php echo bd($recv); ?></b></div>
        <div class="m"><span>Total Payable Due</span><b><?php echo bd($payb); ?></b></div>
    </div>

    <div class="card" style="--c:#00796b">
        <div class="ch"><div class="ic"><i class="fa-solid fa-wallet"></i></div><h3>Available Amount<small>বর্তমান ফান্ড</small></h3></div>
        <div class="m"><span>Cash In Hand</span><b><?php echo bd($fund['CASH']); ?></b></div>
        <div class="m"><span>Bank Balance</span><b><?php echo bd($fund['BANK']); ?></b></div>
        <div class="m"><span>MFS Balance</span><b><?php echo bd($fund['MFS']); ?></b></div>
        <div class="hl" style="margin-top:8px"><span>Total Available</span><b style="font-size:20px"><?php echo bd($fund_total); ?></b></div>
    </div>

    <div class="card" style="--c:#455a64">
        <div class="ch"><div class="ic"><i class="fa-solid fa-comment-sms"></i></div><h3>SMS Balance<small>এসএমএস ব্যালেন্স</small></h3></div>
        <div class="hl"><span>Remaining SMS</span><b><?php echo bd($sms_balance); ?></b></div>
        <div class="bar"><i style="width:0%"></i></div>
        <div class="cap" style="margin-bottom:10px">SMS সেবা এখনো যুক্ত হয়নি</div>
        <button class="btn" type="button" disabled title="SMS সেবা যুক্ত হলে চালু হবে">Recharge / Details</button>
    </div>
</div>

<div class="r2">
    <div class="card" style="--c:#2e7d32">
        <div class="ch"><div class="ic"><i class="fa-solid fa-magnifying-glass"></i></div><h3>Stock Search<small>পণ্যের স্টক খুঁজুন</small></h3></div>
        <label for="stockQ" class="cap">পণ্যের নাম বা কোড</label>
        <input type="text" id="stockQ" placeholder="পণ্যের নাম বা কোড লিখুন..." oninput="stockSearch()" autocomplete="off">
        <div class="stock-wrap">
            <table>
                <thead><tr><th>Product</th><th>Size</th><th>Color</th><th class="r">Stock (SFT)</th><th class="r">Box</th><th class="r">Pcs</th></tr></thead>
                <tbody id="stockResult"></tbody>
            </table>
        </div>
    </div>

    <div class="card" style="--c:#f9a825">
        <div class="ch"><div class="ic" style="background:#b45309"><i class="fa-solid fa-bell"></i></div><h3>Today's Alerts &amp; Reminders<small>আজকের সতর্কবার্তা ও মনে করিয়ে দেওয়া</small></h3></div>
        <div class="scroll" style="max-height:300px;">
        <?php if ($alerts) { foreach ($alerts as $a) { ?>
            <div class="al"><span class="dot" style="background:<?php echo h($a['color']); ?>"></span>
                <div><b><?php echo h($a['title']); ?></b> — <?php echo h($a['text']); ?><div class="cap"><?php echo h($a['sub']); ?></div></div></div>
        <?php } } else echo '<div class="empty">আজ কোনো সতর্কবার্তা নেই ✓</div>'; ?>
        </div>
    </div>
    
</div>

<div class="r2">
    <div class="card" style="--c:#1565c0">
        <div class="ch"><div class="ic"><i class="fa-solid fa-star"></i></div><h3>Top Selling Products<small>চলতি মাস</small></h3></div>
        <div class="scroll"><table>
            <thead><tr><th style="width:34px">SL</th><th>Product</th><th class="r">Sold (SFT)</th><th class="r">Amount (BDT)</th></tr></thead>
            <tbody>
            <?php if ($top) { $i = 1; foreach ($top as $t) { ?>
                <tr><td><?php echo $i++; ?></td>
                    <td><b><?php echo h($t['item_name']); ?></b> <span class="cap"><?php echo h($t['size_name']); ?></span>
                        <div class="bar" style="--c:#1565c0"><i style="width:<?php echo $top_max > 0 ? round($t['sold_sft'] / $top_max * 100) : 0; ?>%"></i></div></td>
                    <td class="r"><?php echo number_format($t['sold_sft'], 2); ?></td>
                    <td class="r"><?php echo number_format($t['sold_amount'], 2); ?></td></tr>
            <?php } } else echo '<tr><td colspan="4" class="empty">এই মাসে কোনো বিক্রি নেই।</td></tr>'; ?>
            </tbody></table></div>
    </div>

    <div class="card" style="--c:#c62828">
        <div class="ch"><div class="ic"><i class="fa-solid fa-truck"></i></div><h3>Delivery Pending List<small>ডেলিভারি বাকি</small></h3></div>
        <div class="scroll" style="max-height:320px;"><table>
            <thead><tr><th>Invoice</th><th>Customer</th><th>Product</th><th class="r">Pending</th></tr></thead>
            <tbody>
            <?php if ($pend) { foreach ($pend as $p) { ?>
                <tr><td><b style="color:#1e4273;"><?php echo h($p['invoice_no']); ?></b><div class="cap"><?php echo date('d-M-y', strtotime($p['invoice_date'])); ?></div></td>
                    <td><?php echo h($p['customer_name']); ?></td>
                    <td><?php echo h($p['item_name']); ?></td>
                    <td class="r"><b class="neg"><?php echo number_format($p['pending_sft'], 2); ?> SFT</b><div class="cap"><?php echo h(box_pcs($p['pending_sft'], $p)); ?></div></td></tr>
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