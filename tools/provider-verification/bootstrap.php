<?php

declare(strict_types=1);

use Maatify\Paymob\ProviderVerification\Support\VerificationContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$repositoryRoot = dirname(__DIR__, 2);

require_once $repositoryRoot . '/vendor/autoload.php';
require_once __DIR__ . '/Support/VerificationConfig.php';
require_once __DIR__ . '/Support/CaptureSession.php';
require_once __DIR__ . '/Support/SemanticSanitizer.php';
require_once __DIR__ . '/Support/CapturingApiClient.php';
require_once __DIR__ . '/Support/VerificationContext.php';

/** Run one explicit provider verification scenario and emit its safe report. */
function runProviderVerification(string $scenario, ?string $paymentMethod = null): int
{
    return VerificationContext::run($scenario, $paymentMethod, dirname(__DIR__, 2));
}
