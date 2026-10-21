<?php
// user_&_employee/role_permission.php -- Role Permissions Matrix
// Hierarchy: Super Admin > Company Admin ('admin') > onno role (Manager etc.)
//  * Super Admin  : 'admin' role er sima (erp_admin_menu_grants) thik kore; onno role-o set korte pare. Shob menu dekhe.
//  * Company Admin: shudhu nijer sima er bhitorer menu diye onno role banay/permission dey. 'superadmin'/'admin' role dekhe/bodlay na.
include('../auth.php'); require_admin(); require_page_access(); include('../config.php');   // admin (Company Admin) বা superadmin
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function back($m, $role = '') {
    $u = 'role_permission.php' . ($role !== '' ? '?role=' . rawurlencode($role) : '');
    echo "<script>alert(" . json_encode($m) . "); window.location.href=" . json_encode($u) . ";</script>"; exit;
}

$is_super = (current_role() === 'superadmin');
$ceil     = pa_ceiling_ids();   // null = সীমা সেট করা নেই, নইলে [menu_id => true]
$RESERVED = ['admin', 'superadmin'];

// ---------- নতুন রোল ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {
    require_csrf();
    $name = trim($_POST['role_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_ -]{2,20}$/', $name)) back('রোলের নাম ২-২০ অক্ষরের হতে হবে (ইংরেজি অক্ষর/সংখ্যা)।');
    $norm = preg_replace('/[\s_\-]+/', '', strtolower($name));   // "Super Admin", "company-admin", "SU_ADMIN" সবই ধরা পড়বে
    if (in_array($norm, ['admin', 'superadmin', 'companyadmin', 'suadmin', 'sadmin', 'owner'], true)) back('এই নামটি সিস্টেমের জন্য সংরক্ষিত।');
    $st = $conn->prepare("SELECT id FROM erp_roles WHERE LOWER(role_name)=LOWER(?)");
    $st->bind_param("s", $name); $st->execute();
    if ($st->get_result()->num_rows > 0) back('এই নামে রোল আগে থেকেই আছে!');
    $st = $conn->prepare("INSERT INTO erp_roles (role_name, description) VALUES (?,?)");
    $st->bind_param("ss", $name, $desc); $st->execute();
    back('রোল তৈরি হয়েছে। এখন মেনু পারমিশন টিক দিয়ে Save করুন।', $name);
}

// ---------- রোল ডিলিট ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $name = $_POST['role'] ?? '';
    $st = $conn->prepare("SELECT is_system FROM erp_roles WHERE role_name=?");
    $st->bind_param("s", $name); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$r) back('রোল পাওয়া যায়নি।');
    if ($r['is_system'] || in_array($name, $RESERVED, true)) back('সিস্টেম রোল ডিলিট করা যাবে না।');
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM erp_users WHERE role=?");
    $st->bind_param("s", $name); $st->execute();
    if ((int)$st->get_result()->fetch_assoc()['c'] > 0) back('এই রোলে ইউজার আছে। আগে ইউজারদের রোল বদলান।');
    $st = $conn->prepare("DELETE FROM erp_role_permissions WHERE role_name=?"); $st->bind_param("s", $name); $st->execute();
    $st = $conn->prepare("DELETE FROM erp_roles WHERE role_name=?");           $st->bind_param("s", $name); $st->execute();
    back('রোল ডিলিট হয়েছে।');
}

// ---------- পারমিশন সেভ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_perm'])) {
    require_csrf();
    $name = $_POST['role_name'] ?? '';
    $st = $conn->prepare("SELECT role_name FROM erp_roles WHERE role_name=?");
    $st->bind_param("s", $name); $st->execute();
    if (!$st->get_result()->num_rows) back('রোল পাওয়া যায়নি।');
    if ($name === 'superadmin') back('সুপার অ্যাডমিনের সব অ্যাক্সেস আছে, পরিবর্তন করা যাবে না।', $name);
    if ($name === 'admin' && !$is_super) back('Company Admin এর অ্যাক্সেস শুধু Super Admin ঠিক করতে পারে।', $name);

    $ids = array_unique(array_map('intval', $_POST['menu_ids'] ?? []));
    if ($name !== 'admin' && $ceil !== null) {   // সীমার বাইরের মেনু দেওয়া যাবে না
        $ids = array_values(array_filter($ids, function ($i) use ($ceil) { return isset($ceil[$i]); }));
    }
    if ($name === 'admin' && !$ids) back('Company Admin এর জন্য কমপক্ষে একটি মেনু দিন (ফাঁকা থাকলে সীমা উঠে যায়)।', $name);

  
    mysqli_begin_transaction($conn);
    try {
        if ($name === 'admin') {   // Super Admin সীমা ঠিক করছে
            $conn->query("DELETE FROM erp_admin_menu_grants");
            $ins = $conn->prepare("INSERT INTO erp_admin_menu_grants (menu_id) SELECT id FROM erp_menus WHERE id=?");
            foreach ($ids as $mid) { if ($mid > 0) { $ins->bind_param("i", $mid); $ins->execute(); } }
        } else {
            $d = $conn->prepare("DELETE FROM erp_role_permissions WHERE role_name=?");
            $d->bind_param("s", $name); $d->execute();
            $ins = $conn->prepare("INSERT INTO erp_role_permissions (role_name, menu_id) SELECT ?, id FROM erp_menus WHERE id=?");
            foreach ($ids as $mid) { if ($mid > 0) { $ins->bind_param("si", $name, $mid); $ins->execute(); } }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        back('সেভ ব্যর্থ হয়েছে।', $name);
    }
    back('পারমিশন সংরক্ষিত হয়েছে। ইউজার পরের বার পেজ রিফ্রেশ/লগইন করলে নতুন মেনু দেখবে।', $name);
}

// ---------- ডাটা ----------
$roles = [];
$rq = mysqli_query($conn, "SELECT role_name, is_system, (SELECT COUNT(*) FROM erp_users u WHERE u.role = erp_roles.role_name) AS ucount
                           FROM erp_roles " . ($is_super ? "" : "WHERE role_name <> 'superadmin' ") .
                          "ORDER BY (role_name='superadmin') DESC, (role_name='admin') DESC, role_name");
while ($rq && $x = mysqli_fetch_assoc($rq)) $roles[] = $x;

$cur = $_GET['role'] ?? '';
$names = array_column($roles, 'role_name');
if (!in_array($cur, $names, true)) {
    $cur = '';
    foreach ($roles as $x) { if (!in_array($x['role_name'], $RESERVED, true)) { $cur = $x['role_name']; break; } }
    if ($cur === '' && $roles) $cur = ($is_super && in_array('admin', $names, true)) ? 'admin' : $roles[0]['role_name'];
}
// বদলানো যাবে না: superadmin (সবার জন্য), admin (Company Admin নিজে দেখলে)
$is_admin_role  = ($cur === 'superadmin') || ($cur === 'admin' && !$is_super);
// Super Admin সব মেনু দেখে (superadmin রোল দেখলে এবং Company Admin এর সীমা ঠিক করার সময়)
$show_all_menus = ($cur === 'superadmin') || ($cur === 'admin' && $is_super);

$allowed = [];   // null = সব টিক
if ($cur === 'superadmin' || ($cur === 'admin' && $ceil === null)) {
    $allowed = null;
} elseif ($cur === 'admin') {
    $allowed = $ceil;
} elseif ($cur !== '') {
    $st = $conn->prepare("SELECT menu_id FROM erp_role_permissions WHERE role_name=?");
    $st->bind_param("s", $cur); $st->execute();
    $pr = $st->get_result();
    while ($x = $pr->fetch_assoc()) $allowed[(int)$x['menu_id']] = true;
}

// মেনু ট্রি (admin-only পেজ দুটো ম্যাট্রিক্সে দেখানো হবে না)
$parents = []; $kids = [];
$mq = mysqli_query($conn, "SELECT id, menu_name, parent_id, route_link, icon FROM erp_menus WHERE status='Active' ORDER BY order_no ASC");
while ($mq && $m = mysqli_fetch_assoc($mq)) {
    $rl = trim($m['route_link'] ?? '');
    if (strpos($rl, 'system_user.php') !== false || strpos($rl, 'role_permission.php') !== false) {
    if ($cur !== 'admin' && $cur !== 'superadmin') continue;   // admin-only page: shudhu admin/superadmin er matrix e dekhabe
}
    $isLeafRoute = ($rl !== '' && $rl[0] !== '#');
    if (!$show_all_menus && $ceil !== null && $isLeafRoute && !isset($ceil[(int)$m['id']])) continue;   // সীমার বাইরের মেনু লুকানো
    if ((int)$m['parent_id'] === 0) $parents[] = $m; else $kids[(int)$m['parent_id']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Role Permissions Matrix</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 15px; margin: 0; color: #333; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; align-items: center; margin-bottom: 15px; border: 1px solid #cdcd4a; flex-wrap: wrap; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f1f1f1; }
    .form-control { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; box-sizing: border-box; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); justify-content: center; align-items: center; z-index: 9999; }
    .modal-content { background: #fff; border: 1px solid #cddc39; border-radius: 6px; padding: 20px; width: 100%; max-width: 380px; box-shadow: 0 4px 15px rgba(0,0,0,.2); }
    .panel-header { background: #ececef; padding: 10px 15px; font-size: 13px; font-weight: bold; color: #405057; border-bottom: 1px solid #cddc39; margin: -20px -20px 15px; border-radius: 6px 6px 0 0; }
    .form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
    .form-group label { font-size: 12px; font-weight: bold; color: #444; }
    .box { background: #fff; border: 1px solid #cddc39; border-radius: 4px; padding: 10px 15px; }
    .menu-group { border: 1px solid #dbebe7; border-radius: 4px; margin-bottom: 10px; }
    .menu-group .gh { background: #dbebe7; padding: 8px 12px; font-weight: bold; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .menu-group .gb { padding: 6px 12px 6px 36px; display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 4px 12px; }
    .menu-group label { font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; padding: 3px 0; }
    input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }
    .note { background: #fff8e1; border: 1px solid #ffe082; padding: 8px 12px; border-radius: 4px; font-size: 12.5px; margin-bottom: 12px; }
</style>
</head>
<body>

<div class="control-bar">
    <b style="font-size:13px;">Role:</b>
    <select class="form-control" onchange="window.location.href='role_permission.php?role='+encodeURIComponent(this.value)">
        <?php foreach ($roles as $x) echo '<option value="' . h($x['role_name']) . '"' . ($x['role_name'] === $cur ? ' selected' : '') . '>' . h(role_label($x['role_name'])) . ' (' . (int)$x['ucount'] . ' user)</option>'; ?>
    </select>
    <button class="btn-action" onclick="document.getElementById('roleModal').style.display='flex'"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New Role</button>
    <?php if ($cur !== '' && !in_array($cur, $RESERVED, true)): ?>
        <button class="btn-action" onclick="delRole()"><i class="fa-solid fa-trash" style="color:#e74c3c;"></i> Delete Role</button>
    <?php endif; ?>
    <?php if ($cur !== '' && !$is_admin_role): ?>
        <span style="flex:1"></span>
        <button class="btn-action" type="button" onclick="setAll(true)">Select All</button>
        <button class="btn-action" type="button" onclick="setAll(false)">Clear All</button>
        <button class="btn-action" type="button" onclick="document.getElementById('permForm').submit()" style="background:#28a745;color:#fff;border-color:#28a745;"><i class="fa-solid fa-floppy-disk"></i> Save Permissions</button>
    <?php endif; ?>
</div>

<div class="modal" id="roleModal">
  <div class="modal-content">
    <form method="POST" action="role_permission.php">
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
      <div class="panel-header">NEW ROLE</div>
      <div class="form-group"><label>Role Name * (ইংরেজি, সর্বোচ্চ ২০ অক্ষর)</label><input type="text" name="role_name" class="form-control" required maxlength="20" placeholder="e.g. sales, store, manager"></div>
      <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control" maxlength="150"></div>
      <div style="text-align:right; display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" class="btn-action" onclick="document.getElementById('roleModal').style.display='none'">Close</button>
        <button type="submit" name="create_role" class="btn-action" style="background:#28a745;color:#fff;border-color:#28a745;">Create</button>
      </div>
    </form>
  </div>
</div>

<div class="box">
<?php if ($cur === 'superadmin'): ?>
    <div class="note"><b>Super Admin</b> এর সব মেনুতে অ্যাক্সেস আছে, এটি বদলানো যায় না।</div>
<?php elseif ($cur === 'admin' && !$is_super): ?>
    <div class="note">আপনার (Company Admin) মেনু অ্যাক্সেস Super Admin ঠিক করে দিয়েছে। এর বাইরের মেনু আপনি বা আপনার ইউজাররা কেউ পাবে না। অন্য রোলের পারমিশন ঠিক করতে উপর থেকে রোল বাছুন।</div>
<?php elseif ($cur === 'admin'): ?>
    <div class="note">এখানে টিক দেওয়া মেনুগুলোই Company Admin ও তার বানানো সব রোলের <b>সর্বোচ্চ সীমা</b>। টিক ছাড়া মেনু (যেমন Company, Branch, Warehouse) শুধু আপনার কাছে থাকবে।<?php if ($ceil === null) echo ' <b>এখনো কোনো সীমা সেট করা নেই</b>, তাই Company Admin সব মেনু পাচ্ছে। Save করলে সীমা চালু হবে।'; ?></div>
<?php elseif ($cur === ''): ?>
    <div class="note">কোনো রোল নেই। New Role চাপুন।</div>
<?php else: ?>
    <div class="note"><b><?php echo h(role_label($cur)); ?></b> রোলের ইউজাররা শুধু নিচে টিক দেওয়া মেনুগুলো সাইডবারে দেখবে।<?php if ($ceil !== null && !$is_super) echo ' এখানে শুধু সেই মেনুই দেখানো হচ্ছে যেগুলো Super Admin-এর দেওয়া সীমার ভেতরে।'; elseif ($ceil !== null) echo ' এখানে শুধু Company Admin-এর সীমার ভেতরের মেনু দেওয়া কার্যকর হবে।'; ?> System User Registry ও Role Permissions শুধু admin-এর জন্য, তাই এখানে দেখানো হয়নি।</div>
<?php endif; ?>

<form method="POST" action="role_permission.php" id="permForm">
<input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
<input type="hidden" name="role_name" value="<?php echo h($cur); ?>">
<input type="hidden" name="save_perm" value="1">
<?php foreach ($parents as $p):
    $pid = (int)$p['id']; $hasKids = !empty($kids[$pid]);
    $prl = trim($p['route_link'] ?? '');
    if (!$hasKids && ($prl === '' || $prl[0] === '#')) continue; ?>
  <div class="menu-group">
    <div class="gh">
      <?php if ($hasKids): ?>
        <input type="checkbox" class="parent-chk" data-pid="<?php echo $pid; ?>" <?php echo $is_admin_role ? 'disabled' : ''; ?>>
      <?php else: ?>
        <input type="checkbox" class="leaf-chk" name="menu_ids[]" value="<?php echo $pid; ?>" <?php echo ($allowed === null || isset($allowed[$pid])) ? 'checked' : ''; ?> <?php echo $is_admin_role ? 'disabled' : ''; ?>>
      <?php endif; ?>
      <i class="fa <?php echo h($p['icon'] ?: 'fa-folder'); ?>"></i> <?php echo h($p['menu_name']); ?>
    </div>
    <?php if ($hasKids): ?>
    <div class="gb">
      <?php foreach ($kids[$pid] as $k): $kid = (int)$k['id']; ?>
        <label><input type="checkbox" class="leaf-chk child-<?php echo $pid; ?>" name="menu_ids[]" value="<?php echo $kid; ?>" <?php echo ($allowed === null || isset($allowed[$kid])) ? 'checked' : ''; ?> <?php echo $is_admin_role ? 'disabled' : ''; ?>> <?php echo h($k['menu_name']); ?></label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</form>
</div>

<script>
var CUR = <?php echo json_encode($cur); ?>;
function syncParents() {
    document.querySelectorAll('.parent-chk').forEach(function(p) {
        var c = document.querySelectorAll('.child-' + p.dataset.pid), n = 0;
        c.forEach(function(x) { if (x.checked) n++; });
        p.checked = c.length > 0 && n === c.length;
        p.indeterminate = n > 0 && n < c.length;
    });
}
document.querySelectorAll('.parent-chk').forEach(function(p) {
    p.addEventListener('change', function() {
        document.querySelectorAll('.child-' + p.dataset.pid).forEach(function(x) { x.checked = p.checked; });
        syncParents();
    });
});
document.querySelectorAll('.leaf-chk').forEach(function(x) { x.addEventListener('change', syncParents); });
function setAll(v) { document.querySelectorAll('.leaf-chk').forEach(function(x) { x.checked = v; }); syncParents(); }
function delRole() {
    if (confirm('রোল "' + CUR + '" ডিলিট করতে চান? এর পারমিশনও মুছে যাবে।')) postDelete('role_permission.php', {role: CUR, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
}
syncParents();
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>