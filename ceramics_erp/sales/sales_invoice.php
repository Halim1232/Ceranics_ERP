<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$is_ajax = isset($_POST['action']) || isset($_POST['save_customer']);
if ($is_ajax) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'unauthorized']); exit; }
    include_once('../access.php'); require_page_access('sales/sales_invoice_list.php', true);   // AJAX: JSON 403
} else {
    include('../auth.php');
    require_page_access('sales/sales_invoice_list.php');
}
include('../config.php');

// প্রাইমারি কী কলাম অটো-ডিটেক্ট (id / invoice_id)
$idf = "id";
$mc = mysqli_query($conn, "SHOW COLUMNS FROM sales_invoice_master");
while ($c = mysqli_fetch_assoc($mc)) { if ($c['Key'] == 'PRI') { $idf = $c['Field']; break; } }

function product_stock($conn, $pid) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in - sqft_out),0) AS s FROM erp_stock_ledger WHERE product_id=" . (int)$pid . branch_wh_sql('warehouse_id')));   // ব্রাঞ্চ ইউজার: শুধু নিজের ব্রাঞ্চের ওয়্যারহাউসের স্টক
    return (float)$r['s'];
}
function customer_prev_balance($conn, $cid, $idf, $before_id = 0) {
    $cid = (int)$cid; if ($cid <= 0) return 0;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT opening_due FROM erp_customers WHERE id=$cid"));
    $bal = (float)($r['opening_due'] ?? 0);
    $cond = $before_id > 0 ? "AND m.$idf < " . (int)$before_id : "";
    $q = mysqli_query($conn, "SELECT COALESCE(SUM(IFNULL(t.total,0) + m.labor_charge - m.discount_amount - m.payment_amount),0) AS due
        FROM sales_invoice_master m
        LEFT JOIN (SELECT invoice_id, SUM(total_qty * rate) AS total FROM sales_invoice_details GROUP BY invoice_id) t ON t.invoice_id = m.$idf
        WHERE m.customer_id=$cid $cond");
    $bal += (float)mysqli_fetch_assoc($q)['due'];
    // Sales Return: (return amount - refund) customer er due theke kombe
    try {
        $rc = $before_id > 0 ? "AND invoice_id < " . (int)$before_id : "";
        $rx = "";
        $sr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - discount_amount - refund_amount),0) AS x FROM erp_sales_return WHERE customer_id=$cid $rc $rx"));
        $bal -= (float)($sr['x'] ?? 0);
    } catch (Throwable $e) { /* erp_sales_return table na thakle ignore */ }
    return $bal;
}

// ---------- AJAX ----------
if ($is_ajax) {
    $act = $_POST['action'] ?? '';
    if ($act == 'get_current_stock') {
        echo json_encode(['status'=>'success', 'stock'=>number_format(product_stock($conn, (int)$_POST['product_id']), 2, '.', '')]); exit;
    }
    if ($act == 'get_prev_balance') {
        $bal = customer_prev_balance($conn, (int)$_POST['customer_id'], $idf, (int)($_POST['exclude_id'] ?? 0));
        echo json_encode(['status'=>'success', 'balance'=>number_format($bal, 2, '.', '')]); exit;
    }
    if (isset($_POST['save_customer'])) {
        $name = trim($_POST['customer_name'] ?? ''); $phone = trim($_POST['mobile_no'] ?? ''); $addr = trim($_POST['address'] ?? '');
        if ($name === '' || $phone === '') { echo json_encode(['status'=>'error']); exit; }
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(id) AS m FROM erp_customers"));
        $code = "CUST-" . str_pad(((int)$m['m']) + 1, 4, "0", STR_PAD_LEFT);
        $st = $conn->prepare("INSERT INTO erp_customers (customer_code, customer_name, address, phone, opening_due) VALUES (?,?,?,?,0)");
        $st->bind_param("ssss", $code, $name, $addr, $phone);
        $st->execute();
        echo json_encode(['status'=>'success', 'id'=>$conn->insert_id]); exit;
    }
    echo json_encode(['status'=>'error']); exit;
}

$edit_id = (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) ? (int)$_GET['id'] : 0;

// ---------- SAVE ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_invoice'])) {
    $fail = function($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };

    $invoice_date = mysqli_real_escape_string($conn, $_POST['invoice_date']);
    $customer_id  = (int)$_POST['customer_id'];
    $branch_name  = mysqli_real_escape_string($conn, $_POST['branch_name']);
    if ($bScope = current_branch()) {   // ব্রাঞ্চ ইউজার: ফর্মে যাই আসুক, নিজের ব্রাঞ্চই বসবে
        if ($bScope['id'] === 2147483647) $fail('আপনার ব্রাঞ্চ বরাদ্দ নেই, অ্যাডমিনের সাথে যোগাযোগ করুন।');
        $branch_name = mysqli_real_escape_string($conn, $bScope['name']);
    }
    $sales_person_id = (int)($_POST['sales_person_id'] ?? 0);
    $spchk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM erp_employees WHERE id=$sales_person_id"));
    if (!$spchk) $fail('অনুগ্রহ করে সেলস পার্সন নির্বাচন করুন!');
    $remarks      = mysqli_real_escape_string($conn, $_POST['remarks']);
    $labor    = floatval($_POST['labor_charge'] ?? 0);
    $discount = floatval($_POST['discount_amount'] ?? 0);
    // একাধিক পেমেন্ট লাইন (Cash / bKash / Bank ...)
    $pay_lines = []; $seen_acc = [];
    foreach (($_POST['pay_amount'] ?? []) as $i => $pamt) {
        $pamt = floatval($pamt); $pacc = (int)($_POST['pay_account'][$i] ?? 0);
        if ($pamt <= 0) continue;
        if ($pacc <= 0) $fail('পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!');
        if (isset($seen_acc[$pacc])) $fail('একই ক্যাশ/ব্যাংক/MFS অ্যাকাউন্ট একাধিকবার দেওয়া যাবে না। একটি লাইনে মোট টাকা দিন।');
        $seen_acc[$pacc] = true;
        $pay_lines[] = ['acc' => $pacc, 'amt' => $pamt];
    }
    $payment = 0; foreach ($pay_lines as $pl) $payment += $pl['amt'];
    if ($customer_id <= 0) $fail('অনুগ্রহ করে তালিকা থেকে একটি কাস্টমার নির্বাচন করুন!');

    // আইটেম সংগ্রহ
    $items = []; $need = [];
    foreach (($_POST['product_id'] ?? []) as $i => $p) {
        $pid = (int)$p; $qty = floatval($_POST['qty_in'][$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        if (isset($need[$pid])) $fail('একই পণ্য একাধিকবার দেওয়া যাবে না। আগের লাইনে পরিমাণ বাড়ান।');
        $items[] = ['pid'=>$pid, 'box'=>floatval($_POST['box_in'][$i] ?? 0), 'pcs'=>floatval($_POST['pcs_in'][$i] ?? 0), 'qty'=>$qty, 'rate'=>floatval($_POST['purchase_rate'][$i] ?? 0)];
        $need[$pid] = ($need[$pid] ?? 0) + $qty;
    }
    if (empty($items)) $fail('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!');

    // এডিট লক: ডেলিভারি হয়ে গেলে এডিট নয়
    if ($edit_id > 0) {
        $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no, branch_name FROM sales_invoice_master WHERE $idf=$edit_id"));
        if (!$o) $fail('ইনভয়েস পাওয়া যায়নি!');
        branch_guard($o['branch_name'] ?? '');
        $ono = mysqli_real_escape_string($conn, $o['invoice_no']);
        $invoice_no = $o['invoice_no'];
        $dc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_delivery_challan_master WHERE invoice_no='$ono'"));
        if ($dc['c'] > 0) $fail('এই ইনভয়েসের ডেলিভারি চালান হয়ে গেছে, তাই এডিট করা যাবে না।');
    }

    // স্টক চেক
    $short = [];
    foreach ($need as $pid => $qty) {
        $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, negative_sales FROM erp_products WHERE id=$pid"));
        $stock = product_stock($conn, $pid);
        if ($p && !$p['negative_sales'] && ($qty - $stock) > 0.01) {
            $short[] = $p['item_name'] . " (স্টক: " . number_format($stock, 2) . ", বিক্রি: " . number_format($qty, 2) . ")";
        }
    }
    if ($short) $fail("স্টক অপর্যাপ্ত:\n" . implode("\n", $short));

    // সার্ভারেই হিসাব
    $total = 0; foreach ($items as $it) { $total += $it['qty'] * $it['rate']; }
    $gross = $total + $labor - $discount;
    $prev  = customer_prev_balance($conn, $customer_id, $idf, $edit_id);
    $net   = $gross + $prev - $payment;

    mysqli_begin_transaction($conn);
    try {
        if ($edit_id > 0) {
            mysqli_query($conn, "UPDATE sales_invoice_master SET invoice_date='$invoice_date', customer_id=$customer_id, branch_name='$branch_name', sales_person_id=$sales_person_id, net_balance=$net, labor_charge=$labor, discount_amount=$discount, payment_amount=$payment, remarks='$remarks' WHERE $idf=$edit_id");
            mysqli_query($conn, "DELETE FROM sales_invoice_details WHERE invoice_id=$edit_id");
            $inv_id = $edit_id;
        } else {
            $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX($idf) AS m FROM sales_invoice_master"));
            $invoice_no = "INV-" . date('y') . str_pad(((int)$m['m']) + 1, 4, "0", STR_PAD_LEFT);
            mysqli_query($conn, "INSERT INTO sales_invoice_master (invoice_no, invoice_date, customer_id, branch_name, sales_person_id, net_balance, labor_charge, discount_amount, payment_amount, remarks, status)
                                 VALUES ('$invoice_no', '$invoice_date', $customer_id, '$branch_name', $sales_person_id, $net, $labor, $discount, $payment, '$remarks', 'Undelivered')");
            $inv_id = mysqli_insert_id($conn);
        }
        // ক্যাশ/ব্যাংক লেজারে পেমেন্ট এন্ট্রি (এডিট হলে আগেরটি মুছে নতুন)
        $inv_no_esc = mysqli_real_escape_string($conn, $invoice_no);
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='SALE' AND ref_no='$inv_no_esc'");
        foreach ($pay_lines as $pl) {
            mysqli_query($conn, "INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, created_by)
                                 VALUES ('$invoice_date', {$pl['acc']}, 'IN', {$pl['amt']}, 'SALE', '$inv_no_esc', " . (int)$_SESSION['user_id'] . ")");
        }
        foreach ($items as $it) {
            $amt = $it['qty'] * $it['rate'];
            mysqli_query($conn, "INSERT INTO sales_invoice_details (invoice_id, product_id, box_qty, pcs_qty, total_qty, rate, amount)
                                 VALUES ($inv_id, {$it['pid']}, {$it['box']}, {$it['pcs']}, {$it['qty']}, {$it['rate']}, $amt)");
        }
        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।');
    }
    // ===== Accounts auto-posting: Dr Receivable / Cr Sales Revenue (transaction commit er pore) =====
    // Cash/Bank/bKash er payment line gulo erp_fund_ledger theke acc_sync_fund_journal() journal e niye ashe.
    if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
    include_once('../Accounts/accounts_lib.php');
    post_journal($conn, 'SALES', $invoice_no, $invoice_date, "Sales Invoice $invoice_no", [
        ['1101', $gross, 0, $customer_id],
        ['4001', 0, $gross]
    ]);
    // COGS: Dr 5001 / Cr 1201 (পণ্যের cost_price × বিক্রিত SFT); ব্যর্থ হলেও ইনভয়েস সেভ ঠিক থাকবে
    $cogs_note = '';
    try {
        $cg = post_cogs($conn, $invoice_no, $invoice_date, $inv_id);
        if ($cg['zero_cost'] > 0) $cogs_note = "\n\nসতর্কতা: " . $cg['zero_cost'] . "টি পণ্যের Purchase Price ০, তাই COGS কম ধরা হয়েছে। Product List-এ Purchase Price দিন।";
    } catch (Throwable $e) { error_log('COGS post: ' . $e->getMessage()); }
    acc_sync_fund_journal($conn);
    echo "<script>alert(" . json_encode('সেলস ইনভয়েস সফলভাবে সংরক্ষিত হয়েছে।' . $cogs_note) . "); window.location.href='sales_invoice_list.php';</script>";
    exit;
}

// ---------- ফর্মের ডাটা ----------
$edit_data = null; $edit_items = [];
$next = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX($idf) AS m FROM sales_invoice_master"));
$inv_no_show = "INV-" . date('y') . str_pad(((int)$next['m']) + 1, 4, "0", STR_PAD_LEFT);

if ($edit_id > 0) {
    $er = mysqli_query($conn, "SELECT * FROM sales_invoice_master WHERE $idf=$edit_id");
    if ($er && mysqli_num_rows($er) > 0) {
        $edit_data = array_change_key_case(mysqli_fetch_assoc($er), CASE_LOWER);
        branch_guard($edit_data['branch_name'] ?? '');
        $inv_no_show = $edit_data['invoice_no'];
        $ir = mysqli_query($conn, "SELECT D.*, P.item_name, P.size_name, P.color_name, P.unit_name, P.box_to_pcs, P.ratio_pcs, P.ratio_output, P.negative_sales
                                   FROM sales_invoice_details D JOIN erp_products P ON D.product_id = P.id WHERE D.invoice_id=$edit_id");
        while ($x = mysqli_fetch_assoc($ir)) $edit_items[] = $x;
    }
}

$customers = [];
$cr = mysqli_query($conn, "SELECT id, customer_name, phone, address FROM erp_customers ORDER BY customer_name ASC");
while ($c = mysqli_fetch_assoc($cr)) {
    $parts = array_filter([trim($c['address'] ?? ''), trim($c['phone'] ?? '')]);
    $customers[] = ['id'=>$c['id'], 'label'=>$c['customer_name'] . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '')];
}
$branches = [];
$br = mysqli_query($conn, "SELECT DISTINCT branch_name FROM erp_branches WHERE status='Active'" . branch_sql('branch_name'));
while ($br && $b = mysqli_fetch_assoc($br)) $branches[] = $b['branch_name'];
if (!$branches && !current_branch()) $branches[] = 'Main Factory / Godown';
$employees = [];
$eq = mysqli_query($conn, "SELECT id, employee_name, mobile_no FROM erp_employees WHERE status='Active' ORDER BY employee_name ASC");
while ($eq && $e = mysqli_fetch_assoc($eq)) $employees[] = $e;
$products = mysqli_query($conn, "SELECT id, item_name, unit_name, size_name, color_name, sale_price, box_to_pcs, ratio_pcs, ratio_output, negative_sales FROM erp_products ORDER BY item_name ASC");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function render_row($it = null) {
    $v = function($k, $d = '') use ($it) { return $it ? h($it[$k] ?? $d) : $d; };
    $qty = $it ? number_format((float)$it['Total_Qty'], 2, '.', '') : '';
    $amt = $it ? number_format((float)$it['Total_Qty'] * (float)$it['Rate'], 2, '.', '') : '';
    ?>
    <tr class="grn-item-row" data-boxpcs="<?php echo $v('box_to_pcs','1'); ?>" data-ratiopcs="<?php echo $v('ratio_pcs','1'); ?>" data-ratiout="<?php echo $v('ratio_output','1'); ?>" data-neg="<?php echo $v('negative_sales','0'); ?>">
        <td>
            <input type="text" class="form-control grn-prod-input" list="product_list_options" placeholder="Type product to search..." value="<?php echo $v('item_name'); ?>" onchange="syncProductSelection(this)">
            <input type="hidden" name="product_id[]" value="<?php echo $v('product_id'); ?>">
        </td>
        <td><input type="text" class="form-control grn-item-size" readonly value="<?php echo $v('size_name'); ?>"></td>
        <td><input type="text" class="form-control grn-item-color" readonly value="<?php echo $v('color_name'); ?>"></td>
        <td><input type="text" class="form-control grn-item-unit" readonly value="<?php echo $v('unit_name'); ?>"></td>
        <td><input type="text" class="form-control grn-current-stock" style="text-align:right;" readonly value="0.00"></td>
        <td><input type="number" name="box_in[]" class="form-control grn-box" value="<?php echo $it ? (int)$it['Box_Qty'] : ''; ?>"></td>
        <td><input type="number" name="pcs_in[]" class="form-control grn-pcs" value="<?php echo $it ? (int)$it['Pcs_Qty'] : ''; ?>"></td>
        <td><input type="number" name="qty_in[]" class="form-control grn-qty" step="any" value="<?php echo $qty; ?>"></td>
        <td><input type="number" name="purchase_rate[]" class="form-control grn-rate" step="any" value="<?php echo $v('rate'); ?>"></td>
        <td><input type="number" class="form-control grn-amount" step="any" readonly style="font-weight:bold;" value="<?php echo $amt; ?>"></td>
        <td style="text-align:center;"><button type="button" class="btn-action" style="background:#dc3545;color:#fff;padding:4px 8px;border:none;" onclick="removeThisRow(this)">-</button></td>
    </tr>
    <?php
}
$cust_label_now = '';
if ($edit_data) { foreach ($customers as $c) { if ($c['id'] == $edit_data['customer_id']) $cust_label_now = $c['label']; } }

// Cash Type ড্রপডাউনের অ্যাকাউন্ট তালিকা (erp_fund_accounts)
$accounts = []; $sel_account = 0;
try {
    $fc = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM erp_fund_accounts");
    while ($q && $r = mysqli_fetch_assoc($q)) $fc[strtolower($r['Field'])] = $r['Field'];
    $a_id = $fc['id'] ?? ($fc['account_id'] ?? null);
    $a_nm = $fc['account_name'] ?? ($fc['name'] ?? ($fc['title'] ?? null));
    if ($a_id && $a_nm) {
        $q = mysqli_query($conn, "SELECT `$a_id` AS id, `$a_nm` AS nm FROM erp_fund_accounts ORDER BY `$a_id` ASC");
        while ($q && $r = mysqli_fetch_assoc($q)) $accounts[] = ['id' => (int)$r['id'], 'name' => $r['nm']];
    }
} catch (Exception $e) {}
if (!$sel_account) {   // ডিফল্ট: Cash in Hand
    foreach ($accounts as $a) { if (stripos($a['name'], 'cash in hand') !== false) { $sel_account = $a['id']; break; } }
}
if (!$sel_account && $accounts) $sel_account = $accounts[0]['id'];

// ফর্মে দেখানোর পেমেন্ট লাইন (এডিটে আগেরগুলো, নতুনে একটি খালি লাইন)
$pay_rows = [];
if ($edit_data) {
    try {
        $s2 = mysqli_query($conn, "SELECT account_id, amount FROM erp_fund_ledger WHERE ref_type='SALE' AND ref_no='" . mysqli_real_escape_string($conn, $edit_data['invoice_no']) . "'");
        while ($s2 && $x = mysqli_fetch_assoc($s2)) $pay_rows[] = ['acc' => (int)$x['account_id'], 'amt' => (float)$x['amount']];
    } catch (Exception $e) {}
    if (!$pay_rows && (float)$edit_data['payment_amount'] > 0) $pay_rows[] = ['acc' => $sel_account, 'amt' => (float)$edit_data['payment_amount']];
}
if (!$pay_rows) $pay_rows[] = ['acc' => $sel_account, 'amt' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Invoice - Uttara Builders ERP</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; }
    .form-panel { background: #fff; border: 1px solid #dddd3d; border-radius: 4px; }
    .panel-body { padding: 20px; }
    .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 15px; padding: 10px; }
    .form-group { display: flex; flex-direction: column; gap: 3px; }
    .form-group label { font-size: 13px; font-weight: bold; color: #333; }
    .form-control { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #ccdc39; margin-top: 10px; }
    .grid-table th { background: #cbd5e1; padding: 6px; text-align: left; border: 1px solid #ccdc39; }
    .grid-table td { padding: 3px; border: 1px solid #dee2e6; background: #fff; }
    .inv-layout { display: flex; gap: 15px; align-items: flex-start; }
    .inv-left { flex: 1; min-width: 0; }
    .inv-right { width: 330px; flex-shrink: 0; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow-y: auto; overflow-x: hidden; }
    .inv-right .summary-box { width: 100%; box-sizing: border-box; }
    .items-scroll { max-height: calc(100vh - 430px); min-height: 170px; overflow: auto; border: 1px solid #ccdc39; background: #fff; }
    .items-scroll .grid-table { margin-top: 0; border: none; }
    .items-scroll thead th { position: sticky; top: 0; z-index: 2; box-shadow: 0 1px 0 #ccdc39; }
    .summary-wrapper { display: flex; justify-content: flex-end; margin-top: 8px; gap: 20px; }
    .summary-box { width: 390px; background: #fafafa; border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; }
    .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .summary-row label { font-weight: bold; color: #444; font-size: 12px; }
    .summary-row input { width: 105px; text-align: right; }
    .sup-modal { display: none; position: fixed; z-index: 9999; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,.4); padding-top: 100px; box-sizing: border-box; }
    .sup-modal-content { background: #fff; margin: auto; padding: 20px; border: 1px solid #cdcd39; width: 400px; border-radius: 5px; }
    .low-stock { background: #ffd6d6 !important; }
.neg-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.45); }
    .neg-box { background: #fff; width: 520px; max-width: 94%; margin: 90px auto 0; border-radius: 6px; border-top: 5px solid #e65100; box-shadow: 0 6px 20px rgba(0,0,0,.3); overflow: hidden; }
    .neg-title { padding: 14px 18px; font-size: 16px; font-weight: bold; color: #e65100; background: #fff8f0; border-bottom: 1px solid #f3d9c3; }
    .neg-body { padding: 14px 18px; max-height: 280px; overflow-y: auto; }
    .neg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .neg-table th { background: #f1f5f9; padding: 6px 8px; border: 1px solid #cbd5e1; text-align: left; }
    .neg-table td { padding: 6px 8px; border: 1px solid #dee2e6; }
    .neg-foot { padding: 12px 18px; display: flex; gap: 10px; justify-content: flex-end; background: #f7f8fa; border-top: 1px solid #e3e6ea; }
    .neg-warn td { background: #ffe0b2 !important; }
    input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
</style>
</head>
<body>

<datalist id="product_list_options">
<?php while ($p = mysqli_fetch_assoc($products)) { ?>
    <option value="<?php echo h($p['item_name']); ?>" data-id="<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES); ?>" data-size="<?php echo h($p['size_name']); ?>" data-color="<?php echo h($p['color_name']); ?>" data-unit="<?php echo h($p['unit_name']); ?>" data-rate="<?php echo (float)$p['sale_price']; ?>" data-boxpcs="<?php echo (float)$p['box_to_pcs']; ?>" data-ratiopcs="<?php echo (float)$p['ratio_pcs']; ?>" data-ratiout="<?php echo (float)$p['ratio_output']; ?>" data-neg="<?php echo (int)$p['negative_sales']; ?>">
<?php } ?>
</datalist>
<datalist id="customer_list_options">
<?php foreach ($customers as $c) { ?>
    <option data-id="<?php echo htmlspecialchars((string)$c['id'], ENT_QUOTES); ?>" value="<?php echo h($c['label']); ?>">
<?php } ?>
</datalist>

<div class="form-panel">
<form method="POST" action="" id="invoiceForm" onsubmit="return validateForm()">
    <div style="background:#ececef; padding:10px 18px; font-size:15px; font-weight:bold; color:#495057; display:flex; align-items:center; gap:8px;">
        <button type="submit" name="save_invoice" class="btn-action" style="background:#28a745; color:#fff; border:none;"><i class="fa-solid fa-floppy-disk"></i> Save Invoice</button>
        <button type="button" class="btn-action" onclick="location.href='sales_invoice_list.php'"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        <span style="margin-left:14px;">SALES INVOICE MASTER ENTRY</span>
    </div>
    <div class="panel-body">
        <div class="excel-grid">
            <div class="form-group"><label>Invoice Number (Auto)</label><input type="text" class="form-control" value="<?php echo h($inv_no_show); ?>" readonly style="font-weight:bold;"></div>
            <div class="form-group"><label>Invoice Date</label><input type="date" name="invoice_date" class="form-control" required value="<?php echo $edit_data ? h($edit_data['invoice_date']) : date('Y-m-d'); ?>"></div>
            <div class="form-group"><label>Branch / Warehouse</label>
                <select name="branch_name" class="form-control" required>
                    <?php foreach ($branches as $b) { ?><option value="<?php echo h($b); ?>" <?php if ($edit_data && $edit_data['branch_name'] == $b) echo 'selected'; ?>><?php echo h($b); ?></option><?php } ?>
                </select></div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Customer (Search Box)</label>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="text" id="customer_search" class="form-control" list="customer_list_options" placeholder="Type customer name to search..." onchange="syncCustomerSelection()" style="flex:1;" value="<?php echo h($cust_label_now); ?>">
                    <input type="hidden" name="customer_id" id="hidden_customer_id" value="<?php echo $edit_data ? (int)$edit_data['customer_id'] : ''; ?>">
                    <button type="button" class="btn-action" style="background:#28a745; color:#fff; height:32px; font-size:18px; border:none;" onclick="openCustomerModal()">+</button>
                </div>
            </div>
            <div class="form-group"><label>Sales Person</label>
                <select name="sales_person_id" class="form-control" required>
                    <option value="">-- Select Sales Person --</option>
                    <?php foreach ($employees as $e) { ?><option value="<?php echo (int)$e['id']; ?>" <?php if ($edit_data && (int)($edit_data['sales_person_id'] ?? 0) === (int)$e['id']) echo 'selected'; ?>><?php echo h($e['employee_name'] . ' - ' . $e['mobile_no']); ?></option><?php } ?>
                </select></div>
        </div>

        <div class="inv-layout"><div class="inv-left">
        <label style="font-size:12px; font-weight:bold; color:#2e7d32; margin-top:15px; display:block;"><i class="fa-solid fa-boxes-stacked"></i> Sales Item Details</label>
        <div class="items-scroll">
        <table class="grid-table" id="grnItemsEntryTable">
            <thead><tr>
                <th>Select Product Name</th><th style="width:80px;">Size</th><th style="width:80px;">Color</th><th style="width:60px;">Unit</th>
                <th style="width:90px; text-align:right;">Current Stock</th><th style="width:70px;">Box</th><th style="width:70px;">Pcs</th>
                <th style="width:80px;">QTY (Total)</th><th style="width:90px;">Price</th><th style="width:100px;">Amount</th><th style="width:40px;">Act</th>
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
                <div class="summary-row"><label>Total Amount</label><input type="number" id="sm_total_amount" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Labor Charge (+)</label><input type="number" name="labor_charge" id="sm_labor" class="form-control" step="any" value="<?php echo $edit_data ? h($edit_data['labor_charge']) : '0'; ?>"></div>
                <div class="summary-row"><label>Discount (-)</label>
                    <div style="display:flex; gap:8px;">
                        <input type="number" id="sm_discount_percent" class="form-control" style="width:50px; text-align:center;" placeholder="%" step="any">
                        <input type="number" name="discount_amount" id="sm_discount" class="form-control" step="any" value="<?php echo $edit_data ? h($edit_data['discount_amount']) : '0'; ?>">
                    </div></div>
                <div class="summary-row" style="border-top:1px dashed #ccc; padding-top:6px;"><label>Gross Total</label><input type="number" id="sm_gross" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Previous Balance</label><input type="number" id="sm_prev_balance" class="form-control" readonly value="0.00"></div>
                <div class="summary-row"><label>Payment Amount (-)</label><input type="number" id="sm_payment" class="form-control" readonly value="0.00" title="সব পেমেন্ট লাইনের যোগফল"></div>
                <div id="payLines">
                <?php foreach ($pay_rows as $pr) { ?>
                    <div class="pay-line" style="display:flex; gap:6px; margin-bottom:5px; justify-content:flex-end;">
                        <select name="pay_account[]" class="form-control pay-acc" style="width:150px; font-size:11px; padding:4px;" title="Cash Type">
                            <?php if (!$accounts) { ?><option value="">-- No Account --</option><?php } ?>
                            <?php foreach ($accounts as $a) { ?><option value="<?php echo htmlspecialchars((string)$a['id'], ENT_QUOTES); ?>" <?php if ($a['id'] == $pr['acc']) echo 'selected'; ?>><?php echo h($a['name']); ?></option><?php } ?>
                        </select>
                        <input type="number" name="pay_amount[]" class="form-control pay-amt" step="any" style="width:110px; text-align:right;" placeholder="Amount" value="<?php echo $pr['amt'] !== '' ? number_format($pr['amt'], 2, '.', '') : ''; ?>">
                        <button type="button" style="background:#dc3545; color:#fff; border:none; border-radius:4px; padding:2px 9px; cursor:pointer;" onclick="removePayLine(this)">-</button>
                    </div>
                <?php } ?>
                </div>
                <div style="text-align:right; margin-bottom:6px;"><button type="button" class="btn-action" style="background:#28a745; color:#fff; border:none; font-size:11px; padding:3px 10px;" onclick="addPayLine()"><i class="fa-solid fa-plus"></i> Add Payment Type</button></div>
                <div class="summary-row" style="border-top:2px double #333; padding-top:6px;"><label style="color:#d32f2f;">Net Balance</label><input type="number" id="sm_net" class="form-control" style="font-weight:bold; color:#d32f2f;" readonly value="0.00"></div>
            </div>
        </div><!-- /inv-right -->
        </div><!-- /inv-layout -->
    </div>
</form>
</div>

<div id="customerModal" class="sup-modal">
    <div class="sup-modal-content">
        <div style="font-size:13px; font-weight:bold; color:#2e7d32; margin-bottom:12px;"><i class="fa-solid fa-user-plus"></i> Quick Add New Customer</div>
        <div class="form-group"><label>Customer Name</label><input type="text" id="m_name" class="form-control"></div>
        <div class="form-group" style="margin-top:5px;"><label>Mobile No</label><input type="text" id="m_mobile" class="form-control"></div>
        <div class="form-group" style="margin-top:5px;"><label>Address</label><input type="text" id="m_address" class="form-control"></div>
        <div style="margin-top:10px; display:flex; gap:5px; justify-content:flex-end;">
            <button type="button" class="btn-action" onclick="submitQuickCustomer()">Save</button>
            <button type="button" class="btn-action" onclick="closeCustomerModal()">Close</button>
        </div>
    </div>
</div>

<div id="negModal" class="neg-modal">
    <div class="neg-box">
        <div class="neg-title"><i class="fa-solid fa-triangle-exclamation"></i> "পর্যাপ্ত স্টক নেই! আপনি কি প্রি-অর্ডার নিতে চান?"</div>
        <div class="neg-body">
            <table class="neg-table">
                <thead><tr><th>পণ্য</th><th style="text-align:right;width:130px;">ঘাটতি (SFT)</th></tr></thead>
                <tbody id="negList"></tbody>
            </table>
        </div>
        <div class="neg-foot">
            <button type="button" id="negNo" class="btn-action" onclick="negCancel()"><i class="fa-solid fa-pen" style="color:#0498db;"></i> না, ঠিক করুন</button>
            <button type="button" id="negYes" class="btn-action" style="background:#e65100;color:#fff;border:none;" onclick="negProceed()"><i class="fa-solid fa-check"></i> হ্যাঁ, সেভ করুন</button>
        </div>
    </div>
</div>

<script>
var EDIT_ID = <?php echo (int)$edit_id; ?>;
function num(v) { return parseFloat(v) || 0; }
function post(data) {
    var fd = new FormData(); for (var k in data) fd.append(k, data[k]);
    return fetch('sales_invoice.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
}

// ---- কাস্টমার ----
function syncCustomerSelection() {
    var val = document.getElementById('customer_search').value;
    var opts = document.getElementById('customer_list_options').options;
    document.getElementById('hidden_customer_id').value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { document.getElementById('hidden_customer_id').value = opts[i].getAttribute('data-id'); break; }
    }
    loadPrevBalance();
}
function loadPrevBalance() {
    var cid = document.getElementById('hidden_customer_id').value;
    if (!cid) { document.getElementById('sm_prev_balance').value = '0.00'; calculateSummary(); return; }
    post({ action: 'get_prev_balance', customer_id: cid, exclude_id: EDIT_ID }).then(function(d) {
        if (d.status === 'success') { document.getElementById('sm_prev_balance').value = d.balance; calculateSummary(); }
    });
}
function openCustomerModal() { document.getElementById('customerModal').style.display = 'block'; }
function closeCustomerModal() { document.getElementById('customerModal').style.display = 'none'; }
function submitQuickCustomer() {
    var name = document.getElementById('m_name').value.trim(), mobile = document.getElementById('m_mobile').value.trim(), addr = document.getElementById('m_address').value.trim();
    if (!name || !mobile) { alert('নাম ও মোবাইল নম্বর আবশ্যক!'); return; }
    post({ save_customer: 1, customer_name: name, mobile_no: mobile, address: addr }).then(function(d) {
        if (d.status !== 'success') { alert('কাস্টমার সেভ হয়নি!'); return; }
        var label = name + ' [ ' + (addr ? addr + ' | ' : '') + mobile + ' ]';
        var opt = document.createElement('option'); opt.value = label; opt.setAttribute('data-id', d.id);
        document.getElementById('customer_list_options').appendChild(opt);
        document.getElementById('customer_search').value = label;
        document.getElementById('hidden_customer_id').value = d.id;
        closeCustomerModal(); loadPrevBalance();
    }).catch(function() { alert('কাস্টমার সেভ হয়নি!'); });
}

// ---- প্রোডাক্ট ----
function syncProductSelection(input) {
    var row = input.closest('tr'), val = input.value.trim(), opts = document.getElementById('product_list_options').options;
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value !== val) continue;
        var o = opts[i], pid = o.getAttribute('data-id');
        var dupRow = false;   // একই পণ্য আগেই অন্য রো-তে থাকলে আটকানো হবে
        document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(r) {
            if (r !== row && r.querySelector("input[name='product_id[]']").value === pid) dupRow = true;
        });
        if (dupRow) {
            alert('এই পণ্যটি আগেই যোগ করা হয়েছে। একই পণ্য দুইবার দেওয়া যাবে না, আগের লাইনে পরিমাণ বাড়ান।');
            input.value = '';
            row.querySelector("input[name='product_id[]']").value = '';
            row.querySelector('.grn-item-size').value = ''; row.querySelector('.grn-item-color').value = ''; row.querySelector('.grn-item-unit').value = '';
            row.querySelector('.grn-current-stock').value = '0.00';
            return;
        }
        row.querySelector("input[name='product_id[]']").value = pid;
        row.querySelector('.grn-item-size').value = o.getAttribute('data-size') || '';
        row.querySelector('.grn-item-color').value = o.getAttribute('data-color') || '';
        row.querySelector('.grn-item-unit').value = o.getAttribute('data-unit') || '';
        var rate = num(o.getAttribute('data-rate'));
        if (rate > 0) row.querySelector('.grn-rate').value = rate;
        row.setAttribute('data-boxpcs', num(o.getAttribute('data-boxpcs')) || 1);
        row.setAttribute('data-ratiopcs', num(o.getAttribute('data-ratiopcs')) || 1);
        row.setAttribute('data-ratiout', num(o.getAttribute('data-ratiout')) || 1);
        row.setAttribute('data-neg', o.getAttribute('data-neg') || '0');
        loadRowStock(row, pid);
        calculateQtyFromBox(row.querySelector('.grn-box'));
        return;
    }
}
function loadRowStock(row, pid) {
    post({ action: 'get_current_stock', product_id: pid }).then(function(d) {
        if (d.status === 'success') { row.querySelector('.grn-current-stock').value = d.stock; checkRowStock(row); }
    }).catch(function() { row.querySelector('.grn-current-stock').value = '0.00'; });
}
function checkRowStock(row) {
    var stock = num(row.querySelector('.grn-current-stock').value), qty = num(row.querySelector('.grn-qty').value);
    var bad = row.getAttribute('data-neg') !== '1' && qty > stock + 0.01;
    row.querySelector('.grn-qty').classList.toggle('low-stock', bad);
    row.querySelector('.grn-qty').title = bad ? 'স্টকের চেয়ে বেশি! সেভ করা যাবে না।' : '';
}

// ---- বক্স/পিস/SFT ----
function ratios(row) {
    return { bp: parseInt(row.getAttribute('data-boxpcs')) || 1, rp: parseFloat(row.getAttribute('data-ratiopcs')) || 1, ro: parseFloat(row.getAttribute('data-ratiout')) || 1 };
}
function calculateQtyFromBox(el) {
    var row = el.closest('tr'), r = ratios(row);
    var totalPcs = (parseInt(row.querySelector('.grn-box').value) || 0) * r.bp + (parseInt(row.querySelector('.grn-pcs').value) || 0);
    row.querySelector('.grn-qty').value = (totalPcs * (r.ro / r.rp)).toFixed(2);
    calculateAmount(row.querySelector('.grn-qty'));
}
function calculateBoxFromQty(el) {
    var row = el.closest('tr'), r = ratios(row);
    var qty = num(row.querySelector('.grn-qty').value);
    var totalPcs = Math.round(qty / (r.ro / r.rp));
    row.querySelector('.grn-box').value = Math.floor(totalPcs / r.bp);
    row.querySelector('.grn-pcs').value = totalPcs % r.bp;
    row.querySelector('.grn-qty').value = (totalPcs * (r.ro / r.rp)).toFixed(2);
    calculateAmount(row.querySelector('.grn-qty'));
}

// ---- হিসাব ----
function calculateAmount(el) {
    var row = el.closest('tr');
    row.querySelector('.grn-amount').value = (num(row.querySelector('.grn-qty').value) * num(row.querySelector('.grn-rate').value)).toFixed(2);
    checkRowStock(row);
    calculateTotalAmountAllRows();
}
function calculateTotalAmountAllRows() {
    var t = 0;
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(r) { t += num(r.querySelector('.grn-amount').value); });
    document.getElementById('sm_total_amount').value = t.toFixed(2);
    calculateSummary();
}
function calculateSummary() {
    var gross = num(document.getElementById('sm_total_amount').value) + num(document.getElementById('sm_labor').value) - num(document.getElementById('sm_discount').value);
    document.getElementById('sm_gross').value = gross.toFixed(2);
    document.getElementById('sm_net').value = (gross + num(document.getElementById('sm_prev_balance').value) - num(document.getElementById('sm_payment').value)).toFixed(2);
}
function discFromPercent() {
    document.getElementById('sm_discount').value = (num(document.getElementById('sm_total_amount').value) * num(document.getElementById('sm_discount_percent').value) / 100).toFixed(2);
    calculateSummary();
}
function percentFromDisc() {
    var t = num(document.getElementById('sm_total_amount').value);
    document.getElementById('sm_discount_percent').value = t > 0 ? (num(document.getElementById('sm_discount').value) * 100 / t).toFixed(2) : '0.00';
    calculateSummary();
}

// ---- পেমেন্ট লাইন (Cash / bKash / Bank ...) ----
function recalcPayment() {
    var t = 0;
    document.querySelectorAll('.pay-amt').forEach(function(i) { t += num(i.value); });
    document.getElementById('sm_payment').value = t.toFixed(2);
    calculateSummary();
}
// যে অ্যাকাউন্ট একটি লাইনে নেওয়া হয়েছে, অন্য লাইনের তালিকায় সেটি বন্ধ (disabled) থাকবে
function refreshPayOptions() {
    var sels = document.querySelectorAll('.pay-acc'), used = {};
    sels.forEach(function(s) { if (s.value) used[s.value] = true; });
    sels.forEach(function(s) {
        Array.prototype.forEach.call(s.options, function(o) { o.disabled = (o.value !== '' && o.value !== s.value && !!used[o.value]); });
    });
}
window.addEventListener('load', refreshPayOptions);
function addPayLine() {
    var box = document.getElementById('payLines'), n = box.querySelector('.pay-line').cloneNode(true);
    var used = {};
    box.querySelectorAll('.pay-acc').forEach(function(s) { if (s.value) used[s.value] = true; });
    var sel = n.querySelector('.pay-acc'), pick = null;
    Array.prototype.forEach.call(sel.options, function(o) { o.disabled = false; if (pick === null && o.value !== '' && !used[o.value]) pick = o.value; });
    if (pick === null) { alert('সব ক্যাশ/ব্যাংক/MFS অ্যাকাউন্টই ইতিমধ্যে ব্যবহার করা হয়েছে।'); return; }
    sel.value = pick;
    var rest = num(document.getElementById('sm_gross').value) - num(document.getElementById('sm_payment').value);
    n.querySelector('.pay-amt').value = rest > 0 ? rest.toFixed(2) : '';
    box.appendChild(n);
    refreshPayOptions();
    recalcPayment();
}
function removePayLine(btn) {
    var box = document.getElementById('payLines');
    if (box.querySelectorAll('.pay-line').length > 1) btn.closest('.pay-line').remove();
    else btn.closest('.pay-line').querySelector('.pay-amt').value = '';
    refreshPayOptions();
    recalcPayment();
}

// ---- রো ----
function addNewProductRow() {
    var tbody = document.querySelector('#grnItemsEntryTable tbody'), row = tbody.querySelector('tr').cloneNode(true);
    row.querySelectorAll('input').forEach(function(i) { if (!i.readOnly || i.type === 'hidden') i.value = ''; else i.value = ''; });
    row.querySelector('.grn-current-stock').value = '0.00';
    row.querySelector('.grn-qty').classList.remove('low-stock');
    ['data-boxpcs', 'data-ratiopcs', 'data-ratiout'].forEach(function(a) { row.setAttribute(a, '1'); });
    row.setAttribute('data-neg', '0');
    tbody.appendChild(row);
    var sc = document.querySelector('.items-scroll'); if (sc) sc.scrollTop = sc.scrollHeight;
}
function removeThisRow(btn) {
    if (document.querySelectorAll('#grnItemsEntryTable tbody tr').length > 1) { btn.closest('tr').remove(); calculateTotalAmountAllRows(); }
    else alert('কমপক্ষে একটি রো থাকতে হবে!');
}
function validateForm() {
    if (!document.getElementById('hidden_customer_id').value) { alert('অনুগ্রহ করে তালিকা থেকে একটি কাস্টমার নির্বাচন করুন!'); return false; }
    var ok = false;
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(r) {
        if (r.querySelector("input[name='product_id[]']").value && num(r.querySelector('.grn-qty').value) > 0) ok = true;
    });
    if (!ok) { alert('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!'); return false; }
    var seenP = {}, dupP = false;
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(r) {
        var p = r.querySelector("input[name='product_id[]']").value;
        if (p) { if (seenP[p]) dupP = true; seenP[p] = true; }
    });
    if (dupP) { alert('একই পণ্য একাধিকবার আছে। একটি লাইন রাখুন, আগের লাইনে পরিমাণ বাড়ান।'); return false; }
    var seenA = {}, dupA = false;
    document.querySelectorAll('.pay-line').forEach(function(l) {
        var a = l.querySelector('.pay-acc').value;
        if (num(l.querySelector('.pay-amt').value) > 0 && a) { if (seenA[a]) dupA = true; seenA[a] = true; }
    });
    if (dupA) { alert('একই ক্যাশ/ব্যাংক/MFS অ্যাকাউন্ট একাধিকবার আছে। একটি লাইনে মোট টাকা দিন।'); return false; }
    var payOk = true;
    document.querySelectorAll('.pay-line').forEach(function(l) {
        if (num(l.querySelector('.pay-amt').value) > 0 && !l.querySelector('.pay-acc').value) payOk = false;
    });
    if (!payOk) { alert('পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!'); return false; }

    // নেগেটিভ সেল অনুমোদিত পণ্যে স্টকের বেশি বিক্রি হলে কনফার্মেশন
    if (!window.negConfirmed) {
        var neg = [];
        document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(r) {
            r.classList.remove('neg-warn');
            var pid = r.querySelector("input[name='product_id[]']").value;
            var q = num(r.querySelector('.grn-qty').value), st = num(r.querySelector('.grn-current-stock').value);
            if (pid && r.getAttribute('data-neg') === '1' && q - st > 0.01) {
                neg.push({ row: r, name: r.querySelector('.grn-prod-input').value, short: q - st });
            }
        });
        if (neg.length) {
            var tb = document.getElementById('negList'); tb.innerHTML = '';
            neg.forEach(function(n) {
                n.row.classList.add('neg-warn');
                var tr = document.createElement('tr'), a = document.createElement('td'), b = document.createElement('td');
                a.textContent = n.name; a.style.fontWeight = 'bold';
                b.textContent = '-' + n.short.toFixed(2); b.style.cssText = 'text-align:right;color:#d32f2f;font-weight:bold;';
                tr.appendChild(a); tr.appendChild(b); tb.appendChild(tr);
            });
            document.getElementById('negYes').disabled = false;
            document.getElementById('negModal').style.display = 'block';
            document.getElementById('negNo').focus();   // Enter চাপলে "না" হবে, ভুলে সেভ হবে না
            return false;
        }
    }
    return true;
}

function negCancel() { document.getElementById('negModal').style.display = 'none'; }
function negProceed() {
    document.getElementById('negYes').disabled = true;   // ডাবল ক্লিক বন্ধ
    window.negConfirmed = true;
    var f = document.getElementById('invoiceForm');
    var h = document.createElement('input');
    h.type = 'hidden'; h.name = 'save_invoice'; h.value = '1';   // form.submit() এ বাটনের নাম যায় না, তাই হিডেন ইনপুট
    f.appendChild(h);
    f.submit();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('negModal').style.display === 'block') negCancel();
});

// ---- Enter চাপলে Save হবে না, পরের ঘরে যাবে ----
(function () {
    var form = document.getElementById('invoiceForm'), enterFlag = false;
    function editable(f) {
        return f.offsetParent !== null && !f.disabled && !f.readOnly && f.type !== 'hidden' && f.type !== 'button' && f.type !== 'submit' && f.tabIndex !== -1;
    }
    function focusNext(cur) {
        var all = Array.prototype.filter.call(form.querySelectorAll('input, select, textarea'), editable);
        var i = all.indexOf(cur);
        if (i < 0) return;
        // শেষ রো-এর Rate এ Enter: পণ্য ও পরিমাণ থাকলে নতুন রো খুলবে
        if (cur.classList.contains('grn-rate')) {
            var row = cur.closest('tr'), rows = document.querySelectorAll('#grnItemsEntryTable tbody tr');
            if (row === rows[rows.length - 1] && row.querySelector("input[name='product_id[]']").value && (parseFloat(row.querySelector('.grn-qty').value) || 0) > 0) {
                addNewProductRow();
                var nr = document.querySelectorAll('#grnItemsEntryTable tbody tr');
                var p = nr[nr.length - 1].querySelector('.grn-prod-input'); if (p) p.focus();
                return;
            }
        }
        var nx = all[i + 1];
        if (nx) { nx.focus(); if (nx.tagName === 'INPUT' && nx.type !== 'date' && nx.select) nx.select(); }
        else { var sb = document.querySelector('button[name=save_invoice]'); if (sb) sb.focus(); }   // শেষ ঘরের পর Save বাটনে
    }
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var t = e.target;
        if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON') return;
        if (t.tagName !== 'INPUT' && t.tagName !== 'SELECT') return;
        enterFlag = true; setTimeout(function () { enterFlag = false; }, 500);
        setTimeout(function () { focusNext(t); }, 0);   // datalist এর বাছাই শেষ হওয়ার পর
    }, true);
    form.addEventListener('submit', function (e) { if (enterFlag) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);
})();

// ---- ইভেন্ট ----
document.addEventListener('change', function(e) {
    var c = e.target.classList; if (!c) return;
    if (c.contains('grn-box') || c.contains('grn-pcs')) calculateQtyFromBox(e.target);
    else if (c.contains('grn-qty')) calculateBoxFromQty(e.target);
    else if (c.contains('pay-acc')) refreshPayOptions();
}, true);
document.addEventListener('input', function(e) {
    var c = e.target.classList, id = e.target.id;
    if (c && c.contains('grn-rate')) calculateAmount(e.target);
    else if (c && c.contains('pay-amt')) recalcPayment();
    else if (id === 'sm_discount_percent') discFromPercent();
    else if (id === 'sm_discount') percentFromDisc();
    else if (id === 'sm_labor' || id === 'sm_payment') calculateSummary();
}, true);
document.addEventListener('focus', function(e) {
    if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.type === 'text') && !e.target.readOnly) {
        setTimeout(function() { e.target.select(); }, 50);
    }
}, true);

window.addEventListener('load', function() {
    recalcPayment();
    document.querySelectorAll('#grnItemsEntryTable tbody tr').forEach(function(row) {
        var pid = row.querySelector("input[name='product_id[]']").value;
        if (pid) loadRowStock(row, pid);
    });
    calculateTotalAmountAllRows();
    percentFromDisc();
    loadPrevBalance();
});
</script>
</body>
</html>