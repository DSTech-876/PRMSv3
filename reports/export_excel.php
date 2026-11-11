<?php
$REQUIRE_PERMISSION = 'view_management_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=branch_outstanding.xls");

/* Exports carry the currency code so values are never ambiguous downstream.
   When a currency is explicitly requested the underlying data is filtered to
   that currency; otherwise every currency is kept and each row is labeled
   with its own real currency, never a single assumed code. */
$explicitCurrency = (isset($_GET['currency']) && trim((string)$_GET['currency']) !== '')
    ? CurrencyService::normalize($_GET['currency'], $pdo)
    : null;

$sql = "
    SELECT
        b.branch_name,
        i.currency AS currency,
        COALESCE(SUM(i.invoice_amount), 0) AS total_invoiced,
        COALESCE(SUM(pay.paid_amount), 0) AS total_paid,
        COALESCE(SUM(i.invoice_amount), 0) - COALESCE(SUM(pay.paid_amount), 0) AS outstanding
    FROM branches b
    JOIN procurement_requests pr ON pr.branch_id = b.branch_id
    JOIN commitments c ON c.request_id = pr.request_id
    JOIN purchase_orders po ON po.commitment_id = c.commitment_id
    JOIN invoices i ON i.po_id = po.po_id
    LEFT JOIN (
        SELECT invoice_id, SUM(payment_amount) AS paid_amount
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.invoice_id
";
$params = [];
if ($explicitCurrency !== null) {
    $sql .= " WHERE i.currency = ?";
    $params[] = $explicitCurrency;
}
$sql .= " GROUP BY b.branch_name, i.currency ORDER BY b.branch_name, i.currency";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo "Branch\tCurrency\tTotal Invoiced\tTotal Paid\tOutstanding\n";
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$r['branch_name']}\t{$r['currency']}\t{$r['total_invoiced']}\t{$r['total_paid']}\t{$r['outstanding']}\n";
}
exit;
