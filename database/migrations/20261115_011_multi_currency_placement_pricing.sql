-- Add per-currency placement pricing so Nigerian and international advertisers
-- can use their local funding currency without hidden FX conversions.
CREATE TABLE IF NOT EXISTS pf_ad_placement_prices (
  placement_key VARCHAR(80) NOT NULL,
  currency CHAR(3) NOT NULL,
  price_per_day DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  min_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (placement_key,currency),
  KEY idx_pf_placement_price_active (currency,is_active,placement_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve any existing placement price as its current currency.
INSERT IGNORE INTO pf_ad_placement_prices
  (placement_key,currency,price_per_day,min_duration_days,max_duration_days,is_active)
SELECT placement_key,currency,price_per_day,min_duration_days,max_duration_days,is_active
FROM pf_ad_placements;

-- Add a disabled NGN price row for each placement. Admin sets actual local rates.
INSERT IGNORE INTO pf_ad_placement_prices
  (placement_key,currency,price_per_day,min_duration_days,max_duration_days,is_active)
SELECT placement_key,'NGN',0,min_duration_days,max_duration_days,0
FROM pf_ad_placements;
