<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header("Location: login.php"); exit; }

$status = $_GET['status'] ?? 'all';
$range  = $_GET['range']  ?? '';
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if (in_array($status, ['pending','paid','refunded','cancelled'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($range === 'week')  { $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"; }
if ($range === 'month') { $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; }
if ($range === 'year')  { $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)"; }

if ($search !== '') {
    $where[] = "(o.id = ? OR c.name LIKE ? OR u.name LIKE ?)";
    $params[] = (int)$search;
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT o.id, o.created_at, c.name AS customer, u.name AS user, o.total, o.status, o.notes
                       FROM orders o
                       LEFT JOIN customers c ON o.customer_id = c.id
                       JOIN users u ON o.user_id = u.id
                       $sqlWhere
                       ORDER BY o.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="orders.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Date','Customer','Created By','Total','Status','Notes']);
foreach ($rows as $r) {
  fputcsv($out, [$r['id'], $r['created_at'], $r['customer'], $r['user'], $r['total'], $r['status'], $r['notes']]);
}
fclose($out);
exit;
