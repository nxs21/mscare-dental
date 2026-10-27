<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$today_count = 0;
$total_patients = 0;
$pending_count = 0;
$total_revenue = 0.0;
$upcoming = [];
$recent_activity = [];
$db_error = null;

try {
    $pdo = db();

    // 1. Today's appointments count
    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE branch_id = :b AND appointment_date = CURRENT_DATE AND status <> 'Cancelled'");
    $stmt->execute([':b' => $branch_id]);
    $today_count = (int)$stmt->fetchColumn();

    // 2. Total active registered patients
    $total_patients = (int)$pdo->query("SELECT count(*) FROM patients WHERE status = 'Active'")->fetchColumn();

    // 3. Pending confirmations count
    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE branch_id = :b AND status = 'Pending'");
    $stmt->execute([':b' => $branch_id]);
    $pending_count = (int)$stmt->fetchColumn();

    // 4. Recorded revenue from treatments
    $stmt = $pdo->prepare("SELECT COALESCE(sum(cost), 0) FROM treatments WHERE branch_id = :b");
    $stmt->execute([':b' => $branch_id]);
    $total_revenue = (float)$stmt->fetchColumn();

    // 5. Upcoming appointments for this clinic
    $stmt = $pdo->prepare("
        SELECT a.id, a.ref, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               s.name AS service_name, a.status,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 10
    ");
    $stmt->execute([':b' => $branch_id]);
    $upcoming = $stmt->fetchAll();

    // 6. Recent activity (last treatments or completed/cancelled appointments)
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, s.name AS service_name, a.status,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b
        ORDER BY a.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([':b' => $branch_id]);
    $recent_activity = $stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$badge_map = [
    'Confirmed' => 'b-confirmed',
    'Pending'   => 'b-pending',
    'Completed' => 'b-completed',
    'Cancelled' => 'b-cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261026a">
<script src="dialog.js?v=20261027"></script>
</head>
<body data-guard="admin">
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item active" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="messages.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Messages</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="patient-record.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span>Patient Dental Record</span></a>
      <a class="nav-item" href="chart.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg><span>Dental Chart</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Reports &amp; Analytics</span></a>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= htmlspecialchars($admin['display_name']) ?></div>
          <div class="who-role"><?= htmlspecialchars($cinfo['place']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Dashboard</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <?php if ($db_error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13.5px;">
          <b>Database Connection Notice:</b> <?= htmlspecialchars($db_error) ?>
        </div>
      <?php endif; ?>

      <!-- Live Stats from PostgreSQL -->
      <div class="grid g4" style="margin-bottom:18px;">
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--teal-tint);color:var(--teal-dark);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></div></div>
          <div class="stat-num"><span><?= $today_count ?></span></div>
          <div class="stat-label">Today's appointments</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--violet-tint);color:var(--violet);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg></div></div>
          <div class="stat-num"><span><?= $total_patients ?></span></div>
          <div class="stat-label">Total registered patients</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--amber-tint);color:#9a7317;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div></div>
          <div class="stat-num"><span><?= $pending_count ?></span></div>
          <div class="stat-label">Pending confirmations</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--green-tint);color:var(--green);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg></div></div>
          <div class="stat-num"><span>₱<?= number_format($total_revenue, 2) ?></span></div>
          <div class="stat-label">Recorded revenue</div>
        </div>
      </div>

      <div class="grid g2" style="margin-bottom:18px;">
        <!-- Live Upcoming Appointments -->
        <div class="card">
          <div class="section-head"><h4>Upcoming appointments</h4><a class="link" href="appointments.php">View all</a></div>
          <table id="dashApptTable">
            <thead>
              <tr><th>Patient</th><th>Service</th><th>Date &amp; Time</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php if (empty($upcoming)): ?>
                <tr>
                  <td colspan="5" style="text-align:center;color:var(--muted);padding:36px;">
                    No appointments recorded in the database yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($upcoming as $a): ?>
                  <tr class="row-hover" data-status="<?= htmlspecialchars($a['status']) ?>">
                    <td>
                      <div class="row-people">
                        <div class="avatar-sm"><?= htmlspecialchars($a['initials']) ?></div>
                        <span class="name"><?= htmlspecialchars($a['patient_name']) ?></span>
                      </div>
                    </td>
                    <td><?= htmlspecialchars($a['service_name']) ?></td>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($a['appointment_date'])) ?>, <?= htmlspecialchars($a['time_formatted']) ?></td>
                    <td><span class="badge <?= $badge_map[$a['status']] ?? 'b-pending' ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                    <td>
                      <div class="action-row">
                        <?php if ($a['status'] === 'Pending'): ?>
                          <button type="button" class="icon-btn" title="Confirm" onclick="handleApptAction(<?= (int)$a['id'] ?>, 'confirm_appointment')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                          </button>
                        <?php endif; ?>
                        <?php if ($a['status'] !== 'Cancelled' && $a['status'] !== 'Completed'): ?>
                          <button type="button" class="icon-btn" title="Cancel" onclick="handleApptAction(<?= (int)$a['id'] ?>, 'cancel_appointment')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Schedule Information -->
        <div class="card">
          <div class="section-head"><h4>Clinic Details &mdash; <?= htmlspecialchars($cinfo['name']) ?></h4></div>
          <div style="padding:14px 0;line-height:1.7;font-size:13.5px;color:var(--navy);">
            <p><b>Open Days:</b> <span style="color:var(--teal-dark);font-weight:600;"><?= htmlspecialchars($cinfo['days']) ?></span></p>
            <p><b>Attending Dentist:</b> Dr. Marilou S. Cariño</p>
            <p><b>Operating Hours:</b> 08:00 AM &ndash; 05:00 PM</p>
            <p style="margin-top:12px;font-size:12.5px;color:var(--muted);">All appointments and patient records are synchronized live with the PostgreSQL database.</p>
          </div>
        </div>
      </div>

      <div class="grid g2">
        <!-- Live Recent Activity from Database -->
        <div class="card">
          <div class="section-head"><h4>Recent database activity</h4></div>
          <div>
            <?php if (empty($recent_activity)): ?>
              <p style="color:var(--muted);font-size:13px;padding:16px 0;">No recent activity yet.</p>
            <?php else: ?>
              <?php foreach ($recent_activity as $act): ?>
                <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #EEF3F4;">
                  <div class="avatar-sm"><?= htmlspecialchars($act['initials']) ?></div>
                  <div>
                    <div style="font-size:13px;">
                      <b><?= htmlspecialchars($act['patient_name']) ?></b> &mdash; <?= htmlspecialchars($act['service_name']) ?>
                      <span class="badge <?= $badge_map[$act['status']] ?? 'b-pending' ?>"><?= htmlspecialchars($act['status']) ?></span>
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">
                      <?= date('M j, Y', strtotime($act['appointment_date'])) ?> with Dr. Marilou S. Cariño
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
async function handleApptAction(id, action) {
  if (action === 'cancel_appointment') {
    const ok = await msConfirm({
      title: 'Cancel this appointment?',
      message: 'The appointment will be marked as cancelled and the patient will be notified.',
      confirmText: 'Yes, cancel it', cancelText: 'Keep appointment', danger: true
    });
    if (!ok) return;
  }
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: action, id: id })
    });
    const d = await res.json();
    if (d.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (d.error || 'Failed to update'), 'error');
    }
  } catch (e) {
    msToast('Connection error: ' + e.message, 'error');
  }
}
</script>
</body>
</html>
