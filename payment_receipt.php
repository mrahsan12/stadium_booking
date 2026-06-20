<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('customer');

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function formatSlotWindow(string $startTime, string $endTime): string
{
    $start = strtotime($startTime);
    $end = strtotime($endTime);

    if ($start === false || $end === false) {
        return '-';
    }

    return date('h:i A', $start) . ' - ' . date('h:i A', $end);
}

function formatDateText(string $value, string $format): string
{
    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '-';
    }

    return date($format, $timestamp);
}

function paymentMethodLabel(string $method): string
{
    return $method === 'bank_transfer' ? 'Bank Transfer' : 'Card Payment';
}

function normalizedText(?string $value): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

$userId = (int) $_SESSION['user_id'];
$bookingId = (int) ($_GET['booking_id'] ?? 0);
$paymentId = (int) ($_GET['payment_id'] ?? 0);
$receiptData = null;

if ($bookingId > 0) {
    if ($paymentId > 0) {
        $receiptStmt = $mysqli->prepare('SELECT b.id AS booking_id,
                                                b.branch_id,
                                                b.sport_id,
                                                b.booking_date,
                                                b.status,
                                                b.payment_status,
                                                b.total_amount,
                                                b.paid_amount,
                                                br.name AS branch_name,
                                                br.location AS branch_location,
                                                s.name AS sport_name,
                                                ts.start_time,
                                                ts.end_time,
                                                u.name AS customer_name,
                                                u.email AS customer_email,
                                                p.id AS payment_id,
                                                p.amount,
                                                p.payment_method,
                                                p.payment_type,
                                                p.approval_status,
                                                p.payment_note,
                                                p.transaction_reference,
                                                p.transfer_slip_path,
                                                p.card_holder_name,
                                                p.card_last4,
                                                p.payment_date
                                         FROM bookings b
                                         JOIN payments p ON p.booking_id = b.id
                                         JOIN branches br ON br.id = b.branch_id
                                         JOIN sports s ON s.id = b.sport_id
                                         JOIN time_slots ts ON ts.id = b.slot_id
                                         JOIN users u ON u.id = b.user_id
                                         WHERE b.id = ?
                                           AND b.user_id = ?
                                           AND p.id = ?
                                         LIMIT 1');
        if ($receiptStmt) {
            $receiptStmt->bind_param('iii', $bookingId, $userId, $paymentId);
        }
    } else {
        $receiptStmt = $mysqli->prepare('SELECT b.id AS booking_id,
                                                b.branch_id,
                                                b.sport_id,
                                                b.booking_date,
                                                b.status,
                                                b.payment_status,
                                                b.total_amount,
                                                b.paid_amount,
                                                br.name AS branch_name,
                                                br.location AS branch_location,
                                                s.name AS sport_name,
                                                ts.start_time,
                                                ts.end_time,
                                                u.name AS customer_name,
                                                u.email AS customer_email,
                                                p.id AS payment_id,
                                                p.amount,
                                                p.payment_method,
                                                p.payment_type,
                                                p.approval_status,
                                                p.payment_note,
                                                p.transaction_reference,
                                                p.transfer_slip_path,
                                                p.card_holder_name,
                                                p.card_last4,
                                                p.payment_date
                                         FROM bookings b
                                         JOIN payments p ON p.booking_id = b.id
                                         JOIN branches br ON br.id = b.branch_id
                                         JOIN sports s ON s.id = b.sport_id
                                         JOIN time_slots ts ON ts.id = b.slot_id
                                         JOIN users u ON u.id = b.user_id
                                         WHERE b.id = ?
                                           AND b.user_id = ?
                                         ORDER BY p.payment_date DESC, p.id DESC
                                         LIMIT 1');
        if ($receiptStmt) {
            $receiptStmt->bind_param('ii', $bookingId, $userId);
        }
    }

    if (isset($receiptStmt) && $receiptStmt instanceof mysqli_stmt) {
        $receiptStmt->execute();
        $receiptResult = $receiptStmt->get_result();
        $receiptData = $receiptResult ? $receiptResult->fetch_assoc() : null;
        $receiptStmt->close();
    }
}

$dashboardUrl = 'customer_dashboard.php';
$bookAnotherUrl = 'customer_dashboard.php';

if ($receiptData !== null) {
    $dashboardUrl = 'customer_dashboard.php?' . http_build_query([
        'branch_id' => (int) $receiptData['branch_id'],
        'sport_id' => (int) $receiptData['sport_id'],
        'booking_date' => (string) $receiptData['booking_date'],
        'payment' => 'success',
    ]);

    $bookAnotherUrl = 'customer_dashboard.php?' . http_build_query([
        'branch_id' => (int) $receiptData['branch_id'],
        'sport_id' => (int) $receiptData['sport_id'],
        'booking_date' => (string) $receiptData['booking_date'],
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt | ArenaHub Stadium</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell">
    <nav class="navbar navbar-expand-lg sticky-top no-print">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">ArenaHub Stadium</a>
            <div class="ms-auto d-flex gap-2">
                <a href="<?= h($dashboardUrl) ?>" class="btn btn-outline-secondary">Dashboard</a>
                <a href="logout.php" class="btn btn-main">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5 receipt-shell">
        <div class="container">
            <?php if ($receiptData === null): ?>
                <div class="card branch-card p-4">
                    <h1 class="h4 fw-bold mb-2">Receipt not found</h1>
                    <p class="text-secondary mb-3">The receipt may be unavailable, or this booking may not belong to your account.</p>
                    <a href="customer_dashboard.php" class="btn btn-main no-print">Back to Dashboard</a>
                </div>
            <?php else: ?>
                <?php
                $receiptNumber = 'ARH-' . str_pad((string) $receiptData['booking_id'], 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $receiptData['payment_id'], 4, '0', STR_PAD_LEFT);
                $slotWindow = formatSlotWindow((string) $receiptData['start_time'], (string) $receiptData['end_time']);
                $paymentMethod = paymentMethodLabel((string) $receiptData['payment_method']);
                $approvalStatus = (string) ($receiptData['approval_status'] ?? 'approved');
                $transactionReference = normalizedText($receiptData['transaction_reference']);
                $paymentNote = normalizedText($receiptData['payment_note']);
                $cardHolderName = normalizedText($receiptData['card_holder_name']);
                $cardNumberMasked = trim((string) ($receiptData['card_last4'] ?? '')) !== ''
                    ? 'XXXX XXXX XXXX ' . $receiptData['card_last4']
                    : '-';
                $hasSlipPath = trim((string) ($receiptData['transfer_slip_path'] ?? '')) !== '';
                $approvalLabel = ucfirst($approvalStatus);
                $receiptBadgeVariant = 'approved';
                if ($approvalStatus === 'pending') {
                    $receiptBadgeVariant = 'pending';
                } elseif ($approvalStatus === 'rejected') {
                    $receiptBadgeVariant = 'rejected';
                }
                $receiptBadgeIcon = $approvalStatus === 'approved'
                    ? 'bi-check-circle-fill'
                    : ($approvalStatus === 'rejected' ? 'bi-x-circle-fill' : 'bi-hourglass-split');
                $receiptBadgeText = $approvalStatus === 'approved'
                    ? 'Payment Approved'
                    : ($approvalStatus === 'rejected' ? 'Payment Rejected' : 'Waiting for Owner Approval');
                ?>
                <div class="card branch-card receipt-card p-4 p-lg-5">
                    <div class="receipt-top">
                        <div>
                            <span class="receipt-badge receipt-badge-<?= h($receiptBadgeVariant) ?>">
                                <i class="bi <?= h($receiptBadgeIcon) ?>"></i>
                                <?= h($receiptBadgeText) ?>
                            </span>
                            <h1 class="h3 fw-bold mt-3 mb-2">Booking Payment Receipt</h1>
                            <p class="text-secondary mb-0">Show this receipt on your phone or print it for venue check-in.</p>
                        </div>
                        <div class="text-lg-end">
                            <div class="text-secondary small">Receipt No</div>
                            <div class="receipt-ref"><?= h($receiptNumber) ?></div>
                        </div>
                    </div>

                    <div class="receipt-grid">
                        <div class="receipt-item">
                            <span>Booking Number</span>
                            <strong>#<?= h((string) $receiptData['booking_id']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Payment ID</span>
                            <strong>#<?= h((string) $receiptData['payment_id']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Customer</span>
                            <strong><?= h((string) $receiptData['customer_name']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Email</span>
                            <strong><?= h((string) $receiptData['customer_email']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Branch</span>
                            <strong><?= h((string) $receiptData['branch_name']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Branch Location</span>
                            <strong><?= h((string) $receiptData['branch_location']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Sport</span>
                            <strong><?= h((string) $receiptData['sport_name']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Booking Date</span>
                            <strong><?= h(formatDateText((string) $receiptData['booking_date'], 'd M Y')) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Slot Time</span>
                            <strong><?= h($slotWindow) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Booking Status</span>
                            <strong><?= h(ucfirst((string) $receiptData['status'])) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Payment Status</span>
                            <strong><?= h(ucfirst((string) $receiptData['payment_status'])) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Approval Status</span>
                            <strong><?= h($approvalLabel) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Booking Total</span>
                            <strong>LKR <?= moneyFormat((float) $receiptData['total_amount']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Amount Paid</span>
                            <strong>LKR <?= moneyFormat((float) $receiptData['amount']) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Paid On</span>
                            <strong><?= h(formatDateText((string) $receiptData['payment_date'], 'd M Y, h:i A')) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Payment Method</span>
                            <strong><?= h($paymentMethod) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Transaction Reference</span>
                            <strong><?= h($transactionReference) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Cardholder</span>
                            <strong><?= h($cardHolderName) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Card Number</span>
                            <strong><?= h($cardNumberMasked) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Payment Note</span>
                            <strong><?= h($paymentNote) ?></strong>
                        </div>
                        <div class="receipt-item">
                            <span>Transfer Slip</span>
                            <strong>
                                <?php if ($hasSlipPath): ?>
                                    <a href="<?= h((string) $receiptData['transfer_slip_path']) ?>" target="_blank" rel="noopener">View Uploaded Slip</a>
                                <?php else: ?>
                                    Not uploaded
                                <?php endif; ?>
                            </strong>
                        </div>
                    </div>

                    <div class="receipt-total">
                        <span>Total Paid Amount</span>
                        <strong>LKR <?= moneyFormat((float) $receiptData['amount']) ?></strong>
                    </div>

                    <div class="summary-note mb-4">
                        Keep this receipt for your records. Booking number and receipt number are required for support checks.
                    </div>

                    <div class="receipt-actions no-print">
                        <button type="button" class="btn btn-main" data-action="print-receipt">
                            <i class="bi bi-printer me-1"></i>Print Receipt
                        </button>
                        <a href="<?= h($bookAnotherUrl) ?>" class="btn btn-outline-secondary">Book Another Slot</a>
                        <a href="<?= h($dashboardUrl) ?>" class="btn btn-outline-secondary">Go to Dashboard</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
        const printButton = document.querySelector('[data-action="print-receipt"]');
        if (printButton) {
            printButton.addEventListener('click', function () {
                window.print();
            });
        }
    </script>
</body>
</html>
