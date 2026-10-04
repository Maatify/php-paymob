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

namespace Maatify\Paymob\Payment\DTO;

use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\ApiException;

final readonly class WalletPaymentResponseDTO implements \JsonSerializable
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
        foreach (['id', 'amount_cents', 'currency', 'success', 'pending', 'created_at', 'order'] as $field) {
            if (!array_key_exists($field, $response)) throw new ApiException("Paymob Wallet response is missing required field: {$field}.", null, $response);
        }
        if (!is_int($response['id']) || $response['id'] <= 0 || !is_int($response['amount_cents']) || $response['amount_cents'] <= 0
            || !is_string($response['currency']) || !is_bool($response['success']) || !is_bool($response['pending'])
            || !is_string($response['created_at']) || !is_array($response['order']) || !isset($response['order']['id'])
            || !is_int($response['order']['id']) || $response['order']['id'] <= 0) {
            throw new ApiException('Paymob Wallet response contains invalid required fields.', null, $response);
        }
        $currency = CurrencyEnum::tryFrom($response['currency']);
        if ($currency === null) throw new ApiException('Paymob Wallet response contains an unsupported currency.', null, $response);
        return new self(
            transactionId: (int)$response['id'],
            orderId      : $response['order']['id'],
            amountCents  : $response['amount_cents'],
            currency     : $currency,
            success      : $response['success'],
            pending      : $response['pending'],
            createdAt    : $response['created_at'],
            updatedAt    : $response['updated_at'] ?? null,
            redirectUrl  : $response['redirect_url'] ?? null,
            statusMessage: $response['data']['message'] ?? null
        );
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}
