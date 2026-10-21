<?php
include('../auth.php'); require_admin(); include('../config.php');   // Company Admin বা Super Admin
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function back($m) { echo "<script>alert(" . json_encode($m) . "); window.location.href='system_user.php';</script>"; exit; }

$is_super = (current_role() === 'superadmin');
$me = (int)$_SESSION['user_id'];
$ADMIN_ROLES = ['admin', 'superadmin'];   // এদের ব্রাঞ্চ বরাদ্দ লাগে না, সব ব্রাঞ্চ দেখে

// $role এর অন্য কোনো অ্যাকটিভ ইউজার আছে কিনা (শেষ জনকে মুছতে/নামাতে দেওয়া হবে না)
function other_active_with_role($conn, $role, $exclude_id) {
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM erp_users WHERE role=? AND status='Active' AND id<>?");
    $st->bind_param("si", $role, $exclude_id); $st->execute();
    return (int)$st->get_result()->fetch_assoc()['c'];
}
function get_user($conn, $id) {
    $st = $conn->prepare("SELECT id, role, status FROM erp_users WHERE id=?");
    $st->bind_param("i", $id); $st->execute();
    return $st->get_result()->fetch_assoc();
}

// ---------- DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === $me) back('নিজের অ্যাকাউন্ট ডিলিট করা যাবে না।');
    $u = get_user($conn, $id);
    if (!$u) back('ইউজার পাওয়া যায়নি।');
    if ($u['role'] === 'superadmin' && !$is_super) back('অনুমতি নেই।');
    if (in_array($u['role'], $ADMIN_ROLES, true) && $u['status'] === 'Active' && other_active_with_role($conn, $u['role'], $id) === 0)
        back('এই রোলের শেষ অ্যাকটিভ ইউজার ডিলিট করা যাবে না।');
    $st = $conn->prepare("DELETE FROM erp_users WHERE id=?");
    $st->bind_param("i", $id); $st->execute();
    back('ইউজার ডিলিট হয়েছে।');
}

// ---------- SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    require_csrf();
    $id       = (int)($_POST['id'] ?? 0);
    $username = strtolower(trim($_POST['username'] ?? ''));
    $full     = trim($_POST['full_name'] ?? '');
    $emp      = (int)($_POST['employee_id'] ?? 0);
    $emp      = $emp > 0 ? $emp : null;
    $role     = trim($_POST['role'] ?? '');
    $status   = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';
    $pass     = (string)($_POST['password'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) back('ইউজারনেম ৩-৫০ অক্ষরের হতে হবে (a-z, 0-9, . _ -)।');
    if ($emp && $full === '') {
        $e = $conn->prepare("SELECT employee_name FROM erp_employees WHERE id=?"); $e->bind_param("i", $emp); $e->execute();
        $er = $e->get_result()->fetch_assoc(); $full = $er['employee_name'] ?? '';
    }
    if ($full === '') back('পূর্ণ নাম দিন!');
    if ($id === 0 && strlen($pass) < 6) back('পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের দিন!');
    if ($id > 0 && $pass !== '' && strlen($pass) < 6) back('পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের দিন!');

    $st = $conn->prepare("SELECT role_name FROM erp_roles WHERE role_name=? AND status='Active'");
    $st->bind_param("s", $role); $st->execute();
    $rr = $st->get_result()->fetch_assoc();
    if (!$rr) back('সঠিক রোল সিলেক্ট করুন!');
    $role = $rr['role_name'];
    if ($role === 'superadmin' && !$is_super) back('এই রোল দেওয়া যাবে না।');

    // ব্রাঞ্চ: admin/superadmin = NULL (সব দেখে); বাকিদের বাধ্যতামূলক। 0 = সব ব্রাঞ্চ, >0 = নির্দিষ্ট ব্রাঞ্চ
    $br = null;
    if (!in_array($role, $ADMIN_ROLES, true)) {
        $raw = $_POST['branch_id'] ?? '';
        if ($raw === '') back('ব্রাঞ্চ নির্বাচন করুন!');
        $br = (int)$raw;
        if ($br > 0) {
            $bc = $conn->prepare("SELECT id FROM erp_branches WHERE id=? AND status='Active'");
            $bc->bind_param("i", $br); $bc->execute();
            if (!$bc->get_result()->num_rows) back('সঠিক ব্রাঞ্চ নির্বাচন করুন!');
        }
    }

    $st = $conn->prepare("SELECT id FROM erp_users WHERE username=? AND id<>?");
    $st->bind_param("si", $username, $id); $st->execute();
    if ($st->get_result()->num_rows > 0) back('এই ইউজারনেম আগে থেকেই আছে!');

    if ($emp) {
        $st = $conn->prepare("SELECT id FROM erp_users WHERE employee_id=? AND id<>?");
        $st->bind_param("ii", $emp, $id); $st->execute();
        if ($st->get_result()->num_rows > 0) back('এই কর্মচারীর জন্য আগে থেকেই ইউজার আছে!');
    }

    if ($id > 0) {
        $old = get_user($conn, $id);
        if (!$old) back('ইউজার পাওয়া যায়নি।');
        if ($old['role'] === 'superadmin' && !$is_super) back('অনুমতি নেই।');
        if ($id === $me && ($role !== $old['role'] || $status !== 'Active')) back('নিজের রোল বা স্ট্যাটাস বদলানো যাবে না।');
        if (in_array($old['role'], $ADMIN_ROLES, true) && $old['status'] === 'Active' && ($role !== $old['role'] || $status !== 'Active')
            && other_active_with_role($conn, $old['role'], $id) === 0)
            back('এই রোলের শেষ অ্যাকটিভ ইউজারের রোল/স্ট্যাটাস বদলানো যাবে না।');

        if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $st = $conn->prepare("UPDATE erp_users SET username=?, full_name=?, role=?, status=?, employee_id=?, branch_id=?, password=? WHERE id=?");
            $st->bind_param("ssssiisi", $username, $full, $role, $status, $emp, $br, $hash, $id);
        } else {
            $st = $conn->prepare("UPDATE erp_users SET username=?, full_name=?, role=?, status=?, employee_id=?, branch_id=? WHERE id=?");
            $st->bind_param("ssssiii", $username, $full, $role, $status, $emp, $br, $id);
        }
        $st->execute();
        back('ইউজারের তথ্য আপডেট হয়েছে।');
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = $conn->prepare("INSERT INTO erp_users (username, password, full_name, role, status, employee_id, branch_id) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param("sssssii", $username, $hash, $full, $role, $status, $emp, $br);
        $st->execute();
        back('নতুন ইউজার তৈরি হয়েছে।');
    }
}

// ---------- ডাটা ----------
$roles = [];
$rq = mysqli_query($conn, "SELECT role_name FROM erp_roles WHERE status='Active'" . ($is_super ? '' : " AND role_name <> 'superadmin'") .
                          " ORDER BY (role_name='superadmin') DESC, (role_name='admin') DESC, role_name");
while ($rq && $x = mysqli_fetch_assoc($rq)) $roles[] = $x['role_name'];

$brs = [];
$bq = mysqli_query($conn, "SELECT id, branch_name FROM erp_branches WHERE status='Active' ORDER BY branch_name");
while ($bq && $x = mysqli_fetch_assoc($bq)) $brs[] = $x;

$emps = [];
$eq = mysqli_query($conn, "SELECT e.id, e.employee_code, e.employee_name, u.id AS used_by FROM erp_employees e LEFT JOIN erp_users u ON u.employee_id = e.id WHERE e.status='Active' ORDER BY e.employee_name");
while ($eq && $x = mysqli_fetch_assoc($eq)) $emps[] = $x;

$rows = mysqli_query($conn, "SELECT u.id, u.username, u.full_name, u.role, u.status, u.employee_id, u.branch_id,
                                    e.employee_code, e.employee_name, br.branch_name
                             FROM erp_users u
                             LEFT JOIN erp_employees e ON e.id = u.employee_id
                             LEFT JOIN erp_branches br ON br.id = u.branch_id
                             " . ($is_super ? '' : "WHERE u.role <> 'superadmin' ") . "ORDER BY u.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>System User Registry</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 15px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f1f1f1; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); justify-content: center; align-items: center; z-index: 9999; overflow-y: auto; }
    .modal-content { background: #fff; border: 1px solid #cddc39; border-radius: 6px; padding: 20px; width: 100%; max-width: 560px; box-shadow: 0 4px 15px rgba(0,0,0,.2); }
    .panel-header { background: #ececef; padding: 10px 15px; font-size: 13px; font-weight: bold; color: #405057; border-bottom: 1px solid #cddc39; margin: -20px -20px 15px; border-radius: 6px 6px 0 0; }
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px 15px; }
    .span2 { grid-column: span 2; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: 12px; font-weight: bold; color: #444; }
    .form-group small { font-size: 11px; color: #888; }
    .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .form-control:disabled { background: #f1f1f1; color: #999; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 6px 8px; border: 1px solid #dbebe7; background: #fff; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .selected-row td { background: #bbdeeb !important; }
    .st-Active { color: #28a745; font-weight: bold; } .st-Inactive { color: #dc3545; font-weight: bold; }
    .badge { background: #e3f2fd; color: #0d47a1; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: bold; }
    .badge.admin { background: #fff3e0; color: #e65100; }
    .badge.super { background: #f3e5f5; color: #6a1b9a; }
    .nobr { color: #d32f2f; font-weight: bold; }
</style>
</head>
<body>

<div class="control-bar">
    <button class="btn-action" onclick="openForm()"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New User</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color:#3498db;"></i> Edit / Reset Password</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color:#e74c3c;"></i> Delete</button>
</div>

<div class="modal" id="formModal">
  <div class="modal-content">
    <form method="POST" action="system_user.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
      <div class="panel-header" id="f_title">SYSTEM USER</div>
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="form-grid">
        <div class="form-group span2"><label>Employee (ঐচ্ছিক)</label>
          <select name="employee_id" id="f_emp" class="form-control" onchange="empPicked()">
            <option value="">-- কর্মচারী নির্বাচন করুন --</option>
            <?php foreach ($emps as $e) echo '<option value="' . (int)$e['id'] . '" data-name="' . h($e['employee_name']) . '" data-used="' . (int)$e['used_by'] . '">' . h($e['employee_code'] . ' - ' . $e['employee_name']) . '</option>'; ?>
          </select></div>
        <div class="form-group"><label>Username *</label><input type="text" name="username" id="f_user" class="form-control" required autocomplete="off" autocapitalize="off" placeholder="e.g. rahim"><small>ছোট হাতের a-z, 0-9, . _ -</small></div>
        <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" id="f_full" class="form-control" required></div>
        <div class="form-group"><label id="f_pass_lbl">Password *</label><input type="password" name="password" id="f_pass" class="form-control" autocomplete="new-password"><small id="f_pass_hint">কমপক্ষে ৬ অক্ষর</small></div>
        <div class="form-group"><label>Role *</label>
          <select name="role" id="f_role" class="form-control" required onchange="roleChanged()">
            <?php foreach ($roles as $r) echo '<option value="' . h($r) . '">' . h($r) . '</option>'; ?>
          </select></div>
        <div class="form-group"><label>Branch *</label>
          <select name="branch_id" id="f_branch" class="form-control">
            <option value="">-- নির্বাচন করুন --</option>
            <option value="0">সব ব্রাঞ্চ</option>
            <?php foreach ($brs as $b) echo '<option value="' . (int)$b['id'] . '">' . h($b['branch_name']) . '</option>'; ?>
          </select><small id="f_branch_hint">শুধু এই ব্রাঞ্চের ডেটা দেখতে পাবে</small></div>
        <div class="form-group"><label>Status</label>
          <select name="status" id="f_status" class="form-control"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
      </div>
      <div style="margin-top:15px; text-align:right; display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" class="btn-action" onclick="closeForm()"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
        <button type="submit" name="save_user" class="btn-action"><i class="fa-solid fa-floppy-disk" style="color:#28a745;"></i> Save User</button>
      </div>
    </form>
  </div>
</div>

<div style="background:#fff; padding:15px; border:1px solid #cddc39; border-radius:4px;">
<table class="grid-table">
  <thead>
    <tr><th style="width:38px;"></th><th style="width:40px;">SL</th><th>Username</th><th>Full Name</th><th>Employee</th><th>Role</th><th>Branch</th><th>Status</th></tr>
    <tr style="background:#f5f5f5;"><td></td><td></td>
      <td><input type="text" id="fu" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Username..."></td>
      <td><input type="text" id="fn" style="width:100%;box-sizing:border-box;height:24px;" onkeyup="filt()" placeholder="Name..."></td>
      <td></td>
      <td><select id="fr" style="width:100%;height:24px;" onchange="filt()"><option value="">All</option><?php foreach ($roles as $r) echo '<option>' . h($r) . '</option>'; ?></select></td>
      <td></td>
      <td><select id="fs" style="width:100%;height:24px;" onchange="filt()"><option value="">All</option><option>Active</option><option>Inactive</option></select></td></tr>
  </thead>
  <tbody>
  <?php $sl = 1; while ($rows && $r = mysqli_fetch_assoc($rows)) {
      $isAdm = in_array($r['role'], $ADMIN_ROLES, true);
      if ($isAdm)                          $brTxt = '<span style="color:#6a1b9a;font-weight:bold;">সব ব্রাঞ্চ</span>';
      elseif ($r['branch_id'] === null)    $brTxt = '<span class="nobr">বরাদ্দ নেই</span>';
      elseif ((int)$r['branch_id'] === 0)  $brTxt = 'সব ব্রাঞ্চ';
      else                                 $brTxt = h($r['branch_name'] ?: '-');
  ?>
    <tr class="urow" style="cursor:pointer;" onclick="pick(this)" data-user="<?php echo h(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>">
      <td style="text-align:center;"><input type="radio" name="sel"></td>
      <td><?php echo $sl++; ?></td>
      <td style="font-weight:bold; color:#1a427a;"><?php echo h($r['username']); ?><?php if ((int)$r['id'] === $me) echo ' <small style="color:#888;">(আপনি)</small>'; ?></td>
      <td><?php echo h($r['full_name']); ?></td>
      <td><?php echo $r['employee_code'] ? h($r['employee_code'] . ' - ' . $r['employee_name']) : '<span style="color:#aaa;">-</span>'; ?></td>
      <td><span class="badge <?php echo $r['role'] === 'superadmin' ? 'super' : ($r['role'] === 'admin' ? 'admin' : ''); ?>"><?php echo h($r['role']); ?></span></td>
      <td><?php echo $brTxt; ?></td>
      <td class="st-<?php echo h($r['status']); ?>"><?php echo h($r['status']); ?></td>
    </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<script>
var sel = null, ME = <?php echo $me; ?>, ADMIN_ROLES = ['admin', 'superadmin'];
function pick(tr) {
    document.querySelectorAll('.urow').forEach(function(r){ r.classList.remove('selected-row'); });
    tr.classList.add('selected-row'); tr.querySelector('input[type=radio]').checked = true; sel = tr;
}
function usr(tr) { return JSON.parse(tr.dataset.user); }
function empPicked() {
    var o = document.getElementById('f_emp').selectedOptions[0];
    if (o && o.value && !document.getElementById('f_full').value) document.getElementById('f_full').value = o.dataset.name;
}
// admin / superadmin হলে ব্রাঞ্চ ঘর বন্ধ (তারা সব ব্রাঞ্চ দেখে)
function roleChanged() {
    var isAdm = ADMIN_ROLES.indexOf(document.getElementById('f_role').value) > -1;
    var b = document.getElementById('f_branch');
    b.disabled = isAdm; b.required = !isAdm;
    if (isAdm) b.value = '';
    document.getElementById('f_branch_hint').textContent = isAdm ? 'এই রোল সব ব্রাঞ্চ দেখে, বরাদ্দ লাগবে না' : 'শুধু এই ব্রাঞ্চের ডেটা দেখতে পাবে';
}
function openForm(tr) {
    var u = tr ? usr(tr) : {};
    var editId = tr ? parseInt(u.id) : 0;
    document.getElementById('f_title').textContent = tr ? 'EDIT USER' : 'NEW USER';
    document.getElementById('f_id').value = editId;
    document.getElementById('f_user').value = u.username || '';
    document.getElementById('f_full').value = u.full_name || '';
    var rs = document.getElementById('f_role');
    rs.value = u.role || (rs.options.length ? rs.options[rs.options.length - 1].value : '');
    document.getElementById('f_status').value = u.status || 'Active';
    document.getElementById('f_pass').value = '';
    document.getElementById('f_pass').required = !tr;
    document.getElementById('f_pass_lbl').textContent = tr ? 'New Password' : 'Password *';
    document.getElementById('f_pass_hint').textContent = tr ? 'ফাঁকা রাখলে পাসওয়ার্ড বদলাবে না' : 'কমপক্ষে ৬ অক্ষর';
    document.getElementById('f_branch').value = (u.branch_id === null || u.branch_id === undefined) ? '' : String(u.branch_id);
    roleChanged();
    var es = document.getElementById('f_emp');
    Array.prototype.forEach.call(es.options, function(o) {
        var used = parseInt(o.dataset.used || 0);
        o.disabled = used > 0 && used !== editId;
        if (o.value && used > 0 && used !== editId && o.text.indexOf('(linked)') < 0) o.text += ' (linked)';
    });
    es.value = u.employee_id || '';
    document.getElementById('formModal').style.display = 'flex';
    document.getElementById('f_user').focus();
}
function closeForm() { document.getElementById('formModal').style.display = 'none'; }
function editSelected() { if (!sel) { alert('একজন ইউজার সিলেক্ট করুন!'); return; } openForm(sel); }
function deleteSelected() {
    if (!sel) { alert('একজন ইউজার সিলেক্ট করুন!'); return; }
    var u = usr(sel);
    if (parseInt(u.id) === ME) { alert('নিজের অ্যাকাউন্ট ডিলিট করা যাবে না।'); return; }
    if (confirm('ইউজার "' + u.username + '" ডিলিট করতে চান?')) postDelete('system_user.php', {id: u.id, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
}
function filt() {
    var a = document.getElementById('fu').value.toUpperCase(), n = document.getElementById('fn').value.toUpperCase(),
        r = document.getElementById('fr').value, s = document.getElementById('fs').value;
    document.querySelectorAll('.urow').forEach(function(row) {
        var u = usr(row);
        var ok = u.username.toUpperCase().indexOf(a) > -1 && (u.full_name || '').toUpperCase().indexOf(n) > -1 && (!r || u.role === r) && (!s || u.status === s);
        row.style.display = ok ? '' : 'none';
    });
}
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>