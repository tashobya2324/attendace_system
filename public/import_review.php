<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);
$conn = db();

$batchId = (int) ($_GET['batch'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM attendance_import_batches WHERE id = ?');
$stmt->bind_param('i', $batchId);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    http_response_code(404);
    require __DIR__ . '/../includes/header.php';
    echo '<p class="text-sm text-red-600">Import batch not found.</p>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$rows = $conn->prepare('SELECT * FROM attendance_import_rows WHERE batch_id = ? ORDER BY row_index');
$rows->bind_param('i', $batchId);
$rows->execute();
$importRows = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
$rows->close();

$activeStaff = $conn->query("SELECT id, full_name, staff_no FROM staff WHERE status='active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Which staff already have an attendance record for this date — flagged so
// HR can see they'd be overwriting a live entry before they commit.
$existing = [];
$exStmt = $conn->prepare('SELECT staff_id FROM attendance_records WHERE attendance_date = ?');
$exStmt->bind_param('s', $batch['register_date']);
$exStmt->execute();
$r = $exStmt->get_result();
while ($row = $r->fetch_assoc()) { $existing[(int) $row['staff_id']] = true; }
$exStmt->close();

$dayStart = get_setting('day_start_time', '08:00:00');
$grace = (int) get_setting('grace_minutes', 15);
$startMinutes = ((int) substr($dayStart, 0, 2)) * 60 + (int) substr($dayStart, 3, 2);

function guess_status(?string $checkIn, ?string $remarks, int $startMinutes, int $grace): string
{
    if ($checkIn && preg_match('/^\d{2}:\d{2}/', $checkIn)) {
        [$h, $m] = array_map('intval', explode(':', substr($checkIn, 0, 5)));
        $arrival = $h * 60 + $m;
        return ($arrival - ($startMinutes + $grace)) > 0 ? 'late' : 'present';
    }
    $r = strtolower((string) $remarks);
    if (str_contains($r, 'leave')) return 'leave';
    return 'absent';
}

$token = csrf_token();
$pageTitle = 'Review Import';
$activeNav = 'import.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Review Register Import &mdash; <?= htmlspecialchars($batch['register_date']) ?></h2>
    <p class="text-sm text-gray-500 max-w-2xl">Check every row against the photo. Nothing here is final until you select <strong>Commit to register</strong> below — rows you leave unchecked are simply discarded.</p>
  </div>
  <a href="import.php" class="text-sm text-gray-500 hover:underline">&larr; Back to imports</a>
</div>

<?php if ($batch['status'] === 'committed'): ?>
  <div class="mb-6 text-sm text-green-800 bg-green-50 border border-green-200 rounded-md px-4 py-3">
    This batch was already committed to the register on <?= date('j F Y, g:i a', strtotime($batch['reviewed_at'])) ?>.
    Re-committing will overwrite matching attendance records again.
  </div>
<?php endif; ?>

<?php if (!$batch['error_message'] && empty($importRows)): ?>
  <div class="mb-6 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-4 py-3">
    The AI did not return any readable rows for this photo. Try a clearer or better-lit photo, or add entries directly via the Daily Register instead.
  </div>
<?php elseif ($batch['error_message']): ?>
  <div class="mb-6 text-sm text-red-800 bg-red-50 border border-red-200 rounded-md px-4 py-3">
    <strong>AI extraction failed:</strong> <?= htmlspecialchars($batch['error_message']) ?>
  </div>
<?php endif; ?>

<div class="grid lg:grid-cols-[380px_1fr] gap-6 items-start">

  <div class="bg-white border border-gray-200 rounded-lg p-3 lg:sticky lg:top-4">
    <img src="api/import_image.php?batch=<?= $batchId ?>" alt="Source register photo" class="w-full h-auto rounded">
    <p class="text-xs text-gray-400 mt-2 px-1"><?= htmlspecialchars($batch['original_filename']) ?> &middot; uploaded <?= date('j M Y, g:i a', strtotime($batch['created_at'])) ?></p>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[900px]" id="reviewTable">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
          <tr>
            <th class="text-left px-2 py-2 w-8">Use</th>
            <th class="text-left px-2 py-2">As written</th>
            <th class="text-left px-2 py-2">Matched staff member</th>
            <th class="text-left px-2 py-2">Status</th>
            <th class="text-left px-2 py-2">In</th>
            <th class="text-left px-2 py-2">Out</th>
            <th class="text-left px-2 py-2">Remarks</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($importRows as $row):
            $checkIn = ($row['raw_check_in'] && preg_match('/^\d{2}:\d{2}/', $row['raw_check_in'])) ? substr($row['raw_check_in'], 0, 5) : '';
            $checkOut = ($row['raw_check_out'] && preg_match('/^\d{2}:\d{2}/', $row['raw_check_out'])) ? substr($row['raw_check_out'], 0, 5) : '';
            $status = guess_status($row['raw_check_in'], $row['raw_remarks'], $startMinutes, $grace);
            $matchedId = $row['matched_staff_id'];
            $confident = $matchedId && $row['match_confidence'] >= 72;
            $hasConflict = $matchedId && isset($existing[(int) $matchedId]);
        ?>
          <tr class="border-t border-gray-100 import-row" data-row-id="<?= $row['id'] ?>">
            <td class="px-2 py-2 align-top">
              <input type="checkbox" class="useRow mt-1" <?= $matchedId ? 'checked' : '' ?>>
            </td>
            <td class="px-2 py-2 align-top">
              <div class="font-medium"><?= htmlspecialchars($row['raw_name_text']) ?></div>
              <div class="text-[11px] mt-0.5 <?= $confident ? 'text-green-600' : 'text-amber-600' ?>">
                <?= $matchedId ? ($confident ? 'Matched' : 'Uncertain match') . ' (' . round($row['match_confidence']) . '%)' : 'No confident match' ?>
              </div>
              <?php if ($hasConflict): ?>
                <div class="text-[11px] mt-0.5 text-red-600">Already has a record for this date — will be overwritten</div>
              <?php endif; ?>
            </td>
            <td class="px-2 py-2 align-top">
              <select class="staffSelect w-56 rounded-md border border-gray-300 px-2 py-1.5 text-xs">
                <option value="">&mdash; select staff &mdash;</option>
                <?php foreach ($activeStaff as $s): ?>
                  <option value="<?= $s['id'] ?>" <?= ((int) $matchedId === (int) $s['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['full_name']) ?> (<?= htmlspecialchars($s['staff_no']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-2 align-top">
              <select class="statusSelect rounded-md border border-gray-300 px-2 py-1.5 text-xs">
                <?php foreach (['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'leave' => 'On leave'] as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-2 align-top"><input type="time" class="checkInInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-28" value="<?= htmlspecialchars($checkIn) ?>"></td>
            <td class="px-2 py-2 align-top"><input type="time" class="checkOutInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-28" value="<?= htmlspecialchars($checkOut) ?>"></td>
            <td class="px-2 py-2 align-top"><input type="text" class="remarksInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-40" value="<?= htmlspecialchars($row['raw_remarks'] ?? '') ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($importRows)): ?>
    <div class="flex items-center gap-3 mt-5">
      <button id="commitBtn" class="bg-brand-500 text-white font-semibold text-sm rounded-md px-4 py-2.5 hover:brightness-110">Commit to register</button>
      <span id="commitMsg" class="text-sm"></span>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($token) ?>;
window.BATCH_ID = <?= (int) $batchId ?>;
window.REGISTER_DATE = <?= json_encode($batch['register_date']) ?>;

document.getElementById('commitBtn')?.addEventListener('click', function () {
  var btn = this, msg = document.getElementById('commitMsg');
  var rows = [];
  document.querySelectorAll('.import-row').forEach(function (tr) {
    if (!tr.querySelector('.useRow').checked) return;
    var staffId = tr.querySelector('.staffSelect').value;
    if (!staffId) return;
    rows.push({
      staff_id: staffId,
      status: tr.querySelector('.statusSelect').value,
      check_in: tr.querySelector('.checkInInput').value,
      check_out: tr.querySelector('.checkOutInput').value,
      remarks: tr.querySelector('.remarksInput').value
    });
  });

  if (!rows.length) {
    msg.textContent = 'Select at least one row with a matched staff member.';
    msg.className = 'text-sm text-red-600';
    return;
  }

  btn.disabled = true;
  msg.textContent = 'Saving…';
  msg.className = 'text-sm text-gray-500';

  fetch('api/import_commit.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf: window.CSRF_TOKEN, batch_id: window.BATCH_ID, date: window.REGISTER_DATE, rows: rows })
  })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      btn.disabled = false;
      if (data.error) {
        msg.textContent = data.error;
        msg.className = 'text-sm text-red-600';
        return;
      }
      msg.textContent = data.inserted + ' record(s) committed to the register.';
      msg.className = 'text-sm text-green-600';
      btn.textContent = 'Re-commit';
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
