-- Worker appeal workflow for rejected task submissions.
CREATE TABLE IF NOT EXISTS pf_submission_disputes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  reason MEDIUMTEXT NOT NULL,
  evidence_json JSON NULL,
  status ENUM('OPEN','UNDER_REVIEW','RESOLVED_WORKER','RESOLVED_ADVERTISER') NOT NULL DEFAULT 'OPEN',
  admin_note TEXT NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_dispute_submission (submission_id),
  KEY idx_pf_dispute_status (status,created_at),
  KEY idx_pf_dispute_worker (worker_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
