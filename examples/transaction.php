<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 20:30
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

/** @var PaymobExampleBootstrap $bootstrap */

use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\OrderException;
use Maatify\Paymob\Exception\TransactionException;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Transaction\Service\TransactionService;

$bootstrap = require __DIR__ . '/bootstrap.php';

// Auth + Services
$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo, new SystemClock(new \DateTimeZone('UTC')));

$transactionService = new TransactionService($bootstrap->client, $authService);

//$transactionId = 386897265;
//$transactionId = "ORD-68c9a5e549a1d";
//$transactionId = 344212847;

try {
    // Use a transaction ID returned by an earlier payment.
    $transactionId = 344212847;

    $transaction = $transactionService->getTransaction($transactionId);

    echo "✅ Transaction details:\n";
    echo "ID              : {$transaction->id}\n";
    echo "Order ID        : {$transaction->orderId}\n";
    echo "Amount (cents)  : {$transaction->amountCents}\n";
    echo "Currency        : {$transaction->currency->value}\n";
    echo "Success         : " . ($transaction->success ? 'true' : 'false') . "\n";
    echo "Pending         : " . ($transaction->pending ? 'true' : 'false') . "\n";
    echo "Status          : {$transaction->paymentStatus}\n";
    echo "Created At      : {$transaction->createdAt}\n";
    echo "Updated At      : {$transaction->updatedAt}\n";

} catch (AuthException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ Error fetching transaction: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}

