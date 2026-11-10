<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/helper.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';

/**
 * Regression coverage for the "Director of Finance & Accounts" permission
 * bug: a role created/renamed with different wording than the hard-coded
 * 'Director Accounts & Finance' string literal used throughout the codebase
 * (dashboard routing, canApproveStage() equivalence, approval gates) caused
 * authorized users to be denied finance-stage actions despite having the
 * correct permission grant in role_permissions.
 *
 * canonicalizeRoleName() is applied once at session role_name assignment
 * (login + acting-role switch) so every downstream exact-string comparison
 * keeps working without being individually patched.
 */
final class RoleNameCanonicalizationTest extends PHPUnit\Framework\TestCase
{
    public function testKnownAliasIsNormalizedToCanonicalName(): void
    {
        $this->assertSame(
            'Director Accounts & Finance',
            canonicalizeRoleName('Director of Finance & Accounts')
        );
    }

    public function testNormalizationIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertSame(
            'Director Accounts & Finance',
            canonicalizeRoleName('  director   OF finance & accounts  ')
        );
    }

    public function testUnrelatedRoleNamesPassThroughUnchanged(): void
    {
        $this->assertSame('Finance Officer', canonicalizeRoleName('Finance Officer'));
        $this->assertSame('HOD', canonicalizeRoleName('HOD'));
    }

    public function testNullRoleNamePassesThroughAsNull(): void
    {
        $this->assertNull(canonicalizeRoleName(null));
    }

    public function testCanonicalizedDirectorRoleCanPerformFinanceStageTransition(): void
    {
        $_SESSION['role_name'] = canonicalizeRoleName('Director of Finance & Accounts');

        $request = [
            'request_id' => 99,
            'status' => 'HOD_APPROVED',
            'estimated_value' => 10000,
        ];

        // Should not call pop()/exit — reaching this line means the
        // normalized role was authorized for the Finance Officer-owned
        // FUNDS_VERIFIED stage via the existing equivalentRoles map.
        enforceTransition($request, 'FUNDS_VERIFIED');
        $this->assertTrue(true);
    }
}
