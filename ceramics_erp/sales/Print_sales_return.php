<?php
// print_invoice.php
include('../auth.php'); require_page_access('sales/sales_return.php');
include('../config.php');

if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) { die("ভুল Return আইডি সরবরাহ করা হয়েছে।"); }
$rid = (int)$_GET['id'];

// ===== Company profile =====
$co = [];
try { include_once('../Settings/settings_lib.php'); $co = company_info($conn); } catch (Throwable $e) { $co = []; }
if (!function_exists('company_images')) { function company_images($co) { return [[], []]; } }
list($imgL, $imgR) = company_images($co);
$co_name   = trim($co['company_name'] ?? '') ?: 'Uttara Builders';
$co_tag    = trim($co['tagline'] ?? '');
$co_footer = trim($co['invoice_footer'] ?? '');
$co_lines  = [];
if (trim($co['address'] ?? '') !== '') $co_lines[] = trim($co['address']);
$c2 = [];
$ph = implode(', ', array_filter([trim($co['phone'] ?? ''), trim($co['mobile'] ?? '')]));
if ($ph !== '') $c2[] = 'Mobile: ' . $ph;
if (trim($co['email'] ?? '') !== '')   $c2[] = 'Email: ' . trim($co['email']);
if (trim($co['website'] ?? '') !== '') $c2[] = trim($co['website']);
if ($c2) $co_lines[] = implode(' | ', $c2);
$c3 = [];
if (trim($co['bin_no'] ?? '') !== '')        $c3[] = 'BIN: ' . trim($co['bin_no']);
if (trim($co['trade_license'] ?? '') !== '') $c3[] = 'Trade License: ' . trim($co['trade_license']);
if ($c3) $co_lines[] = implode(' | ', $c3);

// ===== Master =====
$mr = mysqli_query($conn, "SELECT r.*, c.customer_name, c.phone AS c_phone, c.address AS c_address
                           FROM erp_sales_return r LEFT JOIN erp_customers c ON c.id = r.customer_id WHERE r.id = $rid" . branch_sql('r.branch_name') . " LIMIT 1");
$ret = $mr ? mysqli_fetch_assoc($mr) : null;
if (!$ret) { die("কোনো Sales Return রেকর্ড পাওয়া যায়নি!"); }
$ret_no  = $ret['return_no'];
$ret_noe = mysqli_real_escape_string($conn, $ret_no);

$items_res = mysqli_query($conn, "SELECT d.*, p.item_name, p.size_name, p.unit_name
                                  FROM erp_sales_return_details d JOIN erp_products p ON p.id = d.product_id
                                  WHERE d.return_id = $rid ORDER BY d.id ASC");

$pay_methods = [];
$pm = mysqli_query($conn, "SELECT a.account_name, SUM(l.amount) AS amt
                           FROM erp_fund_ledger l JOIN erp_fund_accounts a ON a.id = l.account_id
                           WHERE l.ref_type='SALE_RET' AND l.ref_no='$ret_noe' GROUP BY l.account_id, a.account_name");
while ($pm && $pr = mysqli_fetch_assoc($pm)) { $pay_methods[] = $pr['account_name'] . ': ' . number_format($pr['amt'], 2); }

// ===== Amount in words =====
function bd_words_under_1000($n) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
             'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $s = '';
    if ($n >= 100) { $s .= $ones[intdiv($n, 100)] . ' Hundred '; $n %= 100; }
    if ($n >= 20)  { $s .= $tens[intdiv($n, 10)] . ' '; $n %= 10; }
    if ($n > 0)    { $s .= $ones[$n] . ' '; }
    return $s;
}
function bd_amount_in_words($amount) {
    $amount = round(abs((float)$amount), 2);
    $taka   = (int)floor($amount);
    $paisa  = (int)round(($amount - $taka) * 100);
    if ($taka == 0 && $paisa == 0) return 'Zero Taka Only';
    $w = '';
    $crore = intdiv($taka, 10000000); $taka %= 10000000;
    $lakh  = intdiv($taka, 100000);   $taka %= 100000;
    $thou  = intdiv($taka, 1000);     $taka %= 1000;
    if ($crore) $w .= bd_words_under_1000($crore) . 'Crore ';
    if ($lakh)  $w .= bd_words_under_1000($lakh)  . 'Lakh ';
    if ($thou)  $w .= bd_words_under_1000($thou)  . 'Thousand ';
    if ($taka)  $w .= bd_words_under_1000($taka);
    $w = trim($w);
    $out = ($w !== '' ? $w : 'Zero') . ' Taka';
    if ($paisa) $out .= ' and ' . trim(bd_words_under_1000($paisa)) . ' Paisa';
    return $out . ' Only';
}

$total    = (float)$ret['total_amount'];
$discount = (float)($ret['discount_amount'] ?? 0);
$credit   = $total - $discount;
$refund   = (float)$ret['refund_amount'];
$prev_bal = (float)$ret['prev_balance'];
$net_bal  = (float)$ret['net_balance'];
$remarks_txt = trim((string)($ret['remarks'] ?? ''));
$date_show = $ret['return_date'] ? date('d-M-Y', strtotime($ret['return_date'])) : '-';
$right_img = $imgR ? 'x' : '';
$pay_type_txt = $pay_methods ? implode(' | ', $pay_methods) : 'No refund (Adjusted with due)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Return Print - <?php echo htmlspecialchars($ret_no); ?></title>
    <style>
        @page { size: A4 portrait; margin: 10mm 10mm 15mm 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; background: #e9ecef; font-size: 13px; }
        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 8mm; box-shadow: 0 0 6px rgba(0,0,0,.25); }
        table.page { width: 100%; border-collapse: collapse; height: 258mm; }
        table.page > thead > tr, table.page > tfoot > tr { height: 1px; }
        table.page > thead > tr > td, table.page > tfoot > tr > td { padding: 0; vertical-align: top; }
        table.page > tbody > tr > td { padding: 0; vertical-align: top; height: 100%; }

        .hdr { width: 100%; border-collapse: collapse; border-bottom: 2px solid #2b579a; margin-bottom: 8px; }
        .hdr td { vertical-align: middle; padding: 0 0 8px 0; }
        .hdr .side { width: 190px; }
        .hdr .side.l { text-align: left; }
        .hdr .side.r { text-align: right; }
        .hdr .side img { max-height: 70px; max-width: 90px; margin: 0 2px; vertical-align: middle; }
        .hdr .mid { text-align: center; }
        .company-name { font-size: 26px; font-weight: bold; color: #2b579a; text-transform: uppercase; margin: 0; line-height: 1.1; }
        .company-details { font-size: 12px; color: #555; margin: 4px 0 0 0; }
        #qrBox { display: inline-block; width: 70px; height: 70px; }
        #qrBox img, #qrBox canvas { width: 70px !important; height: 70px !important; }

        .bill-title { text-align: center; font-size: 15px; font-weight: bold; background: #c0392b; color: #fff; padding: 4px 14px; width: fit-content; margin: 0 auto 8px; border-radius: 4px; text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .info-table { width: 100%; margin-bottom: 10px; font-size: 13px; border-collapse: collapse; }
        .info-table td { padding: 3px 0; vertical-align: top; }

        .item-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .item-table th { background: #cbd5e1; color: #000; border: 1px solid #94a3b8; padding: 6px; font-weight: bold; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .item-table td { border: 1px solid #cbd5e1; padding: 6px; }
        .item-table tr { page-break-inside: avoid; }

        .summary-container { display: flex; justify-content: space-between; margin-top: 12px; page-break-inside: avoid; }
        .remarks-section { width: 50%; color: #333; border: 1px solid #94a3b8; border-radius: 4px; align-self: flex-start; }
        .remarks-section .info-row { font-size: 12.5px; padding: 6px 10px; border-bottom: 1px dashed #cbd5e1; }
        .remarks-section .info-row:last-child { border-bottom: none; }
        .remarks-section .info-row b { color: #2b579a; }
        .calculation-table { width: 46%; border-collapse: collapse; font-size: 13px; }
        .calculation-table td { padding: 4px 5px; text-align: right; }
        .calculation-table .label { font-weight: bold; padding-right: 12px; }

        .foot-sig { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .foot-sig td { width: 33.33%; text-align: center; padding: 0 12px; vertical-align: bottom; height: 40px; }
        .sig-line { border-top: 1px solid #333; padding-top: 4px; font-size: 12px; font-weight: bold; }
        .co-footer { text-align: center; margin-top: 8px; font-size: 11px; color: #666; border-top: 1px dashed #ccc; padding-top: 5px; }

        @media print {
            body { background: #fff; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .no-print { display: none !important; }
            table.page { height: 265mm; }
        }
    </style>
</head>
<body>
<div class="sheet">
<table class="page">
    <thead><tr><td>
        <table class="hdr">
            <tr>
                <td class="side l"><?php foreach ($imgL as $p): ?><img src="../<?php echo htmlspecialchars($p); ?>" alt="logo"><?php endforeach; ?></td>
                <td class="mid">
                    <h1 class="company-name"><?php echo htmlspecialchars($co_name); ?></h1>
                    <?php if ($co_tag !== ''): ?><div style="font-size:12px; color:#555; font-style:italic;"><?php echo htmlspecialchars($co_tag); ?></div><?php endif; ?>
                    <p class="company-details"><?php echo implode('<br>', array_map('htmlspecialchars', $co_lines)); ?></p>
                </td>
                <td class="side r">
                    <?php if ($imgR): ?>
                        <?php foreach ($imgR as $p): ?><img src="../<?php echo htmlspecialchars($p); ?>" alt="img"><?php endforeach; ?>
                    <?php else: ?>
                        <div id="qrBox"></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <div class="bill-title">Sales Return</div>
    </td></tr></thead>

    <tfoot><tr><td>
        <table class="foot-sig">
            <tr>
                <td><div class="sig-line">Customer Signature</div></td>
                <td><div class="sig-line">Received By</div></td>
                <td><div class="sig-line">Authorized Signature</div></td>
            </tr>
        </table>
        <?php if ($co_footer !== ''): ?><div class="co-footer"><?php echo htmlspecialchars($co_footer); ?></div><?php endif; ?>
    </td></tr></tfoot>

    <tbody><tr><td>
        <table class="info-table">
            <tr>
                <td style="width:60%;">
                    <strong>Customer Name:</strong> <?php echo htmlspecialchars($ret['customer_name'] ?? 'N/A'); ?><br>
                    <strong>Address:</strong> <?php echo htmlspecialchars(($ret['c_address'] ?? '') !== '' ? $ret['c_address'] : 'N/A'); ?><br>
                    <strong>Mobile:</strong> <?php echo htmlspecialchars(($ret['c_phone'] ?? '') !== '' ? $ret['c_phone'] : 'N/A'); ?>
                </td>
                <td style="width:40%; text-align:right;">
                    <strong>Return No:</strong> <span style="color:#c0392b; font-weight:bold;"><?php echo htmlspecialchars($ret_no); ?></span><br>
                    <strong>Return Date:</strong> <?php echo $date_show; ?><br>
                    <strong>Invoice Ref:</strong> <?php echo htmlspecialchars($ret['invoice_no']); ?>
                </td>
            </tr>
        </table>

        <table class="item-table">
            <thead>
                <tr>
                    <th style="width:32px; text-align:center;">SL</th>
                    <th>Product Description</th>
                    <th style="text-align:center; width:62px;">Size</th>
                    <th style="text-align:center; width:44px;">Unit</th>
                    <th style="text-align:center; width:40px;">Box</th>
                    <th style="text-align:center; width:40px;">Pcs</th>
                    <th style="text-align:right; width:74px;">Return QTY</th>
                    <th style="text-align:right; width:64px;">Price</th>
                    <th style="text-align:right; width:86px;">Amount</th>
                    <th style="text-align:center; width:66px;">Condition</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sl = 1; $sum_box = 0; $sum_pcs = 0; $sum_qty = 0; $sum_amt = 0;
            if ($items_res && mysqli_num_rows($items_res) > 0) {
                while ($it = mysqli_fetch_assoc($items_res)) {
                    $sum_box += (int)$it['box_qty']; $sum_pcs += (int)$it['pcs_qty']; $sum_qty += (float)$it['return_qty']; $sum_amt += (float)$it['amount'];
                    $dmg = ($it['item_condition'] === 'Damaged');
            ?>
                <tr>
                    <td style="text-align:center;"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($it['item_name']); ?></td>
                    <td style="text-align:center;"><span style="font-size:12px;"><?php echo htmlspecialchars($it['size_name'] ?? '-'); ?></span></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars($it['unit_name'] ?? ''); ?></td>
                    <td style="text-align:center;"><?php echo (int)$it['box_qty']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$it['pcs_qty']; ?></td>
                    <td style="text-align:right; font-weight:bold;"><?php echo number_format($it['return_qty'], 2); ?></td>
                    <td style="text-align:right;"><?php echo number_format($it['rate'], 2); ?></td>
                    <td style="text-align:right; font-weight:bold;"><?php echo number_format($it['amount'], 2); ?></td>
                    <td style="text-align:center; font-weight:bold; color:<?php echo $dmg ? '#c0392b' : '#28a745'; ?>;"><?php echo htmlspecialchars($it['item_condition']); ?></td>
                </tr>
            <?php } } else { ?>
                <tr><td colspan="10" style="text-align:center; color:#888; padding:12px;">কোনো আইটেম পাওয়া যায়নি।</td></tr>
            <?php } ?>
                <tr style="background-color:#f8fafc; font-weight:bold;">
                    <td colspan="4" style="text-align:right;">Grand Total:</td>
                    <td style="text-align:center; color:#2b579a;"><?php echo $sum_box; ?></td>
                    <td style="text-align:center; color:#2b579a;"><?php echo $sum_pcs; ?></td>
                    <td style="text-align:right; color:#2b579a;"><?php echo number_format($sum_qty, 2); ?></td>
                    <td></td>
                    <td style="text-align:right; color:#2b579a;"><?php echo number_format($sum_amt, 2); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="summary-container">
            <div class="remarks-section">
                <div class="info-row"><b>Amount In Words (Net Return Credit):</b><br><?php echo htmlspecialchars(bd_amount_in_words($credit)); ?></div>
                <div class="info-row"><b>Refund Type:</b> <?php echo htmlspecialchars($pay_type_txt); ?></div>
                <div class="info-row"><b>Reason / Note:</b> <?php echo $remarks_txt !== '' ? nl2br(htmlspecialchars($remarks_txt)) : '-'; ?></div>
            </div>
            <table class="calculation-table">
                <tr><td class="label">Previous Balance:</td><td style="border-bottom:1px solid #ddd;"><?php echo number_format($prev_bal, 2); ?></td></tr>
                <tr><td class="label">Return Amount:</td><td style="border-bottom:1px solid #ddd;"><?php echo number_format($total, 2); ?></td></tr>
                <tr><td class="label">Discount (-):</td><td style="border-bottom:1px solid #ddd; color:red;"><?php echo number_format($discount, 2); ?></td></tr>
                <tr style="font-weight:bold; font-size:14px;">
                    <td class="label" style="color:#c0392b;">Net Return Credit (-):</td>
                    <td style="border-bottom:2px double #333; color:#c0392b;"><?php echo number_format($credit, 2); ?></td>
                </tr>
                <tr><td class="label">Refund Paid (+):</td><td style="border-bottom:1px solid #ddd; color:green; font-weight:bold;"><?php echo number_format($refund, 2); ?></td></tr>
                <tr style="font-weight:bold; font-size:14px; color:red;">
                    <td class="label">Net Balance (Due):</td>
                    <td style="border-bottom:2px solid red;"><?php echo number_format($net_bal, 2); ?></td>
                </tr>
            </table>
        </div>
    </td></tr></tbody>
</table>
</div>

<?php if ($right_img === ''): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
try {
    new QRCode(document.getElementById('qrBox'), {
        text: <?php echo json_encode($co_name . ' | ' . $ret_no . ' | ' . $date_show . ' | Return: ' . number_format($total, 2)); ?>,
        width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M
    });
} catch (e) {}
</script>
<?php endif; ?>
<script>
window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 700); });
</script>
</body>
</html>