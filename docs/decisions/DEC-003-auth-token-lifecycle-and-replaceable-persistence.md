# DEC-003: Authentication Token Lifecycle / Replaceable Persistence

- **Status:** ACTIVE
- **Date:** 2026-10-04
- **Decision Authority:** Owner
- **Scope / Concern:** Paymob authentication-token lifecycle and replaceable persistence
- **Canonical Contract / Current Owner:** This record / Owner
- **Supersedes:** None
- **Superseded By:** None

## Context

The package needs a cache for short-lived Paymob authentication tokens. This persistence is an **authentication infrastructure cache**, not business/domain persisted state. It does not create a reporting domain or operational-read model. Operational Read / Reporting is **OUT OF SCOPE**, consistent with the ownership test in [Package Building Standard §15](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#15-operational-read--reporting-and-admin-query-api-rules), pinned as `std-package-building@3.0.2`.

Multiple consumers and Paymob configurations require isolation and replaceable persistence without making every consumer install or use one backend.

## Decision

### Semantic boundary and consumer choice

The package owns `TokenRepositoryInterface` as its technology-neutral, replaceable semantic persistence boundary. Services, especially Authentication orchestration, MUST depend on this boundary rather than PDO, MySQL, File, Redis, Mongo, Laravel Cache, Symfony Cache, or other framework-specific storage.

This decision establishes the boundary's semantic identity and responsibility. It does not ratify current method semantics merely because they exist: current unscoped `get()`/`save()` and global `clear()` behavior are not approved contracts. Phase 5 MUST reshape the exact pre-Stable method contract as necessary to achieve credential/configuration-scoped semantics.

Retain the built-in **InMemory**, **File**, and **MySQL/MariaDB** options. A consumer may supply a conforming `TokenRepositoryInterface` implementation using Redis, Mongo, framework cache, or other storage without changing package source. Backend selection is **CONSUMER CHOICE**; no backend is mandatory for all consumers.

### Token lifecycle and scope isolation

The approved legacy Paymob bearer-token lifetime is **60 minutes**. Renewal requires a **new Paymob Auth request**; no refresh-token contract is established for this flow. The final architecture MUST NOT use a hidden configurable provider lifetime, such as `PAYMOB_KEYS_EXPIRY`, to change the provider TTL.

Remediation MUST use the applicable Maatify Clock contract under [Package Building Standard §2](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#2-required-maatify-runtime-dependencies) and [§25](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#25-runtime-workflow-transactions-concurrency-and-clock) when a clock abstraction is required, rather than a direct `time()` dependency in the final compliant architecture.

Every token-cache operation MUST be scoped to the relevant Paymob credential/account/configuration scope. One account/configuration must never consume another's cached token. Cleanup and invalidation, including `clear()`, MUST be scope-local rather than global, whole-table, or across all accounts.

The exact opaque scope-identity representation remains a Phase 5 implementation concern. This record approves no secret-bearing storage key or scope-identity algorithm; implementation must satisfy isolation while protecting secrets.

### File persistence

Retain File persistence as a built-in option. Its target contract requires atomic writes, appropriate locking, private storage semantics, and explicit malformed/corrupt-state failure. An absent file means no cached token. Silent malformed-state acceptance, partial writes, and unsafe concurrent replacement are forbidden. This record establishes requirements without selecting an implementation algorithm.

### MySQL/MariaDB persistence

Retain built-in MySQL/MariaDB persistence inside `maatify/php-paymob`; do not create a companion package solely for MySQL. Apply [Package Building Standard §1](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#1-the-package-contract) and [§6](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#6-schema-rules): package-root `schema/`, direct PDO, no ORM, no external query builder, no Host foreign keys, and no Host JOINs.

The canonical table prefix is `maa_paymob_`, applying `maa_{package_short_name}_`. The target persistence contract requires credential/configuration scope isolation, unique/current token semantics, atomic current-token replacement/upsert semantics, and scope-local cleanup. Current `paymob_tokens` placement/naming and table-wide deletion are not ratified by their existence.

### Optional runtime capability model

MySQL/PDO is an **OPTIONAL RUNTIME CAPABILITY**, rather than a mandatory core installation dependency. This is the approved target dependency model, governed by [Composer Package Standard §15.4 — Optional Runtime Capability Dependencies](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md#154-optional-runtime-capability-dependencies), pinned as `std-composer-package@4.1.0`.

Omission of any directly used prerequisite from Composer `require` is permitted only after Phase 5 proves **all applicable conditions** in §15.4, including:

- Independent optionality: its absence does not prevent installation, core autoload, default use, or unrelated capabilities.
- A package-owned, technology-neutral, replaceable semantic contract from the first infrastructure implementation using this permission.
- No concrete backend coupling in Services/orchestration; the concrete implementation owns its technology-specific prerequisites.
- No technology leakage into the neutral contract.
- Host replaceability without source changes, subclassing a built-in backend, or dependency on its technology or an unrelated backend.
- No eager requirement during bootstrap, core/unrelated autoload, or default construction.
- An explicit, intentional, fail-closed unavailable state when the built-in capability is selected without its prerequisites.
- Semantic growth through the same neutral contract for additional backends providing the same semantics.

A prerequisite also required by a mandatory runtime path remains in `require`. For every eligible omitted prerequisite, both Package Reference disclosure and an exact Composer `suggest` entry identifying the prerequisite and built-in capability are REQUIRED. If the implementation remains PDO/MySQL-based, the expected prerequisites are `ext-pdo` and `ext-pdo_mysql`, each disclosed separately when omitted.

`require-dev` may provision optional prerequisites for actual repository verification, but it is not sufficient consumer disclosure by itself. This record neither changes `composer.json` nor claims current compliance with §15.4. Actual package compliance evidence remains pending Phase 5 implementation.

## Rationale

A neutral semantic boundary lets each Host select persistence without exposing storage technology to Authentication orchestration. Scope-local lifecycle operations prevent cross-account reuse and invalidation. Retaining all three built-in backends preserves supported consumer choices; conditional optional dependencies avoid imposing MySQL prerequisites on unrelated consumers while requiring explicit availability and disclosure contracts.

## Consequences

Phase 5 MUST reconcile repository method semantics, scope isolation, token lifetime/Clock use, File safety, MySQL schema/current-token behavior, and optional-capability eligibility and disclosure with this record. Exact storage algorithms and method signatures remain implementation work within these requirements. Current implementation is not ratified by this decision.

This Work Unit creates no runtime implementation, schema, Composer declaration, tests, or Package Reference. The root `PAYMOB_PACKAGE_REFERENCE.md` must be created/synchronized atomically with the corresponding actual Phase 5 implementation under [Package Building Standard §3](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#3-required-files), before that implementation is accepted.
