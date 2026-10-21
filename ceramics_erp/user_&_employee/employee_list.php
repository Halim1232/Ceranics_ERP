<?php
include('../auth.php'); require_page_access(); include('../config.php');
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function back($m) { echo "<script>alert(" . json_encode($m) . "); window.location.href='employee_list.php';</script>"; exit; }

// ---------- DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_users WHERE employee_id=$id"));
    if ((int)$c['c'] > 0) back('এই কর্মচারীর সাথে সিস্টেম ইউজার যুক্ত আছে, আগে ইউজার ডিলিট করুন।');
    $st = $conn->prepare("DELETE FROM erp_employees WHERE id=?");
    $st->bind_param("i", $id); $st->execute();
    back('কর্মচারীর তথ্য ডিলিট হয়েছে।');
}

// ---------- SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee'])) {
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['employee_name'] ?? '');
    $father  = trim($_POST['father_name'] ?? '');
    $desig   = (int)($_POST['designation_id'] ?? 0);
    $desig   = $desig > 0 ? $desig : null;
    $mobile  = trim($_POST['mobile_no'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $nid     = trim($_POST['nid_no'] ?? '');
    $addr    = trim($_POST['address'] ?? '');
    $joining = trim($_POST['joining_date'] ?? '');
    $joining = $joining !== '' ? $joining : null;
    $salary  = (float)($_POST['basic_salary'] ?? 0);
    $status  = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';
    $remarks = trim($_POST['remarks'] ?? '');
    if ($name === '' || $mobile === '') back('নাম ও মোবাইল নম্বর আবশ্যক!');

    $st = $conn->prepare("SELECT id FROM erp_employees WHERE mobile_no=? AND id<>?");
    $st->bind_param("si", $mobile, $id); $st->execute();
    if ($st->get_result()->num_rows > 0) back('এই মোবাইল নম্বরে আগে থেকেই একজন কর্মচারী আছে!');

    if ($id > 0) {
        $st = $conn->prepare("UPDATE erp_employees SET employee_name=?, father_name=?, designation_id=?, mobile_no=?, email=?, nid_no=?, address=?, joining_date=?, basic_salary=?, status=?, remarks=? WHERE id=?");
        $st->bind_param("ssisssssdssi", $name, $father, $desig, $mobile, $email, $nid, $addr, $joining, $salary, $status, $remarks, $id);
        $st->execute();
    } else {
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0)+1 AS n FROM erp_employees"));
        $code = "EMP-" . str_pad($m['n'], 4, "0", STR_PAD_LEFT);
        $st = $conn->prepare("INSERT INTO erp_employees (employee_code, employee_name, father_name, designation_id, mobile_no, email, nid_no, address, joining_date, basic_salary, status, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param("sssisssssdss", $code, $name, $father, $desig, $mobile, $email, $nid, $addr, $joining, $salary, $status, $remarks);
        $st->execute();
    }
    back('কর্মচারীর তথ্য সংরক্ষিত হয়েছে।');
}

$desigs = [];
$dr = mysqli_query($conn, "SELECT id, designation_name FROM erp_designations WHERE status='Active' ORDER BY designation_name");
while ($dr && $d = mysqli_fetch_assoc($dr)) $desigs[] = $d;

$rows = mysqli_query($conn, "SELECT e.*, d.designation_name FROM erp_employees e LEFT JOIN erp_designations d ON d.id = e.designation_id ORDER BY e.id DESC");
$next = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0)+1 AS n FROM erp_employees"));
$next_code = "EMP-" . str_pad($next['n'], 4, "0", STR_PAD_LEFT);
$tot_salary = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Master Info</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 15px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f1f1f1; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); justify-content: center; align-items: center; z-index: 9999; overflow-y: auto; }
    .modal-content { background: #fff; border: 1px solid #cddc39; border-radius: 6px; padding: 20px; width: 100%; max-width: 720px; box-shadow: 0 4px 15px rgba(0,0,0,.2); }
    .panel-header { background: #ececef; padding: 10px 15px; font-size: 13px; font-weight: bold; color: #405057; border-bottom: 1px solid #cddc39; margin: -20px -20px 15px; border-radius: 6px 6px 0 0; }
    .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 15px; }
    .span2 { grid-column: span 2; } .span3 { grid-column: span 3; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: 12px; font-weight: bold; color: #444; }
    .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 6px 8px; border: 1px solid #dbebe7; background: #fff; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .selected-row td { background: #bbdeeb !important; }
    .r { text-align: right; }
    .st-Active { color: #28a745; font-weight: bold; } .st-Inactive { color: #dc3545; font-weight: bold; }
    .total td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
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
    <form method="POST" action="employee_list.php">
      <div class="panel-header">EMPLOYEE MASTER INFO</div>
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="form-grid">
        <div class="form-group"><label>Employee Code (Auto)</label><input type="text" id="f_code" class="form-control" readonly></div>
        <div class="form-group span2"><label>Employee Name *</label><input type="text" name="employee_name" id="f_name" class="form-control" required></div>

        <div class="form-group span2"><label>Father's Name</label><input type="text" name="father_name" id="f_father" class="form-control"></div>
        <div class="form-group"><label>Designation</label>
          <select name="designation_id" id="f_desig" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($desigs as $d) echo '<option value="' . (int)$d['id'] . '">' . h($d['designation_name']) . '</option>'; ?>
          </select></div>

        <div class="form-group"><label>Mobile No *</label><input type="text" name="mobile_no" id="f_mobile" class="form-control" required placeholder="017XXXXXXXX"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="f_email" class="form-control"></div>
        <div class="form-group"><label>NID No</label><input type="text" name="nid_no" id="f_nid" class="form-control"></div>

        <div class="form-group span3"><label>Address</label><input type="text" name="address" id="f_addr" class="form-control"></div>

        <div class="form-group"><label>Joining Date</label><input type="date" name="joining_date" id="f_join" class="form-control"></div>
        <div class="form-group"><label>Basic Salary</label><input type="number" step="0.01" name="basic_salary" id="f_salary" class="form-control" value="0"></div>
        <div class="form-group"><label>Status</label>
          <select name="status" id="f_status" class="form-control"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>

        <div class="form-group span3"><label>Remarks</label><input type="text" name="remarks" id="f_remarks" class="form-control"></div>
      </div>
      <div style="margin-top:15px; text-align:right; display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" class="btn-action" onclick="closeForm()"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        <button type="submit" name="save_employee" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color:#28a745;"></i> Save Employee</button>
      </div>
    </form>
  </div>
</div>

<div style="background:#fff; padding:15px; border:1px solid #cddc39; border-radius:4px;">
<table class="grid-table">
  <thead>
    <tr><th style="width:38px;"></th><th style="width:40px;">SL</th><th>Code</th><th>Employee Name</th><th>Designation</th><th>Mobile</th><th>Joining</th><th class="r">Basic Salary</th><th>Status</th></tr>
    <tr style="background:#f5f5f5;"><td></td><td></td>
      <td><input type="text" id="fc" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Code..."></td>
      <td><input type="text" id="fn" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Name..."></td>
      <td><input type="text" id="fd" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Designation..."></td>
      <td><input type="text" id="fm" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Mobile..."></td>
      <td></td><td></td>
      <td><select id="fs" style="width:100%;height:24px;" onchange="filt()"><option value="">All</option><option>Active</option><option>Inactive</option></select></td></tr>
  </thead>
  <tbody>
  <?php $sl = 1; while ($rows && $r = mysqli_fetch_assoc($rows)) { $tot_salary += (float)$r['basic_salary']; ?>
    <tr class="erow" style="cursor:pointer;" onclick="pick(this)"
        data-emp="<?php echo h(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>">
      <td style="text-align:center;"><input type="radio" name="sel"></td>
      <td><?php echo $sl++; ?></td>
      <td><?php echo h($r['employee_code']); ?></td>
      <td style="font-weight:bold; color:#1a427a;"><?php echo h($r['employee_name']); ?></td>
      <td><?php echo h($r['designation_name'] ?? '-'); ?></td>
      <td><?php echo h($r['mobile_no']); ?></td>
      <td><?php echo $r['joining_date'] ? date('d-M-Y', strtotime($r['joining_date'])) : '-'; ?></td>
      <td class="r"><?php echo number_format($r['basic_salary'], 2); ?></td>
      <td class="st-<?php echo h($r['status']); ?>"><?php echo h($r['status']); ?></td>
    </tr>
  <?php }
  if ($sl === 1) echo "<tr><td colspan='9' style='text-align:center;color:#888;padding:20px;'>কোনো কর্মচারী নেই। New চাপুন।</td></tr>";
  else echo "<tr class='total'><td colspan='7' style='text-align:center;color:#2e7d32;'>Total Basic Salary</td><td class='r'>" . number_format($tot_salary, 2) . "</td><td></td></tr>"; ?>
  </tbody>
</table>
</div>

<script>
var sel = null, NEXT_CODE = <?php echo json_encode($next_code); ?>;
function pick(tr) {
    document.querySelectorAll('.erow').forEach(function(r){ r.classList.remove('selected-row'); });
    tr.classList.add('selected-row'); tr.querySelector('input[type=radio]').checked = true; sel = tr;
}
function emp(tr) { return JSON.parse(tr.dataset.emp); }
function setv(id, v) { document.getElementById(id).value = (v === null || v === undefined) ? '' : v; }
function openForm(tr) {
    var e = tr ? emp(tr) : {};
    setv('f_id', tr ? e.id : 0);           setv('f_code', tr ? e.employee_code : NEXT_CODE);
    setv('f_name', e.employee_name);       setv('f_father', e.father_name);
    setv('f_desig', e.designation_id);     setv('f_mobile', e.mobile_no);
    setv('f_email', e.email);              setv('f_nid', e.nid_no);
    setv('f_addr', e.address);             setv('f_join', e.joining_date);
    setv('f_salary', tr ? e.basic_salary : 0);
    setv('f_status', tr ? e.status : 'Active'); setv('f_remarks', e.remarks);
    document.getElementById('formModal').style.display = 'flex';
    document.getElementById('f_name').focus();
}
function closeForm() { document.getElementById('formModal').style.display = 'none'; }
function editSelected() { if (!sel) { alert('একজন কর্মচারী সিলেক্ট করুন!'); return; } openForm(sel); }
function deleteSelected() {
    if (!sel) { alert('একজন কর্মচারী সিলেক্ট করুন!'); return; }
    if (confirm('আপনি কি নিশ্চিত এটি ডিলিট করতে চান?')) postDelete('employee_list.php', {id: emp(sel).id, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
}
function filt() {
    var c = document.getElementById('fc').value.toUpperCase(), n = document.getElementById('fn').value.toUpperCase(),
        d = document.getElementById('fd').value.toUpperCase(), m = document.getElementById('fm').value, s = document.getElementById('fs').value;
    document.querySelectorAll('.erow').forEach(function(r) {
        var e = emp(r);
        var ok = (e.employee_code || '').toUpperCase().indexOf(c) > -1 && (e.employee_name || '').toUpperCase().indexOf(n) > -1
              && (e.designation_name || '').toUpperCase().indexOf(d) > -1 && (e.mobile_no || '').indexOf(m) > -1 && (!s || e.status === s);
        r.style.display = ok ? '' : 'none';
    });
}
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>