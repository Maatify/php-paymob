# DEC-002: Paymob Public Capability / Multi-Capability Source Topology

- **Status:** ACTIVE
- **Date:** 2026-10-04
- **Decision Authority:** Owner
- **Scope / Concern:** Package capability boundaries and canonical source topology
- **Canonical Contract / Current Owner:** This record / Owner
- **Supersedes:** None
- **Superseded By:** None

## Context

`maatify/php-paymob` is Paymob-specific and must remain standalone, reusable, Host-agnostic, and framework-agnostic. It must work within any suitable Host/framework without knowledge of Maatify Host project internals or dependencies on Slim, Laravel, or Symfony internals or bindings.

The package is Pre-Stable. Its current legacy source placement does not create a compatibility entitlement that prevents canonical remediation before the first Stable release. The governing architecture owner is [Package Building Standard §5](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#5-directory-structure-inside-src), pinned as `std-package-building@3.0.2`.

## Decision

The approved canonical source topology is:

```text
Source Topology: Multi Capability

Capabilities:
- Authentication
- Order
- Payment
- Transaction
- Callback
```

These are exactly the canonical capabilities. Apply `Domain → Capability → Responsibility`; the package itself represents the Paymob domain, so a redundant `Paymob/` Domain directory must not be added.

The conceptual topology is:

```text
src/
├── Authentication/{Responsibility}/
├── Order/{Responsibility}/
├── Payment/{Responsibility}/
├── Transaction/{Responsibility}/
├── Callback/{Responsibility}/
└── package-wide responsibilities only when genuinely shared
```

Package-wide root responsibilities may include `Adapter/`, `Config/`, `Exception/`, `Enum/`, and `Facade/` only when they own a real responsibility shared across capabilities. Empty or ceremonial directories, including folders added solely for symmetry, are forbidden.

### Responsibility ownership

The following classifications apply to this package under the canonical vocabulary in Package Building Standard §5:

| Responsibility | Meaning |
|---|---|
| `Command/` | Action or mutation intent |
| `DTO/` | Result/data snapshot only |
| `Service/` | Orchestration |
| `Repository/` | Persistence/query boundary |
| `Adapter/` | Provider/HTTP/backend adaptation other than Repository persistence |
| `Config/` | Package configuration contract |
| `Facade/` | Optional thin public discovery/orchestration surface when actually justified |

### Card/VPC and provider scope

Card/VPC Payment Key generation is a **SUPPORTED PACKAGE CAPABILITY**. Card/VPC customer-facing checkout/pay execution is **NOT CURRENTLY A PACKAGE-OWNED RUNTIME CAPABILITY**. Do not invent `CardPaymentService`, `payViaCard`, or an iframe/hosted/embedded checkout execution boundary merely to complete the topology. Any future Card customer-facing execution capability is **NEW SCOPE** and requires its own contract, decision, and review when introduced.

**php-paymob is NOT a generic payment-provider abstraction.** Do not introduce `PaymentProviderInterface`, `GenericGateway`, `ProviderRegistry`, or another generic provider architecture merely to support other providers.

## Rationale

The five capabilities express the retained Paymob responsibilities while keeping persistence and transport ownership discoverable. Framework-neutral boundaries permit reuse by different Hosts without acquiring Host internals. The conceptual topology preserves the approved Card/VPC limitation and avoids creating unsupported runtime capabilities for structural symmetry.

## Consequences

Phase 5 MUST reconcile the actual implementation with this decision:

- Review current action-intent request DTOs and migrate them to `Command` where the Standard applies; result/response/data snapshots remain DTOs.
- Place provider/HTTP transport responsibility under `Adapter`, rather than a generic `Http` architecture root.
- Place token persistence under `Authentication/Repository`.
- Place Transaction Processed Callback and Transaction Response / Return URL responsibilities under `Callback`.
- Retain package-owned exception/config/facade and other root responsibilities only where ownership is genuinely shared across capabilities.

This decision establishes capabilities, topology, and responsibility ownership. It does not establish the exact final PHP symbol inventory or namespace migration result, and proposed class names must not be presented as implemented stable APIs. Current implementation is not ratified by this decision; actual compliance evidence remains pending implementation.

Under [Package Building Standard §3](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#3-required-files), the stable public Runtime API inventory and actual governing topology belong in the root `PAYMOB_PACKAGE_REFERENCE.md` when Phase 5 implements the corresponding contract. That Reference must be created/synchronized atomically with the actual implemented contract before accepting the implementation. It is not created by this decision-persistence Work Unit.
