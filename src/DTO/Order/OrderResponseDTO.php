<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:41
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Order;

use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class OrderResponseDTO
{
    public function __construct(
        public int $id,
        public string $createdAt,
        public CurrencyEnum $currency,
        public int $amountCents,
        public ?string $merchantOrderId = null,
        public ?OrderItemsDTO $items = null,
        public ?array $row = null
    ) {}
}