<?php
$pageTitle = 'Machines';
require_once __DIR__ . '/../includes/header.php';

// Handle form submissions
$notification = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_machine'])) {
        // Add new machine
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO dispenser (Description, Capacity) VALUES (?, ?)");
            if ($stmt->execute([$_POST['description'], $_POST['capacity']])) {
                $machineId = $pdo->lastInsertId();
                // Insert initial status
                $stmt = $pdo->prepare("INSERT INTO dispenserstatus (water_level, operational_status, dispenser_id) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['capacity'], 'Normal', $machineId]);
                // Set location if provided
                if (!empty($_POST['location_id'])) {
                    $stmt = $pdo->prepare("INSERT INTO dispenserlocation (location_id, dispenser_id, Status, DateDeployed) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$_POST['location_id'], $machineId, $_POST['status']]);
                }
                $pdo->commit();
                $notification = 'success|Machine successfully added!';
            } else {
                $pdo->rollBack();
                $notification = 'error|Failed to add machine.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $notification = 'error|Failed to add machine: ' . $e->getMessage();
        }
    } elseif (isset($_POST['edit_machine'])) {
        // Edit existing machine
        try {
            // If location_id is empty, force status to Disabled
            $status = !empty($_POST['location_id']) ? $_POST['status'] : 0;

            // Check if status is being set to Enabled and no location is selected
            if ($status == '1' && empty($_POST['location_id'])) {
                $notification = 'error|Cannot enable machine: Deploy the machine to a location first.';
            } else {
                // Check if any changes were made
                $stmt = $pdo->prepare("
                    SELECT d.Description, d.Capacity, dl.location_id, dl.Status
                    FROM dispenser d
                    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
                    WHERE d.dispenser_id = ?
                ");
                $stmt->execute([$_POST['machine_id']]);
                $current = $stmt->fetch();

                $noChanges = (
                    $current['Description'] === $_POST['description'] &&
                    $current['Capacity'] == $_POST['capacity'] &&
                    ($current['location_id'] == ($_POST['location_id'] ?: null)) &&
                    $current['Status'] == $status
                );

                if ($noChanges) {
                    $notification = 'success|Machine saved successfully (no changes made).';
                } else {
                    $pdo->beginTransaction();
                    // Update dispenser details
                    $stmt = $pdo->prepare("UPDATE dispenser SET Description = ?, Capacity = ? WHERE dispenser_id = ?");
                    if ($stmt->execute([$_POST['description'], $_POST['capacity'], $_POST['machine_id']])) {
                        // Handle location
                        $stmt = $pdo->prepare("SELECT * FROM dispenserlocation WHERE dispenser_id = ?");
                        $stmt->execute([$_POST['machine_id']]);
                        $hasLocation = $stmt->rowCount() > 0;

                        if (empty($_POST['location_id'])) {
                            // Remove location and set status to Disabled
                            if ($hasLocation) {
                                $stmt = $pdo->prepare("DELETE FROM dispenserlocation WHERE dispenser_id = ?");
                                $stmt->execute([$_POST['machine_id']]);
                            }
                        } else {
                            // Update or insert location
                            if ($hasLocation) {
                                $stmt = $pdo->prepare("UPDATE dispenserlocation SET location_id = ?, Status = ?, DateDeployed = NOW() WHERE dispenser_id = ?");
                                $stmt->execute([$_POST['location_id'], $status, $_POST['machine_id']]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO dispenserlocation (location_id, dispenser_id, Status, DateDeployed) VALUES (?, ?, ?, NOW())");
                                $stmt->execute([$_POST['location_id'], $_POST['machine_id'], $status]);
                            }
                        }
                        // Adjust water_level if capacity is reduced
                        $stmt = $pdo->prepare("UPDATE dispenserstatus SET water_level = LEAST(water_level, ?) WHERE dispenser_id = ?");
                        $stmt->execute([$_POST['capacity'], $_POST['machine_id']]);
                        $pdo->commit();
                        $notification = 'success|Machine successfully updated!';
                    } else {
                        $pdo->rollBack();
                        $notification = 'error|Failed to update machine.';
                    }
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $notification = 'error|Failed to update machine: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_machine'])) {
        // Delete machine
        try {
            $pdo->beginTransaction();
            // Delete dependent records
            $stmt = $pdo->prepare("DELETE FROM transaction WHERE dispenser_id = ?");
            $stmt->execute([$_POST['machine_id']]);
            $stmt = $pdo->prepare("DELETE FROM dispenserstatus WHERE dispenser_id = ?");
            $stmt->execute([$_POST['machine_id']]);
            $stmt = $pdo->prepare("DELETE FROM dispenserlocation WHERE dispenser_id = ?");
            $stmt->execute([$_POST['machine_id']]);
            // Delete from dispenser
            $stmt = $pdo->prepare("DELETE FROM dispenser WHERE dispenser_id = ?");
            if ($stmt->execute([$_POST['machine_id']])) {
                $pdo->commit();
                $notification = 'success|Machine successfully deleted!';
            } else {
                $pdo->rollBack();
                $notification = 'error|Failed to delete machine.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $notification = 'error|Cannot delete machine due to database error.';
        }
    } elseif (isset($_POST['update_status'])) {
        // Update machine status
        try {
            // Check if status is being set to Enabled and location is Not Deployed
            $stmt = $pdo->prepare("SELECT dl.location_id FROM dispenserlocation dl WHERE dl.dispenser_id = ?");
            $stmt->execute([$_POST['machine_id']]);
            $location = $stmt->fetch();
            if ($_POST['status'] == '1' && !$location) {
                $notification = 'error|Cannot enable machine: Deploy the machine to a location first.';
            } else {
                $stmt = $pdo->prepare("UPDATE dispenserlocation SET Status = ? WHERE dispenser_id = ?");
                if ($stmt->execute([$_POST['status'], $_POST['machine_id']])) {
                    $notification = 'success|Machine status successfully updated!';
                } else {
                    $notification = 'error|Failed to update machine status.';
                }
            }
        } catch (PDOException $e) {
            $notification = 'error|Failed to update machine status: ' . $e->getMessage();
        }
    }
}

// Get all machines with their locations and status
$machines = $pdo->query("
    SELECT d.*, dl.Status, dl.location_id, l.location_name, ds.water_level, ds.operational_status
    FROM dispenser d
    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    LEFT JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
")->fetchAll();

// Get all locations for dropdowns
$locations = $pdo->query("SELECT * FROM location ORDER BY location_name")->fetchAll();

// Check if we should show the add modal
$showAddModal = isset($_GET['showAddModal']) && $_GET['showAddModal'] == 'true';
?>

<div class="content-area">
    <div class="content-wrapper">
        <!-- Notification Toast -->
        <?php if ($notification): ?>
        <div class="notification-toast <?= explode('|', $notification)[0] ?>">
            <?= explode('|', $notification)[1] ?>
        </div>
        <?php endif; ?>
        
        <div class="content-header">
            <h1 class="content-title">Machine Management</h1>
            <div class="content-actions">
                <div class="search-group">
                    <label for="searchInput">Search:</label>
                    <input type="text" id="searchInput" placeholder="Search machines...">
                </div>
                <div class="rows-per-page">
                    <label for="rowsPerPage">Rows per page:</label>
                    <select id="rowsPerPage">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                    </select>
                </div>
                <div>
                    <button class="btn-primary" id="addMachineBtn">
                        <i class="fas fa-plus"></i> Add New Machine
                    </button>
                </div>
            </div>
        </div>
        
        <div class="table-container">
            <table class="data-table" id="machinesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Description</th>
                        <th>Capacity</th>
                        <th>Water Level</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($machines as $machine): ?>
                    <tr>
                        <td><?= $machine['dispenser_id'] ?></td>
                        <td><?= htmlspecialchars($machine['Description']) ?></td>
                        <td><?= $machine['Capacity'] ?>L</td>
                        <td><?= number_format($machine['water_level'] ?? 0, 1) ?>L</td>
                        <td><?= htmlspecialchars($machine['location_name'] ?? 'Not Deployed') ?></td>
                        <td>
                            <button class="status-btn <?= $machine['Status'] == 1 ? 'enabled' : 'disabled' ?>" 
                                    onclick="showStatusModal(<?= $machine['dispenser_id'] ?>, <?= $machine['Status'] ?? 0 ?>, '<?= addslashes($machine['location_name'] ?? 'Not Deployed') ?>')">
                                <?= $machine['Status'] == 1 ? 'Enabled' : 'Disabled' ?>
                            </button>
                        </td>
                        <td>
                            <button class="btn-action edit" onclick="showEditModal(
                                <?= $machine['dispenser_id'] ?>, 
                                '<?= addslashes($machine['Description']) ?>', 
                                <?= $machine['Capacity'] ?>, 
                                <?= $machine['location_id'] ?? 'null' ?>, 
                                <?= $machine['Status'] ?? 0 ?>,
                                '<?= addslashes($machine['location_name'] ?? 'Not Deployed') ?>'
                            )">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-action delete" onclick="showDeleteModal(<?= $machine['dispenser_id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination" id="pagination"></div>
        </div>
    </div>
</div>

<!-- Add Machine Modal -->
<div class="modal" id="addMachineModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Machine</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="input-group">
                    <label for="add_description">Description</label>
                    <input type="text" id="add_description" name="description" required>
                </div>
                <div class="input-group">
                    <label for="add_capacity">Capacity (Liters)</label>
                    <input type="number" id="add_capacity" name="capacity" min="1" step="0.1" required>
                </div>
                <div class="input-group">
                    <label for="add_location">Location</label>
                    <select id="add_location" name="location_id">
                        <option value="">-- Select Location --</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?= $location['location_id'] ?>"><?= htmlspecialchars($location['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="add_status">Status</label>
                    <select id="add_status" name="status" required>
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="add_machine" class="btn-primary">Save Machine</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addMachineModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Machine Modal -->
<div class="modal" id="editMachineModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Machine</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST" id="editMachineForm">
            <div class="modal-body">
                <input type="hidden" name="machine_id" id="edit_machine_id">
                <div class="input-group">
                    <label for="edit_description">Description</label>
                    <input type="text" id="edit_description" name="description" required>
                </div>
                <div class="input-group">
                    <label for="edit_capacity">Capacity (Liters)</label>
                    <input type="number" id="edit_capacity" name="capacity" min="1" step="0.1" required>
                </div>
                <div class="input-group">
                    <label for="edit_location">Location</label>
                    <select id="edit_location" name="location_id">
                        <option value="">-- Select Location --</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?= $location['location_id'] ?>"><?= htmlspecialchars($location['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" required exaltation="mandatory" required>
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="edit_machine" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editMachineModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal" id="statusModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Update Machine Status</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST" id="statusForm">
            <div class="modal-body">
                <input type="hidden" name="machine_id" id="status_machine_id">
                <div class="input-group">
                    <label>Current Status</label>
                    <p id="current_status_text"></p>
                </div>
                <div class="input-group" id="status_message" style="display: none;">
                    <p style="color: #e74c3c;">Cannot enable machine: Deploy the machine to a location first.</p>
                </div>
                <div class="input-group">
                    <label for="new_status">New Status</label>
                    <select id="new_status" name="status" required>
                        <option value="0">Disabled</option>
                        <option value="1">Enabled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_status" class="btn-primary">Update Status</button>
                <button type="button" class="btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteMachineModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Confirm Deletion</h2>
            <span class="close-modal">×</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this machine? This action will also remove all associated transactions and status records and cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="machine_id" id="delete_machine_id">
            <div class="modal-footer">
                <button type="submit" name="delete_machine" class="btn-danger">Delete Machine</button>
                <button type="button" class="btn-secondary" onclick="closeModal('deleteMachineModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Deploy Alert Modal -->
<div class="modal" id="deployAlertModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Deployment Required</h2>
            <span class="close-modal">×</span>
        </div>
        <div class="modal-body">
            <p>Cannot enable machine: Deploy the machine to a location first.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-primary" onclick="closeModal('deployAlertModal')">OK</button>
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
.input-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.input-group input:focus,
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

.status-btn {
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    min-width: 80px;
    transition: background-color 0.2s, transform 0.1s;
}

.status-btn.enabled {
    background-color: #2ecc71;
}

.status-btn.enabled:hover {
    background-color: #27ae60;
    transform: translateY(-1px);
}

.status-btn.disabled {
    background-color: #e74c3c;
}

.status-btn.disabled:hover {
    background-color: #c0392b;
    transform: translateY(-1px);
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 6px;
    margin-right: 8px;
    transition: all 0.2s;
}

.btn-action.edit {
    background-color: rgba(52, 152, 219, 0.1);
    color: #3498db;
}

.btn-action.edit:hover {
    background-color: #3498db;
    color: white;
}

.btn-action.delete {
    background-color: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
}

.btn-action.delete:hover {
    background-color: #e74c3c;
    color: white;
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

.content-title {
    font-size: 24px;
    color: #2c3e50;
    font-weight: 600;
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
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes fadeOut {
    from {
        opacity: 1;
    }
    to {
        opacity: 0;
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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
    
    .btn-primary {
        width: 100%;
        justify-content: center;
    }
    
    .modal-content {
        width: 95%;
        margin: 10% auto;
    }
}
</style>

<script>
// State management
let currentPage = 1;
let rowsPerPage = 10;
let searchTerm = '';

// Filter and paginate table
function filterAndPaginate() {
    const rows = document.querySelectorAll('#machinesTable tbody tr');
    const filteredRows = [];
    
    // Filter rows based on search term
    rows.forEach(row => {
        const id = row.cells[0].textContent.toLowerCase();
        const description = row.cells[1].textContent.toLowerCase();
        const capacity = row.cells[2].textContent.toLowerCase();
        const waterLevel = row.cells[3].textContent.toLowerCase();
        const location = row.cells[4].textContent.toLowerCase();
        const status = row.cells[5].textContent.toLowerCase();
        
        if (
            id.includes(searchTerm.toLowerCase()) ||
            description.includes(searchTerm.toLowerCase()) ||
            capacity.includes(searchTerm.toLowerCase()) ||
            waterLevel.includes(searchTerm.toLowerCase()) ||
            location.includes(searchTerm.toLowerCase()) ||
            status.includes(searchTerm.toLowerCase())
        ) {
            filteredRows.push(row);
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Calculate pagination
    const totalRows = filteredRows.length;
    const totalPages = Math.ceil(totalRows / rowsPerPage);
    currentPage = Math.min(currentPage, Math.max(1, totalPages));
    
    // Show/hide rows based on current page
    filteredRows.forEach((row, index) => {
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        row.style.display = (index >= start && index < end) ? '' : 'none';
    });
    
    // Update pagination controls
    updatePagination(totalPages);
}

// Update pagination controls
function updatePagination(totalPages) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
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

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Initialize table
    filterAndPaginate();
    
    // Search input event listener
    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        currentPage = 1;
        filterAndPaginate();
    });
    
    // Rows per page event listener
    document.getElementById('rowsPerPage').addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        filterAndPaginate();
    });
    
    // Show add machine modal on page load if parameter is present
    const urlParams = new URLSearchParams(window.location.search);
    const showAddModal = urlParams.get('showAddModal');
    
    if (showAddModal === 'true') {
        document.getElementById('addMachineModal').style.display = 'block';
        // Clean up the URL
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
    
    // Show add machine modal
    document.getElementById('addMachineBtn').addEventListener('click', function() {
        document.getElementById('addMachineModal').style.display = 'block';
    });
    
    // Auto-hide notification toast
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
});

// Show edit machine modal
function showEditModal(id, description, capacity, locationId, status, locationName) {
    document.getElementById('edit_machine_id').value = id;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_capacity').value = capacity;
    document.getElementById('edit_location').value = locationId || '';
    document.getElementById('edit_status').value = status;
    document.getElementById('editMachineModal').dataset.locationName = locationName;
    document.getElementById('editMachineModal').style.display = 'block';
    
    // Update status dropdown based on location
    const statusSelect = document.getElementById('edit_status');
    statusSelect.disabled = !locationId;
    if (!locationId) {
        statusSelect.value = '0';
    }
}

// Show status modal
function showStatusModal(id, currentStatus, locationName) {
    const statusSelect = document.getElementById('new_status');
    const statusMessage = document.getElementById('status_message');
    
    document.getElementById('status_machine_id').value = id;
    document.getElementById('current_status_text').textContent = currentStatus == 1 ? 'Enabled' : 'Disabled';
    document.getElementById('statusModal').dataset.locationName = locationName;
    
    // Check if machine is deployed
    const isDeployed = locationName !== 'Not Deployed';
    
    // Reset dropdown options
    statusSelect.innerHTML = '';
    
    // Add Disabled option (always available)
    const disabledOption = document.createElement('option');
    disabledOption.value = '0';
    disabledOption.textContent = 'Disabled';
    statusSelect.appendChild(disabledOption);
    
    // Add Enabled option only if machine is deployed
    if (isDeployed) {
        const enabledOption = document.createElement('option');
        enabledOption.value = '1';
        enabledOption.textContent = 'Enabled';
        statusSelect.appendChild(enabledOption);
    }
    
    // Set current status
    statusSelect.value = currentStatus;
    
    // Show/hide deployment message
    statusMessage.style.display = isDeployed ? 'none' : 'block';
    
    // Disable submit button if not deployed and trying to enable
    const submitButton = document.querySelector('#statusForm button[name="update_status"]');
    submitButton.disabled = !isDeployed && currentStatus == 0;
    
    document.getElementById('statusModal').style.display = 'block';
}

// Show delete confirmation modal
function showDeleteModal(id) {
    document.getElementById('delete_machine_id').value = id;
    document.getElementById('deleteMachineModal').style.display = 'block';
}

// Close modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modals when clicking X or outside
document.querySelectorAll('.close-modal').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.modal').style.display = 'none';
    });
});

window.addEventListener('click', function(event) {
    if (event.target.className === 'modal') {
        event.target.style.display = 'none';
    }
});

// Client-side validation and status adjustment for Edit Machine form
document.getElementById('editMachineForm').addEventListener('submit', function(event) {
    const locationId = document.getElementById('edit_location').value;
    const statusSelect = document.getElementById('edit_status');

    if (!locationId) {
        // If no location is selected, force status to Disabled
        statusSelect.value = '0';
    }
});

// Update status dropdown based on location selection
document.getElementById('edit_location').addEventListener('change', function() {
    const locationId = this.value;
    const statusSelect = document.getElementById('edit_status');
    
    if (!locationId) {
        statusSelect.value = '0';
        statusSelect.disabled = true; // Disable status dropdown to indicate it's forced to Disabled
    } else {
        statusSelect.disabled = false;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>