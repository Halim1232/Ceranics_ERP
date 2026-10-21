<?php
// core/provision.php -- tenant (customer) toiri/manage er function. Shudhu CLI.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/tenant.php';

function prov_boot() {
    static $done = false;
    if ($done) return;
    if (!erp_load_env()) throw new RuntimeException('erp_env.php paoa jayni: ' . erp_env_path());
    $p = getenv('ERP_PROVISION_FILE') ?: dirname(__DIR__) . '/erp_provision.php';
    if (!is_file($p)) throw new RuntimeException('erp_provision.php paoa jayni: ' . $p);
    require_once $p;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $done = true;
}

// MySQL admin (root) connection. $db khali hole kono database select hobe na.
function prov_admin($db = null) {
    prov_boot();
    $c = new mysqli(ERP_PROVISION_HOST, ERP_PROVISION_USER, ERP_PROVISION_PASS, $db ?: null);
    $c->set_charset('utf8mb4');
    return $c;
}

// master database (CLI te write permission lage, tai admin user diye)
function prov_master() {
    static $m = null;
    if ($m instanceof mysqli) return $m;
    $m = prov_admin(ERP_MASTER_DB);
    return $m;
}

function prov_random($len = 12) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}

function prov_add_months($date, $n) {
    $d = new DateTime($date);
    $day = (int)$d->format('j');
    $d->modify('first day of this month');
    $d->modify('+' . (int)$n . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

function prov_run_sql_file($conn, $file) {
    if (!is_file($file)) throw new RuntimeException("SQL file nai: $file (age: php tools/build_template.php chalan)");
    $conn->multi_query(file_get_contents($file));
    do {
        if ($r = $conn->store_result()) $r->free();
    } while ($conn->more_results() && $conn->next_result());
}

function prov_url_host($sub) { return $sub . '.' . ERP_BASE_DOMAIN; }

function prov_get_tenant($sub) {
    $m = prov_master();
    $st = $m->prepare("SELECT * FROM tenants WHERE subdomain = ? LIMIT 1");
    $st->bind_param('s', $sub);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$r) throw new RuntimeException("'$sub' naam er kono customer nai.");
    return $r;
}

// ---------- create ----------
function tenant_create(array $a) {
    prov_boot();
    $sub       = strtolower(trim($a['subdomain'] ?? ''));
    $company   = trim($a['company'] ?? '');
    $adminUser = strtolower(trim($a['admin_user'] ?? 'admin'));
    $adminPass = (string)($a['admin_pass'] ?? '');
    $adminName = trim($a['admin_name'] ?? '');

    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}$/', $sub))
        throw new InvalidArgumentException("subdomain e shudhu a-z, 0-9 ar hyphen (-) cholbe, underscore (_) na. Jemon: nakshi-tiles");
    if ($company === '') throw new InvalidArgumentException('--company dite hobe.');
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $adminUser)) throw new InvalidArgumentException('admin-user 3-50 okkhor, a-z 0-9 . _ - cholbe.');
    if ($adminPass === '') $adminPass = prov_random(12);
    if (strlen($adminPass) < 6) throw new InvalidArgumentException('admin-pass komopokkhe 6 okkhor.');
    if ($adminName === '') $adminName = $company . ' Admin';

    $m = prov_master();

    $st = $m->prepare("SELECT id FROM tenants WHERE subdomain = ?");
    $st->bind_param('s', $sub); $st->execute();
    if ($st->get_result()->num_rows > 0) throw new RuntimeException("'$sub' age theke ache.");
    $st->close();

    $planId = (int)($a['plan_id'] ?? 0);
    if ($planId > 0) {
        $st = $m->prepare("SELECT id FROM plans WHERE id = ?");
        $st->bind_param('i', $planId); $st->execute();
        if (!$st->get_result()->num_rows) throw new RuntimeException("Plan #$planId paoa jayni.");
        $st->close();
    } else $planId = null;

    $cycle  = (strcasecmp((string)($a['cycle'] ?? ''), 'Yearly') === 0) ? 'Yearly' : 'Monthly';
    $months = (int)($a['months'] ?? 0);
    if ($months <= 0) $months = ($cycle === 'Yearly') ? 12 : 1;
    $end = prov_add_months(date('Y-m-d'), $months);

    $dbName = 'erp_' . str_replace('-', '_', $sub);
    $dbUser = substr($dbName, 0, 32);
    $dbPass = prov_random(24);
    $uhost  = ERP_TENANT_DB_USER_HOST;

    $adm = prov_admin();
    $st = $adm->prepare("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
    $st->bind_param('s', $dbName); $st->execute();
    if ($st->get_result()->num_rows > 0) throw new RuntimeException("Database '$dbName' age theke ache, create kora holo na.");
    $st->close();

    $dbMade = false; $userMade = false;
    try {
        $adm->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $dbMade = true;
        $adm->query("CREATE USER '$dbUser'@'$uhost' IDENTIFIED BY '" . $adm->real_escape_string($dbPass) . "'");
        $userMade = true;
        $adm->query("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$uhost'");

        $t = prov_admin($dbName);
        $schemaDir = dirname(__DIR__) . '/schema';
        prov_run_sql_file($t, $schemaDir . '/template_schema.sql');
        prov_run_sql_file($t, $schemaDir . '/template_seed.sql');

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $st = $t->prepare("INSERT INTO erp_users (username, password, full_name, role, status) VALUES (?,?,?,'superadmin','Active')");
        $st->bind_param('sss', $adminUser, $hash, $adminName);
        $st->execute(); $st->close();

        // company profile: seed er (Uttara) default na, ei customer er nijer naam
        try {
            $cPhone = (string)($a['phone'] ?? ''); $cEmail = (string)($a['email'] ?? '');
            $st = $t->prepare("INSERT INTO erp_company_profile (id, company_name, tagline, address, phone, mobile, email) VALUES (1, ?, '', '', ?, '', ?)
                               ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), tagline = '', address = '', phone = VALUES(phone), mobile = '', email = VALUES(email)");
            $st->bind_param('sss', $company, $cPhone, $cEmail);
            $st->execute(); $st->close();
        } catch (Throwable $e) { error_log('[erp] company profile seed failed: ' . $e->getMessage()); }
        $t->close();

        $enc     = erp_encrypt($dbPass);
        $phone   = $a['phone']   ?? null;
        $email   = $a['email']   ?? null;
        $contact = $a['contact'] ?? null;
        $notes   = $a['notes']   ?? null;
        $start   = date('Y-m-d');
        $st = $m->prepare("INSERT INTO tenants (subdomain, company_name, contact_person, contact_phone, contact_email, db_host, db_name, db_user, db_pass_enc, plan_id, billing_cycle, status, subscription_start, subscription_end, notes)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,'Active',?,?,?)");
        $dbHost = ERP_PROVISION_HOST;
        $st->bind_param('sssssssssissss', $sub, $company, $contact, $phone, $email, $dbHost, $dbName, $dbUser, $enc, $planId, $cycle, $start, $end, $notes);
        $st->execute(); $st->close();
    } catch (Throwable $e) {
        // adha-adhi toiri hole porishkar kore dei
        try { if ($userMade) $adm->query("DROP USER IF EXISTS '$dbUser'@'$uhost'"); } catch (Throwable $x) {}
        try { if ($dbMade)   $adm->query("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable $x) {}
        throw $e;
    }

    return ['url_host' => prov_url_host($sub), 'admin_user' => $adminUser, 'admin_pass' => $adminPass,
            'subscription_end' => $end, 'db' => $dbName, 'db_user' => $dbUser];
}

// ---------- adopt (agei thaka database ke master e tola) ----------
function tenant_adopt(array $a) {
    prov_boot();
    $sub     = strtolower(trim($a['subdomain'] ?? ''));
    $company = trim($a['company'] ?? '');
    $db      = trim($a['db'] ?? '');
    $dbUser  = trim($a['db_user'] ?? '');
    $dbPass  = (string)($a['db_pass'] ?? '');
    $dbHost  = trim($a['db_host'] ?? '') ?: 'localhost';
    $planId  = (int)($a['plan_id'] ?? 0); $planId = $planId > 0 ? $planId : null;
    $end     = trim($a['end'] ?? '');

    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}$/', $sub)) throw new InvalidArgumentException('subdomain thik na (a-z, 0-9, hyphen).');
    if ($company === '' || $db === '' || $dbUser === '') throw new InvalidArgumentException('--company, --db, --db-user dorkar.');
    if ($end === '') $end = prov_add_months(date('Y-m-d'), 1);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) throw new InvalidArgumentException('--end format: YYYY-MM-DD');

    $test = new mysqli($dbHost, $dbUser, $dbPass, $db);   // connect hoy kina age dekhi
    $test->close();

    $m = prov_master();
    $st = $m->prepare("SELECT id FROM tenants WHERE subdomain = ?");
    $st->bind_param('s', $sub); $st->execute();
    if ($st->get_result()->num_rows > 0) throw new RuntimeException("'$sub' age theke ache.");
    $st->close();

    $enc = erp_encrypt($dbPass);
    $st = $m->prepare("INSERT INTO tenants (subdomain, company_name, db_host, db_name, db_user, db_pass_enc, plan_id, billing_cycle, subscription_end, status)
                       VALUES (?,?,?,?,?,?,?,'Monthly',?,'Active')");
    $st->bind_param('ssssssis', $sub, $company, $dbHost, $db, $dbUser, $enc, $planId, $end);
    $st->execute(); $st->close();

    return ['url_host' => prov_url_host($sub), 'subscription_end' => $end];
}

// ---------- suspend / activate ----------
function tenant_set_status($sub, $status) {
    prov_boot();
    prov_get_tenant($sub);
    $st = prov_master()->prepare("UPDATE tenants SET status = ? WHERE subdomain = ?");
    $st->bind_param('ss', $status, $sub);
    $st->execute(); $st->close();
}

// ---------- meyad barano + payment record ----------
function tenant_extend($sub, $months, $amount = 0, $method = '', $ref = '', $note = '') {
    prov_boot();
    $months = (int)$months;
    if ($months <= 0) throw new InvalidArgumentException('--months 1 ba tar beshi dite hobe.');
    $t = prov_get_tenant($sub);

    $today = date('Y-m-d');
    $cur   = $t['subscription_end'];
    $from  = ($cur && $cur >= $today) ? $cur : $today;   // meyad ekhono baki hole tar por theke, na hole aj theke
    $to    = prov_add_months($from, $months);
    $amount = (float)$amount;
    $tid = (int)$t['id'];

    $m = prov_master();
    $m->begin_transaction();
    try {
        $st = $m->prepare("UPDATE tenants SET subscription_end = ? WHERE id = ?");
        $st->bind_param('si', $to, $tid); $st->execute(); $st->close();

        $st = $m->prepare("INSERT INTO tenant_payments (tenant_id, amount, method, reference, note, period_from, period_to) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('idsssss', $tid, $amount, $method, $ref, $note, $from, $to);
        $st->execute(); $st->close();
        $m->commit();
    } catch (Throwable $e) { $m->rollback(); throw $e; }
    return ['from' => $from, 'to' => $to];
}

// ---------- customer er user er password reset ----------
function tenant_reset_password($sub, $username, $newPass) {
    prov_boot();
    if (strlen($newPass) < 6) throw new InvalidArgumentException('Password komopokkhe 6 okkhor.');
    $t = prov_get_tenant($sub);
    $pass = erp_decrypt($t['db_pass_enc']);
    if ($pass === null) throw new RuntimeException('DB password decrypt hoyni (ERP_APP_KEY thik ache?).');

    $c = new mysqli($t['db_host'] ?: 'localhost', $t['db_user'], $pass, $t['db_name']);
    $c->set_charset('utf8mb4');
    $username = strtolower(trim($username));
    $st = $c->prepare("SELECT id FROM erp_users WHERE username = ?");
    $st->bind_param('s', $username); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) throw new RuntimeException("'$username' naam er user nai.");

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $id = (int)$row['id'];
    $st = $c->prepare("UPDATE erp_users SET password = ? WHERE id = ?");
    $st->bind_param('si', $hash, $id); $st->execute(); $st->close();
    $c->close();
}