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
$rows = $body['rows'] ?? [];
if (!$batchId || !is_array($rows) || empty($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nothing to commit — check at least one row and try again.']);
    exit;
}

$conn = db();
$stmt = $conn->prepare('SELECT id FROM staff_import_batches WHERE id = ?');
$stmt->bind_param('i', $batchId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(404);
    echo json_encode(['error' => 'Import batch not found.']);
    exit;
}
$stmt->close();

// Next available MB-#### for rows with no staff number supplied.
$next = (int) substr(($conn->query('SELECT staff_no FROM staff ORDER BY id DESC LIMIT 1')->fetch_assoc()['staff_no'] ?? 'MB-0000'), 3);

$newDeptCache = []; // lowercased name => id, so duplicate "new department" names in one batch reuse the same row
$inserted = 0;
$skipped = 0;

$conn->begin_transaction();
try {
    $findDept = $conn->prepare('SELECT id FROM departments WHERE name = ?');
    $createDept = $conn->prepare('INSERT INTO departments (name) VALUES (?)');
    $checkStaffNo = $conn->prepare('SELECT id FROM staff WHERE staff_no = ?');
    $insertStaff = $conn->prepare(
        'INSERT INTO staff (staff_no, full_name, designation, department_id, date_joined) VALUES (?,?,?,?,CURDATE())'
    );

    foreach ($rows as $row) {
        $name = trim((string) ($row['full_name'] ?? ''));
        if ($name === '') continue;

        $deptId = (int) ($row['department_id'] ?? 0);
        $newDeptName = trim((string) ($row['new_department_name'] ?? ''));

        if (!$deptId && $newDeptName !== '') {
            $key = strtolower($newDeptName);
            if (isset($newDeptCache[$key])) {
                $deptId = $newDeptCache[$key];
            } else {
                $findDept->bind_param('s', $newDeptName);
                $findDept->execute();
                $existing = $findDept->get_result()->fetch_assoc();
                if ($existing) {
                    $deptId = (int) $existing['id'];
                } else {
                    $createDept->bind_param('s', $newDeptName);
                    $createDept->execute();
                    $deptId = $createDept->insert_id;
                }
                $newDeptCache[$key] = $deptId;
            }
        }
        if (!$deptId) { $skipped++; continue; } // no department resolved — skip rather than guess

        $designation = trim((string) ($row['designation'] ?? '')) ?: 'Staff';
        $staffNo = trim((string) ($row['staff_no'] ?? ''));

        if ($staffNo !== '') {
            $checkStaffNo->bind_param('s', $staffNo);
            $checkStaffNo->execute();
            if ($checkStaffNo->get_result()->fetch_assoc()) {
                $skipped++;
                continue; // duplicate staff number — HR must resolve manually
            }
        } else {
            $next++;
            $staffNo = 'MB-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }

        $insertStaff->bind_param('sssi', $staffNo, $name, $designation, $deptId);
        $insertStaff->execute();
        $inserted++;
    }

    $findDept->close();
    $createDept->close();
    $checkStaffNo->close();
    $insertStaff->close();

    $mark = $conn->prepare("UPDATE staff_import_batches SET status='committed', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    $mark->bind_param('ii', $user['id'], $batchId);
    $mark->execute();
    $mark->close();

    $conn->commit();
    echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to commit rows: ' . $e->getMessage()]);
}
