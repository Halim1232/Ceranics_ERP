<?php
// Report/daily_transaction.php — Daily Transaction Report (অ্যাকাউন্ট অনুযায়ী গ্রুপ: Cash in Hand → Bank → MFS)
// Source : erp_fund_ledger (txn_date, account_id, txn_type IN|OUT, amount, ref_type, ref_no)
//          erp_fund_accounts (id, account_name [, account_type, opening_balance])
include('../auth.php'); require_page_access(); include('../config.php');

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
function nf($v) { return number_format((float)$v, 2); }
function table_cols($conn, $t) {
    $o = [];
    try { $q = mysqli_query($conn, "SHOW COLUMNS FROM $t"); while ($q && ($r = mysqli_fetch_assoc($q))) $o[] = $r['Field']; } catch (Throwable $e) {}
    return $o;
}
function first_col(array $have, array $want) { foreach ($want as $c) if (in_array($c, $have, true)) return $c; return null; }
function sql_in($conn, array $vals) {
    $o = []; foreach ($vals as $v) $o[] = "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
    return $o ? implode(',', $o) : "''";
}


// Payment Voucher (PV-) / Collection Voucher (CV-) এর ভাউচার টেবিল অটো-ডিটেক্ট করে সাপ্লায়ার/কাস্টমারের নাম বের করে
function voucher_party_lookup($conn, array $refNos, $kind) {   // $kind: 'SUPPLIER' | 'CUSTOMER'
    $names = [];
    if (!$refNos) return $names;
    static $tables = null;
    if ($tables === null) {
        $tables = [];
        try { $tq = mysqli_query($conn, "SHOW TABLES"); while ($tq && ($t = mysqli_fetch_row($tq))) $tables[] = $t[0]; } catch (Throwable $e) {}
    }
    $no_c    = ['voucher_no', 'pv_no', 'cv_no', 'payment_no', 'collection_no', 'receipt_no', 'txn_no', 'ref_no'];
    $party_c = $kind === 'SUPPLIER' ? ['supplier_id', 'vendor_id', 'party_id'] : ['customer_id', 'party_id'];
    $name_c  = $kind === 'SUPPLIER' ? ['supplier_name', 'party_name'] : ['customer_name', 'party_name'];
    $pids = [];
    foreach ($tables as $t) {
        if (!preg_match('/voucher|payment|receipt|collection|trxn|transaction/i', $t)) continue;
        if (preg_match('/^erp_fund_|detail/i', $t)) continue;
        $cols = table_cols($conn, $t);
        $nc = first_col($cols, $no_c); if (!$nc) continue;
        $pc = first_col($cols, $party_c); $nm = first_col($cols, $name_c);
        if (!$pc && !$nm) continue;
        $sel = "`$nc` AS ref" . ($pc ? ", `$pc` AS pid" : "") . ($nm ? ", `$nm` AS pname" : "");
        try {
            $q = mysqli_query($conn, "SELECT $sel FROM `$t` WHERE `$nc` IN (" . sql_in($conn, $refNos) . ")");
            while ($q && ($r = mysqli_fetch_assoc($q))) {
                if (!empty($r['pname'])) $names[$r['ref']] = $r['pname'];
                elseif (!empty($r['pid'])) $pids[$r['ref']] = (int)$r['pid'];
            }
        } catch (Throwable $e) { continue; }
        if (count($names) + count($pids) >= count($refNos)) break;
    }
    if ($pids) {
        $tbl = $kind === 'SUPPLIER' ? ['erp_suppliers', 'supplier_name'] : ['erp_customers', 'customer_name'];
        try {
            $q = mysqli_query($conn, "SELECT id, {$tbl[1]} AS nm FROM {$tbl[0]} WHERE id IN (" . implode(',', array_unique($pids)) . ")");
            $byid = []; while ($q && ($r = mysqli_fetch_assoc($q))) $byid[(int)$r['id']] = $r['nm'];
            foreach ($pids as $ref => $pid) if (isset($byid[$pid]) && !isset($names[$ref])) $names[$ref] = $byid[$pid];
        } catch (Throwable $e) {}
    }
    return $names;
}

// ---------- কলাম অটো-ডিটেকশন ----------
$acc_cols = table_cols($conn, 'erp_fund_accounts');
$led_cols = table_cols($conn, 'erp_fund_ledger');
$type_col = first_col($acc_cols, ['account_type', 'type', 'group_name', 'category']);
$open_col = in_array('opening_balance', $acc_cols, true) ? 'opening_balance' : null;
$note_col = first_col($led_cols, ['remarks', 'narration', 'note', 'description']);

function acc_group($name, $type) {
    $t = strtolower((string)$type); $n = strtolower((string)$name);
    foreach ([$t, $n] as $s) {
        if ($s === '') continue;
        if (preg_match('/bkash|b-kash|nagad|rocket|upay|mfs|mobile|wallet/', $s)) return 'MFS';
        if (preg_match('/cash/', $s))  return 'CASH';
        if (preg_match('/bank/', $s))  return 'BANK';
    }
    return 'OTHER';
}

// ---------- অ্যাকাউন্ট লোড ----------
$accounts = [];
$sel = "id, account_name" . ($type_col ? ", `$type_col` AS acc_type" : "") . ($open_col ? ", `$open_col` AS opening" : "");
$q = mysqli_query($conn, "SELECT $sel FROM erp_fund_accounts ORDER BY id ASC");
while ($q && ($a = mysqli_fetch_assoc($q))) {
    $a['grp'] = acc_group($a['account_name'], $a['acc_type'] ?? '');
    $a['opening'] = (float)($a['opening'] ?? 0);
    $accounts[(int)$a['id']] = $a;
}

// ---------- ফিল্টার ----------
$today = date('Y-m-d');
$from  = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $today;
$to    = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $today;
if ($to < $from) { $t = $from; $from = $to; $to = $t; }
$tab   = strtoupper($_GET['type'] ?? 'ALL');
if (!in_array($tab, ['ALL', 'CASH', 'BANK', 'MFS'], true)) $tab = 'ALL';

$tab_accounts = [];
foreach ($accounts as $id => $a) { if ($tab === 'ALL' || $a['grp'] === $tab) $tab_accounts[$id] = $a; }
$acc_sel = (int)($_GET['acc'] ?? 0);
if (!isset($tab_accounts[$acc_sel])) $acc_sel = 0;
$use_accounts = $acc_sel ? [$acc_sel => $tab_accounts[$acc_sel]] : $tab_accounts;

// ক্রম: Cash in Hand → Bank → MFS → Other (প্রতিটি গ্রুপে id অনুযায়ী)
$grp_order = ['CASH' => 1, 'BANK' => 2, 'MFS' => 3, 'OTHER' => 4];
uasort($use_accounts, function ($x, $y) use ($grp_order) {
    return [$grp_order[$x['grp']], (int)$x['id']] <=> [$grp_order[$y['grp']], (int)$y['id']];
});
$ids = $use_accounts ? implode(',', array_map('intval', array_keys($use_accounts))) : '0';

$grp_label = ['CASH' => 'Cash in Hand', 'BANK' => 'Bank', 'MFS' => 'MFS', 'OTHER' => 'Other'];
$grp_color = ['CASH' => '#2e7d32', 'BANK' => '#5e35b1', 'MFS' => '#d81b60', 'OTHER' => '#607d8b'];

// ---------- অ্যাকাউন্ট ভিত্তিক Opening ----------
$summary = [];
foreach ($use_accounts as $id => $a) $summary[$id] = ['open' => $a['opening'], 'in' => 0.0, 'out' => 0.0];
$sq = mysqli_query($conn, "SELECT account_id,
        SUM(CASE WHEN txn_date < '$from' THEN (CASE WHEN txn_type='IN' THEN amount ELSE -amount END) ELSE 0 END) AS open_mv
    FROM erp_fund_ledger WHERE account_id IN ($ids) AND txn_date < '$from' GROUP BY account_id");
while ($sq && ($s = mysqli_fetch_assoc($sq))) {
    $id = (int)$s['account_id'];
    if (isset($summary[$id])) $summary[$id]['open'] += (float)$s['open_mv'];
}

// ---------- লেনদেন ----------
$rows = [];
$rq = mysqli_query($conn, "SELECT l.id, l.txn_date, l.account_id, l.txn_type, l.amount, l.ref_type, l.ref_no" . ($note_col ? ", l.`$note_col` AS note" : "") . "
    FROM erp_fund_ledger l
    WHERE l.account_id IN ($ids) AND l.txn_date >= '$from' AND l.txn_date < DATE_ADD('$to', INTERVAL 1 DAY)
    ORDER BY l.txn_date ASC, l.id ASC");
while ($rq && ($r = mysqli_fetch_assoc($rq))) $rows[] = $r;

// ---------- Particulars: Sales → Customer, Payment → Supplier, Transfer → অপর অ্যাকাউন্ট ----------
$refs = ['SALE' => [], 'PAYMENT' => [], 'TRANSFER' => []];
foreach ($rows as $r) { $k = strtoupper((string)$r['ref_type']); if (isset($refs[$k])) $refs[$k][(string)$r['ref_no']] = true; }
$party = []; $legs = [];
try {
    if ($refs['SALE']) {
        $pq = mysqli_query($conn, "SELECT m.Invoice_no AS ref, c.customer_name AS name FROM sales_invoice_master m JOIN erp_customers c ON c.id = m.customer_id WHERE m.Invoice_no IN (" . sql_in($conn, array_keys($refs['SALE'])) . ")");
        while ($pq && ($p = mysqli_fetch_assoc($pq))) $party['SALE|' . $p['ref']] = $p['name'];
    }
    if ($refs['PAYMENT']) {
        $pq = mysqli_query($conn, "SELECT g.grn_no AS ref, s.supplier_name AS name FROM erp_purchase_grn g JOIN erp_suppliers s ON s.id = g.supplier_id WHERE g.grn_no IN (" . sql_in($conn, array_keys($refs['PAYMENT'])) . ")");
        while ($pq && ($p = mysqli_fetch_assoc($pq))) $party['PAYMENT|' . $p['ref']] = $p['name'];
    }
    if ($refs['TRANSFER']) {   // ট্রান্সফারের দুই পাশের অ্যাকাউন্ট (ফিল্টারের বাইরের পাশটাও লাগে)
        $pq = mysqli_query($conn, "SELECT ref_no, account_id, txn_type FROM erp_fund_ledger WHERE ref_type='TRANSFER' AND ref_no IN (" . sql_in($conn, array_keys($refs['TRANSFER'])) . ")");
        while ($pq && ($p = mysqli_fetch_assoc($pq))) $legs[$p['ref_no']][strtoupper($p['txn_type'])] = (int)$p['account_id'];
    }
} catch (Throwable $e) { /* নাম না পেলেও রিপোর্ট চলবে */ }

// GRN-এর বাইরের Payment Voucher (PV-) ও Collection Voucher (CV-)
$need_sup = $need_cus = [];
foreach ($rows as $r) {
    $k = strtoupper((string)$r['ref_type']); $rn = (string)$r['ref_no'];
    if (!empty($party[$k . '|' . $rn]) || $k === 'TRANSFER') continue;
    if ($k === 'PAYMENT' || stripos($rn, 'PV') === 0)         $need_sup[$rn] = $rn;
    elseif ($k === 'COLLECTION' || stripos($rn, 'CV') === 0)  $need_cus[$rn] = $rn;
}
$vparty = voucher_party_lookup($conn, array_values($need_sup), 'SUPPLIER') + voucher_party_lookup($conn, array_values($need_cus), 'CUSTOMER');

$ref_label = ['SALE' => 'Sales Receipt', 'PAYMENT' => 'Supplier Payment', 'TRANSFER' => 'Fund Transfer', 'COLLECTION' => 'Collection'];

// ---------- অ্যাকাউন্ট অনুযায়ী ব্লক তৈরি (প্রতিটির নিজস্ব running balance) ----------
$blocks = [];
foreach ($use_accounts as $id => $a) $blocks[$id] = ['acc' => $a, 'txns' => []];
foreach ($rows as $r) {
    $id = (int)$r['account_id']; if (!isset($blocks[$id])) continue;
    $amt = (float)$r['amount']; $is_in = (strtoupper((string)$r['txn_type']) === 'IN');
    $k = strtoupper((string)$r['ref_type']);
    $name = $party[$k . '|' . $r['ref_no']] ?? ($vparty[$r['ref_no']] ?? '');
    if ($k === 'TRANSFER' && isset($legs[$r['ref_no']])) {
        $other = $is_in ? ($legs[$r['ref_no']]['OUT'] ?? 0) : ($legs[$r['ref_no']]['IN'] ?? 0);
        if ($other && isset($accounts[$other])) $name = ($is_in ? 'From: ' : 'To: ') . $accounts[$other]['account_name'];
    }
    $note = trim((string)($r['note'] ?? ''));
    $blocks[$id]['txns'][] = [
        'day' => substr((string)$r['txn_date'], 0, 10),
        'ref_type' => $ref_label[$k] ?? ($r['ref_type'] ?: '-'), 'ref_no' => $r['ref_no'],
        'part' => trim($name . ($note !== '' ? ($name !== '' ? ' — ' : '') . $note : '')),
        'in' => $is_in ? $amt : 0, 'out' => $is_in ? 0 : $amt,
    ];
}
$opening = $tot_in = $tot_out = 0.0;
foreach ($blocks as $id => &$b) {
    $bal = $summary[$id]['open']; $ti = $to_ = 0.0;
    foreach ($b['txns'] as &$t) { $bal += $t['in'] - $t['out']; $t['bal'] = $bal; $ti += $t['in']; $to_ += $t['out']; }
    unset($t);
    $b['open'] = $summary[$id]['open']; $b['in'] = $ti; $b['out'] = $to_; $b['close'] = $bal;
    $opening += $b['open']; $tot_in += $ti; $tot_out += $to_;
}
unset($b);
$closing = $opening + $tot_in - $tot_out;

// লেনদেন/ব্যালেন্স না থাকা অ্যাকাউন্ট লুকানো (নির্দিষ্ট অ্যাকাউন্ট বেছে নিলে সবসময় দেখাবে)
if (!$acc_sel) {
    foreach ($blocks as $id => $b) {
        if (!$b['txns'] && abs($b['open']) < 0.005) unset($blocks[$id]);
    }
}

// ---------- CSV Export ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Daily_Transaction_' . $from . '_to_' . $to . '.csv');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Account', 'Date', 'Ref Type', 'Ref No', 'Particulars', 'Trxn In', 'Trxn Out', 'Balance']);
    foreach ($blocks as $b) {
        $nm = $b['acc']['account_name'];
        fputcsv($f, [$nm, $from, 'Opening Balance', '', '', '', '', number_format($b['open'], 2, '.', '')]);
        foreach ($b['txns'] as $t) {
            fputcsv($f, [$nm, $t['day'], $t['ref_type'], $t['ref_no'], $t['part'],
                number_format($t['in'], 2, '.', ''), number_format($t['out'], 2, '.', ''), number_format($t['bal'], 2, '.', '')]);
        }
        fputcsv($f, [$nm, $to, 'Total / Closing', '', '', number_format($b['in'], 2, '.', ''), number_format($b['out'], 2, '.', ''), number_format($b['close'], 2, '.', '')]);
        fputcsv($f, []);
    }
    fputcsv($f, ['GRAND TOTAL', '', '', '', '', number_format($tot_in, 2, '.', ''), number_format($tot_out, 2, '.', ''), number_format($closing, 2, '.', '')]);
    fclose($f); exit;
}

function tab_url($type, $from, $to) { return '?' . http_build_query(['type' => $type, 'from' => $from, 'to' => $to]); }
$title_tab = ['ALL' => 'All Transaction', 'CASH' => 'Cash in Hand', 'BANK' => 'Bank', 'MFS' => 'MFS'][$tab];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Transaction Report</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #263238; }
    .title { font-size: 15px; font-weight: bold; color: #2e7d32; text-transform: uppercase; margin-bottom: 4px; }
    .period { font-size: 12px; color: #666; margin-bottom: 10px; }
    .tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
    .tab { padding: 8px 18px; border: 1px solid #cddc39; background: #fff; border-radius: 4px; font-size: 13px; font-weight: bold; color: #444; text-decoration: none; }
    .tab.active { background: #2e7d32; color: #fff; border-color: #2e7d32; }
    .panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; padding: 12px; margin-bottom: 12px; }
    .filter-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 30px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; outline: none; }
    .btn { height: 30px; padding: 0 14px; font-size: 12px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
    .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 14px; }
    .card { background: #fff; padding: 12px 14px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .lbl { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .val { font-size: 19px; font-weight: bold; margin-top: 4px; }

    /* অ্যাকাউন্ট ব্লক */
    .block { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 16px; overflow: hidden; }
    td, th { line-height: 1.2; }
    tr.grp-head td { color: #fff !important; font-size: 13px; font-weight: bold; padding: 6px 12px; border-bottom: none; }
    tr.grp-head .gl { font-size: 10px; background: rgba(255,255,255,.25); padding: 1px 8px; border-radius: 10px; margin-left: 6px; }
    tr.gap td { height: 6px; padding: 0; background: #fafafa; border: none; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
    th { background: #dbebe7; padding: 7px 8px; text-align: left; border: 1px solid #cddc39; border-left: none; border-right: none; }
    td { padding: 5px 8px; border-bottom: 1px solid #eee; background: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .r { text-align: right; }
    .in { color: #2e7d32; font-weight: bold; }
    .outc { color: #d32f2f; font-weight: bold; }
    tr.open-row td { background: #fffde7; font-weight: bold; }
    tr.total-row td { background: #eef5f3; font-weight: bold; border-top: 2px solid #2e7d32; border-bottom: none; }
    tr.grand-row td { background: #dbebe7; font-weight: bold; font-size: 14px; border-top: 2px solid #2e7d32; }
    .empty-msg { padding: 14px; text-align: center; color: #999; font-size: 13px; }
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; padding: 0; }
        .block { page-break-inside: auto; }
        tr.grp-head td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        tr.open-row td, tr.total-row td, th, tr.grand-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="title">Daily Transaction Report — <?php echo h($title_tab); ?></div>
<div class="period">Period: <?php echo date('d-M-Y', strtotime($from)); ?> — <?php echo date('d-M-Y', strtotime($to)); ?></div>

<div class="tabs no-print">
    <a class="tab <?php echo $tab === 'ALL'  ? 'active' : ''; ?>" href="<?php echo tab_url('ALL',  $from, $to); ?>"><i class="fa-solid fa-list"></i> All Transaction</a>
    <a class="tab <?php echo $tab === 'CASH' ? 'active' : ''; ?>" href="<?php echo tab_url('CASH', $from, $to); ?>"><i class="fa-solid fa-wallet"></i> Cash in Hand</a>
    <a class="tab <?php echo $tab === 'BANK' ? 'active' : ''; ?>" href="<?php echo tab_url('BANK', $from, $to); ?>"><i class="fa-solid fa-building-columns"></i> Bank</a>
    <a class="tab <?php echo $tab === 'MFS'  ? 'active' : ''; ?>" href="<?php echo tab_url('MFS',  $from, $to); ?>"><i class="fa-solid fa-mobile-screen"></i> MFS</a>
</div>

<form method="GET" class="panel no-print">
    <input type="hidden" name="type" value="<?php echo h($tab); ?>">
    <div class="filter-row">
        <div class="fg"><label>From Date</label><input type="date" name="from" class="fc" value="<?php echo h($from); ?>"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to" class="fc" value="<?php echo h($to); ?>"></div>
        <div class="fg"><label>Account</label>
            <select name="acc" class="fc" style="min-width:190px;" onchange="this.form.submit()">
                <option value="0">All <?php echo $tab === 'ALL' ? 'Accounts' : h($title_tab) . ' Accounts'; ?></option>
                <?php foreach ($tab_accounts as $id => $a) { ?>
                    <option value="<?php echo $id; ?>" <?php echo $id === $acc_sel ? 'selected' : ''; ?>><?php echo h($a['account_name']); ?></option>
                <?php } ?>
            </select></div>
        <div class="fg"><label>Search (Ref / Party / Note)</label><input type="text" id="q" class="fc" placeholder="Search.." oninput="liveSearch()"></div>
        <button type="submit" class="btn" style="background:#2e7d32;"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
        <a class="btn" style="background:#6c757d;" href="?type=<?php echo h($tab); ?>">Today</a>
        <button type="button" class="btn" style="background:#2b579a;" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <a class="btn" style="background:#217346;" href="?<?php echo h(http_build_query(['type' => $tab, 'from' => $from, 'to' => $to, 'acc' => $acc_sel, 'export' => 'csv'])); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
    </div>
</form>

<div class="cards">
    <div class="card" style="border-color:#f9a825;"><div class="lbl">Opening Balance</div><div class="val"><?php echo nf($opening); ?></div></div>
    <div class="card"><div class="lbl">Total Trxn In</div><div class="val in"><?php echo nf($tot_in); ?></div></div>
    <div class="card" style="border-color:#d32f2f;"><div class="lbl">Total Trxn Out</div><div class="val outc"><?php echo nf($tot_out); ?></div></div>
    <div class="card" style="border-color:#0288d1;"><div class="lbl">Closing Balance</div><div class="val" style="color:#0288d1;"><?php echo nf($closing); ?></div></div>
</div>

<?php if (!$blocks) { ?>
    <div class="panel" style="text-align:center; color:#999;">কোনো অ্যাকাউন্ট বা লেনদেন পাওয়া যায়নি।</div>
<?php } else { ?>
<div class="block">
<table>
    <colgroup>
        <col style="width:48px"><col style="width:105px"><col style="width:140px"><col style="width:120px"><col>
        <col style="width:125px"><col style="width:125px"><col style="width:130px">
    </colgroup>
    <thead>
        <tr>
            <th style="text-align:center;">SL</th><th>Date</th><th>Ref Type</th><th>Ref No</th><th>Particulars</th>
            <th class="r">Trxn In</th><th class="r">Trxn Out</th><th class="r">Balance</th>
        </tr>
    </thead>
    <tbody>
    <?php $first = true; foreach ($blocks as $id => $b) { $a = $b['acc']; ?>
        <?php if (!$first) { ?><tr class="gap"><td colspan="8"></td></tr><?php } $first = false; ?>
        <tr class="grp-head">
            <td colspan="8" style="background:<?php echo $grp_color[$a['grp']]; ?>;"><?php echo h($a['account_name']); ?> <span class="gl"><?php echo h($grp_label[$a['grp']]); ?></span></td>
        </tr>
        <tr class="open-row">
            <td colspan="7" style="text-align:right;">Opening Balance (<?php echo date('d-M-Y', strtotime($from)); ?>)</td>
            <td class="r"><?php echo nf($b['open']); ?></td>
        </tr>
        <?php $sl = 1; foreach ($b['txns'] as $t) { ?>
        <tr class="txn-row">
            <td style="text-align:center;"><?php echo $sl++; ?></td>
            <td><?php echo date('d-M-Y', strtotime($t['day'])); ?></td>
            <td><?php echo h($t['ref_type']); ?></td>
            <td style="font-weight:bold; color:#2b579a;"><?php echo h($t['ref_no']); ?></td>
            <td title="<?php echo h($t['part']); ?>"><?php echo h($t['part'] !== '' ? $t['part'] : '-'); ?></td>
            <td class="r in"><?php echo $t['in']  > 0 ? nf($t['in'])  : ''; ?></td>
            <td class="r outc"><?php echo $t['out'] > 0 ? nf($t['out']) : ''; ?></td>
            <td class="r"><?php echo nf($t['bal']); ?></td>
        </tr>
        <?php } ?>
        <?php if (!$b['txns']) { ?><tr><td colspan="8" class="empty-msg">এই সময়ে কোনো লেনদেন নেই।</td></tr><?php } ?>
        <tr class="total-row">
            <td colspan="5" style="text-align:right;">Total — Closing Balance</td>
            <td class="r in"><?php echo nf($b['in']); ?></td>
            <td class="r outc"><?php echo nf($b['out']); ?></td>
            <td class="r"><?php echo nf($b['close']); ?></td>
        </tr>
    <?php } ?>
    <?php if (count($blocks) > 1) { ?>
        <tr class="gap"><td colspan="8"></td></tr>
        <tr class="grand-row">
            <td colspan="5" style="text-align:right;">GRAND TOTAL (<?php echo h($title_tab); ?>) — Closing Balance</td>
            <td class="r in"><?php echo nf($tot_in); ?></td>
            <td class="r outc"><?php echo nf($tot_out); ?></td>
            <td class="r"><?php echo nf($closing); ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div>
<?php } ?>

<script>
// শুধু স্ক্রিনে দেখানো রো ফিল্টার করে; Opening/Closing সার্ভারের হিসাবই থাকে
function liveSearch() {
    var q = document.getElementById('q').value.toUpperCase().trim();
    document.querySelectorAll('tr.txn-row').forEach(function (r) {
        r.style.display = (!q || r.textContent.toUpperCase().indexOf(q) > -1) ? '' : 'none';
    });
}
</script>
</body>
</html>