-- Parallel Procurement Mode
-- =========================
-- Introduces an OPTIONAL "PARALLEL_MODE" workflow alongside the existing
-- sequential pipeline, which is preserved unchanged as "LEGACY_MODE".
--
-- In PARALLEL_MODE, once a request has cleared its required Branch Head /
-- approval chain, Procurement and Accounts activities (RFQ, commitment, PO,
-- invoice, payment) may proceed independently.  Fund verification becomes
-- optional/informational rather than a hard dependency before RFQ.
--
-- This migration is additive and idempotent:
--   * no existing column is modified or dropped
--   * no existing row is rewritten destructively
--   * every existing request keeps its current `status`
--
-- Rollback: see the bottom of this file.

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Global workflow mode switch (Admin → System Settings)
-- ---------------------------------------------------------------------------
INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES (
    'procurement_workflow_mode',
    'LEGACY_MODE',
    'Procurement workflow mode: LEGACY_MODE (sequential stage gating) or PARALLEL_MODE (independent departmental tasks after approval)',
    NOW()
)
ON DUPLICATE KEY UPDATE config_value = config_value;

COMMIT;

-- ---------------------------------------------------------------------------
-- 2. Per-request workflow mode (idempotent ADD COLUMN)
--    'INHERIT' => follow the global system setting.  This is what makes an
--    existing record automatically render with the task-tracking view the
--    moment an administrator switches the system to PARALLEL_MODE.
--    A record may also be pinned explicitly to LEGACY_MODE or PARALLEL_MODE.
-- ---------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'procurement_requests'
      AND COLUMN_NAME = 'workflow_mode'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE procurement_requests
       ADD COLUMN workflow_mode VARCHAR(20) NOT NULL DEFAULT 'INHERIT'
       COMMENT 'INHERIT | LEGACY_MODE | PARALLEL_MODE'",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'procurement_requests'
      AND INDEX_NAME = 'idx_pr_workflow_mode'
);
SET @ddl = IF(@idx_exists = 0,
    'ALTER TABLE procurement_requests ADD INDEX idx_pr_workflow_mode (workflow_mode)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Task completion tracking
--    Replaces strict pipeline dependency logic with per-task completion state.
--    Rows here are OVERRIDES / explicit records.  When no row exists for a
--    task, ProcurementTaskService derives the task state from live data
--    (rfqs, commitments, purchase_orders, invoices, payments), so legacy
--    records display correctly with no data backfill required.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS procurement_tasks (
    task_id             INT AUTO_INCREMENT PRIMARY KEY,
    request_id          INT NOT NULL,
    task_key            VARCHAR(40) NOT NULL
                        COMMENT 'REQUEST_APPROVAL | FUND_VERIFICATION | RFQ | COMMITMENT | PO | INVOICE | PAYMENT',
    task_status         VARCHAR(20) NOT NULL DEFAULT 'PENDING'
                        COMMENT 'PENDING | IN_PROGRESS | COMPLETED | NOT_REQUIRED',
    task_completed_by   INT NULL,
    task_completed_date DATETIME NULL,
    notes               VARCHAR(255) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_request_task (request_id, task_key),
    KEY idx_pt_request (request_id),
    KEY idx_pt_status (task_status),
    CONSTRAINT fk_procurement_tasks_request
        FOREIGN KEY (request_id) REFERENCES procurement_requests (request_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. Backfill task rows for COMPLETED work on existing records.
--    Only facts that are already true in the data are written.  Nothing is
--    invented, nothing is removed, and derivation still covers any gap.
--    INSERT IGNORE keeps the migration safely re-runnable.
-- ---------------------------------------------------------------------------

-- 4a. Approval already granted
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_by, task_completed_date)
SELECT r.request_id, 'REQUEST_APPROVAL', 'COMPLETED', r.approved_by, COALESCE(r.approved_at, r.updated_at, r.created_at)
FROM procurement_requests r
WHERE UPPER(r.status) NOT IN ('DRAFT', 'SUBMITTED', 'DECLINED', 'CANCELLED');

-- 4b. Funds already verified (informational in PARALLEL_MODE)
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_by, task_completed_date)
SELECT r.request_id, 'FUND_VERIFICATION', 'COMPLETED', r.finance_reviewed_by, COALESCE(r.finance_reviewed_at, r.updated_at)
FROM procurement_requests r
WHERE r.finance_reviewed_at IS NOT NULL OR r.funds_available = 1 OR UPPER(r.status) = 'FUNDS_VERIFIED';

-- 4c. RFQ not required (direct / skip-RFQ procurement)
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status)
SELECT r.request_id, 'RFQ', 'NOT_REQUIRED'
FROM procurement_requests r
LEFT JOIN rfqs q ON q.request_id = r.request_id
WHERE q.rfq_id IS NULL AND r.requires_rfq = 0;

-- 4d. RFQ awarded
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_by, task_completed_date)
SELECT q.request_id, 'RFQ', 'COMPLETED', q.created_by, q.created_at
FROM rfqs q
WHERE UPPER(q.status) = 'AWARDED';

-- 4e. Commitment closed
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_date)
SELECT c.request_id, 'COMMITMENT', 'COMPLETED', COALESCE(c.approved_at, c.created_at)
FROM commitments c
WHERE c.commitment_type = 'ORIGINAL' AND LOWER(c.status) = 'closed';

-- 4f. PO created and approved
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_by, task_completed_date)
SELECT c.request_id, 'PO', 'COMPLETED', p.approved_by, COALESCE(p.approved_at, p.created_at)
FROM purchase_orders p
JOIN commitments c ON c.commitment_id = p.commitment_id
WHERE p.approved_at IS NOT NULL;

-- 4g. Invoice fully paid
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_date)
SELECT c.request_id, 'INVOICE', 'COMPLETED', i.created_at
FROM invoices i
JOIN purchase_orders p ON p.po_id = i.po_id
JOIN commitments c ON c.commitment_id = p.commitment_id
WHERE LOWER(i.status) = 'paid';

-- 4h. Payment recorded against a fully paid invoice
INSERT IGNORE INTO procurement_tasks (request_id, task_key, task_status, task_completed_by, task_completed_date)
SELECT c.request_id, 'PAYMENT', 'COMPLETED', y.created_by, y.payment_date
FROM payments y
JOIN invoices i ON i.invoice_id = y.invoice_id
JOIN purchase_orders p ON p.po_id = i.po_id
JOIN commitments c ON c.commitment_id = p.commitment_id
WHERE LOWER(i.status) = 'paid';

-- ---------------------------------------------------------------------------
-- 5. Permission for the Procurement Progress Dashboard
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO permissions (name, description)
VALUES ('view_procurement_progress', 'View the procurement progress / task tracking dashboard');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'view_procurement_progress'
  AND r.name IN (
      'Admin', 'SuperAdmin', 'Procurement Officer', 'Finance Officer',
      'Director Accounts & Finance', 'Director HRM&A', 'HOD', 'Branch Head',
      'Deputy Government Chemist', 'Government Chemist'
  );

-- ---------------------------------------------------------------------------
-- ROLLBACK (manual)
-- ---------------------------------------------------------------------------
-- UPDATE system_config SET config_value = 'LEGACY_MODE' WHERE config_key = 'procurement_workflow_mode';
-- DROP TABLE IF EXISTS procurement_tasks;
-- ALTER TABLE procurement_requests DROP INDEX idx_pr_workflow_mode;
-- ALTER TABLE procurement_requests DROP COLUMN workflow_mode;
-- DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.name = 'view_procurement_progress';
-- DELETE FROM permissions WHERE name = 'view_procurement_progress';
