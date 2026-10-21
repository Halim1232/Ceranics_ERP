<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
include('../auth.php'); require_page_access(); include('../config.php');

// চালানে ওয়্যারহাউস সংরক্ষণের কলাম (আগে না থাকলে অটো তৈরি; পুরনো চালান WH-001 ধরা হয়)
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM erp_delivery_challan_master LIKE 'warehouse_id'");
    if ($cc && mysqli_num_rows($cc) == 0) mysqli_query($conn, "ALTER TABLE erp_delivery_challan_master ADD COLUMN warehouse_id INT NOT NULL DEFAULT 1");
} catch (Throwable $e) { }

// চালান কোন ব্রাঞ্চের = তার ইনভয়েসের ব্রাঞ্চ। ব্রাঞ্চ ইউজার অন্য ব্রাঞ্চের চালান ছুঁতে পারবে না
function dc_guard($conn, $invoice_no) {
    $st = $conn->prepare("SELECT branch_name FROM sales_invoice_master WHERE Invoice_no=?");
    $st->bind_param("s", $invoice_no); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    branch_guard($r['branch_name'] ?? '');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ১. ইনভয়েস ও কাস্টমার ডাটা সরাসরি প্রসিডিউরাল মেথডে প্রি-লোড
$invoice_list = [];
$inv_res = mysqli_query($conn, "SELECT invoice_id, Invoice_no, customer_id FROM sales_invoice_master WHERE status IN ('Undelivered', 'Partial')" . branch_sql('branch_name') . " ORDER BY invoice_id DESC");
while($inv_res && $row = mysqli_fetch_assoc($inv_res)) { $invoice_list[] = $row; }

$customer_list = [];
$cust_res = mysqli_query($conn, "SELECT id, customer_name FROM erp_customers ORDER BY customer_name ASC");
while($cust_res && $row = mysqli_fetch_assoc($cust_res)) { $customer_list[] = $row; }

// ওয়্যারহাউস তালিকা (ব্রাঞ্চ ইউজার: শুধু নিজের ব্রাঞ্চের)
$wh_list = [];
$wq = mysqli_query($conn, "SELECT w.id, w.warehouse_name, b.branch_name FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.status='Active'" . branch_wh_sql('w.id') . " ORDER BY b.branch_name, w.warehouse_name");
while ($wq && $row = mysqli_fetch_assoc($wq)) { $wh_list[] = $row; }

// ২. আংশিক ডেলিভারির পর অবশিষ্ট (Remaining) বক্স/পিস হিসাব করে ব্রাউজার মেমোরিতে প্রি-লোড করা
$all_items = [];
$item_res = mysqli_query($conn, "
    SELECT d.product_id, d.box_qty AS ord_box, d.pcs_qty AS ord_pcs, d.total_qty AS ord_sft, d.rate, 
           p.item_name AS product_name, p.size_name AS size, p.box_to_pcs, p.ratio_pcs, p.ratio_output, 
           p.unit_name AS unit, m.Invoice_no, m.customer_id,
           IFNULL((SELECT SUM(dc_d.total_qty) FROM erp_delivery_challan_details dc_d 
                   INNER JOIN erp_delivery_challan_master dc_m ON dc_d.challan_id = dc_m.id 
                   WHERE dc_m.invoice_no = m.Invoice_no AND dc_d.product_id = d.product_id), 0) AS delivered_sft
    FROM sales_invoice_details d
    INNER JOIN erp_products p ON d.product_id = p.id
    INNER JOIN sales_invoice_master m ON d.invoice_id = m.invoice_id
    WHERE m.status IN ('Undelivered', 'Partial')" . branch_sql('m.branch_name') . "
");

if($item_res) {
    while($row = mysqli_fetch_assoc($item_res)) {
        $remaining_sft = floatval($row['ord_sft']) - floatval($row['delivered_sft']);
        if($remaining_sft > 0.01) {
            $totalPcsExact = $remaining_sft / (floatval($row['ratio_output']) / floatval($row['ratio_pcs']));
            $totalPcs = round($totalPcsExact);
            $rem_box = floor($totalPcs / intval($row['box_to_pcs']));
            $rem_pcs = $totalPcs % intval($row['box_to_pcs']);
            
            $row['total_qty'] = $remaining_sft;
            $row['box_qty'] = $rem_box;
            $row['pcs_qty'] = $rem_pcs;
            
            $all_items[$row['Invoice_no']][] = $row;
        }
    }
}

// ৩. চালান সংরক্ষণ (স্টক চেক + ট্রানজেকশন + সঠিক _out কলাম)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_challan'])) {
    $is_edit      = intval($_POST['is_edit']);
    $challan_no   = '';
    if ($is_edit > 0) {   // এডিটে চালান নম্বর DB থেকে নেওয়া হয়, ফর্মের মান বিশ্বাস করা হয় না
        $cn = mysqli_fetch_assoc(mysqli_query($conn, "SELECT challan_no, invoice_no FROM erp_delivery_challan_master WHERE id=$is_edit"));
        if (!$cn) { echo "<script>alert('চালান পাওয়া যায়নি!'); history.back();</script>"; exit(); }
        dc_guard($conn, $cn['invoice_no']);
        $challan_no = mysqli_real_escape_string($conn, $cn['challan_no']);
    }
    $challan_date = mysqli_real_escape_string($conn, $_POST['challan_date']);
    $customer_id  = intval($_POST['customer_id']);
    $remarks      = mysqli_real_escape_string($conn, $_POST['remarks']);
    $ref_no       = mysqli_real_escape_string($conn, $_POST['ref_no']);

    // ওয়্যারহাউস: ব্রাঞ্চ ইউজারের ক্ষেত্রে শুধু নিজের ব্রাঞ্চের হতে হবে
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $wchk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM erp_warehouses WHERE id=$warehouse_id AND status='Active'" . branch_wh_sql('id')));
    if (!$wchk) { echo "<script>alert('সঠিক ওয়্যারহাউস নির্বাচন করুন!'); history.back();</script>"; exit(); }
    // ইনভয়েস অবশ্যই নিজের ব্রাঞ্চের হতে হবে
    $ib = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_name FROM sales_invoice_master WHERE Invoice_no='$ref_no'"));
    if (!$ib) { echo "<script>alert('রেফারেন্স ইনভয়েস পাওয়া যায়নি!'); history.back();</script>"; exit(); }
    branch_guard($ib['branch_name'] ?? '');
    $is_edit      = intval($_POST['is_edit']);

    // (ক) প্রোডাক্ট অনুযায়ী মোট ডেলিভারি পরিমাণ জমা করা
    $need = [];
    if (isset($_POST['product_id'])) {
        foreach ($_POST['product_id'] as $key => $p_id) {
            $pid = intval($p_id);
            $sft = floatval($_POST['total_qty'][$key]);
            if ($pid > 0 && $sft > 0) { $need[$pid] = ($need[$pid] ?? 0) + $sft; }
        }
    }
    if (empty($need)) {
        echo "<script>alert('কমপক্ষে একটি পণ্য যোগ করুন!'); history.back();</script>"; exit();
    }

    // (খ) স্টক চেক (এডিট হলে এই চালানের নিজের আগের এন্ট্রি বাদ দিয়ে)
    $short = [];
    foreach ($need as $pid => $qty) {
        $q = mysqli_query($conn, "SELECT p.item_name, p.negative_delivery,
                COALESCE(SUM(s.sqft_in - s.sqft_out), 0) AS stock
              FROM erp_products p
              LEFT JOIN erp_stock_ledger s ON s.product_id = p.id
                   AND NOT (s.voucher_no = '$challan_no' AND s.voucher_type = 'DELIVERY')
                   AND s.warehouse_id = $warehouse_id
              WHERE p.id = $pid GROUP BY p.id");
        $p = mysqli_fetch_assoc($q);
        if ($p && !$p['negative_delivery'] && ($qty - (float)$p['stock']) > 0.01) {
            $short[] = $p['item_name'] . " (স্টক: " . number_format($p['stock'], 2) . ", দিতে চান: " . number_format($qty, 2) . ")";
        }
    }
    if (!empty($short)) {
        $msg = "স্টক অপর্যাপ্ত:\\n" . implode("\\n", array_map('addslashes', $short));
        echo "<script>alert('$msg'); history.back();</script>"; exit();
    }

    // (গ) ট্রানজেকশনের ভেতরে সব সেভ
    mysqli_begin_transaction($conn);
    try {
        if ($is_edit > 0) {
            mysqli_query($conn, "DELETE FROM erp_delivery_challan_details WHERE challan_id = $is_edit");
            mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no = '$challan_no' AND voucher_type = 'DELIVERY'");
            mysqli_query($conn, "UPDATE erp_delivery_challan_master SET challan_date='$challan_date', customer_id=$customer_id, remarks='$remarks', invoice_no='$ref_no', warehouse_id=$warehouse_id WHERE id=$is_edit");
            $challan_id = $is_edit;
        } else {
            // নতুন চালান: নম্বর সেভের মুহূর্তে, লকসহ (ডুপ্লিকেট হবে না)। ফরম্যাট: DC-YY + ৪ অঙ্কের ক্রমিক (INV/GRN এর মতো)
            $mx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_delivery_challan_master FOR UPDATE"));
            $challan_no = "DC-" . date('y') . str_pad(((int)$mx['m']) + 1, 4, "0", STR_PAD_LEFT);
            mysqli_query($conn, "INSERT INTO erp_delivery_challan_master (challan_no, challan_date, invoice_no, customer_id, remarks, warehouse_id) VALUES ('$challan_no', '$challan_date', '$ref_no', $customer_id, '$remarks', $warehouse_id)");
            $challan_id = mysqli_insert_id($conn);
        }

        foreach ($_POST['product_id'] as $key => $p_id) {
            $prod_id = intval($p_id);
            $box = floatval($_POST['box_qty'][$key]);
            $pcs = floatval($_POST['pcs_qty'][$key]);
            $sft = floatval($_POST['total_qty'][$key]);
            if ($prod_id <= 0 || $sft <= 0) continue;

            mysqli_query($conn, "INSERT INTO erp_delivery_challan_details (challan_id, product_id, box_qty, pcs_qty, total_qty) VALUES ($challan_id, $prod_id, $box, $pcs, $sft)");
            // ডেলিভারি = _out কলামে পজিটিভ মান
            mysqli_query($conn, "INSERT INTO erp_stock_ledger (product_id, warehouse_id, voucher_type, voucher_no, box_in, pcs_in, sqft_in, box_out, pcs_out, sqft_out, rate, total_value)
                                 VALUES ($prod_id, $warehouse_id, 'DELIVERY', '$challan_no', 0, 0, 0, $box, $pcs, $sft, 0, 0)");
        }

        // ইনভয়েসের স্ট্যাটাস আপডেট (Partial / Delivered)
        $check_rem = mysqli_query($conn, "
            SELECT d.total_qty AS ord_sft,
                   IFNULL((SELECT SUM(dc_d.total_qty) FROM erp_delivery_challan_details dc_d
                           INNER JOIN erp_delivery_challan_master dc_m ON dc_d.challan_id = dc_m.id
                           WHERE dc_m.invoice_no = m.Invoice_no AND dc_d.product_id = d.product_id), 0) AS delivered_sft
            FROM sales_invoice_details d
            INNER JOIN sales_invoice_master m ON d.invoice_id = m.invoice_id
            WHERE m.Invoice_no = '$ref_no'");
        $is_partial = false;
        while ($check_rem && $r = mysqli_fetch_assoc($check_rem)) {
            if ((floatval($r['ord_sft']) - floatval($r['delivered_sft'])) > 0.01) { $is_partial = true; }
        }
        $new_status = $is_partial ? 'Partial' : 'Delivered';
        mysqli_query($conn, "UPDATE sales_invoice_master SET status = '$new_status' WHERE Invoice_no = '$ref_no'");

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।'); history.back();</script>"; exit();
    }

    echo "<script>window.location.href='delivery_challan.php?action=list';</script>"; exit();
}

// ৪. চালান ডিলিট করার লজিক (DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $ch_res = mysqli_query($conn, "SELECT challan_no, invoice_no FROM erp_delivery_challan_master WHERE id=$id");
    $ch = mysqli_fetch_assoc($ch_res);
    if($ch) {
        dc_guard($conn, $ch['invoice_no']);
        mysqli_query($conn, "UPDATE sales_invoice_master SET status = 'Undelivered' WHERE Invoice_no = '{$ch['invoice_no']}'");
        mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_no = '{$ch['challan_no']}'");
        mysqli_query($conn, "DELETE FROM erp_delivery_challan_details WHERE challan_id = $id");
        mysqli_query($conn, "DELETE FROM erp_delivery_challan_master WHERE id = $id");
    }
    echo "<script>window.location.href='delivery_challan.php?action=list';</script>"; exit();
}
?>


<style>
    .erp-body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #333; padding: 10px; background: #fff; }
    .action-btn { background: #fff; border: 1px solid #dddddd; padding: 6px 15px; font-size: 13px; font-weight: bold; cursor: pointer; margin-right: 5px; color: #000; border-radius: 4px; text-decoration:none; display:inline-block; }
    .action-btn:hover { background: #efefef; }
    .f-box { border: 1px solid #ccdcee; padding: 12px; margin-bottom: 12px; border-radius: 4px; position: relative; background: #fafafa; }
    .f-title { position: absolute; top: -9px; left: 15px; background: #fff; padding: 0 5px; font-weight: bold; color: #495857; font-size: 11px; text-transform: uppercase; }
    .erp-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 5px; }
    .erp-col { flex: 1; min-width: 180px; }
    .erp-col label { display: block; font-weight: bold; margin-bottom: 3px; color: #444; }
    .erp-col input, .erp-col select { width: 100%; padding: 4px; border: 1px solid #ccdcee; box-sizing: border-box; font-size: 12px; height: 26px; border-radius: 3px; }
    .erp-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
    .erp-table th, .erp-table td { border: 1px solid #ccdcee; padding: 8px; text-align: center; }
    .erp-table th { background: #ebf2f2; color: #495857; font-weight: bold; }
    
/* রো গুলোকে একদম ছোট, চ্যাপ্টা এবং লেখা স্পষ্ট করার জন্য আল্ট্রা-স্লিম সিএসএস */
.chk-item-row { 
    display: flex !important; 
    align-items: center !important; 
    padding: 2px 8px !important; /* প্যাডিং একদম কমিয়ে রো ছোট করা হয়েছে */
    border-bottom: 1px solid #f0f0f0 !important;
    background: #ffffff !important;
    width: 80% !important;
    box-sizing: border-box !important;
    height: 24px !important; /* ফিক্সড স্লিম উচ্চতা */
    margin: 0 !important;
}
.chk-item-row:hover { 
    background-color: #f5f9ff !important; 
}
.chk-item-row input[type="checkbox"] { 
    margin: 0 8px 0 0 !important; 
    transform: scale(1.0) !important; /* চেকবক্সের সাইজ কিছুটা ছোট করা হয়েছে */
    cursor: pointer !important; 
    flex-shrink: 0 !important;
    height: auto !important;
    width: auto !important;
}
/* লেখার এলাইনমেন্ট এক লাইনে সোজা রাখার মূল ফিক্স */
.chk-item-row label { 
    cursor: pointer !important; 
    font-weight: normal !important; 
    margin: 0 !important; 
    padding: 0 !important; 
    display: flex !important; 
    justify-content: space-between !important; 
    width: 80% !important; 
    font-size: 12px !important; /* ফন্ট সাইজ মানানসই করা হয়েছে */
    color: #000000 !important;
    white-space: nowrap !important; 
    line-height: 24px !important; /* টেক্সট যেন রো এর ঠিক মাঝখানে থাকে */
}

</style>

<div class="erp-body">
    <div style="background:#ececef; padding:10px; border-radius:4px; display:flex; gap:8px; margin-bottom:12px; border:1px solid #dcdcd4;">
        <?php if($action == 'list'): ?>
            <a href="delivery_challan.php?action=add" class="action-btn" style="background:#2e7d32; color:#fff;">New</a>
            <button class="action-btn" onclick="editSelectedChallan()">Edit</button>
            <button class="action-btn" onclick="deleteSelectedChallan()">Delete</button>
            <button class="action-btn" onclick="printSelectedChallan()">Print</button>
        <?php else: ?>
            <button class="action-btn" type="button" onclick="document.getElementById('challan_form').submit();" style="background:#2e7d32; color:#fff;">Save</button>
            <a href="delivery_challan.php?action=list" class="action-btn">Close</a>
        <?php endif; ?>
    </div>

    <?php if($action == 'list'): ?>
        <div class="f-box">
            <span class="f-title">Delivery Challan List</span>
            <table class="erp-table">
                <thead><tr><th width="4%">Select</th><th>SL</th><th>Challan No</th><th>Date</th><th>Invoice Ref</th><th>Customer Name</th><th>Vehicle Info / Remarks</th></tr></thead>
                <tbody>
                    <?php
                    $res = $conn->query("SELECT m.*, c.customer_name FROM erp_delivery_challan_master m LEFT JOIN erp_customers c ON m.customer_id = c.id" . (current_branch() ? " WHERE m.invoice_no IN (SELECT invoice_no FROM sales_invoice_master WHERE 1=1" . branch_sql('branch_name') . ")" : "") . " ORDER BY m.id DESC");
                    $sl = 1;
                    while($res && $row = $res->fetch_assoc()) {
                        echo "<tr>
                            <td><input type='radio' name='selected_challan' value='".$row['id']."'></td>
                            <td>".$sl++."</td><td style='font-weight:bold; color:#2e7d32;'>".htmlspecialchars((string)$row['challan_no'], ENT_QUOTES)."</td><td>".htmlspecialchars((string)$row['challan_date'], ENT_QUOTES)."</td><td style='font-weight:bold;'>".htmlspecialchars((string)$row['invoice_no'], ENT_QUOTES)."</td><td align='left'>".htmlspecialchars((string)$row['customer_name'], ENT_QUOTES)."</td><td align='left'>".htmlspecialchars((string)$row['remarks'], ENT_QUOTES)."</td>
                        </tr>";
                    }
                    if($sl == 1) { echo "<tr><td colspan='7' style='padding:20px; color:#999;'>No record found. Click 'New' to create a challan.</td></tr>"; }
                    ?>
                </tbody>
            </table>
        </div>
    <?php else: 
        $is_edit = 0; $edit_master = [];
        if($action == 'edit' && isset($_GET['id'])) {
            $is_edit = intval($_GET['id']);
            $edit_master = $conn->query("SELECT * FROM erp_delivery_challan_master WHERE id = $is_edit")->fetch_assoc();
            if ($edit_master) dc_guard($conn, $edit_master['invoice_no']);
        }
    ?>
        <form id="challan_form" method="POST">
            <input type="hidden" name="save_challan" value="1">
            <input type="hidden" name="is_edit" value="<?php echo $is_edit; ?>">
            <div class="f-box">
                <span class="f-title">Delivery Info</span>
                <div class="erp-grid">
                    <div class="erp-col"><label>Delivery No</label><input type="text" value="<?php echo htmlspecialchars((string)($edit_master['challan_no'] ?? 'Auto (সেভ করলে তৈরি হবে)'), ENT_QUOTES); ?>" readonly style="background:#f5f5f5; font-weight:bold;"></div>
                    <div class="erp-col"><label>Delivery Date</label><input type="date" name="challan_date" value="<?php echo htmlspecialchars((string)($edit_master['challan_date'] ?? date('Y-m-d')), ENT_QUOTES); ?>"></div>
                    <!-- কাস্টমার (Party) ড্রপডাউন লিস্টের শতভাগ গ্যারান্টিড পিএইচপি লোডার -->
<div class="erp-col">
    <label>Party</label>
    <select name="customer_id" id="party_id">
        <option value="">-select-</option>
        <?php 
        // ১. আপনার স্ক্রিনশটের প্রথমদিকের সফল কুয়েরি: erp_customers টেবিল থেকে ডাটা ট্রাই করা
        // টেবিল না থাকলে এরর বন্ধ করতে '@' সাইন দিয়ে সেফগার্ড করা হয়েছে যেন পেজ না ভাঙে
        $cust_res = @mysqli_query($conn, "SELECT id, customer_name FROM erp_customers ORDER BY customer_name ASC");
        
        // ২. যদি প্রথম টেবিল না মেলে, তবে ব্যাকআপ হিসেবে শুধু 'customer' টেবিল চেক করবে
        if(!$cust_res) {
            $cust_res = @mysqli_query($conn, "SELECT customer_id, customer_name FROM customer ORDER BY customer_name ASC");
        }
        
        // ৩. কুয়েরি সফল হলে ডাটা লুপ করে ড্রপডাউনে পুশ করা
        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
            while($cust = mysqli_fetch_assoc($cust_res)) { 
                $c_id = isset($cust['customer_id']) ? $cust['customer_id'] : $cust['id'];
                $sel = (isset($edit_master['customer_id']) && $edit_master['customer_id'] == $c_id) ? 'selected' : '';
                echo "<option value='".$c_id."' $sel>".htmlspecialchars((string)$cust['customer_name'], ENT_QUOTES)."</option>"; 
            }
        } else {
            // যদি দুটি টেবিলই কোন কারণে মিসম্যাচ হয়, তবে সিস্টেম ক্র্যাশ না করে এই অপশনটি দেখাবে
            echo "<option value=''>No customer found (Check table name)</option>";
        }
        ?>
    </select>
</div>



                    <div class="erp-col"><label>Warehouse/Godown *</label>
                        <select name="warehouse_id" id="warehouse_id" required>
                            <option value="">-select-</option>
                            <?php $selw = (int)($edit_master['warehouse_id'] ?? 0);
                            foreach ($wh_list as $w) {
                                $isSel = ($selw === (int)$w['id'] || (!$selw && count($wh_list) === 1)) ? 'selected' : '';
                                echo "<option value='" . (int)$w['id'] . "' $isSel>" . htmlspecialchars($w['warehouse_name'] . ' (' . $w['branch_name'] . ')', ENT_QUOTES) . "</option>";
                            } ?>
                        </select></div>
                </div>
                
                <div style="margin-top:12px;"><button type="button" class="action-btn" onclick="toggleReferencePanel()" style="background: #ebf2f2; border-color: #ccdcee; font-size:12px;">⚙️ More Reference</button></div>
                
                <!-- শুরু থেকেই ওপেন রাখার জন্য display:block সেট করা হয়েছে -->
                <div id="reference_panel" class="f-box" style="margin-top:10px; background:#fff; display:block;">
                    <span class="f-title">Reference Selection</span>
                    <div class="erp-grid">
                        <div class="erp-col"><label>1. Reference Type</label><select id="ref_type"><option value="Sales Invoice">Sales Invoice</option></select></div>
                        <div class="erp-col"><label>2. Reference (Undelivered)</label>
                            <select id="ref_no" name="ref_no" onchange="loadStaticItems(this.value)">
                                <option value="">-select-</option>
                                <?php if($is_edit > 0) { echo "<option value='".htmlspecialchars((string)$edit_master['invoice_no'], ENT_QUOTES)."' selected>".htmlspecialchars((string)$edit_master['invoice_no'], ENT_QUOTES)."</option>"; } ?>
                                <?php foreach($invoice_list as $inv) { echo "<option value='".htmlspecialchars((string)$inv['Invoice_no'], ENT_QUOTES)."'>".htmlspecialchars((string)$inv['Invoice_no'], ENT_QUOTES)."</option>"; } ?>
                            </select>
                        </div>
                        <div class="erp-col" style="position:relative;"><label>3. Reference Detail</label>
                            <div class="custom-dropdown">
                                <input type="text" id="drop_text" value="Select Items" readonly onclick="toggleDropdown()" style="cursor:pointer; background:#fff; font-weight:bold;">
                                <div id="myDropdown" class="dropdown-content">
                                    <div class="chk-item-row" style="background:#f8f9fa; border-bottom:2px solid #ddd;">
                                        <input type="checkbox" id="checkAll" onchange="toggleSelectAll(this)">
                                        <label for="checkAll" style="font-weight:bold; color:#000;">Check All</label>
                                    </div>
                                    <div id="dynamic_checkboxes"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                                <div class="f-box">
            <span class="f-title">Details Table</span>
            <div style="margin-bottom:8px;">
                <button type="button" class="action-btn" style="padding:4px 12px; font-size:12px;" onclick="addSelectedItemsToGrid()">Add</button>
                <button type="button" class="action-btn" style="padding:4px 12px; font-size:12px;" onclick="clearGrid()">Clear</button>
            </div>
            <table class="erp-table">
                <thead><tr><th width="4%">SL</th><th>Product Description</th><th width="12%">Size</th><th width="8%">Unit</th><th width="10%">Box in Pcs</th><th width="12%">Total QTY</th><th width="12%" style="background:#fff9c4; color:#000;">Box</th><th width="12%" style="background:#fff9c4; color:#000;">Pcs</th></tr></thead>
                <tbody id="challan_grid_body">
    <?php if($is_edit > 0) {
        $items = $conn->query("SELECT d.*, p.item_name, p.size_name, p.unit_name, p.box_to_pcs 
                               FROM erp_delivery_challan_details d 
                               INNER JOIN erp_products p ON d.product_id=p.id 
                               WHERE d.challan_id=$is_edit");
        $sl = 1;
        while($row = $items->fetch_assoc()) {
            echo "<tr>
                <td>".$sl++."</td>
                <td align='left'><b>".htmlspecialchars((string)$row['item_name'], ENT_QUOTES)."</b><input type='hidden' name='product_id[]' value='".htmlspecialchars((string)$row['product_id'], ENT_QUOTES)."'></td>
                <td>".htmlspecialchars((string)$row['size_name'], ENT_QUOTES)."</td>
                <td><span style='background:#eee; padding:2px 6px;'>".htmlspecialchars((string)$row['unit_name'], ENT_QUOTES)."</span></td>
                <td>".htmlspecialchars((string)$row['box_to_pcs'], ENT_QUOTES)."</td>
                <td><input type='number' style='width:100%; text-align:center; background:#f5f5f5; font-weight:bold; border:none;' name='total_qty[]' value='".$row['total_qty']."' readonly></td>
                <td><input type='number' style='width:100%; text-align:center; border:1px solid #abc;' name='box_qty[]' value='".$row['box_qty']."'></td>
                <td><input type='number' style='width:100%; text-align:center; border:1px solid #abc;' name='pcs_qty[]' value='".$row['pcs_qty']."'></td>
            </tr>";
        }
    } else { 
        echo "<tr><td colspan='8' style='color:#999; padding:15px;' id='no_records_row'>No records to display.</td></tr>"; 
    } ?>
</tbody>

            </table>
        </div>
        <div class="f-box"><span class="f-title">Remarks / Vehicle Info</span><input type="text" name="remarks" value="<?php echo htmlspecialchars((string)($edit_master['remarks'] ?? ''), ENT_QUOTES); ?>" style="width:100%; padding:6px; border:1px solid #ccdcee;"></div>
    </form>
    <?php endif; ?>
</div>

<script>
// ১. পিএইচপি ডাটা জাভাস্ক্রিপ্ট লোকাল মেমরিতে পুশ করা (কোনো এরর বা ব্যাকস্ল্যাশ ছাড়া)
let localInvoiceData = <?php echo json_encode($all_items); ?>;
let selectedInvoiceItems = [];
function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

// ড্রপডাউন মেনু টগল (হাইড/শো) করার লজিক
function toggleDropdown() { 
    document.getElementById("myDropdown").classList.toggle("show-drop"); 
}

// ড্রপডাউনের বাইরে ক্লিক করলে প্যানেল অটো বন্ধ হওয়া
window.onclick = function(event) { 
    if (!event.target.matches('#drop_text') && !event.target.closest('.custom-dropdown')) { 
        document.getElementById("myDropdown").classList.remove('show-drop'); 
    } 
}

// অ্যারের প্রথম ইনডেক্স [0] থেকে customer_id রিড করে পার্টি ১০০% অটো-সিলেক্ট করার গ্যারান্টিড ইঞ্জিন
function loadStaticItems(refNo) {
    let container = document.getElementById('dynamic_checkboxes'); 
    container.innerHTML = '';
    
    if(!refNo || !localInvoiceData || !localInvoiceData[refNo]) return;
    
    // সিলেক্টেড ইনভয়েসের পুরো অ্যারে ডাটা অ্যাসাইন করা হলো
    selectedInvoiceItems = localInvoiceData[refNo];
    
    // ইনভয়েসের প্রথম ডাটা রো থেকে customer_id রিড করার সঠিক অ্যারে ইনডেক্সিং ফিক্স
if(selectedInvoiceItems && selectedInvoiceItems.length > 0) {
    let firstRow = selectedInvoiceItems[0]; // অ্যারের প্রথম আইটেম [0] ইনডেক্স ফিক্সড করা হলো
    if(firstRow && firstRow.customer_id) {
        let partySelect = document.getElementById('party_id');
        if(partySelect) { 
            partySelect.value = firstRow.customer_id; // কাস্টমার আইডি অটো-ম্যাচ হয়ে সিলেক্ট হবে
        }
    }
}


    // চেকবক্স জেনারেটর
    selectedInvoiceItems.forEach((item, index) => {
        container.innerHTML += `
            <div class="chk-item-row">
                <input type="checkbox" class="item-chk" value="${index}" id="item_lbl_${index}">
                <label for="item_lbl_${index}">
                    <span>${esc(item.product_name)}</span>
                    <span style="font-weight:bold; color:#555; margin-left:auto; padding-right:5px;">Qty: ${parseFloat(item.total_qty)}</span>
                </label>
            </div>`;
    });
    document.getElementById("myDropdown").classList.add("show-drop");
}




// ডাটাবেজ থেকে প্রতিটি প্রোডাক্টের নিজস্ব আলাদা আলাদা রেশিও রিড করার ডাইনামিক ইঞ্জিন
function addSelectedItemsToGrid() {
    let tbody = document.getElementById('challan_grid_body');
    let noRecords = document.getElementById('no_records_row'); if(noRecords) noRecords.remove();
    let checkboxes = document.querySelectorAll('.item-chk:checked');
    let sl = tbody.querySelectorAll('tr').length + 1;
    
    checkboxes.forEach(chk => {
        let item = selectedInvoiceItems[chk.value]; 
        
        // প্রোডাক্টের ডাটাবেজ রো থেকে তার নিজস্ব আলাদা আলাদা মান ডাইনামিক্যালি রিড করা হচ্ছে
        let boxInPcs = item.box_to_pcs ? parseInt(item.box_to_pcs) : 1;
        let rRatioPcs = item.ratio_pcs ? parseFloat(item.ratio_pcs) : 1;
        let rRatioOut = item.ratio_output ? parseFloat(item.ratio_output) : 1;
        
        let b_qty = item.box_qty ? parseInt(item.box_qty) : 0;
        let p_qty = item.pcs_qty ? parseInt(item.pcs_qty) : 0;
        
        // প্রতিটি রো-তে আলাদা প্রোডাক্টের নিজস্ব ইউনিক ডাটা অ্যাট্রিবিউট সেট হচ্ছে
        tbody.innerHTML += `
        <tr data-boxpcs="${boxInPcs}" data-ratiopcs="${rRatioPcs}" data-ratiout="${rRatioOut}">
            <td>${sl++}</td>
            <td align="left"><b>${esc(item.product_name)}</b><input type='hidden' name='product_id[]' value='${item.product_id}'><input type='hidden' name='ref_no' value='${document.getElementById('ref_no').value}'></td>
            <td>${esc(item.size || 'N/A')}</td>
            <td><span style='background:#eee; padding:2px 6px; border-radius:3px;'>${esc(item.unit)}</span></td>
            <td>${boxInPcs}</td>
            <td><input type='number' class='form-control text-center grn-qty' style='width:100%; border:1px solid #abc;' name='total_qty[]' value='${parseFloat(item.total_qty).toFixed(2)}' step='any' oninput='calculateBoxFromQty(this)'></td>
            <td><input type='number' class='form-control text-center grn-box' style='width:100%; border:1px solid #abc;' name='box_qty[]' value='${b_qty}' step='any' oninput='calculateQtyFromBox(this)'></td>
            <td><input type='number' class='form-control text-center grn-pcs' style='width:100%; border:1px solid #abc;' name='pcs_qty[]' value='${p_qty}' step='any' oninput='calculateQtyFromBox(this)'></td>
        </tr>`;
    });
    document.getElementById("myDropdown").classList.remove("show-drop");
}

// ১. বক্স ও পিস থেকে মোট কোয়ান্টিটি (SFT) হিসাব করার নিখুঁত মেকানিজম
function calculateQtyFromBox(inputElement) {
    var row = inputElement.closest('tr');
    
    var rBoxToPcs = parseInt(row.getAttribute("data-boxpcs")) || 1;
    var rRatioPcs = parseFloat(row.getAttribute("data-ratiopcs")) || 1;
    var rRatioOutput = parseFloat(row.getAttribute("data-ratiout")) || 1;
    
    var box = parseInt(row.querySelector(".grn-box").value) || 0;
    var pcs = parseInt(row.querySelector(".grn-pcs").value) || 0;
    
    // মোট পিস এবং প্রকৃত SFT হিসাব
    var totalPcs = (box * rBoxToPcs) + pcs;
    var totalQty = totalPcs * (rRatioOutput / rRatioPcs);
    
    // দশমিকের পর ২ ঘর স্পষ্ট দেখানোর জন্য ফিক্সড ২ ডিজিট
    row.querySelector(".grn-qty").value = totalQty.toFixed(2);
}

// ২. রাউন্ড কোয়ান্টিটি টাইপ করলে কাছাকাছি বক্স-পিস মিলিয়ে প্রকৃত কিউটিওয়াই অটো নেওয়ার মেকানিজম
function calculateBoxFromQty(inputElement) {
    var row = inputElement.closest('tr');
    
    var rBoxToPcs = parseInt(row.getAttribute("data-boxpcs")) || 1;
    var rRatioPcs = parseFloat(row.getAttribute("data-ratiopcs")) || 1;
    var rRatioOutput = parseFloat(row.getAttribute("data-ratiout")) || 1;
    
    var qty = parseFloat(row.querySelector(".grn-qty").value) || 0;
    if(qty <= 0) return;
    
    // কোয়ান্টিটি থেকে ব্যাক-ক্যালকুলেশন করে একদম নিখুঁত মোট পিস (Exact Pcs) বের করা
    var totalPcsExact = qty / (rRatioOutput / rRatioPcs);
    
    // ইউজার রাউন্ড ফিগার টাইপ করলে পিসকে কাছাকাছি পূর্ণসংখ্যায় (Nearest Integer) রূপান্তর করা হলো
    var totalPcs = Math.round(totalPcsExact);
    
    // পূর্ণসংখ্যার পিস থেকে বক্স এবং অতিরিক্ত পিস আলাদা করা
    var box = Math.floor(totalPcs / rBoxToPcs);
    var pcs = totalPcs % rBoxToPcs;
    
    // বক্স এবং পিস ইনপুট বক্সে পূর্ণসংখ্যার মানগুলো অটো-সেট করা
    row.querySelector(".grn-box").value = box;
    row.querySelector(".grn-pcs").value = pcs;
    
    // [ম্যাজিক পার্ট]: পূর্ণসংখ্যার বক্স-পিস ম্যাচ করার পর প্রকৃত টোটাল কোয়ান্টিটি (SFT) পুনরায় হিসাব করা
    var finalTotalPcs = (box * rBoxToPcs) + pcs;
    var adjustedQty = finalTotalPcs * (rRatioOutput / rRatioPcs);
    
    // টাইপিং লক এড়াতে ইউজার যখন টাইপ করা শেষ করবে (onblur বা বাইরে ক্লিক), তখন প্রকৃত মানটি (যেমন: ৫৩.৩৩) অটো-বসে যাবে
    inputElement.addEventListener('blur', function() {
        row.querySelector(".grn-qty").value = adjustedQty.toFixed(2);
    }, { once: true });
}



function toggleSelectAll(master) { document.querySelectorAll('.item-chk').forEach(chk => chk.checked = master.checked); }
function clearGrid() { document.getElementById('challan_grid_body').innerHTML = '<tr><td colspan="8" style="color:#999; padding:15px;" id="no_records_row">No records to display.</td></tr>'; }
function getSelectedRadio() { return document.querySelector('input[name="selected_challan"]:checked'); }
function editSelectedChallan() { let rb = getSelectedRadio(); if(rb) { window.location.href = 'delivery_challan.php?action=edit&id=' + rb.value; } else { alert('Please select a challan!'); } }
function deleteSelectedChallan() { let rb = getSelectedRadio(); if(rb && confirm('Delete this challan?')) { postDelete('delivery_challan.php', {id: rb.value, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'}); } else if(!rb) { alert('Please select a challan!'); } }
function printSelectedChallan() { 
    let rb = getSelectedRadio(); 
    if(rb) { 
        // ধাপ ১ এ তৈরি করা নতুন ফাইলটির সঠিক পাথ লিংক করা হলো
        window.open('print_delivery_challan.php?id=' + rb.value, '_blank'); 
    } else { 
        alert('Please select a challan from the list to print!'); 
    } 
}

</script>
<script src="../csrf_delete.js"></script>
</body>
</html>