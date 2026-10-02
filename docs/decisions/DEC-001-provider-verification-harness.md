# DEC-001: Maintained Real Paymob Provider Verification Harness

- **Status:** ACTIVE
- **Date:** 2026-10-02
- **Decision Authority:** Owner
- **Scope / Concern:** Real Paymob provider verification architecture
- **Canonical Contract / Current Owner:** This record / Owner
- **Supersedes:** None
- **Superseded By:** None

## Context

The repository needs repeatable evidence for real Paymob provider flows that have already been exercised and reviewed. Disposable verification runners have lost captured evidence, so later verification could not produce a reliable semantic fixture. This work confirms existing package behavior against the provider; it is not trial-and-error API discovery.

The verification tooling needs to use the package's current services and request DTOs while exposing each request attempt and preserving raw exchange evidence securely enough for a Lead to review implementation and diagnose failures.

## Decision

Maintain the real Paymob provider verification harness under `tools/provider-verification/` in this repository.

The harness:

- is repository-owned provider verification tooling, not a Unit Test suite and not the Consumer Verification Harness;
- uses the current package services and request DTOs for provider business flows, with a verification-only transport adapter for capture and transport evidence;
- confirms a provider contract established before execution and must not be used for trial-and-error discovery;
- is saved in Git before its provider scripts are used; disposable provider runners are not used for maintained verification;
- keeps raw sensitive provider evidence outside the repository and never commits or pushes it;
- exposes reviewable scripts and failure-stage evidence so the Lead can inspect implementation when a run fails; and
- accumulates coverage for accepted and future provider flows in this canonical harness.

Provider execution is manual and explicit because flows may create provider-side orders or transactions. A verification run does not itself establish package acceptance; its scoped code and evidence remain subject to Lead review.

## Rationale

Maintained scripts keep the exact exercised package path reviewable and reusable. A separate capture transport provides request-attempt, response, TLS, and error evidence without changing package runtime behavior. Sanitized fixtures can preserve provider structure and meaningful semantics while excluding account-specific secrets and identifiers.

## Consequences

- New provider-flow verification belongs in `tools/provider-verification/` unless this decision is formally superseded.
- The harness remains outside automatic CI execution; an operator selects and runs a flow deliberately.
- Capture failures retain raw evidence only in a private operating-system temporary directory for local diagnosis. Successful capture removes raw evidence only after sanitization and leak checks pass.
- Provider calls, runtime remediation, and fixture promotion remain separate reviewable steps.
