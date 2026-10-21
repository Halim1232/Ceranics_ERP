<?php
// stock_adj_lib.php -- Stock Adjustment (Short / Over / Damage) ar Physical Inventory er shared engine
// Caller e age $conn (config.php) thakte hobe.

function adj_ensure($conn) {
    static $done = false; if ($done) return; $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_stock_adjustment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        adj_no VARCHAR(30) NOT NULL UNIQUE,
        adj_date DATE NOT NULL,
        adj_type VARCHAR(12) NOT NULL,
        warehouse_id INT NULL,
        reason VARCHAR(60) NULL,
        remarks VARCHAR(255) NULL,
        ref_no VARCHAR(30) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (adj_type), INDEX (adj_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_stock_adjustment_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        adj_id INT NOT NULL,
        product_id INT NOT NULL,
        direction VARCHAR(3) NOT NULL,
        box_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
        pcs_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
        sqft_qty DECIMAL(14,4) NOT NULL DEFAULT 0,
        rate DECIMAL(14,2) NOT NULL DEFAULT 0,
        INDEX (adj_id), INDEX (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_physical_count (
        id INT AUTO_INCREMENT PRIMARY KEY,
        count_no VARCHAR(30) NOT NULL UNIQUE,
        count_date DATE NOT NULL,
        warehouse_id INT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'Draft',
        remarks VARCHAR(255) NULL,
        adj_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_physical_count_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        count_id INT NOT NULL,
        product_id INT NOT NULL,
        system_sft DECIMAL(14,4) NOT NULL DEFAULT 0,
        phys_box DECIMAL(14,2) NULL,
        phys_pcs DECIMAL(14,2) NULL,
        phys_sft DECIMAL(14,4) NULL,
        INDEX (count_id), INDEX (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_stock_transfer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_no VARCHAR(30) NOT NULL UNIQUE,
        transfer_date DATE NOT NULL,
        from_warehouse_id INT NOT NULL,
        to_warehouse_id INT NOT NULL,
        remarks VARCHAR(255) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (transfer_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_stock_transfer_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT NOT NULL,
        product_id INT NOT NULL,
        box_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
        pcs_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
        sqft_qty DECIMAL(14,4) NOT NULL DEFAULT 0,
        rate DECIMAL(14,2) NOT NULL DEFAULT 0,
        INDEX (transfer_id), INDEX (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function adj_has_wh($conn) {
    static $r = null;
    if ($r === null) {
        try { $q = mysqli_query($conn, "SHOW COLUMNS FROM erp_stock_ledger LIKE 'warehouse_id'"); $r = ($q && mysqli_num_rows($q) > 0); }
        catch (Throwable $e) { $r = false; }
    }
    return $r;
}

// [ ['id'=>, 'name'=>, 'branch_id'=>, 'branch_name'=>], ... ]
function adj_warehouses($conn) {
    $out = [];
    try {
        $q = mysqli_query($conn, "SELECT w.id, w.warehouse_name, w.branch_id, b.branch_name
                                  FROM erp_warehouses w LEFT JOIN erp_branches b ON b.id = w.branch_id
                                  WHERE w.status='Active' ORDER BY w.warehouse_name");
        while ($q && $x = mysqli_fetch_assoc($q)) $out[] = ['id' => (int)$x['id'], 'name' => $x['warehouse_name'], 'branch_id' => (int)$x['branch_id'], 'branch_name' => $x['branch_name']];
    } catch (Throwable $e) {}
    return $out;
}

function adj_wh_cond($conn, $wh) {
    return (adj_has_wh($conn) && (int)$wh > 0) ? " AND warehouse_id = " . (int)$wh : '';
}

// Current stock (SFT). Edit e nijer purono entry bad dite $ex_vt/$ex_no dao.
function adj_stock($conn, $pid, $wh = 0, $ex_vt = '', $ex_no = '') {
    $ex = '';
    if ($ex_no !== '') $ex = " AND NOT (voucher_type='" . mysqli_real_escape_string($conn, $ex_vt) . "' AND voucher_no='" . mysqli_real_escape_string($conn, $ex_no) . "')";
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sqft_in - sqft_out),0) AS s FROM erp_stock_ledger WHERE product_id=" . (int)$pid . adj_wh_cond($conn, $wh) . $ex));
    return (float)$r['s'];
}

// Last purchase rate, na thakle product er cost_price
function adj_rate($conn, $pid) {
    $pid = (int)$pid;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT (SELECT l.rate FROM erp_stock_ledger l WHERE l.product_id = p.id AND l.voucher_type='PURCHASE' AND l.sqft_in > 0 ORDER BY l.id DESC LIMIT 1) AS lr, p.cost_price FROM erp_products p WHERE p.id=$pid"));
    if (!$r) return 0;
    return ($r['lr'] !== null && (float)$r['lr'] > 0) ? (float)$r['lr'] : (float)$r['cost_price'];
}

// SFT -> [box, loose] (positive)
function adj_box_loose($sft, $p) {
    $bp = (int)$p['box_to_pcs']; $rp = (float)$p['ratio_pcs']; $ro = (float)$p['ratio_output'];
    $per = ($rp > 0 && $ro > 0) ? $ro / $rp : 0;
    if ($per <= 0 || $bp <= 0) return [0, 0];
    $tp = (int)round(abs($sft) / $per);
    return [intdiv($tp, $bp), $tp % $bp];
}
function adj_per_pcs($p) {
    $rp = (float)$p['ratio_pcs']; $ro = (float)$p['ratio_output'];
    return ($rp > 0 && $ro > 0) ? $ro / $rp : 0;
}

function adj_next_no($conn, $table, $col, $prefix) {
    $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(id) AS m FROM `$table`"));
    $n = ((int)$m['m']) + 1;
    do {
        $no = $prefix . '-' . date('y') . str_pad($n, 4, '0', STR_PAD_LEFT);
        $e = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM `$table` WHERE `$col`='" . mysqli_real_escape_string($conn, $no) . "'"));
        $n++;
    } while ((int)$e['c'] > 0);
    return $no;
}

// Ledger e ek line likhe. $dir = 'IN' | 'OUT'
function adj_ledger_insert($conn, $pid, $vt, $no, $dir, $box, $pcs, $sft, $rate, $wh) {
    $pid = (int)$pid; $vt = mysqli_real_escape_string($conn, $vt); $no = mysqli_real_escape_string($conn, $no);
    $box = (float)$box; $pcs = (float)$pcs; $sft = (float)$sft; $rate = (float)$rate; $val = round($sft * $rate, 2);
    $in = $dir === 'IN';
    $cols = "product_id, voucher_type, voucher_no, box_in, pcs_in, sqft_in, box_out, pcs_out, sqft_out, rate, total_value";
    $vals = "$pid, '$vt', '$no', " . ($in ? "$box, $pcs, $sft, 0, 0, 0" : "0, 0, 0, $box, $pcs, $sft") . ", $rate, $val";
    if (adj_has_wh($conn)) { $cols .= ", warehouse_id"; $vals .= ", " . ((int)$wh > 0 ? (int)$wh : 'NULL'); }
    mysqli_query($conn, "INSERT INTO erp_stock_ledger ($cols) VALUES ($vals)");
}
function adj_ledger_delete($conn, $vt, $no) {
    mysqli_query($conn, "DELETE FROM erp_stock_ledger WHERE voucher_type='" . mysqli_real_escape_string($conn, $vt) . "' AND voucher_no='" . mysqli_real_escape_string($conn, $no) . "'");
}

function adj_n2($v) { return number_format((float)$v, 2); }