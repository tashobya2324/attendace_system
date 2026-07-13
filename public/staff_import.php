<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);
$conn = db();

$token = csrf_token();

$recentBatches = $conn->query(
    "SELECT b.id, b.original_filename, b.file_type, b.status, b.created_at,
            (SELECT COUNT(*) FROM staff_import_rows r WHERE r.batch_id = b.id) AS row_count
     FROM staff_import_batches b
     ORDER BY b.created_at DESC LIMIT 15"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Mass Staff Entry';
$activeNav = 'staff_import.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Mass Staff Entry</h2>
    <p class="text-sm text-gray-500 max-w-2xl">Upload a nominal roll — an Excel sheet, Word document, PDF, or a photo
      of a printed list — and let AI draft the staff records. Nothing is added to the establishment until you
      review and confirm every row on the next screen.</p>
  </div>
</div>

<div class="grid md:grid-cols-[380px_1fr] gap-6 items-start">

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-1">Upload a staff list</h3>
    <p class="text-xs text-gray-500 mb-4">Excel (.xlsx), CSV, Word (.docx), PDF, or a photo (JPG/PNG). Up to 10&nbsp;MB.</p>

    <form id="uploadForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">

      <label class="block text-xs font-semibold text-gray-600 mb-1">Document</label>
      <input type="file" name="document" id="fDoc" required
             accept=".csv,.xlsx,.docx,.pdf,image/jpeg,image/png"
             class="w-full mb-3 text-sm">

      <button id="fSubmit" type="submit" class="w-full bg-brand-500 text-white font-semibold text-sm rounded-md py-2.5 hover:brightness-110">
        Extract with AI
      </button>
      <div id="uploadMsg" class="text-xs mt-2"></div>
    </form>
  </div>

  <div>
    <div class="bg-white border border-gray-200 rounded-lg p-5">
      <h3 class="font-serif text-lg font-semibold mb-3">Recent imports</h3>
      <?php if (empty($recentBatches)): ?>
        <p class="text-sm text-gray-400 py-6 text-center">No staff lists imported yet.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
            <tr><th class="text-left py-1.5">File</th><th class="text-left py-1.5">Type</th><th class="text-right py-1.5">Rows</th><th class="text-left py-1.5">Status</th><th class="py-1.5"></th></tr>
          </thead>
          <tbody>
          <?php foreach ($recentBatches as $b): ?>
            <tr class="border-t border-gray-100">
              <td class="py-2 text-gray-600"><?= htmlspecialchars($b['original_filename']) ?></td>
              <td class="py-2 text-gray-400 uppercase text-xs"><?= htmlspecialchars($b['file_type']) ?></td>
              <td class="py-2 text-right tabular-nums"><?= (int) $b['row_count'] ?></td>
              <td class="py-2">
                <?php $badge = ['pending_review' => 'bg-amber-100 text-amber-700', 'committed' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700'][$b['status']]; ?>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-full <?= $badge ?>"><?= str_replace('_', ' ', ucfirst($b['status'])) ?></span>
              </td>
              <td class="py-2 text-right whitespace-nowrap">
                <a href="staff_import_review.php?batch=<?= $b['id'] ?>" class="text-xs text-brand-600 hover:underline font-semibold mr-3">Review &rarr;</a>
                <?php if ($b['status'] !== 'committed'): ?>
                  <button type="button" class="deleteBatchBtn text-xs text-red-600 hover:underline" data-batch-id="<?= $b['id'] ?>">Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.deleteBatchBtn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (!confirm('Delete this pending import and its extracted rows?')) return;
    var body = new URLSearchParams();
    body.set('csrf', <?= json_encode($token) ?>);
    body.set('batch_id', btn.getAttribute('data-batch-id'));
    fetch('api/staff_import_delete_batch.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { alert(data.error); return; }
        btn.closest('tr').remove();
      });
  });
});

document.getElementById('uploadForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var btn = document.getElementById('fSubmit'), msg = document.getElementById('uploadMsg');
  btn.disabled = true;
  btn.textContent = 'Reading document with AI… this can take a few seconds';
  msg.textContent = '';

  fetch('api/staff_import_upload.php', { method: 'POST', body: new FormData(this) })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) {
        btn.disabled = false;
        btn.textContent = 'Extract with AI';
        msg.textContent = data.error;
        msg.className = 'text-xs mt-2 text-red-600';
        return;
      }
      window.location = 'staff_import_review.php?batch=' + data.batch_id;
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Extract with AI';
      msg.textContent = 'Upload failed — check your connection and try again.';
      msg.className = 'text-xs mt-2 text-red-600';
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
