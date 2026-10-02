# Paymob provider verification

This maintained CLI tooling verifies explicitly selected Paymob provider flows through the package's current services and request DTOs. The verification transport captures request and response evidence outside the repository and emits a sanitized report and response fixture candidate.

This tooling is separate from the Unit Test suite: it makes real HTTP requests to the Paymob sandbox and exercises provider behavior. It is also separate from the Consumer Verification Harness, which validates a package consumer's integration with a published package. These scripts use the current repository source.

## Before running a provider flow

Execution is manual and explicit. Each flow can create provider-side orders or transactions. Confirm the exact provider contract and approved scenario with the Lead before running a script; the scripts are for confirmation, not trial-and-error API discovery. They are not run automatically by CI.

Requirements:

- PHP 8.2 or later, Composer dependencies installed, and the cURL extension.
- A repository-local `.env` with `PAYMOB_API_KEY`, `PAYMOB_BASE_URL`, and `PAYMOB_HMAC_SECRET`.
- `PAYMOB_BASE_URL` normalizes to `https://accept.paymob.com/api`.
- The selected payment flow's integration ID: `PAYMOB_INTEGRATION_ID_CARD`, `PAYMOB_INTEGRATION_ID_KIOSK`, or `PAYMOB_INTEGRATION_ID_WALLET`.
- Wallet flow only: `PAYMOB_TEST_WALLET_MSISDN=01010101010`, the approved public sandbox test input.
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
```

`payment-key.php` requires exactly one explicit method. The other payment flows select their current integration ID from configuration. `wallet.php` initiates the current wallet flow only; it does not submit an OTP or follow a redirect.

## Evidence and security

Raw request and response bytes are written to a private run directory under the operating-system temporary directory, guarded to remain outside the repository. Files and directories use owner-only permissions. On a fully successful capture, decoding, sanitization, and leak-guard validation, raw evidence is removed. On a transport, decoding, mapping, or sanitization failure, raw evidence is retained outside Git for local diagnosis; output contains only a local path, byte counts, SHA-256 values, stage, and sanitized exception details. Inspect or remove retained evidence locally after diagnosis.

Reports preserve response field names, JSON shape, scalar types, nulls, and provider-semantic values. Known secrets, private PII, and account-specific identifiers are replaced with type-compatible placeholders; repeated IDs and references use stable mappings within a capture session. A leak guard fails closed if configured or detected sensitive values remain. Do not copy raw evidence into the repository, a fixture, a log, or a review comment.

Run the network-free sanitizer check with:

```sh
php tools/provider-verification/self-check.php
```
