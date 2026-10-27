<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$patients = [];
$selected_patient = null;
$prec = null;
$pmed = null;
$pcon = null;
$db_error = null;

try {
    $pdo = db();

    // 1. Fetch patient list with signature approval indicators
    $p_stmt = $pdo->query("
        SELECT p.id, concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name) AS full_name,
               COALESCE(pc.status, 'none') AS consent_status
        FROM patients p
        LEFT JOIN patient_consents pc ON pc.patient_id = p.id
        WHERE p.status = 'Active'
        ORDER BY full_name ASC
    ");
    $patients = $p_stmt->fetchAll();

    $sel_id = (int)($_GET['patient'] ?? ($patients[0]['id'] ?? 0));

    if ($sel_id > 0) {
        $p_query = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
        $p_query->execute([':id' => $sel_id]);
        $selected_patient = $p_query->fetch();

        $r_query = $pdo->prepare("SELECT * FROM patient_records WHERE patient_id = :id");
        $r_query->execute([':id' => $sel_id]);
        $prec = $r_query->fetch() ?: [];

        $m_query = $pdo->prepare("SELECT * FROM patient_medical_histories WHERE patient_id = :id");
        $m_query->execute([':id' => $sel_id]);
        $pmed = $m_query->fetch() ?: [];

        $c_query = $pdo->prepare("SELECT pc.*, adm.display_name AS reviewer_name FROM patient_consents pc LEFT JOIN admins adm ON adm.id = pc.reviewed_by_admin_id WHERE pc.patient_id = :id");
        $c_query->execute([':id' => $sel_id]);
        $pcon = $c_query->fetch() ?: [];
    }

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$c_status = $pcon['status'] ?? 'none';
$badge_class = [
    'approved' => 'b-confirmed',
    'pending'  => 'b-pending',
    'rejected' => 'b-cancelled',
    'none'     => 'b-pending',
][$c_status] ?? 'b-pending';

$status_label = [
    'approved' => 'Approved',
    'pending'  => 'Waiting for Clinic Approval',
    'rejected' => 'Rejected',
    'none'     => 'Not Submitted Yet',
][$c_status] ?? 'Not Done';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Dental Record | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261026a">
<style>
.ar-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
.ar-item { background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; }
.ar-k { font-size: 11.5px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.ar-v { font-size: 13.5px; font-weight: 600; color: var(--navy); margin-top: 3px; }
.ar-v.empty { color: #94a3b8; font-weight: normal; font-style: italic; }
.sig-img-wrap { max-width: 420px; border: 1.5px solid var(--line); border-radius: 10px; padding: 12px; background: #fff; margin: 12px 0; }
.sig-img-wrap img { max-width: 100%; height: auto; display: block; }
</style>
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
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="messages.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Messages</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item active" href="patient-record.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span>Patient Dental Record</span></a>
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
      <div><h2>Patient Dental Record</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <div id="arRoot">
        <div class="card ar-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;">
          <div style="flex:1;max-width:380px;">
            <label class="ar-label" for="arPatientSelect" style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Select Patient</label>
            <select id="arPatientSelect" onchange="location.href='patient-record.php?patient=' + this.value;" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13.5px;">
              <?php foreach ($patients as $pt): ?>
                <option value="<?= (int)$pt['id'] ?>" <?= ($selected_patient && $selected_patient['id'] == $pt['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($pt['full_name']) ?><?= ($pt['consent_status'] === 'pending') ? ' — [Signature Needs Approval]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;gap:10px;align-items:center;">
            <span class="badge <?= $badge_class ?>" style="padding:6px 12px;font-size:12px;">Signature: <?= $status_label ?></span>
            <?php if (!empty($pmed['is_completed'])): ?>
              <span class="badge b-confirmed" style="padding:6px 12px;font-size:12px;">Medical History: Complete</span>
            <?php else: ?>
              <span class="badge b-pending" style="padding:6px 12px;font-size:12px;">Medical History: Pending</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$selected_patient): ?>
          <div class="card"><p style="text-align:center;color:var(--muted);padding:40px;">No patient selected or registered yet.</p></div>
        <?php else: ?>
          <div class="card ar-card" id="arCard" style="margin-top:16px;">
            <div class="tabs ar-tabs">
              <button type="button" class="tab active" onclick="switchArTab(event, 'panel-info')">Patient information</button>
              <button type="button" class="tab" onclick="switchArTab(event, 'panel-medical')">Medical history</button>
              <button type="button" class="tab" onclick="switchArTab(event, 'panel-consent')">Informed consent &amp; signature</button>
            </div>

            <!-- Tab 1: Personal Info -->
            <div id="panel-info" class="ar-panel" style="padding:16px 0;">
              <h4 style="margin-bottom:14px;color:var(--navy);">Patient Information Record</h4>
              <div class="ar-grid">
                <div class="ar-item"><div class="ar-k">Full Name</div><div class="ar-v"><?= e(trim($selected_patient['first_name'] . ' ' . $selected_patient['middle_name'] . ' ' . $selected_patient['last_name'])) ?></div></div>
                <div class="ar-item"><div class="ar-k">Email Address</div><div class="ar-v"><?= htmlspecialchars($selected_patient['email']) ?></div></div>
                <div class="ar-item"><div class="ar-k">Mobile Number</div><div class="ar-v"><?= htmlspecialchars($selected_patient['phone']) ?></div></div>
                <div class="ar-item"><div class="ar-k">Age / Sex</div><div class="ar-v"><?= htmlspecialchars($selected_patient['age'] . ' yrs / ' . ($prec['sex'] ?? $selected_patient['gender'])) ?></div></div>
                <div class="ar-item"><div class="ar-k">Birthdate</div><div class="ar-v <?= empty($prec['birthdate']) ? 'empty' : '' ?>"><?= $prec['birthdate'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Home Address</div><div class="ar-v <?= empty($prec['home_address']) ? 'empty' : '' ?>"><?= $prec['home_address'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Occupation</div><div class="ar-v <?= empty($prec['occupation']) ? 'empty' : '' ?>"><?= $prec['occupation'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Dental Insurance</div><div class="ar-v <?= empty($prec['dental_insurance']) ? 'empty' : '' ?>"><?= $prec['dental_insurance'] ?? 'None' ?></div></div>
                <div class="ar-item"><div class="ar-k">Reason for Consultation</div><div class="ar-v <?= empty($prec['reason_for_consultation']) ? 'empty' : '' ?>"><?= $prec['reason_for_consultation'] ?? 'Not specified' ?></div></div>
              </div>
            </div>

            <!-- Tab 2: Medical History -->
            <div id="panel-medical" class="ar-panel" style="display:none;padding:16px 0;">
              <h4 style="margin-bottom:14px;color:var(--navy);">Comprehensive Health Evaluation</h4>
              <div class="ar-grid">
                <div class="ar-item"><div class="ar-k">Physician Name</div><div class="ar-v <?= empty($pmed['physician_name']) ? 'empty' : '' ?>"><?= $pmed['physician_name'] ?? 'None reported' ?></div></div>
                <div class="ar-item"><div class="ar-k">Blood Type</div><div class="ar-v <?= empty($pmed['blood_type']) ? 'empty' : '' ?>"><?= $pmed['blood_type'] ?? 'Unknown' ?></div></div>
                <div class="ar-item"><div class="ar-k">Blood Pressure</div><div class="ar-v <?= empty($pmed['blood_pressure']) ? 'empty' : '' ?>"><?= $pmed['blood_pressure'] ?? 'Normal' ?></div></div>
                <div class="ar-item"><div class="ar-k">In Good Health?</div><div class="ar-v"><?= (!empty($pmed['in_good_health'])) ? 'Yes' : 'No' ?></div></div>
                <div class="ar-item"><div class="ar-k">Under Treatment?</div><div class="ar-v"><?= (!empty($pmed['under_treatment'])) ? 'Yes (' . htmlspecialchars($pmed['treatment_details'] ?? '') . ')' : 'No' ?></div></div>
                <div class="ar-item"><div class="ar-k">Hospitalized?</div><div class="ar-v"><?= (!empty($pmed['hospitalized'])) ? 'Yes (' . htmlspecialchars($pmed['hospitalized_details'] ?? '') . ')' : 'No' ?></div></div>
                <div class="ar-item" style="grid-column:1/-1;">
                  <div class="ar-k">Reported Allergies</div>
                  <div class="ar-v" style="color:#b91c1c;">
                    <?= !empty($pmed['allergies']) ? htmlspecialchars(is_array($pmed['allergies']) ? implode(', ', $pmed['allergies']) : str_replace(['{','}','"'], '', $pmed['allergies'])) : 'None reported' ?>
                  </div>
                </div>
                <div class="ar-item" style="grid-column:1/-1;">
                  <div class="ar-k">Systemic Conditions</div>
                  <div class="ar-v">
                    <?= !empty($pmed['conditions']) ? htmlspecialchars(is_array($pmed['conditions']) ? implode(', ', $pmed['conditions']) : str_replace(['{','}','"'], '', $pmed['conditions'])) : 'None reported' ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Tab 3: Consent & Signature Review -->
            <div id="panel-consent" class="ar-panel" style="display:none;padding:16px 0;">
              <h4 style="margin-bottom:10px;color:var(--navy);">Informed Consent &amp; Signature Review</h4>
              <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
                Review the patient's signature below. Once approved, the patient's booking lock will immediately release, allowing them to schedule an appointment.
              </p>

              <?php if (empty($pcon['signature_data'])): ?>
                <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:14px;border-radius:10px;font-size:13.5px;">
                  This patient has not uploaded a handwritten signature yet.
                </div>
              <?php else: ?>
                <div class="sig-img-wrap">
                  <img src="<?= htmlspecialchars($pcon['signature_data']) ?>" alt="Patient Signature Preview">
                </div>
                <p style="font-size:12.5px;color:var(--muted);margin-bottom:16px;">
                  <b>Signed on:</b> <?= $pcon['signature_date'] ?? 'N/A' ?> &bull; <b>Current Status:</b> <span class="badge <?= $badge_class ?>"><?= $status_label ?></span>
                  <?php if (!empty($pcon['reviewer_name'])): ?>
                    &bull; <i>Reviewed by <?= htmlspecialchars($pcon['reviewer_name']) ?></i>
                  <?php endif; ?>
                </p>

                <?php if ($c_status === 'pending'): ?>
                  <div style="display:flex;gap:12px;margin-top:16px;">
                    <button type="button" class="btn btn-primary" onclick="reviewConsent(<?= (int)$selected_patient['id'] ?>, 'approve')">
                      Approve Signature
                    </button>
                    <button type="button" class="btn btn-ghost" style="color:#b91c1c;border-color:#fca5a5;" onclick="reviewConsent(<?= (int)$selected_patient['id'] ?>, 'reject')">
                      Reject Signature
                    </button>
                  </div>
                <?php else: ?>
                  <div style="display:flex;gap:12px;margin-top:16px;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="reviewConsent(<?= (int)$selected_patient['id'] ?>, 'approve')">
                      Re-approve
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="reviewConsent(<?= (int)$selected_patient['id'] ?>, 'reject')">
                      Reject
                    </button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>

          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function switchArTab(e, panelId) {
  document.querySelectorAll('.ar-tabs .tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.ar-panel').forEach(p => p.style.display = 'none');
  e.currentTarget.classList.add('active');
  document.getElementById(panelId).style.display = 'block';
}

async function reviewConsent(patientId, decision) {
  let note = '';
  if (decision === 'reject') {
    note = await msPrompt({
      title: 'Reject signature',
      message: 'The patient will see this reason and can upload a new signature.',
      label: 'Reason for rejection',
      value: 'Blurry photo, please re-upload clear signature.',
      confirmText: 'Reject signature', danger: true
    });
    if (!note) return;
  }
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: decision === 'approve' ? 'approve_consent' : 'reject_consent',
        patient_id: patientId,
        note: note
      })
    });
    const d = await res.json();
    if (d.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (d.error || 'Failed to update'), 'error');
    }
  } catch (err) {
    msToast('Network error: ' + err.message, 'error');
  }
}
</script>
</body>
</html>
