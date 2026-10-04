<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-18
 * Time: 17:12
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

use Maatify\Paymob\Callback\Service\WebhookValidator;
use Maatify\Paymob\Exception\WebhookException;

$validator = new WebhookValidator($bootstrap->config);

try {
    $body = file_get_contents('php://input');
    if ($body === false) {
        throw new RuntimeException('Unable to read webhook request body');
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Webhook JSON body must be an object');
    }

    // The caller combines the JSON body with the HMAC query parameter.
    $payload['hmac'] = $_GET['hmac'] ?? null;
    $payload = $validator->validate($payload);

    echo "✅ Webhook valid for Transaction #{$payload->transactionId}\n";
} catch (WebhookException | JsonException | RuntimeException $e) {
    echo "❌ Invalid webhook: " . $e->getMessage();
}
