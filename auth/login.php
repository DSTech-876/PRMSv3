<?php

require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/policy.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

$error = "";

if (isset($_GET['timeout'])) {
    $error = "Session expired due to inactivity. Please log in again.";
}

if (isset($_GET['security'])) {
    $error = "Your session ended for security reasons. Please log in again.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = strtolower(trim($_POST['email'] ?? ''));

    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // 🚫 Locked account → stop immediately
    if ($user && $user['lock_until'] && strtotime($user['lock_until']) > time()) {
        modalPop(
    "Account Locked",
    "Too many failed attempts. Please try again later.",
    "",
    "error"
);

    }

    // ✅ Valid login
    elseif ($user && password_verify($password, $user['password_hash'])) {

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
 $stmt = $pdo->prepare("
    SELECT name
    FROM roles
    WHERE id = ?
");
$stmt->execute([$user['role_id']]);
$role = canonicalizeRoleName($stmt->fetchColumn());

$_SESSION['role_id'] = $user['role_id'];
$_SESSION['role_name'] = $role;
$_SESSION['role'] = $role;

        $_SESSION['name']    = $user['full_name'];
        $_SESSION['full_name'] = $user['full_name'];
        
if (strtolower($_SESSION['role_name']) === 'admin') {
    $_SESSION['show_admin_welcome'] = true;
}

        // Session binding
        $_SESSION['ip_address']  = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent']  = $_SERVER['HTTP_USER_AGENT'];

        // Reset failed attempts
        $pdo->prepare("
            UPDATE users
            SET failed_attempts = 0,
                lock_until = NULL
            WHERE user_id = ?
        ")->execute([$user['user_id']]);

        // Force password change
        if ($user['must_change_password']) {
            $_SESSION['force_pw_change'] = true;
            header("Location: /auth/change_password.php");
            exit;
        }

        // Set login notification for pop-up
        $_SESSION['login_notification'] = 'Welcome, ' . htmlspecialchars($user['full_name']) . '! You have successfully logged in.';
        header("Location: /dashboard/index.php");
        exit;
    }

    // ❌ Failed login
    else {

        if ($user) {
            $attempts = min($user['failed_attempts'] + 1, 5);
            $lockUntil = null;

            if ($attempts >= 5) {
                $lockUntil = date("Y-m-d H:i:s", strtotime("+15 minutes"));

                $pdo->prepare("
                    INSERT INTO audit_log
                    (table_name, record_id, action, changed_by, notes)
                    VALUES ('users', ?, 'LOCKOUT', ?, 'Account locked after failed attempts')
                ")->execute([$user['user_id'], $user['user_id']]);
            }

            $pdo->prepare("
                UPDATE users
                SET failed_attempts = ?, lock_until = ?
                WHERE user_id = ?
            ")->execute([$attempts, $lockUntil, $user['user_id']]);
        }

        modalPop(
    "Login Failed",
    "Invalid login credentials.",
    "",
    "error"
);

    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login | DGC Procurement</title>
<link rel="icon" type="image/png" href="/logo/cropped-Logo.png">
<link rel="shortcut icon" type="image/png" href="/logo/cropped-Logo.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root {
    --dgc-green: #0b5e2b;
    --dgc-green-light: #0d7a38;
    --dgc-gold: #c9a227;
    --dgc-gold-light: #e4c44a;
  }

  * { box-sizing: border-box; }

  html, body {
    height: 100%;
    margin: 0;
    font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
  }

  /* ── Split layout ── */
  .login-wrapper {
    display: flex;
    min-height: 100vh;
  }

  /* Left hero panel */
  .hero-panel {
    flex: 1 1 50%;
    background: linear-gradient(160deg, var(--dgc-green) 0%, #064a20 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 2rem;
    position: relative;
    overflow: hidden;
    color: #fff;
  }

  .hero-panel::before {
    content: '';
    position: absolute;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(201, 162, 39, 0.08);
    top: -120px;
    right: -120px;
  }

  .hero-panel::after {
    content: '';
    position: absolute;
    width: 350px;
    height: 350px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    bottom: -80px;
    left: -80px;
  }

  .hero-panel .hero-content {
    position: relative;
    z-index: 1;
    text-align: center;
    max-width: 380px;
  }

  .hero-panel .hero-logo {
    width: 110px;
    height: 110px;
    object-fit: contain;
    margin-bottom: 1.5rem;
    filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
  }

  .hero-panel h1 {
    font-size: 1.6rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
  }

  .hero-panel .hero-sub {
    font-size: 0.95rem;
    opacity: 0.8;
    line-height: 1.5;
  }

  .hero-panel .hero-badge {
    display: inline-block;
    background: var(--dgc-gold);
    color: var(--dgc-green);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    padding: 0.3rem 1rem;
    border-radius: 20px;
    margin-top: 1.5rem;
  }

  /* Right form panel */
  .form-panel {
    flex: 1 1 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    padding: 2rem;
  }

  .login-card {
    width: 100%;
    max-width: 420px;
  }

  .login-card .card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.08);
    overflow: hidden;
  }

  .login-card .card-body {
    padding: 2.5rem 2rem 2rem;
  }

  .login-card .card-header-bar {
    height: 5px;
    background: linear-gradient(90deg, var(--dgc-green), var(--dgc-gold));
  }

  .login-card h4 {
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 0.25rem;
  }

  .login-card .subtitle {
    color: #6c757d;
    font-size: 0.9rem;
    margin-bottom: 1.75rem;
  }

  /* Form inputs */
  .form-floating .form-control {
    border-radius: 10px;
    border: 1.5px solid #dee2e6;
    padding-left: 2.8rem;
    height: 3.2rem;
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
  }

  .form-floating .form-control:focus {
    border-color: var(--dgc-green);
    box-shadow: 0 0 0 3px rgba(11, 94, 43, 0.12);
  }

  .form-floating label {
    padding-left: 2.8rem;
    color: #999;
  }

  .input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    font-size: 1.1rem;
    z-index: 5;
    pointer-events: none;
    transition: color 0.2s;
  }

  .form-floating:focus-within .input-icon {
    color: var(--dgc-green);
  }

  .pw-toggle {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #adb5bd;
    font-size: 1.1rem;
    z-index: 5;
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
  }

  .pw-toggle:hover { color: var(--dgc-green); }

  /* Submit button */
  .btn-login {
    background: linear-gradient(135deg, var(--dgc-green), var(--dgc-green-light));
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1rem;
    padding: 0.75rem;
    color: #fff;
    transition: transform 0.15s, box-shadow 0.15s;
  }

  .btn-login:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(11, 94, 43, 0.3);
    color: #fff;
  }

  .btn-login:active {
    transform: translateY(0);
  }

  /* Links */
  .auth-link {
    color: var(--dgc-green);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.875rem;
    transition: color 0.2s;
  }

  .auth-link:hover { color: var(--dgc-gold); }

  /* Alert */
  .alert-login {
    border-radius: 10px;
    border-left: 4px solid #dc3545;
    font-size: 0.9rem;
  }

  /* Footer */
  .login-footer {
    text-align: center;
    margin-top: 1.25rem;
    font-size: 0.8rem;
    color: #adb5bd;
  }

  /* ── Responsive ── */
  @media (max-width: 991.98px) {
    .hero-panel { display: none; }
    .form-panel {
      flex: 1;
      background: linear-gradient(160deg, #f8f9fa 60%, #e9ecef 100%);
    }
  }

  /* Fade-in animation */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .login-card { animation: fadeUp 0.5s ease-out; }
</style>
</head>

<body>

<div class="login-wrapper">

  <!-- Hero Panel (left) -->
  <div class="hero-panel d-none d-lg-flex">
    <div class="hero-content">
      <img src="/logo/cropped-Logo.png"
           alt="Government Chemist Logo"
           class="hero-logo">
      <h1>DGC Inventory, Procurement & Assets Management System Portal</h1>
      <p class="hero-sub">
        Streamlined inventory, procurement & assets management for the Department of the Government Chemist.
      </p>
      <span class="hero-badge">Secure &bull; Audited &bull; Compliant</span>
    </div>
  </div>

  <!-- Form Panel (right) -->
  <div class="form-panel">
    <div class="login-card">

      <div class="card">
        <div class="card-header-bar"></div>
        <div class="card-body">

          <!-- Mobile logo (shown only on small screens) -->
          <div class="text-center d-lg-none mb-3">
            <img src="/logo/cropped-Logo.png"
                 alt="Government Chemist Logo"
                 style="height:64px;" class="mb-2">
          </div>

          <h4>Welcome back</h4>
          <p class="subtitle">Sign in to your account to continue</p>

          <!-- Error Message -->
          <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-login d-flex align-items-center gap-2" role="alert">
              <i class="bi bi-exclamation-triangle-fill"></i>
              <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <!-- Login Form -->
          <form method="post" novalidate autocomplete="off">

            <div class="form-floating position-relative mb-3">
              <i class="bi bi-envelope input-icon"></i>
              <input
                type="email"
                name="email"
                id="loginEmail"
                class="form-control"
                placeholder="your.name@moh.gov.jm"
                required
                autofocus
              >
              <label for="loginEmail">Email address</label>
            </div>

            <div class="form-floating position-relative mb-2">
              <i class="bi bi-lock input-icon"></i>
              <input
                type="password"
                name="password"
                id="loginPassword"
                class="form-control"
                placeholder="Password"
                required
              >
              <label for="loginPassword">Password</label>
              <button type="button"
                      class="pw-toggle"
                      onclick="togglePw()"
                      aria-label="Toggle password visibility">
                <i class="bi bi-eye" id="pwIcon"></i>
              </button>
            </div>

            <div class="d-flex justify-content-end mb-3">
              <a href="/auth/forgot_password.php" class="auth-link">
                Forgot password?
              </a>
            </div>

            <button type="submit" class="btn btn-login w-100">
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>

          </form>

        </div>
      </div>

      <div class="login-footer">
        &copy; <?= date('Y') ?> Department of the Government Chemist &middot; All rights reserved
      </div>

    </div>
  </div>

</div>

<script>
function togglePw() {
  const input = document.getElementById('loginPassword');
  const icon = document.getElementById('pwIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('bi-eye', 'bi-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('bi-eye-slash', 'bi-eye');
  }
}
</script>

</body>
</html>
