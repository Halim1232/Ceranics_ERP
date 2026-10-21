<?php
include('../auth.php'); require_page_access();
include('../config.php');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// প্রাইমারি কী কলাম অটো-ডিটেক্ট (id / invoice_id)
$idf = "id";
$mc = mysqli_query($conn, "SHOW COLUMNS FROM sales_invoice_master");
while ($c = mysqli_fetch_assoc($mc)) { if ($c['Key'] == 'PRI') { $idf = $c['Field']; break; } }

// ---------- ডিলিট ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $del_id = (int)($_POST['id'] ?? 0);
    $inv = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_invoice_master WHERE $idf=$del_id"));
    if (!$inv) {
        echo "<script>alert('ইনভয়েস পাওয়া যায়নি।'); window.location.href='sales_invoice_list.php';</script>"; exit;
    }
    $inv = array_change_key_case($inv, CASE_LOWER);
    branch_guard($inv['branch_name'] ?? '');   // অন্য ব্রাঞ্চের ইনভয়েস ডিলিট করা যাবে না
    $no  = mysqli_real_escape_string($conn, $inv['invoice_no']);

    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_delivery_challan_master WHERE invoice_no='$no'"));
    if ($chk['c'] > 0) {
        echo "<script>alert('এই ইনভয়েসের ডেলিভারি চালান আছে, ডিলিট করা যাবে না। আগে চালান ডিলিট করুন।'); window.location.href='sales_invoice_list.php';</script>"; exit;
    }

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM sales_invoice_details WHERE invoice_id=$del_id");
        mysqli_query($conn, "DELETE FROM erp_fund_ledger WHERE ref_type='SALE' AND ref_no='$no'");
        mysqli_query($conn, "DELETE FROM sales_invoice_master WHERE $idf=$del_id");
        mysqli_commit($conn);

        // ===== Accounts journal clean-up (commit er pore, fail korle delete bhangbe na) =====
        try {
            if (!defined('ACC_NO_AUTOSYNC')) define('ACC_NO_AUTOSYNC', true);
            include_once('../Accounts/accounts_lib.php');
            delete_journal($conn, 'SALES', $inv['invoice_no']);   // Dr Receivable / Cr Sales Revenue
            delete_journal($conn, 'COGS', $inv['invoice_no']);    // Dr COGS / Cr Inventory
            acc_sync_fund_journal($conn);                         // fund ledger er row muche gele oi payment journal o soriye dey
        } catch (Throwable $e) { /* journal clean-up fail korleo invoice delete thik thakbe */ }

        echo "<script>alert('সেলস ইনভয়েসটি সফলভাবে মুছে ফেলা হয়েছে।'); window.location.href='sales_invoice_list.php';</script>";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('ডিলিট ব্যর্থ হয়েছে।'); window.location.href='sales_invoice_list.php';</script>";
    }
    exit;
}

// ---------- তালিকা ----------
$sql = "SELECT m.*, c.customer_name,
               IFNULL(t.total, 0) AS items_total
        FROM sales_invoice_master m
        LEFT JOIN erp_customers c ON m.customer_id = c.id
        LEFT JOIN (SELECT invoice_id, SUM(total_qty * rate) AS total FROM sales_invoice_details GROUP BY invoice_id) t
               ON t.invoice_id = m.$idf
        WHERE 1=1" . branch_sql('m.branch_name') . "
        ORDER BY m.$idf DESC";
$result = mysqli_query($conn, $sql);

$rows = [];
$sum_total = 0; $sum_paid = 0; $sum_due = 0;
while ($result && $r = mysqli_fetch_assoc($result)) {
    $r2 = array_change_key_case($r, CASE_LOWER);
    $total = (float)$r2['items_total'] + (float)$r2['labor_charge'] - (float)$r2['discount_amount'];
    $paid  = (float)$r2['payment_amount'];
    $due   = $total - $paid;
    $rows[] = [
        'id'     => $r[$idf],
        'no'     => $r2['invoice_no'],
        'cust'   => $r2['customer_name'] ?? 'N/A',
        'date'   => $r2['invoice_date'],
        'branch' => $r2['branch_name'] ?? '-',
        'total'  => $total,
        'paid'   => $paid,
        'due'    => $due,
        'status' => $r2['status'] ?? 'Undelivered',
    ];
    $sum_total += $total; $sum_paid += $paid; $sum_due += $due;
}

$branch_opts = [];
try {
    $bq = mysqli_query($conn, "SELECT branch_name FROM erp_branches WHERE status='Active'" . branch_sql('branch_name') . " ORDER BY branch_name");
    while ($bq && $b = mysqli_fetch_assoc($bq)) $branch_opts[] = $b['branch_name'];
} catch (Throwable $e) { }
foreach ($rows as $r0) { if ($r0['branch'] !== '' && !in_array($r0['branch'], $branch_opts, true)) $branch_opts[] = $r0['branch']; }

function status_color($s) {
    if ($s == 'Delivered') return '#28a745';
    if ($s == 'Partial')   return '#e67e22';
    return '#dc3545';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Invoice List - Uttara Builders ERP</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
    .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
    .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 5px; }
    .btn-action:hover { background: #f0f0f0; }
    .btn-print { border: none; background: #2b579a; color: #fff; }
    .btn-print:hover { background: #234a82; }
    .form-panel { background: #fff; border: 1px solid #dddd3d; border-radius: 4px; margin-bottom: 20px; }
    .panel-header { background: #ececef; padding: 8px 15px; font-size: 13px; font-weight: bold; color: #495057; border-bottom: 1px solid #e3e34a; }
    .panel-body { padding: 15px; }
    .filter-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px 15px; margin-bottom: 12px; }
    .fg { display: flex; flex-direction: column; gap: 3px; }
    .fg label { font-size: 11px; font-weight: bold; }
    .fc { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; }
    .grid-table { width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #ccdc39; }
    .grid-table th { background: #cbd5e1; color: #222; padding: 8px; text-align: left; border: 1px solid #ccdc39; }
    .grid-table td { padding: 8px; border: 1px solid #dee2e6; background: #fff; }
    .grid-table tbody tr:hover td { background: #f1f5f9; }
    .grid-table tr.selected-row td { background: #e0f2fe !important; }
    .r { text-align: right; }
    .total-row td { background: #dbebe7 !important; font-weight: bold; border-top: 2px solid #2e7d32; }
</style>
</head>
<body>

<div class="control-bar">
    <button class="btn-action" onclick="goToNewInvoice()"><i class="fa-solid fa-plus" style="color:#28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelectedInvoice()"><i class="fa-solid fa-pen" style="color:#0498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelectedInvoice()"><i class="fa-solid fa-trash" style="color:#dc3545;"></i> Delete</button>
    <button class="btn-action btn-print" onclick="printSelectedInvoice()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="form-panel">
    <div class="panel-header">SALES INVOICE LIST</div>
    <div class="panel-body">

        <div class="filter-grid">
            <div class="fg"><label>Invoice No</label><input type="text" id="f_no" class="fc" oninput="runFilter()" placeholder="Search invoice.."></div>
            <div class="fg"><label>Customer</label><input type="text" id="f_cust" class="fc" oninput="runFilter()" placeholder="Search customer.."></div>
            <div class="fg"><label>Date From</label><input type="date" id="f_from" class="fc" onchange="runFilter()"></div>
            <div class="fg"><label>Date To</label><input type="date" id="f_to" class="fc" onchange="runFilter()"></div>
            <div class="fg"><label>Branch</label>
                <select id="f_branch" class="fc" onchange="runFilter()">
                    <option value="">All Branch</option>
                    <?php foreach ($branch_opts as $bo) echo '<option value="' . h($bo) . '">' . h($bo) . '</option>'; ?>
                </select>
            </div>
            <div class="fg"><label>Status</label>
                <select id="f_status" class="fc" onchange="runFilter()">
                    <option value="">All</option>
                    <option value="Undelivered">Undelivered</option>
                    <option value="Partial">Partial</option>
                    <option value="Delivered">Delivered</option>
                </select>
            </div>
        </div>

        <table class="grid-table" id="invoiceGridTable">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;"><input type="checkbox" id="selectAll" onclick="toggleAllRows(this)"></th>
                    <th style="width:40px; text-align:center;">SL</th>
                    <th>Invoice Number</th>
                    <th>Customer Name</th>
                    <th>Invoice Date</th>
                    <th>Branch / Warehouse</th>
                    <th class="r" style="width:110px;">Total Amount</th>
                    <th class="r" style="width:100px;">Paid</th>
                    <th class="r" style="width:100px;">Due</th>
                    <th style="width:100px; text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($rows) {
                $sl = 1;
                foreach ($rows as $row) {
                    $dateShow = $row['date'] ? date('d-M-Y', strtotime($row['date'])) : '-';
            ?>
                <tr class="inv-row" onclick="selectGridRow(this, <?php echo (int)$row['id']; ?>)" style="cursor:pointer;"
                    data-no="<?php echo h($row['no']); ?>"
                    data-cust="<?php echo h($row['cust']); ?>"
                    data-date="<?php echo h($row['date']); ?>"
                    data-branch="<?php echo h($row['branch']); ?>"
                    data-status="<?php echo h($row['status']); ?>">
                    <td style="text-align:center;" onclick="event.stopPropagation();">
                        <input type="checkbox" class="row-select" value="<?php echo (int)$row['id']; ?>" onclick="selectGridRow(this.closest('tr'), <?php echo (int)$row['id']; ?>)">
                    </td>
                    <td style="text-align:center;" class="sl-cell"><?php echo $sl++; ?></td>
                    <td style="font-weight:bold; color:#2b579a;"><?php echo h($row['no']); ?></td>
                    <td><?php echo h($row['cust']); ?></td>
                    <td><?php echo $dateShow; ?></td>
                    <td><?php echo h($row['branch']); ?></td>
                    <td class="r" style="font-weight:bold;"><?php echo number_format($row['total'], 2); ?></td>
                    <td class="r" style="color:#28a745;"><?php echo number_format($row['paid'], 2); ?></td>
                    <td class="r" style="color:#d32f2f; font-weight:bold;"><?php echo number_format($row['due'], 2); ?></td>
                    <td style="text-align:center;"><span style="color:<?php echo status_color($row['status']); ?>; font-weight:bold;"><?php echo h($row['status']); ?></span></td>
                </tr>
            <?php
                }
            ?>
                <tr class="total-row">
                    <td colspan="6" style="text-align:center; color:#2e7d32;">Grand Total</td>
                    <td class="r"><?php echo number_format($sum_total, 2); ?></td>
                    <td class="r"><?php echo number_format($sum_paid, 2); ?></td>
                    <td class="r"><?php echo number_format($sum_due, 2); ?></td>
                    <td></td>
                </tr>
            <?php
            } else {
                echo "<tr><td colspan='10' style='text-align:center; padding:15px; color:#777;'>কোনো সেলস ইনভয়েস ডাটা পাওয়া যায়নি।</td></tr>";
            }
            ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var selectedInvoiceId = null;
var selectedStatus = '';

function selectGridRow(rowElement, id) {
    document.querySelectorAll('#invoiceGridTable tbody tr').forEach(function(tr) { tr.classList.remove('selected-row'); });
    document.querySelectorAll('.row-select').forEach(function(chk) { chk.checked = false; });
    rowElement.classList.add('selected-row');
    var chk = rowElement.querySelector('.row-select');
    if (chk) chk.checked = true;
    selectedInvoiceId = id;
    selectedStatus = rowElement.getAttribute('data-status') || '';
}

// হেডারের চেকবক্স: একটি মাত্র রো সিলেক্ট থাকে, তাই এটি শুধু সবকিছু আনচেক করে
function toggleAllRows(master) {
    master.checked = false;
    document.querySelectorAll('.row-select').forEach(function(chk) { chk.checked = false; });
    document.querySelectorAll('#invoiceGridTable tbody tr').forEach(function(tr) { tr.classList.remove('selected-row'); });
    selectedInvoiceId = null;
}

function goToNewInvoice() { window.location.href = 'sales_invoice.php'; }

function editSelectedInvoice() {
    if (!selectedInvoiceId) { alert('অনুগ্রহ করে এডিট করার জন্য তালিকা থেকে একটি ইনভয়েস সিলেক্ট করুন।'); return; }
    if (selectedStatus !== 'Undelivered') {
        alert('এই ইনভয়েসের ডেলিভারি শুরু হয়ে গেছে, তাই এডিট করা যাবে না।');
        return;
    }
    window.location.href = 'sales_invoice.php?action=edit&id=' + selectedInvoiceId;
}

function deleteSelectedInvoice() {
    if (!selectedInvoiceId) { alert('অনুগ্রহ করে মুছে ফেলার জন্য তালিকা থেকে একটি ইনভয়েস সিলেক্ট করুন।'); return; }
    if (selectedStatus !== 'Undelivered') {
        alert('এই ইনভয়েসের ডেলিভারি চালান আছে, ডিলিট করা যাবে না।');
        return;
    }
    if (confirm('আপনি কি নিশ্চিত যে এই ইনভয়েসটি সম্পূর্ণ মুছে ফেলতে চান?')) {
        postDelete('sales_invoice_list.php', {id: selectedInvoiceId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'});
    }
}

function printSelectedInvoice() {
    if (!selectedInvoiceId) { alert('অনুগ্রহ করে প্রিন্ট করার জন্য তালিকা থেকে একটি ইনভয়েস সিলেক্ট করুন।'); return; }
    window.open('print_invoice.php?id=' + selectedInvoiceId, '_blank');
}

function runFilter() {
    var no   = document.getElementById('f_no').value.toUpperCase().trim();
    var cust = document.getElementById('f_cust').value.toUpperCase().trim();
    var from = document.getElementById('f_from').value;
    var to   = document.getElementById('f_to').value;
    var st   = document.getElementById('f_status').value;
    var br   = document.getElementById('f_branch').value;
    var sl = 1;

    document.querySelectorAll('.inv-row').forEach(function(row) {
        var ok = true;
        if (no   && row.getAttribute('data-no').toUpperCase().indexOf(no) === -1) ok = false;
        if (cust && row.getAttribute('data-cust').toUpperCase().indexOf(cust) === -1) ok = false;
        var d = row.getAttribute('data-date');
        if (from && d < from) ok = false;
        if (to   && d > to)   ok = false;
        if (st   && row.getAttribute('data-status') !== st) ok = false;
        if (br   && row.getAttribute('data-branch') !== br) ok = false;
        row.style.display = ok ? '' : 'none';
        if (ok) row.querySelector('.sl-cell').textContent = sl++;
    });
}
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>