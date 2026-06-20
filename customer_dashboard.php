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

function formatSlotWindow(string $startTime, string $endTime): string
{
    return date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime));
}

function slotDurationLabel(float $hours): string
{
    $formatted = rtrim(rtrim(number_format($hours, 2), '0'), '.');

    return $formatted . ' hr' . ((float) $formatted === 1.0 ? '' : 's');
}

function isPastSlotForDate(string $bookingDate, string $startTime): bool
{
    if ($bookingDate !== date('Y-m-d')) {
        return false;
    }

    $slotStart = strtotime($bookingDate . ' ' . $startTime);

    return $slotStart !== false && $slotStart <= time();
}

function expireAdvanceBookings(mysqli $mysqli): int
{
    $sql = "UPDATE bookings
            SET status = 'cancelled',
                payment_status = 'expired',
                cancelled_at = NOW()
            WHERE status = 'pending'
              AND payment_status = 'partial'
              AND payment_due_at IS NOT NULL
              AND payment_due_at <= NOW()";

    $mysqli->query($sql);
    $expiredRows = max($mysqli->affected_rows, 0);

    $mysqli->query("UPDATE payment_followups pf
                    JOIN bookings b ON b.id = pf.booking_id
                    SET pf.status = 'cancelled',
                        pf.sent_at = IFNULL(pf.sent_at, NOW())
                    WHERE pf.status = 'pending'
                      AND b.status = 'cancelled'
                      AND b.payment_status = 'expired'");

    return $expiredRows;
}

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

$expiredCount = expireAdvanceBookings($mysqli);
$successMessage = ($_GET['payment'] ?? '') === 'success'
    ? 'Payment successful. Your booking is confirmed.'
    : '';
$errorMessage = '';

$selectedBranchId = (int) ($_GET['branch_id'] ?? 0);
$selectedSportId = (int) ($_GET['sport_id'] ?? 0);
$selectedDate = trim((string) ($_GET['booking_date'] ?? date('Y-m-d')));

if (!isValidYmdDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$branches = [];
$branchResult = $mysqli->query('SELECT id, name, location FROM branches ORDER BY name ASC');
if ($branchResult) {
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'location' => $row['location'] ?? 'Sri Lanka',
        ];
    }
    $branchResult->free();
}

if ($selectedBranchId <= 0 && count($branches) > 0) {
    $selectedBranchId = (int) $branches[0]['id'];
}

$validBranchIds = array_column($branches, 'id');
if ($selectedBranchId > 0 && !in_array($selectedBranchId, $validBranchIds, true)) {
    $selectedBranchId = count($branches) > 0 ? (int) $branches[0]['id'] : 0;
}

$sports = [];
if ($selectedBranchId > 0) {
    $sportStmt = $mysqli->prepare('SELECT id, name, hourly_rate FROM sports WHERE branch_id = ? ORDER BY name ASC');
    $sportStmt->bind_param('i', $selectedBranchId);
    $sportStmt->execute();
    $sportResult = $sportStmt->get_result();

    if ($sportResult) {
        while ($row = $sportResult->fetch_assoc()) {
            $sports[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'hourly_rate' => (float) $row['hourly_rate'],
            ];
        }
    }

    $sportStmt->close();
}

if ($selectedSportId <= 0 && count($sports) > 0) {
    $selectedSportId = (int) $sports[0]['id'];
}

$validSportIds = array_column($sports, 'id');
if ($selectedSportId > 0 && !in_array($selectedSportId, $validSportIds, true)) {
    $selectedSportId = count($sports) > 0 ? (int) $sports[0]['id'] : 0;
}

$currentSport = null;
foreach ($sports as $sport) {
    if ((int) $sport['id'] === $selectedSportId) {
        $currentSport = $sport;
        break;
    }
}

$currentBranch = null;
foreach ($branches as $branch) {
    if ((int) $branch['id'] === $selectedBranchId) {
        $currentBranch = $branch;
        break;
    }
}

$slots = [];
if ($selectedSportId > 0) {
    $slotStmt = $mysqli->prepare('SELECT id, start_time, end_time FROM time_slots WHERE sport_id = ? ORDER BY start_time ASC');
    $slotStmt->bind_param('i', $selectedSportId);
    $slotStmt->execute();
    $slotResult = $slotStmt->get_result();

    if ($slotResult) {
        while ($row = $slotResult->fetch_assoc()) {
            $slots[] = [
                'id' => (int) $row['id'],
                'start_time' => (string) $row['start_time'],
                'end_time' => (string) $row['end_time'],
            ];
        }
    }

    $slotStmt->close();
}

$slotStatusMap = [];
if ($selectedSportId > 0) {
    $bookingStmt = $mysqli->prepare("SELECT slot_id, status, payment_due_at
                                     FROM bookings
                                     WHERE sport_id = ?
                                       AND booking_date = ?
                                       AND status IN ('pending', 'confirmed')");
    $bookingStmt->bind_param('is', $selectedSportId, $selectedDate);
    $bookingStmt->execute();
    $bookingResult = $bookingStmt->get_result();

    if ($bookingResult) {
        while ($row = $bookingResult->fetch_assoc()) {
            $slotId = (int) $row['slot_id'];
            $status = (string) $row['status'];
            $isBlocked = false;
            $label = 'Booked';

            if ($status === 'confirmed') {
                $isBlocked = true;
                $label = 'Already booked';
            } elseif ($status === 'pending') {
                $dueAt = $row['payment_due_at'];
                $isBlocked = $dueAt === null || strtotime((string) $dueAt) > time();
                if ($isBlocked) {
                    $label = 'Advance hold';
                    if ($dueAt !== null) {
                        $label .= ' till ' . date('h:i A', strtotime((string) $dueAt));
                    }
                }
            }

            if ($isBlocked) {
                $slotStatusMap[$slotId] = $label;
            }
        }
    }

    $bookingStmt->close();
}

$branchDayBlockReason = '';
$slotBlockReasons = [];
if ($selectedBranchId > 0 && $selectedSportId > 0) {
    $blockStmt = $mysqli->prepare(
        "SELECT block_scope, slot_id, reason
         FROM owner_slot_blocks
         WHERE block_date = ?
           AND branch_id = ?
           AND (block_scope = 'branch_day' OR (block_scope = 'slot' AND sport_id = ?))"
    );
    $blockStmt->bind_param('sii', $selectedDate, $selectedBranchId, $selectedSportId);
    $blockStmt->execute();
    $blockResult = $blockStmt->get_result();

    if ($blockResult) {
        while ($row = $blockResult->fetch_assoc()) {
            $scope = (string) $row['block_scope'];
            $reason = trim((string) ($row['reason'] ?? 'Blocked by owner'));

            if ($scope === 'branch_day') {
                $branchDayBlockReason = $reason;
                continue;
            }

            $slotId = (int) ($row['slot_id'] ?? 0);
            if ($slotId > 0) {
                $slotBlockReasons[$slotId] = $reason;
            }
        }
    }

    $blockStmt->close();
}

$slotCards = [];
$availableSlotOptions = [];
foreach ($slots as $slot) {
    $slotId = (int) $slot['id'];
    $hourlyRate = $currentSport !== null ? (float) $currentSport['hourly_rate'] : 0;
    if ($hourlyRate <= 0) {
        $hourlyRate = 3000.00;
    }

    $hours = calculateSlotDurationHours($slot['start_time'], $slot['end_time']);
    $totalAmount = round($hourlyRate * $hours, 2);
    $window = formatSlotWindow($slot['start_time'], $slot['end_time']);
    $state = 'available';
    $statusReason = 'Ready to book';

    if ($branchDayBlockReason !== '') {
        $state = 'unavailable';
        $statusReason = 'Branch holiday: ' . $branchDayBlockReason;
    } elseif (isset($slotBlockReasons[$slotId])) {
        $state = 'unavailable';
        $statusReason = 'Blocked by owner: ' . $slotBlockReasons[$slotId];
    } elseif (isset($slotStatusMap[$slotId])) {
        $state = 'unavailable';
        $statusReason = $slotStatusMap[$slotId];
    } elseif (isPastSlotForDate($selectedDate, $slot['start_time'])) {
        $state = 'unavailable';
        $statusReason = 'Time passed for today';
    }

    $slotCard = [
        'slot_id' => $slotId,
        'start_time' => $slot['start_time'],
        'end_time' => $slot['end_time'],
        'window' => $window,
        'total_amount' => $totalAmount,
        'duration_label' => slotDurationLabel($hours),
        'state' => $state,
        'status_reason' => $statusReason,
    ];

    $slotCards[] = $slotCard;

    if ($state === 'available') {
        $availableSlotOptions[] = $slotCard;
    }
}

$availableSlotCount = count($availableSlotOptions);
$unavailableSlotCount = count($slotCards) - $availableSlotCount;
$defaultSelectedSlot = $availableSlotOptions[0] ?? null;

$myBookings = [];
$myBookingStmt = $mysqli->prepare("SELECT b.id,
                                          b.booking_date,
                                          b.status,
                                          b.payment_status,
                                          b.total_amount,
                                          b.paid_amount,
                                          br.name AS branch_name,
                                          s.name AS sport_name,
                                          ts.start_time,
                                          ts.end_time,
                                          p.id AS payment_id
                                   FROM bookings b
                                   JOIN branches br ON br.id = b.branch_id
                                   JOIN sports s ON s.id = b.sport_id
                                   JOIN time_slots ts ON ts.id = b.slot_id
                                   LEFT JOIN payments p ON p.id = (
                                       SELECT p2.id
                                       FROM payments p2
                                       WHERE p2.booking_id = b.id
                                       ORDER BY p2.payment_date DESC, p2.id DESC
                                       LIMIT 1
                                   )
                                   WHERE b.user_id = ?
                                   ORDER BY b.booking_date DESC, ts.start_time DESC
                                   LIMIT 10");
$userId = (int) $_SESSION['user_id'];
$myBookingStmt->bind_param('i', $userId);
$myBookingStmt->execute();
$myBookingResult = $myBookingStmt->get_result();

if ($myBookingResult) {
    while ($row = $myBookingResult->fetch_assoc()) {
        $myBookings[] = $row;
    }
}

$myBookingStmt->close();

$isToday = $selectedDate === date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Booking Dashboard</title>
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
                <a href="branches.php" class="btn btn-outline-secondary">Branches</a>
                <a href="logout.php" class="btn btn-main">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5">
        <div class="container">
            <div class="card dashboard-hero p-4 p-lg-5 mb-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-start gap-4">
                    <div class="dashboard-hero-copy">
                        <span class="section-kicker text-white-50">Live Booking Dashboard</span>
                        <h1 class="h2 fw-bold mb-2">Welcome, <?= h((string) $_SESSION['user_name']) ?></h1>
                        <p class="mb-0">Green slots can be selected directly from the schedule. Red slots are unavailable and cannot be booked.</p>
                    </div>
                    <div class="dashboard-stat-grid">
                        <div class="dashboard-stat">
                            <span>Selected Date</span>
                            <strong><?= h(date('d M Y', strtotime($selectedDate))) ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span>Green Slots</span>
                            <strong><?= $availableSlotCount ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span>Unavailable</span>
                            <strong><?= $unavailableSlotCount ?></strong>
                        </div>
                    </div>
                </div>

                <?php if ($expiredCount > 0): ?>
                    <div class="alert alert-warning mt-4 mb-0">
                        <?= $expiredCount ?> old advance booking(s) expired and got auto-cancelled.
                    </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success mt-4 mb-0"><?= h($successMessage) ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger mt-4 mb-0"><?= h($errorMessage) ?></div>
                <?php endif; ?>
            </div>

            <?php if (count($branches) === 0): ?>
                <div class="alert alert-info">No branches found. Please add branch and sport data to the database.</div>
            <?php else: ?>
                <div class="card branch-card filter-shell p-4 mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <span class="section-kicker">Find Your Session</span>
                            <h2 class="h5 fw-bold mb-1">Branch, sport, and date</h2>
                            <p class="text-secondary mb-0">Change the filters to refresh the live availability board instantly.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="slot-legend slot-legend-booked">Unavailable</span>
                            <span class="slot-legend slot-legend-available">Ready to book</span>
                        </div>
                    </div>

                    <form method="GET" class="row g-3 align-items-end" id="filterForm">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="branch_id">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id" required>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int) $branch['id'] ?>" <?= (int) $branch['id'] === $selectedBranchId ? 'selected' : '' ?>>
                                        <?= h((string) $branch['name']) ?> (<?= h((string) $branch['location']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="sport_id">Sport</label>
                            <select class="form-select" id="sport_id" name="sport_id" required>
                                <?php foreach ($sports as $sport): ?>
                                    <option value="<?= (int) $sport['id'] ?>" <?= (int) $sport['id'] === $selectedSportId ? 'selected' : '' ?>>
                                        <?= h((string) $sport['name']) ?> - LKR <?= moneyFormat((float) $sport['hourly_rate']) ?>/hr
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" for="booking_date">Booking Date</label>
                            <input type="date" class="form-control" id="booking_date" name="booking_date" value="<?= h($selectedDate) ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-1 d-grid">
                            <button type="submit" class="btn btn-main">Go</button>
                        </div>
                    </form>
                </div>

                <form method="GET" action="payment.php" id="goPaymentForm">
                    <input type="hidden" name="branch_id" value="<?= $selectedBranchId ?>">
                    <input type="hidden" name="sport_id" value="<?= $selectedSportId ?>">
                    <input type="hidden" name="booking_date" value="<?= h($selectedDate) ?>">

                    <div class="row g-4">
                        <div class="col-xl-8">
                            <div class="card branch-card schedule-panel p-4 h-100">
                                <div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
                                    <div>
                                        <span class="section-kicker">Choose Your Time</span>
                                        <h2 class="h4 fw-bold mb-1">Time Schedule <?= $isToday ? 'for Today' : 'for ' . h(date('d M Y', strtotime($selectedDate))) ?></h2>
                                        <p class="text-secondary mb-0">Tap a green slot to select it. Red slots are unavailable because they are booked, on hold, or already passed.</p>
                                    </div>
                                    <?php if ($currentSport !== null): ?>
                                        <span class="location-badge"><i class="bi bi-lightning-charge-fill"></i><?= h((string) $currentSport['name']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (count($slotCards) === 0): ?>
                                    <div class="alert alert-info mb-0">No time slots configured for this sport.</div>
                                <?php else: ?>
                                    <div class="schedule-slot-grid">
                                        <?php foreach ($slotCards as $slotCard): ?>
                                            <?php
                                            $isAvailable = $slotCard['state'] === 'available';
                                            $isChecked = $isAvailable && $defaultSelectedSlot !== null && (int) $slotCard['slot_id'] === (int) $defaultSelectedSlot['slot_id'];
                                            ?>
                                            <?php if ($isAvailable): ?>
                                                <label class="schedule-slot-card slot-available-card <?= $isChecked ? 'is-selected' : '' ?>" data-role="slot-card">
                                                    <input
                                                        class="schedule-slot-radio visually-hidden"
                                                        type="radio"
                                                        name="slot_id"
                                                        value="<?= (int) $slotCard['slot_id'] ?>"
                                                        data-window="<?= h((string) $slotCard['window']) ?>"
                                                        data-total="<?= moneyFormat((float) $slotCard['total_amount']) ?>"
                                                        data-duration="<?= h((string) $slotCard['duration_label']) ?>"
                                                        data-status="Ready to book"
                                                        <?= $isChecked ? 'checked' : '' ?>
                                                    >
                                                    <div class="schedule-slot-top">
                                                        <span class="slot-time-pill"><i class="bi bi-clock"></i><?= h((string) $slotCard['window']) ?></span>
                                                        <span class="slot-state-badge slot-state-green">Available</span>
                                                    </div>
                                                    <p class="slot-card-note">This slot is free right now and can be booked immediately.</p>
                                                    <div class="slot-booking-meta">
                                                        <div>
                                                            <div class="slot-card-price">LKR <?= moneyFormat((float) $slotCard['total_amount']) ?></div>
                                                            <small><?= h((string) $slotCard['duration_label']) ?> booking</small>
                                                        </div>
                                                        <div class="slot-card-action"><i class="bi bi-check2-circle"></i>Select slot</div>
                                                    </div>
                                                </label>
                                            <?php else: ?>
                                                <article class="schedule-slot-card slot-unavailable-card" aria-disabled="true">
                                                    <div class="schedule-slot-top">
                                                        <span class="slot-time-pill"><i class="bi bi-clock-history"></i><?= h((string) $slotCard['window']) ?></span>
                                                        <span class="slot-state-badge slot-state-red">Unavailable</span>
                                                    </div>
                                                    <p class="slot-card-note"><?= h((string) $slotCard['status_reason']) ?></p>
                                                    <div class="slot-booking-meta">
                                                        <div>
                                                            <div class="slot-card-price">LKR <?= moneyFormat((float) $slotCard['total_amount']) ?></div>
                                                            <small><?= h((string) $slotCard['duration_label']) ?> booking</small>
                                                        </div>
                                                        <div class="slot-card-action is-blocked"><i class="bi bi-lock-fill"></i>Not selectable</div>
                                                    </div>
                                                </article>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card branch-card summary-panel p-4 h-100">
                                <div class="mb-4">
                                    <span class="section-kicker">Summary</span>
                                    <h2 class="h4 fw-bold mb-1">Booking Snapshot</h2>
                                    <p class="text-secondary mb-0">Your selected slot details update instantly from the schedule board.</p>
                                </div>

                                <?php if ($availableSlotCount === 0): ?>
                                    <div class="summary-empty">
                                        No green slots are available on <?= h(date('d M Y', strtotime($selectedDate))) ?>. Change the date or sport to continue.
                                    </div>
                                <?php else: ?>
                                    <div class="summary-stack mb-4">
                                        <div class="summary-item">
                                            <span>Branch</span>
                                            <strong><?= h((string) ($currentBranch['name'] ?? 'ArenaHub Branch')) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Location</span>
                                            <strong><?= h((string) ($currentBranch['location'] ?? 'Sri Lanka')) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Sport</span>
                                            <strong><?= h((string) ($currentSport['name'] ?? 'Selected Sport')) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Date</span>
                                            <strong><?= h(date('d M Y', strtotime($selectedDate))) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Selected Slot</span>
                                            <strong id="summarySelectedSlot"><?= h((string) ($defaultSelectedSlot['window'] ?? 'Choose a slot')) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Duration</span>
                                            <strong id="summarySelectedDuration"><?= h((string) ($defaultSelectedSlot['duration_label'] ?? '--')) ?></strong>
                                        </div>
                                        <div class="summary-item">
                                            <span>Status</span>
                                            <strong class="summary-status-available" id="summarySelectedState">Ready to book</strong>
                                        </div>
                                    </div>

                                    <div class="summary-total">
                                        <span>Total Payable</span>
                                        <strong id="summarySelectedAmount">LKR <?= moneyFormat((float) ($defaultSelectedSlot['total_amount'] ?? 0)) ?></strong>
                                    </div>

                                    <div class="summary-note mb-4">
                                        <i class="bi bi-info-circle me-2"></i>Green slots are bookable. Red slots stay locked and appear as unavailable.
                                    </div>

                                    <button type="submit" class="btn btn-main w-100" id="continueButton">Continue to Payment</button>
                                    <p class="small text-secondary mt-2 mb-0">Card and bank transfer payment options will open in the next step.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <div class="card branch-card history-panel p-4 mt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <span class="section-kicker">History</span>
                        <h2 class="h4 fw-bold mb-1">My Recent Bookings</h2>
                        <p class="text-secondary mb-0">Quick overview of your latest confirmed and pending reservations.</p>
                    </div>
                </div>

                <?php if (count($myBookings) === 0): ?>
                    <p class="text-secondary mb-0">No bookings yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sport & Slot</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myBookings as $booking): ?>
                                    <?php
                                    $status = (string) $booking['status'];
                                    $bookingId = (int) $booking['id'];
                                    $paymentId = (int) ($booking['payment_id'] ?? 0);
                                    $statusClass = 'secondary';

                                    if ($status === 'confirmed') {
                                        $statusClass = 'success';
                                    } elseif ($status === 'pending') {
                                        $statusClass = 'warning';
                                    } elseif ($status === 'cancelled') {
                                        $statusClass = 'danger';
                                    }

                                    $receiptUrl = $paymentId > 0
                                        ? 'payment_receipt.php?' . http_build_query([
                                            'booking_id' => $bookingId,
                                            'payment_id' => $paymentId,
                                        ])
                                        : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= h(date('d M Y', strtotime((string) $booking['booking_date']))) ?></strong><br>
                                            <small class="text-secondary"><?= h((string) $booking['branch_name']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= h((string) $booking['sport_name']) ?></strong><br>
                                            <small class="text-secondary"><?= h(date('h:i A', strtotime((string) $booking['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $booking['end_time']))) ?></small>
                                        </td>
                                        <td>
                                            <small class="d-block">Paid: LKR <?= moneyFormat((float) $booking['paid_amount']) ?></small>
                                            <small class="d-block">Total: LKR <?= moneyFormat((float) $booking['total_amount']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= h($statusClass) ?>"><?= h(ucfirst($status)) ?></span>
                                            <div><small class="text-secondary"><?= h(ucfirst((string) $booking['payment_status'])) ?></small></div>
                                        </td>
                                        <td>
                                            <?php if ($paymentId > 0): ?>
                                                <a href="<?= h($receiptUrl) ?>" class="btn btn-sm btn-outline-secondary">View Receipt</a>
                                            <?php else: ?>
                                                <small class="text-secondary">Not available</small>
                                            <?php endif; ?>
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

    <script>
        const filterForm = document.getElementById('filterForm');
        const branchSelect = document.getElementById('branch_id');
        const sportSelect = document.getElementById('sport_id');
        const bookingDateInput = document.getElementById('booking_date');

        if (branchSelect && filterForm) {
            branchSelect.addEventListener('change', function () {
                filterForm.submit();
            });
        }

        if (sportSelect && filterForm) {
            sportSelect.addEventListener('change', function () {
                filterForm.submit();
            });
        }

        if (bookingDateInput && filterForm) {
            bookingDateInput.addEventListener('change', function () {
                filterForm.submit();
            });
        }

        const goPaymentForm = document.getElementById('goPaymentForm');
        const slotRadios = document.querySelectorAll('input[name="slot_id"]');
        const slotCards = document.querySelectorAll('[data-role="slot-card"]');
        const summarySelectedSlot = document.getElementById('summarySelectedSlot');
        const summarySelectedDuration = document.getElementById('summarySelectedDuration');
        const summarySelectedAmount = document.getElementById('summarySelectedAmount');
        const summarySelectedState = document.getElementById('summarySelectedState');
        const continueButton = document.getElementById('continueButton');

        function getSelectedSlot() {
            for (const radio of slotRadios) {
                if (radio.checked) {
                    return radio;
                }
            }
            return null;
        }

        function syncSelectedCard() {
            slotCards.forEach(function (card) {
                const radio = card.querySelector('input[name="slot_id"]');
                card.classList.toggle('is-selected', !!radio && radio.checked);
            });
        }

        function updateSlotPreview() {
            if (!summarySelectedSlot || !summarySelectedDuration || !summarySelectedAmount || !summarySelectedState) {
                return;
            }

            const selected = getSelectedSlot();
            if (!selected) {
                summarySelectedSlot.textContent = 'Choose a slot';
                summarySelectedDuration.textContent = '--';
                summarySelectedAmount.textContent = 'LKR 0.00';
                summarySelectedState.textContent = 'Waiting for selection';
                if (continueButton) {
                    continueButton.disabled = true;
                }
                syncSelectedCard();
                return;
            }

            summarySelectedSlot.textContent = selected.getAttribute('data-window') || 'Selected slot';
            summarySelectedDuration.textContent = selected.getAttribute('data-duration') || '--';
            summarySelectedAmount.textContent = `LKR ${selected.getAttribute('data-total') || '0.00'}`;
            summarySelectedState.textContent = selected.getAttribute('data-status') || 'Ready to book';
            if (continueButton) {
                continueButton.disabled = false;
            }
            syncSelectedCard();
        }

        slotRadios.forEach(function (radio) {
            radio.addEventListener('change', updateSlotPreview);
        });

        if (goPaymentForm) {
            goPaymentForm.addEventListener('submit', function (event) {
                if (!getSelectedSlot()) {
                    event.preventDefault();
                    alert('Please select an available slot before continuing.');
                }
            });
        }

        updateSlotPreview();
    </script>
</body>
</html>
