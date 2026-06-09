<?php
// Interval-based database backups for the report group/forum topic.
// This file is intentionally separate from the original dbbackupwizwiz.sh mechanism.
// The old backup system remains untouched.

ignore_user_abort(true);
set_time_limit(0);

$lockFile = sys_get_temp_dir() . '/wizwiz_report_group_backup.lock';
$lock = @fopen($lockFile, 'c');
if($lock && !@flock($lock, LOCK_EX | LOCK_NB)){
    exit('locked');
}
if($lock){
    @ftruncate($lock, 0);
    @fwrite($lock, 'pid=' . getmypid() . ' time=' . date('Y-m-d H:i:s'));
}

require_once __DIR__ . '/../config.php';

if(function_exists('wizwiz_runReportDatabaseBackups')){
    // The function itself checks the configured interval and runs every enabled backup one-by-one.
    wizwiz_runReportDatabaseBackups(false);
}

if($lock){
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
