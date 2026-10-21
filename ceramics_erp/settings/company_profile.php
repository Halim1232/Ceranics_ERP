<?php
include('../auth.php'); require_page_access();
include('settings_lib.php');
st_ensure($conn);
$msg = '';

// Header image slots: DB column => label  (logo_path = Left 1, age theke chhilo)
$slots = [
    'left'  => ['logo_path' => 'Left Image 1 (Logo)', 'left_img2' => 'Left Image 2'],
    'right' => ['right_img1' => 'Right Image 1 (QR / Logo)', 'right_img2' => 'Right Image 2'],
];

if (isset($_POST['save_company'])) {
    $cur = company_info($conn);
    $img = [];
    $err = '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    foreach ($slots as $side => $cols) {
        foreach ($cols as $col => $label) {
            $img[$col] = $cur[$col] ?? '';
            if (!empty($_POST['remove_' . $col])) $img[$col] = '';

            if ($err === '' && isset($_FILES[$col]) && $_FILES[$col]['error'] === UPLOAD_ERR_OK) {
                $f    = $_FILES[$col];
                $info = @getimagesize($f['tmp_name']);
                if (!$info || !isset($exts[$info['mime']])) {
                    $err = "$label: শুধু JPG / PNG / WEBP ছবি দেওয়া যাবে।";
                } elseif ($f['size'] > 1048576) {
                    $err = "$label: ছবি ১ MB এর বেশি হওয়া যাবে না।";
                } else {
                    $dir = defined('TENANT_UPLOAD_DIR') ? rtrim(TENANT_UPLOAD_DIR, '/\\') : dirname(__DIR__) . '/uploads';   // customer-wise folder
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $name = 'company_' . $col . '_' . time() . '.' . $exts[$info['mime']];
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) $img[$col] = (defined('TENANT_UPLOAD_REL') ? TENANT_UPLOAD_REL : 'uploads/') . $name;
                    else $err = "$label: আপলোড ব্যর্থ হয়েছে (uploads ফোল্ডার write permission চেক করুন)।";
                }
            }
        }
    }

    if ($err === '') {
        $v = function ($k) { return trim($_POST[$k] ?? ''); };
        if ($v('company_name') === '') {
            $err = 'কোম্পানির নাম আবশ্যক।';
        } else {
            $st = $conn->prepare("UPDATE erp_company_profile SET company_name=?, tagline=?, address=?, phone=?, mobile=?, email=?, website=?, bin_no=?, trade_license=?, invoice_footer=?, logo_path=?, left_img2=?, right_img1=?, right_img2=? WHERE id=1");
            $a = [$v('company_name'), $v('tagline'), $v('address'), $v('phone'), $v('mobile'), $v('email'), $v('website'), $v('bin_no'), $v('trade_license'), $v('invoice_footer'),
                  $img['logo_path'], $img['left_img2'], $img['right_img1'], $img['right_img2']];
            $st->bind_param("ssssssssssssss", ...$a);
            $st->execute();
            $msg = "<div class='alert ok'>কোম্পানি প্রোফাইল সফলভাবে সংরক্ষিত হয়েছে।</div>";
        }
    }
    if ($err !== '') $msg = "<div class='alert err'>" . st_h($err) . "</div>";
}

$co = company_info($conn);
st_head('Company Profile Setup');
echo $msg;
?>
<div class="title">Company Profile Setup</div>
<form method="POST" enctype="multipart/form-data">
    <div class="form-panel">
        <div class="control-bar" style="margin:0;border-radius:4px 4px 0 0">
            <button type="submit" name="save_company" class="btn-action btn-green"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </div>
        <div class="panel-header">Company Information</div>
        <div class="panel-body"><div class="excel-grid">
            <div class="fg span2"><label>Company Name *</label><input class="form-control" name="company_name" required value="<?php echo st_h($co['company_name'] ?? ''); ?>"></div>
            <div class="fg"><label>Tagline</label><input class="form-control" name="tagline" value="<?php echo st_h($co['tagline'] ?? ''); ?>"></div>
            <div class="fg span3"><label>Address</label><input class="form-control" name="address" value="<?php echo st_h($co['address'] ?? ''); ?>"></div>
            <div class="fg"><label>Phone</label><input class="form-control" name="phone" value="<?php echo st_h($co['phone'] ?? ''); ?>"></div>
            <div class="fg"><label>Mobile</label><input class="form-control" name="mobile" value="<?php echo st_h($co['mobile'] ?? ''); ?>"></div>
            <div class="fg"><label>Email</label><input class="form-control" type="email" name="email" value="<?php echo st_h($co['email'] ?? ''); ?>"></div>
            <div class="fg"><label>Website</label><input class="form-control" name="website" value="<?php echo st_h($co['website'] ?? ''); ?>"></div>
            <div class="fg"><label>BIN / VAT No</label><input class="form-control" name="bin_no" value="<?php echo st_h($co['bin_no'] ?? ''); ?>"></div>
            <div class="fg"><label>Trade License No</label><input class="form-control" name="trade_license" value="<?php echo st_h($co['trade_license'] ?? ''); ?>"></div>
            <div class="fg span3"><label>Invoice / Challan Footer Note</label><input class="form-control" name="invoice_footer" placeholder="যেমন: বিক্রিত মাল ফেরত নেওয়া হয় না" value="<?php echo st_h($co['invoice_footer'] ?? ''); ?>"></div>
        </div></div>
    </div>

    <?php foreach ($slots as $side => $cols): ?>
    <div class="form-panel">
        <div class="panel-header"><?php echo $side === 'left' ? 'Header Images — Left Side (max 2)' : 'Header Images — Right Side (max 2)'; ?></div>
        <div class="panel-body" style="display:flex;gap:40px;flex-wrap:wrap">
            <?php foreach ($cols as $col => $label): ?>
            <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap">
                <div style="width:140px;height:90px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;background:#fafafa">
                    <?php if (!empty($co[$col])): ?>
                        <img src="../<?php echo st_h($co[$col]); ?>" style="max-width:130px;max-height:80px" alt="">
                    <?php else: ?><span style="color:#999">No image</span><?php endif; ?>
                </div>
                <div>
                    <div class="fg"><label><?php echo st_h($label); ?> (JPG/PNG/WEBP, max 1 MB)</label><input type="file" name="<?php echo $col; ?>" accept="image/png,image/jpeg,image/webp"></div>
                    <?php if (!empty($co[$col])): ?>
                        <label style="display:block;margin-top:6px"><input type="checkbox" name="remove_<?php echo $col; ?>" value="1"> Remove korun</label>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</form>
<div class="alert warn">Right side e kono chobi na dile print e invoice info diye auto QR code dekhabe.</div>
</body></html>