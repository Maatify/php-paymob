# Paymob provider verification

This maintained CLI tooling verifies explicitly selected Paymob provider flows through the package's current Services and Commands. The verification transport captures request and response evidence outside the repository and saves a sanitized report and response fixture candidate.

This tooling is separate from the Unit Test suite: it makes real HTTP requests to the Paymob Egypt API and exercises provider behavior. Test and Live modes use the same API base URL; credentials and integration IDs determine the configured mode. It is also separate from the Consumer Verification Harness, which validates a package consumer's integration with a published package. These scripts use the current repository source.

## Before running a provider flow

Execution is manual and explicit. Each flow can create provider-side orders or transactions. Confirm the exact provider contract and approved scenario with the Lead before running a script; the scripts are for confirmation, not trial-and-error API discovery. They are not run automatically by CI.

Requirements for the existing scenarios:

- PHP 8.4 or later, Composer dependencies installed, and the cURL extension.
- A repository-local `.env` with `PAYMOB_API_KEY`, `PAYMOB_HMAC_SECRET`, all three integration IDs, and `PAYMOB_BASE_URL`.
- `PAYMOB_BASE_URL` normalizes to `https://accept.paymob.com/api`.
- All configured integration IDs are positive: `PAYMOB_INTEGRATION_ID_CARD`, `PAYMOB_INTEGRATION_ID_KIOSK`, and `PAYMOB_INTEGRATION_ID_WALLET`.
- The package configuration requires a non-empty `PAYMOB_HMAC_SECRET` for every flow.
- Wallet flow only: `PAYMOB_TEST_WALLET_MSISDN=01010101010`, the approved public test input.
- Transaction Inquiry additionally requires `PAYMOB_TEST_TRANSACTION_ID`, a positive existing Transaction ID supplied locally. All flows still load all three integration IDs because the current package configuration contract requires them. Transaction Inquiry does not use an integration ID to create its request and does not require `PAYMOB_TEST_WALLET_MSISDN`.

Run one flow at a time from the repository root:

```sh
php tools/provider-verification/auth.php
php tools/provider-verification/order.php
php tools/provider-verification/payment-key.php card
php tools/provider-verification/payment-key.php kiosk
php tools/provider-verification/payment-key.php wallet
php tools/provider-verification/kiosk.php
php tools/provider-verification/wallet.php
php tools/provider-verification/transaction-inquiry.php
```

## Create Intention contract probe

The verification-only `intention` scenario reads only `PAYMOB_SECRET_KEY`,
`PAYMOB_INTEGRATION_ID_CARD`, `PAYMOB_TEST_NOTIFICATION_URL`, and
`PAYMOB_TEST_REDIRECTION_URL`. It targets `POST
https://accept.paymob.com/v1/intention/` directly with Token authorization. It
does not execute Auth, Order, Payment Key, or another scenario. Configure the
three local values in `.env`; placeholders in `.env.example` are not usable
provider credentials or callback architecture decisions.

The command creates one synthetic 15000 EGP cents Card Intention and can create
provider-side state. Run it only after direct Lead review and separate explicit
authorization for the exact provider call:

```sh
php tools/provider-verification/intention.php
```

`PAYMOB_SECRET_KEY`, Authorization values, client secrets, synthetic billing
data, and private callback URL data are excluded from sanitized artifacts and
diagnostics. A successful response must be HTTP 201 and satisfy the prepared
response contract. The reported `special_reference_matches` boolean proves
correlation without exposing the reference. This tooling is a transitional
verification-only contract probe; it is not package runtime behavior or a
public request builder.

`payment-key.php` requires exactly one explicit method. The other payment flows select their current integration ID from configuration. `wallet.php` initiates the current wallet flow only; it does not submit an OTP or follow a redirect.
`transaction-inquiry.php` authenticates and reads only the configured Transaction ID with `GET /api/acceptance/transactions/{id}` and Bearer authorization. It creates no order or payment. Run it only after separate Lead authorization for a real provider call.

## Evidence and security

Raw request and response bytes are written to a private run directory under the operating-system temporary directory, guarded to remain outside the repository. Files and directories use owner-only permissions. A fully validated sanitized report is persisted under the separate `maatify-paymob-provider-verification/sanitized/` directory with `0700` directory and `0600` file permissions. The harness verifies the saved report's byte count, SHA-256, read-back, and JSON validity before deleting raw evidence. Successful sanitized artifacts remain available for handoff and review; stdout contains a concise summary with the artifact path and hash. If verification fails before a safe artifact is ready, raw evidence is retained outside Git for local diagnosis. If raw cleanup fails after artifact verification, both the sanitized artifact and remaining raw evidence are retained and the diagnostic identifies their paths and the failing stage.

Failures after capture first persist a sanitized failure diagnostic in that same private artifact directory, then print a concise handoff with its path, byte count, and SHA-256. Engine exception trace arguments are disabled before package loading, and deprecation diagnostics use a short argument-free STDERR handler. Failure artifacts retain captured attempt metadata and raw file hashes, never raw request or response bodies.

An existing retained run can be converted into a sanitized report without a provider request:

```sh
php tools/provider-verification/recover.php <absolute-run-directory> wallet
php tools/provider-verification/recover.php <absolute-run-directory> kiosk
php tools/provider-verification/recover.php <absolute-run-directory> payment-key card
php tools/provider-verification/recover.php <absolute-run-directory> transaction-inquiry
php tools/provider-verification/recover.php <absolute-run-directory> intention
```

Offline recovery supports `wallet`, `kiosk`, Intention, Transaction Inquiry, standalone Card Payment Key recovery with an
explicit `card` method. It makes no provider request. It reads a source run in place, writes a sanitized recovery
artifact, and does not delete or modify the retained raw run. Runtime-only metadata absent from raw files is reported
as unavailable rather than inferred. Intention recovery accepts exactly one retained request to
`https://accept.paymob.com/v1/intention/`, validates the synthetic request contract, and checks matching
`special_reference` values when both are present. Since the raw triplet omits HTTP status and retained headers, it
reports provider outcome and authorization-value verification as unavailable; it does not infer a 201 response.

Reports preserve response field names, JSON shape, scalar types, nulls, and provider-semantic values. Known secrets, private PII, and account-specific identifiers are replaced with type-compatible placeholders; repeated IDs and references use stable mappings within a capture session. A leak guard fails closed if configured or detected sensitive values remain. Do not copy raw evidence into the repository, a fixture, a log, or a review comment.

Run the network-free sanitizer check with:

```sh
php tools/provider-verification/self-check.php
```
