# DEC-005: First-RC Public Runtime API / Construction Contract

- **Status:** ACTIVE
- **Date:** 2026-10-05
- **Decision Authority:** Owner
- **Scope / Concern:** First-RC public PHP Runtime API, FQCN inventory, and construction contract
- **Canonical Contract / Current Owner:** This record / Owner
- **Supersedes:** None
- **Superseded By:** None

## Context

The package is Pre-Stable and its former Runtime namespaces and request DTOs do not create compatibility entitlements. Phase 5 implements the Owner-approved multi-capability topology and public API while preserving the provider contracts established in Phase 4 and the boundaries in DEC-001 through DEC-004.

The canonical current API and consumer construction guidance are maintained in [`PAYMOB_PACKAGE_REFERENCE.md`](../../PAYMOB_PACKAGE_REFERENCE.md). This record preserves the approved first-RC contract and its governing choices.

## Decision

### Topology and boundaries

```text
Source Topology: Multi Capability

Capabilities:
- Authentication
- Order
- Payment
- Transaction
- Callback
```

Shared package responsibilities use `Config/`, `Adapter/`, `Enum/`, `Exception/`, `Factory/` when retained, and `Facade/` only for actual shared responsibilities. Capability-owned code follows `Domain → Capability → Responsibility`. There is no generic payment-provider abstraction, generic gateway, or provider registry. Card/VPC Payment Key generation remains supported; customer-facing Card execution is not a package capability. Old pre-Stable FQCN aliases and compatibility shims are not retained.

### Approved public FQCN inventory

```text
Maatify\Paymob\Config\PaymobConfig
Maatify\Paymob\Adapter\ApiClientInterface
Maatify\Paymob\Adapter\ApiClient
Maatify\Paymob\Adapter\CurlApiClient
Maatify\Paymob\Adapter\GuzzleApiClient
Maatify\Paymob\Enum\CurrencyEnum
Maatify\Paymob\Exception\PaymobExceptionInterface
Maatify\Paymob\Exception\...
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

All Commands are final readonly, self-validating action intent and contain no provider token or orchestration responsibility. `OrderItem` and `BillingData` are validated Value Objects. DTOs are final readonly `JsonSerializable` result snapshots. The mutable `OrderItemsDTO` and old request DTO symbols are removed.

### Construction contract

`PaymobConfig` accepts API key, HMAC secret, three positive integration IDs, and an HTTPS base URL. Its default is `https://accept.paymob.com/api`; trailing slashes are removed. Runtime classes receive config from the Host and do not read environment variables.

`AuthService` requires `ApiClientInterface`, `PaymobConfig`, `TokenRepositoryInterface`, and `Maatify\SharedCommon\Contracts\ClockInterface`. The facade accepts those dependencies plus an optional PSR logger and channel. It remains a thin composition layer and exposes only `payViaKiosk(CreateOrderCommand, BillingData)` and `payViaWallet(CreateOrderCommand, BillingData, string)` convenience flows.

`ApiClient` defaults to cURL. Guzzle is explicitly selected and optional. MySQL/MariaDB persistence is an optional built-in capability; PDO is supplied by the Host. Consumers may implement `TokenRepositoryInterface` without PDO or Guzzle prerequisites.

### Lifecycle and provider semantics

Token cache operations are scoped by a non-secret SHA-256 identity derived from normalized base URL and API key. Provider token TTL is exactly 3600 seconds. Renewal obtains a new `/auth/tokens` token; there is no refresh-token contract. Payment Key expiration is named `expirationSeconds` and defaults to 180 seconds.

HTTP statuses `>= 400` are provider/API failures. Failure to obtain an HTTP response is a distinct transport/network failure. Exactly one same-scope refresh and replay is permitted after a typed 401 for Order, Payment Key, Kiosk Pay, and Transaction Inquiry. Auth, Wallet Pay, Webhook, and Return URL do not recover by replay. No general retry is provided.

Processed Callback and Return URL HMAC behavior retain the accepted Phase-4 contracts. Return URL data remains customer-facing advisory input; the Processed Callback is the authoritative server-side status boundary.

## Rationale

The five capabilities preserve Paymob ownership without coupling the package to a Host framework or another provider. Explicit Commands and Value Objects make intent and validation visible. A replaceable scoped token contract avoids forcing unrelated consumers to install a database extension. Typed transport failures and a single bounded 401 replay preserve provider outcomes without inventing retry guarantees.

## Consequences

The implementation, examples, Composer metadata, and tests must agree with this contract. The root `PAYMOB_PACKAGE_REFERENCE.md` is the canonical current description of the implemented API after this batch. Because the package is Pre-Stable, the old FQCN map is not supported through aliases. DEC-001 through DEC-004 remain ACTIVE and unchanged.
