<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

if (empty($_SESSION['messages_csrf'])) $_SESSION['messages_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['messages_csrf'];

$filter = in_array($_GET['f'] ?? '', ['unread', 'replied'], true) ? $_GET['f'] : 'all';
$db_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Session expired. Please try again.');
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['action'] ?? '';
        $pdo = db();
        if ($act === 'read' || $act === 'unread') {
            $q = $pdo->prepare("UPDATE contact_messages SET is_read = :v WHERE id = :id AND branch_id = :b");
            $q->execute([':v' => $act === 'read' ? 'true' : 'false', ':id' => $id, ':b' => $branch_id]);
        } elseif ($act === 'replied') {
            $q = $pdo->prepare("UPDATE contact_messages SET replied_at = now(), replied_by_admin_id = :a, is_read = true
                                WHERE id = :id AND branch_id = :b");
            $q->execute([':a' => admin_id(), ':id' => $id, ':b' => $branch_id]);
        } elseif ($act === 'read_all') {
            $pdo->prepare("UPDATE contact_messages SET is_read = true WHERE branch_id = :b")->execute([':b' => $branch_id]);
        }
        flash('Message updated.');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    redirect_to('messages.php' . ($filter !== 'all' ? '?f=' . $filter : ''));
}

$flash = take_flash();
$messages = [];
$counts = ['all' => 0, 'unread' => 0, 'replied' => 0];
try {
    $pdo = db();
    $c = $pdo->prepare("SELECT count(*) AS total,
                               count(*) FILTER (WHERE NOT is_read) AS unread,
                               count(*) FILTER (WHERE replied_at IS NOT NULL) AS replied
                        FROM contact_messages WHERE branch_id = :b");
    $c->execute([':b' => $branch_id]);
    $r = $c->fetch();
    $counts = ['all' => (int)$r['total'], 'unread' => (int)$r['unread'], 'replied' => (int)$r['replied']];

    $where = $filter === 'unread' ? 'AND NOT m.is_read' : ($filter === 'replied' ? 'AND m.replied_at IS NOT NULL' : '');
    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.mobile, m.email, m.message, m.is_read, m.replied_at,
               to_char(m.created_at, 'Mon DD, YYYY · HH12:MI AM') AS sent_at,
               to_char(m.replied_at, 'Mon DD, YYYY') AS replied_on,
               a.display_name AS replied_by
        FROM contact_messages m
        LEFT JOIN admins a ON a.id = m.replied_by_admin_id
        WHERE m.branch_id = :b $where
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([':b' => $branch_id]);
    $messages = $stmt->fetchAll();
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261026a">
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
      <a class="nav-item active" href="messages.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Messages</span></a>
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
      <div><h2>Messages</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:14px;padding:12px 16px;"><?= e($flash['message']) ?></div><?php endif; ?>
      <?php if ($db_error): ?><div class="card" style="margin-bottom:14px;padding:12px 16px;color:var(--coral);">Could not load messages: <?= e($db_error) ?></div><?php endif; ?>
      <div class="card">
        <div class="toolbar">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-ghost' ?>" href="messages.php">All (<?= $counts['all'] ?>)</a>
            <a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-ghost' ?>" href="messages.php?f=unread">Unread (<?= $counts['unread'] ?>)</a>
            <a class="btn btn-sm <?= $filter === 'replied' ? 'btn-primary' : 'btn-ghost' ?>" href="messages.php?f=replied">Replied (<?= $counts['replied'] ?>)</a>
          </div>
          <?php if ($counts['unread'] > 0): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="read_all">
            <button class="btn btn-sm btn-ghost" type="submit">Mark all as read</button>
          </form>
          <?php endif; ?>
        </div>
        <table>
          <thead><tr><th>From</th><th>Message</th><th>Received</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$messages): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:40px;">No messages yet.</td></tr>
          <?php else: foreach ($messages as $m): ?>
            <tr class="row-hover">
              <td style="min-width:170px;">
                <div class="name" style="<?= $m['is_read'] ? '' : 'font-weight:700;' ?>"><?= e($m['name']) ?></div>
                <div class="sub"><?= e($m['mobile']) ?></div>
                <?php if ($m['email']): ?><div class="sub"><?= e($m['email']) ?></div><?php endif; ?>
              </td>
              <td style="max-width:380px;white-space:pre-wrap;word-break:break-word;"><?= e($m['message']) ?></td>
              <td style="white-space:nowrap;font-size:12.5px;"><?= e($m['sent_at']) ?></td>
              <td>
                <?php if ($m['replied_at']): ?>
                  <span class="badge b-confirmed">Replied</span>
                  <div class="sub"><?= e($m['replied_on']) ?><?= $m['replied_by'] ? ' · ' . e($m['replied_by']) : '' ?></div>
                <?php elseif (!$m['is_read']): ?>
                  <span class="badge b-pending">New</span>
                <?php else: ?>
                  <span class="badge b-crown">Read</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php if ($m['email']): ?>
                    <a class="btn btn-sm btn-ghost" href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: Your message to MSCare Dental Clinic') ?>">Email</a>
                  <?php endif; ?>
                  <a class="btn btn-sm btn-ghost" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['mobile'])) ?>">Call</a>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-sm btn-ghost" name="action" value="<?= $m['is_read'] ? 'unread' : 'read' ?>" type="submit"><?= $m['is_read'] ? 'Mark unread' : 'Mark read' ?></button>
                    <?php if (!$m['replied_at']): ?><button class="btn btn-sm btn-primary" name="action" value="replied" type="submit">Mark replied</button><?php endif; ?>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
