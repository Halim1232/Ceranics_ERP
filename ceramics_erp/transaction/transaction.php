<?php
include('../auth.php');
include('../config.php');
include('../balance_functions.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$CFG = [
    'collection' => ['t'=>'Collection Voucher',     'p'=>'CV', 'vt'=>'COLLECTION', 'fund'=>'IN',  'party'=>'CUSTOMER', 'plabel'=>'Customer'],
    'payment'    => ['t'=>'Payment Voucher',        'p'=>'PV', 'vt'=>'PAYMENT',    'fund'=>'OUT', 'party'=>'SUPPLIER', 'plabel'=>'Supplier'],
    'transfer'   => ['t'=>'Bank & Cash Transfer',   'p'=>'BT', 'vt'=>'TRANSFER'],
    'income'     => ['t'=>'Others Income',          'p'=>'OI', 'vt'=>'INCOME',     'fund'=>'IN',  'head'=>'Income Head'],
    'expense'    => ['t'=>'Daily Expenses',         'p'=>'DE', 'vt'=>'EXPENSE',    'fund'=>'OUT', 'head'=>'Expense Head'],
    'journal'    => ['t'=>'Adjustment (Journal)',   'p'=>'JV', 'vt'=>'JOURNAL'],
];
$type = strtolower($_GET['type'] ?? 'collection');
if (!isset($CFG[$type])) $type = 'collection';
require_page_access('transaction/transaction.php?type=' . $type);
$c  = $CFG[$type];
$vt = $c['vt'];

// ---------- জার্নালের সাহায্যকারী ফাংশন ----------
// ---- চার্ট অফ অ্যাকাউন্টস (erp_chart_of_accounts) পড়া — কলামের নাম অটো-ডিটেক্ট ----
function coa_rows($conn) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['rows' => [], 'm' => []];
    try {
        $cols = []; $pri = 'id';
        $r = mysqli_query($conn, "SHOW COLUMNS FROM erp_chart_of_accounts");
        while ($x = mysqli_fetch_assoc($r)) { $cols[strtolower($x['Field'])] = $x['Field']; if ($x['Key'] === 'PRI') $pri = $x['Field']; }
        $pick = function($cands) use ($cols) { foreach ($cands as $k) if (isset($cols[$k])) return $cols[$k]; return null; };
        $m = ['id'     => $pri,
              'name'   => $pick(['account_name', 'head_name', 'ac_name', 'name', 'account_head', 'title', 'accounts_name']),
              'code'   => $pick(['account_code', 'head_code', 'ac_code', 'code']),
              'type'   => $pick(['account_type', 'head_type', 'type', 'category', 'nature']),
              'parent' => $pick(['parent_id', 'parent', 'parent_code', 'parent_account', 'group_id']),
              'fund'   => $pick(['fund_type', 'fund', 'fund_category']),
              'status' => $pick(['status'])];
        if (!$m['name']) return $cache;
        $rows = [];
        $q = mysqli_query($conn, "SELECT * FROM erp_chart_of_accounts");
        while ($x = mysqli_fetch_assoc($q)) {
            $st = $m['status'] ? strtolower(trim((string)$x[$m['status']])) : '';
            $rows[] = ['id' => (string)$x[$pri], 'name' => trim((string)$x[$m['name']]),
                       'code' => $m['code'] ? trim((string)$x[$m['code']]) : '', 'type' => $m['type'] ? trim((string)$x[$m['type']]) : '',
                       'parent' => $m['parent'] ? trim((string)$x[$m['parent']]) : '', 'fund' => $m['fund'] ? trim((string)$x[$m['fund']]) : '',
                       'active' => !in_array($st, ['inactive', '0'], true)];
        }
        $cache = ['rows' => $rows, 'm' => $m];
    } catch (Throwable $e) { /* টেবিল না থাকলে খালি */ }
    return $cache;
}
// পেরেন্ট কলামে ID থাকে নাকি কোড — যেটা বেশি মেলে
function coa_parent_by_code($rows) {
    $ids = array_column($rows, 'id'); $codes = array_column($rows, 'code'); $mi = 0; $mc = 0;
    foreach ($rows as $r) {
        if ($r['parent'] === '' || $r['parent'] === '0') continue;
        if (in_array($r['parent'], $ids, true))   $mi++;
        if (in_array($r['parent'], $codes, true)) $mc++;
    }
    return $mc > $mi;
}
// পোস্টিং-যোগ্য (গ্রুপ নয়) সক্রিয় হেড; $type = 'Expense' / 'Income' ...; $excludeFund = Cash/Bank/MFS চিহ্নিত হেড বাদ
function coa_leaf_heads($conn, $type = null, $excludeFund = false) {
    $rows = coa_rows($conn)['rows']; if (!$rows) return [];
    $byCode = coa_parent_by_code($rows); $parents = [];
    foreach ($rows as $r) if ($r['parent'] !== '' && $r['parent'] !== '0') $parents[$r['parent']] = 1;
    $out = [];
    foreach ($rows as $r) {
        if (!$r['active']) continue;
        if ($r['parent'] === '' || $r['parent'] === '0') continue;                       // রুট = গ্রুপ হেড
        if (isset($parents[$byCode ? $r['code'] : $r['id']]) || isset($parents[$r['name']])) continue; // সন্তান আছে = গ্রুপ
        if ($type && strcasecmp($r['type'], $type) !== 0) continue;
        if ($excludeFund && $r['fund'] !== '' && $r['fund'] !== '0') continue;
        $out[] = $r;
    }
    usort($out, function($a, $b) { return strnatcmp($a['code'], $b['code']); });
    return $out;
}
// জার্নালের অ্যাকাউন্ট হেড: ফান্ড অ্যাকাউন্ট + চার্ট অফ অ্যাকাউন্টসের সব পোস্টিং-যোগ্য হেড
function journal_heads($conn, $accounts) {
    $out = []; $fundNames = [];
    foreach ($accounts as $a) {
        $out['FUND:' . $a['id']] = ($a['coa_label'] ?? $a['account_name']) . ' - FUND';
        $fundNames[strtolower(trim($a['account_name']))] = 1;
    }
    foreach (coa_leaf_heads($conn, null, true) as $x) {
        if (isset($fundNames[strtolower($x['name'])])) continue; // ফান্ড অ্যাকাউন্টের সাথে নাম মিললে ফান্ডটাই থাকবে
        $lab = $x['name'] . ($x['code'] !== '' ? ' - ' . $x['code'] : '') . ($x['type'] !== '' ? ' - ' . strtoupper($x['type']) : '');
        if (in_array($lab, $out, true)) $lab .= ' #' . $x['id'];
        $out['COA:' . $x['id']] = $lab;
    }
    return $out;
}
// পার্টি: কাস্টমার / সাপ্লায়ার / কর্মচারী
function journal_parties($conn) {
    $out = [];
    $r = mysqli_query($conn, "SELECT id, customer_name, phone FROM erp_customers ORDER BY customer_name");
    while ($r && $x = mysqli_fetch_assoc($r)) $out['CUSTOMER:' . $x['id']] = 'Customer: ' . $x['customer_name'] . ($x['phone'] ? ' [ ' . $x['phone'] . ' ]' : '');
    $r = mysqli_query($conn, "SELECT id, supplier_name, mobile_no FROM erp_suppliers ORDER BY supplier_name");
    while ($r && $x = mysqli_fetch_assoc($r)) $out['SUPPLIER:' . $x['id']] = 'Supplier: ' . $x['supplier_name'] . ($x['mobile_no'] ? ' [ ' . $x['mobile_no'] . ' ]' : '');
    $r = mysqli_query($conn, "SELECT id, employee_code, employee_name, mobile_no FROM erp_employees WHERE status='Active' ORDER BY employee_name");
    while ($r && $x = mysqli_fetch_assoc($r)) $out['EMPLOYEE:' . $x['id']] = 'Employee: ' . $x['employee_name'] . ' (' . $x['employee_code'] . ')' . ($x['mobile_no'] ? ' [ ' . $x['mobile_no'] . ' ]' : '');
    return $out;
}
if ($type === 'journal') { // জার্নালের সারিগুলোর টেবিল (একবারই তৈরি হয়)
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_voucher_lines (
        id INT AUTO_INCREMENT PRIMARY KEY, voucher_id INT NOT NULL, head_kind VARCHAR(10) NOT NULL, head_id INT NOT NULL, head_label VARCHAR(255) NULL,
        party_type VARCHAR(20) NULL, party_id INT NULL, dr_amount DECIMAL(14,2) NOT NULL DEFAULT 0, cr_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL, INDEX (voucher_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ---------- AJAX: পার্টির ব্যালেন্স ----------
if (($_GET['ajax'] ?? '') === 'balance') {
    header('Content-Type: application/json');
    $pt = $_GET['pt'] ?? ''; $id = (int)($_GET['id'] ?? 0);
    $b = $pt === 'CUSTOMER' ? customer_prev_balance($conn, $id) : ($pt === 'SUPPLIER' ? supplier_balance($conn, $id) : 0);
    echo json_encode(['balance' => round($b, 2)]); exit;
}

// ---------- ফান্ড অ্যাকাউন্ট (ব্যালেন্সসহ) ----------
$accounts = []; $acc_ids = [];
$ar = mysqli_query($conn, "SELECT a.id, a.account_name, a.account_type,
        a.opening_balance + COALESCE(SUM(CASE WHEN l.txn_type='IN' THEN l.amount END),0) - COALESCE(SUM(CASE WHEN l.txn_type='OUT' THEN l.amount END),0) AS bal
        FROM erp_fund_accounts a LEFT JOIN erp_fund_ledger l ON l.account_id = a.id
        WHERE a.status='Active' GROUP BY a.id ORDER BY a.account_type, a.id");
while ($a = mysqli_fetch_assoc($ar)) { $accounts[] = $a; $acc_ids[] = (int)$a['id']; }
// ফান্ড অ্যাকাউন্টকে চার্ট অফ অ্যাকাউন্টসের হেডের সাথে মেলানো: ফান্ডের ধরন (CASH/BANK/MFS) = চার্টের Fund কলাম (Cash/Bank/MFS)
$fundMap = [];
foreach (coa_rows($conn)['rows'] as $cr) {
    if ($cr['active'] && $cr['fund'] !== '' && $cr['fund'] !== '0' && !isset($fundMap[strtolower($cr['fund'])])) $fundMap[strtolower($cr['fund'])] = $cr;
}
foreach ($accounts as &$fa) {
    $cr = $fundMap[strtolower($fa['account_type'])] ?? null;
    if ($cr) $fa['coa_label'] = ($cr['code'] !== '' ? $cr['code'] . ' - ' : '') . $cr['name'] . (strcasecmp(trim($fa['account_name']), $cr['name']) === 0 ? '' : ' » ' . $fa['account_name']);
    else     $fa['coa_label'] = $fa['account_name'] . ' (' . $fa['account_type'] . ')';
}
unset($fa);

// ---------- POST: সেভ / ডিলিট ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail = function($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');
    $uid = (int)$_SESSION['user_id'];
    $self = "transaction.php?type=$type";

    // ---- নতুন খাত (+ বাটন): চার্ট অফ অ্যাকাউন্টসে রুটের নিচে নতুন হেড তৈরি ----
    if (($_POST['do'] ?? '') === 'add_head') {
        header('Content-Type: application/json');
        $nm = trim($_POST['name'] ?? ''); $want = ($type === 'income') ? 'Income' : 'Expense';
        if ($nm === '' || !isset($c['head'])) { echo json_encode(['ok' => false]); exit; }
        $d = coa_rows($conn); $m = $d['m']; $rows = $d['rows'];
        foreach ($rows as $r) if (strcasecmp($r['name'], $nm) === 0) { echo json_encode(['ok' => true, 'name' => $r['name'], 'existing' => true]); exit; }
        try {
            if (!$m || !$m['name'] || !$m['parent']) throw new Exception('schema');
            $root = null;
            foreach ($rows as $r) if (strcasecmp($r['type'], $want) === 0 && ($r['parent'] === '' || $r['parent'] === '0')) { $root = $r; break; }
            if (!$root) throw new Exception('no root');
            $byCode = coa_parent_by_code($rows); $pkey = $byCode ? $root['code'] : $root['id'];
            $esc = function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; };
            $ci = ['`' . $m['name'] . '`']; $vi = [$esc($nm)];
            if ($m['code']) { // রুটের নিচের সর্বোচ্চ কোড + ১
                $mx = (int)$root['code'];
                foreach ($rows as $r) if ($r['parent'] === (string)$pkey && is_numeric($r['code'])) $mx = max($mx, (int)$r['code']);
                $ci[] = '`' . $m['code'] . '`'; $vi[] = $esc($mx + 1);
            }
            if ($m['type'])   { $ci[] = '`' . $m['type'] . '`';   $vi[] = $esc($root['type']); }
            $ci[] = '`' . $m['parent'] . '`'; $vi[] = $esc($pkey);
            if ($m['status']) { $ci[] = '`' . $m['status'] . '`'; $vi[] = "'Active'"; }
            mysqli_query($conn, "INSERT INTO erp_chart_of_accounts (" . implode(',', $ci) . ") VALUES (" . implode(',', $vi) . ")");
            echo json_encode(['ok' => true, 'name' => $nm]);
        } catch (Throwable $e) { echo json_encode(['ok' => false]); }
        exit;
    }

    // ---- ডিলিট ----
    if (($_POST['do'] ?? '') === 'delete') {
        $id = (int)$_POST['id'];
        $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT voucher_no FROM erp_vouchers WHERE id=$id AND voucher_type='$vt'"));
        if ($o) {
            $no = mysqli_real_escape_string($conn, $o['voucher_no']);
            mysqli_begin_transaction($conn);
            try {
                mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='$vt' AND ref_no='$no'");
                if ($vt === 'JOURNAL') mysqli_query($conn, "DELETE FROM erp_voucher_lines WHERE voucher_id=$id");
                mysqli_query($conn, "DELETE FROM erp_vouchers WHERE id=$id");
                mysqli_commit($conn);
            } catch (Exception $e) { mysqli_rollback($conn); $fail('ডিলিট ব্যর্থ হয়েছে।'); }
        }
        echo "<script>alert('ভাউচার মুছে ফেলা হয়েছে।'); window.location.href='$self';</script>"; exit;
    }

    // ---- জার্নাল সেভ (বহু-সারি, Dr = Cr) ----
    if ($type === 'journal' && ($_POST['do'] ?? '') === 'save') {
        $edit = (int)($_POST['edit_id'] ?? 0);
        $date = mysqli_real_escape_string($conn, $_POST['voucher_date'] ?? '');
        $ref  = mysqli_real_escape_string($conn, trim($_POST['ref_no'] ?? ''));
        $narr = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
        if ($date === '') $fail('তারিখ দিন!');
        $H = journal_heads($conn, $accounts); $PM = journal_parties($conn);
        $lines = []; $totDr = 0; $totCr = 0; $seenK = [];
        foreach (($_POST['j_head'] ?? []) as $i => $hk) {
            $n  = $i + 1;
            $dr = round(max(0, floatval($_POST['j_dr'][$i] ?? 0)), 2);
            $cr = round(max(0, floatval($_POST['j_cr'][$i] ?? 0)), 2);
            $hk = trim($hk); $pk = trim($_POST['j_party'][$i] ?? '');
            if ($hk === '' && $pk === '' && $dr == 0 && $cr == 0) continue; // ফাঁকা সারি
            if (!isset($H[$hk])) $fail("সারি $n: তালিকা থেকে একটি অ্যাকাউন্ট হেড নির্বাচন করুন!");
            if ($pk !== '' && !isset($PM[$pk])) $fail("সারি $n: পার্টি সঠিক নয়, তালিকা থেকে নির্বাচন করুন!");
            if (isset($seenK[$hk . '|' . $pk])) $fail("সারি $n: একই অ্যাকাউন্ট হেড ও পার্টি আগের সারিতে আছে — একটি সারিতে মোট পরিমাণ দিন!");
            $seenK[$hk . '|' . $pk] = 1;
            if (($dr > 0) === ($cr > 0)) $fail("সারি $n: Dr অথবা Cr — যেকোনো একটিতে পরিমাণ দিন!");
            list($hkind, $hid) = explode(':', $hk);
            $ptype = ''; $pidn = 0;
            if ($pk !== '') { list($ptype, $pidn) = explode(':', $pk); }
            $lines[] = ['kind'=>$hkind, 'hid'=>(int)$hid, 'label'=>mysqli_real_escape_string($conn, $H[$hk]), 'ptype'=>$ptype, 'pid'=>(int)$pidn,
                        'dr'=>$dr, 'cr'=>$cr, 'note'=>mysqli_real_escape_string($conn, trim($_POST['j_note'][$i] ?? ''))];
            $totDr += $dr; $totCr += $cr;
        }
        if (count($lines) < 2) $fail('কমপক্ষে দুটি সারি দিন!');
        if (abs($totDr - $totCr) > 0.009) $fail('Dr ও Cr এর যোগফল সমান নয়! (Dr: ' . number_format($totDr, 2) . ', Cr: ' . number_format($totCr, 2) . ')');

        $refSql = $ref !== '' ? "'$ref'" : 'NULL';
        mysqli_begin_transaction($conn);
        try {
            if ($edit > 0) {
                $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT voucher_no FROM erp_vouchers WHERE id=$edit AND voucher_type='JOURNAL'"));
                if (!$o) throw new Exception('not found');
                $no = $o['voucher_no']; $noe = mysqli_real_escape_string($conn, $no);
                mysqli_query($conn, "UPDATE erp_vouchers SET voucher_date='$date', account_id=NULL, to_account_id=NULL, party_type=NULL, party_id=NULL, entry_side=NULL, cr_party_type=NULL, cr_party_id=NULL, head_name=$refSql, amount=$totDr, discount=0, remarks='$narr' WHERE id=$edit");
                mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='JOURNAL' AND ref_no='$noe'");
                mysqli_query($conn, "DELETE FROM erp_voucher_lines WHERE voucher_id=$edit");
                $vid = $edit;
            } else {
                $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(id) AS m FROM erp_vouchers"));
                $no = 'JV-' . date('y') . str_pad(((int)$m['m']) + 1, 4, '0', STR_PAD_LEFT);
                $noe = mysqli_real_escape_string($conn, $no);
                mysqli_query($conn, "INSERT INTO erp_vouchers (voucher_no, voucher_type, voucher_date, head_name, amount, discount, remarks, created_by)
                                     VALUES ('$noe', 'JOURNAL', '$date', $refSql, $totDr, 0, '$narr', $uid)");
                $vid = mysqli_insert_id($conn);
            }
            foreach ($lines as $L) {
                $pt_sql = $L['ptype'] !== '' ? "'{$L['ptype']}'" : 'NULL'; $pi_sql = $L['pid'] > 0 ? $L['pid'] : 'NULL';
                mysqli_query($conn, "INSERT INTO erp_voucher_lines (voucher_id, head_kind, head_id, head_label, party_type, party_id, dr_amount, cr_amount, note)
                                     VALUES ($vid, '{$L['kind']}', {$L['hid']}, '{$L['label']}', $pt_sql, $pi_sql, {$L['dr']}, {$L['cr']}, '{$L['note']}')");
                if ($L['kind'] === 'FUND') { // ফান্ড অ্যাকাউন্টে Dr = টাকা বাড়ে (IN), Cr = টাকা কমে (OUT)
                    $tt = $L['dr'] > 0 ? 'IN' : 'OUT'; $amt = $L['dr'] > 0 ? $L['dr'] : $L['cr'];
                    mysqli_query($conn, "INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, remarks, created_by)
                                         VALUES ('$date', {$L['hid']}, '$tt', $amt, 'JOURNAL', '$noe', '$narr', $uid)");
                }
            }
            mysqli_commit($conn);
        } catch (Exception $e) { mysqli_rollback($conn); $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।'); }
        echo "<script>alert('জার্নাল $no সংরক্ষিত হয়েছে।'); window.location.href='$self';</script>"; exit;
    }

    // ---- সেভ ----
    if (($_POST['do'] ?? '') === 'save') {
        $edit    = (int)($_POST['edit_id'] ?? 0);
        $date    = mysqli_real_escape_string($conn, $_POST['voucher_date']);
        $amount  = round(floatval($_POST['amount'] ?? 0), 2);
        $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
        $acc = (int)($_POST['account_id'] ?? 0);
        $to  = (int)($_POST['to_account_id'] ?? 0);
        $pt = ''; $pid = 0; $side = ''; $head = '';

        if (isset($c['party'])) { $pt = $c['party']; $pid = (int)($_POST['party_id'] ?? 0); }
        $discount = 0; $cpt = ''; $cpid = 0; $cacc = 0;
        if (in_array($type, ['payment', 'collection'])) { // Supplier / Customer / Employee / Others
            $q  = $_POST['party_type'] ?? $c['party'];
            $pt = in_array($q, ['SUPPLIER', 'CUSTOMER', 'EMPLOYEE', 'OTHER'], true) ? $q : $c['party'];
            $discount = round(max(0, floatval($_POST['discount'] ?? 0)), 2);
            if ($pt === 'OTHER') { $pid = 0; $head = mysqli_real_escape_string($conn, trim($_POST['other_name'] ?? '')); }
        }
        if (isset($c['head']))  { $head = mysqli_real_escape_string($conn, trim($_POST['head_name'] ?? '')); }

        if ($amount <= 0) $fail('পরিমাণ ০-এর বেশি হতে হবে!');
        if ($date === '') $fail('তারিখ দিন!');
        if ($pt === 'OTHER' && $head === '') $fail('নাম লিখুন!');
        if ($pt !== '' && $pt !== 'OTHER' && $pid <= 0) $fail('তালিকা থেকে একজন পার্টি নির্বাচন করুন!');
        if ($type === 'journal') {
            if ($pt === '' && !in_array($acc, $acc_ids))   $fail('Debit সাইডে একটি ফান্ড অ্যাকাউন্ট নির্বাচন করুন!');
            if ($cpt === '' && !in_array($cacc, $acc_ids)) $fail('Credit সাইডে একটি ফান্ড অ্যাকাউন্ট নির্বাচন করুন!');
            if ($cpt !== '' && $cpid <= 0) $fail('Credit সাইডে একটি পার্টি নির্বাচন করুন!');
            if ($pt === $cpt && ($pt === '' ? $acc === $cacc : $pid === $cpid)) $fail('Debit ও Credit একই হতে পারে না!');
        } elseif (!in_array($acc, $acc_ids)) $fail('একটি ফান্ড অ্যাকাউন্ট নির্বাচন করুন!');
        if ($type === 'transfer') {
            if (!in_array($to, $acc_ids)) $fail('গন্তব্য অ্যাকাউন্ট নির্বাচন করুন!');
            if ($acc === $to) $fail('একই অ্যাকাউন্টে ট্রান্সফার করা যায় না!');
        } else { $to = ($type === 'journal') ? $cacc : 0; }
        if (isset($c['head']) && $head === '') $fail('খাতের নাম দিন!');

        $N = function($v) { return $v > 0 ? (int)$v : 'NULL'; };
        $S = function($v) { return $v !== '' ? "'$v'" : 'NULL'; };

        mysqli_begin_transaction($conn);
        try {
            if ($edit > 0) {
                $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT voucher_no FROM erp_vouchers WHERE id=$edit AND voucher_type='$vt'"));
                if (!$o) throw new Exception('not found');
                $no = $o['voucher_no']; $noe = mysqli_real_escape_string($conn, $no);
                mysqli_query($conn, "UPDATE erp_vouchers SET voucher_date='$date', account_id={$N($acc)}, to_account_id={$N($to)}, party_type={$S($pt)}, party_id={$N($pid)}, entry_side={$S($side)}, head_name={$S($head)}, cr_party_type={$S($cpt)}, cr_party_id={$N($cpid)}, amount=$amount, discount=$discount, remarks='$remarks' WHERE id=$edit");
                mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='$vt' AND ref_no='$noe'");
            } else {
                $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(id) AS m FROM erp_vouchers"));
                $no = $c['p'] . '-' . date('y') . str_pad(((int)$m['m']) + 1, 4, '0', STR_PAD_LEFT);
                $noe = mysqli_real_escape_string($conn, $no);
                mysqli_query($conn, "INSERT INTO erp_vouchers (voucher_no, voucher_type, voucher_date, account_id, to_account_id, party_type, party_id, entry_side, head_name, cr_party_type, cr_party_id, amount, discount, remarks, created_by)
                                     VALUES ('$noe', '$vt', '$date', {$N($acc)}, {$N($to)}, {$S($pt)}, {$N($pid)}, {$S($side)}, {$S($head)}, {$S($cpt)}, {$N($cpid)}, $amount, $discount, '$remarks', $uid)");
            }
            $led = function($accid, $t) use ($conn, $date, $amount, $vt, $noe, $remarks, $uid) {
                mysqli_query($conn, "INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, remarks, created_by)
                                     VALUES ('$date', $accid, '$t', $amount, '$vt', '$noe', '$remarks', $uid)");
            };
            if ($type === 'transfer')      { $led($acc, 'OUT'); $led($to, 'IN'); }
            elseif ($type === 'journal')   { if ($acc > 0) $led($acc, 'IN'); if ($cacc > 0) $led($cacc, 'OUT'); }
            else                           { $led($acc, $c['fund']); }
            mysqli_commit($conn);
        } catch (Exception $e) { mysqli_rollback($conn); $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।'); }

        echo "<script>alert('ভাউচার $no সংরক্ষিত হয়েছে।'); window.location.href='$self';</script>"; exit;
    }
}

// ---------- তালিকা কুয়েরির SQL ----------
$sel = "SELECT v.*, a.account_name, ta.account_name AS to_name,
        CASE v.party_type WHEN 'CUSTOMER' THEN cu.customer_name WHEN 'SUPPLIER' THEN su.supplier_name WHEN 'EMPLOYEE' THEN em.employee_name END AS party_name,
        CASE v.cr_party_type WHEN 'CUSTOMER' THEN ccu.customer_name WHEN 'SUPPLIER' THEN csu.supplier_name END AS cr_party_name
        FROM erp_vouchers v
        LEFT JOIN erp_fund_accounts a  ON a.id  = v.account_id
        LEFT JOIN erp_fund_accounts ta ON ta.id = v.to_account_id
        LEFT JOIN erp_customers cu ON v.party_type='CUSTOMER' AND cu.id = v.party_id
        LEFT JOIN erp_suppliers su ON v.party_type='SUPPLIER' AND su.id = v.party_id
        LEFT JOIN erp_employees em ON v.party_type='EMPLOYEE' AND em.id = v.party_id
        LEFT JOIN erp_customers ccu ON v.cr_party_type='CUSTOMER' AND ccu.id = v.cr_party_id
        LEFT JOIN erp_suppliers csu ON v.cr_party_type='SUPPLIER' AND csu.id = v.cr_party_id";

// ---------- প্রিন্ট ----------
if (($_GET['action'] ?? '') === 'print') {
    $pid = (int)($_GET['id'] ?? 0);
    $v = mysqli_fetch_assoc(mysqli_query($conn, "$sel WHERE v.id=$pid AND v.voucher_type='$vt'"));
    if (!$v) die('ভাউচার পাওয়া যায়নি');
    // ব্রাঞ্চ-ওয়াইজ হেডিং: ভাউচার যে ইউজার বানিয়েছে তার ব্রাঞ্চ; না পেলে চলতি ইউজারের ব্রাঞ্চ; তাও না থাকলে কোম্পানির নাম
    $co = []; $hn = 'Uttara Builders'; $hp = '';
    try {
        include_once('../Settings/settings_lib.php'); $co = company_info($conn);
        $hn = trim($co['company_name'] ?? '') ?: $hn; $hp = trim($co['mobile'] ?? '') ?: trim($co['phone'] ?? '');
        $bn = '';
        $bs = $conn->prepare("SELECT br.branch_name FROM erp_users u JOIN erp_branches br ON br.id = u.branch_id WHERE u.id = ?");
        $cb = (int)($v['created_by'] ?? 0); $bs->bind_param('i', $cb); $bs->execute();
        $bn = (string)($bs->get_result()->fetch_assoc()['branch_name'] ?? ''); $bs->close();
        if ($bn === '' && function_exists('current_branch')) { $cbr = current_branch(); if (is_array($cbr) && ($cbr['name'] ?? '') !== '__no_branch__') $bn = (string)($cbr['name'] ?? ''); }
        $bi = $bn !== '' ? branch_info($conn, $bn) : null;
        if ($bi) { $hn = trim($bi['branch_name'] ?? '') ?: $hn; $hp = trim($bi['phone'] ?? '') ?: $hp; }
    } catch (Throwable $e) { }
    $particular = $v['party_name'] ?: ($v['head_name'] ?: ($type === 'transfer' ? $v['account_name'] . ' → ' . $v['to_name'] : '-'));
    $jlines = [];
    if ($type === 'journal') {
        $jq = mysqli_query($conn, "SELECT * FROM erp_voucher_lines WHERE voucher_id=" . (int)$v['id'] . " ORDER BY id");
        while ($jq && $x = mysqli_fetch_assoc($jq)) $jlines[] = $x;
        if ($jlines) { $particular = 'Journal Entry' . ($v['head_name'] ? ' (Ref: ' . $v['head_name'] . ')' : ''); $v['entry_side'] = ''; $v['account_name'] = ''; }
    }
    if ($type === 'journal' && !$jlines && ($v['cr_party_type'] || $v['to_account_id'])) {
        $particular = 'Dr: ' . ($v['party_name'] ?: $v['account_name']) . '  |  Cr: ' . ($v['cr_party_name'] ?: $v['to_name']);
        $v['entry_side'] = ''; $v['account_name'] = '';
    }
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title><?php echo h($v['voucher_no']); ?></title>
<style>
 body{font-family:'Segoe UI',Arial,sans-serif;margin:30px;font-size:14px;color:#222}
 .hd{text-align:center;border-bottom:2px solid #2e7d32;padding-bottom:8px;margin-bottom:18px}
 .hd h2{margin:0;color:#2e7d32;text-transform:uppercase} .tt{display:inline-block;background:#2e7d32;color:#fff;padding:3px 16px;border-radius:3px;margin-top:6px;font-weight:bold}
 table{width:100%;border-collapse:collapse;margin-top:10px} td{padding:9px;border:1px solid #bbb} td.l{width:30%;background:#f4f6f8;font-weight:bold}
 .sig{display:flex;justify-content:space-between;margin-top:70px}.sig div{border-top:1px solid #333;width:170px;text-align:center;padding-top:5px;font-size:12px}
 @media print{.np{display:none}}
</style></head><body onload="window.print()">
<div class="hd"><h2><?php echo h($hn); ?></h2><?php if ($hp !== '') echo '<div style="font-size:12px;color:#555;">Phone: ' . h($hp) . '</div>'; ?><div class="tt"><?php echo h($c['t']); ?></div></div>
<table>
 <tr><td class="l">Voucher No</td><td><b><?php echo h($v['voucher_no']); ?></b></td></tr>
 <tr><td class="l">Date</td><td><?php echo date('d-M-Y', strtotime($v['voucher_date'])); ?></td></tr>
 <tr><td class="l">Particulars</td><td><?php echo h($particular); ?><?php echo $v['entry_side'] ? ' (' . h($v['entry_side']) . ')' : ''; ?></td></tr>
 <tr><td class="l">Account</td><td><?php echo h($v['account_name'] ?: '-'); ?></td></tr>
 <tr><td class="l">Amount (BDT)</td><td><b><?php echo number_format($v['amount'], 2); ?></b></td></tr>
 <?php if (!empty($v['discount']) && $v['discount'] > 0) { ?><tr><td class="l">Discount (BDT)</td><td><?php echo number_format($v['discount'], 2); ?></td></tr><?php } ?>
 <tr><td class="l">Remarks</td><td><?php echo h($v['remarks']); ?></td></tr>
</table>
<?php if ($jlines) { $PMp = journal_parties($conn); $td = 0; $tc = 0; ?>
<table style="margin-top:16px;font-size:13px;">
 <tr style="background:#f4f6f8;font-weight:bold;"><td>SL</td><td>Account Head</td><td>Party</td><td style="text-align:right;">Dr</td><td style="text-align:right;">Cr</td><td>Note</td></tr>
 <?php foreach ($jlines as $k => $L) { $td += $L['dr_amount']; $tc += $L['cr_amount']; $pk = $L['party_type'] ? $L['party_type'] . ':' . $L['party_id'] : ''; ?>
 <tr><td><?php echo $k + 1; ?></td><td><?php echo h($L['head_label']); ?></td><td><?php echo h($PMp[$pk] ?? ''); ?></td>
     <td style="text-align:right;"><?php echo $L['dr_amount'] > 0 ? number_format($L['dr_amount'], 2) : ''; ?></td>
     <td style="text-align:right;"><?php echo $L['cr_amount'] > 0 ? number_format($L['cr_amount'], 2) : ''; ?></td><td><?php echo h($L['note']); ?></td></tr>
 <?php } ?>
 <tr style="font-weight:bold;background:#f4f6f8;"><td colspan="3" style="text-align:right;">Total</td><td style="text-align:right;"><?php echo number_format($td, 2); ?></td><td style="text-align:right;"><?php echo number_format($tc, 2); ?></td><td></td></tr>
</table>
<?php } ?>
<div class="sig"><div>Received / Paid By</div><div>Prepared By</div><div>Authorized Signature</div></div>
</body></html>
<?php exit; }

// ---------- ফর্ম/তালিকার ডাটা ----------
$action  = $_GET['action'] ?? 'list';
$edit_id = ($action === 'edit') ? (int)($_GET['id'] ?? 0) : 0;
$show_form = ($action === 'new' || $edit_id > 0);
$ed = null; $party_label = ''; $target_now = 'FUND';

$need_party_list = isset($c['party']) || $type === 'journal';
$customers = []; $suppliers = []; $employees = []; $others = [];
$multi = in_array($type, ['payment', 'collection']);
if ($show_form && $need_party_list) {
    if ($multi || ($c['party'] ?? '') !== 'SUPPLIER') {
        $r = mysqli_query($conn, "SELECT id, customer_name, phone FROM erp_customers ORDER BY customer_name");
        while ($x = mysqli_fetch_assoc($r)) $customers[] = ['id'=>$x['id'], 'label'=>$x['customer_name'] . ($x['phone'] ? ' [ ' . $x['phone'] . ' ]' : '')];
    }
    if ($multi || ($c['party'] ?? '') !== 'CUSTOMER') {
        $r = mysqli_query($conn, "SELECT id, supplier_name, mobile_no FROM erp_suppliers ORDER BY supplier_name");
        while ($x = mysqli_fetch_assoc($r)) $suppliers[] = ['id'=>$x['id'], 'label'=>$x['supplier_name'] . ($x['mobile_no'] ? ' [ ' . $x['mobile_no'] . ' ]' : '')];
    }
    if ($multi) {
        $r = mysqli_query($conn, "SELECT DISTINCT head_name FROM erp_vouchers WHERE party_type='OTHER' AND head_name IS NOT NULL AND head_name<>'' ORDER BY head_name");
        while ($r && $x = mysqli_fetch_assoc($r)) $others[] = ['id'=>'', 'label'=>$x['head_name']];
        $r = mysqli_query($conn, "SELECT id, employee_code, employee_name, mobile_no FROM erp_employees WHERE status='Active' ORDER BY employee_name");
        while ($r && $x = mysqli_fetch_assoc($r)) $employees[] = ['id'=>$x['id'], 'label'=>$x['employee_name'] . ' (' . $x['employee_code'] . ')' . ($x['mobile_no'] ? ' [ ' . $x['mobile_no'] . ' ]' : '')];
    }
}
if ($edit_id > 0) {
    $ed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_vouchers WHERE id=$edit_id AND voucher_type='$vt'"));
    if (!$ed) { echo "<script>window.location.href='transaction.php?type=$type';</script>"; exit; }
    if ($ed['party_type']) {
        $target_now = $ed['party_type'];
        $lst = ['CUSTOMER'=>$customers, 'SUPPLIER'=>$suppliers, 'EMPLOYEE'=>$employees][$ed['party_type']] ?? [];
        foreach ($lst as $p) if ($p['id'] == $ed['party_id']) $party_label = $p['label'];
        if ($ed['party_type'] === 'OTHER') $party_label = $ed['head_name'];
    }
}

$rows = []; $sum = 0; $jsum = [];
if (!$show_form) {
    $lr = mysqli_query($conn, "$sel WHERE v.voucher_type='$vt' ORDER BY v.id DESC");
    while ($r = mysqli_fetch_assoc($lr)) { $rows[] = $r; $sum += $r['amount']; }
    if ($type === 'journal' && $rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        $jl = mysqli_query($conn, "SELECT voucher_id, head_label, dr_amount FROM erp_voucher_lines WHERE voucher_id IN ($ids) ORDER BY id");
        while ($jl && $x = mysqli_fetch_assoc($jl)) { $jsum[$x['voucher_id']][$x['dr_amount'] > 0 ? 'dr' : 'cr'][] = $x['head_label']; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo h($c['t']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f0f0f0; }
    .btn-print { border: none; background: #2b579a; color: #fff; }
    .btn-save { border: none; background: #28a745; color: #fff; }
    .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 20px; }
    .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #ced4da; text-transform: uppercase; }
    .panel-body { padding: 18px; }
    .grid3 { display: grid; grid-template-columns: 1fr; gap: 14px; max-width: 620px; margin: 0 auto; }
    .form-footer { display: flex; justify-content: center; gap: 10px; padding: 14px; border-top: 1px solid #e3e6ea; background: #f7f8fa; border-radius: 0 0 4px 4px; }
    .jside { border: 1px solid #cbd5e1; border-left-width: 4px; border-radius: 4px; padding: 12px; display: flex; flex-direction: column; gap: 10px; background: #fcfcfd; }
    .jtitle { font-weight: bold; font-size: 13px; }
    .jwrap { width: 100%; }
    .jhead { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 16px; margin-bottom: 14px; }
    .jtools { display: flex; gap: 8px; margin-bottom: 8px; }
    #jTable th { text-align: center; }
    #jTable td { padding: 3px; }
    .jdel { cursor: pointer; color: #dc3545; font-size: 15px; padding: 4px 8px; }
    .fc.bad { border-color: #dc3545; background: #fff0f0; }
    #party_search { font-size: 15px; font-weight: 600; height: 38px; }
    .btn-plus { height: 32px; width: 36px; background: #28a745; color: #fff; border: none; border-radius: 4px; font-size: 20px; font-weight: bold; cursor: pointer; flex-shrink: 0; line-height: 1; }
    .btn-plus:hover { background: #218838; }
    .hmodal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.4); }
    .hmodal-box { background: #fff; width: 380px; max-width: 92%; margin: 110px auto 0; padding: 18px; border: 1px solid #cddc39; border-radius: 6px; }
    .grid3 > .fg { grid-column: 1 / -1 !important; }
    .fg { display: flex; flex-direction: column; gap: 4px; }
    .fg label { font-size: 12px; font-weight: bold; color: #444; }
    .fc { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .bal { font-size: 12px; font-weight: bold; margin-top: 2px; min-height: 16px; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 8px; border: 1px solid #dee2e6; background: #fff; }
    .grid-table tbody tr:hover td { background: #f1f5f9; }
    .grid-table tr.selected-row td { background: #bddeb6 !important; }
    .r { text-align: right; } .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .filter { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; }
</style>
</head>
<body>

<?php if (!$show_form) { ?>
<div class="control-bar">
    <button class="btn-action" onclick="location.href='transaction.php?type=<?php echo $type; ?>&action=new'"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New</button>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#0498db;"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#dc3545;"></i> Delete</button>
    <button class="btn-action btn-print" onclick="printSel()"><i class="fa-solid fa-print"></i> Print</button>
</div>
<div class="form-panel">
    <div class="panel-header"><?php echo h($c['t']); ?> List</div>
    <div class="panel-body">
        <div class="filter">
            <div class="fg"><label>Voucher No</label><input class="fc" id="f_no" oninput="runFilter()" placeholder="Search.."></div>
            <div class="fg"><label>Party / Head</label><input class="fc" id="f_party" oninput="runFilter()" placeholder="Search.."></div>
            <div class="fg"><label>Date From</label><input type="date" class="fc" id="f_from" onchange="runFilter()"></div>
            <div class="fg"><label>Date To</label><input type="date" class="fc" id="f_to" onchange="runFilter()"></div>
        </div>
        <table class="grid-table" id="vTable">
            <thead><tr><th style="width:30px;"></th><th style="width:40px;">SL</th><th>Voucher No</th><th>Date</th><th>Particulars</th><th>Account</th><th class="r">Amount</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php if ($rows) { $sl = 1; foreach ($rows as $r) {
                $part = $r['party_name'] ?: ($r['head_name'] ?: ($type === 'transfer' ? 'Transfer' : '-'));
                if ($r['entry_side']) $part .= ' (' . $r['entry_side'] . ')';
                if (isset($c['party']) && $r['party_type'] && $r['party_type'] !== $c['party']) $part .= ' [' . ucfirst(strtolower($r['party_type'])) . ']';
                $acct = $r['account_name'] ?: '-';
                if ($r['to_name']) $acct .= ' → ' . $r['to_name'];
                if ($type === 'journal' && ($r['cr_party_type'] || $r['to_account_id'])) {
                    $part = 'Dr: ' . ($r['party_name'] ?: $r['account_name']) . '  |  Cr: ' . ($r['cr_party_name'] ?: $r['to_name']); $acct = '-';
                }
                if ($type === 'journal' && isset($jsum[$r['id']])) {
                    $part = 'Dr: ' . implode(', ', $jsum[$r['id']]['dr'] ?? []) . '  |  Cr: ' . implode(', ', $jsum[$r['id']]['cr'] ?? []);
                    $acct = $r['head_name'] ? 'Ref: ' . $r['head_name'] : '-';
                }
            ?>
                <tr class="v-row" style="cursor:pointer;" onclick="selRow(this, <?php echo (int)$r['id']; ?>)" data-no="<?php echo h($r['voucher_no']); ?>" data-party="<?php echo h($part); ?>" data-date="<?php echo h($r['voucher_date']); ?>">
                    <td style="text-align:center;"><input type="checkbox" class="chk" onclick="event.stopPropagation(); selRow(this.closest('tr'), <?php echo (int)$r['id']; ?>)"></td>
                    <td class="sl"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;color:#2e7d32;"><?php echo h($r['voucher_no']); ?></td>
                    <td><?php echo date('d-M-Y', strtotime($r['voucher_date'])); ?></td>
                    <td style="color:#4050b3;font-weight:500;"><?php echo h($part); ?></td>
                    <td><?php echo h($acct); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo number_format($r['amount'], 2); ?></td>
                    <td><?php echo h($r['remarks']); ?></td>
                </tr>
            <?php } ?>
                <tr class="total-row"><td colspan="6" style="text-align:center;color:#2e7d32;">Grand Total</td><td class="r"><?php echo number_format($sum, 2); ?></td><td></td></tr>
            <?php } else { echo "<tr><td colspan='8' style='text-align:center;padding:20px;color:#999;'>কোনো ভাউচার পাওয়া যায়নি।</td></tr>"; } ?>
            </tbody>
        </table>
    </div>
</div>
<form id="delForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="do" value="delete"><input type="hidden" name="id" id="delId">
</form>
<script>
var selId = null;
function selRow(row, id) {
    document.querySelectorAll('#vTable tbody tr').forEach(function(t) { t.classList.remove('selected-row'); });
    document.querySelectorAll('.chk').forEach(function(c) { c.checked = false; });
    row.classList.add('selected-row'); var c = row.querySelector('.chk'); if (c) c.checked = true; selId = id;
}
function editSel() { if (!selId) { alert('একটি ভাউচার সিলেক্ট করুন!'); return; } location.href = 'transaction.php?type=<?php echo $type; ?>&action=edit&id=' + selId; }
function delSel()  { if (!selId) { alert('একটি ভাউচার সিলেক্ট করুন!'); return; } if (confirm('ভাউচারটি মুছে ফেলতে চান? ফান্ড ব্যালেন্সও সংশোধিত হবে।')) { document.getElementById('delId').value = selId; document.getElementById('delForm').submit(); } }
function printSel(){ if (!selId) { alert('একটি ভাউচার সিলেক্ট করুন!'); return; } window.open('transaction.php?type=<?php echo $type; ?>&action=print&id=' + selId, '_blank'); }
function runFilter() {
    var no = f_no.value.toUpperCase().trim(), pa = f_party.value.toUpperCase().trim(), from = f_from.value, to = f_to.value, sl = 1;
    document.querySelectorAll('.v-row').forEach(function(r) {
        var ok = true, d = r.getAttribute('data-date');
        if (no && r.getAttribute('data-no').toUpperCase().indexOf(no) === -1) ok = false;
        if (pa && r.getAttribute('data-party').toUpperCase().indexOf(pa) === -1) ok = false;
        if (from && d < from) ok = false; if (to && d > to) ok = false;
        r.style.display = ok ? '' : 'none'; if (ok) r.querySelector('.sl').textContent = sl++;
    });
}
</script>

<?php } else {
    $is_j = ($type === 'journal');
    $acc_label = ($type === 'transfer') ? 'From Account (যেখান থেকে)' : (($c['fund'] ?? '') === 'IN' ? 'Receive In Account (যেখানে জমা)' : (($c['fund'] ?? '') === 'OUT' ? 'Pay From Account (যেখান থেকে)' : 'Fund Account'));
    function acc_opts($accounts, $selected) {
        $o = '<option value="">-- নির্বাচন করুন --</option>';
        foreach ($accounts as $a) {
            $o .= '<option value="' . $a['id'] . '"' . ($selected == $a['id'] ? ' selected' : '') . '>' . h($a['coa_label']) . ' — ' . number_format($a['bal'], 2) . '</option>';
        }
        return $o;
    }

    $is_multi = in_array($type, ['payment', 'collection']);
    $ptLabels = ['SUPPLIER'=>'Supplier (সাপ্লায়ার)', 'CUSTOMER'=>'Customer (কাস্টমার)', 'EMPLOYEE'=>'Employee (কর্মচারী)', 'OTHER'=>'Others (অন্যান্য)'];
    $ptShort  = ['SUPPLIER'=>'Supplier', 'CUSTOMER'=>'Customer', 'EMPLOYEE'=>'Employee', 'OTHER'=>'Name (Others)'];
    $ptOrder  = $type === 'collection' ? ['CUSTOMER', 'SUPPLIER', 'EMPLOYEE', 'OTHER'] : ['SUPPLIER', 'CUSTOMER', 'EMPLOYEE', 'OTHER'];
    $ptn = $ed['party_type'] ?? ($c['party'] ?? '');

    // জার্নাল: হেড/পার্টি তালিকা ও এডিটের সারি
    $jHeads = []; $jParties = []; $jRows = []; $jNo = ''; $jRef = ''; $jNarr = '';
    if ($is_j) {
        $H = journal_heads($conn, $accounts); $PM = journal_parties($conn);
        foreach ($H as $k => $l)  $jHeads[]   = ['k'=>$k, 'l'=>$l];
        foreach ($PM as $k => $l) $jParties[] = ['k'=>$k, 'l'=>$l];
        if ($ed) {
            $jNo = $ed['voucher_no']; $jRef = (string)$ed['head_name']; $jNarr = (string)$ed['remarks'];
            $lr = mysqli_query($conn, "SELECT * FROM erp_voucher_lines WHERE voucher_id=" . (int)$ed['id'] . " ORDER BY id");
            while ($lr && $x = mysqli_fetch_assoc($lr)) {
                $hk = $x['head_kind'] . ':' . $x['head_id']; $pk = $x['party_type'] ? $x['party_type'] . ':' . $x['party_id'] : '';
                $jRows[] = ['head'=>$hk, 'headLabel'=>($H[$hk] ?? $x['head_label']), 'party'=>$pk, 'partyLabel'=>($PM[$pk] ?? ''),
                            'dr'=>$x['dr_amount'] > 0 ? $x['dr_amount'] : '', 'cr'=>$x['cr_amount'] > 0 ? $x['cr_amount'] : '', 'note'=>(string)$x['note']];
            }
            if (!$jRows) { // পুরনো ধরনের এন্ট্রি → সারিতে রূপান্তর (অ্যাকাউন্ট হেড না থাকলে নতুন করে বাছতে হবে)
                $legs = [[$ed['party_type'], (int)$ed['party_id'], (int)$ed['account_id'], ($ed['entry_side'] ?? 'DR') === 'CR' ? 'cr' : 'dr']];
                if (!empty($ed['cr_party_type']) || !empty($ed['to_account_id'])) $legs[] = [$ed['cr_party_type'] ?? '', (int)($ed['cr_party_id'] ?? 0), (int)$ed['to_account_id'], 'cr'];
                foreach ($legs as $lg) {
                    $hk = (!$lg[0] && $lg[2] > 0) ? 'FUND:' . $lg[2] : ''; $pk = $lg[0] ? $lg[0] . ':' . $lg[1] : '';
                    $jRows[] = ['head'=>$hk, 'headLabel'=>($H[$hk] ?? ''), 'party'=>$pk, 'partyLabel'=>($PM[$pk] ?? ''),
                                'dr'=>$lg[3] === 'dr' ? $ed['amount'] : '', 'cr'=>$lg[3] === 'cr' ? $ed['amount'] : '', 'note'=>''];
                }
            }
        } else {
            $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(id) AS m FROM erp_vouchers"));
            $jNo = 'JV-' . date('y') . str_pad(((int)$m['m']) + 1, 4, '0', STR_PAD_LEFT);
        }
    }
?>
<form method="POST" onsubmit="return validateForm()">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="do" value="save">
<input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">

<div class="form-panel">
    <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0;border-bottom:none;">
        <button type="submit" class="btn-action btn-save"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <button type="button" class="btn-action" onclick="location.href='transaction.php?type=<?php echo $type; ?>'"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
    </div>
    <div class="panel-header"><?php echo h($c['t']); ?> — <?php echo $edit_id ? 'Edit Mode (' . h($ed['voucher_no']) . ')' : 'New Entry'; ?></div>
    <div class="panel-body">
        <?php if ($is_j) { ?>
        <div class="jwrap">
            <div class="jhead">
                <div class="fg"><label>Voucher No</label><input type="text" class="fc" readonly style="font-weight:bold;" value="<?php echo h($jNo); ?>"></div>
                <div class="fg"><label>Voucher Date *</label><input type="date" name="voucher_date" class="fc" required value="<?php echo $ed ? h($ed['voucher_date']) : date('Y-m-d'); ?>"></div>
                <div class="fg"><label>Ref. No.</label><input type="text" name="ref_no" class="fc" maxlength="60" value="<?php echo h($jRef); ?>"></div>
                <div class="fg" style="grid-column: 1 / -1;"><label>Narration</label><textarea name="remarks" class="fc" rows="2" maxlength="255" style="height:auto;padding:6px 10px;"><?php echo h($jNarr); ?></textarea></div>
            </div>
            <div class="jtools">
                <button type="button" class="btn-action" onclick="jAdd(null, true)"><i class="fa-solid fa-plus" style="color:#28a745;"></i> Add</button>
                <button type="button" class="btn-action" onclick="jClear()"><i class="fa-solid fa-eraser" style="color:#e67e22;"></i> Clear</button>
            </div>
            <table class="grid-table" id="jTable">
                <thead><tr><th style="width:40px;"></th><th style="width:30%;">Account Head</th><th style="width:27%;">Party</th><th style="width:130px;">Dr. Amount</th><th style="width:130px;">Cr. Amount</th><th>Note</th></tr></thead>
                <tbody id="jBody"></tbody>
                <tfoot><tr class="total-row"><td colspan="3" style="text-align:right;">Total :</td><td class="r" id="jTotDr">0.00</td><td class="r" id="jTotCr">0.00</td><td id="jDiff"></td></tr></tfoot>
            </table>
            <datalist id="jhead_list"><?php foreach ($jHeads as $x) echo '<option value="' . h($x['l']) . '">'; ?></datalist>
            <datalist id="jparty_list"><?php foreach ($jParties as $x) echo '<option value="' . h($x['l']) . '">'; ?></datalist>
        </div>
        <?php } else { ?>
        <div class="grid3">
            <div class="fg"><label>Date *</label><input type="date" name="voucher_date" class="fc" required value="<?php echo $ed ? h($ed['voucher_date']) : date('Y-m-d'); ?>"></div>

            <?php if ($is_multi) { ?>
            <div class="fg"><label><?php echo $type === 'collection' ? 'Receive From (কার কাছ থেকে)' : 'Pay To (কাকে)'; ?> *</label>
                <select name="party_type" id="party_type" class="fc" onchange="onPayTo()">
                    <?php foreach ($ptOrder as $k) echo '<option value="' . $k . '"' . ($ptn == $k ? ' selected' : '') . '>' . $ptLabels[$k] . '</option>'; ?>
                </select></div>

            <div class="fg" id="partyBox">
                <label id="partyLbl"><?php echo $ptShort[$ptn] ?? 'Party'; ?> (Search) *</label>
                <input type="text" id="party_search" class="fc" placeholder="<?php echo $ptn == 'OTHER' ? 'নাম লিখুন বা আগের তালিকা থেকে বাছুন...' : 'নাম লিখে খুঁজুন...'; ?>" onchange="syncParty()" oninput="if (curPT === 'OTHER') other_name.value = this.value.trim();" value="<?php echo h($party_label); ?>">
                <input type="hidden" name="other_name" id="other_name" value="<?php echo ($ed && $ed['party_type'] === 'OTHER') ? h($ed['head_name']) : ''; ?>">
                <input type="hidden" name="party_id" id="party_id" value="<?php echo $ed ? (int)$ed['party_id'] : ''; ?>">
                <div class="bal" id="partyBal"></div>
            </div>
            <datalist id="party_list"></datalist>
            <?php } ?>


            <?php if (isset($c['head'])) {
                $heads = array_column(coa_leaf_heads($conn, $type == 'income' ? 'Income' : 'Expense'), 'name');
                if (!$heads) $heads = $type == 'income'
                    ? ['Scrap Sale', 'Rent Income', 'Interest Income', 'Other Income']
                    : ['Transport', 'Tea & Snacks', 'Labor / Unloading', 'Fuel', 'Electricity Bill', 'Office Rent', 'Mobile & Internet', 'Repair & Maintenance', 'Stationery', 'Miscellaneous'];
                $curHead = $ed ? (string)$ed['head_name'] : '';
                if ($curHead !== '' && !in_array($curHead, $heads, true)) $heads[] = $curHead; // পুরনো ভাউচারের খাত
            ?>
            <div class="fg"><label><?php echo h($c['head']); ?> *</label>
                <div style="display:flex; gap:6px; align-items:center;">
                    <input type="text" name="head_name" id="head_name" class="fc" list="head_list" autocomplete="off" placeholder="লিখে খুঁজুন বা তালিকা থেকে বাছুন..." value="<?php echo h($curHead); ?>">
                    <button type="button" class="btn-plus" title="নতুন খাত যোগ করুন" onclick="openHeadModal()">+</button>
                </div>
                <datalist id="head_list">
                    <?php foreach ($heads as $hn) echo '<option value="' . h($hn) . '">'; ?>
                </datalist>
            </div>
            <?php } ?>

            <?php if (!$is_j) { ?>
            <div class="fg" id="accBox"><label id="accLbl"><?php echo $acc_label; ?> *</label>
                <select name="account_id" id="account_id" class="fc"><?php echo acc_opts($accounts, $ed['account_id'] ?? 0); ?></select></div>
            <?php } ?>

            <?php if ($type === 'transfer') { ?>
            <div class="fg"><label>To Account (যেখানে যাবে) *</label>
                <select name="to_account_id" id="to_account_id" class="fc"><?php echo acc_opts($accounts, $ed['to_account_id'] ?? 0); ?></select></div>
            <?php } ?>

            <div class="fg"><label>Amount (BDT) *</label><input type="number" step="0.01" min="0.01" name="amount" id="amount" class="fc" required style="font-weight:bold;text-align:right;" value="<?php echo $ed ? h($ed['amount']) : ''; ?>" onfocus="this.select()"></div>

            <?php if ($is_multi) { ?>
            <div class="fg"><label>Discount (BDT)</label>
                <input type="number" step="0.01" min="0" name="discount" id="discount" class="fc" style="font-weight:bold;text-align:right;" value="<?php echo h($ed['discount'] ?? 0); ?>" onfocus="this.select()">
                <div class="bal" id="discHint" style="color:#666;"></div></div>
            <?php } ?>

            <div class="fg"><label>Remarks / Narration</label><input type="text" name="remarks" class="fc" maxlength="255" value="<?php echo $ed ? h($ed['remarks']) : ''; ?>"></div>
        </div>
        <?php } ?>
    </div>
</div>
</form>

<?php if (isset($c['head'])) { ?>
<div id="headModal" class="hmodal"><div class="hmodal-box">
    <div style="font-size:13px;font-weight:bold;color:#2e7d32;margin-bottom:10px;"><i class="fa-solid fa-plus"></i> নতুন <?php echo h($c['head']); ?> যোগ করুন</div>
    <input type="text" id="newHeadInput" class="fc" placeholder="খাতের নাম লিখুন" onkeydown="if (event.key === 'Enter') { event.preventDefault(); addHead(); }">
    <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
        <button type="button" class="btn-action" onclick="closeHeadModal()">Cancel</button>
        <button type="button" class="btn-action btn-save" onclick="addHead()">Add</button>
    </div>
</div></div>
<?php } ?>

<script>
var TYPE = <?php echo json_encode($type); ?>;
var PARTY_FIXED = <?php echo json_encode($c['party'] ?? ''); ?>;
var CUSTOMERS = <?php echo json_encode($customers); ?>, SUPPLIERS = <?php echo json_encode($suppliers); ?>, EMPLOYEES = <?php echo json_encode($employees); ?>, OTHERS = <?php echo json_encode($others); ?>;
var curPT = PARTY_FIXED;
function $id(i) { return document.getElementById(i); }
function optHtml(arr) { return arr.map(function(p) { return '<option data-id="' + p.id + '" value="' + p.label.replace(/"/g, '&quot;') + '">'; }).join(''); }

// ---- Payment / Collection: পার্টি ----
function fillList(pt) {
    var dl = $id('party_list'); if (!dl) return;
    dl.innerHTML = optHtml(pt === 'CUSTOMER' ? CUSTOMERS : (pt === 'EMPLOYEE' ? EMPLOYEES : (pt === 'OTHER' ? OTHERS : SUPPLIERS)));
    $id('party_search').setAttribute('list', 'party_list');
}
function syncParty() {
    var val = party_search.value, opts = $id('party_list').options;
    party_id.value = ''; partyBal.textContent = '';
    if (curPT === 'OTHER') { other_name.value = val.trim(); return; }
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { party_id.value = opts[i].getAttribute('data-id'); showBal(); break; }
    }
}
function balText(pt, b) {
    var word = pt === 'CUSTOMER' ? 'কাস্টমারের বকেয়া' : 'সাপ্লায়ারের পাওনা';
    return word + ': ' + b.toFixed(2) + ' BDT' + (b < 0 ? ' (অগ্রিম/বেশি জমা)' : '');
}
function showBal() {
    if (!party_id.value || curPT === 'EMPLOYEE' || curPT === 'OTHER') { partyBal.textContent = ''; return; }
    fetch('transaction.php?ajax=balance&pt=' + curPT + '&id=' + party_id.value).then(function(r) { return r.json(); }).then(function(d) {
        var b = parseFloat(d.balance) || 0;
        partyBal.style.color = b > 0 ? '#d32f2f' : '#2e7d32';
        partyBal.textContent = balText(curPT, b);
    });
}
function onPayTo() {
    var t = $id('party_type').value;
    PARTY_FIXED = t; curPT = t;
    partyLbl.textContent = ({SUPPLIER: 'Supplier', CUSTOMER: 'Customer', EMPLOYEE: 'Employee', OTHER: 'Name (Others)'})[t] + ' (Search) *';
    party_search.placeholder = t === 'OTHER' ? 'নাম লিখুন বা আগের তালিকা থেকে বাছুন...' : 'নাম লিখে খুঁজুন...';
    party_search.value = ''; party_id.value = ''; other_name.value = ''; partyBal.textContent = '';
    fillList(t);
}
function updDisc() {
    var h = $id('discHint'); if (!h) return;
    var a = parseFloat($id('amount').value) || 0, d = parseFloat($id('discount').value) || 0;
    h.textContent = d > 0 ? (TYPE === 'collection' ? 'ফান্ডে জমা হবে: ' : 'ফান্ড থেকে বের হবে: ') + a.toFixed(2) + ' | পার্টির হিসাবে সমন্বয়: ' + (a + d).toFixed(2) + ' BDT' : '';
}
function openHeadModal() { $id('headModal').style.display = 'block'; $id('newHeadInput').value = ''; $id('newHeadInput').focus(); }
function closeHeadModal() { $id('headModal').style.display = 'none'; }
function putHead(v) {
    var dl = $id('head_list'), opts = dl.options, found = false;
    for (var i = 0; i < opts.length; i++) { if (opts[i].value.toLowerCase() === v.toLowerCase()) { v = opts[i].value; found = true; break; } }
    if (!found) { var o = document.createElement('option'); o.value = v; dl.appendChild(o); }
    $id('head_name').value = v; closeHeadModal();
}
function addHead() {
    var v = $id('newHeadInput').value.trim(); if (!v) { alert('খাতের নাম লিখুন!'); return; }
    var fd = new FormData();
    fd.append('do', 'add_head'); fd.append('name', v); fd.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
    var fallback = function() { putHead(v); alert('চার্ট অফ অ্যাকাউন্টসে নতুন হেড তৈরি করা যায়নি। নামটি শুধু এই ভাউচারে বসানো হলো। স্থায়ী করতে Accounts > Chart of Accounts এ হেডটি যোগ করুন।'); };
    fetch('transaction.php?type=' + TYPE, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) {
        if (d && d.ok) putHead(d.name || v); else fallback();
    }).catch(fallback);
}

// ---- Journal: বহু-সারি (Account Head / Party / Dr / Cr / Note) ----
var J_HEADS = <?php echo json_encode($jHeads); ?>, J_PARTIES = <?php echo json_encode($jParties); ?>, J_ROWS = <?php echo json_encode($jRows); ?>;
var JH = {}, JP = {};
function jSum(sel) { var t = 0; document.querySelectorAll('#jBody ' + sel).forEach(function(e) { t += parseFloat(e.value) || 0; }); return t; }
function jTotals() {
    var dr = jSum('.j-dr'), cr = jSum('.j-cr'), d = dr - cr, el = $id('jDiff');
    $id('jTotDr').textContent = dr.toFixed(2); $id('jTotCr').textContent = cr.toFixed(2);
    if (Math.abs(d) < 0.005) { el.textContent = dr > 0 ? '✔ Dr = Cr' : ''; el.style.color = '#2e7d32'; }
    else { el.textContent = 'পার্থক্য: ' + d.toFixed(2); el.style.color = '#d32f2f'; }
}
function jAdd(d, auto) {
    d = d || {};
    var tr = document.createElement('tr'); tr.className = 'jrow';
    tr.innerHTML = '<td style="text-align:center;"><span class="jdel" title="মুছুন" onclick="jDel(this)"><i class="fa-solid fa-trash-can"></i></span></td>'
        + '<td><input type="text" class="fc j-head-txt" list="jhead_list" autocomplete="off" placeholder="অ্যাকাউন্ট হেড লিখে খুঁজুন..." onchange="jPick(this, JH)"><input type="hidden" name="j_head[]" class="j-head"></td>'
        + '<td><input type="text" class="fc j-party-txt" list="jparty_list" autocomplete="off" placeholder="পার্টি (ঐচ্ছিক)" onchange="jPick(this, JP)"><input type="hidden" name="j_party[]" class="j-party"></td>'
        + '<td><input type="number" step="0.01" min="0" name="j_dr[]" class="fc j-dr" style="text-align:right;" oninput="jSide(this, \'.j-cr\')" onfocus="this.select()"></td>'
        + '<td><input type="number" step="0.01" min="0" name="j_cr[]" class="fc j-cr" style="text-align:right;" oninput="jSide(this, \'.j-dr\')" onfocus="this.select()"></td>'
        + '<td><input type="text" name="j_note[]" class="fc j-note" maxlength="200"></td>';
    $id('jBody').appendChild(tr);
    tr.querySelector('.j-head-txt').value = d.headLabel || ''; tr.querySelector('.j-head').value = d.head || '';
    tr.querySelector('.j-party-txt').value = d.partyLabel || ''; tr.querySelector('.j-party').value = d.party || '';
    tr.querySelector('.j-dr').value = d.dr || ''; tr.querySelector('.j-cr').value = d.cr || '';
    tr.querySelector('.j-note').value = d.note || '';
    if (auto) { // নতুন সারিতে বাকি পার্থক্যটা উল্টো দিকে বসিয়ে দেয়
        var diff = jSum('.j-dr') - jSum('.j-cr');
        if (diff > 0.004) tr.querySelector('.j-cr').value = diff.toFixed(2);
        else if (diff < -0.004) tr.querySelector('.j-dr').value = (-diff).toFixed(2);
        jTotals(); tr.querySelector('.j-head-txt').focus();
    }
}
function jDup(tr) { // একই অ্যাকাউন্ট হেড + পার্টি দুই সারিতে নয়
    var hk = tr.querySelector('.j-head').value; if (!hk) return false;
    var pk = tr.querySelector('.j-party').value, dup = false;
    document.querySelectorAll('#jBody .jrow').forEach(function(r) {
        if (r !== tr && r.querySelector('.j-head').value === hk && r.querySelector('.j-party').value === pk) dup = true;
    });
    return dup;
}
function jPick(el, map) {
    var k = map[el.value.trim()] || '';
    el.nextElementSibling.value = k;
    el.classList.toggle('bad', !k && el.value.trim() !== '');
    if (k && jDup(el.closest('tr'))) {
        alert('এই অ্যাকাউন্ট হেড (ও পার্টি) আগের সারিতে আছে। একই সারিতে মোট পরিমাণ দিন।');
        el.value = ''; el.nextElementSibling.value = '';
    }
}
function jSide(el, otherSel) { // একই সারিতে Dr ও Cr দুটোই থাকবে না
    if (parseFloat(el.value) > 0) el.closest('tr').querySelector(otherSel).value = '';
    jTotals();
}
function jDel(btn) {
    var tr = btn.closest('tr');
    if (document.querySelectorAll('#jBody .jrow').length > 2) tr.remove();
    else tr.querySelectorAll('input').forEach(function(i) { i.value = ''; i.classList.remove('bad'); });
    jTotals();
}
function jClear() { $id('jBody').innerHTML = ''; jAdd(); jAdd(); jTotals(); }
function jInit() {
    J_HEADS.forEach(function(h) { JH[h.l] = h.k; });
    J_PARTIES.forEach(function(p) { JP[p.l] = p.k; });
    if (J_ROWS.length) J_ROWS.forEach(function(d) { jAdd(d, false); }); else { jAdd(); jAdd(); }
    jTotals();
}

function validateForm() {
    if (TYPE === 'journal') {
        var rows = document.querySelectorAll('#jBody .jrow'), used = 0, dr = 0, cr = 0, seenJ = {};
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r], n = r + 1;
            var ht = row.querySelector('.j-head-txt').value.trim(), hk = row.querySelector('.j-head').value;
            var pt = row.querySelector('.j-party-txt').value.trim(), pk = row.querySelector('.j-party').value;
            var d = parseFloat(row.querySelector('.j-dr').value) || 0, c = parseFloat(row.querySelector('.j-cr').value) || 0;
            if (!ht && !pt && !d && !c) continue;
            if (!hk) { alert('সারি ' + n + ': তালিকা থেকে একটি অ্যাকাউন্ট হেড নির্বাচন করুন!'); return false; }
            if (pt && !pk) { alert('সারি ' + n + ': তালিকা থেকে একটি পার্টি নির্বাচন করুন!'); return false; }
            if (seenJ[hk + '|' + pk]) { alert('সারি ' + n + ': একই অ্যাকাউন্ট হেড ও পার্টি আগের সারিতে আছে — একটি সারিতে মোট পরিমাণ দিন!'); return false; }
            seenJ[hk + '|' + pk] = 1;
            if ((d > 0) === (c > 0)) { alert('সারি ' + n + ': Dr অথবা Cr — যেকোনো একটিতে পরিমাণ দিন!'); return false; }
            used++; dr += d; cr += c;
        }
        if (used < 2) { alert('কমপক্ষে দুটি সারি দিন!'); return false; }
        if (Math.abs(dr - cr) > 0.009) { alert('Dr ও Cr এর যোগফল সমান নয়! (Dr: ' + dr.toFixed(2) + ', Cr: ' + cr.toFixed(2) + ')'); return false; }
        return true;
    }
    var needParty = !!PARTY_FIXED;
    if (curPT === 'OTHER') {
        needParty = false;
        if (!party_search.value.trim()) { alert('নাম লিখুন!'); return false; }
        other_name.value = party_search.value.trim();
    }
    if (needParty && !party_id.value) { alert('তালিকা থেকে একটি পার্টি নির্বাচন করুন!'); return false; }
    if (!account_id.value) { alert('একটি ফান্ড অ্যাকাউন্ট নির্বাচন করুন!'); return false; }
    if ($id('head_name')) {
        var hv = head_name.value.trim().toLowerCase(), hok = false, ho = $id('head_list').options;
        for (var q = 0; q < ho.length; q++) { if (ho[q].value.toLowerCase() === hv) { hok = true; head_name.value = ho[q].value; break; } }
        if (!hok) { alert('তালিকা থেকে একটি খাত বাছুন, অথবা পাশের + বাটন দিয়ে নতুন খাত যোগ করুন!'); return false; }
    }
    if (TYPE === 'transfer') {
        if (!to_account_id.value) { alert('গন্তব্য অ্যাকাউন্ট নির্বাচন করুন!'); return false; }
        if (account_id.value === to_account_id.value) { alert('একই অ্যাকাউন্টে ট্রান্সফার করা যায় না!'); return false; }
    }
    return true;
}
window.addEventListener('load', function() {
    ['amount', 'discount'].forEach(function(i) { var e = $id(i); if (e) e.addEventListener('input', updDisc); });
    updDisc();
    if ($id('party_type')) { PARTY_FIXED = curPT = party_type.value; }
    if (PARTY_FIXED) { fillList(PARTY_FIXED); if (party_id.value) showBal(); }
    if (TYPE === 'journal') jInit();
});
</script>
<?php } ?>
</body>
</html>