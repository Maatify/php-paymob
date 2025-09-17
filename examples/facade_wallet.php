<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 18:53
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

use Maatify\Paymob\DTO\WalletFlowResultDTO;
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Exception\{
    AuthException,
    OrderException,
    TransactionException,
    NetworkException,
    ApiException
};

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

$facade = new PaymobFacade(
    config : $bootstrap->config,
    http   : $bootstrap->client,
    repo   : new InMemoryTokenRepository(),
    logger : $bootstrap->logger
);

try {
    // Step 1: Build order request
    $items = new OrderItemsDTO(
        new OrderItemDTO('T-shirt', 5000, 1),
        new OrderItemDTO('Shoes', 10000, 1, 'Running Shoes')
    );

    $orderRequest = new OrderRequestDTO(
        amountCents    : 15000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    // Step 2: Billing data (Wallet accepts full data or NA in some fields)
    $billing = new BillingDataDTO(
        firstName  : 'Mohamed',
        lastName   : 'Abdulalim',
        email      : 'mohamed@example.com',
        phoneNumber: '201000000000',
        country    : 'EG',
        city       : 'Cairo',
        street     : 'Nile St.',
        building   : '12',
        floor      : '8',
        apartment  : '803',
        postalCode : '12345',
        state      : 'EG'
    );

    // Step 3: Pay via Wallet (with mobile wallet number)
    $walletNumber = "01117122122"; // 👈 replace with a real wallet number
    /** @var WalletFlowResultDTO $result*/
    $result = $facade->payViaWallet($orderRequest, $billing, $walletNumber);

    echo "✅ Wallet Payment Flow Result:\n";
    echo "Order ID        : {$result->order->id}\n";
    echo "Transaction ID  : {$result->wallet->transactionId}\n";
    echo "Redirect URL    : {$result->wallet->redirectUrl}\n";
    echo "Currency        : {$result->wallet->currency->value}\n";
    echo "Payment Status  : {$result->wallet->statusMessage}\n";
    echo "Success         : " . ($result->wallet->success ? 'true' : 'false') . "\n";
    echo "Pending         : " . ($result->wallet->pending ? 'true' : 'false') . "\n";

} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
