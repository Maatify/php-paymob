# 📘 Paymob PHP SDK

![PHP](https://img.shields.io/badge/PHP-%5E8.2-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Status](https://img.shields.io/badge/status-Development-orange)

Private PHP SDK for integrating with **[Paymob Egypt APIs](https://developers.paymob.com/egypt/)**.  
Provides a clean, PSR-compliant wrapper around Paymob’s REST APIs with **DTOs, Repositories, Exceptions, and pluggable HTTP clients** (cURL or Guzzle).

---

## ✨ Features

* ✅ Authentication (`/auth/tokens`) with auto-expiry + retry on 401.
* ✅ Config via `PaymobConfigDTO` (API key, integration IDs, base URL).
* ✅ Token caching via `TokenRepositoryInterface` (in-memory, file, or DB/Redis implementations).
* ✅ Pluggable HTTP clients (`CurlApiClient`, `GuzzleApiClient`, or unified `ApiClient`).
* ✅ Structured exceptions (`AuthException`, `OrderException`, `TransactionException`, etc).
* ✅ `PaymobExceptionFactory` for mapping Paymob error codes → typed exceptions.
* ✅ Logging support (PSR-3 / Monolog).
* ✅ Orders API (`createOrder`) with `OrderRequestDTO`, `OrderResponseDTO`, `OrderItemDTO`, `OrderItemsDTO`.
* 🚧 Upcoming: Transactions API, Kiosk Payments, Wallet Payments.

---

## 📦 Installation

```bash
composer require maatify/paymob-php:dev-main
````

If the repository is private:

```bash
composer config repositories.paymob-php vcs git@github.com:maatify/paymob-php.git
composer require maatify/paymob-php:dev-main
```

---

## ⚙️ Configuration

Add these to your `.env` file:

```dotenv
PAYMOB_API_KEY=your-api-key
PAYMOB_INTEGRATION_ID_CARD=your-integration-id-card
PAYMOB_INTEGRATION_ID_KIOSK=your-integration-id-kiosk
PAYMOB_INTEGRATION_ID_WALLET=your-integration-id-wallet
PAYMOB_BASE_URL=https://accept.paymobsolutions.com/api
PAYMOB_KEYS_EXPIRY=180
```

---

## 🧱 Project Structure (current)

```
src/
 ├── DTO/
 │    ├── PaymobConfigDTO.php
 │    ├── Auth/TokenResponseDTO.php
 │    └── Order/
 │         ├── OrderItemDTO.php
 │         ├── OrderItemsDTO.php
 │         ├── OrderRequestDTO.php
 │         └── OrderResponseDTO.php
 ├── Exception/
 │    ├── PaymobException.php
 │    ├── ApiException.php
 │    ├── AuthException.php
 │    ├── OrderException.php
 │    ├── TransactionException.php
 │    ├── WebhookException.php
 │    ├── NetworkException.php
 │    └── PaymobExceptionFactory.php
 ├── Http/
 │    ├── ApiClientInterface.php
 │    ├── CurlApiClient.php
 │    ├── GuzzleApiClient.php
 │    └── ApiClient.php  ← unified wrapper
 ├── Repository/
 │    ├── TokenRepositoryInterface.php
 │    └── InMemoryTokenRepository.php
 └── Service/
      ├── AuthService.php
      └── OrderService.php
examples/
 ├── bootstrap.php
 ├── auth.php
 └── order.php
```

---

## 🚀 Usage

See [examples](./examples):

* [Auth Example](./examples/auth.php) → Get a Paymob token.
* [Order Example](./examples/order.php) → Create a new order with items.

---

### Bootstrap

```php
use Maatify\Paymob\Http\ApiClient;
use Maatify\Paymob\Http\ApiClientInterface;
use Maatify\Paymob\Http\CurlApiClient;
use Maatify\Paymob\Http\GuzzleApiClient;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

final readonly class PaymobExampleBootstrap
{
    public function __construct(
        public PaymobConfigDTO $config,
        public ApiClientInterface $client,
        public Logger $logger,
    )
    {
    }
}
```

---

### 🔑 Get Token

```php
$tokenDto = $authService->getToken();
echo "Token: {$tokenDto->token}\n";
```

---

### 🛒 Create Order

```php
$items = new OrderItemsDTO(
    new OrderItemDTO('T-shirt', 5000, 1),
    new OrderItemDTO('Shoes', 10000, 1, 'Running Shoes')
);

$request = new OrderRequestDTO(
    amountCents: 15000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

$response = $orderService->createOrder($request);
```

---

## 🔥 Error Handling

All SDK calls may throw typed exceptions.
Every exception extends from `PaymobException`, making error handling consistent.

```php
try {
    $response = $orderService->createOrder($request);
} catch (OrderException $e) {
    echo "❌ Order error: " . $e->getMessage();
    print_r($e->getResponse());
}
```

---

## ⚡ Token Repository Implementations

* **InMemoryTokenRepository**: Stores token in runtime memory (default).
* **FileTokenRepository** (coming soon).
* **DB/RedisTokenRepository** (planned).

---

## 🚧 Roadmap

* [ ] Transactions API
* [ ] Kiosk payments
* [ ] Wallet payments
* [ ] File/DB/Redis token repositories
* [ ] Full exception mapping (all Paymob error codes)

---

📌 **Note**: This SDK is under active development (v0.x).
Expect breaking changes until stable v1.0 release.

