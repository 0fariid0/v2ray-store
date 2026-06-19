<?php
// Batch checker for users who left the required channel.
// Run by cron every few minutes. It processes a small batch each run so the bot does not hang.
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/jdf.php';

@set_time_limit(55);
@ignore_user_abort(true);

if(v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on') !== 'on') exit;
$channel = trim((string)($botState['lockChannel'] ?? ''));
if($channel === '') exit;

$batch = intval(v2raystore_pro_setting('CHANNEL_LEAVE_CHECK_BATCH', '80'));
if($batch < 10) $batch = 10;
if($batch > 150) $batch = 150;
$offset = intval(v2raystore_pro_setting('CHANNEL_LEAVE_CHECK_OFFSET', '0'));
$text = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_TEXT', '⚠️ شما از کانال ربات خارج شدید. برای ادامه استفاده از ربات، لطفاً دوباره عضو کانال شوید.');
$now = time();

$stmt = $connection->prepare("SELECT `id`,`userid`,`last_join_state`,`last_channel_leave_notice` FROM `users` WHERE `id` > ? AND `userid` != ? ORDER BY `id` ASC LIMIT ?");
$stmt->bind_param('iii', $offset, $admin, $batch);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if($res->num_rows <= 0){
    v2raystore_pro_set_setting('CHANNEL_LEAVE_CHECK_OFFSET', '0');
    exit;
}

$lastId = $offset;
while($u = $res->fetch_assoc()){
    $lastId = intval($u['id']);
    $uid = intval($u['userid']);
    if($uid <= 0) continue;

    $r = bot('getChatMember', ['chat_id'=>$channel, 'user_id'=>$uid, '_timeout'=>7]);
    if(!is_object($r) || empty($r->ok) || empty($r->result->status)){
        usleep(120000);
        continue;
    }
    $status = (string)$r->result->status;
    $prev = (string)($u['last_join_state'] ?? '');
    $leftNow = in_array($status, ['left','kicked'], true);
    $wasLeft = in_array($prev, ['left','kicked'], true);

    if($leftNow && !$wasLeft){
        $noticeAt = intval($u['last_channel_leave_notice'] ?? 0);
        if($noticeAt < $now - 86400){
            $send = sendMessage($text, null, 'HTML', $uid);
            $ok = !(is_object($send) && isset($send->ok) && !$send->ok);
            if($ok){
                $stmt = $connection->prepare("UPDATE `users` SET `last_channel_leave_notice`=? WHERE `userid`=?");
                if($stmt){ $stmt->bind_param('ii', $now, $uid); $stmt->execute(); $stmt->close(); }
            }
        }
    }

    if($status !== $prev){
        $stmt = $connection->prepare("UPDATE `users` SET `last_join_state`=? WHERE `userid`=?");
        if($stmt){ $stmt->bind_param('si', $status, $uid); $stmt->execute(); $stmt->close(); }
    }
    usleep(120000);
}

v2raystore_pro_set_setting('CHANNEL_LEAVE_CHECK_OFFSET', (string)$lastId);
?>
