<?php
// tools/build_template.php -- ekhon-kar (Uttara-r) database theke notun customer-er jonno "faka template" toiri kore:
//   schema/template_schema.sql : shob table er structure (kono data na)
//   schema/template_seed.sql   : shudhu reference data (menu, role, permission, chart of accounts, fund account)
//
//   php tools/build_template.php --db=ceramics_erp --user=root [--pass=] [--host=localhost] [--seed=erp_menus,erp_roles,...]
//
// Customer, product, invoice, stock ityadi KONO transaction data ei file e jabe na.
// Chalanor por schema/template_seed.sql ekbar chokh bulie dekhe nin (jemon menu te duplicate/vul thakle age source e thik korun).

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

function cli_parse(array $args) {
    $o = [];
    foreach ($args as $a) if (substr($a, 0, 2) === '--') { $kv = explode('=', substr($a, 2), 2); $o[$kv[0]] = $kv[1] ?? '1'; }
    return $o;
}
$o = cli_parse(array_slice($argv, 1));
$host = $o['host'] ?? 'localhost';
$db   = $o['db'] ?? 'ceramics_erp';
$user = $o['user'] ?? 'root';
$pass = $o['pass'] ?? '';
$seedTables = array_filter(array_map('trim', explode(',', $o['seed'] ?? 'erp_menus,erp_roles,erp_chart_of_accounts,erp_fund_accounts')));
// loop er bhitore:
$r = $m->query("SELECT * FROM `$t`" . ($t === 'erp_roles' ? " WHERE is_system = 1" : ""));

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = new mysqli($host, $user, $pass, $db);
$m->set_charset('utf8mb4');

$dir = dirname(__DIR__) . '/schema';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// ---------- 1) schema ----------
$tables = []; $views = [];
$res = $m->query("SHOW FULL TABLES");
while ($r = $res->fetch_row()) { if (strtoupper($r[1]) === 'VIEW') $views[] = $r[0]; else $tables[] = $r[0]; }

$sql  = "-- template_schema.sql  (source db: $db, " . date('Y-m-d H:i') . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach ($tables as $t) {
    $row = $m->query("SHOW CREATE TABLE `$t`")->fetch_row();
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/i', '', $row[1]);     // counter reset
    $sql .= $ddl . ";\n\n";
}
$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
file_put_contents($dir . '/template_schema.sql', $sql);
echo count($tables) . " ta table, schema/template_schema.sql e likha holo.\n";
if ($views) echo "SOTORKO: " . count($views) . " ta VIEW ache (" . implode(', ', $views) . "). View template e jay na, alada kore chalate hobe.\n";
foreach (['erp_warehouses', 'erp_purchase_return', 'erp_sales_return'] as $need) {
    if (!in_array($need, $tables, true)) echo "SOTORKO: '$need' table source e nai (migration/page ekbar chalan, tarpor abar build korun).\n";
}

// ---------- 2) seed ----------
$out = "-- template_seed.sql : shudhu reference data\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach ($seedTables as $t) {
    if (!in_array($t, $tables, true)) { echo "SOTORKO: seed table '$t' nai, bad dewa holo.\n"; continue; }
    $r = $m->query("SELECT * FROM `$t`");
    if ($r->num_rows === 0) { echo "  $t : kono row nai\n"; continue; }
    $fields = $r->fetch_fields();
    $cols = array_map(function ($f) { return '`' . $f->name . '`'; }, $fields);
    $rows = [];
    while ($row = $r->fetch_assoc()) {
        $vals = [];
        foreach ($fields as $f) {
            $v = $row[$f->name];
            if ($v === null) { $vals[] = 'NULL'; continue; }
            if (strpos($f->name, 'opening_') === 0) { $vals[] = "'0'"; continue; }    // opening balance customer e jabe na
            $vals[] = "'" . $m->real_escape_string($v) . "'";
        }
        $rows[] = '(' . implode(',', $vals) . ')';
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        $out .= "INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $chunk) . ";\n";
    }
    $out .= "\n";
    echo "  $t : " . count($rows) . " row\n";
}
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";
file_put_contents($dir . '/template_seed.sql', $out);
echo "schema/template_seed.sql likha holo.\n";
echo "Ekhon chokh bulie dekhun, tarpor: php tools/tenants.php create ...\n";