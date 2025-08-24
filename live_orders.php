<?php
// Start session safely (won't error if already started)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/dispatch.php'; // (and whatsapp.php if dispatch.php doesn't include it)

// Guard: only proceed if logged in
if (empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

/* -------------------------------------------
   URL helper + page title
-------------------------------------------- */
$pageTitle = "Live Orders";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

/* -------------------------------------------
   Guard: ensure required tables exist
-------------------------------------------- */
function tableExists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

if (!tableExists($pdo, 'drivers') || !tableExists($pdo, 'driver_areas')) {
    // Redirect to your installer and STOP so no queries run
    header("Location: " . $u('install_live_orders.php?from=live_orders'));
    exit;
}

/* -------------------------------------------
   Actions (run only after we know tables exist)
-------------------------------------------- */
// Add driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_driver') {
    $name  = trim($_POST['driver_name'] ?? '');
    $phone = trim($_POST['driver_phone'] ?? '');
    if ($name !== '' && $phone !== '') {
        $stmt = $pdo->prepare("INSERT INTO drivers (name, phone_e164, active) VALUES (?,?,1)");
        $stmt->execute([$name, $phone]);
    }
    header("Location: " . $u('live_orders.php'));
    exit;
}

// Add areas to a driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_areas') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $areasCSV = strtoupper(trim($_POST['areas_csv'] ?? ''));
    if ($driverId && $areasCSV !== '') {
        $areas = array_filter(array_map('trim', explode(',', $areasCSV)));
        foreach ($areas as $a) {
            if ($a === '') continue;
            $ins = $pdo->prepare("INSERT IGNORE INTO driver_areas (driver_id, area_prefix) VALUES (?,?)");
            $ins->execute([$driverId, $a]);
        }
    }
    header("Location: " . $u('live_orders.php'));
    exit;
}

// Toggle driver active/inactive
if (isset($_GET['toggle_driver'])) {
    $id = (int)$_GET['toggle_driver'];
    $pdo->prepare("UPDATE drivers SET active = 1 - active WHERE id = ?")->execute([$id]);
    header("Location: " . $u('live_orders.php'));
    exit;
}

// Manual assign & send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_send') {
    $orderId  = (int)($_POST['order_id'] ?? 0);
    $driverId = (int)($_POST['driver_id'] ?? 0);
    if ($orderId && $driverId) {
        send_order_to_driver($pdo, $orderId, $driverId); // from lib/dispatch.php
    }
    header("Location: " . $u('live_orders.php'));
    exit;
}

// Auto-assign by outward postcode & send
if (isset($_GET['auto_assign'])) {
    $orderId = (int)$_GET['auto_assign'];
    $o = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $o->execute([$orderId]);
    $ord = $o->fetch(PDO::FETCH_ASSOC);
    if ($ord && !empty($ord['address_postcode'])) {
        $driver = find_driver_for_postcode($pdo, $ord['address_postcode']); // from lib/dispatch.php
        if ($driver) {
            send_order_to_driver($pdo, $orderId, (int)$driver['id']);
        }
    }
    header("Location: " . $u('live_orders.php'));
    exit;
}

/* -------------------------------------------
   Load data for page (safe now)
-------------------------------------------- */
$drivers = $pdo->query("SELECT * FROM drivers ORDER BY active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$ordersStmt = $pdo->query("
    SELECT o.*, d.name AS driver_name
    FROM orders o
    LEFT JOIN drivers d ON d.id = o.assigned_driver_id
    WHERE o.delivery_status IN ('new','assigned')
    ORDER BY o.created_at DESC
");
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Prefetch driver areas
$areasByDriver = [];
if ($drivers) {
    $ids = array_column($drivers, 'id');
    if (!empty($ids)) {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT * FROM driver_areas WHERE driver_id IN ($in) ORDER BY area_prefix ASC");
        $st->execute($ids);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $areasByDriver[$r['driver_id']][] = $r['area_prefix'];
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
  <!-- Simple, PHP-only auto-refresh -->
  <meta http-equiv="refresh" content="15">
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
  <h1 class="text-2xl font-semibold flex items-center gap-2">
    <span class="relative flex h-3 w-3">
      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
      <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
    </span>
    Live Orders
  </h1>

  <!-- Driver Management -->
  <div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <h2 class="text-lg font-semibold mb-4">Add Driver</h2>
      <form method="post" class="grid grid-cols-1 gap-3">
        <input type="hidden" name="action" value="add_driver">
        <label class="text-sm">Name
          <input name="driver_name" class="border p-2 rounded w-full" required>
        </label>
        <label class="text-sm">Phone (E.164, e.g. +447900123456)
          <input name="driver_phone" class="border p-2 rounded w-full" required>
        </label>
        <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg">Add Driver</button>
      </form>
    </div>

    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <h2 class="text-lg font-semibold mb-4">Assign Areas to Driver</h2>
      <form method="post" class="grid grid-cols-1 gap-3">
        <input type="hidden" name="action" value="add_areas">
        <label class="text-sm">Driver
          <select name="driver_id" class="border p-2 rounded w-full" required>
            <option value="">— Select —</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?><?= $d['active'] ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="text-sm">Areas (comma-separated, e.g. S1,S2,S35)
          <input name="areas_csv" class="border p-2 rounded w-full" placeholder="S1,S2,S3">
        </label>
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">Add Areas</button>
      </form>
    </div>
  </div>

  <!-- Driver List -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 overflow-x-auto">
    <h2 class="text-lg font-semibold mb-4">Drivers</h2>
    <table class="min-w-full">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Driver</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Phone</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Areas</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Status</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$drivers): ?>
          <tr><td colspan="5" class="px-3 py-6 text-gray-500 text-center">No drivers yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($drivers as $d): ?>
          <tr class="border-t">
            <td class="px-3 py-2"><?= htmlspecialchars($d['name']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($d['phone_e164']) ?></td>
            <td class="px-3 py-2 text-sm text-gray-700">
              <?php
                $list = $areasByDriver[$d['id']] ?? [];
                echo $list ? implode(', ', $list) : '—';
              ?>
            </td>
            <td class="px-3 py-2">
              <?php if ($d['active']): ?>
                <span class="px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-700">Active</span>
              <?php else: ?>
                <span class="px-2 py-1 text-xs rounded bg-gray-200 text-gray-700">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2">
              <a href="<?= htmlspecialchars($u('live_orders.php?toggle_driver='.(int)$d['id'])) ?>"
                 class="px-3 py-1 rounded-lg border hover:bg-gray-100">
                 <?= $d['active'] ? 'Deactivate' : 'Activate' ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Live Orders -->
  <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 overflow-x-auto">
    <div class="mb-3 text-sm text-gray-500">Auto-refreshing every 15s.</div>
    <table class="min-w-full">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Date</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Order #</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Address</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Postcode</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Total</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Status</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Assigned</th>
          <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr><td colspan="8" class="px-3 py-8 text-center text-gray-500">No live orders right now.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $o): ?>
          <tr class="border-t">
            <td class="px-3 py-2"><?= htmlspecialchars($o['created_at']) ?></td>
            <td class="px-3 py-2">#<?= (int)$o['id'] ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($o['address_line1'] ?? '—') ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($o['address_postcode'] ?? '—') ?></td>
            <td class="px-3 py-2">£<?= number_format((float)$o['total'], 2) ?></td>
            <td class="px-3 py-2">
              <span class="px-2 py-1 text-xs rounded
                <?= ($o['delivery_status'] ?? 'new') === 'assigned' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' ?>">
                <?= htmlspecialchars(ucfirst($o['delivery_status'] ?? 'new')) ?>
              </span>
            </td>
            <td class="px-3 py-2"><?= htmlspecialchars($o['driver_name'] ?? '—') ?></td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($u('live_orders.php?auto_assign='.(int)$o['id'])) ?>"
                   class="px-3 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white">Auto-Assign & Send</a>

                <form method="post" class="flex items-center gap-2">
                  <input type="hidden" name="action" value="assign_send">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <select name="driver_id" class="border p-1 rounded">
                    <?php foreach ($drivers as $d): if (!$d['active']) continue; ?>
                      <option value="<?= (int)$d['id'] ?>">
                        <?= htmlspecialchars($d['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">
                    Assign & Send
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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

</html>
