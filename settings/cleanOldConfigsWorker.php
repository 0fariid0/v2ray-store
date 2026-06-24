<?php
// اجرای مرحله‌ای پاکسازی کانفیگ‌های قدیمی
// Cron پیشنهادی: * * * * * php /path/to/bot/settings/cleanOldConfigsWorker.php >/dev/null 2>&1

$root = dirname(__DIR__);
chdir($root);
include_once $root . '/config.php';

if(!function_exists('v2raystore_processCleanOldConfigsJob')){
    echo "clean worker functions not loaded\n";
    exit;
}

// هر اجرا فقط چند مورد؛ برای اینکه به پنل و سرور فشار نیاید.
$result = v2raystore_processCleanOldConfigsJob(5, 45, true);

if(php_sapi_name() === 'cli'){
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
