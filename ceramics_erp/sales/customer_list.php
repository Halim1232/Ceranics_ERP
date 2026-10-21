<?php
// ১. ডাটাবেজ কানেকশন ও মেসেজ ইনিশিয়ালাইজেশন
include('../auth.php'); require_page_access(); include('../config.php');
$message = "";

// ২. অটো কাস্টমার কোড জেনারেশন লজিক
$auto_query  = mysqli_query($conn, "SELECT MAX(id) as max_id FROM erp_customers");
$auto_row    = mysqli_fetch_assoc($auto_query);
$next_id     = isset($auto_row['max_id']) ? ($auto_row['max_id'] + 1) : 1;
$generated_customer_code = "CUST-" . str_pad($next_id, 4, "0", STR_PAD_LEFT);

// ৩. ডাটা সেভ এবং আপডেট অ্যাকশন
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer'])) {
    $customer_code = mysqli_real_escape_string($conn, $_POST['customer_code']);
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $address       = mysqli_real_escape_string($conn, $_POST['address']);
    $phone         = mysqli_real_escape_string($conn, $_POST['phone']);
    $opening_due   = mysqli_real_escape_string($conn, $_POST['opening_due']);
    
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
        $update_id = (int)$_GET['id'];
        $query = "UPDATE erp_customers SET customer_code='$customer_code', customer_name='$customer_name', address='$address', phone='$phone', opening_due='$opening_due' WHERE id=$update_id";
    } else {
        $query = "INSERT INTO erp_customers (customer_code, customer_name, address, phone, opening_due) VALUES ('$customer_code', '$customer_name', '$address', '$phone', '$opening_due')";
    }
    
    if (mysqli_query($conn, $query)) {
        echo "<script>window.location.href='customer_list.php';</script>";
        exit;
    }
}

// ৪. ডিলিট অ্যাকশন
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    if (mysqli_query($conn, "DELETE FROM erp_customers WHERE id = $delete_id")) {
        echo "<script>window.location.href='customer_list.php';</script>";
        exit;
    }
}

// ৫. এডিট ডেটা প্রি-লোড
$edit_data = [];
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    $edit_res = mysqli_query($conn, "SELECT * FROM erp_customers WHERE id = $edit_id");
    if ($edit_res && mysqli_num_rows($edit_res) > 0) {
        $edit_data = mysqli_fetch_assoc($edit_res);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Entry Master</title>
    <link rel="stylesheet" href="https://cloudflare.com">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
        .control-bar { background: #ececec; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 15px; border: 1px solid #cddc39; }
        .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px; }
        .btn-action:hover { background: #f1f1f1; }
        
        /* কাস্টমার ফর্মকে স্ক্রিনের মাঝখানে পপ-আপ মোডাল বানানোর সিএসএস */
        .form-panel { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); display: flex; justify-content: center; align-items: center; z-index: 9999; }
        .modal-content { background: #ffffff; border: 1px solid #cddc39; border-radius: 6px; padding: 20px; width: 100%; max-width: 450px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); box-sizing: border-box; }
        .panel-header { background: #ececec; padding: 10px 15px; font-size: 13px; font-weight: bold; color: #405057; border-bottom: 1px solid #cddc39; margin: -20px -20px 15px -20px; border-radius: 6px 6px 0 0; }
        
        /* ১ কলামের সোজা সারির এক্সেল লেআউট */
        .excel-grid { display: grid; grid-template-columns: 1fr; gap: 12px 0px; background: #ffffff; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; outline: none; width: 100%; box-sizing: border-box; }
        
        /* সাপ্লায়ার লিস্টের হুবহু টেবিল থিম */
        .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
        .grid-table th { background-color: #dbede7; color: #333; padding: 8px; text-align: left; font-weight: 600; border: 1px solid #cddc39; }
        .grid-table td { padding: 6px 8px; border: 1px solid #dbede7; background-color: #fff; }
        .grid-table tr:nth-child(even) td { background-color: #f8fafc; }
        .selected-row td { background-color: #bbdeeb !important; }
    </style>
</head>
<body>
<!-- টপ অ্যাকশন বাটন কন্ট্রোল বার (সবসময় দেখা যাবে) -->
<div class="control-bar" id="actionGridButtons" style="display: flex;">
    <button class="btn-action" onclick="showForm()"><i class="fa-solid fa-plus" style="color: #28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color: #3498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color: #e74c3c;"></i> Delete</button>
</div>

<!-- স্ক্রিনের মাঝখানে ওপেন হওয়া ১ সারির সেন্ট্রাল পপ-আপ মোডাল ফর্ম -->
<div class="form-panel" id="customerFormPanel" style="display: <?php echo (isset($_GET['action']) && ($_GET['action']=='edit' || $_GET['action']=='new')) ? 'flex' : 'none'; ?>;">
    <div class="modal-content">
        <form method="POST" action="">
            <div class="panel-header">CUSTOMER MASTER INFO</div>
            
            <div class="excel-grid">
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Customer Code (Auto)</label>
                    <input type="text" name="customer_code" class="form-control" readonly value="<?php echo isset($edit_data['customer_code']) ? htmlspecialchars($edit_data['customer_code']) : $generated_customer_code; ?>">
                </div>
                
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Customer Name *</label>
                    <input type="text" name="customer_name" class="form-control" required placeholder="e.g. Abir Ceramics" value="<?php echo isset($edit_data['customer_name']) ? htmlspecialchars($edit_data['customer_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Mobile No *</label>
                    <input type="text" name="phone" class="form-control" required placeholder="e.g. 017XXXXXXXX" value="<?php echo isset($edit_data['phone']) ? htmlspecialchars($edit_data['phone']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Office & Factory Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Full Address..." value="<?php echo isset($edit_data['address']) ? htmlspecialchars($edit_data['address']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Opening Due</label>
                    <input type="number" step="0.01" name="opening_due" class="form-control" value="<?php echo isset($edit_data['opening_due']) ? htmlspecialchars($edit_data['opening_due']) : '0.00'; ?>">
                </div>
            </div>
            
            <div style="margin-top: 15px; text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color: #e74c3c;"></i> Close</button>
                <button type="submit" name="save_customer" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color: #28a745;"></i> Save Customer</button>
            </div>
        </form>
    </div>
</div>
<!-- গ্রিড ডেটা টেবিল ভিউ এরিয়া (ফর্ম মোডাল ওপেন থাকলে এটি সম্পূর্ণ হাইড থাকবে) -->
<div style="background: #fff; padding: 15px; border: 1px solid #cddc39; border-radius: 4px; margin-top: 15px; display: <?php echo (isset($_GET['action']) && ($_GET['action']=='edit' || $_GET['action']=='new')) ? 'none' : 'block'; ?>;">
    <table class="grid-table" id="customerGridTable">
        <thead>
            <tr>
                <th style="width: 38px; text-align: center;"><input type="checkbox"></th>
                <th>Customer Code</th>
                <th>Customer Name</th>
                <th>Office & Factory Address</th>
                <th>Mobile No</th>
                <th>Opening Due</th>
            </tr>
            <tr style="background: #f5f5f5;">
                <td></td>
                <td><input type="text" id="filterCode" style="width:100%; box-sizing:border-box; height:24px;" onkeyup="filterTable(1)" placeholder="Code..."></td>
                <td><input type="text" id="filterName" style="width:100%; box-sizing:border-box; height:24px;" onkeyup="filterTable(2)" placeholder="Name..."></td>
                <td><input type="text" id="filterAddress" style="width:100%; box-sizing:border-box; height:24px;" onkeyup="filterTable(3)" placeholder="Address..."></td>
                <td><input type="text" id="filterMobile" style="width:100%; box-sizing:border-box; height:24px;" onkeyup="filterTable(4)" placeholder="Mobile..."></td>
                <td><input type="text" id="filterDue" style="width:100%; box-sizing:border-box; height:24px;" onkeyup="filterTable(5)" placeholder="Due..."></td>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sup_res = mysqli_query($conn, "SELECT * FROM erp_customers ORDER BY id DESC");
            if ($sup_res && mysqli_num_rows($sup_res) > 0) {
                while ($row = mysqli_fetch_assoc($sup_res)) {
            ?>
            <tr id="sup_row_<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" onclick="selectCustomerRow(this, <?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>)" style="cursor: pointer;">
                <td style="text-align: center;">
                    <input type="checkbox" class="customer-row-select" value="<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" onclick="event.stopPropagation(); selectCustomerRow(this.closest('tr'), <?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>)">
                </td>
                <td style="color: #405057; font-weight: 500;"><?php echo htmlspecialchars($row['customer_code']); ?></td>
                <td style="color: #1a427a; font-weight: bold;"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                <td><?php echo htmlspecialchars($row['address']); ?></td>
                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                <td style="text-align: right; font-weight: bold;"><?php echo number_format($row['opening_due'], 2); ?></td>
            </tr>
            <?php 
                } 
            } else { 
            ?>
            <tr>
                <td colspan="6" style="text-align: center; color: #888; padding: 20px; font-style: italic;">No records found.</td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
<script>
// ১. পপ-আপ ফর্ম কন্ট্রোল
function showForm() {
    window.location.href = "customer_list.php?action=new";
}

function hideForm() {
    window.location.href = "customer_list.php";
}

// ২. ইনলাইন লাইভ এক্সেল ফিল্টারিং ইঞ্জিন (সাপ্লায়ার পেজের লজিক)
function filterTable(colIndex) {
    var inputIds = ["", "filterCode", "filterName", "filterAddress", "filterMobile", "filterDue"];
    var input = document.getElementById(inputIds[colIndex]);
    var filter = input.value.toUpperCase();
    var table = document.getElementById("customerGridTable");
    var tr = table.getElementsByTagName("tr");

    for (var i = 2; i < tr.length; i++) {
        var td = tr[i].getElementsByTagName("td")[colIndex];
        if (td) {
            var txtValue = td.textContent || td.innerText;
            tr[i].style.display = (txtValue.toUpperCase().indexOf(filter) > -1) ? "" : "none";
        }
    }
}

// ৩. রো সিলেকশন মেথড
var selectedCustomerId = null;
function selectCustomerRow(rowElement, id) {
    document.querySelectorAll("#customerGridTable tbody tr").forEach(function (tr) { 
        tr.classList.remove("selected-row"); 
    });
    document.querySelectorAll(".customer-row-select").forEach(function (chk) { 
        chk.checked = false; 
    });
    
    rowElement.classList.add("selected-row");
    var chkBox = rowElement.querySelector(".customer-row-select");
    if(chkBox) chkBox.checked = true;
    selectedCustomerId = id;
}

// ৪. এডিট এবং ডিলিট কন্ট্রোলার অ্যাকশন
function editSelected() {
    if (!selectedCustomerId) { alert("অনুগ্রহ করে একটি কাস্টমার রো সিলেক্ট করুন!"); return; }
    window.location.href = "customer_list.php?action=edit&id=" + selectedCustomerId;
}

// ৫. ডিলিট কন্ট্রোলার
function deleteSelected() {
    if (!selectedCustomerId) { alert("অনুগ্রহ করে একটি কাস্টমার রো সিলেক্ট করুন!"); return; }
    if (confirm("আপনি কি নিশ্চিত এটি ডিলিট করতে চান?")) {
        postDelete('customer_list.php', {id: selectedCustomerId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
    }
}
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>
