-- Financial workflow extension. Additive only; run after the core marketplace migration.
CREATE TABLE IF NOT EXISTS pf_funding_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 advertiser_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 payment_method VARCHAR(40) NOT NULL,
 payer_reference VARCHAR(160) NULL,
 proof_url VARCHAR(2048) NULL,
 status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
 ledger_entry_id BIGINT UNSIGNED NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 review_note TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_pf_funding_advertiser (advertiser_id,status,created_at),
 KEY idx_pf_funding_review (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_payment_webhooks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 provider VARCHAR(40) NOT NULL,
 provider_event_id VARCHAR(160) NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 processing_status ENUM('RECEIVED','PROCESSED','IGNORED','FAILED') NOT NULL DEFAULT 'RECEIVED',
 received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at DATETIME NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_pf_payment_provider_event (provider,provider_event_id),
 KEY idx_pf_payment_processing (processing_status,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
