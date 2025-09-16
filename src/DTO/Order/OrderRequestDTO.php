<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:42
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Order;

use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class OrderRequestDTO
{
    public function __construct(
        public int $amountCents,
        public CurrencyEnum $currency,
        public ?string $merchantOrderId = null,
        public ?OrderItemsDTO $items = null,
    ) {}

    public function toArray(string $authToken): array
    {
        return [
            'auth_token'       => $authToken,
            'amount_cents'     => $this->amountCents,
            'currency'         => $this->currency->value,
            'merchant_order_id'=> $this->merchantOrderId,
            'items'            => $this->items?->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            amountCents: (int) $data['amount_cents'],
            currency: CurrencyEnum::fromString($data['currency']),
            merchantOrderId: $data['merchant_order_id'] ?? null,
            items: !empty($data['items'])
                ? OrderItemsDTO::fromArray($data['items'])
                : null
        );
    }
}