<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Maatify\Paymob\Config\PaymobConfig;
use RuntimeException;

/** Holds scenario-scoped local inputs for manual Paymob verification. */
final readonly class VerificationConfig
{
    private function __construct(
        public string $repositoryRoot,
        public string $apiKey,
        public string $baseUrl,
        public string $hmacSecret,
        public ?int $cardIntegrationId,
        public ?int $kioskIntegrationId,
        public ?int $walletIntegrationId,
        public ?string $walletTestMsisdn,
        public ?int $testTransactionId,
        public ?string $keysExpiry,
        public string $scenario,
        public ?string $paymentMethod,
    ) {}

    /**
     * Read the local .env without displaying values and validate only inputs
     * required by the requested scenario.
     */
    public static function load(string $repositoryRoot, string $scenario, ?string $paymentMethod = null): self
    {
        $root = realpath($repositoryRoot);
        if ($root === false || !is_file($root . '/.env')) {
            throw new RuntimeException('The repository-local .env file is required.');
        }

        if (!in_array($scenario, ['auth', 'order', 'payment-key', 'kiosk', 'wallet', 'transaction-inquiry'], true)) {
            throw new InvalidArgumentException('Unknown provider verification scenario.');
        }

        if ($scenario === 'payment-key' && !in_array($paymentMethod, ['card', 'kiosk', 'wallet'], true)) {
            throw new InvalidArgumentException('Select exactly one payment-key method: card, kiosk, or wallet.');
        }

        if ($scenario !== 'payment-key' && $paymentMethod !== null) {
            throw new InvalidArgumentException('A payment-key method is only valid for payment-key.php.');
        }

        $contents = file_get_contents($root . '/.env');
        if (!is_string($contents)) {
            throw new RuntimeException('The repository-local .env file could not be read.');
        }

        $values = Dotenv::parse($contents);
        $apiKey = self::required($values, 'PAYMOB_API_KEY');
        $baseUrl = rtrim(self::required($values, 'PAYMOB_BASE_URL'), '/');
        $hmacSecret = isset($values['PAYMOB_HMAC_SECRET']) && trim($values['PAYMOB_HMAC_SECRET']) !== ''
            ? trim($values['PAYMOB_HMAC_SECRET'])
            : '';

        if ($baseUrl !== 'https://accept.paymob.com/api') {
            throw new RuntimeException('PAYMOB_BASE_URL does not match the Paymob Egypt API base URL.');
        }

        $cardIntegrationId = null;
        $kioskIntegrationId = null;
        $walletIntegrationId = null;
        $walletTestMsisdn = null;
        $testTransactionId = null;

        $requiredIntegration = match (true) {
            $scenario === 'payment-key' && $paymentMethod === 'card' => 'card',
            $scenario === 'payment-key' && $paymentMethod === 'kiosk' => 'kiosk',
            $scenario === 'payment-key' && $paymentMethod === 'wallet' => 'wallet',
            $scenario === 'kiosk' => 'kiosk',
            $scenario === 'wallet' => 'wallet',
            default => null,
        };

        if ($requiredIntegration !== null) {
            $key = match ($requiredIntegration) {
                'card' => 'PAYMOB_INTEGRATION_ID_CARD',
                'kiosk' => 'PAYMOB_INTEGRATION_ID_KIOSK',
                'wallet' => 'PAYMOB_INTEGRATION_ID_WALLET',
            };
            $id = self::positiveInteger($values, $key);
            match ($requiredIntegration) {
                'card' => $cardIntegrationId = $id,
                'kiosk' => $kioskIntegrationId = $id,
                'wallet' => $walletIntegrationId = $id,
            };
        }

        if ($scenario === 'wallet') {
            $walletTestMsisdn = self::required($values, 'PAYMOB_TEST_WALLET_MSISDN');
            if ($walletTestMsisdn !== '01010101010') {
                throw new RuntimeException('PAYMOB_TEST_WALLET_MSISDN does not match the approved sandbox test input.');
            }
        }

        if ($scenario === 'transaction-inquiry') {
            $value = self::required($values, 'PAYMOB_TEST_TRANSACTION_ID');
            if (!ctype_digit($value) || $value[0] === '0' || (string)(int)$value !== $value) {
                throw new RuntimeException('The transaction inquiry input must be a positive integer.');
            }
            $testTransactionId = (int)$value;
        }

        $keysExpiry = isset($values['PAYMOB_KEYS_EXPIRY']) && trim((string)$values['PAYMOB_KEYS_EXPIRY']) !== ''
            ? trim((string)$values['PAYMOB_KEYS_EXPIRY'])
            : null;

        if ($keysExpiry !== null) {
            $_ENV['PAYMOB_KEYS_EXPIRY'] = $keysExpiry;
        } else {
            unset($_ENV['PAYMOB_KEYS_EXPIRY']);
        }

        return new self(
            $root,
            $apiKey,
            $baseUrl,
            $hmacSecret,
            $cardIntegrationId,
            $kioskIntegrationId,
            $walletIntegrationId,
            $walletTestMsisdn,
            $testTransactionId,
            $keysExpiry,
            $scenario,
            $paymentMethod,
        );
    }

    /** Build the package configuration while leaving unused integration IDs unselected. */
    public function packageConfig(): PaymobConfig
    {
        // PaymobConfig requires all IDs; zero fills only fields unused by this scenario.
        return new PaymobConfig(
            $this->apiKey,
            $this->cardIntegrationId ?? 0,
            $this->kioskIntegrationId ?? 0,
            $this->walletIntegrationId ?? 0,
            $this->baseUrl,
            $this->hmacSecret,
        );
    }

    /** @return list<string> */
    public function configuredSecrets(): array
    {
        return array_values(array_filter([$this->apiKey, $this->hmacSecret], static fn(string $v): bool => $v !== ''));
    }

    /** @param array<string, string> $values */
    private static function required(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('A required provider verification input is missing.');
        }

        return trim($value);
    }

    /** @param array<string, string> $values */
    private static function positiveInteger(array $values, string $key): int
    {
        $value = self::required($values, $key);
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new RuntimeException('A required integration ID is not a positive integer.');
        }

        return (int)$value;
    }
}
