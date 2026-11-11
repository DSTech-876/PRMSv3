<?php
$REQUIRE_PERMISSION = 'view_pmo_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryService.php';

/* ─── Check inventory tables ─────────────────────────────────────────── */
$invReady = inventoryTablesExist($pdo);

/* ─── KPI queries (only if tables exist) ─────────────────────────────── */
$stats = [
    'active_items' => 0, 'total_value' => 0, 'low_stock_count' => 0,
    'asset_items' => 0, 'serialized_items' => 0,
];
$pendingReqs = $pendingGrn = $pendingTransfers = $pendingAdj = $pendingDisp = 0;
$expiringCount = 0;
$recentTxns = $topAssets = $lifecycleStats = [];
$assetRegistryRows = [];
$assetRegistryReady = false;
$serialCount = 0;

if ($invReady) {
    /* ─── Check whether item_domain column exists (migration 024) ─────── */
    $domainReady = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inv_items'
          AND COLUMN_NAME = 'item_domain'
    ")->fetchColumn() > 0;

    $assetFilter = $domainReady
        ? "i.item_domain IN ('ASSET','BOTH')"
        : "EXISTS (SELECT 1 FROM inv_categories c WHERE c.category_id = i.category_id AND c.category_code = 'ASSETS')";

    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM inv_items WHERE item_status = 'ACTIVE') AS active_items,
            (SELECT COUNT(*) FROM inv_items i
               WHERE $assetFilter AND i.item_status = 'ACTIVE') AS asset_items,
            (SELECT COUNT(*) FROM inv_items WHERE serial_number_flag = 1 AND item_status = 'ACTIVE') AS serialized_items,
            (SELECT COALESCE(SUM(sl.quantity_on_hand * sl.unit_cost), 0) FROM inv_stock sl) AS total_value,
            (SELECT COALESCE(SUM(sl.quantity_on_hand * sl.unit_cost), 0)
               FROM inv_stock sl
               JOIN inv_items i ON sl.item_id = i.item_id
               WHERE $assetFilter) AS asset_value,
            (SELECT COUNT(DISTINCT i2.item_id)
               FROM inv_items i2
               LEFT JOIN (SELECT item_id, SUM(quantity_on_hand) AS qty FROM inv_stock GROUP BY item_id) s
                 ON i2.item_id = s.item_id
               WHERE i2.item_status = 'ACTIVE'
                 AND i2.reorder_level > 0
                 AND COALESCE(s.qty, 0) <= i2.reorder_level) AS low_stock_count
    ")->fetch(PDO::FETCH_ASSOC);

    $pendingReqs      = $pdo->query("SELECT COUNT(*) FROM inv_requisitions WHERE status = 'SUBMITTED'")->fetchColumn();
    $pendingGrn       = $pdo->query("SELECT COUNT(*) FROM inv_goods_received WHERE status IN ('DRAFT','RECEIVED')")->fetchColumn();
    $pendingTransfers = $pdo->query("SELECT COUNT(*) FROM inv_transfers WHERE status = 'PENDING_APPROVAL'")->fetchColumn();
    $pendingAdj       = $pdo->query("SELECT COUNT(*) FROM inv_adjustments WHERE status = 'PENDING_APPROVAL'")->fetchColumn();
    $pendingDisp      = $pdo->query("SELECT COUNT(*) FROM inv_disposals WHERE status IN ('RECOMMENDED','PENDING_APPROVAL')")->fetchColumn();

    $expiringCount = $pdo->query("
        SELECT COUNT(DISTINCT s.item_id)
        FROM inv_stock s
        WHERE s.expiry_date IS NOT NULL
          AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
          AND s.quantity_on_hand > 0
    ")->fetchColumn();

    /* Serialized asset lifecycle stats (if table exists) */
    $snTableExists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_serial_numbers'
    ")->fetchColumn();

    if ($snTableExists) {
        $serialCount = $pdo->query("SELECT COUNT(*) FROM inv_serial_numbers")->fetchColumn();

        $lifecycleStats = $pdo->query("
            SELECT lifecycle_status, COUNT(*) AS cnt
            FROM inv_serial_numbers
            GROUP BY lifecycle_status
            ORDER BY FIELD(lifecycle_status,
                'ORDERED','RECEIVED','ASSIGNED','IN_SERVICE',
                'UNDER_REPAIR','TRANSFERRED','DISPOSED','LOST_STOLEN')
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        /* Recently registered serial numbers */
        $recentSerials = $pdo->query("
            SELECT sn.serial_number, sn.dgc_asset_number, sn.lifecycle_status,
                   sn.po_number, sn.grn_number, sn.created_at,
                   i.item_code, i.item_name,
                   u.full_name AS issued_to
            FROM inv_serial_numbers sn
            JOIN inv_items i ON sn.item_id = i.item_id
            LEFT JOIN users u ON sn.issued_to_user_id = u.user_id
            ORDER BY sn.created_at DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $assetRegistryColumns = [
            'asset_status',
            'condition_last_updated_at',
            'next_condition_review_due_date',
            'purchase_value',
            'current_book_value',
            'depreciation_last_updated_at',
            'next_depreciation_review_due_date',
        ];
        $assetColsPlaceholders = implode(',', array_fill(0, count($assetRegistryColumns), '?'));
        $assetColsStmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inv_serial_numbers'
              AND COLUMN_NAME IN ($assetColsPlaceholders)
        ");
        $assetColsStmt->execute($assetRegistryColumns);
        $availableAssetColumns = $assetColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $movementTableExists = (int) $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inv_asset_movements'
        ")->fetchColumn();
        $assetRegistryReady = ($movementTableExists > 0) && (count($availableAssetColumns) === count($assetRegistryColumns));

        if ($assetRegistryReady) {
            $assetRegistryRows = $pdo->query("
                SELECT
                    sn.serial_id,
                    sn.serial_number,
                    sn.dgc_asset_number,
                    sn.purchase_req_number,
                    sn.po_number,
                    sn.invoice_number,
                    sn.grn_number,
                    sn.asset_status,
                    sn.current_condition,
                    sn.condition_last_updated_at,
                    sn.next_condition_review_due_date,
                    sn.purchase_value,
                    sn.current_book_value,
                    sn.depreciation_last_updated_at,
                    sn.next_depreciation_review_due_date,
                    sn.bos_number,
                    i.item_code,
                    i.item_name,
                    CONCAT_WS(' / ', l.building, l.room_storage_area) AS room_register,
                    COALESCE(mv.move_count, 0) AS move_count,
                    mv.last_moved_at
                FROM inv_serial_numbers sn
                JOIN inv_items i ON sn.item_id = i.item_id
                LEFT JOIN inv_locations l ON sn.location_id = l.location_id
                LEFT JOIN (
                    SELECT serial_id, COUNT(*) AS move_count, MAX(moved_at) AS last_moved_at
                    FROM inv_asset_movements
                    GROUP BY serial_id
                ) mv ON mv.serial_id = sn.serial_id
                ORDER BY sn.created_at DESC
                LIMIT 12
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /* Recent transactions */
    $recentTxns = $pdo->query("
        SELECT st.transaction_type, st.quantity, st.created_at AS transaction_date,
               i.item_code, i.item_name, l.location_code, u.full_name
        FROM inv_transactions st
        JOIN inv_items i ON st.item_id = i.item_id
        LEFT JOIN inv_locations l ON st.location_id = l.location_id
        LEFT JOIN users u ON st.performed_by = u.user_id
        ORDER BY st.created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Top asset items by value */
    $topAssets = $pdo->query("
        SELECT i.item_code, i.item_name, c.category_name,
               i.serial_number_flag,
               SUM(sl.quantity_on_hand) AS total_qty,
               SUM(sl.quantity_on_hand * sl.unit_cost) AS total_value
        FROM inv_stock sl
        JOIN inv_items i ON sl.item_id = i.item_id
        JOIN inv_categories c ON i.category_id = c.category_id
        WHERE $assetFilter
        GROUP BY i.item_id, i.item_code, i.item_name, c.category_name, i.serial_number_flag
        ORDER BY total_value DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$totalPending = $pendingReqs + $pendingGrn + $pendingTransfers + $pendingAdj + $pendingDisp;

/* ─── Workplace procurement request tracking ───────────────────────── */
// Status display metadata (single source of truth used by both count filter and HTML)
$procStatusLabels = [
    'DRAFT'             => ['label' => 'Draft',            'color' => 'secondary', 'pending' => false],
    'SUBMITTED'         => ['label' => 'Submitted',        'color' => 'info',      'pending' => true],
    'PENDING_HOD'       => ['label' => 'Pending HOD',      'color' => 'warning',   'pending' => true],
    'PENDING_FINANCE'   => ['label' => 'Pending Finance',  'color' => 'warning',   'pending' => true],
    'PENDING_COMMITTEE' => ['label' => 'Pending Committee','color' => 'warning',   'pending' => true],
    'PENDING_DGC'       => ['label' => 'Pending DGC',      'color' => 'warning',   'pending' => true],
    'APPROVED'          => ['label' => 'Approved',         'color' => 'success',   'pending' => false],
    'DECLINED'          => ['label' => 'Declined',         'color' => 'danger',    'pending' => false],
    'CANCELLED'         => ['label' => 'Cancelled',        'color' => 'dark',      'pending' => false],
];
$procPendingStatuses = array_keys(array_filter($procStatusLabels, fn($m) => $m['pending']));

// Status counts for all procurement requests visible to this role
$procStatusCounts = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM procurement_requests
    WHERE request_type NOT IN ('REIMBURSEMENT','PETTY_CASH')
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Recent / active procurement requests (last 20)
$recentProcurement = $pdo->query("
    SELECT pr.request_id, pr.request_number, pr.description, pr.status,
           pr.estimated_value, pr.currency, pr.created_at,
           u.full_name AS requestor_name
    FROM procurement_requests pr
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.request_type NOT IN ('REIMBURSEMENT','PETTY_CASH')
    ORDER BY pr.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Pending-approval total derived from the same labels array
$procPendingTotal = array_sum(
    array_intersect_key($procStatusCounts, array_flip($procPendingStatuses))
);

$lifecycleLabels = [
    'ORDERED'     => ['label' => 'Ordered',      'color' => 'info'],
    'RECEIVED'    => ['label' => 'Received',     'color' => 'primary'],
    'ASSIGNED'    => ['label' => 'Assigned',     'color' => 'success'],
    'IN_SERVICE'  => ['label' => 'In Service',   'color' => 'success'],
    'UNDER_REPAIR'=> ['label' => 'Under Repair', 'color' => 'warning'],
    'TRANSFERRED' => ['label' => 'Transferred',  'color' => 'secondary'],
    'DISPOSED'    => ['label' => 'Disposed',     'color' => 'dark'],
    'LOST_STOLEN' => ['label' => 'Lost/Stolen',  'color' => 'danger'],
];

$assetStatusLabels = [
    'NEW' => ['label' => 'New', 'color' => 'primary'],
    'LIKE_NEW' => ['label' => 'Like New', 'color' => 'info'],
    'USED' => ['label' => 'Used', 'color' => 'secondary'],
    'POOR' => ['label' => 'Poor', 'color' => 'warning'],
    'DAMAGED' => ['label' => 'Damaged', 'color' => 'danger'],
    'GOOD' => ['label' => 'Good', 'color' => 'success'],
    'REPAIRED' => ['label' => 'Repaired', 'color' => 'warning'],
    'SERVICED' => ['label' => 'Serviced', 'color' => 'success'],
    'TO_BE_DISPOSED' => ['label' => 'To Be Disposed', 'color' => 'dark'],
    'BOARD_OF_SURVEY_ITEM' => ['label' => 'Board of Survey', 'color' => 'dark'],
    'DONATED' => ['label' => 'Donated', 'color' => 'secondary'],
    'SOLD' => ['label' => 'Sold', 'color' => 'secondary'],
];


/* Bootstrap contextual colour names used by the lifecycle/status maps above
   are translated to design-system badge variants for consistent styling. */
$pmoVariant = static function (?string $color): string {
    return match ($color) {
        'success'            => 'success',
        'danger'             => 'danger',
        'warning'            => 'warning',
        'info', 'primary'    => 'info',
        'purple'             => 'purple',
        default              => 'neutral',
    };
};

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => 'Property Management Officer',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $_SESSION['role_name'] ?? 'Property Management Officer',
        date('l, j F Y')
    ),
    'icon'        => 'bi-building-gear',
    'breadcrumbs' => [
        ['label' => 'Home',      'href' => '/dashboard/index.php'],
        ['label' => 'Inventory', 'href' => '/inventory/items/list.php'],
        ['label' => 'Property Management'],
    ],
    'actions' => [
        ['label' => 'Add Item / Asset', 'href' => '/inventory/items/add.php', 'icon' => 'bi-plus-lg', 'variant' => 'primary'],
        ['label' => 'All Items',        'href' => '/inventory/items/list.php', 'icon' => 'bi-list-ul'],
        ['label' => 'Reports',          'href' => '/inventory/reports/',       'icon' => 'bi-bar-chart'],
        ['label' => 'Refresh',          'icon' => 'bi-arrow-clockwise', 'onclick' => 'location.reload()', 'title' => 'Refresh page'],
    ],
]);
?>

<?php if (!$invReady): ?>
    <div class="ui-alert ui-alert--warning">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>Inventory module not yet set up.</strong>
            A database administrator needs to run
            <code>migrations/019_inventory_management_system.sql</code> before this module can be used.
        </div>
    </div>
<?php else: ?>

<?php
ui_kpi_grid([
    ['label' => 'Active Inventory Items', 'value' => number_format((int)$stats['active_items']),       'meta' => 'Status ACTIVE',        'icon' => 'bi-box-seam',      'variant' => 'blue',  'href' => '/inventory/items/list.php'],
    ['label' => 'Asset Items',            'value' => number_format((int)$stats['asset_items']),        'meta' => 'Capitalised assets',   'icon' => 'bi-building-gear', 'variant' => 'purple','href' => '/inventory/items/list.php?domain=ASSET'],
    ['label' => 'Asset Value',            'value' => '$' . number_format((float)($stats['asset_value'] ?? 0), 0), 'meta' => 'Book value on hand', 'icon' => 'bi-cash-stack', 'variant' => 'green'],
    ['label' => 'Serialized Items',       'value' => number_format((int)$stats['serialized_items']),   'meta' => $serialCount . ' tracked serials', 'icon' => 'bi-upc-scan', 'variant' => 'amber'],
    ['label' => 'Low Stock Alerts',       'value' => number_format((int)$stats['low_stock_count']),    'meta' => 'At or below reorder',  'icon' => 'bi-exclamation-triangle', 'variant' => ((int)$stats['low_stock_count'] > 0 ? 'red' : 'slate')],
    ['label' => 'Expiring (90 days)',     'value' => number_format((int)$expiringCount),               'meta' => 'Shelf-life items',     'icon' => 'bi-hourglass-split', 'variant' => ($expiringCount > 0 ? 'amber' : 'slate')],
    ['label' => 'Pending Actions',        'value' => number_format((int)$totalPending),                'meta' => 'Across inventory queues', 'icon' => 'bi-bell', 'variant' => ($totalPending > 0 ? 'red' : 'green')],
    ['label' => 'Workplace Requests',     'value' => number_format((int)$procPendingTotal),            'meta' => 'Procurement pending',  'icon' => 'bi-briefcase', 'variant' => 'slate', 'href' => '/procurement/list.php'],
]);

ui_tabs('pmo', [
    ['id' => 'overview',    'label' => 'Overview',    'icon' => 'bi-grid-1x2',       'active' => true],
    ['id' => 'assets',      'label' => 'Assets',      'icon' => 'bi-building-gear'],
    ['id' => 'registry',    'label' => 'Registry',    'icon' => 'bi-diagram-3-fill'],
    ['id' => 'txns',        'label' => 'Transactions','icon' => 'bi-clock-history'],
    ['id' => 'procurement', 'label' => 'Procurement', 'icon' => 'bi-briefcase'],
]);
?>

<?php ui_tabpanel_open('pmo', 'overview', true); ?>
    <div class="ui-grid ui-grid--2">
        <div>
            <?php
            ui_card_open(['title' => 'Pending Actions', 'icon' => 'bi-bell']);
            ui_action_tiles([
                ['label' => 'Requisitions', 'value' => (int)$pendingReqs,      'icon' => 'bi-file-earmark-text',   'href' => '/inventory/requisitions/list.php?status=SUBMITTED',       'variant' => 'amber'],
                ['label' => 'GRNs Pending', 'value' => (int)$pendingGrn,       'icon' => 'bi-box-seam',            'href' => '/inventory/receiving/list.php',                           'variant' => 'blue'],
                ['label' => 'Transfers',    'value' => (int)$pendingTransfers, 'icon' => 'bi-arrow-left-right',    'href' => '/inventory/transfers/list.php?status=PENDING_APPROVAL',   'variant' => 'purple'],
                ['label' => 'Adjustments',  'value' => (int)$pendingAdj,       'icon' => 'bi-sliders',             'href' => '/inventory/adjustments/list.php?status=PENDING_APPROVAL', 'variant' => 'slate'],
                ['label' => 'Disposals',    'value' => (int)$pendingDisp,      'icon' => 'bi-trash3',              'href' => '/inventory/disposal/list.php',                            'variant' => 'red'],
            ]);
            ui_card_close();
            ?>
        </div>
        <div>
            <?php
            ui_card_open(['title' => 'Quick Actions', 'icon' => 'bi-lightning-charge']);
            echo '<div class="ui-action-stack">';
            ui_actions([
                ['label' => 'Add Item / Asset',  'href' => '/inventory/items/add.php',          'icon' => 'bi-plus-lg', 'variant' => 'primary'],
                ['label' => 'Record GRN',        'href' => '/inventory/receiving/add.php',      'icon' => 'bi-box-seam'],
                ['label' => 'Issue Stock',       'href' => '/inventory/issuing/add.php',        'icon' => 'bi-box-arrow-right'],
                ['label' => 'Transfer',          'href' => '/inventory/transfers/add.php',      'icon' => 'bi-arrow-left-right'],
                ['label' => 'Disposal Request',  'href' => '/inventory/disposal/add.php',       'icon' => 'bi-trash3'],
                ['label' => 'Stock Count',       'href' => '/inventory/stocktake/add.php',      'icon' => 'bi-clipboard-data'],
                ['label' => 'View Assets',       'href' => '/inventory/items/list.php?domain=ASSET', 'icon' => 'bi-building-gear'],
            ]);
            echo '</div>';
            ui_card_close();
            ?>
        </div>
    </div>

    <?php if (!empty($lifecycleStats)): ?>
    <?php
    ui_card_open(['title' => 'Asset Lifecycle Status', 'icon' => 'bi-diagram-3']);
    $lifecycleTiles = [];
    foreach ($lifecycleLabels as $status => $meta) {
        $lifecycleTiles[] = [
            'label'   => $meta['label'],
            'value'   => (int)($lifecycleStats[$status] ?? 0),
            'variant' => $pmoVariant($meta['color']),
        ];
    }
    ui_action_tiles($lifecycleTiles);
    ?>
    <div class="ui-chain" aria-label="Asset lifecycle chain">
        <span class="ui-chain__node">PR #</span>
        <span class="ui-chain__node">PO #</span>
        <span class="ui-chain__node">Invoice #</span>
        <span class="ui-chain__node ui-chain__node--active">Serial #</span>
        <span class="ui-chain__node">GRN #</span>
        <span class="ui-chain__node">DGC Asset #</span>
        <span class="ui-chain__node">Issue Req #</span>
        <span class="ui-chain__node">BOS</span>
        <span class="ui-chain__node ui-chain__node--end">Disposed</span>
    </div>
    <?php ui_card_close(); ?>
    <?php endif; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('pmo', 'assets'); ?>
    <?php
    ui_card_open([
        'title' => 'Top Assets by Value',
        'icon'  => 'bi-trophy',
        'flush' => true,
    ]);
    if (empty($topAssets)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No assets recorded yet', 'bi-box');
        echo '</div>';
    } else {
        ui_table_toolbar('pmo-assets-table', ['placeholder' => 'Search item or category…']);
        ui_table_open('pmo-assets-table', ['perPage' => 15, 'label' => 'Top assets by value']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Item</th>
                <th scope="col" data-sortable>Category</th>
                <th scope="col" data-sortable>Serialized</th>
                <th scope="col" class="ui-num" data-sortable>Qty</th>
                <th scope="col" class="ui-num" data-sortable>Value</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($topAssets as $ta): ?>
            <tr>
                <th scope="row"><code><?= ui_e($ta['item_code']) ?></code> <?= ui_e($ta['item_name']) ?></th>
                <td><?= ui_e($ta['category_name']) ?></td>
                <td><?= $ta['serial_number_flag'] ? ui_badge('Yes', 'success') : ui_badge('No', 'neutral') ?></td>
                <td class="ui-num"><?= ui_e(number_format((float)$ta['total_qty'], 0)) ?></td>
                <td class="ui-num ui-num--strong">$<?= ui_e(number_format((float)$ta['total_value'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('pmo-assets-table');
    }
    ui_card_close();
    ?>

    <?php if (!empty($recentSerials ?? [])): ?>
    <?php
    ui_card_open([
        'title' => 'Recent Serial Number Registrations',
        'icon'  => 'bi-upc-scan',
        'flush' => true,
    ]);
    ui_table_toolbar('pmo-serials-table', ['placeholder' => 'Search serial, item or asset number…']);
    ui_table_open('pmo-serials-table', ['perPage' => 15, 'label' => 'Recent serial number registrations']);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>Serial #</th>
            <th scope="col" data-sortable>Item</th>
            <th scope="col" data-sortable>DGC Asset #</th>
            <th scope="col" data-sortable>PO #</th>
            <th scope="col" data-sortable>GRN #</th>
            <th scope="col" data-sortable>Issued To</th>
            <th scope="col" data-sortable>Status</th>
            <th scope="col" data-sortable>Registered</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($recentSerials as $rs):
        $lbl = $lifecycleLabels[$rs['lifecycle_status']] ?? ['label' => $rs['lifecycle_status'], 'color' => 'secondary'];
    ?>
        <tr>
            <th scope="row"><code><?= ui_e($rs['serial_number']) ?></code></th>
            <td>
                <span class="ui-muted"><?= ui_e($rs['item_code']) ?></span><br>
                <?= ui_e($rs['item_name']) ?>
            </td>
            <td><?= ui_e($rs['dgc_asset_number'] ?? '—') ?></td>
            <td><?= ui_e($rs['po_number'] ?? '—') ?></td>
            <td><?= ui_e($rs['grn_number'] ?? '—') ?></td>
            <td><?= ui_e($rs['issued_to'] ?? '—') ?></td>
            <td><?= ui_badge((string)$lbl['label'], $pmoVariant($lbl['color'])) ?></td>
            <td class="ui-muted"><?= ui_e(date('Y-m-d', strtotime((string)$rs['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('pmo-serials-table');
    ui_card_close();
    ?>
    <?php endif; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('pmo', 'registry'); ?>
    <?php
    ui_card_open([
        'title'    => 'Procurement → Asset → Room Registry Placement',
        'icon'     => 'bi-diagram-3-fill',
        'subtitle' => 'Full chain of custody with condition and depreciation review scheduling.',
        'flush'    => true,
    ]);

    if (!$assetRegistryReady) {
        echo '<div class="ui-card__pad"><div class="ui-alert ui-alert--info">'
           . '<i class="bi bi-info-circle" aria-hidden="true"></i><div>'
           . 'Run migration <code>migrations/2026_05_26_asset_room_registry_and_movement.sql</code> to enable room registry placement, movement history, and 6–12 month condition/depreciation review tracking.'
           . '</div></div></div>';
    } elseif (empty($assetRegistryRows)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No serialized assets available.', 'bi-box');
        echo '</div>';
    } else {
        ui_table_toolbar('pmo-registry-table', ['placeholder' => 'Search asset, serial or room…']);
        ui_table_open('pmo-registry-table', ['perPage' => 10, 'label' => 'Asset room registry placement']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Asset</th>
                <th scope="col">Procurement Chain</th>
                <th scope="col" data-sortable>Room Register</th>
                <th scope="col">Movement</th>
                <th scope="col">Condition Review</th>
                <th scope="col">Depreciation Review</th>
                <th scope="col" data-sortable>Status</th>
                <th scope="col" data-sortable>BOS</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($assetRegistryRows as $ar):
            $assetStatus = $assetStatusLabels[$ar['asset_status'] ?? ''] ?? ['label' => ($ar['asset_status'] ?? 'Unknown'), 'color' => 'secondary'];
            $conditionDue = '-';
            if (!empty($ar['next_condition_review_due_date'])) {
                $conditionDue = date('Y-m-d', strtotime($ar['next_condition_review_due_date']));
            }
            $deprDue = '-';
            if (!empty($ar['next_depreciation_review_due_date'])) {
                $deprDue = date('Y-m-d', strtotime($ar['next_depreciation_review_due_date']));
            }
            $condClass = 'secondary';
            if (!empty($ar['next_condition_review_due_date'])) {
                $condClass = (strtotime($ar['next_condition_review_due_date']) < strtotime('today')) ? 'danger' : ((strtotime($ar['next_condition_review_due_date']) <= strtotime('+180 days')) ? 'warning' : 'success');
            }
            $deprClass = 'secondary';
            if (!empty($ar['next_depreciation_review_due_date'])) {
                $deprClass = (strtotime($ar['next_depreciation_review_due_date']) < strtotime('today')) ? 'danger' : ((strtotime($ar['next_depreciation_review_due_date']) <= strtotime('+180 days')) ? 'warning' : 'success');
            }
        ?>
            <tr>
                <th scope="row">
                    <code><?= ui_e($ar['serial_number']) ?></code><br>
                    <span class="ui-muted"><?= ui_e($ar['item_code']) ?></span> <?= ui_e($ar['item_name']) ?><br>
                    <span class="ui-muted">DGC Asset: <?= ui_e($ar['dgc_asset_number'] ?? '—') ?></span>
                </th>
                <td>
                    <dl class="ui-dl ui-dl--compact">
                        <dt>PR</dt><dd><?= ui_e($ar['purchase_req_number'] ?? '—') ?></dd>
                        <dt>PO</dt><dd><?= ui_e($ar['po_number'] ?? '—') ?></dd>
                        <dt>Invoice</dt><dd><?= ui_e($ar['invoice_number'] ?? '—') ?></dd>
                        <dt>GRN</dt><dd><?= ui_e($ar['grn_number'] ?? '—') ?></dd>
                    </dl>
                </td>
                <td><?= ui_e($ar['room_register'] ?: 'Unassigned') ?></td>
                <td>
                    <?= ui_badge((int)$ar['move_count'] . ' move(s)', 'neutral') ?><br>
                    <span class="ui-muted"><?= !empty($ar['last_moved_at']) ? ui_e(date('Y-m-d', strtotime((string)$ar['last_moved_at']))) : 'No movement logged' ?></span>
                </td>
                <td>
                    <?= ui_badge((string)($ar['current_condition'] ?: 'Not set'), $pmoVariant($condClass)) ?><br>
                    <span class="ui-muted">Due: <?= ui_e($conditionDue) ?></span>
                </td>
                <td>
                    <span class="ui-num--strong">$<?= ui_e(number_format((float)($ar['current_book_value'] ?? 0), 2)) ?></span><br>
                    <span class="ui-muted">Bought: $<?= ui_e(number_format((float)($ar['purchase_value'] ?? 0), 2)) ?></span><br>
                    <?= ui_badge('Due: ' . $deprDue, $pmoVariant($deprClass)) ?>
                </td>
                <td><?= ui_badge((string)$assetStatus['label'], $pmoVariant($assetStatus['color'])) ?></td>
                <td><?= ui_e($ar['bos_number'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('pmo-registry-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('pmo', 'txns'); ?>
    <?php
    ui_card_open(['title' => 'Recent Transactions', 'icon' => 'bi-clock-history', 'flush' => true]);
    if (empty($recentTxns)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No recent transactions', 'bi-clock-history');
        echo '</div>';
    } else {
        ui_table_toolbar('pmo-txns-table', ['placeholder' => 'Search item, type or location…']);
        ui_table_open('pmo-txns-table', ['perPage' => 15, 'label' => 'Recent inventory transactions']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Date</th>
                <th scope="col" data-sortable>Type</th>
                <th scope="col" data-sortable>Item</th>
                <th scope="col" data-sortable>Location</th>
                <th scope="col" class="ui-num" data-sortable>Qty</th>
                <th scope="col" data-sortable>By</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentTxns as $t):
            $tc = match($t['transaction_type']) {
                'RECEIPT','TRANSFER_IN','ADJUSTMENT_IN','RETURN' => 'success',
                'ISSUE','TRANSFER_OUT','ADJUSTMENT_OUT','DISPOSAL' => 'danger',
                default => 'secondary'
            };
        ?>
            <tr>
                <td class="ui-muted"><?= ui_e(date('Y-m-d H:i', strtotime((string)$t['transaction_date']))) ?></td>
                <td><?= ui_badge(str_replace('_', ' ', (string)$t['transaction_type']), $pmoVariant($tc)) ?></td>
                <th scope="row"><code><?= ui_e($t['item_code']) ?></code> <?= ui_e($t['item_name']) ?></th>
                <td><?= ui_e($t['location_code'] ?? '—') ?></td>
                <td class="ui-num ui-num--strong"><?= ui_e(number_format((float)$t['quantity'], 2)) ?></td>
                <td><?= ui_e($t['full_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('pmo-txns-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php endif; /* $invReady */ ?>

<?php
/* Workplace procurement is available regardless of inventory module state. */
if ($invReady) {
    ui_tabpanel_open('pmo', 'procurement');
}
?>
    <?php
    $procTiles = [];
    foreach ($procStatusLabels as $st => $meta) {
        $cnt = (int)($procStatusCounts[$st] ?? 0);
        if ($cnt === 0) continue;
        $procTiles[] = [
            'label'   => $meta['label'],
            'value'   => $cnt,
            'href'    => '/procurement/list.php?status=' . urlencode($st),
            'variant' => $pmoVariant($meta['color']),
        ];
    }

    ui_card_open([
        'title' => 'Workplace Procurement Requests',
        'icon'  => 'bi-briefcase',
        'tools' => '<a class="ui-action ui-action--sm" href="/procurement/list.php"><i class="bi bi-list-ul" aria-hidden="true"></i><span>View All Requests</span></a>',
    ]);
    if (!empty($procTiles)) {
        ui_action_tiles($procTiles);
    } else {
        ui_empty('No procurement activity to summarise.', 'bi-briefcase');
    }
    ui_card_close();

    ui_card_open([
        'title' => 'Recent Requests (Last 20)',
        'icon'  => 'bi-clock-history',
        'tools' => ui_badge($procPendingTotal . ' Pending Action', 'warning'),
        'flush' => true,
    ]);

    if (empty($recentProcurement)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No procurement requests found.', 'bi-briefcase');
        echo '</div>';
    } else {
        ui_table_toolbar('pmo-proc-table', ['placeholder' => 'Search reference, description or requestor…']);
        ui_table_open('pmo-proc-table', ['perPage' => 10, 'label' => 'Recent workplace procurement requests']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Ref #</th>
                <th scope="col" data-sortable>Description</th>
                <th scope="col" data-sortable>Requested By</th>
                <th scope="col" class="ui-num" data-sortable>Amount</th>
                <th scope="col" data-sortable>Status</th>
                <th scope="col" data-sortable>Date</th>
                <th scope="col" data-ui-noexport></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentProcurement as $pr):
            $stMeta = $procStatusLabels[$pr['status']] ?? ['label' => $pr['status'], 'color' => 'secondary'];
        ?>
            <tr>
                <th scope="row">
                    <a class="ui-link" href="/procurement/view.php?id=<?= (int)$pr['request_id'] ?>">
                        <?= ui_e($pr['request_number'] ?? '#' . $pr['request_id']) ?>
                    </a>
                </th>
                <td class="ui-truncate" title="<?= ui_e($pr['description'] ?? '') ?>"><?= ui_e($pr['description'] ?? '—') ?></td>
                <td><?= ui_e($pr['requestor_name'] ?? '—') ?></td>
                <td class="ui-num ui-num--strong">
                    <?php if ($pr['estimated_value']): ?>
                        <?= ui_e($pr['currency'] ?? 'JMD') ?> <?= ui_e(number_format((float)$pr['estimated_value'], 2)) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= ui_badge((string)$stMeta['label'], $pmoVariant($stMeta['color'])) ?></td>
                <td class="ui-muted"><?= ui_e(date('Y-m-d', strtotime((string)$pr['created_at']))) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--sm" href="/procurement/view.php?id=<?= (int)$pr['request_id'] ?>" aria-label="View request">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('pmo-proc-table');
    }
    ui_card_close();
    ?>
<?php
if ($invReady) {
    ui_tabpanel_close();
}
?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
