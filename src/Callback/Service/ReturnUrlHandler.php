<?php

declare(strict_types=1);

namespace Maatify\Paymob\Callback\Service;

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Callback\DTO\ReturnUrlResponseDTO;
use Maatify\Paymob\Exception\ReturnUrlException;

/**
 * Validates Paymob's customer-facing Transaction Response redirect and returns its result.
 *
 * The validated Transaction Processed callback remains the authoritative source for
 * server-side payment and order state changes. The returned data_message is advisory:
 * Paymob does not include it among the 20 HMAC-signed Transaction Response values.
 */
final readonly class ReturnUrlHandler
{
    public function __construct(
        private PaymobConfig $config
    ) {
    }

    /**
     * Parse a PHP-normalized GET query after verifying all signed values and the HMAC.
     *
     * PHP changes dotted wire names to underscores in $_GET: source_data.pan becomes
     * source_data_pan, and data.message becomes data_message. The signed order value
     * may arrive as order or order_id; when both exist they must be identical.
     * All other signed values and hmac must be strings; the order value and hmac
     * must also be non-empty. Success and pending must be exactly "true" or "false".
     * Invalid input or an empty configured secret fails closed. The message is
     * unsigned and must not drive payment or order state decisions.
     *
     * @throws ReturnUrlException When the query or HMAC configuration is invalid.
     */
    public function parse(array $query): ReturnUrlResponseDTO
    {
        if ($this->config->hmacSecret === '') {
            throw new ReturnUrlException('Return URL HMAC secret must not be empty');
        }

        $providedHmac = $query['hmac'] ?? null;
        if (!is_string($providedHmac) || $providedHmac === '') {
            throw new ReturnUrlException('Return URL HMAC must be a non-empty string');
        }

        foreach (['success', 'pending'] as $field) {
            if (!isset($query[$field]) || !in_array($query[$field], ['true', 'false'], true)) {
                throw new ReturnUrlException("Invalid {$field} query value for return URL");
            }
        }

        $hasOrder = array_key_exists('order', $query);
        $hasOrderId = array_key_exists('order_id', $query);
        if (!$hasOrder && !$hasOrderId) {
            throw new ReturnUrlException('Missing order query value for return URL');
        }

        if ($hasOrder && (!is_string($query['order']) || $query['order'] === '')) {
            throw new ReturnUrlException('Invalid order query value for return URL');
        }

        if ($hasOrderId && (!is_string($query['order_id']) || $query['order_id'] === '')) {
            throw new ReturnUrlException('Invalid order_id query value for return URL');
        }

        if ($hasOrder && $hasOrderId && $query['order'] !== $query['order_id']) {
            throw new ReturnUrlException('Conflicting order query values for return URL');
        }

        $canonicalOrder = $hasOrder ? $query['order'] : $query['order_id'];
        if (!$this->validateHmac($query, $providedHmac, $canonicalOrder)) {
            throw new ReturnUrlException('Invalid HMAC signature for return URL');
        }

        $message = $query['data_message'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new ReturnUrlException('Invalid data_message query value for return URL');
        }

        return new ReturnUrlResponseDTO(
            transactionId: (int) $query['id'],
            orderId: (int) $canonicalOrder,
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
     * The order position uses the validated value from order or order_id, without
     * signing the key name. Source data uses PHP's underscored keys, not dotted
     * array keys. data_message is outside these 20 signed values. Missing or
     * non-string signed fields cannot contribute an implicit empty value.
     *
     * @throws ReturnUrlException When a signed field is missing or is not a string.
     */
    private function validateHmac(array $query, string $providedHmac, string $canonicalOrder): bool
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
            if ($field === 'order') {
                $concatenated .= $canonicalOrder;

                continue;
            }

            if (!array_key_exists($field, $query) || !is_string($query[$field])) {
                throw new ReturnUrlException("Missing or invalid {$field} query value for return URL");
            }

            $concatenated .= $query[$field];
        }

        $calculated = hash_hmac('sha512', $concatenated, $this->config->hmacSecret);

        return hash_equals($calculated, $providedHmac);
    }
}
