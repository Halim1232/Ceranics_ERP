<?php
// Sales/sales_return.php -- Sales Return: List + New + Edit + Delete
// Shorto : shudhu DELIVERED mal-i return hobe (challan qty - ager return)
// Stock  : Good hole erp_stock_ledger e PLUS (voucher_type='SALES_RETURN', rate = last purchase rate). Damaged hole stock e dhukbe na.
// Balance: Net = Previous Balance - Return Amount + Refund
// Refund : erp_fund_ledger (ref_type='SALE_RET', txn_type='OUT')
// Journal: SRETURN  Dr Sales Return(4003, na thakle 4001) / Cr 1101 Receivable(customer)
//          SRCOGS   Dr 1201 Inventory / Cr 5001 COGS  (shudhu Good mal)
//          Refund   -> acc_sync_fund_journal(): Dr 1101 / Cr Cash|Bank|MFS
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$is_ajax = isset($_POST['action']);
if ($is_ajax) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'unauthorized']); exit; }
    include_once('../access.php'); require_page_access('sales/sales_return.php', true);
} else {
    include('../auth.php'); require_page_access();
}
include('../config.php');
include_once('../balance_functions.php');   // customer_prev_balance()

// discount_amount column (age na thakle auto toiri)
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM erp_sales_return LIKE 'discount_amount'");
    if ($cc && mysqli_num_rows($cc) == 0) mysqli_query($conn, "ALTER TABLE erp_sales_return ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_amount");
} catch (Throwable $e) { /* table na thakle ignore */ }
// warehouse_id column (age na thakle auto toiri)
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM erp_sales_return LIKE 'warehouse_id'");
    if ($cc && mysqli_num_rows($cc) == 0) mysqli_query($conn, "ALTER TABLE erp_sales_return ADD COLUMN warehouse_id INT NOT NULL DEFAULT 1 AFTER branch_name");
} catch (Throwable $e) { }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// প্রাইমারি কী কলাম অটো-ডিটেক্ট (id / invoice_id)
$idf = "id";
$mc = mysqli_query($conn, "SHOW COLUMNS FROM sales_invoice_master");
while ($c = mysqli_fetch_assoc($mc)) { if ($c['Key'] == 'PRI') { $idf = $c['Field']; break; } }

const SR_STOCK = 'SALES_RETURN';
const SR_FUND  = 'SALE_RET';

function product_stock($conn, $pid) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in - sqft_out),0) AS s FROM erp_stock_ledger WHERE product_id=" . (int)$pid));
    return (float)$r['s'];
}
// ferot mal stock e dhukbe last purchase rate e (na thakle product er cost_price)
function last_cost_rate($conn, $pid) {
    $pid = (int)$pid;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT rate FROM erp_stock_ledger WHERE product_id=$pid AND voucher_type='PURCHASE' AND sqft_in > 0 ORDER BY id DESC LIMIT 1"));
    if ($r && (float)$r['rate'] > 0) return (float)$r['rate'];
    $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT cost_price FROM erp_products WHERE id=$pid"));
    return (float)($p['cost_price'] ?? 0);
}
// Ei invoice er product-wise: sold / delivered / ager return / ebar return-jogyo
function returnable_items($conn, $inv_id, $inv_no, $exclude = 0) {
    $inv_id = (int)$inv_id; $exclude = (int)$exclude;
    $no = mysqli_real_escape_string($conn, $inv_no);
    $items = [];
    $q = mysqli_query($conn, "SELECT d.product_id, SUM(d.total_qty) AS sold,
            SUM(d.total_qty * d.rate) / NULLIF(SUM(d.total_qty), 0) AS rate,
            p.item_name, p.size_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output
        FROM sales_invoice_details d JOIN erp_products p ON p.id = d.product_id
        WHERE d.invoice_id = $inv_id
        GROUP BY d.product_id, p.item_name, p.size_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output");
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $items[(int)$r['product_id']] = [
            'product_id' => (int)$r['product_id'], 'item_name' => $r['item_name'], 'size_name' => $r['size_name'] ?? '', 'unit_name' => $r['unit_name'] ?? '',
            'box_to_pcs' => (float)$r['box_to_pcs'] ?: 1, 'ratio_pcs' => (float)$r['ratio_pcs'] ?: 1, 'ratio_output' => (float)$r['ratio_output'] ?: 1,
            'sold' => (float)$r['sold'], 'rate' => round((float)$r['rate'], 4), 'delivered' => 0, 'returned' => 0, 'returnable' => 0,
        ];
    }
    $q = mysqli_query($conn, "SELECT dd.product_id, SUM(dd.total_qty) AS q
        FROM erp_delivery_challan_details dd JOIN erp_delivery_challan_master dm ON dm.id = dd.challan_id
        WHERE dm.invoice_no = '$no' GROUP BY dd.product_id");
    while ($q && $r = mysqli_fetch_assoc($q)) { if (isset($items[(int)$r['product_id']])) $items[(int)$r['product_id']]['delivered'] = (float)$r['q']; }
    $ex = $exclude > 0 ? "AND r.id <> $exclude" : "";
    $q = mysqli_query($conn, "SELECT rd.product_id, SUM(rd.return_qty) AS q
        FROM erp_sales_return_details rd JOIN erp_sales_return r ON r.id = rd.return_id
        WHERE r.invoice_no = '$no' $ex GROUP BY rd.product_id");
    while ($q && $r = mysqli_fetch_assoc($q)) { if (isset($items[(int)$r['product_id']])) $items[(int)$r['product_id']]['returned'] = (float)$r['q']; }
    foreach ($items as $pid => &$it) {
        $it['returnable'] = round(max(0, $it['delivered'] - $it['returned']), 4);
        if ($it['delivered'] <= 0) unset($items[$pid]);   // delivery hoyni = return hobe na
    }
    unset($it);
    return $items;
}
function grp_sum($conn, $sql) { $o = []; $q = mysqli_query($conn, $sql); while ($q && $r = mysqli_fetch_assoc($q)) $o[(int)$r['pid']] = (float)$r['q']; return $o; }

// ---------- AJAX ----------
if ($is_ajax) {
    $act = $_POST['action'];
    $exclude = (int)($_POST['exclude_id'] ?? 0);
    if ($act === 'get_prev_balance') {
        $b = customer_prev_balance($conn, (int)$_POST['customer_id'], $idf, 0, $exclude);
        echo json_encode(['status' => 'success', 'balance' => number_format($b, 2, '.', '')]); exit;
    }
    if ($act === 'get_invoices') {
        $cid = (int)$_POST['customer_id']; $out = [];
        $q = mysqli_query($conn, "SELECT $idf AS iid, invoice_no AS inv_no, invoice_date AS inv_date FROM sales_invoice_master WHERE customer_id=$cid" . branch_sql('branch_name') . " ORDER BY $idf DESC");
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $its = returnable_items($conn, $r['iid'], $r['inv_no'], $exclude);
            $rem = 0; foreach ($its as $it) $rem += $it['returnable'];
            if ($rem > 0.004) $out[] = ['id' => (int)$r['iid'], 'no' => $r['inv_no'], 'date' => $r['inv_date']];
        }
        echo json_encode(['status' => 'success', 'invoices' => $out]); exit;
    }
    if ($act === 'get_items') {
        $iid = (int)$_POST['invoice_id'];
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no AS inv_no FROM sales_invoice_master WHERE $idf=$iid" . branch_sql('branch_name')));
        if (!$m) { echo json_encode(['status' => 'error']); exit; }
        $wid = 0;   // sesh challan er warehouse = ferot asha malr default warehouse
        try {
            $cw = mysqli_fetch_assoc(mysqli_query($conn, "SELECT warehouse_id FROM erp_delivery_challan_master WHERE invoice_no='" . mysqli_real_escape_string($conn, $m['inv_no']) . "' ORDER BY id DESC LIMIT 1"));
            $wid = (int)($cw['warehouse_id'] ?? 0);
        } catch (Throwable $e) { }
        echo json_encode(['status' => 'success', 'warehouse_id' => $wid, 'items' => array_values(returnable_items($conn, $iid, $m['inv_no'], $exclude))]); exit;
    }
    echo json_encode(['status' => 'error']); exit;
}

$self = 'sales_return.php';
$action = $_GET['action'] ?? 'list';
$fail = function ($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };

// ---------- POST: DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');
    $id = (int)$_POST['id'];
    $g = mysqli_fetch_assoc(mysqli_query($conn, "SELECT return_no FROM erp_sales_return WHERE id=$id" . branch_sql('branch_name')));
    if (!$g) $fail('Sales Return পাওয়া যায়নি।');
    $no = $g['return_no']; $noe = mysqli_real_escape_string($conn, $no);

    // মুছলে ফেরত আসা মাল স্টক থেকে কমবে -- মাইনাসে যাবে কিনা
    $short = [];
    foreach (grp_sum($conn, "SELECT product_id AS pid, SUM(sqft_in) AS q FROM erp_stock_ledger WHERE voucher_no='$noe' AND voucher_type='" . SR_STOCK . "' GROUP BY product_id") as $pid => $inq) {
        $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, negative_delivery FROM erp_products WHERE id=$pid"));
        $after = product_stock($conn, $pid) - $inq;
        if ($p && !$p['negative_delivery'] && $after < -0.01) $short[] = $p['item_name'] . " (মুছলে স্টক হবে " . number_format($after, 2) . ")";
    }
    if ($short) $fail("এই Return মুছলে স্টক মাইনাসে যাবে, কারণ ফেরত আসা মাল আবার বিক্রি/ডেলিভারি হয়েছে:\n" . implode("\n", $short));

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$noe' AND voucher_type='" . SR_STOCK . "'");
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='" . SR_FUND . "' AND ref_no='$noe'");
        mysqli_query($conn, "DELETE FROM erp_sales_return_details WHERE return_id=$id");
        mysqli_query($conn, "DELETE FROM erp_sales_return WHERE id=$id");
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); error_log($e->getMessage()); $fail('ডিলিট ব্যর্থ হয়েছে।'); }

    try {
        if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
        include_once('../Accounts/accounts_lib.php');
        delete_journal($conn, 'SRETURN', $no);
        delete_journal($conn, 'SRCOGS', $no);
        acc_sync_fund_journal($conn);
    } catch (Throwable $e) { /* journal clean-up fail korleo return delete thik thakbe */ }
    echo "<script>alert('Sales Return মুছে ফেলা হয়েছে।'); window.location.href='$self';</script>"; exit;
}

// ---------- POST: SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');

    $edit_id     = (int)($_POST['edit_id'] ?? 0);
    $ret_date    = trim($_POST['return_date'] ?? '');
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $invoice_id  = (int)($_POST['invoice_id'] ?? 0);
    $remarks     = trim($_POST['remarks'] ?? '');
    if ($ret_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ret_date)) $fail('সঠিক তারিখ দিন!');
    if ($customer_id <= 0) $fail('অনুগ্রহ করে তালিকা থেকে একটি কাস্টমার নির্বাচন করুন!');

    $inv = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no AS inv_no, branch_name AS br FROM sales_invoice_master WHERE $idf=$invoice_id AND customer_id=$customer_id" . branch_sql('branch_name')));
    if (!$inv) $fail('ইনভয়েস পাওয়া যায়নি বা এই কাস্টমারের নয়!');
    $inv_no = $inv['inv_no']; $branch = $inv['br'] ?? '';
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $wchk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM erp_warehouses WHERE id=$warehouse_id"));
    if (!$wchk) $fail('ফেরত আসা মাল কোন ওয়্যারহাউজে জমা হবে তা নির্বাচন করুন!');

    // রিফান্ড লাইন
    $pay_lines = [];
    foreach (($_POST['pay_amount'] ?? []) as $i => $pamt) {
        $pamt = round(floatval($pamt), 2); $pacc = (int)($_POST['pay_account'][$i] ?? 0);
        if ($pamt <= 0) continue;
        if ($pacc <= 0) $fail('টাকা ফেরতের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!');
        $pay_lines[] = ['acc' => $pacc, 'amt' => $pamt];
    }
    $refund = 0; foreach ($pay_lines as $pl) $refund += $pl['amt'];
    $discount = round(floatval($_POST['discount_amount'] ?? 0), 2);
    if ($discount < 0) $discount = 0;

    // আইটেম + return-jogyo qty check (delivery na hole return hobe na)
    $avail = returnable_items($conn, $invoice_id, $inv_no, $edit_id);
    $items = []; $need = []; $good_new = [];
    foreach (($_POST['product_id'] ?? []) as $i => $p) {
        $pid = (int)$p; $qty = round(floatval($_POST['qty_in'][$i] ?? 0), 4);
        if ($pid <= 0 || $qty <= 0) continue;
        if (!isset($avail[$pid])) $fail('একটি পণ্য এই ইনভয়েসের ডেলিভারিতে নেই, Return করা যাবে না!');
        $cond = (($_POST['cond'][$i] ?? 'Good') === 'Damaged') ? 'Damaged' : 'Good';
        $rate = round(floatval($_POST['rate'][$i] ?? 0), 4);
        $items[] = ['pid' => $pid, 'box' => floatval($_POST['box_in'][$i] ?? 0), 'pcs' => floatval($_POST['pcs_in'][$i] ?? 0), 'qty' => $qty,
                    'rate' => $rate, 'amount' => round($qty * $rate, 2), 'cond' => $cond];
        $need[$pid] = ($need[$pid] ?? 0) + $qty;
        if ($cond === 'Good') $good_new[$pid] = ($good_new[$pid] ?? 0) + $qty;
    }
    if (!$items) $fail('কমপক্ষে একটি পণ্য ও Return পরিমাণ দিন!');
    $over = [];
    foreach ($need as $pid => $q) {
        if ($q - $avail[$pid]['returnable'] > 0.01) $over[] = $avail[$pid]['item_name'] . " (Return-যোগ্য: " . number_format($avail[$pid]['returnable'], 2) . ", দিয়েছেন: " . number_format($q, 2) . ")";
    }
    if ($over) $fail("ডেলিভারির চেয়ে বেশি Return করা যাবে না:\n" . implode("\n", $over));

    // এডিটে: আগের Good return কমালে স্টক মাইনাসে যাবে কিনা
    $old_no = '';
    if ($edit_id > 0) {
        $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT return_no FROM erp_sales_return WHERE id=$edit_id" . branch_sql('branch_name')));
        if (!$o) $fail('Sales Return পাওয়া যায়নি!');
        $old_no = $o['return_no'];
        $olde = mysqli_real_escape_string($conn, $old_no);
        $old_good = grp_sum($conn, "SELECT product_id AS pid, SUM(sqft_in) AS q FROM erp_stock_ledger WHERE voucher_no='$olde' AND voucher_type='" . SR_STOCK . "' GROUP BY product_id");
        $short = [];
        foreach ($old_good as $pid => $oq) {
            $nq = $good_new[$pid] ?? 0;
            if ($nq >= $oq) continue;
            $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, negative_delivery FROM erp_products WHERE id=$pid"));
            $after = product_stock($conn, $pid) - $oq + $nq;
            if ($p && !$p['negative_delivery'] && $after < -0.01) $short[] = $p['item_name'] . " (স্টক হবে " . number_format($after, 2) . ")";
        }
        if ($short) $fail("Return কমালে স্টক মাইনাসে যাবে, কারণ ফেরত আসা মাল আবার বিক্রি হয়েছে:\n" . implode("\n", $short));
    }

    // সার্ভারেই হিসাব
    $total = 0; $good_value = 0;
    foreach ($items as $k => $it) {
        $total += $it['amount'];
        $items[$k]['cost'] = ($it['cond'] === 'Good') ? last_cost_rate($conn, $it['pid']) : 0;
        if ($it['cond'] === 'Good') $good_value += round($it['qty'] * $items[$k]['cost'], 2);
    }
    $total = round($total, 2); $good_value = round($good_value, 2);
    if ($discount - $total > 0.01) $fail('Discount, Return Amount এর বেশি হতে পারবে না!');
    $credit = round($total - $discount, 2);   // customer er due theke ja kombe
    $prev  = round(customer_prev_balance($conn, $customer_id, $idf, 0, $edit_id), 2);
    $net   = round($prev - $credit + $refund, 2);

    mysqli_begin_transaction($conn);
    try {
        if ($edit_id > 0) {
            mysqli_query($conn, "SELECT id FROM erp_sales_return WHERE id=$edit_id FOR UPDATE");
            $ret_no = $old_no; $noe = mysqli_real_escape_string($conn, $ret_no);
            $st = $conn->prepare("UPDATE erp_sales_return SET return_date=?, customer_id=?, invoice_id=?, invoice_no=?, branch_name=?, warehouse_id=?, total_amount=?, discount_amount=?, prev_balance=?, refund_amount=?, net_balance=?, remarks=? WHERE id=?");
            $st->bind_param("siissidddddsi", $ret_date, $customer_id, $invoice_id, $inv_no, $branch, $warehouse_id, $total, $discount, $prev, $refund, $net, $remarks, $edit_id);
            $st->execute();
            $rid = $edit_id;
            mysqli_query($conn, "DELETE FROM erp_sales_return_details WHERE return_id=$rid");
            mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$noe' AND voucher_type='" . SR_STOCK . "'");
            mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='" . SR_FUND . "' AND ref_no='$noe'");
        } else {
            $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_sales_return FOR UPDATE"));
            $ret_no = "SR-" . date('y') . str_pad(((int)$m['m']) + 1, 4, "0", STR_PAD_LEFT);
            $st = $conn->prepare("INSERT INTO erp_sales_return (return_no, return_date, customer_id, invoice_id, invoice_no, branch_name, warehouse_id, total_amount, discount_amount, prev_balance, refund_amount, net_balance, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param("ssiissiddddds", $ret_no, $ret_date, $customer_id, $invoice_id, $inv_no, $branch, $warehouse_id, $total, $discount, $prev, $refund, $net, $remarks);
            $st->execute();
            $rid = $conn->insert_id;
        }
        $dl = $conn->prepare("INSERT INTO erp_sales_return_details (return_id, product_id, box_qty, pcs_qty, return_qty, rate, amount, item_condition) VALUES (?,?,?,?,?,?,?,?)");
        $sl = $conn->prepare("INSERT INTO erp_stock_ledger (product_id, warehouse_id, voucher_type, voucher_no, box_in, pcs_in, sqft_in, box_out, pcs_out, sqft_out, rate, total_value) VALUES (?, ?, '" . SR_STOCK . "', ?, ?, ?, ?, 0, 0, 0, ?, ?)");
        foreach ($items as $it) {
            $dl->bind_param("iiddddds", $rid, $it['pid'], $it['box'], $it['pcs'], $it['qty'], $it['rate'], $it['amount'], $it['cond']);
            $dl->execute();
            if ($it['cond'] === 'Good') {   // Damaged mal stock e dhukbe na
                $val = round($it['qty'] * $it['cost'], 2);
                $sl->bind_param("iisddddd", $it['pid'], $warehouse_id, $ret_no, $it['box'], $it['pcs'], $it['qty'], $it['cost'], $val);
                $sl->execute();
            }
        }
        $fl = $conn->prepare("INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, created_by) VALUES (?, ?, 'OUT', ?, '" . SR_FUND . "', ?, ?)");
        $uid = (int)$_SESSION['user_id'];
        foreach ($pay_lines as $pl) {
            $fl->bind_param("sidsi", $ret_date, $pl['acc'], $pl['amt'], $ret_no, $uid);
            $fl->execute();
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn); error_log('Sales return save: ' . $e->getMessage());
        $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।');
    }

    // Accounts auto-posting (commit er pore)
    try {
        if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
        include_once('../Accounts/accounts_lib.php');
        $sr_acc = acc_id($conn, '4003') ? '4003' : '4001';   // 4003 = Sales Return account (na thakle Sales Revenue 4001 debit)
        post_journal($conn, 'SRETURN', $ret_no, $ret_date, "Sales Return $ret_no (Invoice $inv_no)", [
            [$sr_acc, $credit, 0],
            ['1101', 0, $credit, $customer_id]
        ]);
        if ($good_value > 0.004) {
            post_journal($conn, 'SRCOGS', $ret_no, $ret_date, "Return stock $ret_no", [
                ['1201', $good_value, 0],
                ['5001', 0, $good_value]
            ]);
        } else delete_journal($conn, 'SRCOGS', $ret_no);
        acc_sync_fund_journal($conn);
    } catch (Throwable $e) { error_log('Sales return journal: ' . $e->getMessage()); }

    echo "<script>alert('Sales Return $ret_no সফলভাবে সংরক্ষিত হয়েছে।'); window.location.href='$self';</script>"; exit;
}

// ---------- ডাটা লোড ----------
$edit_id   = ($action === 'edit') ? (int)($_GET['id'] ?? 0) : 0;
$show_form = ($action === 'new' || $edit_id > 0);

$customers = []; $edit_data = null; $ret_no_show = ''; $pay_rows = []; $accounts = []; $init = null;

if ($show_form) {
    $q = mysqli_query($conn, "SELECT id, customer_name, phone, address FROM erp_customers ORDER BY customer_name ASC");
    while ($q && $c = mysqli_fetch_assoc($q)) {
        $parts = array_filter([trim($c['address'] ?? ''), trim($c['phone'] ?? '')]);
        $customers[] = ['id' => (int)$c['id'], 'label' => $c['customer_name'] . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '')];
    }
    $warehouses = [];
    $wq = mysqli_query($conn, "SELECT w.id, w.warehouse_name, b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.status='Active'" . branch_wh_sql('w.id') . " ORDER BY b.branch_name, w.warehouse_name");
    while ($wq && $w = mysqli_fetch_assoc($wq)) $warehouses[] = $w;
    $q = mysqli_query($conn, "SELECT id, account_name FROM erp_fund_accounts WHERE status='Active' ORDER BY id ASC");
    while ($q && $a = mysqli_fetch_assoc($q)) $accounts[] = ['id' => (int)$a['id'], 'name' => $a['account_name']];
    $sel_account = 0;
    foreach ($accounts as $a) { if (stripos($a['name'], 'cash in hand') !== false) { $sel_account = $a['id']; break; } }
    if (!$sel_account && $accounts) $sel_account = $accounts[0]['id'];

    $next = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_sales_return"));
    $ret_no_show = "SR-" . date('y') . str_pad(((int)$next['m']) + 1, 4, "0", STR_PAD_LEFT);

    if ($edit_id > 0) {
        $er = mysqli_query($conn, "SELECT * FROM erp_sales_return WHERE id=$edit_id" . branch_sql('branch_name'));
        $edit_data = $er ? mysqli_fetch_assoc($er) : null;
        if (!$edit_data) { echo "<script>window.location.href='$self';</script>"; exit; }
        $ret_no_show = $edit_data['return_no'];
        $noe = mysqli_real_escape_string($conn, $ret_no_show);

        $its = returnable_items($conn, $edit_data['invoice_id'], $edit_data['invoice_no'], $edit_id);
        $dq = mysqli_query($conn, "SELECT product_id, box_qty, pcs_qty, return_qty, rate, item_condition FROM erp_sales_return_details WHERE return_id=$edit_id");
        $saved = [];
        while ($dq && $d = mysqli_fetch_assoc($dq)) $saved[(int)$d['product_id']][] = $d;
        $rows = [];
        foreach ($its as $pid => $it) {
            if (!empty($saved[$pid])) {
                foreach ($saved[$pid] as $d) {
                    $row = $it;
                    $row['s_box'] = (float)$d['box_qty']; $row['s_pcs'] = (float)$d['pcs_qty']; $row['s_qty'] = (float)$d['return_qty'];
                    $row['rate'] = (float)$d['rate']; $row['s_cond'] = $d['item_condition'];
                    $rows[] = $row;
                }
            } else $rows[] = $it;
        }
        $cl = ''; foreach ($customers as $c) if ($c['id'] == $edit_data['customer_id']) $cl = $c['label'];
        $init = ['customer_id' => (int)$edit_data['customer_id'], 'customer_label' => $cl, 'invoice_id' => (int)$edit_data['invoice_id'],
                 'invoice_no' => $edit_data['invoice_no'], 'items' => $rows];

        $s2 = mysqli_query($conn, "SELECT account_id, amount FROM erp_fund_ledger WHERE ref_type='" . SR_FUND . "' AND ref_no='$noe'");
        while ($s2 && $x = mysqli_fetch_assoc($s2)) $pay_rows[] = ['acc' => (int)$x['account_id'], 'amt' => (float)$x['amount']];
    }
    if (!$pay_rows) $pay_rows[] = ['acc' => $sel_account, 'amt' => ''];
}

// ---------- তালিকা ----------
$list = []; $sum_total = 0; $sum_refund = 0; $sum_disc = 0;
if (!$show_form) {
    $res = mysqli_query($conn, "SELECT r.id, r.return_no, r.return_date, r.invoice_no, r.total_amount, r.discount_amount, r.refund_amount, c.customer_name
        FROM erp_sales_return r LEFT JOIN erp_customers c ON c.id = r.customer_id WHERE 1=1" . branch_sql('r.branch_name') . " ORDER BY r.id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $list[] = $r; $sum_total += (float)$r['total_amount']; $sum_refund += (float)$r['refund_amount']; $sum_disc += (float)$r['discount_amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Return - Uttara Builders ERP</title>
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
    .ro { background: #f5f5f5; }
    .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 15px; margin-bottom: 12px; }
    .summary-wrapper { display: flex; justify-content: flex-end; margin-top: 8px; gap: 20px; }
    .summary-box { width: 390px; background: #fafafa; border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; }
    .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .summary-row label { font-weight: bold; color: #444; font-size: 12px; }
    .summary-row input { width: 120px; text-align: right; }
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
    <div class="panel-header">SALES RETURN LIST</div>
    <div class="panel-body">
        <div class="filter-grid">
            <div class="form-group"><label>Return No / Invoice</label><input type="text" id="f_no" class="form-control" oninput="runFilter()" placeholder="Search.."></div>
            <div class="form-group"><label>Customer</label><input type="text" id="f_cust" class="form-control" oninput="runFilter()" placeholder="Search customer.."></div>
            <div class="form-group"><label>Date From</label><input type="date" id="f_from" class="form-control" onchange="runFilter()"></div>
            <div class="form-group"><label>Date To</label><input type="date" id="f_to" class="form-control" onchange="runFilter()"></div>
        </div>
        <table class="grid-table list-table" id="srTable">
            <thead><tr>
                <th style="width:30px;"></th><th style="width:40px;text-align:center;">SL</th><th>Return No</th><th>Date</th><th>Customer</th><th>Invoice Ref</th>
                <th class="r" style="width:110px;">Return Amount</th><th class="r" style="width:90px;">Discount</th><th class="r" style="width:100px;">Refunded</th><th class="r" style="width:110px;">Adjusted to Due</th>
            </tr></thead>
            <tbody>
            <?php if ($list) { $sl = 1; foreach ($list as $r) { $adj = (float)$r['total_amount'] - (float)$r['discount_amount'] - (float)$r['refund_amount']; ?>
                <tr class="g-row" style="cursor:pointer;" onclick="selRow(this, <?php echo (int)$r['id']; ?>)"
                    data-no="<?php echo h($r['return_no'] . ' ' . $r['invoice_no']); ?>" data-cust="<?php echo h($r['customer_name']); ?>" data-date="<?php echo h($r['return_date']); ?>">
                    <td style="text-align:center;"><input type="checkbox" class="chk" onclick="event.stopPropagation(); selRow(this.closest('tr'), <?php echo (int)$r['id']; ?>)"></td>
                    <td style="text-align:center;" class="sl"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;color:#2e7d32;"><?php echo h($r['return_no']); ?></td>
                    <td><?php echo $r['return_date'] ? date('d-M-Y', strtotime($r['return_date'])) : '-'; ?></td>
                    <td style="color:#4050b3;font-weight:500;"><?php echo h($r['customer_name'] ?? 'N/A'); ?></td>
                    <td style="font-weight:bold;color:#2b579a;"><?php echo h($r['invoice_no']); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo number_format($r['total_amount'], 2); ?></td>
                    <td class="r"><?php echo number_format($r['discount_amount'], 2); ?></td>
                    <td class="r" style="color:#28a745;"><?php echo number_format($r['refund_amount'], 2); ?></td>
                    <td class="r" style="color:#d32f2f;font-weight:bold;"><?php echo number_format($adj, 2); ?></td>
                </tr>
            <?php } ?>
                <tr class="total-row"><td colspan="6" style="text-align:center;color:#2e7d32;">Grand Total</td>
                    <td class="r"><?php echo number_format($sum_total, 2); ?></td><td class="r"><?php echo number_format($sum_disc, 2); ?></td><td class="r"><?php echo number_format($sum_refund, 2); ?></td><td class="r"><?php echo number_format($sum_total - $sum_disc - $sum_refund, 2); ?></td></tr>
            <?php } else echo "<tr><td colspan='10' style='text-align:center;padding:15px;color:#777;'>কোনো Sales Return পাওয়া যায়নি। New চাপুন।</td></tr>"; ?>
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
    document.querySelectorAll('#srTable tbody tr').forEach(function (t) { t.classList.remove('selected-row'); });
    document.querySelectorAll('.chk').forEach(function (c) { c.checked = false; });
    row.classList.add('selected-row'); var c = row.querySelector('.chk'); if (c) c.checked = true; selId = id;
}
function editSel()  { if (!selId) { alert('একটি Return সিলেক্ট করুন!'); return; } location.href = '<?php echo $self; ?>?action=edit&id=' + selId; }
function printSel() { if (!selId) { alert('একটি Return সিলেক্ট করুন!'); return; } window.open('print_sales_return.php?id=' + selId, '_blank'); }
function delSel()   {
    if (!selId) { alert('একটি Return সিলেক্ট করুন!'); return; }
    if (confirm('Sales Return মুছে ফেলতে চান? স্টক ও কাস্টমারের হিসাবও সংশোধিত হবে।')) { document.getElementById('delId').value = selId; document.getElementById('delForm').submit(); }
}
function runFilter() {
    var no = f_no.value.toUpperCase().trim(), cu = f_cust.value.toUpperCase().trim(), from = f_from.value, to = f_to.value, sl = 1;
    document.querySelectorAll('.g-row').forEach(function (r) {
        var ok = true, d = r.getAttribute('data-date');
        if (no && r.getAttribute('data-no').toUpperCase().indexOf(no) === -1) ok = false;
        if (cu && r.getAttribute('data-cust').toUpperCase().indexOf(cu) === -1) ok = false;
        if (from && d < from) ok = false; if (to && d > to) ok = false;
        r.style.display = ok ? '' : 'none'; if (ok) r.querySelector('.sl').textContent = sl++;
    });
}
</script>

<?php } else { ?>
<!-- ================= FORM ================= -->
<datalist id="customer_list_options">
<?php foreach ($customers as $c) { ?>
    <option data-id="<?php echo $c['id']; ?>" value="<?php echo h($c['label']); ?>">
<?php } ?>
</datalist>

<div class="form-panel">
<form method="POST" action="<?php echo $self; ?>" id="srForm" onsubmit="return validateForm()">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
    <input type="hidden" name="customer_id" id="hidden_customer_id" value="">
    <input type="hidden" name="invoice_id" id="hidden_invoice_id" value="">
    <div style="background:#ececef; padding:10px 18px; font-size:15px; font-weight:bold; color:#495057; display:flex; justify-content:space-between; align-items:center;">
        <span>SALES RETURN — <?php echo $edit_id ? 'EDIT MODE' : 'NEW ENTRY'; ?></span>
        <span style="display:flex; gap:8px;">
            <button type="submit" name="save_return" value="1" class="btn-action" style="background:#28a745; color:#fff; border:none;"><i class="fa-solid fa-floppy-disk"></i> Save Return</button>
            <button type="button" class="btn-action" onclick="location.href='<?php echo $self; ?>'"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        </span>
    </div>
    <div class="panel-body">
        <div class="excel-grid">
            <div class="form-group"><label>Return Number (Auto)</label><input type="text" class="form-control" value="<?php echo h($ret_no_show); ?>" readonly style="font-weight:bold;"></div>
            <div class="form-group"><label>Return Date</label><input type="date" name="return_date" class="form-control" required value="<?php echo $edit_data ? h($edit_data['return_date']) : date('Y-m-d'); ?>"></div>
            <div class="form-group"><label>Invoice (delivered only)</label>
                <select id="invoice_sel" class="form-control" onchange="onInvoiceChange()"><option value="">-- আগে কাস্টমার নির্বাচন করুন --</option></select></div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Customer (Search Box)</label>
                <input type="text" id="customer_search" class="form-control" list="customer_list_options" placeholder="Type customer name to search..." onchange="syncCustomerSelection()">
            </div>
            <div class="form-group"><label>Return Warehouse (ferot mal jekhane jama hobe)</label>
                <select name="warehouse_id" id="warehouse_sel" class="form-control" required>
                    <?php foreach ($warehouses as $w) { ?><option value="<?php echo (int)$w['id']; ?>" <?php if ($edit_data && (int)($edit_data['warehouse_id'] ?? 1) === (int)$w['id']) echo 'selected'; ?>><?php echo h($w['warehouse_name'] . ' (' . $w['branch_name'] . ')'); ?></option><?php } ?>
                </select></div>
        </div>

        <label style="font-size:12px; font-weight:bold; color:#2e7d32; margin-top:15px; display:block;"><i class="fa-solid fa-rotate-left"></i> Return Item Details</label>
        <table class="grid-table" id="srItemsTable">
            <thead><tr>
                <th style="width:30px;text-align:center;"><input type="checkbox" id="chkAll" onclick="toggleAllRows(this)" title="সব সিলেক্ট / আনসিলেক্ট"></th>
                <th>Product</th><th style="width:70px;">Size</th><th style="width:45px;">Unit</th>
                <th class="r" style="width:75px;">Delivered</th><th class="r" style="width:75px;">Ager Return</th><th class="r" style="width:80px;">Return-jogyo</th>
                <th style="width:60px;">Box</th><th style="width:60px;">Pcs</th><th style="width:85px;">Return QTY</th>
                <th style="width:80px;">Price</th><th style="width:95px;">Amount</th><th style="width:95px;">Condition</th><th style="width:34px;text-align:center;">Act</th>
            </tr></thead>
            <tbody id="srBody"><tr><td colspan="14" style="text-align:center;color:#999;padding:12px;">কাস্টমার ও ইনভয়েস নির্বাচন করুন।</td></tr></tbody>
        </table>

        <div class="summary-wrapper">
            <div class="form-group" style="flex:1; margin-top:10px;">
                <label>Remarks / Reason</label>
                <textarea name="remarks" class="form-control" rows="5" placeholder="Return er karon..."><?php echo $edit_data ? h($edit_data['remarks']) : ''; ?></textarea>
            </div>
            <div class="summary-box">
                <div class="summary-row"><label>Previous Balance (বাকি)</label><input type="number" id="sm_prev" class="form-control ro" readonly value="0.00"></div>
                <div class="summary-row"><label>Return Amount (items)</label><input type="number" id="sm_total" class="form-control ro" readonly value="0.00"></div>
                <div class="summary-row"><label>Discount (-)</label>
                    <div style="display:flex; gap:8px;">
                        <input type="number" id="sm_discount_percent" class="form-control" style="width:50px; text-align:center;" placeholder="%" step="any">
                        <input type="number" name="discount_amount" id="sm_discount" class="form-control" step="any" value="<?php echo $edit_data ? h($edit_data['discount_amount'] ?? 0) : '0'; ?>">
                    </div></div>
                <div class="summary-row" style="border-top:1px dashed #ccc; padding-top:6px;"><label>Return Credit (-)</label><input type="number" id="sm_credit" class="form-control ro" readonly value="0.00" title="Return Amount - Discount"></div>
                <div class="summary-row"><label>Refund Amount (+)</label><input type="number" id="sm_refund" class="form-control ro" readonly value="0.00" title="কাস্টমারকে ফেরত দেওয়া টাকার যোগফল"></div>
                <div id="payLines">
                <?php foreach ($pay_rows as $pr) { ?>
                    <div class="pay-line" style="display:flex; gap:6px; margin-bottom:5px; justify-content:flex-end;">
                        <select name="pay_account[]" class="form-control pay-acc" style="width:150px; font-size:11px; padding:4px;" title="Cash Type">
                            <?php if (!$accounts) { ?><option value="">-- No Account --</option><?php } ?>
                            <?php foreach ($accounts as $a) { ?><option value="<?php echo $a['id']; ?>" <?php if ($a['id'] == $pr['acc']) echo 'selected'; ?>><?php echo h($a['name']); ?></option><?php } ?>
                        </select>
                        <input type="number" name="pay_amount[]" class="form-control pay-amt" step="any" style="width:110px; text-align:right;" placeholder="Refund Amount" value="<?php echo $pr['amt'] !== '' ? number_format($pr['amt'], 2, '.', '') : ''; ?>">
                        <button type="button" style="background:#dc3545; color:#fff; border:none; border-radius:4px; padding:2px 9px; cursor:pointer;" onclick="removePayLine(this)">-</button>
                    </div>
                <?php } ?>
                </div>
                <div style="text-align:right; margin-bottom:6px;"><button type="button" class="btn-action" style="background:#28a745; color:#fff; border:none; font-size:11px; padding:3px 10px;" onclick="addPayLine()"><i class="fa-solid fa-plus"></i> Add Refund Type</button></div>
                <div class="summary-row" style="border-top:2px double #333; padding-top:6px;"><label style="color:#d32f2f;">Net Balance (কাস্টমারের বাকি)</label><input type="number" id="sm_net" class="form-control ro" style="font-weight:bold; color:#d32f2f;" readonly value="0.00"></div>
            </div>
        </div>
    </div>
</form>
</div>

<script>
var EDIT_ID = <?php echo (int)$edit_id; ?>;
var INIT = <?php echo json_encode($init, JSON_UNESCAPED_UNICODE); ?>;
function num(v) { return parseFloat(v) || 0; }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function post(data) {
    var fd = new FormData(); for (var k in data) fd.append(k, data[k]);
    return fetch('<?php echo $self; ?>', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
}

// ---- কাস্টমার / ইনভয়েস ----
function syncCustomerSelection() {
    var val = document.getElementById('customer_search').value, opts = document.getElementById('customer_list_options').options;
    document.getElementById('hidden_customer_id').value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { document.getElementById('hidden_customer_id').value = opts[i].getAttribute('data-id'); break; }
    }
    resetItems();
    loadInvoices(); loadPrevBalance();
}
function loadInvoices(selectId) {
    var cid = document.getElementById('hidden_customer_id').value, sel = document.getElementById('invoice_sel');
    sel.innerHTML = '<option value="">-- Select Invoice --</option>';
    document.getElementById('hidden_invoice_id').value = '';
    if (!cid) return Promise.resolve();
    return post({ action: 'get_invoices', customer_id: cid, exclude_id: EDIT_ID }).then(function (d) {
        if (d.status !== 'success') return;
        if (!d.invoices.length) { sel.innerHTML = '<option value="">-- Return-jogyo delivered invoice nai --</option>'; return; }
        d.invoices.forEach(function (v) {
            var o = document.createElement('option'); o.value = v.id; o.textContent = v.no + '  (' + v.date + ')'; sel.appendChild(o);
        });
        if (selectId) { sel.value = selectId; document.getElementById('hidden_invoice_id').value = selectId; }
    });
}
function onInvoiceChange() {
    var id = document.getElementById('invoice_sel').value;
    document.getElementById('hidden_invoice_id').value = id;
    resetItems();
    if (!id) return;
    post({ action: 'get_items', invoice_id: id, exclude_id: EDIT_ID }).then(function (d) {
        if (d.status === 'success') {
            buildRows(d.items);
            if (d.warehouse_id && !EDIT_ID) {   // sesh challan er warehouse default
                var ws = document.getElementById('warehouse_sel');
                if (ws.querySelector('option[value="' + d.warehouse_id + '"]')) ws.value = d.warehouse_id;
            }
        }
    });
}
function loadPrevBalance() {
    var cid = document.getElementById('hidden_customer_id').value;
    if (!cid) { document.getElementById('sm_prev').value = '0.00'; calcSummary(); return; }
    post({ action: 'get_prev_balance', customer_id: cid, exclude_id: EDIT_ID }).then(function (d) {
        if (d.status === 'success') { document.getElementById('sm_prev').value = d.balance; calcSummary(); }
    });
}

// ---- আইটেম রো ----
function resetItems() {
    var ca = document.getElementById('chkAll'); if (ca) ca.checked = false;
    document.getElementById('srBody').innerHTML = '<tr><td colspan="14" style="text-align:center;color:#999;padding:12px;">কাস্টমার ও ইনভয়েস নির্বাচন করুন।</td></tr>';
    calcSummary();
}
function buildRows(items) {
    var body = document.getElementById('srBody');
    if (!items.length) { body.innerHTML = '<tr><td colspan="14" style="text-align:center;color:#999;padding:12px;">এই ইনভয়েসে Return-যোগ্য ডেলিভারি নেই।</td></tr>'; calcSummary(); return; }
    var html = '';
    items.forEach(function (it) {
        var has = it.s_qty !== undefined;
        html += '<tr class="sr-row" data-boxpcs="' + it.box_to_pcs + '" data-ratiopcs="' + it.ratio_pcs + '" data-ratiout="' + it.ratio_output + '" data-max="' + it.returnable + '" data-name="' + esc(it.item_name) + '">'
          + '<td style="text-align:center;"><input type="checkbox" class="sr-chk" onclick="onRowCheck(this)"' + (has ? ' checked' : '') + '></td>'
          + '<td><b>' + esc(it.item_name) + '</b><input type="hidden" name="product_id[]" value="' + it.product_id + '"></td>'
          + '<td>' + esc(it.size_name) + '</td><td>' + esc(it.unit_name) + '</td>'
          + '<td class="r">' + num(it.delivered).toFixed(2) + '</td><td class="r">' + num(it.returned).toFixed(2) + '</td>'
          + '<td class="r" style="font-weight:bold;color:#2e7d32;">' + num(it.returnable).toFixed(2) + '</td>'
          + '<td><input type="number" name="box_in[]" class="form-control sr-box" value="' + (has ? parseInt(it.s_box) : '') + '"></td>'
          + '<td><input type="number" name="pcs_in[]" class="form-control sr-pcs" value="' + (has ? parseInt(it.s_pcs) : '') + '"></td>'
          + '<td><input type="number" name="qty_in[]" class="form-control sr-qty" step="any" value="' + (has ? num(it.s_qty).toFixed(2) : '') + '"></td>'
          + '<td><input type="number" name="rate[]" class="form-control sr-rate" step="any" value="' + num(it.rate) + '"></td>'
          + '<td><input type="number" class="form-control sr-amount ro" readonly style="font-weight:bold;" value=""></td>'
          + '<td><select name="cond[]" class="form-control sr-cond"><option value="Good"' + ((has && it.s_cond === 'Damaged') ? '' : ' selected') + '>Good</option><option value="Damaged"' + ((has && it.s_cond === 'Damaged') ? ' selected' : '') + '>Damaged</option></select></td>'
          + '<td style="text-align:center;"><button type="button" style="background:#dc3545;color:#fff;border:none;border-radius:4px;padding:3px 9px;cursor:pointer;" onclick="removeRow(this)" title="এই রো বাদ দিন">-</button></td>'
          + '</tr>';
    });
    body.innerHTML = html;
    document.querySelectorAll('.sr-row').forEach(function (r) { setRowEnabled(r, r.querySelector('.sr-chk').checked); calcRow(r); });
    syncChkAll();
    calcSummary();
}
// ---- রো সিলেক্ট / ডিলিট ----
// Unchecked rows er shob input disabled thake, tai form submit e jay na
function setRowEnabled(row, on) {
    row.querySelectorAll('input:not(.sr-chk), select').forEach(function (el) { el.disabled = !on; });
    row.style.opacity = on ? '1' : '0.55';
}
function syncChkAll() {
    var all = document.querySelectorAll('.sr-chk'), on = document.querySelectorAll('.sr-chk:checked');
    var m = document.getElementById('chkAll');
    if (m) m.checked = all.length > 0 && all.length === on.length;
}
function onRowCheck(chk) {
    var row = chk.closest('.sr-row');
    setRowEnabled(row, chk.checked);
    if (chk.checked) { var b = row.querySelector('.sr-box'); if (b) b.focus(); }
    syncChkAll(); calcSummary();
}
function toggleAllRows(master) {
    document.querySelectorAll('.sr-row').forEach(function (r) {
        r.querySelector('.sr-chk').checked = master.checked;
        setRowEnabled(r, master.checked);
    });
    calcSummary();
}
function removeRow(btn) {
    btn.closest('.sr-row').remove();
    if (!document.querySelectorAll('.sr-row').length) {
        document.getElementById('srBody').innerHTML = '<tr><td colspan="14" style="text-align:center;color:#999;padding:12px;">সব রো বাদ দেওয়া হয়েছে। আবার ইনভয়েস সিলেক্ট করলে আইটেম ফিরে আসবে।</td></tr>';
    }
    syncChkAll(); calcSummary();
}

function ratios(row) {
    return { bp: parseInt(row.getAttribute('data-boxpcs')) || 1, rp: parseFloat(row.getAttribute('data-ratiopcs')) || 1, ro: parseFloat(row.getAttribute('data-ratiout')) || 1 };
}
function qtyFromBox(row) {
    var r = ratios(row);
    var tp = (parseInt(row.querySelector('.sr-box').value) || 0) * r.bp + (parseInt(row.querySelector('.sr-pcs').value) || 0);
    row.querySelector('.sr-qty').value = tp > 0 ? (tp * (r.ro / r.rp)).toFixed(2) : '';
    calcRow(row); calcSummary();
}
function boxFromQty(row) {
    var r = ratios(row), q = num(row.querySelector('.sr-qty').value);
    if (q <= 0) { row.querySelector('.sr-box').value = ''; row.querySelector('.sr-pcs').value = ''; calcRow(row); calcSummary(); return; }
    var tp = Math.round(q / (r.ro / r.rp));
    row.querySelector('.sr-box').value = Math.floor(tp / r.bp);
    row.querySelector('.sr-pcs').value = tp % r.bp;
    row.querySelector('.sr-qty').value = (tp * (r.ro / r.rp)).toFixed(2);
    calcRow(row); calcSummary();
}
function calcRow(row) {
    var a = num(row.querySelector('.sr-qty').value) * num(row.querySelector('.sr-rate').value);
    row.querySelector('.sr-amount').value = a > 0 ? a.toFixed(2) : '';
}

// ---- সামারি ----
function calcSummary() {
    var t = 0;
    document.querySelectorAll('.sr-row').forEach(function (r) { if (r.querySelector('.sr-chk').checked) t += num(r.querySelector('.sr-amount').value); });
    var refund = 0;
    document.querySelectorAll('.pay-amt').forEach(function (i) { refund += num(i.value); });
    var disc = num(document.getElementById('sm_discount').value), credit = t - disc;
    document.getElementById('sm_total').value = t.toFixed(2);
    document.getElementById('sm_credit').value = credit.toFixed(2);
    document.getElementById('sm_refund').value = refund.toFixed(2);
    if (document.activeElement !== document.getElementById('sm_discount_percent'))
        document.getElementById('sm_discount_percent').value = t > 0 ? (disc * 100 / t).toFixed(2) : '0.00';
    document.getElementById('sm_net').value = (num(document.getElementById('sm_prev').value) - credit + refund).toFixed(2);
}
function addPayLine() {
    var box = document.getElementById('payLines'), n = box.querySelector('.pay-line').cloneNode(true);
    n.querySelector('.pay-amt').value = '';
    box.appendChild(n); calcSummary();
}
function removePayLine(btn) {
    var box = document.getElementById('payLines');
    if (box.querySelectorAll('.pay-line').length > 1) btn.closest('.pay-line').remove();
    else btn.closest('.pay-line').querySelector('.pay-amt').value = '';
    calcSummary();
}

function validateForm() {
    if (!document.getElementById('hidden_customer_id').value) { alert('অনুগ্রহ করে তালিকা থেকে একটি কাস্টমার নির্বাচন করুন!'); return false; }
    if (!document.getElementById('hidden_invoice_id').value) { alert('একটি ইনভয়েস নির্বাচন করুন!'); return false; }
    var ok = false, over = [], perProd = {};
    document.querySelectorAll('.sr-row').forEach(function (r) {
        if (!r.querySelector('.sr-chk').checked) return;
        var q = num(r.querySelector('.sr-qty').value);
        if (q > 0) {
            ok = true;
            var pid = r.querySelector("input[name='product_id[]']").value;
            perProd[pid] = perProd[pid] || { q: 0, max: num(r.getAttribute('data-max')), name: r.getAttribute('data-name') };
            perProd[pid].q += q;
        }
    });
    if (!ok) { alert('কমপক্ষে একটি পণ্যের Return পরিমাণ দিন!'); return false; }
    for (var k in perProd) { if (perProd[k].q - perProd[k].max > 0.01) over.push(perProd[k].name + ' (সর্বোচ্চ ' + perProd[k].max.toFixed(2) + ')'); }
    if (over.length) { alert('ডেলিভারির চেয়ে বেশি Return করা যাবে না:\n' + over.join('\n')); return false; }
    if (num(document.getElementById('sm_discount').value) - num(document.getElementById('sm_total').value) > 0.01) { alert('Discount, Return Amount এর বেশি হতে পারে না!'); return false; }
    var payOk = true;
    document.querySelectorAll('.pay-line').forEach(function (l) {
        if (num(l.querySelector('.pay-amt').value) > 0 && !l.querySelector('.pay-acc').value) payOk = false;
    });
    if (!payOk) { alert('টাকা ফেরতের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!'); return false; }
    return true;
}

// ---- ইভেন্ট ----
document.addEventListener('change', function (e) {
    var c = e.target.classList; if (!c) return;
    var row = e.target.closest('.sr-row');
    if (c.contains('sr-box') || c.contains('sr-pcs')) qtyFromBox(row);
    else if (c.contains('sr-qty')) boxFromQty(row);
}, true);
document.addEventListener('input', function (e) {
    var c = e.target.classList; if (!c) return;
    if (c.contains('sr-rate')) { calcRow(e.target.closest('.sr-row')); calcSummary(); }
    else if (c.contains('pay-amt')) calcSummary();
    else if (e.target.id === 'sm_discount_percent') {
        var t = num(document.getElementById('sm_total').value);
        document.getElementById('sm_discount').value = (t * num(e.target.value) / 100).toFixed(2); calcSummary();
    }
    else if (e.target.id === 'sm_discount') calcSummary();
}, true);
document.addEventListener('focus', function (e) {
    if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.type === 'text') && !e.target.readOnly) {
        setTimeout(function () { e.target.select(); }, 50);
    }
}, true);

window.addEventListener('load', function () {
    if (INIT) {   // edit mode: customer/invoice lock kore dei
        document.getElementById('customer_search').value = INIT.customer_label;
        document.getElementById('hidden_customer_id').value = INIT.customer_id;
        document.getElementById('hidden_invoice_id').value = INIT.invoice_id;
        var sel = document.getElementById('invoice_sel');
        sel.innerHTML = '<option value="' + INIT.invoice_id + '">' + esc(INIT.invoice_no) + '</option>';
        sel.disabled = true; document.getElementById('customer_search').disabled = true;
        buildRows(INIT.items);
    }
    loadPrevBalance();
    calcSummary();
});
</script>
<?php } ?>
</body>
</html>