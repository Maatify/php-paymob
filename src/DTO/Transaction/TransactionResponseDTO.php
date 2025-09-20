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
        public bool $isCaptured,
        public bool $isRefunded,
        public bool $isVoided,
        public bool $is3DSecure,
        public bool $isStandalonePayment,
        public ?int $integrationId = null,
        public ?int $profileId = null,
        public ?int $merchantId = null,
        public ?string $merchantOrderId = null,
        public ?string $paymentStatus = null,     // e.g. UNPAID, PAID, VOIDED
        public ?string $statusMessage = null,     // e.g. "Pending Payment"
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $sourceType = null,        // e.g. "aggregator", "wallet", "card"
        public ?string $sourceSubType = null,     // e.g. "AGGREGATOR", "Vodafone Cash"
        public ?string $redirectUrl = null,       // Wallet/Card redirect URL
        public ?int $refundedAmountCents = null,
        public ?int $capturedAmountCents = null,
        public ?array $items = null,              // Array of order items if available
        public ?array $billingData = null,        // Billing data (from payment key)
        public ?array $raw = null,                // Keep full raw response for debugging
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id                 : (int)($data['id'] ?? 0),
            orderId            : (int)($data['order']['id'] ?? $data['order_id'] ?? 0),
            amountCents        : (int)($data['amount_cents'] ?? 0),
            currency           : isset($data['currency']) ? CurrencyEnum::from($data['currency']) : CurrencyEnum::EGP,
            success            : (bool)($data['success'] ?? false),
            pending            : (bool)($data['pending'] ?? false),
            isCaptured         : (bool)($data['is_captured'] ?? false),
            isRefunded         : (bool)($data['is_refunded'] ?? false),
            isVoided           : (bool)($data['is_voided'] ?? false),
            is3DSecure         : (bool)($data['is_3d_secure'] ?? false),
            isStandalonePayment: (bool)($data['is_standalone_payment'] ?? false),
            integrationId      : $data['integration_id'] ?? null,
            profileId          : $data['profile_id'] ?? null,
            merchantId         : $data['order']['merchant']['id'] ?? null,
            merchantOrderId    : $data['order']['merchant_order_id'] ?? null,
            paymentStatus      : $data['order']['payment_status'] ?? $data['payment_status'] ?? null,
            statusMessage      : $data['data']['message'] ?? null,
            createdAt          : $data['created_at'] ?? null,
            updatedAt          : $data['updated_at'] ?? null,
            sourceType         : $data['source_data']['type'] ?? null,
            sourceSubType      : $data['source_data']['sub_type'] ?? null,
            redirectUrl        : $data['redirect_url'] ?? null,
            refundedAmountCents: $data['refunded_amount_cents'] ?? null,
            capturedAmountCents: $data['captured_amount'] ?? null,
            items              : $data['order']['items'] ?? null,
            billingData        : $data['payment_key_claims']['billing_data'] ?? null,
            raw                : $data,
        );
    }
}

