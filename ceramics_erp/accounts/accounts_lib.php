<?php
// Accounts/accounts_lib.php  -- shared engine for all Accounts pages + sales/purchase hooks
// config.php include bad dewa hoyeche (memory loop er karon) -- index.php er moto shorashori connection
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { http_response_code(401); exit('Unauthorized'); }   // লগইন ছাড়া নয়
if (!isset($conn) || !($conn instanceof mysqli)) {
    include __DIR__ . '/../config.php';   // require_once নয়: access.php আগেই লোকাল স্কোপে লোড করে ফেলেছে, তাই গ্লোবাল $conn পেতে প্লেইন include লাগবে
}

function n2($v) { return number_format((float)$v, 2); }
function esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }

// Asset/Expense = debit nature
function acc_is_dr($type) { return in_array($type, ['Asset', 'Expense']); }

function acc_id($conn, $code) {
    $c = esc($conn, $code);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM erp_chart_of_accounts WHERE account_code='$c' LIMIT 1"));
    return $r ? (int)$r['id'] : 0;
}

// Voucher delete (sales/purchase delete hook)
function delete_journal($conn, $type, $ref) {
    $t = esc($conn, $type); $r = esc($conn, $ref);
    $q = mysqli_query($conn, "SELECT id FROM erp_journal_master WHERE voucher_type='$t' AND ref_no='$r'");
    while ($q && $m = mysqli_fetch_assoc($q)) {
        mysqli_query($conn, "DELETE FROM erp_journal_details WHERE journal_id=" . (int)$m['id']);
        mysqli_query($conn, "DELETE FROM erp_journal_master WHERE id=" . (int)$m['id']);
    }
}

/*
 * post_journal($conn,'SALES',$invoice_no,$date,'narration',[ ['1001',500,0], ['1101',100,0,$customer_id], ['4001',0,600] ]);
 * Line = [account_code, debit, credit, party_id(optional)]. Negative debit/credit auto-flip. Same type+ref = replace (edit safe).
 */
function post_journal($conn, $type, $ref, $date, $narration, $lines) {
    $clean = []; $dr = 0; $cr = 0;
    foreach ($lines as $l) {
        $d = round((float)$l[1], 2); $c = round((float)$l[2], 2);
        if ($d < 0) { $c += -$d; $d = 0; }
        if ($c < 0) { $d += -$c; $c = 0; }
        if ($d == 0 && $c == 0) continue;
        $aid = acc_id($conn, $l[0]);
        if ($aid == 0) return false;
        $clean[] = [$aid, $d, $c, isset($l[3]) ? (int)$l[3] : 0];
        $dr += $d; $cr += $c;
    }
    if (!$clean || abs($dr - $cr) > 0.01) return false;   // unbalanced voucher reject

    delete_journal($conn, $type, $ref);
    $t = esc($conn, $type); $r = esc($conn, $ref); $dt = esc($conn, $date); $nr = esc($conn, $narration);
    $vno = $t . '-' . $r;
    mysqli_query($conn, "INSERT INTO erp_journal_master (voucher_no, voucher_date, voucher_type, ref_no, narration) VALUES ('$vno','$dt','$t','$r','$nr')");
    $jid = mysqli_insert_id($conn);
    foreach ($clean as $c) {
        $party = $c[3] > 0 ? $c[3] : 'NULL';
        mysqli_query($conn, "INSERT INTO erp_journal_details (journal_id, account_id, debit, credit, party_id) VALUES ($jid,{$c[0]},{$c[1]},{$c[2]},$party)");
    }
    return $jid;
}

/*
 * COGS (Cost of Goods Sold): Dr 5001 COGS / Cr 1201 Inventory -- সেলস ইনভয়েস অনুযায়ী।
 * একক খরচ = erp_products.cost_price (Purchase Price, প্রতি SFT)। ইনভয়েস সেভ/এডিটে নতুন করে বসে (replace), ডিলিটে মুছে যায়।
 * সতর্কতা: পরে cost_price বদলালে আগের ইনভয়েসের COGS নিজে বদলায় না (ওটা তখনকার খরচই থাকে)।
 */
function sales_invoice_cogs($conn, $invoice_id) {
    $id = (int)$invoice_id;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(d.total_qty * IFNULL(p.cost_price,0)),0) AS cogs,
            COALESCE(SUM(CASE WHEN IFNULL(p.cost_price,0) <= 0 THEN 1 ELSE 0 END),0) AS zero_cost
        FROM sales_invoice_details d JOIN erp_products p ON p.id = d.product_id WHERE d.invoice_id = $id"));
    return ['cogs' => round((float)$r['cogs'], 2), 'zero_cost' => (int)$r['zero_cost']];
}
function post_cogs($conn, $invoice_no, $invoice_date, $invoice_id) {
    $c = sales_invoice_cogs($conn, $invoice_id);
    $c['posted'] = false;
    if ($c['cogs'] <= 0.004) { delete_journal($conn, 'COGS', $invoice_no); return $c; }   // খরচ ০ হলে আগের COGS মুছে দেয়
    $c['posted'] = (bool)post_journal($conn, 'COGS', $invoice_no, $invoice_date, "COGS Invoice $invoice_no", [
        ['5001', $c['cogs'], 0],
        ['1201', 0, $c['cogs']]
    ]);
    return $c;
}

/*
 * Per-account totals. net = (Dr - Cr), opening included if $with_open.
 */
function acc_rows($conn, $from, $to, $with_open = true) {
    $f = esc($conn, $from); $t = esc($conn, $to);
    $sql = "SELECT a.id, a.account_code, a.account_name, a.account_type, a.opening_balance,
                   IFNULL(t.dr,0) AS dr, IFNULL(t.cr,0) AS cr
            FROM erp_chart_of_accounts a
            LEFT JOIN (SELECT d.account_id, SUM(d.debit) dr, SUM(d.credit) cr
                       FROM erp_journal_details d JOIN erp_journal_master m ON m.id = d.journal_id
                       WHERE m.voucher_date BETWEEN '$f' AND '$t' GROUP BY d.account_id) t ON t.account_id = a.id
            WHERE a.is_group = 0 AND a.status = 'Active'
            ORDER BY a.account_code";
    $out = [];
    $res = mysqli_query($conn, $sql);
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $open = (float)$r['opening_balance'];
        $open_dr = acc_is_dr($r['account_type']) ? $open : -$open;   // signed Dr-Cr
        $r['open_signed'] = $with_open ? $open_dr : 0;
        $r['net'] = $r['open_signed'] + (float)$r['dr'] - (float)$r['cr'];
        $out[] = $r;
    }
    return $out;
}

// Fund balances for dashboard (Cash / Bank / MFS)
function acc_fund_balance($conn, $fund) {
    $f = esc($conn, $fund);
    $sum = 0;
    $res = mysqli_query($conn, "SELECT a.id, a.opening_balance FROM erp_chart_of_accounts a WHERE a.fund_type='$f' AND a.status='Active'");
    while ($res && $a = mysqli_fetch_assoc($res)) {
        $x = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(debit-credit),0) b FROM erp_journal_details WHERE account_id=" . (int)$a['id']));
        $sum += (float)$a['opening_balance'] + (float)$x['b'];
    }
    return $sum;
}
if (!function_exists('get_cash_balance')) { function get_cash_balance($conn) { return acc_fund_balance($conn, 'Cash'); } }
if (!function_exists('get_bank_balance')) { function get_bank_balance($conn) { return acc_fund_balance($conn, 'Bank'); } }
if (!function_exists('get_mfs_balance'))  { function get_mfs_balance($conn)  { return acc_fund_balance($conn, 'MFS'); } }

// Common page head (same look as other ERP pages)
function acc_head($title) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{font-family:"Segoe UI",Arial,sans-serif;background:#fafafa;padding:12px;margin:0;color:#333;font-size:13px}
.control-bar{background:#ececef;padding:10px;border-radius:4px;display:flex;gap:8px;margin-bottom:12px;border:1px solid #cdcd4a;align-items:flex-end;flex-wrap:wrap}
.btn-action{padding:6px 15px;font-size:13px;font-weight:bold;border:1px solid #d1d1d1;background:#fff;cursor:pointer;border-radius:4px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:#000}
.btn-green{background:#28a745;color:#fff;border-color:#218838}
.form-panel{background:#fff;border:1px solid #cddc39;border-radius:4px;margin-bottom:15px}
.panel-header{background:#ececef;padding:8px 15px;font-weight:bold;color:#495057;border-bottom:1px solid #ced4da;text-transform:uppercase}
.panel-body{padding:15px}
.fg{display:flex;flex-direction:column;gap:3px}
.fg label{font-size:11px;font-weight:bold;color:#444}
.form-control{height:30px;padding:0 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;box-sizing:border-box}
.excel-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 15px}
.grid-table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #cddc39;background:#fff}
.grid-table th{background:#dbebe7;padding:8px;text-align:left;border:1px solid #cddc39}
.grid-table td{padding:6px 8px;border:1px solid #dee2e6}
.r{text-align:right}.tot td{background:#dbebe7;font-weight:bold}.grp td{background:#f1f5f9;font-weight:bold}
.alert{padding:10px;border-radius:4px;margin-bottom:10px}.ok{background:#d4edda;color:#155724}.err{background:#f8d7da;color:#721c24}
.title{font-size:15px;font-weight:bold;color:#2e7d32;margin-bottom:10px;text-transform:uppercase}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:15px}
@media print{.control-bar,.no-print{display:none}}
</style></head><body>';
}


/*
 * Fund Ledger (erp_fund_ledger) -> Journal sync.
 * Cash/Bank/bKash er asol lenden (sales payment, collection CV, payment PV, transfer BT) erp_fund_ledger e thake.
 * Ei function oi row gulo theke journal baniye rakhe, jate Accounts report ar Dashboard ek-i number dekhay.
 *   SALE / COLLECTION : Dr Cash|Bank|MFS / Cr Accounts Receivable
 *   PAYMENT           : Cr Cash|Bank|MFS / Dr Accounts Payable
 *   TRANSFER          : Dr (IN account) / Cr (OUT account)
 *   onno ref_type     : IN = Cr Other Income, OUT = Dr General Expense
 * Idempotent: unchanged voucher skip kore, delete hoye gele journal o muche dey.
 */
function acc_sync_fund_journal($conn) {
    try {
        $codes = ['CASH' => '1001', 'BANK' => '1002', 'MFS' => '1003'];
        $map = [];
        $q = mysqli_query($conn, "SELECT id, account_type FROM erp_fund_accounts");
        while ($q && $a = mysqli_fetch_assoc($q)) $map[(int)$a['id']] = $codes[strtoupper($a['account_type'])] ?? '1001';

        $groups = [];
        $q = mysqli_query($conn, "SELECT * FROM erp_fund_ledger ORDER BY id ASC");
        while ($q && $r = mysqli_fetch_assoc($q)) $groups[$r['ref_type'] . '|' . $r['ref_no']][] = $r;

        // যেসব জার্নাল ভাউচারের লাইন আছে (Dr/Cr সহ) — এগুলো নিচের acc_sync_voucher_journals() পোস্ট করে,
        // তাই fund ledger থেকে আলাদা করে (Other Income / General Expense ধরে) বানানো হবে না।
        $jl = [];
        try {
            $jq = mysqli_query($conn, "SELECT DISTINCT v.voucher_no FROM erp_vouchers v JOIN erp_voucher_lines l ON l.voucher_id = v.id WHERE v.voucher_type='JOURNAL'");
            while ($jq && $j = mysqli_fetch_assoc($jq)) $jl[$j['voucher_no']] = true;
        } catch (Throwable $e) { /* erp_voucher_lines table na thakle ignore */ }

        $keep = [];
        foreach ($groups as $k => $rows) {
            list($rt, $ref) = explode('|', $k, 2);
            if ($rt === 'JOURNAL' && isset($jl[$ref])) continue;   // লাইনসহ জার্নাল acc_sync_voucher_journals() পোস্ট করে
            $vtype = 'FUND-' . $rt;
            $keep[$vtype . '|' . $ref] = true;

            $sig  = substr(md5(json_encode(array_map(function ($x) { return [$x['account_id'], $x['txn_type'], $x['amount'], $x['txn_date']]; }, $rows))), 0, 8);
            $narr = "Fund $rt $ref [$sig]";
            $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT narration FROM erp_journal_master WHERE voucher_type='" . esc($conn, $vtype) . "' AND ref_no='" . esc($conn, $ref) . "' LIMIT 1"));
            if ($old && $old['narration'] === $narr) continue;   // unchanged

            $lines = []; $net = 0;
            foreach ($rows as $r) {
                $code = $map[(int)$r['account_id']] ?? '1001';
                $amt  = (float)$r['amount'];
                if (strtoupper($r['txn_type']) === 'IN') { $lines[] = [$code, $amt, 0]; $net += $amt; }
                else                                      { $lines[] = [$code, 0, $amt]; $net -= $amt; }
            }
            if (abs($net) > 0.004) {
                $ctr = in_array($rt, ['SALE', 'COLLECTION', 'SALE_RET']) ? '1101' : (in_array($rt, ['PAYMENT', 'PUR_RET']) ? '2001' : ($net > 0 ? '4002' : '5004'));
                $lines[] = $net > 0 ? [$ctr, 0, $net] : [$ctr, -$net, 0];
            }
            post_journal($conn, $vtype, $ref, $rows[0]['txn_date'], $narr, $lines);
        }

        // fund ledger theke delete hoye jawa voucher er journal muche felo
        // (lineshoho journal voucher er bhul FUND-JOURNAL entry o ekhane muche jay)
        $q = mysqli_query($conn, "SELECT voucher_type, ref_no FROM erp_journal_master WHERE voucher_type LIKE 'FUND-%'");
        while ($q && $m = mysqli_fetch_assoc($q)) {
            if (!isset($keep[$m['voucher_type'] . '|' . $m['ref_no']])) delete_journal($conn, $m['voucher_type'], $m['ref_no']);
        }
    } catch (Throwable $e) { /* fund table na thakle ignore */ }
    acc_sync_voucher_journals($conn);
}

/*
 * Journal Voucher (transaction.php?type=journal) -> সব লাইন সঠিক অ্যাকাউন্টে পোস্ট।
 * ফান্ড হেড = Cash/Bank/MFS (1001/1002/1003), অন্য হেড = Chart of Accounts এর নিজের কোড।
 * বারবার চালালে সমস্যা নেই: বদল না হলে বাদ দেয়, ভাউচার মুছে গেলে জার্নালও মোছে।
 */
function acc_sync_voucher_journals($conn) {
    try {
        $codes = ['CASH' => '1001', 'BANK' => '1002', 'MFS' => '1003'];
        $fmap = []; $cmap = [];
        $q = mysqli_query($conn, "SELECT id, account_type FROM erp_fund_accounts");
        while ($q && $a = mysqli_fetch_assoc($q)) $fmap[(int)$a['id']] = $codes[strtoupper($a['account_type'])] ?? '1001';
        $q = mysqli_query($conn, "SELECT id, account_code FROM erp_chart_of_accounts");
        while ($q && $a = mysqli_fetch_assoc($q)) $cmap[(int)$a['id']] = $a['account_code'];

        $keep = [];
        $vq = mysqli_query($conn, "SELECT id, voucher_no, voucher_date, remarks FROM erp_vouchers WHERE voucher_type='JOURNAL'");
        while ($vq && $v = mysqli_fetch_assoc($vq)) {
            $lines = []; $sig = []; $ok = true;
            $lq = mysqli_query($conn, "SELECT head_kind, head_id, party_id, dr_amount, cr_amount FROM erp_voucher_lines WHERE voucher_id=" . (int)$v['id'] . " ORDER BY id");
            while ($lq && $l = mysqli_fetch_assoc($lq)) {
                $code = ($l['head_kind'] === 'FUND') ? ($fmap[(int)$l['head_id']] ?? null) : ($cmap[(int)$l['head_id']] ?? null);
                if ($code === null || $code === '') { $ok = false; break; }
                $lines[] = [$code, (float)$l['dr_amount'], (float)$l['cr_amount'], (int)$l['party_id']];
                $sig[] = [$code, $l['dr_amount'], $l['cr_amount'], $l['party_id']];
            }
            if (!$ok || !$lines) continue;   // লাইন নেই (পুরনো ধরন) বা হেড পাওয়া যায়নি
            $no = $v['voucher_no']; $keep[$no] = true;
            $narr = 'Journal ' . $no . (trim((string)$v['remarks']) !== '' ? ' - ' . mb_substr(trim($v['remarks']), 0, 100) : '')
                  . ' [' . substr(md5(json_encode($sig) . $v['voucher_date']), 0, 8) . ']';
            $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT narration FROM erp_journal_master WHERE voucher_type='JOURNAL' AND ref_no='" . esc($conn, $no) . "' LIMIT 1"));
            if ($old && $old['narration'] === $narr) continue;   // বদল নেই
            post_journal($conn, 'JOURNAL', $no, $v['voucher_date'], $narr, $lines);
        }
        $q = mysqli_query($conn, "SELECT ref_no FROM erp_journal_master WHERE voucher_type='JOURNAL'");
        while ($q && $m = mysqli_fetch_assoc($q)) {
            if (!isset($keep[$m['ref_no']])) delete_journal($conn, 'JOURNAL', $m['ref_no']);
        }
    } catch (Throwable $e) { /* erp_voucher_lines টেবিল না থাকলে বাদ */ }
}

// Report page load hole auto sync (sales/purchase hook e ACC_NO_AUTOSYNC define kore eta bondho rakha hoy)
if (!defined('ACC_NO_AUTOSYNC')) { acc_sync_fund_journal($conn); acc_sync_voucher_journals($conn); }