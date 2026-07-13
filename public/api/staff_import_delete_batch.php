<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['hr', 'admin']);
verify_csrf();

$batchId = (int) ($_POST['batch_id'] ?? 0);
if (!$batchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid batch.']);
    exit;
}

$conn = db();
$stmt = $conn->prepare('SELECT stored_filename, status FROM staff_import_batches WHERE id = ?');
$stmt->bind_param('i', $batchId);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    http_response_code(404);
    echo json_encode(['error' => 'Import batch not found.']);
    exit;
}
if ($batch['status'] === 'committed') {
    http_response_code(400);
    echo json_encode(['error' => 'This batch was already committed and cannot be deleted.']);
    exit;
}

$del = $conn->prepare('DELETE FROM staff_import_batches WHERE id = ?');
$del->bind_param('i', $batchId);
$del->execute();
$del->close();

$path = __DIR__ . '/../../storage/staff_imports/' . $batch['stored_filename'];
if (is_file($path)) @unlink($path);

echo json_encode(['ok' => true]);
