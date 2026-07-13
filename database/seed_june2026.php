<?php
/**
 * Seeds the Mbarara DLG attendance system with a realistic staff establishment
 * and a full month of attendance records for June 2026 (plus two trailing
 * months, April-May 2026, so the AI forecasting engine has history to work
 * from). Run once via CLI:
 *
 *   php database/seed_june2026.php
 *
 * Safe to re-run: it wipes staff/attendance/reports tables first (not users
 * or settings), then rebuilds them deterministically (fixed random seed).
 */

require_once __DIR__ . '/../config/database.php';

$conn = db();

$FIRST = ["Grace","Moses","Sarah","Peter","Florence","David","Immaculate","Robert","Joyce","Emmanuel",
    "Betty","Charles","Norah","James","Winnie","Patrick","Agnes","Simon","Lydia","Henry",
    "Ruth","Vincent","Mary","Tom","Sylvia","Isaac","Beatrice","Joseph","Diana","Godfrey",
    "Allen","Brenda","Denis","Harriet","Ivan"];
$LAST = ["Ahimbisibwe","Kyomuhendo","Tumusiime","Byaruhanga","Ninsiima","Twinomujuni","Kemigisha",
    "Bagonza","Turyahabwe","Natukunda","Rukundo","Ampeire","Nuwagaba","Katusiime","Mugabe",
    "Kanyesigye","Asiimwe","Muhwezi","Tibaijuka","Atwine"];
$DESIG = ["Office Attendant","Records Officer","Clerical Officer","Accounts Assistant","Health Inspector",
    "Extension Worker","Community Development Officer","Nursing Officer","Engineering Assistant",
    "Assistant Records Officer","Revenue Officer","Planning Assistant","Secretary","Driver","Store Keeper"];

$DEPARTMENTS = [
    "Finance & Administration" => 0.90,
    "Health Services" => 0.80,
    "Education" => 0.86,
    "Works & Technical Services" => 0.74,
    "Community-Based Services" => 0.83,
    "Revenue & Planning" => 0.88,
    "Production & Marketing" => 0.82,
    "Internal Audit" => 0.92,
];
$PER_DEPT = [5, 6, 5, 5, 4, 5, 4, 3];

$seed = 42;
function seededRand(&$seed) {
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $seed / 0x7fffffff;
}

echo "Clearing existing attendance data, report history and staff...\n";
$conn->query('SET FOREIGN_KEY_CHECKS=0');
$conn->query('TRUNCATE TABLE report_flags');
$conn->query('TRUNCATE TABLE monthly_reports');
$conn->query('TRUNCATE TABLE attendance_records');
$conn->query('TRUNCATE TABLE staff');
$conn->query('SET FOREIGN_KEY_CHECKS=1');

$deptIds = [];
$res = $conn->query('SELECT id, name FROM departments');
while ($row = $res->fetch_assoc()) { $deptIds[$row['name']] = (int) $row['id']; }

echo "Creating staff establishment...\n";
$staffId = 1;
$staff = [];
$deptNames = array_keys($DEPARTMENTS);
foreach ($deptNames as $di => $deptName) {
    $count = $PER_DEPT[$di];
    for ($i = 0; $i < $count; $i++) {
        $fn = $FIRST[array_rand($FIRST)];
        $ln = $LAST[array_rand($LAST)];
        $name = "$fn $ln";
        $desig = $DESIG[array_rand($DESIG)];
        $staffNo = 'MB-' . str_pad($staffId, 4, '0', STR_PAD_LEFT);
        $email = strtolower(str_replace(' ', '.', $name)) . '@mbararadlg.go.ug';
        $phone = '07' . rand(0, 9) . rand(1000000, 9999999);

        $stmt = $conn->prepare('INSERT INTO staff (staff_no, full_name, designation, department_id, phone, email, date_joined) VALUES (?,?,?,?,?,?,?)');
        $joined = date('Y-m-d', strtotime('-' . rand(1, 12) . ' years'));
        $deptId = $deptIds[$deptName];
        $stmt->bind_param('sssisss', $staffNo, $name, $desig, $deptId, $phone, $email, $joined);
        $stmt->execute();
        $staff[] = ['id' => $stmt->insert_id, 'dept' => $deptName, 'bias' => $DEPARTMENTS[$deptName]];
        $stmt->close();
        $staffId++;
    }
}
echo count($staff) . " staff created.\n";

function isWeekday($ts) { $w = (int) date('N', $ts); return $w < 6; }

$startMin = 8 * 60;
$grace = 15;
$endMin = 17 * 60;

$insert = $conn->prepare(
    'INSERT INTO attendance_records (staff_id, attendance_date, check_in, check_out, status, late_minutes, remarks, capture_method)
     VALUES (?,?,?,?,?,?,?,?)'
);

$months = [['y' => 2026, 'm' => 4], ['y' => 2026, 'm' => 5], ['y' => 2026, 'm' => 6]];
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
                // Baseline arrival stays tight around the official start time (mostly within grace);
                // only the (1-bias) share of days incurs a genuine late arrival on top of that.
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

echo "Done. {$totalRecords} attendance records created across April-June 2026.\n";
echo "Log in as hr.officer / password123 to review the register, or cao / password123 to view the June 2026 report.\n";
