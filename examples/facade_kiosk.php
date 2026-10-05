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
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\{ApiException, AuthException, NetworkException, OrderException, PaymobExceptionInterface, TransactionException};
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;

$repo = new InMemoryTokenRepository();
// Facade init
$facade = new PaymobFacade(
    config : $bootstrap->config,
    http   : $bootstrap->client,
    repo   : $repo,
    clock  : $bootstrap->clock,
    logger : $bootstrap->logger,
);
try {
    // 1. Order items
    $items = [
        new OrderItem('T-shirt', 5000, 1),
        new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

    // 2. Order request
    $orderRequest = new CreateOrderCommand(
        amountCents    : 15000,
        currency       : CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
        items          : $items
    );

    // 3. Billing info
    $billing = new BillingData(
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
} catch (PaymobExceptionInterface $e) {
    echo "❌ Paymob SDK error: " . $e->getMessage() . PHP_EOL;
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage();
}
