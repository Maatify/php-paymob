<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 17:42
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Payment;

final readonly class WalletPaymentRequestDTO
{
    public function __construct(
        public string $paymentToken,
        public string $phoneNumber
    ) {}

    public function toArray(): array
    {
        return [
            'source' => [
                'identifier' => $this->phoneNumber,
                'subtype'    => 'WALLET'
            ],
            'payment_token' => $this->paymentToken
        ];
    }
}

