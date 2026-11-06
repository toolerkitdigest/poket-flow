-- Preserve legacy advertiser identity, spendable balance and ad history.
-- Current advertiser.balance is the authoritative opening balance. Historical
-- transactions are archived separately and are NOT replayed into the live ledger,
-- preventing deposits/spend from being counted twice.
CREATE TABLE IF NOT EXISTS pf_legacy_advertiser_accounts (
  legacy_advertiser_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  company_name VARCHAR(150) NOT NULL,
  website VARCHAR(255) NULL,
  description TEXT NULL,
  contact_email VARCHAR(190) NULL,
  legacy_status VARCHAR(30) NOT NULL,
  legacy_balance DECIMAL(18,2) NOT NULL,
  wallet_id BIGINT UNSIGNED NOT NULL,
  opening_ledger_entry_id BIGINT UNSIGNED NOT NULL,
  source_created_at DATETIME NULL,
  source_updated_at DATETIME NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (legacy_advertiser_id),
  UNIQUE KEY uq_pf_legacy_advertiser_user (user_id),
  UNIQUE KEY uq_pf_legacy_advertiser_wallet (wallet_id),
  UNIQUE KEY uq_pf_legacy_advertiser_opening_ledger (opening_ledger_entry_id),
  KEY idx_pf_legacy_advertiser_status (legacy_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_legacy_advertiser_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_table ENUM('advertiser_transactions','ad_campaigns') NOT NULL,
  legacy_id BIGINT UNSIGNED NOT NULL,
  legacy_advertiser_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  source_created_at DATETIME NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_legacy_advertiser_history_source (source_table,legacy_id),
  KEY idx_pf_legacy_advertiser_history_owner (owner_user_id,source_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
