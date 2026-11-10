<?php
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/page_guard.php";

/*
|--------------------------------------------------------------------------
| SOP-Aligned Dashboard Router
|--------------------------------------------------------------------------
*/


$role = $_SESSION['role_name'] ?? '';

switch ($role) {
    // System Control
    case 'SuperAdmin':
    case 'Admin':
        header("Location: /dashboard/admin.php"); exit;

    // Final Authority
    case 'Deputy Government Chemist':
        header("Location: /dashboard/gc.php"); exit;

    // Recommendation Authority
    case 'Procurement Committee':
        header("Location: /dashboard/committee.php"); exit;

    // Operational Roles
    case 'Procurement Officer':
        header("Location: /dashboard/procurement.php"); exit;
    case 'Evaluation Committee Member':
        header("Location: /dashboard/evaluation.php"); exit;
    case 'Finance Officer':
        header("Location: /dashboard/finance.php"); exit;
    case 'HOD':
        header("Location: /dashboard/hod.php"); exit;

    // New: Director HRM&A
    case 'Director HRM&A':
        header("Location: /dashboard/director_hrma.php"); exit;

    // New: Director Procurement
    case 'Director Procurement':
        header("Location: /dashboard/director_procurement.php"); exit;

    // New: Director Accounts & Finance
    case 'Director Accounts & Finance':
        header("Location: /dashboard/director_accounts_finance.php"); exit;

    // New: Property Management Officer
    case 'Property Management Officer':
        header("Location: /dashboard/property_management_officer.php"); exit;

    // New: Requestor
    case 'Requestor':
        header("Location: /dashboard/requestor.php"); exit;

    // Read Only
    case 'Viewer':
    default:
        // An unrecognized role_name falls through to the read-only Viewer
        // dashboard. This is almost always a sign that a role was renamed
        // or created with wording that doesn't match the literal case
        // labels above (role names are normalized at login via
        // canonicalizeRoleName(), but new/legacy sessions or admin-created
        // roles outside that alias map will still land here). Log it so the
        // mismatch is diagnosable instead of silently granting read-only
        // access to a role that should have an action dashboard.
        if ($role !== '' && $role !== 'Viewer') {
            error_log(sprintf(
                'dashboard/index.php: unmapped role_name "%s" (user_id=%s) routed to viewer.php as fallback',
                $role,
                $_SESSION['user_id'] ?? 'unknown'
            ));
        }
        header("Location: /dashboard/viewer.php"); exit;
}
