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
$totalStaff = (int) $conn->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch_assoc()['c'];

$stmt = $conn->prepare(
    "SELECT ar.id, ar.staff_id, s.full_name, s.staff_no, s.designation, d.name AS dept,
            ar.status, ar.check_in, ar.check_out, ar.remarks, ar.capture_method
     FROM attendance_records ar
     JOIN staff s ON s.id = ar.staff_id
     JOIN departments d ON d.id = s.department_id
     WHERE ar.attendance_date = ?
     ORDER BY ar.check_in IS NULL, ar.check_in"
);
$stmt->bind_param('s', $date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
foreach ($rows as $r) { $counts[$r['status']]++; }

foreach ($rows as &$r) {
    if ($r['check_in'] && $r['check_out']) {
        $in = strtotime($r['check_in']);
        $out = strtotime($r['check_out']);
        $r['hours'] = round(max(0, $out - $in) / 3600, 1);
    } else {
        $r['hours'] = null;
    }
}

echo json_encode([
    'date' => $date,
    'total_staff' => $totalStaff,
    'not_logged' => max(0, $totalStaff - count($rows)),
    'counts' => $counts,
    'rows' => $rows,
]);
