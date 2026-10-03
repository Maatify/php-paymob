<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use RuntimeException;
use stdClass;

/** Classifies supported provider request attempts without network or filesystem access. */
final class ProviderAttemptStageClassifier
{
    private int $orderAttempts = 0;

    private int $paymentKeyAttempts = 0;

    private int $kioskPaymentAttempts = 0;

    private int $walletPaymentAttempts = 0;

    private int $transactionInquiryAttempts = 0;

    private function __construct(
        private readonly string $scenario,
        private readonly ?string $paymentMethod,
        private readonly ?int $integrationId,
        private readonly ?int $transactionId,
    ) {}

    /** Build a run-scoped classifier from configuration that has already been validated. */
    public static function fromConfig(VerificationConfig $config): self
    {
        $paymentMethod = match ($config->scenario) {
            'payment-key' => $config->paymentMethod,
            'kiosk' => 'kiosk',
            'wallet' => 'wallet',
            default => null,
        };

        if ($config->scenario === 'payment-key'
            && !in_array($paymentMethod, ['card', 'kiosk', 'wallet'], true)) {
            throw new RuntimeException('The configured Payment Key method is unavailable for attempt classification.');
        }

        $integrationId = match ($paymentMethod) {
            'card' => $config->cardIntegrationId,
            'kiosk' => $config->kioskIntegrationId,
            'wallet' => $config->walletIntegrationId,
            default => null,
        };

        if ($paymentMethod !== null && (!is_int($integrationId) || $integrationId < 1)) {
            throw new RuntimeException(
                'The configured Payment Key integration is unavailable for attempt classification.',
            );
        }

        if ($config->scenario === 'transaction-inquiry'
            && (!is_int($config->testTransactionId) || $config->testTransactionId < 1)) {
            throw new RuntimeException('The configured Transaction ID is unavailable for attempt classification.');
        }

        return new self($config->scenario, $paymentMethod, $integrationId, $config->testTransactionId);
    }

    /** Classify one request represented as the live PHP array or a retained JSON object. */
    public function classify(string $url, mixed $request): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'accept.paymob.com') {
            throw new RuntimeException('A provider attempt URL failed its HTTPS host guard.');
        }

        $path = $parts['path'] ?? '';

        if ($this->scenario === 'transaction-inquiry') {
            if ($path === '/api/auth/tokens') {
                if (!$this->isRequestObject($request)) {
                    throw new RuntimeException('A provider attempt request must be an object.');
                }
                return $this->classifyAuth($request);
            }
            return $this->classifyTransactionInquiry($parts, $path, $request);
        }

        if (!$this->isRequestObject($request)) {
            throw new RuntimeException('A provider attempt request must be an object.');
        }

        return match ($path) {
            '/api/auth/tokens' => $this->classifyAuth($request),
            '/api/ecommerce/orders' => $this->classifyOrder($request),
            '/api/acceptance/payment_keys' => $this->classifyPaymentKey($request),
            '/api/acceptance/payments/pay' => $this->classifyPayment($request),
            default => throw new RuntimeException('A provider attempt URL path is not supported.'),
        };
    }

    /** Classify only the configured empty GET request, including its retained empty body. */
    private function classifyTransactionInquiry(array $parts, string $path, mixed $request): string
    {
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('~^/api/acceptance/transactions/([1-9][0-9]*)$~D', $path, $matches) !== 1
            || (string)$this->transactionId !== $matches[1]
            || ($request !== [] && $request !== null)) {
            throw new RuntimeException('A Transaction Inquiry attempt does not match the configured empty GET request.');
        }

        return self::nextAttemptStage(
            $this->transactionInquiryAttempts,
            'transaction-inquiry',
            'transaction-inquiry-retry',
            'Transaction Inquiry',
        );
    }

    /** @param array<string, mixed>|stdClass $request */
    private function classifyAuth(array|stdClass $request): string
    {
        $this->requireNonEmptyString($request, 'api_key');

        return 'auth';
    }

    /** @param array<string, mixed>|stdClass $request */
    private function classifyOrder(array|stdClass $request): string
    {
        if (!in_array($this->scenario, ['order', 'payment-key', 'kiosk', 'wallet'], true)) {
            throw new RuntimeException('An Order attempt is not part of the selected verification scenario.');
        }

        $this->requireNonEmptyString($request, 'auth_token');
        $this->requireInteger($request, 'amount_cents');
        $this->requireNonEmptyString($request, 'currency');

        return self::nextAttemptStage($this->orderAttempts, 'order', 'order-retry', 'Order');
    }

    /** @param array<string, mixed>|stdClass $request */
    private function classifyPaymentKey(array|stdClass $request): string
    {
        if ($this->paymentMethod === null || $this->integrationId === null) {
            throw new RuntimeException('A Payment Key attempt has no configured method.');
        }

        $this->requireNonEmptyString($request, 'auth_token');
        $this->requireInteger($request, 'order_id');
        $this->requireInteger($request, 'amount_cents');
        $this->requireNonEmptyString($request, 'currency');
        $this->requireInteger($request, 'expiration');

        $billingData = $this->fieldValue($request, 'billing_data');
        if (!$this->hasField($request, 'billing_data')
            || !$this->isRequestObject($billingData)) {
            throw new RuntimeException('A Payment Key attempt is missing valid billing data.');
        }

        if ($this->fieldValue($request, 'integration_id') !== $this->integrationId) {
            throw new RuntimeException('A Payment Key attempt does not match the configured integration.');
        }

        $stage = 'payment-key-' . $this->paymentMethod;

        return self::nextAttemptStage(
            $this->paymentKeyAttempts,
            $stage,
            $stage . '-retry',
            'Payment Key',
        );
    }

    /** @param array<string, mixed>|stdClass $request */
    private function classifyPayment(array|stdClass $request): string
    {
        if ($this->scenario === 'kiosk') {
            $this->requireSource($request, 'AGGREGATOR', 'AGGREGATOR');
            $this->requireNonEmptyString($request, 'payment_token');
            $this->requireNonEmptyString($request, 'auth_token');

            return self::nextAttemptStage(
                $this->kioskPaymentAttempts,
                'kiosk-payment',
                'kiosk-payment-retry',
                'Kiosk Pay',
            );
        }

        if ($this->scenario !== 'wallet') {
            throw new RuntimeException('A payment attempt is not part of the selected verification scenario.');
        }

        $this->requireSource($request, 'WALLET');
        $this->requireNonEmptyString($request, 'payment_token');

        $hasAuthToken = $this->hasField($request, 'auth_token');
        if ($this->walletPaymentAttempts === 0 && $hasAuthToken) {
            throw new RuntimeException('The initial Wallet attempt unexpectedly contains an Auth token.');
        }
        if ($this->walletPaymentAttempts > 0) {
            $this->requireNonEmptyString($request, 'auth_token');
        }

        return self::nextAttemptStage(
            $this->walletPaymentAttempts,
            'wallet-initiation',
            'wallet-initiation-retry',
            'Wallet Pay',
        );
    }

    /** @param array<string, mixed>|stdClass $request */
    private function requireSource(
        array|stdClass $request,
        string $subtype,
        ?string $expectedIdentifier = null,
    ): void
    {
        $source = $this->fieldValue($request, 'source');
        if (!$this->hasField($request, 'source') || !$this->isRequestObject($source)) {
            throw new RuntimeException('A payment attempt is missing its source object.');
        }

        $identifier = $this->fieldValue($source, 'identifier');
        $sourceSubtype = $this->fieldValue($source, 'subtype');
        if (!is_string($identifier) || trim($identifier) === '' || $sourceSubtype !== $subtype
            || ($expectedIdentifier !== null && $identifier !== $expectedIdentifier)) {
            throw new RuntimeException('A payment attempt source does not match its selected method.');
        }
    }

    /** @param array<string, mixed>|stdClass $request */
    private function requireNonEmptyString(array|stdClass $request, string $field): void
    {
        $value = $this->fieldValue($request, $field);
        if (!$this->hasField($request, $field) || !is_string($value) || trim($value) === '') {
            throw new RuntimeException('A provider attempt is missing a required string field.');
        }
    }

    /** @param array<string, mixed>|stdClass $request */
    private function requireInteger(array|stdClass $request, string $field): void
    {
        $value = $this->fieldValue($request, $field);
        if (!$this->hasField($request, $field) || !is_int($value)) {
            throw new RuntimeException('A provider attempt is missing a required integer field.');
        }
    }

    /** @param array<string, mixed>|stdClass $request */
    private function hasField(array|stdClass $request, string $field): bool
    {
        return is_array($request) ? array_key_exists($field, $request) : property_exists($request, $field);
    }

    /** @param array<string, mixed>|stdClass $request */
    private function fieldValue(array|stdClass $request, string $field): mixed
    {
        if (is_array($request)) {
            return $request[$field] ?? null;
        }

        return $request->{$field} ?? null;
    }

    private function isRequestObject(mixed $request): bool
    {
        return $request instanceof stdClass || (is_array($request) && !array_is_list($request));
    }

    private static function nextAttemptStage(
        int &$attemptCount,
        string $initialStage,
        string $retryStage,
        string $operation,
    ): string {
        if ($attemptCount >= 2) {
            throw new RuntimeException('The retained or live run contains too many ' . $operation . ' attempts.');
        }

        $attemptCount++;

        return $attemptCount === 1 ? $initialStage : $retryStage;
    }
}
