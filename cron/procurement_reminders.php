<?php
/**
 * Procurement Smart Reminders Cron
 * ================================
 * Recommended schedule: 0 7 * * *   (daily at 07:00)
 *
 *   crontab -e
 *   0 7 * * * /usr/bin/php /var/www/prms/cron/procurement_reminders.php >> /var/log/prms/reminders.log 2>&1
 *
 * Responsibilities
 *   1. Run every enabled reminder type via ReminderService:
 *        RFQ not created, commitment not uploaded, PO pending,
 *        invoice pending, payment pending, inventory receipt pending.
 *   2. Honour admin configuration (enable/disable, intervals, escalation).
 *   3. Retry deliveries that previously failed, up to each type's
 *      configured `max_attempts`.
 *   4. Record execution results through CronAuditService and update the
 *      cron_job_registry heartbeat so admins can verify the job is active.
 *
 * Exit codes: 0 success / partial, 1 locked or fatal error.
 *
 * CLI flags:
 *   --dry-run      detect and report, but do not notify
 *   --type=KEY     process a single reminder type
 *   --no-email     create in-app notifications only
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/ReminderService.php';
require_once __DIR__ . '/../services/CronAuditService.php';

const REMINDER_CRON_NAME = 'procurement_reminders';

/** Timestamped console/log line. */
function reminderLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/** Parse simple CLI flags. */
function reminderCliOptions(array $argv): array
{
    $options = ['dry_run' => false, 'type' => null, 'send_email' => true];
    foreach ($argv as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--no-email') {
            $options['send_email'] = false;
        } elseif (str_starts_with($arg, '--type=')) {
            $options['type'] = strtoupper(substr($arg, 7));
        }
    }
    return $options;
}

/** Record the last run on the registry so the schedule can be verified. */
function touchCronRegistry(PDO $pdo, string $status, bool $started = false): void
{
    try {
        if ($started) {
            $pdo->prepare(
                'UPDATE cron_job_registry SET last_started_at = NOW(), last_status = ? WHERE cron_name = ?'
            )->execute([$status, REMINDER_CRON_NAME]);
            return;
        }

        if ($status === 'success') {
            $pdo->prepare(
                'UPDATE cron_job_registry SET last_success_at = NOW(), last_status = ? WHERE cron_name = ?'
            )->execute([$status, REMINDER_CRON_NAME]);
            return;
        }

        $pdo->prepare('UPDATE cron_job_registry SET last_status = ? WHERE cron_name = ?')
            ->execute([$status, REMINDER_CRON_NAME]);
    } catch (Throwable $e) {
        // Registry is advisory only; never fail the run because of it.
        reminderLog('WARN: could not update cron_job_registry: ' . $e->getMessage());
    }
}

/** True when the job has been disabled by an administrator. */
function reminderCronEnabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT is_enabled FROM cron_job_registry WHERE cron_name = ? LIMIT 1');
        $stmt->execute([REMINDER_CRON_NAME]);
        $value = $stmt->fetchColumn();
        return $value === false ? true : (bool)(int)$value;
    } catch (Throwable $e) {
        return true; // registry missing: run by default
    }
}

/**
 * Retry previously failed deliveries for a reminder type.
 *
 * @return array{retried:int,recovered:int,exhausted:int}
 */
function retryFailedReminders(ReminderService $service, string $type, array $settings, ?int $executionId): array
{
    $stats = ['retried' => 0, 'recovered' => 0, 'exhausted' => 0];

    $maxAttempts = (int)($settings['max_attempts'] ?? 3);
    foreach ($service->findRetryable($type, $maxAttempts) as $row) {
        $stats['retried']++;

        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            $stats['exhausted']++;
            continue;
        }

        $item = [
            'request_id'       => $row['request_id'] ?? null,
            'request_number'   => $row['request_ref'] ?? null,
            'days_outstanding' => $row['days_outstanding'] ?? 0,
        ];
        $isEscalation = ((int)($row['escalation_level'] ?? 0)) === 1;
        $message = $service->buildMessage($type, $item, $settings, $isEscalation);
        $notificationId = $service->createNotification($userId, $message, $item, $settings, $isEscalation);

        $service->logDispatch([
            'execution_id'     => $executionId,
            'reminder_type'    => $type,
            'request_id'       => $row['request_id'] ?? null,
            'request_ref'      => $row['request_ref'] ?? null,
            'user_id'          => $userId,
            'escalation_level' => (int)($row['escalation_level'] ?? 0),
            'status'           => $notificationId !== null ? 'sent' : 'failed',
            'attempts'         => (int)($row['attempts'] ?? 1),
            'days_outstanding' => $row['days_outstanding'] ?? null,
            'error_message'    => $notificationId !== null ? null : 'Retry failed to create notification',
            'notification_id'  => $notificationId,
            'sent_on'          => $row['sent_on'] ?? date('Y-m-d'),
        ]);

        if ($notificationId !== null) {
            $stats['recovered']++;
        } elseif (((int)($row['attempts'] ?? 1)) + 1 >= $maxAttempts) {
            $stats['exhausted']++;
        }
    }

    return $stats;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$options = reminderCliOptions($argv ?? []);

reminderLog('=== Procurement Smart Reminders starting ===');

if (!isset($pdo) || !$pdo instanceof PDO) {
    reminderLog('FATAL: no database connection available.');
    exit(1);
}

if (!reminderCronEnabled($pdo)) {
    reminderLog('Job is disabled in cron_job_registry. Nothing to do.');
    exit(0);
}

// Prevent overlapping executions.
$lockId = null;
if (class_exists('CronAuditService')) {
    $lockId = CronAuditService::acquireLock(REMINDER_CRON_NAME, 900);
    if ($lockId === null) {
        reminderLog('Another run holds the lock. Exiting.');
        exit(1);
    }
}

$executionId = class_exists('CronAuditService')
    ? CronAuditService::startExecution(REMINDER_CRON_NAME)
    : null;

touchCronRegistry($pdo, 'running', true);

$totals = [
    'candidates' => 0, 'reminders' => 0, 'escalations' => 0,
    'failures' => 0, 'skipped' => 0, 'retried' => 0, 'recovered' => 0,
];
$finalStatus = 'success';

try {
    $service = new ReminderService($pdo);

    $types = $options['type'] !== null ? [$options['type']] : ReminderService::types();

    foreach ($types as $type) {
        if (!in_array($type, ReminderService::types(), true)) {
            reminderLog("SKIP: unknown reminder type '{$type}'");
            continue;
        }

        $settings = $service->getSettings($type);

        if (!$settings['is_enabled']) {
            reminderLog(sprintf('%-26s disabled by configuration', $type));
            continue;
        }

        if ($options['dry_run']) {
            $outstanding = $service->findOutstanding($type);
            $totals['candidates'] += count($outstanding);
            reminderLog(sprintf(
                '%-26s DRY RUN: %d outstanding (delay %dd, repeat %dd, escalate %dd)',
                $type,
                count($outstanding),
                $settings['initial_delay_days'],
                $settings['repeat_interval_days'],
                $settings['escalation_after_days']
            ));
            continue;
        }

        // 1. Retry anything that failed previously.
        $retry = retryFailedReminders($service, $type, $settings, $executionId);
        $totals['retried']   += $retry['retried'];
        $totals['recovered'] += $retry['recovered'];
        if ($retry['retried'] > 0) {
            reminderLog(sprintf(
                '%-26s retried %d failed deliveries (%d recovered, %d exhausted)',
                $type,
                $retry['retried'],
                $retry['recovered'],
                $retry['exhausted']
            ));
        }

        // 2. Process newly due reminders / escalations.
        $summary = $service->processType($type, $executionId, $options['send_email']);

        $totals['candidates']  += $summary['candidates'];
        $totals['reminders']   += $summary['reminders'];
        $totals['escalations'] += $summary['escalations'];
        $totals['failures']    += $summary['failures'];
        $totals['skipped']     += $summary['skipped'];

        reminderLog(sprintf(
            '%-26s candidates=%d reminders=%d escalations=%d failures=%d skipped=%d',
            $type,
            $summary['candidates'],
            $summary['reminders'],
            $summary['escalations'],
            $summary['failures'],
            $summary['skipped']
        ));
    }

    if ($totals['failures'] > 0) {
        $finalStatus = 'partial_failure';
    }
} catch (Throwable $e) {
    $finalStatus = 'failed';
    reminderLog('FATAL: ' . $e->getMessage());

    if ($executionId !== null && class_exists('CronAuditService')) {
        CronAuditService::completeExecution(
            $executionId,
            'failed',
            $totals['candidates'],
            0,
            $totals['reminders'] + $totals['escalations'],
            $totals['failures'],
            $e->getMessage()
        );
    }
    touchCronRegistry($pdo, 'failed');

    if ($lockId !== null) {
        CronAuditService::releaseLock($lockId);
    }
    exit(1);
}

if ($executionId !== null && class_exists('CronAuditService')) {
    CronAuditService::completeExecution(
        $executionId,
        $finalStatus,
        $totals['candidates'],
        $totals['reminders'] + $totals['escalations'],
        $totals['reminders'] + $totals['escalations'],
        $totals['failures'],
        null,
        sprintf(
            'reminders=%d escalations=%d skipped=%d retried=%d recovered=%d',
            $totals['reminders'],
            $totals['escalations'],
            $totals['skipped'],
            $totals['retried'],
            $totals['recovered']
        )
    );
}

touchCronRegistry($pdo, $finalStatus === 'success' ? 'success' : $finalStatus);

if ($lockId !== null) {
    CronAuditService::releaseLock($lockId);
}

reminderLog(sprintf(
    'Done. candidates=%d reminders=%d escalations=%d failures=%d skipped=%d retried=%d recovered=%d status=%s',
    $totals['candidates'],
    $totals['reminders'],
    $totals['escalations'],
    $totals['failures'],
    $totals['skipped'],
    $totals['retried'],
    $totals['recovered'],
    $finalStatus
));

exit(0);
