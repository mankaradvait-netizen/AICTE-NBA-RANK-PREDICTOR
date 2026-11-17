# Security Hardening & Compliance Guide (OWASP ASVS L2 & API Top 10)

## 1. Operating System & Container Hardening
- **Non-Root Execution:** The PHP-FPM container executes as unprivileged user `www-data`.
- **Read-Only Root Filesystem:** Container root filesystem is mounted read-only; only `/var/nirf/storage` is writable.
- **Minimal Surface:** Base images are Alpine Linux with minimal installed utilities; dangerous compilation tools are absent in production.

## 2. PHP Runtime Hardening
- **Process & Shell Restrictions:** `disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_multi_exec,parse_ini_file,show_source,dl`.
- **Remote Inclusion Prevention:** `allow_url_fopen = Off` and `allow_url_include = Off`.
- **Information Leakage:** `expose_php = Off`, `display_errors = Off`.
- **Path Isolation:** `open_basedir = /var/www/html:/var/nirf/storage:/tmp`.

## 3. Database Least Privilege & Network Isolation
- **No Host Port Publishing:** PostgreSQL container publishes no external port (`expose: 5432` on internal Docker network only).
- **Authentication:** `scram-sha-256` password hashing for all database accounts.
- **Dedicated Application Roles:**
  - `nirf_formula_reader`: `SELECT` privileges only on `nirf_formula_engine_db`.
  - `nirf_formula_admin`: `SELECT` for validation plus narrowly scoped `INSERT`/`UPDATE` privileges for workbook import and formula-set activation; no blanket delete privilege.
  - `nirf_institution_app`: Non-owner runtime role for `nirf_institution_core_db`; it must not have `BYPASSRLS`. It receives only `SELECT` and `INSERT` on `security_audit_logs`.
  - Separate schema-owner/migration roles own database tables. Provide `DB_FORMULA_MIGRATION_*` and `DB_CORE_MIGRATION_*` only to migration/rotation CLI processes; do not place these in the application `.env` or PHP-FPM environment.
- **Row-Level Security (RLS):** Enabled and forced on `institution_submissions`. Every authenticated request explicitly sets both tenant and role context; a missing institution context is cleared rather than inherited from a prior request on a reused connection.
- **Encrypted values:** Base64-encoded ciphertext/IV/tag and serialized encrypted MFA/API-client envelopes are stored as `TEXT`. The migration converts legacy `BYTEA` values from UTF-8, matching the application's encoded representation and preserving reads/rotation.
- **Migrations:** Run `bin/console migrate` using separate formula/core schema-owner connections. Never run migrations as `DB_CORE_USER`. PostgreSQL owners can otherwise bypass ordinary table permissions, including append-only audit-log grants.

## 4. Cryptographic Key Management & Envelope Encryption
- **Key Custody:** Generate independent 256-bit keys (for example, `openssl rand -hex 32`) and inject them via a secret manager or protected runtime environment. `.env.example` contains blank secret fields; do not store real keys in source control, shell history, command-line arguments, or the application `.env`.
- **Envelope Encryption:** Each submission is encrypted using a unique, random 256-bit data encryption key (DEK). The DEK is encrypted with the master key (KEK) using AES-256-KW / GCM and stored alongside the ciphertext.
- **Key Rotation:** In a maintenance window, stop writes, provide the current key as `MASTER_KEY_HEX` and the new key as `ROTATION_MASTER_KEY_HEX` to the CLI process only, then run `bin/console keys:rotate <new-key-id>` using the migration connection. The command locks the submissions table and transactionally replaces each bundled wrapped DEK and row key ID; unexpected/invalid rows cause a full rollback. Only after success, set `MASTER_KEY_HEX` and `ACTIVE_KEY_ID` to the new values and resume writes. Retain a protected recovery copy of the old key until backups and restore procedures have been validated.

## 6. Required Runtime Secrets

`JWT_SECRET`, `MASTER_KEY_HEX`, the database passwords, and `POSTGRES_PASSWORD` must be unique deployment secrets. Compose now refuses to start when required values are absent; it does not supply usable fallback credentials. Load secret values from the deployment secret manager and do not paste them into shell commands or commit a populated `.env`.

For the one-time `user:create-admin` operation, securely inject `INITIAL_ADMIN_PASSWORD` into only the CLI process; the password is no longer accepted as a command-line argument. Do not add that value to the application `.env`.

## 5. Defense-in-Depth Web & API Security
- **Security Headers:** Strict HSTS (`max-age=63072000`), CSP (`default-src 'none'`), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, restrictive Permissions/Cross-Origin policies, and `X-Permitted-Cross-Domain-Policies: none`.
- **Rate Limiting:** Login is limited to 10 attempts per minute per IP; MFA verification is limited to 5 attempts per minute per IP and 5 attempts per five minutes per account, including attempts from different IPs. General API traffic is limited to 120 requests per minute per IP. Rate-limit storage/database errors fail closed rather than bypassing the control. Five failed password attempts trigger a 15-minute account lockout.
- **HMAC Signatures:** Server-to-server requests authenticate with HMAC-SHA256, 300-second timestamp freshness window, and nonce anti-replay validation.
- **Formula Injection Prevention:** All Excel exports escape cells starting with `=`, `+`, `-`, or `@` with a leading apostrophe (`'`).
