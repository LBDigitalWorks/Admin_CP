<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$pageTitle = "Customers";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

$editCustomer = null;

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: " . $u('customers.php'));
    exit;
}

// Handle edit mode (load customer data)
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $editCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission (add or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['email'])) {
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($_POST['update_id'])) {
        // Update existing
        $id = (int) $_POST['update_id'];
        $stmt = $pdo->prepare("UPDATE customers SET name=?, email=?, phone=?, notes=? WHERE id=?");
        $stmt->execute([$name, $email, $phone, $notes, $id]);
    } else {
        // Add new
        $stmt = $pdo->prepare("INSERT INTO customers (name, email, phone, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $phone, $notes]);
    }
    header("Location: " . $u('customers.php'));
    exit;
}

// Filters
$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Fetch customers
$stmt = $pdo->prepare("SELECT * FROM customers $sqlWhere ORDER BY created_at DESC");
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

  <!-- Page title + search -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <h1 class="text-2xl font-semibold">Customers</h1>
    <form method="get" class="flex items-center gap-2">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, phone" class="border rounded-lg p-2 w-64 max-w-full">
      <?php if ($search !== ''): ?>
        <a href="<?= htmlspecialchars($u('customers.php')) ?>" class="px-3 py-2 rounded-lg border hover:bg-gray-100">Reset</a>
      <?php endif; ?>
      <button class="px-3 py-2 rounded-lg bg-gray-900 text-white">Search</button>
    </form>
  </div>

  <!-- Add/Edit card -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 max-w-2xl">
    <h2 class="text-lg font-semibold mb-4"><?= $editCustomer ? 'Edit Customer' : 'Add Customer' ?></h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="update_id" value="<?= $editCustomer['id'] ?? '' ?>">

      <div class="md:col-span-1">
        <label class="block text-sm font-medium mb-1">Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editCustomer['name'] ?? '') ?>" required class="border p-2 rounded w-full">
      </div>

      <div class="md:col-span-1">
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($editCustomer['email'] ?? '') ?>" required class="border p-2 rounded w-full">
      </div>

      <div class="md:col-span-1">
        <label class="block text-sm font-medium mb-1">Phone</label>
        <input type="text" name="phone" value="<?= htmlspecialchars($editCustomer['phone'] ?? '') ?>" class="border p-2 rounded w-full">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Notes</label>
        <textarea name="notes" class="border p-2 rounded w-full" rows="3"><?= htmlspecialchars($editCustomer['notes'] ?? '') ?></textarea>
      </div>

      <div class="md:col-span-2 flex gap-2">
        <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
          <?= $editCustomer ? 'Update Customer' : 'Add Customer' ?>
        </button>
        <?php if ($editCustomer): ?>
          <a href="<?= htmlspecialchars($u('customers.php')) ?>" class="px-4 py-2 rounded-lg border hover:bg-gray-100">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 overflow-x-auto">
    <div class="mb-3 text-sm text-gray-500">
      <?= count($customers) ?> result<?= count($customers) === 1 ? '' : 's' ?><?= $search ? ' for “'.htmlspecialchars($search).'”' : '' ?>
    </div>
    <table class="min-w-full">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Name</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Email</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Phone</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Notes</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Date Added</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$customers): ?>
          <tr>
            <td colspan="6" class="px-3 py-8 text-center text-gray-500">No customers found.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($customers as $c): ?>
          <tr class="border-t hover:bg-gray-50">
            <td class="px-3 py-2"><?= htmlspecialchars($c['name'] ?? '') ?></td>
            <td class="px-3 py-2">
              <?php if (!empty($c['email'])): ?>
                <a class="text-indigo-600 hover:underline" href="mailto:<?= htmlspecialchars($c['email']) ?>"><?= htmlspecialchars($c['email']) ?></a>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
            <td class="px-3 py-2"><?= nl2br(htmlspecialchars($c['notes'] ?? '')) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($c['created_at'] ?? '') ?></td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($u('customers.php?edit=' . (int)$c['id'])) ?>" class="px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white">Edit</a>
                <a href="<?= htmlspecialchars($u('customers.php?delete=' . (int)$c['id'])) ?>" onclick="return confirm('Delete this customer?')" class="px-3 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white">Delete</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- Footer (dark, matches header) -->
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
