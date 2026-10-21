<?php
include('../auth.php'); require_page_access();
include('../config.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function cust_label($name, $addr, $phone) {
    $parts = array_filter([trim((string)$addr), trim((string)$phone)]);
    return $name . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '');
}
function bal_cr_style($b) { return $b < -0.004 ? 'color:#d32f2f;' : ''; }   // Cr balance lal
function bal_cr_class($b) { return $b < -0.004 ? 'cr-bal' : ''; }   // B&W print e Cr underline hobe
function bal_txt($b) { return number_format(abs($b), 2) . ($b < 0 ? ' Cr' : ' Dr'); }

// টেবিলের কলাম নাম অটো-ডিটেক্ট (টেবিল না থাকলে খালি অ্যারে)
function table_cols($conn, $t) {
    $c = [];
    try {
        $q = mysqli_query($conn, "SHOW COLUMNS FROM `$t`");
        while ($q && $r = mysqli_fetch_assoc($q)) $c[strtolower($r['Field'])] = $r['Field'];
    } catch (Exception $e) {}
    return $c;
}
function pick($cols, $cands) { foreach ($cands as $x) if (isset($cols[$x])) return $cols[$x]; return null; }

// ---------- ফিল্টার ----------
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$customer    = trim($_GET['customer'] ?? '');
$customer_id = (int)($_GET['customer_id'] ?? 0);
$msg = ''; $warn = '';

if ($customer_id <= 0 && $customer !== '') {
    $like = '%' . $customer . '%';
    $st = $conn->prepare("SELECT id FROM erp_customers WHERE customer_name LIKE ? OR address LIKE ? OR phone LIKE ? LIMIT 6");
    $st->bind_param("sss", $like, $like, $like);
    $st->execute();
    $ids = [];
    $rs = $st->get_result();
    while ($x = $rs->fetch_assoc()) $ids[] = (int)$x['id'];
    $st->close();
    if (count($ids) == 1)      $customer_id = $ids[0];
    elseif (count($ids) > 1)   $msg = 'একাধিক কাস্টমার মিলেছে, অনুগ্রহ করে তালিকা থেকে একজনকে সিলেক্ট করুন।';
    else                       $msg = 'কোনো কাস্টমার পাওয়া যায়নি।';
}
if ($customer_id <= 0 && $customer === '') $msg = 'স্টেটমেন্ট দেখতে একজন কাস্টমার সিলেক্ট করুন।';

$cust = null; $ledger = []; $opening = 0; $T = ['dr' => 0, 'cr' => 0]; $closing = 0;

if ($customer_id > 0) {
    $st = $conn->prepare("SELECT customer_code, customer_name, address, phone, opening_due FROM erp_customers WHERE id = ?");
    $st->bind_param("i", $customer_id);
    $st->execute();
    $cust = $st->get_result()->fetch_assoc();
    $st->close();
}

if ($cust) {
    $customer = cust_label($cust['customer_name'], $cust['address'], $cust['phone']);

    // ---- Collection Voucher (erp_vouchers) কলাম ডিটেক্ট ----
    $vc = table_cols($conn, 'erp_vouchers');
    $vId   = pick($vc, ['id', 'voucher_id']);
    $vNo   = pick($vc, ['voucher_no', 'vch_no', 'voucher_number', 'vno']);
    $vDate = pick($vc, ['voucher_date', 'txn_date', 'vch_date', 'date']);
    $vType = pick($vc, ['voucher_type', 'vch_type', 'type', 'vtype']);
    $vParty= pick($vc, ['party_id', 'customer_id', 'ledger_id', 'head_id']);
    $vAcc  = pick($vc, ['account_id', 'fund_account_id', 'cash_account_id', 'bank_account_id']);
    $vAmt  = pick($vc, ['amount', 'total_amount', 'credit_amount']);
    $vRem  = pick($vc, ['remarks', 'narration', 'note']);
    $vOk   = ($vNo && $vDate && $vParty && $vAmt);

    $ac = table_cols($conn, 'erp_fund_accounts');
    $aId   = pick($ac, ['id', 'account_id']);
    $aName = pick($ac, ['account_name', 'name', 'title']);
    $accJoin = ($vAcc && $aId && $aName) ? "LEFT JOIN erp_fund_accounts a ON a.`$aId` = v.`$vAcc`" : '';
    $accSel  = $accJoin ? "a.`$aName`" : "''";

    $collCond = "v.`$vNo` LIKE 'CV%'";
    if ($vType) $collCond = "($collCond OR LOWER(v.`$vType`) LIKE '%collection%' OR LOWER(v.`$vType`) LIKE '%receipt%')";

    if (!$vOk) {
        $warn = 'Collection Voucher-er হিসাব লেজারে আসছে না: erp_vouchers টেবিলের কলাম মেলেনি। পাওয়া কলাম: ' . ($vc ? implode(', ', $vc) : '(টেবিল পাওয়া যায়নি)');
    }

    // ---- শুরুর ব্যালেন্স ----
    $st = $conn->prepare("SELECT COALESCE(SUM(IFNULL(t.total,0) + m.labor_charge - m.discount_amount - m.payment_amount),0) AS due
        FROM sales_invoice_master m
        LEFT JOIN (SELECT invoice_id, SUM(total_qty * rate) AS total FROM sales_invoice_details GROUP BY invoice_id) t ON t.invoice_id = m.invoice_id
        WHERE m.customer_id = ? AND m.invoice_date < ?");
    $st->bind_param("is", $customer_id, $from);
    $st->execute();
    $before = (float)$st->get_result()->fetch_assoc()['due'];
    $st->close();

    $collBefore = 0;
    if ($vOk) {
        $st = $conn->prepare("SELECT COALESCE(SUM(v.`$vAmt`),0) AS s FROM erp_vouchers v WHERE v.`$vParty` = ? AND v.`$vDate` < ? AND $collCond");
        $st->bind_param("is", $customer_id, $from);
        $st->execute();
        $collBefore = (float)$st->get_result()->fetch_assoc()['s'];
        $st->close();
    }
    // Sales Return (from er age): credit = total - discount, refund = customer ke taka ferot (debit)
    $retBefore = 0; $refBefore = 0;
    try {
        $st = $conn->prepare("SELECT COALESCE(SUM(total_amount - discount_amount),0) AS c, COALESCE(SUM(refund_amount),0) AS f FROM erp_sales_return WHERE customer_id = ? AND return_date < ?");
        $st->bind_param("is", $customer_id, $from);
        $st->execute();
        $rb = $st->get_result()->fetch_assoc();
        $st->close();
        $retBefore = (float)$rb['c']; $refBefore = (float)$rb['f'];
    } catch (Throwable $e) { /* erp_sales_return nai */ }
    $opening = (float)$cust['opening_due'] + $before - $collBefore - $retBefore + $refBefore;

    // ---- ইভেন্ট সংগ্রহ ----
    $ev = [];

    // ইনভয়েস (বিক্রয় + ইনভয়েসের সাথে নেওয়া পেমেন্ট)
    $st = $conn->prepare("SELECT m.invoice_id, m.invoice_no, m.invoice_date, m.remarks, m.labor_charge, m.discount_amount, m.payment_amount,
               IFNULL(t.total,0) AS items_total, IFNULL(t.qty,0) AS qty
        FROM sales_invoice_master m
        LEFT JOIN (SELECT invoice_id, SUM(total_qty * rate) AS total, SUM(total_qty) AS qty FROM sales_invoice_details GROUP BY invoice_id) t ON t.invoice_id = m.invoice_id
        WHERE m.customer_id = ? AND m.invoice_date BETWEEN ? AND ?
        ORDER BY m.invoice_date ASC, m.invoice_id ASC");
    $st->bind_param("iss", $customer_id, $from, $to);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
        $gross = (float)$r['items_total'] + (float)$r['labor_charge'] - (float)$r['discount_amount'];
        $paid  = (float)$r['payment_amount'];
        $iid   = (int)$r['invoice_id'];
        $ev[] = ['date' => $r['invoice_date'], 'ord' => 0, 'sub' => $iid * 2, 'ref' => $r['invoice_no'],
                 'desc' => 'Sales Invoice (' . number_format($r['qty'], 2) . ' SFT)' . ($r['remarks'] ? ' - ' . $r['remarks'] : ''),
                 'dr' => $gross, 'cr' => 0];
        if ($paid > 0) {
            // ইনভয়েসের পেমেন্ট লাইন (Cash / bKash / Bank আলাদা আলাদা) fund ledger থেকে
            $lines = [];
            if ($aId && $aName) {
                try {
                    $s2 = $conn->prepare("SELECT a.`$aName` AS n, l.amount AS amt FROM erp_fund_ledger l JOIN erp_fund_accounts a ON a.`$aId` = l.account_id WHERE l.ref_type = 'SALE' AND l.ref_no = ?");
                    $s2->bind_param("s", $r['invoice_no']);
                    $s2->execute();
                    $rs2 = $s2->get_result();
                    while ($x = $rs2->fetch_assoc()) $lines[] = $x;
                    $s2->close();
                } catch (Exception $e) {}
            }
            $lsum = 0; foreach ($lines as $x) $lsum += (float)$x['amt'];
            if ($lines && abs($lsum - $paid) < 0.01) {
                foreach ($lines as $x) {
                    $ev[] = ['date' => $r['invoice_date'], 'ord' => 0, 'sub' => $iid * 2 + 1, 'ref' => $r['invoice_no'],
                             'desc' => 'Payment Received (' . $x['n'] . ')', 'dr' => 0, 'cr' => (float)$x['amt']];
                }
            } else {
                $ev[] = ['date' => $r['invoice_date'], 'ord' => 0, 'sub' => $iid * 2 + 1, 'ref' => $r['invoice_no'],
                         'desc' => 'Payment Received (at Invoice)', 'dr' => 0, 'cr' => $paid];
            }
        }
    }
    $st->close();

    // Collection Voucher
    if ($vOk) {
        $orderId = $vId ? ", v.`$vId`" : '';
        $remSel  = $vRem ? "v.`$vRem`" : "''";
        $st = $conn->prepare("SELECT v.`$vNo` AS vno, v.`$vDate` AS vdate, v.`$vAmt` AS amt, $accSel AS acct, $remSel AS rem " . ($vId ? ", v.`$vId` AS vid" : ", 0 AS vid") . "
            FROM erp_vouchers v $accJoin
            WHERE v.`$vParty` = ? AND v.`$vDate` BETWEEN ? AND ? AND $collCond
            ORDER BY v.`$vDate` ASC$orderId");
        $st->bind_param("iss", $customer_id, $from, $to);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $ev[] = ['date' => substr($r['vdate'], 0, 10), 'ord' => 1, 'sub' => (int)$r['vid'], 'ref' => $r['vno'],
                     'desc' => 'Payment Received (' . ($r['acct'] !== '' && $r['acct'] !== null ? $r['acct'] : 'Collection') . ')' . ($r['rem'] ? ' - ' . $r['rem'] : ''),
                     'dr' => 0, 'cr' => (float)$r['amt']];
        }
        $st->close();
    }

    // Sales Return: Credit row (Return Credit) + Refund row (Debit, account onujayi)
    try {
        $st = $conn->prepare("SELECT id, return_no, return_date, invoice_no, total_amount, discount_amount, refund_amount, remarks
            FROM erp_sales_return WHERE customer_id = ? AND return_date BETWEEN ? AND ? ORDER BY return_date ASC, id ASC");
        $st->bind_param("iss", $customer_id, $from, $to);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $rid    = (int)$r['id'];
            $credit = (float)$r['total_amount'] - (float)$r['discount_amount'];
            $refund = (float)$r['refund_amount'];
            $desc   = 'Sales Return (Invoice ' . $r['invoice_no'] . ')'
                    . ((float)$r['discount_amount'] > 0 ? ' [Discount ' . number_format($r['discount_amount'], 2) . ']' : '')
                    . ($r['remarks'] ? ' - ' . $r['remarks'] : '');
            $ev[] = ['date' => $r['return_date'], 'ord' => 2, 'sub' => $rid * 2, 'ref' => $r['return_no'],
                     'desc' => $desc, 'dr' => 0, 'cr' => $credit];
            if ($refund > 0) {
                $lines = [];
                if ($aId && $aName) {
                    try {
                        $s2 = $conn->prepare("SELECT a.`$aName` AS n, l.amount AS amt FROM erp_fund_ledger l JOIN erp_fund_accounts a ON a.`$aId` = l.account_id WHERE l.ref_type = 'SALE_RET' AND l.ref_no = ?");
                        $s2->bind_param("s", $r['return_no']);
                        $s2->execute();
                        $rs2 = $s2->get_result();
                        while ($x = $rs2->fetch_assoc()) $lines[] = $x;
                        $s2->close();
                    } catch (Throwable $e) {}
                }
                $lsum = 0; foreach ($lines as $x) $lsum += (float)$x['amt'];
                if ($lines && abs($lsum - $refund) < 0.01) {
                    foreach ($lines as $x) {
                        $ev[] = ['date' => $r['return_date'], 'ord' => 2, 'sub' => $rid * 2 + 1, 'ref' => $r['return_no'],
                                 'desc' => 'Refund Paid (' . $x['n'] . ')', 'dr' => (float)$x['amt'], 'cr' => 0];
                    }
                } else {
                    $ev[] = ['date' => $r['return_date'], 'ord' => 2, 'sub' => $rid * 2 + 1, 'ref' => $r['return_no'],
                             'desc' => 'Refund Paid', 'dr' => $refund, 'cr' => 0];
                }
            }
        }
        $st->close();
    } catch (Throwable $e) { /* erp_sales_return nai */ }

    usort($ev, function ($a, $b) { return [$a['date'], $a['ord'], $a['sub']] <=> [$b['date'], $b['ord'], $b['sub']]; });

    $bal = $opening;
    foreach ($ev as $e) {
        $bal += $e['dr'] - $e['cr'];
        $T['dr'] += $e['dr']; $T['cr'] += $e['cr'];
        $e['bal'] = $bal;
        $ledger[] = $e;
    }
    $closing = $bal;
}

$cl = [];
$cq = mysqli_query($conn, "SELECT id, customer_name, address, phone FROM erp_customers ORDER BY customer_name ASC");
while ($cq && $c = mysqli_fetch_assoc($cq)) $cl[] = $c;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Ledger Statement</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; color: #333; }
    .filter-panel { background: #fff; padding: 12px; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 12px; }
    .filter-grid { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 10px 15px; align-items: flex-end; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: #fff; }
    .btn { padding: 6px 15px; font-weight: bold; border: none; color: #fff; cursor: pointer; border-radius: 4px; height: 30px; font-size: 12px; }
    .btn-show { background: #2e7d32; } .btn-print { background: #dc3545; }
    .notice { background: #fff8e1; border: 1px solid #ffe082; padding: 12px; border-radius: 4px; color: #6d4c00; margin-bottom: 12px; }
    .cust-box { background: #fff; border: 1px solid #cbd5e1; border-left: 4px solid #2e7d32; border-radius: 4px; padding: 10px 15px; margin-bottom: 12px; font-size: 13px; line-height: 1.6; }
    .cards { display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
    .card { flex: 1; min-width: 150px; background: #fff; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #2e7d32; }
    .card .t { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; }
    .card .v { font-size: 18px; font-weight: bold; margin-top: 4px; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid #999; background: #fff; }
    .grid-table th { background: #dbebe7; padding: 8px; text-align: left; border: 1px solid #999; }
    .grid-table td { padding: 6px 8px; border: 1px solid #ccc; }
    .grid-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .r { text-align: right; } .c { text-align: center; }
    .open-row td { background: #fff3e0 !important; font-weight: bold; }
    .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
    .print-head { display: none; text-align: center; margin-bottom: 10px; }
    @media print {
        .filter-panel, .notice { display: none; }
        body { background: #fff; padding: 0; }
        .print-head { display: block; }
        /* B&W print: Cr balance kalo, bold ar underline hoye alada dekhabe */
        .cr-bal { color: #000 !important; font-weight: bold !important; text-decoration: underline; }
    }
</style>
</head>
<body>

<div class="print-head">
    <h2 style="margin:0;color:#2e7d32;">UTTARA BUILDERS</h2>
    <div style="font-weight:bold;">Customer Ledger Statement: <?php echo date('d-M-Y', strtotime($from)); ?> to <?php echo date('d-M-Y', strtotime($to)); ?></div>
</div>

<datalist id="dl_customer">
<?php foreach ($cl as $c) {
    echo '<option data-id="' . (int)$c['id'] . '" value="' . h(cust_label($c['customer_name'], $c['address'], $c['phone'])) . '">';
} ?>
</datalist>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Customer</label>
            <input type="text" name="customer" id="customer_search" class="fc" list="dl_customer" autocomplete="off" placeholder="Name / Address / Mobile" value="<?php echo h($customer); ?>" oninput="syncCustomer()">
            <input type="hidden" name="customer_id" id="customer_id" value="<?php echo $customer_id ? $customer_id : ''; ?>"></div>
        <div class="fg"><label>From Date</label><input type="date" name="from" class="fc" value="<?php echo h($from); ?>"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to" class="fc" value="<?php echo h($to); ?>"></div>
        <div class="fg" style="flex-direction:row; gap:6px;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</form>

<?php if ($warn) { ?><div class="notice"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo h($warn); ?></div><?php } ?>

<?php if (!$cust) { ?>
    <div class="notice"><i class="fa-solid fa-circle-info"></i> <?php echo h($msg); ?></div>
<?php } else { ?>

<div class="cust-box">
    <b style="font-size:15px;color:#2e7d32;"><?php echo h($cust['customer_name']); ?></b>
    <span style="color:#777;">(<?php echo h($cust['customer_code']); ?>)</span><br>
    <i class="fa-solid fa-location-dot"></i> <?php echo h($cust['address'] ?: '-'); ?> &nbsp;|&nbsp;
    <i class="fa-solid fa-phone"></i> <?php echo h($cust['phone'] ?: '-'); ?>
</div>

<div class="cards">
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Opening Balance</div><div class="v <?php echo bal_cr_class($opening); ?>" style="color:#ef6c00;<?php echo bal_cr_style($opening); ?>"><?php echo bal_txt($opening); ?></div></div>
    <div class="card" style="border-left-color:#0056b3;"><div class="t">Total Debit (Sales + Refund)</div><div class="v" style="color:#0056b3;"><?php echo number_format($T['dr'], 2); ?></div></div>
    <div class="card" style="border-left-color:#28a745;"><div class="t">Total Credit (Received + Return)</div><div class="v" style="color:#28a745;"><?php echo number_format($T['cr'], 2); ?></div></div>
    <div class="card" style="border-left-color:#d32f2f;"><div class="t">Closing Balance</div><div class="v" style="color:#d32f2f;"><?php echo bal_txt($closing); ?></div></div>
</div>

<table class="grid-table">
    <thead>
        <tr>
            <th class="c" style="width:45px;">SL</th><th style="width:100px;">Date</th><th style="width:130px;">Ref No</th><th>Particulars</th>
            <th class="r" style="width:120px;">Debit</th><th class="r" style="width:120px;">Credit</th><th class="r" style="width:130px;">Balance</th>
        </tr>
    </thead>
    <tbody>
        <tr class="open-row">
            <td colspan="4">Opening Balance (<?php echo date('d-M-Y', strtotime($from)); ?> এর আগ পর্যন্ত)</td>
            <td></td><td></td><td class="r <?php echo bal_cr_class($opening); ?>" style="<?php echo bal_cr_style($opening); ?>"><?php echo bal_txt($opening); ?></td>
        </tr>
    <?php $sl = 1; foreach ($ledger as $l) { $isColl = $l['ord'] == 1; ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo date('d-M-Y', strtotime($l['date'])); ?></td>
            <td style="font-weight:bold;color:<?php echo $l['ord'] == 2 ? '#c0392b' : ($isColl ? '#2e7d32' : '#2b579a'); ?>;"><?php echo h($l['ref']); ?></td>
            <td><?php echo h($l['desc']); ?></td>
            <td class="r"><?php echo $l['dr'] > 0 ? number_format($l['dr'], 2) : ''; ?></td>
            <td class="r" style="color:#28a745;"><?php echo $l['cr'] > 0 ? number_format($l['cr'], 2) : ''; ?></td>
            <td class="r <?php echo bal_cr_class($l['bal']); ?>" style="font-weight:bold;<?php echo bal_cr_style($l['bal']); ?>"><?php echo bal_txt($l['bal']); ?></td>
        </tr>
    <?php } ?>
    <?php if (!$ledger) { ?>
        <tr><td colspan="7" class="c" style="padding:15px;color:#777;">এই সময়ে কোনো লেনদেন নেই।</td></tr>
    <?php } ?>
        <tr class="total-row">
            <td colspan="4" class="r">Total / Closing Balance :</td>
            <td class="r"><?php echo number_format($T['dr'], 2); ?></td>
            <td class="r"><?php echo number_format($T['cr'], 2); ?></td>
            <td class="r <?php echo bal_cr_class($closing); ?>" style="<?php echo bal_cr_style($closing); ?>"><?php echo bal_txt($closing); ?></td>
        </tr>
    </tbody>
</table>
<div style="font-size:11px;color:#777;margin-top:6px;">Dr = কাস্টমারের কাছে পাওনা, Cr = কাস্টমারকে ফেরত দেয় (অগ্রিম)</div>

<?php } ?>

<script>
function syncCustomer() {
    var val = document.getElementById('customer_search').value;
    var opts = document.getElementById('dl_customer').options;
    var idBox = document.getElementById('customer_id');
    idBox.value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { idBox.value = opts[i].getAttribute('data-id'); break; }
    }
}
</script>
</body>
</html>