<?php
include_once '../baseInfo.php';
include_once '../config.php';
$time = time();

if(file_exists("warnOffset.txt")) $warnOffset = intval(file_get_contents("warnOffset.txt"));
else $warnOffset = 0;
$limit = 50;

function wizwiz_warn_array_value($obj, $key, $default = null){
    if(function_exists('wizwiz_arrayValue')) return wizwiz_arrayValue($obj, $key, $default);
    if(is_array($obj) && array_key_exists($key, $obj)) return $obj[$key];
    if(is_object($obj) && isset($obj->$key)) return $obj->$key;
    return $default;
}

function wizwiz_warn_decode($value){
    if(function_exists('wizwiz_decodeMaybeJson')) return wizwiz_decodeMaybeJson($value, true);
    if(is_array($value)) return $value;
    if(is_object($value)) return json_decode(json_encode($value), true);
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function wizwiz_warn_expiry_seconds($value){
    if(function_exists('wizwiz_panelExpiryToSeconds')) return wizwiz_panelExpiryToSeconds($value);
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

function wizwiz_warn_client_identity($client){
    if(function_exists('wizwiz_panelClientIdentity')) return wizwiz_panelClientIdentity($client);
    $id = (string)wizwiz_warn_array_value($client, 'id', '');
    if($id === '') $id = (string)wizwiz_warn_array_value($client, 'uuid', '');
    if($id === '') $id = (string)wizwiz_warn_array_value($client, 'password', '');
    return $id;
}

function wizwiz_warn_client_email($client){
    if(function_exists('wizwiz_panelClientEmail')) return wizwiz_panelClientEmail($client);
    return trim((string)wizwiz_warn_array_value($client, 'email', ''));
}

function wizwiz_warn_find_stat($stats, $email){
    if(function_exists('wizwiz_panelFindClientStat')) return wizwiz_panelFindClientStat($stats, $email);
    $email = trim((string)$email);
    if($email === '') return null;
    if(is_object($stats)) $stats = [$stats];
    if(!is_array($stats)) return null;
    foreach($stats as $stat){
        $statEmail = trim((string)wizwiz_warn_array_value($stat, 'email', ''));
        if($statEmail !== '' && $statEmail === $email) return $stat;
    }
    return null;
}

function wizwiz_warn_rows_from_getjson($json){
    if(function_exists('wizwiz_panelListFromGetJson')) return wizwiz_panelListFromGetJson($json);
    if(!$json || !isset($json->obj)) return [];
    $rows = $json->obj;
    if(is_object($rows)) $rows = [$rows];
    return is_array($rows) ? $rows : [];
}

function wizwiz_warn_order_state($order){
    global $connection;

    if(!is_array($order)) return ['found'=>false, 'logged_in'=>false];

    // مهم: قبل از تصمیم برای هشدار یا حذف، تاریخ واقعی پنل را در دیتابیس ربات sync می‌کنیم.
    if(function_exists('wizwiz_syncOrderExpiryFromPanel')){
        $sync = wizwiz_syncOrderExpiryFromPanel($order, true);
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
            $state['expiry_time'] = wizwiz_warn_expiry_seconds($info->expire ?? 0);
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
    $rows = wizwiz_warn_rows_from_getjson($response);

    foreach($rows as $row){
        $rowId = intval(wizwiz_warn_array_value($row, 'id', 0));
        if($inbound_id > 0 && $rowId !== $inbound_id) continue;

        $settings = wizwiz_warn_decode(wizwiz_warn_array_value($row, 'settings', '{}'));
        $clients = $settings['clients'] ?? [];
        if(!is_array($clients)) $clients = [];

        foreach($clients as $client){
            $clientId = wizwiz_warn_client_identity($client);
            $clientEmail = wizwiz_warn_client_email($client);
            $match = false;
            if($uuid !== '' && $clientId !== '' && $clientId === $uuid) $match = true;
            if(!$match && $remark !== '' && $clientEmail !== '' && $clientEmail === $remark) $match = true;
            if(!$match) continue;

            $state['found'] = true;

            $stat = wizwiz_warn_find_stat(wizwiz_warn_array_value($row, 'clientStats', []), $clientEmail);

            $total = intval(wizwiz_warn_array_value($client, 'totalGB', 0));
            if($total <= 0 && $stat) $total = intval(wizwiz_warn_array_value($stat, 'total', 0));
            if($total <= 0) $total = intval(wizwiz_warn_array_value($row, 'total', 0));

            $up = $stat ? intval(wizwiz_warn_array_value($stat, 'up', 0)) : intval(wizwiz_warn_array_value($row, 'up', 0));
            $down = $stat ? intval(wizwiz_warn_array_value($stat, 'down', 0)) : intval(wizwiz_warn_array_value($row, 'down', 0));

            $enable = (bool)wizwiz_warn_array_value($row, 'enable', true);
            $clientEnable = wizwiz_warn_array_value($client, 'enable', null);
            if($clientEnable !== null && !$clientEnable) $enable = false;
            if($stat){
                $statEnable = wizwiz_warn_array_value($stat, 'enable', null);
                if($statEnable !== null && !$statEnable) $enable = false;
            }

            $clientExp = wizwiz_warn_expiry_seconds(wizwiz_warn_array_value($client, 'expiryTime', 0));
            $statExp = $stat ? wizwiz_warn_expiry_seconds(wizwiz_warn_array_value($stat, 'expiryTime', 0)) : 0;
            $rowExp = wizwiz_warn_expiry_seconds(wizwiz_warn_array_value($row, 'expiryTime', 0));
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

function wizwiz_warn_update_notif_by_order($orderId, $notif){
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

function wizwiz_warn_delete_order_by_id($orderId){
    global $connection;
    $orderId = intval($orderId);
    if($orderId <= 0) return;
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    if(!$stmt) return;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $stmt->close();
}

function wizwiz_warn_setting_value($type, $default = null){
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

function wizwiz_warn_remove_orphan_if_checked($order, $state, $notify = true){
    if(!is_array($order) || !is_array($state)) return false;
    if(!empty($state['found']) || empty($state['logged_in'])) return false;
    $orderId = intval($order['id'] ?? 0);
    if($orderId <= 0) return false;
    wizwiz_warn_delete_order_by_id($orderId);
    if($notify && !empty($order['userid'])){
        $remark = htmlspecialchars((string)($order['remark'] ?? ''), ENT_QUOTES, 'UTF-8');
        sendMessage("ℹ️ سرویس <b>$remark</b> دیگر داخل پنل وجود ندارد؛ از لیست ربات هم پاک شد.", null, 'HTML', intval($order['userid']));
    }
    return true;
}

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND (`notif`=0 OR `notif` = -1) ORDER BY `id` ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $warnOffset);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if($orders){
    if($orders->num_rows>0){
        while ($order = $orders->fetch_assoc()){
            $from_id = intval($order['userid']);
            $remark = $order['remark'];
            $notif = intval($order['notif']);
            $orderId = intval($order['id']);

            $state = wizwiz_warn_order_state($order);
            if(empty($state['found'])){
                // اگر پنل در دسترس بود و کلاینت دیگر وجود نداشت، فقط رکورد اضافه ربات را پاک می‌کنیم.
                wizwiz_warn_remove_orphan_if_checked($order, $state, true);
                continue;
            }

            $expiryTime = intval($state['expiry_time'] ?? 0);
            $total = intval($state['total'] ?? 0);
            $totalLeft = intval($state['total_left'] ?? 0);
            $enable = !empty($state['enable']);
            $leftgb = ($total > 0) ? round($totalLeft / 1073741824, 2) : 999999;

            if($notif == 0){
                $send = "";
                if($expiryTime > 0 && $expiryTime < time() + 86400) $send = "روز";
                elseif($total > 0 && $leftgb < 1) $send = "گیگ";

                if($send != ""){
                    $msg = "💡 کاربر گرامی،\nاز سرویس اشتراک $remark تنها (۱ $send) باقی مانده است. می‌توانید از قسمت کانفیگ‌های من، سرویس فعلی خود را تمدید کنید یا سرویس جدید خریداری کنید.";
                    sendMessage($msg, null, null, $from_id);
                    wizwiz_warn_update_notif_by_order($orderId, -1);
                }elseif(!$enable){
                    $newTime = $time + 86400 * 2;
                    wizwiz_warn_update_notif_by_order($orderId, $newTime);
                }
            }elseif($notif == -1){
                // اگر ادمین از پنل تاریخ یا حجم را تمدید کرده باشد، هشدار قبلی را آزاد می‌کنیم تا دفعه بعد دوباره درست هشدار بدهد.
                $isCloseToExpire = ($expiryTime > 0 && $expiryTime < time() + 86400);
                $isLowVolume = ($total > 0 && $leftgb < 1);
                if(!$isCloseToExpire && !$isLowVolume && $enable){
                    wizwiz_warn_update_notif_by_order($orderId, 0);
                }
            }
        }
        file_put_contents("warnOffset.txt", $warnOffset + $limit);
    }else{
        if(file_exists('warnOffset.txt')) unlink('warnOffset.txt');
    }
}

// مرحله حذف خودکار: فقط وقتی گزینه حذف خودکار روشن است. پاکسازی رکوردهای حذف‌شده از پنل مستقل از این گزینه انجام می‌شود.
$autoDeleteConfigs = wizwiz_warn_setting_value('CLEAN_OLD_CONFIGS_AUTO', 'off') === 'on';
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

            $state = wizwiz_warn_order_state($order);
            if(empty($state['found'])){
                wizwiz_warn_remove_orphan_if_checked($order, $state, true);
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
                    wizwiz_warn_delete_order_by_id($orderId);
                    continue;
                }
            }else{
                wizwiz_warn_update_notif_by_order($orderId, 0);
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
            $state = wizwiz_warn_order_state($order);
            if(empty($state['found'])) wizwiz_warn_remove_orphan_if_checked($order, $state, true);
        }
        file_put_contents("orphanOffset.txt", $orphanOffset + $limit);
    }else{
        if(file_exists('orphanOffset.txt')) unlink('orphanOffset.txt');
    }
}
