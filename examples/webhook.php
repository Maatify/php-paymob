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

use Maatify\Paymob\Webhook\WebhookValidator;
use Maatify\Paymob\Exception\WebhookException;

$validator = new WebhookValidator($bootstrap->config);

try {
    $payload = $validator->validate(
        json_decode(file_get_contents('php://input'), true)
    );

    echo "✅ Webhook valid for Transaction #{$payload->transactionId}\n";
} catch (WebhookException $e) {
    echo "❌ Invalid webhook: " . $e->getMessage();
}