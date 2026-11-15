-- PoketFlow locked marketplace foundation
-- MySQL 8.0+ | additive only | reviewed before execution
-- Does not ALTER or DELETE legacy tables. No FK constraints are added
-- until the exact live users.id type and backup are verified.

CREATE TABLE IF NOT EXISTS pf_account_modes (
  user_id BIGINT UNSIGNED NOT NULL,
  can_work TINYINT(1) NOT NULL DEFAULT 1,
  can_advertise TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing members keep their IDs and become eligible for both platform modes.
INSERT IGNORE INTO pf_account_modes (user_id,can_work,can_advertise)
SELECT id,1,1 FROM users;

CREATE TABLE IF NOT EXISTS pf_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  wallet_type ENUM('ADVERTISER','WORKER') NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_wallet_owner_type_currency (user_id,wallet_type,currency),
  KEY idx_pf_wallet_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create empty USD ledgers for all existing members; no balances are fabricated.
INSERT IGNORE INTO pf_wallets (user_id,wallet_type,currency)
SELECT id,'WORKER','USD' FROM users
UNION ALL
SELECT id,'ADVERTISER','USD' FROM users;

CREATE TABLE IF NOT EXISTS pf_wallet_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  entry_type ENUM('DEPOSIT','CAMPAIGN_RESERVE','CAMPAIGN_RELEASE','WORKER_REWARD','WITHDRAWAL_HOLD','WITHDRAWAL_PAID','REFUND','ADJUSTMENT') NOT NULL,
  amount DECIMAL(18,2) NOT NULL COMMENT 'Signed: credits positive, debits negative',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  reference_type VARCHAR(40) NOT NULL,
  reference_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_ledger_idempotency (idempotency_key),
  KEY idx_pf_ledger_wallet_date (wallet_id,created_at),
  KEY idx_pf_ledger_user_date (user_id,created_at),
  KEY idx_pf_ledger_reference (reference_type,reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  campaign_type ENUM('WORKER_TASK','BLOG_SPONSORED') NOT NULL,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary VARCHAR(500) NULL,
  description MEDIUMTEXT NOT NULL,
  instructions MEDIUMTEXT NULL,
  requirements MEDIUMTEXT NULL,
  category VARCHAR(80) NULL,
  destination_url VARCHAR(2048) NULL,
  reward_per_completion DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  approved_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  reserved_budget DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  spent_budget DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  evidence_required TINYINT(1) NOT NULL DEFAULT 1,
  evidence_instructions TEXT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  status ENUM('DRAFT','PENDING_REVIEW','ACTIVE','PAUSED','REJECTED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  admin_note TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_campaign_slug (slug),
  KEY idx_pf_campaign_discovery (campaign_type,status,starts_at,ends_at),
  KEY idx_pf_campaign_advertiser (advertiser_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_campaign_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('RESERVED','PARTIALLY_RELEASED','CONSUMED','RELEASED') NOT NULL DEFAULT 'RESERVED',
  ledger_entry_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pf_reservation_campaign (campaign_id,status),
  KEY idx_pf_reservation_advertiser (advertiser_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  submission_text MEDIUMTEXT NULL,
  evidence_json JSON NULL,
  status ENUM('PENDING','APPROVED','REJECTED','DISPUTED','WITHDRAWN') NOT NULL DEFAULT 'PENDING',
  reward_amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  reward_ledger_entry_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pf_submission_campaign_status (campaign_id,status,created_at),
  KEY idx_pf_submission_worker (worker_id,status,created_at),
  KEY idx_pf_submission_reviewer (reviewed_by,reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_withdrawals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  wallet_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  method VARCHAR(40) NOT NULL,
  destination_encrypted TEXT NOT NULL,
  status ENUM('PENDING','PROCESSING','PAID','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  hold_ledger_entry_id BIGINT UNSIGNED NULL,
  payout_reference VARCHAR(160) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  admin_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pf_withdrawal_user (user_id,status,created_at),
  KEY idx_pf_withdrawal_status (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_blog_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  public_token_hash CHAR(64) NOT NULL,
  placement_key VARCHAR(80) NOT NULL,
  creative_json JSON NOT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  status ENUM('PENDING','ACTIVE','PAUSED','ENDED','REVOKED') NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_blog_campaign (campaign_id),
  UNIQUE KEY uq_pf_blog_token (public_token_hash),
  KEY idx_pf_blog_delivery (placement_key,status,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_blog_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('IMPRESSION','CLICK') NOT NULL,
  event_key VARCHAR(120) NOT NULL,
  occurred_at DATETIME NOT NULL,
  metadata_json JSON NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_blog_event_key (event_key),
  KEY idx_pf_blog_event_campaign (campaign_id,event_type,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_api_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  abilities_json JSON NOT NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_api_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  subject_type VARCHAR(60) NOT NULL,
  subject_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip_address VARBINARY(16) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pf_audit_actor (actor_user_id,created_at),
  KEY idx_pf_audit_subject (subject_type,subject_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
