-- Allow Director Accounts & Finance to verify funds
-- =================================================
-- Fund verification is a workflow stage action (it closes the pending
-- Finance Officer approval and advances the request to FUNDS_VERIFIED),
-- not day-to-day operational recording. canApproveStage() already treats
-- Director Accounts & Finance as an equivalent actor for the Finance
-- Officer stage, but the role did not hold the 'verify_funds' permission,
-- so /procurement/verify_funds.php denied access with
-- "You do not have permission to access this page."
--
-- The page additionally accepts 'approve_request' as an alternate
-- permission (see $ALTERNATE_PERMISSIONS in procurement/verify_funds.php),
-- but granting the explicit permission keeps the admin permission matrix
-- and any page_permissions override consistent.

START TRANSACTION;

SET @director_finance_role_id = (
    SELECT id FROM roles WHERE name = 'Director Accounts & Finance' LIMIT 1
);

INSERT IGNORE INTO permissions (name, description)
VALUES ('verify_funds', 'Verify fund availability for procurement requests');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @director_finance_role_id, p.id
FROM permissions p
WHERE @director_finance_role_id IS NOT NULL
  AND p.name = 'verify_funds';

COMMIT;
