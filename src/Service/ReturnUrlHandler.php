<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-20
 * Time: 15:15
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\Webhook\ReturnUrlResponseDTO;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use RuntimeException;

final readonly class ReturnUrlHandler
{
    public function __construct(
        private PaymobConfigDTO $config
    ) {}

    public function parse(array $query): ReturnUrlResponseDTO
    {
        $dto = new ReturnUrlResponseDTO(
            transactionId: (int)($query['id'] ?? 0),
            orderId      : (int)($query['order'] ?? 0),
            amountCents  : (int)($query['amount_cents'] ?? 0),
            currency     : $query['currency'] ?? 'EGP',
            success      : filter_var($query['success'] ?? false, FILTER_VALIDATE_BOOLEAN),
            pending      : filter_var($query['pending'] ?? false, FILTER_VALIDATE_BOOLEAN),
            message      : $query['message'] ?? null,
            hmac         : $query['hmac'] ?? null,
        );

        if (!$this->validateHmac($query, $dto->hmac)) {
            throw new RuntimeException("Invalid HMAC signature for return_url");
        }

        return $dto;
    }

    private function validateHmac(array $query, ?string $providedHmac): bool
    {
        if (!$providedHmac) {
            return false;
        }

        // نفس ترتيب Paymob بالظبط (id, order, amount_cents, success, ...)
        $fields = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order',
            'owner',
            'pending',
            'source_data_pan',
            'source_data_sub_type',
            'source_data_type',
            'success',
        ];

        $concatenated = '';
        foreach ($fields as $field) {
            $concatenated .= $query[$field] ?? '';
        }

        $calculated = hash_hmac('sha512', $concatenated, $this->config->hmacSecret);
        return hash_equals($calculated, $providedHmac);
    }
}

