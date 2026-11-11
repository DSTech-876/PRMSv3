<?php
if (!isset($pdo)) {
require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$outstanding = $pdo->query("
    SELECT 
        SUM(i.invoice_amount - IFNULL(p.paid, 0)) AS outstanding
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(payment_amount) AS paid
        FROM payments
        GROUP BY invoice_id
    ) p ON i.invoice_id = p.invoice_id
    WHERE i.status != 'Paid'
")->fetchColumn();


$openPOs = $pdo->query("
  SELECT COUNT(*) FROM purchase_orders WHERE status='Open'
")->fetchColumn();

$overdue = $pdo->query("
  SELECT COUNT(*) FROM invoices
  WHERE status!='Paid'
  AND invoice_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

ui_card_open(['title' => 'Financial Snapshot', 'icon' => 'bi-cash-coin']);
ui_kpi_grid([
    [
        'label'   => 'Outstanding Balance',
        'value'   => '$' . number_format((float)($outstanding ?? 0), 0),
        'meta'    => 'Across all branches',
        'icon'    => 'bi-wallet2',
        'variant' => 'purple',
        'href'    => '/invoice/list.php',
    ],
    [
        'label'   => 'Open Purchase Orders',
        'value'   => (string)(int)$openPOs,
        'meta'    => 'Currently active',
        'icon'    => 'bi-file-earmark-check',
        'variant' => 'green',
        'href'    => '/po/list.php?status=Open',
    ],
    [
        'label'   => 'Unpaid Invoices',
        'value'   => (string)(int)$overdue,
        'meta'    => 'Over 30 days old',
        'icon'    => 'bi-exclamation-octagon',
        'variant' => 'red',
        'href'    => '/invoice/list.php?status=Unpaid',
    ],
]);
ui_card_close();
