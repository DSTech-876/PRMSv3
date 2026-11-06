-- Separate Director Accounts & Finance permissions from Finance Officer
-- =====================================================================
-- Director Accounts & Finance is a supervisory/approval role: it approves
-- escalated/finance-stage decisions and oversees the Finance Officer's
-- day-to-day processing work. It should NOT share Finance Officer's
-- purely operational permissions (recording invoices/payments,
-- reconciling petty cash, verifying reimbursement goods/receipts) —
-- those remain exclusive to Finance Officer. In exchange, Director
-- Accounts & Finance is granted the genuine chain-stage approval
-- permissions ('approve_request', 'approve_commitment') it was missing,
-- so it can actually act on finance approvals (previously it held the
-- permission bit for petty cash/reimbursement approvals only, and was
-- additionally blocked by hard-coded role checks in the approval
-- handlers — see accompanying PHP changes).

START TRANSACTION;

SET @director_finance_role_id = (
    SELECT id FROM roles WHERE name = 'Director Accounts & Finance' LIMIT 1
);

-- Remove Finance Officer's day-to-day operational permissions from the
-- Director role. These represent executing/recording work, not approving.
DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = @director_finance_role_id
  AND @director_finance_role_id IS NOT NULL
  AND p.name IN (
      'record_invoice',
      'record_payment',
      'reconcile_petty_cash',
      'verify_petty_cash_reconciliation',
      'verify_reimbursement_goods',
      'print_invoice',
      'print_purchase_order'
  );

-- Grant the chain-stage approval permissions required for Director
-- Accounts & Finance to genuinely act as a finance approver.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @director_finance_role_id, p.id
FROM permissions p
WHERE @director_finance_role_id IS NOT NULL
  AND p.name IN (
      'approve_request',
      'approve_commitment'
  );

COMMIT;
