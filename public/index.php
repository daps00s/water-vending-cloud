<?php
// login.php
session_start();
if (isset($_SESSION['admin_logged_in'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Vending Machine - Admin Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Login Page Specific Styles */
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --danger: #e74c3c;
            --success: #2ecc71;
            --light: #ecf0f1;
            --dark: #34495e;
            --gray: #bdc3c7;
            --white: #ffffff;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --gradient: linear-gradient(135deg, #3498db, #2980b9);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            background: var(--gradient);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow: auto;
        }

        .login-wrapper {
            width: 100%;
            max-width: 480px;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
        }

        .login-container {
            background: var(--white);
            padding: 40px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 100%;
            text-align: center;
            animation: slideIn 0.5s ease-in-out;
            backdrop-filter: blur(5px);
            position: relative;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo {
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            object-fit: contain;
        }

        .system-title {
            color: var(--secondary);
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        h1 {
            color: var(--secondary);
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 14px;
            animation: fadeIn 0.3s ease-in-out, fadeOut 0.5s 3s forwards;
            background: rgba(231, 76, 60, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .input-group {
            margin-bottom: 20px;
            text-align: left;
            position: relative;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark);
            font-weight: 500;
            font-size: 14px;
        }

        .input-group .input-wrapper {
            position: relative;
        }

        .input-group input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 1px solid var(--gray);
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }

        .input-group input.invalid {
            border-color: var(--danger);
            background: rgba(231, 76, 60, 0.05);
        }

        .input-group .icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 18px;
        }

        .input-group .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
            font-size: 18px;
        }

        .input-group .toggle-password:hover {
            color: var(--primary);
        }

        .form-actions {
            margin-top: 15px;
        }

        button {
            background: var(--gradient);
            color: var(--white);
            border: none;
            padding: 14px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        button:hover {
            background: linear-gradient(135deg, #2980b9, #1a6ea3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        button:disabled {
            background: var(--gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        button .spinner {
            display: none;
            border: 2px solid var(--white);
            border-top: 2px solid transparent;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 0.8s linear infinite;
        }

        button.loading .spinner {
            display: inline-block;
        }

        button.loading span {
            display: none;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .forgot-password {
            display: block;
            text-align: right;
            margin-top: 10px;
            color: var(--primary);
            font-size: 14px;
            text-decoration: none;
            transition: color 0.3s;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        footer {
            margin-top: 20px;
            color: var(--light);
            font-size: 12px;
            text-align: center;
            opacity: 0.8;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }

            .logo {
                width: 60px;
                height: 60px;
            }

            .system-title {
                font-size: 20px;
            }

            h1 {
                font-size: 24px;
            }

            button {
                padding: 12px;
            }
        }

        /* Dark Mode (Optional) */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #2c3e50, #34495e);
            }

            .login-container {
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                color: var(--light);
            }

            .system-title, h1 {
                color: var(--light);
            }

            .input-group label {
                color: var(--light);
            }

            .input-group input {
                background: rgba(255, 255, 255, 0.1);
                border-color: rgba(255, 255, 255, 0.3);
                color: var(--light);
            }

            .input-group input:focus {
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.3);
            }

            .input-group .icon, .input-group .toggle-password {
                color: var(--light);
            }

            footer {
                color: var(--gray);
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container" role="main" aria-labelledby="login-title">
            <img src="assets/images/water-drop.png" alt="Water Vending Logo" class="logo">
            <div class="system-title">Water Vending Machine</div>
            <h1 id="login-title">Admin Login</h1>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($_GET['error']); ?></span>
                </div>
            <?php endif; ?>
            
            <form action="authenticate.php" method="POST" id="loginForm" aria-label="Admin login form">
                <div class="input-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user icon"></i>
                        <input type="text" id="username" name="username" required aria-required="true" autocomplete="username">
                    </div>
                </div>
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="password" name="password" required aria-required="true" autocomplete="current-password">
                        <i class="fas fa-eye toggle-password" id="togglePassword" role="button" aria-label="Toggle password visibility"></i>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" id="loginButton">
                        <span>Login</span>
                        <span class="spinner"></span>
                    </button>
                    <a href="#" class="forgot-password" aria-label="Forgot password (not implemented)">Forgot Password?</a>
                </div>
            </form>
        </div>
    </div>
    <footer>
        © 2025 Water Vending Machine. All rights reserved.
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const togglePassword = document.getElementById('togglePassword');

            // Real-time validation
            function validateInput(input) {
                if (!input.value.trim()) {
                    input.classList.add('invalid');
                } else {
                    input.classList.remove('invalid');
                }
            }

            usernameInput.addEventListener('input', () => validateInput(usernameInput));
            passwordInput.addEventListener('input', () => validateInput(passwordInput));

            // Show/hide password
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.type === 'password' ? 'text' : 'password';
                passwordInput.type = type;
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
                this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
            });

            // Auto-remove alerts after animation
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    alert.remove();
                }, 3500); // Matches animation duration (3000ms display + 500ms fadeOut)
            });

            // Form submission handling
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const username = usernameInput.value.trim();
                const password = passwordInput.value.trim();

                if (!username || !password) {
                    validateInput(usernameInput);
                    validateInput(passwordInput);
                    const alert = document.createElement('div');
                    alert.className = 'alert error';
                    alert.innerHTML = `
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Please fill in all fields</span>
                    `;
                    loginForm.prepend(alert);
                    setTimeout(() => {
                        alert.remove();
                    }, 3500); // Matches animation duration
                    return;
                }

                loginButton.disabled = true;
                loginButton.classList.add('loading');
                loginForm.submit();
            });

            // Allow Enter key to submit
            loginForm.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !loginButton.disabled) {
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });

            // Focus on username input
            usernameInput.focus();
        });
    </script>
</body>
</html>