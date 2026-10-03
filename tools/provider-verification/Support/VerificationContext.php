<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentRequestDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyRequestDTO;
use Maatify\Paymob\DTO\Payment\WalletPaymentRequestDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Service\AuthService;
use Maatify\Paymob\Service\KioskPaymentService;
use Maatify\Paymob\Service\OrderService;
use Maatify\Paymob\Service\PaymentKeyService;
use Maatify\Paymob\Service\WalletPaymentService;
use Throwable;

/** Wires one explicit manual provider flow through the package services and capture transport. */
final class VerificationContext
{
    private string $stage = 'preflight';

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
            $apiClient = new CapturingApiClient($config->baseUrl, $captureSession);
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
        $configDTO = $this->config->packageConfig();
        $authService = new AuthService($this->apiClient, $configDTO, $this->tokenRepository);
        $orderService = new OrderService($this->apiClient, $configDTO, $authService);
        $paymentKeyService = new PaymentKeyService($this->apiClient, $authService);
        $kioskService = new KioskPaymentService($this->apiClient, $authService);
        $walletService = new WalletPaymentService($this->apiClient, $authService);
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
            'expiry_source' => 'local package behavior: PAYMOB_KEYS_EXPIRY minutes; not provider evidence',
        ];

        if ($this->config->scenario === 'auth') {
            return ['result' => 'PASS', 'scenario' => 'auth', 'service_results' => $results];
        }

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
        $paymentKeyResponse = $paymentKeyService->generate(new PaymentKeyRequestDTO(
            orderId: $orderResponse->id,
            integrationId: $integrationId,
            amountCents: 15000,
            currency: CurrencyEnum::EGP,
            billingData: $this->syntheticBillingData(),
            expirationMinutes: 180,
        ));
        $results['payment_key'] = [
            'service' => PaymentKeyService::class,
            'dto' => $paymentKeyResponse::class,
            'produced' => true,
            'token_value' => '[withheld]',
            'token_type' => get_debug_type($paymentKeyResponse->token),
            'order_id_type' => get_debug_type($paymentKeyResponse->orderId),
            'integration_id_configured' => true,
            'expiration_value_sent' => 180,
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
            $kioskResponse = $kioskService->pay(new KioskPaymentRequestDTO($paymentKeyResponse->token));
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
        $walletResponse = $walletService->pay(new WalletPaymentRequestDTO(
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

    private function syntheticOrderRequest(): OrderRequestDTO
    {
        $merchantOrderId = 'provider-verify-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        return new OrderRequestDTO(
            amountCents: 15000,
            currency: CurrencyEnum::EGP,
            merchantOrderId: $merchantOrderId,
            items: new OrderItemsDTO(new OrderItemDTO(
                name: 'Provider verification item',
                amountCents: 15000,
                quantity: 1,
                description: 'Synthetic provider verification order',
            )),
        );
    }

    private function syntheticBillingData(): BillingDataDTO
    {
        return new BillingDataDTO(
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
        $this->apiClient->setStage($stage);
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
