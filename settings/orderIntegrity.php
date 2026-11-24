<?php
// No schema changes and no reconstruction of deleted financial records.
function v2raystore_receiptEntryAllowed($pay, $userId, $prefix){
    if(!is_array($pay) || (int)($pay['user_id'] ?? 0) !== (int)$userId || (int)$userId <= 0) return false;
    if(!in_array($pay['state'] ?? '', ['pending', 'sent'], true) || trim((string)($pay['payid'] ?? '')) !== '') return false;
    $type = (string)($pay['type'] ?? '');
    switch($prefix){
        case 'increaseWalletWithCartToCart': return $type === 'INCREASE_WALLET';
        case 'payWithCartToCart': return in_array($type, ['BUY_SUB', 'RENEW_SCONFIG'], true);
        case 'payCustomWithCartToCart': return $type === 'BUY_SUB';
        case 'payRenewWithCartToCart': return in_array($type, ['RENEW_ACCOUNT', 'RENEW_SCONFIG'], true);
        case 'payIncreaseDayWithCartToCart': return strpos($type, 'INCREASE_DAY_') === 0;
        case 'payIncreaseWithCartToCart': return strpos($type, 'INCREASE_VOLUME_') === 0;
    }
    return false;
}

function v2raystore_executePayInsert($stmt){
    try{
        if($stmt && $stmt->execute() && $stmt->affected_rows === 1) return true;
    }catch(Throwable $e){
        error_log('Payment creation failed: database code ' . (int)$e->getCode());
    }
    // Never show payment instructions when storage failed. No automatic INSERT
    // retry: a lost DB response must not create a second payment.
    sendMessage('ثبت سفارش کامل نشد. لطفاً کمی بعد دوباره از منوی اصلی اقدام کنید؛ هنوز پرداخت نکنید.', getMainKeys());
    exit();
}

function v2raystore_lockOrderConversation($connection, $database, $userId){
    $key = 'v2order:' . substr(hash('sha256', (string)$database . ':' . (string)$userId), 0, 48);
    try{
        $stmt = $connection->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        if(!$stmt) throw new RuntimeException('Lock unavailable');
        $stmt->bind_param('s', $key);
        if(!$stmt->execute()) throw new RuntimeException('Lock failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if((int)($row['acquired'] ?? 0) !== 1) throw new RuntimeException('Lock busy');
    }catch(Throwable $e){
        // Telegram retries the update. Never acknowledge an unprocessed update.
        http_response_code(503);
        header('Retry-After: 2');
        exit();
    }
    register_shutdown_function(function() use ($connection, $key){
        try{
            $stmt = $connection->prepare('SELECT RELEASE_LOCK(?)');
            if($stmt){ $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close(); }
        }catch(Throwable $e){}
    });
}

/** Only the webhook that reserved the wallet funds may run the approval. */
function v2raystore_walletApprovalOwns($hashId){
    return isset($GLOBALS['v2raystore_wallet_attempt'])
        && $GLOBALS['v2raystore_wallet_attempt']['hash'] === $hashId;
}

function v2raystore_walletApprovalMutating($hashId){
    if(v2raystore_walletApprovalOwns($hashId)) $GLOBALS['v2raystore_wallet_attempt']['uncertain'] = true;
}

function v2raystore_walletCallbackAcceptsType($route, $type){
    return match($route){
        'payCustomWithWallet' => $type === 'BUY_SUB',
        'payWithWallet' => in_array($type, ['BUY_SUB', 'RENEW_SCONFIG'], true),
        'payRenewWithWallet' => $type === 'RENEW_ACCOUNT',
        'payIncraseDayWithWallet' => preg_match('/^INCREASE_DAY_\d+_\d+$/D', $type) === 1,
        'payIncraseWithWallet' => preg_match('/^INCREASE_VOLUME_\d+_\d+$/D', $type) === 1,
        default => false,
    };
}

function v2raystore_runWalletPayment($hashId, $route, $userId){
    global $connection, $admin;
    if(!v2raystore_isWalletOpenForCurrentUser()) return ['ok'=>false, 'message'=>'پرداخت با کیف پول برای حساب شما غیرفعال است.'];
    $pay = v2raystore_getPayByHash($hashId);
    if(!$pay || (int)$userId <= 0 || (int)$pay['user_id'] !== (int)$userId
        || !v2raystore_walletCallbackAcceptsType($route, (string)$pay['type'])){
        return ['ok'=>false, 'message'=>'سفارش قابل پرداخت با این دکمه پیدا نشد.'];
    }
    if(($pay['state'] ?? '') !== 'pending'){
        return ['ok'=>false, 'message'=>'این سفارش در انتظار پرداخت نیست یا قبلاً پردازش شده است.'];
    }
    if(trim((string)($pay['payid'] ?? '')) !== ''){
        return ['ok'=>false, 'message'=>'برای این سفارش درگاه باز شده است؛ وضعیت آن را بررسی کنید. برای کیف پول سفارش جداگانه بسازید.'];
    }
    if(v2raystore_salesBlockReasonForPayType((string)$pay['type']) !== ''){
        return ['ok'=>false, 'message'=>'فروش برای این سفارش در حال حاضر غیرفعال است.'];
    }
    $id = (int)$pay['id'];
    $price = (int)$pay['price'];
    if($id <= 0 || $price < 0) return ['ok'=>false, 'message'=>'مبلغ سفارش معتبر نیست.'];

    // One guarded statement; works with installations using MyISAM for users.
    $stmt = $connection->prepare("UPDATE `pays` p JOIN `users` u ON u.`userid` = p.`user_id`
        SET p.`state` = 'wallet_processing', p.`payment_method` = 'wallet',
            p.`approval_error` = NULL, p.`approval_error_date` = UNIX_TIMESTAMP(), u.`wallet` = u.`wallet` - p.`price`
        WHERE p.`id` = ? AND p.`user_id` = ? AND p.`state` = 'pending' AND p.`price` = ? AND u.`wallet` >= p.`price`
          AND COALESCE(p.`payid`,'') = '' AND COALESCE(p.`receipt_file_id`,'') = ''");
    $stmt->bind_param('iii', $id, $userId, $price);
    $claimed = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    if(!$claimed) return ['ok'=>false, 'message'=>'موجودی کیف پول کافی نیست یا وضعیت سفارش تغییر کرده است.'];

    $GLOBALS['v2raystore_wallet_attempt'] = ['hash'=>$hashId, 'uncertain'=>false];
    try{
        $result = match($route){
            'payRenewWithWallet' => v2raystore_approveRenewAccountPayByHash($hashId),
            'payIncraseDayWithWallet' => v2raystore_approveIncreaseDayPayByHash($hashId),
            'payIncraseWithWallet' => v2raystore_approveIncreaseVolumePayByHash($hashId),
            default => v2raystore_approveSentOrderByHash($hashId),
        };
    }catch(Throwable $e){
        $result = ['ok'=>false, 'message'=>'پردازش سفارش متوقف شد.'];
        // An exception may occur after approval was saved (e.g. Telegram/reporting).
        error_log('Wallet fulfillment interrupted: ' . $hashId);
    }finally{
        $uncertain = $GLOBALS['v2raystore_wallet_attempt']['uncertain'];
        unset($GLOBALS['v2raystore_wallet_attempt']);
    }

    try{
        $stored = v2raystore_getPayByHash($hashId);
        if(($stored['state'] ?? '') === 'approved'){
            if(empty($result['ok'])){
                $result = ['ok'=>true, 'type'=>$pay['type'], 'user_id'=>(int)$userId,
                    'order_ids'=>json_decode($stored['auto_approved_orders'] ?? '[]', true) ?: []];
            }
            v2raystore_notifyWalletCustomer($hashId, $result);
            return $result;
        }
        if(!$uncertain && empty($result['ok']) && ($stored['state'] ?? '') === 'wallet_processing'){
            $stmt = $connection->prepare("UPDATE `pays` p JOIN `users` u ON u.`userid` = p.`user_id`
                SET p.`state` = 'pending', p.`payment_method` = '', u.`wallet` = u.`wallet` + ?
                WHERE p.`id` = ? AND p.`user_id` = ? AND p.`state` = 'wallet_processing'");
            $stmt->bind_param('iii', $price, $id, $userId);
            $returned = $stmt->execute() && $stmt->affected_rows > 0;
            $stmt->close();
            if($returned) return $result;
        }
    }catch(Throwable $e){
        error_log('Wallet settlement needs review: ' . $hashId);
    }
    // A request that may already have changed a panel must never be replayed or
    // automatically refunded. The code identifies the held payment in the DB.
    $message = 'وضعیت نهایی سفارش نیاز به بررسی دارد. دوباره پرداخت نکنید و این کد را به پشتیبانی بدهید: ' . $hashId;
    try{
        v2raystore_setPayApprovalError($hashId, $message);
        if(!empty($admin)) sendMessage("⚠️ پرداخت کیف پول نیاز به بررسی دارد\nکد: $hashId\nکاربر: $userId\nمبلغ: $price تومان\n" . ($result['message'] ?? ''), null, null, $admin);
    }catch(Throwable $e){
        error_log('Wallet review notification failed: ' . $hashId);
    }
    return ['ok'=>false, 'message'=>$message];
}

function v2raystore_holdApprovalForReview($hashId){
    global $connection;
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approval_review' WHERE `hash_id` = ? AND `state` IN ('processing','auto_processing')");
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $stmt->close();
}

function v2raystore_parseWalletTopUp($input){
    $digits = v2raystore_rewardNormalizeNumberInput($input);
    if(!preg_match('/^[0-9]{1,10}$/D', $digits)) return null;
    $amount = (int)$digits;
    return $amount >= 5000 && $amount <= 2147483647 ? $amount : null;
}

function v2raystore_marzbanWriteResult($body, $httpStatus){
    if($body === false || $httpStatus === 0) return null;
    $json = json_decode((string)$body);
    if($httpStatus < 200 || $httpStatus >= 300 || (is_object($json) && isset($json->detail))){
        // Don't stringify objects/arrays or expose raw response bodies.
        return (object)['success'=>false, 'msg'=>'درخواست در پنل مرزبان تأیید نشد (HTTP ' . (int)$httpStatus . ').'];
    }
    if($httpStatus === 204 || (is_object($json) && (
        !empty($json->success) || (!empty($json->username) && isset($json->status))
    ))) return (object)['success'=>true];
    return null;
}

function v2raystore_storeGatewayReference($payId, $reference){
    global $connection;
    if((int)$payId <= 0 || trim((string)$reference) === '') return false;
    $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?
        WHERE `id` = ? AND `state` = 'pending' AND COALESCE(`payid`,'') = ''");
    $stmt->bind_param('si', $reference, $payId);
    $saved = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $saved;
}
