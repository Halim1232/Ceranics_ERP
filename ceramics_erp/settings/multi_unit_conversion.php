<?php
// Settings/multi_unit_conversion.php -- Multi-Unit Conversion Setup (shudhu tiles / compound product er jonno)
//   1 Box = N Pcs            -> erp_products.box_to_pcs
//   M Pcs = K SFT (round)    -> erp_products.ratio_pcs / ratio_output
//   1 Box = X FM             -> erp_products.fm_per_box   (Factory Measurement, shudhu Purchase e)
// Stock shob shomoy SFT te. Baki unit (Box/Pcs/FM) SFT theke auto hishab hoy.
include('../auth.php');
require_page_access('Settings/multi_unit_conversion.php');
include('../config.php');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// fm_per_box column na thakle toiri kore nei
try {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM erp_products LIKE 'fm_per_box'");
    if ($c && mysqli_num_rows($c) == 0) mysqli_query($conn, "ALTER TABLE erp_products ADD COLUMN fm_per_box DECIMAL(10,4) NOT NULL DEFAULT 0");
} catch (Throwable $e) { error_log('fm_per_box: ' . $e->getMessage()); }

if (empty($_SESSION['mu_csrf'])) { $_SESSION['mu_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['mu_csrf'];

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
function nf($v, $min = 2, $max = 4) {   // 16.1400 -> 16.14 , 16.1234 -> 16.1234
    $s = number_format((float)$v, $max, '.', '');
    $s = rtrim($s, '0');
    $dot = strpos($s, '.');
    $dec = $dot === false ? 0 : strlen($s) - $dot - 1;
    if ($dec < $min) $s = number_format((float)$v, $min, '.', '');
    return $s;
}

// ===== Filters =====
$q        = trim($_GET['q'] ?? '');
$f_size   = trim($_GET['size'] ?? '');
$show_all = !empty($_GET['all']);
$only_inc = !empty($_GET['inc']);
$qs = http_build_query(['q' => $q, 'size' => $f_size, 'all' => $show_all ? 1 : 0, 'inc' => $only_inc ? 1 : 0]);

// ===== Save =====
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { die('Invalid request token.'); }
    $rows = $_POST['mu'] ?? [];
    $changed = 0;
    mysqli_begin_transaction($conn);
    try {
        $sel = mysqli_prepare($conn, "SELECT item_name, box_to_pcs, ratio_pcs, ratio_output, fm_per_box FROM erp_products WHERE id=?");
        $upd = mysqli_prepare($conn, "UPDATE erp_products SET box_to_pcs=?, ratio_pcs=?, ratio_output=?, fm_per_box=? WHERE id=?");
        foreach ($rows as $pid => $v) {
            $pid = (int)$pid;
            if ($pid <= 0 || !is_array($v)) continue;
            mysqli_stmt_bind_param($sel, 'i', $pid);
            mysqli_stmt_execute($sel);
            $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
            if (!$cur) continue;

            $box = round((float)($v['box_to_pcs'] ?? 0), 2);
            $rp  = round((float)($v['ratio_pcs'] ?? 0), 2);
            $ro  = round((float)($v['ratio_output'] ?? 0), 2);
            $fm  = round((float)($v['fm_per_box'] ?? 0), 4);
            if ($box <= 0 || $rp <= 0 || $ro <= 0) throw new Exception($cur['item_name'] . ': Pcs per Box, Pcs ebong SFT 0 er cheye boro hote hobe.');
            if ($fm < 0) throw new Exception($cur['item_name'] . ': FM per Box minus hote pare na.');

            if ($box == round((float)$cur['box_to_pcs'], 2) && $rp == round((float)$cur['ratio_pcs'], 2)
                && $ro == round((float)$cur['ratio_output'], 2) && $fm == round((float)$cur['fm_per_box'], 4)) continue;

            mysqli_stmt_bind_param($upd, 'ddddi', $box, $rp, $ro, $fm, $pid);
            mysqli_stmt_execute($upd);
            $changed++;
        }
        mysqli_commit($conn);
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?' . $qs . '&saved=' . $changed);
        exit;
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        $flash = '<div class="alert err">Save hoyni: ' . e($ex->getMessage()) . '</div>';
    }
}
if (isset($_GET['saved'])) {
    $n = (int)$_GET['saved'];
    $flash = $n > 0 ? '<div class="alert ok">✔ ' . $n . ' ti product er unit setup save hoyeche.</div>'
                    : '<div class="alert info">Kono poriborton hoyni.</div>';
}

// ===== Data =====
$sizes = [];
$r = mysqli_query($conn, "SELECT DISTINCT size_name FROM erp_products WHERE size_name IS NOT NULL AND size_name<>'' ORDER BY size_name");
while ($r && $x = mysqli_fetch_row($r)) $sizes[] = $x[0];

$where = ['1=1'];
if (!$show_all) $where[] = 'p.is_compound = 1';
if ($q !== '') {
    $qe = mysqli_real_escape_string($conn, $q);
    $where[] = "(p.item_name LIKE '%$qe%' OR p.item_code LIKE '%$qe%' OR p.color_name LIKE '%$qe%')";
}
if ($f_size !== '') $where[] = "p.size_name='" . mysqli_real_escape_string($conn, $f_size) . "'";
if ($only_inc) $where[] = '(p.fm_per_box <= 0 OR p.box_to_pcs <= 0 OR p.ratio_pcs <= 0 OR p.ratio_output <= 0)';

$res = mysqli_query($conn, "SELECT p.id, p.item_code, p.item_name, p.size_name, p.color_name, p.unit_name, p.is_compound,
        p.box_to_pcs, p.ratio_pcs, p.ratio_output, p.fm_per_box,
        EXISTS(SELECT 1 FROM erp_stock_ledger l WHERE l.product_id = p.id) AS has_tx
    FROM erp_products p WHERE " . implode(' AND ', $where) . " ORDER BY p.size_name, p.item_name, p.color_name");
$total = $res ? mysqli_num_rows($res) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Multi-Unit Conversion Setup</title>
<style>
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #333; background: #f1f4f9; margin: 0; padding: 10px 14px; }
    .wrap { width: 100%; min-height: calc(100vh - 20px); display: flex; flex-direction: column; }
    h2 { margin: 0 0 4px; color: #2b579a; font-size: 22px; }
    .note { background: #fff8e1; border: 1px solid #f0d98a; border-radius: 4px; padding: 8px 12px; margin: 8px 0 10px; line-height: 1.6; }
    .card { background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .card.grow { flex: 1; display: flex; flex-direction: column; min-height: 0; margin-bottom: 0; }
    .card h4 { margin: 0 0 8px; font-size: 13px; color: #2b579a; text-transform: uppercase; letter-spacing: .3px; }
    .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
    .bar label { display: block; font-size: 11px; font-weight: bold; color: #555; margin-bottom: 2px; }
    input[type=text], input[type=number], select { padding: 6px 8px; border: 1px solid #94a3b8; border-radius: 3px; font-size: 13px; background: #fff; }
    .btn { background: #2b579a; color: #fff; border: none; padding: 7px 16px; font-weight: bold; border-radius: 3px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn.green { background: #2e7d32; } .btn.gray { background: #64748b; }
    .btn:disabled { opacity: .5; cursor: not-allowed; }
    .chk { display: flex; align-items: center; gap: 5px; padding-bottom: 6px; }
    .scroll { flex: 1; min-height: 300px; max-height: calc(100vh - 420px); overflow: auto; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #cbd5e1; border: 1px solid #94a3b8; padding: 6px; text-align: left; position: sticky; top: 0; z-index: 2; }
    table.grid td { border: 1px solid #cbd5e1; padding: 4px 6px; }
    table.grid tr:hover td { background: #f8fafc; }
    .c { text-align: center; } .r { text-align: right; }
    td.in input { width: 100%; text-align: right; padding: 5px 6px; }
    td.in input.chg { background: #fff7d6; border-color: #e0a800; font-weight: bold; }
    tr.rowchg td.nm { border-left: 4px solid #e0a800; }
    td.auto { background: #f8fafc; color: #1e4273; font-weight: bold; text-align: right; }
    .tx { font-size: 10.5px; color: #b45309; display: block; }
    .col { font-weight: normal; color: #777; font-size: 11px; }
    .alert { padding: 9px 12px; border-radius: 4px; margin-bottom: 10px; font-weight: bold; }
    .alert.ok { background: #e6f4ea; color: #1e6b30; border: 1px solid #a8d5b5; }
    .alert.err { background: #fde8e8; color: #b42318; border: 1px solid #f5b5b5; }
    .alert.info { background: #e8f0fe; color: #2b579a; border: 1px solid #b6c8ee; }
    .savebar { border-top: 2px solid #2b579a; padding: 10px 14px; margin: 10px -12px -12px; display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .warn { color: #b45309; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
    <h2>Multi-Unit Conversion Setup</h2>
    <div class="note">
        <b>সূত্র:</b> ১ Box = <b>N</b> Pcs &nbsp;|&nbsp; <b>M</b> Pcs = <b>K</b> SFT (রাউন্ড ফিগারে, যেমন ৩ Pcs = ২ SFT) &nbsp;|&nbsp; ১ Box = <b>X</b> FM (Factory Measurement, শুধু Purchase এ)।<br>
        স্টক সবসময় <b>SFT</b> তে জমা হয়; Box, Pcs ও FM সেটআপ অনুযায়ী SFT থেকে নিজে হিসাব হয়। FM = ০ রাখলে ওই পণ্যে Purchase এ FM বাছা যাবে না।<br>
        <span class="warn">⚠ নতুন মান শুধু নতুন GRN / Invoice / Challan এ কাজ করবে। পুরোনো এন্ট্রির SFT বদলায় না, কিন্তু স্টক রিপোর্টের Box / Pcs হিসাব বদলে যেতে পারে — যে পণ্যে লেনদেন আছে সেটা বদলানোর আগে নিশ্চিত হয়ে নিন।</span>
    </div>

    <?php echo $flash; ?>

    <!-- ===== Bulk Apply by Size ===== -->
    <div class="card">
        <h4>Bulk Apply by Size</h4>
        <div class="bar">
            <div><label>Size</label>
                <select id="bkSize"><option value="">-- Select --</option>
                <?php foreach ($sizes as $z): ?><option value="<?php echo e($z); ?>"><?php echo e($z); ?></option><?php endforeach; ?></select></div>
            <div><label>১ Box = কত Pcs</label><input type="number" id="bkBox" step="0.01" min="0" style="width:110px;" placeholder="25"></div>
            <div><label>Pcs (M)</label><input type="number" id="bkRp" step="0.01" min="0" style="width:90px;" placeholder="3"></div>
            <div><label>= SFT (K)</label><input type="number" id="bkRo" step="0.01" min="0" style="width:90px;" placeholder="2"></div>
            <div><label>১ Box = কত FM</label><input type="number" id="bkFm" step="any" min="0" style="width:110px;" placeholder="16.14"></div>
            <button type="button" class="btn" onclick="bulkApply()">Apply to this size</button>
            <span style="color:#777;">ফাঁকা ঘর বদলাবে না। Apply শুধু নিচের তালিকার ঘরে বসায়; <b>Save</b> না চাপা পর্যন্ত database এ যায় না।</span>
        </div>
    </div>

    <!-- ===== Filter ===== -->
    <div class="card">
        <form method="get" class="bar">
            <div><label>Search (Name / Code / Color)</label><input type="text" name="q" value="<?php echo e($q); ?>" style="width:240px;" placeholder="Search..."></div>
            <div><label>Size</label>
                <select name="size"><option value="">All</option>
                <?php foreach ($sizes as $z): ?><option value="<?php echo e($z); ?>" <?php echo $z === $f_size ? 'selected' : ''; ?>><?php echo e($z); ?></option><?php endforeach; ?></select></div>
            <label class="chk"><input type="checkbox" name="inc" value="1" <?php echo $only_inc ? 'checked' : ''; ?>> শুধু অসম্পূর্ণ (FM সেট হয়নি)</label>
            <label class="chk"><input type="checkbox" name="all" value="1" <?php echo $show_all ? 'checked' : ''; ?>> Compound নয় এমন পণ্যও দেখাও</label>
            <button class="btn" type="submit">Filter</button>
            <a class="btn gray" href="<?php echo e(basename($_SERVER['PHP_SELF'])); ?>">Reset</a>
            <div style="margin-left:auto; color:#555;"><b><?php echo $total; ?></b> ti product</div>
        </form>
    </div>

    <!-- ===== Table ===== -->
    <div class="card grow">
        <form method="post" id="mf" onsubmit="return confirmSave();" style="flex:1; display:flex; flex-direction:column; min-height:0;">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <div class="scroll">
            <table class="grid" id="muTable">
                <thead>
                    <tr>
                        <th style="width:36px;">SL</th>
                        <th style="width:90px;">Code</th>
                        <th>Item Name</th>
                        <th style="width:90px;">Size</th>
                        <th style="width:50px;">UOM</th>
                        <th class="r" style="width:110px;">১ Box = Pcs</th>
                        <th class="r" style="width:90px;">Pcs (M)</th>
                        <th class="r" style="width:90px;">= SFT (K)</th>
                        <th class="r" style="width:110px;">১ Box = FM</th>
                        <th class="r" style="width:85px;">SFT / Pcs</th>
                        <th class="r" style="width:90px;">SFT / Box</th>
                        <th class="r" style="width:100px;">১ FM = SFT</th>
                    </tr>
                </thead>
                <tbody>
                <?php $sl = 1; if ($total): while ($p = mysqli_fetch_assoc($res)): $id = (int)$p['id']; ?>
                    <tr data-size="<?php echo e($p['size_name']); ?>" data-tx="<?php echo (int)$p['has_tx']; ?>">
                        <td class="c"><?php echo $sl++; ?></td>
                        <td><?php echo e($p['item_code']); ?></td>
                        <td class="nm"><b><?php echo e($p['item_name']); ?></b>
                            <?php if (trim((string)$p['color_name']) !== ''): ?><span class="col"> <?php echo e($p['color_name']); ?></span><?php endif; ?>
                            <?php if ((int)$p['has_tx']): ?><span class="tx">লেনদেন আছে</span><?php endif; ?></td>
                        <td><?php echo e($p['size_name'] ?? '-'); ?></td>
                        <td><?php echo e($p['unit_name'] ?? '-'); ?></td>
                        <?php
                        $fields = [
                            'box_to_pcs'   => nf($p['box_to_pcs'], 2, 2),
                            'ratio_pcs'    => nf($p['ratio_pcs'], 2, 2),
                            'ratio_output' => nf($p['ratio_output'], 2, 2),
                            'fm_per_box'   => nf($p['fm_per_box'], 2, 4),
                        ];
                        foreach ($fields as $f => $val): ?>
                        <td class="in"><input type="number" name="mu[<?php echo $id; ?>][<?php echo $f; ?>]" data-f="<?php echo $f; ?>" data-orig="<?php echo $val; ?>"
                                value="<?php echo $val; ?>" step="<?php echo $f === 'fm_per_box' ? 'any' : '0.01'; ?>" min="0"></td>
                        <?php endforeach; ?>
                        <td class="auto c-sp">-</td>
                        <td class="auto c-sb">-</td>
                        <td class="auto c-fs">-</td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="12" class="c" style="color:#888; padding:14px;">কোনো পণ্য পাওয়া যায়নি। (শুধু "Is Item Compound Unit?" টিক দেওয়া পণ্য এখানে আসে।)</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="savebar">
                <div><b id="chgRows">0</b> ti product e poriborton hoyeche</div>
                <button type="submit" class="btn green" id="saveBtn" disabled>💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var rows = document.querySelectorAll('#muTable tbody tr[data-size]');

    function val(row, f) { var i = row.querySelector('input[data-f="' + f + '"]'); return parseFloat(i.value) || 0; }

    function recalc(row) {
        var box = val(row, 'box_to_pcs'), rp = val(row, 'ratio_pcs'), ro = val(row, 'ratio_output'), fm = val(row, 'fm_per_box');
        var sp = rp > 0 ? ro / rp : 0, sb = sp * box;
        row.querySelector('.c-sp').textContent = sp > 0 ? sp.toFixed(4) : '-';
        row.querySelector('.c-sb').textContent = sb > 0 ? sb.toFixed(4) : '-';
        row.querySelector('.c-fs').textContent = (fm > 0 && sb > 0) ? (sb / fm).toFixed(4) : '-';
        var changed = false;
        row.querySelectorAll('td.in input').forEach(function (i) {
            var c = (parseFloat(i.value) || 0) !== (parseFloat(i.dataset.orig) || 0);
            i.classList.toggle('chg', c); if (c) changed = true;
        });
        row.classList.toggle('rowchg', changed);
    }
    function refresh() {
        var n = document.querySelectorAll('#muTable tr.rowchg').length;
        document.getElementById('chgRows').textContent = n;
        document.getElementById('saveBtn').disabled = (n === 0);
    }
    rows.forEach(function (row) {
        recalc(row);
        row.querySelectorAll('td.in input').forEach(function (i) {
            i.addEventListener('input', function () { recalc(row); refresh(); });
            var jf = false;
            i.addEventListener('focus', function () { jf = true; i.select(); });
            i.addEventListener('mouseup', function (e) { if (jf) { e.preventDefault(); jf = false; } });
            i.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var r = row.nextElementSibling;
                while (r && !r.querySelector('input[data-f="' + i.dataset.f + '"]')) r = r.nextElementSibling;
                if (r) r.querySelector('input[data-f="' + i.dataset.f + '"]').focus();
            });
        });
    });
    refresh();

    window.bulkApply = function () {
        var size = document.getElementById('bkSize').value;
        if (!size) { alert('আগে Size বাছুন।'); return; }
        var map = { box_to_pcs: 'bkBox', ratio_pcs: 'bkRp', ratio_output: 'bkRo', fm_per_box: 'bkFm' };
        var any = false;
        Object.keys(map).forEach(function (f) { if (document.getElementById(map[f]).value !== '') any = true; });
        if (!any) { alert('অন্তত একটা মান লিখুন।'); return; }
        var n = 0;
        rows.forEach(function (row) {
            if (row.dataset.size !== size) return;
            Object.keys(map).forEach(function (f) {
                var v = document.getElementById(map[f]).value;
                if (v !== '') row.querySelector('input[data-f="' + f + '"]').value = v;
            });
            recalc(row); n++;
        });
        refresh();
        alert(n > 0 ? n + ' ti product e বসেছে। এবার Save চাপুন।' : 'এই Size এর কোনো পণ্য তালিকায় নেই। Filter এ Size বদলে দেখুন।');
    };

    window.confirmSave = function () {
        var n = 0, tx = 0;
        document.querySelectorAll('#muTable tr.rowchg').forEach(function (r) { n++; if (r.dataset.tx === '1') tx++; });
        if (n === 0) return false;
        var bad = false;
        document.querySelectorAll('#muTable tr.rowchg').forEach(function (r) {
            if (val(r, 'box_to_pcs') <= 0 || val(r, 'ratio_pcs') <= 0 || val(r, 'ratio_output') <= 0) bad = true;
        });
        if (bad) { alert('Pcs per Box, Pcs ও SFT অবশ্যই ০ এর বেশি হতে হবে।'); return false; }
        var msg = n + ' ti product save korben?';
        if (tx > 0) msg += '\n\n⚠ ' + tx + ' ti product e আগের লেনদেন আছে। পুরোনো এন্ট্রি বদলাবে না, কিন্তু স্টক রিপোর্টের Box/Pcs হিসাব বদলে যেতে পারে।';
        return confirm(msg);
    };
})();
</script>
</body>
</html>