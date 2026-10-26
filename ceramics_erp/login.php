<?php
include('config.php');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = ""; $error_role = false;
$max_attempts = 5;
$lock_seconds = 300;

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // CSRF চেক
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "অবৈধ অনুরোধ! পেজ রিফ্রেশ করে আবার চেষ্টা করুন।";
    }
    // ব্রুট-ফোর্স লক চেক
    elseif (($_SESSION['login_attempts'] ?? 0) >= $max_attempts
            && (time() - ($_SESSION['last_attempt'] ?? 0)) < $lock_seconds) {
        $wait = ceil(($lock_seconds - (time() - $_SESSION['last_attempt'])) / 60);
        $error = "অনেকবার ভুল হয়েছে। $wait মিনিট পর আবার চেষ্টা করুন।";
    }
    else {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = trim($_POST['password'] ?? '');

        $stmt = $conn->prepare("SELECT id, username, full_name, password, role, status FROM erp_users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $ok = false; $inactive = false;
        if ($row) {
            $stored = $row['password'];
            if (password_verify($password, $stored)) {
                $ok = true;
                // প্রয়োজন হলে হ্যাশ নতুন করা
                if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                    $new = password_hash($password, PASSWORD_DEFAULT);
                    $u = $conn->prepare("UPDATE erp_users SET password=? WHERE id=?");
                    $u->bind_param("si", $new, $row['id']);
                    $u->execute(); $u->close();
                }
            }
            // পুরনো প্লেইন টেক্সট পাসওয়ার্ড: মিললে অটো হ্যাশে রূপান্তর
            elseif (!preg_match('/^\$2[ayb]\$/', $stored) && hash_equals($stored, $password)) {
                $ok = true;
                $new = password_hash($password, PASSWORD_DEFAULT);
                $u = $conn->prepare("UPDATE erp_users SET password=? WHERE id=?");
                $u->bind_param("si", $new, $row['id']);
                $u->execute(); $u->close();
            }
        }

        // নিষ্ক্রিয় ইউজার লগইন করতে পারবে না
        if ($ok && ($row['status'] ?? 'Active') === 'Inactive') { $ok = false; $inactive = true; }
        // রোল ফাঁকা থাকলে লগইন নয় (কোনো অ্যাক্সেস থাকে না)
        if ($ok && trim((string)$row['role']) === '') { $ok = false; $error_role = true; }

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['user_id']       = $row['id'];
            $_SESSION['username']      = $row['username'];
            $_SESSION['full_name']     = $row['full_name'];
            $_SESSION['role']          = (string)$row['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['login_attempts'], $_SESSION['last_attempt']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt']   = time();
            // ইউজার আছে কিনা তা বোঝা যাবে না (নিরাপত্তার জন্য একই বার্তা)
            $error = $inactive ? "এই ইউজারটি নিষ্ক্রিয় করা হয়েছে। অ্যাডমিনের সাথে যোগাযোগ করুন।" : ($error_role ? "এই ইউজারের কোনো রোল নেই। অ্যাডমিনের সাথে যোগাযোগ করুন।" : "ইউজারনেম অথবা পাসওয়ার্ড ভুল!");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((function_exists('erp_company_name') && erp_company_name($conn) !== '' ? erp_company_name($conn) . ' | ' : '') . (defined('ERP_APP_NAME') ? ERP_APP_NAME : 'Sohoj ERP'), ENT_QUOTES); ?> - Login</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid #28a745; width: 320px; }
        h2 { margin: 0 0 20px; color: #333; text-align: center; font-size: 22px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #666; font-size: 14px; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        button { width: 100%; padding: 12px; background: #28a745; color: white; border: none; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; }
        button:hover { background: #218838; }
        .error-msg { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2><?php $__cn = function_exists('erp_company_name') ? erp_company_name($conn) : ''; echo htmlspecialchars($__cn !== '' ? $__cn : (defined('TENANT_NAME') ? TENANT_NAME : ''), ENT_QUOTES); ?></h2>
        <div style="text-align:center;color:#888;font-size:12px;margin:-14px 0 18px;"><?php echo htmlspecialchars(defined('ERP_APP_NAME') ? ERP_APP_NAME : 'Sohoj ERP', ENT_QUOTES); ?></div>
        <?php if ($error != "") { echo '<div class="error-msg">' . htmlspecialchars($error) . '</div>'; } ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label>ইউজারনেম</label>
                <input type="text" name="username" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus>
            </div>
            <div class="form-group">
                <label>পাসওয়ার্ড</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit">লগইন করুন</button>
        </form>
    </div>
</body>
</html>