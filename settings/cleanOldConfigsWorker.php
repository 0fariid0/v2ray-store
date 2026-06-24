<?php
// اجرای مرحله‌ای بررسی و پاکسازی کانفیگ‌های تمام‌شده از روی خود پنل
// Cron پیشنهادی: * * * * * php /path/to/bot/settings/cleanOldConfigsWorker.php >/dev/null 2>&1

$root = dirname(__DIR__);
chdir($root);
include_once $root . '/config.php';

if(!function_exists('v2raystore_refreshCleanOldExpiredIndex')){
    echo "clean worker functions not loaded\n";
    exit;
}

// هر اجرا فقط چند سفارش را از خود پنل بررسی می‌کند تا فشار ناگهانی به سرور و پنل وارد نشود.
$scan = v2raystore_refreshCleanOldExpiredIndex(18, 35);

$delete = ['processed'=>0, 'local_deleted'=>0, 'failed'=>0, 'skipped_renewed'=>0];
$auto = function_exists('v2raystore_cleanSettingGet') ? (string)v2raystore_cleanSettingGet('CLEAN_OLD_CONFIGS_AUTO') : 'off';
$job = function_exists('v2raystore_getCleanOldConfigsJob') ? v2raystore_getCleanOldConfigsJob() : ['state'=>0];

// حذف فقط وقتی اجرا می‌شود که صف دستی فعال باشد یا حذف خودکار روشن شده باشد.
// قبل از حذف، همان کانفیگ دوباره از پنل verify می‌شود؛ اگر تمدید شده باشد حذف نمی‌شود.
if((is_array($job) && intval($job['state'] ?? 0) === 1) || $auto === 'on'){
    if(!is_array($job) || intval($job['state'] ?? 0) !== 1){
        $days = intval(function_exists('v2raystore_cleanSettingGet') ? (v2raystore_cleanSettingGet('CLEAN_OLD_CONFIGS_DAYS') ?? 10) : 10);
        if($days <= 0) $days = 10;
        if(function_exists('v2raystore_startCleanOldConfigsJob')){
            v2raystore_startCleanOldConfigsJob($days, 'panel_expiry', 0, null);
        }
    }
    if(function_exists('v2raystore_processCleanOldConfigsJob')){
        $delete = v2raystore_processCleanOldConfigsJob(4, 20, true);
    }
}

if(php_sapi_name() === 'cli'){
    echo json_encode(['scan'=>$scan, 'delete'=>$delete], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
