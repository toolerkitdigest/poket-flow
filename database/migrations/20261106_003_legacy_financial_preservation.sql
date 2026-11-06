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
