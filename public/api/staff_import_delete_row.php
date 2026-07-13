<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['hr', 'admin']);
verify_csrf();

$rowId = (int) ($_POST['row_id'] ?? 0);
if (!$rowId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid row.']);
    exit;
}

$stmt = db()->prepare('DELETE FROM staff_import_rows WHERE id = ?');
$stmt->bind_param('i', $rowId);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
