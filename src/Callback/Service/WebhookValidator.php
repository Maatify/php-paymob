<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-18
 * Time: 17:06
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Callback\Service;

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Callback\DTO\WebhookPayloadDTO;
use Maatify\Paymob\Exception\WebhookException;

/**
 * Verifies normalized Transaction Processed callback payloads with Paymob's transaction HMAC.
 *
 * The caller combines the JSON body with the query-string HMAC in one array before validation.
 */
final readonly class WebhookValidator
{
    public function __construct(
        private PaymobConfig $config
    ) {}

    /**
     * Verifies a normalized Transaction Processed callback and returns its parsed payload.
     *
     * The array must contain `type`, a transaction `obj`, and a non-empty string `hmac`. Every
     * signed transaction field must be present with a string, integer, or boolean value. Invalid
     * or incomplete signed input fails closed with WebhookException.
     *
     * @throws WebhookException When the secret, callback shape, signed fields, or HMAC is invalid.
     */
    public function validate(array $payload): WebhookPayloadDTO
    {
        if ($this->config->hmacSecret === '') {
            throw new WebhookException('HMAC secret not configured');
        }

        if (($payload['type'] ?? null) !== 'TRANSACTION') {
            throw new WebhookException('Unsupported webhook type');
        }

        if (!isset($payload['obj']) || !is_array($payload['obj'])) {
            throw new WebhookException('Missing or malformed transaction object');
        }

        if (!isset($payload['hmac']) || !is_string($payload['hmac']) || $payload['hmac'] === '') {
            throw new WebhookException('Missing or malformed HMAC in webhook payload');
        }

        $computed = $this->computeHmac($payload['obj']);

        if (!hash_equals($computed, $payload['hmac'])) {
            throw new WebhookException('Invalid HMAC signature');
        }

        $this->validateTypedFields($payload['obj']);

        return WebhookPayloadDTO::fromArray($payload);
    }

    private function validateTypedFields(array $obj): void
    {
        foreach (['id', 'amount_cents'] as $field) {
            if (!isset($obj[$field]) || !is_int($obj[$field]) || $obj[$field] <= 0) {
                throw new WebhookException("Invalid typed webhook field: {$field}");
            }
        }
        if (!isset($obj['order']) || !is_array($obj['order']) || !isset($obj['order']['id'])
            || !is_int($obj['order']['id']) || $obj['order']['id'] <= 0) {
            throw new WebhookException('Invalid typed webhook field: order.id');
        }
        if (!isset($obj['currency']) || !is_string($obj['currency']) || $obj['currency'] === '') {
            throw new WebhookException('Invalid typed webhook field: currency');
        }
        foreach (['success', 'pending'] as $field) {
            if (!array_key_exists($field, $obj) || !is_bool($obj[$field])) {
                throw new WebhookException("Invalid typed webhook field: {$field}");
            }
        }
        if (!isset($obj['source_data']) || !is_array($obj['source_data'])) {
            throw new WebhookException('Invalid typed webhook field: source_data');
        }
        foreach (['type', 'sub_type', 'pan'] as $field) {
            if (!isset($obj['source_data'][$field]) || !is_string($obj['source_data'][$field])) {
                throw new WebhookException("Invalid typed webhook field: source_data.{$field}");
            }
        }
    }

    /**
     * Builds Paymob's ordered canonical transaction string and returns its SHA-512 HMAC.
     *
     * Boolean leaves use the literal strings `true` and `false`; all signed paths are required
     * and unsupported leaf shapes are rejected instead of being replaced with guessed values.
     * The transaction object is passed directly, so its transaction identifier path is `id`.
     *
     * @throws WebhookException When a signed path is missing or its leaf is unsupported.
     */
    private function computeHmac(array $obj): string
    {
        $paths = [
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
            'order.id',
            'owner',
            'pending',
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ];

        $canonical = '';
        foreach ($paths as $path) {
            $value = $obj;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    throw new WebhookException("Missing signed webhook field: {$path}");
                }

                $value = $value[$part];
            }

            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new WebhookException("Unsupported signed webhook field: {$path}");
            }

            $canonical .= is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }

        return hash_hmac('sha512', $canonical, $this->config->hmacSecret);
    }
}
