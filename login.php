<?php
require __DIR__ . '/config.php';

// Ensure session exists (in case config.php doesn't start it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, bounce to dashboard
if (!empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

$error = '';
// Very light rate limit: 1 attempt every 2 seconds
if (!empty($_SESSION['last_login_try']) && time() - $_SESSION['last_login_try'] < 2) {
    $error = 'Please wait a moment before trying again.';
}
$_SESSION['last_login_try'] = time();

// CSRF token
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $csrfOk = isset($_POST['csrf']) && hash_equals($_SESSION['csrf_login'], $_POST['csrf']);
    if (!$csrfOk) {
        $error = 'Security check failed. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password'])) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'    => (int)$user['id'],
                'name'  => $user['name'] ?? '',
                'email' => $user['email'] ?? ''
            ];
            header("Location: " . $u('index.php'));
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login – Admin Panel</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-white to-gray-100 text-gray-900">

<!-- Shell -->
<div class="grid lg:grid-cols-2 min-h-screen">
  <!-- Left: Brand / Accent -->
  <div class="hidden lg:flex items-center justify-center bg-gray-900 text-white p-10">
    <div class="max-w-md">
      <div class="h-12 w-12 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 grid place-items-center text-gray-900 font-extrabold shadow-lg mb-6">LB</div>
      <h1 class="text-3xl font-semibold">L&amp;B Admin Panel</h1>
      <p class="mt-3 text-gray-300">Manage transactions, customers, and reports from one clean dashboard.</p>
    </div>
  </div>

  <!-- Right: Form -->
  <div class="flex items-center justify-center p-6">
    <div class="w-full max-w-md">
      <h2 class="text-2xl font-semibold text-gray-900">Sign in</h2>
      <p class="mt-1 text-sm text-gray-500">Access your admin control panel</p>

      <?php if (!empty($error)): ?>
        <div class="mt-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg p-3 text-sm">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form class="mt-6 space-y-4" method="post" action="">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_login']) ?>">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" required autocomplete="email"
                 class="w-full border rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                 placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <div class="relative">
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   class="w-full border rounded-lg p-2 pr-10 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                   placeholder="••••••••">
            <button type="button" id="togglePass"
                    class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-800"
                    aria-label="Show password">👁</button>
          </div>
        </div>

        <button type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg p-2.5">
          Sign in
        </button>
      </form>

      <div class="mt-6 flex items-center justify-between text-sm">
        <a class="text-gray-500 hover:text-gray-800" href="<?= htmlspecialchars($u('index.php')) ?>">← Back to site</a>
        <a class="text-gray-500 hover:text-gray-800" href="<?= htmlspecialchars($u('password_reset.php')) ?>">Forgot password?</a>
      </div>

      <footer class="mt-10 text-center text-xs text-gray-500">
        &copy; <?= date('Y') ?> L&amp;B Digital. All rights reserved.
      </footer>
    </div>
  </div>
</div>

<script>
  const btn = document.getElementById('togglePass');
  const pwd = document.getElementById('password');
  if (btn && pwd) {
    btn.addEventListener('click', () => {
      pwd.type = pwd.type === 'password' ? 'text' : 'password';
      btn.textContent = pwd.type === 'password' ? '👁' : '🙈';
    });
  }
</script>
</body>
</html>

