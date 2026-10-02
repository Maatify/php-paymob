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
        try {
            $config = VerificationConfig::load($repositoryRoot, $scenario, $paymentMethod);
            $captureSession = new CaptureSession($config->repositoryRoot);
            $apiClient = new CapturingApiClient($config->baseUrl, $captureSession);
            $context = new self($config, $captureSession, $apiClient, new InMemoryTokenRepository());
            $report = $context->execute();
            $sanitizer = new SemanticSanitizer($config->configuredSecrets(), $config->walletTestMsisdn);
            $report['capture'] = $captureSession->buildSanitizedReport($sanitizer);
            $context->assertSafeOutput($report, $sanitizer);

            if (!$captureSession->cleanupRawEvidence()) {
                throw new \RuntimeException('Sanitized capture succeeded but private raw evidence cleanup failed.');
            }

            $report['raw_evidence'] = 'cleaned after sanitized report validation';
            self::emit($report);
            return 0;
        } catch (Throwable $exception) {
            if ($context !== null) {
                $stage = $context->stage;
            }

            if ($captureSession ?? null) {
                $diagnostic = $captureSession->failureDiagnostic(
                    $stage,
                    $exception,
                    $config->configuredSecrets() ?? [],
                );
            } else {
                $diagnostic = [
                    'result' => 'FAIL',
                    'failing_stage' => $stage,
                    'exception_class' => get_class($exception),
                    'exception_message' => 'Provider verification preflight did not produce a private capture session.',
                    'raw_evidence_retained' => false,
                    'raw_storage_directory' => null,
                    'raw_artifacts' => [],
                    'captured_attempts' => [],
                ];
            }

            self::emit($diagnostic);
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
            phoneNumber: '+20000000000',
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

    private static function emit(array $report): void
    {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
