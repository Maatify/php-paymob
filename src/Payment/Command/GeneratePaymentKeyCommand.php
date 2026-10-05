<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Command;

use InvalidArgumentException;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Payment\ValueObject\BillingData;

final readonly class GeneratePaymentKeyCommand
{
    public function __construct(public int $orderId, public int $integrationId, public int $amountCents, public CurrencyEnum $currency,
        public BillingData $billingData, public int $expirationSeconds = 180)
    {
        if (min($orderId, $integrationId, $amountCents, $expirationSeconds) <= 0) throw new InvalidArgumentException('Order, integration, amount, and expiration values must be positive.');
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'integration_id' => $this->integrationId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency->value,
            'expiration' => $this->expirationSeconds,
            'billing_data' => $this->billingData->toArray(),
        ];
    }
}
