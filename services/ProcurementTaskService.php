<?php
/**
 * ProcurementTaskService
 * ======================
 * Task completion tracking that replaces strict pipeline dependency logic.
 *
 * The board is built from two layers:
 *
 *   1. DERIVED state — read live from rfqs / commitments / purchase_orders /
 *      invoices / payments.  This is what makes legacy records work with no
 *      data backfill: a request submitted long before this feature existed
 *      still renders a complete, accurate task board.
 *
 *   2. EXPLICIT state — rows in `procurement_tasks` (task_status,
 *      task_completed_by, task_completed_date).  These override the derived
 *      value and are how a user records "not required" or marks a task done
 *      out of sequence.
 *
 * Every lookup is defensive: a missing table or column degrades that task to
 * PENDING instead of raising, so the panel can never break a request page.
 */

require_once __DIR__ . '/WorkflowModeService.php';

final class ProcurementTaskService
{
    // --- Task keys -----------------------------------------------------
    public const TASK_REQUEST_APPROVAL  = 'REQUEST_APPROVAL';
    public const TASK_FUND_VERIFICATION = 'FUND_VERIFICATION';
    public const TASK_RFQ               = 'RFQ';
    public const TASK_COMMITMENT        = 'COMMITMENT';
    public const TASK_PO                = 'PO';
    public const TASK_INVOICE           = 'INVOICE';
    public const TASK_PAYMENT           = 'PAYMENT';

    // --- Task statuses -------------------------------------------------
    public const STATUS_PENDING      = 'PENDING';
    public const STATUS_IN_PROGRESS  = 'IN_PROGRESS';
    public const STATUS_COMPLETED    = 'COMPLETED';
    public const STATUS_NOT_REQUIRED = 'NOT_REQUIRED';

    /**
     * Ordered task definitions. `optional` marks a task that never blocks the
     * procurement from being considered complete — fund verification is
     * optional/informational in PARALLEL_MODE because Branch Head approval
     * already confirms budget availability.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function taskDefinitions(): array
    {
        return [
            [
                'key'        => self::TASK_REQUEST_APPROVAL,
                'label'      => 'Request',
                'department' => 'Requestor / Branch Head',
                'optional'   => false,
            ],
            [
                'key'        => self::TASK_FUND_VERIFICATION,
                'label'      => 'Fund Verification',
                'department' => 'Accounts',
                'optional'   => true,
            ],
            [
                'key'        => self::TASK_RFQ,
                'label'      => 'RFQ',
                'department' => 'Procurement',
                'optional'   => false,
            ],
            [
                'key'        => self::TASK_COMMITMENT,
                'label'      => 'Commitment',
                'department' => 'Accounts',
                'optional'   => false,
            ],
            [
                'key'        => self::TASK_PO,
                'label'      => 'PO',
                'department' => 'Procurement',
                'optional'   => false,
            ],
            [
                'key'        => self::TASK_INVOICE,
                'label'      => 'Invoice',
                'department' => 'Procurement / Accounts',
                'optional'   => false,
            ],
            [
                'key'        => self::TASK_PAYMENT,
                'label'      => 'Payment',
                'department' => 'Accounts',
                'optional'   => false,
            ],
        ];
    }

    public static function validStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_NOT_REQUIRED,
        ];
    }

    public static function normalizeStatus(?string $status): string
    {
        $status = strtoupper(trim((string)$status));
        return in_array($status, self::validStatuses(), true) ? $status : self::STATUS_PENDING;
    }

    /**
     * Presentation metadata for a task status.
     *
     * @return array{icon:string,label:string,badge:string,css:string}
     */
    public static function statusPresentation(string $status): array
    {
        switch (self::normalizeStatus($status)) {
            case self::STATUS_COMPLETED:
                return ['icon' => '✅', 'label' => 'Completed',    'badge' => 'bg-success',             'css' => 'task-completed'];
            case self::STATUS_IN_PROGRESS:
                return ['icon' => '🔄', 'label' => 'In Progress',  'badge' => 'bg-primary',             'css' => 'task-in-progress'];
            case self::STATUS_NOT_REQUIRED:
                return ['icon' => '➖', 'label' => 'Not Required', 'badge' => 'bg-secondary',           'css' => 'task-not-required'];
            default:
                return ['icon' => '⏳', 'label' => 'Pending',      'badge' => 'bg-warning text-dark',   'css' => 'task-pending'];
        }
    }

    // ------------------------------------------------------------------
    // Board assembly
    // ------------------------------------------------------------------

    /**
     * Build the full task board for a request.
     *
     * @param array<string,mixed>|int $request Request row or request_id
     * @return array{
     *   request_id:int,
     *   mode:string,
     *   approved:bool,
     *   tasks:array<int,array<string,mixed>>,
     *   summary:array<string,int>
     * }
     */
    public static function getTaskBoard(PDO $pdo, $request): array
    {
        if (is_array($request)) {
            $row = $request;
        } else {
            $row = WorkflowModeService::loadRequest($pdo, (int)$request) ?? [];
        }

        $requestId = (int)($row['request_id'] ?? 0);
        $derived   = self::deriveTaskStates($pdo, $requestId, $row);
        $overrides = self::fetchOverrides($pdo, $requestId);

        $tasks = [];
        foreach (self::taskDefinitions() as $definition) {
            $key     = $definition['key'];
            $state   = $derived[$key] ?? ['status' => self::STATUS_PENDING, 'detail' => ''];
            $status  = self::normalizeStatus($state['status']);
            $detail  = (string)($state['detail'] ?? '');
            $by      = null;
            $byName  = null;
            $date    = $state['completed_date'] ?? null;
            $source  = 'derived';

            if (isset($overrides[$key])) {
                $override = $overrides[$key];
                $status   = self::normalizeStatus($override['task_status'] ?? null);
                $by       = $override['task_completed_by'] !== null ? (int)$override['task_completed_by'] : null;
                $byName   = $override['completed_by_name'] ?? null;
                $date     = $override['task_completed_date'] ?? $date;
                $detail   = ($override['notes'] ?? '') !== '' ? (string)$override['notes'] : $detail;
                $source   = 'recorded';
            }

            $presentation = self::statusPresentation($status);

            $tasks[] = [
                'key'                 => $key,
                'label'               => $definition['label'],
                'department'          => $definition['department'],
                'optional'            => (bool)$definition['optional'],
                'task_status'         => $status,
                'task_completed_by'   => $by,
                'completed_by_name'   => $byName,
                'task_completed_date' => $date,
                'detail'              => $detail,
                'source'              => $source,
                'icon'                => $presentation['icon'],
                'status_label'        => $presentation['label'],
                'badge'               => $presentation['badge'],
            ];
        }

        $summary = [
            'total'        => count($tasks),
            'completed'    => 0,
            'in_progress'  => 0,
            'pending'      => 0,
            'not_required' => 0,
        ];
        foreach ($tasks as $task) {
            switch ($task['task_status']) {
                case self::STATUS_COMPLETED:    $summary['completed']++;    break;
                case self::STATUS_IN_PROGRESS:  $summary['in_progress']++;  break;
                case self::STATUS_NOT_REQUIRED: $summary['not_required']++; break;
                default:                        $summary['pending']++;      break;
            }
        }
        $actionable = $summary['total'] - $summary['not_required'];
        $summary['percent_complete'] = $actionable > 0
            ? (int)round(($summary['completed'] / $actionable) * 100)
            : 100;

        return [
            'request_id' => $requestId,
            'mode'       => $row ? WorkflowModeService::resolveRequestMode($pdo, $row) : WorkflowModeService::getMode($pdo),
            'approved'   => $row ? WorkflowModeService::isApprovedForProcurement($pdo, $row) : false,
            'tasks'      => $tasks,
            'summary'    => $summary,
        ];
    }

    /**
     * Record or update an explicit task state.
     * COMPLETED stamps task_completed_by / task_completed_date; any other
     * status clears them so a reopened task does not keep a stale signature.
     */
    public static function markTask(
        PDO $pdo,
        int $requestId,
        string $taskKey,
        string $status,
        ?int $userId = null,
        string $notes = ''
    ): bool {
        $taskKey = strtoupper(trim($taskKey));
        $known   = array_column(self::taskDefinitions(), 'key');
        if ($requestId <= 0 || !in_array($taskKey, $known, true)) {
            return false;
        }

        $status        = self::normalizeStatus($status);
        $isCompleted   = $status === self::STATUS_COMPLETED;
        $completedBy   = $isCompleted ? $userId : null;
        $completedDate = $isCompleted ? date('Y-m-d H:i:s') : null;

        // Portable upsert: UPDATE first, INSERT when no existing row matched.
        // Avoids MySQL-only ON DUPLICATE KEY UPDATE so the same code path is
        // exercised by the SQLite-backed unit tests.
        try {
            $update = $pdo->prepare('
                UPDATE procurement_tasks
                SET task_status = ?, task_completed_by = ?, task_completed_date = ?, notes = ?
                WHERE request_id = ? AND task_key = ?
            ');
            $update->execute([$status, $completedBy, $completedDate, $notes, $requestId, $taskKey]);

            if ($update->rowCount() > 0) {
                return true;
            }

            $insert = $pdo->prepare('
                INSERT INTO procurement_tasks
                    (request_id, task_key, task_status, task_completed_by, task_completed_date, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            return $insert->execute([$requestId, $taskKey, $status, $completedBy, $completedDate, $notes]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Convenience wrapper used by action endpoints after a successful commit.
     * Never throws — task tracking must not break a completed workflow action.
     */
    public static function recordCompletion(PDO $pdo, int $requestId, string $taskKey, ?int $userId = null, string $notes = ''): void
    {
        try {
            self::markTask($pdo, $requestId, $taskKey, self::STATUS_COMPLETED, $userId, $notes);
        } catch (Throwable $e) {
            // tracking is best-effort
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private static function fetchOverrides(PDO $pdo, int $requestId): array
    {
        if ($requestId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('
                SELECT t.task_key, t.task_status, t.task_completed_by, t.task_completed_date, t.notes,
                       u.full_name AS completed_by_name
                FROM procurement_tasks t
                LEFT JOIN users u ON u.user_id = t.task_completed_by
                WHERE t.request_id = ?
            ');
            $stmt->execute([$requestId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // users table / column shape differences must not break the board
            try {
                $stmt = $pdo->prepare('SELECT task_key, task_status, task_completed_by, task_completed_date, notes FROM procurement_tasks WHERE request_id = ?');
                $stmt->execute([$requestId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $inner) {
                return [];
            }
        }

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[strtoupper((string)$row['task_key'])] = $row;
        }
        return $byKey;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,array<string,mixed>>
     */
    private static function deriveTaskStates(PDO $pdo, int $requestId, array $request): array
    {
        $status = strtoupper(trim((string)($request['status'] ?? '')));
        $states = [];

        // --- Request approval -----------------------------------------
        if ($request === []) {
            $states[self::TASK_REQUEST_APPROVAL] = ['status' => self::STATUS_PENDING, 'detail' => ''];
        } elseif (in_array($status, ['DECLINED', 'CANCELLED'], true)) {
            $states[self::TASK_REQUEST_APPROVAL] = [
                'status' => self::STATUS_NOT_REQUIRED,
                'detail' => ucfirst(strtolower($status)),
            ];
        } elseif (WorkflowModeService::isApprovedForProcurement($pdo, $request)) {
            $states[self::TASK_REQUEST_APPROVAL] = [
                'status'         => self::STATUS_COMPLETED,
                'detail'         => 'Approved',
                'completed_date' => $request['approved_at'] ?? null,
            ];
        } elseif ($status === 'DRAFT' || $status === '') {
            $states[self::TASK_REQUEST_APPROVAL] = ['status' => self::STATUS_PENDING, 'detail' => 'Not submitted'];
        } else {
            $states[self::TASK_REQUEST_APPROVAL] = ['status' => self::STATUS_IN_PROGRESS, 'detail' => 'Awaiting approval'];
        }

        // --- Fund verification (optional / informational) ---------------
        $fundsVerified = !empty($request['finance_reviewed_at'])
            || (int)($request['funds_available'] ?? 0) === 1
            || $status === 'FUNDS_VERIFIED';
        $states[self::TASK_FUND_VERIFICATION] = $fundsVerified
            ? [
                'status'         => self::STATUS_COMPLETED,
                'detail'         => 'Funds confirmed',
                'completed_date' => $request['finance_reviewed_at'] ?? null,
            ]
            : ['status' => self::STATUS_PENDING, 'detail' => 'Optional — informational only'];

        // --- RFQ --------------------------------------------------------
        $rfq = self::fetchOne($pdo, 'SELECT rfq_id, rfq_number, status, rfq_date, created_at FROM rfqs WHERE request_id = ? ORDER BY rfq_id DESC LIMIT 1', [$requestId]);
        if ($rfq) {
            $rfqStatus = strtoupper((string)($rfq['status'] ?? ''));
            $number    = (string)($rfq['rfq_number'] ?? '');
            if (in_array($rfqStatus, ['AWARDED', 'CLOSED', 'COMPLETED'], true)) {
                $states[self::TASK_RFQ] = ['status' => self::STATUS_COMPLETED, 'detail' => trim($number . ' awarded'), 'completed_date' => $rfq['created_at'] ?? null];
            } elseif (in_array($status, ['QUOTE_APPROVED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'INVOICE_RECEIVED', 'COMPLETED'], true)) {
                $states[self::TASK_RFQ] = ['status' => self::STATUS_COMPLETED, 'detail' => trim($number . ' quote approved')];
            } else {
                $states[self::TASK_RFQ] = ['status' => self::STATUS_IN_PROGRESS, 'detail' => trim($number . ' ' . strtolower(str_replace('_', ' ', $rfqStatus)))];
            }
        } elseif (array_key_exists('requires_rfq', $request) && (int)$request['requires_rfq'] === 0) {
            $states[self::TASK_RFQ] = ['status' => self::STATUS_NOT_REQUIRED, 'detail' => 'Direct procurement'];
        } else {
            $states[self::TASK_RFQ] = ['status' => self::STATUS_PENDING, 'detail' => 'Not yet created'];
        }

        // --- Commitment --------------------------------------------------
        $commitment = self::fetchOne(
            $pdo,
            "SELECT commitment_id, commitment_number, status, document_path, approved_at, created_at
             FROM commitments WHERE request_id = ? ORDER BY commitment_id DESC LIMIT 1",
            [$requestId]
        );
        if ($commitment) {
            $cStatus = strtolower((string)($commitment['status'] ?? ''));
            $number  = (string)($commitment['commitment_number'] ?? '');
            if ($cStatus === 'closed' || !empty($commitment['approved_at'])) {
                $states[self::TASK_COMMITMENT] = ['status' => self::STATUS_COMPLETED, 'detail' => trim($number . ' approved'), 'completed_date' => $commitment['approved_at'] ?? null];
            } else {
                $states[self::TASK_COMMITMENT] = ['status' => self::STATUS_IN_PROGRESS, 'detail' => trim($number . ' open')];
            }
        } else {
            $states[self::TASK_COMMITMENT] = ['status' => self::STATUS_PENDING, 'detail' => 'Not yet created'];
        }

        // --- Purchase order ----------------------------------------------
        $po = self::fetchOne(
            $pdo,
            'SELECT p.po_id, p.po_number, p.status, p.approved_at, p.created_at
             FROM purchase_orders p
             JOIN commitments c ON c.commitment_id = p.commitment_id
             WHERE c.request_id = ?
             ORDER BY p.po_id DESC LIMIT 1',
            [$requestId]
        );
        $poRequired = !(isset($request['po_required']) && strtoupper((string)$request['po_required']) === 'NO');
        if ($po) {
            $number = (string)($po['po_number'] ?? '');
            if (!empty($po['approved_at'])) {
                $states[self::TASK_PO] = ['status' => self::STATUS_COMPLETED, 'detail' => trim($number . ' approved'), 'completed_date' => $po['approved_at']];
            } else {
                $states[self::TASK_PO] = ['status' => self::STATUS_IN_PROGRESS, 'detail' => trim($number . ' created')];
            }
        } elseif (!$poRequired) {
            $states[self::TASK_PO] = ['status' => self::STATUS_NOT_REQUIRED, 'detail' => 'No PO required'];
        } else {
            $states[self::TASK_PO] = ['status' => self::STATUS_PENDING, 'detail' => 'Not yet created'];
        }

        // --- Invoice -------------------------------------------------------
        $invoice = self::fetchOne(
            $pdo,
            'SELECT i.invoice_id, i.invoice_number, i.status, i.invoice_amount, i.created_at
             FROM invoices i
             JOIN purchase_orders p ON p.po_id = i.po_id
             JOIN commitments c ON c.commitment_id = p.commitment_id
             WHERE c.request_id = ?
             ORDER BY i.invoice_id DESC LIMIT 1',
            [$requestId]
        );
        if ($invoice) {
            $number  = (string)($invoice['invoice_number'] ?? '');
            $iStatus = strtolower((string)($invoice['status'] ?? ''));
            $states[self::TASK_INVOICE] = [
                'status'         => self::STATUS_COMPLETED,
                'detail'         => trim($number . ' recorded' . ($iStatus !== '' ? ' (' . $iStatus . ')' : '')),
                'completed_date' => $invoice['created_at'] ?? null,
            ];
            $states[self::TASK_PAYMENT] = $iStatus === 'paid'
                ? ['status' => self::STATUS_COMPLETED,   'detail' => 'Invoice fully paid']
                : ($iStatus === 'partially paid'
                    ? ['status' => self::STATUS_IN_PROGRESS, 'detail' => 'Partially paid']
                    : ['status' => self::STATUS_PENDING,     'detail' => 'Awaiting payment']);
        } else {
            $states[self::TASK_INVOICE] = ['status' => self::STATUS_PENDING, 'detail' => 'Not yet uploaded'];
            $states[self::TASK_PAYMENT] = ['status' => self::STATUS_PENDING, 'detail' => 'Awaiting invoice'];
        }

        // A recorded payment always counts, even if invoice status lags.
        $payment = self::fetchOne(
            $pdo,
            'SELECT y.payment_id, y.payment_reference, y.payment_date
             FROM payments y
             JOIN invoices i ON i.invoice_id = y.invoice_id
             JOIN purchase_orders p ON p.po_id = i.po_id
             JOIN commitments c ON c.commitment_id = p.commitment_id
             WHERE c.request_id = ?
             ORDER BY y.payment_id DESC LIMIT 1',
            [$requestId]
        );
        if ($payment && ($states[self::TASK_PAYMENT]['status'] ?? '') === self::STATUS_PENDING) {
            $states[self::TASK_PAYMENT] = [
                'status'         => self::STATUS_IN_PROGRESS,
                'detail'         => trim('Payment ' . (string)($payment['payment_reference'] ?? '') . ' recorded'),
                'completed_date' => $payment['payment_date'] ?? null,
            ];
        }

        return $states;
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private static function fetchOne(PDO $pdo, string $sql, array $params): ?array
    {
        if (($params[0] ?? 0) <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
