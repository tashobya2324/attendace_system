<?php
/**
 * AI-assisted analysis engine for the Mbarara DLG attendance system.
 *
 * "AI-enabled" here is delivered through three concrete, explainable
 * techniques rather than a black box, which is what an HR unit can
 * actually defend to the CAO:
 *
 *   1. Rule-based anomaly detection  -> flags staff crossing policy thresholds
 *   2. Statistical forecasting        -> 3-month moving average + linear trend
 *      to project next month's attendance/absenteeism before it happens
 *   3. Template-driven NLG            -> auto-drafts the narrative memo that
 *      HR currently types by hand every month
 *
 * generate_ai_narrative() below is written as a swappable seam: if an
 * LLM_API_KEY is configured it can be routed through a real language model
 * for richer prose; otherwise it falls back to the deterministic generator
 * so the system works fully offline.
 */

function get_setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $res = db()->query('SELECT setting_key, setting_value FROM settings');
        while ($row = $res->fetch_assoc()) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Core period statistics for a given year/month.
 */
function compute_period_stats(int $year, int $month): array
{
    $conn = db();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $stmt = $conn->prepare(
        'SELECT status, COUNT(*) AS c FROM attendance_records
         WHERE attendance_date BETWEEN ? AND ? GROUP BY status'
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
    while ($row = $res->fetch_assoc()) {
        $counts[$row['status']] = (int) $row['c'];
    }
    $stmt->close();

    $total = array_sum($counts);
    $present = $counts['present'];
    $late = $counts['late'];
    $absent = $counts['absent'];
    $leave = $counts['leave'];

    $attendanceRate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0.0;
    $punctualityRate = ($present + $late) > 0 ? round(($present / ($present + $late)) * 100, 1) : 0.0;
    $absenteeismRate = $total > 0 ? round(($absent / $total) * 100, 1) : 0.0;

    $totalStaff = (int) $conn->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch_assoc()['c'];

    $workDaysRes = $conn->prepare(
        'SELECT COUNT(DISTINCT attendance_date) c FROM attendance_records WHERE attendance_date BETWEEN ? AND ?'
    );
    $workDaysRes->bind_param('ss', $start, $end);
    $workDaysRes->execute();
    $workDays = (int) $workDaysRes->get_result()->fetch_assoc()['c'];
    $workDaysRes->close();

    return compact('counts', 'total', 'present', 'late', 'absent', 'leave',
        'attendanceRate', 'punctualityRate', 'absenteeismRate', 'totalStaff', 'workDays', 'start', 'end');
}

/**
 * Per-department breakdown for a period, sorted best to worst attendance.
 */
function compute_department_stats(int $year, int $month): array
{
    $conn = db();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $sql = "SELECT d.id, d.name,
                COUNT(ar.id) AS total,
                SUM(ar.status='present') AS present,
                SUM(ar.status='late') AS late,
                SUM(ar.status='absent') AS absent,
                SUM(ar.status='leave') AS leave_days,
                COUNT(DISTINCT s.id) AS staff_count
            FROM departments d
            JOIN staff s ON s.department_id = d.id AND s.status = 'active'
            LEFT JOIN attendance_records ar ON ar.staff_id = s.id AND ar.attendance_date BETWEEN ? AND ?
            GROUP BY d.id, d.name
            ORDER BY d.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $total = (int) $row['total'];
        $present = (int) $row['present'];
        $late = (int) $row['late'];
        $absent = (int) $row['absent'];
        $rate = $total > 0 ? ($present + $late) / $total : 0;
        $punct = ($present + $late) > 0 ? $present / ($present + $late) : 0;
        $out[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'staff_count' => (int) $row['staff_count'],
            'total' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'leave' => (int) $row['leave_days'],
            'rate' => $rate,
            'punctuality' => $punct,
            'absence_rate' => $total > 0 ? $absent / $total : 0,
        ];
    }
    $stmt->close();
    usort($out, fn($a, $b) => $b['rate'] <=> $a['rate']);
    return $out;
}

/**
 * Daily series for the trend chart.
 */
function compute_daily_series(int $year, int $month): array
{
    $conn = db();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $sql = "SELECT attendance_date,
                SUM(status='present') AS present,
                SUM(status='late') AS late,
                SUM(status='absent') AS absent
            FROM attendance_records
            WHERE attendance_date BETWEEN ? AND ?
            GROUP BY attendance_date
            ORDER BY attendance_date";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'date' => $row['attendance_date'],
            'present' => (int) $row['present'],
            'late' => (int) $row['late'],
            'absent' => (int) $row['absent'],
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Rule-based anomaly detection: staff exceeding late/absence thresholds.
 * This is the "flagging" AI technique — simple, transparent, and auditable
 * by the CAO, which matters more in a public-sector setting than a opaque model.
 */
function detect_flagged_staff(int $year, int $month): array
{
    $conn = db();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $lateThreshold = (int) get_setting('late_flag_threshold', 3);
    $absentThreshold = (int) get_setting('absence_flag_threshold', 2);

    $sql = "SELECT s.id, s.full_name, s.staff_no, d.name AS dept,
                SUM(ar.status='late') AS late_count,
                SUM(ar.status='absent') AS absent_count
            FROM staff s
            JOIN departments d ON d.id = s.department_id
            JOIN attendance_records ar ON ar.staff_id = s.id AND ar.attendance_date BETWEEN ? AND ?
            WHERE s.status = 'active'
            GROUP BY s.id, s.full_name, s.staff_no, d.name
            HAVING SUM(ar.status='late') > ? OR SUM(ar.status='absent') > ?
            ORDER BY (SUM(ar.status='late') + SUM(ar.status='absent') * 2) DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssii', $start, $end, $lateThreshold, $absentThreshold);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $late = (int) $row['late_count'];
        $absent = (int) $row['absent_count'];
        $out[] = [
            'id' => (int) $row['id'],
            'name' => $row['full_name'],
            'staff_no' => $row['staff_no'],
            'dept' => $row['dept'],
            'late' => $late,
            'absent' => $absent,
            'risk_score' => round($late * 1.0 + $absent * 2.0, 1),
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Statistical forecasting: projects next month's attendance rate using a
 * simple linear trend fitted over the trailing months of real data
 * (falls back to a moving average when fewer than 2 points exist).
 * This gives HR/the CAO a forward-looking signal, not just a rear-view mirror.
 */
function forecast_next_month(int $year, int $month, int $lookback = 6): array
{
    $points = [];
    for ($i = $lookback - 1; $i >= 0; $i--) {
        $ts = mktime(0, 0, 0, $month - $i, 1, $year);
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        $stats = compute_period_stats($y, $m);
        if ($stats['total'] > 0) {
            $points[] = ['y' => $y, 'm' => $m, 'rate' => $stats['attendanceRate'], 'absent' => $stats['absenteeismRate']];
        }
    }

    $n = count($points);
    if ($n === 0) {
        return ['available' => false];
    }
    if ($n === 1) {
        return [
            'available' => true,
            'method' => 'single-period carry-forward',
            'projected_attendance_rate' => $points[0]['rate'],
            'projected_absenteeism_rate' => $points[0]['absent'],
            'confidence' => 'low',
            'points_used' => $n,
        ];
    }

    // Simple linear regression over index 0..n-1 against attendance rate & absenteeism.
    $xs = range(0, $n - 1);
    $fit = function (array $ys) use ($xs, $n) {
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;
        $num = 0.0; $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $meanX) * ($ys[$i] - $meanY);
            $den += ($x - $meanX) ** 2;
        }
        $slope = $den != 0 ? $num / $den : 0.0;
        $intercept = $meanY - $slope * $meanX;
        $next = $slope * $n + $intercept;
        return [max(0, min(100, round($next, 1))), round($slope, 2)];
    };

    [$projRate, $slopeRate] = $fit(array_column($points, 'rate'));
    [$projAbsent, $slopeAbsent] = $fit(array_column($points, 'absent'));

    return [
        'available' => true,
        'method' => "linear trend over trailing {$n} month(s)",
        'projected_attendance_rate' => $projRate,
        'projected_absenteeism_rate' => $projAbsent,
        'trend_direction' => $slopeRate > 0.3 ? 'improving' : ($slopeRate < -0.3 ? 'declining' : 'stable'),
        'confidence' => $n >= 4 ? 'moderate' : 'low',
        'points_used' => $n,
        'history' => $points,
    ];
}

/**
 * Auto-drafted narrative memo (template-driven NLG). Deterministic and
 * offline by default; swap in a real LLM call here if LLM_API_KEY is set
 * and richer prose is desired (see README "AI extension point").
 */
function generate_ai_narrative(array $stats, array $deptStats, array $flagged, array $forecast, string $periodLabel): string
{
    $target = (float) get_setting('attendance_target_pct', 85);
    $worst = $deptStats[count($deptStats) - 1] ?? null;
    $best = $deptStats[0] ?? null;

    $lines = [];
    $lines[] = "During {$periodLabel}, Mbarara District Local Government recorded an overall attendance rate of "
        . "{$stats['attendanceRate']}% across {$stats['total']} logged staff-days over {$stats['workDays']} working days, "
        . "against an institutional target of {$target}%. The punctuality rate among staff who reported for duty was "
        . "{$stats['punctualityRate']}%, and absenteeism stood at {$stats['absenteeismRate']}%.";

    if ($stats['attendanceRate'] >= $target) {
        $lines[] = "This places the district above its attendance target for the period, reflecting consistent supervisory oversight across departments.";
    } else {
        $gap = round($target - $stats['attendanceRate'], 1);
        $lines[] = "This falls {$gap} percentage points short of the district target and warrants continued follow-up at departmental level.";
    }

    if ($best && $worst && $best['name'] !== $worst['name']) {
        $lines[] = sprintf(
            "%s posted the strongest attendance performance at %d%%, while %s recorded the lowest at %d%% and is flagged for departmental follow-up.",
            $best['name'], round($best['rate'] * 100), $worst['name'], round($worst['rate'] * 100)
        );
    }

    if (count($flagged) > 0) {
        $lines[] = count($flagged) . " staff member(s) exceeded the late-arrival or unexplained-absence thresholds set out in the HR attendance policy and are listed in the flagged-staff schedule for supervisory attention.";
    } else {
        $lines[] = "No staff exceeded the late-arrival or absence thresholds set out in the HR attendance policy during this period.";
    }

    if (!empty($forecast['available']) && ($forecast['points_used'] ?? 0) >= 2) {
        $dir = $forecast['trend_direction'] ?? 'stable';
        $lines[] = sprintf(
            "Based on a %s, attendance is projected at approximately %d%% next month, a %s trend that HR recommends monitoring alongside the department-level indicators above.",
            $forecast['method'], round($forecast['projected_attendance_rate']), $dir
        );
    }

    return implode(' ', $lines);
}

/**
 * Structured AI insight bullets shown alongside the narrative (kept separate
 * from the memo prose so the CAO view can render them as a scannable list).
 */
function generate_ai_insights(array $stats, array $deptStats, array $flagged, array $forecast): array
{
    $insights = [];

    foreach ($deptStats as $d) {
        if ($d['rate'] < 0.75 && $d['total'] > 0) {
            $insights[] = sprintf('%s is trending below 75%% attendance (%d%%) — recommend a supervisory check-in.', $d['name'], round($d['rate'] * 100));
        }
    }

    if (count($flagged) >= 3) {
        $insights[] = count($flagged) . ' staff have crossed policy thresholds this period — consider a written caution round for repeat cases.';
    }

    if (!empty($forecast['available']) && ($forecast['trend_direction'] ?? '') === 'declining') {
        $insights[] = 'Trailing-month trend is declining — projected attendance next month is lower than the current period.';
    } elseif (!empty($forecast['available']) && ($forecast['trend_direction'] ?? '') === 'improving') {
        $insights[] = 'Trailing-month trend is improving — current interventions appear to be working.';
    }

    if (empty($insights)) {
        $insights[] = 'No material anomalies detected for this period beyond the individually flagged staff below.';
    }

    return $insights;
}

/**
 * Report entry point used by reports.php and api/send_report.php: tries a
 * real Gemini call first (see includes/gemini.php) and falls back to the
 * deterministic template generator above if no API key is configured, or
 * if the call fails or times out — so the report always renders.
 *
 * @return array{narrative:string, insights:array, source:'ai'|'template'}
 */
function generate_ai_report(array $stats, array $deptStats, array $flagged, array $forecast, string $periodLabel): array
{
    if (getenv('GEMINI_API_KEY')) {
        require_once __DIR__ . '/gemini.php';
        $target = (float) get_setting('attendance_target_pct', 85);
        $result = gemini_generate_report_narrative($stats, $deptStats, $flagged, $forecast, $periodLabel, $target);
        if ($result['ok']) {
            return ['narrative' => $result['narrative'], 'insights' => $result['insights'], 'source' => 'ai'];
        }
    }

    return [
        'narrative' => generate_ai_narrative($stats, $deptStats, $flagged, $forecast, $periodLabel),
        'insights' => generate_ai_insights($stats, $deptStats, $flagged, $forecast),
        'source' => 'template',
    ];
}
