<?php
$files = [
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\PlatformPayoutSetting.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\PlatformCommissionRate.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\PayoutItem.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\PayoutAttempt.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\PaymentAttempt.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\OrderItem.php',
    'd:\next-technical-assessment\eventhub\apps\core-api\app\Models\AuditLog.php',
];
foreach ($files as $file) {
    $content = file_get_contents($file);
    $content = str_replace('public $timestamps = false;', 'const UPDATED_AT = null;', $content);
    file_put_contents($file, $content);
}
