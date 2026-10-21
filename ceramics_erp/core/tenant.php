<?php
// core/tenant.php -- Tenant resolve (subdomain -> customer er database) + encryption helpers.
// Web (config.php) ar CLI (tools/*.php) dutoi ei file use kore.

// ---------- env file ----------
function erp_env_path() {
    return getenv('ERP_ENV_FILE') ?: dirname(__DIR__) . '/erp_env.php';
}
function erp_load_env() {
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    $p = erp_env_path();
    if (is_file($p)) { require_once $p; $loaded = true; } else { $loaded = false; }
    return $loaded;
}

// ---------- encryption (tenant DB password master e encrypted thake) ----------
function erp_key() {
    if (!defined('ERP_APP_KEY')) throw new RuntimeException('ERP_APP_KEY erp_env.php te nai.');
    $k = ERP_APP_KEY;
    if (strpos($k, 'base64:') === 0) $k = base64_decode(substr($k, 7));
    if (strlen($k) !== 32) throw new RuntimeException('ERP_APP_KEY 32 byte hote hobe. Chalan: php tools/tenants.php gen-key');
    return $k;
}
function erp_encrypt($plain) {
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', erp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('Encrypt failed (openssl extension on ache?)');
    return base64_encode($iv . $tag . $c);
}
function erp_decrypt($b64) {
    $raw = base64_decode((string)$b64, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', erp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

// ---------- error / block page ----------
function erp_block_page($code, $title, $msg) {
    if (php_sapi_name() === 'cli') { fwrite(STDERR, "$title: $msg\n"); exit(1); }
    http_response_code($code);
    $support = defined('ERP_SUPPORT_TEXT') ? ERP_SUPPORT_TEXT : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title></head>'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">'
       . '<div style="background:#fff;padding:35px 45px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);border-top:5px solid #c0392b;max-width:460px;text-align:center;">'
       . '<h2 style="margin:0 0 12px;color:#333;">' . htmlspecialchars($title) . '</h2>'
       . '<p style="color:#555;line-height:1.6;margin:0;">' . htmlspecialchars($msg) . '</p>'
       . ($support !== '' ? '<p style="color:#888;font-size:13px;margin-top:18px;">' . htmlspecialchars($support) . '</p>' : '')
       . '</div></body></html>';
    exit;
}

// ---------- master database (web: shudhu SELECT permission dilei cholbe) ----------
function erp_master_conn() {
    static $m = null;
    if ($m instanceof mysqli) return $m;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli(ERP_MASTER_HOST, ERP_MASTER_USER, ERP_MASTER_PASS, ERP_MASTER_DB);
    return $m;
}

// ---------- constants ----------
function tenant_define_constants(array $T, $legacy) {
    $set = function ($k, $v) { if (!defined($k)) define($k, $v); };
    $root = dirname(__DIR__);
    $set('TENANT_ID', (int)$T['id']);
    $set('TENANT_SUBDOMAIN', $T['subdomain']);
    $set('TENANT_NAME', (string)($T['company_name'] ?? ''));
    $set('TENANT_DB', $T['db_name']);
    $set('TENANT_LEGACY', (bool)$legacy);
    $set('TENANT_PLAN_ID', isset($T['plan_id']) ? (int)$T['plan_id'] : 0);
    $mods = trim((string)($T['plan_modules'] ?? ''));
    $set('TENANT_MODULES', $mods === '' ? null : array_values(array_filter(array_map('trim', explode(',', strtolower($mods))))));
    $set('TENANT_MAX_USERS', (int)($T['max_users'] ?: ($T['plan_max_users'] ?? 0)));
    $end = $T['subscription_end'] ?? null;
    $grace = (int)($T['grace_days'] ?? 0);
    $set('TENANT_SUB_END', $end);
    $set('TENANT_EXPIRED', $end ? (strtotime($end . ' 23:59:59') + $grace * 86400 < time()) : false);
    $set('TENANT_DAYS_LEFT', $end ? (int)floor((strtotime($end . ' 23:59:59') - time()) / 86400) : null);
    // upload folder: customer-wise alada
    if ($legacy) { $set('TENANT_UPLOAD_DIR', $root . '/uploads/'); $set('TENANT_UPLOAD_REL', 'uploads/'); }
    else         { $set('TENANT_UPLOAD_DIR', $root . '/uploads/' . $T['subdomain'] . '/'); $set('TENANT_UPLOAD_REL', 'uploads/' . $T['subdomain'] . '/'); }
}

// ---------- main: ei request kon customer er? ----------
function tenant_boot() {
    static $T = null;
    if ($T !== null) return $T;

    // env file na thakle = ager moto single database (XAMPP te cholte thakbe)
    if (!erp_load_env()) {
        $T = ['id' => 0, 'subdomain' => 'legacy', 'company_name' => '', 'db_host' => 'localhost', 'db_name' => 'ceramics_erp',
              'db_user' => 'root', 'db_pass' => '', 'plan_id' => null, 'plan_modules' => null, 'max_users' => 0,
              'subscription_end' => null, 'grace_days' => 0, 'status' => 'Active'];
        tenant_define_constants($T, true);
        return $T;
    }

    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.\-]+$/', $host)) erp_block_page(400, 'Invalid request', 'Thik ekta website address diye ashun.');

    $base = strtolower(ERP_BASE_DOMAIN);
    $sub = null; $custom = null;
    if (substr($host, -(strlen($base) + 1)) === '.' . $base) {
        $sub = substr($host, 0, -(strlen($base) + 1));
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,38}$/', $sub)) erp_block_page(404, 'Not found', 'এই ঠিকানায় কোনো কোম্পানি পাওয়া যায়নি।');
    } elseif (defined('ERP_DEV_TENANT') && ERP_DEV_TENANT !== '' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $sub = ERP_DEV_TENANT;   // local test: localhost e kon customer dekhabe (ERP_BASE_DOMAIN=localhost hole-o kaj kore)
    } elseif ($host === $base || $host === 'www.' . $base) {
        erp_block_page(404, 'Not found', 'আপনার কোম্পানির নিজস্ব ঠিকানা দিয়ে লগইন করুন।');
    } else {
        $custom = $host;         // customer er nijer domain
    }

    try {
        $m = erp_master_conn();
        $sql = "SELECT t.*, p.modules AS plan_modules, p.max_users AS plan_max_users FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE ";
        if ($sub !== null) { $st = $m->prepare($sql . "t.subdomain = ? LIMIT 1"); $st->bind_param('s', $sub); }
        else               { $st = $m->prepare($sql . "t.custom_domain = ? LIMIT 1"); $st->bind_param('s', $custom); }
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    } catch (Throwable $e) {
        error_log('[erp] master db error: ' . $e->getMessage());
        erp_block_page(503, 'Service unavailable', 'সিস্টেম এই মুহূর্তে চালু নেই। একটু পরে আবার চেষ্টা করুন।');
    }

    if (!$row) erp_block_page(404, 'Not found', 'এই ঠিকানায় কোনো কোম্পানি পাওয়া যায়নি।');
    if ($row['status'] !== 'Active') erp_block_page(403, 'অ্যাকাউন্ট বন্ধ আছে', 'এই অ্যাকাউন্টটি সাময়িকভাবে বন্ধ আছে। অনুগ্রহ করে সার্ভিস প্রদানকারীর সাথে যোগাযোগ করুন।');

    $pass = erp_decrypt($row['db_pass_enc']);
    if ($pass === null) { error_log('[erp] cannot decrypt db password for ' . $row['subdomain']); erp_block_page(500, 'Server error', 'কনফিগারেশন ত্রুটি। সাপোর্টে জানান।'); }

    $T = $row;
    $T['db_host'] = $row['db_host'] ?: 'localhost';
    $T['db_pass'] = $pass;
    unset($T['db_pass_enc']);
    tenant_define_constants($T, false);
    return $T;
}

// session cookie surokkha (session start er age call korte hobe)
function tenant_session_setup() {
    if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_NONE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
}