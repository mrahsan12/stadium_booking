<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function redirectPolicyPage(string $type, string $message): void
{
    $_SESSION['owner_policy_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: owner_policy_rules.php');
    exit;
}

function bookingStartDateTime(string $bookingDate, string $startTime): ?DateTimeImmutable
{
    $value = $bookingDate . ' ' . $startTime;
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($date instanceof DateTimeImmutable) {
        return $date;
    }

    return DateTimeImmutable::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . substr($startTime, 0, 5)) ?: null;
}

function refundPercentForHours(float $hoursUntilStart, array $policy): float
{
    $fullHours = (int) ($policy['full_refund_before_hours'] ?? 24);
    $partialHours = (int) ($policy['partial_refund_before_hours'] ?? 6);
    $partialPercent = (float) ($policy['partial_refund_percent'] ?? 50.0);

    if ($hoursUntilStart >= $fullHours) {
        return 100.0;
    }

    if ($hoursUntilStart >= $partialHours) {
        return max(0.0, min(100.0, $partialPercent));
    }

    return 0.0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $ownerId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'save_policy') {
        $fullHours = (int) ($_POST['full_refund_before_hours'] ?? 24);
        $partialHours = (int) ($_POST['partial_refund_before_hours'] ?? 6);
        $partialPercent = (float) ($_POST['partial_refund_percent'] ?? 50.0);

        if ($fullHours < 0 || $fullHours > 720) {
            redirectPolicyPage('danger', 'Full refund hours should be between 0 and 720.');
        }
        if ($partialHours < 0 || $partialHours > 720) {
            redirectPolicyPage('danger', 'Partial refund hours should be between 0 and 720.');
        }
        if ($partialHours > $fullHours) {
            redirectPolicyPage('danger', 'Partial refund window cannot be greater than full refund window.');
        }
        if ($partialPercent < 0 || $partialPercent > 100) {
            redirectPolicyPage('danger', 'Partial refund percent should be between 0 and 100.');
        }

        $updateStmt = $mysqli->prepare(
            "UPDATE owner_cancellation_policy
             SET full_refund_before_hours = ?,
                 partial_refund_before_hours = ?,
                 partial_refund_percent = ?
             WHERE id = 1
             LIMIT 1"
        );
        $updateStmt->bind_param('iid', $fullHours, $partialHours, $partialPercent);
        $saved = $updateStmt->execute();
        $updateStmt->close();

        if ($saved) {
            redirectPolicyPage('success', 'Cancellation and refund policy updated.');
        }
        redirectPolicyPage('danger', 'Could not save policy settings.');
    }

    if ($action === 'cancel_booking_policy') {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
        $cancelReason = substr($cancelReason, 0, 255);

        if ($bookingId <= 0) {
            redirectPolicyPage('danger', 'Invalid booking selected for cancellation.');
        }

        try {
            $mysqli->begin_transaction();

            $policyRow = null;
            $policyStmt = $mysqli->prepare(
                "SELECT full_refund_before_hours, partial_refund_before_hours, partial_refund_percent
                 FROM owner_cancellation_policy
                 WHERE id = 1
                 LIMIT 1"
            );
            $policyStmt->execute();
            $policyResult = $policyStmt->get_result();
            $policyRow = $policyResult ? $policyResult->fetch_assoc() : null;
            $policyStmt->close();
            if (!$policyRow) {
                throw new RuntimeException('Cancellation policy is not configured.');
            }

            $bookingStmt = $mysqli->prepare(
                "SELECT b.id,
                        b.status,
                        b.payment_status,
                        b.total_amount,
                        b.paid_amount,
                        b.booking_date,
                        ts.start_time
                 FROM bookings b
                 JOIN time_slots ts ON ts.id = b.slot_id
                 WHERE b.id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $bookingStmt->bind_param('i', $bookingId);
            $bookingStmt->execute();
            $bookingResult = $bookingStmt->get_result();
            $bookingRow = $bookingResult ? $bookingResult->fetch_assoc() : null;
            $bookingStmt->close();

            if (!$bookingRow) {
                throw new RuntimeException('Booking not found.');
            }

            if ((string) $bookingRow['status'] === 'cancelled') {
                throw new RuntimeException('Booking is already cancelled.');
            }

            $startDate = bookingStartDateTime((string) $bookingRow['booking_date'], (string) $bookingRow['start_time']);
            if (!$startDate) {
                throw new RuntimeException('Invalid booking schedule for policy calculation.');
            }

            $now = new DateTimeImmutable('now');
            $secondsUntil = $startDate->getTimestamp() - $now->getTimestamp();
            $hoursUntil = $secondsUntil / 3600;

            $refundPercent = refundPercentForHours($hoursUntil, $policyRow);
            $paidAmount = (float) $bookingRow['paid_amount'];
            $refundAmount = round(($paidAmount * $refundPercent) / 100, 2);
            $remainingPaid = max(0.0, round($paidAmount - $refundAmount, 2));
            $totalAmount = (float) $bookingRow['total_amount'];

            $newPaymentStatus = 'paid';
            if ($remainingPaid <= 0.0) {
                $newPaymentStatus = 'unpaid';
            } elseif ($remainingPaid < $totalAmount) {
                $newPaymentStatus = 'partial';
            }

            $cancelStmt = $mysqli->prepare(
                "UPDATE bookings
                 SET status = 'cancelled',
                     cancelled_at = NOW(),
                     payment_status = ?,
                     paid_amount = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            $cancelStmt->bind_param('sdi', $newPaymentStatus, $remainingPaid, $bookingId);
            $cancelStmt->execute();
            $cancelStmt->close();

            if ($refundAmount > 0.0) {
                $method = 'card';
                $methodStmt = $mysqli->prepare(
                    "SELECT payment_method
                     FROM payments
                     WHERE booking_id = ?
                       AND payment_type <> 'refund'
                     ORDER BY payment_date DESC, id DESC
                     LIMIT 1"
                );
                $methodStmt->bind_param('i', $bookingId);
                $methodStmt->execute();
                $methodResult = $methodStmt->get_result();
                $methodRow = $methodResult ? $methodResult->fetch_assoc() : null;
                $methodStmt->close();
                if ($methodRow && in_array((string) $methodRow['payment_method'], ['card', 'bank_transfer'], true)) {
                    $method = (string) $methodRow['payment_method'];
                }

                $note = 'Refund by owner cancellation policy (' . rtrim(rtrim(number_format($refundPercent, 2), '0'), '.') . '%).';
                if ($cancelReason !== '') {
                    $note .= ' Reason: ' . $cancelReason;
                }
                $reference = 'REFUND-' . date('YmdHis') . '-' . random_int(1000, 9999);

                $refundStmt = $mysqli->prepare(
                    "INSERT INTO payments (
                        booking_id,
                        amount,
                        payment_method,
                        payment_type,
                        approval_status,
                        approved_by,
                        approved_at,
                        payment_note,
                        transaction_reference
                    ) VALUES (?, ?, ?, 'refund', 'approved', ?, NOW(), ?, ?)"
                );
                $refundStmt->bind_param('idsiss', $bookingId, $refundAmount, $method, $ownerId, $note, $reference);
                $refundStmt->execute();
                $refundStmt->close();
            }

            $mysqli->commit();

            $statusMessage = 'Booking cancelled.';
            if ($refundAmount > 0.0) {
                $statusMessage .= ' Refund generated: LKR ' . moneyFormat($refundAmount) . '.';
            } else {
                $statusMessage .= ' No refund based on the policy window.';
            }
            redirectPolicyPage('success', $statusMessage);
        } catch (Throwable $e) {
            $mysqli->rollback();
            redirectPolicyPage('danger', $e->getMessage());
        }
    }

    redirectPolicyPage('danger', 'Unknown action.');
}

$flash = $_SESSION['owner_policy_flash'] ?? null;
unset($_SESSION['owner_policy_flash']);

$policy = [
    'full_refund_before_hours' => 24,
    'partial_refund_before_hours' => 6,
    'partial_refund_percent' => 50.0,
];
$policyStmt = $mysqli->prepare(
    "SELECT full_refund_before_hours, partial_refund_before_hours, partial_refund_percent
     FROM owner_cancellation_policy
     WHERE id = 1
     LIMIT 1"
);
$policyStmt->execute();
$policyResult = $policyStmt->get_result();
$policyRow = $policyResult ? $policyResult->fetch_assoc() : null;
$policyStmt->close();
if ($policyRow) {
    $policy = [
        'full_refund_before_hours' => (int) $policyRow['full_refund_before_hours'],
        'partial_refund_before_hours' => (int) $policyRow['partial_refund_before_hours'],
        'partial_refund_percent' => (float) $policyRow['partial_refund_percent'],
    ];
}

$bookings = [];
$bookingStmt = $mysqli->prepare(
    "SELECT b.id,
            b.booking_date,
            b.status,
            b.total_amount,
            b.paid_amount,
            u.name AS customer_name,
            u.email AS customer_email,
            br.name AS branch_name,
            s.name AS sport_name,
            ts.start_time,
            ts.end_time
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     JOIN branches br ON br.id = b.branch_id
     JOIN sports s ON s.id = b.sport_id
     JOIN time_slots ts ON ts.id = b.slot_id
     WHERE b.status IN ('pending', 'confirmed')
       AND b.booking_date >= CURDATE()
     ORDER BY b.booking_date ASC, ts.start_time ASC
     LIMIT 300"
);
$bookingStmt->execute();
$bookingResult = $bookingStmt->get_result();
if ($bookingResult) {
    while ($row = $bookingResult->fetch_assoc()) {
        $startDate = bookingStartDateTime((string) $row['booking_date'], (string) $row['start_time']);
        $hoursUntil = 0.0;
        if ($startDate instanceof DateTimeImmutable) {
            $hoursUntil = ($startDate->getTimestamp() - time()) / 3600;
        }
        $refundPercent = refundPercentForHours($hoursUntil, $policy);
        $refundAmount = round((((float) $row['paid_amount']) * $refundPercent) / 100, 2);

        $row['hours_until'] = $hoursUntil;
        $row['refund_percent'] = $refundPercent;
        $row['refund_amount'] = $refundAmount;
        $bookings[] = $row;
    }
}
$bookingStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Cancellation & Refund Rules | ArenaHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell owner-shell owner-theme-pos">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="admin_dashboard.php">
                <span class="owner-brand-mark"><i class="bi bi-cash-stack"></i></span>
                <span>Cancellation & Refund Rules</span>
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
            <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
                <div class="alert alert-<?= h((string) $flash['type']) ?> mb-4"><?= h((string) $flash['message']) ?></div>
            <?php endif; ?>

            <div class="card branch-card owner-card p-4 mb-4">
                <span class="section-kicker">Policy Settings</span>
                <h1 class="h4 fw-bold mb-3">Configure Cancellation Windows</h1>
                <form method="POST" class="row g-3 owner-form-shell">
                    <input type="hidden" name="action" value="save_policy">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="full_refund_before_hours">Full refund if cancelled before (hours)</label>
                        <input type="number" min="0" max="720" class="form-control" id="full_refund_before_hours" name="full_refund_before_hours" value="<?= (int) $policy['full_refund_before_hours'] ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="partial_refund_before_hours">Partial refund if cancelled before (hours)</label>
                        <input type="number" min="0" max="720" class="form-control" id="partial_refund_before_hours" name="partial_refund_before_hours" value="<?= (int) $policy['partial_refund_before_hours'] ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="partial_refund_percent">Partial refund %</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control" id="partial_refund_percent" name="partial_refund_percent" value="<?= h(number_format((float) $policy['partial_refund_percent'], 2, '.', '')) ?>" required>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-main">Save Policy</button>
                    </div>
                </form>
            </div>

            <div class="card branch-card owner-card p-4">
                <span class="section-kicker">Apply Policy</span>
                <h2 class="h5 fw-bold mb-3">Upcoming Bookings (Cancellation Policy)</h2>
                <?php if (count($bookings) === 0): ?>
                    <div class="alert alert-info mb-0">No upcoming pending or confirmed bookings found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table owner-table">
                            <thead>
                                <tr>
                                    <th>Booking</th>
                                    <th>Customer</th>
                                    <th>Schedule</th>
                                    <th>Paid</th>
                                    <th>Policy Result</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?= (int) $booking['id'] ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $booking['branch_name']) ?> / <?= h((string) $booking['sport_name']) ?></small><br>
                                            <small class="text-secondary"><?= h(ucfirst((string) $booking['status'])) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= h((string) $booking['customer_name']) ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $booking['customer_email']) ?></small>
                                        </td>
                                        <td>
                                            <?= h(date('d M Y', strtotime((string) $booking['booking_date']))) ?><br>
                                            <small class="text-secondary"><?= h(date('h:i A', strtotime((string) $booking['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $booking['end_time']))) ?></small><br>
                                            <small class="text-secondary">
                                                <?= $booking['hours_until'] >= 0 ? h(number_format((float) $booking['hours_until'], 1)) . ' hours left' : 'Already started or passed' ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="d-block">Paid: LKR <?= moneyFormat((float) $booking['paid_amount']) ?></small>
                                            <small class="d-block">Total: LKR <?= moneyFormat((float) $booking['total_amount']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-info"><?= h(rtrim(rtrim(number_format((float) $booking['refund_percent'], 2), '0'), '.')) ?>% refund</span><br>
                                            <small class="text-secondary">Refund: LKR <?= moneyFormat((float) $booking['refund_amount']) ?></small>
                                        </td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Cancel booking using current policy?');">
                                                <input type="hidden" name="action" value="cancel_booking_policy">
                                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                                <input type="text" class="form-control form-control-sm mb-2" name="cancel_reason" maxlength="255" placeholder="Optional reason">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel and Apply Refund</button>
                                            </form>
                                        </td>
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
