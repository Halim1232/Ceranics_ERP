<?php
// ১. ডাটাবেজ কানেকশন ইনক্লুড করা
include('../auth.php'); require_page_access(); include('../config.php');

$message = "";

// 🚀 ২. অটো-কোড জেনারেট করার প্রফেশনাল লজিক (সর্বশেষ আইডি + ১ করে কোড বানাবে)
$auto_code_query = "SELECT MAX(id) as max_id FROM erp_product_groups";
$auto_code_result = mysqli_query($conn, $auto_code_query);
$auto_code_row = mysqli_fetch_assoc($auto_code_result);
$next_id = $auto_code_row['max_id'] ? ($auto_code_row['max_id'] + 1) : 101;
$generated_group_code = "G-" . $next_id;

// ৩. নতুন গ্রুপ সেভ অথবা পুরাতন গ্রুপ আপডেট করার লজিক
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_group'])) {
    $group_code = mysqli_real_escape_string($conn, $_POST['group_code']);
    $group_name = mysqli_real_escape_string($conn, $_POST['group_name']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;

    if ($edit_id > 0) {
        // 🚀 আপডেট লজিক (Edit Mode)
        $update_query = "UPDATE erp_product_groups SET group_code='$group_code', group_name='$group_name', status='$status' WHERE id=$edit_id";
        if (mysqli_query($conn, $update_query)) {
            $message = "<div class='alert success'>প্রোডাক্ট গ্রুপটি সফলভাবে আপডেট হয়েছে!</div>";
        } else {
            $message = "<div class='alert error'>আপডেট ব্যর্থ হয়েছে: " . mysqli_error($conn) . "</div>";
        }
    } else {
        // 🚀 ইনসার্ট লজিক (New Mode)
        $insert_query = "INSERT INTO erp_product_groups (group_code, group_name, status) VALUES ('$group_code', '$group_name', '$status')";
        if (mysqli_query($conn, $insert_query)) {
            $message = "<div class='alert success'>নতুন প্রোডাক্ট গ্রুপ সফলভাবে সেভ হয়েছে!</div>";
            // নতুন কোড রি-জেনারেট করা যেন ফরম ফ্রেশ থাকে
            $generated_group_code = "G-" . ($next_id + 1);
        } else {
            $message = "<div class='alert error'>সেভ ব্যর্থ হয়েছে: " . mysqli_error($conn) . "</div>";
        }
    }
}

// ৪. ডিলিট করার পিএইচপি লজিক (Delete Action)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    $delete_query = "DELETE FROM erp_product_groups WHERE id=$delete_id";
    if (mysqli_query($conn, $delete_query)) {
        header("Location: product_group.php?status=deleted");
        exit();
    }
}

if (isset($_GET['status']) && $_GET['status'] == 'deleted') {
    $message = "<div class='alert error'>প্রোডাক্ট গ্রুপটি সফলভাবে ডিলিট করা হয়েছে!</div>";
}

// ৫. ডাটাবেজ থেকে গ্রুপ লিস্ট তুলে আনার কুয়েরি
$groups_query = "SELECT * FROM erp_product_groups ORDER BY id DESC";
$groups_result = mysqli_query($conn, $groups_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Group Master</title>
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
        .form-control:focus { border-color: #28a745; box-shadow: 0 0 0 3px rgba(40,167,69,0.1); }
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

    <!-- 📝 ২. ৩-কলামের প্রফেশনাল গ্রুপ ইনপুট ফর্ম প্যানেল -->
    <div class="form-panel" id="groupFormPanel">
        <form method="POST" action="" id="mainGroupForm">
            <!-- এডিট মোড ট্র্যাকিং এর জন্য হাইডেন ইনপুট -->
            <input type="hidden" name="edit_id" id="edit_id" value="0">
            
            <div class="control-bar" style="margin-bottom: 0; border-bottom: none; border-radius: 4px 4px 0 0;">
                <button type="submit" name="save_group" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color: #28a745;"></i> Save</button>
                <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color: #dc3545;"></i> Close</button>
            </div>
            
            <div class="panel-header" id="formPanelHeader">PRODUCT GROUP INFO</div>
            <div class="panel-body">
                <div class="group-grid">
                    <div class="form-group">
                        <label>Group Code *</label>
                        <!-- 🚀 ডাইনামিকালি জেনারেটেড কোডটি এখানে অটো-বসে যাবে, যা পরে টাইপ করে এডিট করাও সম্ভব -->
                        <input type="text" name="group_code" id="group_code" class="form-control" required value="<?php echo $generated_group_code; ?>">
                    </div>
                    <div class="form-group">
                        <label>Group Name *</label>
                        <input type="text" name="group_name" id="group_name" class="form-control" style="border-color: #28a745;" required placeholder="যেমন: Tiles">
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
    <div style="background: #fff; padding: 15px; border: 1px solid #ced4da; border-radius: 4px;" id="groupListGridArea">
        <table class="grid-table" id="groupGridTable">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Group Code</th>
                    <th>Product Group Name</th>
                    <th>Status</th>
                </tr>
                <tr style="background: #f1f5f9;">
                    <td></td>
                    <td><input type="text" id="filterGroupCode" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterGroupTable(1)" placeholder="🔍 Code..."></td>
                    <td><input type="text" id="filterGroupName" style="width:100%; box-sizing:border-box; height:24px; font-size:11px;" onkeyup="filterGroupTable(2)" placeholder="🔍 Group Name..."></td>
                    <td></td>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($groups_result)) { ?>
                <tr>
                    <!-- 🚀 চেকবক্সের সাথে ডেটা অ্যাট্রিবিউট পাস করা হচ্ছে যেন জাভাস্ক্রিপ্ট দিয়ে রিড করা যায় -->
                    <td><input type="checkbox" class="row-select" value="<?php echo htmlspecialchars((string)$row['id'], ENT_QUOTES); ?>" data-code="<?php echo htmlspecialchars((string)$row['group_code'], ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars((string)$row['group_name'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES); ?>" onclick="onlyOneCheck(this)"></td>
                    <td><?php echo htmlspecialchars((string)$row['group_code'], ENT_QUOTES); ?></td>
                    <td style="color: #0056b3; font-weight: 500;"><?php echo htmlspecialchars((string)$row['group_name'], ENT_QUOTES); ?></td>
                    <td><span class="badge"><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES); ?></span></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <script>
    // একাধিক চেকবক্সে একসাথে টিক দেওয়া বন্ধ করার লজিক (ERP Standard)
    function onlyOneCheck(checkbox) {
        var checkboxes = document.getElementsByClassName('row-select');
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i] !== checkbox) checkboxes[i].checked = false;
        }
    }

    function showForm(mode) {
        document.getElementById('groupFormPanel').style.display = 'block';
        document.getElementById('actionGridButtons').style.display = 'none';
        document.getElementById('groupListGridArea').style.display = 'none';
        if(mode === 'new') {
            document.getElementById('edit_id').value = "0";
            document.getElementById('group_name').value = "";
            document.getElementById('status').value = "Active";
            document.getElementById('formPanelHeader').innerText = "PRODUCT GROUP INFO (NEW MODE)";
        }
    }
    
    function hideForm() {
        document.getElementById('groupFormPanel').style.display = 'none';
        document.getElementById('actionGridButtons').style.display = 'flex';
        document.getElementById('groupListGridArea').style.display = 'block';
    }

    // 🚀 এডিট বাটন সচল করার মাস্টার ফাংশন
    function editSelected() {
        var selected = document.querySelector('.row-select:checked');
        if (!selected) {
            alert('অনুগ্রহ করে এডিট করার জন্য যেকোনো একটি রো সিলেক্ট করুন!');
            return;
        }
        
        // ফরম রেডি করা এবং ডেটা পুশ করা
        showForm('edit');
        document.getElementById('formPanelHeader').innerText = "PRODUCT GROUP INFO (EDIT MODE)";
        document.getElementById('edit_id').value = selected.value;
        document.getElementById('group_code').value = selected.getAttribute('data-code');
        document.getElementById('group_name').value = selected.getAttribute('data-name');
        document.getElementById('status').value = selected.getAttribute('data-status');
    }

    // 🚀 ডিলিট বাটন সচল করার মাস্টার ফাংশন
    function deleteSelected() {
        var selected = document.querySelector('.row-select:checked');
        if (!selected) {
            alert('অনুগ্রহ করে ডিলিট করার জন্য যেকোনো একটি রো সিলেক্ট করুন!');
            return;
        }
        
        if (confirm('আপনি কি নিশ্চিত যে এই প্রোডাক্ট গ্রুপটি চিরতরে ডিলিট করতে চান?')) {
            postDelete('product_group.php', {id: selected.value, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
        }
    }

    // কলাম সমান্তরাল লাইভ সার্চ ফিল্টার
    function filterGroupTable(colIndex) {
        var inputIds = ["", "filterGroupCode", "filterGroupName"];
        var input = document.getElementById(inputIds[colIndex]).value.toUpperCase();
        var table = document.getElementById('groupGridTable');
        var tr = table.getElementsByTagName('tr');

        for (var i = 2; i < tr.length; i++) {
            var td = tr[i].getElementsByTagName('td')[colIndex];
            if (td) {
                var txtValue = td.textContent || td.innerText;
                tr[i].style.display = (txtValue.toUpperCase().indexOf(input) > -1) ? "" : "none";
            }
        }
    }
    </script>
<script src="../csrf_delete.js"></script>
</body>
</html>
