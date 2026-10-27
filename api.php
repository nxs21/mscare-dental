<?php
// MSCare Admin - JSON API used by the admin pages.
// Every action requires a signed-in admin session; actions are limited to the
// admin's own clinic branch.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';

header('Content-Type: application/json; charset=utf-8');

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}


/** Is a clinic notification switch on? (Settings > Notifications). Defaults to on. */
function notify_enabled($pdo, $branch_id, $key) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE branch_id = :b AND setting_key = :k");
        $st->execute([':b' => $branch_id, ':k' => $key]);
        $v = $st->fetchColumn();
        return $v === false ? true : $v === '1';
    } catch (PDOException $ex) {
        return true; // settings table not created yet -> default on
    }
}

/** Put a message in the patient's notification bell (portal). Never blocks the main action. */
function notify_patient($pdo, $appt_id, $branch_id, $title, $message) {
    try {
        $st = $pdo->prepare("SELECT patient_id FROM appointments WHERE id = :id AND branch_id = :b");
        $st->execute([':id' => $appt_id, ':b' => $branch_id]);
        $pid = $st->fetchColumn();
        if (!$pid) return false;
        $ins = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message) VALUES (:p, :a, :t, :m)");
        $ins->execute([':p' => $pid, ':a' => $appt_id, ':t' => $title, ':m' => $message]);
        return true;
    } catch (PDOException $ex) {
        error_log('MSCare notify_patient: ' . $ex->getMessage());
        return false;
    }
}

/** "Cleaning on Oct 30, 2026 at 8:00 AM" */
function appt_summary($pdo, $appt_id) {
    $st = $pdo->prepare("SELECT s.name, a.appointment_date, a.appointment_time FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.id = :id");
    $st->execute([':id' => $appt_id]);
    $r = $st->fetch();
    if (!$r) return 'your appointment';
    return $r['name'] . ' on ' . date('M j, Y', strtotime($r['appointment_date'])) . ' at ' . date('g:i A', strtotime($r['appointment_time']));
}

if (!current_admin()) {
    respond(['ok' => false, 'error' => 'Your admin session has expired. Please log in again.'], 401);
}

$admin = current_admin();
$admin_id = admin_id();
$branch_id = admin_clinic_id();
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = db();

    switch ($action) {
        case 'confirm_appointment':
        case 'complete_appointment':
        case 'cancel_appointment':
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

            $status = [
                'confirm_appointment'  => 'Confirmed',
                'complete_appointment' => 'Completed',
                'cancel_appointment'   => 'Cancelled',
            ][$action];

            $sql = "UPDATE appointments SET status = :status, reviewed_by_admin_id = :adm, updated_at = now()";
            $params = [':status' => $status, ':adm' => $admin_id, ':id' => $id, ':bid' => $branch_id];
            if ($action === 'cancel_appointment') {
                $sql .= ", admin_notes = :note";
                $params[':note'] = trim((string)($data['reason'] ?? 'Cancelled by clinic admin'));
            }
            $sql .= " WHERE id = :id AND branch_id = :bid";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                respond(['ok' => false, 'error' => 'That appointment was not found for your clinic.']);
            }
            if ($action !== 'complete_appointment' && notify_enabled($pdo, $branch_id, 'n_patient_confirm')) {
                $what = appt_summary($pdo, $id);
                if ($action === 'confirm_appointment') {
                    notify_patient($pdo, $id, $branch_id, 'Appointment confirmed', 'Your ' . $what . ' is confirmed. See you at the clinic!');
                } else {
                    notify_patient($pdo, $id, $branch_id, 'Appointment cancelled', 'Your ' . $what . ' was cancelled by the clinic. Please book another time.');
                }
            }
            respond(['ok' => true]);

        case 'reschedule_appointment':
            $id = (int)($data['id'] ?? 0);
            $date = trim((string)($data['date'] ?? ''));
            $time = trim((string)($data['time'] ?? ''));
            if ($id <= 0 || $date === '' || $time === '') {
                respond(['ok' => false, 'error' => 'Please provide both date and time.']);
            }
            $time_sql = date('H:i', strtotime($time));
            $stmt = $pdo->prepare("UPDATE appointments
                                      SET appointment_date = :d, appointment_time = :t, reviewed_by_admin_id = :adm
                                    WHERE id = :id AND branch_id = :bid");
            $stmt->execute([':d' => $date, ':t' => $time_sql, ':adm' => $admin_id, ':id' => $id, ':bid' => $branch_id]);
            if ($stmt->rowCount() === 0) respond(['ok' => false, 'error' => 'Appointment not found.']);
            if (notify_enabled($pdo, $branch_id, 'n_patient_confirm')) {
                notify_patient($pdo, $id, $branch_id, 'Appointment rescheduled', 'Your appointment was moved: ' . appt_summary($pdo, $id) . '.');
            }
            respond(['ok' => true]);

        case 'create_appointment':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $service = trim((string)($data['service'] ?? ''));
            $date = trim((string)($data['date'] ?? ''));
            $time = trim((string)($data['time'] ?? ''));

            if ($patient_id <= 0 || $date === '' || $time === '') {
                respond(['ok' => false, 'error' => 'Patient, date and time are required.']);
            }

            $p_stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :pid AND status = 'Active'");
            $p_stmt->execute([':pid' => $patient_id]);
            $p = $p_stmt->fetch();
            if (!$p) respond(['ok' => false, 'error' => 'Patient not found.']);

            $s_stmt = $pdo->prepare("SELECT id FROM services WHERE name = :s AND is_active = TRUE");
            $s_stmt->execute([':s' => $service]);
            $service_id = $s_stmt->fetchColumn();
            if (!$service_id) respond(['ok' => false, 'error' => 'Please choose a valid service.']);

            $ref = 'MSC-' . strtoupper(bin2hex(random_bytes(3)));
            $time_sql = date('H:i', strtotime($time));
            $dentist_id = $pdo->query("SELECT id FROM dentists WHERE is_active = TRUE ORDER BY id LIMIT 1")->fetchColumn();

            $ins = $pdo->prepare("
                INSERT INTO appointments
                    (ref, patient_id, branch_id, service_id, dentist_id, appointment_date, appointment_time,
                     first_name, last_name, phone, email, is_current_patient, status, reviewed_by_admin_id)
                VALUES
                    (:ref, :pid, :bid, :sid, :did, :d, :t, :fn, :ln, :ph, :em, TRUE, 'Confirmed', :adm)
            ");
            $ins->execute([
                ':ref' => $ref, ':pid' => $p['id'], ':bid' => $branch_id,
                ':sid' => (int)$service_id, ':did' => $dentist_id ?: null,
                ':d' => $date, ':t' => $time_sql,
                ':fn' => $p['first_name'], ':ln' => $p['last_name'],
                ':ph' => $p['phone'], ':em' => $p['email'], ':adm' => $admin_id,
            ]);
            respond(['ok' => true, 'ref' => $ref]);

        case 'approve_consent':
        case 'reject_consent':
            $patient_id = (int)($data['patient_id'] ?? 0);
            if ($patient_id <= 0) respond(['ok' => false, 'error' => 'Invalid patient.']);

            $status = $action === 'approve_consent' ? 'approved' : 'rejected';
            $note = $action === 'reject_consent'
                ? trim((string)($data['note'] ?? 'Signature not approved. Please upload again.'))
                : null;

            $stmt = $pdo->prepare("UPDATE patient_consents
                                      SET status = :st, rejection_note = :note,
                                          reviewed_by_admin_id = :adm, reviewed_at = now()
                                    WHERE patient_id = :pid");
            $stmt->execute([':st' => $status, ':note' => $note, ':adm' => $admin_id, ':pid' => $patient_id]);
            if ($stmt->rowCount() === 0) respond(['ok' => false, 'error' => 'This patient has not submitted a consent form yet.']);
            respond(['ok' => true]);

        case 'reset_sig_tries':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE patient_consents SET sig_tries_used = 0 WHERE patient_id = :pid");
            $stmt->execute([':pid' => $patient_id]);
            respond(['ok' => true]);

        case 'log_treatment':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $date = trim((string)($data['date'] ?? date('Y-m-d')));
            $proc = trim((string)($data['procedure'] ?? ''));
            $tooth = trim((string)($data['tooth'] ?? '—'));
            $cost = (float)($data['cost'] ?? 0);
            $notes = trim((string)($data['notes'] ?? ''));
            $dentist = trim((string)($data['dentist'] ?? 'Dr. Marilou S. Cariño'));

            if ($patient_id <= 0 || $proc === '') {
                respond(['ok' => false, 'error' => 'Patient and Procedure are required.']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO treatments
                    (patient_id, branch_id, treatment_date, procedure_name, tooth, dentist_name,
                     cost, notes, created_by_admin_id)
                VALUES (:pid, :bid, :d, :pr, :th, :dt, :c, :nt, :adm)
            ");
            $stmt->execute([
                ':pid' => $patient_id, ':bid' => $branch_id, ':d' => $date,
                ':pr' => $proc, ':th' => $tooth, ':dt' => $dentist,
                ':c' => $cost, ':nt' => $notes, ':adm' => $admin_id,
            ]);
            respond(['ok' => true]);

        case 'save_tooth':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $tooth_num = (int)($data['tooth_number'] ?? 0);
            $code = trim((string)($data['condition_code'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));

            if ($patient_id <= 0 || $tooth_num < 11 || $tooth_num > 85) {
                respond(['ok' => false, 'error' => 'Invalid patient or tooth number.']);
            }

            if ($code === '' || strtolower($code) === 'none') {
                $stmt = $pdo->prepare("DELETE FROM dental_charts WHERE patient_id = :pid AND tooth_number = :tn");
                $stmt->execute([':pid' => $patient_id, ':tn' => $tooth_num]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dental_charts (patient_id, tooth_number, condition_code, notes, updated_by_admin_id)
                    VALUES (:pid, :tn, :c, :nt, :adm)
                    ON CONFLICT (patient_id, tooth_number) DO UPDATE SET
                        condition_code = EXCLUDED.condition_code,
                        notes = EXCLUDED.notes,
                        updated_by_admin_id = EXCLUDED.updated_by_admin_id
                ");
                $stmt->execute([':pid' => $patient_id, ':tn' => $tooth_num, ':c' => $code, ':nt' => $notes, ':adm' => $admin_id]);
            }
            respond(['ok' => true]);

        case 'mark_notif_read':
            // Admin bell: remember which notifications this admin has opened.
            admin_bell_ensure_table($pdo);
            if (!empty($data['all'])) {
                $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key)
                               SELECT :a, 'p' || id FROM appointments WHERE branch_id = :b AND status = 'Pending'
                               ON CONFLICT DO NOTHING")->execute([':a' => $admin_id, ':b' => $branch_id]);
                $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key)
                               SELECT :a, 'c' || id FROM appointments
                                WHERE branch_id = :b AND status = 'Cancelled' AND admin_notes LIKE 'Cancelled by patient%'
                               ON CONFLICT DO NOTHING")->execute([':a' => $admin_id, ':b' => $branch_id]);
                respond(['ok' => true]);
            }
            $key = (string)($data['key'] ?? '');
            if (!preg_match('/^[pc]\d{1,18}$/', $key)) respond(['ok' => false, 'error' => 'Invalid notification.']);
            $own = $pdo->prepare("SELECT 1 FROM appointments WHERE id = :id AND branch_id = :b");
            $own->execute([':id' => (int)substr($key, 1), ':b' => $branch_id]);
            if (!$own->fetchColumn()) respond(['ok' => false, 'error' => 'Notification not found.']);
            $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key) VALUES (:a, :k) ON CONFLICT DO NOTHING")
                ->execute([':a' => $admin_id, ':k' => $key]);
            respond(['ok' => true]);

        case 'send_reminder':
            // Sends a reminder to the patient's notification bell for one upcoming appointment.
            $appt_id = (int)($data['appointment_id'] ?? $data['id'] ?? 0);
            if ($appt_id <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

            $chk = $pdo->prepare("SELECT patient_id, status, appointment_date FROM appointments WHERE id = :id AND branch_id = :b");
            $chk->execute([':id' => $appt_id, ':b' => $branch_id]);
            $row = $chk->fetch();
            if (!$row) respond(['ok' => false, 'error' => 'That appointment was not found for your clinic.']);
            if (!$row['patient_id']) respond(['ok' => false, 'error' => 'This booking is not linked to a patient account, so there is no portal to notify.']);
            if (!in_array($row['status'], ['Pending', 'Confirmed'], true)) respond(['ok' => false, 'error' => 'Reminders can only be sent for pending or confirmed appointments.']);

            $ok = notify_patient($pdo, $appt_id, $branch_id, 'Appointment reminder',
                'Reminder: ' . appt_summary($pdo, $appt_id) . '. Please arrive 10 minutes early.');
            if (!$ok) respond(['ok' => false, 'error' => 'Could not send the reminder.'], 500);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (PDOException $ex) {
    if ($ex->getCode() === '23505') {
        respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
    }
    respond(['ok' => false, 'error' => 'Database error: ' . $ex->getMessage()], 500);
}
