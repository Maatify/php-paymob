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
use Maatify\Paymob\Exception\ApiException;

final readonly class KioskPaymentResponseDTO implements \JsonSerializable
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
        foreach (['id', 'amount_cents', 'pending', 'success', 'currency', 'order'] as $field) {
            if (!array_key_exists($field, $response)) throw new ApiException("Paymob Kiosk response is missing required field: {$field}.", null, $response);
        }
        if (!is_int($response['id']) || $response['id'] <= 0 || !is_int($response['amount_cents']) || $response['amount_cents'] <= 0
            || !is_bool($response['pending']) || !is_bool($response['success']) || !is_string($response['currency'])
            || !is_array($response['order']) || !isset($response['order']['id']) || !is_int($response['order']['id']) || $response['order']['id'] <= 0
            || !isset($response['order']['merchant_order_id']) || !is_string($response['order']['merchant_order_id'])) {
            throw new ApiException('Paymob Kiosk response contains invalid required fields.', null, $response);
        }
        $currency = CurrencyEnum::tryFrom($response['currency']);
        if ($currency === null) throw new ApiException('Paymob Kiosk response contains an unsupported currency.', null, $response);
        $data = $response['data'] ?? null;
        if ($data !== null && !is_array($data)) throw new ApiException('Paymob Kiosk response has invalid data.', null, $response);
        foreach ([
            'data.bill_reference' => [$data['bill_reference'] ?? null, 'int'],
            'data.message' => [$data['message'] ?? null, 'string'],
            'order.payment_status' => [$response['order']['payment_status'] ?? null, 'string'],
            'created_at' => [$response['created_at'] ?? null, 'string'],
            'updated_at' => [$response['updated_at'] ?? null, 'string'],
        ] as $field => [$value, $type]) {
            if ($value !== null && get_debug_type($value) !== $type) throw new ApiException("Paymob Kiosk response has an invalid optional field: {$field}.", null, $response);
        }
        return new self(
            transactionId  : $response['id'],
            orderId        : $response['order']['id'],
            merchantOrderId: $response['order']['merchant_order_id'],
            amountCents    : $response['amount_cents'],
            currency       : $currency,
            pending        : $response['pending'],
            success        : $response['success'],
            billReference  : $response['data']['bill_reference'] ?? null,
            statusMessage  : $response['data']['message'] ?? null,
            paymentStatus  : $response['order']['payment_status'] ?? null,
            createdAt      : $response['created_at'] ?? null,
            updatedAt      : $response['updated_at'] ?? null,
            row            : $response
        );
    }
    /** Returns typed Kiosk result fields without the retained provider response row. */
    public function jsonSerialize(): array
    {
        return [
            'transactionId' => $this->transactionId,
            'orderId' => $this->orderId,
            'merchantOrderId' => $this->merchantOrderId,
            'amountCents' => $this->amountCents,
            'currency' => $this->currency,
            'pending' => $this->pending,
            'success' => $this->success,
            'billReference' => $this->billReference,
            'statusMessage' => $this->statusMessage,
            'paymentStatus' => $this->paymentStatus,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
