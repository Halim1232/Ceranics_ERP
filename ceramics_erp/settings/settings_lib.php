<?php
// Settings/settings_lib.php -- Settings module er shared engine
if (!isset($conn) || !($conn instanceof mysqli)) {
    include __DIR__ . '/../config.php';   // tenant এর নিজের DB
}

function st_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Dorkari table/column gulo auto toiri (age thakle kichu kore na)
function st_ensure($conn) {
    static $done = false;          // ek request e ekbar-i cholbe (company_info/branch_info bar bar dake)
    if ($done) return;
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_company_profile (
        id INT PRIMARY KEY,
        company_name VARCHAR(150) NOT NULL DEFAULT '',
        tagline VARCHAR(200) NULL,
        address VARCHAR(255) NULL,
        phone VARCHAR(60) NULL,
        mobile VARCHAR(60) NULL,
        email VARCHAR(120) NULL,
        website VARCHAR(120) NULL,
        bin_no VARCHAR(60) NULL,
        trade_license VARCHAR(60) NULL,
        invoice_footer VARCHAR(255) NULL,
        logo_path VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_company_profile"));
    if (!(int)$c['c']) {
        mysqli_query($conn, "INSERT INTO erp_company_profile (id, company_name, tagline, address, mobile, email)
            VALUES (1, 'Uttara Builders', 'Premium Ceramics & Tiles', 'Main Road, Godown Area, Kishoreganj, Bangladesh', '017XXXXXXXX', 'info@uttarabuilders.com')");
    }

    // Header image slots: logo_path = Left 1 (age theke ache), baki 3 ta notun
    $ch = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM erp_company_profile");
    while ($q && $r = mysqli_fetch_assoc($q)) $ch[] = $r['Field'];
    foreach (['left_img2', 'right_img1', 'right_img2'] as $col) {
        if (!in_array($col, $ch)) mysqli_query($conn, "ALTER TABLE erp_company_profile ADD COLUMN `$col` VARCHAR(255) NULL");
    }

    $have = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM erp_branches");
    while ($q && $r = mysqli_fetch_assoc($q)) $have[] = $r['Field'];
    $need = [
        'branch_code' => "VARCHAR(30) NULL",
        'branch_type' => "VARCHAR(20) NOT NULL DEFAULT 'Branch'",
        'address'     => "VARCHAR(255) NULL",
        'phone'       => "VARCHAR(40) NULL",
        'status'      => "VARCHAR(10) NOT NULL DEFAULT 'Active'",
    ];
    foreach ($need as $col => $def) {
        if (!in_array($col, $have)) mysqli_query($conn, "ALTER TABLE erp_branches ADD COLUMN `$col` $def");
    }
}

// Print page ba onno kothao company info lagle: $co = company_info($conn);
function company_info($conn) {
    st_ensure($conn);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_company_profile WHERE id=1"));
    return $r ?: [];
}

// Print header er chobi: [left paths[], right paths[]]  (left: max 2, right: max 2)
function company_images($co) {
    $L = []; $R = [];
    foreach (['logo_path', 'left_img2'] as $k)  if (!empty($co[$k])) $L[] = $co[$k];
    foreach (['right_img1', 'right_img2'] as $k) if (!empty($co[$k])) $R[] = $co[$k];
    return [$L, $R];
}

// ============================================================
//  Branch-wise report / print header
// ============================================================

// Branch er row (erp_branches theke), branch er NAAM diye. Na pele null.
function branch_info($conn, $branch) {
    $branch = trim((string)$branch);
    if ($branch === '') return null;
    st_ensure($conn);
    $st = $conn->prepare("SELECT * FROM erp_branches WHERE branch_name = ? LIMIT 1");
    $st->bind_param("s", $branch);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

// Warehouse id theke tar branch er row
function branch_of_warehouse($conn, $wid) {
    $wid = (int)$wid;
    if ($wid <= 0) return null;
    try {
        st_ensure($conn);
        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.* FROM erp_warehouses w JOIN erp_branches b ON b.id = w.branch_id WHERE w.id = $wid LIMIT 1"));
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

// Report er Branch/Warehouse filter (id) theke branch er row. Warehouse dile tar branch, na hole branch_id, duto-i na dile null (= All).
function branch_by_scope($conn, $branch_id = 0, $warehouse_id = 0) {
    if ((int)$warehouse_id > 0) return branch_of_warehouse($conn, $warehouse_id);
    $bid = (int)$branch_id;
    if ($bid <= 0) return null;
    try {
        st_ensure($conn);
        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_branches WHERE id = $bid LIMIT 1"));
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/*
 * Header er shob tothho ek jaygay. $branch = branch er naam (string) ba branch row (array) ba null.
 * Niyom: branch er naam/address/phone thakle seta, na thakle company profile er. Logo, tagline, email, BIN, footer company profile theke.
 * Return: name, tagline, address, phone, lines[] (print header er niche line gulo), footer, imgL[], imgR[], company[], branch[]|null
 */
function report_header($conn, $branch = null) {
    $co = company_info($conn);
    $br = is_array($branch) ? $branch : branch_info($conn, $branch);
    $v = function ($a, $k) { return is_array($a) ? trim((string)($a[$k] ?? '')) : ''; };

    $name  = $v($br, 'branch_name') ?: ($v($co, 'company_name') ?: 'Company');
    $addr  = $v($br, 'address')     ?: $v($co, 'address');
    $phone = $v($br, 'phone')       ?: implode(', ', array_filter([$v($co, 'phone'), $v($co, 'mobile')]));

    $lines = [];
    if ($addr !== '') $lines[] = $addr;
    $c2 = [];
    if ($phone !== '')              $c2[] = 'Mobile: ' . $phone;
    if ($v($co, 'email') !== '')    $c2[] = 'Email: ' . $v($co, 'email');
    if ($v($co, 'website') !== '')  $c2[] = $v($co, 'website');
    if ($c2) $lines[] = implode(' | ', $c2);
    $c3 = [];
    if ($v($co, 'bin_no') !== '')        $c3[] = 'BIN: ' . $v($co, 'bin_no');
    if ($v($co, 'trade_license') !== '') $c3[] = 'Trade License: ' . $v($co, 'trade_license');
    if ($c3) $lines[] = implode(' | ', $c3);

    list($imgL, $imgR) = company_images($co);
    return [
        'name' => $name, 'tagline' => $v($co, 'tagline'), 'address' => $addr, 'phone' => $phone, 'lines' => $lines,
        'footer' => $v($co, 'invoice_footer'), 'imgL' => $imgL, 'imgR' => $imgR, 'company' => $co, 'branch' => $br,
    ];
}

// Report page er <div class="print-head"> er bhitorer HTML (naam, thikana, phone, report er naam, filter line)
function report_head_html($h, $title, $sub = '') {
    $o = '<h2 style="margin:0;color:#2e7d32;">' . st_h($h['name']) . '</h2>';
    if ($h['address'] !== '') $o .= '<div style="font-size:12px;color:#555;">' . st_h($h['address']) . '</div>';
    if ($h['phone'] !== '')   $o .= '<div style="font-size:12px;color:#555;">Phone: ' . st_h($h['phone']) . '</div>';
    $o .= '<div style="font-weight:bold;margin-top:2px;">' . st_h($title) . '</div>';
    if ($sub !== '') $o .= '<div style="font-size:12px;color:#555;">' . st_h($sub) . '</div>';
    return $o;
}

function st_head($title) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . st_h($title) . '</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{font-family:"Segoe UI",Arial,sans-serif;background:#fafafa;padding:12px;margin:0;color:#333;font-size:13px}
.control-bar{background:#ececef;padding:10px;border-radius:4px;display:flex;gap:8px;margin-bottom:12px;border:1px solid #cdcd4a;align-items:flex-end;flex-wrap:wrap}
.btn-action{padding:6px 15px;font-size:13px;font-weight:bold;border:1px solid #d1d1d1;background:#fff;cursor:pointer;border-radius:4px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:#000}
.btn-green{background:#28a745;color:#fff;border-color:#218838}
.btn-red{background:#dc3545;color:#fff;border-color:#c82333}
.form-panel{background:#fff;border:1px solid #cddc39;border-radius:4px;margin-bottom:15px}
.panel-header{background:#ececef;padding:8px 15px;font-weight:bold;color:#495057;border-bottom:1px solid #ced4da;text-transform:uppercase}
.panel-body{padding:15px}
.fg{display:flex;flex-direction:column;gap:3px}
.fg label{font-size:11px;font-weight:bold;color:#444}
.form-control{height:30px;padding:0 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;box-sizing:border-box;width:100%}
textarea.form-control{height:60px;padding:6px 8px}
.excel-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 15px}
.span2{grid-column:span 2}.span3{grid-column:span 3}
.grid-table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #cddc39;background:#fff}
.grid-table th{background:#dbebe7;padding:8px;text-align:left;border:1px solid #cddc39}
.grid-table td{padding:5px 8px;border:1px solid #dee2e6}
.grid-table tr:nth-child(even) td{background:#f8fafc}
.r{text-align:right}.c{text-align:center}
.alert{padding:10px;border-radius:4px;margin-bottom:10px}.ok{background:#d4edda;color:#155724}.err{background:#f8d7da;color:#721c24}.warn{background:#fff3cd;color:#856404}
.title{font-size:15px;font-weight:bold;color:#2e7d32;margin-bottom:10px;text-transform:uppercase}
.badge{background:#d4edda;color:#155724;padding:3px 9px;border-radius:4px;font-size:12px;font-weight:bold}
.badge.off{background:#f8d7da;color:#721c24}
.selected-row td{background:#bddeb6 !important}
</style></head><body>';
}