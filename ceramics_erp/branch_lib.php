<?php
// branch_lib.php  (project root, access.php er pashe)
// access.php er SHESH e ei line ta jog korben:   require_once __DIR__ . '/branch_lib.php';
//
// Heading er niyom (shob print/report e ek-i):
//   1) document er nijer branch (invoice/GRN/return er branch)  ->  2) login user er branch  ->  3) company name

// Login user er branch er nam. '' = kono branch assign kora nai (head office / shob branch) -> company name dekhabe
if (!function_exists('current_branch')) {
    function current_branch() {
        static $b = null;
        if ($b !== null) return $b;
        $b = '';
        if (empty($_SESSION['user_id'])) return $b;
        try {
            $st = pa_db()->prepare("SELECT b.branch_name FROM erp_users u JOIN erp_branches b ON b.id = u.branch_id WHERE u.id = ?");
            $uid = (int)$_SESSION['user_id'];
            $st->bind_param("i", $uid); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            $b = trim((string)($r['branch_name'] ?? ''));
        } catch (Throwable $e) { $b = ''; }   // branch_id column ekhono nai hole company name-i dekhabe
        $_SESSION['branch_name'] = $b;
        return $b;
    }
}

// company_info() array er name/address/phone ke branch er tothto diye bodle dey (print page er $co)
if (!function_exists('branch_apply')) {
    function branch_apply($conn, array $co, $doc_branch = '') {
        $n = trim((string)$doc_branch);
        if ($n === '') $n = current_branch();
        if ($n === '') return $co;
        if (!function_exists('branch_info')) {
            try { include_once __DIR__ . '/Settings/settings_lib.php'; } catch (Throwable $e) { return $co; }
        }
        $br = branch_info($conn, $n);
        if (!$br) return $co;
        $co['company_name'] = $br['branch_name'];
        if (trim((string)($br['address'] ?? '')) !== '') $co['address'] = trim($br['address']);
        if (trim((string)($br['phone'] ?? '')) !== '')   { $co['phone'] = trim($br['phone']); $co['mobile'] = ''; }
        return $co;
    }
}

// Report er print-head e shudhu nam lagle
if (!function_exists('branch_head_name')) {
    function branch_head_name($conn, $doc_branch = '') {
        $n = trim((string)$doc_branch);
        if ($n === '') $n = current_branch();
        if ($n !== '') return $n;
        try {
            if (!function_exists('company_info')) include_once __DIR__ . '/Settings/settings_lib.php';
            $co = company_info($conn);
            if (trim((string)($co['company_name'] ?? '')) !== '') return trim($co['company_name']);
        } catch (Throwable $e) {}
        return 'UTTARA BUILDERS';
    }
}

// Return document er branch (erp_sales_return / erp_purchase_return)
if (!function_exists('doc_branch')) {
    function doc_branch($conn, $table, $id) {
        if (!in_array($table, ['erp_sales_return', 'erp_purchase_return'], true)) return '';
        try { $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_name FROM `$table` WHERE id=" . (int)$id)); return trim((string)($r['branch_name'] ?? '')); }
        catch (Throwable $e) { return ''; }
    }
}

// Delivery Challan e branch column nai, tai oi invoice er branch
if (!function_exists('challan_branch')) {
    function challan_branch($conn, $id) {
        try {
            $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.branch_name FROM erp_delivery_challan_master m JOIN sales_invoice_master s ON s.invoice_no = m.invoice_no WHERE m.id=" . (int)$id . " LIMIT 1"));
            return trim((string)($r['branch_name'] ?? ''));
        } catch (Throwable $e) { return ''; }
    }
}