<?php
/**
 * Created by Maatify.dev
 * User: Mohamed Abdulalim
 * Date: 2025-09-16
 * Time: 19:15
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyRequestDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyResponseDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Service\AuthService;
use Maatify\Paymob\Service\OrderService;
use Maatify\Paymob\Service\PaymentKeyService;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Exception\{AuthException, ApiException, NetworkException, OrderException, TransactionException};

// Auth + Services
$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo);
$orderService = new OrderService($bootstrap->client, $bootstrap->config, $authService);
$paymentKeyService = new PaymentKeyService($bootstrap->client, $authService);

// Step 1: Create order
$items = new OrderItemsDTO(
    new OrderItemDTO('T-shirt', 5000, 1),
    new OrderItemDTO('Shoes', 10000, 1, 'Running Shoes')
);

$orderRequest = new OrderRequestDTO(
    amountCents: 15000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

try {
    $orderResponse = $orderService->createOrder($orderRequest);

    echo "✅ Order created successfully. ID = {$orderResponse->id}\n";

    // Step 2: Billing data
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

    // Step 3: Payment key request
    $paymentKeyRequest = new PaymentKeyRequestDTO(
        orderId      : $orderResponse->id, // 👈 dynamic orderId from Paymob
        integrationId: $bootstrap->config->integrationIdCard,
        amountCents  : $orderResponse->amountCents,
        currency     : $orderResponse->currency,
        billingData  : $billing
    );

    /** @var PaymentKeyResponseDTO $paymentKeyResponse */
    $paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);

    echo "✅ Payment key generated successfully:\n";
    echo "Token: {$paymentKeyResponse->token}\n";
    echo "Order ID: {$paymentKeyResponse->orderId}\n";


} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
