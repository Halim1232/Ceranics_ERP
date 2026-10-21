<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode([]); exit; }
include('config.php');

$q = trim($_GET['q'] ?? '');


$like = '%' . $q . '%';
$stmt = $conn->prepare("
  SELECT p.id, p.item_code, p.item_name, p.size_name, p.color_name, p.brand_name,
         p.box_to_pcs, p.ratio_pcs, p.ratio_output,
         COALESCE(SUM(s.sqft_in - s.sqft_out), 0) AS stock_sft
  FROM erp_products p
  LEFT JOIN erp_stock_ledger s ON s.product_id = p.id
  WHERE p.item_name LIKE ? OR p.item_code LIKE ?
  GROUP BY p.id
  ORDER BY p.item_name
  LIMIT 300");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
    $sft = round((float)$r['stock_sft'], 2);
    $bp  = (int)$r['box_to_pcs'];
    $per = ((float)$r['ratio_pcs'] > 0 && (float)$r['ratio_output'] > 0) ? $r['ratio_output'] / $r['ratio_pcs'] : 0;

    $box = 0; $pcs = 0;
    if ($per > 0 && $bp > 0) {
        $tp  = (int)round(abs($sft) / $per);
        $box = intdiv($tp, $bp);  $pcs = $tp % $bp;
        if ($sft < 0) { $box = -$box; $pcs = -$pcs; }   // মাইনাস দেখানো, ব্লক নয়
    }
    $out[] = [
        'code' => $r['item_code'], 'name' => $r['item_name'], 'size' => $r['size_name'],
        'color' => $r['color_name'], 'brand' => $r['brand_name'],
        'sft' => number_format($sft, 2, '.', ''), 'box' => $box, 'pcs' => $pcs,
        'negative' => $sft < 0,
    ];
}
echo json_encode($out);