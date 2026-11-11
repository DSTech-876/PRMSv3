<?php
/**
 * Shared renderer for the petty cash / reimbursement "awaiting approval"
 * dashboard widgets. Presentation only — all querying and aggregation stays
 * in the calling widget.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

if (!function_exists('render_pending_finance_approvals')) {
    /**
     * @param array $cfg Keys:
     *   title, icon, tableId, approverRole, rows, totalAmount, oldestDate,
     *   daysPendingOldest, overdueCount, overdueDays, listUrl,
     *   viewUrlTpl (contains {id}), emptyMessage
     */
    function render_pending_finance_approvals(array $cfg): void
    {
        $rows    = $cfg['rows'] ?? [];
        $tableId = (string)($cfg['tableId'] ?? 'finance-approvals');
        $tools   = '';

        if (!empty($rows)) {
            $tools = '<a class="ui-action ui-action--sm ui-action--primary" href="'
                . ui_e($cfg['listUrl'] ?? '#') . '">Review All</a>';
        }

        ui_card_open([
            'title'    => (string)($cfg['title'] ?? 'Awaiting Approval'),
            'icon'     => (string)($cfg['icon'] ?? 'bi-cash-coin'),
            'subtitle' => 'Requests submitted by employees in your '
                . (string)($cfg['approverRole'] ?? '') . ' scope',
            'tools'    => $tools,
        ]);

        if (empty($rows)) {
            ui_empty((string)($cfg['emptyMessage'] ?? 'All caught up! Nothing awaits your approval.'), 'bi-check2-circle');
            ui_card_close();
            return;
        }

        $overdueDays  = (int)($cfg['overdueDays'] ?? 2);
        $overdueCount = (int)($cfg['overdueCount'] ?? 0);

        ui_kpi_grid([
            [
                'label'   => 'Pending Requests',
                'value'   => (string)count($rows),
                'icon'    => 'bi-hourglass-split',
                'variant' => 'blue',
            ],
            [
                'label'   => 'Total Amount',
                'value'   => 'JMD ' . number_format((float)($cfg['totalAmount'] ?? 0), 0),
                'icon'    => 'bi-cash-stack',
                'variant' => 'green',
            ],
            [
                'label'   => 'Oldest Request',
                'value'   => (int)($cfg['daysPendingOldest'] ?? 0) . 'd',
                'meta'    => !empty($cfg['oldestDate']) ? date('M d, Y', strtotime((string)$cfg['oldestDate'])) : 'N/A',
                'icon'    => 'bi-clock-history',
                'variant' => 'amber',
            ],
            [
                'label'   => 'Overdue',
                'value'   => (string)$overdueCount,
                'meta'    => 'Pending > ' . $overdueDays . ' days',
                'icon'    => 'bi-exclamation-triangle',
                'variant' => $overdueCount > 0 ? 'red' : 'slate',
            ],
        ]);

        ui_table_toolbar($tableId, [
            'columns'     => true,
            'export'      => true,
            'placeholder' => 'Search requests…',
        ]);
        ui_table_open($tableId, [
            'paginate' => true,
            'perPage'  => 10,
            'label'    => (string)($cfg['title'] ?? 'Awaiting approval'),
        ]);
        ?>
        <thead>
            <tr>
                <th scope="col">Request #</th>
                <th scope="col">Requester</th>
                <th scope="col">Branch</th>
                <th scope="col">Purpose</th>
                <th scope="col" class="ui-num">Amount</th>
                <th scope="col">Submitted</th>
                <th scope="col" class="ui-num">Days Pending</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $req):
            $viewUrl = str_replace('{id}', (string)(int)$req['request_id'], (string)($cfg['viewUrlTpl'] ?? '#'));
            $desc    = (string)($req['description'] ?? '');
            $days    = (int)$req['days_pending'];
        ?>
            <tr>
                <th scope="row"><a href="<?= ui_e($viewUrl) ?>"><?= ui_e($req['request_number']) ?></a></th>
                <td><?= ui_e($req['requester_name'] ?? 'Unknown') ?></td>
                <td><?= ui_e($req['branch_name'] ?? 'N/A') ?></td>
                <td class="ui-cell-wrap" title="<?= ui_e($desc) ?>">
                    <?= ui_e(mb_strimwidth($desc, 0, 33, '…')) ?>
                </td>
                <td class="ui-num ui-num--strong">JMD <?= number_format((float)$req['estimated_value'], 0) ?></td>
                <td class="ui-nowrap"><?= date('M d, Y', strtotime((string)$req['created_at'])) ?></td>
                <td class="ui-num"><?= ui_badge((string)$days, $days > $overdueDays ? 'warning' : 'info') ?></td>
                <td><a class="ui-action ui-action--sm" href="<?= ui_e($viewUrl) ?>">Review</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close($tableId);
        ui_card_close();
    }
}
