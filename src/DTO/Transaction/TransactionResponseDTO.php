<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 20:28
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Transaction;

use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class TransactionResponseDTO
{
    public function __construct(
        public int $id,
        public int $orderId,
        public int $amountCents,
        public CurrencyEnum $currency,
        public bool $success,
        public bool $pending,
        public ?string $hmac = null,
        public ?string $paymentKeyClaims = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $createdAt = null
    ) {}
}
