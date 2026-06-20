<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireGuest();

$errorMessage = '';
$clientError = '';
$registeredRole = trim((string) ($_GET['role'] ?? 'customer'));
$registeredMessage = '';
if (isset($_GET['registered'])) {
    $registeredMessage = $registeredRole === 'owner'
        ? 'Owner account created successfully. Please login now.'
        : 'Registration successful. Please login now.';
}
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if ($emailValue === '' || $password === '') {
        $errorMessage = 'Invalid email or password.';
    } elseif (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Invalid email format';
    } else {
        $stmt = $mysqli->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $emailValue);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$user) {
            $errorMessage = 'User not found';
        } elseif (!password_verify($password, $user['password'])) {
            $errorMessage = 'Incorrect password';
        } else {
            startUserSession($user);
            recordUserLogin($mysqli, (int) $user['id']);

            if ($rememberMe) {
                createRememberToken($mysqli, (int) $user['id']);
            } else {
                clearRememberCookie();
            }

            header('Location: ' . dashboardRoute($user['role']));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Indoor Stadium Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/auth.css" rel="stylesheet">
</head>
<body class="auth-page">
    <a class="home-link" href="index.php"><i class="bi bi-arrow-left"></i> Back to Home</a>

    <div class="auth-wrapper">
        <div class="glass-card">
            <div class="auth-header">
                <div class="brand-badge"><i class="bi bi-person-lock"></i></div>
                <h1 class="h3 fw-bold mb-2 brand-text">Account Login</h1>
                <p class="auth-muted mb-0">Customer and owner accounts can log in here. Browsing does not require a login.</p>
            </div>
            <div class="auth-body">
                <?php if ($registeredMessage !== ''): ?>
                    <div class="alert alert-success"><?= h($registeredMessage) ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger"><?= h($errorMessage) ?></div>
                <?php endif; ?>

                <?php if ($clientError !== ''): ?>
                    <div class="alert alert-danger"><?= h($clientError) ?></div>
                <?php endif; ?>

                <form method="POST" id="loginForm" novalidate>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" value="<?= h($emailValue) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                            <label class="form-check-label" for="remember_me">Remember me</label>
                        </div>
                        <a href="forgot_password.php" class="inline-link">Forgot Password?</a>
                    </div>
                    <button type="submit" class="btn btn-main w-100 mb-3">Login</button>
                </form>

                <p class="text-center mb-0 auth-muted">
                    Don't have an account?
                    <a href="register.php" class="inline-link">Register</a>
                </p>
                <p class="text-center mt-2 mb-0 auth-muted">
                    Need an owner account?
                    <a href="register.php?type=owner" class="inline-link">Create owner account</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('loginForm');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');

        form.addEventListener('submit', function (event) {
            const email = emailInput.value.trim();
            const password = passwordInput.value.trim();
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            let message = '';

            if (!email || !password) {
                message = 'Email and password are required.';
            } else if (!emailPattern.test(email)) {
                message = 'Please enter a valid email address.';
            }

            if (message) {
                event.preventDefault();
                showClientAlert(message);
            }
        });

        document.querySelectorAll('.toggle-password').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = button.querySelector('i');
                const isHidden = input.type === 'password';

                input.type = isHidden ? 'text' : 'password';
                icon.classList.toggle('bi-eye', !isHidden);
                icon.classList.toggle('bi-eye-slash', isHidden);
                button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        });

        function showClientAlert(message) {
            let alertBox = document.querySelector('.client-alert');
            if (!alertBox) {
                alertBox = document.createElement('div');
                alertBox.className = 'alert alert-danger client-alert';
                form.parentElement.insertBefore(alertBox, form);
            }
            alertBox.textContent = message;
        }
    </script>
</body>
</html>
