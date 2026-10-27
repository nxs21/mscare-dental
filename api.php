<?php
// MSCare - small JSON API for actions used by the patient pages.
// Every action requires a real signed-in patient session; there is no demo
// patient fallback (that fallback used to show another patient's name/data).
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$admin = current_admin();
$patient = current_patient();
$pid = $patient ? patient_id() : 0;
$aid = $admin ? admin_id() : 0;
$isAdmin = (bool)$admin;

if (!$patient && !$admin) {
    respond(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], 401);
}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = db();

    // ---------- Admin: fetch notifications ----------
    if ($action === 'fetch_admin_notifications') {
        if (!$admin) respond(['ok' => false, 'error' => 'Admin session required.'], 403);
        $rows = $pdo->prepare("SELECT id, title, message, is_read, created_at, patient_id, appointment_id FROM notifications WHERE (admin_id IS NULL OR admin_id = :aid) ORDER BY created_at DESC, id DESC LIMIT 50");
        $rows->execute([':aid' => $aid]);
        $unread = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (admin_id IS NULL OR admin_id = :aid) AND is_read = FALSE")->execute([':aid' => $aid])->fetchColumn();
        respond(['ok' => true, 'notifications' => $rows->fetchAll(), 'unread' => $unread]);
    }

    // ---------- Admin: mark notification(s) read ----------
    if ($action === 'mark_admin_notifications_read') {
        if (!$admin) respond(['ok' => false, 'error' => 'Admin session required.'], 403);
        $nid = (int)($data['id'] ?? 0);
        if ($nid > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND (admin_id IS NULL OR admin_id = :aid)");
            $stmt->execute([':id' => $nid, ':aid' => $aid]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE (admin_id IS NULL OR admin_id = :aid) AND is_read = FALSE");
            $stmt->execute([':aid' => $aid]);
        }
        respond(['ok' => true]);
    }

    // ---------- Patient: cancel appointment ----------
    if ($action === 'cancel_appointment') {
        if (!$patient) respond(['ok' => false, 'error' => 'Patient session required.'], 403);
        $apptId = (int)($data['id'] ?? 0);
        if ($apptId <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

        $stmt = $pdo->prepare("
            UPDATE appointments
               SET status = 'Cancelled', admin_notes = 'Cancelled by patient via portal', updated_at = now()
                WHERE id = :aid AND patient_id = :pid AND status IN ('Pending', 'Confirmed')
        ");
        $stmt->execute([':aid' => $apptId, ':pid' => $pid]);
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => 'That appointment can no longer be cancelled.']);
        }
        respond(['ok' => true]);
    }




              




    // Booking is done through patient-book.php (full validation lives there).

    respond(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (PDOException $ex) {
    if ($ex->getCode() === '23505') {
        respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
    }
    error_log('MSCare DB (api): ' . $ex->getMessage());
    respond(['ok' => false, 'error' => db_fail_text($ex, 'Something went wrong. Please try again.')], 500);
}
