<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini.php';
require_once __DIR__ . '/../../includes/doc_parsing.php';
require_once __DIR__ . '/../../includes/import_matching.php';
$user = require_role(['hr', 'admin']);
verify_csrf();

if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a document to upload.']);
    exit;
}

$file = $_FILES['document'];
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File is too large (max 10 MB).']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$typeMap = ['csv' => 'csv', 'xlsx' => 'xlsx', 'docx' => 'docx', 'pdf' => 'pdf', 'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image'];
if (!isset($typeMap[$ext])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported file type. Use CSV, XLSX, DOCX, PDF, JPG, or PNG.']);
    exit;
}
$fileType = $typeMap[$ext];

$storageDir = __DIR__ . '/../../storage/staff_imports';
if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);
$storedName = 'staff_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath = $storageDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the uploaded file.']);
    exit;
}

$extractionMethod = 'ai';
$aiRaw = null;
$rows = [];
$extractionError = null;

switch ($fileType) {
    case 'csv':
        $rows2D = read_csv_rows($destPath);
        $direct = heuristic_parse_staff_rows($rows2D);
        if ($direct !== null) {
            $extractionMethod = 'direct_parse';
            $rows = $direct;
        } else {
            $result = gemini_extract_staff_list(null, null, rows2D_to_text($rows2D));
            $aiRaw = $result['raw'];
            $rows = $result['rows'];
            $extractionError = $result['error'];
        }
        break;

    case 'xlsx':
        $rows2D = read_xlsx_rows($destPath);
        $direct = heuristic_parse_staff_rows($rows2D);
        if ($direct !== null) {
            $extractionMethod = 'direct_parse';
            $rows = $direct;
        } else {
            $result = gemini_extract_staff_list(null, null, rows2D_to_text($rows2D));
            $aiRaw = $result['raw'];
            $rows = $result['rows'];
            $extractionError = $result['error'];
        }
        break;

    case 'docx':
        $text = extract_docx_text($destPath);
        $rows2D = array_map(fn($line) => explode("\t", $line), explode("\n", $text));
        $direct = heuristic_parse_staff_rows($rows2D);
        if ($direct !== null) {
            $extractionMethod = 'direct_parse';
            $rows = $direct;
        } else {
            $result = gemini_extract_staff_list(null, null, $text);
            $aiRaw = $result['raw'];
            $rows = $result['rows'];
            $extractionError = $result['error'];
        }
        break;

    case 'pdf':
        $result = gemini_extract_staff_list($destPath, 'application/pdf', null);
        $aiRaw = $result['raw'];
        $rows = $result['rows'];
        $extractionError = $result['error'];
        break;

    case 'image':
        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
        $result = gemini_extract_staff_list($destPath, $mime, null);
        $aiRaw = $result['raw'];
        $rows = $result['rows'];
        $extractionError = $result['error'];
        break;
}

$conn = db();
$conn->begin_transaction();
try {
    $origName = $file['name'];
    $model = $extractionMethod === 'ai' ? GEMINI_MODEL : null;
    $stmt = $conn->prepare(
        'INSERT INTO staff_import_batches
            (original_filename, stored_filename, file_type, extraction_method, uploaded_by, ai_model, ai_raw_response, status, error_message)
         VALUES (?,?,?,?,?,?,?,"pending_review",?)'
    );
    $stmt->bind_param('ssssisss', $origName, $storedName, $fileType, $extractionMethod, $user['id'], $model, $aiRaw, $extractionError);
    $stmt->execute();
    $batchId = $stmt->insert_id;
    $stmt->close();

    if (!empty($rows)) {
        $departments = $conn->query('SELECT id, name FROM departments')->fetch_all(MYSQLI_ASSOC);
        $insertRow = $conn->prepare(
            'INSERT INTO staff_import_rows
                (batch_id, row_index, raw_full_name, raw_department, raw_designation, raw_staff_no, matched_department_id, department_match_confidence)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name === '') continue;
            $dept = $row['department'] ?? null;
            $designation = $row['designation'] ?? null;
            $staffNo = $row['staff_no'] ?? null;

            $match = match_department_by_name($dept, $departments);
            $matchedDeptId = $match['department_id'];
            $confidence = $match['score'];

            $insertRow->bind_param('iissssid', $batchId, $i, $name, $dept, $designation, $staffNo, $matchedDeptId, $confidence);
            $insertRow->execute();
        }
        $insertRow->close();
    }

    $conn->commit();
    echo json_encode(['ok' => true, 'batch_id' => $batchId]);
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save the import batch: ' . $e->getMessage()]);
}
