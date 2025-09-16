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

use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Service\AuthService;
use Maatify\Paymob\Service\OrderService;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\OrderException;

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

try {
    // Auth service
    $authService = new AuthService(
        http: $bootstrap->client,
        config: $bootstrap->config,
        repo: new InMemoryTokenRepository() // ممكن تغيرها بـ DB repo
    );

    // Order service
    $orderService = new OrderService(
        http: $bootstrap->client,
        config: $bootstrap->config,
        authService: $authService
    );

    // Items
    $items = new OrderItemsDTO(
        new OrderItemDTO('T-shirt', 5000, 1),
        new OrderItemDTO('Shoes', 10000, 1, 'Running Shoes')
    );

    // Order request
    $request = new OrderRequestDTO(
        amountCents: 15000,
        currency: CurrencyEnum::EGP,
        merchantOrderId: 'ORD-' . uniqid(),
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