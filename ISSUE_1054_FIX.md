# Fix for Issue #1054: Unknown column 'spec_review_status' in 'rfqs'

## Problem Summary

Users encountered the error:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'spec_review_status' in 'rfqs'
```

This error occurred when:
- Fresh database installations used `combined_schema.sql` + migrations
- Triggers referenced columns that were renamed in earlier migrations
- Stored procedures needed for backward compatibility were missing

## Root Cause

### Schema Evolution Timeline

1. **July 31, 2026**: Migration `2026_07_31_rfq_quote_approval_workflow.sql` created:
   - Columns: `spec_review_status`, `spec_reviewer_id`, `spec_reviewed_at`, `spec_review_comments`
   - Triggers: Three triggers using these column names
   - Stored procedures: `sp_approve_rfq_spec_review`, `sp_reject_rfq_spec_review`

2. **August 21, 2026**: Migration `2026_08_21_requestor_branch_head_approval_workflow.sql` renamed columns:
   - `spec_review_status` → `requestor_spec_review_status`
   - Related columns also renamed with `requestor_` prefix
   - Updated some triggers but missed others

3. **August 25, 2026**: Partial fixes applied via multiple migrations, but gaps remained

4. **November 12, 2026**: This comprehensive fix ensures consistency

### Why the Error Occurred

**Issue 1: Missing Columns in combined_schema.sql**
- The `combined_schema.sql` file (used for fresh database installations) was never updated after migrations added approval workflow columns
- Fresh installs had the base schema but lacked the approval workflow columns

**Issue 2: Inconsistent Trigger Names**
- Trigger `trg_initialize_spec_review_on_first_quote` still referenced old column name `spec_review_status`
- When this trigger fired (on quote upload), it caused the "Unknown column" error

**Issue 3: Missing Backward-Compatible Procedures**
- Old procedure names were dropped but no aliases were created
- Legacy integrations calling old procedure names would fail

## Solution

### New Migration: 2026_11_12_fix_rfq_spec_review_status_column_1054.sql

This comprehensive, idempotent migration ensures:

1. **All approval workflow columns exist** with correct names:
   - `requestor_spec_review_status` (ENUM: PENDING, APPROVED, REJECTED)
   - `requestor_reviewer_id`
   - `requestor_reviewed_at`
   - `requestor_review_comments`
   - `branch_head_approval_status` (ENUM: PENDING, APPROVED, REJECTED, RETURNED)
   - `branch_head_approver_id`
   - `branch_head_approved_at`
   - `branch_head_comments`

2. **All supporting tables exist**:
   - `rfq_requestor_reviews` - Immutable history of requestor reviews
   - `rfq_quote_approvals` - Complete audit trail of all approvals
   - `rfq_spec_reviewers` - Track spec reviewer assignments
   - `rfq_branch_head_approvers` - Track branch head assignments

3. **All triggers use correct column names**:
   - `trg_initialize_rfq_approval_workflow` - Initializes approval statuses when RFQ is created
   - `trg_require_quote_approval_for_commitment` - Enforces both approvals before commitment
   - `trg_initialize_spec_review_on_first_quote` - Sets status on first quote upload

4. **All procedures exist** with backward compatibility:
   - Primary procedures: `sp_approve_rfq_requestor_review`, `sp_reject_rfq_requestor_review`
   - Backward-compatible aliases: `sp_approve_rfq_spec_review`, `sp_reject_rfq_spec_review`

5. **Proper indexes** for performance:
   - Index on `requestor_spec_review_status`
   - Index on `branch_head_approval_status`
   - Index on `branch_head_approver_id`

## Two-Stage Approval Workflow

The RFQ approval workflow consists of two independent approval stages:

### Stage 1: Requestor Specification Confirmation
- **Who**: Requestor (RFQ creator or override)
- **What**: Reviews selected quote compliance with specifications
- **Column**: `requestor_spec_review_status`
- **Outcomes**:
  - `APPROVED` - Specifications are met
  - `REJECTED` - Specifications are not met (returns to quote selection)
  - `PENDING` - Awaiting review

### Stage 2: Branch Head Final Approval
- **When**: Only after Stage 1 is approved
- **Who**: Branch Head or designated approver
- **What**: Reviews and approves/rejects the RFQ award
- **Column**: `branch_head_approval_status`
- **Outcomes**:
  - `APPROVED` - Award approved
  - `REJECTED` - Award rejected
  - `RETURNED` - Returned for clarification
  - `PENDING` - Awaiting approval

### Commitment Creation Requirements
The trigger `trg_require_quote_approval_for_commitment` prevents commitment creation until:
- `requestor_spec_review_status` = 'APPROVED' AND
- `branch_head_approval_status` = 'APPROVED'

## Deployment Instructions

### For Existing Installations

Apply the migration via standard deployment process:

```bash
# Using application migration runner (if available)
php migrations/apply.php 2026_11_12_fix_rfq_spec_review_status_column_1054.sql

# Or via MySQL CLI
mysql -u username -p database_name < migrations/2026_11_12_fix_rfq_spec_review_status_column_1054.sql
```

### For Fresh Installations

The migration is automatically included in the migration sequence and will be applied during standard database setup.

## Verification

### After Deployment

Verify the fix with these commands:

```sql
-- 1. Check columns exist
SELECT COLUMN_NAME 
FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'rfqs' 
AND TABLE_SCHEMA = DATABASE()
AND COLUMN_NAME LIKE '%spec_review%'
OR COLUMN_NAME LIKE '%branch_head%';

-- Expected columns should include:
-- - requestor_spec_review_status
-- - requestor_reviewer_id
-- - requestor_reviewed_at
-- - requestor_review_comments
-- - branch_head_approval_status
-- - branch_head_approver_id
-- - branch_head_approved_at
-- - branch_head_comments

-- 2. Check triggers exist and use correct column names
SHOW CREATE TRIGGER trg_initialize_spec_review_on_first_quote;
-- Should show: SET requestor_spec_review_status = 'PENDING'

-- 3. Check procedures exist
SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE '%rfq%spec%';
-- Should list all 4 procedures (2 primary + 2 aliases)

-- 4. Check supporting tables
SHOW TABLES LIKE 'rfq_requestor_reviews';
SHOW TABLES LIKE 'rfq_quote_approvals';
SHOW TABLES LIKE 'rfq_spec_reviewers';
SHOW TABLES LIKE 'rfq_branch_head_approvers';
```

### Functional Test

1. Create a new RFQ
2. Add a vendor and upload a quote
   - Should NOT encounter "Unknown column" error
   - `requestor_spec_review_status` should be initialized to 'PENDING'
3. Select the quote for requestor review
4. Submit requestor approval
   - `requestor_spec_review_status` should change to 'APPROVED'
5. Proceed to branch head approval
6. Approve the award
   - `branch_head_approval_status` should change to 'APPROVED'
7. Create commitment
   - Should succeed (both approvals are approved)

## Impact Analysis

### Fixed Issues
✅ "Unknown column 'spec_review_status'" error eliminated  
✅ Trigger column references corrected  
✅ Backward-compatible procedures restored  
✅ Missing tables created  
✅ All indexes in place  

### No Negative Impact
✅ Existing data preserved  
✅ No breaking changes  
✅ Idempotent (safe to run multiple times)  
✅ Works with both fresh installs and existing databases  

### Backward Compatibility
✅ Old procedure names (`sp_approve_rfq_spec_review`, `sp_reject_rfq_spec_review`) work via aliases  
✅ All existing approval workflow functionality preserved  
✅ Audit trails remain intact  

## Database Schema Reference

### rfqs Table Additions
```sql
-- Requestor Specification Confirmation Stage
requestor_spec_review_status ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING'
requestor_reviewer_id INT(11)
requestor_reviewed_at DATETIME
requestor_review_comments TEXT

-- Branch Head Final Approval Stage
branch_head_approval_status ENUM('PENDING','APPROVED','REJECTED','RETURNED') DEFAULT 'PENDING'
branch_head_approver_id INT(11)
branch_head_approved_at DATETIME
branch_head_comments TEXT
```

### New Supporting Tables

**rfq_requestor_reviews**
- Immutable record of each requestor's specification review
- Contains: rfq_id, requestor_id, review_outcome, comments, timestamps

**rfq_quote_approvals**
- Complete audit trail of all approvals
- Contains: rfq_id, quote_id, approval_stage, approver_id, action, comments, timestamps

**rfq_spec_reviewers**
- Assignment tracking for spec reviewers
- Contains: rfq_id, reviewer_id, role, assignment info

**rfq_branch_head_approvers**
- Assignment tracking for branch head approvers
- Contains: rfq_id, approver_id, role, assignment info

## Related Issues and Documentation

- **Documentation**: `RFQ_SPEC_REVIEW_ERROR_FIX_COMPLETE.md`
- **Spec Review Workflow**: See `services/RequestorSpecificationReviewService.php`
- **Quote Approval Workflow**: See `services/RFQQuoteApprovalService.php`
- **Previous Fixes**: 
  - `2026_08_25_fix_spec_review_trigger_and_procedures.sql`
  - `2026_08_25_fix_rfq_triggers_column_names.sql`

## Support and Troubleshooting

### If You Encounter Issues

1. **"Column already exists" error**
   - This is normal if the column was added by an earlier migration
   - The migration uses `IF NOT EXISTS` clauses and is safe to re-run

2. **Trigger not updated error**
   - The migration drops and recreates all triggers
   - If a trigger references stale columns, re-running the migration fixes it

3. **Procedure not found error**
   - The migration creates all procedures including backward-compatible aliases
   - Verify with: `SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE '%rfq%';`

4. **Commitment creation still fails**
   - Check that both approval statuses are actually 'APPROVED'
   - Query: `SELECT rfq_id, requestor_spec_review_status, branch_head_approval_status FROM rfqs WHERE rfq_id = ?;`

## Summary

This migration provides a comprehensive fix for issue #1054 by ensuring complete consistency across all deployment paths (fresh install, existing database, partial deployments). The two-stage RFQ approval workflow now functions correctly with proper triggers, procedures, tables, and column naming throughout.

---

**Migration**: `migrations/2026_11_12_fix_rfq_spec_review_status_column_1054.sql`  
**Status**: Ready for deployment  
**Testing**: Tested for idempotence and backward compatibility  
**Date**: November 12, 2026
