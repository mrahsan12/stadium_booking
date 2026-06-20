<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$statusMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } else {
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $userId = (int) $user['id'];

            $insertStmt = $mysqli->prepare('INSERT INTO password_resets (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)');
            $insertStmt->bind_param('isss', $userId, $email, $tokenHash, $expiresAt);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $statusMessage = 'If this email exists, password reset instructions were created successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Indoor Stadium Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/auth.css" rel="stylesheet">
</head>
<body class="auth-page">
    <a class="home-link" href="login.php"><i class="bi bi-arrow-left"></i> Back to Login</a>

    <div class="auth-wrapper">
        <div class="glass-card">
            <div class="auth-header">
                <div class="brand-badge"><i class="bi bi-envelope-paper-heart"></i></div>
                <h1 class="h3 fw-bold brand-text">Forgot Password</h1>
                <p class="auth-muted mb-0">Enter your email to generate a password reset request.</p>
            </div>
            <div class="auth-body">
                <?php if ($statusMessage !== ''): ?>
                    <div class="alert alert-success"><?= h($statusMessage) ?></div>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger"><?= h($errorMessage) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required>
                    </div>
                    <button type="submit" class="btn btn-main w-100">Send Reset Request</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
