<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/access.php';   // current_role(), require_page_access(), require_admin()

// প্রজেক্টের বেস URL অটো বের করা (XAMPP-এ যেকোনো ফোল্ডারে কাজ করবে)
$__root = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$__dir  = str_replace('\\', '/', __DIR__);
$BASE_URL = rtrim(str_replace($__root, '', $__dir), '/');

// ৩০ মিনিট নিষ্ক্রিয় থাকলে অটো লগআউট
$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $login_url = $BASE_URL . '/login.php';
    // iframe (ট্যাব) এর ভেতরে খুললেও পুরো পেজ লগইনে যাবে
    echo "<script>window.top.location.href='" . $login_url . "';</script>";
    exit();
}

$_SESSION['last_activity'] = time();

// ---- CSRF helper (delete/save POST এ ব্যবহার) ----
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function require_csrf() {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo "<script>alert('অবৈধ অনুরোধ! পেজ রিফ্রেশ করে আবার চেষ্টা করুন।'); history.back();</script>";
        exit;
    }
}
?>
