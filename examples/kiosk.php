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

use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyRequestDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentRequestDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Service\AuthService;
use Maatify\Paymob\Service\OrderService;
use Maatify\Paymob\Service\PaymentKeyService;
use Maatify\Paymob\Service\KioskPaymentService;
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

    $orderResponse = $orderService->createOrder($orderRequest);
    echo "✅ Order created. ID = {$orderResponse->id}\n";

    // Step 2: Billing data
    $billing = new BillingDataDTO(
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
    $paymentKeyRequest = new PaymentKeyRequestDTO(
        orderId      : $orderResponse->id,
        integrationId: $bootstrap->config->integrationIdKiosk, // 👈 مهم نستخدم Integration ID بتاع الكشك
        amountCents  : $orderResponse->amountCents,
        currency     : $orderResponse->currency,
        billingData  : $billing
    );

    $paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);
    echo "✅ Payment key generated. Token = {$paymentKeyResponse->token}\n";

    // Step 4: Pay via Kiosk
    $kioskRequest = new KioskPaymentRequestDTO(
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
