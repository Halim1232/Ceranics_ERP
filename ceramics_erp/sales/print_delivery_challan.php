<?php
// sales/print_delivery_challan.php -- Delivery Challan print (print_grn.php er moto A4 layout)
include('../auth.php'); require_page_access('sales/delivery_challan.php'); include('../config.php');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ===== Company profile (Settings > Company Profile Setup) =====
$co = [];
try { include_once('../Settings/settings_lib.php'); $co = company_info($conn); } catch (Throwable $e) { $co = []; }
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

if (!function_exists('company_images')) { function company_images($co) { return [[], []]; } }
list($imgL, $imgR) = company_images($co);
$right_img = $imgR ? 'x' : '';   // right e chobi na thakle auto QR

// ===== Master =====
$sql_master = "SELECT m.*, c.customer_name, c.phone, c.address
               FROM erp_delivery_challan_master m
               LEFT JOIN erp_customers c ON m.customer_id = c.id
               WHERE m.id = $id LIMIT 1";
$master_res = mysqli_query($conn, $sql_master);
$challan = $master_res ? mysqli_fetch_assoc($master_res) : null;
if (!$challan) { die("Challan Record Not Found!"); }

// চালানের ব্রাঞ্চ = তার ইনভয়েসের ব্রাঞ্চ (চালানের টেবিলে ব্রাঞ্চের কলাম নেই)
$ib = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_name FROM sales_invoice_master WHERE Invoice_no='" . mysqli_real_escape_string($conn, (string)$challan['invoice_no']) . "' LIMIT 1"));
branch_guard($ib['branch_name'] ?? '');
$challan['branch_name'] = $ib['branch_name'] ?? '';
// ===== ব্রাঞ্চ-ওয়াইজ হেডিং: ব্রাঞ্চের নাম/ঠিকানা/ফোন থাকলে কোম্পানির জায়গায় বসে =====
$__br = function_exists('branch_info') ? branch_info($conn, $challan['branch_name']) : null;
if ($__br) {
    $co_name = trim($__br['branch_name'] ?? '') ?: $co_name;
    $co_lines = [];
    $__ad = trim($__br['address'] ?? '') ?: trim($co['address'] ?? '');
    if ($__ad !== '') $co_lines[] = $__ad;
    $__ph = trim($__br['phone'] ?? '') ?: implode(', ', array_filter([trim($co['phone'] ?? ''), trim($co['mobile'] ?? '')]));
    $c2 = [];
    if ($__ph !== '') $c2[] = 'Mobile: ' . $__ph;
    if (trim($co['email'] ?? '') !== '')   $c2[] = 'Email: ' . trim($co['email']);
    if (trim($co['website'] ?? '') !== '') $c2[] = trim($co['website']);
    if ($c2) $co_lines[] = implode(' | ', $c2);
    $c3 = [];
    if (trim($co['bin_no'] ?? '') !== '')        $c3[] = 'BIN: ' . trim($co['bin_no']);
    if (trim($co['trade_license'] ?? '') !== '') $c3[] = 'Trade License: ' . trim($co['trade_license']);
    if ($c3) $co_lines[] = implode(' | ', $c3);
}

$wh_show = '';
try {
    if (!empty($challan['warehouse_id'])) {
        $wr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT warehouse_name FROM erp_warehouses WHERE id=" . (int)$challan['warehouse_id']));
        $wh_show = trim((string)($wr['warehouse_name'] ?? ''));
    }
} catch (Throwable $e) { }

$challan_no  = (string)$challan['challan_no'];
$invoice_ref = trim((string)($challan['invoice_no'] ?? ''));
$date_show   = !empty($challan['challan_date']) ? date('d-M-Y', strtotime($challan['challan_date'])) : '-';
$branch_show = trim((string)($challan['branch_name'] ?? ''));
$remarks_txt = trim((string)($challan['remarks'] ?? ''));

// ===== Items =====
$sql_items = "SELECT d.*, p.item_name, p.size_name, p.unit_name, p.box_to_pcs
              FROM erp_delivery_challan_details d
              INNER JOIN erp_products p ON d.product_id = p.id
              WHERE d.challan_id = $id
              ORDER BY d.id ASC";
$items_res = mysqli_query($conn, $sql_items);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Challan Print - <?php echo e($challan_no); ?></title>
    <style>
        @page { size: A4 portrait; margin: 10mm 10mm 15mm 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; background: #e9ecef; font-size: 13px; }

        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 8mm; box-shadow: 0 0 6px rgba(0,0,0,.25); }

        /* header = thead, footer = tfoot -> boro challan hole proti page e repeat hoy */
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

        .bill-title { text-align: center; font-size: 15px; font-weight: bold; background: #2b579a; color: #fff; padding: 4px 14px; width: fit-content; margin: 0 auto 8px; border-radius: 4px; text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .info-table { width: 100%; margin-bottom: 10px; font-size: 13px; border-collapse: collapse; }
        .info-table td { padding: 3px 0; vertical-align: top; }

        .item-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .item-table th { background: #cbd5e1; color: #000; border: 1px solid #94a3b8; padding: 6px; font-weight: bold; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .item-table td { border: 1px solid #cbd5e1; padding: 6px; }
        .item-table tr { page-break-inside: avoid; }

        .summary-container { display: flex; justify-content: space-between; margin-top: 12px; page-break-inside: avoid; }
        .remarks-section { width: 100%; color: #333; border: 1px solid #94a3b8; border-radius: 4px; align-self: flex-start; }
        .remarks-section .info-row { font-size: 12.5px; padding: 6px 10px; border-bottom: 1px dashed #cbd5e1; }
        .remarks-section .info-row:last-child { border-bottom: none; }
        .remarks-section .info-row b { color: #2b579a; }

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

    <!-- ========== HEADER (proti page e repeat) ========== -->
    <thead><tr><td>
        <table class="hdr">
            <tr>
                <td class="side l">
                    <?php foreach ($imgL as $p): ?><img src="../<?php echo e($p); ?>" alt="logo"><?php endforeach; ?>
                </td>
                <td class="mid">
                    <h1 class="company-name"><?php echo e($co_name); ?></h1>
                    <?php if ($co_tag !== ''): ?><div style="font-size:12px; color:#555; font-style:italic;"><?php echo e($co_tag); ?></div><?php endif; ?>
                    <p class="company-details"><?php echo implode('<br>', array_map('htmlspecialchars', $co_lines)); ?></p>
                </td>
                <td class="side r">
                    <?php if ($imgR): ?>
                        <?php foreach ($imgR as $p): ?><img src="../<?php echo e($p); ?>" alt="img"><?php endforeach; ?>
                    <?php else: ?>
                        <div id="qrBox"></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <div class="bill-title">Delivery Challan</div>
    </td></tr></thead>

    <!-- ========== FOOTER (proti page e repeat) ========== -->
    <tfoot><tr><td>
        <table class="foot-sig">
            <tr>
                <td><div class="sig-line">Customer Signature</div></td>
                <td><div class="sig-line">Prepared By</div></td>
                <td><div class="sig-line">Authorized Signature</div></td>
            </tr>
        </table>
        <?php if ($co_footer !== ''): ?><div class="co-footer"><?php echo e($co_footer); ?></div><?php endif; ?>
    </td></tr></tfoot>

    <!-- ========== BODY ========== -->
    <tbody><tr><td>

        <table class="info-table">
            <tr>
                <td style="width:60%;">
                    <strong>Customer Name:</strong> <?php echo e($challan['customer_name'] ?? 'N/A'); ?><br>
                    <strong>Address:</strong> <?php echo e(trim((string)($challan['address'] ?? '')) !== '' ? $challan['address'] : 'N/A'); ?><br>
                    <strong>Mobile:</strong> <?php echo e(trim((string)($challan['phone'] ?? '')) !== '' ? $challan['phone'] : 'N/A'); ?>
                </td>
                <td style="width:40%; text-align:right;">
                    <strong>Challan No:</strong> <span style="color:#2b579a; font-weight:bold;"><?php echo e($challan_no); ?></span><br>
                    <strong>Challan Date:</strong> <?php echo $date_show; ?><br>
                    <strong>Invoice Ref:</strong> <?php echo e($invoice_ref !== '' ? $invoice_ref : '-'); ?>
                    <?php if ($branch_show !== ''): ?><br><strong>Branch:</strong> <?php echo e($branch_show); ?><?php endif; ?>
                    <?php if ($wh_show !== ''): ?><br><strong>Warehouse:</strong> <?php echo e($wh_show); ?><?php endif; ?>
                </td>
            </tr>
        </table>

        <table class="item-table">
            <thead>
                <tr>
                    <th style="width:32px; text-align:center;">SL</th>
                    <th>Product Description</th>
                    <th style="text-align:center; width:70px;">Size</th>
                    <th style="text-align:center; width:50px;">Unit</th>
                    <th style="text-align:center; width:70px;">Box in Pcs</th>
                    <th style="text-align:center; width:48px;">Box</th>
                    <th style="text-align:center; width:48px;">Pcs</th>
                    <th style="text-align:right; width:95px;">Total QTY (SFT)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sl = 1; $total_box = 0; $total_pcs = 0; $total_sft = 0;
            if ($items_res && mysqli_num_rows($items_res) > 0) {
                while ($row = mysqli_fetch_assoc($items_res)) {
                    $total_box += (int)$row['box_qty'];
                    $total_pcs += (int)$row['pcs_qty'];
                    $total_sft += (float)$row['total_qty'];
            ?>
                <tr>
                    <td style="text-align:center;"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold;"><?php echo e($row['item_name']); ?></td>
                    <td style="text-align:center;"><span style="font-size:12px;"><?php echo e($row['size_name'] ?? '-'); ?></span></td>
                    <td style="text-align:center;"><?php echo e($row['unit_name'] ?? '-'); ?></td>
                    <td style="text-align:center;"><?php echo e($row['box_to_pcs'] ?? '-'); ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['box_qty']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['pcs_qty']; ?></td>
                    <td style="text-align:right; font-weight:bold;"><?php echo number_format((float)$row['total_qty'], 2); ?></td>
                </tr>
            <?php } } else { ?>
                <tr><td colspan="8" style="text-align:center; color:#888; padding:12px;">কোনো আইটেম পাওয়া যায়নি।</td></tr>
            <?php } ?>
                <tr style="background-color:#f8fafc; font-weight:bold;">
                    <td colspan="5" style="text-align:right;">Grand Total:</td>
                    <td style="text-align:center; color:#2b579a;"><?php echo $total_box; ?></td>
                    <td style="text-align:center; color:#2b579a;"><?php echo $total_pcs; ?></td>
                    <td style="text-align:right; color:#2b579a;"><?php echo number_format($total_sft, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="summary-container">
            <div class="remarks-section">
                <div class="info-row"><b>Remarks / Vehicle Info:</b> <?php echo $remarks_txt !== '' ? nl2br(e($remarks_txt)) : 'None'; ?></div>
            </div>
        </div>

    </td></tr></tbody>
</table>
</div>

<?php if ($right_img === ''): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Settings e QR image na thakle challan info diye auto QR toiri hobe
try {
    new QRCode(document.getElementById('qrBox'), {
        text: <?php echo json_encode($co_name . ' | ' . $challan_no . ' | ' . $date_show . ' | Inv: ' . $invoice_ref . ' | Qty: ' . number_format($total_sft, 2)); ?>,
        width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M
    });
} catch (e) {}
</script>
<?php endif; ?>

<script>
window.addEventListener("load", function () {
    setTimeout(function () { window.print(); }, 700);
});
</script>
</body>
</html>