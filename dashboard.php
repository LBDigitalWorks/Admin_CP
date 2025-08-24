<?php
session_start();
require_once __DIR__ . '/config.php';
$pageTitle = "Dashboard";

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

// Date filter
$filter = $_GET['range'] ?? 'month';
$dateCondition = '';
if ($filter === 'week') {
    $dateCondition = "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filter === 'month') {
    $dateCondition = "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($filter === 'year') {
    $dateCondition = "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// Summary stats
$stmt = $pdo->query("SELECT 
    SUM(CASE WHEN type='sale' THEN amount ELSE 0 END) AS total_sales,
    SUM(CASE WHEN type='refund' THEN amount ELSE 0 END) AS total_refunds,
    SUM(CASE WHEN type='sale' THEN amount ELSE 0 END) - 
    SUM(CASE WHEN type='refund' THEN amount ELSE 0 END) AS net_revenue
    FROM transactions WHERE 1=1 $dateCondition");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Graph data
$stmt = $pdo->query("SELECT DATE(created_at) as date, 
    SUM(CASE WHEN type='sale' THEN amount ELSE 0 END) AS daily_sales
    FROM transactions
    WHERE 1=1 $dateCondition
    GROUP BY DATE(created_at)
    ORDER BY date ASC");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = array_column($data, 'date');
$values = array_map('floatval', array_column($data, 'daily_sales'));

include 'includes/header.php';
?>

<h1 class="text-3xl font-bold mb-6">Dashboard</h1>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white p-6 rounded shadow text-center">
        <h2 class="text-lg font-semibold">Total Sales</h2>
        <p class="text-2xl font-bold text-green-600">$<?= number_format($stats['total_sales'] ?? 0, 2) ?></p>
    </div>
    <div class="bg-white p-6 rounded shadow text-center">
        <h2 class="text-lg font-semibold">Total Refunds</h2>
        <p class="text-2xl font-bold text-red-600">$<?= number_format($stats['total_refunds'] ?? 0, 2) ?></p>
    </div>
    <div class="bg-white p-6 rounded shadow text-center">
        <h2 class="text-lg font-semibold">Net Revenue</h2>
        <p class="text-2xl font-bold text-blue-600">$<?= number_format($stats['net_revenue'] ?? 0, 2) ?></p>
    </div>
</div>

<!-- Date Range Filter -->
<div class="mb-4">
    <form method="get" class="inline-block">
        <select name="range" onchange="this.form.submit()" class="border p-2 rounded">
            <option value="week" <?= $filter === 'week' ? 'selected' : '' ?>>This Week</option>
            <option value="month" <?= $filter === 'month' ? 'selected' : '' ?>>This Month</option>
            <option value="year" <?= $filter === 'year' ? 'selected' : '' ?>>This Year</option>
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Time</option>
        </select>
    </form>
</div>

<!-- Chart -->
<div class="bg-white p-6 rounded shadow">
    <canvas id="salesChart"></canvas>
</div>

<script src="assets/js/chart.min.js"></script>
<script>
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: 'Daily Sales ($)',
            data: <?= json_encode($values) ?>,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.4,
            fill: true
        }]
    }
});
</script>

<?php include 'includes/footer.php'; ?>
