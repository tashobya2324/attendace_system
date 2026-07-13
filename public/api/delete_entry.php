<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['hr', 'admin']);
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid record.']);
    exit;
}

$stmt = db()->prepare('DELETE FROM attendance_records WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
