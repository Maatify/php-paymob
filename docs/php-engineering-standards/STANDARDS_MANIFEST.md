# Standards Manifest

## Resolution

This is the completed Selective Pinned Upgrade record. It records adoption resolution, not package implementation compliance or release acceptance.

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Adoption Commit:** `2ea426aee0e6a5265f0c30ced667f21bb7b1d302`
- **Adoption Date:** `2026-10-04`
- **Overall Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Stage 1 Structural / Transitive Resolution:** `PASS`
- **Stage 2 Canonical Standard Applicability:** `PASS`
- **Final Local Reference Closure:** `PASS`
- **Frozen Profile Baseline Verification:** `PASS`

## Pinned Adoption Control Set

The Control Set contains exactly these three files from the exact Adoption Commit above. Neither active Profile inherits another Profile; no unused or inherited Profile is copied.

| Local pinned file | Exact upstream Git blob |
| --- | --- |
| [STANDARDS_ADOPTION_STANDARD_AR.md](standards/STANDARDS_ADOPTION_STANDARD_AR.md) | `d84856f7cd50112b32d6843989700a819e6bad98` |
| [COMPOSER_PACKAGE_PROFILE.md](standards/profiles/COMPOSER_PACKAGE_PROFILE.md) | `9c7bf1952372c59d50bb62daa03ce45729d1ebc4` |
| [REPOSITORY_GOVERNANCE_PROFILE.md](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md) | `ef7e13313e804cb6f02785dbff667b58119176b1` |

## Profile Activations and Resolution

### `composer-package@4.0.0` — Scope `/`

- **Stage 1:** `PASS`; `Extends: None`; no inherited Profiles or inheritance cycle. All nine direct Required Standard references exist at the exact Adoption Commit and resolve from the pinned local Profile.
- **Candidate Standard References:** `std-package-building`, `std-composer-package`, `std-ci-workflow`, `std-library-presentation`, `std-testing`, `std-external-provider-verification`, `std-documentation-lifecycle`, `std-php-source-documentation`, `std-php-coding-style`.
- **Stage 2:** `PASS`; all nine Candidates are applicable under their canonical contracts to this standalone reusable PHP Composer library at `/`. Package-owned SQL/MySQL token persistence activates the conditional persistence/database requirements. The package owns the Paymob provider contract, making `std-external-provider-verification` applicable directly through this Profile.
- **Resolution Status:** `VALID`.
- **Exception State:** `NONE`.

### `repository-governance@3.0.0` — Scope `/`

- **Stage 1:** `PASS`; `Extends: None`; no inherited Profiles or inheritance cycle. All four direct Required Standard references exist at the exact Adoption Commit and resolve from the pinned local Profile.
- **Candidate Standard References:** `std-ai-collaboration-workflow`, `std-github-phase-stack-workflow`, `std-documentation-lifecycle`, `std-decision-governance`.
- **Stage 2:** `PASS`; all four Candidates are applicable under their canonical contracts to repository-owned collaboration, Phase workflow, durable documentation, and decision governance at `/`. `std-documentation-lifecycle` is shared with the Composer activation.
- **Resolution Status:** `VALID`.
- **Exception State:** `NONE`.

## Canonical Applicability Evidence

The root is a package-only repository, not a deployable Host/Application. The following artifact facts determine Stage 2 independently of Profile composition:

| Candidate Standard | Canonical applicability and artifact evidence at `/` |
| --- | --- |
| `std-package-building` | Reusable PHP/Composer library; package-owned SQL/MySQL token persistence also activates its conditional database requirements. |
| `std-composer-package` | Standalone reusable PHP Composer library with its own root `composer.json`. |
| `std-ci-workflow` | Reusable Composer Package whose repository owns artifact verification and CI. |
| `std-library-presentation` | Standalone consumer-facing PHP Package repository owning its documentation and presentation surfaces. |
| `std-testing` | Repository-owned implemented behavior, public workflows, and integration boundaries require regression protection. |
| `std-external-provider-verification` | Provider Contract Owner: Paymob endpoints, authentication, request/response mapping, error/status semantics, HMAC/Webhook/Return URL, and retry/auth-recovery semantics. |
| `std-documentation-lifecycle` | Package and Repository Governance own durable consumer, technical, decision, and current-state adoption documentation. |
| `std-php-source-documentation` | Repository-owned, manually maintained PHP source exists within the activated scope. |
| `std-php-coding-style` | Repository-owned, manually maintained runtime, test, example, and tooling PHP exists within the activated scope. |
| `std-ai-collaboration-workflow` | Repository Governance owns collaboration, execution authority, evidence, and review. |
| `std-github-phase-stack-workflow` | Repository Governance owns governed branches, PRs, Phases, and integration boundaries. |
| `std-decision-governance` | Repository is subject to Maatify repository governance and owns durable engineering decision scope. |

## Final Resolved Applicable Standards Set

The union of both activations contains exactly **12 unique applicable Standards**. Every file below is byte-identical to its blob at the exact Adoption Commit.

| Standard ID | Version | Local pinned file | Exact upstream Git blob |
| --- | --- | --- | --- |
| `std-package-building` | `3.0.2` | [PACKAGE_BUILDING_STANDARD.md](standards/packages/PACKAGE_BUILDING_STANDARD.md) | `1475bb006eefe9e1ef9a6973f6664c3d0690dc2c` |
| `std-composer-package` | `4.1.0` | [COMPOSER_PACKAGE_STANDARD.md](standards/packages/COMPOSER_PACKAGE_STANDARD.md) | `d020e3f4b09830338b4a1647851048e02c64252e` |
| `std-ci-workflow` | `4.0.0` | [CI_WORKFLOW_STANDARD.md](standards/packages/CI_WORKFLOW_STANDARD.md) | `81f63475c89a1e85790acfaef885a25049518399` |
| `std-library-presentation` | `4.0.0` | [LIBRARY_PRESENTATION_STANDARD.md](standards/packages/LIBRARY_PRESENTATION_STANDARD.md) | `25d4d75d7f23934b3e204a28f1dbc7d06ca63c04` |
| `std-testing` | `2.0.0` | [TESTING_STANDARD.md](standards/testing/TESTING_STANDARD.md) | `587821a939976b3d7d87c2e8c9c7cfbbba56d7af` |
| `std-external-provider-verification` | `1.0.0` | [EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md](standards/integrations/EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md) | `44a2f4d55c2624b5e52035523d36c02f7d2f0910` |
| `std-documentation-lifecycle` | `3.0.0` | [DOCUMENTATION_LIFECYCLE_STANDARD_AR.md](standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md) | `9b0c73f5043c922bceaeac7d824d421045f77247` |
| `std-php-source-documentation` | `1.0.0` | [PHP_SOURCE_DOCUMENTATION_STANDARD.md](standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md) | `92864c0a696af4b571bb9ba8fdd527be9d076d33` |
| `std-php-coding-style` | `1.0.1` | [PHP_CODING_STYLE_STANDARD.md](standards/php/PHP_CODING_STYLE_STANDARD.md) | `a4cfae0200db193b9d1a8407f1c034345f3265e7` |
| `std-ai-collaboration-workflow` | `10.0.0` | [AI_COLLABORATION_WORKFLOW_AR.md](standards/ai/AI_COLLABORATION_WORKFLOW_AR.md) | `21a9be9697034e9f9d5bc856bd706d2af00bc8ab` |
| `std-github-phase-stack-workflow` | `4.0.0` | [GITHUB_PHASE_STACK_WORKFLOW_AR.md](standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md) | `f96f94a7be5a90caea629ad4fa5051819084859c` |
| `std-decision-governance` | `1.0.0` | [DECISION_GOVERNANCE_STANDARD_AR.md](standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) | `2c21ee8b3611eb234641bb3c9640ee3ccc756e5f` |

## Final Local Reference Closure

- **Result:** `PASS`; all 67 local relative Markdown references in the three Control files and twelve Applicable Standard files resolve within those two sets.
- No Reference Support Set, non-applicable Standard, or extra Profile is used to repair a link.
- All 15 pinned file blobs match the exact Adoption Commit. Files already byte-identical to the target are preserved without rewriting them to manufacture a diff.

## Frozen Profile Version Baseline Evidence

- **Historical previous adoption only:** `5f872d3ef7da847cba3f82fee124a19c22f1c5c4`. It is not the current Adoption Commit or target Profile baseline.
- **`composer-package@4.0.0`:** Lead preflight supplied for this upgrade found no known prior Completed VALID consumer adoption of this version on Maatify default branches. This completed `VALID` upgrade establishes its frozen artifact as `9c7bf1952372c59d50bb62daa03ce45729d1ebc4` at Adoption Commit `2ea426aee0e6a5265f0c30ced667f21bb7b1d302`, under Adoption Standard §5.1.1. No contradictory baseline evidence was found during this upgrade.
- **`repository-governance@3.0.0`:** Previously frozen by the Lead-supplied Completed VALID adoption evidence for `Maatify/php-rate-limiter` at Adoption Commit `f9048d9d75395244fa4af26b55e6b85ff0a898c6`. Its frozen blob is `ef7e13313e804cb6f02785dbff667b58119176b1`. The previous local adoption, exact previous upstream adoption, and exact new upstream adoption all use that same artifact byte-for-byte.
- **Frozen Profile Baseline Verification:** `PASS`; no known frozen-version/content mismatch.

## Governing Baseline and Compliance Boundaries

[Composer Package Standard §15.4](standards/packages/COMPOSER_PACKAGE_STANDARD.md#154-optional-runtime-capability-dependencies), now pinned as `std-composer-package@4.1.0`, permits Optional Runtime Capability Dependencies only when all applicable architectural, declaration, and verification conditions are proven. Adoption establishes this conditional permission in the governing baseline; it does not establish that `php-paymob` already complies with an optional MySQL implementation model.

Actual compliance evidence for `MySqlTokenRepository`, `TokenRepositoryInterface`, Composer `suggest`, an explicit fail-closed unavailable state, Package Reference disclosure, and verification prerequisites remains separate subsequent work. This adoption changes no runtime or Composer manifest and records no DEC-002/003/004 decision.

`std-external-provider-verification@1.0.0` is **APPLICABLE**, supplied directly by `composer-package@4.0.0`; it is not an Additional Standard. Detailed conformance assessment of existing provider-verification tooling follows in an independent Work Unit after adoption merge/reconciliation. No provider call, harness redesign, or fixture change is part of this completed adoption.

## Additional Standards and Exceptions

- **Explicit Additional Standards:** `NONE`.
- **Exceptions / Overrides:** `NONE`.
