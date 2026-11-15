-- PoketFlow advertiser-only product realignment.
-- Additive: preserves legacy users, passwords, roles, wallets, ledger rows and worker history.
-- Run only after migrations 001 through 009 on a verified disposable database first.

ALTER TABLE pf_campaigns
  MODIFY campaign_type ENUM('WORKER_TASK','BLOG_SPONSORED','SITE_DISPLAY') NOT NULL;

CREATE TABLE IF NOT EXISTS pf_advertiser_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  advertiser_name VARCHAR(150) NOT NULL,
  website VARCHAR(255) NULL,
  contact_email VARCHAR(190) NOT NULL,
  status ENUM('PENDING','ACTIVE','SUSPENDED','REJECTED') NOT NULL DEFAULT 'PENDING',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_pf_advertiser_profile_status (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing members retain their identities and gain an advertiser profile.
-- Legacy suspended/rejected advertiser restrictions are carried forward.
INSERT IGNORE INTO pf_advertiser_profiles
  (user_id,advertiser_name,contact_email,status)
SELECT u.id,
       COALESCE(NULLIF(TRIM(u.name),''),CONCAT('Advertiser ',u.id)),
       u.email,
       CASE UPPER(COALESCE(la.legacy_status,''))
         WHEN 'SUSPENDED' THEN 'SUSPENDED'
         WHEN 'REJECTED' THEN 'REJECTED'
         ELSE 'ACTIVE'
       END
FROM users u
LEFT JOIN pf_legacy_advertiser_accounts la ON la.user_id=u.id;

-- Add advertiser capability only where an account-mode row is missing.
-- Existing capability settings are not overwritten.
INSERT IGNORE INTO pf_account_modes (user_id,can_work,can_advertise)
SELECT id,0,1 FROM users;

CREATE TABLE IF NOT EXISTS pf_ad_placements (
  placement_key VARCHAR(80) NOT NULL,
  surface ENUM('MAIN_SITE','BLOG') NOT NULL,
  label VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  price_per_day DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  min_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
  formats_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (placement_key),
  KEY idx_pf_ad_placements_surface_active (surface,is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prices deliberately start at zero and placements disabled. Admin must set
-- real prices after reviewing traffic and inventory; no impression volume is promised.
INSERT IGNORE INTO pf_ad_placements
  (placement_key,surface,label,description,price_per_day,currency,min_duration_days,max_duration_days,formats_json,is_active,sort_order)
VALUES
 ('main_home_top','MAIN_SITE','Homepage top banner','Prominent banner near the top of the PoketFlow homepage',0,'USD',1,365,'["banner"]',0,10),
 ('main_home_inline','MAIN_SITE','Homepage inline','Native sponsored card within homepage content',0,'USD',1,365,'["native"]',0,20),
 ('main_directory_inline','MAIN_SITE','Opportunities directory','Sponsored listing within the public opportunities directory',0,'USD',1,365,'["native"]',0,30),
 ('site_footer_banner','MAIN_SITE','Site-wide footer banner','Banner placement near the footer on eligible main-site pages',0,'USD',1,365,'["banner"]',0,40);

CREATE TABLE IF NOT EXISTS pf_site_campaigns (
  campaign_id BIGINT UNSIGNED NOT NULL,
  placement_key VARCHAR(80) NOT NULL,
  requested_budget DECIMAL(18,2) NOT NULL,
  creative_json JSON NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('PENDING','ACTIVE','PAUSED','ENDED','REVOKED') NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id),
  KEY idx_pf_site_campaign_delivery (placement_key,status,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_site_campaign_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('RESERVED','RELEASED','CONSUMED') NOT NULL DEFAULT 'RESERVED',
  ledger_entry_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_site_reservation_campaign (campaign_id),
  KEY idx_pf_site_reservation_advertiser (advertiser_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_site_ad_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('IMPRESSION','CLICK') NOT NULL,
  placement_key VARCHAR(80) NOT NULL,
  event_key VARCHAR(120) NOT NULL,
  occurred_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_site_event_key (event_key),
  KEY idx_pf_site_event_campaign (campaign_id,event_type,occurred_at),
  KEY idx_pf_site_event_placement (placement_key,event_type,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pf_payment_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('PAYSTACK','PAYPAL') NOT NULL,
  provider_reference VARCHAR(190) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('INITIALIZED','PENDING','PAID','FAILED','CANCELLED') NOT NULL DEFAULT 'INITIALIZED',
  checkout_url VARCHAR(2048) NULL,
  provider_transaction_id VARCHAR(190) NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_payment_order_reference (provider,provider_reference),
  KEY idx_pf_payment_order_advertiser (advertiser_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
