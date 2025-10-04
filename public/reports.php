<?php
ob_start(); // Start output buffering
$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';

// Check if download request is made
if (isset($_GET['download'])) {
    $format = $_GET['format'] ?? 'csv';
    $reportType = $_GET['report'] ?? 'transactions';
    $machineFilter = $_GET['machine'] ?? 'all';
    $timeFilter = $_GET['time'] ?? 'month';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    
    // Generate and download the report
    if ($format === 'csv') {
        generateReport($reportType, $format, $machineFilter, $timeFilter, $startDate, $endDate);
    }
    exit;
}

// Get all machines for filter dropdown
$machines = $pdo->query("SELECT dispenser_id, Description FROM dispenser ORDER BY Description")->fetchAll();

// Get filter values from GET or set defaults
$currentMachineFilter = $_GET['machine'] ?? 'all';
$currentTimeFilter = $_GET['time'] ?? 'month';
$currentReportType = $_GET['report'] ?? 'transactions';
$currentVisualType = $_GET['visual'] ?? 'table';
$currentStartDate = $_GET['start_date'] ?? '';
$currentEndDate = $_GET['end_date'] ?? '';

// Force visual type to 'table' for transactions report
if ($currentReportType === 'transactions') {
    $currentVisualType = 'table';
}

// Get machine name for display and file name
$machineName = 'All Machines';
if ($currentMachineFilter !== 'all') {
    $stmt = $pdo->prepare("SELECT Description FROM dispenser WHERE dispenser_id = ?");
    $stmt->execute([intval($currentMachineFilter)]);
    $machineName = $stmt->fetchColumn();
}
$machineNameForFile = preg_replace('/[^A-Za-z0-9]/', '', $machineName);

// Calculate actual date range for display
$currentDate = new DateTime();
$dateRangeDisplay = '';
$timeFilterName = '';
if ($currentTimeFilter === 'custom' && $currentStartDate && $currentEndDate) {
    $startDate = new DateTime($currentStartDate);
    $endDate = new DateTime($currentEndDate);
    $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
    $timeFilterName = 'Custom';
} else {
    switch ($currentTimeFilter) {
        case 'day':
            $dateRangeDisplay = $currentDate->format('M j, Y');
            $timeFilterName = 'Today';
            break;
        case 'week':
            $startDate = (clone $currentDate)->modify('-6 days');
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
            $timeFilterName = 'Last7Days';
            break;
        case 'month':
            $startDate = (clone $currentDate)->modify('-29 days');
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
            $timeFilterName = 'Last30Days';
            break;
        case 'year':
            $startDate = (clone $currentDate)->modify('-364 days');
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
            $timeFilterName = 'LastYear';
            break;
        default:
            $dateRangeDisplay = 'All Time';
            $timeFilterName = 'AllTime';
    }
}

// Build WHERE clauses based on filters
$machineWhere = $currentMachineFilter !== 'all' ? " AND t.dispenser_id = " . intval($currentMachineFilter) : '';
$machineWhereDispenser = $currentMachineFilter !== 'all' ? " AND dispenser.dispenser_id = " . intval($currentMachineFilter) : '';
$timeWhere = buildTimeWhereClause($currentTimeFilter, $currentStartDate, $currentEndDate);

// Get data for the reports
$transactions = $pdo->query("
    SELECT 
        t.transaction_id, 
        t.amount_dispensed, 
        t.DateAndTime, 
        d.Description as machine_name,
        l.location_name,
        CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED) as coin_value
    FROM transaction t
    JOIN dispenser d ON t.dispenser_id = d.dispenser_id
    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    WHERE 1=1 $machineWhere $timeWhere
    ORDER BY t.DateAndTime DESC
    LIMIT 100
")->fetchAll();

// Get machine status data
$machine_status = $pdo->query("
    SELECT 
        dispenser.dispenser_id,
        dispenser.Description as machine_name,
        l.location_name,
        ds.water_level,
        dispenser.Capacity,
        dl.Status as is_active
    FROM dispenser
    JOIN dispenserstatus ds ON dispenser.dispenser_id = ds.dispenser_id
    LEFT JOIN dispenserlocation dl ON dispenser.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    WHERE 1=1 $machineWhereDispenser
")->fetchAll();

// Get sales summary data
$sales_summary = $pdo->query("
    SELECT 
        DATE(t.DateAndTime) as transaction_date,
        SUM(CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED)) as total_sales,
        COUNT(*) as transaction_count,
        SUM(t.amount_dispensed) as total_water_dispensed
    FROM transaction t
    JOIN dispenser d ON t.dispenser_id = d.dispenser_id
    WHERE 1=1 $machineWhere $timeWhere
    GROUP BY DATE(t.DateAndTime)
    ORDER BY transaction_date DESC
")->fetchAll();

// Get water consumption data
$water_consumption = $pdo->query("
    SELECT 
        dispenser.dispenser_id,
        dispenser.Description as machine_name,
        l.location_name,
        SUM(t.amount_dispensed) as total_water_dispensed,
        COUNT(t.transaction_id) as transaction_count,
        SUM(CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED)) as total_income
    FROM dispenser
    LEFT JOIN transaction t ON dispenser.dispenser_id = t.dispenser_id $timeWhere
    LEFT JOIN dispenserlocation dl ON dispenser.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    WHERE 1=1 $machineWhereDispenser
    GROUP BY dispenser.dispenser_id, dispenser.Description, l.location_name
    ORDER BY total_water_dispensed DESC
")->fetchAll();

// Calculate summary statistics
$transactionSummary = [
    'total_transactions' => count($transactions),
    'total_amount' => array_sum(array_column($transactions, 'amount_dispensed')),
    'total_value' => array_sum(array_column($transactions, 'coin_value'))
];
$machineSummary = [
    'total_machines' => count($machine_status),
    'active_machines' => count(array_filter($machine_status, fn($m) => $m['is_active'])),
    'avg_water_level' => count($machine_status) ? array_sum(array_column($machine_status, 'water_level')) / count($machine_status) : 0
];
$salesSummary = [
    'total_sales' => array_sum(array_column($sales_summary, 'total_sales')),
    'total_transactions' => array_sum(array_column($sales_summary, 'transaction_count')),
    'max_sales_date' => !empty($sales_summary) ? max(array_column($sales_summary, 'transaction_date')) : null
];
$waterSummary = [
    'total_water' => array_sum(array_column($water_consumption, 'total_water_dispensed')),
    'total_income' => array_sum(array_column($water_consumption, 'total_income')),
    'top_machine' => !empty($water_consumption) ? max(array_column($water_consumption, 'machine_name')) : null
];

/**
 * Build WHERE clause for time filter
 */
function buildTimeWhereClause($timeFilter, $startDate = '', $endDate = '') {
    if ($timeFilter === 'custom' && $startDate && $endDate) {
        $start = htmlspecialchars($startDate);
        $end = htmlspecialchars($endDate);
        return " AND t.DateAndTime BETWEEN '$start 00:00:00' AND '$end 23:59:59'";
    }
    switch ($timeFilter) {
        case 'day':
            return " AND DateAndTime >= CURDATE()";
        case 'week':
            return " AND DateAndTime >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        case 'month':
            return " AND DateAndTime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        case 'year':
            return " AND DateAndTime >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        default:
            return "";
    }
}

/**
 * Generate and download report
 */
function generateReport($reportType, $format, $machineFilter, $timeFilter, $startDate = '', $endDate = '') {
    global $pdo;
    
    // Build WHERE clauses
    $machineWhere = $machineFilter !== 'all' ? " AND t.dispenser_id = " . intval($machineFilter) : '';
    $machineWhereDispenser = $machineFilter !== 'all' ? " AND dispenser.dispenser_id = " . intval($machineFilter) : '';
    $timeWhere = buildTimeWhereClause($timeFilter, $startDate, $endDate);
    
    // Get machine name for title
    $machineName = 'All Machines';
    if ($machineFilter !== 'all') {
        $stmt = $pdo->prepare("SELECT Description FROM dispenser WHERE dispenser_id = ?");
        $stmt->execute([intval($machineFilter)]);
        $machineName = $stmt->fetchColumn();
    }
    $machineNameForFile = preg_replace('/[^A-Za-z0-9]/', '', $machineName);
    
    // Calculate actual date range for display
    $currentDate = new DateTime();
    $dateRangeDisplay = '';
    if ($timeFilter === 'custom' && $startDate && $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $dateRangeDisplay = $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
        $timeFilterName = 'Custom';
    } else {
        switch ($timeFilter) {
            case 'day':
                $dateRangeDisplay = $currentDate->format('M j, Y');
                $timeFilterName = 'Today';
                break;
            case 'week':
                $startDate = (clone $currentDate)->modify('-6 days');
                $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
                $timeFilterName = 'Last7Days';
                break;
            case 'month':
                $startDate = (clone $currentDate)->modify('-29 days');
                $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
                $timeFilterName = 'Last30Days';
                break;
            case 'year':
                $startDate = (clone $currentDate)->modify('-364 days');
                $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $currentDate->format('M j, Y');
                $timeFilterName = 'LastYear';
                break;
            default:
                $dateRangeDisplay = 'All Time';
                $timeFilterName = 'AllTime';
        }
    }
    
    // Get data based on report type
    switch ($reportType) {
        case 'transactions':
            $data = $pdo->query("
                SELECT 
                    t.transaction_id, 
                    t.amount_dispensed, 
                    t.DateAndTime, 
                    d.Description as machine_name,
                    l.location_name,
                    CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED) as coin_value
                FROM transaction t
                JOIN dispenser d ON t.dispenser_id = d.dispenser_id
                LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
                LEFT JOIN location l ON dl.location_id = l.location_id
                WHERE 1=1 $machineWhere $timeWhere
                ORDER BY t.DateAndTime DESC
            ")->fetchAll();
            $title = "Transaction Report";
            $columns = [
                'Transaction ID', 
                'Date & Time', 
                'Machine', 
                'Location', 
                'Amount Dispensed (L)', 
                'Coin Value (PHP)'
            ];
            break;
            
        case 'machines':
            $data = $pdo->query("
                SELECT 
                    dispenser.dispenser_id,
                    dispenser.Description as machine_name,
                    l.location_name,
                    ds.water_level,
                    dispenser.Capacity,
                    CASE WHEN dl.Status = 1 THEN 'Active' ELSE 'Inactive' END as status
                FROM dispenser
                JOIN dispenserstatus ds ON dispenser.dispenser_id = ds.dispenser_id
                LEFT JOIN dispenserlocation dl ON dispenser.dispenser_id = dl.dispenser_id
                LEFT JOIN location l ON dl.location_id = l.location_id
                WHERE 1=1 $machineWhereDispenser
            ")->fetchAll();
            $title = "Machine Status Report";
            $columns = [
                'Machine ID', 
                'Machine Name', 
                'Location', 
                'Water Level (L)', 
                'Capacity (L)', 
                'Status'
            ];
            break;
            
        case 'sales':
            $data = $pdo->query("
                SELECT 
                    DATE(t.DateAndTime) as transaction_date,
                    SUM(CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED)) as total_sales,
                    COUNT(*) as transaction_count,
                    SUM(t.amount_dispensed) as total_water_dispensed
                FROM transaction t
                JOIN dispenser d ON t.dispenser_id = d.dispenser_id
                WHERE 1=1 $machineWhere $timeWhere
                GROUP BY DATE(t.DateAndTime)
                ORDER BY transaction_date DESC
            ")->fetchAll();
            $title = "Sales Summary Report";
            $columns = [
                'Date', 
                'Total Sales (PHP)', 
                'Transaction Count', 
                'Total Water Dispensed (L)'
            ];
            break;
            
        case 'water':
            $data = $pdo->query("
                SELECT 
                    dispenser.dispenser_id,
                    dispenser.Description as machine_name,
                    l.location_name,
                    SUM(t.amount_dispensed) as total_water_dispensed,
                    COUNT(t.transaction_id) as transaction_count,
                    SUM(CAST(REGEXP_REPLACE(t.coin_type, '[^0-9]', '') AS UNSIGNED)) as total_income
                FROM dispenser
                LEFT JOIN transaction t ON dispenser.dispenser_id = t.dispenser_id $timeWhere
                LEFT JOIN dispenserlocation dl ON dispenser.dispenser_id = dl.dispenser_id
                LEFT JOIN location l ON dl.location_id = l.location_id
                WHERE 1=1 $machineWhereDispenser
                GROUP BY dispenser.dispenser_id, dispenser.Description, l.location_name
                ORDER BY total_water_dispensed DESC
            ")->fetchAll();
            $title = "Water Consumption Report";
            $columns = [
                'Machine ID', 
                'Machine Name', 
                'Location', 
                'Total Water Dispensed (L)', 
                'Transaction Count', 
                'Total Income (PHP)'
            ];
            break;
            
        default:
            $data = [];
            $title = "Report";
            $columns = [];
            break;
    }
    
    // Add filter info to title
    $fullTitle = $title . " - $machineName - $dateRangeDisplay";
    
    if ($format === 'csv') {
        generateCSVReport($fullTitle, $columns, $data, $machineFilter, $timeFilter, $machineName, $timeFilterName, $dateRangeDisplay);
    }
}

/**
 * Generate CSV report
 */
function generateCSVReport($title, $columns, $data, $machineFilter, $timeFilter, $machineName, $timeFilterName, $dateRangeDisplay) {
    ob_end_clean();
    
    $reportType = explode(' - ', $title)[0];
    $reportTypeForFile = str_replace(' ', '', $reportType);
    $machineNameForFile = preg_replace('/[^A-Za-z0-9]/', '', $machineName);
    $fileName = "{$reportTypeForFile}_{$machineNameForFile}_{$timeFilterName}_" . date('Ymd_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['Report:', $title]);
    fputcsv($output, ['Machine:', $machineName]);
    fputcsv($output, ['Date Range:', $dateRangeDisplay]);
    fputcsv($output, []);
    
    fputcsv($output, $columns);
    
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($row as $key => $value) {
            if (!is_numeric($key)) {
                $displayValue = is_numeric($value) && strpos($key, 'amount') === false ? 
                    number_format($value, 2) : 
                    $value;
                $csvRow[] = $displayValue;
            }
        }
        fputcsv($output, $csvRow);
    }
    
    fclose($output);
    exit;
}
?>
<div class="content-area">
    <div class="content-wrapper">
        <div class="content-header">
            <h1 class="content-title">Reports</h1>
            <div class="content-actions">
                <!-- Download buttons will be added via JavaScript -->
            </div>
        </div>
        
        <div class="report-controls">
            <form id="reportFilters" method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="filter-group">
                    <label for="reportType">Report Type:</label>
                    <select id="reportType" name="report" class="form-control">
                        <option value="transactions" <?php echo $currentReportType === 'transactions' ? 'selected' : ''; ?>>Transaction Report</option>
                        <option value="machines" <?php echo $currentReportType === 'machines' ? 'selected' : ''; ?>>Machine Status Report</option>
                        <option value="sales" <?php echo $currentReportType === 'sales' ? 'selected' : ''; ?>>Sales Summary Report</option>
                        <option value="water" <?php echo $currentReportType === 'water' ? 'selected' : ''; ?>>Water Consumption Report</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="machineFilter">Machine:</label>
                    <select id="machineFilter" name="machine" class="form-control">
                        <option value="all" <?php echo $currentMachineFilter === 'all' ? 'selected' : ''; ?>>All Machines</option>
                        <?php foreach ($machines as $machine): ?>
                        <option value="<?php echo $machine['dispenser_id']; ?>" <?php echo $currentMachineFilter == $machine['dispenser_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($machine['Description']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="timeFilter">Time Period:</label>
                    <select id="timeFilter" name="time" class="form-control">
                        <option value="day" <?php echo $currentTimeFilter === 'day' ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo $currentTimeFilter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="month" <?php echo $currentTimeFilter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="year" <?php echo $currentTimeFilter === 'year' ? 'selected' : ''; ?>>Last Year</option>
                        <option value="custom" <?php echo $currentTimeFilter === 'custom' ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="visualType">Visual Type:</label>
                    <select id="visualType" name="visual" class="form-control">
                        <?php if ($currentReportType === 'transactions'): ?>
                            <option value="table" selected>Table</option>
                        <?php else: ?>
                            <option value="table" <?php echo $currentVisualType === 'table' ? 'selected' : ''; ?>>Table</option>
                            <option value="bar" <?php echo $currentVisualType === 'bar' ? 'selected' : ''; ?>>Bar Chart</option>
                            <option value="line" <?php echo $currentVisualType === 'line' ? 'selected' : ''; ?>>Line Chart</option>
                            <option value="pie" <?php echo $currentVisualType === 'pie' ? 'selected' : ''; ?>>Pie Chart</option>
                            <option value="area" <?php echo $currentVisualType === 'area' ? 'selected' : ''; ?>>Area Chart</option>
                            <option value="stacked" <?php echo $currentVisualType === 'stacked' ? 'selected' : ''; ?>>Stacked Bar Chart</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="filter-group button-group">
                    <button type="button" id="downloadPdf" class="btn btn-download pdf">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button type="button" id="downloadCsv" class="btn btn-download csv">
                        <i class="fas fa-file-csv"></i> CSV
                    </button>
                </div>

                <!-- Hidden inputs for custom date range -->
                <input type="hidden" id="startDate" name="start_date" value="<?php echo htmlspecialchars($currentStartDate); ?>">
                <input type="hidden" id="endDate" name="end_date" value="<?php echo htmlspecialchars($currentEndDate); ?>">
            </form>
            <div id="downloadError" class="error-message" style="display: none;">
                <p><i class="fas fa-exclamation-circle"></i> Failed to initiate download. Please try again.</p>
            </div>
        </div>
        
        <!-- Custom Date Modal -->
        <div id="customDateModal" class="modal" style="display: none;">
            <div class="modal-content">
                <span class="close">×</span>
                <h2>Select Custom Date Range</h2>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="modalStartDate">Start Date:</label>
                        <input type="date" id="modalStartDate" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="modalEndDate">End Date:</label>
                        <input type="date" id="modalEndDate" class="form-control">
                    </div>
                    <button id="applyDateRange" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </div>

        <div class="report-preview" id="reportPreview">
            <div class="report-container <?php echo $currentReportType === 'transactions' ? 'active' : ''; ?>" id="transactionsReport">
                <h2>Transaction Report</h2>
                <div class="filter-info">
                    <p><strong>Machine:</strong> <?php echo htmlspecialchars($machineName); ?></p>
                    <p><strong>Date Range:</strong> <?php echo htmlspecialchars($dateRangeDisplay); ?></p>
                </div>
                <div class="report-summary">
                    <p>This report details individual transactions, showing a total of <?php echo $transactionSummary['total_transactions']; ?> transactions with <?php echo number_format($transactionSummary['total_amount'], 2); ?> liters dispensed and PHP <?php echo number_format($transactionSummary['total_value'], 2); ?> in revenue.</p>
                </div>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Date & Time</th>
                                <th>Machine</th>
                                <th>Location</th>
                                <th>Amount (L)</th>
                                <th>Value (PHP)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo $transaction['transaction_id']; ?></td>
                                <td><?php echo date('M j, Y h:i A', strtotime($transaction['DateAndTime'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['machine_name']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['location_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $transaction['amount_dispensed']; ?></td>
                                <td><?php echo number_format($transaction['coin_value'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="report-container <?php echo $currentReportType === 'machines' ? 'active' : ''; ?>" id="machinesReport">
                <h2>Machine Status Report</h2>
                <div class="filter-info">
                    <p><strong>Machine:</strong> <?php echo htmlspecialchars($machineName); ?></p>
                    <p><strong>Date Range:</strong> <?php echo htmlspecialchars($dateRangeDisplay); ?></p>
                </div>
                <div class="report-summary">
                    <?php if ($currentVisualType === 'table'): ?>
                        <p>This report covers <?php echo $machineSummary['total_machines']; ?> machines, with <?php echo $machineSummary['active_machines']; ?> active and an average water level of <?php echo number_format($machineSummary['avg_water_level'], 2); ?> liters.</p>
                    <?php else: ?>
                        <p>The chart illustrates the status of <?php echo $machineSummary['total_machines']; ?> machines, showing <?php echo $machineSummary['active_machines']; ?> active units and an average water level of <?php echo number_format($machineSummary['avg_water_level'], 2); ?> liters.</p>
                    <?php endif; ?>
                </div>
                <?php if ($currentVisualType === 'table'): ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Machine ID</th>
                                <th>Machine Name</th>
                                <th>Location</th>
                                <th>Water Level (L)</th>
                                <th>Capacity (L)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($machine_status as $machine): ?>
                            <tr>
                                <td><?php echo $machine['dispenser_id']; ?></td>
                                <td><?php echo htmlspecialchars($machine['machine_name']); ?></td>
                                <td><?php echo htmlspecialchars($machine['location_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $machine['water_level']; ?></td>
                                <td><?php echo $machine['Capacity']; ?></td>
                                <td><?php echo $machine['is_active'] ? 'Active' : 'Inactive'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="chart-container">
                    <canvas id="machinesChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="report-container <?php echo $currentReportType === 'sales' ? 'active' : ''; ?>" id="salesReport">
                <h2>Sales Summary Report</h2>
                <div class="filter-info">
                    <p><strong>Machine:</strong> <?php echo htmlspecialchars($machineName); ?></p>
                    <p><strong>Date Range:</strong> <?php echo htmlspecialchars($dateRangeDisplay); ?></p>
                </div>
                <div class="report-summary">
                    <?php if ($currentVisualType === 'table'): ?>
                        <p>This report summarizes <?php echo $salesSummary['total_transactions']; ?> transactions, generating PHP <?php echo number_format($salesSummary['total_sales'], 2); ?> in sales, with peak activity on <?php echo $salesSummary['max_sales_date'] ? date('M j, Y', strtotime($salesSummary['max_sales_date'])) : 'N/A'; ?>.</p>
                    <?php else: ?>
                        <p>The chart highlights sales trends, with <?php echo $salesSummary['total_transactions']; ?> transactions and PHP <?php echo number_format($salesSummary['total_sales'], 2); ?> in revenue, peaking on <?php echo $salesSummary['max_sales_date'] ? date('M j, Y', strtotime($salesSummary['max_sales_date'])) : 'N/A'; ?>.</p>
                    <?php endif; ?>
                </div>
                <?php if ($currentVisualType === 'table'): ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Sales (PHP)</th>
                                <th>Transactions</th>
                                <th>Water Dispensed (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_summary as $day): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($day['transaction_date'])); ?></td>
                                <td><?php echo number_format($day['total_sales'], 2); ?></td>
                                <td><?php echo $day['transaction_count']; ?></td>
                                <td><?php echo $day['total_water_dispensed']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="chart-container">
                    <canvas id="salesChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="report-container <?php echo $currentReportType === 'water' ? 'active' : ''; ?>" id="waterReport">
                <h2>Water Consumption Report</h2>
                <div class="filter-info">
                    <p><strong>Machine:</strong> <?php echo htmlspecialchars($machineName); ?></p>
                    <p><strong>Date Range:</strong> <?php echo htmlspecialchars($dateRangeDisplay); ?></p>
                </div>
                <div class="report-summary">
                    <?php if ($currentVisualType === 'table'): ?>
                        <p>This report shows <?php echo number_format($waterSummary['total_water'], 2); ?> liters dispensed, generating PHP <?php echo number_format($waterSummary['total_income'], 2); ?> in income, with <?php echo htmlspecialchars($waterSummary['top_machine'] ?? 'N/A'); ?> as the top-performing machine.</p>
                    <?php else: ?>
                        <p>The chart displays water consumption, with <?php echo number_format($waterSummary['total_water'], 2); ?> liters dispensed and PHP <?php echo number_format($waterSummary['total_income'], 2); ?> in revenue, led by <?php echo htmlspecialchars($waterSummary['top_machine'] ?? 'N/A'); ?>.</p>
                    <?php endif; ?>
                </div>
                <?php if ($currentVisualType === 'table'): ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Machine ID</th>
                                <th>Machine Name</th>
                                <th>Location</th>
                                <th>Water Dispensed (L)</th>
                                <th>Transactions</th>
                                <th>Total Income (PHP)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($water_consumption as $machine): ?>
                            <tr>
                                <td><?php echo $machine['dispenser_id']; ?></td>
                                <td><?php echo htmlspecialchars($machine['machine_name']); ?></td>
                                <td><?php echo htmlspecialchars($machine['location_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $machine['total_water_dispensed'] ?? 0; ?></td>
                                <td><?php echo $machine['transaction_count'] ?? 0; ?></td>
                                <td><?php echo number_format($machine['total_income'] ?? 0, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="chart-container">
                    <canvas id="waterChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.content-area {
    padding: 15px 0 0 0;
    background-color: #f8f9fa;
    width: 100%;
    margin-left: 0;
}

.content-wrapper {
    padding: 0 20px;
    max-width: 100%;
    margin: 0 auto;
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.content-title {
    font-size: 20px;
    color: #2c3e50;
}

.report-controls {
    background: white;
    padding: 15px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 15px;
}

#reportFilters {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    min-width: 150px;
    flex-grow: 1;
}

.filter-group label {
    margin-bottom: 4px;
    font-weight: 500;
    color: #2c3e50;
    font-size: 13px;
}

.button-group {
    display: flex;
    gap: 8px;
    align-items: center;
}

.form-control {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
}

.btn {
    padding: 6px 12px;
    border-radius: 3px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-download {
    color: white;
}

.btn-download.pdf {
    background-color: #e74c3c;
}

.btn-download.csv {
    background-color: #27ae60;
}

.btn-download:hover {
    opacity: 0.9;
}

.error-message {
    margin-top: 8px;
    padding: 8px;
    border-radius: 3px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.report-preview {
    background: white;
    padding: 15px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    max-width: 90%;
    margin: 0 auto;
}

.report-container {
    display: none;
    width: 100%;
    overflow-x: visible;
}

.report-container.active {
    display: block;
}

.filter-info {
    margin-bottom: 10px;
    font-size: 12px;
    color: #2c3e50;
}

.filter-info p {
    margin: 4px 0;
}

.report-summary {
    margin-bottom: 10px;
    font-size: 14px;
    color: #34495e;
    background: #f9f9f9;
    padding: 8px;
    border-radius: 3px;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.report-table th, 
.report-table td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.report-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
}

.report-table tr:hover {
    background-color: #f5f5f5;
}

.table-responsive {
    overflow-x: auto;
    width: 100%;
}

.chart-container {
    position: relative;
    max-width: 100%;
    margin: 15px 0;
    overflow-x: visible;
}

.chart-container canvas {
    max-width: 100%;
    height: auto;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.modal-content {
    background-color: #fefefe;
    margin: 15% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 80%;
    max-width: 400px;
    border-radius: 6px;
}

.close {
    color: #aaa;
    float: right;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover,
.close:focus {
    color: #000;
    text-decoration: none;
}

.modal-body {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.btn-primary {
    background-color: #3498db;
    color: white;
    padding: 8px 16px;
    border-radius: 3px;
    border: none;
    cursor: pointer;
    font-size: 13px;
}

.btn-primary:hover {
    background-color: #2980b9;
}

@media (max-width: 768px) {
    #reportFilters {
        flex-direction: column;
        gap: 8px;
    }
    
    .filter-group {
        min-width: 100%;
    }
    
    .button-group {
        width: 100%;
        justify-content: space-between;
    }
    
    .report-table th, 
    .report-table td {
        padding: 6px;
        font-size: 11px;
    }
    
    .chart-container {
        max-width: 100%;
        overflow-x: auto;
    }
    
    .error-message {
        font-size: 11px;
        padding: 6px;
    }
    
    .report-preview {
        padding: 10px;
    }
    
    .report-summary {
        font-size: 14px;
        padding: 6px;
    }
}

/* Print styles for PDF */
@media print {
    body * {
        visibility: hidden;
    }
    .report-container.active,
    .report-container.active * {
        visibility: visible !important;
    }
    .report-controls, .content-header, .button-group, .error-message, .modal {
        display: none !important;
    }
    .report-preview {
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
        margin: 0 !important;
        background: white !important;
        border-radius: 0 !important;
    }
    .report-container.active {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        text-align: center !important;
        overflow: visible !important;
        page-break-inside: avoid;
    }
    .report-container h2,
    .filter-info,
    .report-summary {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        text-align: left !important;
        overflow: visible !important;
        page-break-inside: avoid;
    }
    .chart-container {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        height: auto !important;
        margin: 0 auto !important;
        padding: 0 !important;
        text-align: center !important;
        overflow: visible !important;
        page-break-inside: avoid;
    }
    .chart-container canvas {
        width: 100% !important;
        max-width: 100% !important;
        height: auto !important;
        margin: 0 auto !important;
        overflow: visible !important;
    }
    .table-responsive {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        text-align: center !important;
        overflow: visible !important;
        page-break-inside: avoid;
    }
    .report-table {
        font-size: 9pt !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        overflow: visible !important;
    }
    .report-table th, 
    .report-table td {
        padding: 6px !important;
        white-space: nowrap;
        overflow: visible !important;
    }
    .report-summary {
        font-size: 10pt !important;
        padding: 8px !important;
        width: 100% !important;
        background: #f9f9f9 !important;
        border-radius: 3px !important;
    }
    .filter-info p {
        font-size: 9pt !important;
    }
    @page {
        margin: 0.5in;
    }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const reportFiltersForm = document.getElementById('reportFilters');
    const reportTypeSelect = document.getElementById('reportType');
    const visualTypeSelect = document.getElementById('visualType');
    const selects = document.querySelectorAll('#reportFilters select');
    const downloadError = document.getElementById('downloadError');
    const timeFilter = document.getElementById('timeFilter');
    const customDateModal = document.getElementById('customDateModal');
    const modalStartDate = document.getElementById('modalStartDate');
    const modalEndDate = document.getElementById('modalEndDate');
    const applyDateRange = document.getElementById('applyDateRange');
    const closeModal = document.querySelector('.modal .close');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');

    // Function to update visual type options based on report type
    function updateVisualTypeOptions() {
        const reportType = reportTypeSelect.value;
        const currentVisualType = visualTypeSelect.value;

        // Store current visual type if not transactions
        let selectedVisualType = reportType === 'transactions' ? 'table' : currentVisualType;

        // Clear current options
        visualTypeSelect.innerHTML = '';

        if (reportType === 'transactions') {
            // Only allow table for transactions
            visualTypeSelect.innerHTML = '<option value="table" selected>Table</option>';
        } else {
            // Allow all visual types for other reports
            const options = [
                { value: 'table', text: 'Table' },
                { value: 'bar', text: 'Bar Chart' },
                { value: 'line', text: 'Line Chart' },
                { value: 'pie', text: 'Pie Chart' },
                { value: 'area', text: 'Area Chart' },
                { value: 'stacked', text: 'Stacked Bar Chart' }
            ];
            options.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.text;
                if (opt.value === selectedVisualType) {
                    option.selected = true;
                }
                visualTypeSelect.appendChild(option);
            });
        }
    }

    // Initialize visual type options on page load
    updateVisualTypeOptions();

    // Update visual type options when report type changes
    reportTypeSelect.addEventListener('change', updateVisualTypeOptions);

    // Auto-submit form on select change (except timeFilter)
    selects.forEach(select => {
        if (select.id !== 'timeFilter') {
            select.addEventListener('change', function() {
                console.log(`Select ${select.id} changed to ${select.value}`);
                downloadError.style.display = 'none';
                try {
                    // Force visual type to table if report type is transactions
                    if (reportTypeSelect.value === 'transactions') {
                        visualTypeSelect.value = 'table';
                    }
                    reportFiltersForm.submit();
                } catch (error) {
                    console.error('Form submission failed:', error);
                    downloadError.style.display = 'block';
                }
            });
        }
    });

    // Handle time filter change
    timeFilter.addEventListener('change', function() {
        if (timeFilter.value === 'custom') {
            customDateModal.style.display = 'block';
            modalStartDate.value = startDateInput.value || '';
            modalEndDate.value = endDateInput.value || '';
            modalStartDate.focus();
        } else {
            startDateInput.value = '';
            endDateInput.value = '';
            reportFiltersForm.submit();
        }
    });

    // Close modal
    closeModal.addEventListener('click', function() {
        customDateModal.style.display = 'none';
        timeFilter.value = '<?php echo $currentTimeFilter === 'custom' ? 'custom' : 'month'; ?>'; // Revert to previous
    });

    // Apply custom date range
    applyDateRange.addEventListener('click', applyCustomDateRange);

    // Apply on Enter key in date inputs
    modalStartDate.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyCustomDateRange();
    });
    modalEndDate.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyCustomDateRange();
    });

    function applyCustomDateRange() {
        const startDate = modalStartDate.value;
        const endDate = modalEndDate.value;
        if (startDate && endDate && new Date(startDate) <= new Date(endDate)) {
            startDateInput.value = startDate;
            endDateInput.value = endDate;
            customDateModal.style.display = 'none';
            reportFiltersForm.submit();
        } else {
            alert('Please select valid start and end dates.');
        }
    }

    // Download buttons
    document.getElementById('downloadPdf').addEventListener('click', function() {
        downloadError.style.display = 'none';
        downloadReport('pdf');
    });
    
    document.getElementById('downloadCsv').addEventListener('click', function() {
        downloadError.style.display = 'none';
        downloadReport('csv');
    });
    
    // Chart rendering
    const visualType = '<?php echo $currentVisualType; ?>';
    const reportType = '<?php echo $currentReportType; ?>';
    
    if (visualType !== 'table' && reportType !== 'transactions') {
        renderChart();
    }
    
    function renderChart() {
        let chartData, labels, datasets, chartType, options;
        
        const visualTypeMap = {
            'bar': 'bar',
            'line': 'line',
            'pie': 'pie',
            'area': 'line',
            'stacked': 'bar'
        };
        
        chartType = visualTypeMap[visualType] || 'bar';
        
        switch (reportType) {
            case 'machines':
                chartData = <?php echo json_encode($machine_status); ?>;
                labels = chartData.map(item => item.machine_name);
                datasets = [
                    {
                        label: 'Water Level (L)',
                        data: chartData.map(item => item.water_level),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${i * 360 / chartData.length}, 70%, 50%)`) : 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area'
                    },
                    {
                        label: 'Capacity (L)',
                        data: chartData.map(item => item.Capacity),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${(i * 360 / chartData.length) + 120}, 70%, 50%)`) : 'rgba(255, 206, 86, 0.5)',
                        borderColor: 'rgba(255, 206, 86, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area',
                        hidden: visualType === 'pie'
                    }
                ];
                options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: chartType !== 'pie',
                            position: 'top',
                            labels: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        title: {
                            display: true,
                            text: `Machine Status Report - ${visualType.charAt(0).toUpperCase() + visualType.slice(1)}`,
                            font: { size: 16 } // 12pt ≈ 16px
                        }
                    },
                    scales: chartType === 'pie' ? {} : {
                        x: {
                            title: { 
                                display: true, 
                                text: 'Machine',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: { 
                                display: true, 
                                text: 'Liters',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        }
                    },
                    ...(visualType === 'stacked' && {
                        scales: {
                            x: { 
                                stacked: true,
                                title: { 
                                    display: true, 
                                    text: 'Machine',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            },
                            y: { 
                                stacked: true, 
                                beginAtZero: true,
                                title: { 
                                    display: true, 
                                    text: 'Liters',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            }
                        }
                    })
                };
                break;

            case 'sales':
                chartData = <?php echo json_encode($sales_summary); ?>;
                labels = chartData.map(item => new Date(item.transaction_date).toLocaleDateString());
                datasets = [
                    {
                        label: 'Total Sales (PHP)',
                        data: chartData.map(item => item.total_sales),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${i * 360 / chartData.length}, 70%, 50%)`) : 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area'
                    },
                    {
                        label: 'Water Dispensed (L)',
                        data: chartData.map(item => item.total_water_dispensed),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${(i * 360 / chartData.length) + 120}, 70%, 50%)`) : 'rgba(255, 159, 64, 0.5)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area',
                        hidden: visualType === 'pie'
                    }
                ];
                options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: chartType !== 'pie',
                            position: 'top',
                            labels: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        title: {
                            display: true,
                            text: `Sales Summary Report - ${visualType.charAt(0).toUpperCase() + visualType.slice(1)}`,
                            font: { size: 16 } // 12pt ≈ 16px
                        }
                    },
                    scales: chartType === 'pie' ? {} : {
                        x: {
                            title: { 
                                display: true, 
                                text: 'Date',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: { 
                                display: true, 
                                text: 'Value',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        }
                    },
                    ...(visualType === 'stacked' && {
                        scales: {
                            x: { 
                                stacked: true,
                                title: { 
                                    display: true, 
                                    text: 'Date',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            },
                            y: { 
                                stacked: true, 
                                beginAtZero: true,
                                title: { 
                                    display: true, 
                                    text: 'Value',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            }
                        }
                    })
                };
                break;

            case 'water':
                chartData = <?php echo json_encode($water_consumption); ?>;
                labels = chartData.map(item => item.machine_name);
                datasets = [
                    {
                        label: 'Water Dispensed (L)',
                        data: chartData.map(item => item.total_water_dispensed || 0),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${i * 360 / chartData.length}, 70%, 50%)`) : 'rgba(153, 102, 255, 0.5)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area'
                    },
                    {
                        label: 'Total Income (PHP)',
                        data: chartData.map(item => item.total_income || 0),
                        backgroundColor: visualType === 'pie' ? chartData.map((_, i) => `hsl(${(i * 360 / chartData.length) + 120}, 70%, 50%)`) : 'rgba(255, 99, 132, 0.5)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: chartType === 'pie' ? 0 : 1,
                        fill: visualType === 'area',
                        hidden: visualType === 'pie'
                    }
                ];
                options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: chartType !== 'pie',
                            position: 'top',
                            labels: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        title: {
                            display: true,
                            text: `Water Consumption Report - ${visualType.charAt(0).toUpperCase() + visualType.slice(1)}`,
                            font: { size: 16 } // 12pt ≈ 16px
                        }
                    },
                    scales: chartType === 'pie' ? {} : {
                        x: {
                            title: { 
                                display: true, 
                                text: 'Machine',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: { 
                                display: true, 
                                text: 'Value',
                                font: { size: 16 } // 12pt ≈ 16px
                            },
                            ticks: {
                                font: { size: 16 } // 12pt ≈ 16px
                            }
                        }
                    },
                    ...(visualType === 'stacked' && {
                        scales: {
                            x: { 
                                stacked: true,
                                title: { 
                                    display: true, 
                                    text: 'Machine',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            },
                            y: { 
                                stacked: true, 
                                beginAtZero: true,
                                title: { 
                                    display: true, 
                                    text: 'Value',
                                    font: { size: 16 }
                                },
                                ticks: {
                                    font: { size: 16 }
                                }
                            }
                        }
                    })
                };
                break;

            default:
                return;
        }

        const canvasId = `${reportType}Chart`;
        const ctx = document.getElementById(canvasId)?.getContext('2d');
        if (!ctx) {
            console.error(`Canvas element ${canvasId} not found`);
            return;
        }

        // Calculate dynamic width based on number of labels and visual type
        const baseWidth = visualType === 'pie' ? 400 : 600;
        const widthPerLabel = visualType === 'pie' ? 30 : 50;
        const maxWidth = window.innerWidth * 0.9; // Cap at 90% of viewport width
        let calculatedWidth = Math.min(baseWidth + (labels.length * widthPerLabel), maxWidth);

        // Adjust width for long charts to fit within viewport if needed
        if (calculatedWidth > maxWidth && visualType !== 'pie') {
            calculatedWidth = maxWidth;
        }

        const canvas = document.getElementById(canvasId);
        const chartContainer = canvas.parentElement;
        
        // Set dynamic width for chart container and canvas
        chartContainer.style.width = `${calculatedWidth}px`;
        chartContainer.style.maxWidth = '100%';
        canvas.width = calculatedWidth;
        canvas.height = visualType === 'pie' ? 300 : 200;

        // Adjust report container to accommodate chart width
        const reportContainer = chartContainer.closest('.report-container');
        reportContainer.style.maxWidth = visualType === 'pie' ? '600px' : `${Math.min(calculatedWidth + 20, maxWidth)}px`;
        reportContainer.style.margin = '0 auto';

        if (window[canvasId]?.chart) {
            window[canvasId].chart.destroy();
        }

        window[canvasId] = new Chart(ctx, {
            type: chartType,
            data: {
                labels: labels,
                datasets: datasets
            },
            options: options
        });
    }

    function downloadReport(format) {
        const reportType = document.getElementById('reportType').value;
        const machineFilter = document.getElementById('machineFilter').value;
        const timeFilter = document.getElementById('timeFilter').value;
        let visualType = document.getElementById('visualType').value;
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        // Force visual type to table for transactions
        if (reportType === 'transactions') {
            visualType = 'table';
        }

        if (format === 'csv') {
            const url = `<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?download=1&format=csv&report=${reportType}&machine=${machineFilter}&time=${timeFilter}&visual=${visualType}&start_date=${startDate}&end_date=${endDate}`;
            window.location.href = url;
        } else if (format === 'pdf') {
            const reportContainer = document.querySelector('.report-container.active');
            if (!reportContainer) {
                console.error('No active report container found');
                downloadError.style.display = 'block';
                return;
            }

            // Ensure chart or table is visible based on visual type
            const chartContainer = reportContainer.querySelector('.chart-container');
            const tableContainer = reportContainer.querySelector('.table-responsive');
            let originalChartDisplay, originalTableDisplay;

            if (visualType !== 'table') {
                if (chartContainer) {
                    originalChartDisplay = chartContainer.style.display;
                    chartContainer.style.display = 'block';
                }
                if (tableContainer) {
                    originalTableDisplay = tableContainer.style.display;
                    tableContainer.style.display = 'none';
                }
            } else {
                if (chartContainer) {
                    originalChartDisplay = chartContainer.style.display;
                    chartContainer.style.display = 'none';
                }
                if (tableContainer) {
                    originalTableDisplay = tableContainer.style.display;
                    tableContainer.style.display = 'block';
                }
            }

            // Calculate content width, adjusting for bar charts
            const isBarChart = visualType === 'bar' || visualType === 'stacked';
            let contentWidth;
            if (visualType !== 'table' && chartContainer) {
                // For charts, use the chart container's width or calculate based on data
                contentWidth = parseInt(chartContainer.style.width) || chartContainer.scrollWidth;
                if (isBarChart) {
                    // Estimate width based on number of bars (labels)
                    const chartData = {
                        machines: <?php echo json_encode($machine_status); ?>,
                        sales: <?php echo json_encode($sales_summary); ?>,
                        water: <?php echo json_encode($water_consumption); ?>
                    }[reportType];
                    const labelCount = chartData.length;
                    const widthPerBar = 50; // Pixels per bar, matching renderChart logic
                    contentWidth = Math.max(contentWidth, 600 + labelCount * widthPerBar);
                }
            } else if (tableContainer) {
                // For tables, use the table's scrollWidth
                contentWidth = tableContainer.scrollWidth;
            } else {
                contentWidth = 600; // Fallback
            }

            // Cap content width to fit within PDF page
            const maxPageWidthPx = 960; // 10in at 96 DPI (landscape after margins)
            contentWidth = Math.min(contentWidth, maxPageWidthPx);
            const isWide = contentWidth > 720; // Threshold for landscape (7.5in at 96 DPI)

            // Configure PDF settings
            const margin = 0.5; // 0.5 inch margins
            const pageWidth = isWide ? 11 : 8.5; // Letter size in inches
            const pageHeight = isWide ? 8.5 : 11;
            const printableWidth = pageWidth - 2 * margin; // Width after margins
            const printableHeight = pageHeight - 2 * margin; // Height after margins
            const contentWidthInches = contentWidth / 96; // Convert pixels to inches at 96 DPI

            // Initialize jsPDF
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({
                orientation: isWide ? 'landscape' : 'portrait',
                unit: 'in',
                format: 'letter'
            });

            // Calculate scaling for html2canvas
            const scale = Math.min(2, printableWidth / contentWidthInches); // Cap scale at 2 for quality

            // Configure html2canvas
            const canvasOptions = {
                scale: scale,
                useCORS: true,
                logging: false,
                width: contentWidth,
                windowWidth: contentWidth
            };

            // Render canvas and generate PDF
            setTimeout(() => {
                html2canvas(reportContainer, canvasOptions).then(canvas => {
                    const imgData = canvas.toDataURL('image/jpeg', 0.98);
                    const imgWidth = Math.min(contentWidthInches, printableWidth);
                    const imgHeight = (canvas.height / canvas.width) * imgWidth;

                    // Calculate x-offset to center content
                    const xOffset = (printableWidth - imgWidth) / 2 + margin;

                    // Add image to PDF
                    pdf.addImage(imgData, 'JPEG', xOffset, margin, imgWidth, imgHeight);

                    // Save PDF
                    pdf.save(`${reportType}_${machineFilter}_${timeFilter}_${visualType}_${new Date().toISOString().replace(/[:.]/g, '')}.pdf`);

                    // Restore original display
                    if (chartContainer) chartContainer.style.display = originalChartDisplay;
                    if (tableContainer) tableContainer.style.display = originalTableDisplay;
                }).catch(err => {
                    console.error('PDF generation failed:', err);
                    downloadError.style.display = 'block';
                    if (chartContainer) chartContainer.style.display = originalChartDisplay;
                    if (tableContainer) tableContainer.style.display = originalTableDisplay;
                });
            }, 1000);
        }
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
ob_end_flush();
?>