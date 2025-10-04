<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db_connect.php';

$response = ['success' => false, 'message' => ''];

try {
    // Validate input
    $dispenser_id = $_POST['dispenser_id'] ?? null;
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;

    if (!$dispenser_id || !$amount || $amount <= 0) {
        throw new Exception('Invalid input: Dispenser ID and a positive amount are required.');
    }

    // Get machine capacity and current water level
    $stmt = $pdo->prepare("
        SELECT d.Capacity, COALESCE(ds.water_level, 0) as water_level
        FROM dispenser d
        LEFT JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
        WHERE d.dispenser_id = ?
    ");
    $stmt->execute([$dispenser_id]);
    $machine = $stmt->fetch();

    if (!$machine) {
        throw new Exception('Machine not found.');
    }

    $new_water_level = $machine['water_level'] + $amount;

    // Check if new water level exceeds capacity
    if ($new_water_level > $machine['Capacity']) {
        throw new Exception('Refill amount exceeds machine capacity of ' . $machine['Capacity'] . 'L.');
    }

    // Check if dispenserstatus record exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispenserstatus WHERE dispenser_id = ?");
    $stmt->execute([$dispenser_id]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        // Update existing record
        $stmt = $pdo->prepare("
            UPDATE dispenserstatus 
            SET water_level = ?, operational_status = ?
            WHERE dispenser_id = ?
        ");
        $stmt->execute([$new_water_level, 'Normal', $dispenser_id]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("
            INSERT INTO dispenserstatus (dispenser_id, water_level, operational_status)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$dispenser_id, $new_water_level, 'Normal']);
    }

    $response['success'] = true;
    $response['message'] = 'success|Water level successfully updated!';

} catch (Exception $e) {
    $response['message'] = 'error|' . $e->getMessage();
}

echo json_encode($response);
?>