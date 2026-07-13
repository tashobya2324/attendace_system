<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);
$conn = db();

$token = csrf_token();

$recentBatches = $conn->query(
    "SELECT b.id, b.register_date, b.status, b.created_at, b.original_filename,
            (SELECT COUNT(*) FROM attendance_import_rows r WHERE r.batch_id = b.id) AS row_count
     FROM attendance_import_batches b
     ORDER BY b.created_at DESC LIMIT 15"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Import Paper Register';
$activeNav = 'import.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Import Paper Register</h2>
    <p class="text-sm text-gray-500 max-w-2xl">Photograph a past day's paper attendance register and let AI draft a
      first transcription. Nothing is written to the official register until you review and confirm every row on
      the next screen — the AI proposes, you decide.</p>
  </div>
</div>

<div class="grid md:grid-cols-[380px_1fr] gap-6 items-start">

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-1">Upload a register photo</h3>
    <p class="text-xs text-gray-500 mb-4">One photo per day. JPG or PNG, up to 10&nbsp;MB.</p>

    <form id="uploadForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">

      <label class="block text-xs font-semibold text-gray-600 mb-1">Date this register covers</label>
      <input type="date" name="register_date" id="fDate" required max="<?= date('Y-m-d') ?>"
             class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">

      <label class="block text-xs font-semibold text-gray-600 mb-1">Register photo</label>
      <input type="file" name="image" id="fImage" accept="image/jpeg,image/png" required
             class="w-full mb-3 text-sm">

      <div id="preview" class="hidden mb-3 border border-gray-200 rounded-md overflow-hidden">
        <img id="previewImg" class="w-full h-auto" alt="Selected register preview">
      </div>

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
        <p class="text-sm text-gray-400 py-6 text-center">No register photos imported yet.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
            <tr><th class="text-left py-1.5">Date</th><th class="text-left py-1.5">File</th><th class="text-right py-1.5">Rows</th><th class="text-left py-1.5">Status</th><th class="py-1.5"></th></tr>
          </thead>
          <tbody>
          <?php foreach ($recentBatches as $b): ?>
            <tr class="border-t border-gray-100">
              <td class="py-2"><?= htmlspecialchars($b['register_date']) ?></td>
              <td class="py-2 text-gray-500"><?= htmlspecialchars($b['original_filename']) ?></td>
              <td class="py-2 text-right tabular-nums"><?= (int) $b['row_count'] ?></td>
              <td class="py-2">
                <?php $badge = ['pending_review' => 'bg-amber-100 text-amber-700', 'committed' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700'][$b['status']]; ?>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-full <?= $badge ?>"><?= str_replace('_', ' ', ucfirst($b['status'])) ?></span>
              </td>
              <td class="py-2 text-right"><a href="import_review.php?batch=<?= $b['id'] ?>" class="text-xs text-brand-600 hover:underline font-semibold">Review &rarr;</a></td>
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
document.getElementById('fImage').addEventListener('change', function () {
  var file = this.files[0];
  var preview = document.getElementById('preview'), img = document.getElementById('previewImg');
  if (!file) { preview.classList.add('hidden'); return; }
  img.src = URL.createObjectURL(file);
  preview.classList.remove('hidden');
});

document.getElementById('uploadForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var btn = document.getElementById('fSubmit'), msg = document.getElementById('uploadMsg');
  btn.disabled = true;
  btn.textContent = 'Reading register with AI… this can take a few seconds';
  msg.textContent = '';

  fetch('api/import_upload.php', { method: 'POST', body: new FormData(this) })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) {
        btn.disabled = false;
        btn.textContent = 'Extract with AI';
        msg.textContent = data.error;
        msg.className = 'text-xs mt-2 text-red-600';
        return;
      }
      window.location = 'import_review.php?batch=' + data.batch_id;
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
