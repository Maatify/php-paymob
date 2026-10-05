<?php

declare(strict_types=1);

namespace Maatify\Paymob\Config;

use InvalidArgumentException;

final readonly class PaymobConfig
{
    public string $baseUrl;

    public function __construct(
        public string $apiKey,
        public string $hmacSecret,
        public int $integrationIdCard,
        public int $integrationIdKiosk,
        public int $integrationIdWallet,
        string $baseUrl = 'https://accept.paymob.com/api',
    ) {
        if (trim($apiKey) === '' || trim($hmacSecret) === '') {
            throw new InvalidArgumentException('Paymob API key and HMAC secret must not be empty.');
        }
        if (min($integrationIdCard, $integrationIdKiosk, $integrationIdWallet) <= 0) {
            throw new InvalidArgumentException('Paymob integration IDs must be positive.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('Paymob base URL must be a valid HTTPS URL.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }
}
