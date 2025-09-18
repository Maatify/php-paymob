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
* ✅ Payment Keys API (/acceptance/payment_keys) with `PaymentKeyRequestDTO`, `PaymentKeyResponseDTO`, `BillingDataDTO`.
* ✅ Kiosk Payments API (pay) with typed response `KioskPaymentResponseDTO`.
* ✅ Facade (PaymobFacade) for full flows (e.g. payViaKiosk) in one call.
* ✅ Wallet Payments API (pay) with typed response `WalletPaymentResponseDTO`.
* ✅ Facade (PaymobFacade) for full flows (e.g. payViaWallet) in one call.
* ✅ Transactions API (/acceptance/transactions/{id}) with typed response `TransactionResponseDTO`.

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
 │    ├── Auth/
 │    │    └── TokenResponseDTO.php
 │    ├── Order/
 │    │    ├── OrderItemDTO.php
 │    │    ├── OrderItemsDTO.php
 │    │    ├── OrderRequestDTO.php
 │    │    └── OrderResponseDTO.php
 │    ├── Payment/
 │    │    ├── BillingDataDTO.php
 │    │    ├── PaymentKeyRequestDTO.php
 │    │    ├── PaymentKeyResponseDTO.php
 │    │    ├── KioskPaymentRequestDTO.php
 │    │    ├── KioskPaymentResponseDTO.php
 │    │    ├── WalletPaymentRequestDTO.php
 │    │    └── WalletPaymentResponseDTO.php
 │    └── Transaction/
 │         └── TransactionResponseDTO.php
 ├── Enum/
 │    └── CurrencyEnum.php
 ├── Exception/
 │    ├── PaymobException.php
 │    ├── ApiException.php
 │    ├── AuthException.php
 │    ├── OrderException.php
 │    ├── TransactionException.php
 │    ├── WebhookException.php
 │    ├── NetworkException.php
 │    └── PaymobExceptionFactory.php
 ├── Facade/
 │    └── PaymobFacade.php   ← full flows (Kiosk + Wallet)
 ├── Http/
 │    ├── ApiClientInterface.php
 │    ├── CurlApiClient.php
 │    ├── GuzzleApiClient.php
 │    └── ApiClient.php
 ├── Repository/
 │    ├── TokenRepositoryInterface.php
 │    └── InMemoryTokenRepository.php
 ├── Service/
 │    ├── AuthService.php
 │    ├── OrderService.php
 │    ├── PaymentKeyService.php
 │    ├── KioskPaymentService.php
 │    ├── WalletPaymentService.php
 │    └── TransactionService.php
 ├── PaymobConfigDTO.php
 ├── KioskFlowResultDTO.php
 └── WalletFlowResultDTO.php
examples/
 ├── bootstrap.php
 ├── auth.php
 ├── order.php
 ├── kiosk.php
 ├── facade_kiosk.php
 ├── wallet.php
 ├── facade_wallet.php
 └── transaction.php
```


---

## 🚀 Usage

See [examples](./examples):

* [Auth Example](./examples/auth.php) → Get a Paymob token.
* [Order Example](./examples/order.php) → Create a new order with items.
* [Payment Key Example](./examples/payment_key.php) → Generate a payment key after creating an order.
* [KIOSK Example](./examples/kiosk.php) → Initiate a payment via kiosks (e.g., Aman, Masary).
* [Facade Kiosk Example](./examples/facade_kiosk.php) → Full Kiosk flow (Order + Key + Pay) in one call٫
* [Wallet Example](./examples/wallet.php) → Initiate a payment via Wallet (Vodafone Cash, Orange, Etisalat, WE).
* [Facade Wallet Example](./examples/facade_wallet.php) → Full Wallet flow (Order + Key + Pay) in one call.
* [Transaction Example](./examples/transaction.php) → Query transaction details by transaction ID.
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
### 💳 Generate Payment Key
```php
$billing = new BillingDataDTO(
    firstName: 'Mohamed',
    lastName: 'Abdulalim',
    email: 'mohamed@example.com',
    phoneNumber: '201000000000',
    country: 'EG',       //<---- Optional
    city: 'Cairo',       //<---- Optional
    street: 'Nile St.',  //<---- Optional
    building: '12',      //<---- Optional
    floor: '8',          //<---- Optional
    apartment: '803',    //<---- Optional
    postalCode: '12345', //<---- Optional
    state: 'EG'          //<---- Optional
);

$paymentKeyRequest = new PaymentKeyRequestDTO(
    orderId: $orderResponse->id,
    integrationId: $bootstrap->config->integrationIdCard,
    amountCents: $orderResponse->amountCents,
    currency: $orderResponse->currency,
    billingData: $billing
);

$paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);

echo "Token: {$paymentKeyResponse->token}\n";

```

---

## 🏪 Kiosk Payments

Use **KioskPaymentService** to initiate a payment via kiosks (e.g., Aman, Masary).
This requires using the **Kiosk integration ID** when generating the payment key.

### Example

```php
use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyRequestDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentRequestDTO;
use Maatify\Paymob\Service\OrderService;
use Maatify\Paymob\Service\PaymentKeyService;
use Maatify\Paymob\Service\KioskPaymentService;
use Maatify\Paymob\Enum\CurrencyEnum;

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

$orderResponse = $orderService->createOrder($orderRequest);
echo "✅ Order created. ID = {$orderResponse->id}\n";

// Step 2: Billing data (NA allowed for kiosk)
$billing = new BillingDataDTO(
    firstName: 'Mohamed',
    lastName: 'Abdulalim',
    email: 'mohamed@example.com',
    phoneNumber: '201000000000',
    country: 'NA',
    city: 'NA',
    street: 'NA',
    building: 'NA',
    floor: 'NA',
    apartment: 'NA',
    postalCode: 'NA',
    state: 'NA'
);

// Step 3: Generate payment key using Kiosk integration ID
$paymentKeyRequest = new PaymentKeyRequestDTO(
    orderId: $orderResponse->id,
    integrationId: $bootstrap->config->integrationIdKiosk,
    amountCents: $orderResponse->amountCents,
    currency: $orderResponse->currency,
    billingData: $billing
);

$paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);
echo "✅ Payment key generated. Token = {$paymentKeyResponse->token}\n";

// Step 4: Pay via Kiosk
$kioskRequest = new KioskPaymentRequestDTO($paymentKeyResponse->token);
$kioskResponse = $kioskService->pay($kioskRequest);

echo "✅ Kiosk Payment initiated successfully:\n";
echo "Transaction ID    : {$kioskResponse->transactionId}\n";
echo "Bill Reference    : {$kioskResponse->billReference}\n";
echo "Order ID          : {$kioskResponse->orderId}\n";
echo "Currency          : {$kioskResponse->currency->value}\n";
echo "Created At        : {$kioskResponse->createdAt}\n";
echo "Updated At        : {$kioskResponse->updatedAt}\n";
echo "Is Success        : " . ($kioskResponse->success ? 'true' : 'false') . "\n";
echo "Is Pending        : " . ($kioskResponse->pending ? 'true' : 'false') . "\n";
echo "Merchant Order ID : {$kioskResponse->merchantOrderId}\n";
echo "Payment Status    : {$kioskResponse->paymentStatus}\n";
echo "Status Message    : {$kioskResponse->statusMessage}\n";
```

---

### 🏪 Facade: Pay via Kiosk (One Call)

```php
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Repository\InMemoryTokenRepository;

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

$billing = new BillingDataDTO(
    firstName: 'Mohamed',
    lastName: 'Abdulalim',
    email: 'mohamed@example.com',
    phoneNumber: '201000000000',
    country: 'EG',       //<---- Optional
    city: 'Cairo',       //<---- Optional
    street: 'Nile St.',  //<---- Optional
    building: '12',      //<---- Optional
    floor: '8',          //<---- Optional
    apartment: '803',    //<---- Optional
    postalCode: '12345', //<---- Optional
    state: 'EG'          //<---- Optional
);

$facade = new PaymobFacade(
    config : $bootstrap->config,
    http   : $bootstrap->client,
    repo   : new InMemoryTokenRepository(),
    logger : $bootstrap->logger
);

$result = $facade->payViaKiosk($orderRequest, $billing);

echo "✅ Order ID        : {$result->order->id}\n";
echo "✅ Transaction ID  : {$result->kiosk->transactionId}\n";
echo "✅ Bill Reference  : {$result->kiosk->billReference}\n";
echo "✅ Payment Status  : {$result->kiosk->paymentStatus}\n";

```
---

## 📱 Wallet Payments

Use WalletPaymentService to initiate a payment via mobile wallets (e.g., Vodafone Cash, Orange Money, Etisalat Cash, WE Pay).
This requires using the Wallet integration ID when generating the payment key.
### Example
```php
use Maatify\Paymob\DTO\Payment\WalletPaymentRequestDTO;
use Maatify\Paymob\Service\WalletPaymentService;

$paymentKeyRequest = new PaymentKeyRequestDTO(
    orderId: $orderResponse->id,
    integrationId: $bootstrap->config->integrationIdWallet,
    amountCents: $orderResponse->amountCents,
    currency: $orderResponse->currency,
    billingData: $billing
);

$paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);

$walletRequest = new WalletPaymentRequestDTO(
    paymentToken: $paymentKeyResponse->token,
    walletNumber: '01000000000' // رقم محفظة العميل
);

$walletResponse = $walletService->pay($walletRequest);

echo "✅ Wallet Payment initiated successfully:\n";
echo "Transaction ID    : {$walletResponse->transactionId}\n";
echo "Order ID          : {$walletResponse->orderId}\n";
echo "Payment Status    : {$walletResponse->paymentStatus}\n";
echo "Status Message    : {$walletResponse->statusMessage}\n";
echo "Redirect URL      : {$walletResponse->redirectUrl}\n";

```
---

### 📱 Facade: Pay via Wallet (One Call)
```php
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\Enum\CurrencyEnum;

$items = new OrderItemsDTO(
    new OrderItemDTO('Headphones', 7000, 1),
    new OrderItemDTO('Charger', 3000, 1, 'Fast Charger')
);

$orderRequest = new OrderRequestDTO(
    amountCents: 10000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

$billing = new BillingDataDTO(
    firstName: 'Mohamed',
    lastName: 'Abdulalim',
    email: 'mohamed@example.com',
    phoneNumber: '201000000000',
    country: 'EG',
    city: 'Cairo',
    street: 'Nile St.',
    building: '12'
);

$facade = new PaymobFacade(
    config : $bootstrap->config,
    http   : $bootstrap->client,
    repo   : new InMemoryTokenRepository(),
    logger : $bootstrap->logger
);

$result = $facade->payViaWallet($orderRequest, $billing, walletNumber: '01000000000');

echo "✅ Order ID        : {$result->order->id}\n";
echo "✅ Transaction ID  : {$result->wallet->transactionId}\n";
echo "✅ Payment Status  : {$result->wallet->paymentStatus}\n";
echo "✅ Redirect URL    : {$result->wallet->redirectUrl}\n";

```
---
📊 Transactions API

Use TransactionService to fetch transaction details by ID.
This endpoint is useful for verifying payments, checking status, and reconciling orders.

Example
```php
use Maatify\Paymob\Service\TransactionService;
use Maatify\Paymob\DTO\Transaction\TransactionResponseDTO;

$transactionService = new TransactionService(
    http: $bootstrap->client,
    auth: $authService
);

// Fetch transaction details by ID
$transactionId = 344212847;
$transaction = $transactionService->getById($transactionId);

echo "✅ Transaction fetched successfully:\n";
echo "Transaction ID  : {$transaction->id}\n";
echo "Order ID        : {$transaction->orderId}\n";
echo "Amount Cents    : {$transaction->amountCents}\n";
echo "Currency        : {$transaction->currency->value}\n";
echo "Status          : {$transaction->paymentStatus}\n";
echo "Pending?        : " . ($transaction->pending ? 'true' : 'false') . "\n";
echo "Success?        : " . ($transaction->success ? 'true' : 'false') . "\n";
echo "Created At      : {$transaction->createdAt}\n";
echo "Updated At      : {$transaction->updatedAt}\n";

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

* [ ] File/DB/Redis token repositories
* [ ] Full exception mapping (all Paymob error codes)

---

📌 **Note**: This SDK is under active development (v0.x).
Expect breaking changes until stable v1.0 release.

