<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 17:43
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Payment;

use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class WalletPaymentResponseDTO
{
    public function __construct(
        public int $transactionId,
        public int $orderId,
        public int $amountCents,
        public CurrencyEnum $currency,
        public bool $success,
        public bool $pending,
        public string $createdAt,
        public ?string $updatedAt = null,
        public ?string $redirectUrl = null,
        public ?string $statusMessage = null
    )
    {
    }

    public static function fromArray(array $response): self
    {
        return new self(
            transactionId: (int)$response['id'],
            orderId      : (int)$response['order']['id'],
            amountCents  : (int)$response['amount_cents'],
            currency     : CurrencyEnum::from($response['currency']),
            success      : (bool)$response['success'],
            pending      : (bool)$response['pending'],
            createdAt    : $response['created_at'] ?? '',
            updatedAt    : $response['updated_at'] ?? null,
            redirectUrl  : $response['redirect_url'] ?? null,
            statusMessage: $response['data']['message'] ?? null
        );
    }
}