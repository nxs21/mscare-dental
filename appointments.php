<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$appointments = [];
$patients = [];
$services = [];
$db_error = null;

try {
    $pdo = db();

    // 1. Fetch appointments for this clinic
    $stmt = $pdo->prepare("
        SELECT a.id, a.ref, a.patient_id, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               s.name AS service_name, a.status,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               COALESCE(p.phone, a.phone) AS phone,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([':b' => $branch_id]);
    $appointments = $stmt->fetchAll();

    // 2. Fetch registered patients for the modal
    $p_stmt = $pdo->query("SELECT id, concat_ws(' ', first_name, NULLIF(middle_name, ''), last_name) AS full_name FROM patients ORDER BY full_name");
    $patients = $p_stmt->fetchAll();

    // 3. Fetch active services
    $s_stmt = $pdo->query("SELECT id, name FROM services WHERE is_active = TRUE ORDER BY sort_order");
    $services = $s_stmt->fetchAll();

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
<title>Appointment scheduling | MSCare Dental Clinic</title>
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
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item active" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
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
      <div><h2>Appointment scheduling</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <div class="card">
        <div class="toolbar">
          <div class="tabs" id="statusFilterTabs">
            <button type="button" class="tab active" data-filter="all">All</button>
            <button type="button" class="tab" data-filter="Pending">Pending</button>
            <button type="button" class="tab" data-filter="Confirmed">Confirmed</button>
            <button type="button" class="tab" data-filter="Completed">Completed</button>
            <button type="button" class="tab" data-filter="Cancelled">Cancelled</button>
          </div>
          <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('apptModal').classList.add('active');"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> New appointment</button>
        </div>

        <table id="adminApptTable">
          <thead>
            <tr><th>Patient</th><th>Service</th><th>Attending Dentist</th><th>Date &amp; Time</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php if (empty($appointments)): ?>
              <tr>
                <td colspan="6" style="text-align:center;color:var(--muted);padding:40px;">
                  No appointments found for <?= htmlspecialchars($cinfo['label']) ?> in the database.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($appointments as $a): ?>
                <tr class="row-hover appt-row" data-status="<?= htmlspecialchars($a['status']) ?>">
                  <td>
                    <div class="row-people">
                      <div class="avatar-sm"><?= htmlspecialchars($a['initials']) ?></div>
                      <div>
                        <span class="name"><?= htmlspecialchars($a['patient_name']) ?></span>
                        <div style="font-size:11.5px;color:var(--muted);"><?= htmlspecialchars($a['phone']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($a['service_name']) ?></td>
                  <td>Dr. Marilou S. Cariño</td>
                  <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($a['appointment_date'])) ?><br><span style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($a['time_formatted']) ?></span></td>
                  <td><span class="badge <?= $badge_map[$a['status']] ?? 'b-pending' ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                  <td>
                    <div class="action-row">
                      <?php if (!empty($a['patient_id']) && in_array($a['status'], ['Pending', 'Confirmed'], true)): ?>
                        <button type="button" class="icon-btn" title="Send reminder to patient" aria-label="Send reminder to patient" onclick="sendReminder(<?= (int)$a['id'] ?>, this)">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] === 'Pending'): ?>
                        <button type="button" class="icon-btn" title="Confirm" onclick="handleAppt(<?= (int)$a['id'] ?>, 'confirm_appointment')">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] === 'Confirmed'): ?>
                        <button type="button" class="icon-btn" title="Mark completed" onclick="handleAppt(<?= (int)$a['id'] ?>, 'complete_appointment')">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] !== 'Cancelled' && $a['status'] !== 'Completed'): ?>
                        <button type="button" class="icon-btn" title="Cancel" onclick="handleAppt(<?= (int)$a['id'] ?>, 'cancel_appointment')">
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
        <div class="empty hidden" id="adminApptTableEmpty">No appointments match this filter.</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: New Appointment -->
<div class="modal-backdrop" id="apptModal" style="display:none;">
  <div class="modal">
    <div class="modal-head">
      <h3>New appointment</h3>
      <button type="button" class="modal-close" onclick="document.getElementById('apptModal').style.display='none';"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <form id="newApptForm" onsubmit="submitNewAppt(event)">
      <div class="form-grid">
        <div class="full" style="font-size:12.5px;color:var(--muted);"><?= htmlspecialchars($cinfo['label']) ?> &mdash; open <?= htmlspecialchars($cinfo['days']) ?></div>
        <div class="full">
          <label>Patient</label>
          <select id="naPatient" required style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <?php foreach ($patients as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Service</label>
          <select id="naService" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <?php foreach ($services as $s): ?>
              <option value="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Date</label>
          <input type="date" id="naDate" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div>
          <label>Time</label>
          <select id="naTime" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <option>08:00 AM</option><option>09:00 AM</option><option>09:30 AM</option><option>10:00 AM</option>
            <option>11:00 AM</option><option>01:00 PM</option><option>02:00 PM</option><option>03:30 PM</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:16px;width:100%;">Create Appointment</button>
    </form>
  </div>
</div>

<script>
function adminToast(msg) {
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}

async function sendReminder(id, btn) {
  btn.disabled = true;
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: 'send_reminder', appointment_id: id })
    });
    const d = await res.json();
    adminToast(d.ok ? 'Reminder sent to the patient.' : (d.error || 'Could not send the reminder.'));
  } catch (e) {
    adminToast('Network error: ' + e.message);
  }
  btn.disabled = false;
}

async function handleAppt(id, action) {
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
      msToast('Error: ' + (d.error || 'Action failed'), 'error');
    }
  } catch (e) {
    msToast('Network error: ' + e.message, 'error');
  }
}

async function submitNewAppt(e) {
  e.preventDefault();
  const pid = document.getElementById('naPatient').value;
  const s = document.getElementById('naService').value;
  const d = document.getElementById('naDate').value;
  const t = document.getElementById('naTime').value;

  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'create_appointment',
        patient_id: pid,
        service: s,
        date: d,
        time: t
      })
    });
    const resp = await res.json();
    if (resp.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (resp.error || 'Could not create appointment'), 'error');
    }
  } catch (err) {
    msToast('Network error: ' + err.message, 'error');
  }
}

// Client-side filtering tabs
document.querySelectorAll('#statusFilterTabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#statusFilterTabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const filter = btn.dataset.filter;
    const rows = document.querySelectorAll('.appt-row');
    let visible = 0;
    rows.forEach(r => {
      if (filter === 'all' || r.dataset.status === filter) {
        r.style.display = '';
        visible++;
      } else {
        r.style.display = 'none';
      }
    });
    const emptyMsg = document.getElementById('adminApptTableEmpty');
    if (emptyMsg) emptyMsg.classList.toggle('hidden', visible > 0);
  });
});
</script>
</body>
</html>
