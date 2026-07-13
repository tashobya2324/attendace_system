<?php
/** @var string $pageTitle */
/** @var string $activeNav */
$user = current_user();
$districtName = get_setting('district_name', 'Mbarara District Local Government');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Staff Attendance System') ?> — Mbarara DLG</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: {
            50: '#eef4fb', 100: '#d6e6f6', 200: '#adcdee', 300: '#7fb0e2',
            400: '#4a8ed3', 500: '#1c5cab', 600: '#164a8a', 700: '#123a6d',
            800: '#0f2e56', 900: '#0b2242'
          },
          gold: { 500: '#b9791f', 600: '#8f5c15' }
        },
        fontFamily: {
          serif: ['Georgia', '"Iowan Old Style"', '"Palatino Linotype"', 'serif'],
        }
      }
    }
  }
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
  body { font-family: -apple-system, "Segoe UI", system-ui, sans-serif; }
  .font-serif { font-family: Georgia, "Iowan Old Style", "Palatino Linotype", serif; }
  ::selection { background: #adcdee; color: #0b2242; }
  @media print {
    .no-print, nav, #sendReportBtn, #sendMsg, a[href*="print=1"] { display: none !important; }
  }
</style>
</head>
<body class="bg-[#f1efe8] text-[#17202b] min-h-screen">

<?php if ($user): ?>
<div class="border-b-2 border-[#17202b] bg-[#fcfcfa]">
  <div class="max-w-6xl mx-auto px-6 py-4 flex items-end justify-between gap-6 flex-wrap">
    <div class="flex items-center gap-3">
      <img src="assets/img/mbarara-emblem.svg" alt="Mbarara coat of arms" class="w-11 h-12 flex-none">
      <div>
        <div class="text-[11px] tracking-widest uppercase text-gray-600 font-semibold">Mbarara District Local Government</div>
        <h1 class="font-serif text-xl font-medium leading-tight">Staff Attendance System</h1>
      </div>
    </div>
    <nav class="flex items-center gap-1 flex-wrap">
      <?php
      $navItems = [];
      if (in_array($user['role'], ['hr', 'admin'], true)) {
          $navItems[] = ['dashboard.php', 'Dashboard'];
          $navItems[] = ['register.php', 'Daily Register'];
          $navItems[] = ['import.php', 'Import Register'];
          $navItems[] = ['staff.php', 'Staff'];
          $navItems[] = ['departments.php', 'Departments'];
      }
      $navItems[] = ['reports.php', 'Monthly Report'];
      foreach ($navItems as [$href, $label]):
          $isActive = ($activeNav ?? '') === $href;
      ?>
        <a href="<?= $href ?>" class="px-3 py-2 rounded-md text-sm font-semibold <?= $isActive ? 'bg-brand-500 text-white' : 'text-gray-700 hover:bg-gray-100' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <div class="w-px h-6 bg-gray-300 mx-2"></div>
      <div class="text-sm text-gray-600 mr-1"><?= htmlspecialchars($user['full_name']) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars($user['role']) ?>)</span></div>
      <a href="logout.php" class="px-3 py-2 rounded-md text-sm font-semibold text-red-700 hover:bg-red-50">Logout</a>
    </nav>
  </div>
</div>
<?php endif; ?>

<div class="max-w-6xl mx-auto px-6 py-8">
