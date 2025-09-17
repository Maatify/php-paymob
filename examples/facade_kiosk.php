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
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\{ApiException, AuthException, NetworkException, OrderException, TransactionException};
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Repository\InMemoryTokenRepository;

$repo = new InMemoryTokenRepository();
// Facade init
$facade = new PaymobFacade(
    config : $bootstrap->config,
    http   : $bootstrap->client,
    repo   : $repo,
    logger : $bootstrap->logger,
);
try {
    // 1. Order items
    $items = new OrderItemsDTO(
        new OrderItemDTO('T-shirt', 5000, 1),
        new OrderItemDTO('Shoes', 10000, 1, 'Running Shoes')
    );

    // 2. Order request
    $orderRequest = new OrderRequestDTO(
        amountCents    : 15000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    // 3. Billing info
    $billing = new BillingDataDTO(
        firstName  : 'Mohamed',
        lastName   : 'Abdulalim',
        email      : 'mohamed@example.com',
        phoneNumber: '201000000000',
        country    : 'NA',  // kiosk allows NA
        city       : 'NA',
        street     : 'NA',
        building   : 'NA',
        floor      : 'NA',
        apartment  : 'NA',
        postalCode : 'NA',
        state      : 'NA'
    );

    // 4. Full flow in one call
    $result = $facade->payViaKiosk($orderRequest, $billing);

    echo "✅ Kiosk Flow Completed:\n";
    echo "Order ID        : {$result->order->id}\n";
    echo "Merchant OrderId: {$result->order->merchantOrderId}\n";
    echo "Transaction ID  : {$result->kiosk->transactionId}\n";
    echo "Bill Reference  : {$result->kiosk->billReference}\n";
    echo "Currency        : {$result->kiosk->currency->value}\n";
    echo "Payment Status  : {$result->kiosk->paymentStatus}\n";
    echo "Is Pending      : " . ($result->kiosk->pending ? 'Yes' : 'No') . "\n";
    echo "Message         : {$result->kiosk->statusMessage}\n";

} catch (AuthException|OrderException|TransactionException|NetworkException|ApiException $e) {
    echo "❌ SDK error: " . $e->getMessage() . PHP_EOL;
    print_r($e->getResponse());
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}