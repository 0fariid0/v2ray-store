<?php
// V2Ray Store scheduled report runner.
// Runs from cron and sends daily stats + database backups to the configured report group/topic.

$projectRoot = realpath(__DIR__ . '/..');
if($projectRoot){
    @chdir($projectRoot);
}

ignore_user_abort(true);
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$lockFile = sys_get_temp_dir() . '/v2raystore_report_group_backup.lock';
$lock = @fopen($lockFile, 'c');
if($lock && !@flock($lock, LOCK_EX | LOCK_NB)){
    exit('locked');
}
if($lock){
    @ftruncate($lock, 0);
    @fwrite($lock, 'pid=' . getmypid() . ' time=' . date('Y-m-d H:i:s'));
}

try {
    require_once __DIR__ . '/../config.php';

    // آمار روزانه باید از کران هم اجرا شود، نه فقط از دکمه دستی داخل ربات.
    if(function_exists('wizwiz_processDailyChannelStats')){
        wizwiz_processDailyChannelStats(false);
    }elseif(function_exists('v2raystore_processDailyChannelStats')){
        v2raystore_processDailyChannelStats(false);
    }

    // بکاپ دیتابیس؛ برای سازگاری با نسخه‌های قدیمی و جدید هر دو نام تابع پشتیبانی می‌شود.
    if(function_exists('wizwiz_runReportDatabaseBackups')){
        wizwiz_runReportDatabaseBackups(false);
    }elseif(function_exists('v2raystore_runReportDatabaseBackups')){
        v2raystore_runReportDatabaseBackups(false);
    }
} catch (Throwable $e) {
    @file_put_contents(sys_get_temp_dir() . '/v2raystore_report_group_backup_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

if($lock){
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
