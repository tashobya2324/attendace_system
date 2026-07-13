<?php
/**
 * Fills in missing attendance records for a given month/year, for weekdays
 * up to (and including) today only, without touching any record that
 * already exists (e.g. real entries made through the Daily Register).
 *
 * Usage: php database/seed_fill_month.php 2026 7
 */

require_once __DIR__ . '/../config/database.php';

$year = isset($argv[1]) ? (int) $argv[1] : (int) date('Y');
$month = isset($argv[2]) ? (int) $argv[2] : (int) date('n');

$conn = db();

$DEPT_BIAS = [
    "Finance & Administration" => 0.90, "Health Services" => 0.80, "Education" => 0.86,
    "Works & Technical Services" => 0.74, "Community-Based Services" => 0.83, "Revenue & Planning" => 0.88,
    "Production & Marketing" => 0.82, "Internal Audit" => 0.92,
];

$seed = 99;
function seededRand(&$seed) {
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $seed / 0x7fffffff;
}

$staff = [];
$res = $conn->query("SELECT s.id, d.name AS dept FROM staff s JOIN departments d ON d.id = s.department_id WHERE s.status='active'");
while ($row = $res->fetch_assoc()) {
    $staff[] = ['id' => (int) $row['id'], 'dept' => $row['dept'], 'bias' => $DEPT_BIAS[$row['dept']] ?? 0.85];
}

$existing = [];
$stmt = $conn->prepare('SELECT staff_id, attendance_date FROM attendance_records WHERE attendance_date BETWEEN ? AND ?');
$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $existing[$row['staff_id'] . '_' . $row['attendance_date']] = true; }
$stmt->close();

$startMin = 8 * 60;
$grace = 15;
$endMin = 17 * 60;
$today = new DateTime('today');

$insert = $conn->prepare(
    'INSERT INTO attendance_records (staff_id, attendance_date, check_in, check_out, status, late_minutes, remarks, capture_method)
     VALUES (?,?,?,?,?,?,?,?)'
);

$daysInMonth = (int) date('t', strtotime($start));
$created = 0;

for ($day = 1; $day <= $daysInMonth; $day++) {
    $ts = mktime(0, 0, 0, $month, $day, $year);
    $date = new DateTime(date('Y-m-d', $ts));
    if ($date > $today) break;
    if ((int) $date->format('N') >= 6) continue;
    $dateStr = $date->format('Y-m-d');

    foreach ($staff as $s) {
        $key = $s['id'] . '_' . $dateStr;
        if (isset($existing[$key])) continue;

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
        $created++;
    }
}
$insert->close();

echo "Filled {$created} missing attendance records for {$year}-{$month} (existing entries left untouched).\n";
