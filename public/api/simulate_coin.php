<?php
// Set proper headers first
header('Content-Type: application/json');

// Set to Philippine timezone (UTC+8)
date_default_timezone_set('Asia/Manila');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Absolute path to required files
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

try {
    // Get POST data
    $json = file_get_contents('php://input');
    if (empty($json)) {
        throw new Exception('No data received', 400);
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    // Validate required fields
    $required = ['machine_id', 'coin_type', 'amount'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Missing required field: $field", 400);
        }
        if (empty($data[$field]) && $data[$field] !== 0 && $data[$field] !== '0') {
            throw new Exception("Empty value for required field: $field", 400);
        }
    }

    // Sanitize inputs
    $machineId = (int)$data['machine_id'];
    $coinType = preg_replace('/[^a-z0-9-]/', '', strtolower($data['coin_type']));
    $amount = (float)$data['amount'];
    
    // Validate amount
    if ($amount <= 0) {
        throw new Exception("Invalid amount: must be positive", 400);
    }

    // Get current Philippine time (don't use client timestamp)
    $phTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $timestamp = $phTime->format('Y-m-d H:i:s');

    // Verify machine exists
    $stmt = $pdo->prepare("SELECT 1 FROM dispenser WHERE dispenser_id = ?");
    $stmt->execute([$machineId]);
    if (!$stmt->fetch()) {
        throw new Exception("Invalid machine ID: $machineId", 404);
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Insert transaction with Philippine time
        $stmt = $pdo->prepare("INSERT INTO transaction 
                              (dispenser_id, amount_dispensed, coin_type, DateAndTime) 
                              VALUES (?, ?, ?, ?)");
        if (!$stmt->execute([$machineId, $amount, $coinType, $timestamp])) {
            throw new Exception("Failed to insert transaction", 500);
        }

        $transactionId = $pdo->lastInsertId();

        // Update dispenser status with water level check
        $update = $pdo->prepare("UPDATE dispenserstatus 
                                SET water_level = GREATEST(0, water_level - ?), 
                                    operational_status = CASE WHEN water_level - ? <= 0 THEN 'Empty' ELSE 'Normal' END
                                WHERE dispenser_id = ?");
        if (!$update->execute([$amount, $amount, $machineId])) {
            throw new Exception("Failed to update dispenser status", 500);
        }

        // Commit transaction
        $pdo->commit();

        // Log successful transaction with Philippine time
        $logMessage = sprintf(
            "[%s] Transaction #%d | Machine %d | %s | %.2fL\n",
            $timestamp,
            $transactionId,
            $machineId,
            $coinType,
            $amount
        );
        file_put_contents($logDir . '/coin_transactions.log', $logMessage, FILE_APPEND);

        // Successful response
        echo json_encode([
            'success' => true,
            'message' => 'Transaction recorded successfully',
            'transaction_id' => $transactionId,
            'timestamp' => $timestamp,
            'local_time' => $phTime->format('M j, Y, h:i A'), // Human-readable format
            'amount_dispensed' => $amount,
            'coin_type' => $coinType
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    // Log the error with Philippine time
    error_log(sprintf(
        "[%s] API Error: %s (Code: %d)\n%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getCode(),
        json_encode($data ?? [], JSON_PRETTY_PRINT)
    ), 3, $logDir . '/error.log');

    // Return JSON error response
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'timestamp' => date('Y-m-d H:i:s'),
        'local_time' => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('M j, Y, h:i A')
    ]);
}