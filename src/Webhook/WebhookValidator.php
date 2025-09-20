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

namespace Maatify\Paymob\Webhook;

use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\DTO\Webhook\WebhookPayloadDTO;
use Maatify\Paymob\Exception\WebhookException;

final readonly class WebhookValidator
{
    public function __construct(
        private PaymobConfigDTO $config
    ) {}

    /**
     * Verify webhook payload against HMAC.
     *
     * @throws WebhookException
     */
    public function validate(array $payload): WebhookPayloadDTO
    {
        if (empty($this->config->hmacSecret)) {
            throw new WebhookException("HMAC secret not configured");
        }

        if (!isset($payload['hmac'])) {
            throw new WebhookException("Missing HMAC in webhook payload");
        }

        $computed = $this->computeHmac($payload['obj'] ?? []);
        $provided = $payload['hmac'];

        if (!hash_equals($computed, $provided)) {
            throw new WebhookException("Invalid HMAC signature");
        }

        return WebhookPayloadDTO::fromArray($payload);
    }

    private function computeHmac(array $obj): string
    {
        // ترتيب الحقول بيكون critical عند Paymob
        $keys = [
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
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ];

        $string = '';
        foreach ($keys as $key) {
            $parts = explode('.', $key);
            $value = $obj;
            foreach ($parts as $part) {
                $value = $value[$part] ?? '';
            }
            $string .= (string)$value;
        }

        return hash_hmac('sha512', $string, $this->config->hmacSecret);
    }
}