<?php
/**
 * WorkflowModeService
 * ===================
 * Central switch between the two supported procurement workflow modes.
 *
 *   LEGACY_MODE   — the existing sequential pipeline.  Stage transitions are
 *                   validated against allowedTransitions() and every action is
 *                   gated on the request reaching a specific status first.
 *                   This is the default and is completely unchanged.
 *
 *   PARALLEL_MODE — once a request has cleared its required approval chain
 *                   (Branch Head / HOD approval confirms budget availability
 *                   and management authorization), Procurement and Accounts
 *                   work independently.  Fund verification becomes optional
 *                   and informational; it no longer blocks RFQ creation.
 *
 * Resolution order for a given request:
 *   1. procurement_requests.workflow_mode, when pinned to an explicit mode
 *   2. system_config.procurement_workflow_mode (the global admin setting)
 *
 * The per-request column defaults to 'INHERIT' so that switching the system
 * setting immediately applies to every historical record without any data
 * rewrite — satisfying the "no record becomes stuck" requirement.
 */

require_once __DIR__ . '/SystemConfigService.php';

final class WorkflowModeService
{
    public const MODE_LEGACY   = 'LEGACY_MODE';
    public const MODE_PARALLEL = 'PARALLEL_MODE';
    public const MODE_INHERIT  = 'INHERIT';

    public const CONFIG_KEY = 'procurement_workflow_mode';

    /** Statuses from which no further procurement activity may start. */
    private const TERMINAL_STATUSES = ['DRAFT', 'DECLINED', 'CANCELLED', 'PAUSED'];

    /**
     * Statuses that, on their own, prove the approval chain has been cleared.
     * Anything at or beyond a first approval grants procurement eligibility.
     */
    private const APPROVED_STATUSES = [
        'HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'FUNDS_VERIFIED',
        'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
        'COMMITTEE_RECOMMENDED', 'QUOTE_REVIEW_PENDING',
        'ADDITIONAL_QUOTATIONS_REQUIRED', 'QUOTE_REQUESTOR_REVIEW_PENDING',
        'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
        'QUOTE_APPROVED', 'COMMITMENT_REVIEW_PENDING', 'COMMITMENTS_PENDING',
        'COMMITMENT_APPROVED', 'PO_PENDING', 'PO_APPROVED', 'INVOICE_RECEIVED',
        'AWARDED', 'COMPLETED',
    ];

    /** @var array<string,string>|null simple per-request memo cache */
    private static ?array $modeCache = null;

    public static function availableModes(): array
    {
        return [
            self::MODE_LEGACY   => 'Legacy Sequential Mode',
            self::MODE_PARALLEL => 'Parallel Procurement Mode',
        ];
    }

    public static function modeLabel(string $mode): string
    {
        return self::availableModes()[self::normalizeMode($mode)] ?? 'Legacy Sequential Mode';
    }

    /**
     * Coerce any stored/posted value to a valid mode. Unknown values fall back
     * to LEGACY_MODE so a corrupt setting can never unlock the workflow.
     */
    public static function normalizeMode(?string $mode): string
    {
        $mode = strtoupper(trim((string)$mode));
        return $mode === self::MODE_PARALLEL ? self::MODE_PARALLEL : self::MODE_LEGACY;
    }

    /** The global, system-wide workflow mode. */
    public static function getMode(PDO $pdo): string
    {
        return self::normalizeMode(
            SystemConfigService::getString($pdo, self::CONFIG_KEY, self::MODE_LEGACY)
        );
    }

    public static function isParallelMode(PDO $pdo): bool
    {
        return self::getMode($pdo) === self::MODE_PARALLEL;
    }

    /** Persist the global workflow mode. Returns the stored (normalized) value. */
    public static function setMode(PDO $pdo, ?string $mode): string
    {
        $normalized = self::normalizeMode($mode);
        SystemConfigService::upsert(
            $pdo,
            self::CONFIG_KEY,
            $normalized,
            'Procurement workflow mode: LEGACY_MODE (sequential stage gating) or PARALLEL_MODE (independent departmental tasks after approval)'
        );
        self::$modeCache = null;
        return $normalized;
    }

    /**
     * Effective mode for a single request: an explicitly pinned per-record
     * mode wins, otherwise the global setting applies.
     *
     * @param array<string,mixed> $request
     */
    public static function resolveRequestMode(PDO $pdo, array $request): string
    {
        $pinned = strtoupper(trim((string)($request['workflow_mode'] ?? self::MODE_INHERIT)));
        if ($pinned === self::MODE_PARALLEL || $pinned === self::MODE_LEGACY) {
            return $pinned;
        }
        return self::getMode($pdo);
    }

    /** @param array<string,mixed> $request */
    public static function isParallelRequest(PDO $pdo, array $request): bool
    {
        return self::resolveRequestMode($pdo, $request) === self::MODE_PARALLEL;
    }

    /**
     * Pin a single request to an explicit mode, or pass null/INHERIT to make
     * it follow the global setting again.
     */
    public static function pinRequestMode(PDO $pdo, int $requestId, ?string $mode): string
    {
        $value = ($mode === null || strtoupper(trim((string)$mode)) === self::MODE_INHERIT)
            ? self::MODE_INHERIT
            : self::normalizeMode($mode);

        $stmt = $pdo->prepare('UPDATE procurement_requests SET workflow_mode = ? WHERE request_id = ?');
        $stmt->execute([$value, $requestId]);
        return $value;
    }

    /**
     * Has the request cleared its approval chain?  Branch Head approval is
     * treated as confirmation of both budget availability and management
     * authorization, which is what grants procurement eligibility.
     *
     * True when the request has reached an approved status, or when no
     * `pending` REQUEST-level approval rows remain and the request has moved
     * past DRAFT.
     *
     * @param array<string,mixed> $request
     */
    public static function isApprovedForProcurement(PDO $pdo, array $request): bool
    {
        $status = strtoupper(trim((string)($request['status'] ?? '')));

        if ($status === '' || in_array($status, self::TERMINAL_STATUSES, true)) {
            return false;
        }
        if (in_array($status, self::APPROVED_STATUSES, true)) {
            return true;
        }

        // SUBMITTED: approved only once every required approval stage is closed.
        $requestId = (int)($request['request_id'] ?? 0);
        if ($requestId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM request_approvals
                WHERE request_id = ?
                  AND status = 'pending'
                  AND (entity_type IS NULL OR entity_type = '' OR entity_type = 'REQUEST')
            ");
            $stmt->execute([$requestId]);
            $pending = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Without approval data we must not unlock anything.
            return false;
        }

        if ($pending > 0) {
            return false;
        }

        // Guard against a request with no chain at all being treated as approved.
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM request_approvals
                WHERE request_id = ?
                  AND status = 'approved'
                  AND (entity_type IS NULL OR entity_type = '' OR entity_type = 'REQUEST')
            ");
            $stmt->execute([$requestId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The single question the refactored engine asks instead of "is the
     * request at exactly stage X?": may this department act now?
     *
     * In LEGACY_MODE this always returns false, leaving every existing
     * sequential gate fully in force.
     *
     * @param array<string,mixed> $request
     */
    public static function allowsParallelAction(PDO $pdo, array $request, string $action = ''): bool
    {
        if (!self::isParallelRequest($pdo, $request)) {
            return false;
        }
        return self::isApprovedForProcurement($pdo, $request);
    }

    /**
     * Load a request row (including workflow_mode when the column exists) by id.
     *
     * @return array<string,mixed>|null
     */
    public static function loadRequest(PDO $pdo, int $requestId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM procurement_requests WHERE request_id = ? LIMIT 1');
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
