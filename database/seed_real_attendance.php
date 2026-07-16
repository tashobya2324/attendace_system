<?php
/**
 * Generates attendance history for the real staff establishment (loaded by
 * seed_real_staff.php) so the AI monthly report has something to describe.
 *
 * Unlike seed_june2026.php, this does NOT touch staff or departments — it
 * only (re)builds attendance_records/monthly_reports/report_flags against
 * whoever is currently on the establishment. Safe to re-run. Run once via
 * CLI:
 *
 *   php database/seed_real_attendance.php
 */

require_once __DIR__ . '/../config/database.php';

$conn = db();

$seed = 42;
function seededRand(&$seed) {
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $seed / 0x7fffffff;
}
function isWeekday($ts) { return (int) date('N', $ts) < 6; }

// Per-department attendance "bias" (higher = better attendance/punctuality).
// Departments not listed default to 0.85.
$DEPT_BIAS = [
    'Audit' => 0.92,
    'Finance' => 0.90,
    'Administration' => 0.88,
    'Planning' => 0.87,
    'Procurement' => 0.86,
    'Education' => 0.86,
    'Statutory' => 0.86,
    'Council' => 0.85,
    'Production' => 0.83,
    'Community Based Services' => 0.83,
    'Natural Resources' => 0.82,
    'Trade, Industry and Local Economic Development' => 0.82,
    'Health Services' => 0.80,
    'Water' => 0.79,
    'Works' => 0.75,
];

echo "Clearing existing attendance data and report history (staff/departments untouched)...\n";
$conn->query('SET FOREIGN_KEY_CHECKS=0');
$conn->query('TRUNCATE TABLE report_flags');
$conn->query('TRUNCATE TABLE monthly_reports');
$conn->query('TRUNCATE TABLE attendance_records');
$conn->query('SET FOREIGN_KEY_CHECKS=1');

$staff = [];
$res = $conn->query(
    "SELECT s.id, d.name AS dept FROM staff s JOIN departments d ON d.id = s.department_id WHERE s.status='active'"
);
while ($row = $res->fetch_assoc()) {
    $staff[] = ['id' => (int) $row['id'], 'bias' => $DEPT_BIAS[$row['dept']] ?? 0.85];
}
echo count($staff) . " active staff found.\n";

$startMin = 8 * 60;
$grace = 15;
$endMin = 17 * 60;

$insert = $conn->prepare(
    'INSERT INTO attendance_records (staff_id, attendance_date, check_in, check_out, status, late_minutes, remarks, capture_method)
     VALUES (?,?,?,?,?,?,?,?)'
);

// Trailing months up to and including the most recently completed month.
$monthsBack = 4;
$months = [];
for ($i = $monthsBack - 1; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, (int) date('n') - $i - 1, 1, (int) date('Y'));
    $months[] = ['y' => (int) date('Y', $ts), 'm' => (int) date('n', $ts)];
}

$totalRecords = 0;
foreach ($months as $period) {
    $y = $period['y']; $m = $period['m'];
    $daysInMonth = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
    echo "Generating attendance for {$y}-{$m}...\n";

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $ts = mktime(0, 0, 0, $m, $day, $y);
        if (!isWeekday($ts)) continue;
        $dateStr = date('Y-m-d', $ts);

        foreach ($staff as $s) {
            $bias = $s['bias'];
            $roll = seededRand($seed);
            $status = 'present'; $checkIn = null; $checkOut = null; $remarks = ''; $lateMin = 0; $method = 'manual';

            if ($roll < (1 - $bias) * 0.35) {
                $status = 'leave';
                $remarks = ['Approved sick leave', 'Annual leave', 'Compassionate leave', 'Official duty travel'][rand(0, 3)];
            } elseif ($roll < (1 - $bias)) {
                $status = 'absent';
                $remarks = (seededRand($seed) < 0.4) ? 'Unexplained' : 'Reported sick, no leave form filed';
            } else {
                $lateRoll = seededRand($seed);
                $arrival = $startMin - 10 + (int) floor(seededRand($seed) * 20);
                if ($lateRoll > $bias) $arrival += 20 + (int) floor(seededRand($seed) * 80);
                $arrival = max($startMin - 25, $arrival);
                $checkIn = sprintf('%02d:%02d:00', intdiv($arrival, 60), $arrival % 60);
                $lateMin = max(0, $arrival - ($startMin + $grace));
                $status = $lateMin > 0 ? 'late' : 'present';
                if ($status === 'late') $remarks = ['Transport delay', 'Traffic', 'Family emergency', 'Not stated'][rand(0, 3)];
                $departMin = $endMin - 15 + (int) floor(seededRand($seed) * 45);
                $checkOut = sprintf('%02d:%02d:00', intdiv($departMin, 60), $departMin % 60);
                $method = (seededRand($seed) < 0.15) ? 'biometric' : 'manual';
            }

            $insert->bind_param('issssiss', $s['id'], $dateStr, $checkIn, $checkOut, $status, $lateMin, $remarks, $method);
            $insert->execute();
            $totalRecords++;
        }
    }
}
$insert->close();

echo "Done. {$totalRecords} attendance records created across " . count($months) . " month(s).\n";
