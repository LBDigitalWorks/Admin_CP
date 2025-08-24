<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$filter = $_GET['range'] ?? 'month';
$dateCondition = '';
if ($filter === 'week') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filter === 'month') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($filter === 'year') {
    $dateCondition = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
} else {
    $dateCondition = '';
}

// Fetch transactions
$stmt = $pdo->query("SELECT * FROM transactions $dateCondition ORDER BY created_at DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="transactions.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, array_keys($rows[0] ?? []));

foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
