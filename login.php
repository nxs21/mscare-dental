<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

if (isset($_GET['logout'])) {
    unset($_SESSION['mscare_patient']);
    session_regenerate_id(true);
    flash('You have been logged out. See you at your next visit!');
    header('Location: login.php');
    exit;
}

$allowed_next = ['patient-dashboard.php', 'patient-book.php', 'patient-appointments.php',
                 'patient-history.php', 'patient-record.php', 'patient-profile.php'];
$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'patient-dashboard.php');
if (!in_array($next, $allowed_next, true)) $next = 'patient-dashboard.php';

if (current_patient()) { header('Location: ' . $next); exit; }

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $stmt = db()->prepare("SELECT * FROM patients WHERE LOWER(email) = :em AND status = 'Active' LIMIT 1");
            $stmt->execute([':em' => $email]);
            $p = $stmt->fetch();
            if ($p && password_verify($password, $p['password_hash'])) {
                if (empty($p['email_verified'])) {
                    // Correct password but email never verified: send a fresh code and ask for it.
                    if (otp_wait_seconds((int)$p['id'], 'register') === 0) { send_patient_code($p, 'register'); }
                    session_regenerate_id(true);
                    $_SESSION['verify_pid'] = (int)$p['id'];
                    flash('Please verify your email first. We sent a code to ' . mask_email($p['email']) . '.');
                    header('Location: verify-email.php');
                    exit;
                }
                session_regenerate_id(true);
                login_patient_session($p);
                header('Location: ' . $next);
                exit;
            }
            $error = 'Incorrect email or password. Please try again.';
        } catch (PDOException $ex) {
            error_log('MSCare DB (login): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'We could not reach the database. Please try again in a moment.');
        }
    }
}

$page = 'login'; $title = 'Patient log in';
include __DIR__ . '/includes/header.php';
$flash = take_flash();
?>
<section class="auth">
  <div class="container">
    <div class="auth-card">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['about_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
      </aside>

      <div class="auth-main">
        <a class="auth-back" href="index.php"><?= icon($ui['back'], 16) ?> Back to homepage</a>
        <h2>Welcome back</h2>

        <?php if ($flash): ?><div class="alert ok" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form method="post" action="login.php" novalidate>
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <div class="field">
            <label for="email">Email</label>
            <span class="input-icon"><?= icon($ui['mail'], 17) ?>
              <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" placeholder="you@email.com" required autofocus>
            </span>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Your password" required>
              <button type="button" class="pass-toggle" data-toggle-pass="password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span>
          </div>
          <p style="text-align:right;margin:-6px 0 14px;font-size:.92rem"><a href="forgot-password.php">Forgot password?</a></p>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Log in <?= icon($ui['arrow'], 18) ?></button>
        </form>

        <p class="auth-switch">New to MSCare? <a href="register.php">Create a patient account</a></p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
