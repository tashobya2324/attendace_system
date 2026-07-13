<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role(['hr', 'admin']);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$token = $body['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid or expired session token. Please refresh the page.']);
    exit;
}

$batchId = (int) ($body['batch_id'] ?? 0);
$date = $body['date'] ?? '';
$rows = $body['rows'] ?? [];

if (!$batchId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($rows) || empty($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nothing to commit — check at least one row and try again.']);
    exit;
}

$conn = db();

$stmt = $conn->prepare('SELECT id FROM attendance_import_batches WHERE id = ?');
$stmt->bind_param('i', $batchId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(404);
    echo json_encode(['error' => 'Import batch not found.']);
    exit;
}
$stmt->close();

$validStatus = ['present', 'late', 'absent', 'leave'];
$inserted = 0;

$conn->begin_transaction();
try {
    $upsert = $conn->prepare(
        'INSERT INTO attendance_records (staff_id, attendance_date, check_in, check_out, status, remarks, capture_method, recorded_by)
         VALUES (?,?,?,?,?,?,"ocr_import",?)
         ON DUPLICATE KEY UPDATE
           check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status),
           remarks = VALUES(remarks), capture_method = "ocr_import", recorded_by = VALUES(recorded_by)'
    );

    foreach ($rows as $row) {
        $staffId = (int) ($row['staff_id'] ?? 0);
        $status = in_array($row['status'] ?? '', $validStatus, true) ? $row['status'] : 'present';
        $checkIn = !empty($row['check_in']) ? $row['check_in'] . ':00' : null;
        $checkOut = !empty($row['check_out']) ? $row['check_out'] . ':00' : null;
        $remarks = trim((string) ($row['remarks'] ?? ''));
        if (!$staffId) continue;

        $upsert->bind_param('isssssi', $staffId, $date, $checkIn, $checkOut, $status, $remarks, $user['id']);
        $upsert->execute();
        $inserted++;
    }
    $upsert->close();

    $mark = $conn->prepare("UPDATE attendance_import_batches SET status='committed', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    $mark->bind_param('ii', $user['id'], $batchId);
    $mark->execute();
    $mark->close();

    $conn->commit();
    echo json_encode(['ok' => true, 'inserted' => $inserted]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to commit rows: ' . $e->getMessage()]);
}
