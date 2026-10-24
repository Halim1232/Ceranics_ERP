<?php
// sms_lib.php (প্রজেক্টের রুটে, fund_lib.php এর পাশে) -- SMS গেটওয়ে ইঞ্জিন
// ব্যবহার:  include_once('../sms_lib.php');
//           sms_send_event($conn, 'invoice', $customer_id, ['invoice_no' => ..., 'amount' => ...]);
// SMS ব্যর্থ হলেও মূল সেভ আটকায় না (সব জায়গায় try/catch এর ভেতরে ডাকুন)।

function sms_defaults() {
    return [
        'invoice'    => 'প্রিয} {customer}, ইনভয়েস {invoice_no} (তারিখ {date}) এ মোট {amount} টাকা, জমা {paid} টাকা। বর্তমান বকেয়া {balance} টাকা। - {company}',
        'collection' => 'প্রিয} {customer}, আপনার {amount} টাকা জমা পেয়েছি (ভাউচার {voucher_no})। বর্তমান বকেয়া {balance} টাকা। ধন্যবাদ। - {company}',
        'delivery'   => 'প্রিয} {customer}, চালান {challan_no} এর মাধ্যমে আপনার মাল ডেলিভারি দেওয়া হয়েছে (ইনভয়েস {invoice_no})। - {company}',
    ];
}

function sms_ensure($conn) {
    static $done = false; if ($done) return; $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_sms_settings (
        id INT PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        provider VARCHAR(30) NOT NULL DEFAULT 'custom',
        http_method VARCHAR(4) NOT NULL DEFAULT 'GET',
        api_url VARCHAR(500) NULL,
        post_body TEXT NULL,
        extra_headers TEXT NULL,
        api_key VARCHAR(255) NULL,
        api_secret VARCHAR(255) NULL,
        sender_id VARCHAR(40) NULL,
        country_code VARCHAR(5) NOT NULL DEFAULT '880',
        success_match VARCHAR(100) NULL,
        ssl_verify TINYINT(1) NOT NULL DEFAULT 1,
        on_invoice TINYINT(1) NOT NULL DEFAULT 0,
        on_collection TINYINT(1) NOT NULL DEFAULT 0,
        on_delivery TINYINT(1) NOT NULL DEFAULT 0,
        tpl_invoice TEXT NULL,
        tpl_collection TEXT NULL,
        tpl_delivery TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erp_sms_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        event VARCHAR(20) NOT NULL,
        phone VARCHAR(20) NULL,
        message TEXT NULL,
        status VARCHAR(10) NOT NULL,
        http_code INT NULL,
        response VARCHAR(500) NULL,
        ref_no VARCHAR(50) NULL,
        created_by INT NULL,
        INDEX (sent_at), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM erp_sms_settings"));
    if (!(int)$c['c']) {
        $d = sms_defaults();
        $st = $conn->prepare("INSERT INTO erp_sms_settings (id, tpl_invoice, tpl_collection, tpl_delivery) VALUES (1, ?, ?, ?)");
        $st->bind_param("sss", $d['invoice'], $d['collection'], $d['delivery']);
        $st->execute();
    }
}

function sms_settings($conn) {
    sms_ensure($conn);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM erp_sms_settings WHERE id=1"));
    return $r ?: [];
}

// মোবাইল নম্বর গেটওয়ের ফরম্যাটে: কান্ট্রি কোড থাকলে 8801XXXXXXXXX, ফাঁকা থাকলে লোকাল 01XXXXXXXXX
function sms_normalize_phone($raw, $cc = '880') {
    $d = preg_replace('/\D+/', '', (string)$raw);
    $cc = preg_replace('/\D+/', '', (string)$cc);
    if ($d === '') return '';
    if ($cc !== '' && strpos($d, $cc) === 0 && strlen($d) >= strlen($cc) + 9) { $local = substr($d, strlen($cc)); }
    else { $local = ltrim($d, '0'); }
    if (strlen($local) < 9 || strlen($local) > 11) return '';
    return ($cc === '') ? '0' . $local : $cc . $local;
}

function sms_render($tpl, array $vars) {
    foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    return trim(preg_replace('/\{[a-z_]+\}/', '', $tpl));   // বাকি অজানা প্লেসহোল্ডার মুছে ফেলে
}

function sms_fill($tpl, array $map, $mode) {   // $mode: url | json | raw
    foreach ($map as $k => $v) {
        $v = (string)$v;
        if ($mode === 'url')       $val = rawurlencode($v);
        elseif ($mode === 'json')  $val = substr(json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1);
        else                       $val = $v;
        $tpl = str_replace('{' . $k . '}', $val, $tpl);
    }
    return $tpl;
}

// গেটওয়েতে রিকোয়েস্ট; ফেরত: ['code' => http, 'resp' => body/error]
function sms_http(array $s, $phone, $message) {
    $map = ['to' => $phone, 'message' => $message, 'sender' => $s['sender_id'] ?? '',
            'api_key' => $s['api_key'] ?? '', 'api_secret' => $s['api_secret'] ?? ''];
    $method = (($s['http_method'] ?? 'GET') === 'POST') ? 'POST' : 'GET';
    $url = sms_fill((string)$s['api_url'], $map, 'url');
    $body = ''; $headers = [];
    if ($method === 'POST') {
        $tpl = (string)($s['post_body'] ?? '');
        $isJson = (strlen(ltrim($tpl)) && ltrim($tpl)[0] === '{');
        $body = sms_fill($tpl, $map, $isJson ? 'json' : 'url');
        $headers[] = 'Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded');
    }
    foreach (preg_split('/\R/', (string)($s['extra_headers'] ?? '')) as $h) {
        $h = trim(sms_fill($h, $map, 'raw'));
        if ($h !== '' && strpos($h, ':') !== false) $headers[] = $h;
    }
    $verify = !empty($s['ssl_verify']);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) { $resp = 'cURL: ' . curl_error($ch); $code = 0; }
        curl_close($ch);
        return ['code' => $code, 'resp' => (string)$resp];
    }
    // cURL না থাকলে (php.ini তে extension=curl চালু করা ভালো)
    $opts = ['http' => ['method' => $method, 'timeout' => 8, 'ignore_errors' => true, 'header' => implode("\r\n", $headers), 'content' => $body],
             'ssl'  => ['verify_peer' => $verify, 'verify_peer_name' => $verify]];
    $resp = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($resp === false) return ['code' => 0, 'resp' => 'গেটওয়ের সাথে সংযোগ হয়নি'];
    return ['code' => $code, 'resp' => (string)$resp];
}

function sms_log($conn, $event, $phone, $message, $status, $code, $resp, $ref) {
    try {
        $resp = mb_substr(trim((string)$resp), 0, 480);
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $st = $conn->prepare("INSERT INTO erp_sms_log (event, phone, message, status, http_code, response, ref_no, created_by) VALUES (?,?,?,?,?,?,?,?)");
        $st->bind_param("ssssissi", $event, $phone, $message, $status, $code, $resp, $ref, $uid);
        $st->execute();
    } catch (Throwable $e) { error_log('sms_log: ' . $e->getMessage()); }
}

// একটি SMS পাঠানো। $force = true হলে "চালু" সুইচ না দেখেই পাঠায় (টেস্টের জন্য)
function sms_send($conn, $to_raw, $message, $event = 'manual', $ref = '', $force = false) {
    $s = sms_settings($conn);
    if (!$force && empty($s['enabled'])) return ['ok' => false, 'status' => 'Disabled', 'detail' => 'SMS বন্ধ আছে'];
    if (trim((string)($s['api_url'] ?? '')) === '') return ['ok' => false, 'status' => 'Failed', 'detail' => 'Gateway URL সেট করা নেই'];
    $message = trim((string)$message);
    if ($message === '') return ['ok' => false, 'status' => 'Failed', 'detail' => 'বার্তা ফাঁকা'];
    $phone = sms_normalize_phone($to_raw, $s['country_code'] ?? '880');
    if ($phone === '') {
        sms_log($conn, $event, trim((string)$to_raw), $message, 'Skipped', 0, 'মোবাইল নম্বর সঠিক নয়/নেই', $ref);
        return ['ok' => false, 'status' => 'Skipped', 'detail' => 'মোবাইল নম্বর সঠিক নয়'];
    }
    $r = sms_http($s, $phone, $message);
    $match = trim((string)($s['success_match'] ?? ''));
    $ok = ($r['code'] >= 200 && $r['code'] < 300) && ($match === '' || stripos($r['resp'], $match) !== false);
    sms_log($conn, $event, $phone, $message, $ok ? 'Sent' : 'Failed', $r['code'], $r['resp'], $ref);
    return ['ok' => $ok, 'status' => $ok ? 'Sent' : 'Failed', 'detail' => 'HTTP ' . $r['code'] . ' — ' . mb_substr(trim($r['resp']), 0, 200)];
}

// ইভেন্ট ভিত্তিক SMS: $event = invoice | collection | delivery
function sms_send_event($conn, $event, $customer_id, array $vars = []) {
    $map = ['invoice' => ['on_invoice', 'tpl_invoice'], 'collection' => ['on_collection', 'tpl_collection'], 'delivery' => ['on_delivery', 'tpl_delivery']];
    if (!isset($map[$event])) return null;
    $s = sms_settings($conn);
    if (empty($s['enabled']) || empty($s[$map[$event][0]])) return null;   // বন্ধ থাকলে কিছুই করে না
    $cid = (int)$customer_id; if ($cid <= 0) return null;
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT customer_name, phone FROM erp_customers WHERE id=$cid"));
    if (!$c) return null;
    $company = '';
    try { $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT company_name FROM erp_company_profile WHERE id=1")); $company = trim($r['company_name'] ?? ''); } catch (Throwable $e) { }
    $vars += ['customer' => $c['customer_name'], 'company' => $company];
    $msg = sms_render((string)($s[$map[$event][1]] ?? ''), $vars);
    $ref = $vars['invoice_no'] ?? ($vars['voucher_no'] ?? ($vars['challan_no'] ?? ''));
    return sms_send($conn, $c['phone'], $msg, $event, $ref);
} 