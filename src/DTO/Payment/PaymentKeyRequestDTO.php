<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 19:12
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Payment;

use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class PaymentKeyRequestDTO
{
    public function __construct(
        public int $orderId,
        public int $integrationId,
        public int $amountCents,
        public CurrencyEnum $currency,
        public BillingDataDTO $billingData,
        public int $expirationMinutes = 180, // default
    ) {}

    public function toArray(string $authToken): array
    {
        return [
            'auth_token'     => $authToken,
            'order_id'       => $this->orderId,
            'integration_id' => $this->integrationId,
            'amount_cents'   => $this->amountCents,
            'currency'       => $this->currency->value,
            'expiration'     => $this->expirationMinutes,
            'billing_data'   => $this->billingData->toArray(),
        ];
    }
}