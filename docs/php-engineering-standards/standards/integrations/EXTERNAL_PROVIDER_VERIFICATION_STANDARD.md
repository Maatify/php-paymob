# External Provider Verification Standard

## Standard Metadata

- **Standard ID:** `std-external-provider-verification`
- **Standard Version:** `1.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`
- **Version Assignment:** `OWNER-APPROVED BOOTSTRAP VERSION`

This Standard owns verification against externally controlled providers and the associated evidence and fixture lifecycle. It applies across provider domains, including Payment, SMS, Email, WhatsApp, KYC, and Shipping, without prescribing a provider implementation.

## 1. Applicability and Ownership

This Standard applies when the Scope itself is a **Provider Contract Owner**: it implements or owns a provider-specific contract such as endpoint/method selection, authentication, signature/HMAC, request serialization, response mapping, provider status/error semantics, retry/idempotency, or webhook/callback semantics.

Applicable Scopes include provider-specific Packages, Adapters, Base Modules, and a Host that owns such a contract directly. Merely using HTTP or a generic Mail/SMS abstraction does not establish Provider Contract Ownership. A Host consuming a provider Package solely through its public API does not become the owner of the provider contract owned by that Package; any provider-specific contract independently owned by the Host is assessed separately.

Profile composition supplies a Candidate Standard Reference. Canonical applicability in this section determines whether this Standard enters the Final Resolved Applicable Standards Set under `STANDARDS_ADOPTION_STANDARD_AR.md`; composition alone does not widen applicability.

`TESTING_STANDARD.md` retains ownership of Unit, Integration, System/E2E, Consumer Verification Harness, and regression protection. `CI_WORKFLOW_STANDARD.md` retains reusable Package/Base Artifact CI mechanics under its existing applicability. This Standard owns current-truth provider verification and provider evidence/fixture lifecycle; it does not define another testing layer, runtime architecture, Composer dependency policy, or Host CI architecture. `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` retains general documentation roles, freshness, language, and retention semantics. These canonical references are text-only because their local composition is not guaranteed for every independently applicable Scope.

## 2. Normative Language

**MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**, and **MAY** express mandatory requirements, expected defaults, and permissions in the sense used by the Maatify Standards.

## 3. Service Control Boundary

Classification MUST follow control over the service lifecycle, not merely whether the service is local or remote.

| Boundary | Characteristics | Verification consequence |
|---|---|---|
| Repository-controlled / provisionable integration service | The Repository/CI can deterministically provision, configure/version, health-check, reset, isolate, clean up, and destroy it. | Applicable real-service Integration testing uses its supported service under the applicable Testing and CI contracts. |
| Externally controlled provider | The service is outside that deterministic lifecycle and may depend on provider availability, account state, credentials, quota/cost, remote side effects, provider-controlled state, or contract drift. | Deterministic contract verification and controlled live verification have distinct evidence and execution boundaries. |

A remotely provisioned repository-controlled service can satisfy the first boundary. A provider sandbox remains an externally controlled provider when its account, state, availability, or side effects remain outside that lifecycle. A local fake of a provider does not establish actual provider execution.

## 4. Verification Concerns

The verification model MUST distinguish three concerns, rather than introduce three Testing Layers:

1. **Deterministic Provider-Contract Verification** protects the implemented contract repeatably.
2. **Controlled Live Provider Verification** checks the actual externally controlled provider within an authorized execution boundary.
3. **Evidence / Fixture Lifecycle** controls evidence safety, semantics, provenance, and explicit promotion.

Live provider verification MUST NOT replace deterministic testing or be represented as deterministic regression protection. Deterministic results MUST NOT be represented as proof of current remote truth.

### 4.1 Deterministic Provider-Contract Verification

The Scope MUST maintain repeatable, automatable verification of its applicable provider-specific contract. Coverage MUST follow the actual contract and include relevant request construction/serialization, response/error/status mapping, signature/HMAC, retry/idempotency, promoted fixtures, official examples, and synthetic contract cases. Sanitization/leak guards, provenance validation, and maintained provider-verification tooling logic affecting safety or evidence MUST also be deterministically verifiable.

Applicable deterministic provider-contract regression protection MUST remain automated under the Testing contract and applicable CI enforcement. Neither external-provider unavailability nor an account blocker authorizes omission or a silent skip of required deterministic verification.

### 4.2 Controlled Live Provider Verification

Live verification executes against the actual provider. It MUST be intentional, authorized, bounded, safe, and traceable. A repository MAY use a local command or a protected/manual/scheduled workflow; this Standard prescribes no command, workflow filename, or scheduling interval.

Live-provider execution MUST NOT be required baseline PR CI. Baseline CI MUST NOT depend on live provider availability, provider production credentials, remote account/quota/cost state, or uncontrolled external side effects. Controlled live verification is a separate gate when required under Section 5; separation MUST NOT become a silent skip or a claim that an outstanding live obligation passed.

## 5. When Live Verification Is Required

Controlled live verification MUST be performed on any of the following triggers, when all three conditions below hold:

- initial establishment of a materially live-verifiable contract;
- first conformance/readiness assessment of an already-existing materially live-verifiable Provider Contract when no accepted current live proof covers its current contract state; or
- material invalidation of previously accepted live proof.

The three conditions remain:

- the Scope owns the provider-specific contract;
- real provider execution adds material evidence that deterministic verification alone cannot obtain; and
- a safe authorized execution path exists.

No redundant live call is required merely because this Standard is being adopted or assessed when accepted, current, traceable, applicable live-provider evidence already covers the same current contract state and no material invalidation has occurred. An existing materially live-verifiable Provider Contract without such proof MUST NOT receive an indefinite exemption from first-conformance/readiness assessment.

Live calls are neither required for every integration nor always optional. The Scope MUST make the applicability assessment and its evidence or outstanding limitation discoverable. Where a materially needed live proof lacks a safe authorized path, execution MUST fail closed; the missing proof MUST remain explicit and MUST NOT be reported as verified.

Inbound webhook/HMAC/callback contracts, destructive scenarios, or unavailable scenarios MAY instead use suitable official, promoted, or synthetic evidence when a live call adds no meaningful proof or cannot be executed safely. The evidence MUST state what it proves and its limitations; an alternative source MUST NOT be presented as a real observation. Appropriate deterministic protection remains required.

## 6. Pre-Network Contract and Production Behavior

Before network execution, verification MUST target a contract derived from the current implementation, official provider documentation, applicable contracts, and accepted prior evidence where relevant. The expected request, response/status/error semantics, and scenario boundary MUST be known before the run. Missing or conflicting material contract facts MUST remain unresolved rather than guessed through calls.

An ordinary verification run MUST NOT be trial-and-error API discovery. Exploratory investigation, if needed, is a separate concern requiring its own scope and authorization.

Verification MUST reuse actual production behavior where applicable, including services, DTOs, request builders, serialization, response/error/status mappings, and production-defined retries. A verification-only capture/observation seam MAY be used, but MUST NOT reconstruct an independent business request contract instead of exercising production code.

Production-defined retry behavior MAY be verified within the authorized bounds. Verification tooling MUST NOT invent an additional retry policy to obtain success. Transport success alone MUST NOT establish a provider-contract match; verification MUST assess the provider's actual semantic result against the pre-derived expectation.

## 7. Side-Effect Safety

Before real-provider execution, the following MUST be known and controlled where applicable:

- provider environment/account and scenario;
- destination/recipient;
- side-effect class;
- bounded request and retry count, including production-defined retries;
- cost/quota implications; and
- cleanup/reversal behavior where possible, including any irreversible residual effect.

Authorization MUST cover the actual environment, scenario, and side-effect boundary. Execution MUST fail closed when no safe authorized path exists, and MUST stop if continued calls would exceed that boundary. A hazardous call MUST NOT be made merely to claim compliance. The run's revision/ref, scenario, relevant environment, execution date, outcome, and evidence limitations MUST be traceable without exposing sensitive values.

## 8. Evidence States and Explicit Fixture Promotion

The lifecycle MUST distinguish:

```text
Raw Provider Evidence
≠ Sanitized Evidence
≠ Promoted Regression Fixture
```

Raw capture is not mandatory. If retained, raw evidence MUST remain private, MUST NOT enter Git, and MUST NOT expose sensitive content through ordinary PRs, comments, logs, or reports. Retention and disposition MUST be bounded. Capture and reporting MUST prevent credentials, secrets, and sensitive recipient/customer data from leaking through incidental output.

Sanitized evidence is not automatically a fixture. Promotion MUST follow a reviewable process:

```text
evidence
→ sanitize when required
→ semantic validation
→ leak validation
→ review
→ explicit promotion
```

Only evidence whose relevant semantics and leak safety have been validated may be explicitly promoted for regression use. A provider response MUST NOT automatically overwrite fixtures. Live-run success alone does not authorize promotion, and a sanitized candidate MUST remain distinguishable from a promoted fixture until that process completes.

### 8.1 Semantic Sanitization

When sanitization is required, it MUST preserve contract-relevant field names, structure, types, nullability, boolean/enum/status semantics, relevant formats, and identifier relationships. Removing a secret MUST NOT erase the meaningful status or error being proven.

Repeated occurrences of an identifier within an evidence/promotion unit MUST retain the same synthetic identity when that relationship is part of the proof. Globally stable mappings between runs are not required. Replacement values MUST be format-compatible when their format is part of the contract. Variable values such as request IDs, timestamps, or temporary tokens MAY be normalized when they are not contract-relevant; sensitive tokens MUST still be removed or safely replaced.

### 8.2 Fixture Sources and Provenance

Permitted source kinds include:

- `REAL_PROVIDER_CAPTURE` — evidence observed from actual provider execution;
- `OFFICIAL_PROVIDER_EXAMPLE` — an official provider example; and
- `SYNTHETIC_CONTRACT_CASE` — a constructed case for the declared contract.

Official or synthetic evidence MUST NOT be labeled as real observed evidence. A promoted fixture or fixture set MUST have discoverable provenance appropriate to its source and proof, including:

- provider and capability/scenario;
- source kind;
- source/retrieval/verification date where applicable;
- verified repository revision/ref where applicable;
- provider environment when material;
- a safe evidence integrity reference when relevant; and
- `contains_real_secrets = false`, supported by leak validation.

Provenance MUST preserve the distinction between what was observed, retrieved, and constructed. An integrity reference MUST NOT disclose sensitive source evidence. No global sidecar filename, storage format, fixture directory, or integrity algorithm is required.

## 9. Outcomes, Drift, and Failure Boundaries

Controlled Live Provider Verification outcomes MUST use at least the following outcome classification. Classification or recovery of retained live-provider evidence MUST also use it when that evidence is being interpreted as a provider-verification outcome. Provider-specific subtypes MAY supplement it.

This taxonomy is not required for ordinary Unit, Integration, System/E2E, PHPUnit pass/fail, or deterministic fixture regression results. Ordinary deterministic tests retain their normal test-runner semantics.

| Outcome | Meaning |
|---|---|
| `MATCH` | Observed evidence matches the pre-derived contract for the verified scenario and boundary; it does not prove unexecuted capabilities. |
| `LOCAL_IMPLEMENTATION_DEFECT` | Evidence establishes a defect in the local implementation against the applicable provider contract. |
| `PROVIDER_DRIFT` | Evidence establishes a provider-side contract change or incompatibility with the accepted expectation. |
| `ACCOUNT_OR_CONFIGURATION_BLOCKER` | Account, credentials, quota, or configuration prevents the intended proof. |
| `TRANSPORT_OR_ENVIRONMENT_FAILURE` | Transport or execution environment prevents the intended proof. |
| `VERIFICATION_HARNESS_FAILURE` | Verification tooling, capture, validation, or reporting fails to produce reliable proof. |
| `UNRESOLVED` | Evidence is insufficient or conflicting; attribution cannot yet be established. |

Classification MUST be evidence-backed. A mismatch alone MUST NOT be guessed into provider drift or a local defect. A blocker or failed proof MUST NOT be reported as `MATCH`. Expected provider rejection MAY match a deliberately verified failure contract when its semantics actually satisfy that scenario; it is not proof of success for another scenario.

Provider mismatch MUST NOT authorize automatic runtime remediation or automatic fixture rewriting. Resolution and promotion require their respective authorized, reviewable changes; this Standard grants no runtime mutation authority.

## 10. Freshness and Material Re-Verification

This Standard imposes no universal time-based evidence TTL. Live re-verification MUST be reassessed on a material invalidation trigger, including a relevant provider API, authentication, endpoint, request/response, signature, retry/idempotency, or official-contract change, or provider-related incompatibility evidence. When the conditions in Section 5 hold, the affected live proof MUST be renewed.

Unrelated documentation, release metadata, or an internal refactor that does not affect the provider contract does not itself require live re-verification. Historical evidence remains tied to its verified revision, scenario, and boundary; it MUST NOT silently establish current truth after material invalidation.

Provider capabilities and their verification/evidence state MUST be discoverable from repository-owned current-state surfaces when needed to assess applicability, readiness, or invalidation. No separate Provider Coverage Register file is required. A fixture set proves its declared regression cases, not universal live capability coverage.

## 11. Recovery and Maintained Verification Logic

Recovery MAY exist when retained evidence is sufficient and repeating a costly, side-effecting, or limited provider call would be unnecessary. Recovery is not mandatory. If implemented, it MUST be network-free, MUST NOT alter source evidence or fabricate missing facts, and MUST preserve provenance. Recovery MUST NOT be represented as a new live observation or fill missing proof with assumptions.

Maintained verification logic affecting safety or evidence MUST be deterministically verifiable using repository-selected tooling. This Standard does not mandate a self-check or recovery script, class names, capture architecture, one script per scenario, JSON reports, or a particular Composer entry point. Contracts and observable verification behavior govern compliance.

## 12. Version History

### `1.0.0`

- Bootstrap the provider-control boundary, conditional live verification, evidence safety, explicit fixture promotion, outcome classification, and material-trigger re-verification contract.
