CREATE TABLE IF NOT EXISTS funnel_clicks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tracking_id CHAR(32) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    status ENUM('pending','approved') NOT NULL DEFAULT 'pending',
    network_transaction_id VARCHAR(191) DEFAULT NULL,
    payout DECIMAL(12,4) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    converted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_funnel_tracking_id (tracking_id),
    UNIQUE KEY uq_funnel_network_transaction (network_transaction_id),
    KEY idx_funnel_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
