<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use RuntimeException;
use stdClass;

/** Enforces each scenario's legal request transitions and exact 401 recovery rules. */
final class ProviderAttemptStageClassifier
{
    private string $nextOperation = 'auth';

    private ?string $pendingRecovery = null;

    private ?string $authorizedStage = null;

    /** @param string $secretKey Verification credential used only during pre-network authorization checks. */
    private function __construct(
        private readonly string $scenario,
        private readonly ?string $paymentMethod,
        private readonly ?int $integrationId,
        private readonly ?int $transactionId,
        private readonly string $secretKey,
        private readonly ?string $expectedNotificationUrl,
        private readonly ?string $expectedRedirectionUrl,
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
            default => $config->scenario === 'intention' ? $config->cardIntegrationId : null,
        };

        if ($paymentMethod !== null && (!is_int($integrationId) || $integrationId < 1)) {
            throw new RuntimeException('The configured Payment Key integration is unavailable for attempt classification.');
        }

        if ($config->scenario === 'transaction-inquiry'
            && (!is_int($config->testTransactionId) || $config->testTransactionId < 1)) {
            throw new RuntimeException('The configured Transaction ID is unavailable for attempt classification.');
        }

        $classifier = new self(
            $config->scenario,
            $paymentMethod,
            $integrationId,
            $config->testTransactionId,
            $config->secretKey ?? '',
            $config->notificationUrl,
            $config->redirectionUrl,
        );
        if ($config->scenario === 'intention') {
            $classifier->nextOperation = 'create-intention';
        }

        return $classifier;
    }

    /** Validate and authorize the exact next request in the current run. */
    public function classify(string $url, mixed $request, string $method = 'POST', array $headers = []): string
    {
        if ($this->authorizedStage !== null) {
            throw new RuntimeException('The previous provider attempt has no recorded outcome.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'accept.paymob.com') {
            throw new RuntimeException('A provider attempt URL failed its HTTPS host guard.');
        }

        $path = $parts['path'] ?? '';
        if ($this->scenario === 'intention') {
            if ($method !== 'POST' || $path !== '/v1/intention/' || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('The Intention attempt URL or method failed its exact contract guard.');
            }
            $operation = $this->validateIntention($request, $headers);
        } else {
            if ($this->scenario === 'transaction-inquiry' && $path === '/api/acceptance/transactions/' . $this->transactionId) {
                $operation = $this->validateTransactionInquiry($parts, $path, $request);
            } else {
                if (!$this->isRequestObject($request)) {
                    throw new RuntimeException('A provider attempt request must be an object.');
                }
                $operation = match ($path) {
                    '/api/auth/tokens' => $this->validateAuth($request),
                    '/api/ecommerce/orders' => $this->validateOrder($request),
                    '/api/acceptance/payment_keys' => $this->validatePaymentKey($request),
                    '/api/acceptance/payments/pay' => $this->validatePayment($request),
                    default => throw new RuntimeException('A provider attempt URL path is not supported.'),
                };
            }
        }

        $refresh = $this->pendingRecovery !== null && $this->nextOperation === 'auth';
        if ($operation !== $this->nextOperation) {
            throw new RuntimeException('A provider attempt is not the next legal workflow transition.');
        }

        if ($refresh && $operation !== 'auth') {
            throw new RuntimeException('An approved recovery requires a fresh Auth request first.');
        }

        if ($operation === 'auth') {
            $stage = 'auth';
        } elseif ($refresh) {
            throw new RuntimeException('A recovery operation cannot bypass its fresh Auth request.');
        } elseif ($this->pendingRecovery !== null) {
            if ($operation !== $this->pendingRecovery) {
                throw new RuntimeException('A recovery must replay the same failed operation.');
            }
            $stage = $operation . '-retry';
        } else {
            $stage = $operation;
        }

        $this->authorizedStage = $stage;
        return $stage;
    }

    /** @param array<string, mixed> $headers */
    private function validateIntention(mixed $request, array $headers): string
    {
        if ($this->nextOperation !== 'create-intention' || !is_array($request) || array_is_list($request)) {
            throw new RuntimeException('The Intention scenario must start with its exact create request.');
        }
        $expected = [
            'amount', 'currency', 'payment_methods', 'items', 'billing_data',
            'special_reference', 'expiration', 'notification_url', 'redirection_url',
        ];
        $actual = array_keys((array)$request);
        sort($expected);
        sort($actual);
        $amount = $request['amount'] ?? null;
        if ($actual !== $expected || !is_int($amount) || $amount < 1
            || ($request['currency'] ?? null) !== 'EGP'
            || ($request['payment_methods'] ?? null) !== [$this->integrationId]
            || !is_array($request['items'] ?? null) || count($request['items']) !== 1
            || !is_array($request['items'][0] ?? null)
            || array_keys($request['items'][0]) !== ['name', 'amount', 'description', 'quantity']
            || ($request['items'][0]['name'] ?? null) !== 'Synthetic verification item'
            || ($request['items'][0]['amount'] ?? null) !== $amount
            || ($request['items'][0]['quantity'] ?? null) !== 1
            || ($request['items'][0]['description'] ?? null) !== 'Synthetic provider verification item'
            || ($request['billing_data'] ?? null) !== [
                'apartment' => '1', 'first_name' => 'Verification', 'last_name' => 'Customer',
                'street' => 'Synthetic Street', 'building' => '1',
                'phone_number' => '+20' . '1000' . str_repeat('0', 6),
                'city' => 'Cairo', 'country' => 'EG', 'email' => 'verification@example.test',
                'floor' => '1', 'state' => 'Cairo',
            ]
            || !is_string($request['special_reference'] ?? null)
            || preg_match('/^verification-[a-f0-9]{24}$/D', $request['special_reference']) !== 1
            || !is_int($request['expiration'] ?? null) || $request['expiration'] < 1 || $request['expiration'] > 3600
            || ($request['notification_url'] ?? null) !== $this->expectedNotificationUrl
            || ($request['redirection_url'] ?? null) !== $this->expectedRedirectionUrl) {
            throw new RuntimeException('The Intention request does not match its closed synthetic Card contract.');
        }
        $headerMap = [];
        if (count($headers) !== 2) {
            throw new RuntimeException('The Intention request must carry exactly its authorization and JSON headers.');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new RuntimeException('The Intention request headers must be explicit name/value pairs.');
            }
            $headerName = strtolower($name);
            if (isset($headerMap[$headerName])) {
                throw new RuntimeException('The Intention request contains a duplicate header.');
            }
            $headerMap[$headerName] = $value;
        }
        if (($headerMap['authorization'] ?? null) !== 'Token ' . $this->secretKey
            || $this->secretKey === '' || strtolower($headerMap['content-type'] ?? '') !== 'application/json') {
            throw new RuntimeException(
                'The Intention request authorization or content type failed its pre-network guard.',
            );
        }
        return 'create-intention';
    }

    /** Validate successful provider response fields without inferring absent values. */
    public static function validateIntentionResponse(mixed $response, int $integrationId, string $reference): bool
    {
        if (!is_array($response) || !is_string($response['id'] ?? null) || trim($response['id']) === ''
            || !is_int($response['intention_order_id'] ?? null) || $response['intention_order_id'] < 1
            || !is_string($response['client_secret'] ?? null) || trim($response['client_secret']) === ''
            || !is_array($response['payment_methods'] ?? null) || !is_string($response['special_reference'] ?? null)
            || $response['special_reference'] !== $reference || !is_bool($response['confirmed'] ?? null)
            || !is_string($response['status'] ?? null) || trim($response['status']) === '') {
            return false;
        }
        foreach ($response['payment_methods'] as $method) {
            if (is_array($method) && ($method['integration_id'] ?? null) === $integrationId) {
                return true;
            }
            if ($method instanceof stdClass && ($method->integration_id ?? null) === $integrationId) {
                return true;
            }
        }
        return false;
    }

    /** Validate retained Intention JSON without credential or callback configuration. */
    public static function validateRetainedIntentionRequest(mixed $request, int $integrationId): bool
    {
        if (!is_array($request) || array_is_list($request)) {
            return false;
        }
        $expected = ['amount', 'currency', 'payment_methods', 'items', 'billing_data', 'special_reference', 'expiration', 'notification_url', 'redirection_url'];
        $actual = array_keys($request);
        sort($expected);
        sort($actual);
        foreach (['notification_url', 'redirection_url'] as $urlKey) {
            $url = $request[$urlKey] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
                || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                return false;
            }
        }
        $amount = $request['amount'] ?? null;
        return $actual === $expected && is_int($amount) && $amount > 0
            && ($request['currency'] ?? null) === 'EGP'
            && ($request['payment_methods'] ?? null) === [$integrationId]
            && ($request['items'] ?? null) === [[
                'name' => 'Synthetic verification item', 'amount' => $amount,
                'description' => 'Synthetic provider verification item', 'quantity' => 1,
            ]]
            && ($request['billing_data'] ?? null) === [
                'apartment' => '1', 'first_name' => 'Verification', 'last_name' => 'Customer',
                'street' => 'Synthetic Street', 'building' => '1', 'phone_number' => '+20' . '1000' . str_repeat('0', 6),
                'city' => 'Cairo', 'country' => 'EG', 'email' => 'verification@example.test',
                'floor' => '1', 'state' => 'Cairo',
            ]
            && is_string($request['special_reference'] ?? null)
            && preg_match('/^verification-[a-f0-9]{24}$/D', $request['special_reference']) === 1
            && is_int($request['expiration'] ?? null) && $request['expiration'] >= 1 && $request['expiration'] <= 3600;
    }

    /** Record the actual captured transport and HTTP outcome before response decoding. */
    public function recordOutcome(string $stage, bool $transportOk, ?int $httpStatus): void
    {
        if ($this->authorizedStage !== $stage) {
            throw new RuntimeException('A provider outcome does not match the authorized request.');
        }
        $this->authorizedStage = null;

        if (!$transportOk || $httpStatus === null) {
            $this->nextOperation = 'terminal';
            $this->pendingRecovery = null;
            return;
        }

        if ($this->scenario === 'intention' && $stage === 'create-intention' && $httpStatus === 401) {
            $this->nextOperation = 'terminal';
            $this->pendingRecovery = null;
            return;
        }

        if ($httpStatus === 401 && $stage !== 'auth' && !str_ends_with($stage, '-retry')
            && $stage !== 'wallet-initiation') {
            $this->pendingRecovery = $stage;
            $this->nextOperation = 'auth';
            return;
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->nextOperation = 'terminal';
            $this->pendingRecovery = null;
            return;
        }

        if ($stage === 'auth') {
            if ($this->pendingRecovery !== null) {
                $this->nextOperation = $this->pendingRecovery;
            } else {
                $this->nextOperation = $this->firstOperation();
            }
            return;
        }

        if (str_ends_with($stage, '-retry')) {
            $this->pendingRecovery = null;
        }
        $this->nextOperation = $this->nextAfter($this->baseOperation($stage));
    }

    /** Retained triplets have no status metadata and may advance only the normal non-retry path. */
    public function recordRetainedOutcome(string $stage): void
    {
        if ($this->authorizedStage !== $stage || $this->pendingRecovery !== null
            || str_ends_with($stage, '-retry')) {
            throw new RuntimeException('Retained evidence cannot prove an authentication recovery transition.');
        }
        $this->authorizedStage = null;
        if ($stage === 'auth') {
            $this->nextOperation = $this->firstOperation();
            return;
        }
        $this->nextOperation = $this->nextAfter($stage);
    }

    /** @param array<string, mixed>|stdClass $request */
    private function validateAuth(array|stdClass $request): string
    {
        $this->requireNonEmptyString($request, 'api_key');
        return 'auth';
    }

    /** @param array<string, mixed>|stdClass $request */
    private function validateOrder(array|stdClass $request): string
    {
        if (!in_array($this->scenario, ['order', 'payment-key', 'kiosk', 'wallet'], true)) {
            throw new RuntimeException('An Order attempt is not part of the selected verification scenario.');
        }
        $this->requireNonEmptyString($request, 'auth_token');
        $this->requireInteger($request, 'amount_cents');
        $this->requireNonEmptyString($request, 'currency');
        return 'order';
    }

    /** @param array<string, mixed>|stdClass $request */
    private function validatePaymentKey(array|stdClass $request): string
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
        if (!$this->hasField($request, 'billing_data') || !$this->isRequestObject($billingData)) {
            throw new RuntimeException('A Payment Key attempt is missing valid billing data.');
        }
        if ($this->fieldValue($request, 'integration_id') !== $this->integrationId) {
            throw new RuntimeException('A Payment Key attempt does not match the configured integration.');
        }
        return 'payment-key-' . $this->paymentMethod;
    }

    /** @param array<string, mixed>|stdClass $request */
    private function validatePayment(array|stdClass $request): string
    {
        if ($this->scenario === 'kiosk') {
            $this->requireSource($request, 'AGGREGATOR', 'AGGREGATOR');
            $this->requireNonEmptyString($request, 'payment_token');
            $this->requireNonEmptyString($request, 'auth_token');
            return 'kiosk-payment';
        }
        if ($this->scenario !== 'wallet') {
            throw new RuntimeException('A payment attempt is not part of the selected verification scenario.');
        }
        $this->requireSource($request, 'WALLET');
        $this->requireNonEmptyString($request, 'payment_token');
        if ($this->hasField($request, 'auth_token')) {
            throw new RuntimeException('Wallet Pay must not contain an Auth token.');
        }
        return 'wallet-initiation';
    }

    /** @param array<string, mixed>|stdClass $request */
    private function validateTransactionInquiry(array $parts, string $path, mixed $request): string
    {
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('~^/api/acceptance/transactions/([1-9][0-9]*)$~D', $path, $matches) !== 1
            || (string)$this->transactionId !== $matches[1] || ($request !== [] && $request !== null)) {
            throw new RuntimeException('A Transaction Inquiry attempt does not match the configured empty GET request.');
        }
        return 'transaction-inquiry';
    }

    private function firstOperation(): string
    {
        return match ($this->scenario) {
            'auth' => 'terminal',
            'intention' => 'create-intention',
            'transaction-inquiry' => 'transaction-inquiry',
            default => 'order',
        };
    }

    private function nextAfter(string $operation): string
    {
        return match ([$this->scenario, $operation]) {
            ['order', 'order'] => 'terminal',
            ['payment-key', 'order'] => 'payment-key-' . $this->paymentMethod,
            ['kiosk', 'order'] => 'payment-key-kiosk',
            ['wallet', 'order'] => 'payment-key-wallet',
            ['payment-key', 'payment-key-' . $this->paymentMethod] => 'terminal',
            ['kiosk', 'payment-key-kiosk'] => 'kiosk-payment',
            ['wallet', 'payment-key-wallet'] => 'wallet-initiation',
            default => 'terminal',
        };
    }

    private function baseOperation(string $stage): string
    {
        return str_ends_with($stage, '-retry') ? substr($stage, 0, -6) : $stage;
    }

    /** @param array<string, mixed>|stdClass $request */
    private function requireSource(array|stdClass $request, string $subtype, ?string $expectedIdentifier = null): void
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
        return is_array($request) ? ($request[$field] ?? null) : ($request->{$field} ?? null);
    }

    private function isRequestObject(mixed $request): bool
    {
        return $request instanceof stdClass || (is_array($request) && !array_is_list($request));
    }
}
