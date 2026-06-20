<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function columnExists(mysqli $mysqli, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $safeColumn = $mysqli->real_escape_string($column);
    $result = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function indexExists(mysqli $mysqli, string $table, string $index): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $safeIndex = $mysqli->real_escape_string($index);
    $result = $mysqli->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$safeIndex}'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function ensureAuthSchema(mysqli $mysqli): void
{
    static $schemaChecked = false;

    if ($schemaChecked) {
        return;
    }

    $schemaChecked = true;

    if (!columnExists($mysqli, 'users', 'last_login_at')) {
        $mysqli->query('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER role');
    }

    if (!columnExists($mysqli, 'payments', 'approval_status')) {
        $mysqli->query("ALTER TABLE payments ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER payment_type");
    }

    if (!columnExists($mysqli, 'payments', 'approved_by')) {
        $mysqli->query('ALTER TABLE payments ADD COLUMN approved_by INT DEFAULT NULL AFTER approval_status');
    }

    if (!columnExists($mysqli, 'payments', 'approved_at')) {
        $mysqli->query('ALTER TABLE payments ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by');
    }

    if (!indexExists($mysqli, 'payments', 'idx_payments_approval_status')) {
        $mysqli->query('ALTER TABLE payments ADD INDEX idx_payments_approval_status (approval_status)');
    }

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS owner_expenses (
            id INT NOT NULL AUTO_INCREMENT,
            expense_date DATE NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_owner_expenses_date (expense_date),
            KEY idx_owner_expenses_creator (created_by),
            CONSTRAINT fk_owner_expenses_user
                FOREIGN KEY (created_by) REFERENCES users(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS owner_slot_blocks (
            id INT NOT NULL AUTO_INCREMENT,
            block_date DATE NOT NULL,
            branch_id INT NOT NULL,
            sport_id INT DEFAULT NULL,
            slot_id INT DEFAULT NULL,
            block_scope ENUM('branch_day', 'slot') NOT NULL DEFAULT 'slot',
            reason VARCHAR(150) NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_owner_slot_block_slot_date (slot_id, block_date),
            KEY idx_owner_slot_block_date_branch (block_date, branch_id),
            KEY idx_owner_slot_block_sport (sport_id),
            CONSTRAINT fk_owner_slot_blocks_branch
                FOREIGN KEY (branch_id) REFERENCES branches(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_owner_slot_blocks_sport
                FOREIGN KEY (sport_id) REFERENCES sports(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_owner_slot_blocks_slot
                FOREIGN KEY (slot_id) REFERENCES time_slots(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_owner_slot_blocks_user
                FOREIGN KEY (created_by) REFERENCES users(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS owner_cancellation_policy (
            id TINYINT NOT NULL,
            full_refund_before_hours INT NOT NULL DEFAULT 24,
            partial_refund_before_hours INT NOT NULL DEFAULT 6,
            partial_refund_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $mysqli->query(
        "INSERT INTO owner_cancellation_policy (
            id,
            full_refund_before_hours,
            partial_refund_before_hours,
            partial_refund_percent
        ) VALUES (1, 24, 6, 50.00)
        ON DUPLICATE KEY UPDATE id = id"
    );

    $mysqli->query("UPDATE payments p
                    JOIN bookings b ON b.id = p.booking_id
                    SET p.approval_status = 'approved',
                        p.approved_at = IFNULL(p.approved_at, p.payment_date)
                    WHERE p.approval_status = 'pending'
                      AND b.status = 'confirmed'");
}

function recordUserLogin(mysqli $mysqli, int $userId): void
{
    ensureAuthSchema($mysqli);

    $stmt = $mysqli->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function startUserSession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
}

function dashboardRoute(string $role): string
{
    return $role === 'admin' ? 'admin_dashboard.php' : 'customer_dashboard.php';
}

function clearRememberCookie(): void
{
    setcookie('remember_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function createRememberToken(mysqli $mysqli, int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $deleteStmt = $mysqli->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
    $deleteStmt->bind_param('i', $userId);
    $deleteStmt->execute();
    $deleteStmt->close();

    $insertStmt = $mysqli->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
    $insertStmt->bind_param('isss', $userId, $selector, $tokenHash, $expiresAt);
    $insertStmt->execute();
    $insertStmt->close();

    setcookie('remember_token', $selector . ':' . $validator, [
        'expires' => strtotime($expiresAt),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tryRememberLogin(mysqli $mysqli): void
{
    if (!empty($_SESSION['user_id']) || empty($_COOKIE['remember_token'])) {
        return;
    }

    $parts = explode(':', $_COOKIE['remember_token'], 2);
    if (count($parts) !== 2) {
        clearRememberCookie();
        return;
    }

    [$selector, $validator] = $parts;

    $stmt = $mysqli->prepare('SELECT rt.id AS token_id, rt.user_id, rt.token_hash, rt.expires_at, u.id, u.name, u.role FROM remember_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.selector = ? LIMIT 1');
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $result = $stmt->get_result();
    $tokenRow = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$tokenRow) {
        clearRememberCookie();
        return;
    }

    $isExpired = strtotime($tokenRow['expires_at']) < time();
    $isValid = hash_equals($tokenRow['token_hash'], hash('sha256', $validator));

    if ($isExpired || !$isValid) {
        $deleteStmt = $mysqli->prepare('DELETE FROM remember_tokens WHERE id = ?');
        $tokenId = (int) $tokenRow['token_id'];
        $deleteStmt->bind_param('i', $tokenId);
        $deleteStmt->execute();
        $deleteStmt->close();
        clearRememberCookie();
        return;
    }

    startUserSession([
        'id' => (int) $tokenRow['id'],
        'name' => $tokenRow['name'],
        'role' => $tokenRow['role'],
    ]);
    recordUserLogin($mysqli, (int) $tokenRow['id']);

    createRememberToken($mysqli, (int) $tokenRow['user_id']);
}

function requireGuest(): void
{
    if (!empty($_SESSION['user_id'])) {
        header('Location: ' . dashboardRoute($_SESSION['user_role']));
        exit;
    }
}

function requireLogin(string $requiredRole = 'customer'): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    if ($requiredRole !== '' && ($_SESSION['user_role'] ?? '') !== $requiredRole) {
        header('Location: ' . dashboardRoute($_SESSION['user_role'] ?? 'customer'));
        exit;
    }
}

function logoutUser(mysqli $mysqli): void
{
    if (!empty($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $mysqli->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    clearRememberCookie();
}

ensureAuthSchema($mysqli);
tryRememberLogin($mysqli);
