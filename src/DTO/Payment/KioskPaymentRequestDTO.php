<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 21:10
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Payment;

final readonly class KioskPaymentRequestDTO
{
    public function __construct(
        public string $paymentToken,
    ) {}

    public function toArray(string $authToken): array
    {
        return [
            'source' => [
                'identifier' => 'AGGREGATOR',
                'subtype'    => 'AGGREGATOR',
            ],
            'payment_token' => $this->paymentToken,
            'auth_token'    => $authToken,
        ];
    }
}