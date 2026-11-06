SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role ENUM('WORKER','ADVERTISER','ADMIN') NOT NULL DEFAULT 'WORKER',
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  password_hash VARCHAR(255) NOT NULL,
  country VARCHAR(100) NULL,
  status ENUM('ACTIVE','SUSPENDED','PENDING','BANNED') NOT NULL DEFAULT 'ACTIVE',
  referral_code VARCHAR(30) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO users (id,role,name,email,email_verified,password_hash,country,status,referral_code)
VALUES (1,'WORKER','Test Member','test-member@example.invalid',0,'$2y$10$test-hash','Nigeria','ACTIVE','PFTEST0001');

CREATE TABLE settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO settings (setting_key,setting_value) VALUES
('mail_from_name','PoketFlow'),('mail_from_address','no-reply@poketflow.com'),('mail_reply_to','support@poketflow.com');

CREATE TABLE wallet_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('OFFER_REWARD','REFERRAL_REWARD','WITHDRAWAL','ADJUSTMENT','REVERSAL','BONUS') NOT NULL,
  reference_type VARCHAR(50) NULL,
  reference_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('PENDING','COMPLETED','REVERSED','FAILED') NOT NULL DEFAULT 'COMPLETED',
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO wallet_transactions (id,user_id,type,reference_type,reference_id,amount,currency,status,description,created_at) VALUES
(1,1,'BONUS','TEST',NULL,10.00,'USD','COMPLETED','Historical test credit','2026-10-01 07:32:32'),
(2,1,'WITHDRAWAL','withdrawal',1,-5.00,'USD','COMPLETED','Historical withdrawal hold','2026-10-01 07:56:44'),
(3,1,'REVERSAL','withdrawal',1,5.00,'USD','COMPLETED','Historical rejected withdrawal refund','2026-10-01 07:59:19'),
(4,1,'OFFER_REWARD','CONVERSION',1,0.25,'USD','COMPLETED','Historical reward','2026-10-02 13:12:16');

CREATE TABLE withdrawals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(50) NOT NULL,
  method_network VARCHAR(50) NULL,
  payment_details TEXT NOT NULL,
  status ENUM('PENDING','PROCESSING','PAID','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  admin_note TEXT NULL,
  transaction_reference VARCHAR(190) NULL,
  processed_by BIGINT UNSIGNED NULL,
  processed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO withdrawals (id,user_id,amount,method,method_network,payment_details,status,admin_note,transaction_reference,processed_by,processed_at,created_at)
VALUES (1,1,5.00,'USDT','TRC20','{"address":"T-test-address"}','REJECTED','Test rejection',NULL,1,'2026-10-01 07:59:19','2026-10-01 07:56:44');

CREATE TABLE campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_type ENUM('CPA_NETWORK','DIRECT_ADVERTISER') NOT NULL,
  advertiser_id BIGINT UNSIGNED NULL,
  network_id BIGINT UNSIGNED NULL,
  external_offer_id VARCHAR(190) NULL,
  network_offer_url VARCHAR(500) NULL,
  image_url VARCHAR(500) NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  category VARCHAR(100) NULL,
  instructions TEXT NULL,
  network_payout DECIMAL(12,2) NULL,
  reward_rate DECIMAL(5,2) NULL,
  worker_reward DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  platform_margin DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  budget DECIMAL(12,2) NULL,
  spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  countries TEXT NULL,
  devices TEXT NULL,
  os TEXT NULL,
  incentive_allowed TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('DRAFT','ACTIVE','PAUSED','COMPLETED','EXPIRED') NOT NULL DEFAULT 'DRAFT',
  approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO campaigns (id,source_type,network_id,external_offer_id,network_offer_url,title,description,category,instructions,network_payout,reward_rate,worker_reward,platform_margin,status,approval_status,created_at)
VALUES (1,'DIRECT_ADVERTISER',1,'TEST-OFFER','https://affiliate.invalid/secret-token','Archived test offer','Historical campaign snapshot','Offer','Old instructions',1.00,40.00,0.40,0.60,'PAUSED','APPROVED','2026-09-30 09:04:41');

CREATE TABLE conversions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  network_id BIGINT UNSIGNED NULL,
  external_transaction_id VARCHAR(190) NULL,
  network_payout DECIMAL(12,2) NULL,
  reward_rate DECIMAL(5,2) NULL,
  worker_reward DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  platform_margin DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('PENDING','APPROVED','REJECTED','REVERSED') NOT NULL DEFAULT 'PENDING',
  converted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO conversions (id,campaign_id,worker_id,network_id,external_transaction_id,network_payout,reward_rate,worker_reward,platform_margin,status,converted_at,created_at)
VALUES (1,1,1,1,'legacy-test-transaction',1.00,40.00,0.40,0.60,'APPROVED','2026-09-30 15:47:00','2026-09-30 20:58:40');

CREATE TABLE advertisers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  company_name VARCHAR(150) NOT NULL,
  status ENUM('PENDING','ACTIVE','SUSPENDED','REJECTED') NOT NULL DEFAULT 'PENDING',
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  website VARCHAR(255) NULL,
  description TEXT NULL,
  contact_email VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_advertiser_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO advertisers (id,user_id,company_name,status,balance,website,description,contact_email,created_at)
VALUES (1,1,'Test Advertiser','ACTIVE',25.75,'https://advertiser.example.invalid','Legacy advertiser profile','billing@example.invalid','2026-09-01 10:00:00');
CREATE TABLE advertiser_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  type ENUM('DEPOSIT','CAMPAIGN_FUNDING','CAMPAIGN_SPEND','REFUND','ADJUSTMENT') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('PENDING','COMPLETED','FAILED','REVERSED') NOT NULL DEFAULT 'COMPLETED',
  campaign_id BIGINT UNSIGNED NULL,
  reference VARCHAR(190) NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO advertiser_transactions (id,advertiser_id,campaign_id,type,amount,reference,status,description,created_at) VALUES
(1,1,NULL,'DEPOSIT',40.00,'legacy-deposit-1','COMPLETED','Historical advertiser deposit','2026-09-01 10:10:00'),
(2,1,1,'CAMPAIGN_SPEND',14.25,'legacy-spend-1','COMPLETED','Historical campaign spend','2026-09-02 10:10:00'),
(3,1,NULL,'DEPOSIT',10.00,'legacy-pending-1','PENDING','Unsettled advertiser deposit','2026-09-03 10:10:00');
CREATE TABLE ad_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  destination_url VARCHAR(500) NOT NULL,
  budget DECIMAL(12,2) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  creative_type ENUM('BANNER','IMAGE','TEXT') NOT NULL DEFAULT 'TEXT',
  creative_url VARCHAR(500) NULL,
  placement VARCHAR(100) NULL,
  spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO ad_campaigns (id,advertiser_id,name,destination_url,budget,status,creative_type,creative_url,placement,spent,approval_status,created_at)
VALUES (1,1,'Archived banner campaign','https://example.invalid',20.00,'PAUSED','BANNER','https://example.invalid/banner.png','sidebar',3.00,'APPROVED','2026-09-04 10:00:00');
SET FOREIGN_KEY_CHECKS=1;
