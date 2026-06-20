<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireGuest();

const OWNER_REGISTRATION_CODE = 'ARENAHUB_OWNER_2026';

$errorMessage = '';
$nameValue = '';
$emailValue = '';
$accountType = trim((string) ($_GET['type'] ?? 'customer'));
$accountType = $accountType === 'owner' ? 'owner' : 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameValue = trim($_POST['name'] ?? '');
    $emailValue = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $accountType = trim((string) ($_POST['account_type'] ?? 'customer'));
    $accountType = $accountType === 'owner' ? 'owner' : 'customer';
    $ownerAccessCode = trim((string) ($_POST['owner_access_code'] ?? ''));

    if ($nameValue === '' || $emailValue === '' || $password === '' || $confirmPassword === '') {
        $errorMessage = 'All fields are required.';
    } elseif (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $errorMessage = 'Password must be at least 6 characters long.';
    } elseif ($accountType === 'owner' && !hash_equals(OWNER_REGISTRATION_CODE, $ownerAccessCode)) {
        $errorMessage = 'Invalid owner access code.';
    } else {
        $checkStmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $checkStmt->bind_param('s', $emailValue);
        $checkStmt->execute();
        $existing = $checkStmt->get_result();
        $userExists = $existing && $existing->num_rows > 0;
        $checkStmt->close();

        if ($userExists) {
            $errorMessage = 'Email already exists.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $role = $accountType === 'owner' ? 'admin' : 'customer';
            $insertStmt = $mysqli->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            $insertStmt->bind_param('ssss', $nameValue, $emailValue, $passwordHash, $role);
            $isSaved = $insertStmt->execute();
            $insertStmt->close();

            if ($isSaved) {
                $registeredRole = $role === 'admin' ? 'owner' : 'customer';
                header('Location: login.php?registered=1&role=' . urlencode($registeredRole));
                exit;
            }

            $errorMessage = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Indoor Stadium Booking</title>
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
                <div class="brand-badge"><i class="bi bi-person-plus"></i></div>
                <h1 class="h3 fw-bold mb-2 brand-text">Create Account</h1>
                <p class="auth-muted mb-0">Create a customer or owner account based on your account type.</p>
            </div>
            <div class="auth-body">
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger"><?= h($errorMessage) ?></div>
                <?php endif; ?>

                <form method="POST" id="registerForm" novalidate>
                    <div class="mb-3">
                        <label for="account_type" class="form-label fw-semibold">Account Type</label>
                        <select class="form-select" id="account_type" name="account_type" required>
                            <option value="customer" <?= $accountType === 'customer' ? 'selected' : '' ?>>Customer</option>
                            <option value="owner" <?= $accountType === 'owner' ? 'selected' : '' ?>>Owner</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" id="name" name="name" placeholder="Enter your name" value="<?= h($nameValue) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" value="<?= h($emailValue) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Create password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label fw-semibold">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-4 <?= $accountType === 'owner' ? '' : 'd-none' ?>" id="ownerCodeWrapper">
                        <label for="owner_access_code" class="form-label fw-semibold">Owner access code</label>
                        <input type="password" class="form-control" id="owner_access_code" name="owner_access_code" placeholder="Enter owner access code" <?= $accountType === 'owner' ? 'required' : '' ?>>
                    </div>
                    <button type="submit" class="btn btn-main w-100 mb-3">Register</button>
                </form>
                <p class="text-center mb-0 auth-muted">
                    Already have an account?
                    <a href="login.php" class="inline-link">Login</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        const registerForm = document.getElementById('registerForm');
        const accountTypeSelect = document.getElementById('account_type');
        const regEmail = document.getElementById('email');
        const regPassword = document.getElementById('password');
        const regConfirm = document.getElementById('confirm_password');
        const ownerCodeWrapper = document.getElementById('ownerCodeWrapper');
        const ownerCodeInput = document.getElementById('owner_access_code');

        function syncOwnerCodeField() {
            const isOwner = accountTypeSelect.value === 'owner';
            ownerCodeWrapper.classList.toggle('d-none', !isOwner);
            ownerCodeInput.required = isOwner;

            if (!isOwner) {
                ownerCodeInput.value = '';
            }
        }

        registerForm.addEventListener('submit', function (event) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const email = regEmail.value.trim();
            const password = regPassword.value;
            const confirmPassword = regConfirm.value;
            const isOwner = accountTypeSelect.value === 'owner';
            const ownerCode = ownerCodeInput.value.trim();

            let message = '';
            if (!emailPattern.test(email)) {
                message = 'Valid email address is required.';
            } else if (password.length < 6) {
                message = 'Password must be at least 6 characters long.';
            } else if (password !== confirmPassword) {
                message = 'Passwords must match.';
            } else if (isOwner && !ownerCode) {
                message = 'Owner access code is required.';
            }

            if (message) {
                event.preventDefault();
                showClientAlert(message);
            }
        });

        accountTypeSelect.addEventListener('change', syncOwnerCodeField);

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
                registerForm.parentElement.insertBefore(alertBox, registerForm);
            }
            alertBox.textContent = message;
        }

        syncOwnerCodeField();
    </script>
</body>
</html>
