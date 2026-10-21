<?php
// কাস্টমারের বকেয়া = opening + ইনভয়েসের বাকি − কালেকশন ± জার্নাল
function customer_prev_balance($conn, $cid, $idf = 'Invoice_ID', $before_id = 0, $exclude_return_id = 0) {
    $cid = (int)$cid; if ($cid <= 0) return 0;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT opening_due FROM erp_customers WHERE id=$cid"));
    $bal = (float)($r['opening_due'] ?? 0);

    $cond = $before_id > 0 ? "AND m.$idf < " . (int)$before_id : "";
    $q = mysqli_query($conn, "SELECT COALESCE(SUM(IFNULL(t.total,0) + m.labor_charge - m.discount_amount - m.payment_amount),0) AS due
        FROM sales_invoice_master m
        LEFT JOIN (SELECT invoice_id, SUM(total_qty * rate) AS total FROM sales_invoice_details GROUP BY invoice_id) t ON t.invoice_id = m.$idf
        WHERE m.customer_id=$cid $cond");
    $bal += (float)mysqli_fetch_assoc($q)['due'];

    // Sales Return: (return amount - refund) customer er due theke kombe
    try {
        $rc = $before_id > 0 ? "AND invoice_id < " . (int)$before_id : "";
        $rx = $exclude_return_id > 0 ? "AND id <> " . (int)$exclude_return_id : "";
        $sr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - discount_amount - refund_amount),0) AS x FROM erp_sales_return WHERE customer_id=$cid $rc $rx"));
        $bal -= (float)($sr['x'] ?? 0);
    } catch (Throwable $e) { /* erp_sales_return table na thakle ignore */ }

    $v = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(CASE
            WHEN voucher_type='COLLECTION' THEN -amount
            WHEN voucher_type='JOURNAL' AND entry_side='DR' THEN amount
            WHEN voucher_type='JOURNAL' AND entry_side='CR' THEN -amount
            ELSE 0 END),0) AS x FROM erp_vouchers WHERE party_type='CUSTOMER' AND party_id=$cid"));
    return $bal + (float)$v['x'];
}

// সাপ্লায়ারের পাওনা = GRN-এর বাকি − পেমেন্ট ভাউচার ± জার্নাল
function supplier_balance($conn, $sid, $exclude_return_id = 0) {
    $sid = (int)$sid; if ($sid <= 0) return 0;
    $q = mysqli_query($conn, "SELECT COALESCE(SUM(IFNULL(t.items,0) + g.labor_charge - g.discount_amount - g.payment_amount),0) AS due
        FROM erp_purchase_grn g
        LEFT JOIN (SELECT voucher_no, SUM(sqft_in * rate) AS items FROM erp_stock_ledger WHERE voucher_type='PURCHASE' GROUP BY voucher_no) t ON t.voucher_no = g.grn_no
        WHERE g.supplier_id=$sid");
    $bal = (float)mysqli_fetch_assoc($q)['due'];

    // Purchase Return: (return amount - discount - refund) supplier er pawna theke kombe
    try {
        $rx = $exclude_return_id > 0 ? "AND id <> " . (int)$exclude_return_id : "";
        $pr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - discount_amount - refund_amount),0) AS x FROM erp_purchase_return WHERE supplier_id=$sid $rx"));
        $bal -= (float)($pr['x'] ?? 0);
    } catch (Throwable $e) { /* erp_purchase_return table na thakle ignore */ }

    $v = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(CASE
            WHEN voucher_type='PAYMENT' THEN -amount
            WHEN voucher_type='JOURNAL' AND entry_side='CR' THEN amount
            WHEN voucher_type='JOURNAL' AND entry_side='DR' THEN -amount
            ELSE 0 END),0) AS x FROM erp_vouchers WHERE party_type='SUPPLIER' AND party_id=$sid"));
    return $bal + (float)$v['x'];
}