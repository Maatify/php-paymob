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

use Maatify\Paymob\Exception\ApiException;

final readonly class OrderItemDTO implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public int $amountCents,
        public int $quantity,
        public ?string $description = null
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['name'], $data['amount_cents'], $data['quantity']) || !is_string($data['name'])
            || !is_int($data['amount_cents']) || !is_int($data['quantity'])
            || (isset($data['description']) && !is_string($data['description']))) {
            throw new ApiException('Paymob order item response has invalid required fields.', null, $data);
        }
        return new self(
            name: $data['name'],
            amountCents: $data['amount_cents'],
            quantity: $data['quantity'],
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
