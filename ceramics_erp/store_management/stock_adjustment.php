<?php
// stock_adjustment.php?type=short | over | damage
include('../auth.php'); require_page_access();
include('../config.php');
include('stock_adj_lib.php');
try { include_once('../Settings/settings_lib.php'); } catch (Throwable $e) {}
adj_ensure($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$CFG = [
    'short'  => ['t' => 'Product Short Adjust', 'p' => 'SA', 'vt' => 'ADJ_SHORT',  'dir' => 'OUT', 'col' => '#d32f2f',
                 'reasons' => ['Count Difference', 'Theft / Missing', 'Correction', 'Other']],
    'over'   => ['t' => 'Product Over Adjust',  'p' => 'OA', 'vt' => 'ADJ_OVER',   'dir' => 'IN',  'col' => '#2e7d32',
                 'reasons' => ['Count Difference', 'Found Stock', 'Correction', 'Other']],
    'damage' => ['t' => 'Product Damage',       'p' => 'PD', 'vt' => 'ADJ_DAMAGE', 'dir' => 'OUT', 'col' => '#e65100',
                 'reasons' => ['Breakage', 'Transport Damage', 'Moisture / Water Damage', 'Defective', 'Other']],
];
$type = strtolower($_GET['type'] ?? 'short');
if (!isset($CFG[$type])) $type = 'short';
$c = $CFG[$type]; $vt = $c['vt']; $dir = $c['dir'];
$self = "stock_adjustment.php?type=$type";

$whs = adj_warehouses($conn);
$whIds = array_column($whs, 'id');
$needWh = adj_has_wh($conn) && !empty($whs);

// ---------- AJAX: stock + rate ----------
if (($_GET['ajax'] ?? '') === 'stock') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['pid'] ?? 0); $wh = (int)($_GET['wh'] ?? 0); $ex = trim($_GET['ex'] ?? '');
    echo json_encode(['stock' => round(adj_stock($conn, $pid, $wh, $vt, $ex), 2), 'rate' => round(adj_rate($conn, $pid), 2)]);
    exit;
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail = function ($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');
    $uid = (int)$_SESSION['user_id'];

    // ---- ডিলিট ----
    if (($_POST['do'] ?? '') === 'delete') {
        $id = (int)$_POST['id'];
        $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT adj_no FROM erp_stock_adjustment WHERE id=$id AND adj_type='" . strtoupper($type) . "'"));
        if ($o) {
            mysqli_begin_transaction($conn);
            try {
                adj_ledger_delete($conn, $vt, $o['adj_no']);
                mysqli_query($conn, "DELETE FROM erp_stock_adjustment_details WHERE adj_id=$id");
                mysqli_query($conn, "DELETE FROM erp_stock_adjustment WHERE id=$id");
                mysqli_commit($conn);
            } catch (Throwable $e) { mysqli_rollback($conn); $fail('ডিলিট ব্যর্থ হয়েছে।'); }
        }
        echo "<script>alert('এন্ট্রি মুছে ফেলা হয়েছে, স্টক সংশোধিত হয়েছে।'); window.location.href='$self';</script>"; exit;
    }

    // ---- সেভ ----
    if (($_POST['do'] ?? '') === 'save') {
        $edit   = (int)($_POST['edit_id'] ?? 0);
        $date   = $_POST['adj_date'] ?? '';
        $wh     = (int)($_POST['warehouse_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $fail('সঠিক তারিখ দিন!');
        if ($needWh && !in_array($wh, $whIds)) $fail('ওয়্যারহাউস সিলেক্ট করুন!');
        if (!$needWh) $wh = 0;
        if (!in_array($reason, $c['reasons'])) $fail('কারণ (Reason) সিলেক্ট করুন!');

        $items = []; $need = [];
        foreach (($_POST['product_id'] ?? []) as $i => $p) {
            $pid = (int)$p; $sft = round(floatval($_POST['qty'][$i] ?? 0), 4);
            if ($pid <= 0 || $sft <= 0) continue;
            $items[] = ['pid' => $pid, 'box' => floatval($_POST['box'][$i] ?? 0), 'pcs' => floatval($_POST['pcs'][$i] ?? 0), 'sft' => $sft];
            $need[$pid] = ($need[$pid] ?? 0) + $sft;
        }
        if (!$items) $fail('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!');

        $adj_no = '';
        if ($edit > 0) {
            $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT adj_no FROM erp_stock_adjustment WHERE id=$edit AND adj_type='" . strtoupper($type) . "'"));
            if (!$o) $fail('এন্ট্রি পাওয়া যায়নি!');
            $adj_no = $o['adj_no'];
        }

        // OUT হলে স্টকের বেশি বাদ দেওয়া যাবে না
        if ($dir === 'OUT') {
            $short = [];
            foreach ($need as $pid => $qty) {
                $stock = adj_stock($conn, $pid, $wh, $vt, $adj_no);
                if ($qty - $stock > 0.01) {
                    $pn = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name FROM erp_products WHERE id=$pid"));
                    $short[] = ($pn['item_name'] ?? $pid) . " (স্টক: " . number_format($stock, 2) . ", বাদ: " . number_format($qty, 2) . ")";
                }
            }
            if ($short) $fail("স্টকের চেয়ে বেশি বাদ দেওয়া যাবে না:\n" . implode("\n", $short));
        }

        mysqli_begin_transaction($conn);
        try {
            $de = mysqli_real_escape_string($conn, $date); $re = mysqli_real_escape_string($conn, $reason); $rm = mysqli_real_escape_string($conn, $remarks);
            $whs_sql = $wh > 0 ? $wh : 'NULL';
            if ($edit > 0) {
                mysqli_query($conn, "UPDATE erp_stock_adjustment SET adj_date='$de', warehouse_id=$whs_sql, reason='$re', remarks='$rm' WHERE id=$edit");
                mysqli_query($conn, "DELETE FROM erp_stock_adjustment_details WHERE adj_id=$edit");
                adj_ledger_delete($conn, $vt, $adj_no);
                $adj_id = $edit;
            } else {
                $adj_no = adj_next_no($conn, 'erp_stock_adjustment', 'adj_no', $c['p']);
                $ne = mysqli_real_escape_string($conn, $adj_no);
                mysqli_query($conn, "INSERT INTO erp_stock_adjustment (adj_no, adj_date, adj_type, warehouse_id, reason, remarks, created_by)
                                     VALUES ('$ne', '$de', '" . strtoupper($type) . "', $whs_sql, '$re', '$rm', $uid)");
                $adj_id = mysqli_insert_id($conn);
            }
            foreach ($items as $it) {
                $rate = adj_rate($conn, $it['pid']);
                mysqli_query($conn, "INSERT INTO erp_stock_adjustment_details (adj_id, product_id, direction, box_qty, pcs_qty, sqft_qty, rate)
                                     VALUES ($adj_id, {$it['pid']}, '$dir', {$it['box']}, {$it['pcs']}, {$it['sft']}, $rate)");
                adj_ledger_insert($conn, $it['pid'], $vt, $adj_no, $dir, $it['box'], $it['pcs'], $it['sft'], $rate, $wh);
            }
            mysqli_commit($conn);
        } catch (Throwable $e) { mysqli_rollback($conn); $fail('সেভ ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।'); }

        echo "<script>alert(" . json_encode("$adj_no সংরক্ষিত হয়েছে।") . "); window.location.href='$self';</script>"; exit;
    }
}

// ---------- ডাটা ----------
$action  = $_GET['action'] ?? 'list';
$edit_id = ($action === 'edit') ? (int)($_GET['id'] ?? 0) : 0;
$show_form = ($action === 'new' || $edit_id > 0);

$wh_name = function ($id) use ($whs) { foreach ($whs as $w) if ($w['id'] == $id) return $w['name']; return '-'; };

// ---------- প্রিন্ট ----------
if ($action === 'print') {
    $id = (int)($_GET['id'] ?? 0);
    $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_stock_adjustment WHERE id=$id AND adj_type='" . strtoupper($type) . "'"));
    if (!$m) die('এন্ট্রি পাওয়া যায়নি');
    $bn = ''; foreach ($whs as $w) if ($w['id'] == $m['warehouse_id']) $bn = $w['branch_name'] ?? '';
    $co = []; $br = null;
    try { $co = company_info($conn); if ($bn !== '') $br = branch_info($conn, $bn); } catch (Throwable $e) {}
    $hn = trim($br['branch_name'] ?? '') ?: (trim($co['company_name'] ?? '') ?: 'Uttara Builders');
    $hp = trim($br['phone'] ?? '');
    $dr = mysqli_query($conn, "SELECT d.*, p.item_name, p.size_name, p.color_name, p.unit_name FROM erp_stock_adjustment_details d JOIN erp_products p ON p.id = d.product_id WHERE d.adj_id=$id ORDER BY d.id");
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title><?php echo h($m['adj_no']); ?></title>
<style>
 body{font-family:'Segoe UI',Arial,sans-serif;margin:25px;font-size:13px;color:#222}
 .hd{text-align:center;border-bottom:2px solid #2e7d32;padding-bottom:8px;margin-bottom:14px}.hd h2{margin:0;color:#2e7d32;text-transform:uppercase}
 .tt{display:inline-block;background:#2e7d32;color:#fff;padding:3px 16px;border-radius:3px;margin-top:6px;font-weight:bold}
 table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #999;padding:6px}th{background:#e8eef0}.r{text-align:right}.c{text-align:center}
 .meta td{border:none;padding:3px 0}
 .sig{display:flex;justify-content:space-between;margin-top:70px}.sig div{border-top:1px solid #333;width:170px;text-align:center;padding-top:5px;font-size:12px}
</style></head><body onload="window.print()">
<div class="hd"><h2><?php echo h($hn); ?></h2><?php if ($hp !== '') echo '<div style="font-size:12px;">Phone: ' . h($hp) . '</div>'; ?><div class="tt"><?php echo h($c['t']); ?></div></div>
<table class="meta"><tr><td><b>No:</b> <?php echo h($m['adj_no']); ?></td><td class="r"><b>Date:</b> <?php echo date('d-M-Y', strtotime($m['adj_date'])); ?></td></tr>
<tr><td><b>Warehouse:</b> <?php echo h($wh_name($m['warehouse_id'])); ?></td><td class="r"><b>Reason:</b> <?php echo h($m['reason']); ?></td></tr></table>
<table><thead><tr><th class="c" style="width:35px">SL</th><th>Product</th><th>Size</th><th>Color</th><th class="r">Box</th><th class="r">Pcs</th><th class="r">Qty (SFT)</th><th class="r">Rate</th><th class="r">Value</th></tr></thead><tbody>
<?php $sl = 1; $tq = 0; $tv = 0; while ($dr && $d = mysqli_fetch_assoc($dr)) { $v = $d['sqft_qty'] * $d['rate']; $tq += $d['sqft_qty']; $tv += $v; ?>
<tr><td class="c"><?php echo $sl++; ?></td><td><b><?php echo h($d['item_name']); ?></b></td><td><?php echo h($d['size_name']); ?></td><td><?php echo h($d['color_name']); ?></td>
<td class="r"><?php echo (int)$d['box_qty']; ?></td><td class="r"><?php echo (int)$d['pcs_qty']; ?></td><td class="r"><?php echo adj_n2($d['sqft_qty']); ?></td><td class="r"><?php echo adj_n2($d['rate']); ?></td><td class="r"><?php echo adj_n2($v); ?></td></tr>
<?php } ?>
<tr><td colspan="6" class="r"><b>Total</b></td><td class="r"><b><?php echo adj_n2($tq); ?></b></td><td></td><td class="r"><b><?php echo adj_n2($tv); ?></b></td></tr></tbody></table>
<?php if ($m['remarks']) echo '<p><b>Remarks:</b> ' . h($m['remarks']) . '</p>'; ?>
<div class="sig"><div>Prepared By</div><div>Store In-charge</div><div>Authorized Signature</div></div>
</body></html>
<?php exit; }

// ---------- ফর্ম ডাটা ----------
$ed = null; $ed_items = []; $adj_no_show = '(Auto)';
if ($edit_id > 0) {
    $ed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_stock_adjustment WHERE id=$edit_id AND adj_type='" . strtoupper($type) . "'"));
    if (!$ed) { echo "<script>window.location.href='$self';</script>"; exit; }
    $adj_no_show = $ed['adj_no'];
    $ir = mysqli_query($conn, "SELECT d.*, p.item_name, p.size_name, p.color_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output FROM erp_stock_adjustment_details d JOIN erp_products p ON p.id = d.product_id WHERE d.adj_id=$edit_id ORDER BY d.id");
    while ($ir && $x = mysqli_fetch_assoc($ir)) $ed_items[] = $x;
}
$products = [];
if ($show_form) {
    $pr = mysqli_query($conn, "SELECT p.id, p.item_name, p.size_name, p.color_name, p.unit_name, p.cost_price, p.box_to_pcs, p.ratio_pcs, p.ratio_output,
        (SELECT l.rate FROM erp_stock_ledger l WHERE l.product_id = p.id AND l.voucher_type='PURCHASE' AND l.sqft_in > 0 ORDER BY l.id DESC LIMIT 1) AS lr
        FROM erp_products p ORDER BY p.item_name");
    while ($pr && $x = mysqli_fetch_assoc($pr)) { $x['rate'] = ($x['lr'] !== null && (float)$x['lr'] > 0) ? (float)$x['lr'] : (float)$x['cost_price']; $products[] = $x; }
}

// ---------- তালিকা ----------
$rows = []; $sumq = 0; $sumv = 0;
if (!$show_form) {
    $lr = mysqli_query($conn, "SELECT m.*, IFNULL(t.items,0) AS items, IFNULL(t.qty,0) AS qty, IFNULL(t.val,0) AS val
        FROM erp_stock_adjustment m
        LEFT JOIN (SELECT adj_id, COUNT(*) items, SUM(sqft_qty) qty, SUM(sqft_qty * rate) val FROM erp_stock_adjustment_details GROUP BY adj_id) t ON t.adj_id = m.id
        WHERE m.adj_type='" . strtoupper($type) . "' ORDER BY m.id DESC");
    while ($lr && $r = mysqli_fetch_assoc($lr)) { $rows[] = $r; $sumq += $r['qty']; $sumv += $r['val']; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo h($c['t']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: #f0f0f0; }
    .btn-print { border: none; background: #2b579a; color: #fff; } .btn-save { border: none; background: #28a745; color: #fff; }
    .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 20px; }
    .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #ced4da; text-transform: uppercase; }
    .panel-body { padding: 15px; }
    .grid4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
    .fg { display: flex; flex-direction: column; gap: 4px; }
    .fg label { font-size: 12px; font-weight: bold; color: #444; }
    .fc { height: 30px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #cddc39; }
    .grid-table th { background: #dbebe7; padding: 7px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 4px; border: 1px solid #dee2e6; background: #fff; }
    .grid-table tbody tr.v-row:hover td { background: #f1f5f9; }
    .grid-table tr.selected-row td { background: #bddeb6 !important; }
    .r { text-align: right; }
    .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .filter { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
    .low-stock { background: #ffd6d6 !important; }
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; }
</style>
</head>
<body>

<?php if (!$show_form) { ?>
<div class="control-bar">
    <button class="btn-action" onclick="location.href='<?php echo $self; ?>&action=new'"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New</button>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#0498db;"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#dc3545;"></i> Delete</button>
    <button class="btn-action btn-print" onclick="printSel()"><i class="fa-solid fa-print"></i> Print</button>
</div>
<div class="form-panel">
    <div class="panel-header" style="color:<?php echo $c['col']; ?>;"><?php echo h($c['t']); ?> List</div>
    <div class="panel-body">
        <div class="filter">
            <div class="fg"><label>Adjustment No</label><input class="fc" id="f_no" oninput="runFilter()" placeholder="Search.."></div>
            <div class="fg"><label>Reason</label><input class="fc" id="f_reason" oninput="runFilter()" placeholder="Search.."></div>
            <div class="fg"><label>Date From</label><input type="date" class="fc" id="f_from" onchange="runFilter()"></div>
            <div class="fg"><label>Date To</label><input type="date" class="fc" id="f_to" onchange="runFilter()"></div>
        </div>
        <table class="grid-table" id="vTable">
            <thead><tr><th style="width:30px;"></th><th style="width:40px;">SL</th><th>Adjustment No</th><th>Date</th><th>Warehouse</th><th>Reason</th><th class="r">Items</th><th class="r">Qty (SFT)</th><th class="r">Value (BDT)</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php if ($rows) { $sl = 1; foreach ($rows as $r) { ?>
                <tr class="v-row" style="cursor:pointer;" onclick="selRow(this, <?php echo (int)$r['id']; ?>)" data-no="<?php echo h($r['adj_no']); ?>" data-reason="<?php echo h($r['reason']); ?>" data-date="<?php echo h($r['adj_date']); ?>">
                    <td style="text-align:center;"><input type="checkbox" class="chk" onclick="event.stopPropagation(); selRow(this.closest('tr'), <?php echo (int)$r['id']; ?>)"></td>
                    <td class="sl"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;color:<?php echo $c['col']; ?>;"><?php echo h($r['adj_no']); ?></td>
                    <td><?php echo date('d-M-Y', strtotime($r['adj_date'])); ?></td>
                    <td><?php echo h($wh_name($r['warehouse_id'])); ?></td>
                    <td><?php echo h($r['reason']); ?></td>
                    <td class="r"><?php echo (int)$r['items']; ?></td>
                    <td class="r"><?php echo adj_n2($r['qty']); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo adj_n2($r['val']); ?></td>
                    <td><?php echo h($r['remarks']); ?></td>
                </tr>
            <?php } ?>
                <tr class="total-row"><td colspan="7" style="text-align:center;">Grand Total</td><td class="r"><?php echo adj_n2($sumq); ?></td><td class="r"><?php echo adj_n2($sumv); ?></td><td></td></tr>
            <?php } else echo "<tr><td colspan='10' style='text-align:center;padding:20px;color:#999;'>কোনো এন্ট্রি নেই।</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>
<form id="delForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="do" value="delete"><input type="hidden" name="id" id="delId">
</form>
<script>
var selId = null;
function selRow(row, id) {
    document.querySelectorAll('#vTable tbody tr').forEach(function(t) { t.classList.remove('selected-row'); });
    document.querySelectorAll('.chk').forEach(function(c) { c.checked = false; });
    row.classList.add('selected-row'); var c = row.querySelector('.chk'); if (c) c.checked = true; selId = id;
}
function editSel() { if (!selId) { alert('একটি এন্ট্রি সিলেক্ট করুন!'); return; } location.href = '<?php echo $self; ?>&action=edit&id=' + selId; }
function delSel()  { if (!selId) { alert('একটি এন্ট্রি সিলেক্ট করুন!'); return; }
    if (confirm('এন্ট্রি মুছে ফেলবেন? স্টক আগের অবস্থায় ফিরে যাবে।')) { document.getElementById('delId').value = selId; document.getElementById('delForm').submit(); } }
function printSel(){ if (!selId) { alert('একটি এন্ট্রি সিলেক্ট করুন!'); return; } window.open('<?php echo $self; ?>&action=print&id=' + selId, '_blank'); }
function runFilter() {
    var no = f_no.value.toUpperCase().trim(), re = f_reason.value.toUpperCase().trim(), from = f_from.value, to = f_to.value, sl = 1;
    document.querySelectorAll('.v-row').forEach(function(r) {
        var ok = true, d = r.getAttribute('data-date');
        if (no && r.getAttribute('data-no').toUpperCase().indexOf(no) === -1) ok = false;
        if (re && r.getAttribute('data-reason').toUpperCase().indexOf(re) === -1) ok = false;
        if (from && d < from) ok = false; if (to && d > to) ok = false;
        r.style.display = ok ? '' : 'none'; if (ok) r.querySelector('.sl').textContent = sl++;
    });
}
</script>

<?php } else {
    function item_row($it = null) {
        $v = function ($k, $d = '') use ($it) { return $it ? h($it[$k] ?? $d) : $d; };
        $q = $it ? number_format((float)$it['sqft_qty'], 2, '.', '') : '';
        ?>
        <tr class="it-row" data-boxpcs="<?php echo $v('box_to_pcs', '0'); ?>" data-ratiopcs="<?php echo $v('ratio_pcs', '0'); ?>" data-ratiout="<?php echo $v('ratio_output', '0'); ?>" data-rate="<?php echo $it ? h($it['rate']) : '0'; ?>">
            <td><input type="text" class="fc p-name" list="prod_list" placeholder="Type product to search..." value="<?php echo $v('item_name'); ?>" onchange="pickProduct(this)">
                <input type="hidden" name="product_id[]" class="p-id" value="<?php echo $v('product_id'); ?>"></td>
            <td><input class="fc p-size" readonly value="<?php echo $v('size_name'); ?>"></td>
            <td><input class="fc p-color" readonly value="<?php echo $v('color_name'); ?>"></td>
            <td><input class="fc p-unit" readonly value="<?php echo $v('unit_name'); ?>"></td>
            <td><input class="fc r p-stock" readonly value="0.00"></td>
            <td><input type="number" name="box[]" class="fc r p-box" value="<?php echo $it ? (int)$it['box_qty'] : ''; ?>"></td>
            <td><input type="number" name="pcs[]" class="fc r p-pcs" value="<?php echo $it ? (int)$it['pcs_qty'] : ''; ?>"></td>
            <td><input type="number" name="qty[]" class="fc r p-qty" step="any" value="<?php echo $q; ?>"></td>
            <td><input class="fc r p-rate" readonly value="<?php echo $it ? number_format((float)$it['rate'], 2, '.', '') : '0.00'; ?>"></td>
            <td><input class="fc r p-val" readonly style="font-weight:bold;" value="<?php echo $it ? number_format((float)$it['sqft_qty'] * (float)$it['rate'], 2, '.', '') : '0.00'; ?>"></td>
            <td style="text-align:center;"><button type="button" class="btn-action" style="background:#dc3545;color:#fff;padding:3px 9px;border:none;" onclick="removeRow(this)">-</button></td>
        </tr>
    <?php } ?>
<datalist id="prod_list">
<?php foreach ($products as $p) { ?>
    <option value="<?php echo h($p['item_name']); ?>" data-id="<?php echo (int)$p['id']; ?>" data-size="<?php echo h($p['size_name']); ?>" data-color="<?php echo h($p['color_name']); ?>" data-unit="<?php echo h($p['unit_name']); ?>" data-rate="<?php echo (float)$p['rate']; ?>" data-boxpcs="<?php echo (float)$p['box_to_pcs']; ?>" data-ratiopcs="<?php echo (float)$p['ratio_pcs']; ?>" data-ratiout="<?php echo (float)$p['ratio_output']; ?>">
<?php } ?>
</datalist>

<form method="POST" onsubmit="return validateForm()">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="do" value="save">
<input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
<div class="form-panel">
    <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0;border-bottom:none;">
        <button type="submit" class="btn-action btn-save"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <button type="button" class="btn-action" onclick="location.href='<?php echo $self; ?>'"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</button>
    </div>
    <div class="panel-header" style="color:<?php echo $c['col']; ?>;"><?php echo h($c['t']); ?> — <?php echo $edit_id ? 'Edit (' . h($adj_no_show) . ')' : 'New Entry'; ?></div>
    <div class="panel-body">
        <div class="grid4">
            <div class="fg"><label>Adjustment No</label><input class="fc" readonly value="<?php echo h($adj_no_show); ?>" style="font-weight:bold;"></div>
            <div class="fg"><label>Date *</label><input type="date" name="adj_date" class="fc" required value="<?php echo $ed ? h($ed['adj_date']) : date('Y-m-d'); ?>"></div>
            <div class="fg"><label>Warehouse<?php echo $needWh ? ' *' : ''; ?></label>
                <select name="warehouse_id" id="wh" class="fc" onchange="reloadStocks()">
                    <?php if (!$whs) echo '<option value="0">-- নেই --</option>'; else echo '<option value="">-- নির্বাচন করুন --</option>'; ?>
                    <?php foreach ($whs as $w) echo '<option value="' . $w['id'] . '"' . ($ed && (int)$ed['warehouse_id'] === $w['id'] ? ' selected' : '') . '>' . h($w['name']) . '</option>'; ?>
                </select></div>
            <div class="fg"><label>Reason *</label>
                <select name="reason" class="fc" required>
                    <option value="">-- নির্বাচন করুন --</option>
                    <?php foreach ($c['reasons'] as $rs) echo '<option' . ($ed && $ed['reason'] === $rs ? ' selected' : '') . '>' . h($rs) . '</option>'; ?>
                </select></div>
            <div class="fg" style="grid-column: span 4;"><label>Remarks</label><input type="text" name="remarks" class="fc" maxlength="255" value="<?php echo $ed ? h($ed['remarks']) : ''; ?>"></div>
        </div>

        <label style="font-size:12px;font-weight:bold;color:<?php echo $c['col']; ?>;margin:15px 0 4px;display:block;"><i class="fa-solid fa-boxes-stacked"></i> Product Details</label>
        <table class="grid-table" id="itemTable">
            <thead><tr><th>Product</th><th style="width:75px;">Size</th><th style="width:75px;">Color</th><th style="width:55px;">Unit</th>
                <th class="r" style="width:90px;">Current Stock</th><th style="width:65px;">Box</th><th style="width:65px;">Pcs</th>
                <th style="width:90px;">Qty (SFT)</th><th class="r" style="width:80px;">Rate</th><th class="r" style="width:95px;">Value</th><th style="width:35px;"></th></tr></thead>
            <tbody><?php if ($ed_items) { foreach ($ed_items as $it) item_row($it); } else item_row(); ?></tbody>
            <tfoot><tr class="total-row"><td colspan="7" class="r">Total</td><td class="r" id="t_qty">0.00</td><td></td><td class="r" id="t_val">0.00</td><td></td></tr></tfoot>
        </table>
        <div style="margin-top:8px;"><button type="button" class="btn-action" style="background:#28a745;color:#fff;" onclick="addRow()"><i class="fa-solid fa-plus"></i> Add Row</button></div>
        <?php if ($dir === 'OUT') echo '<div style="font-size:11px;color:#777;margin-top:8px;">স্টকের চেয়ে বেশি বাদ দেওয়া যাবে না।</div>'; ?>
    </div>
</div>
</form>

<script>
var DIR = <?php echo json_encode($dir); ?>, EDIT_NO = <?php echo json_encode($ed ? $ed['adj_no'] : ''); ?>;
function num(v) { v = parseFloat(v); return isNaN(v) ? 0 : v; }
function ratios(row) {
    var bp = num(row.dataset.boxpcs), rp = num(row.dataset.ratiopcs), ro = num(row.dataset.ratiout);
    return { bp: bp, per: (rp > 0 && ro > 0) ? ro / rp : 0 };
}
function pickProduct(inp) {
    var row = inp.closest('tr'), opts = document.getElementById('prod_list').options, val = inp.value.trim();
    row.querySelector('.p-id').value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value !== val) continue;
        var o = opts[i];
        row.querySelector('.p-id').value = o.dataset.id;
        row.querySelector('.p-size').value = o.dataset.size || '';
        row.querySelector('.p-color').value = o.dataset.color || '';
        row.querySelector('.p-unit').value = o.dataset.unit || '';
        row.dataset.boxpcs = o.dataset.boxpcs; row.dataset.ratiopcs = o.dataset.ratiopcs; row.dataset.ratiout = o.dataset.ratiout; row.dataset.rate = o.dataset.rate;
        row.querySelector('.p-rate').value = num(o.dataset.rate).toFixed(2);
        loadStock(row); recalcRow(row); return;
    }
}
function loadStock(row) {
    var pid = row.querySelector('.p-id').value; if (!pid) return;
    var wh = document.getElementById('wh').value || 0;
    fetch('<?php echo $self; ?>&ajax=stock&pid=' + pid + '&wh=' + wh + '&ex=' + encodeURIComponent(EDIT_NO))
      .then(function(r) { return r.json(); }).then(function(d) {
        row.querySelector('.p-stock').value = num(d.stock).toFixed(2);
        row.querySelector('.p-rate').value = num(d.rate).toFixed(2); row.dataset.rate = d.rate;
        recalcRow(row);
      });
}
function reloadStocks() { document.querySelectorAll('.it-row').forEach(loadStock); }
function recalcRow(row) {
    var q = num(row.querySelector('.p-qty').value), rate = num(row.dataset.rate);
    row.querySelector('.p-val').value = (q * rate).toFixed(2);
    var bad = DIR === 'OUT' && q > num(row.querySelector('.p-stock').value) + 0.01;
    row.querySelector('.p-qty').classList.toggle('low-stock', bad);
    row.querySelector('.p-qty').title = bad ? 'স্টকের চেয়ে বেশি!' : '';
    totals();
}
function totals() {
    var q = 0, v = 0;
    document.querySelectorAll('.it-row').forEach(function(r) { q += num(r.querySelector('.p-qty').value); v += num(r.querySelector('.p-val').value); });
    document.getElementById('t_qty').textContent = q.toFixed(2); document.getElementById('t_val').textContent = v.toFixed(2);
}
function fromBoxPcs(row) {
    var r = ratios(row); if (r.per <= 0 || r.bp <= 0) return;
    var tp = (parseInt(row.querySelector('.p-box').value) || 0) * r.bp + (parseInt(row.querySelector('.p-pcs').value) || 0);
    row.querySelector('.p-qty').value = (tp * r.per).toFixed(2); recalcRow(row);
}
function fromQty(row) {
    var r = ratios(row); if (r.per > 0 && r.bp > 0) {
        var tp = Math.round(num(row.querySelector('.p-qty').value) / r.per);
        row.querySelector('.p-box').value = Math.floor(tp / r.bp); row.querySelector('.p-pcs').value = tp % r.bp;
        row.querySelector('.p-qty').value = (tp * r.per).toFixed(2);
    }
    recalcRow(row);
}
document.addEventListener('change', function(e) {
    var c = e.target.classList, row = e.target.closest('.it-row'); if (!row || !c) return;
    if (c.contains('p-box') || c.contains('p-pcs')) fromBoxPcs(row); else if (c.contains('p-qty')) fromQty(row);
});
document.addEventListener('input', function(e) { var row = e.target.closest('.it-row'); if (row && e.target.classList.contains('p-qty')) recalcRow(row); });
function addRow() {
    var tb = document.querySelector('#itemTable tbody'), n = tb.querySelector('tr').cloneNode(true);
    n.querySelectorAll('input').forEach(function(i) { i.value = ''; });
    n.querySelector('.p-stock').value = '0.00'; n.querySelector('.p-rate').value = '0.00'; n.querySelector('.p-val').value = '0.00';
    n.querySelector('.p-qty').classList.remove('low-stock');
    n.dataset.boxpcs = 0; n.dataset.ratiopcs = 0; n.dataset.ratiout = 0; n.dataset.rate = 0;
    tb.appendChild(n);
}
function removeRow(btn) {
    if (document.querySelectorAll('.it-row').length > 1) { btn.closest('tr').remove(); totals(); } else alert('কমপক্ষে একটি রো থাকতে হবে!');
}
function validateForm() {
    var ok = false, over = false;
    document.querySelectorAll('.it-row').forEach(function(r) {
        if (r.querySelector('.p-id').value && num(r.querySelector('.p-qty').value) > 0) ok = true;
        if (r.querySelector('.p-qty').classList.contains('low-stock')) over = true;
    });
    if (!ok) { alert('কমপক্ষে একটি পণ্য ও পরিমাণ দিন!'); return false; }
    if (over) { alert('স্টকের চেয়ে বেশি পরিমাণ দেওয়া হয়েছে!'); return false; }
    return true;
}
window.addEventListener('load', function() { reloadStocks(); document.querySelectorAll('.it-row').forEach(recalcRow); });
</script>
<?php } ?>
</body>
</html>