<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 17:45
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);


/**
 * Example: Wallet Payment
 * Created by Maatify.dev
 */

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\WalletPaymentService;
use Maatify\Paymob\Exception\{
    AuthException,
    ApiException,
    NetworkException,
    OrderException,
    TransactionException
};

$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo, new SystemClock());
$orderService = new OrderService($bootstrap->client, $bootstrap->config, $authService);
$paymentKeyService = new PaymentKeyService($bootstrap->client, $authService);
$walletService = new WalletPaymentService($bootstrap->client, $authService);

try {
    // Step 1: Create order
    $items = [
        new OrderItem('Game Credits', 20000, 1),
        new OrderItem('VIP Pass', 5000, 1)];

    $orderRequest = new CreateOrderCommand(
        amountCents    : 25000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    $orderResponse = $orderService->createOrder($orderRequest);
    echo "✅ Order created. ID = {$orderResponse->id}\n";

    // Step 2: Billing Data
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

    // Step 3: Generate Payment Key (Wallet Integration ID)
    $paymentKeyRequest = new GeneratePaymentKeyCommand(
        orderId      : $orderResponse->id,
        integrationId: $bootstrap->config->integrationIdWallet,
        amountCents  : $orderResponse->amountCents,
        currency     : $orderResponse->currency,
        billingData  : $billing
    );

    $paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);
    echo "✅ Payment key generated. Token = {$paymentKeyResponse->token}\n";

    // Step 4: Pay via Wallet (customer phone required)
    $walletRequest = new InitiateWalletPaymentCommand(
        paymentToken: $paymentKeyResponse->token,
        phoneNumber : '01095556063' // customer’s wallet phone
    );

    $walletResponse = $walletService->pay($walletRequest);

    echo "✅ Wallet Payment initiated successfully:\n";
    echo "Transaction ID : {$walletResponse->transactionId}\n";
    echo "Order ID       : {$walletResponse->orderId}\n";
    echo "Currency       : {$walletResponse->currency->value}\n";
    echo "Success        : " . ($walletResponse->success ? 'true' : 'false') . "\n";
    echo "Pending        : " . ($walletResponse->pending ? 'true' : 'false') . "\n";
    echo "Redirect URL   : {$walletResponse->redirectUrl}\n"; // لو المحفظة محتاجة Redirect/SMS
    echo "Message        : {$walletResponse->statusMessage}\n";
} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
