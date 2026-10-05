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
        foreach (['order', 'source_data', 'data', 'payment_key_claims'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && !is_array($data[$field])) {
                throw new ApiException("Paymob Transaction response has an invalid {$field} object.", null, $data);
            }
        }
        $order = $data['order'] ?? [];
        $source = $data['source_data'] ?? [];
        $details = $data['data'] ?? [];
        $claims = $data['payment_key_claims'] ?? [];
        if (array_key_exists('merchant', $order) && $order['merchant'] !== null && !is_array($order['merchant'])) {
            throw new ApiException('Paymob Transaction response has an invalid order.merchant object.', null, $data);
        }
        $merchant = $order['merchant'] ?? [];
        if (array_key_exists('billing_data', $claims) && $claims['billing_data'] !== null && !is_array($claims['billing_data'])) {
            throw new ApiException('Paymob Transaction response has invalid billing_data.', null, $data);
        }
        $optionalTypes = [
            'integration_id' => [$data['integration_id'] ?? null, 'int'],
            'profile_id' => [$data['profile_id'] ?? null, 'int'],
            'order.merchant.id' => [$merchant['id'] ?? null, 'int'],
            'merchant_order_id' => [$order['merchant_order_id'] ?? null, 'string'],
            'order.payment_status' => [$order['payment_status'] ?? null, 'string'],
            'payment_status' => [$data['payment_status'] ?? null, 'string'],
            'data.message' => [$details['message'] ?? null, 'string'],
            'created_at' => [$data['created_at'] ?? null, 'string'],
            'updated_at' => [$data['updated_at'] ?? null, 'string'],
            'source_data.type' => [$source['type'] ?? null, 'string'],
            'source_data.sub_type' => [$source['sub_type'] ?? null, 'string'],
            'redirect_url' => [$data['redirect_url'] ?? null, 'string'],
            'refunded_amount_cents' => [$data['refunded_amount_cents'] ?? null, 'int'],
            'captured_amount' => [$data['captured_amount'] ?? null, 'int'],
            'order.items' => [$order['items'] ?? null, 'array'],
            'payment_key_claims.billing_data' => [$claims['billing_data'] ?? null, 'array'],
            'order_id' => [$data['order_id'] ?? null, 'int'],
        ];
        foreach ($optionalTypes as $field => [$value, $type]) {
            if ($value !== null && get_debug_type($value) !== $type) {
                throw new ApiException("Paymob Transaction response has an invalid optional field: {$field}.", null, $data);
            }
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
    /** Returns the typed transaction snapshot without its retained provider response. */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->orderId,
            'amountCents' => $this->amountCents,
            'currency' => $this->currency,
            'success' => $this->success,
            'pending' => $this->pending,
            'isCaptured' => $this->isCaptured,
            'isRefunded' => $this->isRefunded,
            'isVoided' => $this->isVoided,
            'is3DSecure' => $this->is3DSecure,
            'isStandalonePayment' => $this->isStandalonePayment,
            'integrationId' => $this->integrationId,
            'profileId' => $this->profileId,
            'merchantId' => $this->merchantId,
            'merchantOrderId' => $this->merchantOrderId,
            'paymentStatus' => $this->paymentStatus,
            'statusMessage' => $this->statusMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'sourceType' => $this->sourceType,
            'sourceSubType' => $this->sourceSubType,
            'redirectUrl' => $this->redirectUrl,
            'refundedAmountCents' => $this->refundedAmountCents,
            'capturedAmountCents' => $this->capturedAmountCents,
            'items' => $this->items,
            'billingData' => $this->billingData,
        ];
    }
}
