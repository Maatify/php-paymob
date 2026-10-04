<?php

declare(strict_types=1);

namespace Maatify\Paymob\Order\ValueObject;

use InvalidArgumentException;

final readonly class OrderItem
{
    public function __construct(public string $name, public int $amountCents, public int $quantity, public ?string $description = null)
    {
        if (trim($name) === '' || $amountCents <= 0 || $quantity <= 0) throw new InvalidArgumentException('Order item requires a name, positive amount, and positive quantity.');
    }

    public function toArray(): array
    {
        $item = ['name' => $this->name, 'amount_cents' => $this->amountCents, 'quantity' => $this->quantity];
        if ($this->description !== null) $item['description'] = $this->description;
        return $item;
    }
}
