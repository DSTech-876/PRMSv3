<?php
/**
 * Admin — Smart Reminder Settings
 * ===============================
 * Lets administrators configure, per reminder type:
 *   * enable / disable
 *   * initial delay and repeat interval
 *   * escalation threshold and escalation role
 *   * notification priority and delivery retry ceiling
 *
 * Also surfaces scheduled-job health (cron_job_registry + cron_execution_log)
 * and recent reminder delivery results so failures are visible.
 */

$REQUIRE_PERMISSION = 'manage_reminder_settings';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ReminderService.php';

$service = new ReminderService($pdo);

/* ---------------------------------------------------------------------------
   Save
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/admin/reminder_settings.php');

    $saved = 0;
    foreach (ReminderService::types() as $type) {
        if (!isset($_POST['type'][$type])) {
            continue;
        }
        $input = $_POST['type'][$type];

        $ok = $service->saveSettings($type, [
            'label'                 => ReminderService::DEFAULTS[$type]['label'],
            'is_enabled'            => !empty($input['is_enabled']),
            'initial_delay_days'    => (int)($input['initial_delay_days'] ?? 3),
            'repeat_interval_days'  => (int)($input['repeat_interval_days'] ?? 3),
            'escalation_enabled'    => !empty($input['escalation_enabled']),
            'escalation_after_days' => (int)($input['escalation_after_days'] ?? 7),
            'escalation_role'       => trim((string)($input['escalation_role'] ?? '')),
            'priority'              => $input['priority'] ?? 'high',
            'max_attempts'          => (int)($input['max_attempts'] ?? 3),
        ]);
        if ($ok) {
            $saved++;
        }
    }

    if (function_exists('logAudit')) {
        logAudit($pdo, 'reminder_settings', 0, 'UPDATE', "Updated {$saved} reminder configuration(s)");
    }

    pop("Reminder settings saved ({$saved} updated).", '/admin/reminder_settings.php', 1500, 'success');
    exit;
}

/* ---------------------------------------------------------------------------
   Load
--------------------------------------------------------------------------- */
$settings = $service->getAllSettings();

/* Available roles for the escalation selector */
$roles = [];
try {
    $roles = $pdo->query('SELECT name FROM roles ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $roles = [];
}

/* Scheduled job health */
$jobs = [];
try {
    $jobs = $pdo->query(
        'SELECT cron_name, label, schedule_expr, is_enabled,
                last_started_at, last_success_at, last_status
           FROM cron_job_registry ORDER BY cron_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $jobs = [];
}

/* Recent reminder executions */
$executions = [];
try {
    $stmt = $pdo->prepare(
        'SELECT cron_name, started_at, completed_at, status, requests_processed,
                notifications_created, notifications_failed, execution_notes
           FROM cron_execution_log
          WHERE cron_name = ?
          ORDER BY id DESC LIMIT 10'
    );
    $stmt->execute(['procurement_reminders']);
    $executions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $executions = [];
}

/* Recent failures needing attention */
$failures = [];
try {
    $failures = $pdo->query(
        "SELECT reminder_type, request_ref, user_id, escalation_level,
                status, attempts, error_message, sent_on
           FROM reminder_dispatch_log
          WHERE status IN ('failed','retrying')
          ORDER BY id DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $failures = [];
}

$pageTitle = 'Smart Reminder Settings';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

$csrf = ensureCsrfToken();
?>

<div class="container-fluid py-3">

  <div class="mb-3">
    <h4 class="mb-1"><i class="bi bi-alarm me-2"></i>Smart Reminder Settings</h4>
    <div class="text-muted small">
      Configure when reminders are sent, how often they repeat, and when they escalate.
      Reminders are delivered by the <code>procurement_reminders</code> cron job.
    </div>
  </div>

  <!-- Scheduled job health -->
  <div class="card mb-3">
    <div class="card-header py-2 fw-semibold"><i class="bi bi-clock-history me-1"></i>Scheduled Jobs</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Job</th><th>Schedule</th><th>Status</th>
            <th>Last Started</th><th>Last Success</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobs)): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">
              Job registry unavailable — apply migration
              <code>2026_11_12_notifications_reminders_multicurrency.sql</code>.
            </td></tr>
          <?php else: foreach ($jobs as $job): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars((string)$job['label']) ?></div>
                <code class="small"><?= htmlspecialchars((string)$job['cron_name']) ?></code>
              </td>
              <td><code><?= htmlspecialchars((string)$job['schedule_expr']) ?></code></td>
              <td>
                <?php if (empty($job['is_enabled'])): ?>
                  <span class="badge bg-secondary">Disabled</span>
                <?php else:
                  $st = (string)($job['last_status'] ?? '');
                  $cls = $st === 'success' ? 'bg-success' : ($st === '' ? 'bg-secondary' : ($st === 'running' ? 'bg-info' : 'bg-danger'));
                ?>
                  <span class="badge bg-success">Enabled</span>
                  <?php if ($st !== ''): ?>
                    <span class="badge <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="small"><?= htmlspecialchars((string)($job['last_started_at'] ?? '—')) ?></td>
              <td class="small"><?= htmlspecialchars((string)($job['last_success_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer small text-muted">
      Install with: <code>0 7 * * * /usr/bin/php <?= htmlspecialchars($_SERVER['DOCUMENT_ROOT']) ?>/cron/procurement_reminders.php</code>
    </div>
  </div>

  <!-- Reminder configuration -->
  <form method="post" action="/admin/reminder_settings.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="row g-3">
      <?php foreach ($settings as $type => $cfg): ?>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
              <span class="fw-semibold"><?= htmlspecialchars((string)$cfg['label']) ?></span>
              <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox"
                       id="en_<?= htmlspecialchars($type) ?>"
                       name="type[<?= htmlspecialchars($type) ?>][is_enabled]"
                       value="1" <?= $cfg['is_enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label small" for="en_<?= htmlspecialchars($type) ?>">Enabled</label>
              </div>
            </div>
            <div class="card-body">
              <code class="small text-muted d-block mb-2"><?= htmlspecialchars($type) ?></code>

              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label small mb-1">First reminder after (days)</label>
                  <input type="number" min="0" max="365" class="form-control form-control-sm"
                         name="type[<?= htmlspecialchars($type) ?>][initial_delay_days]"
                         value="<?= (int)$cfg['initial_delay_days'] ?>">
                </div>
                <div class="col-6">
                  <label class="form-label small mb-1">Repeat every (days)</label>
                  <input type="number" min="0" max="365" class="form-control form-control-sm"
                         name="type[<?= htmlspecialchars($type) ?>][repeat_interval_days]"
                         value="<?= (int)$cfg['repeat_interval_days'] ?>">
                  <div class="form-text small">0 = send once only</div>
                </div>

                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox"
                           id="esc_<?= htmlspecialchars($type) ?>"
                           name="type[<?= htmlspecialchars($type) ?>][escalation_enabled]"
                           value="1" <?= $cfg['escalation_enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="esc_<?= htmlspecialchars($type) ?>">
                      Escalate when still outstanding
                    </label>
                  </div>
                </div>

                <div class="col-6">
                  <label class="form-label small mb-1">Escalate after (days)</label>
                  <input type="number" min="0" max="365" class="form-control form-control-sm"
                         name="type[<?= htmlspecialchars($type) ?>][escalation_after_days]"
                         value="<?= (int)$cfg['escalation_after_days'] ?>">
                </div>
                <div class="col-6">
                  <label class="form-label small mb-1">Escalate to role</label>
                  <select class="form-select form-select-sm"
                          name="type[<?= htmlspecialchars($type) ?>][escalation_role]">
                    <?php
                    $current = (string)($cfg['escalation_role'] ?? '');
                    $options = $roles;
                    if ($current !== '' && !in_array($current, $options, true)) {
                        array_unshift($options, $current);
                    }
                    foreach ($options as $role): ?>
                      <option value="<?= htmlspecialchars((string)$role) ?>"
                        <?= $current === $role ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$role) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-6">
                  <label class="form-label small mb-1">Priority</label>
                  <select class="form-select form-select-sm"
                          name="type[<?= htmlspecialchars($type) ?>][priority]">
                    <?php foreach (['normal', 'high', 'urgent'] as $p): ?>
                      <option value="<?= $p ?>" <?= ($cfg['priority'] ?? 'high') === $p ? 'selected' : '' ?>>
                        <?= ucfirst($p) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6">
                  <label class="form-label small mb-1">Retry attempts</label>
                  <input type="number" min="1" max="10" class="form-control form-control-sm"
                         name="type[<?= htmlspecialchars($type) ?>][max_attempts]"
                         value="<?= (int)$cfg['max_attempts'] ?>">
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-3">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-1"></i>Save Reminder Settings
      </button>
    </div>
  </form>

  <!-- Execution history -->
  <div class="card mt-4">
    <div class="card-header py-2 fw-semibold"><i class="bi bi-list-check me-1"></i>Recent Reminder Runs</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Started</th><th>Completed</th><th>Status</th><th>Processed</th><th>Created</th><th>Failed</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php if (empty($executions)): ?>
            <tr><td colspan="7" class="text-muted text-center py-3">No reminder runs recorded yet.</td></tr>
          <?php else: foreach ($executions as $run): ?>
            <tr>
              <td class="small"><?= htmlspecialchars((string)$run['started_at']) ?></td>
              <td class="small"><?= htmlspecialchars((string)($run['completed_at'] ?? '—')) ?></td>
              <td>
                <?php $s = (string)$run['status']; ?>
                <span class="badge <?= $s === 'success' ? 'bg-success' : ($s === 'partial_failure' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                  <?= htmlspecialchars($s) ?>
                </span>
              </td>
              <td><?= (int)$run['requests_processed'] ?></td>
              <td><?= (int)$run['notifications_created'] ?></td>
              <td><?= (int)$run['notifications_failed'] ?></td>
              <td class="small text-muted"><?= htmlspecialchars((string)($run['execution_notes'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Failures -->
  <?php if (!empty($failures)): ?>
    <div class="card mt-3 border-danger">
      <div class="card-header py-2 fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>Reminder Delivery Failures (pending retry)
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Type</th><th>Request</th><th>User</th><th>Level</th><th>Status</th><th>Attempts</th><th>Error</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach ($failures as $f): ?>
              <tr>
                <td class="small"><?= htmlspecialchars((string)$f['reminder_type']) ?></td>
                <td class="small"><?= htmlspecialchars((string)($f['request_ref'] ?? '—')) ?></td>
                <td class="small"><?= (int)($f['user_id'] ?? 0) ?: '—' ?></td>
                <td class="small"><?= ((int)$f['escalation_level'] === 1) ? 'Escalation' : 'Reminder' ?></td>
                <td><span class="badge bg-danger"><?= htmlspecialchars((string)$f['status']) ?></span></td>
                <td><?= (int)$f['attempts'] ?></td>
                <td class="small text-muted"><?= htmlspecialchars((string)($f['error_message'] ?? '')) ?></td>
                <td class="small"><?= htmlspecialchars((string)$f['sent_on']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
