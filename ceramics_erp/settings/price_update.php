<?php
// price_update.php -- Product Price Update (cost_price / sale_price) + change log
include('../auth.php');
$PAGE_KEY = 'Settings/price_update.php';   // erp_menus.route_link er sathe hubohu mile thakte hobe
require_page_access($PAGE_KEY);
include('../config.php');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$me = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? ('user#' . ($_SESSION['user_id'] ?? '?'));

// ===== Log table (first run e auto toiri hobe) =====
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_price_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    item_code VARCHAR(50) DEFAULT NULL,
    item_name VARCHAR(150) DEFAULT NULL,
    field_name VARCHAR(20) NOT NULL,
    old_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    new_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    changed_by VARCHAR(100) DEFAULT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_prod (product_id),
    KEY idx_date (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (empty($_SESSION['pu_csrf'])) { $_SESSION['pu_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['pu_csrf'];

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
$fields = ['cost_price' => 'Cost Price', 'sale_price' => 'Sale Price'];

// ===== Filters =====
$q     = trim($_GET['q'] ?? '');
$grp   = trim($_GET['group'] ?? '');
$cat   = trim($_GET['category'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$size  = trim($_GET['size'] ?? '');
$tab   = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'edit';
$qs    = http_build_query(['q' => $q, 'group' => $grp, 'category' => $cat, 'brand' => $brand, 'size' => $size]);

// ===== Save =====
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { die('Invalid request token.'); }
    $rows = $_POST['price'] ?? [];
    $changed_rows = 0; $changed_fields = 0;

    mysqli_begin_transaction($conn);
    try {
        $sel = mysqli_prepare($conn, "SELECT item_code, item_name, cost_price, sale_price FROM erp_products WHERE id=?");
        $log = mysqli_prepare($conn, "INSERT INTO erp_price_log (product_id,item_code,item_name,field_name,old_value,new_value,changed_by) VALUES (?,?,?,?,?,?,?)");

        foreach ($rows as $pid => $vals) {
            $pid = (int)$pid;
            if ($pid <= 0 || !is_array($vals)) continue;
            mysqli_stmt_bind_param($sel, 'i', $pid);
            mysqli_stmt_execute($sel);
            $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
            if (!$cur) continue;

            $set = []; $logs = [];
            foreach ($fields as $f => $lbl) {
                if (!isset($vals[$f]) || trim($vals[$f]) === '') continue;
                $raw = str_replace(',', '', trim($vals[$f]));
                if (!is_numeric($raw) || (float)$raw < 0) throw new Exception("Invalid $lbl for " . $cur['item_name']);
                $new = round((float)$raw, 2);
                $old = round((float)$cur[$f], 2);
                if ($new != $old) { $set[$f] = $new; $logs[] = [$f, $old, $new]; }
            }
            if (!$set) continue;

            $sql = "UPDATE erp_products SET " . implode(',', array_map(fn($f) => "$f=" . number_format($set[$f], 2, '.', ''), array_keys($set))) . " WHERE id=$pid";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

            foreach ($logs as [$f, $old, $new]) {
                mysqli_stmt_bind_param($log, 'isssdds', $pid, $cur['item_code'], $cur['item_name'], $f, $old, $new, $me);
                mysqli_stmt_execute($log);
                $changed_fields++;
            }
            $changed_rows++;
        }
        mysqli_commit($conn);
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?' . $qs . '&saved=' . $changed_rows . '-' . $changed_fields);
        exit;
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        $flash = '<div class="alert err">Save hoyni: ' . e($ex->getMessage()) . '</div>';
    }
}
if (isset($_GET['saved'])) {
    [$r, $fl] = array_pad(explode('-', $_GET['saved']), 2, 0);
    $flash = ((int)$fl > 0)
        ? '<div class="alert ok">✔ ' . (int)$r . ' ti product er ' . (int)$fl . ' ti price update hoyeche.</div>'
        : '<div class="alert info">Kono price poriborton hoyni.</div>';
}

// ===== Dropdown data =====
$groups = []; $cats = []; $brands = []; $sizes = [];
$r = mysqli_query($conn, "SELECT DISTINCT group_name FROM erp_products WHERE group_name IS NOT NULL AND group_name<>'' ORDER BY group_name");
while ($r && $x = mysqli_fetch_row($r)) $groups[] = $x[0];
$r = mysqli_query($conn, "SELECT DISTINCT category_name FROM erp_products WHERE category_name IS NOT NULL AND category_name<>'' ORDER BY category_name");
while ($r && $x = mysqli_fetch_row($r)) $cats[] = $x[0];
$r = mysqli_query($conn, "SELECT DISTINCT brand_name FROM erp_products WHERE brand_name IS NOT NULL AND brand_name<>'' ORDER BY brand_name");
while ($r && $x = mysqli_fetch_row($r)) $brands[] = $x[0];
$r = mysqli_query($conn, "SELECT DISTINCT size_name FROM erp_products WHERE size_name IS NOT NULL AND size_name<>'' ORDER BY size_name");
while ($r && $x = mysqli_fetch_row($r)) $sizes[] = $x[0];

// ===== Product list =====
$where = ['1=1'];
if ($q !== '') {
    $qe = mysqli_real_escape_string($conn, $q);
    $where[] = "(item_name LIKE '%$qe%' OR item_code LIKE '%$qe%' OR size_name LIKE '%$qe%' OR color_name LIKE '%$qe%')";
}
if ($grp !== '') $where[] = "group_name='" . mysqli_real_escape_string($conn, $grp) . "'";
if ($cat !== '') $where[] = "category_name='" . mysqli_real_escape_string($conn, $cat) . "'";
if ($brand !== '') $where[] = "brand_name='" . mysqli_real_escape_string($conn, $brand) . "'";
if ($size !== '') $where[] = "size_name='" . mysqli_real_escape_string($conn, $size) . "'";
$products = mysqli_query($conn, "SELECT id,item_code,item_name,size_name,color_name,unit_name,cost_price,sale_price
                                 FROM erp_products WHERE " . implode(' AND ', $where) . " ORDER BY item_name, size_name, color_name");
$total_rows = $products ? mysqli_num_rows($products) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Price Update</title>
<style>
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #333; background: #f1f4f9; margin: 0; padding: 10px 14px; }
    .wrap { width: 100%; max-width: none; margin: 0; min-height: calc(100vh - 20px); display: flex; flex-direction: column; }
    .card { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    #pf { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    h2 { margin: 0 0 4px; color: #2b579a; font-size: 22px; }
    .sub { color: #666; margin-bottom: 14px; }
    .tabs a { display: inline-block; padding: 7px 16px; text-decoration: none; color: #2b579a; border: 1px solid #94a3b8; border-bottom: none; border-radius: 4px 4px 0 0; background: #e2e8f0; font-weight: bold; }
    .tabs a.on { background: #2b579a; color: #fff; border-color: #2b579a; }
    .card { background: #fff; border: 1px solid #cbd5e1; border-radius: 0 4px 4px 4px; padding: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 12px; }
    .bar label { display: block; font-size: 11px; font-weight: bold; color: #555; margin-bottom: 2px; }
    input[type=text], input[type=number], select { padding: 6px 8px; border: 1px solid #94a3b8; border-radius: 3px; font-size: 13px; background: #fff; }
    .btn { background: #2b579a; color: #fff; border: none; padding: 7px 16px; font-weight: bold; border-radius: 3px; cursor: pointer; font-size: 13px; }
    .btn.green { background: #2e7d32; }
    .btn.gray { background: #64748b; }
    .btn:disabled { opacity: .5; cursor: not-allowed; }
    .bulk { background: #f8fafc; border: 1px dashed #94a3b8; border-radius: 4px; padding: 10px; margin-bottom: 12px; }
    .bulk b { color: #2b579a; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #cbd5e1; border: 1px solid #94a3b8; padding: 6px; text-align: left; position: sticky; top: 0; z-index: 2; }
    table.grid td { border: 1px solid #cbd5e1; padding: 4px 6px; }
    table.grid tr:hover td { background: #f8fafc; }
    .num { text-align: right; }
    td.pin input { width: 100%; text-align: right; padding: 5px 6px; }
    td.pin input.chg { background: #fff7d6; border-color: #e0a800; font-weight: bold; }
    tr.rowchg td.nm { border-left: 4px solid #e0a800; }
    .old { display: block; font-size: 10.5px; color: #888; text-align: right; }
    .alert { padding: 9px 12px; border-radius: 4px; margin-bottom: 12px; font-weight: bold; }
    .alert.ok { background: #e6f4ea; color: #1e6b30; border: 1px solid #a8d5b5; }
    .alert.err { background: #fde8e8; color: #b42318; border: 1px solid #f5b5b5; }
    .alert.info { background: #e8f0fe; color: #2b579a; border: 1px solid #b6c8ee; }
    .savebar { background: #fff; border-top: 2px solid #2b579a; padding: 10px 14px; margin: 10px -14px -14px; display: flex; justify-content: space-between; align-items: center; }
    .scroll { flex: 1; min-height: 300px; max-height: calc(100vh - 330px); overflow: auto; }
    .up { color: #b42318; font-weight: bold; } .down { color: #1e6b30; font-weight: bold; }
</style>
</head>
<body>
<div class="wrap">
    <h2>Price Update</h2>
    <div class="sub">Product er Cost Price ebong Sale Price ek jaygay theke update korun. Prottek poriborton log e save thake.</div>

    <div class="tabs">
        <a href="?<?php echo e($qs); ?>" class="<?php echo $tab === 'edit' ? 'on' : ''; ?>">Update Prices</a>
        <a href="?tab=log" class="<?php echo $tab === 'log' ? 'on' : ''; ?>">Price Change Log</a>
    </div>

    <div class="card">
    <?php echo $flash; ?>

<?php if ($tab === 'log'): ?>
    <?php
    $lq = mysqli_query($conn, "SELECT * FROM erp_price_log ORDER BY id DESC LIMIT 300");
    ?>
    <div class="scroll">
    <table class="grid">
        <thead><tr><th>Date / Time</th><th>Code</th><th>Product</th><th>Field</th><th class="num">Old</th><th class="num">New</th><th class="num">Change</th><th>By</th></tr></thead>
        <tbody>
        <?php if ($lq && mysqli_num_rows($lq)): while ($l = mysqli_fetch_assoc($lq)):
            $diff = $l['new_value'] - $l['old_value']; ?>
            <tr>
                <td><?php echo e(date('d-M-Y h:i A', strtotime($l['changed_at']))); ?></td>
                <td><?php echo e($l['item_code']); ?></td>
                <td><b><?php echo e($l['item_name']); ?></b></td>
                <td><?php echo e($fields[$l['field_name']] ?? $l['field_name']); ?></td>
                <td class="num"><?php echo number_format($l['old_value'], 2); ?></td>
                <td class="num"><b><?php echo number_format($l['new_value'], 2); ?></b></td>
                <td class="num <?php echo $diff > 0 ? 'up' : 'down'; ?>"><?php echo ($diff > 0 ? '+' : '') . number_format($diff, 2); ?></td>
                <td><?php echo e($l['changed_by']); ?></td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="8" style="text-align:center; color:#888; padding:14px;">Ekhono kono price poriborton log nei.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

<?php else: ?>

    <!-- ===== Filter ===== -->
    <form method="get" class="bar">
        <div><label>Search (Name / Code / Size / Color)</label><input type="text" name="q" value="<?php echo e($q); ?>" style="width:260px;" placeholder="Search..."></div>
        <div><label>Group</label>
            <select name="group"><option value="">All</option>
            <?php foreach ($groups as $g): ?><option value="<?php echo e($g); ?>" <?php echo $g === $grp ? 'selected' : ''; ?>><?php echo e($g); ?></option><?php endforeach; ?></select></div>
        <div><label>Category</label>
            <select name="category"><option value="">All</option>
            <?php foreach ($cats as $c): ?><option value="<?php echo e($c); ?>" <?php echo $c === $cat ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?></select></div>
        <div><label>Brand</label>
            <select name="brand"><option value="">All</option>
            <?php foreach ($brands as $b): ?><option value="<?php echo e($b); ?>" <?php echo $b === $brand ? 'selected' : ''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select></div>
        <div><label>Size</label>
            <select name="size"><option value="">All</option>
            <?php foreach ($sizes as $z): ?><option value="<?php echo e($z); ?>" <?php echo $z === $size ? 'selected' : ''; ?>><?php echo e($z); ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit">Filter</button>
        <a class="btn gray" style="text-decoration:none;" href="<?php echo e(basename($_SERVER['PHP_SELF'])); ?>">Reset</a>
        <div style="margin-left:auto; color:#555;"><b><?php echo $total_rows; ?></b> ti product</div>
    </form>

    <!-- ===== Bulk adjust (sudhu screen e dekha products e) ===== -->
    <div class="bulk">
        <b>Bulk Adjust</b> (ei list e jegulo dekha jachche shudhu segulo te):
        <select id="bkField">
            <option value="all">Sob price</option>
            <?php foreach ($fields as $f => $lbl): ?><option value="<?php echo $f; ?>"><?php echo $lbl; ?></option><?php endforeach; ?>
        </select>
        <select id="bkMode">
            <option value="pct">Percent (%)</option>
            <option value="amt">Fixed Amount (Tk)</option>
        </select>
        <input type="number" id="bkVal" step="0.01" placeholder="+5 ba -5" style="width:110px;">
        <button type="button" class="btn" onclick="bulkApply()">Apply</button>
        <button type="button" class="btn gray" onclick="resetAll()">Undo All Changes</button>
        <span style="color:#888;">Apply korle shudhu input e boshbe, Save na kora porjonto database e jabe na.</span>
    </div>

    <form method="post" id="pf" onsubmit="return confirmSave();">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

        <div class="scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th style="width:36px;">SL</th>
                    <th style="width:100px;">Code</th>
                    <th>Product</th>
                    <th style="width:80px;">Size</th>
                    <th style="width:70px;">Color</th>
                    <th style="width:50px;">Unit</th>
                    <?php foreach ($fields as $f => $lbl): ?><th class="num" style="width:120px;"><?php echo $lbl; ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php $sl = 1; if ($total_rows): while ($p = mysqli_fetch_assoc($products)): ?>
                <tr data-id="<?php echo (int)$p['id']; ?>">
                    <td><?php echo $sl++; ?></td>
                    <td><?php echo e($p['item_code']); ?></td>
                    <td class="nm"><b><?php echo e($p['item_name']); ?></b></td>
                    <td><?php echo e($p['size_name'] ?? '-'); ?></td>
                    <td><?php echo e($p['color_name'] ?? '-'); ?></td>
                    <td><?php echo e($p['unit_name'] ?? '-'); ?></td>
                    <?php foreach ($fields as $f => $lbl): $v = number_format((float)$p[$f], 2, '.', ''); ?>
                    <td class="pin">
                        <input type="number" step="0.01" min="0"
                               name="price[<?php echo (int)$p['id']; ?>][<?php echo $f; ?>]"
                               value="<?php echo $v; ?>" data-orig="<?php echo $v; ?>" data-field="<?php echo $f; ?>"
                               data-name="<?php echo e($p['item_name']); ?>">
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align:center; color:#888; padding:14px;">Kono product pawa jayni.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="savebar">
            <div><b id="chgCount">0</b> ti price poriborton hoyeche (<b id="rowCount">0</b> ti product)</div>
            <button type="submit" class="btn green" id="saveBtn" disabled>💾 Save Price Changes</button>
        </div>
    </form>

<?php endif; ?>
    </div>
</div>

<script>
(function () {
    var inputs = document.querySelectorAll('td.pin input');
    function mark(inp) {
        var changed = parseFloat(inp.value || 0).toFixed(2) !== inp.dataset.orig;
        inp.classList.toggle('chg', changed);
        inp.closest('tr').classList.toggle('rowchg', inp.closest('tr').querySelector('input.chg') !== null);
    }
    function refresh() {
        var n = document.querySelectorAll('td.pin input.chg').length;
        var r = document.querySelectorAll('tr.rowchg').length;
        var c = document.getElementById('chgCount'); if (!c) return;
        c.textContent = n; document.getElementById('rowCount').textContent = r;
        document.getElementById('saveBtn').disabled = (n === 0);
    }
    inputs.forEach(function (i) {
        i.addEventListener('input', function () { mark(i); refresh(); });
        // click / Tab korle ghorer sob number select hoye jabe
        var justFocused = false;
        i.addEventListener('focus', function () { justFocused = true; i.select(); });
        i.addEventListener('mouseup', function (e) { if (justFocused) { e.preventDefault(); justFocused = false; } });
        // Enter chaple ager mot form submit na hoye nicher row er ek-i column e jabe
        i.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var tr = i.closest('tr').nextElementSibling;
            while (tr && !tr.querySelector('input[data-field="' + i.dataset.field + '"]')) tr = tr.nextElementSibling;
            if (tr) tr.querySelector('input[data-field="' + i.dataset.field + '"]').focus();
        });
    });

    window.bulkApply = function () {
        var val = parseFloat(document.getElementById('bkVal').value);
        if (isNaN(val)) { alert('Percent ba amount likhun (jemon 5 ba -5).'); return; }
        var f = document.getElementById('bkField').value, mode = document.getElementById('bkMode').value;
        inputs.forEach(function (i) {
            if (f !== 'all' && i.dataset.field !== f) return;
            var cur = parseFloat(i.value || 0);
            var nv = mode === 'pct' ? cur + cur * val / 100 : cur + val;
            if (nv < 0) nv = 0;
            i.value = nv.toFixed(2); mark(i);
        });
        refresh();
    };
    window.resetAll = function () {
        inputs.forEach(function (i) { i.value = i.dataset.orig; mark(i); });
        refresh();
    };
    window.confirmSave = function () {
        var n = document.querySelectorAll('td.pin input.chg').length;
        return n > 0 && confirm(n + ' ti price poriborton save korben?');
    };
})();
</script>
</body>
</html>