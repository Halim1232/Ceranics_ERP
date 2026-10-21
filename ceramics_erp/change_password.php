<?php
include('auth.php');
include('config.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = "";
$ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $msg = "অবৈধ অনুরোধ! পেজ রিফ্রেশ করে আবার চেষ্টা করুন।";
    } else {
        $old     = $_POST['old_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM erp_users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($old, $row['password'])) {
            $msg = "বর্তমান পাসওয়ার্ড ভুল!";
        } elseif (strlen($new) < 8) {
            $msg = "নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।";
        } elseif ($new !== $confirm) {
            $msg = "নতুন পাসওয়ার্ড দুটি মিলছে না!";
        } elseif ($new === $old) {
            $msg = "নতুন পাসওয়ার্ড আগেরটির মতো হতে পারবে না।";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE erp_users SET password = ? WHERE id = ?");
            $u->bind_param("si", $hash, $_SESSION['user_id']);
            $u->execute();
            $u->close();
            $ok  = true;
            $msg = "পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে।";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 20px; margin: 0; }
        .box { background: #fff; max-width: 380px; padding: 25px; border-radius: 6px; border-top: 4px solid #28a745; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        h3 { margin: 0 0 15px; color: #333; }
        label { display: block; font-size: 13px; color: #555; margin: 12px 0 4px; }
        input { width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { margin-top: 18px; width: 100%; padding: 10px; background: #28a745; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .msg { padding: 10px; border-radius: 4px; margin-bottom: 10px; font-size: 13px; }
        .msg.ok  { background: #d4edda; color: #155724; }
        .msg.err { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="box">
    <h3>পাসওয়ার্ড পরিবর্তন</h3>
    <?php if ($msg) echo '<div class="msg ' . ($ok ? 'ok' : 'err') . '">' . htmlspecialchars($msg) . '</div>'; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <label>বর্তমান পাসওয়ার্ড</label>
        <input type="password" name="old_password" required>
        <label>নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)</label>
        <input type="password" name="new_password" required minlength="8">
        <label>নতুন পাসওয়ার্ড আবার লিখুন</label>
        <input type="password" name="confirm_password" required minlength="8">
        <button type="submit">পাসওয়ার্ড বদলান</button>
    </form>
</div>
</body>
</html>