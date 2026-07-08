<?php
include_once 'config.php';
check();
$robotState = $botState['botState']??"on";
if ($userInfo['step'] == "banned" && $from_id != $admin && $userInfo['isAdmin'] != true) {
    sendMessage($mainValues['banned']);
    exit();
}
$checkSpam = checkSpam();
if(is_numeric($checkSpam)){
    $time = jdate("Y-m-d H:i:s", $checkSpam);
    sendMessage("اکانت شما به دلیل اسپم مسدود شده است\nزمان آزادسازی اکانت شما: \n$time");
    exit();
}
if(preg_match("/^haveJoined(.*)/",$data,$match)){
    if ($joniedState== "kicked" || $joniedState== "left"){
        alert($mainValues['not_joine_yet']);
        exit();
    }else{
        delMessage();
        $text = $match[1];
    }
}
$v2raystoreJoinExempt = (!empty($userInfo) && !empty($userInfo['join_exempt']));
$v2raystoreIsAdminUser = (!empty($userInfo) && !empty($userInfo['isAdmin']));
if(function_exists('v2raystore_pro_process_channel_leave_notice')){
    v2raystore_pro_process_channel_leave_notice($userInfo, $joniedState);
}
if (($joniedState== "kicked" || $joniedState== "left") && $from_id != $admin && !$v2raystoreIsAdminUser && !$v2raystoreJoinExempt){
    sendMessage(str_replace("CHANNEL-ID", $channelLock, $mainValues['join_channel_message']), json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['join_channel'],'url'=>"https://t.me/" . str_replace("@", "", $botState['lockChannel'])]],
        [['text'=>$buttonValues['have_joined'],'callback_data'=>'haveJoined' . $text]],
        ]]),"HTML");
    exit;
}
if($robotState == "off" && $from_id != $admin){
    sendMessage($mainValues['bot_is_updating']);
    exit();
}
if(v2raystore_stopPurchaseIfBlocked($data ?? '', $userInfo['step'] ?? '')){
    exit();
}
if(function_exists('v2raystore_pro_handle_bot_update')){
    v2raystore_pro_handle_bot_update();
}

// دکمه‌های نمایشی/عنوانی نباید هیچ استپی را اجرا کنند.
// قبلاً callback عمومی v2raystore روی بعضی منوها باعث می‌شد متن پیام callback
// به عنوان ورودی مرحله‌های قبلی پردازش شود و منو ناخواسته به بخش‌های دیگر مثل حذف بپرد.
if(isset($data) && (in_array($data, ['v2raystore', 'noop'], true) || preg_match('/^noop_/', (string)$data))){
    alert('این دکمه فقط عنوان است.');
    exit();
}

// پنل «ارسال و دسترسی هر سرور» نماینده مثل بقیه منوهای ساده،
// در بخش مدیریت نماینده هندل می‌شود. کدهای زودهنگام قبلی حذف شدند تا دکمه فقط رنگ عوض نکند.


// منوی جدید و ساده تنظیم نحوه ارسال هر سرور برای نماینده.
// این بخش مستقل از هندلر قدیمی نوشته شده تا دکمه بدون گیر کردن یا تداخل باز شود.
if(preg_match('/^agSendMode(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || (!empty($userInfo) && $userInfo['isAdmin'] == true))){
    $agentId = intval($match[1]);
    $offset = max(0, intval($match[2]));
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    if(!$stmt){ alert('خطا در خواندن نماینده.', true); exit(); }
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$exists){ alert('نماینده پیدا نشد.', true); exit(); }
    $txt = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیم ارسال سرورها';
    $keys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $res = editText($message_id, $txt, $keys, 'HTML');
    if(!is_object($res) || empty($res->ok)){
        sendMessage($txt, $keys, 'HTML');
    }
    exit();
}


if(preg_match('/^agSendToggle(\d+)_(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || (!empty($userInfo) && $userInfo['isAdmin'] == true))){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $offset = max(0, intval($match[3]));
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    if(!$stmt){ alert('خطا در خواندن نماینده.', true); exit(); }
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$exists){ alert('نماینده پیدا نشد.', true); exit(); }

    $next = function_exists('v2raystore_toggleAgentServerDeliveryMode') ? v2raystore_toggleAgentServerDeliveryMode($agentId, $serverId) : false;
    if($next === false){
        alert('تغییر نحوه ارسال انجام نشد.', true);
        exit();
    }
    $txt = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیم ارسال سرورها';
    $keys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $res = editText($message_id, $txt, $keys, 'HTML');
    if(!is_object($res) || empty($res->ok)){
        sendMessage($txt, $keys, 'HTML');
    }
    $label = function_exists('v2raystore_agentServerDeliveryDisplayLabel') ? v2raystore_agentServerDeliveryDisplayLabel($agentId, $serverId) : (function_exists('v2raystore_deliveryModeLabel') ? v2raystore_deliveryModeLabel($next, 'پیش‌فرض سرور ⚙️') : $next);
    alert('نحوه ارسال تغییر کرد: ' . $label);
    exit();
}

if(preg_match('/^agSendSale(\d+)_(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || (!empty($userInfo) && $userInfo['isAdmin'] == true))){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $offset = max(0, intval($match[3]));
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    if(!$stmt){ alert('خطا در خواندن نماینده.', true); exit(); }
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$exists){ alert('نماینده پیدا نشد.', true); exit(); }

    $next = function_exists('v2raystore_toggleAgentServerSaleState') ? v2raystore_toggleAgentServerSaleState($agentId, $serverId) : false;
    if($next === false){
        alert('تغییر وضعیت فروش انجام نشد.', true);
        exit();
    }
    $txt = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیم ارسال سرورها';
    $keys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $res = editText($message_id, $txt, $keys, 'HTML');
    if(!is_object($res) || empty($res->ok)){
        sendMessage($txt, $keys, 'HTML');
    }
    alert($next === 'on' ? 'فروش این سرور برای نماینده باز شد.' : 'فروش این سرور برای نماینده بسته شد.');
    exit();
}

if(preg_match('/^agSendSet(\d+)_(\d+)_(default|both|config|sub)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || (!empty($userInfo) && $userInfo['isAdmin'] == true))){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $mode = $match[3];
    $offset = max(0, intval($match[4]));
    $ok = function_exists('v2raystore_setAgentServerDeliveryMode') ? v2raystore_setAgentServerDeliveryMode($agentId, $serverId, $mode) : false;
    if(!$ok){
        alert('ذخیره تنظیم ارسال انجام نشد.', true);
        exit();
    }
    $txt = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیم ارسال سرورها';
    $keys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $res = editText($message_id, $txt, $keys, 'HTML');
    if(!is_object($res) || empty($res->ok)){
        sendMessage($txt, $keys, 'HTML');
    }
    $label = function_exists('v2raystore_agentServerDeliveryDisplayLabel') ? v2raystore_agentServerDeliveryDisplayLabel($agentId, $serverId) : (function_exists('v2raystore_deliveryModeLabel') ? v2raystore_deliveryModeLabel($mode, 'پیش‌فرض سرور ⚙️') : $mode);
    alert('نحوه ارسال ذخیره شد: ' . $label);
    exit();
}

if(preg_match('/^appTutorial_(v2rayng|v2rayn|streisand)$/', $data ?? '', $match)){
    if(function_exists('v2raystore_botFeatureEnabled') && !v2raystore_botFeatureEnabled('configTutorialButtonsState', 'on')){
        alert('دکمه‌های آموزش توسط مدیریت غیرفعال شده است.');
        exit();
    }
    $app = $match[1];
    $item = function_exists('v2raystore_findTutorialForApp') ? v2raystore_findTutorialForApp($app) : null;
    $title = function_exists('v2raystore_appTutorialTitle') ? v2raystore_appTutorialTitle($app) : $app;
    $body = is_array($item) ? (string)($item['body'] ?? '') : '';
    if(trim($body) === '' && function_exists('v2raystore_defaultTutorialForApp')){
        $def = v2raystore_defaultTutorialForApp($app);
        $body = is_array($def) ? (string)($def['body'] ?? '') : '';
    }
    if(trim($body) === '') $body = 'آموزش پیش‌فرض برای این برنامه پیدا نشد.';
    $msg = "📚 <b>آموزش {$title}</b>

" . $body;
    // برای اینکه دکمه آموزش حتی روی پیام‌های قدیمی/غیرقابل ویرایش هم جواب بدهد، پیام جدا ارسال می‌شود.
    sendMessage($msg, json_encode(['inline_keyboard'=>[
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'mainMenu']]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    if(isset($callback_query->id)) alert('آموزش ارسال شد.');
    exit();
}

if(!function_exists('v2raystore_appendServerPlanToChannelReport')){
    function v2raystore_appendServerPlanToChannelReport($msg, $serverTitle = '', $planTitle = ''){
        $msg = (string)$msg;
        $serverTitle = trim((string)$serverTitle);
        $planTitle = trim((string)$planTitle);
        $lines = [];
        $esc = function($value){ return function_exists('v2raystore_h') ? v2raystore_h($value) : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };

        // بعضی قالب‌های قدیمیِ پیام خرید داخل دیتابیس فقط «نام سرویس/ریمارک» دارند و پلن واقعی را نشان نمی‌دهند.
        // اینجا بدون دست زدن به تنظیمات ذخیره‌شده، سرور و پلن را به گزارش کانال/ادمین اضافه می‌کنیم.
        if($serverTitle !== '' && strpos($msg, 'سرور') === false){
            $lines[] = '🖥 سرور: <b>' . $esc($serverTitle) . '</b>';
        }
        if($planTitle !== '' && strpos($msg, 'پلن') === false){
            $lines[] = '📦 پلن: <b>' . $esc($planTitle) . '</b>';
        }

        if(count($lines) == 0) return $msg;
        return rtrim($msg) . "
" . implode("
", $lines);
    }
}

// لغو امن خرید/پرداخت کارت‌به‌کارت در مرحله ارسال رسید
if(function_exists('v2raystore_isCartToCartReceiptStep') && v2raystore_isCartToCartReceiptStep($userInfo['step'] ?? '', $storeReceiptStepMatch) && (($text ?? '') == ($buttonValues['cancel'] ?? ''))){
    $hashId = $storeReceiptStepMatch[2] ?? '';
    $cancelResult = v2raystore_cancelPendingPayByUser($hashId, $from_id);
    setUser();
    setUser('', 'temp');
    sendMessage(($cancelResult['ok'] ? '❌ ' : '⚠️ ') . $cancelResult['message'], $removeKeyboard, 'HTML');
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit();
}
if(preg_match('/^cancelPendingPay(.+)$/', $data ?? '', $storeCancelPayMatch)){
    $cancelResult = function_exists('v2raystore_cancelPendingPayByUser') ? v2raystore_cancelPendingPayByUser($storeCancelPayMatch[1], $from_id) : ['ok'=>false, 'message'=>'امکان لغو پرداخت در دسترس نیست.'];
    setUser();
    setUser('', 'temp');
    if(isset($message_id)) delMessage();
    sendMessage(($cancelResult['ok'] ? '❌ ' : '⚠️ ') . $cancelResult['message'], $removeKeyboard, 'HTML');
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit();
}

// هندلر مرکزی و مقاوم دریافت رسید کارت‌به‌کارت
// این بخش قبل از هندلرهای قدیمی اجرا می‌شود تا عکس رسید با کپشن/بدون کپشن گم نشود
// و پیام ادمین همیشه همراه دکمه‌های تأیید/رد ارسال شود.
if(function_exists('v2raystore_isCartToCartReceiptStep') && isset($update->message) && v2raystore_isCartToCartReceiptStep($userInfo['step'] ?? '', $storeReceiptStepMatch)){
    $storeReceiptHashId = $storeReceiptStepMatch[2] ?? '';
    $storeReceiptStepPrefix = $storeReceiptStepMatch[1] ?? '';
    $storeReceiptFileId = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, '') : ($fileid ?? '');

    if($storeReceiptFileId === ''){
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($storeReceiptHashId);
        else sendMessage($mainValues['please_send_only_image'] ?? 'لطفاً فقط عکس رسید پرداخت را ارسال کنید.');
        exit();
    }

    if(function_exists('v2raystore_processCartToCartReceiptUpload')){
        $storeReceiptResult = v2raystore_processCartToCartReceiptUpload($storeReceiptHashId, $storeReceiptStepPrefix, $storeReceiptFileId);
        if(!empty($storeReceiptResult['ok'])){
            setUser();
            setUser('', 'temp');
            sendMessage($storeReceiptResult['user_message'] ?? '✅ رسید شما ثبت شد و برای ادمین ارسال شد.', $removeKeyboard, 'HTML');
            sendMessage($mainValues['reached_main_menu'], getMainKeys());
            if(empty($storeReceiptResult['admin_ok'])){
                $adminErr = trim((string)($storeReceiptResult['admin_message'] ?? ''));
                $warn = "⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.";
                if($adminErr !== '') $warn .= "\nعلت: <code>" . v2raystore_h($adminErr) . "</code>";
                sendMessage($warn, null, 'HTML');
            }
        }else{
            $err = trim((string)($storeReceiptResult['message'] ?? 'ثبت رسید انجام نشد.'));
            sendMessage("⚠️ " . $err, function_exists('v2raystore_cartToCartReceiptKeyboard') ? v2raystore_cartToCartReceiptKeyboard($storeReceiptHashId) : null, 'HTML');
        }
        exit();
    }
}
if(function_exists('v2raystore_processAutoApproveOrders')){
    v2raystore_processAutoApproveOrders(false, 2);
}
if($data == 'autoApproveOrdersMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getAutoApproveMenuText(), v2raystore_getAutoApproveMenuKeys(), 'HTML');
    exit();
}
if($data == 'toggleAutoApproveOrders' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $state = v2raystore_getAutoApproveState();
    setSettings('autoApproveState', $state['enabled'] ? 'off' : 'on');
    editText($message_id, v2raystore_getAutoApproveMenuText(), v2raystore_getAutoApproveMenuKeys(), 'HTML');
    exit();
}
if($data == 'setAutoApproveMinutes' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏱ لطفاً تعداد دقیقه برای تأیید خودکار را ارسال کنید.\nمثلاً: 5\n\nعدد مجاز: 1 تا 1440 دقیقه", $cancelKey, 'HTML');
    setUser('setAutoApproveMinutes');
    exit();
}
if($data == 'autoApproveTypesMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getAutoApproveTypesText(), v2raystore_getAutoApproveTypesKeys(), 'HTML');
    exit();
}
if(preg_match('/^toggleAutoApproveType_(.+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $items = function_exists('v2raystore_autoApproveTypeItems') ? v2raystore_autoApproveTypeItems() : [];
    $key = $match[1];
    if(array_key_exists($key, $items)){
        $types = v2raystore_getAutoApproveTypes();
        $types[$key] = (($types[$key] ?? 'on') === 'on') ? 'off' : 'on';
        v2raystore_saveAutoApproveTypes($types);
        editText($message_id, v2raystore_getAutoApproveTypesText(), v2raystore_getAutoApproveTypesKeys(), 'HTML');
    }else alert('گزینه معتبر نیست.', true);
    exit();
}
if(preg_match('/^setAllAutoApproveTypes_(on|off)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $types = [];
    foreach(v2raystore_autoApproveTypeItems() as $key => $item) $types[$key] = $match[1];
    v2raystore_saveAutoApproveTypes($types);
    editText($message_id, v2raystore_getAutoApproveTypesText(), v2raystore_getAutoApproveTypesKeys(), 'HTML');
    exit();
}
if(($userInfo['step'] ?? '') == 'setAutoApproveMinutes' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text) && intval($text) >= 1 && intval($text) <= 1440){
        setSettings('autoApproveMinutes', intval($text));
        setUser();
        sendMessage("✅ زمان تأیید خودکار روی " . intval($text) . " دقیقه تنظیم شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getAutoApproveMenuText(), v2raystore_getAutoApproveMenuKeys(), 'HTML');
    }else{
        sendMessage('لطفاً فقط عدد بین 1 تا 1440 ارسال کنید.');
    }
    exit();
}
if($data == 'autoApproveBlockedUsersMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getAutoApproveBlockedUsersText(), v2raystore_getAutoApproveBlockedUsersKeys(), 'HTML');
    exit();
}
if($data == 'addAutoApproveBlockedUser' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("🚫 آیدی عددی کاربری که نباید رسیدهایش خودکار تأیید شود را ارسال کنید.\n\nمی‌توانید پیام کاربر را هم فوروارد کنید.\nمثال: <code>6073739858</code>", $cancelKey, 'HTML');
    setUser('addAutoApproveBlockedUser');
    exit();
}
if(($userInfo['step'] ?? '') == 'addAutoApproveBlockedUser' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = 0;
    if(isset($update->message->forward_from->id)) $targetId = intval($update->message->forward_from->id);
    elseif(preg_match('/(\d{5,15})/', (string)$text, $m)) $targetId = intval($m[1]);

    if($targetId > 0){
        v2raystore_addAutoApproveBlockedUser($targetId);
        setUser();
        sendMessage("✅ کاربر <code>$targetId</code> از تأیید خودکار مستثنی شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getAutoApproveBlockedUsersText(), v2raystore_getAutoApproveBlockedUsersKeys(), 'HTML');
    }else{
        sendMessage('❌ آیدی معتبر پیدا نشد. فقط آیدی عددی کاربر را ارسال کنید.', $cancelKey, 'HTML');
    }
    exit();
}
if($data == 'removeAutoApproveBlockedUserManual' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("➖ آیدی عددی کاربری که می‌خواهید از لیست بلاک تأیید خودکار حذف شود را ارسال کنید.", $cancelKey, 'HTML');
    setUser('removeAutoApproveBlockedUserManual');
    exit();
}
if(($userInfo['step'] ?? '') == 'removeAutoApproveBlockedUserManual' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = 0;
    if(isset($update->message->forward_from->id)) $targetId = intval($update->message->forward_from->id);
    elseif(preg_match('/(\d{5,15})/', (string)$text, $m)) $targetId = intval($m[1]);

    if($targetId > 0){
        v2raystore_removeAutoApproveBlockedUser($targetId);
        setUser();
        sendMessage("✅ کاربر <code>$targetId</code> از لیست بلاک تأیید خودکار حذف شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getAutoApproveBlockedUsersText(), v2raystore_getAutoApproveBlockedUsersKeys(), 'HTML');
    }else{
        sendMessage('❌ آیدی معتبر پیدا نشد. فقط آیدی عددی کاربر را ارسال کنید.', $cancelKey, 'HTML');
    }
    exit();
}
if(preg_match('/^removeAutoApproveBlockedUser(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_removeAutoApproveBlockedUser(intval($match[1]));
    editText($message_id, v2raystore_getAutoApproveBlockedUsersText(), v2raystore_getAutoApproveBlockedUsersKeys(), 'HTML');
    exit();
}
if($data == 'clearAutoApproveBlockedUsers' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_saveAutoApproveBlockedUsers([]);
    editText($message_id, v2raystore_getAutoApproveBlockedUsersText(), v2raystore_getAutoApproveBlockedUsersKeys(), 'HTML');
    exit();
}

if($data == 'runAutoApproveOrdersNow' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $result = v2raystore_processAutoApproveOrders(true, 10);
    $msg = "🚀 بررسی دستی تأیید خودکار انجام شد.\n\nتعداد تأیید شده: " . intval($result['processed']);
    if(!empty($result['messages'])) $msg .= "\n\n" . implode("\n", $result['messages']);
    editText($message_id, $msg . "\n\n" . v2raystore_getAutoApproveMenuText(), v2raystore_getAutoApproveMenuKeys(), 'HTML');
    exit();
}

if($data == 'setReportGroupChat' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("📌 آیدی گروه/کانال گزارش را ارسال کنید.\n\nبرای دسته‌بندی با تاپیک، بهتر است یک <b>سوپرگروه Forum</b> بسازی، ربات را ادمین کنی، سپس آیدی گروه مثل <code>-1001234567890</code> را بفرستی.\n\nاگر گروه معمولی یا کانال بدهی، گزارش‌ها بدون تاپیک ارسال می‌شوند.", $cancelKey, 'HTML');
    setUser('setReportGroupChat');
    exit();
}
if(($userInfo['step'] ?? '') == 'setReportGroupChat' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $chat = trim((string)$text);
    $botInfo = bot('getMe');
    $botId = (is_object($botInfo) && !empty($botInfo->ok) && isset($botInfo->result->id)) ? intval($botInfo->result->id) : 0;
    $result = $botId ? bot('getChatMember', ['chat_id'=>$chat, 'user_id'=>$botId]) : null;
    if(is_object($result) && !empty($result->ok) && isset($result->result->status) && in_array($result->result->status, ['administrator','creator'], true)){
        setSettings('rewardChannel', $chat);
        // مقصد عوض شده؛ تاپیک‌های قبلی دیگر معتبر نیستند.
        if(function_exists('v2raystore_saveReportTopicStore')) v2raystore_saveReportTopicStore([]);
        setUser();
        sendMessage("✅ مقصد گزارش‌ها تنظیم شد: <code>" . v2raystore_h($chat) . "</code>", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else{
        sendMessage("❌ ربات داخل این گروه/کانال ادمین نیست یا آیدی اشتباه است.\nلطفاً ربات را ادمین کن و آیدی را دوباره بفرست.", $cancelKey, 'HTML');
    }
    exit();
}
if($data == 'toggleReportForumTopics' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(v2raystore_reportForumEnabled()){
        v2raystore_reportDeleteAllTopics();
        setSettings('storeReportForumState', 'off');
        alert('تاپیک‌های گزارش خاموش و حذف شدند.');
    }else{
        setSettings('storeReportForumState', 'on');
        alert('حالت تاپیک فعال شد. با اولین گزارش، تاپیک مربوطه ساخته می‌شود.');
    }
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'deleteAllReportForumTopics' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_reportDeleteAllTopics();
    alert('تاپیک‌های ذخیره‌شده حذف شدند.');
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'rebuildReportForumTopics' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!v2raystore_reportForumEnabled()) setSettings('storeReportForumState', 'on');
    $made = 0;
    foreach(v2raystore_reportTopicItems() as $topicKey => $info){
        if(!v2raystore_reportTopicHasEnabledEvents($topicKey)) continue;
        $events = $info['events'] ?? [];
        $eventKey = count($events) ? $events[0] : 'daily_stats';
        $thread = v2raystore_reportEnsureTopic($eventKey);
        if($thread > 0) $made++;
    }
    alert($made > 0 ? 'تاپیک‌ها ساخته/ترمیم شدند.' : 'ساخت تاپیک ناموفق بود؛ گروه باید Forum باشد و ربات دسترسی مدیریت تاپیک داشته باشد.', $made <= 0);
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'toggleReportBackupBotDb' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $new = v2raystore_backupBotDbEnabled() ? 'off' : 'on';
    setSettings('storeBackupBotDbState', $new);
    if($new === 'off' && !v2raystore_anyPanelDbBackupEnabled()) v2raystore_reportDeleteTopic('database');
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if(($data == 'setReportBackupInterval' || $data == 'setReportBackupTime') && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏱ فاصله اجرای بکاپ دیتابیس را بفرستید.

مثال‌ها:
<code>30 دقیقه</code>
<code>نیم ساعت</code>
<code>1 ساعت</code>
<code>24 ساعت</code>

عدد تنها هم به دقیقه حساب می‌شود. حداقل مجاز ۱۰ دقیقه است.", $cancelKey, 'HTML');
    setUser('setReportBackupInterval');
    exit();
}
if(($userInfo['step'] ?? '') == 'setReportBackupInterval' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $minutes = function_exists('v2raystore_parseBackupIntervalMinutes') ? v2raystore_parseBackupIntervalMinutes($text) : intval($text);
    if($minutes >= 10){
        setSettings('storeReportBackupIntervalMinutes', $minutes);
        setSettings('storeReportBackupLastTs', time());
        setUser();
        $pretty = function_exists('v2raystore_formatMinutesFa') ? v2raystore_formatMinutesFa($minutes) : ($minutes . ' دقیقه');
        sendMessage("✅ فاصله بکاپ دیتابیس روی <b>" . v2raystore_h($pretty) . "</b> تنظیم شد.

از این لحظه، بکاپ بعدی بعد از همین فاصله اجرا می‌شود. برای ارسال فوری می‌توانید از گزینه <b>اجرای بکاپ الان</b> استفاده کنید.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else{
        sendMessage("❌ مقدار وارد شده درست نیست. مثال: <code>30 دقیقه</code> یا <code>1 ساعت</code>
حداقل مجاز ۱۰ دقیقه است.", null, 'HTML');
    }
    exit();
}
if($data == 'setReportBackupItemDelay' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ فاصله بین بکاپ دیتابیس ربات و هر پنل را به ثانیه بفرستید.

مثال: <code>15</code>
عدد پیشنهادی: ۱۰ تا ۳۰ ثانیه
حداکثر مجاز: ۳۰۰ ثانیه", $cancelKey, 'HTML');
    setUser('setReportBackupItemDelay');
    exit();
}
if(($userInfo['step'] ?? '') == 'setReportBackupItemDelay' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $sec = intval(strtr(trim((string)$text), $map));
    if($sec >= 0 && $sec <= 300){
        setSettings('storeReportBackupItemDelaySeconds', $sec);
        setUser();
        sendMessage("✅ فاصله بین ارسال هر بکاپ روی <b>{$sec} ثانیه</b> تنظیم شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else{
        sendMessage("❌ عدد باید بین ۰ تا ۳۰۰ ثانیه باشد.", null, 'HTML');
    }
    exit();
}
if($data == 'resetReportBackupSchedule' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setSettings('storeReportBackupLastTs', 0);
    alert('زمان‌بندی بکاپ ریست شد. در اولین اجرای کران، اگر بکاپ فعال باشد اجرا می‌شود.');
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'runReportDbBackupsNow' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert('بکاپ در حال اجراست؛ اگر دیتابیس بزرگ باشد کمی زمان می‌برد.');
    $res = v2raystore_runReportDatabaseBackups(true);
    editText($message_id, ($res['ok'] ? "✅ " : "❌ ") . v2raystore_h($res['message'] ?? 'انجام شد') . "\n\n" . v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'reportPanelDbBackupMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getReportPanelBackupMenuText(), v2raystore_getReportPanelBackupMenuKeys(), 'HTML');
    exit();
}
if(preg_match('/^togglePanelDbBackup(\d+)$/', $data ?? '', $mm) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sid = intval($mm[1]);
    $key = 'storePanelDbBackup_' . $sid;
    $new = v2raystore_panelDbBackupEnabled($sid) ? 'off' : 'on';
    setSettings($key, $new);
    if($new === 'off' && !v2raystore_backupBotDbEnabled() && !v2raystore_anyPanelDbBackupEnabled()) v2raystore_reportDeleteTopic('database');
    editText($message_id, v2raystore_getReportPanelBackupMenuText(), v2raystore_getReportPanelBackupMenuKeys(), 'HTML');
    exit();
}
if($data == 'reportChannelSettingsMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'toggleDailyChannelStats' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_reportToggleSetting('storeReportDailyState', 'off');
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'toggleReportLiveStats' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_reportToggleSetting('storeReportLiveStatsState', 'on');
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if($data == 'setDailyChannelStatsTime' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("🕘 لطفاً ساعت ارسال آمار روزانه را با فرمت 24 ساعته بفرستید.
مثال: <code>21:30</code>

زمان بر اساس ساعت سرور ربات محاسبه می‌شود.", $cancelKey, 'HTML');
    setUser('setDailyChannelStatsTime');
    exit();
}
if(($userInfo['step'] ?? '') == 'setDailyChannelStatsTime' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $timeText = trim((string)$text);
    if(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeText)){
        setSettings('storeReportDailyTime', $timeText);
        setUser();
        sendMessage("✅ ساعت ارسال آمار روزانه روی <b>$timeText</b> تنظیم شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else{
        sendMessage("❌ فرمت ساعت درست نیست. مثال درست: <code>21:30</code>", null, 'HTML');
    }
    exit();
}
if($data == 'sendDailyChannelStatsNow' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sent = v2raystore_sendDailyChannelStats(true);
    alert($sent ? 'آمار به کانال/گروه گزارش ارسال شد.' : 'ارسال آمار ناموفق بود.', !$sent);
    editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    exit();
}
if(preg_match('/^toggleReportEvent_(.+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $key = $match[1];
    if(array_key_exists($key, v2raystore_reportEventItems())){
        $newState = v2raystore_reportToggleSetting(v2raystore_reportEventKey($key), 'on');
        if($newState === 'off') v2raystore_reportDeleteTopicForEvent($key);
        editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else alert('گزینه معتبر نیست.', true);
    exit();
}
if(preg_match('/^toggleReportDetail_(.+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $key = $match[1];
    if(array_key_exists($key, v2raystore_reportDetailItems())){
        v2raystore_reportToggleSetting(v2raystore_reportDetailKey($key), 'on');
        editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else alert('گزینه معتبر نیست.', true);
    exit();
}
if(preg_match('/^toggleReportStat_(.+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $key = $match[1];
    if(array_key_exists($key, v2raystore_reportStatItems())){
        v2raystore_reportToggleSetting(v2raystore_reportStatKey($key), 'on');
        editText($message_id, v2raystore_getReportSettingsMenuText(), v2raystore_getReportSettingsMenuKeys(), 'HTML');
    }else alert('گزینه معتبر نیست.', true);
    exit();
}
if(preg_match('/^autoCancelOrder(.+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    sendMessage("📝 لطفاً دلیل لغو کامل سفارش خودکار را ارسال کنید.\nاین دلیل برای کاربر هم ارسال می‌شود.", $cancelKey, 'HTML', $from_id);
    setUser('autoCancelOrder|' . $hashId . '|' . $chat_id . '|' . $message_id);
    alert('دلیل لغو را در پی وی ربات ارسال کنید.', true);
    exit();
}
if(preg_match('/^autoCancelOrder\|(.+)\|(-?\d+)\|(\d+)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = $match[1];
    $reportChatId = $match[2];
    $reportMsgId = intval($match[3]);
    $result = v2raystore_cancelAutoApprovedPay($hashId, $text);
    setUser();
    sendMessage(($result['ok'] ? '✅ ' : '❌ ') . $result['message'], $removeKeyboard, 'HTML');
    if($result['ok']){
        $cancelTitle = (($result['type'] ?? '') === 'RENEW_ACCOUNT') ? '↩️ تمدید لغو شد و سرویس به قبل از تمدید برگشت.' : '❌ سفارش خودکار لغو و حذف شد.';
        editText($reportMsgId, $cancelTitle . "\n\n🔖 کد پرداخت: <code>" . htmlspecialchars($hashId, ENT_QUOTES, 'UTF-8') . "</code>\n📝 دلیل:\n" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), null, 'HTML', $reportChatId);
    }
    exit();
}

if(preg_match('/^approveNewMember(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = (int)$match[1];
    $targetUser = v2raystore_getUserByTelegramId($targetId);
    if(!$targetUser){
        alert("کاربر پیدا نشد", true);
        exit();
    }

    $referrerId = !empty($targetUser['approval_referrer']) ? (int)$targetUser['approval_referrer'] : (!empty($targetUser['refered_by']) ? (int)$targetUser['refered_by'] : null);
    if(!empty($referrerId)){
        $stmt = $connection->prepare("UPDATE `users` SET `approval_status` = 'approved', `step` = 'none', `refered_by` = ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $referrerId, $targetId);
    }else{
        $stmt = $connection->prepare("UPDATE `users` SET `approval_status` = 'approved', `step` = 'none' WHERE `userid` = ?");
        $stmt->bind_param("i", $targetId);
    }
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ درخواست عضویت شما توسط مدیریت تایید شد.\n\nاکنون می‌توانید از ربات استفاده کنید. برای شروع، /start را ارسال کنید.", null, 'HTML', $targetId);
    if(!empty($referrerId)){
        sendMessage($mainValues['invited_user_joined_message'], null, null, $referrerId);
    }

    editText($message_id, "✅ عضویت کاربر <code>$targetId</code> با موفقیت تایید شد.", null, 'HTML');
    alert("تایید شد");
    exit();
}
if(preg_match('/^rejectNewMember(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = (int)$match[1];
    $stmt = $connection->prepare("UPDATE `users` SET `approval_status` = 'rejected', `step` = 'none' WHERE `userid` = ?");
    $stmt->bind_param("i", $targetId);
    $stmt->execute();
    $stmt->close();

    sendMessage("❌ درخواست عضویت شما توسط مدیریت تایید نشد.\n\nدر صورت نیاز، می‌توانید دوباره آیدی عددی معرف معتبر را ارسال کنید تا درخواست جدید ثبت شود.", null, 'HTML', $targetId);
    editText($message_id, "❌ درخواست عضویت کاربر <code>$targetId</code> رد شد.", null, 'HTML');
    alert("رد شد");
    exit();
}
if(preg_match('/^revokeCodeAccess(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = (int)$match[1];
    $targetUser = v2raystore_getUserByTelegramId($targetId);
    if(!$targetUser){
        alert("کاربر مورد نظر یافت نشد.", true);
        exit();
    }
    $ok = v2raystore_setUserAccessExempt($targetId, false);
    if($ok){
        sendMessage("🔒 دسترسی شما که از طریق کد ورود فعال شده بود، توسط مدیریت غیرفعال شد.\n\nدر صورت دریافت کد ورود جدید از مدیریت، می‌توانید دوباره آن را در ربات ارسال کنید.", null, 'HTML', $targetId);
        $msg = "🧹 <b>دسترسی کد ورود حذف شد</b>\n\n" .
               "دسترسی ایجادشده با کد ورود برای کاربر <code>$targetId</code> حذف شد.\n" .
               "در صورت ارسال کد معتبر جدید، دسترسی کاربر مجدداً فعال خواهد شد.";
        $keys = v2raystore_inlineKeyboardJson([
            [['text'=>'🚫 بلاک کاربر', 'callback_data'=>'blockCodeAccess' . $targetId, 'style'=>'danger']]
        ]);
        editText($message_id, $msg, $keys, 'HTML');
        alert("دسترسی حذف شد.");
    }else{
        alert("حذف دسترسی انجام نشد. دوباره تلاش کنید.", true);
    }
    exit();
}
if(preg_match('/^blockCodeAccess(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = (int)$match[1];
    $targetUser = v2raystore_getUserByTelegramId($targetId);
    if(!$targetUser){
        alert("کاربر مورد نظر یافت نشد.", true);
        exit();
    }
    $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned', `access_exempt` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $targetId);
    $ok = $stmt->execute();
    $stmt->close();
    if($ok){
        sendMessage("⛔️ دسترسی شما به ربات توسط مدیریت مسدود شد.\n\nدر صورت نیاز، لطفاً با پشتیبانی در ارتباط باشید.", null, 'HTML', $targetId);
        editText($message_id, "🚫 کاربر <code>$targetId</code> با موفقیت مسدود شد.", null, 'HTML');
        alert("کاربر مسدود شد.");
    }else{
        alert("مسدودسازی انجام نشد. دوباره تلاش کنید.", true);
    }
    exit();
}
if($data == "testAccountManagement" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $msg = "🧪 <b>مدیریت اکانت تست</b>\n\n" .
           "از این بخش می‌توانید تست‌ها را برای هر سرور یا هر اینباند تعریف کنید، سقف دریافت هر تست را کنترل کنید و سابقه استفاده کاربران را ریست کنید.";
    editText($message_id, $msg, v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}

if($data == "testPlansList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getTestPlanListText(false), v2raystore_getTestPlanListKeys(), "HTML");
    exit();
}
if(preg_match('/^testPlanDetails(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getTestPlanDetailsText($m[1]), v2raystore_getTestPlanDetailsKeys($m[1]), "HTML");
    exit();
}
if(preg_match('/^toggleTestPlanState(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    $stmt = $connection->prepare("UPDATE `server_plans` SET `active` = IF(`active`=1,0,1) WHERE `id`=? AND COALESCE(`price`,0)=0");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $stmt->close();
    editText($message_id, v2raystore_getTestPlanDetailsText($pid), v2raystore_getTestPlanDetailsKeys($pid), "HTML");
    exit();
}
if(preg_match('/^deleteTestPlanAsk(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    editText($message_id, "⚠️ این اکانت تست از لیست کاربران حذف می‌شود، ولی سفارش‌های قبلی کاربران پاک نمی‌شوند.\n\nمطمئنی حذف شود؟", json_encode(['inline_keyboard'=>[
        [['text'=>'✅ بله حذف شود', 'callback_data'=>'deleteTestPlanConfirm' . $pid, 'style'=>'danger']],
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'testPlanDetails' . $pid, 'style'=>'primary']]
    ]], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if(preg_match('/^deleteTestPlanConfirm(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=? AND COALESCE(`price`,0)=0 LIMIT 1");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $stmt->close();
    editText($message_id, "✅ اکانت تست حذف شد.", v2raystore_getTestPlanListKeys(), "HTML");
    exit();
}
if(preg_match('/^editTestPlanField(\d+)_(title|volume|days|acount|limitip)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $labels = ['title'=>'عنوان', 'volume'=>'حجم گیگ', 'days'=>'مدت روز', 'acount'=>'ظرفیت', 'limitip'=>'محدودیت IP'];
    delMessage();
    sendMessage("✏️ مقدار جدید برای <b>" . ($labels[$m[2]] ?? $m[2]) . "</b> پلن تست #" . intval($m[1]) . " را ارسال کنید.", $cancelKey, "HTML");
    setUser('editTestPlanField|' . intval($m[1]) . '|' . $m[2]);
    exit();
}
if(preg_match('/^editTestPlanField\|(\d+)\|(title|volume|days|acount|limitip)$/', $userInfo['step'] ?? '', $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    $field = $m[2];
    $value = trim((string)$text);
    if(in_array($field, ['volume','days'], true)){
        if(!is_numeric($value) || floatval($value) <= 0){ sendMessage('❌ مقدار باید عددی و بزرگتر از صفر باشد.', $cancelKey, 'HTML'); exit(); }
        $num = floatval($value);
        $stmt = $connection->prepare("UPDATE `server_plans` SET `$field`=? WHERE `id`=? AND COALESCE(`price`,0)=0");
        $stmt->bind_param("di", $num, $pid);
    }elseif(in_array($field, ['acount','limitip'], true)){
        if(!preg_match('/^\d+$/', $value)){ sendMessage('❌ مقدار باید عدد صحیح باشد.', $cancelKey, 'HTML'); exit(); }
        $num = intval($value);
        $stmt = $connection->prepare("UPDATE `server_plans` SET `$field`=? WHERE `id`=? AND COALESCE(`price`,0)=0");
        $stmt->bind_param("ii", $num, $pid);
    }else{
        if($value === ''){ sendMessage('❌ عنوان نمی‌تواند خالی باشد.', $cancelKey, 'HTML'); exit(); }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=? AND COALESCE(`price`,0)=0");
        $stmt->bind_param("si", $value, $pid);
    }
    $stmt->execute();
    $stmt->close();
    setUser();
    sendMessage('✅ ذخیره شد.', $removeKeyboard, 'HTML');
    sendMessage(v2raystore_getTestPlanDetailsText($pid), v2raystore_getTestPlanDetailsKeys($pid), 'HTML');
    exit();
}
if($data == "addTestPlan" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $res = $connection->query("SELECT `id`, `title`, `flag` FROM `server_info` WHERE `active`=1 ORDER BY `id` ASC");
    $rows = [];
    if($res){
        while($srv = $res->fetch_assoc()){
            $rows[] = [[ 'text'=>trim(($srv['flag'] ? $srv['flag'] . ' ' : '') . $srv['title']), 'callback_data'=>'addTestPlanServer' . intval($srv['id']) ]];
        }
    }
    if(empty($rows)) $rows[] = [[ 'text'=>'سرور فعالی پیدا نشد', 'callback_data'=>'v2raystore' ]];
    $rows[] = [[ 'text'=>'⬅️ بازگشت', 'callback_data'=>'testAccountManagement' ]];
    editText($message_id, "🧪 <b>افزودن اکانت تست</b>\n\nاول سرور را انتخاب کن:", json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if(preg_match('/^addTestPlanServer(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sid = intval($m[1]);
    $rows = [];
    $rows[] = [[ 'text'=>'کل سرور / پورت اختصاصی', 'callback_data'=>'addTestPlanInbound' . $sid . '_0' ]];
    $json = function_exists('getJson') ? getJson($sid) : null;
    if($json && isset($json->obj) && is_array($json->obj)){
        foreach($json->obj as $row){
            $iid = intval($row->id ?? 0);
            if($iid <= 0) continue;
            $proto = (string)($row->protocol ?? '');
            $net = '';
            $ss = json_decode($row->streamSettings ?? '');
            if($ss && isset($ss->network)) $net = (string)$ss->network;
            $remark = trim((string)($row->remark ?? ''));
            $title = 'اینباند ' . $iid . ($remark !== '' ? ' - ' . $remark : '') . ($proto !== '' ? ' | ' . $proto : '') . ($net !== '' ? '/' . $net : '');
            $rows[] = [[ 'text'=>$title, 'callback_data'=>'addTestPlanInbound' . $sid . '_' . $iid ]];
        }
    }
    $rows[] = [[ 'text'=>'⬅️ بازگشت', 'callback_data'=>'addTestPlan' ]];
    editText($message_id, "🚪 حالا اینباند تست را انتخاب کن.\n\nاگر می‌خواهی تست برای کل سرور/پورت اختصاصی باشد، گزینه اول را بزن.", json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if(preg_match('/^addTestPlanInbound(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sid = intval($m[1]);
    $iid = intval($m[2]);
    $ctx = ['server_id'=>$sid, 'inbound_id'=>$iid, 'protocol'=>'', 'type'=>''];
    if($iid > 0){
        $json = function_exists('getJson') ? getJson($sid) : null;
        if($json && isset($json->obj) && is_array($json->obj)){
            foreach($json->obj as $row){
                if(intval($row->id ?? 0) === $iid){
                    $ctx['protocol'] = (string)($row->protocol ?? 'vless');
                    $ss = json_decode($row->streamSettings ?? '');
                    $ctx['type'] = ($ss && isset($ss->network)) ? (string)$ss->network : 'tcp';
                    break;
                }
            }
        }
        if($ctx['protocol'] === '') $ctx['protocol'] = 'vless';
        if($ctx['type'] === '') $ctx['type'] = 'tcp';
        setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
        delMessage();
        sendMessage("🔋 حجم اکانت تست را به گیگ بفرست.\nمثال: <code>0.1</code>", $cancelKey, "HTML");
        setUser('addTestPlanVolume');
    }else{
        setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
        delMessage();
        sendMessage("📡 پروتکل تست را بفرست: <code>vless</code> یا <code>vmess</code> یا <code>trojan</code>", $cancelKey, "HTML");
        setUser('addTestPlanProtocol');
    }
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanProtocol' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $proto = strtolower(trim((string)$text));
    if(!in_array($proto, ['vless','vmess','trojan'], true)){ sendMessage('❌ فقط vless یا vmess یا trojan مجاز است.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['protocol'] = $proto;
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("🌐 نوع شبکه را بفرست. مثال: <code>tcp</code> یا <code>ws</code> یا <code>grpc</code>", $cancelKey, 'HTML');
    setUser('addTestPlanNetwork');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanNetwork' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $net = strtolower(trim((string)$text));
    if(!preg_match('/^[a-z0-9_-]{2,30}$/', $net)){ sendMessage('❌ نوع شبکه معتبر نیست.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['type'] = $net;
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("🔋 حجم اکانت تست را به گیگ بفرست.\nمثال: <code>0.1</code>", $cancelKey, 'HTML');
    setUser('addTestPlanVolume');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanVolume' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text) || floatval($text) <= 0){ sendMessage('❌ حجم باید عددی و بزرگتر از صفر باشد.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['volume'] = floatval($text);
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("⏰ مدت اعتبار تست را به روز بفرست.\nمثال: <code>1</code>", $cancelKey, 'HTML');
    setUser('addTestPlanDays');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanDays' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text) || floatval($text) <= 0){ sendMessage('❌ مدت باید عددی و بزرگتر از صفر باشد.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['days'] = floatval($text);
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("🚪 ظرفیت ساخت تست برای این مورد را بفرست.\nمثال: <code>100</code>\nبرای نامحدود بودن ظرفیت پلن، عدد بزرگ بگذار.", $cancelKey, 'HTML');
    setUser('addTestPlanCapacity');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanCapacity' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!preg_match('/^\d+$/', trim((string)$text))){ sendMessage('❌ ظرفیت باید عدد صحیح باشد.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['acount'] = intval($text);
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("👥 محدودیت IP را بفرست.\nمعمولاً <code>1</code>", $cancelKey, 'HTML');
    setUser('addTestPlanLimitIp');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanLimitIp' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!preg_match('/^\d+$/', trim((string)$text)) || intval($text) < 0){ sendMessage('❌ محدودیت IP باید عدد صحیح باشد.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $ctx['limitip'] = intval($text);
    setUser(json_encode($ctx, JSON_UNESCAPED_UNICODE), 'temp');
    sendMessage("🔮 عنوان نمایشی تست را بفرست.\nمثال: <code>تست آلمان</code>", $cancelKey, 'HTML');
    setUser('addTestPlanTitle');
    exit();
}
if(($userInfo['step'] ?? '') == 'addTestPlanTitle' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $title = trim((string)$text);
    if($title === ''){ sendMessage('❌ عنوان نمی‌تواند خالی باشد.', $cancelKey, 'HTML'); exit(); }
    $ctx = json_decode($userInfo['temp'] ?? '{}', true); if(!is_array($ctx)) $ctx = [];
    $sid = intval($ctx['server_id'] ?? 0); $iid = intval($ctx['inbound_id'] ?? 0);
    $volume = floatval($ctx['volume'] ?? 0); $days = floatval($ctx['days'] ?? 0); $acount = intval($ctx['acount'] ?? 0); $limitip = intval($ctx['limitip'] ?? 1);
    $protocol = trim((string)($ctx['protocol'] ?? 'vless')); $net = trim((string)($ctx['type'] ?? 'tcp'));
    if($sid <= 0 || $volume <= 0 || $days <= 0){ sendMessage('❌ اطلاعات مرحله ناقص است. دوباره از افزودن اکانت تست شروع کن.', $removeKeyboard, 'HTML'); setUser('', 'temp'); setUser(); exit(); }
    $now = time();
    $stmt = $connection->prepare("INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `is_test_plan`, `descr`, `pic`, `active`, `step`, `date`) VALUES ('', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, '', '', 1, 10, ?)");
    $stmt->bind_param("iiiissddsi", $sid, $iid, $acount, $limitip, $title, $protocol, $days, $volume, $net, $now);
    $ok = $stmt->execute();
    $newId = intval($connection->insert_id);
    $err = $stmt->error;
    $stmt->close();
    setUser('', 'temp'); setUser();
    if($ok){
        sendMessage("✅ اکانت تست جدید ثبت شد.", $removeKeyboard, 'HTML');
        sendMessage(v2raystore_getTestPlanDetailsText($newId), v2raystore_getTestPlanDetailsKeys($newId), 'HTML');
    }else{
        sendMessage("❌ ثبت تست ناموفق بود: <code>" . v2raystore_h($err) . "</code>", $removeKeyboard, 'HTML');
    }
    exit();
}

if($data == "toggleTestAccountAutoDelete" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $current = function_exists('v2raystore_getTestAccountAutoDeleteState') ? v2raystore_getTestAccountAutoDeleteState() : 'off';
    $newState = ($current === 'on') ? 'off' : 'on';
    if(function_exists('v2raystore_setTestAccountAutoDeleteState')) v2raystore_setTestAccountAutoDeleteState($newState);
    $msg = "🧪 <b>مدیریت اکانت تست</b>

" .
           "حذف خودکار اکانت تست بعد از پایان: <b>" . ($newState === 'on' ? 'روشن ✅' : 'خاموش ❌') . "</b>";
    editText($message_id, $msg, v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}

if(preg_match('/^adjustDefaultTestAccountLimit_(plus|minus)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $current = function_exists('v2raystore_getDefaultTestAccountLimit') ? v2raystore_getDefaultTestAccountLimit() : 1;
    $newLimit = ($m[1] === 'plus') ? ($current + 1) : max(1, $current - 1);
    if(function_exists('v2raystore_setDefaultTestAccountLimit')) v2raystore_setDefaultTestAccountLimit($newLimit);
    $msg = "🧪 <b>مدیریت اکانت تست</b>

" .
           "سقف پیش‌فرض اکانت تست برای کاربران روی <b>{$newLimit} بار</b> تنظیم شد.

" .
           "کاربرانی که سقف اختصاصی دارند، طبق سقف اختصاصی خودشان محاسبه می‌شوند.";
    editText($message_id, $msg, v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}
if($data == "resetAllTestAccountsAsk" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,
        "⚠️ <b>تایید ریست اکانت تست</b>\n\nبا تایید این گزینه، سابقه استفاده از اکانت تست برای همه کاربران پاک می‌شود و همه می‌توانند دوباره طبق سقف مجازشان از تست استفاده کنند.\n\nآیا مطمئن هستید؟",
        json_encode(['inline_keyboard'=>[
            [['text'=>'✅ بله، ریست شود', 'callback_data'=>'resetAllTestAccountsConfirm', 'style'=>'success']],
            [['text'=>'⬅️ بازگشت', 'callback_data'=>'testAccountManagement', 'style'=>'primary']]
        ]], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if($data == "resetAllTestAccountsConfirm" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $connection->query("UPDATE `users` SET `freetrial` = NULL, `test_account_count` = 0");
    $connection->query("TRUNCATE TABLE `test_account_usage`");
    editText($message_id, "✅ سابقه استفاده از اکانت تست برای همه کاربران با موفقیت ریست شد.", v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}
if($data == "resetTestPlanUsageList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getTestPlanResetListText(), v2raystore_getTestPlanResetListKeys(), "HTML");
    exit();
}
if(preg_match('/^resetTestPlanUsageAsk(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    $plan = function_exists('v2raystore_getTestPlanById') ? v2raystore_getTestPlanById($pid) : null;
    if(!$plan){
        alert('اکانت تست پیدا نشد.', true);
        exit();
    }
    $stats = function_exists('v2raystore_getTestPlanUsageStats') ? v2raystore_getTestPlanUsageStats($plan) : ['user_count'=>0, 'usage_count'=>0];
    $title = function_exists('v2raystore_testPlanDisplayTitle') ? v2raystore_testPlanDisplayTitle($plan, true) : ('تست #' . $pid);
    $safeTitle = function_exists('v2raystore_h') ? v2raystore_h($title) : htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $msg = "⚠️ <b>تایید ریست جداگانه اکانت تست</b>\n\n" .
           "مورد انتخابی: <b>{$safeTitle}</b>\n" .
           "کاربران استفاده‌کرده: <b>" . intval($stats['user_count'] ?? 0) . "</b>\n" .
           "تعداد رکورد تست: <b>" . intval($stats['usage_count'] ?? 0) . "</b>\n\n" .
           "با تایید، فقط سابقه دریافت همین سرور/اینباند پاک می‌شود و تست‌های بقیه سرورها دست‌نخورده می‌مانند.";
    editText($message_id, $msg, json_encode(['inline_keyboard'=>[
        [['text'=>'✅ بله، همین تست ریست شود', 'callback_data'=>'resetTestPlanUsageConfirm' . $pid, 'style'=>'danger']],
        [['text'=>'📋 بازگشت به لیست ریست', 'callback_data'=>'resetTestPlanUsageList', 'style'=>'primary']],
        [['text'=>'⬅️ جزئیات تست', 'callback_data'=>'testPlanDetails' . $pid, 'style'=>'primary']]
    ]], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if(preg_match('/^resetTestPlanUsageConfirm(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($m[1]);
    $result = function_exists('v2raystore_resetTestAccountUsageForPlan') ? v2raystore_resetTestAccountUsageForPlan($pid) : ['ok'=>false];
    if(empty($result['ok'])){
        alert('ریست این اکانت تست انجام نشد. دوباره بررسی کن.', true);
        exit();
    }
    $plan = $result['plan'] ?? (function_exists('v2raystore_getTestPlanById') ? v2raystore_getTestPlanById($pid) : null);
    $title = $plan && function_exists('v2raystore_testPlanDisplayTitle') ? v2raystore_testPlanDisplayTitle($plan, true) : ('تست #' . $pid);
    $safeTitle = function_exists('v2raystore_h') ? v2raystore_h($title) : htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $msg = "✅ <b>ریست جداگانه انجام شد</b>\n\n" .
           "مورد ریست‌شده: <b>{$safeTitle}</b>\n" .
           "کاربران آزادشده: <b>" . intval($result['users'] ?? 0) . "</b>\n" .
           "رکوردهای حذف‌شده: <b>" . intval($result['deleted'] ?? 0) . "</b>\n\n" .
           "اکانت تست سرورها/اینباندهای دیگر دست‌نخورده باقی ماند.";
    editText($message_id, $msg, v2raystore_getTestPlanResetListKeys(), "HTML");
    exit();
}
if($data == "resetOneTestAccount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را که می‌خواهید سابقه اکانت تست او ریست شود ارسال کنید.", $cancelKey, "HTML");
    setUser("resetOneTestAccount");
    exit();
}
if($data == "setTestAccountLimit" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را ارسال کنید که می‌خواهید سقف اکانت تست او تغییر کند.", $cancelKey, "HTML");
    setUser("setTestAccountLimitUser");
    exit();
}
if($data == "removeTestAccountLimit" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را ارسال کنید که می‌خواهید سقف اختصاصی اکانت تست او حذف و به حالت پیش‌فرض بازگردد.", $cancelKey, "HTML");
    setUser("removeTestAccountLimit");
    exit();
}
if($data == "testAccountLimitList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getTestAccountLimitsListText(), json_encode(['inline_keyboard'=>[
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'testAccountManagement', 'style'=>'primary']]
    ]], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}
if($data == "setTestAccountRemarkPrefix" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $currentPrefix = function_exists('v2raystore_getTestRemarkPrefix') ? v2raystore_getTestRemarkPrefix() : 'test';
    $currentPreview = $currentPrefix === '' ? 'بدون پیشوند' : htmlspecialchars($currentPrefix, ENT_QUOTES, 'UTF-8');
    sendMessage("🏷 <b>تنظیم ریمارک اکانت تست</b>

پیشوند فعلی: <code>{$currentPreview}</code>

متن جدید را ارسال کنید. این متن ابتدای نام کانفیگ تست قرار می‌گیرد.
مثال: <code>test</code> → <code>test-Germany-12345</code>

برای برگشت به پیش‌فرض: <code>/default</code>
برای حذف پیشوند: <code>/empty</code>", $cancelKey, "HTML");
    setUser("setTestAccountRemarkPrefix");
    exit();
}
if(($userInfo['step'] ?? '') == 'setTestAccountRemarkPrefix' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $rawPrefix = trim((string)$text);
    if($rawPrefix === '/default'){
        $prefix = 'test';
    }elseif($rawPrefix === '/empty' || $rawPrefix === '/none'){
        $prefix = '__empty__';
    }else{
        $prefix = function_exists('v2raystore_cleanTestRemarkPrefix') ? v2raystore_cleanTestRemarkPrefix($rawPrefix) : trim($rawPrefix);
        if($prefix === ''){
            sendMessage("❌ ریمارک واردشده معتبر نیست. فقط حروف، عدد، خط تیره، آندرلاین و نقطه مجاز است.
برای پیش‌فرض <code>/default</code> را بفرست.", $cancelKey, "HTML");
            exit();
        }
    }
    if(function_exists('farid_setSettingValue')) farid_setSettingValue('TEST_ACCOUNT_REMARK_PREFIX', $prefix);
    else{
        $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `setting` WHERE `type` = 'TEST_ACCOUNT_REMARK_PREFIX' LIMIT 1");
        $stmt->execute();
        $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if($cnt > 0){
            $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'TEST_ACCOUNT_REMARK_PREFIX'");
            $stmt->bind_param("s", $prefix);
        }else{
            $type = 'TEST_ACCOUNT_REMARK_PREFIX';
            $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, ?)");
            $stmt->bind_param("ss", $prefix, $type);
        }
        $stmt->execute();
        $stmt->close();
    }
    setUser();
    $savedPreviewValue = ($prefix === '__empty__') ? '' : $prefix;
    $savedPreview = $savedPreviewValue === '' ? 'بدون پیشوند' : htmlspecialchars($savedPreviewValue, ENT_QUOTES, 'UTF-8');
    sendMessage("✅ ریمارک اکانت تست تنظیم شد: <code>{$savedPreview}</code>", $removeKeyboard, "HTML");
    sendMessage("🧪 مدیریت اکانت تست", v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}
if(($userInfo['step'] ?? '') == 'setTestAccountLimitValue' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $limitText = trim((string)$text);
    if(!preg_match('/^\d+$/', $limitText)){
        sendMessage("❌ لطفاً فقط عدد وارد کنید. عدد <code>0</code> یعنی نامحدود.", $cancelKey, "HTML");
        exit();
    }
    $limit = intval($limitText);
    $targetId = intval($userInfo['temp'] ?? 0);
    if($targetId <= 0){
        setUser('', 'temp');
        setUser();
        sendMessage("❌ آیدی کاربر در حافظه مرحله پیدا نشد. لطفاً دوباره از منوی مدیریت اکانت تست اقدام کنید.", v2raystore_getTestAccountManageKeys(), "HTML");
        exit();
    }
    v2raystore_ensureBasicUserRecord($targetId);
    if($limit === 0){
        $stmt = $connection->prepare("UPDATE `users` SET `test_account_exempt` = 1, `test_account_limit` = NULL WHERE `userid` = ?");
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $stmt->close();
        $resultMsg = "✅ سقف اکانت تست کاربر <code>{$targetId}</code> روی حالت نامحدود تنظیم شد.";
    }else{
        $stmt = $connection->prepare("UPDATE `users` SET `test_account_exempt` = 0, `test_account_limit` = ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $limit, $targetId);
        $stmt->execute();
        $stmt->close();
        $resultMsg = "✅ سقف اکانت تست کاربر <code>{$targetId}</code> روی <b>{$limit}</b> بار تنظیم شد.";
    }
    setUser('', 'temp');
    setUser();
    sendMessage($resultMsg, $removeKeyboard, "HTML");
    sendMessage("🧪 مدیریت اکانت تست", v2raystore_getTestAccountManageKeys(), "HTML");
    exit();
}
if(in_array($userInfo['step'] ?? '', ['resetOneTestAccount','setTestAccountLimitUser','removeTestAccountLimit'], true) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = trim((string)$text);
    if(!preg_match('/^\d{5,20}$/', $targetId)){
        sendMessage("❌ لطفاً یک آیدی عددی معتبر ارسال کنید.", $cancelKey, "HTML");
        exit();
    }
    $targetId = intval($targetId);
    v2raystore_ensureBasicUserRecord($targetId);
    if($userInfo['step'] == 'resetOneTestAccount'){
        $stmt = $connection->prepare("UPDATE `users` SET `freetrial` = NULL, `test_account_count` = 0 WHERE `userid` = ?");
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $stmt->close();
        $stmt = $connection->prepare("DELETE FROM `test_account_usage` WHERE `userid` = ?");
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $stmt->close();
        setUser();
        sendMessage("✅ سابقه اکانت تست کاربر <code>{$targetId}</code> با موفقیت ریست شد.", $removeKeyboard, "HTML");
        sendMessage("🧪 مدیریت اکانت تست", v2raystore_getTestAccountManageKeys(), "HTML");
    }elseif($userInfo['step'] == 'setTestAccountLimitUser'){
        setUser((string)$targetId, 'temp');
        setUser('setTestAccountLimitValue');
        sendMessage("لطفاً سقف دریافت اکانت تست برای کاربر <code>{$targetId}</code> را ارسال کنید.\n\nمثلاً عدد <code>2</code> یعنی این کاربر دو بار می‌تواند اکانت تست دریافت کند.\nعدد <code>0</code> یعنی نامحدود.", $cancelKey, "HTML");
    }elseif($userInfo['step'] == 'removeTestAccountLimit'){
        $stmt = $connection->prepare("UPDATE `users` SET `test_account_exempt` = 0, `test_account_limit` = NULL WHERE `userid` = ?");
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $stmt->close();
        setUser();
        sendMessage("✅ سقف اختصاصی اکانت تست کاربر <code>{$targetId}</code> حذف شد و محدودیت او به حالت پیش‌فرض بازگشت.", $removeKeyboard, "HTML");
        sendMessage("🧪 مدیریت اکانت تست", v2raystore_getTestAccountManageKeys(), "HTML");
    }
    exit();
}

if(preg_match('/^requestCartToCartCard(.+)/', $data, $match)){
    $paymentKeys = v2raystore_getPaymentKeys();
    $account = function_exists('v2raystore_getCartToCartAccountForUser') ? v2raystore_getCartToCartAccountForUser($from_id, $paymentKeys) : ['is_second'=>false];
    v2raystore_markUserCardVersion($from_id, $paymentKeys);
    $url = v2raystore_cardContactUrl($paymentKeys);
    $contact = v2raystore_cardContactDisplay($paymentKeys);
    $accountTitle = function_exists('v2raystore_cartToCartAccountTitle') ? v2raystore_cartToCartAccountTitle($account) : 'خرید';
    $requestText = !empty($account['is_second']) ? 'شماره کارت خرید دوم جهت واریز' : 'شماره کارت جهت واریز';
    $msg = "💳 <b>دریافت شماره کارت</b>

نوع پرداخت: <b>$accountTitle</b>

روی دکمه زیر بزنید و به ادمین $contact پیام بدهید.
متن پیام:
<code>$requestText</code>

بعد از دریافت شماره کارت و واریز، به همین ربات برگردید و تصویر رسید را ارسال کنید.";
    $keys = v2raystore_inlineKeyboardJson([
        [['text'=>'📩 پیام به ادمین برای شماره کارت', 'url'=>$url, 'style'=>'success']],
        [['text'=>'🔙 برگشت به منوی اصلی', 'callback_data'=>'mainMenu', 'style'=>'primary']]
    ]);
    editText($message_id, $msg, $keys, 'HTML');
    exit();
}
if($data == 'markCartToCartCardChanged' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_markCardInfoChanged();
    editText($message_id, "✅ وضعیت شماره کارت تغییر کرد.

از این به بعد کاربران برای پرداخت کارت‌به‌کارت باید دوباره شماره کارت جدید را از ادمین دریافت کنند.", getGateWaysKeys(), 'HTML');
    exit();
}
v2raystore_handleNewMemberLock();
if(strstr($text, "/start ")){
    $inviter = str_replace("/start ", "", $text);
    if($inviter < 0) exit();
    if($uinfo->num_rows == 0 && $inviter != $from_id){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $inviter);
        $stmt->execute();
        $inviterInfo = $stmt->get_result();
        $stmt->close();
        
        if($inviterInfo->num_rows > 0){
            $first_name = !empty($first_name)?$first_name:" ";
            $username = !empty($username)?$username:" ";
            if($uinfo->num_rows == 0){
                $sql = "INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `refered_by`)
                                    VALUES (?,?,?, 0,0,?,?)";
                $stmt = $connection->prepare($sql);
                $time = time();
                $stmt->bind_param("issii", $from_id, $first_name, $username, $time, $inviter);
                $stmt->execute();
                $stmt->close();
            }else{
                $refcode = time();
                $sql = "UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?";
                $stmt = $connection->prepare($sql);
                $stmt->bind_param("si", $inviter, $from_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $uinfo = $stmt->get_result();
            $userInfo = $uinfo->fetch_assoc();
            $stmt->close();
            
            setUser("referedBy" . $inviter);
            $userInfo['step'] = "referedBy" . $inviter;
            sendMessage($mainValues['invited_user_joined_message'],null,null, $inviter);
        }
    }
    
    $text = "/start";
}
if($userInfo['phone'] == null && $from_id != $admin && $userInfo['isAdmin'] != true && $botState['requirePhone'] == "on"){
    if(isset($update->message->contact)){
        $contact = $update->message->contact;
        $phone_number = $contact->phone_number;
        $phone_id = $contact->user_id;
        if($phone_id != $from_id){
            sendMessage($mainValues['please_select_from_below_buttons']);
            exit();
        }else{
            if(!preg_match('/^\+98(\d+)/',$phone_number) && !preg_match('/^98(\d+)/',$phone_number) && !preg_match('/^0098(\d+)/',$phone_number) && $botState['requireIranPhone'] == 'on'){
                sendMessage($mainValues['use_iranian_number_only']);
                exit();
            }
            setUser($phone_number, 'phone');
            
            sendMessage($mainValues['phone_confirmed'],$removeKeyboard);
            $text = "/start";
            
            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $uinfo = $stmt->get_result();
            $userInfo = $uinfo->fetch_assoc();
            $stmt->close();
        }
    }else{
        sendMessage($mainValues['send_your_phone_number'], json_encode([
			'keyboard' => [[[
					'text' => $buttonValues['send_phone_number'],
					'request_contact' => true,
				]]],
			'resize_keyboard' => true
		]));
		exit();
    }
}
if(preg_match('/^\/([Ss]tart)/', $text) or $text == $buttonValues['back_to_main'] or $data == 'mainMenu') {
    setUser();
    setUser("", "temp"); 
    $startMessageText = function_exists('v2raystore_getMainText') ? v2raystore_getMainText('start_message', $mainValues['start_message'] ?? '') : ($mainValues['start_message'] ?? '');
    if(isset($data) and $data == "mainMenu"){
        $res = editText($message_id, $startMessageText, getMainKeys(), null);
        if(!$res->ok){
            sendMessage($startMessageText, getMainKeys(), null);
        }
    }else{
        if($from_id != $admin && empty($userInfo['first_start'])){
            setUser('sent','first_start');
            $keys = json_encode(['inline_keyboard'=>[
                [['text'=>$buttonValues['send_message_to_user'],'callback_data'=>'sendMessageToUser' . $from_id]]
            ]]);
    
            sendMessage(str_replace(["FULLNAME", "USERNAME", "USERID"], ["<a href='tg://user?id=$from_id'>$first_name</a>", $username, $from_id], $mainValues['new_member_joined'])
                ,$keys, "html",$admin);
        }
        sendMessage($startMessageText, getMainKeys(), null);
    }
}
if(preg_match('/^sendMessageToUser(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    editText($message_id,'لطفاً متن پیام مورد نظر را ارسال کنید.');
    setUser($data);
}
if(preg_match('/^sendMessageToUser(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    sendMessage($text,null,null,$match[1]);
    sendMessage("✅ پیام شما برای کاربر ارسال شد.",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
    setUser();
}

if(preg_match('/^admin(Main|Reports|Configs|Sales|Users|Messages|Settings)Menu$/', $data ?? '', $adminMenuMatch) && ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)){
    $adminMenuMap = [
        'Main' => ['title' => $mainValues['reached_main_menu'] ?? 'مدیریت ربات', 'keys' => 'getAdminKeysPlus'],
        'Reports' => ['title' => '📊 گزارش‌ها و جستجو', 'keys' => 'getAdminReportsMenuKeys'],
        'Configs' => ['title' => '🧾 کانفیگ‌ها و سرویس‌ها', 'keys' => 'getAdminConfigsMenuKeys'],
        'Sales' => ['title' => '🖥 سرورها، پلن‌ها و فروش', 'keys' => 'getAdminSalesMenuKeys'],
        'Users' => ['title' => '👥 کاربران، نماینده‌ها و دسترسی‌ها', 'keys' => 'getAdminUsersMenuKeys'],
        'Messages' => ['title' => '📨 پیام‌ها، پشتیبانی و محتوا', 'keys' => 'getAdminMessagesMenuKeys'],
        'Settings' => ['title' => '⚙️ تنظیمات، ظاهر و آموزش‌ها', 'keys' => 'getAdminSettingsMenuKeys'],
    ];
    $menuName = $adminMenuMatch[1];
    $menuInfo = $adminMenuMap[$menuName] ?? $adminMenuMap['Main'];
    $keysFn = $menuInfo['keys'];
    $textTitle = "<b>" . $menuInfo['title'] . "</b>

";
    if($menuName == 'Main'){
        $textTitle = $menuInfo['title'];
    }else{
        $textTitle .= "گزینه‌های این بخش مرتب شده‌اند تا منوی مدیریت خلوت‌تر و سریع‌تر باشد.";
    }
    editText($message_id, $textTitle, function_exists($keysFn) ? $keysFn() : getAdminKeysPlus(), 'HTML');
    exit();
}
if($data=='botReports' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "آمار ربات در این لحظه",getBotReportKeys());
}
if($data=="adminsList" && $from_id == $admin){
    editText($message_id, "لیست ادمین ها",getAdminsKeys());
}
if($data == 'adminServerSalesAdminsList' && intval($from_id) === intval($admin)){
    if(function_exists('v2raystore_getAdminServerSalesAdminsText') && function_exists('v2raystore_getAdminServerSalesAdminsKeys')){
        editText($message_id, v2raystore_getAdminServerSalesAdminsText(), v2raystore_getAdminServerSalesAdminsKeys(), 'HTML');
    }else{
        alert('فایل config.php جدید جایگزین نشده است.', true);
    }
}

if(preg_match('/^adminServerSales(\d+)$/', $data ?? '', $match) && intval($from_id) === intval($admin)){
    $targetAdminId = intval($match[1]);
    editText($message_id, v2raystore_getAdminServerSalesAccessText($targetAdminId), v2raystore_getAdminServerSalesAccessKeys($targetAdminId), 'HTML');
    exit();
}
if(preg_match('/^toggleAdminServerSaleAccess(\d+)_(\d+)$/', $data ?? '', $match) && intval($from_id) === intval($admin)){
    $targetAdminId = intval($match[1]);
    $serverId = intval($match[2]);
    if($targetAdminId === intval($admin)){
        alert('ادمین اصلی همیشه به همه سرورها دسترسی دارد.', true);
        exit();
    }
    $ok = function_exists('v2raystore_toggleAdminServerSaleAccess') ? v2raystore_toggleAdminServerSaleAccess($targetAdminId, $serverId) : false;
    if(!$ok){
        alert('تغییر دسترسی انجام نشد.', true);
        exit();
    }
    editText($message_id, v2raystore_getAdminServerSalesAccessText($targetAdminId), v2raystore_getAdminServerSalesAccessKeys($targetAdminId), 'HTML');
    alert('دسترسی فروش این سرور برای ادمین بروزرسانی شد.');
    exit();
}
if(preg_match('/^agentServerSalesAccess(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $agentExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$agentExists){
        alert('نماینده پیدا نشد.', true);
        exit();
    }
    editText(
        $message_id,
        v2raystore_getAdminServerSalesAccessText($agentId, 'نماینده'),
        v2raystore_getAdminServerSalesAccessKeys($agentId, 'agentPercentDetails' . $agentId, 'toggleAgentServerSaleAccess'),
        'HTML'
    );
    exit();
}
if(preg_match('/^toggleAgentServerSaleAccess(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $agentExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$agentExists){
        alert('نماینده پیدا نشد.', true);
        exit();
    }
    $ok = function_exists('v2raystore_toggleAdminServerSaleAccess') ? v2raystore_toggleAdminServerSaleAccess($agentId, $serverId) : false;
    if(!$ok){
        alert('تغییر دسترسی انجام نشد.', true);
        exit();
    }
    editText(
        $message_id,
        v2raystore_getAdminServerSalesAccessText($agentId, 'نماینده'),
        v2raystore_getAdminServerSalesAccessKeys($agentId, 'agentPercentDetails' . $agentId, 'toggleAgentServerSaleAccess'),
        'HTML'
    );
    alert('دسترسی فروش این سرور برای نماینده بروزرسانی شد.');
    exit();
}
if(preg_match('/^toggleAdminReceipt(\d+)$/',$data,$match) && intval($from_id) === intval($admin)){
    $targetAdminId = intval($match[1]);
    if($targetAdminId === intval($admin)){
        alert("ادمین اصلی همیشه فیش سفارش را دریافت می‌کند و قابل خاموش شدن نیست.", true);
        exit();
    }

    $stmt = $connection->prepare("UPDATE `users` SET `receive_order_receipts` = IF(COALESCE(`receive_order_receipts`, 0) = 1, 0, 1) WHERE `userid` = ? AND `isAdmin` = 1");
    $stmt->bind_param("i", $targetAdminId);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "لیست ادمین ها", getAdminsKeys());
    alert("تنظیم دریافت فیش این ادمین بروزرسانی شد.");
    exit();
}
if(preg_match('/^delAdmin(\d+)/',$data,$match) && $from_id === $admin){
    $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = false WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id, "لیست ادمین ها",getAdminsKeys());

}
if($data=="addNewAdmin" && $from_id === $admin){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را که می‌خواهید ادمین شود ارسال کنید.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewAdmin" && $from_id === $admin && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = true, `receive_order_receipts` = 0 WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅ کاربر مورد نظر با موفقیت ادمین شد.",$removeKeyboard);
        setUser();
        
        sendMessage("لیست ادمین ها",getAdminsKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data == "newMemberAccessMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $state = v2raystore_getBotStatesArray();
    $mode = v2raystore_getNewMemberAccessMode($state);
    $since = intval($state['newMemberAccessStartedAt'] ?? 0);
    $sinceText = $since > 0 ? jdate("Y/m/d H:i", $since) : 'ثبت نشده';
    $msg = "🔐 <b>مدیریت دسترسی اعضای جدید</b>\n\n" .
           "وضعیت فعلی: <b>" . v2raystore_newMemberAccessModeTitle($mode) . "</b>\n" .
           "زمان اعمال وضعیت: <code>$sinceText</code>\n\n" .
           "• آزاد برای همه: همه می‌توانند وارد ربات شوند.\n" .
           "• فقط کاربران قبلی: فقط کسانی که قبل از فعال‌سازی این حالت داخل دیتابیس ربات بوده‌اند.\n" .
           "• فقط خریداران قبلی: فقط کسانی که حداقل یک سفارش/پرداخت قبلی دارند.\n" .
           "• تایید دستی با معرف: کاربر جدید باید آیدی عددی معرف را بفرستد و ادمین تایید کند.";
    editText($message_id, $msg, v2raystore_getNewMemberAccessMenuKeys(), 'HTML');
    exit();
}
if(preg_match('/^setNewMemberAccessMode_(open|existing|buyers|approval)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_setNewMemberAccessMode($match[1]);
    $msg = "✅ وضعیت دسترسی اعضای جدید تغییر کرد.\n\nوضعیت جدید: <b>" . v2raystore_newMemberAccessModeTitle($match[1]) . "</b>";
    editText($message_id, $msg, v2raystore_getNewMemberAccessMenuKeys(), 'HTML');
    exit();
}
if($data == "generateBuyersAccessCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $code = v2raystore_generateBuyersAccessCode();
    $msg = "✅ کد ورود جدید با موفقیت ساخته شد.

🎟 کد: <code>$code</code>

این کد را به کاربرانی بدهید که می‌خواهید در حالت «فقط خریداران قبلی» بتوانند دسترسی بگیرند.";
    editText($message_id, $msg, v2raystore_getNewMemberAccessMenuKeys(), 'HTML');
    exit();
}
if($data == "clearBuyersAccessCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_setBuyersAccessCode('');
    editText($message_id, "✅ کد ورود خریداران با موفقیت حذف شد.", v2raystore_getNewMemberAccessMenuKeys(), 'HTML');
    exit();
}
if($data == "setBuyersAccessCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("✏️ لطفاً کد ورود جدید را ارسال کنید.

فقط حروف انگلیسی، عدد، خط تیره و زیرخط ذخیره می‌شود.
نمونه: <code>VIP-2026</code>", $cancelKey, 'HTML');
    setUser('setBuyersAccessCode');
    exit();
}
if(($userInfo['step'] ?? '') == "setBuyersAccessCode" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $code = v2raystore_setBuyersAccessCode($text);
    setUser();
    if($code === ''){
        sendMessage("❌ کد ارسال‌شده معتبر نیست. فقط حروف انگلیسی، عدد، خط تیره و زیرخط قابل ذخیره است.", $removeKeyboard, 'HTML');
    }else{
        sendMessage("✅ کد ورود با موفقیت ذخیره شد: <code>$code</code>", $removeKeyboard, 'HTML');
    }
    sendMessage("🔐 مدیریت دسترسی اعضای جدید", v2raystore_getNewMemberAccessMenuKeys(), 'HTML');
    exit();
}
if($data == "joinExemptMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $msg = "🚪 <b>معافیت جوین اجباری کانال</b>\n\n" .
           "از این بخش می‌توانید یک کاربر را از بررسی عضویت اجباری کانال معاف کنید.\n" .
           "کاربر معاف حتی اگر عضو کانال قفل نباشد، می‌تواند از ربات استفاده کند.";
    editText($message_id, $msg, v2raystore_getJoinExemptMenuKeys(), 'HTML');
    exit();
}
if($data == "addJoinExemptUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("➕ آیدی عددی کاربری که می‌خواهید از جوین اجباری کانال معاف شود را ارسال کنید.", $cancelKey, 'HTML');
    setUser('addJoinExemptUser');
    exit();
}
if($data == "removeJoinExemptUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("➖ آیدی عددی کاربری که می‌خواهید معافیت جوین اجباری‌اش حذف شود را ارسال کنید.", $cancelKey, 'HTML');
    setUser('removeJoinExemptUser');
    exit();
}
if($data == "joinExemptList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getJoinExemptListText(), v2raystore_getJoinExemptMenuKeys(), 'HTML');
    exit();
}
if(in_array($userInfo['step'] ?? '', ['addJoinExemptUser','removeJoinExemptUser'], true) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = trim((string)$text);
    if(!preg_match('/^\d+$/', $targetId)){
        sendMessage("❌ فقط آیدی عددی معتبر ارسال کنید.", $cancelKey, 'HTML');
        exit();
    }
    $enableExempt = ($userInfo['step'] == 'addJoinExemptUser');
    $ok = v2raystore_setUserJoinExempt((int)$targetId, $enableExempt);
    setUser();
    if($ok){
        $msg = $enableExempt ?
            "✅ کاربر <code>$targetId</code> از جوین اجباری کانال معاف شد." :
            "✅ معافیت جوین اجباری کاربر <code>$targetId</code> حذف شد.";
        sendMessage($msg, $removeKeyboard, 'HTML');
        sendMessage("🚪 معافیت جوین اجباری کانال", v2raystore_getJoinExemptMenuKeys(), 'HTML');
    }else{
        sendMessage("❌ ذخیره تغییرات انجام نشد. دوباره تلاش کنید.", $removeKeyboard, 'HTML');
        sendMessage("🚪 معافیت جوین اجباری کانال", v2raystore_getJoinExemptMenuKeys(), 'HTML');
    }
    exit();
}

if($data == "userButtonSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $msg = "🎛 <b>تنظیمات دکمه‌های کاربر</b>

از این بخش می‌توانید دکمه‌های صفحه اصلی کاربر را مخفی/فعال کنید یا جای آن‌ها را تغییر دهید.
در منوی کاربر، دکمه‌های فعال با ترتیب و ردیف‌بندی انتخابی شما نمایش داده می‌شوند؛ هر ردیف حداکثر ۲ دکمه دارد، ولی می‌توانید یک ردیف را تک‌دکمه‌ای کنید.
دکمه مدیریت ربات برای ادمین‌ها همیشه نمایش داده می‌شود.";
    editText($message_id, $msg, v2raystore_getUserButtonSettingsKeys(), 'HTML');
    exit();
}
if($data == "userButtonLayoutSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getUserButtonOrderText(), v2raystore_getUserButtonOrderSettingsKeys(), 'HTML');
    exit();
}
if(preg_match('/^moveUserButtonOrder_([A-Za-z0-9_]+)_(up|down)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ok = v2raystore_moveUserButtonOrder($match[1], $match[2]);
    if(!$ok) alert('امکان جابه‌جایی بیشتر وجود ندارد.');
    editText($message_id, v2raystore_getUserButtonOrderText(), v2raystore_getUserButtonOrderSettingsKeys(), 'HTML');
    exit();
}
if(preg_match('/^toggleUserButtonRowBreak_([A-Za-z0-9_]+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_toggleUserButtonRowBreak($match[1]);
    editText($message_id, v2raystore_getUserButtonOrderText(), v2raystore_getUserButtonOrderSettingsKeys(), 'HTML');
    exit();
}
if($data == "resetUserButtonRows" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_resetUserButtonRowBreaks();
    editText($message_id, v2raystore_getUserButtonOrderText(), v2raystore_getUserButtonOrderSettingsKeys(), 'HTML');
    exit();
}
if($data == "resetUserButtonOrder" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_resetUserButtonOrder();
    editText($message_id, v2raystore_getUserButtonOrderText(), v2raystore_getUserButtonOrderSettingsKeys(), 'HTML');
    exit();
}
if(preg_match('/^toggleUserButtonVisibility_([A-Za-z0-9_]+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $key = $match[1];
    $current = v2raystore_userButtonVisible($key);
    v2raystore_setUserButtonVisible($key, !$current);
    editText($message_id, "🎛 تنظیمات دکمه‌های کاربر", v2raystore_getUserButtonSettingsKeys(), 'HTML');
    exit();
}
if(preg_match('/^setAllUserButtons_(on|off)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_setAllUserButtonsVisible($match[1] == 'on');
    editText($message_id, "🎛 تنظیمات دکمه‌های کاربر", v2raystore_getUserButtonSettingsKeys(), 'HTML');
    exit();
}

if(($data=="botSettings" or preg_match("/^changeBot(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="botSettings"){
        $defaultOnBotKeys = ['smartRenewState','configTutorialButtonsState','configDiagnosticsState'];
        $currentBotValue = $botState[$match[1]] ?? (in_array($match[1], $defaultOnBotKeys, true) ? 'on' : 'off');
        $newValue = $currentBotValue=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}


if($data == "renewSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getRenewSettingsMenuText(), v2raystore_getRenewSettingsMenuKeys(), "HTML");
    exit();
}
if(preg_match('/^setRenewExtendMode_(reset|add)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setSettings('renewExtendMode', $match[1]);
    editText($message_id, v2raystore_getRenewSettingsMenuText(), v2raystore_getRenewSettingsMenuKeys(), "HTML");
    exit();
}

if($data == "switchLocationSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_getSwitchSettingsMenuText(), v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}
if($data == "toggleSwitchCostMode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $settings = v2raystore_getServerSwitchSettings();
    $modes = ['auto', 'percent', 'manual'];
    $currentIndex = array_search($settings['mode'], $modes, true);
    if($currentIndex === false) $currentIndex = 0;
    $settings['mode'] = $modes[($currentIndex + 1) % count($modes)];
    v2raystore_saveServerSwitchSettings($settings);
    editText($message_id, v2raystore_getSwitchSettingsMenuText(), v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}
if(in_array($data, ['editSwitchDefaultGb','editSwitchPercent','editSwitchMinGb','editSwitchDailyLimit'], true) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    if($data == 'editSwitchDefaultGb'){
        sendMessage("🔻 حجم ثابت کسر برای تغییر سرور را به گیگابایت وارد کنید.\nمثال: <code>1.5</code>", $cancelKey, "HTML");
    }elseif($data == 'editSwitchPercent'){
        sendMessage("📊 درصد عمومی کسر حجم در حالت درصدی را وارد کنید.\nمثال: اگر <code>15</code> وارد کنید، از سرویس ۳۰ گیگ مقدار ۴.۵ گیگ و از سرویس ۵ گیگ مقدار ۰.۷۵ گیگ کم می‌شود.", $cancelKey, "HTML");
    }elseif($data == 'editSwitchMinGb'){
        sendMessage("🔹 حداقل حجم کسر در حالت خودکار/درصدی را به گیگابایت وارد کنید.\nمثال: <code>0.5</code>", $cancelKey, "HTML");
    }else{
        sendMessage("🕘 تعداد دفعات مجاز تغییر سرور/لوکیشن برای هر کانفیگ در هر روز را وارد کنید.\nمثال: <code>1</code> یعنی روزی یک‌بار، <code>2</code> یعنی روزی دو بار.\nبرای نامحدود کردن کاربر عادی عدد <code>0</code> را وارد کنید.", $cancelKey, "HTML");
    }
    setUser($data);
    exit();
}
if(in_array($userInfo['step'] ?? '', ['editSwitchDefaultGb','editSwitchPercent','editSwitchMinGb','editSwitchDailyLimit'], true) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $settings = v2raystore_getServerSwitchSettings();
    if($userInfo['step'] == 'editSwitchDailyLimit'){
        if(!ctype_digit(trim((string)$text)) || intval($text) < 0){
            sendMessage("لطفاً یک عدد صحیح صفر یا بزرگ‌تر وارد کنید.", $cancelKey);
            exit();
        }
        $settings['daily_limit'] = intval($text);
    }else{
        if(!is_numeric($text) || floatval($text) < 0){
            sendMessage("لطفاً مقدار را فقط به عدد وارد کنید. مثال: 1.5", $cancelKey);
            exit();
        }
        if($userInfo['step'] == 'editSwitchPercent' && floatval($text) > 100){
            sendMessage("درصد نمی‌تواند بیشتر از 100 باشد. مثال: 15", $cancelKey);
            exit();
        }
        if($userInfo['step'] == 'editSwitchDefaultGb') $settings['default_gb'] = floatval($text);
        elseif($userInfo['step'] == 'editSwitchPercent') $settings['percent'] = floatval($text);
        else $settings['min_gb'] = floatval($text);
    }
    v2raystore_saveServerSwitchSettings($settings);
    setUser();
    sendMessage("✅ تنظیمات تغییر سرور ذخیره شد.", $removeKeyboard);
    sendMessage(v2raystore_getSwitchSettingsMenuText(), v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}
if($data == 'selectSwitchPairFrom' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🌎 سرور مبدا را برای تنظیم حجم ثابت انتخاب کنید:", v2raystore_getSwitchPairFromKeys(false, 'gb'), "HTML");
    exit();
}
if($data == 'selectSwitchPairPercentFrom' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🌎 سرور مبدا را برای تنظیم درصد اختصاصی انتخاب کنید:", v2raystore_getSwitchPairFromKeys(false, 'percent'), "HTML");
    exit();
}
if($data == 'selectSwitchPairDeleteFrom' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🗑 سرور مبدا مسیر اختصاصی را انتخاب کنید:", v2raystore_getSwitchPairFromKeys(true), "HTML");
    exit();
}
if(preg_match('/^switchPairFrom(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🌎 سرور مقصد را انتخاب کنید:", v2raystore_getSwitchPairToKeys($match[1], false, 'gb'), "HTML");
    exit();
}
if(preg_match('/^switchPairPercentFrom(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🌎 سرور مقصد را انتخاب کنید:", v2raystore_getSwitchPairToKeys($match[1], false, 'percent'), "HTML");
    exit();
}
if(preg_match('/^switchPairDeleteFrom(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "🗑 سرور مقصد مسیری که می‌خواهید حذف شود را انتخاب کنید:", v2raystore_getSwitchPairToKeys($match[1], true), "HTML");
    exit();
}
if(preg_match('/^switchPairTo(\d+)_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $fromTitle = v2raystore_switchGetServerTitle($match[1]);
    $toTitle = v2raystore_switchGetServerTitle($match[2]);
    sendMessage("🔻 حجم کسر اختصاصی مسیر زیر را به گیگابایت وارد کنید:\n\n<b>" . htmlspecialchars($fromTitle, ENT_QUOTES, 'UTF-8') . " ➜ " . htmlspecialchars($toTitle, ENT_QUOTES, 'UTF-8') . "</b>\n\nمثال: <code>2</code>", $cancelKey, "HTML");
    setUser('editSwitchPairCost' . intval($match[1]) . '_' . intval($match[2]));
    exit();
}
if(preg_match('/^switchPairPercentTo(\d+)_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $fromTitle = v2raystore_switchGetServerTitle($match[1]);
    $toTitle = v2raystore_switchGetServerTitle($match[2]);
    sendMessage("📊 درصد کسر اختصاصی مسیر زیر را وارد کنید:\n\n<b>" . htmlspecialchars($fromTitle, ENT_QUOTES, 'UTF-8') . " ➜ " . htmlspecialchars($toTitle, ENT_QUOTES, 'UTF-8') . "</b>\n\nمثال: <code>15</code> یعنی ۱۵٪ از حجم باقی‌مانده کم شود.\nبرای سرویس ۳۰ گیگ می‌شود ۴.۵ گیگ و برای سرویس ۵ گیگ می‌شود ۰.۷۵ گیگ.\n\nمسیر برگشتی خودکار معکوس حساب می‌شود؛ مثلاً اگر این مسیر را <code>50</code> بگذاری، برگشت از مقصد به مبدا حجم فعلی را دو برابر می‌کند.", $cancelKey, "HTML");
    setUser('editSwitchPairPercent' . intval($match[1]) . '_' . intval($match[2]));
    exit();
}
if(preg_match('/^switchPairDeleteTo(\d+)_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_deleteSwitchPairCostGb($match[1], $match[2]);
    editText($message_id, "✅ تنظیم اختصاصی این مسیر حذف شد.\nاز این بعد برای این مسیر، تنظیمات عمومی اعمال می‌شود.", v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}
if(preg_match('/^editSwitchPairCost(\d+)_(\d+)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text) || floatval($text) < 0){
        sendMessage("لطفاً مقدار حجم را فقط به عدد وارد کنید. مثال: 2.5", $cancelKey);
        exit();
    }
    v2raystore_setSwitchPairCostGb($match[1], $match[2], floatval($text));
    setUser();
    sendMessage("✅ حجم ثابت اختصاصی مسیر ذخیره شد.", $removeKeyboard);
    sendMessage(v2raystore_getSwitchSettingsMenuText(), v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}
if(preg_match('/^editSwitchPairPercent(\d+)_(\d+)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text) || floatval($text) < 0 || floatval($text) > 100){
        sendMessage("لطفاً درصد را بین 0 تا 100 وارد کنید. مثال: 15", $cancelKey);
        exit();
    }
    v2raystore_setSwitchPairPercent($match[1], $match[2], floatval($text));
    setUser();
    sendMessage("✅ درصد اختصاصی مسیر ذخیره شد.", $removeKeyboard);
    sendMessage(v2raystore_getSwitchSettingsMenuText(), v2raystore_getSwitchSettingsMenuKeys(), "HTML");
    exit();
}

if($data=="adminTextSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $currentWelcome = function_exists('v2raystore_getMainText') ? v2raystore_getMainText('start_message', $mainValues['start_message'] ?? '') : ($mainValues['start_message'] ?? '');
    $previewRaw = trim((string)$currentWelcome);
    if(function_exists('mb_strlen') && mb_strlen($previewRaw, 'UTF-8') > 1200) $previewRaw = mb_substr($previewRaw, 0, 1200, 'UTF-8') . "\n...";
    $preview = htmlspecialchars($previewRaw, ENT_QUOTES, 'UTF-8');

    $currentPurchaseRules = function_exists('v2raystore_getMainText') ? v2raystore_getMainText('purchase_rules_text', '') : ($mainValues['purchase_rules_text'] ?? '');
    $rulesPreviewRaw = trim((string)$currentPurchaseRules);
    if($rulesPreviewRaw === '') $rulesPreviewRaw = 'غیرفعال / متنی ثبت نشده است.';
    elseif(function_exists('mb_strlen') && mb_strlen($rulesPreviewRaw, 'UTF-8') > 1200) $rulesPreviewRaw = mb_substr($rulesPreviewRaw, 0, 1200, 'UTF-8') . "\n...";
    $rulesPreview = htmlspecialchars($rulesPreviewRaw, ENT_QUOTES, 'UTF-8');

    $msg = "📝 <b>تنظیم متن‌ها</b>\n\n" .
           "اینجا هم متن خوش‌آمدگویی صفحه اصلی و هم متن قوانین/آخرین اخبار قبل از خرید تنظیم می‌شود.\n" .
           "مسیر: <b>مدیریت ربات ← تنظیمات ربات ← متن‌ها / قوانین خرید</b>\n\n" .
           "📌 <b>متن خوش‌آمد فعلی:</b>\n<pre>" . $preview . "</pre>\n\n" .
           "📢 <b>قوانین/آخرین اخبار خرید:</b>\n<pre>" . $rulesPreview . "</pre>";
    editText($message_id, $msg, farid_textSettingsKeyboard(), "HTML");
    exit();
}
if($data=="editStartWelcomeText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("📝 متن جدید خوش‌آمدگویی صفحه اصلی را ارسال کنید.\n\nمی‌توانید متن چندخطی، ایموجی و لینک ارسال کنید.\nبرای لغو، دکمه انصراف را بزنید.", $cancelKey, "HTML");
    setUser("editStartWelcomeText");
    exit();
}
if($userInfo['step'] == "editStartWelcomeText" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newStartText = trim((string)$text);
    if($newStartText === ''){
        sendMessage("⚠️ متن نمی‌تواند خالی باشد. لطفاً متن خوش‌آمدگویی را ارسال کنید.", $cancelKey, "HTML");
        exit();
    }

    $saveResult = farid_updateMainValueInValuesFile('start_message', $newStartText);
    if($saveResult === true){
        $mainValues['start_message'] = $newStartText;
        setUser();
        sendMessage("✅ متن خوش‌آمدگویی با موفقیت ذخیره شد.\nاز این به بعد پیام صفحه اصلی با متن جدید نمایش داده می‌شود.", $removeKeyboard, "HTML");
        sendMessage("📝 تنظیم متن‌ها", farid_textSettingsKeyboard(), "HTML");
    }else{
        sendMessage("❌ ذخیره متن انجام نشد.\n" . htmlspecialchars((string)$saveResult, ENT_QUOTES, 'UTF-8'), $cancelKey, "HTML");
    }
    exit();
}
if($data=="editPurchaseRulesText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("📢 متن قوانین یا آخرین اخبار قبل از خرید را ارسال کنید.\n\nاین متن وقتی کاربر روی خرید بزند نمایش داده می‌شود و فقط با دکمه تأیید می‌تواند ادامه خرید را ببیند.\nبرای غیرفعال کردن، از دکمه حذف متن در همین بخش استفاده کنید.", $cancelKey, "HTML");
    setUser("editPurchaseRulesText");
    exit();
}
if($userInfo['step'] == "editPurchaseRulesText" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $rulesText = trim((string)$text);
    if($rulesText === ''){
        sendMessage("⚠️ متن نمی‌تواند خالی باشد. برای غیرفعال کردن این بخش، دکمه حذف متن قوانین/اخبار را بزنید.", $cancelKey, "HTML");
        exit();
    }
    $saveResult = farid_updateMainValueInValuesFile('purchase_rules_text', $rulesText);
    if($saveResult === true){
        $mainValues['purchase_rules_text'] = $rulesText;
        setUser();
        sendMessage("✅ متن قوانین/آخرین اخبار خرید با موفقیت ذخیره شد.", $removeKeyboard, "HTML");
        sendMessage("📝 تنظیم متن‌ها", farid_textSettingsKeyboard(), "HTML");
    }else{
        sendMessage("❌ ذخیره متن انجام نشد.\n" . htmlspecialchars((string)$saveResult, ENT_QUOTES, 'UTF-8'), $cancelKey, "HTML");
    }
    exit();
}
if($data=="clearPurchaseRulesText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $saveResult = function_exists('v2raystore_removeMainText') ? v2raystore_removeMainText('purchase_rules_text') : true;
    if($saveResult === true){
        unset($mainValues['purchase_rules_text']);
        editText($message_id, "✅ متن قوانین/آخرین اخبار خرید حذف شد. از این به بعد کاربر مستقیم وارد خرید می‌شود.", farid_textSettingsKeyboard(), "HTML");
    }else{
        alert("حذف متن انجام نشد: " . (string)$saveResult);
    }
    exit();
}
if($data=="changeUpdateConfigLinkState" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = $botState['updateConnectionState']=="robot"?"site":"robot";
    setSettings('updateConnectionState', $newValue);
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(($data=="gateWays_Channels" or preg_match("/^changeGateWays(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="gateWays_Channels"){
        $newValue = $botState[$match[1]]=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getGateWaysKeys());
}
if($data=="changeConfigRemarkType"){
    switch($botState['remark']){
        case "digits":
            $newValue = "manual";
            break;
        case "manual":
            $newValue = "idanddigits";
            break;
        default:
            $newValue = "digits";
            break;
    }
    setSettings('remark', $newValue);
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(preg_match('/^changePaymentKeys(\w+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    switch($match[1]){
        case "nextpay":
            $gate = "کد جدید درگاه نکست پی";
            break;
        case "nowpayment":
            $gate = "کد جدید درگاه nowPayment";
            break;
        case "zarinpal":
            $gate = "کد جدید درگاه زرین پال";
            break;
        case "bankAccount":
            $gate = "شماره کارت خرید اول";
            break;
        case "holderName":
            $gate = "اسم دارنده کارت خرید اول";
            break;
        case "secondBankAccount":
            $gate = "شماره کارت خرید دوم";
            break;
        case "secondHolderName":
            $gate = "اسم دارنده کارت خرید دوم";
            break;
        case "cardContact":
            $gate = "آیدی عددی یا یوزرنیم ادمین دریافت شماره کارت";
            break;
        case "tronwallet":
            $gate = "آدرس والت ترون";
            break;
    }
    sendMessage("🔘|لطفاً $gate را وارد کنید

برای خالی کردن مقدار، /empty را بفرستید.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changePaymentKeys(\w+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $paymentKeys = v2raystore_getPaymentKeys();
    $value = trim((string)$text);
    if(in_array(strtolower($value), ['/empty', 'empty', 'خالی'], true)) $value = '';
    $paymentKeys[$match[1]] = $value;
    if(in_array($match[1], ['bankAccount', 'holderName', 'secondBankAccount', 'secondHolderName'], true)){
        $paymentKeys['cardInfoVersion'] = time();
    }
    v2raystore_savePaymentKeys($paymentKeys);

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    setUser();
}
if(($data == "agentsList" || preg_match('/^nextAgentList(\d+)/',$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getAgentsList($match[1]??0);
    if($keys != null) editText($message_id,$mainValues['agents_list'], $keys);
    else alert("نماینده ای یافت نشد");
}

if($data == "addAgentManual" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("➕ <b>افزودن نماینده دستی</b>\n\nلطفاً آیدی عددی تلگرام کاربر را ارسال کنید.\nاگر کاربر هنوز /start نزده باشد، یک رکورد ساده برای او ساخته می‌شود و پنل نمایندگی برایش ارسال خواهد شد.", $cancelKey, "HTML");
    setUser("addAgentManualUserId");
    exit();
}
if($userInfo['step'] == "addAgentManualUserId" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = trim((string)$text);
    if(!preg_match('/^\d{5,20}$/', $targetId)){
        sendMessage("⚠️ آیدی عددی معتبر نیست. لطفاً فقط آیدی عددی تلگرام کاربر را ارسال کنید.", $cancelKey, "HTML");
        exit();
    }
    setUser("addAgentManualDiscount_" . $targetId);
    sendMessage("✅ آیدی کاربر دریافت شد.\n\nلطفاً درصد تخفیف عمومی نماینده را فقط به عدد وارد کنید. مثال: <code>10</code>", $cancelKey, "HTML");
    exit();
}
if(preg_match('/^addAgentManualDiscount_(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetId = (int)$m[1];
    if(!is_numeric($text) || floatval($text) < 0 || floatval($text) > 100){
        sendMessage("⚠️ درصد تخفیف باید عددی بین 0 تا 100 باشد.", $cancelKey, "HTML");
        exit();
    }
    $discountValue = (string)(0 + $text);
    v2raystore_ensureBasicUserRecord($targetId);
    $discount = json_encode(['normal'=> (function_exists('v2raystore_makeAgentPricingRule') ? v2raystore_makeAgentPricingRule('percent', $discountValue) : $discountValue), 'plans'=>[], 'servers'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 1, `discount_percent` = ?, `agent_date` = ?, `step` = 'none' WHERE `userid` = ?");
    $stmt->bind_param('sii', $discount, $now, $targetId);
    $ok = $stmt->execute();
    $stmt->close();
    setUser();
    if($ok){
        sendMessage("✅ نماینده با موفقیت به‌صورت دستی اضافه شد.\n\nآیدی عددی: <code>$targetId</code>\nدرصد تخفیف عمومی: <code>$discountValue%</code>", $removeKeyboard, "HTML");
        sendMessage("👤 پنل نمایندگی شما توسط مدیریت فعال شد.\n\nبرای مشاهده امکانات، از دکمه زیر استفاده کنید.", getMainKeys(), "HTML", $targetId);
        $keys = getAgentsList();
        if($keys != null) sendMessage($mainValues['agents_list'], $keys, "HTML");
    }else{
        sendMessage("❌ افزودن نماینده انجام نشد. لطفاً دوباره تلاش کنید.", $removeKeyboard, "HTML");
    }
    exit();
}

if(preg_match('/^agentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $userDetail = bot('getChat',['chat_id'=>$match[1]])->result;
    $userUserName = $userDetail->username;
    $fullName = $userDetail->first_name . " " . $userDetail->last_name;

    editText($message_id,str_replace("AGENT-NAME", $fullName, $mainValues['agent_details']), getAgentDetails($match[1]));
}
if(preg_match('/^removeAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['agent_deleted_successfuly']);
    $keys = getAgentsList();
    if($keys != null) editKeys($keys);
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]]]));
}
if(preg_match('/^agentPercentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userName = $info['name'];
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[1]));
}
if(preg_match('/^addDiscount(Server|Plan)Agent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[2]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    if($match[1] == "Plan"){
        $offset = 0;
        $limit = 20;
        
        $condition = array_values(array_keys((function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'], true) ?: []))['plans'] ?? array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $condition .= ($condition === "" ? "WHERE " : " AND ") . "COALESCE(`price`,0) != 0";
        $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
                $stmt->bind_param("i", $row['catid']);
                $stmt->execute();
                $catInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match[2] . "_" . $row['id']]];
            }
            
            if($list->num_rows >= $limit){
                $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match[2] . "_" . ($offset + $limit)]];
            }
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,"لطفاً سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
        }else alert("سروری باقی نمانده است");
    }else{
        $condition = array_values(array_keys((function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'], true) ?: []))['servers'] ?? array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_info` $condition");
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $keys[] = [['text'=>$row['title'],'callback_data'=>"editAgentDiscountServer" . $match[2] . "_" . $row['id']]];
            }
            
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,"لطفاً سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
        }else alert("سروری باقی نمانده است");
    }
}
if(preg_match('/^nextAgentDiscountPlan(?<agentId>\d+)_(?<offset>\d+)/',$data,$match) &&($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    $offset = $match['offset'];
    $limit = 20;
    
    $condition = array_values(array_keys((function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'], true) ?: []))['plans'] ?? array()));
    $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
    $condition .= ($condition === "" ? "WHERE " : " AND ") . "COALESCE(`price`,0) != 0";
    $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows > 0){
        $keys = array();
        while($row = $list->fetch_assoc()){
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $row['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match['agentId'] . "_" . $row['id']]];
        }
        
        if($list->num_rows >= $limit && $offset == 0){
            $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]];
        }
        elseif($list->num_rows >= $limit && $offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)],
                ['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]
                ];
        }
        elseif($offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)]
                ];
        }
        $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match['agentId']]];
        $keys = json_encode(['inline_keyboard'=>$keys]);
        
        editText($message_id,"لطفاً سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
    }else alert("سروری باقی نمانده است");
}
if(preg_match('/^removePercentOfAgent(?<type>Server|Plan)(?<agentId>\d+)_(?<serverId>\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $discounts = function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'],true) ?: []);
    if($match['type'] == "Server") unset($discounts['servers'][$match['serverId']]);
    elseif($match['type'] == "Plan") unset($discounts['plans'][$match['serverId']]);
    
    $discounts = json_encode($discounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param("si", $discounts, $match['agentId']);
    $stmt->execute();
    $stmt->close();
    
    alert('با موفقیت حذف شد');
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match['agentId']));
}
if(preg_match('/^toggleAgentLink_(config|sub)_(\d+)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $key = $match[1];
    $agentId = intval($match[2]);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$info){
        alert('نماینده پیدا نشد');
        exit();
    }
    $discountInfo = function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'] ?? '', true) ?: []);
    $links = function_exists('v2raystore_agentLinkSettingsNormalize') ? v2raystore_agentLinkSettingsNormalize($discountInfo['links'] ?? []) : ['config'=>'default','sub'=>'default'];
    $current = $links[$key] ?? 'default';
    $next = function_exists('v2raystore_agentLinkNextState') ? v2raystore_agentLinkNextState($current) : ($current === 'default' ? 'on' : ($current === 'on' ? 'off' : 'default'));
    $links[$key] = $next;

    // جلوی ذخیره حالتی که هیچ لینکی به نماینده نرسد گرفته می‌شود.
    $globalConfig = (($botState['configLinkState'] ?? 'on') != 'off');
    $globalSub = (($botState['subLinkState'] ?? 'off') == 'on');
    $resolvedConfig = ($links['config'] === 'default') ? $globalConfig : ($links['config'] === 'on');
    $resolvedSub = ($links['sub'] === 'default') ? $globalSub : ($links['sub'] === 'on');
    if(!$resolvedConfig && !$resolvedSub){
        alert('حداقل یکی از لینک عادی یا لینک ساب باید برای نماینده فعال باشد.');
        exit();
    }

    $discountInfo['links'] = $links;
    $encoded = json_encode($discountInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param('si', $encoded, $agentId);
    $stmt->execute();
    $stmt->close();

    $label = ($key === 'config') ? 'لینک عادی' : 'لینک ساب';
    $stateLabel = function_exists('v2raystore_agentLinkStateLabel') ? v2raystore_agentLinkStateLabel($next) : $next;
    alert($label . ' نماینده: ' . $stateLabel);
    editText($message_id, str_replace("AGENT-NAME", $info['name'] ?? $agentId, $mainValues['agent_discount_settings']), getAgentDiscounts($agentId));
    exit();
}


if(preg_match('/^(?:agentServerDelivery|agSrvPanel)_(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $offset = intval($match[2]);
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $agentExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$agentExists){
        alert('نماینده پیدا نشد.', true);
        exit();
    }
    $panelText = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیمات نحوه ارسال نماینده';
    $panelKeys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $editRes = editText($message_id, $panelText, $panelKeys, 'HTML');
    if(!is_object($editRes) || empty($editRes->ok)){
        sendMessage($panelText, $panelKeys, 'HTML');
    }
    exit();
}
if(preg_match('/^toggleAgentServerDelivery_(\\d+)_(\\d+)_(\\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $offset = intval($match[3]);
    $next = function_exists('v2raystore_toggleAgentServerDeliveryMode') ? v2raystore_toggleAgentServerDeliveryMode($agentId, $serverId) : false;
    if($next === false){
        alert('تغییر نحوه ارسال انجام نشد.', true);
        exit();
    }
    $panelText = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیمات نحوه ارسال نماینده';
    $panelKeys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $editRes = editText($message_id, $panelText, $panelKeys, 'HTML');
    if(!is_object($editRes) || empty($editRes->ok)){
        sendMessage($panelText, $panelKeys, 'HTML');
    }
    $label = function_exists('v2raystore_deliveryModeLabel') ? v2raystore_deliveryModeLabel($next, 'پیش‌فرض سرور ⚙️') : $next;
    alert('نحوه ارسال این سرور برای نماینده: ' . $label);
    exit();
}
if(preg_match('/^toggleAgentServerSaleDelivery_(\d+)_(\d+)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $serverId = intval($match[2]);
    $offset = intval($match[3]);
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $agentExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$agentExists){
        alert('نماینده پیدا نشد.', true);
        exit();
    }
    $ok = function_exists('v2raystore_toggleAdminServerSaleAccess') ? v2raystore_toggleAdminServerSaleAccess($agentId, $serverId) : false;
    if(!$ok){
        alert('تغییر دسترسی فروش انجام نشد.', true);
        exit();
    }
    $panelText = function_exists('v2raystore_getAgentDeliveryMenuText') ? v2raystore_getAgentDeliveryMenuText($agentId) : 'تنظیمات نحوه ارسال نماینده';
    $panelKeys = function_exists('v2raystore_getAgentDeliveryMenuKeys') ? v2raystore_getAgentDeliveryMenuKeys($agentId, $offset) : getAgentDiscounts($agentId);
    $editRes = editText($message_id, $panelText, $panelKeys, 'HTML');
    if(!is_object($editRes) || empty($editRes->ok)){
        sendMessage($panelText, $panelKeys, 'HTML');
    }
    $allowed = function_exists('v2raystore_adminHasClosedServerSaleAccess') ? v2raystore_adminHasClosedServerSaleAccess($agentId, $serverId) : false;
    alert($allowed ? 'دسترسی فروش این سرور برای نماینده فعال شد.' : 'دسترسی فروش این سرور برای نماینده غیرفعال شد.');
    exit();
}
if(preg_match('/^toggleAgentBuying_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$info){ alert('نماینده پیدا نشد'); exit(); }
    $d = v2raystore_agentPricingDecode($info['discount_percent'] ?? null);
    $l = v2raystore_agentLimitsNormalize($d['limits'] ?? []);
    $l['buying'] = ($l['buying'] === 'on') ? 'off' : 'on';
    $d['limits'] = $l;
    $encoded = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent`=? WHERE `userid`=?");
    $stmt->bind_param('si', $encoded, $agentId);
    $stmt->execute();
    $stmt->close();
    alert('وضعیت خرید نماینده تغییر کرد.');
    editText($message_id, str_replace('AGENT-NAME', $info['name'] ?? $agentId, $mainValues['agent_discount_settings']), getAgentDiscounts($agentId));
    exit();
}
if(preg_match('/^toggleAgentSwitchLocation_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$info){ alert('نماینده پیدا نشد'); exit(); }
    $next = function_exists('v2raystore_toggleAgentSwitchLocationState') ? v2raystore_toggleAgentSwitchLocationState($agentId) : false;
    if($next === false){ alert('ذخیره تنظیمات تغییر لوکیشن انجام نشد.', true); exit(); }
    $label = function_exists('v2raystore_agentSwitchLocationLabel') ? v2raystore_agentSwitchLocationLabel($next) : $next;
    alert('تغییر لوکیشن نماینده: ' . $label);
    editText($message_id, str_replace('AGENT-NAME', $info['name'] ?? $agentId, $mainValues['agent_discount_settings']), getAgentDiscounts($agentId));
    exit();
}
if(preg_match('/^editAgent(DailyCap|CreditCap)_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[2]);
    delMessage();
    if($match[1] === 'DailyCap'){
        sendMessage("سقف خرید روزانه نماینده را عددی وارد کنید.\nبرای نامحدود کردن عدد <code>0</code> را بفرستید.", $cancelKey, 'HTML');
        setUser('saveAgentLimit_daily_' . $agentId);
    }else{
        sendMessage("سقف اعتبار/بدهی نماینده را به تومان وارد کنید.\nبرای نامحدود کردن عدد <code>0</code> را بفرستید.", $cancelKey, 'HTML');
        setUser('saveAgentLimit_credit_' . $agentId);
    }
    exit();
}
if(preg_match('/^saveAgentLimit_(daily|credit)_(\d+)$/', $userInfo['step'] ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $value = str_replace([',','٬',' '], '', trim((string)$text));
    if(!is_numeric($value) || intval($value) < 0){ sendMessage('لطفاً فقط عدد صفر یا بزرگتر وارد کن.', $cancelKey); exit(); }
    $agentId = intval($match[2]);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=? AND `is_agent`=1 LIMIT 1");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$info){ setUser(); sendMessage('نماینده پیدا نشد.', $removeKeyboard); exit(); }
    $d = v2raystore_agentPricingDecode($info['discount_percent'] ?? null);
    $l = v2raystore_agentLimitsNormalize($d['limits'] ?? []);
    if($match[1] === 'daily') $l['daily_cap'] = intval($value);
    else $l['credit_cap'] = intval($value);
    $d['limits'] = $l;
    $encoded = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent`=? WHERE `userid`=?");
    $stmt->bind_param('si', $encoded, $agentId);
    $stmt->execute();
    $stmt->close();
    setUser();
    sendMessage('✅ تنظیمات نماینده ذخیره شد.', $removeKeyboard);
    sendMessage(str_replace('AGENT-NAME', $info['name'] ?? $agentId, $mainValues['agent_discount_settings']), getAgentDiscounts($agentId), 'HTML');
    exit();
}
if(preg_match('/^agentProReport_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $agentId = intval($match[1]);
    $txt = function_exists('v2raystore_agentReportText') ? v2raystore_agentReportText($agentId) : 'گزارش در دسترس نیست.';
    editText($message_id, $txt, json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'], 'callback_data'=>'agentPercentDetails' . $agentId]]]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}

if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $typeFa = $match[1] == 'Normal' ? 'عمومی' : ($match[1] == 'Plan' ? 'پلن' : 'سرور');
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>'درصد تخفیف', 'callback_data'=>'chooseAgentPricing_percent_' . $match[1] . '_' . $match[2] . '_' . $match[3]]],
        [['text'=>'قیمت هر گیگ', 'callback_data'=>'chooseAgentPricing_gb_' . $match[1] . '_' . $match[2] . '_' . $match[3]]],
        [['text'=>$buttonValues['back_button'], 'callback_data'=>'agentPercentDetails' . $match[2]]]
    ]], JSON_UNESCAPED_UNICODE);
    editText($message_id, "برای تنظیم {$typeFa} نماینده، نوع محاسبه را انتخاب کنید:", $keys, 'HTML');
}
if(preg_match('/^chooseAgentPricing_(percent|gb)_(Server|Plan|Normal)_(\d+)_(.*)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    if($match[1] === 'gb'){
        sendMessage("لطفاً قیمت هر گیگ را به تومان وارد کنید.\nمثال: اگر <code>6000</code> وارد کنید، سرویس ۵ گیگ برای نماینده <code>30000</code> تومان حساب می‌شود.", $cancelKey, 'HTML');
    }else{
        sendMessage("لطفاً درصد تخفیف نماینده را وارد کنید.\nمثال: <code>10</code> یعنی ۱۰٪ تخفیف از قیمت اصلی.", $cancelKey, 'HTML');
    }
    setUser('saveAgentPricing_' . $match[1] . '_' . $match[2] . '_' . $match[3] . '_' . $match[4]);
}
if(preg_match('/^saveAgentPricing_(percent|gb)_(Server|Plan|Normal)_(\d+)_(.*)$/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $value = str_replace([',','٬',' '], '', trim((string)$text));
    if(!is_numeric($value)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $value = floatval($value);
    if($value < 0){
        sendMessage("عدد نمی‌تواند منفی باشد.", $cancelKey);
        exit();
    }
    if($match[1] === 'percent' && $value > 100){
        sendMessage("درصد باید بین 0 تا 100 باشد.", $cancelKey);
        exit();
    }
    if($match[1] === 'gb' && $value <= 0){
        sendMessage("قیمت هر گیگ باید بیشتر از صفر باشد.", $cancelKey);
        exit();
    }

    $agentId = intval($match[3]);
    $targetId = (string)$match[4];
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$agentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $discountInfo = function_exists('v2raystore_agentPricingDecode') ? v2raystore_agentPricingDecode($info['discount_percent'] ?? null) : (json_decode($info['discount_percent'],true) ?: []);
    $rule = function_exists('v2raystore_makeAgentPricingRule') ? v2raystore_makeAgentPricingRule($match[1], $value) : ['mode'=>$match[1], 'value'=>$value];
    if($match[2] == "Server") $discountInfo['servers'][$targetId] = $rule;
    elseif($match[2] == "Plan") $discountInfo['plans'][$targetId] = $rule;
    elseif($match[2] == "Normal") $discountInfo['normal'] = $rule;
    $encodedDiscount = json_encode($discountInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param("si", $encodedDiscount, $agentId);
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    sendMessage(str_replace("AGENT-NAME", $info['name'] ?? $agentId, $mainValues['agent_discount_settings']), getAgentDiscounts($agentId));
    setUser();
}
if($data=="editRewardTime" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🙃 | لطفاً زمان تأخیر در ارسال گزارش رو به ساعت وارد کن\n\nنکته: هر n ساعت گزارش به ربات ارسال میشه! ",$cancelKey);
    setUser($data);
}
if($data=="userReports" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🙃 | لطفاً آیدی عددی کاربر رو وارد کن",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "userReports" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        sendMessage($mainValues['please_wait_message'],$removeKeyboard);
        $keys = getUserInfoKeys($text);
        if($keys != null){
            sendMessage("اطلاعات کاربر <a href='tg://user?id=$text'>$fullName</a>",$keys,"html");
            setUser();
        }else sendMessage("کاربری با این آیدی یافت نشد");
    }else{
        sendMessage("😡|لطفاً فقط عدد ارسال کن");
    }
}
if($data=="inviteSetting" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
    $stmt->execute();
    $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " تومان";
    $stmt->close();
    setUser();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
        [
            ['text'=>$inviteAmount,'callback_data'=>"editInviteAmount"],
            ['text'=>"مقدار پورسانت",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
            ],
        ]]); 
    $res = editText($message_id,"✅ تنظیمات بازاریابی",$keys);
    if(!$res->ok){
        delMessage();
        sendMessage("✅ تنظیمات بازاریابی",$keys);
    }
} 
if($data=="inviteBanner" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    $inviteText = $inviteText != null?json_decode($inviteText,true):array('type'=>'text');
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if($inviteText['type'] == "text"){
        editText($message_id,"بنر فعلی: \n" . $inviteText['text'],$keys);
    }else{
        delMessage();
        $res = sendPhoto($inviteText['file_id'], $inviteText['caption'], $keys,null);
        if(!$res->ok){
            sendMessage("تصویر فعلی یافت نشد، لطفاً اقدام به ویرایش بنر کنید",$keys);
        }
    }
    setUser();
}
if($data=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤖 | لطفاً بنر جدید را بفرستید از متن  LINK برای نمایش لینک دعوت استفاده کنید)",$cancelKey);
    setUser($data);
}
if($userInfo['step']=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $data = array();
    if(isset($update->message->photo)){
        $data['type'] = 'photo';
        $data['caption'] = $caption;
        $data['file_id'] = $fileid;
    }
    elseif(isset($update->message->text)){
        $data['type'] = 'text';
        $data['text'] = $text;
    }else{
        sendMessage("🥺 | بنر ارسال شده پشتیبانی نمی شود");
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $checkExist = $stmt->get_result();
    $stmt->close();
    $data = json_encode($data);
    if($checkExist->num_rows > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_TEXT'");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_TEXT')");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if(isset($update->message->text)){
        sendMessage("بنر فعلی: \n" . $text,$keys);
    }else{
        sendPhoto($fileid, $caption, $keys);
    }
    setUser();
}
if($data=="editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً مبلغ پورسانت رو به تومان وارد کن",$cancelKey);
    setUser($data);
} 
if($userInfo['step'] == "editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows > 0){
            $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_AMOUNT')");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
            [
                ['text'=>number_format($text) . " تومان",'callback_data'=>"editInviteAmount"],
                ['text'=>"مقدار پورسانت",'callback_data'=>"v2raystore"]
                ], 
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
                ],
            ]]); 
        sendMessage("✅ تنظیمات بازاریابی",$keys);
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if($userInfo['step'] == "editRewardTime" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("لطفاً عدد بفرستید");
        exit();
    }
    elseif($text <0 ){
        sendMessage("مقدار وارد شده معتبر نیست");
        exit();
    }
    
    setSettings('rewaredTime', $text);
    sendMessage($mainValues['change_bot_settings_message'],getBotSettingKeys());
    setUser();
    exit();
}
if($data=="inviteFriends"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    if($inviteText != null){
        delMessage();
        $inviteText = json_decode($inviteText,true);
    
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " تومان";
        $stmt->close();
        
        $getBotInfo = bot('getMe', ['_timeout' => 4]);
        $botId = (is_object($getBotInfo) && !empty($getBotInfo->ok) && isset($getBotInfo->result->username)) ? $getBotInfo->result->username : '';
        if($botId === ''){
            alert('دریافت اطلاعات ربات از تلگرام ناموفق بود. لطفاً چند لحظه بعد دوباره تلاش کنید.', true);
            exit();
        }
        
        $link = "t.me/$botId?start=" . $from_id;
        if($inviteText['type'] == "text"){
            $txt = str_replace('LINK',"<code>$link</code>",$inviteText['text']);
            $res = sendMessage($txt,null,"HTML");
        } 
        else{
            $txt = str_replace('LINK',"$link",$inviteText['caption']);
            $res = sendPhoto($inviteText['file_id'],$txt,null,"HTML");
        }
        $msgId = $res->result->message_id;
        sendMessage("با لینک بالا دوستاتو به ربات دعوت کن و با هر خرید $inviteAmount بدست بیار",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),null,null,$msgId);
    }
    else alert("این قسمت غیر فعال است");
}
if($data=="myInfo"){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $totalBuys = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $myWallet = number_format($userInfo['wallet']) . " تومان";
    
    $accountKeys = [];
    if(v2raystore_isWalletOpenForCurrentUser()){
        $accountKeys[] = [
            ['text'=>"شارژ کیف پول 💰",'callback_data'=>"increaseMyWallet"]
        ];
    }
    $accountKeys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$accountKeys], JSON_UNESCAPED_UNICODE);
    editText($message_id, "
💞 اطلاعات حساب شما:
    
🔰 شناسه کاربری: <code> $from_id </code>
این همان آیدی عددی معرف شماست؛ اگر کسی برای عضویت نیاز به معرف داشت، همین عدد را برایش بفرستید.
🍄 یوزرنیم: <code> @$username </code>
👤 اسم:  <code> $first_name </code>
💰 موجودی: <code> $myWallet </code>

☑️ کل سرویس ها : <code> $totalBuys </code> عدد
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",
            $keys,"html");
}
if($data=="transferMyWallet"){
    setUser();
    alert("گزینه انتقال موجودی غیرفعال شده است.", true);
    exit();
}
if(($userInfo['step'] == "transferMyWallet" || preg_match('/^tranfserUserAmount(\d+)/', $userInfo['step'])) && $text != $buttonValues['cancel']){
    setUser();
    sendMessage("گزینه انتقال موجودی غیرفعال شده است.", $removeKeyboard);
    sendMessage("لطفاً یکی از کلید های زیر را انتخاب کنید", getMainKeys());
    exit();
}
if($data=="increaseMyWallet"){
    if(!v2raystore_isWalletOpenForCurrentUser()){
        alert("کیف پول در حال حاضر برای حساب شما غیرفعال است.", true);
        exit();
    }
    delMessage();
    sendMessage("لطفاً مبلغ شارژ کیف پول را به تومان وارد کنید. حداقل مبلغ قابل ثبت ۵۰۰۰ تومان است.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseMyWallet" && $text != $buttonValues['cancel']){
    if(!v2raystore_isWalletOpenForCurrentUser()){
        setUser();
        sendMessage("کیف پول در حال حاضر برای حساب شما غیرفعال است.", $removeKeyboard);
        exit();
    }
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    elseif($text < 5000){
        sendMessage("لطفاً مقداری بیشتر از 5000 وارد کن");
        exit();
    }
    sendMessage("🪄 لطفاً صبور باشید ...",$removeKeyboard);
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'INCREASE_WALLET' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, 'INCREASE_WALLET', '0', '0', '0', ?, ?, 'pending')");
    $stmt->bind_param("siii", $hash_id, $from_id, $text, $time);
    $stmt->execute();
    $stmt->close();
    
    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "increaseWalletWithCartToCart" . $hash_id]];
    if($botState['nowPaymentWallet'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];

    
	$keys = json_encode(['inline_keyboard'=>$keyboard]);
    sendMessage("اطلاعات شارژ:\nمبلغ ". number_format($text) . " تومان\n\nلطفاً روش پرداخت را انتخاب کنید",$keys);
    setUser();
}
if(preg_match('/increaseWalletWithCartToCart(.*)/',$data, $match)) {
    delMessage();
    setUser($data);
    v2raystore_sendCartToCartInstructions($match[1], 'increase_wallet_cart_to_cart', 'HTML');
    exit;
}
if(preg_match('/increaseWalletWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        v2raystore_markPayReceiptSent($match[1], $fileid);
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = number_format($payInfo['price']);

    

        sendMessage($mainValues['order_increase_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        $msg = str_replace(['PRICE', 'USERNAME', 'NAME', 'USER-ID'],[$price, $username, $name, $from_id], $mainValues['increase_wallet_request_message']);
        
        $keyboard = v2raystore_adminPendingWalletKeyboard($match[1], $uid);
        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        }
    }else{
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/^approvePayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    $result = function_exists('v2raystore_approveIncreaseWalletPayByHash') ? v2raystore_approveIncreaseWalletPayByHash($hashId, false) : ['ok'=>false, 'message'=>'تابع تأیید شارژ کیف پول در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    $userId = intval($result['user_id'] ?? 0);
    $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
    if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard('✅ تأیید شد', $userId, 'success', $copyText));
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید شد','callback_data'=>'dontsendanymore']]]], JSON_UNESCAPED_UNICODE));
    exit();
}

if(preg_match('/^decPayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $decUserId = 0;
    if($stmt){
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $decPayInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $decUserId = intval($decPayInfo['user_id'] ?? 0);
        if(($decPayInfo['state'] ?? '') != 'sent'){
            alert('این درخواست قبلاً تأیید/رد شده یا قابل رد کردن نیست.', true);
            if(($decPayInfo['state'] ?? '') == 'approved' && function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard('✅ تأیید شد', $decUserId, 'success'));
            exit();
        }
    }
    $keys = function_exists('v2raystore_orderStatusKeyboard') ? v2raystore_orderStatusKeyboard('❌ رد شد', $decUserId, 'danger') : json_encode(['inline_keyboard'=>[[['text'=>'❌ رد شد','callback_data'=>'dontsendanymore']]]], JSON_UNESCAPED_UNICODE);
    file_put_contents("temp" . $from_id . ".txt", $keys);
    sendMessage("لطفاً دلیل عدم تأیید افزایش موجودی را وارد کنید",$cancelKey);
    setUser("decPayment" . $message_id . "_" . $match[1]);
}
if(preg_match('/^decPayment(\d+)_(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined' WHERE `hash_id` = ? AND `state` = 'sent'");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if($changed <= 0){
        setUser();
        sendMessage('❌ این درخواست دیگر در وضعیت قابل رد کردن نیست؛ احتمالاً قبلاً تأیید/رد شده است.', $removeKeyboard);
        exit();
    }
    
    sendMessage("💔 افزایش موجودی شما به مبلغ "  . number_format($price) . " به دلیل زیر رد شد\n\n$text",null,null,$userId);


    editKeys(file_get_contents("temp" . $from_id . ".txt"), $match[1]);
    setUser();
    sendMessage('پیامت رو براش ارسال کردم ... 🤝',$removeKeyboard);
    unlink("temp" . $from_id . ".txt");
}
if($data=="increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("increaseWalletUser" . $text);
            sendMessage($mainValues['enter_increase_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^increaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage("✅ مبلغ " . number_format($text). " تومان به حساب شما اضافه شد",null,null,$match[1]);
        sendMessage("✅ مبلغ " . number_format($text) . " تومان به کیف پول کاربر مورد نظر اضافه شد",$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("decreaseWalletUser" . $text);
            sendMessage($mainValues['enter_decrease_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^decreaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']),null,null,$match[1]);
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_user_wallet']),$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗|لطفاً ربات را در گروه/کانال گزارش ادمین کن و آیدی گروه/کانال را بفرست",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botInfo = bot('getMe', ['_timeout' => 4]);
    $botId = (is_object($botInfo) && !empty($botInfo->ok) && isset($botInfo->result->id)) ? $botInfo->result->id : 0;
    $result = $botId ? bot('getChatMember', ['chat_id'=>$text, 'user_id'=>$botId, '_timeout'=>5]) : null;
    if(is_object($result) && !empty($result->ok)){
        if($result->result->status == "administrator"){
            setSettings('rewardChannel', $text);
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage("😡|ربات هنوز داخل گروه/کانال گزارش ادمین نیست. اول ربات را ادمین کن و آیدیش را دوباره بفرست.");
}
if($data=="editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗|لطفاً ربات رو در کانال ادمین کن و آیدی کانال رو بفرست",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botInfo = bot('getMe', ['_timeout' => 4]);
    $botId = (is_object($botInfo) && !empty($botInfo->ok) && isset($botInfo->result->id)) ? $botInfo->result->id : 0;
    $result = $botId ? bot('getChatMember', ['chat_id'=>$text, 'user_id'=>$botId, '_timeout'=>5]) : null;
    if(is_object($result) && !empty($result->ok)){
        if($result->result->status == "administrator"){
            setSettings("lockChannel", $text);
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage($mainValues['the_bot_in_not_admin']);
}

$v2raystorePurchaseRulesConfirmed = false;
if(preg_match('/^confirmPurchaseRules_(agentOneBuy|buySubscription|agentMuchBuy)$/', (string)$data, $purchaseRulesMatch)){
    $data = $purchaseRulesMatch[1];
    $v2raystorePurchaseRulesConfirmed = true;
    if(($botState['sellState'] ?? 'off') != "on" && !($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)){
        alert($mainValues['selling_is_off'] ?? 'فروش در حال حاضر بسته است.', true);
        $startMessageText = function_exists('v2raystore_getMainText') ? v2raystore_getMainText('start_message', $mainValues['start_message'] ?? '') : ($mainValues['start_message'] ?? '');
        editText($message_id, $startMessageText, getMainKeys(), null);
        exit();
    }
}
if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && !$v2raystorePurchaseRulesConfirmed && !($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true) && function_exists('v2raystore_purchaseRulesIsEnabled') && v2raystore_purchaseRulesIsEnabled()){
    if(($botState['sellState'] ?? 'off') != "on"){
        alert($mainValues['selling_is_off'] ?? 'فروش در حال حاضر بسته است.', true);
        $startMessageText = function_exists('v2raystore_getMainText') ? v2raystore_getMainText('start_message', $mainValues['start_message'] ?? '') : ($mainValues['start_message'] ?? '');
        editText($message_id, $startMessageText, getMainKeys(), null);
        exit();
    }
    editText($message_id, v2raystore_purchaseRulesMessage(), v2raystore_purchaseRulesKeyboard($data), null);
    exit();
}
if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if($botState['cartToCartState'] == "off" && $botState['walletState'] == "off"){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") $buyType = "none";
    elseif($data=="agentOneBuy") $buyType = "one";
    elseif($data== "agentMuchBuy") $buyType = "much";
    if(function_exists('v2raystore_agentCanStartPurchase')){
        $agentGate = v2raystore_agentCanStartPurchase($userInfo ?? null, $buyType);
        if(empty($agentGate['ok'])){
            alert($agentGate['message'] ?? 'خرید نماینده فعلاً مجاز نیست.', true);
            exit();
        }
    }
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `state` = 1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = intval($cat['id']);
        if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($id, $from_id, $userInfo ?? null, $buyType)) continue;
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "selectServer{$id}_{$buyType}"];
    }
    if(empty($keyboard)){
        alert($mainValues['no_server_available'] ?? 'در حال حاضر سروری برای خرید فعال نیست.', true);
        exit;
    }
    $keyboard = array_chunk($keyboard,1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
}
if($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        sendMessage($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "createAccServer$id"];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/createAccServer(\d+)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert("هیچ دسته بندی برای این سرور وجود ندارد");
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 AND COALESCE(`price`,0) != 0");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert("هیچ دسته بندی برای این سرور وجود ندارد");exit;
        }
        alert("♻️ | دریافت دسته بندی ...");
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "2️⃣ مرحله دو:

دسته بندی مورد نظرت رو انتخاب کن 🤭", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 AND COALESCE(`price`,0) != 0 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert("💡پلنی در این دسته بندی وجود ندارد ");
    }else{
        alert("📍در حال دریافت لیست پلن ها");
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه:

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("❗️لطفاً مدت زمان اکانت را به ( روز ) وارد کن:",$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        if($text >0){
            sendMessage("❕حجم اکانت ها رو به گیگابایت ( GB ) وارد کن:");
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage("عدد باید بیشتر از 0 باشه");
        }
    }else{
        sendMessage('😡 | مگه نمیگم فقط عدد بفرس نمیفهمی؟ یا خودتو زدی به نفهمی؟');
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }
    sendMessage($mainValues['enter_account_amount']);
    setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $text);
}
if(preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }
    $uid = $from_id;
    $fid = $match[1];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $match[2];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $match[3];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;
    
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount < $text) {
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $serverTitle = $serverInfo['title'] ?? '';
    $srv_remark = $serverInfo['remark'];
    $stmt->close();
    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0];
    $last_num = $savedinfo[1];
    include_once 'phpqrcode/qrlib.php';
    $ecc = 'L';
    $pixel_Size = 11;
    $frame_Size = 0;
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();


	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?);");
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
    for($i = 1; $i<= $text; $i++){
        $uniqid = generateRandomString(42,$protocol); 
        if($portType == "auto"){
            $port++;
        }else{
            $port = rand(1111,65000);
        }
        $last_num++;
        
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
    
        if($inbound_id == 0){                    
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $remark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                    }
                }
            }
            else{
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                
                if(!$response->success){
                    if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                    elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);
    
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                }
            }
        }else {
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
            }
        }
        
        if(is_null($response)){
            sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
            break;
        }
    	if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
            break;
    	}
    	if(!$response->success){
            sendMessage('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
            sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
            break;
        }
    
        if($serverType == "marzban"){
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
            $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links);
        }
        else{
            $token = RandomString(30);
            $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
            $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
            $vray_link = json_encode($vraylink);
        }
        $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreLoopLinks = $vraylink;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
            $acc_text = "
    
        🔮 $remark \n " . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"<code>$link</code>":"");
            if($subLink != "") $acc_text .= 
            " \n🌐 subscription : <code>$subLink</code>";
        
            $file = RandomString() .".png";
            
            QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);


        	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
            unlink($file);
        }
        $stmt->bind_param("ssiiisssisiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar);
        $stmt->execute();
    }
    $stmt->close();
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
        $stmt->bind_param("ii", $text, $fid);
        $stmt->execute();
        $stmt->close();
    }
    sendMessage("☑️|❤️ اکانت های جدید با موفقیت ساخته شد",getMainKeys());
    setUser();
}
if(preg_match('/payWithTronWallet(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    
    $price = $payInfo['price'];
    $priceInTrx = round($price / $botState['TRXRate'],2);
    
    $stmt = $connection->prepare("UPDATE `pays` SET `tron_price` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("ds", $priceInTrx, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$priceInTrx, $paymentKeys['tronwallet']], $mainValues['pay_with_tron_wallet']), $cancelKey, "html");
    setUser($data);
}
if(preg_match('/^payWithTronWallet(.*)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!preg_match('/^[0-9a-f]{64}$/i',$text)){
        sendMessage($mainValues['incorrect_tax_id']);
        exit(); 
    }else{
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows == 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?, `state` = '0' WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $text, $match[1]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }else sendMessage($mainValues['used_tax_id']);
    }

}
if(preg_match('/payWithWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    sendMessage($mainValues['please_wait_message'],$removeKeyboard);
    
    
    $price = $payInfo['price'];
    $priceInUSD = round($price / $botState['USDRate'],2);
    $priceInTrx = round($price / $botState['TRXRate'],2);
    $pay = NOWPayments('POST', 'payment', [
        'price_amount' => $priceInUSD,
        'price_currency' => 'usd',
        'pay_currency' => 'trx'
    ]);
    if(isset($pay->pay_address)){
        $payAddress = $pay->pay_address;
        
        $payId = $pay->payment_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $payId, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پرداخت با درگاه ارزی ریالی",'url'=>"https://changeto.technology/quick?amount=$priceInTrx&currency=TRX&address=$payAddress"]],
            [['text'=>"پرداخت کردم ✅",'callback_data'=>"havePaiedWeSwap" . $match[1]]]
            ]]);
sendMessage("
✅ لینک پرداخت با موفقیت ایجاد شد

💰مبلغ : " . $priceInTrx . " ترون

✔️ بعد از پرداخت حدود 1 الی 15 دقیقه صبر کنید تا پرداخت به صورت کامل انجام شود سپس روی پرداخت کردم کلیک کنید
⁮⁮ ⁮⁮
",$keys);
    }else{
        if($pay->statusCode == 400){
            sendMessage("مقدار انتخاب شده کمتر از حد مجاز است");
        }else{
            sendMessage("مشکلی رخ داده است، لطفاً به پشتیبانی اطلاع بدهید");
        }
        sendMessage("لطفاً یکی از کلید های زیر را انتخاب کنید",getMainKeys());
    }
}
if(preg_match('/havePaiedWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($payInfo['state'] == "pending"){
    $payid = $payInfo['payid'];
    $payType = $payInfo['type'];
    $price = $payInfo['price'];

    $request_json = NOWPayments('GET', 'payment', $payid);
    if($request_json->payment_status == 'finished' or $request_json->payment_status == 'confirmed' or $request_json->payment_status == 'sending'){
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
        
    if($payType == "INCREASE_WALLET"){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد");
        sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد",null,null,$admin);                
    }
    elseif($payType == "BUY_SUB"){
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    $description = $payInfo['description'];
    
    
    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($volume == 0 && $days == 0){
        $volume = $file_detail['volume'];
        $days = $file_detail['days'];
    }
    
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];   
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;
    
    $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
    $eachPrice = $price / $accountCount;
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $serverTitle = $serverInfo['title'];
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();
    include_once 'phpqrcode/qrlib.php';

    alert($mainValues['sending_config_to_user']);
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
    for($i = 1; $i <= $accountCount; $i++){
        $uniqid = generateRandomString(42,$protocol);
        
        $savedinfo = file_get_contents('settings/temp.txt');
        $savedinfo = explode('-',$savedinfo);
        $port = $savedinfo[0] + 1;
        $last_num = $savedinfo[1] + 1;
        
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }
        elseif($botState['remark'] == "manual"){
            $remark = $payInfo['description'];
        }
        else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
        if(!empty($description)) $remark = $description;
        if($portType == "auto"){
            file_put_contents('settings/temp.txt',$port.'-'.$last_num);
        }else{
            $port = rand(1111,65000);
        }
        
        if($inbound_id == 0){    
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $remark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                    }
                }
            }else{
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                if(!$response->success){
                    if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                    elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);
                    
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                } 
            }
        }else {
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
            } 
        }
        
        if(is_null($response)){
            sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
            exit;
        }
        if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        	exit;
        }
        if(!$response->success){
            sendMessage('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
            sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
            exit;
        }
        
        if($serverType == "marzban"){
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
            $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links);
        }else{
            $token = RandomString(30);
            $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
    
            $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
            $vray_link = json_encode($vraylink);
        }
        $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreLoopLinks = $vraylink;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
        $acc_text = "
        
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");

if($subLink != "") $acc_text .= "



🌐 subscription : <code>$subLink</code>
        
        ";
              
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;
            
            QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);

        	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
            unlink($file);
        }
        
        $agentBought = $payInfo['agent_bought'];
        
        $stmt = $connection->prepare("INSERT INTO `orders_list` 
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
        $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agentBought);
        $stmt->execute();
        $order = $stmt->get_result(); 
        $stmt->close();
    }
    
    if($userInfo['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $userInfo['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
    }
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"v2raystore"]
        ],
        ]]);
        
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
        $stmt->bind_param("ii", $accountCount, $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
        $stmt->bind_param("ii", $accountCount, $fid);
        $stmt->execute();
        $stmt->close();
    }
    $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'ارزی ریالی', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);
    $msg = v2raystore_appendServerPlanToChannelReport($msg, $serverTitle, $file_detail['title'] ?? '');
    
    sendMessage($msg,$keys,"html", $admin);
}
elseif($payType == "RENEW_ACCOUNT"){
    $result = function_exists('v2raystore_approveRenewAccountPayByHash') ? v2raystore_approveRenewAccountPayByHash($payInfo['hash_id'], false) : ['ok'=>false, 'message'=>'تابع تمدید در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit;
    }
    sendMessage("✅سرویس " . ($result['renew_remark'] ?? '') . " با موفقیت تمدید شد", getMainKeys());
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"به به تمدید 😍",'callback_data'=>"v2raystore"]
        ],
    ]], JSON_UNESCAPED_UNICODE);
    $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کیف پول/درگاه', $from_id, $username, $first_name, ($result['price'] ?? 0), ($result['renew_remark'] ?? ''), ($result['renew_volume'] ?? ''), ($result['renew_days'] ?? '')], $mainValues['renew_account_request_message']);
    sendMessage($msg, $keys,"html", $admin);
}
elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo)){
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];

    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $volume = $res['volume'];

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
        else
            $response = editInboundTraffic($server_id, $uuid, 0, $volume);
    }
    
if($response->success){
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
    $newVolume = $volume * 86400;
    $stmt->bind_param("is", $newVolume, $uuid);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    $newVolume = $volume * 86400;
    $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("✅$volume روز به مدت زمان سرویس شما اضافه شد",getMainKeys());
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"اخیش یکی زمان زد 😁",'callback_data'=>"v2raystore"]
            ],
        ]]);
sendMessage("
🔋|💰 افزایش زمان با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume روز
💰قیمت: $price تومان
⁮⁮ ⁮⁮
",$keys,"html", $admin);

    exit;
}else {
    alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفاً به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید", true);
    exit;
}
}
elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo)){
$orderId = $increaseInfo[1];

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$orderInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$server_id = $orderInfo['server_id'];
$inbound_id = $orderInfo['inbound_id'];
$remark = $orderInfo['remark'];
$uuid = $orderInfo['uuid']??"0";

$planid = $increaseInfo[2];

$stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
$stmt->bind_param("i", $planid);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
$price = $payInfo['price'];
$volume = $res['volume'];

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, 0);
    }
    
if($response->success){
    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"اخیش یکی حجم زد 😁",'callback_data'=>"v2raystore"]
            ],
        ]]);
sendMessage("
🔋|💰 افزایش حجم با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume گیگ
💰قیمت: $price تومان
⁮⁮ ⁮⁮
",$keys,"html", $admin);
    sendMessage( "✅$volume گیگ به حجم سرویس شما اضافه شد",getMainKeys());exit;
    

}else {
    alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفاً به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید",true);
    exit;
}
}
elseif($payType == "RENEW_SCONFIG"){
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $volume = $file_detail['volume'];
    $days = $file_detail['days'];
    
    $price = $payInfo['price'];   
    $server_id = $file_detail['server_id'];
    $configInfo = json_decode($payInfo['description'],true);
    $remark = $configInfo['remark'];
    $uuid = $configInfo['uuid'];
    $isMarzban = $configInfo['marzban'];
    
    $remark = $payInfo['description'];
    $inbound_id = $payInfo['volume']; 
    
    if($isMarzban){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
    }
    
	if(is_null($response)){
		alert('🔻مشکل فنی در اتصال به سرور. لطفاً به مدیریت اطلاع بدید',true);
		exit;
	}
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();

    sendMessage("
    🔋|💰 تمدید مشخصات کانفیگ با ( کیف پول )
    
    ▫️آیدی کاربر: $from_id
    👨‍💼اسم کاربر: $first_name
    ⚡️ نام کاربری: $username
    🎈 نام سرویس: $remark
    ⏰ مدت کانفیگ: $volume گیگ
    حجم کانفیگ:  $days روز
    💰قیمت: $price تومان
    ⁮⁮ ⁮⁮
    ",$keys,"html", $admin);

}
    
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"پرداخت انجام شد",'callback_data'=>"v2raystore"]]
		    ]]));
}else{
    if($request_json->payment_status == 'partially_paid'){
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'partiallyPaied' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
        alert("شما هزینه کمتری پرداخت کردید، لطفاً به پشتیبانی پیام بدهید");
    }else{
        alert("پرداخت مورد نظر هنوز تکمیل نشده!");
    }
}
}else alert("این لینک پرداخت منقضی شده است");
}
if($data=="messageToSpeceficUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'], $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "messageToSpeceficUser" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $text);
    $stmt->execute();
    $usersCount = $stmt->get_result()->num_rows;
    $stmt->close();

    if($usersCount > 0 ){
        sendMessage("👀| خصوصی میخوای بهش پیام بدی شیطون، پیامت رو بفرس تا در گوشش بگم:");
        setUser("sendMessageToUser" . $text);
    }else{
        sendMessage($mainValues['user_not_found']);
    }
}

if($data == 'broadcastQueueStatus' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $queue = function_exists('farid_getActiveBroadcastQueue') ? farid_getActiveBroadcastQueue() : null;
    if(!$queue){
        editText($message_id, "✅ در حال حاضر هیچ ارسال/فوروارد همگانی فعالی در صف نیست.", getAdminKeysPlus(), 'HTML');
        exit();
    }
    $txt = function_exists('farid_formatBroadcastQueueText') ? farid_formatBroadcastQueueText($queue, true) : farid_getActiveBroadcastQueueText();
    $key = function_exists('farid_getBroadcastStatusKeyboard') ? farid_getBroadcastStatusKeyboard($queue['id']) : getAdminKeysPlus();
    editText($message_id, $txt, $key, 'HTML');
    exit();
}
if(preg_match('/^broadcastQueueCancel(\d+)$/', $data, $bqCancel) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $qid = intval($bqCancel[1]);
    $stmt = $connection->prepare("DELETE FROM `send_list` WHERE `id` = ? AND `state` = 1 AND `type` != 'updateConfigs'");
    $stmt->bind_param('i', $qid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if($affected > 0) editText($message_id, "🛑 صف ارسال/فوروارد همگانی متوقف و حذف شد.", getAdminKeysPlus(), 'HTML');
    else editText($message_id, "⚠️ صف موردنظر پیدا نشد یا قبلاً پایان یافته است.", getAdminKeysPlus(), 'HTML');
    exit();
}

if(($data == 'message2All' || $data == 'startBroadcastMessage2All') && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $queueText = function_exists('farid_getActiveBroadcastQueueText') ? farid_getActiveBroadcastQueueText() : null;
    if($queueText !== null){
        $queue = function_exists('farid_getActiveBroadcastQueue') ? farid_getActiveBroadcastQueue() : null;
        $key = ($queue && function_exists('farid_getBroadcastStatusKeyboard')) ? farid_getBroadcastStatusKeyboard($queue['id']) : null;
        sendMessage($queueText, $key, 'HTML');
        exit();
    }

    setUser();
    // پیام منوی مدیریت قبلی حذف می‌شود؛ بعد از حذف دیگر نمی‌توان همان message_id را edit کرد.
    // بنابراین منوی انتخاب مخاطب باید با sendMessage ارسال شود تا دکمه "ارسال همگانی" بی‌پاسخ نماند.
    delMessage();
    sendMessage("📨 ارسال پیام همگانی\n\nلطفاً مشخص کنید پیام برای کدام گروه از کاربران ارسال شود.", farid_getBroadcastTargetKeyboard('message'), 'HTML');
    exit();
}

if(preg_match('/^broadcastTargetMessage_(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $target = farid_normalizeBroadcastTarget($match[1]);
    $title = farid_getBroadcastTargetTitle($target);

    delMessage();
    setUser('s2a|' . $target);
    sendMessage("✉️ لطفاً متن یا فایل پیام همگانی را ارسال کنید.

🎯 گروه مخاطب: <b>$title</b>

برای جلوگیری از هنگ، شمارش مخاطبان همین لحظه انجام نمی‌شود؛ بعد از تأیید، صف ارسال خودش مرحله‌ای مخاطبان را بررسی و ارسال می‌کند.", $cancelKey, 'HTML');
    exit();
}

/* ======================================================================
   ♻️ پنل اختصاصی «به‌روزرسانی و ارسال کانفیگ‌ها» (Admin Only)
   - جدا شده از بخش پیام همگانی طبق درخواست شما
   - شامل: به‌روزرسانی همه / یک کاربر / یک کانفیگ / بر اساس دامنه / تنظیم پیام پس از به‌روزرسانی
   - + پاکسازی کانفیگ‌های قدیمی (با پیش‌نمایش قبل از حذف)
   ====================================================================== */

if($data == "updateConfigsMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    // خروج از هر استپ قبلی برای جلوگیری از گیر کردن داخل حالت‌های متنی
    setUser();

    $job = farid_getUpdateConfigsJob();

    $stateTxt = (intval($job['state'] ?? 0) == 1) ? "فعال ✅" : "غیرفعال ⛔️";
    $mode = $job['mode'] ?? '';

    // عنوان مود
    $modeTitle = "—";
    if($mode == "all_active") $modeTitle = "همه کانفیگ‌های فعال";
    elseif($mode == "user")   $modeTitle = "کانفیگ‌های کاربر: " . intval($job['userid'] ?? 0);
    elseif($mode == "ids" || $mode == "sub_ids")    $modeTitle = ($job['filter_title'] ?? "فیلتر سفارشی");
    else $modeTitle = "—";

    $total = 0;
    $offset = intval($job['offset'] ?? 0);
    if(intval($job['state'] ?? 0) == 1){
        $total = farid_getUpdateConfigsTotal($job);
    }
    $left = max(0, $total - $offset);

    // پیام پس از به‌روزرسانی
    $afterMsg = farid_getUpdateAfterMessage();
    $afterMsgState = (strlen(trim($afterMsg)) > 0) ? "فعال ✅" : "خاموش 🚫";

    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"📦 عملیات گروهی", 'callback_data'=>"noop_update_group", 'style'=>'primary']],
        [['text'=>"✅ همه کانفیگ‌های فعال",'callback_data'=>"updateConfigsAllActive", 'style'=>'success'], ['text'=>"👤 فقط یک کاربر",'callback_data'=>"updateConfigsUser", 'style'=>'primary']],
        [['text'=>"🧩 یک کانفیگ",'callback_data'=>"updateConfigsOne", 'style'=>'primary'], ['text'=>"🌐 بر اساس دامنه/ساب",'callback_data'=>"updateConfigsByDomain", 'style'=>'primary']],
        [['text'=>"🌐 آپدیت کانفیگ‌های ساب",'callback_data'=>"updateSubConfigsBySub", 'style'=>'primary']],

        [['text'=>"✉️ پیام پس از آپدیت",'callback_data'=>"updateConfigsAfterMessage", 'style'=>'primary']],

        [['text'=>"🚀 شروع / ادامه",'callback_data'=>"updateConfigsRun", 'style'=>'success'], ['text'=>"📊 وضعیت",'callback_data'=>"updateConfigsStatus", 'style'=>'primary']],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop", 'style'=>'danger']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"adminConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE);

    $txt = "♻️ پنل مدیریت به‌روزرسانی و ارسال کانفیگ‌ها\n\n".
           "🔰 وضعیت عملیات: $stateTxt\n".
           "🎯 نوع عملیات: $modeTitle\n".
           (intval($job['state'] ?? 0) == 1 ? ("📦 کل: $total\n☑️ انجام‌شده: $offset\n📣 باقی‌مانده: $left\n") : "").
           "\n✉️ پیام پس از به‌روزرسانی: $afterMsgState";

    editText($message_id, $txt, $keys);
    exit();
}

if($data == "manualAttachConfig" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    setUser('manualAttachConfigUser');
    sendMessage("👤 آیدی عددی کاربری که می‌خوای کانفیگ به نامش ثبت بشه رو ارسال کن.\n\nمثال: <code>123456789</code>", $cancelKey, 'HTML');
    exit();
}

if($userInfo['step'] == 'manualAttachConfigUser' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetUid = trim((string)$text);
    if(!preg_match('/^\d+$/', $targetUid)){
        sendMessage("❌ فقط آیدی عددی کاربر را ارسال کن.", $cancelKey, 'HTML');
        exit();
    }
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `userid` = ? LIMIT 1");
    $uidInt = intval($targetUid);
    $stmt->bind_param('i', $uidInt);
    $stmt->execute();
    $existsUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$existsUser){
        sendMessage("❌ این کاربر در دیتابیس ربات پیدا نشد. کاربر باید حداقل یک بار /start بزند.", $cancelKey, 'HTML');
        exit();
    }
    setUser($targetUid, 'temp');
    setUser('manualAttachConfigLink');
    sendMessage("🔗 حالا لینک کانفیگ یا لینک ساب را ارسال کن.\n\nربات خودش تشخیص می‌دهد لینک عادی است یا ساب، بعد داخل سرورهای ثبت‌شده می‌گردد و اگر کلاینت را پیدا کند به حساب همین کاربر اضافه می‌کند.", $cancelKey, 'HTML');
    exit();
}

if($userInfo['step'] == 'manualAttachConfigLink' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    $targetUid = intval($userInfo['temp'] ?? 0);
    [$ok, $result] = farid_registerManualConfigForUser($targetUid, $text);
    setUser('', 'temp');
    setUser();
    if(!$ok){
        sendMessage("❌ ثبت دستی انجام نشد:\n\n" . $result, json_encode(['inline_keyboard'=>[[['text'=>'↩️ تلاش دوباره','callback_data'=>'manualAttachConfig'], ['text'=>'⬅️ بازگشت','callback_data'=>'adminConfigsMenu']]]]), 'HTML');
        exit();
    }
    $msg = "✅ کانفیگ با موفقیت ثبت شد.\n\n" .
           "👤 کاربر: <code>$targetUid</code>\n" .
           "🧾 شماره سفارش: <code>" . intval($result['order_id']) . "</code>\n" .
           "🔮 نام سرویس: <b>" . htmlspecialchars($result['remark']) . "</b>\n" .
           "📡 سرور: <code>" . intval($result['server_id']) . "</code>\n" .
           "🔢 Inbound: <code>" . intval($result['inbound_id']) . "</code>";
    if(!empty($result['sub_link'])) $msg .= "\n\n🌐 subscription:\n<code>" . $result['sub_link'] . "</code>";
    sendMessage($msg, json_encode(['inline_keyboard'=>[[['text'=>'➕ افزودن کانفیگ دیگر','callback_data'=>'manualAttachConfig']], [['text'=>'⬅️ بازگشت','callback_data'=>'adminConfigsMenu']]]]), 'HTML');
    exit();
}


// شروع صف: همه کانفیگ‌های فعال
if($data == "updateConfigsAllActive" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        alert("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.");
        exit();
    }

    $job = [
        'state' => 1,
        'mode'  => 'all_active',
        'userid'=> 0,
        'offset'=> 0,
        'batch' => 10,
        'created_at' => time(),
        'requested_by' => $from_id,
        'filter_title' => 'همه کانفیگ‌های فعال',
        'stats' => ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0],
        'links_count' => 0,
        'status_chat_id' => 0,
        'status_message_id' => 0,
        'auto_running' => 0,
        'auto_last_ts' => 0,
        'stopped_at' => 0,
        'stopped_by' => 0,
        'report_sent' => 0
    ];
    farid_setUpdateConfigsJob($job);

    $total = farid_getUpdateConfigsTotal($job);

    editText($message_id, "✅ فهرست به‌روزرسانی «همه کانفیگ‌های فعال» ایجاد شد.\n📌 تعداد کل موارد: $total\n\nبرای آغاز عملیات، روی «🚀 شروع اجرای خودکار» کلیک کنید.", json_encode(['inline_keyboard'=>[
        [['text'=>"🚀 شروع اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
        [['text'=>"📊 مشاهده وضعیت",'callback_data'=>"updateConfigsStatus"]],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]],
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

// شروع صف: کاربر مشخص
if($data == "updateConfigsUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        alert("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.");
        exit();
    }
    sendMessage("👤 لطفاً شناسه کاربری (UserID) را ارسال کنید تا فقط کانفیگ‌های فعال همان کاربر به‌روزرسانی و ارسال شود:", $cancelKey);
    setUser("updateConfigsUser");
    exit();
}
if($userInfo['step'] == "updateConfigsUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("⛔️ فقط عدد بفرست (User ID).");
        exit();
    }

    $uid = intval($text);
    if($uid <= 0){
        sendMessage("⛔️ User ID نامعتبر است.");
        exit();
    }

    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        sendMessage("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.", $removeKeyboard);
        setUser();
        exit();
    }

    $job = [
        'state' => 1,
        'mode'  => 'user',
        'userid'=> $uid,
        'offset'=> 0,
        'batch' => 10,
        'created_at' => time(),
        'requested_by' => $from_id,
        'filter_title' => "کاربر: $uid",
        'stats' => ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0],
        'links_count' => 0,
        'status_chat_id' => 0,
        'status_message_id' => 0,
        'auto_running' => 0,
        'auto_last_ts' => 0,
        'stopped_at' => 0,
        'stopped_by' => 0,
        'report_sent' => 0
    ];
    farid_setUpdateConfigsJob($job);

    $total = farid_getUpdateConfigsTotal($job);

    sendMessage("✅ فهرست به‌روزرسانی برای کاربر $uid ایجاد شد.\n📌 تعداد کانفیگ‌های فعال: $total\n\nبرای آغاز عملیات، روی «🚀 شروع اجرای خودکار» کلیک کنید.", json_encode(['inline_keyboard'=>[
        [['text'=>"🚀 شروع اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
        [['text'=>"📊 مشاهده وضعیت",'callback_data'=>"updateConfigsStatus"]],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]],
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    setUser();
    exit();
}

// شروع صف: بر اساس دامنه/آدرس (هاست)
if($data == "updateConfigsByDomain" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        alert("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.");
        exit();
    }
    sendMessage("🌐 لطفاً دامنه یا آدرس موردنظر را ارسال کنید (مثال: example.com یا 1.2.3.4)\n\nربات صرفاً کانفیگ‌هایی را انتخاب می‌کند که «دامنه/آدرس» آن‌ها با مقدار واردشده مطابقت داشته باشد و سپس آن‌ها را به‌روزرسانی و ارسال می‌کند.", $cancelKey);
    setUser("updateConfigsByDomain");
    exit();
}
if($userInfo['step'] == "updateConfigsByDomain" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $domainRaw = trim($text);
    if(strlen($domainRaw) < 3){
        sendMessage("⛔️ دامنه/آدرس واردشده معتبر نیست. لطفاً یک مقدار صحیح ارسال کنید.");
        exit();
    }

    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        sendMessage("⛔️ در حال حاضر یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.", $removeKeyboard);
        setUser();
        exit();
    }

    sendMessage("⏳ در حال بررسی کانفیگ‌های ثبت‌شده در پایگاه داده... لطفاً چند لحظه صبر کنید.", $removeKeyboard);

    $found = farid_findLinkItemsByDomain($domainRaw);

    $items = [];
    $ordersCount = 0;
    $linksCount  = 0;

    if(is_array($found) && isset($found['items'])){
        $items = is_array($found['items']) ? $found['items'] : [];
        $ordersCount = intval($found['orders_count'] ?? 0);
        $linksCount  = intval($found['links_count'] ?? count($items));
    }

    if(!is_array($items) || count($items) == 0){
        sendMessage("❌ هیچ کانفیگی با این دامنه/آدرس در دیتابیس پیدا نشد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        setUser();
        exit();
    }

    $job = [
        'state' => 1,
        'mode'  => 'links',
        'userid'=> 0,
        'ids'   => [],
        'items' => array_values($items),
        'orders_count' => intval($ordersCount),
        'links_count'  => intval($linksCount),
        'offset'=> 0,
        'batch' => 10,
        'created_at' => time(),
        'requested_by' => $from_id,
        'filter_title' => "دامنه/آدرس: " . farid_normalizeDomainInput($domainRaw),
        'stats' => ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0],
        'status_chat_id' => 0,
        'status_message_id' => 0,
        'auto_running' => 0,
        'auto_last_ts' => 0,
        'stopped_at' => 0,
        'stopped_by' => 0,
        'report_sent' => 0
    ];
    farid_setUpdateConfigsJob($job);

    $total = farid_getUpdateConfigsTotal($job);

    sendMessage("✅ فهرست به‌روزرسانی بر اساس دامنه/آدرس ایجاد شد.\n🎯 فیلتر: " . farid_normalizeDomainInput($domainRaw) . "\n\n🔗 تعداد کانفیگ‌های منطبق (بر اساس لینک): $total\n📌 تعداد سفارش‌های درگیر (یکتا): $ordersCount\n\nبرای آغاز عملیات، روی «🚀 شروع اجرای خودکار» کلیک کنید.", json_encode(['inline_keyboard'=>[
        [['text'=>"🚀 شروع اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
        [['text'=>"📊 مشاهده وضعیت",'callback_data'=>"updateConfigsStatus"]],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]],
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    setUser();
    exit();
}


// شروع صف: آپدیت و ارسال فقط لینک‌های ساب بر اساس دامنه یا آدرس کامل ساب
if($data == "updateSubConfigsBySub" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        alert("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.");
        exit();
    }
    sendMessage("🌐 دامنه ساب یا آدرس کامل لینک ساب را ارسال کنید.\n\nنمونه دامنه: <code>sub.example.com</code>\nنمونه لینک کامل: <code>https://sub.example.com/sub/xxxx</code>\n\nدر این حالت فقط لینک ساب برای کاربران ارسال می‌شود و لینک کانفیگ عادی ارسال نمی‌شود.", $cancelKey, 'HTML');
    setUser("updateSubConfigsBySub");
    exit();
}
if($userInfo['step'] == "updateSubConfigsBySub" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $subRaw = trim((string)$text);
    if(strlen($subRaw) < 3){
        sendMessage("⛔️ مقدار واردشده معتبر نیست. دامنه ساب یا آدرس کامل ساب را ارسال کنید.", $cancelKey, 'HTML');
        exit();
    }

    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        sendMessage("⛔️ در حال حاضر یک عملیات فعال در حال اجراست. ابتدا آن را متوقف کنید.", $removeKeyboard);
        setUser();
        exit();
    }

    $found = function_exists('farid_findSubOrderIdsByInput') ? farid_findSubOrderIdsByInput($subRaw) : ['ids'=>[], 'orders_count'=>0];
    $ids = $found['ids'] ?? [];
    $ordersCount = intval($found['orders_count'] ?? count($ids));
    if(empty($ids)){
        sendMessage("⛔️ هیچ کانفیگ فعالی با این دامنه/لینک ساب پیدا نشد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        setUser();
        exit();
    }

    $filterTitle = function_exists('farid_normalizeSubLookupTitle') ? farid_normalizeSubLookupTitle($subRaw) : $subRaw;
    $job = [
        'state' => 1,
        'mode'  => 'sub_ids',
        'userid'=> 0,
        'ids'   => array_values(array_unique(array_map('intval', $ids))),
        'offset'=> 0,
        'batch' => 10,
        'created_at' => time(),
        'requested_by' => $from_id,
        'filter_title' => "آپدیت ساب: " . $filterTitle,
        'stats' => ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0],
        'links_count' => $ordersCount,
        'status_chat_id' => 0,
        'status_message_id' => 0,
        'auto_running' => 0,
        'auto_last_ts' => 0,
        'stopped_at' => 0,
        'stopped_by' => 0,
        'report_sent' => 0
    ];
    farid_setUpdateConfigsJob($job);

    sendMessage("✅ فهرست آپدیت ساب ساخته شد.\n🎯 فیلتر: " . htmlspecialchars($filterTitle, ENT_QUOTES, 'UTF-8') . "\n📌 تعداد کانفیگ‌های ساب: $ordersCount\n\nبرای آغاز عملیات، روی «🚀 شروع اجرای خودکار» کلیک کنید.", json_encode(['inline_keyboard'=>[
        [['text'=>"🚀 شروع اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
        [['text'=>"📊 مشاهده وضعیت",'callback_data'=>"updateConfigsStatus"]],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]],
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    setUser();
    exit();
}

// به‌روزرسانی و ارسال یک کانفیگ مشخص (User ID / Order ID / Remark)
if($data == "updateConfigsOne" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) == 1){
        alert("⛔️ یک عملیات فعال در حال اجراست. ابتدا «توقف عملیات» را انتخاب کنید.");
        exit();
    }
    sendMessage("🧩 برای پیدا کردن کانفیگ جهت آپدیت، یکی از این موارد را بفرستید:\n\n👤 آیدی عددی مشتری: همه کانفیگ‌های همان کاربر نمایش داده می‌شود.\n🔑 بخشی از نام/Remark کانفیگ\n#️⃣ شناسه دقیق سفارش با # مثل: #123\n\nدر نتیجه‌ها روی کانفیگ موردنظر بزنید.", $cancelKey);
    setUser("updateConfigsOne");
    exit();
}
if($userInfo['step'] == "updateConfigsOne" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $q = trim((string)$text);

    // اگر با # ارسال شد => Order ID دقیق، برای حفظ قابلیت قبلی
    if(preg_match('/^#\s*(\d+)$/', $q, $m)){
        $oid = intval($m[1]);
        $done = farid_updateAndSendOneOrder($oid, $from_id);
        if($done) sendMessage("✅ انجام شد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        else sendMessage("⛔️ سفارشی با این شناسه پیدا نشد یا خطا در به‌روزرسانی.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        setUser();
        exit();
    }

    if(strlen($q) < 2){
        sendMessage("حداقل ۲ کاراکتر وارد کن. برای OrderID دقیق از # استفاده کن، مثل #123");
        exit();
    }

    // عدد خالی یعنی User ID مشتری؛ همه کانفیگ‌های فعال همان کاربر نمایش داده می‌شود.
    // اگر برای آن User ID چیزی نبود، برای سازگاری با نسخه قبلی همان عدد به عنوان Order ID بررسی می‌شود.
    if(preg_match('/^\d+$/', $q)){
        $uidSearch = intval($q);
        $stmt = $connection->prepare("SELECT `id`,`remark`,`userid` FROM `orders_list` WHERE `status` = 1 AND `userid` = ? ORDER BY `id` DESC LIMIT 50");
        $stmt->bind_param("i", $uidSearch);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if($res->num_rows == 0){
            $oid = $uidSearch;
            $done = farid_updateAndSendOneOrder($oid, $from_id);
            if($done) sendMessage("✅ انجام شد.", json_encode(['inline_keyboard'=>[
                [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
            ]], JSON_UNESCAPED_UNICODE));
            else sendMessage("⛔️ برای این آیدی مشتری کانفیگی پیدا نشد. اگر منظورت OrderID است، آن را با # بفرست؛ مثل #$oid", json_encode(['inline_keyboard'=>[
                [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
            ]], JSON_UNESCAPED_UNICODE));
            setUser();
            exit();
        }
    }else{
        // متن => جستجو بر اساس Remark، حتی اگر بخشی از اسم کانفیگ باشد
        $stmt = $connection->prepare("SELECT `id`,`remark`,`userid` FROM `orders_list` WHERE `status` = 1 AND `remark` LIKE CONCAT('%', ?, '%') ORDER BY `id` DESC LIMIT 50");
        $stmt->bind_param("s", $q);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    }

    if($res->num_rows == 0){
        sendMessage("موردی پیدا نشد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        setUser();
        exit();
    }

    $keys = [];
    while($row = $res->fetch_assoc()){
        $oid = intval($row['id']);
        $r   = trim((string)$row['remark']);
        $uid = intval($row['userid']);
        if($r === '') $r = 'بدون نام';
        $shortRemark = function_exists('mb_substr') ? mb_substr($r, 0, 32, 'UTF-8') : substr($r, 0, 32);
        if((function_exists('mb_strlen') ? mb_strlen($r, 'UTF-8') : strlen($r)) > 32) $shortRemark .= '…';
        $keys[] = [['text'=>"$shortRemark | $uid | #$oid",'callback_data'=>"updateConfigsOneSelect$oid"]];
    }
    $keys[] = [['text'=>$buttonValues['cancel'],'callback_data'=>"updateConfigsMenu"]];
    $keyboard = json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);

    sendMessage("یکی از کانفیگ‌های پیدا شده را انتخاب کن:", $keyboard);
    // step رو نگه میداریم تا بعد از انتخاب، با cancel برگرده
    exit();
}
if(preg_match('/^updateConfigsOneSelect(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = intval($match[1]);
    $done = farid_updateAndSendOneOrder($oid, $from_id);
    if($done) alert("✅ انجام شد.");
    else alert("⛔️ خطا در به‌روزرسانی.");
    setUser();
    exit();
}

// تنظیم پیام پس از به‌روزرسانی
if($data == "updateConfigsAfterMessage" && ($from_id == $admin || $userInfo['isAdmin'] == true)){


    $current = farid_getUpdateAfterMessage();
    $currentPreview = (strlen(trim($current)) > 0) ? $current : "— خاموش —";

    sendMessage("✉️ پیام پس از به‌روزرسانی کانفیگ\n\nپیام فعلی:\n$currentPreview\n\nلطفاً متن جدید را ارسال کنید.\nبرای غیرفعال‌سازی: /none\nبرای بازگشت به متن پیش‌فرض: /default", $cancelKey);
    setUser("updateConfigsAfterMessage");
    exit();
}
if($userInfo['step'] == "updateConfigsAfterMessage" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $t = trim($text);

    if($t == "/none"){
        farid_setUpdateAfterMessage("");
        sendMessage("✅ پیام پس از به‌روزرسانی غیرفعال شد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
    }elseif($t == "/default"){
        farid_setUpdateAfterMessage(farid_defaultUpdateAfterMessage());
        sendMessage("✅ پیام پیش‌فرض ثبت شد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
    }else{
        farid_setUpdateAfterMessage($t);
        sendMessage("✅ پیام جدید ذخیره شد.", json_encode(['inline_keyboard'=>[
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
    }

    setUser();
    exit();
}


/* ======================================================================
   🔁 پنل تغییر اینباند کانفیگ‌ها (Admin Only)
   - برای انتقال کانفیگ‌های ثبت‌شده از یک inbound قدیمی به inbound جدید همان سرور
   - مرحله‌ای و سبک: هر اجرا چند مورد را منتقل می‌کند و همان پیام وضعیت را edit می‌کند.
   ====================================================================== */

if($data == "inboundMoveMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    editText($message_id, farid_inboundMoveMenuText(), farid_inboundMoveMenuKeys(0), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveSourcesPage_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $page = max(0, intval($m[1]));
    editText($message_id, farid_inboundMoveMenuText(), farid_inboundMoveMenuKeys($page), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveSrc_(\d+)_(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($m[1]);
    $srcInbound = intval($m[2]);
    $page = max(0, intval($m[3]));
    editText($message_id, farid_inboundMoveSourceText($serverId, $srcInbound, $page), farid_inboundMoveSourceKeys($serverId, $srcInbound, $page), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveSrcOrders_(\d+)_(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($m[1]);
    $srcInbound = intval($m[2]);
    $page = max(0, intval($m[3]));
    editText($message_id, farid_inboundMoveSourceText($serverId, $srcInbound, $page), farid_inboundMoveSourceKeys($serverId, $srcInbound, $page), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveBulk_(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($m[1]);
    $srcInbound = intval($m[2]);
    $count = farid_inboundMoveSourceCount($serverId, $srcInbound);
    if($count <= 0){ alert('برای این اینباند کانفیگ فعالی در ربات پیدا نشد.', true); exit(); }
    setUser(); setUser('', 'temp');
    editText($message_id, farid_inboundMoveTargetSelectText('bulk', $serverId, $srcInbound, 0), farid_inboundMoveTargetSelectKeys('bulk', $serverId, $srcInbound, 0), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveOne_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $orderId = intval($m[1]);
    $order = farid_inboundMoveFetchOrder($orderId);
    if(!$order){ alert('کانفیگ پیدا نشد.', true); exit(); }
    $serverId = intval($order['server_id'] ?? 0);
    $srcInbound = intval($order['inbound_id'] ?? 0);
    setUser(); setUser('', 'temp');
    editText($message_id, farid_inboundMoveTargetSelectText('one', $serverId, $srcInbound, $orderId), farid_inboundMoveTargetSelectKeys('one', $serverId, $srcInbound, $orderId), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveTargetBulk_(\d+)_(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($m[1]);
    $srcInbound = intval($m[2]);
    $targetInbound = intval($m[3]);
    $ids = farid_inboundMoveOrderIdsBySource($serverId, $srcInbound, 2000);
    if($serverId <= 0 || $srcInbound <= 0 || $targetInbound <= 0 || empty($ids)){ alert('موردی برای انتقال پیدا نشد.', true); exit(); }
    if($targetInbound == $srcInbound){ alert('inbound مقصد با مبدا یکی است.', true); exit(); }
    $targetInfo = farid_switchFindXuiInboundInfo($serverId, $targetInbound, '');
    if(!$targetInfo){ alert('inbound مقصد داخل پنل پیدا نشد.', true); exit(); }
    $title = 'انتقال همه از inbound ' . $srcInbound . ' به ' . $targetInbound;
    $job = farid_inboundMoveCreateJob($ids, $serverId, $srcInbound, $targetInbound, $from_id, $title);
    $job['status_chat_id'] = intval($chat_id ?? $from_id);
    $job['status_message_id'] = intval($message_id ?? 0);
    farid_inboundMoveSetJob($job);
    editText($message_id, farid_inboundMoveProgressText($job), farid_inboundMoveProgressKeys($job), 'HTML');
    exit();
}

if(preg_match('/^inboundMoveTargetOne_(\d+)_(\d+)$/', $data ?? '', $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $orderId = intval($m[1]);
    $targetInbound = intval($m[2]);
    $order = farid_inboundMoveFetchOrder($orderId);
    if(!$order){ alert('کانفیگ پیدا نشد.', true); exit(); }
    $serverId = intval($order['server_id'] ?? 0);
    $srcInbound = intval($order['inbound_id'] ?? 0);
    if($targetInbound <= 0 || $serverId <= 0 || $srcInbound <= 0){ alert('اطلاعات اینباند ناقص است.', true); exit(); }
    if($targetInbound == $srcInbound){ alert('inbound مقصد با مبدا یکی است.', true); exit(); }
    $targetInfo = farid_switchFindXuiInboundInfo($serverId, $targetInbound, '');
    if(!$targetInfo){ alert('inbound مقصد داخل پنل پیدا نشد.', true); exit(); }
    $job = farid_inboundMoveCreateJob([$orderId], $serverId, $srcInbound, $targetInbound, $from_id, 'انتقال تکی #' . $orderId);
    $job['status_chat_id'] = intval($chat_id ?? $from_id);
    $job['status_message_id'] = intval($message_id ?? 0);
    farid_inboundMoveSetJob($job);
    editText($message_id, farid_inboundMoveProgressText($job), farid_inboundMoveProgressKeys($job), 'HTML');
    exit();
}

if(($userInfo['step'] ?? '') == 'inboundMoveAskTarget' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $targetInbound = intval(trim((string)$text));
    if($targetInbound <= 0){
        sendMessage('❌ فقط آیدی عددی inbound مقصد را بفرست.', $cancelKey, 'HTML');
        exit();
    }
    $temp = (string)($userInfo['temp'] ?? '');
    $parts = explode('|', $temp);
    $ids = [];
    $title = '';
    $serverId = 0;
    $srcInbound = 0;

    if(($parts[0] ?? '') === 'one'){
        $orderId = intval($parts[1] ?? 0);
        $order = farid_inboundMoveFetchOrder($orderId);
        if(!$order){
            setUser(); setUser('', 'temp');
            sendMessage('❌ کانفیگ پیدا نشد.', $removeKeyboard, 'HTML');
            exit();
        }
        $ids = [$orderId];
        $serverId = intval($order['server_id'] ?? 0);
        $srcInbound = intval($order['inbound_id'] ?? 0);
        $title = 'انتقال تکی #' . $orderId;
    }elseif(($parts[0] ?? '') === 'bulk'){
        $serverId = intval($parts[1] ?? 0);
        $srcInbound = intval($parts[2] ?? 0);
        $ids = farid_inboundMoveOrderIdsBySource($serverId, $srcInbound, 2000);
        $title = 'انتقال همه از inbound ' . $srcInbound . ' به ' . $targetInbound;
    }else{
        setUser(); setUser('', 'temp');
        sendMessage('❌ اطلاعات مرحله انتقال از بین رفته؛ دوباره از منو شروع کن.', $removeKeyboard, 'HTML');
        exit();
    }

    if($serverId <= 0 || $srcInbound <= 0 || empty($ids)){
        setUser(); setUser('', 'temp');
        sendMessage('❌ موردی برای انتقال پیدا نشد.', $removeKeyboard, 'HTML');
        exit();
    }
    if($targetInbound == $srcInbound){
        sendMessage('❌ inbound مقصد با مبدا یکی است. آیدی دیگری بفرست.', $cancelKey, 'HTML');
        exit();
    }
    $targetInfo = farid_switchFindXuiInboundInfo($serverId, $targetInbound, '');
    if(!$targetInfo){
        sendMessage('❌ inbound مقصد داخل پنل پیدا نشد. آیدی را درست وارد کن.', $cancelKey, 'HTML');
        exit();
    }

    $job = farid_inboundMoveCreateJob($ids, $serverId, $srcInbound, $targetInbound, $from_id, $title);
    setUser(); setUser('', 'temp');
    $sent = sendMessage(farid_inboundMoveProgressText($job), farid_inboundMoveProgressKeys($job), 'HTML');
    if(is_object($sent) && !empty($sent->ok) && isset($sent->result->message_id)){
        $job['status_chat_id'] = intval($sent->result->chat->id ?? $from_id);
        $job['status_message_id'] = intval($sent->result->message_id);
        farid_inboundMoveSetJob($job);
    }
    exit();
}

if($data == 'inboundMoveStatus' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_inboundMoveGetJob();
    editText($message_id, farid_inboundMoveProgressText($job), farid_inboundMoveProgressKeys($job), 'HTML');
    exit();
}

if($data == 'inboundMoveStop' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_inboundMoveGetJob();
    if(intval($job['state'] ?? 0) != 1){ alert('عملیات فعالی وجود ندارد.', true); exit(); }
    $job['state'] = 0;
    $job['auto_running'] = 0;
    $job['stopped_at'] = time();
    $job['stopped_by'] = $from_id;
    farid_inboundMoveSetJob($job);
    farid_inboundMoveEditProgress($job, true);
    alert('✅ انتقال متوقف شد.');
    exit();
}

if($data == 'inboundMoveRun' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_inboundMoveGetJob();
    if(intval($job['state'] ?? 0) != 1){ alert('عملیات فعالی برای اجرا وجود ندارد.', true); exit(); }
    if(intval($job['auto_running'] ?? 0) == 1){ alert('عملیات در حال اجراست.'); exit(); }

    $job['status_chat_id'] = intval($chat_id ?? $from_id);
    $job['status_message_id'] = intval($message_id ?? 0);
    $job['auto_running'] = 1;
    $job['auto_last_ts'] = time();
    farid_inboundMoveSetJob($job);
    alert('✅ انتقال شروع شد.');
    farid_inboundMoveEditProgress($job);
    farid_finishWebhookResponse();

    $started = microtime(true);
    $maxRuntime = 32;
    $lastProgressEdit = 0.0;
    while(true){
        $job = farid_inboundMoveGetJob();
        if(intval($job['state'] ?? 0) != 1) break;
        farid_inboundMoveRunBatch($job, intval($job['batch'] ?? 5));
        $job = farid_inboundMoveGetJob();
        $nowProgress = microtime(true);
        // برای سرعت بیشتر، بعد از هر batch به تلگرام edit نمی‌زنیم؛ هر چند ثانیه یک‌بار کافی است.
        if(($nowProgress - $lastProgressEdit) >= 2.0 || intval($job['state'] ?? 0) != 1){
            farid_inboundMoveEditProgress($job);
            $lastProgressEdit = $nowProgress;
        }
        if(intval($job['state'] ?? 0) != 1) break;
        if((microtime(true) - $started) >= $maxRuntime) break;
        usleep(120000);
    }
    $job = farid_inboundMoveGetJob();
    $job['auto_running'] = 0;
    $job['auto_last_ts'] = time();
    farid_inboundMoveSetJob($job);
    farid_inboundMoveEditProgress($job, true);
    exit();
}

// 🗑 پنل مستقل پاکسازی کانفیگ‌های تمام‌شده
// این پنل از بخش آپدیت جداست و همه‌ی وضعیت‌ها روی همان پیام قبلی edit می‌شوند.
if($data == "cleanOldConfigsMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText() : "🗑 پنل پاکسازی در دسترس نیست.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'managePanel']]]], JSON_UNESCAPED_UNICODE);
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if(in_array($data ?? '', ["cleanOldConfigsPreview", "cleanOldConfigsRefreshPanel", "cleanOldConfigsQueueStatus"], true) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText() : "🗑 وضعیت در دسترس نیست.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'managePanel']]]], JSON_UNESCAPED_UNICODE);
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if($data == "cleanOldConfigsToggleAuto" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $auto = function_exists('v2raystore_cleanSettingGet') ? (string)(v2raystore_cleanSettingGet("CLEAN_OLD_CONFIGS_AUTO") ?? "off") : (string)(farid_getSettingValue("CLEAN_OLD_CONFIGS_AUTO") ?? "off");
    $auto = ($auto == "on") ? "off" : "on";
    if(function_exists('v2raystore_cleanSettingSet')) v2raystore_cleanSettingSet("CLEAN_OLD_CONFIGS_AUTO", $auto);
    else farid_setSettingValue("CLEAN_OLD_CONFIGS_AUTO", $auto);
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['notice'=>'وضعیت حذف خودکار تغییر کرد.']) : "✅ انجام شد";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if($data == "cleanOldConfigsSetDays" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏱ تعداد روز بعد از اتمام واقعی کانفیگ را بفرست.\n\nمثال: اگر 2 بفرستی، کانفیگ‌هایی حذف می‌شوند که حداقل ۲ روز از تمام شدن زمان یا حجمشان در خود پنل گذشته باشد.\n\nفقط عدد بفرست.", $cancelKey);
    setUser("cleanOldConfigsSetDays");
    exit();
}
if(($userInfo['step'] ?? '') == "cleanOldConfigsSetDays" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("⛔️ فقط عدد بفرست.");
        exit();
    }
    $days = intval($text);
    if($days < 1) $days = 1;
    if($days > 3650) $days = 3650;
    if(function_exists('v2raystore_cleanSettingSet')) v2raystore_cleanSettingSet("CLEAN_OLD_CONFIGS_DAYS", strval($days));
    else farid_setSettingValue("CLEAN_OLD_CONFIGS_DAYS", strval($days));
    setUser();
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['notice'=>"بازه پاکسازی روی $days روز تنظیم شد."]) : "✅ بازه پاکسازی روی $days روز تنظیم شد.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>'🗑 پنل پاکسازی','callback_data'=>'cleanOldConfigsMenu']]]], JSON_UNESCAPED_UNICODE);
    sendMessage($txt, $keys, 'HTML');
    exit();
}

if($data == "cleanOldConfigsScanRunOnce" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    if(function_exists('v2raystore_getCleanOldPanelScanSession') && function_exists('v2raystore_startCleanOldPanelScan')){
        $session = v2raystore_getCleanOldPanelScanSession();
        if(empty($session['active']) || intval($session['active']) !== 1){
            v2raystore_startCleanOldPanelScan('manual', true);
        }
    }
    $scanRes = function_exists('v2raystore_runCleanOldPanelScanStep') ? v2raystore_runCleanOldPanelScanStep(20, 18, false) : ['processed'=>0];
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['scan'=>$scanRes, 'notice'=>'یک مرحله بررسی پنل انجام شد.']) : "یک مرحله اجرا شد.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if($data == "cleanOldConfigsScanStop" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(function_exists('v2raystore_stopCleanOldPanelScan')) v2raystore_stopCleanOldPanelScan();
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['notice'=>'بررسی مرحله‌ای پنل متوقف شد.']) : "⛔️ متوقف شد.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if(in_array($data ?? '', ["cleanOldConfigsDoDelete", "cleanOldConfigsQueueRunOnce"], true) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    // دکمه «ثبت صف حذف» حذف شده؛ کانفیگ‌های شناسایی‌شده خودکار داخل لیست آماده حذف می‌آیند.
    // این دکمه فقط حذف مرحله‌ای را شروع/ادامه می‌دهد و همان پیام پنل را edit می‌کند.
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);

    $days = intval(function_exists('v2raystore_cleanSettingGet') ? (v2raystore_cleanSettingGet("CLEAN_OLD_CONFIGS_DAYS") ?? 10) : (farid_getSettingValue("CLEAN_OLD_CONFIGS_DAYS") ?? 10));
    if($days <= 0) $days = 10;

    $job = function_exists('v2raystore_getCleanOldConfigsJob') ? v2raystore_getCleanOldConfigsJob() : ['state'=>0];
    if(empty($job['state']) || intval($job['state']) !== 1){
        $total = function_exists('v2raystore_quickCountCleanOldConfigCandidates') ? v2raystore_quickCountCleanOldConfigCandidates($days, 'panel_expiry') : 0;
        if($total <= 0){
            $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['notice'=>'فعلاً لیست آماده حذف خالی است؛ بررسی پنل را شروع کن تا موارد تمام‌شده مستقیم وارد لیست شوند.']) : "✅ موردی برای حذف نیست.";
            $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
            editText($message_id, $txt, $keys, 'HTML');
            exit();
        }
        if(function_exists('v2raystore_startCleanOldConfigsJob')){
            v2raystore_startCleanOldConfigsJob($days, 'panel_expiry', $from_id, $total);
        }
    }

    $deleteRes = function_exists('v2raystore_processCleanOldConfigsJob') ? v2raystore_processCleanOldConfigsJob(5, 9, true) : ['processed'=>0];
    $notice = 'حذف مرحله‌ای شروع/ادامه پیدا کرد. از این به بعد worker همان پیام را بروزرسانی می‌کند.';
    if(is_array($deleteRes) && !empty($deleteRes['message'])) $notice = (string)$deleteRes['message'];
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['delete'=>$deleteRes, 'notice'=>$notice]) : "یک مرحله حذف اجرا شد.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

if($data == "cleanOldConfigsQueueStop" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(function_exists('v2raystore_stopCleanOldConfigsJob')) v2raystore_stopCleanOldConfigsJob();
    if(function_exists('v2raystore_registerCleanOldUiMessage')) v2raystore_registerCleanOldUiMessage($from_id, $message_id);
    $txt = function_exists('v2raystore_buildCleanOldControlPanelText') ? v2raystore_buildCleanOldControlPanelText(['notice'=>'صف حذف متوقف شد.']) : "⛔️ صف متوقف شد.";
    $keys = function_exists('v2raystore_cleanOldControlPanelKeyboard') ? v2raystore_cleanOldControlPanelKeyboard() : null;
    editText($message_id, $txt, $keys, 'HTML');
    exit();
}

// وضعیت عملیات
if($data == "updateConfigsStatus" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state']??0) != 1){
        alert("⛔️ صف فعالی وجود ندارد.");
        exit();
    }

    $total = farid_getUpdateConfigsTotal($job);
    $offset = intval($job['offset']??0);
    $left = max(0, $total - $offset);

    $modeTitle = "نامشخص";
    if(($job['mode']??'') == "all_active") $modeTitle = "همه کانفیگ‌های فعال";
    elseif(($job['mode']??'') == "user") $modeTitle = "کانفیگ‌های کاربر: " . ($job['userid']??'-');
    elseif(in_array(($job['mode']??''), ["ids","links","sub_ids"], true)) $modeTitle = ($job['filter_title'] ?? "فیلتر سفارشی");

    $stats = $job['stats'] ?? [];
    $updated = intval($stats['updated'] ?? 0);
    $failed  = intval($stats['failed'] ?? 0);

    sendMessage("📊 وضعیت عملیات به‌روزرسانی کانفیگ‌ها\n\n🎯 نوع: $modeTitle\n📌 کل: $total\n✅ انجام‌شده: $offset\n⏳ باقی‌مانده: $left\n\n✅ موفق: $updated\n⛔️ ناموفق: $failed", json_encode(['inline_keyboard'=>[
        [['text'=>"🚀 شروع اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
        [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

// توقف عملیات
if($data == "updateConfigsStop" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();
    if(intval($job['state'] ?? 0) != 1){
        alert("⛔️ عملیات فعالی برای توقف وجود ندارد.");
        exit();
    }

    $job['state'] = 0;
    $job['stopped_at'] = time();
    $job['stopped_by'] = $from_id;
    $job['auto_running'] = 0;
    $job['auto_last_ts'] = time();

    farid_setUpdateConfigsJob($job);
    farid_editUpdateConfigsProgressMessage($job, true);


    alert("✅ عملیات متوقف شد.");
    exit();
}


if($data == "updateConfigsRun" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $job = farid_getUpdateConfigsJob();

    if(intval($job['state'] ?? 0) != 1){
        alert("⛔️ عملیات فعالی یافت نشد. لطفاً ابتدا از منو یک عملیات به‌روزرسانی ایجاد کنید.");
        exit();
    }

    // جلوگیری از اجرای هم‌زمان چندباره
    if(intval($job['auto_running'] ?? 0) == 1){
        alert("ℹ️ عملیات در حال اجراست. برای توقف، روی «توقف عملیات» کلیک کنید.");
        exit();
    }

    // Batch ثابت ۱۰تایی (طبق درخواست)
    $job['batch'] = 10;

    // ذخیره پیام وضعیت برای ویرایش لحظه‌ای
    $job['status_chat_id'] = intval($chat_id ?? 0);
    $job['status_message_id'] = intval($message_id ?? 0);
    $job['auto_running'] = 1;
    $job['auto_last_ts'] = time();

    farid_setUpdateConfigsJob($job);
    alert("✅ عملیات آغاز شد.");


    // یک بار پیام را به حالت «در حال اجرا» تبدیل می‌کنیم
    farid_editUpdateConfigsProgressMessage($job);

    // پاسخ وبهوک را سریع تمام می‌کنیم تا تلگرام مجدداً درخواست را تکرار نکند
    farid_finishWebhookResponse();

    // اجرای خودکار در بازه‌های امن؛ از ماندن طولانی PHP و هنگ سرور جلوگیری می‌کند.
    $runStartedAt = microtime(true);
    $maxRuntimeSeconds = farid_updateConfigsMaxRuntimeSeconds();
    while(true){
        $job = farid_getUpdateConfigsJob();

        // اگر توسط ادمین متوقف شده باشد یا تمام شده باشد
        if(intval($job['state'] ?? 0) != 1){
            break;
        }

        // اجرای خودکار - بدون بازنویسی وضعیت در DB (برای جلوگیری از تداخل با دکمه توقف)

        $batch = intval($job['batch'] ?? 10);
        if($batch < 1) $batch = 10;
        if($batch > 25) $batch = 25;

        // اجرای یک مرحله
        farid_runUpdateConfigsBatch($job, $batch);

        // به‌روزرسانی پیام وضعیت
        $job = farid_getUpdateConfigsJob();
        farid_editUpdateConfigsProgressMessage($job);

        // اگر تمام شد
        if(intval($job['state'] ?? 0) != 1){
            break;
        }

        // اگر بازه امن تمام شد، عملیات را خراب نمی‌کنیم؛ فقط برای ادامه‌ی بعدی آزادش می‌کنیم.
        if((microtime(true) - $runStartedAt) >= $maxRuntimeSeconds){
            break;
        }

        // کمی مکث برای جلوگیری از محدودیت ویرایش پیام
        usleep(250000);
    }

    // پایان این بازه اجرای امن (تکمیل، توقف یا آماده ادامه)
    $job = farid_getUpdateConfigsJob();
    $job['auto_running'] = 0;
    $job['auto_last_ts'] = time();
    farid_setUpdateConfigsJob($job);

    // ارسال گزارش پایان کار فقط وقتی واقعاً عملیات تمام/متوقف شده باشد.
    if(intval($job['state'] ?? 0) != 1){
        farid_sendUpdateConfigsFinalReportIfNeeded($job);
    }

    // به‌روزرسانی پیام وضعیت نهایی این بازه
    farid_editUpdateConfigsProgressMessage($job, true);

    exit();
}


/* ======================================================================
   📩 پیام به کاربران تمام/نزدیک اتمام (X-UI)
   - لیست کردن اکانت‌هایی که «حجم» یا «تاریخ» آنها تمام شده ولی هنوز در پنل هستند
   - لیست کردن اکانت‌هایی که «نزدیک به اتمام» هستند (مثلاً ۳ روز یا ۳ گیگ باقی‌مانده)
   - ارسال پیام گروهی به کاربران (بر اساس دیتابیس ربات)
   ====================================================================== */

// منوی اصلی پیام به کاربران
if($data == "xuiMsgMenu" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();

    $days = farid_xuiMsg_getNearDaysThreshold();
    $gb   = farid_xuiMsg_getNearGbThreshold();

    $txt = "📩 پیام به کاربران (X-UI)\n\n".
           "در این بخش می‌تونی کاربران \"تمام شده\" یا \"نزدیک به اتمام\" رو (از روی اطلاعات پنل X-UI) پیدا کنی و براشون پیام ارسال کنی.\n\n".
           "⚙️ آستانه نزدیک به اتمام:\n".
           "⏳ روز: $days\n".
           "📦 حجم: $gb گیگ\n\n".
           "یک گزینه رو انتخاب کن:";

    $keys = json_encode(['inline_keyboard'=>[
        [["text"=>"📛 لیست تمام‌شده‌ها (حجم/تاریخ)","callback_data"=>"xuiMsgExpired_0"]],
        [["text"=>"⏳ لیست نزدیک به اتمام","callback_data"=>"xuiMsgNear_0"]],
        [["text"=>"⚙️ تنظیم آستانه","callback_data"=>"xuiMsgSettings"]],
        [["text"=>$buttonValues['back_button'],"callback_data"=>"managePanel"]],
    ]], JSON_UNESCAPED_UNICODE);

    editText($message_id, $txt, $keys);
    exit();
}

// تنظیم آستانه‌ها
if($data == "xuiMsgSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    $days = farid_xuiMsg_getNearDaysThreshold();
    $gb   = farid_xuiMsg_getNearGbThreshold();

    $txt = "⚙️ تنظیم آستانه نزدیک به اتمام\n\n".
           "⏳ روز فعلی: $days\n".
           "📦 حجم فعلی: $gb گیگ\n\n".
           "نکته: اگر یکی از مقادیر را 0 بگذاری، آن معیار در «نزدیک به اتمام» لحاظ نمی‌شود.";

    $keys = json_encode(['inline_keyboard'=>[
        [["text"=>"⏳ تغییر روز","callback_data"=>"xuiMsgSetDays"]],
        [["text"=>"📦 تغییر حجم (گیگ)","callback_data"=>"xuiMsgSetGb"]],
        [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
    ]], JSON_UNESCAPED_UNICODE);

    editText($message_id, $txt, $keys);
    exit();
}

if($data == "xuiMsgSetDays" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ تعداد روزِ نزدیک به اتمام رو بفرست (مثلاً 3).\nاگر می‌خوای معیار روز غیرفعال بشه: 0", $cancelKey);
    setUser("xuiMsgSetDays");
    exit();
}
if($userInfo['step'] == "xuiMsgSetDays" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("⛔️ فقط عدد بفرست.");
        exit();
    }
    $days = intval($text);
    if($days < 0) $days = 0;
    if($days > 3650) $days = 3650;

    farid_setSettingValue("XUI_NEAR_EXPIRE_DAYS", strval($days));
    setUser();
    sendMessage("✅ ذخیره شد. آستانه روز: $days", json_encode(['inline_keyboard'=>[
        [["text"=>"⚙️ تنظیمات","callback_data"=>"xuiMsgSettings"]],
        [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

if($data == "xuiMsgSetGb" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("📦 آستانه حجمِ نزدیک به اتمام رو به گیگ بفرست (مثلاً 3).\nاگر می‌خوای معیار حجم غیرفعال بشه: 0", $cancelKey);
    setUser("xuiMsgSetGb");
    exit();
}
if($userInfo['step'] == "xuiMsgSetGb" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("⛔️ فقط عدد بفرست.");
        exit();
    }
    $gb = floatval($text);
    if($gb < 0) $gb = 0;
    if($gb > 10240) $gb = 10240;

    // ذخیره به صورت عدد (رشته)
    farid_setSettingValue("XUI_NEAR_EXPIRE_GB", strval($gb));
    setUser();
    sendMessage("✅ ذخیره شد. آستانه حجم: $gb گیگ", json_encode(['inline_keyboard'=>[
        [["text"=>"⚙️ تنظیمات","callback_data"=>"xuiMsgSettings"]],
        [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

// لیست «تمام شده‌ها»
if(preg_match('/^xuiMsgExpired_(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    $offset = intval($m[1]);
    if($offset < 0) $offset = 0;
    $limit = 15;

    $accounts = farid_xuiMsg_getExpiredAccounts();
    $total = count($accounts);

    if($total <= 0){
        editText($message_id, "✅ موردی پیدا نشد (اکانتِ تمام‌شده‌ای که هنوز داخل پنل X-UI باشد).", json_encode(['inline_keyboard'=>[
            [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        exit();
    }

    $pageItems = array_slice($accounts, $offset, $limit);
    $lines = [];
    $i = $offset + 1;
    foreach($pageItems as $acc){
        $order = farid_xuiMsg_findOrderByUuid($acc['server_id'], $acc['uuid']);
        $lines[] = farid_xuiMsg_formatAccountLine($i, $acc, $order, true);
        $i++;
    }

    $fromNum = $offset + 1;
    $toNum = min($offset + $limit, $total);

    $txt = "📛 لیست تمام‌شده‌ها (X-UI)\n\n".
           "🔢 تعداد کل: $total\n".
           "📄 نمایش: $fromNum تا $toNum\n\n".
           implode("\n", $lines) .
           "\n\n⚠️ فقط مواردی که UserID در دیتابیس ربات دارند قابل پیام دادن هستند.";

    $navRow = [];
    if($offset > 0){
        $prev = max(0, $offset - $limit);
        $navRow[] = ["text"=>"◀️ قبلی","callback_data"=>"xuiMsgExpired_{$prev}"];
    }
    if(($offset + $limit) < $total){
        $next = $offset + $limit;
        $navRow[] = ["text"=>"▶️ بعدی","callback_data"=>"xuiMsgExpired_{$next}"];
    }

    $kb = [];
    $kb[] = [["text"=>"✉️ ارسال پیام به کاربران این لیست","callback_data"=>"xuiMsgSendExpired"]];
    if(!empty($navRow)) $kb[] = $navRow;
    $kb[] = [["text"=>"🔄 بروزرسانی","callback_data"=>"xuiMsgExpired_{$offset}"]];
    $kb[] = [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]];

    editText($message_id, $txt, json_encode(['inline_keyboard'=>$kb], JSON_UNESCAPED_UNICODE));
    exit();
}

// لیست «نزدیک به اتمام»
if(preg_match('/^xuiMsgNear_(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    $offset = intval($m[1]);
    if($offset < 0) $offset = 0;
    $limit = 15;

    $days = farid_xuiMsg_getNearDaysThreshold();
    $gb   = farid_xuiMsg_getNearGbThreshold();

    $accounts = farid_xuiMsg_getNearExpireAccounts($days, $gb);
    $total = count($accounts);

    if($total <= 0){
        $t = "✅ موردی پیدا نشد (اکانتِ نزدیک به اتمام با آستانه فعلی).\n\n".
             "⚙️ آستانه فعلی:\n⏳ روز: $days\n📦 حجم: $gb گیگ";
        editText($message_id, $t, json_encode(['inline_keyboard'=>[
            [["text"=>"⚙️ تنظیم آستانه","callback_data"=>"xuiMsgSettings"]],
            [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        exit();
    }

    $pageItems = array_slice($accounts, $offset, $limit);
    $lines = [];
    $i = $offset + 1;
    foreach($pageItems as $acc){
        $order = farid_xuiMsg_findOrderByUuid($acc['server_id'], $acc['uuid']);
        $lines[] = farid_xuiMsg_formatAccountLine($i, $acc, $order, false);
        $i++;
    }

    $fromNum = $offset + 1;
    $toNum = min($offset + $limit, $total);

    $txt = "⏳ لیست نزدیک به اتمام (X-UI)\n\n".
           "⚙️ آستانه: $days روز یا $gb گیگ\n".
           "🔢 تعداد کل: $total\n".
           "📄 نمایش: $fromNum تا $toNum\n\n".
           implode("\n", $lines) .
           "\n\n⚠️ فقط مواردی که UserID در دیتابیس ربات دارند قابل پیام دادن هستند.";

    $navRow = [];
    if($offset > 0){
        $prev = max(0, $offset - $limit);
        $navRow[] = ["text"=>"◀️ قبلی","callback_data"=>"xuiMsgNear_{$prev}"];
    }
    if(($offset + $limit) < $total){
        $next = $offset + $limit;
        $navRow[] = ["text"=>"▶️ بعدی","callback_data"=>"xuiMsgNear_{$next}"];
    }

    $kb = [];
    $kb[] = [["text"=>"✉️ ارسال پیام به کاربران این لیست","callback_data"=>"xuiMsgSendNear"]];
    if(!empty($navRow)) $kb[] = $navRow;
    $kb[] = [["text"=>"🔄 بروزرسانی","callback_data"=>"xuiMsgNear_{$offset}"]];
    $kb[] = [["text"=>"⚙️ تنظیم آستانه","callback_data"=>"xuiMsgSettings"]];
    $kb[] = [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]];

    editText($message_id, $txt, json_encode(['inline_keyboard'=>$kb], JSON_UNESCAPED_UNICODE));
    exit();
}

// ارسال پیام برای تمام‌شده‌ها
if($data == "xuiMsgSendExpired" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("✉️ متن پیامی که می‌خوای برای کاربرانِ تمام‌شده ارسال بشه رو بفرست.\n\nبرای لغو: دکمه لغو", $cancelKey);
    setUser("xuiMsgSendExpired");
    exit();
}
if($userInfo['step'] == "xuiMsgSendExpired" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $msg = trim($text);
    if($msg === ""){
        sendMessage("⛔️ متن پیام خالیه.");
        exit();
    }

    setUser();
    sendMessage("⏳ شروع شد... در حال آماده‌سازی لیست و ارسال پیام.");

    // جلوگیری از retry تلگرام
    if(function_exists('farid_finishWebhookResponse')){
        farid_finishWebhookResponse();
    }

    $accounts = farid_xuiMsg_getExpiredAccounts();
    $report = farid_xuiMsg_sendMessageToAccounts($accounts, $msg);

    $repTxt = "✅ گزارش ارسال پیام (تمام‌شده‌ها)\n\n".
              "👥 کاربران هدف: {$report['target_users']}\n".
              "📨 ارسال موفق: {$report['sent']}\n".
              "⛔️ ناموفق: {$report['failed']}\n".
              "❓ اکانت‌های بدون UserID در دیتابیس: {$report['unknown_accounts']}\n".
              "🔢 کل اکانت‌های لیست: {$report['total_accounts']}";

    if(!empty($report['failed_user_ids'])){
        $repTxt .= "\n\n⚠️ UserIDهای ناموفق (تا 20 مورد):\n" . implode(", ", array_slice($report['failed_user_ids'], 0, 20));
    }

    sendMessage($repTxt, json_encode(['inline_keyboard'=>[
        [["text"=>"📛 مشاهده لیست","callback_data"=>"xuiMsgExpired_0"]],
        [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

// ارسال پیام برای نزدیک به اتمام
if($data == "xuiMsgSendNear" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("✉️ متن پیامی که می‌خوای برای کاربرانِ نزدیک به اتمام ارسال بشه رو بفرست.\n\nبرای لغو: دکمه لغو", $cancelKey);
    setUser("xuiMsgSendNear");
    exit();
}
if($userInfo['step'] == "xuiMsgSendNear" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $msg = trim($text);
    if($msg === ""){
        sendMessage("⛔️ متن پیام خالیه.");
        exit();
    }

    setUser();
    sendMessage("⏳ شروع شد... در حال آماده‌سازی لیست و ارسال پیام.");

    if(function_exists('farid_finishWebhookResponse')){
        farid_finishWebhookResponse();
    }

    $days = farid_xuiMsg_getNearDaysThreshold();
    $gb   = farid_xuiMsg_getNearGbThreshold();
    $accounts = farid_xuiMsg_getNearExpireAccounts($days, $gb);
    $report = farid_xuiMsg_sendMessageToAccounts($accounts, $msg);

    $repTxt = "✅ گزارش ارسال پیام (نزدیک به اتمام)\n\n".
              "⚙️ آستانه: $days روز یا $gb گیگ\n\n".
              "👥 کاربران هدف: {$report['target_users']}\n".
              "📨 ارسال موفق: {$report['sent']}\n".
              "⛔️ ناموفق: {$report['failed']}\n".
              "❓ اکانت‌های بدون UserID در دیتابیس: {$report['unknown_accounts']}\n".
              "🔢 کل اکانت‌های لیست: {$report['total_accounts']}";

    if(!empty($report['failed_user_ids'])){
        $repTxt .= "\n\n⚠️ UserIDهای ناموفق (تا 20 مورد):\n" . implode(", ", array_slice($report['failed_user_ids'], 0, 20));
    }

    sendMessage($repTxt, json_encode(['inline_keyboard'=>[
        [["text"=>"⏳ مشاهده لیست","callback_data"=>"xuiMsgNear_0"]],
        [["text"=>"⚙️ تنظیم آستانه","callback_data"=>"xuiMsgSettings"]],
        [["text"=>"⬅️ بازگشت","callback_data"=>"xuiMsgMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}




if(preg_match('/^s2a(?:\|(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config))?$/', $userInfo['step'] ?? '', $broadcastStepMatch) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $target = farid_normalizeBroadcastTarget($broadcastStepMatch[1] ?? 'all');
    $targetTitle = farid_getBroadcastTargetTitle($target);

    setUser();

    if($fileid !== null) {
        $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `text`, `file_id`, `target_type`, `pin_after_send`) VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param('ssss', $filetype, $caption, $fileid, $target);
    }
    else{
        $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `text`, `target_type`, `pin_after_send`) VALUES ('text', ?, ?, 0)");
        $stmt->bind_param("ss", $text, $target);
    }
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    sendMessage('⏳ پیام دریافت شد و آماده بررسی است.', $removeKeyboard);
    sendMessage("📨 پیش‌نمایش ارسال همگانی

🎯 گروه مخاطب: <b>$targetTitle</b>

برای سبک‌ماندن ربات، تعداد مخاطبان داخل صف محاسبه و به‌روزرسانی می‌شود.

بعد از ارسال، پیام برای کاربران پین شود؟", json_encode(['inline_keyboard'=>[
        [['text'=>"✅ ارسال و پین شود", 'callback_data'=>"yesSend2AllPin" . $id, 'style'=>'success']],
        [['text'=>"📨 ارسال بدون پین", 'callback_data'=>"yesSend2All" . $id, 'style'=>'primary'], ['text'=>"❌ لغو ارسال", 'callback_data'=>"noDontSend2all" . $id, 'style'=>'danger']]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}
if(preg_match('/^noDontSend2all(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `send_list` WHERE `id` = ?");
    $stmt->bind_param('i', $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id,'✅ ارسال همگانی لغو شد.',getAdminKeysPlus());
    exit();
}
if(preg_match('/^yesSend2All(Pin)?(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $queueId = intval($match[2]);
    $pinAfterSend = !empty($match[1]) ? 1 : 0;
    $stmt = $connection->prepare("SELECT `target_type` FROM `send_list` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param('i', $queueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $target = farid_normalizeBroadcastTarget($row['target_type'] ?? 'all');
    $targetTitle = farid_getBroadcastTargetTitle($target);

    $nowForQueue = time();
    $stmt = $connection->prepare("UPDATE `send_list` SET `state` = 1, `pin_after_send` = ?, `offset` = 0, `last_user_id` = 0, `total_count` = 0, `sent_count` = 0, `failed_count` = 0, `blocked_count` = 0, `last_report_at` = 0, `pause_until` = 0, `started_at` = 0, `updated_at` = ? WHERE `id` = ?") ;
    $stmt->bind_param('iii', $pinAfterSend, $nowForQueue, $queueId);
    $stmt->execute();
    $stmt->close();

    $pinText = $pinAfterSend ? "
📌 پین بعد از ارسال: <b>فعال</b>" : "
📌 پین بعد از ارسال: <b>غیرفعال</b>";
    editText($message_id,"⏳ ارسال همگانی آغاز شد.

🎯 گروه مخاطب: <b>$targetTitle</b>$pinText

ارسال و شمارش مخاطبان به‌صورت مرحله‌ای انجام می‌شود تا ربات هنگ نکند.",getAdminKeysPlus(), 'HTML');
    exit();
}
if($data=="forwardToAll" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $queueText = function_exists('farid_getActiveBroadcastQueueText') ? farid_getActiveBroadcastQueueText() : null;
    if($queueText !== null){
        $queue = function_exists('farid_getActiveBroadcastQueue') ? farid_getActiveBroadcastQueue() : null;
        $key = ($queue && function_exists('farid_getBroadcastStatusKeyboard')) ? farid_getBroadcastStatusKeyboard($queue['id']) : null;
        sendMessage($queueText, $key, 'HTML');
        exit();
    }

    setUser();
    // بعد از حذف پیام قبلی، editText روی همان message_id شکست می‌خورد؛ پس منوی جدید را جدا ارسال می‌کنیم.
    delMessage();
    sendMessage("📤 فوروارد همگانی\n\nلطفاً مشخص کنید پیام فورواردی برای کدام گروه از کاربران ارسال شود.", farid_getBroadcastTargetKeyboard('forward'), 'HTML');
    exit();
}
if(preg_match('/^broadcastTargetForward_(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $target = farid_normalizeBroadcastTarget($match[1]);
    $title = farid_getBroadcastTargetTitle($target);

    delMessage();
    setUser('forwardToAll|' . $target);
    sendMessage("📤 لطفاً پیامی را که می‌خواهید فوروارد شود ارسال کنید.

🎯 گروه مخاطب: <b>$title</b>

شمارش مخاطبان داخل صف انجام می‌شود تا کلیک روی دکمه باعث هنگ نشود.", $cancelKey, 'HTML');
    exit();
}
if(preg_match('/^forwardToAll(?:\|(all|approved|buyers|access_code|no_config|no_purchase_30|left_channel|inactive_config))?$/', $userInfo['step'] ?? '', $forwardStepMatch) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $target = farid_normalizeBroadcastTarget($forwardStepMatch[1] ?? 'all');
    $targetTitle = farid_getBroadcastTargetTitle($target);

    $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `message_id`, `chat_id`, `target_type`, `pin_after_send`) VALUES ('forwardall', ?, ?, ?, 0)");
    $stmt->bind_param('sss', $message_id, $chat_id, $target);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    setUser();
    sendMessage('⏳ پیام فورواردی دریافت شد و آماده بررسی است.', $removeKeyboard);
    sendMessage("📤 پیش‌نمایش فوروارد همگانی

🎯 گروه مخاطب: <b>$targetTitle</b>

برای سبک‌ماندن ربات، تعداد مخاطبان داخل صف محاسبه و به‌روزرسانی می‌شود.

بعد از فوروارد، پیام برای کاربران پین شود؟", json_encode(['inline_keyboard'=>[
        [['text'=>"✅ فوروارد و پین شود", 'callback_data'=>"yesSend2AllPin" . $id, 'style'=>'success']],
        [['text'=>"📤 فوروارد بدون پین", 'callback_data'=>"yesSend2All" . $id, 'style'=>'primary'], ['text'=>"❌ لغو", 'callback_data'=>"noDontSend2all" . $id, 'style'=>'danger']]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}
if(preg_match('/selectServer(?<serverId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    $sid = intval($match['serverId']);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($sid, $from_id, $userInfo ?? null, $match['buyType'])){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }

    if(preg_match('/^renew(\d+)$/', $match['buyType'], $renewServerMatch)){
        $renewOrderId = intval($renewServerMatch[1]);
        $stmt = $connection->prepare("SELECT `server_id`, `userid`, `status` FROM `orders_list` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $renewOrderId);
        $stmt->execute();
        $renewOrderForServer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewOrderForServer || intval($renewOrderForServer['status'] ?? 0) != 1 || (intval($renewOrderForServer['userid']) != intval($from_id) && $from_id != $admin && ($userInfo['isAdmin'] ?? false) != true)){
            alert($mainValues['config_not_found'] ?? 'کانفیگ پیدا نشد.', true);
            exit();
        }
        if(intval($renewOrderForServer['server_id'] ?? 0) !== $sid){
            alert('برای تمدید فقط پلن‌های همان سرور فعلی سرویس قابل انتخاب است. اگر می‌خواهی سرور را عوض کنی، اول تغییر لوکیشن بده.', true);
            exit();
        }
        $stmt = $connection->prepare("SELECT `ucount`, `active`, `state` FROM `server_info` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $renewServerInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewServerInfo || intval($renewServerInfo['active'] ?? 0) != 1 || intval($renewServerInfo['state'] ?? 0) != 1 || intval($renewServerInfo['ucount'] ?? 0) <= 0){
            alert('سرور فعلی ظرفیت ندارد. اول تغییر لوکیشن بده، بعد تمدید کن.', true);
            exit();
        }
    }
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert($mainValues['category_not_avilable']);
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 AND COALESCE(`price`,0) != 0");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}_{$match['buyType']}"];
        }
        if(empty($keyboard)){
            alert($mainValues['category_not_avilable']);exit;
        }
        alert($mainValues['receive_categories']);

        $backCallback = ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"));
        if(preg_match('/^renew\d+$/', $match['buyType'])) $backCallback = 'mySubscriptions';
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => $backCallback];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_category'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCategory(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = intval($match['categoryId']);
    $sid = intval($match['serverId']);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($sid, $from_id, $userInfo ?? null, $match['buyType'])){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }

    if(preg_match('/^renew(\d+)$/', $match['buyType'], $renewCategoryMatch)){
        $renewOrderId = intval($renewCategoryMatch[1]);
        $stmt = $connection->prepare("SELECT `server_id`, `userid`, `status` FROM `orders_list` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $renewOrderId);
        $stmt->execute();
        $renewOrderForCategory = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewOrderForCategory || intval($renewOrderForCategory['status'] ?? 0) != 1 || (intval($renewOrderForCategory['userid']) != intval($from_id) && $from_id != $admin && ($userInfo['isAdmin'] ?? false) != true)){
            alert($mainValues['config_not_found'] ?? 'کانفیگ پیدا نشد.', true);
            exit();
        }
        if(intval($renewOrderForCategory['server_id'] ?? 0) !== $sid){
            alert('برای تمدید فقط پلن‌های همان سرور فعلی سرویس قابل انتخاب است. اگر می‌خواهی سرور را عوض کنی، اول تغییر لوکیشن بده.', true);
            exit();
        }
        $stmt = $connection->prepare("SELECT `ucount`, `active`, `state` FROM `server_info` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $renewServerInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewServerInfo || intval($renewServerInfo['active'] ?? 0) != 1 || intval($renewServerInfo['state'] ?? 0) != 1 || intval($renewServerInfo['ucount'] ?? 0) <= 0){
            alert('سرور فعلی ظرفیت ندارد. اول تغییر لوکیشن بده، بعد تمدید کن.', true);
            exit();
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `price` != 0 and COALESCE(`price`,0) != 0 and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_plan_available']); 
    }else{
        alert($mainValues['receive_plans']);
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much" || preg_match('/^renew\d+$/', $match['buyType']))){
                $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $id, $sid, $file['volume'] ?? 0, 1) : $price;
            }
            $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($botState['plandelkhahState'] == "on" && $match['buyType'] != "much"){
	        $keyboard[] = ['text' => $mainValues['buy_custom_plan'], 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_plan'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCustomPlan(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = intval($match['serverId']);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($sid, $from_id, $userInfo ?? null, $match['buyType'])){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 AND COALESCE(`price`,0) != 0 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    alert($mainValues['receive_plans']);
    $keyboard = [];
    while($file = $respd->fetch_assoc()){
        $id = $file['id'];
        $name = preg_replace("/پلن\s(\d+)\sگیگ\s/","",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}_{$match['buyType']}"];
    }
    $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['select_one_plan_to_edit'], json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/selectCustomePlan(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id == $admin)){
	delMessage();
	$price = $botState['gbPrice'];
	if(($match['buyType'] == "one" || preg_match('/^renew\d+$/', $match['buyType'])) && $userInfo['is_agent'] == true){ 
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        if(function_exists('v2raystore_isAgentPricingPerGb') && v2raystore_isAgentPricingPerGb($userInfo, $match[1], $serverId)){
            $price = v2raystore_applyAgentPricing($price, $userInfo, $match[1], $serverId, 1, 1);
        }else{
            $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $match[1], $serverId, 0, 1) : $price;
        }
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, $mainValues['customer_custome_plan_volume']),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
}
if(preg_match('/selectCustomPlanGB(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡|لطفاً فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفاً عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(" عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }
    
    $id = $match['planId'];
    $price = $botState['dayPrice'];
	if(($match['buyType'] == "one" || preg_match('/^renew\d+$/', $match['buyType'])) && $userInfo['is_agent'] == true){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        if(function_exists('v2raystore_isAgentPricingPerGb') && v2raystore_isAgentPricingPerGb($userInfo, $id, $serverId)){
            $price = 0;
        }else{
            $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $id, $serverId, 0, 1) : $price;
        }
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, $mainValues['customer_custome_plan_day']));
	setUser("selectCustomPlanDay" . $id . "_" . $match['categoryId'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/selectCustomPlanDay(?<planId>\d+)_(?<categoryId>\d+)_(?<accountCount>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡|لطفاً فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفاً عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage("عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }

	sendMessage($mainValues['customer_custome_plan_name']);
	setUser("enterCustomPlanName" . $match['planId'] . "_" . $match['categoryId'] . "_" . $match['accountCount'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/^discountCustomPlanDay(\d+)/',$userInfo['step'], $match) || preg_match('/enterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $rowId = $match[1];

        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $price = $payInfo['price'];
        $id = $payInfo['type'];
    	$volume = $payInfo['volume'];
        $days = $payInfo['day'];
        $stmt->close();
            
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
            
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $price * $amount / 100;
                    $price -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $price -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($price < 0) $price = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $price, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"v2raystore"]
                        ],
                    ]]);
            sendMessage(
                str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                ,$keys,null,$admin);
                }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
    }else{
        $id = $match[1];
    	$call_id = $match[2];
    	$volume = $match[3];
        $days = $match[4];
        if($match['buyType'] != "much"){
            if(preg_match('/^[a-z]+[0-9]+$/',$text)){} else{
                sendMessage($mainValues['incorrect_config_name']);
                exit();
            }
        }
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1 AND COALESCE(`price`,0) != 0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$respd){
        alert($mainValues['no_plan_available'] ?? 'پلن پیدا نشد.', true);
        exit();
    }
    $planServerIdForSale = intval($respd['server_id'] ?? 0);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($planServerIdForSale, $from_id, $userInfo ?? null, $match['buyType'] ?? '')){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $token = base64_encode("{$from_id}.{$id}");

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $discountPrice = 0;
        $gbPrice = $botState['gbPrice'];
        $dayPrice = $botState['dayPrice'];
        
        if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || preg_match('/^renew\d+$/', $match['buyType']))) {
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param("i", $match[1]);
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
            
            if(function_exists('v2raystore_isAgentPricingPerGb') && v2raystore_isAgentPricingPerGb($userInfo, $id, $sid)){
                $gbPrice = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($gbPrice, $userInfo, $id, $sid, 1, 1) : $gbPrice;
                $dayPrice = 0;
            }else{
                $gbPrice = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($gbPrice, $userInfo, $id, $sid, 0, 1) : $gbPrice;
                $dayPrice = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($dayPrice, $userInfo, $id, $sid, 0, 1) : $dayPrice;
            }
        }
        
        $agentBought = false;
        if($userInfo['is_agent'] == 1 && ($match['buyType'] == "one" || $match['buyType'] == "much" || preg_match('/^renew\d+$/', $match['buyType']))) {
            $agentBought = true;
        }
        
        $price =  ($volume * $gbPrice) + ($days * $dayPrice);
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`)
                                    VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, 'pending', ?)");
        $stmt->bind_param("ssiiiiiii", $hash_id, $text, $from_id, $id, $volume, $days, $price, $time, $agentBought);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
        v2raystore_notifyPurchaseStarted($hash_id, 'انتخاب پلن دلخواه');
    }
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payCustomWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payCustomWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountCustom_" . $rowId]];
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
    $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
    sendMessage(str_replace(['VOLUME', 'DAYS', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$volume, $days, $name, $price, $desc], $mainValues['buy_subscription_detail']),json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    setUser();
}
if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
if($data=="getTestAccount"){
    $plans = function_exists('v2raystore_getActiveTestPlans') ? v2raystore_getActiveTestPlans() : [];
    if(empty($plans)){
        alert("در حال حاضر اکانت تست فعال نیست.");
        exit();
    }
    if(count($plans) === 1){
        $testPlanId = intval($plans[0]['id'] ?? 0);
        if(function_exists('v2raystore_canUserGetTestAccount') && !v2raystore_canUserGetTestAccount($userInfo, $from_id, $testPlanId)){
            $taken = function_exists('v2raystore_getUserTakenTestAccountLabels') ? v2raystore_getUserTakenTestAccountLabels($from_id, $plans) : [];
            $where = count($taken) ? "\nگرفته‌شده: " . implode('، ', $taken) : '';
            alert("از این سرور قبلاً کانفیگ تست گرفته‌ای." . $where, true);
            exit();
        }
        alert($mainValues['receving_information'] ?? "در حال آماده‌سازی اکانت تست...");
        $data = "freeTrial{$testPlanId}_normal";
    }else{
        editText($message_id, v2raystore_getTestAccountMenuText($from_id, $plans), v2raystore_getTestAccountMenuKeys($from_id, $plans), "HTML");
        exit();
    }
}
if((preg_match('/^discountSelectPlan(\d+)_(\d+)_(\d+)/',$userInfo['step'],$match) || 
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) || 
    preg_match('/enterAccountName(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) || 
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$data, $match)) && 
    ($botState['sellState']=="on" ||$from_id ==$admin) && 
    $text != $buttonValues['cancel']){
    if(preg_match('/^discountSelectPlan/', $userInfo['step'])){
        $rowId = $match[3];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $canUse = $discountInfo['can_use'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"v2raystore"]
                        ],
                    ]]);
                sendMessage(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null,$admin);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }elseif(isset($data)) delMessage();


    if($botState['remark'] ==  "manual" && preg_match('/^selectPlan/',$data) && $match['buyType'] != "much" && !preg_match('/^renew\d+$/', $match['buyType'])){
        sendMessage($mainValues['customer_custome_plan_name'], $cancelKey);
        setUser('enterAccountName' . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
        exit();
    }

    $remark = "";
    if(preg_match("/selectPlan(\d+)_(\d+)_(\w+)/",$userInfo['step'])){
        if($match['buyType'] == "much"){
            if(is_numeric($text)){
                if($text > 0){
                    $accountCount = $text;
                    setUser();
                }else{sendMessage( $mainValues['send_positive_number']); exit(); }
            }else{ sendMessage($mainValues['send_only_number']); exit(); }
        }        
    }
    elseif(preg_match("/enterAccountName(\d+)_(\d+)/",$userInfo['step'])){
        if(preg_match('/^[a-z]+[0-9]+$/',$text)){
            $remark = $text;
            setUser();
        } else{
            sendMessage($mainValues['incorrect_config_name']);
            exit();
        }
    }
    else{
        if($match['buyType'] == "much"){
            setUser($data);
            sendMessage($mainValues['enter_account_amount'], $cancelKey);
            exit();
        }
    }
    
    
    $id = $match[1];
	$call_id = $match[2];
    alert($mainValues['receving_information']);
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1 AND COALESCE(`price`,0) != 0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$respd){
        alert($mainValues['no_plan_available'] ?? 'پلن پیدا نشد.', true);
        exit();
    }
    $planServerIdForSale = intval($respd['server_id'] ?? 0);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($planServerIdForSale, $from_id, $userInfo ?? null, $match['buyType'] ?? '')){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    if(isset($accountCount)) $price *= $accountCount;
    
    $agentBought = false;
    if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much" || preg_match('/^renew\d+$/', $match['buyType']))){
        $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $id, $sid, $respd['volume'] ?? 0, $accountCount ?? 1) : $price;

        $agentBought = true;
    }
    if(preg_match('/^renew(\d+)$/', $match['buyType'], $renewBuyMatch)){
        $renewOrderId = intval($renewBuyMatch[1]);
        $stmt = $connection->prepare("SELECT `id`, `userid`, `remark`, `status`, `server_id` FROM `orders_list` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $renewOrderId);
        $stmt->execute();
        $renewOrder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewOrder || intval($renewOrder['status'] ?? 0) != 1 || (intval($renewOrder['userid']) != intval($from_id) && $from_id != $admin && ($userInfo['isAdmin'] ?? false) != true)){
            alert($mainValues['config_not_found'] ?? 'کانفیگ پیدا نشد.', true);
            exit();
        }
        if(intval($renewOrder['server_id'] ?? 0) !== intval($sid)){
            alert('برای تمدید فقط پلن‌های همان سرور فعلی سرویس قابل انتخاب است. اگر می‌خواهی سرور را عوض کنی، اول تغییر لوکیشن بده.', true);
            exit();
        }
        $stmt = $connection->prepare("SELECT `ucount`, `active`, `state` FROM `server_info` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $renewServerInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$renewServerInfo || intval($renewServerInfo['active'] ?? 0) != 1 || intval($renewServerInfo['state'] ?? 0) != 1 || intval($renewServerInfo['ucount'] ?? 0) <= 0){
            alert('سرور فعلی ظرفیت ندارد. اول تغییر لوکیشن بده، بعد تمدید کن.', true);
            exit();
        }
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        $time = time();
        $renewDesc = json_encode(['order_id'=>$renewOrderId, 'renew_plan_id'=>intval($id)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`) VALUES (?, ?, ?, 'RENEW_ACCOUNT', ?, '0', '0', ?, ?, 'pending')");
        $stmt->bind_param("ssiiii", $hash_id, $renewDesc, $from_id, $renewOrderId, $price, $time);
        $stmt->execute();
        $stmt->close();
        if(function_exists('v2raystore_notifyPurchaseStarted')) v2raystore_notifyPurchaseStarted($hash_id, 'انتخاب پلن تمدید');

        $renewKeyboard = [];
        $priceText = ($price == 0) ? 'رایگان' : number_format($price) . ' تومان';
        if($price == 0){
            $renewKeyboard[] = [['text'=>'📥 تمدید رایگان', 'callback_data'=>'freeRenew' . $hash_id]];
        }else{
            if($botState['cartToCartState'] == "on") $renewKeyboard[] = [['text' => "💳 کارت به کارت مبلغ $priceText",  'callback_data' => "payRenewWithCartToCart$hash_id"]];
            if($botState['nowPaymentOther'] == "on") $renewKeyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
            if($botState['zarinpal'] == "on") $renewKeyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
            if($botState['nextpay'] == "on") $renewKeyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
            if($botState['walletState'] == "on") $renewKeyboard[] = [['text' => "پرداخت با موجودی مبلغ $priceText",  'callback_data' => "payRenewWithWallet$hash_id"]];
        }
        $renewKeyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]];
        $renewSettings = function_exists('v2raystore_getRenewSettings') ? v2raystore_getRenewSettings() : ['mode'=>'reset','max_days'=>45];
        $renewModeText = ($renewSettings['mode'] ?? 'reset') === 'add' ? 'افزایشی؛ حجم اضافه می‌شود و تاریخ نهایتاً تا ۴۵ روز جلو می‌رود.' : 'ریست کامل؛ حجم و تاریخ مثل تمدید قبلی ریست می‌شود.';
        $msg = "🔄 <b>تمدید سرویس</b>\n\n" .
               "سرویس: <code>" . htmlspecialchars($renewOrder['remark'], ENT_QUOTES, 'UTF-8') . "</code>\n" .
               "پلن انتخابی: <b>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</b>\n" .
               "قیمت: <b>$priceText</b>\n\n" .
               "حالت تمدید: <b>" . htmlspecialchars($renewModeText, ENT_QUOTES, 'UTF-8') . "</b>\n\n" .
               "یکی از روش‌های پرداخت را انتخاب کن:";
        sendMessage($msg, json_encode(['inline_keyboard'=>$renewKeyboard], JSON_UNESCAPED_UNICODE), "HTML");
        exit();
    }

    if($price == 0 or ($from_id == $admin)){
        $keyboard[] = [['text' => '📥 دریافت رایگان', 'callback_data' => "freeTrial{$id}_{$match['buyType']}"]];
        setUser($remark, 'temp');
    }else{
        $token = base64_encode("{$from_id}.{$id}");
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])){
            $hash_id = RandomString();
            $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $stmt->close();
            
            $time = time();
            if(isset($accountCount)){
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`, `agent_count`)
                                            VALUES (?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?, ?)");
                $stmt->bind_param("siiiiii", $hash_id, $from_id, $id, $price, $time, $agentBought, $accountCount);
            }else{
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`)
                                            VALUES (?, ?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?)");
                $stmt->bind_param("ssiiiii", $hash_id, $remark, $from_id, $id, $price, $time, $agentBought);
            }
            $stmt->execute();
            $rowId = $stmt->insert_id;
            $stmt->close();
            v2raystore_notifyPurchaseStarted($hash_id, isset($accountCount) ? 'انتخاب پلن خرید انبوه' : 'انتخاب پلن خرید');
        }else{
            $price = $afterDiscount;
        }
        
        if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
        if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
        if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
        if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
        if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
        if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
        if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountSelectPlan_" . $match[1] . "_" . $match[2] . "_" . $rowId]];

    }
	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]];
    $priceC = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
    if(isset($accountCount)){
        $eachPrice = number_format($price / $accountCount) . " تومان";
        $msg = str_replace(['ACCOUNT-COUNT', 'TOTAL-PRICE', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$accountCount, $priceC, $name, $eachPrice, $desc], $mainValues['buy_much_subscription_detail']);
    }
    else $msg = str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $priceC, $desc], $mainValues['buy_subscription_detail']);
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/payCustomWithWallet(.*)/',$data, $match)){
    if(function_exists('v2raystore_approveSentOrderByHash')){
        setUser();
        $hashId = trim($match[1]);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        $stmt->bind_param("s", $hashId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$payInfo){ alert('پرداخت پیدا نشد.', true); exit(); }
        if(($payInfo['state'] ?? '') == 'approved'){ alert('این سفارش قبلاً تأیید شده است.', true); exit(); }
        $price = intval($payInfo['price'] ?? 0);
        $userwallet = intval($userInfo['wallet'] ?? 0);
        if($userwallet < $price){
            $needamount = $price - $userwallet;
            alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ", true);
            exit();
        }
        $result = v2raystore_approveSentOrderByHash($hashId, false);
        if(!$result['ok']){ alert($result['message'], true); exit(); }
        if($price > 0){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = GREATEST(`wallet` - ?, 0) WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();
        }
        editText($message_id, "✅ سرویس شما با موفقیت فعال شد", getMainKeys());
        exit();
    }
    setUser();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($payInfo['state'] == "paid_with_wallet") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    
    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];

    if($userInfo['wallet'] < $price){
        alert("موجودی حساب شما کم است");
        exit();
    }
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$from_id}-{$rnd}";
    $remark = $payInfo['description']; 
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    
    if($inbound_id == 0){    
        if($serverType == "marzban"){
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
            if(!$response->success){
                if($response->msg == "User already exists"){
                    $remark .= rand(1111,99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                }
            }
        }else{
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);
                
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
            }
        }
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
        if(!$response->success){
            if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
        } 
    }
    
    if(is_null($response)){
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
        sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    include_once 'phpqrcode/qrlib.php';
    
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
        $vraylink = [$subLink];
        $vray_link = json_encode($response->vray_links);
    }
    else{
        $token = RandomString(30);
        $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
    
        $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
        $vray_link = json_encode($vraylink);
    }
    delMessage();
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
    $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreLoopLinks = $vraylink;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
        $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($subLink != "") $acc_text .= "



🌐 subscription : <code>$subLink</code>"; 
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
        unlink($file);
    }

    
    if($userInfo['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $userInfo['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
    }
    
    $agentBought = $payInfo['agent_bought'];
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
    $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result(); 
    $stmt->close();
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"v2raystore"]
        ],
        ]]);
    $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
    $msg = v2raystore_appendServerPlanToChannelReport($msg, $serverTitle ?? '', $file_detail['title'] ?? '');
    sendMessage($msg,$keys,"html", $admin);
}
if(preg_match('/^showQr(Sub|Config)(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$order){
        alert('سرویس پیدا نشد.', true);
        exit;
    }

    $qrType = $match[1];
    if($qrType == 'Config' && function_exists('v2raystore_isSubOnlyOrder') && v2raystore_isSubOnlyOrder($order)){
        // وقتی سرویس فقط با لینک ساب ارسال می‌شود، QR دکمه کانفیگ هم باید روی آدرس ساب ساخته شود.
        $qrType = 'Sub';
    }

    $remarkSafe = htmlspecialchars((string)($order['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
    $keyboard = function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]], JSON_UNESCAPED_UNICODE);

    if($qrType == "Sub"){
        $subLink = function_exists('v2raystore_orderCurrentSubLink')
            ? v2raystore_orderCurrentSubLink($order)
            : v2raystore_makeCustomerSubLink($order['server_id'], $order['token'], $order['uuid'] ?? "", $order['inbound_id'] ?? 0, $order['remark'] ?? "");
        if(trim((string)$subLink) == ""){
            alert("لینک ساب پنل برای این سرویس پیدا نشد.", true);
            exit;
        }
        $acc_text = "🌐 <b>QR لینک ساب</b>\n";
        if($remarkSafe !== '') $acc_text .= "🔮 سرویس: <b>{$remarkSafe}</b>\n";
        $acc_text .= "\n<code>" . htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') . "</code>";
        if(function_exists('v2raystore_subConfigUsageGuide')) $acc_text .= v2raystore_subConfigUsageGuide();
        if(function_exists('v2raystore_sendQrLinkMessage') && v2raystore_sendQrLinkMessage($from_id, $subLink, $acc_text, $keyboard, 'HTML')){
            exit;
        }
        sendMessage($acc_text, $keyboard, 'HTML', $from_id);
        exit;
    }

    $vraylink = json_decode((string)($order['link'] ?? ''), true);
    if(!is_array($vraylink)) $vraylink = [];
    $sentAny = false;
    foreach($vraylink as $vray_link){
        $vray_link = trim((string)$vray_link);
        if($vray_link === '') continue;
        $acc_text = "💝 <b>QR کانفیگ</b>\n";
        if($remarkSafe !== '') $acc_text .= "🔮 سرویس: <b>{$remarkSafe}</b>\n";
        $acc_text .= "\n<code>" . htmlspecialchars($vray_link, ENT_QUOTES, 'UTF-8') . "</code>";
        if(function_exists('v2raystore_sendQrLinkMessage') && v2raystore_sendQrLinkMessage($from_id, $vray_link, $acc_text, $keyboard, 'HTML')){
            $sentAny = true;
            continue;
        }
        sendMessage($acc_text, $keyboard, 'HTML', $from_id);
        $sentAny = true;
    }
    if(!$sentAny) alert('لینک کانفیگ برای ساخت QR پیدا نشد.', true);
    exit;
}
if(preg_match('/payCustomWithCartToCart(.*)/',$data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount != 0 && $acount <= 0){
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }
    
    setUser($data);
    delMessage();
    v2raystore_sendCartToCartInstructions($match[1], 'buy_account_cart_to_cart', 'HTML');
    exit;
}
if(preg_match('/payCustomWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        v2raystore_markPayReceiptSent($match[1], $fileid);
        
        $fid = $payInfo['plan_id'];
        $volume = $payInfo['volume'];
        $days = $payInfo['day'];
        
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $res['catid']);
        $stmt->execute();
        $catname = $stmt->get_result()->fetch_assoc()['title'];
        $stmt->close();
        $filename = $catname." ".$res['title']; 
        $serverTitle = '';
        if(!empty($res['server_id'])){
            $stmt = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id`=? LIMIT 1");
            if($stmt){
                $stmt->bind_param("i", $res['server_id']);
                $stmt->execute();
                $serverTitle = $stmt->get_result()->fetch_assoc()['title'] ?? '';
                $stmt->close();
            }
        }
        $fileprice = $payInfo['price'];
        $remark = $payInfo['description'];
        
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                            ["کارت به کارت", $from_id, $username, $first_name, $fileprice, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
        $msg = v2raystore_appendServerPlanToChannelReport($msg, $serverTitle ?? '', $filename ?? ($res['title'] ?? ''));
        $keyboard = json_encode(['inline_keyboard' => [
            [
                ['text' => $buttonValues['approve'], 'callback_data' => "accept" . $match[1], 'style' => 'success'],
                ['text' => $buttonValues['decline'], 'callback_data' => "declineOrder" . $match[1], 'style' => 'danger']
            ],
            [v2raystore_userPrivateButton($uid)]
        ]], JSON_UNESCAPED_UNICODE);
        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            $adminMsg = sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
            if(function_exists('v2raystore_storeAdminPayMessage') && isset($adminMsg->ok) && $adminMsg->ok && isset($adminMsg->result->message_id)){
                v2raystore_storeAdminPayMessage($match[1], $admin, intval($adminMsg->result->message_id));
            }
        }
    }else{
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/accCustom(.*)/',$data, $match) and $text != $buttonValues['cancel']){
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($payInfo['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    $uid = $payInfo['user_id'];

    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'] ?? null;
    $customPort = $file_detail['custom_port'] ?? null;
    $customSni = $file_detail['custom_sni'] ?? null;
    $customDomain = $file_detail['custom_domain'] ?? null;

    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$uid}-{$rnd}";
    $remark = $payInfo['description'];
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    
    if($inbound_id == 0){    
        if($serverType == "marzban"){
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
            if(!$response->success){
                if($response->msg == "User already exists"){
                    $remark .= rand(1111,99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                }
            }
        }else{
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);

                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
            }
        }
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
        if(!$response->success){
            if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
        } 
    }
    
    if(is_null($response)){
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
        sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    include_once 'phpqrcode/qrlib.php';
    
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
        $vraylink = [$subLink];
        $vray_link= json_encode($response->vray_links);
    }
    else{
        $token = RandomString(30);
        $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
    
        $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
        $vray_link= json_encode($vraylink);
    }
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);

    $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
    $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
    $__v2raystoreServerType = isset($serverType) ? $serverType : '';
    $__v2raystoreRemark = isset($remark) ? $remark : '';
    $__v2raystoreLoopLinks = $vraylink;
    if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
        $__v2raystoreLoopLinks = [];
    }

    foreach($__v2raystoreLoopLinks as $vray_link){
        $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$vray_link</code>":"");
if($subLink != "") $acc_text .= "


\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
    
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
        unlink($file);
    }
    sendMessage('✅ کانفیگ و براش ارسال کردم', getMainKeys());
    
    $agentBought = $payInfo['agent_bought'];
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
    $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();


    if(function_exists('v2raystore_orderStatusKeyboard')){
        editKeys(v2raystore_orderStatusKeyboard('✅ تأیید شد', $uid, 'success'));
    }else{
        editKeys(json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید شد','callback_data'=>'v2raystore']]]], JSON_UNESCAPED_UNICODE));
    }
    
    $filename = $file_detail['title'];
    $fileprice = number_format($file_detail['price']);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_detail= $stmt->get_result()->fetch_assoc();
    $stmt->close();


    if($user_detail['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $user_detail['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
    }

    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $uname = $user_detail['name'];
    $user_name = $user_detail['username'];
    
    if($admin != $from_id){ 
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"به به 🛍",'callback_data'=>"v2raystore"]
            ],
            ]]);
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
            [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
        sendMessage($msg,null,null,$admin);
    }
    
}
if(preg_match('/payWithWallet(.*)/',$data, $match)){
    if(function_exists('v2raystore_approveSentOrderByHash')){
        setUser();
        $hashId = trim($match[1]);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        $stmt->bind_param("s", $hashId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$payInfo){ alert('پرداخت پیدا نشد.', true); exit(); }
        if(($payInfo['state'] ?? '') == 'approved'){ alert('این سفارش قبلاً تأیید شده است.', true); exit(); }
        $price = intval($payInfo['price'] ?? 0);
        $userwallet = intval($userInfo['wallet'] ?? 0);
        if($userwallet < $price){
            $needamount = $price - $userwallet;
            alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ", true);
            exit();
        }
        $result = v2raystore_approveSentOrderByHash($hashId, false);
        if(!$result['ok']){ alert($result['message'], true); exit(); }
        if($price > 0){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = GREATEST(`wallet` - ?, 0) WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();
        }
        editText($message_id, "✅ سرویس شما با موفقیت فعال شد", getMainKeys());
        exit();
    }
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    
    $uid = $from_id;
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    if($payInfo['state'] == "paid_with_wallet") exit();
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $price = $payInfo['price'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;
    
    if($userInfo['wallet'] < $price){
        alert("موجودی حساب شما کم است");
        exit();
    }

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
        $isMarzban = $configInfo['marzban'];
        
        $inbound_id = $payInfo['volume']; 
        
        if($isMarzban){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
        }else{
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
            else
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
        }
        
    	if(is_null($response)){
    		alert('🔻مشکل فنی در اتصال به سرور. لطفاً به مدیریت اطلاع بدید',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]
            ],
            ]]);
        editText($message_id,"✅سرویس $remark با موفقیت تمدید شد",$keys);
    }else{
        $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }        
    
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $srv_remark = $serverInfo['remark'];
        $serverTitle = $serverInfo['title'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $portType = $serverConfig['port_type'];
        $serverType = $serverConfig['type'];
        $panelUrl = $serverConfig['panel_url'];
        $stmt->close();

        include_once 'phpqrcode/qrlib.php';
        $msg = $message_id;

        $agent_bought = false;
	    $eachPrice = $price / $accountCount;
        if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")) {$agent_bought = true; setUser('', 'temp');}

        alert($mainValues['sending_config_to_user']);
        if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
        if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
            $savedinfo = explode('-',$savedinfo);
            $port = $savedinfo[0] + 1;
            $last_num = $savedinfo[1] + 1;
        
        
            if($botState['remark'] == "digits"){
                $rnd = rand(10000,99999);
                $remark = "{$srv_remark}-{$rnd}";
            }
            elseif($botState['remark'] == "manual"){
                $remark = $payInfo['description'];
            }
            else{
                $rnd = rand(1111,99999);
                $remark = "{$srv_remark}-{$from_id}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
            }else{
                $port = rand(1111,65000);
            }
        
            if($inbound_id == 0){    
                if($serverType == "marzban"){
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                    if(!$response->success){
                        if($response->msg == "User already exists"){
                            $remark .= rand(1111,99999);
                            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                        }
                    }
                }
                else{
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                    if(!$response->success){
                        if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                        elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);

                        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                    }
                }
            }else {
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                if(!$response->success){
                    if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                } 
            }
            if(is_null($response)){
                sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
                sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
                exit;
            }
        
        
            if($serverType == "marzban"){
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
                $vraylink = [$subLink];
                $vray_link= json_encode($response->vray_links);
            }
            else{
                $token = RandomString(30);
                $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
                $vray_link= json_encode($vraylink);
                $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
            }

            $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreLoopLinks = $vraylink;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
                $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($subLink != "") $acc_text .= "


\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
                
                QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
                unlink($file);
            }
            
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result(); 
            $stmt->close();
        }
    
        delMessage($msg);
        if($userInfo['refered_by'] != null){
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->execute();
            $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
            $stmt->close();
            $inviterId = $userInfo['refered_by'];
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $inviteAmount, $inviterId);
            $stmt->execute();
            $stmt->close();
             
            sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
            $stmt->bind_param("ii", $accountCount, $server_id);
            $stmt->execute();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
            $stmt->bind_param("ii", $accountCount, $fid);
            $stmt->execute();
            $stmt->close();
        }
    }
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    $stmt->close();
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"v2raystore"]
        ],
        ]]);
    if($payInfo['type'] == "RENEW_SCONFIG"){
        $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['renew_account_request_message']);
    }
    else{
        $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);
        $msg = v2raystore_appendServerPlanToChannelReport($msg, $serverTitle, $file_detail['title'] ?? '');
    }

    sendMessage($msg,$keys,"html", $admin);
}
if(preg_match('/payWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($payInfo['type'] != "RENEW_SCONFIG"){
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }else{
            if($acount <= 0){
                alert(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
                exit();
            }
        }
    }
    setUser($data);
    delMessage();
    v2raystore_sendCartToCartInstructions($match[1], 'buy_account_cart_to_cart', 'HTML');
    exit;
}
if(preg_match('/payWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        v2raystore_markPayReceiptSent($match[1], $fileid);
    
        
        $fid = $payInfo['plan_id'];
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $days = $res['days'];
        $volume = $res['volume'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $res['server_id']);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverTitle = $serverInfo['title'];
    
        if($payInfo['type'] == "RENEW_SCONFIG"){
            $configInfo = json_decode($payInfo['description'],true);
            $filename = $configInfo['remark'];
        }else{
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $res['catid']);
            $stmt->execute();
            $catname = $stmt->get_result()->fetch_assoc()['title'];
            $stmt->close();
            $filename = $catname." ".$res['title']; 
        }
        $fileprice = $payInfo['price'];
    
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        if($payInfo['agent_count'] != 0) {
            $msg = str_replace(['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],[$payInfo['agent_count'], 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename], $mainValues['buy_new_much_account_request']);
        }
        else {
            $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],[$serverTitle, 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename, $volume, $days], $mainValues['buy_new_account_request']);
        }
        $msg = v2raystore_appendServerPlanToChannelReport($msg, $serverTitle, $filename);

        $keyboard = v2raystore_adminPendingOrderKeyboard($match[1], $uid);
        setUser('', 'temp');
        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            $res = sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
            if(function_exists('v2raystore_storeAdminPayMessage') && isset($res->ok) && $res->ok && isset($res->result->message_id)){
                v2raystore_storeAdminPayMessage($match[1], $admin, intval($res->result->message_id));
            }
        }
    }else{
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }
}
if($data=="availableServers"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `acount` != 0 AND `inbound_id` != 0 AND COALESCE(`price`,0) != 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"تعداد باقیمانده",'callback_data'=>"v2raystore"],
        ['text'=>"پلن",'callback_data'=>"v2raystore"],
        ['text'=>'سرور','callback_data'=>"v2raystore"]
        ];
    while($file_detail = $serversList->fetch_assoc()){
        $days = $file_detail['days'];
        $title = $file_detail['title'];
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $name = $name->fetch_assoc()['title'];
            
            $keys[] = [
                ['text'=>$acount . " اکانت",'callback_data'=>"v2raystore"],
                ['text'=>$title??" ",'callback_data'=>"v2raystore"],
                ['text'=>$name??" ",'callback_data'=>"v2raystore"]
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 | موجودی پلن اشتراکی:", $keys);
}
if($data=="availableServers2"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `inbound_id` = 0 AND COALESCE(`price`,0) != 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"تعداد باقیمانده",'callback_data'=>"v2raystore"],
        ['text'=>'سرور','callback_data'=>"v2raystore"]
        ];
    while($file_detail2 = $serversList->fetch_assoc()){
        $days2 = $file_detail2['days'];
        $title2 = $file_detail2['title'];
        $server_id2 = $file_detail2['server_id'];
        $inbound_id2 = $file_detail2['inbound_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id2);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $sInfo = $name->fetch_assoc();
            $name = $sInfo['title'];
            $acount2 = $sInfo['ucount'];
            
            $keys[] = [
                ['text'=>$acount2 . " اکانت",'callback_data'=>"v2raystore"],
                ['text'=>$title2??" ",'callback_data'=>"v2raystore"],
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 | موجودی پلن اختصاصی:", $keys);
}
if($data=="agencySettings" && $userInfo['is_agent'] == 1){
    editText($message_id, $mainValues['agent_setting_message'] ,getAgentKeys());
}
if($data=="requestAgency"){
    if($userInfo['is_agent'] == 2){
        alert($mainValues['agency_request_already_sent']);
    }elseif($userInfo['is_agent'] == 0){
        $msg = str_replace(["USERNAME", "NAME", "USERID"], [$username, $first_name, $from_id], $mainValues['request_agency_message']);
        sendMessage($msg, json_encode(['inline_keyboard'=>[
            [
                ['text' => $buttonValues['approve'], 'callback_data' => "agencyApprove" . $from_id ],
                ['text' => $buttonValues['decline'], 'callback_data' => "agencyDecline" . $from_id]
            ]
            ]]), null, $admin);
        setUser(2, 'is_agent');
        alert($mainValues['agency_request_sent']);
    }elseif($userInfo['is_agent'] == -1) alert($mainValues['agency_request_declined']);
    elseif($userInfo['is_agent'] == 1) editText($message_id,"لطفاً یکی از کلید های زیر را انتخاب کنید",getMainKeys());
}
if(preg_match('/^agencyDecline(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['declined'],'callback_data'=>"v2raystore"]]
        ]]));
    sendMessage($mainValues['agency_request_declined'], null,null,$match[1]);
    setUser(-1, 'is_agent', $match[1]);
}
if(preg_match('/^agencyApprove(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
}
if(preg_match('/^agencyApprove(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        editKeys(json_encode(['inline_keyboard'=>[
            [['text'=>$buttonValues['approved'],'callback_data'=>"v2raystore"]]
            ]]), $match[2]);
        sendMessage($mainValues['saved_successfuly']);
        setUser();
        $discount = json_encode(['normal'=> (function_exists('v2raystore_makeAgentPricingRule') ? v2raystore_makeAgentPricingRule('percent', $text) : $text), 'plans'=>[], 'servers'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 1, `discount_percent` = ?, `agent_date` = ? WHERE `userid` = ?");
        $stmt->bind_param("sii", $discount, $time, $match[1]);
        $stmt->execute();
        $stmt->close();
        sendMessage($mainValues['agency_request_approved'], null,null,$match[1]);
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/accept(.*)/',$data, $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    $result = v2raystore_approveSentOrderByHash($match[1], false);
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    $approvedText = function_exists('v2raystore_approvalStatusTextFromResult') ? v2raystore_approvalStatusTextFromResult($result, false) : ($buttonValues['approved'] ?? '✅ تأیید شد');
    $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
    if(function_exists('v2raystore_orderStatusKeyboard')){
        editKeys(v2raystore_orderStatusKeyboard($approvedText, intval($result['user_id'] ?? 0), 'success', $copyText));
    }else{
        editKeys(json_encode(['inline_keyboard'=>[[['text'=>$approvedText,'callback_data'=>'v2raystore']]]], JSON_UNESCAPED_UNICODE));
    }
    exit();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($payInfo['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    $uid = $payInfo['user_id'];
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;

    
    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
        $isMarzban = $configInfo['marzban'];
        
        $inbound_id = $payInfo['volume']; 
        
        if($isMarzban){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
        }else{
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
            else
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
        }
        
    	if(is_null($response)){
    		alert('🔻مشکل فنی در اتصال به سرور. لطفاً به مدیریت اطلاع بدید',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['renewed_config_to_user']), getMainKeys(),null,null);
        sendMessage("✅سرویس $remark با موفقیت تمدید شد",null,null,$uid);
    }else{
        $accountCount = $payInfo['agent_count'] != 0? $payInfo['agent_count']:1;
        $eachPrice = $price / $accountCount;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $srv_remark = $serverInfo['remark'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $serverType = $serverConfig['type'];
        $portType = $serverConfig['port_type'];
        $panelUrl = $serverConfig['panel_url'];
        $stmt->close();
    
    
        alert($mainValues['sending_config_to_user']);
        include_once 'phpqrcode/qrlib.php';
        if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
        if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
            $savedinfo = explode('-',$savedinfo);
            $port = $savedinfo[0] + 1;
            $last_num = $savedinfo[1] + 1;
    
    
            if($botState['remark'] == "digits"){
                $rnd = rand(10000,99999);
                $remark = "{$srv_remark}-{$rnd}";
            }
            elseif($botState['remark'] == "manual"){
                $remark = $payInfo['description'];
            }
            else{
                $rnd = rand(1111,99999);
                $remark = "{$srv_remark}-{$uid}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
            }else{
                $port = rand(1111,65000);
            }
        
            if($inbound_id == 0){   
                if($serverType == "marzban"){
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                    if(!$response->success){
                        if($response->msg == "User already exists"){
                            $remark .= rand(1111,99999);
                            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                        }
                    }
                }
                else{
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                    if(!$response->success){
                        if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                        elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);

                        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                    }
                }
            }else {
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                if(!$response->success){
                    if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                } 
            }
            if(is_null($response)){
                sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
                sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
                exit;
            }
                
            if($serverType == "marzban"){
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
                $vraylink = [$subLink];
                $vray_link = json_encode($response->vray_links);
            }
            else{
                $token = RandomString(30);
                $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
        
                $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $fid) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
                $vray_link = json_encode($vraylink);
            }
            $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreLoopLinks = $vraylink;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
                $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($subLink != "") $acc_text .= "


\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
            
                QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $uid);
                unlink($file);
            }
            $agent_bought = $payInfo['agent_bought'];
    
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result();
            $stmt->close();
        }
        // پیام خلاصه «کانفیگ برای کاربر ارسال شد» حذف شد؛ کانفیگ اصلی قبلاً برای کاربر ارسال می‌شود.
        if($inbound_id == 0) {
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
            $stmt->bind_param("ii", $accountCount, $server_id);
            $stmt->execute();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
            $stmt->bind_param("ii", $accountCount, $fid);
            $stmt->execute();
            $stmt->close();
        }

    }

    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"v2raystore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
    if($payInfo['type'] != "RENEW_SCONFIG"){
        $filename = $file_detail['title'];
        $fileprice = number_format($file_detail['price']);
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user_detail= $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($user_detail['refered_by'] != null){
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->execute();
            $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
            $stmt->close();
            $inviterId = $user_detail['refered_by'];
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $inviteAmount, $inviterId);
            $stmt->execute();
            $stmt->close();
             
            sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
        }
    
    
        $uname = $user_detail['name'];
        $user_name = $user_detail['username'];
        
        if($admin != $from_id){
            $keys = json_encode(['inline_keyboard'=>[
                [
                    ['text'=>"به به 🛍",'callback_data'=>"v2raystore"]
                ],
                ]]);
                
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
                    [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
            
            sendMessage($msg,null,null,$admin);
        }
    }
}
if(preg_match('/^declineOrder(.+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $decPay = null;
    if($stmt){
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $decPay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if(!$decPay){
        alert('پرداخت پیدا نشد.', true);
        exit();
    }
    if(($decPay['state'] ?? '') == 'approved'){
        $canDeclineApproved = function_exists('v2raystore_payHasLinkedApprovedOrder') ? !v2raystore_payHasLinkedApprovedOrder($decPay) : false;
        if(!$canDeclineApproved){
            alert('این سفارش قبلاً تأیید شده و قابل رد کردن نیست.', true);
            if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard('✅ تأیید شد', intval($decPay['user_id'] ?? 0), 'success'));
            exit();
        }
    }
    if(in_array(($decPay['state'] ?? ''), ['declined','auto_cancelled'], true)){
        alert('این سفارش قبلاً رد یا لغو شده است.', true);
        if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard('❌ رد شد', intval($decPay['user_id'] ?? 0), 'danger'));
        exit();
    }
    setUser('declineOrder|' . $hashId . '|' . $message_id . '|' . intval($decPay['user_id'] ?? 0));
    sendMessage('دلیلت از عدم تایید چیه؟ ( بفرس براش ) 😔 ', $cancelKey);
    exit();
}
if(preg_match('/^declineOrder\|(.+)\|(\d+)\|(\d+)$/',$userInfo['step'] ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']){
    setUser();
    $hashId = $match[1];
    $targetMsgId = intval($match[2]);
    $uid = intval($match[3]);

    $declineResult = function_exists('v2raystore_declinePayByHash') ? v2raystore_declinePayByHash($hashId, $text) : ['ok'=>false, 'message'=>'تابع رد سفارش در دسترس نیست.'];
    if(!$declineResult['ok']){
        sendMessage('❌ ' . $declineResult['message'], $removeKeyboard, 'HTML');
        exit();
    }

    if(function_exists('v2raystore_orderStatusKeyboard')){
        editKeys(v2raystore_orderStatusKeyboard('❌ رد شد', $uid, 'danger'), $targetMsgId);
    }else{
        editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ رد شد','callback_data'=>'v2raystore']]]], JSON_UNESCAPED_UNICODE), $targetMsgId);
    }
    sendMessage('پیامت رو براش ارسال کردم ... 🤝',$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
    sendMessage($text, null, null, $uid);
    exit();
}
if(preg_match('/decline/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage('دلیلت از عدم تایید چیه؟ ( بفرس براش ) 😔 ',$cancelKey);
}
if(preg_match('/decline(\d+)_(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']){
    setUser();
    $uid = $match[1];
    if(function_exists('v2raystore_orderStatusKeyboard')){
        editKeys(v2raystore_orderStatusKeyboard('❌ رد شد', intval($uid), 'danger'), $match[2]);
    }else{
        editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ رد شد','callback_data'=>'v2raystore']]]], JSON_UNESCAPED_UNICODE), $match[2]);
    }

    sendMessage('پیامت رو براش ارسال کردم ... 🤝',$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
    sendMessage($text, null, null, $uid);
}

// appTutorial_* is handled near the top of the file so tutorial buttons always respond quickly.

if($data=="supportSection"){
    editText($message_id,"به بخش پشتیبانی خوش اومدی🛂\nلطفاً، یکی از دکمه های زیر را انتخاب نمایید.",
        json_encode(['inline_keyboard'=>[
        [['text'=>"✉️ ثبت تیکت",'callback_data'=>"usersNewTicket"]],
        [['text'=>"تیکت های باز 📨",'callback_data'=>"usersOpenTickets"],['text'=>"📮 لیست تیکت ها", 'callback_data'=>"userAllTickets"]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if($data== "usersNewTicket"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $temp = array();
    if($ticketCategory->num_rows >0){
        while($row = $ticketCategory->fetch_assoc()){
            $ticketName = $row['value'];
            $temp[] = ['text'=>$ticketName,'callback_data'=>"supportCat$ticketName"];
            
            if(count($temp) == 2){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        
        if($temp != null){
            if(count($temp)>0){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        $temp[] = ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"];
        array_push($keys,$temp);
        editText($message_id,"💠لطفاً واحد مورد نظر خود را انتخاب نمایید!",json_encode(['inline_keyboard'=>$keys]));
    }else{
        alert("ای وای، ببخشید الان نیستم");
    }
}
if($data == 'dayPlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"تعداد روز",'callback_data'=>"v2raystore"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if($data=='addNewDayPlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("تعداد روز و قیمت آن را بصورت زیر وارد کنید :
10-30000

مقدار اول مدت زمان (10) روز
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);exit;
}
if($userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_day` VALUES (NULL, ?, ?)");
    $stmt->bind_param("ii", $volume, $price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن زمانی جدید با موفقیت اضافه شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
    setUser();
}
if(preg_match('/^deleteDayPlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن موردنظر با موفقیت حذف شد");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"تعداد روز",'callback_data'=>"v2raystore"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        setUser();
        $stmt = $connection->prepare("UPDATE `increase_day` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    
        if($res->num_rows == 0){
           sendMessage( 'لیست پلن های زمانی خالی است ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]
                ]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"تعداد روز",'callback_data'=>"v2raystore"]];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            $acount =$cat['acount'];
    
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
        
        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    
        
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeDayPlanDay(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("روز جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanDay(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"تعداد روز",'callback_data'=>"v2raystore"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    sendMessage($msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    
}
if($data == 'volumePlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"backplan"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"مقدار حجم",'callback_data'=>"v2raystore"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit;
}
if($data=='addNewVolumePlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم و قیمت آن را بصورت زیر وارد کنید :
10-30000

مقدار اول حجم (10) گیگابایت
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);
 exit;
}
if($userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_plan` VALUES (NULL, ? ,?)");
    $stmt->bind_param("ii",$volume,$price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن حجمی جدید با موفقیت اضافه شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
    setUser();
}
if(preg_match('/^deleteVolumePlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن موردنظر با موفقیت حذف شد");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"مقدار حجم",'callback_data'=>"v2raystore"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $pid);
        $stmt->execute();
        $stmt->close();
        sendMessage("عملیات با موفقیت انجام شد",$removeKeyboard);
        
        setUser();
        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();
        
        if($plans->num_rows == 0){
           sendMessage( 'لیست پلن های حجمی خالی است ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                        [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                        ]]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"مقدار حجم",'callback_data'=>"v2raystore"]];
        while ($cat = $plans->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
        $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
        
        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $stmt = $connection->prepare("UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $pid);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       sendMessage( 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"قیمت",'callback_data'=>"v2raystore"],['text'=>"مقدار حجم",'callback_data'=>"v2raystore"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    
}
if(preg_match('/^supportCat(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['enter_ticket_title'], $cancelKey);
    setUser("newTicket_" . $match[1]);
}
if(preg_match('/^newTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    setUser($text, 'temp');
	setUser("sendTicket_" . $match[1]);
    sendMessage($mainValues['enter_ticket_description']);
}
if(preg_match('/^sendTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    if(isset($text) || isset($update->message->photo)){
        $ticketCat = $match[1];
        
        $ticketTitle = $userInfo['temp'];
        $time = time();
    
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $stmt = $connection->prepare("INSERT INTO `chats` (`user_id`,`create_date`, `title`,`category`,`state`,`rate`) VALUES 
                            (?,?,?,?,'0','0')");
        $stmt->bind_param("iiss", $from_id, $time, $ticketTitle, $ticketCat);
        $stmt->execute();
        $inserId = $stmt->get_result();
        $chatRowId = $stmt->insert_id;
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$chatRowId}"]]
            ]]);
        if(isset($text)){
            $txt = "تیکت جدید:\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: @$username\nآیدی عددی: $from_id\n\nموضوع تیکت: $ticketCat\n\nعنوان تیکت: " .$ticketTitle . "\nمتن تیکت: $text";
            $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendMessage($txt,$keys,"html", $admin);
        }else{
            $txt = "تیکت جدید:\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: @$username\nآیدی عددی: $from_id\n\nموضوع تیکت: $ticketCat\n\nعنوان تیکت: " .$ticketTitle . "\nمتن تیکت: $caption";
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendPhoto($fileid, $txt,$keys, "HTML", $admin);
        }
        $stmt->execute();
        $stmt->close();
        
        sendMessage("پیام شما با موفقیت ثبت شد",$removeKeyboard,"HTML");
        sendMessage("لطفاً یکی از کلید های زیر را انتخاب کنید",getMainKeys());
            
        setUser(NULL,'temp');
    	setUser("none");
    }else{
        sendMessage("پیام مورد نظر پشتیبانی نمی شود");
    }
    
}
if($data== "usersOpenTickets" || $data == "userAllTickets"){
    if($data== "usersOpenTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 AND `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = 2;
    }elseif($data == "userAllTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = "all";
    }
	$allList = $ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	setUser("none");


	if($allList>0){
        while($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="ADMIN"?"ادمین":"کاربر";
            if($state !=2){
                $keys = [
                        [['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ به تیکت 📝",'callback_data'=>"replySupport_{$rowId}"]],
                        [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [
                    [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                    ];
            }
                
            if(isset(json_decode($lastmsg,true)['file_id'])){
                $info = json_decode($lastmsg,true);
                $fileid = $info['file_id'];
                $caption = $info['caption'];
                $txt ="🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType : $caption";
                sendPhoto($fileid, $txt,json_encode(['inline_keyboard'=>$keys]), "HTML");
            }else{
                sendMessage(" 🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType : $lastmsg",json_encode(['inline_keyboard'=>$keys]),"HTML");
            }

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    sendmessage("موارد بیشتر",json_encode(['inline_keyboard'=>[
                		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
                		        ]]),"HTML");
		}
	}else{
	    alert("تیکتی یافت نشد");
        exit();
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  $from_id != $admin){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $from_id = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    editKeys();

    $ticketClosed = " $title : $category \n\n" . "این تیکت بسته شد\n به این تیکت رأی بدهید";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"بسیار بد 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"بد 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"خوب 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"بسیار خوب 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"عالی 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html');
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"$from_id",'callback_data'=>"v2raystore"],
            ['text'=>"آیدی کاربر",'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>$first_name??" ",'callback_data'=>"v2raystore"],
            ['text'=>"اسم کاربر",'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>"$title",'callback_data'=>'v2raystore'],
            ['text'=>"عنوان",'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>"$category",'callback_data'=>'v2raystore'],
            ['text'=>"دسته بندی",'callback_data'=>'v2raystore']
        ],
        ]]);
    sendMessage("☑️| تیکت توسط کاربر بسته شد",$keys,"HTML",$admin);

}
if(preg_match('/^replySupport_(.*)/',$data,$match)){
    delMessage();
    sendMessage("💠لطفاً متن پیام خود را بصورت ساده و مختصر ارسال کنید!",$cancelKey);
	setUser("sendMsg_" . $match[1]);
}
if(preg_match('/^sendMsg_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    $ticketRowId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $ticketRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];



    $time = time();
    if(isset($text)){
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nآیدی عددی: $from_id\n" . "\nمتن پیام: $text";
    
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $stmt->bind_param("iis",$ticketRowId, $time, $text);
        sendMessage($txt,json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$ticketRowId}"]]
            ]]),"HTML",$admin);
    }else{
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nآیدی عددی: $from_id\n" . "\nمتن پیام: $caption";
        
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt->bind_param("iis", $ticketRowId, $time, $text);
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$ticketRowId}"]]
            ]]);
        sendPhoto($fileid, $txt,$keys, "HTML", $admin);
    }
    $stmt->execute();
    $stmt->close();
                
    sendMessage("پیام شما با موفقیت ثبت شد",getMainKeys(),"HTML");
	setUser("none");
}
if(preg_match("/^rate_+([0-9])+_+([0-9])/",$data,$match)){
    $rowChatId = $match[1];
    $rate = $match[2];
    
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i",$rowChatId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
    
    
    $stmt = $connection->prepare("UPDATE `chats` SET `rate` = $rate WHERE `id` = ?");
    $stmt->bind_param("i", $rowChatId);
    $stmt->execute();
    $stmt->close();
    editText($message_id,"✅");
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"رای تیکت",'callback_data'=>"v2raystore"]
            ],
        ]]);

    sendMessage("
📨|رأی به تیکت 

👤 آیدی عددی: $from_id
❕نام کاربر: $first_name
❗️نام کاربری: $username
〽️ عنوان: $title
⚜️ دسته بندی: $category
❤️ رای: $rate
 ⁮⁮
    ",$keys,"HTML",$admin);
}
if($data=="ticketsList" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ticketSection = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"تیکت های باز",'callback_data'=>"openTickets"],
            ['text'=>"تیکت های جدید",'callback_data'=>"newTickets"]
            ],
        [
            ['text'=>"همه ی تیکت ها",'callback_data'=>"allTickets"],
            ['text'=>"دسته بندی تیکت ها",'callback_data'=>"ticketsCategory"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]]
        ]]);
    editText($message_id, "به بخش تیکت ها خوش اومدید، 
    
🚪 /start
    ",$ticketSection);
}
if($data=='ticketsCategory' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"دسته بندی",'callback_data'=>"v2raystore"]];
    
    if($ticketCategory->num_rows>0){
        while($row = $ticketCategory->fetch_assoc()){
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"v2raystore"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"v2raystore"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id,"دسته بندی تیکت ها",$keys);
}
if($data=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addTicketCategory');
    editText($message_id,"لطفاً اسم دسته بندی را وارد کنید");
}
if ($userInfo['step']=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
	$stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('TICKETS_CATEGORY', ?)");	
	$stmt->bind_param("s", $text);
	$stmt->execute();
	$stmt->close();
    setUser();
    sendMessage($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"دسته بندی",'callback_data'=>"v2raystore"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"v2raystore"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"v2raystore"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    sendMessage("دسته بندی تیکت ها",$keys);
}
if(preg_match("/^delTicketCat_(\d+)/",$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("با موفقیت حذف شد");
        

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"دسته بندی",'callback_data'=>"v2raystore"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"v2raystore"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"v2raystore"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "دسته بندی تیکت ها",$keys);
}
if(($data=="openTickets" or $data=="newTickets" or $data == "allTickets")  and  $from_id ==$admin){
    if($data=="openTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
        $type = 2;
    }elseif($data=="newTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
        $type = 0;
    }elseif($data=="allTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
        $type = "all";
    }
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();
	$allList =$ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $admin = $row['user_id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];
	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i",$rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="USER"?"کاربر":"ادمین";
            
            if($state !=2){
                $keys = [
                        [['text'=>"بستن تیکت",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ",'callback_data'=>"reply_{$rowId}"]],
                        [['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [[['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]];
                $rate = "\nرأی: ". $row['rate'];
            }
            
            sendMessage("آیدی کاربر: $admin\nنام کاربر: $username\nدسته بندی: $category $rate\n\nموضوع: $title\nآخرین پیام:\n[$sentType] $lastmsg",
                json_encode(['inline_keyboard'=>$keys]),"html");

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("موارد بیشتر",$keys,"html");
		}
	}else{
        alert("تیکتی یافت نشد");
	}
}
if(preg_match('/^moreTicket_(.+)_(.+)/',$data, $match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,$mainValues['please_wait_message']);
    $type = $match[1];
    $offset = $match[2];
    if($type=="2") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
    elseif($type=="0") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
    elseif($type=="all") $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
    
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();

	$allList =$ticketList->num_rows;
	$cont = 5 + $offset;
	$current = 0;
	$keys = array();
	$rowCont = 0;
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
            $rowCont++;
            if($rowCont>$offset){
    		    $current++;
    		    
                $rowId = $row['id'];
                $admin = $row['user_id'];
                $title = $row['title'];
                $category = $row['category'];
    	        $state = $row['state'];
    	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";
    
                $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
                $stmt->bind_param("i",$rowId);
                $stmt->execute();
                $ticketInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $lastmsg = $ticketInfo['text'];
                $sentType = $ticketInfo['msg_type']=="USER"?"کاربر":"ادمین";
                
                if($state !=2){
                    $keys = [
                            [['text'=>"بستن تیکت",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ",'callback_data'=>"reply_{$rowId}"]],
                            [['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]
                            ];
                }
                else{
                    $keys = [[['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]];
                    $rate = "\nرأی: ". $row['rate'];
                }
                
                sendMessage("آیدی کاربر: $admin\nنام کاربر: $username\nدسته بندی: $category $rate\n\nموضوع: $title\nآخرین پیام:\n[$sentType] $lastmsg",
                    json_encode(['inline_keyboard'=>$keys]),"html");


    			if($current>=$cont){
    			    break;
    			}
            }
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("موارد بیشتر",$keys);
		}
	}else{
        alert("تیکتی یافت نشد");
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    $ticketClosed = "[$title] <i>$category</i> \n\n" . "این تیکت بسته شد\n به این تیکت رأی بدهید";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"بسیار بد 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"بد 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"خوب 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"بسیار خوب 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"عالی 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html', $userId);
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>"تیکت بسته شد",'callback_data'=>"v2raystore"]]
        ]]));

}
if(preg_match('/^latestMsg_(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC LIMIT 10");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $rowId = $row['id'];
        $type = $row['msg_type'] == "USER" ?"کاربر":"ادمین";
        $text = $row['text'];
        if(isset(json_decode($text,true)['file_id'])) $text = "تصویر /dlPic" . $rowId; 

        $output .= "<i>[$type]</i>\n$text\n\n";
    }
    sendMessage($output, null, "html");
}
if(preg_match('/^\/dlPic(\d+)/',$text,$match)){
     $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $text = json_decode($row['text'],true);
        $fileid = $text['file_id'];
        $caption = $text['caption'];
        $chatInfoId = $row['chat_id'];
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
        $stmt->bind_param("i", $chatInfoId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $userid = $info['user_id'];
        
        if($userid == $from_id || $from_id == $admin || $userInfo['isAdmin'] == true) sendPhoto($fileid, $caption);
    }
}
if($data == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را که می‌خواهید مسدود شود ارسال کنید.", $cancelKey);
    setUser($data);
}
if($data=="unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً آیدی عددی کاربری را که می‌خواهید از حالت مسدود خارج شود ارسال کنید.", $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();
        
        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] != "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("✅ کاربر مورد نظر با موفقیت مسدود شد.",$removeKeyboard);
            }else{
                sendMessage("ℹ️ این کاربر از قبل مسدود بوده است.",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="mainMenuButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if(preg_match('/^delMainButton(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("با موفقیت حذف شد");
    editText($message_id,"مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if($data == "addNewMainButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً اسم دکمه را وارد کنید",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewMainButton" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage("لطفاً فقط متن بفرستید");
        exit();
    }
    sendMessage("لطفاً پاسخ دکمه را وارد کنید");
    setUser("setMainButtonAnswer" . $text);
}
if(preg_match('/^setMainButtonAnswer(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage("لطفاً فقط متن بفرستید");
        exit();
    }
    setUser();
    
    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
    $btn = "MAIN_BUTTONS" . $match[1];
    $stmt->bind_param("ss", $btn, $text); 
    $stmt->execute();
    $stmt->close();
    
    sendMessage("مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if($userInfo['step'] == "unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();

        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] == "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'none' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ کاربر مورد نظر با موفقیت از حالت مسدود خارج شد.",$removeKeyboard);
            }else{
                sendMessage("ℹ️ این کاربر در حال حاضر مسدود نیست.",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match("/^reply_(.*)/",$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser("answer_" . $match[1]);
    sendMessage("لطفاً پیام خود را ارسال کنید",$cancelKey);
}
if(preg_match('/^answer_(.*)/',$userInfo['step'],$match) and  $from_id ==$admin  and $text!=$buttonValues['cancel']){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];
    
    $time = time();

    
    if(isset($text)){
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        sendMessage("\[$ticketTitle] _{$ticketCat}_\n\n" . $text,json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]),"MarkDown", $userId);        
    }else{
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        $keyboard = json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]);
            
        sendPhoto($fileid, "\[$ticketTitle] _{$ticketCat}_\n\n" . $caption,$keyboard, "MarkDown", $userId);
    }
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 1 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    setUser();
    sendMessage("پیام شما با موفقیت ارسال شد ✅",$removeKeyboard);
}
if(preg_match('/freeTrial(\d+)_(?<buyType>\w+)/',$data,$match)) {
    $id = intval($match[1]);
    $testPlanForLimit = function_exists('v2raystore_getTestPlanById') ? v2raystore_getTestPlanById($id) : null;
    $__v2raystoreManagedTestPlan = is_array($testPlanForLimit);
 
    if($__v2raystoreManagedTestPlan && function_exists('v2raystore_canUserGetTestAccount') && !v2raystore_canUserGetTestAccount($userInfo, $from_id, $id)){
        $used = function_exists('v2raystore_getUserTestAccountUsedCount') ? v2raystore_getUserTestAccountUsedCount($userInfo, $id) : 1;
        $limit = function_exists('v2raystore_getTestAccountLimitText') ? v2raystore_getTestAccountLimitText($userInfo) : '1 بار';
        $serverText = function_exists('v2raystore_testPlanDisplayTitle') ? v2raystore_testPlanDisplayTitle($testPlanForLimit, true) : 'این سرور';
        $taken = function_exists('v2raystore_getUserTakenTestAccountLabels') ? v2raystore_getUserTakenTestAccountLabels($from_id) : [];
        $takenText = count($taken) ? "
گرفته‌شده: " . implode('، ', $taken) : '';
        alert("⚠️ شما قبلاً از {$serverText} کانفیگ تست دریافت کرده‌اید. تعداد استفاده از این مورد: {$used} | سقف مجاز: {$limit}" . $takenText, true);
        exit;
    }elseif(!$__v2raystoreManagedTestPlan && !function_exists('v2raystore_canUserGetTestAccount') && $userInfo['freetrial'] == 'used' and !($from_id == $admin) && (!function_exists('v2raystore_agentNormalPercentValue') || v2raystore_agentNormalPercentValue($userInfo) < 100)){
        alert('⚠️شما قبلا هدیه رایگان خود را دریافت کردید');
        exit;
    }
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $__v2raystoreManagedTestPlan = function_exists('v2raystore_isTestPlanRow') ? v2raystore_isTestPlanRow($file_detail) : (intval($file_detail['price'] ?? 0) === 0);

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $netType = $file_detail['type'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;
    
    $agentBought = false;
    if($match['buyType'] == "one" || $match['buyType'] == "much"){
        $agentBought = true;
        
        
        $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $id, $server_id, $volume ?? 0, 1) : $price;
    }
    
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0){
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }
    
    // ساخت اکانت تست نباید قبل از ساخت واقعی قفل بلندمدت روی کاربر بگذارد؛
    // محدودیت استفاده بعد از ساخت موفق و با v2raystore_markTestAccountUsed ثبت می‌شود.
    $__v2raystoreTestReserved = false;
    $__v2raystoreReleaseTestReservation = function() use (&$__v2raystoreTestReserved, $from_id){
        if($__v2raystoreTestReserved && function_exists('v2raystore_releaseTestAccountCreation')){
            v2raystore_releaseTestAccountCreation($from_id);
            $__v2raystoreTestReserved = false;
        }
    };
    // اگر وسط ساخت اکانت تست خطای ناگهانی/تایم‌اوت رخ بدهد، کاربر در حالت processing گیر نمی‌کند.
    register_shutdown_function(function() use (&$__v2raystoreTestReserved, $from_id){
        if($__v2raystoreTestReserved && function_exists('v2raystore_releaseTestAccountCreation')){
            v2raystore_releaseTestAccountCreation($from_id);
        }
    });

    if($__v2raystoreManagedTestPlan && function_exists('v2raystore_reserveTestAccountCreation')){
        if(!v2raystore_reserveTestAccountCreation($from_id, $id)){
            alert('⏳ درخواست قبلی اکانت تست هنوز در حال پردازش است یا چند لحظه پیش ثبت شده. لطفاً کمی صبر کن و دوباره تلاش کن.', true);
            exit;
        }
        $__v2raystoreTestReserved = true;
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();

    if($from_id == $admin && !empty($userInfo['temp'])){
        $remark = $userInfo['temp'];
        setUser('','temp');
    }else{
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
    }
    if($__v2raystoreManagedTestPlan){
        if(function_exists('v2raystore_applyTestRemarkPrefix')){
            $remark = v2raystore_applyTestRemarkPrefix($remark);
        }else{
            $remark = 'test-' . $remark;
        }
    }
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    if($inbound_id == 0){    
        if($serverType == "marzban"){
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $id);
            if(!$response->success){
                if($response->msg == "User already exists"){
                    $remark .= rand(1111,99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $id);
                }
            }
        }else{
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);

                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id);
            }
        }
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id); 
        if(!$response->success){
            if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id);
        }
    }
    if(is_null($response)){
        if(isset($__v2raystoreReleaseTestReservation) && is_callable($__v2raystoreReleaseTestReservation)) $__v2raystoreReleaseTestReservation();
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفاً مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        if(isset($__v2raystoreReleaseTestReservation) && is_callable($__v2raystoreReleaseTestReservation)) $__v2raystoreReleaseTestReservation();
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        if(isset($__v2raystoreReleaseTestReservation) && is_callable($__v2raystoreReleaseTestReservation)) $__v2raystoreReleaseTestReservation();
        alert('❌ | 😮 وای خطا داد لطفاً سریع به مدیر بگو ...');
        sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
	include_once 'phpqrcode/qrlib.php';
	
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
        $vraylink = [$subLink];
        $vray_link = json_encode($response->vray_links);
    }else{
        $token = RandomString(30);
        $subLink = (function_exists('v2raystore_runtimeWantsSub') ? v2raystore_runtimeWantsSub((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['subLinkState'] ?? 'off') == 'on')) ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : "";
        $vraylink = (function_exists('v2raystore_buildPlanInboundConnectionLinks') ? v2raystore_buildPlanInboundConnectionLinks($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain, $id) : getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain));
        $vray_link = json_encode($vraylink);
    }
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
    $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
        $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
        $__v2raystoreServerType = isset($serverType) ? $serverType : '';
        $__v2raystoreRemark = isset($remark) ? $remark : '';
        $__v2raystoreIsTestAccount = !empty($__v2raystoreManagedTestPlan);
        $__v2raystoreTestHeading = $__v2raystoreIsTestAccount ? '🧪 اکانت تست شما آماده شد' : null;
        $__v2raystoreTestExtra = $__v2raystoreIsTestAccount ? "🔋 حجم اکانت تست: <b>{$volume} گیگ</b>
⏰ مدت اعتبار تست: <b>{$days} روز</b>
ℹ️ این اکانت تست است و {$volume} گیگ حجم دارد." : '';
        $__v2raystoreLoopLinks = $vraylink;
        $__v2raystoreTestSendOk = false;
        if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, $__v2raystoreTestHeading, $__v2raystoreTestExtra, (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
            $__v2raystoreTestSendOk = true;
            $__v2raystoreLoopLinks = [];
        }
        foreach($__v2raystoreLoopLinks as $link){
        if($__v2raystoreIsTestAccount){
            $acc_text = "🧪 اکانت تست شما فعال شد
🔮 نام اکانت تست: $remark
🔋 حجم اکانت تست: $volume گیگ
⏰ مدت اعتبار تست: $days روز
ℹ️ این اکانت تست است و $volume گیگ حجم دارد.
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
        }else{
            $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز
" . ((function_exists('v2raystore_runtimeWantsConfig') ? v2raystore_runtimeWantsConfig((isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : (($botState['configLinkState'] ?? 'on') != 'off')) && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
        }
if($subLink != "") $acc_text .= "


\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString().".png";
        $ecc = 'L'; 
        $pixel_Size = 11;
        $frame_Size = 0;
        QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        $sendRes = sendPhoto($botUrl . $file, $acc_text,(function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])),"HTML", $__v2raystoreTargetUid);
        $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($sendRes) : true;
        if(!$sendOk){
            $sendRes = sendMessage($acc_text, (function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])), "HTML", $__v2raystoreTargetUid);
            $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($sendRes) : true;
        }
        if(!$sendOk){
            $plainText = function_exists('v2raystore_plainTextFromHtml') ? v2raystore_plainTextFromHtml($acc_text) : strip_tags($acc_text);
            $sendRes = sendMessage($plainText, (function_exists('v2raystore_configSentKeyboard') ? v2raystore_configSentKeyboard() : json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]])), null, $__v2raystoreTargetUid);
            $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($sendRes) : true;
        }
        if($sendOk) $__v2raystoreTestSendOk = true;
        unlink($file);
    }
    if(!$__v2raystoreTestSendOk && function_exists('v2raystore_reportSendMessage')){
        $safeRemark = function_exists('v2raystore_h') ? v2raystore_h($remark) : htmlspecialchars($remark, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        v2raystore_reportSendMessage('⚠️ خطای ارسال اکانت تست به کاربر', "🆔 کاربر: <code>{$from_id}</code>
🔮 ریمارک: <code>{$safeRemark}</code>
📝 سرویس روی پنل ساخته شد اما ارسال پیام کانفیگ تست به کاربر ناموفق بود. کاربر می‌تواند از «کانفیگ‌های من» دریافت کند.", v2raystore_reportPrivateKeyboard($from_id), 'test_account');
    }
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?)");
	$stmt->bind_param("isiiisssisiiii", $from_id, $token, $id, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $newOrderId = intval($connection->insert_id);
    $stmt->close();
    if(!empty($__v2raystoreManagedTestPlan) && function_exists('v2raystore_notifyTestAccountTaken')) v2raystore_notifyTestAccountTaken($newOrderId, $from_id, $file_detail['title'] ?? '', $remark, $volume, $days);
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    if(!empty($__v2raystoreManagedTestPlan)){
        if(function_exists('v2raystore_markTestAccountUsed')){
            $__v2raystoreMarkedTest = v2raystore_markTestAccountUsed($from_id, $id, $server_id, $inbound_id, $newOrderId);
            if(!$__v2raystoreMarkedTest){
                // پلن روی پنل ساخته شده؛ حتی اگر ثبت جدول مصرف خطا داد، کاربر نباید دوباره همان تست را بی‌نهایت بگیرد.
                setUser('used','freetrial');
            }
            if(isset($__v2raystoreTestReserved)) $__v2raystoreTestReserved = false;
        }else{
            setUser('used','freetrial');
            if(isset($__v2raystoreTestReserved)) $__v2raystoreTestReserved = false;
        }
    }else{
        if(isset($__v2raystoreTestReserved)) $__v2raystoreTestReserved = false;
    }    
}
if(preg_match('/^showMainButtonAns(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    editText($message_id,$info['value'],json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if(preg_match('/^marzbanHostSettings(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $match[1]);
    $stmt->execute();
    $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
    $stmt->close();
    
    $hosts = getMarzbanHosts($serverId)->inbounds;
    $networkType = array();
    foreach($hosts as $key => $inbound){
        $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"selectHost{$match[1]}*_*{$inbound->protocol}*_*{$inbound->tag}"]];
    }
    $networkType[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match[1]]];
    $networkType = json_encode(['inline_keyboard'=>$networkType]);
    editText($message_id, "لطفاً نوع شبکه های این پلن را انتخاب کنید",$networkType);
}
if(preg_match('/^selectHost(?<planId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $saveBtn = "ذخیره ✅";
    unset($markup[count($markup)-1]);
    if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);
    foreach($markup as $key => $keyboard){
        if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
    }
        
    if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
        $markup[] = [['text'=>$saveBtn,'callback_data'=>"saveServerHost" . $match['planId']]];
    }
    $markup[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match['planId']]];
    $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
    editKeys($markup);
}
if(preg_match('/^saveServerHost(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $inbounds = array();
    $proxies = array();
    unset($markup[count($markup)-1]);
    unset($markup[count($markup)-1]);
    
    foreach($markup as $key=>$value){
        $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
        if($state > 0){
            preg_match('/^selectHost(?<serverId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
            $inbounds[$info['protocol']][] = $tag;
            $proxies[$info['protocol']] = array();

            if($info['protocol'] == "vless"){
                $proxies["vless"] = ["flow" => ""];
            }
            elseif($info['protocol'] == "shadowsocks"){
                $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
            }
        }
    }
    $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=? WHERE `id`=?");
    $stmt->bind_param("si", $info, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id, "با موفقیت ذخیره شد",getPlanDetailsKeys($match[1]));
    setUser();
}
if($data=="rejectedAgentList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"لیست کاربران رد شده از نمایندگی",$keys);
    }else alert("کاربری یافت نشد");
}
if(preg_match('/^releaseRejectedAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['saved_successfuly']);
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"لیست کاربران رد شده از نمایندگی",$keys);
    }else editText($message_id,"کاربری یافت نشد",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"]]]]));
}
if($data=="showUUIDLeft" && ($botState['searchState']=="on" || $from_id== $admin)){
    delMessage();
    sendMessage($mainValues['send_config_uuid'],$cancelKey);
    setUser('showAccount');
}
if($userInfo['step'] == "showAccount" and $text != $buttonValues['cancel']){
    if(preg_match('/^vmess:\/\/(.*)/',$text,$match)){
        $jsonDecode = json_decode(base64_decode($match[1]),true);
        $text = $jsonDecode['id'];
        $marzbanText = $match[1];
    }elseif(preg_match('/^vless:\/\/(.*?)\@/',$text,$match)){
        $marzbanText = $text = $match[1];
    }elseif(preg_match('/^trojan:\/\/(.*?)\@/',$text,$match)){
        $marzbanText = $text = $match[1];
    }elseif(!preg_match('/[a-f0-9]{8}\-[a-f0-9]{4}\-4[a-f0-9]{3}\-(8|9|a|b)[a-f0-9]{3}\-[a-f0-9]{12}/', $text)){
        sendMessage($mainValues['not_correct_text']);
        exit();
    }
    $text = htmlspecialchars(stripslashes(trim($text)));
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `server_config`");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();
    $found = false; 
    $isMarzban = false;
    while($row = $serversList->fetch_assoc()){
        $serverId = $row['id'];
        $serverType = $row['type'];
        
        if($serverType == "marzban"){
            $usersList = getMarzbanJson($serverId)->users;
            if(strstr(json_encode($usersList, JSON_UNESCAPED_UNICODE), $marzbanText) && !empty($marzbanText)){
                $found = true;
                $isMarzban = true;
                foreach($usersList as $key => $config){
                    if(strstr(json_encode($config->links, JSON_UNESCAPED_UNICODE), $marzbanText)){
                	    $remark = $config->username;
                        $total = $config->data_limit!=0?sumerize($config->data_limit):"نامحدود";
                        $totalUsed = sumerize($config->used_traffic);
                        $state = $config->status == "active"?$buttonValues['active']:$buttonValues['deactive'];
                        $expiryTime = $config->expire != 0?jdate("Y-m-d H:i:s",$config->expire):"نامحدود";
                        $leftMb = $config->data_limit!=0?$config->data_limit - $config->used_traffic:"نامحدود";
                        
                        if(is_numeric($leftMb)){
                            if($leftMb<0) $leftMb = 0;
                            else $leftMb = sumerize($leftMb);
                        }
                        
                        $expiryDay = $config->expire != 0?
                            floor(
                                ($config->expire - time())/(60 * 60 * 24)
                                ):
                                "نامحدود";    
                        if(is_numeric($expiryDay)){
                            if($expiryDay<0) $expiryDay = 0;
                        }
                	    $configLocation = ["remark" => $remark ,"uuid" =>$text, "marzban"=>true];
                        break;
                    }
                }
                break;
            }
        }else{
            $response = getJson($serverId);
            if($response->success){
                if(strstr(json_encode($response->obj), $text)){
                    $found = true;
                    $list = $response->obj;
                    if(!isset($list[0]->clientStats)){
                        foreach($list as $keys=>$packageInfo){
                        	if(strstr($packageInfo->settings, $text)){
                        	    $configLocation = ["remark"=> $packageInfo->remark, "uuid" =>$text];
                        	    $remark = $packageInfo->remark;
                                $upload = sumerize($packageInfo->up);
                                $download = sumerize($packageInfo->down);
                                $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                                $total = $packageInfo->total!=0?sumerize($packageInfo->total):"نامحدود";
                                $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"نامحدود";
                                $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"نامحدود";
                                $expiryDay = $packageInfo->expiryTime != 0?
                                    floor(
                                        (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24))
                                        :
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                break;
                        	}
                        }
                    }
                    else{
                        $keys = -1;
                        $settings = array_column($list,'settings');
                        foreach($settings as $key => $value){
                        	if(strstr($value, $text)){
                        		$keys = $key;
                        		break;
                        	}
                        }
                        if($keys == -1){
                            $found = false;
                            break;
                        }
                        $clientsSettings = json_decode($list[$keys]->settings,true)['clients'];
                        if(!is_array($clientsSettings)){
                            sendMessage("با عرض پوزش، متأسفانه مشکلی رخ داده است، لطفاً مجدد اقدام کنید");
                            exit();
                        }
                        $settingsId = array_column($clientsSettings,'id');
                        $settingKey = array_search($text,$settingsId);
                        
                        if(!isset($clientsSettings[$settingKey]['email'])){
                            $packageInfo = $list[$keys];
                    	    $configLocation = ["remark" => $packageInfo->remark ,"uuid" =>$text];
                    	    $remark = $packageInfo->remark;
                            $upload = sumerize($packageInfo->up);
                            $download = sumerize($packageInfo->down);
                            $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                            $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                            $total = $packageInfo->total!=0?sumerize($packageInfo->total):"نامحدود";
                            $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"نامحدود";
                            $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"نامحدود";
                            if(is_numeric($leftMb)){
                                if($leftMb<0){
                                    $leftMb = 0;
                                }else{
                                    $leftMb = sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down);
                                }
                            }
    
                            
                            $expiryDay = $packageInfo->expiryTime != 0?
                                floor(
                                    (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24)
                                    ):
                                    "نامحدود";    
                            if(is_numeric($expiryDay)){
                                if($expiryDay<0) $expiryDay = 0;
                            }
                        }else{
                            $email = $clientsSettings[$settingKey]['email'];
                            $clientState = $list[$keys]->clientStats;
                            $emails = array_column($clientState,'email');
                            $emailKey = array_search($email,$emails);                    
                 
                            // if($clientState[$emailKey]->total != 0 || $clientState[$emailKey]->up != 0  ||  $clientState[$emailKey]->down != 0 || $clientState[$emailKey]->expiryTime != 0){
                            if(count($clientState) > 1){
                        	    $configLocation = ["id" => $list[$keys]->id, "remark"=>$email, "uuid"=>$text];
                                $upload = sumerize($clientState[$emailKey]->up);
                                $download = sumerize($clientState[$emailKey]->down);
                                $total = $clientState[$emailKey]->total==0 && $list[$keys]->total !=0?$list[$keys]->total:$clientState[$emailKey]->total;
                                $leftMb = $total!=0?($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down):"نامحدود";
                                if(is_numeric($leftMb)){
                                    if($leftMb<0){
                                        $leftMb = 0;
                                    }else{
                                        $leftMb = sumerize($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down);
                                    }
                                }
                                $totalUsed = sumerize($clientState[$emailKey]->up + $clientState[$emailKey]->down);
                                $total = $total!=0?sumerize($total):"نامحدود";
                                $expTime = $clientState[$emailKey]->expiryTime == 0 && $list[$keys]->expiryTime?$list[$keys]->expiryTime:$clientState[$emailKey]->expiryTime;
                                $expiryTime = $expTime != 0?jdate("Y-m-d H:i:s",substr($expTime,0,-3)):"نامحدود";
                                $expiryDay = $expTime != 0?
                                    floor(
                                        ((substr($expTime,0,-3)-time())/(60 * 60 * 24))
                                        ):
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                $state = $clientState[$emailKey]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $remark = $email;
                            }
                            else{
                                $clientUpload = $clientState[$emailKey]->up;
                                $clientDownload = $clientState[$emailKey]->down;
                                $clientTotal = $clientState[$emailKey]->total;
                                $clientExpTime = $clientState[$emailKey]->expiryTime;
                                
                                $up = $list[$keys]->up;
                                $down = $list[$keys]->down;
                                $total = $list[$keys]->total;
                                $expiry = $list[$keys]->expiryTime;
                                
                                if(($clientTotal != 0 || $clientTotal != null) && ($clientExpTime != 0 || $clientExpTime != null)){
                                    $up = $clientUpload;
                                    $down = $clientDownload;
                                    $total = $clientTotal;
                                    $expiry = $clientExpTime;
                                }
    
                                $upload = sumerize($up);
                                $download = sumerize($down);
                                $configLocation = ["uuid" => $text, "remark"=>$list[$keys]->remark];
                                $leftMb = $total!=0?($total - $up - $down):"نامحدود";
                                if(is_numeric($leftMb)){
                                    if($leftMb<0){
                                        $leftMb = 0;
                                    }else{
                                        $leftMb = sumerize($total - $up - $down);
                                    }
                                }
                                $totalUsed = sumerize($up + $down);
                                $total = $total!=0?sumerize($total):"نامحدود";
                                
                                
                                $expiryTime = $expiry != 0?jdate("Y-m-d H:i:s",substr($expiry,0,-3)):"نامحدود";
                                $expiryDay = $expiry != 0?
                                    floor(
                                        ((substr($expiry,0,-3)-time())/(60 * 60 * 24))
                                        ):
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                $state = $list[$keys]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $remark = $list[$keys]->remark;
                            }
                        }
                    }
                    break;
                }
            }
        }
    }
    if(!$found){
         sendMessage("ای وای ، اطلاعاتت اشتباهه 😔",$cancelKey);
    }else{
        setUser();
        $keys = json_encode(['inline_keyboard'=>array_merge([
        [
            ['text'=>$state??" ",'callback_data'=>"v2raystore"],
            ['text'=>"🔘 وضعیت اکانت 🔘",'callback_data'=>"v2raystore"],
            ],
        [
    		['text'=>$remark??" ",'callback_data'=>"v2raystore"],
            ['text'=>"« نام اکانت »",'callback_data'=>"v2raystore"],
            ]],(!$isMarzban?[
        [
            ['text'=>$upload?? " ",'callback_data'=>"v2raystore"],
            ['text'=>"√ آپلود √",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$download??" ",'callback_data'=>"v2raystore"],
            ['text'=>"√ دانلود √",'callback_data'=>"v2raystore"],
            ]]:[
        [
            ['text'=>$totalUsed?? " ",'callback_data'=>"v2raystore"],
            ['text'=>"√ آپلود + دانلود √",'callback_data'=>"v2raystore"],
            ]]),[
        [
            ['text'=>$total??" ",'callback_data'=>"v2raystore"],
            ['text'=>"† حجم کلی †",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$leftMb??" ",'callback_data'=>"v2raystore"],
            ['text'=>"~ حجم باقیمانده ~",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$expiryTime??" ",'callback_data'=>"v2raystore"],
            ['text'=>"تاریخ اتمام",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$expiryDay??" ",'callback_data'=>"v2raystore"],
            ['text'=>"تعداد روز باقیمانده",'callback_data'=>"v2raystore"],
            ],
        (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] == "on")?
            [
                ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId],
                ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId],
                ]:[]
                ),
        (($botState['renewAccountState'] != "on" && $botState['updateConfigLinkState'] == "on")?
            [
                ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId]
                ]:[]
                ),
        (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] != "on")?
            [
                ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId]
                ]:[]
                ),
        [['text'=>"صفحه اصلی",'callback_data'=>"mainMenu"]]
        ])]);
        setUser(json_encode($configLocation,488), "temp");
        sendMessage("🔰مشخصات حسابت:",$keys,"MarkDown");
    }
}

if(preg_match('/sConfigRenew(\d+)/', $data,$match)){
    if($botState['sellState']=="off" && $from_id !=$admin){ alert($mainValues['bot_is_updating']); exit(); }
    
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if(isset($configInfo['marzban'])){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `custom_sni` LIKE '%inbounds%' AND `active` = 1 AND `price` != 0");
        $stmt->bind_param("i", $server_id);
    }else{
        $response = getJson($server_id)->obj;
        if($response == null){delMessage(); exit();}
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` = 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` != 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
        }
        $stmt->bind_param("is", $server_id, $protocol);
    }
    
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    if($plans->num_rows > 0){
        $keyboard = [];
        while($file = $plans->fetch_assoc()){ 
            $add = false;
            
            if(isset($configInfo['marzban'])) $add = true;
            else{
                $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $isReality = $stmt->get_result()->fetch_assoc()['reality'];
                $stmt->close();
                
                if($isReality == $configReality) $add = true;
            }
            
            if($add){
                $id = $file['id'];
                $name = $file['title'];
                $price = $file['price'];
                $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه:

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage("💡پلنی در این دسته بندی وجود ندارد ");
}
if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];


    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1 AND COALESCE(`price`,0) != 0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$respd){
        alert($mainValues['no_plan_available'] ?? 'پلن پیدا نشد.', true);
        exit();
    }
    $planServerIdForSale = intval($respd['server_id'] ?? 0);
    if(function_exists('v2raystore_canUserBuyFromServer') && !v2raystore_canUserBuyFromServer($planServerIdForSale, $from_id, $userInfo ?? null, $match['buyType'] ?? '')){
        alert(function_exists('v2raystore_serverSaleClosedMessage') ? v2raystore_serverSaleClosedMessage() : 'فروش این سرور فعلاً بسته است.', true);
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_SCONFIG' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark, 'marzban' => isset($configInfo['marzban'])],488);
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiii", $hash_id, $description, $from_id, $id, $inbound_id, $price, $time);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    sendMessage(str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $price, $desc], $mainValues['buy_subscription_detail']), json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/sConfigUpdate(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];


    if(isset($configInfo['marzban'])){
        $info = getMarzbanUserInfo($server_id, $remark);
        $vraylink = $info->links;
    }else{
        $response = getJson($server_id)->obj;
        if($response == null){delMessage(); exit();}
        
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }
        
        if($uuid == null){delMessage(); exit();}
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId);
    }
    
    if($vraylink == null){delMessage(); exit();}
    include_once 'phpqrcode/qrlib.php';  
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH',540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT',540);
    $__v2raystoreTargetUid = isset($uid) ? $uid : (isset($from_id) ? $from_id : 0);
    $__v2raystoreSubLink = isset($subLink) ? $subLink : '';
    $__v2raystoreServerType = isset($serverType) ? $serverType : '';
    $__v2raystoreRemark = isset($remark) ? $remark : '';
    $__v2raystoreLoopLinks = $vraylink;
    if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($__v2raystoreTargetUid, $__v2raystoreRemark, $vraylink, $__v2raystoreSubLink, $__v2raystoreServerType, null, null, '', (function_exists('v2raystore_getRuntimeDeliveryLinkOptions') ? v2raystore_getRuntimeDeliveryLinkOptions($__v2raystoreTargetUid, (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), null, (isset($server_id)?$server_id:(isset($serverId)?$serverId:0))) : null))){
        $__v2raystoreLoopLinks = [];
    }
    foreach($__v2raystoreLoopLinks as $vray_link){
        $acc_text = $botState['configLinkState'] != "off"?"<code>$vray_link</code>":".";
    
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        $file = RandomString() .".png";
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        sendPhoto($botUrl . $file, $acc_text,null,"HTML");
        unlink($file);
    }
}

if (($data == 'addNewPlan' || $data=="addNewRahgozarPlan" || $data == "addNewMarzbanPlan") and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();
    if($data=="addNewPlan" || $data == "addNewMarzbanPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`)
                                            VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?);";
    }elseif($data=="addNewRahgozarPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`, `rahgozar`)
                    VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?, 1);";
    }
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $time);
    $stmt->execute();
    $stmt->close();
    delMessage();
    $msg = '❗️یه عنوان برا پلن انتخاب کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/(addNewRahgozarPlan|addNewPlan|addNewMarzbanPlan)/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $catkey = [];
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent` =0 and `active`=1");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    while ($cat = $cats->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $catkey[] = ["$id - $name"];
    }
    $catkey[] = [$buttonValues['cancel']];

    $step = checkStep('server_plans');

    if($step==1 and $text!=$buttonValues['cancel']){
        $msg = '🔰 لطفاً قیمت پلن رو به تومان وارد کنید!';
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = '🔰لطفاً یه دسته از لیست زیر برا پلن انتخاب کن ';
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey,'resize_keyboard'=>true]));
        }else{
            $msg = '‼️ لطفاً یک مقدار عددی وارد کنید';
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];

        $stmt = $connection->prepare("SELECT `id` FROM `server_config` WHERE `type` = 'marzban'");
        $stmt->execute();
        $info = $stmt->get_result()->fetch_all();
        $stmt->close();
        
        
        
        $marzbanList = array_column($info, 0); 
        if(count($marzbanList) > 0) $condition  = " AND `id` " .($userInfo['step'] == "addNewMarzbanPlan"?"IN":"NOT IN") . " (" . implode(", ", $marzbanList) . ")";
        else $condition = "";


        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 $condition");
        $stmt->execute();
        
        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage("لطفاً یکی از سرورها رو انتخاب کن 👇 ", json_encode([
                'inline_keyboard' => $srvkey]), "HTML");
        $inarr = 0;
        foreach ($catkey as $op) {
            if (in_array($text, $op) and $text != $buttonValues['cancel']) {
                $inarr = 1;
            }
        }
        if( $inarr==1 ){
            $input = explode(' - ',$text);
            $catid = $input[0];
            $stmt = $connection->prepare("UPDATE `server_plans` SET `catid`=?,`step`=50 WHERE `active`=0");
            $stmt->bind_param("i", $catid);
            $stmt->execute();
            $stmt->close();

            sendMessage($msg,$cancelKey);
        }else{
            $msg = '‼️ لطفاً فقط یکی از گزینه های پیشنهادی زیر را انتخاب کنید';
            sendMessage($msg,$catkey);
        }
    } 
    if($step==50 and $text!=$buttonValues['cancel'] and preg_match('/selectNewPlanServer(\d+)/', $data,$match)){
        $newStep = $userInfo['step'] == "addNewMarzbanPlan"?53:51;
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `server_id`=?,`step`=? WHERE `active`=0");
        $stmt->bind_param("ii", $match[1], $newStep);
        $stmt->execute();
        $stmt->close();

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"🎖پورت اختصاصی",'callback_data'=>"withSpecificPort"]],
            [['text'=>"🎗پورت اشتراکی",'callback_data'=>"withSharedPort"]]
            ]]);
        if($userInfo['step'] != "addNewMarzbanPlan") editText($message_id, "لطفاً نوعیت پورت پنل رو انتخاب کنید", $keys);
        else editText($message_id, "📅 | لطفاً تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==51 and $text!=$buttonValues['cancel'] and preg_match('/^with(Specific|Shared)Port/',$data,$match)){
        if($userInfo['step'] == "addNewRahgozarPlan") $msg =  "📡 | لطفاً پروتکل پلن مورد نظر را وارد کنید (vless | vmess)";
        else $msg =  "📡 | لطفاً پروتکل پلن مورد نظر را وارد کنید (vless | vmess | trojan)";
        editText($message_id,$msg);
        if($match[1] == "Shared"){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `step`=60 WHERE `active`=0");
            $stmt->execute();
            $stmt->close();
        }
        elseif($match[1] == "Specific"){
            $stmt = $connection->prepare("UPDATE server_plans SET step=52 WHERE active=0");
            $stmt->execute();
            $stmt->close();
        }
    }
    if($step==60 and $text!=$buttonValues['cancel']){
        if($text != "vless" && $text != "vmess" && $text != "trojan" && $userInfo['step'] == "addNewPlan"){
            sendMessage("لطفاً فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        elseif($text != "vless" && $text != "vmess" && $userInfo['step'] == "addNewRahgozarPlan"){
            sendMessage("لطفاً فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=61 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("📅 | لطفاً تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==61 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=62 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | لطفاً مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==62 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `volume`=?,`step`=63 WHERE `active`=0");
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("🛡 | لطفاً آیدی سطر کانکشن در پنل را وارد کنید:");
    }
    if($step==63 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        
        $response = getJson($res['server_id'])->obj;
        foreach($response as $row){
            if($row->id == $text) {
                $netType = json_decode($row->streamSettings)->network;
            }
        }        
        if(is_null($netType)){
            sendMessage("کانفیگی با این سطر آیدی یافت نشد");
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type` = ?, `inbound_id`=?,`step`=64 WHERE `active`=0");
        $stmt->bind_param("si", $netType, $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("لطفاً ظرفیت تعداد اکانت رو پورت مورد نظر را وارد کنید");
    }
    if($step==64 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=?,`step`=65 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🧲 | لطفاً تعداد چند کاربره این پلن را وارد کنید ( 0 نامحدود است )");
    }
    if($step==65 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `limitip`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    if($step==52 and $text!=$buttonValues['cancel']){
        if($userInfo['step'] == "addNewPlan" && $text != "vless" && $text != "vmess" && $text != "trojan"){
            sendMessage("لطفاً فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }elseif($userInfo['step'] == "addNewRahgozarPlan" && $text != "vless" && $text != "vmess"){
            sendMessage("لطفاً فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=53 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("📅 | لطفاً تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==53 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=54 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | لطفاً مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==54 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        if($userInfo['step'] == "addNewPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?,`step`=55 WHERE `active`=0");
            $msg = "🔉 | لطفاً نوع شبکه این پلن را انتخاب کنید (ws | tcp | grpc | httpupgrade) :";
        }elseif($userInfo['step'] == "addNewRahgozarPlan" || $userInfo['step'] == "addNewMarzbanPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?, `type`='ws', `step`=4 WHERE `active`=0");
            $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        }
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage($msg);
    }
    if($step==55 and $text!=$buttonValues['cancel']){
        if($text != "tcp" && $text != "ws" && $text != "grpc" && $text != "httpupgrade"){
            sendMessage("لطفاً فقط نوع (ws | tcp | grpc | httpupgrade) را وارد کنید");
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    
    if($step==4 and $text!=$buttonValues['cancel']){
        
        if($userInfo['step'] == "addNewMarzbanPlan"){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0 AND `step` = 4");
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
        
            $hosts = getMarzbanHosts($serverId)->inbounds;
            $networkType = array();
            foreach($hosts as $key => $inbound){
                $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"planNetworkType{$inbound->protocol}*_*{$inbound->tag}"]];
            }
            $networkType = json_encode(['inline_keyboard'=>$networkType]);

            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `step` = 5 WHERE `step` = 4");
            sendMessage("لطفاً نوع شبکه های این پلن را انتخاب کنید",$networkType);
        }
        else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `active`=1,`step`=10 WHERE `step`=4");
            $imgtxt = '☑️ | پنل با موفقیت ثبت و ایجاد شد ( لذت ببرید ) ';
            
            sendMessage($imgtxt,$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
            setUser();
        }
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

    } 
    elseif($step == 5 and $text != $buttonValues['cancel'] && preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match)){
        $saveBtn = "ذخیره ✅";
        if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);

        foreach($markup as $key => $keyboard){
            if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
        }

        if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
            $markup[] = [['text'=>$saveBtn,'callback_data'=>"savePlanNetworkType"]];
        }
        $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
        
        editKeys($markup);
    }
    elseif($step == 5 && $text != $buttonValues['cancel'] && $data == "savePlanNetworkType"){
        delMessage();
        $inbounds = array();
        $proxies = array();
        unset($markup[count($markup)-1]);

        foreach($markup as $key=>$value){
            $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
            if($state > 0){
                preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
                $inbounds[$info['protocol']][] = $tag;
                $proxies[$info['protocol']] = array();
    
                if($info['protocol'] == "vless"){
                    $proxies["vless"] = ["flow" => ""];
                }
                elseif($info['protocol'] == "shadowsocks"){
                    $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
                }
            }
        }
        
        $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=?, `active`=1,`step`=10 WHERE `step`=5");
        $stmt->bind_param("s", $info);
        $stmt->execute();
        $stmt->close();
        
        $imgtxt = '☑️ | پنل با موفقیت ثبت و ایجاد شد ( لذت ببرید ) ';
        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeysPlus());
        setUser();
    }
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"➖➖➖",'callback_data'=>"v2raystore"]];
    $keyboard[] = [['text'=>'➕ افزودن پلن اختصاصی و اشتراکی','callback_data'=>"addNewPlan"]];
    $keyboard[] = [
        ['text'=>'➕ افزودن پلن رهگذر','callback_data'=>"addNewRahgozarPlan"],
        ['text'=>"افزودن پلن مرزبان",'callback_data'=>"addNewMarzbanPlan"]
                    ];
    $keyboard[] = [['text'=>'➕ افزودن پلن حجمی','callback_data'=>"volumePlanSettings"],['text'=>'➕ افزودن پلن زمانی','callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => "➕ افزودن پلن دلخواه", 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = ' ☑️ مدیریت پلن ها:';
    
    if(isset($data) and $data=='backplan') {
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]));
    }
    
    
    exit;
}
if(($data=="editCustomPlan" || preg_match('/^editCustom(gbPrice|dayPrice)/',$userInfo['step'],$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($data)){
        if(is_numeric($text)){
            setSettings($match[1], $text);
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard); 
        }else{
            sendMessage("فقط عدد ارسال کن");
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . " تومان";
    $dayPrice=number_format($botState['dayPrice']??0) . " تومان";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>"هزینه هر گیگ",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>"هزینه هر روز",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]
            
        ]]);
    if(!isset($data)){
        sendMessage("تنظیمات پلن دلخواه",$keys);
        setUser();
    }else{
        editText($message_id,"تنظیمات پلن دلخواه",$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $title = $match[1] == "dayPrice"?"هر روز":"هر گیگ";
    sendMessage("لطفاً هزینه " . $title . " را به تومان وارد کنید",$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? AND COALESCE(`price`,0) != 0 ORDER BY`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert("متاسفانه، هیچ پلنی براش انتخاب نکردی 😑");
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"],];
        $msg = ' ▫️ یه پلن رو انتخاب کن بریم برای ادیت:';
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}

function v2raystore_planMultiInboundManageView($planId){
    global $connection, $buttonValues;
    $planId = intval($planId);
    $stmt = @$connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? LIMIT 1");
    if(!$stmt) return ['text'=>'خطا در خواندن پلن.', 'keyboard'=>json_encode(['inline_keyboard'=>[]])];
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$plan) return ['text'=>'پلن پیدا نشد.', 'keyboard'=>json_encode(['inline_keyboard'=>[]])];

    $serverId = intval($plan['server_id'] ?? 0);
    $stmt = @$connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    if(!$stmt) return ['text'=>'خطا در خواندن سرور.', 'keyboard'=>json_encode(['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'planDetails'.$planId]]]])];
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $server = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$server || ($server['type'] ?? '') !== 'sanaei_new'){
        return [
            'text'=>"🚪 تنظیم چند اینباند فقط برای سنایی جدید فعال است.\n\nبرای نسخه‌های قدیمی همان اینباند تکی پلن استفاده می‌شود.",
            'keyboard'=>json_encode(['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'planDetails'.$planId]]]], JSON_UNESCAPED_UNICODE)
        ];
    }

    $selected = function_exists('v2raystore_planInboundIds') ? v2raystore_planInboundIds($plan, true) : [intval($plan['inbound_id'] ?? 0)];
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected))));
    $json = getJson($serverId);
    $rows = ($json && !empty($json->success) && isset($json->obj) && is_array($json->obj)) ? $json->obj : [];

    $keyboard = [];
    foreach($rows as $row){
        if(!is_object($row)) continue;
        $iid = intval($row->id ?? 0);
        if($iid <= 0) continue;
        $remark = trim((string)($row->remark ?? ''));
        $protocol = trim((string)($row->protocol ?? ''));
        $checked = in_array($iid, $selected, true) ? '✅' : '▫️';
        $title = $checked . ' #' . $iid . ($remark !== '' ? ' - ' . $remark : '') . ($protocol !== '' ? ' (' . $protocol . ')' : '');
        if(function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 48) $title = mb_substr($title, 0, 45, 'UTF-8') . '...';
        $keyboard[] = [['text'=>$title, 'callback_data'=>'togglePlanInbound_'.$planId.'_'.$iid]];
    }

    if(empty($keyboard)) $keyboard[] = [['text'=>'هیچ inbound از پنل دریافت نشد', 'callback_data'=>'v2raystore']];
    else{
        $keyboard[] = [
            ['text'=>'✅ انتخاب همه', 'callback_data'=>'planInboundAll_'.$planId],
            ['text'=>'🧹 پاک کردن', 'callback_data'=>'planInboundClear_'.$planId],
        ];
    }
    $keyboard[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'planDetails'.$planId]];

    $summary = function_exists('v2raystore_planMultiInboundSummary') ? v2raystore_planMultiInboundSummary($plan) : implode(',', $selected);
    $text = "🚪 تنظیم چند اینباند برای پلن\n\n";
    $text .= "پلن: <b>" . htmlspecialchars((string)($plan['title'] ?? $planId), ENT_QUOTES, 'UTF-8') . "</b>\n";
    $text .= "وضعیت فعلی: <code>" . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . "</code>\n\n";
    $text .= "با انتخاب چند اینباند، کاربر هنگام خرید همین پلن روی همه اینباندهای انتخابی سنایی جدید ثبت می‌شود و لینک‌های همان کلاینت برای کاربر ارسال می‌شود.\n";
    $text .= "اولین اینباند انتخاب‌شده به‌عنوان اینباند اصلی سفارش ذخیره می‌شود.";

    return ['text'=>$text, 'keyboard'=>json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE)];
}




function v2raystore_inboundAddressManageView($serverId){
    global $connection, $buttonValues;
    $serverId = intval($serverId);
    $stmt = @$connection->prepare("SELECT si.`title`, sc.* FROM `server_config` sc LEFT JOIN `server_info` si ON si.`id`=sc.`id` WHERE sc.`id`=? LIMIT 1");
    if(!$stmt) return ['text'=>'خطا در خواندن سرور.', 'keyboard'=>json_encode(['inline_keyboard'=>[]])];
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $server = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server) return ['text'=>'سرور پیدا نشد.', 'keyboard'=>json_encode(['inline_keyboard'=>[]])];
    if(($server['type'] ?? '') !== 'sanaei_new'){
        return [
            'text'=>'🌐 آدرس اینباند فقط برای سنایی جدید فعال است. برای بقیه پنل‌ها همان آدرس سرور قبلی استفاده می‌شود.',
            'keyboard'=>json_encode(['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'showServerSettings'.$serverId.'_0']]]], JSON_UNESCAPED_UNICODE)
        ];
    }
    $json = getJson($serverId);
    $rows = ($json && !empty($json->success) && isset($json->obj) && is_array($json->obj)) ? $json->obj : [];
    $keyboard = [];
    foreach($rows as $row){
        if(!is_object($row)) continue;
        $iid = intval($row->id ?? 0);
        if($iid <= 0) continue;
        $remark = trim((string)($row->remark ?? ''));
        $protocol = trim((string)($row->protocol ?? ''));
        $summary = function_exists('v2raystore_inboundAddressSummary') ? v2raystore_inboundAddressSummary($serverId, $iid, 24) : 'تنظیم';
        $title = '#' . $iid . ($remark !== '' ? ' - ' . $remark : '') . ($protocol !== '' ? ' (' . $protocol . ')' : '') . ' | ' . $summary;
        if(function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 54) $title = mb_substr($title, 0, 51, 'UTF-8') . '...';
        elseif(strlen($title) > 54) $title = substr($title, 0, 51) . '...';
        $keyboard[] = [['text'=>$title, 'callback_data'=>'editInboundAddr_'.$serverId.'_'.$iid]];
    }
    if(empty($keyboard)) $keyboard[] = [['text'=>'هیچ اینباندی از پنل دریافت نشد','callback_data'=>'v2raystore']];
    $keyboard[] = [['text'=>'⬅️ بازگشت','callback_data'=>'showServerSettings'.$serverId.'_0']];
    $title = htmlspecialchars((string)($server['title'] ?? $serverId), ENT_QUOTES, 'UTF-8');
    $text = "🌐 آدرس اینباندها\n\nسرور: <b>{$title}</b>\n\nبرای هر inbound می‌توانی یک یا چند دامنه/IP جداگانه ثبت کنی. لینک‌های همان inbound با همان آدرس‌ها ساخته می‌شوند.\nاگر برای inbound آدرس ثبت نکنی، رفتار قبلی ربات استفاده می‌شود.";
    return ['text'=>$text, 'keyboard'=>json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE)];
}

if(preg_match('/^v2raystoreplanmultiinbounds(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $view = v2raystore_planMultiInboundManageView($match[1]);
    editText($message_id, $view['text'], $view['keyboard'], 'HTML');
    exit;
}
if(preg_match('/^togglePlanInbound_(\d+)_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $planId = intval($match[1]);
    $iid = intval($match[2]);
    $plan = function_exists('v2raystore_getPlanRow') ? v2raystore_getPlanRow($planId) : null;
    if(!$plan){ alert('پلن پیدا نشد.'); exit; }
    if(function_exists('v2raystore_planIsSanaeiNew') && !v2raystore_planIsSanaeiNew($plan)){
        alert('چند اینباند فقط برای سنایی جدید فعال است.');
        $view = v2raystore_planMultiInboundManageView($planId);
        editText($message_id, $view['text'], $view['keyboard'], 'HTML');
        exit;
    }
    $ids = function_exists('v2raystore_planInboundIds') ? v2raystore_planInboundIds($plan, true) : [intval($plan['inbound_id'] ?? 0)];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if(in_array($iid, $ids, true)){
        $ids = array_values(array_filter($ids, function($v) use ($iid){ return intval($v) !== $iid; }));
    }else{
        $ids[] = $iid;
    }
    if(function_exists('v2raystore_savePlanMultiInboundIds')) v2raystore_savePlanMultiInboundIds($planId, $ids);
    $view = v2raystore_planMultiInboundManageView($planId);
    editText($message_id, $view['text'], $view['keyboard'], 'HTML');
    exit;
}
if(preg_match('/^planInboundAll_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $planId = intval($match[1]);
    $plan = function_exists('v2raystore_getPlanRow') ? v2raystore_getPlanRow($planId) : null;
    if(!$plan){ alert('پلن پیدا نشد.'); exit; }
    if(function_exists('v2raystore_planIsSanaeiNew') && !v2raystore_planIsSanaeiNew($plan)){
        alert('چند اینباند فقط برای سنایی جدید فعال است.');
        $view = v2raystore_planMultiInboundManageView($planId);
        editText($message_id, $view['text'], $view['keyboard'], 'HTML');
        exit;
    }
    $serverId = intval($plan['server_id'] ?? 0);
    $json = getJson($serverId);
    $ids = [];
    if($json && !empty($json->success) && isset($json->obj) && is_array($json->obj)){
        foreach($json->obj as $row){
            if(is_object($row) && intval($row->id ?? 0) > 0) $ids[] = intval($row->id);
        }
    }
    if(function_exists('v2raystore_savePlanMultiInboundIds')) v2raystore_savePlanMultiInboundIds($planId, $ids);
    $view = v2raystore_planMultiInboundManageView($planId);
    editText($message_id, $view['text'], $view['keyboard'], 'HTML');
    exit;
}
if(preg_match('/^planInboundClear_(\d+)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $planId = intval($match[1]);
    $plan = function_exists('v2raystore_getPlanRow') ? v2raystore_getPlanRow($planId) : null;
    if($plan && function_exists('v2raystore_planIsSanaeiNew') && !v2raystore_planIsSanaeiNew($plan)){
        alert('چند اینباند فقط برای سنایی جدید فعال است.');
    }else{
        if(function_exists('v2raystore_savePlanMultiInboundIds')) v2raystore_savePlanMultiInboundIds($planId, []);
    }
    $view = v2raystore_planMultiInboundManageView($planId);
    editText($message_id, $view['text'], $view['keyboard'], 'HTML');
    exit;
}

if(preg_match('/planDetails(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else editText($message_id, "ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^v2raystoreplantoggleactive(\d+)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $pid = intval($match[1]);

    $stmt = $connection->prepare("SELECT `id`, `price`, `step`, `active` FROM `server_plans` WHERE `id`=? LIMIT 1");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $planRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$planRow){
        alert("پلن پیدا نشد.", true);
        exit;
    }
    if(intval($planRow['price'] ?? 0) === 0){
        alert("فعال/غیرفعال کردن اکانت تست فقط از بخش مدیریت اکانت تست انجام می‌شود.", true);
        exit;
    }
    if(intval($planRow['step'] ?? 0) !== 10){
        alert("این پلن هنوز کامل ثبت نشده و قابل تغییر وضعیت نیست.", true);
        exit;
    }

    $stmt = $connection->prepare("UPDATE `server_plans` SET `active` = IF(`active`=1,0,1) WHERE `id`=? AND COALESCE(`price`,0) != 0 AND `step`=10 LIMIT 1");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if($changed > 0) alert("وضعیت پلن تغییر کرد.");
    else alert("وضعیت پلن تغییر نکرد.", true);

    $keys = getPlanDetailsKeys($pid);
    if($keys == null){
        editText($message_id, "پلن پیدا نشد.", getMainKeys());
    }else{
        editText($message_id, "ویرایش تنظیمات پلن", $keys, "HTML");
    }
    exit;
}
if(preg_match('/^v2raystoreplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert('لیست خالی است');
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = " 🚀 ".$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = "$sold \n  ☑️ $remark ";
        foreach($orderLink as $link){
            $txt .= $botState['configLinkState'] != "off"?"<code>".$link."</code> \n":"";
        }
        $txt .= "\n ❗ $channelLock \n";
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^v2raystoreplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن رو برات حذفش کردم ☹️☑️");
    
    editText($message_id,"لطفاً یکی از کلید های زیر را انتخاب کنید",getMainKeys());
}
if(preg_match('/^v2raystoreplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 یه اسم برا پلن جدید انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^v2raystoreplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys);
}
if(preg_match('/^v2raystoreplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 ظرفیت جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^v2raystoreplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^v2raystoreplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 سطر جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^v2raystoreplansinobundid(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^v2raystoreplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 توضیحاتت رو برام وارد کن:",$cancelKey);exit;
}
if(preg_match('/^v2raystoreplaneditdes(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 dest رو برام وارد کن:\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editDestName(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) &&  $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest` = NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 spiderX رو برام وارد کن\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editSpiderX(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 serverNames رو به صورت زیر برام وارد کن:\n
`[
  \"yahoo.com\",
  \"www.yahoo.com\"
]`
    \n\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editServerNames(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"None", 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>"xtls-rprx-vision", 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage("🎯 لطفاً یکی از موارد زیر رو انتخاب کن",$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    editText($message_id, "ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^v2raystoreplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 شیطون قیمت و گرون کردی 😂 ، خب قیمت جدید و بزن ببینم :",$cancelKey);exit;
}
if(preg_match('/^v2raystoreplanrial(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)&& $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
        setUser();
        
        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert("موردی یافت نشد");
            exit;
        }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
    }else{
        sendMessage("بهت میگم قیمت وارد کن برداشتی یه چیز دیگه نوشتی 🫤 ( عدد وارد کن ) عجبا");
    }
}
if($data == 'mySubscriptions' || $data == "agentConfigsList" || preg_match('/^(changeAgentOrder|changeOrdersPage)(\d+)$/',$data, $match)){
    // نمایش کانفیگ‌های کاربر/نماینده نباید به روشن بودن فروش وابسته باشد.
    // فروش ممکن است بسته باشد، اما کاربر باید بتواند سرویس‌های قبلی خود را ببیند.
    $results_per_page = 50;
    $isAgentConfigList = ($data == "agentConfigsList") || (isset($match[1]) && $match[1] == "changeAgentOrder");
    $page = isset($match[2]) ? intval($match[2]) : 1;
    if($page < 1) $page = 1;

    if($isAgentConfigList){
        $stmt = $connection->prepare("SELECT COUNT(*) AS cnt FROM `orders_list` WHERE `userid`=? AND `status`=1");
    }else{
        $stmt = $connection->prepare("SELECT COUNT(*) AS cnt FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0");
    }
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $number_of_result = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if($number_of_result <= 0){
        $keyboard = [
            [['text'=>'➕ ثبت کانفیگ با لینک', 'callback_data'=>'addMyConfigByLink']],
            [['text'=>$buttonValues['back_to_main'], 'callback_data'=>'mainMenu']]
        ];
        editText($message_id, "📦 هنوز کانفیگی داخل حساب شما ثبت نشده است.

اگر از قبل لینک کانفیگ یا لینک ساب دارید، می‌توانید آن را داخل ربات ثبت کنید تا در همین بخش نمایش داده شود.", json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE));
        exit;
    }

    $number_of_page = max(1, ceil($number_of_result / $results_per_page));
    if($page > $number_of_page) $page = $number_of_page;
    $page_first_result = ($page - 1) * $results_per_page;

    if($isAgentConfigList){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
    }else{
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0 ORDER BY `id` DESC LIMIT ?, ?");
    }
    $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    if($orders->num_rows == 0){
        alert($mainValues['you_dont_have_config']);
        exit;
    }

    $ordersRows = [];
    while($cat = $orders->fetch_assoc()) $ordersRows[] = $cat;
    if(function_exists('v2raystore_pro_refresh_last_online_for_orders')){
        $ordersRows = v2raystore_pro_refresh_last_online_for_orders($ordersRows, 12);
    }

    $keyboard = [];
    foreach($ordersRows as $cat){
        $id = intval($cat['id']);
        $remark = $cat['remark'];
        $lastOnlineLabel = function_exists('v2raystore_pro_cached_last_online_label_for_order') ? v2raystore_pro_cached_last_online_label_for_order($cat) : '';
        $btnText = trim($remark . ($lastOnlineLabel !== '' ? ' | ' . $lastOnlineLabel : ''));
        $keyboard[] = [['text' => $btnText, 'callback_data' => "orderDetails$id"]];
    }

    $prev = $page - 1;
    $next = $page + 1;
    $buttons = [];
    if($prev > 0){
        $buttons[] = ['text' => "◀", 'callback_data' => ($isAgentConfigList ? "changeAgentOrder$prev" : "changeOrdersPage$prev")];
    }
    if($next <= $number_of_page){
        $buttons[] = ['text' => "➡", 'callback_data' => ($isAgentConfigList ? "changeAgentOrder$next" : "changeOrdersPage$next")];
    }
    if(!empty($buttons)) $keyboard[] = $buttons;

    if($isAgentConfigList) $keyboard[] = [['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchAgentConfig"]];
    else {
        $keyboard[] = [
            ['text'=>'➕ ثبت کانفیگ با لینک','callback_data'=>"addMyConfigByLink"],
            ['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchMyConfig"]
        ];
    }
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];

    $listText = $mainValues['select_one_to_show_detail'];
    if(!$isAgentConfigList && function_exists('v2raystore_smartRenewCandidateFromOrders') && (!function_exists('v2raystore_botFeatureEnabled') || v2raystore_botFeatureEnabled('smartRenewState', 'on'))){
        $smartCandidate = v2raystore_smartRenewCandidateFromOrders($ordersRows);
        if($smartCandidate){
            $keyboard = array_merge(v2raystore_smartRenewRows($smartCandidate), $keyboard);
            $listText = v2raystore_smartRenewHeaderText($smartCandidate) . $listText;
        }
    }
    editText($message_id, $listText, json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE), 'HTML');
    exit;
}

if($data == "addMyConfigByLink"){
    delMessage();
    setUser('addMyConfigByLink');
    sendMessage("🔗 لینک کانفیگ یا لینک ساب خودتان را ارسال کنید.

ربات داخل سرورهای ثبت‌شده می‌گردد، اگر این کانفیگ را در پنل پیدا کند با همان نامی که در پنل ثبت شده به حساب شما اضافه می‌کند.

اگر برای سرور چند دامنه داخل ربات ثبت شده باشد، همه لینک‌ها مثل خرید عادی برای شما ساخته و نمایش داده می‌شود.", $cancelKey, 'HTML');
    exit();
}

if($userInfo['step'] == 'addMyConfigByLink' && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    [$ok, $result] = farid_registerManualConfigForUser($from_id, $text);
    setUser();
    if(!$ok){
        sendMessage("❌ ثبت کانفیگ انجام نشد:

" . htmlspecialchars((string)$result, ENT_QUOTES, 'UTF-8'), json_encode(['inline_keyboard'=>[
            [['text'=>'↩️ تلاش دوباره','callback_data'=>'addMyConfigByLink']],
            [['text'=>$buttonValues['back_button'],'callback_data'=>'mySubscriptions']]
        ]], JSON_UNESCAPED_UNICODE), 'HTML');
        exit();
    }

    $already = !empty($result['already_exists']);
    $msg = ($already ? "♻️ این کانفیگ قبلاً ثبت شده بود و لینک‌های آن بروزرسانی شد." : "✅ کانفیگ با موفقیت به حساب شما اضافه شد.") . "

" .
           "🔮 نام سرویس: <b>" . htmlspecialchars($result['remark']) . "</b>
" .
           "🧾 شماره سفارش: <code>" . intval($result['order_id']) . "</code>
" .
           "🔗 تعداد لینک ساخته‌شده: <code>" . intval($result['links_count'] ?? 0) . "</code>";
    sendMessage($msg, json_encode(['inline_keyboard'=>[
        [['text'=>'📦 مشاهده کانفیگ‌های من','callback_data'=>'mySubscriptions']],
        [['text'=>'➕ ثبت لینک دیگر','callback_data'=>'addMyConfigByLink']],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}

if($data=="searchAgentConfig" || $data == "searchMyConfig" || $data=="searchUsersConfig"){
    delMessage();
    sendMessage("🔎 نام سرویس، لینک کانفیگ یا لینک ساب را ارسال کنید:",$cancelKey);
    setUser($data);
}
if(($userInfo['step'] == "searchAgentConfig" || $userInfo['step'] == "searchMyConfig") && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    $orderId = farid_findOrderIdBySearchText($text, $from_id, $userInfo['step'] == "searchMyConfig");
    
    $keys = getOrderDetailKeys($from_id, $orderId);
    if($keys != null){
        $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $orderId);
        $keys['keyboard'] = farid_attachUpdateAllMyConfigsButton($keys['keyboard']);
    }
    if($keys == null) sendMessage($mainValues['no_order_found']); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}
if(($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']) || preg_match('/^userOrderDetails(\d+)_(\d+)/',$data,$match)){
    if(isset($data)){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else{
        sendMessage($mainValues['please_wait_message'], $removeKeyboard); 
        $foundOrderId = farid_findOrderIdBySearchText($text, 0, false);
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $foundOrderId);
    }
    $stmt->execute();
    $orderInfo = $stmt->get_result();
    $stmt->close();
    

    if($orderInfo->num_rows == 0) sendMessage($mainValues['no_order_found']); 
    else {
        $row = $orderInfo->fetch_assoc();
        $orderId = intval($row['id']);
        $ownerUid = intval($row['userid'] ?? 0);

        $keys = getUserOrderDetailKeys($orderId, isset($data)?$match[2]:0);
        if($keys == null) sendMessage($mainValues['no_order_found']); 
        else{
            // ✅ دکمه‌های به‌روزرسانی (تکی + همه کانفیگ‌های کاربر)
            $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $orderId);
            if($ownerUid > 0) $keys['keyboard'] = farid_attachUpdateAllUserConfigsButton($keys['keyboard'], $ownerUid);

            if(!isset($data)) sendMessage($keys['msg'], $keys['keyboard'], "HTML");
            else editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
            setUser();
        }
    }
}


function v2raystore_supportPrefillUrl($messageText = ''){
    global $admin, $connection;
    $messageText = trim((string)$messageText);
    $encodedText = $messageText !== '' ? rawurlencode($messageText) : '';
    $adminId = intval($admin ?? 0);
    $adminUsername = '';

    if($adminId > 0 && isset($connection) && $connection instanceof mysqli){
        $stmt = @$connection->prepare("SELECT `username` FROM `users` WHERE `userid` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i', $adminId);
            if($stmt->execute()){
                $row = $stmt->get_result()->fetch_assoc();
                $adminUsername = trim((string)($row['username'] ?? ''));
            }
            $stmt->close();
        }
    }

    if(function_exists('v2raystore_cleanTelegramUsernameValue')){
        $adminUsername = v2raystore_cleanTelegramUsernameValue($adminUsername);
    }else{
        $adminUsername = ltrim($adminUsername, '@');
        if($adminUsername === '-' || $adminUsername === 'ندارد') $adminUsername = '';
    }

    if($adminUsername !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $adminUsername)){
        // برای پر شدن متن داخل پی‌وی، لینک tg://resolve در اکثر کلاینت‌های تلگرام بهتر از https://t.me/... عمل می‌کند.
        return 'tg://resolve?domain=' . $adminUsername . ($encodedText !== '' ? '&text=' . $encodedText : '');
    }

    // لینک عددی تلگرام متن آماده را پشتیبانی نمی‌کند؛ اگر یوزرنیم ادمین ثبت نشده باشد، فقط پی‌وی باز می‌شود.
    return 'tg://user?id=' . $adminId;
}

function v2raystore_diagSupportText($remark = ''){
    $remark = trim((string)$remark);
    if(function_exists('v2raystore_adminHelpContactText')){
        $txt = (string)v2raystore_adminHelpContactText($remark);
    }else{
        $txt = "سلام، کانفیگ من وصل نمی‌شود. لطفاً بررسی کنید.\nنام سرویس: {remark}";
        $txt = str_replace('{remark}', $remark, $txt);
    }
    $txt = str_replace(["\r\n", "\r"], "\n", trim($txt));
    if($remark !== ''){
        // نام سرویس در خط جدا باشد تا کاربر/ادمین راحت کپی کند.
        $txt = preg_replace('/نام\s*سرویس\s*:\s*' . preg_quote($remark, '/') . '/u', "نام سرویس:\n" . $remark, $txt);
        if(strpos($txt, $remark) === false){
            $txt = rtrim($txt) . "\n\nنام سرویس:\n" . $remark;
        }
    }
    return $txt;
}

function v2raystore_openSupportChatByCallback($supportText){
    global $callbackId;
    // باز شدن خودکار پی‌وی از داخل callback در تلگرام قابل اتکا نیست؛
    // پس فقط callback را جواب می‌دهیم و در پیام اصلی دکمه URL می‌گذاریم.
    return bot('answercallbackquery', [
        'callback_query_id' => $callbackId,
        'text' => 'مشکل واضحی پیدا نشد؛ دکمه پیام به پشتیبانی نمایش داده شد.',
        'show_alert' => false
    ]);
}

if(preg_match('/^diagnoseConfig(\d+)$/', $data, $match)){
    if(function_exists('v2raystore_botFeatureEnabled') && !v2raystore_botFeatureEnabled('configDiagnosticsState', 'on')){
        alert('خطایابی خودکار توسط مدیریت غیرفعال شده است.');
        exit();
    }
    $oid = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? AND `userid`=? AND `status`=1 LIMIT 1");
    $stmt->bind_param('ii', $oid, $from_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$order){ alert($mainValues['no_order_found'] ?? 'سفارش پیدا نشد.'); exit(); }
    $summary = function_exists('v2raystore_getOrderRemainingSummary') ? v2raystore_getOrderRemainingSummary($order) : null;
    $problems = [];
    $okLines = [];
    if(is_array($summary) && array_key_exists('enabled', $summary) && $summary['enabled'] === false){
        $problems[] = '🔘 کانفیگ روی پنل غیرفعال است.';
    }elseif(is_array($summary) && array_key_exists('enabled', $summary)){
        $okLines[] = '🔘 کانفیگ روی پنل فعال است.';
    }
    $expire = intval($summary['expire_date'] ?? $order['expire_date'] ?? 0);
    if($expire > 0 && $expire <= time()) $problems[] = '⏰ زمان سرویس تمام شده است.';
    else $okLines[] = '⏰ زمان سرویس فعال است: ' . htmlspecialchars($summary['remaining_days_text'] ?? v2raystore_formatRemainingDaysText($expire), ENT_QUOTES, 'UTF-8');
    if(is_array($summary) && isset($summary['remaining_gb']) && $summary['remaining_gb'] !== null){
        if(floatval($summary['remaining_gb']) <= 0) $problems[] = '🔋 حجم سرویس تمام شده است.';
        else $okLines[] = '🔋 حجم باقی‌مانده: ' . htmlspecialchars($summary['remaining_gb_text'] . ' گیگ', ENT_QUOTES, 'UTF-8');
    }else $okLines[] = '🔋 حجم سرویس نامحدود یا قابل تشخیص نیست.';
    $domainChanged = false;
    if(function_exists('farid_generateUpdatedVrayLinks') && function_exists('v2raystore_normalizeConfigLinksArray')){
        $oldLinks = v2raystore_normalizeConfigLinksArray(json_decode($order['link'] ?? '', true) ?: ($order['link'] ?? ''));
        $newLinks = farid_generateUpdatedVrayLinks($order);
        $hostOf = function($link){
            $link = trim((string)$link);
            if($link === '') return '';
            if(preg_match('/^(vmess|vless|trojan):\/\//i', $link)){
                if(stripos($link, 'vmess://') === 0){
                    $dec = base64_decode(substr($link, 8), true);
                    $j = $dec ? json_decode($dec, true) : null;
                    return strtolower((string)($j['add'] ?? $j['host'] ?? ''));
                }
                $parts = @parse_url($link);
                return strtolower((string)($parts['host'] ?? ''));
            }
            $parts = @parse_url($link);
            return strtolower((string)($parts['host'] ?? ''));
        };
        $oldHosts = array_filter(array_map($hostOf, $oldLinks));
        $newHosts = array_filter(array_map($hostOf, is_array($newLinks) ? $newLinks : []));
        if(count($oldHosts) && count($newHosts) && array_values(array_unique($oldHosts)) != array_values(array_unique($newHosts))) $domainChanged = true;
    }
    $keys = [];
    if($domainChanged){
        $problems[] = '🌐 دامنه یا آدرس اتصال تغییر کرده است.';
    }
    if(count($problems) == 0){
        $adminTextRaw = v2raystore_diagSupportText($order['remark'] ?? '');
        v2raystore_openSupportChatByCallback($adminTextRaw);
        $supportUrl = v2raystore_supportPrefillUrl($adminTextRaw);
        $msg = "🛠 <b>بررسی خودکار کانفیگ</b>

✅ مشکل واضحی از سمت حساب شما پیدا نشد.

برای ارسال پیام آماده به پشتیبانی، دکمه زیر را بزنید.";
        editText($message_id, $msg, json_encode(['inline_keyboard'=>[
            [['text'=>'💬 پیام به پشتیبانی', 'url'=>$supportUrl]],
            [['text'=>$buttonValues['back_button'] ?? '⬅️ بازگشت', 'callback_data'=>'orderDetails' . $oid]]
        ]], JSON_UNESCAPED_UNICODE), 'HTML');
        exit();
    }else{
        if($domainChanged && !(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order))){
            $keys[] = [['text'=>'🔄 بروزرسانی کانفیگ', 'callback_data'=>'updateConfigConnectionLink' . $oid]];
        }
        if($expire <= time() || (is_array($summary) && isset($summary['remaining_gb']) && $summary['remaining_gb'] !== null && floatval($summary['remaining_gb']) <= 0)){
            $keys[] = [['text'=>'🔥 تمدید سرویس', 'callback_data'=>'renewAccount' . $oid]];
        }
    }
    $msg = "🛠 <b>بررسی خودکار کانفیگ</b>\n\n" . implode("\n", $problems);
    if(count($okLines)){
        $msg .= "\n\nجزئیات:\n" . implode("\n", $okLines);
    }
    $keys[] = [['text'=>'ℹ️ اطلاعات سرویس', 'callback_data'=>'orderDetails' . $oid]];
    $keys[] = [['text'=>$buttonValues['back_button'], 'callback_data'=>'mySubscriptions']];
    editText($message_id, $msg, json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}
if(preg_match('/^diagReadyText_(\d+)$/', $data, $match)){
    $oid = intval($match[1]);
    $stmt = $connection->prepare("SELECT `remark` FROM `orders_list` WHERE `id`=? AND `userid`=? LIMIT 1");
    $stmt->bind_param('ii', $oid, $from_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $txt = v2raystore_diagSupportText($row['remark'] ?? '');
    sendMessage("📋 متن آماده برای ارسال به پشتیبانی:

<code>" . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . "</code>

با دکمه زیر پی‌وی پشتیبانی باز می‌شود و اگر برای ادمین یوزرنیم ثبت شده باشد، متن هم داخل پیام آماده می‌شود.", json_encode(['inline_keyboard'=>[
        [['text'=>'💬 رفتن به پیوی پشتیبانی', 'url'=>v2raystore_supportPrefillUrl($txt)]],
        [['text'=>$buttonValues['back_button'], 'callback_data'=>'diagnoseConfig' . $oid]]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}
if($data == 'editDiagAdminText' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("متن آماده‌ای که برای پیام به پشتیبانی نمایش داده می‌شود را ارسال کن.\nمی‌توانی از <code>{remark}</code> برای نام سرویس استفاده کنی.", $cancelKey, 'HTML');
    setUser('editDiagAdminText');
    exit();
}
if(($userInfo['step'] ?? '') == 'editDiagAdminText' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_setSettingValue('CONFIG_DIAG_ADMIN_TEXT', trim((string)$text));
    setUser();
    sendMessage('✅ متن پشتیبانی ذخیره شد.', $removeKeyboard);
    exit();
}

if(preg_match('/^orderDetails(\d+)(_|)(?<offset>\d+|)/', $data, $match)){
    $keys = getOrderDetailKeys($from_id, $match[1], !empty($match['offset'])?$match['offset']:0);
    if($keys != null){
        $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $match[1]);
        $keys['keyboard'] = farid_attachUpdateAllMyConfigsButton($keys['keyboard']);
    }
    if($keys == null){
        alert($mainValues['no_order_found']);exit;
    }else editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if(preg_match('/^editConfigNote(\d+)$/', $data, $match)){
    $oid = intval($match[1]);
    $stmt = $connection->prepare("SELECT `id`, `remark`, `config_note` FROM `orders_list` WHERE `id`=? AND `userid`=? AND `status`=1 LIMIT 1");
    $stmt->bind_param("ii", $oid, $from_id);
    $stmt->execute();
    $orderNoteInfo = $stmt->get_result();
    $stmt->close();

    if(!$orderNoteInfo || $orderNoteInfo->num_rows == 0){
        alert($mainValues['no_order_found'] ?? 'سفارشی یافت نشد');
        exit;
    }

    $orderNoteInfo = $orderNoteInfo->fetch_assoc();
    $currentNote = trim((string)($orderNoteInfo['config_note'] ?? ''));
    setUser('editConfigNote' . $oid);
    delMessage();
    $notePreview = $currentNote !== '' ? "

📝 یادداشت فعلی:
<blockquote>" . htmlspecialchars($currentNote, ENT_QUOTES, 'UTF-8') . "</blockquote>" : "";
    sendMessage("📝 یادداشت کانفیگ <b>" . htmlspecialchars($orderNoteInfo['remark'], ENT_QUOTES, 'UTF-8') . "</b> را ارسال کنید.

برای حذف یادداشت، عبارت <code>/empty</code> را بفرستید.
حداکثر ۳۰۰ کاراکتر." . $notePreview, $cancelKey, 'HTML');
    exit;
}
if(preg_match('/^editConfigNote(\d+)$/', $userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $oid = intval($match[1]);
    $stmt = $connection->prepare("SELECT `id`, `remark` FROM `orders_list` WHERE `id`=? AND `userid`=? AND `status`=1 LIMIT 1");
    $stmt->bind_param("ii", $oid, $from_id);
    $stmt->execute();
    $orderNoteInfo = $stmt->get_result();
    $stmt->close();

    if(!$orderNoteInfo || $orderNoteInfo->num_rows == 0){
        setUser();
        sendMessage($mainValues['no_order_found'] ?? 'سفارشی یافت نشد', $removeKeyboard);
        exit;
    }

    $note = trim((string)$text);
    $noteLower = function_exists('mb_strtolower') ? mb_strtolower($note, 'UTF-8') : strtolower($note);
    if(in_array($noteLower, ['/empty', 'empty', 'حذف', 'پاک', 'پاک کردن'], true)){
        $note = '';
    }elseif(function_exists('v2raystore_safeConfigNoteText')){
        $note = v2raystore_safeConfigNoteText($note);
    }else{
        $note = trim($note);
        if(strlen($note) > 1200) $note = substr($note, 0, 1200);
    }

    $stmt = $connection->prepare("UPDATE `orders_list` SET `config_note`=? WHERE `id`=? AND `userid`=? LIMIT 1");
    $stmt->bind_param("sii", $note, $oid, $from_id);
    $stmt->execute();
    $stmt->close();
    setUser();

    $keys = getOrderDetailKeys($from_id, $oid, 0);
    $noteResultMessage = $note === ''
        ? "✅ یادداشت کانفیگ حذف شد."
        : "📝 یادداشت کانفیگ: " . htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

    if($keys != null){
        $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
        $keys['keyboard'] = farid_attachUpdateAllMyConfigsButton($keys['keyboard']);
        sendMessage($note === '' ? ($noteResultMessage . "

" . $keys['msg']) : $keys['msg'], $keys['keyboard'], 'HTML');
    }else{
        sendMessage($noteResultMessage, $removeKeyboard, 'HTML');
    }
    exit;
}
if($data=="cantEditGrpc"){
    alert("نوعیت این کانفیگ رو تغییر داده نمیتونید!");
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً پورت مورد نظر خود را وارد کنید\nبرای حذف پورت دلخواه عدد 0 را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage("ویرایش تنظیمات پلن", getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً sni مورد نظر خود را وارد کنید\nبرای حذف متن /empty را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomSni(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else {
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();  
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
     
    sendMessage("ویرایش تنظیمات پلن", getPlanDetailsKeys($match[1]));
    setUser();
}
if(preg_match('/^changeCustomDomain(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🌐 دامنه اختصاصی این پلن را وارد کنید.

مثال:
example.com
cdn.example.com

اگر چند دامنه داری هر کدام را در یک خط بفرست.
برای برگشت به دامنه/آی‌پی کلی، /empty را بفرست.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomDomain(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $planId = intval($match[1]);
    $normalizedDomain = v2raystore_normalizePlanDomainInput($text);
    if($text == "/empty" || $normalizedDomain === ""){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_domain`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $planId);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_domain`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $normalizedDomain, $planId);
    }
    $stmt->execute();
    $stmt->close();

    $updatedLinks = farid_refreshPlanOrderLinks($planId);
    sendMessage($mainValues['saved_successfuly'] . "
✅ لینک‌های ذخیره‌شده کاربران این پلن هم به‌روزرسانی شد: " . $updatedLinks, $removeKeyboard);
    sendMessage("ویرایش تنظیمات پلن", getPlanDetailsKeys($planId));
    setUser();
}
if(preg_match('/^changeCustomPath(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_path` = IF(`custom_path` = 1, 0, 1) WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editKeys(getPlanDetailsKeys($match[1]));
}
if(preg_match('/changeNetworkType(\d+)_(\d+)/', $data, $match)){
    $fid = $match[1];
    $oid = $match[2];
    
	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$oid";
		
	}else $name = "$oid";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $protocol = $order['protocol'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network; 
            $security = json_decode($row->streamSettings)->security;
            $netType = ($netType == 'tcp') ? 'ws' : 'tcp';
        break;
        }
    }

    if($protocol == 'trojan') $netType = 'tcp';

    $update_response = editInbound($server_id, $uuid, $uuid, $protocol, $netType);
    $order['protocol'] = $protocol;
    $order['server_id'] = $server_id;
    $order['uuid'] = $uuid;
    $order['remark'] = $remark;
    $vraylink = farid_generateUpdatedVrayLinks($order);

    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=? WHERE `id`=?");
    $stmt->bind_param("ssi", $protocol, $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $oid);
    if($keys != null) $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="changeProtocolIsDisable"){
    alert("تغییر پروتکل غیر فعال است");
}
if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = intval($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$order){
        alert($mainValues['config_not_found'] ?? "کانفیگ یافت نشد");
        exit();
    }

    $ownerId = intval($order['userid'] ?? 0);

    // امنیت: فقط مالک کانفیگ یا ادمین اجازه به‌روزرسانی دارد
    if($ownerId != $from_id && !($from_id == $admin || $userInfo['isAdmin'] == true)){
        alert("⛔️ شما به این کانفیگ دسترسی ندارید");
        exit();
    }

    if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
        alert("ℹ️ این سرویس با لینک ساب ارسال می‌شود؛ بروزرسانی لینک عادی برای آن غیرفعال است. ساب را داخل برنامه Update کنید.", true);
        exit();
    }

    $remark = $order['remark'] ?? "-";

    // ساخت لینک‌های جدید
    $vraylink = farid_generateUpdatedVrayLinks($order);
    if($vraylink == null){
        alert("⛔️ خطا در دریافت لینک جدید (سرور/پنل در دسترس نیست)");
        exit();
    }

    $vray_link = json_encode($vraylink, JSON_UNESCAPED_UNICODE);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=? WHERE `id`=?");
    $stmt->bind_param("si", $vray_link, $oid);
    $stmt->execute();
    $stmt->close();

    // ✅ ارسال در پیام جدید برای کاربر (طبق درخواست)
    if($ownerId > 0){
        farid_sendUpdatedConfigToUser($ownerId, $remark, $vraylink);
    }

    // به‌روزرسانی پیام جزئیات (برای همان کسی که دکمه را زده)
    if($ownerId == $from_id){
        $keys = getOrderDetailKeys($from_id, $oid);
        if($keys != null){
            $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
            $keys['keyboard'] = farid_attachUpdateAllMyConfigsButton($keys['keyboard']);
        }
    }else{
        $keys = getUserOrderDetailKeys($oid);
        if($keys != null){
            $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
            if($ownerId > 0) $keys['keyboard'] = farid_attachUpdateAllUserConfigsButton($keys['keyboard'], $ownerId);
        }
    }

    if($keys != null){
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }
}

// ♻️ به‌روزرسانی و ارسال «همه کانفیگ‌های کاربر» درجا (برای کاربر)
if(preg_match('/^updateAllMyConfigs_(\d+)/', $data, $match)){
    alert($mainValues['please_wait_message']);

    $offset = intval($match[1]);
    if($offset < 0) $offset = 0;

    $uid = $from_id;
    $batch = 5; // تعداد آیتم در هر مرحله

    // تعداد کل کانفیگ‌های فعال
    $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `orders_list` WHERE `status` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $total = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if($total <= 0){
        alert("⛔️ کانفیگ فعالی برای به‌روزرسانی پیدا نشد.");
        exit();
    }

    // خواندن batch
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1 AND `userid` = ? ORDER BY `id` ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("iii", $uid, $batch, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $processed = 0;
    $updated = 0;
    $failed = 0;

    while($order = $res->fetch_assoc()){
        $processed++;
        if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
            continue;
        }
        $links = farid_generateUpdatedVrayLinks($order);
        if($links == null){
            $failed++;
            continue;
        }

        $oid = intval($order['id']);
        $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

        $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
        $stmt->bind_param("si", $linkJson, $oid);
        $stmt->execute();
        $stmt->close();

        $remark = $order['remark'] ?? "-";
        // ارسال بدون پیام اضافه (پیام اضافه فقط در پایان ارسال می‌شود)
        farid_sendUpdatedConfigToUser($uid, $remark, $links, "");
        $updated++;
    }

    $newOffset = $offset + $processed;
    $left = max(0, $total - $newOffset);

    if($newOffset >= $total){
        // پیام پیش‌فرض/تنظیمی بعد از به‌روزرسانی (یک‌بار در پایان)
        $after = farid_getUpdateAfterMessage();
        if(strlen(trim($after)) > 0){
            sendMessage($after, null, "HTML", $uid);
        }

        $msg = "✅ به‌روزرسانی همه کانفیگ‌های شما انجام شد.\\n\\n".
               "🔰 کل: $total\\n".
               "✅ موفق: $updated\\n".
               "⛔️ ناموفق: $failed\\n".
               "🕒 " . jdate("Y-m-d H:i", time());

        editText($message_id, $msg, json_encode(['inline_keyboard'=>[
            [['text'=>"📦 کانفیگ‌های من",'callback_data'=>"mySubscriptions"]],
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        exit();
    }

    $msg = "⏳ به‌روزرسانی کانفیگ‌ها در حال انجام است...\\n\\n".
           "🔰 کل: $total\\n".
           "☑️ انجام‌شده: $newOffset\\n".
           "📣 باقی‌مانده: $left\\n\\n".
           "✅ موفق: $updated\\n".
           "⛔️ ناموفق: $failed\\n\\n".
           "برای ادامه روی «ادامه» بزن.";

    editText($message_id, $msg, json_encode(['inline_keyboard'=>[
        [['text'=>"▶️ ادامه",'callback_data'=>"updateAllMyConfigs_" . $newOffset]],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

// ♻️ به‌روزرسانی و ارسال «همه کانفیگ‌های یک کاربر» درجا (برای ادمین)
if(preg_match('/^updateAllUserConfigs(\d+)_(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert($mainValues['please_wait_message']);

    $targetUid = intval($match[1]);
    $offset = intval($match[2]);
    if($offset < 0) $offset = 0;

    if($targetUid <= 0){
        alert("⛔️ User ID نامعتبر");
        exit();
    }

    $batch = 5;

    // تعداد کل کانفیگ‌های فعال کاربر
    $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `orders_list` WHERE `status` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $targetUid);
    $stmt->execute();
    $total = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if($total <= 0){
        alert("⛔️ کانفیگ فعالی برای این کاربر پیدا نشد.");
        exit();
    }

    // خواندن batch
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1 AND `userid` = ? ORDER BY `id` ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("iii", $targetUid, $batch, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $processed = 0;
    $updated = 0;
    $failed = 0;

    while($order = $res->fetch_assoc()){
        $processed++;
        if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
            continue;
        }
        $links = farid_generateUpdatedVrayLinks($order);
        if($links == null){
            $failed++;
            continue;
        }

        $oid = intval($order['id']);
        $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

        $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
        $stmt->bind_param("si", $linkJson, $oid);
        $stmt->execute();
        $stmt->close();

        $remark = $order['remark'] ?? "-";
        // ارسال بدون پیام اضافه (پیام اضافه فقط در پایان ارسال می‌شود)
        farid_sendUpdatedConfigToUser($targetUid, $remark, $links, "");
        $updated++;
    }

    $newOffset = $offset + $processed;
    $left = max(0, $total - $newOffset);

    if($newOffset >= $total){
        // پیام پیش‌فرض/تنظیمی بعد از به‌روزرسانی (یک‌بار در پایان)
        $after = farid_getUpdateAfterMessage();
        if(strlen(trim($after)) > 0){
            sendMessage($after, null, "HTML", $targetUid);
        }

        $msg = "✅ به‌روزرسانی همه کانفیگ‌های کاربر انجام شد.\\n\\n".
               "👤 UserID: $targetUid\\n".
               "🔰 کل: $total\\n".
               "✅ موفق: $updated\\n".
               "⛔️ ناموفق: $failed\\n".
               "🕒 " . jdate("Y-m-d H:i", time());

        editText($message_id, $msg, json_encode(['inline_keyboard'=>[
            [['text'=>"🔎 جستجو کانفیگ کاربر",'callback_data'=>"searchUsersConfig"]],
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE));
        exit();
    }

    $msg = "⏳ به‌روزرسانی کانفیگ‌های کاربر در حال انجام است...\\n\\n".
           "👤 UserID: $targetUid\\n".
           "🔰 کل: $total\\n".
           "☑️ انجام‌شده: $newOffset\\n".
           "📣 باقی‌مانده: $left\\n\\n".
           "✅ موفق: $updated\\n".
           "⛔️ ناموفق: $failed\\n\\n".
           "برای ادامه روی «ادامه» بزن.";

    editText($message_id, $msg, json_encode(['inline_keyboard'=>[
        [['text'=>"▶️ ادامه",'callback_data'=>"updateAllUserConfigs" . $targetUid . "_" . $newOffset]],
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE));
    exit();
}

if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = intval($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? LIMIT 1");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$order){
        alert($mainValues['config_not_found'] ?? "کانفیگ یافت نشد");
        exit();
    }

    $ownerId = intval($order['userid'] ?? 0);
    if($ownerId != $from_id && !($from_id == $admin || $userInfo['isAdmin'] == true)){
        alert("⛔️ شما به این کانفیگ دسترسی ندارید");
        exit();
    }

    $result = farid_renewAccountConnectionLinks($order);
    if(empty($result['ok'])){
        $errorMsg = $result['message'] ?? "خطا در قطع دسترسی و ساخت لینک جدید";
        if(is_array($errorMsg) || is_object($errorMsg)) $errorMsg = json_encode($errorMsg, JSON_UNESCAPED_UNICODE);
        alert("⛔️ " . strval($errorMsg), true);
        exit();
    }

    $remark = $result['remark'] ?? ($order['remark'] ?? "-");
    $links = $result['links'] ?? [];

    // ارسال لینک جدید در پیام تازه؛ مثل بخش آپدیت، پیام جزئیات فقط به‌روزرسانی می‌شود.
    if($ownerId > 0){
        farid_sendUpdatedConfigToUser($ownerId, $remark, $links, "", "🔐 لینک جدید سرویس شما ساخته شد");
    }

    if($ownerId == $from_id){
        $keys = getOrderDetailKeys($from_id, $oid);
        if($keys != null){
            $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
            if(function_exists('farid_attachUpdateAllMyConfigsButton')) $keys['keyboard'] = farid_attachUpdateAllMyConfigsButton($keys['keyboard']);
        }
    }else{
        $keys = getUserOrderDetailKeys($oid);
        if($keys != null){
            $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
            if($ownerId > 0 && function_exists('farid_attachUpdateAllUserConfigsButton')) $keys['keyboard'] = farid_attachUpdateAllUserConfigsButton($keys['keyboard'], $ownerId);
        }
    }

    if($keys != null){
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }
    alert("✅ دسترسی قبلی قطع شد و لینک جدید در پیام جداگانه ارسال شد.");
    exit();
}
if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    
    if($inboundId == 0){
        if($serverType == "marzban") $update_response = changeMarzbanState($server_id, $remark);
        else $update_response = changeInboundState($server_id, $uuid);
    }else{
        $update_response = changeClientState($server_id, $inboundId, $uuid);
    }
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = getUserOrderDetailKeys($oid);
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage("عملیه مورد نظر با مشکل روبرو شد\n" . $update_response->msg);
}

if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data,$match)){
    $fid = $match[1];
    $oid = $match[2];
    $protocol = $match[3];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt= $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$id";
		
	}else $name = "$id";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    $rahgozar = $order['rahgozar'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            $security = json_decode($row->streamSettings)->security;
            break;
        }
    }
    if($protocol == 'trojan') $netType = 'tcp';
    $uniqid = generateRandomString(42,$protocol); 
    $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB"; 
    $update_response = editInbound($server_id, $uniqid, $uuid, $protocol, $netType, $security, $rahgozar);
    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, 0, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=?, `uuid` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $protocol, $vray_link, $uniqid, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    if($keys != null) $keys['keyboard'] = farid_attachUpdateConfigButton($keys['keyboard'], $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/^renewGoSwitchLocation(\d+)$/', $data, $match)){
    // از پیام تمدیدِ سرور پر، کاربر را مستقیم وارد مسیر تغییر لوکیشن همان سرویس می‌کنیم.
    $data = 'switchLocation' . intval($match[1]);
}

if(preg_match('/^renewAccount(\d+)$/',$data,$match) && $text != $buttonValues['cancel']){
    if(($botState['renewAccountState'] ?? 'off') != "on"){
        alert('تمدید سرویس در حال حاضر غیرفعال است.', true);
        exit();
    }
    if(($botState['sellState'] ?? 'on') != "on" && $from_id != $admin && ($userInfo['isAdmin'] ?? false) != true){
        alert($mainValues['selling_is_off'] ?? 'فروش غیرفعال است.', true);
        exit();
    }
    $renewOrderId = intval($match[1]);
    $stmt = $connection->prepare("SELECT `id`, `userid`, `status`, `remark`, `server_id` FROM `orders_list` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $renewOrderId);
    $stmt->execute();
    $renewOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$renewOrder || intval($renewOrder['status'] ?? 0) != 1 || (intval($renewOrder['userid']) != intval($from_id) && $from_id != $admin && ($userInfo['isAdmin'] ?? false) != true)){
        alert($mainValues['config_not_found'] ?? 'کانفیگ پیدا نشد.', true);
        exit();
    }

    $currentServerId = intval($renewOrder['server_id'] ?? 0);
    $stmt = $connection->prepare("SELECT `id`, `title`, `flag`, `active`, `state`, `ucount` FROM `server_info` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $currentServerId);
    $stmt->execute();
    $currentServer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $showSwitchLocationNotice = function($serverTitle = '') use ($message_id, $buttonValues, $renewOrder, $renewOrderId){
        $safeRemark = htmlspecialchars((string)($renewOrder['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeServer = htmlspecialchars((string)$serverTitle, ENT_QUOTES, 'UTF-8');
        $serverLine = $safeServer !== '' ? "📍 سرور فعلی: <b>{$safeServer}</b>\n" : '';
        $msg = "⚠️ <b>تمدید از روی سرور فعلی ممکن نیست</b>\n\n" .
               "سرویس: <code>{$safeRemark}</code>\n" .
               $serverLine .
               "ظرفیت سرور فعلی پر است یا پلن تمدید فعالی برای همین سرور وجود ندارد.\n\n" .
               "برای تمدید، اول لوکیشن سرویس را به یک سرور دارای ظرفیت تغییر بده؛ بعد دوباره تمدید را بزن.\n\n" .
               "می‌خواهی الان وارد بخش تغییر لوکیشن شوی؟";
        $keyboard = json_encode(['inline_keyboard'=>[
            [['text'=>'✅ بله، تغییر لوکیشن', 'callback_data'=>'renewGoSwitchLocation' . $renewOrderId]],
            [['text'=>'❌ نه، بازگشت', 'callback_data'=>'orderDetails' . $renewOrderId]],
        ]], JSON_UNESCAPED_UNICODE);
        editText($message_id, $msg, $keyboard, 'HTML');
    };

    if(!$currentServer || intval($currentServer['active'] ?? 0) != 1 || intval($currentServer['state'] ?? 0) != 1 || intval($currentServer['ucount'] ?? 0) <= 0){
        $showSwitchLocationNotice($currentServer['title'] ?? '');
        exit();
    }

    $stmt = $connection->prepare("SELECT `id`, `title` FROM `server_categories` WHERE `parent` = 0 ORDER BY `id` ASC");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $cats->fetch_assoc()){
        $catId = intval($cat['id']);
        $stmt = $connection->prepare("SELECT COUNT(*) AS cnt FROM `server_plans` WHERE `server_id` = ? AND `catid` = ? AND `active` = 1 AND `price` != 0");
        $stmt->bind_param("ii", $currentServerId, $catId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(intval($row['cnt'] ?? 0) > 0){
            $keyboard[] = ['text' => $cat['title'], 'callback_data' => "selectCategory{$catId}_{$currentServerId}_renew{$renewOrderId}"];
        }
    }

    if(empty($keyboard)){
        $showSwitchLocationNotice($currentServer['title'] ?? '');
        exit();
    }

    $keyboard = array_chunk($keyboard, 1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'], 'callback_data'=>"mySubscriptions"]];
    $serverTitle = trim((string)(($currentServer['flag'] ?? '') . ' ' . ($currentServer['title'] ?? '')));
    editText($message_id, "🔄 <b>تمدید سرویس</b>\n\nسرویس انتخابی: <code>" . htmlspecialchars($renewOrder['remark'], ENT_QUOTES, 'UTF-8') . "</code>\n📍 سرور فعلی: <b>" . htmlspecialchars($serverTitle, ENT_QUOTES, 'UTF-8') . "</b>\n\nبرای تمدید فقط پلن‌های همین سرور نمایش داده می‌شود. دسته موردنظر را انتخاب کن:", json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE), "HTML");
    exit();
}

if(preg_match('/^discountRenew(\d+)_(\d+)/',$userInfo['step'], $match) || preg_match('/renewAccount(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountRenew/', $userInfo['step'])){
        $rowId = $match[2];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();            
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"v2raystore"]
                        ],
                    ]]);
                sendMessage(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null,$admin);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }else delMessage();

    $oid = $match[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    $order = $order->fetch_assoc();
    $serverId = $order['server_id'];
    $fid = $order['fileid'];
    $agentBought = $order['agent_bought'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $fid, $serverId, $respd['volume'] ?? 0, 1) : $price;
    }
    if(!preg_match('/^discountRenew/', $userInfo['step'])){
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                    VALUES (?, ?, 'RENEW_ACCOUNT', ?, '0', '0', ?, ?, 'pending')");
        $stmt->bind_param("siiii", $hash_id, $from_id, $oid, $price, $time);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }else $price = $afterDiscount;

    if($price == 0) $price = "رایگان";
    else $price .= " تومان";
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => "💳 کارت به کارت مبلغ $price",  'callback_data' => "payRenewWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "پرداخت با موجودی مبلغ $price",  'callback_data' => "payRenewWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountRenew_" . $match[1] . "_" . $rowId]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];



    sendMessage("لطفاً با یکی از روش های زیر اکانت خود را تمدید کنید :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/payRenewWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $oid = $stmt->get_result()->fetch_assoc()['plan_id'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    
    setUser($data);
    delMessage();

    v2raystore_sendCartToCartInstructions($match[1], 'renew_ccount_cart_to_cart', 'HTML');
    exit;
}
if(preg_match('/payRenewWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $stmt->close();
        
        v2raystore_markPayReceiptSent($match[1], $fileid);
    

        
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fid = function_exists('v2raystore_getRenewPlanIdFromPay') ? v2raystore_getRenewPlanIdFromPay($payInfo, $order) : $order['fileid'];
        $remark = $order['remark'];
        $uid = $order['userid'];
        $userName = $userInfo['username'];
        $uname = $userInfo['name'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payInfo['price'];
        $volume = $respd['volume'];
        $days = $respd['days'];
        
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        // notify admin
        
        $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کارت به کارت', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveRenewAcc$hash_id"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decRenewAcc$hash_id"]
                ]
            ]
        ]);
    
        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        }
        setUser();
    }else{
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $result = function_exists('v2raystore_approveRenewAccountPayByHash') ? v2raystore_approveRenewAccountPayByHash($match[1], false) : ['ok'=>false, 'message'=>'تابع تمدید در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    $approvedText = function_exists('v2raystore_approvalStatusTextFromResult') ? v2raystore_approvalStatusTextFromResult($result, false) : ($buttonValues['approved'] ?? '✅ تأیید شد');
    $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
    if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard($approvedText, intval($result['user_id'] ?? 0), 'success', $copyText));
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>$approvedText,'callback_data'=>'v2raystore']]]], JSON_UNESCAPED_UNICODE));
    sendMessage("✅سرویس " . ($result['renew_remark'] ?? '') . " با موفقیت تمدید شد", null, null, intval($result['user_id'] ?? 0));
    exit;
}
if(preg_match('/decRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    $declineResult = function_exists('v2raystore_declinePayByHash') ? v2raystore_declinePayByHash($hashId, 'رد شده توسط ادمین') : ['ok'=>false, 'message'=>'تابع رد سفارش در دسترس نیست.'];
    if(!$declineResult['ok']){
        alert($declineResult['message'], true);
        exit();
    }
    $uid = intval($declineResult['user_id'] ?? 0);
    if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard('❌ رد شد', $uid, 'danger'));
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌','callback_data'=>'dontsendanymore']]]], JSON_UNESCAPED_UNICODE));
    if($uid > 0) sendMessage("😖|تمدید سرویس شما لغو شد", null, null, $uid);
    exit;
}
if(preg_match('/payRenewWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$payInfo){
        alert('پرداخت پیدا نشد.', true);
        exit();
    }
    if(($payInfo['state'] ?? '') == "approved"){
        alert('این تمدید قبلاً انجام شده است.', true);
        exit();
    }
    $price = intval($payInfo['price'] ?? 0);
    $userwallet = intval($userInfo['wallet'] ?? 0);
    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }

    $result = function_exists('v2raystore_approveRenewAccountPayByHash') ? v2raystore_approveRenewAccountPayByHash($match[1], false) : ['ok'=>false, 'message'=>'تابع تمدید در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }

    if($price > 0){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
    }
    editText($message_id, "✅سرویس " . ($result['renew_remark'] ?? '') . " با موفقیت تمدید شد", getMainKeys());
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"به به تمدید 😍",'callback_data'=>"v2raystore"]
        ],
    ]], JSON_UNESCAPED_UNICODE);
    $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کیف پول', $from_id, $username, $first_name, $price, ($result['renew_remark'] ?? ''), ($result['renew_volume'] ?? ''), ($result['renew_days'] ?? '')], $mainValues['renew_account_request_message']);
    sendMessage($msg, $keys,"html", $admin);
    exit;
}

if(preg_match('/freeRenew(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$payInfo || intval($payInfo['price'] ?? 0) != 0 || intval($payInfo['user_id'] ?? 0) != intval($from_id)){
        alert('تمدید رایگان نامعتبر است.', true);
        exit();
    }
    $result = function_exists('v2raystore_approveRenewAccountPayByHash') ? v2raystore_approveRenewAccountPayByHash($match[1], false) : ['ok'=>false, 'message'=>'تابع تمدید در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    editText($message_id, "✅سرویس " . ($result['renew_remark'] ?? '') . " با موفقیت تمدید شد", getMainKeys());
    exit;
}

if(preg_match('/^switchLocation(\d+)$/', $data, $match)){
    $oid = intval($match[1]);
    $order = v2raystore_switchGetOrder($oid);
    if(!$order){
        alert($mainValues['config_not_found'] ?? 'کانفیگ یافت نشد', true);
        exit();
    }
    $isAdminSwitch = ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
    $ownerId = intval($order['userid'] ?? 0);
    $canAccessSwitchOrder = function_exists('v2raystore_canActorSwitchOrder') ? v2raystore_canActorSwitchOrder($order, $from_id, $isAdminSwitch) : ($isAdminSwitch || $ownerId == $from_id);
    if(!$canAccessSwitchOrder){
        alert('⛔️ شما به این کانفیگ دسترسی ندارید', true);
        exit();
    }
    if(!$isAdminSwitch && function_exists('v2raystore_canUseSwitchLocation') && !v2raystore_canUseSwitchLocation($order, $from_id, false)){
        alert('⛔️ تغییر سرور برای این سرویس در حال حاضر غیرفعال است.', true);
        exit();
    }
    if(!$isAdminSwitch && !function_exists('v2raystore_canUseSwitchLocation') && (($botState['switchLocationState'] ?? 'off') != 'on')){
        alert('⛔️ تغییر سرور در حال حاضر غیرفعال است.', true);
        exit();
    }
    if(intval($order['amount'] ?? 0) <= 0 && intval($order['agent_bought'] ?? 0) == 0){
        alert('اکانت تست یا سرویس رایگان قابل تغییر سرور نیست.', true);
        exit();
    }
    if(intval($order['expire_date'] ?? 0) > 0 && intval($order['expire_date']) < time()){
        alert('سرویس شما غیرفعال است. لطفاً ابتدا آن را تمدید کنید.', true);
        exit();
    }
    $live = farid_switchGetOrderLiveState($order);
    if(empty($live['ok'])){
        alert('⛔️ ' . ($live['message'] ?? 'امکان بررسی حجم سرویس وجود ندارد.'), true);
        exit();
    }
    $remainingGb = floatval($live['remaining_gb'] ?? 0);
    if($remainingGb <= 0){
        alert('حجم سرویس شما تمام شده است. لطفاً ابتدا آن را تمدید یا شارژ کنید.', true);
        exit();
    }
    $settings = v2raystore_getServerSwitchSettings();
    if(!$isAdminSwitch && intval($settings['daily_limit']) > 0 && v2raystore_switchUsedToday($oid, $ownerId) >= intval($settings['daily_limit'])){
        alert('⛔️ برای هر کانفیگ فقط ' . intval($settings['daily_limit']) . ' بار در روز می‌توانید تغییر سرور انجام دهید.', true);
        exit();
    }

    $currentServer = intval($order['server_id'] ?? 0);
    $stmt = $connection->prepare("SELECT `id`, `title`, `ucount` FROM `server_info` WHERE `active` = 1 AND `state` = 1 AND `id` != ? ORDER BY `id` DESC");
    $stmt->bind_param('i', $currentServer);
    $stmt->execute();
    $servers = $stmt->get_result();
    $stmt->close();
    $keyboard = [];
    while($srv = $servers->fetch_assoc()){
        $sid = intval($srv['id']);
        if(!$isAdminSwitch && intval($srv['ucount'] ?? 0) <= 0) continue;
        $cost = v2raystore_calcSwitchDeductionGb($order, $sid, $remainingGb);
        $changeGb = floatval($cost['change_gb'] ?? ($cost['deduct_gb'] ?? 0));
        $changeType = ($cost['change_type'] ?? 'deduct');
        if($changeType === 'deduct' && $remainingGb <= $changeGb){
            $label = $srv['title'] . ' (حجم کافی نیست)';
        }else{
            $sign = ($changeType === 'add') ? '+' : '-';
            $label = $srv['title'] . ' (' . $sign . v2raystore_switchFormatGb($changeGb) . 'GB)';
        }
        $keyboard[] = ['text'=>$label, 'callback_data'=>'switchSrvPreview' . $sid . '_' . $oid];
    }
    if(empty($keyboard)){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر سرور وجود ندارد.', true);
        exit();
    }
    $keyboard = array_chunk($keyboard, 1);
    $keyboard[] = [['text'=>$buttonValues['back_button'] ?? 'بازگشت', 'callback_data'=>($isAdminSwitch ? 'userOrderDetails' . $oid . '_0' : 'orderDetails' . $oid)]];
    $msg = "🌎 <b>تغییر سرور کانفیگ</b>\n\n" .
           "🔮 نام سرویس: <b>" . htmlspecialchars((string)$order['remark'], ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📦 حجم باقی‌مانده فعلی: <b>" . v2raystore_switchFormatGb($remainingGb) . " GB</b>\n\n" .
           "سرور مقصد را انتخاب کنید. عدد داخل پرانتز مقدار حجمی است که بابت تغییر سرور کم یا اضافه می‌شود.";
    editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}

if(preg_match('/^switchSrvPreview(\d+)_(\d+)$/', $data, $match)){
    $sid = intval($match[1]);
    $oid = intval($match[2]);
    $order = v2raystore_switchGetOrder($oid);
    if(!$order){ alert($mainValues['config_not_found'] ?? 'کانفیگ یافت نشد', true); exit(); }
    $isAdminSwitch = ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
    $ownerId = intval($order['userid'] ?? 0);
    $canAccessSwitchOrder = function_exists('v2raystore_canActorSwitchOrder') ? v2raystore_canActorSwitchOrder($order, $from_id, $isAdminSwitch) : ($isAdminSwitch || $ownerId == $from_id);
    if(!$canAccessSwitchOrder){ alert('⛔️ شما به این کانفیگ دسترسی ندارید', true); exit(); }
    if(!$isAdminSwitch && function_exists('v2raystore_canUseSwitchLocation') && !v2raystore_canUseSwitchLocation($order, $from_id, false)){ alert('⛔️ تغییر سرور برای این سرویس در حال حاضر غیرفعال است.', true); exit(); }
    $live = farid_switchGetOrderLiveState($order);
    if(empty($live['ok'])){ alert('⛔️ ' . ($live['message'] ?? 'امکان بررسی حجم سرویس وجود ندارد.'), true); exit(); }
    $remainingGb = floatval($live['remaining_gb'] ?? 0);
    $cost = v2raystore_calcSwitchDeductionGb($order, $sid, $remainingGb);
    $changeGb = floatval($cost['change_gb'] ?? ($cost['deduct_gb'] ?? 0));
    $changeType = ($cost['change_type'] ?? 'deduct');
    if($changeType === 'deduct' && $remainingGb <= $changeGb){
        alert('حجم باقی‌مانده برای این تغییر کافی نیست. حجم باقی‌مانده: ' . v2raystore_switchFormatGb($remainingGb) . 'GB، حجم موردنیاز: ' . v2raystore_switchFormatGb($changeGb) . 'GB', true);
        exit();
    }
    $fromTitle = v2raystore_switchGetServerTitle($order['server_id']);
    $toTitle = v2raystore_switchGetServerTitle($sid);
    $afterGb = ($changeType === 'add') ? ($remainingGb + $changeGb) : max(0, $remainingGb - $changeGb);
    $priceDiff = number_format(intval($cost['price_diff'] ?? 0));
    $changeLine = ($changeType === 'add')
        ? "🔺 حجم افزایشی: <b>" . v2raystore_switchFormatGb($changeGb) . " GB</b>\n"
        : "🔻 حجم کسرشونده: <b>" . v2raystore_switchFormatGb($changeGb) . " GB</b>\n";
    $msg = "⚠️ <b>تأیید تغییر سرور</b>\n\n" .
           "🔮 سرویس: <b>" . htmlspecialchars((string)$order['remark'], ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📍 از: <b>" . htmlspecialchars($fromTitle, ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📍 به: <b>" . htmlspecialchars($toTitle, ENT_QUOTES, 'UTF-8') . "</b>\n\n" .
           "📦 حجم فعلی: <b>" . v2raystore_switchFormatGb($remainingGb) . " GB</b>\n" .
           $changeLine .
           "✅ حجم بعد از تغییر: <b>" . v2raystore_switchFormatGb($afterGb) . " GB</b>\n" .
           "💰 اختلاف قیمت شناسایی‌شده: <b>{$priceDiff} تومان</b>\n\n" .
           "بعد از تأیید، کانفیگ از سرور قبلی حذف می‌شود و لینک/ساب جدید در یک پیام جداگانه برای کاربر ارسال می‌شود.";
    $keyboard = json_encode(['inline_keyboard'=>[
        [['text'=>'✅ تأیید و تغییر سرور', 'callback_data'=>'confirmSwitchServer' . $sid . '_' . $oid, 'style'=>'success']],
        [['text'=>'⬅️ بازگشت به انتخاب سرور', 'callback_data'=>'switchLocation' . $oid]],
    ]], JSON_UNESCAPED_UNICODE);
    editText($message_id, $msg, $keyboard, 'HTML');
    exit();
}

if(preg_match('/^confirmSwitchServer(\d+)_(\d+)$/', $data, $match)){
    alert($mainValues['please_wait_message'] ?? 'لطفاً صبر کنید...');
    $sid = intval($match[1]);
    $oid = intval($match[2]);
    $isAdminSwitch = ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
    $result = farid_switchOrderServer($oid, $sid, $from_id, $isAdminSwitch);
    if(empty($result['ok'])){
        alert('⛔️ ' . ($result['message'] ?? 'تغییر سرور انجام نشد.'), true);
        exit();
    }

    $ownerId = intval($result['owner_id'] ?? 0);
    $newRemark = $result['new_remark'] ?? '-';
    $links = $result['links'] ?? [];
    if($ownerId > 0){
        $resultChangeType = ($result['change_type'] ?? 'deduct');
        $resultChangeGb = floatval($result['change_gb'] ?? ($result['deduct_gb'] ?? 0));
        $resultChangeLine = ($resultChangeType === 'add')
            ? "🔺 حجم اضافه‌شده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n"
            : "🔻 حجم کسرشده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n";
        $extra = "✅ سرور سرویس شما تغییر کرد.\n" .
                 "📍 سرور جدید: <b>" . htmlspecialchars((string)($result['target_title'] ?? ''), ENT_QUOTES, 'UTF-8') . "</b>\n" .
                 $resultChangeLine .
                 "📦 حجم باقی‌مانده جدید: <b>" . v2raystore_switchFormatGb($result['remaining_gb_after'] ?? 0) . " GB</b>";
        $deliveryOptions = is_array($result['delivery_options'] ?? null) ? $result['delivery_options'] : ['config'=>true, 'sub'=>false];
        $subLink = trim((string)($result['sub_link'] ?? ''));
        if(!empty($deliveryOptions['sub']) && empty($deliveryOptions['config']) && $subLink !== '' && function_exists('farid_sendUpdatedSubToUser')){
            farid_sendUpdatedSubToUser($ownerId, $newRemark, $subLink, $extra, '🌎 لینک ساب سرویس شما بعد از تغییر سرور');
        }elseif(!empty($deliveryOptions['sub']) && !empty($deliveryOptions['config']) && $subLink !== '' && function_exists('farid_sendUpdatedSubToUser')){
            farid_sendUpdatedConfigToUser($ownerId, $newRemark, $links, '', '🌎 لینک جدید سرویس شما بعد از تغییر سرور');
            farid_sendUpdatedSubToUser($ownerId, $newRemark, $subLink, $extra, '🌎 لینک ساب سرویس شما بعد از تغییر سرور');
        }else{
            farid_sendUpdatedConfigToUser($ownerId, $newRemark, $links, $extra, '🌎 لینک جدید سرویس شما بعد از تغییر سرور');
        }
    }
    if(function_exists('v2raystore_notifyServerSwitch')){
        v2raystore_notifyServerSwitch($result, $from_id, $isAdminSwitch);
    }

    $backCb = $isAdminSwitch ? ('userOrderDetails' . $oid . '_0') : ('orderDetails' . $oid);
    $resultChangeType = ($result['change_type'] ?? 'deduct');
    $resultChangeGb = floatval($result['change_gb'] ?? ($result['deduct_gb'] ?? 0));
    $resultChangeLine = ($resultChangeType === 'add')
        ? "🔺 حجم اضافه‌شده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n"
        : "🔻 حجم کسرشده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n";
    $msg = "✅ سرور کانفیگ با موفقیت تغییر کرد.\n\n" .
           "🔮 نام جدید: <b>" . htmlspecialchars((string)$newRemark, ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📍 مقصد: <b>" . htmlspecialchars((string)($result['target_title'] ?? ''), ENT_QUOTES, 'UTF-8') . "</b>\n" .
           $resultChangeLine .
           "📦 حجم باقی‌مانده: <b>" . v2raystore_switchFormatGb($result['remaining_gb_after'] ?? 0) . " GB</b>\n\n" .
           "لینک/ساب جدید در پیام جداگانه برای کاربر ارسال شد.";
    editText($message_id, $msg, json_encode(['inline_keyboard'=>[
        [['text'=>'📄 مشاهده جزئیات سرویس', 'callback_data'=>$backCb]],
        [['text'=>$buttonValues['back_to_main'] ?? 'منوی اصلی', 'callback_data'=>'mainMenu']],
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
    exit();
}

if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است.لطفاً ابتدا آن را تمدید کنید",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفاً برای تغییر لوکیشن سرویس فعلی, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if($data=="giftVolumeAndDay" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای هدیه دادن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "giftToServer{$sid}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    editText($message_id, ' 📍 لطفاً برای هدیه دادن, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^giftToServer(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفاً مدت زمان هدیه را به روز وارد کنید\nبرای اضافه نشدن زمان 0 را وارد کنید", $cancelKey);
    setUser('giftServerDay' . $match[1]);
}
if(preg_match('/^giftServerDay(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            sendMessage("لطفاً حجم هدیه را به مگابایت وارد کنید\nبرای اضافه نشدن حجم 0 را وارد کنید");
            setUser('giftServerVolume' . $match[1] . "_" . $text);
        }else sendMessage("عددی بزرگتر و یا مساوی به 0 واردکنید");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^giftServerVolume(\d+)_(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            $stmt = $connection->prepare("INSERT INTO `gift_list` (`server_id`, `volume`, `day`) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $match[1], $text, $match[2]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());

            setUser();
        }else sendMessage("عددی بزرگتر و یا مساوی به 0 واردکنید");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است.لطفاً ابتدا آن را تمدید کنید",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفاً برای تغییر لوکیشن سرویس فعلی, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^switchServer(\d+)_(\d+)$/',$data,$match)){
    $sid = intval($match[1]);
    $oid = intval($match[2]);
    $isAdminSwitch = ($from_id == $admin || (!empty($userInfo['isAdmin'])));
    $result = farid_switchOrderServer($oid, $sid, $from_id, $isAdminSwitch);
    if(empty($result['ok'])){
        alert('⛔️ ' . ($result['message'] ?? 'تغییر سرور انجام نشد.'), true);
        exit;
    }

    $ownerId = intval($result['owner_id'] ?? $from_id);
    $links = $result['links'] ?? [];
    $newRemark = (string)($result['new_remark'] ?? '');
    if($ownerId > 0 && !empty($links)){
        $resultChangeType = ($result['change_type'] ?? 'deduct');
        $resultChangeGb = floatval($result['change_gb'] ?? ($result['deduct_gb'] ?? 0));
        $resultChangeLine = ($resultChangeType === 'add')
            ? "🔺 حجم اضافه‌شده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n"
            : "🔻 حجم کسرشده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n";
        $extra = "✅ سرور سرویس شما تغییر کرد.\n" .
                 "📍 سرور جدید: <b>" . htmlspecialchars((string)($result['target_title'] ?? ''), ENT_QUOTES, 'UTF-8') . "</b>\n" .
                 $resultChangeLine .
                 "📦 حجم باقی‌مانده جدید: <b>" . v2raystore_switchFormatGb($result['remaining_gb_after'] ?? 0) . " GB</b>";
        $deliveryOptions = is_array($result['delivery_options'] ?? null) ? $result['delivery_options'] : ['config'=>true, 'sub'=>false];
        $subLink = trim((string)($result['sub_link'] ?? ''));
        if(!empty($deliveryOptions['sub']) && empty($deliveryOptions['config']) && $subLink !== '' && function_exists('farid_sendUpdatedSubToUser')){
            farid_sendUpdatedSubToUser($ownerId, $newRemark, $subLink, $extra, '🌎 لینک ساب سرویس شما بعد از تغییر سرور');
        }elseif(!empty($deliveryOptions['sub']) && !empty($deliveryOptions['config']) && $subLink !== '' && function_exists('farid_sendUpdatedSubToUser')){
            farid_sendUpdatedConfigToUser($ownerId, $newRemark, $links, '', '🌎 لینک جدید سرویس شما بعد از تغییر سرور');
            farid_sendUpdatedSubToUser($ownerId, $newRemark, $subLink, $extra, '🌎 لینک ساب سرویس شما بعد از تغییر سرور');
        }else{
            farid_sendUpdatedConfigToUser($ownerId, $newRemark, $links, $extra, '🌎 لینک جدید سرویس شما بعد از تغییر سرور');
        }
    }
    if(function_exists('v2raystore_notifyServerSwitch')){
        v2raystore_notifyServerSwitch($result, $from_id, $isAdminSwitch);
    }

    $resultChangeType = ($result['change_type'] ?? 'deduct');
    $resultChangeGb = floatval($result['change_gb'] ?? ($result['deduct_gb'] ?? 0));
    $resultChangeLine = ($resultChangeType === 'add')
        ? "🔺 حجم اضافه‌شده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n"
        : "🔻 حجم کسرشده: <b>" . v2raystore_switchFormatGb($resultChangeGb) . " GB</b>\n";
    $msg = "✅ سرور کانفیگ با موفقیت تغییر کرد.\n\n" .
           "🔮 نام جدید: <b>" . htmlspecialchars($newRemark, ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📍 سرور جدید: <b>" . htmlspecialchars((string)($result['target_title'] ?? ''), ENT_QUOTES, 'UTF-8') . "</b>\n" .
           $resultChangeLine .
           "📦 حجم باقی‌مانده: <b>" . v2raystore_switchFormatGb($result['remaining_gb_after'] ?? 0) . " GB</b>\n\n" .
           "لینک/ساب جدید در یک پیام جداگانه برای کاربر ارسال شد.";
    editText($message_id, $msg, json_encode(['inline_keyboard'=>[
        [['text'=>'🔙 بازگشت به جزئیات کانفیگ', 'callback_data'=>'orderDetails' . intval($result['order_id'] ?? $oid)]],
        [['text'=>$buttonValues['back_to_main'] ?? 'منوی اصلی', 'callback_data'=>'mainMenu']],
    ]]), 'HTML');
    exit;
}
if(preg_match('/switchServer(.+)_(.+)/',$data,$match)){
    $sid = $match[1];
    $oid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fid = $order['fileid'];
    $protocol = $order['protocol'];
	$link = json_decode($order['link'])[0];
	
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid); 
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $flow = $file_detail['flow'] == "None"?"":$file_detail['flow'];
    $customPath = $file_detail['custom_path'] ?? null;
    $customPort = $file_detail['custom_port'] ?? null;
    $customSni = $file_detail['custom_sni'] ?? null;
    $customDomain = $file_detail['custom_domain'] ?? null;
	
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $server_info['reality'];
    $serverType = $server_info['type'];
    $panelUrl = $server_info['panel_url'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];


    if($botState['remark'] == "digits"){
        $rnd = rand(10000,99999);
        $newRemark = "{$srv_remark}-{$rnd}";
    }else{
        $rnd = rand(1111,99999);
        $newRemark = "{$srv_remark}-{$from_id}-{$rnd}";
    }
	
    if(preg_match('/vmess/',$link)){
        $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
        $uniqid = $link_info->id;
        $port = $link_info->port;
        $netType = $link_info->net;
    }else{
        $link_info = parse_url($link);
        $panel_ip = $link_info['host'];
        $uniqid = $link_info['user'];
        $protocol = $link_info['scheme'];
        $port = $link_info['port'];
        $netType = explode('type=',$link_info['query'])[1]; 
        $netType = explode('&',$netType)[0];
    }

    if($inbound_id > 0) {
        $remove_response = deleteClient($server_id, $inbound_id, $uuid);
		if(is_null($remove_response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفاً به مدیریت اطلاع بدید',true);
			exit;
		}
        if($remove_response){
            $total = $remove_response['total'];
            $up = $remove_response['up'];
            $down = $remove_response['down'];
			$id_label = $protocol == 'trojan' ? 'password' : 'id';
			if($serverType == "sanaei" || $serverType == "alireza"){
			    if($reality == "true"){
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "flow" => $flow,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];			        
			    }else{
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];
			    }
			}else{
                $newArr = [
                  "$id_label" => $uniqid,
                  "flow" => $remove_response['flow'],
                  "email" => $newRremark,
                  "limitIp" => $remove_response['limitIp'],
                  "totalGB" => $total - $up - $down,
                  "expiryTime" => $remove_response['expiryTime']
                ];
			}
            
            $response = addInboundAccount($sid, '', $inbound_id, 1, $newRemark, 0, 1, $newArr); 
            if(is_null($response)){
                alert('🔻اتصال به سرور برقرار نیست. لطفاً به مدیریت اطلاع بدید',true);
                exit;
            }
			if($response == "inbound not Found"){
                alert("🔻سطر (inbound) با آیدی $inbound_id در این سرور یافت نشد. لطفاً به مدیریت اطلاع بدید",true);
                exit;
            }
			if(!$response->success){
				alert('🔻خطا در ساخت کانفیگ. لطفاً به مدیریت اطلاع بدید',true);
				exit;
			}
			$vray_link = getConnectionLink($sid, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id, null, $customPath, $customPort, $customSni, $customDomain);
			deleteClient($server_id, $inbound_id, $uuid, 1);
        }
    }else{
        $response = deleteInbound($server_id, $uuid);
		if(is_null($response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفاً به مدیریت اطلاع بدید',true);
			exit;
		}
        if($response){
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $newRemark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                    }
                }
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = function_exists('v2raystore_subLinkFromResponseForMessage') ? v2raystore_subLinkFromResponseForMessage((isset($server_id)?$server_id:(isset($serverId)?$serverId:0)), $response, (isset($uid)?$uid:(isset($from_id)?$from_id:0)), (isset($agentBought)?$agentBought:null), (isset($payInfo)?$payInfo:null), (isset($uuid)?$uuid:''), (isset($inbound_id)?$inbound_id:0), (isset($remark)?$remark:'')) : "";
                $vraylink = $response->vray_links;

                $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ?, `uuid` =? WHERE `id` = ?");
                $stmt->bind_param("ssi", $token, $uniqid, $oid);
                $stmt->execute();
                $stmt->close();

            }else{
                $res = addUser($sid, $response['uniqid'], $response['protocol'], $response['port'], $response['expiryTime'], $newRemark, $response['volume'] / 1073741824, $response['netType'], $response['security']);
                $vray_link = getConnectionLink($sid, $response['uniqid'], $response['protocol'], $newRemark, $response['port'], $response['netType'], $inbound_id, null, $customPath, $customPort, $customSni, $customDomain);
            }
            deleteInbound($server_id, $uuid, 1);
        }
    }
    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `link`=?, `remark` = ? WHERE `id` = ?");
    $stmt->bind_param("issi", $sid, $vray_link, $newRemark, $oid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `status` = 1 ORDER BY `id` DESC");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();
    
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $cremark = $cat['remark'];
        $keyboard[] = ['text' => "$cremark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    $msg = " 📍لوکیشن سرویس $remark به $server_title با ریمارک $newRemark تغییر یافت.\n لطفاً برای مشاهده مشخصات, روی آن بزنید👇";
    
    editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit();
}
elseif(preg_match('/^deleteMyConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, "آیا از حذف کانفیگ $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بلی",'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>"نخیر",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif($data=="noDontDelete"){
    editText($message_id, "عملیه مورد نظر لغو شد",json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = intval($match[1]);
    $deleteResult = function_exists('v2raystore_fastDeleteOrderEverywhere') ? v2raystore_fastDeleteOrderEverywhere($oid, true, true) : (function_exists('v2raystore_deleteOrderEverywhere') ? v2raystore_deleteOrderEverywhere($oid, true, true) : ['ok'=>false, 'message'=>'تابع حذف پیدا نشد.']);

    if(empty($deleteResult['panel_ok']) && empty($deleteResult['local_deleted'])){
        editText($message_id, "❌ حذف کانفیگ انجام نشد. اتصال پنل یا اطلاعات کانفیگ را بررسی کن.", json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
        ]));
        exit();
    }

    $remark = $deleteResult['order']['remark'] ?? '';
    editText($message_id, "کانفیگ $remark با موفقیت حذف شد", json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
        ]
    ]));

    if(function_exists('v2raystore_buildDeleteConfigReport')){
        $owner = $deleteResult['owner'] ?? [];
        $report = v2raystore_buildDeleteConfigReport(
            $deleteResult,
            $owner['userid'] ?? $from_id,
            $owner['name'] ?? $first_name,
            $owner['username'] ?? $username
        );
        sendMessage($report, null, "HTML", $admin);
    }
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, "آیا از حذف کانفیگ $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بلی",'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>"نخیر",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = intval($match[1]);
    $deleteResult = function_exists('v2raystore_fastDeleteOrderEverywhere') ? v2raystore_fastDeleteOrderEverywhere($oid, true, true) : (function_exists('v2raystore_deleteOrderEverywhere') ? v2raystore_deleteOrderEverywhere($oid, true, true) : ['ok'=>false, 'message'=>'تابع حذف پیدا نشد.']);

    if(empty($deleteResult['panel_ok']) && empty($deleteResult['local_deleted'])){
        editText($message_id, "❌ حذف کانفیگ انجام نشد. اتصال پنل یا اطلاعات کانفیگ را بررسی کن.", json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
        ]));
        exit();
    }

    $remark = $deleteResult['order']['remark'] ?? '';
    editText($message_id, "کانفیگ $remark با موفقیت حذف شد", json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
        ]
    ]));

    if(function_exists('v2raystore_buildDeleteConfigReport')){
        $report = v2raystore_buildDeleteConfigReport($deleteResult, $from_id, $first_name, $username);
        sendMessage($report, null, "HTML", $admin);
    }
    exit();
}
if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert("در حال حاضر هیچ پلنی برای افزایش مدت زمان سرویس وجود ندارد");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = intval($cat['price']);
        if($agentBought == true){
            $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $orderInfo['fileid'], $orderInfo['server_id'], 0, 1) : $price;
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) . " تومان";
        $keyboard[] = ['text' => "$title روز $price", 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    editText($message_id, "لطفاً یکی از پلن های افزایشی را انتخاب کنید :", json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($agentBought == true){
        $planprice = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($planprice, $userInfo, $orderInfo['fileid'], $orderInfo['server_id'], 0, 1) : $planprice;
    }
    
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_DAY%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();

    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncraseDayWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "لطفاً با یکی از روش های زیر پرداخت خود را تکمیل کنید :",json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$data,$match)) {
    delMessage();
    setUser($data);
    v2raystore_sendCartToCartInstructions($match[1], 'renew_ccount_cart_to_cart', 'HTML');

    exit;
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType,$increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];

        v2raystore_markPayReceiptSent($match[1], $fileid);
    

        
        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
    
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin   
        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'زمان', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseDay{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseDay{$match[1]}"]
                ]
            ]
        ]);


        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        }
        setUser();
    }else{ 
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }

}
if(preg_match('/approveIncreaseDay(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    $result = function_exists('v2raystore_approveIncreaseDayPayByHash') ? v2raystore_approveIncreaseDayPayByHash($hashId, false) : ['ok'=>false, 'message'=>'تابع تأیید افزایش زمان در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    $approvedText = function_exists('v2raystore_approvalStatusTextFromResult') ? v2raystore_approvalStatusTextFromResult($result, false) : '✅ تأیید شد';
    $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
    if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard($approvedText, intval($result['user_id'] ?? 0), 'success', $copyText));
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید شد','callback_data'=>'dontsendanymore']]]], JSON_UNESCAPED_UNICODE));
    exit();
}

if(preg_match('/payIncraseDayWithWallet(.*)/', $data,$match)){
    if(function_exists('v2raystore_approveIncreaseDayPayByHash')){
        $hashId = trim($match[1]);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        $stmt->bind_param("s", $hashId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$payInfo){ alert('پرداخت پیدا نشد.', true); exit(); }
        $price = intval($payInfo['price'] ?? 0);
        $userwallet = intval($userInfo['wallet'] ?? 0);
        if($userwallet < $price){
            $needamount = $price - $userwallet;
            alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ", true);
            exit();
        }
        $result = v2raystore_approveIncreaseDayPayByHash($hashId, false);
        if(!$result['ok']){ alert($result['message'], true); exit(); }
        if($price > 0){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = GREATEST(`wallet` - ?, 0) WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();
        }
        editText($message_id, "✅ افزایش زمان سرویس با موفقیت انجام شد", getMainKeys());
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];

    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }

    
    
    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
        else
            $response = editInboundTraffic($server_id, $uuid, 0, $volume);
    }
        
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $newVolume = $volume * 86400;
        $stmt->bind_param("is", $newVolume, $uuid);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        $newVolume = $volume * 86400;
        $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        editText($message_id, "✅$volume روز به مدت زمان سرویس شما اضافه شد",getMainKeys());
        
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی زمان زد 😁",'callback_data'=>"v2raystore"]
                ],
            ]]);
        sendMessage("
🔋|💰 افزایش زمان با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume روز
💰قیمت: $price تومان
⁮⁮ ⁮⁮
        ",$keys,"html", $admin);

        exit;
    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفاً به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید", true);
        exit;
    }
}
if(preg_match('/^increaseAVolume(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($res->num_rows==0){
        alert("در حال حاضر هیچ پلن حجمی وجود ندارد");
        exit;
    }
    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = intval($cat['price']);
        if($agentBought == true){
            $price = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($price, $userInfo, $orderInfo['fileid'], $orderInfo['server_id'], $cat['volume'] ?? 0, 1) : $price;
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) . ' تومان';
        
        $keyboard[] = ['text' => "$title گیگ $price", 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"صفحه ی اصلی 🏘",'callback_data'=>"mainMenu"]];
    $res = editText($message_id, "لطفاً یکی از پلن های حجمی را انتخاب کنید :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/increaseVolumePlan(?<orderId>.+)_(?<volumeId>.+)/',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    $plangb = $res['volume'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $planprice = function_exists('v2raystore_applyAgentPricing') ? v2raystore_applyAgentPricing($planprice, $userInfo, $orderInfo['fileid'], $orderInfo['server_id'], $plangb ?? 0, 1) : $planprice;
    }

    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_VOLUME%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_VOLUME_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();
    
    $keyboard = array();
    
    if($planprice == 0) $planprice = ' رایگان';
    else $planprice = " " . number_format($planprice) . " تومان";
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی  " . $planprice,  'callback_data' => "payIncraseWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "لطفاً با یکی از روش های زیر پرداخت خود را تکمیل کنید :",json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/payIncreaseWithCartToCart(.*)/',$data)) {
    setUser($data);
    delMessage();
    
    v2raystore_sendCartToCartInstructions($match[1], 'renew_ccount_cart_to_cart', 'HTML');
    exit;
}
if(preg_match('/payIncreaseWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(function_exists('v2raystore_isReceiptPhotoMessage') ? v2raystore_isReceiptPhotoMessage($update) : isset($update->message->photo)){
        $fileid = function_exists('v2raystore_getBestPhotoFileId') ? v2raystore_getBestPhotoFileId($update, $fileid ?? '') : $fileid;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];
    
        v2raystore_markPayReceiptSent($match[1], $fileid);
    
        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
        $state = str_replace('payIncreaseWithCartToCart','',$userInfo['step']);
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin

        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'حجم', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);

         $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseVolume{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseVolume{$match[1]}"]
                ]
            ]
        ]);

        if(function_exists('v2raystore_sendAdminPaymentPhoto')){
            $adminSend = v2raystore_sendAdminPaymentPhoto($match[1], $fileid, $msg, $keyboard, "HTML", $uid);
            if(empty($adminSend['ok'])) sendMessage("⚠️ رسید شما ثبت شد، اما ارسال پیام به ادمین ناموفق بود. لطفاً به پشتیبانی اطلاع دهید.", null, "HTML");
        }else{
            sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        }
        setUser();
    }else{
        if(function_exists('v2raystore_sendReceiptPhotoOnlyNotice')) v2raystore_sendReceiptPhotoOnlyNotice($match[1]);
        else sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hashId = trim($match[1]);
    $result = function_exists('v2raystore_approveIncreaseVolumePayByHash') ? v2raystore_approveIncreaseVolumePayByHash($hashId, false) : ['ok'=>false, 'message'=>'تابع تأیید افزایش حجم در دسترس نیست.'];
    if(!$result['ok']){
        alert($result['message'], true);
        exit();
    }
    $approvedText = function_exists('v2raystore_approvalStatusTextFromResult') ? v2raystore_approvalStatusTextFromResult($result, false) : '✅ تأیید شد';
    $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
    if(function_exists('v2raystore_orderStatusKeyboard')) editKeys(v2raystore_orderStatusKeyboard($approvedText, intval($result['user_id'] ?? 0), 'success', $copyText));
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید شد','callback_data'=>'dontsendanymore']]]], JSON_UNESCAPED_UNICODE));
    exit();
}

if(preg_match('/decIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"لغو شد ❌",'callback_data'=>"v2raystore"]]
		    ]]));
    
    sendMessage("افزایش حجم $volume گیگ اشتراک $remark لغو شد",null,null,$uid);
}
if(preg_match('/decIncreaseDay(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"لغو شد ❌",'callback_data'=>"v2raystore"]]
		    ]]));
    
    sendMessage("افزایش زمان $volume روز اشتراک $remark لغو شد",null,null,$uid);
}
if(preg_match('/payIncraseWithWallet(.*)/', $data,$match)){
    if(function_exists('v2raystore_approveIncreaseVolumePayByHash')){
        $hashId = trim($match[1]);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        $stmt->bind_param("s", $hashId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$payInfo){ alert('پرداخت پیدا نشد.', true); exit(); }
        $price = intval($payInfo['price'] ?? 0);
        $userwallet = intval($userInfo['wallet'] ?? 0);
        if($userwallet < $price){
            $needamount = $price - $userwallet;
            alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ", true);
            exit();
        }
        $result = v2raystore_approveIncreaseVolumePayByHash($hashId, false);
        if(!$result['ok']){ alert($result['message'], true); exit(); }
        if($price > 0){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = GREATEST(`wallet` - ?, 0) WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();
        }
        editText($message_id, "✅ افزایش حجم سرویس با موفقیت انجام شد", getMainKeys());
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];


    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفاً به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, 0);
    }
        
    if($response->success){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی حجم زد 😁",'callback_data'=>"v2raystore"]
                ],
            ]]);
        sendMessage("
🔋|💰 افزایش حجم با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume گیگ
💰قیمت: $price تومان
⁮⁮ ⁮⁮
        ",$keys,"html", $admin);
        editText($message_id, "✅$volume گیگ به حجم سرویس شما اضافه شد",getMainKeys());exit;
        

    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفاً به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید",true);
        exit;
    }
}
if($data == 'cantEditTrojan'){
    alert("پروتکل تروجان فقط نوع شبکه TCP را دارد");
    exit;
}
if(($data=='categoriesSetting' || preg_match('/^nextCategoryPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getCategoriesKeys($match[1]);
    else $keys = getCategoriesKeys();
    
    editText($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if($data=='addNewCategory' and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    delMessage();
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();


    $sql = "INSERT INTO `server_categories` VALUES (NULL, 0, '', 0,2,0);";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $stmt->close();


    $msg = '▪️یه اسم برای دسته بندی وارد کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/^addNewCategory/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $step = checkStep('server_categories');
    if($step==2 and $text!=$buttonValues['cancel'] ){
        
        $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=?,`step`=4,`active`=1 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = 'یه دسته بندی جدید برات ثبت کردم 🙂☑️';
        sendMessage($msg,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getCategoriesKeys());
    }
}
if(preg_match('/^v2raystorecategorydelete(\d+)_(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("دسته بندی رو برات حذفش کردم ☹️☑️");
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keys = getCategoriesKeys($match[2]);
    editText($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if(preg_match('/^v2raystorecategoryedit/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("〽️ یه اسم جدید برا دسته بندی انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/v2raystorecategoryedit(\d+)_(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    sendMessage("☑️ مدیریت دسته ها:", getCategoriesKeys($match[2]));
}
if(($data=='serversSetting' || preg_match('/^nextServerPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getServerListKeys($match[1]);
    else $keys = getServerListKeys();
    
    editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^toggleServerState(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_info` SET `state` = IF(`state` = 0,1,0) WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    alert("وضعیت سرور با موفقیت تغییر کرد");
    
    $keys = getServerListKeys($match[2]);
    editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^toggleServerUserSales(\d+)_(\d+)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(intval($from_id) !== intval($admin)){
        alert('فقط ادمین اصلی می‌تواند فروش اختصاصی سرورها را تغییر دهد.', true);
        exit();
    }
    $serverId = intval($match[1]);
    $offset = intval($match[2]);
    $ok = function_exists('v2raystore_toggleServerUserSale') ? v2raystore_toggleServerUserSale($serverId) : false;
    if(!$ok){
        alert('تغییر وضعیت فروش سرور انجام نشد.', true);
        exit();
    }
    $keys = getServerConfigKeys($serverId, $offset);
    editText($message_id, '☑️ مدیریت سرور ها:', $keys);
    alert('وضعیت فروش این سرور برای کاربران بروزرسانی شد.');
    exit();
}
if(preg_match('/^toggleServerDeliveryMode(\d+)_(\d+)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(intval($from_id) !== intval($admin) && empty($permissions['servers'])){
        alert('برای تغییر تنظیمات سرور دسترسی ندارید.', true);
        exit();
    }
    $serverId = intval($match[1]);
    $offset = intval($match[2]);
    $next = function_exists('v2raystore_toggleServerDeliveryMode') ? v2raystore_toggleServerDeliveryMode($serverId) : false;
    if($next === false){
        alert('تغییر نحوه ارسال انجام نشد.', true);
        exit();
    }
    $keys = getServerConfigKeys($serverId, $offset);
    editText($message_id, '☑️ مدیریت سرور ها:', $keys);
    $label = function_exists('v2raystore_deliveryModeLabel') ? v2raystore_deliveryModeLabel($next) : $next;
    alert('نحوه ارسال این سرور: ' . $label);
    exit();
}
if(preg_match('/^showServerSettings(\d+)_(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getServerConfigKeys($match[1], $match[2]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
}
if(preg_match('/^manageInboundAddresses(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($match[1]);
    $view = v2raystore_inboundAddressManageView($serverId);
    editText($message_id, $view['text'], $view['keyboard'], 'HTML');
    exit();
}
if(preg_match('/^editInboundAddr_(\d+)_(\d+)$/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $serverId = intval($match[1]);
    $inboundId = intval($match[2]);
    $current = function_exists('v2raystore_getInboundAddressString') ? v2raystore_getInboundAddressString($serverId, $inboundId) : '';
    delMessage();
    sendMessage("🌐 آدرس‌های inbound #{$inboundId}\n\nآدرس فعلی:\n" . ($current !== '' ? $current : 'ثبت نشده / استفاده از حالت قبلی') . "\n\nیک یا چند دامنه/IP را هرکدام در یک خط ارسال کن.\nبرای خالی کردن این inbound، /empty را بفرست.", $cancelKey, 'HTML');
    setUser('editInboundAddr_'.$serverId.'_'.$inboundId);
    exit();
}
if(preg_match('/^editInboundAddr_(\d+)_(\d+)$/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $serverId = intval($match[1]);
    $inboundId = intval($match[2]);
    $value = trim((string)$text);
    if($value === '/empty') $value = '';
    if(function_exists('v2raystore_saveInboundAddressList')) v2raystore_saveInboundAddressList($serverId, $inboundId, $value);
    sendMessage($mainValues['saved_successfuly'] ?? 'ذخیره شد.', $removeKeyboard);
    setUser();
    $view = v2raystore_inboundAddressManageView($serverId);
    sendMessage($view['text'], $view['keyboard'], 'HTML');
    exit();
}
if(preg_match('/^changesServerIp(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $serverIp= $stmt->get_result()->fetch_assoc()['ip']??"اطلاعاتی یافت نشد";
    $stmt->close();
    
    delMessage();
    sendMessage("لیست آیپی های فعلی: \n$serverIp\nلطفاً آیپی های جدید را در خط های جدا بفرستید\n\nبرای خالی کردن متن /empty را وارد کنید",$cancelKey,null,null,null);
    setUser($data);
    exit();
}
if(preg_match('/^changesServerIp(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_config` SET `ip` = ? WHERE `id`=?");
    if($text == "/empty") $text = "";
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[1]);
    sendMessage("☑️ مدیریت سرور ها: $cname",$keys);
    exit();
}
if(preg_match('/^changesServerSubDomain(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT `sub_domain` FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $currentSubDomain = $stmt->get_result()->fetch_assoc()['sub_domain'] ?? '';
    $stmt->close();
    delMessage();
    sendMessage("🌐 آدرس دامنه/بیس لینک ساب این سرور را وارد کنید.\n\nنمونه: https://panel.frahidx.ir:30/sub/\nیا اگر لینک کامل داری همان را بفرست؛ ربات خودش توکن آخر لینک را حذف می‌کند و فقط base را ذخیره می‌کند.\n\nمقدار فعلی:\n" . ($currentSubDomain !== '' ? $currentSubDomain : 'ثبت نشده') . "\n\nبرای خالی کردن /empty را بفرست.", $cancelKey, 'HTML');
    setUser($data);
    exit();
}
if(preg_match('/^changesServerSubDomain(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $subDomain = trim((string)$text);
    if($subDomain == '/empty') $subDomain = '';
    if($subDomain !== '' && function_exists('v2raystore_normalizeManualSubscriptionBase')){
        $subDomain = v2raystore_normalizeManualSubscriptionBase($subDomain, 'sub');
    }
    $stmt = $connection->prepare("UPDATE `server_config` SET `sub_domain`=? WHERE `id`=?");
    $stmt->bind_param("si", $subDomain, $match[1]);
    $stmt->execute();
    $stmt->close();
    sendMessage($mainValues['saved_successfuly'], $removeKeyboard);
    setUser();
    $keys = getServerConfigKeys($match[1]);
    sendMessage("☑️ مدیریت سرور ها", $keys);
    exit();
}
if(preg_match('/^changePortType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `port_type` = IF(`port_type` = 'auto', 'random', 'auto') WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("نوعیت پورت سرور مورد نظر با موفقیت تغییر کرد");
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeRealityState(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `reality` = IF(`reality` = 'true', 'false', 'true') WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeServerType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"
    
🔰 نکته مهم: برای پنل 3x-ui جدید گزینه «سنایی جدید» را بزنید؛ برای 2.6.x گزینه «سنایی قدیمی» را بزنید 

❤️ اگر از پنل سنایی 2.x استفاده میکنید گزینه ( سنایی قدیمی ) را انتخاب کنید
💜 اگر از آخرین نسخه 3x-ui / سنایی 3.x استفاده میکنید گزینه ( سنایی جدید ) را انتخاب کنید
🧡 اگر از پنل علیرضا استفاده میکنید لطفاً نوع پنل را ( علیرضا ) انتخاب کنید
💚 اگر از پنل نیدوکا استفاده میکنید لطفاً نوع پنل را ( ساده ) انتخاب کنید 
💙 اگر از پنل چینی استفاده میکنید لطفاً نوع پنل را ( ساده ) انتخاب کنید 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه !
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",json_encode(['inline_keyboard'=>[
        [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $match[1]],['text'=>"سنایی قدیمی",'callback_data'=>"chhangeServerTypesanaei_" . $match[1]]],
        [['text'=>"سنایی جدید",'callback_data'=>"chhangeServerTypesanaei_new_" . $match[1]],['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $match[1]]]
        ]]));
    exit();
}
if(preg_match('/^chhangeServerType([A-Za-z0-9_]+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("UPDATE `server_config` SET `type` = ? WHERE `id`=?");
    $stmt->bind_param("si",$match[1], $match[2]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[2]);
    editText($message_id, "☑️ مدیریت سرور ها: $cname",$keys);
}
if(($data == "addNewMarzbanPanel" || $data=='addNewServer') and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    setUser($data, 'temp');
    setUser('addserverName');
    sendMessage("مرحله اول: 
▪️یه اسم برا سرورت انتخاب کن:",$cancelKey);
    exit();
}
if($userInfo['step'] == 'addserverName' and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
	sendMessage('مرحله دوم: 
▪️ظرفیت تعداد ساخت کانفیگ رو برای سرورت مشخص کن ( عدد باشه )');
    $data = array();
    $data['title'] = $text;

    setUser('addServerUCount' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerUCount(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['ucount'] = $text;

    sendMessage("مرحله سوم: 
▪️یه اسم ( ریمارک ) برا کانفیگ انتخاب کن:
 ( به صورت انگیلیسی و بدون فاصله )
");
    setUser('addServerRemark' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerRemark(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1], true);
    $data['remark'] = $text;

    sendMessage("مرحله چهارم:
▪️لطفاً یه ( ایموجی پرچم 🇮🇷 ) برا سرورت انتخاب کن:");
    setUser('addServerFlag' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerFlag(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['flag'] = $text;
    sendMessage("مرحله پنجم:

▪️لطفاً آدرس پنل x-ui رو به صورت مثال زیر وارد کن:

❕https://yourdomain.com:54321
❕https://yourdomain.com:54321/path
❗️http://125.12.12.36:54321
❗️http://125.12.12.36:54321/path

اگر سرور مورد نظر با دامنه و ssl هست از مثال ( ❕) استفاده کنید
اگر سرور مورد نظر با ip و بدون ssl هست از مثال ( ❗️) استفاده کنید
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
");
    setUser('addServerPanelUrl' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerPanelUrl(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_url'] = $text;
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $data['panel_ip'] = "/empty";
        $data['sni'] = "/empty";
        $data['header_type'] = "/empty";
        $data['response_header'] = "/empty";
        $data['request_header'] = "/empty";
        $data['security'] = "/empty";
        $data['tls_setting'] = "/empty";
        
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "مرحله ششم: 
    ▪️لطفاً یوزر پنل را وارد کنید:");
    
        exit();
    }else{
        setUser('addServerIp' . json_encode($data,JSON_UNESCAPED_UNICODE));
        sendMessage( "🔅 لطفاً ip یا دامنه تانل شده پنل را وارد کنید:
    
    نمونه: 
    91.257.142.14
    sub.domain.com
    ❗️در صورتی که میخواید چند دامنه یا ip کانفیگ بگیرید باید زیر هم بنویسید و برای ربات بفرستین:
        \n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
        exit();
    }
}
if(preg_match('/^addServerIp(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_ip'] = $text;
    setUser('addServerSni' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفاً sni پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerSni(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['sni'] = $text;
    setUser('addServerHeaderType' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 اگر  از header type استفاده میکنید لطفاً http را تایپ کنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerHeaderType(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['header_type'] = $text;
    setUser('addServerRequestHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅اگر از هدر استفاده میکنید لطفاً آدرس رو به این صورت Host:test.com وارد کنید و به جای test.com آدرس دلخواه بزنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerRequestHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['request_header'] = $text;
    setUser('addServerResponseHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفاً response header پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerResponseHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['response_header'] = $text;
    setUser('addServerSecurity' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفاً security پنل را وارد کنید

⚠️ توجه: برای استفاده از tls یا xtls لطفاً کلمه tls یا xtls رو تایپ کنید در غیر این صورت 👇
\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
exit();
}
if(preg_match('/^addServerSecurity(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['security'] = $text;
    setUser('addServerTlsSetting' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage("
    🔅 لطفاً tls|xtls setting پنل را وارد کنید🔻برای خالی گذاشتن متن /empty را وارد کنید 

⚠️ لطفاً تنظیمات سرتیفیکیت رو با دقت انجام بدید مثال:
▫️serverName: yourdomain
▫️certificateFile: /root/cert.crt
▫️keyFile: /root/private.key
\n
"
        .'<b>tls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}]}</code>' . "\n"
        .'<b>xtls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}],"alpn": []}</code>', null, "HTML");

    exit();
}
if(preg_match('/^addServerTlsSetting(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['tls_setting'] = $text;
    setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله ششم: 
▪️لطفاً یوزر پنل را وارد کنید:");

    exit();
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله هفتم: 
▪️لطفاً پسورد پنل را وارد کنید:");
exit();
}
if(preg_match('/^addServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
    $data = json_decode($match[1],true);
    $title = $data['title'];
    $ucount = $data['ucount'];
    $remark = $data['remark'];
    $flag = $data['flag'];

    $panel_url = $data['panel_url'];
    $ip = $data['panel_ip']!="/empty"?$data['panel_ip']:"";
    $sni = $data['sni']!="/empty"?$data['sni']:"";
    $header_type = $data['header_type']!="/empty"?$data['header_type']:"none";
    $request_header = $data['request_header']!="/empty"?$data['request_header']:"";
    $response_header = $data['response_header']!="/empty"?$data['response_header']:"";
    $security = $data['security']!="/empty"?$data['security']:"none";
    $tlsSettings = $data['tls_setting']!="/empty"?$data['tls_setting']:"";
    $serverName = $data['panel_user'];
    $serverPass = $text;
    
    
    $loginResponse['success'] = false;
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
        );
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'accept: application/json'
            ));
        $response = json_decode(curl_exec($curl),true);
        
        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);
    
        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($ch, $loginUrl));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
        
    }
    if(!$loginResponse['success']){
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "
⚠️ با خطا مواجه شدی ! 


مجدد نام کاربری پنل را وارد کنید:
⁮⁮ ⁮⁮
        ");
        exit();
    }
    $stmt = $connection->prepare("INSERT INTO `server_info` (`title`, `ucount`, `remark`, `flag`, `active`)
                                                    VALUES (?,?,?,?,1)");
    $stmt->bind_param("siss", $title, $ucount, $remark, $flag);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    $stmt = $connection->prepare("INSERT INTO `server_config` (`id`, `panel_url`, `ip`, `sni`, `header_type`, `request_header`, `response_header`, `security`, `tlsSettings`, `username`, `password`)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $rowId, $panel_url, $ip, $sni, $header_type, $request_header, $response_header, $security, $tlsSettings, $serverName, $serverPass);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    sendMessage(" تبریک ; سرورت رو ثبت کردی 🥹",$removeKeyboard);
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $stmt = $connection->prepare("UPDATE `server_config` SET `type` = 'marzban' WHERE `id`=?");
        $stmt->bind_param("i",$rowId);
        $stmt->execute();
        $stmt->close();
        
        $keys = getServerListKeys();
        sendMessage("☑️ مدیریت سرور ها",$keys);
    }else{
        sendMessage("
    
🔰 نکته مهم: برای پنل 3x-ui جدید گزینه «سنایی جدید» را بزنید؛ برای 2.6.x گزینه «سنایی قدیمی» را بزنید 

❤️ اگر از پنل سنایی 2.x استفاده میکنید گزینه ( سنایی قدیمی ) را انتخاب کنید
💜 اگر از آخرین نسخه 3x-ui / سنایی 3.x استفاده میکنید گزینه ( سنایی جدید ) را انتخاب کنید
🧡 اگر از پنل علیرضا استفاده میکنید لطفاً نوع پنل را ( علیرضا ) انتخاب کنید
💚 اگر از پنل نیدوکا استفاده میکنید لطفاً نوع پنل را ( ساده ) انتخاب کنید 
💙 اگر از پنل چینی استفاده میکنید لطفاً نوع پنل را ( ساده ) انتخاب کنید 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه !
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
    ",json_encode(['inline_keyboard'=>[
            [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $rowId],['text'=>"سنایی قدیمی",'callback_data'=>"chhangeServerTypesanaei_" . $rowId]],
            [['text'=>"سنایی جدید",'callback_data'=>"chhangeServerTypesanaei_new_" . $rowId],['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $rowId]]
            ]]));
    }
    setUser();
    exit();
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser($data);
    sendMessage( "▪️لطفاً آدرس پنل را وارد کنید:",$cancelKey);
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = array();
    $data['rowId'] = $match[1];
    $data['panel_url'] = $text;
    setUser('editServerPaneUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️لطفاً یوزر پنل را وارد کنید:",$cancelKey);
    exit();
}
if(preg_match('/^editServerPaneUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('editServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️لطفاً پسورد پنل را وارد کنید:");
    exit();
}
if(preg_match('/^editServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
    $data = json_decode($match[1],true);

    $rowId = $data['rowId'];
    $panel_url = $data['panel_url'];
    $serverName = $data['panel_user'];
    $serverPass = $text;
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverInfo['type'];
    $loginResponse['success'] = false;
    
    if($serverType == "marzban"){
        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
        );
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'accept: application/json'
            ));
        $response = json_decode(curl_exec($curl),true);
        
        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);
    
        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($ch, $loginUrl));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
    }
    
    if(!$loginResponse['success']) sendMessage( "اطلاعاتی که وارد کردی اشتباهه 😂");
    else{
        $stmt = $connection->prepare("UPDATE `server_config` SET `panel_url` = ?, `username` = ?, `password` = ? WHERE `id` = ?");
        $stmt->bind_param("sssi", $panel_url, $serverName, $serverPass, $rowId);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("اطلاعات ورود سرور با موفقیت عوض شد",$removeKeyboard);
    }
    $keys = getServerConfigKeys($rowId);
    sendMessage('☑️ مدیریت سرور ها:',$keys);
    setUser();
}
if(preg_match('/^v2raystoredeleteserver(\d+)/',$data,$match) and ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    editText($message_id,"از حذف سرور مطمئنی؟",json_encode(['inline_keyboard'=>[
        [['text'=>"بله",'callback_data'=>"yesDeleteServer" . $match[1]],['text'=>"نخير",'callback_data'=>"showServerSettings" . $match[1] . "_0"]]
        ]]));
}
if(preg_match('/^yesDeleteServer(\d+)/',$data,$match) && ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    $stmt = $connection->prepare("DELETE FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("DELETE FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("🙂 سرور رو چرا حذف کردی اخه ...");
    

    $keys = getServerListKeys();
    if($keys == null) editText($message_id,"موردی یافت نشد");
    else editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break; 
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم"; 
            break;
        default:
            $txt = str_replace("_", " ", $match[1]);
            $end = "برای خالی کردن متن /empty را وارد کنید";
            break;
    }
    delMessage();
    sendMessage("🔘|لطفاً " . $txt . " جدید را وارد کنید" . $end,$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $sql = "UPDATE `server_info` SET `title`";
            break;
        case "Flag":
            $sql = "UPDATE `server_info` SET `flag`";
            break;
        case "Remark":
            $sql = "UPDATE `server_info` SET `remark`";
            break;
        case "Max":
            $sql = "UPDATE `server_info` SET `ucount`";
            break;
    }
    
    if($text == "/empty"){
        $stmt = $connection->prepare("$sql IS NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[2]);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("$sql=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
        $stmt->execute();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $txt = str_replace("_", " ", $match[1]);
    delMessage();
    sendMessage("🔘|لطفاً " . $txt . " جدید را وارد کنید\nبرای خالی کردن متن /empty را وارد کنید",$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($text == "/empty"){
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni` = '' WHERE `id`=?");
        elseif($match[1] == "header_type") $stmt = $connection->prepare("UPDATE `server_config` SET `header_type` = 'none' WHERE `id`=?");
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header` = '' WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header` = '' WHERE `id`=?");
        elseif($match[1] == "security") $stmt = $connection->prepare("UPDATE `server_config` SET `security` = 'none' WHERE `id`=?");
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings` = '' WHERE `id`=?");

        $stmt->bind_param("i", $match[2]);
    }else{
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni`=? WHERE `id`=?");
        elseif($match[1] == "header_type"){
            if($text != "http" && $text != "none"){
                sendMessage("برای نوع header type فقط none و یا http مجاز است");
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `header_type`=? WHERE `id`=?");
        }
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header`=? WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header`=? WHERE `id`=?");
        elseif($match[1] == "security"){
            if($text != "tls" && $text != "none" && $text != "xtls"){
                sendMessage("برای نوع security فقط tls یا xtls و یا هم none مجاز است");
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `security`=? WHERE `id`=?");
        }
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings`=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
    }
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break;
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم";
            break;
    }
    delMessage();
    sendMessage("🔘|لطفاً " . $txt . " جدید را وارد کنید",$cancelKey);
    setUser($data);
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $stmt = $connection->prepare("UPDATE `server_info` SET `title`=? WHERE `id`=?");
            break;
        case "Max":
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount`=? WHERE `id`=?");
            break;
        case "Remark":
            $stmt = $connection->prepare("UPDATE `server_info` SET `remark`=? WHERE `id`=?");
            break;
        case "Flag":
            $stmt = $connection->prepare("UPDATE `server_info` SET `flag`=? WHERE `id`=?");
            break;
    }
    
    $stmt->bind_param("si",$text, $match[2]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
}
if($data=="discount_codes" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"مدیریت کد های تخفیف",getDiscountCodeKeys());
}
if($data=="addDiscountCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🔘|لطفاً مقدار تخفیف را وارد کنید\nبرای درصد علامت % را در کنار عدد وارد کنید در غیر آن مقدار تخفیف به تومان محاسبه میشود",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addDiscountCode" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dInfo = array();
    $dInfo['type'] = 'amount';
    if(strstr($text, "%")) $dInfo['type'] = 'percent';
    $text = trim(str_replace("%", "", $text));
    if(is_numeric($text)){
        $dInfo['amount'] = $text;
        setUser("addDiscountDate" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|لطفاً مدت زمان این تخفیف را به روز وارد کنید\nبرای نامحدود بودن 0 وارد کنید");
    }else sendMessage("🔘|لطفاً فقط عدد و یا درصد بفرستید");
}
if(preg_match('/^addDiscountDate(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $dInfo = json_decode($match[1],true);
        $dInfo['date'] = $text != 0?time() + ($text * 24 * 60 * 60):0;
        
        setUser("addDiscountCount" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|لطفاً تعداد استفاده این تخفیف را وارد کنید\nبرای نامحدود بودن 0 وارد کنید");
    }else sendMessage("🔘|لطفاً فقط عدد بفرستید");
}
if(preg_match('/^addDiscountCount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['count'] = $text>0?$text:-1;
        
        setUser('addDiscountCanUse' . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("لطفاً تعداد استفاده هر یوزر را وارد کنید");
    }else sendMessage("🔘|لطفاً فقط عدد بفرستید");
}
if(preg_match('/^addDiscountCanUse(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['can_use'] = $text>0?$text:-1;
         
        $hashId = RandomString();
        
        $stmt = $connection->prepare("INSERT INTO `discounts` (`hash_id`, `type`, `amount`, `expire_date`, `expire_count`, `can_use`)
                                        VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssiiii", $hashId, $dInfo['type'], $dInfo['amount'], $dInfo['date'], $dInfo['count'], $dInfo['can_use']);
        $stmt->execute();
        $stmt->close();
        sendMessage("کد تخفیف جدید (<code>$hashId</code>) با موفقیت ساخته شد",$removeKeyboard,"HTML");
        setUser();
        sendMessage("مدیریت کد های تخفیف",getDiscountCodeKeys());
    }else sendMessage("🔘|لطفاً فقط عدد بفرستید");
}
if(preg_match('/^delDiscount(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `discounts` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("کد تخفیف مورد نظر با موفقیت حذف شد");
    editText($message_id,"مدیریت کد های تخفیف",getDiscountCodeKeys());
}
if(preg_match('/^copyHash(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("<code>" . $match[1] . "</code>",null,"HTML");
}
if($data == "managePanel" and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    
    setUser();
    $msg = "
👤 عزیزم به بخش مدیریت خوشومدی 
🤌 هرچی نیاز داشتی میتونی اینجا طبق نیازهای فروشگاهت اضافه و تغییر بدی، عزیزم $first_name جان.



🚪 /start
";
    editText($message_id, $msg, getAdminKeysPlus());
}

if(in_array($data ?? '', ['faqMenu', 'tutorialsMenu', 'reciveApplications'], true)){
    $helpType = (($data ?? '') === 'faqMenu') ? 'faq' : 'tutorial';
    editText($message_id, v2raystore_helpUserMenuText($helpType), v2raystore_helpUserMenuKeys($helpType), 'HTML');
    exit();
}
if(preg_match('/^help(Faq|Tut)Item_(\d+)$/', $data ?? '', $match)){
    $helpType = ($match[1] === 'Faq') ? 'faq' : 'tutorial';
    editText($message_id, v2raystore_helpUserItemText($helpType, $match[2]), v2raystore_helpUserItemKeys($helpType), 'HTML');
    exit();
}

if($data == 'adminHelpMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_helpAdminHomeText(), v2raystore_helpAdminHomeKeys(), 'HTML');
    exit();
}
if(preg_match('/^adminHelpList_(faq|tutorial)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_helpAdminListText($match[1]), v2raystore_helpAdminListKeys($match[1]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpItem_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, v2raystore_helpAdminItemText($match[1], $match[2]), v2raystore_helpAdminItemKeys($match[1], $match[2]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpToggle_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $item = v2raystore_helpFindItem($match[1], $match[2]);
    if($item){
        v2raystore_helpUpdateItem($match[1], $match[2], ['enabled'=>empty($item['enabled'])]);
        editText($message_id, v2raystore_helpAdminItemText($match[1], $match[2]), v2raystore_helpAdminItemKeys($match[1], $match[2]), 'HTML');
    }else alert('مورد پیدا نشد.', true);
    exit();
}
if(preg_match('/^adminHelpDelete_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $item = v2raystore_helpFindItem($match[1], $match[2]);
    if(!$item){ alert('مورد پیدا نشد.', true); exit(); }
    editText($message_id, "🗑 آیا از حذف این مورد مطمئن هستید؟\n\n<b>" . v2raystore_h($item['title']) . "</b>", v2raystore_helpAdminDeleteKeys($match[1], $match[2]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpConfirmDelete_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    v2raystore_helpDeleteItem($match[1], $match[2]);
    editText($message_id, v2raystore_helpAdminListText($match[1]), v2raystore_helpAdminListKeys($match[1]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpAdd_(faq|tutorial)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $cfg = v2raystore_helpTypeConfig($match[1]);
    sendMessage("➕ لطفاً عنوان مورد جدید برای <b>" . v2raystore_h($cfg['title']) . "</b> را ارسال کنید.", $cancelKey, 'HTML');
    setUser('adminHelpAddTitle_' . $match[1]);
    setUser('', 'temp');
    exit();
}
if(preg_match('/^adminHelpEditTitle_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $item = v2raystore_helpFindItem($match[1], $match[2]);
    if(!$item){ alert('مورد پیدا نشد.', true); exit(); }
    sendMessage("✏️ عنوان جدید را ارسال کنید:\n\nعنوان فعلی: <b>" . v2raystore_h($item['title']) . "</b>", $cancelKey, 'HTML');
    setUser('adminHelpEditTitle_' . $match[1] . '_' . intval($match[2]));
    exit();
}
if(preg_match('/^adminHelpEditText_(faq|tutorial)_(\d+)$/', $data ?? '', $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $item = v2raystore_helpFindItem($match[1], $match[2]);
    if(!$item){ alert('مورد پیدا نشد.', true); exit(); }
    sendMessage("📝 متن جدید را ارسال کنید.\n\nمی‌توانید متن چندخطی بفرستید.", $cancelKey, 'HTML');
    setUser('adminHelpEditText_' . $match[1] . '_' . intval($match[2]));
    exit();
}
if(preg_match('/^adminHelpAddTitle_(faq|tutorial)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $title = trim((string)$text);
    if($title === ''){
        sendMessage('عنوان نمی‌تواند خالی باشد. دوباره ارسال کنید.');
        exit();
    }
    setUser(function_exists('v2raystore_helpLimitText') ? v2raystore_helpLimitText($title, 120) : $title, 'temp');
    setUser('adminHelpAddText_' . $match[1]);
    sendMessage("📝 حالا متن کامل این مورد را ارسال کنید.\n\nعنوان: <b>" . v2raystore_h($title) . "</b>", $cancelKey, 'HTML');
    exit();
}
if(preg_match('/^adminHelpAddText_(faq|tutorial)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $title = trim((string)($userInfo['temp'] ?? ''));
    $body = trim((string)$text);
    if($title === ''){
        setUser('adminHelpAddTitle_' . $match[1]);
        sendMessage('عنوان ذخیره نشده بود. لطفاً عنوان را دوباره ارسال کنید.', $cancelKey, 'HTML');
        exit();
    }
    if($body === ''){
        sendMessage('متن نمی‌تواند خالی باشد. دوباره ارسال کنید.');
        exit();
    }
    v2raystore_helpAddItem($match[1], $title, $body);
    setUser();
    setUser('', 'temp');
    sendMessage('✅ مورد جدید ذخیره شد.', $removeKeyboard, 'HTML');
    sendMessage(v2raystore_helpAdminListText($match[1]), v2raystore_helpAdminListKeys($match[1]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpEditTitle_(faq|tutorial)_(\d+)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $title = trim((string)$text);
    if($title === ''){
        sendMessage('عنوان نمی‌تواند خالی باشد. دوباره ارسال کنید.');
        exit();
    }
    v2raystore_helpUpdateItem($match[1], $match[2], ['title'=>$title]);
    setUser();
    sendMessage('✅ عنوان ذخیره شد.', $removeKeyboard, 'HTML');
    sendMessage(v2raystore_helpAdminItemText($match[1], $match[2]), v2raystore_helpAdminItemKeys($match[1], $match[2]), 'HTML');
    exit();
}
if(preg_match('/^adminHelpEditText_(faq|tutorial)_(\d+)$/', $userInfo['step'] ?? '', $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $body = trim((string)$text);
    if($body === ''){
        sendMessage('متن نمی‌تواند خالی باشد. دوباره ارسال کنید.');
        exit();
    }
    v2raystore_helpUpdateItem($match[1], $match[2], ['body'=>$body]);
    setUser();
    sendMessage('✅ متن ذخیره شد.', $removeKeyboard, 'HTML');
    sendMessage(v2raystore_helpAdminItemText($match[1], $match[2]), v2raystore_helpAdminItemKeys($match[1], $match[2]), 'HTML');
    exit();
}

if($data == 'reciveApplications') {
    $stmt = $connection->prepare("SELECT * FROM `needed_sofwares` WHERE `status`=1");
    $stmt->execute();
    $respd= $stmt->get_result();
    $stmt->close();

    $keyboard = []; 
    while($file =  $respd->fetch_assoc()){ 
        $link = $file['link'];
        $title = $file['title'];
        $keyboard[] = ['text' => "$title", 'url' => $link];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"];
    $keyboard = array_chunk($keyboard,1); 
    editText($message_id, "
🔸می توانید به راحتی همه فایل ها را (به صورت رایگان) دریافت کنید
📌 شما میتوانید برای راهنمای اتصال به سرویس کانال رسمی مارا دنبال کنید و همچنین از دکمه های زیر میتوانید برنامه های مورد نیاز هر سیستم عامل را دانلود کنید

✅ پیشنهاد ما برنامه V2rayng است زیرا کار با آن ساده است و برای تمام سیستم عامل ها قابل اجرا است، میتوانید به بخش سیستم عامل مورد نظر مراجعه کنید و لینک دانلود را دریافت کنید
", json_encode(['inline_keyboard'=>$keyboard]));
}
if ($text == $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['waiting_message'], $removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
}


/* ======================================================================
   ✅ Functions added/edited for:
   - Dedicated Admin panel for "Update & Send Configs"
   - User "Update Config" button inside My Configs (Order details)
   - Fixes for broadcast/forward cancel + safer keyboards
   ====================================================================== */


function farid_textSettingsKeyboard(){
    global $buttonValues;
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✏️ تغییر متن خوش‌آمدگویی', 'callback_data'=>'editStartWelcomeText', 'style'=>'success']
        ],
        [
            ['text'=>'📢 تغییر قوانین/آخرین اخبار خرید', 'callback_data'=>'editPurchaseRulesText', 'style'=>'success']
        ],
        [
            ['text'=>'🗑 حذف متن قوانین/اخبار خرید', 'callback_data'=>'clearPurchaseRulesText', 'style'=>'danger']
        ],
        [
            ['text'=>$buttonValues['back_button'] ?? '🔙 برگشت', 'callback_data'=>'botSettings', 'style'=>'primary']
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function farid_valuesFilePath(){
    return __DIR__ . '/settings/values.php';
}

function farid_updateValuesFileOnly($key, $value){
    $file = farid_valuesFilePath();
    if(!file_exists($file) || !is_writable($file)) return false;
    $content = file_get_contents($file);
    if($content === false) return false;
    $quotedKey = preg_quote($key, '/');
    $pattern = '/([\'\"]' . $quotedKey . '[\'\"]\s*=>\s*)(?:\"(?:\\\\.|[^\"\\\\])*\"|\'(?:\\\\.|[^\'\\\\])*\')/s';
    $replacement = '$1' . var_export((string)$value, true);
    $newContent = preg_replace($pattern, $replacement, $content, 1, $count);
    if($newContent === null || $count < 1) return false;
    @copy($file, $file . '.bak_' . date('Ymd_His'));
    return file_put_contents($file, $newContent, LOCK_EX) !== false;
}

function farid_updateMainValueInValuesFile($key, $value){
    if(function_exists('v2raystore_saveMainText')){
        $saved = v2raystore_saveMainText($key, (string)$value);
        if($saved !== true) return $saved;
        // ذخیره در فایل فقط برای سازگاری نسخه‌های قدیمی است؛ منبع اصلی از این به بعد دیتابیس است.
        if($key === 'start_message') @farid_updateValuesFileOnly($key, (string)$value);
        return true;
    }
    return 'تابع ذخیره دیتابیسی متن در دسترس نیست.';
}


/* ======================================================================
   🔁 Helper functions for admin inbound migration
   ====================================================================== */

function farid_inboundMoveDefaultJob(){
    return [
        'state'=>0,
        'ids'=>[],
        'server_id'=>0,
        'source_inbound'=>0,
        'target_inbound'=>0,
        'offset'=>0,
        'batch'=>5,
        'created_at'=>0,
        'requested_by'=>0,
        'filter_title'=>'',
        'stats'=>['processed'=>0,'moved'=>0,'sent'=>0,'failed'=>0,'skipped'=>0],
        'errors'=>[],
        'status_chat_id'=>0,
        'status_message_id'=>0,
        'auto_running'=>0,
        'auto_last_ts'=>0,
        'stopped_at'=>0,
        'stopped_by'=>0,
    ];
}

function farid_inboundMoveGetJob(){
    $job = farid_inboundMoveDefaultJob();
    $raw = function_exists('farid_getSettingValue') ? farid_getSettingValue('INBOUND_MOVE_JOB') : '';
    if($raw){
        $data = json_decode((string)$raw, true);
        if(is_array($data)) $job = array_merge($job, $data);
    }
    $job['state'] = intval($job['state'] ?? 0);
    $job['server_id'] = intval($job['server_id'] ?? 0);
    $job['source_inbound'] = intval($job['source_inbound'] ?? 0);
    $job['target_inbound'] = intval($job['target_inbound'] ?? 0);
    $job['offset'] = max(0, intval($job['offset'] ?? 0));
    $job['batch'] = max(1, min(12, intval($job['batch'] ?? 5)));
    $job['created_at'] = intval($job['created_at'] ?? 0);
    $job['requested_by'] = intval($job['requested_by'] ?? 0);
    $job['status_chat_id'] = intval($job['status_chat_id'] ?? 0);
    $job['status_message_id'] = intval($job['status_message_id'] ?? 0);
    $job['auto_running'] = intval($job['auto_running'] ?? 0);
    if(!isset($job['ids']) || !is_array($job['ids'])) $job['ids'] = [];
    $job['ids'] = array_values(array_unique(array_map('intval', $job['ids'])));
    if(!isset($job['stats']) || !is_array($job['stats'])) $job['stats'] = ['processed'=>0,'moved'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    foreach(['processed','moved','sent','failed','skipped'] as $k) $job['stats'][$k] = intval($job['stats'][$k] ?? 0);
    if(!isset($job['errors']) || !is_array($job['errors'])) $job['errors'] = [];
    return $job;
}

function farid_inboundMoveSetJob($job){
    if(!function_exists('farid_setSettingValue')) return false;
    return farid_setSettingValue('INBOUND_MOVE_JOB', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function farid_inboundMoveCreateJob($ids, $serverId, $sourceInbound, $targetInbound, $actorId, $title = ''){
    $job = farid_inboundMoveDefaultJob();
    $job['state'] = 1;
    $job['ids'] = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    $job['server_id'] = intval($serverId);
    $job['source_inbound'] = intval($sourceInbound);
    $job['target_inbound'] = intval($targetInbound);
    $job['batch'] = count($job['ids']) <= 1 ? 1 : 5;
    $job['created_at'] = time();
    $job['requested_by'] = intval($actorId);
    $job['filter_title'] = trim((string)$title);
    farid_inboundMoveSetJob($job);
    return $job;
}

function farid_inboundMoveFetchOrder($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return null;
    $stmt = @$connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function farid_inboundMoveOrderIdsBySource($serverId, $sourceInbound, $limit = 2000){
    global $connection;
    $serverId = intval($serverId); $sourceInbound = intval($sourceInbound); $limit = max(1, min(5000, intval($limit)));
    if($serverId <= 0 || $sourceInbound <= 0) return [];
    $stmt = @$connection->prepare("SELECT `id` FROM `orders_list` WHERE `status`=1 AND `server_id`=? AND `inbound_id`=? ORDER BY `id` ASC LIMIT ?");
    if(!$stmt) return [];
    $stmt->bind_param('iii', $serverId, $sourceInbound, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while($row = $res->fetch_assoc()) $ids[] = intval($row['id']);
    $stmt->close();
    return $ids;
}

function farid_inboundMoveSourceCount($serverId, $sourceInbound){
    global $connection;
    $serverId = intval($serverId); $sourceInbound = intval($sourceInbound);
    if($serverId <= 0 || $sourceInbound <= 0) return 0;
    $stmt = @$connection->prepare("SELECT COUNT(*) AS `cnt` FROM `orders_list` WHERE `status`=1 AND `server_id`=? AND `inbound_id`=?");
    if(!$stmt) return 0;
    $stmt->bind_param('ii', $serverId, $sourceInbound);
    $stmt->execute();
    $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt;
}

function farid_inboundMoveSources($page = 0, $perPage = 10){
    global $connection;
    $page = max(0, intval($page)); $perPage = max(1, min(20, intval($perPage))); $offset = $page * $perPage;
    $sql = "SELECT o.`server_id`, o.`inbound_id`, COUNT(*) AS `cnt`, COALESCE(si.`title`, CONCAT('Server ', o.`server_id`)) AS `server_title`
            FROM `orders_list` o
            LEFT JOIN `server_info` si ON si.`id` = o.`server_id`
            WHERE o.`status`=1 AND o.`inbound_id` > 0
            GROUP BY o.`server_id`, o.`inbound_id`, si.`title`
            ORDER BY o.`server_id` ASC, o.`inbound_id` ASC
            LIMIT ? OFFSET ?";
    $stmt = @$connection->prepare($sql);
    if(!$stmt) return [];
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function farid_inboundMoveSourcesTotal(){
    global $connection;
    $res = @$connection->query("SELECT COUNT(*) AS `cnt` FROM (SELECT `server_id`,`inbound_id` FROM `orders_list` WHERE `status`=1 AND `inbound_id`>0 GROUP BY `server_id`,`inbound_id`) x");
    if(!$res) return 0;
    return intval($res->fetch_assoc()['cnt'] ?? 0);
}

function farid_inboundMoveOrdersPage($serverId, $sourceInbound, $page = 0, $perPage = 8){
    global $connection;
    $serverId = intval($serverId); $sourceInbound = intval($sourceInbound); $page = max(0, intval($page)); $perPage = max(1, min(20, intval($perPage))); $offset = $page * $perPage;
    if($serverId <= 0 || $sourceInbound <= 0) return [];
    $stmt = @$connection->prepare("SELECT `id`,`userid`,`remark`,`uuid`,`expire_date` FROM `orders_list` WHERE `status`=1 AND `server_id`=? AND `inbound_id`=? ORDER BY `id` ASC LIMIT ? OFFSET ?");
    if(!$stmt) return [];
    $stmt->bind_param('iiii', $serverId, $sourceInbound, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function farid_inboundMoveMenuText(){
    $job = farid_inboundMoveGetJob();
    $active = intval($job['state'] ?? 0) == 1;
    $txt = "🔁 <b>تغییر اینباند کانفیگ‌ها</b>\n\n" .
           "از این بخش کانفیگ‌های ثبت‌شده روی inbound قدیمی را به inbound جدید همان سرور منتقل می‌کنی.\n" .
           "برای هر کانفیگ، ربات کانفیگ جدید می‌سازد، لینک جدید را ذخیره می‌کند، برای کاربر می‌فرستد و بعد کانفیگ قبلی را حذف می‌کند.\n\n";
    if($active){
        $txt .= "⚙️ عملیات فعال داری:\n" . farid_inboundMoveProgressText($job, true) . "\n\n";
    }
    $txt .= "📌 اول inbound مبدا را انتخاب کن:";
    return $txt;
}

function farid_inboundMoveMenuKeys($page = 0){
    global $buttonValues;
    $page = max(0, intval($page));
    $rows = [];
    $job = farid_inboundMoveGetJob();
    if(intval($job['state'] ?? 0) == 1){
        $rows[] = [['text'=>'🚀 ادامه عملیات فعال', 'callback_data'=>'inboundMoveRun'], ['text'=>'📊 وضعیت', 'callback_data'=>'inboundMoveStatus']];
        $rows[] = [['text'=>'⛔ توقف عملیات فعال', 'callback_data'=>'inboundMoveStop']];
    }
    foreach(farid_inboundMoveSources($page, 10) as $src){
        $serverTitle = trim((string)($src['server_title'] ?? ('Server ' . intval($src['server_id']))));
        if(function_exists('mb_substr')) $serverTitle = mb_substr($serverTitle, 0, 24, 'UTF-8');
        else $serverTitle = substr($serverTitle, 0, 24);
        $rows[] = [[
            'text' => $serverTitle . ' | inbound ' . intval($src['inbound_id']) . ' | ' . intval($src['cnt']) . ' عدد',
            'callback_data' => 'inboundMoveSrc_' . intval($src['server_id']) . '_' . intval($src['inbound_id']) . '_0'
        ]];
    }
    $total = farid_inboundMoveSourcesTotal();
    $maxPage = max(0, (int)ceil($total / 10) - 1);
    if($maxPage > 0){
        $nav = [];
        if($page > 0) $nav[] = ['text'=>'⬅️ قبلی', 'callback_data'=>'inboundMoveSourcesPage_' . ($page - 1)];
        $nav[] = ['text'=>($page + 1) . '/' . ($maxPage + 1), 'callback_data'=>'v2raystore'];
        if($page < $maxPage) $nav[] = ['text'=>'بعدی ➡️', 'callback_data'=>'inboundMoveSourcesPage_' . ($page + 1)];
        $rows[] = $nav;
    }
    $rows[] = [['text'=>$buttonValues['back_button'] ?? 'برگشت', 'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function farid_inboundMoveTargetListText($serverId, $sourceInbound = 0){
    $json = function_exists('getJson') ? getJson(intval($serverId)) : null;
    if(!$json || !isset($json->obj) || !is_array($json->obj)) return "لیست inboundهای مقصد از پنل خوانده نشد؛ فقط آیدی مقصد را دستی بفرست.";
    $lines = ["📋 چند inbound موجود روی همین سرور:"];
    $shown = 0;
    foreach($json->obj as $row){
        $id = intval($row->id ?? 0);
        if($id <= 0 || $id == intval($sourceInbound)) continue;
        $remark = trim((string)($row->remark ?? ''));
        $proto = trim((string)($row->protocol ?? ''));
        $lines[] = "• <code>$id</code> | " . htmlspecialchars($proto, ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($remark, ENT_QUOTES, 'UTF-8');
        $shown++;
        if($shown >= 12) break;
    }
    if($shown <= 0) $lines[] = 'موردی غیر از inbound مبدا پیدا نشد.';
    return implode("\n", $lines);
}

function farid_inboundMoveTargetItems($serverId, $sourceInbound = 0){
    $json = function_exists('getJson') ? getJson(intval($serverId)) : null;
    $items = [];
    if(!$json || !isset($json->obj) || !is_array($json->obj)) return $items;
    foreach($json->obj as $row){
        $id = intval($row->id ?? 0);
        if($id <= 0 || $id == intval($sourceInbound)) continue;
        $remark = trim((string)($row->remark ?? ''));
        $proto = trim((string)($row->protocol ?? ''));
        $port = intval($row->port ?? 0);
        $items[] = ['id'=>$id, 'remark'=>$remark, 'protocol'=>$proto, 'port'=>$port];
    }
    return $items;
}

function farid_inboundMoveTargetSelectText($mode, $serverId, $sourceInbound, $orderId = 0){
    $serverTitle = function_exists('v2raystore_switchGetServerTitle') ? v2raystore_switchGetServerTitle($serverId) : (string)$serverId;
    $count = ($mode === 'bulk') ? farid_inboundMoveSourceCount($serverId, $sourceInbound) : 1;
    $extra = '';
    if($mode === 'one'){
        $order = farid_inboundMoveFetchOrder($orderId);
        $remark = htmlspecialchars((string)($order['remark'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $extra = "\n🧾 سفارش: <code>" . intval($orderId) . "</code>\n🔮 ریمارک: <b>$remark</b>";
    }
    return "🎯 <b>انتخاب inbound مقصد</b>\n\n" .
           "🖥 سرور: <b>" . htmlspecialchars((string)$serverTitle, ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📥 inbound مبدا: <code>" . intval($sourceInbound) . "</code>\n" .
           "📦 تعداد انتخاب‌شده: <b>" . intval($count) . "</b>" . $extra . "\n\n" .
           "از دکمه‌های زیر inbound مقصد را انتخاب کن. ربات اول مشخصات کانفیگ را از مبدا می‌خواند، سپس کانفیگ قدیمی را حذف می‌کند و بعد روی مقصد می‌سازد تا خطای نام تکراری پیش نیاید.";
}

function farid_inboundMoveTargetSelectKeys($mode, $serverId, $sourceInbound, $orderId = 0){
    global $buttonValues;
    $serverId = intval($serverId); $sourceInbound = intval($sourceInbound); $orderId = intval($orderId);
    $rows = [];
    $items = farid_inboundMoveTargetItems($serverId, $sourceInbound);
    foreach($items as $it){
        $id = intval($it['id']);
        $remark = trim((string)$it['remark']);
        $proto = trim((string)$it['protocol']);
        $port = intval($it['port']);
        $label = '📤 ' . $id;
        if($proto !== '') $label .= ' | ' . $proto;
        if($port > 0) $label .= ':' . $port;
        if($remark !== ''){
            if(function_exists('mb_substr')) $r = mb_substr($remark, 0, 18, 'UTF-8'); else $r = substr($remark, 0, 18);
            if($r !== $remark) $r .= '…';
            $label .= ' | ' . $r;
        }
        $cb = ($mode === 'one') ? ('inboundMoveTargetOne_' . $orderId . '_' . $id) : ('inboundMoveTargetBulk_' . $serverId . '_' . $sourceInbound . '_' . $id);
        $rows[] = [['text'=>$label, 'callback_data'=>$cb]];
    }
    if(empty($rows)){
        $rows[] = [['text'=>'❌ inbound مقصدی غیر از مبدا پیدا نشد', 'callback_data'=>'v2raystore']];
    }
    $rows[] = [['text'=>'⬅️ برگشت به لیست همین مبدا', 'callback_data'=>'inboundMoveSrc_' . $serverId . '_' . $sourceInbound . '_0']];
    $rows[] = [['text'=>$buttonValues['back_button'] ?? 'برگشت', 'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function farid_inboundMoveSourceText($serverId, $sourceInbound, $page = 0){
    $count = farid_inboundMoveSourceCount($serverId, $sourceInbound);
    $serverTitle = function_exists('v2raystore_switchGetServerTitle') ? v2raystore_switchGetServerTitle($serverId) : (string)$serverId;
    $txt = "🔁 <b>انتقال از inbound مبدا</b>\n\n" .
           "🖥 سرور: <b>" . htmlspecialchars($serverTitle, ENT_QUOTES, 'UTF-8') . "</b>\n" .
           "📥 inbound مبدا: <code>" . intval($sourceInbound) . "</code>\n" .
           "📦 تعداد کانفیگ‌های ثبت‌شده در ربات: <b>$count</b>\n\n" .
           "می‌توانی همه را یکجا صف کنی یا از لیست پایین تکی منتقل کنی.\n\n" .
           farid_inboundMoveTargetListText($serverId, $sourceInbound);
    return $txt;
}

function farid_inboundMoveSourceKeys($serverId, $sourceInbound, $page = 0){
    global $buttonValues;
    $serverId = intval($serverId); $sourceInbound = intval($sourceInbound); $page = max(0, intval($page));
    $rows = [];
    $count = farid_inboundMoveSourceCount($serverId, $sourceInbound);
    if($count > 0){
        $rows[] = [['text'=>'🚚 انتقال همه این inbound', 'callback_data'=>'inboundMoveBulk_' . $serverId . '_' . $sourceInbound]];
    }
    $orders = farid_inboundMoveOrdersPage($serverId, $sourceInbound, $page, 8);
    foreach($orders as $o){
        $remark = trim((string)($o['remark'] ?? 'بدون نام'));
        if(function_exists('mb_substr')) $short = mb_substr($remark, 0, 26, 'UTF-8'); else $short = substr($remark, 0, 26);
        if($short !== $remark) $short .= '…';
        $rows[] = [['text'=>'تکی: ' . $short . ' | #' . intval($o['id']), 'callback_data'=>'inboundMoveOne_' . intval($o['id'])]];
    }
    $maxPage = max(0, (int)ceil($count / 8) - 1);
    if($maxPage > 0){
        $nav = [];
        if($page > 0) $nav[] = ['text'=>'⬅️ قبلی', 'callback_data'=>'inboundMoveSrcOrders_' . $serverId . '_' . $sourceInbound . '_' . ($page - 1)];
        $nav[] = ['text'=>($page + 1) . '/' . ($maxPage + 1), 'callback_data'=>'v2raystore'];
        if($page < $maxPage) $nav[] = ['text'=>'بعدی ➡️', 'callback_data'=>'inboundMoveSrcOrders_' . $serverId . '_' . $sourceInbound . '_' . ($page + 1)];
        $rows[] = $nav;
    }
    $rows[] = [['text'=>'⬅️ برگشت به مبداها', 'callback_data'=>'inboundMoveMenu']];
    $rows[] = [['text'=>$buttonValues['back_button'] ?? 'برگشت', 'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function farid_inboundMoveProgressText($job, $compact = false){
    $job = is_array($job) ? $job : farid_inboundMoveGetJob();
    $ids = $job['ids'] ?? [];
    $total = is_array($ids) ? count($ids) : 0;
    $offset = intval($job['offset'] ?? 0);
    $left = max(0, $total - $offset);
    $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
    $state = intval($job['state'] ?? 0);
    $status = $state == 1 ? (intval($job['auto_running'] ?? 0) == 1 ? 'در حال اجرا 🟢' : 'آماده اجرا/ادامه ⏸') : ($offset >= $total && $total > 0 ? 'تکمیل شد ✅' : 'متوقف ⛔');
    $percent = $total > 0 ? round(($offset * 100) / $total) : 0;
    $title = htmlspecialchars((string)($job['filter_title'] ?? 'تغییر اینباند'), ENT_QUOTES, 'UTF-8');
    $txt = ($compact ? '' : "🔁 <b>عملیات تغییر اینباند</b>\n\n") .
           "📌 وضعیت: <b>$status</b>\n" .
           "🎯 عملیات: <b>$title</b>\n" .
           "📥 مبدا: <code>" . intval($job['source_inbound'] ?? 0) . "</code> ➜ 📤 مقصد: <code>" . intval($job['target_inbound'] ?? 0) . "</code>\n" .
           "📦 کل: $total | انجام‌شده: $offset | باقی: $left | $percent%\n" .
           "✅ منتقل: " . intval($stats['moved'] ?? 0) . " | 📤 ارسال: " . intval($stats['sent'] ?? 0) . " | ⏭ رد: " . intval($stats['skipped'] ?? 0) . " | ❌ خطا: " . intval($stats['failed'] ?? 0);
    if(!$compact){
        $errors = $job['errors'] ?? [];
        if(is_array($errors) && count($errors) > 0){
            $txt .= "\n\n⚠️ آخرین خطاها:";
            foreach(array_slice(array_reverse($errors), 0, 4) as $e){
                $txt .= "\n• " . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8');
            }
        }
        $txt .= "\n\nهر بار فقط چند کانفیگ منتقل می‌شود تا پنل و ربات هنگ نکنند.";
    }
    return $txt;
}

function farid_inboundMoveProgressKeys($job){
    global $buttonValues;
    $state = intval($job['state'] ?? 0);
    $rows = [];
    if($state == 1){
        $rows[] = [['text'=>'🚀 شروع / ادامه انتقال', 'callback_data'=>'inboundMoveRun'], ['text'=>'📊 بروزرسانی', 'callback_data'=>'inboundMoveStatus']];
        $rows[] = [['text'=>'⛔ توقف انتقال', 'callback_data'=>'inboundMoveStop']];
    }else{
        $rows[] = [['text'=>'🔁 عملیات جدید', 'callback_data'=>'inboundMoveMenu']];
    }
    $rows[] = [['text'=>$buttonValues['back_button'] ?? 'برگشت', 'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function farid_inboundMoveEditProgress($job = null, $force = false){
    $job = is_array($job) ? $job : farid_inboundMoveGetJob();
    $chatId = intval($job['status_chat_id'] ?? 0);
    $msgId = intval($job['status_message_id'] ?? 0);
    if($chatId <= 0 || $msgId <= 0) return false;
    if(function_exists('bot')){
        bot('editMessageText', [
            'chat_id'=>$chatId,
            'message_id'=>$msgId,
            'text'=>farid_inboundMoveProgressText($job),
            'parse_mode'=>'HTML',
            'reply_markup'=>farid_inboundMoveProgressKeys($job)
        ]);
    }
    return true;
}

function farid_inboundMoveNormalizeClientArray($client){
    if(is_array($client)) return $client;
    if(is_object($client)) return json_decode(json_encode($client), true) ?: [];
    return [];
}

function farid_inboundMoveOneOrder($orderId, $targetInboundId, $actorId = 0){
    global $connection;
    $order = farid_inboundMoveFetchOrder($orderId);
    if(!$order) return ['ok'=>false, 'message'=>'سفارش پیدا نشد.'];
    if(intval($order['status'] ?? 0) != 1) return ['ok'=>false, 'message'=>'سفارش فعال نیست.', 'skip'=>true];

    $serverId = intval($order['server_id'] ?? 0);
    $sourceInboundId = intval($order['inbound_id'] ?? 0);
    $targetInboundId = intval($targetInboundId);
    if($serverId <= 0 || $sourceInboundId <= 0 || $targetInboundId <= 0) return ['ok'=>false, 'message'=>'سرور یا اینباند نامعتبر است.'];
    if($sourceInboundId == $targetInboundId) return ['ok'=>false, 'message'=>'مبدا و مقصد یکی است.', 'skip'=>true];

    $serverConfig = function_exists('farid_switchGetServerConfig') ? farid_switchGetServerConfig($serverId) : null;
    if(!$serverConfig) return ['ok'=>false, 'message'=>'تنظیمات سرور پیدا نشد.'];
    if(function_exists('farid_switchIsMarzbanType') && farid_switchIsMarzbanType($serverConfig['type'] ?? '')) return ['ok'=>false, 'message'=>'تغییر اینباند برای مرزبان کاربرد ندارد.', 'skip'=>true];

    $oldUuid = trim((string)($order['uuid'] ?? ''));
    $oldRemark = trim((string)($order['remark'] ?? ''));
    if($oldUuid === '' || $oldRemark === '') return ['ok'=>false, 'message'=>'UUID یا ریمارک سفارش ناقص است.'];

    $usage = function_exists('v2raystore_orderPanelUsage') ? v2raystore_orderPanelUsage($order) : null;
    $live = function_exists('farid_switchGetOrderLiveState') ? farid_switchGetOrderLiveState($order) : null;
    if(!is_array($usage) || empty($usage['found']) || !is_array($live) || empty($live['ok'])){
        return ['ok'=>false, 'message'=>'کانفیگ روی inbound مبدا پیدا نشد یا پنل پاسخ نداد.'];
    }

    $remainingBytes = isset($usage['remaining']) ? intval($usage['remaining']) : intval($live['remaining_bytes'] ?? 0);
    if($remainingBytes < 0) $remainingBytes = 0;
    $expireSeconds = intval($usage['expiryTime'] ?? ($live['expire_seconds'] ?? 0));
    $expireMillis = $expireSeconds > 0 ? ($expireSeconds * 1000) : intval($live['expire_millis'] ?? 0);
    $limitIp = intval($live['limitIp'] ?? 0);
    $flow = trim((string)($live['flow'] ?? ''));

    $targetInbound = function_exists('farid_switchFindXuiInboundInfo') ? farid_switchFindXuiInboundInfo($serverId, $targetInboundId, '') : null;
    if(!$targetInbound) return ['ok'=>false, 'message'=>'inbound مقصد در پنل پیدا نشد.'];
    $protocol = trim((string)($targetInbound['protocol'] ?? ($order['protocol'] ?? '')));
    if($protocol === '') $protocol = trim((string)($order['protocol'] ?? 'vless'));
    $idLabel = ($protocol === 'trojan') ? 'password' : 'id';

    $newUuid = function_exists('generateRandomString') ? generateRandomString(42, $protocol) : (function_exists('RandomString') ? RandomString(32) : md5(uniqid('', true)));
    $newRemark = $oldRemark;
    $subId = function_exists('RandomString') ? RandomString(16) : substr(md5(uniqid('', true)), 0, 16);
    $client = [
        $idLabel => $newUuid,
        'email' => $newRemark,
        'enable' => true,
        'limitIp' => $limitIp,
        'totalGB' => $remainingBytes,
        'expiryTime' => $expireMillis,
        'subId' => $subId,
    ];
    if($flow !== '') $client['flow'] = $flow;

    // قبل از حذف کانفیگ قدیمی، لینک مقصد را هم تست می‌کنیم تا اگر تنظیمات مقصد مشکل داشت، سرویس قبلی دست نخورده بماند.
    $plan = function_exists('v2raystore_switchGetPlan') ? v2raystore_switchGetPlan(intval($order['fileid'] ?? 0)) : null;
    $custom = function_exists('farid_switchPlanCustomFields') ? farid_switchPlanCustomFields($plan) : ['customPath'=>null,'customPort'=>0,'customSni'=>null,'customDomain'=>null];
    $preLinks = function_exists('farid_switchBuildInboundLinks') ? farid_switchBuildInboundLinks($serverId, $newUuid, $protocol, $newRemark, $targetInbound, $targetInboundId, $custom) : getConnectionLink($serverId, $newUuid, $protocol, $newRemark, intval($targetInbound['port'] ?? 0), (string)($targetInbound['netType'] ?? ''), $targetInboundId, false, $custom['customPath'] ?? null, $custom['customPort'] ?? 0, $custom['customSni'] ?? null, $custom['customDomain'] ?? null);
    $preLinks = function_exists('v2raystore_normalizeConfigLinksArray') ? v2raystore_normalizeConfigLinksArray($preLinks) : (is_array($preLinks) ? $preLinks : [$preLinks]);
    $preLinks = array_values(array_filter(array_map('strval', $preLinks)));
    if(empty($preLinks)) return ['ok'=>false, 'message'=>'قبل از حذف کانفیگ قبلی، لینک مقصد ساخته نشد؛ انتقال لغو شد.'];

    // سنایی در نسخه‌های جدید email را روی کل پنل یکتا می‌گیرد؛ برای جلوگیری از خطای
    // email already in use، بعد از خواندن کامل حجم/زمان از مبدا، اول کلاینت قدیمی حذف می‌شود.
    $deleteOldBefore = function_exists('deleteClient') ? deleteClient($serverId, $sourceInboundId, $oldUuid, 1) : null;
    if($deleteOldBefore === null){
        return ['ok'=>false, 'message'=>'تابع حذف کلاینت در دسترس نیست؛ انتقال انجام نشد.'];
    }
    usleep(250000);

    $response = addInboundAccount($serverId, '', $targetInboundId, 1, $newRemark, 0, $limitIp, $client, intval($order['fileid'] ?? 0));
    $msg = is_object($response) ? (string)($response->msg ?? '') : (string)$response;
    if((!is_object($response) || empty($response->success)) && (stripos($msg, 'email already in use') !== false || stripos($msg, 'duplicate') !== false)){
        // یک بار دیگر حذف بر اساس UUID قبلی را تکرار می‌کنیم؛ بعضی پنل‌ها با کمی تاخیر cache را آزاد می‌کنند.
        if(function_exists('deleteClient')) deleteClient($serverId, $sourceInboundId, $oldUuid, 1);
        usleep(600000);
        $response = addInboundAccount($serverId, '', $targetInboundId, 1, $newRemark, 0, $limitIp, $client, intval($order['fileid'] ?? 0));
        $msg = is_object($response) ? (string)($response->msg ?? '') : (string)$response;
    }
    if($response === null || $response === 'inbound not Found' || !is_object($response) || empty($response->success)){
        // اگر ساخت مقصد شکست خورد، برای خراب نشدن سرویس، تلاش می‌کنیم کانفیگ قبلی را همان inbound مبدا برگردانیم.
        if(function_exists('addInboundAccount')){
            $rollbackClient = $client;
            $rollbackClient[$idLabel] = $oldUuid;
            $rollbackClient['email'] = $oldRemark;
            if(isset($rollbackClient['subId'])) $rollbackClient['subId'] = trim((string)($order['token'] ?? $subId));
            @addInboundAccount($serverId, '', $sourceInboundId, 1, $oldRemark, 0, $limitIp, $rollbackClient, intval($order['fileid'] ?? 0));
        }
        if($response === 'inbound not Found') return ['ok'=>false, 'message'=>'inbound مقصد پیدا نشد. کانفیگ قبلی تلاش شد برگردد.'];
        return ['ok'=>false, 'message'=>'ساخت کلاینت مقصد ناموفق بود: ' . ($msg !== '' ? $msg : 'پاسخ نامعتبر پنل') . ' | کانفیگ قبلی تلاش شد برگردد.'];
    }

    if(($serverConfig['type'] ?? '') === 'sanaei_new' && function_exists('farid_switchClientExistsInInbound') && !farid_switchClientExistsInInbound($serverId, $targetInboundId, $newUuid, $newRemark)){
        if(function_exists('farid_switchSanaeiNewAttachClientToInbound')) farid_switchSanaeiNewAttachClientToInbound($serverId, $targetInboundId, $client);
        if(!farid_switchClientExistsInInbound($serverId, $targetInboundId, $newUuid, $newRemark)){
            return ['ok'=>false, 'message'=>'کلاینت ساخته شد ولی به inbound مقصد وصل نشد.'];
        }
    }

    $targetInboundInfo = farid_switchFindXuiInboundInfo($serverId, $targetInboundId, $newUuid) ?: $targetInbound;
    $links = function_exists('farid_switchBuildInboundLinks') ? farid_switchBuildInboundLinks($serverId, $newUuid, $protocol, $newRemark, $targetInboundInfo, $targetInboundId, $custom) : getConnectionLink($serverId, $newUuid, $protocol, $newRemark, intval($targetInboundInfo['port'] ?? 0), (string)($targetInboundInfo['netType'] ?? ''), $targetInboundId, false, $custom['customPath'] ?? null, $custom['customPort'] ?? 0, $custom['customSni'] ?? null, $custom['customDomain'] ?? null);
    $links = function_exists('v2raystore_normalizeConfigLinksArray') ? v2raystore_normalizeConfigLinksArray($links) : (is_array($links) ? $links : [$links]);
    $links = array_values(array_filter(array_map('strval', $links)));
    if(empty($links)) $links = $preLinks;

    $deleteOld = $deleteOldBefore;

    $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = @$connection->prepare("UPDATE `orders_list` SET `inbound_id`=?, `uuid`=?, `token`=?, `protocol`=?, `link`=?, `remark`=?, `notif`=0 WHERE `id`=?");
    if(!$stmt) return ['ok'=>false, 'message'=>'ذخیره اطلاعات جدید در دیتابیس ناموفق بود.'];
    $stmt->bind_param('isssssi', $targetInboundId, $newUuid, $subId, $protocol, $linkJson, $newRemark, $orderId);
    $ok = $stmt->execute();
    $stmt->close();
    if(!$ok) return ['ok'=>false, 'message'=>'آپدیت دیتابیس انجام نشد.'];

    $sent = false;
    $ownerId = intval($order['userid'] ?? 0);
    if($ownerId > 0 && function_exists('farid_sendUpdatedConfigToUser')){
        farid_sendUpdatedConfigToUser($ownerId, $newRemark, $links, null, '🔄 کانفیگ جدید شما آماده شد');
        $sent = true;
    }

    return [
        'ok'=>true,
        'message'=>'منتقل شد.',
        'sent'=>$sent,
        'order_id'=>$orderId,
        'old_remark'=>$oldRemark,
        'new_remark'=>$newRemark,
        'old_uuid'=>$oldUuid,
        'new_uuid'=>$newUuid,
        'source_inbound'=>$sourceInboundId,
        'target_inbound'=>$targetInboundId,
        'remaining_gb'=>round($remainingBytes / 1073741824, 2),
        'delete_old'=>$deleteOld,
    ];
}

function farid_inboundMoveRunBatch($job = null, $batch = 3){
    $job = is_array($job) ? $job : farid_inboundMoveGetJob();
    if(intval($job['state'] ?? 0) != 1) return ['processed'=>0, 'done'=>true];
    $ids = is_array($job['ids'] ?? null) ? array_values($job['ids']) : [];
    $total = count($ids);
    $offset = max(0, intval($job['offset'] ?? 0));
    $batch = max(1, min(12, intval($batch)));
    $stats = is_array($job['stats'] ?? null) ? $job['stats'] : ['processed'=>0,'moved'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    $errors = is_array($job['errors'] ?? null) ? $job['errors'] : [];
    $processed = 0;
    for($i=0; $i<$batch && ($offset + $i) < $total; $i++){
        $orderId = intval($ids[$offset + $i]);
        $res = farid_inboundMoveOneOrder($orderId, intval($job['target_inbound'] ?? 0), intval($job['requested_by'] ?? 0));
        $processed++;
        $stats['processed'] = intval($stats['processed'] ?? 0) + 1;
        if(!empty($res['ok'])){
            $stats['moved'] = intval($stats['moved'] ?? 0) + 1;
            if(!empty($res['sent'])) $stats['sent'] = intval($stats['sent'] ?? 0) + 1;
        }else{
            if(!empty($res['skip'])) $stats['skipped'] = intval($stats['skipped'] ?? 0) + 1;
            else $stats['failed'] = intval($stats['failed'] ?? 0) + 1;
            $errors[] = '#' . $orderId . ': ' . (string)($res['message'] ?? 'خطای نامشخص');
            if(count($errors) > 20) $errors = array_slice($errors, -20);
        }
    }
    $job['offset'] = $offset + $processed;
    $job['stats'] = $stats;
    $job['errors'] = $errors;
    if($job['offset'] >= $total) $job['state'] = 0;
    farid_inboundMoveSetJob($job);
    return ['processed'=>$processed, 'done'=>intval($job['state']) != 1, 'job'=>$job];
}

function v2raystore_adminSectionBackRow(){
    return [
        ['text'=>'⬅️ بازگشت', 'callback_data'=>'adminMainMenu']
    ];
}

function getAdminKeysPlus(){
    global $buttonValues;

    // منوی اصلی مدیریت فقط دسته‌ها را نشان می‌دهد تا شلوغی کم شود.
    $keys = [];
    $keys[] = [
        ['text'=>'📊 گزارش‌ها و جستجو', 'callback_data'=>'adminReportsMenu'],
        ['text'=>'🧾 کانفیگ‌ها و سرویس‌ها', 'callback_data'=>'adminConfigsMenu']
    ];
    $keys[] = [
        ['text'=>'🖥 سرورها، پلن‌ها و فروش', 'callback_data'=>'adminSalesMenu'],
        ['text'=>'👥 کاربران و نماینده‌ها', 'callback_data'=>'adminUsersMenu']
    ];
    $keys[] = [
        ['text'=>'📨 پیام‌ها و پشتیبانی', 'callback_data'=>'adminMessagesMenu'],
        ['text'=>'⚙️ تنظیمات و ظاهر ربات', 'callback_data'=>'adminSettingsMenu']
    ];
    $keys[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'mainMenu']];

    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminReportsMenuKeys(){
    global $buttonValues;
    $keys = [];
    $keys[] = [
        ['text'=>$buttonValues['bot_reports'] ?? 'آمار کلی ربات', 'callback_data'=>'botReports'],
        ['text'=>$buttonValues['user_reports'] ?? 'گزارش کاربران', 'callback_data'=>'userReports']
    ];
    $keys[] = [
        ['text'=>$buttonValues['search_admin_config'] ?? 'جستجوی کانفیگ', 'callback_data'=>'searchUsersConfig'],
        ['text'=>$buttonValues['message_to_user'] ?? 'پیام به کاربر', 'callback_data'=>'messageToSpeceficUser']
    ];
    $keys[] = [
        ['text'=>'📊 تنظیمات آمار کانال', 'callback_data'=>'reportChannelSettingsMenu']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminConfigsMenuKeys(){
    global $buttonValues;
    $keys = [];
    $keys[] = [
        ['text'=>'➕ افزودن کانفیگ به کاربر', 'callback_data'=>'manualAttachConfig', 'style'=>'success'],
        ['text'=>'♻️ مدیریت آپدیت کانفیگ‌ها', 'callback_data'=>'updateConfigsMenu']
    ];
    $keys[] = [
        ['text'=>$buttonValues['create_account'] ?? 'ساخت اکانت', 'callback_data'=>'createMultipleAccounts'],
        ['text'=>'🗑 پاکسازی کانفیگ‌های تمام‌شده', 'callback_data'=>'cleanOldConfigsMenu']
    ];
    $keys[] = [
        ['text'=>'🔁 تغییر اینباند کانفیگ‌ها', 'callback_data'=>'inboundMoveMenu'],
        ['text'=>'🧪 مدیریت اکانت تست', 'callback_data'=>'testAccountManagement']
    ];
    $keys[] = [
        ['text'=>'⏱ تأیید خودکار سفارش', 'callback_data'=>'autoApproveOrdersMenu']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminSalesMenuKeys(){
    global $buttonValues;
    $keys = [];
    $keys[] = [
        ['text'=>$buttonValues['server_settings'] ?? 'تنظیمات سرورها', 'callback_data'=>'serversSetting'],
        ['text'=>$buttonValues['categories_settings'] ?? 'دسته‌بندی‌ها', 'callback_data'=>'categoriesSetting']
    ];
    $keys[] = [
        ['text'=>$buttonValues['plan_settings'] ?? 'پلن‌ها', 'callback_data'=>'backplan'],
        ['text'=>$buttonValues['discount_settings'] ?? 'کد تخفیف', 'callback_data'=>'discount_codes']
    ];
    $keys[] = [
        ['text'=>$buttonValues['gateways_settings'] ?? 'درگاه‌ها و کانال‌ها', 'callback_data'=>'gateWays_Channels'],
        ['text'=>'💳 کارت‌به‌کارت حرفه‌ای', 'callback_data'=>'proC2CMenu']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminUsersMenuKeys(){
    global $buttonValues, $from_id, $admin;
    $keys = [];
    $keys[] = [
        ['text'=>'🔐 قفل و دسترسی اعضای جدید', 'callback_data'=>'newMemberAccessMenu'],
        ['text'=>'🚪 معافیت جوین اجباری', 'callback_data'=>'joinExemptMenu']
    ];
    $keys[] = [
        ['text'=>'🚪 پیام ترک کانال', 'callback_data'=>'proLeaveNoticeMenu'],
        ['text'=>'👥 زیرمجموعه‌های کاربر', 'callback_data'=>'proReferralAsk']
    ];
    if($from_id == $admin){
        $keys[] = [['text'=>$buttonValues['admins_list'] ?? 'فهرست مدیران', 'callback_data'=>'adminsList']];
    }
    $keys[] = [
        ['text'=>$buttonValues['increase_wallet'] ?? 'افزایش موجودی', 'callback_data'=>'increaseUserWallet'],
        ['text'=>$buttonValues['decrease_wallet'] ?? 'کاهش موجودی', 'callback_data'=>'decreaseUserWallet']
    ];
    $keys[] = [
        ['text'=>$buttonValues['ban_user'] ?? 'مسدود کردن کاربر', 'callback_data'=>'banUser'],
        ['text'=>$buttonValues['unban_user'] ?? 'رفع مسدودی کاربر', 'callback_data'=>'unbanUser']
    ];
    $keys[] = [
        ['text'=>$buttonValues['agent_list'] ?? 'مدیریت نمایندگان', 'callback_data'=>'agentsList'],
        ['text'=>'درخواست‌های رد شده', 'callback_data'=>'rejectedAgentList']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminMessagesMenuKeys(){
    global $buttonValues;
    $keys = [];
    $keys[] = [
        ['text'=>$buttonValues['tickets_list'] ?? 'تیکت‌ها', 'callback_data'=>'ticketsList'],
        ['text'=>$buttonValues['message_to_all'] ?? 'ارسال پیام عمومی', 'callback_data'=>'message2All']
    ];
    $keys[] = [
        ['text'=>$buttonValues['forward_to_all'] ?? 'فوروارد پیام عمومی', 'callback_data'=>'forwardToAll'],
        ['text'=>'📊 وضعیت صف همگانی', 'callback_data'=>'broadcastQueueStatus']
    ];
    $keys[] = [
        ['text'=>'📩 پیام اتمام/نزدیک اتمام', 'callback_data'=>'xuiMsgMenu'],
        ['text'=>'📌 پیام‌های پین‌شده', 'callback_data'=>'broadcastPinsMenu']
    ];
    $keys[] = [
        ['text'=>'📌 پین دستی متن/تصویر/فایل', 'callback_data'=>'proPinMenu'],
        ['text'=>'🛠 متن خطایابی کانفیگ', 'callback_data'=>'editDiagAdminText']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function getAdminSettingsMenuKeys(){
    global $buttonValues;
    $keys = [];
    $keys[] = [
        ['text'=>$buttonValues['bot_settings'] ?? 'تنظیمات ربات', 'callback_data'=>'botSettings'],
        ['text'=>$buttonValues['main_button_settings'] ?? 'مدیریت دکمه‌های اصلی', 'callback_data'=>'mainMenuButtons']
    ];
    $keys[] = [
        ['text'=>'🎛 تنظیمات دکمه‌های کاربر', 'callback_data'=>'userButtonSettings'],
        ['text'=>'📝 متن خوش‌آمد / قوانین خرید', 'callback_data'=>'adminTextSettings']
    ];
    $keys[] = [
        ['text'=>'📚 مدیریت FAQ و آموزش‌ها', 'callback_data'=>'adminHelpMenu']
    ];
    $keys[] = v2raystore_adminSectionBackRow();
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function farid_attachUpdateConfigButton($keyboardJson, $orderId){
    if(empty($keyboardJson)) return $keyboardJson;

    $data = json_decode($keyboardJson, true);
    if(!is_array($data) || !isset($data['inline_keyboard'])) return $keyboardJson;

    $orderId = intval($orderId);
    if(function_exists('farid_orderIdHasSubDelivery') && farid_orderIdHasSubDelivery($orderId)) return $keyboardJson;
    $cb = "updateConfigConnectionLink" . $orderId;

    foreach($data['inline_keyboard'] as $row){
        foreach($row as $btn){
            if(isset($btn['callback_data']) && $btn['callback_data'] == $cb){
                return $keyboardJson;
            }
        }
    }

    $newRow = [
        ['text' => "♻️ به‌روزرسانی کانفیگ", 'callback_data' => $cb, 'style' => 'primary']
    ];

    // تلاش برای قرار دادن دکمه قبل از برگشت به منو
    $inserted = false;
    for($i=0; $i<count($data['inline_keyboard']); $i++){
        foreach($data['inline_keyboard'][$i] as $btn){
            if(isset($btn['callback_data']) && ($btn['callback_data'] == "mainMenu" || $btn['callback_data'] == "mySubscriptions")){
                array_splice($data['inline_keyboard'], $i, 0, [$newRow]);
                $inserted = true;
                break 2;
            }
        }
    }
    if(!$inserted){
        $data['inline_keyboard'][] = $newRow;
    }

    return json_encode($data, JSON_UNESCAPED_UNICODE);
}


// ✅ دکمه «به‌روزرسانی همه کانفیگ‌ها» برای کاربر
function farid_attachUpdateAllMyConfigsButton($keyboardJson){
    if(empty($keyboardJson)) return $keyboardJson;

    $data = json_decode($keyboardJson, true);
    if(!is_array($data) || !isset($data['inline_keyboard'])) return $keyboardJson;

    $cb = "updateAllMyConfigs_0";

    // جلوگیری از تکراری شدن
    foreach($data['inline_keyboard'] as $row){
        foreach($row as $btn){
            if(isset($btn['callback_data']) && $btn['callback_data'] == $cb){
                return $keyboardJson;
            }
        }
    }

    // قبل از دکمه‌های اصلی (مثل mainMenu) اضافه شود
    $insertIndex = count($data['inline_keyboard']);
    foreach($data['inline_keyboard'] as $i => $row){
        foreach($row as $btn){
            if(isset($btn['callback_data']) && in_array($btn['callback_data'], ["mainMenu","mySubscriptions","managePanel"])){
                $insertIndex = $i;
                break 2;
            }
        }
    }

    array_splice($data['inline_keyboard'], $insertIndex, 0, [[
        ['text' => "♻️ به‌روزرسانی همه کانفیگ‌ها", 'callback_data' => $cb, 'style' => 'primary']
    ]]);

    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

// ✅ دکمه «به‌روزرسانی همه کانفیگ‌های کاربر» برای ادمین
function farid_attachUpdateAllUserConfigsButton($keyboardJson, $userId){
    if(empty($keyboardJson)) return $keyboardJson;

    $data = json_decode($keyboardJson, true);
    if(!is_array($data) || !isset($data['inline_keyboard'])) return $keyboardJson;

    $userId = intval($userId);
    if($userId <= 0) return $keyboardJson;

    $cb = "updateAllUserConfigs" . $userId . "_0";

    // جلوگیری از تکراری شدن
    foreach($data['inline_keyboard'] as $row){
        foreach($row as $btn){
            if(isset($btn['callback_data']) && $btn['callback_data'] == $cb){
                return $keyboardJson;
            }
        }
    }

    $insertIndex = count($data['inline_keyboard']);
    foreach($data['inline_keyboard'] as $i => $row){
        foreach($row as $btn){
            if(isset($btn['callback_data']) && in_array($btn['callback_data'], ["mainMenu","mySubscriptions","managePanel","updateConfigsMenu"])){
                $insertIndex = $i;
                break 2;
            }
        }
    }

    array_splice($data['inline_keyboard'], $insertIndex, 0, [[
        ['text' => "♻️ به‌روزرسانی همه کانفیگ‌های کاربر", 'callback_data' => $cb, 'style' => 'primary']
    ]]);

    return json_encode($data, JSON_UNESCAPED_UNICODE);
}


function farid_getSettingValue($type){
    global $connection;
    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res && $res->num_rows > 0){
        return $res->fetch_assoc()['value'];
    }
    return null;
}

function farid_setSettingValue($type, $value){
    global $connection;

    $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `setting` WHERE `type` = ? LIMIT 1");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if($cnt > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = ?");
        $stmt->bind_param("ss", $value, $type);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, ?)");
        $stmt->bind_param("ss", $value, $type);
        $stmt->execute();
        $stmt->close();
    }
}



// ===========================
// پیام پس از به‌روزرسانی کانفیگ
// ===========================
function farid_defaultUpdateAfterMessage(){
    return "✅ کانفیگ شما به‌روزرسانی شد. لطفاً از این پس از این کانفیگ استفاده کنید.";
}

function farid_getUpdateAfterMessage(){
    $raw = farid_getSettingValue("UPDATE_CONFIGS_AFTER_MESSAGE");
    if($raw === null){
        // اگر تنظیم نشده، پیش‌فرض باشد
        return farid_defaultUpdateAfterMessage();
    }
    // اگر خالی باشد => یعنی خاموش
    return strval($raw);
}

function farid_setUpdateAfterMessage($msg){
    farid_setSettingValue("UPDATE_CONFIGS_AFTER_MESSAGE", strval($msg));
}


// ===========================
// Sync candidates before manual/auto cleanup
// ===========================
function farid_syncOldConfigCandidatesBeforeClean($days, $basis, $max = 300){
    global $connection;
    if(!function_exists('v2raystore_syncOrderExpiryFromPanel')) return 0;

    $days = intval($days);
    if($days <= 0) $days = 10;
    $basis = ($basis == "date") ? "date" : "expire_date";
    $max = intval($max);
    if($max < 1) $max = 300;
    if($max > 1000) $max = 1000;

    $now = time();
    $threshold = $now - ($days * 86400);

    if($basis == "date"){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `expire_date` < ? AND `date` < ? ORDER BY `id` ASC LIMIT ?");
        if(!$stmt) return 0;
        $stmt->bind_param("iii", $now, $threshold, $max);
    }else{
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `expire_date` < ? ORDER BY `id` ASC LIMIT ?");
        if(!$stmt) return 0;
        $stmt->bind_param("ii", $threshold, $max);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $synced = 0;
    while($order = $res->fetch_assoc()){
        $info = v2raystore_syncOrderExpiryFromPanel($order, true);
        if(is_array($info) && !empty($info['found'])) $synced++;
    }
    return $synced;
}





// ===========================
// افزودن دستی / جستجوی کانفیگ با لینک یا ساب
// ===========================
function farid_bindParamsDynamic($stmt, $types, $values){
    if($types === '') return true;
    $refs = [];
    foreach($values as $k => $v){
        $refs[$k] = $values[$k];
    }
    $bind = [$types];
    foreach($refs as $k => $v){
        $bind[] = &$refs[$k];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function farid_manualValue($obj, $key, $default = null){
    if(is_array($obj) && array_key_exists($key, $obj)) return $obj[$key];
    if(is_object($obj) && isset($obj->$key)) return $obj->$key;
    return $default;
}

function farid_manualDecodeJson($value){
    if(is_array($value)) return $value;
    if(is_object($value)) return json_decode(json_encode($value), true);
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function farid_extractSubIdFromInput($input){
    $input = trim((string)$input);
    if($input === '') return '';

    if(preg_match('#/(?:sub|json|clash)/([^/?#\s]+)#i', $input, $m)){
        return trim($m[1]);
    }

    $u = @parse_url($input);
    if(is_array($u) && !empty($u['path'])){
        $parts = array_values(array_filter(explode('/', trim($u['path'], '/'))));
        if(count($parts) > 0){
            $last = end($parts);
            if(preg_match('/^[A-Za-z0-9_-]{6,}$/', $last)) return $last;
        }
    }
    return '';
}

function farid_subUrlMatchesServer($input, $serverId){
    $input = trim((string)$input);
    if($input === '' || strpos($input, '://') === false) return false;
    if(!function_exists('v2raystore_getPanelSubscriptionUris')) return false;

    $iu = @parse_url($input);
    if(!is_array($iu) || empty($iu['host'])) return false;
    $inputHost = strtolower($iu['host']);
    $inputPort = intval($iu['port'] ?? (($iu['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $inputPath = '/' . ltrim($iu['path'] ?? '/', '/');

    $uris = v2raystore_getPanelSubscriptionUris($serverId);
    foreach(['subURI','subJsonURI'] as $key){
        if(empty($uris[$key])) continue;
        $pu = @parse_url($uris[$key]);
        if(!is_array($pu) || empty($pu['host'])) continue;
        $panelHost = strtolower($pu['host']);
        $panelPort = intval($pu['port'] ?? (($pu['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $panelPath = '/' . trim($pu['path'] ?? '/', '/');
        if(substr($panelPath, -1) !== '/') $panelPath .= '/';
        if($inputHost === $panelHost && $inputPort === $panelPort && stripos($inputPath, $panelPath) === 0) return true;
    }
    return false;
}

function farid_base64LooseDecode($data){
    $data = trim((string)$data);
    if($data === '') return false;
    $data = str_replace(['-', '_'], ['+', '/'], $data);
    $pad = strlen($data) % 4;
    if($pad > 0) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}

function farid_parseConfigOrSubLink($link){
    $link = trim((string)$link);
    $res = [
        'is_sub' => false,
        'sub_id' => '',
        'protocol' => '',
        'uuid' => '',
        'host' => '',
        'port' => 0,
        'net_type' => '',
        'remark' => '',
    ];
    if($link === '') return $res;

    $subId = farid_extractSubIdFromInput($link);
    if($subId !== '' && preg_match('#^https?://#i', $link)){
        $res['is_sub'] = true;
        $res['sub_id'] = $subId;
        return $res;
    }

    if(stripos($link, 'vmess://') === 0){
        $decoded = farid_base64LooseDecode(substr($link, 8));
        $j = $decoded !== false ? json_decode($decoded, true) : null;
        if(is_array($j)){
            $res['protocol'] = 'vmess';
            $res['uuid'] = (string)($j['id'] ?? '');
            $res['host'] = (string)($j['add'] ?? '');
            $res['port'] = intval($j['port'] ?? 0);
            $res['net_type'] = (string)($j['net'] ?? ($j['type'] ?? ''));
            $res['remark'] = (string)($j['ps'] ?? '');
        }
        return $res;
    }

    if(preg_match('#^(vless|trojan|ss)://#i', $link, $m)){
        $protocol = strtolower($m[1]);
        $u = @parse_url($link);
        $res['protocol'] = $protocol;
        if(is_array($u)){
            $res['host'] = (string)($u['host'] ?? '');
            $res['port'] = intval($u['port'] ?? 0);
            $res['remark'] = isset($u['fragment']) ? urldecode((string)$u['fragment']) : '';
            if($protocol === 'vless' || $protocol === 'trojan'){
                $res['uuid'] = urldecode((string)($u['user'] ?? ''));
            }elseif($protocol === 'ss'){
                $userinfo = urldecode((string)($u['user'] ?? ''));
                $res['uuid'] = $userinfo;
                if($res['uuid'] === '' && isset($u['path'])){
                    $raw = trim((string)$u['path'], '/');
                    $decoded = farid_base64LooseDecode($raw);
                    $res['uuid'] = $decoded !== false ? $decoded : $raw;
                }
            }
            if(!empty($u['query'])){
                $params = [];
                parse_str($u['query'], $params);
                $res['net_type'] = (string)($params['type'] ?? $params['security'] ?? '');
                if(!empty($params['sni']) && $res['host'] === '') $res['host'] = (string)$params['sni'];
            }
        }
        return $res;
    }

    return $res;
}

function farid_manualClientIdentity($client){
    $id = farid_manualValue($client, 'id', '');
    if($id === '') $id = farid_manualValue($client, 'uuid', '');
    if($id === '') $id = farid_manualValue($client, 'password', '');
    return (string)$id;
}

function farid_manualClientSubId($client){
    return trim((string)farid_manualValue($client, 'subId', ''));
}

function farid_manualFindClientStat($stats, $email){
    if(!is_array($stats)) return null;
    foreach($stats as $st){
        if((string)farid_manualValue($st, 'email', '') === (string)$email) return $st;
    }
    return null;
}

function farid_manualExpiryToSeconds($value){
    $v = intval($value);
    if($v <= 0) return 0;
    if($v > 9999999999) $v = intval($v / 1000);
    return $v;
}

function farid_findPanelAccountByInput($input){
    global $connection;
    $parsed = farid_parseConfigOrSubLink($input);
    $targetSubId = $parsed['sub_id'];
    $targetUuid = $parsed['uuid'];
    $targetPort = intval($parsed['port']);

    if($targetSubId === '' && $targetUuid === ''){
        return [false, 'لینک یا ساب‌آیدی قابل تشخیص نیست.'];
    }

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `type` != 'marzban' ORDER BY `id` ASC");
    $stmt->execute();
    $serversRes = $stmt->get_result();
    $stmt->close();

    $servers = [];
    while($srv = $serversRes->fetch_assoc()) $servers[] = $srv;

    // اگر لینک ساب، سروری که subURI/subPort آن با لینک ورودی می‌خواند اول بررسی شود.
    if($targetSubId !== '' && $parsed['is_sub']){
        usort($servers, function($a, $b) use ($input){
            $am = farid_subUrlMatchesServer($input, intval($a['id'])) ? 0 : 1;
            $bm = farid_subUrlMatchesServer($input, intval($b['id'])) ? 0 : 1;
            return $am <=> $bm;
        });
    }

    foreach($servers as $srv){
        $serverId = intval($srv['id']);
        $json = getJson($serverId);
        if(!$json || !isset($json->success) || !$json->success || !isset($json->obj)) continue;
        $rows = is_array($json->obj) ? $json->obj : [$json->obj];

        foreach($rows as $row){
            if(!is_object($row) && !is_array($row)) continue;
            $port = intval(farid_manualValue($row, 'port', 0));
            if($targetPort > 0 && $port > 0 && $targetPort !== $port) continue;

            $settings = farid_manualDecodeJson(farid_manualValue($row, 'settings', '{}'));
            $clients = $settings['clients'] ?? [];
            if(!is_array($clients)) continue;

            $stream = farid_manualDecodeJson(farid_manualValue($row, 'streamSettings', '{}'));
            $stats = farid_manualValue($row, 'clientStats', []);
            if(is_object($stats)) $stats = [$stats];

            foreach($clients as $client){
                $clientId = farid_manualClientIdentity($client);
                $subId = farid_manualClientSubId($client);
                $email = (string)farid_manualValue($client, 'email', '');
                $match = false;
                if($targetSubId !== '' && $subId !== '' && $subId === $targetSubId) $match = true;
                if(!$match && $targetUuid !== '' && $clientId !== '' && $clientId === $targetUuid) $match = true;
                if(!$match) continue;

                $stat = farid_manualFindClientStat($stats, $email);
                $expiry = farid_manualExpiryToSeconds(farid_manualValue($client, 'expiryTime', 0));
                if($stat !== null){
                    $statExp = farid_manualExpiryToSeconds(farid_manualValue($stat, 'expiryTime', 0));
                    if($statExp > 0) $expiry = $statExp;
                }
                if($expiry == 0){
                    $rowExp = farid_manualExpiryToSeconds(farid_manualValue($row, 'expiryTime', 0));
                    if($rowExp > 0) $expiry = $rowExp;
                }

                $protocol = strtolower((string)farid_manualValue($row, 'protocol', $parsed['protocol']));
                if($protocol === '') $protocol = $parsed['protocol'] ?: 'vless';
                $netType = (string)($stream['network'] ?? $parsed['net_type'] ?? 'tcp');
                if($netType === '') $netType = 'tcp';
                $remark = $email !== '' ? $email : ($parsed['remark'] ?: (string)farid_manualValue($row, 'remark', 'manual'));

                return [true, [
                    'server_id' => $serverId,
                    'inbound_id' => intval(farid_manualValue($row, 'id', 0)),
                    'port' => $port,
                    'protocol' => $protocol,
                    'net_type' => $netType,
                    'uuid' => $clientId,
                    'remark' => $remark,
                    'sub_id' => $subId,
                    'expire_date' => $expiry,
                    'provided_link' => trim((string)$input),
                    'is_sub' => !empty($parsed['is_sub']),
                ]];
            }
        }
    }

    return [false, 'این کانفیگ/ساب در سرورهای ثبت‌شده ربات پیدا نشد.'];
}

function farid_registerManualConfigForUser($targetUserId, $input){
    global $connection, $botState;
    $targetUserId = intval($targetUserId);
    if($targetUserId <= 0) return [false, 'آیدی عددی کاربر معتبر نیست.'];

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? LIMIT 1");
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$targetUser) return [false, 'این کاربر هنوز داخل ربات وجود ندارد. اول کاربر باید یک بار /start بزند.'];

    [$ok, $found] = farid_findPanelAccountByInput($input);
    if(!$ok) return [false, $found];

    $serverId = intval($found['server_id']);
    $inboundId = intval($found['inbound_id']);
    $uuid = (string)$found['uuid'];
    $remark = (string)$found['remark'];
    $protocol = (string)$found['protocol'];
    $expire = intval($found['expire_date']);
    $token = trim((string)($found['sub_id'] ?? ''));
    if($token === '') $token = RandomString(30);

    // همیشه لینک‌ها از روی اطلاعات واقعی پنل و دامنه‌های ثبت‌شده داخل ربات بازسازی می‌شوند.
    // این باعث می‌شود اگر کاربر فقط یک لینک بدهد ولی در ربات چند دامنه برای سرور ثبت شده باشد، همه لینک‌ها مثل خرید عادی ساخته شوند.
    $links = [];
    $generated = getConnectionLink($serverId, $uuid, $protocol, $remark, intval($found['port']), (string)$found['net_type'], $inboundId, 0);
    if(is_array($generated)) $links = $generated;
    elseif(is_string($generated) && $generated !== '') $links = [$generated];

    // فقط برای حالت خطا، لینک ورودی را به‌عنوان fallback نگه می‌داریم تا ثبت کاملاً بی‌دلیل شکست نخورد.
    if(empty($links) && !empty($found['provided_link']) && empty($found['is_sub'])){
        $links[] = trim((string)$found['provided_link']);
    }
    $links = v2raystore_normalizeConfigLinksArray($links);
    if(empty($links)) return [false, 'کانفیگ پیدا شد، ولی ساخت لینک خروجی ممکن نشد.'];

    $linkJson = json_encode(array_values($links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $date = (string)time();
    $fileId = 0;
    $alreadyExists = false;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `server_id`=? AND `inbound_id`=? AND `uuid`=? AND `status`=1 ORDER BY `id` DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param('iiis', $targetUserId, $serverId, $inboundId, $uuid);
        $stmt->execute();
        $existingOrder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }else $existingOrder = null;

    if($existingOrder){
        $alreadyExists = true;
        $orderId = intval($existingOrder['id']);
        if(!empty($existingOrder['token'])) $token = (string)$existingOrder['token'];
        $stmt = $connection->prepare("UPDATE `orders_list` SET `token`=?, `server_id`=?, `inbound_id`=?, `remark`=?, `protocol`=?, `expire_date`=?, `link`=?, `notif`=0 WHERE `id`=?");
        if($stmt){
            $stmt->bind_param('siissisi', $token, $serverId, $inboundId, $remark, $protocol, $expire, $linkJson, $orderId);
            $stmt->execute();
            $stmt->close();
        }
    }else{
        $stmt = $connection->prepare("INSERT INTO `orders_list`
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, 0, 0, 0)");
        $uidStr = (string)$targetUserId;
        $stmt->bind_param('ssiiisssiss', $uidStr, $token, $fileId, $serverId, $inboundId, $remark, $uuid, $protocol, $expire, $linkJson, $date);
        $stmt->execute();
        $orderId = intval($connection->insert_id);
        $stmt->close();
    }

    $linkOptions = function_exists('v2raystore_getRuntimeDeliveryLinkOptions')
        ? v2raystore_getRuntimeDeliveryLinkOptions($targetUserId, null, null, null, $serverId)
        : ['config'=>(($botState['configLinkState'] ?? '') != 'off'), 'sub'=>(($botState['subLinkState'] ?? 'off') == 'on')];
    if(function_exists('v2raystore_normalizeDeliveryLinkOptions')) $linkOptions = v2raystore_normalizeDeliveryLinkOptions($linkOptions);

    $subLink = '';
    if(!empty($linkOptions['sub'])){
        $subLink = v2raystore_makeCustomerSubLink($serverId, $token, $uuid, $inboundId, $remark);
    }

    if(!empty($linkOptions['config']) && count($links) > 1){
        $msg = v2raystore_buildMultiDomainConfigMessage($remark, $links, $subLink, '✅ کانفیگ به حساب شما اضافه شد');
    }else{
        $safeLinks = array_map(function($l){ return htmlspecialchars($l, ENT_QUOTES, 'UTF-8'); }, $links);
        $msg = "✅ کانفیگ به حساب شما اضافه شد.

" .
               "🔮 نام سرویس: <b>" . htmlspecialchars($remark, ENT_QUOTES, 'UTF-8') . "</b>
" .
               ((!empty($linkOptions['config'])) ? ("
💝 config:
<code>" . implode("</code>
<code>", $safeLinks) . "</code>
") : "") .
               ($subLink !== '' ? "
🌐 subscription:
<code>" . htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') . "</code>" : "");
    }
    if($msg !== '') sendMessage($msg, null, 'HTML', $targetUserId);

    return [true, [
        'order_id' => $orderId,
        'remark' => $remark,
        'server_id' => $serverId,
        'inbound_id' => $inboundId,
        'sub_link' => $subLink,
        'links_count' => count($links),
        'already_exists' => $alreadyExists,
    ]];
}

function farid_findOrderIdBySearchText($input, $userId = 0, $onlyNonAgent = false){
    global $connection;
    $input = trim((string)$input);
    if($input === '') return 0;

    $parsed = farid_parseConfigOrSubLink($input);
    $subId = $parsed['sub_id'];
    $uuid = $parsed['uuid'];

    $baseSql = "SELECT * FROM `orders_list` WHERE 1=1";
    $types = '';
    $vals = [];
    if(intval($userId) > 0){
        $baseSql .= " AND `userid` = ?";
        $types .= 'i';
        $vals[] = intval($userId);
    }
    if($onlyNonAgent){
        $baseSql .= " AND `agent_bought` = 0";
    }

    if($uuid !== ''){
        $sql = $baseSql . " AND `uuid` = ? ORDER BY `id` DESC LIMIT 1";
        $types2 = $types . 's';
        $vals2 = array_merge($vals, [$uuid]);
        $stmt = $connection->prepare($sql);
        farid_bindParamsDynamic($stmt, $types2, $vals2);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row) return intval($row['id']);
    }

    if($subId !== ''){
        $sql = $baseSql . " AND `token` = ? ORDER BY `id` DESC LIMIT 1";
        $types2 = $types . 's';
        $vals2 = array_merge($vals, [$subId]);
        $stmt = $connection->prepare($sql);
        farid_bindParamsDynamic($stmt, $types2, $vals2);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row) return intval($row['id']);

        $sql = $baseSql . " ORDER BY `id` DESC LIMIT 300";
        $stmt = $connection->prepare($sql);
        if($types !== '') farid_bindParamsDynamic($stmt, $types, $vals);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        while($order = $res->fetch_assoc()){
            $panelSub = v2raystore_findPanelSubId(intval($order['server_id']), $order['token'], $order['uuid'], intval($order['inbound_id']), $order['remark']);
            if($panelSub !== '' && $panelSub === $subId) return intval($order['id']);
        }
    }

    $sql = $baseSql . " AND `remark` LIKE CONCAT('%', ?, '%') ORDER BY `id` DESC LIMIT 1";
    $types2 = $types . 's';
    $vals2 = array_merge($vals, [$input]);
    $stmt = $connection->prepare($sql);
    farid_bindParamsDynamic($stmt, $types2, $vals2);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? intval($row['id']) : 0;
}

// ===========================
// فیلتر بر اساس دامنه/آدرس
// ===========================
function farid_base64UrlDecode($data){
    if($data === null) return false;
    $data = str_replace(["-","_"], ["+","/"], $data);
    $pad = strlen($data) % 4;
    if($pad > 0){
        $data .= str_repeat("=", 4 - $pad);
    }
    return base64_decode($data);
}

function farid_normalizeDomainInput($input){
    $input = trim(strval($input));
    if($input === "") return "";

    // حذف فاصله‌ها
    $input = preg_replace('/\s+/', '', $input);

    // اگر URL کامل بود
    if(strpos($input, "://") !== false){
        $u = @parse_url($input);
        if(is_array($u) && !empty($u['host'])){
            $input = $u['host'];
        }
    }else{
        // حذف path در صورت وجود
        $input = preg_replace('/\/.*$/', '', $input);
    }

    // حذف پورت
    $input = preg_replace('/:\d+$/', '', $input);

    // حذف براکت IPv6
    $input = trim($input, "[]");

    return strtolower($input);
}

function farid_extractDomainCandidatesFromLink($link){
    $link = trim(strval($link));
    if($link === "") return [];

    $candidates = [];

    // VMESS: داخل base64 است
    if(stripos($link, "vmess://") === 0){
        $b64 = substr($link, 8);
        $decoded = farid_base64UrlDecode($b64);
        if($decoded !== false){
            $j = json_decode($decoded, true);
            if(is_array($j)){
                if(!empty($j['add']))  $candidates[] = $j['add'];
                if(!empty($j['host'])) $candidates[] = $j['host'];
                if(!empty($j['sni']))  $candidates[] = $j['sni'];
                if(!empty($j['tlsServerName'])) $candidates[] = $j['tlsServerName'];
            }
        }
        return $candidates;
    }

    // سایر پروتکل‌ها: host اصلی + پارامترهای مهم (sni/host/peer/...) در Query
    $u = @parse_url($link);
    if(is_array($u)){
        if(!empty($u['host'])){
            $candidates[] = $u['host'];
        }

        // برخی لینک‌ها ممکن است host در userinfo باشند: ...@host:port
        if(preg_match('/@([^:\\/\\?#]+)(:|\\/|\\?|#|$)/', $link, $m)){
            $candidates[] = $m[1];
        }

        // Query Params
        if(!empty($u['query'])){
            $params = [];
            @parse_str($u['query'], $params);

            if(is_array($params) && !empty($params)){
                // کلیدهایی که معمولاً دامنه در آن‌ها ذخیره می‌شود
                $allowedKeys = [
                    'sni','host','peer',
                    'servername','serverName',
                    'tlsServerName','tls-server-name',
                    'wsHost','wshost','ws-host',
                    'h2host','h2-host',
                ];

                foreach($params as $k => $v){
                    $kNorm = strtolower(strval($k));
                    if(!in_array($kNorm, array_map('strtolower', $allowedKeys), true)) continue;

                    $vals = [];
                    if(is_array($v)){
                        $vals = $v;
                    }else{
                        $vals = preg_split('/[,\s;|]+/', strval($v));
                    }

                    foreach($vals as $vv){
                        $vv = trim(strval($vv));
                        if($vv === "") continue;
                        $candidates[] = $vv;
                    }
                }
            }
        }
    }else{
        // fallback: فقط ...@host:port
        if(preg_match('/@([^:\\/\\?#]+)(:|\\/|\\?|#|$)/', $link, $m)){
            $candidates[] = $m[1];
        }
    }

    return $candidates;
}

function farid_linkMatchesDomain($link, $domainRaw){
    $domain = farid_normalizeDomainInput($domainRaw);
    if($domain === "") return false;

    $candidates = farid_extractDomainCandidatesFromLink($link);
    if(empty($candidates)) return false;

    foreach($candidates as $h){
        $hNorm = farid_normalizeDomainInput($h);
        if($hNorm === "") continue;

        // تطبیق دقیق
        if($hNorm === $domain) return true;

        // پشتیبانی از ساب‌دامین‌ها
        if(strlen($hNorm) > strlen($domain) && substr($hNorm, -strlen($domain)-1) === "." . $domain){
            return true;
        }
    }

    return false;
}

function farid_findOrderIdsByDomain($domainRaw){
    global $connection;

    $domain = farid_normalizeDomainInput($domainRaw);
    if($domain === "") return ['ids'=>[], 'orders_count'=>0, 'links_count'=>0];

    $like = "%" . $domain . "%";

    // برای سرعت: همه غیر-vmessها فقط با LIKE (case-insensitive)، و vmessها را هم جدا می‌کنیم چون داخل base64 است
    $stmt = $connection->prepare("SELECT `id`, `protocol`, `link` FROM `orders_list` WHERE `status` = 1 AND (`protocol` = 'vmess' OR LOWER(`link`) LIKE ?)");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $ids = [];
    $linksCount = 0;

    while($row = $res->fetch_assoc()){
        $oid = intval($row['id']);
        $linkJson = $row['link'] ?? "";

        $links = json_decode($linkJson, true);
        if(!is_array($links)){
            $links = [$linkJson];
        }

        $matchedThisOrder = false;

        foreach($links as $lnk){
            if(farid_linkMatchesDomain($lnk, $domain)){
                $matchedThisOrder = true;
                $linksCount++;
                // اگر می‌خواهیم فقط یک بار برای هر سفارش شمارش لینک انجام شود، این break را بردارید.
                // فعلاً تعداد «لینک‌های منطبق» را می‌شمارد.
            }
        }

        if($matchedThisOrder){
            $ids[] = $oid;
        }
    }

    // یکتا سازی آی‌دی‌ها
    $ids = array_values(array_unique(array_map('intval', $ids)));

    return [
        'ids' => $ids,
        'orders_count' => count($ids),
        'links_count' => intval($linksCount),
    ];
}


function farid_findLinkItemsByDomain($domainRaw){
    global $connection;

    $domain = farid_normalizeDomainInput($domainRaw);
    if($domain === "") return ['items'=>[], 'orders_count'=>0, 'links_count'=>0];

    $like = "%" . $domain . "%";

    // برای سرعت: همه غیر-vmessها فقط با LIKE (case-insensitive)، و vmessها را هم جدا می‌کنیم چون داخل base64 است
    $stmt = $connection->prepare("SELECT `id`, `protocol`, `link` FROM `orders_list` WHERE `status` = 1 AND (`protocol` = 'vmess' OR LOWER(`link`) LIKE ?)");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $items = [];
    $ordersSet = [];
    $linksCount = 0;

    while($row = $res->fetch_assoc()){
        $oid = intval($row['id']);
        $linkJson = $row['link'] ?? "";

        $links = json_decode($linkJson, true);
        if(!is_array($links)){
            $links = [$linkJson];
        }

        foreach($links as $idx => $lnk){
            if(farid_linkMatchesDomain($lnk, $domain)){
                $items[] = $oid . ":" . intval($idx);
                $linksCount++;
                $ordersSet[$oid] = 1;
            }
        }
    }

    return [
        'items' => array_values($items),
        'orders_count' => count($ordersSet),
        'links_count' => intval($linksCount),
    ];
}



function farid_orderIsSubOnly($order){
    return function_exists('v2raystore_isSubOnlyOrder') && v2raystore_isSubOnlyOrder($order);
}

function farid_orderHasSubDelivery($order){
    if(function_exists('v2raystore_orderUsesSubscription')) return v2raystore_orderUsesSubscription($order);
    if(function_exists('v2raystore_isSubOnlyOrder')) return v2raystore_isSubOnlyOrder($order);
    return false;
}

function farid_orderIdHasSubDelivery($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0 || !isset($connection) || !$connection) return false;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $order ? farid_orderHasSubDelivery($order) : false;
}

function farid_orderIdIsSubOnly($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0 || !isset($connection) || !$connection) return false;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $order ? farid_orderIsSubOnly($order) : false;
}

function farid_normalizeSubLookupTitle($input){
    $input = trim((string)$input);
    if($input === '') return '';
    $plain = strip_tags(html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = preg_replace('/\s+/u', '', $plain);
    return htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
}

function farid_subLookupParts($input){
    $raw = trim((string)$input);
    $raw = strip_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $raw = trim($raw, " \t\n\r\0\x0B`'\"");
    $rawNoSlash = rtrim($raw, '/');
    $isUrl = (bool)preg_match('#^https?://#i', $raw);
    $host = '';
    $path = '';
    if($isUrl){
        $u = @parse_url($raw);
        if(is_array($u)){
            $host = strtolower((string)($u['host'] ?? ''));
            $path = (string)($u['path'] ?? '');
        }
    }else{
        $host = function_exists('farid_normalizeDomainInput') ? farid_normalizeDomainInput($raw) : strtolower(preg_replace('/\/.*$/', '', $raw));
    }
    return ['raw'=>$raw, 'raw_noslash'=>$rawNoSlash, 'is_url'=>$isUrl, 'host'=>$host, 'path'=>$path];
}

function farid_subLinkMatchesInput($subLink, $input){
    $subLink = trim((string)$subLink);
    if($subLink === '') return false;
    $needle = farid_subLookupParts($input);
    $hay = farid_subLookupParts($subLink);
    if($needle['is_url']){
        if(rtrim($subLink, '/') === $needle['raw_noslash']) return true;
        // اگر لینک کامل فرستاد ولی فقط path یا query فرق جزئی داشت، حداقل host و توکن /sub/ یا /json/ را بررسی کن.
        $nToken = '';
        $hToken = '';
        if(preg_match('#/(sub|json)/([^/?#\s]+)#i', $needle['raw'], $m)) $nToken = (string)$m[2];
        if(preg_match('#/(sub|json)/([^/?#\s]+)#i', $subLink, $m)) $hToken = (string)$m[2];
        return $needle['host'] !== '' && $hay['host'] !== '' && $needle['host'] === $hay['host'] && $nToken !== '' && $nToken === $hToken;
    }
    if($needle['host'] === '' || $hay['host'] === '') return false;
    if($needle['host'] === $hay['host']) return true;
    return (strlen($hay['host']) > strlen($needle['host']) && substr($hay['host'], -strlen($needle['host'])-1) === '.' . $needle['host']);
}

function farid_findSubOrderIdsByInput($input){
    global $connection;
    if(!isset($connection) || !$connection) return ['ids'=>[], 'orders_count'=>0];
    $ids = [];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 ORDER BY `id` ASC");
    if(!$stmt) return ['ids'=>[], 'orders_count'=>0];
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while($order = $res->fetch_assoc()){
        $subLink = function_exists('v2raystore_orderCurrentSubLink') ? v2raystore_orderCurrentSubLink($order) : '';
        if($subLink !== '' && farid_subLinkMatchesInput($subLink, $input)){
            $ids[] = intval($order['id']);
        }
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return ['ids'=>$ids, 'orders_count'=>count($ids)];
}

function farid_buildUpdatedSubMessage($remark, $subLink, $title = '✅ لینک ساب سرویس شما بروزرسانی شد'){
    $safeRemark = htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8');
    $safeSub = htmlspecialchars((string)$subLink, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $msg = $safeTitle . "\n";
    if($safeRemark !== '') $msg .= "🔮 نام سرویس: <b>{$safeRemark}</b>\n";
    $msg .= "\n🌐 subscription : <code>{$safeSub}</code>";
    if(function_exists('v2raystore_subConfigUsageGuide')) $msg .= v2raystore_subConfigUsageGuide();
    return $msg;
}

function farid_sendUpdatedSubToUser($userId, $remark, $subLink, $afterMessage = null, $title = null){
    $userId = intval($userId);
    $subLink = trim((string)$subLink);
    if($userId <= 0 || $subLink === '') return false;
    $text = farid_buildUpdatedSubMessage($remark, $subLink, $title ?: '✅ لینک ساب سرویس شما بروزرسانی شد');
    if(function_exists('v2raystore_sendQrLinkMessage')){
        $ok = v2raystore_sendQrLinkMessage($userId, $subLink, $text, null, 'HTML');
    }else{
        $res = sendMessage($text, null, 'HTML', $userId);
        $ok = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($res) : true;
    }
    $msg = $afterMessage;
    if($msg === null && function_exists('farid_getUpdateAfterMessage')) $msg = farid_getUpdateAfterMessage();
    if(strlen(trim(strval($msg))) > 0) sendMessage($msg, null, 'HTML', $userId);
    return $ok;
}

function farid_getUpdateConfigsJob(){
    $defaults = [
        'state' => 0,
        'mode'  => null,
        'userid'=> 0,
        'ids'   => [],
        'items' => [],
        'orders_count' => 0,
        'offset'=> 0,
        'batch' => 10,
        'created_at' => 0,
        'requested_by' => 0,
        'filter_title' => null,
        'stats' => ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0],
        'links_count' => 0,
        'status_chat_id' => 0,
        'status_message_id' => 0,
        'auto_running' => 0,
        'auto_last_ts' => 0,
        'stopped_at' => 0,
        'stopped_by' => 0,
        'report_sent' => 0
    ];

    $raw = farid_getSettingValue("UPDATE_CONFIGS_JOB");
    if(empty($raw)){
        return $defaults;
    }

    $job = json_decode($raw, true);
    if(!is_array($job)){
        return $defaults;
    }

    // merge with defaults
    $job = array_merge($defaults, $job);

    $job['state']  = intval($job['state'] ?? 0);
    $job['offset'] = max(0, intval($job['offset'] ?? 0));
    $job['batch']  = intval($job['batch'] ?? 10);
    if($job['batch'] < 1) $job['batch'] = 10;

    $job['links_count'] = intval($job['links_count'] ?? 0);
    $job['orders_count'] = intval($job['orders_count'] ?? 0);

    if(!isset($job['ids']) || !is_array($job['ids'])) $job['ids'] = [];
    if(!isset($job['items']) || !is_array($job['items'])) $job['items'] = [];
    $job['status_chat_id'] = intval($job['status_chat_id'] ?? 0);
    $job['status_message_id'] = intval($job['status_message_id'] ?? 0);
    $job['auto_running'] = intval($job['auto_running'] ?? 0);
    $job['auto_last_ts'] = intval($job['auto_last_ts'] ?? 0);
    $job['stopped_at'] = intval($job['stopped_at'] ?? 0);
    $job['stopped_by'] = intval($job['stopped_by'] ?? 0);

    $job['userid'] = intval($job['userid'] ?? 0);
    if(!isset($job['mode'])) $job['mode'] = null;

    // ids normalization
    if(!isset($job['ids'])) $job['ids'] = [];
    if(!is_array($job['ids'])){
        if(is_string($job['ids']) && strlen(trim($job['ids'])) > 0){
            $job['ids'] = array_filter(array_map('intval', explode(',', $job['ids'])));
        }else{
            $job['ids'] = [];
        }
    }
    $job['ids'] = array_values(array_map('intval', $job['ids']));

    // stats normalization
    if(!isset($job['stats']) || !is_array($job['stats'])){
        $job['stats'] = ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0];
    }else{
        $job['stats']['processed'] = intval($job['stats']['processed'] ?? 0);
        $job['stats']['updated']   = intval($job['stats']['updated'] ?? 0);
        $job['stats']['failed']    = intval($job['stats']['failed'] ?? 0);
        $job['stats']['sent']      = intval($job['stats']['sent'] ?? 0);
    }

    $job['created_at']   = intval($job['created_at'] ?? 0);
    $job['requested_by'] = intval($job['requested_by'] ?? 0);
    $job['report_sent']  = intval($job['report_sent'] ?? 0);

    // filter_title
    if(!isset($job['filter_title'])) $job['filter_title'] = null;

    return $job;
}


function farid_setUpdateConfigsJob($job){
    $value = json_encode($job, JSON_UNESCAPED_UNICODE);
    farid_setSettingValue("UPDATE_CONFIGS_JOB", $value);
}


function farid_finishWebhookResponse(){
    // تلاش می‌کند پاسخ وبهوک را سریع ببندد تا اجرای طولانی باعث Retry تلگرام نشود
    @ignore_user_abort(true);
    @set_time_limit(0);

    if(function_exists('fastcgi_finish_request')){
        @fastcgi_finish_request();
        return;
    }

    // Fallback برای سرورهایی که fastcgi_finish_request ندارند
    $startedBuffer = false;
    if(ob_get_level() == 0){
        @ob_start();
        $startedBuffer = true;
    }

    echo "OK";
    $size = ob_get_length();

    if(!headers_sent()){
        @header("Content-Encoding: none");
        @header("Content-Length: ".$size);
        @header("Connection: close");
    }

    if($startedBuffer){
        @ob_end_flush();
    }else{
        @ob_flush();
    }
    @flush();
}


function farid_updateConfigsMaxRuntimeSeconds(){
    // Prevent one webhook/process from staying alive too long on shared hosting.
    // Admin can continue the remaining queue with the same button; no order is lost.
    $limit = 20;
    if(defined('V2RAYSTORE_UPDATE_CONFIGS_MAX_RUNTIME')){
        $limit = intval(V2RAYSTORE_UPDATE_CONFIGS_MAX_RUNTIME);
    }elseif(isset($GLOBALS['botState']['updateConfigsMaxRuntime'])){
        $limit = intval($GLOBALS['botState']['updateConfigsMaxRuntime']);
    }
    if($limit < 5) $limit = 5;
    if($limit > 55) $limit = 55;
    return $limit;
}

function farid_updateConfigsModeTitle($job){
    $mode = $job['mode'] ?? '';
    if($mode == "all_active") return "همه کانفیگ‌های فعال";
    if($mode == "user") return "کاربر: " . intval($job['userid'] ?? 0);
    if($mode == "ids" || $mode == "sub_ids") return strval($job['filter_title'] ?? "فیلتر سفارشی");
    if($mode == "links") return strval($job['filter_title'] ?? "فیلتر سفارشی");
    return "—";
}

function farid_buildUpdateConfigsProgressText($job){
    $total  = farid_getUpdateConfigsTotal($job);
    $offset = intval($job['offset'] ?? 0);
    $left   = max(0, $total - $offset);

    $stats = $job['stats'] ?? [];
    if(!is_array($stats)) $stats = [];

    $updated = intval($stats['updated'] ?? 0);
    $failed  = intval($stats['failed'] ?? 0);
    $sent    = intval($stats['sent'] ?? 0);

    $modeTitle = farid_updateConfigsModeTitle($job);

    $state = intval($job['state'] ?? 0);
    $statusLine = "⏳ در حال اجرا";
    if($state == 1 && intval($job['auto_running'] ?? 0) != 1){
        $statusLine = "⏸ آماده ادامه";
    }elseif($state != 1){
        if($offset >= $total){
            $statusLine = "✅ تکمیل شد";
        }else{
            $statusLine = "⛔️ متوقف شد";
        }
    }

    $percent = ($total > 0) ? round(($offset * 100) / $total) : 0;

    $createdAt = intval($job['created_at'] ?? 0);
    $createdTxt = "-";
    if($createdAt > 0){
        $createdTxt = function_exists('jdate') ? jdate("Y-m-d H:i", $createdAt) : date("Y-m-d H:i", $createdAt);
    }

    $nowTxt = function_exists('jdate') ? jdate("Y-m-d H:i", time()) : date("Y-m-d H:i", time());

    $batch = intval($job['batch'] ?? 10);
    if($batch < 1) $batch = 10;

    $extra = "";
    $linksCount = intval($job['links_count'] ?? 0);
    if($linksCount > 0){
        $extra .= "🔗 کانفیگ‌های منطبق (دیتابیس): $linksCount\n";
    }

    $ordersCount = intval($job['orders_count'] ?? 0);
    if($ordersCount > 0){
        $extra .= "📌 سفارش‌های درگیر (یکتا): $ordersCount\n";
    }

    $txt = "♻️ عملیات به‌روزرسانی و ارسال کانفیگ‌ها\n\n".
           "📌 وضعیت: $statusLine\n".
           "🎯 نوع: $modeTitle\n".
           $extra.
           "📦 کل: $total\n".
           "✅ انجام‌شده: $offset\n".
           "⏳ باقی‌مانده: $left\n".
           "📈 پیشرفت: $percent%\n\n".
           "✅ موفق: $updated\n".
           "⛔️ ناموفق: $failed\n".
           "📤 ارسال‌شده: $sent\n\n".
           "🧩 اندازه هر مرحله: $batch\n".
           "🕒 شروع: $createdTxt\n".
           "🔄 آخرین به‌روزرسانی: $nowTxt";

    return $txt;
}

function farid_getUpdateConfigsProgressKeyboard($job){
    $total  = farid_getUpdateConfigsTotal($job);
    $offset = intval($job['offset'] ?? 0);
    $state  = intval($job['state'] ?? 0);

    // در حال اجرا یا آماده ادامه پس از پایان امن بازه پردازش
    if($state == 1){
        $rows = [];
        if(intval($job['auto_running'] ?? 0) == 1){
            $rows[] = [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]];
        }else{
            $rows[] = [['text'=>"🚀 ادامه اجرای خودکار",'callback_data'=>"updateConfigsRun"]];
            $rows[] = [['text'=>"⛔️ توقف عملیات",'callback_data'=>"updateConfigsStop"]];
            $rows[] = [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]];
        }
        return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
    }

    // متوقف شده ولی کامل نشده
    if($offset < $total){
        return json_encode(['inline_keyboard'=>[
            [['text'=>"🚀 ادامه اجرای خودکار",'callback_data'=>"updateConfigsRun"]],
            [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
        ]], JSON_UNESCAPED_UNICODE);
    }

    // تکمیل شده
    return json_encode(['inline_keyboard'=>[
        [['text'=>"⬅️ بازگشت",'callback_data'=>"updateConfigsMenu"]],
    ]], JSON_UNESCAPED_UNICODE);
}

function farid_editUpdateConfigsProgressMessage($job, $forceFinal = false){
    // اگر پیام وضعیت مشخص نباشد، کاری نمی‌کنیم
    $chatId = intval($job['status_chat_id'] ?? 0);
    $msgId  = intval($job['status_message_id'] ?? 0);
    if($chatId <= 0 || $msgId <= 0) return;

    $text = farid_buildUpdateConfigsProgressText($job);
    $keyboard = farid_getUpdateConfigsProgressKeyboard($job);

    if(function_exists('bot')){
        bot('editMessageText',[
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => $text,
            'parse_mode' => "HTML",
            'reply_markup' => $keyboard
        ]);
    }
}

function farid_getUpdateConfigsTotal($job){
    global $connection;

    $mode = $job['mode'] ?? '';

    // ✅ حالت: به‌روزرسانی بر اساس «لیست لینک‌ها»
    if($mode == "links"){
        $items = $job['items'] ?? [];
        if(!is_array($items)) $items = [];
        return count($items);
    }

    // ✅ حالت: به‌روزرسانی بر اساس «لیست آی‌دی سفارش‌ها»
    if($mode == "ids" || $mode == "sub_ids"){
        $ids = $job['ids'] ?? [];
        if(!is_array($ids)) $ids = [];
        return count($ids);
    }

    // حالت: کاربر مشخص
    if($mode == "user"){
        $uid = intval($job['userid'] ?? 0);
        $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `orders_list` WHERE `status` = 1 AND `userid` = ?");
        $stmt->bind_param("i", $uid);
    }else{
        // حالت: همه کانفیگ‌های فعال
        $stmt = $connection->prepare("SELECT COUNT(*) AS `cnt` FROM `orders_list` WHERE `status` = 1");
    }

    $stmt->execute();
    $total = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    return $total;
}



// ===========================
// گزارش پایان کار عملیات به‌روزرسانی
// ===========================
function farid_sendUpdateConfigsFinalReportIfNeeded($job){
    global $admin;

    if(!is_array($job)) return;

    if(intval($job['report_sent'] ?? 0) == 1) return;

    $total = farid_getUpdateConfigsTotal($job);
    $offset = intval($job['offset'] ?? 0);

    $modeTitle = "نامشخص";
    if(($job['mode'] ?? '') == "all_active") $modeTitle = "همه کانفیگ‌های فعال";
    elseif(($job['mode'] ?? '') == "user") $modeTitle = "کانفیگ‌های کاربر: " . intval($job['userid'] ?? 0);
    elseif(in_array(($job['mode'] ?? ''), ["ids","links","sub_ids"], true)) $modeTitle = ($job['filter_title'] ?? "فیلتر سفارشی");

    $stats = $job['stats'] ?? [];
    $processed = intval($stats['processed'] ?? $offset);
    $updated   = intval($stats['updated'] ?? 0);
    $failed    = intval($stats['failed'] ?? 0);
    $sent      = intval($stats['sent'] ?? 0);

    $start = intval($job['created_at'] ?? 0);
    $duration = ($start > 0) ? (time() - $start) : 0;

    $requestedBy = intval($job['requested_by'] ?? 0);
    if($requestedBy <= 0) $requestedBy = $admin;

 $txt = 
"📄 <b>گزارش پایان کار به‌روزرسانی کانفیگ‌ها</b>\n\n".
"🎯 <b>نوع:</b> {$modeTitle}\n".
"🔰 <b>کل:</b> {$total}\n".
"☑️ <b>پردازش‌شده:</b> {$processed}\n".
"✅ <b>موفق:</b> {$updated}\n".
"⛔️ <b>ناموفق:</b> {$failed}\n".
"📨 <b>ارسال‌شده به کاربر:</b> {$sent}\n".
"⏱ <b>مدت:</b> {$duration}s\n".
"🕒 <b>زمان:</b> " . jdate("Y-m-d H:i", time());

sendMessage($txt, null, "HTML", $requestedBy);


    $job['report_sent'] = 1;
    farid_setUpdateConfigsJob($job);
}


function farid_runUpdateConfigsBatch($job, $batch = 5){
    global $connection;

    if(!is_array($job)){
        return ['done'=>true,'processed'=>0,'offset'=>0,'total'=>0,'stats'=>[]];
    }

    $mode = $job['mode'] ?? '';
    $offset = max(0, intval($job['offset'] ?? 0));
    $batch = intval($batch);
    if($batch < 1) $batch = 1;

    $stats = $job['stats'] ?? ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0];
    if(!is_array($stats)){
        $stats = ['processed'=>0,'updated'=>0,'failed'=>0,'sent'=>0];
    }

    $processedNow = 0;

    // ✅ حالت: پردازش بر اساس «لیست لینک‌ها» (برای فیلتر دامنه/آدرس)
    if($mode == "links"){
        $items = $job['items'] ?? [];
        if(!is_array($items)) $items = [];

        $slice = array_slice($items, $offset, $batch);

        // cache برای جلوگیری از چند بار Query و Update یک سفارش در همان Batch
        $orderCache = [];
        $linksCache = [];
        $sentOrderIds = $job['sent_order_ids'] ?? [];
        if(!is_array($sentOrderIds)) $sentOrderIds = [];
        $sentOrderIds = array_map('intval', $sentOrderIds);

        foreach($slice as $token){
            $processedNow++;
            $stats['processed']++;

            $oid = 0;
            $idx = 0;

            // token می‌تواند "OID:IDX" باشد یا آرایه
            if(is_string($token) && strpos($token, ":") !== false){
                $parts = explode(":", $token, 2);
                $oid = intval($parts[0] ?? 0);
                $idx = intval($parts[1] ?? 0);
            }elseif(is_array($token)){
                if(isset($token['id'])) $oid = intval($token['id']);
                elseif(isset($token[0])) $oid = intval($token[0]);

                if(isset($token['idx'])) $idx = intval($token['idx']);
                elseif(isset($token[1])) $idx = intval($token[1]);
            }else{
                $stats['failed']++;
                continue;
            }

            if($oid <= 0){
                $stats['failed']++;
                continue;
            }

            // دریافت سفارش (cache)
            if(!array_key_exists($oid, $orderCache)){
                $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
                $stmt->bind_param("i", $oid);
                $stmt->execute();
                $orderCache[$oid] = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            $order = $orderCache[$oid];
            if(!$order){
                $stats['failed']++;
                continue;
            }
            if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
                continue;
            }

            // تولید لینک‌های جدید + آپدیت دیتابیس (یک بار در هر سفارش داخل Batch)
            if(!array_key_exists($oid, $linksCache)){
                $links = farid_generateUpdatedVrayLinks($order);
                if($links == null){
                    $linksCache[$oid] = null;
                }else{
                    $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

                    $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
                    $stmt->bind_param("si", $linkJson, $oid);
                    $stmt->execute();
                    $stmt->close();

                    $stats['updated']++;
                    $linksCache[$oid] = $links;
                }
            }

            $links = $linksCache[$oid];
            if($links == null){
                $stats['failed']++;
                continue;
            }

            // در فیلتر دامنه/آدرس هم باید خروجی مثل «بروزرسانی لینک» کاربر باشد:
            // یعنی بعد از بازسازی لینک‌ها، همه دامنه‌ها/آی‌پی‌های ثبت‌شده برای همان کانفیگ
            // در یک پیام جدید برای کاربر ارسال شود، نه فقط همان لینکی که با دامنه جستجو شده بود.
            $toUser = intval($order['userid'] ?? 0);
            if($toUser > 0 && !in_array($oid, $sentOrderIds, true)){
                $remark = $order['remark'] ?? "-";
                farid_sendUpdatedConfigToUser($toUser, $remark, $links);
                $stats['sent']++;
                $sentOrderIds[] = $oid;
            }
        }

        $job['offset'] = $offset + $processedNow;
        $job['sent_order_ids'] = array_values(array_unique(array_map('intval', $sentOrderIds)));
    }

    // ✅ حالت: آپدیت ساب‌ها با لیست آی‌دی‌ها؛ فقط لینک ساب ارسال می‌شود، لینک کانفیگ عادی ارسال نمی‌شود.
    elseif($mode == "sub_ids"){
        $ids = $job['ids'] ?? [];
        if(!is_array($ids)) $ids = [];
        $slice = array_slice($ids, $offset, $batch);

        foreach($slice as $oid){
            $oid = intval($oid);
            $processedNow++;
            $stats['processed']++;
            if($oid <= 0){ $stats['failed']++; continue; }

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? AND `status` = 1 LIMIT 1");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if(!$order){ $stats['failed']++; continue; }

            $subLink = function_exists('v2raystore_orderCurrentSubLink') ? v2raystore_orderCurrentSubLink($order) : '';
            if(trim((string)$subLink) === ''){ $stats['failed']++; continue; }

            $toUser = intval($order['userid'] ?? 0);
            if($toUser > 0){
                $remark = $order['remark'] ?? '-';
                if(function_exists('farid_sendUpdatedSubToUser')) farid_sendUpdatedSubToUser($toUser, $remark, $subLink, '');
                else sendMessage("✅ لینک ساب سرویس شما بروزرسانی شد\n\n🌐 subscription : <code>" . htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') . "</code>", null, 'HTML', $toUser);
                $stats['sent']++;
            }
            $stats['updated']++;
        }

        $job['offset'] = $offset + $processedNow;
    }

    // ✅ حالت: فیلتر شده با لیست آی‌دی‌ها (سفارش‌ها)
    elseif($mode == "ids"){
        $ids = $job['ids'] ?? [];
        if(!is_array($ids)) $ids = [];

        $slice = array_slice($ids, $offset, $batch);

        foreach($slice as $oid){
            $oid = intval($oid);

            $processedNow++;
            $stats['processed']++;

            if($oid <= 0){
                $stats['failed']++;
                continue;
            }

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if(!$order){
                $stats['failed']++;
                continue;
            }

            if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
                continue;
            }
            $links = farid_generateUpdatedVrayLinks($order);
            if($links == null){
                $stats['failed']++;
                continue;
            }

            $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

            $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
            $stmt->bind_param("si", $linkJson, $oid);
            $stmt->execute();
            $stmt->close();

            $stats['updated']++;

            $toUser = intval($order['userid'] ?? 0);
            if($toUser > 0){
                $remark = $order['remark'] ?? "-";
                farid_sendUpdatedConfigToUser($toUser, $remark, $links);
                $stats['sent']++;
            }
        }

        $job['offset'] = $offset + $processedNow;
    }else{
        // حالت‌های all_active و user
        if($mode == "user"){
            $uid = intval($job['userid'] ?? 0);
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1 AND `userid` = ? ORDER BY `id` ASC LIMIT ? OFFSET ?");
            $stmt->bind_param("iii", $uid, $batch, $offset);
        }else{
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1 ORDER BY `id` ASC LIMIT ? OFFSET ?");
            $stmt->bind_param("ii", $batch, $offset);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        while($order = $res->fetch_assoc()){
            $processedNow++;
            $stats['processed']++;

            if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)){
                continue;
            }
            $links = farid_generateUpdatedVrayLinks($order);
            if($links == null){
                $stats['failed']++;
                continue;
            }

            $oid = intval($order['id']);
            $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

            $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
            $stmt->bind_param("si", $linkJson, $oid);
            $stmt->execute();
            $stmt->close();

            $stats['updated']++;

            $toUser = intval($order['userid'] ?? 0);
            if($toUser > 0){
                $remark = $order['remark'] ?? "-";
                farid_sendUpdatedConfigToUser($toUser, $remark, $links);
                $stats['sent']++;
            }
        }

        $job['offset'] = $offset + $processedNow;
    }

    $job['stats'] = $stats;

    $total = farid_getUpdateConfigsTotal($job);
    if(intval($job['offset']) >= $total){
        $job['state'] = 0;
    }

    farid_setUpdateConfigsJob($job);

    return [
        'processed' => $processedNow,
        'offset' => intval($job['offset']),
        'total' => $total,
        'done' => (intval($job['state']) != 1),
        'stats' => $stats
    ];
}


function farid_updateAndSendOneOrder($oid, $requestedBy = 0){
    global $connection;
    $oid = intval($oid);
    if($oid <= 0) return false;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$order) return false;
    if(function_exists('farid_orderHasSubDelivery') && farid_orderHasSubDelivery($order)) return false;

    $links = farid_generateUpdatedVrayLinks($order);
    if($links == null) return false;

    $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);

    $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $linkJson, $oid);
    $stmt->execute();
    $stmt->close();

    $remark = $order['remark'] ?? "-";
    $toUser = intval($order['userid'] ?? 0);
    if($toUser > 0){
        farid_sendUpdatedConfigToUser($toUser, $remark, $links);
    }

    return true;
}

function farid_refreshPlanOrderLinks($planId){
    global $connection;
    $planId = intval($planId);
    if($planId <= 0) return 0;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1 AND `fileid` = ?");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    $updated = 0;
    while($order = $orders->fetch_assoc()){
        $links = farid_generateUpdatedVrayLinks($order);
        if($links == null) continue;
        $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);
        $oid = intval($order['id']);
        $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ? WHERE `id` = ?");
        $stmt->bind_param("si", $linkJson, $oid);
        $stmt->execute();
        $stmt->close();
        $updated++;
    }
    return $updated;
}


function farid_renewAccountConnectionLinks($order){
    global $connection;

    if(!is_array($order)){
        return ['ok'=>false, 'message'=>'اطلاعات کانفیگ نامعتبر است.'];
    }

    $oid = intval($order['id'] ?? 0);
    $ownerId = intval($order['userid'] ?? 0);
    $remark = $order['remark'] ?? '-';
    $uuid = $order['uuid'] ?? '0';
    $inboundId = intval($order['inbound_id'] ?? 0);
    $server_id = intval($order['server_id'] ?? 0);
    $rahgozar = $order['rahgozar'] ?? null;
    $file_id = intval($order['fileid'] ?? 0);

    if($oid <= 0 || $server_id <= 0){
        return ['ok'=>false, 'message'=>'شناسه کانفیگ یا سرور نامعتبر است.'];
    }

    $customPath = null;
    $customPort = 0;
    $customSni = null;
    $customDomain = null;
    if($file_id > 0){
        $stmt = $connection->prepare("SELECT `custom_path`, `custom_port`, `custom_sni`, `custom_domain` FROM `server_plans` WHERE `id`=? LIMIT 1");
        if($stmt){
            $stmt->bind_param("i", $file_id);
            $stmt->execute();
            $file_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if($file_detail){
                $customPath = $file_detail['custom_path'];
                $customPort = $file_detail['custom_port'];
                $customSni = $file_detail['custom_sni'];
                $customDomain = $file_detail['custom_domain'] ?? null;
            }
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    if(!$stmt){
        return ['ok'=>false, 'message'=>'دسترسی به اطلاعات سرور ممکن نیست.'];
    }
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info){
        return ['ok'=>false, 'message'=>'سرور کانفیگ پیدا نشد.'];
    }

    $serverType = $server_info['type'] ?? '';
    $newUuid = $uuid;
    $newToken = RandomString(30);
    $vraylink = null;

    if($serverType == "marzban"){
        if(!function_exists('renewMarzbanUUID')){
            return ['ok'=>false, 'message'=>'تابع ساخت لینک جدید مرزبان در دسترس نیست.'];
        }
        $res = renewMarzbanUUID($server_id, $remark);
        if(!is_object($res)){
            return ['ok'=>false, 'message'=>'پاسخ مرزبان نامعتبر بود.'];
        }
        if(isset($res->success) && $res->success === false){
            return ['ok'=>false, 'message'=>($res->msg ?? 'مرزبان لینک جدید را نساخت.')];
        }
        if(isset($res->links) && !empty($res->links)){
            $vraylink = $res->links;
        }elseif(isset($res->subscription_url) && !empty($res->subscription_url)){
            $vraylink = [$res->subscription_url];
        }
        if($vraylink == null){
            return ['ok'=>false, 'message'=>'لینک جدید از مرزبان دریافت نشد.'];
        }
        if(isset($res->subscription_url) && !empty($res->subscription_url)){
            $newUuid = $newToken = str_replace('/sub/', '', $res->subscription_url);
        }
    }else{
        $json = getJson($server_id);
        if(!$json || !isset($json->obj)){
            return ['ok'=>false, 'message'=>'پنل در دسترس نیست یا پاسخ پنل نامعتبر است.'];
        }
        $response = $json->obj;

        $port = null;
        $protocol = null;
        $netType = null;
        $found = false;

        if($inboundId == 0){
            foreach($response as $row){
                $settings = json_decode($row->settings ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach($clients as $client){
                    $cid = $client['id'] ?? null;
                    $pwd = $client['password'] ?? null;
                    if(($cid !== null && $cid == $uuid) || ($pwd !== null && $pwd == $uuid)){
                        $port = $row->port ?? null;
                        $protocol = $row->protocol ?? null;
                        $stream = json_decode($row->streamSettings ?? '{}');
                        $netType = $stream->network ?? null;
                        $found = true;
                        break 2;
                    }
                }
            }
            if(!$found){
                return ['ok'=>false, 'message'=>'کانفیگ روی پنل پیدا نشد.'];
            }
            $update_response = renewInboundUuid($server_id, $uuid);
        }else{
            foreach($response as $row){
                if(isset($row->id) && intval($row->id) == $inboundId){
                    $settings = json_decode($row->settings ?? '{}', true);
                    $clients = $settings['clients'] ?? [];
                    foreach($clients as $client){
                        $cid = $client['id'] ?? null;
                        $pwd = $client['password'] ?? null;
                        if(($cid !== null && $cid == $uuid) || ($pwd !== null && $pwd == $uuid)){
                            $found = true;
                            break;
                        }
                    }
                    $port = $row->port ?? null;
                    $protocol = $row->protocol ?? null;
                    $stream = json_decode($row->streamSettings ?? '{}');
                    $netType = $stream->network ?? null;
                    break;
                }
            }
            if(!$found){
                return ['ok'=>false, 'message'=>'کلاینت کانفیگ روی این اینباند پیدا نشد.'];
            }
            $update_response = renewClientUuid($server_id, $inboundId, $uuid);
        }

        if(is_array($update_response)) $update_response = (object)$update_response;
        if(!is_object($update_response)){
            return ['ok'=>false, 'message'=>'پاسخ پنل بعد از تغییر UUID نامعتبر بود.'];
        }
        if(isset($update_response->success) && $update_response->success === false){
            $msg = $update_response->msg ?? $update_response->message ?? 'پنل تغییر UUID را قبول نکرد.';
            return ['ok'=>false, 'message'=>$msg];
        }
        if(!isset($update_response->newUuid) || empty($update_response->newUuid)){
            return ['ok'=>false, 'message'=>'UUID جدید از پنل دریافت نشد.'];
        }
        $newUuid = $update_response->newUuid;

        if(!$protocol || !$port || !$netType){
            return ['ok'=>false, 'message'=>'اطلاعات لازم برای ساخت لینک جدید کامل نیست.'];
        }

        $vraylink = getConnectionLink($server_id, $newUuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
    }

    if(function_exists('v2raystore_normalizeConfigLinksArray')){
        $normalizedLinks = v2raystore_normalizeConfigLinksArray($vraylink);
    }else{
        if(is_string($vraylink)) $normalizedLinks = [$vraylink];
        elseif(is_object($vraylink)) $normalizedLinks = (array)$vraylink;
        elseif(is_array($vraylink)) $normalizedLinks = $vraylink;
        else $normalizedLinks = [];
        $normalizedLinks = array_values(array_filter(array_map('strval', $normalizedLinks)));
    }

    if(empty($normalizedLinks)){
        return ['ok'=>false, 'message'=>'لینک جدید ساخته نشد.'];
    }

    $vray_link = json_encode($normalizedLinks, JSON_UNESCAPED_UNICODE);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid`=?, `token`=? WHERE `id`=?");
    if(!$stmt){
        return ['ok'=>false, 'message'=>'ذخیره لینک جدید در دیتابیس ممکن نیست.'];
    }
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid);
    $saved = $stmt->execute();
    $stmt->close();

    if(!$saved){
        return ['ok'=>false, 'message'=>'لینک جدید ساخته شد ولی در دیتابیس ذخیره نشد.'];
    }

    return [
        'ok' => true,
        'order_id' => $oid,
        'user_id' => $ownerId,
        'remark' => $remark,
        'links' => $normalizedLinks,
        'uuid' => $newUuid,
        'token' => $newToken,
    ];
}

function farid_generateUpdatedVrayLinks($order){
    global $connection, $botState;

    if(!is_array($order)) return null;

    // Sync expiry time from the panel whenever configs are refreshed.
    if(function_exists('v2raystore_syncOrderExpiryFromPanel')){
        $syncInfo = v2raystore_syncOrderExpiryFromPanel($order, true);
        if(is_array($syncInfo) && !empty($syncInfo['found']) && intval($syncInfo['expire_date'] ?? 0) > 0){
            $order['expire_date'] = intval($syncInfo['expire_date']);
        }
    }

    $server_id = intval($order['server_id'] ?? 0);
    if($server_id <= 0) return null;

    $remark = $order['remark'] ?? "-";
    $uuid = $order['uuid'] ?? "0";
    $inboundId = intval($order['inbound_id'] ?? 0);
    $rahgozar = $order['rahgozar'] ?? null;
    $file_id = intval($order['fileid'] ?? 0);

    // مقادیر سفارشی پلن (در صورت وجود)
    $customPath = null; $customPort = null; $customSni = null; $customDomain = null;
    if($file_id > 0){
        $stmt = $connection->prepare("SELECT `custom_path`, `custom_port`, `custom_sni`, `custom_domain` FROM `server_plans` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $file_id);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($plan){
            $customPath = $plan['custom_path'];
            $customPort = $plan['custom_port'];
            $customSni  = $plan['custom_sni'];
            $customDomain = $plan['custom_domain'] ?? null;
        }
    }

    $stmt = $connection->prepare("SELECT `type`, `security` FROM `server_config` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info) return null;

    $serverType = $server_info['type'] ?? '';
    $security = $server_info['security'] ?? '';

    // Marzban
    if($serverType == "marzban"){
        $info = null;
        if(function_exists("getMarzbanUser")){
            $info = getMarzbanUser($server_id, $remark);
        }
        if($info == null && function_exists("getMarzbanUserInfo")){
            $info = getMarzbanUserInfo($server_id, $remark);
        }

        if(is_object($info) && isset($info->links)){
            return $info->links;
        }
        if(is_object($info) && isset($info->subscription_url)){
            return [$info->subscription_url];
        }
        return null;
    }

    // X-UI / 3X-UI و ...
    $json = getJson($server_id);
    if(!$json || !isset($json->obj)) return null;
    $response = $json->obj;

    $port = null; $protocol = null; $netType = null; $iId = null;

    if($inboundId == 0){
        foreach($response as $row){
            $settings = json_decode($row->settings ?? "{}");
            if(!isset($settings->clients) || !is_array($settings->clients)) continue;

            foreach($settings->clients as $cl){
                $cid = $cl->id ?? null;
                $pwd = $cl->password ?? null;

                if(($cid != null && $cid == $uuid) || ($pwd != null && $pwd == $uuid)){
                    $iId = $row->id;
                    $port = $row->port;
                    $protocol = $row->protocol;

                    $stream = json_decode($row->streamSettings ?? "{}");
                    $netType = $stream->network ?? null;
                    break 2;
                }
            }
        }
    }else{
        foreach($response as $row){
            if(isset($row->id) && intval($row->id) == $inboundId){
                $iId = $row->id;
                $port = $row->port;
                $protocol = $row->protocol;

                $stream = json_decode($row->streamSettings ?? "{}");
                $netType = $stream->network ?? null;
                break;
            }
        }
    }

    if($iId === null || $port === null || $protocol === null || $netType === null) return null;

    if(($botState['updateConnectionState'] ?? '') == "robot"){
        updateConfig($server_id, $iId, $protocol, $netType, $security, $rahgozar);
    }

    return getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
}


function farid_switchIsMarzbanType($type){
    return trim((string)$type) === 'marzban';
}


function farid_switchExtractSubIdFast($value){
    $value = trim((string)$value);
    if($value === '') return '';
    if(function_exists('v2raystore_extractSubIdFromResponseValue')){
        $sid = v2raystore_extractSubIdFromResponseValue($value);
        if($sid !== '') return $sid;
    }
    if(preg_match('#/(?:sub|json)/([^/?#\s]+)#i', $value, $m)){
        $sid = trim((string)$m[1]);
        return ($sid !== '' && preg_match('/^[A-Za-z0-9_-]{6,80}$/', $sid)) ? $sid : '';
    }
    if(preg_match('/^[A-Za-z0-9_-]{6,80}$/', $value)) return $value;
    return '';
}

function farid_switchReplaceSubIdInLink($link, $subId){
    $link = trim((string)$link);
    $subId = trim((string)$subId);
    if($link === '' || $subId === '') return $link;
    if(!preg_match('#^https?://#i', $link)) return $link;
    return preg_replace_callback('#/(sub|json)/([^/?#\s]+)#i', function($m) use ($subId){
        return '/' . $m[1] . '/' . rawurlencode($subId);
    }, $link, 1);
}

function farid_switchBuildSubLinkNoPanel($order, $serverConfig = null){
    global $botUrl;
    if(!is_array($order)) return ['link'=>'', 'sub_id'=>'', 'token'=>''];
    $serverConfig = is_array($serverConfig) ? $serverConfig : farid_switchGetServerConfig(intval($order['server_id'] ?? 0));
    $token = trim((string)($order['token'] ?? ''));
    if($token === '') return ['link'=>'', 'sub_id'=>'', 'token'=>''];

    if(preg_match('#^https?://#i', $token)){
        return ['link'=>$token, 'sub_id'=>farid_switchExtractSubIdFast($token), 'token'=>$token];
    }

    $type = trim((string)($serverConfig['type'] ?? ''));
    $subId = farid_switchExtractSubIdFast($token);
    $link = '';

    if($type === 'marzban'){
        $panel = rtrim((string)($serverConfig['panel_url'] ?? ''), '/');
        if($panel !== '') $link = $panel . '/sub/' . rawurlencode($token);
    }elseif(function_exists('v2raystore_isPanelSubscriptionServer') && v2raystore_isPanelSubscriptionServer($type)){
        // این بخش عمداً به پنل درخواست نمی‌زند تا ربات موقع تغییر لوکیشن هنگ نکند.
        // اول دامنه ساب دستی؛ اگر نبود، از هاست لینک کانفیگ قبلی برای ساخت همان ساب استفاده می‌شود.
        if($subId !== ''){
            if(function_exists('v2raystore_serverManualSubBase')){
                $base = v2raystore_serverManualSubBase($serverConfig, 'sub');
                if($base !== '') $link = rtrim($base, '/') . '/' . rawurlencode($subId);
            }
            if($link === ''){
                $fallbackHost = '';
                $rawLinks = json_decode((string)($order['link'] ?? ''), true);
                if(!is_array($rawLinks)) $rawLinks = [];
                foreach($rawLinks as $candidateLink){
                    $candidateLink = trim((string)$candidateLink);
                    if($candidateLink === '') continue;
                    if(function_exists('v2raystore_parseHostFromConfigLink')){
                        $fallbackHost = v2raystore_parseHostFromConfigLink($candidateLink);
                    }
                    if($fallbackHost === ''){
                        $parts = @parse_url($candidateLink);
                        if(is_array($parts) && !empty($parts['host'])) $fallbackHost = (string)$parts['host'];
                    }
                    if($fallbackHost !== '') break;
                }
                if($fallbackHost !== ''){
                    $parts = @parse_url((string)($serverConfig['panel_url'] ?? ''));
                    $scheme = is_array($parts) && !empty($parts['scheme']) ? (string)$parts['scheme'] : 'https';
                    $link = $scheme . '://' . $fallbackHost . '/sub/' . rawurlencode($subId);
                }
            }
        }
    }else{
        $link = rtrim((string)$botUrl, '/') . '/settings/subLink.php?token=' . urlencode($token);
    }

    return ['link'=>$link, 'sub_id'=>$subId, 'token'=>$token];
}

function farid_switchGetServerConfig($serverId){
    global $connection;
    $serverId = intval($serverId);
    if($serverId <= 0) return null;
    $stmt = @$connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function farid_switchGetServerInfo($serverId){
    global $connection;
    $serverId = intval($serverId);
    if($serverId <= 0) return null;
    $stmt = @$connection->prepare("SELECT * FROM `server_info` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function farid_switchExpiryToSeconds($expiry){
    $expiry = intval($expiry);
    if($expiry <= 0) return 0;
    if(strlen((string)$expiry) > 10) return intval(substr((string)$expiry, 0, -3));
    return $expiry;
}

function farid_switchExpiryToMillis($expiry){
    $expiry = intval($expiry);
    if($expiry <= 0) return 0;
    if(strlen((string)$expiry) > 10) return $expiry;
    return $expiry * 1000;
}

function farid_switchFindXuiInboundInfo($serverId, $inboundId, $uuid = ''){
    $serverId = intval($serverId);
    $inboundId = intval($inboundId);
    $uuid = trim((string)$uuid);
    $json = getJson($serverId);
    if(!$json || !isset($json->obj) || !is_array($json->obj)) return null;
    foreach($json->obj as $row){
        if($inboundId > 0 && intval($row->id ?? 0) != $inboundId) continue;
        $settings = @json_decode($row->settings);
        $clients = is_object($settings) && isset($settings->clients) && is_array($settings->clients) ? $settings->clients : [];
        $client = null;
        if($uuid !== ''){
            foreach($clients as $c){
                if((isset($c->id) && (string)$c->id === $uuid) || (isset($c->password) && (string)$c->password === $uuid)){
                    $client = $c;
                    break;
                }
            }
            if($inboundId <= 0 && !$client) continue;
        }
        $stream = @json_decode($row->streamSettings);
        return [
            'row' => $row,
            'client' => $client,
            'id' => intval($row->id ?? 0),
            'port' => intval($row->port ?? 0),
            'protocol' => (string)($row->protocol ?? ''),
            'netType' => is_object($stream) ? (string)($stream->network ?? '') : '',
            'security' => is_object($stream) ? (string)($stream->security ?? 'none') : 'none',
        ];
    }
    return null;
}


function farid_switchClientExistsInInbound($serverId, $inboundId, $uuid = '', $email = ''){
    $serverId = intval($serverId);
    $inboundId = intval($inboundId);
    $uuid = trim((string)$uuid);
    $email = trim((string)$email);
    if($serverId <= 0 || $inboundId <= 0 || ($uuid === '' && $email === '')) return false;

    $json = getJson($serverId);
    if(!$json || !isset($json->obj) || !is_array($json->obj)) return false;
    foreach($json->obj as $row){
        if(intval($row->id ?? 0) != $inboundId) continue;
        $settings = $row->settings ?? '{}';
        if(is_string($settings)) $settings = @json_decode($settings, true);
        elseif(is_object($settings)) $settings = json_decode(json_encode($settings), true);
        if(!is_array($settings)) return false;
        $clients = $settings['clients'] ?? [];
        if(!is_array($clients)) return false;
        foreach($clients as $client){
            if(is_object($client)) $client = json_decode(json_encode($client), true);
            if(!is_array($client)) continue;
            $cid = trim((string)($client['id'] ?? ''));
            $pwd = trim((string)($client['password'] ?? ''));
            $cem = trim((string)($client['email'] ?? ''));
            if($uuid !== '' && ($cid === $uuid || $pwd === $uuid)) return true;
            if($email !== '' && $cem === $email) return true;
        }
        return false;
    }
    return false;
}

function farid_switchSanaeiNewAttachClientToInbound($serverId, $inboundId, $clientArr){
    global $connection;
    $serverId = intval($serverId);
    $inboundId = intval($inboundId);
    if($serverId <= 0 || $inboundId <= 0 || !is_array($clientArr)) return null;

    $stmt = @$connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverInfo || ($serverInfo['type'] ?? '') !== 'sanaei_new') return null;

    $panelUrl = rtrim((string)($serverInfo['panel_url'] ?? ''), '/');
    if($panelUrl === '') return null;
    $serverName = (string)($serverInfo['username'] ?? '');
    $serverPass = (string)($serverInfo['password'] ?? '');

    $loginUrl = $panelUrl . '/login';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['username'=>$serverName, 'password'=>$serverPass]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, function_exists('v2raystore_panelLoginHeaders') ? v2raystore_panelLoginHeaders($curl, $loginUrl) : []);
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $loginResponseRaw = curl_exec($curl);
    if($loginResponseRaw === false){ curl_close($curl); return null; }
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($loginResponseRaw, 0, $headerSize);
    $body = substr($loginResponseRaw, $headerSize);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1] ?? '';
    $loginJson = json_decode((string)$body, true);
    if(empty($session) || !is_array($loginJson) || empty($loginJson['success'])){ curl_close($curl); return is_array($loginJson) ? (object)$loginJson : null; }

    $clientArr['email'] = (string)($clientArr['email'] ?? ('sw-' . time() . rand(100,999)));
    if(!isset($clientArr['enable'])) $clientArr['enable'] = true;
    if(!isset($clientArr['subId']) || $clientArr['subId'] === '') $clientArr['subId'] = RandomString(16);

    $attempts = [
        [
            'url' => $panelUrl . '/panel/api/inbounds/addClient',
            'payload' => ['id' => $inboundId, 'settings' => ['clients' => [$clientArr]]],
        ],
        [
            'url' => $panelUrl . '/panel/api/inbounds/addClient',
            'payload' => ['id' => $inboundId, 'settings' => json_encode(['clients' => [$clientArr]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ],
        [
            'url' => $panelUrl . '/panel/api/clients/add',
            'payload' => ['client' => $clientArr, 'inboundIds' => [$inboundId], 'inbounds' => [$inboundId], 'inbound_ids' => [$inboundId]],
        ],
    ];

    $last = null;
    foreach($attempts as $attempt){
        if(function_exists('v2raystore_sanaeiNewJsonPost')){
            v2raystore_sanaeiNewJsonPost($curl, $attempt['url'], $session, $attempt['payload']);
        }else{
            curl_setopt_array($curl, [
                CURLOPT_URL => $attempt['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($attempt['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept: application/json', 'Content-Type: application/json', 'Cookie: ' . $session],
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
            ]);
        }
        $raw = curl_exec($curl);
        $decoded = json_decode((string)$raw);
        $last = $decoded ?: $raw;
        if(is_object($decoded) && !empty($decoded->success)) break;
        $msg = is_object($decoded) ? (string)($decoded->msg ?? '') : (string)$raw;
        // Duplicate email/id can still mean the client exists globally; the caller verifies actual inbound attachment.
        if(stripos($msg, 'duplicate') !== false) break;
    }
    curl_close($curl);
    return $last;
}

function farid_switchBuildInboundLinks($serverId, $uuid, $protocol, $remark, $targetInbound, $inboundId, $custom){
    $custom = is_array($custom) ? $custom : [];
    $customPath = $custom['customPath'] ?? false;
    $customPort = $custom['customPort'] ?? 0;
    // برای sanaei_new نباید لینک از لیست global clients گرفته شود؛ چون ممکن است لینک قدیمی/پورت قبلی برگردد.
    // مقدار رشته خالی باعث می‌شود getConnectionLink لینک را از خود inbound مقصد بسازد.
    $customSni = array_key_exists('customSni', $custom) ? $custom['customSni'] : '';
    if($customSni === null) $customSni = '';
    $customDomain = $custom['customDomain'] ?? null;
    return getConnectionLink(
        intval($serverId),
        (string)$uuid,
        (string)$protocol,
        (string)$remark,
        intval($targetInbound['port'] ?? 0),
        (string)($targetInbound['netType'] ?? ''),
        intval($inboundId),
        false,
        $customPath,
        $customPort,
        $customSni,
        $customDomain
    );
}

function farid_switchGetOrderLiveState($order){
    if(!is_array($order)) return ['ok'=>false, 'message'=>'اطلاعات سفارش نامعتبر است.'];
    $serverId = intval($order['server_id'] ?? 0);
    $serverConfig = farid_switchGetServerConfig($serverId);
    if(!$serverConfig) return ['ok'=>false, 'message'=>'تنظیمات سرور فعلی پیدا نشد.'];
    $serverType = (string)($serverConfig['type'] ?? '');
    $uuid = trim((string)($order['uuid'] ?? ''));
    $remark = (string)($order['remark'] ?? '');
    $inboundId = intval($order['inbound_id'] ?? 0);

    if(farid_switchIsMarzbanType($serverType)){
        $info = getMarzbanUser($serverId, $remark);
        if(!$info || isset($info->detail)) return ['ok'=>false, 'message'=>'کانفیگ در پنل مرزبان پیدا نشد یا پنل پاسخ نامعتبر داد.'];
        $total = intval($info->data_limit ?? 0);
        $used = intval($info->used_traffic ?? 0);
        $remaining = max(0, $total - $used);
        $expire = intval($info->expire ?? 0);
        return [
            'ok' => true,
            'panel_type' => 'marzban',
            'remaining_bytes' => $remaining,
            'remaining_gb' => round($remaining / 1073741824, 2),
            'expire_seconds' => $expire,
            'expire_millis' => $expire > 0 ? $expire * 1000 : 0,
            'protocol' => 'marzban',
            'port' => 0,
            'netType' => '',
            'security' => '',
            'limitIp' => 0,
            'flow' => '',
            'uniqid' => $uuid,
        ];
    }

    if($inboundId > 0){
        $inboundInfo = farid_switchFindXuiInboundInfo($serverId, $inboundId, $uuid);
        if(!$inboundInfo || empty($inboundInfo['client'])) return ['ok'=>false, 'message'=>'کلاینت داخل inbound فعلی پیدا نشد.'];
        $preview = deleteClient($serverId, $inboundId, $uuid, 0);
        if($preview === null || !is_array($preview)) return ['ok'=>false, 'message'=>'امکان دریافت وضعیت حجم از پنل فعلی وجود ندارد.'];
        $total = intval($preview['total'] ?? 0);
        $up = intval($preview['up'] ?? 0);
        $down = intval($preview['down'] ?? 0);
        $remaining = max(0, $total - $up - $down);
        $client = $inboundInfo['client'];
        $clientId = trim((string)($client->id ?? ($client->password ?? $uuid)));
        $clientSubId = trim((string)($client->subId ?? ($client->sub_id ?? ($preview['subId'] ?? ($preview['sub_id'] ?? '')))));
        return [
            'ok' => true,
            'panel_type' => 'xui',
            'remaining_bytes' => $remaining,
            'remaining_gb' => round($remaining / 1073741824, 2),
            'expire_seconds' => farid_switchExpiryToSeconds($preview['expiryTime'] ?? 0),
            'expire_millis' => farid_switchExpiryToMillis($preview['expiryTime'] ?? 0),
            'protocol' => $inboundInfo['protocol'],
            'port' => $inboundInfo['port'],
            'netType' => $inboundInfo['netType'],
            'security' => $inboundInfo['security'],
            'limitIp' => intval($preview['limitIp'] ?? ($client->limitIp ?? 0)),
            'flow' => (string)($preview['flow'] ?? ($client->flow ?? '')),
            'uniqid' => $clientId !== '' ? $clientId : $uuid,
            'sub_id' => $clientSubId,
        ];
    }

    $preview = deleteInbound($serverId, $uuid, 0);
    if($preview === null || !is_array($preview)) return ['ok'=>false, 'message'=>'امکان دریافت وضعیت این کانفیگ از پنل فعلی وجود ندارد.'];
    $remaining = intval($preview['volume'] ?? 0);
    if($remaining <= 0){
        $remaining = max(0, intval($preview['total'] ?? 0) - intval($preview['up'] ?? 0) - intval($preview['down'] ?? 0));
    }
    return [
        'ok' => true,
        'panel_type' => 'xui',
        'remaining_bytes' => $remaining,
        'remaining_gb' => round($remaining / 1073741824, 2),
        'expire_seconds' => farid_switchExpiryToSeconds($preview['expiryTime'] ?? 0),
        'expire_millis' => farid_switchExpiryToMillis($preview['expiryTime'] ?? 0),
        'protocol' => (string)($preview['protocol'] ?? ($order['protocol'] ?? '')),
        'port' => intval($preview['port'] ?? 0),
        'netType' => (string)($preview['netType'] ?? ''),
        'security' => (string)($preview['security'] ?? 'none'),
        'limitIp' => 0,
        'flow' => '',
        'uniqid' => (string)($preview['uniqid'] ?? $uuid),
        'sub_id' => trim((string)($preview['subId'] ?? ($preview['sub_id'] ?? ''))),
    ];
}

function farid_switchMakeRemark($targetServerId, $ownerId){
    global $connection, $botState;
    $targetServerId = intval($targetServerId);
    $ownerId = intval($ownerId);
    $stmt = $connection->prepare("SELECT `remark` FROM `server_info` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param('i', $targetServerId);
    $stmt->execute();
    $srvRemark = (string)($stmt->get_result()->fetch_assoc()['remark'] ?? 'srv');
    $stmt->close();
    if(($botState['remark'] ?? '') == 'digits') return $srvRemark . '-' . rand(10000,99999);
    return $srvRemark . '-' . $ownerId . '-' . rand(1111,99999);
}

function farid_switchPlanCustomFields($plan){
    if(!is_array($plan)) $plan = [];
    return [
        'customPath' => $plan['custom_path'] ?? null,
        'customPort' => $plan['custom_port'] ?? 0,
        'customSni' => $plan['custom_sni'] ?? null,
        'customDomain' => $plan['custom_domain'] ?? null,
        'flow' => (isset($plan['flow']) && $plan['flow'] != 'None') ? $plan['flow'] : '',
    ];
}

function farid_switchOrderServer($orderId, $targetServerId, $actorId, $isAdminSwitch = false){
    global $connection, $botState;
    $orderId = intval($orderId);
    $targetServerId = intval($targetServerId);
    $actorId = intval($actorId);
    $isAdminSwitch = (bool)$isAdminSwitch;
    $order = v2raystore_switchGetOrder($orderId);
    if(!$order) return ['ok'=>false, 'message'=>'کانفیگ پیدا نشد.'];
    $ownerId = intval($order['userid'] ?? 0);
    $canAccessSwitchOrder = function_exists('v2raystore_canActorSwitchOrder') ? v2raystore_canActorSwitchOrder($order, $actorId, $isAdminSwitch) : ($isAdminSwitch || $ownerId === $actorId);
    if(!$canAccessSwitchOrder) return ['ok'=>false, 'message'=>'شما به این کانفیگ دسترسی ندارید.'];
    if(!$isAdminSwitch && function_exists('v2raystore_canUseSwitchLocation') && !v2raystore_canUseSwitchLocation($order, $actorId, false)) return ['ok'=>false, 'message'=>'تغییر سرور برای این سرویس در حال حاضر غیرفعال است.'];
    if(!$isAdminSwitch && !function_exists('v2raystore_canUseSwitchLocation') && (($botState['switchLocationState'] ?? 'off') != 'on')) return ['ok'=>false, 'message'=>'تغییر سرور در حال حاضر غیرفعال است.'];
    if(intval($order['amount'] ?? 0) <= 0 && intval($order['agent_bought'] ?? 0) == 0) return ['ok'=>false, 'message'=>'اکانت تست یا سرویس رایگان قابل تغییر سرور نیست.'];
    if(intval($order['server_id'] ?? 0) === $targetServerId) return ['ok'=>false, 'message'=>'سرور مقصد با سرور فعلی یکی است.'];
    if(intval($order['expire_date'] ?? 0) > 0 && intval($order['expire_date']) < time()) return ['ok'=>false, 'message'=>'سرویس غیرفعال است؛ ابتدا باید تمدید شود.'];

    $targetInfo = farid_switchGetServerInfo($targetServerId);
    if(!$targetInfo || intval($targetInfo['active'] ?? 0) != 1 || intval($targetInfo['state'] ?? 0) != 1) return ['ok'=>false, 'message'=>'سرور مقصد فعال نیست.'];
    if(!$isAdminSwitch && intval($targetInfo['ucount'] ?? 0) <= 0) return ['ok'=>false, 'message'=>'ظرفیت سرور مقصد تمام شده است.'];

    $settings = v2raystore_getServerSwitchSettings();
    if(!$isAdminSwitch && intval($settings['daily_limit']) > 0 && v2raystore_switchUsedToday($orderId, $ownerId) >= intval($settings['daily_limit'])){
        return ['ok'=>false, 'message'=>'برای هر کانفیگ فقط ' . intval($settings['daily_limit']) . ' بار در روز امکان تغییر سرور دارید.'];
    }

    $oldServerId = intval($order['server_id'] ?? 0);
    $oldConfig = farid_switchGetServerConfig($oldServerId);
    $targetConfig = farid_switchGetServerConfig($targetServerId);
    if(!$oldConfig || !$targetConfig) return ['ok'=>false, 'message'=>'تنظیمات سرور مبدا یا مقصد پیدا نشد.'];
    $oldType = (string)($oldConfig['type'] ?? '');
    $targetType = (string)($targetConfig['type'] ?? '');
    // لینک/توکن ساب قبلی را بدون درخواست اضافه به پنل نگه می‌داریم تا تغییر لوکیشن هنگ نکند.
    $oldSubSnapshot = function_exists('farid_switchBuildSubLinkNoPanel') ? farid_switchBuildSubLinkNoPanel($order, $oldConfig) : ['link'=>'', 'sub_id'=>'', 'token'=>trim((string)($order['token'] ?? ''))];
    $oldSubLink = trim((string)($oldSubSnapshot['link'] ?? ''));
    $oldSubId = trim((string)($oldSubSnapshot['sub_id'] ?? ''));

    // برای جلوگیری از باگ، جابه‌جایی بین مرزبان و X-UI را انجام نمی‌دهیم.
    if(farid_switchIsMarzbanType($oldType) xor farid_switchIsMarzbanType($targetType)){
        return ['ok'=>false, 'message'=>'تغییر مستقیم بین مرزبان و X-UI پشتیبانی نمی‌شود. سرور مقصد باید هم‌نوع سرور فعلی باشد.'];
    }

    $live = farid_switchGetOrderLiveState($order);
    if(empty($live['ok'])) return ['ok'=>false, 'message'=>$live['message'] ?? 'امکان بررسی وضعیت فعلی سرویس وجود ندارد.'];

    // در سنایی جدید، شناسه اشتراک واقعی داخل خود Client است.
    // همان را از همان درخواست وضعیت فعلی می‌خوانیم و برای مقصد نگه می‌داریم؛
    // پس با هر تغییر لوکیشن، Subscription ID و لینک ساب کاربر عوض نمی‌شود.
    $liveSubId = trim((string)($live['sub_id'] ?? ''));
    if($liveSubId !== ''){
        $oldSubId = $liveSubId;
        if($oldSubLink !== '' && preg_match('#^https?://#i', $oldSubLink) && function_exists('farid_switchReplaceSubIdInLink')){
            $oldSubLink = farid_switchReplaceSubIdInLink($oldSubLink, $oldSubId);
        }elseif(function_exists('farid_switchBuildSubLinkNoPanel')){
            $tmpOrderForSub = $order;
            $tmpOrderForSub['token'] = $oldSubId;
            $tmpSubSnapshot = farid_switchBuildSubLinkNoPanel($tmpOrderForSub, $oldConfig);
            $tmpSubLink = trim((string)($tmpSubSnapshot['link'] ?? ''));
            if($tmpSubLink !== '') $oldSubLink = $tmpSubLink;
        }
    }

    $remainingBytes = intval($live['remaining_bytes'] ?? 0);
    $remainingGb = $remainingBytes / 1073741824;
    if($remainingBytes <= 0) return ['ok'=>false, 'message'=>'حجم سرویس تمام شده است.'];

    $cost = v2raystore_calcSwitchDeductionGb($order, $targetServerId, $remainingGb);
    $changeType = ($cost['change_type'] ?? 'deduct');
    $changeGb = floatval($cost['change_gb'] ?? ($cost['deduct_gb'] ?? 0));
    $changeBytes = (int)floor($changeGb * 1073741824);
    if($changeType === 'deduct' && $remainingBytes <= $changeBytes){
        return ['ok'=>false, 'message'=>'حجم باقی‌مانده برای این تغییر کافی نیست. حجم باقی‌مانده: ' . v2raystore_switchFormatGb($remainingGb) . 'GB، کسر موردنیاز: ' . v2raystore_switchFormatGb($changeGb) . 'GB'];
    }
    $newBytes = ($changeType === 'add') ? ($remainingBytes + $changeBytes) : max(0, $remainingBytes - $changeBytes);
    $newVolumeGb = round($newBytes / 1073741824, 2);
    if($newVolumeGb <= 0) return ['ok'=>false, 'message'=>'بعد از تغییر سرور، حجم قابل استفاده‌ای باقی نمی‌ماند.'];

    $targetPlan = $cost['target_plan'] ?? null;
    if(!is_array($targetPlan) || intval($targetPlan['server_id'] ?? 0) !== $targetServerId){
        $currentPlanForSwitch = function_exists('v2raystore_switchGetPlan') ? v2raystore_switchGetPlan($order['fileid'] ?? 0) : null;
        $targetPlan = function_exists('v2raystore_switchFindEquivalentPlan') ? v2raystore_switchFindEquivalentPlan($currentPlanForSwitch, $targetServerId) : null;
    }
    if(!is_array($targetPlan) || intval($targetPlan['server_id'] ?? 0) !== $targetServerId){
        return ['ok'=>false, 'message'=>'برای سرور مقصد پلن فعال متناظر پیدا نشد. لطفاً برای سرور مقصد یک پلن فعال با اینباند صحیح تعریف کنید.'];
    }
    $targetPlanId = intval($targetPlan['id'] ?? 0);
    if($targetPlanId <= 0){
        return ['ok'=>false, 'message'=>'شناسه پلن مقصد معتبر نیست. لطفاً تنظیمات پلن سرور مقصد را بررسی کنید.'];
    }
    $custom = farid_switchPlanCustomFields($targetPlan);
    $newRemark = farid_switchMakeRemark($targetServerId, $ownerId);
    $oldRemark = (string)($order['remark'] ?? '');
    $uuid = trim((string)($order['uuid'] ?? ''));
    $sourceInboundId = intval($order['inbound_id'] ?? 0);
    $targetInboundIds = function_exists('v2raystore_planInboundIds') ? v2raystore_planInboundIds($targetPlan, true) : [];
    $targetInboundId = !empty($targetInboundIds) ? intval($targetInboundIds[0]) : intval($targetPlan['inbound_id'] ?? 0);
    $links = [];
    $deleteOldAction = null;
    $newToken = trim((string)($order['token'] ?? ''));
    if($newToken === '' && $oldSubId !== '') $newToken = $oldSubId;
    // اگر لینک کامل ساب قبلی را می‌توانیم سریع بسازیم، همان را در سفارش نگه می‌داریم
    // تا بعد از تغییر لوکیشن هم آدرس ساب در جزئیات سرویس عوض نشود.
    if($oldSubLink !== '' && $oldSubId !== '' && preg_match('#^https?://#i', $oldSubLink) && !farid_switchIsMarzbanType($oldType)){
        $newToken = $oldSubLink;
    }
    $newUuid = $uuid;
    $newProtocol = (string)($order['protocol'] ?? ($live['protocol'] ?? ''));

    if(farid_switchIsMarzbanType($oldType) && farid_switchIsMarzbanType($targetType)){
        $expireSeconds = intval($live['expire_seconds'] ?? 0);
        $daysLeft = $expireSeconds > 0 ? max(1, (int)ceil(($expireSeconds - time()) / 86400)) : 3650;
        $response = addMarzbanUser($targetServerId, $newRemark, $newVolumeGb, $daysLeft, $targetPlanId);
        if(!is_object($response) || empty($response->success)){
            if(is_object($response) && ($response->msg ?? '') == 'User already exists'){
                $newRemark .= rand(1111,99999);
                $response = addMarzbanUser($targetServerId, $newRemark, $newVolumeGb, $daysLeft, $targetPlanId);
            }
        }
        if(!is_object($response) || empty($response->success)){
            $msg = is_object($response) ? ($response->msg ?? 'خطای نامشخص') : 'پاسخ نامعتبر پنل مقصد';
            return ['ok'=>false, 'message'=>'ساخت کانفیگ در سرور مقصد انجام نشد: ' . $msg];
        }
        $links = is_array($response->vray_links ?? null) ? $response->vray_links : [];
        $panelToken = str_replace('/sub/', '', (string)($response->sub_link ?? ''));
        if($newToken === '') $newToken = ($oldSubId !== '' ? $oldSubId : $panelToken);
        $newUuid = $panelToken !== '' ? $panelToken : ($oldSubId !== '' ? $oldSubId : $newToken);
        $deleteOldAction = ['type'=>'marzban'];
    }else{
        if($targetInboundId > 0){
            $targetInbound = farid_switchFindXuiInboundInfo($targetServerId, $targetInboundId, '');
            if(!$targetInbound) return ['ok'=>false, 'message'=>'Inbound مقصد با آیدی ' . $targetInboundId . ' پیدا نشد. لطفاً inbound_id پلن سرور مقصد را بررسی کنید.'];
            $protocol = $targetInbound['protocol'] ?: ($live['protocol'] ?? $newProtocol);
            $idLabel = ($protocol == 'trojan') ? 'password' : 'id';
            $flow = trim((string)($custom['flow'] !== '' ? $custom['flow'] : ($live['flow'] ?? '')));
            $newArr = [
                $idLabel => $uuid,
                'email' => $newRemark,
                'enable' => true,
                'limitIp' => intval($live['limitIp'] ?? 0),
                'totalGB' => $newBytes,
                'expiryTime' => intval($live['expire_millis'] ?? 0),
                'subId' => ($oldSubId !== '' ? $oldSubId : RandomString(16)),
            ];
            if($flow !== '') $newArr['flow'] = $flow;
            $response = addInboundAccount($targetServerId, '', $targetInboundId, 1, $newRemark, 0, intval($live['limitIp'] ?? 0), $newArr, $targetPlanId);
            if(is_object($response) && empty($response->success) && strstr((string)($response->msg ?? ''), 'Duplicate email')){
                $newRemark .= RandomString(4, 'small');
                $newArr['email'] = $newRemark;
                $response = addInboundAccount($targetServerId, '', $targetInboundId, 1, $newRemark, 0, intval($live['limitIp'] ?? 0), $newArr, $targetPlanId);
            }
            if($response === null) return ['ok'=>false, 'message'=>'اتصال به سرور مقصد برقرار نشد.'];
            if($response === 'inbound not Found') return ['ok'=>false, 'message'=>'Inbound مقصد پیدا نشد.'];
            if(!is_object($response) || empty($response->success)){
                $msg = is_object($response) ? ($response->msg ?? 'خطای نامشخص') : 'پاسخ نامعتبر پنل مقصد';
                return ['ok'=>false, 'message'=>'ساخت کانفیگ در سرور مقصد انجام نشد: ' . $msg];
            }

            if(($targetType ?? '') === 'sanaei_new' && !farid_switchClientExistsInInbound($targetServerId, $targetInboundId, $uuid, $newRemark)){
                farid_switchSanaeiNewAttachClientToInbound($targetServerId, $targetInboundId, $newArr);
                if(!farid_switchClientExistsInInbound($targetServerId, $targetInboundId, $uuid, $newRemark)){
                    return ['ok'=>false, 'message'=>'کانفیگ در سنایی جدید ساخته شد اما به inbound مقصد وصل نشد. لطفاً inbound_id پلن مقصد را بررسی کنید.'];
                }
            }

            $targetInbound = farid_switchFindXuiInboundInfo($targetServerId, $targetInboundId, $uuid) ?: $targetInbound;
            if(function_exists('v2raystore_buildPlanInboundConnectionLinks')){
                $links = v2raystore_buildPlanInboundConnectionLinks(
                    $targetServerId,
                    $uuid,
                    $protocol,
                    $newRemark,
                    intval($targetInbound['port'] ?? 0),
                    (string)($targetInbound['netType'] ?? ''),
                    $targetInboundId,
                    false,
                    $custom['customPath'] ?? false,
                    $custom['customPort'] ?? 0,
                    array_key_exists('customSni', $custom) ? ($custom['customSni'] ?? '') : '',
                    $custom['customDomain'] ?? null,
                    $targetPlanId
                );
            }else{
                $links = farid_switchBuildInboundLinks($targetServerId, $uuid, $protocol, $newRemark, $targetInbound, $targetInboundId, $custom);
            }
            $deleteOldAction = ($sourceInboundId > 0)
                ? ['type'=>'client', 'inbound_id'=>$sourceInboundId, 'uuid'=>$uuid]
                : ['type'=>'inbound', 'uuid'=>$uuid];
            $newProtocol = $protocol;
        }else{
            $uniqid = (string)($live['uniqid'] ?? $uuid);
            $port = intval($live['port'] ?? rand(1111,65000));
            $protocol = (string)($live['protocol'] ?? $newProtocol);
            $netType = (string)($live['netType'] ?? 'tcp');
            $security = (string)($live['security'] ?? 'none');
            $expireMs = intval($live['expire_millis'] ?? 0);
            $response = addUser($targetServerId, $uniqid, $protocol, $port, $expireMs, $newRemark, $newVolumeGb, $netType, $security, false, $targetPlanId);
            if(is_object($response) && empty($response->success)){
                if(strstr((string)($response->msg ?? ''), 'Duplicate email')) $newRemark .= RandomString(4, 'small');
                if(strstr((string)($response->msg ?? ''), 'Port already exists')) $port = rand(1111,65000);
                $response = addUser($targetServerId, $uniqid, $protocol, $port, $expireMs, $newRemark, $newVolumeGb, $netType, $security, false, $targetPlanId);
            }
            if($response === null) return ['ok'=>false, 'message'=>'اتصال به سرور مقصد برقرار نشد.'];
            if(!is_object($response) || empty($response->success)){
                $msg = is_object($response) ? ($response->msg ?? 'خطای نامشخص') : 'پاسخ نامعتبر پنل مقصد';
                return ['ok'=>false, 'message'=>'ساخت کانفیگ در سرور مقصد انجام نشد: ' . $msg];
            }
            $links = getConnectionLink($targetServerId, $uniqid, $protocol, $newRemark, $port, $netType, 0, false, $custom['customPath'], $custom['customPort'], $custom['customSni'], $custom['customDomain']);
            $deleteOldAction = ['type'=>'inbound', 'uuid'=>$uuid];
            $newUuid = $uniqid;
            $newProtocol = $protocol;
        }
    }

    $links = v2raystore_normalizeConfigLinksArray($links);
    if(empty($links)) return ['ok'=>false, 'message'=>'کانفیگ در مقصد ساخته شد، ولی لینک خروجی ساخته نشد. لطفاً از پنل بررسی کنید.'];

    if(is_array($deleteOldAction)){
        if(($deleteOldAction['type'] ?? '') === 'marzban'){
            deleteMarzban($oldServerId, $oldRemark);
        }elseif(($deleteOldAction['type'] ?? '') === 'client'){
            deleteClient($oldServerId, intval($deleteOldAction['inbound_id'] ?? 0), (string)($deleteOldAction['uuid'] ?? $uuid), 1);
        }elseif(($deleteOldAction['type'] ?? '') === 'inbound'){
            deleteInbound($oldServerId, (string)($deleteOldAction['uuid'] ?? $uuid), 1);
        }
    }

    $linkJson = json_encode($links, JSON_UNESCAPED_UNICODE);
    $newInboundId = intval($targetInboundId ?? 0);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `fileid` = ?, `inbound_id` = ?, `token` = ?, `uuid` = ?, `protocol` = ?, `link` = ?, `remark` = ?, `notif` = 0 WHERE `id` = ?");
    $stmt->bind_param('iiisssssi', $targetServerId, $targetPlanId, $newInboundId, $newToken, $newUuid, $newProtocol, $linkJson, $newRemark, $orderId);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param('i', $oldServerId);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = IF(`ucount` > 0, `ucount` - 1, `ucount`) WHERE `id` = ?");
    $stmt->bind_param('i', $targetServerId);
    $stmt->execute();
    $stmt->close();

    $logChangeGb = ($changeType === 'add') ? (-1 * $changeGb) : $changeGb;
    v2raystore_recordSwitchLog($orderId, $ownerId, $oldServerId, $targetServerId, $oldRemark, $newRemark, $logChangeGb);

    $newOrderForDelivery = $order;
    $newOrderForDelivery['server_id'] = $targetServerId;
    $newOrderForDelivery['fileid'] = $targetPlanId;
    $newOrderForDelivery['inbound_id'] = $newInboundId;
    $newOrderForDelivery['token'] = $newToken;
    $newOrderForDelivery['uuid'] = $newUuid;
    $newOrderForDelivery['protocol'] = $newProtocol;
    $newOrderForDelivery['link'] = $linkJson;
    $newOrderForDelivery['remark'] = $newRemark;
    $deliveryOptions = function_exists('v2raystore_getAgentDeliveryLinkOptionsForOrder') ? v2raystore_getAgentDeliveryLinkOptionsForOrder($newOrderForDelivery) : ['config'=>true, 'sub'=>false];
    if(function_exists('v2raystore_getServerDeliveryMode') && function_exists('v2raystore_deliveryModeNormalize') && function_exists('v2raystore_deliveryModeToOptions')){
        $targetServerDeliveryMode = v2raystore_deliveryModeNormalize(v2raystore_getServerDeliveryMode($targetServerId));
        if($targetServerDeliveryMode === 'sub' && (empty($deliveryOptions['sub']) || !empty($deliveryOptions['config']))){
            $deliveryOptions = ['config'=>false, 'sub'=>true];
        }
    }
    $subLink = '';
    if(!empty($deliveryOptions['sub'])){
        // برای جلوگیری از هنگ، اینجا هم سراغ پنل نمی‌رویم.
        // اولویت با لینک ساب قبلی است تا بعد از تغییر لوکیشن آدرس ساب کاربر عوض نشود.
        $subLink = trim((string)$oldSubLink);
        if($subLink === '' && preg_match('#^https?://#i', (string)$newToken)){
            $subLink = trim((string)$newToken);
        }
        if($subLink === '' && function_exists('farid_switchBuildSubLinkNoPanel')){
            $newSubSnapshot = farid_switchBuildSubLinkNoPanel($newOrderForDelivery, $targetConfig);
            $subLink = trim((string)($newSubSnapshot['link'] ?? ''));
        }
    }

    return [
        'ok' => true,
        'order_id' => $orderId,
        'owner_id' => $ownerId,
        'old_server_id' => $oldServerId,
        'target_server_id' => $targetServerId,
        'target_title' => v2raystore_switchGetServerTitle($targetServerId),
        'old_remark' => $oldRemark,
        'new_remark' => $newRemark,
        'links' => $links,
        'sub_link' => $subLink,
        'delivery_options' => $deliveryOptions,
        'deduct_gb' => ($changeType === 'deduct' ? $changeGb : 0),
        'change_gb' => $changeGb,
        'change_type' => $changeType,
        'remaining_gb_before' => round($remainingGb, 2),
        'remaining_gb_after' => round($newVolumeGb, 2),
    ];
}

function farid_sendUpdatedConfigToUser($userId, $remark, $links, $afterMessage = null, $title = null){
    global $botState, $botUrl;

    $userId = intval($userId);
    if($userId <= 0) return;

    if($links == null) return;

    if(function_exists('v2raystore_normalizeConfigLinksArray')){
        $links = v2raystore_normalizeConfigLinksArray($links);
    }else{
        if(is_string($links)) $links = [$links];
        elseif(is_object($links)) $links = (array)$links;
        elseif(!is_array($links)) return;
        $links = array_values(array_filter(array_map('strval', $links)));
    }
    if(empty($links)) return;

    $title = $title ?: '✅ کانفیگ‌های سرویس شما به‌روزرسانی شد';

    // اگر چند دامنه/چند لینک وجود دارد، همه را در یک پیام واحد ارسال کن.
    if(count($links) > 1 && ($botState['configLinkState'] ?? '') != 'off'){
        if(function_exists('v2raystore_buildMultiDomainConfigMessage')){
            $text = v2raystore_buildMultiDomainConfigMessage($remark, $links, '', $title);
        }else{
            $safeRemark = htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8');
            $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
            $text = "{$safeTitle}
🔮 نام سرویس: <b>{$safeRemark}</b>
";
            foreach($links as $link){
                $text .= "
<code>" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "</code>
";
            }
        }
        sendMessage($text, null, 'HTML', $userId);
    }else{
        include_once "phpqrcode/qrlib.php";

        foreach($links as $link){
            if(empty($link)) continue;

            $safeRemark = htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars((string)$link, ENT_QUOTES, 'UTF-8');
            $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
            $text = ($botState['configLinkState'] ?? '') != "off"
                ? ("{$safeTitle}: <b>{$safeRemark}</b>

<code>{$safeLink}</code>")
                : ("{$safeTitle}: <b>{$safeRemark}</b>");

            $file = RandomString() . ".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;

            QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);

            if(function_exists("addBorderImage")){
                addBorderImage($file);
            }

            if(file_exists("settings/QRCode.jpg")){
                $backgroundImage = @imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = @imagecreatefrompng($file);
                if($backgroundImage && $qrImage){
                    $qrSize = ['width'=>imagesx($qrImage), 'height'=>imagesy($qrImage)];
                    imagecopy($backgroundImage, $qrImage, 300, 300, 0, 0, $qrSize['width'], $qrSize['height']);
                    imagepng($backgroundImage, $file);
                    imagedestroy($backgroundImage);
                    imagedestroy($qrImage);
                }else{
                    if($backgroundImage) imagedestroy($backgroundImage);
                    if($qrImage) imagedestroy($qrImage);
                }
            }

            sendPhoto($botUrl . $file, $text, null, "HTML", $userId);

            if(file_exists($file)) unlink($file);
            usleep(350000);
        }
    }

    $msg = $afterMessage;
    if($msg === null){
        $msg = farid_getUpdateAfterMessage();
    }
    if(strlen(trim(strval($msg))) > 0){
        sendMessage($msg, null, "HTML", $userId);
    }
}


/* ======================================================================
   ✅ Helper functions for X-UI Expired / Near-Expiry messaging
   ====================================================================== */

function farid_xuiMsg_getNearDaysThreshold(){
    $raw = farid_getSettingValue("XUI_NEAR_EXPIRE_DAYS");
    $days = ($raw === null) ? 3 : intval($raw);
    if($days < 0) $days = 0;
    if($days > 3650) $days = 3650;
    return $days;
}

function farid_xuiMsg_getNearGbThreshold(){
    $raw = farid_getSettingValue("XUI_NEAR_EXPIRE_GB");
    $gb = ($raw === null) ? 3 : floatval($raw);
    if($gb < 0) $gb = 0;
    if($gb > 10240) $gb = 10240;
    // جلوگیری از نمایش اعشار خیلی زیاد
    return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
}

function farid_xuiMsg_parseExpirySeconds($expiryVal){
    if($expiryVal === null) return 0;
    // اگر رشته خالی یا صفر بود
    $s = trim(strval($expiryVal));
    if($s === '' || $s === '0') return 0;

    // حذف کاراکترهای غیر عددی
    if(!preg_match('/^\d+$/', $s)){
        $s = preg_replace('/\D+/', '', $s);
    }
    if($s === '') return 0;

    // اگر طول بیشتر از 10 باشد، احتمالاً میلی‌ثانیه است
    if(strlen($s) > 10){
        return intval(substr($s, 0, -3));
    }

    return intval($s);
}


function farid_xuiMsg_collectAllXuiAccounts(){
    global $connection;

    $accounts = [];

    $stmt = $connection->prepare("SELECT `id`,`type` FROM `server_config`");
    $stmt->execute();
    $servers = $stmt->get_result();
    $stmt->close();

    if(!$servers) return [];

    while($srv = $servers->fetch_assoc()){
        $sid = intval($srv['id']);
        $stype = $srv['type'] ?? '';

        // فقط X-UI / 3X-UI
        if($stype == 'marzban') continue;

        $resp = getJson($sid);
        if(!$resp || !isset($resp->success) || !$resp->success) continue;
        if(!isset($resp->obj)) continue;

        $list = $resp->obj;
        if(is_object($list)){
            // در صورت برگشت آبجکت، تبدیل به آرایه (نادر ولی برای اطمینان)
            $list = [$list];
        }
        if(!is_array($list) || count($list) == 0) continue;

        $hasClientStats = isset($list[0]->clientStats);

        foreach($list as $inb){
            if(!is_object($inb)) continue;

            $inboundId = intval($inb->id ?? 0);

            $settingsRaw = $inb->settings ?? '{}';
            $settingsArr = json_decode($settingsRaw, true);
            if(!is_array($settingsArr)) $settingsArr = [];

            $clients = $settingsArr['clients'] ?? [];
            if(!is_array($clients)) $clients = [];

            if($hasClientStats && isset($inb->clientStats) && is_array($inb->clientStats)){
                $statsArr = $inb->clientStats;

                foreach($clients as $cl){
                    if(!is_array($cl)) continue;

                    $uuid = $cl['id'] ?? ($cl['password'] ?? null);
                    if(empty($uuid)) continue;

                    $email = $cl['email'] ?? null;
                    $remark = $email;
                    if($remark === null || $remark === '') $remark = $inb->remark ?? '';

                    // پیدا کردن stats مربوط به همین کلاینت بر اساس email
                    $statObj = null;
                    if($email !== null && $email !== ''){
                        foreach($statsArr as $st){
                            if(is_object($st) && isset($st->email) && $st->email == $email){
                                $statObj = $st;
                                break;
                            }
                        }
                    }

                    if($statObj){
                        $up = intval($statObj->up ?? 0);
                        $down = intval($statObj->down ?? 0);

                        $total = intval($statObj->total ?? 0);
                        if($total == 0 && intval($inb->total ?? 0) != 0) $total = intval($inb->total ?? 0);

                        $expiryRaw = $statObj->expiryTime ?? 0;
                        if(intval($expiryRaw) == 0 && intval($inb->expiryTime ?? 0) != 0) $expiryRaw = $inb->expiryTime;

                        $enable = null;
                        if(isset($statObj->enable)) $enable = (bool)$statObj->enable;
                        elseif(isset($inb->enable)) $enable = (bool)$inb->enable;
                    }else{
                        // fallback به آمار کلی inbound
                        $up = intval($inb->up ?? 0);
                        $down = intval($inb->down ?? 0);
                        $total = intval($inb->total ?? 0);
                        $expiryRaw = $inb->expiryTime ?? 0;
                        $enable = isset($inb->enable) ? (bool)$inb->enable : true;
                    }

                    $expirySec = farid_xuiMsg_parseExpirySeconds($expiryRaw);

                    $used = $up + $down;
                    $left = null;
                    if($total > 0){
                        $left = $total - $used;
                        if($left < 0) $left = 0;
                    }

                    $accounts[] = [
                        'server_id' => $sid,
                        'inbound_id' => $inboundId,
                        'uuid' => strval($uuid),
                        'remark' => strval($remark),
                        'enable' => ($enable === null ? true : $enable),
                        'up' => $up,
                        'down' => $down,
                        'total' => $total,
                        'used' => $used,
                        'left' => $left,
                        'expiry' => $expirySec,
                        'source' => ($statObj ? 'clientStats' : 'inbound')
                    ];
                }
            }else{
                // نسخه‌هایی که clientStats ندارند: آمار روی خود inbound است
                if(count($clients) == 0) continue;

                $up = intval($inb->up ?? 0);
                $down = intval($inb->down ?? 0);
                $total = intval($inb->total ?? 0);
                $expiryRaw = $inb->expiryTime ?? 0;
                $enable = isset($inb->enable) ? (bool)$inb->enable : true;

                $expirySec = farid_xuiMsg_parseExpirySeconds($expiryRaw);

                $used = $up + $down;
                $left = null;
                if($total > 0){
                    $left = $total - $used;
                    if($left < 0) $left = 0;
                }

                foreach($clients as $cl){
                    if(!is_array($cl)) continue;

                    $uuid = $cl['id'] ?? ($cl['password'] ?? null);
                    if(empty($uuid)) continue;

                    $remark = $cl['email'] ?? ($inb->remark ?? '');

                    $accounts[] = [
                        'server_id' => $sid,
                        'inbound_id' => $inboundId,
                        'uuid' => strval($uuid),
                        'remark' => strval($remark),
                        'enable' => $enable,
                        'up' => $up,
                        'down' => $down,
                        'total' => $total,
                        'used' => $used,
                        'left' => $left,
                        'expiry' => $expirySec,
                        'source' => 'inbound'
                    ];
                }
            }
        }
    }

    // حذف تکراری‌ها
    $uniq = [];
    $final = [];
    foreach($accounts as $a){
        $k = $a['server_id'] . '|' . $a['inbound_id'] . '|' . $a['uuid'];
        if(isset($uniq[$k])) continue;
        $uniq[$k] = true;
        $final[] = $a;
    }

    return $final;
}


function farid_xuiMsg_isExpiredAccount($acc){
    $now = time();
    $exp = intval($acc['expiry'] ?? 0);
    if($exp > 0 && $exp <= $now) return true;
    if(isset($acc['left']) && $acc['left'] !== null && is_numeric($acc['left']) && intval($acc['left']) <= 0) return true;
    return false;
}


function farid_xuiMsg_getExpiredAccounts(){
    $all = farid_xuiMsg_collectAllXuiAccounts();
    $out = [];
    foreach($all as $acc){
        if(farid_xuiMsg_isExpiredAccount($acc)) $out[] = $acc;
    }

    // مرتب‌سازی (قدیمی‌ترین منقضی در بالا)
    usort($out, function($a, $b){
        $ea = intval($a['expiry'] ?? 0);
        $eb = intval($b['expiry'] ?? 0);

        // expiryTime مشخص‌ها اول
        if($ea == 0 && $eb != 0) return 1;
        if($ea != 0 && $eb == 0) return -1;

        if($ea != $eb) return ($ea < $eb) ? -1 : 1;

        $la = ($a['left'] === null) ? PHP_INT_MAX : intval($a['left']);
        $lb = ($b['left'] === null) ? PHP_INT_MAX : intval($b['left']);
        if($la == $lb) return 0;
        return ($la < $lb) ? -1 : 1;
    });

    return $out;
}


function farid_xuiMsg_getNearExpireAccounts($daysThreshold, $gbThreshold){
    $days = intval($daysThreshold);
    $gb = floatval($gbThreshold);
    if($days < 0) $days = 0;
    if($gb < 0) $gb = 0;

    // اگر هر دو خاموش باشند
    if($days == 0 && $gb == 0) return [];

    $all = farid_xuiMsg_collectAllXuiAccounts();
    $out = [];
    $now = time();
    $bytesThreshold = ($gb > 0) ? ($gb * 1073741824) : 0;

    foreach($all as $acc){
        if(farid_xuiMsg_isExpiredAccount($acc)) continue;

        $near = false;

        $exp = intval($acc['expiry'] ?? 0);
        if(!$near && $days > 0 && $exp > 0){
            $secLeft = $exp - $now;
            if($secLeft <= ($days * 86400)) $near = true;
        }

        $left = $acc['left'] ?? null;
        if(!$near && $gb > 0 && $left !== null && is_numeric($left)){
            if(floatval($left) <= $bytesThreshold) $near = true;
        }

        if($near) $out[] = $acc;
    }

    // مرتب‌سازی: زودتر تمام‌شونده‌ها اول
    usort($out, function($a, $b){
        $now = time();
        $sa = (intval($a['expiry'] ?? 0) > 0) ? max(0, intval($a['expiry']) - $now) : PHP_INT_MAX;
        $sb = (intval($b['expiry'] ?? 0) > 0) ? max(0, intval($b['expiry']) - $now) : PHP_INT_MAX;
        if($sa != $sb) return ($sa < $sb) ? -1 : 1;

        $la = ($a['left'] === null) ? PHP_INT_MAX : intval($a['left']);
        $lb = ($b['left'] === null) ? PHP_INT_MAX : intval($b['left']);
        if($la == $lb) return 0;
        return ($la < $lb) ? -1 : 1;
    });

    return $out;
}


function farid_xuiMsg_getServerTitle($serverId){
    global $connection;
    static $cache = [];

    $sid = intval($serverId);
    if(isset($cache[$sid])) return $cache[$sid];

    $title = strval($sid);
    $stmt = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id`=? LIMIT 1");
    if($stmt){
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if($res && $res->num_rows > 0){
            $title = $res->fetch_assoc()['title'] ?? $title;
        }
    }

    $cache[$sid] = $title;
    return $title;
}


function farid_xuiMsg_findOrderByUuid($serverId, $uuid){
    global $connection;
    static $cache = [];

    $sid = intval($serverId);
    $uuid = strval($uuid);
    $key = $sid . '|' . $uuid;

    if(array_key_exists($key, $cache)){
        return $cache[$key];
    }

    $stmt = $connection->prepare("SELECT `id`,`userid`,`remark` FROM `orders_list` WHERE `uuid`=? AND `server_id`=? ORDER BY `id` DESC LIMIT 1");
    if(!$stmt){
        $cache[$key] = null;
        return null;
    }

    $stmt->bind_param("si", $uuid, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res && $res->num_rows > 0){
        $row = $res->fetch_assoc();
        $cache[$key] = [
            'id' => intval($row['id'] ?? 0),
            'userid' => intval($row['userid'] ?? 0),
            'remark' => $row['remark'] ?? null
        ];
        return $cache[$key];
    }

    $cache[$key] = null;
    return null;
}


function farid_xuiMsg_formatAccountLine($index, $acc, $order = null, $isExpiredList = true){
    $now = time();
    $serverTitle = farid_xuiMsg_getServerTitle($acc['server_id'] ?? 0);

    $uid = 0;
    $remark = $acc['remark'] ?? '-';
    if(is_array($order)){
        $uid = intval($order['userid'] ?? 0);
        if(!empty($order['remark'])) $remark = $order['remark'];
    }

    $uidTxt = ($uid > 0) ? strval($uid) : '—';

    // روز باقی‌مانده
    $exp = intval($acc['expiry'] ?? 0);
    if($exp > 0){
        $daysLeft = floor(($exp - $now) / 86400);
        if($daysLeft < 0) $daysTxt = 'منقضی';
        elseif($daysLeft == 0) $daysTxt = 'امروز';
        else $daysTxt = $daysLeft . 'روز';
    }else{
        $daysTxt = '∞';
    }

    // حجم باقی‌مانده
    if(!isset($acc['left']) || $acc['left'] === null){
        $leftTxt = '∞';
    }else{
        $lb = intval($acc['left']);
        $leftTxt = ($lb <= 0) ? 'تمام' : (function_exists('sumerize') ? sumerize($lb) : ($lb . 'B'));
    }

    // دلیل
    $reason = '';
    if($isExpiredList){
        if($exp > 0 && $exp <= $now) $reason .= '⏰';
        if(isset($acc['left']) && $acc['left'] !== null && is_numeric($acc['left']) && intval($acc['left']) <= 0) $reason .= '📦';
    }else{
        // نزدیک به اتمام
        if($exp > 0) $reason .= '⏳';
        if(isset($acc['left']) && $acc['left'] !== null) $reason .= '📦';
    }

    $reason = ($reason !== '') ? (" " . $reason) : '';

    return "$index) [$serverTitle] $remark | UID:$uidTxt | ⏳$daysTxt | 📦$leftTxt$reason";
}


function farid_xuiMsg_sendMessageToAccounts($accounts, $message){
    $targets = [];
    $unknownAccounts = 0;

    foreach($accounts as $acc){
        $order = farid_xuiMsg_findOrderByUuid($acc['server_id'], $acc['uuid']);
        if(!$order || intval($order['userid'] ?? 0) <= 0){
            $unknownAccounts++;
            continue;
        }
        $uid = intval($order['userid']);
        $targets[$uid] = true;
    }

    $userIds = array_keys($targets);

    $sent = 0;
    $failed = 0;
    $failedUserIds = [];

    foreach($userIds as $uid){
        $resp = sendMessage($message, null, null, $uid);

        $ok = true;
        if($resp === false){
            $ok = false;
        }elseif(is_object($resp) && isset($resp->ok)){
            $ok = ($resp->ok == true);
        }elseif(is_array($resp) && isset($resp['ok'])){
            $ok = (bool)$resp['ok'];
        }

        if($ok){
            $sent++;
        }else{
            $failed++;
            $failedUserIds[] = $uid;
        }

        // کمی تاخیر برای جلوگیری از محدودیت تلگرام
        usleep(200000);
    }

    return [
        'total_accounts' => count($accounts),
        'unknown_accounts' => $unknownAccounts,
        'target_users' => count($userIds),
        'sent' => $sent,
        'failed' => $failed,
        'failed_user_ids' => $failedUserIds
    ];
}

// اگر کاربر در حالت عادی متن بی‌ربط ارسال کند، به جای سکوت، پیام استارت/منوی اصلی نمایش داده می‌شود.
if(isset($update->message) && isset($text) && trim((string)$text) !== '' && empty($data)){
    $currentStep = (string)($userInfo['step'] ?? 'none');
    $isCommand = preg_match('/^\//', trim((string)$text));
    if(!$isCommand && $currentStep === 'none'){
        $startText = $mainValues['start_message'] ?? ($mainValues['reached_main_menu'] ?? 'منوی اصلی');
        sendMessage($startText, getMainKeys(), 'HTML');
        exit();
    }
}

?>
