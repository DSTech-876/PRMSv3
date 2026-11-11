<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}

$pending = $pdo->query("
    SELECT UPPER(status) AS status, COUNT(*) AS cnt
    FROM procurement_requests
    GROUP BY UPPER(status)
    ORDER BY FIELD(UPPER(status), 'DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'AWARDED', 'COMPLETED', 'DECLINED')
")->fetchAll(PDO::FETCH_ASSOC);

$statusMeta = [
    'DRAFT'                 => ['icon' => '📝', 'bg' => 'secondary',  'label' => 'Draft'],
    'SUBMITTED'             => ['icon' => '⏳', 'bg' => 'warning',   'label' => 'Submitted'],
    'HOD_APPROVED'          => ['icon' => '👤', 'bg' => 'info',      'label' => 'HOD Approved'],
    'FUNDS_VERIFIED'        => ['icon' => '💰', 'bg' => 'primary',   'label' => 'Funds Verified'],
    'DIRECTOR_APPROVED'     => ['icon' => '👤', 'bg' => 'success',   'label' => 'Director Approved'],
    'PROCUREMENT_STAGE'     => ['icon' => '📑', 'bg' => 'dark',      'label' => 'Procurement Stage'],
    'EVALUATION_STAGE'      => ['icon' => '📊', 'bg' => 'dark',      'label' => 'Evaluation Stage'],
    'COMMITTEE_RECOMMENDED' => ['icon' => '🧾', 'bg' => 'info',      'label' => 'Committee Recommended'],
    'GC_APPROVED'           => ['icon' => '🏛', 'bg' => 'success',   'label' => 'GC Approved'],
    'AWARDED'               => ['icon' => '🏆', 'bg' => 'success',   'label' => 'Awarded'],
    'COMPLETED'             => ['icon' => '✅', 'bg' => 'success',   'label' => 'Completed'],
    'DECLINED'              => ['icon' => '❌', 'bg' => 'danger',    'label' => 'Declined'],
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

$total = array_sum(array_column($pending, 'cnt'));

/** Map each workflow status onto a design-system accent colour. */
$statusBarColor = static function (string $status): string {
    return match ($status) {
        'AWARDED', 'GC_APPROVED', 'COMPLETED', 'DIRECTOR_APPROVED' => 'var(--ui-success)',
        'SUBMITTED', 'FUNDS_VERIFIED'                              => 'var(--ui-warning)',
        'HOD_APPROVED'                                             => 'var(--ui-info)',
        'DECLINED'                                                 => 'var(--ui-danger)',
        default                                                    => 'var(--ui-accent)',
    };
};

$statusRows = [];
foreach ($pending as $row) {
    $status = (string)$row['status'];
    $meta   = $statusMeta[$status] ?? ['label' => $status];
    $statusRows[] = [
        'label' => $meta['label'],
        'value' => (int)$row['cnt'],
        'href'  => '/procurement/list.php?status=' . urlencode($status),
        'color' => $statusBarColor($status),
    ];
}

ui_card_open([
    'title' => 'Requests by Status',
    'icon'  => 'bi-bar-chart-steps',
    'tools' => ui_badge($total . ' total', 'info'),
]);
ui_chart_bars($statusRows);
ui_card_close();
