<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Transaction\Service\TransactionService;
use Maatify\Paymob\Payment\Service\WalletPaymentService;
use RuntimeException;
use Throwable;

/** Runs one manual provider flow through package services or an explicit verification-only probe. */
final class VerificationContext
{
    private string $stage = 'preflight';

    private ?bool $paymentKeyOrderIdMatchesCreatedOrder = null;

    private ?bool $transactionIdMatchesRequested = null;

    private function __construct(
        private readonly VerificationConfig $config,
        private readonly CaptureSession $captureSession,
        private readonly CapturingApiClient $apiClient,
        private readonly InMemoryTokenRepository $tokenRepository,
    ) {}

    /** Run one configured flow, print a safe report, and retain raw evidence on failure. */
    public static function run(string $scenario, ?string $paymentMethod, string $repositoryRoot): int
    {
        $stage = 'preflight';
        $context = null;
        $captureSession = null;
        $config = null;
        try {
            $config = VerificationConfig::load($repositoryRoot, $scenario, $paymentMethod);
            $captureSession = new CaptureSession($config->repositoryRoot);
            $attemptStageClassifier = ProviderAttemptStageClassifier::fromConfig($config);
            $apiClient = new CapturingApiClient($config->baseUrl, $captureSession, $attemptStageClassifier);
            $context = new self($config, $captureSession, $apiClient, new InMemoryTokenRepository());
            $report = $context->execute();
            $context->stage = 'sanitize-report';
            $sanitizer = new SemanticSanitizer($config->configuredSecrets(), $config->walletTestMsisdn);
            $report['capture'] = $captureSession->buildSanitizedReport($sanitizer);
            $context->assertSafeOutput($report, $sanitizer);

            $context->stage = 'artifact-persistence';
            $artifact = $captureSession->persistSanitizedArtifact($report);
            $context->stage = 'artifact-verification';
            try {
                $persistedReport = $captureSession->readSanitizedArtifact();
                $context->assertSafeOutput($persistedReport, $sanitizer);
            } catch (Throwable $exception) {
                $captureSession->discardSanitizedArtifact();
                throw $exception;
            }
            $captureSession->markSanitizedArtifactSafe();

            $summary = [
                'result' => $report['result'],
                'scenario' => $report['scenario'],
                'sanitized_artifact_path' => $artifact['path'],
                'sanitized_artifact_bytes' => $artifact['bytes'],
                'sanitized_artifact_sha256' => $artifact['sha256'],
                'raw_evidence_cleaned' => true,
                'exchange_count' => count($report['capture']['exchanges']),
                'stages' => array_values(array_map(
                    static fn(array $exchange): string => $exchange['stage'],
                    $report['capture']['exchanges'],
                )),
                'http_statuses' => array_values(array_map(
                    static fn(array $exchange): int => $exchange['http_status'],
                    $report['capture']['exchanges'],
                )),
                'service_results' => $report['service_results'],
            ];
            $context->assertSafeOutput($summary, $sanitizer);
            $successOutput = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $context->stage = 'raw-evidence-cleanup';
            if (!$captureSession->cleanupRawEvidence()) {
                throw new \RuntimeException('Verified sanitized artifact exists, but private raw evidence cleanup failed.');
            }

            echo $successOutput . PHP_EOL;
            return 0;
        } catch (Throwable $exception) {
            if ($context !== null) {
                $stage = $context->stage;
            }

            $safeStage = self::safeStage($stage);
            $exceptionClass = get_class($exception);
            if ($captureSession instanceof CaptureSession) {
                try {
                    $secrets = $config instanceof VerificationConfig ? $config->configuredSecrets() : [];
                    $diagnostic = $captureSession->failureDiagnostic($safeStage, $exception, $secrets);
                    if ($context?->paymentKeyOrderIdMatchesCreatedOrder === false) {
                        $diagnostic['service_results']['payment_key']['order_id_matches_created_order'] = false;
                        $diagnostic['failure_classification'] = 'PACKAGE';
                    }
                    if ($context?->transactionIdMatchesRequested === false) {
                        $diagnostic['service_results']['transaction_inquiry']['transaction_id_matches_requested'] = false;
                        $diagnostic['failure_classification'] = 'PACKAGE';
                    }
                    $sanitizer = new SemanticSanitizer($secrets);
                    if ($sanitizer->containsSensitiveValues($diagnostic)) {
                        throw new \RuntimeException('Failure diagnostic did not pass the leak guard.');
                    }

                    $fileName = basename($captureSession->rawDirectory()) . '-failure-diagnostic.json';
                    $artifact = CaptureSession::persistJsonArtifact($diagnostic, $fileName);
                    $readBack = file_get_contents($artifact['path']);
                    if (!is_string($readBack) || strlen($readBack) !== $artifact['bytes']
                        || !hash_equals($artifact['sha256'], hash('sha256', $readBack))) {
                        throw new \RuntimeException('Failure diagnostic read-back verification failed.');
                    }
                    $persistedDiagnostic = json_decode($readBack, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($persistedDiagnostic) || $sanitizer->containsSensitiveValues($persistedDiagnostic)) {
                        throw new \RuntimeException('Persisted failure diagnostic did not pass verification.');
                    }

                    $summary = [
                        'result' => 'FAIL',
                        'failing_stage' => $safeStage,
                        'exception_class' => $exceptionClass,
                        'exception_message' => $diagnostic['exception_message'],
                        'failure_artifact_path' => $artifact['path'],
                        'failure_artifact_bytes' => $artifact['bytes'],
                        'failure_artifact_sha256' => $artifact['sha256'],
                        'raw_evidence_retained' => true,
                        'raw_storage_directory' => $captureSession->rawDirectory(),
                        'captured_attempt_count' => count($diagnostic['captured_attempts']),
                    ];
                    if ($context?->paymentKeyOrderIdMatchesCreatedOrder === false) {
                        $summary['service_results']['payment_key']['order_id_matches_created_order'] = false;
                        $summary['failure_classification'] = 'PACKAGE';
                    }
                    if ($context?->transactionIdMatchesRequested === false) {
                        $summary['service_results']['transaction_inquiry']['transaction_id_matches_requested'] = false;
                        $summary['failure_classification'] = 'PACKAGE';
                    }
                    if ($sanitizer->containsSensitiveValues($summary)) {
                        throw new \RuntimeException('Failure handoff summary did not pass the leak guard.');
                    }
                    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
                    return 1;
                } catch (Throwable) {
                    self::emitPersistenceFailure($safeStage, $exceptionClass, $captureSession->rawDirectory());
                    return 1;
                }
            }

            self::emitPersistenceFailure($safeStage, $exceptionClass, null, false);
            return 1;
        }
    }

    /** Execute the requested chain and return summaries that contain no tokens or provider IDs. */
    private function execute(): array
    {
        if ($this->config->scenario === 'intention') {
            $reference = 'verification-' . bin2hex(random_bytes(12));
            $request = [
                'amount' => 15000,
                'currency' => 'EGP',
                'payment_methods' => [$this->config->cardIntegrationId],
                'items' => [[
                    'name' => 'Synthetic verification item',
                    'amount' => 15000,
                    'description' => 'Synthetic provider verification item',
                    'quantity' => 1,
                ]],
                'billing_data' => [
                    'apartment' => '1', 'first_name' => 'Verification', 'last_name' => 'Customer',
                    'street' => 'Synthetic Street', 'building' => '1',
                    'phone_number' => '+201000000000',
                    'city' => 'Cairo', 'country' => 'EG', 'email' => 'verification@example.test',
                    'floor' => '1', 'state' => 'Cairo',
                ],
                'special_reference' => $reference,
                'expiration' => 3600,
                'notification_url' => $this->config->notificationUrl,
                'redirection_url' => $this->config->redirectionUrl,
            ];
            $this->setStage('create-intention');
            $response = $this->apiClient->post('/v1/intention/', $request, [
                'Authorization' => 'Token ' . $this->config->secretKey,
                'Content-Type' => 'application/json',
            ]);
            self::assertIntentionSuccessResponse(
                $this->apiClient->lastHttpStatus(),
                $response,
                (int)$this->config->cardIntegrationId,
                $reference,
            );
            return [
                'result' => 'PASS',
                'scenario' => 'intention',
                'service_results' => [
                    'create_intention' => [
                        'http_status' => 201,
                        'response_contract_valid' => true,
                        'special_reference_matches' => $response['special_reference'] === $reference,
                        'client_secret' => '[withheld]',
                    ],
                ],
            ];
        }

        $configDTO = $this->config->packageConfig();
        $authService = new AuthService($this->apiClient, $configDTO, $this->tokenRepository, new SystemClock(new \DateTimeZone('UTC')));
        $results = [];

        $this->setStage('auth');
        $authResponse = $authService->getToken(forceRefresh: true);
        $results['auth'] = [
            'service' => AuthService::class,
            'dto' => $authResponse::class,
            'produced' => true,
            'token_value' => '[withheld]',
            'token_type' => get_debug_type($authResponse->token),
            'profile_id_type' => get_debug_type($authResponse->profileId),
            'issued_at_type' => get_debug_type($authResponse->issuedAt),
            'expires_at_type' => get_debug_type($authResponse->expiresAt),
            'token_ttl_seconds' => 3600,
        ];

        if ($this->config->scenario === 'auth') {
            return ['result' => 'PASS', 'scenario' => 'auth', 'service_results' => $results];
        }

        if ($this->config->scenario === 'transaction-inquiry') {
            $requestedId = $this->config->testTransactionId;
            if ($requestedId === null) {
                throw new \LogicException('The Transaction Inquiry input is unavailable.');
            }
            $this->setStage('transaction-inquiry');
            $transactionResponse = (new TransactionService($this->apiClient, $authService))
                ->getTransaction($requestedId);
            $this->assertTransactionIdMapping($requestedId, $transactionResponse->id);
            $results['transaction_inquiry'] = [
                'service' => TransactionService::class,
                'dto' => $transactionResponse::class,
                'produced' => true,
                'transaction_id_type' => get_debug_type($transactionResponse->id),
                'order_id_type' => get_debug_type($transactionResponse->orderId),
                'amount_cents' => $transactionResponse->amountCents,
                'currency' => $transactionResponse->currency->value,
                'success' => $transactionResponse->success,
                'pending' => $transactionResponse->pending,
                'payment_status' => $transactionResponse->paymentStatus,
                'transaction_id_matches_requested' => true,
            ];

            return ['result' => 'PASS', 'scenario' => 'transaction-inquiry', 'service_results' => $results];
        }

        $orderService = new OrderService($this->apiClient, $authService);
        $paymentKeyService = new PaymentKeyService($this->apiClient, $authService);
        $kioskService = new KioskPaymentService($this->apiClient, $authService);
        $walletService = new WalletPaymentService($this->apiClient);

        $this->setStage('order');
        $orderResponse = $orderService->createOrder($this->syntheticOrderRequest());
        $results['order'] = [
            'service' => OrderService::class,
            'dto' => $orderResponse::class,
            'produced' => true,
            'id_type' => get_debug_type($orderResponse->id),
            'created_at_type' => get_debug_type($orderResponse->createdAt),
            'currency' => $orderResponse->currency->value,
            'amount_cents' => $orderResponse->amountCents,
            'merchant_order_id_present' => $orderResponse->merchantOrderId !== null,
        ];

        if ($this->config->scenario === 'order') {
            return ['result' => 'PASS', 'scenario' => 'order', 'service_results' => $results];
        }

        $method = $this->selectedPaymentMethod();
        $integrationId = $this->integrationId($method);
        $this->setStage('payment-key-' . $method);
        $paymentKeyResponse = $paymentKeyService->generate(new GeneratePaymentKeyCommand(
            orderId: $orderResponse->id,
            integrationId: $integrationId,
            amountCents: 15000,
            currency: CurrencyEnum::EGP,
            billingData: $this->syntheticBillingData(),
            expirationSeconds: 180,
        ));
        $this->assertPaymentKeyOrderMapping($orderResponse->id, $paymentKeyResponse->orderId);
        $results['payment_key'] = [
            'service' => PaymentKeyService::class,
            'dto' => $paymentKeyResponse::class,
            'produced' => true,
            'token_value' => '[withheld]',
            'token_type' => get_debug_type($paymentKeyResponse->token),
            'order_id_type' => get_debug_type($paymentKeyResponse->orderId),
            'integration_id_configured' => true,
            'expiration_value_sent' => 180,
            'order_id_matches_created_order' => $this->paymentKeyOrderIdMatchesCreatedOrder,
        ];
        if ($this->config->scenario === 'payment-key') {
            return [
                'result' => 'PASS',
                'scenario' => 'payment-key',
                'selected_method' => $method,
                'service_results' => $results,
            ];
        }

        if ($this->config->scenario === 'kiosk') {
            $this->setStage('kiosk-payment');
            $kioskResponse = $kioskService->pay(new InitiateKioskPaymentCommand($paymentKeyResponse->token));
            $results['kiosk'] = [
                'service' => KioskPaymentService::class,
                'dto' => $kioskResponse::class,
                'produced' => true,
                'transaction_id_type' => get_debug_type($kioskResponse->transactionId),
                'order_id_type' => get_debug_type($kioskResponse->orderId),
                'pending' => $kioskResponse->pending,
                'success' => $kioskResponse->success,
                'payment_status' => $kioskResponse->paymentStatus,
            ];

            return ['result' => 'PASS', 'scenario' => 'kiosk', 'service_results' => $results];
        }

        $this->setStage('wallet-initiation');
        $walletResponse = $walletService->pay(new InitiateWalletPaymentCommand(
            paymentToken: $paymentKeyResponse->token,
            phoneNumber: (string)$this->config->walletTestMsisdn,
        ));
        $results['wallet'] = [
            'service' => WalletPaymentService::class,
            'dto' => $walletResponse::class,
            'produced' => true,
            'transaction_id_type' => get_debug_type($walletResponse->transactionId),
            'order_id_type' => get_debug_type($walletResponse->orderId),
            'success' => $walletResponse->success,
            'pending' => $walletResponse->pending,
            'redirect_url_present' => $walletResponse->redirectUrl !== null,
            'redirect_followed' => false,
            'otp_or_mpin_used' => false,
        ];

        return ['result' => 'PASS', 'scenario' => 'wallet', 'service_results' => $results];
    }

    /** Enforce the prepared live success contract and expose it to the offline executable self-check. */
    public static function assertIntentionSuccessResponse(
        ?int $httpStatus,
        mixed $response,
        int $integrationId,
        string $reference,
    ): void {
        if ($httpStatus !== 201
            || !ProviderAttemptStageClassifier::validateIntentionResponse($response, $integrationId, $reference)) {
            throw new RuntimeException('The Create Intention response did not satisfy its prepared success contract.');
        }
    }

    private function syntheticOrderRequest(): CreateOrderCommand
    {
        $merchantOrderId = 'provider-verify-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        return new CreateOrderCommand(
            amountCents: 15000,
            currency: CurrencyEnum::EGP,
            merchantOrderId: $merchantOrderId,
            items: [new OrderItem(
                name: 'Provider verification item',
                amountCents: 15000,
                quantity: 1,
                description: 'Synthetic provider verification order',
            )],
        );
    }

    private function syntheticBillingData(): BillingData
    {
        return new BillingData(
            firstName: 'Provider',
            lastName: 'Verification',
            email: 'provider-verification@example.test',
            phoneNumber: '+201010101010',
            country: 'NA',
            city: 'Test City',
            street: 'Synthetic Test Street',
            building: '1',
            floor: '1',
            apartment: '1',
            postalCode: '00000',
            state: 'Test State',
        );
    }

    private function selectedPaymentMethod(): string
    {
        return match ($this->config->scenario) {
            'payment-key' => (string)$this->config->paymentMethod,
            'kiosk' => 'kiosk',
            'wallet' => 'wallet',
            default => throw new \LogicException('The selected scenario does not request a payment key.'),
        };
    }

    private function integrationId(string $method): int
    {
        return match ($method) {
            'card' => (int)$this->config->cardIntegrationId,
            'kiosk' => (int)$this->config->kioskIntegrationId,
            'wallet' => (int)$this->config->walletIntegrationId,
            default => throw new \LogicException('Unknown payment method integration selection.'),
        };
    }

    private function setStage(string $stage): void
    {
        $this->stage = $stage;
    }

    /** Record the safe mapping result and stop a run with a mismatched Payment Key order ID. */
    private function assertPaymentKeyOrderMapping(int $createdOrderId, int $paymentKeyOrderId): void
    {
        $this->paymentKeyOrderIdMatchesCreatedOrder = $createdOrderId === $paymentKeyOrderId;
        if (!$this->paymentKeyOrderIdMatchesCreatedOrder) {
            $this->setStage('payment-key-order-mapping');
            throw new \RuntimeException('Payment Key response order ID does not match the created order.');
        }
    }

    /** Record the safe match result and stop on a different Transaction ID. */
    private function assertTransactionIdMapping(int $requestedId, int $responseId): void
    {
        $this->transactionIdMatchesRequested = $requestedId === $responseId;
        if (!$this->transactionIdMatchesRequested) {
            $this->setStage('transaction-inquiry-id-mapping');
            throw new \RuntimeException('Transaction response ID does not match the requested ID.');
        }
    }

    private function assertSafeOutput(array $report, SemanticSanitizer $sanitizer): void
    {
        if ($sanitizer->containsSensitiveValues($report)) {
            throw new \RuntimeException('Sanitized provider verification report contains a sensitive literal.');
        }
    }

    private static function emitPersistenceFailure(
        string $stage,
        string $exceptionClass,
        ?string $rawDirectory,
        bool $persistenceFailed = true,
    ): void {
        $report = [
            'result' => 'FAIL',
            'diagnostic' => $persistenceFailed
                ? 'PROVIDER VERIFICATION FAILURE DIAGNOSTIC COULD NOT BE PERSISTED'
                : 'PROVIDER VERIFICATION FAILED BEFORE CAPTURE SESSION CREATION',
            'failing_stage' => self::safeStage($stage),
            'exception_class' => $exceptionClass,
            'raw_storage_directory' => $rawDirectory,
        ];
        fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL);
    }

    private static function safeStage(string $stage): string
    {
        return preg_match('/^[A-Za-z0-9-]{1,80}$/', $stage) === 1 ? $stage : 'unknown';
    }
}
