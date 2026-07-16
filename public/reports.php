<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'cao', 'admin']);
$conn = db();

// Default to the most recently completed month (a mid-month view of the
// current, still-open month is rarely what HR/the CAO want from a monthly
// report) unless a period is explicitly requested via the query string.
if (isset($_GET['year'], $_GET['month'])) {
    $year = (int) $_GET['year'];
    $month = (int) $_GET['month'];
} else {
    $prev = (new DateTime('first day of last month'));
    $year = (int) $prev->format('Y');
    $month = (int) $prev->format('n');
}
if ($month < 1 || $month > 12) { $month = (int) date('n'); }

$stats = compute_period_stats($year, $month);
$deptStats = compute_department_stats($year, $month);
$dailySeries = compute_daily_series($year, $month);
$flagged = detect_flagged_staff($year, $month);
$forecast = forecast_next_month($year, $month);
$periodLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$aiReport = generate_ai_report($stats, $deptStats, $flagged, $forecast, $periodLabel);
$narrative = $aiReport['narrative'];
$insights = $aiReport['insights'];

$existingReport = null;
$stmt = $conn->prepare('SELECT * FROM monthly_reports WHERE report_year=? AND report_month=?');
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$existingReport = $stmt->get_result()->fetch_assoc();
$stmt->close();

$target = (float) get_setting('attendance_target_pct', 85);
$punctTarget = (float) get_setting('punctuality_target_pct', 90);
$token = csrf_token();

// Options for the period selector: last 12 months back from current real month.
$options = [];
$refNow = new DateTime('now');
for ($i = 0; $i < 12; $i++) {
    $d = (clone $refNow)->modify("-$i months");
    $options[] = ['y' => (int) $d->format('Y'), 'm' => (int) $d->format('n'), 'label' => $d->format('F Y')];
}

$pageTitle = 'Monthly Report';
$activeNav = 'reports.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Monthly Attendance Report</h2>
    <p class="text-sm text-gray-500">Prepared for the Chief Administrative Officer &mdash; AI-assisted analysis of the daily attendance register.</p>
  </div>
  <form method="get" class="flex items-center gap-2">
    <select name="ym" onchange="var v=this.value.split('-');window.location='reports.php?year='+v[0]+'&month='+v[1];" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
      <?php foreach ($options as $o): $sel = ($o['y'] === $year && $o['m'] === $month); ?>
        <option value="<?= $o['y'] ?>-<?= $o['m'] ?>" <?= $sel ? 'selected' : '' ?>><?= $o['label'] ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($existingReport && $existingReport['status'] === 'sent'): ?>
  <div class="mb-6 text-sm text-green-800 bg-green-50 border border-green-200 rounded-md px-4 py-3 flex items-center gap-2">
    <span class="font-semibold">Sent to CAO</span> &mdash; this report was transmitted on <?= date('j F Y, g:i a', strtotime($existingReport['sent_at'])) ?>.
  </div>
<?php endif; ?>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
  <?php
  $tiles = [
    ['Staff on establishment', $stats['totalStaff'], '', 'flat'],
    ['Attendance rate', $stats['attendanceRate'] . '%', $stats['attendanceRate'] >= $target ? "Above target ({$target}%)" : "Below target ({$target}%)", $stats['attendanceRate'] >= $target ? 'up' : 'down'],
    ['Punctuality rate', $stats['punctualityRate'] . '%', $stats['punctualityRate'] >= $punctTarget ? 'On track' : 'Needs attention', $stats['punctualityRate'] >= $punctTarget ? 'up' : 'down'],
    ['Absenteeism', $stats['absenteeismRate'] . '%', $stats['absenteeismRate'] <= 5 ? 'Within tolerance' : 'Above tolerance', $stats['absenteeismRate'] <= 5 ? 'up' : 'down'],
    ['Working days', $stats['workDays'], 'Logged in register', 'flat'],
  ];
  $dirColor = ['up' => 'text-green-600', 'down' => 'text-red-600', 'flat' => 'text-gray-400'];
  foreach ($tiles as $t): ?>
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold"><?= $t[0] ?></div>
      <div class="font-serif text-2xl mt-1"><?= $t[1] ?></div>
      <div class="text-xs font-semibold mt-1 <?= $dirColor[$t[3]] ?>"><?= $t[2] ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-[1.2fr_1fr] gap-6 mb-8">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-4">Attendance rate by department</h3>
    <?php foreach ($deptStats as $d): $pct = round($d['rate'] * 100); ?>
      <div class="grid grid-cols-[140px_1fr_46px] gap-3 items-center mb-2.5">
        <div class="text-xs font-semibold text-right text-gray-700 truncate"><?= htmlspecialchars($d['name']) ?></div>
        <div class="h-4 bg-gray-100 rounded overflow-hidden">
          <div class="h-full rounded" style="width:<?= $pct ?>%;background:<?= $pct >= 85 ? '#1c5cab' : ($pct >= 70 ? '#4a8ed3' : '#adcdee') ?>"></div>
        </div>
        <div class="text-xs tabular-nums text-gray-500"><?= $pct ?>%</div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-4">Daily pattern over the month</h3>
    <div style="position:relative; height:220px;">
      <canvas id="trendChart"></canvas>
    </div>
    <div class="flex gap-4 text-xs text-gray-500 mt-3 flex-wrap">
      <span class="flex items-center gap-1.5"><i class="w-2.5 h-2.5 rounded-sm inline-block" style="background:#0ca30c"></i>Present</span>
      <span class="flex items-center gap-1.5"><i class="w-2.5 h-2.5 rounded-sm inline-block" style="background:#b9791f"></i>Late</span>
      <span class="flex items-center gap-1.5"><i class="w-2.5 h-2.5 rounded-sm inline-block" style="background:#b3402c"></i>Absent</span>
    </div>
  </div>
</div>

<div class="bg-white border border-gray-200 rounded-lg p-5 mb-8">
  <h3 class="font-serif text-lg font-semibold mb-3">AI-assisted insight &mdash; forecast &amp; anomalies</h3>
  <div class="grid md:grid-cols-2 gap-6">
    <div>
      <?php if (!empty($forecast['available'])): ?>
        <p class="text-sm text-gray-600 mb-2">Projected next month (<?= $forecast['method'] ?>):</p>
        <div class="flex gap-4">
          <div class="bg-brand-50 rounded-lg px-4 py-3 flex-1">
            <div class="text-[11px] uppercase tracking-wide text-brand-700 font-semibold">Attendance</div>
            <div class="font-serif text-2xl text-brand-800"><?= $forecast['projected_attendance_rate'] ?>%</div>
          </div>
          <div class="bg-amber-50 rounded-lg px-4 py-3 flex-1">
            <div class="text-[11px] uppercase tracking-wide text-amber-700 font-semibold">Absenteeism</div>
            <div class="font-serif text-2xl text-amber-800"><?= $forecast['projected_absenteeism_rate'] ?>%</div>
          </div>
        </div>
        <?php if (!empty($forecast['trend_direction'])): ?>
        <p class="text-xs text-gray-500 mt-2">Trend: <span class="font-semibold"><?= ucfirst($forecast['trend_direction']) ?></span> · confidence: <?= $forecast['confidence'] ?> · based on <?= $forecast['points_used'] ?> month(s) of data.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-sm text-gray-400">Not enough historical data yet to forecast next month.</p>
      <?php endif; ?>
    </div>
    <div>
      <p class="text-sm text-gray-600 mb-2">Flagged observations:</p>
      <ul class="text-sm space-y-1.5 list-disc list-inside text-gray-700">
        <?php foreach ($insights as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-8">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-3">Department detail</h3>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400"><tr><th class="text-left py-1.5">Department</th><th class="text-right py-1.5">Staff</th><th class="text-right py-1.5">Attend.</th><th class="text-right py-1.5">Punctual</th><th class="text-right py-1.5">Absent</th></tr></thead>
        <tbody>
        <?php foreach ($deptStats as $d): ?>
          <tr class="border-t border-gray-100">
            <td class="py-1.5"><?= htmlspecialchars($d['name']) ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= $d['staff_count'] ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= round($d['rate'] * 100) ?>%</td>
            <td class="py-1.5 text-right tabular-nums"><?= round($d['punctuality'] * 100) ?>%</td>
            <td class="py-1.5 text-right tabular-nums"><?= round($d['absence_rate'] * 100) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-1">Flagged for CAO attention</h3>
    <p class="text-xs text-gray-400 mb-3">Staff exceeding policy thresholds for late arrivals or unexplained absences.</p>
    <?php if (empty($flagged)): ?>
      <p class="text-sm text-gray-400 py-8 text-center">No staff exceeded thresholds this period.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400"><tr><th class="text-left py-1.5">Staff</th><th class="text-left py-1.5">Dept</th><th class="text-right py-1.5">Late</th><th class="text-right py-1.5">Absent</th></tr></thead>
        <tbody>
        <?php foreach ($flagged as $f): ?>
          <tr class="border-t border-gray-100">
            <td class="py-1.5 font-medium"><?= htmlspecialchars($f['name']) ?></td>
            <td class="py-1.5 text-gray-500"><?= htmlspecialchars($f['dept']) ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= $f['late'] ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= $f['absent'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<h3 class="font-serif text-lg font-semibold mb-3">Transmittal to the CAO</h3>
<div class="bg-[#e9e6dd] border border-dashed border-gray-300 rounded-lg p-6 font-serif text-[15px] leading-relaxed">
  <h4 class="font-bold text-base mb-2">Monthly Staff Attendance Report &mdash; <?= $periodLabel ?></h4>
  <p class="mb-2"><strong>To:</strong> The Chief Administrative Officer, Mbarara District Local Government<br>
  <strong>From:</strong> Human Resource Management Unit<br>
  <strong>Re:</strong> Staff attendance patterns for <?= $periodLabel ?></p>
  <p><?= htmlspecialchars($narrative) ?></p>
  <div class="text-xs text-gray-500 mt-4 font-sans">
    Generated automatically from the daily attendance register on <?= date('j F Y') ?>
    &mdash; <?= $aiReport['source'] === 'ai' ? 'drafted by Gemini' : 'template-generated (AI unavailable)' ?>.
  </div>
</div>

<?php if (in_array($user['role'], ['hr', 'admin'], true)): ?>
<div class="flex gap-3 mt-4 flex-wrap">
  <button id="sendReportBtn" class="bg-brand-500 text-white font-semibold text-sm rounded-md px-4 py-2.5 hover:brightness-110">
    <?= ($existingReport && $existingReport['status'] === 'sent') ? 'Re-send report to CAO' : 'Send report to CAO' ?>
  </button>
  <a href="reports.php?year=<?= $year ?>&month=<?= $month ?>&print=1" target="_blank" class="border border-gray-300 text-sm font-semibold rounded-md px-4 py-2.5 hover:bg-gray-50">Print / Save PDF</a>
  <span id="sendMsg" class="text-sm self-center"></span>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  const daily = <?= json_encode($dailySeries) ?>;
  const ctx = document.getElementById('trendChart');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: daily.map(d => d.date.slice(8, 10)),
      datasets: [
        { label: 'Present', data: daily.map(d => d.present), borderColor: '#0ca30c', backgroundColor: '#0ca30c', tension: 0.3, pointRadius: 2 },
        { label: 'Late', data: daily.map(d => d.late), borderColor: '#b9791f', backgroundColor: '#b9791f', tension: 0.3, pointRadius: 2 },
        { label: 'Absent', data: daily.map(d => d.absent), borderColor: '#b3402c', backgroundColor: '#b3402c', tension: 0.3, pointRadius: 2 },
      ]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } },
      maintainAspectRatio: false
    }
  });

  <?php if (in_array($user['role'], ['hr', 'admin'], true)): ?>
  document.getElementById('sendReportBtn').addEventListener('click', function () {
    const btn = this, msg = document.getElementById('sendMsg');
    btn.disabled = true;
    const body = new URLSearchParams();
    body.set('csrf', <?= json_encode($token) ?>);
    body.set('year', <?= (int) $year ?>);
    body.set('month', <?= (int) $month ?>);
    fetch('api/send_report.php', { method: 'POST', body })
      .then(r => r.json())
      .then(data => {
        btn.disabled = false;
        if (data.error) { msg.textContent = data.error; msg.className = 'text-sm self-center text-red-600'; return; }
        msg.textContent = 'Report sent to the CAO.';
        msg.className = 'text-sm self-center text-green-600';
        btn.textContent = 'Re-send report to CAO';
      });
  });
  <?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
