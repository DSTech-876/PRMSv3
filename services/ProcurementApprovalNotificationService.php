<?php
/**
 * ProcurementApprovalNotificationService
 * ======================================
 * Broadcasts a single, consistent "approval granted" notification to every
 * user involved in the procurement lifecycle immediately after the FINAL
 * approval stage (Branch Head) is completed.
 *
 * Channels
 *   * In-app notification  (user_notifications → Notification Center + bell)
 *   * Dashboard alert      (same row; dashboards read user_notifications)
 *   * Email                (only when the global email toggle is enabled)
 *
 * Audience
 *   * Procurement Officers / Procurement Managers
 *   * Accounts Officers
 *   * Finance personnel (Finance Officer, Director Accounts & Finance)
 *   * Inventory Officers / Property Management Officers
 *   * Workflow participants: the requestor, every approver who acted on the
 *     chain, and every role still listed on the request's approval chain.
 *
 * The notification tells recipients that approval has been granted and that
 * they may begin preparing and uploading the required procurement documents.
 */

/* Callers always have the database handle loaded (page_guard / config/db.php);
   this service resolves it from its argument or the global handle. */
require_once dirname(__DIR__) . '/services/CurrencyService.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

class ProcurementApprovalNotificationService
{
    /** Notification type recorded against each broadcast row. */
    public const NOTIFICATION_TYPE = 'procurement_approved';

    /**
     * Roles that participate in the procurement lifecycle after approval.
     * Roles absent from a given deployment simply resolve to zero users.
     */
    public const LIFECYCLE_ROLES = [
        'Procurement Officer',
        'Procurement Manager',
        'Accounts Officer',
        'Finance Officer',
        'Director Accounts & Finance',
        'Inventory Officer',
        'Property Management Officer',
    ];

    /**
     * Entry point: notify everyone involved that final approval was granted.
     *
     * @param int         $requestId Procurement request id
     * @param string|null $status    Status the request moved into
     * @param PDO|null    $pdo       Optional handle (defaults to the global)
     * @return array{notified:int,emailed:int,recipients:int[],skipped:string|null}
     */
    public static function notifyApprovalGranted(int $requestId, ?string $status = null, ?PDO $pdo = null): array
    {
        $result = ['notified' => 0, 'emailed' => 0, 'recipients' => [], 'skipped' => null];

        $handle = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if (!$handle instanceof PDO || $requestId <= 0) {
            $result['skipped'] = 'no_database';
            return $result;
        }

        $request = self::loadRequest($handle, $requestId);
        if ($request === null) {
            $result['skipped'] = 'request_not_found';
            return $result;
        }

        $status = $status !== null && trim($status) !== ''
            ? trim($status)
            : (string)($request['status'] ?? '');

        $recipients = self::resolveRecipients($handle, $request);
        if (empty($recipients)) {
            $result['skipped'] = 'no_recipients';
            return $result;
        }

        $content = self::buildContent($request, $status, $handle);

        foreach ($recipients as $userId => $recipient) {
            $created = self::createInAppNotification($handle, (int)$userId, $request, $content);
            if ($created) {
                $result['notified']++;
                $result['recipients'][] = (int)$userId;
            }

            if (self::emailEnabled($handle) && !empty($recipient['email'])) {
                if (self::sendEmail((string)$recipient['email'], $content)) {
                    $result['emailed']++;
                }
            }
        }

        return $result;
    }

    /**
     * True when the request has no approval stages left pending, i.e. the
     * approval just recorded was the final one.
     */
    public static function isFinalApproval(PDO $pdo, int $requestId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM request_approvals
                  WHERE request_id = ? AND status = 'pending'"
            );
            $stmt->execute([$requestId]);
            return ((int)$stmt->fetchColumn()) === 0;
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::isFinalApproval error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load the request with the display fields required by the notification.
     *
     * @return array<string,mixed>|null
     */
    private static function loadRequest(PDO $pdo, int $requestId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT pr.request_id, pr.request_number, pr.description, pr.status,
                        pr.estimated_value, pr.currency, pr.request_type,
                        pr.branch_id, pr.created_by, pr.approved_by, pr.approved_at,
                        b.branch_name,
                        u.full_name AS requestor_name,
                        a.full_name AS approver_name
                   FROM procurement_requests pr
                   LEFT JOIN branches b ON b.branch_id = pr.branch_id
                   LEFT JOIN users u    ON u.user_id   = pr.created_by
                   LEFT JOIN users a    ON a.user_id   = pr.approved_by
                  WHERE pr.request_id = ?
                  LIMIT 1"
            );
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::loadRequest error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve every distinct recipient, keyed by user_id.
     *
     * @return array<int,array{user_id:int,full_name:string,email:string,reason:string}>
     */
    public static function resolveRecipients(PDO $pdo, array $request): array
    {
        $recipients = [];

        // 1. Lifecycle role holders
        foreach (self::LIFECYCLE_ROLES as $role) {
            foreach (self::usersByRole($pdo, $role) as $user) {
                self::addRecipient($recipients, $user, 'role:' . $role);
            }
        }

        // 2. Workflow participants on this specific request
        foreach (self::workflowParticipants($pdo, $request) as $user) {
            self::addRecipient($recipients, $user, (string)($user['reason'] ?? 'workflow_participant'));
        }

        return $recipients;
    }

    /**
     * Active users holding a role, resolved through both the single-role
     * column and the multi-role assignment table when it exists.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function usersByRole(PDO $pdo, string $roleName): array
    {
        $users = [];

        try {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT u.user_id, u.full_name, u.email
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name = ? AND u.is_active = 1'
            );
            $stmt->execute([$roleName]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::usersByRole error: ' . $e->getMessage());
        }

        // Multi-role assignments are optional; ignore if the table is absent.
        try {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT u.user_id, u.full_name, u.email
                   FROM users u
                   JOIN user_roles ur ON ur.user_id = u.user_id
                   JOIN roles r       ON r.id = ur.role_id
                  WHERE r.name = ? AND u.is_active = 1'
            );
            $stmt->execute([$roleName]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $users[] = $row;
            }
        } catch (Throwable $e) {
            // user_roles not present in this deployment — single-role only.
        }

        return $users;
    }

    /**
     * The requestor plus everyone who has acted on, or is assigned to, the
     * approval chain for this request.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function workflowParticipants(PDO $pdo, array $request): array
    {
        $participants = [];

        $requestId = (int)($request['request_id'] ?? 0);

        // Requestor
        $creatorId = (int)($request['created_by'] ?? 0);
        if ($creatorId > 0) {
            $user = self::loadUser($pdo, $creatorId);
            if ($user !== null) {
                $user['reason'] = 'requestor';
                $participants[] = $user;
            }
        }

        // Users who already acted on the approval chain
        try {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT u.user_id, u.full_name, u.email
                   FROM request_approvals ra
                   JOIN users u ON u.user_id = ra.approved_by
                  WHERE ra.request_id = ? AND ra.approved_by IS NOT NULL AND u.is_active = 1'
            );
            $stmt->execute([$requestId]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $row['reason'] = 'approver';
                $participants[] = $row;
            }
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::workflowParticipants error: ' . $e->getMessage());
        }

        // Roles named on the approval chain (covers assigned-but-not-yet-acted)
        try {
            $stmt = $pdo->prepare('SELECT DISTINCT role FROM request_approvals WHERE request_id = ?');
            $stmt->execute([$requestId]);
            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $role) {
                $role = trim((string)$role);
                if ($role === '') {
                    continue;
                }
                foreach (self::usersByRole($pdo, $role) as $row) {
                    $row['reason'] = 'chain_role:' . $role;
                    $participants[] = $row;
                }
            }
        } catch (Throwable $e) {
            // Chain roles are a best-effort enrichment.
        }

        return $participants;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadUser(PDO $pdo, int $userId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id, full_name, email FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Add a recipient, de-duplicating by user_id. The first reason wins.
     */
    private static function addRecipient(array &$recipients, array $user, string $reason): void
    {
        $userId = (int)($user['user_id'] ?? 0);
        if ($userId <= 0 || isset($recipients[$userId])) {
            return;
        }
        $recipients[$userId] = [
            'user_id'   => $userId,
            'full_name' => (string)($user['full_name'] ?? ''),
            'email'     => (string)($user['email'] ?? ''),
            'reason'    => $reason,
        ];
    }

    /**
     * Build the shared notification content.
     *
     * Content includes: request number, title, requesting department,
     * approval date, current status, and a link to the request.
     *
     * @return array<string,string>
     */
    public static function buildContent(array $request, string $status, ?PDO $pdo = null): array
    {
        $requestNumber = (string)($request['request_number'] ?? ('#' . (int)($request['request_id'] ?? 0)));
        $title         = trim((string)($request['description'] ?? ''));
        if ($title === '') {
            $title = 'Procurement Request ' . $requestNumber;
        }
        $department = trim((string)($request['branch_name'] ?? '')) ?: 'Not specified';

        $approvedAtRaw = (string)($request['approved_at'] ?? '');
        $approvalDate  = $approvedAtRaw !== ''
            ? date('d M Y, g:i A', strtotime($approvedAtRaw))
            : date('d M Y, g:i A');

        $statusLabel = self::statusLabel($status);
        $link        = '/procurement/view.php?id=' . (int)($request['request_id'] ?? 0);

        $amount = CurrencyService::formatFull(
            $request['estimated_value'] ?? 0,
            $request['currency'] ?? null,
            $pdo
        );

        $plainTitle = 'Approved: ' . $requestNumber . ' — ready for procurement documents';

        $bodyLines = [
            'Final approval has been granted for this procurement request.',
            '',
            'Request Number: ' . $requestNumber,
            'Request Title: ' . $title,
            'Requesting Department: ' . $department,
            'Estimated Value: ' . $amount,
            'Approval Date: ' . $approvalDate,
            'Current Status: ' . $statusLabel,
            '',
            'You may now begin preparing and uploading the required procurement documents.',
        ];

        return [
            'title'           => $plainTitle,
            'body'            => implode("\n", $bodyLines),
            'request_number'  => $requestNumber,
            'request_title'   => $title,
            'department'      => $department,
            'approval_date'   => $approvalDate,
            'status_label'    => $statusLabel,
            'amount'          => $amount,
            'link'            => $link,
            'subject'         => 'Procurement Approved: ' . $requestNumber,
        ];
    }

    private static function statusLabel(string $status): string
    {
        $status = trim($status);
        if ($status === '') {
            return 'Approved';
        }
        if (function_exists('statusLabel')) {
            $label = statusLabel($status);
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }
        return ucwords(strtolower(str_replace('_', ' ', $status)));
    }

    /**
     * Persist the in-app / dashboard notification row.
     */
    private static function createInAppNotification(PDO $pdo, int $userId, array $request, array $content): bool
    {
        // NotificationService reads the global handle; make sure the handle we
        // were given is the one used when a caller passed an explicit PDO.
        $previous = $GLOBALS['pdo'] ?? null;
        $GLOBALS['pdo'] = $pdo;

        try {
            return self::insertNotification($pdo, $userId, $request, $content);
        } finally {
            if ($previous !== null) {
                $GLOBALS['pdo'] = $previous;
            }
        }
    }

    private static function insertNotification(PDO $pdo, int $userId, array $request, array $content): bool
    {
        try {
            // Skip an identical unread broadcast for the same recipient.
            $dup = $pdo->prepare(
                'SELECT id FROM user_notifications
                  WHERE user_id = ? AND type = ? AND request_id = ? AND is_read = 0
                  LIMIT 1'
            );
            $dup->execute([$userId, self::NOTIFICATION_TYPE, (int)($request['request_id'] ?? 0)]);
            if ($dup->fetchColumn()) {
                return false;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO user_notifications
                     (user_id, request_id, type, title, body, request_ref,
                      action_url, stage, requestor_name, priority)
                 VALUES (:uid, :rid, :type, :title, :body, :ref, :url, :stage, :rname, :prio)'
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':rid'   => (int)($request['request_id'] ?? 0) ?: null,
                ':type'  => self::NOTIFICATION_TYPE,
                ':title' => $content['title'],
                ':body'  => $content['body'],
                ':ref'   => $content['request_number'],
                ':url'   => $content['link'],
                ':stage' => $content['status_label'],
                ':rname' => (string)($request['requestor_name'] ?? ''),
                ':prio'  => 'high',
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::insertNotification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Honour the global notification/email toggles.
     */
    private static function emailEnabled(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
            $stmt->execute(['enable_notifications']);
            $value = $stmt->fetchColumn();
            return $value === false ? true : (bool)(int)$value;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function sendEmail(string $to, array $content): bool
    {
        if (!function_exists('sendMail')) {
            $mailer = dirname(__DIR__) . '/config/mailer.php';
            if (!file_exists($mailer)) {
                return false;
            }
            require_once $mailer;
        }
        if (!function_exists('sendMail')) {
            return false;
        }

        try {
            return (bool)sendMail($to, $content['subject'], self::buildEmailHtml($content));
        } catch (Throwable $e) {
            error_log('ProcurementApprovalNotificationService::sendEmail error: ' . $e->getMessage());
            return false;
        }
    }

    public static function buildEmailHtml(array $content): string
    {
        $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $appUrl = function_exists('getAppUrl') ? getAppUrl() : '';
        $link   = $appUrl . $content['link'];

        $rows = [
            'Request Number'        => $content['request_number'],
            'Request Title'         => $content['request_title'],
            'Requesting Department' => $content['department'],
            'Estimated Value'       => $content['amount'],
            'Approval Date'         => $content['approval_date'],
            'Current Status'        => $content['status_label'],
        ];

        $rowHtml = '';
        foreach ($rows as $label => $value) {
            $rowHtml .= '<tr>'
                . '<td style="padding:6px 12px;color:#555;font-weight:600;">' . $esc($label) . '</td>'
                . '<td style="padding:6px 12px;color:#111;">' . $esc($value) . '</td>'
                . '</tr>';
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;">'
            . '<h2 style="color:#157347;margin-bottom:4px;">Procurement Request Approved</h2>'
            . '<p>Final approval has been granted. You may now begin preparing and uploading '
            . 'the required procurement documents.</p>'
            . '<table style="border-collapse:collapse;margin:16px 0;">' . $rowHtml . '</table>'
            . '<p><a href="' . $esc($link) . '" '
            . 'style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:4px;'
            . 'text-decoration:none;display:inline-block;">View Request</a></p>'
            . '<p style="color:#777;font-size:12px;margin-top:20px;">'
            . 'This is an automated message from the DGC Procurement &amp; Inventory System.</p>'
            . '</div>';
    }
}
