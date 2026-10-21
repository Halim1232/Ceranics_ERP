<?php
// ১. ডিবাগ মোড (ডেভেলপমেন্টের জন্য) — লাইভ সার্ভারে গেলে display_errors 0 করে দিন
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ২. অথ + ডাটাবেজ কানেকশন
include('../auth.php'); require_page_access(); include('../config.php');
$message = "";

// ---------- হেল্পার ফাংশন ----------
// নিরাপদ আউটপুট: key না থাকলে বা null হলেও Warning/Deprecated আসবে না
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
function ev($arr, $key, $default = '') { return h($arr[$key] ?? $default); }

// ডাটাবেজ রেজাল্ট থেকে <option> বানায় (selected সহ)
function db_options($res, $col, $selected = '') {
    $out = '';
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $val = (string)($r[$col] ?? '');
        if ($val === '') continue;
        $sel = ($val === (string)$selected) ? ' selected' : '';
        $out .= "<option value='" . h($val) . "'$sel>" . h($val) . "</option>";
    }
    return $out;
}

// Product Unit মাস্টার থেকে লোড করা ইউনিট অ্যারের <option> বানায়
// $default ডাটাবেজে না থাকলেও যেন ড্রপডাউন ফাঁকা না হয়, তাই fallback হিসেবে যোগ হবে
function unit_options(array $units, $default, $selected = '') {
    $want = ($selected !== '' && $selected !== null) ? (string)$selected : (string)$default;
    $list = $units;
    $found = false;
    foreach ($list as $u) { if (strcasecmp($u, $want) === 0) { $found = true; break; } }
    if (!$found && $want !== '') array_unshift($list, $want);
    $out = '';
    foreach ($list as $u) {
        $sel = (strcasecmp($u, $want) === 0) ? ' selected' : '';
        $out .= "<option value='" . h($u) . "'$sel>" . h($u) . "</option>";
    }
    return $out;
}

$action  = $_GET['action'] ?? '';
$is_edit = ($action === 'edit' && isset($_GET['id']));

// ৩. অটো আইটেম কোড
$auto_p_res = mysqli_query($conn, "SELECT MAX(CAST(item_code AS UNSIGNED)) AS max_prod_code FROM erp_products WHERE item_code REGEXP '^[0-9]+$'");
$auto_p_row = mysqli_fetch_assoc($auto_p_res);
$generated_item_code = (!empty($auto_p_row['max_prod_code']) && $auto_p_row['max_prod_code'] > 0)
                       ? ($auto_p_row['max_prod_code'] + 1) : 1001;

// ৪. সেভ / আপডেট
$edit_data   = [];
$save_failed = false;
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_product'])) {
    $item_code     = mysqli_real_escape_string($conn, trim($_POST['item_code'] ?? ''));
    $item_name     = mysqli_real_escape_string($conn, trim($_POST['item_name'] ?? ''));
    $group_name    = mysqli_real_escape_string($conn, $_POST['group_name'] ?? '');
    $category_name = mysqli_real_escape_string($conn, $_POST['category_name'] ?? '');
    $unit_name     = mysqli_real_escape_string($conn, $_POST['unit_name'] ?? '');
    $size_name     = mysqli_real_escape_string($conn, $_POST['size_name'] ?? '');
    $color_name    = mysqli_real_escape_string($conn, $_POST['color_name'] ?? '');
    $grade_name    = mysqli_real_escape_string($conn, $_POST['grade_name'] ?? '');
    $brand_name    = mysqli_real_escape_string($conn, $_POST['brand_name'] ?? '');

    $cost_price = empty($_POST['cost_price']) ? 0.00 : floatval($_POST['cost_price']);
    $sale_price = empty($_POST['sale_price']) ? 0.00 : floatval($_POST['sale_price']);

    $is_compound       = isset($_POST['is_compound']) ? 1 : 0;
    $negative_sales    = isset($_POST['negative_sales']) ? 1 : 0;
    $negative_delivery = isset($_POST['negative_delivery']) ? 1 : 0;

    // 0 সেভ হলে delivery_challan / sales এ (x / ratio_pcs) এ Division by zero হতো, তাই ফাঁকা হলে 1
    $box_to_pcs   = (floatval($_POST['box_to_pcs'] ?? 0)   > 0) ? floatval($_POST['box_to_pcs'])   : 1;
    $ratio_pcs    = (floatval($_POST['ratio_pcs'] ?? 0)    > 0) ? floatval($_POST['ratio_pcs'])    : 1;
    $ratio_output = (floatval($_POST['ratio_output'] ?? 0) > 0) ? floatval($_POST['ratio_output']) : 1;

    $update_id = $is_edit ? (int)$_GET['id'] : 0;

    try {
        // ডুপ্লিকেট কোড চেক
        $dup = mysqli_query($conn, "SELECT id FROM erp_products WHERE item_code='$item_code' AND id<>$update_id LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) throw new Exception("এই Item Code ($item_code) আগে থেকেই আছে!");

        if ($update_id > 0) {
            $query = "UPDATE erp_products SET item_code='$item_code', item_name='$item_name', group_name='$group_name', category_name='$category_name', unit_name='$unit_name', size_name='$size_name', color_name='$color_name', brand_name='$brand_name', grade_name='$grade_name', cost_price='$cost_price', sale_price='$sale_price', is_compound=$is_compound, negative_sales=$negative_sales, negative_delivery=$negative_delivery, box_to_pcs=$box_to_pcs, ratio_pcs=$ratio_pcs, ratio_output=$ratio_output WHERE id=$update_id";
        } else {
            $query = "INSERT INTO erp_products (item_code, item_name, group_name, category_name, unit_name, size_name, color_name, brand_name, grade_name, cost_price, sale_price, is_compound, negative_sales, negative_delivery, box_to_pcs, ratio_pcs, ratio_output)
                      VALUES ('$item_code', '$item_name', '$group_name', '$category_name', '$unit_name', '$size_name', '$color_name', '$brand_name', '$grade_name', '$cost_price', '$sale_price', $is_compound, $negative_sales, $negative_delivery, $box_to_pcs, $ratio_pcs, $ratio_output)";
        }
        mysqli_query($conn, $query);
        $message = "<div class='alert success'>পণ্যটি সফলভাবে সংরক্ষিত হয়েছে।</div>";
        echo "<script>setTimeout(function(){ window.location.href='product_list.php'; }, 1000);</script>";
    } catch (Throwable $e) {
        // config.php এ MYSQLI_REPORT_STRICT আছে, তাই SQL error এখানে exception হিসেবে আসে
        $message = "<div class='alert error'>ত্রুটি: " . h($e->getMessage()) . "</div>";
        $save_failed = true;
        // ইউজারের টাইপ করা ডাটা ফর্মে ধরে রাখা
        $edit_data = array_merge($_POST, [
            'is_compound' => $is_compound, 'negative_sales' => $negative_sales, 'negative_delivery' => $negative_delivery
        ]);
    }
}

// ৫. ডিলিট (লেনদেন থাকলে ডিলিট হবে না — নইলে stock/invoice এর JOIN ভেঙে যায়)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do_delete'] ?? '') === '1') {
    require_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    $used = 0;
    try {
        foreach (['erp_stock_ledger', 'sales_invoice_details', 'erp_delivery_challan_details'] as $t) {
            $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM $t WHERE product_id=$delete_id"));
            $used += (int)$r['c'];
        }
    } catch (Throwable $e) { /* টেবিল না থাকলে স্কিপ */ }

    if ($used > 0) {
        echo "<script>alert('এই পণ্যের লেনদেন (স্টক/ইনভয়েস/চালান) আছে, তাই ডিলিট করা যাবে না।'); window.location.href='product_list.php';</script>";
    } else {
        mysqli_query($conn, "DELETE FROM erp_products WHERE id=$delete_id");
        echo "<script>alert('পণ্যটি সফলভাবে তালিকা থেকে মুছে ফেলা হয়েছে।'); window.location.href='product_list.php';</script>";
    }
    exit;
}

// ৬. এডিট মোডের ডাটা
if ($is_edit && !$save_failed) {
    $edit_id  = (int)$_GET['id'];
    $edit_res = mysqli_query($conn, "SELECT * FROM erp_products WHERE id = $edit_id");
    if ($edit_res && mysqli_num_rows($edit_res) > 0) $edit_data = mysqli_fetch_assoc($edit_res);
}
$form_open = $is_edit || $save_failed;

// ৭. লিস্ট কুয়েরি
$product_list_res = mysqli_query($conn, "SELECT id, item_code, item_name, group_name, category_name, size_name, color_name, grade_name, brand_name, unit_name FROM erp_products ORDER BY id DESC");

// ৮. ড্রপডাউন ডাটা
$group_res = mysqli_query($conn, "SELECT DISTINCT group_name FROM erp_product_groups WHERE status='Active' ORDER BY group_name");
$cat_res   = mysqli_query($conn, "SELECT DISTINCT category_name FROM erp_product_categories WHERE status='Active' ORDER BY category_name");
$size_res  = mysqli_query($conn, "SELECT DISTINCT size_name FROM erp_product_sizes WHERE status='Active' ORDER BY size_name");
$color_res = mysqli_query($conn, "SELECT DISTINCT color_name FROM erp_product_colors WHERE status='Active' ORDER BY color_name");
$brand_res = mysqli_query($conn, "SELECT DISTINCT brand_name FROM erp_product_brands WHERE status='Active' ORDER BY brand_name");
$grade_res = mysqli_query($conn, "SELECT DISTINCT grade_name FROM erp_product_grades WHERE status='Active' ORDER BY grade_name");

// 🚀 Product Unit মাস্টার থেকে ইউনিটগুলো একবারেই অ্যারেতে লোড (UOM + Compound — সব ড্রপডাউনে ব্যবহার হবে)
$units = [];
$unit_res = mysqli_query($conn, "SELECT DISTINCT unit_name FROM erp_product_units WHERE status='Active' AND unit_name<>'' ORDER BY unit_name");
while ($unit_res && ($ur = mysqli_fetch_assoc($unit_res))) { $units[] = $ur['unit_name']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; padding: 12px; margin: 0; }
        .control-bar { background: #ececef; padding: 10px; border-radius: 4px; display: flex; gap: 8px; margin-bottom: 12px; border: 1px solid #cdcd4a; }
        .btn-action { padding: 6px 15px; font-size: 13px; font-weight: bold; border: 1px solid #d1d1d1; background: #fff; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; }
        .form-panel { background: #fff; border: 1px solid #cddc39; border-radius: 4px; margin-bottom: 20px; padding: 15px; }
        .excel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 15px; }
        .form-group { display: flex; flex-direction: column; gap: 3px; }
        .form-group label { font-size: 11px; font-weight: bold; color: #444; }
        .form-control { height: 28px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; outline: none; box-sizing: border-box; width: 100%; }
        .compound-inline-grid { display: flex; align-items: center; gap: 10px; background: #f8fafc; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 4px; margin-top: 15px; font-size: 12px; font-weight: bold; }
        .grid-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #cddc39; margin-top: 10px; }
        .grid-table th { background-color: #dbebe7; color: #222; padding: 8px; text-align: left; font-weight: bold; border: 1px solid #cddc39; }
        .grid-table td { padding: 7px 8px; border: 1px solid #dee2e6; background-color: #fff; }
        .alert { padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
    </style>
</head>
<body>
<?php if (!empty($message)) echo $message; ?>

<div class="control-bar" id="actionGridButtons" style="display: <?php echo $form_open ? 'none' : 'flex'; ?>;">
    <button class="btn-action" onclick="showForm()"><i class="fa-solid fa-plus" style="color: #28a745;"></i> New</button>
    <button class="btn-action" onclick="editSelected()"><i class="fa-solid fa-pen" style="color: #3498db;"></i> Edit</button>
    <button class="btn-action" onclick="deleteSelected()"><i class="fa-solid fa-trash" style="color: #e74c3c;"></i> Delete</button>
</div>

<div class="form-panel" id="productMainFormPanel" style="display: <?php echo $form_open ? 'block' : 'none'; ?>;">
    <form method="POST" action="">
        <div class="control-bar" style="margin-bottom: 10px; background: #ececef; padding: 5px; border-radius: 4px;">
            <button type="submit" name="save_product" class="btn-action" style="background: #28a745; color: white; padding: 4px 15px; font-weight: bold;"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button type="button" class="btn-action" onclick="hideForm()" style="padding: 4px 15px; font-weight: bold;"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>

        <div style="font-size: 12px; font-weight: bold; color: #333; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 3px;">ITEM MASTER INFO</div>

        <div class="excel-grid">
            <div class="form-group">
                <label>Item Code (Auto/Type)</label>
                <input type="number" name="item_code" class="form-control" style="text-align: left;" required value="<?php echo isset($edit_data['item_code']) ? ev($edit_data, 'item_code') : h($generated_item_code); ?>" onfocus="this.select()">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Item Name</label>
                <input type="text" name="item_name" class="form-control" placeholder="e.g. Kada Combi" required value="<?php echo ev($edit_data, 'item_name'); ?>">
            </div>

            <div class="form-group"><label>Item Group</label>
                <select name="group_name" class="form-control"><option value="">Select Group</option><?php echo db_options($group_res, 'group_name', $edit_data['group_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Item Size</label>
                <select name="size_name" class="form-control"><option value="">Select Size</option><?php echo db_options($size_res, 'size_name', $edit_data['size_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Item Grade</label>
                <select name="grade_name" class="form-control"><option value="">Select Grade</option><?php echo db_options($grade_res, 'grade_name', $edit_data['grade_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Item Category</label>
                <select name="category_name" class="form-control"><option value="">Select Category</option><?php echo db_options($cat_res, 'category_name', $edit_data['category_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Color</label>
                <select name="color_name" class="form-control"><option value="">Select Color</option><?php echo db_options($color_res, 'color_name', $edit_data['color_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Purchase Price</label>
                <input type="number" name="cost_price" class="form-control" style="text-align: right;" step="0.01" onfocus="this.select()" value="<?php echo ev($edit_data, 'cost_price', '0.00'); ?>"></div>
            <div class="form-group"><label>Item Brand</label>
                <select name="brand_name" class="form-control"><option value="">Select Brand</option><?php echo db_options($brand_res, 'brand_name', $edit_data['brand_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Item Unit(UOM)</label>
                <select name="unit_name" class="form-control"><?php echo unit_options($units, 'SFT', $edit_data['unit_name'] ?? ''); ?></select></div>
            <div class="form-group"><label>Sale Price</label>
                <input type="number" name="sale_price" class="form-control" style="text-align: right;" step="0.01" onfocus="this.select()" value="<?php echo ev($edit_data, 'sale_price', '0.00'); ?>"></div>
        </div>

        <div style="margin-top: 15px; display: flex; gap: 40px; font-size: 11px; font-weight: bold; color: #333;">
            <div><input type="checkbox" name="is_compound" id="isCompoundCheck" onchange="toggleCompoundSection()" <?php echo !empty($edit_data['is_compound']) ? 'checked' : ''; ?>> <label for="isCompoundCheck">Is Item Compound Unit?</label></div>
            <div><input type="checkbox" name="negative_sales" id="negSales" <?php echo !empty($edit_data['negative_sales']) ? 'checked' : ''; ?>> <label for="negSales">Is Negative Sales Allow?</label></div>
            <div><input type="checkbox" name="negative_delivery" id="negDelv" <?php echo !empty($edit_data['negative_delivery']) ? 'checked' : ''; ?>> <label for="negDelv">Is Negative Delivery Allow?</label></div>
        </div>

        <div style="border: 1px solid #cbd5e1; border-radius: 4px; margin-top: 10px; padding: 10px;" id="compoundConversionFieldsPanel">
            <div style="font-size: 11px; font-weight: bold; color: #2e7d32; margin-bottom: 8px; text-transform: uppercase;">Compound Unit Conversion</div>
            <div class="compound-inline-grid" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; font-weight: bold;">
                <!-- 🚀 সব ইউনিট ড্রপডাউন এখন Product Unit মাস্টার (erp_product_units) থেকে আসছে -->
                <div>Unit Of <select name="comp_unit" class="form-control" style="width:80px; display:inline-block;"><?php echo unit_options($units, 'BOX'); ?></select></div>
                <div>=</div>
                <div>Base Unit Qty <input type="number" name="box_to_pcs" class="form-control" style="width:55px; display:inline-block; text-align:center;" value="<?php echo ev($edit_data, 'box_to_pcs'); ?>" placeholder="0" onfocus="this.select()"></div>
                <div>Base Unit <select name="comp_base_unit" class="form-control" style="width:80px; display:inline-block;"><?php echo unit_options($units, 'PCS'); ?></select></div>
                <div>/</div>
                <div>Unit Qty <input type="number" name="ratio_pcs" class="form-control" style="width:55px; display:inline-block; text-align:center;" value="<?php echo ev($edit_data, 'ratio_pcs'); ?>" placeholder="0" onfocus="this.select()"></div>
                <div>Unit <select name="ratio_unit_1" class="form-control" style="width:80px; display:inline-block;"><?php echo unit_options($units, 'PCS'); ?></select></div>
                <div>=</div>
                <div>Output Qty <input type="number" name="ratio_output" class="form-control" style="width:65px; display:inline-block; text-align:center;" step="0.01" value="<?php echo ev($edit_data, 'ratio_output'); ?>" placeholder="0.00" onfocus="this.select()"></div>
                <div>Output Unit <select name="ratio_unit_2" class="form-control" style="width:80px; display:inline-block;"><?php echo unit_options($units, 'SFT'); ?></select></div>
            </div>
        </div>
    </form>
</div>

<div style="background: #fff; padding: 15px; border: 1px solid #cdcd4a; border-radius: 4px; display: <?php echo $form_open ? 'none' : 'block'; ?>;" id="productGridAreaView">
    <table class="grid-table" id="productGridTableMaster">
        <thead>
            <tr>
                <th style="width: 38px;"><input type="checkbox"></th>
                <th>Part No. (Code)</th>
                <th>Item Name</th>
                <th>Product Group</th>
                <th>Product Category</th>
                <th>Size</th>
                <th>Color</th>
                <th>Grade</th>
                <th>Brand</th>
                <th>Item UOM</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($product_list_res && mysqli_num_rows($product_list_res) > 0) {
                while ($row = mysqli_fetch_assoc($product_list_res)) {
            ?>
            <tr id="prod_row_<?php echo (int)$row['id']; ?>" onclick="selectProductRow(this, <?php echo (int)$row['id']; ?>)" style="cursor: pointer;">
                <td style="text-align: center;"><input type="checkbox" class="prod-row-select" value="<?php echo (int)$row['id']; ?>" onclick="event.stopPropagation(); selectProductRow(this.closest('tr'), this.value);"></td>
                <td><?php echo h($row['item_code']); ?></td>
                <td style="color: #4050b3; font-weight: 500;"><?php echo h($row['item_name']); ?></td>
                <td><?php echo h($row['group_name'] ?: '-'); ?></td>
                <td><?php echo h($row['category_name'] ?: '-'); ?></td>
                <td><?php echo h($row['size_name'] ?: '-'); ?></td>
                <td><?php echo h($row['color_name'] ?: '-'); ?></td>
                <td><?php echo h($row['grade_name'] ?: '-'); ?></td>
                <td style="font-weight: bold; color: #2e7d32;"><?php echo h($row['brand_name'] ?: '-'); ?></td>
                <td><?php echo h($row['unit_name'] ?: 'SFT'); ?></td>
            </tr>
            <?php
                }
            } else { echo "<tr><td colspan='10' style='text-align:center; padding:15px; color:#999;'>কোনো পণ্যের রেকর্ড পাওয়া যায়নি।</td></tr>"; }
            ?>
        </tbody>
    </table>
</div>
<script>
var selectedProductId = null;

function showForm() {
    document.getElementById('productMainFormPanel').style.display = 'block';
    document.getElementById('actionGridButtons').style.display = 'none';
    document.getElementById('productGridAreaView').style.display = 'none';
    toggleCompoundSection();
}
function hideForm() { window.location.href = 'product_list.php'; }

function selectProductRow(rowElement, id) {
    var table = rowElement.closest('table');
    table.querySelectorAll("tbody tr").forEach(function(tr) { tr.style.backgroundColor = ""; });
    table.querySelectorAll('.prod-row-select').forEach(function(chk) { chk.checked = false; });
    rowElement.style.backgroundColor = "#bddeb6";
    var chkBox = rowElement.querySelector('.prod-row-select');
    if (chkBox) chkBox.checked = true;
    selectedProductId = id;
}

function editSelected() { if (!selectedProductId) { alert('অনুগ্রহ করে একটি পণ্য সিলেক্ট করুন!'); return; } window.location.href = 'product_list.php?action=edit&id=' + selectedProductId; }
function deleteSelected() { if (!selectedProductId) { alert('অনুগ্রহ করে একটি পণ্য সিলেক্ট করুন!'); return; } if (!confirm('আপনি কি নিশ্চিত যে পণ্যটি তালিকা থেকে মুছে ফেলতে চান?')) return; postDelete('product_list.php', {id: selectedProductId, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'}); }

function toggleCompoundSection() {
    var chk = document.getElementById("isCompoundCheck");
    var panel = document.getElementById("compoundConversionFieldsPanel");
    if (panel) { panel.style.display = (chk && chk.checked) ? "block" : "none"; }
}

window.addEventListener("DOMContentLoaded", toggleCompoundSection);
</script>
<script src="../csrf_delete.js"></script>
</body>
</html>