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
        $obj = $data['obj'] ?? [];

        return new self(
            transactionId: (int)($obj['id'] ?? 0),
            orderId      : (int)($obj['order']['id'] ?? 0),
            amountCents  : (int)($obj['amount_cents'] ?? 0),
            currency     : $obj['currency'] ?? 'EGP',
            success      : (bool)($obj['success'] ?? false),
            pending      : (bool)($obj['pending'] ?? false),
            paymentMethod: $obj['source_data']['type'] ?? null,
            subType      : $obj['source_data']['sub_type'] ?? null,
            maskedPan    : $obj['source_data']['pan'] ?? null,
            hmac         : $data['hmac'] ?? null,
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
