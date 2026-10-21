<?php
// user_&_employee/employee_designation.php -- Designation master (employee_list.php এর ড্রপডাউন এখান থেকে আসে)
include('../auth.php'); require_page_access(); include('../config.php');
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function back($m) { echo "<script>alert(" . json_encode($m) . "); window.location.href='employee_designation.php';</script>"; exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_designations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    designation_name VARCHAR(100) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Active',
    UNIQUE KEY uq_designation_name (designation_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) back('অবৈধ অনুরোধ! পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');

    // ---------- DELETE ----------
    if (($_POST['do'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = 0;
        try {
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM erp_employees WHERE designation_id=?");
            $st->bind_param("i", $id); $st->execute();
            $used = (int)$st->get_result()->fetch_assoc()['c'];
        } catch (Throwable $e) { $used = 0; }   // erp_employees টেবিল না থাকলে ব্যবহার নেই
        if ($used > 0) back("এই পদবীতে $used জন কর্মচারী আছে, ডিলিট করা যাবে না। Inactive করে দিন।");
        $st = $conn->prepare("DELETE FROM erp_designations WHERE id=?");
        $st->bind_param("i", $id); $st->execute();
        back('পদবী ডিলিট হয়েছে।');
    }

    // ---------- SAVE ----------
    if (isset($_POST['save_designation'])) {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['designation_name'] ?? '');
        $status = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';
        if ($name === '') back('পদবীর নাম আবশ্যক!');
        $st = $conn->prepare("SELECT id FROM erp_designations WHERE designation_name=? AND id<>?");
        $st->bind_param("si", $name, $id); $st->execute();
        if ($st->get_result()->num_rows > 0) back('এই নামে পদবী আগে থেকেই আছে!');
        if ($id > 0) {
            $st = $conn->prepare("UPDATE erp_designations SET designation_name=?, status=? WHERE id=?");
            $st->bind_param("ssi", $name, $status, $id);
        } else {
            $st = $conn->prepare("INSERT INTO erp_designations (designation_name, status) VALUES (?,?)");
            $st->bind_param("ss", $name, $status);
        }
        $st->execute();
        back('পদবী সংরক্ষিত হয়েছে।');
    }
}

$rows = [];
try {
    $q = mysqli_query($conn, "SELECT d.*, (SELECT COUNT(*) FROM erp_employees e WHERE e.designation_id = d.id) AS emp_count FROM erp_designations d ORDER BY d.id DESC");
} catch (Throwable $e) {   // erp_employees এখনো না থাকলে
    $q = mysqli_query($conn, "SELECT d.*, 0 AS emp_count FROM erp_designations d ORDER BY d.id DESC");
}
while ($q && $r = mysqli_fetch_assoc($q)) $rows[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Designation</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 15px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f1f1f1; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); justify-content: center; align-items: center; z-index: 9999; }
    .modal-content { background: #fff; border: 1px solid #cddc39; border-radius: 6px; padding: 20px; width: 100%; max-width: 380px; box-shadow: 0 4px 15px rgba(0,0,0,.2); }
    .panel-header { background: #ececef; padding: 10px 15px; font-size: 13px; font-weight: bold; color: #405057; border-bottom: 1px solid #cddc39; margin: -20px -20px 15px; border-radius: 6px 6px 0 0; }
    .form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
    .form-group label { font-size: 12px; font-weight: bold; color: #444; }
    .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 6px 8px; border: 1px solid #dbebe7; background: #fff; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .selected-row td { background: #bbdeeb !important; }
    .st-Active { color: #28a745; font-weight: bold; } .st-Inactive { color: #dc3545; font-weight: bold; }
    .c { text-align: center; }
</style>
</head>
<body>

<div class="control-bar">
    <button class="btn-action" onclick="openForm()"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color:#3498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color:#e74c3c;"></i> Delete</button>
</div>

<div class="modal" id="formModal">
  <div class="modal-content">
    <form method="POST" action="employee_designation.php">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="panel-header" id="f_title">EMPLOYEE DESIGNATION</div>
      <div class="form-group"><label>Designation Name *</label><input type="text" name="designation_name" id="f_name" class="form-control" required maxlength="100" placeholder="যেমন: Manager, Salesman, Storekeeper"></div>
      <div class="form-group"><label>Status</label>
        <select name="status" id="f_status" class="form-control"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
      <div style="text-align:right; display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" class="btn-action" onclick="closeForm()"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        <button type="submit" name="save_designation" value="1" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color:#28a745;"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<form id="delForm" method="POST" action="employee_designation.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="do" value="delete"><input type="hidden" name="id" id="delId">
</form>

<div style="background:#fff; padding:15px; border:1px solid #cddc39; border-radius:4px;">
<table class="grid-table" id="dTable">
  <thead>
    <tr><th style="width:38px;"></th><th style="width:40px;">SL</th><th>Designation Name</th><th class="c" style="width:120px;">Employees</th><th style="width:100px;">Status</th></tr>
    <tr style="background:#f5f5f5;"><td></td><td></td>
      <td><input type="text" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="flt(this)" placeholder="Search..."></td><td></td><td></td></tr>
  </thead>
  <tbody>
  <?php $sl = 1; foreach ($rows as $r) { ?>
    <tr class="drow" style="cursor:pointer;" onclick="pick(this)" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo h($r['designation_name']); ?>" data-status="<?php echo h($r['status']); ?>" data-count="<?php echo (int)$r['emp_count']; ?>">
      <td class="c"><input type="radio" name="sel"></td>
      <td><?php echo $sl++; ?></td>
      <td style="font-weight:bold; color:#1a427a;"><?php echo h($r['designation_name']); ?></td>
      <td class="c"><?php echo (int)$r['emp_count']; ?></td>
      <td class="st-<?php echo h($r['status']); ?>"><?php echo h($r['status']); ?></td>
    </tr>
  <?php } if (!$rows) echo "<tr><td colspan='5' class='c' style='color:#888;padding:20px;'>কোনো পদবী নেই। New চাপুন।</td></tr>"; ?>
  </tbody>
</table>
</div>

<script>
var sel = null;
function pick(tr) {
    document.querySelectorAll('.drow').forEach(function (r) { r.classList.remove('selected-row'); });
    tr.classList.add('selected-row'); tr.querySelector('input[type=radio]').checked = true; sel = tr;
}
function openForm(tr) {
    document.getElementById('f_title').textContent = tr ? 'EDIT DESIGNATION' : 'NEW DESIGNATION';
    document.getElementById('f_id').value = tr ? tr.dataset.id : 0;
    document.getElementById('f_name').value = tr ? tr.dataset.name : '';
    document.getElementById('f_status').value = tr ? tr.dataset.status : 'Active';
    document.getElementById('formModal').style.display = 'flex';
    document.getElementById('f_name').focus();
}
function closeForm() { document.getElementById('formModal').style.display = 'none'; }
function editSelected() { if (!sel) { alert('একটি পদবী সিলেক্ট করুন!'); return; } openForm(sel); }
function deleteSelected() {
    if (!sel) { alert('একটি পদবী সিলেক্ট করুন!'); return; }
    if (parseInt(sel.dataset.count) > 0) { alert('এই পদবীতে কর্মচারী আছে, ডিলিট করা যাবে না। Inactive করে দিন।'); return; }
    if (confirm('আপনি কি নিশ্চিত এটি ডিলিট করতে চান?')) { document.getElementById('delId').value = sel.dataset.id; document.getElementById('delForm').submit(); }
}
function flt(inp) {
    var v = inp.value.toUpperCase();
    document.querySelectorAll('.drow').forEach(function (r) { r.style.display = r.dataset.name.toUpperCase().indexOf(v) > -1 ? '' : 'none'; });
}
</script>
</body>
</html>
