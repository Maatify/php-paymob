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

use Maatify\Paymob\Payment\DTO\WalletFlowResultDTO;
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
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
    $items = [
        new OrderItem('T-shirt', 5000, 1),
        new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

    $orderRequest = new CreateOrderCommand(
        amountCents    : 15000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    // Step 2: Billing data (Wallet accepts full data or NA in some fields)
    $billing = new BillingData(
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
