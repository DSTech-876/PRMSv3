-- Procurement Notifications, Smart Reminders & Multi-Currency Support
-- ===================================================================
-- This migration is ADDITIVE and IDEMPOTENT:
--   * no existing column is dropped
--   * no existing row is rewritten destructively
--   * every legacy record keeps its current amounts and is defaulted to the
--     system default currency (JMD) only where no currency was ever stored
--
-- Sections
--   1. Currency reference data (currencies table + system defaults)
--   2. Widen existing currency columns from ENUM('JMD','USD') to CHAR(3)
--   3. Add currency columns to the remaining transactional tables
--   4. Notification type extension (procurement_approved / reminder / escalation)
--   5. Reminder configuration + execution logging + retry support
--   6. Permissions
--
-- Rollback: see the bottom of this file.

-- ===========================================================================
-- 1. CURRENCY REFERENCE DATA
-- ===========================================================================

CREATE TABLE IF NOT EXISTS currencies (
    currency_code   CHAR(3)       NOT NULL,
    currency_name   VARCHAR(60)   NOT NULL,
    currency_symbol VARCHAR(8)    NOT NULL,
    decimal_places  TINYINT       NOT NULL DEFAULT 2,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    display_order   INT           NOT NULL DEFAULT 100,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (currency_code),
    KEY idx_currencies_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO currencies (currency_code, currency_name, currency_symbol, decimal_places, is_active, display_order)
VALUES
    ('JMD', 'Jamaican Dollar',   'J$', 2, 1, 10),
    ('USD', 'United States Dollar', 'US$', 2, 1, 20),
    ('GBP', 'Pound Sterling',    '£',  2, 1, 30),
    ('EUR', 'Euro',              '€',  2, 1, 40),
    ('CAD', 'Canadian Dollar',   'C$', 2, 1, 50)
ON DUPLICATE KEY UPDATE
    currency_name   = VALUES(currency_name),
    currency_symbol = VALUES(currency_symbol),
    decimal_places  = VALUES(decimal_places),
    display_order   = VALUES(display_order);

-- System default currency. Legacy data is JMD, so JMD remains the default and
-- historical records are therefore unchanged in meaning.
INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES ('default_currency', 'JMD', 'System default currency code applied to new and legacy records (JMD, USD, GBP, EUR, CAD)', NOW())
ON DUPLICATE KEY UPDATE config_value = config_value;

INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES ('enable_multi_currency', '1', 'Enable multi-currency selection on transactions (1=enabled, 0=single currency)', NOW())
ON DUPLICATE KEY UPDATE config_value = config_value;

-- Exchange rates used to convert non-JMD estimated values to their JMD
-- equivalent for threshold/approval-chain decisions (see CurrencyService::toJmd()).
-- `config_value = config_value` on duplicate preserves any rate an admin has
-- already configured; these rows only seed a starting value.
INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES
    ('gbp_to_jmd_rate', '196.00', 'Current GBP to JMD exchange rate for currency conversion', NOW()),
    ('eur_to_jmd_rate', '168.00', 'Current EUR to JMD exchange rate for currency conversion', NOW()),
    ('cad_to_jmd_rate', '113.00', 'Current CAD to JMD exchange rate for currency conversion', NOW())
ON DUPLICATE KEY UPDATE config_value = config_value;

-- ===========================================================================
-- 2. WIDEN EXISTING CURRENCY COLUMNS  ENUM('JMD','USD') -> CHAR(3)
--    MODIFY (not DROP/ADD) so every existing value is preserved verbatim.
-- ===========================================================================

DROP PROCEDURE IF EXISTS prms_widen_currency_column;
DELIMITER $$
CREATE PROCEDURE prms_widen_currency_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64))
BEGIN
    DECLARE v_type VARCHAR(255) DEFAULT NULL;

    SELECT COLUMN_TYPE INTO v_type
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND COLUMN_NAME  = p_column
    LIMIT 1;

    -- Only act when the column exists and is not already CHAR(3)
    IF v_type IS NOT NULL AND LOWER(v_type) <> 'char(3)' THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` MODIFY COLUMN `', p_column, '` ',
            "CHAR(3) NOT NULL DEFAULT 'JMD' COMMENT 'ISO-4217 code: JMD|USD|GBP|EUR|CAD'"
        );
        PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL prms_widen_currency_column('procurement_requests', 'currency');
CALL prms_widen_currency_column('rfq_quotes',           'currency');
CALL prms_widen_currency_column('service_contracts',    'currency');

DROP PROCEDURE IF EXISTS prms_widen_currency_column;

-- ===========================================================================
-- 3. ADD CURRENCY COLUMNS TO REMAINING TRANSACTIONAL TABLES
--    Every new column defaults to 'JMD' so existing rows are valid and keep
--    behaving exactly as before (they were implicitly JMD).
-- ===========================================================================

DROP PROCEDURE IF EXISTS prms_add_currency_column;
DELIMITER $$
CREATE PROCEDURE prms_add_currency_column(IN p_table VARCHAR(64), IN p_after VARCHAR(64))
BEGIN
    DECLARE v_table_exists  INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;
    DECLARE v_after_exists  INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table_exists > 0 THEN
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = 'currency';

        SELECT COUNT(*) INTO v_after_exists
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_after;

        IF v_column_exists = 0 THEN
            SET @ddl = CONCAT(
                'ALTER TABLE `', p_table, '` ADD COLUMN `currency` ',
                "CHAR(3) NOT NULL DEFAULT 'JMD' COMMENT 'ISO-4217 code: JMD|USD|GBP|EUR|CAD'",
                IF(v_after_exists > 0, CONCAT(' AFTER `', p_after, '`'), '')
            );
            PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL prms_add_currency_column('commitments',                'commitment_total');
CALL prms_add_currency_column('purchase_orders',            'po_total');
CALL prms_add_currency_column('invoices',                   'invoice_amount');
CALL prms_add_currency_column('payments',                   'payment_amount');
CALL prms_add_currency_column('reimbursement_invoices',     'invoice_amount');
CALL prms_add_currency_column('petty_cash_disbursements',   'amount_authorized');
CALL prms_add_currency_column('petty_cash_reconciliations', 'purchase_amount');
CALL prms_add_currency_column('po_advance_payments',        'payment_amount');
CALL prms_add_currency_column('po_variations',              'variation_amount');

DROP PROCEDURE IF EXISTS prms_add_currency_column;

-- ---------------------------------------------------------------------------
-- 3b. Normalise any legacy/blank currency values to the system default.
--     Only rows that are empty/NULL/invalid are touched; valid codes are kept.
-- ---------------------------------------------------------------------------
UPDATE procurement_requests
   SET currency = 'JMD'
 WHERE currency IS NULL
    OR TRIM(currency) = ''
    OR UPPER(TRIM(currency)) NOT IN ('JMD', 'USD', 'GBP', 'EUR', 'CAD');

UPDATE rfq_quotes
   SET currency = 'JMD'
 WHERE currency IS NULL
    OR TRIM(currency) = ''
    OR UPPER(TRIM(currency)) NOT IN ('JMD', 'USD', 'GBP', 'EUR', 'CAD');

-- ---------------------------------------------------------------------------
-- 3c. Propagate the request currency to downstream records that already exist,
--     so a legacy USD request does not display JMD on its PO / invoice chain.
--     Only rows still holding the default are updated; nothing else is touched.
-- ---------------------------------------------------------------------------
UPDATE commitments c
  JOIN procurement_requests r ON r.request_id = c.request_id
   SET c.currency = r.currency
 WHERE c.currency = 'JMD' AND r.currency <> 'JMD';

UPDATE purchase_orders p
  JOIN commitments c ON c.commitment_id = p.commitment_id
   SET p.currency = c.currency
 WHERE p.currency = 'JMD' AND c.currency <> 'JMD';

UPDATE invoices i
  JOIN purchase_orders p ON p.po_id = i.po_id
   SET i.currency = p.currency
 WHERE i.currency = 'JMD' AND p.currency <> 'JMD';

UPDATE payments y
  JOIN invoices i ON i.invoice_id = y.invoice_id
   SET y.currency = i.currency
 WHERE y.currency = 'JMD' AND i.currency <> 'JMD';

-- Reporting/filtering indexes
DROP PROCEDURE IF EXISTS prms_add_index_if_missing;
DELIMITER $$
CREATE PROCEDURE prms_add_index_if_missing(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_index_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table_exists > 0 THEN
        SELECT COUNT(*) INTO v_index_exists
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index;

        IF v_index_exists = 0 THEN
            SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
            PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL prms_add_index_if_missing('procurement_requests', 'idx_pr_currency',  '`currency`');
CALL prms_add_index_if_missing('commitments',          'idx_cmt_currency', '`currency`');
CALL prms_add_index_if_missing('purchase_orders',      'idx_po_currency',  '`currency`');
CALL prms_add_index_if_missing('invoices',             'idx_inv_currency', '`currency`');
CALL prms_add_index_if_missing('payments',             'idx_pay_currency', '`currency`');

-- ===========================================================================
-- 4. NOTIFICATION TYPE EXTENSION
--    Adds the approval-broadcast and reminder types used by the new services.
--    Existing enum members are preserved in their original order.
-- ===========================================================================
-- Extend the existing ENUM definition in place (rather than replacing it with
-- a fixed literal list) so any deployment-specific members already present
-- (for example 'finance_action_required' from
-- migrations/2026_08_25_user_notifications_finance_action_type.sql) survive
-- untouched, and only the new members are appended.
SET @nt_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_notifications'
      AND COLUMN_NAME = 'type'
);
SET @nt_new_type = @nt_type;
SET @nt_new_type = IF(@nt_new_type IS NOT NULL AND LOCATE("'procurement_approved'", @nt_new_type) = 0,
    CONCAT(SUBSTRING(@nt_new_type, 1, LENGTH(@nt_new_type) - 1), ",'procurement_approved')"),
    @nt_new_type);
SET @nt_new_type = IF(@nt_new_type IS NOT NULL AND LOCATE("'reminder'", @nt_new_type) = 0,
    CONCAT(SUBSTRING(@nt_new_type, 1, LENGTH(@nt_new_type) - 1), ",'reminder')"),
    @nt_new_type);
SET @nt_new_type = IF(@nt_new_type IS NOT NULL AND LOCATE("'escalation'", @nt_new_type) = 0,
    CONCAT(SUBSTRING(@nt_new_type, 1, LENGTH(@nt_new_type) - 1), ",'escalation')"),
    @nt_new_type);
SET @ddl = IF(@nt_type IS NOT NULL AND @nt_new_type <> @nt_type,
    CONCAT('ALTER TABLE `user_notifications` MODIFY COLUMN `type` ', @nt_new_type, ' NOT NULL'),
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- 5. SMART REMINDERS
-- ===========================================================================

-- 5a. Admin-configurable reminder definitions
CREATE TABLE IF NOT EXISTS reminder_settings (
    reminder_type       VARCHAR(50)  NOT NULL
                        COMMENT 'RFQ_PENDING | COMMITMENT_PENDING | PO_PENDING | INVOICE_PENDING | PAYMENT_PENDING | INVENTORY_RECEIPT_PENDING',
    label               VARCHAR(120) NOT NULL,
    description         VARCHAR(255) NULL,
    is_enabled          TINYINT(1)   NOT NULL DEFAULT 1,
    initial_delay_days  INT          NOT NULL DEFAULT 3
                        COMMENT 'Days after the trigger event before the first reminder',
    repeat_interval_days INT         NOT NULL DEFAULT 3
                        COMMENT 'Days between subsequent reminders (0 = send once)',
    escalation_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    escalation_after_days INT        NOT NULL DEFAULT 7
                        COMMENT 'Days after the trigger event before escalating',
    escalation_role     VARCHAR(100) NULL
                        COMMENT 'Role notified on escalation',
    priority            ENUM('normal','high','urgent') NOT NULL DEFAULT 'high',
    max_attempts        INT          NOT NULL DEFAULT 3
                        COMMENT 'Delivery retry attempts before a reminder is marked failed',
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reminder_type),
    KEY idx_reminder_settings_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reminder_settings
    (reminder_type, label, description, is_enabled, initial_delay_days, repeat_interval_days,
     escalation_enabled, escalation_after_days, escalation_role, priority, max_attempts)
VALUES
    ('RFQ_PENDING', 'RFQ not created after approval',
     'Approved request has no RFQ raised yet', 1, 3, 3, 1, 7, 'Procurement Officer', 'high', 3),
    ('COMMITMENT_PENDING', 'Commitment not uploaded',
     'Awarded request has no commitment recorded yet', 1, 3, 3, 1, 7, 'Finance Officer', 'high', 3),
    ('PO_PENDING', 'Purchase Order pending',
     'Commitment exists but no purchase order has been raised', 1, 3, 3, 1, 7, 'Procurement Officer', 'high', 3),
    ('INVOICE_PENDING', 'Invoice pending',
     'Purchase order approved but no invoice received', 1, 5, 5, 1, 10, 'Accounts Officer', 'normal', 3),
    ('PAYMENT_PENDING', 'Payment pending',
     'Invoice received but not fully paid', 1, 5, 5, 1, 10, 'Director Accounts & Finance', 'high', 3),
    ('INVENTORY_RECEIPT_PENDING', 'Inventory receipt pending',
     'Goods paid for but not receipted into inventory', 1, 3, 3, 1, 7, 'Property Management Officer', 'normal', 3)
ON DUPLICATE KEY UPDATE
    label       = VALUES(label),
    description = VALUES(description);

-- 5b. Reminder delivery log with failure + retry tracking.
--     Separate from the legacy `reminder_log` (overdue_alerts.php) so that job
--     keeps working untouched.
CREATE TABLE IF NOT EXISTS reminder_dispatch_log (
    id               BIGINT       NOT NULL AUTO_INCREMENT,
    execution_id     INT          NULL COMMENT 'cron_execution_log.id',
    reminder_type    VARCHAR(50)  NOT NULL,
    request_id       INT          NULL,
    request_ref      VARCHAR(100) NULL,
    user_id          INT          NULL,
    escalation_level TINYINT      NOT NULL DEFAULT 0 COMMENT '0=reminder, 1=escalation',
    status           ENUM('sent','failed','retrying','skipped') NOT NULL DEFAULT 'sent',
    attempts         INT          NOT NULL DEFAULT 1,
    days_outstanding INT          NULL,
    error_message    TEXT         NULL,
    notification_id  INT          NULL,
    email_sent       TINYINT(1)   NOT NULL DEFAULT 0,
    sent_on          DATE         NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reminder_per_day (reminder_type, request_id, user_id, escalation_level, sent_on),
    KEY idx_rdl_status (status),
    KEY idx_rdl_type_request (reminder_type, request_id),
    KEY idx_rdl_execution (execution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5c. Cron schedule registry so admins can verify jobs are active
CREATE TABLE IF NOT EXISTS cron_job_registry (
    cron_name        VARCHAR(80)  NOT NULL,
    label            VARCHAR(120) NOT NULL,
    schedule_expr    VARCHAR(60)  NOT NULL COMMENT 'Documented crontab expression',
    is_enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    last_started_at  DATETIME     NULL,
    last_success_at  DATETIME     NULL,
    last_status      VARCHAR(20)  NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cron_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_job_registry (cron_name, label, schedule_expr, is_enabled)
VALUES
    ('procurement_reminders', 'Procurement smart reminders', '0 7 * * *', 1),
    ('overdue_alerts',        'Overdue workflow alerts',     '0 8 * * *', 1),
    ('inventory_alerts',      'Inventory stock alerts',      '0 6 * * *', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), schedule_expr = VALUES(schedule_expr);

-- ===========================================================================
-- 6. PERMISSIONS
-- ===========================================================================
INSERT IGNORE INTO permissions (name, description) VALUES
    ('view_notification_center', 'View the centralised notification center'),
    ('manage_reminder_settings', 'Configure smart reminder intervals, toggles and escalation'),
    ('manage_currencies',        'Manage the list of supported currencies');

-- Notification center is available to every role that can sign in.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'view_notification_center';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name IN ('manage_reminder_settings', 'manage_currencies')
  AND r.name IN ('Admin', 'SuperAdmin');

-- ===========================================================================
-- ROLLBACK (manual)
-- ===========================================================================
-- DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
--   WHERE p.name IN ('view_notification_center','manage_reminder_settings','manage_currencies');
-- DELETE FROM permissions WHERE name IN ('view_notification_center','manage_reminder_settings','manage_currencies');
-- DROP TABLE IF EXISTS cron_job_registry;
-- DROP TABLE IF EXISTS reminder_dispatch_log;
-- DROP TABLE IF EXISTS reminder_settings;
-- DROP TABLE IF EXISTS currencies;
-- DELETE FROM system_config WHERE config_key IN ('default_currency','enable_multi_currency','gbp_to_jmd_rate','eur_to_jmd_rate','cad_to_jmd_rate');
-- ALTER TABLE commitments     DROP COLUMN currency;  -- repeat per table added in section 3
-- ALTER TABLE procurement_requests MODIFY COLUMN currency ENUM('JMD','USD') NOT NULL DEFAULT 'JMD';
