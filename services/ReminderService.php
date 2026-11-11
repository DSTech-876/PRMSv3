<?php
/**
 * ReminderService
 * ===============
 * Smart, admin-configurable reminders for stalled procurement work.
 *
 * Responsibilities
 *   * Read reminder configuration from `reminder_settings`
 *     (enable/disable per type, initial delay, repeat interval, escalation).
 *   * Detect outstanding work for each reminder type.
 *   * Dispatch in-app + email notifications to the responsible role, and to
 *     the escalation role once the escalation threshold is crossed.
 *   * Log every dispatch result to `reminder_dispatch_log`, including
 *     failures, so that failed deliveries can be retried on the next run.
 *
 * Reminder types
 *   RFQ_PENDING                 RFQ not created after approval
 *   COMMITMENT_PENDING          Commitment not uploaded
 *   PO_PENDING                  Purchase order pending
 *   INVOICE_PENDING             Invoice pending
 *   PAYMENT_PENDING             Payment pending
 *   INVENTORY_RECEIPT_PENDING   Inventory receipt pending
 */

require_once dirname(__DIR__) . '/services/CurrencyService.php';

class ReminderService
{
    public const TYPE_RFQ        = 'RFQ_PENDING';
    public const TYPE_COMMITMENT = 'COMMITMENT_PENDING';
    public const TYPE_PO         = 'PO_PENDING';
    public const TYPE_INVOICE    = 'INVOICE_PENDING';
    public const TYPE_PAYMENT    = 'PAYMENT_PENDING';
    public const TYPE_INVENTORY  = 'INVENTORY_RECEIPT_PENDING';

    /** Default configuration, used when `reminder_settings` has no row yet. */
    public const DEFAULTS = [
        self::TYPE_RFQ => [
            'label' => 'RFQ not created after approval',
            'is_enabled' => 1, 'initial_delay_days' => 3, 'repeat_interval_days' => 3,
            'escalation_enabled' => 1, 'escalation_after_days' => 7,
            'escalation_role' => 'Procurement Officer', 'priority' => 'high', 'max_attempts' => 3,
            'responsible_role' => 'Procurement Officer',
        ],
        self::TYPE_COMMITMENT => [
            'label' => 'Commitment not uploaded',
            'is_enabled' => 1, 'initial_delay_days' => 3, 'repeat_interval_days' => 3,
            'escalation_enabled' => 1, 'escalation_after_days' => 7,
            'escalation_role' => 'Finance Officer', 'priority' => 'high', 'max_attempts' => 3,
            'responsible_role' => 'Finance Officer',
        ],
        self::TYPE_PO => [
            'label' => 'Purchase Order pending',
            'is_enabled' => 1, 'initial_delay_days' => 3, 'repeat_interval_days' => 3,
            'escalation_enabled' => 1, 'escalation_after_days' => 7,
            'escalation_role' => 'Procurement Officer', 'priority' => 'high', 'max_attempts' => 3,
            'responsible_role' => 'Procurement Officer',
        ],
        self::TYPE_INVOICE => [
            'label' => 'Invoice pending',
            'is_enabled' => 1, 'initial_delay_days' => 5, 'repeat_interval_days' => 5,
            'escalation_enabled' => 1, 'escalation_after_days' => 10,
            'escalation_role' => 'Accounts Officer', 'priority' => 'normal', 'max_attempts' => 3,
            'responsible_role' => 'Accounts Officer',
        ],
        self::TYPE_PAYMENT => [
            'label' => 'Payment pending',
            'is_enabled' => 1, 'initial_delay_days' => 5, 'repeat_interval_days' => 5,
            'escalation_enabled' => 1, 'escalation_after_days' => 10,
            'escalation_role' => 'Director Accounts & Finance', 'priority' => 'high', 'max_attempts' => 3,
            'responsible_role' => 'Accounts Officer',
        ],
        self::TYPE_INVENTORY => [
            'label' => 'Inventory receipt pending',
            'is_enabled' => 1, 'initial_delay_days' => 3, 'repeat_interval_days' => 3,
            'escalation_enabled' => 1, 'escalation_after_days' => 7,
            'escalation_role' => 'Property Management Officer', 'priority' => 'normal', 'max_attempts' => 3,
            'responsible_role' => 'Property Management Officer',
        ],
    ];

    private PDO $pdo;

    /** Current run date (YYYY-MM-DD); injectable for deterministic tests. */
    private string $today;

    public function __construct(?PDO $pdo = null, ?string $today = null)
    {
        $handle = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if (!$handle instanceof PDO) {
            throw new RuntimeException('ReminderService requires a PDO connection.');
        }
        $this->pdo   = $handle;
        $this->today = $today ?? date('Y-m-d');
    }

    /** All known reminder type identifiers. */
    public static function types(): array
    {
        return array_keys(self::DEFAULTS);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Effective configuration for a reminder type: the stored row merged over
     * the built-in defaults, so a partially-migrated database still works.
     *
     * @return array<string,mixed>
     */
    public function getSettings(string $type): array
    {
        $defaults = self::DEFAULTS[$type] ?? null;
        if ($defaults === null) {
            throw new InvalidArgumentException("Unknown reminder type: {$type}");
        }
        $settings = $defaults;
        $settings['reminder_type'] = $type;

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM reminder_settings WHERE reminder_type = ? LIMIT 1');
            $stmt->execute([$type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                foreach ($row as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $settings[$key] = $value;
                    }
                }
            }
        } catch (Throwable $e) {
            // Table not migrated yet: defaults remain in force.
        }

        $settings['is_enabled']           = (int)$settings['is_enabled'] === 1;
        $settings['escalation_enabled']   = (int)$settings['escalation_enabled'] === 1;
        $settings['initial_delay_days']   = max(0, (int)$settings['initial_delay_days']);
        $settings['repeat_interval_days'] = max(0, (int)$settings['repeat_interval_days']);
        $settings['escalation_after_days'] = max(0, (int)$settings['escalation_after_days']);
        $settings['max_attempts']         = max(1, (int)$settings['max_attempts']);

        return $settings;
    }

    /**
     * All reminder configurations keyed by type.
     *
     * @return array<string,array<string,mixed>>
     */
    public function getAllSettings(): array
    {
        $all = [];
        foreach (self::types() as $type) {
            $all[$type] = $this->getSettings($type);
        }
        return $all;
    }

    /**
     * Persist administrator changes for one reminder type.
     *
     * @param array<string,mixed> $values
     */
    public function saveSettings(string $type, array $values): bool
    {
        if (!isset(self::DEFAULTS[$type])) {
            throw new InvalidArgumentException("Unknown reminder type: {$type}");
        }

        $priority = in_array($values['priority'] ?? '', ['normal', 'high', 'urgent'], true)
            ? $values['priority']
            : self::DEFAULTS[$type]['priority'];

        try {
            $params = [
                ':type'        => $type,
                ':label'       => (string)($values['label'] ?? self::DEFAULTS[$type]['label']),
                ':descr'       => $values['description'] ?? null,
                ':enabled'     => !empty($values['is_enabled']) ? 1 : 0,
                ':initial'     => max(0, (int)($values['initial_delay_days'] ?? 3)),
                ':repeat'      => max(0, (int)($values['repeat_interval_days'] ?? 3)),
                ':esc_enabled' => !empty($values['escalation_enabled']) ? 1 : 0,
                ':esc_days'    => max(0, (int)($values['escalation_after_days'] ?? 7)),
                ':esc_role'    => $values['escalation_role'] ?? self::DEFAULTS[$type]['escalation_role'],
                ':priority'    => $priority,
                ':attempts'    => max(1, (int)($values['max_attempts'] ?? 3)),
            ];

            /* Portable upsert: update first, insert only when no row matched.
               Avoids MySQL-only ON DUPLICATE KEY UPDATE so the same code path
               works under SQLite. */
            $update = $this->pdo->prepare(
                'UPDATE reminder_settings
                    SET label                 = :label,
                        is_enabled            = :enabled,
                        initial_delay_days    = :initial,
                        repeat_interval_days  = :repeat,
                        escalation_enabled    = :esc_enabled,
                        escalation_after_days = :esc_days,
                        escalation_role       = :esc_role,
                        priority              = :priority,
                        max_attempts          = :attempts
                  WHERE reminder_type = :type'
            );
            $updateParams = $params;
            unset($updateParams[':descr']);
            $update->execute($updateParams);

            if ($update->rowCount() === 0) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO reminder_settings
                        (reminder_type, label, description, is_enabled, initial_delay_days,
                         repeat_interval_days, escalation_enabled, escalation_after_days,
                         escalation_role, priority, max_attempts)
                     VALUES (:type, :label, :descr, :enabled, :initial, :repeat,
                             :esc_enabled, :esc_days, :esc_role, :priority, :attempts)'
                );
                $insert->execute($params);
            }

            return true;
        } catch (Throwable $e) {
            error_log('ReminderService::saveSettings error: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Due-date logic
    // ------------------------------------------------------------------

    /**
     * Decide whether a reminder is due for an item outstanding for
     * $daysOutstanding days, given when it was last reminded.
     *
     * Rules:
     *   * nothing before the initial delay has elapsed;
     *   * after that, the first reminder is always due;
     *   * subsequent reminders respect the repeat interval
     *     (interval 0 means "send once only").
     */
    public function isReminderDue(array $settings, int $daysOutstanding, ?int $daysSinceLastReminder): bool
    {
        if (!$settings['is_enabled']) {
            return false;
        }
        if ($daysOutstanding < $settings['initial_delay_days']) {
            return false;
        }
        if ($daysSinceLastReminder === null) {
            return true; // never reminded
        }
        if ($settings['repeat_interval_days'] <= 0) {
            return false; // send-once reminder already delivered
        }
        return $daysSinceLastReminder >= $settings['repeat_interval_days'];
    }

    /**
     * True once an outstanding item has crossed its escalation threshold.
     */
    public function shouldEscalate(array $settings, int $daysOutstanding): bool
    {
        return $settings['is_enabled']
            && $settings['escalation_enabled']
            && $daysOutstanding >= $settings['escalation_after_days'];
    }

    // ------------------------------------------------------------------
    // Detection
    // ------------------------------------------------------------------

    /**
     * Dialect-resolved detection SQL for a reminder type, or null when the
     * type has no query. Exposed so the queries can be validated directly
     * against the schema instead of being silently swallowed.
     */
    public function detectionSqlFor(string $type): ?string
    {
        $sql = $this->outstandingSql($type);
        return $sql === null ? null : $this->applyDayDiff($sql);
    }

    /**
     * Outstanding items for a reminder type.
     *
     * Each row carries: request_id, request_number, description, branch_id,
     * branch_name, estimated_value, currency, days_outstanding.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findOutstanding(string $type): array
    {
        $sql = $this->detectionSqlFor($type);
        if ($sql === null) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("ReminderService::findOutstanding({$type}) error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * SQL per reminder type. Each query returns approved requests that are
     * missing the next lifecycle artefact, with the age of the triggering
     * event expressed in whole days.
     */
    private function outstandingSql(string $type): ?string
    {
        $base = "SELECT r.request_id, r.request_number, r.description, r.branch_id,
                        b.branch_name, r.estimated_value, r.currency, r.status, ";

        switch ($type) {
            case self::TYPE_RFQ:
                // Approved, requires an RFQ, but none has been raised.
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND COALESCE(r.requires_rfq, 1) = 1
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND NOT EXISTS (SELECT 1 FROM rfqs q WHERE q.request_id = r.request_id)";

            case self::TYPE_COMMITMENT:
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND NOT EXISTS (SELECT 1 FROM commitments c WHERE c.request_id = r.request_id)";

            case self::TYPE_PO:
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND EXISTS (SELECT 1 FROM commitments c WHERE c.request_id = r.request_id)
                    AND NOT EXISTS (
                        SELECT 1 FROM purchase_orders p
                          JOIN commitments c2 ON c2.commitment_id = p.commitment_id
                         WHERE c2.request_id = r.request_id)";

            case self::TYPE_INVOICE:
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND EXISTS (
                        SELECT 1 FROM purchase_orders p
                          JOIN commitments c ON c.commitment_id = p.commitment_id
                         WHERE c.request_id = r.request_id)
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i
                          JOIN purchase_orders p2 ON p2.po_id = i.po_id
                          JOIN commitments c2 ON c2.commitment_id = p2.commitment_id
                         WHERE c2.request_id = r.request_id)";

            case self::TYPE_PAYMENT:
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND EXISTS (
                        SELECT 1 FROM invoices i
                          JOIN purchase_orders p ON p.po_id = i.po_id
                          JOIN commitments c ON c.commitment_id = p.commitment_id
                         WHERE c.request_id = r.request_id
                           AND LOWER(i.status) <> 'paid')";

            case self::TYPE_INVENTORY:
                return $base . "
                        julianday_placeholder AS days_outstanding
                   FROM procurement_requests r
                   LEFT JOIN branches b ON b.branch_id = r.branch_id
                  WHERE r.approved_at IS NOT NULL
                    AND UPPER(r.status) NOT IN ('DRAFT','SUBMITTED','DECLINED','CANCELLED','COMPLETED')
                    AND EXISTS (
                        SELECT 1 FROM invoices i
                          JOIN purchase_orders p ON p.po_id = i.po_id
                          JOIN commitments c ON c.commitment_id = p.commitment_id
                         WHERE c.request_id = r.request_id
                           AND LOWER(i.status) = 'paid')
                    AND NOT EXISTS (
                        SELECT 1
                          FROM inv_goods_received g
                          JOIN purchase_orders gp ON gp.po_number = g.po_reference
                          JOIN commitments gc ON gc.commitment_id = gp.commitment_id
                         WHERE gc.request_id = r.request_id
                           AND UPPER(g.status) IN ('RECEIVED','INSPECTED','ACCEPTED','PARTIAL'))";
        }

        return null;
    }

    /**
     * Replace the day-difference placeholder with the dialect-appropriate
     * expression. MySQL is the production driver; SQLite is used by tests.
     */
    private function applyDayDiff(string $sql): string
    {
        $driver = '';
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            $driver = '';
        }

        $expr = $driver === 'sqlite'
            ? "CAST(julianday('now') - julianday(r.approved_at) AS INTEGER)"
            : 'CAST(DATEDIFF(NOW(), r.approved_at) AS SIGNED)';

        return str_replace('julianday_placeholder', $expr, $sql);
    }

    // ------------------------------------------------------------------
    // Dispatch logging / retry
    // ------------------------------------------------------------------

    /**
     * Days since the most recent successful reminder for this item/level,
     * or null when none has ever been sent.
     */
    public function daysSinceLastReminder(string $type, int $requestId, int $escalationLevel = 0): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT MAX(sent_on) FROM reminder_dispatch_log
                  WHERE reminder_type = ? AND request_id = ? AND escalation_level = ?
                    AND status = 'sent'"
            );
            $stmt->execute([$type, $requestId, $escalationLevel]);
            $last = $stmt->fetchColumn();
            if (!$last) {
                return null;
            }
            $lastTs  = strtotime((string)$last);
            $todayTs = strtotime($this->today);
            if ($lastTs === false || $todayTs === false) {
                return null;
            }
            return (int)floor(($todayTs - $lastTs) / 86400);
        } catch (Throwable $e) {
            error_log('ReminderService::daysSinceLastReminder error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record the outcome of a delivery attempt.
     *
     * Re-running on the same day updates the existing row (incrementing the
     * attempt counter) rather than creating duplicates, which is what makes
     * the retry mechanism safe to run repeatedly.
     */
    public function logDispatch(array $entry): bool
    {
        $status = in_array($entry['status'] ?? '', ['sent', 'failed', 'retrying', 'skipped'], true)
            ? $entry['status']
            : 'sent';

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO reminder_dispatch_log
                    (execution_id, reminder_type, request_id, request_ref, user_id,
                     escalation_level, status, attempts, days_outstanding,
                     error_message, notification_id, email_sent, sent_on)
                 VALUES (:exec, :type, :rid, :ref, :uid, :level, :status, :attempts,
                         :days, :error, :notif, :email, :sent_on)
                 ON DUPLICATE KEY UPDATE
                    status        = VALUES(status),
                    attempts      = reminder_dispatch_log.attempts + 1,
                    error_message = VALUES(error_message),
                    email_sent    = VALUES(email_sent),
                    execution_id  = VALUES(execution_id)'
            );
            $stmt->execute([
                ':exec'     => $entry['execution_id'] ?? null,
                ':type'     => (string)$entry['reminder_type'],
                ':rid'      => $entry['request_id'] ?? null,
                ':ref'      => $entry['request_ref'] ?? null,
                ':uid'      => $entry['user_id'] ?? null,
                ':level'    => (int)($entry['escalation_level'] ?? 0),
                ':status'   => $status,
                ':attempts' => max(1, (int)($entry['attempts'] ?? 1)),
                ':days'     => $entry['days_outstanding'] ?? null,
                ':error'    => $entry['error_message'] ?? null,
                ':notif'    => $entry['notification_id'] ?? null,
                ':email'    => !empty($entry['email_sent']) ? 1 : 0,
                ':sent_on'  => $entry['sent_on'] ?? $this->today,
            ]);
            return true;
        } catch (Throwable $e) {
            // SQLite (tests) has no ON DUPLICATE KEY UPDATE: fall back to a
            // plain upsert so logging still works in every environment.
            return $this->logDispatchFallback($entry, $status);
        }
    }

    private function logDispatchFallback(array $entry, string $status): bool
    {
        try {
            $find = $this->pdo->prepare(
                'SELECT id, attempts FROM reminder_dispatch_log
                  WHERE reminder_type = ? AND request_id = ? AND user_id = ?
                    AND escalation_level = ? AND sent_on = ? LIMIT 1'
            );
            $find->execute([
                (string)$entry['reminder_type'],
                $entry['request_id'] ?? null,
                $entry['user_id'] ?? null,
                (int)($entry['escalation_level'] ?? 0),
                $entry['sent_on'] ?? $this->today,
            ]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upd = $this->pdo->prepare(
                    'UPDATE reminder_dispatch_log
                        SET status = ?, attempts = ?, error_message = ?, email_sent = ?
                      WHERE id = ?'
                );
                $upd->execute([
                    $status,
                    ((int)$existing['attempts']) + 1,
                    $entry['error_message'] ?? null,
                    !empty($entry['email_sent']) ? 1 : 0,
                    (int)$existing['id'],
                ]);
                return true;
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO reminder_dispatch_log
                    (execution_id, reminder_type, request_id, request_ref, user_id,
                     escalation_level, status, attempts, days_outstanding,
                     error_message, notification_id, email_sent, sent_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $entry['execution_id'] ?? null,
                (string)$entry['reminder_type'],
                $entry['request_id'] ?? null,
                $entry['request_ref'] ?? null,
                $entry['user_id'] ?? null,
                (int)($entry['escalation_level'] ?? 0),
                $status,
                max(1, (int)($entry['attempts'] ?? 1)),
                $entry['days_outstanding'] ?? null,
                $entry['error_message'] ?? null,
                $entry['notification_id'] ?? null,
                !empty($entry['email_sent']) ? 1 : 0,
                $entry['sent_on'] ?? $this->today,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('ReminderService::logDispatch error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Previously failed deliveries that are still under the retry ceiling.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findRetryable(string $type, int $maxAttempts): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM reminder_dispatch_log
                  WHERE reminder_type = ? AND status IN ('failed','retrying')
                    AND attempts < ?
                  ORDER BY id ASC"
            );
            $stmt->execute([$type, $maxAttempts]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ReminderService::findRetryable error: ' . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Recipients + notification content
    // ------------------------------------------------------------------

    /**
     * Active users for a role, optionally scoped to a branch.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolveRecipients(string $roleName, ?int $branchId = null): array
    {
        if (trim($roleName) === '') {
            return [];
        }

        $sql = 'SELECT DISTINCT u.user_id, u.full_name, u.email
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                 WHERE r.name = :role AND u.is_active = 1';
        $params = [':role' => $roleName];

        // Branch-scope roles that are inherently branch-bound.
        if ($branchId !== null && in_array($roleName, ['Finance Officer', 'HOD', 'Branch Head'], true)) {
            $sql .= ' AND (u.branch_id = :branch OR u.branch_id IS NULL)';
            $params[':branch'] = $branchId;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ReminderService::resolveRecipients error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build the notification title/body for a reminder.
     *
     * @return array{title:string,body:string,link:string,subject:string}
     */
    public function buildMessage(string $type, array $item, array $settings, bool $isEscalation): array
    {
        $label  = (string)($settings['label'] ?? $type);
        $ref    = (string)($item['request_number'] ?? ('#' . (int)($item['request_id'] ?? 0)));
        $days   = (int)($item['days_outstanding'] ?? 0);
        $prefix = $isEscalation ? 'ESCALATION' : 'Reminder';

        $amount = CurrencyService::formatFull(
            $item['estimated_value'] ?? 0,
            $item['currency'] ?? null,
            $this->pdo
        );

        $title = $prefix . ': ' . $label . ' — ' . $ref;

        $body = implode("\n", [
            $isEscalation
                ? 'This item has passed its escalation threshold and still requires action.'
                : 'This item is outstanding and requires action.',
            '',
            'Request Number: ' . $ref,
            'Request Title: ' . trim((string)($item['description'] ?? '')),
            'Department: ' . (trim((string)($item['branch_name'] ?? '')) ?: 'Not specified'),
            'Value: ' . $amount,
            'Outstanding For: ' . $days . ' day' . ($days === 1 ? '' : 's'),
            'Pending Action: ' . $label,
        ]);

        return [
            'title'   => $title,
            'body'    => $body,
            'link'    => '/procurement/view.php?id=' . (int)($item['request_id'] ?? 0),
            'subject' => $prefix . ': ' . $label . ' (' . $ref . ')',
        ];
    }

    /**
     * Create the in-app reminder notification row.
     *
     * @return int|null notification id on success
     */
    public function createNotification(int $userId, array $message, array $item, array $settings, bool $isEscalation): ?int
    {
        $priority = $isEscalation ? 'urgent' : (string)($settings['priority'] ?? 'high');
        if (!in_array($priority, ['normal', 'high', 'urgent'], true)) {
            $priority = 'high';
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_notifications
                    (user_id, request_id, type, title, body, request_ref,
                     action_url, stage, requestor_name, priority)
                 VALUES (:uid, :rid, :type, :title, :body, :ref, :url, :stage, :rname, :prio)'
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':rid'   => (int)($item['request_id'] ?? 0) ?: null,
                ':type'  => $isEscalation ? 'escalation' : 'reminder',
                ':title' => $message['title'],
                ':body'  => $message['body'],
                ':ref'   => (string)($item['request_number'] ?? ''),
                ':url'   => $message['link'],
                ':stage' => (string)($settings['label'] ?? ''),
                ':rname' => null,
                ':prio'  => $priority,
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('ReminderService::createNotification error: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /**
     * Process a single reminder type end to end.
     *
     * @return array{type:string,enabled:bool,candidates:int,reminders:int,escalations:int,failures:int,skipped:int}
     */
    public function processType(string $type, ?int $executionId = null, bool $sendEmail = true): array
    {
        $summary = [
            'type' => $type, 'enabled' => false, 'candidates' => 0,
            'reminders' => 0, 'escalations' => 0, 'failures' => 0, 'skipped' => 0,
        ];

        $settings = $this->getSettings($type);
        if (!$settings['is_enabled']) {
            return $summary;
        }
        $summary['enabled'] = true;

        $items = $this->findOutstanding($type);
        $summary['candidates'] = count($items);

        foreach ($items as $item) {
            $requestId = (int)($item['request_id'] ?? 0);
            $days      = (int)($item['days_outstanding'] ?? 0);
            $branchId  = isset($item['branch_id']) ? (int)$item['branch_id'] : null;

            $levels = [];
            if ($this->isReminderDue($settings, $days, $this->daysSinceLastReminder($type, $requestId, 0))) {
                $levels[0] = (string)($settings['responsible_role'] ?? self::DEFAULTS[$type]['responsible_role']);
            }
            if ($this->shouldEscalate($settings, $days)
                && $this->isReminderDue($settings, $days, $this->daysSinceLastReminder($type, $requestId, 1))) {
                $levels[1] = (string)($settings['escalation_role'] ?? '');
            }

            if (empty($levels)) {
                $summary['skipped']++;
                continue;
            }

            foreach ($levels as $level => $roleName) {
                $isEscalation = $level === 1;
                $recipients   = $this->resolveRecipients($roleName, $branchId);

                if (empty($recipients)) {
                    $this->logDispatch([
                        'execution_id'     => $executionId,
                        'reminder_type'    => $type,
                        'request_id'       => $requestId,
                        'request_ref'      => $item['request_number'] ?? null,
                        'user_id'          => null,
                        'escalation_level' => $level,
                        'status'           => 'skipped',
                        'days_outstanding' => $days,
                        'error_message'    => 'No active recipients for role: ' . $roleName,
                    ]);
                    $summary['skipped']++;
                    continue;
                }

                $message = $this->buildMessage($type, $item, $settings, $isEscalation);

                foreach ($recipients as $recipient) {
                    $userId = (int)($recipient['user_id'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }

                    $notificationId = $this->createNotification($userId, $message, $item, $settings, $isEscalation);
                    $emailSent = false;
                    $error = null;

                    if ($notificationId === null) {
                        $error = 'Failed to create in-app notification';
                    } elseif ($sendEmail && !empty($recipient['email']) && function_exists('sendMail')) {
                        try {
                            $emailSent = (bool)sendMail(
                                (string)$recipient['email'],
                                $message['subject'],
                                nl2br(htmlspecialchars($message['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                            );
                            if (!$emailSent) {
                                $error = 'Email delivery returned false';
                            }
                        } catch (Throwable $e) {
                            $error = 'Email error: ' . $e->getMessage();
                        }
                    }

                    $status = $notificationId === null ? 'failed' : 'sent';

                    $this->logDispatch([
                        'execution_id'     => $executionId,
                        'reminder_type'    => $type,
                        'request_id'       => $requestId,
                        'request_ref'      => $item['request_number'] ?? null,
                        'user_id'          => $userId,
                        'escalation_level' => $level,
                        'status'           => $status,
                        'days_outstanding' => $days,
                        'error_message'    => $error,
                        'notification_id'  => $notificationId,
                        'email_sent'       => $emailSent,
                    ]);

                    if ($status === 'failed') {
                        $summary['failures']++;
                    } elseif ($isEscalation) {
                        $summary['escalations']++;
                    } else {
                        $summary['reminders']++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Process every reminder type.
     *
     * @return array<string,array<string,mixed>>
     */
    public function run(?int $executionId = null, bool $sendEmail = true): array
    {
        $results = [];
        foreach (self::types() as $type) {
            try {
                $results[$type] = $this->processType($type, $executionId, $sendEmail);
            } catch (Throwable $e) {
                error_log("ReminderService::run({$type}) error: " . $e->getMessage());
                $results[$type] = [
                    'type' => $type, 'enabled' => true, 'candidates' => 0,
                    'reminders' => 0, 'escalations' => 0, 'failures' => 1,
                    'skipped' => 0, 'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }
}
