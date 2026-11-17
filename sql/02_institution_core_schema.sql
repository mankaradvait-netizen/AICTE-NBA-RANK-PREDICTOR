-- ==============================================================================
-- NIRF INSTITUTION CORE DATABASE SCHEMA (02_institution_core_schema.sql)
-- Target Database: nirf_institution_core_db
-- ==============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE IF NOT EXISTS institutions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    institution_code VARCHAR(32) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'SUSPENDED')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    institution_code VARCHAR(32) REFERENCES institutions(institution_code) ON DELETE SET NULL,
    email CITEXT UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL CHECK (role IN ('SYSTEM_ADMIN', 'FORMULA_ADMIN', 'IQAC_EDITOR', 'MANAGEMENT_VIEWER', 'AUDITOR')),
    totp_secret_encrypted TEXT,
    mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ,
    auth_version INT NOT NULL DEFAULT 1,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_version INT NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS password_history (
    id SERIAL PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_password_history_user ON password_history (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id SERIAL PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    family_id UUID NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ,
    replaced_by VARCHAR(64),
    ip VARCHAR(64),
    user_agent TEXT
);

CREATE INDEX IF NOT EXISTS idx_refresh_tokens_family ON refresh_tokens (family_id);

CREATE TABLE IF NOT EXISTS api_clients (
    id SERIAL PRIMARY KEY,
    institution_code VARCHAR(32) NOT NULL REFERENCES institutions(institution_code) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    key_id VARCHAR(64) UNIQUE NOT NULL,
    secret_encrypted TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_used_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS institution_submissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    institution_code VARCHAR(32) NOT NULL REFERENCES institutions(institution_code) ON DELETE CASCADE,
    submitted_by UUID REFERENCES users(id) ON DELETE SET NULL,
    category VARCHAR(64) NOT NULL,
    academic_year VARCHAR(16) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('DRAFT', 'SUBMITTED', 'LOCKED')),
    current_rank_band VARCHAR(32) NOT NULL,
    target_rank_band VARCHAR(32) NOT NULL,
    formula_set_id INT NOT NULL,
    formula_version INT NOT NULL,
    encrypted_raw_metrics TEXT NOT NULL,
    encryption_iv TEXT NOT NULL,
    auth_tag TEXT,
    payload_hmac TEXT,
    key_id VARCHAR(64) NOT NULL,
    payload_sha256 VARCHAR(64) NOT NULL,
    computed_scores JSONB,
    gap_analysis JSONB,
    row_version INT NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_submissions_institution ON institution_submissions (institution_code, academic_year, category);

CREATE TABLE IF NOT EXISTS security_audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id VARCHAR(64),
    ip_address VARCHAR(64),
    action_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(64),
    request_signature VARCHAR(128),
    metadata JSONB,
    prev_hash VARCHAR(64) NOT NULL,
    row_hash VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_security_audit_logs_action ON security_audit_logs (action_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_security_audit_logs_user ON security_audit_logs (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS rate_limits (
    key VARCHAR(128) PRIMARY KEY,
    window_start BIGINT NOT NULL,
    count INT NOT NULL
);

-- These values are base64/JSON text in the application, not arbitrary binary data.
-- Convert existing bytea columns using PostgreSQL's bytea output decoding.
DO $$
DECLARE
    encrypted_column RECORD;
BEGIN
    FOR encrypted_column IN
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND udt_name = 'bytea'
          AND (table_name, column_name) IN (
              ('users', 'totp_secret_encrypted'),
              ('api_clients', 'secret_encrypted'),
              ('institution_submissions', 'encrypted_raw_metrics'),
              ('institution_submissions', 'encryption_iv'),
              ('institution_submissions', 'auth_tag'),
              ('institution_submissions', 'payload_hmac')
          )
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I ALTER COLUMN %I TYPE TEXT USING convert_from(%I, ''UTF8'')',
            current_schema(),
            encrypted_column.table_name,
            encrypted_column.column_name,
            encrypted_column.column_name
        );
    END LOOP;
END $$;

-- ==============================================================================
-- POSTGRESQL ROW-LEVEL SECURITY (RLS) FOR DEFENSE-IN-DEPTH TENANCY ISOLATION
-- ==============================================================================

ALTER TABLE institution_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE institution_submissions FORCE ROW LEVEL SECURITY;

DO $$ 
BEGIN
    DROP POLICY IF EXISTS institution_submissions_isolation_policy ON institution_submissions;
    CREATE POLICY institution_submissions_isolation_policy ON institution_submissions
    USING (
        institution_code = current_setting('app.current_institution', true)
        OR current_setting('app.current_user_role', true) IN ('SYSTEM_ADMIN', 'AUDITOR')
    )
    WITH CHECK (
        institution_code = current_setting('app.current_institution', true)
        OR current_setting('app.current_user_role', true) IN ('SYSTEM_ADMIN', 'AUDITOR')
    );
END $$;

-- ==============================================================================
-- LEAST PRIVILEGE: APPEND-ONLY AUDIT LOG CONSTRAINT
-- ==============================================================================
-- Revoke UPDATE and DELETE permissions from application database user
-- (Applied when running in production PostgreSQL environment)
REVOKE ALL ON security_audit_logs FROM PUBLIC;
REVOKE ALL ON rate_limits FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nirf_institution_app') THEN
        GRANT USAGE ON SCHEMA public TO nirf_institution_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO nirf_institution_app;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO nirf_institution_app;
        REVOKE UPDATE, DELETE ON security_audit_logs FROM nirf_institution_app;
    END IF;
END $$;
