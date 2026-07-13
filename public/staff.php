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
        $staffNo = trim($_POST['staff_no'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $deptId = (int) ($_POST['department_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($staffNo === '' || $fullName === '' || $designation === '' || !$deptId) {
            $error = 'Staff number, full name, designation and department are required.';
        } else {
            $stmt = $conn->prepare('INSERT INTO staff (staff_no, full_name, designation, department_id, phone, email, date_joined) VALUES (?,?,?,?,?,?,CURDATE())');
            $stmt->bind_param('sssiss', $staffNo, $fullName, $designation, $deptId, $phone, $email);
            try {
                $stmt->execute();
                $success = 'Staff member added.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not add staff — staff number may already exist.';
            }
            $stmt->close();
        }
    } elseif ($action === 'deactivate') {
        $id = (int) ($_POST['staff_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE staff SET status='retired' WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $success = 'Staff member marked inactive.';
    } elseif ($action === 'reactivate') {
        $id = (int) ($_POST['staff_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE staff SET status='active' WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $success = 'Staff member reactivated.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['staff_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM staff WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $success = 'Staff member and their attendance history were permanently deleted.';
    }
}

$departments = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$staffList = $conn->query("SELECT s.*, d.name AS dept FROM staff s JOIN departments d ON d.id=s.department_id ORDER BY s.status='active' DESC, s.full_name")->fetch_all(MYSQLI_ASSOC);
$token = csrf_token();

$pageTitle = 'Staff';
$activeNav = 'staff.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h2 class="font-serif text-2xl font-medium">Staff Establishment</h2>
    <p class="text-sm text-gray-500"><?= count($staffList) ?> staff on record across <?= count($departments) ?> departments.</p>
  </div>
</div>

<?php if ($error): ?><div class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid md:grid-cols-[320px_1fr] gap-6 items-start">
  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <h3 class="font-serif text-lg font-semibold mb-3">Add staff member</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="form_action" value="add">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Staff number</label>
      <input name="staff_no" required class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="MB-0031">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Full name</label>
      <input name="full_name" required class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Designation</label>
      <input name="designation" required class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Records Officer">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Department</label>
      <select name="department_id" required class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">
        <option value="">Select department</option>
        <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
      </select>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Phone</label>
      <input name="phone" class="w-full mb-3 rounded-md border border-gray-300 px-3 py-2 text-sm">
      <label class="block text-xs font-semibold text-gray-600 mb-1">Email</label>
      <input name="email" type="email" class="w-full mb-4 rounded-md border border-gray-300 px-3 py-2 text-sm">
      <button class="w-full bg-brand-500 text-white font-semibold text-sm rounded-md py-2.5 hover:brightness-110">Add staff member</button>
    </form>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[700px]">
        <thead class="text-[10.5px] uppercase tracking-wide text-gray-400">
          <tr><th class="text-left px-3 py-2">Staff No.</th><th class="text-left px-3 py-2">Name</th><th class="text-left px-3 py-2">Designation</th><th class="text-left px-3 py-2">Department</th><th class="text-left px-3 py-2">Status</th><th class="px-3 py-2"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($staffList as $s): ?>
          <tr class="border-t border-gray-100">
            <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars($s['staff_no']) ?></td>
            <td class="px-3 py-2 font-medium"><?= htmlspecialchars($s['full_name']) ?></td>
            <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($s['designation']) ?></td>
            <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($s['dept']) ?></td>
            <td class="px-3 py-2">
              <span class="text-[11px] font-bold px-2.5 py-1 rounded-full <?= $s['status']==='active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= ucfirst($s['status']) ?></span>
            </td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <?php if ($s['status'] === 'active'): ?>
              <form method="post" class="inline" onsubmit="return confirm('Mark this staff member inactive? Their attendance history is kept.');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="form_action" value="deactivate">
                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                <button class="text-xs text-amber-700 hover:underline mr-3">Deactivate</button>
              </form>
              <?php else: ?>
              <form method="post" class="inline" onsubmit="return confirm('Reactivate this staff member?');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="form_action" value="reactivate">
                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                <button class="text-xs text-green-700 hover:underline mr-3">Reactivate</button>
              </form>
              <?php endif; ?>
              <form method="post" class="inline" onsubmit="return confirm('Permanently delete this staff member and ALL of their attendance records? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
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
