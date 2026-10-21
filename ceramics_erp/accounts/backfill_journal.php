<?php
// Accounts/backfill_journal.php -- purono Sales Invoice ar GRN gulo journal e post korar one-time tool.
// Bar bar chalaleo safe: same invoice/GRN abar post hole purono entry replace hoy, duplicate hoy na.
include('../auth.php'); require_admin();   // এক-বারের টুল, শুধু admin
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include('accounts_lib.php');

function pk_field($conn, $table) {
    $f = 'id';
    $cols = mysqli_query($conn, "SHOW COLUMNS FROM $table");
    while ($cols && $c = mysqli_fetch_assoc($cols)) { if ($c['Key'] == 'PRI') { $f = $c['Field']; break; } }
    return $f;
}

$report = null;
if (isset($_POST['run']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $report = ['s_ok' => 0, 's_skip' => 0, 'p_ok' => 0, 'p_skip' => 0, 'c_ok' => 0, 'c_keep' => 0, 'zero' => 0, 'skipped' => []];
    $rebuild = !empty($_POST['rebuild_cogs']);   // টিক দিলে সব ইনভয়েসের COGS বর্তমান cost_price দিয়ে নতুন করে

    // ---- Sales invoices
    $pk = pk_field($conn, 'sales_invoice_master');
    $res = mysqli_query($conn, "SELECT * FROM sales_invoice_master ORDER BY $pk ASC");
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $r  = array_change_key_case($row, CASE_LOWER);
        $id = (int)$row[$pk];
        $sum = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(total_qty * rate),0) AS s FROM sales_invoice_details WHERE invoice_id=$id"));
        $gross = (float)$sum['s'] + (float)($r['labor_charge'] ?? 0) - (float)($r['discount_amount'] ?? 0);
        $pay   = (float)($r['payment_amount'] ?? 0);
        $date  = $r['invoice_date'] ?? date('Y-m-d');
        $inv   = $r['invoice_no'];
        $ok = post_journal($conn, 'SALES', $inv, $date, "Sales Invoice $inv", [
            ['1101', $gross, 0, (int)($r['customer_id'] ?? 0)],
            ['4001', 0, $gross]
        ]);
        if ($ok) $report['s_ok']++; else { $report['s_skip']++; $report['skipped'][] = "Sales $inv"; }

        // COGS: যে ইনভয়েসে COGS journal নেই শুধু সেগুলোতে বসে (Rebuild টিক থাকলে সবগুলোতে)
        $has = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM erp_journal_master WHERE voucher_type='COGS' AND ref_no='" . esc($conn, $inv) . "' LIMIT 1"));
        if ($rebuild || !$has) {
            $cg = post_cogs($conn, $inv, $date, $id);
            if ($cg['posted']) $report['c_ok']++;
            $report['zero'] += $cg['zero_cost'];
        } else { $report['c_keep']++; }
    }

    // ---- Purchase GRN
    $res = mysqli_query($conn, "SELECT * FROM erp_purchase_grn ORDER BY id ASC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $g = esc($conn, $r['grn_no']);
        $sum = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(sqft_in * rate),0) AS s FROM erp_stock_ledger WHERE voucher_no='$g' AND voucher_type='PURCHASE'"));
        $gross = (float)$sum['s'] + (float)($r['labor_charge'] ?? 0) - (float)($r['discount_amount'] ?? 0);
        $pay   = (float)($r['payment_amount'] ?? 0);
        $ok = post_journal($conn, 'PURCHASE', $r['grn_no'], $r['grn_date'], "Purchase GRN {$r['grn_no']}", [
            ['1201', $gross, 0],
            ['2001', 0, $gross, (int)$r['supplier_id']]
        ]);
        if ($ok) $report['p_ok']++; else { $report['p_skip']++; $report['skipped'][] = "GRN {$r['grn_no']}"; }
    }

    // Cash/Bank/bKash er lenden erp_fund_ledger theke
    acc_sync_fund_journal($conn);
}

$cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM erp_journal_master"));

acc_head('Backfill Journal');
?>
<div class="title">Backfill Old Vouchers to Accounts</div>

<div class="form-panel"><div class="panel-body">
    <p>Ei tool ager shob <b>Sales Invoice</b> ar <b>Purchase GRN</b> ke journal e post kore, jate General Ledger, Trial Balance, P&amp;L, Balance Sheet e purono data dekha jay.</p>
    <p>Posting rule: Sales = Dr Receivable / Cr Sales Revenue. Purchase = Dr Inventory / Cr Payable.<br>
       Taka ashar/dewar hisab (Cash, Bank, bKash) <b>erp_fund_ledger</b> theke auto ashe (sales payment, collection, payment voucher, transfer).<br>
       Abar chalale duplicate hoy na, purono entry replace hoy.</p>
    <p><b>COGS:</b> Sales Invoice er jonno Dr 5001 Cost of Goods Sold / Cr 1201 Inventory (product er Purchase Price x SFT). Default e shudhu jeishob invoice e COGS nei segulote boshe; niche tick dile shob invoice er COGS ekhonkar Purchase Price diye notun kore hishab hobe.</p>
    <p>Ekhon journal e voucher ache: <b><?php echo (int)$cnt['c']; ?></b> ta</p>
    <form method="POST" onsubmit="return confirm('Shob purono invoice/GRN journal e post korben?');">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="rebuild_cogs" value="1"> Shob invoice er COGS notun kore hishab korun (ekhonkar Purchase Price diye)</label>
        <button class="btn-action btn-green" name="run" value="1"><i class="fa-solid fa-play"></i> Run Backfill</button>
    </form>
</div></div>

<?php if ($report): ?>
    <div class="alert ok">
        Sales posted: <b><?php echo $report['s_ok']; ?></b> | Purchase posted: <b><?php echo $report['p_ok']; ?></b>
        | Skipped: <b><?php echo $report['s_skip'] + $report['p_skip']; ?></b> (amount 0 ba account code nei)
    </div>
    <div class="alert ok">COGS posted: <b><?php echo $report['c_ok']; ?></b> | Already had COGS (unchanged): <b><?php echo $report['c_keep']; ?></b></div>
    <?php if ($report['zero'] > 0): ?>
        <div class="alert err">Sotorkota: <?php echo (int)$report['zero']; ?> ti invoice-line er product er Purchase Price 0, tai COGS kom dhora hoyeche. Product List e Purchase Price din, tarpor Rebuild tick diye abar chalan.</div>
    <?php endif; ?>
    <?php if ($report['skipped']): ?>
        <div class="alert err">Skipped: <?php echo htmlspecialchars(implode(', ', $report['skipped'])); ?></div>
    <?php endif; ?>
<?php endif; ?>
</body></html>