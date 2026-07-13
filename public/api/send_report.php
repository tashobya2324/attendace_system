<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/analysis.php';
$user = require_role(['hr', 'admin']);
verify_csrf();

$year = (int) ($_POST['year'] ?? 0);
$month = (int) ($_POST['month'] ?? 0);
if (!$year || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid reporting period.']);
    exit;
}

$stats = compute_period_stats($year, $month);
$deptStats = compute_department_stats($year, $month);
$flagged = detect_flagged_staff($year, $month);
$forecast = forecast_next_month($year, $month);
$periodLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$narrative = generate_ai_narrative($stats, $deptStats, $flagged, $forecast, $periodLabel);
$insights = generate_ai_insights($stats, $deptStats, $flagged, $forecast);

$conn = db();
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        'INSERT INTO monthly_reports (report_year, report_month, attendance_rate, punctuality_rate, absenteeism_rate, total_staff, narrative_summary, ai_insights, generated_by, status, sent_at)
         VALUES (?,?,?,?,?,?,?,?,?, "sent", NOW())
         ON DUPLICATE KEY UPDATE
           attendance_rate=VALUES(attendance_rate), punctuality_rate=VALUES(punctuality_rate),
           absenteeism_rate=VALUES(absenteeism_rate), total_staff=VALUES(total_staff),
           narrative_summary=VALUES(narrative_summary), ai_insights=VALUES(ai_insights),
           generated_by=VALUES(generated_by), status="sent", sent_at=NOW()'
    );
    $insightsJson = json_encode($insights);
    $stmt->bind_param(
        'iidddissi',
        $year, $month, $stats['attendanceRate'], $stats['punctualityRate'], $stats['absenteeismRate'],
        $stats['totalStaff'], $narrative, $insightsJson, $user['id']
    );
    $stmt->execute();
    $stmt->close();

    $reportId = $conn->insert_id ?: $conn->query(
        'SELECT id FROM monthly_reports WHERE report_year=' . (int) $year . ' AND report_month=' . (int) $month
    )->fetch_assoc()['id'];

    $conn->query('DELETE FROM report_flags WHERE report_id = ' . (int) $reportId);
    if (!empty($flagged)) {
        $ins = $conn->prepare('INSERT INTO report_flags (report_id, staff_id, late_count, absent_count, risk_score) VALUES (?,?,?,?,?)');
        foreach ($flagged as $f) {
            $ins->bind_param('iiiid', $reportId, $f['id'], $f['late'], $f['absent'], $f['risk_score']);
            $ins->execute();
        }
        $ins->close();
    }

    $conn->commit();
    echo json_encode(['ok' => true, 'report_id' => $reportId]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send report: ' . $e->getMessage()]);
}
