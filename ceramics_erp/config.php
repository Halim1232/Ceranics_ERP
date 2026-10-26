<?php
// config.php -- Multi-tenant aware.
// Pages age-r moto-i: include('../config.php');  => $conn (mysqli) ekhon ei request er customer er database e connected.
//   * erp_env.php thakle : subdomain dekhe master theke customer ber kore tar nijer database e connect hoy.
//   * erp_env.php na thakle: ager moto localhost / root / sohoj_erp (XAMPP dev, kono bodol lage na).

// ek request e dui-bar include hole abar connect korbe na
if (isset($conn) && $conn instanceof mysqli && defined('TENANT_ID')) { return; }

require_once __DIR__ . '/core/tenant.php';

$tenant = tenant_boot();      // customer na pele / suspended hole nijei "Not found / বন্ধ" page dekhiye thame
tenant_session_setup();       // session cookie: httponly, samesite, https hole secure

// ager config er variable gulo (Backup utility er moto page e lagte pare)
$servername = $tenant['db_host'];
$username   = $tenant['db_user'];
$password   = $tenant['db_pass'];
$dbname     = $tenant['db_name'];

try {
    $conn = mysqli_connect($servername, $username, $password, $dbname);
} catch (Throwable $e) {
    $conn = false;
    error_log('[erp] tenant db connect failed (' . $dbname . '): ' . $e->getMessage());
}
if (!$conn) {
    erp_block_page(503, 'Service unavailable', 'ডাটাবেজের সাথে সংযোগ করা যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।');
}

unset($tenant['db_pass']);   // global array te password rakhbo na

// query te vul thakle PHP jeno sathe sathe error dekhay
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!function_exists('erp_company_name')) {
    function erp_company_name($c) {
        static $n = null;
        if ($n === null) {
            $n = '';
            try { $r = mysqli_fetch_assoc(mysqli_query($c, "SELECT company_name FROM erp_company_profile WHERE id=1")); $n = trim($r['company_name'] ?? ''); } catch (Throwable $e) {}
        }
        return $n;
    }
}
if (!defined('ERP_APP_NAME')) define('ERP_APP_NAME', 'Sohoj ERP');