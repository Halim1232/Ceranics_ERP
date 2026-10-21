<?php
include('../auth.php'); require_page_access();   // আগে লগইন/পারমিশন চেক ছিল না
include('accounts_lib.php');
$msg = '';

if (isset($_POST['save_account'])) {
    $id     = (int)$_POST['id'];
    $code   = esc($conn, $_POST['account_code']);
    $name   = esc($conn, $_POST['account_name']);
    $type   = esc($conn, $_POST['account_type']);
    $parent = (int)$_POST['parent_id'];
    $isg    = isset($_POST['is_group']) ? 1 : 0;
    $fund   = esc($conn, $_POST['fund_type']);
    $fundsq = ($fund === '' || $isg) ? 'NULL' : "'$fund'";
    $open   = $isg ? 0 : floatval($_POST['opening_balance']);
    $status = esc($conn, $_POST['status']);

    try {
        if ($id > 0) {
            mysqli_query($conn, "UPDATE erp_chart_of_accounts SET account_code='$code', account_name='$name', account_type='$type', parent_id=$parent, is_group=$isg, fund_type=$fundsq, opening_balance=$open, status='$status' WHERE id=$id");
        } else {
            mysqli_query($conn, "INSERT INTO erp_chart_of_accounts (account_code, account_name, account_type, parent_id, is_group, fund_type, opening_balance, status) VALUES ('$code','$name','$type',$parent,$isg,$fundsq,$open,'$status')");
        }
        $msg = "<div class='alert ok'>Account সফলভাবে সংরক্ষিত হয়েছে।</div>";
    } catch (Throwable $e) {
        $msg = "<div class='alert err'>ত্রুটি: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $did = (int)($_POST['id'] ?? 0);
    $used = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_journal_details WHERE account_id=$did"));
    $kids = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_chart_of_accounts WHERE parent_id=$did"));
    if ($used['c'] > 0 || $kids['c'] > 0) {
        $msg = "<div class='alert err'>এই account এ লেনদেন বা sub-account আছে, ডিলিট করা যাবে না। Inactive করুন।</div>";
    } else {
        mysqli_query($conn, "DELETE FROM erp_chart_of_accounts WHERE id=$did");
        $msg = "<div class='alert ok'>Account ডিলিট হয়েছে।</div>";
    }
}

$e = ['id'=>0,'account_code'=>'','account_name'=>'','account_type'=>'Asset','parent_id'=>0,'is_group'=>0,'fund_type'=>'','opening_balance'=>'0.00','status'=>'Active'];
$showForm = false;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_chart_of_accounts WHERE id=" . (int)$_GET['id']));
    if ($r) { $e = $r; $showForm = true; }
}
if (isset($_GET['action']) && $_GET['action'] === 'new') $showForm = true;

$groups = mysqli_query($conn, "SELECT id, account_code, account_name FROM erp_chart_of_accounts WHERE is_group=1 ORDER BY account_code");
$list = mysqli_query($conn, "SELECT a.*, p.account_name AS parent_name FROM erp_chart_of_accounts a LEFT JOIN erp_chart_of_accounts p ON p.id=a.parent_id ORDER BY a.account_code");

acc_head('Chart of Accounts');
echo $msg;
?>
<div class="control-bar">
    <a class="btn-action" href="chart_of_accounts.php?action=new"><i class="fa-solid fa-plus" style="color:#28a745"></i> New</a>
    <button class="btn-action" onclick="editSel()"><i class="fa-solid fa-pen" style="color:#3498db"></i> Edit</button>
    <button class="btn-action" onclick="delSel()"><i class="fa-solid fa-trash" style="color:#e74c3c"></i> Delete</button>
</div>

<?php if ($showForm): ?>
<div class="form-panel">
    <form method="POST" action="chart_of_accounts.php">
        <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
        <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0">
            <button type="submit" name="save_account" class="btn-action btn-green"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <a class="btn-action" href="chart_of_accounts.php"><i class="fa-solid fa-xmark" style="color:#e74c3c"></i> Close</a>
        </div>
        <div class="panel-header">ACCOUNT INFO (<?php echo $e['id'] ? 'EDIT' : 'NEW'; ?> MODE)</div>
        <div class="panel-body"><div class="excel-grid">
            <div class="fg"><label>Account Code *</label><input class="form-control" name="account_code" required value="<?php echo htmlspecialchars($e['account_code']); ?>"></div>
            <div class="fg" style="grid-column:span 2"><label>Account Name *</label><input class="form-control" name="account_name" required value="<?php echo htmlspecialchars($e['account_name']); ?>"></div>
            <div class="fg"><label>Account Type</label>
                <select class="form-control" name="account_type">
                    <?php foreach (['Asset','Liability','Equity','Income','Expense'] as $t) echo "<option " . ($e['account_type'] == $t ? 'selected' : '') . ">$t</option>"; ?>
                </select></div>
            <div class="fg"><label>Parent Group</label>
                <select class="form-control" name="parent_id"><option value="0">-- None --</option>
                    <?php while ($g = mysqli_fetch_assoc($groups)) echo "<option value='{$g['id']}' " . ($e['parent_id'] == $g['id'] ? 'selected' : '') . ">" . htmlspecialchars($g['account_code'] . ' - ' . $g['account_name']) . "</option>"; ?>
                </select></div>
            <div class="fg"><label>Fund Type (Dashboard balance)</label>
                <select class="form-control" name="fund_type"><option value="">-- None --</option>
                    <?php foreach (['Cash','Bank','MFS'] as $t) echo "<option " . ($e['fund_type'] == $t ? 'selected' : '') . ">$t</option>"; ?>
                </select></div>
            <div class="fg"><label>Opening Balance (Asset/Expense = Dr, others = Cr)</label><input class="form-control r" type="number" step="0.01" name="opening_balance" value="<?php echo htmlspecialchars($e['opening_balance']); ?>"></div>
            <div class="fg"><label>Status</label>
                <select class="form-control" name="status"><option <?php echo $e['status']=='Active'?'selected':''; ?>>Active</option><option <?php echo $e['status']=='Inactive'?'selected':''; ?>>Inactive</option></select></div>
            <div class="fg"><label>&nbsp;</label><label><input type="checkbox" name="is_group" <?php echo $e['is_group'] ? 'checked' : ''; ?>> Is Group (heading only, no posting)</label></div>
        </div></div>
    </form>
</div>
<?php else: ?>
<table class="grid-table" id="coaTable">
    <thead>
        <tr><th style="width:30px"></th><th>Code</th><th>Account Name</th><th>Type</th><th>Parent</th><th>Fund</th><th class="r">Opening</th><th>Status</th></tr>
        <tr style="background:#f1f5f9"><td></td>
            <td><input class="form-control" style="height:24px;width:100%" onkeyup="flt(1,this)" placeholder="Code.."></td>
            <td><input class="form-control" style="height:24px;width:100%" onkeyup="flt(2,this)" placeholder="Name.."></td>
            <td><input class="form-control" style="height:24px;width:100%" onkeyup="flt(3,this)" placeholder="Type.."></td>
            <td></td><td></td><td></td><td></td></tr>
    </thead>
    <tbody>
    <?php while ($r = mysqli_fetch_assoc($list)): ?>
        <tr class="<?php echo $r['is_group'] ? 'grp' : ''; ?>" style="cursor:pointer" onclick="pick(this, <?php echo (int)$r['id']; ?>)">
            <td><input type="radio" name="sel" value="<?php echo (int)$r['id']; ?>"></td>
            <td><?php echo htmlspecialchars($r['account_code']); ?></td>
            <td style="<?php echo $r['is_group'] ? '' : 'padding-left:24px;color:#0056b3'; ?>"><?php echo htmlspecialchars($r['account_name']); ?></td>
            <td><?php echo htmlspecialchars((string)$r['account_type'], ENT_QUOTES); ?></td>
            <td><?php echo htmlspecialchars($r['parent_name'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars((string)$r['fund_type'] ?? '-', ENT_QUOTES); ?></td>
            <td class="r"><?php echo $r['is_group'] ? '' : n2($r['opening_balance']); ?></td>
            <td><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES); ?></td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
var selId = null;
function pick(tr, id) { selId = id; tr.querySelector('input[type=radio]').checked = true; }
function editSel() { if (!selId) { alert('একটি account সিলেক্ট করুন!'); return; } location.href = 'chart_of_accounts.php?action=edit&id=' + selId; }
function delSel()  { if (!selId) { alert('একটি account সিলেক্ট করুন!'); return; } if (confirm('আপনি কি নিশ্চিত?')) postDelete('chart_of_accounts.php', {id: selId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'}); }
function flt(col, inp) {
    var v = inp.value.toUpperCase(), rows = document.querySelectorAll('#coaTable tbody tr');
    rows.forEach(function (r) { r.style.display = r.cells[col].textContent.toUpperCase().indexOf(v) > -1 ? '' : 'none'; });
}
</script>
<script src="../csrf_delete.js"></script>
</body></html>
