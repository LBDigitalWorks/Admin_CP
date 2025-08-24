<?php
// Optional: define BASE_URL in config.php, e.g. define('BASE_URL', '/admin/');
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'LB Admin Panel') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 text-gray-900 min-h-screen">

<!-- NAVBAR -->
<nav class="bg-gray-900 text-white">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <!-- Brand -->
        <a href="<?= $baseUrl ?>/index.php" class="flex items-center gap-3">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 grid place-items-center shadow-lg font-bold">LB</div>
            <span class="font-semibold tracking-wide">Admin Panel</span>
        </a>

        <!-- Links -->
        <?php if (isset($_SESSION['user'])): ?>
            <div class="flex items-center gap-4">
                <a href="<?= $baseUrl ?>/index.php" class="px-3 py-2 rounded-lg hover:bg-gray-800">Dashboard</a>
                <a href="<?= $baseUrl ?>/transactions.php" class="px-3 py-2 rounded-lg hover:bg-gray-800">Transactions</a>
                <a href="<?= $baseUrl ?>/customers.php" class="px-3 py-2 rounded-lg hover:bg-gray-800">Customers</a>
                <a href="<?= $baseUrl ?>/reports.php" class="px-3 py-2 rounded-lg hover:bg-gray-800">Reports</a>
                <a href="<?= $baseUrl ?>/settings.php" class="px-3 py-2 rounded-lg hover:bg-gray-800">Settings</a>
                <span class="hidden sm:inline text-sm text-gray-300">Hi, <?= htmlspecialchars($_SESSION['user']['name']) ?></span>
                <a href="<?= $baseUrl ?>/index.php?logout=1" class="px-3 py-2 rounded-lg bg-red-500 hover:bg-red-600">Logout</a>
            </div>
        <?php endif; ?>
    </div>
</nav>

<!-- MAIN WRAPPER -->
<div class="max-w-7xl mx-auto p-6">
