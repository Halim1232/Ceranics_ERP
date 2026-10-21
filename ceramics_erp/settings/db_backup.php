<?php
include('../auth.php');
require_admin();   // পুরো ডেটাবেস (পাসওয়ার্ড হ্যাশসহ) শুধু অ্যাডমিন নামাতে পারবে
include('settings_lib.php');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$bdir = dirname(__DIR__) . '/backups';

// Pure-PHP SQL dump (mysqldump/exec er upor nirbhor kore na). Tables + data; view/trigger/procedure ashe na.
function st_dump($conn, $out) {
    $out("-- Backup of ceramics_erp\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Restore: phpMyAdmin > Import\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = [];
    $r = mysqli_query($conn, "SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    while ($row = mysqli_fetch_row($r)) $tables[] = $row[0];

    foreach ($tables as $t) {
        $c = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `$t`"));
        $out("-- Table: $t\nDROP TABLE IF EXISTS `$t`;\n" . $c[1] . ";\n\n");

        $res = mysqli_query($conn, "SELECT * FROM `$t`", MYSQLI_USE_RESULT);
        $cols = null; $batch = [];
        while ($row = mysqli_fetch_assoc($res)) {
            if ($cols === null) $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $vals = [];
            foreach ($row as $v) $vals[] = ($v === null) ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 100) { $out("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        mysqli_free_result($res);
        if ($batch) $out("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
        $out("\n");
    }
    $out("SET FOREIGN_KEY_CHECKS=1;\n");
}

function valid_backup_name($n) { return (bool)preg_match('/^ceramics_erp_\d{8}_\d{6}\.sql$/', $n); }
function fmt_size($b) { return $b >= 1048576 ? number_format($b / 1048576, 2) . ' MB' : number_format($b / 1024, 1) . ' KB'; }

$msg = '';

// ---------- Direct download ----------
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    @set_time_limit(0);
    $fname = 'ceramics_erp_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');
    st_dump($conn, function ($s) { echo $s; });
    exit;
}

// ---------- Download a saved file ----------
if (isset($_GET['action']) && $_GET['action'] === 'get' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    if (valid_backup_name($f) && is_file("$bdir/$f")) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize("$bdir/$f"));
        readfile("$bdir/$f");
        exit;
    }
    $msg = "<div class='alert err'>ফাইল পাওয়া যায়নি।</div>";
}

// ---------- CSRF (Save / Delete শুধু POST + টোকেন) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    unset($_POST['save_server'], $_POST['delete_file']);
    $msg = "<div class='alert err'>অবৈধ অনুরোধ! পেজ রিফ্রেশ করে আবার চেষ্টা করুন।</div>";
}

// ---------- Save on server ----------
if (isset($_POST['save_server'])) {
    @set_time_limit(0);
    try {
        if (!is_dir($bdir)) mkdir($bdir, 0755, true);
        // Browser theke jeno ei folder er file shorashori na khola jay
        if (!is_file("$bdir/.htaccess")) file_put_contents("$bdir/.htaccess", "Require all denied\nDeny from all\n");
        if (!is_file("$bdir/index.html")) file_put_contents("$bdir/index.html", "");
        $f = 'ceramics_erp_' . date('Ymd_His') . '.sql';
        $fh = fopen("$bdir/$f", 'w');
        if (!$fh) throw new Exception('backups ফোল্ডারে লেখা যাচ্ছে না।');
        st_dump($conn, function ($s) use ($fh) { fwrite($fh, $s); });
        fclose($fh);
        $msg = "<div class='alert ok'>ব্যাকআপ সংরক্ষিত হয়েছে: <b>" . st_h($f) . "</b> (" . fmt_size(filesize("$bdir/$f")) . ")</div>";
    } catch (Throwable $e) {
        $msg = "<div class='alert err'>ব্যাকআপ ব্যর্থ: " . st_h($e->getMessage()) . "</div>";
    }
}

// ---------- Delete a saved file ----------
if (isset($_POST['delete_file'])) {
    $f = basename($_POST['delete_file']);
    if (valid_backup_name($f) && is_file("$bdir/$f") && unlink("$bdir/$f")) $msg = "<div class='alert ok'>ফাইল ডিলিট হয়েছে।</div>";
    else $msg = "<div class='alert err'>ডিলিট করা যায়নি।</div>";
}

$files = [];
if (is_dir($bdir)) {
    foreach (scandir($bdir) as $f) {
        if (valid_backup_name($f)) $files[] = ['name' => $f, 'size' => filesize("$bdir/$f"), 'time' => filemtime("$bdir/$f")];
    }
    usort($files, function ($a, $b) { return $b['time'] <=> $a['time']; });
}

st_head('Database Backup Utility');
echo $msg;
?>
<div class="title">Database Backup Utility</div>

<div class="form-panel">
    <div class="panel-header">Take Backup (ceramics_erp)</div>
    <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn-action btn-green" href="db_backup.php?action=download"><i class="fa-solid fa-download"></i> Download Backup (.sql)</a>
        <form method="POST" style="margin:0" onsubmit="return confirm('সার্ভারে (ceramics_erp/backups) ব্যাকআপ সংরক্ষণ করবেন?')">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button class="btn-action" name="save_server" value="1"><i class="fa-solid fa-server" style="color:#3498db"></i> Save on Server</button>
        </form>
    </div>
</div>

<div class="alert warn">
    ⚠ ব্যাকআপ ফাইলে কাস্টমার, সাপ্লায়ার, হিসাব ও ইউজার পাসওয়ার্ড সহ <b>সব ডাটা</b> থাকে, ফাইলটি নিরাপদ জায়গায় রাখুন। শুধু এই PC তে রাখলে PC নষ্ট হলে ব্যাকআপও হারাবে, নিয়মিত পেনড্রাইভ বা ক্লাউডে কপি করুন।<br>
    Restore করতে: phpMyAdmin &rarr; ceramics_erp &rarr; <b>Import</b> &rarr; .sql ফাইল সিলেক্ট। (Restore এ বর্তমান টেবিল মুছে ব্যাকআপের ডাটা বসে যায়, তাই সাবধানে করুন।)
</div>

<div class="form-panel">
    <div class="panel-header">Saved Backups (server)</div>
    <div class="panel-body" style="padding:0">
        <table class="grid-table">
            <thead><tr><th style="width:40px">SL</th><th>File</th><th>Date</th><th class="r" style="width:110px">Size</th><th class="c" style="width:200px">Action</th></tr></thead>
            <tbody>
            <?php $sl = 1; foreach ($files as $f): ?>
                <tr>
                    <td><?php echo $sl++; ?></td>
                    <td style="color:#0056b3;font-weight:500"><?php echo st_h($f['name']); ?></td>
                    <td><?php echo date('d-M-Y h:i A', $f['time']); ?></td>
                    <td class="r"><?php echo fmt_size($f['size']); ?></td>
                    <td class="c">
                        <a class="btn-action" style="padding:3px 10px" href="db_backup.php?action=get&file=<?php echo urlencode($f['name']); ?>"><i class="fa-solid fa-download" style="color:#28a745"></i> Download</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('এই ব্যাকআপ ফাইল ডিলিট করবেন?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button class="btn-action" style="padding:3px 10px" name="delete_file" value="<?php echo st_h($f['name']); ?>"><i class="fa-solid fa-trash" style="color:#dc3545"></i> Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach;
            if (!$files) echo "<tr><td colspan='5' class='c' style='color:#999;padding:15px'>এখনো কোনো ব্যাকআপ সংরক্ষিত নেই।</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>
</body></html>