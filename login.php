<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['mscare_admin']);
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}

if (current_admin()) {
    header('Location: index.php');
    exit;
}

$username = '';
$error = '';
$next = $_GET['next'] ?? $_POST['next'] ?? 'index.php';
$allowed_next = ['index.php', 'appointments.php', 'patients.php', 'patient-record.php',
                 'chart.php', 'treatments.php', 'reports.php', 'settings.php'];
if (!in_array($next, $allowed_next, true)) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $stmt = db()->prepare("SELECT * FROM admins WHERE username = :u AND is_active = TRUE LIMIT 1");
            $stmt->execute([':u' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                session_regenerate_id(true);
                login_admin_session($admin);
                db()->prepare("UPDATE admins SET last_login_at = now() WHERE id = :id")
                    ->execute([':id' => $admin['id']]);
                header('Location: ' . $next);
                exit;
            }
            $error = 'Incorrect admin username or password.';
        } catch (PDOException $e) {
            $error = 'Database connection error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin log in | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261026a">
</head>
<body data-login-role="admin">
<div class="center-screen">
  <div class="login-wrap">
      <div class="login-hero">
        <div class="brand">
          <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
          <span>MSCare</span>
        </div>
      </div>
    <div class="login-form-side">
      <h3>Admin log in</h3>
      <p class="sub" id="loginSub">Log in to manage the clinic dashboard.</p>
      <?php if ($error): ?>
        <div class="login-error" style="display:block;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST" action="login.php">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label id="userLabel">Admin username</label>
          <div class="input-wrap"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4.5 4.7-6.5 8-6.5S18.5 15.5 20 20"/></svg><input type="text" id="loginEmail" name="username" value="<?= e($username) ?>" autocomplete="username" placeholder="Username" required></div>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="input-wrap"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input type="password" id="loginPass" name="password" autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" required></div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Log in <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
