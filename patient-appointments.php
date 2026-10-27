<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$appts = []; $db_error = null;
try {
    $stmt = db()->prepare("
        SELECT a.id, a.ref, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               s.name AS service_name, b.name AS branch_name,
               COALESCE(d.name, 'Dr. Marilou S. Cariño') AS dentist_name, a.status, a.comments
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        JOIN branches b ON b.id = a.branch_id
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE a.patient_id = :pid
        ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->execute([':pid' => $pid]);
    $appts = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('MSCare DB (appointments): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your appointments right now.');
}

$booked = isset($_GET['booked']) ? preg_replace('/[^A-Z0-9\-]/', '', (string)$_GET['booked']) : '';
$badge = ['Confirmed' => 'b-confirmed', 'Pending' => 'b-pending', 'Completed' => 'b-completed', 'Cancelled' => 'b-cancelled', 'No-show' => 'b-noshow'];

$ptitle = 'My Appointments';
$psub   = '';
$pactive = 'appointments';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($booked): ?>
  <div class="banner ok" role="status">
    <?= icon($ui['check'], 20) ?>
    <div class="b-text"><strong>Appointment requested!</strong><span>Your reference number is <b><?= e($booked) ?></b>. The clinic will confirm it shortly.</span></div>
  </div>
<?php endif; ?>
<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<div class="p-card">
  <div class="p-toolbar">
    <div class="chips" id="pFilterTabs">
      <?php foreach (['all' => 'All', 'Confirmed' => 'Confirmed', 'Pending' => 'Pending', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'] as $k => $label): ?>
        <button type="button" class="chip<?= $k === 'all' ? ' on' : '' ?>" data-filter="<?= e($k) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-primary btn-pill btn-sm" href="patient-book.php"><?= icon($ui['plus'], 16) ?> New appointment</a>
  </div>

  <div class="table-wrap"><table class="p-table stack" id="apptTable">
    <thead><tr><th>Service</th><th>Clinic</th><th>Dentist</th><th>Date &amp; time</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$appts): ?>
      <tr><td colspan="6"><div class="p-empty"><strong>No appointments yet</strong>You have no appointments booked yet.</div></td></tr>
    <?php else: foreach ($appts as $a): ?>
      <tr class="p-appt-row" data-status="<?= e($a['status']) ?>">
        <td data-label="Service" class="strong"><?= e($a['service_name']) ?><?php if ($a['ref']): ?><br><small class="muted"><?= e($a['ref']) ?></small><?php endif; ?></td>
        <td data-label="Clinic"><?= e($a['branch_name']) ?></td>
        <td data-label="Dentist"><?= e($a['dentist_name']) ?></td>
        <td data-label="Date &amp; time" class="nowrap"><?= date('M j, Y', strtotime($a['appointment_date'])) ?>, <?= e($a['time_formatted']) ?></td>
        <td data-label="Status"><span class="badge <?= $badge[$a['status']] ?? 'b-pending' ?>"><?= e($a['status']) ?></span></td>
        <td>
          <?php if (in_array($a['status'], ['Pending', 'Confirmed'], true)): ?>
            <button type="button" class="btn btn-danger btn-sm btn-pill" data-cancel="<?= (int)$a['id'] ?>">Cancel</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <p class="p-empty hidden" id="apptNone" style="padding:18px 0 4px;">No appointments in this filter.</p>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('#pFilterTabs .chip');
  var rows = document.querySelectorAll('.p-appt-row');
  var none = document.getElementById('apptNone');
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabs.forEach(function (b) { b.classList.remove('on'); });
      btn.classList.add('on');
      var f = btn.getAttribute('data-filter'), shown = 0;
      rows.forEach(function (row) {
        var ok = f === 'all' || row.getAttribute('data-status') === f;
        row.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      if (none && rows.length) none.classList.toggle('hidden', shown !== 0);
    });
  });
  document.querySelectorAll('[data-cancel]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var ok = await msConfirm({
        title: 'Cancel this appointment?',
        message: 'The clinic will be told you can no longer make it. You can book another time anytime.',
        confirmText: 'Yes, cancel it', cancelText: 'Keep appointment', danger: true
      });
      if (!ok) return;
      btn.disabled = true;
      fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel_appointment', id: Number(btn.getAttribute('data-cancel')) }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); else { msToast(d.error || 'Could not cancel.', 'error'); btn.disabled = false; } })
        .catch(function (err) { msToast('Network error: ' + err.message); btn.disabled = false; });
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
