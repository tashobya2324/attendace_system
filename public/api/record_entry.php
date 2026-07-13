<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/analysis.php';
$user = require_role(['hr', 'admin']);
verify_csrf();

$staffId = (int) ($_POST['staff_id'] ?? 0);
$date = $_POST['date'] ?? date('Y-m-d');
$action = $_POST['action'] ?? 'in';
$time = $_POST['time'] ?? null;
$method = in_array($_POST['method'] ?? '', ['manual', 'biometric', 'supervisor'], true) ? $_POST['method'] : 'manual';
$remarks = trim($_POST['remarks'] ?? '');

if (!$staffId || !$time || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a staff member, valid date and time.']);
    exit;
}

$conn = db();
$stmt = $conn->prepare('SELECT id FROM staff WHERE id = ? AND status = "active"');
$stmt->bind_param('i', $staffId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(404);
    echo json_encode(['error' => 'Staff member not found.']);
    exit;
}
$stmt->close();

$dayStart = get_setting('day_start_time', '08:00:00');
$grace = (int) get_setting('grace_minutes', 15);
$startMinutes = ((int) substr($dayStart, 0, 2)) * 60 + (int) substr($dayStart, 3, 2);
[$hh, $mm] = array_map('intval', explode(':', $time));
$arrivalMinutes = $hh * 60 + $mm;

$existing = $conn->prepare('SELECT * FROM attendance_records WHERE staff_id = ? AND attendance_date = ?');
$existing->bind_param('is', $staffId, $date);
$existing->execute();
$row = $existing->get_result()->fetch_assoc();
$existing->close();

$timeSql = $time . ':00';

if ($action === 'in') {
    $lateMinutes = max(0, $arrivalMinutes - ($startMinutes + $grace));
    $status = $lateMinutes > 0 ? 'late' : 'present';
    if ($row) {
        $stmt = $conn->prepare('UPDATE attendance_records SET check_in=?, status=?, late_minutes=?, capture_method=?, remarks=IF(?<>"",?,remarks), recorded_by=? WHERE id=?');
        $stmt->bind_param('ssisssii', $timeSql, $status, $lateMinutes, $method, $remarks, $remarks, $user['id'], $row['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO attendance_records (staff_id, attendance_date, check_in, status, late_minutes, remarks, capture_method, recorded_by) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->bind_param('isssissi', $staffId, $date, $timeSql, $status, $lateMinutes, $remarks, $method, $user['id']);
        $stmt->execute();
        $stmt->close();
    }
} else {
    if ($row) {
        $stmt = $conn->prepare('UPDATE attendance_records SET check_out=?, capture_method=?, remarks=IF(?<>"",?,remarks), recorded_by=? WHERE id=?');
        $stmt->bind_param('ssssii', $timeSql, $method, $remarks, $remarks, $user['id'], $row['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $status = 'present';
        $stmt = $conn->prepare('INSERT INTO attendance_records (staff_id, attendance_date, check_out, status, remarks, capture_method, recorded_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('isssssi', $staffId, $date, $timeSql, $status, $remarks, $method, $user['id']);
        $stmt->execute();
        $stmt->close();
    }
}

echo json_encode(['ok' => true]);
