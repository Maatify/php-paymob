<?php

declare(strict_types=1);

namespace Maatify\Paymob\Order\Command;

use InvalidArgumentException;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Order\ValueObject\OrderItem;

final readonly class CreateOrderCommand
{
    /** @param list<OrderItem> $items */
    public function __construct(
        public int $amountCents,
        public CurrencyEnum $currency,
        public ?string $merchantOrderId = null,
        public array $items = [],
    ) {
        if ($amountCents <= 0) throw new InvalidArgumentException('amountCents must be positive.');
        if ($merchantOrderId !== null && trim($merchantOrderId) === '') throw new InvalidArgumentException('merchantOrderId must not be empty.');
        foreach ($items as $item) if (!$item instanceof OrderItem) throw new InvalidArgumentException('items must contain OrderItem values.');
    }

    public function toArray(): array
    {
        return [
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency->value,
            'merchant_order_id' => $this->merchantOrderId,
            'items' => array_map(static fn (OrderItem $item) => $item->toArray(), $this->items),
        ];
    }
}
