<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini.php';
require_once __DIR__ . '/../../includes/import_matching.php';
$user = require_role(['hr', 'admin']);
verify_csrf();

$registerDate = $_POST['register_date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $registerDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a valid date for this register.']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a register photo to upload.']);
    exit;
}

$file = $_FILES['image'];
$allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedMime[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPG or PNG images are supported.']);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Image is too large (max 10 MB).']);
    exit;
}

$storageDir = __DIR__ . '/../../storage/attendance_imports';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}
$storedName = 'reg_' . $registerDate . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMime[$mime];
$destPath = $storageDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the uploaded image.']);
    exit;
}

$extraction = gemini_extract_register($destPath, $mime);

$conn = db();
$conn->begin_transaction();
try {
    $status = $extraction['ok'] ? 'pending_review' : 'pending_review';
    $stmt = $conn->prepare(
        'INSERT INTO attendance_import_batches
            (register_date, original_filename, stored_filename, uploaded_by, ai_model, ai_raw_response, status, error_message)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $origName = $file['name'];
    $model = GEMINI_MODEL;
    $raw = $extraction['raw'];
    $errorMsg = $extraction['error'];
    $stmt->bind_param('sssissss', $registerDate, $origName, $storedName, $user['id'], $model, $raw, $status, $errorMsg);
    $stmt->execute();
    $batchId = $stmt->insert_id;
    $stmt->close();

    if ($extraction['ok']) {
        $activeStaff = $conn->query("SELECT id, full_name FROM staff WHERE status='active'")->fetch_all(MYSQLI_ASSOC);

        $insertRow = $conn->prepare(
            'INSERT INTO attendance_import_rows
                (batch_id, row_index, raw_name_text, raw_check_in, raw_check_out, raw_remarks, matched_staff_id, match_confidence)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($extraction['rows'] as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') continue;
            $checkIn = $row['check_in'] ?? null;
            $checkOut = $row['check_out'] ?? null;
            $remarks = $row['remarks'] ?? null;

            $match = match_staff_by_name($name, $activeStaff);
            $matchedId = $match['staff_id'];
            $confidence = $match['score'];

            $insertRow->bind_param('iissssid', $batchId, $i, $name, $checkIn, $checkOut, $remarks, $matchedId, $confidence);
            $insertRow->execute();
        }
        $insertRow->close();
    }

    $conn->commit();
    echo json_encode(['ok' => true, 'batch_id' => $batchId, 'extraction_ok' => $extraction['ok'], 'extraction_error' => $extraction['error']]);
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save the import batch: ' . $e->getMessage()]);
}
