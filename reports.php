<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bell.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$total_appts = 0;
$completed = 0;
$cancelled = 0;
$months = [];
$mix = [];

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE branch_id = :b");
    $stmt->execute([':b' => $branch_id]);
    $total_appts = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE branch_id = :b AND status = 'Completed'");
    $stmt->execute([':b' => $branch_id]);
    $completed = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE branch_id = :b AND status = 'Cancelled'");
    $stmt->execute([':b' => $branch_id]);
    $cancelled = (int)$stmt->fetchColumn();

    // Last 6 months of appointment counts for this clinic.
    $stmt = $pdo->prepare("
        SELECT to_char(date_trunc('month', appointment_date), 'Mon') AS label,
               date_trunc('month', appointment_date) AS m,
               count(*) AS total
        FROM appointments
        WHERE branch_id = :b AND appointment_date >= (CURRENT_DATE - INTERVAL '5 months')
        GROUP BY 1, 2
        ORDER BY 2
    ");
    $stmt->execute([':b' => $branch_id]);
    $by_month = [];
    foreach ($stmt->fetchAll() as $row) {
        $by_month[$row['label']] = (int)$row['total'];
    }
    for ($i = 5; $i >= 0; $i--) {
        $label = date('M', strtotime("-$i months"));
        $months[] = ['label' => $label, 'total' => $by_month[$label] ?? 0];
    }

    $stmt = $pdo->prepare("
        SELECT procedure_name, count(*) AS total, sum(cost) AS revenue
        FROM treatments
        WHERE branch_id = :b
        GROUP BY procedure_name
        ORDER BY total DESC, procedure_name ASC
        LIMIT 4
    ");
    $stmt->execute([':b' => $branch_id]);
    $mix = $stmt->fetchAll();
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$max_month = max(1, ...array_map(static fn($m) => $m['total'], $months ?: [['total' => 0]]));
$mix_total = array_sum(array_map(static fn($m) => (int)$m['total'], $mix)) ?: 1;
$colours = ['#1FA8A0', '#7E6BC9', '#E7A93C', '#E9694C'];
$gradient_parts = [];
$angle = 0.0;
foreach ($mix as $i => $row) {
    $sweep = ((int)$row['total'] / $mix_total) * 360;
    $gradient_parts[] = $colours[$i % count($colours)] . ' ' . $angle . 'deg ' . ($angle + $sweep) . 'deg';
    $angle += $sweep;
}
$donut_bg = $gradient_parts ? 'conic-gradient(' . implode(', ', $gradient_parts) . ')' : 'conic-gradient(#e2e8f0 0deg 360deg)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports &amp; analytics | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261026a">
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
      <a class="nav-item active" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Reports &amp; Analytics</span></a>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
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
      <div><h2>Reports &amp; analytics</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= e($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= e($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <?php if (!empty($db_error)): ?><div class="login-error" style="display:block;"><?= e($db_error) ?></div><?php endif; ?>
      <div class="grid g3" style="margin-bottom:18px;">
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--teal-tint);color:var(--teal-dark);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></div></div>
          <div class="stat-num"><?= $total_appts ?></div><div class="stat-label">Total appointments</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--green-tint);color:var(--green);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div></div>
          <div class="stat-num"><?= $completed ?></div><div class="stat-label">Completed visits</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:var(--coral-tint);color:var(--coral);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></div></div>
          <div class="stat-num"><?= $cancelled ?></div><div class="stat-label">Cancelled visits</div>
        </div>
      </div>
      <div class="grid g2">
        <div class="card">
          <div class="section-head"><h4>Appointments per month</h4></div>
          <div class="bars" style="height:170px;">
            <?php foreach ($months as $m): ?>
              <div class="bar-col">
                <div class="bar" style="height:<?= $m['total'] ? max(4, round($m['total'] / $max_month * 100)) : 3 ?>%;" title="<?= $m['total'] ?> appointments"></div>
                <div class="bar-lbl"><?= e($m['label']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card">
          <div class="section-head"><h4>Treatment mix</h4></div>
          <?php if (!$mix): ?>
            <div class="empty">No treatments recorded for this clinic yet.</div>
          <?php else: ?>
            <div class="donut-wrap">
              <div class="donut" style="background:<?= e($donut_bg) ?>;"></div>
              <div class="donut-legend">
                <?php foreach ($mix as $i => $row): ?>
                  <div class="item"><span class="dot" style="background:<?= $colours[$i % count($colours)] ?>;"></span><?= e($row['procedure_name']) ?><span class="pct"><?= round((int)$row['total'] / $mix_total * 100) ?>%</span></div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
