<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Maatify\Paymob\ProviderVerification\Support\CaptureSession;
use Maatify\Paymob\ProviderVerification\Support\CapturingApiClient;
use Maatify\Paymob\ProviderVerification\Support\RetainedRunRecovery;
use Maatify\Paymob\ProviderVerification\Support\SemanticSanitizer;
use Maatify\Paymob\ProviderVerification\Support\VerificationConfig;

require_once __DIR__ . '/Support/CapturingApiClient.php';

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
$failureArtifactPath = null;
$recoveryArtifactPath = null;
$recoveryFailureArtifactPath = null;
$syntheticRecoveryDirectory = null;
$syntheticRecoveryFailureDirectory = null;
$syntheticRecoveryConfigDirectory = null;
$rawCleaned = false;
$failure = null;

try {
    verify(ini_get('zend.exception_ignore_args') === '1', 'Exception argument diagnostics are not disabled.');
    echo "PASS zend.exception_ignore_args fail-closed setting\n";

    $bootstrapPath = realpath(__DIR__ . '/bootstrap.php');
    verify(is_string($bootstrapPath), 'Bootstrap path could not be resolved.');
    $deprecationCode = 'require ' . var_export($bootstrapPath, true)
        . '; function deprecation_probe($marker) { trigger_error("synthetic deprecation probe", E_USER_DEPRECATED); }'
        . ' deprecation_probe("argument-secret-marker");';
    $process = proc_open([PHP_BINARY, '-r', $deprecationCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    verify(is_resource($process), 'Could not launch the safe diagnostic self-check subprocess.');
    fclose($pipes[0]);
    $deprecationStdout = stream_get_contents($pipes[1]);
    $deprecationStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $deprecationExit = proc_close($process);
    verify($deprecationExit === 0 && $deprecationStdout === '', 'Deprecation probe did not complete safely.');
    verify(
        is_string($deprecationStderr)
        && str_contains($deprecationStderr, 'E_USER_DEPRECATED')
        && str_contains($deprecationStderr, 'synthetic deprecation probe')
        && preg_match('/^E_USER_DEPRECATED [^:\r\n]+:\d+ synthetic deprecation probe\r?\n$/D', $deprecationStderr) === 1
        && !str_contains($deprecationStderr, 'argument-secret-marker')
        && !str_contains($deprecationStderr, 'Stack trace'),
        'Deprecation handler exposed arguments or a stack trace.',
    );

    $exceptionCode = 'require ' . var_export($bootstrapPath, true)
        . '; function exception_probe($marker) { try { throw new RuntimeException("synthetic exception probe"); }'
        . ' catch (Throwable $exception) { echo json_encode($exception->getTrace()); } }'
        . ' exception_probe("argument-secret-marker");';
    $process = proc_open([PHP_BINARY, '-r', $exceptionCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    verify(is_resource($process), 'Could not launch the exception argument self-check subprocess.');
    fclose($pipes[0]);
    $exceptionStdout = stream_get_contents($pipes[1]);
    $exceptionStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exceptionExit = proc_close($process);
    verify(
        $exceptionExit === 0 && $exceptionStderr === '' && is_string($exceptionStdout)
        && !str_contains($exceptionStdout, 'argument-secret-marker')
        && !str_contains($exceptionStdout, 'args'),
        'zend.exception_ignore_args did not remove arguments from Throwable trace metadata.',
    );
    echo "PASS argument-free exception and deprecation diagnostics\n";

    $curlCloseName = 'curl_' . 'close';
    $harnessFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($harnessFiles as $harnessFile) {
        if (!$harnessFile->isFile() || $harnessFile->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($harnessFile->getPathname());
        verify(is_string($source), 'A harness PHP source file could not be read for the lifecycle check.');
        verify(
            preg_match('/\\b' . preg_quote($curlCloseName, '/') . '\\s*\\(/', $source) !== 1,
            'Harness-owned deprecated cURL close call remains.',
        );
    }
    echo "PASS cURL handle lifecycle scan\n";

    $transportSource = file_get_contents(__DIR__ . '/Support/CapturingApiClient.php');
    verify(is_string($transportSource), 'Transport source could not be read for the offline self-check.');
    $selfCheckMethodMatch = preg_match(
        '/public static function selfCheckTransportConfiguration\(\): void\s*\{(.*?)\n    \}/s',
        $transportSource,
        $selfCheckMethod,
    );
    $curlExecName = 'curl_' . 'exec';
    verify(
        $selfCheckMethodMatch === 1
        && preg_match('/\\b' . preg_quote($curlExecName, '/') . '\\s*\\(/', $selfCheckMethod[1]) !== 1,
        'Transport self-check contains a cURL execution call.',
    );

    verify(extension_loaded('curl'), 'The cURL extension is not loaded.');
    CapturingApiClient::selfCheckTransportConfiguration();
    echo "PASS cURL transport configuration without network execution\n";

    $rawJson = <<<'JSON'
{
  "amount": 15000,
  "amount_cents": 15000,
  "id": 900001,
  "currency": "EGP",
  "payment_status": "UNPAID",
  "message": "Transaction Created Successfully",
  "verification_label": "Provider Verification completed",
  "digit_context": "release 1 is ready",
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
  "billing_data": {
    "first_name": "Provider",
    "last_name": "Verification",
    "building": "1",
    "floor": "1",
    "apartment": "1",
    "postal_code": "00000",
    "street": "Synthetic Test Street",
    "city": "Test City",
    "state": "Test State",
    "email": "private-person@example.org",
    "phone_number": "+201234567890"
  },
  "source": {"identifier": "01010101010", "subtype": "WALLET"},
  "api_key": "configured-api-key-example",
  "auth_token": "synthetic-auth-token-value",
  "payment_token": "synthetic-payment-token-value",
  "authorization": "Bearer synthetic-authorization-credential",
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
    verify($safe->created_at === '2026-10-02T12:00:00.000000Z', 'Timestamp value changed.');
    verify($safe->source_data->type === 'wallet' && $safe->source_data->sub_type === 'WALLET', 'Wallet source semantics changed.');
    verify($safe->data->message === 'Transaction Created Successfully', 'Provider message semantic value changed.');
    verify($safe->message === 'Transaction Created Successfully', 'Top-level provider message semantic value changed.');
    verify($safe->verification_label === 'Provider Verification completed', 'Non-PII Provider/Verification text changed.');
    verify($safe->digit_context === 'release 1 is ready', 'Unrelated string containing digit 1 changed.');
    verify($safe->id !== 900001 && is_int($safe->id), 'Numeric ID containing digit 1 was not mapped normally.');
    echo "PASS scalar types and nested containers\n";
    echo "PASS semantic preservation\n";

    verify($safe->customer->email === 'customer@example.test', 'Email was not replaced.');
    verify($safe->customer->phone_number === '+20000000000', 'Private phone was not replaced.');
    verify($safe->customer->first_name === '<SANITIZED_NAME>', 'Customer name was not replaced.');
    verify($safe->customer->address === '<SANITIZED_ADDRESS>', 'Customer address was not replaced.');
    verify($safe->billing_data->first_name === '<SANITIZED_NAME>', 'Billing first_name was not redacted.');
    verify($safe->billing_data->last_name === '<SANITIZED_NAME>', 'Billing last_name was not redacted.');
    verify($safe->billing_data->building !== '1', 'Billing building value 1 was not redacted.');
    verify($safe->billing_data->floor !== '1', 'Billing floor value 1 was not redacted.');
    verify($safe->billing_data->apartment !== '1', 'Billing apartment value 1 was not redacted.');
    verify($safe->billing_data->postal_code === '<SANITIZED_ADDRESS>', 'Billing postal_code was not redacted.');
    verify($safe->billing_data->street === '<SANITIZED_ADDRESS>', 'Billing street was not redacted.');
    verify($safe->billing_data->city === '<SANITIZED_ADDRESS>', 'Billing city was not redacted.');
    verify($safe->billing_data->state === '<SANITIZED_ADDRESS>', 'Billing state was not redacted.');
    verify($safe->billing_data->email === 'customer@example.test', 'Billing email was not redacted.');
    verify($safe->billing_data->phone_number === '+20000000000', 'Billing phone was not redacted.');
    verify($safe->source->identifier === '01010101010', 'Public wallet test input did not remain recognizable.');
    verify(
        $sanitizer->sensitiveFieldDifferences($raw, $safe) === [],
        'Explicit sensitive-field validation found unredacted PII or credentials.',
    );
    $unsafePii = clone $safe;
    $unsafePii->billing_data = clone $safe->billing_data;
    $unsafePii->billing_data->building = '1';
    verify(
        $sanitizer->sensitiveFieldDifferences($raw, $unsafePii) === ['$.billing_data.building'],
        'Sensitive-field validation did not report an unchanged short PII value by path.',
    );
    $unsafePii->billing_data->building = '<SANITIZED_ADDRESS>1';
    verify(
        $sanitizer->sensitiveFieldDifferences($raw, $unsafePii) === ['$.billing_data.building'],
        'Sensitive-field validation accepted a noncanonical partial redaction.',
    );
    echo "PASS PII redaction and public wallet test input handling\n";

    verify($safe->token !== $raw->token, 'Provider token was not replaced.');
    verify($safe->auth_token !== 'synthetic-auth-token-value', 'Auth token was not replaced.');
    verify($safe->payment_token !== 'synthetic-payment-token-value', 'Payment token was not replaced.');
    verify($safe->authorization !== 'Bearer synthetic-authorization-credential', 'Authorization credentials were not replaced.');
    verify($safe->token_context->type === 'wallet', 'Nested token metadata semantic type changed.');
    verify($safe->token_context->message === 'Transaction Created Successfully', 'Nested token metadata message changed.');
    verify($safe->token_context->token !== $raw->token_context->token, 'Nested provider token was not replaced.');
    verify($safe->api_key !== 'configured-api-key-example', 'Configured API key literal was not replaced.');
    verify($safe->hmac_secret !== 'configured-hmac-secret-example', 'Configured HMAC secret literal was not replaced.');
    verify(!$sanitizer->containsSensitiveValues($safe), 'A configured secret or detected sensitive value remains.');
    foreach ([
        'configured API key' => 'configured-api-key-example',
        'auth token' => 'synthetic-auth-token-value',
        'payment token' => 'synthetic-payment-token-value',
        'HMAC secret' => 'configured-hmac-secret-example',
        'authorization credential' => 'Bearer synthetic-authorization-credential',
    ] as $secretName => $secretValue) {
        verify(
            $sanitizer->containsSensitiveValues(['unrelated_safe_field' => $secretValue]),
            ucfirst($secretName) . ' reinsertion was not rejected by the global leak guard.',
        );
    }
    verify(
        $sanitizer->containsSensitiveValues([
            'unrelated_safe_field' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signature-payload-1234567890',
        ]),
        'An unseeded embedded JWT-like token was not rejected by the leak guard.',
    );
    verify(
        $sanitizer->containsSensitiveValues(['diagnostic' => 'embedded private-person@example.org value']),
        'Embedded email leak outside the original field was not rejected.',
    );
    verify(
        $sanitizer->containsSensitiveValues(['diagnostic' => 'embedded 01012345678 value']),
        'Embedded phone leak outside the original field was not rejected.',
    );
    $safeHashMetadata = [
        'amount_cents' => 15000,
        'id' => $safe->id,
        'timestamp' => $safe->created_at,
        'sha256' => hash('sha256', 'safe deterministic metadata'),
        'message' => 'Transaction Created Successfully',
        'note' => 'Provider Verification completed at step 1',
    ];
    verify(
        !$sanitizer->containsSensitiveValues($safeHashMetadata),
        'Unrelated amount, ID, timestamp, semantic text, or SHA-256 metadata caused a false leak.',
    );
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
  "billing_data": {
    "first_name": "Provider",
    "last_name": "Verification",
    "building": "1",
    "floor": "1",
    "apartment": "1",
    "postal_code": "00000",
    "street": "Synthetic Test Street",
    "city": "Test City",
    "state": "Test State",
    "phone_number": "+201010101010",
    "email": "person@example.org"
  }
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
            'billing_data' => [
                'first_name' => 'string',
                'last_name' => 'string',
                'building' => 'string',
                'floor' => 'string',
                'apartment' => 'string',
                'postal_code' => 'string',
                'street' => 'string',
                'city' => 'string',
                'state' => 'string',
                'phone_number' => 'string',
                'email' => 'string',
            ],
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
        $sanitizedExchange['sanitized_request']->billing_data->building !== '1'
        && $sanitizedExchange['sanitized_request']->billing_data->floor !== '1'
        && $sanitizedExchange['sanitized_request']->billing_data->apartment !== '1',
        'CaptureSession did not redact short billing PII values.',
    );
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

    $failureDiagnostic = $captureSession->failureDiagnostic(
        'self-check-failure',
        new RuntimeException('synthetic safe failure detail'),
        ['configured-api-key-example', 'configured-hmac-secret-example'],
    );
    $failureSanitizer = new SemanticSanitizer(
        [
            'configured-api-key-example',
            'configured-hmac-secret-example',
            'synthetic-request-payment-token',
            'synthetic-provider-response-token',
            'synthetic-auth-token-value',
        ],
        '01010101010',
    );
    $failureSanitizer->prime([$request, $response]);
    verify(
        !$failureSanitizer->containsSensitiveValues($failureDiagnostic),
        'Synthetic failure diagnostic contains a secret.',
    );
    $failureArtifact = CaptureSession::persistJsonArtifact(
        $failureDiagnostic,
        basename($rawDirectory) . '-failure-diagnostic.json',
    );
    $failureArtifactPath = $failureArtifact['path'];
    $failureBytes = file_get_contents($failureArtifactPath);
    verify(is_string($failureBytes), 'Synthetic failure artifact could not be read back.');
    verify(strlen($failureBytes) === $failureArtifact['bytes'], 'Synthetic failure artifact byte count does not match.');
    verify(hash_equals($failureArtifact['sha256'], hash('sha256', $failureBytes)), 'Synthetic failure artifact SHA-256 does not match.');
    verify((fileperms(dirname($failureArtifactPath)) & 0777) === 0700, 'Failure artifact directory mode is not 0700.');
    verify((fileperms($failureArtifactPath) & 0777) === 0600, 'Failure artifact file mode is not 0600.');
    $persistedFailure = json_decode($failureBytes, true, 512, JSON_THROW_ON_ERROR);
    verify(is_array($persistedFailure), 'Synthetic failure artifact JSON did not decode.');
    verify(!$failureSanitizer->containsSensitiveValues($persistedFailure), 'Persisted failure artifact contains a secret.');
    verify(
        isset($persistedFailure['failing_stage'], $persistedFailure['exception_class'], $persistedFailure['captured_attempts'])
        && count($persistedFailure['captured_attempts']) === 1,
        'Synthetic failure artifact omitted stage, exception, or captured attempt metadata.',
    );
    $failureStdoutEquivalent = [
        'result' => $persistedFailure['result'],
        'failing_stage' => $persistedFailure['failing_stage'],
        'exception_class' => $persistedFailure['exception_class'],
        'exception_message' => $persistedFailure['exception_message'],
        'failure_artifact_path' => $failureArtifact['path'],
        'failure_artifact_bytes' => $failureArtifact['bytes'],
        'failure_artifact_sha256' => $failureArtifact['sha256'],
        'raw_evidence_retained' => $persistedFailure['raw_evidence_retained'],
        'raw_storage_directory' => $persistedFailure['raw_storage_directory'],
        'captured_attempt_count' => count($persistedFailure['captured_attempts']),
    ];
    verify(!array_key_exists('captured_attempts', $failureStdoutEquivalent), 'Failure stdout summary contains the full attempt list.');
    verify(!$failureSanitizer->containsSensitiveValues($failureStdoutEquivalent), 'Failure stdout summary contains a secret.');
    echo "PASS durable failure diagnostic, concise handoff, bytes, hash, read-back, JSON, and leak guard\n";

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

    $recoverySource = file_get_contents(__DIR__ . '/Support/RetainedRunRecovery.php');
    $recoveryEntryPoint = file_get_contents(__DIR__ . '/recover.php');
    verify(is_string($recoverySource) && is_string($recoveryEntryPoint), 'Recovery source could not be inspected.');
    foreach (['curl_' . 'init', 'curl_' . 'exec', 'ApiClient' . 'Interface', 'Auth' . 'Service', 'Order' . 'Service'] as $forbiddenCall) {
        verify(
            !str_contains($recoverySource, $forbiddenCall) && !str_contains($recoveryEntryPoint, $forbiddenCall),
            'Offline recovery source contains a provider-network dependency.',
        );
    }

    $temporaryRoot = realpath(sys_get_temp_dir());
    verify(is_string($temporaryRoot), 'Operating-system temporary directory could not be resolved.');
    $privateNamespace = $temporaryRoot . DIRECTORY_SEPARATOR . 'maatify-paymob-provider-verification';
    if (!is_dir($privateNamespace)) {
        verify(mkdir($privateNamespace, 0700), 'Private evidence namespace could not be created for recovery self-check.');
        chmod($privateNamespace, 0700);
    }
    $syntheticRecoveryDirectory = $privateNamespace . DIRECTORY_SEPARATOR . 'run-self-check-' . bin2hex(random_bytes(6));
    verify(mkdir($syntheticRecoveryDirectory, 0700), 'Synthetic retained run could not be created.');
    chmod($syntheticRecoveryDirectory, 0700);

    $syntheticRecoveryConfigDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymob-recovery-config-' . bin2hex(random_bytes(6));
    verify(mkdir($syntheticRecoveryConfigDirectory, 0700), 'Synthetic recovery configuration directory could not be created.');
    $recoveryConfigPath = $syntheticRecoveryConfigDirectory . DIRECTORY_SEPARATOR . '.env';
    $recoveryConfig = "PAYMOB_API_KEY=recovery-self-check-api-secret\n"
        . "PAYMOB_HMAC_SECRET=recovery-self-check-hmac-secret\n"
        . "PAYMOB_BASE_URL=https://accept.paymob.com/api\n"
        . "PAYMOB_INTEGRATION_ID_WALLET=73486\n"
        . "PAYMOB_TEST_WALLET_MSISDN=01010101010\n";
    verify(file_put_contents($recoveryConfigPath, $recoveryConfig) === strlen($recoveryConfig), 'Synthetic recovery configuration could not be written.');
    chmod($recoveryConfigPath, 0600);

    $syntheticPaymentKeyRequest = json_encode([
        'auth_token' => 'recovery-self-check-auth-token',
        'order_id' => 123456,
        'integration_id' => 73486,
        'amount_cents' => 15000,
        'currency' => 'EGP',
        'created_at' => '2026-10-03T10:01:00Z',
        'verification_label' => 'Provider Verification step 1',
        'billing_data' => [
            'first_name' => 'Provider',
            'last_name' => 'Verification',
            'building' => '1',
            'floor' => '1',
            'apartment' => '1',
            'postal_code' => '00000',
            'street' => 'Synthetic Test Street',
            'city' => 'Test City',
            'state' => 'Test State',
            'email' => 'private-person@example.org',
            'phone_number' => '+201234567890',
        ],
    ], JSON_THROW_ON_ERROR);
    $syntheticExchanges = [
        [
            'https://accept.paymob.com/api/auth/tokens',
            '{"api_key":"recovery-self-check-api-secret"}',
            '{"token":"recovery-self-check-auth-token","profile":{"id":456789,"email":"recovery-person@example.org"},"issued_at":"2026-10-03T10:00:00Z"}',
        ],
        [
            'https://accept.paymob.com/api/ecommerce/orders',
            '{"amount_cents":15000,"currency":"EGP","merchant_order_id":"recovery-private-order-ref","items":[{"name":"Synthetic wallet order","amount_cents":15000,"quantity":1}]}',
            '{"id":123456,"merchant_order_id":"recovery-private-order-ref","payment_status":"UNPAID","created_at":"2026-10-03T10:01:00Z"}',
        ],
        [
            'https://accept.paymob.com/api/acceptance/payment_keys',
            $syntheticPaymentKeyRequest,
            '{"token":"recovery-self-check-payment-token","order_id":123456,"integration_id":73486,"currency":"EGP","status":"issued"}',
        ],
        [
            'https://accept.paymob.com/api/acceptance/payments/pay',
            '{"source":{"identifier":"01010101010","subtype":"WALLET"},"payment_token":"recovery-self-check-payment-token"}',
            '{"success":false,"pending":true,"payment_status":"UNPAID","order_id":123456,"transaction_id":887766,"integration_id":73486,"merchant_order_id":"recovery-private-order-ref","source":{"identifier":"01010101010","subtype":"WALLET"},"created_at":"2026-10-03T10:02:00Z","message":"Transaction Created Successfully"}',
        ],
    ];
    foreach ($syntheticExchanges as $index => [$url, $requestBody, $responseBody]) {
        $prefix = str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT);
        foreach ([
            $prefix . '-request-url.txt' => $url,
            $prefix . '-request-body.bin' => $requestBody,
            $prefix . '-response-body.bin' => $responseBody,
        ] as $fileName => $contents) {
            $path = $syntheticRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
            verify(file_put_contents($path, $contents, LOCK_EX) === strlen($contents), 'Synthetic raw exchange file could not be written.');
            chmod($path, 0600);
        }
    }

    $sourceSnapshot = [];
    foreach (scandir($syntheticRecoveryDirectory) ?: [] as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }
        $sourcePath = $syntheticRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
        $sourceSnapshot[$fileName] = [filesize($sourcePath), hash_file('sha256', $sourcePath), fileperms($sourcePath) & 0777];
    }

    $recoveryResult = RetainedRunRecovery::recover($syntheticRecoveryConfigDirectory, $syntheticRecoveryDirectory, 'wallet');
    verify($recoveryResult['result'] === 'PASS', 'Synthetic offline recovery failed.');
    verify($recoveryResult['exchange_count'] === 4, 'Synthetic recovery did not discover four triplets.');
    verify(
        $recoveryResult['recovered_stages'] === ['auth', 'order', 'payment-key-wallet', 'wallet-initiation'],
        'Synthetic recovery derived an incorrect stage sequence.',
    );
    verify($recoveryResult['source_raw_retained'] === true, 'Recovery did not retain the synthetic source run.');
    $recoveryReport = $recoveryResult['report'];
    verify(
        $recoveryReport['source']['exchange_count'] === 4
        && $recoveryReport['source']['source_raw_retained'] === true,
        'Recovery report omitted source retention or exchange count.',
    );
    $recoveredWallet = $recoveryReport['exchanges'][3];
    $recoveredPaymentKey = $recoveryReport['exchanges'][2];
    verify($recoveredWallet['method'] === null, 'Recovery invented an unavailable request method.');
    foreach (['http_status', 'transport_ok', 'curl_errno', 'curl_error', 'request_header_names', 'response_header_names'] as $unavailableField) {
        verify($recoveredWallet[$unavailableField] === null, 'Recovery invented unavailable runtime metadata.');
    }
    verify(
        $recoveredWallet['metadata_source'] === 'unavailable_from_retained_raw',
        'Recovery omitted the unavailable metadata source marker.',
    );
    verify(
        $recoveredWallet['sanitized_request']['source']['identifier'] === '01010101010',
        'Recovery did not preserve the public wallet test input.',
    );
    $recoveredBillingData = $recoveredPaymentKey['sanitized_request']['billing_data'];
    verify(
        $recoveredBillingData['first_name'] === '<SANITIZED_NAME>'
        && $recoveredBillingData['last_name'] === '<SANITIZED_NAME>'
        && $recoveredBillingData['building'] !== '1'
        && $recoveredBillingData['floor'] !== '1'
        && $recoveredBillingData['apartment'] !== '1'
        && $recoveredBillingData['postal_code'] === '<SANITIZED_ADDRESS>'
        && $recoveredBillingData['street'] === '<SANITIZED_ADDRESS>'
        && $recoveredBillingData['city'] === '<SANITIZED_ADDRESS>'
        && $recoveredBillingData['state'] === '<SANITIZED_ADDRESS>'
        && $recoveredBillingData['email'] === 'customer@example.test'
        && $recoveredBillingData['phone_number'] === '+20000000000',
        'Offline recovery did not redact the complete synthetic payment-key billing PII shape.',
    );
    verify(
        $recoveredPaymentKey['sanitized_request']['amount_cents'] === 15000
        && $recoveredPaymentKey['sanitized_request']['created_at'] === '2026-10-03T10:01:00Z'
        && $recoveredPaymentKey['sanitized_request']['verification_label'] === 'Provider Verification step 1',
        'Offline recovery changed unrelated amount, timestamp, or Provider/Verification context.',
    );
    verify(
        $recoveredWallet['sanitized_response_fixture_candidate']['success'] === false
        && $recoveredWallet['sanitized_response_fixture_candidate']['pending'] === true
        && $recoveredWallet['sanitized_response_fixture_candidate']['payment_status'] === 'UNPAID'
        && $recoveredWallet['sanitized_response_fixture_candidate']['message'] === 'Transaction Created Successfully',
        'Recovery changed wallet response semantics.',
    );
    $orderResponse = $recoveryReport['exchanges'][1]['sanitized_response_fixture_candidate'];
    $paymentKeyRequest = $recoveryReport['exchanges'][2]['sanitized_request'];
    verify(
        $orderResponse['id'] === $paymentKeyRequest['order_id']
        && $paymentKeyRequest['order_id'] === $recoveredWallet['sanitized_response_fixture_candidate']['order_id'],
        'Repeated order IDs are not consistent across recovered exchanges.',
    );
    verify(
        $recoveredWallet['sanitized_response_fixture_candidate']['order_id']
        !== $recoveredWallet['sanitized_response_fixture_candidate']['transaction_id'],
        'Distinct order and transaction IDs collapsed during recovery.',
    );
    verify(
        $orderResponse['merchant_order_id']
        === $recoveredWallet['sanitized_response_fixture_candidate']['merchant_order_id'],
        'Repeated private references are not consistent across recovered exchanges.',
    );
    verify(
        $recoveryReport['leak_guard_result'] === 'PASS'
        && $recoveryReport['referential_consistency_result'] === 'PASS',
        'Recovery omitted leak-guard or referential-consistency evidence.',
    );
    $recoveryArtifactPath = $recoveryResult['artifact']['path'];
    $recoveryArtifactBytes = file_get_contents($recoveryArtifactPath);
    verify(is_string($recoveryArtifactBytes), 'Synthetic recovery artifact could not be read back.');
    verify(strlen($recoveryArtifactBytes) === $recoveryResult['artifact']['bytes'], 'Recovery artifact byte count does not match.');
    verify(hash_equals($recoveryResult['artifact']['sha256'], hash('sha256', $recoveryArtifactBytes)), 'Recovery artifact SHA-256 does not match.');
    verify((fileperms(dirname($recoveryArtifactPath)) & 0777) === 0700, 'Recovery artifact directory mode is not 0700.');
    verify((fileperms($recoveryArtifactPath) & 0777) === 0600, 'Recovery artifact file mode is not 0600.');
    foreach ([
        'recovery-self-check-api-secret',
        'recovery-self-check-hmac-secret',
        'recovery-self-check-auth-token',
        'recovery-self-check-payment-token',
        'recovery-private-order-ref',
        'recovery-person@example.org',
    ] as $sensitiveLiteral) {
        verify(!str_contains($recoveryArtifactBytes, $sensitiveLiteral), 'Recovery artifact contains a synthetic secret or PII literal.');
    }
    $afterRecoverySnapshot = [];
    foreach (scandir($syntheticRecoveryDirectory) ?: [] as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }
        $sourcePath = $syntheticRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
        $afterRecoverySnapshot[$fileName] = [filesize($sourcePath), hash_file('sha256', $sourcePath), fileperms($sourcePath) & 0777];
    }
    verify($sourceSnapshot === $afterRecoverySnapshot, 'Recovery modified the synthetic source run.');
    echo "PASS network-free retained-run recovery, stage derivation, sanitization, metadata honesty, referential consistency, and retained source\n";

    $syntheticRecoveryFailureDirectory = $privateNamespace . DIRECTORY_SEPARATOR . 'run-self-check-failure-' . bin2hex(random_bytes(6));
    verify(mkdir($syntheticRecoveryFailureDirectory, 0700), 'Synthetic post-prime failure run could not be created.');
    chmod($syntheticRecoveryFailureDirectory, 0700);
    $failureRawExchange = [
        '0001-request-url.txt' => 'https://accept.paymob.com/api/auth/tokens',
        '0001-request-body.bin' => '{"api_key":"recovery-self-check-api-secret","billing_data":{"building":"1"},"amount_cents":15000}',
        '0001-response-body.bin' => '{"message":"recovery-self-check-api-secret","profile":{"id":123456}}',
    ];
    foreach ($failureRawExchange as $fileName => $contents) {
        $path = $syntheticRecoveryFailureDirectory . DIRECTORY_SEPARATOR . $fileName;
        verify(file_put_contents($path, $contents, LOCK_EX) === strlen($contents), 'Synthetic post-prime failure evidence could not be written.');
        chmod($path, 0600);
    }
    $failureSourceSnapshot = [];
    foreach ($failureRawExchange as $fileName => $_contents) {
        $path = $syntheticRecoveryFailureDirectory . DIRECTORY_SEPARATOR . $fileName;
        $failureSourceSnapshot[$fileName] = [filesize($path), hash_file('sha256', $path), fileperms($path) & 0777];
    }
    $recoveryFailure = RetainedRunRecovery::recover(
        $syntheticRecoveryConfigDirectory,
        $syntheticRecoveryFailureDirectory,
        'wallet',
    );
    verify($recoveryFailure['result'] === 'FAIL', 'Synthetic post-prime semantic failure unexpectedly recovered.');
    verify($recoveryFailure['source_raw_retained'] === true, 'Failed recovery did not retain its source run.');
    verify(
        is_array($recoveryFailure['failure_artifact']) && $recoveryFailure['failure_artifact']['path'] !== '',
        'Failed recovery after priming short PII did not persist a durable diagnostic artifact.',
    );
    $recoveryFailureArtifactPath = $recoveryFailure['failure_artifact']['path'];
    $recoveryFailureBytes = file_get_contents($recoveryFailureArtifactPath);
    verify(is_string($recoveryFailureBytes), 'Recovery failure diagnostic could not be read back.');
    verify(
        strlen($recoveryFailureBytes) === $recoveryFailure['failure_artifact']['bytes']
        && hash_equals($recoveryFailure['failure_artifact']['sha256'], hash('sha256', $recoveryFailureBytes)),
        'Recovery failure diagnostic byte count or SHA-256 does not match.',
    );
    verify((fileperms(dirname($recoveryFailureArtifactPath)) & 0777) === 0700, 'Recovery failure artifact directory mode is not 0700.');
    verify((fileperms($recoveryFailureArtifactPath) & 0777) === 0600, 'Recovery failure artifact file mode is not 0600.');
    verify(
        !str_contains($recoveryFailureBytes, 'recovery-self-check-api-secret')
        && !str_contains($recoveryFailureBytes, 'recovery-self-check-hmac-secret'),
        'Recovery failure diagnostic contains a synthetic configured secret.',
    );
    $recoveryFailureSanitizer = new SemanticSanitizer(
        ['recovery-self-check-api-secret', 'recovery-self-check-hmac-secret'],
    );
    $recoveryFailureSanitizer->prime([
        json_decode($failureRawExchange['0001-request-body.bin'], false, 512, JSON_THROW_ON_ERROR),
        json_decode($failureRawExchange['0001-response-body.bin'], false, 512, JSON_THROW_ON_ERROR),
    ]);
    $recoveryFailureReport = json_decode($recoveryFailureBytes, true, 512, JSON_THROW_ON_ERROR);
    verify(
        is_array($recoveryFailureReport) && !$recoveryFailureSanitizer->containsSensitiveValues($recoveryFailureReport),
        'Recovery failure artifact failed JSON or configured-secret leak-guard verification.',
    );
    $failureSourceAfter = [];
    foreach ($failureRawExchange as $fileName => $_contents) {
        $path = $syntheticRecoveryFailureDirectory . DIRECTORY_SEPARATOR . $fileName;
        $failureSourceAfter[$fileName] = [filesize($path), hash_file('sha256', $path), fileperms($path) & 0777];
    }
    verify(
        $failureSourceSnapshot === $failureSourceAfter,
        'Post-prime failed recovery modified its synthetic source run.',
    );
    echo "PASS post-prime failure diagnostic persists with short contextual PII and retained raw source\n";
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

    foreach ([$artifactPath, $failureArtifactPath, $recoveryArtifactPath, $recoveryFailureArtifactPath] as $selfCheckArtifactPath) {
        if ($selfCheckArtifactPath !== null && is_file($selfCheckArtifactPath) && !unlink($selfCheckArtifactPath)) {
            $failure ??= new RuntimeException('Self-check sanitized artifact cleanup failed.');
        }
        if ($selfCheckArtifactPath !== null && file_exists($selfCheckArtifactPath)) {
            $failure ??= new RuntimeException('Self-check sanitized artifact remains after final cleanup.');
        }
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
    if ($syntheticRecoveryDirectory !== null && is_dir($syntheticRecoveryDirectory)) {
        foreach (scandir($syntheticRecoveryDirectory) ?: [] as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $path = $syntheticRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !is_link($path) && !unlink($path)) {
                $failure ??= new RuntimeException('Synthetic retained-run cleanup failed.');
            }
        }
        if (is_dir($syntheticRecoveryDirectory) && !rmdir($syntheticRecoveryDirectory)) {
            $failure ??= new RuntimeException('Synthetic retained-run directory cleanup failed.');
        }
    }
    if ($syntheticRecoveryFailureDirectory !== null && is_dir($syntheticRecoveryFailureDirectory)) {
        foreach (scandir($syntheticRecoveryFailureDirectory) ?: [] as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $path = $syntheticRecoveryFailureDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !is_link($path) && !unlink($path)) {
                $failure ??= new RuntimeException('Synthetic incomplete retained-run cleanup failed.');
            }
        }
        if (is_dir($syntheticRecoveryFailureDirectory) && !rmdir($syntheticRecoveryFailureDirectory)) {
            $failure ??= new RuntimeException('Synthetic incomplete retained-run directory cleanup failed.');
        }
    }
    if ($syntheticRecoveryConfigDirectory !== null && is_file($syntheticRecoveryConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
        if (!unlink($syntheticRecoveryConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
            $failure ??= new RuntimeException('Synthetic recovery configuration cleanup failed.');
        }
    }
    if ($syntheticRecoveryConfigDirectory !== null && is_dir($syntheticRecoveryConfigDirectory)
        && !rmdir($syntheticRecoveryConfigDirectory)) {
        $failure ??= new RuntimeException('Synthetic recovery configuration directory cleanup failed.');
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'SELF-CHECK: FAIL (' . get_class($failure) . '): ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "SELF-CHECK: PASS\n";
exit(0);
