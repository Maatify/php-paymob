<?php

declare(strict_types=1);

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\DTO\Webhook\ReturnUrlResponseDTO;
use RuntimeException;

/**
 * Validates Paymob's customer-facing Transaction Response redirect and returns its result.
 *
 * The validated Transaction Processed callback remains the authoritative source for
 * server-side payment and order state changes.
 */
final readonly class ReturnUrlHandler
{
    public function __construct(
        private PaymobConfigDTO $config
    ) {
    }

    /**
     * Parse a PHP-normalized GET query after verifying all signed values and the HMAC.
     *
     * PHP changes dotted wire names to underscores in $_GET: source_data.pan becomes
     * source_data_pan, and data.message becomes data_message. All 20 signed values,
     * plus hmac, must be present as strings; success and pending must be exactly
     * "true" or "false". Invalid input or an empty configured secret fails closed.
     *
     * @throws RuntimeException When the query or HMAC configuration is invalid.
     */
    public function parse(array $query): ReturnUrlResponseDTO
    {
        if ($this->config->hmacSecret === '') {
            throw new RuntimeException('Return URL HMAC secret must not be empty');
        }

        $providedHmac = $query['hmac'] ?? null;
        if (!is_string($providedHmac) || $providedHmac === '') {
            throw new RuntimeException('Return URL HMAC must be a non-empty string');
        }

        foreach (['success', 'pending'] as $field) {
            if (!isset($query[$field]) || !in_array($query[$field], ['true', 'false'], true)) {
                throw new RuntimeException("Invalid {$field} query value for return URL");
            }
        }

        if (!$this->validateHmac($query, $providedHmac)) {
            throw new RuntimeException('Invalid HMAC signature for return URL');
        }

        $message = $query['data_message'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new RuntimeException('Invalid data_message query value for return URL');
        }

        return new ReturnUrlResponseDTO(
            transactionId: (int) $query['id'],
            orderId: (int) $query['order'],
            amountCents: (int) $query['amount_cents'],
            currency: $query['currency'],
            success: $query['success'] === 'true',
            pending: $query['pending'] === 'true',
            message: $message,
            hmac: $providedHmac,
        );
    }

    /**
     * Concatenate the 20 required PHP-normalized GET strings in Paymob's HMAC order.
     *
     * Response redirects sign the flat order value and the underscored source_data
     * keys produced by PHP, rather than order.id or dotted PHP array keys.
     * Missing or non-string fields cannot contribute an implicit empty value.
     *
     * @throws RuntimeException When a signed field is missing or is not a string.
     */
    private function validateHmac(array $query, string $providedHmac): bool
    {
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
            if (!array_key_exists($field, $query) || !is_string($query[$field])) {
                throw new RuntimeException("Missing or invalid {$field} query value for return URL");
            }

            $concatenated .= $query[$field];
        }

        $calculated = hash_hmac('sha512', $concatenated, $this->config->hmacSecret);

        return hash_equals($calculated, $providedHmac);
    }
}
