-- Main-platform advertiser workflow for text-first blog sponsorships.
-- Uses its own reservation table, separate from worker-task campaign reserves.
ALTER TABLE pf_blog_campaigns
  ADD COLUMN requested_budget DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER placement_key,
  ADD COLUMN sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING' AFTER status,
  ADD COLUMN last_sync_error VARCHAR(255) NULL AFTER sync_status;

CREATE TABLE IF NOT EXISTS pf_blog_campaign_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('RESERVED','CONSUMED','RELEASED') NOT NULL DEFAULT 'RESERVED',
  ledger_entry_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_blog_reservation_campaign (campaign_id),
  KEY idx_pf_blog_reservation_advertiser (advertiser_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
