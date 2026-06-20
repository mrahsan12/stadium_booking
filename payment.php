<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('customer');

function isValidYmdDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function calculateSlotDurationHours(string $startTime, string $endTime): float
{
    $start = strtotime('1970-01-01 ' . $startTime);
    $end = strtotime('1970-01-01 ' . $endTime);

    if ($start === false || $end === false) {
        return 1.0;
    }

    if ($end <= $start) {
        $end += 24 * 3600;
    }

    $duration = ($end - $start) / 3600;

    return $duration > 0 ? $duration : 1.0;
}

function isPastSlotForDate(string $bookingDate, string $startTime): bool
{
    if ($bookingDate !== date('Y-m-d')) {
        return false;
    }

    $slotStart = strtotime($bookingDate . ' ' . $startTime);

    return $slotStart !== false && $slotStart <= time();
}

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function normalizeCardNumber(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function sanitizeReference(string $value): string
{
    $value = trim($value);
    return preg_replace('/\s+/', '', $value) ?? '';
}

function validatePaymentContext(int $branchId, int $sportId, int $slotId, string $bookingDate): bool
{
    return $branchId > 0 && $sportId > 0 && $slotId > 0 && isValidYmdDate($bookingDate);
}

$errorMessage = '';
$paymentMethod = 'card';

$selectedBranchId = (int) ($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);
$selectedSportId = (int) ($_POST['sport_id'] ?? $_GET['sport_id'] ?? 0);
$selectedSlotId = (int) ($_POST['slot_id'] ?? $_GET['slot_id'] ?? 0);
$selectedDate = trim((string) ($_POST['booking_date'] ?? $_GET['booking_date'] ?? date('Y-m-d')));

if (!isValidYmdDate($selectedDate)) {
    $selectedDate = '';
}

$cardHolderName = trim((string) ($_POST['card_holder_name'] ?? ''));
$cardNumberInput = trim((string) ($_POST['card_number'] ?? ''));
$cardExpiryMonth = trim((string) ($_POST['card_expiry_month'] ?? ''));
$cardExpiryYear = trim((string) ($_POST['card_expiry_year'] ?? ''));
$cardCvv = trim((string) ($_POST['card_cvv'] ?? ''));
$transactionReference = trim((string) ($_POST['transaction_reference'] ?? ''));

$slotData = null;
$totalAmount = 0.00;
$slotBlocked = false;

if (!validatePaymentContext($selectedBranchId, $selectedSportId, $selectedSlotId, $selectedDate)) {
    $errorMessage = 'Invalid booking details. Please choose a slot from the dashboard again.';
} elseif ($selectedDate < date('Y-m-d')) {
    $errorMessage = 'You cannot pay for a past date.';
} else {
    $slotStmt = $mysqli->prepare('SELECT ts.id AS slot_id,
                                         ts.start_time,
                                         ts.end_time,
                                         s.id AS sport_id,
                                         s.name AS sport_name,
                                         s.hourly_rate,
                                         b.id AS branch_id,
                                         b.name AS branch_name,
                                         b.location AS branch_location
                                  FROM time_slots ts
                                  JOIN sports s ON s.id = ts.sport_id
                                  JOIN branches b ON b.id = s.branch_id
                                  WHERE ts.id = ?
                                  LIMIT 1');
    $slotStmt->bind_param('i', $selectedSlotId);
    $slotStmt->execute();
    $slotResult = $slotStmt->get_result();
    $slotData = $slotResult ? $slotResult->fetch_assoc() : null;
    $slotStmt->close();

    if (!$slotData) {
        $errorMessage = 'Selected slot not found.';
    } elseif ((int) $slotData['sport_id'] !== $selectedSportId || (int) $slotData['branch_id'] !== $selectedBranchId) {
        $errorMessage = 'Selected slot does not match the chosen branch or sport.';
        $slotData = null;
    }

    if ($slotData) {
        $hours = calculateSlotDurationHours((string) $slotData['start_time'], (string) $slotData['end_time']);
        $hourlyRate = (float) $slotData['hourly_rate'];
        if ($hourlyRate <= 0) {
            $hourlyRate = 3000.00;
        }
        $totalAmount = round($hours * $hourlyRate, 2);

        if (isPastSlotForDate($selectedDate, (string) $slotData['start_time'])) {
            $slotBlocked = true;
            $errorMessage = 'This time slot has already started or passed. Please choose another available slot.';
        } else {
            $ownerBlockStmt = $mysqli->prepare(
                "SELECT block_scope, reason
                 FROM owner_slot_blocks
                 WHERE block_date = ?
                   AND branch_id = ?
                   AND (block_scope = 'branch_day' OR (block_scope = 'slot' AND slot_id = ?))
                 LIMIT 1"
            );
            $ownerBlockStmt->bind_param('sii', $selectedDate, $selectedBranchId, $selectedSlotId);
            $ownerBlockStmt->execute();
            $ownerBlockResult = $ownerBlockStmt->get_result();
            $ownerBlockRow = $ownerBlockResult ? $ownerBlockResult->fetch_assoc() : null;
            $ownerBlockStmt->close();

            if ($ownerBlockRow) {
                $slotBlocked = true;
                $blockReason = trim((string) ($ownerBlockRow['reason'] ?? 'Blocked by owner'));
                $scope = (string) ($ownerBlockRow['block_scope'] ?? 'slot');
                if ($scope === 'branch_day') {
                    $errorMessage = 'This branch is blocked on the selected date: ' . $blockReason;
                } else {
                    $errorMessage = 'This slot is blocked by the owner: ' . $blockReason;
                }
            }
        }

        if (!$slotBlocked && $errorMessage === '') {
            $blockStmt = $mysqli->prepare("SELECT id
                                           FROM bookings
                                           WHERE sport_id = ?
                                             AND slot_id = ?
                                             AND booking_date = ?
                                             AND status IN ('pending', 'confirmed')
                                             AND (status = 'confirmed' OR payment_due_at IS NULL OR payment_due_at > NOW())
                                           LIMIT 1");
            $blockStmt->bind_param('iis', $selectedSportId, $selectedSlotId, $selectedDate);
            $blockStmt->execute();
            $blockResult = $blockStmt->get_result();
            $slotBlocked = $blockResult && $blockResult->num_rows > 0;
            $blockStmt->close();

            if ($slotBlocked) {
                $errorMessage = 'This slot is already booked. Please choose another available slot.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '' && $slotData !== null && !$slotBlocked) {
    $paymentMethodInput = (string) ($_POST['payment_method'] ?? 'card');
    $paymentMethod = $paymentMethodInput === 'bank_transfer' ? 'bank_transfer' : 'card';

    $dbTransactionRef = null;
    $dbSlipPath = null;
    $dbCardHolderName = null;
    $dbCardLast4 = null;
    $uploadTargetPath = null;

    if ($paymentMethod === 'bank_transfer') {
        $transactionReference = sanitizeReference($transactionReference);
        if ($transactionReference === '') {
            $errorMessage = 'Transaction reference number is required for bank transfer.';
        } elseif (!preg_match('/^[A-Za-z0-9_-]{6,40}$/', $transactionReference)) {
            $errorMessage = 'Transaction reference should be 6-40 characters (letters, numbers, _ or -).';
        }

        if ($errorMessage === '' && isset($_FILES['transfer_slip']) && (int) $_FILES['transfer_slip']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['transfer_slip']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Could not upload transfer slip image. Please try again.';
            } else {
                $tmpFile = (string) $_FILES['transfer_slip']['tmp_name'];
                $fileSize = (int) $_FILES['transfer_slip']['size'];

                if ($fileSize > 5 * 1024 * 1024) {
                    $errorMessage = 'Transfer slip image must be less than 5 MB.';
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo ? finfo_file($finfo, $tmpFile) : false;
                    if ($finfo) {
                        finfo_close($finfo);
                    }

                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        'image/gif' => 'gif',
                    ];

                    if (!$mimeType || !isset($allowed[$mimeType])) {
                        $errorMessage = 'Transfer slip must be an image file (jpg, png, webp, gif).';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/payment_slips';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                            $errorMessage = 'Failed to create upload folder.';
                        } else {
                            $fileName = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];
                            $absoluteTarget = $uploadDir . '/' . $fileName;
                            $relativeTarget = 'uploads/payment_slips/' . $fileName;

                            if (!move_uploaded_file($tmpFile, $absoluteTarget)) {
                                $errorMessage = 'Failed to save uploaded transfer slip.';
                            } else {
                                $uploadTargetPath = $absoluteTarget;
                                $dbSlipPath = $relativeTarget;
                            }
                        }
                    }
                }
            }
        }

        $dbTransactionRef = $transactionReference;
    } else {
        $cardHolderName = trim($cardHolderName);
        $normalizedCard = normalizeCardNumber($cardNumberInput);
        $normalizedCvv = preg_replace('/\D+/', '', $cardCvv) ?? '';
        $monthNumber = (int) $cardExpiryMonth;
        $yearNumber = (int) $cardExpiryYear;
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        if ($cardHolderName === '') {
            $errorMessage = 'Card holder name is required.';
        } elseif (strlen($normalizedCard) < 12 || strlen($normalizedCard) > 19) {
            $errorMessage = 'Enter a valid card number.';
        } elseif ($monthNumber < 1 || $monthNumber > 12) {
            $errorMessage = 'Enter a valid expiry month.';
        } elseif ($yearNumber < $currentYear || $yearNumber > $currentYear + 20) {
            $errorMessage = 'Enter a valid expiry year.';
        } elseif ($yearNumber === $currentYear && $monthNumber < $currentMonth) {
            $errorMessage = 'This card is already expired.';
        } elseif (strlen($normalizedCvv) < 3 || strlen($normalizedCvv) > 4) {
            $errorMessage = 'Enter a valid CVV.';
        } else {
            $dbCardHolderName = $cardHolderName;
            $dbCardLast4 = substr($normalizedCard, -4);
            $dbTransactionRef = 'CARD-' . date('YmdHis') . '-' . random_int(1000, 9999);
        }
    }

    if ($errorMessage === '') {
        try {
            $mysqli->begin_transaction();

            $lockStmt = $mysqli->prepare("SELECT id
                                          FROM bookings
                                          WHERE sport_id = ?
                                            AND slot_id = ?
                                            AND booking_date = ?
                                            AND status IN ('pending', 'confirmed')
                                            AND (status = 'confirmed' OR payment_due_at IS NULL OR payment_due_at > NOW())
                                          LIMIT 1
                                          FOR UPDATE");
            $lockStmt->bind_param('iis', $selectedSportId, $selectedSlotId, $selectedDate);
            $lockStmt->execute();
            $lockResult = $lockStmt->get_result();
            $existingBooking = $lockResult ? $lockResult->fetch_assoc() : null;
            $lockStmt->close();

            if ($existingBooking) {
                throw new RuntimeException('This slot has just been booked by another user. Please choose another slot.');
            }

            $ownerLockStmt = $mysqli->prepare(
                "SELECT block_scope, reason
                 FROM owner_slot_blocks
                 WHERE block_date = ?
                   AND branch_id = ?
                   AND (block_scope = 'branch_day' OR (block_scope = 'slot' AND slot_id = ?))
                 LIMIT 1
                 FOR UPDATE"
            );
            $ownerLockStmt->bind_param('sii', $selectedDate, $selectedBranchId, $selectedSlotId);
            $ownerLockStmt->execute();
            $ownerLockResult = $ownerLockStmt->get_result();
            $ownerLockRow = $ownerLockResult ? $ownerLockResult->fetch_assoc() : null;
            $ownerLockStmt->close();

            if ($ownerLockRow) {
                $reason = trim((string) ($ownerLockRow['reason'] ?? 'Blocked by owner'));
                if ((string) ($ownerLockRow['block_scope'] ?? 'slot') === 'branch_day') {
                    throw new RuntimeException('This branch is blocked on the selected date: ' . $reason);
                }
                throw new RuntimeException('This slot was blocked by the owner: ' . $reason);
            }

            $bookingInsert = $mysqli->prepare('INSERT INTO bookings (
                                                   user_id,
                                                   branch_id,
                                                   sport_id,
                                                   slot_id,
                                                   booking_date,
                                                   payment_due_at,
                                                   advance_expires_at,
                                                   status,
                                                   cancelled_at,
                                                   payment_status,
                                                   payment_plan,
                                                   total_amount,
                                                   paid_amount
                                               ) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, ?, ?, ?, ?)');
            $status = 'pending';
            $paymentStatus = 'paid';
            $paymentPlan = 'full';
            $paidAmount = $totalAmount;
            $userId = (int) $_SESSION['user_id'];
            $bookingInsert->bind_param(
                'iiiissssdd',
                $userId,
                $selectedBranchId,
                $selectedSportId,
                $selectedSlotId,
                $selectedDate,
                $status,
                $paymentStatus,
                $paymentPlan,
                $totalAmount,
                $paidAmount
            );
            $bookingInsert->execute();
            $bookingId = (int) $mysqli->insert_id;
            $bookingInsert->close();

            $paymentInsert = $mysqli->prepare('INSERT INTO payments (
                                                   booking_id,
                                                   amount,
                                                   payment_method,
                                                   payment_type,
                                                   approval_status,
                                                   payment_note,
                                                   transaction_reference,
                                                   transfer_slip_path,
                                                   card_holder_name,
                                                   card_last4
                                               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $paymentType = 'full';
            $approvalStatus = 'pending';
            $paymentNote = $paymentMethod === 'card'
                ? 'Card payment submitted. Waiting for owner approval.'
                : 'Bank transfer submitted. Waiting for owner approval.';
            $paymentInsert->bind_param(
                'idssssssss',
                $bookingId,
                $totalAmount,
                $paymentMethod,
                $paymentType,
                $approvalStatus,
                $paymentNote,
                $dbTransactionRef,
                $dbSlipPath,
                $dbCardHolderName,
                $dbCardLast4
            );
            $paymentInsert->execute();
            $paymentId = (int) $mysqli->insert_id;
            $paymentInsert->close();

            $mysqli->commit();

            $query = http_build_query([
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
            ]);
            header('Location: payment_receipt.php?' . $query);
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($uploadTargetPath !== null && is_file($uploadTargetPath)) {
                @unlink($uploadTargetPath);
            }
            $errorMessage = $e->getMessage();
        }
    }
}

$slotWindow = $slotData
    ? date('h:i A', strtotime((string) $slotData['start_time'])) . ' - ' . date('h:i A', strtotime((string) $slotData['end_time']))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment | ArenaHub Stadium</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell">
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">ArenaHub Stadium</a>
            <div class="ms-auto d-flex gap-2">
                <a href="customer_dashboard.php?branch_id=<?= $selectedBranchId ?>&sport_id=<?= $selectedSportId ?>&booking_date=<?= h($selectedDate) ?>" class="btn btn-outline-secondary">Back</a>
                <a href="logout.php" class="btn btn-main">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5 payment-shell">
        <div class="container">
            <div class="payment-page-header mb-4">
                <span class="section-kicker">Checkout</span>
                <h1 class="h3 fw-bold mb-2">Secure Payment</h1>
                <p class="text-secondary mb-0">Submit payment now. Owner approval will confirm your booking from pending to confirmed.</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger mb-4"><?= h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if ($slotData === null): ?>
                <div class="card branch-card p-4">
                    <p class="mb-3">Booking details not available. Please return to dashboard and pick a slot.</p>
                    <a class="btn btn-main" href="customer_dashboard.php">Go to Dashboard</a>
                </div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data" id="paymentForm">
                    <input type="hidden" name="branch_id" value="<?= $selectedBranchId ?>">
                    <input type="hidden" name="sport_id" value="<?= $selectedSportId ?>">
                    <input type="hidden" name="slot_id" value="<?= $selectedSlotId ?>">
                    <input type="hidden" name="booking_date" value="<?= h($selectedDate) ?>">

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card branch-card p-4">
                                <div class="mb-4">
                                    <span class="section-kicker">Payment Method</span>
                                    <h2 class="h4 fw-bold mb-1">Choose how you want to pay</h2>
                                    <p class="text-secondary mb-0">After payment submission, the booking stays pending until the owner marks it as complete.</p>
                                </div>

                                <div class="method-grid mb-4">
                                    <label class="method-card">
                                        <input class="form-check-input" type="radio" name="payment_method" value="card" <?= $paymentMethod === 'card' ? 'checked' : '' ?>>
                                        <span>
                                            <strong><i class="bi bi-credit-card-2-front me-1"></i>Card Payment</strong><br>
                                            <small class="text-secondary">Visa, MasterCard, or debit card</small>
                                        </span>
                                    </label>
                                    <label class="method-card">
                                        <input class="form-check-input" type="radio" name="payment_method" value="bank_transfer" <?= $paymentMethod === 'bank_transfer' ? 'checked' : '' ?>>
                                        <span>
                                            <strong><i class="bi bi-bank me-1"></i>Bank Transfer</strong><br>
                                            <small class="text-secondary">Reference number required, slip optional.</small>
                                        </span>
                                    </label>
                                </div>

                                <div id="cardFields" class="payment-method-panel">
                                    <h3 class="h6 fw-bold mb-3">Card Details</h3>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Cardholder Name</label>
                                            <input type="text" class="form-control" name="card_holder_name" id="card_holder_name" value="<?= h($cardHolderName) ?>" placeholder="Name on card">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Card Number</label>
                                            <input type="text" class="form-control" name="card_number" id="card_number" inputmode="numeric" maxlength="23" value="<?= h($cardNumberInput) ?>" placeholder="1234 5678 9012 3456">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Expiry Month</label>
                                            <input type="number" class="form-control" name="card_expiry_month" id="card_expiry_month" min="1" max="12" value="<?= h($cardExpiryMonth) ?>" placeholder="MM">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Expiry Year</label>
                                            <input type="number" class="form-control" name="card_expiry_year" id="card_expiry_year" min="<?= date('Y') ?>" max="<?= date('Y') + 20 ?>" value="<?= h($cardExpiryYear) ?>" placeholder="YYYY">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">CVV</label>
                                            <input type="password" class="form-control" name="card_cvv" id="card_cvv" inputmode="numeric" maxlength="4" value="<?= h($cardCvv) ?>" placeholder="123">
                                        </div>
                                    </div>
                                </div>

                                <div id="bankFields" class="payment-method-panel">
                                    <h3 class="h6 fw-bold mb-3">Bank Transfer Details</h3>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Transaction Reference Number <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="transaction_reference" id="transaction_reference" value="<?= h($transactionReference) ?>" placeholder="Example: HNB4587TRX">
                                            <small class="text-secondary">Only letters, numbers, `_` and `-` are allowed.</small>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Bank Transfer Slip (Optional Image)</label>
                                            <input type="file" class="form-control" name="transfer_slip" id="transfer_slip" accept="image/jpeg,image/png,image/webp,image/gif">
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-main w-100 mt-4">Pay Now</button>
                                <p class="small text-secondary mt-2 mb-0">The receipt opens after submission. The owner review status will be visible there.</p>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="card branch-card p-4 payment-summary-card">
                                <div class="mb-4">
                                    <span class="section-kicker">Summary</span>
                                    <h2 class="h4 fw-bold mb-1">Booking Summary</h2>
                                    <p class="text-secondary mb-0">Double-check your branch, date, and slot before submitting payment.</p>
                                </div>

                                <div class="summary-stack mb-4">
                                    <div class="summary-item">
                                        <span>Branch</span>
                                        <strong><?= h((string) $slotData['branch_name']) ?></strong>
                                    </div>
                                    <div class="summary-item">
                                        <span>Location</span>
                                        <strong><?= h((string) $slotData['branch_location']) ?></strong>
                                    </div>
                                    <div class="summary-item">
                                        <span>Sport</span>
                                        <strong><?= h((string) $slotData['sport_name']) ?></strong>
                                    </div>
                                    <div class="summary-item">
                                        <span>Date</span>
                                        <strong><?= h(date('d M Y', strtotime($selectedDate))) ?></strong>
                                    </div>
                                    <div class="summary-item">
                                        <span>Slot</span>
                                        <strong><?= h($slotWindow) ?></strong>
                                    </div>
                                </div>

                                <div class="summary-total">
                                    <span>Total Payable</span>
                                    <strong>LKR <?= moneyFormat($totalAmount) ?></strong>
                                </div>

                                <div class="secure-note">
                                    <i class="bi bi-shield-check me-1"></i> Secure checkout with owner verification before final confirmation.
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <script>
        const methodRadios = document.querySelectorAll('input[name="payment_method"]');
        const cardFields = document.getElementById('cardFields');
        const bankFields = document.getElementById('bankFields');
        const paymentForm = document.getElementById('paymentForm');

        function selectedMethod() {
            for (const radio of methodRadios) {
                if (radio.checked) {
                    return radio.value;
                }
            }
            return 'card';
        }

        function toggleMethodPanels() {
            const method = selectedMethod();
            const showCard = method === 'card';

            if (cardFields) {
                cardFields.style.display = showCard ? 'block' : 'none';
            }
            if (bankFields) {
                bankFields.style.display = showCard ? 'none' : 'block';
            }
        }

        methodRadios.forEach(function (radio) {
            radio.addEventListener('change', toggleMethodPanels);
        });

        toggleMethodPanels();

        if (paymentForm) {
            paymentForm.addEventListener('submit', function (event) {
                const method = selectedMethod();
                const cardHolder = document.getElementById('card_holder_name');
                const cardNumber = document.getElementById('card_number');
                const cardMonth = document.getElementById('card_expiry_month');
                const cardYear = document.getElementById('card_expiry_year');
                const cardCvvInput = document.getElementById('card_cvv');
                const transferRef = document.getElementById('transaction_reference');

                if (method === 'card') {
                    if (!cardHolder.value.trim() || !cardNumber.value.trim() || !cardMonth.value.trim() || !cardYear.value.trim() || !cardCvvInput.value.trim()) {
                        event.preventDefault();
                        alert('Please fill in all card details.');
                        return;
                    }
                }

                if (method === 'bank_transfer') {
                    if (!transferRef.value.trim()) {
                        event.preventDefault();
                        alert('Transaction reference number is required for bank transfer.');
                    }
                }
            });
        }
    </script>
</body>
</html>
