<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = $argv[1] ?? '';
if (!in_array($method, ['card', 'kiosk', 'wallet'], true)) {
    fwrite(STDERR, "Usage: php tools/provider-verification/payment-key.php card|kiosk|wallet\n");
    exit(2);
}

exit(runProviderVerification('payment-key', $method));
