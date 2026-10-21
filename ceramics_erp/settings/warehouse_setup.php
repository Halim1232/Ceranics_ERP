<?php
// Settings/warehouse_setup.php -- Warehouse / Godown master (prottek warehouse ekta branch er under)
include('../auth.php'); require_page_access();
include('settings_lib.php');
st_ensure($conn);

// Table na thakle toiri + purono sob stock er jonno default warehouse (id=1)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_code VARCHAR(30) NULL,
    warehouse_name VARCHAR(120) NOT NULL,
    branch_id INT NOT NULL,
    address VARCHAR(255) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$wc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_warehouses"));
if (!(int)$wc['c']) {
    mysqli_query($conn, "INSERT INTO erp_warehouses (id, warehouse_code, warehouse_name, branch_id, address)
        SELECT 1, 'WH-001', CONCAT(branch_name, ' Godown'), id, address FROM erp_branches ORDER BY id LIMIT 1");
}

$msg = '';
$self = 'warehouse_setup.php';

// ---------- SAVE ----------
if (isset($_POST['save_wh'])) {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['warehouse_name'] ?? '');
    $bid    = (int)($_POST['branch_id'] ?? 0);
    $addr   = trim($_POST['address'] ?? '');
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $code   = trim($_POST['warehouse_code'] ?? '');
    if ($name === '' || $bid <= 0) {
        $msg = "<div class='alert err'>ওয়্যারহাউজের নাম ও Branch আবশ্যক।</div>";
    } else {
        if ($id > 0) {
            $st = $conn->prepare("UPDATE erp_warehouses SET warehouse_code=?, warehouse_name=?, branch_id=?, address=?, status=? WHERE id=?");
            $st->bind_param("ssissi", $code, $name, $bid, $addr, $status, $id);
        } else {
            if ($code === '') {
                $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0) AS m FROM erp_warehouses"));
                $code = 'WH-' . str_pad(((int)$m['m']) + 1, 3, '0', STR_PAD_LEFT);
            }
            $st = $conn->prepare("INSERT INTO erp_warehouses (warehouse_code, warehouse_name, branch_id, address, status) VALUES (?,?,?,?,?)");
            $st->bind_param("ssiss", $code, $name, $bid, $addr, $status);
        }
        $st->execute();
        $msg = "<div class='alert ok'>ওয়্যারহাউজ সফলভাবে সংরক্ষিত হয়েছে।</div>";
        $_GET['action'] = 'list';
    }
}

// ---------- DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $did = (int)($_POST['id'] ?? 0);
    $used = 0;
    foreach (["erp_stock_ledger", "erp_purchase_grn", "erp_delivery_challan_master"] as $t) {
        try {
            $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$t` WHERE warehouse_id=$did"));
            $used += (int)$c['c'];
        } catch (Throwable $e) { /* column/table nai */ }
    }
    if ($used > 0) $msg = "<div class='alert err'>এই ওয়্যারহাউজে লেনদেন/স্টক আছে, ডিলিট করা যাবে না। Inactive করুন।</div>";
    else { mysqli_query($conn, "DELETE FROM erp_warehouses WHERE id=$did"); $msg = "<div class='alert ok'>ওয়্যারহাউজ ডিলিট হয়েছে।</div>"; }
    $_GET['action'] = 'list';
}

$action = $_GET['action'] ?? 'list';
$e = ['id' => 0, 'warehouse_code' => '', 'warehouse_name' => '', 'branch_id' => 0, 'address' => '', 'status' => 'Active'];
$showForm = false;
if ($action === 'edit' && isset($_GET['id'])) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_warehouses WHERE id=" . (int)$_GET['id']));
    if ($r) { $e = $r; $showForm = true; }
}
if ($action === 'new') $showForm = true;

$branches = [];
$bq = mysqli_query($conn, "SELECT id, branch_name FROM erp_branches WHERE status='Active' ORDER BY branch_name");
while ($bq && $b = mysqli_fetch_assoc($bq)) $branches[] = $b;

$list = mysqli_query($conn, "SELECT w.*, b.branch_name FROM erp_warehouses w LEFT JOIN erp_branches b ON b.id = w.branch_id ORDER BY b.branch_name, w.warehouse_name");

st_head('Warehouse / Godown Setup');
echo $msg;
?>
<div class="title">Warehouse / Godown Setup</div>

<div class="control-bar">
    <a class="btn-action" href="<?php echo $self; ?>?action=new"><i class="fa-solid fa-plus" style="color:#28a745"></i> New</a>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#3498db"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#e74c3c"></i> Delete</button>
</div>

<?php if ($showForm): ?>
<div class="form-panel">
    <form method="POST" action="<?php echo $self; ?>">
        <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
        <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0">
            <button type="submit" name="save_wh" class="btn-action btn-green"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <a class="btn-action" href="<?php echo $self; ?>"><i class="fa-solid fa-xmark" style="color:#e74c3c"></i> Close</a>
        </div>
        <div class="panel-header">Warehouse Info (<?php echo $e['id'] ? 'Edit' : 'New'; ?>)</div>
        <div class="panel-body"><div class="excel-grid">
            <div class="fg"><label>Warehouse Code</label><input class="form-control" name="warehouse_code" placeholder="auto (WH-002)" value="<?php echo st_h($e['warehouse_code']); ?>"></div>
            <div class="fg span2"><label>Warehouse / Godown Name *</label><input class="form-control" name="warehouse_name" required value="<?php echo st_h($e['warehouse_name']); ?>"></div>
            <div class="fg"><label>Branch *</label>
                <select class="form-control" name="branch_id" required>
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branches as $b) echo '<option value="' . (int)$b['id'] . '"' . ((int)$e['branch_id'] === (int)$b['id'] ? ' selected' : '') . '>' . st_h($b['branch_name']) . '</option>'; ?>
                </select></div>
            <div class="fg span2"><label>Address</label><input class="form-control" name="address" value="<?php echo st_h($e['address']); ?>"></div>
            <div class="fg"><label>Status</label>
                <select class="form-control" name="status"><option <?php echo $e['status'] == 'Active' ? 'selected' : ''; ?>>Active</option><option <?php echo $e['status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        </div></div>
    </form>
</div>
<?php else: ?>
<table class="grid-table" id="whTable">
    <thead><tr><th style="width:30px"></th><th>Code</th><th>Warehouse / Godown</th><th>Branch</th><th>Address</th><th>Status</th></tr></thead>
    <tbody>
    <?php while ($r = mysqli_fetch_assoc($list)): ?>
        <tr style="cursor:pointer" onclick="pick(this, <?php echo (int)$r['id']; ?>)">
            <td><input type="radio" name="sel"></td>
            <td><?php echo st_h($r['warehouse_code']); ?></td>
            <td style="font-weight:bold;color:#0056b3"><?php echo st_h($r['warehouse_name']); ?></td>
            <td><?php echo st_h($r['branch_name'] ?? '-'); ?></td>
            <td><?php echo st_h($r['address']); ?></td>
            <td><span class="badge <?php echo $r['status'] === 'Active' ? '' : 'off'; ?>"><?php echo st_h($r['status']); ?></span></td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>
<div class="alert warn" style="margin-top:12px">Purono sob stock/GRN/challan ager moto <b>WH-001</b> e ache. Notun warehouse banale GRN e oi warehouse select kore stock tulte hobe.</div>
<?php endif; ?>

<script>
var selId = null;
function pick(tr, id) { selId = id; tr.querySelector('input[type=radio]').checked = true; }
function editSel() { if (!selId) { alert('একটি ওয়্যারহাউজ সিলেক্ট করুন!'); return; } location.href = '<?php echo $self; ?>?action=edit&id=' + selId; }
function delSel()  { if (!selId) { alert('একটি ওয়্যারহাউজ সিলেক্ট করুন!'); return; } if (confirm('আপনি কি নিশ্চিত?')) postDelete('<?php echo $self; ?>', {id: selId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'}); }
</script>
<script src="../csrf_delete.js"></script>
</body></html>