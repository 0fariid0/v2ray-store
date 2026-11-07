<?php
// No schema changes and no reconstruction of deleted financial records.
function v2raystore_receiptEntryAllowed($pay, $userId, $prefix){
    if(!is_array($pay) || (int)($pay['user_id'] ?? 0) !== (int)$userId || (int)$userId <= 0) return false;
    if(!in_array($pay['state'] ?? '', ['pending', 'sent'], true)) return false;
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
