<?php
include('../auth.php'); require_page_access();   // আগে লগইন/পারমিশন চেক ছিল না
include('accounts_lib.php');
$to = $_GET['to'] ?? date('Y-m-d');
$data = acc_rows($conn, '1900-01-01', $to, true);

acc_head('Trial Balance');
?>
<div class="title">Trial Balance</div>
<form method="GET" class="control-bar">
    <div class="fg"><label>As on Date</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>"></div>
    <button class="btn-action btn-green"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
    <button type="button" class="btn-action" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</form>

<table class="grid-table">
    <thead><tr><th style="width:40px">SL</th><th>Code</th><th>Account Name</th><th>Type</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
    <tbody>
    <?php
    $sl = 1; $td = 0; $tc = 0;
    foreach ($data as $r) {
        if (abs($r['net']) < 0.005) continue;       // zero balance hide
        $d = $r['net'] > 0 ? $r['net'] : 0;
        $c = $r['net'] < 0 ? -$r['net'] : 0;
        $td += $d; $tc += $c;
        echo "<tr><td>" . $sl++ . "</td><td>" . htmlspecialchars($r['account_code']) . "</td><td style='color:#0056b3'>" . htmlspecialchars($r['account_name']) . "</td><td>" . htmlspecialchars((string)$r['account_type'], ENT_QUOTES) . "</td><td class='r'>" . ($d ? n2($d) : '') . "</td><td class='r'>" . ($c ? n2($c) : '') . "</td></tr>";
    }
    if ($sl == 1) echo "<tr><td colspan='6' style='text-align:center;color:#999;padding:15px'>কোনো ব্যালেন্স নেই।</td></tr>";
    ?>
        <tr class="tot"><td colspan="4" class="r">Grand Total</td><td class="r"><?php echo n2($td); ?></td><td class="r"><?php echo n2($tc); ?></td></tr>
    </tbody>
</table>
<?php if (abs($td - $tc) > 0.01): ?>
    <div class="alert err" style="margin-top:10px">⚠ Trial Balance মিলছে না! পার্থক্য: <?php echo n2(abs($td - $tc)); ?> — Opening balance বা manual entry চেক করুন।</div>
<?php else: ?>
    <div class="alert ok" style="margin-top:10px">✔ Trial Balance মিলেছে (Dr = Cr)।</div>
<?php endif; ?>
</body></html>