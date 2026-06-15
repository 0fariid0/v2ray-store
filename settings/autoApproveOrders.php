<?php
// اجرای سبک تأیید خودکار سفارش‌ها برای اینکه زمان‌بندی فقط وابسته به وبهوک کاربران نباشد.
// این فایل توسط ربات بعد از ثبت رسید، در پس‌زمینه اجرا می‌شود.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
chdir($root);

$delay = isset($argv[1]) ? intval($argv[1]) : 0;
if($delay > 0){
    // بیشتر از یک روز نگه نمی‌داریم تا پروسه اشتباهی روی سرور نماند.
    $delay = min($delay, 86400);
    sleep($delay);
}

require_once $root . '/config.php';

if(function_exists('v2raystore_processAutoApproveOrders')){
    // force=false یعنی فقط سفارش‌هایی که واقعاً زمانشان رسیده تأیید می‌شوند.
    v2raystore_processAutoApproveOrders(false, 10);
}
