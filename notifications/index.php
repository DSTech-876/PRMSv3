<?php
/**
 * Notification Center
 * ===================
 * Centralised view of a user's notifications:
 *   * unread count
 *   * read / unread status and filtering
 *   * priority levels
 *   * full notification history
 *   * direct links to the related record
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/NotificationService.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

/* ---------------------------------------------------------------------------
   Actions (POST, CSRF protected)
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/notifications/index.php');

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId > 0) {
            NotificationService::markRead($notifId, $userId);
        }
    } elseif ($action === 'mark_all_read') {
        NotificationService::markAllRead($userId);
    }

    $query = [];
    foreach (['status', 'priority', 'type'] as $key) {
        if (!empty($_POST['f_' . $key])) {
            $query[$key] = $_POST['f_' . $key];
        }
    }
    $redirect = '/notifications/index.php';
    if (!empty($query)) {
        $redirect .= '?' . http_build_query($query);
    }
    header('Location: ' . $redirect);
    exit;
}

/* ---------------------------------------------------------------------------
   Filters
--------------------------------------------------------------------------- */
$filterStatus   = $_GET['status']   ?? 'all';     // all | unread | read
$filterPriority = $_GET['priority'] ?? 'all';     // all | normal | high | urgent
$filterType     = $_GET['type']     ?? 'all';

if (!in_array($filterStatus, ['all', 'unread', 'read'], true)) {
    $filterStatus = 'all';
}
if (!in_array($filterPriority, ['all', 'normal', 'high', 'urgent'], true)) {
    $filterPriority = 'all';
}

$unreadCount = NotificationService::countUnread($userId);
$allRows     = NotificationService::getAll($userId, 200);

/* Distinct types present in this user's history, for the type filter */
$availableTypes = [];
foreach ($allRows as $row) {
    $type = (string)($row['type'] ?? '');
    if ($type !== '' && !in_array($type, $availableTypes, true)) {
        $availableTypes[] = $type;
    }
}
sort($availableTypes);
if ($filterType !== 'all' && !in_array($filterType, $availableTypes, true)) {
    $filterType = 'all';
}

$rows = array_values(array_filter($allRows, static function (array $row) use ($filterStatus, $filterPriority, $filterType): bool {
    $isRead = !empty($row['is_read']);
    if ($filterStatus === 'unread' && $isRead) {
        return false;
    }
    if ($filterStatus === 'read' && !$isRead) {
        return false;
    }
    if ($filterPriority !== 'all' && ($row['priority'] ?? 'normal') !== $filterPriority) {
        return false;
    }
    if ($filterType !== 'all' && ($row['type'] ?? '') !== $filterType) {
        return false;
    }
    return true;
}));

/* ---------------------------------------------------------------------------
   Presentation helpers
--------------------------------------------------------------------------- */
if (!function_exists('ncPriorityBadge')) {
    function ncPriorityBadge(string $priority): string
    {
        $map = [
            'urgent' => ['bg-danger', 'Urgent'],
            'high'   => ['bg-warning text-dark', 'High'],
            'normal' => ['bg-secondary', 'Normal'],
        ];
        [$class, $label] = $map[$priority] ?? $map['normal'];
        return '<span class="badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('ncTypeLabel')) {
    function ncTypeLabel(string $type): string
    {
        $map = [
            'approval_needed'         => 'Approval Needed',
            'return_correction'       => 'Returned for Correction',
            'clarification'           => 'Clarification',
            'rejection'               => 'Rejection',
            'cancellation'            => 'Cancellation',
            'draft_ready'             => 'Draft Ready',
            'submission'              => 'Submission',
            'finance_action_required' => 'Finance Action Required',
            'procurement_approved'    => 'Procurement Approved',
            'reminder'                => 'Reminder',
            'escalation'              => 'Escalation',
        ];
        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}

if (!function_exists('ncTypeIcon')) {
    function ncTypeIcon(string $type): string
    {
        $map = [
            'approval_needed'         => 'bi-shield-check',
            'return_correction'       => 'bi-arrow-counterclockwise',
            'clarification'           => 'bi-question-circle',
            'rejection'               => 'bi-x-octagon',
            'cancellation'            => 'bi-slash-circle',
            'draft_ready'             => 'bi-file-earmark-text',
            'submission'              => 'bi-send',
            'finance_action_required' => 'bi-cash-coin',
            'procurement_approved'    => 'bi-patch-check-fill',
            'reminder'                => 'bi-alarm',
            'escalation'              => 'bi-exclamation-triangle-fill',
        ];
        return $map[$type] ?? 'bi-bell';
    }
}

if (!function_exists('ncViewUrl')) {
    function ncViewUrl(array $row): ?string
    {
        $actionUrl = trim((string)($row['action_url'] ?? ''));
        if ($actionUrl !== '') {
            return $actionUrl;
        }
        $requestId = (int)($row['request_id'] ?? 0);
        return $requestId > 0 ? '/procurement/view.php?id=' . $requestId : null;
    }
}

if (!function_exists('ncRelativeTime')) {
    function ncRelativeTime(?string $timestamp): string
    {
        if (!$timestamp) {
            return '';
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60)     { return 'just now'; }
        if ($diff < 3600)   { return (int)floor($diff / 60) . 'm ago'; }
        if ($diff < 86400)  { return (int)floor($diff / 3600) . 'h ago'; }
        if ($diff < 604800) { return (int)floor($diff / 86400) . 'd ago'; }
        return date('d M Y', $ts);
    }
}

$pageTitle = 'Notification Center';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

$csrf = ensureCsrfToken();
?>

<div class="container-fluid py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h4 class="mb-1"><i class="bi bi-bell-fill me-2"></i>Notification Center</h4>
      <div class="text-muted small">
        <?= count($allRows) ?> notification<?= count($allRows) === 1 ? '' : 's' ?> in history
        <?php if ($unreadCount > 0): ?>
          &middot; <span class="fw-semibold text-danger"><?= (int)$unreadCount ?> unread</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($unreadCount > 0): ?>
      <form method="post" action="/notifications/index.php" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="mark_all_read">
        <input type="hidden" name="f_status"   value="<?= htmlspecialchars($filterStatus) ?>">
        <input type="hidden" name="f_priority" value="<?= htmlspecialchars($filterPriority) ?>">
        <input type="hidden" name="f_type"     value="<?= htmlspecialchars($filterType) ?>">
        <button type="submit" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-check2-all me-1"></i>Mark all as read
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <form method="get" action="/notifications/index.php" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small mb-1" for="status">Status</label>
        <select class="form-select form-select-sm" name="status" id="status" onchange="this.form.submit()">
          <option value="all"    <?= $filterStatus === 'all'    ? 'selected' : '' ?>>All</option>
          <option value="unread" <?= $filterStatus === 'unread' ? 'selected' : '' ?>>Unread</option>
          <option value="read"   <?= $filterStatus === 'read'   ? 'selected' : '' ?>>Read</option>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1" for="priority">Priority</label>
        <select class="form-select form-select-sm" name="priority" id="priority" onchange="this.form.submit()">
          <option value="all"    <?= $filterPriority === 'all'    ? 'selected' : '' ?>>All</option>
          <option value="urgent" <?= $filterPriority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
          <option value="high"   <?= $filterPriority === 'high'   ? 'selected' : '' ?>>High</option>
          <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1" for="type">Type</label>
        <select class="form-select form-select-sm" name="type" id="type" onchange="this.form.submit()">
          <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All types</option>
          <?php foreach ($availableTypes as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>>
              <?= htmlspecialchars(ncTypeLabel($type)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <a href="/notifications/index.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </div>
  </form>

  <!-- List -->
  <?php if (empty($rows)): ?>
    <div class="card card-body text-center text-muted py-5">
      <i class="bi bi-inbox" style="font-size:2rem;"></i>
      <div class="mt-2">No notifications match the selected filters.</div>
    </div>
  <?php else: ?>
    <div class="list-group shadow-sm">
      <?php foreach ($rows as $row):
          $isRead   = !empty($row['is_read']);
          $priority = (string)($row['priority'] ?? 'normal');
          $type     = (string)($row['type'] ?? '');
          $viewUrl  = ncViewUrl($row);
          $iconTone = $priority === 'urgent' ? 'danger' : ($priority === 'high' ? 'warning' : 'secondary');
      ?>
        <div class="list-group-item <?= $isRead ? '' : 'bg-light border-start border-4 border-primary' ?>">
          <div class="d-flex gap-3">
            <div class="pt-1">
              <i class="bi <?= htmlspecialchars(ncTypeIcon($type)) ?> fs-4 text-<?= $iconTone ?>"></i>
            </div>

            <div class="flex-grow-1">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="fw-semibold"><?= htmlspecialchars((string)($row['title'] ?? '')) ?></span>
                <?= ncPriorityBadge($priority) ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars(ncTypeLabel($type)) ?></span>
                <?php if (!$isRead): ?>
                  <span class="badge bg-primary">New</span>
                <?php endif; ?>
              </div>

              <?php if (!empty($row['body'])): ?>
                <div class="small text-muted mb-2" style="white-space:pre-line;"><?= htmlspecialchars((string)$row['body']) ?></div>
              <?php endif; ?>

              <div class="small text-muted d-flex flex-wrap gap-3">
                <?php if (!empty($row['request_ref'])): ?>
                  <span><i class="bi bi-hash"></i><?= htmlspecialchars((string)$row['request_ref']) ?></span>
                <?php endif; ?>
                <?php if (!empty($row['stage'])): ?>
                  <span><i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars((string)$row['stage']) ?></span>
                <?php endif; ?>
                <span title="<?= htmlspecialchars((string)($row['created_at'] ?? '')) ?>">
                  <i class="bi bi-clock me-1"></i><?= htmlspecialchars(ncRelativeTime($row['created_at'] ?? null)) ?>
                </span>
                <?php if ($isRead && !empty($row['read_at'])): ?>
                  <span><i class="bi bi-check2 me-1"></i>Read <?= htmlspecialchars(ncRelativeTime($row['read_at'])) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="d-flex flex-column gap-1 align-items-end">
              <?php if ($viewUrl !== null): ?>
                <a href="<?= htmlspecialchars($viewUrl) ?>" class="btn btn-sm btn-primary text-nowrap">
                  <i class="bi bi-box-arrow-up-right me-1"></i>Open
                </a>
              <?php endif; ?>
              <?php if (!$isRead): ?>
                <form method="post" action="/notifications/index.php" class="m-0">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="f_status"   value="<?= htmlspecialchars($filterStatus) ?>">
                  <input type="hidden" name="f_priority" value="<?= htmlspecialchars($filterPriority) ?>">
                  <input type="hidden" name="f_type"     value="<?= htmlspecialchars($filterType) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">
                    <i class="bi bi-check2 me-1"></i>Mark read
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
