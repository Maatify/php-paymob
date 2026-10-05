<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-18
 * Time: 17:04
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Callback\DTO;

use Maatify\Paymob\Exception\WebhookException;

final readonly class WebhookPayloadDTO implements \JsonSerializable
{
    public function __construct(
        public int $transactionId,
        public int $orderId,
        public int $amountCents,
        public string $currency,
        public bool $success,
        public bool $pending,
        public ?string $paymentMethod = null,
        public ?string $subType = null,
        public ?string $maskedPan = null,
        public ?string $hmac = null,
        public array $raw = []
    ) {}

    public static function fromArray(array $data): self
    {
        $obj = $data['obj'] ?? null;
        if (!is_array($obj) || !isset($obj['order']) || !is_array($obj['order'])
            || !isset($obj['source_data']) || !is_array($obj['source_data'])) {
            throw new WebhookException('Malformed typed webhook payload');
        }
        foreach (['id', 'amount_cents'] as $field) {
            if (!isset($obj[$field]) || !is_int($obj[$field]) || $obj[$field] <= 0) {
                throw new WebhookException("Invalid typed webhook field: {$field}");
            }
        }
        if (!isset($obj['order']['id']) || !is_int($obj['order']['id']) || $obj['order']['id'] <= 0
            || !isset($obj['currency']) || !is_string($obj['currency']) || $obj['currency'] === '') {
            throw new WebhookException('Invalid typed webhook order or currency');
        }
        foreach (['success', 'pending'] as $field) {
            if (!array_key_exists($field, $obj) || !is_bool($obj[$field])) {
                throw new WebhookException("Invalid typed webhook field: {$field}");
            }
        }
        foreach (['type', 'sub_type', 'pan'] as $field) {
            if (!isset($obj['source_data'][$field]) || !is_string($obj['source_data'][$field])) {
                throw new WebhookException("Invalid typed webhook source_data field: {$field}");
            }
        }
        $hmac = $data['hmac'] ?? null;
        if ($hmac !== null && !is_string($hmac)) {
            throw new WebhookException('Invalid typed webhook field: hmac');
        }

        return new self(
            transactionId: $obj['id'],
            orderId      : $obj['order']['id'],
            amountCents  : $obj['amount_cents'],
            currency     : $obj['currency'],
            success      : $obj['success'],
            pending      : $obj['pending'],
            paymentMethod: $obj['source_data']['type'],
            subType      : $obj['source_data']['sub_type'],
            maskedPan    : $obj['source_data']['pan'],
            hmac         : $hmac,
            raw          : $data
        );
    }
    /** Returns the typed callback snapshot, including its typed HMAC, without the raw payload. */
    public function jsonSerialize(): array
    {
        return [
            'transactionId' => $this->transactionId,
            'orderId' => $this->orderId,
            'amountCents' => $this->amountCents,
            'currency' => $this->currency,
            'success' => $this->success,
            'pending' => $this->pending,
            'paymentMethod' => $this->paymentMethod,
            'subType' => $this->subType,
            'maskedPan' => $this->maskedPan,
            'hmac' => $this->hmac,
        ];
    }
}
