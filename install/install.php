<?php
// Kept as a compatibility CLI entrypoint. Database migrations must never be
// triggerable through the public web server.
if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/update.php';
updateBot();
echo "Database migrations completed.\n";
?>
