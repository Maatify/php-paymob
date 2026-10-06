<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Maatify\Paymob\Config\PaymobConfig;
use RuntimeException;

/** Holds only the local credentials and synthetic inputs required by one verification scenario. */
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
        public ?string $secretKey,
        public ?string $notificationUrl,
        public ?string $redirectionUrl,
        public string $scenario,
        public ?string $paymentMethod,
    ) {}

    /**
     * Read the local .env without displaying values and validate only the credentials,
     * integration configuration, and synthetic inputs required by the selected scenario.
     */
    public static function load(string $repositoryRoot, string $scenario, ?string $paymentMethod = null): self
    {
        $root = realpath($repositoryRoot);
        if ($root === false || !is_file($root . '/.env')) {
            throw new RuntimeException('The repository-local .env file is required.');
        }

        if (!in_array(
            $scenario,
            ['auth', 'order', 'payment-key', 'kiosk', 'wallet', 'transaction-inquiry', 'intention'],
            true,
        )) {
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
        if ($scenario === 'intention') {
            $secretKey = self::required($values, 'PAYMOB_SECRET_KEY');
            $cardIntegrationId = self::positiveInteger($values, 'PAYMOB_INTEGRATION_ID_CARD');
            $notificationUrl = self::required($values, 'PAYMOB_TEST_NOTIFICATION_URL');
            $redirectionUrl = self::required($values, 'PAYMOB_TEST_REDIRECTION_URL');
            foreach ([$notificationUrl, $redirectionUrl] as $url) {
                $parts = parse_url($url);
                if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                    || !is_string($parts['host'] ?? null) || $parts['host'] === ''
                    || isset($parts['user']) || isset($parts['pass'])
                    || isset($parts['fragment'])) {
                    throw new RuntimeException('A required Intention verification URL is invalid.');
                }
            }
            return new self(
                $root,
                '',
                'https://accept.paymob.com',
                '',
                $cardIntegrationId,
                null,
                null,
                null,
                null,
                $secretKey,
                $notificationUrl,
                $redirectionUrl,
                $scenario,
                null,
            );
        }
        $apiKey = self::required($values, 'PAYMOB_API_KEY');
        $baseUrl = rtrim(self::required($values, 'PAYMOB_BASE_URL'), '/');
        $hmacSecret = self::required($values, 'PAYMOB_HMAC_SECRET');

        if ($baseUrl !== 'https://accept.paymob.com/api') {
            throw new RuntimeException('PAYMOB_BASE_URL does not match the Paymob Egypt API base URL.');
        }

        $cardIntegrationId = null;
        $kioskIntegrationId = null;
        $walletIntegrationId = null;
        $walletTestMsisdn = null;
        $testTransactionId = null;

        $cardIntegrationId = self::positiveInteger($values, 'PAYMOB_INTEGRATION_ID_CARD');
        $kioskIntegrationId = self::positiveInteger($values, 'PAYMOB_INTEGRATION_ID_KIOSK');
        $walletIntegrationId = self::positiveInteger($values, 'PAYMOB_INTEGRATION_ID_WALLET');

        if ($scenario === 'wallet') {
            $walletTestMsisdn = self::required($values, 'PAYMOB_TEST_WALLET_MSISDN');
            if ($walletTestMsisdn !== '01010101010') {
                throw new RuntimeException('PAYMOB_TEST_WALLET_MSISDN does not match the approved sandbox test input.');
            }
        }

        if ($scenario === 'transaction-inquiry') {
            $value = $values['PAYMOB_TEST_TRANSACTION_ID'] ?? null;
            $testTransactionId = self::canonicalPositiveInteger($value, 'The transaction inquiry input must be a positive integer.');
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
            null,
            null,
            null,
            $scenario,
            $paymentMethod,
        );
    }

    /** Load only the Card integration ID needed to inspect retained Intention evidence offline. */
    public static function loadIntentionRecovery(string $repositoryRoot): self
    {
        $root = realpath($repositoryRoot);
        if ($root === false || !is_file($root . '/.env')) {
            throw new RuntimeException('The repository-local .env file is required.');
        }
        $contents = file_get_contents($root . '/.env');
        if (!is_string($contents)) {
            throw new RuntimeException('The repository-local .env file could not be read.');
        }
        $values = Dotenv::parse($contents);
        return new self(
            $root, '', 'https://accept.paymob.com', '',
            self::positiveInteger($values, 'PAYMOB_INTEGRATION_ID_CARD'),
            null, null, null, null, null, null, null, 'intention', null,
        );
    }

    /** Build the package configuration while leaving unused integration IDs unselected. */
    public function packageConfig(): PaymobConfig
    {
        return new PaymobConfig(
            $this->apiKey,
            $this->hmacSecret,
            $this->cardIntegrationId ?? 0,
            $this->kioskIntegrationId ?? 0,
            $this->walletIntegrationId ?? 0,
            $this->baseUrl,
        );
    }

    /** @return list<string> */
    public function configuredSecrets(): array
    {
        return array_values(array_filter(
            [$this->apiKey, $this->hmacSecret, $this->secretKey],
            static fn(?string $value): bool => is_string($value) && $value !== '',
        ));
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
        return self::canonicalPositiveInteger(
            $values[$key] ?? null,
            'A required integration ID is not a positive integer.',
        );
    }

    private static function canonicalPositiveInteger(mixed $value, string $message): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new RuntimeException($message);
        }

        $maximum = (string)PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new RuntimeException($message);
        }

        return (int)$value;
    }
}
