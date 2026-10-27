<?php
// MSCare Admin - Settings hub (Clinic Profile, Appointment Preferences, Notifications,
// Account & Security, Services & Pricing, Privacy & System).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bell.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

// ---- sections shown in the menu (same order as the design) -------------------------
$SECTIONS = [
    'clinic'        => ['Clinic Profile',          'Clinic details, logo, and operating hours',  'M4 21V6l8-3v18M12 9h8v12M4 21h16M8 9h.01M8 13h.01M8 17h.01M16 13h.01M16 17h.01'],
    'appointments'  => ['Appointment Preferences', 'Schedules, booking rules, and availability', 'M8 3v3M16 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v6M4 6v13a1 1 0 0 0 1 1h6M17 22a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM17 14.5V17l1.5 1'],
    'notifications' => ['Notifications',           'Email alerts and reminders',                 'M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10.3 20a2 2 0 0 0 3.4 0'],
    'email'         => ['Email Sender',            'Mailbox used to send verification codes',    'M3 6h18v12H3zM3 7l9 6 9-6'],
    'account'       => ['Account & Security',      'Admin profile, password, and permissions',   'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c0-3.9 3.1-7 7-7 1.4 0 2.7.4 3.8 1.1M18 15.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM18 13v1.5M18 20.5V22M21 14.5l-1.3.8M16.3 19.7 15 20.5M21 20.5l-1.3-.8M16.3 15.3 15 14.5'],
    'services'      => ['Services & Pricing',      'Dental treatments and service fees',         'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 10 0v-2M20 11a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM4 3h4M12 3h4'],
    'privacy'       => ['Privacy & System',        'Patient privacy, backups, and audit logs',   'M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6zM9 12l2 2 4-4'],
];
$sec = (string)($_GET['s'] ?? '');
if (!isset($SECTIONS[$sec])) $sec = '';

function icon_path($d, $size = 20) {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

// ---- storage: created on first visit so no manual migration is needed -------------
$db_error = null;
$pdo = null;
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
        branch_id smallint NOT NULL,
        setting_key varchar(60) NOT NULL,
        setting_value text NOT NULL DEFAULT '',
        updated_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (branch_id, setting_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id bigserial PRIMARY KEY,
        admin_id integer,
        branch_id smallint,
        action varchar(80) NOT NULL,
        detail text NOT NULL DEFAULT '',
        created_at timestamptz NOT NULL DEFAULT now())");
} catch (PDOException $ex) {
    $db_error = $ex->getMessage();
}

$DEFAULTS = [
    'open_time' => '09:00', 'close_time' => '17:00',
    'slot_minutes' => '30', 'max_per_day' => '12', 'advance_days' => '60', 'cancel_hours' => '24', 'auto_confirm' => '0',
    'n_booking_alert' => '1', 'n_cancel_alert' => '1', 'n_patient_confirm' => '1', 'n_reminder' => '1', 'reminder_hours' => '24', 'alert_email' => '',
    'clinic_logo' => '', 'p_mask_records' => '1', 'p_session_minutes' => '30', 'p_backup_freq' => 'weekly',
];

function get_settings($pdo, $bid, $defaults) {
    $out = $defaults;
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM clinic_settings WHERE branch_id = :b");
    $st->execute([':b' => $bid]);
    foreach ($st->fetchAll() as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}
function put_setting($pdo, $bid, $key, $val) {
    $st = $pdo->prepare("INSERT INTO clinic_settings (branch_id, setting_key, setting_value, updated_at)
                         VALUES (:b, :k, :v, now())
                         ON CONFLICT (branch_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()");
    $st->execute([':b' => $bid, ':k' => $key, ':v' => (string)$val]);
}
function audit($pdo, $admin_id, $bid, $action, $detail = '') {
    $st = $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, branch_id, action, detail) VALUES (:a, :b, :x, :d)");
    $st->execute([':a' => $admin_id, ':b' => $bid, ':x' => $action, ':d' => $detail]);
}
function initials_of($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '' && strtolower($part) !== 'dr.') $letters .= mb_substr($part, 0, 1);
    }
    return mb_strtoupper(mb_substr($letters, 0, 2));
}
function pg_days_arr($v) {
    if (is_array($v)) return $v;
    $v = trim((string)$v, '{}');
    return $v === '' ? [] : array_map('trim', explode(',', $v));
}
function valid_time($t) { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t); }
function int_in($v, $min, $max, $fallback) {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return ($n === false || $n < $min || $n > $max) ? $fallback : $n;
}

// CSRF token (per session)
if (empty($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['settings_csrf'];

$errors = [];
$DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ---- save handlers ----------------------------------------------------------------
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = (string)($_POST['section'] ?? $sec);
    if (!isset($SECTIONS[$sec])) $sec = '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            if ($sec === 'clinic') {
                $name = trim((string)($_POST['name'] ?? ''));
                $addr = trim((string)($_POST['address'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $days = array_values(array_intersect($DAYS, (array)($_POST['days'] ?? [])));
                $open = (string)($_POST['open_time'] ?? ''); $close = (string)($_POST['close_time'] ?? '');
                if ($name === '' || $addr === '') $errors[] = 'Clinic name and address are required.';
                if (!$days) $errors[] = 'Select at least one operating day.';
                if (!valid_time($open) || !valid_time($close) || $open >= $close) $errors[] = 'Opening time must be earlier than closing time.';
                $logoPath = null;
                if (!empty($_FILES['clinic_logo']['name']) && $_FILES['clinic_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $lf = $_FILES['clinic_logo'];
                    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $mime = ($lf['error'] === UPLOAD_ERR_OK && is_uploaded_file($lf['tmp_name'])) ? (new finfo(FILEINFO_MIME_TYPE))->file($lf['tmp_name']) : '';
                    if ($lf['error'] !== UPLOAD_ERR_OK) $errors[] = 'The logo could not be uploaded. Try a smaller image.';
                    elseif (!isset($ext[$mime])) $errors[] = 'Logo must be a PNG, JPG, WEBP or GIF image.';
                    elseif ($lf['size'] > 2 * 1024 * 1024) $errors[] = 'Logo must be 2 MB or smaller.';
                    else {
                        $dir = __DIR__ . '/uploads';
                        if (!is_dir($dir)) @mkdir($dir, 0775, true);
                        foreach (glob($dir . '/logo_' . (int)$branch_id . '.*') ?: [] as $old) @unlink($old);
                        $rel = 'uploads/logo_' . (int)$branch_id . '.' . $ext[$mime];
                        if (move_uploaded_file($lf['tmp_name'], __DIR__ . '/' . $rel)) $logoPath = $rel;
                        else $errors[] = 'Could not save the logo file. Check that the uploads folder is writable.';
                    }
                }
                if (!$errors) {
                    if ($logoPath !== null) put_setting($pdo, $branch_id, 'clinic_logo', $logoPath);
                    $st = $pdo->prepare("UPDATE branches SET name = :n, address = :a, phone = :p, open_days = :d WHERE id = :id");
                    $st->execute([':n' => $name, ':a' => $addr, ':p' => $phone, ':d' => '{' . implode(',', $days) . '}', ':id' => $branch_id]);
                    put_setting($pdo, $branch_id, 'open_time', $open);
                    put_setting($pdo, $branch_id, 'close_time', $close);
                    audit($pdo, $admin['id'], $branch_id, 'Clinic profile updated', $name);
                }
            } elseif ($sec === 'appointments') {
                put_setting($pdo, $branch_id, 'slot_minutes', int_in($_POST['slot_minutes'] ?? '', 10, 120, 30));
                put_setting($pdo, $branch_id, 'max_per_day', int_in($_POST['max_per_day'] ?? '', 1, 100, 12));
                put_setting($pdo, $branch_id, 'advance_days', int_in($_POST['advance_days'] ?? '', 1, 365, 60));
                put_setting($pdo, $branch_id, 'cancel_hours', int_in($_POST['cancel_hours'] ?? '', 0, 168, 24));
                put_setting($pdo, $branch_id, 'auto_confirm', !empty($_POST['auto_confirm']) ? '1' : '0');
                audit($pdo, $admin['id'], $branch_id, 'Appointment preferences updated');
            } elseif ($sec === 'notifications') {
                $email = trim((string)($_POST['alert_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid alert email address.';
                if (!$errors) {
                    foreach (['n_booking_alert', 'n_cancel_alert', 'n_patient_confirm', 'n_reminder'] as $k) {
                        put_setting($pdo, $branch_id, $k, !empty($_POST[$k]) ? '1' : '0');
                    }
                    put_setting($pdo, $branch_id, 'reminder_hours', int_in($_POST['reminder_hours'] ?? '', 1, 168, 24));
                    put_setting($pdo, $branch_id, 'alert_email', $email);
                    audit($pdo, $admin['id'], $branch_id, 'Notification settings updated');
                }
            } elseif ($sec === 'email') {
                require_once __DIR__ . '/includes/smtp.php';
                $cur = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic', 'smtp_from_email' => '']);
                $host = trim((string)($_POST['smtp_host'] ?? '')) ?: 'smtp.gmail.com';
                $port = int_in($_POST['smtp_port'] ?? '', 1, 65535, 587);
                $user = trim((string)($_POST['smtp_username'] ?? ''));
                $pass = str_replace(' ', '', (string)($_POST['smtp_password'] ?? ''));
                if ($pass === '') $pass = $cur['smtp_password'];          // blank = keep the saved password
                $fname = trim((string)($_POST['smtp_from_name'] ?? '')) ?: 'MSCare Dental Clinic';
                $testTo = trim((string)($_POST['test_to'] ?? ''));
                if (!filter_var($user, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter the sender email address (e.g. yourclinic@gmail.com).';
                if ($pass === '') $errors[] = 'Enter the sender app password.';
                if (strtolower($user) === 'yourclinic@gmail.com' || strtolower($pass) === 'abcdefghijklmnop') $errors[] = 'Those are sample values. Enter the real sender Gmail and its App Password.';
                elseif ($pass !== '' && !preg_match('/^[a-z]{16}$/i', $pass) && substr($user, -10) === '@gmail.com') $errors[] = 'A Gmail App Password is exactly 16 letters (shown as 4 groups of 4). Your normal Gmail password will not work - create an App Password at myaccount.google.com/apppasswords.';
                if ($testTo !== '' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) $errors[] = 'The test email address is not valid.';
                if (!$errors) {
                    put_setting($pdo, 0, 'smtp_host', $host);
                    put_setting($pdo, 0, 'smtp_port', $port);
                    put_setting($pdo, 0, 'smtp_username', $user);
                    put_setting($pdo, 0, 'smtp_password', $pass);
                    put_setting($pdo, 0, 'smtp_from_name', $fname);
                    audit($pdo, $admin['id'], $branch_id, 'Email sender updated', $user);
                    if ($testTo !== '') {
                        [$ok, $err] = smtp_send_with(['host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass, 'from_name' => $fname, 'from_email' => '', 'verify_ssl' => false],
                            $testTo, 'MSCare test email', '<p>It works! MSCare can now send verification codes.</p>', 'It works! MSCare can now send verification codes.');
                        if ($ok) { flash('Email sender saved. Test email sent to ' . $testTo . ' - check the inbox (and Spam).'); }
                        else     { flash('Saved, but the test email FAILED: ' . $err, 'error'); }
                        redirect_to('settings.php?s=email');
                    }
                }
            } elseif ($sec === 'account') {
                $act = (string)($_POST['do'] ?? '');
                if ($act === 'profile') {
                    $dn = trim((string)($_POST['display_name'] ?? ''));
                    if ($dn === '') $errors[] = 'Display name is required.';
                    if (!$errors) {
                        $ini = initials_of($dn) ?: 'AD';
                        $st = $pdo->prepare("UPDATE admins SET display_name = :n, initials = :i WHERE id = :id");
                        $st->execute([':n' => $dn, ':i' => $ini, ':id' => $admin['id']]);
                        $_SESSION['mscare_admin']['display_name'] = $dn;
                        $_SESSION['mscare_admin']['initials'] = $ini;
                        audit($pdo, $admin['id'], $branch_id, 'Admin profile updated');
                    }
                } elseif ($act === 'password') {
                    $cur = (string)($_POST['current_password'] ?? '');
                    $new = (string)($_POST['new_password'] ?? '');
                    $con = (string)($_POST['confirm_password'] ?? '');
                    $st = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id");
                    $st->execute([':id' => $admin['id']]);
                    $hash = (string)$st->fetchColumn();
                    if (!password_verify($cur, $hash)) $errors[] = 'Your current password is incorrect.';
                    elseif (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
                    elseif ($new !== $con) $errors[] = 'New password and confirmation do not match.';
                    if (!$errors) {
                        $st = $pdo->prepare("UPDATE admins SET password_hash = :h WHERE id = :id");
                        $st->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $admin['id']]);
                        audit($pdo, $admin['id'], $branch_id, 'Password changed');
                    }
                }
            } elseif ($sec === 'services') {
                $prices = (array)($_POST['price'] ?? []);
                $active = (array)($_POST['active'] ?? []);
                $ids = $pdo->query("SELECT id FROM services")->fetchAll(PDO::FETCH_COLUMN);
                $pdo->beginTransaction();
                $up = $pdo->prepare("UPDATE services SET price = :p, is_active = :a WHERE id = :id");
                foreach ($ids as $sid) {
                    $raw = str_replace(',', '', (string)($prices[$sid] ?? '0'));
                    if (!is_numeric($raw) || (float)$raw < 0) { $errors[] = 'Prices must be valid amounts (0 or more).'; break; }
                    $up->bindValue(':p', number_format((float)$raw, 2, '.', ''));
                    $up->bindValue(':a', isset($active[$sid]), PDO::PARAM_BOOL);
                    $up->bindValue(':id', (int)$sid, PDO::PARAM_INT);
                    $up->execute();
                }
                if ($errors) { $pdo->rollBack(); } else { $pdo->commit(); audit($pdo, $admin['id'], $branch_id, 'Services & pricing updated'); }
            } elseif ($sec === 'privacy') {
                $freq = in_array($_POST['p_backup_freq'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['p_backup_freq'] : 'weekly';
                put_setting($pdo, $branch_id, 'p_mask_records', !empty($_POST['p_mask_records']) ? '1' : '0');
                put_setting($pdo, $branch_id, 'p_session_minutes', int_in($_POST['p_session_minutes'] ?? '', 5, 480, 30));
                put_setting($pdo, $branch_id, 'p_backup_freq', $freq);
                audit($pdo, $admin['id'], $branch_id, 'Privacy & system settings updated');
            }
            if (!$errors && $sec !== '') {
                flash($SECTIONS[$sec][0] . ' saved.');
                redirect_to('settings.php?s=' . $sec);
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save: ' . $ex->getMessage();
        }
    }
}

// ---- load data for the current view ------------------------------------------------
$S = $DEFAULTS; $branch = null; $dentists = []; $services = []; $logs = [];
if ($pdo) {
    try {
        $S = get_settings($pdo, $branch_id, $DEFAULTS);
        $st = $pdo->prepare("SELECT * FROM branches WHERE id = :id");
        $st->execute([':id' => $branch_id]);
        $branch = $st->fetch() ?: null;
        if ($sec === 'clinic') {
            $dentists = $pdo->query("SELECT * FROM dentists WHERE is_active = TRUE ORDER BY name")->fetchAll();
        } elseif ($sec === 'services') {
            $services = $pdo->query("SELECT * FROM services ORDER BY category, sort_order, name")->fetchAll();
        } elseif ($sec === 'privacy') {
            $st = $pdo->prepare("SELECT l.*, a.display_name FROM admin_audit_logs l LEFT JOIN admins a ON a.id = l.admin_id
                                 WHERE l.branch_id = :b ORDER BY l.created_at DESC, l.id DESC LIMIT 25");
            $st->execute([':b' => $branch_id]);
            $logs = $st->fetchAll();
        }
    } catch (PDOException $ex) {
        $db_error = $ex->getMessage();
    }
}
$flash = take_flash();
function checked($v) { return $v === '1' ? ' checked' : ''; }
function switch_row($name, $title, $sub, $on) {
    return '<label class="set-switch"><span class="set-switch-text"><strong>' . e($title) . '</strong><small>' . e($sub) . '</small></span>'
         . '<input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '><span class="set-slider" aria-hidden="true"></span></label>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="messages.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Messages</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="patient-record.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span>Patient Dental Record</span></a>
      <a class="nav-item" href="chart.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg><span>Dental Chart</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Reports &amp; Analytics</span></a>
      <a class="nav-item active" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= e($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= e($admin['display_name']) ?></div>
          <div class="who-role"><?= e($cinfo['place']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Settings</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= e($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= e($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
  <?php if (!empty($db_error)): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($db_error) ?></div><?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($sec === ''): ?>
  <div class="card set-card">
    <div class="set-title">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
      <h3>Settings</h3>
    </div>
    <nav class="set-list" aria-label="Settings sections">
      <?php foreach ($SECTIONS as $key => [$label, $desc, $d]): ?>
        <a class="set-row" href="settings.php?s=<?= e($key) ?>">
          <span class="set-ico"><?= icon_path($d, 22) ?></span>
          <span class="set-text"><strong><?= e($label) ?></strong><small><?= e($desc) ?></small></span>
          <svg class="set-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

<?php else: [$label, $desc, $d] = $SECTIONS[$sec]; ?>
  <a class="set-back" href="settings.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg> Back to Settings</a>
  <div class="card set-card set-panel">
    <div class="set-title">
      <span class="set-ico sm"><?= icon_path($d, 20) ?></span>
      <div><h3><?= e($label) ?></h3></div>
    </div>

  <?php if ($sec === 'clinic' && $branch): $open = pg_days_arr($branch['open_days'] ?? ''); ?>
    <form method="post" class="set-form" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="clinic">
      <div class="form-grid">
        <div class="full"><label>Clinic name</label><input type="text" name="name" value="<?= e($_POST['name'] ?? $branch['name']) ?>" required></div>
        <div class="full"><label>Address</label><input type="text" name="address" value="<?= e($_POST['address'] ?? $branch['address']) ?>" required></div>
        <div><label>Contact number</label><input type="text" name="phone" value="<?= e($_POST['phone'] ?? $branch['phone']) ?>"></div>
        <div><label>City</label><input type="text" value="<?= e($branch['city']) ?>" disabled></div>
        <div><label>Opening time</label><input type="time" name="open_time" value="<?= e($_POST['open_time'] ?? $S['open_time']) ?>" required></div>
        <div><label>Closing time</label><input type="time" name="close_time" value="<?= e($_POST['close_time'] ?? $S['close_time']) ?>" required></div>
        <div class="full">
          <label>Operating days</label>
          <div class="day-pick">
            <?php foreach ($DAYS as $dy): ?>
              <label class="day-chip"><input type="checkbox" name="days[]" value="<?= $dy ?>"<?= in_array($dy, $open, true) ? ' checked' : '' ?>><span><?= $dy ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="full">
          <label>Clinic logo</label>
          <div class="logo-row">
            <span class="logo-box"><?php if (!empty($S['clinic_logo'])): ?><img src="<?= e($S['clinic_logo']) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $S['clinic_logo']) ?>" alt="Clinic logo" style="width:100%;height:100%;object-fit:contain;border-radius:14px;"><?php else: ?><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#1FA8A0" stroke-width="1.6"><circle cx="12" cy="12" r="8"/></svg><?php endif; ?></span>
            <input type="file" name="clinic_logo" accept="image/png,image/jpeg,image/webp,image/gif">
          </div>
        </div>
      </div>
      <?php if ($dentists): ?>
        <div class="set-sub">Dentist on duty</div>
        <div class="set-dentists">
          <?php foreach ($dentists as $dn): ?>
            <div class="set-dentist"><span class="avatar"><?= e(initials_of($dn['name'])) ?></span><div><strong><?= e($dn['name']) ?></strong><small><?= e($dn['title']) ?><?= !empty($dn['phone']) ? ' · ' . e($dn['phone']) : '' ?></small></div></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'appointments'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="appointments">
      <div class="form-grid">
        <div><label>Appointment length (minutes)</label><input type="number" name="slot_minutes" min="10" max="120" step="5" value="<?= e($S['slot_minutes']) ?>"></div>
        <div><label>Max appointments per day</label><input type="number" name="max_per_day" min="1" max="100" value="<?= e($S['max_per_day']) ?>"></div>
        <div><label>Patients can book up to (days ahead)</label><input type="number" name="advance_days" min="1" max="365" value="<?= e($S['advance_days']) ?>"></div>
        <div><label>Cancel / reschedule cut-off (hours before)</label><input type="number" name="cancel_hours" min="0" max="168" value="<?= e($S['cancel_hours']) ?>"></div>
      </div>
      <div class="set-switches"><?= switch_row('auto_confirm', 'Auto-confirm new bookings', '', $S['auto_confirm'] === '1') ?></div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'notifications'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="notifications">
      <div class="set-switches">
        <?= switch_row('n_booking_alert', 'New booking alerts', '', $S['n_booking_alert'] === '1') ?>
        <?= switch_row('n_cancel_alert', 'Cancellation alerts', '', $S['n_cancel_alert'] === '1') ?>
        <?= switch_row('n_patient_confirm', 'Patient confirmation emails', '', $S['n_patient_confirm'] === '1') ?>
        <?= switch_row('n_reminder', 'Appointment reminders', '', $S['n_reminder'] === '1') ?>
      </div>
      <div class="form-grid" style="margin-top:16px;">
        <div><label>Send reminder (hours before)</label><input type="number" name="reminder_hours" min="1" max="168" value="<?= e($_POST['reminder_hours'] ?? $S['reminder_hours']) ?>"></div>
        <div><label>Clinic alert email</label><input type="email" name="alert_email" placeholder="clinic@example.com" value="<?= e($_POST['alert_email'] ?? $S['alert_email']) ?>"></div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'email'): ?>
    <?php $E = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic']); ?>
    <form method="post" class="set-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="email">
      <div class="form-grid">
        <div><label>Sender email</label><input type="email" name="smtp_username" placeholder="yourclinic@gmail.com" value="<?= e($_POST['smtp_username'] ?? $E['smtp_username']) ?>" required></div>
        <div><label>App password <?= $E['smtp_password'] !== '' ? '(saved - leave blank to keep)' : '' ?></label><input type="password" name="smtp_password" placeholder="xxxx xxxx xxxx xxxx" autocomplete="new-password" <?= $E['smtp_password'] === '' ? 'required' : '' ?>></div>
        <div><label>Sender name</label><input type="text" name="smtp_from_name" value="<?= e($_POST['smtp_from_name'] ?? $E['smtp_from_name']) ?>"></div>
        <div><label>SMTP server</label><input type="text" name="smtp_host" value="<?= e($_POST['smtp_host'] ?? $E['smtp_host']) ?>"></div>
        <div><label>Port (587 or 465)</label><input type="number" name="smtp_port" min="1" max="65535" value="<?= e($_POST['smtp_port'] ?? $E['smtp_port']) ?>"></div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save &amp; test</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'account'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="profile">
      <div class="set-sub first">Admin profile</div>
      <div class="form-grid">
        <div><label>Display name</label><input type="text" name="display_name" value="<?= e($_POST['display_name'] ?? $admin['display_name']) ?>" required></div>
        <div><label>Username</label><input type="text" value="<?= e($admin['username']) ?>" disabled></div>
        <div><label>Role</label><input type="text" value="<?= e(ucfirst($admin['role'])) ?> · <?= e($cinfo['label']) ?>" disabled></div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
    <form method="post" class="set-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="password">
      <div class="set-sub">Change password</div>
      <div class="form-grid">
        <div class="full"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
        <div><label>New password (min. 8 characters)</label><input type="password" name="new_password" autocomplete="new-password" minlength="8" required></div>
        <div><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Update password</button></div>
    </form>

  <?php elseif ($sec === 'services'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="services">
      <?php if (!$services): ?>
        <p class="set-note">No services found in the database.</p>
      <?php else: $cat = null; foreach ($services as $sv): ?>
        <?php if ($sv['category'] !== $cat): $cat = $sv['category']; ?><div class="set-sub<?= $cat === $services[0]['category'] ? ' first' : '' ?>"><?= e($cat) ?></div><?php endif; ?>
        <div class="svc-row">
          <span class="svc-name"><?= e($sv['name']) ?></span>
          <label class="svc-price"><span>₱</span><input type="text" inputmode="decimal" name="price[<?= (int)$sv['id'] ?>]" value="<?= e(number_format((float)$sv['price'], 2, '.', '')) ?>" aria-label="Price for <?= e($sv['name']) ?>"></label>
          <label class="svc-active"><input type="checkbox" name="active[<?= (int)$sv['id'] ?>]" value="1"<?= !empty($sv['is_active']) ? ' checked' : '' ?>> Active</label>
        </div>
      <?php endforeach; endif; ?>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save prices</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'privacy'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="privacy">
      <div class="set-switches"><?= switch_row('p_mask_records', 'Mask sensitive details', '', $S['p_mask_records'] === '1') ?></div>
      <div class="form-grid" style="margin-top:16px;">
        <div><label>Auto log-out after (minutes idle)</label><input type="number" name="p_session_minutes" min="5" max="480" value="<?= e($S['p_session_minutes']) ?>"></div>
        <div><label>Backup schedule</label>
          <select name="p_backup_freq"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $v): ?><option value="<?= $k ?>"<?= $S['p_backup_freq'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>
    <div class="set-sub">Audit log</div>
    <?php if (!$logs): ?>
      <p class="set-note">No activity recorded yet. Changes made in Settings will appear here.</p>
    <?php else: ?>
      <div class="set-table"><table>
        <thead><tr><th>When</th><th>Admin</th><th>Action</th></tr></thead>
        <tbody><?php foreach ($logs as $lg): ?>
          <tr><td><?= e(date('M j, Y g:i A', strtotime($lg['created_at']))) ?></td><td><?= e($lg['display_name'] ?? 'Unknown') ?></td><td><?= e($lg['action']) ?><?= $lg['detail'] !== '' ? ' <span class="set-note">— ' . e($lg['detail']) . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php if ($flash): ?>
<script>window.addEventListener('DOMContentLoaded',function(){var t=document.createElement('div');t.className='toast';t.textContent=<?= json_encode($flash['message']) ?>;document.body.appendChild(t);setTimeout(function(){t.remove();},3200);});</script>
<?php endif; ?>
  </div>
</div>
</body>
</html>
