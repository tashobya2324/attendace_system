<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analysis.php';

if (current_user()) {
    header('Location: ' . (current_user()['role'] === 'cao' ? 'reports.php' : 'dashboard.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user = attempt_login($username, $password);
    if ($user) {
        header('Location: ' . ($user['role'] === 'cao' ? 'reports.php' : 'dashboard.php'));
        exit;
    }
    $error = 'Incorrect username or password.';
}
$token = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Mbarara DLG Staff Attendance System</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = { theme: { extend: { colors: { brand: {
    50:'#eef4fb',100:'#d6e6f6',200:'#adcdee',300:'#7fb0e2',400:'#4a8ed3',
    500:'#1c5cab',600:'#164a8a',700:'#123a6d',800:'#0f2e56',900:'#0b2242' } } } } }
</script>
<style>body{font-family:-apple-system,"Segoe UI",system-ui,sans-serif;}.font-serif{font-family:Georgia,"Iowan Old Style","Palatino Linotype",serif;}</style>
</head>
<body class="bg-[#f1efe8] min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="flex flex-col items-center mb-8">
      <img src="assets/img/mbarara-emblem.svg" alt="Mbarara coat of arms" class="w-20 h-24 mb-3">
      <div class="text-[11px] tracking-widest uppercase text-gray-600 font-semibold">Mbarara District Local Government</div>
      <h1 class="font-serif text-2xl font-medium mt-1">Staff Attendance System</h1>
      <p class="text-xs text-gray-500 mt-1">Human Resource Management Unit</p>
    </div>

    <form method="post" class="bg-white border border-gray-200 rounded-xl shadow-sm p-7">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <?php if ($error): ?>
        <div class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <label class="block text-xs font-semibold text-gray-600 mb-1" for="username">Username</label>
      <input id="username" name="username" required autofocus class="w-full mb-4 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" placeholder="hr.officer or cao">

      <label class="block text-xs font-semibold text-gray-600 mb-1" for="password">Password</label>
      <input id="password" name="password" type="password" required class="w-full mb-5 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">

      <button class="w-full bg-brand-500 hover:brightness-110 text-white font-semibold text-sm rounded-md py-2.5">Sign in</button>

      <p class="text-[11px] text-gray-400 mt-4 leading-relaxed">
        Demo accounts &mdash; HR officer: <code>hr.officer</code>, CAO: <code>cao</code>. Password for both: <code>password123</code>.
        Change these immediately after import in a real deployment.
      </p>
    </form>
  </div>
</body>
</html>
