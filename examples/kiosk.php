<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 21:16
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);


/**
 * Example: Kiosk Payment
 * Created by Maatify.dev
 */


/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Exception\{
    AuthException,
    ApiException,
    NetworkException,
    OrderException,
    TransactionException
};

// Auth + Services
$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo);
$orderService = new OrderService($bootstrap->client, $bootstrap->config, $authService);
$paymentKeyService = new PaymentKeyService($bootstrap->client, $authService);
$kioskService = new KioskPaymentService($bootstrap->client, $authService);

try {
    // Step 1: Create order
    $items = [
        new OrderItem('T-shirt', 5000, 1),
        new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

    $orderRequest = new CreateOrderCommand(
        amountCents    : 15000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    $orderResponse = $orderService->createOrder($orderRequest);
    echo "✅ Order created. ID = {$orderResponse->id}\n";

    // Step 2: Billing data
    $billing = new BillingData(
        firstName  : 'Mohamed',
        lastName   : 'Abdulalim',
        email      : 'mohamed@example.com',
        phoneNumber: '201000000000',
//        country    : 'EG',
//        city       : 'Cairo',
//        street     : 'Nile St.',
//        building   : '12',
//        floor      : '8',
//        apartment  : '803',
//        postalCode : '12345',
//        state      : 'EG'
    );

    // Step 3: Generate Payment Key
    $paymentKeyRequest = new GeneratePaymentKeyCommand(
        orderId      : $orderResponse->id,
        integrationId: $bootstrap->config->integrationIdKiosk, // 👈 مهم نستخدم Integration ID بتاع الكشك
        amountCents  : $orderResponse->amountCents,
        currency     : $orderResponse->currency,
        billingData  : $billing
    );

    $paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);
    echo "✅ Payment key generated. Token = {$paymentKeyResponse->token}\n";

    // Step 4: Pay via Kiosk
    $kioskRequest = new InitiateKioskPaymentCommand(
        paymentToken: $paymentKeyResponse->token
    );

    $kioskResponse = $kioskService->pay($kioskRequest);

    echo "✅ Kiosk Payment initiated successfully:\n";
    echo "Transaction ID    : {$kioskResponse->transactionId}\n";
    echo "Bill Reference    : {$kioskResponse->billReference}\n";
    echo "Order Id          : {$kioskResponse->orderId}\n";
    echo "Currency          : {$kioskResponse->currency->value}\n";
    echo "Create At         : {$kioskResponse->createdAt}\n";
    echo "Update At         : {$kioskResponse->updatedAt}\n";
    echo "Is Success        : {$kioskResponse->success}\n";
    echo "Merchant Order Id : {$kioskResponse->merchantOrderId}\n";
    echo "Payment Status    : {$kioskResponse->paymentStatus}\n";
    echo "Is Pending        : {$kioskResponse->pending}\n";
    echo "Status Message    : {$kioskResponse->statusMessage}\n";
} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
