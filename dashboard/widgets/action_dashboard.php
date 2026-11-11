<?php
/**
 * Shared role action dashboard.
 *
 * Rendered by the procurement, finance, requestor, director_procurement and
 * director_accounts_finance dashboards. All server-side state (search,
 * filters, sorting, pagination) is unchanged — the queues are now split into
 * tabbed panels so the page stays short, and the tables use the shared
 * design-system components (sticky headers, column visibility, export menu).
 *
 * Expects: $dashboardModel from DashboardActionService::buildDashboard().
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

if (!function_exists('dashboardActionTableSortLink')) {
    function dashboardActionTableSortLink(array $state, string $column, string $label): string
    {
        $params = $_GET;
        $prefix = $state['prefix'];
        $currentSort = (string)($state['sort_key'] ?? '');
        $currentDir = (string)($state['sort_dir'] ?? 'DESC');
        $params[$prefix . 'sort'] = $column;
        $params[$prefix . 'dir'] = $currentSort === $column && $currentDir === 'ASC' ? 'DESC' : 'ASC';
        $params[$prefix . 'page'] = 1;
        $arrow = $currentSort === $column ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
        return '<a href="?' . htmlspecialchars(http_build_query($params)) . '" class="dashboard-link-sort">' . htmlspecialchars($label) . $arrow . '</a>';
    }
}

if (!function_exists('dashboardActionBadgeClass')) {
    function dashboardActionBadgeClass(string $variant): string
    {
        return match ($variant) {
            'red' => 'dashboard-badge dashboard-badge-red',
            'amber' => 'dashboard-badge dashboard-badge-amber',
            'green' => 'dashboard-badge dashboard-badge-green',
            default => 'dashboard-badge dashboard-badge-blue',
        };
    }
}

if (!function_exists('dashboardActionButtonClass')) {
    function dashboardActionButtonClass(string $variant): string
    {
        return match ($variant) {
            'danger' => 'dashboard-pill dashboard-pill-danger',
            'primary' => 'dashboard-pill dashboard-pill-primary',
            default => 'dashboard-pill dashboard-pill-secondary',
        };
    }
}

if (!function_exists('dashboardActionBadgeVariant')) {
    /** Map the legacy colour names onto design-system badge variants. */
    function dashboardActionBadgeVariant(string $variant): string
    {
        return match ($variant) {
            'red' => 'danger',
            'amber' => 'warning',
            'green' => 'success',
            default => 'info',
        };
    }
}

if (!function_exists('dashboardActionHiddenFields')) {
    /**
     * Preserve every query parameter that does not belong to this queue, so
     * filtering one table never resets the others.
     */
    function dashboardActionHiddenFields(string $prefix): void
    {
        foreach ($_GET as $key => $value) {
            if (strpos((string)$key, $prefix) === 0) {
                continue;
            }
            echo '<input type="hidden" name="' . ui_e((string)$key) . '" value="'
                . ui_e(is_array($value) ? json_encode($value) : (string)$value) . '">';
        }
    }
}

if (!function_exists('dashboardActionPageSizeSelect')) {
    /** Render the shared "N per page" selector for a queue. */
    function dashboardActionPageSizeSelect(array $state): void
    {
        echo '<label class="ui-visually-hidden" for="' . ui_e($state['prefix']) . 'per_page">Rows per page</label>';
        echo '<select class="ui-input" id="' . ui_e($state['prefix']) . 'per_page" name="' . ui_e($state['prefix'] . 'per_page') . '">';
        foreach ($state['page_size_options'] as $pageSize) {
            echo '<option value="' . (int)$pageSize . '"'
                . ($state['per_page'] === $pageSize ? ' selected' : '') . '>'
                . (int)$pageSize . ' per page</option>';
        }
        echo '</select>';
    }
}

if (!function_exists('dashboardActionFilterActions')) {
    /** Render the Apply/Reset buttons shared by every queue filter form. */
    function dashboardActionFilterActions(): void
    {
        echo '<button type="submit" class="ui-action ui-action--primary">'
            . '<i class="bi bi-funnel" aria-hidden="true"></i><span>Apply</span></button>';
        echo '<a class="ui-action" href="' . ui_e($_SERVER['PHP_SELF']) . '">'
            . '<i class="bi bi-x-circle" aria-hidden="true"></i><span>Reset</span></a>';
    }
}

$config       = $dashboardModel['config'];
$context      = $dashboardModel['context'];
$actionQueue  = $dashboardModel['action_queue'];
$queueState   = $actionQueue['state'];
$monitoring   = $dashboardModel['monitoring_queue'] ?? [];
$activeReqs   = $dashboardModel['active_requests'] ?? [];
$notifications = $dashboardModel['notifications'];
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => $config['title'],
    'subtitle' => sprintf(
        '%s • %s • %s — %s',
        $context['full_name'],
        $context['role_name'],
        $context['today'],
        $config['description']
    ),
    'icon'        => 'bi-clipboard-check',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => $config['title']],
    ],
    'actions' => array_map(static function (array $link): array {
        return [
            'label'   => $link['label'],
            'href'    => $link['href'],
            'icon'    => 'bi-arrow-right-circle',
            'variant' => ($link['variant'] ?? '') === 'primary' ? 'primary' : null,
        ];
    }, $config['quick_links']),
]);

/* Summary cards become KPI tiles: same values, far less vertical space. */
$summaryVariants = ['red' => 'red', 'amber' => 'amber', 'blue' => 'blue', 'green' => 'green'];
$summaryIcons    = ['red' => 'bi-exclamation-octagon', 'amber' => 'bi-hourglass-split', 'blue' => 'bi-inboxes', 'green' => 'bi-check2-circle'];

ui_kpi_grid(array_map(static function (array $card) use ($summaryVariants, $summaryIcons): array {
    $variant = (string)$card['variant'];
    return [
        'label'   => $card['label'],
        'value'   => number_format((int)$card['value']),
        'icon'    => $summaryIcons[$variant] ?? 'bi-dot',
        'variant' => $summaryVariants[$variant] ?? 'slate',
    ];
}, $dashboardModel['summary_cards']));

$tabs = [
    [
        'id'     => 'queue',
        'label'  => $config['primary_widget_title'],
        'icon'   => 'bi-check2-square',
        'count'  => number_format((int)$actionQueue['total']),
        'active' => true,
    ],
];
if (!empty($activeReqs)) {
    $tabs[] = [
        'id'    => 'requests',
        'label' => $activeReqs['title'],
        'icon'  => 'bi-list-task',
        'count' => number_format((int)$activeReqs['total']),
    ];
}
if (!empty($monitoring)) {
    $tabs[] = [
        'id'    => 'monitoring',
        'label' => $monitoring['title'],
        'icon'  => 'bi-speedometer2',
        'count' => number_format((int)$monitoring['total']),
    ];
}
$tabs[] = [
    'id'    => 'notifications',
    'label' => $config['notification_title'],
    'icon'  => 'bi-bell',
    'count' => number_format((int)$notifications['unread_count']),
];

ui_tabs('dashboard', $tabs);
?>

<?php /* ─── Action queue ─────────────────────────────────────────────── */ ?>
<?php ui_tabpanel_open('dashboard', 'queue', true); ?>
    <?php
    ob_start(); ?>
    <form method="get" class="ui-filter-form">
        <?php dashboardActionHiddenFields($queueState['prefix']); ?>
        <label class="ui-visually-hidden" for="<?= ui_e($queueState['prefix']) ?>q">Search action queue</label>
        <input class="ui-input" type="search" id="<?= ui_e($queueState['prefix']) ?>q"
               name="<?= ui_e($queueState['prefix'] . 'q') ?>"
               value="<?= ui_e($queueState['search']) ?>"
               placeholder="Search reference, requestor, stage or action">
        <label class="ui-visually-hidden" for="<?= ui_e($queueState['prefix']) ?>risk">Risk</label>
        <select class="ui-input" id="<?= ui_e($queueState['prefix']) ?>risk" name="<?= ui_e($queueState['prefix'] . 'risk') ?>">
            <option value="">All risks</option>
            <option value="critical" <?= $queueState['risk'] === 'critical' ? 'selected' : '' ?>>Critical</option>
            <option value="overdue" <?= $queueState['risk'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            <option value="warning" <?= $queueState['risk'] === 'warning' ? 'selected' : '' ?>>Near SLA</option>
            <option value="normal" <?= $queueState['risk'] === 'normal' ? 'selected' : '' ?>>Normal</option>
        </select>
        <label class="ui-visually-hidden" for="<?= ui_e($queueState['prefix']) ?>priority">Priority</label>
        <select class="ui-input" id="<?= ui_e($queueState['prefix']) ?>priority" name="<?= ui_e($queueState['prefix'] . 'priority') ?>">
            <option value="">All priorities</option>
            <option value="urgent" <?= $queueState['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
            <option value="high" <?= $queueState['priority'] === 'high' ? 'selected' : '' ?>>High</option>
            <option value="normal" <?= $queueState['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
        </select>
        <label class="ui-visually-hidden" for="<?= ui_e($queueState['prefix']) ?>category">Section</label>
        <select class="ui-input" id="<?= ui_e($queueState['prefix']) ?>category" name="<?= ui_e($queueState['prefix'] . 'category') ?>">
            <option value="">All sections</option>
            <?php foreach ($actionQueue['workflow_summary'] as $summary): ?>
                <option value="<?= ui_e($summary['label']) ?>" <?= $queueState['category'] === $summary['label'] ? 'selected' : '' ?>>
                    <?= ui_e($summary['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php dashboardActionPageSizeSelect($queueState); ?>
        <?php dashboardActionFilterActions(); ?>
    </form>
    <?php
    $queueFilters = ob_get_clean();

    ui_card_open([
        'title' => $config['primary_widget_title'],
        'icon'  => 'bi-check2-square',
        'flush' => true,
        'tools' => ui_badge(number_format((int)$actionQueue['total']) . ' items', 'neutral'),
    ]);

    ui_table_toolbar('queue-table', [
        'search' => false,
        'extra'  => $queueFilters,
    ]);
    ?>

    <?php if ($actionQueue['workflow_summary']): ?>
        <div class="ui-chip-row">
            <?php foreach ($actionQueue['workflow_summary'] as $summary): ?>
                <a class="ui-action" href="<?= ui_e($summary['href']) ?>">
                    <span><?= ui_e($summary['label']) ?></span>
                    <span class="ui-tab__count"><?= (int)$summary['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    /* Server-side pagination is authoritative, so the client-side pager and
       sorter stay off; the header links continue to drive sorting. */
    ui_table_open('queue-table', [
        'paginate' => false,
        'sortable' => false,
        'virtual'  => true,
        'label'    => $config['primary_widget_title'],
    ]);
    ?>
        <thead>
            <tr>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'request_number', 'Reference No.') ?></th>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'requestor_name', 'Requestor') ?></th>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'department', 'Department') ?></th>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'current_stage', 'Current Stage') ?></th>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'action_required', 'Action Required') ?></th>
                <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($queueState, 'age_days', 'Age') ?></th>
                <th scope="col"><?= dashboardActionTableSortLink($queueState, 'priority_rank', 'Priority') ?></th>
                <th scope="col">Badges</th>
                <th scope="col">Links</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($actionQueue['rows'])): ?>
            <tr><td colspan="9" class="ui-empty"><?= ui_e($config['empty_text']) ?></td></tr>
        <?php else: foreach ($actionQueue['rows'] as $row): ?>
            <tr>
                <td><a href="<?= ui_e($row['action_url']) ?>"><?= ui_e($row['request_number']) ?></a></td>
                <td class="ui-truncate"><?= ui_e($row['requestor_name']) ?></td>
                <td class="ui-truncate"><?= ui_e($row['department']) ?></td>
                <td><?= ui_status_badge($row['current_stage']) ?></td>
                <td class="ui-truncate"><?= ui_e($row['action_required']) ?></td>
                <td class="ui-num ui-nowrap"><?= (int)$row['age_days'] ?>d</td>
                <td>
                    <?= ui_badge(
                        strtoupper((string)$row['priority']),
                        $row['priority'] === 'urgent' ? 'danger' : ($row['priority'] === 'high' ? 'warning' : 'success'),
                        true
                    ) ?>
                </td>
                <td>
                    <div class="ui-badges">
                    <?php foreach ($row['badges'] as $badge): ?>
                        <?= ui_badge($badge['label'], dashboardActionBadgeVariant($badge['variant'])) ?>
                    <?php endforeach; ?>
                    </div>
                </td>
                <td class="ui-nowrap">
                    <a class="ui-action" href="<?= ui_e($row['view_url']) ?>"><i class="bi bi-eye" aria-hidden="true"></i><span>View</span></a>
                    <a class="ui-action ui-action--primary" href="<?= ui_e($row['action_url']) ?>"><i class="bi bi-lightning" aria-hidden="true"></i><span>Act</span></a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    <?php
    ui_table_close('queue-table', false);

    ob_start();
    renderShowingInfo($queueState['page'], $queueState['per_page'], (int)$actionQueue['total']);
    renderPagination(
        (int)$actionQueue['total'],
        $queueState['per_page'],
        $queueState['page'],
        $actionQueue['pagination_params'],
        $queueState['prefix'] . 'page'
    );
    ui_card_close(ob_get_clean());
    ?>
<?php ui_tabpanel_close(); ?>

<?php /* ─── Active requests ──────────────────────────────────────────── */ ?>
<?php if (!empty($activeReqs)): ?>
    <?php $requestsState = $activeReqs['state']; ?>
    <?php ui_tabpanel_open('dashboard', 'requests'); ?>
        <?php
        ob_start(); ?>
        <form method="get" class="ui-filter-form">
            <?php dashboardActionHiddenFields($requestsState['prefix']); ?>
            <label class="ui-visually-hidden" for="<?= ui_e($requestsState['prefix']) ?>q">Search requests</label>
            <input class="ui-input" type="search" id="<?= ui_e($requestsState['prefix']) ?>q"
                   name="<?= ui_e($requestsState['prefix'] . 'q') ?>"
                   value="<?= ui_e($requestsState['search']) ?>"
                   placeholder="Search request number or status">
            <label class="ui-visually-hidden" for="<?= ui_e($requestsState['prefix']) ?>category">Status</label>
            <select class="ui-input" id="<?= ui_e($requestsState['prefix']) ?>category" name="<?= ui_e($requestsState['prefix'] . 'category') ?>">
                <option value="">All statuses</option>
                <?php foreach ($activeReqs['workflow_summary'] as $summary): ?>
                    <?php $value = (string)($summary['filter_value'] ?? $summary['label']); ?>
                    <option value="<?= ui_e($value) ?>" <?= $requestsState['category'] === $value ? 'selected' : '' ?>>
                        <?= ui_e($summary['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php dashboardActionPageSizeSelect($requestsState); ?>
            <?php dashboardActionFilterActions(); ?>
        </form>
        <?php
        $requestFilters = ob_get_clean();

        ui_card_open([
            'title' => $activeReqs['title'],
            'icon'  => 'bi-list-task',
            'flush' => true,
            'tools' => ui_badge(number_format((int)$activeReqs['total']) . ' items', 'neutral'),
        ]);
        ui_table_toolbar('requests-table', ['search' => false, 'extra' => $requestFilters]);
        ?>

        <?php if ($activeReqs['workflow_summary']): ?>
            <div class="ui-chip-row">
                <?php foreach ($activeReqs['workflow_summary'] as $summary): ?>
                    <a class="ui-action" href="<?= ui_e($summary['href']) ?>">
                        <span><?= ui_e($summary['label']) ?></span>
                        <span class="ui-tab__count"><?= (int)$summary['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php ui_table_open('requests-table', ['paginate' => false, 'sortable' => false, 'virtual' => true, 'label' => $activeReqs['title']]); ?>
            <thead>
                <tr>
                    <th scope="col"><?= dashboardActionTableSortLink($requestsState, 'request_number', 'Request Number') ?></th>
                    <th scope="col"><?= dashboardActionTableSortLink($requestsState, 'status', 'Status') ?></th>
                    <th scope="col"><?= dashboardActionTableSortLink($requestsState, 'current_approver', 'Current Approver') ?></th>
                    <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($requestsState, 'days_open', 'Days Open') ?></th>
                    <th scope="col"><?= dashboardActionTableSortLink($requestsState, 'action_needed', 'Action Needed') ?></th>
                    <th scope="col">Badges</th>
                    <th scope="col">Links</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($activeReqs['rows'])): ?>
                <tr><td colspan="7" class="ui-empty">No active requests found.</td></tr>
            <?php else: foreach ($activeReqs['rows'] as $row): ?>
                <tr>
                    <td><a href="<?= ui_e($row['action_url']) ?>"><?= ui_e($row['request_number']) ?></a></td>
                    <td><?= ui_status_badge($row['status']) ?></td>
                    <td class="ui-truncate"><?= ui_e($row['current_approver']) ?></td>
                    <td class="ui-num"><?= (int)$row['days_open'] ?></td>
                    <td class="ui-truncate"><?= ui_e($row['action_needed']) ?></td>
                    <td>
                        <div class="ui-badges">
                        <?php foreach ($row['badges'] as $badge): ?>
                            <?= ui_badge($badge['label'], dashboardActionBadgeVariant($badge['variant'])) ?>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="ui-nowrap">
                        <a class="ui-action" href="<?= ui_e($row['view_url']) ?>"><i class="bi bi-eye" aria-hidden="true"></i><span>View</span></a>
                        <a class="ui-action ui-action--primary" href="<?= ui_e($row['action_url']) ?>"><i class="bi bi-lightning" aria-hidden="true"></i><span>Act</span></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        <?php
        ui_table_close('requests-table', false);

        ob_start();
        renderShowingInfo($requestsState['page'], $requestsState['per_page'], (int)$activeReqs['total']);
        renderPagination(
            (int)$activeReqs['total'],
            $requestsState['per_page'],
            $requestsState['page'],
            $activeReqs['pagination_params'],
            $requestsState['prefix'] . 'page'
        );
        ui_card_close(ob_get_clean());
        ?>
    <?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php /* ─── Workflow monitoring ──────────────────────────────────────── */ ?>
<?php if (!empty($monitoring)): ?>
    <?php $monitorState = $monitoring['state']; ?>
    <?php ui_tabpanel_open('dashboard', 'monitoring'); ?>
        <?php
        ob_start(); ?>
        <form method="get" class="ui-filter-form">
            <?php dashboardActionHiddenFields($monitorState['prefix']); ?>
            <label class="ui-visually-hidden" for="<?= ui_e($monitorState['prefix']) ?>q">Search officer</label>
            <input class="ui-input" type="search" id="<?= ui_e($monitorState['prefix']) ?>q"
                   name="<?= ui_e($monitorState['prefix'] . 'q') ?>"
                   value="<?= ui_e($monitorState['search']) ?>" placeholder="Search officer">
            <?php dashboardActionPageSizeSelect($monitorState); ?>
            <?php dashboardActionFilterActions(); ?>
        </form>
        <?php
        $monitorFilters = ob_get_clean();

        ui_card_open([
            'title' => $monitoring['title'],
            'icon'  => 'bi-speedometer2',
            'flush' => true,
            'tools' => ui_badge(number_format((int)$monitoring['total']) . ' officers', 'neutral'),
        ]);
        ui_table_toolbar('monitor-table', ['search' => false, 'extra' => $monitorFilters]);
        ?>
        <p class="ui-text-sm ui-text-muted" style="padding:.5rem .75rem 0;margin:0;">
            Workload is branch-based because workflow records are not assigned to a named officer in the request table.
        </p>
        <?php ui_table_open('monitor-table', ['paginate' => false, 'sortable' => false, 'label' => $monitoring['title']]); ?>
            <thead>
                <tr>
                    <th scope="col"><?= dashboardActionTableSortLink($monitorState, 'officer', 'Officer') ?></th>
                    <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($monitorState, 'pending_items', 'Pending Items') ?></th>
                    <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($monitorState, 'overdue_count', 'Overdue') ?></th>
                    <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($monitorState, 'near_sla', 'Near SLA Breach') ?></th>
                    <th scope="col" class="ui-num"><?= dashboardActionTableSortLink($monitorState, 'escalations', 'Escalations') ?></th>
                    <th scope="col"><?= dashboardActionTableSortLink($monitorState, 'requires_intervention', 'Requires Intervention') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($monitoring['rows'])): ?>
                <tr><td colspan="6" class="ui-empty">No officers found.</td></tr>
            <?php else: foreach ($monitoring['rows'] as $row): ?>
                <tr>
                    <td><?= ui_e($row['officer']) ?></td>
                    <td class="ui-num"><?= (int)$row['pending_items'] ?></td>
                    <td class="ui-num"><?= (int)$row['overdue_count'] ?></td>
                    <td class="ui-num"><?= (int)$row['near_sla'] ?></td>
                    <td class="ui-num"><?= (int)$row['escalations'] ?></td>
                    <td><?= ui_badge((string)$row['requires_intervention'], $row['requires_intervention'] === 'Yes' ? 'danger' : 'success', true) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        <?php
        ui_table_close('monitor-table', false);

        ob_start();
        renderShowingInfo($monitorState['page'], $monitorState['per_page'], (int)$monitoring['total']);
        renderPagination(
            (int)$monitoring['total'],
            $monitorState['per_page'],
            $monitorState['page'],
            $monitoring['pagination_params'],
            $monitorState['prefix'] . 'page'
        );
        ui_card_close(ob_get_clean());
        ?>
    <?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php /* ─── Notifications + workflow summary ─────────────────────────── */ ?>
<?php ui_tabpanel_open('dashboard', 'notifications'); ?>
    <div class="ui-grid ui-grid--sidebar">
        <div>
            <?php
            ui_card_open([
                'title' => $config['notification_title'],
                'icon'  => 'bi-bell',
                'flush' => true,
                'tools' => ui_badge(number_format((int)$notifications['unread_count']) . ' unread', 'info'),
            ]);
            ?>
            <?php if (empty($notifications['rows'])): ?>
                <?php ui_empty('No recent notifications.', 'bi-bell-slash'); ?>
            <?php else: ?>
                <div class="ui-scroll-area--lg ui-scroll-area">
                    <div class="ui-feed">
                        <?php foreach ($notifications['rows'] as $notification): ?>
                            <div class="ui-feed__item">
                                <span class="ui-feed__icon" aria-hidden="true"><i class="bi bi-bell"></i></span>
                                <div class="ui-feed__body">
                                    <div class="ui-feed__title"><?= ui_e($notification['title']) ?></div>
                                    <?php if (!empty($notification['body'])): ?>
                                        <div class="ui-feed__note" title="<?= ui_e($notification['body']) ?>"><?= ui_e($notification['body']) ?></div>
                                    <?php endif; ?>
                                    <div class="ui-feed__meta ui-mt-3">
                                        <?= ui_badge((string)($notification['priority'] ?? 'normal'), 'neutral') ?>
                                        <?php if (!empty($notification['view_url'])): ?>
                                            <a class="ui-action" href="<?= ui_e($notification['view_url']) ?>"><i class="bi bi-eye" aria-hidden="true"></i><span>View Record</span></a>
                                        <?php endif; ?>
                                        <?php if (!empty($notification['action_url'])): ?>
                                            <a class="ui-action ui-action--primary" href="<?= ui_e($notification['action_url']) ?>"><i class="bi bi-lightning" aria-hidden="true"></i><span>Take Action</span></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php ui_card_close(); ?>
        </div>

        <div>
            <?php
            ui_card_open(['title' => $config['summary_title'], 'icon' => 'bi-pie-chart']);
            ui_chart_bars(array_map(static function (array $summary): array {
                return [
                    'label' => $summary['label'],
                    'value' => (int)$summary['count'],
                    'href'  => $summary['href'],
                ];
            }, $actionQueue['workflow_summary']));
            ui_card_close();
            ?>
        </div>
    </div>
<?php ui_tabpanel_close(); ?>

</div>
