<?php
// Interval-based database backups for the report group/forum topic.
// This file is intentionally separate from the original dbbackupwizwiz.sh mechanism.
// The old backup system remains untouched.

// Cron may run this file from /root or another working directory.
// config.php uses relative includes like settings/values.php, so we must
// move to the project root before loading it. This fixes scheduled backups
// not running while manual backups from inside the bot still work.
$projectRoot = realpath(__DIR__ . '/..');
if($projectRoot){
    @chdir($projectRoot);
}

ignore_user_abort(true);
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$lockFile = sys_get_temp_dir() . '/wizwiz_report_group_backup.lock';
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
} catch (Throwable $e) {
    @file_put_contents(sys_get_temp_dir() . '/wizwiz_report_group_backup_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    if($lock){ @flock($lock, LOCK_UN); @fclose($lock); }
    exit('config_error');
}

if(function_exists('wizwiz_runReportDatabaseBackups')){
    // The function itself checks the configured interval and runs every enabled backup one-by-one.
    wizwiz_runReportDatabaseBackups(false);
}

if($lock){
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
