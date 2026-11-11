<?php
/**
 * Admin KPI strip — one tile per core system entity.
 *
 * Read-only aggregate counts. Every query is wrapped so that a module whose
 * tables have not been migrated yet (e.g. inventory) degrades to "n/a"
 * instead of breaking the dashboard.
 *
 * Requires: $pdo, includes/ui.php
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/_init.php';
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

if (!function_exists('adminKpiCount')) {
    /**
     * Run a COUNT query, returning null when the underlying table is absent.
     *
     * @return int|null
     */
    function adminKpiCount(PDO $pdo, string $sql): ?int
    {
        try {
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('adminKpiDisplay')) {
    /** Format a count for display, collapsing missing modules to an em dash. */
    function adminKpiDisplay(?int $value): string
    {
        return $value === null ? '—' : number_format($value);
    }
}

$kpiRequests    = adminKpiCount($pdo, "SELECT COUNT(*) FROM procurement_requests");
$kpiRfqs        = adminKpiCount($pdo, "SELECT COUNT(*) FROM rfqs");
$kpiPos         = adminKpiCount($pdo, "SELECT COUNT(*) FROM purchase_orders");
$kpiCommitments = adminKpiCount($pdo, "SELECT COUNT(*) FROM commitments");
$kpiInvoices    = adminKpiCount($pdo, "SELECT COUNT(*) FROM invoices");
$kpiPayments    = adminKpiCount($pdo, "SELECT COUNT(*) FROM payments");
$kpiInventory   = adminKpiCount($pdo, "SELECT COUNT(*) FROM inv_items WHERE item_status = 'ACTIVE'");
$kpiUsers       = adminKpiCount($pdo, "SELECT COUNT(*) FROM users");

/* Secondary "needs attention" counts shown as tile meta text. */
$openRequests  = adminKpiCount($pdo, "SELECT COUNT(*) FROM procurement_requests WHERE UPPER(status) NOT IN ('COMPLETED','DECLINED','CANCELLED')");
$openRfqs      = adminKpiCount($pdo, "SELECT COUNT(*) FROM rfqs WHERE UPPER(status) <> 'AWARDED'");
$openPos       = adminKpiCount($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Open'");
$unpaidInvoice = adminKpiCount($pdo, "SELECT COUNT(*) FROM invoices WHERE status <> 'Paid'");
$activeUsers   = adminKpiCount($pdo, "SELECT COUNT(*) FROM users WHERE is_active = 1");

ui_kpi_grid([
    [
        'label' => 'Requests', 'value' => adminKpiDisplay($kpiRequests),
        'meta' => $openRequests === null ? '' : number_format($openRequests) . ' in progress',
        'icon' => 'bi-file-earmark-text', 'variant' => 'blue', 'href' => '/procurement/list.php',
    ],
    [
        'label' => 'RFQs', 'value' => adminKpiDisplay($kpiRfqs),
        'meta' => $openRfqs === null ? '' : number_format($openRfqs) . ' not yet awarded',
        'icon' => 'bi-journal-text', 'variant' => 'purple', 'href' => '/rfq/list.php',
    ],
    [
        'label' => 'Purchase Orders', 'value' => adminKpiDisplay($kpiPos),
        'meta' => $openPos === null ? '' : number_format($openPos) . ' open',
        'icon' => 'bi-receipt', 'variant' => 'green', 'href' => '/po/list.php',
    ],
    [
        'label' => 'Commitments', 'value' => adminKpiDisplay($kpiCommitments),
        'meta' => 'Budget reservations',
        'icon' => 'bi-pin-angle', 'variant' => 'amber', 'href' => '/commitments/list.php',
    ],
    [
        'label' => 'Invoices', 'value' => adminKpiDisplay($kpiInvoices),
        'meta' => $unpaidInvoice === null ? '' : number_format($unpaidInvoice) . ' unpaid',
        'icon' => 'bi-file-earmark-ruled', 'variant' => 'red', 'href' => '/invoice/list.php',
    ],
    [
        'label' => 'Payments', 'value' => adminKpiDisplay($kpiPayments),
        'meta' => 'Recorded disbursements',
        'icon' => 'bi-credit-card', 'variant' => 'green', 'href' => '/payment/list.php',
    ],
    [
        'label' => 'Inventory Items', 'value' => adminKpiDisplay($kpiInventory),
        'meta' => $kpiInventory === null ? 'Module not installed' : 'Active items',
        'icon' => 'bi-box-seam', 'variant' => 'blue', 'href' => '/inventory/items/list.php',
    ],
    [
        'label' => 'Users', 'value' => adminKpiDisplay($kpiUsers),
        'meta' => $activeUsers === null ? '' : number_format($activeUsers) . ' active',
        'icon' => 'bi-people', 'variant' => 'slate', 'href' => '/users/list.php',
    ],
]);
