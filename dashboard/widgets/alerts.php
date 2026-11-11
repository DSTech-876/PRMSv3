<?php
if (!isset($pdo)) {
require_once __DIR__.'/_init.php';

}
require_once dirname(__DIR__, 2) . '/includes/ui.php';


/* ===============================
   ALERT METRICS
================================ */

// Outstanding balance
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


// Open POs
$openPOs = (int) $pdo->query("
  SELECT COUNT(*) FROM purchase_orders WHERE status = 'Open'
")->fetchColumn();

// Unpaid invoices
$unpaidInvoices = (int) $pdo->query("
  SELECT COUNT(*) FROM invoices WHERE status != 'Paid'
")->fetchColumn();

/* ===============================
   THRESHOLDS
================================ */
$alerts = [];

if ($outstanding > 100000) {
  $alerts[] = [
    'type' => 'danger',
    'icon' => 'bi-cash-stack',
    'text' => 'Outstanding balance exceeds $100,000',
    'link' => '/invoice/list.php'
  ];
}

if ($openPOs >= 10) {
  $alerts[] = [
    'type' => 'warning',
    'icon' => 'bi-file-earmark-text',
    'text' => "There are $openPOs open purchase orders",
    'link' => '/po/list.php?status=Open'
  ];
}

if ($unpaidInvoices >= 5) {
  $alerts[] = [
    'type' => 'danger',
    'icon' => 'bi-clock-history',
    'text' => "$unpaidInvoices unpaid invoices require attention",
    'link' => '/invoice/list.php?status=Unpaid'
  ];
}

ui_card_open([
    'title' => 'System Alerts',
    'icon'  => 'bi-exclamation-triangle',
    'tools' => $alerts ? ui_badge(count($alerts) . ' active', 'danger') : ui_badge('All clear', 'success'),
]);

if (empty($alerts)) {
    ?>
    <div class="ui-alert ui-alert--success">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <span>All systems operating normally</span>
    </div>
    <?php
} else {
    foreach ($alerts as $a) {
        $variant = $a['type'] === 'warning' ? 'warning' : 'danger';
        ?>
        <a href="<?= ui_e($a['link']) ?>" class="ui-alert ui-alert--<?= ui_e($variant) ?> ui-alert--link">
            <i class="bi <?= ui_e($a['icon']) ?>" aria-hidden="true"></i>
            <span><?= ui_e($a['text']) ?></span>
            <i class="bi bi-chevron-right ui-alert__chevron" aria-hidden="true"></i>
        </a>
        <?php
    }
}

ui_card_close();
