<?php
// physical_inventory.php -- Physical stock count: sheet toiri -> gune boshano -> difference -> Over/Short adjustment post
include('../auth.php'); require_page_access();
include('../config.php');
include('stock_adj_lib.php');
try { include_once('../Settings/settings_lib.php'); } catch (Throwable $e) {}
adj_ensure($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function opts($conn, $table, $col, $sel = '') {
    $o = '';
    try {
        $q = mysqli_query($conn, "SELECT DISTINCT $col FROM $table WHERE $col != '' ORDER BY $col");
        while ($q && $x = mysqli_fetch_assoc($q)) { $v = h($x[$col]); $o .= "<option value=\"$v\"" . ($sel !== '' && strcasecmp($sel, $x[$col]) === 0 ? ' selected' : '') . ">$v</option>"; }
    } catch (Throwable $e) {}
    return $o;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$whs = adj_warehouses($conn);
$whIds = array_column($whs, 'id');
$needWh = adj_has_wh($conn) && !empty($whs);
$wh_name = function ($id) use ($whs) { foreach ($whs as $w) if ($w['id'] == $id) return $w['name']; return '-'; };
$action = $_GET['action'] ?? 'list';
$self = 'physical_inventory.php';

// ডেটা সেভ (ফিজিক্যাল কাউন্ট) -- save ও post দুই জায়গায় ব্যবহার
function save_counts($conn, $cid, $wh) {
    $ids = $_POST['product_id'] ?? [];
    foreach ($ids as $i => $p) {
        $pid = (int)$p; if ($pid <= 0) continue;
        $bx = trim($_POST['phys_box'][$i] ?? ''); $pc = trim($_POST['phys_pcs'][$i] ?? ''); $sf = trim($_POST['phys_sft'][$i] ?? '');
        $pr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT box_to_pcs, ratio_pcs, ratio_output FROM erp_products WHERE id=$pid"));
        $per = $pr ? adj_per_pcs($pr) : 0; $bp = $pr ? (int)$pr['box_to_pcs'] : 0;
        if ($sf === '' && ($bx !== '' || $pc !== '') && $per > 0 && $bp > 0) $sf = (string)(((float)$bx * $bp + (float)$pc) * $per);
        $sys = round(adj_stock($conn, $pid, $wh), 4);
        $b = $bx === '' ? 'NULL' : (float)$bx; $p2 = $pc === '' ? 'NULL' : (float)$pc; $s = $sf === '' ? 'NULL' : round((float)$sf, 4);
        mysqli_query($conn, "UPDATE erp_physical_count_details SET system_sft=$sys, phys_box=$b, phys_pcs=$p2, phys_sft=$s WHERE count_id=$cid AND product_id=$pid");
    }
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail = function ($msg) { echo "<script>alert(" . json_encode($msg) . "); history.back();</script>"; exit; };
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $fail('অবৈধ অনুরোধ! পেজ রিফ্রেশ করুন।');
    $uid = (int)$_SESSION['user_id']; $do = $_POST['do'] ?? '';

    // ---- নতুন কাউন্ট শিট ----
    if ($do === 'create') {
        $date = $_POST['count_date'] ?? ''; $wh = (int)($_POST['warehouse_id'] ?? 0); $rm = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $fail('সঠিক তারিখ দিন!');
        if ($needWh && !in_array($wh, $whIds)) $fail('ওয়্যারহাউস সিলেক্ট করুন!');
        if (!$needWh) $wh = 0;
        $where = ['1=1'];
        foreach ([['group_name', 'group'], ['category_name', 'category'], ['brand_name', 'brand'], ['size_name', 'size'], ['color_name', 'color']] as $e) {
            $v = trim($_POST[$e[1]] ?? ''); if ($v !== '') $where[] = "p.{$e[0]} = '" . mysqli_real_escape_string($conn, $v) . "'";
        }
        $pq = mysqli_query($conn, "SELECT p.id FROM erp_products p WHERE " . implode(' AND ', $where) . " ORDER BY p.item_name");
        $pids = []; while ($pq && $x = mysqli_fetch_assoc($pq)) $pids[] = (int)$x['id'];
        $only = !empty($_POST['only_stock']);
        $lines = [];
        foreach ($pids as $pid) { $st = round(adj_stock($conn, $pid, $wh), 4); if ($only && abs($st) < 0.005) continue; $lines[$pid] = $st; }
        if (!$lines) $fail('এই ফিল্টারে কোনো পণ্য পাওয়া যায়নি।');
        mysqli_begin_transaction($conn);
        try {
            $no = adj_next_no($conn, 'erp_physical_count', 'count_no', 'PI');
            mysqli_query($conn, "INSERT INTO erp_physical_count (count_no, count_date, warehouse_id, status, remarks, created_by) VALUES ('$no', '$date', " . ($wh > 0 ? $wh : 'NULL') . ", 'Draft', '$rm', $uid)");
            $cid = mysqli_insert_id($conn);
            foreach ($lines as $pid => $st) mysqli_query($conn, "INSERT INTO erp_physical_count_details (count_id, product_id, system_sft) VALUES ($cid, $pid, $st)");
            mysqli_commit($conn);
        } catch (Throwable $e) { mysqli_rollback($conn); $fail('কাউন্ট শিট তৈরি ব্যর্থ হয়েছে।'); }
        echo "<script>window.location.href='$self?action=open&id=$cid';</script>"; exit;
    }

    // ---- ডিলিট (শুধু Draft) ----
    if ($do === 'delete') {
        $id = (int)$_POST['id'];
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM erp_physical_count WHERE id=$id"));
        if (!$m) $fail('পাওয়া যায়নি।');
        if ($m['status'] !== 'Draft') $fail('পোস্ট করা কাউন্ট ডিলিট করা যাবে না। এডজাস্টমেন্ট ঠিক করতে Short/Over Adjust ব্যবহার করুন।');
        mysqli_query($conn, "DELETE FROM erp_physical_count_details WHERE count_id=$id");
        mysqli_query($conn, "DELETE FROM erp_physical_count WHERE id=$id");
        echo "<script>alert('কাউন্ট শিট মুছে ফেলা হয়েছে।'); window.location.href='$self';</script>"; exit;
    }

    // ---- সেভ / পোস্ট ----
    if ($do === 'save' || $do === 'post') {
        $cid = (int)($_POST['count_id'] ?? 0);
        $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_physical_count WHERE id=$cid"));
        if (!$m) $fail('কাউন্ট শিট পাওয়া যায়নি।');
        if ($m['status'] !== 'Draft') $fail('এই কাউন্ট ইতিমধ্যে পোস্ট হয়ে গেছে।');
        $wh = (int)$m['warehouse_id'];

        mysqli_begin_transaction($conn);
        try {
            save_counts($conn, $cid, $wh);
            if ($do === 'save') { mysqli_commit($conn); echo "<script>alert('ড্রাফট সংরক্ষিত হয়েছে।'); window.location.href='$self?action=open&id=$cid';</script>"; exit; }

            // পোস্ট: গণনা করা লাইনের difference বের করে Over/Short adjustment
            $dq = mysqli_query($conn, "SELECT d.*, p.box_to_pcs, p.ratio_pcs, p.ratio_output FROM erp_physical_count_details d JOIN erp_products p ON p.id = d.product_id WHERE d.count_id=$cid AND d.phys_sft IS NOT NULL");
            $diffs = [];
            while ($dq && $d = mysqli_fetch_assoc($dq)) {
                $sys = round((float)$d['system_sft'], 2); $ph = round((float)$d['phys_sft'], 2); $df = round($ph - $sys, 2);
                if (abs($df) >= 0.01) $diffs[] = ['d' => $d, 'diff' => $df];
            }
            $adj_id = 'NULL'; $adj_no = '';
            if ($diffs) {
                $adj_no = adj_next_no($conn, 'erp_stock_adjustment', 'adj_no', 'PA');
                $ne = mysqli_real_escape_string($conn, $adj_no); $cn = mysqli_real_escape_string($conn, $m['count_no']);
                mysqli_query($conn, "INSERT INTO erp_stock_adjustment (adj_no, adj_date, adj_type, warehouse_id, reason, remarks, ref_no, created_by)
                                     VALUES ('$ne', '{$m['count_date']}', 'PHYSICAL', " . ($wh > 0 ? $wh : 'NULL') . ", 'Physical Count', 'Physical Inventory $cn', '$cn', $uid)");
                $adj_id = mysqli_insert_id($conn);
                foreach ($diffs as $x) {
                    $d = $x['d']; $df = $x['diff']; $in = $df > 0; $q = abs($df);
                    list($bx, $ls) = adj_box_loose($q, $d);
                    $rate = adj_rate($conn, (int)$d['product_id']);
                    mysqli_query($conn, "INSERT INTO erp_stock_adjustment_details (adj_id, product_id, direction, box_qty, pcs_qty, sqft_qty, rate)
                                         VALUES ($adj_id, {$d['product_id']}, '" . ($in ? 'IN' : 'OUT') . "', $bx, $ls, $q, $rate)");
                    adj_ledger_insert($conn, (int)$d['product_id'], $in ? 'ADJ_OVER' : 'ADJ_SHORT', $adj_no, $in ? 'IN' : 'OUT', $bx, $ls, $q, $rate, $wh);
                }
            }
            mysqli_query($conn, "UPDATE erp_physical_count SET status='Posted', adj_id=$adj_id WHERE id=$cid");
            mysqli_commit($conn);
        } catch (Throwable $e) { mysqli_rollback($conn); $fail('পোস্ট ব্যর্থ হয়েছে, কোনো ডাটা পরিবর্তন হয়নি।'); }
        $msg = $diffs ? "পোস্ট হয়েছে। এডজাস্টমেন্ট নং: $adj_no (" . count($diffs) . " টি পণ্যে পার্থক্য)" : 'পোস্ট হয়েছে। কোনো পার্থক্য নেই, স্টক অপরিবর্তিত।';
        echo "<script>alert(" . json_encode($msg) . "); window.location.href='$self?action=open&id=$cid';</script>"; exit;
    }
}

// ---------- কাউন্ট ডাটা লোড (open / sheet) ----------
$cnt = null; $lines = [];
if ($action === 'open' || $action === 'sheet') {
    $cid = (int)($_GET['id'] ?? 0);
    $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_physical_count WHERE id=$cid"));
    if (!$cnt) { echo "<script>window.location.href='$self';</script>"; exit; }
    $wh = (int)$cnt['warehouse_id'];
    $lq = mysqli_query($conn, "SELECT d.*, p.item_code, p.item_name, p.size_name, p.color_name, p.unit_name, p.box_to_pcs, p.ratio_pcs, p.ratio_output
                               FROM erp_physical_count_details d JOIN erp_products p ON p.id = d.product_id WHERE d.count_id=$cid ORDER BY p.item_name");
    while ($lq && $x = mysqli_fetch_assoc($lq)) {
        // Draft এ লাইভ সিস্টেম স্টক, Posted এ সংরক্ষিত
        $x['sys'] = ($cnt['status'] === 'Draft') ? round(adj_stock($conn, (int)$x['product_id'], $wh), 2) : round((float)$x['system_sft'], 2);
        $lines[] = $x;
    }
}

// ---------- হেডিং (প্রিন্ট) ----------
$head_name = 'Uttara Builders'; $head_phone = '';
if ($cnt) {
    $bn = ''; foreach ($whs as $w) if ($w['id'] == $cnt['warehouse_id']) $bn = $w['branch_name'] ?? '';
    try { $co = company_info($conn); $br = $bn !== '' ? branch_info($conn, $bn) : null;
          $head_name = trim($br['branch_name'] ?? '') ?: (trim($co['company_name'] ?? '') ?: 'Uttara Builders'); $head_phone = trim($br['phone'] ?? ''); } catch (Throwable $e) {}
}

// ---------- ব্ল্যাংক কাউন্ট শিট প্রিন্ট ----------
if ($action === 'sheet' && $cnt) { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Count Sheet <?php echo h($cnt['count_no']); ?></title>
<style>
 body{font-family:'Segoe UI',Arial,sans-serif;margin:20px;font-size:13px;color:#222}
 .hd{text-align:center;border-bottom:2px solid #2e7d32;padding-bottom:6px;margin-bottom:10px}.hd h2{margin:0;color:#2e7d32;text-transform:uppercase}
 .tt{display:inline-block;background:#2e7d32;color:#fff;padding:2px 14px;border-radius:3px;margin-top:5px;font-weight:bold}
 table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #777;padding:6px}th{background:#e8eef0}.c{text-align:center}
 .sig{display:flex;justify-content:space-between;margin-top:60px}.sig div{border-top:1px solid #333;width:170px;text-align:center;padding-top:5px;font-size:12px}
</style></head><body onload="window.print()">
<div class="hd"><h2><?php echo h($head_name); ?></h2><?php if ($head_phone !== '') echo '<div style="font-size:12px;">Phone: ' . h($head_phone) . '</div>'; ?><div class="tt">Physical Inventory Count Sheet</div></div>
<div style="display:flex;justify-content:space-between;"><span><b>Sheet No:</b> <?php echo h($cnt['count_no']); ?></span><span><b>Warehouse:</b> <?php echo h($wh_name($cnt['warehouse_id'])); ?></span><span><b>Date:</b> <?php echo date('d-M-Y', strtotime($cnt['count_date'])); ?></span></div>
<table><thead><tr><th class="c" style="width:35px">SL</th><th>Product</th><th>Size</th><th>Color</th><th style="width:90px">Box</th><th style="width:90px">Pcs</th><th style="width:110px">Remarks</th></tr></thead><tbody>
<?php $sl = 1; foreach ($lines as $l) { ?>
<tr><td class="c"><?php echo $sl++; ?></td><td><b><?php echo h($l['item_name']); ?></b></td><td><?php echo h($l['size_name']); ?></td><td><?php echo h($l['color_name']); ?></td><td></td><td></td><td></td></tr>
<?php } ?></tbody></table>
<div class="sig"><div>Counted By</div><div>Verified By</div><div>Authorized Signature</div></div>
</body></html>
<?php exit; }

// ---------- তালিকা ----------
$clist = [];
if ($action === 'list') {
    $q = mysqli_query($conn, "SELECT c.*, (SELECT COUNT(*) FROM erp_physical_count_details d WHERE d.count_id=c.id) AS items,
                              (SELECT COUNT(*) FROM erp_physical_count_details d WHERE d.count_id=c.id AND d.phys_sft IS NOT NULL) AS counted,
                              a.adj_no FROM erp_physical_count c LEFT JOIN erp_stock_adjustment a ON a.id = c.adj_id ORDER BY c.id DESC");
    while ($q && $x = mysqli_fetch_assoc($q)) $clist[] = $x;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Physical Inventory</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #333; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; flex-wrap: wrap; align-items: center; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #000; }
    .btn-action:hover { background: #f0f0f0; }
    .btn-save { border: none; background: #28a745; color: #fff; } .btn-post { border: none; background: #e65100; color: #fff; } .btn-print { border: none; background: #2b579a; color: #fff; }
    .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 15px; }
    .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #2e7d32; border-bottom: 1px solid #ced4da; text-transform: uppercase; }
    .panel-body { padding: 15px; }
    .grid5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px 16px; }
    .fg { display: flex; flex-direction: column; gap: 4px; }
    .fg label { font-size: 12px; font-weight: bold; color: #444; }
    .fc { height: 30px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #cddc39; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 7px; text-align: left; border: 1px solid #cddc39; }
    .grid-table td { padding: 4px 6px; border: 1px solid #dee2e6; }
    .grid-table tr.selected-row td { background: #bddeb6 !important; }
    .r { text-align: right; } .c { text-align: center; }
    .cards { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 130px; background: #fff; padding: 10px 14px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; } .card .v { font-size: 18px; font-weight: bold; margin-top: 3px; }
    .over { color: #2e7d32; font-weight: bold; } .short { color: #d32f2f; font-weight: bold; }
    .badge { padding: 3px 9px; border-radius: 4px; font-size: 12px; font-weight: bold; }
    .b-Draft { background: #fff3cd; color: #856404; } .b-Posted { background: #d4edda; color: #155724; }
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; }
    .ro { background: #f5f5f5; }
</style>
</head>
<body>

<?php if ($action === 'list') { ?>
<div class="control-bar">
    <a class="btn-action" href="<?php echo $self; ?>?action=new"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New Count Sheet</a>
    <button class="btn-action" onclick="openSel()"><i class="fa-solid fa-pen-to-square" style="color:#0498db;"></i> Open / Count</button>
    <button class="btn-action" onclick="sheetSel()"><i class="fa-solid fa-print" style="color:#2b579a;"></i> Print Blank Sheet</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#dc3545;"></i> Delete (Draft)</button>
</div>
<div class="form-panel">
    <div class="panel-header">Physical Inventory List</div>
    <div class="panel-body">
        <table class="grid-table" id="cTable">
            <thead><tr><th style="width:30px;"></th><th>Sheet No</th><th>Date</th><th>Warehouse</th><th class="r">Items</th><th class="r">Counted</th><th>Status</th><th>Adjustment No</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($clist as $r) { ?>
                <tr style="cursor:pointer;" onclick="selRow(this, <?php echo (int)$r['id']; ?>, '<?php echo h($r['status']); ?>')">
                    <td class="c"><input type="radio" name="sel"></td>
                    <td style="font-weight:bold;color:#2e7d32;"><?php echo h($r['count_no']); ?></td>
                    <td><?php echo date('d-M-Y', strtotime($r['count_date'])); ?></td>
                    <td><?php echo h($wh_name($r['warehouse_id'])); ?></td>
                    <td class="r"><?php echo (int)$r['items']; ?></td><td class="r"><?php echo (int)$r['counted']; ?></td>
                    <td><span class="badge b-<?php echo h($r['status']); ?>"><?php echo h($r['status']); ?></span></td>
                    <td><?php echo h($r['adj_no'] ?: '-'); ?></td><td><?php echo h($r['remarks']); ?></td>
                </tr>
            <?php } if (!$clist) echo "<tr><td colspan='9' class='c' style='padding:20px;color:#999;'>কোনো কাউন্ট শিট নেই। New চাপুন।</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>
<form id="delForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" id="delId">
</form>
<script>
var selId = null, selSt = '';
function selRow(tr, id, st) {
    document.querySelectorAll('#cTable tbody tr').forEach(function(r) { r.classList.remove('selected-row'); });
    tr.classList.add('selected-row'); tr.querySelector('input').checked = true; selId = id; selSt = st;
}
function openSel() { if (!selId) { alert('একটি কাউন্ট শিট সিলেক্ট করুন!'); return; } location.href = '<?php echo $self; ?>?action=open&id=' + selId; }
function sheetSel() { if (!selId) { alert('একটি কাউন্ট শিট সিলেক্ট করুন!'); return; } window.open('<?php echo $self; ?>?action=sheet&id=' + selId, '_blank'); }
function delSel() {
    if (!selId) { alert('একটি কাউন্ট শিট সিলেক্ট করুন!'); return; }
    if (selSt !== 'Draft') { alert('পোস্ট করা কাউন্ট ডিলিট করা যাবে না।'); return; }
    if (confirm('কাউন্ট শিট মুছে ফেলবেন?')) { document.getElementById('delId').value = selId; document.getElementById('delForm').submit(); }
}
</script>

<?php } elseif ($action === 'new') { ?>
<form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="do" value="create">
<div class="form-panel">
    <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0;border-bottom:none;">
        <button type="submit" class="btn-action btn-save"><i class="fa-solid fa-table-list"></i> Create Count Sheet</button>
        <a class="btn-action" href="<?php echo $self; ?>"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</a>
    </div>
    <div class="panel-header">New Physical Inventory Sheet</div>
    <div class="panel-body">
        <div class="grid5">
            <div class="fg"><label>Count Date *</label><input type="date" name="count_date" class="fc" required value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="fg"><label>Warehouse<?php echo $needWh ? ' *' : ''; ?></label>
                <select name="warehouse_id" class="fc" <?php echo $needWh ? 'required' : ''; ?>>
                    <?php if (!$whs) echo '<option value="0">-- নেই --</option>'; else echo '<option value="">-- নির্বাচন করুন --</option>'; ?>
                    <?php foreach ($whs as $w) echo '<option value="' . $w['id'] . '">' . h($w['name']) . '</option>'; ?>
                </select></div>
            <div class="fg"><label>Product Group</label><select name="group" class="fc"><option value="">All Group</option><?php echo opts($conn, 'erp_product_groups', 'group_name'); ?></select></div>
            <div class="fg"><label>Category</label><select name="category" class="fc"><option value="">All Category</option><?php echo opts($conn, 'erp_product_categories', 'category_name'); ?></select></div>
            <div class="fg"><label>Brand</label><select name="brand" class="fc"><option value="">All Brand</option><?php echo opts($conn, 'erp_product_brands', 'brand_name'); ?></select></div>
            <div class="fg"><label>Size</label><select name="size" class="fc"><option value="">All Size</option><?php echo opts($conn, 'erp_product_sizes', 'size_name'); ?></select></div>
            <div class="fg"><label>Color</label><select name="color" class="fc"><option value="">All Color</option><?php echo opts($conn, 'erp_product_colors', 'color_name'); ?></select></div>
            <div class="fg" style="grid-column: span 2;"><label>Remarks</label><input type="text" name="remarks" class="fc" maxlength="255"></div>
            <div class="fg"><label>&nbsp;</label><label style="font-weight:normal;"><input type="checkbox" name="only_stock" value="1" checked> শুধু স্টক আছে এমন পণ্য</label></div>
        </div>
        <div style="font-size:12px;color:#777;margin-top:10px;">ফিল্টার দিয়ে পণ্যের তালিকা বেছে নিন (যেমন একটি সাইজ বা ব্র্যান্ড করে গুনলে সুবিধা)। শিট তৈরির পর গুনে Box/Pcs বসাবেন।</div>
    </div>
</div>
</form>

<?php } elseif ($action === 'open' && $cnt) {
    $draft = ($cnt['status'] === 'Draft');
    $adjq = $cnt['adj_id'] ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT adj_no FROM erp_stock_adjustment WHERE id=" . (int)$cnt['adj_id'])) : null;
?>
<form method="POST" id="cForm" onsubmit="return false;">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="do" id="doField" value="save">
<input type="hidden" name="count_id" value="<?php echo (int)$cnt['id']; ?>">
<div class="control-bar">
    <?php if ($draft) { ?>
        <button type="button" class="btn-action btn-save" onclick="submitAs('save')"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn-action btn-post" onclick="postIt()"><i class="fa-solid fa-check-double"></i> Post Adjustment</button>
    <?php } ?>
    <a class="btn-action btn-print" href="<?php echo $self; ?>?action=sheet&id=<?php echo (int)$cnt['id']; ?>" target="_blank"><i class="fa-solid fa-print"></i> Blank Count Sheet</a>
    <a class="btn-action" href="<?php echo $self; ?>"><i class="fa-solid fa-xmark" style="color:#e74c3c;"></i> Close</a>
    <span style="margin-left:auto;font-size:13px;"><b><?php echo h($cnt['count_no']); ?></b> | <?php echo date('d-M-Y', strtotime($cnt['count_date'])); ?> | <?php echo h($wh_name($cnt['warehouse_id'])); ?>
        | <span class="badge b-<?php echo h($cnt['status']); ?>"><?php echo h($cnt['status']); ?></span><?php if ($adjq) echo ' | Adj: <b>' . h($adjq['adj_no']) . '</b>'; ?></span>
</div>

<div class="cards">
    <div class="card"><div class="t">Items</div><div class="v"><?php echo count($lines); ?></div></div>
    <div class="card" style="border-left-color:#0288d1;"><div class="t">Counted</div><div class="v" id="c_cnt" style="color:#0288d1;">0</div></div>
    <div class="card"><div class="t">Over (SFT)</div><div class="v over" id="c_over">0.00</div></div>
    <div class="card" style="border-left-color:#d32f2f;"><div class="t">Short (SFT)</div><div class="v short" id="c_short">0.00</div></div>
</div>

<table class="grid-table" id="cntTable">
    <thead><tr><th class="c" style="width:38px;">SL</th><th>Product</th><th>Size</th><th>Color</th>
        <th class="r" style="width:100px;">System (SFT)</th><th class="r" style="width:80px;">Sys Box</th><th class="r" style="width:70px;">Sys Pcs</th>
        <th style="width:85px;">Phys Box</th><th style="width:85px;">Phys Pcs</th><th style="width:100px;">Phys (SFT)</th>
        <th class="r" style="width:100px;">Diff (SFT)</th><th class="c" style="width:90px;">Result</th></tr></thead>
    <tbody>
    <?php $sl = 1; foreach ($lines as $l) {
        list($sbx, $sls) = adj_box_loose($l['sys'], $l); $per = adj_per_pcs($l); $bp = (int)$l['box_to_pcs']; ?>
        <tr class="cr" data-sys="<?php echo $l['sys']; ?>" data-per="<?php echo $per; ?>" data-bp="<?php echo $bp; ?>">
            <td class="c"><?php echo $sl++; ?></td>
            <td style="font-weight:bold;color:#4050b3;"><?php echo h($l['item_name']); ?><input type="hidden" name="product_id[]" value="<?php echo (int)$l['product_id']; ?>"></td>
            <td><?php echo h($l['size_name']); ?></td><td><?php echo h($l['color_name']); ?></td>
            <td class="r" style="font-weight:bold;"><?php echo adj_n2($l['sys']); ?></td><td class="r"><?php echo $sbx; ?></td><td class="r"><?php echo $sls; ?></td>
            <td><input type="number" step="any" name="phys_box[]" class="fc r pb" value="<?php echo $l['phys_box'] !== null ? (float)$l['phys_box'] + 0 : ''; ?>" <?php echo $draft ? '' : 'readonly'; ?>></td>
            <td><input type="number" step="any" name="phys_pcs[]" class="fc r pp" value="<?php echo $l['phys_pcs'] !== null ? (float)$l['phys_pcs'] + 0 : ''; ?>" <?php echo $draft ? '' : 'readonly'; ?>></td>
            <td><input type="number" step="any" name="phys_sft[]" class="fc r ps" value="<?php echo $l['phys_sft'] !== null ? number_format((float)$l['phys_sft'], 2, '.', '') : ''; ?>" <?php echo $draft ? '' : 'readonly'; ?>></td>
            <td class="r df" style="font-weight:bold;">-</td><td class="c rs">-</td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<div style="font-size:11px;color:#777;margin-top:8px;">ফাঁকা রাখা পণ্য গণনায় ধরা হবে না (স্টক বদলাবে না)। Post করলে পার্থক্য অনুযায়ী Over (স্টক বাড়বে) / Short (স্টক কমবে) এডজাস্টমেন্ট নিজে থেকে তৈরি হবে এবং সেই সময়ের সিস্টেম স্টকের সাথে মিলানো হবে।</div>
</form>

<script>
var DRAFT = <?php echo $draft ? 'true' : 'false'; ?>;
function num(v) { v = parseFloat(v); return isNaN(v) ? 0 : v; }
function calcRow(row, src) {
    var per = num(row.dataset.per), bp = num(row.dataset.bp), sys = num(row.dataset.sys);
    var b = row.querySelector('.pb'), p = row.querySelector('.pp'), s = row.querySelector('.ps');
    if (src === 'bp' && per > 0 && bp > 0 && (b.value !== '' || p.value !== '')) s.value = (((parseInt(b.value) || 0) * bp + (parseInt(p.value) || 0)) * per).toFixed(2);
    else if (src === 'sft' && per > 0 && bp > 0 && s.value !== '') {
        var tp = Math.round(num(s.value) / per); b.value = Math.floor(tp / bp); p.value = tp % bp; s.value = (tp * per).toFixed(2);
    }
    var df = row.querySelector('.df'), rs = row.querySelector('.rs');
    if (s.value === '') { df.textContent = '-'; rs.textContent = '-'; df.className = 'r df'; row.dataset.diff = ''; return; }
    var d = Math.round((num(s.value) - sys) * 100) / 100; row.dataset.diff = d;
    df.textContent = (d > 0 ? '+' : '') + d.toFixed(2);
    if (Math.abs(d) < 0.01) { rs.textContent = 'OK'; rs.className = 'c rs'; df.className = 'r df'; }
    else if (d > 0) { rs.textContent = 'OVER'; rs.className = 'c rs over'; df.className = 'r df over'; }
    else { rs.textContent = 'SHORT'; rs.className = 'c rs short'; df.className = 'r df short'; }
}
function summary() {
    var c = 0, o = 0, sh = 0;
    document.querySelectorAll('.cr').forEach(function(r) {
        if (r.dataset.diff === '' || r.dataset.diff === undefined) return; c++;
        var d = num(r.dataset.diff); if (d >= 0.01) o += d; else if (d <= -0.01) sh += -d;
    });
    document.getElementById('c_cnt').textContent = c; document.getElementById('c_over').textContent = o.toFixed(2); document.getElementById('c_short').textContent = sh.toFixed(2);
}
document.querySelectorAll('.cr').forEach(function(row) {
    calcRow(row, '');
    if (!DRAFT) return;
    row.querySelector('.pb').addEventListener('input', function() { calcRow(row, 'bp'); summary(); });
    row.querySelector('.pp').addEventListener('input', function() { calcRow(row, 'bp'); summary(); });
    row.querySelector('.ps').addEventListener('change', function() { calcRow(row, 'sft'); summary(); });
    row.querySelector('.ps').addEventListener('input', function() { calcRow(row, ''); summary(); });
});
summary();
function submitAs(d) { document.getElementById('doField').value = d; var f = document.getElementById('cForm'); f.onsubmit = null; f.submit(); }
function postIt() {
    var c = parseInt(document.getElementById('c_cnt').textContent) || 0;
    if (!c) { alert('কোনো পণ্যের ফিজিক্যাল পরিমাণ বসানো হয়নি!'); return; }
    if (confirm(c + ' টি পণ্যের গণনা অনুযায়ী Over/Short এডজাস্টমেন্ট পোস্ট করবেন? পোস্টের পর আর বদলানো যাবে না।')) submitAs('post');
}
</script>
<?php } ?>
</body>
</html>