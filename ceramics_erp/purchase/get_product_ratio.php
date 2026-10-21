<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['id' => 0, 'error' => 'unauthorized']); exit; }
include_once('../access.php');
if (!page_access_allowed('sales/sales_invoice_list.php')
 && !page_access_allowed('purchase/purchase_grn_factory.php')
 && !page_access_allowed('purchase/purchase_grn.php')) pa_deny(true);
include('../config.php');

if (isset($_GET['product_id'])) {
    $pid = (int)$_GET['product_id'];
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in - sqft_out),0) AS s FROM erp_stock_ledger WHERE product_id=$pid" . branch_wh_sql('warehouse_id')));
    echo json_encode(['id' => $pid, 'stock' => (float)$r['s']]);
    exit;
}

if (isset($_GET['product_name'])) {
    $p_name = mysqli_real_escape_string($conn, trim($_GET['product_name']));
    $res = mysqli_query($conn, "SELECT id, size_name, color_name, unit_name, cost_price, box_to_pcs, ratio_pcs, ratio_output
                                FROM erp_products WHERE UPPER(TRIM(item_name)) = UPPER('$p_name') LIMIT 1");
    echo ($res && mysqli_num_rows($res) > 0) ? json_encode(mysqli_fetch_assoc($res)) : json_encode(['id' => 0]);
    exit;
}
echo json_encode(['id' => 0]);