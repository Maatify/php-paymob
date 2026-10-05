# Paymob PHP Package Reference

## Package Purpose

`maatify/php-paymob` is a Host-agnostic PHP package for Paymob Egypt authentication, order creation, Payment Key generation, Kiosk and Wallet payment initiation, Transaction Inquiry, and callback validation. Runtime configuration and persistence are supplied through explicit constructor dependencies.

## Boundaries / Non-Goals

The package is Paymob-specific. It does not define a generic payment-provider abstraction or framework bindings. Card/VPC Payment Key generation is supported; Card checkout execution is not a package capability. The package is Pre-Stable and does not retain aliases for removed pre-Stable FQCNs.

## Source Topology

```text
Source Topology: Multi Capability

Capabilities:
- Authentication
- Order
- Payment
- Transaction
- Callback
```

Package-wide responsibilities are in `Config/`, `Adapter/`, `Enum/`, `Exception/`, `Factory/`, and `Facade/`. Capability code is grouped under its capability and responsibility.

## Capabilities

- **Authentication:** Paymob Auth token lifecycle and replaceable, scoped token persistence.
- **Order:** Create orders with `CreateOrderCommand` and `OrderItem` values.
- **Payment:** Generate Payment Keys and initiate Kiosk or Wallet payments.
- **Transaction:** Query a transaction by positive provider transaction ID.
- **Callback:** Validate Transaction Processed callbacks and customer-facing Return URL responses.

## Public Runtime API Inventory

```text
Maatify\Paymob\Config\PaymobConfig
Maatify\Paymob\Adapter\ApiClientInterface
Maatify\Paymob\Adapter\ApiClient
Maatify\Paymob\Adapter\CurlApiClient
Maatify\Paymob\Adapter\GuzzleApiClient
Maatify\Paymob\Enum\CurrencyEnum
Maatify\Paymob\Exception\PaymobExceptionInterface
Maatify\Paymob\Exception\PaymobException and named subclasses
Maatify\Paymob\Facade\PaymobFacade

Maatify\Paymob\Authentication\DTO\TokenResponseDTO
Maatify\Paymob\Authentication\ValueObject\TokenScope
Maatify\Paymob\Authentication\Repository\TokenRepositoryInterface
Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository
Maatify\Paymob\Authentication\Repository\FileTokenRepository
Maatify\Paymob\Authentication\Repository\Pdo\MySqlTokenRepository
Maatify\Paymob\Authentication\Service\AuthService

Maatify\Paymob\Order\Command\CreateOrderCommand
Maatify\Paymob\Order\ValueObject\OrderItem
Maatify\Paymob\Order\DTO\OrderItemDTO
Maatify\Paymob\Order\DTO\OrderItemCollectionDTO
Maatify\Paymob\Order\DTO\OrderResponseDTO
Maatify\Paymob\Order\Service\OrderService

Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand
Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand
Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand
Maatify\Paymob\Payment\ValueObject\BillingData
Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO
Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO
Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO
Maatify\Paymob\Payment\DTO\KioskFlowResultDTO
Maatify\Paymob\Payment\DTO\WalletFlowResultDTO
Maatify\Paymob\Payment\Service\PaymentKeyService
Maatify\Paymob\Payment\Service\KioskPaymentService
Maatify\Paymob\Payment\Service\WalletPaymentService

Maatify\Paymob\Transaction\DTO\TransactionResponseDTO
Maatify\Paymob\Transaction\Service\TransactionService
Maatify\Paymob\Callback\DTO\WebhookPayloadDTO
Maatify\Paymob\Callback\DTO\ReturnUrlResponseDTO
Maatify\Paymob\Callback\Service\WebhookValidator
Maatify\Paymob\Callback\Service\ReturnUrlHandler
```

Each DTO is final readonly and implements `JsonSerializable`. Commands are final readonly and validate their inputs. `OrderItem` and `BillingData` are final readonly Value Objects.

## Construction / Wiring

The Host loads environment variables and creates the dependencies. The package does not read `$_ENV` or call `getenv()`.

```php
use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$config = new PaymobConfig($apiKey, $hmacSecret, $cardId, $kioskId, $walletId);
$clock = new SystemClock(new DateTimeZone('UTC'));
$tokens = new InMemoryTokenRepository();
$http = new ApiClient($config); // CurlApiClient by default
$auth = new AuthService($http, $config, $tokens, $clock);
```

`PaymobFacade` accepts `PaymobConfig`, `ApiClientInterface`, `TokenRepositoryInterface`, `ClockInterface`, optional `LoggerInterface`, and an optional channel string. It composes the package Services without a DI container.

## Configuration

`PaymobConfig` constructor inputs are `apiKey`, `hmacSecret`, `integrationIdCard`, `integrationIdKiosk`, `integrationIdWallet`, and `baseUrl`.

- API key and HMAC secret must be non-empty.
- All integration IDs must be positive.
- Base URL must be HTTPS; trailing slashes are removed.
- Default base URL: `https://accept.paymob.com/api`.

Hosts may use environment variables such as those in `.env.example`, but environment loading stays outside Runtime classes.

## Authentication Token Lifecycle

`AuthService::getToken(bool $forceRefresh = false): TokenResponseDTO` reads the current scope from the injected repository and injected clock. A cached token is valid only while `expiresAt > now`. New tokens receive `issuedAt` from the clock and `expiresAt = issuedAt + 3600`. Renewal performs a new `POST /auth/tokens`; no refresh-token flow or configurable provider TTL is used.

`getToken(true)` clears only the current scope, performs one Auth request, saves the new token to the same scope, and returns it.

## TokenScope

`TokenScope::fromConfig(PaymobConfig)` derives an opaque SHA-256 hex value from normalized base URL, a NUL separator, and API key. The raw API key is not returned, persisted as a scope key, logged as a scope key, or placed in filenames/SQL scope columns.

## Persistence Extension Contract

`TokenRepositoryInterface` is the replaceable, technology-neutral persistence boundary:

```php
get(TokenScope $scope): ?TokenResponseDTO
save(TokenScope $scope, TokenResponseDTO $token): void
clear(TokenScope $scope): void
```

Every operation is scope-local. A consumer-supplied implementation does not require the prerequisites of built-in File or MySQL persistence.

## Built-in InMemory Persistence

`InMemoryTokenRepository` keeps tokens in memory, keyed by `TokenScope`. Clearing one scope leaves all other scopes intact.

## Built-in File Persistence

`FileTokenRepository` accepts a storage directory. Each scope uses its opaque digest as a JSON filename. Missing data returns `null`; malformed JSON, missing or invalid required fields, and failed file operations raise `TokenStorageException`. Writes use a per-scope lock and atomic rename. The directory and token files use restrictive permissions.

## Built-in MySQL/MariaDB Persistence

`MySqlTokenRepository` accepts a Host-provided PDO using the MySQL driver. It uses direct PDO SQL and the `maa_paymob_auth_tokens` table. It reads by exact scope, atomically upserts the current token against the unique scope key, and deletes only that scope. Unknown PDO infrastructure errors may propagate unchanged.

## Optional Runtime Prerequisites

- The default `CurlApiClient` requires `ext-curl`, a mandatory package prerequisite.
- `GuzzleApiClient` requires optional `guzzlehttp/guzzle ^7.0`.
- `MySqlTokenRepository` requires optional `ext-pdo`, `ext-pdo_mysql`, and a Host-provided PDO using the `mysql` driver.
- A consumer implementation of `TokenRepositoryInterface` does not inherit PDO/MySQL requirements.

## HTTP Adapter Contract

`ApiClientInterface` defines `post(string $uri, array $body, array $headers = []): array` and `get(string $uri, array $query = [], array $headers = []): array`. Services depend on this interface. `ApiClient` is a convenience delegator and selects cURL by default.

## cURL Adapter

`CurlApiClient` sends JSON requests and verifies TLS peers and host names (`CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`). It passes caller headers for GET and POST.

## Guzzle Adapter

`GuzzleApiClient` is explicitly selected and checks availability before use. It sets `http_errors=false`, passes GET query and caller headers, and shares response classification with cURL. TLS verification remains enabled.

## Provider/API Failure Semantics

Every HTTP status `>= 400`, including 400, is a provider/API failure represented by `ApiException` or a named subclass. `getProviderStatusCode()` returns the provider status independently of the PHP exception code. `getResponse()` retains a decoded array or raw string body. Response bodies are not automatically logged.

## Transport/Network Failure Semantics

DNS, connection, TLS, timeout, or inability to obtain an HTTP response is a `NetworkException`. It is distinct from a provider response, including provider 4xx/5xx responses. The original transport throwable is retained as `previous` when available.

## 401 Recovery

Exactly one same-scope token invalidation, fresh Auth request, and replay is performed after a typed provider 401 for Order, Payment Key, Kiosk Pay, and Transaction Inquiry. A second failure propagates as its actual exception. Auth itself, Wallet Pay, Webhook validation, and Return URL parsing do not retry. There is no general retry or automatic 5xx mutation replay.

## Order

`OrderService::createOrder(CreateOrderCommand): OrderResponseDTO` posts to `/ecommerce/orders` with `auth_token`, `amount_cents`, `currency`, `merchant_order_id`, and `items`. The Host constructs `CreateOrderCommand` with `OrderItem` values; the Service adds the auth token.

## Payment Key

`PaymentKeyService::generate(GeneratePaymentKeyCommand): PaymentKeyResponseDTO` posts to `/acceptance/payment_keys`. The command includes a positive order ID, integration ID, amount, `CurrencyEnum`, `BillingData`, and `expirationSeconds` (default 180 seconds). The Auth token is injected by the Service. The response `orderId` comes from command context; the provider response is not assumed to contain `order`.

## Kiosk

`KioskPaymentService::pay(InitiateKioskPaymentCommand): KioskPaymentResponseDTO` posts to `/acceptance/payments/pay`. It preserves `source.identifier=AGGREGATOR`, `source.subtype=AGGREGATOR`, `payment_token`, and `auth_token`.

## Wallet

`WalletPaymentService::pay(InitiateWalletPaymentCommand): WalletPaymentResponseDTO` posts to `/acceptance/payments/pay`. It preserves the wallet MSISDN as `source.identifier`, `source.subtype=WALLET`, and `payment_token`; it does not add `auth_token` and does not refresh/replay after 401.

## Transaction Inquiry

`TransactionService::getTransaction(int $id): TransactionResponseDTO` requires a positive transaction ID and performs `GET /acceptance/transactions/{transaction_id}` with `Authorization: Bearer {auth-token}`. Inquiry is transaction-ID only.

## Processed Callback / HMAC

`WebhookValidator::validate(array $payload): WebhookPayloadDTO` validates the Transaction Processed callback and its accepted HMAC field order. Required signed fields fail closed. The validated Processed Callback is the authoritative server-side payment-status boundary.

## Return URL / Response Callback

`ReturnUrlHandler::parse(array $query): ReturnUrlResponseDTO` validates PHP-normalized query keys and HMAC. It accepts `order` or `order_id`; if both exist they must match. `order.id` is not accepted as a replacement. PHP-normalized `source_data_pan`, `source_data_sub_type`, `source_data_type`, and `data_message` are preserved. `data_message` is unsigned advisory text. Return URL data is for customer-facing display and is not authoritative payment/order state.

## Card/VPC Scope

Card/VPC Payment Key generation is supported using the configured Card integration ID. Customer-facing Card payment execution, `CardPaymentService`, `payViaCard`, and iframe/hosted checkout behavior are outside the implemented package capability.

## Exceptions

All package exceptions implement `PaymobExceptionInterface` and use the `maatify/exceptions` base. Named provider classifications include Unauthorized, Validation, Not Found, Rate Limit, Service Unavailable, Auth, Order, Transaction, and Webhook failures. `TokenStorageException`, `OptionalCapabilityUnavailableException`, and `ReturnUrlException` represent their named package boundaries.

## DTO Diagnostic Snapshots and JSON

Where present, `OrderResponseDTO::$row`, `KioskPaymentResponseDTO::$row`, `TransactionResponseDTO::$raw`, and `WebhookPayloadDTO::$raw` retain diagnostic provider or callback snapshots as publicly accessible PHP properties. These snapshots may contain sensitive data. Each DTO excludes its diagnostic snapshot from `JsonSerializable` output. Serializing `KioskFlowResultDTO` does not automatically expose its nested order or Kiosk snapshots; serializing `WalletFlowResultDTO` does not automatically expose its nested order snapshot. Consumers should inspect these properties deliberately and must not blindly log or routinely serialize them.

## Sensitive Data / Logging

Do not log API keys, HMAC secrets, auth tokens, payment keys, wallet phone numbers, billing data, request payloads, raw provider response bodies, HMAC values, or traces containing arguments. Built-in transport logs are restricted to method, URI path, status, and exception class. Public token DTO serialization is an explicit consumer data contract, not permission to log it.

## Schema Contract

`schema/mysql.sql` defines `maa_paymob_auth_tokens` with `PRIMARY KEY (id)`, unique 64-character `scope_key`, token, positive profile identity, and Unix timestamps. It is an authentication cache only: no soft delete, Host foreign keys, or Host JOINs.

## Consumer Workflow

```text
Host configuration
→ PaymobConfig
→ ClockInterface
→ TokenRepositoryInterface implementation
→ ApiClientInterface implementation
→ Services or PaymobFacade
→ Paymob provider
→ typed DTO or typed package failure
```

Use `new ApiClient($config)` for the default cURL path. Inject a custom `TokenRepositoryInterface` to use consumer storage. Select `GuzzleApiClient` only when its optional package is installed. Select `MySqlTokenRepository` only when PDO, the MySQL PDO driver, and a Host PDO connection are available.

## Extension Points

Consumers may supply their own `ApiClientInterface` or `TokenRepositoryInterface` implementations. These implementations remain Host-owned and do not require unrelated optional backend dependencies.

## Applicable Decisions / Supporting Docs

- [DEC-001: Provider Verification Harness](docs/decisions/DEC-001-provider-verification-harness.md)
- [DEC-002: Paymob Capability Topology](docs/decisions/DEC-002-paymob-multi-capability-source-topology.md)
- [DEC-003: Token Lifecycle and Replaceable Persistence](docs/decisions/DEC-003-auth-token-lifecycle-and-replaceable-persistence.md)
- [DEC-004: Provider Failure and Authentication Recovery](docs/decisions/DEC-004-provider-failure-and-authentication-recovery-semantics.md)
- [DEC-005: First-RC Public Runtime API and Construction](docs/decisions/DEC-005-first-rc-public-runtime-api-construction-contract.md)
