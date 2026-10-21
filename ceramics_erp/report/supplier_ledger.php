<?php
// Report/supplier_statement.php -- Supplier Ledger Statement (customer ledger er moto)
// Cr = amra supplier ke dibo (pawna), Dr = supplier amader kache pabe / advance dewa
// Credit: Purchase GRN, Refund Received (supplier taka ferot dile)
// Debit : Payment Made (GRN e / Payment Voucher), Purchase Return
include('../auth.php'); require_page_access();
include('../config.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function sup_label($name, $addr, $phone) {
    $parts = array_filter([trim((string)$addr), trim((string)$phone)]);
    return $name . ($parts ? ' [ ' . implode(' | ', $parts) . ' ]' : '');
}
// Cr (amader dite hobe) lal; B&W print e underline
function pay_style($b) { return $b > 0.004 ? 'color:#d32f2f;' : ''; }
function pay_class($b) { return $b > 0.004 ? 'cr-bal' : ''; }
function bal_txt($b) { return number_format(abs($b), 2) . ($b < -0.004 ? ' Dr' : ' Cr'); }

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

$supplier    = trim($_GET['supplier'] ?? '');
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$msg = ''; $warn = '';

if ($supplier_id <= 0 && $supplier !== '') {
    $like = '%' . $supplier . '%';
    $st = $conn->prepare("SELECT id FROM erp_suppliers WHERE supplier_name LIKE ? OR address LIKE ? OR mobile_no LIKE ? OR company_name LIKE ? LIMIT 6");
    $st->bind_param("ssss", $like, $like, $like, $like);
    $st->execute();
    $ids = [];
    $rs = $st->get_result();
    while ($x = $rs->fetch_assoc()) $ids[] = (int)$x['id'];
    $st->close();
    if (count($ids) == 1)      $supplier_id = $ids[0];
    elseif (count($ids) > 1)   $msg = 'একাধিক সাপ্লায়ার মিলেছে, অনুগ্রহ করে তালিকা থেকে একজনকে সিলেক্ট করুন।';
    else                       $msg = 'কোনো সাপ্লায়ার পাওয়া যায়নি।';
}
if ($supplier_id <= 0 && $supplier === '') $msg = 'স্টেটমেন্ট দেখতে একজন সাপ্লায়ার সিলেক্ট করুন।';

$sup = null; $ledger = []; $opening = 0; $T = ['dr' => 0, 'cr' => 0]; $closing = 0;

if ($supplier_id > 0) {
    $st = $conn->prepare("SELECT supplier_code, supplier_name, company_name, address, mobile_no FROM erp_suppliers WHERE id = ?");
    $st->bind_param("i", $supplier_id);
    $st->execute();
    $sup = $st->get_result()->fetch_assoc();
    $st->close();
}

if ($sup) {
    $supplier = sup_label($sup['supplier_name'], $sup['address'], $sup['mobile_no']);

    // ---- Voucher (erp_vouchers) কলাম ডিটেক্ট ----
    $vc = table_cols($conn, 'erp_vouchers');
    $vId    = pick($vc, ['id', 'voucher_id']);
    $vNo    = pick($vc, ['voucher_no', 'vch_no', 'voucher_number', 'vno']);
    $vDate  = pick($vc, ['voucher_date', 'txn_date', 'vch_date', 'date']);
    $vType  = pick($vc, ['voucher_type', 'vch_type', 'type', 'vtype']);
    $vParty = pick($vc, ['party_id', 'supplier_id', 'ledger_id', 'head_id']);
    $vPType = pick($vc, ['party_type']);
    $vSide  = pick($vc, ['entry_side']);
    $vAcc   = pick($vc, ['account_id', 'fund_account_id', 'cash_account_id', 'bank_account_id']);
    $vAmt   = pick($vc, ['amount', 'total_amount', 'debit_amount']);
    $vRem   = pick($vc, ['remarks', 'narration', 'note']);
    $vOk    = ($vNo && $vDate && $vParty && $vAmt);

    $ac = table_cols($conn, 'erp_fund_accounts');
    $aId   = pick($ac, ['id', 'account_id']);
    $aName = pick($ac, ['account_name', 'name', 'title']);
    $accJoin = ($vAcc && $aId && $aName) ? "LEFT JOIN erp_fund_accounts a ON a.`$aId` = v.`$vAcc`" : '';
    $accSel  = $accJoin ? "a.`$aName`" : "''";

    if (!$vOk) {
        $warn = 'Payment Voucher-er হিসাব লেজারে আসছে না: erp_vouchers টেবিলের কলাম মেলেনি। পাওয়া কলাম: ' . ($vc ? implode(', ', $vc) : '(টেবিল পাওয়া যায়নি)');
    }

    // ---- শুরুর ব্যালেন্স (পাওনা = +) ----
    $st = $conn->prepare("SELECT COALESCE(SUM(IFNULL(t.total,0) + g.labor_charge - g.discount_amount - g.payment_amount),0) AS due
        FROM erp_purchase_grn g
        LEFT JOIN (SELECT voucher_no, SUM(sqft_in * rate) AS total FROM erp_stock_ledger WHERE voucher_type='PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
        WHERE g.supplier_id = ? AND g.grn_date < ?");
    $st->bind_param("is", $supplier_id, $from);
    $st->execute();
    $opening = (float)$st->get_result()->fetch_assoc()['due'];
    $st->close();

    // Purchase Return (from er age): debit = total - discount, refund = supplier er dewa taka (credit)
    try {
        $st = $conn->prepare("SELECT COALESCE(SUM(total_amount - discount_amount),0) AS c, COALESCE(SUM(refund_amount),0) AS f FROM erp_purchase_return WHERE supplier_id = ? AND return_date < ?");
        $st->bind_param("is", $supplier_id, $from);
        $st->execute();
        $rb = $st->get_result()->fetch_assoc();
        $st->close();
        $opening += -(float)$rb['c'] + (float)$rb['f'];
    } catch (Throwable $e) { /* erp_purchase_return nai */ }

    // ---- ভাউচার (Payment Voucher / Journal) ----
    $vRows = [];
    if ($vOk) {
        $sel  = "v.`$vNo` AS vno, v.`$vDate` AS vdate, v.`$vAmt` AS amt, $accSel AS acct, "
              . ($vRem ? "v.`$vRem`" : "''") . " AS rem, "
              . ($vType ? "v.`$vType`" : "''") . " AS vtype, "
              . ($vSide ? "v.`$vSide`" : "''") . " AS vside, "
              . ($vId ? "v.`$vId`" : "0") . " AS vid";
        $ptype = $vPType ? "AND UPPER(v.`$vPType`) = 'SUPPLIER'" : '';
        $st = $conn->prepare("SELECT $sel FROM erp_vouchers v $accJoin WHERE v.`$vParty` = ? $ptype ORDER BY v.`$vDate` ASC" . ($vId ? ", v.`$vId`" : ''));
        $st->bind_param("i", $supplier_id);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $t = strtoupper((string)$r['vtype']);
            $isPay = (strpos($t, 'PAYMENT') !== false) || (stripos((string)$r['vno'], 'PV') === 0);
            $isJrn = ($t === 'JOURNAL');
            if (!$isPay && !$isJrn) continue;
            $amt = (float)$r['amt']; $d = substr($r['vdate'], 0, 10);
            if ($isPay) { $dr = $amt; $cr = 0; $desc = 'Payment Made (' . ($r['acct'] !== '' && $r['acct'] !== null ? $r['acct'] : 'Payment Voucher') . ')'; }
            else {
                $side = strtoupper((string)$r['vside']);
                $cr = ($side === 'CR') ? $amt : 0; $dr = ($side === 'DR') ? $amt : 0;
                $desc = 'Journal Adjustment (' . ($side ?: '-') . ')';
            }
            $vRows[] = ['date' => $d, 'ord' => 1, 'sub' => (int)$r['vid'], 'ref' => $r['vno'],
                        'desc' => $desc . ($r['rem'] ? ' - ' . $r['rem'] : ''), 'dr' => $dr, 'cr' => $cr];
        }
        $st->close();
    }

    // ---- ইভেন্ট সংগ্রহ ----
    $ev = [];
    foreach ($vRows as $x) {
        if ($x['date'] < $from) $opening += $x['cr'] - $x['dr'];
        elseif ($x['date'] <= $to) $ev[] = $x;
    }

    // GRN (ক্রয় = Credit) + GRN এর সাথে দেওয়া পেমেন্ট (Debit)
    $st = $conn->prepare("SELECT g.id, g.grn_no, g.grn_date, g.remarks, g.labor_charge, g.discount_amount, g.payment_amount,
               IFNULL(t.total,0) AS items_total, IFNULL(t.qty,0) AS qty
        FROM erp_purchase_grn g
        LEFT JOIN (SELECT voucher_no, SUM(sqft_in * rate) AS total, SUM(sqft_in) AS qty FROM erp_stock_ledger WHERE voucher_type='PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
        WHERE g.supplier_id = ? AND g.grn_date BETWEEN ? AND ?
        ORDER BY g.grn_date ASC, g.id ASC");
    $st->bind_param("iss", $supplier_id, $from, $to);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
        $gross = (float)$r['items_total'] + (float)$r['labor_charge'] - (float)$r['discount_amount'];
        $paid  = (float)$r['payment_amount'];
        $gid   = (int)$r['id'];
        $ev[] = ['date' => $r['grn_date'], 'ord' => 0, 'sub' => $gid * 2, 'ref' => $r['grn_no'],
                 'desc' => 'Purchase GRN (' . number_format($r['qty'], 2) . ' SFT)' . ($r['remarks'] ? ' - ' . $r['remarks'] : ''),
                 'dr' => 0, 'cr' => $gross];
        if ($paid > 0) {
            $lines = [];
            if ($aId && $aName) {
                try {
                    $s2 = $conn->prepare("SELECT a.`$aName` AS n, l.amount AS amt FROM erp_fund_ledger l JOIN erp_fund_accounts a ON a.`$aId` = l.account_id WHERE l.ref_type = 'PAYMENT' AND l.ref_no = ?");
                    $s2->bind_param("s", $r['grn_no']);
                    $s2->execute();
                    $rs2 = $s2->get_result();
                    while ($x = $rs2->fetch_assoc()) $lines[] = $x;
                    $s2->close();
                } catch (Exception $e) {}
            }
            $lsum = 0; foreach ($lines as $x) $lsum += (float)$x['amt'];
            if ($lines && abs($lsum - $paid) < 0.01) {
                foreach ($lines as $x) {
                    $ev[] = ['date' => $r['grn_date'], 'ord' => 0, 'sub' => $gid * 2 + 1, 'ref' => $r['grn_no'],
                             'desc' => 'Payment Made (' . $x['n'] . ')', 'dr' => (float)$x['amt'], 'cr' => 0];
                }
            } else {
                $ev[] = ['date' => $r['grn_date'], 'ord' => 0, 'sub' => $gid * 2 + 1, 'ref' => $r['grn_no'],
                         'desc' => 'Payment Made (at GRN)', 'dr' => $paid, 'cr' => 0];
            }
        }
    }
    $st->close();

    // Purchase Return: Debit row (Return Credit) + Refund row (Credit, account onujayi)
    try {
        $st = $conn->prepare("SELECT id, return_no, return_date, grn_no, total_amount, discount_amount, refund_amount, remarks
            FROM erp_purchase_return WHERE supplier_id = ? AND return_date BETWEEN ? AND ? ORDER BY return_date ASC, id ASC");
        $st->bind_param("iss", $supplier_id, $from, $to);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $rid    = (int)$r['id'];
            $credit = (float)$r['total_amount'] - (float)$r['discount_amount'];
            $refund = (float)$r['refund_amount'];
            $desc   = 'Purchase Return (GRN ' . $r['grn_no'] . ')'
                    . ((float)$r['discount_amount'] > 0 ? ' [Discount ' . number_format($r['discount_amount'], 2) . ']' : '')
                    . ($r['remarks'] ? ' - ' . $r['remarks'] : '');
            $ev[] = ['date' => $r['return_date'], 'ord' => 2, 'sub' => $rid * 2, 'ref' => $r['return_no'],
                     'desc' => $desc, 'dr' => $credit, 'cr' => 0];
            if ($refund > 0) {
                $lines = [];
                if ($aId && $aName) {
                    try {
                        $s2 = $conn->prepare("SELECT a.`$aName` AS n, l.amount AS amt FROM erp_fund_ledger l JOIN erp_fund_accounts a ON a.`$aId` = l.account_id WHERE l.ref_type = 'PUR_RET' AND l.ref_no = ?");
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
                                 'desc' => 'Refund Received (' . $x['n'] . ')', 'dr' => 0, 'cr' => (float)$x['amt']];
                    }
                } else {
                    $ev[] = ['date' => $r['return_date'], 'ord' => 2, 'sub' => $rid * 2 + 1, 'ref' => $r['return_no'],
                             'desc' => 'Refund Received', 'dr' => 0, 'cr' => $refund];
                }
            }
        }
        $st->close();
    } catch (Throwable $e) { /* erp_purchase_return nai */ }

    usort($ev, function ($a, $b) { return [$a['date'], $a['ord'], $a['sub']] <=> [$b['date'], $b['ord'], $b['sub']]; });

    $bal = $opening;
    foreach ($ev as $e) {
        $bal += $e['cr'] - $e['dr'];
        $T['dr'] += $e['dr']; $T['cr'] += $e['cr'];
        $e['bal'] = $bal;
        $ledger[] = $e;
    }
    $closing = $bal;
}

$sl_list = [];
$sq = mysqli_query($conn, "SELECT id, supplier_name, address, mobile_no FROM erp_suppliers ORDER BY supplier_name ASC");
while ($sq && $c = mysqli_fetch_assoc($sq)) $sl_list[] = $c;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Supplier Ledger Statement</title>
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
    <div style="font-weight:bold;">Supplier Ledger Statement: <?php echo date('d-M-Y', strtotime($from)); ?> to <?php echo date('d-M-Y', strtotime($to)); ?></div>
</div>

<datalist id="dl_supplier">
<?php foreach ($sl_list as $c) {
    echo '<option data-id="' . (int)$c['id'] . '" value="' . h(sup_label($c['supplier_name'], $c['address'], $c['mobile_no'])) . '">';
} ?>
</datalist>

<form method="GET" class="filter-panel">
    <div class="filter-grid">
        <div class="fg"><label>Supplier</label>
            <input type="text" name="supplier" id="supplier_search" class="fc" list="dl_supplier" autocomplete="off" placeholder="Name / Address / Mobile" value="<?php echo h($supplier); ?>" oninput="syncSupplier()">
            <input type="hidden" name="supplier_id" id="supplier_id" value="<?php echo $supplier_id ? $supplier_id : ''; ?>"></div>
        <div class="fg"><label>From Date</label><input type="date" name="from" class="fc" value="<?php echo h($from); ?>"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to" class="fc" value="<?php echo h($to); ?>"></div>
        <div class="fg" style="flex-direction:row; gap:6px;">
            <button type="submit" class="btn btn-show"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
            <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</form>

<?php if ($warn) { ?><div class="notice"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo h($warn); ?></div><?php } ?>

<?php if (!$sup) { ?>
    <div class="notice"><i class="fa-solid fa-circle-info"></i> <?php echo h($msg); ?></div>
<?php } else { ?>

<div class="cust-box">
    <b style="font-size:15px;color:#2e7d32;"><?php echo h($sup['supplier_name']); ?></b>
    <span style="color:#777;">(<?php echo h($sup['supplier_code']); ?>)</span><?php if (!empty($sup['company_name'])) echo ' &nbsp;<span style="color:#555;">Contact: ' . h($sup['company_name']) . '</span>'; ?><br>
    <i class="fa-solid fa-location-dot"></i> <?php echo h($sup['address'] ?: '-'); ?> &nbsp;|&nbsp;
    <i class="fa-solid fa-phone"></i> <?php echo h($sup['mobile_no'] ?: '-'); ?>
</div>

<div class="cards">
    <div class="card" style="border-left-color:#ef6c00;"><div class="t">Opening Balance</div><div class="v <?php echo pay_class($opening); ?>" style="color:#ef6c00;<?php echo pay_style($opening); ?>"><?php echo bal_txt($opening); ?></div></div>
    <div class="card" style="border-left-color:#0056b3;"><div class="t">Total Debit (Payment + Return)</div><div class="v" style="color:#0056b3;"><?php echo number_format($T['dr'], 2); ?></div></div>
    <div class="card" style="border-left-color:#28a745;"><div class="t">Total Credit (Purchase + Refund)</div><div class="v" style="color:#28a745;"><?php echo number_format($T['cr'], 2); ?></div></div>
    <div class="card" style="border-left-color:#d32f2f;"><div class="t">Closing Balance</div><div class="v <?php echo pay_class($closing); ?>" style="color:#d32f2f;"><?php echo bal_txt($closing); ?></div></div>
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
            <td></td><td></td><td class="r <?php echo pay_class($opening); ?>" style="<?php echo pay_style($opening); ?>"><?php echo bal_txt($opening); ?></td>
        </tr>
    <?php $sl = 1; foreach ($ledger as $l) { $isVch = $l['ord'] == 1; ?>
        <tr>
            <td class="c"><?php echo $sl++; ?></td>
            <td><?php echo date('d-M-Y', strtotime($l['date'])); ?></td>
            <td style="font-weight:bold;color:<?php echo $l['ord'] == 2 ? '#c0392b' : ($isVch ? '#2e7d32' : '#2b579a'); ?>;"><?php echo h($l['ref']); ?></td>
            <td><?php echo h($l['desc']); ?></td>
            <td class="r" style="color:#0056b3;"><?php echo $l['dr'] > 0 ? number_format($l['dr'], 2) : ''; ?></td>
            <td class="r"><?php echo $l['cr'] > 0 ? number_format($l['cr'], 2) : ''; ?></td>
            <td class="r <?php echo pay_class($l['bal']); ?>" style="font-weight:bold;<?php echo pay_style($l['bal']); ?>"><?php echo bal_txt($l['bal']); ?></td>
        </tr>
    <?php } ?>
    <?php if (!$ledger) { ?>
        <tr><td colspan="7" class="c" style="padding:15px;color:#777;">এই সময়ে কোনো লেনদেন নেই।</td></tr>
    <?php } ?>
        <tr class="total-row">
            <td colspan="4" class="r">Total / Closing Balance :</td>
            <td class="r"><?php echo number_format($T['dr'], 2); ?></td>
            <td class="r"><?php echo number_format($T['cr'], 2); ?></td>
            <td class="r <?php echo pay_class($closing); ?>" style="<?php echo pay_style($closing); ?>"><?php echo bal_txt($closing); ?></td>
        </tr>
    </tbody>
</table>
<div style="font-size:11px;color:#777;margin-top:6px;">Cr = সাপ্লায়ারকে দিতে হবে (পাওনা), Dr = সাপ্লায়ার আমাদের কাছে পাবে না / অগ্রিম দেওয়া (আমরা পাব)</div>

<?php } ?>

<script>
function syncSupplier() {
    var val = document.getElementById('supplier_search').value;
    var opts = document.getElementById('dl_supplier').options;
    var idBox = document.getElementById('supplier_id');
    idBox.value = '';
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) { idBox.value = opts[i].getAttribute('data-id'); break; }
    }
}
</script>
</body>
</html>