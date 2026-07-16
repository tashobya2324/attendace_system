<?php
/**
 * Loads the district's real establishment register from
 * docs/sorted STAFF LIST MBARARA DLG.xlsx, replacing the placeholder demo
 * staff/departments/attendance created by seed_june2026.php.
 *
 * Wipes staff, departments, attendance_records, monthly_reports and
 * report_flags first (users and settings are untouched), then rebuilds
 * departments and staff from the sheet. Run once via CLI:
 *
 *   php database/seed_real_staff.php
 */

require_once __DIR__ . '/../config/database.php';

$xlsxPath = __DIR__ . '/../docs/sorted STAFF LIST MBARARA DLG.xlsx';
if (!is_file($xlsxPath)) {
    fwrite(STDERR, "Cannot find staff list at $xlsxPath\n");
    exit(1);
}

function colToIndex(string $col): int
{
    $col = preg_replace('/[0-9]/', '', $col);
    $idx = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

function readXlsxRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Cannot open $path as a zip archive.");
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $doc = new DOMDocument();
        $doc->loadXML($ssXml);
        foreach ($doc->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $text .= $t->nodeValue;
            }
            $sharedStrings[] = $text;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $doc = new DOMDocument();
    $doc->loadXML($sheetXml);

    $rows = [];
    foreach ($doc->getElementsByTagName('row') as $row) {
        $rowData = [];
        foreach ($row->getElementsByTagName('c') as $c) {
            $col = preg_replace('/[0-9]/', '', $c->getAttribute('r'));
            $idx = colToIndex($col);
            $type = $c->getAttribute('t');
            $vNode = $c->getElementsByTagName('v');
            $val = $vNode->length > 0 ? $vNode->item(0)->nodeValue : '';
            if ($type === 's' && $val !== '') {
                $val = $sharedStrings[(int) $val] ?? '';
            }
            $rowData[$idx] = $val;
        }
        if (!empty($rowData)) {
            $maxIdx = max(array_keys($rowData));
            $line = [];
            for ($i = 0; $i <= $maxIdx; $i++) {
                $line[] = $rowData[$i] ?? '';
            }
            $rows[] = $line;
        }
    }
    return $rows;
}

function tidy(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', $s));
}

function titleCase(string $s): string
{
    return mb_convert_case(strtolower(tidy($s)), MB_CASE_TITLE, 'UTF-8');
}

// Canonical department names, keyed by the raw uppercase text used in the sheet.
$DEPT_MAP = [
    'ADMINISTRATION' => 'Administration',
    'FINANCE' => 'Finance',
    'HEALTH SERVICES' => 'Health Services',
    'WORKS' => 'Works',
    'NATURAL RESOURCES' => 'Natural Resources',
    'COMMUNITY BASED SERVICES' => 'Community Based Services',
    'PRODUCTION' => 'Production',
    'EDUCATION' => 'Education',
    'PLANNING' => 'Planning',
    'AUDIT' => 'Audit',
    'TRADE, INDUSTRY AND LOCAL ECONOMIC DEVELOPMENT' => 'Trade, Industry and Local Economic Development',
    'PROCUREMENT' => 'Procurement',
    'WATER' => 'Water',
    'STATUTORY' => 'Statutory',
    'COUNCIL' => 'Council',
];

$rows = readXlsxRows($xlsxPath);
$header = array_shift($rows); // SN | Staff Name | Department | Rank | Salary Scale | Title | Category

$staffRows = [];
foreach ($rows as $r) {
    [$sn, $name, $dept, $rank, $scale, $title, $category] = array_pad($r, 7, '');
    $sn = tidy($sn);
    if ($sn === '' || $sn === 'SN') {
        continue;
    }
    $deptRaw = strtoupper(tidy($dept));
    $deptName = $DEPT_MAP[$deptRaw] ?? titleCase($dept);
    $staffRows[] = [
        'staff_no' => $sn,
        'full_name' => titleCase($name),
        'department' => $deptName,
        'job_group' => titleCase($rank),
        'salary_scale' => strtoupper(tidy($scale)),
        'designation' => titleCase($title),
        'category' => strtolower(tidy($category)) === 'health' ? 'health' : 'traditional',
    ];
}

echo 'Parsed ' . count($staffRows) . " staff rows from the sheet.\n";

$conn = db();

echo "Clearing existing attendance data, report history, staff and departments...\n";
$conn->query('SET FOREIGN_KEY_CHECKS=0');
$conn->query('TRUNCATE TABLE report_flags');
$conn->query('TRUNCATE TABLE monthly_reports');
$conn->query('TRUNCATE TABLE attendance_records');
$conn->query('TRUNCATE TABLE staff');
$conn->query('TRUNCATE TABLE departments');
$conn->query('SET FOREIGN_KEY_CHECKS=1');

echo "Inserting departments...\n";
$deptIds = [];
$insertDept = $conn->prepare('INSERT INTO departments (name) VALUES (?)');
foreach (array_unique(array_column($staffRows, 'department')) as $name) {
    $insertDept->bind_param('s', $name);
    $insertDept->execute();
    $deptIds[$name] = $insertDept->insert_id;
}
$insertDept->close();

echo "Inserting staff...\n";
$insertStaff = $conn->prepare(
    'INSERT INTO staff (staff_no, full_name, designation, department_id, job_group, salary_scale, staff_category, date_joined)
     VALUES (?,?,?,?,?,?,?,CURDATE())'
);
$inserted = 0;
foreach ($staffRows as $s) {
    $deptId = $deptIds[$s['department']];
    $insertStaff->bind_param(
        'sssisss',
        $s['staff_no'], $s['full_name'], $s['designation'], $deptId, $s['job_group'], $s['salary_scale'], $s['category']
    );
    $insertStaff->execute();
    $inserted++;
}
$insertStaff->close();

echo "Done: " . count($deptIds) . " departments, $inserted staff inserted.\n";
