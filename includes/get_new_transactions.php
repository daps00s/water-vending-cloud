<?php
require_once 'db_connect.php';

// Get parameters from request
$startDate = $_GET['start'] ?? '';
$endDate = $_GET['end'] ?? '';
$machineId = $_GET['machine'] ?? '';
$lastId = $_GET['last_id'] ?? 0;

// Build the base query for new transactions
$query = "SELECT t.*, d.Description as machine_name, l.location_name
         FROM transaction t
         JOIN dispenser d ON t.dispenser_id = d.dispenser_id
         JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
         JOIN location l ON dl.location_id = l.location_id
         WHERE t.transaction_id > :lastId";

$params = ['lastId' => $lastId];

// Add conditions based on filters
if (!empty($startDate)) {
    $query .= " AND DATE(t.DateAndTime) >= :startDate";
    $params['startDate'] = $startDate;
}

if (!empty($endDate)) {
    $query .= " AND DATE(t.DateAndTime) <= :endDate";
    $params['endDate'] = $endDate;
}

if (!empty($machineId)) {
    $query .= " AND t.dispenser_id = :machineId";
    $params['machineId'] = $machineId;
}

$query .= " ORDER BY t.DateAndTime DESC";

// Prepare and execute the query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$newTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total new coins
$totalNewCoins = 0;
foreach ($newTransactions as $transaction) {
    preg_match('/(\d+)/', $transaction['coin_type'], $matches);
    if (isset($matches[1])) {
        $totalNewCoins += (int)$matches[1];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'newTransactions' => $newTransactions,
    'totalNewCoins' => $totalNewCoins
]);
?>