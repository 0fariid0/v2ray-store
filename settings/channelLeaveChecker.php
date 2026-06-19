<?php
// Cron optional: checks users who left the locked channel and sends the configured notice.
include_once '../baseInfo.php';
include_once '../config.php';

@set_time_limit(55);
@ignore_user_abort(true);

if(!function_exists('v2raystore_pro_process_channel_leave_notice')) exit;
$channel = trim((string)($botState['lockChannel'] ?? ''));
if($channel === '') exit;
if(function_exists('v2raystore_pro_setting') && v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on') !== 'on') exit;

$limit = intval($botState['channel_leave_check_batch'] ?? 80);
if($limit < 10) $limit = 10;
if($limit > 200) $limit = 200;
$adminId = intval($admin ?? 0);

$res = $connection->query("SELECT * FROM `users` WHERE `userid` != '$adminId' AND COALESCE(`last_join_state`, '') NOT IN ('left','kicked') ORDER BY `last_channel_leave_notice` ASC, `id` ASC LIMIT " . intval($limit));
if(!$res) exit;

while($user = $res->fetch_assoc()){
    $uid = intval($user['userid'] ?? 0);
    if($uid <= 0) continue;
    $status = 'unknown';
    $r = bot('getChatMember', ['chat_id'=>$channel, 'user_id'=>$uid, '_timeout'=>8]);
    if(is_object($r) && !empty($r->ok) && isset($r->result->status)) $status = (string)$r->result->status;
    if($status === 'unknown') continue;
    v2raystore_pro_process_channel_leave_notice($user, $status);
    usleep(70000);
}
?>
