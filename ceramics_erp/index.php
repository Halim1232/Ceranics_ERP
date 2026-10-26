<?php
include('auth.php');
include('config.php');

// সফটওয়্যারের নাম + কোম্পানির নাম (প্রতিটি tenant নিজের erp_company_profile থেকে)
if (!defined('ERP_APP_NAME')) define('ERP_APP_NAME', 'Sohoj ERP');
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
$co_name = erp_company_name($conn);

// ---- role wise menu permission ----
$role       = current_role();
$is_super   = ($role === 'superadmin');
$ceil       = pa_ceiling_ids();
$all_access = $is_super || ($role === 'admin' && $ceil === null);
$allowed = [];
if (!$all_access) {
    if ($role === 'admin') {
        $allowed = $ceil;
    } elseif ($role !== '') {
        $ps = $conn->prepare("SELECT menu_id FROM erp_role_permissions WHERE role_name = ?");
        $ps->bind_param("s", $role); $ps->execute();
        $pr = $ps->get_result();
        while ($x = $pr->fetch_assoc()) {
            $mid = (int)$x['menu_id'];
            if ($ceil === null || isset($ceil[$mid])) $allowed[$mid] = true;
        }
    }
}
$rowsAll = []; $hasKids = [];
$mr = $conn->query("SELECT * FROM erp_menus WHERE status = 'Active' ORDER BY order_no ASC");
while ($mr && $m = $mr->fetch_assoc()) {
    $rowsAll[] = $m;
    if ((int)$m['parent_id'] !== 0) $hasKids[(int)$m['parent_id']] = true;
}
$all = []; $children = [];
foreach ($rowsAll as $m) {
    $mid = (int)$m['id'];
    if ((int)$m['parent_id'] === 0) { $all[] = $m; }
    elseif ($all_access || isset($allowed[$mid])) { $children[(int)$m['parent_id']][] = $m; }
}
if (!$all_access) {
    $all = array_values(array_filter($all, function ($p) use ($hasKids, $children, $allowed) {
        $pid = (int)$p['id'];
        return isset($hasKids[$pid]) ? !empty($children[$pid]) : isset($allowed[$pid]);
    }));
}
// ---- end ----

// ---- হেডারে একটাই নাম ----
// একাধিক ব্রাঞ্চ থাকলে যে ব্রাঞ্চে লগইন করেছে তার নাম (যেমন Uttara Builders / Uttara Marble);
// একটা বা কোনো ব্রাঞ্চ না থাকলে (যেমন Nakshi Tiles) কোম্পানির নাম। সব ব্রাঞ্চ দেখার ইউজারেরও কোম্পানির নাম।
$hdr_name = $co_name;
try {
    $bc = 0;
    try { $bq = mysqli_query($conn, "SELECT COUNT(*) FROM erp_branches WHERE status = 'Active'"); $bc = (int)mysqli_fetch_row($bq)[0]; }
    catch (Throwable $e1) { $bq = mysqli_query($conn, "SELECT COUNT(*) FROM erp_branches"); $bc = (int)mysqli_fetch_row($bq)[0]; }
    if ($bc > 1) {
        // current_branch() array ফেরত দেয় (['id'=>, 'name'=>]) বা null (সব ব্রাঞ্চ)
        $cb = function_exists('current_branch') ? current_branch() : null;
        if (is_array($cb) && !empty($cb['name']) && $cb['name'] !== '__no_branch__') $hdr_name = trim((string)$cb['name']);
    }
} catch (Throwable $e) {}

// ---- দ্রুত বোতাম (যে ইউজারের মেনু অনুমতি আছে শুধু সেগুলো) ----
$quick_defs = [
    ['label' => 'Sales Invoice',    'icon' => 'fa-file-invoice-dollar', 'pat' => ['sales invoice', 'sales_invoice']],
    ['label' => 'Delivery Challan', 'icon' => 'fa-truck-fast',          'pat' => ['delivery challan', 'delivery_challan']],
    ['label' => 'Collection',       'icon' => 'fa-hand-holding-dollar', 'pat' => ['type=collection', 'collection']],
    ['label' => 'Expenses',         'icon' => 'fa-receipt',             'pat' => ['type=expense', 'expense'], 'fallback' => 'transaction/transaction.php?type=expense'],
];
$quick = []; $found = false;
foreach ($quick_defs as $qd) {
    foreach ($rowsAll as $m) {
        if ((int)$m['parent_id'] === 0) continue;
        $hay = strtolower($m['route_link'] . ' ' . $m['menu_name']);
        $hit = false;
        foreach ($qd['pat'] as $p) { if (strpos($hay, $p) !== false) { $hit = true; break; } }
        if ($hit && ($all_access || isset($allowed[(int)$m['id']]))) {
            $quick[] = ['label' => $qd['label'], 'icon' => $qd['icon'], 'tab' => 'tab_sub_' . (int)$m['id'], 'name' => $m['menu_name'], 'url' => $m['route_link']];
            $found = true;
            break;
        }
    }
    // মেনুতে এন্ট্রি না থাকলে নির্দিষ্ট URL (শুধু পুরো অ্যাক্সেসের ইউজারের জন্য)
    if (empty($found) && !empty($qd['fallback']) && $all_access) {
        $quick[] = ['label' => $qd['label'], 'icon' => $qd['icon'], 'tab' => 'tab_quick_' . strtolower($qd['label']), 'name' => $qd['label'], 'url' => $qd['fallback']];
    }
    $found = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(($co_name !== '' ? $co_name . ' | ' : '') . ERP_APP_NAME, ENT_QUOTES); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    :root {
        --green: #002a79; --green-dark: #00796b; --accent: #00796b;
        --side: #1f2d3d; --side-2: #172230; --side-hover: #2a3b4f;
        --text: #cfd8dc; --border: #2c3e50;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; }
    body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: #f4f6f9; }

    /* হেডার */
    .erp-header { background: linear-gradient(90deg, var(--green-dark), var(--green)); color: #ffffff; height: 48px; padding: 0 16px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,.2); z-index: 10; }
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; letter-spacing: .5px; font-size: 15px; }
    .toggle-btn { background: rgba(255,255,255,.15); border: none; color: #fff; width: 32px; height: 32px; border-radius: 4px; cursor: pointer; font-size: 15px; }
    .toggle-btn:hover { background: rgba(255,255,255,.28); }
    .user-info { display: flex; align-items: center; gap: 6px; font-size: 13px; }
    .user-info a { color: #fff; text-decoration: none; padding: 5px 10px; border-radius: 4px; background: rgba(255,255,255,.12); font-size: 12px; }
    .user-info a:hover { background: rgba(255,255,255,.25); }
    .user-name { margin-right: 8px; font-weight: 600; }

    /* ইউজার ড্রপডাউন */
    .user-menu { position: relative; }
    .user-btn { background: rgba(255,255,255,.12); border: none; color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 7px; }
    .user-btn:hover { background: rgba(255,255,255,.25); }
    .user-btn .caret { font-size: 10px; transition: transform .2s; }
    .user-menu.open .caret { transform: rotate(180deg); }
    .user-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 6px); min-width: 180px; background: #fff; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,.2); overflow: hidden; z-index: 100; }
    .user-menu.open .user-dropdown { display: block; }
    .user-info .user-dropdown a { display: flex; align-items: center; gap: 10px; padding: 10px 14px; color: #333; text-decoration: none; font-size: 13px; background: none; border-radius: 0; }
    .user-info .user-dropdown a:hover { background: #f1f5f9; }
    .user-dropdown a i { width: 16px; color: #2e7d32; }
    .user-dropdown a.logout i { color: #dc3545; }
    .user-dropdown .divider { height: 1px; background: #eee; }

    /* সাইডবার */
    .erp-wrapper { display: flex; flex: 1; overflow: hidden; }
    .erp-sidebar { width: 245px; background: var(--side); overflow-y: auto; overflow-x: hidden; transition: width .25s ease; flex-shrink: 0; }
    .erp-sidebar::-webkit-scrollbar { width: 6px; }
    .erp-sidebar::-webkit-scrollbar-thumb { background: #3b4f65; border-radius: 3px; }
    .erp-main-nav { list-style: none; padding: 8px 0; }
    .nav-item { margin: 2px 8px; }
    .nav-link { color: var(--text); padding: 11px 12px; display: flex; justify-content: space-between; align-items: center; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; border-radius: 6px; white-space: nowrap; transition: background .15s; }
    .nav-link:hover { background: var(--side-hover); color: #fff; }
    .nav-link .lbl { display: flex; align-items: center; overflow: hidden; }
    .nav-link i.ic { width: 22px; text-align: center; margin-right: 10px; font-size: 14px; color: #8fa3b8; }
    .nav-link:hover i.ic { color: var(--accent); }
    .nav-link.dash { background: var(--accent); color: #fff; }
    .nav-link.dash i.ic { color: #fff; }
    .nav-link.open { background: var(--side-hover); color: #fff; }
    .nav-link.open i.ic { color: var(--accent); }
    .arrow { font-size: 11px; transition: transform .25s; color: #8fa3b8; }
    .nav-link.open .arrow { transform: rotate(90deg); }

    /* সাবমেনু অ্যানিমেশন */
    .treeview-menu { list-style: none; max-height: 0; overflow: hidden; transition: max-height .3s ease; background: var(--side-2); border-radius: 0 0 6px 6px; margin-top: 2px; }
    .treeview-menu.show { max-height: 600px; }
    .treeview-menu li a { color: #aab7c4; padding: 9px 12px 9px 38px; display: flex; align-items: center; text-decoration: none; font-size: 12.5px; border-left: 3px solid transparent; white-space: nowrap; }
    .treeview-menu li a i { width: 18px; margin-right: 8px; font-size: 11px; }
    .treeview-menu li a:hover { color: #fff; background: #223245; }
    .treeview-menu li a.active { color: #fff; background: #223245; border-left-color: var(--accent); font-weight: 600; }

    /* সংকুচিত সাইডবার */
    .erp-sidebar.collapsed { width: 60px; }
    .erp-sidebar.collapsed .lbl span, .erp-sidebar.collapsed .arrow { display: none; }
    .erp-sidebar.collapsed .nav-link { justify-content: center; }
    .erp-sidebar.collapsed .nav-link i.ic { margin-right: 0; }
    .erp-sidebar.collapsed .treeview-menu { display: none; }

    /* ট্যাব বার */
    .erp-content-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
    .erp-tabs-bar { background: #e4e7eb; padding: 6px 10px 0; display: flex; gap: 3px; border-bottom: 1px solid #cfd4da; overflow-x: auto; white-space: nowrap; }
    .erp-tabs-bar::-webkit-scrollbar { height: 4px; }
    .tab-btn { background: #d3d8de; border: 1px solid #c1c7ce; border-bottom: none; padding: 7px 14px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-radius: 6px 6px 0 0; color: #455; flex-shrink: 0; }
    .tab-btn:hover { background: #e9ecef; }
    .tab-btn.active { background: #fff; font-weight: 700; color: #222; border-top: 3px solid var(--green); }
    .tab-close { color: #999; font-size: 15px; line-height: 1; border-radius: 50%; width: 16px; height: 16px; text-align: center; }
    .tab-close:hover { background: #dc3545; color: #fff; }
    .tab-content-container { flex: 1; overflow: hidden; position: relative; }
    .tab-iframe { width: 100%; height: 100%; border: none; display: none; background: #fff; }
    .tab-iframe.active { display: block; }

    /* ড্যাশবোর্ড */
    .dashboard-view { padding: 22px; overflow-y: auto; height: 100%; }
    .db-title { font-size: 20px; font-weight: 700; color: #263238; }
    .db-subtitle { font-size: 13px; color: #78909c; margin: 4px 0 18px; }
    .db-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .db-card { background: #fff; padding: 18px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: center; gap: 14px; border-left: 4px solid #ccc; }
    .db-card .ico { width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 19px; color: #fff; }
    .card-label { font-size: 11px; font-weight: 700; color: #78909c; text-transform: uppercase; letter-spacing: .4px; }
    .card-value { font-size: 20px; font-weight: 700; color: #263238; margin-top: 3px; }

    /* হেডার: বাঁয়ে সফটওয়্যার (সাইডবারের সমান জায়গা) + কোম্পানি/ব্রাঞ্চ, ডানে দ্রুত বোতাম + প্রোফাইল */
    .erp-header { padding: 0 16px 0 0; }
    .brand-side { width: 230px; flex-shrink: 0; display: flex; align-items: center; gap: 10px; padding-left: 16px; font-weight: 800; letter-spacing: .5px; font-size: 22px; white-space: nowrap; }
    .brand-co { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; padding-left: 14px; font-size: 14px; white-space: nowrap; overflow: hidden; }
    .brand-co .co { font-weight: 600; font-size: 15px; overflow: hidden; text-overflow: ellipsis; border-left: 1px solid rgba(255,255,255,.5); padding-left: 12px; }
    .quick { display: flex; align-items: center; gap: 6px; margin-right: 12px; }
    .qk { display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,.14); color: #fff; border: 1px solid rgba(255,255,255,.28); border-radius: 5px; padding: 6px 11px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .qk:hover { background: rgba(255,255,255,.3); }
    .qk i { font-size: 13px; }
    @media (max-width: 1250px) { .qk span { display: none; } .qk { padding: 7px 10px; } }
    @media (max-width: 800px) { .brand-co .co { display: none; } }
</style>

</head>
<body>
    <div class="erp-header">
    <div class="brand-side">
        <button class="toggle-btn" onclick="toggleSidebar()" title="মেনু ছোট/বড়"><i class="fa fa-bars"></i></button>
        <img src="assets/sohojerp_logo.svg" alt="SohojERP" style="height:55px;vertical-align:middle;margin-right:5px;">
    </div>
    <div class="brand-co">
        <?php if ($hdr_name !== '') { ?><span class="co"><?php echo htmlspecialchars($hdr_name, ENT_QUOTES); ?></span><?php } ?>
    </div>
    <?php if ($quick) { ?>
    <div class="quick">
        <?php foreach ($quick as $qk) { ?>
        <button type="button" class="qk" title="<?php echo htmlspecialchars($qk['label'], ENT_QUOTES); ?>"
                data-qtab="<?php echo htmlspecialchars($qk['tab'], ENT_QUOTES); ?>"
                data-name="<?php echo htmlspecialchars($qk['name'], ENT_QUOTES); ?>"
                data-url="<?php echo htmlspecialchars($qk['url'], ENT_QUOTES); ?>"
                onclick="openTab(this.dataset.qtab, this.dataset.name, this.dataset.url)">
            <i class="fa <?php echo htmlspecialchars($qk['icon'], ENT_QUOTES); ?>"></i><span><?php echo htmlspecialchars($qk['label'], ENT_QUOTES); ?></span>
        </button>
        <?php } ?>
    </div>
    <?php } ?>
    <div class="user-info">
        <div class="user-menu" id="userMenu">
            <button type="button" class="user-btn" onclick="toggleUserMenu(event)">
                <i class="fa fa-user-circle"></i>
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                <i class="fa fa-chevron-down caret"></i>
            </button>
            <div class="user-dropdown">
                <a href="javascript:void(0);" onclick="openTab('tab_pass', 'Change Password', 'change_password.php'); closeUserMenu();">
                    <i class="fa fa-key"></i> পাসওয়ার্ড বদলান
                </a>
                <div class="divider"></div>
                <a href="logout.php" class="logout"><i class="fa fa-sign-out-alt"></i> লগআউট</a>
            </div>
        </div>
    </div>
</div>

<div class="erp-wrapper">
    <div class="erp-sidebar" id="sidebar">
        <ul class="erp-main-nav">
            <li class="nav-item">
                <a href="javascript:void(0);" onclick="switchToDashboard()" class="nav-link dash">
                    <span class="lbl"><i class="fa fa-tachometer-alt ic"></i><span>Dashboard</span></span>
                </a>
            </li>
            <?php
            

            foreach ($all as $parent) {
                $pid   = (int)$parent['id'];
                $pname = htmlspecialchars($parent['menu_name']);
                $picon = htmlspecialchars($parent['icon'] ?: 'fa-folder');
                if (!empty($children[$pid])) {
                    echo '<li class="nav-item">';
                    echo '<a href="javascript:void(0);" class="nav-link toggle-submenu"><span class="lbl"><i class="fa ' . $picon . ' ic"></i><span>' . $pname . '</span></span><i class="fa fa-chevron-right arrow"></i></a>';
                    echo '<ul class="treeview-menu">';
                    foreach ($children[$pid] as $sub) {
                        $sname = htmlspecialchars($sub['menu_name'], ENT_QUOTES);
                        $surl  = htmlspecialchars($sub['route_link'], ENT_QUOTES);
                        $suniq = 'tab_sub_' . (int)$sub['id'];
                        $sicon = htmlspecialchars($sub['icon'] ?: 'fa-circle');
                        echo '<li><a href="javascript:void(0);" data-tab="' . $suniq . '" onclick="openTab(\'' . $suniq . '\', \'' . $sname . '\', \'' . $surl . '\')"><i class="fa ' . $sicon . '"></i> ' . $sname . '</a></li>';
                    }
                    echo '</ul></li>';
                } else {
                    $uniq = 'tab_par_' . $pid;
                    $url  = htmlspecialchars($parent['route_link'], ENT_QUOTES);
                    echo '<li class="nav-item"><a href="javascript:void(0);" data-tab="' . $uniq . '" onclick="openTab(\'' . $uniq . '\', \'' . $pname . '\', \'' . $url . '\')" class="nav-link"><span class="lbl"><i class="fa ' . $picon . ' ic"></i><span>' . $pname . '</span></span></a></li>';
                }
            }
            ?>
        </ul>
    </div>
        <style>
            .erp-content-area { flex: 1; display: flex; flex-direction: column; background: #f4f6f9; overflow: hidden; }
            .erp-tabs-bar { background: #e0e0e0; padding: 5px 10px 0 10px; display: flex; gap: 4px; border-bottom: 1px solid #cccccc; }
            .tab-btn { background: #d6d6d6; border: 1px solid #b5b5b5; border-bottom: none; padding: 6px 12px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px; border-radius: 3px 3px 0 0; }
            .tab-btn.active { background: #ffffff; font-weight: bold; border-top: 2px solid #2e7d32; }
            .tab-close { color: #888; font-weight: bold; cursor: pointer; }
            .tab-content-container { flex: 1; overflow: hidden; position: relative; }
            .tab-iframe { width: 100%; height: 100%; border: none; display: none; }
            .tab-iframe.active { display: block; }
            .dashboard-view { padding: 20px; overflow-y: auto; height: 100%; }
            .db-title { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 5px; }
            .db-subtitle { font-size: 12px; color: #666; margin-bottom: 15px; }
            .db-cards { display: flex; gap: 15px; }
            .db-card { flex: 1; background: white; padding: 15px; border-left: 4px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 2px; }
            .card-label { font-size: 11px; font-weight: bold; color: #777; margin-bottom: 5px; }
            .card-value { font-size: 18px; font-weight: bold; color: #222; }
        </style>
        <div class="erp-content-area">
            <div class="erp-tabs-bar" id="tabsBar">
                <button class="tab-btn active" id="btn_dashboard" onclick="switchToDashboard()">Dashboard</button>
            </div>
            <div class="tab-content-container" id="tabContentContainer">
                <div class="dashboard-view" id="content_dashboard" style="padding:0; overflow:hidden;">
    <iframe src="dashboard.php" style="width:100%; height:100%; border:none; display:block;"></iframe>
</div>
            </div>
        </div>
    </div>
    <script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function toggleUserMenu(e) {
    e.stopPropagation();
    document.getElementById('userMenu').classList.toggle('open');
}
function closeUserMenu() {
    document.getElementById('userMenu').classList.remove('open');
}
// বাইরে ক্লিক করলে মেনু বন্ধ
document.addEventListener('click', function(e) {
    var m = document.getElementById('userMenu');
    if (m && !m.contains(e.target)) m.classList.remove('open');
});
// ট্যাবের iframe-এর ভেতরে ক্লিক করলেও মেনু বন্ধ
window.addEventListener('blur', function() {
    if (document.activeElement && document.activeElement.tagName === 'IFRAME') closeUserMenu();
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.toggle-submenu').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('collapsed')) sidebar.classList.remove('collapsed');
            var menu = this.nextElementSibling, willOpen = !menu.classList.contains('show');
            // অ্যাকর্ডিয়ন: অন্যগুলো বন্ধ
            document.querySelectorAll('.treeview-menu.show').forEach(function(m) { m.classList.remove('show'); m.previousElementSibling.classList.remove('open'); });
            if (willOpen) { menu.classList.add('show'); this.classList.add('open'); }
        });
    });
});

function setActiveMenu(tabId) {
    document.querySelectorAll('.treeview-menu a, .nav-link[data-tab]').forEach(function(a) { a.classList.remove('active'); });
    var cur = document.querySelector('[data-tab="' + tabId + '"]');
    if (cur) {
        cur.classList.add('active');
        var ul = cur.closest('.treeview-menu');
        if (ul) { ul.classList.add('show'); ul.previousElementSibling.classList.add('open'); }
    }
}

function clearTabs() {
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-iframe').forEach(function(f) { f.classList.remove('active'); });
}

function switchToDashboard() {
    clearTabs();
    document.getElementById('btn_dashboard').classList.add('active');
    document.getElementById('content_dashboard').style.display = 'block';
    setActiveMenu('');
}

function activateTab(tabId) {
    document.getElementById('content_dashboard').style.display = 'none';
    clearTabs();
    document.getElementById('btn_' + tabId).classList.add('active');
    document.getElementById('iframe_' + tabId).classList.add('active');
    setActiveMenu(tabId);
}

function openTab(tabId, tabName, url) {
    if (document.getElementById('btn_' + tabId)) { activateTab(tabId); return; }

    var btn = document.createElement('button');
    btn.className = 'tab-btn';
    btn.id = 'btn_' + tabId;
    btn.innerHTML = '<span>' + tabName + '</span> <span class="tab-close" onclick="closeTab(event, \'' + tabId + '\')">&times;</span>';
    btn.addEventListener('click', function(e) {
        if (e.target.classList.contains('tab-close')) return;
        activateTab(tabId);
    });
    document.getElementById('tabsBar').appendChild(btn);

    var frame = document.createElement('iframe');
    frame.className = 'tab-iframe';
    frame.id = 'iframe_' + tabId;
    frame.src = url;
    document.getElementById('tabContentContainer').appendChild(frame);

    activateTab(tabId);
    btn.scrollIntoView({ inline: 'nearest' });
}

function closeTab(event, tabId) {
    event.stopPropagation();
    var btn = document.getElementById('btn_' + tabId), frame = document.getElementById('iframe_' + tabId);
    var wasActive = btn.classList.contains('active');
    btn.remove(); frame.remove();
    if (wasActive) {
        // বন্ধ করা ট্যাবের আগের ট্যাবে ফিরে যাওয়া
        var tabs = document.querySelectorAll('.tab-btn');
        var last = tabs[tabs.length - 1];
        if (last && last.id !== 'btn_dashboard') activateTab(last.id.replace('btn_', ''));
        else switchToDashboard();
    }
}
var stTimer;
function stockSearch() {
    clearTimeout(stTimer);                       // প্রতিটি কী-তে রিকোয়েস্ট না পাঠিয়ে ৩০০ms থামলে পাঠানো
    stTimer = setTimeout(function () {
        var q = document.getElementById('stockQ').value.trim();
        var box = document.getElementById('stockResult');
        if (q.length < 2) { box.innerHTML = ''; return; }
        fetch('ajax_stock_search.php?q=' + encodeURIComponent(q))
          .then(r => r.json()).then(list => {
            if (!list.length) { box.innerHTML = '<div style="padding:10px;color:#999">কিছু পাওয়া যায়নি</div>'; return; }
            box.innerHTML = list.map(function (p) {
                var cls = p.negative ? 'color:#d32f2f;font-weight:700;' : 'color:#2e7d32;font-weight:700;';
                var tag = p.negative ? ' <small>(শর্টেজ)</small>' : '';
                return '<div style="display:flex;justify-content:space-between;padding:8px 10px;border-bottom:1px solid #eee">'
                     + '<span><b>' + esc(p.name) + '</b> <small>' + esc(p.size || '') + ' ' + esc(p.color || '') + '</small></span>'
                     + '<span style="' + cls + '">' + p.sft + ' SFT | ' + p.box + ' Box ' + p.pcs + ' Pcs' + tag + '</span></div>';
            }).join('');
        });
    }, 300);
}
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
</script>
</body>
</html>