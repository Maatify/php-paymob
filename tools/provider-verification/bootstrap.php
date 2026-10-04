<?php

declare(strict_types=1);

use Maatify\Paymob\ProviderVerification\Support\VerificationContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

if (ini_set('zend.exception_ignore_args', '1') === false
    || ini_get('zend.exception_ignore_args') !== '1') {
    fwrite(STDERR, "PROVIDER VERIFICATION FAIL CLOSED: exception argument diagnostics could not be disabled.\n");
    exit(2);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }

    $label = $severity === E_DEPRECATED ? 'E_DEPRECATED' : 'E_USER_DEPRECATED';
    fwrite(STDERR, sprintf("%s %s:%d %s\n", $label, basename($file), $line, $message));
    return true;
});

$repositoryRoot = dirname(__DIR__, 2);

require_once $repositoryRoot . '/vendor/autoload.php';
require_once __DIR__ . '/Support/VerificationConfig.php';
require_once __DIR__ . '/Support/CaptureSession.php';
require_once __DIR__ . '/Support/SemanticSanitizer.php';
require_once __DIR__ . '/Support/ProviderAttemptStageClassifier.php';
require_once __DIR__ . '/Support/RetainedRunRecovery.php';

/** Run one explicit provider verification scenario and emit its safe report. */
function runProviderVerification(string $scenario, ?string $paymentMethod = null): int
{
    require_once __DIR__ . '/Support/CapturingApiClient.php';
    require_once __DIR__ . '/Support/VerificationContext.php';

    return VerificationContext::run($scenario, $paymentMethod, dirname(__DIR__, 2));
}
