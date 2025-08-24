<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$pageTitle = "Orders";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

$editOrder = null;

// Fetch customers for select
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Delete
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: " . $u('orders.php'));
    exit;
}

// Load edit
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $editOrder = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Add/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['total'], $_POST['status'])) {
    $total    = (float) $_POST['total'];
    $status   = in_array($_POST['status'], ['pending','paid','refunded','cancelled'], true) ? $_POST['status'] : 'pending';
    $customer = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
    $notes    = trim($_POST['notes'] ?? '');
    $user_id  = (int) $_SESSION['user']['id'];

    if (!empty($_POST['update_id'])) {
        $id = (int) $_POST['update_id'];
        $stmt = $pdo->prepare("UPDATE orders SET customer_id=?, total=?, status=?, notes=? WHERE id=?");
        $stmt->execute([$customer, $total, $status, $notes, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO orders (customer_id, user_id, total, status, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$customer, $user_id, $total, $status, $notes]);
    }
    header("Location: " . $u('orders.php'));
    exit;
}

/* -------- Filters / Search / Pagination -------- */
$status = $_GET['status'] ?? 'all';            // pending|paid|refunded|cancelled|all
$range  = $_GET['range']  ?? '';               // week|month|year|''
$search = trim($_GET['search'] ?? '');         // by order id / customer / user
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];

if (in_array($status, ['pending','paid','refunded','cancelled'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($range === 'week') {
    $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($range === 'month') {
    $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($range === 'year') {
    $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

if ($search !== '') {
    // Search by order id exactly, or by customer/user name (LIKE)
    $where[] = "(o.id = ? OR c.name LIKE ? OR u.name LIKE ?)";
    $params[] = (int)$search;
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count
$stmt = $pdo->prepare("SELECT COUNT(*)
                       FROM orders o
                       LEFT JOIN customers c ON o.customer_id = c.id
                       JOIN users u ON o.user_id = u.id
                       $sqlWhere");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// Rows
$stmt = $pdo->prepare("SELECT o.*, c.name AS customer_name, u.name AS user_name
                       FROM orders o
                       LEFT JOIN customers c ON o.customer_id = c.id
                       JOIN users u ON o.user_id = u.id
                       $sqlWhere
                       ORDER BY o.created_at DESC
                       LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Persist query (for pagination/export)
$persist = $_GET;
unset($persist['page']);
$queryBase = http_build_query($persist);
$exportHref = $u('export_orders_csv.php') . ($queryBase ? ('?' . $queryBase) : '');
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

  <!-- Title + controls -->
  <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <h1 class="text-2xl font-semibold">Orders</h1>

    <div class="flex flex-wrap items-center gap-2">
      <!-- Status filter -->
      <?php
        $statuses = ['all'=>'All','pending'=>'Pending','paid'=>'Paid','refunded'=>'Refunded','cancelled'=>'Cancelled'];
        foreach ($statuses as $key=>$label):
          $q = $_GET; $q['status'] = $key; unset($q['page']);
          $href = $u('orders.php') . '?' . http_build_query($q);
          $active = ($status === $key) ? 'bg-gray-900 text-white' : 'border border-gray-300 hover:bg-gray-100';
      ?>
        <a href="<?= htmlspecialchars($href) ?>" class="px-3 py-2 rounded-lg <?= $active ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
      
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800 flex items-center gap-1 text-red-500 font-semibold" 
   href="<?= htmlspecialchars($u('live_orders.php')) ?>">
  <span class="relative flex h-3 w-3">
    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
  </span>
  Live
</a>


      <!-- Date range filter -->
      <?php
        $ranges = [''=>'All time','week'=>'7d','month'=>'30d','year'=>'12m'];
        foreach ($ranges as $key=>$label):
          $q = $_GET; $q['range'] = $key; unset($q['page']);
          $href = $u('orders.php') . '?' . http_build_query($q);
          $active = ($range === $key) ? 'bg-gray-900 text-white' : 'border border-gray-300 hover:bg-gray-100';
      ?>
        <a href="<?= htmlspecialchars($href) ?>" class="px-3 py-2 rounded-lg <?= $active ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>

  
    </div>

    <!-- Search -->
    <form method="get" class="flex items-center gap-2">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
      <input type="hidden" name="range"  value="<?= htmlspecialchars($range)  ?>">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Order ID, customer or user" class="border rounded-lg p-2 w-64 max-w-full">
      <?php if ($search !== ''): ?>
        <a href="<?= htmlspecialchars($u('orders.php')) ?>" class="px-3 py-2 rounded-lg border hover:bg-gray-100">Reset</a>
      <?php endif; ?>
      <button class="px-3 py-2 rounded-lg bg-gray-900 text-white">Search</button>
    </form>
  </div>

  <!-- Add/Edit Form -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 max-w-2xl">
    <h2 class="text-lg font-semibold mb-4"><?= $editOrder ? 'Edit Order' : 'Add Order' ?></h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="update_id" value="<?= $editOrder['id'] ?? '' ?>">

      <div>
        <label class="block text-sm font-medium mb-1">Customer</label>
        <select name="customer_id" class="border p-2 rounded w-full">
          <option value="">— None —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= isset($editOrder['customer_id']) && (int)$editOrder['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Total ($)</label>
        <input type="number" step="0.01" name="total" value="<?= htmlspecialchars($editOrder['total'] ?? '') ?>" required class="border p-2 rounded w-full">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Status</label>
        <select name="status" class="border p-2 rounded w-full">
          <?php foreach (['pending','paid','refunded','cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= (isset($editOrder['status']) && $editOrder['status']===$s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Notes</label>
        <textarea name="notes" class="border p-2 rounded w-full" rows="3"><?= htmlspecialchars($editOrder['notes'] ?? '') ?></textarea>
      </div>

      <div class="md:col-span-2 flex gap-2">
        <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
          <?= $editOrder ? 'Update Order' : 'Add Order' ?>
        </button>
        <?php if ($editOrder): ?>
          <a href="<?= htmlspecialchars($u('orders.php')) ?>" class="px-4 py-2 rounded-lg border hover:bg-gray-100">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 overflow-x-auto">
    <div class="mb-3 text-sm text-gray-500">
      <?= $total ?> result<?= $total===1?'':'s' ?><?= $search ? ' for “'.htmlspecialchars($search).'”' : '' ?>
    </div>
    <table class="min-w-full">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Date</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Order #</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Customer</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Created By</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Total</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Status</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500">No orders found.</td></tr>
        <?php endif; ?>

        <?php foreach ($orders as $o): ?>
          <?php
            $badge = [
              'pending'  => 'bg-amber-100 text-amber-700',
              'paid'     => 'bg-emerald-100 text-emerald-700',
              'refunded' => 'bg-blue-100 text-blue-700',
              'cancelled'=> 'bg-rose-100 text-rose-700',
            ][$o['status']] ?? 'bg-gray-100 text-gray-700';
          ?>
          <tr class="border-t hover:bg-gray-50">
            <td class="px-3 py-2"><?= htmlspecialchars($o['created_at']) ?></td>
            <td class="px-3 py-2">#<?= (int)$o['id'] ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($o['user_name'] ?? '—') ?></td>
            <td class="px-3 py-2">$<?= number_format((float)$o['total'], 2) ?></td>
            <td class="px-3 py-2"><span class="px-2 py-1 text-xs rounded <?= $badge ?>"><?= ucfirst($o['status']) ?></span></td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($u('orders.php?edit='.(int)$o['id'])) ?>" class="px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white">Edit</a>
                <a href="<?= htmlspecialchars($u('orders.php?delete='.(int)$o['id'])) ?>" onclick="return confirm('Delete this order?')" class="px-3 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white">Delete</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="p-4 flex flex-wrap gap-2">
        <?php for ($i = 1; $i <= $totalPages; $i++):
          $q = $_GET; $q['page'] = $i;
          $href = $u('orders.php') . '?' . http_build_query($q);
        ?>
          <a href="<?= htmlspecialchars($href) ?>" class="px-3 py-1 rounded-lg border <?= $i===$page?'bg-indigo-600 text-white':'bg-white hover:bg-gray-100' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
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
