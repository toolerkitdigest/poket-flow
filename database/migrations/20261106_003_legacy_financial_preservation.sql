-- Legacy financial-history preservation extension.
-- Run after 20261106_001_locked_marketplace_core.sql.
-- This alters only newly introduced pf_* tables, never legacy tables.

ALTER TABLE pf_wallet_ledger
  MODIFY entry_type ENUM(
    'DEPOSIT',
    'CAMPAIGN_RESERVE',
    'CAMPAIGN_RELEASE',
    'WORKER_REWARD',
    'WITHDRAWAL_HOLD',
    'WITHDRAWAL_PAID',
    'REFUND',
    'ADJUSTMENT',
    'LEGACY_IMPORT'
  ) NOT NULL;

ALTER TABLE pf_withdrawals
  ADD COLUMN legacy_withdrawal_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN method_network VARCHAR(50) NULL AFTER method,
  ADD UNIQUE KEY uq_pf_withdrawal_legacy_id (legacy_withdrawal_id);

-- Archive selected historical campaign/conversion facts without importing
-- CPA network URLs, activating campaigns, or restoring any CPA integration.
CREATE TABLE IF NOT EXISTS pf_legacy_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_table ENUM('campaigns','conversions') NOT NULL,
  legacy_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  snapshot_json JSON NOT NULL,
  source_created_at DATETIME NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_legacy_history_source (source_table,legacy_id),
  KEY idx_pf_legacy_history_owner (owner_user_id,source_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
