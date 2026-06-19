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
    static $done = false;
    if($done) return;
    $done = true;
    v2raystore_pro_add_column_if_missing('users', 'last_join_state', "varchar(20) DEFAULT NULL");
    v2raystore_pro_add_column_if_missing('users', 'last_channel_leave_notice', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'wallet_used', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'pay_amount_original', "int(11) NOT NULL DEFAULT 0");
    v2raystore_pro_add_column_if_missing('pays', 'cart_random_amount', "int(11) NOT NULL DEFAULT 0");
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
    if(is_array($value) || is_object($value)) $value = v2raystore_pro_extract_value_deep($value, ['lastOnline','last_online','lastOnlineTime','lastSeen','last_seen','time','online_at','value']);
    if($value === null || $value === '' || $value === false) return 'نامشخص / ثبت نشده';
    if(is_numeric($value)){
        $ts = intval($value);
        if($ts > 9999999999) $ts = intval($ts / 1000);
        if($ts <= 0) return 'هنوز اتصالی ثبت نشده';
        return function_exists('jdate') ? jdate('Y-m-d H:i', $ts) : date('Y-m-d H:i', $ts);
    }
    $text = trim((string)$value);
    if($text === '' || strtolower($text) === 'null') return 'نامشخص / ثبت نشده';
    return $text;
}}

if(!function_exists('v2raystore_pro_last_online_line_for_order')){
function v2raystore_pro_last_online_line_for_order($order){
    global $connection;
    $serverId = intval($order['server_id'] ?? 0);
    if($serverId <= 0) return '';
    $stmt = @$connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    if(!$stmt) return '';
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $server = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server) return '';
    $type = (string)($server['type'] ?? '');
    if($type === 'marzban') return '';
    $email = v2raystore_pro_client_email_from_order($order);
    if($email === '') return '';

    $decoded = null;
    if(function_exists('v2raystore_sanaeiRequestJson') && $type === 'sanaei_new'){
        $payloads = [
            ['emails' => [$email]],
            ['email' => $email],
            [$email],
        ];
        foreach($payloads as $payload){
            $decoded = @v2raystore_sanaeiRequestJson($server, '/panel/api/inbounds/lastOnline', 'POST', $payload);
            if(is_array($decoded) && (!isset($decoded['success']) || $decoded['success'] || !empty($decoded['obj']))) break;
        }
    }
    if(!is_array($decoded)) return '';
    $obj = $decoded['obj'] ?? $decoded['data'] ?? $decoded['result'] ?? $decoded;
    $value = null;
    if(is_array($obj)){
        if(array_key_exists($email, $obj)) $value = $obj[$email];
        elseif(isset($obj[0]) && (is_array($obj[0]) || is_object($obj[0]))) $value = v2raystore_pro_extract_value_deep($obj, ['lastOnline','last_online','lastOnlineTime','lastSeen','last_seen','time','online_at','value']);
        else $value = v2raystore_pro_extract_value_deep($obj, ['lastOnline','last_online','lastOnlineTime','lastSeen','last_seen','time','online_at','value']);
    }else $value = $obj;
    $txt = v2raystore_pro_format_last_online($value);
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
            if($txt !== '') @sendMessage($txt, null, 'HTML', $uid);
            @$connection->query("UPDATE `users` SET `last_channel_leave_notice`=" . intval(time()) . " WHERE `userid`='" . $connection->real_escape_string((string)$uid) . "'");
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
        $stmt = @$connection->prepare("SELECT `price`, `pay_amount_original`, `cart_random_amount` FROM `pays` WHERE `hash_id`=? AND `user_id`=? LIMIT 1");
        if($stmt){
            $stmt->bind_param('si', $hashId, $uid);
            $stmt->execute();
            $pay2 = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price2 = intval($pay2['price'] ?? 0);
            $orig2 = intval($pay2['pay_amount_original'] ?? 0);
            $randomApplied = intval($pay2['cart_random_amount'] ?? 0);
            if($price2 > 0 && $randomApplied <= 0){
                $rand = mt_rand(1, 999);
                $new = $price2 + $rand;
                if($orig2 <= 0) $orig2 = $price2;
                $stmt = @$connection->prepare("UPDATE `pays` SET `price`=?, `pay_amount_original`=?, `cart_random_amount`=? WHERE `hash_id`=? AND `user_id`=?");
                if($stmt){ $stmt->bind_param('iiisi', $new, $orig2, $rand, $hashId, $uid); $stmt->execute(); $stmt->close(); }
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
        [['text'=>'📊 برترین خریداران', 'callback_data'=>'proTopBuyers'], ['text'=>'👥 زیرمجموعه‌های کاربر', 'callback_data'=>'proReferralAsk']],
        [['text'=>'📌 پین/آن‌پین پیام', 'callback_data'=>'proPinMenu'], ['text'=>'📣 پیام هدفمند', 'callback_data'=>'proTargetBroadcastMenu']],
        [['text'=>'🚪 پیام ترک کانال', 'callback_data'=>'proLeaveNoticeMenu'], ['text'=>'💳 تنظیمات کارت‌به‌کارت', 'callback_data'=>'proC2CMenu']],
        [['text'=>'🔌 پلن پورت اشتراکی سنایی', 'callback_data'=>'proSharedPortAsk']],
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

if(!function_exists('v2raystore_pro_top_buyers_text')){
function v2raystore_pro_top_buyers_text(){
    global $connection;
    $sql = "SELECT p.`user_id`, COUNT(*) AS cnt, SUM(COALESCE(p.`price`,0)+COALESCE(p.`wallet_used`,0)) AS total, MAX(p.`request_date`) AS last_pay, u.`name`, u.`username` FROM `pays` p LEFT JOIN `users` u ON u.`userid`=p.`user_id` WHERE p.`state` IN ('paid','approved') GROUP BY p.`user_id` ORDER BY total DESC, cnt DESC LIMIT 20";
    $res = @$connection->query($sql);
    if(!$res || $res->num_rows <= 0) return "📊 هنوز خرید تاییدشده‌ای برای نمایش وجود ندارد.";
    $i = 1; $lines = ["🏆 <b>برترین خریداران</b>\n"];
    while($r = $res->fetch_assoc()){
        $uid = (string)$r['user_id'];
        $name = trim((string)($r['name'] ?? '')) ?: '-';
        $username = trim((string)($r['username'] ?? ''));
        $userTxt = $username !== '' ? '@' . $username : v2raystore_pro_h($name);
        $last = intval($r['last_pay'] ?? 0);
        $lastTxt = $last > 0 ? (function_exists('jdate') ? jdate('Y-m-d', $last) : date('Y-m-d', $last)) : '-';
        $lines[] = $i . ") <code>$uid</code> | $userTxt | مبلغ: <b>" . number_format(intval($r['total'] ?? 0)) . "</b> | تعداد: <b>" . intval($r['cnt'] ?? 0) . "</b> | آخرین: $lastTxt";
        $i++;
    }
    return implode("\n", $lines);
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

if(!function_exists('v2raystore_pro_create_shared_port_plan')){
function v2raystore_pro_create_shared_port_plan($input){
    global $connection;
    $parts = array_map('trim', explode('|', (string)$input));
    if(count($parts) < 8) return ['ok'=>false, 'message'=>"فرمت درست نیست.\nserver_id|cat_id|inbound_id|title|price|volumeGB|days|count"];
    [$serverId, $catId, $inboundId, $title, $price, $volume, $days, $count] = $parts;
    $serverId = intval($serverId); $catId = intval($catId); $inboundId = intval($inboundId); $price = intval($price); $volume = floatval($volume); $days = floatval($days); $count = intval($count);
    if($serverId <= 0 || $inboundId <= 0 || $title === '' || $price < 0 || $volume <= 0 || $days <= 0) return ['ok'=>false, 'message'=>'مقادیر واردشده معتبر نیست.'];
    $json = function_exists('getJson') ? @getJson($serverId) : null;
    if(!$json || !isset($json->obj)) return ['ok'=>false, 'message'=>'خواندن اینباند از پنل ناموفق بود.'];
    $rows = is_array($json->obj) ? $json->obj : [$json->obj];
    $found = null;
    foreach($rows as $row){ if(intval($row->id ?? 0) === $inboundId){ $found = $row; break; } }
    if(!$found) return ['ok'=>false, 'message'=>'اینباند موردنظر پیدا نشد.'];
    $protocol = trim((string)($found->protocol ?? 'vless')) ?: 'vless';
    $date = (string)time();
    $descr = 'پلن پورت اشتراکی ساخته‌شده از inbound #' . $inboundId;
    $pic = '';
    $fileid = '';
    $type = 'volume';
    $active = 1;
    $step = 10;
    $customPort = intval($found->port ?? 0);
    $flow = 'None';
    $settings = @json_decode((string)($found->settings ?? '{}'), true);
    if(is_array($settings) && !empty($settings['clients'][0]['flow'])) $flow = (string)$settings['clients'][0]['flow'];
    $stmt = @$connection->prepare("INSERT INTO `server_plans` (`fileid`,`catid`,`server_id`,`inbound_id`,`acount`,`title`,`protocol`,`days`,`volume`,`type`,`price`,`descr`,`pic`,`active`,`step`,`date`,`flow`,`custom_port`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if(!$stmt) return ['ok'=>false, 'message'=>'ساخت پلن در دیتابیس ناموفق بود.'];
    $stmt->bind_param('siiiissddsissiissi', $fileid, $catId, $serverId, $inboundId, $count, $title, $protocol, $days, $volume, $type, $price, $descr, $pic, $active, $step, $date, $flow, $customPort);
    $ok = $stmt->execute();
    $newId = $stmt->insert_id;
    $err = $stmt->error;
    $stmt->close();
    if(!$ok) return ['ok'=>false, 'message'=>'خطا در ذخیره پلن: ' . $err];
    return ['ok'=>true, 'message'=>"✅ پلن پورت اشتراکی ساخته شد.\nID پلن: <code>$newId</code>\nپروتکل: <b>" . v2raystore_pro_h($protocol) . "</b>\nInbound: <code>$inboundId</code>"];
}}

if(!function_exists('v2raystore_pro_handle_bot_update')){
function v2raystore_pro_handle_bot_update(){
    global $data, $text, $from_id, $admin, $message_id, $userInfo, $filetype, $fileid, $caption, $buttonValues, $mainValues, $update;
    if(empty($from_id)) return;
    $isAdmin = (intval($from_id) == intval($admin ?? 0)) || (!empty($userInfo['isAdmin']));
    if(!$isAdmin) return;
    $step = (string)($userInfo['step'] ?? '');

    if(($data ?? '') === 'proToolsMenu'){
        editText($message_id, "🚀 <b>ابزارهای حرفه‌ای 3x-ui</b>\n\nاین بخش بدون حذف قابلیت‌های قبلی، ابزارهای جدید مدیریت، پیام هدفمند، پین و پرداخت را اضافه می‌کند.", v2raystore_pro_menu_keyboard(), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proTopBuyers'){
        editText($message_id, v2raystore_pro_top_buyers_text(), v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proReferralAsk'){
        setUser('proReferralLookup');
        editText($message_id, "👥 آیدی عددی کاربر را ارسال کن تا زیرمجموعه‌های او با تعداد و ID عددی نمایش داده شود.", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proReferralLookup' && isset($text) && trim((string)$text) !== ''){
        setUser();
        sendMessage(v2raystore_pro_referrals_text($text), v2raystore_pro_keyboard([[['text'=>'⬅️ ابزار حرفه‌ای','callback_data'=>'proToolsMenu']]]), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proLeaveNoticeMenu'){
        $state = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_STATE', 'on');
        $txt = v2raystore_pro_setting('CHANNEL_LEAVE_NOTICE_TEXT', '⚠️ شما از کانال ربات خارج شدید. برای ادامه استفاده از ربات، لطفاً دوباره عضو کانال شوید.');
        editText($message_id, "🚪 <b>پیام ترک کانال</b>\nوضعیت: <b>$state</b>\n\nمتن فعلی:\n<code>" . v2raystore_pro_h($txt) . "</code>", v2raystore_pro_keyboard([
            [['text'=>($state==='on'?'خاموش کردن':'روشن کردن'), 'callback_data'=>'proToggleLeaveNotice']],
            [['text'=>'✏️ تنظیم متن پیام', 'callback_data'=>'proSetLeaveNoticeText']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']],
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
            [['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']],
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
            [['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']],
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
            [['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']],
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
        $count = farid_countBroadcastTargets($target);
        $title = farid_getBroadcastTargetTitle($target);
        if($count <= 0){
            editText($message_id, "⚠️ برای گروه انتخاب‌شده کاربری پیدا نشد.

🎯 گروه مخاطب: <b>$title</b>", farid_getBroadcastTargetKeyboard('pin'), 'HTML');
            exit();
        }
        setUser('pinToAll|' . $target);
        editText($message_id, "📌 پیام متنی، تصویر یا فایل را ارسال کن تا برای گروه انتخاب‌شده ارسال و پین شود.

🎯 گروه مخاطب: <b>$title</b>
👥 تعداد مخاطبان: <b>$count</b>", null, 'HTML');
        exit();
    }
    if(preg_match('/^pinToAll\|(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config)$/', $step, $pinStepMatch) && isset($text) && ($text !== ($buttonValues['cancel'] ?? 'لغو'))){
        $target = farid_normalizeBroadcastTarget($pinStepMatch[1]);
        $targetTitle = farid_getBroadcastTargetTitle($target);
        $targetCount = farid_countBroadcastTargets($target);
        if($targetCount <= 0){
            setUser();
            sendMessage("⚠️ پین همگانی ثبت نشد، چون برای گروه انتخاب‌شده هیچ مخاطبی وجود ندارد.

🎯 گروه مخاطب: <b>$targetTitle</b>", null, 'HTML');
            exit();
        }
        if(!empty($fileid)){
            $baseType = ($filetype ?? '') ?: 'document';
            $queueType = 'pin_' . $baseType;
            $stmt = $GLOBALS['connection']->prepare("INSERT INTO `send_list` (`type`, `text`, `file_id`, `target_type`) VALUES (?, ?, ?, ?)");
            $cap = (string)($caption ?? '');
            $stmt->bind_param('ssss', $queueType, $cap, $fileid, $target);
        }else{
            $queueType = 'pin_text';
            $stmt = $GLOBALS['connection']->prepare("INSERT INTO `send_list` (`type`, `text`, `target_type`) VALUES (?, ?, ?)");
            $msgText = (string)$text;
            $stmt->bind_param('sss', $queueType, $msgText, $target);
        }
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        setUser();
        sendMessage('⏳ پیام پین هدفمند دریافت شد و آماده بررسی است.', $GLOBALS['removeKeyboard'] ?? null, 'HTML');
        sendMessage("📌 پیش‌نمایش پین هدفمند

🎯 گروه مخاطب: <b>$targetTitle</b>
👥 تعداد مخاطبان: <b>$targetCount</b>

آیا ارسال و پین برای این گروه آغاز شود؟", json_encode(['inline_keyboard'=>[
            [['text'=>'✅ بله، شروع شود', 'callback_data'=>'yesSend2All' . $id, 'style'=>'success'], ['text'=>'❌ لغو', 'callback_data'=>'noDontSend2all' . $id, 'style'=>'danger']]
        ]], JSON_UNESCAPED_UNICODE), 'HTML');
        exit();
    }
    if(($data ?? '') === 'proSharedPortAsk'){
        setUser('proSharedPortCreate');
        editText($message_id, "🔌 ساخت پلن پورت اشتراکی سنایی از روی inbound\n\nفرمت را دقیق ارسال کن:\n<code>server_id|cat_id|inbound_id|title|price|volumeGB|days|count</code>\n\nمثال:\n<code>1|2|5|Shared VLESS|50000|30|30|1</code>", v2raystore_pro_keyboard([[['text'=>'⬅️ بازگشت','callback_data'=>'proToolsMenu']]]), 'HTML');
        exit();
    }
    if($step === 'proSharedPortCreate' && isset($text) && trim((string)$text) !== ''){
        $r = v2raystore_pro_create_shared_port_plan($text);
        setUser();
        sendMessage(($r['ok'] ? '' : '⚠️ ') . $r['message'], v2raystore_pro_keyboard([[['text'=>'⬅️ ابزار حرفه‌ای','callback_data'=>'proToolsMenu']]]), 'HTML');
        exit();
    }
}}
?>
