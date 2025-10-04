<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';

if($_SESSION['admin_role'] !== 'Administrator') {
    header("Location: dashboard.php");
    exit;
}

// Handle form submissions
$notification = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        // Add new user
        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO userlogin (username, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)");
        if ($stmt->execute([$_POST['username'], $_POST['email'], $hashed_password, $_POST['role']])) {
            $notification = 'success|User successfully added!';
        } else {
            $notification = 'error|Failed to add user.';
        }
    } elseif (isset($_POST['edit_user'])) {
        // Edit existing user
        $params = [$_POST['username'], $_POST['email'], $_POST['role'], $_POST['user_id']];
        $sql = "UPDATE userlogin SET username = ?, email = ?, role = ? WHERE user_id = ?";
        
        if (!empty($_POST['password'])) {
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE userlogin SET username = ?, email = ?, password = ?, role = ? WHERE user_id = ?";
            $params = [$_POST['username'], $_POST['email'], $hashed_password, $_POST['role'], $_POST['user_id']];
        }
        
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            $notification = 'success|User successfully updated!';
        } else {
            $notification = 'error|Failed to update user.';
        }
    } elseif (isset($_POST['update_status'])) {
        // Update user status
        if ($_POST['user_id'] != $_SESSION['admin_id']) {
            $stmt = $pdo->prepare("UPDATE userlogin SET is_active = ? WHERE user_id = ?");
            if ($stmt->execute([$_POST['status'], $_POST['user_id']])) {
                $notification = 'success|User status successfully updated!';
            } else {
                $notification = 'error|Failed to update user status.';
            }
        } else {
            $notification = 'error|You cannot change your own account status!';
        }
    } elseif (isset($_POST['delete_user'])) {
        // Delete user
        if ($_POST['user_id'] != $_SESSION['admin_id']) {
            $stmt = $pdo->prepare("DELETE FROM userlogin WHERE user_id = ?");
            if ($stmt->execute([$_POST['user_id']])) {
                $notification = 'success|User successfully deleted!';
            } else {
                $notification = 'error|Failed to delete user.';
            }
        } else {
            $notification = 'error|You cannot delete your own account!';
        }
    }
    // Refresh to show changes
    header("Location: users.php");
    exit;
}

$users = $pdo->query("SELECT * FROM userlogin ORDER BY role, username")->fetchAll();
$current_user_id = $_SESSION['admin_id'];
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
            <h1 class="content-title">User Management</h1>
            <div class="content-actions">
                <button class="btn-primary" id="addUserBtn">
                    <i class="fas fa-user-plus"></i> Add New User
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $user): ?>
                    <tr>
                        <td><?= $user['user_id'] ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td>
                            <span class="role-badge <?= strtolower($user['role']) ?>">
                                <?= $user['role'] ?>
                            </span>
                        </td>
                        <td>
                            <?php if($user['user_id'] == $current_user_id): ?>
                                <span class="status-btn <?= $user['is_active'] ? 'active' : 'inactive' ?> no-click">
                                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            <?php else: ?>
                                <button class="status-btn <?= $user['is_active'] ? 'active' : 'inactive' ?>" 
                                        onclick="showStatusModal(<?= $user['user_id'] ?>, <?= $user['is_active'] ?>, '<?= addslashes($user['username']) ?>')">
                                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-action edit" onclick="showEditModal(
                                <?= $user['user_id'] ?>, 
                                '<?= addslashes($user['username']) ?>', 
                                '<?= addslashes($user['email']) ?>', 
                                '<?= addslashes($user['role']) ?>'
                            )">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if($user['user_id'] != $current_user_id): ?>
                                <button class="btn-action delete" onclick="showDeleteModal(
                                    <?= $user['user_id'] ?>,
                                    '<?= addslashes($user['username']) ?>'
                                )">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <span class="btn-action delete disabled" onclick="showAdminRestrictionModal()">
                                    <i class="fas fa-trash"></i>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Add New User</h2>
        <form method="POST">
            <div class="input-group">
                <label for="add_username">Username</label>
                <input type="text" id="add_username" name="username" required>
            </div>
            <div class="input-group">
                <label for="add_email">Email</label>
                <input type="email" id="add_email" name="email" required>
            </div>
            <div class="input-group">
                <label for="add_password">Password</label>
                <input type="password" id="add_password" name="password" required>
            </div>
            <div class="input-group">
                <label for="add_role">Role</label>
                <select id="add_role" name="role" required>
                    <option value="Administrator">Administrator</option>
                    <option value="Technician">Technician</option>
                    <option value="Staff">Staff</option>
                    <option value="Student">Student</option>
                </select>
            </div>
            <button type="submit" name="add_user" class="btn-primary">Add User</button>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Edit User</h2>
        <form method="POST">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="input-group">
                <label for="edit_username">Username</label>
                <input type="text" id="edit_username" name="username" required>
            </div>
            <div class="input-group">
                <label for="edit_email">Email</label>
                <input type="email" id="edit_email" name="email" required>
            </div>
            <div class="input-group">
                <label for="edit_password">New Password (leave blank to keep current)</label>
                <input type="password" id="edit_password" name="password">
            </div>
            <div class="input-group">
                <label for="edit_role">Role</label>
                <select id="edit_role" name="role" required>
                    <option value="Administrator">Administrator</option>
                    <option value="Technician">Technician</option>
                    <option value="Staff">Staff</option>
                    <option value="Student">Student</option>
                </select>
            </div>
            <button type="submit" name="edit_user" class="btn-primary">Save Changes</button>
        </form>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal" id="statusUserModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Update User Status</h2>
        <form method="POST">
            <input type="hidden" name="user_id" id="status_user_id">
            <div class="input-group">
                <label>Current Status</label>
                <p id="current_status_text"></p>
            </div>
            <div class="input-group">
                <label for="new_status">New Status</label>
                <select id="new_status" name="status" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <button type="submit" name="update_status" class="btn-primary">Update Status</button>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteUserModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Confirm Deletion</h2>
        <p id="deleteModalText">Are you sure you want to delete this user? This action cannot be undone.</p>
        <form method="POST">
            <input type="hidden" name="user_id" id="delete_user_id">
            <input type="hidden" name="delete_user" value="1">
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteUserModal')">Cancel</button>
                <button type="submit" class="btn-danger">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Restriction Modal -->
<div class="modal" id="adminRestrictionModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Action Restricted</h2>
        <p>You cannot modify your own account status or delete your own account.</p>
        <div class="form-actions">
            <button type="button" class="btn-primary" onclick="closeModal('adminRestrictionModal')">OK</button>
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
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 500px;
    position: relative;
}

.close-modal {
    position: absolute;
    right: 20px;
    top: 10px;
    font-size: 24px;
    cursor: pointer;
}

.input-group {
    margin-bottom: 15px;
}

.input-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.input-group input,
.input-group textarea,
.input-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.btn-danger {
    background-color: #e74c3c;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
}

.btn-danger:hover {
    background-color: #c0392b;
}

.role-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 500;
}

.role-badge.administrator {
    background-color: #3498db;
    color: white;
}

.role-badge.technician {
    background-color: #2ecc71;
    color: white;
}

.role-badge.staff {
    background-color: #9b59b6;
    color: white;
}

.role-badge.student {
    background-color: #f1c40f;
    color: black;
}

.status-btn {
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    color: white;
    font-size: 14px;
    font-weight: 500;
    min-width: 80px;
    transition: all 0.2s;
}

.status-btn.active {
    background-color: #2ecc71;
}

.status-btn.active:hover {
    background-color: #27ae60;
}

.status-btn.inactive {
    background-color: #e74c3c;
}

.status-btn.inactive:hover {
    background-color: #c0392b;
}

.status-btn.no-click {
    cursor: default;
    pointer-events: none;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 4px;
    margin-right: 5px;
    transition: all 0.3s;
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

.btn-action.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.notification-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 4px;
    color: white;
    font-weight: 500;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    z-index: 1100;
    animation: slideIn 0.3s, fadeOut 0.5s 2.5s forwards;
}

.notification-toast.success {
    background-color: #2ecc71;
}

.notification-toast.error {
    background-color: #e74c3c;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}
</style>

<script>
// Show add user modal
document.getElementById('addUserBtn').addEventListener('click', function() {
    document.getElementById('addUserModal').style.display = 'block';
});

// Show edit user modal
function showEditModal(id, username, email, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    document.getElementById('editUserModal').style.display = 'block';
}

// Show status modal
function showStatusModal(id, currentStatus, username) {
    document.getElementById('status_user_id').value = id;
    document.getElementById('current_status_text').textContent = currentStatus ? 'Active' : 'Inactive';
    document.getElementById('new_status').value = currentStatus ? '0' : '1';
    document.getElementById('statusUserModal').style.display = 'block';
}

// Show delete confirmation modal
function showDeleteModal(id, username) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('deleteModalText').textContent = `Are you sure you want to delete the user "${username}"? This action cannot be undone.`;
    document.getElementById('deleteUserModal').style.display = 'block';
}

// Show admin restriction modal
function showAdminRestrictionModal() {
    document.getElementById('adminRestrictionModal').style.display = 'block';
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

// Auto-hide notification toast
document.addEventListener('DOMContentLoaded', function() {
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>