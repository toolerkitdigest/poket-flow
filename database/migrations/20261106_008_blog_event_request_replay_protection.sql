-- Short-lived replay protection for signed blog-to-marketplace event requests.
-- Request nonces are retained for ten minutes; event keys remain the durable
-- idempotency key for impression/click records.
CREATE TABLE IF NOT EXISTS pf_blog_event_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nonce CHAR(64) NOT NULL,
  request_timestamp BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_blog_event_request_nonce (nonce),
  KEY idx_pf_blog_event_request_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
