<?php
include_once '../baseInfo.php';
include_once '../config.php';
include_once 'jdf.php';

@set_time_limit(55);
@ignore_user_abort(true);

// بروزرسانی نرخ ارز مثل نسخه قبلی، اما بدون توقف صف ارسال در صورت خطای API نرخ ارز
$rateLimit = $botState['rateLimit'] ?? 0;
if(time() > $rateLimit){
    $rateRaw = @curl_get_file_contents("https://api.pooleno.ir/v1/currency/short-name/trx?type=buy");
    $rate = @json_decode($rateRaw, true);
    if(is_array($rate) && isset($rate['priceUsdt'], $rate['priceFiat'])){
        $botState['USDRate'] = round($rate['priceUsdt'], 2);
        $botState['TRXRate'] = round($rate['priceFiat'] / 10, 2);
        $botState['rateLimit'] = strtotime("+1 hour");

        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
        $stmt->execute();
        $isExists = $stmt->get_result();
        $stmt->close();
        if($isExists->num_rows > 0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
        else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
        $newData = json_encode($botState, JSON_UNESCAPED_UNICODE);

        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $newData);
        $stmt->execute();
        $stmt->close();
    }
}

$lockFile = sys_get_temp_dir() . '/v2raystore_broadcast_queue_' . md5(__DIR__) . '.lock';
$lockHandle = @fopen($lockFile, 'c');
if(!$lockHandle) exit;
if(!@flock($lockHandle, LOCK_EX | LOCK_NB)){
    // اجرای قبلی هنوز در حال ارسال است؛ از اجرای همزمان و قفل شدن CPU جلوگیری می‌کنیم.
    exit;
}
register_shutdown_function(function() use ($lockHandle){
    if($lockHandle){
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

function v2raystore_broadcast_api_error($response){
    if(!is_object($response)) return 'پاسخ نامعتبر از تلگرام';
    if(isset($response->ok) && $response->ok) return '';
    return (string)($response->description ?? 'خطای نامشخص تلگرام');
}

function v2raystore_broadcast_retry_after($response){
    if(is_object($response) && isset($response->parameters) && isset($response->parameters->retry_after)){
        return intval($response->parameters->retry_after);
    }
    $desc = v2raystore_broadcast_api_error($response);
    if(preg_match('/retry after\s+(\d+)/i', $desc, $m)) return intval($m[1]);
    return 0;
}

function v2raystore_broadcast_is_blocked_error($response){
    $desc = strtolower(v2raystore_broadcast_api_error($response));
    return strpos($desc, 'bot was blocked') !== false
        || strpos($desc, 'user is deactivated') !== false
        || strpos($desc, 'chat not found') !== false
        || strpos($desc, 'forbidden') !== false
        || strpos($desc, 'kicked') !== false;
}

function v2raystore_broadcast_send_to_user($info, $targetUserId, $keys){
    $type = $info['type'] ?? 'text';
    $pinAfterSend = false;
    if(strpos((string)$type, 'pin_') === 0){
        $pinAfterSend = true;
        $type = substr((string)$type, 4);
        if($type === '') $type = 'text';
    }
    $file_id = $info['file_id'] ?? '';
    $chat_id = $info['chat_id'] ?? '';
    $text = $info['text'] ?? '';
    $message_id = intval($info['message_id'] ?? 0);

    $base = [
        'chat_id' => $targetUserId,
        '_timeout' => 12,
    ];

    $pinResult = function($response) use ($pinAfterSend, $targetUserId){
        if(!$pinAfterSend || !is_object($response) || empty($response->ok) || empty($response->result->message_id)) return $response;
        bot('pinChatMessage', [
            'chat_id' => $targetUserId,
            'message_id' => intval($response->result->message_id),
            'disable_notification' => true,
            '_timeout' => 8,
        ]);
        return $response;
    };

    if($type == 'text'){
        return $pinResult(bot('sendMessage', $base + [
            'text' => $text,
            'reply_markup' => $keys,
        ]));
    }
    if($type == 'music'){
        return $pinResult(bot('sendAudio', $base + [
            'audio' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys,
        ]));
    }
    if($type == 'video'){
        return $pinResult(bot('sendVideo', $base + [
            'video' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys,
        ]));
    }
    if($type == 'voice'){
        return $pinResult(bot('sendVoice', $base + [
            'voice' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys,
        ]));
    }
    if($type == 'photo'){
        return $pinResult(bot('sendPhoto', $base + [
            'photo' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys,
        ]));
    }
    if($type == 'forwardall'){
        return $pinResult(bot('forwardMessage', [
            'chat_id' => $targetUserId,
            'from_chat_id' => $chat_id,
            'message_id' => $message_id,
            '_timeout' => 12,
        ]));
    }

    return $pinResult(bot('sendDocument', $base + [
        'document' => $file_id,
        'caption' => $text,
        'reply_markup' => $keys,
    ]));
}

function v2raystore_broadcast_admin_message($text){
    global $admin;
    return bot('sendMessage', [
        'chat_id' => $admin,
        'text' => $text,
        'parse_mode' => 'HTML',
        '_timeout' => 10,
    ]);
}

$stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` = 1 AND `type` != 'updateConfigs' AND (`pause_until` = 0 OR `pause_until` <= ?) ORDER BY `id` ASC LIMIT 1");
$now = time();
$stmt->bind_param('i', $now);
$stmt->execute();
$list = $stmt->get_result();
$stmt->close();

if($list->num_rows <= 0) exit;

$info = $list->fetch_assoc();
$sendId = intval($info['id']);
$type = $info['type'] ?? 'text';
$targetType = function_exists('farid_normalizeBroadcastTarget') ? farid_normalizeBroadcastTarget($info['target_type'] ?? 'all') : 'all';
$targetTitle = function_exists('farid_getBroadcastTargetTitle') ? farid_getBroadcastTargetTitle($targetType) : 'همه کاربران';
$targetCount = intval($info['total_count'] ?? 0);
if($targetCount <= 0 && function_exists('farid_countBroadcastTargets')) $targetCount = farid_countBroadcastTargets($targetType);
$condition = function_exists('farid_getBroadcastTargetCondition') ? farid_getBroadcastTargetCondition($targetType, 'u') : '1=1';

$settings = function_exists('farid_getBroadcastThrottleSettings') ? farid_getBroadcastThrottleSettings() : ['batch_size'=>12, 'delay_ms'=>250, 'max_runtime'=>22, 'progress_interval'=>120];
$batchSize = intval($settings['batch_size']);
$delayMs = intval($settings['delay_ms']);
$maxRuntime = intval($settings['max_runtime']);
$progressInterval = intval($settings['progress_interval']);

$offset = intval($info['offset'] ?? 0);
$lastUserId = intval($info['last_user_id'] ?? 0);
$sentCount = intval($info['sent_count'] ?? 0);
$failedCount = intval($info['failed_count'] ?? 0);
$blockedCount = intval($info['blocked_count'] ?? 0);
$startedAt = intval($info['started_at'] ?? 0);
$lastReportAt = intval($info['last_report_at'] ?? 0);

if($targetCount > 0 && intval($info['total_count'] ?? 0) <= 0){
    $stmt = $connection->prepare("UPDATE `send_list` SET `total_count` = ?, `updated_at` = ? WHERE `id` = ?");
    $stmt->bind_param('iii', $targetCount, $now, $sendId);
    $stmt->execute();
    $stmt->close();
}

// اگر صف قدیمی با offset شروع شده باشد، cursor را فقط یک بار با همان offset هماهنگ می‌کنیم تا پیام تکراری ارسال نشود.
if($lastUserId <= 0 && $offset > 0){
    $legacyOffset = max(0, $offset - 1);
    $sql = "SELECT u.`id` FROM `users` u WHERE $condition ORDER BY u.`id` ASC LIMIT 1 OFFSET ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $legacyOffset);
    $stmt->execute();
    $legacyRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($legacyRow) $lastUserId = intval($legacyRow['id']);
}

if($startedAt <= 0){
    $startedAt = $now;
    $lastReportAt = $now;
    $msgTitle = ($type == 'forwardall') ? 'فوروارد همگانی' : 'ارسال پیام همگانی';
    v2raystore_broadcast_admin_message("🚀 عملیات $msgTitle شروع شد\n\n🎯 گروه مخاطب: $targetTitle\n👥 تعداد مخاطبان: $targetCount\n📦 هر اجرا: $batchSize پیام\n⏱ فاصله هر پیام: $delayMs میلی‌ثانیه");
    $stmt = $connection->prepare("UPDATE `send_list` SET `started_at` = ?, `last_report_at` = ?, `total_count` = ?, `last_user_id` = ?, `updated_at` = ? WHERE `id` = ?");
    $stmt->bind_param('iiiiii', $startedAt, $lastReportAt, $targetCount, $lastUserId, $now, $sendId);
    $stmt->execute();
    $stmt->close();
}

$sql = "SELECT u.`id`, u.`userid` FROM `users` u WHERE $condition AND u.`id` > ? ORDER BY u.`id` ASC LIMIT ?";
$stmt = $connection->prepare($sql);
$stmt->bind_param('ii', $lastUserId, $batchSize);
$stmt->execute();
$usersList = $stmt->get_result();
$stmt->close();

if($usersList->num_rows <= 0){
    $doneCount = $targetCount > 0 ? $targetCount : $offset;
    $msgTitle = ($type == 'forwardall') ? 'فوروارد همگانی' : 'ارسال پیام همگانی';
    v2raystore_broadcast_admin_message("✅ عملیات $msgTitle با موفقیت پایان یافت\n\n🎯 گروه مخاطب: $targetTitle\n👥 تعداد مخاطبان: $doneCount\n☑️ پردازش‌شده: $offset\n📨 موفق: $sentCount\n⛔️ ناموفق: $failedCount\n🚫 بلاک/غیرفعال: $blockedCount");
    $stmt = $connection->prepare("DELETE FROM `send_list` WHERE `id` = ?");
    $stmt->bind_param('i', $sendId);
    $stmt->execute();
    $stmt->close();
    exit;
}

$keys = json_encode([
    'inline_keyboard' => [
        [['text'=>$buttonValues['start_bot'], 'callback_data'=>"mainMenu"]]
    ]
], JSON_UNESCAPED_UNICODE);

$processed = 0;
$throttled = false;
$pauseUntil = 0;
$startTime = microtime(true);

while($user = $usersList->fetch_assoc()){
    if(($processed % 10) === 0){
        $stateRow = $connection->query("SELECT `state` FROM `send_list` WHERE `id` = " . intval($sendId) . " LIMIT 1");
        $stateInfo = $stateRow ? $stateRow->fetch_assoc() : null;
        if(!$stateInfo || intval($stateInfo['state'] ?? 0) != 1){
            $cancelUpdatedAt = time();
            $stmtCancel = $connection->prepare("UPDATE `send_list` SET `offset` = ?, `last_user_id` = ?, `sent_count` = ?, `failed_count` = ?, `blocked_count` = ?, `updated_at` = ? WHERE `id` = ?");
            if($stmtCancel){
                $stmtCancel->bind_param('iiiiiii', $offset, $lastUserId, $sentCount, $failedCount, $blockedCount, $cancelUpdatedAt, $sendId);
                $stmtCancel->execute();
                $stmtCancel->close();
            }
            v2raystore_broadcast_admin_message("⛔️ ارسال همگانی وسط اجرا لغو شد.
☑️ پردازش‌شده: $offset
📨 موفق: $sentCount
⛔️ ناموفق: $failedCount");
            exit;
        }
    }
    $currentDbId = intval($user['id']);
    $targetUserId = trim((string)$user['userid']);

    if($targetUserId === '' || !preg_match('/^-?\d+$/', $targetUserId)){
        $failedCount++;
        $processed++;
        $offset++;
        $lastUserId = $currentDbId;
        continue;
    }

    $result = v2raystore_broadcast_send_to_user($info, $targetUserId, $keys);

    if(is_object($result) && isset($result->ok) && $result->ok){
        $sentCount++;
        $processed++;
        $offset++;
        $lastUserId = $currentDbId;
    }else{
        $retryAfter = v2raystore_broadcast_retry_after($result);
        if($retryAfter > 0){
            $throttled = true;
            $pauseUntil = time() + min(max($retryAfter, 5), 90);
            break;
        }

        if(v2raystore_broadcast_is_blocked_error($result)) $blockedCount++;
        else $failedCount++;

        $processed++;
        $offset++;
        $lastUserId = $currentDbId;
    }

    if($delayMs > 0) usleep($delayMs * 1000);
    if((microtime(true) - $startTime) >= $maxRuntime) break;
}

$updatedAt = time();
$stmt = $connection->prepare("UPDATE `send_list` SET `offset` = ?, `last_user_id` = ?, `sent_count` = ?, `failed_count` = ?, `blocked_count` = ?, `pause_until` = ?, `updated_at` = ? WHERE `id` = ?");
$stmt->bind_param('iiiiiiii', $offset, $lastUserId, $sentCount, $failedCount, $blockedCount, $pauseUntil, $updatedAt, $sendId);
$stmt->execute();
$stmt->close();

if($throttled){
    $waitSec = max(1, $pauseUntil - time());
    v2raystore_broadcast_admin_message("⏸ تلگرام محدودیت موقت ارسال داد.\n\nصف متوقف نشد و حدود $waitSec ثانیه دیگر ادامه می‌دهد.\n☑️ پردازش‌شده: $offset\n📨 موفق: $sentCount\n⛔️ ناموفق: $failedCount");
    exit;
}

// اگر کمتر از batch برگشت یعنی به انتهای لیست رسیده‌ایم؛ همین اجرا صف را جمع‌بندی می‌کند.
if($processed >= $usersList->num_rows && $usersList->num_rows < $batchSize){
    $doneCount = $targetCount > 0 ? $targetCount : $offset;
    $msgTitle = ($type == 'forwardall') ? 'فوروارد همگانی' : 'ارسال پیام همگانی';
    v2raystore_broadcast_admin_message("✅ عملیات $msgTitle با موفقیت پایان یافت\n\n🎯 گروه مخاطب: $targetTitle\n👥 تعداد مخاطبان: $doneCount\n☑️ پردازش‌شده: $offset\n📨 موفق: $sentCount\n⛔️ ناموفق: $failedCount\n🚫 بلاک/غیرفعال: $blockedCount");
    $stmt = $connection->prepare("DELETE FROM `send_list` WHERE `id` = ?");
    $stmt->bind_param('i', $sendId);
    $stmt->execute();
    $stmt->close();
    exit;
}

if(($updatedAt - $lastReportAt) >= $progressInterval){
    $left = max(0, ($targetCount > 0 ? $targetCount : $offset) - $offset);
    $msgTitle = ($type == 'forwardall') ? 'فوروارد همگانی' : 'ارسال پیام همگانی';
    v2raystore_broadcast_admin_message("📊 گزارش پیشرفت $msgTitle\n\n🎯 گروه مخاطب: $targetTitle\n☑️ پردازش‌شده: $offset\n📨 موفق: $sentCount\n⛔️ ناموفق: $failedCount\n🚫 بلاک/غیرفعال: $blockedCount\n📣 باقی‌مانده تقریبی: $left");
    $stmt = $connection->prepare("UPDATE `send_list` SET `last_report_at` = ? WHERE `id` = ?");
    $stmt->bind_param('ii', $updatedAt, $sendId);
    $stmt->execute();
    $stmt->close();
}
?>
