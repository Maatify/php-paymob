<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Command;

use InvalidArgumentException;

final readonly class InitiateKioskPaymentCommand
{
    public function __construct(public string $paymentToken)
    {
        if (trim($paymentToken) === '') throw new InvalidArgumentException('paymentToken must not be empty.');
    }

    public function toArray(): array
    {
        return [
            'source' => ['identifier' => 'AGGREGATOR', 'subtype' => 'AGGREGATOR'],
            'payment_token' => $this->paymentToken,
        ];
    }
}
