<?php
// PostgreSQL configuration for Render
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'water_vending';
$username = getenv('DB_USER') ?: 'wvm_user';
$password = getenv('DB_PASSWORD') ?: '';

try {
    // PostgreSQL connection
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    
    if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection unavailable',
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    } else {
        die('Database connection unavailable. Please try again later.');
    }
}
?>
