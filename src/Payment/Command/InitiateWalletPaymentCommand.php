<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Command;

use InvalidArgumentException;

final readonly class InitiateWalletPaymentCommand
{
    public function __construct(public string $paymentToken, public string $phoneNumber)
    {
        if (trim($paymentToken) === '' || trim($phoneNumber) === '') throw new InvalidArgumentException('paymentToken and phoneNumber must not be empty.');
    }

    public function toArray(): array
    {
        return ['source' => ['identifier' => $this->phoneNumber, 'subtype' => 'WALLET'], 'payment_token' => $this->paymentToken];
    }
}
