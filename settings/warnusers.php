<?php
include_once '../baseInfo.php';
include_once '../config.php';
$time = time();

if(file_exists("warnOffset.txt")) $warnOffset = intval(file_get_contents("warnOffset.txt"));
else $warnOffset = 0;
$limit = 50;

function v2raystore_warn_array_value($obj, $key, $default = null){
    if(function_exists('v2raystore_arrayValue')) return v2raystore_arrayValue($obj, $key, $default);
    if(is_array($obj) && array_key_exists($key, $obj)) return $obj[$key];
    if(is_object($obj) && isset($obj->$key)) return $obj->$key;
    return $default;
}

function v2raystore_warn_decode($value){
    if(function_exists('v2raystore_decodeMaybeJson')) return v2raystore_decodeMaybeJson($value, true);
    if(is_array($value)) return $value;
    if(is_object($value)) return json_decode(json_encode($value), true);
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function v2raystore_warn_expiry_seconds($value){
    if(function_exists('v2raystore_panelExpiryToSeconds')) return v2raystore_panelExpiryToSeconds($value);
    if($value === null) return 0;
    if(is_string($value)){
        $value = trim($value);
        if($value === '' || $value === '0') return 0;
        $value = preg_replace('/[^0-9\-]/', '', $value);
        if($value === '' || $value === '-' || $value === '0') return 0;
    }
    $v = intval($value);
    if($v <= 0) return 0;
    if($v > 9999999999) $v = intval($v / 1000);
    return $v;
}

function v2raystore_warn_client_identity($client){
    if(function_exists('v2raystore_panelClientIdentity')) return v2raystore_panelClientIdentity($client);
    $id = (string)v2raystore_warn_array_value($client, 'id', '');
    if($id === '') $id = (string)v2raystore_warn_array_value($client, 'uuid', '');
    if($id === '') $id = (string)v2raystore_warn_array_value($client, 'password', '');
    return $id;
}

function v2raystore_warn_client_email($client){
    if(function_exists('v2raystore_panelClientEmail')) return v2raystore_panelClientEmail($client);
    return trim((string)v2raystore_warn_array_value($client, 'email', ''));
}

function v2raystore_warn_find_stat($stats, $email){
    if(function_exists('v2raystore_panelFindClientStat')) return v2raystore_panelFindClientStat($stats, $email);
    $email = trim((string)$email);
    if($email === '') return null;
    if(is_object($stats)) $stats = [$stats];
    if(!is_array($stats)) return null;
    foreach($stats as $stat){
        $statEmail = trim((string)v2raystore_warn_array_value($stat, 'email', ''));
        if($statEmail !== '' && $statEmail === $email) return $stat;
    }
    return null;
}

function v2raystore_warn_rows_from_getjson($json){
    if(function_exists('v2raystore_panelListFromGetJson')) return v2raystore_panelListFromGetJson($json);
    if(!$json || !isset($json->obj)) return [];
    $rows = $json->obj;
    if(is_object($rows)) $rows = [$rows];
    return is_array($rows) ? $rows : [];
}

function v2raystore_warn_order_state($order){
    global $connection;

    if(!is_array($order)) return ['found'=>false, 'logged_in'=>false];

    // مهم: قبل از تصمیم برای هشدار یا حذف، تاریخ واقعی پنل را در دیتابیس ربات sync می‌کنیم.
    if(function_exists('v2raystore_syncOrderExpiryFromPanel')){
        $sync = v2raystore_syncOrderExpiryFromPanel($order, true);
        if(is_array($sync) && !empty($sync['found']) && intval($sync['expire_date'] ?? 0) > 0){
            $order['expire_date'] = intval($sync['expire_date']);
        }
    }

    $server_id = intval($order['server_id'] ?? 0);
    $inbound_id = intval($order['inbound_id'] ?? 0);
    $uuid = trim((string)($order['uuid'] ?? ''));
    $remark = trim((string)($order['remark'] ?? ''));

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ? LIMIT 1");
    if(!$stmt) return ['found'=>false, 'logged_in'=>false];
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$serverConfig) return ['found'=>false, 'logged_in'=>false];

    $serverType = $serverConfig['type'] ?? '';
    $state = [
        'found' => false,
        'logged_in' => false,
        'server_type' => $serverType,
        'total' => 0,
        'up' => 0,
        'down' => 0,
        'total_left' => 0,
        'expiry_time' => 0,
        'enable' => true,
    ];

    if($serverType == "marzban"){
        $info = getMarzbanUser($server_id, $remark);
        if(isset($info->username)){
            $state['found'] = true;
            $state['logged_in'] = true;
            $state['total'] = intval($info->data_limit ?? 0);
            $used = intval($info->used_traffic ?? 0);
            $state['up'] = $used;
            $state['down'] = 0;
            $state['total_left'] = $state['total'] > 0 ? ($state['total'] - $used) : PHP_INT_MAX;
            $state['expiry_time'] = v2raystore_warn_expiry_seconds($info->expire ?? 0);
            $state['enable'] = (($info->status ?? '') == "active");
        }elseif(isset($info->detail) && $info->detail == "User not found"){
            $state['logged_in'] = true;
        }
        return $state;
    }

    $response = getJson($server_id);
    if(!$response || !isset($response->success) || !$response->success){
        return $state;
    }

    $state['logged_in'] = true;
    $rows = v2raystore_warn_rows_from_getjson($response);

    foreach($rows as $row){
        $rowId = intval(v2raystore_warn_array_value($row, 'id', 0));
        if($inbound_id > 0 && $rowId !== $inbound_id) continue;

        $settings = v2raystore_warn_decode(v2raystore_warn_array_value($row, 'settings', '{}'));
        $clients = $settings['clients'] ?? [];
        if(!is_array($clients)) $clients = [];

        foreach($clients as $client){
            $clientId = v2raystore_warn_client_identity($client);
            $clientEmail = v2raystore_warn_client_email($client);
            $match = false;
            if($uuid !== '' && $clientId !== '' && $clientId === $uuid) $match = true;
            if(!$match && $remark !== '' && $clientEmail !== '' && $clientEmail === $remark) $match = true;
            if(!$match) continue;

            $state['found'] = true;

            $stat = v2raystore_warn_find_stat(v2raystore_warn_array_value($row, 'clientStats', []), $clientEmail);

            $total = intval(v2raystore_warn_array_value($client, 'totalGB', 0));
            if($total <= 0 && $stat) $total = intval(v2raystore_warn_array_value($stat, 'total', 0));
            if($total <= 0) $total = intval(v2raystore_warn_array_value($row, 'total', 0));

            $up = $stat ? intval(v2raystore_warn_array_value($stat, 'up', 0)) : intval(v2raystore_warn_array_value($row, 'up', 0));
            $down = $stat ? intval(v2raystore_warn_array_value($stat, 'down', 0)) : intval(v2raystore_warn_array_value($row, 'down', 0));

            $enable = (bool)v2raystore_warn_array_value($row, 'enable', true);
            $clientEnable = v2raystore_warn_array_value($client, 'enable', null);
            if($clientEnable !== null && !$clientEnable) $enable = false;
            if($stat){
                $statEnable = v2raystore_warn_array_value($stat, 'enable', null);
                if($statEnable !== null && !$statEnable) $enable = false;
            }

            $clientExp = v2raystore_warn_expiry_seconds(v2raystore_warn_array_value($client, 'expiryTime', 0));
            $statExp = $stat ? v2raystore_warn_expiry_seconds(v2raystore_warn_array_value($stat, 'expiryTime', 0)) : 0;
            $rowExp = v2raystore_warn_expiry_seconds(v2raystore_warn_array_value($row, 'expiryTime', 0));
            $expiry = $clientExp > 0 ? $clientExp : ($statExp > 0 ? $statExp : $rowExp);

            $state['total'] = $total;
            $state['up'] = $up;
            $state['down'] = $down;
            $state['total_left'] = $total > 0 ? ($total - $up - $down) : PHP_INT_MAX;
            $state['expiry_time'] = $expiry;
            $state['enable'] = $enable;
            return $state;
        }
    }

    return $state;
}

function v2raystore_warn_update_notif_by_order($orderId, $notif){
    global $connection;
    $orderId = intval($orderId);
    $notif = intval($notif);
    if($orderId <= 0) return;
    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = ? WHERE `id` = ?");
    if(!$stmt) return;
    $stmt->bind_param("ii", $notif, $orderId);
    $stmt->execute();
    $stmt->close();
}

function v2raystore_warn_delete_order_by_id($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return;
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    if(!$stmt) return;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $stmt->close();
}

function v2raystore_warn_setting_value($type, $default = null){
    global $connection;
    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 1");
    if(!$stmt) return $default;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res && $res->num_rows > 0) return $res->fetch_assoc()['value'];
    return $default;
}

function v2raystore_warn_ensure_notification_columns(){
    global $connection;
    $columns = [
        'notif_msg_id' => "ALTER TABLE `orders_list` ADD `notif_msg_id` int(20) NOT NULL DEFAULT 0 AFTER `notif`",
        'notif_kind' => "ALTER TABLE `orders_list` ADD `notif_kind` varchar(40) NOT NULL DEFAULT '' AFTER `notif_msg_id`"
    ];
    foreach($columns as $column => $alter){
        $exists = @($connection->query("SHOW COLUMNS FROM `orders_list` LIKE '$column'"));
        if(!$exists || $exists->num_rows == 0) @($connection->query($alter));
    }
}

function v2raystore_warn_h($value){
    if(function_exists('v2raystore_h')) return v2raystore_h($value);
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function v2raystore_warn_is_test_order($order){
    return intval($order['amount'] ?? 0) <= 0;
}

function v2raystore_warn_gb_text($bytes){
    $gb = round(max(0, intval($bytes)) / 1073741824, 2);
    if($gb <= 0) return '۰';
    return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
}

function v2raystore_warn_update_notification_state($orderId, $notif, $kind = '', $messageId = 0){
    global $connection;
    $orderId = intval($orderId);
    $notif = intval($notif);
    $messageId = intval($messageId);
    $kind = trim((string)$kind);
    if($orderId <= 0) return false;
    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = ?, `notif_kind` = ?, `notif_msg_id` = ? WHERE `id` = ?");
    if(!$stmt){
        v2raystore_warn_update_notif_by_order($orderId, $notif);
        return false;
    }
    $stmt->bind_param('isii', $notif, $kind, $messageId, $orderId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function v2raystore_warn_delete_previous_message($order){
    $msgId = intval($order['notif_msg_id'] ?? 0);
    $userId = intval($order['userid'] ?? 0);
    if($msgId > 0 && $userId != 0) @delMessage($msgId, $userId);
}

function v2raystore_warn_send_or_replace($order, $kind, $msg, $notifValue){
    $orderId = intval($order['id'] ?? 0);
    $oldKind = trim((string)($order['notif_kind'] ?? ''));
    $oldMsgId = intval($order['notif_msg_id'] ?? 0);
    if($oldKind === $kind && $oldMsgId > 0){
        v2raystore_warn_update_notification_state($orderId, $notifValue, $kind, $oldMsgId);
        return $oldMsgId;
    }
    v2raystore_warn_delete_previous_message($order);
    $res = sendMessage($msg, null, 'HTML', intval($order['userid'] ?? 0));
    $newMsgId = 0;
    if(is_object($res) && isset($res->ok) && $res->ok && isset($res->result->message_id)) $newMsgId = intval($res->result->message_id);
    v2raystore_warn_update_notification_state($orderId, $notifValue, $kind, $newMsgId);
    return $newMsgId;
}

function v2raystore_warn_clear_notification($order, $deleteMessage = true){
    if($deleteMessage) v2raystore_warn_delete_previous_message($order);
    v2raystore_warn_update_notification_state(intval($order['id'] ?? 0), 0, '', 0);
}

function v2raystore_warn_build_low_message($order, $kind, $leftBytes, $expiryTime){
    $remark = v2raystore_warn_h($order['remark'] ?? '');
    $isTest = v2raystore_warn_is_test_order($order);
    if($kind === 'low_volume'){
        $left = v2raystore_warn_gb_text($leftBytes);
        if($isTest){
            return "⚠️ <b>حجم اکانت تست شما رو به پایان است</b>\n\n🔮 نام اکانت تست: <code>{$remark}</code>\n🔋 حجم باقی‌مانده: <b>{$left} گیگ</b>\n\nاین اکانت تست است؛ در صورت رضایت از کیفیت سرویس، می‌توانید از منوی خرید سرویس اصلی تهیه کنید.";
        }
        return "⚠️ <b>هشدار حجم سرویس</b>\n\nاز حجم اکانت شما کمتر از <b>۱ گیگ</b> باقی مانده است.\n🔮 نام اکانت: <code>{$remark}</code>\n🔋 حجم باقی‌مانده: <b>{$left} گیگ</b>\n\nبرای جلوگیری از قطع سرویس، از بخش «کانفیگ‌های من» سرویس را تمدید یا افزایش حجم دهید.";
    }

    $leftSeconds = max(0, intval($expiryTime) - time());
    $hours = max(1, ceil($leftSeconds / 3600));
    if($isTest){
        return "⚠️ <b>زمان اکانت تست شما رو به پایان است</b>\n\n🔮 نام اکانت تست: <code>{$remark}</code>\n⏰ زمان باقی‌مانده: <b>{$hours} ساعت</b>\n\nاین اکانت تست است؛ در صورت رضایت از کیفیت سرویس، می‌توانید سرویس اصلی تهیه کنید.";
    }
    return "⚠️ <b>هشدار پایان زمان سرویس</b>\n\nکمتر از <b>۱ روز</b> از زمان اکانت شما باقی مانده است.\n🔮 نام اکانت: <code>{$remark}</code>\n⏰ زمان باقی‌مانده: <b>{$hours} ساعت</b>\n\nبرای جلوگیری از قطع سرویس، از بخش «کانفیگ‌های من» سرویس را تمدید کنید.";
}

function v2raystore_warn_build_finished_message($order, $finishKind){
    $remark = v2raystore_warn_h($order['remark'] ?? '');
    $isTest = v2raystore_warn_is_test_order($order);
    if($finishKind === 'finished_volume'){
        if($isTest){
            return "⛔️ <b>حجم اکانت تست شما تمام شد</b>\n\n🔮 نام اکانت تست: <code>{$remark}</code>\n\nحجم اکانت تست شما به پایان رسید. برای ادامه استفاده، می‌توانید از منوی ربات سرویس اصلی خریداری کنید.";
        }
        return "⛔️ <b>حجم اکانت شما تمام شد</b>\n\n🔮 نام اکانت: <code>{$remark}</code>\n\nحجم این اکانت تمام شده است. برای ادامه استفاده، از بخش «کانفیگ‌های من» افزایش حجم بزنید یا سرویس جدید تهیه کنید.";
    }

    if($isTest){
        return "⏰ <b>مدت اکانت تست شما تمام شد</b>\n\n🔮 نام اکانت تست: <code>{$remark}</code>\n\nمدت اعتبار اکانت تست شما به پایان رسید. برای ادامه استفاده، می‌توانید سرویس اصلی خریداری کنید.";
    }
    return "⏰ <b>مدت اکانت شما تمام شد</b>\n\n🔮 نام اکانت: <code>{$remark}</code>\n\nمدت اعتبار این اکانت تمام شده است. برای ادامه استفاده، از بخش «کانفیگ‌های من» سرویس را تمدید کنید یا سرویس جدید تهیه کنید.";
}

function v2raystore_warn_delete_config_from_panel($order, $state){
    $server_id = intval($order['server_id'] ?? 0);
    $inbound_id = intval($order['inbound_id'] ?? 0);
    $uuid = $order['uuid'] ?? '0';
    $remark = $order['remark'] ?? '';
    if(($state['server_type'] ?? '') == 'marzban') return deleteMarzban($server_id, $remark);
    if($inbound_id > 0) return deleteClient($server_id, $inbound_id, $uuid, 1);
    return deleteInbound($server_id, $uuid, 1);
}

v2raystore_warn_ensure_notification_columns();

function v2raystore_warn_remove_orphan_if_checked($order, $state, $notify = true){
    if(!is_array($order) || !is_array($state)) return false;
    if(!empty($state['found']) || empty($state['logged_in'])) return false;
    $orderId = intval($order['id'] ?? 0);
    if($orderId <= 0) return false;
    v2raystore_warn_delete_order_by_id($orderId);
    if($notify && !empty($order['userid'])){
        $remark = htmlspecialchars((string)($order['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
        sendMessage("ℹ️ سرویس <b>$remark</b> دیگر داخل پنل وجود ندارد؛ از لیست ربات هم پاک شد.", null, 'HTML', intval($order['userid']));
    }
    return true;
}

$autoDeleteConfigs = v2raystore_warn_setting_value('CLEAN_OLD_CONFIGS_AUTO', 'off') === 'on';
$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND (`notif` IN (0, -1, -2) OR COALESCE(`notif_kind`, '') != '') ORDER BY `id` ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $warnOffset);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if($orders){
    if($orders->num_rows>0){
        while ($order = $orders->fetch_assoc()){
            $from_id = intval($order['userid']);
            $notif = intval($order['notif']);
            $orderId = intval($order['id']);
            $storedKind = trim((string)($order['notif_kind'] ?? ''));
            if($storedKind === '' && $notif == -1) $storedKind = 'legacy_warning';

            $state = v2raystore_warn_order_state($order);
            if(empty($state['found'])){
                v2raystore_warn_remove_orphan_if_checked($order, $state, true);
                continue;
            }

            $expiryTime = intval($state['expiry_time'] ?? 0);
            $total = intval($state['total'] ?? 0);
            $totalLeft = intval($state['total_left'] ?? 0);
            $enable = !empty($state['enable']);
            $leftgb = ($total > 0) ? round($totalLeft / 1073741824, 2) : 999999;

            $finishKind = '';
            if($total > 0 && $totalLeft <= 0) $finishKind = 'finished_volume';
            elseif($expiryTime > 0 && $expiryTime <= time()) $finishKind = 'finished_time';

            if($finishKind !== ''){
                if($storedKind !== $finishKind){
                    $msg = v2raystore_warn_build_finished_message($order, $finishKind);
                    v2raystore_warn_send_or_replace($order, $finishKind, $msg, -2);
                }else{
                    v2raystore_warn_update_notification_state($orderId, -2, $finishKind, intval($order['notif_msg_id'] ?? 0));
                }

                if($autoDeleteConfigs){
                    $res = v2raystore_warn_delete_config_from_panel($order, $state);
                    if(!is_null($res)) v2raystore_warn_delete_order_by_id($orderId);
                }
                continue;
            }

            $lowKind = '';
            if($expiryTime > 0 && $expiryTime < time() + 86400) $lowKind = 'low_time';
            elseif($total > 0 && $totalLeft > 0 && $totalLeft <= 1073741824) $lowKind = 'low_volume';

            if($lowKind !== ''){
                if($storedKind !== $lowKind){
                    $msg = v2raystore_warn_build_low_message($order, $lowKind, $totalLeft, $expiryTime);
                    v2raystore_warn_send_or_replace($order, $lowKind, $msg, -1);
                }else{
                    v2raystore_warn_update_notification_state($orderId, -1, $lowKind, intval($order['notif_msg_id'] ?? 0));
                }
                continue;
            }

            if(!$enable){
                $newTime = $time + 86400 * 2;
                v2raystore_warn_update_notification_state($orderId, $newTime, 'disabled_wait', intval($order['notif_msg_id'] ?? 0));
                continue;
            }

            // اگر اکانت تمدید/شارژ شد و دیگر در وضعیت هشدار یا پایان نیست، پیام قبلی پاک و وضعیت اعلان آزاد می‌شود.
            if($notif != 0 || $storedKind !== ''){
                v2raystore_warn_clear_notification($order, true);
            }
        }
        file_put_contents("warnOffset.txt", $warnOffset + $limit);
    }else{
        if(file_exists('warnOffset.txt')) unlink('warnOffset.txt');
    }
}

// مرحله حذف خودکار: فقط وقتی گزینه حذف خودکار روشن است. پاکسازی رکوردهای حذف‌شده از پنل مستقل از این گزینه انجام می‌شود.
$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `notif` > 0 AND `notif` < ? LIMIT 50");
$stmt->bind_param("i", $time);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if($orders){
    if($orders->num_rows>0){
        while ($order = $orders->fetch_assoc()){
            $from_id = intval($order['userid']);
            $remark = $order['remark'];
            $uuid = $order['uuid'] ?? "0";
            $server_id = intval($order['server_id']);
            $inbound_id = intval($order['inbound_id']);
            $orderId = intval($order['id']);

            $state = v2raystore_warn_order_state($order);
            if(empty($state['found'])){
                v2raystore_warn_remove_orphan_if_checked($order, $state, true);
                continue;
            }

            if(!$autoDeleteConfigs){
                // حذف به دلیل پایان حجم/تاریخ تا وقتی حذف خودکار خاموش است انجام نمی‌شود.
                continue;
            }

            $expiryTime = intval($state['expiry_time'] ?? 0);
            $total = intval($state['total'] ?? 0);
            $totalLeft = intval($state['total_left'] ?? 0);
            $leftgb = ($total > 0) ? round($totalLeft / 1073741824, 2) : 999999;

            $shouldDelete = false;
            if($expiryTime > 0 && $expiryTime <= time()) $shouldDelete = true;
            elseif($total > 0 && $leftgb <= 0) $shouldDelete = true;

            if($shouldDelete){
                $res = null;
                if(($state['server_type'] ?? '') == "marzban"){
                    $res = deleteMarzban($server_id, $remark);
                }else{
                    if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
                    else $res = deleteInbound($server_id, $uuid, 1);
                }
                if(!is_null($res)){
                    $msg = "💡 کاربر گرامی،\nاشتراک سرویس $remark منقضی شد و از لیست سفارش‌ها حذف گردید. لطفاً از فروشگاه، سرویس جدید خریداری کنید.";
                    sendMessage($msg, null, null, $from_id);
                    v2raystore_warn_delete_order_by_id($orderId);
                    continue;
                }
            }else{
                v2raystore_warn_update_notif_by_order($orderId, 0);
            }
        }
    }
}

// پاکسازی مستقل رکوردهایی که از پنل حذف شده‌اند؛ این بخش به گزینه حذف خودکار وابسته نیست.
if(file_exists("orphanOffset.txt")) $orphanOffset = intval(file_get_contents("orphanOffset.txt"));
else $orphanOffset = 0;
$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 ORDER BY `id` ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $orphanOffset);
$stmt->execute();
$orphanOrders = $stmt->get_result();
$stmt->close();

if($orphanOrders){
    if($orphanOrders->num_rows > 0){
        while($order = $orphanOrders->fetch_assoc()){
            $state = v2raystore_warn_order_state($order);
            if(empty($state['found'])) v2raystore_warn_remove_orphan_if_checked($order, $state, true);
        }
        file_put_contents("orphanOffset.txt", $orphanOffset + $limit);
    }else{
        if(file_exists('orphanOffset.txt')) unlink('orphanOffset.txt');
    }
}
