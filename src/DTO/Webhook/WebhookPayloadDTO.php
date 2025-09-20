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

namespace Maatify\Paymob\DTO\Webhook;

final readonly class WebhookPayloadDTO
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
}
