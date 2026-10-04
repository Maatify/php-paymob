<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:39
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Order\DTO;

final readonly class OrderItemDTO
{
    public function __construct(
        public string $name,
        public int $amountCents,
        public int $quantity,
        public ?string $description = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            amountCents: (int) $data['amount_cents'],
            quantity: (int) $data['quantity'],
            description: $data['description'] ?? null
        );
    }

    public function toArray(): array
    {
        $data = [
            'name'        => $this->name,
            'amount_cents'=> $this->amountCents,
            'quantity'    => $this->quantity,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        return $data;
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}
