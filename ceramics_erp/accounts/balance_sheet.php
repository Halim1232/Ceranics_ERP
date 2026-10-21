<?php
include('../auth.php'); require_page_access();   // আগে লগইন/পারমিশন চেক ছিল না
include('accounts_lib.php');
$to = $_GET['to'] ?? date('Y-m-d');
$data = acc_rows($conn, '1900-01-01', $to, true);

$assets = []; $liab = []; $eq = [];
$ta = 0; $tl = 0; $te = 0; $profit = 0;
foreach ($data as $r) {
    $net = $r['net'];
    if (abs($net) < 0.005) continue;
    switch ($r['account_type']) {
        case 'Asset':     $assets[] = [$r, $net];  $ta += $net;  break;
        case 'Liability': $liab[]   = [$r, -$net]; $tl += -$net; break;
        case 'Equity':    $eq[]     = [$r, -$net]; $te += -$net; break;
        case 'Income':    $profit += -$net; break;
        case 'Expense':   $profit -= $net;  break;
    }
}
$total_eq  = $te + $profit;
$total_le  = $tl + $total_eq;
$diff = $ta - $total_le;

acc_head('Balance Sheet');
?>
<div class="title">Balance Sheet</div>
<form method="GET" class="control-bar">
    <div class="fg"><label>As on Date</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>"></div>
    <button class="btn-action btn-green"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
    <button type="button" class="btn-action" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</form>

<div class="cols">
    <div>
        <table class="grid-table">
            <thead><tr><th colspan="2">Assets (সম্পদ)</th></tr></thead>
            <tbody>
            <?php foreach ($assets as $x) echo "<tr><td>" . htmlspecialchars($x[0]['account_name']) . "</td><td class='r'>" . n2($x[1]) . "</td></tr>";
            if (!$assets) echo "<tr><td colspan='2' style='color:#999'>কোনো সম্পদ নেই</td></tr>"; ?>
            <tr class="tot"><td>Total Assets</td><td class="r"><?php echo n2($ta); ?></td></tr>
            </tbody>
        </table>
    </div>
    <div>
        <table class="grid-table">
            <thead><tr><th colspan="2">Liabilities (দায়)</th></tr></thead>
            <tbody>
            <?php foreach ($liab as $x) echo "<tr><td>" . htmlspecialchars($x[0]['account_name']) . "</td><td class='r'>" . n2($x[1]) . "</td></tr>";
            if (!$liab) echo "<tr><td colspan='2' style='color:#999'>কোনো দায় নেই</td></tr>"; ?>
            <tr class="tot"><td>Total Liabilities</td><td class="r"><?php echo n2($tl); ?></td></tr>
            </tbody>
            <thead><tr><th colspan="2">Equity (মালিকানা স্বত্ব)</th></tr></thead>
            <tbody>
            <?php foreach ($eq as $x) echo "<tr><td>" . htmlspecialchars($x[0]['account_name']) . "</td><td class='r'>" . n2($x[1]) . "</td></tr>"; ?>
            <tr><td>Current Profit / (Loss)</td><td class="r" style="color:<?php echo $profit >= 0 ? '#2e7d32' : '#d32f2f'; ?>"><?php echo n2($profit); ?></td></tr>
            <tr class="tot"><td>Total Equity</td><td class="r"><?php echo n2($total_eq); ?></td></tr>
            <tr class="tot"><td>Total Liabilities + Equity</td><td class="r"><?php echo n2($total_le); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php if (abs($diff) > 0.01): ?>
    <div class="alert err" style="margin-top:10px">⚠ Balance Sheet মিলছে না! পার্থক্য: <?php echo n2(abs($diff)); ?> — Opening balance ঠিক আছে কিনা এবং Trial Balance চেক করুন।</div>
<?php else: ?>
    <div class="alert ok" style="margin-top:10px">✔ Assets = Liabilities + Equity</div>
<?php endif; ?>
</body></html>