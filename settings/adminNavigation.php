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
        $botState = v2raystore_applyRoleSpecificStates(v2raystore_getBotStatesArray(true), $recipient);
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

// Each entry: title, parent, child buttons. One action per row; no decorative dead buttons.
function v2raystore_adminMenuTree(){
    return [
        'Main'=>['🧭 مدیریت ربات', 'mainMenu', [
            ['📊 آمار و گزارش‌ها','adminReportsMenu'], ['🧾 مدیریت سرویس‌ها','adminConfigsMenu'],
            ['🖥 سرورها و پلن‌ها','adminSalesMenu'], ['💳 پرداخت و جایزه','adminPaymentsMenu'],
            ['👥 کاربران و نمایندگان','adminUsersMenu'], ['📨 پیام‌ها و پشتیبانی','adminMessagesMenu'],
            ['📝 محتوا و آموزش','adminContentMenu'], ['⚙️ تنظیمات ربات','adminSettingsMenu']]],
        'Reports'=>['📊 آمار و گزارش‌ها','adminMainMenu',[
            ['📈 آمار کلی ربات','botReports'],['👤 گزارش یک کاربر','userReports'],
            ['📊 تنظیمات گزارش و آمار کانال','reportChannelSettingsMenu']]],
        'Configs'=>['🧾 مدیریت سرویس‌ها','adminMainMenu',[
            ['🔎 جستجوی کاربر یا کانفیگ','searchUsersConfig'],['➕ ساخت و ثبت سرویس','adminConfigCreateMenu'],
            ['♻️ بروزرسانی و انتقال','adminConfigUpdateMenu'],['🧪 اکانت‌های تست','testAccountManagement'],
            ['🗑 پاکسازی سرویس‌های تمام‌شده','cleanOldConfigsMenu']]],
        'ConfigCreate'=>['➕ ساخت و ثبت سرویس','adminConfigsMenu',[
            ['➕ ثبت کانفیگ موجود برای کاربر','manualAttachConfig'],['📦 ساخت چند اکانت','createMultipleAccounts']]],
        'ConfigUpdate'=>['♻️ بروزرسانی و انتقال','adminConfigsMenu',[
            ['♻️ ارسال و بروزرسانی کانفیگ‌ها','updateConfigsMenu'],['🔁 انتقال بین اینباندها','inboundMoveMenu']]],
        'Sales'=>['🖥 سرورها و پلن‌ها','adminMainMenu',[
            ['🖥 مدیریت سرورها','serversSetting'],['📦 پلن‌ها و دسته‌بندی‌ها','adminCatalogMenu'],
            ['🏷 کدهای تخفیف','discount_codes']]],
        'Catalog'=>['📦 پلن‌ها و دسته‌بندی‌ها','adminSalesMenu',[
            ['📦 مدیریت پلن‌ها','backplan'],['🗂 مدیریت دسته‌بندی‌ها','categoriesSetting']]],
        'Payments'=>['💳 پرداخت و جایزه','adminMainMenu',[
            ['🏦 روش‌ها و اطلاعات پرداخت','adminPaymentMethodsMenu'],['⏱ تأیید خودکار سفارش','autoApproveOrdersMenu'],
            ['🎁 جایزه خرید و تمدید','rewardSettings']]],
        'PaymentMethods'=>['🏦 روش‌ها و اطلاعات پرداخت','adminPaymentsMenu',[
            ['💳 حساب‌ها، درگاه‌ها و کانال‌ها','gateWays_Channels'],['💳 تنظیمات کارت‌به‌کارت','proC2CMenu']]],
        'Users'=>['👥 کاربران و نمایندگان','adminMainMenu',[
            ['👤 اطلاعات و زیرمجموعه‌ها','adminUserLookupMenu'],['💰 مدیریت کیف پول کاربران','adminWalletMenu'],
            ['🚫 مسدودی و رفع مسدودی','adminBlocksMenu'],['🤝 مدیریت نمایندگی','adminAgentsMenu'],
            ['🔐 قوانین ورود و عضویت','adminAccessMenu'],['👮 مدیران ربات','adminsList']]],
        'UserLookup'=>['👤 اطلاعات و زیرمجموعه‌ها','adminUsersMenu',[
            ['👤 گزارش یک کاربر','userReports'],['👥 زیرمجموعه‌های کاربر','proReferralAsk']]],
        'Wallet'=>['💰 مدیریت کیف پول کاربران','adminUsersMenu',[
            ['➕ افزایش موجودی','increaseUserWallet'],['➖ کاهش موجودی','decreaseUserWallet']]],
        'Blocks'=>['🚫 مسدودی و رفع مسدودی','adminUsersMenu',[
            ['🚫 مسدودسازی عادی','banUser'],['🔇 مسدودسازی بی‌صدا','silentBanUser'],
            ['✅ رفع مسدودی عادی / بی‌صدا','unbanUser']]],
        'Agents'=>['🤝 مدیریت نمایندگی','adminUsersMenu',[
            ['👥 فهرست و تنظیمات نمایندگان','agentsList'],['➕ افزودن نماینده','addAgentManual'],
            ['📋 درخواست‌های ردشده','rejectedAgentList']]],
        'Access'=>['🔐 قوانین ورود و عضویت','adminUsersMenu',[
            ['🔑 دسترسی اعضای جدید','newMemberAccessMenu'],['🚪 معافیت عضویت اجباری','joinExemptMenu'],
            ['📩 پیام ترک کانال','proLeaveNoticeMenu']]],
        'Messages'=>['📨 پیام‌ها و پشتیبانی','adminMainMenu',[
            ['✉️ پیام به یک کاربر','messageToSpeceficUser'],['📣 پیام‌های همگانی','adminBroadcastMenu'],
            ['📌 پیام‌های پین‌شده','adminPinsMenu'],['🎫 تیکت و خطایابی','adminSupportMenu'],
            ['⏳ اعلان حجم و انقضای سرویس','xuiMsgMenu']]],
        'Broadcast'=>['📣 پیام‌های همگانی','adminMessagesMenu',[
            ['📝 ارسال پیام همگانی','message2All'],['↪️ فوروارد همگانی','forwardToAll'],
            ['📊 وضعیت صف ارسال','broadcastQueueStatus']]],
        'Pins'=>['📌 پیام‌های پین‌شده','adminMessagesMenu',[
            ['📌 فهرست پین‌های همگانی','broadcastPinsMenu'],['➕ پین متن، تصویر یا فایل','proPinMenu']]],
        'Support'=>['🎫 تیکت و خطایابی','adminMessagesMenu',[
            ['🎫 تیکت‌ها','ticketsList'],['🛠 متن راهنمای خطایابی','editDiagAdminText']]],
        'Content'=>['📝 محتوا و آموزش','adminMainMenu',[
            ['📝 خوش‌آمد و قوانین خرید','adminTextSettings'],['📚 آموزش‌ها و سوالات متداول','adminHelpMenu']]],
        'Settings'=>['⚙️ تنظیمات ربات','adminMainMenu',[
            ['⚙️ امکانات و وضعیت ربات','botSettings'],['🎛 ظاهر و دکمه‌ها','adminAppearanceMenu']]],
        'Appearance'=>['🎛 ظاهر و دکمه‌ها','adminSettingsMenu',[
            ['➕ دکمه‌های سفارشی صفحه اصلی','mainMenuButtons'],['🎛 ترتیب و نمایش دکمه‌های کاربر','userButtonSettings']]],
        // Older messages with the old Quick callback remain usable.
        'Quick'=>['⚡ دسترسی سریع','adminMainMenu',[
            ['🔎 جستجوی کانفیگ','searchUsersConfig'],['✉️ پیام به کاربر','messageToSpeceficUser'],
            ['♻️ بروزرسانی کانفیگ‌ها','updateConfigsMenu'],['📦 پلن‌ها','backplan']]]
    ];
}

function v2raystore_adminMenuKeys($name){
    global $from_id, $admin;
    $tree = v2raystore_adminMenuTree();
    $entry = $tree[$name] ?? $tree['Main'];
    $rows = [];
    foreach($entry[2] as [$title,$callback]){
        if($callback === 'adminsList' && (int)$from_id !== (int)$admin) continue;
        $rows[] = [['text'=>$title,'callback_data'=>$callback]];
    }
    $rows[] = [['text'=>'⬅️ بازگشت','callback_data'=>$entry[1]]];
    if($name !== 'Main') $rows[] = [['text'=>'🏠 صفحه اصلی مدیریت','callback_data'=>'adminMainMenu']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_adminMenuText($name){
    $tree = v2raystore_adminMenuTree();
    $entry = $tree[$name] ?? $tree['Main'];
    if($name === 'Main' && function_exists('v2raystore_adminDashboardText')) return v2raystore_adminDashboardText();
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
        'menu'=>v2raystore_adminMenuFromMarkup($message->reply_markup),
    ];
    v2raystore_setSettingValue($key, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function v2raystore_restoreFormOrigin(){
    global $from_id, $removeKeyboard, $userInfo;
    $raw = v2raystore_getSettingValue('FORM_ORIGIN_' . (int)$from_id, '');
    $origin = json_decode($raw, true);
    if(!is_array($origin) || empty($origin['text']) || empty($origin['reply_markup']['inline_keyboard'])) return false;
    if(time() - (int)($origin['time'] ?? 0) > 86400) return false;
    setUser();
    setUser('', 'temp');
    $userInfo['step'] = 'none';
    sendMessage('↩️ عملیات لغو شد.', $removeKeyboard, null);
    bot('sendMessage',[
        'chat_id'=>$from_id,
        'text'=>$origin['text'],
        'entities'=>json_encode($origin['entities'] ?? [], JSON_UNESCAPED_UNICODE),
        'reply_markup'=>json_encode($origin['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return true;
}

function v2raystore_handleAdminNavigation(){
    global $from_id, $admin, $userInfo, $data, $text, $buttonValues, $message_id, $update, $removeKeyboard;
    if((int)$from_id !== (int)$admin && empty($userInfo['isAdmin'])) return;
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
        $expected = json_decode(v2raystore_adminMenuKeys($name),true)['inline_keyboard'];
        $expectedCallbacks = [];
        foreach($expected as $row) foreach($row as $button) $expectedCallbacks[] = $button['callback_data'];
        if($callbacks === $expectedCallbacks) return 'admin' . $name . 'Menu';
    }
    return '';
}

function v2raystore_adjustAdminBackMarkup($markup, $recipientId){
    global $from_id, $admin, $userInfo, $update, $data;
    if((int)$recipientId !== (int)$from_id
        || ((int)$from_id !== (int)$admin && empty($userInfo['isAdmin']))) return $markup;
    $decoded = is_string($markup) ? json_decode($markup,true) : $markup;
    if(!is_array($decoded) || !isset($decoded['inline_keyboard'])) return $markup;
    $tree = v2raystore_adminMenuTree();
    if(preg_match('/^admin([A-Za-z]+)Menu$/D',(string)($data ?? ''),$m) && isset($tree[$m[1]])) return $markup;
    $source = $update->callback_query->message->reply_markup ?? null;
    $parent = $source ? v2raystore_adminMenuFromMarkup($source) : '';
    // A settings toggle re-renders the same page: retain its existing back destination.
    if($parent === '' && $source){
        $source = json_decode(json_encode($source),true);
        foreach(($source['inline_keyboard'] ?? []) as $row){
            foreach($row as $button){
                if(preg_match('/بازگشت|برگشت/u',(string)($button['text'] ?? ''))
                    && preg_match('/^admin[A-Za-z]+Menu$/D',(string)($button['callback_data'] ?? ''))){
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
    if($parent === '') return $markup;
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
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_cancelMenuForStep($step){
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
        'adminsList','broadcastQueueStatus','broadcastPinsMenu','proPinMenu','ticketsList',
        'xuiMsgMenu','adminTextSettings','adminHelpMenu','botSettings','mainMenuButtons',
        'userButtonSettings','testAccountManagement','cleanOldConfigsMenu',
        'updateConfigsMenu','inboundMoveMenu'
    ], true)) return true;
    return (bool)preg_match('/^(?:(?:agentDetails|agentPercentDetails|nextAgentList|nextServerPage|plansList|planDetails|nextCategoryPage|testPlanDetails)[0-9]+|showServerSettings[0-9]+_[0-9]+|botSettings[A-Za-z]+)$/D', $callback);
}
