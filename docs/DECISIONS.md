# Architectural Decision Records (ADRs)

## ADR-001: Deterministic Engine & Zero Hardcoded NIRF Logic
- **Status:** Accepted
- **Context:** NIRF methodology updates formulas, metric caps, and normalization constants across academic cycles. Hardcoding equations in PHP leads to deployment overhead, inconsistency, and regression risks.
- **Decision:** All NIRF formulas, derived variable chains, validation rules, input definitions, weights, and tier recommendations reside in the Formula Database (`nirf_formula_engine_db`) and are ingested via structurally validated, dry-run-tested Excel workbooks. Upload file hashes and four-eyes approval are recorded; digital workbook signatures are not implemented. PHP acts as a deterministic evaluation runtime.

## ADR-002: Dual Database Separation of Concerns
- **Status:** Accepted
- **Context:** Institution metrics and user authentication are highly confidential, while formula definitions require strict administrative auditing and 4-eyes approval.
- **Decision:** Two completely distinct databases are utilized with zero cross-database foreign keys:
  1. `nirf_formula_engine_db`: Read-only for scoring operations; modified only by authenticated `FORMULA_ADMIN` during workbook upload.
  2. `nirf_institution_core_db`: Multi-tenant institution repository with Row-Level Security (RLS) and tamper-evident audit logging.
  Every submission records `formula_set_id` and `formula_version` to enable exact historical recalculation.

## ADR-003: Formula Verification and Placeholder Benchmarks
- **Status:** Accepted
- **Context:** Blueprint formulas contain empirical parameters (e.g., 95% PhD target, 0.33/0.34 experience weights, 1:15 FSR, 80% graduation rate, 50%/20% women benchmarks) which may vary between NIRF categories.
- **Decision:** Unconfirmed rules are flagged with `needs_verification = TRUE`. Activation rejects unverified rules even when an acknowledgement is supplied, requires non-placeholder benchmarks with HTTPS source references, and requires an HTTPS methodology citation. HTTPS and human approval record provenance but do not prove source authority; reviewers must verify source contents and academic-year applicability. Seed data remains DRAFT and explicitly unverified.

## ADR-004: Envelope Encryption (AES-256-GCM Default + CBC-HMAC Mode)
- **Status:** Accepted
- **Context:** Sensitive institutional financial and salary figures require defense-in-depth protection at rest.
- **Decision:** Primary encryption uses AES-256-GCM envelope encryption with unique per-record keys wrapped by an offline master key. For environments requiring compliance with older blueprints, `CRYPTO_MODE=cbc_hmac` provides AES-256-CBC with Encrypt-then-MAC (HMAC-SHA256) via HKDF key derivation.

## ADR-005: AST Whitelist Linter for Safe Expression Evaluation
- **Status:** Accepted
- **Context:** Dynamic formulas uploaded via Excel could introduce code execution or denial-of-service vulnerabilities.
- **Decision:** Expressions are strictly parsed using Symfony ExpressionLanguage AST traversal before persistence. Expressions are restricted to `[0-9a-zA-Z_.+\-*/()\s,<>=!&|?:]`, depth ≤ 12, length ≤ 500 chars, and a strict function whitelist (`min`, `max`, `abs`, `sqrt`, `log10`, `clamp`, `safe_div`). Method calls, object access, string literals, and PHP constants are unconditionally rejected.

## ADR-006: Tamper-Evident SHA-256 Chained Audit Log
- **Status:** Accepted
- **Context:** Compliance requires proof that audit records have not been altered or deleted.
- **Decision:** `security_audit_logs` maintains a cryptographic hash chain: `row_hash = SHA-256(prev_hash || canonical_row_representation)`. PostgreSQL user permissions revoke `UPDATE` and `DELETE` on this table. A CLI verification tool (`bin/console audit:verify-chain`) scans the table for chain breaks.
