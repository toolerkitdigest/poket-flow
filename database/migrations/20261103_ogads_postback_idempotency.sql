-- PoketFlow: OGAds postback idempotency
-- Apply once, after confirming the preflight query returns no rows.
--
-- PRE-FLIGHT:
-- SELECT network_id, external_transaction_id, COUNT(*) AS total
-- FROM conversions
-- WHERE external_transaction_id IS NOT NULL
--   AND external_transaction_id <> ''
-- GROUP BY network_id, external_transaction_id
-- HAVING COUNT(*) > 1;
--
-- The production preflight on 2026-11-03 returned no duplicate groups.
-- Existing conversion and wallet rows are not modified or deleted.
--
-- This key makes one callback tracking ID unique per CPA network.
-- The callback handler also requires an active OGAds network ID, so
-- new OGAds callbacks cannot bypass this key with a NULL network_id.
--
-- ROLLBACK (only if intentionally reverting this protection):
-- ALTER TABLE conversions
--   DROP INDEX uq_conversions_network_external;
--
ALTER TABLE conversions
    ADD UNIQUE KEY uq_conversions_network_external
    (network_id, external_transaction_id);
