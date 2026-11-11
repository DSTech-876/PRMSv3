<?php
/**
 * Recent activity feed.
 *
 * Shared by the admin, viewer, GC and HOD dashboards. The query and the
 * procurement visibility guard are unchanged; only the presentation now
 * uses the shared design system (compact feed, capped height, no inline
 * per-widget stylesheet).
 */
if (!isset($pdo)) {
require_once __DIR__.'/_init.php';
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

$activityVisibilityWhere = '';
if (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole()
) {
    $activityVisibilityWhere = "
        WHERE (
            table_name <> 'procurement_requests'
            OR EXISTS (
                SELECT 1
                FROM procurement_requests pr
                WHERE pr.request_id = audit_log.record_id
                  AND " . getProcurementVisibilitySqlCondition('pr') . "
            )
        )
    ";
}

$recent = $pdo->query("
    SELECT table_name, action, notes, change_date
    FROM audit_log
    {$activityVisibilityWhere}
    ORDER BY change_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

/* Bootstrap icon + badge variant per audit action. */
$activityIcons = [
    'CREATE'               => ['bi-plus-circle',    'success'],
    'STATUS_CHANGE'        => ['bi-arrow-repeat',   'info'],
    'PASSWORD_CHANGE'      => ['bi-key',            'warning'],
    'ADMIN_PASSWORD_RESET' => ['bi-key-fill',       'warning'],
    'BACKDATED_REQUEST_AT' => ['bi-exclamation-triangle', 'warning'],
    'UPDATE'               => ['bi-pencil',         'info'],
    'DELETE'               => ['bi-trash',          'danger'],
];

ui_card_open([
    'title' => 'Recent Activity',
    'icon'  => 'bi-clock-history',
    'flush' => true,
    'tools' => ui_badge(number_format(count($recent)) . ' entries', 'neutral')
        . '<a class="ui-action" href="/audit/list.php"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Full audit log</span></a>',
]);
?>
<div class="ui-scroll-area">
    <?php if ($recent): ?>
        <div class="ui-feed">
            <?php foreach ($recent as $r): ?>
                <?php
                $action = strtoupper((string)$r['action']);
                [$icon, $variant] = $activityIcons[$action] ?? ['bi-journal-text', 'neutral'];
                ?>
                <div class="ui-feed__item">
                    <span class="ui-feed__icon" aria-hidden="true"><i class="bi <?= ui_e($icon) ?>"></i></span>
                    <div class="ui-feed__body">
                        <div class="ui-feed__title">
                            <?= ui_badge(str_replace('_', ' ', $action), $variant) ?>
                            <span>on <strong><?= ui_e($r['table_name']) ?></strong></span>
                        </div>
                        <?php if (!empty($r['notes'])): ?>
                            <div class="ui-feed__note" title="<?= ui_e($r['notes']) ?>"><?= ui_e($r['notes']) ?></div>
                        <?php endif; ?>
                        <div class="ui-feed__meta">
                            <time datetime="<?= ui_e(date('c', strtotime((string)$r['change_date']))) ?>">
                                <?= ui_e(date('d M Y, H:i', strtotime((string)$r['change_date']))) ?>
                            </time>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php ui_empty('No recent activity recorded.', 'bi-clock-history'); ?>
    <?php endif; ?>
</div>
<?php
ui_card_close();
