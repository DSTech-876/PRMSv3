<?php
/**
 * Acting Role Helpers
 * 
 * Provides functions to:
 *  - Get a user's active acting role assignments
 *  - Switch the session to an acting role
 *  - Revert to the primary role
 *  - Check if currently acting
 */

/**
 * Get all currently valid acting role assignments for a user.
 * Returns array of ['id', 'acting_role_id', 'role_name', 'reason', 'ends_at']
 */
function getActingRoles(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT ar.id, ar.acting_role_id, r.name AS role_name,
               ar.reason, ar.ends_at
        FROM acting_roles ar
        JOIN roles r ON ar.acting_role_id = r.id
        WHERE ar.user_id = ?
          AND ar.is_active = 1
          AND ar.starts_at <= NOW()
          AND (ar.ends_at IS NULL OR ar.ends_at >= NOW())
        ORDER BY r.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Switch the current session to an acting role.
 * Returns true on success, false if not authorised.
 */
function switchToActingRole(PDO $pdo, int $userId, int $actingRoleId): bool
{
    // Verify the assignment is valid
    $stmt = $pdo->prepare("
        SELECT ar.id, r.name AS role_name
        FROM acting_roles ar
        JOIN roles r ON ar.acting_role_id = r.id
        WHERE ar.user_id = ?
          AND ar.acting_role_id = ?
          AND ar.is_active = 1
          AND ar.starts_at <= NOW()
          AND (ar.ends_at IS NULL OR ar.ends_at >= NOW())
        LIMIT 1
    ");
    $stmt->execute([$userId, $actingRoleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;

    // Save primary role on first switch (so we can revert)
    if (!isset($_SESSION['primary_role_id'])) {
        $_SESSION['primary_role_id']   = $_SESSION['role_id'];
        $_SESSION['primary_role_name'] = $_SESSION['role_name'];
    }

    $previousRoleId = $_SESSION['role_id'];

    // Update session to the acting role
    $actingRoleName = canonicalizeRoleName($row['role_name']);
    $_SESSION['role_id']   = $actingRoleId;
    $_SESSION['role_name'] = $actingRoleName;
    $_SESSION['role']      = $actingRoleName;
    $_SESSION['is_acting'] = true;
    $_SESSION['acting_assignment_id'] = $row['id'];

    // Audit log
    logRoleSwitch($pdo, $userId, $previousRoleId, $actingRoleId, true);

    return true;
}

/**
 * Revert session back to the user's primary role.
 */
function revertToPrimaryRole(PDO $pdo, int $userId): void
{
    if (!isset($_SESSION['primary_role_id'])) return;

    $previousRoleId = $_SESSION['role_id'];

    $_SESSION['role_id']   = $_SESSION['primary_role_id'];
    $_SESSION['role_name'] = canonicalizeRoleName($_SESSION['primary_role_name']);
    $_SESSION['role']      = $_SESSION['role_name'];

    unset(
        $_SESSION['is_acting'],
        $_SESSION['acting_assignment_id'],
        $_SESSION['primary_role_id'],
        $_SESSION['primary_role_name']
    );

    // Audit log
    logRoleSwitch($pdo, $userId, $previousRoleId, $_SESSION['role_id'], false);
}

/**
 * Check if the current session is in acting mode.
 */
function isActingRole(): bool
{
    return !empty($_SESSION['is_acting']);
}

/**
 * Get the primary role name (even when acting).
 */
function getPrimaryRoleName(): string
{
    return $_SESSION['primary_role_name'] ?? $_SESSION['role_name'] ?? '';
}

/**
 * Write an audit entry for role switches.
 */
function logRoleSwitch(PDO $pdo, int $userId, int $fromRoleId, int $toRoleId, bool $isActing): void
{
    $stmt = $pdo->prepare("
        INSERT INTO acting_role_log (user_id, switched_from_role_id, switched_to_role_id, is_acting, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $fromRoleId,
        $toRoleId,
        $isActing ? 1 : 0,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}
