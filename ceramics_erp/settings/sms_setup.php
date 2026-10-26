<?php
// settings/sms_setup.php -- SMS গেটওয়ে সেটআপ, ইভেন্ট ও টেমপ্লেট, টেস্ট SMS, লগ
include('../auth.php'); require_admin(); require_page_access();
include('../config.php');
include('settings_lib.php');
include('../sms_lib.php');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
sms_ensure($conn);

$msg = '';
$PROVIDERS = ['custom', 'bulksmsbd', 'alphasms', 'greenweb'];

// ---------- সেভ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms'])) {
    require_csrf();
    $cur = sms_settings($conn);
    $v = function ($k) { return trim($_POST[$k] ?? ''); };
    $enabled  = isset($_POST['enabled']) ? 1 : 0;
    $provider = in_array($v('provider'), $PROVIDERS, true) ? $v('provider') : 'custom';
    $method   = ($v('http_method') === 'POST') ? 'POST' : 'GET';
    $url      = $v('api_url');
    $err = '';
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $err = 'Gateway URL http:// বা https:// দিয়ে শুরু হতে হবে।';
    elseif ($enabled && $url === '') $err = 'SMS চালু করতে Gateway URL দিতে হবে।';
    elseif ($enabled && strpos($url, '{message}') === false && strpos($v('post_body'), '{message}') === false) $err = 'URL অথবা POST Body-তে {message} প্লেসহোল্ডার থাকতে হবে।';
    elseif ($enabled && strpos($url, '{to}') === false && strpos($v('post_body'), '{to}') === false) $err = 'URL অথবা POST Body-তে {to} প্লেসহোল্ডার থাকতে হবে।';
    if ($err === '') {
        $api_key    = $v('api_key')    !== '' ? $v('api_key')    : (string)($cur['api_key'] ?? '');
        $api_secret = $v('api_secret') !== '' ? $v('api_secret') : (string)($cur['api_secret'] ?? '');
        if (!empty($_POST['clear_key']))    $api_key = '';
        if (!empty($_POST['clear_secret'])) $api_secret = '';
        $post_body = $v('post_body'); $headers = trim($_POST['extra_headers'] ?? '');
        $sender = $v('sender_id'); $cc = preg_replace('/\D+/', '', $v('country_code')); $match = $v('success_match');
        $ssl = isset($_POST['ssl_verify']) ? 1 : 0;
        $oi = isset($_POST['on_invoice']) ? 1 : 0; $oc = isset($_POST['on_collection']) ? 1 : 0; $od = isset($_POST['on_delivery']) ? 1 : 0;
        $ti = $v('tpl_invoice'); $tc = $v('tpl_collection'); $td = $v('tpl_delivery');
        $st = $conn->prepare("UPDATE erp_sms_settings SET enabled=?, provider=?, http_method=?, api_url=?, post_body=?, extra_headers=?, api_key=?, api_secret=?, sender_id=?, country_code=?, success_match=?, ssl_verify=?, on_invoice=?, on_collection=?, on_delivery=?, tpl_invoice=?, tpl_collection=?, tpl_delivery=? WHERE id=1");
        $st->bind_param("issssssssssiiiisss", $enabled, $provider, $method, $url, $post_body, $headers, $api_key, $api_secret, $sender, $cc, $match, $ssl, $oi, $oc, $od, $ti, $tc, $td);
        $st->execute();
        $msg = "<div class='alert ok'>SMS সেটিংস সংরক্ষিত হয়েছে।</div>";
    } else {
        $msg = "<div class='alert err'>" . st_h($err) . "</div>";
    }
}

// ---------- টেস্ট SMS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    require_csrf();
    $res = sms_send($conn, $_POST['test_phone'] ?? '', $_POST['test_msg'] ?? '', 'test', '', true);
    $msg = "<div class='alert " . ($res['ok'] ? 'ok' : 'err') . "'><b>" . ($res['ok'] ? 'টেস্ট SMS পাঠানো হয়েছে।' : 'টেস্ট SMS ব্যর্থ হয়েছে।') . "</b> " . st_h($res['detail']) . "</div>";
}

// ---------- লগ পরিষ্কার (৯০ দিনের আগের) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    require_csrf();
    mysqli_query($conn, "DELETE FROM erp_sms_log WHERE sent_at < (NOW() - INTERVAL 90 DAY)");
    $msg = "<div class='alert ok'>৯০ দিনের আগের লগ মুছে ফেলা হয়েছে।</div>";
}

$s = sms_settings($conn);
$log = [];
$lq = mysqli_query($conn, "SELECT * FROM erp_sms_log ORDER BY id DESC LIMIT 50");
while ($lq && $x = mysqli_fetch_assoc($lq)) $log[] = $x;
$cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(status='Sent') AS s, SUM(status='Failed') AS f, COUNT(*) AS t FROM erp_sms_log WHERE sent_at >= (NOW() - INTERVAL 30 DAY)"));

st_head('SMS Setup');
echo $msg;
?>
<style>
    .hint { font-size: 11.5px; color: #777; margin-top: 3px; line-height: 1.5; }
    .hint code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; color: #b71c1c; }
    .tpl-box { display: flex; flex-direction: column; gap: 4px; border: 1px solid #e3e8ec; border-radius: 4px; padding: 10px; background: #fcfcfd; }
    .tpl-head { display: flex; align-items: center; gap: 10px; font-weight: bold; }
    .cnt { font-size: 11px; color: #666; }
    textarea.tpl { height: 74px; padding: 6px 8px; }
    .sw { display: inline-flex; align-items: center; gap: 6px; font-weight: bold; cursor: pointer; }
    .sw input { width: 17px; height: 17px; }
    .st-Sent { color: #28a745; font-weight: bold; } .st-Failed { color: #dc3545; font-weight: bold; } .st-Skipped { color: #e67e22; font-weight: bold; }
    .mini { display: flex; gap: 14px; margin-bottom: 12px; flex-wrap: wrap; }
    .mini div { background: #fff; border: 1px solid #cddc39; border-radius: 4px; padding: 8px 14px; font-size: 12px; }
    .mini b { font-size: 17px; display: block; }
    pre.resp { white-space: pre-wrap; word-break: break-all; margin: 0; font-family: inherit; font-size: 11.5px; color: #555; max-width: 380px; }
</style>
<div class="title">SMS Setup</div>

<div class="mini">
    <div>গত ৩০ দিনে পাঠানো<b style="color:#28a745;"><?php echo (int)($cnt['s'] ?? 0); ?></b></div>
    <div>ব্যর্থ<b style="color:#dc3545;"><?php echo (int)($cnt['f'] ?? 0); ?></b></div>
    <div>মোট চেষ্টা<b><?php echo (int)($cnt['t'] ?? 0); ?></b></div>
    <div>বর্তমান অবস্থা<b style="color:<?php echo !empty($s['enabled']) ? '#28a745' : '#dc3545'; ?>;"><?php echo !empty($s['enabled']) ? 'চালু' : 'বন্ধ'; ?></b></div>
</div>

<form method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?php echo st_h($_SESSION['csrf_token']); ?>">

<div class="form-panel">
    <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0">
        <button type="submit" name="save_sms" value="1" class="btn-action btn-green"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <label class="sw" style="margin-left:14px;"><input type="checkbox" name="enabled" value="1" <?php echo !empty($s['enabled']) ? 'checked' : ''; ?>> SMS সার্ভিস চালু</label>
    </div>
    <div class="panel-header">Gateway Configuration</div>
    <div class="panel-body"><div class="excel-grid">
        <div class="fg"><label>Provider (নমুনা সেটিং)</label>
            <select class="form-control" name="provider" id="provider" onchange="applyPreset(true)">
                <option value="custom" <?php echo ($s['provider'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom (নিজে বসাব)</option>
                <option value="bulksmsbd" <?php echo ($s['provider'] ?? '') === 'bulksmsbd' ? 'selected' : ''; ?>>BulkSMSBD</option>
                <option value="alphasms" <?php echo ($s['provider'] ?? '') === 'alphasms' ? 'selected' : ''; ?>>Alpha SMS</option>
                <option value="greenweb" <?php echo ($s['provider'] ?? '') === 'greenweb' ? 'selected' : ''; ?>>Greenweb</option>
            </select>
            <div class="hint">বাছলে নিচের URL/Method বসে যাবে। এগুলো নমুনা, আপনার প্রোভাইডারের বর্তমান API ডকুমেন্ট মিলিয়ে নিন।</div></div>
        <div class="fg"><label>HTTP Method</label>
            <select class="form-control" name="http_method" id="http_method" onchange="togglePost()">
                <option value="GET" <?php echo ($s['http_method'] ?? 'GET') === 'GET' ? 'selected' : ''; ?>>GET</option>
                <option value="POST" <?php echo ($s['http_method'] ?? '') === 'POST' ? 'selected' : ''; ?>>POST</option>
            </select></div>
        <div class="fg"><label>Sender ID</label><input class="form-control" name="sender_id" id="sender_id" maxlength="40" value="<?php echo st_h($s['sender_id'] ?? ''); ?>"></div>

        <div class="fg span3"><label>Gateway URL *</label>
            <input class="form-control" name="api_url" id="api_url" maxlength="500" placeholder="https://.../send?key={api_key}&to={to}&message={message}" value="<?php echo st_h($s['api_url'] ?? ''); ?>">
            <div class="hint">প্লেসহোল্ডার: <code>{to}</code> <code>{message}</code> <code>{sender}</code> <code>{api_key}</code> <code>{api_secret}</code>। মান নিজে থেকে URL-encode হয়ে বসবে।</div></div>

        <div class="fg span3" id="postBox"><label>POST Body (শুধু POST হলে)</label>
            <textarea class="form-control" name="post_body" id="post_body" style="height:64px;" placeholder="api_key={api_key}&to={to}&msg={message}   অথবা JSON: {&quot;to&quot;:&quot;{to}&quot;,&quot;text&quot;:&quot;{message}&quot;}"><?php echo st_h($s['post_body'] ?? ''); ?></textarea>
            <div class="hint"><code>{</code> দিয়ে শুরু করলে JSON হিসেবে যাবে, নইলে form-encoded।</div></div>

        <div class="fg span3"><label>Extra Headers (প্রতি লাইনে একটি, ঐচ্ছিক)</label>
            <textarea class="form-control" name="extra_headers" id="extra_headers" style="height:46px;" placeholder="Authorization: Bearer {api_key}"><?php echo st_h($s['extra_headers'] ?? ''); ?></textarea></div>

        <div class="fg"><label>API Key / Token</label>
            <input class="form-control" type="password" name="api_key" autocomplete="new-password" placeholder="<?php echo !empty($s['api_key']) ? '•••••• সংরক্ষিত (বদলাতে নতুন মান দিন)' : ''; ?>">
            <?php if (!empty($s['api_key'])) echo '<label class="hint"><input type="checkbox" name="clear_key" value="1"> সংরক্ষিত Key মুছুন</label>'; ?></div>
        <div class="fg"><label>API Secret / Password (ঐচ্ছিক)</label>
            <input class="form-control" type="password" name="api_secret" autocomplete="new-password" placeholder="<?php echo !empty($s['api_secret']) ? '•••••• সংরক্ষিত' : ''; ?>">
            <?php if (!empty($s['api_secret'])) echo '<label class="hint"><input type="checkbox" name="clear_secret" value="1"> সংরক্ষিত Secret মুছুন</label>'; ?></div>
        <div class="fg"><label>Country Code</label>
            <input class="form-control" name="country_code" id="country_code" maxlength="5" value="<?php echo st_h($s['country_code'] ?? '880'); ?>">
            <div class="hint">বাংলাদেশ: <code>880</code> (নম্বর হবে 8801XXXXXXXXX)। ফাঁকা রাখলে 01XXXXXXXXX লোকাল ফরম্যাটে যাবে।</div></div>

        <div class="fg"><label>সফল ধরার শব্দ (Success Match)</label>
            <input class="form-control" name="success_match" id="success_match" maxlength="100" value="<?php echo st_h($s['success_match'] ?? ''); ?>">
            <div class="hint">গেটওয়ের উত্তরে এই লেখা থাকলে "Sent"। ফাঁকা হলে শুধু HTTP 200-299 দেখবে।</div></div>
        <div class="fg" style="justify-content:flex-end;"><label class="sw"><input type="checkbox" name="ssl_verify" value="1" <?php echo !empty($s['ssl_verify']) ? 'checked' : ''; ?>> SSL সার্টিফিকেট যাচাই করুন</label>
            <div class="hint">লোকাল XAMPP-এ "cURL error 60" এলে এটি বন্ধ করে টেস্ট করুন। লাইভ সার্ভারে চালু রাখুন।</div></div>
    </div></div>
</div>

<div class="form-panel">
    <div class="panel-header">কখন SMS যাবে &amp; বার্তার টেমপ্লেট</div>
    <div class="panel-body">
        <div class="hint" style="margin:0 0 10px;">প্লেসহোল্ডার: <code>{customer}</code> <code>{company}</code> <code>{date}</code> <code>{invoice_no}</code> <code>{challan_no}</code> <code>{voucher_no}</code> <code>{amount}</code> <code>{paid}</code> <code>{balance}</code>। বাংলা বার্তায় প্রতি SMS ৭০ অক্ষর (লম্বা হলে ৬৭ করে ভাগ), ইংরেজিতে ১৬০ (১৫৩)।</div>
        <div style="display:grid; grid-template-columns:1fr; gap:12px;">
            <div class="tpl-box">
                <div class="tpl-head"><label class="sw"><input type="checkbox" name="on_invoice" value="1" <?php echo !empty($s['on_invoice']) ? 'checked' : ''; ?>> নতুন Sales Invoice সেভ হলে</label><span class="cnt" id="cnt_invoice"></span></div>
                <textarea class="form-control tpl" name="tpl_invoice" data-cnt="cnt_invoice"><?php echo st_h($s['tpl_invoice'] ?? ''); ?></textarea>
            </div>
            <div class="tpl-box">
                <div class="tpl-head"><label class="sw"><input type="checkbox" name="on_collection" value="1" <?php echo !empty($s['on_collection']) ? 'checked' : ''; ?>> কাস্টমারের Collection Voucher সেভ হলে</label><span class="cnt" id="cnt_collection"></span></div>
                <textarea class="form-control tpl" name="tpl_collection" data-cnt="cnt_collection"><?php echo st_h($s['tpl_collection'] ?? ''); ?></textarea>
            </div>
            <div class="tpl-box">
                <div class="tpl-head"><label class="sw"><input type="checkbox" name="on_delivery" value="1" <?php echo !empty($s['on_delivery']) ? 'checked' : ''; ?>> নতুন Delivery Challan সেভ হলে</label><span class="cnt" id="cnt_delivery"></span></div>
                <textarea class="form-control tpl" name="tpl_delivery" data-cnt="cnt_delivery"><?php echo st_h($s['tpl_delivery'] ?? ''); ?></textarea>
            </div>
        </div>
        <div class="hint" style="margin-top:10px;">শুধু <b>নতুন</b> এন্ট্রিতে SMS যায়, এডিটে যায় না। কাস্টমারের মোবাইল নম্বর না থাকলে বা ভুল হলে লগে "Skipped" দেখাবে, সেভ আটকাবে না।</div>
    </div>
</div>

<div class="alert warn">Save করার পর নিচের টেস্ট অংশ থেকে একটি SMS পাঠিয়ে দেখুন। সংরক্ষিত সেটিংসই ব্যবহার হয়, তাই আগে Save চাপতে ভুলবেন না।</div>
</form>

<form method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?php echo st_h($_SESSION['csrf_token']); ?>">
<div class="form-panel">
    <div class="panel-header">Test SMS</div>
    <div class="panel-body" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
        <div class="fg" style="width:200px;"><label>মোবাইল নম্বর</label><input class="form-control" name="test_phone" placeholder="01XXXXXXXXX" required></div>
        <div class="fg" style="flex:1; min-width:260px;"><label>বার্তা</label><input class="form-control" name="test_msg" value="Test SMS from ERP" required></div>
        <button type="submit" name="send_test" value="1" class="btn-action"><i class="fa-solid fa-paper-plane" style="color:#2b579a;"></i> Send Test</button>
    </div>
</div>
</form>

<div class="form-panel">
    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>SMS Log (সর্বশেষ ৫০টি)</span>
        <form method="POST" style="margin:0;" onsubmit="return confirm('৯০ দিনের আগের লগ মুছে ফেলবেন?')">
            <input type="hidden" name="csrf_token" value="<?php echo st_h($_SESSION['csrf_token']); ?>">
            <button type="submit" name="clear_log" value="1" class="btn-action" style="padding:2px 10px; font-size:11px;"><i class="fa-solid fa-broom" style="color:#e67e22;"></i> পুরনো লগ মুছুন</button>
        </form>
    </div>
    <div class="panel-body" style="padding:0; overflow-x:auto;">
        <table class="grid-table">
            <thead><tr><th style="width:140px;">সময়</th><th style="width:80px;">ইভেন্ট</th><th style="width:120px;">নম্বর</th><th>বার্তা</th><th style="width:75px;">অবস্থা</th><th>গেটওয়ের উত্তর</th></tr></thead>
            <tbody>
            <?php foreach ($log as $l) { ?>
                <tr>
                    <td><?php echo date('d-M-y h:i A', strtotime($l['sent_at'])); ?></td>
                    <td><?php echo st_h($l['event']); ?><?php echo $l['ref_no'] ? '<br><small style="color:#2b579a;">' . st_h($l['ref_no']) . '</small>' : ''; ?></td>
                    <td><?php echo st_h($l['phone']); ?></td>
                    <td><?php echo st_h($l['message']); ?></td>
                    <td class="st-<?php echo st_h($l['status']); ?>"><?php echo st_h($l['status']); ?></td>
                    <td><pre class="resp"><?php echo st_h(($l['http_code'] ? 'HTTP ' . $l['http_code'] . ' — ' : '') . $l['response']); ?></pre></td>
                </tr>
            <?php } if (!$log) echo "<tr><td colspan='6' class='c' style='color:#999;padding:15px;'>এখনো কোনো SMS পাঠানো হয়নি।</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// প্রোভাইডার নমুনা (প্রোভাইডারের বর্তমান ডকুমেন্ট মিলিয়ে নেবেন)
var PRESETS = {
    bulksmsbd: { method: 'GET',  url: 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={to}&senderid={sender}&message={message}', body: '', match: '202', cc: '880' },
    alphasms:  { method: 'POST', url: 'https://api.sms.net.bd/sendsms', body: 'api_key={api_key}&msg={message}&to={to}', match: '"error":0', cc: '880' },
    greenweb:  { method: 'GET',  url: 'http://api.greenweb.com.bd/api.php?token={api_key}&to={to}&message={message}', body: '', match: 'Ok', cc: '' }
};
function $id(i) { return document.getElementById(i); }
function applyPreset(fill) {
    var p = PRESETS[$id('provider').value];
    if (fill && p) {
        $id('http_method').value = p.method; $id('api_url').value = p.url; $id('post_body').value = p.body;
        $id('success_match').value = p.match; $id('country_code').value = p.cc;
    }
    togglePost();
}
function togglePost() { $id('postBox').style.display = ($id('http_method').value === 'POST') ? '' : 'none'; }
function segCount(t) {
    var uni = /[^\x00-\x7F]/.test(t), len = Array.from(t).length;
    var single = uni ? 70 : 160, multi = uni ? 67 : 153;
    var seg = len <= single ? (len ? 1 : 0) : Math.ceil(len / multi);
    return len + ' অক্ষর · ' + seg + ' SMS' + (uni ? ' (বাংলা/ইউনিকোড)' : '');
}
document.querySelectorAll('textarea.tpl').forEach(function (ta) {
    var out = $id(ta.dataset.cnt);
    function upd() { out.textContent = segCount(ta.value); }
    ta.addEventListener('input', upd); upd();
});
togglePost();
</script>
</body></html>