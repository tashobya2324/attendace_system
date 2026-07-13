<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';
$user = require_role(['hr', 'admin']);
$conn = db();

$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Department name is required.';
        } else {
            $stmt = $conn->prepare('INSERT INTO departments (name) VALUES (?)');
            $stmt->bind_param('s', $name);
            try {
                $stmt->execute();
                $success = 'Department added.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not add department — that name may already exist.';
            }
            $stmt->close();
        }
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['department_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || !$id) {
            $error = 'A valid department and name are required.';
        } else {
            $stmt = $conn->prepare('UPDATE departments SET name = ? WHERE id = ?');
            $stmt->bind_param('si', $name, $id);
            try {
                $stmt->execute();
                $success = 'Department renamed.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not rename department — that name may already exist.';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['department_id'] ?? 0);
        $countStmt = $conn->prepare('SELECT COUNT(*) c FROM staff WHERE department_id = ?');
        $countStmt->bind_param('i', $id);
        $countStmt->execute();
        $count = (int) $countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();

        if ($count > 0) {
            $error = "Cannot delete this department — {$count} staff record(s) are still assigned to it. Reassign or remove those staff first.";
        } else {
            $stmt = $conn->prepare('DELETE FROM departments WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $success = 'Department deleted.';
        }
    }
}

$departments = $conn->query(
    "SELECT d.id, d.name, COUNT(s.id) AS staff_count
     FROM departments d
     LEFT JOIN staff s ON s.department_id = d.id AND s.status = 'active'
     GROUP BY d.id, d.name
     ORDER BY d.name"
)->fetch_all(MYSQLI_ASSOC);

$token = csrf_token();
$pageTitle = 'Departments';
$activeNav = 'departments.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Departments</h2>
    <p class="text-sm text-gray-500"><?= count($departments) ?> departments on record.</p>
  </div>
</div>

<?php if ($error): ?><div class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid md:grid-cols-[320px_1fr] gap-6 items-start">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-3">Add department</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="form_action" value="add">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Department name</label>
      <input name="name" required class="w-full mb-4 rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="e.g. Natural Resources">
      <button class="w-full bg-brand-500 text-white font-semibold text-sm rounded-md py-2.5 hover:brightness-110">Add department</button>
    </form>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
          <tr><th class="text-left px-3 py-2">Department</th><th class="text-right px-3 py-2">Active staff</th><th class="px-3 py-2"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($departments as $d): ?>
          <tr class="border-t border-gray-100">
            <td class="px-3 py-2">
              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="form_action" value="rename">
                <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                <input name="name" value="<?= htmlspecialchars($d['name']) ?>" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm w-56">
                <button class="text-xs font-semibold text-brand-600 hover:underline">Save</button>
              </form>
            </td>
            <td class="px-3 py-2 text-right tabular-nums"><?= $d['staff_count'] ?></td>
            <td class="px-3 py-2 text-right">
              <form method="post" onsubmit="return confirm('Delete this department? This only works if no staff are assigned to it.');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                <button class="text-xs text-red-600 hover:underline">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
