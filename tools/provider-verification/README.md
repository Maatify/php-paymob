# Paymob provider verification

This maintained CLI tooling verifies explicitly selected Paymob provider flows through the package's current services and request DTOs. The verification transport captures request and response evidence outside the repository and saves a sanitized report and response fixture candidate.

This tooling is separate from the Unit Test suite: it makes real HTTP requests to the Paymob Egypt API and exercises provider behavior. Test and Live modes use the same API base URL; credentials and integration IDs determine the configured mode. It is also separate from the Consumer Verification Harness, which validates a package consumer's integration with a published package. These scripts use the current repository source.

## Before running a provider flow

Execution is manual and explicit. Each flow can create provider-side orders or transactions. Confirm the exact provider contract and approved scenario with the Lead before running a script; the scripts are for confirmation, not trial-and-error API discovery. They are not run automatically by CI.

Requirements:

- PHP 8.2 or later, Composer dependencies installed, and the cURL extension.
- A repository-local `.env` with `PAYMOB_API_KEY` and `PAYMOB_BASE_URL`.
- `PAYMOB_BASE_URL` normalizes to `https://accept.paymob.com/api`.
- `PAYMOB_HMAC_SECRET` is optional for Auth, Order, Payment Key, Kiosk, and Wallet verification. Webhook/HMAC verification requirements will be set when those contracts are in scope.
- The selected payment flow's integration ID: `PAYMOB_INTEGRATION_ID_CARD`, `PAYMOB_INTEGRATION_ID_KIOSK`, or `PAYMOB_INTEGRATION_ID_WALLET`.
- Wallet flow only: `PAYMOB_TEST_WALLET_MSISDN=01010101010`, the approved public test input.
- Transaction Inquiry only: `PAYMOB_TEST_TRANSACTION_ID`, a positive existing Transaction ID supplied locally. No integration ID or Wallet MSISDN is needed.
- Optional: `PAYMOB_KEYS_EXPIRY`, consumed by the current package's local token-expiry calculation.

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
```

Offline recovery supports `wallet`, `kiosk`, Transaction Inquiry, and standalone Card Payment Key recovery with an
explicit `card` method. It makes no provider request. It reads a source run in place, writes a sanitized recovery
artifact, and does not delete or modify the retained raw run. Runtime-only metadata absent from raw files is reported
as unavailable rather than inferred.

Reports preserve response field names, JSON shape, scalar types, nulls, and provider-semantic values. Known secrets, private PII, and account-specific identifiers are replaced with type-compatible placeholders; repeated IDs and references use stable mappings within a capture session. A leak guard fails closed if configured or detected sensitive values remain. Do not copy raw evidence into the repository, a fixture, a log, or a review comment.

Run the network-free sanitizer check with:

```sh
php tools/provider-verification/self-check.php
```
