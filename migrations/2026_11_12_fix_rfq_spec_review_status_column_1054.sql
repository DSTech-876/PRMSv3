-- Migration: Fix Unknown column 'spec_review_status' in 'rfqs' - Issue #1054
-- Purpose: Ensure rfqs table has all required approval workflow columns
--          and supporting tables needed for the RFQ approval workflow
--
-- Background:
-- Issue #1054 reported "Unknown column 'spec_review_status' in 'rfqs'"
-- This occurred because:
-- 1. combined_schema.sql doesn't include columns added by later migrations
-- 2. When fresh installations run combined_schema.sql + migrations,
--    there's a timing issue where triggers reference columns that don't exist yet
-- 3. Stored procedures need to exist for backward compatibility
--
-- Solution:
-- This idempotent migration ensures all required columns, tables, triggers,
-- and procedures exist with correct names, regardless of deployment path
-- (fresh install, existing database, or specific migration subset).
-- ===================================================================

-- ===================================
-- 1. Add missing approval workflow columns to rfqs table
-- ===================================
-- These columns track the two-stage approval workflow:
-- Stage 1: Requestor Specification Confirmation
-- Stage 2: Branch Head Final Approval

ALTER TABLE `rfqs`
  ADD COLUMN IF NOT EXISTS `requestor_spec_review_status` 
    ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING'
    AFTER `quote_review_status`,
  ADD COLUMN IF NOT EXISTS `requestor_reviewer_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `requestor_reviewed_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `requestor_review_comments` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `branch_head_approval_status` 
    ENUM('PENDING','APPROVED','REJECTED','RETURNED') DEFAULT 'PENDING',
  ADD COLUMN IF NOT EXISTS `branch_head_approver_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `branch_head_approved_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `branch_head_comments` TEXT DEFAULT NULL;

-- ===================================
-- 2. Add indexes for approval workflow queries
-- ===================================
CREATE INDEX IF NOT EXISTS idx_rfq_requestor_spec_review_status 
  ON rfqs(`requestor_spec_review_status`);
CREATE INDEX IF NOT EXISTS idx_rfq_branch_head_approval_status 
  ON rfqs(`branch_head_approval_status`);
CREATE INDEX IF NOT EXISTS idx_rfq_branch_head_approver_id 
  ON rfqs(`branch_head_approver_id`);

-- ===================================
-- 3. Clean up old column names if they exist from earlier schema
-- ===================================
-- Handle case where old migration was partially applied
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_NAME = 'rfqs' 
    AND TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME = 'spec_review_status'
);

SET @sql := IF(@col_exists > 0,
  'ALTER TABLE `rfqs` DROP COLUMN `spec_review_status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===================================
-- 4. Ensure supporting tables exist
-- ===================================

-- Table for tracking requestor specification reviews
CREATE TABLE IF NOT EXISTS `rfq_requestor_reviews` (
  `rfq_requestor_review_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `requestor_id` INT(11) NOT NULL,
  `review_outcome` ENUM('MEETS_SPECIFICATIONS','DOES_NOT_MEET_SPECIFICATIONS','FURTHER_REVIEW_NEEDED') 
    DEFAULT 'MEETS_SPECIFICATIONS',
  `comments` TEXT DEFAULT NULL,
  `review_date` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_requestor_id` (`requestor_id`),
  KEY `idx_review_outcome` (`review_outcome`),
  CONSTRAINT `fk_rfq_requestor_reviews_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_requestor_reviews_requestor` FOREIGN KEY (`requestor_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Track requestor specification review outcomes';

-- Table for complete approval audit trail
CREATE TABLE IF NOT EXISTS `rfq_quote_approvals` (
  `approval_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `quote_id` INT(11) DEFAULT NULL COMMENT 'Quote ID if specific to a quote',
  `approval_stage` VARCHAR(100) NOT NULL COMMENT 'REQUESTOR_REVIEW, BRANCH_HEAD_APPROVAL, etc.',
  `approver_id` INT(11) NOT NULL COMMENT 'User who performed the action',
  `approver_role` VARCHAR(100) DEFAULT NULL COMMENT 'Role of approver',
  `action` ENUM('APPROVED','REJECTED','RETURNED_FOR_CLARIFICATION') NOT NULL,
  `comments` TEXT DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `requestor_notes` TEXT DEFAULT NULL,
  `vendor_submission_details` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_quote_id` (`quote_id`),
  KEY `idx_approval_stage` (`approval_stage`),
  KEY `idx_approver_id` (`approver_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_rfq_quote_approvals_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_quote_approvals_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Audit trail for RFQ quote approval workflow';

-- Table for spec reviewer assignments
CREATE TABLE IF NOT EXISTS `rfq_spec_reviewers` (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `reviewer_id` INT(11) NOT NULL,
  `reviewer_role` VARCHAR(100) NOT NULL,
  `assigned_by` INT(11) NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `idx_rfq_reviewer` (`rfq_id`, `reviewer_id`),
  KEY `idx_reviewer_id` (`reviewer_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_rfq_spec_reviewers_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_spec_reviewers_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_spec_reviewers_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Track spec review assignments for RFQs';

-- Table for branch head approval assignments
CREATE TABLE IF NOT EXISTS `rfq_branch_head_approvers` (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `approver_id` INT(11) NOT NULL,
  `approver_role` VARCHAR(100) NOT NULL,
  `assigned_by` INT(11) NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `idx_rfq_approver` (`rfq_id`, `approver_id`),
  KEY `idx_approver_id` (`approver_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_rfq_branch_head_approvers_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_branch_head_approvers_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_branch_head_approvers_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Track branch head approval assignments for RFQs';

-- ===================================
-- 5. Recreate triggers with correct column names
-- ===================================

-- Initialize approval statuses when RFQ is created
DROP TRIGGER IF EXISTS `trg_initialize_rfq_approval_workflow`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_rfq_approval_workflow` BEFORE INSERT ON `rfqs` FOR EACH ROW
BEGIN
    IF NEW.requestor_spec_review_status IS NULL THEN
        SET NEW.requestor_spec_review_status = 'PENDING';
    END IF;
    IF NEW.branch_head_approval_status IS NULL THEN
        SET NEW.branch_head_approval_status = 'PENDING';
    END IF;
END
$$
DELIMITER ;

-- Enforce approval requirements before commitment creation
DROP TRIGGER IF EXISTS `trg_require_quote_approval_for_commitment`;
DELIMITER $$
CREATE TRIGGER `trg_require_quote_approval_for_commitment` BEFORE INSERT ON `commitments` FOR EACH ROW
BEGIN
    DECLARE requestor_status VARCHAR(50);
    DECLARE branch_head_status VARCHAR(50);

    IF NEW.rfq_id IS NOT NULL AND NEW.selected_quote_id IS NOT NULL THEN
        SELECT requestor_spec_review_status, branch_head_approval_status
          INTO requestor_status, branch_head_status
          FROM rfqs
         WHERE rfq_id = NEW.rfq_id
         LIMIT 1;

        IF requestor_status IS NULL OR requestor_status != 'APPROVED' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot create commitment: Requestor specification confirmation not approved';
        END IF;

        IF branch_head_status IS NULL OR branch_head_status != 'APPROVED' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot create commitment: Branch Head approval not granted';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- Initialize spec review on first quote upload
DROP TRIGGER IF EXISTS `trg_initialize_spec_review_on_first_quote`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_spec_review_on_first_quote` AFTER INSERT ON `rfq_quotes` FOR EACH ROW 
BEGIN
    DECLARE quote_count INT;
    
    SELECT COUNT(*) INTO quote_count
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    WHERE rv.rfq_id = (
        SELECT rfq_id FROM rfq_vendors WHERE rfq_vendor_id = NEW.rfq_vendor_id
    );
    
    IF quote_count = 1 THEN
        UPDATE rfqs
        SET requestor_spec_review_status = 'PENDING'
        WHERE rfq_id = (
            SELECT rfq_id FROM rfq_vendors WHERE rfq_vendor_id = NEW.rfq_vendor_id LIMIT 1
        );
    END IF;
END
$$
DELIMITER ;

-- ===================================
-- 6. Recreate stored procedures
-- ===================================

-- Approve requestor specification review
DROP PROCEDURE IF EXISTS `sp_approve_rfq_requestor_review`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_requestor_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_comments TEXT,
    IN p_quote_id INT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
       SET requestor_spec_review_status = 'APPROVED',
           requestor_reviewer_id = p_requestor_id,
           requestor_reviewed_at = NOW(),
           requestor_review_comments = p_comments
     WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_requestor_reviews
        (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
    VALUES
        (p_rfq_id, p_requestor_id, 'MEETS_SPECIFICATIONS', p_comments, NOW(), NOW(), NOW());

    INSERT INTO rfq_quote_approvals
        (rfq_id, quote_id, approval_stage, approver_id, action, comments, requestor_notes, created_at)
    VALUES
        (p_rfq_id, p_quote_id, 'REQUESTOR_REVIEW', p_requestor_id, 'APPROVED', p_comments, p_comments, NOW());

    COMMIT;
END
$$
DELIMITER ;

-- Reject requestor specification review
DROP PROCEDURE IF EXISTS `sp_reject_rfq_requestor_review`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_requestor_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_reason TEXT,
    IN p_quote_id INT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
       SET requestor_spec_review_status = 'REJECTED',
           requestor_reviewer_id = p_requestor_id,
           requestor_reviewed_at = NOW(),
           requestor_review_comments = p_reason
     WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_requestor_reviews
        (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
    VALUES
        (p_rfq_id, p_requestor_id, 'DOES_NOT_MEET_SPECIFICATIONS', p_reason, NOW(), NOW(), NOW());

    INSERT INTO rfq_quote_approvals
        (rfq_id, quote_id, approval_stage, approver_id, action, comments, rejection_reason, requestor_notes, created_at)
    VALUES
        (p_rfq_id, p_quote_id, 'REQUESTOR_REVIEW', p_requestor_id, 'REJECTED', p_reason, p_reason, p_reason, NOW());

    COMMIT;
END
$$
DELIMITER ;

-- Backward-compatible aliases for old procedure names
DROP PROCEDURE IF EXISTS `sp_approve_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_comments TEXT,
    IN p_quote_id INT
)
BEGIN
    CALL sp_approve_rfq_requestor_review(p_rfq_id, p_requestor_id, p_comments, p_quote_id);
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reject_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_reason TEXT,
    IN p_quote_id INT
)
BEGIN
    CALL sp_reject_rfq_requestor_review(p_rfq_id, p_requestor_id, p_reason, p_quote_id);
END
$$
DELIMITER ;

-- ===================================
-- 7. Verification Queries (informational)
-- ===================================
-- Verify trigger definitions:
-- SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
-- FROM information_schema.TRIGGERS
-- WHERE EVENT_OBJECT_TABLE = 'rfqs' AND TRIGGER_SCHEMA = DATABASE();
--
-- Verify procedure existence:
-- SHOW PROCEDURE STATUS WHERE Name LIKE '%rfq%';
--
-- Verify columns:
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
-- WHERE TABLE_NAME = 'rfqs' AND COLUMN_NAME LIKE '%spec_review%'
-- OR TABLE_NAME = 'rfqs' AND COLUMN_NAME LIKE '%branch_head%';

-- ===================================
-- End of migration 2026_11_12_fix_rfq_spec_review_status_column_1054.sql
-- ===================================
