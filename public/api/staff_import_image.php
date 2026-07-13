<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role(['hr', 'admin']);

$batchId = (int) ($_GET['batch'] ?? 0);
$stmt = db()->prepare('SELECT stored_filename FROM staff_import_batches WHERE id = ?');
$stmt->bind_param('i', $batchId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$path = __DIR__ . '/../../storage/staff_imports/' . $row['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
