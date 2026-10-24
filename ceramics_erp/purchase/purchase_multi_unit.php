<?php
// ১. ব্রাউজারে যেকোনো সুনির্দিষ্ট পিএইচপি এরর লাইভ দেখার কোর প্রোটোকল
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ২. ডাটাবেজ কানেকশন নিশ্চিত করা
include('../auth.php'); require_page_access(); include('../config.php'); include('../fund_lib.php');
$message = "";

// ===== FM (Factory Measurement) er jonno dorkari column (ekbar-i toiri hoy) =====
function pg_ensure_col($conn, $table, $col, $def) {
    try {
        $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        if ($r && mysqli_num_rows($r) == 0) mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
    } catch (Throwable $e) { error_log('pg_ensure_col: ' . $e->getMessage()); }
}
pg_ensure_col($conn, 'erp_products',     'fm_per_box', "DECIMAL(10,4) NOT NULL DEFAULT 0");
pg_ensure_col($conn, 'erp_stock_ledger', 'entry_unit', "VARCHAR(10) NOT NULL DEFAULT 'BASE'");
pg_ensure_col($conn, 'erp_stock_ledger', 'fm_qty',     "DECIMAL(14,4) NOT NULL DEFAULT 0");
pg_ensure_col($conn, 'erp_stock_ledger', 'fm_price',   "DECIMAL(14,4) NOT NULL DEFAULT 0");
try {   // stock rate (Amount / SFT) 4 doshomik porjonto rakhte hobe
    $rc = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM erp_stock_ledger LIKE 'rate'"));
    if ($rc && preg_match('/decimal\((\d+),(\d+)\)/i', $rc['Type'], $m) && (int)$m[2] < 4) {
        mysqli_query($conn, "ALTER TABLE erp_stock_ledger MODIFY rate DECIMAL(16,4) " . ($rc['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " DEFAULT 0");
    }
} catch (Throwable $e) { error_log('rate precision: ' . $e->getMessage()); }
function pg_rate_fmt($r) { $x = number_format((float)$r, 4, '.', ''); if (substr($x, -2) === '00') $x = substr($x, 0, -2); return $x; }

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_supplier'])) {
    $sup_name    = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $sup_mobile  = mysqli_real_escape_string($conn, $_POST['mobile_no']);
    $sup_address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    
    $sql_sup = "INSERT INTO erp_suppliers (supplier_name, mobile_no, address, status) VALUES ('$sup_name', '$sup_mobile', '$sup_address', 'Active')";
    if (mysqli_query($conn, $sql_sup)) { echo json_encode(["status" => "success", "id" => mysqli_insert_id($conn)]); } 
    else { echo json_encode(["status" => "error", "message" => mysqli_error($conn)]); }
    exit;
}

// অটোমেটিক ইউনিক GRN নম্বর জেনারেশন লজিক
$auto_query = "SELECT MAX(id) as max_id FROM erp_purchase_grn";
$auto_res   = mysqli_query($conn, $auto_query);
$auto_row   = mysqli_fetch_assoc($auto_res);
$next_id    = isset($auto_row['max_id']) ? ($auto_row['max_id'] + 1) : 1;
$generated_grn_no = "GRN-" . date('y') . str_pad($next_id, 4, "0", STR_PAD_LEFT);

// ৩. ডাটাবেজ টেবিল স্ট্রাকচারের সাথে হুবহু মিল রেখে তৈরি করা ফিক্সড সেভ পিএইচপি ইঞ্জিন
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_grn'])) {
    $grn_no          = mysqli_real_escape_string($conn, $_POST['grn_no']);
    $grn_date        = mysqli_real_escape_string($conn, $_POST['grn_date']);
    $supplier_id     = (int)$_POST['supplier_id'];
    $branch_name     = mysqli_real_escape_string($conn, $_POST['branch_name']);
    $remarks         = mysqli_real_escape_string($conn, $_POST['remarks']);
    
    // ফর্ম থেকে আসা গাণিতিক মানগুলো রিড করা
    $labor_charge    = floatval($_POST['labor_charge'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $payment_amount  = 0;   // নিচে পেমেন্ট লাইনগুলোর যোগফল থেকে বসবে
    $net_balance     = floatval($_POST['net_balance'] ?? 0); // আপনার ডাটাবেজের জেনুইন একটিভ কলাম

    // একই পণ্য দুইবার নয় + পেমেন্ট থাকলে অ্যাকাউন্ট আবশ্যক
    $fund_ids = array_map('intval', array_column(fl_fund_accounts($conn), 'id'));
    $vmsg = ''; $seen = [];
    // একাধিক পেমেন্ট লাইন; অ্যাকাউন্ট Chart of Accounts এর ফান্ড হেড থেকে
    $pay_lines = []; $acc_seen = [];
    foreach (($_POST['pay_amount'] ?? []) as $i => $pamt) {
        $pamt = round(floatval($pamt), 2); $pacc = (int)($_POST['pay_account'][$i] ?? 0);
        if ($pamt <= 0) continue;
        if (!in_array($pacc, $fund_ids, true)) { $vmsg = 'পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!'; break; }
        if (isset($acc_seen[$pacc])) { $vmsg = 'একই ক্যাশ/অ্যাকাউন্ট একাধিকবার দেওয়া যাবে না — ওই লাইনেই পরিমাণ বাড়ান!'; break; }
        $acc_seen[$pacc] = 1;
        $pay_lines[] = ['acc' => $pacc, 'amt' => $pamt];
    }
    $payment_amount = round(array_sum(array_column($pay_lines, 'amt')), 2);
    foreach (($_POST['product_id'] ?? []) as $pp) {
        $pp = (int)$pp;
        if ($pp > 0) { if (isset($seen[$pp])) $vmsg = 'একই পণ্য একাধিকবার দেওয়া যাবে না — একটি সারিতে মোট পরিমাণ দিন!'; $seen[$pp] = 1; }
    }
    // FM unit dile product e "1 box = koto FM" set thaka dorkar
    foreach (($_POST['product_id'] ?? []) as $i => $pp) {
        $pp = (int)$pp;
        if ($pp > 0 && (($_POST['entry_unit'][$i] ?? 'BASE') === 'FM') && $vmsg === '') {
            $fr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name, fm_per_box FROM erp_products WHERE id=$pp"));
            if (!$fr || (float)$fr['fm_per_box'] <= 0) $vmsg = 'এই পণ্যের জন্য Multi-Unit এ "১ বক্স = কত FM" সেট করা নেই: ' . ($fr['item_name'] ?? '');
        }
    }
    // ওয়্যারহাউজ যাচাই; branch warehouse থেকেই নেওয়া হয়
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $wrow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.id=$warehouse_id" . branch_wh_sql('w.id')));
    if (!$wrow) { if ($vmsg === '') $vmsg = 'অনুগ্রহ করে একটি ওয়্যারহাউজ নির্বাচন করুন!'; }
    else { $branch_name = mysqli_real_escape_string($conn, $wrow['branch_name']); }
    if ($vmsg !== '') { echo "<script>alert(" . json_encode($vmsg) . "); history.back();</script>"; exit; }

    // গার্ড: এডিটে GRN নম্বর DB থেকে নেওয়া হয় (ফর্মের মান নয়) ও ব্রাঞ্চ যাচাই; নতুনে নম্বর আগে ব্যবহার হয়নি
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        $gx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grn_no, branch_name FROM erp_purchase_grn WHERE id=" . (int)$_GET['id']));
        if (!$gx) { echo "<script>alert('GRN পাওয়া যায়নি!'); window.location.href='purchase_multi_unit.php';</script>"; exit; }
        branch_guard($gx['branch_name'] ?? '');
        $grn_no = mysqli_real_escape_string($conn, $gx['grn_no']);
    } else {
        $gd = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_grn WHERE grn_no='$grn_no'"));
        if ($gd['c'] > 0) { echo "<script>alert('এই GRN নম্বর আগে থেকেই আছে, পেজ রিফ্রেশ করে আবার চেষ্টা করুন।'); window.location.href='purchase_multi_unit.php';</script>"; exit; }
    }

    // Purchase Return thaka GRN edit kora jabe na
    if (isset($_GET['action']) && $_GET['action'] === 'edit') {
        try {
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='$grn_no'"));
            if ($chk['c'] > 0) { echo "<script>alert('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।'); window.location.href='purchase_multi_unit.php';</script>"; exit; }
        } catch (Throwable $e) { /* return table nai */ }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        $update_id = (int)$_GET['id'];
        // এডিট আপডেটে ডাটাবেজ কলামের নিখুঁত রিম্যাপ প্রোটোকল
        $query = "UPDATE erp_purchase_grn SET grn_date='$grn_date', supplier_id=$supplier_id, branch_name='$branch_name', warehouse_id=$warehouse_id, net_balance='$net_balance', labor_charge='$labor_charge', discount_amount='$discount_amount', payment_amount='$payment_amount', remarks='$remarks' WHERE id=$update_id";
    } else {
        // নতুন ইনসার্ট কুয়েরিতে আপনার ডাটাবেজ টেবিলের ৯টি জেনুইন একটিভ কলাম ও ভ্যালু সিঙ্ক
        $query = "INSERT INTO erp_purchase_grn (grn_no, grn_date, supplier_id, branch_name, warehouse_id, net_balance, labor_charge, discount_amount, payment_amount, remarks) 
                  VALUES ('$grn_no', '$grn_date', $supplier_id, '$branch_name', $warehouse_id, '$net_balance', '$labor_charge', '$discount_amount', '$payment_amount', '$remarks')";
    }
    
  if (mysqli_query($conn, $query)) {
    // এডিট হলে পুরনো লেজার এন্ট্রি আগে মুছে ফেলা
    mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$grn_no' AND voucher_type='PURCHASE'");

    $items_total = 0;
    if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
            for ($i = 0; $i < count($_POST['product_id']); $i++) {
                $p_id = (int)$_POST['product_id'][$i];
                if ($p_id > 0) {
                    $box_in = floatval($_POST['box_in'][$i] ?? 0);
                    $pcs_in = floatval($_POST['pcs_in'][$i] ?? 0);
                    $qty_in = floatval($_POST['qty_in'][$i] ?? 0);
                    $unit   = (($_POST['entry_unit'][$i] ?? 'BASE') === 'FM') ? 'FM' : 'BASE';
                    $fm_qty = 0; $fm_price = 0;
                    if ($unit === 'FM') {
                        // FM hishab: Amount = FM Qty x FM Price; stock rate = Amount / total SFT
                        $fm_qty   = floatval($_POST['fm_qty'][$i] ?? 0);
                        $fm_price = floatval($_POST['fm_price'][$i] ?? 0);
                        $amt  = round($fm_qty * $fm_price, 2);
                        $rate = $qty_in > 0 ? $amt / $qty_in : 0;
                    } else {
                        $rate = floatval($_POST['purchase_rate'][$i] ?? 0);
                        $amt  = $qty_in * $rate;
                    }
                    $items_total += $amt;
                    $rate_s = number_format($rate, 6, '.', ''); $amt_s = number_format($amt, 4, '.', '');

                    $ledger_sql = "INSERT INTO erp_stock_ledger (product_id, warehouse_id, voucher_type, voucher_no, box_in, pcs_in, sqft_in, rate, total_value, entry_unit, fm_qty, fm_price) VALUES ($p_id, $warehouse_id, 'PURCHASE', '$grn_no', $box_in, $pcs_in, $qty_in, $rate_s, $amt_s, '$unit', $fm_qty, $fm_price)";
                    mysqli_query($conn, $ledger_sql);
                }
            }
        }
        // পেমেন্ট → ফান্ড লেজার (OUT); এডিটে আগের এন্ট্রি মুছে নতুন করে
        // ref_type = 'PAYMENT' (GRN Factory পেজের মতোই) যাতে Accounts এ Dr Payable / Cr Cash-Bank-MFS হয়; আগের 'GRN' টাইপও মুছে নতুন করে
        $gL = mysqli_real_escape_string($conn, $grn_no);
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type IN ('PAYMENT','GRN') AND ref_no='$gL'");
        foreach ($pay_lines as $pl) {
            mysqli_query($conn, "INSERT INTO erp_fund_ledger (txn_date, account_id, txn_type, amount, ref_type, ref_no, created_by)
                                 VALUES ('$grn_date', {$pl['acc']}, 'OUT', {$pl['amt']}, 'PAYMENT', '$gL', " . (int)$_SESSION['user_id'] . ")");
        }
        // ===== Accounts auto-posting: Dr Inventory (1201) / Cr Payable (2001) =====
        try {
            $jt = $items_total;
            $jgross = round($jt + $labor_charge - $discount_amount, 2);
            if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
            include_once('../Accounts/accounts_lib.php');
            post_journal($conn, 'PURCHASE', $grn_no, $grn_date, "Purchase GRN " . $grn_no, [
                ['1201', $jgross, 0],
                ['2001', 0, $jgross, $supplier_id]
            ]);
            acc_sync_fund_journal($conn);
        } catch (Throwable $e) { error_log('GRN journal: ' . $e->getMessage()); }
        $message = "<div class='alert success'>জিআরএন ভাউচার সফলভাবে সংরক্ষিত ও লাইভ স্টক আপডেট হয়েছে।</div>";
        echo "<script>setTimeout(function(){ window.location.href='purchase_multi_unit.php'; }, 1000);</script>";
    } else {
        $message = "<div class='alert error'>ডাটাবেজ এরর: " . mysqli_error($conn) . "</div>";
    }
}



// ডিলিট অ্যাকশন মেথড
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    $grn_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grn_no FROM erp_purchase_grn WHERE id=$delete_id" . branch_sql('branch_name')));
    if ($grn_row) {
        $g_no = $grn_row['grn_no'];
        try {   // Purchase Return thaka GRN delete kora jabe na
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='" . mysqli_real_escape_string($conn, $g_no) . "'"));
            if ($chk['c'] > 0) { echo "<script>alert('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।'); window.location.href='purchase_multi_unit.php';</script>"; exit; }
        } catch (Throwable $e) { /* return table nai */ }
        mysqli_query($conn, "DELETE FROM erp_purchase_grn WHERE id=$delete_id");
        mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no='$g_no' AND voucher_type='PURCHASE'");
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type IN ('PAYMENT','GRN') AND ref_no='" . mysqli_real_escape_string($conn, $g_no) . "'");
        try {
            if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
            include_once('../Accounts/accounts_lib.php');
            delete_journal($conn, 'PURCHASE', $g_no);
            acc_sync_fund_journal($conn);
        } catch (Throwable $e) { error_log('GRN journal cleanup: ' . $e->getMessage()); }
        echo "<script>alert('GRN রেকর্ডটি সফলভাবে মুছে ফেলা হয়েছে।'); window.location.href='purchase_multi_unit.php';</script>";
        exit;
    }
}

// এডিট মোডে ওল্ড ডাটা রিডার কুয়েরি
$edit_data = []; $edit_items_result = false; $pay_rows = []; $fund_accounts = fl_fund_accounts($conn);
$sel_account = 0;   // ডিফল্ট পেমেন্ট অ্যাকাউন্ট: Cash in Hand
foreach ($fund_accounts as $a) { if (stripos($a['account_name'], 'cash in hand') !== false) { $sel_account = (int)$a['id']; break; } }
if (!$sel_account && $fund_accounts) $sel_account = (int)$fund_accounts[0]['id'];
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    $edit_res = mysqli_query($conn, "SELECT * FROM erp_purchase_grn WHERE id = $edit_id");
    if ($edit_res && mysqli_num_rows($edit_res) > 0) {
        $edit_data = mysqli_fetch_assoc($edit_res);
        branch_guard($edit_data['branch_name'] ?? '');
        $g_no = $edit_data['grn_no'];
        try {   // Edit form kholar age-i block
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_purchase_return WHERE grn_no='" . mysqli_real_escape_string($conn, $g_no) . "'"));
            if ($chk['c'] > 0) { echo "<script>alert('এই GRN এ Purchase Return আছে, আগে Return ডিলিট করুন।'); window.location.href='purchase_multi_unit.php';</script>"; exit; }
        } catch (Throwable $e) { /* return table nai */ }
        $s2 = mysqli_query($conn, "SELECT account_id, amount FROM erp_fund_ledger WHERE ref_type IN ('PAYMENT','GRN') AND ref_no='" . mysqli_real_escape_string($conn, $g_no) . "' ORDER BY id ASC");
        while ($s2 && $x = mysqli_fetch_assoc($s2)) $pay_rows[] = ['acc' => (int)$x['account_id'], 'amt' => (float)$x['amount']];
        if (!$pay_rows && (float)$edit_data['payment_amount'] > 0) $pay_rows[] = ['acc' => $sel_account, 'amt' => (float)$edit_data['payment_amount']];
        $ledger_sql = "SELECT l.*, p.item_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output, p.fm_per_box, p.size_name, p.color_name, p.unit_name FROM erp_stock_ledger l JOIN erp_products p ON l.product_id = p.id WHERE l.voucher_no='$g_no' AND l.voucher_type='PURCHASE' ORDER BY l.id ASC";
        $edit_items_result = mysqli_query($conn, $ledger_sql);
    }
}

// অল সাপ্লায়ার এবং প্রোডাক্টের মূল কুয়েরি তালিকা (সংশোধিত কলাম সিঙ্ক)
if (!$pay_rows) $pay_rows[] = ['acc' => $sel_account, 'amt' => ''];
$supplier_res = mysqli_query($conn, "SELECT id, supplier_name, mobile_no, address FROM erp_suppliers ORDER BY supplier_name ASC");
$branch_res   = mysqli_query($conn, "SELECT DISTINCT branch_name FROM erp_branches WHERE status='Active'" . branch_sql('branch_name')) ?: mysqli_query($conn, "SELECT 'Main Warehouse' AS branch_name");
$warehouses = [];
$wq = mysqli_query($conn, "SELECT w.id, w.warehouse_name, b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.status='Active'" . branch_wh_sql('w.id') . " ORDER BY b.branch_name, w.warehouse_name");
while ($wq && $w = mysqli_fetch_assoc($wq)) $warehouses[] = $w;
$product_res  = mysqli_query($conn, "SELECT id, item_name, cost_price, unit_name, size_name, color_name, box_to_pcs, ratio_pcs, ratio_output, fm_per_box FROM erp_products ORDER BY item_name ASC");
$fm_map = [];
if ($product_res) { while ($fp = mysqli_fetch_assoc($product_res)) { if ((float)$fp['fm_per_box'] > 0) $fm_map[(int)$fp['id']] = (float)$fp['fm_per_box']; } mysqli_data_seek($product_res, 0); }
$grn_has_fm = false;
if ($edit_items_result) { while ($fx = mysqli_fetch_assoc($edit_items_result)) { if (($fx['entry_unit'] ?? '') === 'FM') $grn_has_fm = true; } mysqli_data_seek($edit_items_result, 0); }

$grn_sql = "SELECT g.id, g.grn_no, g.grn_date, g.branch_name, g.labor_charge, g.discount_amount, g.payment_amount, s.supplier_name, IFNULL(t.total,0) AS items_total
    FROM erp_purchase_grn g
    LEFT JOIN erp_suppliers s ON s.id = g.supplier_id
    LEFT JOIN (SELECT voucher_no, SUM(IF(total_value > 0, total_value, sqft_in * rate)) AS total FROM erp_stock_ledger WHERE voucher_type='PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
    WHERE 1=1" . branch_sql('g.branch_name') . " ORDER BY g.id DESC";
$grn_result = mysqli_query($conn, $grn_sql);
// তালিকার সারি + গ্র্যান্ড টোটাল (Total = আইটেম + লেবার - ডিসকাউন্ট; Due = Total - Paid)
$grn_rows = []; $sum_total = 0; $sum_paid = 0; $sum_due = 0;
while ($grn_result && $r = mysqli_fetch_assoc($grn_result)) {
    $total = (float)$r['items_total'] + (float)$r['labor_charge'] - (float)$r['discount_amount'];
    $paid  = (float)$r['payment_amount']; $due = $total - $paid;
    $grn_rows[] = ['id' => (int)$r['id'], 'no' => $r['grn_no'], 'date' => $r['grn_date'], 'sup' => $r['supplier_name'] ?? 'N/A', 'branch' => $r['branch_name'] ?? '-', 'total' => $total, 'paid' => $paid, 'due' => $due];
    $sum_total += $total; $sum_paid += $paid; $sum_due += $due;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cloudflare.com">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
        .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
        .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
        .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 20px; }
        .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #ced4da; }
        .panel-body { padding: 15px; }
        .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
        .filter-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px 15px; margin-bottom: 12px; }
        .filter-grid .form-group label { font-size: 13px; font-weight: bold; color: #333; }
        .list-table td { padding: 8px; }
        .list-table tbody tr:hover td { background: #f1f5f9; }
        .list-table tr.selected-row td { background: #e0f2fe !important; }
        .r { text-align: right; }
        .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
        .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 15px; background: #ffffff; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 3px; position: relative; }
        .form-control { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; outline: none; width: 100%; box-sizing: border-box; }
        .flex-row { display: flex; gap: 5px; align-items: center; }
        .btn-plus { height: 28px; width: 28px; background: #28a745; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .summary-wrapper { display: flex; justify-content: flex-end; margin-top: 15px; width: 100%; }
        .summary-box { width: 350px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; font-size: 12px; box-sizing: border-box; }
    /* ডান পাশে ফিক্সড সামারি বক্স (Sales Invoice এর মতো) */
    .inv-layout { display: flex; gap: 15px; align-items: flex-start; }
    .inv-left { flex: 1; min-width: 0; }
    .inv-right { width: 400px; flex-shrink: 0; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow-y: auto; overflow-x: hidden; }
    .inv-right .summary-box { width: 100%; box-sizing: border-box; }
    .items-scroll { max-height: calc(100vh - 430px); min-height: 170px; overflow: auto; border: 1px solid #ccdc39; background: #fff; }
    .items-scroll .grid-table { margin-top: 0; border: none; }
    .items-scroll thead th { position: sticky; top: 0; z-index: 2; box-shadow: 0 1px 0 #ccdc39; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .summary-row label { font-weight: bold; color: #444; }
        .summary-row input { width: 120px; text-align: right; height: 24px; padding: 0 5px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .grid-table { width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #cddc39; margin-top: 10px; }
        .grid-table th { background-color: #dbebe7; color: #222; padding: 6px; text-align: left; font-weight: bold; border: 1px solid #cddc39; }
        .grid-table td { padding: 5px 6px; border: 1px solid #dee2e6; background-color: #fff; }
        .selected-row td { background-color: #bddeb6 !important; }
        .sup-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); padding-top: 100px; box-sizing: border-box; }
        .sup-modal-content { background-color: #fff; margin: auto; padding: 20px; border: 1px solid #cddc39; width: 400px; border-radius: 5px; }
            #supplier_search { font-size: 15px; font-weight: 600; height: 36px; }
        #sup_meta_card { font-size: 12px !important; }
        /* column width fixed; product name column baki jayga pabe, choto hole table scroll hobe */
        #grnItemEntryTable { table-layout: fixed; min-width: 900px; }
        #grnItemEntryTable.has-fm { min-width: 1060px; }
        /* number ghor e up/down arrow lagbe na */
        #grnItemEntryTable input[type=number]::-webkit-outer-spin-button,
        #grnItemEntryTable input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        #grnItemEntryTable input[type=number] { -moz-appearance: textfield; appearance: textfield; }
        #grnItemEntryTable .form-control { padding: 0 4px; }
        #grnItemEntryTable td, #grnItemEntryTable th { padding-left: 4px; padding-right: 4px; }
        #grnItemEntryTable td, #grnItemEntryTable th { overflow: hidden; }
        #grnItemEntryTable:not(.has-fm) .fm-col { display: none; }
        #grnItemEntryTable .fm-ro { background: #f1f3f5; color: #9aa0a6; }
        #grnItemEntryTable select.grn-unit-sel { padding: 0 2px; }
    </style>
</head>
<body>
<?php if(!empty($message)) echo $message; ?>

<div class="control-bar" id="actionGridButtons" style="display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'none' : 'flex'; ?>;">
    <button class="btn-action" onclick="showForm()"><i class="fa-solid fa-plus" style="color: #28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color: #3498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color: #e74c3c;"></i> Delete</button>
    <button class="btn-action" style="background:#2b579a; color:#fff; border:none;" onclick="printSelected()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="form-panel" id="productFormPanel" style="display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'block' : 'none'; ?>;">
    <form method="POST" action="" id="grnMainForm" onsubmit="return grnValidate()">
        <div class="control-bar" style="margin-bottom: 0; border-bottom: none; border-radius: 4px 4px 0 0;">
            <button type="submit" name="save_grn" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color: #28a745;"></i> Save GRN</button>
            <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color: #e74c3c;"></i> Close</button>
        </div>
        <div class="panel-header">MATERIAL RECEIPT (GRN) - MULTI UNIT ENTRY</div>
        <div class="panel-body">
            <div class="excel-grid">
                <div class="form-group"><label>GRN Number (Auto)</label><input type="text" name="grn_no" class="form-control" readonly value="<?php echo isset($edit_data['grn_no']) ? htmlspecialchars($edit_data['grn_no']) : $generated_grn_no; ?>"></div>
                <div class="form-group"><label>GRN Date</label><input type="date" name="grn_date" class="form-control" required value="<?php echo isset($edit_data['grn_date']) ? $edit_data['grn_date'] : date('Y-m-d'); ?>"></div>
                <div class="form-group"><label>Branch</label><select name="branch_name" id="branch_sel" onchange="filterWarehouses()" class="form-control" required><?php mysqli_data_seek($branch_res, 0); while($b = mysqli_fetch_assoc($branch_res)) { $sel = (isset($edit_data['branch_name']) && $edit_data['branch_name'] == $b['branch_name']) ? 'selected' : ''; echo "<option value='".$b['branch_name']."' $sel>".$b['branch_name']."</option>"; } ?></select></div>
                
                <div class="form-group"><label>Warehouse / Godown</label>
                    <select name="warehouse_id" id="warehouse_sel" class="form-control" required>
                        <?php foreach ($warehouses as $w) { ?><option value="<?php echo (int)$w['id']; ?>" data-branch="<?php echo htmlspecialchars($w['branch_name']); ?>" <?php if (isset($edit_data['warehouse_id']) && (int)$edit_data['warehouse_id'] === (int)$w['id']) echo 'selected'; ?>><?php echo htmlspecialchars($w['warehouse_name']); ?></option><?php } ?>
                    </select></div>
                <!-- আপনার আসল ফিক্স: ডাটাবেজের সম্পূর্ণ সাপ্লায়ার তালিকা লোড করার নিখুঁত ডাটালিস্ট লুপ -->
                <div class="form-group">
                    <label>Supplier (Search Box)</label>
                    <div class="flex-row">
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list_options" placeholder="Type to search supplier.." onchange="syncSupplierSelection()" value="<?php echo isset($edit_data['supplier_id']) ? htmlspecialchars($edit_data['supplier_name'] ?? '') : ''; ?>">
                        <datalist id="supplier_list_options">
                            <?php if($supplier_res) { mysqli_data_seek($supplier_res, 0); while($s = mysqli_fetch_assoc($supplier_res)) { 
                                $combo = htmlspecialchars($s['supplier_name']) . " [ " . htmlspecialchars($s['address'] ?? 'No Address') . " - " . htmlspecialchars($s['mobile_no']) . " ]";
                                echo "<option data-id='".$s['id']."' data-mobile='".htmlspecialchars($s['mobile_no'])."' data-address='".htmlspecialchars($s['address'])."' value='".$combo."'></option>"; 
                            }} ?>
                        </datalist>
                        <input type="hidden" name="supplier_id" id="hidden_supplier_id" value="<?php echo $edit_data['supplier_id'] ?? ''; ?>">
                        <button type="button" class="btn-plus" onclick="openSupplierModal()">+</button>
                    </div>
                    <div id="sup_meta_card" style="font-size: 11px; color: #4050b3; margin-top: 4px; font-weight: bold;"></div>
                </div>
                <div class="form-group"><label>Challan / Invoice No</label><input type="text" name="challan_no" class="form-control" placeholder="e.g. CH-2291" value="<?php echo isset($edit_data['challan_no']) ? htmlspecialchars($edit_data['challan_no']) : ''; ?>"></div>
            </div>
            <div class="inv-layout"><div class="inv-left">
            <div style="border-top: 1px dashed #cbd5e1; padding-top: 10px; margin-top: 10px;">
                <label style="font-size: 12px; font-weight: bold; color: #2e7d32;"><i class="fa-solid fa-boxes-stacked"></i> Received Item Details</label>
                <div class="items-scroll">
                <table class="grid-table<?php echo $grn_has_fm ? ' has-fm' : ''; ?>" id="grnItemEntryTable">
                    <thead>
                        <tr>
                            <th style="min-width: 260px;">Select Product Name</th><th style="width: 64px;">Size</th><th style="width: 64px;">Color</th><th style="width: 66px;">Unit</th><th style="text-align: right; width: 70px;">Current Stock</th><th style="text-align: right; width: 52px;">Box</th><th style="text-align: right; width: 52px;">Pcs</th><th style="text-align: right; width: 82px;">QTY (SFT)</th><th class="fm-col" style="text-align: right; width: 78px;">FM Qty</th><th class="fm-col" style="text-align: right; width: 70px;">FM Price</th><th style="text-align: right; width: 78px;" title="FM হলে: Amount ÷ SFT">Rate (SFT)</th><th style="text-align: right; width: 92px;">Amount</th><th style="width: 56px; text-align: center;">Act</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (isset($edit_items_result) && $edit_items_result && mysqli_num_rows($edit_items_result) > 0) {
                            mysqli_data_seek($edit_items_result, 0);
                            while ($item = mysqli_fetch_assoc($edit_items_result)) { 
                                $is_fm = (($item['entry_unit'] ?? '') === 'FM');
                                $i_amt = ((float)($item['total_value'] ?? 0) > 0) ? (float)$item['total_value'] : (float)$item['sqft_in'] * (float)$item['rate'];
                        ?>
                        <tr class="grn-item-row" data-boxpcs="<?php echo $item['box_to_pcs']; ?>" data-ratiopcs="<?php echo $item['ratio_pcs']; ?>" data-ratioout="<?php echo $item['ratio_output']; ?>" data-fmbox="<?php echo (float)($item['fm_per_box'] ?? 0); ?>">
                            <td><input type="text" class="form-control grn-prod-input" list="product_list_options" placeholder="Type product name to search.." onchange="syncProductSelection(this)" value="<?php echo htmlspecialchars($item['item_name']); ?>"><input type="hidden" name="product_id[]" class="hidden_product_id" value="<?php echo $item['product_id']; ?>"></td>
                            <td><input type="text" class="form-control grn-item-size" readonly value="<?php echo htmlspecialchars($item['size_name'] ?? ''); ?>"></td>
                            <td><input type="text" class="form-control grn-item-color" readonly value="<?php echo htmlspecialchars($item['color_name'] ?? ''); ?>"></td>
                            <td><select name="entry_unit[]" class="form-control grn-unit-sel" onchange="onUnitChange(this)"><option value="BASE"><?php echo htmlspecialchars($item['unit_name'] ?: 'SFT'); ?></option><option value="FM" <?php echo $is_fm ? 'selected' : ''; ?>>FM</option></select></td>
                            <td><input type="text" class="form-control grn-current-stock" style="text-align: right;" readonly value="0.00"></td>
                            <td><input type="number" name="box_in[]" class="form-control grn-box" style="text-align: right;" step="1" onfocus="this.select()" oninput="calculateQtyFromBox(this)" value="<?php echo (int)$item['box_in']; ?>"></td>
                            <td><input type="number" name="pcs_in[]" class="form-control grn-pcs" style="text-align: right;" step="1" onfocus="this.select()" oninput="calculateQtyFromBox(this)" value="<?php echo (int)$item['pcs_in']; ?>"></td>
                            <td><input type="number" name="qty_in[]" class="form-control grn-qty" style="text-align: right;" step="0.01" required onfocus="this.select()" oninput="calculateBoxFromQty(this)" value="<?php echo number_format($item['sqft_in'], 2, '.', ''); ?>"></td>
                            <td class="fm-col"><input type="number" name="fm_qty[]" class="form-control grn-fm-qty<?php echo $is_fm ? '' : ' fm-ro'; ?>" style="text-align: right;" step="0.01" onfocus="this.select()" oninput="calculateFromFm(this)" <?php echo $is_fm ? '' : 'readonly'; ?> value="<?php echo number_format($is_fm ? (float)$item['fm_qty'] : 0, 2, '.', ''); ?>"></td>
                            <td class="fm-col"><input type="number" name="fm_price[]" class="form-control grn-fm-price<?php echo $is_fm ? '' : ' fm-ro'; ?>" style="text-align: right;" step="any" onfocus="this.select()" oninput="calculateAmount(this)" <?php echo $is_fm ? '' : 'readonly'; ?> value="<?php echo number_format($is_fm ? (float)$item['fm_price'] : 0, 2, '.', ''); ?>"></td>
                            <td><input type="number" name="purchase_rate[]" class="form-control grn-rate<?php echo $is_fm ? ' fm-ro' : ''; ?>" style="text-align: right;" step="any" required onfocus="this.select()" oninput="calculateAmount(this)" <?php echo $is_fm ? 'readonly' : ''; ?> value="<?php echo pg_rate_fmt($item['rate']); ?>"></td>
                            <td><input type="number" class="form-control grn-amount" style="text-align: right; font-weight: bold; background: #fdfbe7;" readonly value="<?php echo number_format($i_amt, 2, '.', ''); ?>"></td>
                            <td style="text-align: center;"><button type="button" style="background: #dc3545; color: white; border: none; padding: 2px 8px; border-radius: 3px; font-size: 11px;" onclick="removeThisRow(this)">Delete</button></td>
                        </tr>
                        <?php } } else { ?>
                        <tr class="grn-item-row" data-boxpcs="0" data-ratiopcs="0" data-ratioout="0" data-fmbox="0">
                            <td>
                                <input type="text" class="form-control grn-prod-input" list="product_list_options" placeholder="Type product name to search.." onchange="syncProductSelection(this)">
                                <input type="hidden" name="product_id[]" class="hidden_product_id">
                            </td>
                            <td><input type="text" class="form-control grn-item-size" readonly></td>
                            <td><input type="text" class="form-control grn-item-color" readonly></td>
                            <td><select name="entry_unit[]" class="form-control grn-unit-sel" onchange="onUnitChange(this)"><option value="BASE">Unit</option><option value="FM" disabled>FM</option></select></td>
                            <td><input type="text" class="form-control grn-current-stock" style="text-align: right;" readonly value="0.00"></td>
                            <td><input type="number" name="box_in[]" class="form-control grn-box" style="text-align: right;" step="1" onfocus="this.select()" oninput="calculateQtyFromBox(this)" value="0"></td>
                            <td><input type="number" name="pcs_in[]" class="form-control grn-pcs" style="text-align: right;" step="1" onfocus="this.select()" oninput="calculateQtyFromBox(this)" value="0"></td>
                            <td><input type="number" name="qty_in[]" class="form-control grn-qty" style="text-align: right;" step="0.01" required onfocus="this.select()" oninput="calculateBoxFromQty(this)" value="0.00"></td>
                            <td class="fm-col"><input type="number" name="fm_qty[]" class="form-control grn-fm-qty fm-ro" style="text-align: right;" step="0.01" onfocus="this.select()" oninput="calculateFromFm(this)" readonly value="0.00"></td>
                            <td class="fm-col"><input type="number" name="fm_price[]" class="form-control grn-fm-price fm-ro" style="text-align: right;" step="any" onfocus="this.select()" oninput="calculateAmount(this)" readonly value="0.00"></td>
                            <td><input type="number" name="purchase_rate[]" class="form-control grn-rate" style="text-align: right;" step="any" required onfocus="this.select()" oninput="calculateAmount(this)" value="0.00"></td>
                            <td><input type="number" class="form-control grn-amount" style="text-align: right; font-weight: bold; background: #fdfbe7;" readonly value="0.00"></td>
                            <td style="text-align: center;"><button type="button" style="background: #dc3545; color: white; border: none; padding: 2px 8px; border-radius: 3px; font-size: 11px;" onclick="removeThisRow(this)">Delete</button></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                </div>
                <datalist id="product_list_options"><?php if ($product_res) { mysqli_data_seek($product_res, 0); while ($pp2 = mysqli_fetch_assoc($product_res)) { echo "<option value='" . htmlspecialchars($pp2['item_name']) . " '></option>"; } } ?></datalist>
                <div class="row-action-bar" style="margin-top: 8px;"><button type="button" class="btn-action" style="background: #28a745; color: white;" onclick="addNewProductRow()"><i class="fa-solid fa-plus"></i> Add Row</button></div>
            </div>
            <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 3px;"><label style="font-size: 11px; font-weight: bold; color: #444;">Remarks / Internal Note</label><input type="text" name="remarks" class="form-control" placeholder="Narration here..." value="<?php echo isset($edit_data['remarks']) ? htmlspecialchars($edit_data['remarks']) : ''; ?>"></div>
            </div><!-- /inv-left -->
            <div class="inv-right">
                <div class="summary-box">
                    <div class="summary-row"><label>Total Amount</label><input type="number" id="sm_total_amount" class="form-control" readonly value="0.00"></div>
                    <div class="summary-row"><label>Labor / Other Charge (+)</label><input type="number" name="labor_charge" id="sm_labor" class="form-control" step="0.01" onfocus="this.select()" oninput="calculateSummary()" value="0.00"></div>
                    <div class="summary-row"><label>Discount Amount (-)</label><div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end; width: 100%;"><input type="number" id="sm_disc_percent" class="form-control" style="width: 45px; text-align: center; background: #fffdf0; padding: 0 2px;" placeholder="%" step="0.01" onfocus="this.select()" oninput="calculateSummary()"><input type="number" name="discount_amount" id="sm_disc_amount" class="form-control" style="width: 120px;" step="0.01" onfocus="this.select()" oninput="calculateSummary()" value="0.00"></div></div>
                    <div class="summary-row" style="border-top: 1px dashed #ccc; padding-top: 4px;"><label style="color: #2e7d32;">Gross Total</label><input type="number" id="sm_gross_total" class="form-control" readonly style="font-weight: bold;" value="0.00"></div>
                    <div class="summary-row"><label>Previous Balance</label><input type="number" id="sm_prev_balance" class="form-control" readonly value="0.00"></div>
                    <div class="summary-row"><label>Payment Amount (-)</label><input type="number" id="sm_payment" class="form-control" readonly value="0.00" title="সব পেমেন্ট লাইনের যোগফল"></div>
                    <div id="payLines">
                    <?php foreach ($pay_rows as $pr) { ?>
                        <div class="pay-line" style="display:flex; gap:6px; margin-bottom:5px; justify-content:flex-end;">
                            <select name="pay_account[]" class="form-control pay-acc" style="width:190px; font-size:11px; padding:4px;" title="Cash Type"><?php echo fl_acc_opts($fund_accounts, $pr['acc']); ?></select>
                            <input type="number" name="pay_amount[]" class="form-control pay-amt" step="0.01" style="width:100px; text-align:right;" placeholder="Amount" onfocus="this.select()" oninput="recalcPayment()" value="<?php echo $pr['amt'] !== '' ? number_format($pr['amt'], 2, '.', '') : ''; ?>">
                            <button type="button" style="background:#dc3545; color:#fff; border:none; border-radius:4px; padding:2px 9px; cursor:pointer;" onclick="removePayLine(this)">-</button>
                        </div>
                    <?php } ?>
                    </div>
                    <div style="text-align:right; margin-bottom:6px;"><button type="button" class="btn-action" style="background:#28a745; color:#fff; border:none; font-size:11px; padding:3px 10px;" onclick="addPayLine()"><i class="fa-solid fa-plus"></i> Add Payment Type</button></div>
                    <div class="summary-row" style="border-top: 2px double #888; padding-top: 4px;"><label style="color: #d32f2f; font-size: 13px;">Net Balance</label><input type="number" name="net_balance" id="sm_net_balance" class="form-control" readonly style="font-weight: bold; color: #d32f2f; font-size: 13px;" value="0.00"></div>
                </div>
            </div><!-- /inv-right --></div><!-- /inv-layout -->
        </div>
    </form>
</div>

<div class="form-panel" id="productListGridArea" style="display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'none' : 'block'; ?>;">
    <div class="panel-header">PURCHASE GRN LIST (MULTI UNIT)</div>
    <div class="panel-body">
        <div class="filter-grid">
            <div class="form-group"><label>GRN No</label><input type="text" id="flt_grn_no" class="form-control" oninput="runGrnLiveFilter()" placeholder="Search GRN.."></div>
            <div class="form-group"><label>Supplier</label><input type="text" id="flt_supplier" class="form-control" oninput="runGrnLiveFilter()" placeholder="Search supplier.."></div>
            <div class="form-group"><label>Date From</label><input type="date" id="flt_date_from" class="form-control" onchange="runGrnLiveFilter()"></div>
            <div class="form-group"><label>Date To</label><input type="date" id="flt_date_to" class="form-control" onchange="runGrnLiveFilter()"></div>
            <div class="form-group"><label>Branch</label>
                <select id="flt_branch" class="form-control" onchange="runGrnLiveFilter()">
                    <option value="">All Branch</option>
                    <?php $bopts = array_values(array_unique(array_column($grn_rows, 'branch'))); sort($bopts);
                    foreach ($bopts as $bo) echo '<option value="' . htmlspecialchars($bo, ENT_QUOTES) . '">' . htmlspecialchars($bo) . '</option>'; ?>
                </select></div>
        </div>
        <table class="grid-table list-table" id="grnGridTable">
            <thead><tr>
                <th style="width:30px;"></th><th style="width:40px;text-align:center;">SL</th><th>GRN No</th><th>Date</th><th>Supplier</th><th>Branch / Warehouse</th>
                <th class="r" style="width:110px;">Total Amount</th><th class="r" style="width:100px;">Paid</th><th class="r" style="width:100px;">Due</th>
            </tr></thead>
            <tbody>
            <?php if ($grn_rows) { $sl = 1; foreach ($grn_rows as $r) { ?>
                <tr id="grn_row_<?php echo $r['id']; ?>" class="grn-data-row" style="cursor:pointer;" onclick="selectGrnRow(this, <?php echo $r['id']; ?>)"
                    data-grn="<?php echo htmlspecialchars($r['no'], ENT_QUOTES); ?>" data-supplier="<?php echo htmlspecialchars($r['sup'], ENT_QUOTES); ?>" data-date="<?php echo htmlspecialchars((string)$r['date'], ENT_QUOTES); ?>" data-branch="<?php echo htmlspecialchars((string)$r['branch'], ENT_QUOTES); ?>" data-total="<?php echo round($r['total'], 2); ?>" data-paid="<?php echo round($r['paid'], 2); ?>" data-due="<?php echo round($r['due'], 2); ?>">
                    <td style="text-align:center;"><input type="checkbox" class="grn-row-select" value="<?php echo $r['id']; ?>" onclick="event.stopPropagation(); selectGrnRow(this.closest('tr'), <?php echo $r['id']; ?>)"></td>
                    <td style="text-align:center;" class="sl"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;color:#2e7d32;"><?php echo htmlspecialchars($r['no']); ?></td>
                    <td><?php echo $r['date'] ? date('d-M-Y', strtotime($r['date'])) : '-'; ?></td>
                    <td style="color:#4050b3;font-weight:500;"><?php echo htmlspecialchars($r['sup']); ?></td>
                    <td><?php echo htmlspecialchars($r['branch']); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo number_format($r['total'], 2); ?></td>
                    <td class="r" style="color:#28a745;"><?php echo number_format($r['paid'], 2); ?></td>
                    <td class="r" style="color:#d32f2f;font-weight:bold;"><?php echo number_format($r['due'], 2); ?></td>
                </tr>
            <?php } ?>
                <tr class="total-row"><td colspan="6" style="text-align:center;color:#2e7d32;">Grand Total</td>
                    <td class="r" id="gt_total"><?php echo number_format($sum_total, 2); ?></td><td class="r" id="gt_paid"><?php echo number_format($sum_paid, 2); ?></td><td class="r" id="gt_due"><?php echo number_format($sum_due, 2); ?></td></tr>
            <?php } else echo "<tr><td colspan='9' style='text-align:center;padding:15px;color:#777;'>কোনো GRN পাওয়া যায়নি। New চাপুন।</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="supplierModalWindow" class="sup-modal">
    <div class="sup-modal-content">
        <div style="font-size: 13px; font-weight: bold; color: #2e7d32; margin-bottom: 12px;"><i class="fa-solid fa-user-plus"></i> Quick Add New Supplier</div>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <div class="form-group"><label style="font-size:11px; font-weight:bold;">Supplier Name</label><input type="text" id="m_sup_name" class="form-control" placeholder="e.g. RAK Ceramics"></div>
            <div class="form-group"><label style="font-size:11px; font-weight:bold;">Mobile No</label><input type="text" id="m_sup_mobile" class="form-control" placeholder="e.g. 018XXXXXXXX"></div>
            <div class="form-group"><label style="font-size:11px; font-weight:bold;">Factory Address</label><input type="text" id="m_sup_address" class="form-control" placeholder="e.g. Gazipur"></div>
            <div style="text-align:right; margin-top:10px; display:flex; gap:5px; justify-content:flex-end;"><button type="button" class="btn-action" onclick="closeSupplierModal()">Cancel</button><button type="button" class="btn-action" style="background:#28a745; color:white; border-color:#218838;" onclick="submitQuickSupplier()">Save Supplier</button></div>
        </div>
    </div>
</div>

<script>
var selectedGrnId = null;
function showForm() { document.getElementById('productFormPanel').style.display = 'block'; document.getElementById('actionGridButtons').style.display = 'none'; document.getElementById('productListGridArea').style.display = 'none'; }
function hideForm() { document.getElementById('productFormPanel').style.display = 'none'; document.getElementById('actionGridButtons').style.display = 'flex'; document.getElementById('productListGridArea').style.display = 'block'; window.location.href = 'purchase_multi_unit.php'; }
function selectGrnRow(rowElement, id) { document.querySelectorAll("#grnGridTable tbody tr").forEach(function(tr) { tr.classList.remove('selected-row'); }); document.querySelectorAll('.grn-row-select').forEach(function(chk) { chk.checked = false; }); rowElement.classList.add('selected-row'); var chkBox = rowElement.querySelector('.grn-row-select'); if (chkBox) chkBox.checked = true; selectedGrnId = id; }
function printSelected() { if (!selectedGrnId) { alert('অনুগ্রহ করে একটি জিআরএন সিলেক্ট করুন!'); return; } window.open('print_grn.php?id=' + selectedGrnId, '_blank'); }
function editSelected() { if (!selectedGrnId) { alert('অনুগ্রহ করে একটি জিআরএন সিলেক্ট করুন!'); return; } window.location.href = 'purchase_multi_unit.php?action=edit&id=' + selectedGrnId; }
function deleteSelected() { if (!selectedGrnId) { alert('অনুগ্রহ করে একটি জিআরএন সিলেক্ট করুন!'); return; } if (!confirm('আপনি কি নিশ্চিত যে রেকর্ডটি ডিলিট করতে চান?')) return; postDelete('purchase_multi_unit.php', {id: selectedGrnId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'}); }

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

function syncSupplierSelection() {
    var val = document.getElementById("supplier_search").value;
    var opts = document.getElementById("supplier_list_options").options;
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) {
            document.getElementById("hidden_supplier_id").value = opts[i].getAttribute("data-id");
            document.getElementById("sup_meta_card").innerHTML = "<i class='fa-solid fa-phone'></i> Mobile: " + opts[i].getAttribute("data-mobile") + " | <i class='fa-solid fa-location-dot'></i> Address: " + opts[i].getAttribute("data-address");
            break;
        }
    }
}

function submitQuickSupplier() {
    var name = document.getElementById("m_sup_name").value.trim(); var mobile = document.getElementById("m_sup_mobile").value.trim(); var addr = document.getElementById("m_sup_address").value.trim();
    if(!name || !mobile) { alert("Required!"); return; }
    var formData = new FormData(); formData.append('save_supplier', '1'); formData.append('supplier_name', name); formData.append('mobile_no', mobile); formData.append('address', addr);
    fetch('purchase_multi_unit.php', { method: 'POST', body: formData }).then(function(res) { return res.json(); }).then(function(data) {
        var dl = document.getElementById("supplier_list_options"); var opt = document.createElement("option"); var combo = name + " [ " + addr + " - " + mobile + " ]";
        var newId = (data && data.id) ? data.id : "NEW"; opt.setAttribute("data-id", newId); opt.setAttribute("data-mobile", mobile); opt.setAttribute("data-address", addr); opt.value = combo; dl.appendChild(opt);
        document.getElementById("supplier_search").value = combo; document.getElementById("hidden_supplier_id").value = newId; document.getElementById("sup_meta_card").innerHTML = "<i class='fa-solid fa-phone'></i> Mobile: " + mobile + " | <i class='fa-solid fa-location-dot'></i> Address: " + addr;
        closeSupplierModal(); alert("সাপ্লায়ার সফলভাবে সংরক্ষিত হয়েছে!");
    }).catch(function() { window.location.reload(); });
}
var PRODUCT_FM = <?php echo json_encode($fm_map, JSON_FORCE_OBJECT); ?>;

function rowInfo(row) {
    return {
        boxPcs: parseFloat(row.getAttribute("data-boxpcs")) || 1,
        rp: parseFloat(row.getAttribute("data-ratiopcs")) || 1,
        ro: parseFloat(row.getAttribute("data-ratioout")) || 1,
        fmBox: parseFloat(row.getAttribute("data-fmbox")) || 0
    };
}
function isFmRow(row) { var s = row.querySelector(".grn-unit-sel"); return !!s && s.value === "FM"; }
function currentPcs(row) {
    var i = rowInfo(row);
    return ((parseInt(row.querySelector(".grn-box").value) || 0) * i.boxPcs) + (parseInt(row.querySelector(".grn-pcs").value) || 0);
}
function refreshFmCols() {
    var any = false;
    document.querySelectorAll("#grnItemEntryTable tbody tr").forEach(function (r) { if (isFmRow(r)) any = true; });
    document.getElementById("grnItemEntryTable").classList.toggle("has-fm", any);
}
function applyUnitState(row) {
    var fm = isFmRow(row);
    var fq = row.querySelector(".grn-fm-qty"), fp = row.querySelector(".grn-fm-price"), rt = row.querySelector(".grn-rate");
    fq.readOnly = !fm; fp.readOnly = !fm; rt.readOnly = fm;
    fq.classList.toggle("fm-ro", !fm); fp.classList.toggle("fm-ro", !fm); rt.classList.toggle("fm-ro", fm);
}
function setFmOptionState(row) {
    var opt = row.querySelector('.grn-unit-sel option[value="FM"]'); if (!opt) return;
    var ok = rowInfo(row).fmBox > 0;
    opt.disabled = !ok; opt.textContent = ok ? "FM" : "FM (সেট নেই)";
}
function syncFmFromPcs(row, totalPcs) {
    var i = rowInfo(row), f = row.querySelector(".grn-fm-qty");
    if (f && isFmRow(row) && i.fmBox > 0) f.value = (totalPcs * i.fmBox / i.boxPcs).toFixed(2);
}
function onUnitChange(sel) {
    var row = sel.closest("tr"), i = rowInfo(row);
    if (sel.value === "FM" && i.fmBox <= 0) { alert('এই পণ্যের জন্য Multi-Unit এ "১ বক্স = কত FM" সেট করা নেই।'); sel.value = "BASE"; return; }
    var rateEl = row.querySelector(".grn-rate"), fq = row.querySelector(".grn-fm-qty"), fp = row.querySelector(".grn-fm-price");
    if (sel.value === "FM") {
        applyUnitState(row);
        var rate = parseFloat(rateEl.value) || 0;
        var sftPerBox = i.boxPcs * (i.ro / i.rp);
        fp.value = rate > 0 ? (rate * sftPerBox / i.fmBox).toFixed(2) : "0.00";   // SFT rate theke shuru-r mot (badle neya jabe)
        syncFmFromPcs(row, currentPcs(row));
    } else {
        fq.value = "0.00"; fp.value = "0.00";
        applyUnitState(row);
    }
    refreshFmCols();
    calculateAmount(row.querySelector(".grn-qty"));
}

function syncProductSelection(inputElement) {
    var row = inputElement.closest('tr'); var pName = inputElement.value.trim(); if (pName === "") return;
    var loading = !!window.grnLoading;   // edit page load hole save kora rate/qty ovverwrite hobe na
    fetch('get_product_ratio.php?product_name=' + encodeURIComponent(pName)).then(function(res) { return res.json(); }).then(function(data) {
        if (data && data.id > 0) {
            var myHid = row.querySelector(".hidden_product_id");
            if (!loading) {
                var dupFound = false;
                document.querySelectorAll(".hidden_product_id").forEach(function(h) { if (h !== myHid && h.value == data.id) dupFound = true; });
                if (dupFound) {
                    alert('এই পণ্য আগের সারিতে আছে। একই পণ্য দুইবার দেওয়া যাবে না — ওই সারিতেই পরিমাণ বাড়ান।');
                    inputElement.value = ""; myHid.value = "";
                    ["grn-item-size", "grn-item-color"].forEach(function(c) { row.querySelector("." + c).value = ""; });
                    return;
                }
            }
            myHid.value = data.id;
            row.querySelector(".grn-item-size").value = data.size_name || "";
            row.querySelector(".grn-item-color").value = data.color_name || "";
            row.setAttribute("data-boxpcs", parseFloat(data.box_to_pcs) || 1);
            row.setAttribute("data-ratiopcs", parseFloat(data.ratio_pcs) || 1);
            row.setAttribute("data-ratioout", parseFloat(data.ratio_output) || 1);
            row.setAttribute("data-fmbox", parseFloat(PRODUCT_FM[data.id]) || 0);
            var sel = row.querySelector(".grn-unit-sel");
            sel.options[0].textContent = data.unit_name || "SFT";
            setFmOptionState(row);
            if (!loading) {
                sel.value = "BASE"; applyUnitState(row); refreshFmCols();
                row.querySelector(".grn-fm-qty").value = "0.00"; row.querySelector(".grn-fm-price").value = "0.00";
                row.querySelector(".grn-rate").value = data.cost_price || "0.00";
                calculateQtyFromBox(row.querySelector(".grn-box"));
            }
        }
    });
}

function calculateQtyFromBox(inputElement) {
    var row = inputElement.closest('tr'), i = rowInfo(row);
    var totalPcs = currentPcs(row);
    var totalQty = totalPcs * (i.ro / i.rp);
    var finalQty = parseFloat(parseFloat(totalQty.toFixed(4)).toFixed(2));
    row.querySelector(".grn-qty").value = finalQty.toFixed(2);
    syncFmFromPcs(row, totalPcs);
    calculateAmount(row.querySelector(".grn-qty"));
}

function calculateBoxFromQty(inputElement) {
    var row = inputElement.closest('tr'), i = rowInfo(row);
    var qty = parseFloat(row.querySelector(".grn-qty").value) || 0;
    var totalPcs = Math.round(qty / (i.ro / i.rp));
    row.querySelector(".grn-box").value = Math.floor(totalPcs / i.boxPcs);
    row.querySelector(".grn-pcs").value = totalPcs % i.boxPcs;
    syncFmFromPcs(row, totalPcs);
    inputElement.onblur = function() { var finalQty = totalPcs * (i.ro / i.rp); row.querySelector(".grn-qty").value = finalQty.toFixed(2); calculateAmount(row.querySelector(".grn-qty")); };
    calculateAmount(row.querySelector(".grn-qty"));
}

// FM Qty lekhle box / pcs / total SFT auto hishab
function calculateFromFm(inputElement) {
    var row = inputElement.closest('tr'), i = rowInfo(row);
    if (i.fmBox <= 0) return;
    var fmQty = parseFloat(inputElement.value) || 0;
    var totalPcs = Math.round(fmQty * i.boxPcs / i.fmBox);
    row.querySelector(".grn-box").value = Math.floor(totalPcs / i.boxPcs);
    row.querySelector(".grn-pcs").value = totalPcs % i.boxPcs;
    row.querySelector(".grn-qty").value = (totalPcs * (i.ro / i.rp)).toFixed(2);
    calculateAmount(row.querySelector(".grn-qty"));
}

function calculateAmount(inputElement) {
    var row = inputElement.closest('tr');
    var qty = parseFloat(row.querySelector(".grn-qty").value) || 0;
    if (isFmRow(row)) {
        // Amount = FM Qty x FM Price ; stock rate = Amount / total SFT
        var fq = parseFloat(row.querySelector(".grn-fm-qty").value) || 0, fp = parseFloat(row.querySelector(".grn-fm-price").value) || 0;
        var amt = Math.round(fq * fp * 100) / 100;
        row.querySelector(".grn-amount").value = amt.toFixed(2);
        row.querySelector(".grn-rate").value = qty > 0 ? (amt / qty).toFixed(4) : "0.0000";
    } else {
        var rate = parseFloat(row.querySelector(".grn-rate").value) || 0;
        row.querySelector(".grn-amount").value = (qty * rate).toFixed(2);
    }
    calculateTotalAmountAllRows();
}
function calculateTotalAmountAllRows() { var totalAll = 0; document.querySelectorAll("#grnItemEntryTable tbody tr").forEach(function(row) { totalAll += parseFloat(row.querySelector(".grn-amount").value) || 0; }); document.getElementById("sm_total_amount").value = totalAll.toFixed(2); calculateSummary(); }
function calculateSummary() { var amt = parseFloat(document.getElementById("sm_total_amount").value) || 0; var labor = parseFloat(document.getElementById("sm_labor").value) || 0; var discPercent = parseFloat(document.getElementById("sm_disc_percent").value) || 0; var discAmt = parseFloat(document.getElementById("sm_disc_amount").value) || 0; var prevBal = parseFloat(document.getElementById("sm_prev_balance").value) || 0; var payment = parseFloat(document.getElementById("sm_payment").value) || 0; if (document.activeElement.id === "sm_disc_percent" && discPercent > 0) { discAmt = (amt * discPercent) / 100; document.getElementById("sm_disc_amount").value = discAmt.toFixed(2); } else if (document.activeElement.id === "sm_disc_amount" && discAmt > 0) { discPercent = (discAmt / amt) * 100; document.getElementById("sm_disc_percent").value = discPercent.toFixed(2); } var gross = (amt + labor) - discAmt; document.getElementById("sm_gross_total").value = gross.toFixed(2); document.getElementById("sm_net_balance").value = ((gross + prevBal) - payment).toFixed(2); }
function addNewProductRow() {
    var tableBody = document.querySelector("#grnItemEntryTable tbody"); var firstRow = tableBody.querySelector("tr"); var newRow = firstRow.cloneNode(true);
    ["data-boxpcs", "data-ratiopcs", "data-ratioout"].forEach(function(a) { newRow.setAttribute(a, "0"); });
    newRow.setAttribute("data-fmbox", "0");
    var inputs = newRow.getElementsByTagName('input');
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].type === 'number') { var c = inputs[i].className; inputs[i].value = (c.includes('grn-qty') || c.includes('grn-amount') || c.includes('grn-fm') || c.includes('grn-rate')) ? "0.00" : "0"; }
        else { inputs[i].value = ""; }
    }
    var prodInput = newRow.querySelector('.grn-prod-input'); if (prodInput) { prodInput.value = ""; prodInput.setAttribute("onchange", "syncProductSelection(this)"); }
    var sel = newRow.querySelector(".grn-unit-sel"); sel.value = "BASE"; sel.options[0].textContent = "Unit";
    tableBody.appendChild(newRow);
    applyUnitState(newRow); setFmOptionState(newRow); refreshFmCols();
}
function removeThisRow(buttonElement) { var tableBody = document.querySelector("#grnItemEntryTable tbody"); if (tableBody.querySelectorAll("tr").length > 1) { buttonElement.closest('tr').remove(); calculateTotalAmountAllRows(); } else { alert("ফরমের শেষ আইটেম রোটি মুছে ফেলা সম্ভব নয়!"); } }
function openSupplierModal() { document.getElementById("supplierModalWindow").style.display = "block"; }
function closeSupplierModal() { document.getElementById("supplierModalWindow").style.display = "none"; }
function runGrnLiveFilter() {
    var no = document.getElementById('flt_grn_no').value.toUpperCase().trim(), su = document.getElementById('flt_supplier').value.toUpperCase().trim(),
        from = document.getElementById('flt_date_from').value, to = document.getElementById('flt_date_to').value,
        br = document.getElementById('flt_branch').value, sl = 1, tt = 0, tp = 0, td = 0;
    document.querySelectorAll('.grn-data-row').forEach(function(r) {
        var ok = true, d = r.getAttribute('data-date');
        if (no && r.getAttribute('data-grn').toUpperCase().indexOf(no) === -1) ok = false;
        if (su && r.getAttribute('data-supplier').toUpperCase().indexOf(su) === -1) ok = false;
        if (br && r.getAttribute('data-branch') !== br) ok = false;
        if (from && d < from) ok = false; if (to && d > to) ok = false;
        r.style.display = ok ? '' : 'none';
        if (ok) { r.querySelector('.sl').textContent = sl++; tt += parseFloat(r.getAttribute('data-total')) || 0; tp += parseFloat(r.getAttribute('data-paid')) || 0; td += parseFloat(r.getAttribute('data-due')) || 0; }
    });
    var f = function (n) { return n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    var a = document.getElementById('gt_total'), b = document.getElementById('gt_paid'), c = document.getElementById('gt_due');
    if (a) a.textContent = f(tt); if (b) b.textContent = f(tp); if (c) c.textContent = f(td);
}

function recalcPayment() {
    var t = 0;
    document.querySelectorAll(".pay-amt").forEach(function(i) { t += parseFloat(i.value) || 0; });
    document.getElementById("sm_payment").value = t.toFixed(2);
    calculateSummary();
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
    var box = document.getElementById("payLines"), n = box.querySelector(".pay-line").cloneNode(true);
    var rest = (parseFloat(document.getElementById("sm_gross_total").value) || 0) - (parseFloat(document.getElementById("sm_payment").value) || 0);
    n.querySelector(".pay-amt").value = rest > 0 ? rest.toFixed(2) : "";
    box.appendChild(n);
    recalcPayment();
}
function removePayLine(btn) {
    var box = document.getElementById("payLines");
    if (box.querySelectorAll(".pay-line").length > 1) btn.closest(".pay-line").remove();
    else btn.closest(".pay-line").querySelector(".pay-amt").value = "";
    recalcPayment();
}

function grnValidate() {
    var fmBad = false;
    document.querySelectorAll("#grnItemEntryTable tbody tr").forEach(function(r) {
        if (isFmRow(r) && (parseFloat(r.querySelector(".grn-fm-qty").value) || 0) <= 0) fmBad = true;
    });
    if (fmBad) { alert("FM ইউনিট বাছা সারিতে FM Qty দিন!"); return false; }
    var seen = {}, dup = false;
    document.querySelectorAll("#grnItemEntryTable tbody tr").forEach(function(r) {
        var h = r.querySelector(".hidden_product_id");
        if (h && h.value) { if (seen[h.value]) dup = true; seen[h.value] = 1; }
    });
    if (dup) { alert("একই পণ্য একাধিকবার দেওয়া যাবে না — একটি সারিতে মোট পরিমাণ দিন!"); return false; }
    var payOk = true;
    document.querySelectorAll(".pay-line").forEach(function(l) {
        if ((parseFloat(l.querySelector(".pay-amt").value) || 0) > 0 && !l.querySelector(".pay-acc").value) payOk = false;
    });
    if (!payOk) { alert("পেমেন্টের জন্য ক্যাশ/অ্যাকাউন্ট টাইপ সিলেক্ট করুন!"); return false; }
    var seenAcc = {}, dupAcc = false;
    document.querySelectorAll('.pay-line').forEach(function(l) {
        var a = l.querySelector('.pay-acc').value;
        if (a && (parseFloat(l.querySelector('.pay-amt').value) || 0) > 0) { if (seenAcc[a]) dupAcc = true; seenAcc[a] = 1; }
    });
    if (dupAcc) { alert(PAY_DUP_MSG); return false; }
    return true;
}
window.addEventListener("DOMContentLoaded", function() {
    window.grnLoading = true; setTimeout(function() { window.grnLoading = false; }, 1500);
    var val = document.getElementById("supplier_search").value; if(val) syncSupplierSelection();
    var rows = document.querySelectorAll("#grnItemEntryTable tbody tr");
    rows.forEach(function(row) { applyUnitState(row); });
    refreshFmCols();
    rows.forEach(function(row) { var input = row.querySelector(".grn-prod-input"); if(input && input.value !== "") { syncProductSelection(input); } });
    recalcPayment();
    calculateTotalAmountAllRows();
});
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>