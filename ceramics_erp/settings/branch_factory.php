<?php
include('../auth.php'); require_page_access();
include('settings_lib.php');
st_ensure($conn);
$msg = '';

// Primary key auto-detect
$pk = 'id';
$q = mysqli_query($conn, "SHOW COLUMNS FROM erp_branches");
while ($q && $c = mysqli_fetch_assoc($q)) { if ($c['Key'] == 'PRI') { $pk = $c['Field']; break; } }

// ---------- Save / Update ----------
if (isset($_POST['save_branch'])) {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $code    = trim($_POST['branch_code'] ?? '');
    $name    = trim($_POST['branch_name'] ?? '');
    $type    = in_array($_POST['branch_type'] ?? '', ['Branch', 'Factory', 'Warehouse', 'Showroom']) ? $_POST['branch_type'] : 'Branch';
    $addr    = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    try {
        if ($name === '') throw new Exception('নাম আবশ্যক।');
        $d = $conn->prepare("SELECT COUNT(*) c FROM erp_branches WHERE branch_name=? AND `$pk`<>?");
        $d->bind_param("si", $name, $edit_id); $d->execute();
        if ((int)$d->get_result()->fetch_assoc()['c'] > 0) throw new Exception('এই নামে আগেই একটি ব্রাঞ্চ আছে।');

        if ($edit_id > 0) {
            $o = $conn->prepare("SELECT branch_name FROM erp_branches WHERE `$pk`=?");
            $o->bind_param("i", $edit_id); $o->execute();
            $old = $o->get_result()->fetch_assoc()['branch_name'] ?? '';

            $st = $conn->prepare("UPDATE erp_branches SET branch_code=?, branch_name=?, branch_type=?, address=?, phone=?, status=? WHERE `$pk`=?");
            $st->bind_param("ssssssi", $code, $name, $type, $addr, $phone, $status, $edit_id); $st->execute();

            // Nam bodlale ager invoice/GRN e o notun nam boshe jak, na hole report e ladger ulta-palta hobe
            if ($old !== '' && $old !== $name) {
                foreach (['erp_purchase_grn', 'sales_invoice_master'] as $t) {
                    $u = $conn->prepare("UPDATE $t SET branch_name=? WHERE branch_name=?");
                    $u->bind_param("ss", $name, $old); $u->execute();
                }
            }
            $msg = "<div class='alert ok'>ব্রাঞ্চ আপডেট হয়েছে।</div>";
        } else {
            $st = $conn->prepare("INSERT INTO erp_branches (branch_code, branch_name, branch_type, address, phone, status) VALUES (?,?,?,?,?,?)");
            $st->bind_param("ssssss", $code, $name, $type, $addr, $phone, $status); $st->execute();
            $msg = "<div class='alert ok'>নতুন ব্রাঞ্চ সংরক্ষিত হয়েছে।</div>";
        }
    } catch (Throwable $e) {
        $msg = "<div class='alert err'>ত্রুটি: " . st_h($e->getMessage()) . "</div>";
    }
}

// ---------- Delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $did = (int)($_POST['id'] ?? 0);
    try {
        $b = $conn->prepare("SELECT branch_name FROM erp_branches WHERE `$pk`=?");
        $b->bind_param("i", $did); $b->execute();
        $nm = $b->get_result()->fetch_assoc()['branch_name'] ?? null;
        if ($nm === null) throw new Exception('ব্রাঞ্চ পাওয়া যায়নি।');
        $used = 0;
        foreach (['erp_purchase_grn', 'sales_invoice_master'] as $t) {
            $u = $conn->prepare("SELECT COUNT(*) c FROM $t WHERE branch_name=?");
            $u->bind_param("s", $nm); $u->execute();
            $used += (int)$u->get_result()->fetch_assoc()['c'];
        }
        if ($used > 0) throw new Exception("এই ব্রাঞ্চে $used টি ইনভয়েস/GRN আছে, ডিলিট করা যাবে না। Inactive করে দিন।");
        $x = $conn->prepare("DELETE FROM erp_branches WHERE `$pk`=?");
        $x->bind_param("i", $did); $x->execute();
        $msg = "<div class='alert ok'>ব্রাঞ্চ ডিলিট হয়েছে।</div>";
    } catch (Throwable $e) {
        $msg = "<div class='alert err'>" . st_h($e->getMessage()) . "</div>";
    }
}

$m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(`$pk`) AS m FROM erp_branches"));
$next_code = "BR-" . str_pad(((int)$m['m']) + 1, 3, "0", STR_PAD_LEFT);
$list = mysqli_query($conn, "SELECT * FROM erp_branches ORDER BY `$pk` DESC");

st_head('Branch / Factory Premises');
echo $msg;
?>
<div class="title">Branch / Factory Premises</div>

<div class="control-bar" id="actionBar">
    <button class="btn-action" onclick="showForm('new')"><i class="fa-solid fa-plus" style="color:#28a745"></i> New</button>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#3498db"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#e74c3c"></i> Delete</button>
</div>

<div class="form-panel" id="formPanel" style="display:none">
    <form method="POST">
        <input type="hidden" name="edit_id" id="edit_id" value="0">
        <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0">
            <button type="submit" name="save_branch" class="btn-action btn-green"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button type="button" class="btn-action" onclick="hideForm()"><i class="fa-solid fa-xmark" style="color:#e74c3c"></i> Close</button>
        </div>
        <div class="panel-header" id="formHeader">BRANCH INFO (NEW MODE)</div>
        <div class="panel-body"><div class="excel-grid">
            <div class="fg"><label>Code</label><input class="form-control" name="branch_code" id="f_code" value="<?php echo st_h($next_code); ?>"></div>
            <div class="fg span2"><label>Branch / Factory Name *</label><input class="form-control" name="branch_name" id="f_name" required placeholder="যেমন: Main Factory / Godown"></div>
            <div class="fg"><label>Type</label>
                <select class="form-control" name="branch_type" id="f_type"><option>Branch</option><option>Factory</option><option>Warehouse</option><option>Showroom</option></select></div>
            <div class="fg"><label>Phone</label><input class="form-control" name="phone" id="f_phone"></div>
            <div class="fg"><label>Status</label>
                <select class="form-control" name="status" id="f_status"><option>Active</option><option>Inactive</option></select></div>
            <div class="fg span3"><label>Address</label><input class="form-control" name="address" id="f_addr"></div>
        </div></div>
    </form>
</div>

<div id="gridArea" style="background:#fff;padding:15px;border:1px solid #cddc39;border-radius:4px">
    <table class="grid-table" id="brTable">
        <thead>
            <tr><th style="width:30px"></th><th>Code</th><th>Name</th><th>Type</th><th>Phone</th><th>Address</th><th>Status</th></tr>
            <tr style="background:#f1f5f9"><td></td>
                <td><input class="form-control" style="height:24px" onkeyup="flt(1,this)" placeholder="Code.."></td>
                <td><input class="form-control" style="height:24px" onkeyup="flt(2,this)" placeholder="Name.."></td>
                <td><input class="form-control" style="height:24px" onkeyup="flt(3,this)" placeholder="Type.."></td>
                <td></td><td></td><td></td></tr>
        </thead>
        <tbody>
        <?php while ($r = mysqli_fetch_assoc($list)): $id = (int)$r[$pk]; ?>
            <tr style="cursor:pointer" onclick="pick(this)"
                data-id="<?php echo $id; ?>" data-code="<?php echo st_h($r['branch_code'] ?? ''); ?>" data-name="<?php echo st_h($r['branch_name']); ?>"
                data-type="<?php echo st_h($r['branch_type'] ?? 'Branch'); ?>" data-phone="<?php echo st_h($r['phone'] ?? ''); ?>"
                data-addr="<?php echo st_h($r['address'] ?? ''); ?>" data-status="<?php echo st_h($r['status'] ?? 'Active'); ?>">
                <td><input type="radio" name="sel"></td>
                <td><?php echo st_h($r['branch_code'] ?? ''); ?></td>
                <td style="color:#0056b3;font-weight:500"><?php echo st_h($r['branch_name']); ?></td>
                <td><?php echo st_h($r['branch_type'] ?? 'Branch'); ?></td>
                <td><?php echo st_h($r['phone'] ?? ''); ?></td>
                <td><?php echo st_h($r['address'] ?? ''); ?></td>
                <td><span class="badge <?php echo (($r['status'] ?? 'Active') === 'Active') ? '' : 'off'; ?>"><?php echo st_h($r['status'] ?? 'Active'); ?></span></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
var sel = null, nextCode = <?php echo json_encode($next_code); ?>;
function pick(tr) {
    document.querySelectorAll('#brTable tbody tr').forEach(function (r) { r.classList.remove('selected-row'); });
    tr.classList.add('selected-row'); tr.querySelector('input[type=radio]').checked = true; sel = tr;
}
function showForm(mode) {
    document.getElementById('formPanel').style.display = 'block';
    document.getElementById('actionBar').style.display = 'none';
    document.getElementById('gridArea').style.display = 'none';
    if (mode === 'new') {
        document.getElementById('edit_id').value = 0;
        document.getElementById('f_code').value = nextCode;
        ['f_name', 'f_phone', 'f_addr'].forEach(function (i) { document.getElementById(i).value = ''; });
        document.getElementById('f_type').value = 'Branch';
        document.getElementById('f_status').value = 'Active';
        document.getElementById('formHeader').innerText = 'BRANCH INFO (NEW MODE)';
    }
}
function hideForm() {
    document.getElementById('formPanel').style.display = 'none';
    document.getElementById('actionBar').style.display = 'flex';
    document.getElementById('gridArea').style.display = 'block';
}
function editSel() {
    if (!sel) { alert('একটি ব্রাঞ্চ সিলেক্ট করুন!'); return; }
    showForm('edit');
    var d = sel.dataset;
    document.getElementById('formHeader').innerText = 'BRANCH INFO (EDIT MODE)';
    document.getElementById('edit_id').value = d.id;
    document.getElementById('f_code').value = d.code;
    document.getElementById('f_name').value = d.name;
    document.getElementById('f_type').value = d.type;
    document.getElementById('f_phone').value = d.phone;
    document.getElementById('f_addr').value = d.addr;
    document.getElementById('f_status').value = d.status;
}
function delSel() {
    if (!sel) { alert('একটি ব্রাঞ্চ সিলেক্ট করুন!'); return; }
    if (confirm('আপনি কি নিশ্চিত যে এই ব্রাঞ্চ ডিলিট করতে চান?')) postDelete('branch_factory.php', {id: sel.dataset.id, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
}
function flt(col, inp) {
    var v = inp.value.toUpperCase();
    document.querySelectorAll('#brTable tbody tr').forEach(function (r) {
        r.style.display = r.cells[col].textContent.toUpperCase().indexOf(v) > -1 ? '' : 'none';
    });
}
</script>
<script src="../csrf_delete.js"></script>
</body></html>