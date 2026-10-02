<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Maatify\Paymob\ProviderVerification\Support\CaptureSession;
use Maatify\Paymob\ProviderVerification\Support\CapturingApiClient;
use Maatify\Paymob\ProviderVerification\Support\SemanticSanitizer;
use Maatify\Paymob\ProviderVerification\Support\VerificationConfig;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$captureSession = null;
$configurationDirectory = null;
$artifactPath = null;
$artifactDirectory = null;
$rawDirectory = null;
$rawCleaned = false;
$failure = null;

try {
    verify(extension_loaded('curl'), 'The cURL extension is not loaded.');
    CapturingApiClient::selfCheckTransportConfiguration();
    echo "PASS cURL transport configuration without network execution\n";

    $rawJson = <<<'JSON'
{
  "amount": 15000,
  "amount_cents": 15000,
  "currency": "EGP",
  "payment_status": "UNPAID",
  "pending": true,
  "success": false,
  "optional": null,
  "source_data": {"type": "wallet", "sub_type": "WALLET"},
  "data": {"klass": "Wallet", "message": "Transaction Created Successfully", "txn_response_code": "APPROVED"},
  "created_at": "2026-10-02T12:00:00.000000Z",
  "order": {"id": 123456, "payment_status": "UNPAID"},
  "shipping_data": {"order_id": 123456, "order": 123456, "integration_id": 73486},
  "payment_key_claims": {"order_id": 123456, "integration_id": 73486},
  "transaction": {"id": 887766, "source_id": 998877, "integration_id": 73486},
  "merchant_order_id": "private-merchant-reference-20261002",
  "customer": {
    "first_name": "Real Customer Name",
    "last_name": "Private Surname",
    "email": "person@example.org",
    "phone_number": "01012345678",
    "address": "Private Street 12"
  },
  "source": {"identifier": "01010101010", "subtype": "WALLET"},
  "api_key": "configured-api-key-example",
  "hmac_secret": "configured-hmac-secret-example",
  "token": "synthetic-provider-token-placeholder",
  "token_context": {"type": "wallet", "token": "nested-sensitive-token", "message": "Transaction Created Successfully"},
  "redirect_url": "https://accept.paymob.com/redirect/123456?token=synthetic-provider-token-placeholder&order_id=123456&merchant_order_id=private-merchant-reference-20261002&status=UNPAID",
  "details": {"values": [1, 1.5, true, false, null, {"enabled": true}]}
}
JSON;

    $raw = json_decode($rawJson);
    verify($raw instanceof stdClass, 'Synthetic source JSON did not decode to an object.');
    $sanitizer = new SemanticSanitizer(['configured-api-key-example', 'configured-hmac-secret-example'], '01010101010');
    $sanitizer->prime([$raw]);
    $safe = $sanitizer->sanitize($raw);

    verify($sanitizer->sameShape($raw, $safe), 'Sanitization changed a JSON container or scalar type.');
    verify($safe->amount === 15000 && is_int($safe->amount), 'Integer amount was not preserved.');
    verify($safe->amount_cents === 15000 && is_int($safe->amount_cents), 'Integer amount_cents was not preserved.');
    verify($safe->pending === true && $safe->success === false && $safe->optional === null, 'Boolean or null values changed.');
    verify($safe->details->values[1] === 1.5 && is_float($safe->details->values[1]), 'Float value was not preserved.');
    verify($safe->payment_status === 'UNPAID', 'payment_status semantic value changed.');
    verify($safe->source_data->type === 'wallet' && $safe->source_data->sub_type === 'WALLET', 'Wallet source semantics changed.');
    verify($safe->data->message === 'Transaction Created Successfully', 'Provider message semantic value changed.');
    verify($safe->created_at === '2026-10-02T12:00:00.000000Z', 'Timestamp value changed.');
    echo "PASS scalar types and nested containers\n";
    echo "PASS semantic preservation\n";

    verify($safe->customer->email === 'customer@example.test', 'Email was not replaced.');
    verify($safe->customer->phone_number === '+20000000000', 'Private phone was not replaced.');
    verify($safe->customer->first_name === '<SANITIZED_NAME>', 'Customer name was not replaced.');
    verify($safe->customer->address === '<SANITIZED_ADDRESS>', 'Customer address was not replaced.');
    verify($safe->source->identifier === '01010101010', 'Public wallet test input did not remain recognizable.');
    echo "PASS PII redaction and public wallet test input handling\n";

    verify($safe->token !== $raw->token, 'Provider token was not replaced.');
    verify($safe->token_context->type === 'wallet', 'Nested token metadata semantic type changed.');
    verify($safe->token_context->message === 'Transaction Created Successfully', 'Nested token metadata message changed.');
    verify($safe->token_context->token !== $raw->token_context->token, 'Nested provider token was not replaced.');
    verify($safe->api_key !== 'configured-api-key-example', 'Configured API key literal was not replaced.');
    verify($safe->hmac_secret !== 'configured-hmac-secret-example', 'Configured HMAC secret literal was not replaced.');
    verify(!$sanitizer->containsSensitiveValues($safe), 'A configured secret or detected sensitive value remains.');
    $safeJson = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(!str_contains($safeJson, $raw->token), 'Original provider token appears in sanitized JSON.');
    verify(!str_contains($safeJson, $raw->token_context->token), 'Nested provider token appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'configured-api-key-example'), 'Configured API key appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'configured-hmac-secret-example'), 'Configured HMAC secret appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'person@example.org'), 'Original email appears in sanitized JSON.');
    verify(!str_contains($safeJson, '01012345678'), 'Original private phone appears in sanitized JSON.');
    echo "PASS secret redaction and leak guard\n";

    $safeUrl = $safe->redirect_url;
    $safeUrlParts = parse_url($safeUrl);
    $rawUrlParts = parse_url($raw->redirect_url);
    verify(is_array($safeUrlParts) && is_array($rawUrlParts), 'Sanitized URL is invalid.');
    verify($safeUrlParts['scheme'] === $rawUrlParts['scheme'], 'URL scheme changed.');
    verify($safeUrlParts['path'] !== $rawUrlParts['path'], 'Account-specific URL path ID was not replaced.');
    parse_str((string)$safeUrlParts['query'], $safeQuery);
    parse_str((string)$rawUrlParts['query'], $rawQuery);
    verify(array_keys($safeQuery) === array_keys($rawQuery), 'URL query key names or structure changed.');
    verify($safeQuery['token'] !== $rawQuery['token'], 'URL secret token was not replaced.');
    verify($safeQuery['order_id'] === (string)$safe->order->id, 'URL order ID does not match its response ID mapping.');
    verify($safeQuery['merchant_order_id'] === $safe->merchant_order_id, 'URL merchant reference does not match its response reference mapping.');
    verify($safeQuery['status'] === 'UNPAID', 'URL semantic query value changed.');
    echo "PASS URL sanitization and query structure\n";

    verify($safe->order->id === $safe->shipping_data->order_id, 'Repeated order ID changed across fields.');
    verify($safe->order->id === $safe->shipping_data->order, 'Repeated order alias changed across fields.');
    verify($safe->order->id === $safe->payment_key_claims->order_id, 'Repeated order ID changed in claims.');
    verify($safe->shipping_data->integration_id === $safe->payment_key_claims->integration_id, 'Repeated integration ID changed in claims.');
    verify($safe->shipping_data->integration_id === $safe->transaction->integration_id, 'Repeated integration ID changed in transaction.');
    verify($safe->order->id !== $safe->transaction->id, 'Distinct order and transaction IDs collapsed.');
    verify($safe->transaction->id !== $safe->transaction->source_id, 'Distinct transaction and source IDs collapsed.');
    verify(is_int($safe->order->id) && $safe->order->id > 0, 'Sanitized numeric provider IDs are not positive integers.');
    echo "PASS repeated ID consistency and distinct-ID separation\n";

    $semanticDifferences = $sanitizer->semanticDifferences($raw, $safe);
    verify($semanticDifferences === [], 'Semantic-value verification reported a change.');
    $encoded = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(is_string(json_decode($encoded)) || json_last_error() === JSON_ERROR_NONE, 'Sanitized output is not valid JSON.');
    echo "PASS sanitized JSON validity\n";

    $configurationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymob-provider-config-check-' . bin2hex(random_bytes(8));
    verify(mkdir($configurationDirectory, 0700), 'Could not create a private synthetic configuration directory.');
    $configurationPath = $configurationDirectory . DIRECTORY_SEPARATOR . '.env';
    $configuration = "PAYMOB_API_KEY=self-check-api-key-value\nPAYMOB_BASE_URL=https://accept.paymob.com/api\n";
    verify(file_put_contents($configurationPath, $configuration) === strlen($configuration), 'Could not write a synthetic configuration file.');
    chmod($configurationPath, 0600);
    $optionalHmacConfig = VerificationConfig::load($configurationDirectory, 'auth');
    verify($optionalHmacConfig->hmacSecret === '', 'Auth verification required an absent HMAC secret.');
    verify(
        $optionalHmacConfig->configuredSecrets() === ['self-check-api-key-value'],
        'Configured secret list included an absent or empty HMAC value.',
    );
    echo "PASS scenario HMAC optionality and configured-secret filtering\n";

    $requestJson = <<<'JSON'
{
  "api_key": "configured-api-key-example",
  "source": {"type": "wallet", "subtype": "WALLET", "identifier": "01010101010"},
  "payment_token": "synthetic-request-payment-token",
  "amount_cents": 15000,
  "currency": "EGP",
  "expiration": 180,
  "integration_id": 876543,
  "billing_data": {"phone_number": "+201010101010", "email": "person@example.org"}
}
JSON;
    $responseJson = <<<'JSON'
{
  "success": false,
  "pending": true,
  "payment_status": "UNPAID",
  "token": "synthetic-provider-response-token",
  "order_id": 123456,
  "transaction_id": 887766,
  "integration_id": 876543,
  "customer": {"first_name": "Real Customer Name", "email": "person@example.org", "phone_number": "01012345678"},
  "source": {"subtype": "WALLET", "identifier": "01010101010"}
}
JSON;
    $request = json_decode($requestJson);
    $response = json_decode($responseJson);
    verify($request instanceof stdClass && $response instanceof stdClass, 'Synthetic exchange JSON did not decode to objects.');

    $captureSanitizer = new SemanticSanitizer(
        ['configured-api-key-example', 'configured-hmac-secret-example'],
        '01010101010',
    );
    $captureSession = new CaptureSession(dirname(__DIR__, 2));
    $rawDirectory = $captureSession->rawDirectory();
    $captureSession->captureExchange([
        'stage' => 'wallet-initiation',
        'method' => 'POST',
        'final_url_raw' => 'https://accept.paymob.com/api/acceptance/payments/pay',
        'request_header_names' => ['Content-Type'],
        'request_json_valid' => true,
        'request_body_structure' => [
            'api_key' => 'string',
            'source' => ['type' => 'string', 'subtype' => 'string', 'identifier' => 'string'],
            'payment_token' => 'string',
            'amount_cents' => 'int',
            'currency' => 'string',
            'expiration' => 'int',
            'integration_id' => 'int',
            'billing_data' => ['phone_number' => 'string', 'email' => 'string'],
        ],
        'request_query_structure' => null,
        'http_status' => 200,
        'transport_ok' => true,
        'curl_errno' => 0,
        'curl_error' => '',
        'response_header_names' => ['Content-Type'],
        'response_json_valid' => true,
        'response_top_level_type' => 'stdClass',
        'response_top_level_keys' => array_keys(get_object_vars($response)),
        'response_top_level_types' => array_map(
            static fn(mixed $value): string => get_debug_type($value),
            get_object_vars($response),
        ),
    ], 'https://accept.paymob.com/api/acceptance/payments/pay', $requestJson, $responseJson);

    $captureReport = $captureSession->buildSanitizedReport($captureSanitizer);
    verify(count($captureReport['exchanges']) === 1, 'Synthetic sanitized exchange was not captured.');
    $sanitizedExchange = $captureReport['exchanges'][0];
    verify(isset($sanitizedExchange['sanitized_request']), 'Sanitized request evidence is missing.');
    verify($sanitizedExchange['sanitized_request']->source->subtype === 'WALLET', 'Sanitized request lost the wallet subtype.');
    verify($sanitizedExchange['sanitized_request']->amount_cents === 15000, 'Sanitized request changed amount_cents.');
    verify($sanitizedExchange['sanitized_request']->currency === 'EGP', 'Sanitized request changed currency.');
    verify($sanitizedExchange['sanitized_request']->expiration === 180, 'Sanitized request changed expiration.');
    verify(
        $sanitizedExchange['sanitized_request']->integration_id === $sanitizedExchange['sanitized_response_fixture_candidate']->integration_id,
        'Sanitized request and response integration IDs do not share a stable mapping.',
    );
    verify(
        $sanitizedExchange['sanitized_request']->payment_token !== 'synthetic-request-payment-token',
        'Synthetic request payment token was not redacted.',
    );
    echo "PASS sanitized request evidence and semantic preservation\n";

    $fullReport = [
        'result' => 'PASS',
        'scenario' => 'wallet',
        'service_results' => ['wallet' => ['success' => false, 'pending' => true]],
        'capture' => $captureReport,
    ];
    verify(!$captureSanitizer->containsSensitiveValues($fullReport), 'Full synthetic report failed the pre-persistence leak guard.');

    $artifact = $captureSession->persistSanitizedArtifact($fullReport);
    $artifactPath = $artifact['path'];
    $artifactDirectory = dirname($artifactPath);
    $repositoryPath = realpath(dirname(__DIR__, 2));
    $temporaryRoot = realpath(sys_get_temp_dir());
    $artifactRealPath = realpath($artifactPath);
    verify(is_file($artifactPath), 'Sanitized artifact was not persisted.');
    verify($repositoryPath !== false && $temporaryRoot !== false && $artifactRealPath !== false, 'Artifact or root path could not be resolved.');
    verify(
        str_starts_with($artifactRealPath, $temporaryRoot . DIRECTORY_SEPARATOR)
        && !str_starts_with($artifactRealPath, $repositoryPath . DIRECTORY_SEPARATOR),
        'Sanitized artifact is not outside the repository under the operating-system temporary directory.',
    );
    verify(
        basename($artifactDirectory) === 'sanitized'
        && basename(dirname($artifactDirectory)) === 'maatify-paymob-provider-verification',
        'Sanitized artifact is not under the expected temporary namespace.',
    );
    verify(
        preg_match('/^run-\d{8}T\d{6}Z-[a-f0-9]{20}-sanitized-report\.json$/', basename($artifactPath)) === 1,
        'Sanitized artifact name does not contain its unique run identity.',
    );
    verify((fileperms($artifactDirectory) & 0777) === 0700, 'Sanitized artifact directory permissions are not 0700.');
    verify((fileperms($artifactPath) & 0777) === 0600, 'Sanitized artifact file permissions are not 0600.');
    $artifactBytes = file_get_contents($artifactPath);
    verify(is_string($artifactBytes), 'Sanitized artifact could not be read back.');
    verify(strlen($artifactBytes) === $artifact['bytes'] && filesize($artifactPath) === $artifact['bytes'], 'Sanitized artifact byte count does not match.');
    verify(hash_equals($artifact['sha256'], hash('sha256', $artifactBytes)), 'Sanitized artifact SHA-256 does not match.');
    $decodedArtifact = json_decode($artifactBytes, true, 512, JSON_THROW_ON_ERROR);
    verify(is_array($decodedArtifact), 'Sanitized artifact JSON did not decode to an object or array.');
    $readBackReport = $captureSession->readSanitizedArtifact();
    $readBackExchange = $readBackReport['capture']['exchanges'][0];
    verify(isset($readBackExchange['sanitized_request']), 'Read-back report omitted sanitized_request.');
    verify($readBackExchange['sanitized_request']['source']['subtype'] === 'WALLET', 'Read-back request lost its wallet subtype.');
    verify($readBackExchange['sanitized_request']['amount_cents'] === 15000, 'Read-back request changed its amount.');
    verify($readBackExchange['sanitized_request']['currency'] === 'EGP', 'Read-back request changed its currency.');
    verify($readBackExchange['sanitized_request']['expiration'] === 180, 'Read-back request changed its expiration.');
    verify(
        $readBackExchange['sanitized_request']['integration_id'] === $readBackExchange['sanitized_response_fixture_candidate']['integration_id'],
        'Read-back request and response integration ID mappings differ.',
    );
    verify(
        $readBackExchange['sanitized_request']['payment_token'] !== 'synthetic-request-payment-token',
        'Read-back request payment token was not redacted.',
    );
    verify(
        $readBackExchange['sanitized_response_fixture_candidate']['payment_status'] === 'UNPAID'
        && $readBackExchange['sanitized_response_fixture_candidate']['success'] === false
        && $readBackExchange['sanitized_response_fixture_candidate']['pending'] === true,
        'Read-back report omitted sanitized provider response semantics.',
    );
    verify(!$captureSanitizer->containsSensitiveValues($readBackReport), 'Persisted full report failed the post-persistence leak guard.');
    $captureSession->markSanitizedArtifactSafe();
    echo "PASS durable sanitized artifact, permissions, bytes, hash, read-back, JSON, and leak guard\n";

    verify(is_dir($rawDirectory), 'Raw evidence directory is missing before cleanup.');
    verify(count(glob($rawDirectory . DIRECTORY_SEPARATOR . '*') ?: []) === 3, 'Raw request/response evidence is incomplete before cleanup.');
    verify($captureSession->cleanupRawEvidence(), 'Raw evidence cleanup failed.');
    $rawCleaned = true;
    verify(!file_exists($rawDirectory), 'Raw evidence directory remains after cleanup.');
    verify(is_file($artifactPath), 'Sanitized artifact was not retained after raw cleanup.');
    verify(!$captureSanitizer->containsSensitiveValues($readBackReport), 'Retained sanitized artifact contains synthetic secrets or PII.');
    foreach ([
        'configured-api-key-example',
        'synthetic-request-payment-token',
        'synthetic-provider-response-token',
        'person@example.org',
        '01012345678',
        'Real Customer Name',
    ] as $syntheticSensitiveValue) {
        verify(!str_contains($artifactBytes, $syntheticSensitiveValue), 'Retained sanitized artifact contains a synthetic secret or PII literal.');
    }
    echo "PASS raw evidence retained before cleanup and removed afterward; sanitized artifact retained\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($captureSession instanceof CaptureSession && !$rawCleaned && $rawDirectory !== null && is_dir($rawDirectory)) {
        try {
            if (!$captureSession->cleanupRawEvidence()) {
                $failure ??= new RuntimeException('Self-check raw evidence cleanup failed.');
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
    }

    if ($artifactPath !== null && is_file($artifactPath) && !unlink($artifactPath)) {
        $failure ??= new RuntimeException('Self-check sanitized artifact cleanup failed.');
    }
    if ($artifactPath !== null && file_exists($artifactPath)) {
        $failure ??= new RuntimeException('Self-check sanitized artifact remains after final cleanup.');
    }
    if ($artifactDirectory !== null && is_dir($artifactDirectory)) {
        $entries = scandir($artifactDirectory);
        if (is_array($entries) && count($entries) === 2 && !rmdir($artifactDirectory)) {
            $failure ??= new RuntimeException('Empty self-check sanitized artifact directory cleanup failed.');
        }
    }
    if ($configurationDirectory !== null && is_file($configurationDirectory . DIRECTORY_SEPARATOR . '.env')) {
        if (!unlink($configurationDirectory . DIRECTORY_SEPARATOR . '.env')) {
            $failure ??= new RuntimeException('Self-check synthetic configuration cleanup failed.');
        }
    }
    if ($configurationDirectory !== null && is_dir($configurationDirectory) && !rmdir($configurationDirectory)) {
        $failure ??= new RuntimeException('Self-check synthetic configuration directory cleanup failed.');
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'SELF-CHECK: FAIL (' . get_class($failure) . '): ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "SELF-CHECK: PASS\n";
exit(0);
