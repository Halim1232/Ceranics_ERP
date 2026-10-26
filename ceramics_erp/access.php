<?php
// access.php (প্রজেক্টের রুটে, auth.php এর পাশে)
// Role ও পেজ-পারমিশন হেল্পার। include করলে কোনো আউটপুট হয় না।
// পারমিশনের উৎস: erp_menus.route_link + erp_role_permissions (Role Permissions Matrix এ যা টিক দেওয়া)
//
// ব্যবহার (পেজের শুরুতে, auth.php এর পরে):
//   require_page_access();                              // এই পেজের নিজের মেনু-পারমিশন লাগবে
//   require_page_access('sales/sales_invoice_list.php');// অন্য মেনুর পারমিশন ধার নেবে (print/helper পেজের জন্য)
//   require_admin();                                    // শুধু admin
//   page_access_allowed(...)                            // true/false (JSON/AJAX এর জন্য)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// রিকোয়েস্ট-প্রতি একটাই DB কানেকশন
function pa_db() {
    static $c = null;
    if ($c === null) {
        include __DIR__ . '/config.php';   // লোকাল স্কোপে $conn তৈরি করে
        $c = $conn;
    }
    return $c;
}

// ইউজারের বর্তমান রোল: প্রতি রিকোয়েস্টে DB থেকে (সেশনের পুরনো মান বিশ্বাস করা হয় না)
// ইউজার Inactive/মুছে গেলে '' ফেরত, তখন সব গার্ডেড পেজ বন্ধ।
function current_role() {
    static $role = null;
    if ($role !== null) return $role;
    $role = '';
    if (empty($_SESSION['user_id'])) return $role;
    try {
        $st = pa_db()->prepare("SELECT role, status FROM erp_users WHERE id = ?");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param("i", $uid);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r && $r['status'] === 'Active') $role = trim((string)$r['role']);   // রোল ফাঁকা থাকলে কোনো অ্যাক্সেস নেই ('admin' ধরা হয় না)
    } catch (Throwable $e) { $role = ''; }
    $_SESSION['role'] = $role;   // index.php সাইডবারও এটা ব্যবহার করে
    return $role;
}

// রোলের দেখানোর নাম (ডাটাবেজের কী 'admin'/'superadmin' ই থাকে, স্ক্রিনে এই নাম দেখায়)
if (!function_exists('role_label')) {
    function role_label($r) {
        $m = ['superadmin' => 'Super Admin', 'admin' => 'Company Admin'];
        return $m[$r] ?? $r;
    }
}

function pa_norm_route($r) {
    return ltrim(strtolower(trim(str_replace('\\', '/', (string)$r))), '/');
}

// চলতি স্ক্রিপ্টের প্রজেক্ট-রুট থেকে আপেক্ষিক পাথ, যেমন "product/product_list.php"
function pa_current_route() {
    $file = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $root = realpath(__DIR__);
    if (!$file || !$root) return '';
    return pa_norm_route(substr($file, strlen($root)));
}

// সীমা: null = টেবিল নেই/ফাঁকা (কোনো সীমা নেই), নইলে [menu_id => true]
function pa_ceiling_ids() {
    static $ids = false;
    if ($ids !== false) return $ids;
    $ids = null;
    try {
        $r = pa_db()->query("SELECT menu_id FROM erp_admin_menu_grants");
        $tmp = [];
        while ($x = $r->fetch_assoc()) $tmp[(int)$x['menu_id']] = true;
        if ($tmp) $ids = $tmp;
    } catch (Throwable $e) { $ids = null; }
    return $ids;
}

function page_access_allowed($route = null) {
    if (empty($_SESSION['user_id'])) return false;
    $role = current_role();
    if ($role === 'superadmin') return true;
    if ($role === '') return false;
    $ceil = pa_ceiling_ids();
    if ($role === 'admin' && $ceil === null) return true;

    $target = pa_norm_route($route !== null ? $route : pa_current_route());
    $tp = explode('?', $target, 2);
    $tpath = $tp[0];
    $tq = [];
    if (isset($tp[1])) {
        parse_str($tp[1], $tq);
    } else {
        foreach ($_GET as $k => $v) { if (is_scalar($v)) $tq[strtolower($k)] = strtolower((string)$v); }
    }
    if ($tpath === '') return false;

    $base = "m.status = 'Active' AND m.route_link IS NOT NULL AND m.route_link <> '' AND m.route_link NOT LIKE '#%'";
    try {
        if ($role === 'admin') {          // Company Admin = শুধু Super Admin এর দেওয়া সীমা
            $st = pa_db()->prepare("SELECT m.route_link FROM erp_admin_menu_grants g JOIN erp_menus m ON m.id = g.menu_id WHERE $base");
        } else {                          // অন্য রোল = নিজের পারমিশন ∩ সীমা
            $st = pa_db()->prepare("SELECT m.route_link FROM erp_role_permissions p JOIN erp_menus m ON m.id = p.menu_id
                                    WHERE p.role_name = ? AND $base" .
                                    ($ceil !== null ? " AND m.id IN (SELECT menu_id FROM erp_admin_menu_grants)" : ""));
            $st->bind_param("s", $role);
        }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $mp = explode('?', pa_norm_route($r['route_link']), 2);
            if ($mp[0] !== $tpath) continue;
            $mq = [];
            if (isset($mp[1])) parse_str($mp[1], $mq);
            $ok = true;
            foreach ($mq as $k => $v) { if (!isset($tq[$k]) || $tq[$k] !== $v) { $ok = false; break; } }
            if ($ok) return true;
        }
    } catch (Throwable $e) { return false; }
    return false;
}

function require_admin($json = false) {      // Company Admin বা Super Admin
    if (!in_array(current_role(), ['admin', 'superadmin'], true)) pa_deny($json);
}
function require_super($json = false) {      // শুধু Super Admin
    if (current_role() !== 'superadmin') pa_deny($json);
}
function require_page_access($route = null, $json = false) {
    if (!page_access_allowed($route)) {
        pa_deny($json);
    }
}

function pa_deny($json = false) {
    http_response_code(403);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'আপনার এই পেজে প্রবেশের অনুমতি নেই।'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo '<!DOCTYPE html>
    <html lang="bn">
    <head><meta charset="UTF-8"><title>Access Denied</title></head>
    <body style="font-family:Arial,sans-serif;padding:40px;text-align:center">
        <h2>403 - Access Denied</h2>
        <p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p>
        <a href="../index.php">ERP Dashboard-এ ফিরে যান</a>
    </body>
    </html>';
    exit;
}
// ---- ব্রাঞ্চ স্কোপ ----
// null = সব ব্রাঞ্চ; নইলে ['id'=>, 'name'=>]। ব্রাঞ্চ বরাদ্দ না থাকলে কিছুই দেখাবে না।
function current_branch() {
    static $b = false;
    if ($b !== false) return $b;
    $b = null;
    $role = current_role();
    if ($role === '' || $role === 'admin' || $role === 'superadmin') return $b;
    $none = ['id' => 2147483647, 'name' => '__no_branch__'];
    try {
        $st = pa_db()->prepare("SELECT u.branch_id, br.branch_name FROM erp_users u LEFT JOIN erp_branches br ON br.id = u.branch_id WHERE u.id = ?");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param("i", $uid); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        if (!$r || $r['branch_id'] === null) { $b = $none; }
        elseif ((int)$r['branch_id'] === 0)  { $b = null; }
        elseif ($r['branch_name'] !== null)  { $b = ['id' => (int)$r['branch_id'], 'name' => $r['branch_name']]; }
        else                                 { $b = $none; }
    } catch (Throwable $e) { $b = $none; }
    return $b;
}
// WHERE এ যোগ করার টুকরো: "... WHERE 1=1" . branch_sql('m.branch_name')
function branch_sql($col) {
    $b = current_branch(); if (!$b) return '';
    return " AND $col = '" . pa_db()->real_escape_string($b['name']) . "'";
}
function branch_wh_sql($col = 'warehouse_id') {
    $b = current_branch(); if (!$b) return '';
    return " AND $col IN (SELECT id FROM erp_warehouses WHERE branch_id = " . (int)$b['id'] . ")";
}
// নির্দিষ্ট রেকর্ড খোলা/এডিট/ডিলিট/প্রিন্টের আগে
function branch_guard($branch_name, $json = false) {
    $b = current_branch();
    if ($b && (string)$branch_name !== $b['name']) pa_deny($json);
}
function branch_guard_wh($wid, $json = false) {
    $b = current_branch(); if (!$b) return;
    $st = pa_db()->prepare("SELECT 1 FROM erp_warehouses WHERE id = ? AND branch_id = ?");
    $w = (int)$wid; $bi = (int)$b['id'];
    $st->bind_param("ii", $w, $bi); $st->execute();
    if (!$st->get_result()->num_rows) pa_deny($json);
}
require_once __DIR__ . '/branch_lib.php';