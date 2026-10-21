<?php
// ফান্ড অ্যাকাউন্ট (Cash / Bank / MFS) — চার্ট অফ অ্যাকাউন্টসের হেডের নামসহ ড্রপডাউনের জন্য
// Sales Invoice / Purchase GRN / Return পেজে include('../fund_lib.php'); দিয়ে ব্যবহার করুন

// চার্টের Fund কলাম (Cash / Bank / MFS) অনুযায়ী হেডের কোড ও নাম
function fl_coa_fund_map($conn) {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        $cols = [];
        $r = mysqli_query($conn, "SHOW COLUMNS FROM erp_chart_of_accounts");
        while ($x = mysqli_fetch_assoc($r)) $cols[strtolower($x['Field'])] = $x['Field'];
        $pick = function($c) use ($cols) { foreach ($c as $k) if (isset($cols[$k])) return $cols[$k]; return null; };
        $nm = $pick(['account_name', 'head_name', 'ac_name', 'name', 'account_head', 'title', 'accounts_name']);
        $cd = $pick(['account_code', 'head_code', 'ac_code', 'code']);
        $fd = $pick(['fund_type', 'fund', 'fund_category']);
        $st = $pick(['status']);
        if (!$nm || !$fd) return $map;
        $q = mysqli_query($conn, "SELECT `$nm` AS nm, " . ($cd ? "`$cd`" : "''") . " AS cd, `$fd` AS fd, " . ($st ? "`$st`" : "''") . " AS st FROM erp_chart_of_accounts");
        while ($x = mysqli_fetch_assoc($q)) {
            $f = strtolower(trim((string)$x['fd'])); $s = strtolower(trim((string)$x['st']));
            if ($f === '' || $f === '0' || in_array($s, ['inactive', '0'], true) || isset($map[$f])) continue;
            $map[$f] = ['code' => trim((string)$x['cd']), 'name' => trim((string)$x['nm'])];
        }
    } catch (Throwable $e) { /* চার্ট টেবিল না পেলে ফান্ড অ্যাকাউন্টের নামই দেখাবে */ }
    return $map;
}

// সক্রিয় ফান্ড অ্যাকাউন্ট + বর্তমান ব্যালেন্স + চার্ট হেডসহ লেবেল (coa_label)
function fl_fund_accounts($conn) {
    $out = []; $map = fl_coa_fund_map($conn);
    $r = mysqli_query($conn, "SELECT a.id, a.account_name, a.account_type,
            a.opening_balance + COALESCE(SUM(CASE WHEN l.txn_type='IN' THEN l.amount END),0) - COALESCE(SUM(CASE WHEN l.txn_type='OUT' THEN l.amount END),0) AS bal
            FROM erp_fund_accounts a LEFT JOIN erp_fund_ledger l ON l.account_id = a.id
            WHERE a.status='Active' GROUP BY a.id ORDER BY a.account_type, a.id");
    while ($a = mysqli_fetch_assoc($r)) {
        $cr = $map[strtolower($a['account_type'])] ?? null;
        if ($cr) $a['coa_label'] = ($cr['code'] !== '' ? $cr['code'] . ' - ' : '') . $cr['name'] . (strcasecmp(trim($a['account_name']), $cr['name']) === 0 ? '' : ' » ' . $a['account_name']);
        else     $a['coa_label'] = $a['account_name'] . ' (' . $a['account_type'] . ')';
        $out[] = $a;
    }
    return $out;
}

// <select> এর অপশন
function fl_acc_opts($accounts, $selected = 0) {
    $o = '<option value="">-- নির্বাচন করুন --</option>';
    foreach ($accounts as $a) {
        $o .= '<option value="' . (int)$a['id'] . '"' . ((int)$selected === (int)$a['id'] ? ' selected' : '') . '>' . htmlspecialchars($a['coa_label'], ENT_QUOTES) . ' — ' . number_format($a['bal'], 2) . '</option>';
    }
    return $o;
}