<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);
$conn = db();

$batchId = (int) ($_GET['batch'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM staff_import_batches WHERE id = ?');
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

$rows = $conn->prepare('SELECT * FROM staff_import_rows WHERE batch_id = ? ORDER BY row_index');
$rows->bind_param('i', $batchId);
$rows->execute();
$importRows = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
$rows->close();

$departments = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// Existing staff numbers/names, so obvious duplicates are flagged before commit.
$existingStaffNos = [];
$existingNames = [];
$sRes = $conn->query('SELECT staff_no, full_name FROM staff');
while ($row = $sRes->fetch_assoc()) {
    $existingStaffNos[strtoupper($row['staff_no'])] = true;
    $existingNames[strtolower(trim($row['full_name']))] = true;
}

$isImage = $batch['file_type'] === 'image';
$isPdf = $batch['file_type'] === 'pdf';

$token = csrf_token();
$pageTitle = 'Review Staff Import';
$activeNav = 'staff_import.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Review Staff Import</h2>
    <p class="text-sm text-gray-500 max-w-2xl">Check every row before adding it to the establishment. Nothing here is
      final until you select <strong>Commit to establishment</strong> below — rows you leave unchecked are simply
      discarded.</p>
  </div>
  <a href="staff_import.php" class="text-sm text-gray-500 hover:underline">&larr; Back to imports</a>
</div>

<?php if ($batch['status'] === 'committed'): ?>
  <div class="mb-6 text-sm text-green-800 bg-green-50 border border-green-200 rounded-md px-4 py-3">
    This batch was already committed on <?= date('j F Y, g:i a', strtotime($batch['reviewed_at'])) ?>.
    Re-committing will add any still-checked rows again — check for duplicates first.
  </div>
<?php endif; ?>

<?php if ($batch['error_message']): ?>
  <div class="mb-6 text-sm text-red-800 bg-red-50 border border-red-200 rounded-md px-4 py-3">
    <strong>AI extraction failed:</strong> <?= htmlspecialchars($batch['error_message']) ?>
  </div>
<?php elseif (empty($importRows)): ?>
  <div class="mb-6 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-4 py-3">
    No readable rows were found in this document.
  </div>
<?php else: ?>
  <div class="mb-6 text-xs text-gray-400">
    Extracted via <?= $batch['extraction_method'] === 'direct_parse' ? 'direct spreadsheet parsing' : 'AI (' . htmlspecialchars($batch['ai_model']) . ')' ?>.
  </div>
<?php endif; ?>

<div class="grid <?= ($isImage || $isPdf) ? 'lg:grid-cols-[380px_1fr]' : '' ?> gap-6 items-start">

  <?php if ($isImage): ?>
  <div class="bg-white border border-gray-200 rounded-lg p-3 lg:sticky lg:top-4">
    <img src="api/staff_import_image.php?batch=<?= $batchId ?>" alt="Source document" class="w-full h-auto rounded">
    <p class="text-xs text-gray-400 mt-2 px-1"><?= htmlspecialchars($batch['original_filename']) ?></p>
  </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <?php if (!empty($importRows)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[900px]" id="reviewTable">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
          <tr>
            <th class="text-left px-2 py-2 w-8">Use</th>
            <th class="text-left px-2 py-2">Full name</th>
            <th class="text-left px-2 py-2">Department</th>
            <th class="text-left px-2 py-2">Designation</th>
            <th class="text-left px-2 py-2">Staff No.</th>
            <th class="px-2 py-2 w-8"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($importRows as $row):
            $dupName = isset($existingNames[strtolower(trim($row['raw_full_name']))]);
            $dupStaffNo = $row['raw_staff_no'] && isset($existingStaffNos[strtoupper($row['raw_staff_no'])]);
        ?>
          <tr class="border-t border-gray-100 import-row" data-row-id="<?= $row['id'] ?>">
            <td class="px-2 py-2 align-top">
              <input type="checkbox" class="useRow mt-1" checked>
            </td>
            <td class="px-2 py-2 align-top">
              <input type="text" class="nameInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-48" value="<?= htmlspecialchars($row['raw_full_name']) ?>">
              <?php if ($dupName): ?><div class="text-[11px] mt-0.5 text-amber-600">Name looks similar to an existing staff member</div><?php endif; ?>
            </td>
            <td class="px-2 py-2 align-top">
              <select class="deptSelect rounded-md border border-gray-300 px-2 py-1.5 text-xs w-44">
                <option value="">&mdash; select department &mdash;</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ((int) $row['matched_department_id'] === (int) $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
                <option value="__new__">+ Create new department&hellip;</option>
              </select>
              <input type="text" class="newDeptInput hidden mt-1 rounded-md border border-gray-300 px-2 py-1.5 text-xs w-44" placeholder="New department name">
              <?php if (!$row['matched_department_id'] && $row['raw_department']): ?>
                <div class="text-[11px] mt-0.5 text-amber-600">As written: "<?= htmlspecialchars($row['raw_department']) ?>" — no confident match</div>
              <?php endif; ?>
            </td>
            <td class="px-2 py-2 align-top"><input type="text" class="designationInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-36" value="<?= htmlspecialchars($row['raw_designation'] ?? '') ?>" placeholder="Staff"></td>
            <td class="px-2 py-2 align-top">
              <input type="text" class="staffNoInput rounded-md border border-gray-300 px-2 py-1.5 text-xs w-28" value="<?= htmlspecialchars($row['raw_staff_no'] ?? '') ?>" placeholder="auto">
              <?php if ($dupStaffNo): ?><div class="text-[11px] mt-0.5 text-red-600">Already in use</div><?php endif; ?>
            </td>
            <td class="px-2 py-2 align-top">
              <button type="button" class="deleteRowBtn text-gray-400 hover:text-red-600" title="Remove this row from the preview">&times;</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex items-center gap-3 mt-5">
      <button id="commitBtn" class="bg-brand-500 text-white font-semibold text-sm rounded-md px-4 py-2.5 hover:brightness-110">Commit to establishment</button>
      <span id="commitMsg" class="text-sm"></span>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($token) ?>;
window.BATCH_ID = <?= (int) $batchId ?>;

document.querySelectorAll('.deptSelect').forEach(function (sel) {
  sel.addEventListener('change', function () {
    var newInput = sel.closest('td').querySelector('.newDeptInput');
    newInput.classList.toggle('hidden', sel.value !== '__new__');
  });
});

document.querySelectorAll('.deleteRowBtn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var tr = btn.closest('.import-row');
    if (!confirm('Remove this row from the preview?')) return;
    var body = new URLSearchParams();
    body.set('csrf', window.CSRF_TOKEN);
    body.set('row_id', tr.getAttribute('data-row-id'));
    fetch('api/staff_import_delete_row.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) { if (!data.error) tr.remove(); });
  });
});

document.getElementById('commitBtn')?.addEventListener('click', function () {
  var btn = this, msg = document.getElementById('commitMsg');
  var rows = [];
  var invalid = false;

  document.querySelectorAll('.import-row').forEach(function (tr) {
    if (!tr.querySelector('.useRow').checked) return;
    var name = tr.querySelector('.nameInput').value.trim();
    if (!name) return;
    var deptSelect = tr.querySelector('.deptSelect');
    var deptId = deptSelect.value;
    var newDept = tr.querySelector('.newDeptInput').value.trim();
    if (deptId === '__new__' && !newDept) { invalid = true; }
    rows.push({
      full_name: name,
      department_id: deptId !== '__new__' ? deptId : '',
      new_department_name: deptId === '__new__' ? newDept : '',
      designation: tr.querySelector('.designationInput').value.trim(),
      staff_no: tr.querySelector('.staffNoInput').value.trim()
    });
  });

  if (invalid) {
    msg.textContent = 'Enter a name for each new department, or pick an existing one.';
    msg.className = 'text-sm text-red-600';
    return;
  }
  if (!rows.length) {
    msg.textContent = 'Select at least one row with a name.';
    msg.className = 'text-sm text-red-600';
    return;
  }

  btn.disabled = true;
  msg.textContent = 'Saving…';
  msg.className = 'text-sm text-gray-500';

  fetch('api/staff_import_commit.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf: window.CSRF_TOKEN, batch_id: window.BATCH_ID, rows: rows })
  })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      btn.disabled = false;
      if (data.error) {
        msg.textContent = data.error;
        msg.className = 'text-sm text-red-600';
        return;
      }
      msg.textContent = data.inserted + ' staff member(s) added' + (data.skipped ? ', ' + data.skipped + ' skipped (duplicate staff no.)' : '') + '.';
      msg.className = 'text-sm text-green-600';
      btn.textContent = 'Re-commit';
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
