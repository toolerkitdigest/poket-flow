-- PoketFlow migration preflight (READ ONLY).
-- Run this BEFORE 2026_11_05_add_campaign_click_reward_snapshot.sql.
-- It does not change data or schema. Confirm the current database is poketflo_db.
-- Expected: campaign_clicks has none of the six snapshot columns yet;
-- campaign_clicks.campaign_id, campaigns.id, campaigns.network_id and
-- networks.id should have compatible integer types.

SELECT DATABASE() AS current_database;

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    CHARACTER_MAXIMUM_LENGTH,
    NUMERIC_PRECISION,
    NUMERIC_SCALE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'campaign_clicks' AND COLUMN_NAME IN (
          'campaign_id',
          'snapshot_network_id',
          'snapshot_external_offer_id',
          'snapshot_network_payout',
          'snapshot_reward_rate',
          'snapshot_worker_reward',
          'snapshot_platform_margin'
      ))
      OR (TABLE_NAME = 'campaigns' AND COLUMN_NAME IN (
          'id', 'network_id', 'source_type', 'external_offer_id',
          'network_payout', 'reward_rate', 'worker_reward', 'platform_margin'
      ))
      OR (TABLE_NAME = 'networks' AND COLUMN_NAME = 'id')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Stop if any snapshot_* column already exists: inspect the schema first;
-- do not blindly rerun the ALTER TABLE migration.
