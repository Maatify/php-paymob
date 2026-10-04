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

use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Exception\{AuthException, ApiException, NetworkException, OrderException, TransactionException};

// Auth + Services
$repo = new InMemoryTokenRepository();
$authService = new AuthService($bootstrap->client, $bootstrap->config, $repo, new SystemClock(new \DateTimeZone('UTC')));
$orderService = new OrderService($bootstrap->client, $authService);
$paymentKeyService = new PaymentKeyService($bootstrap->client, $authService);

// Step 1: Create order
$items = [
    new OrderItem('T-shirt', 5000, 1),
    new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

$orderRequest = new CreateOrderCommand(
    amountCents: 15000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

try {
    $orderResponse = $orderService->createOrder($orderRequest);

    echo "✅ Order created successfully. ID = {$orderResponse->id}\n";

    // Step 2: Billing data
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

    // Step 3: Payment key request
    $paymentKeyRequest = new GeneratePaymentKeyCommand(
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
