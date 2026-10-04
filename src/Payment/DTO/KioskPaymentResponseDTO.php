<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 21:10
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\DTO;

use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class KioskPaymentResponseDTO
{
    public function __construct(
        public int $transactionId,
        public int $orderId,
        public string $merchantOrderId,
        public int $amountCents,
        public CurrencyEnum $currency,
        public bool $pending,
        public bool $success,
        public ?int $billReference = null,
        public ?string $statusMessage = null,
        public ?string $paymentStatus = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?array $row = null,
    ) {}

    public static function fromArray(array $response): self
    {
        return new self(
            transactionId  : (int)$response['id'],
            orderId        : (int)($response['order']['id'] ?? 0),
            merchantOrderId: $response['order']['merchant_order_id'] ?? '',
            amountCents    : (int)($response['amount_cents'] ?? 0),
            currency       : isset($response['currency'])
                ? CurrencyEnum::from($response['currency'])
                : CurrencyEnum::EGP, // fallback default
            pending        : (bool)($response['pending'] ?? false),
            success        : (bool)($response['success'] ?? false),
            billReference  : $response['data']['bill_reference'] ?? null,
            statusMessage  : $response['data']['message'] ?? null,
            paymentStatus  : $response['order']['payment_status'] ?? null,
            createdAt      : $response['created_at'] ?? null,
            updatedAt      : $response['updated_at'] ?? null,
            row            : $response
        );
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}