<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 19:13
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\DTO;

final readonly class PaymentKeyResponseDTO
{
    public function __construct(
        public string $token,
        public int $orderId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'],
            orderId: (int)($data['order'] ?? 0),
        );
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}

