<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$pageTitle = "Reports";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

// --- Helpers ---
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// --- Filters ---
$range = $_GET['range'] ?? ''; // '', 'week', 'month', 'year'
$start = $_GET['start'] ?? '';
$end   = $_GET['end']   ?? '';

$where = []; $params = [];
if ($start && $end) {
    $where[] = "DATE(created_at) BETWEEN ? AND ?";
    $params[] = $start; $params[] = $end;
} else {
    if ($range === 'week')  $where[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    if ($range === 'month') $where[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
    if ($range === 'year')  $where[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}
$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// --- KPIs from transactions ---
$stmt = $pdo->prepare("
  SELECT 
    COALESCE(SUM(CASE WHEN type='sale'   THEN amount END),0) AS total_sales,
    COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS total_refunds,
    COALESCE(SUM(CASE WHEN type='sale'   THEN amount END),0)
  - COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS net_revenue
  FROM transactions $sqlWhere
");
$stmt->execute($params);
$kpis = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales'=>0,'total_refunds'=>0,'net_revenue'=>0];

// --- Daily Net Revenue series ---
$stmt = $pdo->prepare("
  SELECT DATE(created_at) AS d,
         COALESCE(SUM(CASE WHEN type='sale'   THEN amount END),0) -
         COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS net
  FROM transactions $sqlWhere
  GROUP BY DATE(created_at) ORDER BY d ASC
");
$stmt->execute($params);
$dailyRows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$dailyLabs  = array_column($dailyRows, 'd');
$dailyNet   = array_map('floatval', array_column($dailyRows, 'net'));

// --- Sales vs Refunds series ---
$stmt = $pdo->prepare("
  SELECT DATE(created_at) AS d,
         COALESCE(SUM(CASE WHEN type='sale'   THEN amount END),0) AS sales,
         COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS refunds
  FROM transactions $sqlWhere
  GROUP BY DATE(created_at) ORDER BY d ASC
");
$stmt->execute($params);
$srRows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
$srLabs   = array_column($srRows, 'd');
$srSales  = array_map('floatval', array_column($srRows, 'sales'));
$srRefund = array_map('floatval', array_column($srRows, 'refunds'));

// --- Optional: AOV + order status if 'orders' exists ---
$hasOrders = table_exists($pdo, 'orders');
$AOV = 0.0;
$statusCounts = ['pending'=>0,'paid'=>0,'refunded'=>0,'cancelled'=>0];

if ($hasOrders) {
    $oWhere = []; $oParams = [];
    if ($start && $end) { $oWhere[]="DATE(created_at) BETWEEN ? AND ?"; $oParams[]=$start; $oParams[]=$end; }
    else {
        if ($range==='week')  $oWhere[]="created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        if ($range==='month') $oWhere[]="created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        if ($range==='year')  $oWhere[]="created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    }
    $oSql        = $oWhere ? ('WHERE '.implode(' AND ', $oWhere)) : '';
    $oSqlPaid    = $oWhere ? ($oSql." AND status='paid'") : "WHERE status='paid'";
    // AOV = total paid / count paid (fallback to all)
    $stmt = $pdo->prepare("SELECT SUM(total) AS s, COUNT(*) AS c FROM orders $oSqlPaid"); $stmt->execute($oParams);
    $paid = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['s'=>0,'c'=>0];
    if (!empty($paid['c'])) $AOV = (float)$paid['s'] / (int)$paid['c'];
    else { $stmt = $pdo->prepare("SELECT SUM(total) AS s, COUNT(*) AS c FROM orders $oSql"); $stmt->execute($oParams);
           $all = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['s'=>0,'c'=>0];
           $AOV = !empty($all['c']) ? (float)$all['s'] / (int)$all['c'] : 0.0; }

    // Status breakdown
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM orders $oSql GROUP BY status"); $stmt->execute($oParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($statusCounts[$r['status']])) $statusCounts[$r['status']] = (int)$r['c'];
} else {
    // Approx AOV from transactions if no orders
    $sqlSale = $sqlWhere ? ($sqlWhere." AND type='sale'") : "WHERE type='sale'";
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s, COUNT(*) c FROM transactions $sqlSale");
    $stmt->execute($params);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['s'=>0,'c'=>0];
    $AOV = !empty($agg['c']) ? (float)$agg['s'] / (int)$agg['c'] : 0.0;
}

// Export link
$persist = $_GET;
$queryBase = http_build_query($persist);
$exportDailyHref = $u('export_reports_csv.php') . '?type=daily_net' . ($queryBase ? '&'.$queryBase : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
  <!-- Filters -->
  <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
    <div class="flex flex-wrap items-center gap-2">
      <?php
        $ranges = [''=>'All time','week'=>'Last 7 days','month'=>'Last 30 days','year'=>'Last 12 months'];
        foreach ($ranges as $key=>$label):
          $q = $_GET; $q['range']=$key; unset($q['start'],$q['end']);
          $href = $u('reports.php').'?'.http_build_query($q);
          $active = ($range===$key && empty($start) && empty($end)) ? 'bg-gray-900 text-white' : 'border border-gray-300 hover:bg-gray-100';
      ?>
        <a href="<?= htmlspecialchars($href) ?>" class="px-3 py-2 rounded-lg <?= $active ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="flex flex-wrap items-center gap-2">
      <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="border rounded-lg p-2">
      <input type="date" name="end"   value="<?= htmlspecialchars($end)   ?>" class="border rounded-lg p-2">
      <button class="px-3 py-2 rounded-lg bg-gray-900 text-white">Apply</button>
      <?php if ($start || $end): ?>
        <a href="<?= htmlspecialchars($u('reports.php')) ?>" class="px-3 py-2 rounded-lg border hover:bg-gray-100">Reset</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($exportDailyHref) ?>" class="px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Export Daily Net CSV</a>
    </form>
  </div>

  <!-- KPI cards -->
  <section class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Total Sales</div>
      <div class="mt-2 text-3xl font-bold text-emerald-600">$<?= number_format((float)$kpis['total_sales'], 2) ?></div>
    </div>
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Total Refunds</div>
      <div class="mt-2 text-3xl font-bold text-rose-600">$<?= number_format((float)$kpis['total_refunds'], 2) ?></div>
    </div>
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Net Revenue</div>
      <div class="mt-2 text-3xl font-bold text-indigo-600">$<?= number_format((float)$kpis['net_revenue'], 2) ?></div>
    </div>
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Avg. Order Value<?= $hasOrders ? '' : ' *' ?></div>
      <div class="mt-2 text-3xl font-bold text-gray-800">$<?= number_format($AOV, 2) ?></div>
      <?php if (!$hasOrders): ?><div class="text-xs text-gray-500 mt-1">* Approximated from sales transactions.</div><?php endif; ?>
    </div>
  </section>

  <!-- Charts -->
  <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100 lg:col-span-2">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">Daily Net Revenue</h2>
      </div>
      <canvas id="dailyNetChart" class="mt-4"></canvas>
    </div>

    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">Sales vs Refunds</h2>
      </div>
      <canvas id="salesRefundsChart" class="mt-4"></canvas>
    </div>
  </section>

  <?php if ($hasOrders): ?>
  <!-- Orders status -->
  <section class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
    <h2 class="text-lg font-semibold mb-4">Order Status Breakdown</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <?php
        $pill = function($label,$count,$classes){
          return "<div class='p-4 rounded-xl border text-center $classes'><div class='text-sm text-gray-500'>$label</div><div class='text-2xl font-semibold mt-1'>$count</div></div>";
        };
        echo $pill('Pending',  $statusCounts['pending'],  'bg-amber-50 border-amber-100 text-amber-700');
        echo $pill('Paid',     $statusCounts['paid'],     'bg-emerald-50 border-emerald-100 text-emerald-700');
        echo $pill('Refunded', $statusCounts['refunded'], 'bg-blue-50 border-blue-100 text-blue-700');
        echo $pill('Cancelled',$statusCounts['cancelled'],'bg-rose-50 border-rose-100 text-rose-700');
      ?>
    </div>
  </section>
  <?php endif; ?>
</main>

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

<script>
  new Chart(document.getElementById('dailyNetChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($dailyLabs) ?>,
      datasets: [{
        label: 'Net Revenue',
        data: <?= json_encode($dailyNet) ?>,
        borderColor: '#4f46e5',
        backgroundColor: 'rgba(79,70,229,0.12)',
        tension: .3,
        fill: true
      }]
    },
    options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: true } } }
  });

  new Chart(document.getElementById('salesRefundsChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($srLabs) ?>,
      datasets: [
        { label: 'Sales',   data: <?= json_encode($srSales) ?>,  borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.15)', tension: .3, fill: true },
        { label: 'Refunds', data: <?= json_encode($srRefund) ?>, borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.12)',   tension: .3, fill: true }
      ]
    },
    options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: true } } }
  });
</script>
</body>
</html>
