<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO;
use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NotFoundException;
use Maatify\Paymob\Exception\RateLimitException;
use Maatify\Paymob\Exception\ServiceUnavailableException;
use Maatify\Paymob\Exception\ValidationException;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Transaction\Service\TransactionService;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\ProviderVerification\Support\CaptureSession;
use Maatify\Paymob\ProviderVerification\Support\CapturingApiClient;
use Maatify\Paymob\ProviderVerification\Support\ProviderAttemptStageClassifier;
use Maatify\Paymob\ProviderVerification\Support\RetainedRunRecovery;
use Maatify\Paymob\ProviderVerification\Support\SemanticSanitizer;
use Maatify\Paymob\ProviderVerification\Support\VerificationConfig;
use Maatify\Paymob\ProviderVerification\Support\VerificationContext;

require_once __DIR__ . '/Support/CapturingApiClient.php';
require_once __DIR__ . '/Support/VerificationContext.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

verify(
    CapturingApiClient::classifyCapturedResponse(200, '{"id":1}') === ['id' => 1],
    'Captured response seam did not preserve valid 2xx JSON mapping.',
);
$capturedErrorCases = [
    [401, '{"error_code":"validation_failed"}', UnauthorizedException::class, ['error_code' => 'validation_failed']],
    [404, '{"message":"missing"}', NotFoundException::class, ['message' => 'missing']],
    [429, '{"message":"slow"}', RateLimitException::class, ['message' => 'slow']],
    [500, '{"message":"down"}', ServiceUnavailableException::class, ['message' => 'down']],
    [400, '{"error_code":"validation_failed"}', ValidationException::class, ['error_code' => 'validation_failed']],
    [502, '<html>failure</html>', ServiceUnavailableException::class, '<html>failure</html>'],
    [200, '{bad', ApiException::class, '{bad'],
    [200, '42', ApiException::class, '42'],
];
foreach ($capturedErrorCases as [$status, $body, $expectedClass, $expectedResponse]) {
    try {
        CapturingApiClient::classifyCapturedResponse($status, $body);
        verify(false, 'Captured response seam accepted an error or malformed response.');
    } catch (Throwable $exception) {
        verify(
            $exception instanceof $expectedClass
            && method_exists($exception, 'getProviderStatusCode')
            && $exception->getProviderStatusCode() === ($status >= 400 ? $status : null)
            && method_exists($exception, 'getResponse')
            && $exception->getResponse() === $expectedResponse,
            'Captured response seam diverged from production exception/status/evidence mapping.',
        );
    }
}
echo "PASS CapturingApiClient production response-classification seam mapping\n";

$captureSession = null;
$configurationDirectory = null;
$artifactPath = null;
$artifactDirectory = null;
$rawDirectory = null;
$failureArtifactPath = null;
$recoveryArtifactPath = null;
$recoveryArtifactPathB = null;
$recoveryFailureArtifactPath = null;
$kioskRecoveryArtifactPaths = [];
$syntheticKioskRecoveryDirectories = [];
$cardRecoveryArtifactPaths = [];
$syntheticCardRecoveryDirectories = [];
$transactionRecoveryArtifactPaths = [];
$syntheticTransactionRecoveryDirectories = [];
$syntheticKioskConfigDirectory = null;
$syntheticPaymentKeyConfigDirectory = null;
$legacyRecoveryArtifactPath = null;
$legacyRecoverySentinelOwned = false;
$unexpectedRecoveryFailureArtifactPaths = [];
$syntheticRecoveryDirectory = null;
$syntheticRecoveryFailureDirectory = null;
$syntheticRecoveryConfigDirectory = null;
$rawCleaned = false;
$failure = null;

$createCapturingClient = static function (VerificationConfig $config) use (&$captureSession): CapturingApiClient {
    if (!$captureSession instanceof CaptureSession) {
        throw new RuntimeException('The network-free attempt classifier requires a prepared capture session.');
    }

    return new CapturingApiClient(
        $config->baseUrl,
        $captureSession,
        ProviderAttemptStageClassifier::fromConfig($config),
    );
};

$classifyWithCapturingClient = static function (VerificationConfig $config, array $exchanges) use (
    $createCapturingClient,
): array {
    $client = $createCapturingClient($config);
    $stages = [];
    foreach ($exchanges as $exchange) {
        [$url, $request] = $exchange;
        if (is_string($request)) {
            $request = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
        }
        $stage = $client->classifyAttemptStage($url, $request);
        $stages[] = $stage;
        $status = array_key_exists(3, $exchange) ? $exchange[3] : 200;
        $transportOk = $exchange[4] ?? true;
        $client->recordClassifiedOutcome($stage, $transportOk, $status);
    }

    return $stages;
};

$expectClassificationFailure = static function (
    CapturingApiClient $client,
    string $url,
    mixed $request,
    string $description,
): void {
    try {
        $client->classifyAttemptStage($url, $request);
    } catch (RuntimeException) {
        return;
    }

    verify(false, 'Malformed request unexpectedly classified: ' . $description);
};

$expectWorkflowFailure = static function (VerificationConfig $config, array $exchanges, string $description) use (
    $classifyWithCapturingClient,
): void {
    try {
        $classifyWithCapturingClient($config, $exchanges);
    } catch (RuntimeException) {
        return;
    }
    verify(false, 'Invalid provider workflow was accepted: ' . $description);
};

$composeSyntheticAuthenticatedRequest = static function (array $commandPayload, string $authToken): array {
    return ['auth_token' => $authToken, ...$commandPayload];
};

$makeSyntheticOrderRequest = static function (string $authToken, string $merchantOrderId) use ($composeSyntheticAuthenticatedRequest): array {
    $commandPayload = (new CreateOrderCommand(
        amountCents: 15000,
        currency: CurrencyEnum::EGP,
        merchantOrderId: $merchantOrderId,
        items: [new OrderItem(
            name: 'Provider verification item',
            amountCents: 15000,
            quantity: 1,
            description: 'Synthetic provider verification order',
        )],
    ))->toArray();
    return $composeSyntheticAuthenticatedRequest($commandPayload, $authToken);
};

$makeSyntheticPaymentKeyRequest = static function (int $integrationId, string $authToken) use ($composeSyntheticAuthenticatedRequest): array {
    $commandPayload = (new GeneratePaymentKeyCommand(
        orderId: 123456,
        integrationId: $integrationId,
        amountCents: 15000,
        currency: CurrencyEnum::EGP,
        billingData: new BillingData(
            firstName: 'Synthetic',
            lastName: 'Verification',
            email: 'synthetic@example.test',
            phoneNumber: '+20000000000',
            country: 'NA',
            city: 'Synthetic City',
            street: 'Synthetic Street',
            building: '1',
            floor: '1',
            apartment: '1',
            postalCode: '00000',
            state: 'Synthetic State',
        ),
        expirationSeconds: 180,
    ))->toArray();
    return $composeSyntheticAuthenticatedRequest($commandPayload, $authToken);
};

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

    $sendMethodMatch = preg_match(
        '/private function send\\([^\\n]+\\): array\\s*\\{(.*?)\\n    \\}/s',
        $transportSource,
        $sendMethod,
    );
    $classifyPosition = $sendMethodMatch === 1
        ? strpos($sendMethod[1], '$stage = $this->classifyAttemptStage($url, $requestShape);')
        : false;
    $curlInitPosition = $sendMethodMatch === 1 ? strpos($sendMethod[1], '$curl = curl_init($url);') : false;
    $sendExecPosition = $sendMethodMatch === 1
        ? strpos($sendMethod[1], $curlExecName . '($curl)')
        : false;
    verify(
        $sendMethodMatch === 1 && is_int($classifyPosition) && is_int($curlInitPosition)
        && is_int($sendExecPosition) && $classifyPosition < $curlInitPosition
        && $curlInitPosition < $sendExecPosition
        && str_contains($sendMethod[1], "'stage' => " . '$stage')
        && !str_contains($transportSource, 'setStage('),
        'Live capture does not classify the actual request before cURL or persist that shared stage.',
    );

    $guzzleSource = file_get_contents(dirname(__DIR__, 2) . '/src/Adapter/GuzzleApiClient.php');
    verify(
        is_string($guzzleSource)
        && str_contains($guzzleSource, 'return $this->send(\'GET\', $uri, [\'query\' => $query, \'headers\' => $headers]);')
        && str_contains($guzzleSource, '$this->client->request($method, ltrim($uri, \'/\'), $options)')
        && str_contains($guzzleSource, '\'base_uri\' => rtrim($config->baseUrl, \'/\') . \'/\'')
        && str_contains($guzzleSource, '\'allow_redirects\' => false')
        && str_contains($guzzleSource, "'http_errors' => false"),
        'GuzzleApiClient no longer preserves the configured base path, disables redirects, or forwards GET query and headers.',
    );
    echo "PASS Guzzle GET caller headers and provider-response classification contract\n";

    $verificationContextSource = file_get_contents(__DIR__ . '/Support/VerificationContext.php');
    verify(
        is_string($verificationContextSource)
        && str_contains($verificationContextSource, 'ProviderAttemptStageClassifier::fromConfig($config)')
        && !str_contains($verificationContextSource, '$this->apiClient->setStage('),
        'VerificationContext still supplies coarse orchestration stages to live capture.',
    );

    $selfCheckSource = file_get_contents(__FILE__);
    $procOpenName = 'proc_' . 'open';
    verify(
        is_string($selfCheckSource)
        && preg_match('/\\b(?:curl_exec|shell_exec|passthru|popen)\\s*\\(/', $selfCheckSource) !== 1
        && preg_match('/(?:file_get_contents|fopen)\\s*\\(\\s*[\'\"]https?:/i', $selfCheckSource) !== 1
        && substr_count($selfCheckSource, $procOpenName . '(') === 2
        && preg_match('/->\\s*(?:post|get)\\s*\\(/', $selfCheckSource) !== 1
        && !str_contains($selfCheckSource, 'auth' . '.php')
        && !str_contains($selfCheckSource, 'order' . '.php')
        && !str_contains($selfCheckSource, 'kiosk' . '.php')
        && !str_contains($selfCheckSource, 'wallet' . '.php')
        && !str_contains($selfCheckSource, 'payment-key' . '.php')
        && !str_contains($selfCheckSource, 'transaction-inquiry' . '.php'),
        'The self-check source contains a provider execution or network-backed input.',
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
  "shipping_data": {"order_id": 123456, "order": 123456, "integration_id": 980003},
  "payment_key_claims": {"order_id": 123456, "integration_id": 980003},
  "transaction": {"id": 887766, "source_id": 998877, "integration_id": 980003},
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

    $rawPhoneCollections = json_decode(
        '{"profile":{"phones":["synthetic-profile-private-phone-zero","synthetic-profile-private-phone-index-one",null,false,1234567890,12345.5,"01010101010",""]},'
        . '"merchant":{"phones":["synthetic-merchant-private-phone-zero","synthetic-merchant-private-phone-index-one",null]},'
        . '"unrelated_values":["unrelated semantic value 123","release 1 is ready"],'
        . '"source":{"identifier":"01010101010"}}',
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    $phoneCollectionSanitizer = new SemanticSanitizer([], '01010101010');
    $phoneCollectionSanitizer->prime([$rawPhoneCollections]);
    $safePhoneCollections = $phoneCollectionSanitizer->sanitize($rawPhoneCollections);
    $safePhoneCollectionJson = json_encode($safePhoneCollections, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(
        $phoneCollectionSanitizer->sameShape($rawPhoneCollections, $safePhoneCollections),
        'Phone collection sanitization changed JSON shape, scalar types, or ordering.',
    );
    verify(
        $safePhoneCollections->profile->phones === [
            '+20000000000', '+20000000000', null, false, 20000000000, 20000000000.0, '+20000000000', '+20000000000',
        ]
        && $safePhoneCollections->merchant->phones === ['+20000000000', '+20000000000', null],
        'Profile or merchant phones collection did not preserve positions while redacting scalar elements.',
    );
    verify(
        count($safePhoneCollections->profile->phones) === count($rawPhoneCollections->profile->phones)
        && count($safePhoneCollections->merchant->phones) === count($rawPhoneCollections->merchant->phones),
        'Phone collection sanitization changed list cardinality.',
    );
    verify(
        $safePhoneCollections->unrelated_values === $rawPhoneCollections->unrelated_values,
        'An unrelated string list was treated as a phone collection.',
    );
    verify(
        $safePhoneCollections->source->identifier === '01010101010'
        && $safePhoneCollections->profile->phones[6] === '+20000000000',
        'The public Wallet test exception escaped source.identifier into phones[].',
    );
    foreach ([
        'synthetic-profile-private-phone-zero',
        'synthetic-profile-private-phone-index-one',
        'synthetic-merchant-private-phone-zero',
        'synthetic-merchant-private-phone-index-one',
    ] as $rawPhone) {
        verify(!str_contains($safePhoneCollectionJson, $rawPhone), 'A raw phones[] value remains after sanitization.');
    }
    verify(
        $phoneCollectionSanitizer->sensitiveFieldDifferences($rawPhoneCollections, $safePhoneCollections) === [],
        'Sensitive-field validation rejected canonical phones[] replacements.',
    );
    $unsafePhoneCollections = clone $safePhoneCollections;
    $unsafePhoneCollections->profile = clone $safePhoneCollections->profile;
    $unsafePhoneCollections->profile->phones[1] = $rawPhoneCollections->profile->phones[1];
    verify(
        $phoneCollectionSanitizer->sensitiveFieldDifferences($rawPhoneCollections, $unsafePhoneCollections)
            === ['$.profile.phones[1]'],
        'Sensitive-field validation did not recognize the numeric child key at profile.phones[1].',
    );
    $unsafePhoneCollections->profile->phones[1] = '<SANITIZED_PHONE>';
    verify(
        $phoneCollectionSanitizer->sensitiveFieldDifferences($rawPhoneCollections, $unsafePhoneCollections)
            === ['$.profile.phones[1]'],
        'Sensitive-field validation accepted a noncanonical phones[] replacement.',
    );
    echo "PASS profile/merchant phones[] contextual redaction, index 1 validation, shape, and public-test path isolation\n";

    $rawSenderName = json_decode(
        '{"profile":{"sms_sender_name":"synthetic-private-sms-sender"},'
        . '"semantic_name":"WalletPayment","nickname":"Keep this nickname",'
        . '"items":[{"name":"Synthetic wallet item"}]}',
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    $senderNameSanitizer = new SemanticSanitizer();
    $senderNameSanitizer->prime([$rawSenderName]);
    $safeSenderName = $senderNameSanitizer->sanitize($rawSenderName);
    verify($senderNameSanitizer->sameShape($rawSenderName, $safeSenderName), 'Sender-name sanitization changed JSON shape.');
    verify(
        $safeSenderName->profile->sms_sender_name === '<SANITIZED_NAME>'
        && $safeSenderName->semantic_name === 'WalletPayment'
        && $safeSenderName->nickname === 'Keep this nickname'
        && $safeSenderName->items[0]->name === 'Synthetic wallet item',
        'Explicit sms_sender_name classification changed an unrelated name-like semantic field.',
    );
    verify(
        !str_contains(json_encode($safeSenderName, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'synthetic-private-sms-sender'),
        'Raw sms_sender_name remains after sanitization.',
    );
    verify(
        $senderNameSanitizer->sensitiveFieldDifferences($rawSenderName, $safeSenderName) === [],
        'Sensitive-field validation rejected the canonical sms_sender_name replacement.',
    );
    $unsafeSenderName = clone $safeSenderName;
    $unsafeSenderName->profile = clone $safeSenderName->profile;
    $unsafeSenderName->profile->sms_sender_name = $rawSenderName->profile->sms_sender_name;
    verify(
        $senderNameSanitizer->sensitiveFieldDifferences($rawSenderName, $unsafeSenderName)
            === ['$.profile.sms_sender_name'],
        'Sensitive-field validation did not recognize the explicit sms_sender_name field.',
    );
    echo "PASS explicit sms_sender_name redaction and narrow name classification\n";

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

    $upgReferenceRawJson = <<<'JSON'
{
  "data": {
    "klass": "WalletPayment",
    "message": "Transaction Created Successfully",
    "wallet_issuer": "VODAFONE",
    "txn_response_code": "200",
    "gateway_source": "",
    "amount": 15000,
    "currency": "EGP",
    "pending": true,
    "success": false,
    "created_at": "2026-10-02T19:04:18.639752Z",
    "uig_txn_id": "123456789",
    "mpg_txn_id": "987654321",
    "order_info": "synthetic-order-info-reference",
    "mer_txn_ref": "synthetic-merchant-transaction-reference",
    "upg_qrcode_ref": "synthetic-private-upg-reference"
  },
  "repeated": {"upg_qrcode_ref": "synthetic-private-upg-reference"},
  "different": {"upg_qrcode_ref": "synthetic-different-upg-reference"},
  "numeric_reference": {"upg_qrcode_ref": "112233445566"},
  "other_endpoint_reference": "123456789012"
}
JSON;
    $upgReferenceRaw = json_decode($upgReferenceRawJson);
    verify($upgReferenceRaw instanceof stdClass, 'Synthetic UPG reference JSON did not decode.');
    $upgReferenceSanitizer = new SemanticSanitizer();
    $upgReferenceSanitizer->prime([$upgReferenceRaw]);
    $upgReferenceSafe = $upgReferenceSanitizer->sanitize($upgReferenceRaw);
    verify(
        $upgReferenceSanitizer->sameShape($upgReferenceRaw, $upgReferenceSafe),
        'UPG reference sanitization changed JSON shape or types.',
    );
    echo "PASS upg_qrcode_ref same-shape validation\n";

    $upgReferenceMappings = [];
    foreach ($upgReferenceSanitizer->referenceMappingSummary() as $mapping) {
        foreach ($mapping['paths'] as $mappingPath) {
            $upgReferenceMappings[$mappingPath] = $mapping['fake_value'];
        }
    }
    $upgIdMappingPaths = [];
    foreach ($upgReferenceSanitizer->idMappingSummary() as $mapping) {
        foreach ($mapping['paths'] as $mappingPath) {
            $upgIdMappingPaths[$mappingPath] = true;
        }
    }
    $repeatedUpgReference = $upgReferenceSafe->data->upg_qrcode_ref;
    $differentUpgReference = $upgReferenceSafe->different->upg_qrcode_ref;
    $numericUpgReference = $upgReferenceSafe->numeric_reference->upg_qrcode_ref;
    verify(
        is_string($repeatedUpgReference)
        && preg_match('/^PV-REF-\d{4}$/D', $repeatedUpgReference) === 1
        && $repeatedUpgReference === $upgReferenceSafe->repeated->upg_qrcode_ref
        && $repeatedUpgReference === $upgReferenceMappings['$.data.upg_qrcode_ref']
        && $repeatedUpgReference === $upgReferenceMappings['$.repeated.upg_qrcode_ref'],
        'Repeated UPG private references did not use one stable shared reference mapping.',
    );
    verify(
        is_string($differentUpgReference)
        && preg_match('/^PV-REF-\d{4}$/D', $differentUpgReference) === 1
        && $differentUpgReference !== $repeatedUpgReference,
        'Different UPG private references collapsed to the same mapping.',
    );
    verify(
        is_string($numericUpgReference)
        && preg_match('/^PV-REF-\d{4}$/D', $numericUpgReference) === 1
        && $numericUpgReference === $upgReferenceMappings['$.numeric_reference.upg_qrcode_ref'],
        'Digit-only UPG reference did not use the shared string-reference mapping.',
    );
    verify(
        $upgReferenceSafe->other_endpoint_reference !== $upgReferenceMappings['$.numeric_reference.upg_qrcode_ref']
        && !str_starts_with($upgReferenceSafe->other_endpoint_reference, 'PV-REF-'),
        'Existing digit-only other_endpoint_reference ID mapping changed.',
    );
    verify(
        isset($upgReferenceMappings['$.data.order_info'], $upgReferenceMappings['$.data.mer_txn_ref'])
        && $upgReferenceSafe->data->uig_txn_id !== '123456789'
        && $upgReferenceSafe->data->mpg_txn_id !== '987654321'
        && isset($upgIdMappingPaths['$.data.uig_txn_id'], $upgIdMappingPaths['$.data.mpg_txn_id'])
        && isset($upgIdMappingPaths['$.other_endpoint_reference']),
        'Existing UPG transaction/reference mappings were not preserved.',
    );
    echo "PASS repeated reference consistency and distinct-reference separation\n";

    verify(
        $upgReferenceSafe->data->klass === 'WalletPayment'
        && $upgReferenceSafe->data->message === 'Transaction Created Successfully'
        && $upgReferenceSafe->data->wallet_issuer === 'VODAFONE'
        && $upgReferenceSafe->data->txn_response_code === '200'
        && $upgReferenceSafe->data->gateway_source === ''
        && $upgReferenceSafe->data->amount === 15000
        && $upgReferenceSafe->data->currency === 'EGP'
        && $upgReferenceSafe->data->pending === true
        && $upgReferenceSafe->data->success === false
        && $upgReferenceSafe->data->created_at === '2026-10-02T19:04:18.639752Z',
        'UPG sanitization changed provider-semantic Wallet fields.',
    );
    verify(
        $upgReferenceSanitizer->semanticDifferences($upgReferenceRaw, $upgReferenceSafe) === [],
        'UPG sanitization failed semantic validation.',
    );
    verify(
        $upgReferenceSanitizer->sensitiveFieldDifferences($upgReferenceRaw, $upgReferenceSafe) === [],
        'UPG sanitization failed sensitive-field validation.',
    );
    verify(
        !$upgReferenceSanitizer->containsSensitiveValues($upgReferenceSafe),
        'UPG sanitized response failed the leak guard.',
    );
    $upgReferenceSafeJson = json_encode($upgReferenceSafe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (['synthetic-private-upg-reference', 'synthetic-different-upg-reference', '112233445566'] as $rawUpgReference) {
        verify(
            !str_contains($upgReferenceSafeJson, $rawUpgReference),
            'A raw UPG private reference remains in sanitized JSON.',
        );
    }
    echo "PASS semantic preservation, sensitive-field validation, and UPG leak guard\n";

    $semanticDifferences = $sanitizer->semanticDifferences($raw, $safe);
    verify($semanticDifferences === [], 'Semantic-value verification reported a change.');
    $encoded = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(is_string(json_decode($encoded)) || json_last_error() === JSON_ERROR_NONE, 'Sanitized output is not valid JSON.');
    echo "PASS sanitized JSON validity\n";

    $configurationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymob-provider-config-check-' . bin2hex(random_bytes(8));
    verify(mkdir($configurationDirectory, 0700), 'Could not create a private synthetic configuration directory.');
    $configurationPath = $configurationDirectory . DIRECTORY_SEPARATOR . '.env';
    $configuration = "PAYMOB_API_KEY=self-check-api-key-value\nPAYMOB_HMAC_SECRET=self-check-hmac-secret\nPAYMOB_BASE_URL=https://accept.paymob.com/api\nPAYMOB_INTEGRATION_ID_CARD=980001\nPAYMOB_INTEGRATION_ID_KIOSK=980002\nPAYMOB_INTEGRATION_ID_WALLET=980003\nPAYMOB_TEST_TRANSACTION_ID=42\n";
    verify(file_put_contents($configurationPath, $configuration) === strlen($configuration), 'Could not write a synthetic configuration file.');
    chmod($configurationPath, 0600);
    $authConfig = VerificationConfig::load($configurationDirectory, 'auth');
    verify($authConfig->hmacSecret === 'self-check-hmac-secret', 'Auth verification did not retain the required HMAC secret.');
    verify(
        $authConfig->configuredSecrets() === ['self-check-api-key-value', 'self-check-hmac-secret'],
        'Configured secret list did not include both required credentials.',
    );
    echo "PASS required credential handling and configured-secret filtering\n";

    $overflowInteger = str_repeat('9', strlen((string)PHP_INT_MAX) + 1);
    $overflowConfiguration = str_replace(
        'PAYMOB_INTEGRATION_ID_CARD=980001',
        'PAYMOB_INTEGRATION_ID_CARD=' . $overflowInteger,
        $configuration,
    );
    verify(file_put_contents($configurationPath, $overflowConfiguration) === strlen($overflowConfiguration),
        'Could not prepare the synthetic integration overflow case.');
    $integrationOverflowRejected = false;
    try {
        VerificationConfig::load($configurationDirectory, 'auth');
    } catch (RuntimeException $exception) {
        $integrationOverflowRejected = true;
        verify(!str_contains($exception->getMessage(), $overflowInteger), 'Integration overflow value leaked in an exception.');
    }
    verify($integrationOverflowRejected, 'Integration ID above PHP_INT_MAX was accepted.');
    $leadingZeroConfiguration = str_replace(
        'PAYMOB_INTEGRATION_ID_CARD=980001',
        'PAYMOB_INTEGRATION_ID_CARD=0980001',
        $configuration,
    );
    verify(file_put_contents($configurationPath, $leadingZeroConfiguration) === strlen($leadingZeroConfiguration),
        'Could not prepare the synthetic noncanonical integration ID case.');
    $noncanonicalIntegrationRejected = false;
    try {
        VerificationConfig::load($configurationDirectory, 'auth');
    } catch (RuntimeException) {
        $noncanonicalIntegrationRejected = true;
    }
    verify($noncanonicalIntegrationRejected, 'A leading-zero integration ID was accepted.');
    $overflowTransactionConfiguration = str_replace(
        'PAYMOB_TEST_TRANSACTION_ID=42',
        'PAYMOB_TEST_TRANSACTION_ID=' . $overflowInteger,
        $configuration,
    );
    verify(file_put_contents($configurationPath, $overflowTransactionConfiguration) === strlen($overflowTransactionConfiguration),
        'Could not prepare the synthetic Transaction ID overflow case.');
    $transactionOverflowRejected = false;
    try {
        VerificationConfig::load($configurationDirectory, 'transaction-inquiry');
    } catch (RuntimeException $exception) {
        $transactionOverflowRejected = true;
        verify(!str_contains($exception->getMessage(), $overflowInteger), 'Transaction ID overflow value leaked in an exception.');
    }
    verify($transactionOverflowRejected, 'Transaction ID above PHP_INT_MAX was accepted.');
    verify(file_put_contents($configurationPath, $configuration) === strlen($configuration),
        'Could not restore the synthetic Transaction Inquiry configuration.');
    echo "PASS canonical integer range validation and overflow rejection without value disclosure\n";

    $transactionConfig = VerificationConfig::load($configurationDirectory, 'transaction-inquiry');
    verify(
        $transactionConfig->testTransactionId === 42
        && $transactionConfig->cardIntegrationId === 980001
        && $transactionConfig->kioskIntegrationId === 980002
        && $transactionConfig->walletIntegrationId === 980003
        && $transactionConfig->walletTestMsisdn === null,
        'Transaction Inquiry configuration did not retain the canonical integration IDs.',
    );
    verify(
        file_put_contents($configurationPath, str_replace('PAYMOB_TEST_TRANSACTION_ID=42' . "\n", '', $configuration)) !== false,
        'Could not prepare the synthetic missing Transaction ID case.',
    );
    $missingTransactionIdRejected = false;
    try {
        VerificationConfig::load($configurationDirectory, 'transaction-inquiry');
    } catch (RuntimeException $exception) {
        $missingTransactionIdRejected = true;
        verify(!str_contains($exception->getMessage(), '42'), 'Transaction ID validation exposed the configured ID.');
    }
    verify($missingTransactionIdRejected, 'Transaction Inquiry accepted a missing Transaction ID.');
    verify(file_put_contents($configurationPath, $configuration) === strlen($configuration),
        'Could not restore the synthetic Transaction Inquiry configuration.');

    $transactionUrl = 'https://accept.paymob.com/api/acceptance/transactions/42';
    $transactionClassifier = ProviderAttemptStageClassifier::fromConfig($transactionConfig);
    $transactionClassifier->recordOutcome(
        $transactionClassifier->classify('https://accept.paymob.com/api/auth/tokens', ['api_key' => 'synthetic']),
        true,
        200,
    );
    verify(
        $transactionClassifier->classify($transactionUrl, []) === 'transaction-inquiry',
        'Transaction Inquiry normal GET was not classified after Auth.',
    );
    foreach ([
        [$transactionUrl, []],
        ['https://accept.paymob.com/api/acceptance/transactions/43', []],
        ['https://accept.paymob.com/api/ecommerce/orders/transaction_inquiry', []],
        [$transactionUrl . '?unexpected=1', []],
        ['https://other.example/api/acceptance/transactions/42', []],
        [$transactionUrl, ['unexpected' => true]],
    ] as [$invalidUrl, $invalidRequest]) {
        $classifier = ProviderAttemptStageClassifier::fromConfig($transactionConfig);
        $authStage = $classifier->classify('https://accept.paymob.com/api/auth/tokens', ['api_key' => 'synthetic']);
        $classifier->recordOutcome($authStage, true, 200);
        $normalInquiryStage = $classifier->classify($transactionUrl, []);
        $classifier->recordOutcome($normalInquiryStage, true, 200);
        $rejected = false;
        try {
            $classifier->classify($invalidUrl, $invalidRequest);
        } catch (RuntimeException) {
            $rejected = true;
        }
        verify($rejected, 'A malformed or third Transaction Inquiry attempt was accepted.');
    }
    echo "PASS Transaction Inquiry configuration and closed request classification\n";

    $transactionHttp = new class implements ApiClientInterface {
        /** @var list<array{uri: string, query: array, headers: array}> */
        public array $getCalls = [];

        /** @var list<array{uri: string, body: array}> */
        public array $postCalls = [];

        public bool $rejectFirstGet = false;

        public function post(string $uri, array $body, array $headers = []): array
        {
            $this->postCalls[] = ['uri' => $uri, 'body' => $body];
            return ['token' => 'synthetic-refreshed-auth-token', 'profile' => ['id' => 77]];
        }

        public function get(string $uri, array $query = [], array $headers = []): array
        {
            $this->getCalls[] = ['uri' => $uri, 'query' => $query, 'headers' => $headers];
            if ($this->rejectFirstGet && count($this->getCalls) === 1) {
                throw new UnauthorizedException('Synthetic unauthorized response.', 401);
            }
            return [
                'id' => 42,
                'order' => ['id' => 123456, 'payment_status' => 'UNPAID'],
                'amount_cents' => 15000,
                'currency' => 'EGP',
                'success' => false,
                'pending' => true,
                'is_captured' => false,
                'is_refunded' => false,
                'is_voided' => false,
                'is_3d_secure' => false,
                'is_standalone_payment' => true,
            ];
        }
    };
    $transactionTokenRepository = new InMemoryTokenRepository();
    $transactionTokenIssuedAt = time();
    $transactionTokenRepository->save(TokenScope::fromConfig($transactionConfig->packageConfig()), new TokenResponseDTO(
        'synthetic-initial-auth-token', 77, $transactionTokenIssuedAt, $transactionTokenIssuedAt + 3600,
    ));
    $transactionService = new TransactionService(
        $transactionHttp,
        new AuthService($transactionHttp, $transactionConfig->packageConfig(), $transactionTokenRepository, new SystemClock(new \DateTimeZone('UTC'))),
    );
    $transactionDto = $transactionService->getTransaction(42);
    verify(
        $transactionDto->id === 42
        && $transactionDto->orderId === 123456
        && $transactionDto->amountCents === 15000
        && $transactionDto->currency->value === 'EGP'
        && $transactionDto->success === false
        && $transactionDto->pending === true
        && $transactionDto->paymentStatus === 'UNPAID'
        && $transactionHttp->getCalls === [[
            'uri' => '/acceptance/transactions/42',
            'query' => [],
            'headers' => ['Authorization' => 'Bearer synthetic-initial-auth-token'],
        ]]
        && $transactionHttp->postCalls === [],
        'TransactionService did not use the exact Bearer GET request and DTO mapping.',
    );
    $transactionHttp->getCalls = [];
    $transactionHttp->rejectFirstGet = true;
    $transactionService->getTransaction(42);
    verify(
        count($transactionHttp->getCalls) === 2
        && $transactionHttp->getCalls[0]['headers']['Authorization'] === 'Bearer synthetic-initial-auth-token'
        && $transactionHttp->getCalls[1]['headers']['Authorization'] === 'Bearer synthetic-refreshed-auth-token'
        && $transactionHttp->getCalls[1]['query'] === []
        && count($transactionHttp->postCalls) === 1
        && $transactionHttp->postCalls[0]['uri'] === '/auth/tokens',
        'TransactionService existing 401 retry did not send the refreshed Bearer token.',
    );

    $mappingContext = (new ReflectionClass(VerificationContext::class))->newInstanceWithoutConstructor();
    $mappingMethod = new ReflectionMethod(VerificationContext::class, 'assertTransactionIdMapping');
    $mappingMethod->invoke($mappingContext, 42, 42);
    verify(
        (new ReflectionProperty(VerificationContext::class, 'transactionIdMatchesRequested'))
            ->getValue($mappingContext) === true,
        'Matching Transaction IDs were not recorded as consistent.',
    );
    $mismatchRejected = false;
    try {
        $mappingMethod->invoke($mappingContext, 42, 43);
    } catch (RuntimeException) {
        $mismatchRejected = true;
    }
    verify(
        $mismatchRejected
        && (new ReflectionProperty(VerificationContext::class, 'transactionIdMatchesRequested'))
            ->getValue($mappingContext) === false
        && (new ReflectionProperty(VerificationContext::class, 'stage'))
            ->getValue($mappingContext) === 'transaction-inquiry-id-mapping'
        && str_contains(
            $verificationContextSource,
            "\$diagnostic['service_results']['transaction_inquiry']['transaction_id_matches_requested'] = false;",
        )
        && str_contains(
            $verificationContextSource,
            "\$diagnostic['failure_classification'] = 'PACKAGE';",
        ),
        'A mismatched response Transaction ID did not fail as PACKAGE.',
    );
    echo "PASS TransactionService Bearer requests, DTO mapping, and Transaction ID consistency\n";

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
    $attemptClassifierSource = file_get_contents(__DIR__ . '/Support/ProviderAttemptStageClassifier.php');
    verify(
        is_string($recoverySource) && is_string($recoveryEntryPoint) && is_string($attemptClassifierSource),
        'Recovery or shared classifier source could not be inspected.',
    );
    foreach (['curl_' . 'init', 'curl_' . 'exec', 'ApiClient' . 'Interface', 'Auth' . 'Service', 'Order' . 'Service'] as $forbiddenCall) {
        verify(
            !str_contains($recoverySource, $forbiddenCall) && !str_contains($recoveryEntryPoint, $forbiddenCall),
            'Offline recovery source contains a provider-network dependency.',
        );
    }
    verify(
        str_contains($recoverySource, '$attemptStageClassifier->classify($urlBytes, $request)')
        && !str_contains($recoverySource, 'function deriveStage(')
        && !str_contains($recoverySource, 'kioskPaymentAttemptCount')
        && preg_match('/\\b\\d{5,}\\b/', $attemptClassifierSource) !== 1,
        'Recovery or shared classifier retains a second rule source or performs a forbidden side effect.',
    );
    foreach ([
        $curlExecName . '(',
        'curl_' . 'init(',
        'file_get_contents(',
        'file_put_contents(',
        'fopen(',
        'mkdir(',
        'unlink(',
        'realpath(',
        'getenv(',
        'Dotenv',
        '$_ENV',
        '$_SERVER',
        'error_log(',
        'fwrite(',
        'trigger_error(',
        'CaptureSession',
        'persistJsonArtifact(',
    ] as $forbiddenClassifierOperation) {
        verify(
            !str_contains($attemptClassifierSource, $forbiddenClassifierOperation),
            'The shared attempt classifier contains a forbidden environment, I/O, network, logging, '
                . 'or artifact dependency.',
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
        . "PAYMOB_INTEGRATION_ID_CARD=980001\n"
        . "PAYMOB_INTEGRATION_ID_KIOSK=980002\n"
        . "PAYMOB_INTEGRATION_ID_WALLET=980003\n"
        . "PAYMOB_TEST_WALLET_MSISDN=01010101010\n";
    verify(file_put_contents($recoveryConfigPath, $recoveryConfig) === strlen($recoveryConfig), 'Synthetic recovery configuration could not be written.');
    chmod($recoveryConfigPath, 0600);
    $walletRecoveryConfigDTO = VerificationConfig::load($syntheticRecoveryConfigDirectory, 'wallet');

    $syntheticPaymentKeyRequest = json_encode([
        'auth_token' => 'recovery-self-check-auth-token',
        'order_id' => 123456,
        'integration_id' => 980003,
        'amount_cents' => 15000,
        'currency' => 'EGP',
        'expiration' => 180,
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
    $syntheticWalletResponse = <<<'JSON'
{
  "success": false,
  "pending": true,
  "payment_status": "UNPAID",
  "order_id": 123456,
  "transaction_id": 887766,
  "integration_id": 980003,
  "merchant_order_id": "recovery-private-order-ref",
  "source": {"identifier": "01010101010", "subtype": "WALLET"},
  "created_at": "2026-10-03T10:02:00Z",
  "message": "Transaction Created Successfully",
  "other_endpoint_reference": "recovery-other-endpoint-reference",
  "data": {
    "klass": "WalletPayment",
    "message": "Transaction Created Successfully",
    "wallet_issuer": "VODAFONE",
    "txn_response_code": "200",
    "gateway_source": "",
    "amount": 15000,
    "currency": "EGP",
    "pending": true,
    "created_at": "2026-10-03T10:02:00Z",
    "uig_txn_id": "567890123",
    "mpg_txn_id": "678901234",
    "order_info": "recovery-order-info-reference",
    "mer_txn_ref": "recovery-merchant-transaction-reference",
    "upg_qrcode_ref": "synthetic-private-upg-reference"
  },
  "transaction": {"upg_qrcode_ref": "synthetic-private-upg-reference"}
}
JSON;
    $syntheticExchanges = [
        [
            'https://accept.paymob.com/api/auth/tokens',
            '{"api_key":"recovery-self-check-api-secret"}',
            '{"token":"recovery-self-check-auth-token","profile":{"id":456789,"email":"recovery-person@example.org","phones":["synthetic-profile-private-phone-zero","synthetic-profile-private-phone-index-one",null],"sms_sender_name":"synthetic-private-sms-sender"},"issued_at":"2026-10-03T10:00:00Z"}',
        ],
        [
            'https://accept.paymob.com/api/ecommerce/orders',
            json_encode(
                $makeSyntheticOrderRequest('recovery-self-check-auth-token', 'recovery-private-order-ref'),
                JSON_THROW_ON_ERROR,
            ),
            '{"id":123456,"merchant_order_id":"recovery-private-order-ref","merchant":{"id":345678,"phones":["synthetic-merchant-private-phone-zero","synthetic-merchant-private-phone-index-one",null]},"payment_status":"UNPAID","created_at":"2026-10-03T10:01:00Z"}',
        ],
        [
            'https://accept.paymob.com/api/acceptance/payment_keys',
            $syntheticPaymentKeyRequest,
            json_encode([
                'token' => 'recovery-self-check-payment-token',
                'order_id' => 123456,
                'integration_id' => 980003,
                'currency' => 'EGP',
                'status' => 'issued',
            ], JSON_THROW_ON_ERROR),
        ],
        [
            'https://accept.paymob.com/api/acceptance/payments/pay',
            '{"source":{"identifier":"01010101010","subtype":"WALLET"},"payment_token":"recovery-self-check-payment-token"}',
            $syntheticWalletResponse,
        ],
    ];
    $walletLiveStages = $classifyWithCapturingClient($walletRecoveryConfigDTO, $syntheticExchanges);
    verify(
        $walletLiveStages === ['auth', 'order', 'payment-key-wallet', 'wallet-initiation'],
        'The shared live classifier did not derive the normal Wallet stage sequence.',
    );
    $walletSyntheticOrderRequest = json_decode($syntheticExchanges[1][1], true, 512, JSON_THROW_ON_ERROR);
    verify(
        is_array($walletSyntheticOrderRequest)
        && $walletSyntheticOrderRequest['auth_token'] === 'recovery-self-check-auth-token',
        'Synthetic Wallet Order request was not derived from CreateOrderCommand with its Auth token.',
    );
    echo "PASS shared live classifier normal Wallet sequence and DTO-derived Order request\n";

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

    $snapshotFile = static function (string $path): array {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('A synthetic evidence snapshot target is not a regular file.');
        }
        $contents = file_get_contents($path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        $permissions = fileperms($path);
        if (!is_string($contents) || !is_int($size) || !is_string($hash) || !is_int($permissions)) {
            throw new RuntimeException('A synthetic evidence snapshot could not be read completely.');
        }

        return [
            'contents' => $contents,
            'bytes' => $size,
            'sha256' => $hash,
            'permissions' => $permissions & 0777,
        ];
    };
    $snapshotSourceRun = static function (string $directory) use ($snapshotFile): array {
        clearstatcache(true, $directory);
        $directoryPermissions = fileperms($directory);
        if (is_link($directory) || !is_dir($directory) || !is_int($directoryPermissions)) {
            throw new RuntimeException('Synthetic retained run directory could not be snapshotted safely.');
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('Synthetic retained run could not be listed for a snapshot.');
        }
        $snapshot = [];
        foreach ($entries as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $snapshot[$fileName] = $snapshotFile($directory . DIRECTORY_SEPARATOR . $fileName);
        }
        ksort($snapshot, SORT_STRING);

        return [
            'directory_permissions' => $directoryPermissions & 0777,
            'files' => $snapshot,
        ];
    };
    $syntheticSourceIdentity = basename($syntheticRecoveryDirectory);
    $validateRecoveryArtifact = static function (
        array $recovery,
        string $scenario,
        string $sourceIdentity,
        ?string $paymentMethod = null,
    ) use ($repositoryPath, $snapshotFile): array {
        $artifact = $recovery['artifact'] ?? null;
        verify(is_array($artifact) && is_string($artifact['path'] ?? null), 'Successful recovery omitted its artifact path.');
        verify(is_int($artifact['bytes'] ?? null) && is_string($artifact['sha256'] ?? null), 'Successful recovery omitted artifact size or hash metadata.');

        $path = $artifact['path'];
        $realPath = realpath($path);
        $directory = realpath(dirname($path));
        verify(
            $realPath !== false && $directory !== false && !is_link($path) && is_file($path)
            && dirname($realPath) === $directory,
            'Successful recovery artifact is not a regular non-symlink file.',
        );
        $artifactIdentity = $paymentMethod === null ? $scenario : $scenario . '-' . $paymentMethod;
        verify(
            preg_match(
                '/^' . preg_quote($sourceIdentity, '/') . '-recovered-' . preg_quote($artifactIdentity, '/')
                . '-\\d{8}T\\d{6}Z-[a-f0-9]{16}\\.json$/D',
                basename($realPath),
            ) === 1,
            'Successful recovery artifact name omitted its source, scenario, UTC time, or random suffix.',
        );
        verify(
            $repositoryPath !== false
            && $realPath !== $repositoryPath
            && !str_starts_with($realPath, $repositoryPath . DIRECTORY_SEPARATOR),
            'Successful recovery artifact is inside the repository.',
        );

        $snapshot = $snapshotFile($realPath);
        verify(
            $snapshot['bytes'] === $artifact['bytes']
            && strlen($snapshot['contents']) === $artifact['bytes'],
            'Recovery artifact returned byte count does not match.',
        );
        verify(
            hash_equals($artifact['sha256'], $snapshot['sha256'])
            && hash_equals($artifact['sha256'], hash('sha256', $snapshot['contents'])),
            'Recovery artifact returned SHA-256 does not match independent read-back.',
        );
        verify($snapshot['permissions'] === 0600, 'Recovery artifact file mode is not 0600.');
        verify((fileperms($directory) & 0777) === 0700, 'Recovery artifact parent mode is not 0700.');
        $persistedReport = json_decode($snapshot['contents'], true, 512, JSON_THROW_ON_ERROR);
        verify(
            is_array($persistedReport) && $persistedReport === ($recovery['report'] ?? null),
            'Recovery artifact does not contain valid JSON.',
        );

        return $snapshot;
    };
    $sourceSnapshot = $snapshotSourceRun($syntheticRecoveryDirectory);
    verify(is_string($artifactDirectory), 'Sanitized artifact directory is unavailable for the collision regression.');
    $legacyRecoveryArtifactPath = $artifactDirectory . DIRECTORY_SEPARATOR
        . $syntheticSourceIdentity . '-recovered-wallet-report.json';
    verify(
        !file_exists($legacyRecoveryArtifactPath) && !is_link($legacyRecoveryArtifactPath),
        'Synthetic legacy recovery artifact path unexpectedly exists before sentinel creation.',
    );
    $legacySentinelBytes = json_encode(
        ['sentinel' => 'legacy fixed-name collision artifact'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $legacySentinelFile = @fopen($legacyRecoveryArtifactPath, 'xb');
    verify(is_resource($legacySentinelFile), 'Synthetic legacy recovery sentinel could not be created exclusively.');
    $legacyRecoverySentinelOwned = true;
    $legacySentinelWritten = fwrite($legacySentinelFile, $legacySentinelBytes);
    $legacySentinelClosed = fclose($legacySentinelFile);
    verify(
        $legacySentinelWritten === strlen($legacySentinelBytes) && $legacySentinelClosed,
        'Synthetic legacy recovery sentinel was not written completely.',
    );
    verify(chmod($legacyRecoveryArtifactPath, 0600), 'Synthetic legacy recovery sentinel mode could not be set.');
    $legacySentinelSnapshot = $snapshotFile($legacyRecoveryArtifactPath);
    verify(
        is_array(json_decode($legacySentinelSnapshot['contents'], true, 512, JSON_THROW_ON_ERROR)),
        'Synthetic legacy recovery sentinel is not valid JSON.',
    );

    $recoveryResult = RetainedRunRecovery::recover($syntheticRecoveryConfigDirectory, $syntheticRecoveryDirectory, 'wallet');
    if (is_string($recoveryResult['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $recoveryResult['failure_artifact']['path'];
    }
    verify(
        $recoveryResult['result'] === 'PASS'
        && $recoveryResult['scenario'] === 'wallet'
        && $recoveryResult['report']['source']['scenario'] === 'wallet'
        && $recoveryResult['recovered_stages'] === $walletLiveStages,
        'Synthetic offline Wallet recovery failed or lost its selected scenario.',
    );
    verify($recoveryResult['exchange_count'] === 4, 'Synthetic recovery did not discover four triplets.');
    verify(
        $recoveryResult['recovered_stages'] === ['auth', 'order', 'payment-key-wallet', 'wallet-initiation'],
        'Synthetic recovery derived an incorrect stage sequence.',
    );
    verify($recoveryResult['source_raw_retained'] === true, 'Recovery did not retain the synthetic source run.');
    verify(is_string($recoveryResult['artifact']['path'] ?? null), 'First recovery did not return an artifact path.');
    $recoveryArtifactPath = $recoveryResult['artifact']['path'];
    $recoveryArtifactSnapshot = $validateRecoveryArtifact($recoveryResult, 'wallet', $syntheticSourceIdentity);
    $recoveryReport = $recoveryResult['report'];
    verify(
        $recoveryReport['source']['exchange_count'] === 4
        && $recoveryReport['source']['source_raw_retained'] === true,
        'Recovery report omitted source retention or exchange count.',
    );
    $recoveredWallet = $recoveryReport['exchanges'][3];
    $recoveredPaymentKey = $recoveryReport['exchanges'][2];
    $recoveredAuthProfile = $recoveryReport['exchanges'][0]['sanitized_response_fixture_candidate']['profile'];
    $recoveredOrderMerchant = $recoveryReport['exchanges'][1]['sanitized_response_fixture_candidate']['merchant'];
    verify(
        $recoveredAuthProfile['phones'] === ['+20000000000', '+20000000000', null]
        && $recoveredAuthProfile['sms_sender_name'] === '<SANITIZED_NAME>',
        'Persisted synthetic auth response did not safely redact profile phones or sms_sender_name.',
    );
    verify(
        $recoveredOrderMerchant['phones'] === ['+20000000000', '+20000000000', null],
        'Persisted synthetic order response did not safely redact merchant phones.',
    );
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
    $recoveredWalletResponse = $recoveredWallet['sanitized_response_fixture_candidate'];
    $recoveredUpgReference = $recoveredWalletResponse['data']['upg_qrcode_ref'];
    verify(
        is_string($recoveredUpgReference)
        && preg_match('/^PV-REF-\d{4}$/D', $recoveredUpgReference) === 1
        && $recoveredUpgReference === $recoveredWalletResponse['transaction']['upg_qrcode_ref'],
        'Synthetic recovery did not sanitize repeated upg_qrcode_ref values consistently.',
    );
    $recoveredUpgReferenceMapping = null;
    foreach ($recoveryReport['reference_mappings'] as $mapping) {
        if (in_array('$.exchanges[4].response.data.upg_qrcode_ref', $mapping['paths'], true)) {
            $recoveredUpgReferenceMapping = $mapping;
            break;
        }
    }
    verify(
        is_array($recoveredUpgReferenceMapping)
        && $recoveredUpgReferenceMapping['fake_value'] === $recoveredUpgReference
        && in_array('$.exchanges[4].response.transaction.upg_qrcode_ref', $recoveredUpgReferenceMapping['paths'], true),
        'Synthetic recovery omitted the shared UPG reference mapping paths.',
    );
    verify(
        $recoveredWalletResponse['data']['klass'] === 'WalletPayment'
        && $recoveredWalletResponse['data']['message'] === 'Transaction Created Successfully'
        && $recoveredWalletResponse['data']['wallet_issuer'] === 'VODAFONE'
        && $recoveredWalletResponse['data']['txn_response_code'] === '200'
        && $recoveredWalletResponse['data']['gateway_source'] === ''
        && $recoveredWalletResponse['data']['amount'] === 15000
        && $recoveredWalletResponse['data']['currency'] === 'EGP'
        && $recoveredWalletResponse['data']['pending'] === true
        && $recoveredWalletResponse['data']['created_at'] === '2026-10-03T10:02:00Z'
        && $recoveredWalletResponse['data']['uig_txn_id'] !== '567890123'
        && $recoveredWalletResponse['data']['mpg_txn_id'] !== '678901234',
        'Synthetic recovery changed Wallet semantics or existing transaction ID mapping.',
    );
    echo "PASS F11 sanitized reference mapping through synthetic offline recovery\n";
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
    $recoveryArtifactBytes = $recoveryArtifactSnapshot['contents'];
    $publicWalletPaths = [];
    $collectPublicWalletPaths = static function (mixed $value, string $path = '$') use (&$collectPublicWalletPaths, &$publicWalletPaths): void {
        if ($value instanceof stdClass) {
            foreach ($value as $key => $child) {
                $collectPublicWalletPaths($child, $path . '.' . (string)$key);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $childPath = array_is_list($value) ? $path . '[' . $key . ']' : $path . '.' . (string)$key;
                $collectPublicWalletPaths($child, $childPath);
            }

            return;
        }

        if ($value === '01010101010') {
            $publicWalletPaths[] = $path;
        }
    };
    $collectPublicWalletPaths($recoveryReport);
    verify(
        count($publicWalletPaths) === 2
        && array_reduce(
            $publicWalletPaths,
            static fn(bool $safe, string $path): bool => $safe && str_ends_with($path, '.source.identifier'),
            true,
        ),
        'The public Wallet test number appeared outside approved source.identifier paths in the recovery artifact.',
    );
    foreach ([
        'recovery-self-check-api-secret',
        'recovery-self-check-hmac-secret',
        'recovery-self-check-auth-token',
        'recovery-self-check-payment-token',
        'recovery-private-order-ref',
        'recovery-other-endpoint-reference',
        'recovery-order-info-reference',
        'recovery-merchant-transaction-reference',
        'synthetic-private-upg-reference',
        'recovery-person@example.org',
        'synthetic-profile-private-phone-zero',
        'synthetic-profile-private-phone-index-one',
        'synthetic-merchant-private-phone-zero',
        'synthetic-merchant-private-phone-index-one',
        'synthetic-private-sms-sender',
    ] as $sensitiveLiteral) {
        verify(!str_contains($recoveryArtifactBytes, $sensitiveLiteral), 'Recovery artifact contains a synthetic secret or PII literal.');
    }
    $afterRecoverySnapshot = $snapshotSourceRun($syntheticRecoveryDirectory);
    verify($sourceSnapshot === $afterRecoverySnapshot, 'Recovery modified the synthetic source run.');
    verify(
        $legacySentinelSnapshot === $snapshotFile($legacyRecoveryArtifactPath),
        'First recovery modified or removed the pre-existing legacy artifact sentinel.',
    );

    $recoveryResultB = RetainedRunRecovery::recover(
        $syntheticRecoveryConfigDirectory,
        $syntheticRecoveryDirectory,
        'wallet',
    );
    if (is_string($recoveryResultB['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $recoveryResultB['failure_artifact']['path'];
    }
    verify($recoveryResultB['result'] === 'PASS', 'Second synthetic offline recovery failed.');
    verify($recoveryResultB['exchange_count'] === 4, 'Second synthetic recovery did not discover four triplets.');
    verify(
        $recoveryResultB['recovered_stages'] === ['auth', 'order', 'payment-key-wallet', 'wallet-initiation'],
        'Second synthetic recovery derived an incorrect stage sequence.',
    );
    verify(is_string($recoveryResultB['artifact']['path'] ?? null), 'Second recovery did not return an artifact path.');
    $recoveryArtifactPathB = $recoveryResultB['artifact']['path'];
    verify(
        $recoveryArtifactPath !== $recoveryArtifactPathB
        && $recoveryArtifactPath !== $legacyRecoveryArtifactPath
        && $recoveryArtifactPathB !== $legacyRecoveryArtifactPath,
        'Successful recovery reused the first artifact or the legacy fixed-name sentinel path.',
    );
    $recoveryArtifactSnapshotB = $validateRecoveryArtifact($recoveryResultB, 'wallet', $syntheticSourceIdentity);
    $recoveryReportB = $recoveryResultB['report'];
    $recoveryReportWithoutTimestamp = $recoveryReport;
    $recoveryReportBWithoutTimestamp = $recoveryReportB;
    unset($recoveryReportWithoutTimestamp['source']['recovered_at']);
    unset($recoveryReportBWithoutTimestamp['source']['recovered_at']);
    verify(
        $recoveryReportBWithoutTimestamp === $recoveryReportWithoutTimestamp
        && array_column($recoveryReportB['exchanges'], 'stage') === $recoveryResult['recovered_stages']
        && $recoveryReportB['id_mappings'] === $recoveryReport['id_mappings']
        && $recoveryReportB['reference_mappings'] === $recoveryReport['reference_mappings'],
        'Second recovery changed the report schema, stages, or sanitizer mappings.',
    );
    verify(
        $recoveryArtifactSnapshot === $snapshotFile($recoveryArtifactPath)
        && is_file($recoveryArtifactPath)
        && !is_link($recoveryArtifactPath),
        'Artifact A was removed or changed by the second recovery.',
    );
    verify(
        $recoveryArtifactSnapshotB === $snapshotFile($recoveryArtifactPathB)
        && is_file($recoveryArtifactPathB)
        && !is_link($recoveryArtifactPathB),
        'Artifact B was removed or changed after its persistence.',
    );
    verify(
        $legacySentinelSnapshot === $snapshotFile($legacyRecoveryArtifactPath)
        && is_file($legacyRecoveryArtifactPath)
        && !is_link($legacyRecoveryArtifactPath),
        'Second recovery modified or removed the legacy artifact sentinel.',
    );
    verify(
        $sourceSnapshot === $snapshotSourceRun($syntheticRecoveryDirectory),
        'The second recovery modified the synthetic source run.',
    );
    echo "PASS F12 legacy-name collision sentinel, two unique recoveries, artifact read-back, permissions, and retained source snapshots\n";
    echo "PASS network-free retained-run recovery, stage derivation, sanitization, metadata honesty, referential consistency, and retained source\n";

    $kioskIntegrationId = 982341;
    $kioskApiKey = 'kiosk-self-check-api-secret';
    $kioskHmacSecret = 'kiosk-self-check-hmac-secret';
    $kioskAuthToken = 'synthetic-kiosk-auth-token';
    $kioskRefreshedAuthToken = 'synthetic-kiosk-refreshed-auth-token';
    $kioskPaymentToken = 'synthetic-kiosk-payment-token';
    $kioskMerchantOrderReference = 'synthetic-kiosk-private-merchant-order-reference';
    $kioskBillReference = 765432109;

    $syntheticKioskConfigDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'paymob-recovery-kiosk-config-' . bin2hex(random_bytes(6));
    verify(
        mkdir($syntheticKioskConfigDirectory, 0700),
        'Synthetic Kiosk configuration directory could not be created.',
    );
    $kioskConfigPath = $syntheticKioskConfigDirectory . DIRECTORY_SEPARATOR . '.env';
    $kioskConfig = "PAYMOB_API_KEY={$kioskApiKey}\n"
        . "PAYMOB_HMAC_SECRET={$kioskHmacSecret}\n"
        . "PAYMOB_BASE_URL=https://accept.paymob.com/api\n"
        . "PAYMOB_INTEGRATION_ID_CARD=980001\n"
        . "PAYMOB_INTEGRATION_ID_KIOSK={$kioskIntegrationId}\n"
        . "PAYMOB_INTEGRATION_ID_WALLET=980003\n";
    verify(
        file_put_contents($kioskConfigPath, $kioskConfig) === strlen($kioskConfig),
        'Synthetic Kiosk configuration could not be written.',
    );
    chmod($kioskConfigPath, 0600);
    $kioskConfigDTO = VerificationConfig::load($syntheticKioskConfigDirectory, 'kiosk');
    verify(
        $kioskConfigDTO->kioskIntegrationId === $kioskIntegrationId
        && $kioskConfigDTO->cardIntegrationId === 980001
        && $kioskConfigDTO->walletIntegrationId === 980003
        && $kioskConfigDTO->walletTestMsisdn === null,
        'Kiosk recovery configuration incorrectly requires or selects Wallet inputs.',
    );

    $kioskRequestDTO = new InitiateKioskPaymentCommand($kioskPaymentToken);
    $kioskDTORequest = $composeSyntheticAuthenticatedRequest($kioskRequestDTO->toArray(), $kioskAuthToken);
    verify(
        $kioskDTORequest === [
            'auth_token' => $kioskAuthToken,
            'source' => ['identifier' => 'AGGREGATOR', 'subtype' => 'AGGREGATOR'],
            'payment_token' => $kioskPaymentToken,
        ],
        'Kiosk request DTO did not produce the accepted AGGREGATOR request contract.',
    );
    echo "PASS Kiosk request DTO AGGREGATOR contract with synthetic tokens\n";

    $syntheticPaymentKeyConfigDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'paymob-classifier-payment-key-config-' . bin2hex(random_bytes(6));
    verify(
        mkdir($syntheticPaymentKeyConfigDirectory, 0700),
        'Synthetic Payment Key classifier configuration directory could not be created.',
    );
    $paymentKeyConfigPath = $syntheticPaymentKeyConfigDirectory . DIRECTORY_SEPARATOR . '.env';
    $paymentKeyConfig = "PAYMOB_API_KEY=classifier-self-check-api-key\n"
        . "PAYMOB_BASE_URL=https://accept.paymob.com/api\n"
        . "PAYMOB_HMAC_SECRET=classifier-self-check-hmac-secret\n"
        . "PAYMOB_INTEGRATION_ID_CARD=980001\n"
        . "PAYMOB_INTEGRATION_ID_KIOSK=980002\n"
        . "PAYMOB_INTEGRATION_ID_WALLET=980003\n";
    verify(
        file_put_contents($paymentKeyConfigPath, $paymentKeyConfig) === strlen($paymentKeyConfig),
        'Synthetic Payment Key classifier configuration could not be written.',
    );
    chmod($paymentKeyConfigPath, 0600);
    $cardPaymentKeyConfigDTO = VerificationConfig::load(
        $syntheticPaymentKeyConfigDirectory,
        'payment-key',
        'card',
    );
    $kioskPaymentKeyConfigDTO = VerificationConfig::load(
        $syntheticPaymentKeyConfigDirectory,
        'payment-key',
        'kiosk',
    );
    $walletPaymentKeyConfigDTO = VerificationConfig::load(
        $syntheticPaymentKeyConfigDirectory,
        'payment-key',
        'wallet',
    );

    $syntheticKioskResponse = [
        'id' => 887766,
        'order' => [
            'id' => 123456,
            'merchant_order_id' => $kioskMerchantOrderReference,
            'payment_status' => 'UNPAID',
        ],
        'amount_cents' => 15000,
        'currency' => 'EGP',
        'pending' => true,
        'success' => false,
        'created_at' => '2026-10-03T10:02:00Z',
        'updated_at' => '2026-10-03T10:03:00Z',
        'data' => [
            'bill_reference' => $kioskBillReference,
            'klass' => 'CAGGPayment',
            'message' => 'Pending Payment',
            'txn_response_code' => '05',
        ],
    ];
    $kioskResponseDTO = KioskPaymentResponseDTO::fromArray($syntheticKioskResponse);
    verify(
        $kioskResponseDTO->transactionId === 887766
        && $kioskResponseDTO->orderId === 123456
        && $kioskResponseDTO->merchantOrderId === $kioskMerchantOrderReference
        && $kioskResponseDTO->amountCents === 15000
        && $kioskResponseDTO->currency === CurrencyEnum::EGP
        && $kioskResponseDTO->pending === true
        && $kioskResponseDTO->success === false
        && $kioskResponseDTO->billReference === $kioskBillReference
        && $kioskResponseDTO->statusMessage === 'Pending Payment'
        && $kioskResponseDTO->paymentStatus === 'UNPAID'
        && $kioskResponseDTO->createdAt === '2026-10-03T10:02:00Z'
        && $kioskResponseDTO->updatedAt === '2026-10-03T10:03:00Z',
        'Kiosk response DTO did not map the accepted synthetic provider response.',
    );
    echo "PASS Kiosk response DTO mapping for IDs, amount/currency, outcome, bill reference, and status\n";

    $syntheticKioskResponseJson = json_encode($syntheticKioskResponse, JSON_THROW_ON_ERROR);
    $syntheticKioskResponseObject = json_decode($syntheticKioskResponseJson, false, 512, JSON_THROW_ON_ERROR);
    verify(
        $syntheticKioskResponseObject instanceof stdClass,
        'Synthetic Kiosk response did not decode to a JSON object.',
    );
    $kioskSanitizer = new SemanticSanitizer([$kioskApiKey, $kioskHmacSecret]);
    $kioskSanitizer->prime([$syntheticKioskResponseObject]);
    $sanitizedKioskResponse = $kioskSanitizer->sanitize($syntheticKioskResponseObject, '', '$.kiosk_response');
    verify(
        $kioskSanitizer->sameShape($syntheticKioskResponseObject, $sanitizedKioskResponse),
        'Kiosk sanitization changed JSON shape or scalar types.',
    );
    verify(
        $kioskSanitizer->semanticDifferences($syntheticKioskResponseObject, $sanitizedKioskResponse) === [],
        'Kiosk sanitization changed provider-semantic values.',
    );
    verify(
        $kioskSanitizer->sensitiveFieldDifferences($syntheticKioskResponseObject, $sanitizedKioskResponse) === [],
        'Kiosk sanitization left a sensitive field unchanged.',
    );
    verify(
        !$kioskSanitizer->containsSensitiveValues($sanitizedKioskResponse),
        'Synthetic Kiosk response failed the shared leak guard.',
    );
    verify(
        $sanitizedKioskResponse->data->klass === 'CAGGPayment'
        && $sanitizedKioskResponse->data->message === 'Pending Payment'
        && $sanitizedKioskResponse->data->txn_response_code === '05'
        && $sanitizedKioskResponse->order->payment_status === 'UNPAID'
        && $sanitizedKioskResponse->amount_cents === 15000
        && $sanitizedKioskResponse->currency === 'EGP'
        && $sanitizedKioskResponse->pending === true
        && $sanitizedKioskResponse->success === false,
        'Kiosk sanitization changed accepted provider semantics.',
    );
    verify(
        is_int($syntheticKioskResponseObject->data->bill_reference)
        && is_int($sanitizedKioskResponse->data->bill_reference)
        && $sanitizedKioskResponse->data->bill_reference !== $syntheticKioskResponseObject->data->bill_reference,
        'Kiosk bill_reference sanitization did not preserve scalar type while replacing its private value.',
    );
    echo "PASS Kiosk shared sanitizer shape, semantics, sensitive fields, private bill reference, and leak guard\n";

    $makeKioskRecoveryDirectory = static function (string $label) use (
        $privateNamespace,
        &$syntheticKioskRecoveryDirectories,
    ): string {
        $directory = $privateNamespace . DIRECTORY_SEPARATOR . 'run-self-check-kiosk-'
            . $label . '-' . bin2hex(random_bytes(6));
        verify(mkdir($directory, 0700), 'Synthetic Kiosk retained run could not be created.');
        chmod($directory, 0700);
        $syntheticKioskRecoveryDirectories[] = $directory;

        return $directory;
    };
    $writeRecoveryRun = static function (string $directory, array $exchanges): void {
        foreach ($exchanges as $index => [$url, $request, $response]) {
            $prefix = str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT);
            $requestBody = is_string($request) ? $request : json_encode($request, JSON_THROW_ON_ERROR);
            $responseBody = is_string($response) ? $response : json_encode($response, JSON_THROW_ON_ERROR);
            foreach ([
                $prefix . '-request-url.txt' => $url,
                $prefix . '-request-body.bin' => $requestBody,
                $prefix . '-response-body.bin' => $responseBody,
            ] as $fileName => $contents) {
                $path = $directory . DIRECTORY_SEPARATOR . $fileName;
                verify(
                    file_put_contents($path, $contents, LOCK_EX) === strlen($contents),
                    'Synthetic retained-run exchange file could not be written.',
                );
                chmod($path, 0600);
            }
        }
    };

    $transactionSanitizer = new SemanticSanitizer();
    $transactionResponseObject = (object)[
        'id' => 42,
        'order' => (object)['id' => 123456, 'payment_status' => 'UNPAID'],
        'amount_cents' => 15000,
        'currency' => 'EGP',
        'success' => false,
        'pending' => true,
    ];
    $transactionSanitizer->prime([$transactionUrl, $transactionResponseObject]);
    $safeTransactionUrl = $transactionSanitizer->sanitizeUrl($transactionUrl);
    $safeTransactionResponse = $transactionSanitizer->sanitize($transactionResponseObject);
    $safeTransactionPath = parse_url($safeTransactionUrl, PHP_URL_PATH);
    verify(
        is_string($safeTransactionPath)
        && basename($safeTransactionPath) === (string)$safeTransactionResponse->id
        && basename($safeTransactionPath) !== '42'
        && $transactionSanitizer->sanitizeUrl('https://accept.paymob.com/api/other/42')
            === 'https://accept.paymob.com/api/other/42'
        && !$transactionSanitizer->containsSensitiveValues([
            'final_url' => $safeTransactionUrl,
            'response' => $safeTransactionResponse,
        ]),
        'Short Transaction ID URL sanitization lost privacy or referential consistency.',
    );
    echo "PASS context-specific Transaction ID URL and response mapping\n";

    $transactionRecoveryDirectory = $privateNamespace . DIRECTORY_SEPARATOR
        . 'run-self-check-transaction-' . bin2hex(random_bytes(6));
    verify(mkdir($transactionRecoveryDirectory, 0700), 'Synthetic Transaction Inquiry run could not be created.');
    chmod($transactionRecoveryDirectory, 0700);
    $syntheticTransactionRecoveryDirectories[] = $transactionRecoveryDirectory;
    $writeRecoveryRun($transactionRecoveryDirectory, [
        [
            'https://accept.paymob.com/api/auth/tokens',
            ['api_key' => 'self-check-api-key-value'],
            ['token' => 'synthetic-transaction-auth-token', 'profile' => ['id' => 77]],
        ],
        [$transactionUrl, '', [
            'id' => 42,
            'order' => ['id' => 123456, 'payment_status' => 'UNPAID'],
            'amount_cents' => 15000,
            'currency' => 'EGP',
            'success' => false,
            'pending' => true,
        ]],
    ]);
    $transactionSourceBefore = $snapshotSourceRun($transactionRecoveryDirectory);
    $transactionRecovery = RetainedRunRecovery::recover(
        $configurationDirectory,
        $transactionRecoveryDirectory,
        'transaction-inquiry',
    );
    if (is_string($transactionRecovery['artifact']['path'] ?? null)) {
        $transactionRecoveryArtifactPaths[] = $transactionRecovery['artifact']['path'];
    }
    if (is_string($transactionRecovery['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $transactionRecovery['failure_artifact']['path'];
    }
    verify(
        $transactionRecovery['result'] === 'PASS'
        && $transactionRecovery['recovered_stages'] === ['auth', 'transaction-inquiry']
        && $transactionRecovery['source_raw_retained'] === true
        && $transactionSourceBefore === $snapshotSourceRun($transactionRecoveryDirectory),
        'Synthetic Transaction Inquiry recovery failed or modified retained raw evidence.',
    );
    $transactionRecovered = $transactionRecovery['report'];
    $transactionRecoveredGet = $transactionRecovered['exchanges'][1];
    $recoveredTransactionPath = parse_url($transactionRecoveredGet['final_url'], PHP_URL_PATH);
    verify(
        $transactionRecoveredGet['sanitized_request'] === null
        && $transactionRecoveredGet['request_body_bytes'] === 0
        && $transactionRecoveredGet['request_json_valid'] === null
        && $transactionRecovered['exchanges'][0]['request_json_valid'] === true
        && is_string($recoveredTransactionPath)
        && basename($recoveredTransactionPath)
            === (string)$transactionRecoveredGet['sanitized_response_fixture_candidate']['id']
        && basename($recoveredTransactionPath) !== '42'
        && $transactionRecoveredGet['sanitized_response_fixture_candidate']['success'] === false
        && $transactionRecoveredGet['sanitized_response_fixture_candidate']['pending'] === true
        && $transactionRecoveredGet['sanitized_response_fixture_candidate']['order']['payment_status'] === 'UNPAID'
        && $transactionRecovered['leak_guard_result'] === 'PASS'
        && $transactionRecovered['referential_consistency_result'] === 'PASS',
        'Recovered empty GET or sanitized Transaction ID mapping did not preserve its contract.',
    );
    $transactionArtifact = $transactionRecovery['artifact'];
    $transactionArtifactBytes = file_get_contents($transactionArtifact['path']);
    verify(
        is_string($transactionArtifactBytes)
        && strlen($transactionArtifactBytes) === $transactionArtifact['bytes']
        && hash_equals($transactionArtifact['sha256'], hash('sha256', $transactionArtifactBytes))
        && (fileperms(dirname($transactionArtifact['path'])) & 0777) === 0700
        && (fileperms($transactionArtifact['path']) & 0777) === 0600
        && !str_contains($transactionArtifactBytes, $transactionUrl)
        && !str_contains($transactionArtifactBytes, 'synthetic-transaction-auth-token'),
        'Transaction Inquiry recovery artifact failed integrity, permissions, or privacy checks.',
    );
    $transactionRecoveryAgain = RetainedRunRecovery::recover(
        $configurationDirectory,
        $transactionRecoveryDirectory,
        'transaction-inquiry',
    );
    if (is_string($transactionRecoveryAgain['artifact']['path'] ?? null)) {
        $transactionRecoveryArtifactPaths[] = $transactionRecoveryAgain['artifact']['path'];
    }
    if (is_string($transactionRecoveryAgain['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $transactionRecoveryAgain['failure_artifact']['path'];
    }
    verify(
        $transactionRecoveryAgain['result'] === 'PASS'
        && $transactionRecoveryAgain['artifact']['path'] !== $transactionArtifact['path']
        && $transactionSourceBefore === $snapshotSourceRun($transactionRecoveryDirectory)
        && hash_equals($transactionArtifact['sha256'], hash_file('sha256', $transactionArtifact['path'])),
        'Repeated Transaction Inquiry recovery replaced an artifact or changed retained raw evidence.',
    );

    $nonemptyTransactionDirectory = $privateNamespace . DIRECTORY_SEPARATOR
        . 'run-self-check-transaction-nonempty-' . bin2hex(random_bytes(6));
    verify(mkdir($nonemptyTransactionDirectory, 0700), 'Synthetic nonempty GET run could not be created.');
    chmod($nonemptyTransactionDirectory, 0700);
    $syntheticTransactionRecoveryDirectories[] = $nonemptyTransactionDirectory;
    $writeRecoveryRun($nonemptyTransactionDirectory, [
        [$transactionUrl, 'null', ['id' => 42]],
    ]);
    $nonemptyTransactionBefore = $snapshotSourceRun($nonemptyTransactionDirectory);
    $nonemptyRecovery = RetainedRunRecovery::recover(
        $configurationDirectory,
        $nonemptyTransactionDirectory,
        'transaction-inquiry',
    );
    if (is_string($nonemptyRecovery['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $nonemptyRecovery['failure_artifact']['path'];
    }
    verify(
        $nonemptyRecovery['result'] === 'FAIL'
        && $nonemptyRecovery['source_raw_retained'] === true
        && $nonemptyTransactionBefore === $snapshotSourceRun($nonemptyTransactionDirectory),
        'Recovery accepted a nonempty Transaction Inquiry GET body or changed retained raw evidence.',
    );
    echo "PASS Transaction Inquiry empty-GET recovery, source retention, and artifact verification\n";

    $kioskAuthExchange = [
        'https://accept.paymob.com/api/auth/tokens',
        ['api_key' => $kioskApiKey],
        [
            'token' => $kioskAuthToken,
            'profile' => [
                'id' => 456789,
                'email' => 'kiosk-private-person@example.org',
                'phones' => ['synthetic-kiosk-auth-phone-zero', 'synthetic-kiosk-auth-phone-one', null],
                'sms_sender_name' => 'Synthetic Kiosk Private Sender',
            ],
            'issued_at' => '2026-10-03T10:00:00Z',
        ],
    ];
    $kioskOrderExchange = [
        'https://accept.paymob.com/api/ecommerce/orders',
        $makeSyntheticOrderRequest($kioskAuthToken, $kioskMerchantOrderReference),
        [
            'id' => 123456,
            'merchant_order_id' => $kioskMerchantOrderReference,
            'payment_status' => 'UNPAID',
            'created_at' => '2026-10-03T10:01:00Z',
        ],
    ];
    $kioskPaymentKeyExchange = [
        'https://accept.paymob.com/api/acceptance/payment_keys',
        [
            'auth_token' => $kioskAuthToken,
            'order_id' => 123456,
            'integration_id' => $kioskIntegrationId,
            'amount_cents' => 15000,
            'currency' => 'EGP',
            'expiration' => 180,
            'billing_data' => [
                'first_name' => 'Synthetic Kiosk Customer',
                'last_name' => 'Private Example',
                'building' => '77',
                'floor' => '2',
                'apartment' => '4',
                'postal_code' => '12345',
                'street' => 'Synthetic Kiosk Private Street',
                'city' => 'Synthetic Kiosk City',
                'state' => 'Synthetic Kiosk State',
                'email' => 'kiosk-private-person@example.org',
                'phone_number' => '+201234567890',
            ],
        ],
        [
            'token' => $kioskPaymentToken,
            'order_id' => 123456,
            'integration_id' => $kioskIntegrationId,
            'currency' => 'EGP',
            'status' => 'issued',
        ],
    ];
    $kioskPaymentExchange = [
        'https://accept.paymob.com/api/acceptance/payments/pay',
        $composeSyntheticAuthenticatedRequest($kioskRequestDTO->toArray(), $kioskAuthToken),
        $syntheticKioskResponse,
    ];
    $kioskFourExchangeRun = [
        $kioskAuthExchange,
        $kioskOrderExchange,
        $kioskPaymentKeyExchange,
        $kioskPaymentExchange,
    ];
    $kioskLiveStages = $classifyWithCapturingClient($kioskConfigDTO, $kioskFourExchangeRun);
    verify(
        $kioskLiveStages === ['auth', 'order', 'payment-key-kiosk', 'kiosk-payment'],
        'The shared live classifier did not derive the normal Kiosk stage sequence.',
    );
    echo "PASS shared live classifier normal Kiosk sequence\n";

    $authUrl = 'https://accept.paymob.com/api/auth/tokens';
    $orderUrl = 'https://accept.paymob.com/api/ecommerce/orders';
    $paymentKeyUrl = 'https://accept.paymob.com/api/acceptance/payment_keys';
    $paymentUrl = 'https://accept.paymob.com/api/acceptance/payments/pay';
    $syntheticRefreshAuthToken = 'classifier-synthetic-refreshed-auth-token';
    $classifierAuthExchange = [
        $authUrl,
        ['api_key' => 'classifier-synthetic-api-key'],
        ['token' => 'classifier-synthetic-auth-token'],
    ];
    $classifierOrderExchange = [
        $orderUrl,
        $makeSyntheticOrderRequest('classifier-synthetic-auth-token', 'classifier-order-reference'),
        [],
    ];
    $classifierRefreshAuthExchange = [
        $authUrl,
        ['api_key' => 'classifier-synthetic-api-key'],
        ['token' => $syntheticRefreshAuthToken],
    ];
    $classifierOrderRetryExchange = [
        $orderUrl,
        $makeSyntheticOrderRequest($syntheticRefreshAuthToken, 'classifier-order-reference'),
        [],
    ];
    $cardIntegrationId = $cardPaymentKeyConfigDTO->cardIntegrationId;
    $paymentKeyKioskIntegrationId = $kioskPaymentKeyConfigDTO->kioskIntegrationId;
    $paymentKeyWalletIntegrationId = $walletPaymentKeyConfigDTO->walletIntegrationId;
    verify(
        $cardIntegrationId === 980001
        && $paymentKeyKioskIntegrationId === 980002
        && $paymentKeyWalletIntegrationId === 980003,
        'Payment Key classifier fixtures did not select each method integration from configuration.',
    );
    $cardPaymentKeyExchange = [
        $paymentKeyUrl,
        $makeSyntheticPaymentKeyRequest((int)$cardIntegrationId, 'classifier-synthetic-auth-token'),
        [],
    ];
    $cardPaymentKeyRetryExchange = [
        $paymentKeyUrl,
        $makeSyntheticPaymentKeyRequest((int)$cardIntegrationId, $syntheticRefreshAuthToken),
        [],
    ];
    $kioskSelectedPaymentKeyExchange = [
        $paymentKeyUrl,
        $makeSyntheticPaymentKeyRequest((int)$paymentKeyKioskIntegrationId, 'classifier-synthetic-auth-token'),
        [],
    ];
    $walletSelectedPaymentKeyExchange = [
        $paymentKeyUrl,
        $makeSyntheticPaymentKeyRequest((int)$paymentKeyWalletIntegrationId, 'classifier-synthetic-auth-token'),
        [],
    ];
    $cardPaymentKeyRetryStages = $classifyWithCapturingClient($cardPaymentKeyConfigDTO, [
        $classifierAuthExchange,
        $classifierOrderExchange,
        [...$cardPaymentKeyExchange, 401],
        $classifierRefreshAuthExchange,
        $cardPaymentKeyRetryExchange,
    ]);
    verify(
        $cardPaymentKeyRetryStages === [
            'auth', 'order', 'payment-key-card', 'auth', 'payment-key-card-retry',
        ],
        'Synthetic Card Payment Key classification did not select its configured integration or retry stage.',
    );
    verify(
        $classifyWithCapturingClient($kioskPaymentKeyConfigDTO, [
            $classifierAuthExchange,
            $classifierOrderExchange,
            $kioskSelectedPaymentKeyExchange,
        ]) === ['auth', 'order', 'payment-key-kiosk'],
        'Payment Key scenario did not select the configured Kiosk integration.',
    );
    verify(
        $classifyWithCapturingClient($walletPaymentKeyConfigDTO, [
            $classifierAuthExchange,
            $classifierOrderExchange,
            $walletSelectedPaymentKeyExchange,
        ]) === ['auth', 'order', 'payment-key-wallet'],
        'Payment Key scenario did not select the configured Wallet integration.',
    );
    $orderOnlyConfigDTO = VerificationConfig::load($configurationDirectory, 'order');
    verify(
        $classifyWithCapturingClient($authConfig, [[$authUrl, ['api_key' => 'self-check-api-key-value'], []]])
            === ['auth']
        && $classifyWithCapturingClient($orderOnlyConfigDTO, [
            [$authUrl, ['api_key' => 'self-check-api-key-value'], []],
            [$orderUrl, $makeSyntheticOrderRequest('classifier-synthetic-auth-token', 'order-only-reference'), []],
        ]) === ['auth', 'order'],
        'Auth or Order scenario did not use the shared classifier.',
    );
    $objectAuthClient = $createCapturingClient($authConfig);
    $objectAuthStage = $objectAuthClient->classifyAttemptStage($authUrl, (object)['api_key' => 'self-check-api-key-value']);
    verify($objectAuthStage === 'auth', 'Shared classifier did not accept retained JSON object request input.');
    $objectAuthClient->recordClassifiedOutcome($objectAuthStage, true, 200);
    echo "PASS Auth, Order, Payment Key card/kiosk/wallet, and array/object input classification\n";

    $cardApiKey = 'classifier-self-check-api-key';
    $cardAuthToken = 'synthetic-card-private-auth-token';
    $cardRefreshedAuthToken = 'synthetic-card-private-refreshed-auth-token';
    $cardPaymentToken = 'synthetic-card-private-payment-key-token';
    $cardRetryPaymentToken = 'synthetic-card-private-retry-payment-key-token';
    $cardMerchantReference = 'synthetic-card-private-merchant-order-reference';
    $cardOtherReference = 'synthetic-card-private-other-endpoint-reference';
    $cardOrderId = 123456;
    $cardAccountId = 456789;
    $cardBilling = new BillingData(
        firstName: 'Private Card Given',
        lastName: 'Private Card Family',
        email: 'private-card-person@example.org',
        phoneNumber: '+201234567890',
        country: 'NA',
        city: 'Private Card City',
        street: 'Private Card Street',
        building: '77',
        floor: '2',
        apartment: '4',
        postalCode: '12345',
        state: 'Private Card State',
    );
    $cardGeneratePaymentKeyCommand = new GeneratePaymentKeyCommand(
        orderId: $cardOrderId,
        integrationId: (int)$cardIntegrationId,
        amountCents: 15000,
        currency: CurrencyEnum::EGP,
        billingData: $cardBilling,
        expirationSeconds: 180,
    );
    $cardPaymentKeyRequest = $composeSyntheticAuthenticatedRequest($cardGeneratePaymentKeyCommand->toArray(), $cardAuthToken);
    verify(
        array_diff([
            'auth_token', 'order_id', 'integration_id', 'amount_cents', 'currency', 'expiration', 'billing_data',
        ], array_keys($cardPaymentKeyRequest)) === []
        && $cardPaymentKeyRequest['integration_id'] === $cardIntegrationId,
        'Synthetic Card Payment Key request is incomplete or did not select its configured integration.',
    );
    $cardAuthExchange = [
        $authUrl,
        ['api_key' => $cardApiKey],
        [
            'token' => $cardAuthToken,
            'profile' => ['id' => $cardAccountId, 'email' => 'private-card-person@example.org'],
        ],
    ];
    $cardOrderExchange = [
        $orderUrl,
        $makeSyntheticOrderRequest($cardAuthToken, $cardMerchantReference),
        [
            'id' => $cardOrderId,
            'merchant_order_id' => $cardMerchantReference,
            'other_endpoint_reference' => $cardOtherReference,
            'payment_status' => 'UNPAID',
        ],
    ];
    $cardPaymentKeyExchange = [
        $paymentKeyUrl,
        $cardPaymentKeyRequest,
        [
            'token' => $cardPaymentToken,
            'order' => $cardOrderId,
            'integration_id' => $cardIntegrationId,
            'currency' => 'EGP',
            'status' => 'issued',
        ],
    ];
    $cardNormalExchanges = [$cardAuthExchange, $cardOrderExchange, $cardPaymentKeyExchange];
    $cardNormalLiveStages = $classifyWithCapturingClient($cardPaymentKeyConfigDTO, $cardNormalExchanges);
    verify(
        $cardNormalLiveStages === ['auth', 'order', 'payment-key-card']
        && $cardOrderExchange[1]['auth_token'] === $cardAuthToken,
        'Synthetic Card normal requests did not follow the DTO and shared live classifier contracts.',
    );

    $makeCardRecoveryDirectory = static function (string $label) use (
        $privateNamespace,
        &$syntheticCardRecoveryDirectories,
    ): string {
        $directory = $privateNamespace . DIRECTORY_SEPARATOR . 'run-self-check-card-'
            . $label . '-' . bin2hex(random_bytes(6));
        verify(mkdir($directory, 0700), 'Synthetic Card retained run could not be created.');
        chmod($directory, 0700);
        $syntheticCardRecoveryDirectories[] = $directory;

        return $directory;
    };
    $recoverCard = static function (string $directory) use (
        $syntheticPaymentKeyConfigDirectory,
        &$cardRecoveryArtifactPaths,
        &$unexpectedRecoveryFailureArtifactPaths,
    ): array {
        $result = RetainedRunRecovery::recover(
            $syntheticPaymentKeyConfigDirectory,
            $directory,
            'payment-key',
            'card',
        );
        if (is_string($result['artifact']['path'] ?? null)) {
            $cardRecoveryArtifactPaths[] = $result['artifact']['path'];
        }
        if (is_string($result['failure_artifact']['path'] ?? null)) {
            $unexpectedRecoveryFailureArtifactPaths[] = $result['failure_artifact']['path'];
        }

        return $result;
    };

    $cardNormalDirectory = $makeCardRecoveryDirectory('normal');
    $writeRecoveryRun($cardNormalDirectory, $cardNormalExchanges);
    $cardNormalSourceSnapshot = $snapshotSourceRun($cardNormalDirectory);
    $cardNormalRecovery = $recoverCard($cardNormalDirectory);
    verify(
        $cardNormalRecovery['result'] === 'PASS'
        && $cardNormalRecovery['scenario'] === 'payment-key'
        && $cardNormalRecovery['payment_method'] === 'card'
        && $cardNormalRecovery['recovered_stages'] === $cardNormalLiveStages
        && $cardNormalRecovery['exchange_count'] === 3
        && $cardNormalRecovery['source_raw_retained'] === true,
        'Synthetic normal Card Payment Key recovery failed or lost its explicit identity/stages.',
    );
    $cardNormalSnapshot = $validateRecoveryArtifact(
        $cardNormalRecovery,
        'payment-key',
        basename($cardNormalDirectory),
        'card',
    );
    $cardReport = $cardNormalRecovery['report'];
    verify(
        $cardReport['source']['scenario'] === 'payment-key'
        && $cardReport['source']['payment_method'] === 'card'
        && $cardReport['leak_guard_result'] === 'PASS'
        && $cardReport['referential_consistency_result'] === 'PASS',
        'Card recovery report omitted explicit identity or sanitizer safety results.',
    );

    $cardSanitizer = new SemanticSanitizer([$cardApiKey]);
    $cardRawValues = [];
    foreach ($cardNormalExchanges as [$url, $request, $response]) {
        $cardRawValues[] = $url;
        $cardRawValues[] = json_decode(json_encode($request, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $cardRawValues[] = json_decode(json_encode($response, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
    $cardSanitizer->prime($cardRawValues);
    foreach ($cardNormalExchanges as $index => [$url, $request, $response]) {
        $rawRequest = $cardRawValues[$index * 3 + 1];
        $rawResponse = $cardRawValues[$index * 3 + 2];
        $safeRequest = json_decode(
            json_encode($cardReport['exchanges'][$index]['sanitized_request'], JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $safeResponse = json_decode(
            json_encode($cardReport['exchanges'][$index]['sanitized_response_fixture_candidate'], JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        verify(
            $cardSanitizer->sameShape($rawRequest, $safeRequest)
            && $cardSanitizer->sameShape($rawResponse, $safeResponse)
            && $cardSanitizer->semanticDifferences($rawRequest, $safeRequest) === []
            && $cardSanitizer->semanticDifferences($rawResponse, $safeResponse) === []
            && $cardSanitizer->sensitiveFieldDifferences($rawRequest, $safeRequest) === []
            && $cardSanitizer->sensitiveFieldDifferences($rawResponse, $safeResponse) === [],
            'Card recovery changed JSON shape or semantics, or retained sensitive fields.',
        );
    }
    verify(!$cardSanitizer->containsSensitiveValues($cardReport), 'Card recovery failed the shared leak guard.');
    $safeCardAuth = $cardReport['exchanges'][0]['sanitized_response_fixture_candidate'];
    $safeCardOrderRequest = $cardReport['exchanges'][1]['sanitized_request'];
    $safeCardOrderResponse = $cardReport['exchanges'][1]['sanitized_response_fixture_candidate'];
    $safeCardPaymentKeyRequest = $cardReport['exchanges'][2]['sanitized_request'];
    $safeCardPaymentKeyResponse = $cardReport['exchanges'][2]['sanitized_response_fixture_candidate'];
    $safeCardBilling = $safeCardPaymentKeyRequest['billing_data'];
    verify(
        $safeCardOrderResponse['id'] === $safeCardPaymentKeyRequest['order_id']
        && $safeCardPaymentKeyRequest['order_id'] === $safeCardPaymentKeyResponse['order']
        && $safeCardOrderRequest['merchant_order_id'] === $safeCardOrderResponse['merchant_order_id']
        && $safeCardOrderRequest['merchant_order_id'] !== $cardMerchantReference
        && is_int($safeCardPaymentKeyRequest['integration_id'])
        && $safeCardPaymentKeyRequest['integration_id'] === $safeCardPaymentKeyResponse['integration_id']
        && $safeCardPaymentKeyRequest['integration_id'] !== $cardIntegrationId
        && $safeCardAuth['profile']['id'] !== $safeCardOrderResponse['id']
        && $safeCardOrderResponse['id'] !== $safeCardPaymentKeyRequest['integration_id']
        && $safeCardOrderResponse['other_endpoint_reference'] !== $safeCardOrderResponse['merchant_order_id']
        && $safeCardPaymentKeyResponse['token'] === '<REDACTED_SECRET>'
        && $safeCardBilling['first_name'] === '<SANITIZED_NAME>'
        && $safeCardBilling['last_name'] === '<SANITIZED_NAME>'
        && $safeCardBilling['email'] === 'customer@example.test'
        && $safeCardBilling['phone_number'] === '+20000000000'
        && $safeCardBilling['street'] === '<SANITIZED_ADDRESS>'
        && $safeCardBilling['city'] === '<SANITIZED_ADDRESS>'
        && $safeCardBilling['state'] === '<SANITIZED_ADDRESS>'
        && $safeCardBilling['postal_code'] === '<SANITIZED_ADDRESS>'
        && $safeCardBilling['building'] !== '77'
        && $safeCardBilling['floor'] !== '2'
        && $safeCardBilling['apartment'] !== '4',
        'Card recovery did not preserve distinct, type-safe Order/Integration/account/reference mappings or token secrecy.',
    );
    foreach ([
        $cardApiKey, $cardAuthToken, $cardPaymentToken, $cardMerchantReference, $cardOtherReference,
        'private-card-person@example.org', '+201234567890', 'Private Card Given', 'Private Card Family',
        'Private Card City', 'Private Card Street', 'Private Card State',
        (string)$cardOrderId, (string)$cardAccountId, (string)$cardIntegrationId,
    ] as $cardPrivateLiteral) {
        verify(
            !str_contains($cardNormalSnapshot['contents'], $cardPrivateLiteral),
            'Card recovery artifact retained a synthetic secret, PII, private reference, or ID.',
        );
    }
    verify(
        $cardNormalSourceSnapshot === $snapshotSourceRun($cardNormalDirectory),
        'Normal Card recovery modified its synthetic retained source run.',
    );
    echo "PASS synthetic normal Card Payment Key recovery, sanitizer safety, and referential consistency\n";

    $cardNormalRecoveryB = $recoverCard($cardNormalDirectory);
    verify(
        $cardNormalRecoveryB['result'] === 'PASS'
        && $cardNormalRecoveryB['artifact']['path'] !== $cardNormalRecovery['artifact']['path'],
        'Repeated Card recovery reused an existing artifact name.',
    );
    $cardNormalSnapshotB = $validateRecoveryArtifact(
        $cardNormalRecoveryB,
        'payment-key',
        basename($cardNormalDirectory),
        'card',
    );
    verify(
        $cardNormalSnapshot === $snapshotFile($cardNormalRecovery['artifact']['path'])
        && $cardNormalSnapshotB === $snapshotFile($cardNormalRecoveryB['artifact']['path'])
        && $cardNormalSourceSnapshot === $snapshotSourceRun($cardNormalDirectory),
        'Repeated Card recovery replaced an artifact or modified retained raw evidence.',
    );
    echo "PASS Card recovery unique non-replacing artifacts, verified bytes/hash, and owner-only permissions\n";

    $cardRetryAuthExchange = [
        $authUrl,
        ['api_key' => $cardApiKey],
        ['token' => $cardRefreshedAuthToken, 'profile' => ['id' => $cardAccountId]],
    ];
    $cardRetryExchanges = [
        $cardAuthExchange,
        $cardOrderExchange,
        [...$cardPaymentKeyExchange, 401],
        $cardRetryAuthExchange,
        [
            $paymentKeyUrl,
            $composeSyntheticAuthenticatedRequest($cardGeneratePaymentKeyCommand->toArray(), $cardRefreshedAuthToken),
            [
                'token' => $cardRetryPaymentToken,
                'order' => $cardOrderId,
                'integration_id' => $cardIntegrationId,
                'currency' => 'EGP',
                'status' => 'issued',
            ],
        ],
    ];
    $cardRetryLiveStages = $classifyWithCapturingClient($cardPaymentKeyConfigDTO, $cardRetryExchanges);
    verify(
        $cardRetryLiveStages === ['auth', 'order', 'payment-key-card', 'auth', 'payment-key-card-retry'],
        'The shared live classifier did not derive the Card Payment Key retry sequence.',
    );
    $cardRetryDirectory = $makeCardRecoveryDirectory('retry');
    $writeRecoveryRun($cardRetryDirectory, $cardRetryExchanges);
    $cardRetrySourceSnapshot = $snapshotSourceRun($cardRetryDirectory);
    $cardRetryRecovery = $recoverCard($cardRetryDirectory);
    if (is_string($cardRetryRecovery['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $cardRetryRecovery['failure_artifact']['path'];
    }
    verify(
        $cardRetryRecovery['result'] === 'FAIL'
        && $cardRetryRecovery['recovery_proof'] === 'unavailable_without_http_status'
        && !in_array('payment-key-card-retry', $cardRetryRecovery['recovered_stages'], true)
        && $cardRetrySourceSnapshot === $snapshotSourceRun($cardRetryDirectory),
        'Card retained recovery inferred an unproven 401 transition or modified raw evidence.',
    );
    echo "PASS retained Card retry-looking sequence fails closed without HTTP status proof\n";

    foreach ([
        ['payment-key', null],
        ['payment-key', 'kiosk'],
        ['wallet', 'card'],
    ] as [$invalidScenario, $invalidMethod]) {
        $invalidRecovery = RetainedRunRecovery::recover(
            $syntheticPaymentKeyConfigDirectory,
            $cardNormalDirectory,
            $invalidScenario,
            $invalidMethod,
        );
        if (is_string($invalidRecovery['failure_artifact']['path'] ?? null)) {
            $cardRecoveryArtifactPaths[] = $invalidRecovery['failure_artifact']['path'];
        }
        verify(
            $invalidRecovery['result'] === 'FAIL'
            && $invalidRecovery['exchange_count'] === 0
            && $invalidRecovery['source_raw_retained'] === false,
            'Unsupported recovery scenario/method combination did not fail before retained raw discovery.',
        );
    }
    echo "PASS Card recovery explicit-method fail-closed combinations\n";

    $contextReflection = new ReflectionClass(VerificationContext::class);
    $mappingProbe = $contextReflection->newInstanceWithoutConstructor();
    $mappingGuard = $contextReflection->getMethod('assertPaymentKeyOrderMapping');
    $mappingGuard->invoke($mappingProbe, $cardOrderId, $cardOrderId);
    $mappingResult = $contextReflection->getProperty('paymentKeyOrderIdMatchesCreatedOrder');
    verify($mappingResult->getValue($mappingProbe) === true, 'Matching Payment Key order IDs were not observed.');
    $mappingRejected = false;
    try {
        $mappingGuard->invoke($mappingProbe, $cardOrderId, 0);
    } catch (RuntimeException $exception) {
        $mappingRejected = true;
        verify(
            $mappingResult->getValue($mappingProbe) === false
            && $contextReflection->getProperty('stage')->getValue($mappingProbe) === 'payment-key-order-mapping',
            'Payment Key order-ID mismatch lost its safe boolean or failure stage.',
        );
    }
    verify($mappingRejected, 'Mismatched Payment Key order IDs did not fail closed.');
    echo "PASS Payment Key order-ID mapping observability and mismatch fail-closed guard\n";

    $kioskOrderRetryStages = $classifyWithCapturingClient($kioskConfigDTO, [
        $kioskAuthExchange,
        [...$kioskOrderExchange, 401],
        $classifierRefreshAuthExchange,
        $classifierOrderRetryExchange,
        $kioskPaymentKeyExchange,
        $kioskPaymentExchange,
    ]);
    verify(
        $kioskOrderRetryStages === [
            'auth', 'order', 'auth', 'order-retry', 'payment-key-kiosk', 'kiosk-payment',
        ],
        'Kiosk Order retry did not retain the Auth/Order semantic sequence.',
    );
    $kioskPaymentKeyRetryRequest = $kioskPaymentKeyExchange[1];
    $kioskPaymentKeyRetryRequest['auth_token'] = $syntheticRefreshAuthToken;
    $kioskPaymentKeyRetryStages = $classifyWithCapturingClient($kioskConfigDTO, [
        $kioskAuthExchange,
        $kioskOrderExchange,
        [...$kioskPaymentKeyExchange, 401],
        $classifierRefreshAuthExchange,
        [$paymentKeyUrl, $kioskPaymentKeyRetryRequest, []],
        $kioskPaymentExchange,
    ]);
    verify(
        $kioskPaymentKeyRetryStages === [
            'auth', 'order', 'payment-key-kiosk', 'auth', 'payment-key-kiosk-retry', 'kiosk-payment',
        ],
        'Kiosk Payment Key retry did not use its independent attempt counter.',
    );

    $walletInitialRequest = json_decode($syntheticExchanges[3][1], true, 512, JSON_THROW_ON_ERROR);
    $walletRefreshAuthExchange = [
        $authUrl,
        ['api_key' => 'recovery-self-check-api-secret'],
        ['token' => 'wallet-classifier-refreshed-auth-token'],
    ];
    $walletOrderRetryStages = $classifyWithCapturingClient($walletRecoveryConfigDTO, [
        $syntheticExchanges[0],
        [...$syntheticExchanges[1], 401],
        $walletRefreshAuthExchange,
        [
            $orderUrl,
            $makeSyntheticOrderRequest('wallet-classifier-refreshed-auth-token', 'recovery-private-order-ref'),
            [],
        ],
        $syntheticExchanges[2],
        $syntheticExchanges[3],
    ]);
    verify(
        $walletOrderRetryStages === [
            'auth', 'order', 'auth', 'order-retry', 'payment-key-wallet', 'wallet-initiation',
        ],
        'Wallet Order retry did not use the shared classifier.',
    );
    $walletPaymentKeyRetryStages = $classifyWithCapturingClient($walletRecoveryConfigDTO, [
        $syntheticExchanges[0],
        $syntheticExchanges[1],
        [...$syntheticExchanges[2], 401],
        $walletRefreshAuthExchange,
        [$paymentKeyUrl, $makeSyntheticPaymentKeyRequest(980003, 'wallet-classifier-refreshed-auth-token'), []],
        $syntheticExchanges[3],
    ]);
    verify(
        $walletPaymentKeyRetryStages === [
            'auth', 'order', 'payment-key-wallet', 'auth', 'payment-key-wallet-retry', 'wallet-initiation',
        ],
        'Wallet Payment Key retry did not use its independent attempt counter.',
    );
    $walletPaymentRetryRequest = $walletInitialRequest;
    $walletPaymentRetryRequest['auth_token'] = 'wallet-classifier-refreshed-auth-token';
    $expectWorkflowFailure($walletRecoveryConfigDTO, [
        $syntheticExchanges[0],
        $syntheticExchanges[1],
        $syntheticExchanges[2],
        [...$syntheticExchanges[3], 401],
        $walletRefreshAuthExchange,
        [$paymentUrl, $walletPaymentRetryRequest, []],
    ], 'Wallet Pay 401 and retry');
    echo "PASS Kiosk and Wallet Order/Payment Key 401 recovery; Wallet Pay retry rejected\n";

    $classifierFailureCases = 0;
    $expectClassifierFailureCase = static function (
        VerificationConfig $config,
        string $url,
        mixed $request,
        string $description,
    ) use (&$classifierFailureCases, $createCapturingClient, $expectClassificationFailure): void {
        $expectClassificationFailure($createCapturingClient($config), $url, $request, $description);
        $classifierFailureCases++;
    };
    $expectClassifierFailureCase($authConfig, $authUrl, ['wrong' => 'shape'], 'missing Auth api_key');
    $expectClassifierFailureCase($orderOnlyConfigDTO, $orderUrl, [
        'amount_cents' => 15000,
        'currency' => 'EGP',
    ], 'missing Order auth_token');
    $missingPaymentKeyExpiration = $makeSyntheticPaymentKeyRequest((int)$cardIntegrationId, 'synthetic-token');
    unset($missingPaymentKeyExpiration['expiration']);
    $expectClassifierFailureCase(
        $cardPaymentKeyConfigDTO,
        $paymentKeyUrl,
        $missingPaymentKeyExpiration,
        'missing Payment Key expiration',
    );
    $mismatchedCardIntegrationRequest = $makeSyntheticPaymentKeyRequest(
        (int)$cardIntegrationId + 1,
        'synthetic-token',
    );
    $expectClassifierFailureCase(
        $cardPaymentKeyConfigDTO,
        $paymentKeyUrl,
        $mismatchedCardIntegrationRequest,
        'Payment Key integration mismatch',
    );
    $expectClassifierFailureCase($kioskConfigDTO, $paymentUrl, [
        'source' => ['identifier' => 'not-aggregator', 'subtype' => 'AGGREGATOR'],
        'payment_token' => 'synthetic-token',
        'auth_token' => 'synthetic-auth-token',
    ], 'malformed Kiosk AGGREGATOR source');
    $walletUnexpectedInitialAuth = $walletInitialRequest;
    $walletUnexpectedInitialAuth['auth_token'] = 'unexpected-initial-auth-token';
    $expectClassifierFailureCase(
        $walletRecoveryConfigDTO,
        $paymentUrl,
        $walletUnexpectedInitialAuth,
        'Wallet initial attempt with Auth token',
    );
    $expectClassifierFailureCase($authConfig, 'https://accept.paymob.com/api/unsupported', [
        'api_key' => 'synthetic-api-key',
    ], 'unknown Paymob path');
    $expectClassifierFailureCase($authConfig, 'https://invalid.example/api/auth/tokens', [
        'api_key' => 'synthetic-api-key',
    ], 'non-Paymob URL');
    $expectClassifierFailureCase($authConfig, 'http://accept.paymob.com/api/auth/tokens', [
        'api_key' => 'synthetic-api-key',
    ], 'non-HTTPS URL');
    $expectClassifierFailureCase($authConfig, $authUrl, 'not-an-object', 'unexpected top-level request type');

    $walletRetryWithAuth = $walletInitialRequest;
    $walletRetryWithAuth['auth_token'] = 'unexpected-wallet-auth-token';
    $expectWorkflowFailure($walletRecoveryConfigDTO, [
        $syntheticExchanges[0], $syntheticExchanges[1], $syntheticExchanges[2], $syntheticExchanges[3],
        [$paymentUrl, $walletRetryWithAuth, []],
    ], 'Wallet Pay retry carrying an Auth token');
    $classifierFailureCases++;

    $orderNormalRequest = $makeSyntheticOrderRequest('workflow-auth-token', 'workflow-order-reference');
    $orderReplayRequest = $makeSyntheticOrderRequest('workflow-refresh-token', 'workflow-order-reference');
    $orderAuthExchange = [$authUrl, ['api_key' => 'workflow-api-key'], []];
    $orderExchange = [$orderUrl, $orderNormalRequest, []];
    $refreshAuthExchange = [$authUrl, ['api_key' => 'workflow-api-key'], [], 200];
    $validOrderRecovery = $classifyWithCapturingClient($orderOnlyConfigDTO, [
        $orderAuthExchange,
        [...$orderExchange, 401],
        $refreshAuthExchange,
        [$orderUrl, $orderReplayRequest, []],
    ]);
    verify($validOrderRecovery === ['auth', 'order', 'auth', 'order-retry'], 'Exact 401 Order recovery was not authorized.');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange, $orderExchange, [$orderUrl, $orderReplayRequest, []],
    ], 'Order retry without 401');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange, [...$orderExchange, 400], $refreshAuthExchange,
    ], 'Order non-401 recovery');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange, [...$orderExchange, 401], $refreshAuthExchange,
        [$orderUrl, $orderReplayRequest, [], 401], $refreshAuthExchange,
    ], 'second Order replay');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange, [...$orderExchange, 401],
        [$paymentKeyUrl, $cardPaymentKeyExchange[1], []],
    ], 'different operation before required Auth refresh');
    $expectWorkflowFailure($cardPaymentKeyConfigDTO, [
        $classifierAuthExchange,
        $classifierOrderExchange,
        [...$cardPaymentKeyExchange, 401],
        $refreshAuthExchange,
        $classifierOrderExchange,
    ], 'different operation after recovery Auth');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange,
        [...$orderExchange, null, false],
        $refreshAuthExchange,
    ], 'transport failure recovery');
    $expectWorkflowFailure($orderOnlyConfigDTO, [
        $orderAuthExchange, $orderExchange, $orderAuthExchange,
    ], 'unprompted extra Auth');
    $expectWorkflowFailure($authConfig, [
        $orderAuthExchange, $orderAuthExchange,
    ], 'recursive Auth');
    $expectWorkflowFailure($cardPaymentKeyConfigDTO, [
        $classifierAuthExchange,
        [$paymentKeyUrl, $cardPaymentKeyExchange[1], []],
    ], 'Payment Key before Order');
    $expectWorkflowFailure($cardPaymentKeyConfigDTO, [
        $classifierAuthExchange,
        $classifierOrderExchange,
        $cardPaymentKeyExchange,
        $cardPaymentKeyExchange,
    ], 'Payment Key retry without 401 recovery');
    $expectWorkflowFailure($kioskConfigDTO, [
        $kioskAuthExchange,
        $kioskOrderExchange,
        $kioskPaymentKeyExchange,
        $kioskPaymentExchange,
        $kioskPaymentExchange,
    ], 'Kiosk Pay retry without 401 recovery');

    $transactionAuthExchange = [$authUrl, ['api_key' => 'transaction-workflow-api-key'], []];
    $transactionInquiryUrl = 'https://accept.paymob.com/api/acceptance/transactions/42';
    $transactionInquiryExchange = [$transactionInquiryUrl, null, []];
    $transactionRecoveryStages = $classifyWithCapturingClient($transactionConfig, [
        $transactionAuthExchange,
        [...$transactionInquiryExchange, 401],
        [$authUrl, ['api_key' => 'transaction-workflow-api-key'], [], 200],
        $transactionInquiryExchange,
    ]);
    verify(
        $transactionRecoveryStages === ['auth', 'transaction-inquiry', 'auth', 'transaction-inquiry-retry'],
        'Transaction Inquiry exact 401 recovery was not authorized.',
    );
    $expectWorkflowFailure($transactionConfig, [
        $transactionAuthExchange, $transactionInquiryExchange, $transactionInquiryExchange,
    ], 'Transaction Inquiry retry without 401');
    verify($classifierFailureCases >= 11, 'Malformed and out-of-order classifier regressions were not exercised.');
    echo "PASS fail-closed workflow, exact-401 recovery, replay limit, out-of-order, and extra-Auth matrix\n";

    $kioskRecoveryDirectory = $makeKioskRecoveryDirectory('four-stage');
    $writeRecoveryRun($kioskRecoveryDirectory, $kioskFourExchangeRun);
    $kioskSourceSnapshot = $snapshotSourceRun($kioskRecoveryDirectory);
    $kioskSourceIdentity = basename($kioskRecoveryDirectory);
    $kioskRecoveryResult = RetainedRunRecovery::recover(
        $syntheticKioskConfigDirectory,
        $kioskRecoveryDirectory,
        'kiosk',
    );
    if (is_string($kioskRecoveryResult['artifact']['path'] ?? null)) {
        $kioskRecoveryArtifactPaths[] = $kioskRecoveryResult['artifact']['path'];
    }
    if (is_string($kioskRecoveryResult['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $kioskRecoveryResult['failure_artifact']['path'];
    }
    verify(
        $kioskRecoveryResult['result'] === 'PASS'
        && $kioskRecoveryResult['scenario'] === 'kiosk'
        && $kioskRecoveryResult['exchange_count'] === 4
        && $kioskRecoveryResult['recovered_stages'] === $kioskLiveStages
        && $kioskRecoveryResult['recovered_stages'] === [
            'auth', 'order', 'payment-key-kiosk', 'kiosk-payment',
        ]
        && $kioskRecoveryResult['source_raw_retained'] === true,
        'Synthetic four-exchange Kiosk recovery failed or derived incorrect scenario/stages.',
    );
    $kioskRecoveryArtifactPath = $kioskRecoveryResult['artifact']['path'];
    $kioskRecoverySnapshot = $validateRecoveryArtifact($kioskRecoveryResult, 'kiosk', $kioskSourceIdentity);
    $kioskRecoveryReport = $kioskRecoveryResult['report'];
    verify(
        $kioskRecoveryReport['source']['scenario'] === 'kiosk'
        && $kioskRecoveryReport['source']['exchange_count'] === 4
        && $kioskRecoveryReport['source']['source_raw_retained'] === true,
        'Kiosk recovery report did not preserve selected scenario or source retention.',
    );
    verify(
        is_string($kioskRecoveryResult['artifact']['path'] ?? null),
        'Successful Kiosk recovery omitted its artifact path.',
    );
    foreach ($kioskRecoveryReport['exchanges'] as $exchange) {
        verify(
            $exchange['metadata_source'] === 'unavailable_from_retained_raw'
            && $exchange['method'] === null
            && $exchange['http_status'] === null
            && $exchange['transport_ok'] === null
            && $exchange['curl_errno'] === null
            && $exchange['curl_error'] === null
            && $exchange['request_header_names'] === null
            && $exchange['response_header_names'] === null,
            'Kiosk recovery invented runtime-only metadata unavailable from retained raw files.',
        );
    }

    $kioskRecoveredResponse = $kioskRecoveryReport['exchanges'][3]['sanitized_response_fixture_candidate'];
    verify(
        $kioskRecoveredResponse['data']['klass'] === 'CAGGPayment'
        && $kioskRecoveredResponse['data']['message'] === 'Pending Payment'
        && $kioskRecoveredResponse['data']['txn_response_code'] === '05'
        && $kioskRecoveredResponse['order']['payment_status'] === 'UNPAID'
        && $kioskRecoveredResponse['amount_cents'] === 15000
        && $kioskRecoveredResponse['currency'] === 'EGP'
        && $kioskRecoveredResponse['pending'] === true
        && $kioskRecoveredResponse['success'] === false,
        'Kiosk recovery artifact changed accepted provider semantics.',
    );
    $kioskRecoveredBillingData = $kioskRecoveryReport['exchanges'][2]['sanitized_request']['billing_data'];
    verify(
        $kioskRecoveredBillingData['first_name'] === '<SANITIZED_NAME>'
        && $kioskRecoveredBillingData['last_name'] === '<SANITIZED_NAME>'
        && $kioskRecoveredBillingData['building'] !== '77'
        && $kioskRecoveredBillingData['floor'] !== '2'
        && $kioskRecoveredBillingData['apartment'] !== '4'
        && $kioskRecoveredBillingData['postal_code'] === '<SANITIZED_ADDRESS>'
        && $kioskRecoveredBillingData['street'] === '<SANITIZED_ADDRESS>'
        && $kioskRecoveredBillingData['city'] === '<SANITIZED_ADDRESS>'
        && $kioskRecoveredBillingData['state'] === '<SANITIZED_ADDRESS>'
        && $kioskRecoveredBillingData['email'] === 'customer@example.test'
        && $kioskRecoveredBillingData['phone_number'] === '+20000000000',
        'Kiosk recovery did not redact synthetic billing name, address, email, or phone PII.',
    );
    $billReferenceMappingPath = '$.exchanges[4].response.data.bill_reference';
    $billReferenceMapping = null;
    foreach ($kioskRecoveryReport['id_mappings'] as $mapping) {
        if (in_array($billReferenceMappingPath, $mapping['paths'], true)) {
            $billReferenceMapping = $mapping;
            break;
        }
    }
    verify(
        is_array($billReferenceMapping)
        && $billReferenceMapping['fake_type'] === 'int'
        && is_int($billReferenceMapping['fake_value'])
        && $billReferenceMapping['fake_value'] !== $kioskBillReference
        && $kioskRecoveredResponse['data']['bill_reference'] === $billReferenceMapping['fake_value'],
        'Kiosk bill reference did not use the shared type-preserving ID mapping summary.',
    );
    verify(
        $kioskRecoveryReport['leak_guard_result'] === 'PASS'
        && $kioskRecoveryReport['referential_consistency_result'] === 'PASS',
        'Kiosk recovery omitted leak-guard or referential-consistency evidence.',
    );
    $kioskOrderRequest = $kioskRecoveryReport['exchanges'][1]['sanitized_request'];
    $kioskOrderResponse = $kioskRecoveryReport['exchanges'][1]['sanitized_response_fixture_candidate'];
    verify(
        $kioskOrderRequest['merchant_order_id'] === $kioskOrderResponse['merchant_order_id']
        && $kioskOrderResponse['merchant_order_id'] === $kioskRecoveredResponse['order']['merchant_order_id'],
        'Kiosk merchant/order private references lost their shared sanitizer mapping.',
    );
    verify(
        $kioskRecoveryReport['exchanges'][0]['sanitized_response_fixture_candidate']['profile']['phones'] === [
            '+20000000000', '+20000000000', null,
        ]
        && $kioskRecoveryReport['exchanges'][0]['sanitized_response_fixture_candidate']['profile']['email']
            === 'customer@example.test'
        && $kioskRecoveryReport['exchanges'][0]['sanitized_response_fixture_candidate']['profile']['sms_sender_name']
            === '<SANITIZED_NAME>',
        'Kiosk recovery did not preserve and sanitize auth profile phones[] and sms_sender_name.',
    );
    foreach ([
        $kioskApiKey,
        $kioskHmacSecret,
        $kioskAuthToken,
        $kioskRefreshedAuthToken,
        $kioskPaymentToken,
        $kioskMerchantOrderReference,
        'kiosk-private-person@example.org',
        '+201234567890',
        'Synthetic Kiosk Customer',
        'Private Example',
        'Synthetic Kiosk Private Street',
        'Synthetic Kiosk City',
        'Synthetic Kiosk Private State',
        'synthetic-kiosk-auth-phone-zero',
        'Synthetic Kiosk Private Sender',
    ] as $kioskSensitiveLiteral) {
        verify(
            !str_contains($kioskRecoverySnapshot['contents'], $kioskSensitiveLiteral),
            'Sanitized Kiosk artifact contains a synthetic secret, private reference, or PII literal.',
        );
    }
    verify(
        $kioskSourceSnapshot === $snapshotSourceRun($kioskRecoveryDirectory),
        'Four-stage Kiosk recovery modified its synthetic retained source run.',
    );
    echo "PASS synthetic Kiosk four-stage recovery with safe semantics, references, PII, and metadata\n";

    $kioskRecoveryResultB = RetainedRunRecovery::recover(
        $syntheticKioskConfigDirectory,
        $kioskRecoveryDirectory,
        'kiosk',
    );
    if (is_string($kioskRecoveryResultB['artifact']['path'] ?? null)) {
        $kioskRecoveryArtifactPaths[] = $kioskRecoveryResultB['artifact']['path'];
    }
    if (is_string($kioskRecoveryResultB['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $kioskRecoveryResultB['failure_artifact']['path'];
    }
    verify(
        $kioskRecoveryResultB['result'] === 'PASS'
        && $kioskRecoveryResultB['artifact']['path'] !== $kioskRecoveryArtifactPath,
        'Repeated Kiosk recovery reused an existing artifact name.',
    );
    $kioskRecoveryArtifactPathB = $kioskRecoveryResultB['artifact']['path'];
    $kioskRecoverySnapshotB = $validateRecoveryArtifact($kioskRecoveryResultB, 'kiosk', $kioskSourceIdentity);
    verify(
        $kioskRecoverySnapshot === $snapshotFile($kioskRecoveryArtifactPath)
        && $kioskRecoverySnapshotB === $snapshotFile($kioskRecoveryArtifactPathB),
        'Repeated Kiosk recovery changed an earlier artifact or failed to persist a unique artifact.',
    );
    verify(
        $kioskSourceSnapshot === $snapshotSourceRun($kioskRecoveryDirectory),
        'Repeated Kiosk recovery modified its synthetic retained source run.',
    );
    echo "PASS Kiosk unique non-overwriting recovery artifacts\n";

    $kioskRetryAuthExchange = [
        'https://accept.paymob.com/api/auth/tokens',
        ['api_key' => $kioskApiKey],
        [
            'token' => $kioskRefreshedAuthToken,
            'profile' => [
                'id' => 456789,
                'email' => 'kiosk-private-person@example.org',
                'phones' => ['synthetic-kiosk-auth-phone-zero', 'synthetic-kiosk-auth-phone-one', null],
            ],
            'issued_at' => '2026-10-03T10:04:00Z',
        ],
    ];
    $kioskRetryExchanges = [
        $kioskAuthExchange,
        $kioskOrderExchange,
        $kioskPaymentKeyExchange,
        [...$kioskPaymentExchange, 401],
        $kioskRetryAuthExchange,
        [
            'https://accept.paymob.com/api/acceptance/payments/pay',
            $composeSyntheticAuthenticatedRequest($kioskRequestDTO->toArray(), $kioskRefreshedAuthToken),
            $syntheticKioskResponse,
        ],
    ];
    $kioskRetryLiveStages = $classifyWithCapturingClient($kioskConfigDTO, $kioskRetryExchanges);
    verify(
        $kioskRetryLiveStages === [
            'auth', 'order', 'payment-key-kiosk', 'kiosk-payment', 'auth', 'kiosk-payment-retry',
        ],
        'Shared live classifier did not derive the synthetic Kiosk Pay retry sequence.',
    );
    $kioskRetryDirectory = $makeKioskRecoveryDirectory('retry');
    $writeRecoveryRun($kioskRetryDirectory, $kioskRetryExchanges);
    $kioskRetrySourceSnapshot = $snapshotSourceRun($kioskRetryDirectory);
    $kioskRetryResult = RetainedRunRecovery::recover(
        $syntheticKioskConfigDirectory,
        $kioskRetryDirectory,
        'kiosk',
    );
    if (is_string($kioskRetryResult['artifact']['path'] ?? null)) {
        $kioskRecoveryArtifactPaths[] = $kioskRetryResult['artifact']['path'];
    }
    if (is_string($kioskRetryResult['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $kioskRetryResult['failure_artifact']['path'];
    }
    verify(
        $kioskRetryResult['result'] === 'FAIL'
        && $kioskRetryResult['recovery_proof'] === 'unavailable_without_http_status'
        && !in_array('kiosk-payment-retry', $kioskRetryResult['recovered_stages'], true)
        && $kioskRetrySourceSnapshot === $snapshotSourceRun($kioskRetryDirectory),
        'Kiosk retained recovery inferred an unproven 401 transition or modified raw evidence.',
    );
    echo "PASS retained Kiosk retry-looking sequence fails closed without HTTP status proof\n";

    $kioskMismatchedPaymentKeyRequest = $kioskPaymentKeyExchange[1];
    $kioskMismatchedPaymentKeyRequest['integration_id'] = $kioskIntegrationId + 1;
    $kioskMismatchedIntegrationDirectory = $makeKioskRecoveryDirectory('mismatched-integration');
    $writeRecoveryRun($kioskMismatchedIntegrationDirectory, [
        $kioskAuthExchange,
        $kioskOrderExchange,
        [
            $kioskPaymentKeyExchange[0],
            $kioskMismatchedPaymentKeyRequest,
            $kioskPaymentKeyExchange[2],
        ],
    ]);
    $kioskMismatchedResult = RetainedRunRecovery::recover(
        $syntheticKioskConfigDirectory,
        $kioskMismatchedIntegrationDirectory,
        'kiosk',
    );
    if (is_string($kioskMismatchedResult['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $kioskMismatchedResult['failure_artifact']['path'];
    }
    verify(
        $kioskMismatchedResult['result'] === 'FAIL'
        && $kioskMismatchedResult['scenario'] === 'kiosk'
        && is_array($kioskMismatchedResult['failure_artifact'] ?? null)
        && is_string($kioskMismatchedResult['failure_artifact']['path'] ?? null),
        'Kiosk recovery accepted a Payment Key request for a different integration.',
    );
    $kioskMismatchFailureBytes = file_get_contents($kioskMismatchedResult['failure_artifact']['path']);
    verify(is_string($kioskMismatchFailureBytes), 'Kiosk mismatch failure artifact could not be read back.');
    $kioskMismatchFailure = json_decode(
        $kioskMismatchFailureBytes,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    verify(
        is_array($kioskMismatchFailure) && $kioskMismatchFailure['scenario'] === 'kiosk',
        'Kiosk durable failure artifact omitted the selected scenario.',
    );

    $malformedKioskPayRequest = $kioskRequestDTO->toArray();
    $malformedKioskDirectory = $makeKioskRecoveryDirectory('malformed-pay');
    $writeRecoveryRun($malformedKioskDirectory, [
        $kioskAuthExchange,
        $kioskOrderExchange,
        $kioskPaymentKeyExchange,
        [
            $kioskPaymentExchange[0],
            $malformedKioskPayRequest,
            $syntheticKioskResponse,
        ],
    ]);
    $malformedKioskResult = RetainedRunRecovery::recover(
        $syntheticKioskConfigDirectory,
        $malformedKioskDirectory,
        'kiosk',
    );
    if (is_string($malformedKioskResult['failure_artifact']['path'] ?? null)) {
        $unexpectedRecoveryFailureArtifactPaths[] = $malformedKioskResult['failure_artifact']['path'];
    }
    verify(
        $malformedKioskResult['result'] === 'FAIL'
        && $malformedKioskResult['scenario'] === 'kiosk',
        'Kiosk recovery guessed the stage of a structurally incomplete pay request.',
    );
    echo "PASS Kiosk integration matching, structural fail-closed behavior, and scenario-aware failure artifacts\n";

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
    verify(
        $recoveryFailure['result'] === 'FAIL' && $recoveryFailure['scenario'] === 'wallet',
        'Synthetic post-prime Wallet failure unexpectedly recovered or lost its selected scenario.',
    );
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
        is_array($recoveryFailureReport)
        && $recoveryFailureReport['scenario'] === 'wallet'
        && !$recoveryFailureSanitizer->containsSensitiveValues($recoveryFailureReport),
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

    $selfCheckArtifactPaths = [
        $artifactPath,
        $failureArtifactPath,
        $recoveryArtifactPath,
        $recoveryArtifactPathB,
        $recoveryFailureArtifactPath,
    ];
    $selfCheckArtifactPaths = array_merge(
        $selfCheckArtifactPaths,
        $kioskRecoveryArtifactPaths,
        $cardRecoveryArtifactPaths,
        $transactionRecoveryArtifactPaths,
    );
    if ($legacyRecoverySentinelOwned && $legacyRecoveryArtifactPath !== null) {
        $selfCheckArtifactPaths[] = $legacyRecoveryArtifactPath;
    }
    foreach (array_merge($selfCheckArtifactPaths, $unexpectedRecoveryFailureArtifactPaths) as $selfCheckArtifactPath) {
        if ($selfCheckArtifactPath !== null && is_link($selfCheckArtifactPath)) {
            $failure ??= new RuntimeException('Self-check sanitized artifact became a symlink and was not removed.');
        } elseif ($selfCheckArtifactPath !== null && is_file($selfCheckArtifactPath) && !unlink($selfCheckArtifactPath)) {
            $failure ??= new RuntimeException('Self-check sanitized artifact cleanup failed.');
        }
        if ($selfCheckArtifactPath !== null && (file_exists($selfCheckArtifactPath) || is_link($selfCheckArtifactPath))) {
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
    foreach ($syntheticKioskRecoveryDirectories as $syntheticKioskRecoveryDirectory) {
        if (!is_dir($syntheticKioskRecoveryDirectory)) {
            continue;
        }
        foreach (scandir($syntheticKioskRecoveryDirectory) ?: [] as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $path = $syntheticKioskRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !is_link($path) && !unlink($path)) {
                $failure ??= new RuntimeException('Synthetic Kiosk retained-run cleanup failed.');
            }
        }
        if (is_dir($syntheticKioskRecoveryDirectory) && !rmdir($syntheticKioskRecoveryDirectory)) {
            $failure ??= new RuntimeException('Synthetic Kiosk retained-run directory cleanup failed.');
        }
    }
    foreach ($syntheticCardRecoveryDirectories as $syntheticCardRecoveryDirectory) {
        if (!is_dir($syntheticCardRecoveryDirectory)) {
            continue;
        }
        foreach (scandir($syntheticCardRecoveryDirectory) ?: [] as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $path = $syntheticCardRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !is_link($path) && !unlink($path)) {
                $failure ??= new RuntimeException('Synthetic Card retained-run cleanup failed.');
            }
        }
        if (is_dir($syntheticCardRecoveryDirectory) && !rmdir($syntheticCardRecoveryDirectory)) {
            $failure ??= new RuntimeException('Synthetic Card retained-run directory cleanup failed.');
        }
    }
    foreach ($syntheticTransactionRecoveryDirectories as $syntheticTransactionRecoveryDirectory) {
        if (!is_dir($syntheticTransactionRecoveryDirectory)) {
            continue;
        }
        foreach (scandir($syntheticTransactionRecoveryDirectory) ?: [] as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $path = $syntheticTransactionRecoveryDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !is_link($path) && !unlink($path)) {
                $failure ??= new RuntimeException('Synthetic Transaction Inquiry retained-run cleanup failed.');
            }
        }
        if (is_dir($syntheticTransactionRecoveryDirectory) && !rmdir($syntheticTransactionRecoveryDirectory)) {
            $failure ??= new RuntimeException('Synthetic Transaction Inquiry retained-run directory cleanup failed.');
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
    if ($syntheticKioskConfigDirectory !== null
        && is_file($syntheticKioskConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
        if (!unlink($syntheticKioskConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
            $failure ??= new RuntimeException('Synthetic Kiosk configuration cleanup failed.');
        }
    }
    if ($syntheticKioskConfigDirectory !== null && is_dir($syntheticKioskConfigDirectory)
        && !rmdir($syntheticKioskConfigDirectory)) {
        $failure ??= new RuntimeException('Synthetic Kiosk configuration directory cleanup failed.');
    }
    if ($syntheticPaymentKeyConfigDirectory !== null
        && is_file($syntheticPaymentKeyConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
        if (!unlink($syntheticPaymentKeyConfigDirectory . DIRECTORY_SEPARATOR . '.env')) {
            $failure ??= new RuntimeException('Synthetic Payment Key configuration cleanup failed.');
        }
    }
    if ($syntheticPaymentKeyConfigDirectory !== null && is_dir($syntheticPaymentKeyConfigDirectory)
        && !rmdir($syntheticPaymentKeyConfigDirectory)) {
        $failure ??= new RuntimeException('Synthetic Payment Key configuration directory cleanup failed.');
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'SELF-CHECK: FAIL (' . get_class($failure) . '): ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "SELF-CHECK: PASS\n";
exit(0);
