<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
require_role(['hr', 'admin']);

$today = date('Y-m-d');
$conn = db();

$totalStaff = (int) $conn->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch_assoc()['c'];
$todayRes = $conn->prepare("SELECT status, COUNT(*) c FROM attendance_records WHERE attendance_date = ? GROUP BY status");
$todayRes->bind_param('s', $today);
$todayRes->execute();
$todayCounts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
$r = $todayRes->get_result();
while ($row = $r->fetch_assoc()) { $todayCounts[$row['status']] = (int) $row['c']; }
$todayRes->close();
$loggedToday = array_sum($todayCounts);
$notLogged = max(0, $totalStaff - $loggedToday);

$year = (int) date('Y');
$month = (int) date('n');
$stats = compute_period_stats($year, $month);
$deptStats = compute_department_stats($year, $month);
$flagged = detect_flagged_staff($year, $month);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Good day, <?= htmlspecialchars(explode(' ', current_user()['full_name'])[0]) ?></h2>
    <p class="text-sm text-gray-500"><?= date('l, j F Y') ?> &mdash; here is today's snapshot and this month so far.</p>
  </div>
  <a href="register.php" class="bg-brand-500 text-white text-sm font-semibold px-4 py-2.5 rounded-md hover:brightness-110">Open Daily Register &rarr;</a>
</div>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
  <?php foreach ([
    ['Present today', $todayCounts['present'], 'text-green-600'],
    ['Late today', $todayCounts['late'], 'text-amber-600'],
    ['Absent today', $todayCounts['absent'], 'text-red-600'],
    ['On leave', $todayCounts['leave'], 'text-purple-700'],
    ['Not yet logged', $notLogged, 'text-gray-500'],
  ] as [$label, $n, $color]): ?>
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="text-2xl font-bold tabular-nums <?= $color ?>"><?= $n ?></div>
      <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold mt-1"><?= $label ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid md:grid-cols-3 gap-4 mb-8">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Month-to-date attendance rate</div>
    <div class="font-serif text-3xl mt-1"><?= $stats['attendanceRate'] ?>%</div>
  </div>
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Month-to-date punctuality</div>
    <div class="font-serif text-3xl mt-1"><?= $stats['punctualityRate'] ?>%</div>
  </div>
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Staff flagged this month</div>
    <div class="font-serif text-3xl mt-1"><?= count($flagged) ?></div>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-6">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-3">Department attendance (month-to-date)</h3>
    <?php foreach ($deptStats as $d): ?>
      <div class="grid grid-cols-[1fr_auto] gap-3 items-center mb-2 text-sm">
        <div class="flex items-center gap-2">
          <span class="w-32 truncate text-gray-700 font-medium"><?= htmlspecialchars($d['name']) ?></span>
          <div class="flex-1 h-3 bg-gray-100 rounded overflow-hidden min-w-[80px]">
            <div class="h-full bg-brand-500 rounded" style="width:<?= round($d['rate']*100) ?>%"></div>
          </div>
        </div>
        <span class="tabular-nums text-gray-500 text-xs"><?= round($d['rate']*100) ?>%</span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-3">Flagged staff (policy thresholds exceeded)</h3>
    <?php if (empty($flagged)): ?>
      <p class="text-sm text-gray-400 py-6 text-center">No staff have exceeded thresholds this month.</p>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[11px] uppercase text-gray-400"><th class="pb-2">Staff</th><th class="pb-2">Dept</th><th class="pb-2 text-right">Late</th><th class="pb-2 text-right">Absent</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($flagged, 0, 6) as $f): ?>
          <tr class="border-t border-gray-100">
            <td class="py-1.5 font-medium"><?= htmlspecialchars($f['name']) ?></td>
            <td class="py-1.5 text-gray-500"><?= htmlspecialchars($f['dept']) ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= $f['late'] ?></td>
            <td class="py-1.5 text-right tabular-nums"><?= $f['absent'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
