<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db_connect.php';

// Get date range filter
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

// Get filtered or all coin collections
$machineFilter = $_GET['machine_id'] ?? null;
$whereClauses = [];
$params = [];

if ($machineFilter) {
    $whereClauses[] = "t.dispenser_id = :machine_id";
    $params[':machine_id'] = $machineFilter;
}

if ($startDate && $endDate) {
    $whereClauses[] = "t.DateAndTime BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate . ' 00:00:00';
    $params[':end_date'] = $endDate . ' 23:59:59';
}

$whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";
$query = "
    SELECT 
        t.transaction_id as collection_id,
        t.DateAndTime as collection_date,
        t.coin_type,
        d.Description as machine_name,
        l.location_name
    FROM transaction t
    JOIN dispenser d ON t.dispenser_id = d.dispenser_id
    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    $whereClause
    ORDER BY t.DateAndTime DESC
    LIMIT 100
";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$collections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get last collection date for specific machine
$lastCollection = null;
if ($machineFilter) {
    $lastCollectionQuery = "
        SELECT MAX(t.DateAndTime) as last_collection
        FROM transaction t
        WHERE t.dispenser_id = :machine_id
    ";
    $lastStmt = $pdo->prepare($lastCollectionQuery);
    $lastStmt->bindValue(':machine_id', $machineFilter, PDO::PARAM_INT);
    $lastStmt->execute();
    $lastCollection = $lastStmt->fetchColumn();
}

// Calculate totals
$totalCoins = 0;
$totalCollections = count($collections);
$coinTypes = [];

foreach ($collections as $collection) {
    preg_match('/(\d+)/', $collection['coin_type'], $matches);
    $coinValue = isset($matches[1]) ? (int)$matches[1] : 0;
    $totalCoins += $coinValue;
    if (!isset($coinTypes[$collection['coin_type']])) {
        $coinTypes[$collection['coin_type']] = 0;
    }
    $coinTypes[$collection['coin_type']] += $coinValue;
}

// Get current machine info if filtered
$currentMachine = null;
if ($machineFilter) {
    $machineQuery = "
        SELECT d.dispenser_id, d.Description, l.location_name 
        FROM dispenser d
        LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
        LEFT JOIN location l ON dl.location_id = l.location_id
        WHERE d.dispenser_id = :machine_id
    ";
    $machineStmt = $pdo->prepare($machineQuery);
    $machineStmt->bindValue(':machine_id', $machineFilter, PDO::PARAM_INT);
    $machineStmt->execute();
    $currentMachine = $machineStmt->fetch(PDO::FETCH_ASSOC);
}

echo json_encode([
    'totalCoins' => $totalCoins,
    'totalCollections' => $totalCollections,
    'coinTypes' => $coinTypes,
    'lastCollection' => $lastCollection,
    'currentMachine' => $currentMachine
]);
?>