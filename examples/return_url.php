<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-20
 * Time: 15:20
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

use Maatify\Paymob\Service\ReturnUrlHandler;

$handler = new ReturnUrlHandler($bootstrap->config);

try {
    // PHP normalizes dotted query names in $_GET:
    // source_data.type becomes source_data_type; data.message becomes data_message.
    $response = $handler->parse($_GET);

    // Use this Transaction Response Callback for customer-facing result/redirect handling.
    // Update order/payment state from the validated Transaction Processed Callback instead.
    echo "✅ Transaction: {$response->transactionId}\n";
    echo "✅ Order: {$response->orderId}\n";
    echo "✅ Status: " . ($response->success ? 'Success' : ($response->pending ? 'Pending' : 'Failed')) . "\n";
    echo "✅ Amount: " . ($response->amountCents / 100) . " {$response->currency}\n";
} catch (Throwable $e) {
    echo "❌ Invalid return URL: " . $e->getMessage();
}
