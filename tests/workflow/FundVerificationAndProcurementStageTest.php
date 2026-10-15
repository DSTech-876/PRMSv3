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
