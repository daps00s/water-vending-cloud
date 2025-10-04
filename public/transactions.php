<?php
date_default_timezone_set('Asia/Manila');
$pageTitle = 'Transactions';
require_once __DIR__ . '/../includes/header.php';

// Set date range to last 30 days
$startDate = date('Y-m-d', strtotime('-30 days'));
$endDate = date('Y-m-d');

// Get filters
$machineId = $_GET['machine'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Build query
$query = "SELECT t.*, d.Description as machine_name, l.location_name, t.water_type
          FROM transaction t
          JOIN dispenser d ON t.dispenser_id = d.dispenser_id
          JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
          JOIN location l ON dl.location_id = l.location_id
          WHERE DATE(t.DateAndTime) BETWEEN :startDate AND :endDate";
$params = ['startDate' => $startDate, 'endDate' => $endDate];

if ($machineId) {
    $query .= " AND t.dispenser_id = :machineId";
    $params['machineId'] = $machineId;
}

if ($searchTerm) {
    $query .= " AND (t.transaction_id LIKE :searchTerm1 OR l.location_name LIKE :searchTerm2 OR d.Description LIKE :searchTerm3 OR CAST(t.amount_dispensed AS CHAR) LIKE :searchTerm4 OR t.coin_type LIKE :searchTerm5 OR t.water_type LIKE :searchTerm6)";
    $params['searchTerm1'] = "%$searchTerm%";
    $params['searchTerm2'] = "%$searchTerm%";
    $params['searchTerm3'] = "%$searchTerm%";
    $params['searchTerm4'] = "%$searchTerm%";
    $params['searchTerm5'] = "%$searchTerm%";
    $params['searchTerm6'] = "%$searchTerm%";
}

$query .= " ORDER BY t.DateAndTime DESC";

$transactions = $pdo->prepare($query);
$transactions->execute($params);
$transactions = $transactions->fetchAll();

// Get all machines for filter
$machines = $pdo->query("SELECT dispenser_id, Description FROM dispenser ORDER BY Description")->fetchAll();
?>

<div class="content-area">
    <div class="content-wrapper">
        <div class="content-header">
            <div class="content-title-group">
                <h1 class="content-title">Transaction History (Last 30 Days)</h1>
                <a href="accounting_and_calibration.php" class="btn-primary switch-mode-btn" title="Switch to Accounting and Calibration Mode">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 12h-4l2 2-2 2m-2-2h-4"></path>
                        <path d="M2 12h4l-2-2 2-2m2 2h4"></path>
                    </svg>
                    Switch to Accounting and Calibration
                </a>
            </div>
            <div class="content-actions">
                <div class="search-group">
                    <label for="searchInput">Search:</label>
                    <input type="text" id="searchInput" placeholder="Search transactions..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                </div>
                <div class="rows-per-page">
                    <label for="rowsPerPage">Rows per page:</label>
                    <select id="rowsPerPage">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                        <option value="all">All</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="filter-container">
            <div class="date-filter">
                <select id="machineFilter">
                    <option value="">All Machines</option>
                    <?php foreach ($machines as $machine): ?>
                    <option value="<?php echo $machine['dispenser_id']; ?>" data-name="<?php echo htmlspecialchars($machine['Description']); ?>" <?php echo $machineId == $machine['dispenser_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($machine['Description']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="table-container">
            <table class="data-table" id="transactionsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date & Time</th>
                        <th>Machine</th>
                        <th>Location</th>
                        <th>Amount (L)</th>
                        <th>Water Type</th>
                        <th>Coin Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                    <tr data-machine-id="<?php echo $transaction['dispenser_id']; ?>">
                        <td><?php echo $transaction['transaction_id']; ?></td>
                        <td class="transaction-time"><?php echo date('M j, Y h:i A', strtotime($transaction['DateAndTime'])); ?></td>
                        <td><?php echo htmlspecialchars($transaction['machine_name']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['location_name']); ?></td>
                        <td><?php echo $transaction['amount_dispensed']; ?>L</td>
                        <td><?php echo htmlspecialchars($transaction['water_type']); ?></td>
                        <td><?php echo $transaction['coin_type']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination" id="pagination"></div>
        </div>
    </div>
</div>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    overflow: auto;
}

.modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border-radius: 10px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    animation: fadeIn 0.3s ease;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    background-color: #f8f9fa;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
    color: #2c3e50;
}

.close-modal {
    font-size: 24px;
    color: #6c757d;
    cursor: pointer;
    transition: color 0.2s;
}

.close-modal:hover {
    color: #343a40;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 15px 20px;
    border-top: 1px solid #e9ecef;
    background-color: #f8f9fa;
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
}

.input-group {
    margin-bottom: 20px;
}

.input-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #2c3e50;
}

.input-group input,
.input-group textarea,
.input-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.input-group textarea {
    min-height: 80px;
}

.input-group input:focus,
.input-group textarea:focus,
.input-group select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.2);
}

.btn-primary {
    color: #fff;
    background-color: #3498db;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background-color 0.2s, transform 0.1s;
}

.btn-primary:hover {
    background-color: #2980b9;
    transform: translateY(-1px);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-secondary {
    color: #fff;
    background-color: #6c757d;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: background-color 0.2s, transform 0.1s;
}

.btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-1px);
}

.btn-secondary:active {
    transform: translateY(0);
}

.btn-danger {
    background-color: #e74c3c;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: background-color 0.2s, transform 0.1s;
}

.btn-danger:hover {
    background-color: #c0392b;
    transform: translateY(-1px);
}

.btn-danger:active {
    transform: translateY(0);
}

.switch-mode-btn {
    text-decoration: none;
}

.notification-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    z-index: 1100;
    animation: slideIn 0.3s, fadeOut 0.5s 2.5s forwards;
}

.notification-toast.success {
    background-color: #2ecc71;
}

.notification-toast.error {
    background-color: #e74c3c;
}

.content-area {
    padding: 20px 0;
    background-color: #f8f9fa;
    width: 100%;
}

.content-wrapper {
    padding: 0 30px;
    max-width: 100%;
    margin: 0 auto;
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.content-title-group {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.content-title {
    font-size: 24px;
    color: #2c3e50;
    font-weight: 600;
    margin: 0;
}

.content-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.search-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-group label {
    font-weight: 500;
    color: #2c3e50;
}

.search-group input {
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    width: 200px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.search-group input:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.2);
}

.rows-per-page {
    display: flex;
    align-items: center;
    gap: 8px;
}

.rows-per-page label {
    font-weight: 500;
    color: #2c3e50;
}

.rows-per-page select {
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.rows-per-page select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.2);
}

.filter-container {
    margin-bottom: 20px;
}

.date-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.date-filter select {
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.date-filter select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.2);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.data-table th,
.data-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.data-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
}

.data-table tr:hover {
    background-color: #f1f3f5;
}

.data-table tr.new-transaction {
    animation: blinkGreen 0.5s alternate 6;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
}

.pagination button {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background-color: #fff;
    cursor: pointer;
    color: #2c3e50;
    font-size: 14px;
    transition: background-color 0.2s, transform 0.1s;
}

.pagination button:hover:not(:disabled) {
    background-color: #3498db;
    color: white;
    transform: translateY(-1px);
}

.pagination button:disabled {
    background-color: #f8f9fa;
    color: #6c757d;
    cursor: not-allowed;
}

.pagination .active {
    background-color: #3498db;
    color: white;
    border-color: #3498db;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes blinkGreen {
    from { background-color: #f8f9fa; }
    to { background-color: #90EE90; }
}

@media (max-width: 768px) {
    .content-actions {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .search-group input {
        width: 100%;
    }
    
    .rows-per-page select {
        width: 100%;
    }
    
    .date-filter {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-filter select {
        width: 100%;
    }
}
</style>

<script>
// State management
let currentPage = 1;
let rowsPerPage = 10;
let searchTerm = '<?php echo $searchTerm; ?>';
let currentMachineId = '<?php echo $machineId; ?>';
let knownTransactionIds = new Set();

// Initialize known transactions
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#transactionsTable tbody tr').forEach(row => {
        knownTransactionIds.add(row.cells[0].textContent);
    });
});

// Debounce function to delay search
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function updateURL() {
    const machineId = document.getElementById('machineFilter').value;
    const searchTerm = document.getElementById('searchInput').value;
    
    const params = new URLSearchParams();
    if (machineId) params.set('machine', machineId);
    if (searchTerm) params.set('search', searchTerm);
    
    window.location.href = `transactions.php?${params.toString()}`;
}

// Filter and paginate table
function filterAndPaginate() {
    const rows = document.querySelectorAll('#transactionsTable tbody tr');
    const filteredRows = [];
    
    // Filter rows based on search term and machine filter
    rows.forEach(row => {
        const transactionId = row.cells[0].textContent.toLowerCase();
        const dateTime = row.cells[1].textContent.toLowerCase();
        const machineName = row.cells[2].textContent.toLowerCase();
        const locationName = row.cells[3].textContent.toLowerCase();
        const amount = row.cells[4].textContent.toLowerCase();
        const waterType = row.cells[5].textContent.toLowerCase();
        const coinType = row.cells[6].textContent.toLowerCase();
        
        // Check if row matches search term
        const matchesSearch = searchTerm === '' || 
            transactionId.includes(searchTerm.toLowerCase()) ||
            dateTime.includes(searchTerm.toLowerCase()) ||
            machineName.includes(searchTerm.toLowerCase()) ||
            locationName.includes(searchTerm.toLowerCase()) ||
            amount.includes(searchTerm.toLowerCase()) ||
            waterType.includes(searchTerm.toLowerCase()) ||
            coinType.includes(searchTerm.toLowerCase());
        
        // Check if row matches machine filter
        const matchesMachine = currentMachineId === '' || 
            row.getAttribute('data-machine-id') === currentMachineId;
        
        if (matchesSearch && matchesMachine) {
            filteredRows.push(row);
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Calculate pagination
    const totalRows = filteredRows.length;
    const totalPages = rowsPerPage === 'all' ? 1 : Math.ceil(totalRows / parseInt(rowsPerPage));
    currentPage = Math.min(currentPage, Math.max(1, totalPages));
    
    // Show/hide rows based on current page
    filteredRows.forEach((row, index) => {
        if (rowsPerPage === 'all') {
            row.style.display = '';
        } else {
            const start = (currentPage - 1) * parseInt(rowsPerPage);
            const end = start + parseInt(rowsPerPage);
            row.style.display = (index >= start && index < end) ? '' : 'none';
        }
    });
    
    // Update pagination controls
    updatePagination(totalPages);
}

// Update pagination controls
function updatePagination(totalPages) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
    if (rowsPerPage === 'all') {
        return; // No pagination controls when showing all rows
    }
    
    // Previous button
    const prevButton = document.createElement('button');
    prevButton.textContent = 'Previous';
    prevButton.disabled = currentPage === 1;
    prevButton.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            filterAndPaginate();
        }
    });
    pagination.appendChild(prevButton);
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        const pageButton = document.createElement('button');
        pageButton.textContent = i;
        pageButton.className = i === currentPage ? 'active' : '';
        pageButton.addEventListener('click', () => {
            currentPage = i;
            filterAndPaginate();
        });
        pagination.appendChild(pageButton);
    }
    
    // Next button
    const nextButton = document.createElement('button');
    nextButton.textContent = 'Next';
    nextButton.disabled = currentPage === totalPages || totalPages === 0;
    nextButton.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            filterAndPaginate();
        }
    });
    pagination.appendChild(nextButton);
}

// Refresh transactions
function refreshTransactions() {
    const machineId = document.getElementById('machineFilter').value;
    const searchTerm = document.getElementById('searchInput').value;
    
    // Update current machine ID
    currentMachineId = machineId;
    
    const params = new URLSearchParams();
    params.set('start', '<?php echo $startDate; ?>');
    params.set('end', '<?php echo $endDate; ?>');
    if (machineId) params.set('machine', machineId);
    if (searchTerm) params.set('search', searchTerm);
    
    const url = `api/get_transactions.php?${params.toString()}`;
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            const tbody = document.querySelector('.data-table tbody');
            
            // Sort data to ensure newest transactions are first
            data.sort((a, b) => new Date(b.DateAndTime) - new Date(a.DateAndTime));
            
            // Update table content
            tbody.innerHTML = '';
            data.forEach(transaction => {
                const date = new Date(transaction.DateAndTime);
                const formattedDate = date.toLocaleString('en-US', {
                    timeZone: 'Asia/Manila',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
                
                // Only highlight if this is the first time we've seen this transaction
                const isNew = !knownTransactionIds.has(transaction.transaction_id);
                
                const row = `
                    <tr data-machine-id="${transaction.dispenser_id}" class="${isNew ? 'new-transaction' : ''}">
                        <td>${transaction.transaction_id}</td>
                        <td>${formattedDate}</td>
                        <td>${transaction.machine_name}</td>
                        <td>${transaction.location_name}</td>
                        <td>${transaction.amount_dispensed}L</td>
                        <td>${transaction.water_type}</td>
                        <td>${transaction.coin_type}</td>
                    </tr>
                `;
                tbody.innerHTML += row;
                
                // Add to known transactions to prevent future highlighting
                if (isNew) {
                    knownTransactionIds.add(transaction.transaction_id);
                }
            });
            
            // Ensure new transactions are visible by resetting to first page if new transactions are present
            if (data.some(t => !knownTransactionIds.has(t.transaction_id))) {
                currentPage = 1;
            }
            
            filterAndPaginate();
        })
        .catch(error => console.error('Error refreshing transactions:', error));
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Set initial values from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('rows')) {
        document.getElementById('rowsPerPage').value = urlParams.get('rows');
        rowsPerPage = urlParams.get('rows');
    }
    
    // Initialize current machine ID from URL
    currentMachineId = '<?php echo $machineId; ?>';
    
    filterAndPaginate();
    
    // Debounced search input event listener
    const debouncedSearch = debounce(function() {
        searchTerm = document.getElementById('searchInput').value;
        currentPage = 1;
        updateURL();
    }, 500);
    
    document.getElementById('rowsPerPage').addEventListener('change', function() {
        rowsPerPage = this.value;
        currentPage = 1;
        filterAndPaginate();
    });
    
    // Machine filter event listener
    document.getElementById('machineFilter').addEventListener('change', function() {
        currentMachineId = this.value;
        currentPage = 1;
        filterAndPaginate();
        updateURL();
    });
    
    // Auto-refresh every 2 seconds
    setInterval(refreshTransactions, 2000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>