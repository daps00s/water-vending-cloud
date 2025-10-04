<?php
// Database connection
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "water_dispenser_system";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('<div class="bg-red-100 text-red-700 p-4 m-4 rounded-lg">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '. Please check MySQL server status or contact support@waterdispenser.com.</div>');
}

// Fetch dispensers for dropdown
$dispensers = $conn->query("SELECT dispenser_id, Description FROM dispenser")->fetchAll(PDO::FETCH_ASSOC);
if (empty($dispensers)) {
    die('<div class="bg-red-100 text-red-700 p-4 m-4 rounded-lg">No dispensers found. Please populate the dispenser table in the database.</div>');
}

// Fetch transactions for selected dispenser and date range
$dispenser_id = isset($_GET['dispenser_id']) ? (int)$_GET['dispenser_id'] : 27;
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if ($start_date > $end_date) {
    $calibration_message = '<div class="bg-red-100 text-red-700 p-4 rounded-lg">Error: Start date cannot be after end date. Please select a valid date range.</div>';
    $transactions = [];
    $calibration_transactions = [];
    $total_revenue = 0;
    $total_dispensed = 0;
    $discrepancies = [];
    $chart_labels = ['1 Peso', '5 Peso', '10 Peso'];
    $chart_values = [0, 0, 0];
    $problem_details = '';
    $action_plan = '';
    $adjustment_details = '';
} else {
    $stmt = $conn->prepare("SELECT transaction_id, amount_dispensed, DateAndTime, coin_type, water_type 
                           FROM transaction 
                           WHERE dispenser_id = ? 
                           AND DateAndTime BETWEEN ? AND ? 
                           ORDER BY DateAndTime DESC 
                           LIMIT 50");
    $stmt->execute([$dispenser_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total revenue and dispensed amount
    $total_revenue = 0;
    $total_dispensed = 0;
    $discrepancies = [];
    $coin_values = ['1 Peso' => 1, '5 Peso' => 5, '10 Peso' => 10];
    $expected_amounts = ['1 Peso' => 0.5, '5 Peso' => 2.5, '10 Peso' => 5];

    foreach ($transactions as &$transaction) {
        $total_dispensed += $transaction['amount_dispensed'];
        $coin_value = $coin_values[$transaction['coin_type']] ?? 0;
        $total_revenue += $coin_value;
        $expected = $expected_amounts[$transaction['coin_type']] ?? 0;
        if (abs($transaction['amount_dispensed'] - $expected) > 0.01) {
            $discrepancies[] = $transaction['transaction_id'];
        }
        // Format date with full month name
        $date = new DateTime($transaction['DateAndTime']);
        $transaction['formatted_date'] = $date->format('F j, Y H:i:s');
    }
    unset($transaction); // Unset reference to avoid issues

    // Fetch dispenser description
    $stmt = $conn->prepare("SELECT Description FROM dispenser WHERE dispenser_id = ?");
    $stmt->execute([$dispenser_id]);
    $dispenser_desc = $stmt->fetch(PDO::FETCH_ASSOC)['Description'] ?? 'Unknown';

    // Calibration analysis
    $calibration_message = '<div class="text-gray-600 p-2">No transactions found for calibration analysis. Try a different date range or dispenser.</div>';
    $chart_labels = ['1 Peso', '5 Peso', '10 Peso'];
    $chart_values = [0, 0, 0];
    $problem_details = '';
    $action_plan = '';
    $adjustment_details = '';

    if (!empty($transactions)) {
        $stmt = $conn->prepare("SELECT transaction_id, amount_dispensed, coin_type 
                               FROM transaction 
                               WHERE dispenser_id = ? 
                               AND DateAndTime BETWEEN ? AND ?");
        $stmt->execute([$dispenser_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $calibration_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_actual = 0;
        $total_expected = 0;
        $total_transactions = count($calibration_transactions);
        $correct_transactions = 0;
        $discrepancy_details = [];
        $coin_stats = [
            '1 Peso' => ['total' => 0, 'correct' => 0, 'deviation_sum' => 0],
            '5 Peso' => ['total' => 0, 'correct' => 0, 'deviation_sum' => 0],
            '10 Peso' => ['total' => 0, 'correct' => 0, 'deviation_sum' => 0]
        ];

        foreach ($calibration_transactions as $transaction) {
            $coin_type = $transaction['coin_type'];
            $actual = $transaction['amount_dispensed'];
            $expected = $expected_amounts[$coin_type] ?? 0;
            $total_actual += $actual;
            $total_expected += $expected;
            $deviation = $actual - $expected;

            $coin_stats[$coin_type]['total']++;
            $coin_stats[$coin_type]['deviation_sum'] += $deviation;
            if (abs($deviation) <= 0.01) {
                $correct_transactions++;
                $coin_stats[$coin_type]['correct']++;
            } else {
                $discrepancy_details[] = [
                    'transaction_id' => $transaction['transaction_id'],
                    'coin_type' => $coin_type,
                    'actual' => number_format($actual, 2),
                    'expected' => number_format($expected, 2),
                    'deviation' => number_format($deviation, 2)
                ];
            }
        }

        // Calculate overall accuracy and chart data
        $chart_data = [
            '1 Peso' => ['correct' => $coin_stats['1 Peso']['correct'], 'total' => $coin_stats['1 Peso']['total']],
            '5 Peso' => ['correct' => $coin_stats['5 Peso']['correct'], 'total' => $coin_stats['5 Peso']['total']],
            '10 Peso' => ['correct' => $coin_stats['10 Peso']['correct'], 'total' => $coin_stats['10 Peso']['total']]
        ];
        $chart_values = array_map(function($data) {
            return $data['total'] > 0 ? ($data['correct'] / $data['total']) * 100 : 0;
        }, $chart_data);

        if ($total_transactions > 0) {
            $accuracy = ($correct_transactions / $total_transactions) * 100;
            $status = $accuracy < 90 ? 'text-red-600' : 'text-green-600';
            $calibration_message = '<div class="' . $status . ' p-2 font-medium">Calibration Accuracy: ' . number_format($accuracy, 2) . '% (' . $correct_transactions . '/' . $total_transactions . ' transactions correct)</div>';

            // Problem details
            if ($accuracy < 90) {
                $problem_details = '<h3 class="text-lg font-semibold mt-4">Identified Problems</h3>';
                $problem_details .= '<ul class="list-disc pl-5 text-gray-700">';
                foreach ($coin_stats as $coin_type => $stats) {
                    if ($stats['total'] > 0) {
                        $coin_accuracy = ($stats['correct'] / $stats['total']) * 100;
                        if ($coin_accuracy < 90) {
                            $avg_deviation = $stats['deviation_sum'] / $stats['total'];
                            $problem_details .= '<li>' . htmlspecialchars($coin_type) . ': ' . number_format($coin_accuracy, 2) . '% accuracy, average deviation ' . number_format($avg_deviation, 2) . ' liters</li>';
                        }
                    }
                }
                $problem_details .= '</ul>';

                // Action plan
                $action_plan = '<h3 class="text-lg font-semibold mt-4">Action Plan</h3>';
                $action_plan .= '<ol class="list-decimal pl-5 text-gray-700">';
                $action_plan .= '<li>Inspect the flow meter of ' . htmlspecialchars($dispenser_desc) . ' (ID: ' . $dispenser_id . ') for blockages, wear, or calibration drift.</li>';
                $action_plan .= '<li>Check the coin acceptor to ensure accurate detection of 1 Peso, 5 Peso, and 10 Peso coins.</li>';
                $action_plan .= '<li>Adjust the dispenser’s flow settings as specified below using the control panel or software.</li>';
                $action_plan .= '<li>Run 5 test transactions per coin type and re-run this dashboard to verify accuracy above 90%.</li>';
                $action_plan .= '<li>Contact maintenance at support@waterdispenser.com or +1-800-555-1234 if issues persist after adjustments.</li>';
                $action_plan .= '</ol>';

                // Adjustment details
                $adjustment_details = '<h3 class="text-lg font-semibold mt-4">Detailed Adjustments Needed</h3>';
                $adjustment_details .= '<ul class="list-disc pl-5 text-gray-700">';
                foreach ($coin_stats as $coin_type => $stats) {
                    if ($stats['total'] > 0) {
                        $avg_deviation = $stats['deviation_sum'] / $stats['total'];
                        if (abs($avg_deviation) > 0.01) {
                            $adjustment_details .= '<li>' . htmlspecialchars($coin_type) . ': ' . 
                                ($avg_deviation > 0 ? 'Reduce' : 'Increase') . ' dispensing by ' . 
                                number_format(abs($avg_deviation), 2) . ' liters per transaction.</li>';
                        }
                    }
                }
                $adjustment_details .= '</ul>';

                // Discrepancy details table
                if (!empty($discrepancy_details)) {
                    $problem_details .= '<h3 class="text-lg font-semibold mt-4">Discrepant Transactions</h3>';
                    $problem_details .= '<div class="overflow-x-auto"><table class="w-full border-collapse mt-2 text-sm">';
                    $problem_details .= '<thead><tr class="bg-gray-200"><th class="p-2 border">Transaction ID</th><th class="p-2 border">Coin Type</th><th class="p-2 border">Actual (liters)</th><th class="p-2 border">Expected (liters)</th><th class="p-2 border">Deviation (liters)</th></tr></thead>';
                    $problem_details .= '<tbody>';
                    foreach ($discrepancy_details as $detail) {
                        $problem_details .= '<tr class="discrepancy"><td class="p-2 border">' . $detail['transaction_id'] . '</td>';
                        $problem_details .= '<td class="p-2 border">' . htmlspecialchars($detail['coin_type']) . '</td>';
                        $problem_details .= '<td class="p-2 border">' . $detail['actual'] . '</td>';
                        $problem_details .= '<td class="p-2 border">' . $detail['expected'] . '</td>';
                        $problem_details .= '<td class="p-2 border">' . $detail['deviation'] . '</td></tr>';
                    }
                    $problem_details .= '</tbody></table></div>';
                }
            } else {
                $calibration_message .= '<div class="text-green-600 p-2 font-medium">No significant calibration issues detected. Continue regular monitoring.</div>';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Dispenser Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .discrepancy { background-color: #fee2e2; }
        .table-container { max-height: 300px; overflow-y: auto; }
        .sticky-header { position: sticky; top: 0; z-index: 10; }
        .transaction-row:hover { cursor: pointer; background-color: #f1f5f9; }
        .modal { display: none; position: fixed; z-index: 20; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 500px; border-radius: 8px; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-close:hover { color: #000; }
        @media (max-width: 640px) {
            .grid-cols-2 { grid-template-columns: 1fr; }
            .text-2xl { font-size: 1.25rem; }
            .text-xl { font-size: 1.125rem; }
            canvas { height: 200px !important; }
            .modal-content { margin: 10% auto; width: 95%; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen font-sans">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <!-- Header -->
        <header class="bg-blue-700 text-white p-4 rounded-lg shadow-lg mb-6 relative">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="transactions.php" class="text-white bg-red-600 hover:bg-red-700 px-3 py-1 rounded-md text-sm font-medium">Exit</a>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold">Water Dispenser Dashboard</h1>
                        <p class="text-sm sm:text-base text-blue-100">Monitor and analyze dispenser performance</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Filter Section -->
        <section class="bg-white p-4 sm:p-6 rounded-lg shadow-md mb-6">
            <h2 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Filter Data</h2>
            <form action="" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="dispenser_id" class="block text-sm font-medium text-gray-700">Dispenser</label>
                    <select name="dispenser_id" id="dispenser_id" onchange="this.form.submit()"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($dispensers as $dispenser): ?>
                            <option value="<?= $dispenser['dispenser_id'] ?>"
                                    <?= $dispenser['dispenser_id'] == $dispenser_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dispenser['Description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>"
                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>"
                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-blue-500 sm:text-sm">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 sm:text-sm">Apply</button>
                    <button type="button" onclick="window.location.href='?dispenser_id=27&start_date=<?= date('Y-m-d', strtotime('-7 days')) ?>&end_date=<?= date('Y-m-d') ?>'"
                            class="w-full bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 sm:text-sm">Reset</button>
                </div>
            </form>
        </section>

        <!-- KPI Cards -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="text-base font-semibold text-gray-700">Total Revenue</h3>
                <p class="text-xl sm:text-2xl font-bold text-blue-600">₱<?= number_format($total_revenue, 2) ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="text-base font-semibold text-gray-700">Water Dispensed</h3>
                <p class="text-xl sm:text-2xl font-bold text-green-600"><?= number_format($total_dispensed, 2) ?> L</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-md cursor-pointer" id="discrepancyCard">
                <h3 class="text-base font-semibold text-gray-700">Discrepancies</h3>
                <p class="text-xl sm:text-2xl font-bold <?= count($discrepancies) > 0 ? 'text-red-600' : 'text-gray-600' ?>">
                    <?= count($discrepancies) ?>
                </p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="text-base font-semibold text-gray-700">Calibration Accuracy</h3>
                <p class="text-xl sm:text-2xl font-bold <?= isset($accuracy) && $accuracy < 90 ? 'text-red-600' : 'text-green-600' ?>">
                    <?= isset($accuracy) ? number_format($accuracy, 2) . '%' : 'N/A' ?>
                </p>
            </div>
        </section>

        <!-- Calibration Overview -->
        <section class="bg-white p-4 sm:p-6 rounded-lg shadow-md mb-6">
            <h2 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Calibration Overview</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <?= $calibration_message ?>
                    <?= $problem_details ?>
                    <?= $action_plan ?>
                    <?= $adjustment_details ?>
                </div>
                <div>
                    <canvas id="calibrationChart" class="max-w-full h-48 sm:h-64"></canvas>
                </div>
            </div>
        </section>

        <!-- Transactions Table -->
        <section id="transactionsSection" class="bg-white p-4 sm:p-6 rounded-lg shadow-md">
            <h2 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Recent Transactions</h2>
            <div class="mb-4">
                <input type="text" id="searchInput" placeholder="Search transactions (ID, coin type, water type)..."
                       class="w-full border-gray-300 rounded-md shadow-sm p-2 focus:ring-2 focus:ring-blue-500 sm:text-sm">
            </div>
            <div class="table-container overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-200 sticky-header">
                            <th class="p-2 border text-left">ID</th>
                            <th class="p-2 border text-left">Date & Time</th>
                            <th class="p-2 border text-left">Coin</th>
                            <th class="p-2 border text-left">Dispensed (L)</th>
                            <th class="p-2 border text-left">Water Type</th>
                            <th class="p-2 border text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody id="transactionTable">
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="transaction-row <?= in_array($transaction['transaction_id'], $discrepancies) ? 'discrepancy' : '' ?>"
                                data-transaction='{
                                    "id": "<?= $transaction['transaction_id'] ?>",
                                    "coin_type": "<?= htmlspecialchars($transaction['coin_type']) ?>",
                                    "amount": "<?= number_format($transaction['amount_dispensed'], 2) ?>",
                                    "expected": "<?= number_format($expected_amounts[$transaction['coin_type']] ?? 0, 2) ?>",
                                    "water_type": "<?= htmlspecialchars($transaction['water_type']) ?>",
                                    "date": "<?= htmlspecialchars($transaction['formatted_date']) ?>"
                                }'>
                                <td class="p-2 border"><?= $transaction['transaction_id'] ?></td>
                                <td class="p-2 border"><?= htmlspecialchars($transaction['formatted_date']) ?></td>
                                <td class="p-2 border"><?= htmlspecialchars($transaction['coin_type']) ?></td>
                                <td class="p-2 border"><?= number_format($transaction['amount_dispensed'], 2) ?></td>
                                <td class="p-2 border"><?= htmlspecialchars($transaction['water_type']) ?></td>
                                <td class="p-2 border">
                                    <?= in_array($transaction['transaction_id'], $discrepancies) ? 'Discrepancy' : 'Normal' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Modal -->
        <div id="transactionModal" class="modal">
            <div class="modal-content">
                <span class="modal-close">&times;</span>
                <h2 class="text-lg font-semibold mb-4">Transaction Details</h2>
                <div id="modalContent" class="text-gray-700"></div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="mt-6 text-center text-gray-600 text-sm">
            <p>&copy; 2025 Water Dispenser System. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#transactionTable tr');
            rows.forEach(row => {
                const rowText = Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.toLowerCase()).join(' ');
                row.style.display = rowText.includes(searchTerm) ? '' : 'none';
            });
        });

        // Modal functionality
        const modal = document.getElementById('transactionModal');
        const modalContent = document.getElementById('modalContent');
        const closeModal = document.querySelector('.modal-close');

        document.querySelectorAll('.transaction-row').forEach(row => {
            row.addEventListener('click', function() {
                const data = JSON.parse(this.dataset.transaction);
                modalContent.innerHTML = `
                    <p><strong>Transaction ID:</strong> ${data.id}</p>
                    <p><strong>Coin Type:</strong> ${data.coin_type}</p>
                    <p><strong>Amount Dispensed:</strong> ${data.amount} liters</p>
                    <p><strong>Expected:</strong> ${data.expected} liters</p>
                    <p><strong>Water Type:</strong> ${data.water_type}</p>
                    <p><strong>Date:</strong> ${data.date}</p>
                `;
                modal.style.display = 'block';
            });
        });

        closeModal.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Discrepancy card click functionality
        document.getElementById('discrepancyCard').addEventListener('click', function() {
            const rows = document.querySelectorAll('#transactionTable tr');
            rows.forEach(row => {
                const isDiscrepancy = row.classList.contains('discrepancy');
                row.style.display = isDiscrepancy ? '' : 'none';
            });
            document.getElementById('searchInput').value = ''; // Clear search input
            // Scroll to transactions section
            document.getElementById('transactionsSection').scrollIntoView({ behavior: 'smooth' });
        });

        // Calibration Chart
        const ctx = document.getElementById('calibrationChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Calibration Accuracy (%)',
                    data: <?= json_encode($chart_values) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#ef4444'],
                    borderColor: ['#1d4ed8', '#059669', '#dc2626'],
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: { display: true, text: 'Accuracy (%)', font: { size: 12 } },
                        ticks: { stepSize: 20, font: { size: 10 } }
                    },
                    x: {
                        title: { display: true, text: 'Coin Type', font: { size: 12 } },
                        ticks: { font: { size: 10 } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.raw.toFixed(2)}% accuracy`;
                            }
                        }
                    }
                },
                maintainAspectRatio: false
            }
        });
    </script>
</body>
</html>
<?php $conn = null; ?>