<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function riskLabel(array $row): array
{
    $total = (int) ($row['total_bookings'] ?? 0);
    $confirmed = (int) ($row['confirmed_count'] ?? 0);
    $cancelled = (int) ($row['cancelled_count'] ?? 0);
    $expired = (int) ($row['expired_count'] ?? 0);
    $pending = (int) ($row['pending_count'] ?? 0);

    $cancellationRate = $total > 0 ? ($cancelled / $total) * 100 : 0.0;

    if ($total >= 5 && $cancellationRate >= 45.0) {
        return [
            'label' => 'High Cancellation Risk',
            'class' => 'danger',
            'hint' => 'Frequent cancellations',
        ];
    }

    if ($expired >= 3) {
        return [
            'label' => 'Payment Expiry Risk',
            'class' => 'warning',
            'hint' => 'Multiple expired/pending payments',
        ];
    }

    if ($pending >= 3) {
        return [
            'label' => 'Follow-Up Needed',
            'class' => 'warning',
            'hint' => 'Many pending bookings',
        ];
    }

    if ($confirmed >= 8 && $cancellationRate < 20.0) {
        return [
            'label' => 'Highly Reliable',
            'class' => 'success',
            'hint' => 'Consistent confirmed bookings',
        ];
    }

    return [
        'label' => 'Normal',
        'class' => 'secondary',
        'hint' => 'No major risk signals',
    ];
}

$rows = [];
$stmt = $mysqli->prepare(
    "SELECT u.id,
            u.name,
            u.email,
            COALESCE(COUNT(b.id), 0) AS total_bookings,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed_count,
            COALESCE(SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count,
            COALESCE(SUM(CASE WHEN b.payment_status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_count,
            COALESCE(SUM(b.total_amount), 0) AS lifetime_booking_value,
            MAX(b.created_at) AS last_booking_at
     FROM users u
     LEFT JOIN bookings b ON b.user_id = u.id
     WHERE u.role = 'customer'
     GROUP BY u.id, u.name, u.email
     ORDER BY last_booking_at DESC, total_bookings DESC, u.name ASC
     LIMIT 500"
);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Customer Insights | ArenaHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell owner-shell owner-theme-overview">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="admin_dashboard.php">
                <span class="owner-brand-mark"><i class="bi bi-people-fill"></i></span>
                <span>Customer History & Risk Flags</span>
            </a>
            <div class="ms-auto d-flex gap-2">
                <a href="admin_dashboard.php" class="btn btn-outline-secondary owner-top-btn">Owner Dashboard</a>
                <a href="owner_export_reports.php" class="btn btn-outline-secondary owner-top-btn">Export Reports</a>
                <a href="logout.php" class="btn btn-main owner-top-btn">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5">
        <div class="container">
            <div class="card branch-card owner-card p-4 mb-4">
                <span class="section-kicker">Insights</span>
                <h1 class="h4 fw-bold mb-1">Customer Behavior Snapshot</h1>
                <p class="text-secondary mb-0">Risk flags are calculated automatically from cancellations, pending patterns, and payment expiries.</p>
            </div>

            <div class="card branch-card owner-card p-4">
                <?php if (count($rows) === 0): ?>
                    <div class="alert alert-info mb-0">No customers found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table owner-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Totals</th>
                                    <th>Status Mix</th>
                                    <th>Risk Flag</th>
                                    <th>Last Booking</th>
                                    <th>Lifetime Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $total = (int) $row['total_bookings'];
                                    $confirmed = (int) $row['confirmed_count'];
                                    $pending = (int) $row['pending_count'];
                                    $cancelled = (int) $row['cancelled_count'];
                                    $expired = (int) $row['expired_count'];
                                    $risk = riskLabel($row);
                                    $cancelRate = $total > 0 ? (($cancelled / $total) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= h((string) $row['name']) ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $row['email']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-primary">Total: <?= $total ?></span>
                                        </td>
                                        <td>
                                            <small class="d-block">Confirmed: <?= $confirmed ?></small>
                                            <small class="d-block">Pending: <?= $pending ?></small>
                                            <small class="d-block">Cancelled: <?= $cancelled ?></small>
                                            <small class="d-block">Expired: <?= $expired ?></small>
                                            <small class="text-secondary">Cancellation Rate: <?= h(number_format($cancelRate, 1)) ?>%</small>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= h((string) $risk['class']) ?>"><?= h((string) $risk['label']) ?></span><br>
                                            <small class="text-secondary"><?= h((string) $risk['hint']) ?></small>
                                        </td>
                                        <td>
                                            <?= $row['last_booking_at'] ? h(date('d M Y, h:i A', strtotime((string) $row['last_booking_at']))) : '-' ?>
                                        </td>
                                        <td>LKR <?= moneyFormat((float) $row['lifetime_booking_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</body>
</html>
