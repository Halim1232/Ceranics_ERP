<?php
include('../auth.php'); require_page_access();   // আগে লগইন/পারমিশন চেক ছিল না
include('accounts_lib.php');

$acc_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');
$f = esc($conn, $from); $t = esc($conn, $to);

$accounts = mysqli_query($conn, "SELECT id, account_code, account_name FROM erp_chart_of_accounts WHERE is_group=0 ORDER BY account_code");

$acc = null; $rows = null; $opening = 0;
if ($acc_id > 0) {
    $acc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_chart_of_accounts WHERE id=$acc_id"));
    if ($acc) {
        $ob = (float)$acc['opening_balance'];
        $opening = acc_is_dr($acc['account_type']) ? $ob : -$ob;
        $prior = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(d.debit-d.credit),0) b FROM erp_journal_details d JOIN erp_journal_master m ON m.id=d.journal_id WHERE d.account_id=$acc_id AND m.voucher_date < '$f'"));
        $opening += (float)$prior['b'];
        $rows = mysqli_query($conn, "SELECT m.voucher_date, m.voucher_no, m.voucher_type, m.narration, d.debit, d.credit
                                     FROM erp_journal_details d JOIN erp_journal_master m ON m.id=d.journal_id
                                     WHERE d.account_id=$acc_id AND m.voucher_date BETWEEN '$f' AND '$t'
                                     ORDER BY m.voucher_date, m.id, d.id");
    }
}
function drcr($v) { return n2(abs($v)) . ($v >= 0 ? ' Dr' : ' Cr'); }

acc_head('General Ledger');
?>
<div class="title">General Ledger</div>
<form method="GET" class="control-bar">
    <div class="fg" style="min-width:280px"><label>Account</label>
        <select name="account_id" class="form-control" required>
            <option value="">-- Select Account --</option>
            <?php while ($a = mysqli_fetch_assoc($accounts)) echo "<option value='{$a['id']}' " . ($acc_id == $a['id'] ? 'selected' : '') . ">" . htmlspecialchars($a['account_code'] . ' - ' . $a['account_name']) . "</option>"; ?>
        </select></div>
    <div class="fg"><label>From</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>"></div>
    <div class="fg"><label>To</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>"></div>
    <button class="btn-action btn-green"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
    <button type="button" class="btn-action" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</form>

<?php if ($acc): ?>
<div style="margin-bottom:8px;font-weight:bold"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?>
    <span style="font-weight:normal;color:#666">(<?php echo date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to)); ?>)</span></div>
<table class="grid-table">
    <thead><tr><th style="width:40px">SL</th><th>Date</th><th>Voucher No</th><th>Type</th><th>Narration</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead>
    <tbody>
        <tr class="grp"><td colspan="7" class="r">Opening Balance</td><td class="r"><?php echo drcr($opening); ?></td></tr>
        <?php
        $sl = 1; $bal = $opening; $td = 0; $tc = 0;
        while ($rows && $r = mysqli_fetch_assoc($rows)) {
            $bal += (float)$r['debit'] - (float)$r['credit'];
            $td += (float)$r['debit']; $tc += (float)$r['credit'];
            echo "<tr><td>" . $sl++ . "</td><td>" . date('d-M-Y', strtotime($r['voucher_date'])) . "</td><td style='color:#0056b3;font-weight:bold'>" . htmlspecialchars($r['voucher_no']) . "</td><td>" . htmlspecialchars($r['voucher_type']) . "</td><td>" . htmlspecialchars($r['narration'] ?? '') . "</td><td class='r'>" . ($r['debit'] > 0 ? n2($r['debit']) : '') . "</td><td class='r'>" . ($r['credit'] > 0 ? n2($r['credit']) : '') . "</td><td class='r'>" . drcr($bal) . "</td></tr>";
        }
        if ($sl == 1) echo "<tr><td colspan='8' style='text-align:center;color:#999;padding:15px'>এই সময়ে কোনো লেনদেন নেই।</td></tr>";
        ?>
        <tr class="tot"><td colspan="5" class="r">Total / Closing</td><td class="r"><?php echo n2($td); ?></td><td class="r"><?php echo n2($tc); ?></td><td class="r"><?php echo drcr($bal); ?></td></tr>
    </tbody>
</table>
<?php endif; ?>
</body></html>