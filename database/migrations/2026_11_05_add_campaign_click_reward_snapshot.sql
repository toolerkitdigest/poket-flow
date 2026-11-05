-- PoketFlow: preserve the campaign's financial terms at click time.
-- Run this migration once before deploying the updated click creator and postback.
-- Existing click rows remain NULL; the callback uses campaign values only for
-- those legacy rows and uses these snapshots for all newly recorded clicks.

ALTER TABLE campaign_clicks
    ADD COLUMN snapshot_network_id INT NULL AFTER campaign_id,
    ADD COLUMN snapshot_external_offer_id VARCHAR(100) NULL AFTER snapshot_network_id,
    ADD COLUMN snapshot_network_payout DECIMAL(12,2) NULL AFTER snapshot_external_offer_id,
    ADD COLUMN snapshot_reward_rate DECIMAL(5,2) NULL AFTER snapshot_network_payout,
    ADD COLUMN snapshot_worker_reward DECIMAL(12,2) NULL AFTER snapshot_reward_rate,
    ADD COLUMN snapshot_platform_margin DECIMAL(12,2) NULL AFTER snapshot_worker_reward;
