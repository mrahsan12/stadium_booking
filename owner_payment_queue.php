<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function redirectWithFlash(string $type, string $message): void
{
    $_SESSION['owner_queue_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: owner_payment_queue.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
    $decisionNote = substr($decisionNote, 0, 255);
    $ownerId = (int) ($_SESSION['user_id'] ?? 0);

    if ($paymentId <= 0 || ($action !== 'approve_payment' && $action !== 'reject_payment')) {
        redirectWithFlash('danger', 'Invalid approval request.');
    }

    try {
        $mysqli->begin_transaction();

        $paymentStmt = $mysqli->prepare(
            "SELECT p.id,
                    p.booking_id,
                    p.amount,
                    p.approval_status,
                    b.status AS booking_status,
                    b.total_amount,
                    b.paid_amount
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             WHERE p.id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $paymentStmt->bind_param('i', $paymentId);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        $paymentRow = $paymentResult ? $paymentResult->fetch_assoc() : null;
        $paymentStmt->close();

        if (!$paymentRow) {
            throw new RuntimeException('Payment record not found.');
        }

        if ((string) $paymentRow['approval_status'] !== 'pending') {
            throw new RuntimeException('This payment is already reviewed.');
        }

        $bookingId = (int) $paymentRow['booking_id'];

        if ($action === 'approve_payment') {
            $notePrefix = 'Owner approved payment.';
            $finalNote = $notePrefix;
            if ($decisionNote !== '') {
                $finalNote .= ' Note: ' . $decisionNote;
            }

            $approveStmt = $mysqli->prepare(
                "UPDATE payments
                 SET approval_status = 'approved',
                     approved_by = ?,
                     approved_at = NOW(),
                     payment_note = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            $approveStmt->bind_param('isi', $ownerId, $finalNote, $paymentId);
            $approveStmt->execute();
            $approveStmt->close();

            $bookingUpdateStmt = $mysqli->prepare(
                "UPDATE bookings
                 SET status = 'confirmed',
                     payment_status = 'paid',
                     paid_amount = GREATEST(paid_amount, total_amount)
                 WHERE id = ?
                 LIMIT 1"
            );
            $bookingUpdateStmt->bind_param('i', $bookingId);
            $bookingUpdateStmt->execute();
            $bookingUpdateStmt->close();

            $mysqli->commit();
            redirectWithFlash('success', 'Payment approved and booking confirmed.');
        }

        $rejectNote = 'Owner rejected payment.';
        if ($decisionNote !== '') {
            $rejectNote .= ' Reason: ' . $decisionNote;
        }

        $rejectStmt = $mysqli->prepare(
            "UPDATE payments
             SET approval_status = 'rejected',
                 approved_by = ?,
                 approved_at = NOW(),
                 payment_note = ?
             WHERE id = ?
             LIMIT 1"
        );
        $rejectStmt->bind_param('isi', $ownerId, $rejectNote, $paymentId);
        $rejectStmt->execute();
        $rejectStmt->close();

        $bookingCancelStmt = $mysqli->prepare(
            "UPDATE bookings
             SET status = 'cancelled',
                 payment_status = 'expired',
                 cancelled_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        $bookingCancelStmt->bind_param('i', $bookingId);
        $bookingCancelStmt->execute();
        $bookingCancelStmt->close();

        $mysqli->commit();
        redirectWithFlash('warning', 'Payment rejected and booking cancelled.');
    } catch (Throwable $e) {
        $mysqli->rollback();
        redirectWithFlash('danger', $e->getMessage());
    }
}

$flash = $_SESSION['owner_queue_flash'] ?? null;
unset($_SESSION['owner_queue_flash']);

$pendingPayments = [];
$pendingStmt = $mysqli->prepare(
    "SELECT p.id,
            p.booking_id,
            p.amount,
            p.payment_method,
            p.payment_type,
            p.transaction_reference,
            p.transfer_slip_path,
            p.payment_note,
            p.payment_date,
            b.booking_date,
            b.status AS booking_status,
            b.payment_status,
            b.total_amount,
            b.paid_amount,
            u.name AS customer_name,
            u.email AS customer_email,
            br.name AS branch_name,
            s.name AS sport_name,
            ts.start_time,
            ts.end_time
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN users u ON u.id = b.user_id
     JOIN branches br ON br.id = b.branch_id
     JOIN sports s ON s.id = b.sport_id
     JOIN time_slots ts ON ts.id = b.slot_id
     WHERE p.approval_status = 'pending'
     ORDER BY p.payment_date ASC, p.id ASC
     LIMIT 500"
);
$pendingStmt->execute();
$pendingResult = $pendingStmt->get_result();
if ($pendingResult) {
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingPayments[] = $row;
    }
}
$pendingStmt->close();

$reviewedPayments = [];
$reviewedStmt = $mysqli->prepare(
    "SELECT p.id,
            p.booking_id,
            p.amount,
            p.approval_status,
            p.approved_at,
            p.payment_date,
            u.name AS customer_name,
            br.name AS branch_name,
            s.name AS sport_name
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN users u ON u.id = b.user_id
     JOIN branches br ON br.id = b.branch_id
     JOIN sports s ON s.id = b.sport_id
     WHERE p.approval_status IN ('approved', 'rejected')
     ORDER BY p.approved_at DESC, p.id DESC
     LIMIT 20"
);
$reviewedStmt->execute();
$reviewedResult = $reviewedStmt->get_result();
if ($reviewedResult) {
    while ($row = $reviewedResult->fetch_assoc()) {
        $reviewedPayments[] = $row;
    }
}
$reviewedStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Payment Queue | ArenaHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell owner-shell owner-theme-bookings">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="admin_dashboard.php">
                <span class="owner-brand-mark"><i class="bi bi-check2-square"></i></span>
                <span>Payment Approval Queue</span>
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
                <span class="section-kicker">Approvals</span>
                <h1 class="h4 fw-bold mb-1">Pending Payment Reviews</h1>
                <p class="text-secondary mb-0">Approve to confirm the booking, or reject to cancel it safely.</p>
            </div>

            <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
                <div class="alert alert-<?= h((string) $flash['type']) ?> mb-4"><?= h((string) $flash['message']) ?></div>
            <?php endif; ?>

            <div class="card branch-card owner-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h2 class="h5 fw-bold mb-0">Queue</h2>
                    <span class="badge text-bg-warning">Pending: <?= count($pendingPayments) ?></span>
                </div>

                <?php if (count($pendingPayments) === 0): ?>
                    <div class="alert alert-success mb-0">No pending payment requests right now.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table owner-table">
                            <thead>
                                <tr>
                                    <th>Booking</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Submitted</th>
                                    <th>Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingPayments as $payment): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?= (int) $payment['booking_id'] ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $payment['branch_name']) ?> / <?= h((string) $payment['sport_name']) ?></small><br>
                                            <small class="text-secondary"><?= h(date('d M Y', strtotime((string) $payment['booking_date']))) ?>, <?= h(date('h:i A', strtotime((string) $payment['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $payment['end_time']))) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= h((string) $payment['customer_name']) ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $payment['customer_email']) ?></small>
                                        </td>
                                        <td>
                                            <strong>LKR <?= moneyFormat((float) $payment['amount']) ?></strong><br>
                                            <small class="text-secondary">Reference: <?= h((string) ($payment['transaction_reference'] ?? '-')) ?></small>
                                            <?php if (trim((string) ($payment['transfer_slip_path'] ?? '')) !== ''): ?>
                                                <div><a href="<?= h((string) $payment['transfer_slip_path']) ?>" target="_blank" rel="noopener">View Slip</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h(ucwords(str_replace('_', ' ', (string) $payment['payment_method']))) ?></td>
                                        <td>
                                            <?= h(date('d M Y', strtotime((string) $payment['payment_date']))) ?><br>
                                            <small class="text-secondary"><?= h(date('h:i A', strtotime((string) $payment['payment_date']))) ?></small>
                                        </td>
                                        <td>
                                            <form method="POST" class="mb-2">
                                                <input type="hidden" name="action" value="approve_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                                <input type="text" class="form-control form-control-sm mb-2" name="decision_note" maxlength="255" placeholder="Optional note">
                                                <button type="submit" class="btn btn-sm btn-success w-100">Approve</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Reject this payment and cancel booking?');">
                                                <input type="hidden" name="action" value="reject_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                                <input type="text" class="form-control form-control-sm mb-2" name="decision_note" maxlength="255" placeholder="Reason (optional)">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card branch-card owner-card p-4">
                <h2 class="h5 fw-bold mb-3">Recently Reviewed</h2>
                <?php if (count($reviewedPayments) === 0): ?>
                    <div class="text-secondary">No payments have been reviewed yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table owner-table">
                            <thead>
                                <tr>
                                    <th>Payment</th>
                                    <th>Customer</th>
                                    <th>Branch/Sport</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Reviewed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviewedPayments as $row): ?>
                                    <?php $state = (string) $row['approval_status']; ?>
                                    <tr>
                                        <td>#<?= (int) $row['id'] ?> (Booking #<?= (int) $row['booking_id'] ?>)</td>
                                        <td><?= h((string) $row['customer_name']) ?></td>
                                        <td><?= h((string) $row['branch_name']) ?> / <?= h((string) $row['sport_name']) ?></td>
                                        <td>LKR <?= moneyFormat((float) $row['amount']) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= $state === 'approved' ? 'success' : 'danger' ?>">
                                                <?= h(ucfirst($state)) ?>
                                            </span>
                                        </td>
                                        <td><?= h((string) ($row['approved_at'] !== null ? date('d M Y, h:i A', strtotime((string) $row['approved_at'])) : '-')) ?></td>
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
