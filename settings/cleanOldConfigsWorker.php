<?php
// اجرای سبک پاکسازی/بررسی کانفیگ‌های تمام‌شده از روی خود پنل
// Cron پیشنهادی توسط v2raystore.sh نصب می‌شود:
// هر دقیقه 3 بار اجرا می‌شود: ثانیه 0، 20 و 40. هر بار فقط 5 کانفیگ بررسی می‌شود.

$root = dirname(__DIR__);
chdir($root);
include_once $root . '/config.php';

if(!function_exists('v2raystore_runCleanOldPanelScanStep')){
    echo "clean worker functions not loaded\n";
    exit;
}

// جلوگیری از هم‌پوشانی اجراها؛ اگر پنل کند باشد، اجرای بعدی همزمان وارد نمی‌شود.
$lockFile = sys_get_temp_dir() . '/v2raystore_clean_old_configs_worker.lock';
$lock = @fopen($lockFile, 'c');
if($lock && !@flock($lock, LOCK_EX | LOCK_NB)){
    if(php_sapi_name() === 'cli') echo "worker already running\n";
    exit;
}

// بررسی پنل فقط وقتی انجام می‌شود که:
// 1) بررسی دستی فعال شده باشد، یا
// 2) ساعت 4 صبح ایران بررسی روزانه شروع شود.
$scan = v2raystore_runCleanOldPanelScanStep(5, 17, true);

$delete = ['processed'=>0, 'local_deleted'=>0, 'failed'=>0, 'skipped_renewed'=>0];
$auto = function_exists('v2raystore_cleanSettingGet') ? (string)v2raystore_cleanSettingGet('CLEAN_OLD_CONFIGS_AUTO') : 'off';
$job = function_exists('v2raystore_getCleanOldConfigsJob') ? v2raystore_getCleanOldConfigsJob() : ['state'=>0];

// حذف مرحله‌ای فقط وقتی دکمه «شروع حذف» زده شده باشد یا حذف خودکار روشن باشد.
// کانفیگ‌های شناسایی‌شده قبلاً داخل جدول آماده حذف هستند؛ اینجا فقط چند مورد را سبک حذف می‌کنیم.
if((is_array($job) && intval($job['state'] ?? 0) === 1) || $auto === 'on'){
    if(!is_array($job) || intval($job['state'] ?? 0) !== 1){
        $days = intval(function_exists('v2raystore_cleanSettingGet') ? (v2raystore_cleanSettingGet('CLEAN_OLD_CONFIGS_DAYS') ?? 10) : 10);
        if($days <= 0) $days = 10;
        $ready = function_exists('v2raystore_quickCountCleanOldConfigCandidates') ? v2raystore_quickCountCleanOldConfigCandidates($days, 'panel_expiry') : 0;
        if($ready > 0 && function_exists('v2raystore_startCleanOldConfigsJob')){
            v2raystore_startCleanOldConfigsJob($days, 'panel_expiry', 0, $ready);
            $job = v2raystore_getCleanOldConfigsJob();
        }
    }
    if(is_array($job) && intval($job['state'] ?? 0) === 1 && function_exists('v2raystore_processCleanOldConfigsJob')){
        $delete = v2raystore_processCleanOldConfigsJob(2, 12, true);
    }
}

// اگر ادمین پنل پاکسازی را باز کرده باشد، همان پیام قبلی ویرایش می‌شود؛ پیام تکراری ارسال نمی‌شود.
if(function_exists('v2raystore_updateCleanOldUiMessage')){
    v2raystore_updateCleanOldUiMessage(['scan'=>$scan, 'delete'=>$delete], false);
}

if($lock){
    @flock($lock, LOCK_UN);
    @fclose($lock);
}

if(php_sapi_name() === 'cli'){
    echo json_encode(['scan'=>$scan, 'delete'=>$delete], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
