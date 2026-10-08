<?php
// UI navigation and independent silent access control. No database schema change.
function v2raystore_mainKeysForRecipient($recipientId){
    global $from_id, $userInfo, $botState;
    $recipient = v2raystore_getUserRowFresh($recipientId);
    if(!$recipient) return null;
    $saved = [$from_id, $userInfo, $botState];
    try {
        $from_id = (int)$recipientId;
        $userInfo = $recipient;
        $botState = v2seg_applyStates(v2raystore_applyRoleSpecificStates(v2raystore_getBotStatesArray(true), $recipient), $recipient);
        return getMainKeys();
    } finally {
        [$from_id, $userInfo, $botState] = $saved;
    }
}

function v2raystore_isSilentBlocked($userId){
    if((int)$userId <= 0) return false;
    return v2raystore_getSettingValue('SILENT_BLOCK_' . (int)$userId, '0') === '1';
}

function v2raystore_setUserBlockMode($userId, $mode){
    global $connection, $admin;
    if(!in_array($mode, ['silent', 'normal', 'none'], true)) return false;
    $userId = (int)$userId;
    $user = v2raystore_getUserRowFresh($userId);
    if(!$user || $userId === (int)$admin || !empty($user['isAdmin'])) return false;
    // A silent block is separate from wizard state: role changes and form resets cannot undo it.
    if($mode === 'silent'){
        return v2raystore_setSettingValue('SILENT_BLOCK_' . $userId, '1');
    }
    $step = $mode === 'normal' ? 'banned' : 'none';
    $stmt = $connection->prepare("UPDATE users SET step=? WHERE userid=? AND (step='banned' OR ?='banned')");
    if(!$stmt) return false;
    $stmt->bind_param('sis', $step, $userId, $step);
    $ok = $stmt->execute();
    $stmt->close();
    if(!$ok) return false;
    return v2raystore_setSettingValue('SILENT_BLOCK_' . $userId, '0');
}

// Each entry: title, parent, child buttons. Related actions are paired in two columns; no decorative dead buttons.
function v2raystore_adminMenuTree(){
    $tree = [
        'Main'=>['👥 انتخاب بخش مدیریت','mainMenu',[[
            '👤 مشتریان قدیمی','cgManage_legacy'],['🆕 مشتریان جدید','cgManage_new']]],
        'LegacyDashboard'=>['🧭 مدیریت ربات', 'adminMainMenu', [
            ['👤 مدیریت کاربر','adminUserOperationsMenu'], ['🤝 مدیریت نمایندگی','adminAgentsMenu'],
            ['🧾 مدیریت سرویس','adminConfigsMenu'], ['🖥 سرورها و پلن‌ها','adminSalesMenu'],
            ['💳 پرداخت و جایزه','adminPaymentsMenu'], ['📊 آمار و گزارش‌ها','adminReportsMenu'],
            ['📨 پیام و پشتیبانی','adminMessagesMenu'], ['📝 محتوا و آموزش','adminContentMenu'],
            ['⚙️ تنظیمات ربات','adminSettingsMenu'], ['⚡ دسترسی سریع','adminQuickMenu']]],
        'Reports'=>['📊 آمار و گزارش‌ها','adminMainMenu',[
            ['📊 آمار مشتریان قدیمی','cgStats_legacy'],
            ['📈 آمار کلی ربات','botReports'],['📊 گزارش‌های کانال','reportChannelSettingsMenu'],
            ['⏱ فاصله گزارش درآمد','editRewardTime']]],
        'Configs'=>['🧾 مدیریت سرویس‌ها','adminMainMenu',[
            ['🔎 جستجوی کانفیگ','searchUsersConfig'],['➕ ساخت و ثبت سرویس','adminConfigCreateMenu'],
            ['♻️ بروزرسانی و انتقال','adminConfigUpdateMenu'],['🧪 اکانت‌های تست','testAccountManagement'],
            ['🗑 پاکسازی سرویس‌ها','cleanOldConfigsMenu'],['♻️ قوانین تمدید و سرویس','botSettingsService'],
            ['🔗 لینک، ساب و تحویل','botSettingsConnections']]],
        'ConfigCreate'=>['➕ ساخت و ثبت سرویس','adminConfigsMenu',[
            ['➕ ثبت کانفیگ کاربر','manualAttachConfig'],['📦 ساخت چند اکانت','createMultipleAccounts']]],
        'ConfigUpdate'=>['♻️ بروزرسانی و انتقال','adminConfigsMenu',[
            ['♻️ آپدیت کانفیگ‌ها','updateConfigsMenu'],['🔁 انتقال بین اینباندها','inboundMoveMenu']]],
        'Sales'=>['🖥 سرورها و پلن‌ها','adminMainMenu',[
            ['🖥 مدیریت سرورها','serversSetting'],['📦 پلن‌ها و دسته‌بندی‌ها','adminCatalogMenu'],
            ['🏷 کدهای تخفیف','discount_codes'],['🛒 تنظیمات فروش','botSettingsSales']]],
        'Catalog'=>['📦 پلن‌ها و دسته‌بندی‌ها','adminSalesMenu',[
            ['📦 مدیریت پلن‌ها','backplan'],['🗂 مدیریت دسته‌بندی‌ها','categoriesSetting']]],
        'Payments'=>['💳 پرداخت و جایزه','adminMainMenu',[
            ['🏦 روش‌های پرداخت','adminPaymentMethodsMenu'],['🧾 کنترل رسید و تأیید','adminPaymentChecksMenu'],
            ['🎁 جایزه خرید و تمدید','rewardSettings']]],
        'PaymentChecks'=>['🧾 کنترل رسید و تأیید','adminPaymentsMenu',[
            ['⏱ تأیید خودکار','autoApproveOrdersMenu'],['🧾 بررسی فیش تکراری','receiptDuplicateSettings']]],
        'PaymentMethods'=>['🏦 روش‌ها و اطلاعات پرداخت','adminPaymentsMenu',[
            ['🏦 حساب‌ها و درگاه‌ها','gateWays_Channels'],['💳 تنظیمات کارت‌به‌کارت','proC2CMenu']]],
        'Users'=>['👤 مدیریت مشتری‌ها','adminMainMenu',[
            ['👤 مشتریان قدیمی','cgManage_legacy'],['🆕 مشتریان جدید','cgManage_new']]],
        'UserOperations'=>['👤 عملیات مشترک کاربر','adminUsersMenu',[
            ['🔎 جستجوی کاربر','userReports'],['🚫 مسدودی کاربران','adminBlocksMenu'],
            ['💰 کیف پول کاربر','adminWalletMenu'],['🎗 دعوت و زیرمجموعه','adminMarketingMenu'],
            ['✉️ پیام به کاربر','messageToSpeceficUser'],['🔐 دسترسی و عضویت','adminAccessMenu']]],
        'Marketing'=>['🎗 دعوت و زیرمجموعه','adminUserOperationsMenu',[
            ['👥 زیرمجموعه‌های کاربر','proReferralAsk'],['🎗 بنر و پورسانت','inviteSetting']]],
        'UserLookup'=>['👤 اطلاعات و زیرمجموعه‌ها','adminUserOperationsMenu',[
            ['👤 گزارش یک کاربر','userReports'],['👥 زیرمجموعه‌های کاربر','proReferralAsk']]],
        'Wallet'=>['💰 مدیریت کیف پول کاربران','adminUserOperationsMenu',[
            ['➕ افزایش موجودی','increaseUserWallet'],['➖ کاهش موجودی','decreaseUserWallet']]],
        'Blocks'=>['🚫 مسدودی و رفع مسدودی','adminUserOperationsMenu',[
            ['🚫 مسدودسازی عادی','banUser'],['🔇 مسدودسازی بی‌صدا','silentBanUser'],
            ['✅ رفع مسدودی','unbanUser']]],
        'Agents'=>['🤝 مدیریت نمایندگی','adminMainMenu',[
            ['👥 فهرست نمایندگان','agentsList'],['➕ افزودن نماینده','addAgentManual'],
            ['📋 درخواست‌های ردشده','rejectedAgentList'],['⚙️ تنظیمات نمایندگی','adminAgentOptionsMenu']]],
        'AgentOptions'=>['⚙️ تنظیمات نمایندگی','adminAgentsMenu',[
            ['نمایندگی','changeBotagencyState'],['فروش نماینده','changeBotagentSellState'],
            ['مبنای تخفیف','changeBotagencyPlanDiscount']]],
        'Access'=>['🔐 قوانین ورود و عضویت','adminUserOperationsMenu',[
            ['🔑 دسترسی اعضای جدید','newMemberAccessMenu'],['🚪 معافیت عضویت','joinExemptMenu'],
            ['📱 تأیید شماره و ورود','botSettingsAccess']]],
        'Messages'=>['📨 پیام‌ها و پشتیبانی','adminMainMenu',[
            ['📣 ارسال همگانی','adminBroadcastMenu'],['📌 مدیریت پین‌ها','adminPinsMenu'],
            ['🎫 تیکت و خطایابی','adminSupportMenu'],['⏳ پیام‌های خودکار','adminNoticesMenu']]],
        'Notices'=>['⏳ پیام‌های خودکار','adminMessagesMenu',[
            ['⏳ اعلان حجم و انقضا','xuiMsgMenu'],['📩 پیام ترک کانال','proLeaveNoticeMenu']]],
        'Broadcast'=>['📣 پیام‌های همگانی','adminMessagesMenu',[
            ['📝 ارسال پیام همگانی','message2All'],['↪️ فوروارد همگانی','forwardToAll'],
            ['📊 وضعیت صف ارسال','broadcastQueueStatus']]],
        'Pins'=>['📌 پیام‌های پین‌شده','adminMessagesMenu',[
            ['📌 فهرست پین‌ها','broadcastPinsMenu'],['➕ پین پیام و فایل','proPinMenu']]],
        'Support'=>['🎫 تیکت و خطایابی','adminMessagesMenu',[
            ['🎫 تیکت‌ها','ticketsList'],['🛠 متن راهنمای خطایابی','editDiagAdminText']]],
        'Content'=>['📝 محتوا و آموزش','adminMainMenu',[
            ['📝 خوش‌آمد و قوانین خرید','adminTextSettings'],['📚 آموزش و سوالات','adminHelpMenu']]],
        'Settings'=>['⚙️ تنظیمات ربات','adminMainMenu',[
            ['⚙️ امکانات و وضعیت ربات','botSettings'],['🎛 ظاهر و دکمه‌ها','adminAppearanceMenu'],['👤 تنظیمات مشتریان قدیمی','cgLegacySettings'],['👮 مدیران ربات','adminsList']]],
        'Appearance'=>['🎛 ظاهر و دکمه‌ها','adminSettingsMenu',[
            ['➕ دکمه‌های سفارشی','mainMenuButtons'],['🎛 چیدمان دکمه‌ها','userButtonSettings']]],
        // Older messages with the old Quick callback remain usable.
        'Quick'=>['⚡ دسترسی سریع','adminMainMenu',[
            ['🔎 جستجوی کانفیگ','searchUsersConfig'],['✉️ پیام به کاربر','messageToSpeceficUser'],
            ['♻️ بروزرسانی کانفیگ‌ها','updateConfigsMenu'],['📦 پلن‌ها','backplan']]]
    ];
    if(($GLOBALS['v2seg_shared_context']??'')==='new'){
        $tree['UserOperations'][2]=[
            ['🔎 جستجوی کاربر','userReports'],['🚫 مسدودی کاربران','adminBlocksMenu'],
            ['💰 کیف پول کاربر','adminWalletMenu'],['✉️ پیام به کاربر','messageToSpeceficUser'],
            ['⚙️ امکانات و ورود','customerGroupsMenu']];
        $tree['Agents'][2]=[['👥 فهرست نمایندگان','agentsList'],['➕ افزودن نماینده','addAgentManual'],['📋 درخواست‌های ردشده','rejectedAgentList']];
        $tree['Configs'][2]=[['🔎 جستجوی کانفیگ','searchUsersConfig'],['➕ ثبت کانفیگ کاربر','manualAttachConfig'],['📦 ساخت چند اکانت','createMultipleAccounts']];
    }
    return $tree;
}

function v2raystore_adminMenuKeys($name){
    global $from_id, $admin;
    if(in_array($name,['Main','Users'],true) && function_exists('v2seg_adminGroupEnabled') && !v2seg_adminGroupEnabled('new')){
        if($name==='Main') return v2seg_dashboardKeys('legacy');
        $saved=$GLOBALS['v2seg_shared_context']??null;
        $GLOBALS['v2seg_shared_context']='legacy';
        try{return v2raystore_adminMenuKeys('UserOperations');}
        finally{if($saved===null)unset($GLOBALS['v2seg_shared_context']);else $GLOBALS['v2seg_shared_context']=$saved;}
    }
    $tree = v2raystore_adminMenuTree();
    $entry = $tree[$name] ?? $tree['Main'];
    $buttons = [];
    $agentStates = $name === 'AgentOptions' ? v2raystore_adminBotSettingsState() : [];
    foreach($entry[2] as [$title,$callback]){
        if($callback === 'adminsList' && (int)$from_id !== (int)$admin) continue;
        if($name === 'AgentOptions'){
            $key = substr($callback, strlen('changeBot'));
            $fallback = $key === 'agentSellState' ? ($agentStates['sellState'] ?? 'off') : 'off';
            $on = ($agentStates[$key] ?? $fallback) === 'on';
            $title .= ' · ' . ($key === 'agencyPlanDiscount' ? ($on ? 'پلن' : 'سرور') : ($on ? '🟢' : '🔴'));
        }
        $buttons[] = ['text'=>$title,'callback_data'=>$callback];
    }
    $rows = array_chunk($buttons, 2);
    $navigation = [['text'=>'⬅️ بازگشت','callback_data'=>$entry[1]]];
    if($name !== 'Main' && $entry[1] !== 'adminMainMenu'){
        $navigation[] = ['text'=>'🏠 مدیریت','callback_data'=>'adminMainMenu'];
    }
    $rows[] = $navigation;
    if(isset($GLOBALS['v2seg_shared_context']) && !in_array($name,['Main','Users'],true)){
        $group=$GLOBALS['v2seg_shared_context'];
        foreach($rows as &$row)foreach($row as &$button){
            $cb=$button['callback_data'];
            if($cb==='adminUsersMenu' || ($cb==='adminMainMenu' && preg_match('/بازگشت/u',$button['text'])))$button['callback_data']='cgManage_'.$group;
            elseif($cb!=='adminMainMenu' && $cb!=='mainMenu' && strpos($cb,'cg')!==0)$button['callback_data'].='_ucg_'.$group;
        }
        unset($row,$button);
    }
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_adminMenuText($name){
    if(in_array($name,['Main','Users'],true) && function_exists('v2seg_adminGroupEnabled') && !v2seg_adminGroupEnabled('new')){
        return ($name==='Main' ? '<b>🧭 مدیریت ربات</b>' : '<b>👤 مدیریت کاربر</b>')."\n\nگزینه موردنظر را انتخاب کنید.";
    }
    $tree = v2raystore_adminMenuTree();
    $entry = $tree[$name] ?? $tree['Main'];

    $path = [$entry[0]];
    $parent = $entry[1];
    while(preg_match('/^admin([A-Za-z]+)Menu$/D', $parent, $m) && isset($tree[$m[1]])){
        array_unshift($path, $tree[$m[1]][0]);
        $parent = $tree[$m[1]][1];
    }
    return '<b>' . htmlspecialchars($entry[0], ENT_QUOTES, 'UTF-8') . "</b>\n\n"
        . 'مسیر: ' . implode(' ← ', $path) . "\n\nگزینه موردنظر را انتخاب کنید.";
}

// Keep one originating screen per user's active form, separate from users.temp.
// The Telegram source message supplies its own exact paging/back buttons.
function v2raystore_captureFormOrigin($value, $field){
    global $update, $from_id, $userInfo, $admin;
    if($field !== 'step') return;
    if((int)$from_id !== (int)$admin && empty($userInfo['isAdmin'])) return;
    $key = 'FORM_ORIGIN_' . (int)$from_id;
    if($value === 'none'){
        $origin = json_decode(v2raystore_getSettingValue($key, ''), true);
        if(!empty($origin['menu'])) $GLOBALS['v2raystore_form_return_menu'] = $origin['menu'];
        $GLOBALS['v2raystore_form_return_user'] = (int)$from_id;
        if(!isset($update->callback_query) && !empty($origin['group'])) $GLOBALS['v2seg_shared_context'] = $origin['group'];
        $stmt = $GLOBALS['connection']->prepare("DELETE FROM setting WHERE type=?");
        if($stmt){ $stmt->bind_param('s',$key); $stmt->execute(); $stmt->close(); }
        return;
    }
    if((int)$from_id !== (int)$admin && empty($userInfo['isAdmin'])) return;
    $message = $update->callback_query->message ?? null;
    if(!$message || !isset($message->text, $message->reply_markup)) return;
    // Keep the original screen when an inline wizard advances to another step.
    $existing = v2raystore_getSettingValue($key, '');
    if($existing !== '') return;
    $snapshot = [
        'text'=>(string)$message->text,
        'entities'=>$message->entities ?? [],
        'reply_markup'=>$message->reply_markup,
        'time'=>time(),
        'menu'=>v2raystore_adminMenuFromMarkup($message->reply_markup) ?: v2raystore_markupBackDestination($message->reply_markup),
        'group'=>v2raystore_markupCustomerGroup($message->reply_markup),
        'version'=>2,
    ];
    v2raystore_setSettingValue($key, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function v2raystore_restoreFormOrigin(){
    global $from_id, $removeKeyboard, $userInfo;
    $raw = v2raystore_getSettingValue('FORM_ORIGIN_' . (int)$from_id, '');
    $origin = json_decode($raw, true);
    if(!is_array($origin) || empty($origin['text']) || empty($origin['reply_markup']['inline_keyboard'])) return false;
    if(time() - (int)($origin['time'] ?? 0) > 86400) return false;
    $refresh=null;
    if(empty($origin['version']) && preg_match('/^admin([A-Za-z]+)Menu$/D',$origin['menu']??'',$m)
        && isset(v2raystore_adminMenuTree()[$m[1]]) && v2raystore_adminMenuFromMarkup($origin['reply_markup'])!==$origin['menu'])$refresh=$m[1];
    setUser();
    setUser('', 'temp');
    $userInfo['step'] = 'none';
    sendMessage('↩️ عملیات لغو شد.', $removeKeyboard, null);
    $payload=[
        'chat_id'=>$from_id,
        'text'=>$origin['text'],
        'entities'=>json_encode($origin['entities'] ?? [], JSON_UNESCAPED_UNICODE),
        'reply_markup'=>json_encode($origin['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    if($refresh!==null){
        $payload['text']=v2raystore_adminMenuText($refresh);
        $payload['reply_markup']=v2raystore_adminMenuKeys($refresh);
        unset($payload['entities']);$payload['parse_mode']='HTML';
    }
    bot('sendMessage',$payload);
    return true;
}

function v2raystore_handleAdminNavigation(){
    global $from_id, $admin, $userInfo, $data, $text, $buttonValues, $message_id, $update, $removeKeyboard;
    if((int)$from_id !== (int)$admin && empty($userInfo['isAdmin'])) return;
    v2raystore_normalizeAdminCallback();
    if(isset($update->message) && ($text ?? '') === ($buttonValues['cancel'] ?? '')){
        if(v2raystore_restoreFormOrigin()) exit();
        // Older forms have no snapshot; return safely before any text/media handler.
        $menu = v2raystore_cancelMenuForStep((string)($userInfo['step'] ?? ''));
        setUser();
        setUser('', 'temp');
        $userInfo['step'] = 'none';
        sendMessage('↩️ عملیات لغو شد.', $removeKeyboard, null);
        sendMessage(v2raystore_adminMenuText($menu), v2raystore_adminMenuKeys($menu), 'HTML');
        exit();
    }
    $tree = v2raystore_adminMenuTree();
    if(preg_match('/^admin([A-Za-z]+)Menu$/D', (string)($data ?? ''), $m) && isset($tree[$m[1]])){
        setUser();
        setUser('', 'temp');
        $userInfo['step'] = 'none';
        if($m[1]==='Main' || $m[1]==='Users') unset($GLOBALS['v2seg_shared_context']);
        editText($message_id, v2raystore_adminMenuText($m[1]), v2raystore_adminMenuKeys($m[1]), 'HTML');
        exit();
    }
    $clickedBack = in_array($data ?? '', ['mainMenu','managePanel'], true);
    foreach(($update->callback_query->message->reply_markup->inline_keyboard ?? []) as $row){
        foreach($row as $button){
            if(($button->callback_data ?? '') === ($data ?? '')
                && preg_match('/بازگشت|برگشت/u',(string)($button->text ?? ''))) $clickedBack = true;
        }
    }
    if($clickedBack || v2raystore_isAdminReadScreen((string)($data ?? '')) || (isset($update->message->text) && preg_match('/^\/[Ss]tart(?:\s|$)/', $text ?? ''))){
        setUser();
        setUser('', 'temp');
        $userInfo['step'] = 'none';
        if($clickedBack || (isset($update->message->text) && preg_match('/^\/[Ss]tart(?:\s|$)/',$text??''))){
            unset($GLOBALS['v2raystore_form_return_menu'],$GLOBALS['v2raystore_form_return_user']);
            if(in_array($data??'',['mainMenu','managePanel'],true) || isset($update->message))unset($GLOBALS['v2seg_shared_context']);
        }
    }
    if(isset($update->callback_query)){
        // Callback message text is never a text answer to an outstanding admin form.
        // Preserve DB state for inline wizards; only suppress text handlers this request.
        $userInfo['step'] = 'none';
    }
}

function v2raystore_handleUserBlocking(){
    global $from_id, $admin, $userInfo, $data, $text, $buttonValues, $cancelKey, $removeKeyboard, $update;
    if((int)$from_id !== (int)$admin && empty($userInfo['isAdmin'])) return;
    $actions = ['banUser'=>'normal','silentBanUser'=>'silent','unbanUser'=>'none'];
    if(isset($actions[$data ?? ''])){
        delMessage();
        $prompt = ($data === 'silentBanUser')
            ? '🔇 آیدی عددی کاربر را بفرستید. پیام مسدودی ارسال نمی‌شود و ربات به پیام و دکمه‌های او پاسخ نمی‌دهد.'
            : ($data === 'unbanUser' ? 'آیدی عددی کاربر را برای رفع مسدودی عادی یا بی‌صدا بفرستید.' : 'آیدی عددی کاربر را برای مسدودسازی عادی بفرستید.');
        sendMessage($prompt, $cancelKey, null);
        setUser($data);
        exit();
    }
    $step = $userInfo['step'] ?? '';
    if(!isset($update->message) || !isset($actions[$step]) || ($text ?? '') === ($buttonValues['cancel'] ?? '')) return;
    if(!preg_match('/^[1-9][0-9]{4,15}$/D', trim((string)$text))){
        sendMessage('آیدی عددی معتبر بفرستید.', $cancelKey, null);
        exit();
    }
    $target = (int)trim($text);
    if(!v2raystore_setUserBlockMode($target, $actions[$step])){
        sendMessage('انجام نشد؛ کاربر باید در ربات ثبت شده باشد و مدیر نباشد.', $cancelKey, null);
        exit();
    }
    $message = $step === 'silentBanUser' ? '✅ کاربر بی‌صدا مسدود شد؛ پیامی برای او ارسال نشد.'
        : ($step === 'unbanUser' ? '✅ مسدودی کاربر برداشته شد.' : '✅ کاربر مسدود شد.');
    setUser();
    sendMessage($message, $removeKeyboard, null);
    sendMessage(v2raystore_adminMenuText('Blocks'), v2raystore_adminMenuKeys('Blocks'), 'HTML');
    exit();
}

function v2raystore_adminMenuFromMarkup($markup){
    global $from_id, $admin;
    if(is_object($markup)) $markup = json_decode(json_encode($markup), true);
    if(is_string($markup)) $markup = json_decode($markup,true);
    if(!is_array($markup)) return '';
    $callbacks = [];
    foreach(($markup['inline_keyboard'] ?? []) as $row){
        foreach($row as $button) if(isset($button['callback_data'])) $callbacks[] = $button['callback_data'];
    }
    foreach(v2raystore_adminMenuTree() as $name=>$entry){
        if(in_array($name,['Main','Users'],true) && function_exists('v2seg_adminGroupEnabled') && !v2seg_adminGroupEnabled('new')) continue;
        $expected = json_decode(v2raystore_adminMenuKeys($name),true)['inline_keyboard'];
        $expectedCallbacks = [];
        foreach($expected as $row) foreach($row as $button) $expectedCallbacks[] = $button['callback_data'];
        $plain=function($list){return array_map(function($cb){return preg_replace('/_ucg_(legacy|new)$/D','',$cb);},$list);};
        if($plain($callbacks) === $plain($expectedCallbacks)) return $name==='Main'?'adminUsersMenu':'admin' . $name . 'Menu';
    }
    $callbacks=array_map(function($cb){return preg_replace('/_ucg_(legacy|new)$/D','',$cb);},$callbacks);
    if(function_exists('v2seg_dashboardKeys'))foreach(['legacy','new'] as $g){
        $expected=[];$keys=json_decode(v2seg_dashboardKeys($g),true);
        foreach($keys['inline_keyboard'] as $row)foreach($row as $b)$expected[]=preg_replace('/_ucg_(legacy|new)$/D','',$b['callback_data']);
        if($callbacks===$expected)return 'cgManage_'.$g;
    }
    foreach($callbacks as $cb){
        if(preg_match('/^cgStats_(legacy|new)$/D',$cb,$m) && in_array('adminUsersMenu',$callbacks,true))return 'cgManage_'.$m[1];
    }
    if(in_array('cgToggle_enabled',$callbacks,true))return 'customerGroupsMenu';
    $group=v2raystore_markupCustomerGroup($markup);
    if($group){
        if(in_array('botSettings',$callbacks,true) && in_array('backplan',$callbacks,true))return 'cgCommon_'.$group;
        if(in_array('broadcastTargetMessage_'.$group,$callbacks,true))return 'cgBroadcast_'.$group;
        if(in_array('gateWays_Channels',$callbacks,true) && in_array('backplan',$callbacks,true))return 'cgLegacySettings';
    }
    return '';
}

function v2raystore_markupCustomerGroup($markup){
    if(is_object($markup))$markup=json_decode(json_encode($markup),true);
    if(is_string($markup))$markup=json_decode($markup,true);
    $groups=[];
    foreach(($markup['inline_keyboard']??[]) as $row)foreach($row as $button){
        $cb=$button['callback_data']??'';
        if(preg_match('/_(?:ucg|ctx)_(legacy|new)$/D',$cb,$m) || preg_match('/^cgManage_(legacy|new)$/D',$cb,$m))$groups[$m[1]]=true;
    }
    return count($groups)===1?array_key_first($groups):null;
}

function v2raystore_markupBackDestination($markup){
    if(is_object($markup))$markup=json_decode(json_encode($markup),true);
    if(is_string($markup))$markup=json_decode($markup,true);
    foreach(($markup['inline_keyboard']??[]) as $row)foreach($row as $b){
        if(preg_match('/بازگشت|برگشت/u',$b['text']??'')
            && preg_match('/^(?:admin[A-Za-z]+Menu(?:_ucg_(?:legacy|new))?|cgManage_(?:legacy|new)|cgCommon_(?:legacy|new)|cgLegacySettings)$/D',$b['callback_data']??''))return $b['callback_data'];
    }
    return '';
}

function v2raystore_normalizeAdminCallback(){
    global $from_id,$admin,$userInfo,$data,$update;
    if((int)$from_id!==(int)$admin && empty($userInfo['isAdmin']))return;
    if(isset($update->callback_query)){
        $group=v2raystore_markupCustomerGroup($update->callback_query->message->reply_markup??null);
        if($group)$GLOBALS['v2seg_shared_context']=$group;
    }
    if(preg_match('/^(.+)_ucg_(legacy|new)$/D',(string)($data??''),$m)){
        $data=$m[1];$GLOBALS['v2seg_shared_context']=$m[2];
    }elseif(preg_match('/^cg(?:Manage|Stats|Common|Broadcast|Customers)_(legacy|new)(?:_|$)/D',(string)($data??''),$m))$GLOBALS['v2seg_shared_context']=$m[1];
    elseif(($data??'')==='customerGroupsMenu')$GLOBALS['v2seg_shared_context']='new';
    if(in_array($data??'',['adminMainMenu','adminUsersMenu','managePanel','mainMenu'],true)){
        unset($GLOBALS['v2seg_shared_context'],$GLOBALS['v2raystore_form_return_menu']);
    }
    if(in_array($data??'',['adminMainMenu','adminUsersMenu','managePanel'],true) && function_exists('v2seg_adminGroupEnabled') && !v2seg_adminGroupEnabled('new')){
        $data=$data==='adminUsersMenu' ? 'adminUserOperationsMenu' : 'cgManage_legacy';
        $GLOBALS['v2seg_shared_context']='legacy';
    }
}

function v2raystore_formReturnKeys(){
    global $from_id,$update;
    if(!isset($update->message) || (int)($GLOBALS['v2raystore_form_return_user']??0)!==(int)$from_id)return null;
    $menu=$GLOBALS['v2raystore_form_return_menu']??'';
    if(preg_match('/^admin([A-Za-z]+)Menu(?:_ucg_(legacy|new))?$/D',$menu,$m) && isset(v2raystore_adminMenuTree()[$m[1]])){
        if(!empty($m[2]))$GLOBALS['v2seg_shared_context']=$m[2];
        return v2raystore_adminMenuKeys($m[1]);
    }
    return null;
}

function v2raystore_adjustAdminBackMarkup($markup, $recipientId){
    global $from_id, $admin, $userInfo, $update, $data;
    if((int)$recipientId !== (int)$from_id
        || ((int)$from_id !== (int)$admin && empty($userInfo['isAdmin']))) return $markup;
    if(!isset($update->callback_query) && empty($GLOBALS['v2seg_shared_context'])){
        $origin=json_decode(v2raystore_getSettingValue('FORM_ORIGIN_'.(int)$from_id,''),true);
        $group=$origin['group']??v2raystore_markupCustomerGroup($origin['reply_markup']??null);
        if(in_array($group,['legacy','new'],true))$GLOBALS['v2seg_shared_context']=$group;
    }
    $decoded = is_string($markup) ? json_decode($markup,true) : $markup;
    if(!is_array($decoded) || !isset($decoded['inline_keyboard'])) return $markup;
    // A complete category menu already owns its parent; never make its Back link point to itself.
    if(v2raystore_adminMenuFromMarkup($decoded) !== '') return v2raystore_contextualAdminMarkup($decoded);
    $tree = v2raystore_adminMenuTree();
    if(preg_match('/^admin([A-Za-z]+)Menu$/D',(string)($data ?? ''),$m) && isset($tree[$m[1]])) return v2raystore_contextualAdminMarkup($decoded);
    $source = $update->callback_query->message->reply_markup ?? null;
    $parent = $source ? v2raystore_adminMenuFromMarkup($source) : '';
    // A settings toggle re-renders the same page: retain its existing back destination.
    if($parent === '' && $source){
        $source = json_decode(json_encode($source),true);
        foreach(($source['inline_keyboard'] ?? []) as $row){
            foreach($row as $button){
                if(preg_match('/بازگشت|برگشت/u',(string)($button['text'] ?? ''))
                    && preg_match('/^(?:admin[A-Za-z]+Menu(?:_ucg_(?:legacy|new))?|cgManage_(?:legacy|new)|cgCommon_(?:legacy|new)|cgLegacySettings)$/D',(string)($button['callback_data'] ?? ''))){
                    $parent = $button['callback_data'];
                }
            }
        }
    }
    if($parent === '') $parent = $GLOBALS['v2raystore_form_return_menu'] ?? '';
    if($parent === '' && !isset($update->callback_query)){
        $origin = json_decode(v2raystore_getSettingValue('FORM_ORIGIN_' . (int)$from_id, ''),true);
        $parent = $origin['menu'] ?? '';
    }
    if($parent === '') return v2raystore_contextualAdminMarkup($decoded);
    $decoded = is_string($markup) ? json_decode($markup,true) : $markup;
    if(!is_array($decoded) || !isset($decoded['inline_keyboard'])) return $markup;
    foreach($decoded['inline_keyboard'] as &$row){
        foreach($row as &$button){
            if(preg_match('/بازگشت|برگشت/u',(string)($button['text'] ?? ''))
                && preg_match('/^admin[A-Za-z]+Menu$/D',(string)($button['callback_data'] ?? ''))){
                $button['callback_data'] = $parent;
                $button['text'] = '⬅️ بازگشت';
            }
        }
        unset($button);
    }
    unset($row);
    return v2raystore_contextualAdminMarkup($decoded);
}

function v2raystore_contextualAdminMarkup($decoded){
    $group=$GLOBALS['v2seg_shared_context']??null;
    $root=v2raystore_adminMenuFromMarkup($decoded);
    if(in_array($root,['adminMainMenu','adminUsersMenu'],true))$group=null;
    if($group){
        foreach($decoded['inline_keyboard'] as &$row)foreach($row as &$button){
            $cb=$button['callback_data']??'';
            if($cb==='' || $cb==='v2raystore' || in_array($cb,['mainMenu','managePanel','adminMainMenu','adminUsersMenu'],true)
                || strpos($cb,'cg')===0 || strpos($cb,'monthlyReport')===0 || preg_match('/_ucg_(legacy|new)$/D',$cb))continue;
            if(strlen($cb.'_ucg_'.$group)<=64)$button['callback_data']=$cb.'_ucg_'.$group;
        }
        unset($button,$row);
    }
    return json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function v2raystore_cancelMenuForStep($step){
    if(strpos((string)$step,'cgInput_')===0) return 'Users';
    $groups = [
        'Blocks'=>'^(silentBanUser|banUser|unbanUser)',
        'Wallet'=>'^(increaseUserWallet|decreaseUserWallet|increaseWalletUser|decreaseWalletUser)',
        'Agents'=>'^(addAgent|saveAgent|agent|agencyApprove)',
        'Access'=>'^(setBuyersAccessCode|addJoinExemptUser|removeJoinExemptUser|proSetLeave)',
        'UserLookup'=>'^(userReports|proReferral)',
        'ConfigCreate'=>'^(manualAttach|createAcc)',
        'ConfigUpdate'=>'^(inboundMove|updateConfig)',
        'Configs'=>'^(searchUsersConfig|cleanOld|addTest|editTest|resetOneTest|setTest|removeTest|testAccount)',
        'Catalog'=>'^(addNew.*Plan|changeDayPlan|changeVolumePlan|editCustom|v2raystoreplan|editDestName|editSpiderX|editServerNames|editFlow|editPFlow|v2raystorecategory)',
        'Sales'=>'^(addserver|addServer|changesServer|edits?Server|editInboundAddr|addDiscount|changeDiscount)',
        'PaymentMethods'=>'^(changePaymentKeys|editRewardChannel|editLockChannel)',
        'Payments'=>'^(setAutoApprove|addAutoApprove|removeAutoApprove|autoCancelOrder|reward)',
        'Broadcast'=>'^(message2All|forwardToAll|broadcast)',
        'Pins'=>'^(proPin|proSetPin|pinToAll)',
        'Support'=>'^(editDiag|addTicket|answer_|reply)',
        'Messages'=>'^(messageToSpeceficUser|sendMessageToUser|xuiMsg|sendMsg_)',
        'Reports'=>'^(decPayment|setReport|setDailyChannel)',
        'Content'=>'^(editStart|editPurchaseRules|adminHelp|adminAppTutorial|adminServiceTutorial)',
        'Appearance'=>'^(setMainButton|addNewMainButton)',
        'Settings'=>'^(editSwitch|editInvite|editRewardTime)',
    ];
    foreach($groups as $name=>$regex) if(preg_match('/'.$regex.'/', $step)) return $name;
    return 'Main';
}

function v2raystore_isAdminReadScreen($callback){
    if(in_array($callback, [
        'agentsList','rejectedAgentList','serversSetting','categoriesSetting','backplan',
        'discount_codes','botReports','gateWays_Channels','proC2CMenu','rewardSettings',
        'autoApproveOrdersMenu','newMemberAccessMenu','joinExemptMenu','proLeaveNoticeMenu',
        'customerGroupsMenu','cgCode','adminsList','broadcastQueueStatus','broadcastPinsMenu','proPinMenu','ticketsList',
        'xuiMsgMenu','adminTextSettings','adminHelpMenu','botSettings','mainMenuButtons',
        'userButtonSettings','testAccountManagement','cleanOldConfigsMenu',
        'updateConfigsMenu','inboundMoveMenu'
    ], true)) return true;
    return (bool)preg_match('/^(?:(?:agentDetails|agentPercentDetails|nextAgentList|nextServerPage|plansList|planDetails|nextCategoryPage|testPlanDetails)[0-9]+|showServerSettings[0-9]+_[0-9]+|botSettings[A-Za-z]+)$/D', $callback);
}

function v2raystore_agentOptionsKeys(){
    return v2raystore_adminMenuKeys('AgentOptions');
}

// Pack actual settings as two actionable buttons per row. Preserve callbacks.
function v2raystore_compactSettingsKeys($markup){
    $decoded = json_decode($markup, true);
    if(!is_array($decoded) || !isset($decoded['inline_keyboard'])) return $markup;
    $actions = []; $navigation = [];
    foreach($decoded['inline_keyboard'] as $row){
        $labels = []; $buttons = [];
        foreach($row as $button){
            if(($button['callback_data'] ?? '') === 'v2raystore') $labels[] = (string)$button['text'];
            elseif(preg_match('/بازگشت|برگشت/u',(string)($button['text'] ?? ''))) $navigation[] = $button;
            else $buttons[] = $button;
        }
        if(count($buttons) === 1 && $labels){
            $buttons[0]['text'] = implode(' / ', $labels) . ' · ' . $buttons[0]['text'];
        }
        foreach($buttons as $button) $actions[] = $button;
    }
    $decoded['inline_keyboard'] = array_chunk($actions, 2);
    foreach(array_chunk($navigation, 2) as $row) $decoded['inline_keyboard'][] = $row;
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
