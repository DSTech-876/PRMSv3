<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for CSRF protection, audit logging and notifications on
 * the fund verification and "start procurement" endpoints.
 */
final class FundVerificationAndProcurementStageTest extends TestCase
{
    private function readFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path, "Missing file: {$relativePath}");

        return (string)file_get_contents($path);
    }

    public function testVerifyFundsRequiresCsrfTokenOnPost(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString("requireCsrfToken('/procurement/verify_funds.php?id='", $contents);
        $this->assertMatchesRegularExpression(
            '/REQUEST_METHOD.{0,80}POST.{0,120}requireCsrfToken/s',
            $contents,
            'CSRF validation must run at the start of the POST handler.'
        );
    }

    public function testVerifyFundsFormEmitsCsrfToken(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString('name="csrf_token"', $contents);
        $this->assertStringContainsString('ensureCsrfToken()', $contents);
    }

    public function testVerifyFundsNotifiesAfterCommit(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString('notifyFundVerificationRecorded(', $contents);
        $this->assertMatchesRegularExpression(
            '/\$pdo->commit\(\);.{0,400}notifyFundVerificationRecorded\(/s',
            $contents,
            'Notifications must be dispatched only after the transaction commits.'
        );
    }

    public function testVerifyFundsAdvancesWorkflowStatus(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString('getNextStatusAfterApproval($pdo, $request_id, \'Finance Officer\')', $contents);
        $this->assertStringContainsString('enforceTransition($request, $nextStatus);', $contents);
        $this->assertMatchesRegularExpression(
            '/UPDATE procurement_requests\s+SET status = \?,\s+funds_available = 1/s',
            $contents,
            'Verifying funds must change the request status, not only the funds_available flag.'
        );
        $this->assertStringContainsString('logProcurementVisibilityGranted($pdo, $request_id, $nextStatus', $contents);
    }

    public function testVerifyFundsClosesPendingFinanceApproval(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString("AND role = 'Finance Officer'", $contents);
        $this->assertMatchesRegularExpression(
            '/UPDATE request_approvals\s+SET status = \'approved\'/s',
            $contents,
            'The pending Finance Officer approval must be closed so the pipeline advances.'
        );
    }

    public function testVerifyFundsRedirectsToRequestViewNotCommitmentUpload(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringNotContainsString('/commitments/upload.php?request_id=', $contents);
        $this->assertMatchesRegularExpression(
            "/'Funds Verified',.{0,200}'\\/procurement\\/view\\.php\\?id=' \\. \\\$request_id,\\s+'success'/s",
            $contents,
            'A successful verification must return the actor to the request view.'
        );
    }

    public function testVerifyFundsAllowsDirectorAccountsAndFinance(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString("\$ALTERNATE_PERMISSIONS = ['approve_request'];", $contents);
        $this->assertStringContainsString("canApproveStage(\$actorRole, 'Finance Officer', 0.0)", $contents);

        $guard = $this->readFile('config/page_guard.php');
        $this->assertStringContainsString('$ALTERNATE_PERMISSIONS', $guard);
        $this->assertStringContainsString('has_permission($alternatePermission)', $guard);
    }

    public function testVerifyFundsSkipsRfqBypassedRequests(): void
    {
        $contents = $this->readFile('procurement/verify_funds.php');

        $this->assertStringContainsString('isSkipRfqPath(', $contents);
    }

    public function testRequestViewExposesVerifyFundsLinkForFinanceStageRoles(): void
    {
        $contents = $this->readFile('procurement/view.php');

        $this->assertStringContainsString("'Director Accounts & Finance', 'Admin', 'SuperAdmin'];", $contents);
        $this->assertStringContainsString('/procurement/verify_funds.php?id=', $contents);
        $this->assertStringContainsString('$showVerifyFundsLink', $contents);
    }

    public function testDirectorFinanceDashboardExposesFundVerificationAction(): void
    {
        $contents = $this->readFile('services/DashboardActionService.php');

        $this->assertStringContainsString("'verify_funds_director'", $contents);
        $this->assertMatchesRegularExpression(
            "/verify_funds_director.{0,200}\\/procurement\\/verify_funds\\.php\\?id=\\{request_id\\}/s",
            $contents,
            'The Director Accounts & Finance dashboard must offer an actionable fund verification link.'
        );
    }

    public function testStartProcurementWritesAuditAndTimeline(): void
    {
        $contents = $this->readFile('procurement/start_procurement.php');

        $this->assertStringContainsString("logAudit(\$pdo, 'procurement_requests', \$id, 'STATUS_CHANGE'", $contents);
        $this->assertStringContainsString("logRequestTimeline(\$pdo, \$id, 'PROCUREMENT_STAGE'", $contents);
        $this->assertStringContainsString('logProcurementVisibilityGranted($pdo, $id, ', $contents);
    }

    public function testStartProcurementNotifiesStakeholders(): void
    {
        $contents = $this->readFile('procurement/start_procurement.php');

        $this->assertStringContainsString("require_once \$_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';", $contents);
        $this->assertStringContainsString('notifyProcurementStageStarted($id', $contents);
    }

    public function testNotificationHelpersExist(): void
    {
        $contents = $this->readFile('config/notifications.php');

        $this->assertStringContainsString('function notifyFundVerificationRecorded(', $contents);
        $this->assertStringContainsString('function notifyProcurementStageStarted(', $contents);
        $this->assertStringContainsString('shouldSendProcurementNotificationForRequest($requestId)', $contents);
    }
}
