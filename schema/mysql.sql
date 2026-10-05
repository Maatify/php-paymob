-- Paymob authentication token cache. One current token per credential scope.
-- Infrastructure cache only: no soft delete, Host foreign key, or Host JOIN.
CREATE TABLE maa_paymob_auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate row identifier.',
    scope_key CHAR(64) NOT NULL COMMENT 'Opaque SHA-256 credential-scope identity.',
    token TEXT NOT NULL COMMENT 'Paymob bearer token; sensitive authentication cache data.',
    profile_id BIGINT UNSIGNED NOT NULL COMMENT 'Positive Paymob profile identity.',
    issued_at BIGINT UNSIGNED NOT NULL COMMENT 'Token issue time as Unix timestamp.',
    expires_at BIGINT UNSIGNED NOT NULL COMMENT 'Token expiry time as Unix timestamp.',
    PRIMARY KEY (id),
    UNIQUE KEY maa_paymob_auth_tokens_scope_key_unique (scope_key)
) ENGINE=InnoDB COMMENT='Current Paymob authentication token cache, one row per scope; no soft delete, Host FK, or Host JOIN.';
