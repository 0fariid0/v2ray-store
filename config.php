<?php
include_once "settings/values.php";
include_once 'settings/jdf.php';
include_once 'baseInfo.php';

$connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
if($connection->connect_error){
    exit("error " . $connection->connect_error);  
}
$connection->set_charset("utf8mb4");


function v2raystore_textSettingsAllowedKeys(){
    return ['start_message', 'purchase_rules_text'];
}

function v2raystore_getTextSettings(){
    global $connection;
    if(array_key_exists('v2raystore_text_settings_cache', $GLOBALS) && is_array($GLOBALS['v2raystore_text_settings_cache'])){
        return $GLOBALS['v2raystore_text_settings_cache'];
    }
    if(!isset($connection) || !($connection instanceof mysqli)){
        $GLOBALS['v2raystore_text_settings_cache'] = [];
        return [];
    }
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'TEXT_SETTINGS' LIMIT 1");
    if(!$stmt){
        $GLOBALS['v2raystore_text_settings_cache'] = [];
        return [];
    }
    if(!$stmt->execute()){
        $stmt->close();
        $GLOBALS['v2raystore_text_settings_cache'] = [];
        return [];
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row || !isset($row['value']) || trim((string)$row['value']) === ''){
        $GLOBALS['v2raystore_text_settings_cache'] = [];
        return [];
    }
    $data = json_decode((string)$row['value'], true);
    $GLOBALS['v2raystore_text_settings_cache'] = is_array($data) ? $data : [];
    return $GLOBALS['v2raystore_text_settings_cache'];
}

function v2raystore_saveTextSettings($settings){
    global $connection;
    if(!isset($connection) || !($connection instanceof mysqli)) return 'اتصال دیتابیس در دسترس نیست.';
    if(!is_array($settings)) $settings = [];
    $allowed = v2raystore_textSettingsAllowedKeys();
    $clean = [];
    foreach($allowed as $key){
        if(array_key_exists($key, $settings)) $clean[$key] = (string)$settings[$key];
    }
    $value = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if($value === false) return 'ساخت JSON تنظیمات متن ناموفق بود.';

    $stmt = @$connection->prepare("SELECT `type` FROM `setting` WHERE `type` = 'TEXT_SETTINGS' LIMIT 1");
    if(!$stmt) return 'آماده‌سازی خواندن تنظیمات متن ناموفق بود: ' . $connection->error;
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if($exists){
        $stmt = @$connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'TEXT_SETTINGS'");
        if(!$stmt) return 'آماده‌سازی ذخیره تنظیمات متن ناموفق بود: ' . $connection->error;
        $stmt->bind_param('s', $value);
    }else{
        $type = 'TEXT_SETTINGS';
        $stmt = @$connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        if(!$stmt) return 'آماده‌سازی ساخت تنظیمات متن ناموفق بود: ' . $connection->error;
        $stmt->bind_param('ss', $type, $value);
    }
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if($ok) $GLOBALS['v2raystore_text_settings_cache'] = $clean;
    return $ok ? true : ('ذخیره تنظیمات متن ناموفق بود: ' . $err);
}

function v2raystore_saveMainText($key, $value){
    global $mainValues;
    if(!in_array($key, v2raystore_textSettingsAllowedKeys(), true)) return 'کلید متن معتبر نیست.';
    $settings = v2raystore_getTextSettings();
    $settings[$key] = (string)$value;
    $res = v2raystore_saveTextSettings($settings);
    if($res === true) $mainValues[$key] = (string)$value;
    return $res;
}

function v2raystore_removeMainText($key){
    global $mainValues;
    if(!in_array($key, v2raystore_textSettingsAllowedKeys(), true)) return 'کلید متن معتبر نیست.';
    $settings = v2raystore_getTextSettings();
    unset($settings[$key]);
    $res = v2raystore_saveTextSettings($settings);
    if($res === true && $key === 'purchase_rules_text') unset($mainValues[$key]);
    return $res;
}

function v2raystore_getMainText($key, $default = ''){
    global $mainValues;
    $settings = v2raystore_getTextSettings();
    if(array_key_exists($key, $settings)) return (string)$settings[$key];
    if(isset($mainValues[$key])) return (string)$mainValues[$key];
    return (string)$default;
}

function v2raystore_applyTextSettings(){
    global $mainValues;
    $settings = v2raystore_getTextSettings();
    foreach(v2raystore_textSettingsAllowedKeys() as $key){
        if(array_key_exists($key, $settings)) $mainValues[$key] = (string)$settings[$key];
    }
}

function v2raystore_purchaseRulesText(){
    return trim((string)v2raystore_getMainText('purchase_rules_text', ''));
}

function v2raystore_purchaseRulesIsEnabled(){
    return v2raystore_purchaseRulesText() !== '';
}

function v2raystore_purchaseRulesKeyboard($target){
    $allowed = ['agentOneBuy', 'buySubscription', 'agentMuchBuy'];
    if(!in_array($target, $allowed, true)) $target = 'buySubscription';
    return json_encode(['inline_keyboard'=>[
        [['text'=>'✅ تأیید و ادامه خرید', 'callback_data'=>'confirmPurchaseRules_' . $target]],
    ]], JSON_UNESCAPED_UNICODE);
}

function v2raystore_purchaseRulesMessage(){
    $text = v2raystore_purchaseRulesText();
    return $text !== '' ? $text : 'لطفاً قوانین خرید را تأیید کنید.';
}



function v2raystore_ensureOrderNoteColumn(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('ORDER_CONFIG_NOTE_V1')) return;
    $exists = @($connection->query("SHOW COLUMNS FROM `orders_list` LIKE 'config_note'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `orders_list` ADD `config_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL AFTER `remark`"));
    }
    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('ORDER_CONFIG_NOTE_V1');
}
v2raystore_ensureOrderNoteColumn();
v2raystore_applyTextSettings();

function v2raystore_safeConfigNoteText($note){
    $note = trim((string)$note);
    $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note);
    if(function_exists('mb_strlen') && mb_strlen($note, 'UTF-8') > 300){
        $note = mb_substr($note, 0, 300, 'UTF-8');
    }elseif(strlen($note) > 1200){
        $note = substr($note, 0, 1200);
    }
    return $note;
}

function v2raystore_buildConfigDetailsMessage($state, $remark, $configLinks = '', $subLink = '', $configNote = ''){
    $msg = "وضعیت کانفیگ: " . $state . "

";
    $msg .= "🔮 نام کانفیگ: " . htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8') . "
";

    $configNote = function_exists('v2raystore_safeConfigNoteText') ? v2raystore_safeConfigNoteText($configNote) : trim((string)$configNote);
    if(trim((string)$configNote) !== ''){
        $msg .= "
📝 یادداشت کانفیگ: " . htmlspecialchars($configNote, ENT_QUOTES, 'UTF-8') . "
";
    }

    $configLinks = trim((string)$configLinks);
    if($configLinks !== ''){
        $msg .= "
لینک اتصال: " . $configLinks . "
";
    }

    $subLink = trim((string)$subLink);
    if($subLink !== ''){
        $msg .= "
لینک سابسکریپشن: " . $subLink . "
";
    }

    $msg .= "⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
";
    return $msg;
}

function v2raystore_orderDetailButtonCallback($button){
    return strtolower(trim((string)($button['callback_data'] ?? '')));
}

function v2raystore_isMyConfigProtocolRow($row){
    global $buttonValues;
    if(!is_array($row) || count($row) == 0) return false;

    $texts = [];
    $callbacks = [];
    foreach($row as $button){
        if(!is_array($button)) continue;
        $texts[] = trim((string)($button['text'] ?? ''));
        $callbacks[] = v2raystore_orderDetailButtonCallback($button);
    }
    $joinedText = trim(implode(' ', $texts));
    $joinedCallback = trim(implode(' ', $callbacks));
    $selectedProtocol = trim((string)($buttonValues['selected_protocol'] ?? ''));

    if($selectedProtocol !== '' && $joinedText === $selectedProtocol) return true;
    if(preg_match('/(پروتکل\s*انتخاب|selected[_\s-]*protocol)/ui', $joinedText . ' ' . $joinedCallback)) return true;

    $allProtocolButtons = true;
    $hasProtocolWord = false;
    foreach($texts as $t){
        $plain = trim(str_replace(['☑️','✅','✔️','✓'], '', $t));
        $plain = trim(preg_replace('/\s+/u', ' ', $plain));
        if(preg_match('/\b(vless|vmess|trojan)\b/i', $plain)) $hasProtocolWord = true;
        else $allProtocolButtons = false;
    }
    if($hasProtocolWord && $allProtocolButtons) return true;
    if(strpos($joinedCallback, 'changeaccprotocol') !== false || strpos($joinedCallback, 'changeprotocolisdisable') !== false) return true;

    return false;
}

function v2raystore_isMyConfigInfoRow($row){
    if(!is_array($row) || count($row) == 0) return false;
    foreach($row as $button){
        if(!is_array($button)) return false;
        $cb = (string)($button['callback_data'] ?? '');
        if($cb !== 'v2raystore') return false;
    }
    return true;
}

function v2raystore_isMyConfigPagerRow($row){
    if(!is_array($row) || count($row) == 0) return false;
    foreach($row as $button){
        $cb = (string)($button['callback_data'] ?? '');
        if(strpos($cb, 'orderDetails') !== 0) return false;
    }
    return true;
}

function v2raystore_isMyConfigImportantSingleButton($button){
    $cb = v2raystore_orderDetailButtonCallback($button);
    $text = trim((string)($button['text'] ?? ''));
    if(strpos($cb, 'editconfignote') === 0) return true;
    if(strpos($cb, 'switchlocation') === 0) return true;
    if(strpos($cb, 'changaccountconnectionlink') === 0) return true;
    if(strpos($cb, 'updateconfigconnectionlink') === 0) return true;
    if(strpos($cb, 'deletemyconfig') === 0) return true;
    if(in_array($cb, ['mysubscriptions', 'agentconfigslist', 'mainmenu'], true)) return true;
    if(preg_match('/(حذف|لغو|بازگشت|برگشت|لینک\s*جدید|بروزرسانی\s*لینک|به‌روزرسانی\s*لینک)/u', $text)) return true;
    return false;
}

function v2raystore_prepareMyConfigDetailKeyboard($keyboard, $orderId, $agentBought = false, $note = ''){
    global $buttonValues;
    if(!is_array($keyboard)) return $keyboard;

    $topRows = [];
    $buttons = [];
    $importantRows = [];
    $dangerRows = [];
    $backRows = [];
    $noteAdded = false;

    foreach($keyboard as $row){
        if(!is_array($row) || count($row) == 0) continue;
        if(v2raystore_isMyConfigProtocolRow($row)) continue;

        if(v2raystore_isMyConfigPagerRow($row) || v2raystore_isMyConfigInfoRow($row)){
            $topRows[] = $row;
            continue;
        }

        foreach($row as $button){
            if(!is_array($button) || !isset($button['text'])) continue;
            $cb = v2raystore_orderDetailButtonCallback($button);
            if(strpos($cb, 'deleteMyConfig') === 0 || strpos($cb, 'deletemyconfig') === 0){
                $dangerRows[] = [$button];
            }elseif(in_array($cb, ['mysubscriptions', 'agentconfigslist', 'mainmenu'], true)){
                $backRows[] = [$button];
            }elseif(v2raystore_isMyConfigImportantSingleButton($button)){
                $importantRows[] = [$button];
            }else{
                $buttons[] = $button;
            }
        }
    }

    $noteText = trim((string)$note) === '' ? '📝 ثبت یادداشت کانفیگ' : '✏️ ویرایش یادداشت کانفیگ';
    $noteRow = [[
        'text' => $noteText,
        'callback_data' => 'editConfigNote' . intval($orderId),
        'style' => 'primary'
    ]];

    $out = [];
    foreach($topRows as $row) $out[] = $row;
    $out[] = $noteRow;

    foreach($importantRows as $row){
        $cb = isset($row[0]) ? v2raystore_orderDetailButtonCallback($row[0]) : '';
        if(strpos($cb, 'editconfignote') === 0) continue;
        $out[] = $row;
    }

    $chunk = [];
    foreach($buttons as $button){
        $chunk[] = $button;
        if(count($chunk) == 2){
            $out[] = $chunk;
            $chunk = [];
        }
    }
    if(count($chunk) > 0) $out[] = $chunk;

    foreach($dangerRows as $row) $out[] = $row;
    foreach($backRows as $row) $out[] = $row;

    return $out;
}


function v2raystore_cleanSingleDomainHost($domain){
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

function v2raystore_normalizePlanDomainInput($domain){
    $domain = trim((string)$domain);
    if($domain === "") return "";

    $lines = preg_split('/\r\n|\r|\n/', $domain);
    $clean = [];
    foreach($lines as $line){
        $host = v2raystore_cleanSingleDomainHost($line);
        if($host !== "") $clean[] = $host;
    }
    $clean = array_values(array_unique($clean));
    return implode("\n", $clean);
}


function v2raystore_pickHostValue($value){
    if($value === null) return '';
    if(is_string($value) || is_numeric($value)){
        $value = trim((string)$value);
        return ($value === '' || strtolower($value) === 'null') ? '' : $value;
    }
    if(is_object($value)) $value = get_object_vars($value);
    if(is_array($value)){
        foreach(['Host','host','HOST'] as $key){
            if(array_key_exists($key, $value)){
                $picked = v2raystore_pickHostValue($value[$key]);
                if($picked !== '') return $picked;
            }
        }
        if(isset($value['name']) && isset($value['value']) && strtolower(trim((string)$value['name'])) === 'host'){
            $picked = v2raystore_pickHostValue($value['value']);
            if($picked !== '') return $picked;
        }
        foreach($value as $item){
            $picked = v2raystore_pickHostValue($item);
            if($picked !== '') return $picked;
        }
    }
    return '';
}

function v2raystore_extractWsSettings($streamSettings, $fallbackHost = ''){
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
        if(isset($wsArr['host'])) $host = v2raystore_pickHostValue($wsArr['host']);
        if($host === '' && isset($wsArr['headers'])) $host = v2raystore_pickHostValue($wsArr['headers']);
        if($host === '' && isset($wsArr['header'])) $host = v2raystore_pickHostValue($wsArr['header']);
        if(isset($wsArr['header'])){
            $headerArr = is_object($wsArr['header']) ? get_object_vars($wsArr['header']) : (is_array($wsArr['header']) ? $wsArr['header'] : []);
            if(isset($headerArr['type']) && trim((string)$headerArr['type']) !== '') $headerType = trim((string)$headerArr['type']);
        }
    }

    if($host === '') $host = v2raystore_cleanSingleDomainHost($fallbackHost);
    return ['path' => ($path !== '' ? $path : '/'), 'host' => $host, 'header_type' => $headerType];
}

function v2raystore_schemaPatchDone($key){
    global $connection;
    $key = (string)$key;
    if(!isset($GLOBALS['v2raystore_schema_patch_cache']) || !is_array($GLOBALS['v2raystore_schema_patch_cache'])){
        $GLOBALS['v2raystore_schema_patch_cache'] = [];
    }
    if(array_key_exists($key, $GLOBALS['v2raystore_schema_patch_cache'])){
        return $GLOBALS['v2raystore_schema_patch_cache'][$key];
    }
    $type = 'SCHEMA_PATCH_' . $key;
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt){
        $GLOBALS['v2raystore_schema_patch_cache'][$key] = false;
        return false;
    }
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    $done = $row && (($row['value'] ?? '') === 'done');
    $GLOBALS['v2raystore_schema_patch_cache'][$key] = $done;
    return $done;
}

function v2raystore_markSchemaPatchDone($key){
    global $connection;
    $key = (string)$key;
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
    if($ok){
        if(!isset($GLOBALS['v2raystore_schema_patch_cache']) || !is_array($GLOBALS['v2raystore_schema_patch_cache'])) $GLOBALS['v2raystore_schema_patch_cache'] = [];
        $GLOBALS['v2raystore_schema_patch_cache'][$key] = true;
    }
    return $ok;
}

function v2raystore_ensureFastSettingIndexes(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('FAST_SETTING_INDEX_V1')) return;
    $idx = @($connection->query("SHOW INDEX FROM `setting` WHERE `Key_name` = 'idx_setting_type'"));
    if($idx && $idx->num_rows == 0){
        @($connection->query("ALTER TABLE `setting` ADD INDEX `idx_setting_type` (`type`(100))"));
    }
    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('FAST_SETTING_INDEX_V1');
}
v2raystore_ensureFastSettingIndexes();

function v2raystore_ensurePlanCustomDomainColumn(){
    global $connection;
    if(v2raystore_schemaPatchDone('PLAN_CUSTOM_DOMAIN_V1')) return;
    $exists = @($connection->query("SHOW COLUMNS FROM `server_plans` LIKE 'custom_domain'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `server_plans` ADD `custom_domain` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL AFTER `custom_sni`"));
    }
    v2raystore_markSchemaPatchDone('PLAN_CUSTOM_DOMAIN_V1');
}
v2raystore_ensurePlanCustomDomainColumn();

function v2raystore_ensureExtraUserColumns(){
    global $connection;
    if(v2raystore_schemaPatchDone('USERS_ACCESS_JOIN_CARD_V2')) return;
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
    v2raystore_markSchemaPatchDone('USERS_ACCESS_JOIN_CARD_V2');
}
v2raystore_ensureExtraUserColumns();

function v2raystore_ensureAccessCodeAuditColumns(){
    global $connection;
    if(v2raystore_schemaPatchDone('USERS_ACCESS_CODE_AUDIT_V1')) return;
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
    v2raystore_markSchemaPatchDone('USERS_ACCESS_CODE_AUDIT_V1');
}
v2raystore_ensureAccessCodeAuditColumns();


function v2raystore_ensureTestAccountManagementColumns(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('USERS_TEST_ACCOUNT_MGMT_V1')) return;
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
    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('USERS_TEST_ACCOUNT_MGMT_V1');
}
v2raystore_ensureTestAccountManagementColumns();


function v2raystore_ensureBroadcastTargetColumn(){
    global $connection;
    if(v2raystore_schemaPatchDone('SEND_LIST_TARGET_TYPE_V1')) return;
    $exists = @($connection->query("SHOW COLUMNS FROM `send_list` LIKE 'target_type'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `send_list` ADD `target_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL DEFAULT 'all' AFTER `state`"));
    }
    v2raystore_markSchemaPatchDone('SEND_LIST_TARGET_TYPE_V1');
}
v2raystore_ensureBroadcastTargetColumn();

function v2raystore_ensureBroadcastQueueColumns(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('SEND_LIST_QUEUE_V2')) return;

    // ستون‌های جدید برای ارسال همگانی مرحله‌ای و بدون فشار روی CPU اضافه می‌شوند.
    $columns = [
        'last_user_id' => "ALTER TABLE `send_list` ADD `last_user_id` int(11) NOT NULL DEFAULT 0 AFTER `offset`",
        'total_count' => "ALTER TABLE `send_list` ADD `total_count` int(11) NOT NULL DEFAULT 0 AFTER `target_type`",
        'sent_count' => "ALTER TABLE `send_list` ADD `sent_count` int(11) NOT NULL DEFAULT 0 AFTER `total_count`",
        'failed_count' => "ALTER TABLE `send_list` ADD `failed_count` int(11) NOT NULL DEFAULT 0 AFTER `sent_count`",
        'blocked_count' => "ALTER TABLE `send_list` ADD `blocked_count` int(11) NOT NULL DEFAULT 0 AFTER `failed_count`",
        'last_report_at' => "ALTER TABLE `send_list` ADD `last_report_at` int(11) NOT NULL DEFAULT 0 AFTER `blocked_count`",
        'pause_until' => "ALTER TABLE `send_list` ADD `pause_until` int(11) NOT NULL DEFAULT 0 AFTER `last_report_at`",
        'started_at' => "ALTER TABLE `send_list` ADD `started_at` int(11) NOT NULL DEFAULT 0 AFTER `pause_until`",
        'updated_at' => "ALTER TABLE `send_list` ADD `updated_at` int(11) NOT NULL DEFAULT 0 AFTER `started_at`",
    ];

    foreach($columns as $column => $query){
        $exists = @($connection->query("SHOW COLUMNS FROM `send_list` LIKE '$column'"));
        if($exists && $exists->num_rows == 0){
            @($connection->query($query));
        }
    }

    $idx = @($connection->query("SHOW INDEX FROM `send_list` WHERE `Key_name` = 'idx_broadcast_state'"));
    if($idx && $idx->num_rows == 0){
        @($connection->query("ALTER TABLE `send_list` ADD INDEX `idx_broadcast_state` (`state`, `type`)"));
    }
    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('SEND_LIST_QUEUE_V2');
}
v2raystore_ensureBroadcastQueueColumns();


function v2raystore_ensureServerSwitchTables(){
    global $connection;

    if(!function_exists('v2raystore_schemaPatchDone') || !v2raystore_schemaPatchDone('SERVER_SWITCH_V1')){
        @($connection->query("CREATE TABLE IF NOT EXISTS `server_switch_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `user_id` bigint(20) NOT NULL,
            `from_server_id` int(11) NOT NULL,
            `to_server_id` int(11) NOT NULL,
            `old_remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
            `new_remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
            `deducted_gb` float NOT NULL DEFAULT 0,
            `created_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_order_created` (`order_id`, `created_at`),
            KEY `idx_user_created` (`user_id`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_persian_ci"));

        @($connection->query("CREATE TABLE IF NOT EXISTS `server_switch_costs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `from_server_id` int(11) NOT NULL,
            `to_server_id` int(11) NOT NULL,
            `volume_gb` float NOT NULL DEFAULT 0,
            `percent_rate` float DEFAULT NULL,
            `updated_at` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_route` (`from_server_id`, `to_server_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_persian_ci"));

        if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('SERVER_SWITCH_V1');
    }

    // نسخه‌های قبلی جدول هزینه مسیر را فقط با حجم ثابت می‌ساختند؛ این ستون برای حالت درصدی اضافه می‌شود.
    if(!function_exists('v2raystore_schemaPatchDone') || !v2raystore_schemaPatchDone('SERVER_SWITCH_PERCENT_V1')){
        @($connection->query("CREATE TABLE IF NOT EXISTS `server_switch_costs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `from_server_id` int(11) NOT NULL,
            `to_server_id` int(11) NOT NULL,
            `volume_gb` float NOT NULL DEFAULT 0,
            `percent_rate` float DEFAULT NULL,
            `updated_at` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_route` (`from_server_id`, `to_server_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_persian_ci"));
        $col = @($connection->query("SHOW COLUMNS FROM `server_switch_costs` LIKE 'percent_rate'"));
        if(!$col || $col->num_rows == 0){
            @($connection->query("ALTER TABLE `server_switch_costs` ADD `percent_rate` float DEFAULT NULL AFTER `volume_gb`"));
        }
        if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('SERVER_SWITCH_PERCENT_V1');
    }
}
v2raystore_ensureServerSwitchTables();

function v2raystore_switchGetSettingRaw(){
    global $connection;
    $type = 'SERVER_SWITCH_SETTINGS';
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row['value'] ?? null;
}

function v2raystore_getServerSwitchSettings(){
    $default = [
        'mode' => 'auto',              // auto | manual | percent
        'default_gb' => 1,             // only manual mode, unless pair override exists
        'percent' => 10,               // percent of remaining volume in percent mode
        'min_gb' => 0.5,               // minimum deduction in auto/percent mode
        'daily_limit' => 1,            // per config per day for normal users; 0 means unlimited
    ];
    $raw = v2raystore_switchGetSettingRaw();
    $data = is_string($raw) ? json_decode($raw, true) : [];
    if(!is_array($data)) $data = [];
    $data = array_merge($default, $data);
    $allowedModes = ['auto', 'manual', 'percent'];
    $data['mode'] = in_array(($data['mode'] ?? 'auto'), $allowedModes, true) ? $data['mode'] : 'auto';
    $data['default_gb'] = max(0, floatval($data['default_gb'] ?? 1));
    $data['percent'] = min(100, max(0, floatval($data['percent'] ?? 10)));
    $data['min_gb'] = max(0, floatval($data['min_gb'] ?? 0.5));
    $data['daily_limit'] = max(0, intval($data['daily_limit'] ?? 1));
    return $data;
}

function v2raystore_saveServerSwitchSettings($settings){
    global $connection;
    if(!is_array($settings)) $settings = [];
    $current = v2raystore_getServerSwitchSettings();
    $settings = array_merge($current, $settings);
    $allowedModes = ['auto', 'manual', 'percent'];
    $settings['mode'] = in_array(($settings['mode'] ?? 'auto'), $allowedModes, true) ? $settings['mode'] : 'auto';
    $settings['default_gb'] = max(0, floatval($settings['default_gb'] ?? 1));
    $settings['percent'] = min(100, max(0, floatval($settings['percent'] ?? 10)));
    $settings['min_gb'] = max(0, floatval($settings['min_gb'] ?? 0.5));
    $settings['daily_limit'] = max(0, intval($settings['daily_limit'] ?? 1));

    $type = 'SERVER_SWITCH_SETTINGS';
    $value = json_encode($settings, JSON_UNESCAPED_UNICODE);
    $stmt = @$connection->prepare("SELECT `id` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $exists = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    if($exists){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = ?");
        $stmt->bind_param('ss', $value, $type);
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        $stmt->bind_param('ss', $type, $value);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_switchFormatGb($gb){
    $gb = floatval($gb);
    if($gb < 0) $gb = 0;
    return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
}

function v2raystore_switchGetServerTitle($serverId){
    global $connection;
    $serverId = intval($serverId);
    if($serverId <= 0) return '-';
    $stmt = @$connection->prepare("SELECT `title` FROM `server_info` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return (string)$serverId;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string)($row['title'] ?? $serverId));
}

function v2raystore_switchGetOrder($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return null;
    $stmt = @$connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function v2raystore_switchGetPlan($planId){
    global $connection;
    $planId = intval($planId);
    if($planId <= 0) return null;
    $stmt = @$connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function v2raystore_switchFindEquivalentPlan($currentPlan, $targetServerId){
    global $connection;
    if(!is_array($currentPlan)) return null;
    $targetServerId = intval($targetServerId);
    if($targetServerId <= 0) return null;

    $volume = floatval($currentPlan['volume'] ?? -1);
    $days = floatval($currentPlan['days'] ?? -1);
    $title = trim((string)($currentPlan['title'] ?? ''));
    $type = trim((string)($currentPlan['type'] ?? ''));
    $protocol = trim((string)($currentPlan['protocol'] ?? ''));

    // Exact volume/days/title first.
    $stmt = @$connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `active` = 1 AND ABS(`volume` - ?) < 0.001 AND ABS(`days` - ?) < 0.001 AND `title` = ? ORDER BY `price` DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param('idds', $targetServerId, $volume, $days, $title);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row) return $row;
    }

    // Same volume/days/protocol/type.
    $stmt = @$connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `active` = 1 AND ABS(`volume` - ?) < 0.001 AND ABS(`days` - ?) < 0.001 AND `protocol` = ? AND `type` = ? ORDER BY `price` DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param('iddss', $targetServerId, $volume, $days, $protocol, $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row) return $row;
    }

    // Same volume/days fallback.
    $stmt = @$connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `active` = 1 AND ABS(`volume` - ?) < 0.001 AND ABS(`days` - ?) < 0.001 ORDER BY `price` DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param('idd', $targetServerId, $volume, $days);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row) return $row;
    }

    return null;
}

function v2raystore_getSwitchPairCostGb($fromServerId, $toServerId){
    global $connection;
    $fromServerId = intval($fromServerId);
    $toServerId = intval($toServerId);
    if($fromServerId <= 0 || $toServerId <= 0) return null;
    $stmt = @$connection->prepare("SELECT `volume_gb` FROM `server_switch_costs` WHERE `from_server_id` = ? AND `to_server_id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('ii', $fromServerId, $toServerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row) return null;
    return max(0, floatval($row['volume_gb']));
}

function v2raystore_getSwitchPairPercent($fromServerId, $toServerId){
    global $connection;
    $fromServerId = intval($fromServerId);
    $toServerId = intval($toServerId);
    if($fromServerId <= 0 || $toServerId <= 0) return null;
    $stmt = @$connection->prepare("SELECT `percent_rate` FROM `server_switch_costs` WHERE `from_server_id` = ? AND `to_server_id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('ii', $fromServerId, $toServerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row || $row['percent_rate'] === null || $row['percent_rate'] === '') return null;
    return min(100, max(0, floatval($row['percent_rate'])));
}

function v2raystore_switchPercentToGb($remainingGb, $percent, $minGb = 0){
    $remainingGb = max(0, floatval($remainingGb));
    $percent = min(100, max(0, floatval($percent)));
    $minGb = max(0, floatval($minGb));
    if($remainingGb <= 0 || $percent <= 0) return 0;
    $deduct = $remainingGb * ($percent / 100);
    if($minGb > 0) $deduct = max($minGb, $deduct);
    // هیچ‌وقت بیشتر از حجم باقی‌مانده کم نکنیم تا سرویس خراب نشود.
    return min($remainingGb, round($deduct, 2));
}

function v2raystore_setSwitchPairCostGb($fromServerId, $toServerId, $gb){
    global $connection;
    $fromServerId = intval($fromServerId);
    $toServerId = intval($toServerId);
    $gb = max(0, floatval($gb));
    if($fromServerId <= 0 || $toServerId <= 0 || $fromServerId == $toServerId) return false;
    $now = time();
    $stmt = @$connection->prepare("SELECT `id` FROM `server_switch_costs` WHERE `from_server_id` = ? AND `to_server_id` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('ii', $fromServerId, $toServerId);
    $stmt->execute();
    $exists = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    if($exists){
        $stmt = $connection->prepare("UPDATE `server_switch_costs` SET `volume_gb` = ?, `updated_at` = ? WHERE `from_server_id` = ? AND `to_server_id` = ?");
        $stmt->bind_param('diii', $gb, $now, $fromServerId, $toServerId);
    }else{
        $stmt = $connection->prepare("INSERT INTO `server_switch_costs` (`from_server_id`, `to_server_id`, `volume_gb`, `updated_at`) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iidi', $fromServerId, $toServerId, $gb, $now);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_setSwitchPairPercent($fromServerId, $toServerId, $percent){
    global $connection;
    $fromServerId = intval($fromServerId);
    $toServerId = intval($toServerId);
    $percent = min(100, max(0, floatval($percent)));
    if($fromServerId <= 0 || $toServerId <= 0 || $fromServerId == $toServerId) return false;
    $now = time();
    $stmt = @$connection->prepare("SELECT `id` FROM `server_switch_costs` WHERE `from_server_id` = ? AND `to_server_id` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('ii', $fromServerId, $toServerId);
    $stmt->execute();
    $exists = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    if($exists){
        $stmt = $connection->prepare("UPDATE `server_switch_costs` SET `percent_rate` = ?, `updated_at` = ? WHERE `from_server_id` = ? AND `to_server_id` = ?");
        $stmt->bind_param('diii', $percent, $now, $fromServerId, $toServerId);
    }else{
        $stmt = $connection->prepare("INSERT INTO `server_switch_costs` (`from_server_id`, `to_server_id`, `volume_gb`, `percent_rate`, `updated_at`) VALUES (?, ?, 0, ?, ?)");
        $stmt->bind_param('iidi', $fromServerId, $toServerId, $percent, $now);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_deleteSwitchPairCostGb($fromServerId, $toServerId){
    global $connection;
    $fromServerId = intval($fromServerId);
    $toServerId = intval($toServerId);
    if($fromServerId <= 0 || $toServerId <= 0) return false;
    $stmt = @$connection->prepare("DELETE FROM `server_switch_costs` WHERE `from_server_id` = ? AND `to_server_id` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('ii', $fromServerId, $toServerId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_calcSwitchDeductionGb($order, $targetServerId, $remainingGb = null){
    $settings = v2raystore_getServerSwitchSettings();
    $fromServerId = intval($order['server_id'] ?? 0);
    $targetServerId = intval($targetServerId);

    // مسیر مستقیم یعنی همان چیزی که ادمین گفته از مبدا به مقصد اعمال شود.
    // اگر مسیر مستقیم تنظیم نشده باشد ولی مسیر برعکس تنظیم شده باشد، همان مقدار به صورت معکوس محاسبه می‌شود؛ یعنی به حجم اضافه می‌شود.
    $directPairPercent = v2raystore_getSwitchPairPercent($fromServerId, $targetServerId);
    $directPairCost = v2raystore_getSwitchPairCostGb($fromServerId, $targetServerId);
    $reversePairPercent = v2raystore_getSwitchPairPercent($targetServerId, $fromServerId);
    $reversePairCost = v2raystore_getSwitchPairCostGb($targetServerId, $fromServerId);

    $currentPlan = v2raystore_switchGetPlan($order['fileid'] ?? 0);
    $targetPlan = v2raystore_switchFindEquivalentPlan($currentPlan, $targetServerId);

    $sourcePrice = is_array($currentPlan) ? floatval($currentPlan['price'] ?? 0) : floatval($order['amount'] ?? 0);
    $targetPrice = is_array($targetPlan) ? floatval($targetPlan['price'] ?? 0) : $sourcePrice;
    $sourceVolume = is_array($currentPlan) ? floatval($currentPlan['volume'] ?? 0) : 0;
    $targetVolume = is_array($targetPlan) ? floatval($targetPlan['volume'] ?? 0) : $sourceVolume;
    $sourcePerGb = ($sourceVolume > 0 && $sourcePrice > 0) ? ($sourcePrice / $sourceVolume) : 0;
    $targetPerGb = ($targetVolume > 0 && $targetPrice > 0) ? ($targetPrice / $targetVolume) : 0;
    $pricePerGb = max($sourcePerGb, $targetPerGb);
    if($pricePerGb <= 0 && $remainingGb !== null && floatval($remainingGb) > 0 && $sourcePrice > 0) $pricePerGb = $sourcePrice / floatval($remainingGb);

    $remainingForPercent = ($remainingGb !== null) ? max(0, floatval($remainingGb)) : max(0, floatval($sourceVolume));
    $reason = '';
    $percentUsed = null;
    $changeType = 'deduct'; // deduct = کم کردن حجم، add = اضافه کردن حجم
    $pairMode = 'none';

    if($directPairPercent !== null){
        $pairMode = 'direct_percent';
        $percentUsed = $directPairPercent;
        $amount = v2raystore_switchPercentToGb($remainingForPercent, $directPairPercent, $settings['min_gb']);
        $changeType = 'deduct';
        $reason = 'درصد اختصاصی مسیر توسط ادمین: ' . v2raystore_switchFormatGb($directPairPercent) . '% از حجم باقی‌مانده کم می‌شود';
    }elseif($directPairCost !== null && floatval($directPairCost) > 0){
        $pairMode = 'direct_fixed';
        $amount = $directPairCost;
        $changeType = 'deduct';
        $reason = 'هزینه ثابت اختصاصی مسیر توسط ادمین کم می‌شود';
    }elseif($reversePairPercent !== null){
        $pairMode = 'reverse_percent';
        $percentUsed = $reversePairPercent;
        $amount = v2raystore_switchPercentToGb($remainingForPercent, $reversePairPercent, $settings['min_gb']);
        $changeType = 'add';
        $reason = 'مسیر برگشتیِ درصد اختصاصی ادمین: ' . v2raystore_switchFormatGb($reversePairPercent) . '% به حجم باقی‌مانده اضافه می‌شود';
    }elseif($reversePairCost !== null && floatval($reversePairCost) > 0){
        $pairMode = 'reverse_fixed';
        $amount = $reversePairCost;
        $changeType = 'add';
        $reason = 'مسیر برگشتیِ هزینه ثابت ادمین به حجم اضافه می‌شود';
    }else{
        // وقتی هزینه اختصاصی مسیر وجود ندارد، اگر پلن مقصد ارزان‌تر باشد، تغییر به نفع کاربر است و حجم اضافه می‌شود.
        // اگر پلن مقصد گران‌تر باشد، از حجم کم می‌شود. اگر قیمت‌ها نامشخص/برابر باشند، رفتار قبلی حفظ می‌شود و کسر حجم انجام می‌گیرد.
        $isCheaperTarget = ($sourcePrice > 0 && $targetPrice > 0 && $targetPrice < $sourcePrice);
        $changeType = $isCheaperTarget ? 'add' : 'deduct';

        if($settings['mode'] === 'manual'){
            $amount = floatval($settings['default_gb']);
            $reason = $changeType === 'add' ? 'حجم ثابت برگشت به سرور ارزان‌تر اضافه می‌شود' : 'هزینه ثابت تنظیم‌شده توسط ادمین کم می‌شود';
        }elseif($settings['mode'] === 'percent'){
            $percentUsed = floatval($settings['percent']);
            $amount = v2raystore_switchPercentToGb($remainingForPercent, $percentUsed, $settings['min_gb']);
            $reason = $changeType === 'add'
                ? 'محاسبه درصدی برگشت به سرور ارزان‌تر: ' . v2raystore_switchFormatGb($percentUsed) . '% به حجم باقی‌مانده اضافه می‌شود'
                : 'محاسبه درصدی: ' . v2raystore_switchFormatGb($percentUsed) . '% از حجم باقی‌مانده کم می‌شود';
        }else{
            $diff = abs($targetPrice - $sourcePrice);
            $ratioPercent = ($diff > 0 && max($targetPrice, $sourcePrice) > 0) ? (($diff / max($targetPrice, $sourcePrice)) * 100) : 0;
            $autoGbByPercent = ($ratioPercent > 0 && $remainingForPercent > 0) ? v2raystore_switchPercentToGb($remainingForPercent, $ratioPercent, 0) : 0;
            $autoGbByPrice = ($pricePerGb > 0 && $diff > 0) ? ($diff / $pricePerGb) : 0;
            $autoGb = max($autoGbByPercent, $autoGbByPrice);
            $amount = max(floatval($settings['min_gb']), $autoGb);
            $percentUsed = $ratioPercent > 0 ? $ratioPercent : null;
            $reason = $changeType === 'add' ? 'محاسبه خودکار برگشت به سرور ارزان‌تر؛ اختلاف قیمت به حجم اضافه می‌شود' : 'محاسبه خودکار متعادل از اختلاف قیمت پلن‌ها؛ حجم کم می‌شود';
            if(!is_array($targetPlan)){
                $reason = $changeType === 'add'
                    ? 'پلن هم‌حجم در سرور مقصد پیدا نشد؛ حداقل حجم برگشتی اضافه می‌شود'
                    : 'پلن هم‌حجم در سرور مقصد پیدا نشد؛ حداقل کسر حجم اعمال می‌شود';
            }
        }
    }

    $amount = max(0, round(floatval($amount ?? 0), 2));
    if($changeType === 'deduct' && $remainingGb !== null) $amount = min(max(0, floatval($remainingGb)), $amount);

    return [
        'deduct_gb' => $amount, // برای سازگاری با کدهای قبلی، مقدار خام اینجا نگه داشته شده است.
        'change_gb' => $amount,
        'change_type' => $changeType,
        'is_addition' => ($changeType === 'add'),
        'signed_change_gb' => ($changeType === 'add' ? $amount : -$amount),
        'reason' => $reason,
        'mode' => $settings['mode'],
        'pair_mode' => $pairMode,
        'percent_used' => $percentUsed,
        'source_price' => intval($sourcePrice),
        'target_price' => intval($targetPrice),
        'price_diff' => intval(abs($targetPrice - $sourcePrice)),
        'current_plan_id' => is_array($currentPlan) ? intval($currentPlan['id']) : 0,
        'target_plan_id' => is_array($targetPlan) ? intval($targetPlan['id']) : intval($order['fileid'] ?? 0),
        'target_plan' => is_array($targetPlan) ? $targetPlan : null,
        'settings' => $settings,
    ];
}

function v2raystore_switchTodayStart(){
    $today = strtotime(date('Y-m-d 00:00:00'));
    return $today ?: (time() - 86400);
}

function v2raystore_switchUsedToday($orderId, $userId){
    global $connection;
    $orderId = intval($orderId);
    $userId = intval($userId);
    $start = v2raystore_switchTodayStart();
    $stmt = @$connection->prepare("SELECT COUNT(*) AS `cnt` FROM `server_switch_logs` WHERE `order_id` = ? AND `user_id` = ? AND `created_at` >= ?");
    if(!$stmt) return 0;
    $stmt->bind_param('iii', $orderId, $userId, $start);
    $stmt->execute();
    $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt;
}

function v2raystore_recordSwitchLog($orderId, $userId, $fromServerId, $toServerId, $oldRemark, $newRemark, $deductGb){
    global $connection;
    $now = time();
    $orderId = intval($orderId); $userId = intval($userId); $fromServerId = intval($fromServerId); $toServerId = intval($toServerId);
    $deductGb = floatval($deductGb);
    $stmt = @$connection->prepare("INSERT INTO `server_switch_logs` (`order_id`, `user_id`, `from_server_id`, `to_server_id`, `old_remark`, `new_remark`, `deducted_gb`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if(!$stmt) return false;
    $stmt->bind_param('iiiissdi', $orderId, $userId, $fromServerId, $toServerId, $oldRemark, $newRemark, $deductGb, $now);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_switchRouteCostLabel($rowOrFrom, $toServerId = null){
    if(is_array($rowOrFrom)){
        $percent = ($rowOrFrom['percent_rate'] ?? null);
        $gb = floatval($rowOrFrom['volume_gb'] ?? 0);
    }else{
        $percent = v2raystore_getSwitchPairPercent($rowOrFrom, $toServerId);
        $gb = v2raystore_getSwitchPairCostGb($rowOrFrom, $toServerId);
    }
    $parts = [];
    if($percent !== null && $percent !== '') $parts[] = v2raystore_switchFormatGb($percent) . '%';
    if($gb !== null && floatval($gb) > 0) $parts[] = v2raystore_switchFormatGb($gb) . 'GB';
    return implode(' / ', $parts);
}

function v2raystore_switchDailyLimitText($limit){
    $limit = intval($limit);
    return $limit <= 0 ? 'نامحدود' : ($limit . ' بار در روز برای هر کانفیگ');
}

function v2raystore_getSwitchSettingsMenuText(){
    global $connection;
    $s = v2raystore_getServerSwitchSettings();
    $modeTitles = [
        'auto' => 'خودکار متعادل از اختلاف قیمت پلن‌ها',
        'percent' => 'درصدی از حجم باقی‌مانده',
        'manual' => 'دستی / حجم ثابت'
    ];
    $modeText = $modeTitles[$s['mode']] ?? $modeTitles['auto'];
    $txt = "🌎 <b>تنظیمات تغییر سرور</b>

" .
           "از این بخش می‌توانید مشخص کنید هنگام جابه‌جایی کانفیگ بین سرورها چه مقدار حجم از سرویس کم شود.
" .
           "در حالت درصدی، کسر حجم متناسب با حجم سرویس است؛ مثلاً ۱۵٪ برای ۳۰ گیگ می‌شود ۴.۵ گیگ و برای ۵ گیگ می‌شود ۰.۷۵ گیگ.

" .
           "⚙️ حالت محاسبه: <b>{$modeText}</b>
" .
           "📊 درصد عمومی: <b>" . v2raystore_switchFormatGb($s['percent']) . "%</b>
" .
           "🔻 حجم ثابت دستی: <b>" . v2raystore_switchFormatGb($s['default_gb']) . " GB</b>
" .
           "🔹 حداقل کسر در حالت خودکار/درصدی: <b>" . v2raystore_switchFormatGb($s['min_gb']) . " GB</b>
" .
           "🕘 سقف کاربر عادی: <b>" . v2raystore_switchDailyLimitText($s['daily_limit']) . "</b>

" .
           "ادمین از محدودیت روزانه معاف است. برای نامحدود کردن کاربر عادی عدد <code>0</code> را وارد کنید. اگر برای مسیر خاص درصد تعیین کنید، همان درصد اولویت دارد؛ اگر درصد مسیر نباشد ولی حجم ثابت مسیر باشد، همان حجم ثابت اعمال می‌شود.";

    $stmt = @$connection->prepare("SELECT * FROM `server_switch_costs` ORDER BY `from_server_id`, `to_server_id` LIMIT 20");
    if($stmt){
        $stmt->execute();
        $res = $stmt->get_result();
        if($res && $res->num_rows > 0){
            $txt .= "

📌 <b>تنظیمات اختصاصی مسیرها:</b>
";
            while($row = $res->fetch_assoc()){
                $label = v2raystore_switchRouteCostLabel($row);
                if($label === '') continue;
                $from = v2raystore_switchGetServerTitle($row['from_server_id']);
                $to = v2raystore_switchGetServerTitle($row['to_server_id']);
                $txt .= "• " . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . " ➜ " . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . ": <b>{$label}</b>
";
            }
        }
        $stmt->close();
    }
    return $txt;
}

function v2raystore_getSwitchSettingsMenuKeys(){
    $s = v2raystore_getServerSwitchSettings();
    $modeNames = ['auto'=>'خودکار', 'percent'=>'درصدی', 'manual'=>'دستی'];
    $modeText = $modeNames[$s['mode']] ?? 'خودکار';
    return v2raystore_inlineKeyboardJson([
        [
            ['text'=>'حالت: ' . $modeText, 'callback_data'=>'toggleSwitchCostMode', 'style'=>'primary'],
            ['text'=>'تغییر حالت محاسبه', 'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>v2raystore_switchFormatGb($s['percent']) . '%', 'callback_data'=>'editSwitchPercent', 'style'=>'primary'],
            ['text'=>'درصد عمومی', 'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>v2raystore_switchFormatGb($s['default_gb']) . ' GB', 'callback_data'=>'editSwitchDefaultGb', 'style'=>'primary'],
            ['text'=>'حجم ثابت دستی', 'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>v2raystore_switchFormatGb($s['min_gb']) . ' GB', 'callback_data'=>'editSwitchMinGb', 'style'=>'primary'],
            ['text'=>'حداقل کسر', 'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>v2raystore_switchDailyLimitText($s['daily_limit']), 'callback_data'=>'editSwitchDailyLimit', 'style'=>'primary'],
            ['text'=>'محدودیت روزانه کاربر', 'callback_data'=>'v2raystore']
        ],
        [
            ['text'=>'➕ درصد اختصاصی مسیر', 'callback_data'=>'selectSwitchPairPercentFrom', 'style'=>'success']
        ],
        [
            ['text'=>'➕ حجم ثابت اختصاصی مسیر', 'callback_data'=>'selectSwitchPairFrom', 'style'=>'success']
        ],
        [
            ['text'=>'🗑 حذف تنظیم اختصاصی مسیر', 'callback_data'=>'selectSwitchPairDeleteFrom', 'style'=>'danger']
        ],
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'botSettings']]
    ]);
}

function v2raystore_getSwitchPairFromKeys($deleteMode = false, $mode = 'gb'){
    global $connection;
    $res = @$connection->query("SELECT `id`, `title` FROM `server_info` WHERE `active` = 1 ORDER BY `id` DESC");
    $rows = [];
    if($res){
        while($row = $res->fetch_assoc()){
            $prefix = $deleteMode ? 'switchPairDeleteFrom' : ($mode === 'percent' ? 'switchPairPercentFrom' : 'switchPairFrom');
            $cb = $prefix . intval($row['id']);
            $rows[] = ['text'=>(string)$row['title'], 'callback_data'=>$cb];
        }
    }
    $keyboard = array_chunk($rows, 2);
    $keyboard[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'switchLocationSettings']];
    return v2raystore_inlineKeyboardJson($keyboard);
}

function v2raystore_getSwitchPairToKeys($fromServerId, $deleteMode = false, $mode = 'gb'){
    global $connection;
    $fromServerId = intval($fromServerId);
    $res = @$connection->query("SELECT `id`, `title` FROM `server_info` WHERE `active` = 1 AND `id` <> " . $fromServerId . " ORDER BY `id` DESC");
    $rows = [];
    if($res){
        while($row = $res->fetch_assoc()){
            $to = intval($row['id']);
            $label = (string)$row['title'];
            $current = v2raystore_switchRouteCostLabel($fromServerId, $to);
            if($current !== '') $label .= ' (' . $current . ')';
            $prefix = $deleteMode ? 'switchPairDeleteTo' : ($mode === 'percent' ? 'switchPairPercentTo' : 'switchPairTo');
            $cb = $prefix . $fromServerId . '_' . $to;
            $rows[] = ['text'=>$label, 'callback_data'=>$cb];
        }
    }
    $keyboard = array_chunk($rows, 2);
    $keyboard[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'switchLocationSettings']];
    return v2raystore_inlineKeyboardJson($keyboard);
}

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
        [['text'=>'🎯 انتخاب گروه مخاطب', 'callback_data'=>'v2raystore', 'style'=>'primary']],
        [['text'=>'🌍 پیام برای همه کاربران', 'callback_data'=>$prefix.'all', 'style'=>'success']],
        [['text'=>'✅ پیام برای کاربران دارای دسترسی', 'callback_data'=>$prefix.'approved', 'style'=>'primary']],
        [['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel']],
    ]], JSON_UNESCAPED_UNICODE);
}

function farid_getBroadcastThrottleSettings(){
    global $botState;
    $batchSize = intval($botState['broadcast_batch_size'] ?? 60);
    $delayMs = intval($botState['broadcast_delay_ms'] ?? 150);
    $maxRuntime = intval($botState['broadcast_max_runtime'] ?? 22);
    $progressInterval = intval($botState['broadcast_progress_interval'] ?? 120);

    if($batchSize < 1) $batchSize = 1;
    if($batchSize > 100) $batchSize = 100;
    if($delayMs < 80) $delayMs = 80;
    if($delayMs > 2000) $delayMs = 2000;
    if($maxRuntime < 8) $maxRuntime = 8;
    if($maxRuntime > 50) $maxRuntime = 50;
    if($progressInterval < 30) $progressInterval = 30;

    return [
        'batch_size' => $batchSize,
        'delay_ms' => $delayMs,
        'max_runtime' => $maxRuntime,
        'progress_interval' => $progressInterval,
    ];
}

function farid_formatBroadcastQueueText($sendInfo, $includeSettings = false){
    $offset = intval($sendInfo['offset'] ?? 0);
    $type = $sendInfo['type'] ?? 'text';
    $target = farid_normalizeBroadcastTarget($sendInfo['target_type'] ?? 'all');
    $usersCount = intval($sendInfo['total_count'] ?? 0);
    if($usersCount <= 0) $usersCount = farid_countBroadcastTargets($target);
    $leftMessages = max(0, $usersCount - $offset);
    $targetTitle = farid_getBroadcastTargetTitle($target);
    $sent = intval($sendInfo['sent_count'] ?? 0);
    $failed = intval($sendInfo['failed_count'] ?? 0);
    $blocked = intval($sendInfo['blocked_count'] ?? 0);
    $pauseUntil = intval($sendInfo['pause_until'] ?? 0);
    $statusLine = ($pauseUntil > time()) ? ("⏸ توقف موقت تا " . date('H:i:s', $pauseUntil)) : "🟢 در حال پردازش مرحله‌ای";
    $title = ($type == 'forwardall') ? 'فوروارد همگانی' : 'پیام همگانی';
    $doneLabel = ($type == 'forwardall') ? 'فوروارد شده' : 'ارسال شده';

    $txt = "❗️ یک $title در صف انتشار است.\n\n" .
           "$statusLine\n" .
           "🎯 گروه مخاطب: $targetTitle\n" .
           "🔰 تعداد مخاطبان: $usersCount\n" .
           "☑️ پردازش‌شده: $offset\n" .
           "📨 $doneLabel: $sent\n" .
           "⛔️ ناموفق: $failed\n" .
           "🚫 بلاک/غیرفعال: $blocked\n" .
           "📣 باقی‌مانده: $leftMessages";

    if($includeSettings){
        $st = farid_getBroadcastThrottleSettings();
        $txt .= "\n\n⚙️ تنظیمات فشار کنترل‌شده:\n" .
                "📦 هر اجرا: {$st['batch_size']} پیام\n" .
                "⏱ فاصله هر پیام: {$st['delay_ms']} میلی‌ثانیه\n" .
                "⌛️ حداکثر زمان هر اجرا: {$st['max_runtime']} ثانیه";
    }

    return $txt;
}

function farid_getActiveBroadcastQueue(){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` = 1 AND `type` != 'updateConfigs' ORDER BY `id` ASC LIMIT 1");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();
    if($info->num_rows <= 0) return null;
    return $info->fetch_assoc();
}

function farid_getActiveBroadcastQueueText(){
    $sendInfo = farid_getActiveBroadcastQueue();
    if(!$sendInfo) return null;
    return farid_formatBroadcastQueueText($sendInfo, false);
}

function farid_getBroadcastStatusKeyboard($sendId = 0){
    $sendId = intval($sendId);
    $rows = [];
    if($sendId > 0){
        $rows[] = [
            ['text'=>'🔄 بروزرسانی وضعیت', 'callback_data'=>'broadcastQueueStatus', 'style'=>'primary'],
            ['text'=>'🛑 توقف و حذف صف', 'callback_data'=>'broadcastQueueCancel' . $sendId, 'style'=>'danger']
        ];
    }else{
        $rows[] = [['text'=>'🔄 بروزرسانی وضعیت', 'callback_data'=>'broadcastQueueStatus', 'style'=>'primary']];
    }
    $rows[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_getUserByTelegramId($userId){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->num_rows > 0 ? $res->fetch_assoc() : null;
    $stmt->close();
    return $user;
}

function v2raystore_setUserApprovalStatus($userId, $status, $referrerId = null){
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

function v2raystore_createPendingUserIfNeeded($userId, $firstName, $userName){
    global $connection;
    $existing = v2raystore_getUserByTelegramId($userId);
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

    return v2raystore_getUserByTelegramId($userId);
}

function v2raystore_isUserApprovedForLock($userInfo){
    if(!$userInfo) return false;
    return !isset($userInfo['approval_status']) || $userInfo['approval_status'] == 'approved';
}

function v2raystore_getBotStatesArray($force = false){
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

function v2raystore_saveBotStatesArray($states){
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
    v2raystore_getBotStatesArray(true);
    return $ok;
}


function v2raystore_isAgentUser($user = null){
    if($user === null) $user = $GLOBALS['userInfo'] ?? null;
    return is_array($user) && !empty($user['is_agent']) && intval($user['is_agent']) === 1;
}

function v2raystore_effectiveRoleState($state, $baseKey, $agentKey, $user = null){
    if(!is_array($state)) $state = [];
    if(v2raystore_isAgentUser($user)){
        if(array_key_exists($agentKey, $state) && in_array($state[$agentKey], ['on','off'], true)){
            return $state[$agentKey];
        }
    }
    return (isset($state[$baseKey]) && $state[$baseKey] === 'on') ? 'on' : 'off';
}

function v2raystore_applyRoleSpecificStates($state, $user = null){
    if(!is_array($state)) $state = [];
    // برای جلوگیری از تغییر زیاد در سورس قدیمی، فقط در زمان اجرای درخواست همان کاربر
    // مقدارهای عمومی sellState/walletState با مقدار مخصوص نقش او جایگزین می‌شود.
    // برای ادمین‌ها و کاربران عادی رفتار قبلی حفظ می‌شود؛ برای نماینده‌ها می‌توان فروش/کیف پول جداگانه داشت.
    if(v2raystore_isAgentUser($user)){
        if(array_key_exists('agentSellState', $state) && in_array($state['agentSellState'], ['on','off'], true)){
            $state['sellState'] = $state['agentSellState'];
        }
        if(array_key_exists('agentWalletState', $state) && in_array($state['agentWalletState'], ['on','off'], true)){
            $state['walletState'] = $state['agentWalletState'];
        }
    }
    return $state;
}

function v2raystore_isWalletOpenForCurrentUser(){
    global $botState, $from_id, $admin, $userInfo;
    if($from_id == $admin || (!empty($userInfo) && !empty($userInfo['isAdmin']))) return true;
    return (($botState['walletState'] ?? 'off') === 'on');
}

function v2raystore_ensureBasicUserRecord($userId, $name = '', $username = ''){
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

function v2raystore_getNewMemberAccessMode($state = null){
    if($state === null) $state = v2raystore_getBotStatesArray();
    if(!is_array($state)) $state = [];
    $mode = $state['newMemberAccessMode'] ?? null;
    if(!in_array($mode, ['open','existing','buyers','approval'], true)){
        $mode = (($state['newMemberLockState'] ?? 'off') == 'on') ? 'approval' : 'open';
    }
    return $mode;
}

function v2raystore_newMemberAccessModeTitle($mode){
    switch($mode){
        case 'approval': return '🔐 تایید دستی با معرف';
        case 'buyers': return '🛒 فقط خریداران قبلی';
        case 'existing': return '👥 فقط کاربران قبلی ربات';
        default: return '🌍 آزاد برای همه';
    }
}

function v2raystore_setNewMemberAccessMode($mode){
    global $botState;
    if(!in_array($mode, ['open','existing','buyers','approval'], true)) $mode = 'open';
    $state = v2raystore_getBotStatesArray();
    $oldMode = v2raystore_getNewMemberAccessMode($state);
    $state['newMemberAccessMode'] = $mode;
    $state['newMemberLockState'] = ($mode === 'approval') ? 'on' : 'off';
    if($oldMode !== $mode || empty($state['newMemberAccessStartedAt'])){
        $state['newMemberAccessStartedAt'] = time();
    }
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return $state;
}


function v2raystore_getBuyersAccessCode($state = null){
    if($state === null) $state = v2raystore_getBotStatesArray();
    if(!is_array($state)) $state = [];
    return trim((string)($state['buyersAccessCode'] ?? ''));
}

function v2raystore_setBuyersAccessCode($code){
    global $botState;
    $code = trim((string)$code);
    // کد کوتاه/طولانی عجیب ذخیره نشود، ولی اجازه حروف، عدد، خط تیره و زیرخط داده می‌شود.
    $code = preg_replace('/[^A-Za-z0-9_\-]/', '', $code);
    $state = v2raystore_getBotStatesArray();
    $state['buyersAccessCode'] = $code;
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return $code;
}

function v2raystore_generateBuyersAccessCode(){
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'VIP-';
    for($i=0; $i<8; $i++){
        $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return v2raystore_setBuyersAccessCode($code);
}

function v2raystore_normalizeAccessCodeText($text){
    $text = trim((string)$text);
    if(preg_match('/^\/start\s+(.+)$/i', $text, $m)) $text = trim($m[1]);
    return preg_replace('/\s+/', '', $text);
}

function v2raystore_userIsAccessExempt($userInfo){
    return !empty($userInfo) && !empty($userInfo['access_exempt']);
}

function v2raystore_setUserAccessExempt($userId, $enabled = true, $code = null){
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

function v2raystore_getUserDisplayForAdmin($userId){
    $user = v2raystore_getUserByTelegramId($userId);
    $name = htmlspecialchars((string)($user['name'] ?? 'کاربر'), ENT_QUOTES, 'UTF-8');
    $username = trim((string)($user['username'] ?? ''));
    $username = $username !== '' ? '@' . ltrim($username, '@') : 'ندارد';
    return [$user, $name, htmlspecialchars($username, ENT_QUOTES, 'UTF-8')];
}

function v2raystore_getAccessCodeAdminActionKeys($userId){
    $userId = intval($userId);
    return v2raystore_inlineKeyboardJson([
        [['text'=>'🎟 مدیریت دسترسی کد ورود', 'callback_data'=>'v2raystore', 'style'=>'primary']],
        [
            ['text'=>'🧹 حذف دسترسی کد', 'callback_data'=>'revokeCodeAccess' . $userId, 'style'=>'danger'],
            ['text'=>'🚫 بلاک کاربر', 'callback_data'=>'blockCodeAccess' . $userId, 'style'=>'danger']
        ]
    ]);
}

function v2raystore_sendAccessCodeLoginNotice($userId, $code){
    $userId = intval($userId);
    [$user, $name, $usernameText] = v2raystore_getUserDisplayForAdmin($userId);
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
    foreach(v2raystore_getAllAdminIds() as $adminId){
        sendMessage($msg, v2raystore_getAccessCodeAdminActionKeys($userId), 'HTML', $adminId);
    }
}

function v2raystore_tryActivateAccessCode($userId, $text){
    $code = v2raystore_getBuyersAccessCode();
    if($code === '') return false;
    $sent = v2raystore_normalizeAccessCodeText($text);
    if($sent === '') return false;
    if(hash_equals(strtolower($code), strtolower($sent))){
        $ok = v2raystore_setUserAccessExempt($userId, true, $code);
        if($ok) v2raystore_sendAccessCodeLoginNotice($userId, $code);
        return $ok;
    }
    return false;
}

function v2raystore_userHasPreviousPurchase($userId){
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

function v2raystore_userIsExistingBeforeAccessMode($userInfo, $state = null){
    if(!$userInfo) return false;
    if($state === null) $state = v2raystore_getBotStatesArray();
    $startedAt = intval($state['newMemberAccessStartedAt'] ?? 0);
    $joinedAt = intval($userInfo['date'] ?? 0);
    if($startedAt <= 0) return true;
    return $joinedAt > 0 && $joinedAt <= $startedAt;
}

function v2raystore_newMemberAccessDeniedMessage($mode){
    if($mode === 'buyers'){
        return "🔒 در حال حاضر دسترسی به ربات فقط برای کاربرانی فعال است که قبلاً خرید ثبت‌شده داشته‌اند.\n\nاگر از مدیریت <b>کد ورود</b> دریافت کرده‌اید، لطفاً همان کد را در همین بخش ارسال کنید تا دسترسی شما فعال شود.\nدر صورت وجود هرگونه ابهام، لطفاً با پشتیبانی در ارتباط باشید.";
    }
    if($mode === 'existing'){
        return "🔒 در حال حاضر دسترسی به ربات فقط برای کاربران قبلی فعال است.\nاگر پیش‌تر عضو ربات بوده‌اید و اکنون دسترسی ندارید، لطفاً با پشتیبانی در ارتباط باشید.";
    }
    return "🔒 دسترسی شما هنوز فعال نشده است.";
}

function v2raystore_getNewMemberAccessMenuKeys(){
    $state = v2raystore_getBotStatesArray();
    $mode = v2raystore_getNewMemberAccessMode($state);
    $mark = function($m) use ($mode){ return $mode === $m ? '✅ ' : ''; };
    return v2raystore_inlineKeyboardJson([
        [['text'=>'🔖 وضعیت فعلی: ' . v2raystore_newMemberAccessModeTitle($mode), 'callback_data'=>'v2raystore', 'style'=>'primary']],
        [
            ['text'=>$mark('open') . '🌍 آزاد برای همه', 'callback_data'=>'setNewMemberAccessMode_open', 'style'=>'success'],
            ['text'=>$mark('existing') . '👥 فقط کاربران قبلی', 'callback_data'=>'setNewMemberAccessMode_existing', 'style'=>'primary']
        ],
        [
            ['text'=>$mark('buyers') . '🛒 فقط خریداران قبلی', 'callback_data'=>'setNewMemberAccessMode_buyers', 'style'=>'primary'],
            ['text'=>$mark('approval') . '🔐 تایید دستی با معرف', 'callback_data'=>'setNewMemberAccessMode_approval', 'style'=>'danger']
        ],
        [['text'=>'🎟 کد ورود خریداران: ' . (v2raystore_getBuyersAccessCode($state) !== '' ? v2raystore_getBuyersAccessCode($state) : 'تنظیم نشده'), 'callback_data'=>'v2raystore', 'style'=>'primary']],
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

function v2raystore_setUserJoinExempt($userId, $enabled = true){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $enabled = $enabled ? 1 : 0;
    $user = v2raystore_getUserByTelegramId($userId);
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

function v2raystore_getJoinExemptMenuKeys(){
    return v2raystore_inlineKeyboardJson([
        [['text'=>'🚪 مدیریت معافیت جوین اجباری', 'callback_data'=>'v2raystore', 'style'=>'primary']],
        [
            ['text'=>'➕ معاف کردن کاربر', 'callback_data'=>'addJoinExemptUser', 'style'=>'success'],
            ['text'=>'➖ حذف معافیت کاربر', 'callback_data'=>'removeJoinExemptUser', 'style'=>'danger']
        ],
        [['text'=>'📋 لیست کاربران معاف', 'callback_data'=>'joinExemptList', 'style'=>'primary']],
        [['text'=>'🔙 برگشت', 'callback_data'=>'newMemberAccessMenu', 'style'=>'primary']]
    ]);
}

function v2raystore_getJoinExemptListText(){
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

function v2raystore_getAllAdminIds(){
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

function v2raystore_sendNewMemberApprovalRequest($userId, $referrerId){
    global $first_name, $username;
    $refUser = v2raystore_getUserByTelegramId($referrerId);
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

    foreach(v2raystore_getAllAdminIds() as $adminId){
        sendMessage($msg, $keys, 'HTML', $adminId);
    }
}

function v2raystore_referrerInstructionMessage($rejected = false){
    $prefix = $rejected ? "❌ درخواست قبلی شما توسط مدیریت تایید نشد.\n\n" : "🔒 عضویت در ربات در حال حاضر نیازمند تایید مدیریت است.\n\n";
    return $prefix .
        "برای ثبت درخواست، لطفاً <b>آیدی عددی معرف خود</b> را ارسال کنید.\n\n" .
        "معرف شما می‌تواند آیدی عددی خود را از داخل ربات، از بخش <b>حساب من</b> / <b>اطلاعات حساب</b> دریافت کرده و برای شما ارسال کند.\n" .
        "لطفاً فقط عدد را ارسال کنید؛ نمونه: <code>123456789</code>";
}

function v2raystore_handleNewMemberLock(){
    global $connection, $from_id, $admin, $userInfo, $botState, $text, $data, $first_name, $username;

    $mode = v2raystore_getNewMemberAccessMode($botState);
    if($mode === 'open') return false;
    if($from_id == $admin || (!empty($userInfo) && !empty($userInfo['isAdmin']))) return false;

    $state = is_array($botState) ? $botState : [];
    $existingUser = $userInfo;
    if(!$existingUser){
        $existingUser = v2raystore_createPendingUserIfNeeded($from_id, $first_name, $username);
        $userInfo = $existingUser;
    }

    $plainText = trim((string)$text);
    if(v2raystore_userIsAccessExempt($existingUser)) return false;

    if($mode === 'existing'){
        if(v2raystore_userIsExistingBeforeAccessMode($existingUser, $state)) return false;
        sendMessage(v2raystore_newMemberAccessDeniedMessage('existing'), null, 'HTML');
        exit();
    }

    if($mode === 'buyers'){
        if(v2raystore_userHasPreviousPurchase($from_id)) return false;
        if(v2raystore_tryActivateAccessCode($from_id, $plainText)){
            sendMessage("✅ کد ورود با موفقیت تایید شد و دسترسی شما فعال گردید.

اکنون می‌توانید از امکانات ربات استفاده کنید.", getMainKeys(), 'HTML');
            exit();
        }
        sendMessage(v2raystore_newMemberAccessDeniedMessage('buyers'), null, 'HTML');
        exit();
    }

    // حالت تایید دستی با معرف؛ سازگار با قفل قبلی اعضای جدید
    if(v2raystore_isUserApprovedForLock($existingUser)) return false;

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
        v2raystore_setUserApprovalStatus($from_id, 'pending');
        sendMessage(v2raystore_referrerInstructionMessage(true), null, 'HTML');
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

        $refUser = v2raystore_getUserByTelegramId($referrerId);
        if(!$refUser || !v2raystore_isUserApprovedForLock($refUser)){
            sendMessage("❌ معرفی با این آیدی عددی پیدا نشد یا هنوز تایید نشده است.\nلطفاً آیدی عددی درست معرفتان را ارسال کنید.", null, 'HTML');
            exit();
        }

        v2raystore_setUserApprovalStatus($from_id, 'pending', $referrerId);
        v2raystore_sendNewMemberApprovalRequest($from_id, $referrerId);
        sendMessage("✅ درخواست شما برای ادمین ارسال شد.\nبعد از تایید، دسترسی شما به ربات فعال می‌شود.", null, 'HTML');
        exit();
    }

    setUser('newMemberEnterReferrer');
    sendMessage(v2raystore_referrerInstructionMessage(false), null, 'HTML');
    exit();
}

function v2raystore_extractHeaderPair($headerLine){
    $headerLine = trim((string)$headerLine);
    if($headerLine === '' || strpos($headerLine, ':') === false) return ['', ''];
    [$key, $value] = explode(':', $headerLine, 2);
    return [trim($key), trim($value)];
}

function v2raystore_buildHttpupgradeStreamSettings($security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType){
    [$headerKey, $headerValue] = v2raystore_extractHeaderPair($request_header);
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

function v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType){
    if($netType == 'tcp') return $tcpSettings;
    if($netType == 'httpupgrade') return v2raystore_buildHttpupgradeStreamSettings($security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
    return $wsSettings;
}


function v2raystore_normalizeSanaeiNewResponse($decoded, $serverType){
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

function v2raystore_sanaeiNewBaseUrlFromApiUrl($url){
    $base = preg_replace('#/panel/api/.*$#', '', (string)$url);
    return rtrim($base ?: $url, '/');
}

function v2raystore_sanaeiNewCsrfToken($curl, $baseUrl, $session){
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

function v2raystore_sanaeiNewHeaders($curl, $url, $session, $json = true){
    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $session
    );
    if($json) $headers[] = 'Content-Type: application/json';

    $csrf = v2raystore_sanaeiNewCsrfToken($curl, v2raystore_sanaeiNewBaseUrlFromApiUrl($url), $session);
    if($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    return $headers;
}

function v2raystore_sanaeiNewDecodePayloadJsonFields($payload){
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

function v2raystore_sanaeiNewJsonPost($curl, $url, $session, $payload = null){
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
        CURLOPT_HTTPHEADER => v2raystore_sanaeiNewHeaders($curl, $url, $session, true)
    ));
}


function v2raystore_normalizePanelSettingsArray($settings){
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

function v2raystore_sanaeiRequestJson($server_info, $endpoint, $method = 'GET', $payload = null){
    [$curl, $session] = v2raystore_panelLoginSession($server_info);
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
    $csrf = v2raystore_sanaeiNewCsrfToken(null, $panel, $session);
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

function v2raystore_sanaeiNewFindClientEmail($server_id, $uuid = '', $inbound_id = 0, $remark = ''){
    $remark = trim((string)$remark);
    $uuid = trim((string)$uuid);
    if($remark !== '') return $remark;
    if($uuid === '') return '';
    $json = getJson($server_id);
    if(!$json || empty($json->success) || !isset($json->obj) || !is_array($json->obj)) return '';
    foreach($json->obj as $row){
        if($inbound_id != 0 && intval($row->id ?? 0) != intval($inbound_id)) continue;
        $settings = v2raystore_decodeMaybeJson($row->settings ?? '{}', true);
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

function v2raystore_sanaeiNewClientLinksFromPanel($server_id, $email = '', $uuid = '', $inbound_id = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info || ($server_info['type'] ?? '') !== 'sanaei_new') return [];
    $email = v2raystore_sanaeiNewFindClientEmail($server_id, $uuid, $inbound_id, $email);
    if($email === '') return [];
    $decoded = v2raystore_sanaeiRequestJson($server_info, '/panel/api/clients/links/' . rawurlencode($email), 'GET');
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

function v2raystore_sanaeiNewSubLinksFromPanel($server_id, $subId){
    global $connection;
    $subId = trim((string)$subId);
    if($subId === '') return [];
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info || ($server_info['type'] ?? '') !== 'sanaei_new') return [];
    $decoded = v2raystore_sanaeiRequestJson($server_info, '/panel/api/clients/subLinks/' . rawurlencode($subId), 'GET');
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

function v2raystore_isPanelSubscriptionServer($serverType){
    return in_array($serverType, ['sanaei', 'sanaei_new'], true);
}

function v2raystore_decodeMaybeJson($value, $assoc = true){
    if(is_array($value) || is_object($value)) return $value;
    $decoded = json_decode((string)$value, $assoc);
    if(json_last_error() === JSON_ERROR_NONE) return $decoded;
    return $assoc ? [] : (object)[];
}

function v2raystore_arrayValue($arr, $key, $default = null){
    if(is_array($arr) && array_key_exists($key, $arr)) return $arr[$key];
    if(is_object($arr) && isset($arr->$key)) return $arr->$key;
    return $default;
}

function v2raystore_textContains($haystack, $needle){
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if($needle === '') return false;
    return stripos($haystack, $needle) !== false || strpos($haystack, $needle) !== false;
}

function v2raystore_buttonIsRealApproveAction($button){
    if(!is_array($button)) return false;
    $text = trim((string)($button['text'] ?? ''));
    $callback = strtolower(trim((string)($button['callback_data'] ?? '')));
    $plainText = trim(preg_replace('/^[✅☑️✔️\s]+/u', '', $text));

    // سبز فقط برای تاییدهای واقعی بماند. کلماتی مثل approved در فیلترها نباید سبز شوند.
    $approveCallbacks = [
        'accept', 'approvepayment', 'approverenewacc', 'approveincreaseday', 'approveincreasevolume',
        'approvenewmember', 'confirmswitchserver', 'adminhelpconfirmdelete', 'resetalltestaccountsconfirm'
    ];
    foreach($approveCallbacks as $prefix){
        if(strpos($callback, $prefix) === 0) return true;
    }

    if(preg_match('/^(تأیید|تایید|قبول|ثبت نهایی|بله،?\s*تأیید|بله،?\s*تایید|تأیید و|تایید و)/u', $plainText)) return true;
    return false;
}

function v2raystore_buttonHasVisibleAction($button){
    if(!is_array($button)) return false;
    $actionKeys = [
        'callback_data', 'url', 'web_app', 'login_url', 'switch_inline_query', 'switch_inline_query_current_chat',
        'switch_inline_query_chosen_chat', 'pay', 'copy_text', 'request_contact', 'request_location', 'request_poll'
    ];
    foreach($actionKeys as $key){
        if(array_key_exists($key, $button) && $button[$key] !== null && $button[$key] !== '') return true;
    }
    return false;
}

function v2raystore_buttonStyleByCallback($button){
    if(!is_array($button)) return $button;
    if(!isset($button['text'])) return $button;

    // فقط استایل‌های قابل قبول نگه داشته می‌شود تا دکمه‌ها به خاطر style اشتباه سفید/بی‌رنگ یا خراب نشوند.
    $allowedStyles = ['danger', 'success', 'primary'];
    $callback = (string)($button['callback_data'] ?? '');
    $text = (string)($button['text'] ?? '');
    $haystack = $text . ' ' . $callback;
    $hasAction = v2raystore_buttonHasVisibleAction($button);

    $dangerWords = ['delete', 'del', 'remove', 'ban', 'reject', 'disable', 'decrease', 'cancel', 'clear', 'off', 'stop', 'deny', 'decline', 'لغو', 'حذف', 'بن', 'مسدود', 'رد', 'غیرفعال', 'کاهش', 'پاک', 'خاموش', 'توقف', 'انصراف', '❌', '🗑', '🧹', '➖'];
    foreach($dangerWords as $w){
        if(v2raystore_textContains($haystack, $w)){
            $button['style'] = 'danger';
            return $button;
        }
    }

    if(v2raystore_buttonIsRealApproveAction($button)){
        $button['style'] = 'success';
        return $button;
    }

    if(isset($button['style'])){
        $button['style'] = strtolower(trim((string)$button['style']));
        if(!in_array($button['style'], $allowedStyles, true)){
            // style نامعتبر را به primary تبدیل می‌کنیم تا دکمه یک‌دفعه سفید نشود.
            $button['style'] = 'primary';
            return $button;
        }
        if($button['style'] === 'success' && !v2raystore_buttonIsRealApproveAction($button)){
            // فقط تایید واقعی سبز باشد؛ بقیه اکشن‌های مثبت آبی شوند.
            $button['style'] = 'primary';
        }
        return $button;
    }

    $primaryWords = ['buy', 'renew', 'increase', 'enable', 'pay', 'gift', 'join', 'gettest', 'add', 'generate', 'on', 'back', 'main', 'search', 'show', 'details', 'update', 'change', 'qr', 'sub', 'support', 'info', 'config', 'subscription', 'settings', 'menu', 'list', 'status', 'report', 'backup', 'domain', 'token', 'ssl', 'start', 'run', 'continue', 'خرید', 'تمدید', 'افزایش', 'شارژ', 'فعال', 'پرداخت', 'هدیه', 'عضویت', 'افزودن', 'معاف', 'ساخت', 'روشن', 'برگشت', 'بازگشت', 'جستجو', 'نمایش', 'جزئیات', 'آپدیت', 'بروزرسانی', 'به‌روزرسانی', 'تغییر', 'کیوآر', 'ساب', 'پشتیبانی', 'حساب', 'کانفیگ', 'اشتراک', 'تنظیم', 'مدیریت', 'لیست', 'وضعیت', 'گزارش', 'بکاپ', 'دامنه', 'توکن', 'شروع', 'ادامه', '➕', '🔄', '📊', '⚙️', '🛠'];
    foreach($primaryWords as $w){
        if(v2raystore_textContains($haystack, $w)){
            $button['style'] = 'primary';
            return $button;
        }
    }

    // از این به بعد هر دکمه‌ای که واقعاً اکشن دارد، primary می‌گیرد تا مشکل سفید شدن تصادفی رفع شود.
    // فقط دکمه‌های کاملاً نمایشی/بدون اکشن بی‌رنگ می‌مانند.
    if($hasAction){
        $button['style'] = 'primary';
        return $button;
    }

    unset($button['style']);
    return $button;
}


function v2raystore_styleInlineKeyboard($keyboard){
    if(!is_array($keyboard)) return $keyboard;
    $out = [];
    foreach($keyboard as $row){
        if(!is_array($row) || count($row) === 0) continue;
        $newRow = [];
        foreach($row as $button){
            if(is_array($button) && isset($button['text'])) $newRow[] = v2raystore_buttonStyleByCallback($button);
        }
        if(count($newRow) > 0) $out[] = $newRow;
    }
    return $out;
}

function v2raystore_styleReplyKeyboardButton($button){
    if(is_string($button)) $button = ['text' => $button];
    if(!is_array($button) || !isset($button['text'])) return $button;
    return v2raystore_buttonStyleByCallback($button);
}

function v2raystore_styleReplyKeyboard($keyboard){
    if(!is_array($keyboard)) return $keyboard;
    $out = [];
    foreach($keyboard as $row){
        if(!is_array($row)) continue;
        $newRow = [];
        foreach($row as $button){
            $newRow[] = v2raystore_styleReplyKeyboardButton($button);
        }
        $out[] = $newRow;
    }
    return $out;
}

function v2raystore_styleReplyMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;

    if(isset($decoded['inline_keyboard']) && is_array($decoded['inline_keyboard'])){
        $decoded['inline_keyboard'] = v2raystore_styleInlineKeyboard($decoded['inline_keyboard']);
    }
    if(isset($decoded['keyboard']) && is_array($decoded['keyboard'])){
        $decoded['keyboard'] = v2raystore_styleReplyKeyboard($decoded['keyboard']);
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_inlineKeyboardJson($keyboard){
    return json_encode(['inline_keyboard' => v2raystore_styleInlineKeyboard($keyboard)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


function v2raystore_helpTypeConfig($type){
    $type = (string)$type;
    if($type === 'tutorial'){
        return [
            'type' => 'tutorial',
            'setting' => 'V2RAYSTORE_MANAGED_TUTORIALS',
            'title' => 'آموزش‌های اتصال',
            'icon' => '📚',
            'menu_callback' => 'tutorialsMenu',
            'item_prefix' => 'helpTutItem_',
            'admin_list' => 'adminHelpList_tutorial',
        ];
    }
    return [
        'type' => 'faq',
        'setting' => 'V2RAYSTORE_MANAGED_FAQ',
        'title' => 'سوالات متداول',
        'icon' => '❓',
        'menu_callback' => 'faqMenu',
        'item_prefix' => 'helpFaqItem_',
        'admin_list' => 'adminHelpList_faq',
    ];
}

function v2raystore_helpGetSetting($key){
    global $connection;
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (string)($row['value'] ?? '') : null;
}

function v2raystore_helpSetSetting($key, $value){
    global $connection;
    $value = (string)$value;
    $stmt = @$connection->prepare("SELECT `id` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();
    if($exists){
        $stmt = @$connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $value, $key);
    }else{
        $stmt = @$connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $key, $value);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}


function v2raystore_helpLimitText($text, $max){
    $text = trim((string)$text);
    $max = max(1, intval($max));
    if(function_exists('mb_strlen')){
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
    }
    return strlen($text) > $max ? substr($text, 0, $max) : $text;
}

function v2raystore_helpDefaultItems($type){
    if($type === 'tutorial'){
        return [
            ['id'=>1, 'title'=>'Android - V2rayNG / Hiddify', 'body'=>"1) برنامه V2rayNG یا Hiddify را نصب کنید.\n2) لینک کانفیگ را کپی کنید.\n3) داخل برنامه گزینه Import from Clipboard را بزنید.\n4) کانفیگ را انتخاب و اتصال را روشن کنید.", 'enabled'=>true],
            ['id'=>2, 'title'=>'iOS - Streisand / Hiddify', 'body'=>"1) برنامه Streisand یا Hiddify را نصب کنید.\n2) لینک کانفیگ را کپی کنید.\n3) داخل برنامه از بخش Import، گزینه Clipboard را انتخاب کنید.\n4) کانفیگ را انتخاب و متصل شوید.", 'enabled'=>true],
            ['id'=>3, 'title'=>'Windows - Hiddify / Nekoray', 'body'=>"1) برنامه Hiddify یا Nekoray را نصب کنید.\n2) لینک کانفیگ را کپی کنید.\n3) داخل برنامه Import from Clipboard را بزنید.\n4) کانفیگ را فعال و متصل شوید.", 'enabled'=>true],
            ['id'=>4, 'title'=>'Nekobox / Nekoray', 'body'=>"لینک کانفیگ را کپی کنید، وارد برنامه شوید و از قسمت Import گزینه Clipboard را بزنید. بعد از اضافه شدن کانفیگ، آن را انتخاب و Start کنید.", 'enabled'=>true],
        ];
    }
    return [
        ['id'=>1, 'title'=>'بعد از خرید چه کاری انجام بدهم؟', 'body'=>'بعد از تأیید پرداخت، لینک کانفیگ برای شما ارسال می‌شود. لینک را کپی کرده و طبق بخش آموزش اتصال، وارد برنامه کنید.', 'enabled'=>true],
        ['id'=>2, 'title'=>'اگر کانفیگ وصل نشد چه کنم؟', 'body'=>'اول لینک را از بخش کانفیگ‌های من بروزرسانی کنید. اگر مشکل حل نشد، از بخش پشتیبانی پیام بدهید و نام کانفیگ را ارسال کنید.', 'enabled'=>true],
        ['id'=>3, 'title'=>'آیا امکان تمدید یا افزایش حجم وجود دارد؟', 'body'=>'بله، از بخش کانفیگ‌های من وارد جزئیات سرویس شوید و گزینه تمدید، افزایش حجم یا افزایش زمان را انتخاب کنید.', 'enabled'=>true],
    ];
}

function v2raystore_helpSanitizeItems($items, $type = 'faq', $useDefaultWhenEmpty = true){
    if(!is_array($items)) $items = [];
    $out = [];
    $used = [];
    foreach($items as $row){
        if(!is_array($row)) continue;
        $id = intval($row['id'] ?? 0);
        if($id <= 0){
            $id = 1;
            while(isset($used[$id])) $id++;
        }
        while(isset($used[$id])) $id++;
        $title = trim((string)($row['title'] ?? ''));
        $body = trim((string)($row['body'] ?? ''));
        if($title === '' || $body === '') continue;
        $out[] = [
            'id' => $id,
            'title' => v2raystore_helpLimitText($title, 120),
            'body' => v2raystore_helpLimitText($body, 3900),
            'enabled' => !isset($row['enabled']) || !empty($row['enabled'])
        ];
        $used[$id] = true;
    }
    if(count($out) === 0 && $useDefaultWhenEmpty) $out = v2raystore_helpDefaultItems($type);
    return $out;
}

function v2raystore_helpGetItems($type, $includeDisabled = true){
    $cfg = v2raystore_helpTypeConfig($type);
    $raw = v2raystore_helpGetSetting($cfg['setting']);
    $items = null;
    $hasSavedList = false;
    if($raw !== null && trim($raw) !== ''){
        $decoded = json_decode($raw, true);
        if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)){
            $items = $decoded;
            $hasSavedList = true;
        }
    }
    if(!is_array($items)) $items = v2raystore_helpDefaultItems($cfg['type']);
    $items = v2raystore_helpSanitizeItems($items, $cfg['type'], !$hasSavedList);
    if(!$includeDisabled){
        $items = array_values(array_filter($items, function($row){ return !empty($row['enabled']); }));
    }
    return $items;
}

function v2raystore_helpSaveItems($type, $items){
    $cfg = v2raystore_helpTypeConfig($type);
    $items = v2raystore_helpSanitizeItems($items, $cfg['type'], false);
    return v2raystore_helpSetSetting($cfg['setting'], json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function v2raystore_helpNextItemId($items){
    $max = 0;
    foreach((array)$items as $row) $max = max($max, intval($row['id'] ?? 0));
    return $max + 1;
}

function v2raystore_helpFindItem($type, $id){
    foreach(v2raystore_helpGetItems($type, true) as $row){
        if(intval($row['id']) === intval($id)) return $row;
    }
    return null;
}

function v2raystore_helpUpdateItem($type, $id, $fields){
    $items = v2raystore_helpGetItems($type, true);
    foreach($items as &$row){
        if(intval($row['id']) === intval($id)){
            foreach((array)$fields as $k => $v){
                if($k === 'title') $row['title'] = v2raystore_helpLimitText($v, 120);
                elseif($k === 'body') $row['body'] = v2raystore_helpLimitText($v, 3900);
                elseif($k === 'enabled') $row['enabled'] = !empty($v);
            }
            break;
        }
    }
    unset($row);
    return v2raystore_helpSaveItems($type, $items);
}

function v2raystore_helpDeleteItem($type, $id){
    $items = [];
    foreach(v2raystore_helpGetItems($type, true) as $row){
        if(intval($row['id']) !== intval($id)) $items[] = $row;
    }
    return v2raystore_helpSaveItems($type, $items);
}

function v2raystore_helpAddItem($type, $title, $body){
    $items = v2raystore_helpGetItems($type, true);
    $items[] = ['id'=>v2raystore_helpNextItemId($items), 'title'=>$title, 'body'=>$body, 'enabled'=>true];
    return v2raystore_helpSaveItems($type, $items);
}

function v2raystore_helpUserMenuText($type){
    $cfg = v2raystore_helpTypeConfig($type);
    $items = v2raystore_helpGetItems($cfg['type'], false);
    $msg = $cfg['icon'] . " <b>" . v2raystore_h($cfg['title']) . "</b>\n\n";
    if(count($items) === 0){
        $msg .= "فعلاً موردی توسط مدیریت ثبت نشده است.";
    }else{
        $msg .= "لطفاً یکی از موارد زیر را انتخاب کنید:";
    }
    if($cfg['type'] === 'tutorial'){
        $msg .= "\n\n📌 لینک‌های دانلود برنامه‌ها هم پایین همین بخش نمایش داده می‌شوند.";
    }
    return $msg;
}

function v2raystore_helpUserMenuKeys($type){
    global $connection, $buttonValues;
    $cfg = v2raystore_helpTypeConfig($type);
    $rows = [];
    foreach(v2raystore_helpGetItems($cfg['type'], false) as $row){
        $rows[] = [[
            'text' => ($cfg['type'] === 'faq' ? '❓ ' : '📚 ') . $row['title'],
            'callback_data' => $cfg['item_prefix'] . intval($row['id']),
            'style' => 'primary'
        ]];
    }
    if($cfg['type'] === 'tutorial'){
        $stmt = @$connection->prepare("SELECT `title`, `link` FROM `needed_sofwares` WHERE `status`=1");
        if($stmt){
            $stmt->execute();
            $res = $stmt->get_result();
            while($res && ($file = $res->fetch_assoc())){
                $title = trim((string)($file['title'] ?? ''));
                $link = trim((string)($file['link'] ?? ''));
                if($title !== '' && preg_match('/^https?:\/\//i', $link)){
                    $rows[] = [[ 'text' => '⬇️ ' . $title, 'url' => $link ]];
                }
            }
            $stmt->close();
        }
    }
    $rows[] = [[ 'text' => $buttonValues['back_to_main'] ?? 'بازگشت به منو', 'callback_data' => 'mainMenu', 'style' => 'primary' ]];
    return v2raystore_inlineKeyboardJson($rows);
}

function v2raystore_helpUserItemText($type, $id){
    $cfg = v2raystore_helpTypeConfig($type);
    $item = v2raystore_helpFindItem($cfg['type'], $id);
    if(!$item || empty($item['enabled'])) return "این مورد پیدا نشد یا غیرفعال شده است.";
    return $cfg['icon'] . " <b>" . v2raystore_h($item['title']) . "</b>\n\n" . v2raystore_h($item['body']);
}

function v2raystore_helpUserItemKeys($type){
    $cfg = v2raystore_helpTypeConfig($type);
    return v2raystore_inlineKeyboardJson([
        [[ 'text' => '🔙 برگشت به ' . $cfg['title'], 'callback_data' => $cfg['menu_callback'], 'style' => 'primary' ]],
        [[ 'text' => '🏠 منوی اصلی', 'callback_data' => 'mainMenu', 'style' => 'primary' ]]
    ]);
}

function v2raystore_helpAdminHomeText(){
    return "📚 <b>مدیریت FAQ و آموزش‌ها</b>\n\nاز این بخش می‌توانید سوالات متداول و آموزش‌های اتصال را بدون تغییر فایل، از داخل ربات مدیریت کنید.\n\n• سوالات متداول در منوی کاربر نمایش داده می‌شود.\n• آموزش‌ها داخل بخش راهنمای اتصال/لینک برنامه‌ها نمایش داده می‌شود.";
}

function v2raystore_helpAdminHomeKeys(){
    global $buttonValues;
    return v2raystore_inlineKeyboardJson([
        [[ 'text'=>'❓ مدیریت سوالات متداول', 'callback_data'=>'adminHelpList_faq', 'style'=>'primary' ]],
        [[ 'text'=>'📚 مدیریت آموزش‌های اتصال', 'callback_data'=>'adminHelpList_tutorial', 'style'=>'primary' ]],
        [[ 'text'=>$buttonValues['back_button'] ?? '🔙 برگشت', 'callback_data'=>'managePanel', 'style'=>'primary' ]]
    ]);
}

function v2raystore_helpAdminListText($type){
    $cfg = v2raystore_helpTypeConfig($type);
    $items = v2raystore_helpGetItems($cfg['type'], true);
    $msg = $cfg['icon'] . " <b>مدیریت " . v2raystore_h($cfg['title']) . "</b>\n\n";
    if(count($items) === 0) return $msg . "موردی ثبت نشده است.";
    foreach($items as $i => $row){
        $msg .= ($i + 1) . ". " . (!empty($row['enabled']) ? '✅' : '🚫') . " <b>" . v2raystore_h($row['title']) . "</b>\n";
    }
    $msg .= "\nروی هر مورد بزنید تا ویرایش شود.";
    return $msg;
}

function v2raystore_helpAdminListKeys($type){
    $cfg = v2raystore_helpTypeConfig($type);
    $rows = [];
    foreach(v2raystore_helpGetItems($cfg['type'], true) as $row){
        $rows[] = [[
            'text' => (!empty($row['enabled']) ? '✅ ' : '🚫 ') . $row['title'],
            'callback_data' => 'adminHelpItem_' . $cfg['type'] . '_' . intval($row['id']),
            'style' => 'primary'
        ]];
    }
    $rows[] = [[ 'text'=>'➕ افزودن مورد جدید', 'callback_data'=>'adminHelpAdd_' . $cfg['type'], 'style'=>'primary' ]];
    $rows[] = [[ 'text'=>'🔙 برگشت', 'callback_data'=>'adminHelpMenu', 'style'=>'primary' ]];
    return v2raystore_inlineKeyboardJson($rows);
}

function v2raystore_helpAdminItemText($type, $id){
    $cfg = v2raystore_helpTypeConfig($type);
    $item = v2raystore_helpFindItem($cfg['type'], $id);
    if(!$item) return "مورد پیدا نشد.";
    $msg = $cfg['icon'] . " <b>ویرایش مورد</b>\n\n";
    $msg .= "عنوان: <b>" . v2raystore_h($item['title']) . "</b>\n";
    $msg .= "وضعیت: " . (!empty($item['enabled']) ? '✅ فعال' : '🚫 غیرفعال') . "\n\n";
    $msg .= "متن فعلی:\n" . v2raystore_h($item['body']);
    return $msg;
}

function v2raystore_helpAdminItemKeys($type, $id){
    $cfg = v2raystore_helpTypeConfig($type);
    $item = v2raystore_helpFindItem($cfg['type'], $id);
    $enabled = $item ? !empty($item['enabled']) : false;
    return v2raystore_inlineKeyboardJson([
        [
            [ 'text'=>'✏️ عنوان', 'callback_data'=>'adminHelpEditTitle_' . $cfg['type'] . '_' . intval($id), 'style'=>'primary' ],
            [ 'text'=>'📝 متن', 'callback_data'=>'adminHelpEditText_' . $cfg['type'] . '_' . intval($id), 'style'=>'primary' ]
        ],
        [[ 'text'=>($enabled ? '🚫 غیرفعال کردن' : '✅ فعال کردن'), 'callback_data'=>'adminHelpToggle_' . $cfg['type'] . '_' . intval($id), 'style'=>($enabled ? 'danger' : 'primary') ]],
        [[ 'text'=>'🗑 حذف', 'callback_data'=>'adminHelpDelete_' . $cfg['type'] . '_' . intval($id), 'style'=>'danger' ]],
        [[ 'text'=>'🔙 برگشت به لیست', 'callback_data'=>$cfg['admin_list'], 'style'=>'primary' ]]
    ]);
}

function v2raystore_helpAdminDeleteKeys($type, $id){
    $cfg = v2raystore_helpTypeConfig($type);
    return v2raystore_inlineKeyboardJson([
        [[ 'text'=>'✅ تأیید حذف', 'callback_data'=>'adminHelpConfirmDelete_' . $cfg['type'] . '_' . intval($id), 'style'=>'success' ]],
        [[ 'text'=>'🔙 انصراف', 'callback_data'=>'adminHelpItem_' . $cfg['type'] . '_' . intval($id), 'style'=>'primary' ]]
    ]);
}


function v2raystore_defaultUserButtonVisibilityKeys(){
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
        'faq' => true,
        'my_tickets' => true,
        'search_config' => true,
        'refresh_panel' => true,
    ];
}

function v2raystore_getUserButtonVisibility($state = null){
    if($state === null) $state = v2raystore_getBotStatesArray();
    $defaults = v2raystore_defaultUserButtonVisibilityKeys();
    $saved = is_array($state) && isset($state['userButtonVisibility']) && is_array($state['userButtonVisibility']) ? $state['userButtonVisibility'] : [];
    foreach($defaults as $key => $value){
        if(!array_key_exists($key, $saved)) $saved[$key] = true;
        else $saved[$key] = (bool)$saved[$key];
    }
    return $saved;
}

function v2raystore_userButtonVisible($key, $state = null){
    $vis = v2raystore_getUserButtonVisibility($state);
    return !array_key_exists($key, $vis) || $vis[$key];
}

function v2raystore_setUserButtonVisible($key, $visible){
    global $botState;
    $defaults = v2raystore_defaultUserButtonVisibilityKeys();
    if(!array_key_exists($key, $defaults)) return false;
    $state = v2raystore_getBotStatesArray();
    $vis = v2raystore_getUserButtonVisibility($state);
    $vis[$key] = $visible ? true : false;
    $state['userButtonVisibility'] = $vis;
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function v2raystore_setAllUserButtonsVisible($visible){
    global $botState;
    $state = v2raystore_getBotStatesArray();
    $vis = v2raystore_defaultUserButtonVisibilityKeys();
    foreach($vis as $key => $_) $vis[$key] = $visible ? true : false;
    $state['userButtonVisibility'] = $vis;
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
}

function v2raystore_defaultUserButtonOrder(){
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
        'faq',
        'my_tickets',
        'search_config',
        'refresh_panel',
    ];
}

function v2raystore_getUserButtonOrder($state = null){
    if($state === null) $state = v2raystore_getBotStatesArray();
    $defaults = v2raystore_defaultUserButtonOrder();
    $allowed = array_values(array_keys(v2raystore_defaultUserButtonVisibilityKeys()));
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

function v2raystore_saveUserButtonOrder($order){
    global $botState;
    $state = v2raystore_getBotStatesArray();
    $state['userButtonOrder'] = v2raystore_getUserButtonOrder(['userButtonOrder' => $order]);
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function v2raystore_moveUserButtonOrder($key, $direction){
    $key = (string)$key;
    $direction = (string)$direction;
    $order = v2raystore_getUserButtonOrder();
    $index = array_search($key, $order, true);
    if($index === false) return false;
    $target = ($direction === 'up') ? $index - 1 : (($direction === 'down') ? $index + 1 : $index);
    if($target < 0 || $target >= count($order) || $target === $index) return false;
    $tmp = $order[$target];
    $order[$target] = $order[$index];
    $order[$index] = $tmp;
    return v2raystore_saveUserButtonOrder($order);
}

function v2raystore_resetUserButtonOrder(){
    return v2raystore_saveUserButtonOrder(v2raystore_defaultUserButtonOrder());
}

function v2raystore_getUserButtonRowBreaks($state = null){
    if($state === null) $state = v2raystore_getBotStatesArray();
    $allowed = array_values(array_keys(v2raystore_defaultUserButtonVisibilityKeys()));
    $saved = is_array($state) && isset($state['userButtonRowBreaks']) && is_array($state['userButtonRowBreaks']) ? $state['userButtonRowBreaks'] : [];
    $breaks = [];
    foreach($allowed as $key){
        $breaks[$key] = !empty($saved[$key]);
    }
    return $breaks;
}

function v2raystore_userButtonBreakAfter($key, $state = null){
    $breaks = v2raystore_getUserButtonRowBreaks($state);
    return !empty($breaks[$key]);
}

function v2raystore_setUserButtonRowBreak($key, $enabled){
    global $botState;
    $key = (string)$key;
    $defaults = v2raystore_defaultUserButtonVisibilityKeys();
    if(!array_key_exists($key, $defaults)) return false;
    $state = v2raystore_getBotStatesArray();
    $breaks = v2raystore_getUserButtonRowBreaks($state);
    $breaks[$key] = $enabled ? true : false;
    $state['userButtonRowBreaks'] = $breaks;
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return true;
}

function v2raystore_toggleUserButtonRowBreak($key){
    return v2raystore_setUserButtonRowBreak($key, !v2raystore_userButtonBreakAfter($key));
}

function v2raystore_resetUserButtonRowBreaks(){
    global $botState;
    $state = v2raystore_getBotStatesArray();
    $breaks = v2raystore_getUserButtonRowBreaks([]);
    $state['userButtonRowBreaks'] = $breaks;
    v2raystore_saveBotStatesArray($state);
    $botState = $state;
    return true;
}


function v2raystore_salesStateBlockReason($kind = 'new', $agentContext = null){
    global $botState, $userInfo;
    $state = v2raystore_getBotStatesArray();
    if(!is_array($state) || empty($state)) $state = is_array($botState) ? $botState : [];

    if($agentContext === null){
        $agentContext = v2raystore_isAgentUser($userInfo);
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
    if(!$agentContext && $kind === 'new' && !v2raystore_userButtonVisible('buy_subscriptions', $state)) return 'buy_button_off';
    return '';
}

function v2raystore_purchaseBlockedMessage($reason = ''){
    if($reason === 'buy_button_off'){
        return "🔒 بخش خرید کانفیگ جدید در حال حاضر توسط مدیریت غیرفعال شده است.\n\nدر صورت نیاز، لطفاً از بخش پشتیبانی با مدیریت در ارتباط باشید.";
    }
    return "🔒 فروش خدمات در حال حاضر توسط مدیریت غیرفعال شده است.\n\nتا زمان فعال‌سازی مجدد فروش، امکان ثبت خرید، تمدید یا افزایش حجم و زمان وجود ندارد.";
}

function v2raystore_isConfigPayType($payType){
    $payType = (string)$payType;
    if($payType === 'BUY_SUB' || $payType === 'RENEW_ACCOUNT' || $payType === 'RENEW_SCONFIG') return true;
    if(preg_match('/^INCREASE_(DAY|VOLUME)_/', $payType)) return true;
    return false;
}

function v2raystore_purchaseKindFromPayType($payType){
    $payType = (string)$payType;
    if($payType === 'BUY_SUB') return 'new';
    if(v2raystore_isConfigPayType($payType)) return 'paid';
    return 'none';
}

function v2raystore_getPayTypeByHash($hash){
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

function v2raystore_salesBlockReasonForPayType($payType){
    $kind = v2raystore_purchaseKindFromPayType($payType);
    if($kind === 'none') return '';
    return v2raystore_salesStateBlockReason($kind);
}

function v2raystore_extractPaymentHashFromAction($value){
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

function v2raystore_purchaseActionBlockReason($callbackData = '', $userStep = ''){
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
        if($callbackData !== '' && preg_match($pattern, $callbackData)) return v2raystore_salesStateBlockReason('new');
    }
    foreach($newStepPatterns as $pattern){
        if($userStep !== '' && preg_match($pattern, $userStep)) return v2raystore_salesStateBlockReason('new');
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
        if($callbackData !== '' && preg_match($pattern, $callbackData)) return v2raystore_salesStateBlockReason('paid');
    }
    foreach($paidStepPatterns as $pattern){
        if($userStep !== '' && preg_match($pattern, $userStep)) return v2raystore_salesStateBlockReason('paid');
    }

    foreach([$callbackData, $userStep] as $value){
        $hash = v2raystore_extractPaymentHashFromAction($value);
        if($hash === '') continue;
        $payType = v2raystore_getPayTypeByHash($hash);
        if($payType !== ''){
            $reason = v2raystore_salesBlockReasonForPayType($payType);
            if($reason !== '') return $reason;
        }else{
            if(preg_match('/^(payCustomWithWallet|payCustomWithCartToCart)/', $value)) return v2raystore_salesStateBlockReason('new');
            if(preg_match('/^(payRenew|payIncrease|payIncrase)/', $value)) return v2raystore_salesStateBlockReason('paid');
        }
    }

    return '';
}

function v2raystore_stopPurchaseIfBlocked($callbackData = '', $userStep = ''){
    global $message_id, $removeKeyboard, $buttonValues;
    $reason = v2raystore_purchaseActionBlockReason($callbackData, $userStep);
    if($reason === '') return false;
    setUser();
    $msg = v2raystore_purchaseBlockedMessage($reason);
    if(trim((string)$callbackData) !== ''){
        alert($msg, true);
        if(!empty($message_id)) editText($message_id, $msg, json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'] ?? 'بازگشت', 'callback_data'=>'mainMenu', 'style'=>'primary']]]], JSON_UNESCAPED_UNICODE), 'HTML');
    }else{
        sendMessage($msg, $removeKeyboard, 'HTML');
        sendMessage($GLOBALS['mainValues']['reached_main_menu'] ?? 'منوی اصلی', getMainKeys(), 'HTML');
    }
    return true;
}

function v2raystore_userButtonTitles(){
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
        'faq' => '❓ سوالات متداول',
        'my_tickets' => $buttonValues['my_tickets'] ?? 'تیکت‌های من',
        'search_config' => $buttonValues['search_config'] ?? 'مشخصات کانفیگ',
        'refresh_panel' => '🔄 بروزرسانی پنل',
    ];
}

function v2raystore_getUserButtonSettingsKeys(){
    $titles = v2raystore_userButtonTitles();
    $vis = v2raystore_getUserButtonVisibility();
    $order = v2raystore_getUserButtonOrder();
    $keys = [];
    $keys[] = [['text'=>'🎛 تنظیمات دکمه‌های کاربر', 'callback_data'=>'v2raystore', 'style'=>'primary']];
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
    return v2raystore_inlineKeyboardJson($keys);
}

function v2raystore_getUserButtonOrderText(){
    $titles = v2raystore_userButtonTitles();
    $order = v2raystore_getUserButtonOrder();
    $vis = v2raystore_getUserButtonVisibility();
    $breaks = v2raystore_getUserButtonRowBreaks();
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

function v2raystore_getUserButtonOrderSettingsKeys(){
    $titles = v2raystore_userButtonTitles();
    $order = v2raystore_getUserButtonOrder();
    $breaks = v2raystore_getUserButtonRowBreaks();
    $keys = [];
    $keys[] = [['text'=>'↕️ ترتیب دکمه‌های کاربر', 'callback_data'=>'v2raystore', 'style'=>'primary']];
    $total = count($order);
    $i = 1;
    foreach($order as $key){
        if(!isset($titles[$key])) continue;
        $title = $titles[$key];
        $keys[] = [[
            'text' => $i . '. ' . $title,
            'callback_data' => 'v2raystore',
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
    return v2raystore_inlineKeyboardJson($keys);
}


function v2raystore_getPaymentKeys(){
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

function v2raystore_savePaymentKeys($paymentKeys){
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

function v2raystore_getCardInfoVersion($paymentKeys = null){
    if($paymentKeys === null) $paymentKeys = v2raystore_getPaymentKeys();
    $v = intval($paymentKeys['cardInfoVersion'] ?? 1);
    return $v > 0 ? $v : 1;
}

function v2raystore_userHasActivePaidConfig($userId){
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

function v2raystore_getCartToCartAccountForUser($userId = null, $paymentKeys = null){
    global $from_id, $userInfo;
    if($paymentKeys === null) $paymentKeys = v2raystore_getPaymentKeys();
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

    $hasActivePaid = v2raystore_userHasActivePaidConfig($userId);
    $useSecond = ($hasActivePaid && $secondBank !== '');

    return [
        'bank' => $useSecond ? $secondBank : $primaryBank,
        'holder' => $useSecond ? $secondHolder : $primaryHolder,
        'type' => $useSecond ? 'second' : 'first',
        'is_second' => $useSecond,
        'has_active_paid_config' => $hasActivePaid,
    ];
}

function v2raystore_cartToCartAccountTitle($account){
    return (!empty($account['is_second'])) ? 'خرید دوم و بعدی' : 'خرید اول';
}

function v2raystore_markCardInfoChanged(){
    $keys = v2raystore_getPaymentKeys();
    $keys['cardInfoVersion'] = time();
    return v2raystore_savePaymentKeys($keys);
}

function v2raystore_cardContactRaw($paymentKeys = null){
    global $admin;
    if($paymentKeys === null) $paymentKeys = v2raystore_getPaymentKeys();
    $raw = trim((string)($paymentKeys['cardContact'] ?? ''));
    return $raw !== '' ? $raw : (string)$admin;
}

function v2raystore_cardContactUrl($paymentKeys = null){
    $raw = v2raystore_cardContactRaw($paymentKeys);
    if($raw === '') return '';
    if(preg_match('/^https?:\/\//i', $raw) || preg_match('/^tg:\/\//i', $raw)) return $raw;
    if(preg_match('/^-?\d+$/', $raw)) return 'tg://user?id=' . $raw;
    return 'https://t.me/' . ltrim($raw, '@');
}

function v2raystore_cardContactDisplay($paymentKeys = null){
    $raw = v2raystore_cardContactRaw($paymentKeys);
    if($raw === '') return 'ادمین';
    if(preg_match('/^-?\d+$/', $raw)) return '<code>' . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</code>';
    return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
}

function v2raystore_userHasCardVersion($userInfo, $paymentKeys = null){
    if(!$userInfo) return false;
    return intval($userInfo['card_info_version'] ?? 0) >= v2raystore_getCardInfoVersion($paymentKeys);
}

function v2raystore_markUserCardVersion($userId, $paymentKeys = null){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $version = v2raystore_getCardInfoVersion($paymentKeys);
    $stmt = $connection->prepare("UPDATE `users` SET `card_info_version` = ? WHERE `userid` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('ii', $version, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_cartToCartKeyboard($hashId = ''){
    $rows = [];
    $hashId = trim((string)$hashId);
    if($hashId !== ''){
        $rows[] = [['text'=>'💳 گرفتن شماره کارت', 'callback_data'=>'requestCartToCartCard' . $hashId, 'style'=>'success']];
        $rows[] = [['text'=>'❌ لغو خرید', 'callback_data'=>'cancelPendingPay' . $hashId, 'style'=>'danger']];
    }else{
        $rows[] = [['text'=>'❌ لغو خرید', 'callback_data'=>'mainMenu', 'style'=>'danger']];
    }
    return v2raystore_inlineKeyboardJson($rows);
}

function v2raystore_cartToCartReceiptKeyboard($hashId = ''){
    $hashId = trim((string)$hashId);
    $cb = $hashId !== '' ? ('cancelPendingPay' . $hashId) : 'mainMenu';
    return v2raystore_inlineKeyboardJson([
        [['text'=>'❌ لغو خرید', 'callback_data'=>$cb, 'style'=>'danger']]
    ]);
}

function v2raystore_isCartToCartReceiptStep($step, &$matches = null){
    $step = (string)$step;
    return preg_match('/^(increaseWalletWithCartToCart|payCustomWithCartToCart|payWithCartToCart|payRenewWithCartToCart|payIncreaseDayWithCartToCart|payIncreaseWithCartToCart)(.+)$/', $step, $matches) === 1;
}

function v2raystore_getBestPhotoFileId($updateObj = null, $fallback = ''){
    $fallback = trim((string)$fallback);
    if($updateObj === null && isset($GLOBALS['update'])) $updateObj = $GLOBALS['update'];
    if(!isset($updateObj->message->photo) || !is_array($updateObj->message->photo) || count($updateObj->message->photo) == 0) return $fallback;
    $best = null;
    foreach($updateObj->message->photo as $photoSize){
        if(isset($photoSize->file_id) && trim((string)$photoSize->file_id) !== '') $best = $photoSize;
    }
    return $best && isset($best->file_id) ? trim((string)$best->file_id) : $fallback;
}

function v2raystore_isReceiptPhotoMessage($updateObj = null){
    return v2raystore_getBestPhotoFileId($updateObj, '') !== '';
}

function v2raystore_sendReceiptPhotoOnlyNotice($hashId = ''){
    $txt = "📸 <b>لطفاً فقط تصویر رسید پرداخت را ارسال کنید.</b>\n\n" .
           "✅ اگر عکس رسید کپشن/توضیح داشته باشد مشکلی نیست؛ ربات فقط خودِ عکس رسید را ثبت و برای ادمین ارسال می‌کند.\n" .
           "❌ متن، فایل، ویدیو، ویس یا عکس ارسال‌شده به صورت فایل قابل قبول نیست.\n\n" .
           "اگر منصرف شده‌اید، روی دکمه <b>لغو خرید</b> بزنید.";
    return sendMessage($txt, v2raystore_cartToCartReceiptKeyboard($hashId), 'HTML');
}

function v2raystore_cancelPendingPayByUser($hashId, $userId){
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

function v2raystore_cartToCartNoCardText($alreadyReceived = false, $paymentKeys = null, $account = null){
    $contact = v2raystore_cardContactDisplay($paymentKeys);
    $accountTitle = v2raystore_cartToCartAccountTitle(is_array($account) ? $account : []);
    $requestText = (!empty($account['is_second'])) ? 'شماره کارت خرید دوم جهت واریز' : 'شماره کارت جهت واریز';
    if($alreadyReceived){
        return "💳 <b>پرداخت کارت‌به‌کارت - $accountTitle</b>\n\nشما قبلاً شماره کارت فعلی را دریافت کرده‌اید. لطفاً مبلغ را به همان شماره کارت واریز کنید.\n\nاگر شماره کارت را دوباره لازم دارید، به ادمین $contact پیام بدهید و متن زیر را ارسال کنید:\n<code>$requestText</code>\n\nبعد از واریز، تصویر رسید را همینجا بفرستید.";
    }
    return "💳 <b>پرداخت کارت‌به‌کارت - $accountTitle</b>\n\nبرای دریافت شماره کارت، روی دکمه <b>گرفتن شماره کارت</b> بزنید، به ادمین $contact پیام بدهید و متن زیر را ارسال کنید:\n<code>$requestText</code>\n\nبعد از دریافت شماره کارت و واریز، به همین ربات برگردید و تصویر رسید پرداخت را ارسال کنید.\n\nاین مرحله فقط یک‌بار برای شماره کارت فعلی لازم است؛ اگر ادمین اعلام کند شماره کارت تغییر کرده، دوباره باید شماره کارت جدید را بگیرید.";
}

function v2raystore_sendCartToCartInstructions($hashId, $templateKey, $parse = 'HTML'){
    global $mainValues, $userInfo;
    $paymentKeys = v2raystore_getPaymentKeys();
    $account = v2raystore_getCartToCartAccountForUser($userInfo['userid'] ?? null, $paymentKeys);
    $bank = trim((string)($account['bank'] ?? ''));
    $holder = trim((string)($account['holder'] ?? ''));
    $accountTitle = v2raystore_cartToCartAccountTitle($account);
    $extra = "\n\n📸 <b>بعد از واریز، فقط عکس رسید را همینجا ارسال کنید.</b>\n" .
             "اگر عکس کپشن داشته باشد مشکلی نیست؛ فقط خود عکس رسید برای ادمین ثبت می‌شود.\n" .
             "برای انصراف، دکمه <b>لغو خرید</b> را بزنید.";
    if($bank !== ''){
        $template = $mainValues[$templateKey] ?? 'ACCOUNT-NUMBER\nHOLDER-NAME';
        $txt = "💳 <b>کارت‌به‌کارت - $accountTitle</b>\n\n" . str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"], [$bank, $holder], $template) . $extra;
        sendMessage($txt, v2raystore_cartToCartReceiptKeyboard($hashId), $parse);
        return;
    }
    $already = v2raystore_userHasCardVersion($userInfo, $paymentKeys);
    sendMessage(v2raystore_cartToCartNoCardText($already, $paymentKeys, $account) . $extra, v2raystore_cartToCartKeyboard($hashId), 'HTML');
}

function v2raystore_deleteLocalOrderOnly($orderId){
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

function v2raystore_panelMissingSyncResult($syncInfo){
    return is_array($syncInfo) && !empty($syncInfo['checked']) && empty($syncInfo['found']);
}

function v2raystore_cleanupOrderIfMissingOnPanel($order, $syncInfo = null, $notifyUser = false){
    if(!is_array($order)) return false;
    if($syncInfo === null && function_exists('v2raystore_syncOrderExpiryFromPanel')){
        $syncInfo = v2raystore_syncOrderExpiryFromPanel($order, true);
    }
    if(!v2raystore_panelMissingSyncResult($syncInfo)) return false;

    $orderId = intval($order['id'] ?? 0);
    if($orderId <= 0) return false;
    $deleted = v2raystore_deleteLocalOrderOnly($orderId);
    if($deleted && $notifyUser && !empty($order['userid'])){
        $remark = htmlspecialchars((string)($order['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
        sendMessage("ℹ️ سرویس <b>$remark</b> دیگر داخل پنل وجود ندارد؛ برای جلوگیری از نمایش کانفیگ اضافه، از لیست ربات هم حذف شد.", null, 'HTML', intval($order['userid']));
    }
    return $deleted;
}

function v2raystore_panelExpiryToSeconds($value){
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

function v2raystore_panelClientIdentity($client){
    $id = (string)v2raystore_arrayValue($client, 'id', '');
    if($id === '') $id = (string)v2raystore_arrayValue($client, 'uuid', '');
    if($id === '') $id = (string)v2raystore_arrayValue($client, 'password', '');
    return $id;
}

function v2raystore_panelClientEmail($client){
    return trim((string)v2raystore_arrayValue($client, 'email', ''));
}

function v2raystore_panelFindClientStat($stats, $email){
    $email = trim((string)$email);
    if($email === '') return null;
    if(is_object($stats)) $stats = [$stats];
    if(!is_array($stats)) return null;
    foreach($stats as $stat){
        $statEmail = trim((string)v2raystore_arrayValue($stat, 'email', ''));
        if($statEmail !== '' && $statEmail === $email) return $stat;
    }
    return null;
}

function v2raystore_panelListFromGetJson($json){
    if(!$json || !isset($json->obj)) return [];
    $rows = $json->obj;
    if(is_object($rows)) $rows = [$rows];
    return is_array($rows) ? $rows : [];
}

function v2raystore_syncOrderExpiryFromPanel($order, $updateDb = true){
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
                $newExpire = v2raystore_panelExpiryToSeconds($info->expire);
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
        $rows = v2raystore_panelListFromGetJson($json);
        foreach($rows as $row){
            $rowId = intval(v2raystore_arrayValue($row, 'id', 0));
            if($inboundId > 0 && $rowId !== $inboundId) continue;

            $settings = v2raystore_decodeMaybeJson(v2raystore_arrayValue($row, 'settings', '{}'), true);
            $clients = $settings['clients'] ?? [];
            if(!is_array($clients)) $clients = [];

            foreach($clients as $client){
                $clientId = v2raystore_panelClientIdentity($client);
                $clientEmail = v2raystore_panelClientEmail($client);
                $match = false;
                if($uuid !== '' && $clientId !== '' && $clientId === $uuid) $match = true;
                if(!$match && $remark !== '' && $clientEmail !== '' && $clientEmail === $remark) $match = true;
                if(!$match) continue;

                $found = true;

                $clientExp = v2raystore_panelExpiryToSeconds(v2raystore_arrayValue($client, 'expiryTime', 0));
                $stat = v2raystore_panelFindClientStat(v2raystore_arrayValue($row, 'clientStats', []), $clientEmail);
                $statExp = $stat ? v2raystore_panelExpiryToSeconds(v2raystore_arrayValue($stat, 'expiryTime', 0)) : 0;
                $rowExp = v2raystore_panelExpiryToSeconds(v2raystore_arrayValue($row, 'expiryTime', 0));

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

function v2raystore_extractSubIdFromSettings($settings, $uuid = null, $remark = null){
    $settings = v2raystore_decodeMaybeJson($settings, true);
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

function v2raystore_findPanelSubId($server_id, $token = '', $uuid = '', $inbound_id = 0, $remark = ''){
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
        [$curl, $session] = v2raystore_panelLoginSession($server_info);
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
        $settings = v2raystore_decodeMaybeJson($row->settings ?? '{}', true);
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

function v2raystore_panelLoginHeaders($curl, $loginUrl){
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

function v2raystore_sanaeiCollectCookiesFromHeader($header){
    $cookies = [];
    if(preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', (string)$header, $matches)){
        foreach($matches[1] as $cookieLine){
            $cookieLine = trim($cookieLine);
            if($cookieLine !== '') $cookies[] = $cookieLine;
        }
    }
    return implode('; ', array_unique($cookies));
}

function v2raystore_panelLoginSession($server_info){
    $panel_url = rtrim($server_info['panel_url'], '/');
    $loginUrl = $panel_url . '/login';
    $username = (string)($server_info['username'] ?? '');
    $password = (string)($server_info['password'] ?? '');

    $formHeaders = v2raystore_panelLoginHeaders(null, $loginUrl);
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
        $session = v2raystore_sanaeiCollectCookiesFromHeader($header);
        $loginResponse = json_decode((string)$body, true);
        if($session && is_array($loginResponse) && !empty($loginResponse['success'])){
            return [$curl, $session];
        }
        curl_close($curl);
    }
    return [null, null];
}

function v2raystore_arrayGetDeep($array, $keys){
    if(!is_array($array)) return null;
    foreach($keys as $key){
        if(array_key_exists($key, $array)) return $array[$key];
    }
    foreach($array as $value){
        if(is_array($value)){
            $found = v2raystore_arrayGetDeep($value, $keys);
            if($found !== null && $found !== '') return $found;
        }
    }
    return null;
}

function v2raystore_panelUrlParts($server_info){
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

function v2raystore_normalizeSubPath($path, $default){
    $path = trim((string)$path);
    if($path === '') $path = $default;
    if($path[0] !== '/') $path = '/' . $path;
    if(substr($path, -1) !== '/') $path .= '/';
    return $path;
}

function v2raystore_originWithPort($scheme, $host, $port = 0){
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

function v2raystore_normalizeDirectSubUri($server_info, $direct, $format = 'sub'){
    $direct = trim((string)$direct);
    if($direct === '') return '';
    $parts = v2raystore_panelUrlParts($server_info);
    $scheme = $parts['scheme'];
    if(preg_match('#^https?://#i', $direct)){
        return substr($direct, -1) === '/' ? $direct : $direct . '/';
    }
    if(strpos($direct, '//') === 0){
        $direct = $scheme . ':' . $direct;
        return substr($direct, -1) === '/' ? $direct : $direct . '/';
    }
    $path = v2raystore_normalizeSubPath($direct, ($format === 'json') ? '/json/' : '/sub/');
    $origin = v2raystore_originWithPort($scheme, $parts['host'], $parts['port']);
    return $origin !== '' ? $origin . $path : $path;
}

function v2raystore_buildPanelSubBaseFromSettings($server_info, $settings, $format = 'sub'){
    $settings = is_array($settings) ? $settings : [];
    $parts = v2raystore_panelUrlParts($server_info);
    $scheme = $parts['scheme'];
    $host = trim((string)v2raystore_arrayGetDeep($settings, ['subDomain','subHost','subscriptionDomain','subscriptionHost']));
    if($host === '') $host = $parts['host'];

    // مهم: اگر subPort وجود داشته باشد، باید از خودش استفاده شود و نباید subURI ساخته شده با آدرس پنل ادمین
    // مثل http://domain:1030/wolf/sub/ را برگردانیم. ساب 3x-ui روی سرور جدا و معمولا بدون webBasePath پنل است.
    $subPortRaw = v2raystore_arrayGetDeep($settings, ['subPort','sub_port','subscriptionPort','subscription_port','subListenPort']);
    $subPort = is_numeric($subPortRaw) ? intval($subPortRaw) : 0;
    if($subPort > 0 && $host !== ''){
        if($format === 'json'){
            $path = v2raystore_arrayGetDeep($settings, ['subJsonPath','subJsonURIPath','jsonPath','json_path','subscriptionJsonPath']);
            $path = v2raystore_normalizeSubPath($path, '/json/');
        }else{
            $path = v2raystore_arrayGetDeep($settings, ['subPath','sub_path','subscriptionPath','subscription_path']);
            $path = v2raystore_normalizeSubPath($path, '/sub/');
        }
        return v2raystore_originWithPort($scheme, $host, $subPort) . $path;
    }

    $directKey = ($format === 'json') ? 'subJsonURI' : 'subURI';
    $direct = v2raystore_arrayGetDeep($settings, [$directKey]);
    $normalizedDirect = v2raystore_normalizeDirectSubUri($server_info, $direct, $format);
    if($normalizedDirect !== '') return $normalizedDirect;

    $path = ($format === 'json') ? '/json/' : '/sub/';
    $origin = v2raystore_originWithPort($scheme, $host, $parts['port']);
    return $origin !== '' ? $origin . $path : rtrim((string)($server_info['panel_url'] ?? ''), '/') . $path;
}

function v2raystore_getPanelSettingResponse($server_info, $session, $endpoint){
    $panel = rtrim($server_info['panel_url'] ?? '', '/');
    if($panel === '') return null;

    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $session
    );
    if(function_exists('v2raystore_sanaeiNewCsrfToken')){
        $csrf = v2raystore_sanaeiNewCsrfToken(null, $panel, $session);
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
        if(is_array($obj)) return v2raystore_normalizePanelSettingsArray($obj);
    }
    return null;
}

function v2raystore_getPanelSubscriptionUris($server_id){
    global $connection;
    static $cache = [];
    if(isset($cache[$server_id])) return $cache[$server_id];

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $result = [
        'subURI' => v2raystore_buildPanelSubBaseFromSettings($server_info ?: [], [], 'sub'),
        'subJsonURI' => v2raystore_buildPanelSubBaseFromSettings($server_info ?: [], [], 'json'),
        'subEnable' => true,
    ];

    if(!$server_info || !v2raystore_isPanelSubscriptionServer($server_info['type'] ?? '')){
        $cache[$server_id] = $result;
        return $result;
    }

    [$curl, $session] = v2raystore_panelLoginSession($server_info);
    if($curl) curl_close($curl);
    if(!$session){
        $cache[$server_id] = $result;
        return $result;
    }

    // The UI copy button uses computed subscription settings. In 2.6.x and current 3x-ui this is exposed by
    // /panel/setting/defaultSettings; /panel/setting/all may contain raw webBasePath/webPort values and can recreate
    // the wrong :panelPort/basePath/sub/ URL. Prefer defaultSettings, then fall back to all.
    $settingsDefault = v2raystore_getPanelSettingResponse($server_info, $session, '/panel/setting/defaultSettings');
    $settingsAll = v2raystore_getPanelSettingResponse($server_info, $session, '/panel/setting/all');

    foreach([$settingsDefault, $settingsAll] as $settings){
        if(!is_array($settings) || empty($settings)) continue;
        $hasSubInfo = v2raystore_arrayGetDeep($settings, ['subURI','subJsonURI','subPort','sub_port','subscriptionPort','subscription_port','subPath','sub_path']) !== null;
        if(!$hasSubInfo) continue;
        $result['subURI'] = v2raystore_buildPanelSubBaseFromSettings($server_info, $settings, 'sub');
        $result['subJsonURI'] = v2raystore_buildPanelSubBaseFromSettings($server_info, $settings, 'json');
        if(array_key_exists('subEnable', $settings)) $result['subEnable'] = (bool)$settings['subEnable'];
        break;
    }

    $cache[$server_id] = $result;
    return $result;
}

function v2raystore_panelSubLinkBySubId($server_id, $subId, $format = 'sub'){
    $subId = trim((string)$subId);
    if($subId === '') return '';
    $uris = v2raystore_getPanelSubscriptionUris($server_id);
    $base = ($format === 'json') ? ($uris['subJsonURI'] ?? '') : ($uris['subURI'] ?? '');
    if($base === '') return '';
    return rtrim($base, '/') . '/' . rawurlencode($subId);
}

function v2raystore_makeCustomerSubLink($server_id, $token = '', $uuid = '', $inbound_id = 0, $remark = '', $format = 'sub'){
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

    if(v2raystore_isPanelSubscriptionServer($serverType)){
        $subId = v2raystore_findPanelSubId($server_id, $token, $uuid, $inbound_id, $remark);
        return $subId !== '' ? v2raystore_panelSubLinkBySubId($server_id, $subId, $format) : '';
    }

    $token = trim((string)$token);
    return $token !== '' ? $botUrl . 'settings/subLink.php?token=' . urlencode($token) : '';
}


function v2raystore_replyMarkupHasButtonStyle($markup){
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

function v2raystore_stripButtonStylesRecursive($value){
    if(is_array($value)){
        unset($value['style']);
        foreach($value as $k => $v){
            if(is_array($v)) $value[$k] = v2raystore_stripButtonStylesRecursive($v);
        }
    }
    return $value;
}

function v2raystore_stripButtonStylesFromMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;
    $decoded = v2raystore_stripButtonStylesRecursive($decoded);
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_replyMarkupHasCopyTextButton($markup){
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

function v2raystore_fallbackCopyTextButtonsRecursive($value){
    if(is_array($value)){
        if(array_key_exists('copy_text', $value)){
            unset($value['copy_text']);
            $hasAction = false;
            foreach(['url','callback_data','web_app','login_url','switch_inline_query','switch_inline_query_current_chat','switch_inline_query_chosen_chat','callback_game','pay'] as $field){
                if(array_key_exists($field, $value)){ $hasAction = true; break; }
            }
            if(!$hasAction) $value['callback_data'] = 'v2raystore';
        }
        foreach($value as $k => $v){
            if(is_array($v)) $value[$k] = v2raystore_fallbackCopyTextButtonsRecursive($v);
        }
    }
    return $value;
}

function v2raystore_fallbackCopyTextButtonsFromMarkup($markup){
    if($markup === null || $markup === '') return $markup;
    $isString = is_string($markup);
    $decoded = $isString ? json_decode($markup, true) : $markup;
    if(!is_array($decoded)) return $markup;
    $decoded = v2raystore_fallbackCopyTextButtonsRecursive($decoded);
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bot($method, $datas = []){
    global $botToken;
    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;

    $sendRequest = function($payload) use ($url){
        $ch = curl_init();
        $timeout = isset($payload['_timeout']) ? max(3, intval($payload['_timeout'])) : 8;
        unset($payload['_timeout']);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
        if(defined('CURL_IPRESOLVE_V4')) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
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
        return null;
    }

    $decoded = json_decode($res);
    $currentDatas = $datas;

    // اگر سرور/کلاینت Bot API با style مشکل داشت، یک بار بدون style دوباره تلاش می‌کنیم
    // تا دکمه‌ها کلاً از کار نیفتند.
    if(isset($currentDatas['reply_markup']) && v2raystore_replyMarkupHasButtonStyle($currentDatas['reply_markup']) && is_object($decoded) && isset($decoded->ok) && !$decoded->ok){
        $desc = strtolower((string)($decoded->description ?? ''));
        if(strpos($desc, 'style') !== false || strpos($desc, 'button') !== false || strpos($desc, 'reply markup') !== false){
            $retryDatas = $currentDatas;
            $retryDatas['reply_markup'] = v2raystore_stripButtonStylesFromMarkup($retryDatas['reply_markup']);
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
    if(isset($currentDatas['reply_markup']) && v2raystore_replyMarkupHasCopyTextButton($currentDatas['reply_markup']) && is_object($decoded) && isset($decoded->ok) && !$decoded->ok){
        $desc = strtolower((string)($decoded->description ?? ''));
        if(strpos($desc, 'copy_text') !== false || strpos($desc, 'button') !== false || strpos($desc, 'reply markup') !== false){
            $retryDatas = $currentDatas;
            $retryDatas['reply_markup'] = v2raystore_fallbackCopyTextButtonsFromMarkup($retryDatas['reply_markup']);
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
    $key = v2raystore_styleReplyMarkup($key);
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
    $keys = v2raystore_styleReplyMarkup($keys);
   
    bot('editMessageReplyMarkup',[
		'chat_id' => $ci,
		'message_id' => $msgId,
		'reply_markup' => $keys
    ]);
}
function editText($msgId, $txt, $key = null, $parse = null, $ci = null){
    global $from_id;
    $ci = $ci??$from_id;
    $key = v2raystore_styleReplyMarkup($key);

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
    $keyboard = v2raystore_styleReplyMarkup($keyboard);
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

function v2raystore_getJoinedStateSafe($channelLock, $userId){
    $channelLock = trim((string)$channelLock);
    $userId = intval($userId);
    if($channelLock === '' || strtolower($channelLock) === 'off' || $channelLock === '0' || $userId <= 0){
        return 'member';
    }
    $res = bot('getChatMember', [
        'chat_id' => $channelLock,
        'user_id' => $userId,
        '_timeout' => 4,
    ]);
    if(is_object($res) && !empty($res->ok) && isset($res->result->status)){
        return (string)$res->result->status;
    }
    // اگر تلگرام یا شبکه لحظه‌ای خطا داد، کاربر را اشتباهی غیرعضو حساب نکن.
    return 'member';
}

 
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
$botState = v2raystore_applyRoleSpecificStates($botState, $userInfo);

$channelLock = $botState['lockChannel'];
$joniedState = v2raystore_getJoinedStateSafe($channelLock, $from_id);

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
        return v2raystore_userButtonVisible($key, $botState) ? $button : null;
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
            'enabled' => v2raystore_isWalletOpenForCurrentUser(),
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
            'buttons' => [['text'=>$buttonValues['application_links'],'callback_data'=>"tutorialsMenu"]]
        ],
        'faq' => [
            'enabled' => true,
            'buttons' => [['text'=>'❓ سوالات متداول','callback_data'=>"faqMenu"]]
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
    $rowBreaks = v2raystore_getUserButtonRowBreaks($botState);
    foreach(v2raystore_getUserButtonOrder($botState) as $key){
        if(!isset($definitions[$key])) continue;
        if(empty($definitions[$key]['enabled'])) continue;
        if(!v2raystore_userButtonVisible($key, $botState)) continue;
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
    return v2raystore_inlineKeyboardJson($mainKeys); 
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
        [['text'=>$boughtAccounts,'callback_data'=>"v2raystore"],['text'=>$buttonValues['agent_bought_accounts'],'callback_data'=>"v2raystore"]],
        [['text'=>$joinedDate,'callback_data'=>"v2raystore"],['text'=>$buttonValues['agent_joined_date'],'callback_data'=>"v2raystore"]],
        [['text'=>$agencyDate,'callback_data'=>"v2raystore"],['text'=>$buttonValues['agent_agency_date'],'callback_data'=>"v2raystore"]],
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



function v2raystore_isTestAccountExempt($user){
    return !empty($user) && isset($user['test_account_exempt']) && intval($user['test_account_exempt']) === 1;
}

function v2raystore_getDefaultTestAccountLimit(){
    global $botState;
    $limit = null;
    if(is_array($botState) && isset($botState['TEST_ACCOUNT_DEFAULT_LIMIT'])) $limit = intval($botState['TEST_ACCOUNT_DEFAULT_LIMIT']);
    if(($limit === null || $limit <= 0) && function_exists('v2raystore_getBotStatesArray')){
        $states = v2raystore_getBotStatesArray(true);
        if(is_array($states) && isset($states['TEST_ACCOUNT_DEFAULT_LIMIT'])) $limit = intval($states['TEST_ACCOUNT_DEFAULT_LIMIT']);
    }
    if($limit === null || $limit <= 0) $limit = 1;
    return max(1, min(100, intval($limit)));
}

function v2raystore_setDefaultTestAccountLimit($limit){
    global $botState;
    $limit = max(1, min(100, intval($limit)));
    if(function_exists('v2raystore_getBotStatesArray') && function_exists('v2raystore_saveBotStatesArray')){
        $states = v2raystore_getBotStatesArray(true);
        if(!is_array($states)) $states = [];
        $states['TEST_ACCOUNT_DEFAULT_LIMIT'] = $limit;
        $ok = v2raystore_saveBotStatesArray($states);
        $botState = $states;
        return $ok;
    }
    if(function_exists('setSettings')){
        setSettings('TEST_ACCOUNT_DEFAULT_LIMIT', $limit);
        if(is_array($botState)) $botState['TEST_ACCOUNT_DEFAULT_LIMIT'] = $limit;
        return true;
    }
    return false;
}

function v2raystore_getUserTestAccountLimit($user){
    if(v2raystore_isTestAccountExempt($user)) return 0;
    if(!empty($user) && array_key_exists('test_account_limit', $user) && $user['test_account_limit'] !== null && $user['test_account_limit'] !== ''){
        $limit = intval($user['test_account_limit']);
        if($limit >= 0) return $limit;
    }
    return function_exists('v2raystore_getDefaultTestAccountLimit') ? v2raystore_getDefaultTestAccountLimit() : 1;
}

function v2raystore_isTestAccountProcessingState($value){
    return is_string($value) && strpos($value, 'processing:') === 0;
}

function v2raystore_testAccountProcessingTimestamp($value){
    if(!v2raystore_isTestAccountProcessingState($value)) return 0;
    return intval(substr($value, strrpos($value, ':') + 1));
}

function v2raystore_countUserCreatedTestAccounts($userId){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0 || !isset($connection) || !$connection) return 0;
    $stmt = @$connection->prepare("SELECT COUNT(*) AS c FROM `orders_list` WHERE `userid` = ? AND COALESCE(`amount`,0) = 0");
    if(!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return max(0, intval($row['c'] ?? 0));
}

function v2raystore_cleanupStaleTestAccountProcessing($userId, &$user = null){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0 || !is_array($user)) return false;
    $trial = (string)($user['freetrial'] ?? '');
    if(!v2raystore_isTestAccountProcessingState($trial)) return false;
    $ts = v2raystore_testAccountProcessingTimestamp($trial);
    if($ts > 0 && $ts >= time() - 120) return false;

    $realCount = v2raystore_countUserCreatedTestAccounts($userId);
    $newTrial = $realCount > 0 ? 'used' : null;
    if(isset($connection) && $connection){
        $stmt = @$connection->prepare("UPDATE `users` SET `freetrial` = ?, `test_account_count` = ? WHERE `userid` = ? AND `freetrial` LIKE 'processing:%'");
        if($stmt){
            $stmt->bind_param('sii', $newTrial, $realCount, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
    $user['freetrial'] = $newTrial;
    $user['test_account_count'] = $realCount;
    return true;
}

function v2raystore_getUserTestAccountUsedCount($user){
    if(empty($user)) return 0;
    $count = 0;
    if(array_key_exists('test_account_count', $user)) $count = max(0, intval($user['test_account_count']));
    $trial = (string)($user['freetrial'] ?? '');
    if($trial !== '' && !v2raystore_isTestAccountProcessingState($trial)) $count = max($count, 1);
    return $count;
}

function v2raystore_canUserGetTestAccount($user, $userId = null){
    global $admin;
    if(!empty($userId) && intval($userId) === intval($admin)) return true;
    if(!empty($user) && !empty($user['isAdmin'])) return true;
    if(!empty($userId) && is_array($user)){
        v2raystore_cleanupStaleTestAccountProcessing($userId, $user);
        $trial = (string)($user['freetrial'] ?? '');
        if(v2raystore_isTestAccountProcessingState($trial)) return false;
    }
    $limit = v2raystore_getUserTestAccountLimit($user);
    if($limit === 0) return true;
    return v2raystore_getUserTestAccountUsedCount($user) < $limit;
}

function v2raystore_getTestAccountLimitText($user){
    $limit = v2raystore_getUserTestAccountLimit($user);
    return $limit === 0 ? 'نامحدود' : ($limit . ' بار');
}

function v2raystore_markTestAccountUsed($userId){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;

    // رزرو فقط قفل می‌گذارد؛ شمارنده فقط بعد از ساخت موفق اکانت تست زیاد می‌شود.
    $stmt = @$connection->prepare("UPDATE `users` SET `test_account_count` = GREATEST(COALESCE(`test_account_count`,0), IF(`freetrial` IS NOT NULL AND `freetrial` <> '' AND `freetrial` NOT LIKE 'processing:%', 1, 0)) + 1, `freetrial` = 'used' WHERE `userid` = ? AND `freetrial` LIKE 'processing:%'");
    if($stmt){
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $changed = intval($stmt->affected_rows);
        $stmt->close();
        if($changed > 0) return true;
    }

    $stmt = @$connection->prepare("UPDATE `users` SET `test_account_count` = GREATEST(COALESCE(`test_account_count`,0), IF(`freetrial` IS NOT NULL AND `freetrial` <> '', 1, 0)) + 1, `freetrial` = 'used' WHERE `userid` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_reserveTestAccountCreation($userId){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;

    // اگر پردازش قبلی گیر کرده باشد، زود آزاد شود تا کاربر به خاطر یک تلاش ناموفق قفل نماند.
    $staleBefore = time() - 120;
    $stmt = @$connection->prepare("UPDATE `users` SET `freetrial` = IF(COALESCE(`test_account_count`,0) > 0, 'used', NULL) WHERE `userid` = ? AND `freetrial` LIKE 'processing:%' AND CAST(SUBSTRING_INDEX(`freetrial`, ':', -1) AS UNSIGNED) < ?");
    if($stmt){
        $stmt->bind_param('ii', $userId, $staleBefore);
        $stmt->execute();
        $stmt->close();
    }

    $lockValue = 'processing:' . time();
    $stmt = @$connection->prepare("UPDATE `users` SET `freetrial` = ? WHERE `userid` = ? AND (`freetrial` IS NULL OR `freetrial` = '' OR (`freetrial` LIKE 'processing:%' AND CAST(SUBSTRING_INDEX(`freetrial`, ':', -1) AS UNSIGNED) < ?)) AND (COALESCE(`test_account_exempt`,0) = 1 OR COALESCE(`test_account_limit`,1) = 0 OR COALESCE(`test_account_count`,0) < COALESCE(`test_account_limit`,1))");
    if(!$stmt) return false;
    $now = time() - 120;
    $stmt->bind_param('sii', $lockValue, $userId, $now);
    $stmt->execute();
    $changed = intval($stmt->affected_rows);
    $stmt->close();
    return $changed > 0;
}

function v2raystore_releaseTestAccountCreation($userId){
    global $connection;
    $userId = intval($userId);
    if($userId <= 0) return false;
    $stmt = @$connection->prepare("UPDATE `users` SET `freetrial` = IF(COALESCE(`test_account_count`,0) > 0, 'used', NULL) WHERE `userid` = ? AND `freetrial` LIKE 'processing:%'");
    if(!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}


function v2raystore_cleanTestRemarkPrefix($prefix){
    $prefix = trim((string)$prefix);
    if($prefix === '') return '';
    $prefix = preg_replace('/\s+/u', '-', $prefix);
    // برای جلوگیری از خطای پنل‌ها، فقط حروف، عدد، خط تیره، آندرلاین و نقطه نگه داشته می‌شود.
    $prefix = preg_replace('/[^\p{L}\p{N}_\-.]/u', '', $prefix);
    $prefix = trim($prefix, "-_ .");
    if(function_exists('mb_substr')) $prefix = mb_substr($prefix, 0, 32, 'UTF-8');
    else $prefix = substr($prefix, 0, 32);
    return $prefix;
}

function v2raystore_getTestRemarkPrefix(){
    global $connection;
    $prefix = null;
    if(isset($connection)){
        $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'TEST_ACCOUNT_REMARK_PREFIX' LIMIT 1");
        if($stmt){
            $stmt->execute();
            $res = $stmt->get_result();
            if($res && $res->num_rows > 0) $prefix = $res->fetch_assoc()['value'];
            $stmt->close();
        }
    }
    if($prefix === '__empty__') return '';
    if($prefix === null || $prefix === '') $prefix = 'test';
    return v2raystore_cleanTestRemarkPrefix($prefix) ?: 'test';
}

function v2raystore_applyTestRemarkPrefix($remark, $prefix = null){
    $remark = trim((string)$remark);
    $prefix = ($prefix === null) ? v2raystore_getTestRemarkPrefix() : v2raystore_cleanTestRemarkPrefix($prefix);
    if($remark === '') $remark = 'account';
    if($prefix === '') return $remark;

    $prefixWithDash = $prefix . '-';
    if(function_exists('mb_stripos')){
        if(mb_stripos($remark, $prefixWithDash, 0, 'UTF-8') === 0 || mb_strtolower($remark, 'UTF-8') === mb_strtolower($prefix, 'UTF-8')) return $remark;
    }else{
        if(stripos($remark, $prefixWithDash) === 0 || strtolower($remark) === strtolower($prefix)) return $remark;
    }
    return $prefixWithDash . $remark;
}


function v2raystore_getTestAccountAutoDeleteState(){
    global $botState, $connection;
    $state = null;

    if(is_array($botState) && array_key_exists('TEST_ACCOUNT_AUTO_DELETE', $botState)){
        $state = (string)$botState['TEST_ACCOUNT_AUTO_DELETE'];
    }elseif(function_exists('v2raystore_getBotStatesArray')){
        $states = v2raystore_getBotStatesArray(true);
        if(is_array($states) && array_key_exists('TEST_ACCOUNT_AUTO_DELETE', $states)){
            $state = (string)$states['TEST_ACCOUNT_AUTO_DELETE'];
        }
    }

    if($state === null && isset($connection) && $connection){
        $legacyType = 'TEST_ACCOUNT_AUTO_DELETE';
        $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('s', $legacyType);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if($row && isset($row['value'])) $state = (string)$row['value'];
        }
    }

    return $state === 'on' ? 'on' : 'off';
}

function v2raystore_setTestAccountAutoDeleteState($state){
    global $botState, $connection;
    $state = ($state === 'on') ? 'on' : 'off';

    if(function_exists('v2raystore_getBotStatesArray') && function_exists('v2raystore_saveBotStatesArray')){
        $states = v2raystore_getBotStatesArray(true);
        if(!is_array($states)) $states = [];
        $states['TEST_ACCOUNT_AUTO_DELETE'] = $state;
        $ok = v2raystore_saveBotStatesArray($states);
        $botState = $states;
        return $ok;
    }

    if(function_exists('setSettings')){
        setSettings('TEST_ACCOUNT_AUTO_DELETE', $state);
        if(is_array($botState)) $botState['TEST_ACCOUNT_AUTO_DELETE'] = $state;
        return true;
    }

    if(!isset($connection) || !$connection) return false;
    $type = 'TEST_ACCOUNT_AUTO_DELETE';
    $stmt = @$connection->prepare("SELECT COUNT(*) AS cnt FROM `setting` WHERE `type` = ?");
    if(!$stmt) return false;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $cnt = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if($cnt > 0){
        $stmt = @$connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = ?");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $state, $type);
    }else{
        $stmt = @$connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
        if(!$stmt) return false;
        $stmt->bind_param('ss', $type, $state);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_getTestAccountManageKeys(){
    global $connection, $buttonValues;
    $totalUsers = 0;
    $usedUsers = 0;
    $customUsers = 0;
    $testRemarkPrefix = function_exists('v2raystore_getTestRemarkPrefix') ? v2raystore_getTestRemarkPrefix() : 'test';
    $testRemarkPrefixTitle = ($testRemarkPrefix === '') ? 'بدون پیشوند' : $testRemarkPrefix;
    $defaultTestLimit = function_exists('v2raystore_getDefaultTestAccountLimit') ? v2raystore_getDefaultTestAccountLimit() : 1;
    $testAutoDeleteState = function_exists('v2raystore_getTestAccountAutoDeleteState') ? v2raystore_getTestAccountAutoDeleteState() : 'off';
    $testAutoDeleteTitle = ($testAutoDeleteState === 'on') ? 'روشن ✅' : 'خاموش ❌';
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users`"));
    if($res) $totalUsers = intval(($res->fetch_assoc())['c'] ?? 0);
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users` WHERE `freetrial` IS NOT NULL OR COALESCE(`test_account_count`,0) > 0"));
    if($res) $usedUsers = intval(($res->fetch_assoc())['c'] ?? 0);
    $res = @($connection->query("SELECT COUNT(*) AS c FROM `users` WHERE `test_account_exempt` = 1 OR (`test_account_limit` IS NOT NULL AND `test_account_limit` >= 0)"));
    if($res) $customUsers = intval(($res->fetch_assoc())['c'] ?? 0);

    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'👥 کاربران: ' . $totalUsers, 'callback_data'=>'v2raystore', 'style'=>'primary'],
            ['text'=>'🧪 استفاده‌کرده: ' . $usedUsers, 'callback_data'=>'v2raystore', 'style'=>'primary']
        ],
        [
            ['text'=>'⚙️ سقف اختصاصی: ' . $customUsers, 'callback_data'=>'v2raystore', 'style'=>'primary']
        ],
        [
            ['text'=>'🏷 ریمارک تست: ' . $testRemarkPrefixTitle, 'callback_data'=>'v2raystore', 'style'=>'primary']
        ],
        [
            ['text'=>'🔢 سقف پیش‌فرض تست: ' . $defaultTestLimit . ' بار', 'callback_data'=>'v2raystore', 'style'=>'primary']
        ],
        [
            ['text'=>'➕ افزودن ۱ تست به همه', 'callback_data'=>'adjustDefaultTestAccountLimit_plus', 'style'=>'success'],
            ['text'=>'➖ کم‌کردن ۱ تست از همه', 'callback_data'=>'adjustDefaultTestAccountLimit_minus', 'style'=>'warning']
        ],
        [
            ['text'=>'✏️ تغییر ریمارک تست', 'callback_data'=>'setTestAccountRemarkPrefix', 'style'=>'success']
        ],
        [
            ['text'=>'🗑 حذف خودکار تست تمام‌شده: ' . $testAutoDeleteTitle, 'callback_data'=>'toggleTestAccountAutoDelete', 'style'=>($testAutoDeleteState === 'on' ? 'success' : 'warning')]
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

function v2raystore_getTestAccountLimitsListText(){
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
        $usedText = v2raystore_getUserTestAccountUsedCount($row);
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
        $keys[] = [['text'=>"آزاد ساختن",'callback_data'=>"v2raystore"],['text'=>"اسم کاربر",'callback_data'=>'v2raystore'],['text'=>"آیدی عددی",'callback_data'=>"v2raystore"]];
        while($row = $list->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            
            $keys[] = [['text'=>"✅",'callback_data'=>"releaseRejectedAgent" . $userId],['text'=>$fullName,'callback_data'=>"v2raystore"],['text'=>$userId,'callback_data'=>"v2raystore"]];
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
            ['text'=>"(" . $todayIncome['count'] . ") " . number_format($todayIncome['total']),'callback_data'=>'v2raystore'],
            ['text'=>"درآمد امروز",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>"(" . $yesterdayIncome['count'] . ") " . number_format($yesterdayIncome['total']),'callback_data'=>"v2raystore"],
            ['text'=>"درآمد دیروز",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>"(" . $lastWeekIncome['count'] . ") " . number_format($lastWeekIncome['total']),'callback_data'=>"v2raystore"],
            ['text'=>"درآمد یک هفته",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>"(" . $lastMonthIncome['count'] . ") " . number_format($lastMonthIncome['total']),'callback_data'=>"v2raystore"],
            ['text'=>"درآمد یک ماه",'callback_data'=>"v2raystore"]
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
    $keys[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"درصد تخفیف",'callback_data'=>"v2raystore"],['text'=>"تاریخ نمایندگی",'callback_data'=>"v2raystore"],['text'=>"اسم نماینده",'callback_data'=>"v2raystore"],['text'=>"آیدی عددی",'callback_data'=>"v2raystore"]];
    if($agentList->num_rows > 0){
        while($row = $agentList->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $userUserName = $userDetail->username;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            $joinedDate = jdate("Y-m-d H:i",$row['agent_date']);

            $keys[] = [['text'=>"❌",'callback_data'=>"removeAgent" . $userId],['text'=>"⚙️",'callback_data'=>"agentPercentDetails" . $userId],['text'=>$joinedDate,'callback_data'=>"v2raystore"],['text'=>$fullName,'callback_data'=>"agentDetails" . $userId],['text'=>$userId,'callback_data'=>"agentDetails" . $userId]];
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
    $keys[] = [['text'=>" ",'callback_data'=>"v2raystore"],
    ['text'=>$normal . "%",'callback_data'=>"editAgentDiscountNormal" . $agentId . "_0"],
    ['text'=>"عمومی",'callback_data'=>"v2raystore"]];            
    
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
            ['text'=>$info['title'] . " " . $catInfo['title'],'callback_data'=>"v2raystore"]];            
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
            ['text'=>$info['title'],'callback_data'=>"v2raystore"]];            
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
    
    if(curl_error($ch)) return null;
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
    $flagv2raystore = $cty['flag'];
    $remarkv2raystore = $cty['remark'];
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
            ['text'=>$panelUrl,'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$cname,'callback_data'=>"editServerName$id"],
            ['text'=>"❕نام سرور",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$flagv2raystore,'callback_data'=>"editServerFlag$id"],
            ['text'=>"🚩 پرچم سرور",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$remarkv2raystore,'callback_data'=>"editServerRemark$id"],
            ['text'=>"📣 ریمارک سرور",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$serverType??" ",'callback_data'=>"changeServerType$id"],
            ['text'=>"نوعیت سرور",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$ucount,'callback_data'=>"editServerMax$id"],
            ['text'=>"ظرفیت سرور",'callback_data'=>"v2raystore"]
            ]
            ],
            ($serverConfig['type'] != "marzban"?[
        [
            ['text'=>$portType,'callback_data'=>"changePortType$id"],
            ['text'=>"نوعیت پورت",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$sni,'callback_data'=>"editsServersni$id"],
            ['text'=>"sni",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$headerType,'callback_data'=>"editsServerheader_type$id"],
            ['text'=>"header type",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$requestHeader,'callback_data'=>"editsServerrequest_header$id"],
            ['text'=>"request header",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$responseHeader,'callback_data'=>"editsServerresponse_header$id"],
            ['text'=>"response header",'callback_data'=>"v2raystore"],
            ],
        [
            ['text'=>$security,'callback_data'=>"editsServersecurity$id"],
            ['text'=>"security",'callback_data'=>"v2raystore"],
            ],
        (($serverConfig['type'] == "sanaei" || $serverConfig['type'] == "sanaei_new" || $serverConfig['type'] == "alireza")?
        [
            ['text'=>$reality,'callback_data'=>"changeRealityState$id"],
            ['text'=>"reality",'callback_data'=>"v2raystore"],
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
            ['text'=>"✂️ حذف سرور",'callback_data'=>"v2raystoredeleteserver$id"],
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
    $keys[] = [['text'=>"وضعیت",'callback_data'=>"v2raystore"],['text'=>"تنظیمات",'callback_data'=>"v2raystore"],['text'=>"نوعیت",'callback_data'=>"v2raystore"],['text'=>"سرور",'callback_data'=>"v2raystore"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"سروری یافت نشد",'callback_data'=>"v2raystore"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $flagv2raystore = $cty['flag'];
            $remarkv2raystore = $cty['remark'];
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
            $keys[] = [['text'=>$state,'callback_data'=>'toggleServerState' . $id . "_" . $offset],['text'=>"⚙️",'callback_data'=>"showServerSettings" . $id . "_" . $offset],['text'=>$serverType??" ",'callback_data'=>"v2raystore"],['text'=>$cname,'callback_data'=>"v2raystore"]];
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
    $keys[] = [['text'=>"حذف",'callback_data'=>"v2raystore"],['text'=>"اسم دسته",'callback_data'=>"v2raystore"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"v2raystore"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $keys[] = [['text'=>"❌",'callback_data'=>"v2raystorecategorydelete$id" . "_" . $offset],['text'=>$cname,'callback_data'=>"v2raystorecategoryedit$id" . "_" . $offset]];
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

    $paymentKeys = v2raystore_getPaymentKeys();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>(!empty($paymentKeys['bankAccount'])?$paymentKeys['bankAccount']:" "),'callback_data'=>"changePaymentKeysbankAccount"],
            ['text'=>"شماره کارت خرید اول",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['holderName'])?$paymentKeys['holderName']:" "),'callback_data'=>"changePaymentKeysholderName"],
            ['text'=>"دارنده کارت خرید اول",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['secondBankAccount'])?$paymentKeys['secondBankAccount']:(!empty($paymentKeys['bankAccount2'])?$paymentKeys['bankAccount2']:" ")),'callback_data'=>"changePaymentKeyssecondBankAccount"],
            ['text'=>"شماره کارت خرید دوم",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['secondHolderName'])?$paymentKeys['secondHolderName']:(!empty($paymentKeys['holderName2'])?$paymentKeys['holderName2']:" ")),'callback_data'=>"changePaymentKeyssecondHolderName"],
            ['text'=>"دارنده کارت خرید دوم",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['cardContact'])?$paymentKeys['cardContact']:(string)$admin),'callback_data'=>"changePaymentKeyscardContact"],
            ['text'=>"ادمین دریافت شماره کارت",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>"🔄 شماره کارت عوض شده",'callback_data'=>"markCartToCartCardChanged"],
            ['text'=>"ریست دریافت کارت کاربران",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nowpayment'])?$paymentKeys['nowpayment']:" "),'callback_data'=>"changePaymentKeysnowpayment"],
            ['text'=>"کد درگاه nowPayment",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['zarinpal'])?$paymentKeys['zarinpal']:" "),'callback_data'=>"changePaymentKeyszarinpal"],
            ['text'=>"کد درگاه زرین پال",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nextpay'])?$paymentKeys['nextpay']:" "),'callback_data'=>"changePaymentKeysnextpay"],
            ['text'=>"کد درگاه نکست پی",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>(!empty($paymentKeys['tronwallet'])?$paymentKeys['tronwallet']:" "),'callback_data'=>"changePaymentKeystronwallet"],
            ['text'=>"آدرس والت ترون",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$weSwapState,'callback_data'=>"changeGateWaysweSwapState"],
            ['text'=>"درگاه وی سواپ",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$nextpay,'callback_data'=>"changeGateWaysnextpay"],
            ['text'=>"درگاه نکست پی",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$zarinpal,'callback_data'=>"changeGateWayszarinpal"],
            ['text'=>"درگاه زرین پال",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$nowPaymentWallet,'callback_data'=>"changeGateWaysnowPaymentWallet"],
            ['text'=>"درگاه NowPayment کیف پول",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$nowPaymentOther,'callback_data'=>"changeGateWaysnowPaymentOther"],
            ['text'=>"درگاه NowPayment سایر",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$tronWallet,'callback_data'=>"changeGateWaystronWallet"],
            ['text'=>"درگاه ترون",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$walletState,'callback_data'=>"changeGateWayswalletState"],
            ['text'=>"کیف پول کاربران",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$agentWalletState,'callback_data'=>"changeGateWaysagentWalletState"],
            ['text'=>"کیف پول نماینده‌ها",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$rewaredChannel,'callback_data'=>'editRewardChannel'],
            ['text'=>"گروه/کانال گزارش",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$lockChannel,'callback_data'=>'editLockChannel'],
            ['text'=>"کانال قفل",'callback_data'=>'v2raystore']
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
    $renewSettings = function_exists('v2raystore_getRenewSettings') ? v2raystore_getRenewSettings() : ['mode'=>'reset','max_days'=>45];
    $renewModeText = ($renewSettings['mode'] ?? 'reset') === 'add' ? 'افزایشی / سقف ۴۵ روز' : 'ریست کامل';
    $plandelkhahStore = $botState['plandelkhahState']=="on"?$buttonValues['on']:$buttonValues['off'];
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
            ['text'=>"آپدیت کانفیگ",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=> $agency,'callback_data'=>"changeBotagencyState"],
            ['text'=>"نمایندگی",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=> $agencyPlanDiscount,'callback_data'=>"changeBotagencyPlanDiscount"],
            ['text'=>"نوع تخفیف نمایندگی",'callback_data'=>"v2raystore"]
            ],
        [
            ['text'=>$individualExistence,'callback_data'=>"changeBotindividualExistence"],
            ['text'=>"موجودی اختصاصی",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$sharedExistence,'callback_data'=>"changeBotsharedExistence"],
            ['text'=>"موجودی اشتراکی",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$testAccount,'callback_data'=>"changeBottestAccount"],
            ['text'=>"اکانت تست",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$changeProtocole,'callback_data'=>"changeBotchangeProtocolState"],
            ['text'=>"تغییر پروتکل",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$renewAccount,'callback_data'=>"changeBotrenewAccountState"],
            ['text'=>"تمدید سرویس",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$renewModeText,'callback_data'=>"renewSettings", 'style'=>'primary'],
            ['text'=>"تنظیمات تمدید",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$plandelkhahStore,'callback_data'=>"changeBotplandelkhahState"],
            ['text'=>"پلن دلخواه",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$switchLocation,'callback_data'=>"changeBotswitchLocationState"],
            ['text'=>"تغییر لوکیشن",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>"⚙️ تنظیمات",'callback_data'=>"switchLocationSettings", 'style'=>'primary'],
            ['text'=>"هزینه تغییر سرور",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$increaseTime,'callback_data'=>"changeBotincreaseTimeState"],
            ['text'=>"افزایش زمان",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$increaseVolume,'callback_data'=>"changeBotincreaseVolumeState"],
            ['text'=>"افزایش حجم",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$requirePhone,'callback_data'=>"changeBotrequirePhone"],
            ['text'=>"تأیید شماره",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$requireIranPhone,'callback_data'=>"changeBotrequireIranPhone"],
            ['text'=>"تأیید شماره ایرانی",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$sellState,'callback_data'=>"changeBotsellState"],
            ['text'=>"فروش کاربران",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>'📝 متن‌ها / قوانین خرید','callback_data'=>'adminTextSettings', 'style'=>'primary'],
            ['text'=>'قوانین قبل خرید','callback_data'=>'v2raystore']
        ],
        [
            ['text'=>$agentSellState,'callback_data'=>"changeBotagentSellState"],
            ['text'=>"فروش نماینده‌ها",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$robotState,'callback_data'=>"changeBotbotState"],
            ['text'=>"وضعیت ربات",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$subLink,'callback_data'=>"changeBotsubLinkState"],
            ['text'=>"لینک ساب و مشخصات وب",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$configLink,'callback_data'=>"changeBotconfigLinkState"],
            ['text'=>"لینک کانفیگ",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$searchState,'callback_data'=>"changeBotsearchState"],
            ['text'=>"مشخصات کانفیگ",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$renewConfigLink,'callback_data'=>"changeBotrenewConfigLinkState"],
            ['text'=>"دریافت لینک جدید",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$updateConfigLink,'callback_data'=>"changeBotupdateConfigLinkState"],
            ['text'=>"بروز رسانی لینک",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$qrConfig,'callback_data'=>"changeBotqrConfigState"],
            ['text'=>"کیو آر کد کانفیگ",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$qrSub,'callback_data'=>"changeBotqrSubState"],
            ['text'=>"کیو آر کد ساب",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$remarkType,'callback_data'=>"changeConfigRemarkType"],
            ['text'=>"نوع ریمارک",'callback_data'=>"v2raystore"]
        ],
        [
            ['text'=>$rewaredTime,'callback_data'=>'editRewardTime'],
            ['text'=>"ارسال گزارش درآمد", 'callback_data'=>'v2raystore']
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
            ['text'=>$allUsers,'callback_data'=>'v2raystore'],
            ['text'=>"تعداد کل کاربران",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$allOrders,'callback_data'=>'v2raystore'],
            ['text'=>"کل محصولات خریداری شده",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$allServers,'callback_data'=>'v2raystore'],
            ['text'=>"تعداد سرورها",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$allCategories,'callback_data'=>'v2raystore'],
            ['text'=>"تعداد دسته ها",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$allPlans,'callback_data'=>'v2raystore'],
            ['text'=>"تعداد پلن ها",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$totalRewards,'callback_data'=>'v2raystore'],
            ['text'=>"درآمد کل",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$dayReward,'callback_data'=>'v2raystore'],
            ['text'=>"درآمد امروز",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$weekReward,'callback_data'=>'v2raystore'],
            ['text'=>"درآمد هفته",'callback_data'=>'v2raystore']
            ],
        [
            ['text'=>$monthReward,'callback_data'=>'v2raystore'],
            ['text'=>"درآمد ماه",'callback_data'=>'v2raystore']
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
        $keys[] = [['text'=>"👑 ادمین اصلی: همیشه دریافت فیش روشن است", 'callback_data'=>"v2raystore"]];
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
            $keys[] = [['text'=>"👤 " . $displayName, "callback_data"=>"v2raystore"]];
            $keys[] = [
                ['text'=>"❌ حذف ادمین", 'callback_data'=>"delAdmin" . $uid],
                ['text'=>$receiptText, 'callback_data'=>"toggleAdminReceipt" . $uid]
            ];
        }
    }else{
        $keys[] = [['text'=>"لیست ادمین های فرعی خالی است ❕",'callback_data'=>"v2raystore"]];
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
                ['text'=>"یوزرنیم",'callback_data'=>"v2raystore"]
                ],
            [
                ['text'=>$fullName??" ",'callback_data'=>"v2raystore"],
                ['text'=>"نام",'callback_data'=>"v2raystore"]
                ],
            [
                ['text'=>$boughtService??" ",'callback_data'=>"v2raystore"],
                ['text'=>"سرویس ها",'callback_data'=>"v2raystore"]
                ],
            [
                ['text'=>$totalBoughtPrice??" ",'callback_data'=>"v2raystore"],
                ['text'=>"مبلغ خرید",'callback_data'=>"v2raystore"]
                ],
            [
                ['text'=>$userWallet??" ",'callback_data'=>"v2raystore"],
                ['text'=>"موجودی کیف پول",'callback_data'=>"v2raystore"]
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
        $keys[] = [['text'=>'حذف','callback_data'=>"v2raystore"],['text'=>"استفاده هر یوزر",'callback_data'=>"v2raystore"],['text'=>"تاریخ ختم",'callback_data'=>"v2raystore"],['text'=>"تعداد استفاده",'callback_data'=>"v2raystore"],['text'=>"مقدار تخفیف",'callback_data'=>"v2raystore"],['text'=>"کد تخفیف",'callback_data'=>"v2raystore"]];
        while($row = $list->fetch_assoc()){
            $date = $row['expire_date']!=0?jdate("Y/n/j H:i", $row['expire_date']):"نامحدود";
            $count = $row['expire_count']!=-1?$row['expire_count']:"نامحدود";
            $amount = $row['amount'];
            $amount = $row['type'] == 'percent'? $amount."%":$amount = number_format($amount) . " تومان";
            $hashId = $row['hash_id'];
            $rowId = $row['id'];
            $canUse = $row['can_use'];
            
            $keys[] = [['text'=>'❌','callback_data'=>"delDiscount" . $rowId],['text'=>$canUse, 'callback_data'=>"v2raystore"],['text'=>$date,'callback_data'=>"v2raystore"],['text'=>$count,'callback_data'=>"v2raystore"],['text'=>$amount,'callback_data'=>"v2raystore"],['text'=>$hashId,'callback_data'=>'copyHash' . $hashId]];
        }
    }else{
        $keys[] = [['text'=>"کد تخفیفی یافت نشد",'callback_data'=>"v2raystore"]];
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
                        ['text'=>$title??" " ,'callback_data'=>"v2raystore"]];
        }
    }else{
        $keys[] = [['text'=>"دکمه ای یافت نشد ❕",'callback_data'=>"v2raystore"]];
    }
    $keys[] = [['text'=>"افزودن دکمه جدید ➕",'callback_data'=>"addNewMainButton"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}


if(!function_exists('v2raystore_base64UrlDecodeLoose')){
function v2raystore_base64UrlDecodeLoose($data){
    $data = strtr((string)$data, '-_', '+/');
    $pad = strlen($data) % 4;
    if($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}
}

if(!function_exists('v2raystore_configLinkDomainLabel')){
function v2raystore_configLinkDomainLabel($link, $index = 0){
    $link = trim((string)$link);
    $domain = '';

    if(stripos($link, 'vmess://') === 0){
        $raw = substr($link, 8);
        $decoded = v2raystore_base64UrlDecodeLoose($raw);
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

if(!function_exists('v2raystore_normalizeConfigLinksArray')){
function v2raystore_normalizeConfigLinksArray($links){
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

if(!function_exists('v2raystore_formatConfigLinksBlock')){
function v2raystore_formatConfigLinksBlock($links, $titlePrefix = 'کانفیگ با دامنه', $includeAdvice = true){
    $links = v2raystore_normalizeConfigLinksArray($links);
    if(empty($links)) return '';

    if(count($links) === 1){
        return "\n <code>" . htmlspecialchars($links[0], ENT_QUOTES, 'UTF-8') . "</code>";
    }

    $text = "";
    foreach($links as $i => $link){
        $domain = v2raystore_configLinkDomainLabel($link, $i);
        $text .= "\n🌐 {$titlePrefix} " . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . ":\n";
        $text .= "<code>" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "</code>\n";
    }

    if($includeAdvice){
        $text .= "\nℹ️ لطفاً همه کانفیگ‌ها را در برنامه خود اضافه کنید و هرکدام کیفیت و پایداری بهتری داشت، از همان استفاده کنید.";
    }
    return $text;
}
}

if(!function_exists('v2raystore_buildMultiDomainConfigMessage')){
function v2raystore_buildMultiDomainConfigMessage($remark, $links, $subLink = '', $heading = '✅ کانفیگ‌های سرویس شما آماده شد', $extraLines = ''){
    $links = v2raystore_normalizeConfigLinksArray($links);
    if(count($links) <= 1) return '';

    $remark = htmlspecialchars((string)$remark, ENT_QUOTES, 'UTF-8');
    $msg = $heading . "\n";
    if($remark !== '') $msg .= "🔮 نام سرویس: <b>{$remark}</b>\n";
    $extraLines = trim((string)$extraLines);
    if($extraLines !== '') $msg .= $extraLines . "\n";
    $msg .= v2raystore_formatConfigLinksBlock($links, 'کانفیگ با دامنه', true);

    $subLink = trim((string)$subLink);
    if($subLink !== ''){
        $msg .= "\n\n🌐 لینک اشتراک:\n<code>" . htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') . "</code>";
    }
    return $msg;
}
}

if(!function_exists('v2raystore_sendMultiDomainConfigMessage')){
function v2raystore_sendMultiDomainConfigMessage($chatId, $remark, $links, $subLink = '', $serverType = '', $keyboard = null, $heading = null, $extraLines = ''){
    global $botState, $buttonValues;
    $links = v2raystore_normalizeConfigLinksArray($links);
    if(count($links) <= 1) return false;
    if(($botState['configLinkState'] ?? '') == 'off') return false;
    if($serverType === 'marzban') return false;

    if($heading === null || trim((string)$heading) === '') $heading = '✅ کانفیگ‌های سرویس شما آماده شد';
    $msg = v2raystore_buildMultiDomainConfigMessage($remark, $links, $subLink, $heading, $extraLines);
    if(trim($msg) === '') return false;

    if($keyboard === null){
        $backText = $buttonValues['back_to_main'] ?? 'بازگشت به منوی اصلی';
        $keyboard = json_encode(['inline_keyboard'=>[[['text'=>$backText,'callback_data'=>'mainMenu']]]]);
    }
    $sendRes = sendMessage($msg, $keyboard, 'HTML', $chatId);
    if(function_exists('v2raystore_telegramResponseOk') && v2raystore_telegramResponseOk($sendRes)) return true;

    // اگر HTML یا شبکه تلگرام خطا داد، یک بار متن ساده ارسال می‌شود تا کانفیگ به کاربر برسد.
    $plain = function_exists('v2raystore_plainTextFromHtml') ? v2raystore_plainTextFromHtml($msg) : strip_tags($msg);
    $sendRes = sendMessage($plain, $keyboard, null, $chatId);
    return function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($sendRes) : true;
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
        $v2raystoreplanaccnumber = $stmt->get_result()->num_rows;
        $stmt->close();

        $srvid= $pd['server_id'];
        $keyboard = [
            ($rahgozar==true?[['text'=>"* نوع پلن: رهگذر *",'callback_data'=>'v2raystore']]:[]),
            ($rahgozar==true?[
                ['text'=>$customPath,'callback_data'=>'changeCustomPath' . $id],
                ['text'=>"Path Custom",'callback_data'=>'v2raystore'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customPort,'callback_data'=>'changeCustomPort' . $id],
                ['text'=>"پورت دلخواه",'callback_data'=>'v2raystore'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customSni,'callback_data'=>'changeCustomSni' . $id],
                ['text'=>"sni دلخواه",'callback_data'=>'v2raystore'],
                ]:[]),
            [['text'=>$customDomainText,'callback_data'=>'changeCustomDomain' . $id],['text'=>"🌐 دامنه اختصاصی پلن",'callback_data'=>"v2raystore"]],
            [['text'=>$name,'callback_data'=>"v2raystoreplanname$id"],['text'=>"🔮 نام پلن",'callback_data'=>"v2raystore"]],
            ($reality == "true"?[['text'=>$dest,'callback_data'=>"editDestName$id"],['text'=>"dest",'callback_data'=>"v2raystore"]]:[]),
            ($reality == "true"?[['text'=>$serverName,'callback_data'=>"editServerNames$id"],['text'=>"serverNames",'callback_data'=>"v2raystore"]]:[]),
            ($reality == "true"?[['text'=>$spiderX,'callback_data'=>"editSpiderX$id"],['text'=>"spiderX",'callback_data'=>"v2raystore"]]:[]),
            ($reality == "true"?[['text'=>$flow,'callback_data'=>"editFlow$id"],['text'=>"flow",'callback_data'=>"v2raystore"]]:[]),
            [['text'=>$v2raystoreplanaccnumber,'callback_data'=>"v2raystore"],['text'=>"🎗 تعداد اکانت های فروخته شده",'callback_data'=>"v2raystore"]],
            ($pd['inbound_id'] != 0?[['text'=>"$acount",'callback_data'=>"v2raystoreplanslimit$id"],['text'=>"🚪 تغییر ظرفیت کانفیگ",'callback_data'=>"v2raystore"]]:[]),
            ($pd['inbound_id'] != 0?[['text'=>$pd['inbound_id'],'callback_data'=>"v2raystoreplansinobundid$id"],['text'=>"🚪 سطر کانفیگ",'callback_data'=>"v2raystore"]]:[]),
            [['text'=>"✏️ ویرایش توضیحات",'callback_data'=>"v2raystoreplaneditdes$id"]],
            [['text'=>number_format($price) . " تومان",'callback_data'=>"v2raystoreplanrial$id"],['text'=>"💰 قیمت پلن",'callback_data'=>"v2raystore"]],
            [['text'=>"♻️ دریافت لیست اکانت ها",'callback_data'=>"v2raystoreplanacclist$id"]],
            ($server_info['type'] == "marzban"?[['text'=>"انتخاب Host",'callback_data'=>"marzbanHostSettings" . $id]]:[]),
            [['text'=>"✂️ حذف",'callback_data'=>"v2raystoreplandelete$id"]],
            [['text' => $buttonValues['back_button'], 'callback_data' =>"plansList$srvid"]]
            ];
        return json_encode(['inline_keyboard'=>$keyboard]);
    }
}
function getUserOrderDetailKeys($id, $offset = 0){
    global $connection, $botState, $mainValues, $buttonValues, $botUrl, $from_id, $admin, $userInfo;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    

    if($order->num_rows==0){
        return null;
    }else {
        $order = $order->fetch_assoc();
        $syncInfo = v2raystore_syncOrderExpiryFromPanel($order, true);
        if(v2raystore_cleanupOrderIfMissingOnPanel($order, $syncInfo, false)){
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
        $configNote = function_exists('v2raystore_safeConfigNoteText') ? v2raystore_safeConfigNoteText($order['config_note'] ?? '') : trim((string)($order['config_note'] ?? ''));
        
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
        $leftDays = function_exists('v2raystore_formatRemainingDaysText') ? v2raystore_formatRemainingDaysText($order['expire_date'] ?? 0) : 'نامشخص';
        $acc_link = v2raystore_normalizeConfigLinksArray($acc_link);
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
            $configLinks = v2raystore_formatConfigLinksBlock($pagedLinks);
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
    			    ['text' => $userId, 'callback_data' => "v2raystore"],
                    ['text' => "آیدی کاربر", 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => $firstName, 'callback_data' => "v2raystore"],
                    ['text' => "اسم کاربر", 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => $isAgentBought, 'callback_data' => "v2raystore"],
                    ['text' => "خرید نماینده", 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => "$name", 'callback_data' => "v2raystore"],
                    ['text' => $buttonValues['plan_name'], 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => "$date ", 'callback_data' => "v2raystore"],
                    ['text' => $buttonValues['buy_date'], 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => "$expire_date ", 'callback_data' => "v2raystore"],
                    ['text' => $buttonValues['expire_date'], 'callback_data' => "v2raystore"],
                ],
                [
    			    ['text' => " $leftgb", 'callback_data' => "v2raystore"],
                    ['text' => $buttonValues['volume_left'], 'callback_data' => "v2raystore"],
    			],
                [
                    ['text' => $leftDays, 'callback_data' => "v2raystore"],
                    ['text' => '⏳ روزهای باقی‌مانده', 'callback_data' => "v2raystore"],
                ],
                [
                    ['text' => $buttonValues['selected_protocol'], 'callback_data' => "v2raystore"],
                ]);
                
        if($inbound_id == 0){
            if($protocol == 'trojan') {
                if($security == "xtls"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }else{
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
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
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
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
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
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
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
                        ]:
                            [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "v2raystore"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "v2raystore"],
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
                    ['text' => " $protocol ☑️", 'callback_data' => "v2raystore"],
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
        
        $customerSubLink = v2raystore_makeCustomerSubLink($server_id, $token, $uuid, $inbound_id, $remark);
        $subLink = ($botState['subLinkState'] == "on" && $customerSubLink != "") ? "<code>" . $customerSubLink . "</code>" : "";

        
        $enable = $enable == true? $buttonValues['active']:$buttonValues['deactive'];
        $msg = v2raystore_buildConfigDetailsMessage($enable, $remark, $configLinks, $subLink, $configNote);

        if(($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)){
            $keyboard[] = [['text' => $buttonValues['change_config_location'] ?? '🌎 تغییر لوکیشن', 'callback_data' => "switchLocation{$id}", 'style'=>'primary']];
        }
    
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        return ["keyboard"=>v2raystore_inlineKeyboardJson($keyboard),
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
        $syncInfo = v2raystore_syncOrderExpiryFromPanel($order, true);
        if(v2raystore_cleanupOrderIfMissingOnPanel($order, $syncInfo, false)){
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
        $configNote = function_exists('v2raystore_safeConfigNoteText') ? v2raystore_safeConfigNoteText($order['config_note'] ?? '') : trim((string)($order['config_note'] ?? ''));
        
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
        $leftDays = function_exists('v2raystore_formatRemainingDaysText') ? v2raystore_formatRemainingDaysText($order['expire_date'] ?? 0) : 'نامشخص';
        $acc_link = v2raystore_normalizeConfigLinksArray($acc_link);
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
            $configLinks = v2raystore_formatConfigLinksBlock($pagedLinks);
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
			    ['text' => $name, 'callback_data' => "v2raystore"],
                ['text' => $buttonValues['plan_name'], 'callback_data' => "v2raystore"],
            ],
            [
			    ['text' => $date, 'callback_data' => "v2raystore"],
                ['text' => $buttonValues['buy_date'], 'callback_data' => "v2raystore"],
            ],
            [
			    ['text' => $expire_date, 'callback_data' => "v2raystore"],
                ['text' => $buttonValues['expire_date'], 'callback_data' => "v2raystore"],
            ],
            [
			    ['text' => $leftgb, 'callback_data' => "v2raystore"],
                ['text' => $buttonValues['volume_left'], 'callback_data' => "v2raystore"],
			],
            [
                ['text' => $leftDays, 'callback_data' => "v2raystore"],
                ['text' => '⏳ روزهای باقی‌مانده', 'callback_data' => "v2raystore"],
            ],
            ($serverType != "marzban"?
			[
                ['text' => $buttonValues['selected_protocol'], 'callback_data' => "v2raystore"],
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
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}"];
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
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}" ];
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
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}" ];
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
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}" ];
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
                            if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}" ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
    
                    }
                }
            }else{
                if($serverType != "marzban"){
                    array_push($keyboard, [
                            ['text' => " $protocol ☑️", 'callback_data' => "v2raystore"],
                        ]);
                }
                
                $temp = array();
                if($price != 0 || $agentBought == true){
                    if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                    if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}" ];
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
        
        $customerSubLink = v2raystore_makeCustomerSubLink($server_id, $token, $uuid, $inbound_id, $remark);
        $subLink = ($botState['subLinkState'] == "on" && $customerSubLink != "") ? "<code>" . $customerSubLink . "</code>" : "";

        $msg = v2raystore_buildConfigDetailsMessage($enable, $remark, $configLinks, $subLink, $configNote);
        
        
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
        if(function_exists('v2raystore_prepareMyConfigDetailKeyboard')){
            $keyboard = v2raystore_prepareMyConfigDetailKeyboard($keyboard, $id, $agentBought, $configNote);
        }
        return ["keyboard"=>v2raystore_inlineKeyboardJson($keyboard),
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
        $approvalStatus = (v2raystore_getNewMemberAccessMode($botState) === 'approval' && $from_id != $admin) ? 'pending' : 'approved';
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
            $deleteUrls = [];
            $deleteUrls[] = "$panel_url/panel/api/inbounds/" . $inbound_id . "/delClient/" . rawurlencode($uuid);
            if(!empty($email)) $deleteUrls[] = "$panel_url/panel/api/inbounds/" . $inbound_id . "/delClient/" . rawurlencode($email);
            if(!empty($email)) $deleteUrls[] = "$panel_url/panel/api/clients/del/" . rawurlencode($email);
            $lastDecoded = null;
            foreach(array_values(array_unique($deleteUrls)) as $deleteUrl){
                v2raystore_sanaeiNewJsonPost($curl, $deleteUrl, $session, null);
                $rawDelete = curl_exec($curl);
                $decodedDelete = json_decode((string)$rawDelete);
                $lastDecoded = $decodedDelete ?: (object)['success'=>false, 'msg'=>(string)$rawDelete];
                if(is_object($lastDecoded) && (!isset($lastDecoded->success) || !empty($lastDecoded->success))){
                    curl_close($curl);
                    if(!empty($email)) resetIpLog($server_id, $email);
                    return $lastDecoded;
                }
            }
            curl_close($curl);
            return $lastDecoded ?: (object)['success'=>false, 'msg'=>'حذف کلاینت در Sanaei New ناموفق بود.'];
        }
        
        if($serverType == "sanaei" || $serverType == "alireza"){
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
        // 3x-ui/Sanaei New برای حذف کلاینت بدنه نمی‌خواهد؛ ارسال بدنه باعث حذف‌نشدن در بعضی نسخه‌ها می‌شود.
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, null);
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    $exactEdit = is_array($editType) ? $editType : [];
    $exactTotalBytes = array_key_exists('total_bytes', $exactEdit) ? intval($exactEdit['total_bytes']) : null;
    $exactExpireMs = array_key_exists('expire_ms', $exactEdit) ? intval($exactEdit['expire_ms']) : null;

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
    if($exactExpireMs !== null){
        $expire_microdate = $exactExpireMs;
    }elseif($days != 0) {
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
    }

    if($exactTotalBytes !== null){
        $total = $exactTotalBytes;
        $volume = $exactTotalBytes;
    }elseif($volume != 0){
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

    $renewSettings = $row->settings;
    $forceEnableAfterRenew = ($editType == "renew") || (is_array($editType) && !empty($editType['force_enable']));
    if($forceEnableAfterRenew){
        $settingsArr = json_decode($renewSettings, true);
        if(is_array($settingsArr) && isset($settingsArr['clients'][0]) && is_array($settingsArr['clients'][0])){
            $settingsArr['clients'][0]['enable'] = true;
            if(!isset($settingsArr['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")){
                $settingsArr['clients'][0]['subId'] = RandomString(16);
            }
            $renewSettings = json_encode($settingsArr, 488);
        }
    }

    $dataArr = array('up' => $up,'down' => $down,'total' => is_null($total) ? $row->total : $total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => is_null($expire_microdate) ? $row->expiryTime : $expire_microdate, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $renewSettings,
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    $exactEdit = is_array($editType) ? $editType : [];
    $exactTotalBytes = array_key_exists('total_bytes', $exactEdit) ? intval($exactEdit['total_bytes']) : null;
    $exactExpireMs = array_key_exists('expire_ms', $exactEdit) ? intval($exactEdit['expire_ms']) : null;

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
    if($exactTotalBytes !== null){
        $settings['clients'][$client_key]['totalGB'] = $exactTotalBytes;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }elseif($volume != 0){
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
    
    if($exactExpireMs !== null){
        $settings['clients'][$client_key]['expiryTime'] = $exactExpireMs;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }elseif($days != 0){
        $expiryTime = $settings['clients'][$client_key]['expiryTime'];
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
        $settings['clients'][$client_key]['expiryTime'] = $expire_microdate;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    $forceEnableAfterRenew = ($editType == "renew") || (is_array($editType) && !empty($editType['force_enable']));
    if($forceEnableAfterRenew && isset($settings['clients'][$client_key]) && is_array($settings['clients'][$client_key])){
        $settings['clients'][$client_key]['enable'] = true;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "sanaei_new" || $serverType == "alireza")){
            $settings['clients'][$client_key]['subId'] = RandomString(16);
        }
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, $editedClient);
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
            v2raystore_sanaeiNewJsonPost($curl, $url, $session, null);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, null);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, null);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, array("client" => $clientToAdd, "inboundIds" => array((int)$inbound_id)));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    if($serverType == 'sanaei_new' && $rahgozar == false && $customPath == false && intval($customPort) == 0 && $customSni === null && v2raystore_normalizePlanDomainInput($customDomain) === ''){
        $panelLinks = v2raystore_sanaeiNewClientLinksFromPanel($server_id, $remark, $uniqid, $inbound_id);
        if(!empty($panelLinks)) return $panelLinks;
    }
    preg_match("/^Host:(.*)/i",$request_header,$hostMatch);

    $panel_url = str_ireplace('http://','',$panel_url);
    $panel_url = str_ireplace('https://','',$panel_url);
    $panel_url = strtok($panel_url,":");
    if($server_ip == '') $server_ip = $panel_url;
    $planDomain = v2raystore_normalizePlanDomainInput($customDomain);
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
                    $wsData = v2raystore_extractWsSettings($row->streamSettings, $server_ip);
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
                    $wsData = v2raystore_extractWsSettings($row->streamSettings, $server_ip);
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
        
        
                $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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
            $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
        
        
                $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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
            $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
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
    if(!empty($info['force_active'])) $configState = "active";
    
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
    return v2raystore_normalizeSanaeiNewResponse($decoded, $serverType);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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



        $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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

        $streamSettings = v2raystore_pickStreamSettings($netType, $tcpSettings, $wsSettings, $security, $tlsSettings, $xtlsTitle, $request_header, $header_type, $serverType);
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
    curl_setopt($curl, CURLOPT_HTTPHEADER, v2raystore_panelLoginHeaders($curl, $loginUrl));
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
        v2raystore_sanaeiNewJsonPost($curl, $url, $session, v2raystore_sanaeiNewDecodePayloadJsonFields($dataArr));
    }
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}


// ===== V2Ray Store extra realtime reports + auto order approval =====
function v2raystore_ensureAutoOrderColumns(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('AUTO_ORDER_REPORTS_V4')) return;

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

    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('AUTO_ORDER_REPORTS_V4');
}
v2raystore_ensureAutoOrderColumns();

function v2raystore_ensureAdminReceiptColumns(){
    global $connection;
    if(function_exists('v2raystore_schemaPatchDone') && v2raystore_schemaPatchDone('ADMIN_RECEIPT_SETTINGS_V1')) return;

    $exists = @($connection->query("SHOW COLUMNS FROM `users` LIKE 'receive_order_receipts'"));
    if($exists && $exists->num_rows == 0){
        @($connection->query("ALTER TABLE `users` ADD `receive_order_receipts` tinyint(1) NOT NULL DEFAULT 0 AFTER `isAdmin`"));
    }

    if(function_exists('v2raystore_markSchemaPatchDone')) v2raystore_markSchemaPatchDone('ADMIN_RECEIPT_SETTINGS_V1');
}
v2raystore_ensureAdminReceiptColumns();

function v2raystore_h($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function v2raystore_plainTextForTelegram($text){
    $text = (string)$text;
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

function v2raystore_translateTechnicalError($text){
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
    }elseif(strpos($lower, 'button_user_privacy_restricted') !== false){
        $fa = 'تلگرام اجازه ساخت دکمه رفتن به پی‌وی این کاربر را نداد. این محدودیت از سمت حریم خصوصی کاربر/تلگرام است؛ ربات باید پیام را بدون دکمه پی‌وی ارسال کند.';
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

function v2raystore_userPrivateUrl($userId){
    return 'tg://user?id=' . intval($userId);
}

function v2raystore_userPrivateButton($userId, $text = '👤 رفتن به پی وی مشتری'){
    return ['text' => $text, 'url' => v2raystore_userPrivateUrl($userId), 'style' => 'primary'];
}

function v2raystore_isUserPrivacyButtonError($value){
    if(is_array($value)) $value = implode(' | ', array_map('strval', $value));
    $value = strtolower((string)$value);
    return strpos($value, 'button_user_privacy_restricted') !== false;
}

function v2raystore_stripPrivateUserButtons($markup, &$removed = false){
    $removed = false;
    if($markup === null || $markup === '') return $markup;

    $decoded = is_string($markup) ? json_decode($markup, true) : $markup;
    if(!is_array($decoded) || !isset($decoded['inline_keyboard']) || !is_array($decoded['inline_keyboard'])) return $markup;

    $rows = [];
    foreach($decoded['inline_keyboard'] as $row){
        if(!is_array($row)) continue;
        $newRow = [];
        foreach($row as $button){
            if(!is_array($button)) continue;
            $url = strtolower(trim((string)($button['url'] ?? '')));
            if($url !== '' && (strpos($url, 'tg://user?id=') === 0 || strpos($url, 'tg://openmessage?user_id=') === 0)){
                $removed = true;
                continue;
            }
            $newRow[] = $button;
        }
        if(count($newRow) > 0) $rows[] = $newRow;
    }

    if(!$removed) return $markup;
    $decoded['inline_keyboard'] = $rows;
    if(count($rows) == 0) return null;
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v2raystore_formatUserLine($userId, $name = '', $username = ''){
    $userId = intval($userId);
    $name = trim((string)$name) !== '' ? trim((string)$name) : ('کاربر ' . $userId);
    $username = trim((string)$username);
    $username = ($username !== '' && $username !== 'ندارد' && $username !== ' ندارد ') ? '@' . ltrim($username, '@') : 'ندارد';
    return "👤 کاربر: <a href='tg://user?id={$userId}'>" . v2raystore_h($name) . "</a>\n🆔 آیدی عددی: <code>{$userId}</code>\n🔸 یوزرنیم: " . v2raystore_h($username);
}


// ===== V2Ray Store report group topics + database backup tools =====
function v2raystore_reportForumEnabled(){
    global $botState;
    return (($botState['storeReportForumState'] ?? 'off') === 'on');
}

function v2raystore_reportTopicItems(){
    return [
        // شروع خرید و اکانت تست از گزارش‌های مالی نهایی جدا هستند تا تاپیک خرید و پرداخت شلوغ نشود.
        'purchase_start' => ['title'=>'🟡 شروع خرید و اکانت تست', 'events'=>['purchase_started','test_account']],
        // این تاپیک فقط برای گزارش‌های انجام‌شده/مالی مثل تایید خودکار، خرید کیف پولی، تمدید و افزایش‌ها استفاده می‌شود.
        'purchase' => ['title'=>'🛒 خرید و پرداخت', 'events'=>['payment_approved','auto_approved']],
        'location' => ['title'=>'🌎 تغییر لوکیشن', 'events'=>['server_switched']],
        'stats' => ['title'=>'📊 آمار ربات', 'events'=>['daily_stats']],
        'errors' => ['title'=>'⚠️ خطاها و هشدارها', 'events'=>['approval_failed','admin_order_send_failed']],
        'database' => ['title'=>'🗄 بکاپ دیتابیس', 'events'=>['database_backup']],
    ];
}

function v2raystore_reportTopicKeyForEvent($eventKey){
    $eventKey = trim((string)$eventKey);
    foreach(v2raystore_reportTopicItems() as $key => $info){
        if(in_array($eventKey, $info['events'], true)) return $key;
    }
    return 'general';
}

function v2raystore_reportTopicStore(){
    global $botState;
    $raw = $botState['storeReportForumTopics'] ?? '';
    if(is_array($raw)) return $raw;
    $raw = trim((string)$raw);
    if($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function v2raystore_saveReportTopicStore($topics){
    if(!is_array($topics)) $topics = [];
    setSettings('storeReportForumTopics', json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function v2raystore_reportTopicEnabled($topicKey){
    $topicKey = trim((string)$topicKey);
    if($topicKey === '') return false;
    global $botState;
    return (($botState['storeReportTopicState_' . $topicKey] ?? 'on') === 'on');
}

function v2raystore_reportTopicHasEnabledEvents($topicKey){
    $items = v2raystore_reportTopicItems();
    if(!isset($items[$topicKey])) return true;
    foreach($items[$topicKey]['events'] as $eventKey){
        if(v2raystore_reportIsEnabled(v2raystore_reportEventKey($eventKey), 'on')) return true;
    }
    return false;
}

function v2raystore_reportCleanupLegacyTopics(){
    static $done = false;
    if($done) return;
    $done = true;
    if(!v2raystore_reportForumEnabled()) return;

    $topics = v2raystore_reportTopicStore();
    if(!is_array($topics) || count($topics) == 0) return;

    $validKeys = array_keys(v2raystore_reportTopicItems());
    foreach(array_keys($topics) as $topicKey){
        // تاپیک‌های قدیمی مثل test که در نسخه‌های قبلی جدا ساخته می‌شدند، بعد از تغییر دسته‌بندی حذف می‌شوند.
        if(!in_array($topicKey, $validKeys, true)) v2raystore_reportDeleteTopic($topicKey);
    }
}

function v2raystore_reportEnsureTopic($eventKey){
    v2raystore_reportCleanupLegacyTopics();
    $chat = v2raystore_getIncomeReportChatId();
    if($chat === null || trim((string)$chat) === '') return 0;
    if(!v2raystore_reportForumEnabled()) return 0;

    $topicKey = v2raystore_reportTopicKeyForEvent($eventKey);
    if(!v2raystore_reportTopicEnabled($topicKey)) return 0;

    $items = v2raystore_reportTopicItems();
    $title = $items[$topicKey]['title'] ?? ('📌 ' . $topicKey);
    $topics = v2raystore_reportTopicStore();
    $threadId = intval($topics[$topicKey] ?? 0);
    if($threadId > 0) return $threadId;

    $res = bot('createForumTopic', [
        'chat_id' => $chat,
        'name' => $title,
    ]);
    if(is_object($res) && !empty($res->ok) && isset($res->result->message_thread_id)){
        $threadId = intval($res->result->message_thread_id);
        if($threadId > 0){
            $topics[$topicKey] = $threadId;
            v2raystore_saveReportTopicStore($topics);
            return $threadId;
        }
    }
    return 0;
}

function v2raystore_reportDeleteTopic($topicKey){
    $chat = v2raystore_getIncomeReportChatId();
    $topicKey = trim((string)$topicKey);
    if($chat === null || trim((string)$chat) === '' || $topicKey === '') return false;
    $topics = v2raystore_reportTopicStore();
    $threadId = intval($topics[$topicKey] ?? 0);
    unset($topics[$topicKey]);
    v2raystore_saveReportTopicStore($topics);
    if($threadId <= 0) return false;
    $res = bot('deleteForumTopic', [
        'chat_id' => $chat,
        'message_thread_id' => $threadId,
    ]);
    return is_object($res) && !empty($res->ok);
}

function v2raystore_reportDeleteTopicForEvent($eventKey){
    $topicKey = v2raystore_reportTopicKeyForEvent($eventKey);
    if(!v2raystore_reportTopicHasEnabledEvents($topicKey)) return v2raystore_reportDeleteTopic($topicKey);
    return false;
}

function v2raystore_reportDeleteAllTopics(){
    $topics = v2raystore_reportTopicStore();
    foreach(array_keys($topics) as $topicKey){
        v2raystore_reportDeleteTopic($topicKey);
    }
    v2raystore_saveReportTopicStore([]);
}

function v2raystore_reportSendMessage($title, $body, $keyboard = null, $eventKey = null){
    $chat = v2raystore_getIncomeReportChatId();
    if($chat === null || trim((string)$chat) === '') return null;
    $keyboard = v2raystore_styleReplyMarkup($keyboard);
    $payload = [
        'chat_id' => $chat,
        'text' => $title . "\n\n" . $body,
        'reply_markup' => $keyboard,
        'parse_mode' => 'HTML',
        '_timeout' => 8,
    ];
    $threadId = 0;
    if($eventKey !== null){
        $threadId = v2raystore_reportEnsureTopic($eventKey);
        if($threadId > 0) $payload['message_thread_id'] = $threadId;
    }

    $res = bot('sendMessage', $payload);
    if(is_object($res) && !empty($res->ok)) return $res;

    $desc = is_object($res) && isset($res->description) ? (string)$res->description : '';
    // اگر تاپیک حذف/خراب شده باشد، گزارش نباید از بین برود؛ بدون تاپیک به خود گروه ارسال می‌شود.
    if($threadId > 0 && preg_match('/thread|topic|message thread|not found|invalid/i', $desc)){
        $topics = v2raystore_reportTopicStore();
        $topicKey = v2raystore_reportTopicKeyForEvent($eventKey);
        if(isset($topics[$topicKey])){
            unset($topics[$topicKey]);
            v2raystore_saveReportTopicStore($topics);
        }
        $fallback = $payload;
        unset($fallback['message_thread_id']);
        $res2 = bot('sendMessage', $fallback);
        if(is_object($res2) && !empty($res2->ok)) return $res2;
        $res = $res2;
        $desc = is_object($res) && isset($res->description) ? (string)$res->description : $desc;
    }

    // اگر HTML تلگرام به‌خاطر لینک/کاراکتر خاص خطا داد، نسخه بدون ParseMode ارسال شود تا گزارش قطع نشود.
    if(preg_match('/parse|entity|can\'t parse/i', $desc)){
        $fallback = $payload;
        unset($fallback['parse_mode']);
        $fallback['text'] = str_replace(['<b>','</b>','<code>','</code>','<i>','</i>'], '', $fallback['text']);
        $res2 = bot('sendMessage', $fallback);
        if(is_object($res2) && !empty($res2->ok)) return $res2;
        if(isset($fallback['message_thread_id'])){
            unset($fallback['message_thread_id']);
            $res3 = bot('sendMessage', $fallback);
            if(is_object($res3) && !empty($res3->ok)) return $res3;
            return $res3;
        }
        return $res2;
    }

    return $res;
}

function v2raystore_telegramSendLocalDocument($chatId, $filePath, $caption = '', $parse = 'HTML', $threadId = 0){
    global $botToken;
    $chatId = trim((string)$chatId);
    $filePath = (string)$filePath;
    if($chatId === '' || !is_file($filePath)) return null;
    if(!class_exists('CURLFile')) return null;

    $post = [
        'chat_id' => $chatId,
        'document' => new CURLFile($filePath),
        'caption' => (string)$caption,
        'parse_mode' => $parse,
    ];
    $threadId = intval($threadId);
    if($threadId > 0) $post['message_thread_id'] = $threadId;

    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/sendDocument');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if($err) return (object)['ok'=>false, 'description'=>$err];
    $decoded = json_decode((string)$res);
    return $decoded ?: (object)['ok'=>false, 'description'=>(string)$res];
}

function v2raystore_reportSendLocalDocument($filePath, $caption = '', $eventKey = 'database_backup'){
    $chat = v2raystore_getIncomeReportChatId();
    if($chat === null || trim((string)$chat) === '') return null;
    $threadId = v2raystore_reportEnsureTopic($eventKey);
    $res = v2raystore_telegramSendLocalDocument($chat, $filePath, $caption, 'HTML', $threadId);
    if(is_object($res) && !empty($res->ok)) return $res;
    $desc = is_object($res) && isset($res->description) ? (string)$res->description : '';
    if($threadId > 0 && preg_match('/thread|topic|message thread|not found|invalid/i', $desc)){
        $topics = v2raystore_reportTopicStore();
        $topicKey = v2raystore_reportTopicKeyForEvent($eventKey);
        if(isset($topics[$topicKey])){
            unset($topics[$topicKey]);
            v2raystore_saveReportTopicStore($topics);
        }
        $res2 = v2raystore_telegramSendLocalDocument($chat, $filePath, $caption, 'HTML', 0);
        if(is_object($res2) && !empty($res2->ok)) return $res2;
        return $res2;
    }
    return $res;
}

function v2raystore_backupBotDbEnabled(){
    global $botState;
    return (($botState['storeBackupBotDbState'] ?? 'off') === 'on');
}

function v2raystore_reportBackupTime(){
    // Backward-compatible helper for old installs. The new backup scheduler is interval-based.
    global $botState;
    $time = trim((string)($botState['storeReportBackupTime'] ?? '03:30'));
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '03:30';
    return $time;
}

function v2raystore_reportBackupIntervalMinutes(){
    global $botState;
    $minutes = intval($botState['storeReportBackupIntervalMinutes'] ?? 1440);
    if($minutes < 10) $minutes = 10;
    if($minutes > 43200) $minutes = 43200; // 30 days
    return $minutes;
}

function v2raystore_reportBackupItemDelaySeconds(){
    global $botState;
    $seconds = intval($botState['storeReportBackupItemDelaySeconds'] ?? 15);
    if($seconds < 0) $seconds = 0;
    if($seconds > 300) $seconds = 300;
    return $seconds;
}

function v2raystore_formatMinutesFa($minutes){
    $minutes = intval($minutes);
    if($minutes <= 0) return 'نامعتبر';
    if($minutes % 1440 === 0){
        $d = intval($minutes / 1440);
        return $d == 1 ? 'هر روز' : 'هر ' . $d . ' روز';
    }
    if($minutes % 60 === 0){
        $h = intval($minutes / 60);
        return $h == 1 ? 'هر ۱ ساعت' : 'هر ' . $h . ' ساعت';
    }
    return 'هر ' . $minutes . ' دقیقه';
}

function v2raystore_parseBackupIntervalMinutes($input){
    $txt = trim((string)$input);
    if($txt === '') return 0;
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $txt = strtr($txt, $map);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
    $lower = str_replace(['هر', 'یک', 'يه', 'یك'], ['', '1', '1', '1'], $lower);
    $lower = trim(preg_replace('/\s+/u', ' ', $lower));
    if(strpos($lower, 'نیم') !== false || strpos($lower, 'نيم') !== false) return 30;
    if(preg_match('/(\d+)\s*(روز|day|days|d)\b/u', $lower, $m)) return max(10, intval($m[1]) * 1440);
    if(preg_match('/(\d+)\s*(ساعت|hour|hours|h)\b/u', $lower, $m)) return max(10, intval($m[1]) * 60);
    if(preg_match('/(\d+)\s*(دقیقه|دقيقه|min|mins|minute|minutes|m)\b/u', $lower, $m)) return max(10, intval($m[1]));
    if(preg_match('/^\d+$/', $lower)) return max(10, intval($lower));
    return 0;
}

function v2raystore_reportBackupLastTimestamp(){
    global $botState;
    $ts = intval($botState['storeReportBackupLastTs'] ?? 0);
    if($ts <= 0){
        // Compatibility with the previous daily scheduler.
        $lastDate = trim((string)($botState['storeReportBackupLastDate'] ?? ''));
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastDate)){
            $tmp = strtotime($lastDate . ' ' . v2raystore_reportBackupTime());
            if($tmp) $ts = intval($tmp);
        }
    }
    return $ts;
}

function v2raystore_reportBackupNextTimestamp(){
    $last = v2raystore_reportBackupLastTimestamp();
    if($last <= 0) return 0;
    return $last + (v2raystore_reportBackupIntervalMinutes() * 60);
}

function v2raystore_reportBackupDue(){
    $last = v2raystore_reportBackupLastTimestamp();
    if($last <= 0) return true;
    return time() >= ($last + (v2raystore_reportBackupIntervalMinutes() * 60));
}

function v2raystore_panelDbBackupEnabled($serverId){
    global $botState;
    $serverId = intval($serverId);
    if($serverId <= 0) return false;
    return (($botState['storePanelDbBackup_' . $serverId] ?? 'off') === 'on');
}

function v2raystore_anyPanelDbBackupEnabled(){
    global $connection;
    $res = @($connection->query("SELECT `id` FROM `server_info`"));
    if(!$res) return false;
    while($row = $res->fetch_assoc()){
        if(v2raystore_panelDbBackupEnabled(intval($row['id']))) return true;
    }
    return false;
}

function v2raystore_backupFeatureEnabled(){
    return v2raystore_reportIsEnabled(v2raystore_reportEventKey('database_backup'), 'on') && (v2raystore_backupBotDbEnabled() || v2raystore_anyPanelDbBackupEnabled());
}

function v2raystore_makeTempDir($prefix = 'v2raystore_backup_'){
    $base = sys_get_temp_dir();
    $dir = $base . '/' . $prefix . date('Ymd_His') . '_' . mt_rand(1000,9999);
    if(!is_dir($dir)) @mkdir($dir, 0700, true);
    return is_dir($dir) ? $dir : $base;
}

function v2raystore_gzipFileIfPossible($file){
    $file = (string)$file;
    if(!is_file($file)) return $file;
    $gz = $file . '.gz';
    if(function_exists('gzopen')){
        $in = @fopen($file, 'rb');
        $out = @gzopen($gz, 'wb9');
        if($in && $out){
            while(!feof($in)) gzwrite($out, fread($in, 1024 * 1024));
            fclose($in); gzclose($out);
            @unlink($file);
            if(is_file($gz)) return $gz;
        }
        if($in) @fclose($in);
        if($out) @gzclose($out);
    }
    return $file;
}

function v2raystore_createBotDatabaseBackupFile(){
    global $dbUserName, $dbPassword, $dbName;
    $dir = v2raystore_makeTempDir('v2raystore_bot_db_');
    $file = $dir . '/v2raystore_bot_db_' . date('Y-m-d_H-i-s') . '.sql';
    $cmd = 'MYSQL_PWD=' . escapeshellarg((string)$dbPassword) . ' mysqldump --single-transaction --quick --default-character-set=utf8mb4 -u ' . escapeshellarg((string)$dbUserName) . ' ' . escapeshellarg((string)$dbName) . ' > ' . escapeshellarg($file) . ' 2>' . escapeshellarg($file . '.err');
    @exec($cmd, $out, $code);
    if($code !== 0 || !is_file($file) || filesize($file) <= 0){
        $err = is_file($file . '.err') ? trim((string)@file_get_contents($file . '.err')) : '';
        return ['ok'=>false, 'message'=>$err !== '' ? $err : 'mysqldump اجرا نشد یا خروجی خالی بود.'];
    }
    @unlink($file . '.err');
    return ['ok'=>true, 'file'=>$file];
}

function v2raystore_panelLoginSessionForBackup($server){
    $panel = rtrim((string)($server['panel_url'] ?? ''), '/');
    if($panel === '') return ['ok'=>false, 'message'=>'آدرس پنل خالی است.'];

    $username = (string)($server['username'] ?? '');
    $password = (string)($server['password'] ?? '');
    $baseCandidates = [$panel];
    $parts = @parse_url($panel);
    if(is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])){
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if($origin !== $panel) $baseCandidates[] = rtrim($origin, '/');
    }
    $baseCandidates = array_values(array_unique(array_filter($baseCandidates)));

    $lastError = '';
    $foundBases = [];
    foreach($baseCandidates as $base){
        $loginUrl = rtrim($base, '/') . '/login';
        $formHeaders = function_exists('v2raystore_panelLoginHeaders') ? v2raystore_panelLoginHeaders(null, $loginUrl) : [];
        $jsonHeaders = [];
        foreach($formHeaders as $h){
            if(stripos($h, 'Content-Type:') !== 0) $jsonHeaders[] = $h;
        }
        $jsonHeaders[] = 'Content-Type: application/json';
        $attempts = [
            ['body'=>http_build_query(['username'=>$username, 'password'=>$password]), 'headers'=>$formHeaders],
            ['body'=>json_encode(['username'=>$username, 'password'=>$password], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'headers'=>$jsonHeaders],
        ];

        foreach($attempts as $attempt){
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $loginUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $attempt['body'],
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => $attempt['headers'],
            ]);
            $response = curl_exec($ch);
            $err = curl_error($ch);
            $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            $headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
            $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if($err){
                $lastError = $err;
                continue;
            }
            $headerText = substr((string)$response, 0, $headerSize);
            $body = substr((string)$response, $headerSize);
            $session = function_exists('v2raystore_sanaeiCollectCookiesFromHeader') ? v2raystore_sanaeiCollectCookiesFromHeader($headerText) : '';
            $decoded = json_decode($body, true);

            if($effective !== ''){
                $effBase = preg_replace('~/login/?$~i', '', rtrim($effective, '/'));
                if($effBase && $effBase !== $effective) $foundBases[] = $effBase;
            }

            $success = is_array($decoded) && (!empty($decoded['success']) || (!isset($decoded['success']) && $http < 400 && $session !== ''));
            if($session !== '' && $success){
                $bases = array_values(array_unique(array_filter(array_merge([$base], $foundBases, $baseCandidates))));
                return ['ok'=>true, 'panel'=>$base, 'panel_bases'=>$bases, 'session'=>$session];
            }
            if(is_array($decoded) && isset($decoded['msg'])) $lastError = (string)$decoded['msg'];
            elseif($http >= 400) $lastError = 'HTTP ' . $http;
        }
    }
    return ['ok'=>false, 'message'=>$lastError !== '' ? $lastError : 'ورود به پنل ناموفق بود.'];
}

function v2raystore_panelBackupFileNameFromHeaders($headers, $fallback = 'x-ui.db'){
    $headers = (string)$headers;
    if(preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\'\r\n;]+)/i', $headers, $m)){
        $name = urldecode(trim($m[1], " \t\r\n\"'"));
        $name = basename($name);
        if($name !== '') return $name;
    }
    return $fallback;
}

function v2raystore_isValidPanelBackupBody($body, $headers = ''){
    if($body === false || $body === null) return false;
    $body = (string)$body;
    if(strlen($body) < 64) return false;
    if(strncmp($body, "SQLite format 3\0", 16) === 0) return true;

    $trim = ltrim(substr($body, 0, 2048));
    if($trim === '') return false;
    if($trim[0] === '{' || $trim[0] === '[') return false;
    if(stripos($trim, '<!doctype') === 0 || stripos($trim, '<html') !== false || stripos($trim, '<head') !== false) return false;

    $headers = strtolower((string)$headers);
    if(strpos($headers, 'content-disposition:') !== false && strpos($headers, 'attachment') !== false) return true;
    if(strpos($headers, 'application/octet-stream') !== false || strpos($headers, 'application/x-sqlite3') !== false || strpos($headers, 'application/vnd.sqlite3') !== false) return true;

    // Last safe fallback for old x-ui forks: a non-json/non-html binary response from getDb.
    return true;
}

function v2raystore_downloadPanelDatabaseBackup($server){
    $serverId = intval($server['id'] ?? 0);
    $title = trim((string)($server['title'] ?? ('server_' . $serverId)));
    $type = trim((string)($server['type'] ?? ''));
    if($type === 'marzban') return ['ok'=>false, 'message'=>'بکاپ مستقیم دیتابیس برای مرزبان از طریق API عمومی این ربات پشتیبانی نمی‌شود.'];

    $login = v2raystore_panelLoginSessionForBackup($server);
    if(empty($login['ok'])) return $login;

    $panel = rtrim((string)$login['panel'], '/');
    $session = (string)($login['session'] ?? '');
    $panelBases = $login['panel_bases'] ?? [$panel];
    if(!is_array($panelBases)) $panelBases = [$panel];
    $panelBases[] = $panel;
    $panelBases = array_values(array_unique(array_filter(array_map(function($v){ return rtrim((string)$v, '/'); }, $panelBases))));

    // 3x-ui/Sanaei New endpoint رسمی برای دانلود x-ui.db همین مسیر است؛
    // اگر پنل webBasePath داشته باشد، base URL کشف‌شده از login هم امتحان می‌شود.
    // مسیرهای قدیمی برای forkهای قدیمی X-UI نگه داشته شده‌اند.
    $endpoints = ($type === 'sanaei_new') ? [
        '/panel/api/server/getDb',
        '/panel/api/server/getDb/',
        '/server/getDb',
        '/xui/server/getDb',
    ] : [
        '/panel/api/server/getDb',
        '/server/getDb',
        '/xui/server/getDb',
    ];

    $dir = v2raystore_makeTempDir('v2raystore_panel_db_');
    $lastError = '';

    foreach($panelBases as $baseUrl){
    foreach($endpoints as $endpoint){
        $url = $baseUrl . $endpoint;
        $headers = [];
        if($session !== '') $headers[] = 'Cookie: ' . $session;
        if($type === 'sanaei_new' && function_exists('v2raystore_sanaeiNewCsrfToken')){
            $csrf = v2raystore_sanaeiNewCsrfToken(null, $panel, $session);
            if($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        curl_close($ch);

        if($err){
            $lastError = $endpoint . ': ' . $err;
            continue;
        }
        if($response === false || $http >= 400){
            $lastError = $endpoint . ': HTTP ' . $http;
            continue;
        }

        $rawHeaders = substr((string)$response, 0, $headerSize);
        $body = substr((string)$response, $headerSize);
        if(!v2raystore_isValidPanelBackupBody($body, $rawHeaders)){
            $json = json_decode((string)$body, true);
            if(is_array($json)){
                $lastError = $endpoint . ': ' . ($json['msg'] ?? $json['message'] ?? $json['error'] ?? 'پاسخ JSON بود، نه فایل دیتابیس.');
            }else{
                $lastError = $endpoint . ': پاسخ فایل معتبر نبود.';
            }
            continue;
        }

        $fallbackName = 'x-ui.db';
        $fileName = v2raystore_panelBackupFileNameFromHeaders($rawHeaders, $fallbackName);
        // Telegram must receive the original DB file, not a compressed/gzipped copy.
        // Keep the canonical 3x-ui filename unless the panel explicitly sends another filename.
        if($fileName === '' || strpos($fileName, '.') === false) $fileName = $fallbackName;
        $file = $dir . '/' . $fileName;
        @file_put_contents($file, $body);
        if(is_file($file) && filesize($file) > 64){
            return ['ok'=>true, 'file'=>$file, 'endpoint'=>$endpoint, 'filename'=>$fileName];
        }
        $lastError = $endpoint . ': ذخیره فایل ناموفق بود.';
    }
    }

    return ['ok'=>false, 'message'=>'هیچکدام از مسیرهای دانلود دیتابیس پنل فایل معتبر برنگرداند. آخرین خطا: ' . ($lastError !== '' ? $lastError : 'نامشخص')];
}

function v2raystore_runReportDatabaseBackups($manual = false){
    global $connection, $botState;
    if(!v2raystore_reportIsEnabled(v2raystore_reportEventKey('database_backup'), 'on') && !$manual) return ['ok'=>false, 'message'=>'گزارش بکاپ دیتابیس خاموش است.'];
    if(!$manual && !v2raystore_reportBackupDue()){
        $next = v2raystore_reportBackupNextTimestamp();
        $nextTxt = $next > 0 ? (function_exists('jdate') ? jdate('Y/m/d H:i', $next) : date('Y/m/d H:i', $next)) : 'نامشخص';
        return ['ok'=>true, 'message'=>'هنوز زمان بکاپ بعدی نرسیده است. زمان بعدی: ' . $nextTxt];
    }

    $tasks = [];
    if(v2raystore_backupBotDbEnabled()){
        $tasks[] = ['type'=>'bot', 'id'=>0, 'title'=>'دیتابیس ربات'];
    }

    $sql = "SELECT si.`id`, si.`title`, sc.`panel_url`, sc.`username`, sc.`password`, sc.`type` FROM `server_info` si LEFT JOIN `server_config` sc ON sc.`id` = si.`id` ORDER BY si.`id` ASC";
    $servers = @($connection->query($sql));
    if($servers){
        while($server = $servers->fetch_assoc()){
            $sid = intval($server['id']);
            if(!v2raystore_panelDbBackupEnabled($sid)) continue;
            $tasks[] = ['type'=>'panel', 'id'=>$sid, 'title'=>trim((string)($server['title'] ?? ('سرور ' . $sid))), 'server'=>$server];
        }
    }

    $summary = [];
    if(count($tasks) == 0){
        $summary[] = 'هیچ بکاپی برای ارسال فعال نبود.';
    }

    $delay = v2raystore_reportBackupItemDelaySeconds();
    $idx = 0;
    $total = count($tasks);
    foreach($tasks as $task){
        $idx++;
        if($idx > 1 && $delay > 0) @sleep($delay);

        if(($task['type'] ?? '') === 'bot'){
            $res = v2raystore_createBotDatabaseBackupFile();
            if(!empty($res['ok'])){
                $cap = "🗄 <b>بکاپ جدید دیتابیس ربات</b>\n🔢 مورد: <b>{$idx}/{$total}</b>\n🕒 " . v2raystore_h(function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i'));
                $send = v2raystore_reportSendLocalDocument($res['file'], $cap, 'database_backup');
                $summary[] = (is_object($send) && !empty($send->ok)) ? '✅ دیتابیس ربات ارسال شد.' : '❌ ارسال دیتابیس ربات ناموفق بود.';
                @unlink($res['file']); @rmdir(dirname($res['file']));
            }else{
                $summary[] = '❌ بکاپ دیتابیس ربات ناموفق بود: ' . ($res['message'] ?? 'خطای نامشخص');
            }
            continue;
        }

        if(($task['type'] ?? '') === 'panel'){
            $sid = intval($task['id'] ?? 0);
            $title = trim((string)($task['title'] ?? ('سرور ' . $sid)));
            $res = v2raystore_downloadPanelDatabaseBackup($task['server'] ?? []);
            if(!empty($res['ok'])){
                $cap = "🗄 <b>بکاپ دیتابیس پنل</b>\n🖥 سرور: <b>" . v2raystore_h($title) . "</b>\n🆔 شناسه: <code>$sid</code>\n🔢 مورد: <b>{$idx}/{$total}</b>\n⏳ فاصله بین بکاپ‌ها: <b>" . v2raystore_h($delay) . " ثانیه</b>\n🕒 " . v2raystore_h(function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i'));
                $send = v2raystore_reportSendLocalDocument($res['file'], $cap, 'database_backup');
                $summary[] = (is_object($send) && !empty($send->ok)) ? "✅ بکاپ پنل {$title} ارسال شد." : "❌ ارسال بکاپ پنل {$title} ناموفق بود.";
                @unlink($res['file']); @rmdir(dirname($res['file']));
            }else{
                $summary[] = '❌ بکاپ پنل ' . $title . ' ناموفق بود: ' . ($res['message'] ?? 'خطای نامشخص');
            }
        }
    }

    if(!$manual){
        setSettings('storeReportBackupLastTs', time());
        setSettings('storeReportBackupLastDate', date('Y-m-d'));
    }
    if(count($summary) == 0) $summary[] = 'هیچ بکاپی برای ارسال فعال نبود.';
    $intervalTxt = v2raystore_formatMinutesFa(v2raystore_reportBackupIntervalMinutes());
    $body = "⏱ فاصله اجرای بکاپ: <b>" . v2raystore_h($intervalTxt) . "</b>\n⏳ اجرای ترتیبی: <b>فعال</b>\n\n" . implode("\n", array_map('v2raystore_h', $summary));
    v2raystore_reportEvent('🗄 گزارش بکاپ دیتابیس', $body, null, 'database_backup');
    return ['ok'=>true, 'message'=>implode("\n", $summary)];
}

function v2raystore_getReportPanelBackupMenuText(){
    return "🗄 <b>بکاپ دیتابیس پنل‌ها</b>\n\nاز این بخش مشخص می‌کنی بکاپ دیتابیس کدام پنل‌ها داخل تاپیک دیتابیس ارسال شود.\n\nتوجه: ربات برای X-UI/3x-ui/Sanaei فایل اصلی دیتابیس پنل را بدون فشرده‌سازی دانلود و ارسال می‌کند. برای 3x-ui/Sanaei نام فایل معمولاً x-ui.db است.";
}

function v2raystore_getReportPanelBackupMenuKeys(){
    global $connection, $buttonValues;
    $rows = [];
    $res = @($connection->query("SELECT si.`id`, si.`title`, sc.`type` FROM `server_info` si LEFT JOIN `server_config` sc ON sc.`id` = si.`id` ORDER BY si.`id` ASC"));
    if($res && $res->num_rows > 0){
        while($row = $res->fetch_assoc()){
            $sid = intval($row['id']);
            $state = v2raystore_panelDbBackupEnabled($sid) ? '✅' : '❌';
            $title = trim((string)($row['title'] ?? ('سرور ' . $sid)));
            $type = trim((string)($row['type'] ?? ''));
            $rows[] = [[
                'text' => $state . ' ' . $title . ($type !== '' ? ' | ' . $type : ''),
                'callback_data' => 'togglePanelDbBackup' . $sid,
                'style' => 'primary'
            ]];
        }
    }else{
        $rows[] = [[ 'text'=>'سروری ثبت نشده است', 'callback_data'=>'v2raystore' ]];
    }
    $rows[] = [[ 'text'=>'⬅️ بازگشت', 'callback_data'=>'reportChannelSettingsMenu', 'style'=>'primary' ]];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_getIncomeReportChatId(){
    global $botState, $admin;
    $chat = trim((string)($botState['rewardChannel'] ?? ''));
    return $chat !== '' ? $chat : $admin;
}

function v2raystore_reportEventItems(){
    return [
        'purchase_started' => '🛒 شروع خرید',
        'test_account' => '🧪 دریافت اکانت تست',
        'server_switched' => '🌎 تغییر لوکیشن/سرور',
        'payment_approved' => '✅ پرداخت/خرید تکمیل‌شده',
        'auto_approved' => '🤖 تأیید خودکار سفارش',
        'approval_failed' => '⚠️ خطای تأیید خودکار',
        'admin_order_send_failed' => '⚠️ خطای ارسال رسید/سفارش به ادمین',
        'daily_stats' => '📊 آمار روزانه',
        'database_backup' => '🗄 بکاپ دیتابیس'
    ];
}

function v2raystore_reportStatItems(){
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

function v2raystore_reportDetailItems(){
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

function v2raystore_reportSetting($key, $default = 'on'){
    global $botState;
    $value = $botState[$key] ?? $default;
    return ((string)$value === 'on') ? 'on' : 'off';
}

function v2raystore_reportIsEnabled($key, $default = 'on'){
    return v2raystore_reportSetting($key, $default) === 'on';
}

function v2raystore_reportTime(){
    global $botState;
    $time = trim((string)($botState['storeReportDailyTime'] ?? '21:00'));
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '21:00';
    return $time;
}

function v2raystore_reportToggleSetting($key, $default = 'on'){
    $new = v2raystore_reportIsEnabled($key, $default) ? 'off' : 'on';
    setSettings($key, $new);
    return $new;
}

function v2raystore_reportStatKey($item){
    return 'storeReportStat_' . $item;
}

function v2raystore_reportEventKey($item){
    return 'storeReportEvent_' . $item;
}

function v2raystore_reportDetailKey($item){
    return 'storeReportDetail_' . $item;
}

function v2raystore_reportDetailEnabled($item, $default = 'on'){
    return v2raystore_reportIsEnabled(v2raystore_reportDetailKey($item), $default);
}

function v2raystore_reportTimeLine(){
    if(!v2raystore_reportDetailEnabled('timestamp', 'on')) return '';
    $nowTxt = function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i');
    return "\n🕒 زمان: <b>" . v2raystore_h($nowTxt) . "</b>";
}


function v2raystore_reportPlanServerLinesByPlanId($planId, $volume = '', $days = ''){
    global $connection;
    $planId = intval($planId);
    if($planId <= 0) return [];
    $stmt = $connection->prepare("SELECT sp.`title` AS plan_title, sp.`volume` AS plan_volume, sp.`days` AS plan_days, sc.`title` AS category_title, si.`title` AS server_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sc.`id` = sp.`catid` LEFT JOIN `server_info` si ON si.`id` = sp.`server_id` WHERE sp.`id` = ? LIMIT 1");
    if(!$stmt) return [];
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row) return [];

    $serverTitle = trim((string)($row['server_title'] ?? ''));
    $planTitle = trim(trim((string)($row['category_title'] ?? '')) . ' ' . trim((string)($row['plan_title'] ?? '')));
    if($planTitle === '') $planTitle = trim((string)($row['plan_title'] ?? ''));
    if($volume === '' || floatval($volume) <= 0) $volume = $row['plan_volume'] ?? '';
    if($days === '' || intval($days) <= 0) $days = $row['plan_days'] ?? '';

    $lines = [];
    if($serverTitle !== '') $lines[] = "🖥 سرور: <b>" . v2raystore_h($serverTitle) . "</b>";
    if($planTitle !== '') $lines[] = "📦 پلن: <b>" . v2raystore_h($planTitle) . "</b>";
    if($volume !== '' && floatval($volume) > 0) $lines[] = "🔋 حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
    if($days !== '' && intval($days) > 0) $lines[] = "⏰ مدت: <b>" . v2raystore_h($days) . " روز</b>";
    return $lines;
}

function v2raystore_liveStatsSnapshot($forDaily = false){
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
        if(!v2raystore_reportIsEnabled(v2raystore_reportStatKey($key), 'on')) continue;
        [$label, $value, $suffix] = $item;
        $lines[] = $label . ': <b>' . number_format($value) . $suffix . '</b>';
    }
    if(count($lines) == 0) return '';
    $title = $forDaily ? "📊 <b>آمار روزانه</b>" : "📊 <b>آمار لحظه‌ای</b>";
    return "\n\n" . $title . "\n" . implode("\n", $lines);
}

function v2raystore_reportEvent($title, $body, $keyboard = null, $eventKey = null){
    if($eventKey !== null && !v2raystore_reportIsEnabled(v2raystore_reportEventKey($eventKey), 'on')) return null;
    $res = v2raystore_reportSendMessage($title, $body, $keyboard, $eventKey);
    if(is_object($res) && isset($res->ok) && $res->ok) return $res;

    $desc = is_object($res) && isset($res->description) ? (string)$res->description : '';
    if(function_exists('v2raystore_isUserPrivacyButtonError') && v2raystore_isUserPrivacyButtonError($desc)){
        $removed = false;
        $safeKeyboard = v2raystore_stripPrivateUserButtons($keyboard, $removed);
        if($removed){
            return v2raystore_reportSendMessage($title, $body, $safeKeyboard, $eventKey);
        }
    }
    return $res;
}

function v2raystore_buildDailyChannelStatsText($manual = false){
    $nowTxt = function_exists('jdate') ? jdate('Y/m/d H:i', time()) : date('Y/m/d H:i');
    $title = $manual ? '📊 <b>ارسال دستی آمار کانال</b>' : '📊 <b>گزارش روزانه آمار ربات</b>';
    $stats = v2raystore_liveStatsSnapshot(true);
    if(trim($stats) === '') $stats = "\n\nهیچ آیتم آماری برای ارسال فعال نیست.";
    return $title . "\n\n🕒 زمان گزارش: <b>" . v2raystore_h($nowTxt) . "</b>" . $stats;
}

function v2raystore_sendDailyChannelStats($manual = false){
    if(!v2raystore_reportIsEnabled(v2raystore_reportEventKey('daily_stats'), 'on') && !$manual) return false;
    $chat = v2raystore_getIncomeReportChatId();
    if($chat === null || $chat === '') return false;
    $text = v2raystore_buildDailyChannelStatsText($manual);
    $threadId = v2raystore_reportEnsureTopic('daily_stats');
    $payload = [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML',
        '_timeout' => 8,
    ];
    if($threadId > 0) $payload['message_thread_id'] = $threadId;
    bot('sendMessage', $payload);
    return true;
}

function v2raystore_processDailyChannelStats($force = false){
    if(!$force && !v2raystore_reportIsEnabled('storeReportDailyState', 'off')) return false;
    $today = date('Y-m-d');
    $time = v2raystore_reportTime();
    global $botState;
    $last = (string)($botState['storeReportLastDailyDate'] ?? '');
    if(!$force){
        if($last === $today) return false;
        if(date('H:i') < $time) return false;
    }
    $sent = v2raystore_sendDailyChannelStats($force);
    if($sent && !$force) setSettings('storeReportLastDailyDate', $today);
    return $sent;
}

function v2raystore_getReportSettingsMenuText(){
    $dailyState = v2raystore_reportIsEnabled('storeReportDailyState', 'off') ? 'روشن ✅' : 'خاموش ❌';
    $forumState = v2raystore_reportForumEnabled() ? 'فعال ✅' : 'غیرفعال ❌';
    $botDbState = v2raystore_backupBotDbEnabled() ? 'روشن ✅' : 'خاموش ❌';
    $time = v2raystore_reportTime();
    global $botState;
    $backupInterval = v2raystore_formatMinutesFa(v2raystore_reportBackupIntervalMinutes());
    $backupDelay = v2raystore_reportBackupItemDelaySeconds();
    $last = trim((string)($botState['storeReportLastDailyDate'] ?? ''));
    if($last === '') $last = 'ارسال نشده';
    $backupLastTs = v2raystore_reportBackupLastTimestamp();
    $backupLast = $backupLastTs > 0 ? (function_exists('jdate') ? jdate('Y/m/d H:i', $backupLastTs) : date('Y/m/d H:i', $backupLastTs)) : 'ارسال نشده';
    $backupNextTs = v2raystore_reportBackupNextTimestamp();
    $backupNext = $backupNextTs > 0 ? (function_exists('jdate') ? jdate('Y/m/d H:i', $backupNextTs) : date('Y/m/d H:i', $backupNextTs)) : 'در اولین اجرای کران';
    $chat = trim((string)($botState['rewardChannel'] ?? ''));
    if($chat === '') $chat = 'تنظیم نشده';
    return "📊 <b>تنظیمات گروه/کانال گزارش</b>\n\n" .
           "📌 مقصد گزارش: <code>" . v2raystore_h($chat) . "</code>\n" .
           "🧵 دسته‌بندی با تاپیک گروه: <b>$forumState</b>\n" .
           "🔔 آمار روزانه: <b>$dailyState</b>\n" .
           "🕘 ساعت ارسال آمار: <b>$time</b>\n" .
           "📌 آخرین آمار روزانه: <b>" . v2raystore_h($last) . "</b>\n\n" .
           "🗄 بکاپ جدید دیتابیس ربات به گروه: <b>$botDbState</b>\n" .
           "⏱ فاصله بکاپ دیتابیس: <b>" . v2raystore_h($backupInterval) . "</b>\n" .
           "⏳ فاصله بین هر بکاپ: <b>" . v2raystore_h($backupDelay) . " ثانیه</b>\n" .
           "📌 آخرین بکاپ دیتابیس: <b>" . v2raystore_h($backupLast) . "</b>\n" .
           "⏭ بکاپ بعدی: <b>" . v2raystore_h($backupNext) . "</b>\n\n" .
           "بکاپ‌ها به‌صورت صفی و یکی‌یکی ارسال می‌شوند تا دیتابیس ربات و پنل‌ها همزمان dump نشوند و فشار روی سرور کم بماند. اگر حالت تاپیک فعال باشد، گزارش‌های مالی نهایی داخل تاپیک خرید و پرداخت می‌روند و شروع خرید/اکانت تست داخل تاپیک جداگانه ارسال می‌شود.";
}

function v2raystore_getReportSettingsMenuKeys(){
    global $buttonValues;
    $rows = [];
    $rows[] = [
        ['text'=>'📌 تنظیم گروه/کانال گزارش', 'callback_data'=>'setReportGroupChat', 'style'=>'primary'],
        ['text'=>(v2raystore_reportForumEnabled() ? 'خاموش کردن تاپیک‌ها ❌' : 'فعال‌سازی تاپیک‌ها ✅'), 'callback_data'=>'toggleReportForumTopics', 'style'=> v2raystore_reportForumEnabled() ? 'danger' : 'success']
    ];
    $rows[] = [
        ['text'=>'🧵 ساخت/ترمیم تاپیک‌ها', 'callback_data'=>'rebuildReportForumTopics', 'style'=>'primary'],
        ['text'=>'🗑 حذف همه تاپیک‌ها', 'callback_data'=>'deleteAllReportForumTopics', 'style'=>'danger']
    ];
    $rows[] = [
        ['text'=>(v2raystore_reportIsEnabled('storeReportDailyState', 'off') ? 'خاموش کردن آمار روزانه ❌' : 'روشن کردن آمار روزانه ✅'), 'callback_data'=>'toggleDailyChannelStats', 'style'=>'success'],
        ['text'=>'🕘 ساعت آمار', 'callback_data'=>'setDailyChannelStatsTime', 'style'=>'primary']
    ];
    $rows[] = [
        ['text'=>'📤 ارسال آمار الان', 'callback_data'=>'sendDailyChannelStatsNow', 'style'=>'success']
    ];

    $rows[] = [[ 'text'=>'🗄 تنظیمات بکاپ دیتابیس', 'callback_data'=>'v2raystore', 'style'=>'primary' ]];
    $rows[] = [
        ['text'=>(v2raystore_backupBotDbEnabled() ? '✅ بکاپ دیتابیس ربات' : '❌ بکاپ دیتابیس ربات'), 'callback_data'=>'toggleReportBackupBotDb', 'style'=>'primary'],
        ['text'=>'⏱ فاصله بکاپ', 'callback_data'=>'setReportBackupInterval', 'style'=>'primary']
    ];
    $rows[] = [
        ['text'=>'⏳ فاصله بین ارسال‌ها', 'callback_data'=>'setReportBackupItemDelay', 'style'=>'primary'],
        ['text'=>'🔄 ریست زمان‌بندی بکاپ', 'callback_data'=>'resetReportBackupSchedule', 'style'=>'primary']
    ];
    $rows[] = [
        ['text'=>'🖥 بکاپ دیتابیس پنل‌ها', 'callback_data'=>'reportPanelDbBackupMenu', 'style'=>'primary'],
        ['text'=>'📦 اجرای بکاپ الان', 'callback_data'=>'runReportDbBackupsNow', 'style'=>'success']
    ];

    $rows[] = [[ 'text'=>'🔔 نوع اعلان‌هایی که به گزارش بروند', 'callback_data'=>'v2raystore', 'style'=>'primary' ]];
    foreach(v2raystore_reportEventItems() as $key => $title){
        $state = v2raystore_reportIsEnabled(v2raystore_reportEventKey($key), 'on') ? '✅' : '❌';
        $rows[] = [[ 'text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportEvent_' . $key, 'style'=>'primary' ]];
    }
    $rows[] = [[ 'text'=>'🧩 جزئیات داخل پیام‌های اعلان', 'callback_data'=>'v2raystore', 'style'=>'primary' ]];
    foreach(v2raystore_reportDetailItems() as $key => $title){
        $state = v2raystore_reportDetailEnabled($key, 'on') ? '✅' : '❌';
        $rows[] = [[ 'text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportDetail_' . $key, 'style'=>'primary' ]];
    }
    $rows[] = [[ 'text'=>'📊 آیتم‌های داخل آمار روزانه/دستی', 'callback_data'=>'v2raystore', 'style'=>'primary' ]];
    $pair = [];
    foreach(v2raystore_reportStatItems() as $key => $title){
        $state = v2raystore_reportIsEnabled(v2raystore_reportStatKey($key), 'on') ? '✅' : '❌';
        $pair[] = ['text'=>$state . ' ' . $title, 'callback_data'=>'toggleReportStat_' . $key, 'style'=>'primary'];
        if(count($pair) == 2){ $rows[] = $pair; $pair = []; }
    }
    if(count($pair) > 0) $rows[] = $pair;
    $rows[] = [[ 'text'=>$buttonValues['back_button'] ?? '⬅️ بازگشت', 'callback_data'=>'managePanel', 'style'=>'primary' ]];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_reportPrivateKeyboard($userId, $extraRows = []){
    $rows = [];
    foreach($extraRows as $row){
        if(!empty($row)) $rows[] = $row;
    }
    if(v2raystore_reportDetailEnabled('private_button', 'on')) $rows[] = [v2raystore_userPrivateButton($userId)];
    if(count($rows) == 0) return null;
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_notifyPurchaseStarted($hashId, $source = 'انتخاب پلن'){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return;
    $stmt = $connection->prepare("SELECT p.*, u.`name`, u.`username`, sp.`title` AS plan_title, sp.`volume` AS plan_volume, sp.`days` AS plan_days, sc.`title` AS category_title, si.`title` AS server_title FROM `pays` p LEFT JOIN `users` u ON u.`userid` = p.`user_id` LEFT JOIN `server_plans` sp ON sp.`id` = p.`plan_id` LEFT JOIN `server_categories` sc ON sc.`id` = sp.`catid` LEFT JOIN `server_info` si ON si.`id` = sp.`server_id` WHERE p.`hash_id` = ? LIMIT 1");
    if(!$stmt) return;
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return;
    $uid = intval($pay['user_id']);
    $serverTitle = trim((string)($pay['server_title'] ?? ''));
    $planTitle = trim(trim((string)($pay['category_title'] ?? '')) . ' ' . trim((string)($pay['plan_title'] ?? '')));
    if($planTitle === '') $planTitle = trim((string)($pay['plan_title'] ?? $pay['type'] ?? ''));
    $volume = $pay['volume'] ?? ($pay['plan_volume'] ?? '');
    $days = $pay['day'] ?? ($pay['plan_days'] ?? '');

    $lines = ["🟡 <b>شروع فرایند خرید</b>"];
    if(v2raystore_reportDetailEnabled('user_info', 'on')) $lines[] = v2raystore_formatUserLine($uid, $pay['name'] ?? '', $pay['username'] ?? '');

    // این پیام، گزارش اولیه خرید داخل کانال درآمد است. سرور و پلن باید همیشه نمایش داده شوند
    // حتی اگر گزینه جزئیات پلن در تنظیمات گزارش خاموش باشد؛ چون ادمین برای پیگیری سفارش به آن نیاز دارد.
    if($serverTitle !== '') $lines[] = "🖥 سرور: <b>" . v2raystore_h($serverTitle) . "</b>";
    else $lines[] = "🖥 سرور: <b>نامشخص</b>";

    if($planTitle !== '') $lines[] = "📦 پلن: <b>" . v2raystore_h($planTitle) . "</b>";
    else $lines[] = "📦 پلن: <b>نامشخص</b>";

    if(v2raystore_reportDetailEnabled('plan_info', 'on')){
        if($volume !== '' && intval($volume) > 0) $lines[] = "🔋 حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
        if($days !== '' && intval($days) > 0) $lines[] = "⏰ مدت: <b>" . v2raystore_h($days) . " روز</b>";
        $lines[] = "💳 روش/مرحله: <b>" . v2raystore_h($source) . "</b>";
    }
    if(v2raystore_reportDetailEnabled('amount', 'on')) $lines[] = "💰 مبلغ: <b>" . number_format(intval($pay['price'])) . " تومان</b>";
    // کد پرداخت در گزارش خرید جدید نمایش داده نمی‌شود؛ دکمه‌های ادمین همان هش داخلی را استفاده می‌کنند.
    $body = implode("
", $lines) . v2raystore_reportTimeLine();
    v2raystore_reportEvent('🛒 گزارش خرید جدید', $body, v2raystore_reportPrivateKeyboard($uid), 'purchase_started');
}

function v2raystore_notifyTestAccountTaken($orderId, $userId, $planTitle = '', $remark = '', $volume = '', $days = ''){
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
    if(v2raystore_reportDetailEnabled('user_info', 'on')) $lines[] = v2raystore_formatUserLine($userId, $user['name'] ?? '', $user['username'] ?? '');
    if(v2raystore_reportDetailEnabled('order_ids', 'on')) $lines[] = "🧾 شماره سفارش: <code>" . intval($orderId) . "</code>";
    if(v2raystore_reportDetailEnabled('plan_info', 'on')){
        $lines[] = "📦 پلن: <b>" . v2raystore_h($planTitle) . "</b>";
        $lines[] = "🔮 ریمارک: <code>" . v2raystore_h($remark) . "</code>";
        $lines[] = "🔋 حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
        $lines[] = "⏰ مدت: <b>" . v2raystore_h($days) . " روز</b>";
    }
    $body = implode("\n", $lines) . v2raystore_reportTimeLine();
    $keyboard = v2raystore_reportPrivateKeyboard($userId);
    $res = v2raystore_reportEvent('🧪 گزارش اکانت تست', $body, $keyboard, 'test_account');
    if(!(is_object($res) && !empty($res->ok))){
        // گزارش اکانت تست نباید به خاطر تاپیک خراب/ParseMode/کیبورد از بین برود.
        if(function_exists('v2raystore_reportSendMessage')){
            $res = v2raystore_reportSendMessage('🧪 گزارش اکانت تست', $body, $keyboard, 'test_account');
            if(!(is_object($res) && !empty($res->ok)) && function_exists('v2raystore_stripPrivateUserButtons')){
                $removed = false;
                $safeKeyboard = v2raystore_stripPrivateUserButtons($keyboard, $removed);
                v2raystore_reportSendMessage('🧪 گزارش اکانت تست', $body, $safeKeyboard, 'test_account');
            }
        }
    }
}


function v2raystore_formatReportSecondsFa($seconds){
    $seconds = max(0, intval($seconds));
    if($seconds < 60) return 'در عرض ' . $seconds . ' ثانیه';
    $minutes = intdiv($seconds, 60);
    $remain = $seconds % 60;
    if($minutes < 60){
        return $remain > 0 ? ('در عرض ' . $minutes . ' دقیقه و ' . $remain . ' ثانیه') : ('در عرض ' . $minutes . ' دقیقه');
    }
    $hours = intdiv($minutes, 60);
    $minutes = $minutes % 60;
    return $minutes > 0 ? ('در عرض ' . $hours . ' ساعت و ' . $minutes . ' دقیقه') : ('در عرض ' . $hours . ' ساعت');
}

function v2raystore_formatGbForReport($value){
    $value = floatval($value);
    if($value <= 0) return 'نامحدود';
    $txt = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $txt . ' گیگ';
}


function v2raystore_formatRemainingDaysNumber($expire){
    $expire = intval($expire);
    if($expire <= 0) return 'نامحدود';
    $seconds = $expire - time();
    if($seconds <= 0) return '0';
    return (string)max(1, (int)ceil($seconds / 86400));
}

function v2raystore_formatRemainingDaysText($expire){
    $days = v2raystore_formatRemainingDaysNumber($expire);
    return ($days === 'نامحدود') ? 'نامحدود' : ($days . ' روز');
}

function v2raystore_formatGbNumberForUser($gb){
    if(!is_numeric($gb)) return 'نامحدود';
    $gb = max(0, floatval($gb));
    return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
}

function v2raystore_getOrderRemainingSummary($order){
    if(!is_array($order)) return null;
    $serverId = intval($order['server_id'] ?? 0);
    $inboundId = intval($order['inbound_id'] ?? 0);
    $uuid = trim((string)($order['uuid'] ?? ''));
    $remark = trim((string)($order['remark'] ?? ''));
    if($serverId <= 0) return null;

    $stmt = $GLOBALS['connection']->prepare("SELECT `type` FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverConfig) return null;
    $serverType = (string)($serverConfig['type'] ?? '');

    $remainingBytes = null;
    $expireSeconds = intval($order['expire_date'] ?? 0);

    if($serverType === 'marzban'){
        $info = getMarzbanUser($serverId, $remark);
        if($info && isset($info->username)){
            $total = intval($info->data_limit ?? 0);
            $used = intval($info->used_traffic ?? 0);
            $remainingBytes = $total > 0 ? max(0, $total - $used) : null;
            $expireSeconds = intval($info->expire ?? $expireSeconds);
        }
    }else{
        $json = getJson($serverId);
        $rows = function_exists('v2raystore_panelListFromGetJson') ? v2raystore_panelListFromGetJson($json) : (($json && isset($json->obj) && is_array($json->obj)) ? $json->obj : []);
        foreach($rows as $row){
            $rowId = intval(v2raystore_arrayValue($row, 'id', 0));
            if($inboundId > 0 && $rowId !== $inboundId) continue;
            $settings = json_decode((string)v2raystore_arrayValue($row, 'settings', ''));
            $clients = (is_object($settings) && isset($settings->clients) && is_array($settings->clients)) ? $settings->clients : [];
            $stats = v2raystore_arrayValue($row, 'clientStats', []);
            foreach($clients as $client){
                $cid = function_exists('v2raystore_panelClientIdentity') ? v2raystore_panelClientIdentity($client) : (string)(v2raystore_arrayValue($client, 'id', v2raystore_arrayValue($client, 'password', '')));
                $email = function_exists('v2raystore_panelClientEmail') ? v2raystore_panelClientEmail($client) : (string)v2raystore_arrayValue($client, 'email', '');
                if($cid !== $uuid && ($remark === '' || $email !== $remark)) continue;

                if($inboundId > 0){
                    $stat = function_exists('v2raystore_panelFindClientStat') ? v2raystore_panelFindClientStat($stats, $email) : null;
                    if($stat){
                        $total = intval(v2raystore_arrayValue($stat, 'total', 0));
                        $up = intval(v2raystore_arrayValue($stat, 'up', 0));
                        $down = intval(v2raystore_arrayValue($stat, 'down', 0));
                        $remainingBytes = $total > 0 ? max(0, $total - $up - $down) : null;
                        $exp = v2raystore_arrayValue($stat, 'expiryTime', 0);
                        if((empty($exp) || intval($exp) == 0)) $exp = v2raystore_arrayValue($client, 'expiryTime', 0);
                        if(function_exists('v2raystore_panelExpiryToSeconds')) $expireSeconds = v2raystore_panelExpiryToSeconds($exp) ?: $expireSeconds;
                    }
                }else{
                    $total = intval(v2raystore_arrayValue($row, 'total', 0));
                    $up = intval(v2raystore_arrayValue($row, 'up', 0));
                    $down = intval(v2raystore_arrayValue($row, 'down', 0));
                    $remainingBytes = $total > 0 ? max(0, $total - $up - $down) : null;
                    $exp = v2raystore_arrayValue($row, 'expiryTime', 0);
                    if(function_exists('v2raystore_panelExpiryToSeconds')) $expireSeconds = v2raystore_panelExpiryToSeconds($exp) ?: $expireSeconds;
                }
                break 2;
            }
        }
    }

    $remainingGb = ($remainingBytes === null) ? null : round($remainingBytes / 1073741824, 2);
    return [
        'remaining_gb' => $remainingGb,
        'remaining_gb_text' => ($remainingGb === null ? 'نامحدود' : v2raystore_formatGbNumberForUser($remainingGb)),
        'remaining_days' => v2raystore_formatRemainingDaysNumber($expireSeconds),
        'remaining_days_text' => v2raystore_formatRemainingDaysText($expireSeconds),
        'expire_date' => $expireSeconds,
    ];
}

function v2raystore_extractFirstServiceLink($linkRaw){
    $linkRaw = trim((string)$linkRaw);
    if($linkRaw === '') return '';
    $decoded = json_decode($linkRaw, true);
    if(is_array($decoded)){
        $flat = [];
        $walk = function($item) use (&$walk, &$flat){
            if(is_array($item)){
                foreach($item as $v) $walk($v);
            }else{
                $v = trim((string)$item);
                if($v !== '') $flat[] = $v;
            }
        };
        $walk($decoded);
        if(count($flat) > 0) return $flat[0];
    }
    return $linkRaw;
}

function v2raystore_paymentReportActionText($payType, $result){
    $type = (string)($result['type'] ?? $payType);
    if($type === 'RENEW_ACCOUNT' || $payType === 'RENEW_ACCOUNT' || $payType === 'RENEW_SCONFIG') return '✅ فاکتور با موفقیت پرداخت گردید و سرویس شما تمدید گردید';
    if($type === 'INCREASE_VOLUME' || preg_match('/^INCREASE_VOLUME_/', (string)$payType)) return '✅ فاکتور با موفقیت پرداخت گردید و حجم سرویس شما افزایش یافت';
    if($type === 'INCREASE_DAY' || preg_match('/^INCREASE_DAY_/', (string)$payType)) return '✅ فاکتور با موفقیت پرداخت گردید و زمان سرویس شما افزایش یافت';
    if($type === 'INCREASE_WALLET' || $payType === 'INCREASE_WALLET') return '✅ فاکتور با موفقیت پرداخت گردید و موجودی کیف پول کاربر شارژ شد';
    return '✅ فاکتور با موفقیت پرداخت گردید و سرویس شما فعال گردید';
}

function v2raystore_getOrderRowsForFullPaymentReport($orderIds){
    global $connection;
    if(!is_array($orderIds) || count($orderIds) == 0) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), function($v){ return $v > 0; })));
    if(count($ids) == 0) return [];
    $in = implode(',', $ids);
    $rows = [];
    $sql = "SELECT o.*, sp.`title` AS plan_title, sp.`volume` AS plan_volume, sp.`days` AS plan_days, sp.`limitip` AS plan_limitip, sc.`title` AS category_title, si.`title` AS server_title
            FROM `orders_list` o
            LEFT JOIN `server_plans` sp ON sp.`id` = o.`fileid`
            LEFT JOIN `server_categories` sc ON sc.`id` = sp.`catid`
            LEFT JOIN `server_info` si ON si.`id` = o.`server_id`
            WHERE o.`id` IN ($in)
            ORDER BY FIELD(o.`id`, $in)";
    $res = @($connection->query($sql));
    if($res){
        while($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function v2raystore_notifyPaymentCompletedFullReport($hashId, $result = [], $auto = false){
    global $connection;
    if(!is_array($result) || empty($result['ok']) || !empty($result['already'])) return false;
    $hashId = trim((string)$hashId);
    if($hashId === '') $hashId = trim((string)($result['pay_hash'] ?? ''));
    if($hashId === '') return false;

    $stmt = @$connection->prepare("SELECT p.*, u.`name`, u.`username`, u.`wallet` FROM `pays` p LEFT JOIN `users` u ON u.`userid` = p.`user_id` WHERE p.`hash_id` = ? LIMIT 1");
    if(!$stmt) return false;
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return false;

    $uid = intval($result['user_id'] ?? $pay['user_id'] ?? 0);
    $price = intval($result['price'] ?? $pay['price'] ?? 0);
    $payType = (string)($pay['type'] ?? ($result['type'] ?? ''));
    $stateBefore = (string)($result['pay_state_before'] ?? '');
    $walletNow = intval($pay['wallet'] ?? 0);
    // در پرداخت‌های کیف پولی، گزارش قبل از کم‌شدن موجودی از دیتابیس ساخته می‌شود؛ برای نمایش درست، مبلغ را محاسبه‌ای کم می‌کنیم.
    $walletAfter = $walletNow;
    if($price > 0 && in_array($stateBefore, ['pending','0','paid_with_wallet'], true) && $payType !== 'INCREASE_WALLET'){
        $walletAfter = max(0, $walletNow - $price);
    }
    if($payType === 'INCREASE_WALLET' && $price > 0 && in_array($stateBefore, ['sent','pending','0','auto_processing','processing'], true)){
        // تابع شارژ کیف پول قبل از ساخت گزارش دیتابیس کاربر را افزایش داده است، پس مقدار فعلی همان موجودی جدید است.
        $walletAfter = $walletNow;
    }

    $requestTs = intval($pay['sent_date'] ?? 0);
    if($requestTs <= 0) $requestTs = intval($pay['request_date'] ?? 0);
    $doneTs = intval($pay['auto_approved_date'] ?? 0);
    if($doneTs <= 0) $doneTs = time();
    $duration = ($requestTs > 0) ? v2raystore_formatReportSecondsFa($doneTs - $requestTs) : 'ثبت شد';

    $orderIds = $result['order_ids'] ?? [];
    if(!is_array($orderIds)) $orderIds = [];
    if(count($orderIds) == 0){
        $decodedOrders = json_decode((string)($pay['auto_approved_orders'] ?? '[]'), true);
        if(is_array($decodedOrders)) $orderIds = $decodedOrders;
    }
    $orders = v2raystore_getOrderRowsForFullPaymentReport($orderIds);

    $lines = [];
    $lines[] = '• 🛍 موجودی جدید کاربر : ' . number_format($walletAfter);
    $lines[] = '';
    $lines[] = v2raystore_paymentReportActionText($payType, $result);

    if(count($orders) > 0){
        $idx = 0;
        $perOrderPrice = count($orders) > 1 ? intval(floor($price / count($orders))) : $price;
        foreach($orders as $order){
            $idx++;
            $remark = trim((string)($order['remark'] ?? ($result['renew_remark'] ?? '')));
            $serverTitle = trim((string)($order['server_title'] ?? ''));
            $limitIp = intval($order['plan_limitip'] ?? 0);
            if($limitIp <= 0) $limitIp = 1;
            $days = intval($result['renew_days'] ?? 0);
            if($days <= 0) $days = intval($order['plan_days'] ?? 0);
            $volume = floatval($result['renew_volume'] ?? 0);
            if($volume <= 0) $volume = floatval($order['plan_volume'] ?? 0);
            $servicePrice = $perOrderPrice > 0 ? $perOrderPrice : intval($order['amount'] ?? $price);
            $link = v2raystore_extractFirstServiceLink($order['link'] ?? '');

            $lines[] = '';
            $lines[] = '🔑 اطلاعات سرویس شما' . (count($orders) > 1 ? ' #' . $idx : '') . ' :';
            $lines[] = '';
            $lines[] = '• 📊 تعداد کاربر مجاز : ' . $limitIp;
            $lines[] = '• 💰 هزینه سرویس : ' . number_format($servicePrice) . ' تومان';
            if($remark !== '') $lines[] = '• 🔑 کد سرویس : ' . v2raystore_h($remark);
            if($days > 0) $lines[] = '• 🗓 دوره پرداخت : ' . $days . ' روزه';
            if(($result['type'] ?? '') === 'INCREASE_VOLUME' && !empty($result['increase_volume'])) $lines[] = '• 🚘 ترافیک افزوده‌شده : ' . v2raystore_h($result['increase_volume']) . ' گیگ';
            elseif(($result['type'] ?? '') === 'INCREASE_DAY' && !empty($result['increase_day'])) $lines[] = '• 🗓 زمان افزوده‌شده : ' . v2raystore_h($result['increase_day']) . ' روز';
            else $lines[] = '• 🚘 ترافیک : ' . v2raystore_formatGbForReport($volume);
            $lines[] = '• ⌛️ زمان ساخت : ' . v2raystore_h($duration);
            if($serverTitle !== '') $lines[] = '• 💎 لوکیشن : ' . v2raystore_h($serverTitle);
            if($link !== ''){
                $lines[] = '';
                $lines[] = '🔗لینک سرویس شما : ';
                $lines[] = '<code>' . v2raystore_h($link) . '</code>';
            }
        }
    }else{
        $lines[] = '';
        $lines[] = '🔑 اطلاعات پرداخت شما :';
        if($payType === 'INCREASE_WALLET' || ($result['type'] ?? '') === 'INCREASE_WALLET') $lines[] = '• 💰 مبلغ شارژ کیف پول : ' . number_format($price) . ' تومان';
        elseif(!empty($result['renew_remark'])) $lines[] = '• 🔑 کد سرویس : ' . v2raystore_h($result['renew_remark']);
        if(!empty($result['increase_volume'])) $lines[] = '• 🚘 ترافیک افزوده‌شده : ' . v2raystore_h($result['increase_volume']) . ' گیگ';
        if(!empty($result['increase_day'])) $lines[] = '• 🗓 زمان افزوده‌شده : ' . v2raystore_h($result['increase_day']) . ' روز';
        $lines[] = '• 💰 مبلغ پرداخت : ' . number_format($price) . ' تومان';
        $lines[] = '• ⌛️ زمان انجام : ' . v2raystore_h($duration);
    }

    $username = trim((string)($pay['username'] ?? ''));
    $username = $username !== '' ? '@' . ltrim($username, '@') : 'ندارد';
    $lines[] = '';
    $lines[] = 'id : ' . $uid;
    $lines[] = 'username : ' . v2raystore_h($username);

    $body = implode("\n", $lines);
    $reportType = (string)($result['type'] ?? $payType);
    $isRenewReport = ($reportType === 'RENEW_ACCOUNT' || $payType === 'RENEW_ACCOUNT' || $payType === 'RENEW_SCONFIG');
    $isIncreaseOnly = ($reportType === 'INCREASE_VOLUME' || $reportType === 'INCREASE_DAY' || $reportType === 'INCREASE_WALLET' || preg_match('/^INCREASE_(VOLUME|DAY)_/', (string)$payType));
    if($isRenewReport){
        $keyboard = function_exists('v2raystore_renewCompletedReportKeyboard') ? v2raystore_renewCompletedReportKeyboard($hashId, $uid) : v2raystore_reportPrivateKeyboard($uid);
    }elseif($auto && !$isIncreaseOnly){
        $keyboard = v2raystore_autoOrderActionKeyboard($hashId, $uid);
    }else{
        $keyboard = v2raystore_reportPrivateKeyboard($uid);
    }
    $res = v2raystore_reportEvent('#سفارش_جدید', $body, $keyboard, 'payment_approved');
    return is_object($res) && !empty($res->ok);
}

function v2raystore_notifyServerSwitch($result, $actorId = 0, $isAdminSwitch = false){
    global $connection;
    if(!is_array($result) || empty($result['ok'])) return null;

    $ownerId = intval($result['owner_id'] ?? 0);
    if($ownerId <= 0) return null;

    $user = null;
    $stmt = @$connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $actorId = intval($actorId);
    $actorLine = '';
    if($actorId > 0 && ($isAdminSwitch || $actorId != $ownerId)){
        $actor = null;
        $stmt = @$connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i', $actorId);
            $stmt->execute();
            $actor = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $actorName = trim((string)($actor['name'] ?? ''));
        if($actorName === '') $actorName = ($actorId == intval($GLOBALS['admin'] ?? 0)) ? 'ادمین اصلی' : ('ادمین ' . $actorId);
        $actorLine = "👮 انجام‌دهنده: <b>" . v2raystore_h($actorName) . "</b> <code>" . $actorId . "</code>";
    }

    $orderId = intval($result['order_id'] ?? 0);
    $oldServerId = intval($result['old_server_id'] ?? 0);
    $targetServerId = intval($result['target_server_id'] ?? 0);
    $fromTitle = function_exists('v2raystore_switchGetServerTitle') ? v2raystore_switchGetServerTitle($oldServerId) : (string)$oldServerId;
    $toTitle = trim((string)($result['target_title'] ?? ''));
    if($toTitle === '') $toTitle = function_exists('v2raystore_switchGetServerTitle') ? v2raystore_switchGetServerTitle($targetServerId) : (string)$targetServerId;

    $changeType = (string)($result['change_type'] ?? 'deduct');
    $changeGb = floatval($result['change_gb'] ?? ($result['deduct_gb'] ?? 0));
    $formatGb = function($gb){
        return function_exists('v2raystore_switchFormatGb') ? v2raystore_switchFormatGb($gb) : rtrim(rtrim(number_format((float)$gb, 2, '.', ''), '0'), '.');
    };
    $changeLine = ($changeType === 'add')
        ? "🔺 حجم اضافه‌شده: <b>" . $formatGb($changeGb) . " GB</b>"
        : "🔻 حجم کسرشده: <b>" . $formatGb($changeGb) . " GB</b>";

    $lines = ["✅ <b>تغییر لوکیشن/سرور انجام شد</b>"];
    if(v2raystore_reportDetailEnabled('user_info', 'on')) $lines[] = v2raystore_formatUserLine($ownerId, $user['name'] ?? '', $user['username'] ?? '');
    if(v2raystore_reportDetailEnabled('order_ids', 'on') && $orderId > 0) $lines[] = "🧾 شماره سفارش: <code>" . $orderId . "</code>";

    $oldRemark = trim((string)($result['old_remark'] ?? ''));
    $newRemark = trim((string)($result['new_remark'] ?? ''));
    if($oldRemark !== '') $lines[] = "🔮 کانفیگ قبلی: <code>" . v2raystore_h($oldRemark) . "</code>";
    if($newRemark !== '' && $newRemark !== $oldRemark) $lines[] = "🆕 کانفیگ جدید: <code>" . v2raystore_h($newRemark) . "</code>";

    $lines[] = "📍 از سرور: <b>" . v2raystore_h($fromTitle) . "</b>";
    $lines[] = "📍 به سرور: <b>" . v2raystore_h($toTitle) . "</b>";
    $lines[] = $changeLine;
    $lines[] = "📦 حجم قبل تغییر: <b>" . $formatGb($result['remaining_gb_before'] ?? 0) . " GB</b>";
    $lines[] = "📦 حجم بعد تغییر: <b>" . $formatGb($result['remaining_gb_after'] ?? 0) . " GB</b>";
    if($actorLine !== '') $lines[] = $actorLine;

    $body = implode("\n", $lines) . v2raystore_reportTimeLine();
    return v2raystore_reportEvent('🌎 گزارش تغییر لوکیشن', $body, v2raystore_reportPrivateKeyboard($ownerId), 'server_switched');
}

function v2raystore_getAutoApproveBlockedUsers(){
    global $botState;
    $raw = $botState['autoApproveBlockedUsers'] ?? '';
    $items = [];
    if(is_array($raw)){
        $items = $raw;
    }else{
        $raw = trim((string)$raw);
        if($raw !== ''){
            $decoded = json_decode($raw, true);
            if(is_array($decoded)) $items = $decoded;
            else $items = preg_split('/[\s,،;|]+/u', $raw);
        }
    }
    $ids = [];
    foreach($items as $item){
        if(is_array($item)) continue;
        $id = intval(preg_replace('/\D+/', '', (string)$item));
        if($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function v2raystore_saveAutoApproveBlockedUsers($ids){
    $clean = [];
    if(!is_array($ids)) $ids = [];
    foreach($ids as $id){
        $id = intval($id);
        if($id > 0) $clean[] = $id;
    }
    $clean = array_values(array_unique($clean));
    sort($clean, SORT_NUMERIC);
    setSettings('autoApproveBlockedUsers', json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

function v2raystore_isAutoApproveBlockedUser($userId){
    $userId = intval($userId);
    if($userId <= 0) return false;
    return in_array($userId, v2raystore_getAutoApproveBlockedUsers(), true);
}

function v2raystore_addAutoApproveBlockedUser($userId){
    $userId = intval($userId);
    if($userId <= 0) return false;
    $ids = v2raystore_getAutoApproveBlockedUsers();
    $ids[] = $userId;
    v2raystore_saveAutoApproveBlockedUsers($ids);
    return true;
}

function v2raystore_removeAutoApproveBlockedUser($userId){
    $userId = intval($userId);
    $ids = array_values(array_filter(v2raystore_getAutoApproveBlockedUsers(), function($id) use ($userId){ return intval($id) !== $userId; }));
    v2raystore_saveAutoApproveBlockedUsers($ids);
    return true;
}

function v2raystore_autoApproveTypeItems(){
    return [
        'buy' => [
            'title' => 'خرید جدید',
            'icon' => '🛒',
            'sql' => "`type` = 'BUY_SUB'",
            'match' => function($type){ return $type === 'BUY_SUB'; }
        ],
        'renew' => [
            'title' => 'تمدید سرویس',
            'icon' => '🔄',
            'sql' => "`type` IN ('RENEW_ACCOUNT','RENEW_SCONFIG')",
            'match' => function($type){ return $type === 'RENEW_ACCOUNT' || $type === 'RENEW_SCONFIG'; }
        ],
        'increase_wallet' => [
            'title' => 'شارژ کیف پول',
            'icon' => '💰',
            'sql' => "`type` = 'INCREASE_WALLET'",
            'match' => function($type){ return $type === 'INCREASE_WALLET'; }
        ],
        'increase_volume' => [
            'title' => 'افزایش حجم سرویس',
            'icon' => '🔋',
            'sql' => "`type` LIKE 'INCREASE_VOLUME_%'",
            'match' => function($type){ return preg_match('/^INCREASE_VOLUME_/', $type) === 1; }
        ],
        'increase_day' => [
            'title' => 'افزایش زمان سرویس',
            'icon' => '⏰',
            'sql' => "`type` LIKE 'INCREASE_DAY_%'",
            'match' => function($type){ return preg_match('/^INCREASE_DAY_/', $type) === 1; }
        ],
    ];
}

function v2raystore_getAutoApproveTypes(){
    global $botState;
    $items = v2raystore_autoApproveTypeItems();
    $defaults = [];
    foreach($items as $key => $item) $defaults[$key] = 'on';

    $raw = $botState['autoApproveTypes'] ?? '';
    $saved = [];
    if(is_array($raw)) $saved = $raw;
    else{
        $raw = trim((string)$raw);
        if($raw !== ''){
            $decoded = json_decode($raw, true);
            if(is_array($decoded)) $saved = $decoded;
        }
    }

    foreach($saved as $key => $value){
        if(array_key_exists($key, $defaults)) $defaults[$key] = ($value === 'off' || $value === 0 || $value === false) ? 'off' : 'on';
    }
    return $defaults;
}

function v2raystore_saveAutoApproveTypes($types){
    $items = v2raystore_autoApproveTypeItems();
    $clean = [];
    foreach($items as $key => $item){
        $value = is_array($types) && array_key_exists($key, $types) ? $types[$key] : 'on';
        $clean[$key] = ($value === 'off' || $value === 0 || $value === false) ? 'off' : 'on';
    }
    setSettings('autoApproveTypes', json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

function v2raystore_isAutoApproveTypeEnabled($payType){
    $payType = trim((string)$payType);
    if($payType === '') return false;
    $states = v2raystore_getAutoApproveTypes();
    foreach(v2raystore_autoApproveTypeItems() as $key => $item){
        $matcher = $item['match'] ?? null;
        if(is_callable($matcher) && $matcher($payType)) return (($states[$key] ?? 'on') === 'on');
    }
    return false;
}

function v2raystore_getAutoApproveEnabledSqlCondition(){
    $states = v2raystore_getAutoApproveTypes();
    $parts = [];
    foreach(v2raystore_autoApproveTypeItems() as $key => $item){
        if(($states[$key] ?? 'on') === 'on' && !empty($item['sql'])) $parts[] = '(' . $item['sql'] . ')';
    }
    if(count($parts) == 0) return '';
    return '(' . implode(' OR ', $parts) . ')';
}

function v2raystore_getAutoApproveTypesText(){
    $states = v2raystore_getAutoApproveTypes();
    $msg = "✅ <b>موارد فعال برای تأیید خودکار</b>\n\n" .
           "هر موردی که روشن باشد، بعد از ارسال رسید و گذشت زمان تعیین‌شده خودکار تأیید می‌شود؛ موارد خاموش فقط برای ادمین ارسال می‌شوند.\n\n";
    foreach(v2raystore_autoApproveTypeItems() as $key => $item){
        $on = (($states[$key] ?? 'on') === 'on');
        $msg .= ($item['icon'] ?? '•') . ' ' . v2raystore_h($item['title'] ?? $key) . ': <b>' . ($on ? 'روشن ✅' : 'خاموش ❌') . "</b>\n";
    }
    return $msg;
}

function v2raystore_getAutoApproveTypesKeys(){
    $states = v2raystore_getAutoApproveTypes();
    $rows = [];
    foreach(v2raystore_autoApproveTypeItems() as $key => $item){
        $on = (($states[$key] ?? 'on') === 'on');
        $rows[] = [[
            'text' => ($item['icon'] ?? '•') . ' ' . ($item['title'] ?? $key) . ': ' . ($on ? 'روشن ✅' : 'خاموش ❌'),
            'callback_data' => 'toggleAutoApproveType_' . $key,
            'style' => $on ? 'success' : 'danger'
        ]];
    }
    $rows[] = [
        ['text'=>'✅ روشن کردن همه', 'callback_data'=>'setAllAutoApproveTypes_on', 'style'=>'success'],
        ['text'=>'❌ خاموش کردن همه', 'callback_data'=>'setAllAutoApproveTypes_off', 'style'=>'danger']
    ];
    $rows[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'autoApproveOrdersMenu', 'style'=>'primary']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_getAutoApproveState(){
    global $botState;
    $minutes = intval($botState['autoApproveMinutes'] ?? 5);
    if($minutes < 1) $minutes = 5;
    $blocked = v2raystore_getAutoApproveBlockedUsers();
    return [
        'enabled' => (($botState['autoApproveState'] ?? 'off') === 'on'),
        'minutes' => $minutes,
        'blocked_count' => count($blocked),
        'types' => v2raystore_getAutoApproveTypes()
    ];
}

function v2raystore_getAutoApproveMenuText(){
    $stateData = v2raystore_getAutoApproveState();
    $enabled = !empty($stateData['enabled']);
    $minutes = intval($stateData['minutes']);
    $state = $enabled ? 'روشن ✅' : 'خاموش ❌';
    $blockedCount = count(v2raystore_getAutoApproveBlockedUsers());
    $activeTypes = [];
    $typeStates = v2raystore_getAutoApproveTypes();
    foreach(v2raystore_autoApproveTypeItems() as $key => $item){
        if(($typeStates[$key] ?? 'on') === 'on') $activeTypes[] = ($item['icon'] ?? '•') . ' ' . ($item['title'] ?? $key);
    }
    $typesText = count($activeTypes) ? implode('، ', $activeTypes) : 'هیچ موردی فعال نیست';
    return "⏱ <b>تأیید خودکار سفارش‌ها</b>\n\n" .
           "وضعیت فعلی: <b>$state</b>\n" .
           "زمان تأیید خودکار: <b>$minutes دقیقه بعد از ارسال رسید</b>\n" .
           "موارد فعال: <b>" . v2raystore_h($typesText) . "</b>\n" .
           "کاربران مستثنی از تأیید خودکار: <b>$blockedCount نفر</b>\n\n" .
           "رسیدهای کارت‌به‌کارت فقط برای مواردی که در بخش «موارد تأیید خودکار» روشن هستند، بعد از زمان تعیین‌شده خودکار تأیید می‌شوند.\n" .
           "کاربرانی که داخل لیست بلاک تأیید خودکار باشند، رسیدهایشان فقط برای ادمین می‌رود و خودکار تأیید نمی‌شود.";
}

function v2raystore_getAutoApproveMenuKeys(){
    $s = v2raystore_getAutoApproveState();
    $toggle = $s['enabled'] ? 'خاموش کردن ❌' : 'روشن کردن ✅';
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>$toggle, 'callback_data'=>'toggleAutoApproveOrders', 'style'=>$s['enabled'] ? 'danger' : 'success'],
            ['text'=>'⏱ تنظیم دقیقه', 'callback_data'=>'setAutoApproveMinutes', 'style'=>'primary']
        ],
        [
            ['text'=>'✅ موارد تأیید خودکار', 'callback_data'=>'autoApproveTypesMenu', 'style'=>'primary']
        ],
        [
            ['text'=>'🚫 بلاک تأیید خودکار (' . intval($s['blocked_count']) . ')', 'callback_data'=>'autoApproveBlockedUsersMenu', 'style'=>'danger']
        ],
        [
            ['text'=>'🚀 بررسی و اجرای الان', 'callback_data'=>'runAutoApproveOrdersNow', 'style'=>'success']
        ],
        [
            ['text'=>'⬅️ بازگشت', 'callback_data'=>'managePanel', 'style'=>'primary']
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function v2raystore_getAutoApproveBlockedUsersText(){
    global $connection;
    $ids = v2raystore_getAutoApproveBlockedUsers();
    $msg = "🚫 <b>کاربران مستثنی از تأیید خودکار</b>\n\n" .
           "رسیدهای این کاربران خودکار تأیید نمی‌شود و مثل حالت عادی باید ادمین تأیید/رد کند.\n\n";
    if(count($ids) == 0) return $msg . "لیست فعلاً خالی است.";

    $msg .= "لیست فعلی:\n";
    foreach($ids as $uid){
        $display = '';
        if(isset($connection) && $connection){
            $stmt = @$connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($u){
                    $name = trim((string)($u['name'] ?? ''));
                    $username = trim((string)($u['username'] ?? ''));
                    if($username !== '') $username = '@' . ltrim($username, '@');
                    $display = trim($name . ' ' . $username);
                }
            }
        }
        $msg .= "• <code>$uid</code>" . ($display !== '' ? ' - ' . v2raystore_h($display) : '') . "\n";
    }
    return $msg;
}

function v2raystore_getAutoApproveBlockedUsersKeys(){
    $rows = [
        [
            ['text'=>'➕ افزودن کاربر', 'callback_data'=>'addAutoApproveBlockedUser', 'style'=>'success'],
            ['text'=>'➖ حذف با آیدی', 'callback_data'=>'removeAutoApproveBlockedUserManual', 'style'=>'warning']
        ]
    ];
    $ids = v2raystore_getAutoApproveBlockedUsers();
    foreach(array_slice($ids, 0, 20) as $uid){
        $rows[] = [[
            'text'=>'حذف ' . $uid,
            'callback_data'=>'removeAutoApproveBlockedUser' . $uid,
            'style'=>'danger'
        ]];
    }
    if(count($ids) > 0){
        $rows[] = [[
            'text'=>'🧹 پاک کردن کل لیست',
            'callback_data'=>'clearAutoApproveBlockedUsers',
            'style'=>'danger'
        ]];
    }
    $rows[] = [['text'=>'⬅️ بازگشت', 'callback_data'=>'autoApproveOrdersMenu', 'style'=>'primary']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}


function v2raystore_autoApproveFunctionAllowed($fn){
    if(!function_exists($fn)) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}


function v2raystore_getAutoApproveExactStartAt(){
    global $connection;
    $key = 'AUTO_APPROVE_EXACT_START_AT';
    if(!isset($connection) || !$connection) return time();
    $stmt = @$connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $value = intval($row['value'] ?? 0);
        if($value > 0) return $value;
    }
    $now = time();
    $stmt = @$connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = `value`");
    if($stmt){
        $nowText = (string)$now;
        $stmt->bind_param('ss', $key, $nowText);
        $stmt->execute();
        $stmt->close();
    }
    return $now;
}

function v2raystore_scheduleAutoApproveWakeup($hashId = ''){
    global $connection;
    $hashId = trim((string)$hashId);
    if(!function_exists('v2raystore_getAutoApproveState')) return false;
    $state = v2raystore_getAutoApproveState();
    if(empty($state['enabled'])) return false;

    $minutes = max(1, intval($state['minutes'] ?? 1));
    $sentAt = time();
    if($hashId !== '' && isset($connection) && $connection){
        $stmt = @$connection->prepare("SELECT `user_id`, `type`, `state`, COALESCE(NULLIF(`sent_date`,0), `request_date`, ?) AS `sent_at` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
        if($stmt){
            $now = time();
            $stmt->bind_param('is', $now, $hashId);
            $stmt->execute();
            $pay = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if(!$pay || (string)($pay['state'] ?? '') !== 'sent') return false;
            if(function_exists('v2raystore_isAutoApproveTypeEnabled') && !v2raystore_isAutoApproveTypeEnabled((string)($pay['type'] ?? ''))) return false;
            if(function_exists('v2raystore_getAutoApproveBlockedUsers')){
                $blocked = array_map('intval', v2raystore_getAutoApproveBlockedUsers());
                if(in_array(intval($pay['user_id'] ?? 0), $blocked, true)) return false;
            }
            $sentAt = intval($pay['sent_at'] ?? $sentAt);
            if($sentAt <= 0) $sentAt = time();
            if(function_exists('v2raystore_getAutoApproveExactStartAt') && $sentAt < v2raystore_getAutoApproveExactStartAt()) return false;
        }
    }

    $delay = max(0, ($sentAt + ($minutes * 60)) - time()) + 2;
    $delay = min($delay, 86400);
    $script = __DIR__ . '/settings/autoApproveOrders.php';
    if(!is_file($script)) return false;
    $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . intval($delay) . ' ' . escapeshellarg($hashId) . ' > /dev/null 2>&1 &';

    if(v2raystore_autoApproveFunctionAllowed('exec')){
        @exec($cmd);
        return true;
    }
    if(v2raystore_autoApproveFunctionAllowed('shell_exec')){
        @shell_exec($cmd);
        return true;
    }
    if(v2raystore_autoApproveFunctionAllowed('popen')){
        $handle = @popen($cmd, 'r');
        if($handle){ @pclose($handle); return true; }
    }
    return false;
}

function v2raystore_markPayReceiptSent($hashId, $receiptFileId = null){
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
    if($ok && function_exists('v2raystore_scheduleAutoApproveWakeup')){
        v2raystore_scheduleAutoApproveWakeup($hashId);
    }
    return $ok;
}

function v2raystore_storeAdminPayMessage($hashId, $chatId, $messageId){
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

function v2raystore_getAdminPayMessage($hashId){
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

function v2raystore_shortButtonText($text, $max = 56){
    $text = trim((string)$text);
    if($text === '') return '';
    if(function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    if(!function_exists('mb_strlen') && strlen($text) > $max) return substr($text, 0, $max - 3) . '...';
    return $text;
}

function v2raystore_approvalStatusTextFromResult($result, $auto = false){
    $remarks = $result['remarks'] ?? [];
    if(!is_array($remarks)) $remarks = [];
    $prefix = $auto ? '🤖 تأیید خودکار شد' : '✅ تأیید شد';
    if(!empty($result['renew_remark'])) return v2raystore_shortButtonText($prefix . ': ' . $result['renew_remark']);
    if(count($remarks) == 1) return v2raystore_shortButtonText($prefix . ': ' . $remarks[0]);
    if(count($remarks) > 1) return $prefix . ' | ' . count($remarks) . ' کانفیگ ساخته شد';
    return $prefix;
}

function v2raystore_approvalCopyTextFromResult($result){
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

function v2raystore_approvalConfigNamesLineFromResult($result){
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

    $shown = array_slice($items, 0, 10);
    $escaped = array_map(function($item){ return '<code>' . v2raystore_h($item) . '</code>'; }, $shown);
    $more = count($items) - count($shown);
    if($more > 0) $escaped[] = 'و ' . intval($more) . ' کانفیگ دیگر';

    if(count($escaped) == 1) return '🔮 نام کانفیگ: ' . $escaped[0];
    return "🔮 نام کانفیگ‌ها:
" . implode("
", $escaped);
}

function v2raystore_updateAdminPayMessageStatus($hashId, $statusText, $style = 'success', $userId = 0, $copyText = ''){
    [$chat, $msg, $storedUser] = v2raystore_getAdminPayMessage($hashId);
    if($userId <= 0) $userId = $storedUser;
    if($chat == 0 || $msg <= 0) return false;
    $keys = v2raystore_orderStatusKeyboard($statusText, $userId, $style, $copyText);
    $keys = v2raystore_styleReplyMarkup($keys);
    $res = bot('editMessageReplyMarkup',[
        'chat_id' => $chat,
        'message_id' => $msg,
        'reply_markup' => $keys
    ]);
    if(is_object($res) && isset($res->ok) && $res->ok) return true;
    $desc = is_object($res) && isset($res->description) ? (string)$res->description : '';
    if(function_exists('v2raystore_isUserPrivacyButtonError') && v2raystore_isUserPrivacyButtonError($desc)){
        $removed = false;
        $safeKeys = v2raystore_stripPrivateUserButtons($keys, $removed);
        if($removed){
            bot('editMessageReplyMarkup',[
                'chat_id' => $chat,
                'message_id' => $msg,
                'reply_markup' => $safeKeys
            ]);
        }
    }
    return true;
}

function v2raystore_setPayApprovalError($hashId, $message){
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

function v2raystore_payLinkedOrderIds($hashId){
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

function v2raystore_autoOrderActionKeyboard($hashId, $userId){
    $rows = [];
    if(v2raystore_reportDetailEnabled('cancel_button', 'on')){
        $rows[] = [[ 'text'=>'❌ لغو کامل سفارش', 'callback_data'=>'autoCancelOrder' . $hashId, 'style'=>'danger' ]];
    }
    if(v2raystore_reportDetailEnabled('private_button', 'on')) $rows[] = [v2raystore_userPrivateButton($userId)];
    if(count($rows) == 0) return null;
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_orderStatusKeyboard($statusText, $userId = 0, $style = 'success', $copyText = ''){
    $copyText = trim((string)$copyText);
    if($copyText !== ''){
        $mainButton = ['text'=>$statusText, 'copy_text'=>['text'=>$copyText]];
    }else{
        $mainButton = ['text'=>$statusText, 'callback_data'=>'v2raystore', 'style'=>$style];
    }
    $rows = [[$mainButton]];
    $userId = intval($userId);
    if($userId > 0) $rows[] = [v2raystore_userPrivateButton($userId)];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function v2raystore_adminPendingOrderKeyboard($hashId, $userId){
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ تأیید', 'callback_data'=>'accept' . $hashId, 'style'=>'success'],
            ['text'=>'❌ عدم تأیید', 'callback_data'=>'declineOrder' . $hashId, 'style'=>'danger']
        ],
        [
            v2raystore_userPrivateButton($userId)
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function v2raystore_adminPendingWalletKeyboard($hashId, $userId){
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ تأیید', 'callback_data'=>'approvePayment' . $hashId, 'style'=>'success'],
            ['text'=>'❌ عدم تأیید', 'callback_data'=>'decPayment' . $hashId, 'style'=>'danger']
        ],
        [
            v2raystore_userPrivateButton($userId)
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function v2raystore_getPayByHash($hashId){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return null;
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return null;
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $pay ?: null;
}

function v2raystore_cartToCartReceiptTypeTitle($pay, $stepPrefix = ''){
    $type = is_array($pay) ? (string)($pay['type'] ?? '') : '';
    if($type === 'INCREASE_WALLET') return 'شارژ کیف پول';
    if($type === 'RENEW_ACCOUNT') return 'تمدید سرویس';
    if($type === 'RENEW_SCONFIG') return 'تمدید سرویس سفارشی';
    if(preg_match('/^INCREASE_DAY_/', $type)) return 'افزایش زمان سرویس';
    if(preg_match('/^INCREASE_VOLUME_/', $type)) return 'افزایش حجم سرویس';
    if($type === 'BUY_SUB') return $stepPrefix === 'payCustomWithCartToCart' ? 'خرید سفارشی' : 'خرید جدید';
    return 'پرداخت کارت‌به‌کارت';
}

function v2raystore_adminReceiptKeyboardByPay($pay, $stepPrefix = ''){
    global $buttonValues;
    if(!is_array($pay)) return null;
    $hashId = (string)($pay['hash_id'] ?? '');
    $userId = intval($pay['user_id'] ?? 0);
    $type = (string)($pay['type'] ?? '');
    $approveText = $buttonValues['approve'] ?? '✅ تأیید';
    $declineText = $buttonValues['decline'] ?? '❌ عدم تأیید';

    if($type === 'INCREASE_WALLET') return v2raystore_adminPendingWalletKeyboard($hashId, $userId);
    if($type === 'RENEW_ACCOUNT'){
        return v2raystore_inlineKeyboardJson([
            [
                ['text'=>$approveText, 'callback_data'=>'approveRenewAcc' . $hashId, 'style'=>'success'],
                ['text'=>$declineText, 'callback_data'=>'decRenewAcc' . $hashId, 'style'=>'danger']
            ],
            [v2raystore_userPrivateButton($userId)]
        ]);
    }
    if(preg_match('/^INCREASE_DAY_/', $type)){
        return v2raystore_inlineKeyboardJson([
            [
                ['text'=>$approveText, 'callback_data'=>'approveIncreaseDay' . $hashId, 'style'=>'success'],
                ['text'=>$declineText, 'callback_data'=>'decIncreaseDay' . $hashId, 'style'=>'danger']
            ],
            [v2raystore_userPrivateButton($userId)]
        ]);
    }
    if(preg_match('/^INCREASE_VOLUME_/', $type)){
        return v2raystore_inlineKeyboardJson([
            [
                ['text'=>$approveText, 'callback_data'=>'approveIncreaseVolume' . $hashId, 'style'=>'success'],
                ['text'=>$declineText, 'callback_data'=>'decIncreaseVolume' . $hashId, 'style'=>'danger']
            ],
            [v2raystore_userPrivateButton($userId)]
        ]);
    }
    return v2raystore_adminPendingOrderKeyboard($hashId, $userId);
}

function v2raystore_buildCartToCartReceiptAdminMessage($pay, $stepPrefix = ''){
    global $connection;
    if(!is_array($pay)) return '🧾 رسید پرداخت';
    $uid = intval($pay['user_id'] ?? 0);
    $type = (string)($pay['type'] ?? '');
    $price = number_format(intval($pay['price'] ?? 0));
    $typeTitle = v2raystore_cartToCartReceiptTypeTitle($pay, $stepPrefix);

    $user = null;
    if($uid > 0){
        $stmt = $connection->prepare("SELECT `name`, `username` FROM `users` WHERE `userid` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    $lines = ["🧾 <b>رسید پرداخت کارت‌به‌کارت</b>"];
    $lines[] = "📌 نوع: <b>" . v2raystore_h($typeTitle) . "</b>";
    if($user) $lines[] = v2raystore_formatUserLine($uid, $user['name'] ?? '', $user['username'] ?? '');
    else $lines[] = "🆔 کاربر: <code>{$uid}</code>";
    // کد پرداخت در پیام قابل مشاهده ادمین نمایش داده نمی‌شود؛ callback دکمه‌ها همان هش داخلی را نگه می‌دارد.
    $lines[] = "💰 مبلغ: <b>{$price} تومان</b>";

    $remark = '';
    $planTitle = '';
    $serverTitle = '';
    $volume = '';
    $days = '';

    if($type === 'RENEW_SCONFIG'){
        $configInfo = json_decode((string)($pay['description'] ?? ''), true);
        if(is_array($configInfo)) $remark = trim((string)($configInfo['remark'] ?? ''));
        else $remark = trim((string)($pay['description'] ?? ''));
        $planTitle = 'تمدید سرویس';
    }elseif($type === 'RENEW_ACCOUNT'){
        $oid = intval($pay['plan_id'] ?? 0);
        if($oid > 0){
            $stmt = $connection->prepare("SELECT `remark`, `fileid`, `server_id` FROM `orders_list` WHERE `id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $oid);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($order){
                    $remark = trim((string)($order['remark'] ?? ''));
                    $planId = function_exists('v2raystore_getRenewPlanIdFromPay') ? v2raystore_getRenewPlanIdFromPay($pay, $order) : intval($order['fileid'] ?? 0);
                    if($planId > 0){
                        $stmt = $connection->prepare("SELECT sp.`title`, sp.`volume`, sp.`days`, sc.`title` cat_title, si.`title` server_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sp.`catid` = sc.`id` LEFT JOIN `server_info` si ON sp.`server_id` = si.`id` WHERE sp.`id` = ? LIMIT 1");
                        if($stmt){
                            $stmt->bind_param('i', $planId);
                            $stmt->execute();
                            $plan = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if($plan){
                                $planTitle = trim(($plan['cat_title'] ?? '') . ' ' . ($plan['title'] ?? ''));
                                if($planTitle === '') $planTitle = trim((string)($plan['title'] ?? ''));
                                $serverTitle = trim((string)($plan['server_title'] ?? ''));
                                $volume = $plan['volume'] ?? '';
                                $days = $plan['days'] ?? '';
                            }
                        }
                    }
                    if($serverTitle === '' && intval($order['server_id'] ?? 0) > 0){
                        $sid = intval($order['server_id']);
                        $stmt = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id` = ? LIMIT 1");
                        if($stmt){
                            $stmt->bind_param('i', $sid);
                            $stmt->execute();
                            $srv = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if($srv) $serverTitle = trim((string)($srv['title'] ?? ''));
                        }
                    }
                }
            }
        }
    }elseif(preg_match('/^INCREASE_(DAY|VOLUME)_(\d+)_(\d+)/', $type, $m)){
        $orderId = intval($m[2]);
        $planId = intval($m[3]);
        if($orderId > 0){
            $stmt = $connection->prepare("SELECT `remark`, `server_id`, `fileid` FROM `orders_list` WHERE `id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($order){
                    $remark = trim((string)($order['remark'] ?? ''));
                    if(intval($order['server_id'] ?? 0) > 0){
                        $sid = intval($order['server_id']);
                        $stmt = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id` = ? LIMIT 1");
                        if($stmt){
                            $stmt->bind_param('i', $sid);
                            $stmt->execute();
                            $srv = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if($srv) $serverTitle = trim((string)($srv['title'] ?? ''));
                        }
                    }
                    $basePlanId = intval($order['fileid'] ?? 0);
                    if($basePlanId > 0){
                        $stmt = $connection->prepare("SELECT sp.`title`, sc.`title` cat_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sp.`catid` = sc.`id` WHERE sp.`id` = ? LIMIT 1");
                        if($stmt){
                            $stmt->bind_param('i', $basePlanId);
                            $stmt->execute();
                            $basePlan = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if($basePlan) $planTitle = trim(($basePlan['cat_title'] ?? '') . ' ' . ($basePlan['title'] ?? ''));
                        }
                    }
                }
            }
        }
        if($planId > 0){
            $table = ($m[1] === 'DAY') ? 'increase_day' : 'increase_plan';
            $stmt = $connection->prepare("SELECT `volume` FROM `{$table}` WHERE `id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $inc = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($inc) $volume = $inc['volume'] ?? '';
            }
        }
    }elseif($type !== 'INCREASE_WALLET'){
        $remark = trim((string)($pay['description'] ?? ''));
        $planId = intval($pay['plan_id'] ?? 0);
        $volume = $pay['volume'] ?? '';
        $days = $pay['day'] ?? '';
        if($planId > 0){
            $stmt = $connection->prepare("SELECT sp.`title`, sp.`volume`, sp.`days`, sc.`title` cat_title, si.`title` server_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sp.`catid` = sc.`id` LEFT JOIN `server_info` si ON sp.`server_id` = si.`id` WHERE sp.`id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $plan = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if($plan){
                    $planTitle = trim(($plan['cat_title'] ?? '') . ' ' . ($plan['title'] ?? ''));
                    if($planTitle === '') $planTitle = trim((string)($plan['title'] ?? ''));
                    $serverTitle = trim((string)($plan['server_title'] ?? ''));
                    if($volume === '' || intval($volume) <= 0) $volume = $plan['volume'] ?? $volume;
                    if($days === '' || intval($days) <= 0) $days = $plan['days'] ?? $days;
                }
            }
        }
    }

    if($serverTitle !== '') $lines[] = "🖥 سرور: <b>" . v2raystore_h($serverTitle) . "</b>";
    if($planTitle !== '') $lines[] = "📦 پلن/سرویس: <b>" . v2raystore_h($planTitle) . "</b>";
    if($remark !== '') $lines[] = "🔮 نام کانفیگ: <code>" . v2raystore_h($remark) . "</code>";
    if($type === 'INCREASE_WALLET'){
        $lines[] = "👛 این پرداخت برای افزایش موجودی کیف پول است.";
    }elseif(preg_match('/^INCREASE_DAY_/', $type) && $volume !== '' && intval($volume) > 0){
        $lines[] = "⏰ افزایش زمان: <b>" . v2raystore_h($volume) . " روز</b>";
    }elseif(preg_match('/^INCREASE_VOLUME_/', $type) && $volume !== '' && intval($volume) > 0){
        $lines[] = "🔋 افزایش حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
    }else{
        if($volume !== '' && intval($volume) > 0) $lines[] = "🔋 حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
        if($days !== '' && intval($days) > 0) $lines[] = "⏰ مدت: <b>" . v2raystore_h($days) . " روز</b>";
    }
    $lines[] = "\n✅ عکس رسید به همین پیام وصل شده و دکمه‌های بررسی زیر آن قرار دارد.";
    return implode("\n", $lines);
}

function v2raystore_processCartToCartReceiptUpload($hashId, $stepPrefix, $fileId){
    global $from_id, $mainValues;
    $hashId = trim((string)$hashId);
    $fileId = trim((string)$fileId);
    $stepPrefix = trim((string)$stepPrefix);
    if($hashId === '') return ['ok'=>false, 'message'=>'کد پرداخت نامعتبر است.'];
    if($fileId === '') return ['ok'=>false, 'message'=>'لطفاً رسید را فقط به صورت عکس ارسال کنید.'];

    $pay = v2raystore_getPayByHash($hashId);
    if(!$pay) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد یا منقضی شده است.'];
    $uid = intval($pay['user_id'] ?? 0);
    if($uid > 0 && intval($from_id ?? 0) > 0 && $uid !== intval($from_id)) return ['ok'=>false, 'message'=>'این پرداخت متعلق به حساب شما نیست.'];

    $state = (string)($pay['state'] ?? '');
    if(in_array($state, ['approved', 'paid_with_wallet'], true)) return ['ok'=>false, 'message'=>'این سفارش قبلاً تأیید شده است.'];
    if(in_array($state, ['declined', 'auto_cancelled'], true)) return ['ok'=>false, 'message'=>'این سفارش قبلاً رد یا لغو شده است.'];

    if(!v2raystore_markPayReceiptSent($hashId, $fileId)) return ['ok'=>false, 'message'=>'ثبت رسید در دیتابیس انجام نشد. لطفاً دوباره تلاش کنید.'];
    $pay['state'] = 'sent';
    $pay['receipt_file_id'] = $fileId;

    $msg = v2raystore_buildCartToCartReceiptAdminMessage($pay, $stepPrefix);
    $keyboard = v2raystore_adminReceiptKeyboardByPay($pay, $stepPrefix);
    $adminSend = v2raystore_sendAdminPaymentPhoto($hashId, $fileId, $msg, $keyboard, 'HTML', $uid);

    $type = (string)($pay['type'] ?? '');
    if($type === 'INCREASE_WALLET') $userMessage = $mainValues['order_increase_sent'] ?? '✅ رسید شارژ کیف پول شما ثبت شد و برای ادمین ارسال شد.';
    elseif($type === 'RENEW_ACCOUNT' || preg_match('/^INCREASE_(DAY|VOLUME)_/', $type)) $userMessage = $mainValues['renew_order_sent'] ?? '✅ رسید شما ثبت شد و برای ادمین ارسال شد.';
    else $userMessage = $mainValues['order_buy_sent'] ?? '✅ رسید خرید شما ثبت شد و برای ادمین ارسال شد.';

    return [
        'ok'=>true,
        'admin_ok'=>!empty($adminSend['ok']),
        'admin_message'=>($adminSend['message'] ?? ''),
        'user_message'=>$userMessage,
    ];
}

function v2raystore_notifyOrderReceiptSent($hashId, $fileId = null){
    // رسید خرید دیگر به کانال/گروه گزارش درآمد ارسال نمی‌شود.
    // فقط پیام مستقیم ادمین ارسال می‌شود و گزارش کانال مخصوص تأیید خودکار باقی می‌ماند.
    return null;
}

function v2raystore_getOrderAdminRecipients(){
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

function v2raystore_adminSendFallbackText($hashId, $photo, $caption){
    $hashId = trim((string)$hashId);
    $photo = trim((string)$photo);
    $text = (string)$caption;
    $extra = "\n\n⚠️ <b>توجه:</b> ارسال عکس رسید برای این پیام ناموفق بود، اما سفارش از دست نرفته است و از همین دکمه‌ها قابل بررسی است.";
    if($photo !== '') $extra .= "\n🖼 File ID رسید: <code>" . v2raystore_h($photo) . "</code>";
    return $text . $extra;
}

function v2raystore_sendAdminPaymentPhotoToChat($chatId, $hashId, $photo, $caption, $keyboard = null, $parse = 'HTML'){
    $hashId = trim((string)$hashId);
    $photo = trim((string)$photo);
    $plainCaption = function_exists('v2raystore_plainTextForTelegram') ? v2raystore_plainTextForTelegram($caption) : strip_tags((string)$caption);
    $plainCaption = trim($plainCaption) !== '' ? trim($plainCaption) : '🧾 رسید پرداخت کارت‌به‌کارت';
    if(function_exists('mb_substr')) $safePlainCaption = mb_substr($plainCaption, 0, 900, 'UTF-8');
    else $safePlainCaption = substr($plainCaption, 0, 900);
    $minimalCaption = '🧾 رسید پرداخت کارت‌به‌کارت';

    $ok = false;
    $res = null;
    $descList = [];

    if($photo !== ''){
        $attempts = [
            [$caption, $parse, 'photo with html caption'],
            [$safePlainCaption, null, 'photo with plain caption'],
            [$minimalCaption, null, 'photo with minimal caption'],
        ];
        foreach($attempts as $attempt){
            $res = sendPhoto($photo, $attempt[0], $keyboard, $attempt[1], $chatId);
            $ok = is_object($res) && isset($res->ok) && $res->ok;
            if($ok) break;
            $desc = is_object($res) && isset($res->description) ? (string)$res->description : ($attempt[2] . ' failed');
            $descList[] = $desc;
        }
    }

    if(!$ok){
        // اگر تلگرام به هر دلیل اجازه ارسال عکس همراه دکمه را نداد، پیام متنیِ سفارش با دکمه‌ها ارسال می‌شود
        // تا ادمین بدون دکمه نماند. File ID رسید هم داخل متن می‌آید تا قابل پیگیری باشد.
        $fallback = v2raystore_adminSendFallbackText($hashId, $photo, $caption);
        $res = sendMessage($fallback, $keyboard, $parse, $chatId);
        $ok = is_object($res) && isset($res->ok) && $res->ok;
        if(!$ok){
            $desc3 = is_object($res) && isset($res->description) ? (string)$res->description : 'sendMessage fallback failed';
            $descList[] = $desc3;
            $plainFallback = function_exists('v2raystore_plainTextForTelegram') ? v2raystore_plainTextForTelegram($fallback) : strip_tags($fallback);
            $res = sendMessage($plainFallback, $keyboard, null, $chatId);
            $ok = is_object($res) && isset($res->ok) && $res->ok;
            if(!$ok){
                $desc4 = is_object($res) && isset($res->description) ? (string)$res->description : 'sendMessage plain fallback failed';
                $descList[] = $desc4;
            }
        }
    }

    return ['ok'=>$ok, 'result'=>$res, 'errors'=>$descList];
}

function v2raystore_sendAdminPaymentPhoto($hashId, $photo, $caption, $keyboard = null, $parse = 'HTML', $userId = 0){
    $hashId = trim((string)$hashId);
    $photo = trim((string)$photo);
    $recipients = v2raystore_getOrderAdminRecipients();
    if(count($recipients) == 0) return ['ok'=>false, 'sent'=>0, 'message'=>'هیچ ادمینی برای ارسال سفارش پیدا نشد.'];

    // اول با دکمه tg://user?id تلاش می‌کنیم. اگر تلگرام خطای BUTTON_USER_PRIVACY_RESTRICTED بدهد،
    // همان پیام دوباره بدون دکمه پی‌وی ارسال می‌شود تا دکمه‌های تأیید/رد از بین نروند.
    $keyboard = v2raystore_styleReplyMarkup($keyboard);
    $removedPrivateButton = false;
    $keyboardWithoutPrivate = v2raystore_stripPrivateUserButtons($keyboard, $removedPrivateButton);

    $sent = 0;
    $firstChat = 0;
    $firstMsg = 0;
    $errors = [];

    foreach($recipients as $chatId){
        $chatId = intval($chatId);
        if($chatId == 0) continue;

        $try = v2raystore_sendAdminPaymentPhotoToChat($chatId, $hashId, $photo, $caption, $keyboard, $parse);
        $ok = !empty($try['ok']);
        $res = $try['result'] ?? null;
        $descList = $try['errors'] ?? [];

        if(!$ok && $removedPrivateButton && v2raystore_isUserPrivacyButtonError($descList)){
            $descList[] = 'private user button removed because Telegram returned BUTTON_USER_PRIVACY_RESTRICTED';
            $try2 = v2raystore_sendAdminPaymentPhotoToChat($chatId, $hashId, $photo, $caption, $keyboardWithoutPrivate, $parse);
            $ok = !empty($try2['ok']);
            $res = $try2['result'] ?? $res;
            if(!empty($try2['errors']) && is_array($try2['errors'])) $descList = array_merge($descList, $try2['errors']);
        }

        if($ok){
            $sent++;
            if($firstMsg <= 0 && is_object($res) && isset($res->result->message_id)){
                $firstChat = $chatId;
                $firstMsg = intval($res->result->message_id);
            }
        }else{
            $errors[] = $chatId . ': ' . implode(' | ', $descList);
        }
    }

    if($sent > 0 && $hashId !== '' && $firstMsg > 0){
        v2raystore_storeAdminPayMessage($hashId, $firstChat, $firstMsg);
    }

    if($sent <= 0){
        $errText = count($errors) ? implode("\n", array_slice($errors, 0, 5)) : 'نامشخص';
        $faErr = function_exists('v2raystore_translateTechnicalError') ? v2raystore_translateTechnicalError($errText) : $errText;
        if(function_exists('v2raystore_reportEvent')){
            $body = "⚠️ <b>ارسال پیام سفارش به ادمین ناموفق بود</b>\n" .
                    ($userId ? "🆔 کاربر: <code>" . intval($userId) . "</code>\n" : '') .
                    "📝 خطا به فارسی:\n<code>" . v2raystore_h($faErr) . "</code>" . v2raystore_reportTimeLine();
            $keyboardReport = $userId ? v2raystore_reportPrivateKeyboard($userId) : null;
            v2raystore_reportEvent('⚠️ خطای ارسال سفارش به ادمین', $body, $keyboardReport, 'admin_order_send_failed');
        }
        return ['ok'=>false, 'sent'=>0, 'message'=>$faErr, 'errors'=>$errors];
    }

    return ['ok'=>true, 'sent'=>$sent, 'chat_id'=>$firstChat, 'message_id'=>$firstMsg, 'errors'=>$errors];
}

function v2raystore_buildPendingAdminOrderMessage($pay){
    global $connection;
    if(!is_array($pay)) return '';
    $hash = (string)($pay['hash_id'] ?? '');
    $uid = intval($pay['user_id'] ?? 0);
    $price = number_format(intval($pay['price'] ?? 0));
    $type = (string)($pay['type'] ?? '');
    $planId = intval($pay['plan_id'] ?? 0);
    $remark = '';
    $planTitle = 'نامشخص';
    $serverTitle = '';
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
                    $serverTitle = trim((string)($plan['server_title'] ?? ''));
                    if($planTitle === '') $planTitle = 'پلن خرید';
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
    if($user) $lines[] = v2raystore_formatUserLine($uid, $user['name'] ?? '', $user['username'] ?? '');
    else $lines[] = "🆔 کاربر: <code>{$uid}</code>";
    $lines[] = "💰 مبلغ: <b>{$price} تومان</b>";
    if($serverTitle !== '') $lines[] = "🖥 سرور: <b>" . v2raystore_h($serverTitle) . "</b>";
    $lines[] = "📦 پلن: <b>" . v2raystore_h($planTitle) . "</b>";
    if($remark !== '') $lines[] = "🔮 ریمارک: <code>" . v2raystore_h($remark) . "</code>";
    if($volume !== '' && intval($volume) > 0) $lines[] = "🔋 حجم: <b>" . v2raystore_h($volume) . " گیگ</b>";
    if($days !== '' && intval($days) > 0) $lines[] = "⏰ مدت: <b>" . v2raystore_h($days) . " روز</b>";
    $lines[] = "\n⚠️ این پیام به‌صورت بازیابی خودکار ارسال شده چون پیام سفارش قبلی در ادمین ثبت نشده بود.";
    return implode("\n", $lines);
}

function v2raystore_resendMissingAdminOrderMessages($limit = 3){
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
        $msg = v2raystore_buildPendingAdminOrderMessage($pay);
        $keyboard = v2raystore_adminPendingOrderKeyboard($hash, $uid);
        $photo = trim((string)($pay['receipt_file_id'] ?? ''));
        $res = v2raystore_sendAdminPaymentPhoto($hash, $photo, $msg, $keyboard, 'HTML', $uid);
        if(!empty($res['ok'])) $sent++;
    }
    return ['ok'=>true, 'sent'=>$sent];
}

function v2raystore_telegramResponseOk($res){
    if(is_object($res)){
        if(isset($res->ok)) return !empty($res->ok);
        return true;
    }
    if(is_array($res)){
        if(isset($res['ok'])) return !empty($res['ok']);
        return true;
    }
    return false;
}

function v2raystore_plainTextFromHtml($text){
    $text = (string)$text;
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
    $text = strip_tags($text);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function v2raystore_sendConfigLinksToUser($uid, $remark, $protocol, $volume, $days, $links, $subLink, $serverType){
    global $botUrl, $buttonValues, $botState;
    if(!is_array($links)) $links = [$links];
    $keyboard = json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'] ?? 'بازگشت', 'callback_data'=>'mainMenu']]]], JSON_UNESCAPED_UNICODE);

    // دقیقاً مثل خرید عادی/کیف پول: اگر چند دامنه وجود داشته باشد همه لینک‌ها در یک پیام ارسال می‌شوند.
    if(function_exists('v2raystore_sendMultiDomainConfigMessage') && v2raystore_sendMultiDomainConfigMessage($uid, $remark, $links, $subLink, $serverType, $keyboard)){
        return true;
    }

    if(!class_exists('QRcode') && file_exists('phpqrcode/qrlib.php')) @include_once 'phpqrcode/qrlib.php';
    if(!defined('IMAGE_WIDTH')) define('IMAGE_WIDTH', 540);
    if(!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    $sentAny = false;
    foreach($links as $link){
        $link = (string)$link;
        if(trim($link) === '') continue;
        $acc_text = "😍 سفارش جدید شما\n📡 پروتکل: $protocol\n🔮 نام سرویس: $remark\n🔋حجم سرویس: $volume گیگ\n⏰ مدت سرویس: $days روز\n" .
            (($botState['configLinkState'] ?? 'on') != 'off' && $serverType != 'marzban' ? "\n💝 config : <code>$link</code>" : '');
        if(($botState['subLinkState'] ?? 'off') == 'on' && $subLink != '') $acc_text .= "\n\n🌐 subscription : <code>$subLink</code>";

        $sendOk = false;
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
            $res = sendPhoto($botUrl . $file, $acc_text, $keyboard, 'HTML', $uid);
            $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($res) : true;
            @unlink($file);
        }

        // اگر ارسال عکس/QR به هر دلیل خطا داد، لینک در متن ساده ارسال می‌شود تا کاربر بدون کانفیگ نماند.
        if(!$sendOk){
            $res = sendMessage($acc_text, $keyboard, 'HTML', $uid);
            $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($res) : true;
        }
        if(!$sendOk){
            $plain = function_exists('v2raystore_plainTextFromHtml') ? v2raystore_plainTextFromHtml($acc_text) : strip_tags($acc_text);
            $res = sendMessage($plain, $keyboard, null, $uid);
            $sendOk = function_exists('v2raystore_telegramResponseOk') ? v2raystore_telegramResponseOk($res) : true;
        }
        if($sendOk) $sentAny = true;
    }
    return $sentAny;
}

function v2raystore_lockPayForApproval($hashId, $auto = false){
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

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'processing' WHERE `hash_id` = ? AND `state` IN ('sent','pending')");
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
            if(trim((string)($row['approval_error'] ?? '')) !== '' || (function_exists('v2raystore_payLinkedOrderIds') && count(v2raystore_payLinkedOrderIds($hashId)) == 0)){
                v2raystore_restorePayApprovalState($hashId);
                return v2raystore_lockPayForApproval($hashId, $auto);
            }
            return ['ok'=>false, 'message'=>'این سفارش در حال پردازش است؛ چند بار روی تأیید نزنید.'];
        }
        if($state === 'declined' || $state === 'auto_cancelled') return ['ok'=>false, 'message'=>'این سفارش قبلاً رد یا لغو شده است.'];
    }
    return ['ok'=>false, 'message'=>'این سفارش دیگر در وضعیت قابل تأیید نیست.'];
}

function v2raystore_restorePayApprovalState($hashId){
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

function v2raystore_getRenewSettings(){
    global $botState;
    $mode = (string)($botState['renewExtendMode'] ?? 'reset');
    if(!in_array($mode, ['reset','add'], true)) $mode = 'reset';
    $maxDays = intval($botState['renewMaxDays'] ?? 45);
    if($maxDays < 1) $maxDays = 45;
    return ['mode'=>$mode, 'max_days'=>$maxDays];
}

function v2raystore_getRenewSettingsMenuText(){
    $settings = v2raystore_getRenewSettings();
    $modeTitle = $settings['mode'] === 'add' ? 'افزایشی' : 'ریست کامل';
    return "🔄 <b>تنظیمات تمدید سرویس</b>\n\n" .
           "حالت فعلی: <b>{$modeTitle}</b>\n\n" .
           "• در حالت <b>ریست کامل</b>، تمدید مثل قبل انجام می‌شود: حجم و تاریخ سرویس طبق پلن جدید ریست می‌شود.\n" .
           "• در حالت <b>افزایشی</b>، حجم پلن به حجم فعلی اضافه می‌شود و روزها هم اضافه می‌شود؛ اما تاریخ سرویس از امروز بیشتر از <b>{$settings['max_days']} روز</b> نمی‌شود.";
}

function v2raystore_getRenewSettingsMenuKeys(){
    $settings = v2raystore_getRenewSettings();
    $mode = $settings['mode'];
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>($mode === 'reset' ? '✅ ریست کامل' : 'ریست کامل'), 'callback_data'=>'setRenewExtendMode_reset', 'style'=>($mode === 'reset' ? 'success' : 'primary')],
            ['text'=>($mode === 'add' ? '✅ افزایشی' : 'افزایشی'), 'callback_data'=>'setRenewExtendMode_add', 'style'=>($mode === 'add' ? 'success' : 'primary')]
        ],
        [
            ['text'=>'⬅️ بازگشت به تنظیمات ربات', 'callback_data'=>'botSettings', 'style'=>'primary']
        ]
    ]], JSON_UNESCAPED_UNICODE);
}

function v2raystore_renewMetaFromPay($pay){
    $meta = [];
    if(is_array($pay)){
        $raw = trim((string)($pay['description'] ?? ''));
        if($raw !== ''){
            $decoded = json_decode($raw, true);
            if(is_array($decoded)) $meta = $decoded;
        }
    }
    return $meta;
}

function v2raystore_getRenewOrderIdFromPay($pay){
    $meta = v2raystore_renewMetaFromPay($pay);
    $orderId = intval($meta['order_id'] ?? 0);
    if($orderId <= 0) $orderId = intval($pay['plan_id'] ?? 0);
    return $orderId;
}

function v2raystore_getRenewPlanIdFromPay($pay, $order = null){
    $meta = v2raystore_renewMetaFromPay($pay);
    $planId = intval($meta['renew_plan_id'] ?? 0);
    if($planId <= 0) $planId = intval($meta['selected_plan_id'] ?? 0);
    if($planId <= 0 && is_array($order)) $planId = intval($order['fileid'] ?? 0);
    if($planId <= 0) $planId = intval($pay['plan_id'] ?? 0);
    return $planId;
}

function v2raystore_calculateRenewAddDays($currentExpire, $planDays, $maxDays = 45){
    $now = time();
    $currentExpire = intval($currentExpire);
    $planDays = intval($planDays);
    $maxDays = intval($maxDays);
    if($maxDays < 1) $maxDays = 45;
    if($planDays <= 0) return 0;
    $base = max($currentExpire, $now);
    $maxExpire = $now + ($maxDays * 86400);
    $remainingSeconds = $maxExpire - $base;
    if($remainingSeconds <= 0) return 0;
    return max(0, min($planDays, (int)floor($remainingSeconds / 86400)));
}

function v2raystore_payHasLinkedApprovedOrder($pay){
    if(!is_array($pay)) return false;
    $orders = json_decode((string)($pay['auto_approved_orders'] ?? ''), true);
    if(is_array($orders) && count(array_filter($orders)) > 0) return true;
    $hashId = trim((string)($pay['hash_id'] ?? ''));
    if($hashId !== '' && function_exists('v2raystore_payLinkedOrderIds') && count(v2raystore_payLinkedOrderIds($hashId)) > 0) return true;
    return false;
}

function v2raystore_declinePayByHash($hashId, $reason = ''){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return ['ok'=>false, 'message'=>'کد پرداخت نامعتبر است.'];
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    if(!$stmt) return ['ok'=>false, 'message'=>'دسترسی به جدول پرداخت ممکن نیست.'];
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$pay) return ['ok'=>false, 'message'=>'پرداخت پیدا نشد.'];

    $state = (string)($pay['state'] ?? '');
    if(in_array($state, ['declined','auto_cancelled','cancelled_by_user'], true)){
        return ['ok'=>true, 'message'=>'این سفارش قبلاً رد یا لغو شده است.', 'already'=>true, 'user_id'=>intval($pay['user_id'] ?? 0)];
    }
    if($state === 'approved' && v2raystore_payHasLinkedApprovedOrder($pay)){
        return ['ok'=>false, 'message'=>'این سفارش قبلاً تأیید شده و کانفیگ/عملیات آن ثبت شده است.'];
    }

    $reason = trim((string)$reason);
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined', `cancel_reason` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ? AND `state` IN ('pending','sent','processing','auto_processing','approved','0','paid_with_wallet')");
    if(!$stmt) return ['ok'=>false, 'message'=>'ثبت رد سفارش ناموفق بود.'];
    $stmt->bind_param('ss', $reason, $hashId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if($changed <= 0) return ['ok'=>false, 'message'=>'این سفارش دیگر در وضعیت قابل رد کردن نیست.'];
    return ['ok'=>true, 'message'=>'سفارش رد شد.', 'user_id'=>intval($pay['user_id'] ?? 0), 'pay'=>$pay];
}

function v2raystore_restorePayApprovalStateTo($hashId, $state){
    global $connection;
    $hashId = trim((string)$hashId);
    $state = trim((string)$state);
    if($hashId === '' || $state === '') return false;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = ? WHERE `hash_id` = ? AND `state` IN ('processing','auto_processing')");
    if(!$stmt) return false;
    $stmt->bind_param('ss', $state, $hashId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_renewSnapshotFromOrder($order){
    global $connection;
    if(!is_array($order)) return [];
    $fileid = intval($order['fileid'] ?? 0);
    $plan = null;
    if($fileid > 0){
        $stmt = $connection->prepare("SELECT `id`, `volume`, `days`, `limitip`, `title` FROM `server_plans` WHERE `id` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i', $fileid);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    $volumeGb = floatval($plan['volume'] ?? 0);
    return [
        'order_id' => intval($order['id'] ?? 0),
        'fileid' => $fileid,
        'expire_date' => intval($order['expire_date'] ?? 0),
        'server_id' => intval($order['server_id'] ?? 0),
        'inbound_id' => intval($order['inbound_id'] ?? 0),
        'uuid' => (string)($order['uuid'] ?? ''),
        'remark' => (string)($order['remark'] ?? ''),
        'link' => (string)($order['link'] ?? ''),
        'amount' => intval($order['amount'] ?? 0),
        'volume_gb' => $volumeGb,
        'volume_bytes' => (int)floor($volumeGb * 1073741824),
        'plan_days' => intval($plan['days'] ?? 0),
        'created_at' => time()
    ];
}

function v2raystore_storeRenewSnapshotOnPay($hashId, $payInfo, $order){
    global $connection;
    $hashId = trim((string)$hashId);
    if($hashId === '') return [];
    $meta = v2raystore_renewMetaFromPay($payInfo);
    if(empty($meta['renew_snapshot']) || !is_array($meta['renew_snapshot'])){
        $meta['renew_snapshot'] = v2raystore_renewSnapshotFromOrder($order);
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $connection->prepare("UPDATE `pays` SET `description` = ? WHERE `hash_id` = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('ss', $encoded, $hashId);
            $stmt->execute();
            $stmt->close();
        }
    }
    return $meta['renew_snapshot'];
}

function v2raystore_restorePanelServiceExact($snapshot){
    if(!is_array($snapshot)) return ['ok'=>false, 'message'=>'اطلاعات قبلی سرویس برای برگشت پیدا نشد.'];
    $serverId = intval($snapshot['server_id'] ?? 0);
    $inboundId = intval($snapshot['inbound_id'] ?? 0);
    $uuid = (string)($snapshot['uuid'] ?? '');
    $remark = (string)($snapshot['remark'] ?? '');
    $expireMs = max(0, intval($snapshot['expire_date'] ?? 0)) * 1000;
    $totalBytes = max(0, intval($snapshot['volume_bytes'] ?? 0));
    if($serverId <= 0 || $uuid === '') return ['ok'=>false, 'message'=>'اطلاعات سرور/شناسه سرویس برای برگشت کامل نیست.'];

    $serverType = '';
    global $connection;
    $stmt = $connection->prepare("SELECT `type` FROM `server_config` WHERE `id` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('i', $serverId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverType = (string)($row['type'] ?? '');
    }

    if($serverType === 'marzban'){
        $response = editMarzbanConfig($serverId, [
            'remark' => $remark,
            'expire' => intval($snapshot['expire_date'] ?? 0),
            'data_limit' => $totalBytes
        ]);
    }else{
        $exact = ['total_bytes'=>$totalBytes, 'expire_ms'=>$expireMs];
        $response = ($inboundId > 0)
            ? editClientTraffic($serverId, $inboundId, $uuid, 0, 0, $exact)
            : editInboundTraffic($serverId, $uuid, 0, 0, $exact);
    }

    if(is_null($response)) return ['ok'=>false, 'message'=>'اتصال به پنل برای برگشت سرویس برقرار نشد.'];
    if(is_object($response) && isset($response->success) && empty($response->success)){
        $err = $response->msg ?? 'نامشخص';
        if(function_exists('v2raystore_translateTechnicalError')) $err = v2raystore_translateTechnicalError($err);
        return ['ok'=>false, 'message'=>'خطای پنل در برگشت سرویس: ' . $err];
    }
    if(is_array($response) && isset($response['success']) && empty($response['success'])){
        return ['ok'=>false, 'message'=>'خطای پنل در برگشت سرویس: ' . ($response['msg'] ?? 'نامشخص')];
    }
    return ['ok'=>true, 'message'=>'اطلاعات پنل برگشت داده شد.'];
}

function v2raystore_renewCompletedReportKeyboard($hashId, $userId){
    $rows = [];
    // برای تمدید، دکمه برگشت سرویس باید همیشه روی گزارش نهایی باشد؛ مستقل از تنظیمات عمومی دکمه لغو سفارش.
    $rows[] = [[ 'text'=>'↩️ لغو تمدید و برگشت سرویس', 'callback_data'=>'autoCancelOrder' . $hashId, 'style'=>'danger' ]];
    return v2raystore_reportPrivateKeyboard($userId, $rows);
}

function v2raystore_cancelApprovedRenewPay($pay, $reason){
    global $connection;
    if(!is_array($pay)) return ['ok'=>false, 'message'=>'پرداخت تمدید پیدا نشد.'];
    $hashId = trim((string)($pay['hash_id'] ?? ''));
    if($hashId === '') return ['ok'=>false, 'message'=>'کد پرداخت تمدید نامعتبر است.'];
    if((string)($pay['state'] ?? '') !== 'approved') return ['ok'=>false, 'message'=>'این تمدید هنوز تأیید نشده یا قبلاً لغو شده است.'];
    $meta = v2raystore_renewMetaFromPay($pay);
    $snapshot = $meta['renew_snapshot'] ?? null;
    if(!is_array($snapshot) || intval($snapshot['order_id'] ?? 0) <= 0){
        return ['ok'=>false, 'message'=>'نسخه قبل از تمدید برای این پرداخت ذخیره نشده است؛ برگشت امن ممکن نیست.'];
    }
    $orderId = intval($snapshot['order_id']);

    $restore = v2raystore_restorePanelServiceExact($snapshot);
    if(empty($restore['ok'])) return $restore;

    $fileid = intval($snapshot['fileid'] ?? 0);
    $expire = intval($snapshot['expire_date'] ?? 0);
    $link = (string)($snapshot['link'] ?? '');
    $amount = intval($snapshot['amount'] ?? 0);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ?, `expire_date` = ?, `link` = ?, `amount` = ?, `notif` = 0 WHERE `id` = ? LIMIT 1");
    if(!$stmt) return ['ok'=>false, 'message'=>'برگشت اطلاعات سفارش در دیتابیس ناموفق بود.'];
    $stmt->bind_param('iisii', $fileid, $expire, $link, $amount, $orderId);
    $ok = $stmt->execute();
    $stmt->close();
    if(!$ok) return ['ok'=>false, 'message'=>'برگشت اطلاعات سفارش در دیتابیس ناموفق بود.'];

    $reason = trim((string)$reason);
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'auto_cancelled', `cancel_reason` = ? WHERE `hash_id` = ? LIMIT 1");
    if($stmt){ $stmt->bind_param('ss', $reason, $hashId); $stmt->execute(); $stmt->close(); }

    $uid = intval($pay['user_id'] ?? 0);
    if($uid > 0){
        sendMessage("↩️ تمدید سرویس شما توسط مدیریت لغو شد و سرویس به وضعیت قبل از تمدید برگشت.\n\n📝 دلیل:\n" . $reason, null, 'HTML', $uid);
    }
    return ['ok'=>true, 'message'=>'تمدید لغو شد و تاریخ و حجم سرویس به قبل از تمدید برگشت.', 'type'=>'RENEW_ACCOUNT', 'user_id'=>$uid, 'order_id'=>$orderId];
}

function v2raystore_approveRenewAccountPayByHash($hashId, $auto = false){
    global $connection, $botState, $mainValues;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $previousState = 'sent';
    $fail = function($message) use (&$approvalLocked, &$previousState, $hashId){
        if($approvalLocked) v2raystore_restorePayApprovalStateTo($hashId, $previousState);
        v2raystore_setPayApprovalError($hashId, $message);
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
    if(($payInfo['type'] ?? '') !== 'RENEW_ACCOUNT') return $fail('این پرداخت از نوع تمدید سرویس نیست.');
    if(($payInfo['state'] ?? '') === 'approved'){
        $orderId = v2raystore_getRenewOrderIdFromPay($payInfo);
        return ['ok'=>true, 'message'=>'این تمدید قبلاً تأیید شده است.', 'order_ids'=>[$orderId], 'user_id'=>intval($payInfo['user_id'] ?? 0), 'price'=>intval($payInfo['price'] ?? 0), 'already'=>true, 'type'=>'RENEW_ACCOUNT'];
    }
    if(in_array(($payInfo['state'] ?? ''), ['declined','auto_cancelled','cancelled_by_user'], true)) return $fail('این تمدید قبلاً رد یا لغو شده است.');

    $previousState = (string)($payInfo['state'] ?? 'sent');
    $allowed = ['pending','sent','paid_with_wallet','0'];
    if($auto) $allowed[] = 'auto_processing';
    $placeholders = "'" . implode("','", array_map(function($v){ return str_replace("'", "", $v); }, $allowed)) . "'";
    if($auto && $previousState === 'auto_processing'){
        $approvalLocked = true;
    }else{
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'processing' WHERE `hash_id` = ? AND `state` IN ($placeholders)");
        if(!$stmt) return $fail('قفل‌گذاری تمدید ناموفق بود.');
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if($changed <= 0){
            $stmt = $connection->prepare("SELECT `state`, `approval_error` FROM `pays` WHERE `hash_id` = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('s', $hashId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $state = $row['state'] ?? '';
                if(in_array($state, ['processing','auto_processing'], true)){
                    if(trim((string)($row['approval_error'] ?? '')) !== ''){
                        v2raystore_restorePayApprovalStateTo($hashId, $previousState ?: 'sent');
                        return v2raystore_approveRenewAccountPayByHash($hashId, $auto);
                    }
                    return ['ok'=>false, 'message'=>'این تمدید در حال پردازش است؛ چند بار روی تأیید نزنید.'];
                }
                if($state === 'approved') return ['ok'=>true, 'message'=>'این تمدید قبلاً تأیید شده است.', 'already'=>true, 'type'=>'RENEW_ACCOUNT'];
                if(in_array($state, ['declined','auto_cancelled','cancelled_by_user'], true)) return ['ok'=>false, 'message'=>'این تمدید قبلاً رد یا لغو شده است.'];
            }
            return ['ok'=>false, 'message'=>'این تمدید دیگر در وضعیت قابل تأیید نیست.'];
        }
        $approvalLocked = true;
    }

    $uid = intval($payInfo['user_id'] ?? 0);
    $orderId = v2raystore_getRenewOrderIdFromPay($payInfo);
    $now = time();
    $price = intval($payInfo['price'] ?? 0);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('سفارش اصلی پیدا نشد.');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$order) return $fail('سفارش اصلی پیدا نشد.');

    $renewPlanId = v2raystore_getRenewPlanIdFromPay($payInfo, $order);
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1 LIMIT 1");
    if(!$stmt) return $fail('پلن تمدید پیدا نشد.');
    $stmt->bind_param('i', $renewPlanId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$plan) return $fail('پلن تمدید پیدا نشد یا غیرفعال است.');
    if(intval($plan['server_id'] ?? 0) !== intval($order['server_id'] ?? 0)){
        return $fail('پلن انتخاب‌شده مربوط به سرور فعلی این سرویس نیست. برای تمدید، فقط پلن‌های همان سرور قابل استفاده است. اگر می‌خواهید سرور را تغییر دهید، اول تغییر لوکیشن بدهید.');
    }
    $stmt = $connection->prepare("SELECT `active`, `state`, `ucount` FROM `server_info` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('امکان بررسی ظرفیت سرور فعلی وجود ندارد.');
    $currentServerForRenew = intval($order['server_id'] ?? 0);
    $stmt->bind_param('i', $currentServerForRenew);
    $stmt->execute();
    $serverStock = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverStock || intval($serverStock['active'] ?? 0) != 1 || intval($serverStock['state'] ?? 0) != 1 || intval($serverStock['ucount'] ?? 0) <= 0){
        return $fail('ظرفیت سرور فعلی پر است. کاربر باید ابتدا تغییر لوکیشن بدهد و بعد تمدید کند.');
    }

    $remark = (string)($order['remark'] ?? '');
    $uuid = (string)($order['uuid'] ?? '0');
    $server_id = intval($order['server_id'] ?? 0);
    $inbound_id = intval($order['inbound_id'] ?? 0);
    $currentExpire = intval($order['expire_date'] ?? 0);
    $days = intval($plan['days'] ?? 0);
    $volume = floatval($plan['volume'] ?? 0);
    if(intval($payInfo['day'] ?? 0) > 0) $days = intval($payInfo['day']);
    if(floatval($payInfo['volume'] ?? 0) > 0 && intval($payInfo['volume']) != $renewPlanId) $volume = floatval($payInfo['volume']);

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('تنظیمات سرور پیدا نشد.');
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverInfo) return $fail('تنظیمات سرور پیدا نشد.');
    $serverType = $serverInfo['type'] ?? '';

    // Snapshot must be stored before changing panel/database so admin can cancel an automatic renewal safely.
    v2raystore_storeRenewSnapshotOnPay($hashId, $payInfo, $order);

    $renewSettings = v2raystore_getRenewSettings();
    $resetMode = ($renewSettings['mode'] === 'reset');
    $appliedDays = $days;
    $newExpire = $now + ($days * 86400);
    if(!$resetMode){
        $appliedDays = v2raystore_calculateRenewAddDays($currentExpire, $days, $renewSettings['max_days']);
        $baseExpire = max($currentExpire, $now);
        $newExpire = $baseExpire + ($appliedDays * 86400);
        $maxExpire = $now + (intval($renewSettings['max_days']) * 86400);
        if($newExpire > $maxExpire) $newExpire = $maxExpire;
    }

    if($serverType == 'marzban'){
        if($resetMode) $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume'=>$volume, 'force_active'=>true]);
        else $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$appliedDays, 'plus_volume'=>$volume, 'force_active'=>true]);
    }else{
        $editType = $resetMode ? 'renew' : ['force_enable'=>true];
        $response = ($inbound_id > 0) ? editClientTraffic($server_id, $inbound_id, $uuid, $volume, $appliedDays, $editType) : editInboundTraffic($server_id, $uuid, $volume, $appliedDays, $editType);
    }

    if(is_null($response)) return $fail('اتصال به سرور برقرار نشد.');
    if(is_object($response) && isset($response->success) && empty($response->success)){
        $err = $response->msg ?? 'نامشخص';
        if(function_exists('v2raystore_translateTechnicalError')) $err = v2raystore_translateTechnicalError($err);
        return $fail('خطای تمدید روی سرور: ' . $err);
    }

    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ?, `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
    if($stmt){
        $stmt->bind_param('iii', $renewPlanId, $newExpire, $orderId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    if($stmt){
        $stmt->bind_param('iiisii', $uid, $server_id, $inbound_id, $remark, $price, $now);
        $stmt->execute();
        $stmt->close();
    }

    $ordersJson = json_encode([$orderId], JSON_UNESCAPED_UNICODE);
    $autoFlag = $auto ? 1 : 0;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $ordersJson, $hashId); $stmt->execute(); $stmt->close(); }
    $approvalLocked = false;

    $volumeText = rtrim(rtrim(number_format($volume, 2, '.', ''), '0'), '.');
    $daysText = $resetMode ? $days : $appliedDays;
    $renewedOrderForLive = $order;
    $renewedOrderForLive['fileid'] = $renewPlanId;
    $renewedOrderForLive['expire_date'] = $newExpire;
    $liveRemain = function_exists('v2raystore_getOrderRemainingSummary') ? v2raystore_getOrderRemainingSummary($renewedOrderForLive) : null;
    $finalVolumeText = (is_array($liveRemain) && isset($liveRemain['remaining_gb_text']) && $liveRemain['remaining_gb_text'] !== '') ? $liveRemain['remaining_gb_text'] : $volumeText;
    $finalDaysText = (is_array($liveRemain) && isset($liveRemain['remaining_days']) && $liveRemain['remaining_days'] !== '') ? $liveRemain['remaining_days'] : v2raystore_formatRemainingDaysNumber($newExpire);
    sendMessage(str_replace(['REMARK','VOLUME','DAYS'], [$remark, $finalVolumeText, $finalDaysText], $mainValues['renewed_config_to_user'] ?? 'سرویس شما تمدید شد.'), null, 'HTML', $uid);

    $result = [
        'ok'=>true,
        'message'=>'تمدید با موفقیت انجام شد.',
        'order_ids'=>[$orderId],
        'remarks'=>[$remark],
        'renew_remark'=>$remark,
        'user_id'=>$uid,
        'price'=>$price,
        'plan_id'=>$renewPlanId,
        'renew_order_id'=>$orderId,
        'renew_mode'=>$renewSettings['mode'],
        'renew_days'=>$finalDaysText,
        'renew_volume'=>$finalVolumeText,
        'type'=>'RENEW_ACCOUNT',
        'pay_hash'=>$hashId,
        'pay_state_before'=>$previousState
    ];
    if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
    return $result;
}

function v2raystore_approveSentOrderByHash($hashId, $auto = false){
    global $connection, $botState, $mainValues, $admin;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $fail = function($message) use (&$approvalLocked, $hashId){
        if($approvalLocked) v2raystore_restorePayApprovalState($hashId);
        v2raystore_setPayApprovalError($hashId, $message);
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
    if(($payInfo['type'] ?? '') === 'RENEW_ACCOUNT'){
        return v2raystore_approveRenewAccountPayByHash($hashId, $auto);
    }
    if(($payInfo['state'] ?? '') == 'approved'){
        $existingOrders = json_decode($payInfo['auto_approved_orders'] ?? '[]', true) ?: [];
        if(count($existingOrders) == 0) $existingOrders = v2raystore_payLinkedOrderIds($hashId);
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

    $lock = v2raystore_lockPayForApproval($hashId, $auto);
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
        $legacyExpire = time() + (intval($days) * 86400);
        $legacyOrderForLive = ['server_id'=>$server_id, 'inbound_id'=>$renewInbound, 'uuid'=>$uuid, 'remark'=>$remark, 'expire_date'=>$legacyExpire];
        $legacyLiveRemain = function_exists('v2raystore_getOrderRemainingSummary') ? v2raystore_getOrderRemainingSummary($legacyOrderForLive) : null;
        $legacyVolumeText = (is_array($legacyLiveRemain) && isset($legacyLiveRemain['remaining_gb_text']) && $legacyLiveRemain['remaining_gb_text'] !== '') ? $legacyLiveRemain['remaining_gb_text'] : rtrim(rtrim(number_format(floatval($volume), 2, '.', ''), '0'), '.');
        $legacyDaysText = (is_array($legacyLiveRemain) && isset($legacyLiveRemain['remaining_days']) && $legacyLiveRemain['remaining_days'] !== '') ? $legacyLiveRemain['remaining_days'] : v2raystore_formatRemainingDaysNumber($legacyExpire);
        sendMessage(str_replace(['REMARK','VOLUME','DAYS'], [$remark, $legacyVolumeText, $legacyDaysText], $mainValues['renewed_config_to_user'] ?? 'سرویس شما تمدید شد.'), null, 'HTML', $uid);
        $result = ['ok'=>true, 'message'=>'تمدید با موفقیت انجام شد.', 'order_ids'=>[], 'user_id'=>$uid, 'price'=>$price, 'plan_id'=>$fid, 'renew_remark'=>$remark, 'remarks'=>[$remark], 'renew_days'=>$legacyDaysText, 'renew_volume'=>$legacyVolumeText, 'type'=>'RENEW_SCONFIG', 'pay_hash'=>$hashId, 'pay_state_before'=>($payInfo['state'] ?? '')];
        if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
        return $result;
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
    $deliveryFailed = false;
    $deliveryFailedRemarks = [];

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
        if(!is_object($response) || empty($response->success)) return $fail('خطای ساخت کانفیگ: ' . (function_exists('v2raystore_translateTechnicalError') ? v2raystore_translateTechnicalError(is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response) : (is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response)));

        if($serverType == 'marzban'){
            $uniqid = $token = str_replace('/sub/', '', $response->sub_link);
            $subLink = (($botState['subLinkState'] ?? 'off') == 'on') ? $panelUrl . $response->sub_link : '';
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }else{
            $token = RandomString(30);
            $subLink = (($botState['subLinkState'] ?? 'off') == 'on') ? v2raystore_makeCustomerSubLink($server_id, $token, $uniqid, $inbound_id, $remark) : '';
            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
            $vray_link = json_encode($vraylink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $sendOk = v2raystore_sendConfigLinksToUser($uid, $remark, $protocol, $volume, $days, $vraylink, $subLink, $serverType);
        if(!$sendOk){
            @usleep(300000);
            $sendOk = v2raystore_sendConfigLinksToUser($uid, $remark, $protocol, $volume, $days, $vraylink, $subLink, $serverType);
        }
        if(!$sendOk){
            $deliveryFailed = true;
            $deliveryFailedRemarks[] = $remark;
        }

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

    if($deliveryFailed){
        $failedNames = implode(', ', array_map('strval', $deliveryFailedRemarks));
        $deliveryMsg = 'سرویس ساخته و ثبت شد اما ارسال پیام کانفیگ به کاربر از سمت تلگرام/شبکه ناموفق بود. کاربر می‌تواند از «کانفیگ‌های من» دریافت کند. ریمارک‌ها: ' . $failedNames;
        if(function_exists('v2raystore_setPayApprovalError')) v2raystore_setPayApprovalError($hashId, $deliveryMsg);
        if(function_exists('v2raystore_reportEvent')){
            v2raystore_reportEvent('⚠️ خطای ارسال کانفیگ به کاربر', "🆔 کاربر: <code>{$uid}</code>
🧾 پرداخت: <code>" . v2raystore_h($hashId) . "</code>
📝 " . v2raystore_h($deliveryMsg), v2raystore_reportPrivateKeyboard($uid), 'approval_failed');
        }
    }

    // پیام خلاصه «کانفیگ برای کاربر ارسال شد» حذف شد؛ کانفیگ اصلی قبلاً برای کاربر ارسال می‌شود.
    $result = ['ok'=>true, 'message'=>($deliveryFailed ? 'سفارش تأیید شد اما ارسال کانفیگ به کاربر نیاز به بررسی دارد.' : 'سفارش با موفقیت تأیید شد.'), 'order_ids'=>$orderIds, 'remarks'=>$remarks, 'user_id'=>$uid, 'price'=>$price, 'plan_id'=>$fid, 'type'=>($payInfo['type'] ?? 'BUY_SUB'), 'pay_hash'=>$hashId, 'pay_state_before'=>($payInfo['state'] ?? ''), 'delivery_failed'=>$deliveryFailed];
    if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
    return $result;
}

function v2raystore_approveIncreaseVolumePayByHash($hashId, $auto = false){
    global $connection, $mainValues;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $fail = function($message) use (&$approvalLocked, $hashId){
        if($approvalLocked) v2raystore_restorePayApprovalState($hashId);
        v2raystore_setPayApprovalError($hashId, $message);
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

    $type = (string)($payInfo['type'] ?? '');
    if(!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)$/', $type, $increaseInfo)){
        return $fail('این پرداخت از نوع افزایش حجم نیست.');
    }

    if(($payInfo['state'] ?? '') == 'approved'){
        $orderId = intval($increaseInfo[1]);
        return ['ok'=>true, 'message'=>'این افزایش حجم قبلاً تأیید شده است.', 'order_ids'=>[$orderId], 'user_id'=>intval($payInfo['user_id'] ?? 0), 'price'=>intval($payInfo['price'] ?? 0), 'already'=>true];
    }
    if(in_array(($payInfo['state'] ?? ''), ['declined','auto_cancelled'], true)) return $fail('این سفارش قبلاً رد یا لغو شده است.');

    $lock = v2raystore_lockPayForApproval($hashId, $auto);
    if(empty($lock['ok'])) return $lock;
    $approvalLocked = true;

    $uid = intval($payInfo['user_id'] ?? 0);
    $orderId = intval($increaseInfo[1]);
    $planId = intval($increaseInfo[2]);
    $now = time();
    $price = intval($payInfo['price'] ?? 0);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('سفارش اصلی پیدا نشد.');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$orderInfo) return $fail('سفارش اصلی پیدا نشد.');

    $server_id = intval($orderInfo['server_id'] ?? 0);
    $inbound_id = intval($orderInfo['inbound_id'] ?? 0);
    $remark = (string)($orderInfo['remark'] ?? '');
    $uuid = (string)($orderInfo['uuid'] ?? '0');
    $basePlanId = intval($orderInfo['fileid'] ?? 0);

    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('پلن افزایش حجم پیدا نشد.');
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $incPlan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$incPlan) return $fail('پلن افزایش حجم پیدا نشد.');

    $volume = floatval($incPlan['volume'] ?? 0);
    if($volume <= 0) return $fail('حجم پلن افزایش حجم نامعتبر است.');

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('تنظیمات سرور پیدا نشد.');
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverInfo) return $fail('تنظیمات سرور پیدا نشد.');
    $serverType = $serverInfo['type'] ?? '';

    if($serverType == 'marzban'){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
    }else{
        $response = ($inbound_id > 0) ? editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0) : editInboundTraffic($server_id, $uuid, $volume, 0);
    }

    if(is_null($response)) return $fail('اتصال به سرور برقرار نشد.');
    if(!is_object($response) || empty($response->success)){
        $err = is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response;
        if(function_exists('v2raystore_translateTechnicalError')) $err = v2raystore_translateTechnicalError($err);
        return $fail('خطای افزایش حجم روی سرور: ' . $err);
    }

    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
    if($stmt){ $stmt->bind_param('s', $uuid); $stmt->execute(); $stmt->close(); }

    $ordersJson = json_encode([$orderId], JSON_UNESCAPED_UNICODE);
    $autoFlag = $auto ? 1 : 0;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $ordersJson, $hashId); $stmt->execute(); $stmt->close(); }
    $approvalLocked = false;

    $volumeText = rtrim(rtrim(number_format($volume, 2, '.', ''), '0'), '.');
    sendMessage("✅{$volumeText} گیگ به حجم سرویس شما اضافه شد", null, 'HTML', $uid);

    $result = [
        'ok'=>true,
        'message'=>'افزایش حجم با موفقیت تأیید شد.',
        'order_ids'=>[$orderId],
        'remarks'=>[$remark],
        'renew_remark'=>$remark,
        'user_id'=>$uid,
        'price'=>$price,
        'plan_id'=>$basePlanId,
        'increase_volume'=>$volumeText,
        'type'=>'INCREASE_VOLUME',
        'pay_hash'=>$hashId,
        'pay_state_before'=>($payInfo['state'] ?? '')
    ];
    if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
    return $result;
}


function v2raystore_approveIncreaseWalletPayByHash($hashId, $auto = false){
    global $connection;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $fail = function($message) use (&$approvalLocked, $hashId){
        if($approvalLocked) v2raystore_restorePayApprovalState($hashId);
        v2raystore_setPayApprovalError($hashId, $message);
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
    if(($payInfo['type'] ?? '') !== 'INCREASE_WALLET') return $fail('نوع پرداخت شارژ کیف پول نیست.');
    if(($payInfo['state'] ?? '') == 'approved') return ['ok'=>true, 'message'=>'این شارژ قبلاً تأیید شده است.', 'order_ids'=>[], 'user_id'=>intval($payInfo['user_id'] ?? 0), 'price'=>intval($payInfo['price'] ?? 0), 'wallet_amount'=>intval($payInfo['price'] ?? 0), 'already'=>true, 'type'=>'INCREASE_WALLET'];
    if(in_array(($payInfo['state'] ?? ''), ['declined','auto_cancelled'], true)) return $fail('این پرداخت قبلاً رد یا لغو شده است.');

    $lock = v2raystore_lockPayForApproval($hashId, $auto);
    if(empty($lock['ok'])) return $lock;
    $approvalLocked = true;

    $uid = intval($payInfo['user_id'] ?? 0);
    $price = intval($payInfo['price'] ?? 0);
    if($uid <= 0) return $fail('کاربر پرداخت معتبر نیست.');
    if($price <= 0) return $fail('مبلغ شارژ کیف پول معتبر نیست.');

    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
    if(!$stmt) return $fail('افزایش کیف پول در دیتابیس ناموفق بود.');
    $stmt->bind_param('ii', $price, $uid);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if($changed <= 0) return $fail('کاربر برای افزایش کیف پول پیدا نشد.');

    $now = time();
    $autoFlag = $auto ? 1 : 0;
    $emptyOrders = json_encode([], JSON_UNESCAPED_UNICODE);
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $emptyOrders, $hashId); $stmt->execute(); $stmt->close(); }
    $approvalLocked = false;

    sendMessage("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price) . " تومان به حساب شما اضافه شد", null, null, $uid);

    $result = [
        'ok'=>true,
        'message'=>'شارژ کیف پول با موفقیت تأیید شد.',
        'order_ids'=>[],
        'user_id'=>$uid,
        'price'=>$price,
        'wallet_amount'=>$price,
        'type'=>'INCREASE_WALLET',
        'pay_hash'=>$hashId,
        'pay_state_before'=>($payInfo['state'] ?? '')
    ];
    if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
    return $result;
}

function v2raystore_approveIncreaseDayPayByHash($hashId, $auto = false){
    global $connection;
    $hashId = trim((string)$hashId);
    $approvalLocked = false;
    $fail = function($message) use (&$approvalLocked, $hashId){
        if($approvalLocked) v2raystore_restorePayApprovalState($hashId);
        v2raystore_setPayApprovalError($hashId, $message);
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
    $type = (string)($payInfo['type'] ?? '');
    if(!preg_match('/^INCREASE_DAY_(\d+)_(\d+)$/', $type, $increaseInfo)) return $fail('نوع پرداخت افزایش زمان نیست.');
    if(($payInfo['state'] ?? '') == 'approved') return ['ok'=>true, 'message'=>'این افزایش زمان قبلاً تأیید شده است.', 'order_ids'=>[intval($increaseInfo[1])], 'user_id'=>intval($payInfo['user_id'] ?? 0), 'price'=>intval($payInfo['price'] ?? 0), 'already'=>true, 'type'=>'INCREASE_DAY'];
    if(in_array(($payInfo['state'] ?? ''), ['declined','auto_cancelled'], true)) return $fail('این سفارش قبلاً رد یا لغو شده است.');

    $lock = v2raystore_lockPayForApproval($hashId, $auto);
    if(empty($lock['ok'])) return $lock;
    $approvalLocked = true;

    $orderId = intval($increaseInfo[1]);
    $planId = intval($increaseInfo[2]);
    $uid = intval($payInfo['user_id'] ?? 0);
    $price = intval($payInfo['price'] ?? 0);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('سفارش اصلی پیدا نشد.');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$orderInfo) return $fail('سفارش اصلی پیدا نشد.');

    $server_id = intval($orderInfo['server_id']);
    $inbound_id = intval($orderInfo['inbound_id']);
    $remark = (string)($orderInfo['remark'] ?? '');
    $uuid = $orderInfo['uuid'] ?? '0';

    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('پلن افزایش زمان پیدا نشد.');
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $incPlan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$incPlan) return $fail('پلن افزایش زمان پیدا نشد.');

    $days = intval($incPlan['volume'] ?? 0);
    if($days <= 0) return $fail('مدت پلن افزایش زمان نامعتبر است.');

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return $fail('تنظیمات سرور پیدا نشد.');
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverInfo) return $fail('تنظیمات سرور پیدا نشد.');
    $serverType = $serverInfo['type'] ?? '';

    if($serverType == 'marzban'){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$days]);
    }else{
        $response = ($inbound_id > 0) ? editClientTraffic($server_id, $inbound_id, $uuid, 0, $days) : editInboundTraffic($server_id, $uuid, 0, $days);
    }

    if(is_null($response)) return $fail('اتصال به سرور برقرار نشد.');
    if(!is_object($response) || empty($response->success)){
        $err = is_object($response) ? ($response->msg ?? 'نامشخص') : (string)$response;
        if(function_exists('v2raystore_translateTechnicalError')) $err = v2raystore_translateTechnicalError($err);
        return $fail('خطای افزایش زمان روی سرور: ' . $err);
    }

    $addSeconds = $days * 86400;
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
    if($stmt){ $stmt->bind_param('is', $addSeconds, $uuid); $stmt->execute(); $stmt->close(); }

    $now = time();
    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    if($stmt){ $stmt->bind_param('iiisii', $uid, $server_id, $inbound_id, $remark, $price, $now); $stmt->execute(); $stmt->close(); }

    $ordersJson = json_encode([$orderId], JSON_UNESCAPED_UNICODE);
    $autoFlag = $auto ? 1 : 0;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `auto_approved` = ?, `auto_approved_date` = ?, `auto_approved_orders` = ?, `approval_error` = NULL, `approval_error_date` = 0 WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('iiss', $autoFlag, $now, $ordersJson, $hashId); $stmt->execute(); $stmt->close(); }
    $approvalLocked = false;

    sendMessage("✅{$days} روز به مدت زمان سرویس شما اضافه شد", null, null, $uid);

    $result = [
        'ok'=>true,
        'message'=>'افزایش زمان با موفقیت تأیید شد.',
        'order_ids'=>[$orderId],
        'remarks'=>[$remark],
        'renew_remark'=>$remark,
        'user_id'=>$uid,
        'price'=>$price,
        'plan_id'=>intval($orderInfo['fileid'] ?? 0),
        'increase_day'=>$days,
        'type'=>'INCREASE_DAY',
        'pay_hash'=>$hashId,
        'pay_state_before'=>($payInfo['state'] ?? '')
    ];
    if(function_exists('v2raystore_notifyPaymentCompletedFullReport')) $result['report_sent'] = v2raystore_notifyPaymentCompletedFullReport($hashId, $result, $auto);
    return $result;
}

function v2raystore_recoverStuckAutoProcessingOrders($olderThanSeconds = 300){
    global $connection;
    $olderThanSeconds = max(120, intval($olderThanSeconds));
    $recentProcessingCutoff = time() - $olderThanSeconds;
    $legacyStuckCutoff = time() - max(900, $olderThanSeconds * 3);
    $stmt = @$connection->prepare("UPDATE `pays` SET `state` = 'sent', `approval_error` = NULL, `approval_error_date` = 0 WHERE `state` = 'auto_processing' AND COALESCE(`auto_approved`,0) = 0 AND ((COALESCE(`approval_error_date`,0) > 0 AND `approval_error_date` <= ?) OR (COALESCE(`approval_error_date`,0) = 0 AND COALESCE(NULLIF(`sent_date`,0), `request_date`, 0) <= ?))");
    if(!$stmt) return 0;
    $stmt->bind_param('ii', $recentProcessingCutoff, $legacyStuckCutoff);
    $stmt->execute();
    $changed = intval($stmt->affected_rows);
    $stmt->close();
    return max(0, $changed);
}

function v2raystore_processAutoApproveOrders($force = false, $limit = 3){
    global $connection, $botState;
    $state = v2raystore_getAutoApproveState();
    $minutesForRecover = max(1, intval($state['minutes'] ?? 1));
    if(function_exists('v2raystore_recoverStuckAutoProcessingOrders')) v2raystore_recoverStuckAutoProcessingOrders(max(90, min(300, ($minutesForRecover * 60) + 30)));
    if(!$force && !$state['enabled']) return ['processed'=>0, 'messages'=>[]];
    $minutes = intval($state['minutes']);
    if($minutes < 1) $minutes = 1;
    $cutoff = $force ? time() : (time() - ($minutes * 60));
    $limit = max(1, min(10, intval($limit)));
    $blockedUsers = function_exists('v2raystore_getAutoApproveBlockedUsers') ? v2raystore_getAutoApproveBlockedUsers() : [];
    $blockedSql = '';
    if(count($blockedUsers) > 0){
        $blockedUsers = array_map('intval', $blockedUsers);
        $blockedSql = " AND `user_id` NOT IN (" . implode(',', $blockedUsers) . ")";
    }
    $newOnlySql = '';
    if(!$force && function_exists('v2raystore_getAutoApproveExactStartAt')){
        $autoApproveStartAt = intval(v2raystore_getAutoApproveExactStartAt());
        if($autoApproveStartAt > 0) $newOnlySql = " AND COALESCE(NULLIF(`sent_date`,0), `request_date`, 0) >= " . $autoApproveStartAt;
    }
    $typeSql = function_exists('v2raystore_getAutoApproveEnabledSqlCondition') ? v2raystore_getAutoApproveEnabledSqlCondition() : "(`type` IN ('BUY_SUB','RENEW_SCONFIG') OR `type` LIKE 'INCREASE_VOLUME_%')";
    if(trim((string)$typeSql) === '') return ['processed'=>0, 'messages'=>['هیچ موردی برای تأیید خودکار روشن نیست.']];
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'sent' AND {$typeSql}{$blockedSql}{$newOnlySql} AND COALESCE(NULLIF(`sent_date`,0), `request_date`, 0) > 0 AND COALESCE(NULLIF(`sent_date`,0), `request_date`, 0) <= ? ORDER BY COALESCE(NULLIF(`sent_date`,0), `request_date`) ASC LIMIT $limit");
    if(!$stmt) return ['processed'=>0, 'messages'=>['خطا در دریافت سفارش‌های در انتظار.']];
    $stmt->bind_param('i', $cutoff);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    $processed = 0;
    $messages = [];
    while($pay = $rows->fetch_assoc()){
        $hash = $pay['hash_id'];
        $processingStartedAt = time();
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'auto_processing', `approval_error` = NULL, `approval_error_date` = ? WHERE `hash_id` = ? AND `state` = 'sent'");
        if(!$stmt) continue;
        $stmt->bind_param('is', $processingStartedAt, $hash);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if($changed <= 0) continue;

        $payType = (string)($pay['type'] ?? '');
        if(function_exists('v2raystore_isAutoApproveTypeEnabled') && !v2raystore_isAutoApproveTypeEnabled($payType)){
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ? AND `state` = 'auto_processing'");
            if($stmt){ $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close(); }
            continue;
        }
        if($payType === 'INCREASE_WALLET' && function_exists('v2raystore_approveIncreaseWalletPayByHash')){
            $result = v2raystore_approveIncreaseWalletPayByHash($hash, true);
        }elseif(preg_match('/^INCREASE_DAY_/', $payType) && function_exists('v2raystore_approveIncreaseDayPayByHash')){
            $result = v2raystore_approveIncreaseDayPayByHash($hash, true);
        }elseif(preg_match('/^INCREASE_VOLUME_/', $payType) && function_exists('v2raystore_approveIncreaseVolumePayByHash')){
            $result = v2raystore_approveIncreaseVolumePayByHash($hash, true);
        }else{
            $result = v2raystore_approveSentOrderByHash($hash, true);
        }
        if($result['ok']){
            $processed++;
            $uid = intval($result['user_id'] ?? $pay['user_id']);
            $orders = $result['order_ids'] ?? [];
            $ordersText = count($orders) ? implode(', ', array_map('intval', $orders)) : 'ثبت نشده';
            $statusText = v2raystore_approvalStatusTextFromResult($result, true);
            $copyText = function_exists('v2raystore_approvalCopyTextFromResult') ? v2raystore_approvalCopyTextFromResult($result) : '';
            v2raystore_updateAdminPayMessageStatus($hash, $statusText, 'success', $uid, $copyText);

            if(empty($result['report_sent'])){
                $lines = ["✅ <b>سفارش به‌صورت خودکار تأیید شد</b>"];
                if(v2raystore_reportDetailEnabled('user_info', 'on')) $lines[] = "🆔 کاربر: <code>{$uid}</code>";
                if(v2raystore_reportDetailEnabled('plan_info', 'on') && function_exists('v2raystore_reportPlanServerLinesByPlanId')){
                    foreach(v2raystore_reportPlanServerLinesByPlanId($result['plan_id'] ?? ($pay['plan_id'] ?? 0), $pay['volume'] ?? '', $pay['day'] ?? '') as $reportLine){
                        $lines[] = $reportLine;
                    }
                }
                if(!empty($result['wallet_amount'])) $lines[] = "💰 شارژ کیف پول: <b>" . number_format(intval($result['wallet_amount'])) . " تومان</b>";
                if(!empty($result['increase_volume'])) $lines[] = "🔋 افزایش حجم: <b>" . v2raystore_h($result['increase_volume']) . " گیگ</b>";
                if(!empty($result['increase_day'])) $lines[] = "⏰ افزایش زمان: <b>" . v2raystore_h($result['increase_day']) . " روز</b>";
                if(v2raystore_reportDetailEnabled('amount', 'on')) $lines[] = "💰 مبلغ: <b>" . number_format(intval($result['price'] ?? $pay['price'])) . " تومان</b>";
                // کد پرداخت در گزارش کانال نمایش داده نمی‌شود؛ عملیات داخلی همچنان با hash انجام می‌شود.
                $configNamesLine = v2raystore_approvalConfigNamesLineFromResult($result);
                if($configNamesLine !== '') $lines[] = $configNamesLine;
                if(v2raystore_reportDetailEnabled('order_ids', 'on')) $lines[] = "🧾 سفارش‌های مرتبط: <code>" . v2raystore_h($ordersText) . "</code>";
                $noCancelAuto = in_array(($result['type'] ?? ''), ['INCREASE_VOLUME','INCREASE_DAY','INCREASE_WALLET'], true) || preg_match('/^INCREASE_(VOLUME|DAY)_/', (string)($pay['type'] ?? ''));
                if(!$noCancelAuto && v2raystore_reportDetailEnabled('cancel_button', 'on')) $lines[] = "در صورت نیاز می‌توانید از همین پیام سفارش را کامل لغو کنید و دلیل لغو برای کاربر ارسال می‌شود.";
                $body = implode("\n", $lines) . v2raystore_reportTimeLine();
                $reportKeys = $noCancelAuto ? v2raystore_reportPrivateKeyboard($uid) : v2raystore_autoOrderActionKeyboard($hash, $uid);
                v2raystore_reportEvent('🤖 تأیید خودکار سفارش', $body, $reportKeys, 'auto_approved');
            }
            $messages[] = "✅ $hash تأیید شد.";
        }else{
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ? AND `state` = 'auto_processing'");
            if($stmt){ $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close(); }
            $uid = intval($pay['user_id'] ?? 0);
            $lines = ["⚠️ <b>تأیید خودکار انجام نشد</b>"];
            if(v2raystore_reportDetailEnabled('user_info', 'on')) $lines[] = "🆔 کاربر: <code>{$uid}</code>";
            if(v2raystore_reportDetailEnabled('plan_info', 'on') && function_exists('v2raystore_reportPlanServerLinesByPlanId')){
                foreach(v2raystore_reportPlanServerLinesByPlanId($pay['plan_id'] ?? 0, $pay['volume'] ?? '', $pay['day'] ?? '') as $reportLine){
                    $lines[] = $reportLine;
                }
            }
            $failPayType = (string)($pay['type'] ?? '');
            if($failPayType === 'INCREASE_WALLET'){
                $lines[] = "💰 شارژ کیف پول: <b>" . number_format(intval($pay['price'] ?? 0)) . " تومان</b>";
            }elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $failPayType, $ivm)){
                $stmt = $connection->prepare("SELECT `volume` FROM `increase_plan` WHERE `id` = ? LIMIT 1");
                if($stmt){
                    $incPlanId = intval($ivm[2]);
                    $stmt->bind_param('i', $incPlanId);
                    $stmt->execute();
                    $inc = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if($inc && floatval($inc['volume'] ?? 0) > 0) $lines[] = "🔋 افزایش حجم: <b>" . v2raystore_h($inc['volume']) . " گیگ</b>";
                }
            }elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $failPayType, $idm)){
                $stmt = $connection->prepare("SELECT `volume` FROM `increase_day` WHERE `id` = ? LIMIT 1");
                if($stmt){
                    $incPlanId = intval($idm[2]);
                    $stmt->bind_param('i', $incPlanId);
                    $stmt->execute();
                    $inc = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if($inc && intval($inc['volume'] ?? 0) > 0) $lines[] = "⏰ افزایش زمان: <b>" . v2raystore_h($inc['volume']) . " روز</b>";
                }
            }
            // کد پرداخت در گزارش خطای کانال نمایش داده نمی‌شود.
            $lines[] = "📝 خطا: <b>" . v2raystore_h($result['message']) . "</b>";
            $lines[] = "بعد از اصلاح مشکل، همان دکمه تأیید سفارش دوباره قابل استفاده است.";
            v2raystore_reportEvent('⚠️ خطای تأیید خودکار', implode("\n", $lines) . v2raystore_reportTimeLine(), v2raystore_reportPrivateKeyboard($uid), 'approval_failed');
            $messages[] = "❌ $hash: " . $result['message'];
        }

    }
    return ['processed'=>$processed, 'messages'=>$messages];
}


function v2raystore_panelDeleteResponseOk($response){
    if($response === null || $response === false) return false;
    if(is_object($response)){
        if(isset($response->success) && empty($response->success)) return false;
        if(isset($response->detail) || isset($response->error)) return false;
        return true;
    }
    if(is_array($response)){
        if(isset($response['success']) && empty($response['success'])) return false;
        if(isset($response['detail']) || isset($response['error'])) return false;
        return true;
    }
    return trim((string)$response) !== '';
}

function v2raystore_panelDeleteErrorText($response){
    if(is_object($response)){
        $msg = $response->msg ?? ($response->detail ?? ($response->error ?? 'خطای نامشخص پنل'));
        if(is_array($msg) || is_object($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (string)$msg;
    }
    if(is_array($response)){
        $msg = $response['msg'] ?? ($response['detail'] ?? ($response['error'] ?? 'خطای نامشخص پنل'));
        if(is_array($msg) || is_object($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (string)$msg;
    }
    if($response === null || $response === false) return 'اتصال به پنل یا حذف سرویس ناموفق بود.';
    return (string)$response;
}

function v2raystore_orderServiceStillExistsOnPanel($order, $serverType = ''){
    if(!is_array($order)) return false;
    $serverId = intval($order['server_id'] ?? 0);
    $inboundId = intval($order['inbound_id'] ?? 0);
    $uuid = (string)($order['uuid'] ?? '');
    $remark = (string)($order['remark'] ?? '');
    if($serverId <= 0) return false;

    if($serverType === 'marzban'){
        if($remark === '') return false;
        $info = getMarzbanUserInfo($serverId, $remark);
        if(!$info) return false;
        if(is_object($info) && (isset($info->detail) || (isset($info->success) && empty($info->success)))) return false;
        if(is_array($info) && (isset($info['detail']) || (isset($info['success']) && empty($info['success'])))) return false;
        return true;
    }

    if($uuid === '' || $uuid === '0') return false;
    $response = getJson($serverId);
    if(!$response || !isset($response->obj) || !is_array($response->obj)) return null;
    foreach($response->obj as $row){
        if($inboundId > 0 && intval($row->id ?? 0) !== $inboundId) continue;
        $settings = json_decode($row->settings ?? '');
        $clients = (is_object($settings) && isset($settings->clients) && is_array($settings->clients)) ? $settings->clients : [];
        foreach($clients as $client){
            $cid = (string)($client->id ?? '');
            $cpass = (string)($client->password ?? '');
            $cemail = (string)($client->email ?? '');
            if($cid === $uuid || $cpass === $uuid || ($remark !== '' && $cemail === $remark)) return true;
        }
        if($inboundId <= 0){
            $rowRemark = (string)($row->remark ?? '');
            if($remark !== '' && $rowRemark === $remark) return true;
        }
    }
    return false;
}

function v2raystore_deleteOrderServiceFromPanel($order){
    global $connection;
    if(!is_array($order)) return ['ok'=>false, 'message'=>'اطلاعات سفارش برای حذف از پنل پیدا نشد.'];
    $serverId = intval($order['server_id'] ?? 0);
    $inboundId = intval($order['inbound_id'] ?? 0);
    $uuid = (string)($order['uuid'] ?? '');
    $remark = (string)($order['remark'] ?? '');
    if($serverId <= 0) return ['ok'=>false, 'message'=>'سرور سفارش نامعتبر است.'];

    $serverType = '';
    $stmt = $connection->prepare("SELECT `type` FROM `server_config` WHERE `id` = ? LIMIT 1");
    if($stmt){
        $stmt->bind_param('i', $serverId);
        $stmt->execute();
        $server = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverType = (string)($server['type'] ?? '');
    }

    if($serverType === 'marzban'){
        if($remark === '') return ['ok'=>false, 'message'=>'شناسه مرزبان سفارش برای حذف از پنل خالی است.'];
        $response = deleteMarzban($serverId, $remark);
    }else{
        if($uuid === '' || $uuid === '0') return ['ok'=>false, 'message'=>'UUID سرویس برای حذف از پنل خالی است.'];
        $response = ($inboundId > 0) ? deleteClient($serverId, $inboundId, $uuid, 1) : deleteInbound($serverId, $uuid, 1);
    }

    if(!v2raystore_panelDeleteResponseOk($response)){
        $stillExists = v2raystore_orderServiceStillExistsOnPanel($order, $serverType);
        if($stillExists === false) return ['ok'=>true, 'message'=>'سرویس از قبل روی پنل وجود نداشت یا حذف شده بود.'];
        return ['ok'=>false, 'message'=>'حذف سرویس از پنل ناموفق بود: ' . v2raystore_panelDeleteErrorText($response)];
    }

    $stillExists = v2raystore_orderServiceStillExistsOnPanel($order, $serverType);
    if($stillExists === true) return ['ok'=>true, 'message'=>'درخواست حذف به پنل ارسال شد؛ سفارش لغو می‌شود. اگر پنل لیست را کش کرده باشد، ممکن است چند لحظه دیرتر حذف را نشان دهد.'];
    if($stillExists === null) return ['ok'=>true, 'message'=>'درخواست حذف به پنل ارسال شد؛ بررسی نهایی پنل ممکن نبود.'];
    return ['ok'=>true, 'message'=>'سرویس از پنل حذف شد.'];
}

function v2raystore_deleteOrderCompletely($orderId, $reason = ''){
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

    // اول سرویس از پنل حذف می‌شود. اگر حذف پنل ناموفق باشد، سفارش از دیتابیس حذف نمی‌شود تا کانفیگ یتیم باقی نماند.
    $panelDelete = v2raystore_deleteOrderServiceFromPanel($order);
    if(empty($panelDelete['ok'])) return $panelDelete;

    $server_id = intval($order['server_id']);
    $inbound_id = intval($order['inbound_id']);
    $fileid = intval($order['fileid']);

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
    if(!$stmt) return ['ok'=>false, 'message'=>'حذف سفارش از دیتابیس ناموفق بود؛ اما درخواست حذف پنل انجام شده است.'];
    $stmt->bind_param('i', $orderId);
    $ok = $stmt->execute();
    $stmt->close();
    if(!$ok) return ['ok'=>false, 'message'=>'حذف سفارش از دیتابیس ناموفق بود؛ اما درخواست حذف پنل انجام شده است.'];
    return ['ok'=>true, 'message'=>'سفارش و سرویس پنل حذف شدند.', 'order'=>$order, 'panel_message'=>$panelDelete['message'] ?? ''];
}

function v2raystore_cancelAutoApprovedPay($hashId, $reason){
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
    if(in_array((string)($pay['type'] ?? ''), ['RENEW_ACCOUNT','RENEW_SCONFIG'], true)){
        return v2raystore_cancelApprovedRenewPay($pay, $reason);
    }
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
        $r = v2raystore_deleteOrderCompletely($oid, $reason);
        if(empty($r['ok'])){
            return ['ok'=>false, 'message'=>'لغو کامل انجام نشد: ' . ($r['message'] ?? 'خطای نامشخص در حذف سفارش از پنل')];
        }
        $deleted++;
    }
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'auto_cancelled', `cancel_reason` = ? WHERE `hash_id` = ?");
    if($stmt){ $stmt->bind_param('ss', $reason, $hashId); $stmt->execute(); $stmt->close(); }
    $uid = intval($pay['user_id']);
    sendMessage("❌ سفارش شما توسط مدیریت لغو شد.\n\n📝 دلیل لغو:\n" . $reason, null, 'HTML', $uid);
    return ['ok'=>true, 'message'=>"$deleted سفارش حذف شد.", 'deleted'=>$deleted, 'user_id'=>$uid];
}
// ===== End V2Ray Store extra realtime reports + auto order approval =====

?>
