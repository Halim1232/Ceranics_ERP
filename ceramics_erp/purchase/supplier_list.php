<?php
include('../auth.php'); require_page_access(); include('../config.php');
$message = "";

// ১. অটো সাপ্লায়ার কোড জেনারেশন লজিক (যেমন: SUP-0001)
$auto_query = "SELECT MAX(id) as max_id FROM erp_suppliers";
$auto_res   = mysqli_query($conn, $auto_query);
$auto_row   = mysqli_fetch_assoc($auto_res);
$next_id    = isset($auto_row['max_id']) ? ($auto_row['max_id'] + 1) : 1;
$generated_supplier_code = "SUP-" . str_pad($next_id, 4, "0", STR_PAD_LEFT);

// ২. ডাটা সেভ এবং আপডেট অ্যাকশন
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_supplier'])) {
    $supplier_code = mysqli_real_escape_string($conn, $_POST['supplier_code']);
    $supplier_name = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $company_name  = mysqli_real_escape_string($conn, $_POST['company_name']);
    $mobile_no     = mysqli_real_escape_string($conn, $_POST['mobile_no']);
    $email         = mysqli_real_escape_string($conn, $_POST['email']);
    $address       = mysqli_real_escape_string($conn, $_POST['address']);
    $status        = mysqli_real_escape_string($conn, $_POST['status']);
    
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        $update_id = (int)$_GET['id'];
        $query = "UPDATE erp_suppliers SET supplier_code='$supplier_code', supplier_name='$supplier_name', company_name='$company_name', mobile_no='$mobile_no', email='$email', address='$address', status='$status' WHERE id=$update_id";
    } else {
        $query = "INSERT INTO erp_suppliers (supplier_code, supplier_name, company_name, mobile_no, email, address, status) VALUES ('$supplier_code', '$supplier_name', '$company_name', '$mobile_no', '$email', '$address', '$status')";
    }
                     
    if (mysqli_query($conn, $query)) {
        $message = "<div class='alert success'>সাপ্লায়ারের তথ্য সফলভাবে সেভ হয়েছে।</div>";
        echo "<script>setTimeout(function(){ window.location.href='supplier_list.php'; }, 1000);</script>";
    } else {
        $message = "<div class='alert error'>ত্রুটি: " . mysqli_error($conn) . "</div>";
    }
}

// ৩. ডিলিট অ্যাকশন
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    if (mysqli_query($conn, "DELETE FROM erp_suppliers WHERE id = $delete_id")) {
        echo "<script>alert('সাপ্লায়ার সফলভাবে ডিলিট হয়েছে।'); window.location.href='supplier_list.php';</script>";
        exit;
    }
}

// ৪. এডিট ডাটা রিড
$edit_data = [];
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    $edit_res = mysqli_query($conn, "SELECT * FROM erp_suppliers WHERE id = $edit_id");
    if ($edit_res && mysqli_num_rows($edit_res) > 0) {
        $edit_data = mysqli_fetch_assoc($edit_res);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Entry Master</title>
    <link rel="stylesheet" href="https://cloudflare.com">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
        .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 15px; border: 1px solid #cdcd4a; }
        .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
        .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 20px; }
        .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #ced4da; }
        .panel-body { padding: 20px; }
        .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 20px; background: #ffffff; }
        .form-group { display: flex; flex-direction: column; gap: 3px; }
        .span-two-columns { grid-column: span 2; }
        .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; outline: none; width: 100%; box-sizing: border-box; }
        .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
        .grid-table th { background-color: #dbebe7; color: #333; padding: 8px; text-align: left; font-weight: 600; border: 1px solid #cddc39; }
        .grid-table td { padding: 6px 8px; border: 1px solid #dee2e6; background-color: #fff; }
        .grid-table tr:nth-child(even) td { background-color: #f8fafc; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .selected-row td { background-color: #bddeb6 !important; }
    </style>
</head>
<body>
<?php if(!empty($message)) echo $message; ?>

<div class="control-bar" id="actionGridButtons" style="display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'none' : 'flex'; ?>;">
    <button class="btn-action" onclick="showForm()"><i class="fa-solid fa-plus" style="color: #28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color: #3498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color: #e74c3c;"></i> Delete</button>
</div>

<div class="form-panel" id="supplierFormPanel" style="display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'block' : 'none'; ?>;">
    <form method="POST" action="">
        <div class="control-bar" style="margin-bottom: 0; border-bottom: none; border-radius: 4px 4px 0 0;">
            <button type="submit" name="save_supplier" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color: #28a745;"></i> Save</button>
            <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color: #e74c3c;"></i> Close</button>
        </div>
        <div class="panel-header">SUPPLIER MASTER INFO</div>
        <div class="panel-body">
            <div class="excel-grid">
                <!-- ROW 1 -->
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Supplier Code (Auto)</label>
                    <input type="text" name="supplier_code" class="form-control" readonly value="<?php echo isset($edit_data['supplier_code']) ? htmlspecialchars($edit_data['supplier_code']) : $generated_supplier_code; ?>">
                </div>
                <div class="form-group span-two-columns">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Supplier / Company Name</label>
                    <input type="text" name="supplier_name" class="form-control" required placeholder="e.g. Akij Ceramics Ltd." value="<?php echo isset($edit_data['supplier_name']) ? htmlspecialchars($edit_data['supplier_name']) : ''; ?>">
                </div>
                 <!-- ROW 3 -->
                <div class="form-group span-two-columns">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Office & Factory Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Full factory location address..." value="<?php echo isset($edit_data['address']) ? htmlspecialchars($edit_data['address']) : ''; ?>">
                </div>
                <!-- ROW 2 -->
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Contact Person / Brand Name</label>
                    <input type="text" name="company_name" class="form-control" placeholder="e.g. Asif Rahman" value="<?php echo isset($edit_data['company_name']) ? htmlspecialchars($edit_data['company_name']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Mobile No</label>
                    <input type="text" name="mobile_no" class="form-control" required placeholder="e.g. 017XXXXXXXX" value="<?php echo isset($edit_data['mobile_no']) ? htmlspecialchars($edit_data['mobile_no']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="e.g. supplier@email.com" value="<?php echo isset($edit_data['email']) ? htmlspecialchars($edit_data['email']) : ''; ?>">
                </div>

                    <div class="form-group">
                    <label style="font-size: 12px; font-weight: bold; color: #444;">Status</label>
                    <select name="status" class="form-control">
                        <option value="Active" <?php echo (isset($edit_data['status']) && $edit_data['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo (isset($edit_data['status']) && $edit_data['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </form>
</div>
<div style="background: #fff; padding: 15px; border: 1px solid #cdcd4a; border-radius: 4px; margin-top: 15px; display: <?php echo (isset($_GET['action']) && $_GET['action'] === 'edit') ? 'none' : 'block'; ?>;" id="productListGridArea">
    <table class="grid-table" id="supplierGridTable">
        <thead>
            <tr>
                <th style="width: 38px;"><input type="checkbox"></th>
                <th>Supplier Code</th>
                <th>Supplier / Company Name</th>
                <th>Office & Factory Address</th>
                <th>Contact Person</th>
                <th>Mobile No</th>
                <th>Email</th>
                <th>Status</th>
            </tr>
            <tr style="background: #f5f5f9;">
                <td></td>
                <td><input type="text" id="filterCode" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(1)" placeholder="Code.."></td>
                <td><input type="text" id="filterName" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(2)" placeholder="Name.."></td>
                <td><input type="text" id="filterAddress" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(3)" placeholder="Address.."></td>
                <td><input type="text" id="filterContact" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(4)" placeholder="Contact.."></td>
                <td><input type="text" id="filterMobile" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(5)" placeholder="Mobile.."></td>
                <td><input type="text" id="filterEmail" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterTable(6)" placeholder="Email.."></td>
                <td></td>
            </tr>
        </thead>
        <tbody>
            <?php
            $sup_res = mysqli_query($conn, "SELECT * FROM erp_suppliers ORDER BY id DESC");
            if ($sup_res && mysqli_num_rows($sup_res) > 0) {
                while ($row = mysqli_fetch_assoc($sup_res)) { 
            ?>
            <tr id="sup_row_<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" onclick="selectSupplierRow(this, <?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>)" style="cursor: pointer;">
                <td style="text-align: center;">
                    <input type="checkbox" class="supplier-row-select" value="<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" onclick="event.stopPropagation(); selectSupplierRow(this.closest('tr'), this.value);">
                </td>
                <td><?php echo htmlspecialchars($row['supplier_code']); ?></td>
                <td style="color: #4050b3; font-weight: 500;"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                <td style="color: #555; font-size: 12px;"><?php echo htmlspecialchars($row['address']); ?></td>
                <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                <td><?php echo htmlspecialchars($row['mobile_no']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td><?php echo htmlspecialchars($row['status']); ?></td>
            </tr>
            <?php 
                }
            } else { echo "<tr><td colspan='8' style='text-align:center; padding:15px; color:#999;'>No supplier records found.</td></tr>"; }
            ?>
        </tbody>
    </table>
</div>

<script>
function showForm() {
    document.getElementById('supplierFormPanel').style.display = 'block';
    document.getElementById('actionGridButtons').style.display = 'none';
    document.getElementById('productListGridArea').style.display = 'none';
}
function hideForm() {
    document.getElementById('supplierFormPanel').style.display = 'none';
    document.getElementById('actionGridButtons').style.display = 'flex';
    document.getElementById('productListGridArea').style.display = 'block';
    window.location.href = 'supplier_list.php';
}
function filterTable(colIndex) {
    var inputIds = ["", "filterCode", "filterName", "filterAddress", "filterContact", "filterMobile", "filterEmail"];
    var input = document.getElementById(inputIds[colIndex]).value.toUpperCase();
    var table = document.getElementById("supplierGridTable");
    var tr = table.getElementsByTagName("tr");
    for (var i = 2; i < tr.length; i++) {
        var td = tr[i].getElementsByTagName('td')[colIndex];
        if (td) {
            var txtValue = td.textContent || td.innerText;
            tr[i].style.display = (txtValue.toUpperCase().indexOf(input) > -1) ? "" : "none";
        }
    }
}
var selectedSupplierId = null;
function selectSupplierRow(rowElement, id) {
    document.querySelectorAll("#supplierGridTable tbody tr").forEach(function(tr) { tr.style.backgroundColor = ""; });
    document.querySelectorAll('.supplier-row-select').forEach(function(chk) { chk.checked = false; });
    rowElement.style.backgroundColor = "#bddeb6";
    var chkBox = rowElement.querySelector('.supplier-row-select');
    if (chkBox) chkBox.checked = true;
    selectedSupplierId = id;
}
function editSelected() {
    if (!selectedSupplierId) { alert('অনুগ্রহ করে একটি সাপ্লায়ার সিলেক্ট করুন!'); return; }
    window.location.href = 'supplier_list.php?action=edit&id=' + selectedSupplierId;
}
function deleteSelected() {
    if (!selectedSupplierId) { alert('অনুগ্রহ করে একটি সাপ্লায়ার সিলেক্ট করুন!'); return; }
    if (!confirm('আপনি কি নিশ্চিত যে এই সাপ্লায়ার রেকর্ডটি ডিলিট করতে চান?')) return;
    postDelete('supplier_list.php', {id: selectedSupplierId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
}
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>
