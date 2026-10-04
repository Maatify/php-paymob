# 📘 Paymob PHP SDK

![PHP](https://img.shields.io/badge/PHP-%5E8.4-blue)
![License](https://img.shields.io/badge/license-proprietary-blue)
![Status](https://img.shields.io/badge/status-Development-orange)

Private PHP SDK for integrating with **[Paymob Egypt APIs](https://developers.paymob.com/egypt/)**.  
Provides a clean, PSR-compliant wrapper around Paymob’s REST APIs with **DTOs, Repositories, Exceptions, and pluggable HTTP clients** (cURL or Guzzle).

---

## ✨ Features

* ✅ Authentication (`/auth/tokens`) with a 3600-second token lifetime and scoped cache.
* ✅ Config via `PaymobConfig` (API key, HMAC secret, integration IDs, HTTPS base URL).
* ✅ Token caching via scoped `TokenRepositoryInterface` (in-memory, file, MySQL/MariaDB, or consumer implementation).
* ✅ Pluggable HTTP clients (`CurlApiClient`, `GuzzleApiClient`, or unified `ApiClient`).
* ✅ Structured exceptions (`AuthException`, `OrderException`, `TransactionException`, etc).
* ✅ `PaymobExceptionFactory` for mapping Paymob error codes → typed exceptions.
* ✅ Logging support (PSR-3 / Monolog).
* ✅ Orders API (`createOrder`) with `CreateOrderCommand`, `OrderResponseDTO`, `OrderItem`, `OrderItemCollectionDTO`.
* ✅ Payment Keys API (/acceptance/payment_keys) with `GeneratePaymentKeyCommand`, `PaymentKeyResponseDTO`, `BillingData`.
* ✅ Kiosk Payments API (pay) with typed response `KioskPaymentResponseDTO`.
* ✅ Facade (PaymobFacade) for full flows (e.g. payViaKiosk) in one call.
* ✅ Wallet Payments API (pay) with typed response `WalletPaymentResponseDTO`.
* ✅ Facade (PaymobFacade) for full flows (e.g. payViaWallet) in one call.
* ✅ Transactions API (/acceptance/transactions/{id}) with typed response `TransactionResponseDTO`.

---

## 📦 Installation

```bash
composer require maatify/php-paymob:dev-main
````

If the repository is private:

```bash
composer config repositories.php-paymob vcs git@github.com:Maatify/php-paymob.git
composer require maatify/php-paymob:dev-main
```

---

## ⚙️ Configuration

Add these to your `.env` file:

```dotenv
PAYMOB_API_KEY=your-api-key
PAYMOB_INTEGRATION_ID_CARD=your-integration-id-card
PAYMOB_INTEGRATION_ID_KIOSK=your-integration-id-kiosk
PAYMOB_INTEGRATION_ID_WALLET=your-integration-id-wallet
PAYMOB_HMAC_SECRET=your-hmac-secret
PAYMOB_BASE_URL=https://accept.paymob.com/api
```

---

## 🧱 Project Structure (current)

```text
src/
├── Adapter/
├── Authentication/{DTO,Repository,Service,ValueObject}/
├── Callback/{DTO,Service}/
├── Config/
├── Enum/
├── Exception/
├── Factory/
├── Facade/
├── Order/{Command,DTO,Service,ValueObject}/
├── Payment/{Command,DTO,Service,ValueObject}/
└── Transaction/{DTO,Service}/
schema/mysql.sql
examples/
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
* [Return URL Example](./examples/return_url.php) → Validate a customer-facing Transaction Response redirect.
---

### Bootstrap

```php
use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Adapter\CurlApiClient;
use Maatify\Paymob\Adapter\GuzzleApiClient;
use Maatify\Paymob\Config\PaymobConfig;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

final readonly class PaymobExampleBootstrap
{
    public function __construct(
        public PaymobConfig $config,
        public ApiClientInterface $client,
        public Logger $logger,
        public \Maatify\SharedCommon\Contracts\ClockInterface $clock,
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
$items = [new OrderItem('T-shirt', 5000, 1), new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

$request = new CreateOrderCommand(
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
$billing = new BillingData(
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

$paymentKeyRequest = new GeneratePaymentKeyCommand(
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
use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Enum\CurrencyEnum;

// Step 1: Create order
$items = [new OrderItem('T-shirt', 5000, 1), new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

$orderRequest = new CreateOrderCommand(
    amountCents: 15000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

$orderResponse = $orderService->createOrder($orderRequest);
echo "✅ Order created. ID = {$orderResponse->id}\n";

// Step 2: Billing data (NA allowed for kiosk)
$billing = new BillingData(
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
$paymentKeyRequest = new GeneratePaymentKeyCommand(
    orderId: $orderResponse->id,
    integrationId: $bootstrap->config->integrationIdKiosk,
    amountCents: $orderResponse->amountCents,
    currency: $orderResponse->currency,
    billingData: $billing
);

$paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);
echo "✅ Payment key generated. Token = {$paymentKeyResponse->token}\n";

// Step 4: Pay via Kiosk
$kioskRequest = new InitiateKioskPaymentCommand($paymentKeyResponse->token);
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
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;

$items = [new OrderItem('T-shirt', 5000, 1), new OrderItem('Shoes', 10000, 1, 'Running Shoes')];

$orderRequest = new CreateOrderCommand(
    amountCents: 15000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

$billing = new BillingData(
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
    clock  : $bootstrap->clock,
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
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\Service\WalletPaymentService;

$paymentKeyRequest = new GeneratePaymentKeyCommand(
    orderId: $orderResponse->id,
    integrationId: $bootstrap->config->integrationIdWallet,
    amountCents: $orderResponse->amountCents,
    currency: $orderResponse->currency,
    billingData: $billing
);

$paymentKeyResponse = $paymentKeyService->generate($paymentKeyRequest);

$walletRequest = new InitiateWalletPaymentCommand(
    paymentToken: $paymentKeyResponse->token,
    phoneNumber: '01000000000' // verification input supplied by the Host
);

$walletResponse = $walletService->pay($walletRequest);

echo "✅ Wallet Payment initiated successfully:\n";
echo "Transaction ID    : {$walletResponse->transactionId}\n";
echo "Order ID          : {$walletResponse->orderId}\n";
echo "Status Message    : {$walletResponse->statusMessage}\n";
echo "Redirect URL      : {$walletResponse->redirectUrl}\n";

```
---

### 📱 Facade: Pay via Wallet (One Call)
```php
use Maatify\Paymob\Facade\PaymobFacade;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Order\ValueObject\OrderItem;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Enum\CurrencyEnum;

$items = [new OrderItem('Headphones', 7000, 1), new OrderItem('Charger', 3000, 1, 'Fast Charger')];

$orderRequest = new CreateOrderCommand(
    amountCents: 10000,
    currency: CurrencyEnum::EGP,
    merchantOrderId: 'ORD-' . uniqid(),
    items: $items
);

$billing = new BillingData(
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
    clock  : $bootstrap->clock,
    logger : $bootstrap->logger
);

$result = $facade->payViaWallet($orderRequest, $billing, walletNumber: '01000000000');

echo "✅ Order ID        : {$result->order->id}\n";
echo "✅ Transaction ID  : {$result->wallet->transactionId}\n";
echo "✅ Payment Status  : {$result->wallet->statusMessage}\n";
echo "✅ Redirect URL    : {$result->wallet->redirectUrl}\n";

```
---
📊 Transactions API

Use TransactionService to fetch transaction details by ID.
This endpoint is useful for verifying payments, checking status, and reconciling orders.

Example
```php
use Maatify\Paymob\Transaction\Service\TransactionService;
use Maatify\Paymob\Transaction\DTO\TransactionResponseDTO;

$transactionService = new TransactionService($bootstrap->client, $authService);

// Fetch transaction details by ID
$transactionId = 344212847;
$transaction = $transactionService->getTransaction($transactionId);

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
🌐 Webhooks

Use WebhookValidator to verify Paymob webhook callbacks (e.g., transaction success/failure notifications).
For a Transaction Processed callback, the JSON request body contains the transaction data and the HMAC is supplied in the query string. Combine both values into one normalized array before calling `validate()`; the validator computes the HMAC using your configured secret and compares it with that query value.

⚠️ Make sure to set PAYMOB_HMAC_SECRET in your .env.

Example
```php
use Maatify\Paymob\Callback\Service\WebhookValidator;
use Maatify\Paymob\Exception\WebhookException;

$validator = new WebhookValidator($bootstrap->config);

try {
    $body = file_get_contents('php://input');
    if ($body === false) {
        throw new RuntimeException('Unable to read webhook request body');
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Webhook JSON body must be an object');
    }

    $payload['hmac'] = $_GET['hmac'] ?? null;
    $payload = $validator->validate($payload);

    echo "✅ Webhook valid for Transaction #{$payload->transactionId}\n";
} catch (WebhookException | JsonException | RuntimeException $e) {
    echo "❌ Invalid webhook: " . $e->getMessage();
}

```

### Transaction Response Callback (Return URL)

The existing [Return URL example](./examples/return_url.php) handles Paymob's GET redirect with query parameters and an HMAC:

```php
use Maatify\Paymob\Callback\Service\ReturnUrlHandler;

$handler = new ReturnUrlHandler($bootstrap->config);
$response = $handler->parse($_GET);
```

PHP converts dotted query parameter names to underscores in `$_GET`. For example, `source_data.type` becomes `source_data_type`, and `data.message` becomes `data_message`. Pass the PHP-normalized `$_GET` array to `parse()`; it validates the signed fields and HMAC before returning the result DTO.

Paymob's Transaction Response sample uses `order`, while its HMAC documentation also names `order_id` for the Response GET query. The handler accepts either non-empty string. If both keys are present, their values must match. The same validated order value occupies the existing HMAC position and becomes `orderId` in the DTO; `order.id` is not a supported PHP GET array key.

`data.message` / `data_message` maps to the DTO's `message`, but it is **not** among the 20 HMAC-signed Transaction Response fields. Treat the message as advisory, unsigned provider-return text; do not make payment or order decisions from it.

The Transaction Response Callback is for customer-facing result and redirect handling. Do **not** use it as the authoritative source for updating order or payment status. Use the validated Transaction Processed Callback for authoritative server-side state.

---
## 🔥 Error Handling

All SDK calls may throw typed exceptions.
Package exceptions implement `PaymobExceptionInterface` and use the `maatify/exceptions` base.

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
* **FileTokenRepository**: scoped JSON files with locking and atomic replacement.
* **MySqlTokenRepository**: optional MySQL/MariaDB implementation; consumer repositories may use other storage.

## Optional Runtime Prerequisites

PHP `^8.4` and `ext-curl` are mandatory. The default `ApiClient` selects cURL. Guzzle is optional and requires `guzzlehttp/guzzle ^7.0`. The built-in MySQL/MariaDB repository is optional and requires `ext-pdo`, `ext-pdo_mysql`, and a Host-provided PDO using the MySQL driver. Custom `TokenRepositoryInterface` implementations do not require PDO/MySQL. See [PAYMOB_PACKAGE_REFERENCE.md](./PAYMOB_PACKAGE_REFERENCE.md) for the implemented API and failure contract.

---

## 🚧 Roadmap

* [ ] Stable release qualification

---

📌 **Note**: This package is Pre-Stable. See [PAYMOB_PACKAGE_REFERENCE.md](./PAYMOB_PACKAGE_REFERENCE.md) for the implemented Runtime API and construction contract.
