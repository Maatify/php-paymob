# DEC-004: Provider Failure / Authentication Recovery Semantics

- **Status:** ACTIVE
- **Date:** 2026-10-04
- **Decision Authority:** Owner
- **Scope / Concern:** Paymob provider/API failure classification and bounded authentication recovery
- **Canonical Contract / Current Owner:** This record / Owner
- **Supersedes:** None
- **Superseded By:** None

## Context

The package needs a consistent Paymob-specific failure boundary across its built-in transports and a bounded authentication recovery policy for the first RC. Receiving a provider HTTP error and failing to obtain an HTTP response are distinct observable outcomes. Recovery must preserve credential/configuration isolation and must not become an unbounded or generic retry mechanism.

General exception hierarchy and marker requirements remain owned by [Package Building Standard §7 — Exception Rules](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#7-exception-rules), pinned as `std-package-building@3.0.2`, including its `maatify/exceptions` requirements in [§2](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#2-required-maatify-runtime-dependencies). This record owns only the Paymob-specific failure/recovery policy.

## Decision

### Failure classification

An HTTP response with **status >= 400** is a **Paymob provider/API failure**. HTTP `400` MUST NOT pass as success. Provider HTTP status/body evidence must remain available for typed API failure classification with secret-safe handling.

DNS failure, connection failure, TLS failure, timeout, and transport inability to obtain an HTTP response are **Transport/Network failures**. This boundary is separate from provider/API failure.

### Bounded 401 authentication recovery

The only approved automatic authentication recovery for the first RC is:

```text
authenticated Paymob operation carrying/using the legacy authentication token
→ receives 401
→ invalidate cached token for the SAME credential/configuration scope
→ obtain a fresh token through Paymob Auth
→ replay the original operation ONCE
```

The maximum replay count is **1**. Recursive retry, unbounded retry, and a second transparent retry are forbidden. If replay fails, propagate/classify that second failure normally. The Auth request itself must not enter a recursive self-retry loop. Scope-local invalidation follows [DEC-003](DEC-003-auth-token-lifecycle-and-replaceable-persistence.md).

There is no general automatic retry for other `4xx` responses. There is no blind automatic retry of mutation requests after `5xx` without a separate contract proving safety/idempotency. A generic retry policy must not be added to the first RC merely for convenience.

### Equivalent transport semantics and reclassification

Built-in transports, including cURL and Guzzle, MUST provide equivalent observable failure semantics when distinguishing provider HTTP failures from Transport/Network failures. Transport choice must not change this public package behavior.

Broad conversion of `GuzzleException` to `NetworkException` is **INVALID** when the exception represents an HTTP provider response. Broad service-level catch-all reclassification is **INVALID** when it erases the original failure class.

The package exception hierarchy, marker contract, wrapping/propagation rules, and `maatify/exceptions` requirements remain governed by Package Building Standard §7 and §2. This decision neither invents exception types nor replaces that Standard with a package-specific copy.

### Provider verification boundary

[External Provider Verification Standard](../php-engineering-standards/standards/integrations/EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md), pinned as `std-external-provider-verification@1.0.0`, owns the verification and evidence lifecycle. Provider verification confirms a pre-derived contract; trial-and-error calls must not determine retry/error behavior. [DEC-001](DEC-001-provider-verification-harness.md) remains ACTIVE and owns the maintained real Paymob verification harness. No Provider Call is required or executed by this decision-persistence Work Unit.

## Rationale

Separating HTTP provider failures from inability to obtain a response preserves useful diagnostics and consistent consumer behavior across transports. One same-scope 401 recovery bounds automatic execution while permitting a fresh authentication token. Restricting other retries avoids inventing mutation safety or idempotency guarantees.

## Consequences

Phase 5 MUST reconcile transport status handling, typed failure evidence, equivalent cURL/Guzzle semantics, service reclassification, and bounded same-scope authentication recovery with this record. Current implementation is not ratified by this decision; actual compliance evidence remains pending implementation and applicable verification.

This Work Unit implements no transport, exception, service, retry, or test changes and executes no Provider Call. The stable implemented behavior/exception guarantees belong in the root `PAYMOB_PACKAGE_REFERENCE.md` under [Package Building Standard §3](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#3-required-files), synchronized atomically with the corresponding actual Phase 5 implementation before acceptance. That Reference is not created here.
