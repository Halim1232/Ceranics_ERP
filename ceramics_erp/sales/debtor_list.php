<?php
include('../auth.php'); require_page_access();
include('../config.php');
include('../balance_functions.php');   // customer_prev_balance() — ভাউচার/জার্নালসহ চলতি বকেয়া (কালেকশন ভাউচার স্ক্রিনের সাথে একই হিসাব)

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['dl_csrf'])) { $_SESSION['dl_csrf'] = bin2hex(random_bytes(16)); }
$dl_csrf = $_SESSION['dl_csrf'];

// কালেকশন তারিখের কলাম (একবারই তৈরি হয়, পুরনো ডেটা অপরিবর্তিত)
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM erp_customers LIKE 'next_collect_date'");
    if ($cc && mysqli_num_rows($cc) == 0) mysqli_query($conn, "ALTER TABLE erp_customers ADD COLUMN next_collect_date DATE NULL DEFAULT NULL");
} catch (Throwable $e) { error_log('next_collect_date: ' . $e->getMessage()); }

// ---------- কালেকশন তারিখ সেভ (AJAX) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_collect_date') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($dl_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Invalid token');
        $cid = (int)($_POST['customer_id'] ?? 0);
        $d   = trim((string)($_POST['date'] ?? ''));
        if ($cid <= 0) throw new Exception('Customer পাওয়া যায়নি');
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new Exception('তারিখ ঠিক নয়');
        if (current_branch()) {   // ব্রাঞ্চ ইউজার: শুধু নিজের ব্রাঞ্চের কাস্টমার
            $ok = mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM sales_invoice_master WHERE customer_id=$cid" . branch_sql('branch_name') . " LIMIT 1"));
            if (!$ok) throw new Exception('এই কাস্টমারে আপনার অনুমতি নেই');
        }
        $dv = ($d === '') ? null : $d;
        $st = mysqli_prepare($conn, "UPDATE erp_customers SET next_collect_date=? WHERE id=?");
        mysqli_stmt_bind_param($st, 'si', $dv, $cid);
        mysqli_stmt_execute($st);
        echo json_encode(['ok' => true, 'date' => $d]);
    } catch (Throwable $ex) { echo json_encode(['ok' => false, 'msg' => $ex->getMessage()]); }
    exit;
}

// ---------- ফিল্টার ----------
$showIn = $_GET['show'] ?? 'due';
$show = in_array($showIn, ['due', 'advance', 'all'], true) ? $showIn : 'due';   // ডিফল্ট: শুধু বকেয়া
$sort = (($_GET['sort'] ?? 'balance') === 'name') ? 'name' : 'balance';
$q    = trim($_GET['q'] ?? '');

// ---------- কাস্টমার তালিকা ----------
$types = ""; $params = []; $where = "";
if ($q !== '') {
    $where = "WHERE (customer_name LIKE ? OR customer_code LIKE ? OR address LIKE ? OR phone LIKE ?)";
    $types = "ssss"; $like = '%' . $q . '%'; $params = [$like, $like, $like, $like];
}
if (current_branch()) {   // ব্রাঞ্চ ইউজার: শুধু যে কাস্টমারের এই ব্রাঞ্চে ইনভয়েস আছে
    $bc = "id IN (SELECT customer_id FROM sales_invoice_master WHERE 1=1" . branch_sql('branch_name') . ")";
    $where = ($where === '') ? "WHERE $bc" : $where . " AND $bc";
}
$stmt = $conn->prepare("SELECT id, customer_code, customer_name, address, phone, next_collect_date FROM erp_customers $where ORDER BY customer_name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// শেষ ইনভয়েসের তারিখ (একবারেই সবার)
$last = [];
$lq = mysqli_query($conn, "SELECT customer_id, MAX(invoice_date) AS d FROM sales_invoice_master WHERE 1=1" . branch_sql('branch_name') . " GROUP BY customer_id");
while ($lq && $x = mysqli_fetch_assoc($lq)) $last[(int)$x['customer_id']] = $x['d'];

$rows = [];
$total = 0;
while ($r = $res->fetch_assoc()) {
    $bal = round((float)customer_prev_balance($conn, (int)$r['id']), 2);   // + = কাস্টমার দেবে, − = অগ্রিম জমা
    if ($show == 'due'     && $bal <= 0.005)  continue;
    if ($show == 'advance' && $bal >= -0.005) continue;
    $r['bal'] = $bal;
    $r['last_date'] = $last[(int)$r['id']] ?? null;
    $rows[] = $r;
    $total += $bal;
}
$stmt->close();

$today = date('Y-m-d'); $due_cnt = 0;   // আজ বা তার আগের কালেকশন তারিখ (বকেয়া আছে)
foreach ($rows as $r2) { if (!empty($r2['next_collect_date']) && $r2['next_collect_date'] <= $today && $r2['bal'] > 0.005) $due_cnt++; }

if ($sort == 'name') usort($rows, function ($a, $b) { return strcasecmp($a['customer_name'], $b['customer_name']); });
else usort($rows, function ($a, $b) use ($show) { return $show == 'advance' ? $a['bal'] <=> $b['bal'] : $b['bal'] <=> $a['bal']; });

$showLabel = ['due' => 'Outstanding (Due)', 'advance' => 'Advance (Credit Balance)', 'all' => 'All'][$show];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Debtor List</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #333; }
    .cards { display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 140px; background: #fff; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 18px; font-weight: bold; margin-top: 4px; }
    .title { font-size: 15px; font-weight: bold; color: #2e7d32; margin: 10px 0 8px; text-transform: uppercase; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 15px; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .btn { padding: 6px 15px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; height: 30px; font-size: 12px; }
    .btn-show { background: #2e7d32; } .btn-clear { background: #6c757d; } .btn-print { background: #dc3545; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #999; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #999; }
    .grid-table td { padding: 5px 8px; border: 1px solid #ccc; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .r { text-align: right; } .c { text-align: center; }
    .grand td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    .cd { white-space: nowrap; }
    .cd .cdate { height: 26px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; padding: 0 4px; }
    .cd .sv { height: 26px; width: 28px; border: none; border-radius: 4px; background: #cfd8dc; color: #fff; cursor: pointer; }
    .cd .sv.dirty { background: #2e7d32; }
    .cd .msg { font-size: 11px; color: #2e7d32; margin-left: 4px; }
    .cd .badge { display: block; font-size: 11px; font-weight: bold; margin-top: 2px; }
    .pr-only { display: none; }
    .grid-table tbody tr.ov td { background: #fdecea !important; }
    .grid-table tbody tr.today td { background: #fff4e0 !important; }
    @media print {
        .filter-panel { display: none; }
        body { background: #fff; padding: 0; }
        .print-head { display: block; }
        .cd .cdate, .cd .sv, .cd .msg { display: none; }
        .pr-only { display: inline !important; }
        .grid-table tbody tr.ov td, .grid-table tbody tr.today td { background: #fff !important; }
        tr { break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;">UTTARA BUILDERS</h2>
    <div style="font-weight:bold;">Debtor List (<?php echo h($showLabel); ?>) as on <?php echo date('d-M-Y'); ?></div>
    <?php if ($q !== '') echo '<div style="font-size:12px;color:#555;">Search: ' . h($q) . '</div>'; ?>
</div>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Show</label>
            <select name="show" class="fc" autocomplete="off">
                <option value="due" <?php if ($show == 'due') echo 'selected'; ?>>Outstanding (Due)</option>
                <option value="advance" <?php if ($show == 'advance') echo 'selected'; ?>>Advance (Credit Balance)</option>
                <option value="all" <?php if ($show == 'all') echo 'selected'; ?>>All Customers</option>
            </select></div>
        <div class="fg"><label>Sort By</label>
            <select name="sort" class="fc" autocomplete="off">
                <option value="balance" <?php if ($sort == 'balance') echo 'selected'; ?>>Balance (High to Low)</option>
                <option value="name" <?php if ($sort == 'name') echo 'selected'; ?>>Customer Name</option>
            </select></div>
        <div class="fg"><label>Customer</label><input type="text" name="q" class="fc" autocomplete="off" placeholder="Name / Code / Address / Mobile" value="<?php echo h($q); ?>"></div>
        <div class="fg" style="flex-direction:row; gap:6px; align-items:flex-end;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <button type="button" class="btn btn-clear" onclick="location.href='?'">Clear</button>
            <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</form>

<div class="cards">
    <div class="card"><div class="t">Customers</div><div class="v"><?php echo count($rows); ?></div></div>
    <div class="card" style="border-left-color:#d32f2f;"><div class="t">Net Receivable (Balance)</div><div class="v" style="color:#d32f2f;"><?php echo number_format($total, 2); ?></div></div>
    <div class="card" style="border-left-color:#e65100;"><div class="t">Collection Due (আজ + ওভারডিউ)</div><div class="v" style="color:#e65100;"><?php echo (int)$due_cnt; ?> জন</div></div>
</div>

<div class="title">Debtor List - <?php echo h($showLabel); ?></div>

<table class="grid-table">
    <thead>
        <tr>
            <th class="c" style="width:45px;">SL No</th><th style="width:90px;">Code</th><th>Customer Name</th><th>Address</th><th style="width:110px;">Mobile</th>
            <th class="r" style="width:140px;">Balance</th><th class="c" style="width:95px;">Last Invoice</th><th class="c" style="width:215px;">Collection Date</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rows) { $sl = 1; foreach ($rows as $r) {
        $cd = $r['next_collect_date'] ?? null; $rc = ''; $late = 0;
        if ($cd && $r['bal'] > 0.005) {
            $late = (int)floor((strtotime($today) - strtotime($cd)) / 86400);
            $rc = ($cd < $today) ? 'ov' : (($cd === $today) ? 'today' : '');
        } ?>
        <tr class="<?php echo $rc; ?>" data-id="<?php echo (int)$r['id']; ?>">
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo h($r['customer_code']); ?></td>
            <td style="font-weight:bold;color:#2b579a;"><?php echo h($r['customer_name']); ?></td>
            <td><?php echo h($r['address']); ?></td>
            <td><?php echo h($r['phone']); ?></td>
            <td class="r" style="font-weight:bold;color:<?php echo $r['bal'] > 0 ? '#d32f2f' : '#28a745'; ?>;"><?php echo number_format($r['bal'], 2); ?><?php echo $r['bal'] < 0 ? ' (Adv)' : ''; ?></td>
            <td class="c"><?php echo $r['last_date'] ? date('d-M-Y', strtotime($r['last_date'])) : '-'; ?></td>
            <td class="c cd">
                <input type="date" class="cdate" value="<?php echo h($cd); ?>" aria-label="Collection date">
                <button type="button" class="sv" title="সেভ" onclick="saveCd(this)"><i class="fa-solid fa-check"></i></button><span class="msg"></span>
                <span class="pr-only"><?php echo $cd ? date('d-M-Y', strtotime($cd)) : '-'; ?></span>
                <span class="badge" style="color:<?php echo $rc === 'ov' ? '#b42318' : '#b45309'; ?>;"><?php echo $rc === 'ov' ? $late . ' দিন ওভারডিউ' : ($rc === 'today' ? 'আজ আদায়ের তারিখ' : ''); ?></span>
            </td>
        </tr>
    <?php } ?>
        <tr class="grand">
            <td colspan="5" class="c">Grand Total</td>
            <td class="r"><?php echo number_format($total, 2); ?></td>
            <td></td><td></td>
        </tr>
    <?php } else { ?>
        <tr><td colspan="8" class="c" style="padding:15px;color:#777;">এই ফিল্টারে কোনো কাস্টমার পাওয়া যায়নি।</td></tr>
    <?php } ?>
    </tbody>
</table>

<script>
var TODAY = '<?php echo $today; ?>', DL_CSRF = '<?php echo $dl_csrf; ?>';
document.querySelectorAll('.cdate').forEach(function (i) {
    i.addEventListener('input', function () { i.parentNode.querySelector('.sv').classList.add('dirty'); });
});
function saveCd(btn) {
    var td = btn.parentNode, tr = btn.closest('tr'), inp = td.querySelector('.cdate'), msg = td.querySelector('.msg');
    var fd = new FormData();
    fd.append('action', 'set_collect_date'); fd.append('csrf', DL_CSRF);
    fd.append('customer_id', tr.getAttribute('data-id')); fd.append('date', inp.value);
    msg.textContent = '...';
    fetch(location.href, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { msg.style.color = '#b42318'; msg.textContent = d.msg || 'সেভ হয়নি'; return; }
        msg.style.color = '#2e7d32'; msg.textContent = d.date ? 'সেভ হয়েছে' : 'মুছে গেছে';
        btn.classList.remove('dirty');
        tr.classList.remove('ov', 'today');
        var b = td.querySelector('.badge'); b.textContent = '';
        if (d.date && d.date < TODAY) { tr.classList.add('ov'); b.style.color = '#b42318'; b.textContent = Math.round((new Date(TODAY) - new Date(d.date)) / 86400000) + ' দিন ওভারডিউ'; }
        else if (d.date && d.date === TODAY) { tr.classList.add('today'); b.style.color = '#b45309'; b.textContent = 'আজ আদায়ের তারিখ'; }
        td.querySelector('.pr-only').textContent = d.date ? d.date.split('-').reverse().join('-') : '-';
    }).catch(function () { msg.style.color = '#b42318'; msg.textContent = 'সেভ হয়নি'; });
}
</script>
</body>
</html>