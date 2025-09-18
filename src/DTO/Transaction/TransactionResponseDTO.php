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
        public string $paymentStatus,
        public string $paymentMethod,
        public string $createdAt,
        public ?string $updatedAt,
        public ?string $merchantOrderId = null,
        public ?array $sourceData = null,   // e.g., wallet/mobile/card ref
        public ?string $statusMessage = null,
        public ?array $row = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id             : (int)$data['id'],
            orderId        : (int)$data['order']['id'],
            amountCents    : (int)$data['amount_cents'],
            currency       : CurrencyEnum::from($data['currency']),
            success        : (bool)$data['success'],
            pending        : (bool)($data['pending'] ?? false),
            paymentStatus  : $data['pending'] ? 'PENDING' : ($data['success'] ? 'SUCCESS' : 'FAILED'),
            paymentMethod  : $data['payment_method'] ?? ($data['source_data']['sub_type'] ?? 'unknown'),
            createdAt      : $data['created_at'],
            updatedAt      : $data['updated_at'] ?? null,
            merchantOrderId: $data['merchant_order_id'] ?? null,
            sourceData     : $data['source_data'] ?? null,
            statusMessage  : $data['data']['message'] ?? null,
            row            : $data
        );
    }
}
