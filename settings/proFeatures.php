<?php
// V2RayStore professional add-ons for 3x-ui / Sanaei 3.2.x
// همه قابلیت‌ها به شکل افزونه‌ای اضافه شده‌اند تا هسته قبلی ربات دست‌نخورده و کم‌ریسک بماند.
if(defined('V2RAYSTORE_PRO_FEATURES_LOADED')) return;
define('V2RAYSTORE_PRO_FEATURES_LOADED', true);

if(!function_exists('v2raystore_pro_h')){
function v2raystore_pro_h($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if(!function_exists('v2raystore_pro_setting')){
function v2raystore_pro_setting($key, $default = ''){
    global $connection;
    if(function_exists('farid_getSettingValue')){
        $v = farid_getSettingValue($key, null);
        return ($v === null || $v === '') ? $default : $v;
    }
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
    if(!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? (string)$res['value'] : $default;
}}

if(!function_exists('v2raystore_pro_set_setting')){
function v2raystore_pro_set_setting($key, $value){
    global $connection;
    if(function_exists('farid_setSettingValue')) return farid_setSettingValue($key, $value);
    $stmt = @$connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if($exists) $stmt = $connection->prepare("UPDATE `setting` SET `value`=? WHERE `type`=?");
    else $stmt = $connection->prepare("INSERT INTO `setting` (`value`,`type`) VALUES (?,?)");
    if(!$stmt) return false;
    $stmt->bind_param('ss', $value, $key);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}}

if(!function_exists('v2raystore_pro_add_column_if_missing')){
function v2raystore_pro_add_column_if_missing($table, $column, $definition){
    global $connection;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if($table === '' || $column === '') return false;
    $res = @$connection->query("SHOW COLUMNS FROM `$table` LIKE '" . $connection->real_escape_string($column) . "'");
    if($res && $res->num_rows > 0) return true;
    return @$connection->query("ALTER TABLE `$table` ADD `$column` $definition") ? true : false;
}}

if(!function_exists('v2raystore_pro_ensure_schema')){
function v2raystore_pro_ensure_schema(){
    global $connection;
    static $done = false;
    if($done) return;
    $done = true;
    v2raystore_pro_add_column_if_missing('users', 'last_join_state', "varchar(20) DEFAULT NULL");
    v2raystore_pro_add_column_if_missing('users', 'last_channel_leave_notice', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'wallet_used', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'pay_amount_original', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'cart_random_amount', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('send_list', 'pin_after_send', "tinyint(1) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('send_list', 'pin_title', "varchar(255) DEFAULT NULL");
    v2raystore_pro_add_column_if_missing('server_config', 'cookie', "text DEFAULT NULL");
    v2raystore_pro_add_column_if_missing('server_config', 'cookie_expire', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('orders_list', 'last_online_at', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('orders_list', 'last_online_state', "varchar(20) DEFAULT NULL");
    v2raystore_pro_add_column_if_missing('orders_list', 'last_online_checked_at', "int(11) NOT NULL DEFAULT 0");
    @$connection->query("CREATE TABLE IF NOT EXISTS `broadcast_pins` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `send_id` int(11) NOT NULL DEFAULT 0,
        `user_id` bigint(20) NOT NULL DEFAULT 0,
        `chat_id` varchar(80) NOT NULL,
        `message_id` int(11) NOT NULL DEFAULT 0,
        `title` varchar(255) DEFAULT NULL,
        `created_at` int(11) NOT NULL DEFAULT 0,
        `unpinned` tinyint(1) NOT NULL DEFAULT 0,
        `unpinned_at` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `send_id` (`send_id`),
        KEY `unpinned` (`unpinned`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}}
v2raystore_pro_ensure_schema();

if(!function_exists('v2raystore_pro_extract_value_deep')){
function v2raystore_pro_extract_value_deep($data, $keys){
    if(is_object($data)) $data = json_decode(json_encode($data), true);
    if(!is_array($data)) return null;
    foreach($keys as $k){
        if(array_key_exists($k, $data) && $data[$k] !== '' && $data[$k] !== null) return $data[$k];
    }
    foreach($data as $v){
        if(is_array($v) || is_object($v)){
            $r = v2raystore_pro_extract_value_deep($v, $keys);
            if($r !== null && $r !== '') return $r;
        }
    }
    return null;
}}

if(!function_exists('v2raystore_pro_client_email_from_order')){
function v2raystore_pro_client_email_from_order($order){
    $serverId = intval($order['server_id'] ?? 0);
    $uuid = trim((string)($order['uuid'] ?? ''));
    $remark = trim((string)($order['remark'] ?? ''));
    $inboundId = intval($order['inbound_id'] ?? 0);
    if(function_exists('v2raystore_sanaeiNewFindClientEmail')){
        $email = v2raystore_sanaeiNewFindClientEmail($serverId, $uuid, $inboundId, $remark);
        if(trim((string)$email) !== '') return trim((string)$email);
    }
    if($remark !== '') return $remark;
    if($serverId <= 0 || $uuid === '' || !function_exists('getJson')) return '';
    $json = @getJson($serverId);
    if(!$json || !isset($json->obj)) return '';
    $rows = is_array($json->obj) ? $json->obj : [$json->obj];
    foreach($rows as $row){
        if($inboundId > 0 && intval($row->id ?? 0) != $inboundId) continue;
        $settings = json_decode((string)($row->settings ?? '{}'));
        if(!isset($settings->clients) || !is_array($settings->clients)) continue;
        foreach($settings->clients as $client){
            $cid = (string)($client->id ?? '');
            $pwd = (string)($client->password ?? '');
            if($cid === $uuid || $pwd === $uuid) return trim((string)($client->email ?? ''));
        }
    }
    return '';
}}

if(!function_exists('v2raystore_pro_format_last_online')){
function v2raystore_pro_format_last_online($value){
    if($value === null || $value === '' || $value === false) return 'نامشخص / ثبت نشده';
    if(is_array($value) || is_object($value)) $value = v2raystore_pro_extract_value_deep($value, ['lastOnline','last_online','lastOnlineTime','lastSeen','last_seen','time','online_at','value','last']);
    if($value === null || $value === '' || $value === false) return 'نامشخص / ثبت نشده';
    if(is_numeric($value)){
        $ts = intval($value);
        if($ts > 9999999999) $ts = intval($ts / 1000);
        if($ts <= 0) return 'هنوز اتصالی ثبت نشده';
        return function_exists('jdate') ? jdate('Y-m-d H:i', $ts) : date('Y-m-d H:i', $ts);
    }
    $text = trim((string)$value);
    if($text === '' || strtolower($text) === 'null') return 'نامشخص / ثبت نشده';
    if(preg_match('/^(online|true|on|connected|active)$/i', $text)) return 'آنلاین';
    return $text;
}}

if(!function_exists('v2raystore_pro_status_from_value')){
function v2raystore_pro_status_from_value($value){
    if(is_object($value)) $value = json_decode(json_encode($value), true);
    if(is_array($value)){
        $online = v2raystore_pro_extract_value_deep($value, ['online','isOnline','is_online','connected','active']);
        if($online === true || $online === 1 || $online === '1' || preg_match('/^(true|online|connected|active)$/i', (string)$online)){
            return ['state'=>'online', 'at'=>time(), 'text'=>'آنلاین'];
        }
        $value = v2raystore_pro_extract_value_deep($value, ['lastOnline','last_online','lastOnlineTime','lastSeen','last_seen','time','online_at','value','last']);
    }
    if($value === true) return ['state'=>'online', 'at'=>time(), 'text'=>'آنلاین'];
    if($value === null || $value === '' || $value === false) return ['state'=>'unknown','at'=>0,'text'=>'نامشخص / ثبت نشده'];
    if(is_numeric($value)){
        $ts = intval($value);
        if($ts > 9999999999) $ts = intval($ts / 1000);
        if($ts > 0 && $ts >= time() - 120) return ['state'=>'online', 'at'=>$ts, 'text'=>'آنلاین'];
        return ['state'=>'offline', 'at'=>max(0,$ts), 'text'=>v2raystore_pro_format_last_online($ts)];
    }
    $text = trim((string)$value);
    if(preg_match('/^(online|true|on|connected|active)$/i', $text)) return ['state'=>'online', 'at'=>time(), 'text'=>'آنلاین'];
    if($text === '' || strtolower($text) === 'null') return ['state'=>'unknown','at'=>0,'text'=>'نامشخص / ثبت نشده'];
    $ts = strtotime($text);
    if($ts !== false && $ts > 0){
        if($ts >= time() - 120) return ['state'=>'online', 'at'=>$ts, 'text'=>'آنلاین'];
        return ['state'=>'offline', 'at'=>$ts, 'text'=>v2raystore_pro_format_last_online($ts)];
    }
    return ['state'=>'offline','at'=>0,'text'=>$text];
}}

if(!function_exists('v2raystore_pro_parse_last_online_response')){
function v2raystore_pro_parse_last_online_response($decoded, $email){
    $email = trim((string)$email);
    if(!is_array($decoded)) return ['state'=>'unknown','at'=>0,'text'=>'نامشخص / ثبت نشده'];
    $obj = $decoded['obj'] ?? $decoded['data'] ?? $decoded['result'] ?? $decoded;
    if(is_object($obj)) $obj = json_decode(json_encode($obj), true);
    if(is_array($obj)){
        // 3x-ui/forks sometimes return a plain list of online client emails.
        foreach($obj as $k=>$v){
            if(is_string($v) && strcasecmp(trim($v), $email) === 0) return ['state'=>'online','at'=>time(),'text'=>'آنلاین'];
            if(is_string($k) && strcasecmp(trim($k), $email) === 0) return v2raystore_pro_status_from_value($v);
            if(is_array($v) || is_object($v)){
                $arr = is_object($v) ? json_decode(json_encode($v), true) : $v;
                $rowEmail = trim((string)v2raystore_pro_extract_value_deep($arr, ['email','name','remark','client','id']));
                if($rowEmail !== '' && strcasecmp($rowEmail, $email) === 0) return v2raystore_pro_status_from_value($arr);
            }
        }
    }
    return v2raystore_pro_status_from_value($obj);
}}

if(!function_exists('v2raystore_pro_update_last_online_cache')){
function v2raystore_pro_update_last_online_cache($orderId, $status){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0 || !is_array($status)) return;
    $state = substr((string)($status['state'] ?? 'unknown'), 0, 20);
    $at = intval($status['at'] ?? 0);
    $checked = time();
    $stmt = @$connection->prepare("UPDATE `orders_list` SET `last_online_at`=?, `last_online_state`=?, `last_online_checked_at`=? WHERE `id`=?");
    if($stmt){ $stmt->bind_param('isii', $at, $state, $checked, $orderId); $stmt->execute(); $stmt->close(); }
}}

if(!function_exists('v2raystore_pro_cached_last_online_label_for_order')){
function v2raystore_pro_cached_last_online_label_for_order($order){
    $state = trim((string)($order['last_online_state'] ?? ''));
    $at = intval($order['last_online_at'] ?? 0);
    $checked = intval($order['last_online_checked_at'] ?? 0);
    if($state === 'online' && $checked >= time() - 180) return '🟢 آنلاین';
    if($at > 0) return '🕘 ' . (function_exists('jdate') ? jdate('m/d H:i', $at) : date('m/d H:i', $at));
    return '🕘 نامشخص';
}}

if(!function_exists('v2raystore_pro_fetch_last_online_status')){
function v2raystore_pro_fetch_last_online_status($server, $email){
    $type = (string)($server['type'] ?? '');
    if(!function_exists('v2raystore_sanaeiRequestJson') || $type !== 'sanaei_new') return ['state'=>'unknown','at'=>0,'text'=>'نامشخص / ثبت نشده'];
    // اول endpoint آنلاین‌ها را سبک امتحان می‌کنیم؛ اگر پنل پشتیبانی نکند، lastOnline استفاده می‌شود.
    $onlineEndpoints = [
        ['/panel/api/inbounds/onlines', 'POST', null],
        ['/panel/api/inbounds/onlines', 'GET', null],
    ];
    foreach($onlineEndpoints as $ep){
        $decoded = @v2raystore_sanaeiRequestJson($server, $ep[0], $ep[1], $ep[2]);
        $st = v2raystore_pro_parse_last_online_response($decoded, $email);
        if(($st['state'] ?? '') === 'online') return $st;
    }
    $payloads = [
        ['emails' => [$email]],
        ['email' => $email],
        [$email],
    ];
    foreach($payloads as $payload){
        $decoded = @v2raystore_sanaeiRequestJson($server, '/panel/api/inbounds/lastOnline', 'POST', $payload);
        if(is_array($decoded)){
            $st = v2raystore_pro_parse_last_online_response($decoded, $email);
            if(($st['state'] ?? '') !== 'unknown' || !empty($decoded['obj']) || !empty($decoded['data'])) return $st;
        }
    }
    return ['state'=>'unknown','at'=>0,'text'=>'نامشخص / ثبت نشده'];
}}

if(!function_exists('v2raystore_pro_last_online_line_for_order')){
function v2raystore_pro_last_online_line_for_order($order){
    global $connection;
    $checked = intval($order['last_online_checked_at'] ?? 0);
    if($checked >= time() - 120 && !empty($order['last_online_state'])){
        $label = v2raystore_pro_cached_last_online_label_for_order($order);
        return "\n🕘 آخرین اتصال: " . v2raystore_pro_h(str_replace(['🟢 ','🕘 '], '', $label)) . "\n";
    }
    $serverId = intval($order['server_id'] ?? 0);
    if($serverId <= 0) return '';
    $stmt = @$connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    if(!$stmt) return '';
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $server = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server) return '';
    $email = v2raystore_pro_client_email_from_order($order);
    if($email === '') return '';
    $status = v2raystore_pro_fetch_last_online_status($server, $email);
    v2raystore_pro_update_last_online_cache($order['id'] ?? 0, $status);
    $txt = ($status['state'] ?? '') === 'online' ? 'آنلاین' : v2raystore_pro_format_last_online($status['at'] ?? ($status['text'] ?? ''));
    return "\n🕘 آخرین اتصال: " . v2raystore_pro_h($txt) . "\n";
}}

if(!function_exists('v2raystore_pro_process_channel_leave_notice')){
function v2raystore_pro_process_channel_leave_notice($userInfo, $joinState){
    global $connection, $from_id, $admin;
    if(empty($from_id) || intval($from_id) == intval($admin ?? 0) || empty($userInfo) || !is_array($userInfo)) return;
    $uid = intval($userInfo['userid'] ?? $from_id);
    if($uid <= 0) return;
    $state = trim((string)$joinState);
    if($state === '') return;
    $prev = trim((string)($userInfo['last_join_state'] ?? ''));
    $leftNow = in_array($state, ['left','kicked'], true);
    $wasJoined = ($prev !== '' && !in_array($prev, ['left','kicked'], true));
    if($leftNow && $wasJoined && v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on') === 'on'){
        $lastNotice = intval($userInfo['last_channel_leave_notice'] ?? 0);
        if(time() - $lastNotice > 3600){
            $txt = trim(v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_TEXT', '⚠️ شما از کانال ربات خارج شدید. برای ادامه استفاده از ربات، لطفاً دوباره عضو کانال شوید.'));
            $sentOk = false;
            if($txt !== ''){
                $resp = @sendMessage($txt, null, 'HTML', $uid);
                $sentOk = !(is_object($resp) && isset($resp->ok) && !$resp->ok);
            }
            if($sentOk) @$connection->query("UPDATE `users` SET `last_channel_leave_notice`=" . intval(time()) . " WHERE `userid`='" . $connection->real_escape_string((string)$uid) . "'");
        }
    }
    if($prev !== $state){
        $stmt = @$connection->prepare("UPDATE `users` SET `last_join_state`=? WHERE `userid`=?");
        if($stmt){ $stmt->bind_param('si', $state, $uid); $stmt->execute(); $stmt->close(); }
    }
}}

if(!function_exists('v2raystore_pro_prepare_cart_to_cart_pay')){
function v2raystore_pro_prepare_cart_to_cart_pay($hashId){
    global $connection, $userInfo;
    $hashId = trim((string)$hashId);
    if($hashId === '' || empty($userInfo['userid'])) return '';
    $uid = intval($userInfo['userid']);
    $stmt = @$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? AND `user_id`=? AND `state`='pending' LIMIT 1");
    if(!$stmt) return '';
    $stmt->bind_param('si', $hashId, $uid);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return '';
    if((string)($pay['type'] ?? '') === 'INCREASE_WALLET') return '';
    $lines = [];
    $currentPrice = intval($pay['price'] ?? 0);
    $wallet = intval($userInfo['wallet'] ?? 0);
    if($currentPrice > 0 && $wallet > 0 && $wallet < $currentPrice && intval($pay['wallet_used'] ?? 0) <= 0){
        $original = intval($pay['pay_amount_original'] ?? 0);
        if($original <= 0) $original = $currentPrice;
        $newPrice = $currentPrice - $wallet;
        $stmt = @$connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid`=? AND `wallet` >= ?");
        if($stmt){
            $stmt->bind_param('iii', $wallet, $uid, $wallet);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if($affected > 0){
                $stmt = $connection->prepare("UPDATE `pays` SET `price`=?, `wallet_used`=`wallet_used`+?, `pay_amount_original`=? WHERE `hash_id`=? AND `user_id`=?");
                if($stmt){ $stmt->bind_param('iiisi', $newPrice, $wallet, $original, $hashId, $uid); $stmt->execute(); $stmt->close(); }
                $currentPrice = $newPrice;
                $lines[] = "✅ از کیف پول شما <b>" . number_format($wallet) . "</b> تومان کسر شد؛ باقی‌مانده برای کارت‌به‌کارت: <b>" . number_format($newPrice) . "</b> تومان";
            }
        }
    }
    if(v2raystore_pro_setting('CART_TO_CART_RANDOM_PRICE_STATE', 'off') === 'on'){
        $stmt = @$connection->prepare("SELECT `price`, `pay_amount_original` FROM `pays` WHERE `hash_id`=? AND `user_id`=? LIMIT 1");
        if($stmt){
            $stmt->bind_param('si', $hashId, $uid);
            $stmt->execute();
            $pay2 = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price2 = intval($pay2['price'] ?? 0);
            $orig2 = intval($pay2['pay_amount_original'] ?? 0);
            if($price2 > 0 && $orig2 <= 0){
                $rand = mt_rand(1, 999);
                $new = $price2 + $rand;
                $stmt = @$connection->prepare("UPDATE `pays` SET `price`=?, `pay_amount_original`=? WHERE `hash_id`=? AND `user_id`=?");
                if($stmt){ $stmt->bind_param('iisi', $new, $price2, $hashId, $uid); $stmt->execute(); $stmt->close(); }
                $lines[] = "🔢 مبلغ فاکتور برای شناسایی دقیق رسید به <b>" . number_format($new) . "</b> تومان تغییر کرد.";
            }
        }
    }
    return count($lines) ? "\n\n" . implode("\n", $lines) : '';
}}

if(!function_exists('v2raystore_pro_keyboard')){
function v2raystore_pro_keyboard($rows){
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}}

if(!function_exists('v2raystore_pro_menu_keyboard')){
function v2raystore_pro_menu_keyboard(){
    return v2raystore_pro_keyboard([
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel']],
    ]);
}}

if(!function_exists('v2raystore_pro_pin_target_chat')){
function v2raystore_pro_pin_target_chat(){
    global $botState, $from_id;
    $target = trim(v2raystore_pro_setting('PRO_PIN_TARGET_CHAT', ''));
    if($target !== '') return $target;
    if(!empty($botState['lockChannel'])) return $botState['lockChannel'];
    if(!empty($botState['rewardChannel'])) return $botState['rewardChannel'];
    return $from_id;
}}

if(!function_exists('v2raystore_pro_send_and_pin')){
function v2raystore_pro_send_and_pin($type, $content, $caption = ''){
    $chat = v2raystore_pro_pin_target_chat();
    $res = null;
    if($type === 'text'){
        $res = bot('sendMessage', ['chat_id'=>$chat, 'text'=>$content, 'parse_mode'=>'HTML', '_timeout'=>12]);
    }elseif($type === 'photo'){
        $res = bot('sendPhoto', ['chat_id'=>$chat, 'photo'=>$content, 'caption'=>$caption, 'parse_mode'=>'HTML', '_timeout'=>20]);
    }else{
        $res = bot('sendDocument', ['chat_id'=>$chat, 'document'=>$content, 'caption'=>$caption, 'parse_mode'=>'HTML', '_timeout'=>20]);
    }
    $mid = 0;
    if(is_object($res) && !empty($res->ok) && isset($res->result->message_id)) $mid = intval($res->result->message_id);
    if($mid <= 0) return ['ok'=>false, 'message'=>'ارسال پیام به مقصد پین ناموفق بود. دسترسی ربات/آیدی چت را بررسی کن.'];
    $pin = bot('pinChatMessage', ['chat_id'=>$chat, 'message_id'=>$mid, 'disable_notification'=>true, '_timeout'=>10]);
    if(is_object($pin) && !empty($pin->ok)) return ['ok'=>true, 'message'=>'✅ پیام ارسال و پین شد.', 'message_id'=>$mid, 'chat_id'=>$chat];
    return ['ok'=>false, 'message'=>'پیام ارسال شد ولی پین نشد. ربات باید در مقصد دسترسی pin داشته باشد.', 'message_id'=>$mid, 'chat_id'=>$chat];
}}

if(!function_exists('v2raystore_pro_referrals_text')){
function v2raystore_pro_referrals_text($uid){
    global $connection;
    $uid = intval($uid);
    if($uid <= 0) return 'آیدی عددی نامعتبر است.';
    $stmt = @$connection->prepare("SELECT `userid`, `name`, `username`, `date` FROM `users` WHERE `refered_by`=? ORDER BY `id` DESC LIMIT 200");
    if(!$stmt) return 'امکان خواندن زیرمجموعه‌ها وجود ندارد.';
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    if(!count($rows)) return "👥 کاربر <code>$uid</code> زیرمجموعه‌ای ندارد.";
    $lines = ["👥 زیرمجموعه‌های کاربر <code>$uid</code>\nتعداد: <b>" . count($rows) . "</b>\n"];
    $i = 1;
    foreach($rows as $r){
        $ru = v2raystore_pro_h($r['userid'] ?? '');
        $name = trim((string)($r['name'] ?? '')) ?: '-';
        $username = trim((string)($r['username'] ?? ''));
        $ut = $username !== '' ? '@' . $username : v2raystore_pro_h($name);
        $lines[] = $i . ") <code>$ru</code> | $ut";
        $i++;
    }
    if(count($rows) >= 200) $lines[] = "\nفقط ۲۰۰ مورد آخر نمایش داده شد.";
    return implode("\n", $lines);
}}

if(!function_exists('v2raystore_pro_pinned_broadcasts_text')){
function v2raystore_pro_pinned_broadcasts_text(){
    global $connection;
    $res = @$connection->query("SELECT `send_id`, COALESCE(NULLIF(`title`,''), CONCAT('Broadcast #',`send_id`)) AS title, COUNT(*) AS total, SUM(CASE WHEN `unpinned`=0 THEN 1 ELSE 0 END) AS active, MAX(`created_at`) AS last_at FROM `broadcast_pins` GROUP BY `send_id`, title ORDER BY last_at DESC LIMIT 20");
    $rows = [];
    if($res){ while($r = $res->fetch_assoc()) $rows[] = $r; }
    if(!count($rows)) return ['text'=>'📌 هنوز هیچ پیام پین‌شده‌ای از ارسال همگانی ثبت نشده است.', 'keyboard'=>v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']]])];
    $lines = ["📌 <b>پیام‌های پین‌شده همگانی</b>
"];
    $keys = [];
    foreach($rows as $r){
        $sid = intval($r['send_id'] ?? 0);
        $active = intval($r['active'] ?? 0);
        $total = intval($r['total'] ?? 0);
        $titleRaw = v2raystore_pro_h($r['title'] ?? ('Broadcast #' . $sid));
        $title = function_exists('mb_substr') ? mb_substr($titleRaw, 0, 70, 'UTF-8') : substr($titleRaw, 0, 70);
        $last = intval($r['last_at'] ?? 0);
        $lastTxt = $last > 0 ? (function_exists('jdate') ? jdate('Y-m-d H:i', $last) : date('Y-m-d H:i', $last)) : '-';
        $lines[] = "#<code>$sid</code> | فعال: <b>$active</b> از <b>$total</b> | آخرین: $lastTxt
$title";
        if($sid > 0 && $active > 0) $keys[] = [['text'=>'📍 آن‌پین صف #' . $sid . ' (' . $active . ')', 'callback_data'=>'unpinBroadcastPins' . $sid]];
    }
    $keys[] = [['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']];
    return ['text'=>implode("

", $lines), 'keyboard'=>v2raystore_pro_keyboard($keys)];
}}

if(!function_exists('v2raystore_pro_unpin_broadcast')){
function v2raystore_pro_unpin_broadcast($sendId, $limit = 80){
    global $connection;
    $sendId = intval($sendId);
    $limit = max(1, min(100, intval($limit)));
    $res = @$connection->query("SELECT `id`,`chat_id`,`message_id` FROM `broadcast_pins` WHERE `send_id`=$sendId AND `unpinned`=0 ORDER BY `id` ASC LIMIT $limit");
    $done = 0; $ok = 0; $fail = 0;
    if($res){
        while($r = $res->fetch_assoc()){
            $done++;
            $id = intval($r['id']);
            $api = bot('unpinChatMessage', ['chat_id'=>(string)$r['chat_id'], 'message_id'=>intval($r['message_id']), '_timeout'=>8]);
            if(is_object($api) && !empty($api->ok)) $ok++; else $fail++;
            @$connection->query("UPDATE `broadcast_pins` SET `unpinned`=1, `unpinned_at`=" . time() . " WHERE `id`=$id LIMIT 1");
        }
    }
    $remaining = 0;
    $r2 = @$connection->query("SELECT COUNT(*) AS c FROM `broadcast_pins` WHERE `send_id`=$sendId AND `unpinned`=0");
    if($r2 && ($row=$r2->fetch_assoc())) $remaining = intval($row['c'] ?? 0);
    $text = "📍 نتیجه آن‌پین صف #<code>$sendId</code>

بررسی‌شده: <b>$done</b>
موفق: <b>$ok</b>
ناموفق/ردشده: <b>$fail</b>
باقی‌مانده: <b>$remaining</b>";
    $keys = [];
    if($remaining > 0) $keys[] = [['text'=>'ادامه آن‌پین همین صف', 'callback_data'=>'unpinBroadcastPins' . $sendId]];
    $keys[] = [['text'=>'📌 لیست پیام‌های پین‌شده', 'callback_data'=>'broadcastPinsMenu']];
    $keys[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel']];
    return ['text'=>$text, 'keyboard'=>v2raystore_pro_keyboard($keys)];
}}

if(!function_exists('v2raystore_pro_handle_bot_update')){
function v2raystore_pro_handle_bot_update(){
    global $data, $text, $from_id, $admin, $message_id, $userInfo, $filetype, $fileid, $caption, $buttonValues, $mainValues, $update;
    if(empty($from_id)) return;
    $isAdmin = (intval($from_id) == intval($admin ?? 0)) || (!empty($userInfo['isAdmin']));
    if(!$isAdmin) return;
    $step = (string)($userInfo['step'] ?? '');

    if(($data ?? '') === 'broadcastPinsMenu'){
        $r = v2raystore_pro_pinned_broadcasts_text();
        editText($message_id, $r['text'], $r['keyboard'], 'HTML');
        exit();
    }
    if(preg_match('/^unpinBroadcastPins(\d+)$/', $data ?? '', $m)){
        $r = v2raystore_pro_unpin_broadcast($m[1]);
        editText($message_id, $r['text'], $r['keyboard'], 'HTML');
        exit();
    }

    if(($data ?? '') === 'proToolsMenu'){
        editText($message_id, "✅ این منوی جدا حذف شد و هر قابلیت به بخش مربوط خودش منتقل شده است.", function_exists('getAdminKeysPlus') ? getAdminKeysPlus() : v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proReferralAsk'){
        setUser('proReferralLookup');
        editText($message_id, "👥 آیدی عددی کاربر را ارسال کن تا زیرمجموعه‌های او با تعداد و ID عددی نمایش داده شود.", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']]]), 'HTML');
        exit();
    }
    if($step === 'proReferralLookup' && isset($text) && trim((string)$text) !== ''){
        setUser();
        sendMessage(v2raystore_pro_referrals_text($text), v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proLeaveNoticeMenu'){
        $state = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on');
        $txt = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_TEXT', '⚠️ شما از کانال ربات خارج شدید. برای ادامه استفاده از ربات، لطفاً دوباره عضو کانال شوید.');
        editText($message_id, "🚪 <b>پیام ترک کانال</b>\nوضعیت: <b>$state</b>\n\nمتن فعلی:\n<code>" . v2raystore_pro_h($txt) . "</code>", v2raystore_pro_keyboard([
            [['text'=>($state==='on'?'خاموش کردن':'روشن کردن'), 'callback_data'=>'proToggleLeaveNotice']],
            [['text'=>'✏️ تنظیم متن پیام', 'callback_data'=>'proSetLeaveNoticeText']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']],
        ]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proToggleLeaveNotice'){
        $state = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on') === 'on' ? 'off' : 'on';
        v2raystore_pro_set_setting('CHANNEL_LEAVE_NOTICE_STATE', $state);
        alert('وضعیت: ' . $state);
        $data = 'proLeaveNoticeMenu';
        v2raystore_pro_handle_bot_update();
    }
    if(($data ?? '') === 'proSetLeaveNoticeText'){
        setUser('proSetLeaveNoticeText');
        editText($message_id, "متن جدید پیام ترک کانال را ارسال کن. HTML ساده مجاز است.", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proLeaveNoticeMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proSetLeaveNoticeText' && isset($text) && trim((string)$text) !== ''){
        v2raystore_pro_set_setting('CHANNEL_LEAVE_NOTICE_TEXT', (string)$text);
        setUser();
        sendMessage('✅ متن پیام ترک کانال ذخیره شد.', v2raystore_pro_keyboard([[['text'=>'⬅️ تنظیمات','callback_data'=>'proLeaveNoticeMenu']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proC2CMenu'){
        $rand = v2raystore_pro_setting('CART_TO_CART_RANDOM_PRICE_STATE', 'off');
        $c2c = $GLOBALS['botState']['cartToCartState'] ?? 'on';
        editText($message_id, "💳 <b>تنظیمات کارت‌به‌کارت حرفه‌ای</b>\n\nکارت‌به‌کارت فعلی: <b>$c2c</b>\nقیمت رندم فاکتور: <b>$rand</b>\n\nکسر همزمان کیف پول هنگام کارت‌به‌کارت به‌صورت خودکار فعال است: اگر موجودی کمتر از مبلغ فاکتور باشد، همان مقدار از کیف پول کم و فقط باقی‌مانده کارت‌به‌کارت می‌شود.", v2raystore_pro_keyboard([
            [['text'=>($rand==='on'?'خاموش کردن قیمت رندم':'روشن کردن قیمت رندم'), 'callback_data'=>'proToggleC2CRandom']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']],
        ]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proToggleC2CRandom'){
        $state = v2raystore_pro_setting('CART_TO_CART_RANDOM_PRICE_STATE', 'off') === 'on' ? 'off' : 'on';
        v2raystore_pro_set_setting('CART_TO_CART_RANDOM_PRICE_STATE', $state);
        alert('قیمت رندم: ' . $state);
        $data = 'proC2CMenu';
        v2raystore_pro_handle_bot_update();
    }
    if(($data ?? '') === 'proPinMenu'){
        $target = v2raystore_pro_pin_target_chat();
        editText($message_id, "📌 <b>پین/آن‌پین پیام</b>\nمقصد فعلی: <code>" . v2raystore_pro_h($target) . "</code>\n\nمی‌توانی متن، تصویر یا فایل را به مقصد بفرستی و پین کنی. برای کانال، ربات باید ادمین و دارای دسترسی pin باشد.", v2raystore_pro_keyboard([
            [['text'=>'✏️ تنظیم مقصد پین', 'callback_data'=>'proSetPinChat']],
            [['text'=>'📌 پین متن', 'callback_data'=>'proPinText'], ['text'=>'🖼/📎 پین تصویر/فایل', 'callback_data'=>'proPinMedia']],
            [['text'=>'📍 آن‌پین آخرین پیام پین‌شده', 'callback_data'=>'proUnpinLast']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']],
        ]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proSetPinChat'){
        setUser('proSetPinChat');
        editText($message_id, "آیدی مقصد پین را ارسال کن؛ مثال: <code>@channel</code> یا <code>-100...</code>", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proPinMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proSetPinChat' && isset($text) && trim((string)$text) !== ''){
        v2raystore_pro_set_setting('PRO_PIN_TARGET_CHAT', trim((string)$text));
        setUser();
        sendMessage('✅ مقصد پین ذخیره شد.', v2raystore_pro_keyboard([[['text'=>'⬅️ پین پیام','callback_data'=>'proPinMenu']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proPinText'){
        setUser('proPinText');
        editText($message_id, "متنی که باید ارسال و پین شود را بفرست.", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proPinMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proPinText' && isset($text) && trim((string)$text) !== ''){
        $r = v2raystore_pro_send_and_pin('text', (string)$text);
        setUser();
        sendMessage($r['message'], v2raystore_pro_keyboard([[['text'=>'⬅️ پین پیام','callback_data'=>'proPinMenu']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proPinMedia'){
        setUser('proPinMedia');
        editText($message_id, "یک تصویر یا فایل ارسال کن تا به مقصد فرستاده و پین شود. کپشن هم پشتیبانی می‌شود.", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proPinMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proPinMedia'){
        if(!empty($fileid)){
            $type = ($filetype ?? '') === 'photo' ? 'photo' : 'document';
            $r = v2raystore_pro_send_and_pin($type, $fileid, (string)($caption ?? ''));
            setUser();
            sendMessage($r['message'], v2raystore_pro_keyboard([[['text'=>'⬅️ پین پیام','callback_data'=>'proPinMenu']]]), 'HTML');
            exit();
        }elseif(isset($text) && trim((string)$text) !== ''){
            sendMessage('لطفاً فقط تصویر یا فایل ارسال کن.', v2raystore_pro_keyboard([[['text'=>'⬅️ پین پیام','callback_data'=>'proPinMenu']]]), 'HTML');
            exit();
        }
    }
    if(($data ?? '') === 'proUnpinLast'){
        $target = v2raystore_pro_pin_target_chat();
        $res = bot('unpinChatMessage', ['chat_id'=>$target, '_timeout'=>10]);
        $ok = is_object($res) && !empty($res->ok);
        alert($ok ? 'آن‌پین شد.' : 'آن‌پین ناموفق بود.', !$ok);
        exit();
    }
    if(($data ?? '') === 'proTargetBroadcastMenu'){
        editText($message_id, "📣 نوع ارسال هدفمند را انتخاب کن. گروه‌های جدید شامل بدون کانفیگ، خرید نداشته ۳۰ روزه، خارج‌شده از کانال و کانفیگ غیرفعال به منوی ارسال همگانی اضافه شده‌اند.", v2raystore_pro_keyboard([
            [['text'=>'✉️ پیام متنی هدفمند','callback_data'=>'message2All'], ['text'=>'↪️ فوروارد هدفمند','callback_data'=>'forwardToAll']],
            [['text'=>'📌 پین هدفمند برای گروه‌ها','callback_data'=>'proPinBroadcastMenu']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'managePanel']],
        ]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proPinBroadcastMenu'){
        editText($message_id, "📌 پین هدفمند برای گروه کاربران

گروه مخاطب را انتخاب کن. پیام ارسال‌شده برای هر کاربر بعد از ارسال، در همان چت پین می‌شود؛ اگر کاربر ربات را بلاک کرده باشد یا تلگرام اجازه پین ندهد، ارسال/پین آن کاربر رد می‌شود و صف ادامه پیدا می‌کند.", farid_getBroadcastTargetKeyboard('pin'), 'HTML');
        exit();
    }
    if(preg_match('/^broadcastTargetPin_(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config)$/', $data ?? '', $pinTargetMatch)){
        $target = farid_normalizeBroadcastTarget($pinTargetMatch[1]);
        $title = farid_getBroadcastTargetTitle($target);
        setUser('pinToAll|' . $target);
        editText($message_id, "📌 پیام متنی، تصویر یا فایل را ارسال کن تا برای گروه انتخاب‌شده ارسال و پین شود.

🎯 گروه مخاطب: <b>$title</b>

برای جلوگیری از هنگ، شمارش مخاطبان داخل صف انجام می‌شود.", null, 'HTML');
        exit();
    }
    if(preg_match('/^pinToAll\|(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config)$/', $step, $pinStepMatch) && isset($text) && ($text !== ($buttonValues['cancel'] ?? 'لغو'))){
        $target = farid_normalizeBroadcastTarget($pinStepMatch[1]);
        $targetTitle = farid_getBroadcastTargetTitle($target);
        if(!empty($fileid)){
            $baseType = ($filetype ?? '') ?: 'document';
            $stmt = $GLOBALS['connection']->prepare("INSERT INTO `send_list` (`type`, `text`, `file_id`, `target_type`, `pin_after_send`) VALUES (?, ?, ?, ?, 1)");
            $cap = (string)($caption ?? '');
            $stmt->bind_param('ssss', $baseType, $cap, $fileid, $target);
        }else{
            $stmt = $GLOBALS['connection']->prepare("INSERT INTO `send_list` (`type`, `text`, `target_type`, `pin_after_send`) VALUES ('text', ?, ?, 1)");
            $msgText = (string)$text;
            $stmt->bind_param('ss', $msgText, $target);
        }
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        setUser();
        sendMessage('⏳ پیام پین هدفمند دریافت شد و آماده بررسی است.', $GLOBALS['removeKeyboard'] ?? null, 'HTML');
        sendMessage("📌 پیش‌نمایش پین هدفمند

🎯 گروه مخاطب: <b>$targetTitle</b>

شمارش مخاطبان هنگام اجرای صف انجام می‌شود.

آیا ارسال و پین برای این گروه آغاز شود؟", json_encode(['inline_keyboard'=>[
            [['text'=>'✅ بله، شروع شود', 'callback_data'=>'yesSend2AllPin' . $id, 'style'=>'success'], ['text'=>'❌ لغو', 'callback_data'=>'noDontSend2all' . $id, 'style'=>'danger']]
        ]], JSON_UNESCAPED_UNICODE), 'HTML');
        exit();
    }
}}
?>
