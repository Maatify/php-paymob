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

namespace Maatify\Paymob\Transaction\DTO;

use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\ApiException;

final readonly class TransactionResponseDTO implements \JsonSerializable
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
        foreach (['id', 'amount_cents', 'currency', 'success', 'pending', 'is_captured', 'is_refunded', 'is_voided', 'is_3d_secure', 'is_standalone_payment'] as $field) {
            if (!array_key_exists($field, $data)) throw new ApiException("Paymob Transaction response is missing required field: {$field}.", null, $data);
        }
        if (!is_int($data['id']) || $data['id'] <= 0 || !is_int($data['amount_cents']) || $data['amount_cents'] <= 0
            || !is_string($data['currency'])) throw new ApiException('Paymob Transaction response contains invalid required fields.', null, $data);
        foreach (['success', 'pending', 'is_captured', 'is_refunded', 'is_voided', 'is_3d_secure', 'is_standalone_payment'] as $field) {
            if (!is_bool($data[$field])) throw new ApiException("Paymob Transaction response has an invalid boolean field: {$field}.", null, $data);
        }
        $orderId = $data['order']['id'] ?? $data['order_id'] ?? null;
        if (!is_int($orderId) || $orderId <= 0) throw new ApiException('Paymob Transaction response is missing a valid order ID.', null, $data);
        $currency = CurrencyEnum::tryFrom($data['currency']);
        if ($currency === null) throw new ApiException('Paymob Transaction response contains an unsupported currency.', null, $data);
        return new self(
            id                 : $data['id'],
            orderId            : $orderId,
            amountCents        : $data['amount_cents'],
            currency           : $currency,
            success            : $data['success'],
            pending            : $data['pending'],
            isCaptured         : $data['is_captured'],
            isRefunded         : $data['is_refunded'],
            isVoided           : $data['is_voided'],
            is3DSecure          : $data['is_3d_secure'],
            isStandalonePayment: $data['is_standalone_payment'],
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
    public function jsonSerialize(): array { return get_object_vars($this); }
}
