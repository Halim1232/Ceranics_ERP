<?php
// ১. ডাটাবেজ কানেকশন ইনক্লুড করা (product ফোল্ডার থেকে এক ধাপ বাইরে)
include('../auth.php'); require_page_access(); include('../config.php');

$message = "";

// 🚀 ২. অটো-ইউনিট কোড জেনারেশন লজিক (সর্বশেষ আইডি + ১ করে কোড বানাবে)
$auto_query = "SELECT MAX(id) as max_id FROM erp_product_units";
$auto_res = mysqli_query($conn, $auto_query);
$auto_row = mysqli_fetch_assoc($auto_res);
$next_id = $auto_row['max_id'] ? ($auto_row['max_id'] + 1) : 101;
$generated_unit_code = "UNT-" . $next_id;

// ৩. নতুন ইউনিট সেভ এবং আপডেট লজিক
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_unit'])) {
    $unit_code = mysqli_real_escape_string($conn, $_POST['unit_code']);
    $unit_name = mysqli_real_escape_string($conn, $_POST['unit_name']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;

    if ($edit_id > 0) {
        // 🚀 আপডেট লজিক (Edit Mode)
        $query = "UPDATE erp_product_units SET unit_code='$unit_code', unit_name='$unit_name', status='$status' WHERE id=$edit_id";
        if (mysqli_query($conn, $query)) {
            $message = "<div class='alert success'>ইউনিটটি সফলভাবে আপডেট হয়েছে!</div>";
        } else {
            $message = "<div class='alert error'>আপডেট ব্যর্থ হয়েছে: " . mysqli_error($conn) . "</div>";
        }
    } else {
        // 🚀 ইনসার্ট লজিক (New Mode)
        $query = "INSERT INTO erp_product_units (unit_code, unit_name, status) VALUES ('$unit_code', '$unit_name', '$status')";
        if (mysqli_query($conn, $query)) {
            $message = "<div class='alert success'>নতুন ইউনিট সফলভাবে সেভ হয়েছে!</div>";
            $generated_unit_code = "UNT-" . ($next_id + 1);
        } else {
            $message = "<div class='alert error'>সেভ ব্যর্থ হয়েছে: " . mysqli_error($conn) . "</div>";
        }
    }
}

// ৪. ডিলিট করার পিএইচপি লজিক
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $del_id = (int)($_POST['id'] ?? 0);
    if (mysqli_query($conn, "DELETE FROM erp_product_units WHERE id=$del_id")) {
        header("Location: product_unit.php?status=deleted");
        exit();
    }
}

if (isset($_GET['status']) && $_GET['status'] == 'deleted') {
    $message = "<div class='alert error'>ইউনিটটি সফলভাবে ডিলিট করা হয়েছে!</div>";
}

// ৫. ডাটাবেজ থেকে ইউনিট লিস্ট তুলে আনার কুয়েরি
$units_result = mysqli_query($conn, "SELECT * FROM erp_product_units ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Unit Master</title>
    <link rel="stylesheet" href="https://cloudflare.com">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
        .control-bar { background: #e9ecef; padding: 12px; border-radius: 4px; display: flex; gap: 10px; margin-bottom: 15px; border: 1px solid #ced4da; }
        .btn-action { padding: 8px 20px; font-size: 14px; font-weight: bold; border: 1px solid #999; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
        .btn-action:hover { background: #dee2e6; }
        .form-panel { background: #fff; border: 1px solid #ced4da; border-radius: 4px; margin-bottom: 20px; display: none; }
        .panel-header { background: #e9ecef; padding: 10px 15px; font-size: 14px; font-weight: bold; color: #495057; border-bottom: 1px solid #ced4da; text-transform: uppercase; }
        .panel-body { padding: 25px; }
        .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px 35px; }
        .form-group { display: grid; grid-template-columns: 140px 1fr; align-items: center; }
        label { font-size: 14px; font-weight: 600; color: #333; }
        .form-control { height: 38px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 15px; outline: none; width: 100%; box-sizing: border-box; background: #fff; }
        .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #ced4da; }
        .grid-table th { background-color: #dbe2ef; color: #333; padding: 10px; text-align: left; font-weight: 600; border: 1px solid #ced4da; font-size: 13px; }
        .grid-table td { padding: 10px; border: 1px solid #dee2e6; background: #fff; }
        .grid-table tr:nth-child(even) td { background: #f8f9fa; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge { background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <?php echo $message; ?>
    <!-- 🎛️ ১. অ্যাকশন বাটন কন্ট্রোল বার -->
    <div class="control-bar" id="actionGridButtons">
        <button class="btn-action" onclick="showForm('new')"><i class="fa-solid fa-plus" style="color: #28a745;"></i> New</button>
        <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color: #3498db;"></i> Edit</button>
        <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color: #dc3545;"></i> Delete</button>
    </div>

    <!-- 📝 ২. ৩-কলামের বড় প্রফেশনাল ইনপুট ফর্ম প্যানেল -->
    <div class="form-panel" id="unitFormPanel">
        <form method="POST" action="" id="mainUnitForm">
            <input type="hidden" name="edit_id" id="edit_id" value="0">
            <div class="control-bar" style="margin-bottom: 0; border-bottom: none; border-radius: 4px 4px 0 0;">
                <button type="submit" name="save_unit" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color: #28a745;"></i> Save</button>
                <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color: #dc3545;"></i> Close</button>
            </div>
            <div class="panel-header" id="formHeader">PRODUCT UNIT INFO (NEW MODE)</div>
            <div class="panel-body">
                <div class="excel-grid">
                    <div class="form-group">
                        <label>Unit Code *</label>
                        <input type="text" name="unit_code" id="unit_code" class="form-control" required value="<?php echo $generated_unit_code; ?>">
                    </div>
                    <div class="form-group">
                        <label>Unit Name (UOM) *</label>
                        <input type="text" name="unit_name" id="unit_name" class="form-control" style="border-color: #28a745;" required placeholder="যেমন: SFT, PCS, BOX">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- 📊 ৩. কলাম সমান্তরাল লাইভ ফিল্টার সহ ডাটা গ্রিড টেবিল -->
    <div style="background: #fff; padding: 15px; border: 1px solid #ced4da; border-radius: 4px;" id="unitGridArea">
        <table class="grid-table" id="unitGridTable">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Unit Code</th>
                    <th>Product Unit Name</th>
                    <th>Status</th>
                </tr>
                <tr style="background: #f1f5f9;">
                    <td></td>
                    <td><input type="text" id="fltCode" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterUnitTable(1)" placeholder="🔍 Code..."></td>
                    <td><input type="text" id="fltName" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterUnitTable(2)" placeholder="🔍 Unit Name..."></td>
                    <td></td>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($units_result)) { ?>
                <tr>
                    <td><input type="checkbox" class="row-select" value="<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" data-code="<?php echo htmlspecialchars((string)$row['unit_code'], ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars((string)$row['unit_name'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES); ?>" onclick="onlyOneCheck(this)"></td>
                    <td><?php echo htmlspecialchars((string)$row['unit_code'], ENT_QUOTES); ?></td>
                    <td style="color: #0056b3; font-weight: 500;"><?php echo htmlspecialchars((string)$row['unit_name'], ENT_QUOTES); ?></td>
                    <td><span class="badge"><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES); ?></span></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <script>
    function onlyOneCheck(cb) {
        var cbs = document.getElementsByClassName('row-select');
        for (var i = 0; i < cbs.length; i++) { if (cbs[i] !== cb) cbs[i].checked = false; }
    }
    function showForm(mode) {
        document.getElementById('unitFormPanel').style.display = 'block';
        document.getElementById('actionGridButtons').style.display = 'none';
        document.getElementById('unitGridArea').style.display = 'none';
        if(mode === 'new') {
            document.getElementById('edit_id').value = "0"; 
            document.getElementById('unit_name').value = ""; 
            document.getElementById('status').value = "Active";
            document.getElementById('formHeader').innerText = "PRODUCT UNIT INFO (NEW MODE)";
        }
    }
    function hideForm() {
        document.getElementById('unitFormPanel').style.display = 'none';
        document.getElementById('actionGridButtons').style.display = 'flex';
        document.getElementById('unitGridArea').style.display = 'block';
    }
    function editSelected() {
        var sel = document.querySelector('.row-select:checked');
        if (!sel) { alert('অনুগ্রহ করে এডিট করার জন্য একটি রো সিলেক্ট করুন!'); return; }
        showForm('edit');
        document.getElementById('formHeader').innerText = "PRODUCT UNIT INFO (EDIT MODE)";
        document.getElementById('edit_id').value = sel.value;
        document.getElementById('unit_code').value = sel.getAttribute('data-code');
        document.getElementById('unit_name').value = sel.getAttribute('data-name');
        document.getElementById('status').value = sel.getAttribute('data-status');
    }
    function deleteSelected() {
        var sel = document.querySelector('.row-select:checked');
        if (!sel) { alert('অনুগ্রহ করে ডিলিট করার জন্য একটি রো সিলেক্ট করুন!'); return; }
        if (confirm('আপনি কি নিশ্চিত যে এই ইউনিটটি চিরতরে ডিলিট করতে চান?')) {
            postDelete('product_unit.php', {id: sel.value, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
        }
    }
    function filterUnitTable(colIdx) {
        var ids = ["", "fltCode", "fltName"];
        var input = document.getElementById(ids[colIdx]).value.toUpperCase();
        var tr = document.getElementById('unitGridTable').getElementsByTagName('tr');
        for (var i = 2; i < tr.length; i++) {
            var td = tr[i].getElementsByTagName('td')[colIdx];
            if (td) { tr[i].style.display = (td.textContent || td.innerText).toUpperCase().indexOf(input) > -1 ? "" : "none"; }
        }
    }
    </script>
<script src="../csrf_delete.js"></script>
</body>
</html>
