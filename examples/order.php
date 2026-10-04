<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:46
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\OrderException;

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

try {
    // Auth service
    $authService = new AuthService(
        http: $bootstrap->client,
        config: $bootstrap->config,
        repo: new InMemoryTokenRepository(), // in-memory cache for this example
        clock: $bootstrap->clock,
    );

    // Order service
    $orderService = new OrderService(
        http: $bootstrap->client,
        authService: $authService
    );

    // Items
    $items = [
        new OrderItem('T-shirt', 5000, 1),
        new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

    // Order request
    $request = new CreateOrderCommand(
        amountCents: 15000,
        currency: CurrencyEnum::EGP,
        merchantOrderId: uniqid(),
        items: $items
    );

    // Call Paymob
    $response = $orderService->createOrder($request);

    // Print result
    echo "✅ Order created successfully:\n";
    print_r($response);

} catch (OrderException $e) {
    echo "❌ Order error: " . $e->getMessage() . PHP_EOL;
    if ($resp = $e->getResponse()) {
        print_r($resp);
    }
} catch (Throwable $e) {
    echo "❌ Unexpected error: " . $e->getMessage() . PHP_EOL;
}