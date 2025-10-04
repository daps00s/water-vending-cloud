<?php
// includes/user_profile_slide.php
if(!isset($_SESSION)) { session_start(); }
require_once __DIR__ . '/../includes/db_connect.php';

// Handle profile update
$notification = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $user_id = $_SESSION['admin_id'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    
    $params = [$username, $email, $user_id];
    $sql = "UPDATE userlogin SET username = ?, email = ? WHERE user_id = ?";
    
    if (!empty($_POST['password'])) {
        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "UPDATE userlogin SET username = ?, email = ?, password = ? WHERE user_id = ?";
        $params = [$username, $email, $hashed_password, $user_id];
    }
    
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute($params)) {
        $_SESSION['admin_username'] = $username;
        $notification = 'success|Profile successfully updated!';
    } else {
        $notification = 'error|Failed to update profile.';
    }
    
    // Refresh to show changes
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Get current user data
$user = $pdo->query("SELECT * FROM userlogin WHERE user_id = ".$_SESSION['admin_id'])->fetch();
?>

<!-- User Profile Slide Panel -->
<div class="user-profile-slide" id="userProfileSlide">
    <div class="profile-header">
        <button class="profile-close-btn" id="closeProfileSlide"></button>
    </div>
    
    <?php if ($notification): ?>
    <div class="notification-toast <?php echo explode('|', $notification)[0]; ?>">
        <?php echo explode('|', $notification)[1]; ?>
    </div>
    <?php endif; ?>
    
    <div class="profile-content">
        <div class="profile-avatar">
            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
        </div>
        
        <!-- Default View -->
        <div class="profile-view" id="profileView">
            <h3 class="profile-title">My Profile</h3>
            <div class="info-group">
                <label>Username</label>
                <p class="info-value"><?php echo htmlspecialchars($user['username']); ?></p>
            </div>
            <div class="info-group">
                <label>Email</label>
                <p class="info-value"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            <div class="info-group">
                <label>Role</label>
                <p class="info-value profile-role"><?php echo $user['role']; ?></p>
            </div>
            <button class="btn-primary" id="editProfileBtn">Edit Profile</button>
        </div>
        
        <!-- Edit Form -->
        <form method="POST" class="profile-form" id="profileForm" style="display: none;">
            <h3 class="profile-title">Edit Profile</h3>
            <div class="input-group">
                <label for="profile_username">Username</label>
                <input type="text" id="profile_username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required 
                       pattern="[A-Za-z0-9_]{3,20}" title="3-20 characters, letters, numbers, or underscores">
                <span class="input-error"></span>
            </div>
            <div class="input-group">
                <label for="profile_email">Email</label>
                <input type="email" id="profile_email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                <span class="input-error"></span>
            </div>
            <div class="input-group">
                <label for="profile_password">New Password (optional)</label>
                <input type="password" id="profile_password" name="password" 
                       pattern=".{8,}" title="Minimum 8 characters">
                <span class="input-error"></span>
            </div>
            <button type="submit" name="update_profile" class="btn-primary">Save Changes</button>
            <button type="button" class="btn-secondary" id="cancelEditBtn">Cancel</button>
        </form>
    </div>
</div>

<style>
.user-profile-slide {
    position: fixed;
    top: 0;
    right: -380px;
    width: 380px;
    height: 100vh;
    background: #ffffff;
    box-shadow: -4px 0 15px rgba(0,0,0,0.08);
    z-index: 1000;
    transition: right 0.3s ease-out;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.user-profile-slide.open {
    right: 0;
}

.profile-header {
    padding: 20px;
    border-bottom: 1px solid #f0f2f5;
    position: relative;
}

.profile-close-btn {
    position: absolute;
    top: 20px;
    left: 20px;
    appearance: none;
    border: none;
    background: none;
    padding: 0;
    margin: 0;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
}

.profile-content {
    padding: 20px;
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.profile-avatar {
    width: 60px;
    height: 60px;
    background-color: var(--primary);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 24px;
    margin-bottom: 20px;
}

.profile-title {
    margin: 0 0 20px;
    font-size: 1.25rem;
    color: #1e293b;
    text-align: center;
}

.profile-view {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.info-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.info-group label {
    font-size: 0.8125rem;
    color: #475569;
    font-weight: 500;
}

.info-value {
    font-size: 0.875rem;
    color: #1e293b;
    margin: 0;
}

.profile-role {
    padding: 6px 10px;
    background: #f8fafc;
    border-radius: 4px;
    display: inline-block;
}

.profile-form {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.input-group label {
    font-size: 0.8125rem;
    color: #475569;
    font-weight: 500;
}

.input-group input {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.input-group input:focus {
    outline: none;
    border-color: #818cf8;
    box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.2);
}

.input-error {
    font-size: 0.75rem;
    color: #ef4444;
    min-height: 1rem;
}

.btn-primary {
    padding: 10px;
    background:rgb(0, 71, 236);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-primary:hover {
    background:rgb(0, 70, 248);
}

.btn-secondary {
    padding: 10px;
    background: #e2e8f0;
    color: #1e293b;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-secondary:hover {
    background: #cbd5e1;
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
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.getElementById('profileForm');
    const inputs = form.querySelectorAll('input');
    const profileView = document.getElementById('profileView');
    const editBtn = document.getElementById('editProfileBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const profileSlide = document.getElementById('userProfileSlide');
    const closeBtn = document.getElementById('closeProfileSlide');

    // Function to show notification
    function showNotification(message, type = 'success') {
        // Remove existing toasts
        const existingToasts = document.querySelectorAll('.notification-toast');
        existingToasts.forEach(toast => toast.remove());

        const toast = document.createElement('div');
        toast.className = `notification-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    // Form validation
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            const error = this.nextElementSibling;
            if (this.validity.valid || !this.value) {
                error.textContent = '';
            } else {
                error.textContent = this.title;
            }
        });
    });

    // Toggle to edit mode
    editBtn.addEventListener('click', function() {
        profileView.style.display = 'none';
        form.style.display = 'flex';
        showNotification('Profile edit mode activated!');
    });

    // Cancel edit and return to view mode
    cancelBtn.addEventListener('click', function() {
        form.style.display = 'none';
        profileView.style.display = 'flex';
        inputs.forEach(input => {
            const error = input.nextElementSibling;
            error.textContent = '';
        });
        showNotification('Edit canceled.');
    });

    // Close slide panel
    closeBtn.addEventListener('click', function() {
        profileSlide.classList.remove('open');
    });

    // Reset to view mode when slide is closed
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === 'class' && !profileSlide.classList.contains('open')) {
                if (form.style.display !== 'none') {
                    form.style.display = 'none';
                    profileView.style.display = 'flex';
                    inputs.forEach(input => {
                        const error = input.nextElementSibling;
                        error.textContent = '';
                    });
                    showNotification('Edit canceled and profile panel closed.');
                }
            }
        });
    });

    observer.observe(profileSlide, { attributes: true });
});

</script>
