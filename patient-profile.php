<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
$stmt->execute([':id' => $pid]);
$p = $stmt->fetch();
if (!$p) { unset($_SESSION['mscare_patient']); redirect_to('login.php'); }

$last_visit = null;
try {
    $lv = $pdo->prepare("
        SELECT to_char(max(d), 'Mon DD, YYYY') FROM (
            SELECT max(treatment_date) AS d FROM treatments WHERE patient_id = :pid
            UNION ALL
            SELECT last_dental_visit FROM patient_records WHERE patient_id = :pid2
        ) x");
    $lv->execute([':pid' => $pid, ':pid2' => $pid]);
    $last_visit = $lv->fetchColumn() ?: null;
} catch (PDOException $ex) { $last_visit = null; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = mb_strtoupper(trim((string)($_POST['first_name'] ?? '')));
    $middle = mb_strtoupper(trim((string)($_POST['middle_name'] ?? '')));
    $last   = mb_strtoupper(trim((string)($_POST['last_name'] ?? '')));
    $age    = (int)($_POST['age'] ?? 0);
    $email  = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone  = trim((string)($_POST['phone'] ?? ''));
    $gender = trim((string)($_POST['gender'] ?? 'Prefer not to say'));
    $nameRx = "/^[\p{L}][\p{L} .'\-]*$/u";

    if ($first === '' || $last === '') {
        $error = 'Please enter your first and last name.';
    } elseif (!preg_match($nameRx, $first) || !preg_match($nameRx, $last) || ($middle !== '' && !preg_match($nameRx, $middle))) {
        $error = 'Names can only contain letters, spaces, hyphens, apostrophes and periods.';
    } elseif ($age < 1 || $age > 120) {
        $error = 'Please enter a valid age.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $error = 'Please enter a valid mobile number.';
    } elseif (!in_array($gender, ['Female', 'Male', 'Prefer not to say'], true)) {
        $error = 'Please choose a gender.';
    } else {
        try {
            $dup = $pdo->prepare("SELECT id FROM patients WHERE LOWER(email) = :em AND id <> :id");
            $dup->execute([':em' => $email, ':id' => $pid]);
            if ($dup->fetch()) {
                $error = 'Another account already uses that email.';
            } else {
                $pdo->prepare("UPDATE patients SET first_name=:fn, middle_name=:mn, last_name=:ln,
                               age=:ag, email=:em, phone=:ph, gender=:gn, updated_at=now() WHERE id=:id")
                    ->execute([':fn' => $first, ':mn' => $middle, ':ln' => $last, ':ag' => $age,
                               ':em' => $email, ':ph' => $phone, ':gn' => $gender, ':id' => $pid]);
                if (in_array($gender, ['Female', 'Male'], true)) {
                    $pdo->prepare("UPDATE patient_records SET sex = :gn WHERE patient_id = :id")->execute([':gn' => $gender, ':id' => $pid]);
                }
                refresh_patient_session($pid);
                flash('Profile updated.');
                redirect_to('patient-profile.php');
            }
        } catch (PDOException $ex) {
            error_log('MSCare DB (profile): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'We could not save your profile. Please try again.');
        }
    }
    // keep what the person typed
    $p = array_merge($p, ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'age' => $age,
                          'email' => $email, 'phone' => $phone, 'gender' => $gender]);
}
$gate = booking_status($pid);

$ptitle = 'My Profile';
$psub   = '';
$pactive = 'profile';
include __DIR__ . '/includes/portal_head.php';
?>
<?php if ($error): ?>
  <div class="banner err" role="alert"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($error) ?></strong></div></div>
<?php endif; ?>

<div class="profile-grid">
  <div class="p-card">
    <div class="p-card-head"><h2>Personal details</h2></div>
    <form method="post" action="patient-profile.php" novalidate>
      <div class="form-grid">
        <div class="field third"><label for="first_name">First name</label><input id="first_name" name="first_name" required data-uppercase value="<?= e($p['first_name']) ?>"></div>
        <div class="field third"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" data-uppercase value="<?= e($p['middle_name']) ?>"></div>
        <div class="field third"><label for="last_name">Last name</label><input id="last_name" name="last_name" required data-uppercase value="<?= e($p['last_name']) ?>"></div>
        <div class="field"><label for="age">Age</label><input id="age" name="age" type="number" min="1" max="120" required value="<?= (int)$p['age'] ?>"></div>
        <div class="field"><label for="gender">Gender</label>
          <select id="gender" name="gender">
            <?php foreach (['Female', 'Male', 'Prefer not to say'] as $g): ?>
              <option<?= $p['gender'] === $g ? ' selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= e($p['email']) ?>"></div>
        <div class="field"><label for="phone">Mobile number</label><input id="phone" name="phone" type="tel" required value="<?= e($p['phone']) ?>"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-pill" style="margin-top:8px;">Save changes</button>
    </form>
  </div>

  <div class="p-card profile-card">
    <div class="big-avatar"><?= e($patient['initials']) ?></div>
    <h3><?= e($patient['full_name']) ?></h3>
    <p class="meta"><?= e($patient['email']) ?></p>
    <dl class="kv">
      <div><dt>Last visit</dt><dd><?= e($last_visit ?: 'No visits yet') ?></dd></div>
      <div><dt>Medical history</dt><dd><?= $gate['med_done'] ? 'Completed' : 'Not yet' ?></dd></div>
      <div><dt>Informed consent</dt><dd><?= e(ucfirst($gate['consent'] === 'none' ? 'not signed' : $gate['consent'])) ?></dd></div>
      <div><dt>Booking</dt><dd><?= $gate['can_book'] ? 'Unlocked' : 'Locked' ?></dd></div>
    </dl>
  </div>
</div>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
