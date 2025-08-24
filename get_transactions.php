<?php
session_start();
require_once __DIR__ . '/config.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Validate date
$date = $_GET['date'] ?? '';
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid date"]);
    exit;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Search filter
$search = trim($_GET['search'] ?? '');
$searchCondition = '';
$params = [$date];

if ($search !== '') {
    $searchCondition = " AND (type LIKE ? OR amount LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count total for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM transactions
    WHERE DATE(created_at) = ?
    $searchCondition
");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch paginated results
$stmt = $pdo->prepare("
    SELECT id, amount, type, created_at
    FROM transactions
    WHERE DATE(created_at) = ?
    $searchCondition
    ORDER BY created_at ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output JSON
header('Content-Type: application/json');
echo json_encode([
    "rows" => $rows,
    "totalPages" => $totalPages,
    "currentPage" => $page
]);
