<?php
// tools/tenants.php -- customer manage korar CLI (server e terminal theke chalate hobe, browser theke na)
//
//   php tools/tenants.php create --subdomain=abc --company="ABC Tiles" --admin-user=abcadmin [--admin-pass=...] [--admin-name="Rahim"]
//                                [--plan=1] [--cycle=Monthly|Yearly] [--months=1] [--phone=01xxx] [--email=a@b.c] [--contact="Rahim"]
//   php tools/tenants.php adopt  --subdomain=uttara --company="Uttara Builders" --db=sohoj_erp --db-user=root [--db-pass=] [--db-host=localhost] [--plan=1] [--end=2027-01-31]
//   php tools/tenants.php list
//   php tools/tenants.php suspend  abc
//   php tools/tenants.php activate abc
//   php tools/tenants.php extend abc --months=1 [--amount=1500] [--method=bKash] [--ref=TRX123] [--note="Nov"]
//   php tools/tenants.php reset-password abc abcadmin [--pass=NewPass123]
//   php tools/tenants.php gen-key

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/core/provision.php';
require_once dirname(__DIR__) . '/core/tenant.php';

function cli_parse(array $args) {
    $opts = []; $pos = [];
    foreach ($args as $a) {
        if (substr($a, 0, 2) === '--') {
            $kv = explode('=', substr($a, 2), 2);
            $opts[$kv[0]] = $kv[1] ?? '1';
        } else $pos[] = $a;
    }
    return [$opts, $pos];
}
function out($s = '') { echo $s . PHP_EOL; }

$cmd = $argv[1] ?? 'help';
list($o, $pos) = cli_parse(array_slice($argv, 2));

try {
    switch ($cmd) {
        case 'gen-key':
            out('ERP_APP_KEY er jonno ei line ta erp_env.php te boshan:');
            out("define('ERP_APP_KEY', 'base64:" . base64_encode(random_bytes(32)) . "');");
            out('(Eta nirapod jaygay backup rakhun. Hariye gele shob customer er DB password decrypt hobe na.)');
            break;

        case 'create':
            $r = tenant_create([
                'subdomain' => $o['subdomain'] ?? '', 'company' => $o['company'] ?? '',
                'admin_user' => $o['admin-user'] ?? 'admin', 'admin_pass' => $o['admin-pass'] ?? '', 'admin_name' => $o['admin-name'] ?? '',
                'plan_id' => $o['plan'] ?? 0, 'cycle' => $o['cycle'] ?? 'Monthly', 'months' => $o['months'] ?? 0,
                'phone' => $o['phone'] ?? null, 'email' => $o['email'] ?? null, 'contact' => $o['contact'] ?? null, 'notes' => $o['notes'] ?? null,
            ]);
            out('');
            out('=== Customer toiri hoyeche ===');
            out('Address     : https://' . $r['url_host']);
            out('Username    : ' . $r['admin_user']);
            out('Password    : ' . $r['admin_pass'] . '   (eta ekbar-i dekhano holo, customer ke diye din)');
            out('Meyad sesh : ' . $r['subscription_end']);
            out('Database    : ' . $r['db'] . '  (user: ' . $r['db_user'] . ')');
            break;

        case 'adopt':
            $r = tenant_adopt([
                'subdomain' => $o['subdomain'] ?? '', 'company' => $o['company'] ?? '', 'db' => $o['db'] ?? '', 'db_user' => $o['db-user'] ?? '',
                'db_pass' => $o['db-pass'] ?? '', 'db_host' => $o['db-host'] ?? 'localhost', 'plan_id' => $o['plan'] ?? 0, 'end' => $o['end'] ?? '',
            ]);
            out('Master e tola hoyeche: https://' . $r['url_host'] . '   (meyad: ' . $r['subscription_end'] . ')');
            out('Mone rakhun: ei database er data kichu bodlayni.');
            break;

        case 'list':
            prov_boot();
            $m = prov_master();
            $res = $m->query("SELECT t.subdomain, t.company_name, t.status, t.subscription_end, t.billing_cycle, IFNULL(p.plan_name, '-') AS plan
                              FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id ORDER BY t.subdomain");
            printf("%-16s %-28s %-10s %-9s %-12s %s\n", 'SUBDOMAIN', 'COMPANY', 'STATUS', 'PLAN', 'MEYAD', 'BAKI DIN');
            while ($r = $res->fetch_assoc()) {
                $left = $r['subscription_end'] ? (int)floor((strtotime($r['subscription_end'] . ' 23:59:59') - time()) / 86400) : '';
                printf("%-16s %-28s %-10s %-9s %-12s %s\n", $r['subdomain'], mb_substr($r['company_name'], 0, 28), $r['status'], $r['plan'], $r['subscription_end'] ?: '-', $left);
            }
            break;

        case 'suspend':
        case 'activate':
            $sub = $pos[0] ?? ''; if ($sub === '') throw new InvalidArgumentException("Use: php tools/tenants.php $cmd <subdomain>");
            tenant_set_status($sub, $cmd === 'suspend' ? 'Suspended' : 'Active');
            out("'$sub' ekhon " . ($cmd === 'suspend' ? 'Suspended' : 'Active') . '.');
            break;

        case 'extend':
            $sub = $pos[0] ?? ''; if ($sub === '') throw new InvalidArgumentException('Use: php tools/tenants.php extend <subdomain> --months=1 [--amount=...]');
            $r = tenant_extend($sub, $o['months'] ?? 0, $o['amount'] ?? 0, $o['method'] ?? '', $o['ref'] ?? '', $o['note'] ?? '');
            out("'$sub' er meyad barlo: " . $r['from'] . ' theke ' . $r['to'] . ' porjonto. Payment record rakha holo.');
            break;

        case 'reset-password':
            $sub = $pos[0] ?? ''; $user = $pos[1] ?? '';
            if ($sub === '' || $user === '') throw new InvalidArgumentException('Use: php tools/tenants.php reset-password <subdomain> <username> [--pass=...]');
            $new = $o['pass'] ?? prov_random(12);
            tenant_reset_password($sub, $user, $new);
            out("'$user' er notun password: $new");
            break;

        default:
            out(file_get_contents(__FILE__, false, null, 0, 1800));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}