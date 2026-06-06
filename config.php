<?php
include_once "settings/values.php";
include_once 'settings/jdf.php';
include_once 'baseInfo.php';

$connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
if($connection->connect_error){
    exit("error " . $connection->connect_error);  
}
$connection->set_charset("utf8mb4");


function wizwiz_cleanSingleDomainHost($domain){
    $domain = trim(str_replace(["\r", "\n", "\t"], "", (string)$domain));
    if($domain === "") return "";

    // Accept values like domain.com, https://domain.com:443/path, or domain.com:443/path
    $parseSource = preg_match('/^https?:\/\//i', $domain) ? $domain : ('http://' . $domain);
    $parsed = @parse_url($parseSource);
    if(is_array($parsed) && !empty($parsed['host'])){
        $domain = $parsed['host'];
    }else{
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        $domain = explode('/', $domain, 2)[0];
        // Remove a simple :port from normal hostnames, but leave IPv6-style values alone.
        if(substr_count($domain, ':') === 1) $domain = preg_replace('/:\d+$/', '', $domain);
    }

    return trim($domain, " \t\n\r\0\x0B[]");
}

function wizwiz_normalizePlanDomainInput($domain){
    $domain = trim((string)$domain);
    if($domain === "") return "";

    $lines = preg_split('/\r\n|\r|\n/', $domain);
    $clean = [];
    foreach($lines as $line){
        $host = wizwiz_cleanSingleDomainHost($line);
        if($host !== "") $clean[] = $host;
    }
    $clean = array_values(array_unique($clean));
    return implode("\n", $clean);
}


function wizwiz_pickHostValue($value){
    if($value === null) return '';
    if(is_string($value) || is_numeric($value)){
        $value = trim((string)$value);
        return ($value === '' || strtolower($value) === 'null') ? '' : $value;
    }
    if(is_object($value)) $value = get_object_vars($value);
    if(is_array($value)){
        foreach(['Host','host','HOST'] as $key){
            if(array_key_exists($key, $value)){
                $picked = wizwiz_pickHostValue($value[$key]);
                if($picked !== '') return $picked;
            }
        }
        if(isset($value['name']) && isset($value['value']) && strtolower(trim((string)$value['name'])) === 'host'){
            $picked = wizwiz_pickHostValue($value['value']);
            if($picked !== '') return $picked;
        }
        foreach($value as $item){
            $picked = wizwiz_pickHostValue($item);
            if($picked !== '') return $picked;
        }
    }
    return '';
}

function wizwiz_extractWsSettings($streamSettings, $fallbackHost = ''){
    if(is_string($streamSettings)){
        $decoded = json_decode($streamSettings);
        if(json_last_error() === JSON_ERROR_NONE) $streamSettings = $decoded;
    }
    $wsSettings = null;
    if(is_object($streamSettings) && isset($streamSettings->wsSettings)) $wsSettings = $streamSettings->wsSettings;
    elseif(is_array($streamSettings) && isset($streamSettings['wsSettings'])) $wsSettings = $streamSettings['wsSettings'];

    $path = '/';
    $host = '';
    $headerType = 'none';

    if($wsSettings !== null){
        if(is_string($wsSettings)){
            $decodedWs = json_decode($wsSettings);
            if(json_last_error() === JSON_ERROR_NONE) $wsSettings = $decodedWs;
        }
        $wsArr = is_object($wsSettings) ? get_object_vars($wsSettings) : (is_array($wsSettings) ? $wsSettings : []);
        if(isset($wsArr['path']) && trim((string)$wsArr['path']) !== '') $path = (string)$wsArr['path'];
        if(isset($wsArr['host'])) $host = wizwiz_pickHostValue($wsArr['host']);
        if($host === '' && isset($wsArr['headers'])) $host = wizwiz_pickHostValue($wsArr['headers']);
        if($host === '' && isset($wsArr['header'])) $host = wizwiz_pickHostValue($wsArr['header']);
        if(isset($wsArr['header'])){
            $headerArr = is_object($wsArr['header']) ? get_object_vars($wsArr['header']) : (is_array($wsArr['header']) ? $wsArr['header'] : []);
            if(isset($headerArr['type']) && trim((string)$headerArr['type']) !== '') $headerType = trim((string)$headerArr['type']);
        }
    }

    if($host === '') $host = wizwiz_cleanSingleDomainHost($fallbackHost);
    return ['path' => ($path !== '' ? $path : '/'), 'host' => $host, 'header_type' => $headerType];
}

function wizwiz_schemaPatchDone($key){
    global $connection;
    $type = 'SCHEMA_PATCH_' . $key;
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row && (($row['value'] ?? '') === 'done');
}

function wizwiz_markSchemaPatchDone($key){
    global $connection;
    $type = 'SCHEMA_PATCH_' . $key;
    $value = 'done';
    $stmt = @$connection->prepare("SELECT `id` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();

    if($exists){
        $stmt = @$connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $value, $type);
    }else{
        $stmt = @$connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $type, $value);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_ensurePlanCustomDomainColumn(){
    global $connection;
    if(wizwiz_schemaPatchDone('PLAN_CUSTOM_DOMAIN_V1')) return;
    $exists = @($connection->query("SHOW COLUMNS FROM `server_plans` LIKE 'custom_domain'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `server_plans` ADD `custom_domain` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL AFTER `custom_sni`"));
    }
    wizwiz_markSchemaPatchDone('PLAN_CUSTOM_DOMAIN_V1');
}
wizwiz_ensurePlanCustomDomainColumn();

function wizwiz_ensureExtraUserColumns(){
    global $connection;
    if(wizwiz_schemaPatchDone('USERS_ACCESS_JOIN_CARD_V2')) return;
    $columns = [
        'approval_status' => "ALTER TABLE `users` ADD `approval_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL DEFAULT 'approved' AFTER `spam_info`",
        'approval_referrer' => "ALTER TABLE `users` ADD `approval_referrer` bigint(10) DEFAULT NULL AFTER `approval_status`",
        'approval_request_date' => "ALTER TABLE `users` ADD `approval_request_date` int(255) NOT NULL DEFAULT 0 AFTER `approval_referrer`",
        'join_exempt' => "ALTER TABLE `users` ADD `join_exempt` tinyint(1) NOT NULL DEFAULT 0 AFTER `approval_request_date`",
        'access_exempt' => "ALTER TABLE `users` ADD `access_exempt` tinyint(1) NOT NULL DEFAULT 0 AFTER `join_exempt`",
        'card_info_version' => "ALTER TABLE `users` ADD `card_info_version` int(11) NOT NULL DEFAULT 0 AFTER `access_exempt`",
    ];

    foreach($columns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `users` LIKE '$column'"));
        if($exists && $exists->num_rows == 0){
            @($connection->query($query));
        }
    }
    wizwiz_markSchemaPatchDone('USERS_ACCESS_JOIN_CARD_V2');
}
wizwiz_ensureExtraUserColumns();

function wizwiz_ensureAccessCodeAuditColumns(){
    global $connection;
    if(wizwiz_schemaPatchDone('USERS_ACCESS_CODE_AUDIT_V1')) return;
    $columns = [
        'access_code_used' => "ALTER TABLE `users` ADD `access_code_used` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL AFTER `access_exempt`",
        'access_code_date' => "ALTER TABLE `users` ADD `access_code_date` int(255) NOT NULL DEFAULT 0 AFTER `access_code_used`",
        'access_code_revoked' => "ALTER TABLE `users` ADD `access_code_revoked` tinyint(1) NOT NULL DEFAULT 0 AFTER `access_code_date`",
    ];
    foreach($columns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `users` LIKE '$column'"));
        if($exists && $exists->num_rows == 0){
            @($connection->query($query));
        }
    }
    wizwiz_markSchemaPatchDone('USERS_ACCESS_CODE_AUDIT_V1');
}
wizwiz_ensureAccessCodeAuditColumns();


function wizwiz_ensureTestAccountManagementColumns(){
    global $connection;
    if(function_exists('wizwiz_schemaPatchDone') && wizwiz_schemaPatchDone('USERS_TEST_ACCOUNT_MGMT_V1')) return;
    $columns = [
        'test_account_exempt' => "ALTER TABLE `users` ADD `test_account_exempt` tinyint(1) NOT NULL DEFAULT 0 AFTER `freetrial`",
        'test_account_limit' => "ALTER TABLE `users` ADD `test_account_limit` int(11) DEFAULT NULL AFTER `test_account_exempt`",
        'test_account_count' => "ALTER TABLE `users` ADD `test_account_count` int(11) NOT NULL DEFAULT 0 AFTER `test_account_limit`",
    ];
    foreach($columns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `users` LIKE '$column'"));
        if($exists && $exists->num_rows == 0){
            @($connection->query($query));
        }
    }
    if(function_exists('wizwiz_markSchemaPatchDone')) wizwiz_markSchemaPatchDone('USERS_TEST_ACCOUNT_MGMT_V1');
}
wizwiz_ensureTestAccountManagementColumns();


function wizwiz_ensureBroadcastTargetColumn(){
    global $connection;
    if(wizwiz_schemaPatchDone('SEND_LIST_TARGET_TYPE_V1')) return;
    $exists = @($connection->query("SHOW COLUMNS FROM `send_list` LIKE 'target_type'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `send_list` ADD `target_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL DEFAULT 'all' AFTER `state`"));
    }
    wizwiz_markSchemaPatchDone('SEND_LIST_TARGET_TYPE_V1');
}
wizwiz_ensureBroadcastTargetColumn();

function farid_normalizeBroadcastTarget($target){
    $target = trim((string)$target);
    // برای سازگاری با صف‌های قدیمی، targetهای قبلی هنوز شناخته می‌شوند؛
    // اما در منوی جدید فقط all و approved نمایش داده می‌شوند.
    $allowed = ['all', 'approved', 'buyers', 'access_code'];
    return in_array($target, $allowed, true) ? $target : 'all';
}

function farid_getBroadcastTargetTitle($target){
    $target = farid_normalizeBroadcastTarget($target);
    $titles = [
        'all' => 'همه کاربران ثبت‌شده در ربات',
        'approved' => 'کاربرانی که دسترسی فعال به ربات دارند',
        'buyers' => 'فقط کاربرانی که سابقه خرید دارند',
        'access_code' => 'فقط کاربرانی که با کد ورود آزاد شده‌اند',
    ];
    return $titles[$target] ?? $titles['all'];
}

function farid_getBroadcastTargetCondition($target, $userAlias = 'u'){
    global $admin;
    $target = farid_normalizeBroadcastTarget($target);
    $u = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$userAlias);
    if($u === '') $u = 'u';
    $adminId = intval($admin ?? 0);

    $buyerCondition = "(EXISTS (SELECT 1 FROM `orders_list` o WHERE o.`userid` = {$u}.`userid` AND o.`status` = 1) OR EXISTS (SELECT 1 FROM `pays` p WHERE p.`user_id` = {$u}.`userid` AND p.`state` IN ('paid','approved')))";
    $accessCodeCondition = "(COALESCE({$u}.`access_code_used`, '') != '' AND COALESCE({$u}.`access_code_revoked`, 0) = 0)";
    $manualApprovalCondition = "(COALESCE({$u}.`approval_status`, '') = 'approved' AND COALESCE({$u}.`approval_request_date`, 0) > 0)";

    switch($target){
        // این دو حالت برای صف‌های قدیمی نگه داشته شده‌اند.
        case 'buyers':
            return $buyerCondition;
        case 'access_code':
            return $accessCodeCondition;
        case 'approved':
            // این گزینه نباید همه کاربران قدیمی را حساب کند. مقدار پیش‌فرض approval_status در نصب‌های قدیمی
            // برای خیلی از کاربران approved است، بنابراین فقط approvalهایی حساب می‌شوند که واقعاً درخواست تایید داشته‌اند.
            // معیار دسترسی فعال: ادمین‌ها، خریداران قبلی، کاربران آزادشده با کد ورود، معافیت دسترسی، یا تایید دستی واقعی.
            return "({$u}.`userid` = '{$adminId}' OR {$u}.`isAdmin` = 1 OR COALESCE({$u}.`access_exempt`, 0) = 1 OR $accessCodeCondition OR $buyerCondition OR $manualApprovalCondition)";
        case 'all':
        default:
            return "1=1";
    }
}

function farid_countBroadcastTargets($target){
    global $connection;
    $target = farid_normalizeBroadcastTarget($target);
    $condition = farid_getBroadcastTargetCondition($target, 'u');
    $sql = "SELECT COUNT(*) AS `cnt` FROM `users` u WHERE $condition";
    $res = @$connection->query($sql);
    if(!$res) return 0;
    $row = $res->fetch_assoc();
    return intval($row['cnt'] ?? 0);
}

function farid_getBroadcastTargetKeyboard($mode = 'message'){
    $mode = ($mode === 'forward') ? 'forward' : 'message';
    $prefix = ($mode === 'forward') ? 'broadcastTargetForward_' : 'broadcastTargetMessage_';
    return json_encode(['inline_keyboard'=>[
        [['text'=>'🎯 انتخاب گروه مخاطب', 'callback_data'=>'wizwizch', 'style'=>'primary']],
        [['text'=>'🌍 پیام برای همه کاربران', 'callback_data'=>$prefix.'all', 'style'=>'success']],
        [['text'=>'✅ پیام برای کاربران دارای دسترسی', 'callback_data'=>$prefix.'approved', 'style'=>'primary']],
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel']],
    ]], JSON_UNESCAPED_UNICODE);
}

function farid_getActiveBroadcastQueueText(){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` = 1 AND `type` != 'updateConfigs' LIMIT 1");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();

    if($info->num_rows <= 0) return null;
    $sendInfo = $info->fetch_assoc();
    $offset = intval($sendInfo['offset'] ?? 0);
    $type = $sendInfo['type'] ?? 'text';
    $target = farid_normalizeBroadcastTarget($sendInfo['target_type'] ?? 'all');
    $usersCount = farid_countBroadcastTargets($target);
    $leftMessages = max(0, $usersCount - $offset);
    $targetTitle = farid_getBroadcastTargetTitle($target);

    if($type == 'forwardall'){
        return "❗️ یک فوروارد همگانی در صف انتشار است. لطفاً تا پایان عملیات صبور باشید.\n\n" .
               "🎯 گروه مخاطب: $targetTitle\n" .
               "🔰 تعداد مخاطبان: $usersCount\n" .
               "☑️ فوروارد شده: $offset\n" .
               "📣 باقی‌مانده: $leftMessages";
    }

    return "❗️ یک پیام همگانی در صف انتشار است. لطفاً تا پایان عملیات صبور باشید.\n\n" .
           "🎯 گروه مخاطب: $targetTitle\n" .
           "🔰 تعداد مخاطبان: $usersCount\n" .
           "☑️ ارسال شده: $offset\n" .
           "📣 باقی‌مانده: $leftMessages";
}

function wizwiz_getUserByTelegramId($userId){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->num_rows > 0 ? $res->fetch_assoc() : null;
    $stmt->close();
    return $user;
}

function wizwiz_setUserApprovalStatus($userId, $status, $referrerId = null){
    global $connection;
    $time = time();
    if($referrerId === null || $referrerId === ''){
        $stmt = $connection->prepare("UPDATE `users` SET `approval_status` = ?, `approval_referrer` = NULL, `approval_request_date` = ?, `step` = 'none' WHERE `userid` = ?");
        $stmt->bind_param("sii", $status, $time, $userId);
    }else{
        $stmt = $connection->prepare("UPDATE `users` SET `approval_status` = ?, `approval_referrer` = ?, `approval_request_date` = ?, `refered_by` = ?, `step` = 'newMemberApprovalWait' WHERE `userid` = ?");
        $stmt->bind_param("siiii", $status, $referrerId, $time, $referrerId, $userId);
    }
    $stmt->execute();
    $stmt->close();
}

function wizwiz_createPendingUserIfNeeded($userId, $firstName, $userName){
    global $connection;
    $existing = wizwiz_getUserByTelegramId($userId);
    if($existing) return $existing;

    $firstName = !empty($firstName) ? $firstName : ' ';
    $userName = !empty($userName) ? $userName : ' ';
    $time = time();
    $status = 'pending';
    $step = 'newMemberEnterReferrer';
    $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `step`, `approval_status`, `approval_request_date`) VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)");
    $stmt->bind_param("ississi", $userId, $firstName, $userName, $time, $step, $status, $time);
    $stmt->execute();
    $stmt->close();

    return wizwiz_getUserByTelegramId($userId);
}

function wizwiz_isUserApprovedForLock($userInfo){
    if(!$userInfo) return false;
    return !isset($userInfo['approval_status']) || $userInfo['approval_status'] == 'approved';
}

function wizwiz_getBotStatesArray($force = false){
    global $connection;
    static $cache = null;
    if(!$force && is_array($cache)) return $cache;
    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'BOT_STATES' LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    $state = $row ? json_decode((string)$row['value'], true) : [];
    $cache = is_array($state) ? $state : [];
    return $cache;
}

function wizwiz_saveBotStatesArray($states){
    global $connection, $botState;
    if(!is_array($states)) $states = [];
    $value = json_encode($states, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("SELECT COUNT(*) AS cnt FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    if($cnt > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'");
        $stmt->bind_param('s', $value);
    }else{
        $type = 'BOT_STATES';
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        $stmt->bind_param('ss', $type, $value);
    }
    $ok = $stmt->execute();
    $stmt->close();
    $botState = $states;
    wizwiz_getBotStatesArray(true);
    return $ok;
}


function wizwiz_isAgentUser($user = null){
    if($user === null) $user = $GLOBALS['userInfo'] ?? null;
    return is_array($user) && !empty($user['is_agent']) && intval($user['is_agent']) === 1;
}

function wizwiz_effectiveRoleState($state, $baseKey, $agentKey, $user = null){
    if(!is_array($state)) $state = [];
    if(wizwiz_isAgentUser($user)){
        if(array_key_exists($agentKey, $state) && in_array($state[$agentKey], ['on','off'], true)){
            return $state[$agentKey];
        }
    }
    return (isset($state[$baseKey]) && $state[$baseKey] === 'on') ? 'on' : 'off';
}

function wizwiz_applyRoleSpecificStates($state, $user = null){
    if(!is_array($state)) $state = [];
    // برای جلوگیری از تغییر زیاد در سورس قدیمی، فقط در زمان اجرای درخواست همان کاربر
    // مقدارهای عمومی sellState/walletState با مقدار مخصوص نقش او جایگزین می‌شود.
    // برای ادمین‌ها و کاربران عادی رفتار قبلی حفظ می‌شود؛ برای نماینده‌ها می‌توان فروش/کیف پول جداگانه داشت.
    if(wizwiz_isAgentUser($user)){
        if(array_key_exists('agentSellState', $state) && in_array($state['agentSellState'], ['on','off'], true)){
            $state['sellState'] = $state['agentSellState'];
        }
        if(array_key_exists('agentWalletState', $state) && in_array($state['agentWalletState'], ['on','off'], true)){
            $state['walletState'] = $state['agentWalletState'];
        }
    }
    return $state;
}

function wizwiz_isWalletOpenForCurrentUser(){
    global $botState, $from_id, $admin, $userInfo;
    if($from_id == $admin || (!empty($userInfo) && !empty($userInfo['isAdmin']))) return true;
    return (($botState['walletState'] ?? 'off') === 'on');
}

function wizwiz_ensureBasicUserRecord($userId, $name = '', $username = ''){
    global $connection;
    $userId = (int)$userId;
    if($userId <= 0) return false;
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();
    if($exists) return true;

    $name = trim((string)$name);
    if($name === '') $name = 'کاربر ' . $userId;
    $username = trim((string)$username);
    if($username === '') $username = 'ندارد';
    $time = time();
    $step = 'none';
    $discount = json_encode(['normal'=>0], JSON_UNESCAPED_UNICODE);
    $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `step`, `is_agent`, `discount_percent`, `agent_date`) VALUES (?, ?, ?, 0, 0, ?, ?, 0, ?, 0)");
    if(!$stmt) return false;
    $stmt->bind_param('ississ', $userId, $name, $username, $time, $step, $discount);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getNewMemberAccessMode($state = null){
    if($state === null) $state = wizwiz_getBotStatesArray();
    if(!is_array($state)) $state = [];
    $mode = $state['newMemberAccessMode'] ?? null;
    if(!in_array($mode, ['open','existing','buyers','approval'], true)){
        $mode = (($state['newMemberLockState'] ?? 'off') == 'on') ? 'approval' : 'open';
    }
    return $mode;
}

function wizwiz_newMemberAccessModeTitle($mode){
    switch($mode){
        case 'approval': return '🔐 تایید دستی با معرف';
        case 'buyers': return '🛒 فقط خریداران قبلی';
        case 'existing': return '👥 فقط کاربران قبلی ربات';
        default: return '🌍 آزاد برای همه';
    }
}

function wizwiz_setNewMemberAccessMode($mode){
    global $botState;
    if(!in_array($mode, ['open','existing','buyers','approval'], true)) $mode = 'open';
    $state = wizwiz_getBotStatesArray();
    $oldMode = wizwiz_getNewMemberAccessMode($state);
    $state['newMemberAccessMode'] = $mode;
    $state['newMemberLockState'] = ($mode === 'approval') ? 'on' : 'off';
    if($oldMode !== $mode || empty($state['newMemberAccessStartedAt'])){
        $state['newMemberAccessStartedAt'] = time();
    }
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return $state;
}


function wizwiz_getBuyersAccessCode($state = null){
    if($state === null) $state = wizwiz_getBotStatesArray();
    if(!is_array($state)) $state = [];
    return trim((string)($state['buyersAccessCode'] ?? ''));
}

function wizwiz_setBuyersAccessCode($code){
    global $botState;
    $code = trim((string)$code);
    // کد کوتاه/طولانی عجیب ذخیره نشود، ولی اجازه حروف، عدد، خط تیره و زیرخط داده می‌شود.
    $code = preg_replace('/[^A-Za-z0-9_\-]/', '', $code);
    $state = wizwiz_getBotStatesArray();
    $state['buyersAccessCode'] = $code;
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return $code;
}

function wizwiz_generateBuyersAccessCode(){
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'VIP-';
    for($i=0; $i<8; $i++){
        $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return wizwiz_setBuyersAccessCode($code);
}

function wizwiz_normalizeAccessCodeText($text){
    $text = trim((string)$text);
    if(preg_match('/^\/start\s+(.+)$/i', $text, $m)) $text = trim($m[1]);
    return preg_replace('/\s+/', '', $text);
}

function wizwiz_userIsAccessExempt($userInfo){
    return !empty($userInfo) && !empty($userInfo['access_exempt']);
}

function wizwiz_setUserAccessExempt($userId, $enabled = true, $code = null){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $enabled = $enabled ? 1 : 0;
    if($enabled){
        $safeCode = $code === null ? null : trim((string)$code);
        $now = time();
        $stmt = $connection->prepare("UPDATE `users` SET `access_exempt` = 1, `approval_status` = 'approved', `step` = 'none', `access_code_used` = ?, `access_code_date` = ?, `access_code_revoked` = 0 WHERE `userid` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('sii', $safeCode, $now, $userId);
    }else{
        $stmt = $connection->prepare("UPDATE `users` SET `access_exempt` = 0, `access_code_revoked` = 1 WHERE `userid` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('i', $userId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getUserDisplayForAdmin($userId){
    $user = wizwiz_getUserByTelegramId($userId);
    $name = htmlspecialchars((string)($user['name'] ?? 'کاربر'), ENT_QUOTES, 'UTF-8');
    $username = trim((string)($user['username'] ?? ''));
    $username = $username !== '' ? '@' . ltrim($username, '@') : 'ندارد';
    return [$user, $name, htmlspecialchars($username, ENT_QUOTES, 'UTF-8')];
}

function wizwiz_getAccessCodeAdminActionKeys($userId){
    $userId = intval($userId);
    return wizwiz_inlineKeyboardJson([
        [['text'=>'🎟 مدیریت دسترسی کد ورود', 'callback_data'=>'wizwizch', 'style'=>'primary']],
        [
            ['text'=>'🧹 حذف دسترسی کد', 'callback_data'=>'revokeCodeAccess' . $userId, 'style'=>'danger'],
            ['text'=>'🚫 بلاک کاربر', 'callback_data'=>'blockCodeAccess' . $userId, 'style'=>'danger']
        ]
    ]);
}

function wizwiz_sendAccessCodeLoginNotice($userId, $code){
    $userId = intval($userId);
    [$user, $name, $usernameText] = wizwiz_getUserDisplayForAdmin($userId);
    $codeSafe = htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8');
    $dateText = jdate('Y/m/d H:i', time());
    $msg = "🎟 <b>ورود با کد دسترسی</b>\n\n" .
           "کاربر زیر با استفاده از کد ورود، دسترسی خود را فعال کرد.\n\n" .
           "👤 کاربر: <a href='tg://user?id=$userId'>$name</a>\n" .
           "🆔 آیدی عددی: <code>$userId</code>\n" .
           "🔸 یوزرنیم: $usernameText\n" .
           "🎟 کد استفاده‌شده: <code>$codeSafe</code>\n" .
           "🕒 زمان: <code>$dateText</code>\n\n" .
           "در صورت نیاز می‌توانید دسترسی ایجادشده با این کد را حذف کنید یا کاربر را مسدود نمایید.";
    foreach(wizwiz_getAllAdminIds() as $adminId){
        sendMessage($msg, wizwiz_getAccessCodeAdminActionKeys($userId), 'HTML', $adminId);
    }
}

function wizwiz_tryActivateAccessCode($userId, $text){
    $code = wizwiz_getBuyersAccessCode();
    if($code === '') return false;
    $sent = wizwiz_normalizeAccessCodeText($text);
    if($sent === '') return false;
    if(hash_equals(strtolower($code), strtolower($sent))){
        $ok = wizwiz_setUserAccessExempt($userId, true, $code);
        if($ok) wizwiz_sendAccessCodeLoginNotice($userId, $code);
        return $ok;
    }
    return false;
}

function wizwiz_userHasPreviousPurchase($userId){
    global $connection;
    $userId = (string)$userId;
    if($userId === '') return false;

    $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = ($res && $res->num_rows > 0);
        $stmt->close();
        if($has) return true;
    }

    $paidStates = ['paid', 'approved'];
    $stmt = $connection->prepare("SELECT `id` FROM `pays` WHERE `user_id` = ? AND `state` IN ('paid','approved') LIMIT 1");
    if($stmt){
        $uid = intval($userId);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = ($res && $res->num_rows > 0);
        $stmt->close();
        if($has) return true;
    }
    return false;
}

function wizwiz_userIsExistingBeforeAccessMode($userInfo, $state = null){
    if(!$userInfo) return false;
    if($state === null) $state = wizwiz_getBotStatesArray();
    $startedAt = intval($state['newMemberAccessStartedAt'] ?? 0);
    $joinedAt = intval($userInfo['date'] ?? 0);
    if($startedAt <= 0) return true;
    return $joinedAt > 0 && $joinedAt <= $startedAt;
}

function wizwiz_newMemberAccessDeniedMessage($mode){
    if($mode === 'buyers'){
        return "🔒 در حال حاضر دسترسی به ربات فقط برای کاربرانی فعال است که قبلاً خرید ثبت‌شده داشته‌اند.\n\nاگر از مدیریت <b>کد ورود</b> دریافت کرده‌اید، لطفاً همان کد را در همین بخش ارسال کنید تا دسترسی شما فعال شود.\nدر صورت وجود هرگونه ابهام، لطفاً با پشتیبانی در ارتباط باشید.";
    }
    if($mode === 'existing'){
        return "🔒 در حال حاضر دسترسی به ربات فقط برای کاربران قبلی فعال است.\nاگر پیش‌تر عضو ربات بوده‌اید و اکنون دسترسی ندارید، لطفاً با پشتیبانی در ارتباط باشید.";
    }
    return "🔒 دسترسی شما هنوز فعال نشده است.";
}

function wizwiz_getNewMemberAccessMenuKeys(){
    $state = wizwiz_getBotStatesArray();
    $mode = wizwiz_getNewMemberAccessMode($state);
    $mark = function($m) use ($mode){ return $mode === $m ? '✅ ' : ''; };
    return wizwiz_inlineKeyboardJson([
        [['text'=>'🔖 وضعیت فعلی: ' . wizwiz_newMemberAccessModeTitle($mode), 'callback_data'=>'wizwizch', 'style'=>'primary']],
        [
            ['text'=>$mark('open') . '🌍 آزاد برای همه', 'callback_data'=>'setNewMemberAccessMode_open', 'style'=>'success'],
            ['text'=>$mark('existing') . '👥 فقط کاربران قبلی', 'callback_data'=>'setNewMemberAccessMode_existing', 'style'=>'primary']
        ],
        [
            ['text'=>$mark('buyers') . '🛒 فقط خریداران قبلی', 'callback_data'=>'setNewMemberAccessMode_buyers', 'style'=>'primary'],
            ['text'=>$mark('approval') . '🔐 تایید دستی با معرف', 'callback_data'=>'setNewMemberAccessMode_approval', 'style'=>'danger']
        ],
        [['text'=>'🎟 کد ورود خریداران: ' . (wizwiz_getBuyersAccessCode($state) !== '' ? wizwiz_getBuyersAccessCode($state) : 'تنظیم نشده'), 'callback_data'=>'wizwizch', 'style'=>'primary']],
        [
            ['text'=>'🔄 ساخت کد جدید', 'callback_data'=>'generateBuyersAccessCode', 'style'=>'success'],
            ['text'=>'✏️ تنظیم دستی کد', 'callback_data'=>'setBuyersAccessCode', 'style'=>'primary']
        ],
        [
            ['text'=>'🧹 حذف کد ورود', 'callback_data'=>'clearBuyersAccessCode', 'style'=>'danger'],
            ['text'=>'🚪 معافیت جوین اجباری کانال', 'callback_data'=>'joinExemptMenu', 'style'=>'primary']
        ],
        [['text'=>'🔙 برگشت به مدیریت', 'callback_data'=>'managePanel', 'style'=>'primary']]
    ]);
}

function wizwiz_setUserJoinExempt($userId, $enabled = true){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $enabled = $enabled ? 1 : 0;
    $user = wizwiz_getUserByTelegramId($userId);
    if(!$user){
        $name = 'manual';
        $username = 'manual';
        $time = time();
        $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `step`, `approval_status`, `approval_request_date`, `join_exempt`) VALUES (?, ?, ?, 0, 0, ?, 'none', 'approved', ?, ?)");
        if(!$stmt) return false;
        $stmt->bind_param('issiii', $userId, $name, $username, $time, $time, $enabled);
    }else{
        $stmt = $connection->prepare("UPDATE `users` SET `join_exempt` = ? WHERE `userid` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('ii', $enabled, $userId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getJoinExemptMenuKeys(){
    return wizwiz_inlineKeyboardJson([
        [['text'=>'🚪 مدیریت معافیت جوین اجباری', 'callback_data'=>'wizwizch', 'style'=>'primary']],
        [
            ['text'=>'➕ معاف کردن کاربر', 'callback_data'=>'addJoinExemptUser', 'style'=>'success'],
            ['text'=>'➖ حذف معافیت کاربر', 'callback_data'=>'removeJoinExemptUser', 'style'=>'danger']
        ],
        [['text'=>'📋 لیست کاربران معاف', 'callback_data'=>'joinExemptList', 'style'=>'primary']],
        [['text'=>'🔙 برگشت', 'callback_data'=>'newMemberAccessMenu', 'style'=>'primary']]
    ]);
}

function wizwiz_getJoinExemptListText(){
    global $connection;
    $res = $connection->query("SELECT `userid`, `name`, `username` FROM `users` WHERE `join_exempt` = 1 ORDER BY `id` DESC LIMIT 50");
    if(!$res || $res->num_rows == 0) return "📋 هنوز هیچ کاربری از جوین اجباری کانال معاف نشده است.";
    $msg = "📋 <b>کاربران معاف از جوین اجباری کانال</b>\n\n";
    while($row = $res->fetch_assoc()){
        $uid = htmlspecialchars((string)$row['userid'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8');
        $uname = htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8');
        $msg .= "• <code>$uid</code> - $name" . ($uname ? " (@$uname)" : "") . "\n";
    }
    return $msg;
}

function wizwiz_getAllAdminIds(){
    global $connection, $admin;
    $ids = [(int)$admin];
    $res = $connection->query("SELECT `userid` FROM `users` WHERE `isAdmin` = 1");
    if($res){
        while($row = $res->fetch_assoc()){
            $ids[] = (int)$row['userid'];
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

function wizwiz_sendNewMemberApprovalRequest($userId, $referrerId){
    global $first_name, $username;
    $refUser = wizwiz_getUserByTelegramId($referrerId);
    $refName = $refUser ? $refUser['name'] : '-';
    $uname = !empty($username) ? '@' . str_replace('@', '', $username) : 'ندارد';

    $msg = "🔐 درخواست عضویت جدید\n\n" .
           "👤 کاربر: <a href='tg://user?id=$userId'>" . htmlspecialchars($first_name) . "</a>\n" .
           "🆔 آیدی عددی کاربر: <code>$userId</code>\n" .
           "🔸 یوزرنیم: $uname\n\n" .
           "👥 معرف: <a href='tg://user?id=$referrerId'>" . htmlspecialchars($refName) . "</a>\n" .
           "🆔 آیدی عددی معرف: <code>$referrerId</code>\n\n" .
           "برای فعال شدن دسترسی کاربر، یکی از گزینه‌های زیر را انتخاب کنید.";

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ تایید عضویت','callback_data'=>'approveNewMember' . $userId],
            ['text'=>'❌ رد درخواست','callback_data'=>'rejectNewMember' . $userId]
        ]
    ]]);

    foreach(wizwiz_getAllAdminIds() as $adminId){
        sendMessage($msg, $keys, 'HTML', $adminId);
    }
}

function wizwiz_referrerInstructionMessage($rejected = false){
    $prefix = $rejected ? "❌ درخواست قبلی شما توسط مدیریت تایید نشد.\n\n" : "🔒 عضویت در ربات در حال حاضر نیازمند تایید مدیریت است.\n\n";
    return $prefix .
        "برای ثبت درخواست، لطفاً <b>آیدی عددی معرف خود</b> را ارسال کنید.\n\n" .
        "معرف شما می‌تواند آیدی عددی خود را از داخل ربات، از بخش <b>حساب من</b> / <b>اطلاعات حساب</b> دریافت کرده و برای شما ارسال کند.\n" .
        "لطفاً فقط عدد را ارسال کنید؛ نمونه: <code>123456789</code>";
}

function wizwiz_handleNewMemberLock(){
    global $connection, $from_id, $admin, $userInfo, $botState, $text, $data, $first_name, $username;

    $mode = wizwiz_getNewMemberAccessMode($botState);
    if($mode === 'open') return false;
    if($from_id == $admin || (!empty($userInfo) && !empty($userInfo['isAdmin']))) return false;

    $state = is_array($botState) ? $botState : [];
    $existingUser = $userInfo;
    if(!$existingUser){
        $existingUser = wizwiz_createPendingUserIfNeeded($from_id, $first_name, $username);
        $userInfo = $existingUser;
    }

    $plainText = trim((string)$text);
    if(wizwiz_userIsAccessExempt($existingUser)) return false;

    if($mode === 'existing'){
        if(wizwiz_userIsExistingBeforeAccessMode($existingUser, $state)) return false;
        sendMessage(wizwiz_newMemberAccessDeniedMessage('existing'), null, 'HTML');
        exit();
    }

    if($mode === 'buyers'){
        if(wizwiz_userHasPreviousPurchase($from_id)) return false;
        if(wizwiz_tryActivateAccessCode($from_id, $plainText)){
            sendMessage("✅ کد ورود با موفقیت تایید شد و دسترسی شما فعال گردید.

اکنون می‌توانید از امکانات ربات استفاده کنید.", getMainKeys(), 'HTML');
            exit();
        }
        sendMessage(wizwiz_newMemberAccessDeniedMessage('buyers'), null, 'HTML');
        exit();
    }

    // حالت تایید دستی با معرف؛ سازگار با قفل قبلی اعضای جدید
    if(wizwiz_isUserApprovedForLock($existingUser)) return false;

    $status = $existingUser['approval_status'] ?? 'pending';

    $deepLinkReferrer = null;
    if(preg_match('/^\/start\s+(\d+)$/', $plainText, $m)){
        $deepLinkReferrer = (int)$m[1];
    }

    if($status == 'pending' && ($existingUser['step'] ?? '') == 'newMemberApprovalWait' && $deepLinkReferrer === null && !preg_match('/^\d+$/', $plainText)){
        sendMessage("⏳ درخواست شما قبلاً برای ادمین ارسال شده است.\nبعد از تایید، پیام فعال شدن دسترسی برای شما ارسال می‌شود.", null, 'HTML');
        exit();
    }

    if($status == 'rejected'){
        wizwiz_setUserApprovalStatus($from_id, 'pending');
        sendMessage(wizwiz_referrerInstructionMessage(true), null, 'HTML');
        exit();
    }

    $referrerId = $deepLinkReferrer;
    if($referrerId === null && preg_match('/^\d+$/', $plainText)){
        $referrerId = (int)$plainText;
    }

    if($referrerId !== null){
        if($referrerId == $from_id){
            sendMessage("❌ نمی‌توانید آیدی عددی خودتان را به عنوان معرف وارد کنید.\nلطفاً آیدی عددی معرفتان را ارسال کنید.", null, 'HTML');
            exit();
        }

        $refUser = wizwiz_getUserByTelegramId($referrerId);
        if(!$refUser || !wizwiz_isUserApprovedForLock($refUser)){
            sendMessage("❌ معرفی با این آیدی عددی پیدا نشد یا هنوز تایید نشده است.\nلطفاً آیدی عددی درست معرفتان را ارسال کنید.", null, 'HTML');
            exit();
        }

        wizwiz_setUserApprovalStatus($from_id, 'pending', $referrerId);
        wizwiz_sendNewMemberApprovalRequest($from_id, $referrerId);
        sendMessage("✅ درخواست شما برای ادمین ارسال شد.\nبعد از تایید، دسترسی شما به ربات فعال می‌شود.", null, 'HTML');
        exit();
    }

    setUser('newMemberEnterReferrer');
    sendMessage(wizwiz_referrerInstructionMessage(false), null, 'HTML');
    exit();
}

function wizwiz_extractHeaderPair($headerLine){
    $headerLine = trim((string)$headerLine);
    if($headerLine === '' || strpos($headerLine, ':') === false) return ['', ''];
    [$key, $value] = explode(':', $headerLine, 2);
    return [trim($key), trim($value)];
}

function wizwiz_buildHttpupgradeStreamSettings($security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType){
    [$headerKey, $headerValue] = wizwiz_extractHeaderPair($request_header);
    $host = '';
    $headersArr = [];

    if($header_type != 'none' && $headerKey !== ''){
        if(strtolower($headerKey) == 'host') $host = $headerValue;
        else $headersArr[$headerKey] = $headerValue;
    }

    $httpupgradeSettings = [
        'acceptProxyProtocol' => false,
        'path' => '/',
        'host' => $host,
        'headers' => (object)$headersArr,
    ];

    $stream = [
        'network' => 'httpupgrade',
        'security' => $security,
    ];

    if($security == 'xtls' && $serverType != 'sanaei' && $serverType != 'sanaei_new' && $serverType != 'alireza'){
        $stream[$xtlsTitle] = json_decode($tlsSettings, true) ?: (object)[];
    }elseif($security != 'none'){
        $stream['tlsSettings'] = json_decode($tlsSettings, true) ?: (object)[];
    }

    $stream['httpupgradeSettings'] = $httpupgradeSettings;
    return json_encode($stream, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType){
    if($netType == 'tcp') return $tcpSettings;
    if($netType == 'httpupgrade') return wizwiz_buildHttpupgradeStreamSettings($security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
    return $wsSettings;
}


function wizwiz_normalizeSanaeiNewResponse($decoded, $serverType){
    if($serverType !== 'sanaei_new' || !is_object($decoded) || !isset($decoded->obj)) return $decoded;
    if(is_array($decoded->obj)){
        foreach($decoded->obj as $row){
            if(!is_object($row)) continue;
            foreach(['settings','streamSettings','sniffing'] as $field){
                if(isset($row->$field) && !is_string($row->$field)){
                    $row->$field = json_encode($row->$field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
    }
    return $decoded;
}

function wizwiz_sanaeiNewBaseUrlFromApiUrl($url){
    $base = preg_replace('#/panel/api/.*$#', '', (string)$url);
    return rtrim($base ?: $url, '/');
}

function wizwiz_sanaeiNewCsrfToken($curl, $baseUrl, $session){
    $baseUrl = rtrim((string)$baseUrl, '/');
    if($baseUrl === '') return '';

    $csrfCurl = curl_init();
    curl_setopt_array($csrfCurl, array(
        CURLOPT_URL => $baseUrl . '/csrf-token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent: Mozilla/5.0',
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));

    $response = curl_exec($csrfCurl);
    curl_close($csrfCurl);
    $decoded = json_decode((string)$response, true);
    if(is_array($decoded) && !empty($decoded['success']) && isset($decoded['obj'])) return (string)$decoded['obj'];
    return '';
}

function wizwiz_sanaeiNewHeaders($curl, $url, $session, $json = true){
    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $session
    );
    if($json) $headers[] = 'Content-Type: application/json';

    $csrf = wizwiz_sanaeiNewCsrfToken($curl, wizwiz_sanaeiNewBaseUrlFromApiUrl($url), $session);
    if($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    return $headers;
}

function wizwiz_sanaeiNewDecodePayloadJsonFields($payload){
    if(!is_array($payload)) return $payload;
    foreach(array('settings','streamSettings','sniffing') as $field){
        if(isset($payload[$field]) && is_string($payload[$field])){
            $decoded = json_decode($payload[$field], true);
            if(json_last_error() === JSON_ERROR_NONE) $payload[$field] = $decoded;
        }
    }
    foreach(array('up','down','total','expiryTime','port') as $field){
        if(isset($payload[$field]) && is_numeric($payload[$field])) $payload[$field] = (int)$payload[$field];
    }
    if(isset($payload['enable'])){
        $payload['enable'] = ($payload['enable'] === true || $payload['enable'] === 1 || $payload['enable'] === '1' || $payload['enable'] === 'true');
    }
    return $payload;
}

function wizwiz_sanaeiNewJsonPost($curl, $url, $session, $payload = null){
    $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_POST => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => wizwiz_sanaeiNewHeaders($curl, $url, $session, true)
    ));
}


function wizwiz_normalizePanelSettingsArray($settings){
    if(is_string($settings)){
        $decoded = json_decode($settings, true);
        if(json_last_error() === JSON_ERROR_NONE) $settings = $decoded;
    }
    if(!is_array($settings)) return [];

    // Some panels/forks return [{key/name, value}] instead of a plain object.
    $isList = array_keys($settings) === range(0, count($settings) - 1);
    if($isList){
        $out = [];
        foreach($settings as $row){
            if(!is_array($row)) continue;
            $key = $row['key'] ?? $row['name'] ?? $row['setting'] ?? null;
            if($key === null || $key === '') continue;
            $val = $row['value'] ?? $row['val'] ?? $row['data'] ?? null;
            if(is_string($val)){
                $trim = trim($val);
                if(($trim !== '') && (($trim[0] === '{' && substr($trim, -1) === '}') || ($trim[0] === '[' && substr($trim, -1) === ']'))){
                    $decodedVal = json_decode($trim, true);
                    if(json_last_error() === JSON_ERROR_NONE) $val = $decodedVal;
                }
            }
            $out[$key] = $val;
        }
        if(!empty($out)) return $out;
    }
    return $settings;
}

function wizwiz_sanaeiRequestJson($server_info, $endpoint, $method = 'GET', $payload = null){
    [$curl, $session] = wizwiz_panelLoginSession($server_info);
    if(!$curl || !$session){
        if($curl) curl_close($curl);
        return null;
    }
    $panel = rtrim($server_info['panel_url'] ?? '', '/');
    $url = $panel . $endpoint;
    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $session,
    );
    $csrf = wizwiz_sanaeiNewCsrfToken(null, $panel, $session);
    if($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    $method = strtoupper($method);
    if($payload !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $headers,
    ));
    if($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    elseif($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, '');
    $response = curl_exec($curl);
    curl_close($curl);
    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : null;
}

function wizwiz_sanaeiNewFindClientEmail($server_id, $uuid = '', $inbound_id = 0, $remark = ''){
    $remark = trim((string)$remark);
    $uuid = trim((string)$uuid);
    if($remark !== '') return $remark;
    if($uuid === '') return '';
    $json = getJson($server_id);
    if(!$json || empty($json->success) || !isset($json->obj) || !is_array($json->obj)) return '';
    foreach($json->obj as $row){
        if($inbound_id != 0 && intval($row->id ?? 0) != intval($inbound_id)) continue;
        $settings = wizwiz_decodeMaybeJson($row->settings ?? '{}', true);
        $clients = $settings['clients'] ?? [];
        if(!is_array($clients)) continue;
        foreach($clients as $client){
            if(!is_array($client)) continue;
            $cid = (string)($client['id'] ?? '');
            $pwd = (string)($client['password'] ?? '');
            if($cid === $uuid || $pwd === $uuid) return (string)($client['email'] ?? '');
        }
    }
    return '';
}

function wizwiz_sanaeiNewClientLinksFromPanel($server_id, $email = '', $uuid = '', $inbound_id = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info || ($server_info['type'] ?? '') !== 'sanaei_new') return [];
    $email = wizwiz_sanaeiNewFindClientEmail($server_id, $uuid, $inbound_id, $email);
    if($email === '') return [];
    $decoded = wizwiz_sanaeiRequestJson($server_info, '/panel/api/clients/links/' . rawurlencode($email), 'GET');
    if(!is_array($decoded) || empty($decoded['success'])) return [];
    $obj = $decoded['obj'] ?? [];
    if(is_string($obj)){
        $tmp = json_decode($obj, true);
        if(json_last_error() === JSON_ERROR_NONE) $obj = $tmp;
    }
    if(!is_array($obj)) return [];
    $links = [];
    foreach($obj as $link){
        $link = trim((string)$link);
        if(preg_match('#^(vmess|vless|trojan|ss|hysteria2?|hy2)://#i', $link)) $links[] = $link;
    }
    return array_values(array_unique($links));
}

function wizwiz_sanaeiNewSubLinksFromPanel($server_id, $subId){
    global $connection;
    $subId = trim((string)$subId);
    if($subId === '') return [];
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info || ($server_info['type'] ?? '') !== 'sanaei_new') return [];
    $decoded = wizwiz_sanaeiRequestJson($server_info, '/panel/api/clients/subLinks/' . rawurlencode($subId), 'GET');
    if(!is_array($decoded) || empty($decoded['success'])) return [];
    $obj = $decoded['obj'] ?? [];
    if(is_string($obj)){
        $tmp = json_decode($obj, true);
        if(json_last_error() === JSON_ERROR_NONE) $obj = $tmp;
    }
    if(!is_array($obj)) return [];
    $links = [];
    foreach($obj as $link){
        $link = trim((string)$link);
        if(preg_match('#^(vmess|vless|trojan|ss|hysteria2?|hy2)://#i', $link)) $links[] = $link;
    }
    return array_values(array_unique($links));
}

function wizwiz_isPanelSubscriptionServer($serverType){
    return in_array($serverType, ['sanaei', 'sanaei_new'], true);
}

function wizwiz_decodeMaybeJson($value, $assoc = true){
    if(is_array($value) || is_object($value)) return $value;
    $decoded = json_decode((string)$value, $assoc);
    if(json_last_error() === JSON_ERROR_NONE) return $decoded;
    return $assoc ? [] : (object)[];
}

function wizwiz_arrayValue($arr, $key, $default = null){
    if(is_array($arr) && array_key_exists($key, $arr)) return $arr[$key];
    if(is_object($arr) && isset($arr->$key)) return $arr->$key;
    return $default;
}

function wizwiz_textContains($haystack, $needle){
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if($needle === '') return false;
    return stripos($haystack, $needle) !== false || strpos($haystack, $needle) !== false;
}

function wizwiz_buttonStyleByCallback($button){
    if(!is_array($button)) return $button;
    if(!isset($button['text'])) return $button;

    // Telegram Bot API فقط این سه مقدار را برای style قبول می‌کند.
    // مقدارهای دیگر مثل secondary باعث خطای reply_markup و از کار افتادن دکمه‌ها می‌شوند.
    $allowedStyles = ['danger', 'success', 'primary'];
    if(isset($button['style'])){
        $button['style'] = strtolower(trim((string)$button['style']));
        if(!in_array($button['style'], $allowedStyles, true)) $button['style'] = 'primary';
        return $button;
    }

    $callback = (string)($button['callback_data'] ?? '');
    $haystack = (string)($button['text'] ?? '') . ' ' . $callback;

    // دکمه‌های عنوانی/غیرعملیاتی مثل wizwizch رنگ ثابت و معتبر بگیرند.
    if($callback === 'wizwizch' || $callback === ''){
        $button['style'] = 'primary';
        return $button;
    }

    $dangerWords = ['delete', 'del', 'remove', 'ban', 'reject', 'disable', 'decrease', 'cancel', 'clear', 'off', 'stop', 'لغو', 'حذف', 'بن', 'مسدود', 'رد', 'غیرفعال', 'کاهش', 'پاک', 'خاموش', 'توقف', '❌', '🗑', '🧹', '➖'];
    foreach($dangerWords as $w){
        if(wizwiz_textContains($haystack, $w)){
            $button['style'] = 'danger';
            return $button;
        }
    }

    $successWords = ['buy', 'renew', 'increase', 'enable', 'approve', 'confirm', 'pay', 'gift', 'join', 'gettest', 'add', 'generate', 'on', 'خرید', 'تمدید', 'افزایش', 'شارژ', 'تایید', 'فعال', 'پرداخت', 'هدیه', 'عضویت', 'افزودن', 'معاف', 'ساخت', 'روشن', '✅', '➕', '🔄'];
    foreach($successWords as $w){
        if(wizwiz_textContains($haystack, $w)){
            $button['style'] = 'success';
            return $button;
        }
    }

    $primaryWords = ['back', 'main', 'search', 'show', 'details', 'update', 'change', 'qr', 'sub', 'support', 'info', 'config', 'subscription', 'settings', 'menu', 'list', 'برگشت', 'بازگشت', 'جستجو', 'نمایش', 'جزئیات', 'آپدیت', 'به‌روزرسانی', 'تغییر', 'کیوآر', 'ساب', 'پشتیبانی', 'حساب', 'کانفیگ', 'اشتراک', 'تنظیم', 'مدیریت', 'لیست'];
    foreach($primaryWords as $w){
        if(wizwiz_textContains($haystack, $w)){
            $button['style'] = 'primary';
            return $button;
        }
    }

    return $button;
}


function wizwiz_styleInlineKeyboard($keyboard){
    if(!is_array($keyboard)) return $keyboard;
    $out = [];
    foreach($keyboard as $row){
        if(!is_array($row) || count($row) === 0) continue;
        $newRow = [];
        foreach($row as $button){
            if(is_array($button) && isset($button['text'])) $newRow[] = wizwiz_buttonStyleByCallback($button);
        }
        if(count($newRow) > 0) $out[] = $newRow;
    }
    return $out;
}

function wizwiz_styleReplyKeyboardButton($button){
    if(is_string($button)) $button = ['text' => $button];
    if(!is_array($button) || !isset($button['text'])) return $button;
    return wizwiz_buttonStyleByCallback($button);
}

function wizwiz_styleReplyKeyboard($keyboard){
    if(!is_array($keyboard)) return $keyboard;
    $out = [];
    foreach($keyboard as $row){
        if(!is_array($row)) continue;
        $newRow = [];
        foreach($row as $button){
            $newRow[] = wizwiz_styleReplyKeyboardButton($button);
        }
        $out[] = $newRow;
    }
    return $out;
}

function wizwiz_styleReplyMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;

    if(isset($decoded['inline_keyboard']) && is_array($decoded['inline_keyboard'])){
        $decoded['inline_keyboard'] = wizwiz_styleInlineKeyboard($decoded['inline_keyboard']);
    }
    if(isset($decoded['keyboard']) && is_array($decoded['keyboard'])){
        $decoded['keyboard'] = wizwiz_styleReplyKeyboard($decoded['keyboard']);
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wizwiz_inlineKeyboardJson($keyboard){
    return json_encode(['inline_keyboard' => wizwiz_styleInlineKeyboard($keyboard)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


function wizwiz_defaultUserButtonVisibilityKeys(){
    return [
        'request_agency' => true,
        'my_subscriptions' => true,
        'buy_subscriptions' => true,
        'test_account' => true,
        'wallet_charge' => true,
        'invite_friends' => true,
        'my_info' => true,
        'shared_existence' => true,
        'individual_existence' => true,
        'application_links' => true,
        'my_tickets' => true,
        'search_config' => true,
        'refresh_panel' => true,
    ];
}

function wizwiz_getUserButtonVisibility($state = null){
    if($state === null) $state = wizwiz_getBotStatesArray();
    $defaults = wizwiz_defaultUserButtonVisibilityKeys();
    $saved = is_array($state) && isset($state['userButtonVisibility']) && is_array($state['userButtonVisibility']) ? $state['userButtonVisibility'] : [];
    foreach($defaults as $key => $value){
        if(!array_key_exists($key, $saved)) $saved[$key] = true;
        else $saved[$key] = (bool)$saved[$key];
    }
    return $saved;
}

function wizwiz_userButtonVisible($key, $state = null){
    $vis = wizwiz_getUserButtonVisibility($state);
    return !array_key_exists($key, $vis) || $vis[$key];
}

function wizwiz_setUserButtonVisible($key, $visible){
    global $botState;
    $defaults = wizwiz_defaultUserButtonVisibilityKeys();
    if(!array_key_exists($key, $defaults)) return false;
    $state = wizwiz_getBotStatesArray();
    $vis = wizwiz_getUserButtonVisibility($state);
    $vis[$key] = $visible ? true : false;
    $state['userButtonVisibility'] = $vis;
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function wizwiz_setAllUserButtonsVisible($visible){
    global $botState;
    $state = wizwiz_getBotStatesArray();
    $vis = wizwiz_defaultUserButtonVisibilityKeys();
    foreach($vis as $key => $_) $vis[$key] = $visible ? true : false;
    $state['userButtonVisibility'] = $vis;
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
}

function wizwiz_defaultUserButtonOrder(){
    return [
        'request_agency',
        'my_subscriptions',
        'buy_subscriptions',
        'test_account',
        'wallet_charge',
        'invite_friends',
        'my_info',
        'shared_existence',
        'individual_existence',
        'application_links',
        'my_tickets',
        'search_config',
        'refresh_panel',
    ];
}

function wizwiz_getUserButtonOrder($state = null){
    if($state === null) $state = wizwiz_getBotStatesArray();
    $defaults = wizwiz_defaultUserButtonOrder();
    $allowed = array_values(array_keys(wizwiz_defaultUserButtonVisibilityKeys()));
    $saved = is_array($state) && isset($state['userButtonOrder']) && is_array($state['userButtonOrder']) ? $state['userButtonOrder'] : [];
    $order = [];
    foreach($saved as $key){
        $key = (string)$key;
        if(in_array($key, $allowed, true) && !in_array($key, $order, true)) $order[] = $key;
    }
    foreach($defaults as $key){
        if(in_array($key, $allowed, true) && !in_array($key, $order, true)) $order[] = $key;
    }
    foreach($allowed as $key){
        if(!in_array($key, $order, true)) $order[] = $key;
    }
    return $order;
}

function wizwiz_saveUserButtonOrder($order){
    global $botState;
    $state = wizwiz_getBotStatesArray();
    $state['userButtonOrder'] = wizwiz_getUserButtonOrder(['userButtonOrder' => $order]);
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function wizwiz_moveUserButtonOrder($key, $direction){
    $key = (string)$key;
    $direction = (string)$direction;
    $order = wizwiz_getUserButtonOrder();
    $index = array_search($key, $order, true);
    if($index === false) return false;
    $target = ($direction === 'up') ? $index - 1 : (($direction === 'down') ? $index + 1 : $index);
    if($target < 0 || $target >= count($order) || $target === $index) return false;
    $tmp = $order[$target];
    $order[$target] = $order[$index];
    $order[$index] = $tmp;
    return wizwiz_saveUserButtonOrder($order);
}

function wizwiz_resetUserButtonOrder(){
    return wizwiz_saveUserButtonOrder(wizwiz_defaultUserButtonOrder());
}

function wizwiz_getUserButtonRowBreaks($state = null){
    if($state === null) $state = wizwiz_getBotStatesArray();
    $allowed = array_values(array_keys(wizwiz_defaultUserButtonVisibilityKeys()));
    $saved = is_array($state) && isset($state['userButtonRowBreaks']) && is_array($state['userButtonRowBreaks']) ? $state['userButtonRowBreaks'] : [];
    $breaks = [];
    foreach($allowed as $key){
        $breaks[$key] = !empty($saved[$key]);
    }
    return $breaks;
}

function wizwiz_userButtonBreakAfter($key, $state = null){
    $breaks = wizwiz_getUserButtonRowBreaks($state);
    return !empty($breaks[$key]);
}

function wizwiz_setUserButtonRowBreak($key, $enabled){
    global $botState;
    $key = (string)$key;
    $defaults = wizwiz_defaultUserButtonVisibilityKeys();
    if(!array_key_exists($key, $defaults)) return false;
    $state = wizwiz_getBotStatesArray();
    $breaks = wizwiz_getUserButtonRowBreaks($state);
    $breaks[$key] = $enabled ? true : false;
    $state['userButtonRowBreaks'] = $breaks;
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function wizwiz_toggleUserButtonRowBreak($key){
    return wizwiz_setUserButtonRowBreak($key, !wizwiz_userButtonBreakAfter($key));
}

function wizwiz_resetUserButtonRowBreaks(){
    global $botState;
    $state = wizwiz_getBotStatesArray();
    $breaks = wizwiz_getUserButtonRowBreaks([]);
    $state['userButtonRowBreaks'] = $breaks;
    wizwiz_saveBotStatesArray($state);
    $botState = $state;
    return true;
}


function wizwiz_salesStateBlockReason($kind = 'new', $agentContext = null){
    global $botState, $userInfo;
    $state = wizwiz_getBotStatesArray();
    if(!is_array($state) || empty($state)) $state = is_array($botState) ? $botState : [];

    if($agentContext === null){
        $agentContext = wizwiz_isAgentUser($userInfo);
        if(!$agentContext && isset($GLOBALS['payParam']) && is_array($GLOBALS['payParam'])){
            $agentContext = !empty($GLOBALS['payParam']['agent_bought']);
        }
    }

    if($agentContext){
        $sellState = $state['agentSellState'] ?? ($state['sellState'] ?? 'off');
    }else{
        $sellState = $state['sellState'] ?? 'off';
    }

    if($sellState !== 'on') return 'sales_off';

    // خاموش بودن دکمه خرید فقط برای خرید کاربران عادی اعمال شود.
    // نماینده‌ها دکمه‌های خرید جداگانه خودشان را دارند.
    if(!$agentContext && $kind === 'new' && !wizwiz_userButtonVisible('buy_subscriptions', $state)) return 'buy_button_off';
    return '';
}

function wizwiz_purchaseBlockedMessage($reason = ''){
    if($reason === 'buy_button_off'){
        return "🔒 بخش خرید کانفیگ جدید در حال حاضر توسط مدیریت غیرفعال شده است.\n\nدر صورت نیاز، لطفاً از بخش پشتیبانی با مدیریت در ارتباط باشید.";
    }
    return "🔒 فروش خدمات در حال حاضر توسط مدیریت غیرفعال شده است.\n\nتا زمان فعال‌سازی مجدد فروش، امکان ثبت خرید، تمدید یا افزایش حجم و زمان وجود ندارد.";
}

function wizwiz_isConfigPayType($payType){
    $payType = (string)$payType;
    if($payType === 'BUY_SUB' || $payType === 'RENEW_ACCOUNT' || $payType === 'RENEW_SCONFIG') return true;
    if(preg_match('/^INCREASE_(DAY|VOLUME)_/', $payType)) return true;
    return false;
}

function wizwiz_purchaseKindFromPayType($payType){
    $payType = (string)$payType;
    if($payType === 'BUY_SUB') return 'new';
    if(wizwiz_isConfigPayType($payType)) return 'paid';
    return 'none';
}

function wizwiz_getPayTypeByHash($hash){
    global $connection;
    $hash = trim((string)$hash);
    if($hash === '') return '';
    $stmt = @$connection->prepare("SELECT `type` FROM `pays` WHERE `hash_id` = ? OR `payid` = ? ORDER BY `id` DESC LIMIT 1");
    if(!$stmt) return '';
    $stmt->bind_param('ss', $hash, $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row['type'] ?? '';
}

function wizwiz_salesBlockReasonForPayType($payType){
    $kind = wizwiz_purchaseKindFromPayType($payType);
    if($kind === 'none') return '';
    return wizwiz_salesStateBlockReason($kind);
}

function wizwiz_extractPaymentHashFromAction($value){
    $value = trim((string)$value);
    if($value === '') return '';
    $patterns = [
        '/^(?:payCustomWithWallet|payWithWallet|payCustomWithCartToCart|payWithCartToCart|payRenewWithCartToCart|payRenewWithWallet|payIncreaseDayWithCartToCart|payIncraseDayWithWallet|payIncreaseWithCartToCart|payIncraseWithWallet|payWithWeSwap|payWithTronWallet)(.+)$/',
    ];
    foreach($patterns as $pattern){
        if(preg_match($pattern, $value, $m)) return trim($m[1]);
    }
    return '';
}

function wizwiz_purchaseActionBlockReason($callbackData = '', $userStep = ''){
    global $from_id, $admin, $userInfo;
    $isAdmin = ($from_id == $admin) || (!empty($userInfo['isAdmin']));
    if($isAdmin) return '';

    $callbackData = (string)$callbackData;
    $userStep = (string)$userStep;

    $newPatterns = [
        '/^(buySubscription|agentOneBuy|agentMuchBuy)$/',
        '/^selectServer\d+_/',
        '/^selectCategory\d+_\d+_/',
        '/^selectPlan\d+_\d+_/',
        '/^selectCustomPlan\d+_\d+_/',
        '/^selectCustomePlan\d+_\d+_/',
        '/^freeTrial\d+_/',
        '/^haveDiscountSelectPlan_/',
        '/^haveDiscountCustom_/',
    ];
    $newStepPatterns = [
        '/^discountSelectPlan\d+_\d+_\d+/',
        '/^selectPlan\d+_\d+_\w+$/',
        '/^enterAccountName\d+_\d+_\w+$/',
        '/^selectCustomPlanGB\d+_\d+_\w+$/',
        '/^selectCustomPlanDay\d+_\d+_\d+_\w+$/',
        '/^discountCustomPlanDay\d+/',
        '/^enterCustomPlanName\d+_\d+_\d+_\d+_\w+$/',
    ];
    foreach($newPatterns as $pattern){
        if($callbackData !== '' && preg_match($pattern, $callbackData)) return wizwiz_salesStateBlockReason('new');
    }
    foreach($newStepPatterns as $pattern){
        if($userStep !== '' && preg_match($pattern, $userStep)) return wizwiz_salesStateBlockReason('new');
    }

    $paidPatterns = [
        '/^sConfigRenewPlan\d+_\d+/',
        '/^renewAccount\d+/',
        '/^haveDiscountRenew_/',
        '/^payRenewWithCartToCart.+/',
        '/^payRenewWithWallet.+/',
        '/^increaseADay.+/',
        '/^selectPlanDayIncrease.+_\d+/',
        '/^payIncreaseDayWithCartToCart.+/',
        '/^payIncraseDayWithWallet.+/',
        '/^increaseAVolume.+/',
        '/^increaseVolumePlan.+_\d+/',
        '/^payIncreaseWithCartToCart.+/',
        '/^payIncraseWithWallet.+/',
    ];
    $paidStepPatterns = [
        '/^discountRenew\d+_\d+/',
        '/^payRenewWithCartToCart.+/',
        '/^payIncreaseDayWithCartToCart.+/',
        '/^payIncreaseWithCartToCart.+/',
    ];
    foreach($paidPatterns as $pattern){
        if($callbackData !== '' && preg_match($pattern, $callbackData)) return wizwiz_salesStateBlockReason('paid');
    }
    foreach($paidStepPatterns as $pattern){
        if($userStep !== '' && preg_match($pattern, $userStep)) return wizwiz_salesStateBlockReason('paid');
    }

    foreach([$callbackData, $userStep] as $value){
        $hash = wizwiz_extractPaymentHashFromAction($value);
        if($hash === '') continue;
        $payType = wizwiz_getPayTypeByHash($hash);
        if($payType !== ''){
            $reason = wizwiz_salesBlockReasonForPayType($payType);
            if($reason !== '') return $reason;
        }else{
            if(preg_match('/^(payCustomWithWallet|payCustomWithCartToCart)/', $value)) return wizwiz_salesStateBlockReason('new');
            if(preg_match('/^(payRenew|payIncrease|payIncrase)/', $value)) return wizwiz_salesStateBlockReason('paid');
        }
    }

    return '';
}

function wizwiz_stopPurchaseIfBlocked($callbackData = '', $userStep = ''){
    global $message_id, $removeKeyboard, $buttonValues;
    $reason = wizwiz_purchaseActionBlockReason($callbackData, $userStep);
    if($reason === '') return false;
    setUser();
    $msg = wizwiz_purchaseBlockedMessage($reason);
    if(trim((string)$callbackData) !== ''){
        alert($msg, true);
        if(!empty($message_id)) editText($message_id, $msg, json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'] ?? 'بازگشت', 'callback_data'=>'mainMenu', 'style'=>'primary']]]], JSON_UNESCAPED_UNICODE), 'HTML');
    }else{
        sendMessage($msg, $removeKeyboard, 'HTML');
        sendMessage($GLOBALS['mainValues']['reached_main_menu'] ?? 'منوی اصلی', getMainKeys(), 'HTML');
    }
    return true;
}

function wizwiz_userButtonTitles(){
    global $buttonValues;
    return [
        'request_agency' => $buttonValues['request_agency'] ?? 'درخواست نمایندگی',
        'my_subscriptions' => $buttonValues['my_subscriptions'] ?? 'کانفیگ‌های من',
        'buy_subscriptions' => $buttonValues['buy_subscriptions'] ?? 'خرید کانفیگ جدید',
        'test_account' => 'اکانت تست',
        'wallet_charge' => $buttonValues['sharj'] ?? 'شارژ کیف پول',
        'invite_friends' => $buttonValues['invite_friends'] ?? 'دعوت دوستان',
        'my_info' => $buttonValues['my_info'] ?? 'حساب کاربری',
        'shared_existence' => $buttonValues['shared_existence'] ?? 'موجودی اشتراکی',
        'individual_existence' => $buttonValues['individual_existence'] ?? 'موجودی اختصاصی',
        'application_links' => $buttonValues['application_links'] ?? 'راهنمای اتصال',
        'my_tickets' => $buttonValues['my_tickets'] ?? 'تیکت‌های من',
        'search_config' => $buttonValues['search_config'] ?? 'مشخصات کانفیگ',
        'refresh_panel' => '🔄 بروزرسانی پنل',
    ];
}

function wizwiz_getUserButtonSettingsKeys(){
    $titles = wizwiz_userButtonTitles();
    $vis = wizwiz_getUserButtonVisibility();
    $order = wizwiz_getUserButtonOrder();
    $keys = [];
    $keys[] = [['text'=>'🎛 تنظیمات دکمه‌های کاربر', 'callback_data'=>'wizwizch', 'style'=>'primary']];
    $keys[] = [['text'=>'↕️ جابه‌جایی ترتیب دکمه‌ها', 'callback_data'=>'userButtonLayoutSettings', 'style'=>'primary']];
    $row = [];
    foreach($order as $key){
        if(!isset($titles[$key])) continue;
        $title = $titles[$key];
        $on = !empty($vis[$key]);
        $row[] = [
            'text' => ($on ? '✅ ' : '❌ ') . $title,
            'callback_data' => 'toggleUserButtonVisibility_' . $key,
            'style' => $on ? 'success' : 'danger'
        ];
        if(count($row) >= 2){
            $keys[] = $row;
            $row = [];
        }
    }
    if(count($row) > 0) $keys[] = $row;
    $keys[] = [
        ['text'=>'✅ نمایش همه', 'callback_data'=>'setAllUserButtons_on', 'style'=>'success'],
        ['text'=>'❌ مخفی کردن همه', 'callback_data'=>'setAllUserButtons_off', 'style'=>'danger']
    ];
    $keys[] = [['text'=>'🔙 برگشت به مدیریت', 'callback_data'=>'managePanel', 'style'=>'primary']];
    return wizwiz_inlineKeyboardJson($keys);
}

function wizwiz_getUserButtonOrderText(){
    $titles = wizwiz_userButtonTitles();
    $order = wizwiz_getUserButtonOrder();
    $vis = wizwiz_getUserButtonVisibility();
    $breaks = wizwiz_getUserButtonRowBreaks();
    $msg = "↕️ <b>جابه‌جایی دکمه‌های منوی کاربر</b>

";
    $msg .= "با دکمه‌های بالا و پایین، ترتیب نمایش دکمه‌ها را تغییر دهید.
";
    $msg .= "برای تک‌دکمه کردن یک ردیف، روی «↵ ردیف جدید بعدش» همان دکمه بزنید تا دکمه بعدی به ردیف بعد برود.
";
    $msg .= "هر ردیف همچنان حداکثر ۲ دکمه دارد.

";
    $i = 1;
    foreach($order as $key){
        if(!isset($titles[$key])) continue;
        $status = !empty($vis[$key]) ? '✅' : '❌';
        $rowBreak = !empty($breaks[$key]) ? ' ↵' : '';
        $title = htmlspecialchars((string)$titles[$key], ENT_QUOTES, 'UTF-8');
        $msg .= $i . ". {$status} {$title}{$rowBreak}
";
        $i++;
    }
    $msg .= "
↵ یعنی بعد از آن دکمه، ردیف جدید شروع می‌شود.";
    return $msg;
}

function wizwiz_getUserButtonOrderSettingsKeys(){
    $titles = wizwiz_userButtonTitles();
    $order = wizwiz_getUserButtonOrder();
    $breaks = wizwiz_getUserButtonRowBreaks();
    $keys = [];
    $keys[] = [['text'=>'↕️ ترتیب دکمه‌های کاربر', 'callback_data'=>'wizwizch', 'style'=>'primary']];
    $total = count($order);
    $i = 1;
    foreach($order as $key){
        if(!isset($titles[$key])) continue;
        $title = $titles[$key];
        $keys[] = [[
            'text' => $i . '. ' . $title,
            'callback_data' => 'wizwizch',
            'style' => 'primary'
        ]];
        $moveRow = [];
        if($i > 1){
            $moveRow[] = ['text'=>'⬆️ بالا', 'callback_data'=>'moveUserButtonOrder_' . $key . '_up', 'style'=>'primary'];
        }
        if($i < $total){
            $moveRow[] = ['text'=>'⬇️ پایین', 'callback_data'=>'moveUserButtonOrder_' . $key . '_down', 'style'=>'primary'];
        }
        if(count($moveRow) > 0) $keys[] = $moveRow;
        $breakOn = !empty($breaks[$key]);
        $keys[] = [[
            'text' => ($breakOn ? '✅ ↵ ردیف جدید بعدش فعال' : '↵ ردیف جدید بعدش'),
            'callback_data' => 'toggleUserButtonRowBreak_' . $key,
            'style' => $breakOn ? 'success' : 'primary'
        ]];
        $i++;
    }
    $keys[] = [['text'=>'🔄 بازگشت به ترتیب پیش‌فرض', 'callback_data'=>'resetUserButtonOrder', 'style'=>'danger']];
    $keys[] = [['text'=>'🧹 حذف ردیف‌بندی سفارشی', 'callback_data'=>'resetUserButtonRows', 'style'=>'danger']];
    $keys[] = [['text'=>'🔙 برگشت به تنظیمات دکمه‌ها', 'callback_data'=>'userButtonSettings', 'style'=>'primary']];
    return wizwiz_inlineKeyboardJson($keys);
}


function wizwiz_getPaymentKeys(){
    global $connection;
    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'PAYMENT_KEYS' LIMIT 1");
    if(!$stmt) return [];
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    $keys = $row ? json_decode((string)$row['value'], true) : [];
    return is_array($keys) ? $keys : [];
}

function wizwiz_savePaymentKeys($paymentKeys){
    global $connection;
    if(!is_array($paymentKeys)) $paymentKeys = [];
    $value = json_encode($paymentKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $connection->prepare("SELECT `id` FROM `setting` WHERE `type` = 'PAYMENT_KEYS' LIMIT 1");
    if(!$stmt) return false;
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();

    if($exists){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'PAYMENT_KEYS'");
        if(!$stmt) return false;
        $stmt->bind_param('s', $value);
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('PAYMENT_KEYS', ?)");
        if(!$stmt) return false;
        $stmt->bind_param('s', $value);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getCardInfoVersion($paymentKeys = null){
    if($paymentKeys === null) $paymentKeys = wizwiz_getPaymentKeys();
    $v = intval($paymentKeys['cardInfoVersion'] ?? 1);
    return $v > 0 ? $v : 1;
}

function wizwiz_userHasActivePaidConfig($userId){
    global $connection;
    $userId = trim((string)$userId);
    if($userId === '') return false;
    $now = time();

    // فقط سرویس‌های خریداری‌شده و فعال حساب می‌شوند؛ اکانت تست/هدیه با مبلغ صفر حساب نمی‌شود.
    $sql = "SELECT `id` FROM `orders_list`
            WHERE `userid` = ?
              AND `status` = 1
              AND CAST(COALESCE(`amount`, 0) AS SIGNED) > 0
              AND (CAST(COALESCE(`expire_date`, 0) AS SIGNED) <= 0 OR CAST(COALESCE(`expire_date`, 0) AS SIGNED) > ?)
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if(!$stmt) return false;
    $stmt->bind_param('si', $userId, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $has = ($res && $res->num_rows > 0);
    $stmt->close();
    return $has;
}

function wizwiz_getCartToCartAccountForUser($userId = null, $paymentKeys = null){
    global $from_id, $userInfo;
    if($paymentKeys === null) $paymentKeys = wizwiz_getPaymentKeys();
    if(!is_array($paymentKeys)) $paymentKeys = [];

    if($userId === null || trim((string)$userId) === ''){
        if(isset($from_id) && trim((string)$from_id) !== '') $userId = $from_id;
        elseif(is_array($userInfo ?? null) && isset($userInfo['userid'])) $userId = $userInfo['userid'];
        else $userId = '';
    }

    $primaryBank = trim((string)($paymentKeys['bankAccount'] ?? ''));
    $primaryHolder = trim((string)($paymentKeys['holderName'] ?? ''));
    $secondBank = trim((string)($paymentKeys['secondBankAccount'] ?? ($paymentKeys['bankAccount2'] ?? '')));
    $secondHolder = trim((string)($paymentKeys['secondHolderName'] ?? ($paymentKeys['holderName2'] ?? '')));

    $hasActivePaid = wizwiz_userHasActivePaidConfig($userId);
    $useSecond = ($hasActivePaid && $secondBank !== '');

    return [
        'bank' => $useSecond ? $secondBank : $primaryBank,
        'holder' => $useSecond ? $secondHolder : $primaryHolder,
        'type' => $useSecond ? 'second' : 'first',
        'is_second' => $useSecond,
        'has_active_paid_config' => $hasActivePaid,
    ];
}

function wizwiz_cartToCartAccountTitle($account){
    return (!empty($account['is_second'])) ? 'خرید دوم و بعدی' : 'خرید اول';
}

function wizwiz_markCardInfoChanged(){
    $keys = wizwiz_getPaymentKeys();
    $keys['cardInfoVersion'] = time();
    return wizwiz_savePaymentKeys($keys);
}

function wizwiz_cardContactRaw($paymentKeys = null){
    global $admin;
    if($paymentKeys === null) $paymentKeys = wizwiz_getPaymentKeys();
    $raw = trim((string)($paymentKeys['cardContact'] ?? ''));
    return $raw !== '' ? $raw : (string)$admin;
}

function wizwiz_cardContactUrl($paymentKeys = null){
    $raw = wizwiz_cardContactRaw($paymentKeys);
    if($raw === '') return '';
    if(preg_match('/^https?:\/\//i', $raw) || preg_match('/^tg:\/\//i', $raw)) return $raw;
    if(preg_match('/^-?\d+$/', $raw)) return 'tg://user?id=' . $raw;
    return 'https://t.me/' . ltrim($raw, '@');
}

function wizwiz_cardContactDisplay($paymentKeys = null){
    $raw = wizwiz_cardContactRaw($paymentKeys);
    if($raw === '') return 'ادمین';
    if(preg_match('/^-?\d+$/', $raw)) return '<code>' . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</code>';
    return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
}

function wizwiz_userHasCardVersion($userInfo, $paymentKeys = null){
    if(!$userInfo) return false;
    return intval($userInfo['card_info_version'] ?? 0) >= wizwiz_getCardInfoVersion($paymentKeys);
}

function wizwiz_markUserCardVersion($userId, $paymentKeys = null){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $version = wizwiz_getCardInfoVersion($paymentKeys);
    $stmt = $connection->prepare("UPDATE `users` SET `card_info_version` = ? WHERE `userid` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('ii', $version, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_cartToCartKeyboard($hashId = ''){
    $rows = [];
    $hashId = trim((string)$hashId);
    if($hashId !== ''){
        $rows[] = [['text'=>'💳 گرفتن شماره کارت', 'callback_data'=>'requestCartToCartCard' . $hashId, 'style'=>'success']];
        $rows[] = [['text'=>'❌ لغو خرید', 'callback_data'=>'cancelPendingPay' . $hashId, 'style'=>'danger']];
    }else{
        $rows[] = [['text'=>'❌ لغو خرید', 'callback_data'=>'mainMenu', 'style'=>'danger']];
    }
    return wizwiz_inlineKeyboardJson($rows);
}

function wizwiz_cartToCartReceiptKeyboard($hashId = ''){
    $hashId = trim((string)$hashId);
    $cb = $hashId !== '' ? ('cancelPendingPay' . $hashId) : 'mainMenu';
    return wizwiz_inlineKeyboardJson([
        [['text'=>'❌ لغو خرید', 'callback_data'=>$cb, 'style'=>'danger']]
    ]);
}

function wizwiz_isCartToCartReceiptStep($step, &$matches = null){
    $step = (string)$step;
    return preg_match('/^(increaseWalletWithCartToCart|payCustomWithCartToCart|payWithCartToCart|payRenewWithCartToCart|payIncreaseDayWithCartToCart|payIncreaseWithCartToCart)(.+)$/', $step, $matches) === 1;
}

function wizwiz_getBestPhotoFileId($updateObj = null, $fallback = ''){
    $fallback = trim((string)$fallback);
    if($updateObj === null && isset($GLOBALS['update'])) $updateObj = $GLOBALS['update'];
    if(!isset($updateObj->message->photo) || !is_array($updateObj->message->photo) || count($updateObj->message->photo) == 0) return $fallback;
    $best = null;
    foreach($updateObj->message->photo as $photoSize){
        if(isset($photoSize->file_id) && trim((string)$photoSize->file_id) !== '') $best = $photoSize;
    }
    return $best && isset($best->file_id) ? trim((string)$best->file_id) : $fallback;
}

function wizwiz_isReceiptPhotoMessage($updateObj = null){
    return wizwiz_getBestPhotoFileId($updateObj, '') !== '';
}

function wizwiz_sendReceiptPhotoOnlyNotice($hashId = ''){
    $txt = "📸 <b>لطفاً فقط تصویر رسید پرداخت را ارسال کنید.</b>\n\n" .
           "✅ اگر عکس رسید کپشن/توضیح داشته باشد مشکلی نیست؛ ربات فقط خودِ عکس رسید را ثبت و برای ادمین ارسال می‌کند.\n" .
           "❌ متن، فایل، ویدیو، ویس یا عکس ارسال‌شده به صورت فایل قابل قبول نیست.\n\n" .
           "اگر منصرف شده‌اید، روی دکمه <b>لغو خرید</b> بزنید.";
    return sendMessage($txt, wizwiz_cartToCartReceiptKeyboard($hashId), 'HTML');
}

function wizwiz_cancelPendingPayByUser($hashId, $userId){
    global $connection;
    $hashId = trim((string)$hashId);
    $userId = intval($userId);
    if($hashId === '' || $userId <= 0) return ['ok'=>false, 'message'=>'اطلاعات پرداخت نامعتبر است.'];

    $stmt = $connection->prepare("SELECT `state`, `user_id` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return ['ok'=>false, 'message'=>'خطای دیتابیس هنگام بررسی پرداخت.'];
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$pay) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد یا قبلاً حذف شده است.'];
    if(intval($pay['user_id'] ?? 0) !== $userId) return ['ok'=>false, 'message'=>'این پرداخت متعلق به شما نیست.'];

    $state = (string)($pay['state'] ?? '');
    if(!in_array($state, ['pending', 'sent'], true)){
        return ['ok'=>false, 'message'=>'این پرداخت دیگر قابل لغو نیست.'];
    }

    $now = time();
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'cancelled_by_user', `approval_error` = NULL, `approval_error_date` = ? WHERE `hash_id` = ? AND `user_id` = ? AND `state` IN ('pending','sent')");
    if(!$stmt) return ['ok'=>false, 'message'=>'خطای دیتابیس هنگام لغو پرداخت.'];
    $stmt->bind_param('isi', $now, $hashId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return ['ok'=>($affected > 0), 'message'=>($affected > 0 ? 'خرید با موفقیت لغو شد.' : 'این پرداخت قبلاً از حالت انتظار خارج شده است.')];
}

function wizwiz_cartToCartNoCardText($alreadyReceived = false, $paymentKeys = null, $account = null){
    $contact = wizwiz_cardContactDisplay($paymentKeys);
    $accountTitle = wizwiz_cartToCartAccountTitle(is_array($account) ? $account : []);
    $requestText = (!empty($account['is_second'])) ? 'شماره کارت خرید دوم جهت واریز' : 'شماره کارت جهت واریز';
    if($alreadyReceived){
        return "💳 <b>پرداخت کارت‌به‌کارت - $accountTitle</b>\n\nشما قبلاً شماره کارت فعلی را دریافت کرده‌اید. لطفاً مبلغ را به همان شماره کارت واریز کنید.\n\nاگر شماره کارت را دوباره لازم دارید، به ادمین $contact پیام بدهید و متن زیر را ارسال کنید:\n<code>$requestText</code>\n\nبعد از واریز، تصویر رسید را همینجا بفرستید.";
    }
    return "💳 <b>پرداخت کارت‌به‌کارت - $accountTitle</b>\n\nبرای دریافت شماره کارت، روی دکمه <b>گرفتن شماره کارت</b> بزنید، به ادمین $contact پیام بدهید و متن زیر را ارسال کنید:\n<code>$requestText</code>\n\nبعد از دریافت شماره کارت و واریز، به همین ربات برگردید و تصویر رسید پرداخت را ارسال کنید.\n\nاین مرحله فقط یک‌بار برای شماره کارت فعلی لازم است؛ اگر ادمین اعلام کند شماره کارت تغییر کرده، دوباره باید شماره کارت جدید را بگیرید.";
}

function wizwiz_sendCartToCartInstructions($hashId, $templateKey, $parse = 'HTML'){
    global $mainValues, $userInfo;
    $paymentKeys = wizwiz_getPaymentKeys();
    $account = wizwiz_getCartToCartAccountForUser($userInfo['userid'] ?? null, $paymentKeys);
    $bank = trim((string)($account['bank'] ?? ''));
    $holder = trim((string)($account['holder'] ?? ''));
    $accountTitle = wizwiz_cartToCartAccountTitle($account);
    $extra = "\n\n📸 <b>بعد از واریز، فقط عکس رسید را همینجا ارسال کنید.</b>\n" .
             "اگر عکس کپشن داشته باشد مشکلی نیست؛ فقط خود عکس رسید برای ادمین ثبت می‌شود.\n" .
             "برای انصراف، دکمه <b>لغو خرید</b> را بزنید.";
    if($bank !== ''){
        $template = $mainValues[$templateKey] ?? 'ACCOUNT-NUMBER\nHOLDER-NAME';
        $txt = "💳 <b>کارت‌به‌کارت - $accountTitle</b>\n\n" . str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"], [$bank, $holder], $template) . $extra;
        sendMessage($txt, wizwiz_cartToCartReceiptKeyboard($hashId), $parse);
        return;
    }
    $already = wizwiz_userHasCardVersion($userInfo, $paymentKeys);
    sendMessage(wizwiz_cartToCartNoCardText($already, $paymentKeys, $account) . $extra, wizwiz_cartToCartKeyboard($hashId), 'HTML');
}

function wizwiz_deleteLocalOrderOnly($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return false;
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

function wizwiz_panelMissingSyncResult($syncInfo){
    return is_array($syncInfo) && !empty($syncInfo['checked']) && empty($syncInfo['found']);
}

function wizwiz_cleanupOrderIfMissingOnPanel($order, $syncInfo = null, $notifyUser = false){
    if(!is_array($order)) return false;
    if($syncInfo === null && function_exists('wizwiz_syncOrderExpiryFromPanel')){
        $syncInfo = wizwiz_syncOrderExpiryFromPanel($order, true);
    }
    if(!wizwiz_panelMissingSyncResult($syncInfo)) return false;

    $orderId = intval($order['id'] ?? 0);
    if($orderId <= 0) return false;
    $deleted = wizwiz_deleteLocalOrderOnly($orderId);
    if($deleted && $notifyUser && !empty($order['userid'])){
        $remark = htmlspecialchars((string)($order['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
        sendMessage("ℹ️ سرویس <b>$remark</b> دیگر داخل پنل وجود ندارد؛ برای جلوگیری از نمایش کانفیگ اضافه، از لیست ربات هم حذف شد.", null, 'HTML', intval($order['userid']));
    }
    return $deleted;
}

function wizwiz_panelExpiryToSeconds($value){
    if($value === null) return 0;
    if(is_string($value)){
        $value = trim($value);
        if($value === '' || $value === '0') return 0;
        $value = preg_replace('/[^0-9\-]/', '', $value);
        if($value === '' || $value === '-' || $value === '0') return 0;
    }
    $v = intval($value);
    if($v <= 0) return 0;
    // 3x-ui stores expiryTime in milliseconds; bot stores expire_date in seconds.
    if($v > 9999999999) $v = intval($v / 1000);
    return $v;
}

function wizwiz_panelClientIdentity($client){
    $id = (string)wizwiz_arrayValue($client, 'id', '');
    if($id === '') $id = (string)wizwiz_arrayValue($client, 'uuid', '');
    if($id === '') $id = (string)wizwiz_arrayValue($client, 'password', '');
    return $id;
}

function wizwiz_panelClientEmail($client){
    return trim((string)wizwiz_arrayValue($client, 'email', ''));
}

function wizwiz_panelFindClientStat($stats, $email){
    $email = trim((string)$email);
    if($email === '') return null;
    if(is_object($stats)) $stats = [$stats];
    if(!is_array($stats)) return null;
    foreach($stats as $stat){
        $statEmail = trim((string)wizwiz_arrayValue($stat, 'email', ''));
        if($statEmail !== '' && $statEmail === $email) return $stat;
    }
    return null;
}

function wizwiz_panelListFromGetJson($json){
    if(!$json || !isset($json->obj)) return [];
    $rows = $json->obj;
    if(is_object($rows)) $rows = [$rows];
    return is_array($rows) ? $rows : [];
}

function wizwiz_syncOrderExpiryFromPanel($order, $updateDb = true){
    global $connection;

    if(is_numeric($order)){
        $oid = intval($order);
        if($oid <= 0) return null;
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
        if(!$stmt) return null;
        $stmt->bind_param('i', $oid);
        $stmt->execute();
        $res = $stmt->get_result();
        $order = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
        $stmt->close();
        if(!$order) return null;
    }

    if(!is_array($order)) return null;
    $oid = intval($order['id'] ?? 0);
    $serverId = intval($order['server_id'] ?? 0);
    if($oid <= 0 || $serverId <= 0) return null;

    $uuid = trim((string)($order['uuid'] ?? ''));
    $remark = trim((string)($order['remark'] ?? ''));
    $inboundId = intval($order['inbound_id'] ?? 0);

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverInfo) return null;

    $serverType = (string)($serverInfo['type'] ?? '');
    $newExpire = 0;
    $found = false;
    $checked = false;
    $source = '';

    if($serverType === 'marzban'){
        if(function_exists('getMarzbanUser')){
            $info = getMarzbanUser($serverId, $remark);
            if(is_object($info) && isset($info->expire)){
                $checked = true;
                $found = true;
                $newExpire = wizwiz_panelExpiryToSeconds($info->expire);
                $source = 'marzban';
            }elseif(is_object($info) && isset($info->detail) && stripos((string)$info->detail, 'not found') !== false){
                $checked = true;
                $source = 'marzban_missing';
            }
        }
    }else{
        $json = getJson($serverId);
        if($json && isset($json->success) && $json->success){
            $checked = true;
        }
        $rows = wizwiz_panelListFromGetJson($json);
        foreach($rows as $row){
            $rowId = intval(wizwiz_arrayValue($row, 'id', 0));
            if($inboundId > 0 && $rowId !== $inboundId) continue;

            $settings = wizwiz_decodeMaybeJson(wizwiz_arrayValue($row, 'settings', '{}'), true);
            $clients = $settings['clients'] ?? [];
            if(!is_array($clients)) $clients = [];

            foreach($clients as $client){
                $clientId = wizwiz_panelClientIdentity($client);
                $clientEmail = wizwiz_panelClientEmail($client);
                $match = false;
                if($uuid !== '' && $clientId !== '' && $clientId === $uuid) $match = true;
                if(!$match && $remark !== '' && $clientEmail !== '' && $clientEmail === $remark) $match = true;
                if(!$match) continue;

                $found = true;

                $clientExp = wizwiz_panelExpiryToSeconds(wizwiz_arrayValue($client, 'expiryTime', 0));
                $stat = wizwiz_panelFindClientStat(wizwiz_arrayValue($row, 'clientStats', []), $clientEmail);
                $statExp = $stat ? wizwiz_panelExpiryToSeconds(wizwiz_arrayValue($stat, 'expiryTime', 0)) : 0;
                $rowExp = wizwiz_panelExpiryToSeconds(wizwiz_arrayValue($row, 'expiryTime', 0));

                // Prefer the client settings value because manual edits in 3x-ui update it first.
                if($clientExp > 0){
                    $newExpire = $clientExp;
                    $source = 'client';
                }elseif($statExp > 0){
                    $newExpire = $statExp;
                    $source = 'clientStats';
                }elseif($rowExp > 0){
                    $newExpire = $rowExp;
                    $source = 'inbound';
                }
                break 2;
            }
        }
    }

    if($found && $newExpire > 0 && $updateDb){
        $oldExpire = intval($order['expire_date'] ?? 0);
        if($oldExpire !== $newExpire){
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
            if($stmt){
                $stmt->bind_param('ii', $newExpire, $oid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    return [
        'found' => $found,
        'checked' => $checked,
        'expire_date' => $newExpire,
        'source' => $source,
    ];
}

function wizwiz_extractSubIdFromSettings($settings, $uuid = null, $remark = null){
    $settings = wizwiz_decodeMaybeJson($settings, true);
    $clients = $settings['clients'] ?? [];
    if(!is_array($clients)) return '';

    $fallback = '';
    foreach($clients as $client){
        if(!is_array($client)) continue;
        $subId = trim((string)($client['subId'] ?? ''));
        if($subId === '') continue;
        if($fallback === '') $fallback = $subId;

        $cid = isset($client['id']) ? (string)$client['id'] : '';
        $pwd = isset($client['password']) ? (string)$client['password'] : '';
        $email = isset($client['email']) ? (string)$client['email'] : '';

        if($uuid !== null && $uuid !== '' && ($cid === (string)$uuid || $pwd === (string)$uuid)) return $subId;
        if($remark !== null && $remark !== '' && $email === (string)$remark) return $subId;
    }
    return count($clients) === 1 ? $fallback : '';
}

function wizwiz_findPanelSubId($server_id, $token = '', $uuid = '', $inbound_id = 0, $remark = ''){
    global $connection;
    $token = trim((string)$token);
    $uuid = trim((string)$uuid);
    $remark = trim((string)$remark);

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($server_info && ($server_info['type'] ?? '') === 'sanaei_new' && $remark !== ''){
        [$curl, $session] = wizwiz_panelLoginSession($server_info);
        if($curl && $session){
            curl_setopt_array($curl, array(
                CURLOPT_URL => rtrim($server_info['panel_url'], '/') . '/panel/api/clients/get/' . rawurlencode($remark),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent: Mozilla/5.0',
                    'Accept: application/json, text/plain, */*',
                    'X-Requested-With: XMLHttpRequest',
                    'Cookie: ' . $session
                )
            ));
            $clientResponse = curl_exec($curl);
            curl_close($curl);
            $clientDecoded = json_decode($clientResponse, true);
            $clientObj = $clientDecoded['obj']['client'] ?? ($clientDecoded['obj'] ?? null);
            if(is_array($clientObj) && !empty($clientObj['subId'])) return (string)$clientObj['subId'];
        }
    }

    $json = getJson($server_id);
    if(!$json || !isset($json->obj) || !is_array($json->obj)){
        return ($token !== '' && !preg_match('/^[A-Za-z0-9]{30}$/', $token)) ? $token : '';
    }

    foreach($json->obj as $row){
        if($inbound_id != 0 && intval($row->id ?? 0) != intval($inbound_id)) continue;
        $settings = wizwiz_decodeMaybeJson($row->settings ?? '{}', true);
        $clients = $settings['clients'] ?? [];
        if(!is_array($clients)) continue;

        foreach($clients as $client){
            if(!is_array($client)) continue;
            $subId = trim((string)($client['subId'] ?? ''));
            if($subId === '') continue;

            $cid = isset($client['id']) ? (string)$client['id'] : '';
            $pwd = isset($client['password']) ? (string)$client['password'] : '';
            $email = isset($client['email']) ? (string)$client['email'] : '';

            if($token !== '' && $subId === $token) return $subId;
            if($uuid !== '' && ($cid === $uuid || $pwd === $uuid)) return $subId;
            if($remark !== '' && $email === $remark) return $subId;
        }
    }

    // New 3x-ui subId is usually 16 lower/number characters. Old bot tokens are 30 chars.
    if($token !== '' && !preg_match('/^[A-Za-z0-9]{30}$/', $token)) return $token;
    return '';
}

function wizwiz_panelLoginHeaders($curl, $loginUrl){
    $headers = array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest'
    );

    $baseUrl = preg_replace('#/login/?$#', '', (string)$loginUrl);
    $baseUrl = rtrim($baseUrl, '/');
    if($baseUrl === '') return $headers;

    $csrfCurl = curl_init();
    curl_setopt_array($csrfCurl, array(
        CURLOPT_URL => $baseUrl . '/csrf-token',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest'
        )
    ));

    $response = curl_exec($csrfCurl);
    if($response !== false){
        $headerSize = curl_getinfo($csrfCurl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        if(preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches) && !empty($matches[1])){
            $headers[] = 'Cookie: ' . implode('; ', $matches[1]);
        }
        $decoded = json_decode((string)$body, true);
        if(is_array($decoded) && !empty($decoded['success']) && isset($decoded['obj'])){
            $headers[] = 'X-CSRF-Token: ' . (string)$decoded['obj'];
        }
    }
    curl_close($csrfCurl);

    return $headers;
}

function wizwiz_sanaeiCollectCookiesFromHeader($header){
    $cookies = [];
    if(preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', (string)$header, $matches)){
        foreach($matches[1] as $cookieLine){
            $cookieLine = trim($cookieLine);
            if($cookieLine !== '') $cookies[] = $cookieLine;
        }
    }
    return implode('; ', array_unique($cookies));
}

function wizwiz_panelLoginSession($server_info){
    $panel_url = rtrim($server_info['panel_url'], '/');
    $loginUrl = $panel_url . '/login';
    $username = (string)($server_info['username'] ?? '');
    $password = (string)($server_info['password'] ?? '');

    $formHeaders = wizwiz_panelLoginHeaders(null, $loginUrl);
    $jsonHeaders = [];
    foreach($formHeaders as $h){
        if(stripos($h, 'Content-Type:') !== 0) $jsonHeaders[] = $h;
    }
    $jsonHeaders[] = 'Content-Type: application/json';

    $attempts = [
        ['body' => http_build_query(['username' => $username, 'password' => $password]), 'headers' => $formHeaders],
        ['body' => json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'headers' => $jsonHeaders],
    ];

    foreach($attempts as $attempt){
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $loginUrl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_POSTFIELDS => $attempt['body'],
            CURLOPT_HTTPHEADER => $attempt['headers'],
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($curl);
        if($response === false){
            curl_close($curl);
            continue;
        }
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        $session = wizwiz_sanaeiCollectCookiesFromHeader($header);
        $loginResponse = json_decode((string)$body, true);
        if($session && is_array($loginResponse) && !empty($loginResponse['success'])){
            return [$curl, $session];
        }
        curl_close($curl);
    }
    return [null, null];
}

function wizwiz_arrayGetDeep($array, $keys){
    if(!is_array($array)) return null;
    foreach($keys as $key){
        if(array_key_exists($key, $array)) return $array[$key];
    }
    foreach($array as $value){
        if(is_array($value)){
            $found = wizwiz_arrayGetDeep($value, $keys);
            if($found !== null && $found !== '') return $found;
        }
    }
    return null;
}

function wizwiz_panelUrlParts($server_info){
    $panelUrl = trim((string)($server_info['panel_url'] ?? ''));
    $parsed = @parse_url($panelUrl);
    if(!is_array($parsed)) $parsed = [];
    return [
        'scheme' => !empty($parsed['scheme']) ? $parsed['scheme'] : 'http',
        'host' => !empty($parsed['host']) ? $parsed['host'] : '',
        'port' => isset($parsed['port']) ? intval($parsed['port']) : 0,
        'path' => !empty($parsed['path']) ? $parsed['path'] : '',
    ];
}

function wizwiz_normalizeSubPath($path, $default){
    $path = trim((string)$path);
    if($path === '') $path = $default;
    if($path[0] !== '/') $path = '/' . $path;
    if(substr($path, -1) !== '/') $path .= '/';
    return $path;
}

function wizwiz_originWithPort($scheme, $host, $port = 0){
    $scheme = $scheme ?: 'http';
    $host = trim((string)$host);
    if($host === '') return '';
    $port = intval($port);
    $portPart = '';
    if($port > 0 && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))){
        $portPart = ':' . $port;
    }
    return $scheme . '://' . $host . $portPart;
}

function wizwiz_normalizeDirectSubUri($server_info, $direct, $format = 'sub'){
    $direct = trim((string)$direct);
    if($direct === '') return '';
    $parts = wizwiz_panelUrlParts($server_info);
    $scheme = $parts['scheme'];
    if(preg_match('#^https?://#i', $direct)){
        return substr($direct, -1) === '/' ? $direct : $direct . '/';
    }
    if(strpos($direct, '//') === 0){
        $direct = $scheme . ':' . $direct;
        return substr($direct, -1) === '/' ? $direct : $direct . '/';
    }
    $path = wizwiz_normalizeSubPath($direct, ($format === 'json') ? '/json/' : '/sub/');
    $origin = wizwiz_originWithPort($scheme, $parts['host'], $parts['port']);
    return $origin !== '' ? $origin . $path : $path;
}

function wizwiz_buildPanelSubBaseFromSettings($server_info, $settings, $format = 'sub'){
    $settings = is_array($settings) ? $settings : [];
    $parts = wizwiz_panelUrlParts($server_info);
    $scheme = $parts['scheme'];
    $host = trim((string)wizwiz_arrayGetDeep($settings, ['subDomain','subHost','subscriptionDomain','subscriptionHost']));
    if($host === '') $host = $parts['host'];

    // مهم: اگر subPort وجود داشته باشد، باید از خودش استفاده شود و نباید subURI ساخته شده با آدرس پنل ادمین
    // مثل http://domain:1030/wolf/sub/ را برگردانیم. ساب 3x-ui روی سرور جدا و معمولا بدون webBasePath پنل است.
    $subPortRaw = wizwiz_arrayGetDeep($settings, ['subPort','sub_port','subscriptionPort','subscription_port','subListenPort']);
    $subPort = is_numeric($subPortRaw) ? intval($subPortRaw) : 0;
    if($subPort > 0 && $host !== ''){
        if($format === 'json'){
            $path = wizwiz_arrayGetDeep($settings, ['subJsonPath','subJsonURIPath','jsonPath','json_path','subscriptionJsonPath']);
            $path = wizwiz_normalizeSubPath($path, '/json/');
        }else{
            $path = wizwiz_arrayGetDeep($settings, ['subPath','sub_path','subscriptionPath','subscription_path']);
            $path = wizwiz_normalizeSubPath($path, '/sub/');
        }
        return wizwiz_originWithPort($scheme, $host, $subPort) . $path;
    }

    $directKey = ($format === 'json') ? 'subJsonURI' : 'subURI';
    $direct = wizwiz_arrayGetDeep($settings, [$directKey]);
    $normalizedDirect = wizwiz_normalizeDirectSubUri($server_info, $direct, $format);
    if($normalizedDirect !== '') return $normalizedDirect;

    $path = ($format === 'json') ? '/json/' : '/sub/';
    $origin = wizwiz_originWithPort($scheme, $host, $parts['port']);
    return $origin !== '' ? $origin . $path : rtrim((string)($server_info['panel_url'] ?? ''), '/') . $path;
}

function wizwiz_getPanelSettingResponse($server_info, $session, $endpoint){
    $panel = rtrim($server_info['panel_url'] ?? '', '/');
    if($panel === '') return null;

    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $session
    );
    if(function_exists('wizwiz_sanaeiNewCsrfToken')){
        $csrf = wizwiz_sanaeiNewCsrfToken(null, $panel, $session);
        if($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    foreach(['POST','GET'] as $method){
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $panel . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers
        ));
        if($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, '');
        $response = curl_exec($curl);
        curl_close($curl);
        $decoded = json_decode((string)$response, true);
        if(!is_array($decoded) || empty($decoded['success']) || !array_key_exists('obj', $decoded)) continue;
        $obj = $decoded['obj'];
        if(is_string($obj)){
            $objDecoded = json_decode($obj, true);
            if(json_last_error() === JSON_ERROR_NONE && is_array($objDecoded)) $obj = $objDecoded;
        }
        if(is_array($obj)) return wizwiz_normalizePanelSettingsArray($obj);
    }
    return null;
}

function wizwiz_getPanelSubscriptionUris($server_id){
    global $connection;
    static $cache = [];
    if(isset($cache[$server_id])) return $cache[$server_id];

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $result = [
        'subURI' => wizwiz_buildPanelSubBaseFromSettings($server_info ?: [], [], 'sub'),
        'subJsonURI' => wizwiz_buildPanelSubBaseFromSettings($server_info ?: [], [], 'json'),
        'subEnable' => true,
    ];

    if(!$server_info || !wizwiz_isPanelSubscriptionServer($server_info['type'] ?? '')){
        $cache[$server_id] = $result;
        return $result;
    }

    [$curl, $session] = wizwiz_panelLoginSession($server_info);
    if($curl) curl_close($curl);
    if(!$session){
        $cache[$server_id] = $result;
        return $result;
    }

    // The UI copy button uses computed subscription settings. In 2.6.x and current 3x-ui this is exposed by
    // /panel/setting/defaultSettings; /panel/setting/all may contain raw webBasePath/webPort values and can recreate
    // the wrong :panelPort/basePath/sub/ URL. Prefer defaultSettings, then fall back to all.
    $settingsDefault = wizwiz_getPanelSettingResponse($server_info, $session, '/panel/setting/defaultSettings');
    $settingsAll = wizwiz_getPanelSettingResponse($server_info, $session, '/panel/setting/all');

    foreach([$settingsDefault, $settingsAll] as $settings){
        if(!is_array($settings) || empty($settings)) continue;
        $hasSubInfo = wizwiz_arrayGetDeep($settings, ['subURI','subJsonURI','subPort','sub_port','subscriptionPort','subscription_port','subPath','sub_path']) !== null;
        if(!$hasSubInfo) continue;
        $result['subURI'] = wizwiz_buildPanelSubBaseFromSettings($server_info, $settings, 'sub');
        $result['subJsonURI'] = wizwiz_buildPanelSubBaseFromSettings($server_info, $settings, 'json');
        if(array_key_exists('subEnable', $settings)) $result['subEnable'] = (bool)$settings['subEnable'];
        break;
    }

    $cache[$server_id] = $result;
    return $result;
}

function wizwiz_panelSubLinkBySubId($server_id, $subId, $format = 'sub'){
    $subId = trim((string)$subId);
    if($subId === '') return '';
    $uris = wizwiz_getPanelSubscriptionUris($server_id);
    $base = ($format === 'json') ? ($uris['subJsonURI'] ?? '') : ($uris['subURI'] ?? '');
    if($base === '') return '';
    return rtrim($base, '/') . '/' . rawurlencode($subId);
}

function wizwiz_makeCustomerSubLink($server_id, $token = '', $uuid = '', $inbound_id = 0, $remark = '', $format = 'sub'){
    global $connection, $botUrl;

    $stmt = $connection->prepare("SELECT `type`, `panel_url` FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info) return '';

    $serverType = $server_info['type'] ?? '';
    if($serverType === 'marzban'){
        $token = trim((string)$token);
        if($token === '') return '';
        return rtrim($server_info['panel_url'], '/') . '/sub/' . rawurlencode($token);
    }

    if(wizwiz_isPanelSubscriptionServer($serverType)){
        $subId = wizwiz_findPanelSubId($server_id, $token, $uuid, $inbound_id, $remark);
        return $subId !== '' ? wizwiz_panelSubLinkBySubId($server_id, $subId, $format) : '';
    }

    $token = trim((string)$token);
    return $token !== '' ? $botUrl . 'settings/subLink.php?token=' . urlencode($token) : '';
}


function wizwiz_replyMarkupHasButtonStyle($markup){
    if($markup === null || $markup === '') return false;
    $decoded = is_string($markup) ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return false;
    $stack = [$decoded];
    while($stack){
        $item = array_pop($stack);
        if(is_array($item)){
            if(array_key_exists('style', $item)) return true;
            foreach($item as $v){
                if(is_array($v)) $stack[] = $v;
            }
        }
    }
    return false;
}

function wizwiz_stripButtonStylesRecursive($value){
    if(is_array($value)){
        unset($value['style']);
        foreach($value as $k => $v){
            if(is_array($v)) $value[$k] = wizwiz_stripButtonStylesRecursive($v);
        }
    }
    return $value;
}

function wizwiz_stripButtonStylesFromMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;
    $decoded = wizwiz_stripButtonStylesRecursive($decoded);
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wizwiz_replyMarkupHasCopyTextButton($markup){
    if($markup === null || $markup === '') return false;
    $decoded = is_string($markup) ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return false;
    $stack = [$decoded];
    while($stack){
        $item = array_pop($stack);
        if(is_array($item)){
            if(array_key_exists('copy_text', $item)) return true;
            foreach($item as $v){
                if(is_array($v)) $stack[] = $v;
            }
        }
    }
    return false;
}

function wizwiz_fallbackCopyTextButtonsRecursive($value){
    if(is_array($value)){
        if(array_key_exists('copy_text', $value)){
            unset($value['copy_text']);
            $hasAction = false;
            foreach(['url','callback_data','web_app','login_url','switch_inline_query','switch_inline_query_current_chat','switch_inline_query_chosen_chat','callback_game','pay'] as $field){
                if(array_key_exists($field, $value)){ $hasAction = true; break; }
            }
            if(!$hasAction) $value['callback_data'] = 'wizwizch';
        }
        foreach($value as $k => $v){
            if(is_array($v)) $value[$k] = wizwiz_fallbackCopyTextButtonsRecursive($v);
        }
    }
    return $value;
}

function wizwiz_fallbackCopyTextButtonsFromMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;
    $decoded = wizwiz_fallbackCopyTextButtonsRecursive($decoded);
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bot($method, $datas = []){
    global $botToken;
    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;

    $sendRequest = function($payload) use ($url){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        $res = curl_exec($ch);
        if (curl_error($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            return [null, $err];
        }
        curl_close($ch);
        return [$res, null];
    };

    [$res, $err] = $sendRequest($datas);
    if($err){
        var_dump($err);
        return null;
    }

    $decoded = json_decode($res);
    $currentDatas = $datas;

    // اگر سرور/کلاینت Bot API با style مشکل داشت، یک بار بدون style دوباره تلاش می‌کنیم
    // تا دکمه‌ها کلاً از کار نیفتند.
    if(isset($currentDatas['reply_markup']) && wizwiz_replyMarkupHasButtonStyle($currentDatas['reply_markup']) && is_object($decoded) && isset($decoded->ok) && !$decoded->ok){
        $desc = strtolower((string)($decoded->description ?? ''));
        if(strpos($desc, 'style') !== false || strpos($desc, 'button') !== false || strpos($desc, 'reply markup') !== false){
            $retryDatas = $currentDatas;
            $retryDatas['reply_markup'] = wizwiz_stripButtonStylesFromMarkup($retryDatas['reply_markup']);
            [$res2, $err2] = $sendRequest($retryDatas);
            if(!$err2){
                $decoded2 = json_decode($res2);
                if(is_object($decoded2) && (!isset($decoded2->ok) || $decoded2->ok)) return $decoded2;
                if(is_object($decoded2)) $decoded = $decoded2;
                $currentDatas = $retryDatas;
            }
        }
    }

    // اگر Bot API نصب‌شده قدیمی باشد و copy_text را نشناسد، دکمه به حالت عادی برمی‌گردد
    // تا آپدیت دکمه‌های سفارش از کار نیفتد.
    if(isset($currentDatas['reply_markup']) && wizwiz_replyMarkupHasCopyTextButton($currentDatas['reply_markup']) && is_object($decoded) && isset($decoded->ok) && !$decoded->ok){
        $desc = strtolower((string)($decoded->description ?? ''));
        if(strpos($desc, 'copy_text') !== false || strpos($desc, 'button') !== false || strpos($desc, 'reply markup') !== false){
            $retryDatas = $currentDatas;
            $retryDatas['reply_markup'] = wizwiz_fallbackCopyTextButtonsFromMarkup($retryDatas['reply_markup']);
            [$res3, $err3] = $sendRequest($retryDatas);
            if(!$err3){
                $decoded3 = json_decode($res3);
                if(is_object($decoded3)) return $decoded3;
            }
        }
    }

    return $decoded;
}
function sendMessage($txt, $key = null, $parse ="MarkDown", $ci= null, $msg = null){
    global $from_id;
    $ci = $ci??$from_id;
    $key = wizwiz_styleReplyMarkup($key);
    return bot('sendMessage',[
        'chat_id'=>$ci,
        'text'=>$txt,
        'reply_to_message_id'=>$msg,
        'reply_markup'=>$key,
        'parse_mode'=>$parse
    ]);
}
function editKeys($keys = null, $msgId = null, $ci = null){
    global $from_id,$message_id;
    $ci = $ci??$from_id;
    $msgId = $msgId??$message_id;
    $keys = wizwiz_styleReplyMarkup($keys);
   
    bot('editMessageReplyMarkup',[
		'chat_id' => $ci,
		'message_id' => $msgId,
		'reply_markup' => $keys
    ]);
}
function editText($msgId, $txt, $key = null, $parse = null, $ci = null){
    global $from_id;
    $ci = $ci??$from_id;
    $key = wizwiz_styleReplyMarkup($key);

    return bot('editMessageText', [
        'chat_id' => $ci,
        'message_id' => $msgId,
        'text' => $txt,
        'parse_mode' => $parse,
        'reply_markup' =>  $key
        ]);
}
function delMessage($msg = null, $chat_id = null){
    global $from_id, $message_id;
    $msg = $msg??$message_id;
    $chat_id = $chat_id??$from_id;
    
    return bot('deleteMessage',[
        'chat_id'=>$chat_id,
        'message_id'=>$msg
        ]);
}
function sendAction($action, $ci= null){
    global $from_id;
    $ci = $ci??$from_id;

    return bot('sendChatAction',[
        'chat_id'=>$ci,
        'action'=>$action
    ]);
}
function forwardmessage($tochatId, $fromchatId, $message_id){
    return bot('forwardMessage',[
        'chat_id'=>$tochatId,
        'from_chat_id'=>$fromchatId,
        'message_id'=>$message_id
    ]);
}
function sendPhoto($photo, $caption = null, $keyboard = null, $parse = "MarkDown", $ci =null){
    global $from_id;
    $ci = $ci??$from_id;
    $keyboard = wizwiz_styleReplyMarkup($keyboard);
    return bot('sendPhoto',[
        'chat_id'=>$ci,
        'caption'=>$caption,
        'reply_markup'=>$keyboard,
        'photo'=>$photo,
        'parse_mode'=>$parse
    ]);
}
function getFileUrl($fileid){
    $filePath = bot('getFile',[
        'file_id'=>$fileid
    ])->result->file_path;
    return "https://api.telegram.org/file/bot" . $botToken . "/" . $filePath;
}
function alert($txt, $type = false, $callid = null){
    global $callbackId;
    $callid = $callid??$callbackId;
    return bot('answercallbackquery', [
        'callback_query_id' => $callid,
        'text' => $txt,
        'show_alert' => $type
    ]);
}

$range = [
        '149.154.160.0/22',
        '149.154.164.0/22',
        '91.108.4.0/22',
        '91.108.56.0/22',
        '91.108.8.0/22',
        '95.161.64.0/20',
    ];
function check($return = false){
    global $range;
    foreach ($range as $rg) {
        if (ip_in_range($_SERVER['REMOTE_ADDR'], $rg)) {
            return true;
        }
    }
    if ($return == true) {
        return false;
    }

    die('You do not have access');

}
function curl_get_file_contents($URL){
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    curl_close($c);

    if ($contents) return $contents;
    else return FALSE;
}

function ip_in_range($ip, $range){
    if (strpos($range, '/') == false) {
        $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list($range, $netmask) = explode('/', $range, 2);
    $range_decimal = ip2long($range);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~$wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}

$time = time();
$update = json_decode(file_get_contents("php://input"));
if(isset($update->message)){
    $from_id = $update->message->from->id;
    $text = $update->message->text;
    $first_name = htmlspecialchars($update->message->from->first_name);
    $caption = $update->message->caption;
    $chat_id = $update->message->chat->id;
    $last_name = htmlspecialchars($update->message->from->last_name);
    $username = $update->message->from->username?? " ندارد ";
    $message_id = $update->message->message_id;
    $forward_from_name = $update->message->reply_to_message->forward_sender_name;
    $forward_from_id = $update->message->reply_to_message->forward_from->id;
    $reply_text = $update->message->reply_to_message->text;
}
if(isset($update->callback_query)){
    $callbackId = $update->callback_query->id;
    $data = $update->callback_query->data;
    $text = $update->callback_query->message->text;
    $message_id = $update->callback_query->message->message_id;
    $chat_id = $update->callback_query->message->chat->id;
    $chat_type = $update->callback_query->message->chat->type;
    $username = htmlspecialchars($update->callback_query->from->username)?? " ندارد ";
    $from_id = $update->callback_query->from->id;
    $first_name = htmlspecialchars($update->callback_query->from->first_name);
    $markup = json_decode(json_encode($update->callback_query->message->reply_markup->inline_keyboard),true);
}
if($from_id < 0) exit();
$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
$stmt->bind_param("i", $from_id);
$stmt->execute();
$uinfo = $stmt->get_result();
$userInfo = $uinfo->fetch_assoc();
$stmt->close();
 
$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
$stmt->execute();
$paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
else $paymentKeys = array();
$stmt->close();

$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
$stmt->execute();
$botState = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($botState)) $botState = json_decode($botState,true);
else $botState = array();
$stmt->close();

// اعمال تنظیمات جداگانه فروش و کیف پول برای نماینده‌ها بدون تغییر رفتار کاربران عادی.
$botState = wizwiz_applyRoleSpecificStates($botState, $userInfo);

$channelLock = $botState['lockChannel'];
$joniedState= bot('getChatMember', ['chat_id' => $channelLock,'user_id' => $from_id])->result->status;

if ($update->message->document->file_id) {
    $filetype = 'document';
    $fileid = $update->message->document->file_id;
} elseif ($update->message->audio->file_id) {
    $filetype = 'music';
    $fileid = $update->message->audio->file_id;
} elseif ($update->message->photo[0]->file_id) {
    $filetype = 'photo';
    $fileid = $update->message->photo->file_id;
    if (isset($update->message->photo[2]->file_id)) {
        $fileid = $update->message->photo[2]->file_id;
    } elseif ($fileid = $update->message->photo[1]->file_id) {
        $fileid = $update->message->photo[1]->file_id;
    } else {
        $fileid = $update->message->photo[1]->file_id;
    }
} elseif ($update->message->voice->file_id) {
    $filetype = 'voice';
    $voiceid = $update->message->voice->file_id;
} elseif ($update->message->video->file_id) {
    $filetype = 'video';
    $fileid = $update->message->video->file_id;
}

$cancelKey=json_encode(['keyboard'=>[
    [['text'=>$buttonValues['cancel']]]
],'resize_keyboard'=>true]);
$removeKeyboard = json_encode(['remove_keyboard'=>true]);

function getMainKeys(){
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues;
    $mainKeys = array();
    $temp = array();

    $isAdminUser = ($from_id == $admin || (!empty($userInfo) && !empty($userInfo['isAdmin'])));
    $addRow = function($buttons) use (&$mainKeys){
        $row = [];
        foreach($buttons as $btn){
            if(is_array($btn) && !empty($btn)) $row[] = $btn;
        }
        if(count($row) > 0) $mainKeys[] = array_slice($row, 0, 2);
    };
    $buttonIfVisible = function($key, $button) use ($botState){
        return wizwiz_userButtonVisible($key, $botState) ? $button : null;
    };

    $isAgent = (($botState['agencyState'] ?? 'off') == "on" && !empty($userInfo['is_agent']) && $userInfo['is_agent'] == 1);

    if($isAgent){
        // پنل نماینده باید علاوه بر خرید همکاری، امکانات معمول کاربر مثل حساب من، کیف پول، پشتیبانی، لینک برنامه‌ها و... را هم داشته باشد.
        $addRow([['text'=>$buttonValues['agency_setting'],'callback_data'=>"agencySettings"]]);
    }

    $definitions = [
        'request_agency' => [
            'enabled' => (!$isAgent && (($botState['agencyState'] ?? 'off') == "on" && empty($userInfo['is_agent']))),
            'buttons' => [['text'=>$buttonValues['request_agency'],'callback_data'=>"requestAgency"]]
        ],
        'my_subscriptions' => [
            'enabled' => true,
            'buttons' => [['text'=>$buttonValues['my_subscriptions'],'callback_data'=>($isAgent ? "agentConfigsList" : "mySubscriptions")]]
        ],
        'buy_subscriptions' => [
            'enabled' => (($botState['sellState'] ?? 'off') == "on" || $isAdminUser),
            'buttons' => ($isAgent ? [
                ['text'=>$buttonValues['agent_one_buy'],'callback_data'=>"agentOneBuy"],
                ['text'=>$buttonValues['agent_much_buy'],'callback_data'=>"agentMuchBuy"]
            ] : [
                ['text'=>$buttonValues['buy_subscriptions'],'callback_data'=>"buySubscription"]
            ])
        ],
        'test_account' => [
            'enabled' => (($botState['testAccount'] ?? 'off') == "on"),
            'buttons' => [['text'=>'اکانت تست','callback_data'=>"getTestAccount"]]
        ],
        'wallet_charge' => [
            'enabled' => wizwiz_isWalletOpenForCurrentUser(),
            'buttons' => [['text'=>$buttonValues['sharj'],'callback_data'=>"increaseMyWallet"]]
        ],
        'invite_friends' => [
            'enabled' => true,
            'buttons' => [['text'=>$buttonValues['invite_friends'],'callback_data'=>"inviteFriends"]]
        ],
        'my_info' => [
            'enabled' => true,
            'buttons' => [['text'=>$buttonValues['my_info'],'callback_data'=>"myInfo"]]
        ],
        'shared_existence' => [
            'enabled' => (($botState['sharedExistence'] ?? 'off') == "on"),
            'buttons' => [['text'=>$buttonValues['shared_existence'],'callback_data'=>"availableServers"]]
        ],
        'individual_existence' => [
            'enabled' => (($botState['individualExistence'] ?? 'off') == "on"),
            'buttons' => [['text'=>$buttonValues['individual_existence'],'callback_data'=>"availableServers2"]]
        ],
        'application_links' => [
            'enabled' => true,
            'buttons' => [['text'=>$buttonValues['application_links'],'callback_data'=>"reciveApplications"]]
        ],
        'my_tickets' => [
            'enabled' => true,
            'buttons' => [['text'=>$buttonValues['my_tickets'],'callback_data'=>"supportSection"]]
        ],
        'search_config' => [
            'enabled' => (($botState['searchState'] ?? 'off') == "on" || $isAdminUser),
            'buttons' => [['text'=>$buttonValues['search_config'],'callback_data'=>"showUUIDLeft"]]
        ],
        'refresh_panel' => [
            'enabled' => true,
            'buttons' => [['text'=>'🔄 بروزرسانی پنل', 'callback_data'=>'mainMenu']]
        ],
    ];

    $row = [];
    $rowBreaks = wizwiz_getUserButtonRowBreaks($botState);
    foreach(wizwiz_getUserButtonOrder($botState) as $key){
        if(!isset($definitions[$key])) continue;
        if(empty($definitions[$key]['enabled'])) continue;
        if(!wizwiz_userButtonVisible($key, $botState)) continue;
        foreach($definitions[$key]['buttons'] as $button){
            $row[] = $button;
            if(count($row) >= 2){
                $addRow($row);
                $row = [];
            }
        }
        if(!empty($rowBreaks[$key]) && count($row) > 0){
            $addRow($row);
            $row = [];
        }
    }
    if(count($row) > 0) $addRow($row);

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    if($buttons->num_rows >0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = str_replace("MAIN_BUTTONS","",$row['type']);
            $temp[] =['text'=>$title,'callback_data'=>"showMainButtonAns" . $rowId];
            if(count($temp)>=2){
                array_push($mainKeys,$temp);
                $temp = array();
            }
        }
    }
    if(count($temp) > 0) array_push($mainKeys,$temp);
    if($isAdminUser) array_push($mainKeys,[['text'=>"مدیریت ربات ⚙️",'callback_data'=>"managePanel"]]);
    return wizwiz_inlineKeyboardJson($mainKeys); 
}
function getAgentKeys(){
    global $buttonValues, $mainValues, $from_id, $userInfo, $connection;
    $agencyDate = jdate("Y-m-d H:i:s",$userInfo['agent_date']);
    $joinedDate = jdate("Y-m-d H:i:s",$userInfo['date']);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `agent_bought` = 1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $boughtAccounts = $stmt->get_result()->num_rows;
    $stmt->close();
    
    return json_encode(['inline_keyboard'=>[
        [['text'=>$boughtAccounts,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_bought_accounts'],'callback_data'=>"wizwizch"]],
        [['text'=>$joinedDate,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_joined_date'],'callback_data'=>"wizwizch"]],
        [['text'=>$agencyDate,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_agency_date'],'callback_data'=>"wizwizch"]],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
    ]]);
}
function getAdminKeys(){
    global $buttonValues, $mainValues, $from_id, $admin;
    
    return json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['bot_reports'],'callback_data'=>"botReports"],['text'=>$buttonValues['message_to_user'],'callback_data'=>"messageToSpeceficUser"]],
        [['text'=>$buttonValues['user_reports'],'callback_data'=>"userReports"]],
        ($from_id == $admin?[['text'=>$buttonValues['admins_list'],'callback_data'=>"adminsList"]]:[]),
        [['text'=>$buttonValues['increase_wallet'],'callback_data'=>"increaseUserWallet"],['text'=>$buttonValues['decrease_wallet'],'callback_data'=>"decreaseUserWallet"]],
        [['text'=>$buttonValues['create_account'],'callback_data'=>"createMultipleAccounts"],
        ['text'=>$buttonValues['gift_volume_day'],'callback_data'=>"giftVolumeAndDay"]],
        [['text'=>$buttonValues['ban_user'],'callback_data'=>"banUser"],['text'=>$buttonValues['unban_user'],'callback_data'=>"unbanUser"]],
        [['text'=>$buttonValues['search_admin_config'],'callback_data'=>"searchUsersConfig"]],
        [['text'=>$buttonValues['server_settings'],'callback_data'=>"serversSetting"]],
        [['text'=>$buttonValues['categories_settings'],'callback_data'=>"categoriesSetting"]],
        [['text'=>$buttonValues['plan_settings'],'callback_data'=>"backplan"]],
        [['text'=>$buttonValues['discount_settings'],'callback_data'=>"discount_codes"],['text'=>$buttonValues['main_button_settings'],'callback_data'=>"mainMenuButtons"]],
        [['text'=>$buttonValues['gateways_settings'],'callback_data'=>"gateWays_Channels"],['text'=>$buttonValues['bot_settings'],'callback_data'=>'botSettings']],
        [['text'=>$buttonValues['tickets_list'],'callback_data'=>"ticketsList"],['text'=>$buttonValues['message_to_all'],'callback_data'=>"message2All"]],
        [['text'=>$buttonValues['forward_to_all'],'callback_data'=>"forwardToAll"]],
        [
            ['text'=>$buttonValues['agent_list'],'callback_data'=>"agentsList"],
            ['text'=>'درخواست های رد شده','callback_data'=>"rejectedAgentList"]
            ],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
    ]]);
    
}



function wizwiz_isTestAccountExempt($user){
    return !empty($user) && isset($user['test_account_exempt']) && intval($user['test_account_exempt']) === 1;
}

function wizwiz_getUserTestAccountLimit($user){
    if(wizwiz_isTestAccountExempt($user)) return 0;
    if(!empty($user) && array_key_exists('test_account_limit', $user) && $user['test_account_limit'] !== null && $user['test_account_limit'] !== ''){
        $limit = intval($user['test_account_limit']);
        if($limit >= 0) return $limit;
    }
    return 1;
}

function wizwiz_getUserTestAccountUsedCount($user){
    if(empty($user)) return 0;
    $count = 0;
    if(array_key_exists('test_account_count', $user)) $count = max(0, intval($user['test_account_count']));
    if(!empty($user['freetrial'])) $count = max($count, 1);
    return $count;
}

function wizwiz_canUserGetTestAccount($user, $userId = null){
    global $admin;
    if(!empty($userId) && intval($userId) === intval($admin)) return true;
    if(!empty($user) && !empty($user['isAdmin'])) return true;
    $limit = wizwiz_getUserTestAccountLimit($user);
    if($limit === 0) return true;
    return wizwiz_getUserTestAccountUsedCount($user) < $limit;
}

function wizwiz_getTestAccountLimitText($user){
    $limit = wizwiz_getUserTestAccountLimit($user);
    return $limit === 0 ? 'نامحدود' : ($limit . ' بار');
}

function wizwiz_markTestAccountUsed($userId){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $stmt = $connection->prepare("UPDATE `users` SET `test_account_count` = GREATEST(COALESCE(`test_account_count`,0), IF(`freetrial` IS NOT NULL AND `freetrial` <> '', 1, 0)) + 1, `freetrial` = 'used' WHERE `userid` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getTestAccountManageKeys(){
    global $connection, $buttonValues;
    $totalUsers = 0;
    $usedUsers = 0;
    $customUsers = 0;
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users`"));
    if($res) $totalUsers = intval(($res->fetch_assoc())['c'] ?? 0);
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users` WHERE `freetrial` IS NOT NULL OR COALESCE(`test_account_count`,0) > 0"));
    if($res) $usedUsers = intval(($res->fetch_assoc())['c'] ?? 0);
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users` WHERE `test_account_exempt` = 1 OR (`test_account_limit` IS NOT NULL AND `test_account_limit` >= 0)"));
    if($res) $customUsers = intval(($res->fetch_assoc())['c'] ?? 0);

    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'👥 کاربران: ' . $totalUsers, 'callback_data'=>'wizwizch', 'style'=>'primary'],
            ['text'=>'🧪 استفاده‌کرده: ' . $usedUsers, 'callback_data'=>'wizwizch', 'style'=>'primary']
        ],
        [
            ['text'=>'⚙️ سقف اختصاصی: ' . $customUsers, 'callback_data'=>'wizwizch', 'style'=>'primary']
        ],
        [
            ['text'=>'♻️ ریست تست یک کاربر', 'callback_data'=>'resetOneTestAccount', 'style'=>'primary'],
            ['text'=>'✏️ تنظیم سقف یک کاربر', 'callback_data'=>'setTestAccountLimit', 'style'=>'success']
        ],
        [
            ['text'=>'🔴 بازگشت به سقف پیش‌فرض', 'callback_data'=>'removeTestAccountLimit', 'style'=>'danger'],
            ['text'=>'📋 لیست سقف‌های اختصاصی', 'callback_data'=>'testAccountLimitList', 'style'=>'primary']
        ],
        [
            ['text'=>'🧹 ریست استفاده تست برای همه', 'callback_data'=>'resetAllTestAccountsAsk', 'style'=>'danger']
        ],
        [
            ['text'=>$buttonValues['back_button'] ?? '⬅️ بازگشت', 'callback_data'=>'mainMenu', 'style'=>'primary']
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function wizwiz_getTestAccountLimitsListText(){
    global $connection;
    $stmt = $connection->prepare("SELECT `userid`, `name`, `username`, `test_account_exempt`, `test_account_limit`, `test_account_count`, `freetrial` FROM `users` WHERE `test_account_exempt` = 1 OR (`test_account_limit` IS NOT NULL AND `test_account_limit` >= 0) ORDER BY `id` DESC LIMIT 80");
    if(!$stmt) return "📋 لیست محدودیت‌های اختصاصی اکانت تست در حال حاضر قابل دریافت نیست.";
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    if(!$list || $list->num_rows == 0){
        return "📋 در حال حاضر برای هیچ کاربری سقف اختصاصی اکانت تست ثبت نشده است.";
    }
    $msg = "📋 <b>سقف‌های اختصاصی اکانت تست</b>\n\n";
    while($row = $list->fetch_assoc()){
        $uid = htmlspecialchars((string)$row['userid'], ENT_QUOTES, 'UTF-8');
        $name = trim((string)($row['name'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $display = $name ?: ($username ? '@' . ltrim($username, '@') : 'بدون نام');
        $display = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
        $limitText = (intval($row['test_account_exempt'] ?? 0) === 1) ? 'نامحدود' : (intval($row['test_account_limit'] ?? 1) . ' بار');
        $usedText = wizwiz_getUserTestAccountUsedCount($row);
        $msg .= "• <code>{$uid}</code> - {$display}\n  سقف: <b>{$limitText}</b> | استفاده‌شده: <b>{$usedText}</b>\n\n";
    }
    return $msg;
}

function setSettings($field, $value){
    global $connection, $botState;
    $botState[$field]= $value;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
}
function getRejectedAgentList(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 2");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows>0){
        $keys = array();
        $keys[] = [['text'=>"آزاد ساختن",'callback_data'=>"wizwizch"],['text'=>"اسم کاربر",'callback_data'=>'wizwizch'],['text'=>"آیدی عددی",'callback_data'=>"wizwizch"]];
        while($row = $list->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            
            $keys[] = [['text'=>"✅",'callback_data'=>"releaseRejectedAgent" . $userId],['text'=>$fullName,'callback_data'=>"wizwizch"],['text'=>$userId,'callback_data'=>"wizwizch"]];
        }
        $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
        return json_encode(['inline_keyboard'=>$keys]);
    }else return null;
}
function getAgentDetails($userId){
    global $connection, $mainVAlues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? AND `is_agent` = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $agentDetail = $stmt->get_result();
    $stmt->close();


    $today = strtotime("today");
    $yesterday = strtotime("yesterday");
    $lastWeek = strtotime("last week");
    $lastMonth = strtotime("last month");

    $stmt = $connection->prepare("SELECT COUNT(`id`) AS `count`, SUM(`amount`) AS `total` FROM `orders_list` WHERE `date` >= ? AND `agent_bought` = 1 AND `userid` = ?");
    
    $stmt->bind_param("ii", $today, $userId);
    $stmt->execute();
    $todayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $yesterday, $userId);
    $stmt->execute();
    $yesterdayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastWeek, $userId);
    $stmt->execute();
    $lastWeekIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastMonth, $userId);
    $stmt->execute();
    $lastMonthIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->close();
    
    
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"(" . $todayIncome['count'] . ") " . number_format($todayIncome['total']),'callback_data'=>'wizwizch'],
            ['text'=>"درآمد امروز",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>"(" . $yesterdayIncome['count'] . ") " . number_format($yesterdayIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد دیروز",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastWeekIncome['count'] . ") " . number_format($lastWeekIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد یک هفته",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastMonthIncome['count'] . ") " . number_format($lastMonthIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد یک ماه",'callback_data'=>"wizwizch"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]]
        ]]);
}
function checkSpam(){
    global $connection, $from_id, $userInfo, $admin;
    
    if($userInfo != null && $from_id != $admin){
        $spamInfo = json_decode($userInfo['spam_info'],true)??array();
        $spamDate = $spamInfo['date'];
        if(isset($spamInfo['banned'])){
            if(time() <= $spamInfo['banned']) return $spamInfo['banned'];
        }
        
        if(time() <= $spamDate) $spamInfo['count'] += 1;
        else{
            $spamInfo['count'] = 1;
            $spamInfo['date'] = strtotime("+1 minute");
        }
        if($spamInfo['count'] >= 50){
            $spamInfo['banned'] = strtotime("+1 day");
        }
        $spamInfo = json_encode($spamInfo);
        
        $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
        $stmt->bind_param("si", $spamInfo, $from_id);
        $stmt->execute();
        $stmt->close();
    }else return null;
}
function getAgentsList($offset = 0){
    global $connection, $mainValues, $buttonValues;
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $agentList = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($agentList->num_rows == 0 && $offset == 0){
        $keys[] = [['text'=>'➕ افزودن نماینده دستی', 'callback_data'=>'addAgentManual', 'style'=>'success']];
        $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
    }
    
    if($offset == 0) $keys[] = [['text'=>'➕ افزودن نماینده دستی', 'callback_data'=>'addAgentManual', 'style'=>'success']];
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"درصد تخفیف",'callback_data'=>"wizwizch"],['text'=>"تاریخ نمایندگی",'callback_data'=>"wizwizch"],['text'=>"اسم نماینده",'callback_data'=>"wizwizch"],['text'=>"آیدی عددی",'callback_data'=>"wizwizch"]];
    if($agentList->num_rows > 0){
        while($row = $agentList->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $userUserName = $userDetail->username;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            $joinedDate = jdate("Y-m-d H:i",$row['agent_date']);

            $keys[] = [['text'=>"❌",'callback_data'=>"removeAgent" . $userId],['text'=>"⚙️",'callback_data'=>"agentPercentDetails" . $userId],['text'=>$joinedDate,'callback_data'=>"wizwizch"],['text'=>$fullName,'callback_data'=>"agentDetails" . $userId],['text'=>$userId,'callback_data'=>"agentDetails" . $userId]];
        }
    }
    if($offset == 0 && $limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)]
            ];
    elseif($limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
    elseif($offset != 0)
        $keys[] = [
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
            
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getAgentDiscounts($agentId){
    global $connection, $mainValues, $buttonValues, $botState;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agentInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $keys = array();
    
    $discounts = json_decode($agentInfo['discount_percent'],true);

    $normal = $discounts['normal'];
    $keys[] = [['text'=>" ",'callback_data'=>"wizwizch"],
    ['text'=>$normal . "%",'callback_data'=>"editAgentDiscountNormal" . $agentId . "_0"],
    ['text'=>"عمومی",'callback_data'=>"wizwizch"]];            
    
    if($botState['agencyPlanDiscount']=="on"){
        foreach($discounts['plans'] as $planId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $info['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentPlan" . $agentId . "_" . $planId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountPlan" . $agentId . "_" . $planId],
            ['text'=>$info['title'] . " " . $catInfo['title'],'callback_data'=>"wizwizch"]];            
        }
    }else{
        foreach($discounts['servers'] as $serverId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
            $stmt->bind_param('i', $serverId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentServer" . $agentId . "_" . $serverId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountServer" . $agentId . "_" . $serverId],
            ['text'=>$info['title'],'callback_data'=>"wizwizch"]];            
        }                
    }
    if($botState['agencyPlanDiscount']=="on")$keys[] = [['text' => "افزودن تخفیف پلن", 'callback_data' => "addDiscountPlanAgent" . $agentId]];
    else $keys[] = [['text' => "افزودن تخفیف سرور", 'callback_data' => "addDiscountServerAgent" . $agentId]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function NOWPayments($method, $endpoint, $datas = []){
    global $paymentKeys;

    $base_url = 'https://api.nowpayments.io/v1/';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment']]);
            if(!empty($datas)) {
                if(is_array($datas)) {
                    $parameters = http_build_query($datas);
                    curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint . '?' . $parameters);
                } else {
                    if($endpoint == 'payment') curl_setopt($ch, CURLOPT_URL,$base_url . $endpoint . '/' . $datas);
                }
            } else {
                curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            }
            break;

        case 'POST':
            $datas = json_encode($datas);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment'], 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
            curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            break;

        default:
            break;
    }

    $res = curl_exec($ch);
    
    if(curl_error($ch)) var_dump(curl_error($ch));
    else return json_decode($res);
}
function getServerConfigKeys($serverId,$offset = 0){
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    $cty = $cats->fetch_assoc();
    $id = $cty['id'];
    $cname = $cty['title'];
    $flagwizwiz = $cty['flag'];
    $remarkwizwiz = $cty['remark'];
    $ucount = $cty['ucount'];
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $serverConfig= $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $serverConfig['reality']=="true"?$buttonValues['active']:$buttonValues['deactive'];
    $panelUrl = $serverConfig['panel_url'];
    $sni = !empty($serverConfig['sni'])?$serverConfig['sni']:" ";
    $headerType = !empty($serverConfig['header_type'])?$serverConfig['header_type']:" ";
    $requestHeader = !empty($serverConfig['request_header'])?$serverConfig['request_header']:" ";
    $responseHeader = !empty($serverConfig['response_header'])?$serverConfig['response_header']:" ";
    $security = !empty($serverConfig['security'])?$serverConfig['security']:" ";
    $portType = $serverConfig['port_type']=="auto"?"خودکار":"تصادفی";
    $serverType = " ";
    switch ($serverConfig['type']){
        case "sanaei":
            $serverType = "سنایی قدیمی";
            break;
        case "sanaei_new":
            $serverType = "سنایی جدید";
            break;
        case "alireza":
            $serverType = "علیرضا";
            break;
        case "normal":
            $serverType = "ساده";
            break;
        case "marzban":
            $serverType = "مرزبان";
            break;
    }
    return json_encode(['inline_keyboard'=>array_merge([
        [
            ['text'=>$panelUrl,'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$cname,'callback_data'=>"editServerName$id"],
            ['text'=>"❕نام سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$flagwizwiz,'callback_data'=>"editServerFlag$id"],
            ['text'=>"🚩 پرچم سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$remarkwizwiz,'callback_data'=>"editServerRemark$id"],
            ['text'=>"📣 ریمارک سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$serverType??" ",'callback_data'=>"changeServerType$id"],
            ['text'=>"نوعیت سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$ucount,'callback_data'=>"editServerMax$id"],
            ['text'=>"ظرفیت سرور",'callback_data'=>"wizwizch"]
            ]
            ],
            ($serverConfig['type'] != "marzban"?[
        [
            ['text'=>$portType,'callback_data'=>"changePortType$id"],
            ['text'=>"نوعیت پورت",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$sni,'callback_data'=>"editsServersni$id"],
            ['text'=>"sni",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$headerType,'callback_data'=>"editsServerheader_type$id"],
            ['text'=>"header type",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$requestHeader,'callback_data'=>"editsServerrequest_header$id"],
            ['text'=>"request header",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$responseHeader,'callback_data'=>"editsServerresponse_header$id"],
            ['text'=>"response header",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$security,'callback_data'=>"editsServersecurity$id"],
            ['text'=>"security",'callback_data'=>"wizwizch"],
            ],
        (($serverConfig['type'] == "sanaei" || $serverConfig['type'] == "sanaei_new" || $serverConfig['type'] == "alireza")?
        [
            ['text'=>$reality,'callback_data'=>"changeRealityState$id"],
            ['text'=>"reality",'callback_data'=>"wizwizch"],
            ]:[]),
        [
            ['text'=>"♻️ تغییر آیپی های سرور",'callback_data'=>"changesServerIp$id"],
            ],
        [
            ['text'=>"♻️ تغییر security setting",'callback_data'=>"editsServertlsSettings$id"],
            ]
            ]:[]),[
        [
            ['text'=>"🔅تغییر اطلاعات ورود",'callback_data'=>"changesServerLoginInfo$id"],
            ],
        [
            ['text'=>"✂️ حذف سرور",'callback_data'=>"wizwizdeleteserver$id"],
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "nextServerPage" . $offset]]
        ])]);
}
function getServerListKeys($offset = 0){
    global $connection, $mainValues, $buttonValues;
    
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"وضعیت",'callback_data'=>"wizwizch"],['text'=>"تنظیمات",'callback_data'=>"wizwizch"],['text'=>"نوعیت",'callback_data'=>"wizwizch"],['text'=>"سرور",'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"سروری یافت نشد",'callback_data'=>"wizwizch"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $flagwizwiz = $cty['flag'];
            $remarkwizwiz = $cty['remark'];
            $state = $cty['state'] == "1"?$buttonValues['active']:$buttonValues['deactive'];
            $ucount = $cty['ucount'];
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $serverTypeInfo= $stmt->get_result()->fetch_assoc();
            $stmt->close(); 
            $portType = $serverTypeInfo['port_type']=="auto"?"خودکار":"تصادفی";
            $serverType = " ";
            switch ($serverTypeInfo['type']){
                case "sanaei":
                    $serverType = "سنایی قدیمی";
                    break;
                case "sanaei_new":
                    $serverType = "سنایی جدید";
                    break;
                case "alireza":
                    $serverType = "علیرضا";
                    break;
                case "normal":
                    $serverType = "ساده";
                    break;
                case "marzban":
                    $serverType = "مرزبان";
                    break;
            }
            $keys[] = [['text'=>$state,'callback_data'=>'toggleServerState' . $id . "_" . $offset],['text'=>"⚙️",'callback_data'=>"showServerSettings" . $id . "_" . $offset],['text'=>$serverType??" ",'callback_data'=>"wizwizch"],['text'=>$cname,'callback_data'=>"wizwizch"]];
        } 
    }
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]];
    }
    $keys[] = [
        ['text'=>'➕ ثبت سرور xui','callback_data'=>"addNewServer"],
        ['text'=>"➕ ثبت سرور مرزبان",'callback_data'=>"addNewMarzbanPanel"]
        ];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getCategoriesKeys($offset = 0){
    $limit = 15;
    
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"اسم دسته",'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"wizwizch"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $keys[] = [['text'=>"❌",'callback_data'=>"wizwizcategorydelete$id" . "_" . $offset],['text'=>$cname,'callback_data'=>"wizwizcategoryedit$id" . "_" . $offset]];
        }
    }
    
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]];
    }
    
    $keys[] = [['text'=>'➕ افزودن دسته جدید','callback_data'=>"addNewCategory"]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getGateWaysKeys(){
    global $connection, $mainValues, $buttonValues, $admin;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();
    
    $cartToCartState = $botState['cartToCartState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $walletState = $botState['walletState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agentWalletState = (($botState['agentWalletState'] ?? ($botState['walletState'] ?? 'off'))=="on")?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $weSwapState = $botState['weSwapState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentWallet = $botState['nowPaymentWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentOther = $botState['nowPaymentOther']=="on"?$buttonValues['on']:$buttonValues['off'];
    $tronWallet = $botState['tronWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $zarinpal = $botState['zarinpal']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nextpay = $botState['nextpay']=="on"?$buttonValues['on']:$buttonValues['off'];
    $rewaredChannel = $botState['rewardChannel']??" ";
    $lockChannel = $botState['lockChannel']??" ";

    $paymentKeys = wizwiz_getPaymentKeys();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>(!empty($paymentKeys['bankAccount'])?$paymentKeys['bankAccount']:" "),'callback_data'=>"changePaymentKeysbankAccount"],
            ['text'=>"شماره کارت خرید اول",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['holderName'])?$paymentKeys['holderName']:" "),'callback_data'=>"changePaymentKeysholderName"],
            ['text'=>"دارنده کارت خرید اول",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['secondBankAccount'])?$paymentKeys['secondBankAccount']:(!empty($paymentKeys['bankAccount2'])?$paymentKeys['bankAccount2']:" ")),'callback_data'=>"changePaymentKeyssecondBankAccount"],
            ['text'=>"شماره کارت خرید دوم",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['secondHolderName'])?$paymentKeys['secondHolderName']:(!empty($paymentKeys['holderName2'])?$paymentKeys['holderName2']:" ")),'callback_data'=>"changePaymentKeyssecondHolderName"],
            ['text'=>"دارنده کارت خرید دوم",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['cardContact'])?$paymentKeys['cardContact']:(string)$admin),'callback_data'=>"changePaymentKeyscardContact"],
            ['text'=>"ادمین دریافت شماره کارت",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>"🔄 شماره کارت عوض شده",'callback_data'=>"markCartToCartCardChanged"],
            ['text'=>"ریست دریافت کارت کاربران",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nowpayment'])?$paymentKeys['nowpayment']:" "),'callback_data'=>"changePaymentKeysnowpayment"],
            ['text'=>"کد درگاه nowPayment",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['zarinpal'])?$paymentKeys['zarinpal']:" "),'callback_data'=>"changePaymentKeyszarinpal"],
            ['text'=>"کد درگاه زرین پال",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nextpay'])?$paymentKeys['nextpay']:" "),'callback_data'=>"changePaymentKeysnextpay"],
            ['text'=>"کد درگاه نکست پی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['tronwallet'])?$paymentKeys['tronwallet']:" "),'callback_data'=>"changePaymentKeystronwallet"],
            ['text'=>"آدرس والت ترون",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$weSwapState,'callback_data'=>"changeGateWaysweSwapState"],
            ['text'=>"درگاه وی سواپ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nextpay,'callback_data'=>"changeGateWaysnextpay"],
            ['text'=>"درگاه نکست پی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$zarinpal,'callback_data'=>"changeGateWayszarinpal"],
            ['text'=>"درگاه زرین پال",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentWallet,'callback_data'=>"changeGateWaysnowPaymentWallet"],
            ['text'=>"درگاه NowPayment کیف پول",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentOther,'callback_data'=>"changeGateWaysnowPaymentOther"],
            ['text'=>"درگاه NowPayment سایر",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$tronWallet,'callback_data'=>"changeGateWaystronWallet"],
            ['text'=>"درگاه ترون",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$walletState,'callback_data'=>"changeGateWayswalletState"],
            ['text'=>"کیف پول کاربران",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$agentWalletState,'callback_data'=>"changeGateWaysagentWalletState"],
            ['text'=>"کیف پول نماینده‌ها",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$rewaredChannel,'callback_data'=>'editRewardChannel'],
            ['text'=>"کانال گزارش درآمد",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$lockChannel,'callback_data'=>'editLockChannel'],
            ['text'=>"کانال قفل",'callback_data'=>'wizwizch']
            ],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);

}
function getBotSettingKeys(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();

    $changeProtocole = $botState['changeProtocolState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $renewAccount = $botState['renewAccountState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $plandelkhahwiz = $botState['plandelkhahState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $switchLocation = $botState['switchLocationState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseTime = $botState['increaseTimeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseVolume = $botState['increaseVolumeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $subLink = $botState['subLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $configLink = $botState['configLinkState']=="off"?$buttonValues['off']:$buttonValues['on'];
    $renewConfigLink = $botState['renewConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $updateConfigLink = $botState['updateConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $individualExistence = $botState['individualExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sharedExistence = $botState['sharedExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $testAccount = $botState['testAccount']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agency = $botState['agencyState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agencyPlanDiscount = $botState['agencyPlanDiscount']=="on"?$buttonValues['plan_discount']:$buttonValues['server_discount'];
    $newMemberLock = ($botState['newMemberLockState'] ?? 'off')=="on"?$buttonValues['on']:$buttonValues['off'];
    $qrConfig = $botState['qrConfigState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $qrSub = $botState['qrSubState']=="on"?$buttonValues['on']:$buttonValues['off'];
    
    $requirePhone = $botState['requirePhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $requireIranPhone = $botState['requireIranPhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agentSellState = (($botState['agentSellState'] ?? ($botState['sellState'] ?? 'off'))=="on")?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $searchState = $botState['searchState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $updateConnectionState = $botState['updateConnectionState']=="robot"?"از روی ربات":"از روی سایت";
    $rewaredTime = ($botState['rewaredTime']??0) . " ساعت";
    switch($botState['remark']){
        case "digits":
            $remarkType = "عدد رندم 5 حرفی";
            break;
        case "manual":
            $remarkType = "توسط کاربر";
            break;
        default:
            $remarkType = "آیدی و عدد رندوم";
            break;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"🎗 بنر بازاریابی 🎗",'callback_data'=>"inviteSetting"]
            ],
        [
            ['text'=> $updateConnectionState,'callback_data'=>"changeUpdateConfigLinkState"],
            ['text'=>"آپدیت کانفیگ",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agency,'callback_data'=>"changeBotagencyState"],
            ['text'=>"نمایندگی",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agencyPlanDiscount,'callback_data'=>"changeBotagencyPlanDiscount"],
            ['text'=>"نوع تخفیف نمایندگی",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$individualExistence,'callback_data'=>"changeBotindividualExistence"],
            ['text'=>"موجودی اختصاصی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sharedExistence,'callback_data'=>"changeBotsharedExistence"],
            ['text'=>"موجودی اشتراکی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$testAccount,'callback_data'=>"changeBottestAccount"],
            ['text'=>"اکانت تست",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$changeProtocole,'callback_data'=>"changeBotchangeProtocolState"],
            ['text'=>"تغییر پروتکل",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewAccount,'callback_data'=>"changeBotrenewAccountState"],
            ['text'=>"تمدید سرویس",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$plandelkhahwiz,'callback_data'=>"changeBotplandelkhahState"],
            ['text'=>"پلن دلخواه",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$switchLocation,'callback_data'=>"changeBotswitchLocationState"],
            ['text'=>"تغییر لوکیشن",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseTime,'callback_data'=>"changeBotincreaseTimeState"],
            ['text'=>"افزایش زمان",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseVolume,'callback_data'=>"changeBotincreaseVolumeState"],
            ['text'=>"افزایش حجم",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requirePhone,'callback_data'=>"changeBotrequirePhone"],
            ['text'=>"تأیید شماره",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requireIranPhone,'callback_data'=>"changeBotrequireIranPhone"],
            ['text'=>"تأیید شماره ایرانی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sellState,'callback_data'=>"changeBotsellState"],
            ['text'=>"فروش کاربران",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$agentSellState,'callback_data'=>"changeBotagentSellState"],
            ['text'=>"فروش نماینده‌ها",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$robotState,'callback_data'=>"changeBotbotState"],
            ['text'=>"وضعیت ربات",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$subLink,'callback_data'=>"changeBotsubLinkState"],
            ['text'=>"لینک ساب و مشخصات وب",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$configLink,'callback_data'=>"changeBotconfigLinkState"],
            ['text'=>"لینک کانفیگ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$searchState,'callback_data'=>"changeBotsearchState"],
            ['text'=>"مشخصات کانفیگ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewConfigLink,'callback_data'=>"changeBotrenewConfigLinkState"],
            ['text'=>"دریافت لینک جدید",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$updateConfigLink,'callback_data'=>"changeBotupdateConfigLinkState"],
            ['text'=>"بروز رسانی لینک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrConfig,'callback_data'=>"changeBotqrConfigState"],
            ['text'=>"کیو آر کد کانفیگ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrSub,'callback_data'=>"changeBotqrSubState"],
            ['text'=>"کیو آر کد ساب",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$remarkType,'callback_data'=>"changeConfigRemarkType"],
            ['text'=>"نوع ریمارک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$rewaredTime,'callback_data'=>'editRewardTime'],
            ['text'=>"ارسال گزارش درآمد", 'callback_data'=>'wizwizch']
            ],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);

}
function getBotReportKeys(){
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `users`");
    $stmt->execute();
    $allUsers = $stmt->get_result()->num_rows;
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `orders_list`");
    $stmt->execute();
    $allOrders = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_config`");
    $stmt->execute();
    $allServers = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories`");
    $stmt->execute();
    $allCategories = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans`");
    $stmt->execute();
    $allPlans = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `state` = 'paid' OR `state` = 'approved'");
    $stmt->execute();
    $totalRewards = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    
    $persian = explode("-",jdate("Y-n-1", time()));
    $gregorian = jalali_to_gregorian($persian[0], $persian[1], $persian[2]);
    $date =  $gregorian[0] . "-" . $gregorian[1] . "-" . $gregorian[2];
    $dayTime = strtotime($date);
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $monthReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    $dayTime = strtotime("-" . (date("w")+1) . " days");
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ?  AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $weekReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    $dayTime = strtotime("today");
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $dayReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>$allUsers,'callback_data'=>'wizwizch'],
            ['text'=>"تعداد کل کاربران",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$allOrders,'callback_data'=>'wizwizch'],
            ['text'=>"کل محصولات خریداری شده",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$allServers,'callback_data'=>'wizwizch'],
            ['text'=>"تعداد سرورها",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$allCategories,'callback_data'=>'wizwizch'],
            ['text'=>"تعداد دسته ها",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$allPlans,'callback_data'=>'wizwizch'],
            ['text'=>"تعداد پلن ها",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$totalRewards,'callback_data'=>'wizwizch'],
            ['text'=>"درآمد کل",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$dayReward,'callback_data'=>'wizwizch'],
            ['text'=>"درآمد امروز",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$weekReward,'callback_data'=>'wizwizch'],
            ['text'=>"درآمد هفته",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$monthReward,'callback_data'=>'wizwizch'],
            ['text'=>"درآمد ماه",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>"برگشت به مدیریت",'callback_data'=>'managePanel']
            ]
        ]]);
}
function getAdminsKeys(){
    global $connection, $mainValues, $buttonValues, $admin;
    $keys = array();
    $mainAdminId = intval($admin ?? 0);
    if($mainAdminId != 0){
        $keys[] = [['text'=>"👑 ادمین اصلی: همیشه دریافت فیش روشن است", 'callback_data'=>"wizwizch"]];
    }
    
    $stmt = $connection->prepare("SELECT `userid`, `name`, `username`, COALESCE(`receive_order_receipts`, 0) AS `receive_order_receipts` FROM `users` WHERE `isAdmin` = true ORDER BY `id` DESC");
    $stmt->execute();
    $usersList = $stmt->get_result();
    $stmt->close();
    if($usersList->num_rows > 0){
        while($user = $usersList->fetch_assoc()){
            $uid = intval($user['userid']);
            $displayName = trim((string)($user['name'] ?? ''));
            if($displayName === '') $displayName = (string)$uid;
            $receiptEnabled = intval($user['receive_order_receipts'] ?? 0) === 1;
            $receiptText = $receiptEnabled ? "🧾 دریافت فیش: روشن ✅" : "🧾 دریافت فیش: خاموش ❌";
            $keys[] = [['text'=>"👤 " . $displayName, "callback_data"=>"wizwizch"]];
            $keys[] = [
                ['text'=>"❌ حذف ادمین", 'callback_data'=>"delAdmin" . $uid],
                ['text'=>$receiptText, 'callback_data'=>"toggleAdminReceipt" . $uid]
            ];
        }
    }else{
        $keys[] = [['text'=>"لیست ادمین های فرعی خالی است ❕",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"➕ افزودن ادمین",'callback_data'=>"addNewAdmin"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}
function getUserInfoKeys($userId){
    global $connection, $mainValues, $buttonValues; 
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i",$userId);
    $stmt->execute();
    $userCount = $stmt->get_result();
    $stmt->close();
    if($userCount->num_rows > 0){
        $userInfos = $userCount->fetch_assoc();
        $userWallet = number_format($userInfos['wallet']) . " تومان";
        
        $stmt = $connection->prepare("SELECT COUNT(amount) as count, SUM(amount) as total FROM `orders_list` WHERE `userid` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        
        $boughtService = $info['count'];
        $totalBoughtPrice = number_format($info['total']) . " تومان";
        
        $userDetail = bot('getChat',['chat_id'=>$userId])->result;
        $userUserName = $userDetail->username;
        $fullName = $userDetail->first_name . " " . $userDetail->last_name;
        
        return json_encode(['inline_keyboard'=>[
            [
                ['text'=>$userUserName??" ",'url'=>"t.me/$userUserName"],
                ['text'=>"یوزرنیم",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$fullName??" ",'callback_data'=>"wizwizch"],
                ['text'=>"نام",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$boughtService??" ",'callback_data'=>"wizwizch"],
                ['text'=>"سرویس ها",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$totalBoughtPrice??" ",'callback_data'=>"wizwizch"],
                ['text'=>"مبلغ خرید",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$userWallet??" ",'callback_data'=>"wizwizch"],
                ['text'=>"موجودی کیف پول",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]
                ],
            ]]);
    }else return null;
}
function getDiscountCodeKeys(){
    global $connection, $mainValues, $buttonValues;
    $time = time();
    $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1)");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    $keys = array();
    if($list->num_rows > 0){
        $keys[] = [['text'=>'حذف','callback_data'=>"wizwizch"],['text'=>"استفاده هر یوزر",'callback_data'=>"wizwizch"],['text'=>"تاریخ ختم",'callback_data'=>"wizwizch"],['text'=>"تعداد استفاده",'callback_data'=>"wizwizch"],['text'=>"مقدار تخفیف",'callback_data'=>"wizwizch"],['text'=>"کد تخفیف",'callback_data'=>"wizwizch"]];
        while($row = $list->fetch_assoc()){
            $date = $row['expire_date']!=0?jdate("Y/n/j H:i", $row['expire_date']):"نامحدود";
            $count = $row['expire_count']!=-1?$row['expire_count']:"نامحدود";
            $amount = $row['amount'];
            $amount = $row['type'] == 'percent'? $amount."%":$amount = number_format($amount) . " تومان";
            $hashId = $row['hash_id'];
            $rowId = $row['id'];
            $canUse = $row['can_use'];
            
            $keys[] = [['text'=>'❌','callback_data'=>"delDiscount" . $rowId],['text'=>$canUse, 'callback_data'=>"wizwizch"],['text'=>$date,'callback_data'=>"wizwizch"],['text'=>$count,'callback_data'=>"wizwizch"],['text'=>$amount,'callback_data'=>"wizwizch"],['text'=>$hashId,'callback_data'=>'copyHash' . $hashId]];
        }
    }else{
        $keys[] = [['text'=>"کد تخفیفی یافت نشد",'callback_data'=>"wizwizch"]];
    }
    
    $keys[] = [['text'=>"افزودن کد تخفیف",'callback_data'=>"addDiscountCode"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getMainMenuButtonsKeys(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = str_replace("MAIN_BUTTONS","", $row['type']);
            $answer = $row['value'];
            $keys[] = [
                        ['text'=>"❌",'callback_data'=>"delMainButton" . $rowId],
                        ['text'=>$title??" " ,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"دکمه ای یافت نشد ❕",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"افزودن دکمه جدید ➕",'callback_data'=>"addNewMainButton"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}


if(!function_exists('wizwiz_base64UrlDecodeLoose')){
function wizwiz_base64UrlDecodeLoose($data){
    $data = strtr((string)$data, '-_', '+/');
    $pad = strlen($data) % 4;
    if($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}
}

if(!function_exists('wizwiz_configLinkDomainLabel')){
function wizwiz_configLinkDomainLabel($link, $index = 0){
    $link = trim((string)$link);
    $domain = '';

    if(stripos($link, 'vmess://') === 0){
        $raw = substr($link, 8);
        $decoded = wizwiz_base64UrlDecodeLoose($raw);
        $json = @json_decode($decoded, true);
        if(is_array($json)){
            $domain = trim((string)($json['add'] ?? $json['host'] ?? ''));
        }
    }elseif(preg_match('#^(vless|trojan|ss)://#i', $link)){
        $parts = @parse_url($link);
        if(is_array($parts)){
            $domain = trim((string)($parts['host'] ?? ''));
        }
    }elseif(preg_match('#^https?://#i', $link)){
        $parts = @parse_url($link);
        if(is_array($parts)){
            $domain = trim((string)($parts['host'] ?? ''));
        }
    }

    if($domain === '') $domain = 'دامنه ' . (intval($index) + 1);
    return $domain;
}
}

if(!function_exists('wizwiz_normalizeConfigLinksArray')){
function wizwiz_normalizeConfigLinksArray($links){
    if($links === null) return [];
    if(is_string($links)){
        $decoded = @json_decode($links, true);
        if(is_array($decoded)) $links = $decoded;
        else $links = [$links];
    }elseif(is_object($links)){
        $links = (array)$links;
    }elseif(!is_array($links)){
        return [];
    }

    $out = [];
    foreach($links as $link){
        if(is_array($link) || is_object($link)) continue;
        $link = trim((string)$link);
        if($link !== '') $out[] = $link;
    }
    return array_values($out);
}
}

if(!function_exists('wizwiz_formatConfigLinksBlock')){
function wizwiz_formatConfigLinksBlock($links, $titlePrefix = 'کانفیگ با دامنه', $includeAdvice = true){
    $links = wizwiz_normalizeConfigLinksArray($links);
    if(empty($links)) return '';

    if(count($links) === 1){
        return "\n <code>" . htmlspecialchars($links[0], ENT_QUOTES, 'UTF-8') . "</code>";
    }

    $text = "";
    foreach($links as $i => $link){
        $domain = wizwiz_configLinkDomainLabel($link, $i);
        $text .= "\n🌐 {$titlePrefix} " . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . ":\n";
        $text .= "<code>" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "</code>\n";
    }

    if($includeAdvice){
        $text .= "\nℹ️ لطفاً همه کانفیگ‌ها را در برنامه خود اضافه کنید و هرکدام کیفیت و پایداری بهتری داشت، از همان استفاده کنید.";
    }
    return $text;
}
}

if(!function_exists('wizwiz_buildMultiDomainConfigMessage')){
function wizwiz_buildMultiDomainConfigMessage($remark, $links, $subLink = '', $heading = '✅ کانفیگ‌های سرویس شما آماده شد', $extraLines = ''){
    $links = wizwiz_normalizeConfigLinksArray($links);
    if(count($links) <= 1) return '';

    $remark = htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8');
    $msg = $heading . "\n";
    if($remark !== '') $msg .= "🔮 نام سرویس: <b>{$remark}</b>\n";
    $extraLines = trim((string)$extraLines);
    if($extraLines !== '') $msg .= $extraLines . "\n";
    $msg .= wizwiz_formatConfigLinksBlock($links, 'کانفیگ با دامنه', true);

    $subLink = trim((string)$subLink);
    if($subLink !== ''){
        $msg .= "\n\n🌐 لینک اشتراک:\n<code>" . htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') . "</code>";
    }
    return $msg;
}
}

if(!function_exists('wizwiz_sendMultiDomainConfigMessage')){
function wizwiz_sendMultiDomainConfigMessage($chatId, $remark, $links, $subLink = '', $serverType = '', $keyboard = null, $heading = null, $extraLines = ''){
    global $botState, $buttonValues;
    $links = wizwiz_normalizeConfigLinksArray($links);
    if(count($links) <= 1) return false;
    if(($botState['configLinkState'] ?? '') == 'off') return false;
    if($serverType === 'marzban') return false;

    if($heading === null || trim((string)$heading) === '') $heading = '✅ کانفیگ‌های سرویس شما آماده شد';
    $msg = wizwiz_buildMultiDomainConfigMessage($remark, $links, $subLink, $heading, $extraLines);
    if(trim($msg) === '') return false;

    if($keyboard === null){
        $backText = $buttonValues['back_to_main'] ?? 'بازگشت به منوی اصلی';
        $keyboard = json_encode(['inline_keyboard'=>[[['text'=>$backText,'callback_data'=>'mainMenu']]]]);
    }
    sendMessage($msg, $keyboard, 'HTML', $chatId);
    return true;
}
}

function getPlanDetailsKeys($planId){
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $pdResult = $stmt->get_result();
    $pd = $pdResult->fetch_assoc();
    $stmt->close();


    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $pd['server_id']);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $reality = $server_info['reality'];


    if($pdResult->num_rows == 0) return null;
    else {
        $id=$pd['id'];
        $name=$pd['title'];
        $price=$pd['price'];
        $acount =$pd['acount'];
        $rahgozar = $pd['rahgozar'];
        $customPath = $pd['custom_path']==true?$buttonValues['on']:$buttonValues['off'];
        $dest = $pd['dest']??" ";
        $spiderX = $pd['spiderX']??" ";
        $serverName = $pd['serverNames']??" ";
        $flow = $pd['flow'];
        $customPort = $pd['custom_port'];
        $customSni = $pd['custom_sni']??" ";
        $customDomain = trim($pd['custom_domain'] ?? "");
        $customDomainText = $customDomain !== "" ? $customDomain : "پیش‌فرض";

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $wizwizplanaccnumber = $stmt->get_result()->num_rows;
        $stmt->close();

        $srvid= $pd['server_id'];
        $keyboard = [
            ($rahgozar==true?[['text'=>"* نوع پلن: رهگذر *",'callback_data'=>'wizwizch']]:[]),
            ($rahgozar==true?[
                ['text'=>$customPath,'callback_data'=>'changeCustomPath' . $id],
                ['text'=>"Path Custom",'callback_data'=>'wizwizch'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customPort,'callback_data'=>'changeCustomPort' . $id],
                ['text'=>"پورت دلخواه",'callback_data'=>'wizwizch'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customSni,'callback_data'=>'changeCustomSni' . $id],
                ['text'=>"sni دلخواه",'callback_data'=>'wizwizch'],
                ]:[]),
            [['text'=>$customDomainText,'callback_data'=>'changeCustomDomain' . $id],['text'=>"🌐 دامنه اختصاصی پلن",'callback_data'=>"wizwizch"]],
            [['text'=>$name,'callback_data'=>"wizwizplanname$id"],['text'=>"🔮 نام پلن",'callback_data'=>"wizwizch"]],
            ($reality == "true"?[['text'=>$dest,'callback_data'=>"editDestName$id"],['text'=>"dest",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$serverName,'callback_data'=>"editServerNames$id"],['text'=>"serverNames",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$spiderX,'callback_data'=>"editSpiderX$id"],['text'=>"spiderX",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$flow,'callback_data'=>"editFlow$id"],['text'=>"flow",'callback_data'=>"wizwizch"]]:[]),
            [['text'=>$wizwizplanaccnumber,'callback_data'=>"wizwizch"],['text'=>"🎗 تعداد اکانت های فروخته شده",'callback_data'=>"wizwizch"]],
            ($pd['inbound_id'] != 0?[['text'=>"$acount",'callback_data'=>"wizwizplanslimit$id"],['text'=>"🚪 تغییر ظرفیت کانفیگ",'callback_data'=>"wizwizch"]]:[]),
            ($pd['inbound_id'] != 0?[['text'=>$pd['inbound_id'],'callback_data'=>"wizwizplansinobundid$id"],['text'=>"🚪 سطر کانفیگ",'callback_data'=>"wizwizch"]]:[]),
            [['text'=>"✏️ ویرایش توضیحات",'callback_data'=>"wizwizplaneditdes$id"]],
            [['text'=>number_format($price) . " تومان",'callback_data'=>"wizwizplanrial$id"],['text'=>"💰 قیمت پلن",'callback_data'=>"wizwizch"]],
            [['text'=>"♻️ دریافت لیست اکانت ها",'callback_data'=>"wizwizplanacclist$id"]],
            ($server_info['type'] == "marzban"?[['text'=>"انتخاب Host",'callback_data'=>"marzbanHostSettings" . $id]]:[]),
            [['text'=>"✂️ حذف",'callback_data'=>"wizwizplandelete$id"]],
            [['text' => $buttonValues['back_button'], 'callback_data' =>"plansList$srvid"]]
            ];
        return json_encode(['inline_keyboard'=>$keyboard]);
    }
}
function getUserOrderDetailKeys($id, $offset = 0){
    global $connection, $botState, $mainValues, $buttonValues, $botUrl;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    

    if($order->num_rows==0){
        return null;
    }else {
        $order = $order->fetch_assoc();
        $syncInfo = wizwiz_syncOrderExpiryFromPanel($order, true);
        if(wizwiz_cleanupOrderIfMissingOnPanel($order, $syncInfo, false)){
            return null;
        }
        if(is_array($syncInfo) && !empty($syncInfo['found']) && intval($syncInfo['expire_date'] ?? 0) > 0){
            $order['expire_date'] = intval($syncInfo['expire_date']);
        }
        $userId = $order['userid'];
        $firstName = bot('getChat',['chat_id'=>$userId])->result->first_name ?? " ";
        $fid = $order['fileid']; 
    	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result();
        $stmt->close();
	    $rahgozar = $order['rahgozar'];
        $agentBought = $order['agent_bought'];
        $isAgentBought = $agentBought == true?"بله":"نخیر";

    	if($respd && $respd->num_rows > 0){
    	    $respd = $respd->fetch_assoc(); 
    	    
    	    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $respd['catid']);
            $stmt->execute();
            $cadquery = $stmt->get_result();
            $stmt->close();


    	    if($cadquery) {
    	        $catname = $cadquery->fetch_assoc()['title'];
        	    $name = $catname." ".$respd['title'];
    	    }else $name = "$id";
        	
    	}else $name = "$id";
    	
        $date = jdate("Y-m-d H:i",$order['date']);
        $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
        $remark = $order['remark'];
        $uuid = $order['uuid']??"0";
        $acc_link = json_decode($order['link']);
        $protocol = $order['protocol'];
        $token = $order['token'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $link_status = $order['expire_date'] > time()  ? $buttonValues['active'] : $buttonValues['deactive'];
        $price = $order['amount'];
        
    	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    	$stmt->bind_param('i', $server_id);
    	$stmt->execute();
    	$serverConfig = $stmt->get_result()->fetch_assoc();
    	$stmt->close();
    	$serverType = $serverConfig['type'];
    	$panelUrl = $serverConfig['panel_url'];

        if($serverType == "marzban"){
            $info = getMarzbanUser($server_id, $remark);
            $enable = $info->status =="active"?true:false;
            $total = $info->data_limit;
            $usedTraffic = $info->used_traffic;
            
            $leftgb = round( ($total - $usedTraffic) / 1073741824, 2) . " GB";
        }else{
            $response = getJson($server_id)->obj;
            if($inbound_id == 0) {
                foreach($response as $row){
                    $clients = json_decode($row->settings)->clients;
                    if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                        $total = $row->total;
                        $up = $row->up;
                        $enable = $row->enable;
                        $down = $row->down; 
                        $netType = json_decode($row->streamSettings)->network;
                        $security = json_decode($row->streamSettings)->security;
                        break;
                    }
                }
            }else {
                foreach($response as $row){
                    if($row->id == $inbound_id) {
                        $netType = json_decode($row->streamSettings)->network;
                        $security = json_decode($row->streamSettings)->security;
                        $clientsStates = $row->clientStats;
                        $clients = json_decode($row->settings)->clients;
                        foreach($clients as $key => $client){
                            if($client->id == $uuid || $client->password == $uuid){
                                $email = $client->email;
                                $emails = array_column($clientsStates,'email');
                                $emailKey = array_search($email,$emails);
                                
                                $total = $clientsStates[$emailKey]->total;
                                $up = $clientsStates[$emailKey]->up;
                                $enable = $clientsStates[$emailKey]->enable;
                                if(!$client->enable) $enable = false;
                                $down = $clientsStates[$emailKey]->down; 
                                break;
                            }
                        }
                    }
                }
            }
            $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB";
        }
        $acc_link = wizwiz_normalizeConfigLinksArray($acc_link);
        $configLinks = "";
        
        $limit = 5;
        $count = 0;
        $pagedLinks = [];
        foreach($acc_link as $accLink){
            $count++;
            if($count <= $offset) continue;
            $pagedLinks[] = $accLink;
            if($count >= $offset + $limit) break;
        }
        if($botState['configLinkState'] != "off"){
            $configLinks = wizwiz_formatConfigLinksBlock($pagedLinks);
        }

        $keyboard = array();
        
        $configKeys = [];
        
        if(count($acc_link) > $limit){
            if($offset == 0){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"userOrderDetails{$id}_" . ($offset + $limit)]
                    ];
            }
            elseif(count($acc_link) >= $offset + $limit){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"userOrderDetails{$id}_" . ($offset + $limit)],
                    ['text'=>"»",'callback_data'=>"userOrderDetails{$id}_" . ($offset - $limit)]
                    ];
                
            }
            elseif($offset != 0){
                $configKeys = [
                    ['text'=>"»",'callback_data'=>"userOrderDetails{$id}_" . ($offset - $limit)]
                    ];
            }
        }
    
        array_push($keyboard, $configKeys, [
    			    ['text' => $userId, 'callback_data' => "wizwizch"],
                    ['text' => "آیدی کاربر", 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => $firstName, 'callback_data' => "wizwizch"],
                    ['text' => "اسم کاربر", 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => $isAgentBought, 'callback_data' => "wizwizch"],
                    ['text' => "خرید نماینده", 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => "$name", 'callback_data' => "wizwizch"],
                    ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => "$date ", 'callback_data' => "wizwizch"],
                    ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => "$expire_date ", 'callback_data' => "wizwizch"],
                    ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
                ],
                [
    			    ['text' => " $leftgb", 'callback_data' => "wizwizch"],
                    ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
    			],
                [
                    ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
                ]);
                
        if($inbound_id == 0){
            if($protocol == 'trojan') {
                if($security == "xtls"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }else{
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                    
                }
            }else {
                if($netType == "grpc"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                }
                elseif($netType == "tcp" && $security == "xtls"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }
                else{
                    array_push($keyboard, 
                        ($rahgozar == true?
                        [
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ]:
                            [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "wizwizch"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "wizwizch"],
                        ]),
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }
            }
        }else{
            array_push($keyboard, 
                [
                    ['text' => " $protocol ☑️", 'callback_data' => "wizwizch"],
                ],
                [
                    ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                    ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                    ]
                ); 
            

        }


        $stmt= $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $customerSubLink = wizwiz_makeCustomerSubLink($server_id, $token, $uuid, $inbound_id, $remark);
        $subLink = ($botState['subLinkState'] == "on" && $customerSubLink != "") ? "<code>" . $customerSubLink . "</code>" : "";

        
        $enable = $enable == true? $buttonValues['active']:$buttonValues['deactive'];
        $msg = str_replace(['STATE', 'NAME','CONNECT-LINK', 'SUB-LINK'], [$enable, $remark, $configLinks, $subLink], $mainValues['config_details_message']);
    
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        return ["keyboard"=>wizwiz_inlineKeyboardJson($keyboard),
                "msg"=>$msg];
    }
}
function getOrderDetailKeys($from_id, $id, $offset = 0){
    global $connection, $botState, $mainValues, $buttonValues, $botUrl;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows==0){
        return null;
    }else {
        $order = $order->fetch_assoc();
        $syncInfo = wizwiz_syncOrderExpiryFromPanel($order, true);
        if(wizwiz_cleanupOrderIfMissingOnPanel($order, $syncInfo, false)){
            return null;
        }
        if(is_array($syncInfo) && !empty($syncInfo['found']) && intval($syncInfo['expire_date'] ?? 0) > 0){
            $order['expire_date'] = intval($syncInfo['expire_date']);
        }
        $fid = $order['fileid']; 
    	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result();
        $stmt->close();
	    $rahgozar = $order['rahgozar'];
        $agentBought = $order['agent_bought'];

    	if($respd && $respd->num_rows > 0){
    	    $respd = $respd->fetch_assoc(); 
    	    
    	    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $respd['catid']);
            $stmt->execute();
            $cadquery = $stmt->get_result();
            $stmt->close();


    	    if($cadquery) {
    	        $catname = $cadquery->fetch_assoc()['title'];
        	    $name = $catname." ".$respd['title'];
    	    }else $name = "$id";
        	
    	}else $name = "$id";
    	
        $date = jdate("Y-m-d H:i",$order['date']);
        $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
        $remark = $order['remark'];
        $uuid = $order['uuid']??"0";
        $acc_link = json_decode($order['link']);
        $protocol = $order['protocol'];
        $token = $order['token'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $link_status = $order['expire_date'] > time()  ? $buttonValues['active'] : $buttonValues['deactive'];
        $price = $order['amount'];
        
    	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    	$stmt->bind_param('i', $server_id);
    	$stmt->execute();
    	$serverConfig = $stmt->get_result()->fetch_assoc();
    	$stmt->close();
    	$serverType = $serverConfig['type'];
        $panel_url = $serverConfig['panel_url'];
        
        $found = false;

        if($serverType == "marzban"){
            $info = getMarzbanUser($server_id, $remark);
            if(isset($info->username)){
                $found = true;
                $enable = $info->status =="active"?true:false;
                $total = $info->data_limit;
                $usedTraffic = $info->used_traffic;
                
                $leftgb = round( ($total - $usedTraffic) / 1073741824, 2) . " GB";
            } else $leftgb = "⚠️";
        }else{
            $response = getJson($server_id)->obj;
            if($response){
                if($inbound_id == 0) {
                    foreach($response as $row){
                        $clients = json_decode($row->settings)->clients;
                        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                            $found = true;
                            $total = $row->total;
                            $up = $row->up;
                            $down = $row->down; 
                            $enable = $row->enable;
                            $expiryTime = $row->expiryTime;
                            
                            $netType = json_decode($row->streamSettings)->network;
                            $security = json_decode($row->streamSettings)->security;
                            
                            $clientsStates = $row->clientStats;
                            
                            $inboundEmail = $clients[0]->email;
                            $allEmails = array_column($clientsStates,'email');
                            $clienEmailKey = array_search($inboundEmail,$allEmails);
    
                            $clientTotal = $clientsStates[$clienEmailKey]->total;
                            $clientUp = $clientsStates[$clienEmailKey]->up;
                            $clientDown = $clientsStates[$clienEmailKey]->down;
                            $clientExpiryTime = $clientsStates[$clienEmailKey]->expiryTime;
                                
                            if($clientTotal != 0 && $clientTotal != null && $clientExpiryTime != 0 && $clientExpiryTime != null){
                                $up += $clientUp;
                                $down += $clientDown;
                                $total = $clientTotal;
                            }
    
                            break;
                        }
                    }
                }else {
                    foreach($response as $row){
                        if($row->id == $inbound_id) {
                            $netType = json_decode($row->streamSettings)->network;
                            $security = json_decode($row->streamSettings)->security;
                            
                            $clientsStates = $row->clientStats;
                            $clients = json_decode($row->settings)->clients;
                            foreach($clients as $key => $client){
                                if($client->id == $uuid || $client->password == $uuid){
                                    $found = true;
                                    $email = $client->email;
                                    $emails = array_column($clientsStates,'email');
                                    $emailKey = array_search($email,$emails);
                                    
                                    $total = $clientsStates[$emailKey]->total;
                                    $up = $clientsStates[$emailKey]->up;
                                    $enable = $clientsStates[$emailKey]->enable;
                                    if(!$client->enable) $enable = false;
                                    $down = $clientsStates[$emailKey]->down; 
                                    break;
                                }
                            }
                        }
                    }
                }
                $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB";
            }else $leftgb = "⚠️";
        }
        $acc_link = wizwiz_normalizeConfigLinksArray($acc_link);
        $configLinks = "";
        
        $limit = 5;
        $count = 0;
        $pagedLinks = [];
        foreach($acc_link as $accLink){
            $count++;
            if($count <= $offset) continue;
            $pagedLinks[] = $accLink;
            if($count >= $offset + $limit) break;
        }
        if($botState['configLinkState'] != "off"){
            $configLinks = wizwiz_formatConfigLinksBlock($pagedLinks);
        }
        $keyboard = array();
        
        $configKeys = [];
        
        if(count($acc_link) > $limit){
            if($offset == 0){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"orderDetails{$id}_" . ($offset + $limit)]
                    ];
            }
            elseif(count($acc_link) >= $offset + $limit){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"orderDetails{$id}_" . ($offset + $limit)],
                    ['text'=>"»",'callback_data'=>"orderDetails{$id}_" . ($offset - $limit)]
                    ];
                
            }
            elseif($offset != 0){
                $configKeys = [
                    ['text'=>"»",'callback_data'=>"orderDetails{$id}_" . ($offset - $limit)]
                    ];
            }
        }
        
        array_push($keyboard,$configKeys, [
			    ['text' => $name, 'callback_data' => "wizwizch"],
                ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
            ],
            [
			    ['text' => $date, 'callback_data' => "wizwizch"],
                ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
            ],
            [
			    ['text' => $expire_date, 'callback_data' => "wizwizch"],
                ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
            ],
            [
			    ['text' => $leftgb, 'callback_data' => "wizwizch"],
                ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
			],
            ($serverType != "marzban"?
			[
                ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
            ]:[]));
        if($found){
            if($inbound_id == 0){
                if($protocol == 'trojan') {
                    if($security == "xtls"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        $temp = array();
                        if($price != 0 && $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }else{
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }
                }else {
                    if($netType == "grpc"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                    ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                    ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                                ]);
                        }
                        
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }
                    elseif($netType == "tcp" && $security == "xtls"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                    ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                    ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
    
                    }
                    else{
                        if($serverType != "marzban"){
                            array_push($keyboard,
                                ($rahgozar == true?
                                    [
                                        ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")]
                                    ]:
                                    [
                                        ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")]
                                    ]
                                )
                            );
                        }
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
    
                    }
                }
            }else{
                if($serverType != "marzban"){
                    array_push($keyboard, [
                            ['text' => " $protocol ☑️", 'callback_data' => "wizwizch"],
                        ]);
                }
                
                $temp = array();
                if($price != 0 || $agentBought == true){
                    if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                    if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                }
                if(count($temp)>0) array_push($keyboard, $temp);
    
            }
            $enable = $enable == true? $buttonValues['active']:$buttonValues['deactive'];
        }else $enable = $mainValues['config_doesnt_exist'];


        $stmt= $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $customerSubLink = wizwiz_makeCustomerSubLink($server_id, $token, $uuid, $inbound_id, $remark);
        $subLink = ($botState['subLinkState'] == "on" && $customerSubLink != "") ? "<code>" . $customerSubLink . "</code>" : "";

        $msg = str_replace(['STATE', 'NAME','CONNECT-LINK', 'SUB-LINK'], [$enable, $remark, $configLinks, $subLink], $mainValues['config_details_message']);
        
        
        if($found){
            $extrakey = [];
            if($botState['increaseVolumeState']=="on" && ($price != 0 || $agentBought == true)) $extrakey[] = ['text' => $buttonValues['increase_config_volume'], 'callback_data' => "increaseAVolume{$id}"];
            if($botState['increaseTimeState']=="on" && ($price != 0 || $agentBought == true)) $extrakey[] = ['text' => $buttonValues['increase_config_days'], 'callback_data' => "increaseADay{$id}"];
            $keyboard[] = $extrakey;
            
             
            if($botState['renewConfigLinkState'] == "on" && $botState['updateConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['renew_connection_link'],'callback_data'=>'changAccountConnectionLink' . $id],['text'=>$buttonValues['update_config_connection'],'callback_data'=>'updateConfigConnectionLink' . $id]];
            elseif($botState['renewConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['renew_connection_link'],'callback_data'=>'changAccountConnectionLink' . $id]];
            elseif($botState['updateConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['update_config_connection'],'callback_data'=>'updateConfigConnectionLink' . $id]];
            
            $temp = [];
            if($botState['qrConfigState'] == "on") $temp[] = ['text'=>$buttonValues['qr_config'],'callback_data'=>"showQrConfig" . $id];
            if($botState['qrSubState'] == "on") $temp[] = ['text'=>$buttonValues['qr_sub'],'callback_data'=>"showQrSub" . $id];
            array_push($keyboard, $temp);
            
        }
        $keyboard[] = [['text' => $buttonValues['delete_config'], 'callback_data' => "deleteMyConfig" . $id]];

        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => ($agentBought == true?"agentConfigsList":"mySubscriptions")]];
        return ["keyboard"=>wizwiz_inlineKeyboardJson($keyboard),
                "msg"=>$msg];
    }
}

function RandomString($count = 9, $type = "all") {
    if($type == "all") $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456789';
    elseif($type == "small") $characters = 'abcdef123456789';
    elseif($type == "domain") $characters = 'abcdefghijklmnopqrstuvwxyz';
    
    $randstring = null;
    for ($i = 0; $i < $count; $i++) {
        $randstring .= $characters[
            rand(0, strlen($characters)-1)
        ];
    }
    return $randstring;
}
function generateUID(){
    $randomString = openssl_random_pseudo_bytes(16);
    $time_low = bin2hex(substr($randomString, 0, 4));
    $time_mid = bin2hex(substr($randomString, 4, 2));
    $time_hi_and_version = bin2hex(substr($randomString, 6, 2));
    $clock_seq_hi_and_reserved = bin2hex(substr($randomString, 8, 2));
    $node = bin2hex(substr($randomString, 10, 6));

    $time_hi_and_version = hexdec($time_hi_and_version);
    $time_hi_and_version = $time_hi_and_version >> 4;
    $time_hi_and_version = $time_hi_and_version | 0x4000;

    $clock_seq_hi_and_reserved = hexdec($clock_seq_hi_and_reserved);
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

    return sprintf('%08s-%04s-%04x-%04x-%012s', $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
}
function checkStep($table){
    global $connection;
    
    if($table == "server_plans") $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
    if($table == "server_categories") $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active` = 0");
    
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['step']; 
}
function setUser($value = 'none', $field = 'step'){
    global $connection, $from_id, $username, $first_name, $admin, $botState;

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $uinfo = $stmt->get_result();
    $stmt->close();

    
    if($uinfo->num_rows == 0){
        $time = time();
        $approvalStatus = (wizwiz_getNewMemberAccessMode($botState) === 'approval' && $from_id != $admin) ? 'pending' : 'approved';
        $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `approval_status`, `approval_request_date`)
                            VALUES (?,?,?, 0,0,?,?,?)");
        $stmt->bind_param("issisi", $from_id, $first_name, $username, $time, $approvalStatus, $time);
        $stmt->execute();
        $stmt->close();
    }
    
    if($field == "wallet") $stmt = $connection->prepare("UPDATE `users` SET `wallet` = ? WHERE `userid` = ?");
    elseif($field == "phone") $stmt = $connection->prepare("UPDATE `users` SET `phone` = ? WHERE `userid` = ?");
    elseif($field == "refered_by") $stmt = $connection->prepare("UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?");
    elseif($field == "step") $stmt = $connection->prepare("UPDATE `users` SET `step` = ? WHERE `userid` = ?");
    elseif($field == "freetrial") $stmt = $connection->prepare("UPDATE `users` SET `freetrial` = ? WHERE `userid` = ?");
    elseif($field == "isAdmin") $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = ? WHERE `userid` = ?");
    elseif($field == "first_start") $stmt = $connection->prepare("UPDATE `users` SET `first_start` = ? WHERE `userid` = ?");
    elseif($field == "temp") $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
    elseif($field == "is_agent") $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = ? WHERE `userid` = ?");
    elseif($field == "discount_percent") $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    elseif($field == "agent_date") $stmt = $connection->prepare("UPDATE `users` SET `agent_date` = ? WHERE `userid` = ?");
    elseif($field == "spam_info") $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
    
    $stmt->bind_param("si", $value, $from_id);
    $stmt->execute();
    $stmt->close();
}
function generateRandomString($length, $protocol) {
    return ($protocol == 'trojan') ? substr(md5(time()),5,15) : generateUID();
}
function addBorderImage($add){
    $border = 30;
    $im = ImageCreateFromPNG($add);
    $width = ImageSx($im);
    $height = ImageSy($im);
    $img_adj_width = $width + 2 * $border;
    $img_adj_height = $height + 2 * $border;
    $newimage = imagecreatetruecolor($img_adj_width, $img_adj_height);
    $border_color = imagecolorallocate($newimage, 255, 255, 255);
    imagefilledrectangle($newimage, 0, 0, $img_adj_width, $img_adj_height, $border_color);
    imageCopyResized($newimage, $im, $border, $border, 0, 0, $width, $height, $width, $height);
    ImagePNG($newimage, $add, 5);
}
function sumerize($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    if($gb > 1){
      return round($gb,2) . " گیگابایت"; 
    }
    else{
        $gb *= 1024;
        return round($gb,2) . " مگابایت";
    }

}

function sumerize2($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    return round($gb,2);
}
function deleteClient($server_id, $inbound_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $old_data = []; $oldclientstat = [];
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings);
            $clients = $settings->clients;

            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client->id == $uuid || $client->password == $uuid){
                    $old_data = $client;
                    unset($clients[$key]);
                    $email = $client->email;
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings->clients = $clients;
    $settings = json_encode($settings);
	
    if($delete == 1){
        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);
        
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
        $session = $match[1];
        
        $loginResponse = json_decode($body,true);
        
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei_new"){
            $url = "$panel_url/panel/api/clients/del/" . rawurlencode($email);
            wizwiz_sanaeiNewJsonPost($curl, $url, $session, null);
            $response = curl_exec($curl);
            curl_close($curl);
            return json_decode($response);
        }
        if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
            if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/" . $inbound_id . "/delClient/" . rawurlencode($uuid);
            elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);
            elseif($serverType == "alireza") $url = "$panel_url/xui/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . $session
                )
            ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
        }else{
            curl_setopt_array($curl, array(
                CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,  
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . $session
                )
            ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
        }
        
        $response = curl_exec($curl);
        curl_close($curl);
    }	
    return ['id' => $old_data->id,'expiryTime' => $old_data->expiryTime, 'limitIp' => $old_data->limitIp, 'flow' => $old_data->flow, 'total' => $total, 'up' => $up, 'down' => $down,];

}
function editInboundRemark($server_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            break;
        }
    }


    $dataArr = array('up' => $up,'down' => $down,'total' => $total,'remark' => $newRemark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];
    
    $loginResponse = json_decode($body,true);
    if(!is_array($loginResponse) || empty($loginResponse['success'])){
        curl_close($curl);
        return is_array($loginResponse) ? $loginResponse : ['success'=>false, 'msg'=>'ورود به پنل ناموفق بود یا پاسخ پنل نامعتبر بود.'];
    }

    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$inbound_id";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInboundTraffic($server_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            
            $email = $clients[0]->email;

            break;
        }
    }
    if($days != 0) {
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
    }

    if($volume != 0){
        $leftGB = $total - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        if($editType == "renew"){
            $total = $extend_volume;
            $up = 0;
            $down = 0;
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        else $total = ($leftGB > 0) ? $total + $extend_volume : $extend_volume;
    }

    $dataArr = array('up' => $up,'down' => $down,'total' => is_null($total) ? $row->total : $total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => is_null($expire_microdate) ? $row->expiryTime : $expire_microdate, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!is_array($loginResponse) || empty($loginResponse['success'])){
        curl_close($curl);
        return is_array($loginResponse) ? $loginResponse : ['success'=>false, 'msg'=>'ورود به پنل ناموفق بود یا پاسخ پنل نامعتبر بود.'];
    }

    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$inbound_id";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);
}
function changeInboundState($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $settings = json_decode($row->settings, true);
        $clients = $settings['clients'];
        if($clients[0]['id'] == $uuid || $clients[0]['password'] == $uuid) {
            $inbound_id = $row->id;
            $enable = $row->enable;
            break;
        }
    }
    
    if(!isset($settings['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][0]['subId'] = RandomString(16);
    if(!isset($settings['clients'][0]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][0]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);

    $newEnable = $enable == true?false:true;
    
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => $newEnable,
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];


    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$inbound_id";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response);
    return $response;

}
function renewInboundUuid($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response || !isset($response->obj)) return null;
    $response = $response->obj;
    $client_key = -1;
    $foundClient = false;
    foreach($response as $row){
        $settings = json_decode($row->settings ?? '{}', true);
        $clients = $settings['clients'] ?? [];
        foreach($clients as $key => $client){
            $cid = $client['id'] ?? null;
            $pwd = $client['password'] ?? null;
            if(($cid !== null && $cid == $uuid) || ($pwd !== null && $pwd == $uuid)) {
                $client_key = $key;
                $inbound_id = $row->id;
                $total = $row->total;
                $up = $row->up;
                $down = $row->down;
                $expiryTime = $row->expiryTime;
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                $foundClient = true;
                break 2;
            }
        }
    }
    if(!$foundClient || $client_key < 0) return (object)['success'=>false, 'msg'=>'کانفیگ روی پنل پیدا نشد.'];
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][$client_key]['password'] = $newUuid;
    else $settings['clients'][$client_key]['id'] = $newUuid;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);


    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$inbound_id";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    $response = json_decode($response);
    if(!is_object($response)) $response = (object)['success'=>false, 'msg'=>'پاسخ پنل بعد از تغییر UUID نامعتبر بود.'];
    $response->newUuid = $newUuid;
    return $response;

}
function changeClientState($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $enable = $client['enable'];
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    $settings['clients'][$client_key]['enable'] = $enable == true?false:true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei_new"){
        $url = "$panel_url/panel/api/clients/update/" . rawurlencode($email);
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }
    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    curl_close($curl);
    return $response;

}
function renewClientUuid($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response || !isset($response->obj)) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $protocol = $row->protocol;
                    $client_key = $key;
                    $email = $client['email'];
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][$client_key]['password'] = $newUuid;
    else $settings['clients'][$client_key]['id'] = $newUuid;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!is_array($loginResponse) || empty($loginResponse['success'])){
        curl_close($curl);
        return is_array($loginResponse) ? $loginResponse : ['success'=>false, 'msg'=>'ورود به پنل ناموفق بود یا پاسخ پنل نامعتبر بود.'];
    }

    if($serverType == "sanaei_new"){
        $url = "$panel_url/panel/api/clients/update/" . rawurlencode($email);
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        if(!is_object($response)) $response = (object)['success'=>false, 'msg'=>'پاسخ پنل بعد از تغییر UUID نامعتبر بود.'];
        $response->newUuid = $newUuid;
        return $response;
    }
    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    if(!is_object($response)) $response = (object)['success'=>false, 'msg'=>'پاسخ پنل بعد از تغییر UUID نامعتبر بود.'];
    $response->newUuid = $newUuid;

    curl_close($curl);
    return $response;

}
function editClientRemark($server_id, $inbound_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings['clients'][$client_key]['email'] = $newRemark;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei_new"){
        $url = "$panel_url/panel/api/clients/update/" . rawurlencode($email);
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }
    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);

}
function editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    if($volume != 0){
        $client_total = $settings['clients'][$client_key]['totalGB'];// - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        $volume = ($client_total > 0) ? $client_total + $extend_volume : $extend_volume;
        if($editType == "renew"){
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        $settings['clients'][$client_key]['totalGB'] = $volume;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    
    if($days != 0){
        $expiryTime = $settings['clients'][$client_key]['expiryTime'];
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
        $settings['clients'][$client_key]['expiryTime'] = $expire_microdate;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei_new"){
        $url = "$panel_url/panel/api/clients/update/" . rawurlencode($email);
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }
    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);

}
function deleteInbound($server_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $protocol = $row->protocol;
            $uniqid = ($protocol == 'trojan') ? json_decode($row->settings)->clients[0]->password : json_decode($row->settings)->clients[0]->id;
            $netType = json_decode($row->streamSettings)->network;
            $oldData = [
                'total' => $row->total,
                'up' => $row->up,
                'down' => $row->down,
                'volume' => ((int)$row->total - (int)$row->up - (int)$row->down),
                'port' => $row->port,
                'protocol' => $protocol,
                'expiryTime' => $row->expiryTime,
                'uniqid' => $uniqid,
                'netType' => $netType,
                'security' => json_decode($row->streamSettings)->security,
            ];
            break;
        }
    }
    if($delete == 1){
        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);

        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
        $session = $match[1];

        $loginResponse = json_decode($body,true);
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/del/$inbound_id";
        elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/del/$inbound_id";
        else $url = "$panel_url/xui/inbound/del/$inbound_id";
       
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
        if($serverType == "sanaei_new"){
            wizwiz_sanaeiNewJsonPost($curl, $url, $session, null);
        }
        $response = curl_exec($curl);
        curl_close($curl);
    }
    return $oldData;
}
function resetIpLog($server_id, $remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei_new") $url = $panel_url. "/panel/api/clients/clearIps/" . rawurlencode($remark);
    elseif($serverType == "sanaei") $url = $panel_url. "/panel/inbound/clearClientIps/" . urlencode($remark);
    else $url = $panel_url. "/xui/inbound/clearClientIps/" . urlencode($remark);

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));

    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, null);
    }
    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function resetClientTraffic($server_id, $remark, $inboundId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/clients/resetTraffic/" . rawurlencode($remark);
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    elseif($inboundId == null) $url = "$panel_url/xui/inbound/resetClientTraffic/" . rawurlencode($remark);
    else $url = "$panel_url/xui/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, null);
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addInboundAccount($server_id, $client_id, $inbound_id, $expiryTime, $remark, $volume, $limitip = 1, $newarr = '', $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];
    $reality = $server_info['reality'];
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $iid = $row->id;
            $protocol = $row->protocol;
            break;
        }
    }
    if(!intval($iid)) return "inbound not Found";

    $settings = json_decode($row->settings, true);
    $id_label = $protocol == 'trojan' ? 'password' : 'id';
    if($newarr == ''){
		if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
		    if($reality == "true"){
                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                $stmt->bind_param("i", $planId);
                $stmt->execute();
                $file_detail = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            
                $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "flow" => $flow,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => RandomString(16)
                ];
		    }else{
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => RandomString(16)
                ];
		    }
    	}else{
            $newClient = [
                "$id_label" => $client_id,
                "flow" => "",
                "email" => $remark,
                "limitIp" => $limitip,
                "totalGB" => $volume,
                "expiryTime" => $expiryTime
            ];
		}
        $settings['clients'][] = $newClient;
    }elseif(is_array($newarr)) $settings['clients'][] = $newarr;

    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);

    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei_new"){
        $clientToAdd = ($newarr == '') ? $newClient : $newarr;
        $url = "$panel_url/panel/api/clients/add";
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, array("client" => $clientToAdd, "inboundIds" => array((int)$inbound_id)));
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }
    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
        $newSetting = array();
        if($newarr == '')$newSetting['clients'][] = $newClient;
        elseif(is_array($newarr)) $newSetting['clients'][] = $newarr;
        
        $newSetting = json_encode($newSetting);
        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/addClient";
        elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/addClient/";
        else $url = "$panel_url/xui/inbound/addClient/";

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$iid",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . $session
            )
        ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);

}
function getNewHeaders($netType, $request_header, $response_header, $type){
    global $connection;
    $input = explode(':', $request_header);
    $key = $input[0];
    $value = $input[1];

    $input = explode(':', $response_header);
    $reskey = $input[0];
    $resvalue = $input[1];

    $headers = '';
    if( $netType == 'tcp'){
        if($type == 'none') {
            $headers = '{
              "type": "none"
            }';
        }else {
            $headers = '{
              "type": "http",
              "request": {
                "method": "GET",
                "path": [
                  "/"
                ],
                "headers": {
                   "'.$key.'": [
                     "'.$value.'"
                  ]
                }
              },
              "response": {
                "version": "1.1",
                "status": "200",
                "reason": "OK",
                "headers": {
                   "'.$reskey.'": [
                     "'.$resvalue.'"
                  ]
                }
              }
            }';
        }

    }elseif( $netType == 'ws' || $netType == 'httpupgrade'){
        if($type == 'none') {
            $headers = '{}';
        }else {
            $headers = '{
              "'.$key.'": "'.$value.'"
            }';
        }
    }
    return $headers;

}
function getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id = 0, $rahgozar = false, $customPath = false, $customPort = 0, $customSni = null, $customDomain = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $server_ip = $server_info['ip'];
    $sni = $server_info['sni'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];
    if($serverType == 'sanaei_new' && $rahgozar == false && $customPath == false && intval($customPort) == 0 && $customSni === null && wizwiz_normalizePlanDomainInput($customDomain) === ''){
        $panelLinks = wizwiz_sanaeiNewClientLinksFromPanel($server_id, $remark, $uniqid, $inbound_id);
        if(!empty($panelLinks)) return $panelLinks;
    }
    preg_match("/^Host:(.*)/i",$request_header,$hostMatch);

    $panel_url = str_ireplace('http://','',$panel_url);
    $panel_url = str_ireplace('https://','',$panel_url);
    $panel_url = strtok($panel_url,":");
    if($server_ip == '') $server_ip = $panel_url;
    $planDomain = wizwiz_normalizePlanDomainInput($customDomain);
    if($planDomain !== '') $server_ip = $planDomain;

    $response = getJson($server_id)->obj;
    foreach($response as $row){
        if($inbound_id == 0){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uniqid || $clients[0]->password == $uniqid) {
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = json_decode($row->settings,true);
                    $email = $settings['clients'][0]['email'];
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $email;
                    $remark = $row->remark;
                }
                $tlsStatus = json_decode($row->streamSettings)->security;
                $tlsSetting = json_decode($row->streamSettings)->tlsSettings;
                $xtlsSetting = json_decode($row->streamSettings)->xtlsSettings;
                $netType = json_decode($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = json_decode($row->streamSettings)->tcpSettings->header->type;
                    $path = json_decode($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = json_decode($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                }
                if($netType == 'ws') {
                    $wsData = wizwiz_extractWsSettings($row->streamSettings, $server_ip);
                    $header_type = $wsData['header_type'];
                    $path = $wsData['path'];
                    $host = $wsData['host'];
                }
                if($netType == 'httpupgrade') {
                    $httpupgradeSettings = json_decode($row->streamSettings)->httpupgradeSettings;
                    $path = $httpupgradeSettings->path ?? '/';
                    $host = $httpupgradeSettings->host ?? '';
                    if(empty($host) && isset($httpupgradeSettings->headers->Host)) $host = $httpupgradeSettings->headers->Host;
                    $header_type = !empty($host) ? 'http' : 'none';
                }
                if($header_type == 'http' && empty($host)){
                    $request_header = explode(':', $request_header);
                    $host = $request_header[1];
                }
                if($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->certificates->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
						if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    } 
                    elseif($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $serviceName = json_decode($row->streamSettings)->grpcSettings->serviceName;
                    $grpcSecurity = json_decode($row->streamSettings)->security;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }
                if($netType == 'kcp'){
                    $kcpSettings = json_decode($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                
                break;
            }
        }else{
            if($row->id == $inbound_id) {
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = json_decode($row->settings);
                    $clients = $settings->clients;
                    foreach($clients as $key => $client){
                        if($client->id == $uniqid || $client->password == $uniqid){
                            $flow = $client->flow;
                            break;
                        }
                    }
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $remark;
                    $remark = $remark;
                }
                
                $port = $row->port;
                $tlsStatus = json_decode($row->streamSettings)->security;
                $tlsSetting = json_decode($row->streamSettings)->tlsSettings;
                $xtlsSetting = json_decode($row->streamSettings)->xtlsSettings;
                $netType = json_decode($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = json_decode($row->streamSettings)->tcpSettings->header->type;
                    $path = json_decode($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = json_decode($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                }elseif($netType == 'ws') {
                    $wsData = wizwiz_extractWsSettings($row->streamSettings, $server_ip);
                    $header_type = $wsData['header_type'];
                    $path = $wsData['path'];
                    $host = $wsData['host'];
                }elseif($netType == 'httpupgrade') {
                    $httpupgradeSettings = json_decode($row->streamSettings)->httpupgradeSettings;
                    $path = $httpupgradeSettings->path ?? '/';
                    $host = $httpupgradeSettings->host ?? '';
                    if(empty($host) && isset($httpupgradeSettings->headers->Host)) $host = $httpupgradeSettings->headers->Host;
                    $header_type = !empty($host) ? 'http' : 'none';
                }elseif($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                        if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    }
                    elseif($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $grpcSecurity = json_decode($row->streamSettings)->security;
                    $serviceName = json_decode($row->streamSettings)->grpcSettings->serviceName;
                }elseif($netType == 'kcp'){
                    $kcpSettings = json_decode($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }

                break;
            }
        }


    }
    $protocol = strtolower($protocol);
    $serverIp = explode("\n",$server_ip);
    $outputLink = array();
    foreach($serverIp as $server_ip){
        $server_ip = str_replace("\r","",($server_ip));
        if($inbound_id == 0) {
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                    }
                }
                $psting = '';
                if(($header_type == 'http' && $rahgozar != true && $netType != "grpc" && $netType != "httpupgrade")) $psting .= "&path=/&host=$host";
                if($netType == "ws" && $rahgozar != true) $psting .= "&encryption=none&path=" . rawurlencode($path ?: '/') . (!empty($host)?"&host=$host":"");
                if($netType == "httpupgrade" && $rahgozar != true){
                    $psting .= "&path=" . rawurlencode($path ?: '/');
                    if(!empty($host)) $psting .= "&host=$host";
                }
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1 && in_array($tlsStatus, ["tls", "xtls"], true)) $psting .= "&sni=$sni";
                if(strlen($serverName)>1 && $tlsStatus=="xtls") $server_ip = $serverName;
                if($tlsStatus == "xtls" && $netType == "tcp") $psting .= "&flow=xtls-rprx-direct";
                if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                if($rahgozar == true) $psting .= "&path=" . rawurlencode($path . ($customPath == true?"?ed=2048":"")) . "&encryption=none&host=$host";
                $outputlink = "$protocol://$uniqid@$server_ip:" . ($rahgozar == true?($customPort!="0"?$customPort:"443"):$port) . "?type=$netType&security=" . ($rahgozar==true?"tls":$tlsStatus) . "{$psting}#$remark";
                if($netType == 'grpc' && $tlsStatus != "reality"){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }
    
            if($protocol == 'trojan'){
                $psting = '';
                if($netType == 'ws') $psting .= "&path=" . rawurlencode($path ?: '/') . (!empty($host)?"&host=$host":"");
                if($header_type == 'http' && $netType != 'httpupgrade' && $netType != 'ws') $psting .= "&path=/&host=$host";
                if($netType == 'httpupgrade'){
                    $psting = "&path=" . rawurlencode($path ?: '/');
                    if(!empty($host)) $psting .= "&host=$host";
                }
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$port,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> $kcpType ? $kcpType : "none",
                    "host"=> ($rahgozar == true && empty($host))? $server_ip:(is_null($host) ? '' : $host),
                    "path"=> ($rahgozar == true)?($path . ($customPath == true?"?ed=2048":"")):((is_null($path) and $path != '') ? '/' : (is_null($path) ? '' : $path)),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
    
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($header_type == 'http' && $rahgozar != true){
                    $vmessArr['path'] = "/";
                    $vmessArr['type'] = $header_type;
                    $vmessArr['host'] = $host;
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
    				$vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }else { 
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." .$host;
                        }
                    }
                }
                
                if(strlen($sni) > 1 && in_array($tlsStatus, ["tls", "xtls"], true)) $psting = "&sni=$sni"; else $psting = '';
                if($netType == 'tcp'){
                    if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                    if($tlsStatus=="xtls") $psting .= "&flow=xtls-rprx-direct";
                    if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                    if($header_type == "http") $psting .= "&path=/&host=$host";
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                }elseif($netType == 'ws'){
                    if($rahgozar == true)$outputlink = "$protocol://$uniqid@$server_ip:" . ($customPort!=0?$customPort:"443") . "?type=$netType&security=tls&path=" . rawurlencode($path . ($customPath == true?"?ed=2048":"")) . "&encryption=none&host=$host{$psting}#$remark";
                    else $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&encryption=none&path=" . rawurlencode($path ?: '/') . (!empty($host)?"&host=$host":"") . "{$psting}#$remark";
                }elseif($netType == 'httpupgrade'){
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&path=" . rawurlencode($path ?: '/') . (!empty($host)?"&host=$host":"") . "{$psting}#$remark";
                }
                elseif($netType == 'kcp')
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&headerType=$kcpType&seed=$kcpSeed#$remark";
                elseif($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }
                    elseif($tlsStatus=="reality"){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX#$remark";
                    }
                    else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
                }
            }elseif($protocol == 'trojan'){                
                $psting = '';
                if($netType == 'ws') $psting .= "&path=" . rawurlencode($path ?: '/') . (!empty($host)?"&host=$host":"");
                if($header_type == 'http' && $netType != 'httpupgrade' && $netType != 'ws') $psting .= "&path=/&host=$host";
                if($netType == 'httpupgrade'){
                    $psting = "&path=" . rawurlencode($path ?: '/');
                    if(!empty($host)) $psting .= "&host=$host";
                }
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$port,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> ($netType == 'httpupgrade') ? "none" : (($header_type) ? $header_type : ($kcpType ? $kcpType : "none")),
                    "host"=> ($rahgozar == true && empty($host))?$server_ip:(is_null($host) ? '' : $host),
                    "path"=> ($rahgozar == true)?($path . ($customPath == true?"?ed=2048":"")) :((is_null($path) and $path != '') ? '/' : (is_null($path) ? '' : $path)),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $subDomain = RandomString(4, "domain");
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." .$explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                        
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
                    $vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
    
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }
        $outputLink[] = $outputlink;
    }

    return $outputLink;
}
function updateConfig($server_id, $inboundId, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings,488|JSON_UNESCAPED_UNICODE);
    }
    
    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        if($row->id == $inboundId) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        
        
                $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }
			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                }
            }
            $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
        }

        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$iid";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInbound($server_id, $uniqid, $uuid, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;

    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

    	if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}else{
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

                $settings = '{
              "clients": [
                {
                  "id": "'.$uniqid.'",
    			  "flow": "xtls-rprx-direct".
    			  "email": "' . $remark. '"
                }
              ],
              "decryption": "none",
        	  "fallbacks": []
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
              "enable": true,
			  "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . RandomString(16) . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }
        
        
                $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$client_id.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
        			  "flow": "",
        			  "email": "' . $remark. '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
            		  "flow": "",
            		  "email": "' . $remark. '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }
                }
            }
            $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
        }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }



    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/update/$iid";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function getMarzbanToken($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $panel_url = $server_info['panel_url'];
    $username = $server_info['username'];
    $password = $server_info['password'];
    
    $loginUrl = $panel_url .'/api/admin/token';
    $postFields = array(
        'username' => $username,
        'password' => $password
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
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return (object) ['success'=>false, 'detail'=>curl_error($curl)];
    }
    curl_close($curl);

    return json_decode($response);
}
function getMarzbanJson($server_id, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/users';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    return $response;
}
function getMarzbanUserInfo($server_id, $remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $configInfo = array();
    $curl = curl_init();
    for($i = 0; $i <= 10; $i++){
        $info = getMarzbanUser($server_id, $remark);
		$subLink = "/sub/" . (explode("/sub/", $info->subscription_url)[1]);
		$info->subscription_url = $subLink;
        curl_setopt($curl, CURLOPT_URL, $panel_url . $info->subscription_url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        $response = curl_exec($curl);
        if($response && !curl_error($curl)){
            $configInfo = $info;
            break;
        }
		if($i == 10) $configInfo = $info;
    }
    curl_close($curl);

    return (object) $configInfo;
}
function getMarzbanUser($server_id, $remark, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    
    $panel_url .= '/api/user/' . urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function getMarzbanHosts($server_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .= '/api/core/config';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $planInfo = json_decode($stmt->get_result()->fetch_assoc()['custom_sni'],true);
    $stmt->close();

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $postFields = array(
        "inbounds" => $planInfo['inbounds'],
        "proxies" => $planInfo['proxies'],
        "expire" => time() + (86400 * $days),
        "data_limit" => $volume * 1073741824,
        "username" => urlencode($remark)
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url . "/api/user");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail) || !isset($response->links)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $userInfo = getMarzbanUserInfo($server_id, $remark);

    return (object) [
        'success'=>true,
        'sub_link'=> $userInfo->subscription_url,
        'vray_links' => $response->links
        ];
}
function editMarzbanConfig($server_id,$info){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->error];}

    $remark = $info['remark'];
    $configInfo = getMarzbanUser($server_id, $remark, $token);
    
    
    $expireTime = $configInfo->expire;
    $volume = $configInfo->data_limit;
    $configState = $configInfo->status;

    if(isset($info['plus_day'])) $expireTime += (86400 * $info['plus_day']);
    elseif(isset($info['days'])){
        $expireTime = time() + (86400 * $info['days']);
        $configState = "active";
    }
    
    if(isset($info['plus_volume'])) $volume += $info['plus_volume'] * 1073741824;
    elseif(isset($info['volume'])){
        $volume = $info['volume'] * 1073741824;
        $response = resetMarzbanTraffic($server_id, $remark, $token);
        
        if(!$response->success) return $response;
    }
    
    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $expireTime,
        "data_limit" => $volume,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => $configState
    );
    
    $panel_url .=  '/api/user/'. $remark;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
        ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function resetMarzbanTraffic($server_id, $remark, $token){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .=  '/api/user/' . $remark .'/reset';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function renewMarzbanUUID($server_id,$remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/user/' . $remark .'/revoke_sub';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $response = getMarzbanUserInfo($server_id, $remark);
    return $response;
}

function deleteMarzban($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .=  '/api/user/'. urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    
    return (object) ['success'=>true];
}
function changeMarzbanState($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $configInfo = getMarzbanUser($server_id, $remark, $token);

    $panel_url .=  '/api/user/'. $remark;

    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $configInfo->expire,
        "data_limit" => $configInfo->data_limit,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => $configInfo->status == "active"?"disabled":"active"
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function getJson($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];

    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/list";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/list";
    else $url = "$panel_url/xui/inbound/list";
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => ($serverType == "sanaei_new" ? 'GET' : 'POST'),
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        ),
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    $decoded = json_decode($response);
    return wizwiz_normalizeSanaeiNewResponse($decoded, $serverType);
}
function getNewCert($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $cookie = 'Cookie: session='.$server_info['cookie'];

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => ($serverType == "sanaei_new" ? "$panel_url/panel/api/server/getNewX25519Cert" : "$panel_url/server/getNewX25519Cert"),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => ($serverType == "sanaei_new" ? 'GET' : 'POST'),
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addUser($server_id, $client_id, $protocol, $port, $expiryTime, $remark, $volume, $netType, $security = 'none', $rahgozar = false, $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $sni = $server_info['sni'];
    $cookie = 'Cookie: session='.$server_info['cookie'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $reality = $server_info['reality'];

    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }
    
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
//---------------------------------------Trojan------------------------------------//
    if($protocol == 'trojan'){
        // protocol trojan
        if($security == 'none'){
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'", 
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            
        	if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
                    $tcpSettings = '{
                	  "network": "tcp",
                	  "security": "'.$security.'",
                	  "' . $xtlsTitle . '": '.$tlsSettings.',
                	  "tcpSettings": {
                        "header": '.$headers.'
                      }
                	}';

                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle .'": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
        
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
              "enable": true,
              "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . RandomString(16) . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }



        $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }




        // trojan
        $dataArr = array('up' => '0','down' => '0','total' => $volume,'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => '{
      "enabled": true,
      "destOverride": [
        "http",
        "tls"
      ]
    }');
    }else {
//-------------------------------------- vmess vless -------------------------------//
        if($rahgozar == true){
            $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/wss' . $port . '",
                    "headers": {}
                  }
                }';
            if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
        }else{
            if($security == 'tls') {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else{
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }
            }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "sanaei_new" && $serverType != "alireza") {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "none",
        	  "tcpSettings": {
        		"header": '.$headers.'
        	  }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "none",
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
        }
        
        
		if($protocol == 'vless'){
		    if($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza"){
		        if($reality == "true"){
	                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                    $stmt->bind_param("i", $planId);
                    $stmt->execute();
                    $file_detail = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                
                    $dest = !empty($file_detail['dest'])?$file_detail['dest']:"yahoo.com";
                    $serverNames = !empty($file_detail['serverNames'])?$file_detail['serverNames']:
                                '[
                                    "yahoo.com",
                                    "www.yahoo.com"
                                ]';
                    $spiderX = !empty($file_detail['spiderX'])?$file_detail['spiderX']:"";
                    $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                    


		            $certInfo = getNewCert($server_id)->obj;
		            $publicKey = $certInfo->publicKey;
		            $privateKey = $certInfo->privateKey;
		            $shortId = RandomString(8, "small");
		            $serverName = json_decode($tlsSettings,true)['serverName'];
		            if($netType == "grpc"){
    		            $tcpSettings = '{
                          "network": "grpc",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "grpcSettings": {
                            "serviceName": "",
                    		"multiMode": false
                          }
                        }';
		            }else{
    		            $tcpSettings = '{
                          "network": "tcp",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "tcpSettings": {
                            "acceptProxyProtocol": false,
                    		"header": '.$headers.'
                          }
                        }';
		            }
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "flow": "' . $flow .'",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . RandomString(16) . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		            $netType = "tcp";
		        }else{
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . RandomString(16) . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		        }
		    }else{
			$settings = '{
			  "clients": [
				{
				  "id": "'.$client_id.'",
				  "flow": "",
				  "email": "' . $remark. '"
				}
			  ],
			  "decryption": "none",
			  "fallbacks": []
			}';
		    }
		}

        $streamSettings = wizwiz_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
		if($netType == 'grpc' && $reality != "true"){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' . parse_url($panel_url, PHP_URL_HOST) . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }

        if(($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza") && $reality == "true"){
            $sniffing = '{
              "enabled": true,
              "destOverride": [
                "http",
                "tls",
                "quic"
              ]
            }';
        }else{
            $sniffing = '{
        	  "enabled": true,
        	  "destOverride": [
        		"http",
        		"tls"
        	  ]
        	}';
        }
        // vmess - vless
        $dataArr = array('up' => '0','down' => '0','total' => $volume, 'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings
        ,'sniffing' => $sniffing);
    }
    
    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, wizwiz_panelLoginHeaders($curl, $loginUrl));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $match);
    $session = $match[1];

    $loginResponse = json_decode($body,true);

    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei_new") $url = "$panel_url/panel/api/inbounds/add";
    elseif($serverType == "sanaei") $url = "$panel_url/panel/inbound/add";
    else $url = "$panel_url/xui/inbound/add";
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15, 
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false, 
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . $session
        )
    ));
    if($serverType == "sanaei_new"){
        wizwiz_sanaeiNewJsonPost($curl, $url, $session, wizwiz_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}


// ===== WizWiz extra realtime reports + auto order approval =====
function wizwiz_ensureAutoOrderColumns(){
    global $connection;
    if(function_exists('wizwiz_schemaPatchDone') && wizwiz_schemaPatchDone('AUTO_ORDER_REPORTS_V4')) return;

    $payColumns = [
        'sent_date' => "ALTER TABLE `pays` ADD `sent_date` int(255) NOT NULL DEFAULT 0 AFTER `request_date`",
        'auto_approved' => "ALTER TABLE `pays` ADD `auto_approved` tinyint(1) NOT NULL DEFAULT 0 AFTER `state`",
        'auto_approved_date' => "ALTER TABLE `pays` ADD `auto_approved_date` int(255) NOT NULL DEFAULT 0 AFTER `auto_approved`",
        'auto_approved_orders' => "ALTER TABLE `pays` ADD `auto_approved_orders` text DEFAULT NULL AFTER `auto_approved_date`",
        'cancel_reason' => "ALTER TABLE `pays` ADD `cancel_reason` text DEFAULT NULL AFTER `auto_approved_orders`",
        'admin_chat_id' => "ALTER TABLE `pays` ADD `admin_chat_id` bigint(30) NOT NULL DEFAULT 0 AFTER `cancel_reason`",
        'admin_message_id' => "ALTER TABLE `pays` ADD `admin_message_id` int(20) NOT NULL DEFAULT 0 AFTER `admin_chat_id`",
        'approval_error' => "ALTER TABLE `pays` ADD `approval_error` text DEFAULT NULL AFTER `admin_message_id`",
        'approval_error_date' => "ALTER TABLE `pays` ADD `approval_error_date` int(255) NOT NULL DEFAULT 0 AFTER `approval_error`",
        'receipt_file_id' => "ALTER TABLE `pays` ADD `receipt_file_id` varchar(255) DEFAULT NULL AFTER `approval_error_date`"
    ];
    foreach($payColumns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `pays` LIKE '$column'"));
        if($exists && $exists->num_rows == 0) @($connection->query($query));
    }

    $orderColumns = [
        'auto_approved' => "ALTER TABLE `orders_list` ADD `auto_approved` tinyint(1) NOT NULL DEFAULT 0 AFTER `agent_bought`",
        'auto_pay_hash' => "ALTER TABLE `orders_list` ADD `auto_pay_hash` varchar(120) DEFAULT NULL AFTER `auto_approved`",
        'cancel_reason' => "ALTER TABLE `orders_list` ADD `cancel_reason` text DEFAULT NULL AFTER `auto_pay_hash`"
    ];
    foreach($orderColumns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `orders_list` LIKE '$column'"));
        if($exists && $exists->num_rows == 0) @($connection->query($query));
    }

    if(function_exists('wizwiz_markSchemaPatchDone')) wizwiz_markSchemaPatchDone('AUTO_ORDER_REPORTS_V4');
}
wizwiz_ensureAutoOrderColumns();

function wizwiz_ensureAdminReceiptColumns(){
    global $connection;
    if(function_exists('wizwiz_schemaPatchDone') && wizwiz_schemaPatchDone('ADMIN_RECEIPT_SETTINGS_V1')) return;

    $exists = @($connection->query("SHOW COLUMNS FROM `users` LIKE 'receive_order_receipts'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `users` ADD `receive_order_receipts` tinyint(1) NOT NULL DEFAULT 0 AFTER `isAdmin`"));
    }

    if(function_exists('wizwiz_markSchemaPatchDone')) wizwiz_markSchemaPatchDone('ADMIN_RECEIPT_SETTINGS_V1');
}
wizwiz_ensureAdminReceiptColumns();

function wizwiz_h($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wizwiz_plainTextForTelegram($text){
    $text = (string)$text;
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

function wizwiz_translateTechnicalError($text){
    $raw = trim((string)$text);
    if($raw === '') return 'خطای نامشخص';
    $lower = strtolower($raw);
    $fa = '';
    if(strpos($lower, 'can\'t parse entities') !== false || strpos($lower, 'parse entities') !== false || strpos($lower, 'unsupported start tag') !== false){
        $fa = 'خطای قالب‌بندی متن پیام بود. ربات تلاش می‌کند همان پیام را به صورت متن ساده ارسال کند.';
    }elseif(strpos($lower, 'caption is too long') !== false){
        $fa = 'متن کپشن رسید طولانی‌تر از حد مجاز تلگرام بود.';
    }elseif(strpos($lower, 'message is too long') !== false){
        $fa = 'متن پیام طولانی‌تر از حد مجاز تلگرام بود.';
    }elseif(strpos($lower, 'bot was blocked') !== false || strpos($lower, 'forbidden') !== false){
        $fa = 'ربات توسط گیرنده بلاک شده یا اجازه ارسال پیام ندارد.';
    }elseif(strpos($lower, 'chat not found') !== false || strpos($lower, 'user not found') !== false){
        $fa = 'چت یا کاربر مقصد پیدا نشد.';
    }elseif(strpos($lower, 'wrong file identifier') !== false || strpos($lower, 'file_id') !== false || strpos($lower, 'file identifier') !== false){
        $fa = 'شناسه فایل رسید نامعتبر بود یا تلگرام به فایل دسترسی نداشت.';
    }elseif(strpos($lower, 'file is too big') !== false || strpos($lower, 'request entity too large') !== false){
        $fa = 'حجم فایل ارسالی بیشتر از حد مجاز تلگرام بود.';
    }elseif(strpos($lower, 'too many requests') !== false || strpos($lower, 'retry after') !== false){
        $fa = 'محدودیت موقت تلگرام فعال شده است؛ چند لحظه بعد دوباره قابل ارسال است.';
    }elseif(strpos($lower, 'reply markup') !== false || strpos($lower, 'button') !== false || strpos($lower, 'style') !== false){
        $fa = 'ساختار دکمه‌های زیر پیام با نسخه Bot API سازگار نبود.';
    }elseif(strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false || strpos($lower, 'failed to connect') !== false || strpos($lower, 'could not resolve') !== false){
        $fa = 'ارتباط سرور با تلگرام برقرار نشد یا زمان پاسخ‌گویی تمام شد.';
    }elseif(strpos($lower, 'user already exists') !== false){
        $fa = 'کاربر با این نام/ریمارک از قبل روی پنل وجود دارد.';
    }elseif(strpos($lower, 'duplicate email') !== false){
        $fa = 'ریمارک/ایمیل تکراری است و پنل اجازه ساخت کانفیگ جدید نمی‌دهد.';
    }elseif(strpos($lower, 'port already exists') !== false){
        $fa = 'پورت انتخاب‌شده روی پنل تکراری است.';
    }elseif(strpos($lower, 'inbound not found') !== false){
        $fa = 'این inbound روی سرور پیدا نشد یا حذف شده است.';
    }elseif(strpos($lower, 'connection') !== false || strpos($lower, 'curl') !== false){
        $fa = 'خطای اتصال رخ داد.';
    }

    if($fa === ''){
        if(preg_match('/[آ-ی]/u', $raw)) return $raw;
        return 'خطای نامشخص: ' . $raw;
    }
    return $fa . "\nجزئیات فنی: " . $raw;
}

function wizwiz_userPrivateUrl($userId){
    return 'tg://user?id=' . intval($userId);
}

function wizwiz_userPrivateButton($userId, $text = '👤 رفتن به پی وی مشتری'){
    return ['text' => $text, 'url' => wizwiz_userPrivateUrl($userId), 'style' => 'primary'];
}

function wizwiz_formatUserLine($userId, $name = '', $username = ''){
    $userId = intval($userId);
    $name = trim((string)$name) !== '' ? trim((string)$name) : ('کاربر ' . $userId);
    $username = trim((string)$username);
    $username = ($username !== '' && $username !== 'ندارد' && $username !== ' ندارد ') ? '@' . ltrim($username, '@') : 'ندارد';
    return "👤 کاربر: <a href='tg://user?id={$userId}'>" . wizwiz_h($name) . "</a>\n🆔 آیدی عددی: <code>{$userId}</code>\n🔸 یوزرنیم: " . wizwiz_h($username);
}

function wizwiz_getIncomeReportChatId(){
    global $botState, $admin;
    $chat = trim((string)($botState['rewardChannel'] ?? ''));
    return $chat !== '' ? $chat : $admin;
}

function wizwiz_reportEventItems(){
    return [
        'purchase_started' => '🛒 شروع خرید',
        'test_account' => '🧪 دریافت اکانت تست',
        'auto_approved' => '🤖 تأیید خودکار سفارش',
        'approval_failed' => '⚠️ خطای تأیید خودکار',
        'daily_stats' => '📊 آمار روزانه'
    ];
}

function wizwiz_reportStatItems(){
    return [
        'users_total' => '👥 کل کاربران',
        'users_today' => '👤 کاربران جدید امروز',
        'users_month' => '👥 کاربران جدید ماه',
        'agents_total' => '🤝 تعداد نماینده‌ها',
        'active_services' => '🧾 سرویس‌های فعال',
        'expired_services' => '⌛ سرویس‌های منقضی فعال',
        'total_orders' => '📦 کل سفارش‌ها',
        'today_orders' => '🛒 سفارش‌های امروز',
        'month_orders' => '📆 سفارش‌های ماه',
        'pending_pays' => '⏳ پرداخت‌های در انتظار',
        'approved_pays_today' => '✅ پرداخت‌های تأیید امروز',
        'declined_pays_today' => '❌ پرداخت‌های رد امروز',
        'today_income' => '💰 درآمد امروز',
        'yesterday_income' => '💵 درآمد دیروز',
        'week_income' => '🗓 درآمد هفته',
        'month_income' => '📆 درآمد ماه',
        'total_income' => '🏦 درآمد کل',
        'auto_approved_today' => '🤖 تأیید خودکار امروز',
        'auto_approved_total' => '🤖 کل تأیید خودکار',
        'test_accounts_today' => '🧪 تست‌های امروز',
        'test_accounts_total' => '🧪 کل تست‌ها',
        'wallet_total' => '👛 مجموع کیف پول کاربران',
        'servers_total' => '🖥 تعداد سرورها',
        'plans_total' => '📋 تعداد پلن‌ها'
    ];
}

function wizwiz_reportDetailItems(){
    return [
        'user_info' => '👤 اطلاعات کاربر داخل اعلان‌ها',
        'private_button' => '🔗 دکمه رفتن به پی‌وی مشتری',
        'payment_hash' => '🔖 کد پرداخت',
        'plan_info' => '📦 مشخصات پلن/سرویس',
        'amount' => '💰 مبلغ خرید',
        'order_ids' => '🧾 شماره سفارش/کانفیگ ساخته‌شده',
        'cancel_button' => '❌ دکمه لغو سفارش خودکار',
        'timestamp' => '🕒 زمان گزارش'
    ];
}

function wizwiz_reportSetting($key, $default = 'on'){
    global $botState;
    $value = $botState[$key] ?? $default;
    return ((string)$value === 'on') ? 'on' : 'off';
}

function wizwiz_reportIsEnabled($key, $default = 'on'){
    return wizwiz_reportSetting($key, $default) === 'on';
}

function wizwiz_reportTime(){
    global $botState;
    $time = trim((string)($botState['wizReportDailyTime'] ?? '21:00'));
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '21:00';
    return $time;
}

function wizwiz_reportToggleSetting($key, $default = 'on'){
    $new = wizwiz_reportIsEnabled($key, $default) ? 'off' : 'on';
    setSettings($key, $new);
    return $new;
}

function wizwiz_reportStatKey($item){
    return 'wizReportStat_' . $item;
}

function wizwiz_reportEventKey($item){
    return 'wizReportEvent_' . $item;
}

function wizwiz_reportDetailKey($item){
    return 'wizReportDetail_' . $item;
}

function wizwiz_reportDetailEnabled($item, $default = 'on'){
    return wizwiz_reportIsEnabled(wizwiz_reportDetailKey($item), $default);
}

function wizwiz_reportTimeLine(){
    if(!wizwiz_reportDetailEnabled('timestamp', 'on')) return '';
    $nowTxt = function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i');
    return "\n🕒 زمان: <b>" . wizwiz_h($nowTxt) . "</b>";
}

function wizwiz_liveStatsSnapshot($forDaily = false){
    global $connection;
    $now = time();
    $today = strtotime(date('Y-m-d 00:00:00'));
    $yesterday = strtotime(date('Y-m-d 00:00:00', strtotime('-1 day')));
    $week = strtotime(date('Y-m-d 00:00:00', strtotime('-6 day')));
    $month = strtotime(date('Y-m-01 00:00:00'));
    $q = function($sql) use ($connection){
        $res = @($connection->query($sql));
        if(!$res) return 0;
        $row = $res->fetch_assoc();
        return intval($row['c'] ?? $row['s'] ?? 0);
    };

    $values = [
        'users_total' => ['👥 کاربران', $q("SELECT COUNT(*) c FROM `users`"), ''],
        'users_today' => ['👤 کاربران جدید امروز', $q("SELECT COUNT(*) c FROM `users` WHERE CAST(`date` AS UNSIGNED) >= $today"), ''],
        'users_month' => ['👥 کاربران جدید ماه', $q("SELECT COUNT(*) c FROM `users` WHERE CAST(`date` AS UNSIGNED) >= $month"), ''],
        'agents_total' => ['🤝 نماینده‌ها', $q("SELECT COUNT(*) c FROM `users` WHERE COALESCE(`is_agent`,0) = 1"), ''],
        'active_services' => ['🧾 سرویس‌های فعال', $q("SELECT COUNT(*) c FROM `orders_list` WHERE `status` = 1"), ''],
        'expired_services' => ['⌛ سرویس‌های منقضی فعال', $q("SELECT COUNT(*) c FROM `orders_list` WHERE `status` = 1 AND CAST(`expire_date` AS UNSIGNED) > 0 AND CAST(`expire_date` AS UNSIGNED) < $now"), ''],
        'total_orders' => ['📦 کل سفارش‌ها', $q("SELECT COUNT(*) c FROM `orders_list`"), ''],
        'today_orders' => ['🛒 سفارش امروز', $q("SELECT COUNT(*) c FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $today"), ''],
        'month_orders' => ['📆 سفارش ماه', $q("SELECT COUNT(*) c FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $month"), ''],
        'pending_pays' => ['⏳ پرداخت‌های در انتظار', $q("SELECT COUNT(*) c FROM `pays` WHERE `state` IN ('pending','sent','processing','auto_processing')"), ''],
        'approved_pays_today' => ['✅ پرداخت‌های تأیید امروز', $q("SELECT COUNT(*) c FROM `pays` WHERE `state` = 'approved' AND CAST(COALESCE(NULLIF(`auto_approved_date`,0), `request_date`) AS UNSIGNED) >= $today"), ''],
        'declined_pays_today' => ['❌ پرداخت‌های رد امروز', $q("SELECT COUNT(*) c FROM `pays` WHERE `state` IN ('declined','auto_cancelled') AND CAST(`request_date` AS UNSIGNED) >= $today"), ''],
        'today_income' => ['💰 درآمد امروز', $q("SELECT COALESCE(SUM(`amount`),0) s FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $today"), ' تومان'],
        'yesterday_income' => ['💵 درآمد دیروز', $q("SELECT COALESCE(SUM(`amount`),0) s FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $yesterday AND CAST(`date` AS UNSIGNED) < $today"), ' تومان'],
        'week_income' => ['🗓 درآمد هفته', $q("SELECT COALESCE(SUM(`amount`),0) s FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $week"), ' تومان'],
        'month_income' => ['📆 درآمد ماه', $q("SELECT COALESCE(SUM(`amount`),0) s FROM `orders_list` WHERE CAST(`date` AS UNSIGNED) >= $month"), ' تومان'],
        'total_income' => ['🏦 درآمد کل', $q("SELECT COALESCE(SUM(`amount`),0) s FROM `orders_list`"), ' تومان'],
        'auto_approved_today' => ['🤖 تأیید خودکار امروز', $q("SELECT COUNT(*) c FROM `pays` WHERE `auto_approved` = 1 AND CAST(`auto_approved_date` AS UNSIGNED) >= $today"), ''],
        'auto_approved_total' => ['🤖 کل تأیید خودکار', $q("SELECT COUNT(*) c FROM `pays` WHERE `auto_approved` = 1"), ''],
        'test_accounts_today' => ['🧪 تست‌های امروز', $q("SELECT COUNT(*) c FROM `orders_list` WHERE `status` = 1 AND CAST(`amount` AS UNSIGNED) = 0 AND CAST(`date` AS UNSIGNED) >= $today"), ''],
        'test_accounts_total' => ['🧪 کل تست‌ها', $q("SELECT COUNT(*) c FROM `orders_list` WHERE CAST(`amount` AS UNSIGNED) = 0"), ''],
        'wallet_total' => ['👛 مجموع کیف پول', $q("SELECT COALESCE(SUM(`wallet`),0) s FROM `users`"), ' تومان'],
        'servers_total' => ['🖥 سرورها', $q("SELECT COUNT(*) c FROM `server_config`"), ''],
        'plans_total' => ['📋 پلن‌ها', $q("SELECT COUNT(*) c FROM `server_plans`"), '']
    ];

    $lines = [];
    foreach($values as $key => $item){
        if(!wizwiz_reportIsEnabled(wizwiz_reportStatKey($key), 'on')) continue;
        [$label, $value, $suffix] = $item;
        $lines[] = $label . ': <b>' . number_format($value) . $suffix . '</b>';
    }
    if(count($lines) == 0) return '';
    $title = $forDaily ? "📊 <b>آمار روزانه</b>" : "📊 <b>آمار لحظه‌ای</b>";
    return "\n\n" . $title . "\n" . implode("\n", $lines);
}

function wizwiz_reportEvent($title, $body, $keyboard = null, $eventKey = null){
    if($eventKey !== null && !wizwiz_reportIsEnabled(wizwiz_reportEventKey($eventKey), 'on')) return null;
    $chat = wizwiz_getIncomeReportChatId();
    if($chat === null || $chat === '') return null;
    // اعلان‌ها جدا از آمار هستند؛ آمار فقط در گزارش روزانه/ارسال دستی ارسال می‌شود.
    $msg = $title . "\n\n" . $body;
    return sendMessage($msg, $keyboard, 'HTML', $chat);
}

function wizwiz_buildDailyChannelStatsText($manual = false){
    $nowTxt = function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i');
    $title = $manual ? '📊 <b>ارسال دستی آمار کانال</b>' : '📊 <b>گزارش روزانه آمار ربات</b>';
    $stats = wizwiz_liveStatsSnapshot(true);
    if(trim($stats) === '') $stats = "\n\nهیچ آیتم آماری برای ارسال فعال نیست.";
    return $title . "\n\n🕒 زمان گزارش: <b>" . wizwiz_h($nowTxt) . "</b>" . $stats;
}

function wizwiz_sendDailyChannelStats($manual = false){
    if(!wizwiz_reportIsEnabled(wizwiz_reportEventKey('daily_stats'), 'on') && !$manual) return false;
    $chat = wizwiz_getIncomeReportChatId();
    if($chat === null || $chat === '') return false;
    sendMessage(wizwiz_buildDailyChannelStatsText($manual), null, 'HTML', $chat);
    return true;
}

function wizwiz_processDailyChannelStats($force = false){
    if(!$force && !wizwiz_reportIsEnabled('wizReportDailyState', 'off')) return false;
    $today = date('Y-m-d');
    $time = wizwiz_reportTime();
    global $botState;
    $last = (string)($botState['wizReportLastDailyDate'] ?? '');
    if(!$force){
        if($last === $today) return false;
        if(date('H:i') < $time) return false;
    }
    $sent = wizwiz_sendDailyChannelStats($force);
    if($sent && !$force) setSettings('wizReportLastDailyDate', $today);
    return $sent;
}

function wizwiz_getReportSettingsMenuText(){
    $dailyState = wizwiz_reportIsEnabled('wizReportDailyState', 'off') ? 'روشن ✅' : 'خاموش ❌';
    $time = wizwiz_reportTime();
    global $botState;
    $last = trim((string)($botState['wizReportLastDailyDate'] ?? ''));
    if($last === '') $last = 'ارسال نشده';
    return "📊 <b>تنظیمات آمار و اعلان کانال</b>\n\n" .
           "🔔 آمار روزانه: <b>$dailyState</b>\n" .
           "🕘 ساعت ارسال روزانه: <b>$time</b>\n" .
           "📌 آخرین ارسال روزانه: <b>" . wizwiz_h($last) . "</b>\n" .
           "📎 آمار داخل اعلان‌ها: <b>جدا شده ✅</b>\n\n" .
           "اعلان خرید، تست و تأیید خودکار هرکدام پیام مخصوص خودشان را دارند. از دکمه‌های پایین می‌توانی خود اعلان‌ها، جزئیات داخل اعلان‌ها و آیتم‌های آمار را روشن/خاموش کنی.";
}

function wizwiz_getReportSettingsMenuKeys(){
    global $buttonValues;
    $rows = [];
    $rows[] = [
        ['text'=>(wizwiz_reportIsEnabled('wizReportDailyState', 'off') ? 'خاموش کردن آمار روزانه ❌' : 'روشن کردن آمار روزانه ✅'), 'callback_data'=>'toggleDailyChannelStats', 'style'=>'success'],
        ['text'=>'🕘 تنظیم ساعت', 'callback_data'=>'setDailyChannelStatsTime', 'style'=>'primary']
    ];
    $rows[] = [
        ['text'=>'📤 ارسال آمار الان', 'callback_data'=>'sendDailyChannelStatsNow', 'style'=>'success']
    ];
    $rows[] = [[ 'text'=>'🔔 نوع اعلان‌هایی که به کانال بروند', 'callback_data'=>'wizwizch', 'style'=>'primary' ]];
    foreach(wizwiz_reportEventItems() as $key => $title){
        $state = wizwiz_reportIsEnabled(wizwiz_reportEventKey($key), 'on') ? '✅' : '❌';
        $rows[] = [[ 'text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportEvent_' . $key, 'style'=>'primary' ]];
    }
    $rows[] = [[ 'text'=>'🧩 جزئیات داخل پیام‌های اعلان', 'callback_data'=>'wizwizch', 'style'=>'primary' ]];
    foreach(wizwiz_reportDetailItems() as $key => $title){
        $state = wizwiz_reportDetailEnabled($key, 'on') ? '✅' : '❌';
        $rows[] = [[ 'text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportDetail_' . $key, 'style'=>'primary' ]];
    }
    $rows[] = [[ 'text'=>'📊 آیتم‌های داخل آمار روزانه/دستی', 'callback_data'=>'wizwizch', 'style'=>'primary' ]];
    $pair = [];
    foreach(wizwiz_reportStatItems() as $key => $title){
        $state = wizwiz_reportIsEnabled(wizwiz_reportStatKey($key), 'on') ? '✅' : '❌';
        $pair[] = ['text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportStat_' . $key, 'style'=>'primary'];
        if(count($pair) == 2){ $rows[] = $pair; $pair = []; }
    }
    if(count($pair) > 0) $rows[] = $pair;
    $rows[] = [[ 'text'=>$buttonValues['back_button'] ?? '⬅️ بازگشت', 'callback_data'=>'managePanel', 'style'=>'primary' ]];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function wizwiz_reportPrivateKeyboard($userId, $extraRows = []){
    $rows = [];
    foreach($extraRows as $row){
        if(!empty($row)) $rows[] = $row;
    }
    if(wizwiz_reportDetailEnabled('private_button', 'on')) $rows[] = [wizwiz_userPrivateButton($userId)];
    if(count($rows) == 0) return null;
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function wizwiz_notifyPurchaseStarted($hashId, $source = 'انتخاب پلن'){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return;
    $stmt = $connection->prepare("SELECT p.*, u.`name`, u.`username`, sp.`title` AS plan_title FROM `pays` p LEFT JOIN `users` u ON u.`userid` = p.`user_id` LEFT JOIN `server_plans` sp ON sp.`id` = p.`plan_id` WHERE p.`hash_id` = ? LIMIT 1");
    if(!$stmt) return;
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return;
    $uid = intval($pay['user_id']);
    $lines = ["🟡 <b>شروع فرایند خرید</b>"];
    if(wizwiz_reportDetailEnabled('user_info', 'on')) $lines[] = wizwiz_formatUserLine($uid, $pay['name'] ?? '', $pay['username'] ?? '');
    if(wizwiz_reportDetailEnabled('plan_info', 'on')){
        $lines[] = "📦 پلن/نوع: <b>" . wizwiz_h($pay['plan_title'] ?? $pay['type']) . "</b>";
        $lines[] = "💳 روش/مرحله: <b>" . wizwiz_h($source) . "</b>";
    }
    if(wizwiz_reportDetailEnabled('amount', 'on')) $lines[] = "💰 مبلغ: <b>" . number_format(intval($pay['price'])) . " تومان</b>";
    if(wizwiz_reportDetailEnabled('payment_hash', 'on')) $lines[] = "🔖 کد پرداخت: <code>" . wizwiz_h($hashId) . "</code>";
    $body = implode("\n", $lines) . wizwiz_reportTimeLine();
    wizwiz_reportEvent('🛒 گزارش خرید جدید', $body, wizwiz_reportPrivateKeyboard($uid), 'purchase_started');
}

function wizwiz_notifyTestAccountTaken($orderId, $userId, $planTitle = '', $remark = '', $volume = '', $days = ''){
    global $connection;
    $userId = intval($userId);
    $stmt = $connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
    $user = null;
    if($stmt){
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $lines = ["🧪 <b>اکانت تست دریافت شد</b>"];
    if(wizwiz_reportDetailEnabled('user_info', 'on')) $lines[] = wizwiz_formatUserLine($userId, $user['name'] ?? '', $user['username'] ?? '');
    if(wizwiz_reportDetailEnabled('order_ids', 'on')) $lines[] = "🧾 شماره سفارش: <code>" . intval($orderId) . "</code>";
    if(wizwiz_reportDetailEnabled('plan_info', 'on')){
        $lines[] = "📦 پلن: <b>" . wizwiz_h($planTitle) . "</b>";
        $lines[] = "🔮 ریمارک: <code>" . wizwiz_h($remark) . "</code>";
        $lines[] = "🔋 حجم: <b>" . wizwiz_h($volume) . " گیگ</b>";
        $lines[] = "⏰ مدت: <b>" . wizwiz_h($days) . " روز</b>";
    }
    $body = implode("\n", $lines) . wizwiz_reportTimeLine();
    wizwiz_reportEvent('🧪 گزارش اکانت تست', $body, wizwiz_reportPrivateKeyboard($userId), 'test_account');
}

function wizwiz_getAutoApproveState(){
    global $botState;
    $minutes = intval($botState['autoApproveMinutes'] ?? 5);
    if($minutes < 1) $minutes = 5;
    return [
        'enabled' => (($botState['autoApproveState'] ?? 'off') === 'on'),
        'minutes' => $minutes
    ];
}

function wizwiz_getAutoApproveMenuText(){
    [$enabled, $minutes] = array_values(wizwiz_getAutoApproveState());
    $state = $enabled ? 'روشن ✅' : 'خاموش ❌';
    return "⏱ <b>تأیید خودکار سفارش‌ها</b>\n\n" .
           "وضعیت فعلی: <b>$state</b>\n" .
           "زمان تأیید خودکار: <b>$minutes دقیقه بعد از ارسال رسید</b>\n\n" .
           "وقتی کاربر رسید کارت‌به‌کارت خرید سرویس را ارسال کند، اگر این بخش روشن باشد و سفارش تا زمان تعیین‌شده تأیید/رد نشود، ربات آن را خودکار تأیید می‌کند. گزارش سفارش خودکار داخل کانال گزارش درآمد ارسال می‌شود و از همان‌جا قابل لغو است.";
}

function wizwiz_getAutoApproveMenuKeys(){
    $s = wizwiz_getAutoApproveState();
    $toggle = $s['enabled'] ? 'خاموش کردن ❌' : 'روشن کردن ✅';
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>$toggle, 'callback_data'=>'toggleAutoApproveOrders', 'style'=>$s['enabled'] ? 'danger' : 'success'],
            ['text'=>'⏱ تنظیم دقیقه', 'callback_data'=>'setAutoApproveMinutes', 'style'=>'primary']
        ],
        [
            ['text'=>'🚀 بررسی و اجرای الان', 'callback_data'=>'runAutoApproveOrdersNow', 'style'=>'success']
        ],
        [
            ['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel', 'style'=>'primary']
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function wizwiz_markPayReceiptSent($hashId, $receiptFileId = null){
    global $connection;
    $now = time();
    $receiptFileId = trim((string)$receiptFileId);
    if($receiptFileId !== ''){
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent', `sent_date` = ?, `receipt_file_id` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ? AND `state` IN ('pending','sent')");
        if(!$stmt) return false;
        $stmt->bind_param('iss', $now, $receiptFileId, $hashId);
    }else{
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent', `sent_date` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ? AND `state` IN ('pending','sent')");
        if(!$stmt) return false;
        $stmt->bind_param('is', $now, $hashId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_storeAdminPayMessage($hashId, $chatId, $messageId){
    global $connection;
    $hashId = trim((string)$hashId);
    $chatId = intval($chatId);
    $messageId = intval($messageId);
    if($hashId === '' || $chatId == 0 || $messageId <= 0) return false;
    $stmt = $connection->prepare("UPDATE `pays` SET `admin_chat_id` = ?, `admin_message_id` = ? WHERE `hash_id` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('iis', $chatId, $messageId, $hashId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_getAdminPayMessage($hashId){
    global $connection, $admin;
    $hashId = trim((string)$hashId);
    if($hashId === '') return [0, 0, 0];
    $stmt = $connection->prepare("SELECT `user_id`, `admin_chat_id`, `admin_message_id` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return [0, 0, 0];
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row) return [0, 0, 0];
    $chat = intval($row['admin_chat_id'] ?? 0);
    if($chat == 0) $chat = intval($admin);
    return [intval($chat), intval($row['admin_message_id'] ?? 0), intval($row['user_id'] ?? 0)];
}

function wizwiz_shortButtonText($text, $max = 56){
    $text = trim((string)$text);
    if($text === '') return '';
    if(function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    if(!function_exists('mb_strlen') && strlen($text) > $max) return substr($text, 0, $max - 3) . '...';
    return $text;
}

function wizwiz_approvalStatusTextFromResult($result, $auto = false){
    $remarks = $result['remarks'] ?? [];
    if(!is_array($remarks)) $remarks = [];
    $prefix = $auto ? '🤖 تأیید خودکار شد' : '✅ تأیید شد';
    if(!empty($result['renew_remark'])) return wizwiz_shortButtonText($prefix . ': ' . $result['renew_remark']);
    if(count($remarks) == 1) return wizwiz_shortButtonText($prefix . ': ' . $remarks[0]);
    if(count($remarks) > 1) return $prefix . ' | ' . count($remarks) . ' کانفیگ ساخته شد';
    return $prefix;
}

function wizwiz_approvalCopyTextFromResult($result){
    $items = [];
    if(!empty($result['renew_remark'])) $items[] = trim((string)$result['renew_remark']);
    $remarks = $result['remarks'] ?? [];
    if(is_array($remarks)){
        foreach($remarks as $remark){
            $remark = trim((string)$remark);
            if($remark !== '') $items[] = $remark;
        }
    }
    $items = array_values(array_unique($items));
    if(count($items) == 0) return '';
    $text = implode("\n", $items);
    if(function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 256) return mb_substr($text, 0, 256, 'UTF-8');
    if(!function_exists('mb_strlen') && strlen($text) > 256) return substr($text, 0, 256);
    return $text;
}

function wizwiz_updateAdminPayMessageStatus($hashId, $statusText, $style = 'success', $userId = 0, $copyText = ''){
    [$chat, $msg, $storedUser] = wizwiz_getAdminPayMessage($hashId);
    if($userId <= 0) $userId = $storedUser;
    if($chat == 0 || $msg <= 0) return false;
    editKeys(wizwiz_orderStatusKeyboard($statusText, $userId, $style, $copyText), $msg, $chat);
    return true;
}

function wizwiz_setPayApprovalError($hashId, $message){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return false;
    $message = trim((string)$message);
    $now = time();
    $stmt = $connection->prepare("UPDATE `pays` SET `approval_error` = ?, `approval_error_date` = ? WHERE `hash_id` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('sis', $message, $now, $hashId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_payLinkedOrderIds($hashId){
    global $connection;
    $hashId = trim((string)$hashId);
    $ids = [];
    if($hashId === '') return $ids;
    $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `auto_pay_hash` = ?");
    if(!$stmt) return $ids;
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $ids[] = intval($row['id']);
    $stmt->close();
    return $ids;
}

function wizwiz_autoOrderActionKeyboard($hashId, $userId){
    $rows = [];
    if(wizwiz_reportDetailEnabled('cancel_button', 'on')){
        $rows[] = [[ 'text'=>'❌ لغو کامل سفارش', 'callback_data'=>'autoCancelOrder' . $hashId, 'style'=>'danger' ]];
    }
    if(wizwiz_reportDetailEnabled('private_button', 'on')) $rows[] = [wizwiz_userPrivateButton($userId)];
    if(count($rows) == 0) return null;
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function wizwiz_orderStatusKeyboard($statusText, $userId = 0, $style = 'success', $copyText = ''){
    $copyText = trim((string)$copyText);
    if($copyText !== ''){
        $mainButton = ['text'=>$statusText, 'copy_text'=>['text'=>$copyText]];
    }else{
        $mainButton = ['text'=>$statusText, 'callback_data'=>'wizwizch', 'style'=>$style];
    }
    $rows = [[$mainButton]];
    $userId = intval($userId);
    if($userId > 0) $rows[] = [wizwiz_userPrivateButton($userId)];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function wizwiz_adminPendingOrderKeyboard($hashId, $userId){
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ تأیید', 'callback_data'=>'accept' . $hashId, 'style'=>'success'],
            ['text'=>'❌ عدم تأیید', 'callback_data'=>'declineOrder' . $hashId, 'style'=>'danger']
        ],
        [
            wizwiz_userPrivateButton($userId)
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function wizwiz_adminPendingWalletKeyboard($hashId, $userId){
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ تأیید', 'callback_data'=>'approvePayment' . $hashId, 'style'=>'success'],
            ['text'=>'❌ عدم تأیید', 'callback_data'=>'decPayment' . $hashId, 'style'=>'danger']
        ],
        [
            wizwiz_userPrivateButton($userId)
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function wizwiz_notifyOrderReceiptSent($hashId, $fileId = null){
    // رسید خرید دیگر به کانال/گروه گزارش درآمد ارسال نمی‌شود.
    // فقط پیام مستقیم ادمین ارسال می‌شود و گزارش کانال مخصوص تأیید خودکار باقی می‌ماند.
    return null;
}

function wizwiz_getOrderAdminRecipients(){
    global $connection, $admin;
    $ids = [];
    $mainAdmin = intval($admin ?? 0);
    if($mainAdmin != 0) $ids[] = $mainAdmin;

    // ادمین اصلی همیشه فیش سفارش را دریافت می‌کند.
    // ادمین‌های فرعی فقط وقتی فیش می‌گیرند که از قسمت تنظیمات ادمین‌ها فعال شده باشند.
    if(isset($connection) && $connection){
        $stmt = @$connection->prepare("SELECT `userid` FROM `users` WHERE `isAdmin` = 1 AND COALESCE(`receive_order_receipts`, 0) = 1");
        if($stmt){
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()){
                $uid = intval($row['userid'] ?? 0);
                if($uid != 0) $ids[] = $uid;
            }
            $stmt->close();
        }
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

function wizwiz_adminSendFallbackText($hashId, $photo, $caption){
    $hashId = trim((string)$hashId);
    $photo = trim((string)$photo);
    $text = (string)$caption;
    $extra = "\n\n⚠️ <b>توجه:</b> ارسال عکس رسید برای این پیام ناموفق بود، اما سفارش از دست نرفته است و از همین دکمه‌ها قابل بررسی است.";
    if($hashId !== '') $extra .= "\n🔖 کد پرداخت: <code>" . wizwiz_h($hashId) . "</code>";
    if($photo !== '') $extra .= "\n🖼 File ID رسید: <code>" . wizwiz_h($photo) . "</code>";
    return $text . $extra;
}

function wizwiz_sendAdminPaymentPhoto($hashId, $photo, $caption, $keyboard = null, $parse = 'HTML', $userId = 0){
    $hashId = trim((string)$hashId);
    $photo = trim((string)$photo);
    $recipients = wizwiz_getOrderAdminRecipients();
    if(count($recipients) == 0) return ['ok'=>false, 'sent'=>0, 'message'=>'هیچ ادمینی برای ارسال سفارش پیدا نشد.'];

    // رنگ دکمه‌ها حفظ می‌شود. اگر Bot API مقصد style را قبول نکند، تابع bot() فقط همان درخواست خطادار را بدون style تکرار می‌کند.
    $keyboard = wizwiz_styleReplyMarkup($keyboard);

    $sent = 0;
    $firstChat = 0;
    $firstMsg = 0;
    $errors = [];
    $plainCaption = function_exists('wizwiz_plainTextForTelegram') ? wizwiz_plainTextForTelegram($caption) : strip_tags((string)$caption);

    foreach($recipients as $chatId){
        $chatId = intval($chatId);
        if($chatId == 0) continue;
        $ok = false;
        $res = null;
        $descList = [];

        if($photo !== ''){
            $res = sendPhoto($photo, $caption, $keyboard, $parse, $chatId);
            $ok = is_object($res) && isset($res->ok) && $res->ok;
            if(!$ok){
                $desc = is_object($res) && isset($res->description) ? (string)$res->description : 'sendPhoto failed';
                $descList[] = $desc;
                // اگر مشکل از HTML/Markdown یا کپشن طولانی بود، عکس را با متن ساده دوباره می‌فرستیم.
                $safePlainCaption = ($plainCaption !== '' ? $plainCaption : '🧾 رسید پرداخت');
                if(function_exists('mb_substr')) $safePlainCaption = mb_substr($safePlainCaption, 0, 900, 'UTF-8');
                else $safePlainCaption = substr($safePlainCaption, 0, 900);
                $res = sendPhoto($photo, $safePlainCaption, $keyboard, null, $chatId);
                $ok = is_object($res) && isset($res->ok) && $res->ok;
                if(!$ok){
                    $desc2 = is_object($res) && isset($res->description) ? (string)$res->description : 'sendPhoto plain fallback failed';
                    $descList[] = $desc2;
                }
            }
        }

        if(!$ok){
            // اگر عکس با کپشن ارسال نشد، اول خود عکس را بدون دکمه بفرستیم تا رسید گم نشود، بعد متن سفارش را با دکمه‌ها ارسال کنیم.
            if($photo !== ''){
                $photoOnly = sendPhoto($photo, '🧾 رسید پرداخت', null, null, $chatId);
                if(!(is_object($photoOnly) && isset($photoOnly->ok) && $photoOnly->ok)){
                    $descPhoto = is_object($photoOnly) && isset($photoOnly->description) ? (string)$photoOnly->description : 'sendPhoto photo-only failed';
                    $descList[] = $descPhoto;
                }
            }
            $fallback = wizwiz_adminSendFallbackText($hashId, $photo, $caption);
            $res = sendMessage($fallback, $keyboard, $parse, $chatId);
            $ok = is_object($res) && isset($res->ok) && $res->ok;
            if(!$ok){
                $desc3 = is_object($res) && isset($res->description) ? (string)$res->description : 'sendMessage fallback failed';
                $descList[] = $desc3;
                $plainFallback = function_exists('wizwiz_plainTextForTelegram') ? wizwiz_plainTextForTelegram($fallback) : strip_tags($fallback);
                $res = sendMessage($plainFallback, $keyboard, null, $chatId);
                $ok = is_object($res) && isset($res->ok) && $res->ok;
                if(!$ok){
                    $desc4 = is_object($res) && isset($res->description) ? (string)$res->description : 'sendMessage plain fallback failed';
                    $descList[] = $desc4;
                }
            }
        }

        if($ok){
            $sent++;
            if($firstMsg <= 0 && isset($res->result->message_id)){
                $firstChat = $chatId;
                $firstMsg = intval($res->result->message_id);
            }
        }else{
            $errors[] = $chatId . ': ' . implode(' | ', $descList);
        }
    }

    if($sent > 0 && $hashId !== '' && $firstMsg > 0){
        wizwiz_storeAdminPayMessage($hashId, $firstChat, $firstMsg);
    }

    if($sent <= 0){
        $errText = count($errors) ? implode("\n", array_slice($errors, 0, 5)) : 'نامشخص';
        $faErr = function_exists('wizwiz_translateTechnicalError') ? wizwiz_translateTechnicalError($errText) : $errText;
        if(function_exists('wizwiz_reportEvent')){
            $body = "⚠️ <b>ارسال پیام سفارش به ادمین ناموفق بود</b>\n" .
                    ($hashId !== '' ? "🔖 کد پرداخت: <code>" . wizwiz_h($hashId) . "</code>\n" : '') .
                    ($userId ? "🆔 کاربر: <code>" . intval($userId) . "</code>\n" : '') .
                    "📝 خطا به فارسی:\n<code>" . wizwiz_h($faErr) . "</code>" . wizwiz_reportTimeLine();
            $keyboardReport = $userId ? wizwiz_reportPrivateKeyboard($userId) : null;
            wizwiz_reportEvent('⚠️ خطای ارسال سفارش به ادمین', $body, $keyboardReport, 'admin_order_send_failed');
        }
        return ['ok'=>false, 'sent'=>0, 'message'=>$faErr, 'errors'=>$errors];
    }

    return ['ok'=>true, 'sent'=>$sent, 'chat_id'=>$firstChat, 'message_id'=>$firstMsg, 'errors'=>$errors];
}

function wizwiz_buildPendingAdminOrderMessage($pay){
    global $connection;
    if(!is_array($pay)) return '';
    $hash = (string)($pay['hash_id'] ?? '');
    $uid = intval($pay['user_id'] ?? 0);
    $price = number_format(intval($pay['price'] ?? 0));
    $type = (string)($pay['type'] ?? '');
    $planId = intval($pay['plan_id'] ?? 0);
    $remark = '';
    $planTitle = 'نامشخص';
    $volume = $pay['volume'] ?? '';
    $days = $pay['day'] ?? '';

    if($type == 'RENEW_SCONFIG'){
        $configInfo = json_decode((string)($pay['description'] ?? ''), true);
        if(is_array($configInfo)) $remark = (string)($configInfo['remark'] ?? '');
        else $remark = (string)($pay['description'] ?? '');
        $planTitle = 'تمدید سرویس';
    }else{
        $remark = trim((string)($pay['description'] ?? ''));
        if($planId > 0){
            $stmt = $connection->prepare("SELECT sp.`title`, sp.`volume`, sp.`days`, sc.`title` cat_title, si.`title` server_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sp.`catid` = sc.`id` LEFT JOIN `server_info` si ON sp.`server_id` = si.`id` WHERE sp.`id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $plan = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($plan){
                    $planTitle = trim(($plan['cat_title'] ?? '') . ' ' . ($plan['title'] ?? ''));
                    if($planTitle === '') $planTitle = (string)($plan['server_title'] ?? 'پلن خرید');
                    if($volume === '' || intval($volume) == 0) $volume = $plan['volume'] ?? $volume;
                    if($days === '' || intval($days) == 0) $days = $plan['days'] ?? $days;
                }
            }
        }
    }

    $stmt = $connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
    $user = null;
    if($stmt){
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $lines = ["🧾 <b>سفارش کارت‌به‌کارت در انتظار تأیید</b>"];
    if($user) $lines[] = wizwiz_formatUserLine($uid, $user['name'] ?? '', $user['username'] ?? '');
    else $lines[] = "🆔 کاربر: <code>{$uid}</code>";
    $lines[] = "🔖 کد پرداخت: <code>" . wizwiz_h($hash) . "</code>";
    $lines[] = "💰 مبلغ: <b>{$price} تومان</b>";
    $lines[] = "📦 پلن: <b>" . wizwiz_h($planTitle) . "</b>";
    if($remark !== '') $lines[] = "🔮 ریمارک: <code>" . wizwiz_h($remark) . "</code>";
    if($volume !== '' && intval($volume) > 0) $lines[] = "🔋 حجم: <b>" . wizwiz_h($volume) . " گیگ</b>";
    if($days !== '' && intval($days) > 0) $lines[] = "⏰ مدت: <b>" . wizwiz_h($days) . " روز</b>";
    $lines[] = "\n⚠️ این پیام به‌صورت بازیابی خودکار ارسال شده چون پیام سفارش قبلی در ادمین ثبت نشده بود.";
    return implode("\n", $lines);
}

function wizwiz_resendMissingAdminOrderMessages($limit = 3){
    global $connection;
    $limit = max(1, min(10, intval($limit)));
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'sent' AND `type` IN ('BUY_SUB','RENEW_SCONFIG') AND COALESCE(`admin_message_id`,0) = 0 ORDER BY COALESCE(NULLIF(`sent_date`,0), `request_date`) ASC LIMIT $limit");
    if(!$stmt) return ['ok'=>false, 'sent'=>0, 'message'=>'query failed'];
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();
    $sent = 0;
    while($pay = $rows->fetch_assoc()){
        $hash = (string)($pay['hash_id'] ?? '');
        if($hash === '') continue;
        $uid = intval($pay['user_id'] ?? 0);
        $msg = wizwiz_buildPendingAdminOrderMessage($pay);
        $keyboard = wizwiz_adminPendingOrderKeyboard($hash, $uid);
        $photo = trim((string)($pay['receipt_file_id'] ?? ''));
        $res = wizwiz_sendAdminPaymentPhoto($hash, $photo, $msg, $keyboard, 'HTML', $uid);
        if(!empty($res['ok'])) $sent++;
    }
    return ['ok'=>true, 'sent'=>$sent];
}

function wizwiz_sendConfigLinksToUser($uid, $remark, $protocol, $volume, $days, $links, $subLink, $serverType){
    global $botUrl, $buttonValues, $botState;
    if(!is_array($links)) $links = [$links];
    $keyboard = json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'] ?? 'بازگشت', 'callback_data'=>'mainMenu']]]], JSON_UNESCAPED_UNICODE);

    // دقیقاً مثل خرید عادی/کیف پول: اگر چند دامنه وجود داشته باشد همه لینک‌ها در یک پیام ارسال می‌شوند.
    if(function_exists('wizwiz_sendMultiDomainConfigMessage') && wizwiz_sendMultiDomainConfigMessage($uid, $remark, $links, $subLink, $serverType, $keyboard)){
        return true;
    }

    if(!class_exists('QRcode') && file_exists('phpqrcode/qrlib.php')) @include_once 'phpqrcode/qrlib.php';
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH', 540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    foreach($links as $link){
        $link = (string)$link;
        if(trim($link) === '') continue;
        $acc_text = "😍 سفارش جدید شما\n📡 پروتکل: $protocol\n🔮 نام سرویس: $remark\n🔋حجم سرویس: $volume گیگ\n⏰ مدت سرویس: $days روز\n" .
            (($botState['configLinkState'] ?? 'on') != 'off' && $serverType != 'marzban' ? "\n💝 config : <code>$link</code>" : '');
        if(($botState['subLinkState'] ?? 'off') == 'on' && $subLink != '') $acc_text .= "\n\n🌐 subscription : <code>$subLink</code>";

        if(class_exists('QRcode')){
            $file = RandomString() . '.png';
            QRcode::png($link, $file, 'L', 11, 0);
            if(function_exists('addBorderImage')) @addBorderImage($file);
            if(file_exists('settings/QRCode.jpg')){
                $backgroundImage = @imagecreatefromjpeg('settings/QRCode.jpg');
                $qrImage = @imagecreatefrompng($file);
                if($backgroundImage && $qrImage){
                    $qrSize = ['width' => imagesx($qrImage), 'height' => imagesy($qrImage)];
                    imagecopy($backgroundImage, $qrImage, 300, 300, 0, 0, $qrSize['width'], $qrSize['height']);
                    imagepng($backgroundImage, $file);
                    imagedestroy($backgroundImage);
                    imagedestroy($qrImage);
                }
            }
            sendPhoto($botUrl . $file, $acc_text, $keyboard, 'HTML', $uid);
            @unlink($file);
        }else{
            sendMessage($acc_text, $keyboard, 'HTML', $uid);
        }
    }
    return true;
}

function wizwiz_lockPayForApproval($hashId, $auto = false){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return ['ok'=>false, 'message'=>'کد پرداخت نامعتبر است.'];

    if($auto){
        $stmt = $connection->prepare("SELECT `state`, `approval_error` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        if(!$stmt) return ['ok'=>false, 'message'=>'دسترسی به وضعیت پرداخت ممکن نیست.'];
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$row) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد.'];
        if(($row['state'] ?? '') === 'auto_processing') return ['ok'=>true, 'message'=>'locked'];
        if(($row['state'] ?? '') === 'approved') return ['ok'=>false, 'message'=>'این سفارش قبلاً تأیید شده است.'];
        return ['ok'=>false, 'message'=>'این سفارش دیگر در وضعیت قابل تأیید خودکار نیست.'];
    }

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'processing' WHERE `hash_id` = ? AND `state` = 'sent'");
    if(!$stmt) return ['ok'=>false, 'message'=>'قفل‌گذاری سفارش ممکن نیست.'];
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if($changed > 0) return ['ok'=>true, 'message'=>'locked'];

    $stmt = $connection->prepare("SELECT `state`, `approval_error` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $state = $row['state'] ?? '';
        if($state === 'approved') return ['ok'=>false, 'message'=>'این سفارش قبلاً تأیید شده است.'];
        if($state === 'processing' || $state === 'auto_processing'){
            if(trim((string)($row['approval_error'] ?? '')) !== ''){
                wizwiz_restorePayApprovalState($hashId);
                return wizwiz_lockPayForApproval($hashId, $auto);
            }
            return ['ok'=>false, 'message'=>'این سفارش در حال پردازش است؛ چند بار روی تأیید نزنید.'];
        }
        if($state === 'declined' || $state === 'auto_cancelled') return ['ok'=>false, 'message'=>'این سفارش قبلاً رد یا لغو شده است.'];
    }
    return ['ok'=>false, 'message'=>'این سفارش دیگر در وضعیت قابل تأیید نیست.'];
}

function wizwiz_restorePayApprovalState($hashId){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return false;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ? AND `state` IN ('processing','auto_processing')");
    if(!$stmt) return false;
    $stmt->bind_param('s', $hashId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function wizwiz_approveSentOrderByHash($hashId, $auto = false){
    global $connection, $botState, $mainValues, $admin;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $fail = function($message) use (&$approvalLocked, $hashId){
        if($approvalLocked) wizwiz_restorePayApprovalState($hashId);
        wizwiz_setPayApprovalError($hashId, $message);
        return ['ok'=>false, 'message'=>$message];
    };
    if($hashId === '') return $fail('کد پرداخت نامعتبر است.');

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return $fail('دسترسی به جدول پرداخت ممکن نیست.');
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$payInfo) return $fail('پرداخت پیدا نشد.');
    if(($payInfo['state'] ?? '') == 'approved'){
        $existingOrders = json_decode($payInfo['auto_approved_orders'] ?? '[]', true) ?: [];
        if(count($existingOrders) == 0) $existingOrders = wizwiz_payLinkedOrderIds($hashId);
        // اگر نسخه قدیمی سفارش را قبل از ساخت کانفیگ approved کرده باشد و هیچ سفارش لینک‌شده‌ای وجود نداشته باشد، امکان تلاش دوباره بده.
        if(count($existingOrders) == 0 && intval($payInfo['admin_message_id'] ?? 0) > 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent', `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ? AND `state` = 'approved'");
            if($stmt){ $stmt->bind_param('s', $hashId); $stmt->execute(); $stmt->close(); }
            $payInfo['state'] = 'sent';
        }else{
            return ['ok'=>true, 'message'=>'این سفارش قبلاً تأیید شده است.', 'order_ids'=>$existingOrders, 'user_id'=>intval($payInfo['user_id'] ?? 0), 'price'=>intval($payInfo['price'] ?? 0), 'already'=>true];
        }
    }
    if(in_array(($payInfo['state'] ?? ''), ['declined','auto_cancelled'], true)) return $fail('این سفارش قبلاً رد یا لغو شده است.');

    $lock = wizwiz_lockPayForApproval($hashId, $auto);
    if(empty($lock['ok'])) return $lock;
    $approvalLocked = true;

    $uid = intval($payInfo['user_id']);
    $fid = intval($payInfo['plan_id']);
    $price = intval($payInfo['price']);
    $now = time();
    $orderIds = [];
    $remarks = [];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? LIMIT 1");
    if(!$stmt) return $fail('پلن سفارش پیدا نشد.');
    $stmt->bind_param('i', $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$file_detail) return $fail('پلن سفارش پیدا نشد.');

    $days = $file_detail['days'];
    $volume = $file_detail['volume'];
    if(intval($payInfo['day'] ?? 0) > 0) $days = $payInfo['day'];
    if(floatval($payInfo['volume'] ?? 0) > 0) $volume = $payInfo['volume'];
    $protocol = $file_detail['protocol'];
    $server_id = intval($file_detail['server_id']);
    $netType = $file_detail['type'];
    $acount = intval($file_detail['acount']);
    $inbound_id = intval($file_detail['inbound_id']);
    $limitip = intval($file_detail['limitip']);
    $rahgozar = intval($file_detail['rahgozar']);
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    $customDomain = $file_detail['custom_domain'] ?? null;

    $autoFlag = $auto ? 1 : 0;

    if(($payInfo['type'] ?? '') == 'RENEW_SCONFIG'){
        $configInfo = json_decode((string)$payInfo['description'], true);
        if(!is_array($configInfo)) return $fail('اطلاعات تمدید نامعتبر است.');
        $uuid = $configInfo['uuid'] ?? '';
        $remark = $configInfo['remark'] ?? '';
        $isMarzban = !empty($configInfo['marzban']);
        $renewInbound = intval($payInfo['volume']);
        if($isMarzban) $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume'=>$volume]);
        else $response = ($renewInbound > 0) ? editClientTraffic($server_id, $renewInbound, $uuid, $volume, $days, 'renew') : editInboundTraffic($server_id, $uuid, $volume, $days, 'renew');
        if(is_null($response)) return $fail('اتصال به سرور برقرار نشد.');
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        if($stmt){
            $stmt->bind_param('iiisii', $uid, $server_id, $renewInbound, $remark, $price, $now);
            $stmt->execute();
            $stmt->close();
        }
        $emptyOrders = json_encode([], JSON_UNESCAPED_UNICODE);
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
        if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $emptyOrders, $hashId); $stmt->execute(); $stmt->close(); }
        $approvalLocked = false;
        sendMessage(str_replace(['REMARK','VOLUME','DAYS'], [$remark, $volume, $days], $mainValues['renewed_config_to_user'] ?? 'سرویس شما تمدید شد.'), null, 'HTML', $uid);
        return ['ok'=>true, 'message'=>'تمدید با موفقیت انجام شد.', 'order_ids'=>[], 'user_id'=>$uid, 'price'=>$price, 'plan_id'=>$fid, 'renew_remark'=>$remark, 'remarks'=>[$remark]];
    }

    $accountCount = intval($payInfo['agent_count'] ?? 0);
    if($accountCount <= 0) $accountCount = 1;
    $eachPrice = $accountCount > 0 ? (int)floor($price / $accountCount) : $price;

    if($acount == 0 && $inbound_id != 0) return $fail($mainValues['out_of_connection_capacity'] ?? 'ظرفیت پلن تمام شده است.');
    if($inbound_id == 0){
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=? LIMIT 1");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$serverInfo || intval($serverInfo['ucount']) <= 0) return $fail($mainValues['out_of_server_capacity'] ?? 'ظرفیت سرور تمام شده است.');
    }else{
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=? LIMIT 1");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $srv_remark = $serverInfo['remark'] ?? 'srv';

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverConfig) return $fail('تنظیمات سرور پیدا نشد.');
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];

    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $now + (86400 * $days);
    $agentBought = intval($payInfo['agent_bought'] ?? 0);

    for($i=1; $i <= $accountCount; $i++){
        $uniqid = generateRandomString(42, $protocol);
        $savedinfo = @file_get_contents('settings/temp.txt');
        $savedinfo = explode('-', (string)$savedinfo);
        $port = intval($savedinfo[0] ?? 10000) + 1;
        $last_num = intval($savedinfo[1] ?? 1) + 1;
        $payDescription = trim((string)($payInfo['description'] ?? ''));
        $isCustomPlanPay = ($payDescription !== '' && (intval($payInfo['day'] ?? 0) > 0 || floatval($payInfo['volume'] ?? 0) > 0));
        if($isCustomPlanPay || (($botState['remark'] ?? '') == 'manual' && $payDescription !== '')){
            $remark = $payDescription;
            if($accountCount > 1) $remark .= '-' . $i;
        }elseif(($botState['remark'] ?? '') == 'digits'){
            $remark = $srv_remark . '-' . rand(10000,99999);
        }else{
            $remark = $srv_remark . '-' . $uid . '-' . rand(1111,99999);
        }
        if($portType == 'auto') @file_put_contents('settings/temp.txt', $port . '-' . $last_num);
        else $port = rand(1111,65000);

        if($inbound_id == 0){
            if($serverType == 'marzban'){
                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                if(is_object($response) && empty($response->success) && ($response->msg ?? '') == 'User already exists'){
                    $remark .= rand(1111,99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                }
            }else{
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                if(is_object($response) && empty($response->success)){
                    if(strstr((string)($response->msg ?? ''), 'Duplicate email')) $remark .= RandomString();
                    elseif(strstr((string)($response->msg ?? ''), 'Port already exists')) $port = rand(1111,65000);
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                }
            }
        }else{
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
            if(is_object($response) && empty($response->success)){
                if(strstr((string)($response->msg ?? ''), 'Duplicate email')) $remark .= RandomString();
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
            }
        }

        if(is_null($response)) return $fail('اتصال به سرور برقرار نیست.');
        if($response === 'inbound not Found') return $fail('سطر inbound در سرور پیدا نشد. سفارش به حالت قابل تأیید برگشت؛ بعد از اصلاح inbound دوباره روی تأیید بزنید.');
        if(!is_object($response) || empty($response->success)) return $fail('خطای ساخت کانفیگ: ' . (function_exists('wizwiz_translateTechnicalError') ? wizwiz_translateTechnicalError(is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response) : (is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response)));

        if($serverType == 'marzban'){
            $uniqid = $token = str_replace('/sub/', '', $response->sub_link);
            $subLink = (($botState['subLinkState'] ?? 'off') == 'on') ? $panelUrl . $response->sub_link : '';
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }else{
            $token = RandomString(30);
            $subLink = (($botState['subLinkState'] ?? 'off') == 'on') ? wizwiz_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : '';
            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
            $vray_link = json_encode($vraylink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        wizwiz_sendConfigLinksToUser($uid, $remark, $protocol, $volume, $days, $vraylink, $subLink, $serverType);

        $status = 1;
        $notif = 0;
        $autoFlag = $auto ? 1 : 0;
        $stmt = $connection->prepare("INSERT INTO `orders_list` (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`, `auto_approved`, `auto_pay_hash`) VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if(!$stmt) return $fail('ثبت سفارش در دیتابیس ناموفق بود.');
        $stmt->bind_param('ssiiisssisiiiiiiis', $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $status, $now, $notif, $rahgozar, $agentBought, $autoFlag, $hashId);
        $stmt->execute();
        $orderIds[] = intval($connection->insert_id);
        $remarks[] = $remark;
        $stmt->close();
    }

    if($inbound_id == 0){
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
        if($stmt){ $stmt->bind_param('ii', $accountCount, $server_id); $stmt->execute(); $stmt->close(); }
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE `id`=?");
        if($stmt){ $stmt->bind_param('ii', $accountCount, $fid); $stmt->execute(); $stmt->close(); }
    }

    $ordersJson = json_encode($orderIds, JSON_UNESCAPED_UNICODE);
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $ordersJson, $hashId); $stmt->execute(); $stmt->close(); }
    $approvalLocked = false;

    $stmt = $connection->prepare("SELECT `name`, `username`, `refered_by` FROM `users` WHERE `userid`=? LIMIT 1");
    $user_detail = null;
    if($stmt){
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if(!empty($user_detail['refered_by'])){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        if($stmt){
            $stmt->execute();
            $inviteAmount = intval($stmt->get_result()->fetch_assoc()['value'] ?? 0);
            $stmt->close();
            $inviterId = intval($user_detail['refered_by']);
            if($inviteAmount > 0 && $inviterId > 0){
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                if($stmt){ $stmt->bind_param('ii', $inviteAmount, $inviterId); $stmt->execute(); $stmt->close(); }
                sendMessage('تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ ' . number_format($inviteAmount) . ' تومان جایزه دریافت کردید', null, null, $inviterId);
            }
        }
    }

    // پیام خلاصه «کانفیگ برای کاربر ارسال شد» حذف شد؛ کانفیگ اصلی قبلاً برای کاربر ارسال می‌شود.
    return ['ok'=>true, 'message'=>'سفارش با موفقیت تأیید شد.', 'order_ids'=>$orderIds, 'remarks'=>$remarks, 'user_id'=>$uid, 'price'=>$price, 'plan_id'=>$fid];
}

function wizwiz_processAutoApproveOrders($force = false, $limit = 3){
    global $connection, $botState;
    $state = wizwiz_getAutoApproveState();
    if(!$force && !$state['enabled']) return ['processed'=>0, 'messages'=>[]];
    $minutes = intval($state['minutes']);
    if($minutes < 1) $minutes = 1;
    $cutoff = $force ? time() : (time() - ($minutes * 60));
    $limit = max(1, min(10, intval($limit)));
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'sent' AND `type` IN ('BUY_SUB','RENEW_SCONFIG') AND COALESCE(`sent_date`,0) > 0 AND `sent_date` <= ? ORDER BY `sent_date` ASC LIMIT $limit");
    if(!$stmt) return ['processed'=>0, 'messages'=>['خطا در دریافت سفارش‌های در انتظار.']];
    $stmt->bind_param('i', $cutoff);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    $processed = 0;
    $messages = [];
    while($pay = $rows->fetch_assoc()){
        $hash = $pay['hash_id'];
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'auto_processing' WHERE `hash_id` = ? AND `state` = 'sent'");
        if(!$stmt) continue;
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if($changed <= 0) continue;

        $result = wizwiz_approveSentOrderByHash($hash, true);
        if($result['ok']){
            $processed++;
            $uid = intval($result['user_id'] ?? $pay['user_id']);
            $orders = $result['order_ids'] ?? [];
            $ordersText = count($orders) ? implode(', ', array_map('intval', $orders)) : 'ثبت نشده';
            $statusText = wizwiz_approvalStatusTextFromResult($result, true);
            $copyText = function_exists('wizwiz_approvalCopyTextFromResult') ? wizwiz_approvalCopyTextFromResult($result) : '';
            wizwiz_updateAdminPayMessageStatus($hash, $statusText, 'success', $uid, $copyText);

            $lines = ["✅ <b>سفارش به‌صورت خودکار تأیید شد</b>"];
            if(wizwiz_reportDetailEnabled('user_info', 'on')) $lines[] = "🆔 کاربر: <code>{$uid}</code>";
            if(wizwiz_reportDetailEnabled('amount', 'on')) $lines[] = "💰 مبلغ: <b>" . number_format(intval($result['price'] ?? $pay['price'])) . " تومان</b>";
            if(wizwiz_reportDetailEnabled('payment_hash', 'on')) $lines[] = "🔖 کد پرداخت: <code>" . wizwiz_h($hash) . "</code>";
            if(wizwiz_reportDetailEnabled('order_ids', 'on')) $lines[] = "🧾 سفارش‌های ساخته‌شده: <code>" . wizwiz_h($ordersText) . "</code>";
            if(wizwiz_reportDetailEnabled('cancel_button', 'on')) $lines[] = "در صورت نیاز می‌توانید از همین پیام سفارش را کامل لغو کنید و دلیل لغو برای کاربر ارسال می‌شود.";
            $body = implode("\n", $lines) . wizwiz_reportTimeLine();
            wizwiz_reportEvent('🤖 تأیید خودکار سفارش', $body, wizwiz_autoOrderActionKeyboard($hash, $uid), 'auto_approved');
            $messages[] = "✅ $hash تأیید شد.";
        }else{
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ? AND `state` = 'auto_processing'");
            if($stmt){ $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close(); }
            $uid = intval($pay['user_id'] ?? 0);
            $lines = ["⚠️ <b>تأیید خودکار انجام نشد</b>"];
            if(wizwiz_reportDetailEnabled('user_info', 'on')) $lines[] = "🆔 کاربر: <code>{$uid}</code>";
            if(wizwiz_reportDetailEnabled('payment_hash', 'on')) $lines[] = "🔖 کد پرداخت: <code>" . wizwiz_h($hash) . "</code>";
            $lines[] = "📝 خطا: <b>" . wizwiz_h($result['message']) . "</b>";
            $lines[] = "بعد از اصلاح مشکل، همان دکمه تأیید سفارش دوباره قابل استفاده است.";
            wizwiz_reportEvent('⚠️ خطای تأیید خودکار', implode("\n", $lines) . wizwiz_reportTimeLine(), wizwiz_reportPrivateKeyboard($uid), 'approval_failed');
            $messages[] = "❌ $hash: " . $result['message'];
        }

    }
    return ['processed'=>$processed, 'messages'=>$messages];
}

function wizwiz_deleteOrderCompletely($orderId, $reason = ''){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return ['ok'=>false, 'message'=>'شماره سفارش نامعتبر است.'];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return ['ok'=>false, 'message'=>'دسترسی به سفارش ممکن نیست.'];
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$order) return ['ok'=>false, 'message'=>'سفارش پیدا نشد.'];
    $server_id = intval($order['server_id']);
    $inbound_id = intval($order['inbound_id']);
    $uuid = $order['uuid'] ?? '0';
    $remark = $order['remark'] ?? '';
    $fileid = intval($order['fileid']);

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    $serverConfig = null;
    if($stmt){
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $serverType = $serverConfig['type'] ?? '';
    if($serverType == 'marzban') @deleteMarzban($server_id, $remark);
    else{
        if($inbound_id > 0) @deleteClient($server_id, $inbound_id, $uuid, 1);
        else @deleteInbound($server_id, $uuid, 1);
    }

    if($inbound_id > 0){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` + 1 WHERE `id` = ?");
        if($stmt){ $stmt->bind_param('i', $fileid); $stmt->execute(); $stmt->close(); }
    }else{
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
        if($stmt){ $stmt->bind_param('i', $server_id); $stmt->execute(); $stmt->close(); }
    }

    $stmt = $connection->prepare("UPDATE `orders_list` SET `cancel_reason` = ? WHERE `id` = ?");
    if($stmt){ $stmt->bind_param('si', $reason, $orderId); $stmt->execute(); $stmt->close(); }
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    if($stmt){ $stmt->bind_param('i', $orderId); $stmt->execute(); $stmt->close(); }
    return ['ok'=>true, 'message'=>'حذف شد.', 'order'=>$order];
}

function wizwiz_cancelAutoApprovedPay($hashId, $reason){
    global $connection;
    $hashId = trim((string)$hashId);
    $reason = trim((string)$reason);
    if($reason === '') $reason = 'لغو توسط مدیریت';
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد.'];
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد.'];
    $orders = json_decode((string)($pay['auto_approved_orders'] ?? '[]'), true);
    if(!is_array($orders)) $orders = [];
    if(count($orders) == 0){
        $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `auto_pay_hash` = ?");
        if($stmt){
            $stmt->bind_param('s', $hashId);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) $orders[] = intval($row['id']);
            $stmt->close();
        }
    }
    if(count($orders) == 0) return ['ok'=>false, 'message'=>'سفارشی برای حذف پیدا نشد یا این مورد تمدید بوده و حذف خودکار ندارد.'];
    $deleted = 0;
    foreach($orders as $oid){
        $r = wizwiz_deleteOrderCompletely($oid, $reason);
        if($r['ok']) $deleted++;
    }
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'auto_cancelled', `cancel_reason` = ? WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('ss', $reason, $hashId); $stmt->execute(); $stmt->close(); }
    $uid = intval($pay['user_id']);
    sendMessage("❌ سفارش شما توسط مدیریت لغو شد.\n\n📝 دلیل لغو:\n" . $reason, null, 'HTML', $uid);
    return ['ok'=>true, 'message'=>"$deleted سفارش حذف شد.", 'deleted'=>$deleted, 'user_id'=>$uid];
}
// ===== End WizWiz extra realtime reports + auto order approval =====

?>
