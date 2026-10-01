# Standards Manifest

## Resolution

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Adoption Commit:** `5f872d3ef7da847cba3f82fee124a19c22f1c5c4`
- **Adoption Date:** `2026-10-01`
- **Overall Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Frozen Profile Baseline Verification:** `VALID`

## Pinned Adoption Control Set

All files below are pinned from the exact Adoption Commit above:

- `standards/STANDARDS_ADOPTION_STANDARD_AR.md`
- `standards/profiles/COMPOSER_PACKAGE_PROFILE.md`
- `standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md`

## Profile Activations and Resolution

### `composer-package@3.0.0` — Scope `/`

- **Stage 1:** `VALID`; `Extends: None`; no inherited Profile; all direct Required Standard references resolve.
- **Candidate Standard References:** `std-package-building`, `std-composer-package`, `std-ci-workflow`, `std-library-presentation`, `std-testing`, `std-documentation-lifecycle`, `std-php-source-documentation`, `std-php-coding-style`.
- **Stage 2:** All eight Candidates are applicable to this standalone reusable PHP Composer library. The persistence/database conditions in `std-package-building` apply to package-owned MySQL persistence behavior.
- **Resolution Status:** `VALID`.

### `repository-governance@3.0.0` — Scope `/`

- **Stage 1:** `VALID`; `Extends: None`; no inherited Profile; all direct Required Standard references resolve.
- **Candidate Standard References:** `std-ai-collaboration-workflow`, `std-github-phase-stack-workflow`, `std-documentation-lifecycle`, `std-decision-governance`.
- **Stage 2:** All four Candidates are applicable to the repository-owned collaboration/Phase workflow, durable package documentation, and governed repository decision scope. `std-documentation-lifecycle` is shared with the other Activation.
- **Resolution Status:** `VALID`.

## Resolved Applicable Standards Set

The union of applicable Standards for both activations is:

| Standard ID | Version | Local pinned file |
| --- | --- | --- |
| `std-package-building` | `3.0.2` | `standards/packages/PACKAGE_BUILDING_STANDARD.md` |
| `std-composer-package` | `4.0.0` | `standards/packages/COMPOSER_PACKAGE_STANDARD.md` |
| `std-ci-workflow` | `3.0.0` | `standards/packages/CI_WORKFLOW_STANDARD.md` |
| `std-library-presentation` | `4.0.0` | `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` |
| `std-testing` | `1.1.1` | `standards/testing/TESTING_STANDARD.md` |
| `std-documentation-lifecycle` | `3.0.0` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` |
| `std-php-source-documentation` | `1.0.0` | `standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md` |
| `std-php-coding-style` | `1.0.1` | `standards/php/PHP_CODING_STYLE_STANDARD.md` |
| `std-ai-collaboration-workflow` | `9.0.0` | `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` |
| `std-github-phase-stack-workflow` | `4.0.0` | `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` |
| `std-decision-governance` | `1.0.0` | `standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md` |

## Frozen Profile Version Baseline Evidence

Completed `VALID` Adoption evidence supplied by Lead:

- `Maatify/php-rate-limiter`, Adoption Commit `f9048d9d75395244fa4af26b55e6b85ff0a898c6`, Overall Resolution Status `VALID`, activates `composer-package@3.0.0` and `repository-governance@3.0.0`.
- Frozen `COMPOSER_PACKAGE_PROFILE.md` blob: `6ec9aae66b9a0dc5c33b17fc45d1e1a4ddfe0d81`.
- Frozen `REPOSITORY_GOVERNANCE_PROFILE.md` blob: `ef7e13313e804cb6f02785dbff667b58119176b1`.
- At Adoption Commit `5f872d3ef7da847cba3f82fee124a19c22f1c5c4`, the exact Profile blobs are respectively `6ec9aae66b9a0dc5c33b17fc45d1e1a4ddfe0d81` and `ef7e13313e804cb6f02785dbff667b58119176b1`; both equal their frozen blobs byte-for-byte.
- **Frozen Profile Baseline Verification:** `VALID`; no Profile version/content mismatch.

No explicit Additional Standards or Exceptions/Overrides are recorded.
