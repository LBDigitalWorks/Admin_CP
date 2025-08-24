<?php
session_start();
require_once __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pageTitle = "Dashboard";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

// Safe URL builder: if BASE_URL is set → absolute; else → relative (no leading slash)
$u = function(string $path = '') use ($baseUrl): string {
    $path = ltrim($path, '/');
    return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

// Date filter
$filter = $_GET['range'] ?? 'month';
$dateCondition = '';
if ($filter === 'week') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filter === 'month') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($filter === 'year') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// Summary stats
$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN type='sale' THEN amount END),0)   AS total_sales,
        COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS total_refunds,
        COALESCE(SUM(CASE WHEN type='sale' THEN amount END),0)
      - COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS net_revenue
    FROM transactions
    $dateCondition
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales'=>0,'total_refunds'=>0,'net_revenue'=>0];

// Graph data
$stmt = $pdo->query("
    SELECT DATE(created_at) as date, 
           COALESCE(SUM(CASE WHEN type='sale' THEN amount END),0)   AS sales,
           COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS refunds
    FROM transactions
    $dateCondition
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$labels = array_column($data, 'date');
$salesValues = array_map('floatval', array_column($data, 'sales'));
$refundValues = array_map('floatval', array_column($data, 'refunds'));

// Top 5 Sales Days (all-time)
$stmt = $pdo->query("
    SELECT DATE(created_at) as date, SUM(amount) AS total
    FROM transactions
    WHERE type='sale'
    GROUP BY DATE(created_at)
    ORDER BY total DESC
    LIMIT 5
");
$topDays = $stmt->fetchAll(PDO::FETCH_ASSOC);
$topLabels = array_column($topDays, 'date');
$topValues = array_map('floatval', array_column($topDays, 'total'));

// Prebuild a couple URLs
$exportHref = $u('export_csv.php') . ($filter ? '?range=' . urlencode($filter) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .card { backdrop-filter: blur(6px); }
    .chip { transition: all .15s ease; }
    .chip.active { background: #1f2937; color: #fff; }
  </style>
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

<!-- CONTENT WRAPPER -->
<main class="max-w-7xl mx-auto px-4 py-8 space-y-8">

  <!-- Filter chips + quick links -->
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-2">
      <?php
        $ranges = ['week'=>'Last 7 days','month'=>'Last 30 days','year'=>'Last 12 months'];
        foreach ($ranges as $key=>$label):
          $active = $filter === $key ? 'active' : '';
          $href   = $u('index.php') . '?range=' . urlencode($key);
      ?>
        <a href="<?= htmlspecialchars($href) ?>"
           class="chip px-4 py-2 rounded-full border border-gray-300 hover:bg-gray-100 <?= $active ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= htmlspecialchars($u('transactions.php')) ?>" class="px-3 py-2 rounded-lg border border-gray-300 hover:bg-gray-100">View all transactions</a>
      <a href="<?= htmlspecialchars($exportHref) ?>" class="px-3 py-2 rounded-lg bg-emerald-500 text-white hover:bg-emerald-600">Export CSV</a>
    </div>
  </div>

  <!-- Stats -->
  <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="card bg-white/70 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Total Sales</div>
      <div class="mt-2 text-3xl font-bold text-emerald-600">$<?= number_format((float)($stats['total_sales'] ?? 0), 2) ?></div>
      <a href="<?= htmlspecialchars($u('transactions.php?type=sale')) ?>" class="inline-block mt-3 text-sm text-emerald-700 hover:underline">View sales →</a>
    </div>
    <div class="card bg-white/70 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Total Refunds</div>
      <div class="mt-2 text-3xl font-bold text-rose-600">$<?= number_format((float)($stats['total_refunds'] ?? 0), 2) ?></div>
      <a href="<?= htmlspecialchars($u('transactions.php?type=refund')) ?>" class="inline-block mt-3 text-sm text-rose-700 hover:underline">View refunds →</a>
    </div>
    <div class="card bg-white/70 p-6 rounded-2xl shadow border border-gray-100">
      <div class="text-sm text-gray-500">Net Revenue</div>
      <div class="mt-2 text-3xl font-bold text-indigo-600">$<?= number_format((float)($stats['net_revenue'] ?? 0), 2) ?></div>
      <a href="<?= htmlspecialchars($u('reports.php#revenue')) ?>" class="inline-block mt-3 text-sm text-indigo-700 hover:underline">Revenue report →</a>
    </div>
  </section>

  <!-- Charts -->
  <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="card bg-white/70 p-6 rounded-2xl shadow border border-gray-100 lg:col-span-2">
      <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold">Sales vs Refunds</h2>
        <div class="text-sm text-gray-500"><?= htmlspecialchars($ranges[$filter] ?? '') ?></div>
      </div>
      <canvas id="salesChart" class="mt-4"></canvas>
      <div class="mt-4 flex gap-2">
        <a href="<?= htmlspecialchars($u('transactions.php')) ?>" class="px-3 py-2 rounded-lg border border-gray-300 hover:bg-gray-100">Open transactions</a>
      </div>
    </div>
    <div class="card bg-white/70 p-6 rounded-2xl shadow border border-gray-100">
      <h2 class="text-xl font-semibold">Top 5 Sales Days</h2>
      <p class="text-xs text-gray-500">Click a bar to view transactions</p>
      <canvas id="topDaysChart" class="mt-4"></canvas>
    </div>
  </section>

</main>

<!-- Transaction Modal -->
<div id="transactionModal" class="fixed inset-0 bg-black/50 hidden justify-center items-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl">
    <div class="flex justify-between items-center p-4 border-b">
      <h2 class="text-xl font-bold" id="modalTitle">Transactions</h2>
      <div class="flex items-center gap-2">
        <a id="modalFullLink" href="#" class="text-sm text-indigo-600 hover:underline">Open full page ↗</a>
        <button onclick="closeModal()" class="h-9 w-9 grid place-items-center rounded-lg hover:bg-gray-100">✖</button>
      </div>
    </div>
    <div class="p-4">
      <input type="text" id="transactionSearch" placeholder="Search transactions..." class="border p-2 w-full rounded-lg mb-4" onkeyup="searchTransactions()">
      <div class="overflow-y-auto max-h-[360px] rounded-lg border">
        <table class="w-full text-left border-collapse">
          <thead class="bg-gray-50">
            <tr>
              <th class="border-b p-2">ID</th>
              <th class="border-b p-2">Amount</th>
              <th class="border-b p-2">Type</th>
              <th class="border-b p-2">Date</th>
            </tr>
          </thead>
          <tbody id="transactionTable"></tbody>
        </table>
      </div>
      <div id="paginationControls" class="flex gap-2 mt-4 flex-wrap"></div>
    </div>
  </div>
</div>

<!-- FOOTER -->
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
  // Sales vs Refunds chart
  new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: [
        {
          label: 'Sales',
          data: <?= json_encode($salesValues) ?>,
          borderColor: '#10b981',
          backgroundColor: 'rgba(16,185,129,0.15)',
          tension: .3,
          fill: true
        },
        {
          label: 'Refunds',
          data: <?= json_encode($refundValues) ?>,
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239,68,68,0.12)',
          tension: .3,
          fill: true
        }
      ]
    },
    options: {
      plugins: { legend: { display: true } },
      scales: { y: { beginAtZero: true } }
    }
  });

  // Top 5 Sales Days chart
  const topDaysChart = new Chart(document.getElementById('topDaysChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($topLabels) ?>,
      datasets: [{
        label: 'Total Sales ($)',
        data: <?= json_encode($topValues) ?>,
        backgroundColor: 'rgba(16,185,129,0.7)'
      }]
    },
    options: {
      onClick: function(evt, elements) {
        if (elements.length > 0) {
          const date = this.data.labels[elements[0].index];
          openTransactions(date);
        }
      },
      scales: { y: { beginAtZero: true } }
    }
  });

  let modalDate = '';
  let currentPage = 1;
  let currentSearch = '';

  function openTransactions(date) {
    modalDate = date;
    currentPage = 1;
    currentSearch = '';
    document.getElementById('modalFullLink').href = "<?= htmlspecialchars($u('transactions.php?date=')) ?>" + encodeURIComponent(date);
    loadTransactions();
  }

  function loadTransactions() {
    const url = "<?= htmlspecialchars($u('get_transactions.php')) ?>" +
                `?date=${encodeURIComponent(modalDate)}&page=${currentPage}&search=${encodeURIComponent(currentSearch)}`;

    fetch(url)
      .then(res => res.json())
      .then(data => {
        const tableBody = document.getElementById('transactionTable');
        tableBody.innerHTML = '';

        (data.rows || []).forEach(row => {
          const typeClass = row.type === 'sale' ? 'text-emerald-600 font-semibold' : 'text-rose-600 font-semibold';
          tableBody.innerHTML += `
            <tr class="hover:bg-gray-50">
              <td class="border-b p-2">${row.id}</td>
              <td class="border-b p-2">$${parseFloat(row.amount).toFixed(2)}</td>
              <td class="border-b p-2 ${typeClass}">${row.type}</td>
              <td class="border-b p-2">${row.created_at}</td>
            </tr>
          `;
        });

        // Pagination
        const paginationDiv = document.getElementById('paginationControls');
        paginationDiv.innerHTML = '';
        const totalPages = data.totalPages || 1;
        const current = data.currentPage || 1;
        for (let i = 1; i <= totalPages; i++) {
          paginationDiv.innerHTML += `
            <button class="px-3 py-1 rounded-lg border ${i === current ? 'bg-indigo-600 text-white' : 'bg-white hover:bg-gray-100'}" onclick="changePage(${i})">${i}</button>
          `;
        }

        document.getElementById('modalTitle').textContent = 'Transactions for ' + modalDate;
        document.getElementById('transactionModal').classList.remove('hidden');
        document.getElementById('transactionModal').classList.add('flex');
      });
  }

  function changePage(page) {
    currentPage = page;
    loadTransactions();
  }

  function searchTransactions() {
    currentSearch = document.getElementById('transactionSearch').value;
    currentPage = 1;
    loadTransactions();
  }

  function closeModal() {
    document.getElementById('transactionModal').classList.add('hidden');
    document.getElementById('transactionModal').classList.remove('flex');
  }
</script>
</body>
</html>
