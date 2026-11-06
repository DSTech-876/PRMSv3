<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/helper.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';

/**
 * Regression test for PR #244 review feedback: procurement/view.php offers the
 * Finance Officer approval action to a Director (via canApproveStage()
 * equivalence), so enforceTransition() must honor that same equivalence
 * instead of requiring an exact stageOwner() role match.
 */
final class EnforceTransitionRoleEquivalenceTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        $_SESSION = $_SESSION ?? [];
    }

    public function testDirectorAccountsAndFinanceCanPerformFundsVerifiedTransition(): void
    {
        $_SESSION['role_name'] = 'Director Accounts & Finance';

        $request = [
            'request_id' => 1,
            'status' => 'HOD_APPROVED',
            'estimated_value' => 10000,
        ];

        // Should not call pop()/exit — reaching this line means the Director
        // was authorized for the Finance Officer-owned FUNDS_VERIFIED stage.
        enforceTransition($request, 'FUNDS_VERIFIED');
        $this->assertTrue(true);
    }

    public function testFinanceOfficerCanStillPerformFundsVerifiedTransition(): void
    {
        $_SESSION['role_name'] = 'Finance Officer';

        $request = [
            'request_id' => 2,
            'status' => 'HOD_APPROVED',
            'estimated_value' => 10000,
        ];

        enforceTransition($request, 'FUNDS_VERIFIED');
        $this->assertTrue(true);
    }
}
