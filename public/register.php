<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);

$conn = db();
$viewDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) { $viewDate = date('Y-m-d'); }

$staffList = $conn->query("SELECT s.id, s.staff_no, s.full_name, s.designation, d.name AS dept
                            FROM staff s JOIN departments d ON d.id = s.department_id
                            WHERE s.status='active' ORDER BY s.full_name")->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$dayStart = get_setting('day_start_time', '08:00:00');
$grace = (int) get_setting('grace_minutes', 15);
$token = csrf_token();

$pageTitle = 'Daily Register';
$activeNav = 'register.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Daily Attendance Register</h2>
    <p class="text-sm text-gray-500">Official reporting time is <?= substr($dayStart,0,5) ?>, with a <?= $grace ?>-minute grace period. Later entries are logged as late.</p>
  </div>
  <input type="date" id="viewDate" value="<?= htmlspecialchars($viewDate) ?>" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>

<div class="grid md:grid-cols-[340px_1fr] gap-6 items-start">

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="flex gap-1 bg-gray-100 rounded-md p-1 mb-4">
      <button type="button" id="tabIn" class="flex-1 text-sm font-semibold py-2 rounded bg-green-100 text-green-700">Check In</button>
      <button type="button" id="tabOut" class="flex-1 text-sm font-semibold py-2 rounded text-gray-500">Check Out</button>
    </div>

    <!-- ===== Check In panel ===== -->
    <div id="panelIn">
      <p class="text-xs text-gray-500 mb-3">Only staff who haven't checked in yet today are listed. Once recorded, a person drops off this list.</p>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Staff member</label>
      <select id="fStaffIn" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm"></select>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Time</label>
      <input type="time" id="fTimeIn" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">

      <label class="block text-xs font-semibold text-gray-600 mb-1">Capture method</label>
      <select id="fMethodIn" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">
        <option value="manual">Manual — register desk</option>
        <option value="biometric">Biometric kiosk</option>
        <option value="supervisor">Supervisor entry</option>
      </select>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Remarks <span class="text-gray-400 font-normal">(reason if late)</span></label>
      <textarea id="fRemarksIn" rows="2" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="e.g. Transport delay…"></textarea>

      <button id="fSubmitIn" class="w-full bg-green-600 text-white font-semibold text-sm rounded-md py-2.5 hover:brightness-110">Record check-in</button>
    </div>

    <!-- ===== Check Out panel ===== -->
    <div id="panelOut" class="hidden">
      <p class="text-xs text-gray-500 mb-3">Only staff who checked in but haven't checked out yet today are listed.</p>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Staff member</label>
      <select id="fStaffOut" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm"></select>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Time</label>
      <input type="time" id="fTimeOut" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">

      <label class="block text-xs font-semibold text-gray-600 mb-1">Capture method</label>
      <select id="fMethodOut" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">
        <option value="manual">Manual — register desk</option>
        <option value="biometric">Biometric kiosk</option>
        <option value="supervisor">Supervisor entry</option>
      </select>

      <label class="block text-xs font-semibold text-gray-600 mb-1">Remarks <span class="text-gray-400 font-normal">(optional)</span></label>
      <textarea id="fRemarksOut" rows="2" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="e.g. Left early — approved…"></textarea>

      <button id="fSubmitOut" class="w-full bg-red-600 text-white font-semibold text-sm rounded-md py-2.5 hover:brightness-110">Record check-out</button>
    </div>

    <div id="formMsg" class="text-xs mt-2"></div>
  </div>

  <div>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5" id="dayStrip"></div>

    <div class="bg-white border border-gray-200 rounded-lg p-5">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="font-serif text-lg font-semibold">Ledger &mdash; <span id="ledgerDateLabel"></span></h3>
        <div class="flex gap-2">
          <select id="filterDept" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= htmlspecialchars($d['name']) ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
          </select>
          <select id="filterStatus" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
            <option value="">All statuses</option>
            <option value="present">Present</option><option value="late">Late</option>
            <option value="absent">Absent</option><option value="leave">On leave</option>
          </select>
        </div>
      </div>
      <div class="overflow-x-auto border border-gray-100 rounded-md">
        <table class="w-full text-sm min-w-[820px]">
          <thead class="bg-gray-50 text-[10.5px] uppercase tracking-wide text-gray-400">
            <tr>
              <th class="text-left px-3 py-2">Staff</th><th class="text-left px-3 py-2">Department</th>
              <th class="text-left px-3 py-2">Status</th><th class="text-right px-3 py-2">Check-in</th>
              <th class="text-right px-3 py-2">Check-out</th><th class="text-right px-3 py-2">Hours</th>
              <th class="text-left px-3 py-2">Remarks</th><th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody id="ledgerBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($token) ?>;
window.INITIAL_DATE = <?= json_encode($viewDate) ?>;
</script>
<script src="assets/js/register.js"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
