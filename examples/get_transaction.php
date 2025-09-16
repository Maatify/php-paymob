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
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Service\AuthService;
use Maatify\Paymob\Service\TransactionService;

$bootstrap = require __DIR__ . '/bootstrap.php';

// Auth + Services
$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo);

$transactionService = new TransactionService($bootstrap->client, $authService);

//$transactionId = 386897265;
//$transactionId = "ORD-68c9a5e549a1d";
$transactionId = 344212847;

try {
    $txn = $transactionService->getTransaction($transactionId);

    if ($txn->success) {
        echo "✅ Transaction successful: {$txn->amountCents} {$txn->currency->value}";
    } elseif ($txn->pending) {
        echo "⌛ Transaction pending...";
    } else {
        echo "❌ Transaction failed: {$txn->errorMessage}";
    }
} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
