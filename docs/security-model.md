# Security Model

This document describes the security and data protection model implemented by WhatsApp Commerce Hub.

## Encryption (SecureVault)

**Component:** `includes/Security/SecureVault.php`

### Key material

- Primary key is taken from `WCH_ENCRYPTION_KEY` (wp-config.php constant) or `WCH_ENCRYPTION_KEY` environment variable.
- If not provided, SecureVault falls back to WordPress salts/auth key for backwards compatibility (less secure); a warning is logged.

### Data format

- `SecureVault::encrypt()` emits `v2:` payloads: base64-encoded `[key_version][iv][tag][ciphertext]`.
- `SecureVault::decrypt()` currently supports only `v2:` payloads.

### Key rotation

- `SecureVault::rotateKey()` creates a new derived key version and marks it active in `wch_encryption_key_versions`.
- Existing ciphertext is not automatically re-encrypted; callers must re-encrypt stored data using `SecureVault::reencrypt()`.

## PII Protection (PIIEncryptor)

**Component:** `includes/Security/PIIEncryptor.php`

- Provides per-field encryption using SecureVault field context `pii-{field}`.
- For searchable fields (e.g., phone/email), a deterministic blind index is generated using `SecureVault::hash()` over the normalized value.
- Blind indexes are intended for lookups without decrypting values.

## Rate Limiting (RateLimiter)

**Component:** `includes/Security/RateLimiter.php`

### Storage

Rate limiting is backed by the `wch_rate_limits` table.

- Each `(identifier_hash, limit_type, window_start)` row represents a single fixed time window.
- `request_count` is incremented atomically (single UPDATE with `request_count < limit`) to avoid TOCTOU race conditions.
- `expires_at` is used to delete expired windows.

### Blocking

- A block is stored as a single row with `limit_type = 'blocked'` and `window_start = 'blocked'`.
- `expires_at` indicates when the block lifts.

### Missing table behavior

If the rate limits table is missing (e.g., security features enabled before migrations ran), RateLimiter degrades gracefully:

- `check()`/`checkAndHit()` allow requests.
- `block()`/`unblock()` become no-ops.

## Security Logging

**Component:** anonymous logger in `includes/Providers/SecurityServiceProvider.php`

- All events are logged to the configured file logger.
- Warning/error level events are also written to the `wch_security_log` table when present.
- If the table is missing, database logging is skipped (no runtime errors).
