<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$pageTitle = "Settings";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

// CSRF
if (empty($_SESSION['csrf_settings'])) {
    $_SESSION['csrf_settings'] = bin2hex(random_bytes(16));
}

$userId = (int)$_SESSION['user']['id'];
$flash  = ['ok' => '', 'error' => ''];

// Fetch fresh user row (avoid stale session values)
$stmt = $pdo->prepare("SELECT id, name, email, password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dbUser) { session_destroy(); header("Location: login.php"); exit; }

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    if (!hash_equals($_SESSION['csrf_settings'], $_POST['csrf'] ?? '')) {
        $flash['error'] = "Security check failed. Please try again.";
    } else {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($name === '' || $email === '') {
            $flash['error'] = "Name and email are required.";
        } else {
            // Ensure email unique (except current user)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $flash['error'] = "That email is already in use.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name=?, email=? WHERE id=?");
                $stmt->execute([$name, $email, $userId]);

                // Refresh session user
                $_SESSION['user']['name']  = $name;
                $_SESSION['user']['email'] = $email;
                session_regenerate_id(true);
                $flash['ok'] = "Profile updated.";
                $dbUser['name'] = $name; $dbUser['email'] = $email;
            }
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    if (!hash_equals($_SESSION['csrf_settings'], $_POST['csrf'] ?? '')) {
        $flash['error'] = "Security check failed. Please try again.";
    } else {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $dbUser['password'])) {
            $flash['error'] = "Your current password is incorrect.";
        } elseif ($new !== $confirm) {
            $flash['error'] = "New passwords do not match.";
        } elseif (strlen($new) < 8) {
            $flash['error'] = "Password must be at least 8 characters.";
        } elseif (password_verify($new, $dbUser['password'])) {
            $flash['error'] = "New password must be different from the current one.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([$hash, $userId]);
            session_regenerate_id(true);
            $flash['ok'] = "Password changed successfully.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 via-white to-gray-100 text-gray-900 min-h-screen">

<!-- TOP NAV (JS-free, solid overlay mobile menu) -->
<header class="bg-gray-900 text-white">
  <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
    <!-- Brand -->
    <a class="flex items-center gap-3" href="<?= htmlspecialchars($u('index.php')) ?>">
      <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 grid place-items-center shadow-lg">LB</div>
      <div class="font-semibold tracking-wide">Admin Panel</div>
    </a>

    <!-- Desktop nav -->
    <nav class="hidden md:flex items-center gap-2">
      <a class="px-3 py-2 rounded-lg bg-gray-800 text-white" href="<?= htmlspecialchars($u('index.php')) ?>">Dashboard</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('transactions.php')) ?>">Transactions</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('orders.php')) ?>">Orders</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('customers.php')) ?>">Customers</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('reports.php')) ?>">Reports</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('settings.php')) ?>">Settings</a>
      <!-- Add others if you want:
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('menu.php')) ?>">Menu</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('store.php')) ?>">Store</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800 text-red-400 flex items-center gap-1" href="<?= htmlspecialchars($u('live_orders.php')) ?>">
        <span class="relative flex h-3 w-3">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
        </span>
        Live
      </a>
      -->
    </nav>

    <!-- Desktop right side -->
    <div class="hidden md:flex items-center gap-3">
      <span class="hidden sm:inline text-sm text-gray-300">Hi, <?= htmlspecialchars($_SESSION['user']['name']) ?></span>
      <a href="<?= htmlspecialchars($u('index.php?logout=1')) ?>" class="px-3 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white">Logout</a>
    </div>

    <!-- MOBILE: checkbox + label toggle -->
    <div class="md:hidden">
      <!-- Hidden checkbox controls panel via peer-checked -->
      <input id="navToggle" type="checkbox" class="peer hidden">
      <label for="navToggle" class="h-10 w-10 grid place-items-center rounded-lg hover:bg-gray-800 cursor-pointer" aria-label="Open menu">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </label>

      <!-- FULL-SCREEN, OPAQUE overlay (no blur issues) -->
      <div class="hidden peer-checked:block fixed inset-0 z-50 bg-gray-900">
        <!-- top bar inside overlay with close button -->
        <div class="flex items-center justify-between px-4 h-14 border-b border-gray-800">
          <div class="flex items-center gap-3">
            <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 grid place-items-center">LB</div>
            <div class="font-semibold">Menu</div>
          </div>
          <!-- same checkbox label closes it -->
          <label for="navToggle" class="h-10 w-10 grid place-items-center rounded-lg hover:bg-gray-800 cursor-pointer" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </label>
        </div>
        <!-- links -->
        <div class="px-4 py-3 grid gap-2">
          <a class="block px-3 py-2 rounded-lg bg-gray-800 text-white" href="<?= htmlspecialchars($u('index.php')) ?>">Dashboard</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('transactions.php')) ?>">Transactions</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('orders.php')) ?>">Orders</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('customers.php')) ?>">Customers</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('reports.php')) ?>">Reports</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('settings.php')) ?>">Settings</a>
          <!-- Optional extras:
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('menu.php')) ?>">Menu</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('store.php')) ?>">Store</a>
          <a class="block px-3 py-2 rounded-lg hover:bg-gray-800 text-red-400" href="<?= htmlspecialchars($u('live_orders.php')) ?>">Live</a>
          -->
          <a class="block mt-2 px-3 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white" href="<?= htmlspecialchars($u('index.php?logout=1')) ?>">Logout</a>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-8 space-y-8">

  <!-- Alerts -->
  <?php if ($flash['ok']): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg p-3 text-sm"><?= htmlspecialchars($flash['ok']) ?></div>
  <?php endif; ?>
  <?php if ($flash['error']): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-lg p-3 text-sm"><?= htmlspecialchars($flash['error']) ?></div>
  <?php endif; ?>

  <!-- Profile -->
  <section class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
    <h2 class="text-lg font-semibold mb-4">Profile</h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_settings']) ?>">
      <input type="hidden" name="action" value="profile">

      <div>
        <label class="block text-sm font-medium mb-1">Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($dbUser['name'] ?? '') ?>" required class="border p-2 rounded w-full">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($dbUser['email'] ?? '') ?>" required class="border p-2 rounded w-full">
      </div>

      <div class="md:col-span-2">
        <button class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">Save Changes</button>
      </div>
    </form>
  </section>

  <!-- Password -->
  <section class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
    <h2 class="text-lg font-semibold mb-4">Change Password</h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_settings']) ?>">
      <input type="hidden" name="action" value="password">

      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Current Password</label>
        <input type="password" name="current_password" required class="border p-2 rounded w-full" placeholder="••••••••">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">New Password</label>
        <input type="password" name="new_password" required class="border p-2 rounded w-full" placeholder="At least 8 characters">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Confirm New Password</label>
        <input type="password" name="confirm_password" required class="border p-2 rounded w-full" placeholder="Re-enter new password">
      </div>

      <div class="md:col-span-2">
        <button class="bg-gray-900 text-white px-4 py-2 rounded-lg hover:bg-gray-800">Update Password</button>
      </div>
    </form>
    <p class="mt-3 text-xs text-gray-500">Tip: use a passphrase or a mix of letters, numbers, and symbols.</p>
  </section>

</main>

<!-- Footer -->
<footer class="bg-gray-900 text-gray-300 mt-10">
  <div class="max-w-7xl mx-auto px-4 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <span class="text-sm">&copy; <?= date('Y') ?> LB Admin Panel. All rights reserved.</span>
    <nav class="flex gap-4 text-sm">
      <a href="<?= htmlspecialchars($u('reports.php')) ?>" class="hover:text-white transition">Reports</a>
      <a href="<?= htmlspecialchars($u('settings.php')) ?>" class="hover:text-white transition">Settings</a>
      <a href="<?= htmlspecialchars($u('help.php')) ?>" class="hover:text-white transition">Help</a>
    </nav>
  </div>
</footer>

</body>
</html>
