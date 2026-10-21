<?php
include('../auth.php'); require_page_access();   // আগে লগইন/পারমিশন চেক ছিল না
include('accounts_lib.php');
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$data = acc_rows($conn, $from, $to, false);   // period only, no opening

$inc = []; $exp = []; $ti = 0; $te = 0;
foreach ($data as $r) {
    if ($r['account_type'] === 'Income')  { $v = -$r['net']; if (abs($v) > 0.005) { $inc[] = [$r, $v]; $ti += $v; } }
    if ($r['account_type'] === 'Expense') { $v =  $r['net']; if (abs($v) > 0.005) { $exp[] = [$r, $v]; $te += $v; } }
}
$profit = $ti - $te;
// Gross Profit = Sales Revenue (4001) - COGS (5001)
$gp_sales = 0; $gp_cogs = 0;
foreach ($inc as $x) { if ($x[0]['account_code'] === '4001') $gp_sales += $x[1]; }
foreach ($exp as $x) { if ($x[0]['account_code'] === '5001') $gp_cogs += $x[1]; }
$gross_profit = $gp_sales - $gp_cogs;

acc_head('Profit & Loss Statement');
?>
<div class="title">Profit &amp; Loss Statement</div>
<form method="GET" class="control-bar">
    <div class="fg"><label>From</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>"></div>
    <div class="fg"><label>To</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>"></div>
    <button class="btn-action btn-green"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
    <button type="button" class="btn-action" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</form>

<div class="cols">
    <div>
        <table class="grid-table">
            <thead><tr><th colspan="2">Income (আয়)</th></tr></thead>
            <tbody>
            <?php foreach ($inc as $x) echo "<tr><td>" . htmlspecialchars($x[0]['account_name']) . "</td><td class='r'>" . n2($x[1]) . "</td></tr>";
            if (!$inc) echo "<tr><td colspan='2' style='color:#999'>কোনো আয় নেই</td></tr>"; ?>
            <tr class="tot"><td>Total Income</td><td class="r"><?php echo n2($ti); ?></td></tr>
            </tbody>
        </table>
    </div>
    <div>
        <table class="grid-table">
            <thead><tr><th colspan="2">Expense (ব্যয়)</th></tr></thead>
            <tbody>
            <?php foreach ($exp as $x) echo "<tr><td>" . htmlspecialchars($x[0]['account_name']) . "</td><td class='r'>" . n2($x[1]) . "</td></tr>";
            if (!$exp) echo "<tr><td colspan='2' style='color:#999'>কোনো ব্যয় নেই</td></tr>"; ?>
            <tr class="tot"><td>Total Expense</td><td class="r"><?php echo n2($te); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:15px;padding:10px 14px;background:#fff;border:1px solid #cddc39;border-radius:4px;font-size:14px;">
    Sales Revenue: <b><?php echo n2($gp_sales); ?></b> &minus; COGS: <b><?php echo n2($gp_cogs); ?></b> = Gross Profit:
    <b style="color:<?php echo $gross_profit >= 0 ? '#2e7d32' : '#d32f2f'; ?>"><?php echo n2($gross_profit); ?></b>
</div>
<div style="margin-top:15px;padding:14px;background:#fff;border:2px solid <?php echo $profit >= 0 ? '#2e7d32' : '#d32f2f'; ?>;border-radius:4px;font-size:16px;font-weight:bold;color:<?php echo $profit >= 0 ? '#2e7d32' : '#d32f2f'; ?>">
    <?php echo $profit >= 0 ? 'Net Profit (নিট লাভ)' : 'Net Loss (নিট ক্ষতি)'; ?>: <?php echo n2(abs($profit)); ?> BDT
</div>
</body></html>