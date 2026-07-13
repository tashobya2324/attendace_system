<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['hr', 'admin']);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

$conn = db();

// Staff who have not yet checked in today — eligible for the Check In list.
$stmt = $conn->prepare(
    "SELECT s.id, s.full_name, d.name AS dept
     FROM staff s
     JOIN departments d ON d.id = s.department_id
     LEFT JOIN attendance_records ar ON ar.staff_id = s.id AND ar.attendance_date = ?
     WHERE s.status = 'active' AND ar.check_in IS NULL
     ORDER BY s.full_name"
);
$stmt->bind_param('s', $date);
$stmt->execute();
$needCheckin = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Staff who have checked in but not yet checked out — eligible for the Check Out list.
$stmt = $conn->prepare(
    "SELECT s.id, s.full_name, d.name AS dept
     FROM staff s
     JOIN departments d ON d.id = s.department_id
     JOIN attendance_records ar ON ar.staff_id = s.id AND ar.attendance_date = ?
     WHERE s.status = 'active' AND ar.check_in IS NOT NULL AND ar.check_out IS NULL
     ORDER BY s.full_name"
);
$stmt->bind_param('s', $date);
$stmt->execute();
$needCheckout = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['date' => $date, 'need_checkin' => $needCheckin, 'need_checkout' => $needCheckout]);
