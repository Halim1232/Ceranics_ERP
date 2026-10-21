<?php
// Purchase/purchase_grn_factory.php -- GRN (Factory Measurement): List + New + Edit + Delete
// Stock  : erp_stock_ledger (sqft_in = Actual QTY, rate = Amount / Actual QTY)
// Bill   : erp_purchase_grn_bill (bill_qty, bill_rate) -- edit/print er jonno
// Journal: Dr 1201 Inventory / Cr 2001 Payable (Gross = Bill Amount + Labor - Discount)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$is_ajax = isset($_POST['action']);
if ($is_ajax) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'unauthorized']); exit; }
    include_once('../access.php'); require_page_access('purchase/purchase_grn_factory.php', true);   // AJAX: JSON 403
} else {
    include('../auth.php'); require_page_access();
}
include('../config.php');
include('../balance_functions.php');   // supplier_balance()
include_once('../fund_lib.php');        // Chart of Accounts head label (fl_fund_accounts)

const DEFAULT_FACTOR = 0.9684;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function product_stock($conn, $pid) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in - sqft_out),0) AS s FROM erp_stock_ledger WHERE product_id=" . (int)$pid . branch_wh_sql('warehouse_id')));   // ব্রাঞ্চ ইউজার: নিজের ব্রাঞ্চের স্টক
    return (float)$r['s'];
}
function grn_prev_balance($conn, $sid, $exclude_id = 0) {
    $sid = (int)$sid; if ($sid <= 0) return 0;
    $bal = supplier_balance($conn, $sid);
    if ($exclude_id > 0) {
        $g = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grn_no, labor_charge, discount_amount, payment_amount FROM erp_purchase_grn WHERE id=" . (int)$exclude_id . " AND supplier_id=$sid"));
        if ($g) {
            $no = mysqli_real_escape_string($conn, $g['grn_no']);
            $it = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in * rate),0) AS s FROM erp_stock_ledger WHERE voucher_no='$no' AND voucher_type='PURCHASE'"));
            $bal -= ((float)$it['s'] + (float)$g['labor_charge'] - (float)$g['discount_amount'] - (float)$g['payment_amount']);
        }
    }
    return $bal;
}
function grn_no_in_use_stock($conn, $grn_no) {
    $out = [];
    $no = mysqli_real_escape_string($conn, $grn_no);
    $q = mysqli_query($conn, "SELECT product_id, SUM(sqft_in) AS q FROM erp_stock_ledger WHERE voucher_no='$no' AND voucher_type='PURCHASE' GROUP BY product_id");
    while ($q && $r = mysqli_fetch_assoc($q)) $out[(int)$r['product_id']] = (float)$r['q'];
    return $out;
}

// ---------- AJAX ----------
if ($is_ajax) {
    $act = $_POST['action'];
    if ($act === 'get_current_stock') {
        echo json_encode(['status' => 'success', 'stock' => number_format(product_stock($conn, (int)$_POST['product_id']), 2, '.', '')]); exit;
    }
    if ($act === 'get_prev_balance') {
        $b = grn_prev_balance($conn, (int)$_POST['supplier_id'], (int)($_POST['exclude_id'] ?? 0));
        echo json_encode(['status' => 'success', 'balance' => number_format($b, 2, '.', '')]); exit;
    }
    if ($act === 'save_supplier') {
        $name = trim($_POST['supplier_name'] ?? ''); $phone = trim($_POST['mobile_no'] ?? ''); $addr = trim($_POST['address'] ?? '');
        if ($name === '' || $phone === '') { echo json_encode(['status' => 'error']); exit; }
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_suppliers"));
        $code = "SUP-" . str_pad(((int)$m['m']) + 1, 4, "0", STR_PAD_LEFT);
        $empty = ''; $status = 'Active';
        $st = $conn->prepare("INSERT INTO erp_suppliers (supplier_code, supplier_name, company_name, mobile_no, email, address, status) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param("sssssss", $code, $name, $empty, $phone, $empty, $addr, $status);
        $st->execute();
        echo json_encode(['status' => 'success', 'id' => $conn->insert_id]); exit;
    }
    echo json_encode(['status' => 'error']); exit;
}

$self = 'purchase_grn_factory.php';
$action = $_GET['action'] ?? 'list';
$fail = function ($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };

// ---------- POST: DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');
    $id = (int)$_POST['id'];
    $g = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grn_no FROM erp_purchase_grn WHERE id=$id" . branch_sql('branch_name')));
    if (!$g) $fail('GRN পাওয়া যায়নি।');
    $no = $g['grn_no']; $noe = mysqli_real_escape_string($conn, $no);
    try {   // Purchase Return thaka GRN delete kora jabe na
        $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='$noe'"));
        if ($chk['c'] > 0) $fail('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।');
    } catch (Throwable $e) { /* return table nai */ }

    $short = [];
    foreach (grn_no_in_use_stock($conn, $no) as $pid => $inq) {
        $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, negative_delivery FROM erp_products WHERE id=$pid"));
        $after = product_stock($conn, $pid) - $inq;
        if ($p && !$p['negative_delivery'] && $after < -0.01) $short[] = $p['item_name'] . " (মুছলে স্টক হবে " . number_format($after, 2) . ")";
    }
    if ($short) $fail("এই GRN মুছলে স্টক মাইনাসে যাবে, কারণ মাল ইতিমধ্যে বিক্রি/ডেলিভারি হয়েছে:\n" . implode("\n", $short));

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$noe' AND voucher_type='PURCHASE'");
        mysqli_query($conn, "DELETE FROM erp_purchase_grn_bill WHERE grn_no='$noe'");
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='PAYMENT' AND ref_no='$noe'");
        mysqli_query($conn, "DELETE FROM erp_purchase_grn WHERE id=$id");
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); error_log($e->getMessage()); $fail('ডিলিট ব্যর্থ হয়েছে।'); }

    try {
        if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
        include_once('../Accounts/accounts_lib.php');
        delete_journal($conn, 'PURCHASE', $no);
        acc_sync_fund_journal($conn);
    } catch (Throwable $e) { }
    echo "<script>alert('GRN মুছে ফেলা হয়েছে।'); window.location.href='$self';</script>"; exit;
}

// ---------- POST: SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grn'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');

    $edit_id     = (int)($_POST['edit_id'] ?? 0);
    $grn_date    = trim($_POST['grn_date'] ?? '');
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $branch      = trim($_POST['branch_name'] ?? '');
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $wrow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.id=$warehouse_id" . branch_wh_sql('w.id')));
    if (!$wrow) $fail('অনুগ্রহ করে একটি ওয়্যারহাউজ নির্বাচন করুন!');
    $branch = $wrow['branch_name'];   // branch warehouse থেকেই নেওয়া হয়
    $remarks     = trim($_POST['remarks'] ?? '');
    $labor       = round(floatval($_POST['labor_charge'] ?? 0), 2);
    $discount    = round(floatval($_POST['discount_amount'] ?? 0), 2);
    if ($grn_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $grn_date)) $fail('সঠিক তারিখ দিন!');
    if ($supplier_id <= 0) $fail('অনুগ্রহ করে তালিকা থেকে একটি সাপ্লায়ার নির্বাচন করুন!');

    $pay_lines = []; $acc_seen = [];
    foreach (($_POST['pay_amount'] ?? []) as $i => $pamt) {
        $pamt = round(floatval($pamt), 2); $pacc = (int)($_POST['pay_account'][$i] ?? 0);
        if ($pamt <= 0) continue;
        if ($pacc <= 0) $fail('পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!');
        if (isset($acc_seen[$pacc])) $fail('একই ক্যাশ/অ্যাকাউন্ট একাধিকবার দেওয়া যাবে না — ওই লাইনেই পরিমাণ বাড়ান!');
        $acc_seen[$pacc] = 1;
        $pay_lines[] = ['acc' => $pacc, 'amt' => $pamt];
    }
    $payment = 0; foreach ($pay_lines as $pl) $payment += $pl['amt'];

    // আইটেম: Actual QTY -> stock, Bill Qty x Bill Rate -> Amount, Rate = Amount / Actual QTY
    $items = []; $need = [];
    foreach (($_POST['product_id'] ?? []) as $i => $p) {
        $pid = (int)$p; $qty = floatval($_POST['qty_in'][$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $bq = round(floatval($_POST['bill_qty'][$i] ?? 0), 2);
        $br = round(floatval($_POST['bill_rate'][$i] ?? 0), 4);
        if ($bq <= 0) $fail('প্রতিটি পণ্যের Bill Qty দিন!');
        $amount = round($bq * $br, 2);
        $items[] = ['pid' => $pid, 'box' => floatval($_POST['box_in'][$i] ?? 0), 'pcs' => floatval($_POST['pcs_in'][$i] ?? 0),
                    'qty' => $qty, 'bq' => $bq, 'br' => $br, 'amount' => $amount, 'rate' => round($amount / $qty, 6)];
        $need[$pid] = ($need[$pid] ?? 0) + $qty;
    }
    if (!$items) $fail('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!');

    $old_no = '';
    if ($edit_id > 0) {
        $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grn_no FROM erp_purchase_grn WHERE id=$edit_id" . branch_sql('branch_name')));
        if (!$o) $fail('GRN পাওয়া যায়নি!');
        $old_no = $o['grn_no'];
        try {   // Purchase Return thaka GRN edit kora jabe na
            $olde0 = mysqli_real_escape_string($conn, $old_no);
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='$olde0'"));
            if ($chk['c'] > 0) $fail('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।');
        } catch (Throwable $e) { /* return table nai */ }
        $old_in = grn_no_in_use_stock($conn, $old_no);
        $short = [];
        foreach ($old_in as $pid => $oq) {
            $nq = $need[$pid] ?? 0;
            if ($nq >= $oq) continue;
            $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, negative_delivery FROM erp_products WHERE id=$pid"));
            $after = product_stock($conn, $pid) - $oq + $nq;
            if ($p && !$p['negative_delivery'] && $after < -0.01) $short[] = $p['item_name'] . " (স্টক হবে " . number_format($after, 2) . ")";
        }
        if ($short) $fail("পরিমাণ কমালে স্টক মাইনাসে যাবে, কারণ মাল বিক্রি/ডেলিভারি হয়েছে:\n" . implode("\n", $short));
    }

    $total = 0; foreach ($items as $it) $total += $it['amount'];
    $total = round($total, 2);
    $gross = round($total + $labor - $discount, 2);
    $prev  = round(grn_prev_balance($conn, $supplier_id, $edit_id), 2);
    $net   = round($gross + $prev - $payment, 2);

    mysqli_begin_transaction($conn);
    try {
        if ($edit_id > 0) {
            mysqli_query($conn, "SELECT id FROM erp_purchase_grn WHERE id=$edit_id FOR UPDATE");
            $grn_no = $old_no;
            $st = $conn->prepare("UPDATE erp_purchase_grn SET grn_date=?, supplier_id=?, branch_name=?, warehouse_id=?, prev_balance=?, labor_charge=?, discount_amount=?, payment_amount=?, net_balance=?, remarks=? WHERE id=?");
            $st->bind_param("sisidddddsi", $grn_date, $supplier_id, $branch, $warehouse_id, $prev, $labor, $discount, $payment, $net, $remarks, $edit_id);
            $st->execute();
            $noe = mysqli_real_escape_string($conn, $grn_no);
            mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$noe' AND voucher_type='PURCHASE'");
            mysqli_query($conn, "DELETE FROM erp_purchase_grn_bill WHERE grn_no='$noe'");
            mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='PAYMENT' AND ref_no='$noe'");
        } else {
            $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_purchase_grn FOR UPDATE"));
            $grn_no = "GRN-" . date('y') . str_pad(((int)$m['m']) + 1, 4, "0", STR_PAD_LEFT);
            $noe = mysqli_real_escape_string($conn, $grn_no);
            mysqli_query($conn, "DELETE FROM erp_purchase_grn_bill WHERE grn_no='$noe'");   // পুরনো orphan থাকলে পরিষ্কার
            $st = $conn->prepare("INSERT INTO erp_purchase_grn (grn_no, grn_date, supplier_id, branch_name, warehouse_id, prev_balance, labor_charge, discount_amount, payment_amount, net_balance, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param("ssisiddddds", $grn_no, $grn_date, $supplier_id, $branch, $warehouse_id, $prev, $labor, $discount, $payment, $net, $remarks);
            $st->execute();
        }
        $sl = $conn->prepare("INSERT INTO erp_stock_ledger (product_id, warehouse_id, voucher_type, voucher_no, box_in, pcs_in, sqft_in, box_out, pcs_out, sqft_out, rate, total_value) VALUES (?, ?, 'PURCHASE', ?, ?, ?, ?, 0, 0, 0, ?, ?)");
        $bl = $conn->prepare("INSERT INTO erp_purchase_grn_bill (grn_no, line_no, product_id, bill_qty, bill_rate) VALUES (?,?,?,?,?)");
        $line = 0;
        foreach ($items as $it) {
            $line++;
            $sl->bind_param("iisddddd", $it['pid'], $warehouse_id, $grn_no, $it['box'], $it['pcs'], $it['qty'], $it['rate'], $it['amount']);
            $sl->execute();
            $bl->bind_param("siidd", $grn_no, $line, $it['pid'], $it['bq'], $it['br']);
            $bl->execute();
        }
        $fl = $conn->prepare("INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, created_by) VALUES (?, ?, 'OUT', ?, 'PAYMENT', ?, ?)");
        $uid = (int)$_SESSION['user_id'];
        foreach ($pay_lines as $pl) {
            $fl->bind_param("sidsi", $grn_date, $pl['acc'], $pl['amt'], $grn_no, $uid);
            $fl->execute();
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn); error_log('GRN factory save: ' . $e->getMessage());
        $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।');
    }

    try {
        if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
        include_once('../Accounts/accounts_lib.php');
        post_journal($conn, 'PURCHASE', $grn_no, $grn_date, "Purchase GRN $grn_no", [
            ['1201', $gross, 0],
            ['2001', 0, $gross, $supplier_id]
        ]);
        acc_sync_fund_journal($conn);
    } catch (Throwable $e) { error_log('GRN journal: ' . $e->getMessage()); }

    echo "<script>alert('GRN $grn_no সফলভাবে সংরক্ষিত হয়েছে।'); window.location.href='$self';</script>"; exit;
}

// ---------- ডাটা লোড ----------
$edit_id   = ($action === 'edit') ? (int)($_GET['id'] ?? 0) : 0;
$show_form = ($action === 'new' || $edit_id > 0);

$suppliers = []; $edit_data = null; $edit_items = []; $grn_no_show = ''; $pay_rows = []; $accounts = [];
$branches = []; $sup_label_now = '';

if ($show_form) {
    $q = mysqli_query($conn, "SELECT id, supplier_name, mobile_no, address FROM erp_suppliers WHERE status='Active' OR status IS NULL ORDER BY supplier_name");
    while ($q && $s = mysqli_fetch_assoc($q)) {
        $parts = array_filter([trim($s['address'] ?? ''), trim($s['mobile_no'] ?? '')]);
        $suppliers[] = ['id' => $s['id'], 'label' => $s['supplier_name'] . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '')];
    }
    $q = mysqli_query($conn, "SELECT DISTINCT branch_name FROM erp_branches WHERE status='Active'" . branch_sql('branch_name'));
    while ($q && $b = mysqli_fetch_assoc($q)) $branches[] = $b['branch_name'];
    if (!$branches && !current_branch()) $branches[] = 'Main Factory / Godown';
    $warehouses = [];
    $wq = mysqli_query($conn, "SELECT w.id, w.warehouse_name, b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.status='Active'" . branch_wh_sql('w.id') . " ORDER BY b.branch_name, w.warehouse_name");
    while ($wq && $w = mysqli_fetch_assoc($wq)) $warehouses[] = $w;
    $products = mysqli_query($conn, "SELECT id, item_name, unit_name, size_name, color_name, box_to_pcs, ratio_pcs, ratio_output FROM erp_products ORDER BY item_name ASC");

    // Chart of Accounts এর হেডসহ লেবেল + বর্তমান ব্যালেন্স (fund_lib.php)
    foreach (fl_fund_accounts($conn) as $a) $accounts[] = ['id' => (int)$a['id'], 'name' => $a['account_name'], 'label' => $a['coa_label'] . ' — ' . number_format($a['bal'], 2)];
    $sel_account = 0;
    foreach ($accounts as $a) { if (stripos($a['name'], 'cash in hand') !== false) { $sel_account = $a['id']; break; } }
    if (!$sel_account && $accounts) $sel_account = $accounts[0]['id'];

    $next = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_purchase_grn"));
    $grn_no_show = "GRN-" . date('y') . str_pad(((int)$next['m']) + 1, 4, "0", STR_PAD_LEFT);

    if ($edit_id > 0) {
        $er = mysqli_query($conn, "SELECT * FROM erp_purchase_grn WHERE id=$edit_id" . branch_sql('branch_name'));
        $edit_data = $er ? mysqli_fetch_assoc($er) : null;
        if (!$edit_data) { echo "<script>window.location.href='$self';</script>"; exit; }
        $grn_no_show = $edit_data['grn_no'];
        $noe = mysqli_real_escape_string($conn, $edit_data['grn_no']);
        try {   // Edit form kholar age-i block
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='$noe'"));
            if ($chk['c'] > 0) { echo "<script>alert('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।'); window.location.href='$self';</script>"; exit; }
        } catch (Throwable $e) { /* return table nai */ }
        $ir = mysqli_query($conn, "SELECT l.box_in, l.pcs_in, l.sqft_in, l.rate, l.product_id, p.item_name, p.size_name, p.color_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output
                                   FROM erp_stock_ledger l JOIN erp_products p ON p.id = l.product_id
                                   WHERE l.voucher_no='$noe' AND l.voucher_type='PURCHASE' ORDER BY l.id ASC");
        while ($ir && $x = mysqli_fetch_assoc($ir)) $edit_items[] = $x;

        // Bill Qty / Bill Rate (line_no = ledger er serial)
        $bills = [];
        $bq = mysqli_query($conn, "SELECT line_no, bill_qty, bill_rate FROM erp_purchase_grn_bill WHERE grn_no='$noe' ORDER BY line_no ASC");
        while ($bq && $b = mysqli_fetch_assoc($bq)) $bills[(int)$b['line_no']] = $b;
        foreach ($edit_items as $i => &$it) {
            $auto = round((float)$it['sqft_in'] * DEFAULT_FACTOR, 2);
            if (isset($bills[$i + 1])) {
                $it['bill_qty'] = (float)$bills[$i + 1]['bill_qty'];
                $it['bill_rate'] = (float)$bills[$i + 1]['bill_rate'];
            } else {   // পুরনো (factory page এর বাইরের) GRN
                $it['bill_qty'] = $auto;
                $amt = (float)$it['sqft_in'] * (float)$it['rate'];
                $it['bill_rate'] = $auto > 0 ? $amt / $auto : 0;
            }
            $it['manual'] = abs($it['bill_qty'] - $auto) > 0.005 ? 1 : 0;
        }
        unset($it);

        foreach ($suppliers as $s) if ($s['id'] == $edit_data['supplier_id']) $sup_label_now = $s['label'];
        $s2 = mysqli_query($conn, "SELECT account_id, amount FROM erp_fund_ledger WHERE ref_type='PAYMENT' AND ref_no='$noe'");
        while ($s2 && $x = mysqli_fetch_assoc($s2)) $pay_rows[] = ['acc' => (int)$x['account_id'], 'amt' => (float)$x['amount']];
        if (!$pay_rows && (float)$edit_data['payment_amount'] > 0) $pay_rows[] = ['acc' => $sel_account, 'amt' => (float)$edit_data['payment_amount']];
    }
    if (!$pay_rows) $pay_rows[] = ['acc' => $sel_account, 'amt' => ''];
}

function render_row($it = null) {
    $v = function ($k, $d = '') use ($it) { return $it ? h($it[$k] ?? $d) : $d; };
    $qty  = $it ? number_format((float)$it['sqft_in'], 2, '.', '') : '';
    $rate = $it ? number_format((float)$it['rate'], 4, '.', '') : '';
    $bq   = $it ? number_format((float)$it['bill_qty'], 2, '.', '') : '';
    $br   = $it ? number_format((float)$it['bill_rate'], 4, '.', '') : '';
    $amt  = $it ? number_format((float)$it['bill_qty'] * (float)$it['bill_rate'], 2, '.', '') : '';
    $manual = ($it && !empty($it['manual'])) ? 1 : 0;
    ?>
    <tr class="grn-item-row" data-manual="<?php echo $manual; ?>" data-boxpcs="<?php echo $it ? h($it['box_to_pcs'] ?: 1) : 1; ?>" data-ratiopcs="<?php echo $it ? h($it['ratio_pcs'] ?: 1) : 1; ?>" data-ratiout="<?php echo $it ? h($it['ratio_output'] ?: 1) : 1; ?>">
        <td>
            <input type="text" class="form-control grn-prod-input" list="product_list_options" placeholder="Type product to search..." value="<?php echo $v('item_name'); ?>" onchange="syncProductSelection(this)">
            <input type="hidden" name="product_id[]" value="<?php echo $v('product_id'); ?>">
        </td>
        <td><input type="text" class="form-control grn-item-size" readonly value="<?php echo $v('size_name'); ?>"></td>
        <td><input type="text" class="form-control grn-item-color" readonly value="<?php echo $v('color_name'); ?>"></td>
        <td><input type="text" class="form-control grn-item-unit" readonly value="<?php echo $v('unit_name'); ?>"></td>
        <td><input type="text" class="form-control grn-current-stock" style="text-align:right;" readonly value="0.00"></td>
        <td><input type="number" name="box_in[]" class="form-control grn-box" value="<?php echo $it ? (int)$it['box_in'] : ''; ?>"></td>
        <td><input type="number" name="pcs_in[]" class="form-control grn-pcs" value="<?php echo $it ? (int)$it['pcs_in'] : ''; ?>"></td>
        <td><input type="number" name="qty_in[]" class="form-control grn-qty" step="any" value="<?php echo $qty; ?>"></td>
        <td><input type="number" class="form-control grn-rate" step="any" readonly style="background:#f5f5f5;" value="<?php echo $rate; ?>" title="Rate = Amount / Actual QTY (auto)"></td>
        <td><input type="number" name="bill_qty[]" class="form-control grn-bill-qty" step="any" style="background:#fffde7;" value="<?php echo $bq; ?>"></td>
        <td><input type="number" name="bill_rate[]" class="form-control grn-bill-rate" step="any" style="background:#fffde7;" value="<?php echo $br; ?>"></td>
        <td><input type="number" class="form-control grn-amount" step="any" readonly style="font-weight:bold;" value="<?php echo $amt; ?>"></td>
        <td style="text-align:center;"><button type="button" class="btn-action" style="background:#dc3545;color:#fff;padding:4px 8px;border:none;" onclick="removeThisRow(this)">-</button></td>
    </tr>
    <?php
}

// ---------- তালিকা ----------
$rows = []; $sum_total = 0; $sum_paid = 0; $sum_due = 0;
if (!$show_form) {
    $res = mysqli_query($conn, "SELECT g.id, g.grn_no, g.grn_date, g.branch_name, g.labor_charge, g.discount_amount, g.payment_amount, s.supplier_name, IFNULL(t.total,0) AS items_total
        FROM erp_purchase_grn g
        LEFT JOIN erp_suppliers s ON s.id = g.supplier_id
        LEFT JOIN (SELECT voucher_no, SUM(sqft_in * rate) AS total FROM erp_stock_ledger WHERE voucher_type='PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
        WHERE 1=1" . branch_sql('g.branch_name') . "
        ORDER BY g.id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $total = (float)$r['items_total'] + (float)$r['labor_charge'] - (float)$r['discount_amount'];
        $paid = (float)$r['payment_amount']; $due = $total - $paid;
        $rows[] = ['id' => (int)$r['id'], 'no' => $r['grn_no'], 'date' => $r['grn_date'], 'sup' => $r['supplier_name'] ?? 'N/A', 'branch' => $r['branch_name'] ?? '-', 'total' => $total, 'paid' => $paid, 'due' => $due];
        $sum_total += $total; $sum_paid += $paid; $sum_due += $due;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase GRN (Factory) - Uttara Builders ERP</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px; }
    .btn-action:hover { background: #f0f0f0; }
    .btn-print { border: none; background: #2b579a; color: #fff; }
    .form-panel { background: #fff; border: 1px solid #dddd3d; border-radius: 4px; }
    .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #e3e34a; }
    .panel-body { padding: 15px; }
    .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 15px; padding: 10px; }
    .form-group { display: flex; flex-direction: column; gap: 3px; }
    .form-group label { font-size: 13px; font-weight: bold; color: #333; }
    .form-control { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #ccdc39; margin-top: 10px; }
    .grid-table th { background: #cbd5e1; padding: 6px; text-align: left; border: 1px solid #ccdc39; }
    .grid-table td { padding: 3px; border: 1px solid #dee2e6; background: #fff; }
    .list-table td { padding: 8px; }
    .list-table tbody tr:hover td { background: #f1f5f9; }
    .list-table tr.selected-row td { background: #e0f2fe !important; }
    .r { text-align: right; }
    .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 15px; margin-bottom: 12px; }
    .summary-wrapper { display: flex; justify-content: flex-end; margin-top: 8px; gap: 20px; }
    .summary-box { width: 390px; background: #fafafa; border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; }
    /* ডান পাশে ফিক্সড সামারি বক্স (Sales Invoice এর মতো) */
    .inv-layout { display: flex; gap: 15px; align-items: flex-start; }
    .inv-left { flex: 1; min-width: 0; }
    .inv-right { width: 400px; flex-shrink: 0; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow-y: auto; overflow-x: hidden; }
    .inv-right .summary-box { width: 100%; box-sizing: border-box; }
    .items-scroll { max-height: calc(100vh - 430px); min-height: 170px; overflow: auto; border: 1px solid #ccdc39; background: #fff; }
    .items-scroll .grid-table { margin-top: 0; border: none; }
    .items-scroll thead th { position: sticky; top: 0; z-index: 2; box-shadow: 0 1px 0 #ccdc39; }
    .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .summary-row label { font-weight: bold; color: #444; font-size: 12px; }
    .summary-row input { width: 120px; text-align: right; }
    .sup-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.4); padding-top: 100px; box-sizing: border-box; }
    .sup-modal-content { background: #fff; margin: auto; padding: 20px; border: 1px solid #cdcd39; width: 400px; border-radius: 5px; }
    input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
</style>
</head>
<body>

<?php if (!$show_form) { ?>
<!-- ================= LIST ================= -->
<div class="control-bar">
    <button class="btn-action" onclick="location.href='<?php echo $self; ?>?action=new'"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New</button>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#0498db;"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#dc3545;"></i> Delete</button>
    <button class="btn-action btn-print" onclick="printSel()"><i class="fa-solid fa-print"></i> Print</button>
</div>
<div class="form-panel">
    <div class="panel-header">PURCHASE GRN LIST (FACTORY MEASUREMENT)</div>
    <div class="panel-body">
        <div class="filter-grid">
            <div class="form-group"><label>GRN No</label><input type="text" id="f_no" class="form-control" oninput="runFilter()" placeholder="Search GRN.."></div>
            <div class="form-group"><label>Supplier</label><input type="text" id="f_sup" class="form-control" oninput="runFilter()" placeholder="Search supplier.."></div>
            <div class="form-group"><label>Date From</label><input type="date" id="f_from" class="form-control" onchange="runFilter()"></div>
            <div class="form-group"><label>Date To</label><input type="date" id="f_to" class="form-control" onchange="runFilter()"></div>
        </div>
        <table class="grid-table list-table" id="grnTable">
            <thead><tr>
                <th style="width:30px;"></th><th style="width:40px;text-align:center;">SL</th><th>GRN No</th><th>Date</th><th>Supplier</th><th>Branch / Warehouse</th>
                <th class="r" style="width:110px;">Total Amount</th><th class="r" style="width:100px;">Paid</th><th class="r" style="width:100px;">Due</th>
            </tr></thead>
            <tbody>
            <?php if ($rows) { $sl = 1; foreach ($rows as $r) { ?>
                <tr class="g-row" style="cursor:pointer;" onclick="selRow(this, <?php echo $r['id']; ?>)"
                    data-no="<?php echo h($r['no']); ?>" data-sup="<?php echo h($r['sup']); ?>" data-date="<?php echo h($r['date']); ?>">
                    <td style="text-align:center;"><input type="checkbox" class="chk" onclick="event.stopPropagation(); selRow(this.closest('tr'), <?php echo $r['id']; ?>)"></td>
                    <td style="text-align:center;" class="sl"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;color:#2e7d32;"><?php echo h($r['no']); ?></td>
                    <td><?php echo $r['date'] ? date('d-M-Y', strtotime($r['date'])) : '-'; ?></td>
                    <td style="color:#4050b3;font-weight:500;"><?php echo h($r['sup']); ?></td>
                    <td><?php echo h($r['branch']); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo number_format($r['total'], 2); ?></td>
                    <td class="r" style="color:#28a745;"><?php echo number_format($r['paid'], 2); ?></td>
                    <td class="r" style="color:#d32f2f;font-weight:bold;"><?php echo number_format($r['due'], 2); ?></td>
                </tr>
            <?php } ?>
                <tr class="total-row"><td colspan="6" style="text-align:center;color:#2e7d32;">Grand Total</td>
                    <td class="r"><?php echo number_format($sum_total, 2); ?></td><td class="r"><?php echo number_format($sum_paid, 2); ?></td><td class="r"><?php echo number_format($sum_due, 2); ?></td></tr>
            <?php } else echo "<tr><td colspan='9' style='text-align:center;padding:15px;color:#777;'>কোনো GRN পাওয়া যায়নি। New চাপুন।</td></tr>"; ?>
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
    document.querySelectorAll('#grnTable tbody tr').forEach(function (t) { t.classList.remove('selected-row'); });
    document.querySelectorAll('.chk').forEach(function (c) { c.checked = false; });
    row.classList.add('selected-row'); var c = row.querySelector('.chk'); if (c) c.checked = true; selId = id;
}
function editSel()  { if (!selId) { alert('একটি GRN সিলেক্ট করুন!'); return; } location.href = '<?php echo $self; ?>?action=edit&id=' + selId; }
function printSel() { if (!selId) { alert('একটি GRN সিলেক্ট করুন!'); return; } window.open('print_grn.php?id=' + selId, '_blank'); }
function delSel()   {
    if (!selId) { alert('একটি GRN সিলেক্ট করুন!'); return; }
    if (confirm('GRN মুছে ফেলতে চান? স্টক ও সাপ্লায়ারের হিসাবও সংশোধিত হবে।')) { document.getElementById('delId').value = selId; document.getElementById('delForm').submit(); }
}
function runFilter() {
    var no = f_no.value.toUpperCase().trim(), su = f_sup.value.toUpperCase().trim(), from = f_from.value, to = f_to.value, sl = 1;
    document.querySelectorAll('.g-row').forEach(function (r) {
        var ok = true, d = r.getAttribute('data-date');
        if (no && r.getAttribute('data-no').toUpperCase().indexOf(no) === -1) ok = false;
        if (su && r.getAttribute('data-sup').toUpperCase().indexOf(su) === -1) ok = false;
        if (from && d < from) ok = false; if (to && d > to) ok = false;
        r.style.display = ok ? '' : 'none'; if (ok) r.querySelector('.sl').textContent = sl++;
    });
}
</script>

<?php } else { ?>
<!-- ================= FORM ================= -->
<datalist id="product_list_options">
<?php while ($p = mysqli_fetch_assoc($products)) { ?>
    <option value="<?php echo h($p['item_name']); ?>" data-id="<?php echo $p['id']; ?>" data-size="<?php echo h($p['size_name']); ?>" data-color="<?php echo h($p['color_name']); ?>" data-unit="<?php echo h($p['unit_name']); ?>" data-boxpcs="<?php echo (float)$p['box_to_pcs']; ?>" data-ratiopcs="<?php echo (float)$p['ratio_pcs']; ?>" data-ratiout="<?php echo (float)$p['ratio_output']; ?>">
<?php } ?>
</datalist>
<datalist id="supplier_list_options">
<?php foreach ($suppliers as $s) { ?>
    <option data-id="<?php echo $s['id']; ?>" value="<?php echo h($s['label']); ?>">
<?php } ?>
</datalist>

<div class="form-panel">
<form method="POST" action="<?php echo $self; ?>" id="grnForm" onsubmit="return validateForm()">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
    <div style="background:#ececef; padding:10px 18px; font-size:15px; font-weight:bold; color:#495057; display:flex; align-items:center; gap:8px;">
        <button type="submit" name="save_grn" value="1" class="btn-action" style="background:#28a745; color:#fff; border:none;"><i class="fa-solid fa-floppy-disk"></i> Save GRN</button>
        <button type="button" class="btn-action" onclick="location.href='<?php echo $self; ?>'"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        <span style="margin-left:14px;">PURCHASE GRN (FACTORY MEASUREMENT) — <?php echo $edit_id ? 'EDIT MODE' : 'NEW ENTRY'; ?></span>
    </div>
    <div class="panel-body">
        <div class="excel-grid">
            <div class="form-group"><label>GRN Number (Auto)</label><input type="text" class="form-control" value="<?php echo h($grn_no_show); ?>" readonly style="font-weight:bold;"></div>
            <div class="form-group"><label>GRN Date</label><input type="date" name="grn_date" class="form-control" required value="<?php echo $edit_data ? h($edit_data['grn_date']) : date('Y-m-d'); ?>"></div>
            <div class="form-group"><label>Branch</label>
                <select name="branch_name" id="branch_sel" onchange="filterWarehouses()" class="form-control" required>
                    <?php foreach ($branches as $b) { ?><option value="<?php echo h($b); ?>" <?php if ($edit_data && $edit_data['branch_name'] == $b) echo 'selected'; ?>><?php echo h($b); ?></option><?php } ?>
                </select></div>
            <div class="form-group"><label>Warehouse / Godown</label>
                <select name="warehouse_id" id="warehouse_sel" class="form-control" required>
                    <?php foreach ($warehouses as $w) { ?><option value="<?php echo (int)$w['id']; ?>" data-branch="<?php echo htmlspecialchars($w['branch_name']); ?>" <?php if ($edit_data && (int)($edit_data['warehouse_id'] ?? 1) === (int)$w['id']) echo 'selected'; ?>><?php echo htmlspecialchars($w['warehouse_name']); ?></option><?php } ?>
                </select></div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Supplier (Search Box)</label>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="text" id="supplier_search" class="form-control" list="supplier_list_options" placeholder="Type supplier name to search..." onchange="syncSupplierSelection()" style="flex:1;" value="<?php echo h($sup_label_now); ?>">
                    <input type="hidden" name="supplier_id" id="hidden_supplier_id" value="<?php echo $edit_data ? (int)$edit_data['supplier_id'] : ''; ?>">
                    <button type="button" class="btn-action" style="background:#28a745; color:#fff; height:32px; font-size:18px; border:none;" onclick="openSupplierModal()">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>Factory Factor <span style="font-weight:normal;color:#777;">(Bill Qty = Actual × Factor)</span></label>
                <input type="number" id="factor" class="form-control" step="any" value="<?php echo DEFAULT_FACTOR; ?>" style="background:#fffde7; font-weight:bold;" title="Local measurement e sob row korte 1 dilei hobe">
            </div>
        </div>

        <div class="inv-layout"><div class="inv-left">
        <label style="font-size:12px; font-weight:bold; color:#2e7d32; margin-top:15px; display:block;"><i class="fa-solid fa-boxes-stacked"></i> Purchase Item Details</label>
        <div class="items-scroll">
        <table class="grid-table" id="grnItemsEntryTable">
            <thead><tr>
                <th>Select Product Name</th><th style="width:70px;">Size</th><th style="width:70px;">Color</th><th style="width:50px;">Unit</th>
                <th style="width:80px; text-align:right;">Current Stock</th><th style="width:60px;">Box</th><th style="width:60px;">Pcs</th>
                <th style="width:85px;">Actual QTY</th><th style="width:80px;">Rate</th>
                <th style="width:85px; background:#fff59d;">Bill Qty</th><th style="width:85px; background:#fff59d;">Bill Rate</th>
                <th style="width:95px;">Amount</th><th style="width:40px;">Act</th>
            </tr></thead>
            <tbody>
                <?php if ($edit_items) { foreach ($edit_items as $it) render_row($it); } else { render_row(); } ?>
            </tbody>
        </table>
        </div>
        <div style="margin-top:8px;"><button type="button" class="btn-action" style="background:#28a745; color:#fff;" onclick="addNewProductRow()"><i class="fa-solid fa-plus"></i> Add Row</button></div>

        <div class="form-group" style="margin-top:10px;">
            <label>Remarks / Internal Note</label>
            <textarea name="remarks" class="form-control" rows="5" placeholder="Narration here..."><?php echo $edit_data ? h($edit_data['remarks']) : ''; ?></textarea>
        </div>
        </div><!-- /inv-left -->
        <div class="inv-right">
            <div class="summary-box">
                <div class="summary-row"><label>Total Amount (Bill)</label><input type="number" id="sm_total_amount" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Labor / Other Charge (+)</label><input type="number" name="labor_charge" id="sm_labor" class="form-control" step="any" value="<?php echo $edit_data ? h($edit_data['labor_charge']) : '0'; ?>"></div>
                <div class="summary-row"><label>Discount (-)</label>
                    <div style="display:flex; gap:8px;">
                        <input type="number" id="sm_discount_percent" class="form-control" style="width:50px; text-align:center;" placeholder="%" step="any">
                        <input type="number" name="discount_amount" id="sm_discount" class="form-control" step="any" value="<?php echo $edit_data ? h($edit_data['discount_amount']) : '0'; ?>">
                    </div></div>
                <div class="summary-row" style="border-top:1px dashed #ccc; padding-top:6px;"><label>Gross Total</label><input type="number" id="sm_gross" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Previous Balance (পাওনা)</label><input type="number" id="sm_prev_balance" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Payment Amount (-)</label><input type="number" id="sm_payment" class="form-control" readonly value="0.00" title="সব পেমেন্ট লাইনের যোগফল"></div>
                <div id="payLines">
                <?php foreach ($pay_rows as $pr) { ?>
                    <div class="pay-line" style="display:flex; gap:6px; margin-bottom:5px; justify-content:flex-end;">
                        <select name="pay_account[]" class="form-control pay-acc" style="width:210px; font-size:11px; padding:4px;" title="Cash Type">
                            <?php if (!$accounts) { ?><option value="">-- No Account --</option><?php } ?>
                            <?php foreach ($accounts as $a) { ?><option value="<?php echo $a['id']; ?>" <?php if ($a['id'] == $pr['acc']) echo 'selected'; ?>><?php echo h($a['label']); ?></option><?php } ?>
                        </select>
                        <input type="number" name="pay_amount[]" class="form-control pay-amt" step="any" style="width:110px; text-align:right;" placeholder="Amount" value="<?php echo $pr['amt'] !== '' ? number_format($pr['amt'], 2, '.', '') : ''; ?>">
                        <button type="button" style="background:#dc3545; color:#fff; border:none; border-radius:4px; padding:2px 9px; cursor:pointer;" onclick="removePayLine(this)">-</button>
                    </div>
                <?php } ?>
                </div>
                <div style="text-align:right; margin-bottom:6px;"><button type="button" class="btn-action" style="background:#28a745; color:#fff; border:none; font-size:11px; padding:3px 10px;" onclick="addPayLine()"><i class="fa-solid fa-plus"></i> Add Payment Type</button></div>
                <div class="summary-row" style="border-top:2px double #333; padding-top:6px;"><label style="color:#d32f2f;">Net Balance (বাকি পাওনা)</label><input type="number" id="sm_net" class="form-control" style="font-weight:bold; color:#d32f2f;" readonly value="0.00"></div>
            </div>
        </div><!-- /inv-right --></div><!-- /inv-layout -->
    </div>
</form>
</div>

<div id="supplierModal" class="sup-modal">
    <div class="sup-modal-content">
        <div style="font-size:13px; font-weight:bold; color:#2e7d32; margin-bottom:12px;"><i class="fa-solid fa-user-plus"></i> Quick Add New Supplier</div>
        <div class="form-group"><label>Supplier Name</label><input type="text" id="m_name" class="form-control"></div>
        <div class="form-group" style="margin-top:5px;"><label>Mobile No</label><input type="text" id="m_mobile" class="form-control"></div>
        <div class="form-group" style="margin-top:5px;"><label>Address</label><input type="text" id="m_address" class="form-control"></div>
        <div style="margin-top:10px; display:flex; gap:5px; justify-content:flex-end;">
            <button type="button" class="btn-action" onclick="submitQuickSupplier()">Save</button>
            <button type="button" class="btn-action" onclick="closeSupplierModal()">Close</button>
        </div>
    </div>
</div>

<script>
var EDIT_ID = <?php echo (int)$edit_id; ?>;
function num(v) { return parseFloat(v) || 0; }
function post(data) {
    var fd = new FormData(); for (var k in data) fd.append(k, data[k]);
    return fetch('<?php echo $self; ?>', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
}

// ---- ওয়্যারহাউজ: Branch অনুযায়ী ফিল্টার ----
function filterWarehouses() {
    var br = document.getElementById('branch_sel').value, sel = document.getElementById('warehouse_sel'), first = null, cur = sel.value, curOk = false;
    Array.prototype.forEach.call(sel.options, function (o) {
        var ok = o.getAttribute('data-branch') === br;
        o.hidden = !ok; o.disabled = !ok;
        if (ok && first === null) first = o;
        if (ok && o.value === cur) curOk = true;
    });
    if (!curOk) sel.value = first ? first.value : '';
}
window.addEventListener('load', filterWarehouses);

// ---- সাপ্লায়ার ----
function syncSupplierSelection() {
    var val = document.getElementById('supplier_search').value;
    var opts = document.getElementById('supplier_list_options').options;
    document.getElementById('hidden_supplier_id').value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { document.getElementById('hidden_supplier_id').value = opts[i].getAttribute('data-id'); break; }
    }
    loadPrevBalance();
}
function loadPrevBalance() {
    var sid = document.getElementById('hidden_supplier_id').value;
    if (!sid) { document.getElementById('sm_prev_balance').value = '0.00'; calculateSummary(); return; }
    post({ action: 'get_prev_balance', supplier_id: sid, exclude_id: EDIT_ID }).then(function (d) {
        if (d.status === 'success') { document.getElementById('sm_prev_balance').value = d.balance; calculateSummary(); }
    });
}
function openSupplierModal() { document.getElementById('supplierModal').style.display = 'block'; }
function closeSupplierModal() { document.getElementById('supplierModal').style.display = 'none'; }
function submitQuickSupplier() {
    var name = m_name.value.trim(), mobile = m_mobile.value.trim(), addr = m_address.value.trim();
    if (!name || !mobile) { alert('নাম ও মোবাইল নম্বর আবশ্যক!'); return; }
    post({ action: 'save_supplier', supplier_name: name, mobile_no: mobile, address: addr }).then(function (d) {
        if (d.status !== 'success') { alert('সাপ্লায়ার সেভ হয়নি!'); return; }
        var label = name + ' [ ' + (addr ? addr + ' | ' : '') + mobile + ' ]';
        var opt = document.createElement('option'); opt.value = label; opt.setAttribute('data-id', d.id);
        document.getElementById('supplier_list_options').appendChild(opt);
                document.getElementById('supplier_search').value = label;
        document.getElementById('hidden_supplier_id').value = d.id;
        m_name.value = m_mobile.value = m_address.value = '';
        closeSupplierModal(); loadPrevBalance();
    }).catch(function () { alert('সাপ্লায়ার সেভ হয়নি!'); });
}

// ---- প্রোডাক্ট ----
function syncProductSelection(input) {
    var row = input.closest('tr'), val = input.value.trim(), opts = document.getElementById('product_list_options').options;
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value !== val) continue;
        var o = opts[i], pid = o.getAttribute('data-id');
        row.querySelector("input[name='product_id[]']").value = pid;
        row.querySelector('.grn-item-size').value = o.getAttribute('data-size') || '';
        row.querySelector('.grn-item-color').value = o.getAttribute('data-color') || '';
        row.querySelector('.grn-item-unit').value = o.getAttribute('data-unit') || '';
        row.setAttribute('data-boxpcs', num(o.getAttribute('data-boxpcs')) || 1);
        row.setAttribute('data-ratiopcs', num(o.getAttribute('data-ratiopcs')) || 1);
        row.setAttribute('data-ratiout', num(o.getAttribute('data-ratiout')) || 1);
        loadRowStock(row, pid);
        calculateQtyFromBox(row.querySelector('.grn-box'));
        return;
    }
}
function loadRowStock(row, pid) {
    post({ action: 'get_current_stock', product_id: pid }).then(function (d) {
        if (d.status === 'success') row.querySelector('.grn-current-stock').value = d.stock;
    }).catch(function () { row.querySelector('.grn-current-stock').value = '0.00'; });
}

// ---- বক্স/পিস/Actual QTY ----
function ratios(row) {
    return { bp: parseInt(row.getAttribute('data-boxpcs')) || 1, rp: parseFloat(row.getAttribute('data-ratiopcs')) || 1, ro: parseFloat(row.getAttribute('data-ratiout')) || 1 };
}
function calculateQtyFromBox(el) {
    var row = el.closest('tr'), r = ratios(row);
    var totalPcs = (parseInt(row.querySelector('.grn-box').value) || 0) * r.bp + (parseInt(row.querySelector('.grn-pcs').value) || 0);
    row.querySelector('.grn-qty').value = (totalPcs * (r.ro / r.rp)).toFixed(2);
    recalcRow(row, true);
}
function calculateBoxFromQty(el) {
    var row = el.closest('tr'), r = ratios(row);
    var qty = num(row.querySelector('.grn-qty').value);
    var totalPcs = Math.round(qty / (r.ro / r.rp));
    row.querySelector('.grn-box').value = Math.floor(totalPcs / r.bp);
    row.querySelector('.grn-pcs').value = totalPcs % r.bp;
    row.querySelector('.grn-qty').value = (totalPcs * (r.ro / r.rp)).toFixed(2);
    recalcRow(row, true);
}

// ---- Row হিসাব: Bill Qty (auto) -> Amount = Bill Qty x Bill Rate -> Rate = Amount / Actual QTY ----
function recalcRow(row, fromActual) {
    var q = num(row.querySelector('.grn-qty').value);
    var f = num(document.getElementById('factor').value) || 1;
    if (fromActual && row.getAttribute('data-manual') !== '1') {
        row.querySelector('.grn-bill-qty').value = q > 0 ? (q * f).toFixed(2) : '';
    }
    var amt = num(row.querySelector('.grn-bill-qty').value) * num(row.querySelector('.grn-bill-rate').value);
    row.querySelector('.grn-amount').value = amt.toFixed(2);
    row.querySelector('.grn-rate').value = q > 0 ? (amt / q).toFixed(4) : '';
    calculateTotalAmountAllRows();
}
function recalcAllFromFactor() {
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function (r) { recalcRow(r, true); });
}

// ---- সামারি ----
function calculateTotalAmountAllRows() {
    var t = 0;
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function (r) { t += num(r.querySelector('.grn-amount').value); });
    document.getElementById('sm_total_amount').value = t.toFixed(2);
    calculateSummary();
}
function calculateSummary() {
    var gross = num(sm_total_amount.value) + num(sm_labor.value) - num(sm_discount.value);
    sm_gross.value = gross.toFixed(2);
    sm_net.value = (gross + num(sm_prev_balance.value) - num(sm_payment.value)).toFixed(2);
}
function discFromPercent() { sm_discount.value = (num(sm_total_amount.value) * num(sm_discount_percent.value) / 100).toFixed(2); calculateSummary(); }
function percentFromDisc() { var t = num(sm_total_amount.value); sm_discount_percent.value = t > 0 ? (num(sm_discount.value) * 100 / t).toFixed(2) : '0.00'; calculateSummary(); }

// ---- পেমেন্ট লাইন ----
function recalcPayment() {
    var t = 0;
    document.querySelectorAll('.pay-amt').forEach(function (i) { t += num(i.value); });
    sm_payment.value = t.toFixed(2); calculateSummary();
}
var PAY_DUP_MSG = 'একই ক্যাশ/অ্যাকাউন্ট একাধিকবার দেওয়া যাবে না — ওই লাইনেই পরিমাণ বাড়ান!';
document.addEventListener('change', function(e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains('pay-acc') || !t.value) return;
    var dup = false;
    document.querySelectorAll('.pay-acc').forEach(function(s) { if (s !== t && s.value === t.value) dup = true; });
    if (dup) { alert(PAY_DUP_MSG); t.value = ''; }
});
function addPayLine() {
    var used = {}, free = '';
    document.querySelectorAll('.pay-acc').forEach(function(s) { used[s.value] = 1; });
    Array.prototype.forEach.call(document.querySelector('.pay-acc').options, function(o) { if (!free && o.value && !used[o.value]) free = o.value; });
    if (!free) { alert('সব ক্যাশ/অ্যাকাউন্টই ইতিমধ্যে ব্যবহার হয়েছে!'); return; }
    _addPayLineRaw();
    var l = document.querySelectorAll('.pay-acc'); l[l.length - 1].value = free;
}
function _addPayLineRaw() {
    var box = document.getElementById('payLines'), n = box.querySelector('.pay-line').cloneNode(true);
    var rest = num(sm_gross.value) - num(sm_payment.value);
    n.querySelector('.pay-amt').value = rest > 0 ? rest.toFixed(2) : '';
    box.appendChild(n); recalcPayment();
}
function removePayLine(btn) {
    var box = document.getElementById('payLines');
    if (box.querySelectorAll('.pay-line').length > 1) btn.closest('.pay-line').remove();
    else btn.closest('.pay-line').querySelector('.pay-amt').value = '';
    recalcPayment();
}

// ---- রো ----
function addNewProductRow() {
    var tbody = document.querySelector('#grnItemsEntryTable tbody'), row = tbody.querySelector('tr').cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    row.querySelector('.grn-current-stock').value = '0.00';
    row.setAttribute('data-manual', '0');
    ['data-boxpcs', 'data-ratiopcs', 'data-ratiout'].forEach(function (a) { row.setAttribute(a, '1'); });
    tbody.appendChild(row);
}
function removeThisRow(btn) {
    if (document.querySelectorAll('#grnItemsEntryTable tbody tr').length > 1) { btn.closest('tr').remove(); calculateTotalAmountAllRows(); }
    else alert('কমপক্ষে একটি রো থাকতে হবে!');
}
function validateForm() {
    if (!document.getElementById('hidden_supplier_id').value) { alert('অনুগ্রহ করে তালিকা থেকে একটি সাপ্লায়ার নির্বাচন করুন!'); return false; }
    var ok = false, billOk = true;
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function (r) {
        if (r.querySelector("input[name='product_id[]']").value && num(r.querySelector('.grn-qty').value) > 0) {
            ok = true;
            if (num(r.querySelector('.grn-bill-qty').value) <= 0) billOk = false;
        }
    });
    if (!ok) { alert('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!'); return false; }
    if (!billOk) { alert('প্রতিটি পণ্যের Bill Qty দিন!'); return false; }
    var payOk = true;
    document.querySelectorAll('.pay-line').forEach(function (l) {
        if (num(l.querySelector('.pay-amt').value) > 0 && !l.querySelector('.pay-acc').value) payOk = false;
    });
    if (!payOk) { alert('পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!'); return false; }
    var seenAcc = {}, dupAcc = false;
    document.querySelectorAll('.pay-line').forEach(function(l) {
        var a = l.querySelector('.pay-acc').value;
        if (a && (parseFloat(l.querySelector('.pay-amt').value) || 0) > 0) { if (seenAcc[a]) dupAcc = true; seenAcc[a] = 1; }
    });
    if (dupAcc) { alert(PAY_DUP_MSG); return false; }
    return true;
}

// ---- ইভেন্ট ----
document.addEventListener('change', function (e) {
    var c = e.target.classList; if (!c) return;
    if (c.contains('grn-box') || c.contains('grn-pcs')) calculateQtyFromBox(e.target);
    else if (c.contains('grn-qty')) calculateBoxFromQty(e.target);
    else if (c.contains('grn-bill-qty')) {
        var row = e.target.closest('tr');
        if (e.target.value.trim() === '') { row.setAttribute('data-manual', '0'); recalcRow(row, true); }
    }
}, true);
document.addEventListener('input', function (e) {
    var c = e.target.classList, id = e.target.id;
    if (c && c.contains('grn-bill-qty')) { e.target.closest('tr').setAttribute('data-manual', '1'); recalcRow(e.target.closest('tr'), false); }
    else if (c && c.contains('grn-bill-rate')) recalcRow(e.target.closest('tr'), false);
    else if (c && c.contains('pay-amt')) recalcPayment();
    else if (id === 'factor') recalcAllFromFactor();
    else if (id === 'sm_discount_percent') discFromPercent();
    else if (id === 'sm_discount') percentFromDisc();
    else if (id === 'sm_labor') calculateSummary();
}, true);
document.addEventListener('focus', function (e) {
    if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.type === 'text') && !e.target.readOnly) {
        setTimeout(function () { e.target.select(); }, 50);
    }
}, true);

window.addEventListener('load', function () {
    recalcPayment();
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function (row) {
        var pid = row.querySelector("input[name='product_id[]']").value;
        if (pid) loadRowStock(row, pid);
        recalcRow(row, false);
    });
    calculateTotalAmountAllRows();
    percentFromDisc();
    loadPrevBalance();
});
</script>
<?php } ?>
</body>
</html>