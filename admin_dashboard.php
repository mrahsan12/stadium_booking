<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function isValidYmdDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidTimeHm(string $value): bool
{
    $time = DateTimeImmutable::createFromFormat('H:i', $value);

    return $time !== false && $time->format('H:i') === $value;
}

function timeToDbValue(string $value): string
{
    return $value . ':00';
}

function timeToInputValue(string $value): string
{
    return substr($value, 0, 5);
}

function moneyFormat(float $amount): string
{
    return number_format($amount, 2);
}

function sanitizeStatusFilter(string $value): string
{
    $allowed = ['all', 'pending', 'confirmed', 'cancelled'];

    return in_array($value, $allowed, true) ? $value : 'all';
}

function sanitizeOwnerSection(string $value): string
{
    $allowed = ['overview', 'sports', 'schedule', 'bookings', 'pos', 'graphs'];

    return in_array($value, $allowed, true) ? $value : 'overview';
}

function buildOwnerSectionUrl(string $section, string $reportDate, int $scheduleSportId, string $bookingStatus): string
{
    $query = http_build_query([
        'section' => $section,
        'report_date' => $reportDate,
        'schedule_sport_id' => $scheduleSportId,
        'booking_status' => $bookingStatus,
    ]);

    return 'admin_dashboard.php?' . $query;
}

function setOwnerFlash(string $type, string $message): void
{
    $_SESSION['owner_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function redirectOwnerDashboard(array $state): void
{
    $query = http_build_query([
        'section' => $state['section'],
        'report_date' => $state['report_date'],
        'schedule_sport_id' => (int) $state['schedule_sport_id'],
        'booking_status' => $state['booking_status'],
    ]);

    header('Location: admin_dashboard.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function branchExists(mysqli $mysqli, int $branchId): bool
{
    $stmt = $mysqli->prepare('SELECT id FROM branches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function sportExists(mysqli $mysqli, int $sportId): bool
{
    $stmt = $mysqli->prepare('SELECT id FROM sports WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $sportId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
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

$reportDate = trim((string) ($_GET['report_date'] ?? date('Y-m-d')));
if (!isValidYmdDate($reportDate)) {
    $reportDate = date('Y-m-d');
}

$scheduleSportId = (int) ($_GET['schedule_sport_id'] ?? 0);
if ($scheduleSportId < 0) {
    $scheduleSportId = 0;
}

$bookingStatus = sanitizeStatusFilter(trim((string) ($_GET['booking_status'] ?? 'all')));
$activeSection = sanitizeOwnerSection(trim((string) ($_GET['section'] ?? 'overview')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stateDate = trim((string) ($_POST['report_date'] ?? $reportDate));
    if (!isValidYmdDate($stateDate)) {
        $stateDate = date('Y-m-d');
    }

    $stateScheduleSportId = (int) ($_POST['schedule_sport_id'] ?? $scheduleSportId);
    if ($stateScheduleSportId < 0) {
        $stateScheduleSportId = 0;
    }

    $stateBookingStatus = sanitizeStatusFilter(trim((string) ($_POST['booking_status'] ?? $bookingStatus)));
    $stateSection = sanitizeOwnerSection(trim((string) ($_POST['section'] ?? $activeSection)));
    $state = [
        'section' => $stateSection,
        'report_date' => $stateDate,
        'schedule_sport_id' => $stateScheduleSportId,
        'booking_status' => $stateBookingStatus,
    ];

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_sport') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $sportName = trim((string) ($_POST['sport_name'] ?? ''));
        $sportName = substr($sportName, 0, 100);
        $hourlyRate = (float) ($_POST['hourly_rate'] ?? 0);

        if ($branchId <= 0 || !branchExists($mysqli, $branchId)) {
            setOwnerFlash('danger', 'Please select a valid branch.');
        } elseif ($sportName === '') {
            setOwnerFlash('danger', 'Sport name is required.');
        } elseif ($hourlyRate <= 0) {
            setOwnerFlash('danger', 'Hourly rate should be greater than 0.');
        } else {
            $duplicateStmt = $mysqli->prepare('SELECT id FROM sports WHERE branch_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
            $duplicateStmt->bind_param('is', $branchId, $sportName);
            $duplicateStmt->execute();
            $duplicateResult = $duplicateStmt->get_result();
            $exists = $duplicateResult && $duplicateResult->num_rows > 0;
            $duplicateStmt->close();

            if ($exists) {
                setOwnerFlash('danger', 'This sport already exists in the selected branch.');
            } else {
                $insertStmt = $mysqli->prepare('INSERT INTO sports (branch_id, name, hourly_rate) VALUES (?, ?, ?)');
                $insertStmt->bind_param('isd', $branchId, $sportName, $hourlyRate);
                $saved = $insertStmt->execute();
                $newSportId = (int) $mysqli->insert_id;
                $insertStmt->close();

                if ($saved) {
                    $state['schedule_sport_id'] = $newSportId;
                    setOwnerFlash('success', 'New sport added successfully.');
                } else {
                    setOwnerFlash('danger', 'Could not add sport. Please try again.');
                }
            }
        }

        redirectOwnerDashboard($state);
    }

    if ($action === 'update_sport') {
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $sportName = trim((string) ($_POST['sport_name'] ?? ''));
        $sportName = substr($sportName, 0, 100);
        $hourlyRate = (float) ($_POST['hourly_rate'] ?? 0);

        if ($sportId <= 0 || !sportExists($mysqli, $sportId)) {
            setOwnerFlash('danger', 'Invalid sport selected.');
        } elseif ($branchId <= 0 || !branchExists($mysqli, $branchId)) {
            setOwnerFlash('danger', 'Please select a valid branch.');
        } elseif ($sportName === '') {
            setOwnerFlash('danger', 'Sport name is required.');
        } elseif ($hourlyRate <= 0) {
            setOwnerFlash('danger', 'Hourly rate should be greater than 0.');
        } else {
            $duplicateStmt = $mysqli->prepare('SELECT id FROM sports WHERE branch_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
            $duplicateStmt->bind_param('isi', $branchId, $sportName, $sportId);
            $duplicateStmt->execute();
            $duplicateResult = $duplicateStmt->get_result();
            $exists = $duplicateResult && $duplicateResult->num_rows > 0;
            $duplicateStmt->close();

            if ($exists) {
                setOwnerFlash('danger', 'Another sport with this name already exists in the selected branch.');
            } else {
                $updateStmt = $mysqli->prepare('UPDATE sports SET branch_id = ?, name = ?, hourly_rate = ? WHERE id = ? LIMIT 1');
                $updateStmt->bind_param('isdi', $branchId, $sportName, $hourlyRate, $sportId);
                $saved = $updateStmt->execute();
                $updateStmt->close();

                $state['schedule_sport_id'] = $sportId;
                if ($saved) {
                    setOwnerFlash('success', 'Sport details updated.');
                } else {
                    setOwnerFlash('danger', 'Sport update failed.');
                }
            }
        }

        redirectOwnerDashboard($state);
    }

    if ($action === 'add_slot') {
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));

        if ($sportId <= 0 || !sportExists($mysqli, $sportId)) {
            setOwnerFlash('danger', 'Please select a valid sport for schedule.');
        } elseif (!isValidTimeHm($startTime) || !isValidTimeHm($endTime)) {
            setOwnerFlash('danger', 'Start and end time are required.');
        } else {
            $startTs = strtotime('1970-01-01 ' . $startTime);
            $endTs = strtotime('1970-01-01 ' . $endTime);

            if ($startTs === false || $endTs === false || $endTs <= $startTs) {
                setOwnerFlash('danger', 'End time must be after start time.');
            } else {
                $dbStart = timeToDbValue($startTime);
                $dbEnd = timeToDbValue($endTime);

                $overlapStmt = $mysqli->prepare('SELECT id FROM time_slots WHERE sport_id = ? AND start_time < ? AND end_time > ? LIMIT 1');
                $overlapStmt->bind_param('iss', $sportId, $dbEnd, $dbStart);
                $overlapStmt->execute();
                $overlapResult = $overlapStmt->get_result();
                $overlapExists = $overlapResult && $overlapResult->num_rows > 0;
                $overlapStmt->close();

                if ($overlapExists) {
                    setOwnerFlash('danger', 'This time slot overlaps with an existing slot.');
                } else {
                    $insertStmt = $mysqli->prepare('INSERT INTO time_slots (sport_id, start_time, end_time) VALUES (?, ?, ?)');
                    $insertStmt->bind_param('iss', $sportId, $dbStart, $dbEnd);
                    $saved = $insertStmt->execute();
                    $insertStmt->close();

                    if ($saved) {
                        setOwnerFlash('success', 'New time slot added.');
                    } else {
                        setOwnerFlash('danger', 'Could not add time slot.');
                    }
                }
            }
        }

        $state['schedule_sport_id'] = $sportId > 0 ? $sportId : $state['schedule_sport_id'];
        redirectOwnerDashboard($state);
    }

    if ($action === 'update_slot') {
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));

        if ($slotId <= 0 || $sportId <= 0 || !sportExists($mysqli, $sportId)) {
            setOwnerFlash('danger', 'Invalid slot details.');
            redirectOwnerDashboard($state);
        }

        $slotStmt = $mysqli->prepare('SELECT id FROM time_slots WHERE id = ? AND sport_id = ? LIMIT 1');
        $slotStmt->bind_param('ii', $slotId, $sportId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();
        $slotExists = $slotResult && $slotResult->num_rows > 0;
        $slotStmt->close();

        if (!$slotExists) {
            setOwnerFlash('danger', 'Selected time slot not found.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        $bookingStmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM bookings WHERE slot_id = ?');
        $bookingStmt->bind_param('i', $slotId);
        $bookingStmt->execute();
        $bookingResult = $bookingStmt->get_result();
        $bookingRow = $bookingResult ? $bookingResult->fetch_assoc() : null;
        $bookingStmt->close();
        $bookingCount = (int) ($bookingRow['c'] ?? 0);

        if ($bookingCount > 0) {
            setOwnerFlash('warning', 'This slot has booking history, so editing is locked.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        if (!isValidTimeHm($startTime) || !isValidTimeHm($endTime)) {
            setOwnerFlash('danger', 'Start and end time are required.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        $startTs = strtotime('1970-01-01 ' . $startTime);
        $endTs = strtotime('1970-01-01 ' . $endTime);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            setOwnerFlash('danger', 'End time must be after start time.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        $dbStart = timeToDbValue($startTime);
        $dbEnd = timeToDbValue($endTime);

        $overlapStmt = $mysqli->prepare('SELECT id FROM time_slots WHERE sport_id = ? AND id <> ? AND start_time < ? AND end_time > ? LIMIT 1');
        $overlapStmt->bind_param('iiss', $sportId, $slotId, $dbEnd, $dbStart);
        $overlapStmt->execute();
        $overlapResult = $overlapStmt->get_result();
        $overlapExists = $overlapResult && $overlapResult->num_rows > 0;
        $overlapStmt->close();

        if ($overlapExists) {
            setOwnerFlash('danger', 'This updated time overlaps with another slot.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        $updateStmt = $mysqli->prepare('UPDATE time_slots SET start_time = ?, end_time = ? WHERE id = ? AND sport_id = ? LIMIT 1');
        $updateStmt->bind_param('ssii', $dbStart, $dbEnd, $slotId, $sportId);
        $saved = $updateStmt->execute();
        $updateStmt->close();

        if ($saved) {
            setOwnerFlash('success', 'Time slot updated successfully.');
        } else {
            setOwnerFlash('danger', 'Could not update time slot.');
        }

        $state['schedule_sport_id'] = $sportId;
        redirectOwnerDashboard($state);
    }

    if ($action === 'delete_slot') {
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $sportId = (int) ($_POST['sport_id'] ?? 0);

        if ($slotId <= 0 || $sportId <= 0) {
            setOwnerFlash('danger', 'Invalid slot selected for delete.');
            redirectOwnerDashboard($state);
        }

        $bookingStmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM bookings WHERE slot_id = ?');
        $bookingStmt->bind_param('i', $slotId);
        $bookingStmt->execute();
        $bookingResult = $bookingStmt->get_result();
        $bookingRow = $bookingResult ? $bookingResult->fetch_assoc() : null;
        $bookingStmt->close();
        $bookingCount = (int) ($bookingRow['c'] ?? 0);

        if ($bookingCount > 0) {
            setOwnerFlash('warning', 'Cannot delete slot with booking history.');
            $state['schedule_sport_id'] = $sportId;
            redirectOwnerDashboard($state);
        }

        $deleteStmt = $mysqli->prepare('DELETE FROM time_slots WHERE id = ? AND sport_id = ? LIMIT 1');
        $deleteStmt->bind_param('ii', $slotId, $sportId);
        $deleteStmt->execute();
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($affected > 0) {
            setOwnerFlash('success', 'Time slot deleted.');
        } else {
            setOwnerFlash('danger', 'Time slot delete failed.');
        }

        $state['schedule_sport_id'] = $sportId;
        redirectOwnerDashboard($state);
    }

    if ($action === 'add_expense') {
        $expenseDate = trim((string) ($_POST['expense_date'] ?? $state['report_date']));
        $category = trim((string) ($_POST['expense_category'] ?? ''));
        $description = trim((string) ($_POST['expense_description'] ?? ''));
        $amount = (float) ($_POST['expense_amount'] ?? 0);

        $category = substr($category, 0, 80);
        $description = substr($description, 0, 255);

        if (!isValidYmdDate($expenseDate)) {
            setOwnerFlash('danger', 'Please select a valid expense date.');
        } elseif ($category === '') {
            setOwnerFlash('danger', 'Expense category is required.');
        } elseif ($amount <= 0) {
            setOwnerFlash('danger', 'Expense amount should be greater than 0.');
        } else {
            $ownerId = (int) $_SESSION['user_id'];
            $descriptionValue = $description === '' ? null : $description;

            $insertStmt = $mysqli->prepare('INSERT INTO owner_expenses (expense_date, category, description, amount, created_by) VALUES (?, ?, ?, ?, ?)');
            $insertStmt->bind_param('sssdi', $expenseDate, $category, $descriptionValue, $amount, $ownerId);
            $saved = $insertStmt->execute();
            $insertStmt->close();

            if ($saved) {
                setOwnerFlash('success', 'Expense entry added successfully.');
            } else {
                setOwnerFlash('danger', 'Could not add expense entry.');
            }
        }

        $state['report_date'] = isValidYmdDate($expenseDate) ? $expenseDate : $state['report_date'];
        redirectOwnerDashboard($state);
    }

    if ($action === 'delete_expense') {
        $expenseId = (int) ($_POST['expense_id'] ?? 0);

        if ($expenseId <= 0) {
            setOwnerFlash('danger', 'Invalid expense selected.');
            redirectOwnerDashboard($state);
        }

        $deleteStmt = $mysqli->prepare('DELETE FROM owner_expenses WHERE id = ? LIMIT 1');
        $deleteStmt->bind_param('i', $expenseId);
        $deleteStmt->execute();
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($affected > 0) {
            setOwnerFlash('success', 'Expense removed.');
        } else {
            setOwnerFlash('danger', 'Expense delete failed.');
        }

        redirectOwnerDashboard($state);
    }

    setOwnerFlash('danger', 'Unknown action.');
    redirectOwnerDashboard($state);
}

$flash = $_SESSION['owner_flash'] ?? null;
unset($_SESSION['owner_flash']);

$branches = [];
$branchResult = $mysqli->query('SELECT id, name, location FROM branches ORDER BY name ASC');
if ($branchResult) {
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'location' => (string) ($row['location'] ?? 'Sri Lanka'),
        ];
    }
    $branchResult->free();
}

$sports = [];
$sportsResult = $mysqli->query(
    "SELECT s.id,
            s.branch_id,
            s.name,
            s.hourly_rate,
            b.name AS branch_name,
            COUNT(ts.id) AS slot_count
     FROM sports s
     JOIN branches b ON b.id = s.branch_id
     LEFT JOIN time_slots ts ON ts.sport_id = s.id
     GROUP BY s.id, s.branch_id, s.name, s.hourly_rate, b.name
     ORDER BY b.name ASC, s.name ASC"
);
if ($sportsResult) {
    while ($row = $sportsResult->fetch_assoc()) {
        $sports[] = [
            'id' => (int) $row['id'],
            'branch_id' => (int) $row['branch_id'],
            'name' => (string) $row['name'],
            'hourly_rate' => (float) $row['hourly_rate'],
            'branch_name' => (string) $row['branch_name'],
            'slot_count' => (int) $row['slot_count'],
        ];
    }
    $sportsResult->free();
}

$validSportIds = array_map(
    static fn(array $sport): int => (int) $sport['id'],
    $sports
);

if ($scheduleSportId <= 0 && count($validSportIds) > 0) {
    $scheduleSportId = (int) $validSportIds[0];
}

if ($scheduleSportId > 0 && !in_array($scheduleSportId, $validSportIds, true)) {
    $scheduleSportId = count($validSportIds) > 0 ? (int) $validSportIds[0] : 0;
}

$selectedScheduleSport = null;
foreach ($sports as $sport) {
    if ((int) $sport['id'] === $scheduleSportId) {
        $selectedScheduleSport = $sport;
        break;
    }
}

$scheduleSlots = [];
if ($scheduleSportId > 0) {
    $slotStmt = $mysqli->prepare(
        "SELECT ts.id,
                ts.start_time,
                ts.end_time,
                COUNT(b.id) AS booking_count
         FROM time_slots ts
         LEFT JOIN bookings b ON b.slot_id = ts.id
         WHERE ts.sport_id = ?
         GROUP BY ts.id, ts.start_time, ts.end_time
         ORDER BY ts.start_time ASC"
    );
    $slotStmt->bind_param('i', $scheduleSportId);
    $slotStmt->execute();
    $slotResult = $slotStmt->get_result();

    if ($slotResult) {
        while ($row = $slotResult->fetch_assoc()) {
            $scheduleSlots[] = [
                'id' => (int) $row['id'],
                'start_time' => (string) $row['start_time'],
                'end_time' => (string) $row['end_time'],
                'booking_count' => (int) $row['booking_count'],
            ];
        }
    }

    $slotStmt->close();
}

$bookingSummary = [
    'total' => 0,
    'confirmed_count' => 0,
    'pending_count' => 0,
    'cancelled_count' => 0,
];

$summaryStmt = $mysqli->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count
     FROM bookings
     WHERE booking_date = ?"
);
$summaryStmt->bind_param('s', $reportDate);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result();
$summaryRow = $summaryResult ? $summaryResult->fetch_assoc() : null;
$summaryStmt->close();

if ($summaryRow) {
    $bookingSummary = [
        'total' => (int) ($summaryRow['total'] ?? 0),
        'confirmed_count' => (int) ($summaryRow['confirmed_count'] ?? 0),
        'pending_count' => (int) ($summaryRow['pending_count'] ?? 0),
        'cancelled_count' => (int) ($summaryRow['cancelled_count'] ?? 0),
    ];
}

$bookings = [];
$bookingStmt = $mysqli->prepare(
    "SELECT b.id,
            b.booking_date,
            b.status,
            b.payment_status,
            b.total_amount,
            b.paid_amount,
            b.created_at,
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
     WHERE b.booking_date = ?
       AND (? = 'all' OR b.status = ?)
     ORDER BY ts.start_time ASC, b.created_at DESC
     LIMIT 300"
);
$bookingStmt->bind_param('sss', $reportDate, $bookingStatus, $bookingStatus);
$bookingStmt->execute();
$bookingResult = $bookingStmt->get_result();
if ($bookingResult) {
    while ($row = $bookingResult->fetch_assoc()) {
        $bookings[] = $row;
    }
}
$bookingStmt->close();

$incomeTotal = 0.0;
$incomeStmt = $mysqli->prepare(
    "SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) AS day_income
     FROM payments
     WHERE DATE(payment_date) = ?"
);
$incomeStmt->bind_param('s', $reportDate);
$incomeStmt->execute();
$incomeResult = $incomeStmt->get_result();
$incomeRow = $incomeResult ? $incomeResult->fetch_assoc() : null;
$incomeStmt->close();

if ($incomeRow) {
    $incomeTotal = (float) ($incomeRow['day_income'] ?? 0);
}

$paymentBreakdown = [];
$breakdownStmt = $mysqli->prepare(
    "SELECT payment_method,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) AS amount
     FROM payments
     WHERE DATE(payment_date) = ?
     GROUP BY payment_method
     ORDER BY payment_method ASC"
);
$breakdownStmt->bind_param('s', $reportDate);
$breakdownStmt->execute();
$breakdownResult = $breakdownStmt->get_result();
if ($breakdownResult) {
    while ($row = $breakdownResult->fetch_assoc()) {
        $method = (string) $row['payment_method'];
        $label = $method === 'bank_transfer' ? 'Bank Transfer' : 'Card';
        $paymentBreakdown[] = [
            'label' => $label,
            'amount' => (float) $row['amount'],
        ];
    }
}
$breakdownStmt->close();

$expenseTotal = 0.0;
$expenseTotalStmt = $mysqli->prepare('SELECT COALESCE(SUM(amount), 0) AS day_expense FROM owner_expenses WHERE expense_date = ?');
$expenseTotalStmt->bind_param('s', $reportDate);
$expenseTotalStmt->execute();
$expenseTotalResult = $expenseTotalStmt->get_result();
$expenseTotalRow = $expenseTotalResult ? $expenseTotalResult->fetch_assoc() : null;
$expenseTotalStmt->close();

if ($expenseTotalRow) {
    $expenseTotal = (float) ($expenseTotalRow['day_expense'] ?? 0);
}

$expenses = [];
$expenseStmt = $mysqli->prepare(
    "SELECT oe.id,
            oe.expense_date,
            oe.category,
            oe.description,
            oe.amount,
            oe.created_at,
            u.name AS created_by_name
     FROM owner_expenses oe
     LEFT JOIN users u ON u.id = oe.created_by
     WHERE oe.expense_date = ?
     ORDER BY oe.created_at DESC, oe.id DESC"
);
$expenseStmt->bind_param('s', $reportDate);
$expenseStmt->execute();
$expenseResult = $expenseStmt->get_result();
if ($expenseResult) {
    while ($row = $expenseResult->fetch_assoc()) {
        $expenses[] = $row;
    }
}
$expenseStmt->close();

$totalSlotsAcrossSports = 0;
foreach ($sports as $sport) {
    $totalSlotsAcrossSports += (int) $sport['slot_count'];
}

$netIncome = $incomeTotal - $expenseTotal;

$posTransactions = [];
$posStmt = $mysqli->prepare(
    "SELECT p.id,
            p.payment_date,
            p.amount,
            p.payment_method,
            p.payment_type,
            p.transaction_reference,
            b.id AS booking_id,
            u.name AS customer_name,
            br.name AS branch_name,
            s.name AS sport_name
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN users u ON u.id = b.user_id
     JOIN branches br ON br.id = b.branch_id
     JOIN sports s ON s.id = b.sport_id
     WHERE DATE(p.payment_date) = ?
     ORDER BY p.payment_date DESC, p.id DESC
     LIMIT 300"
);
$posStmt->bind_param('s', $reportDate);
$posStmt->execute();
$posResult = $posStmt->get_result();
if ($posResult) {
    while ($row = $posResult->fetch_assoc()) {
        $posTransactions[] = $row;
    }
}
$posStmt->close();

$graphStartDate = date('Y-m-d', strtotime($reportDate . ' -6 days'));
$graphEndDate = $reportDate;

$incomeByDate = [];
$graphIncomeStmt = $mysqli->prepare(
    "SELECT DATE(payment_date) AS day_key,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) AS amount
     FROM payments
     WHERE DATE(payment_date) BETWEEN ? AND ?
     GROUP BY DATE(payment_date)"
);
$graphIncomeStmt->bind_param('ss', $graphStartDate, $graphEndDate);
$graphIncomeStmt->execute();
$graphIncomeResult = $graphIncomeStmt->get_result();
if ($graphIncomeResult) {
    while ($row = $graphIncomeResult->fetch_assoc()) {
        $incomeByDate[(string) $row['day_key']] = (float) $row['amount'];
    }
}
$graphIncomeStmt->close();

$expenseByDate = [];
$graphExpenseStmt = $mysqli->prepare(
    "SELECT expense_date AS day_key,
            COALESCE(SUM(amount), 0) AS amount
     FROM owner_expenses
     WHERE expense_date BETWEEN ? AND ?
     GROUP BY expense_date"
);
$graphExpenseStmt->bind_param('ss', $graphStartDate, $graphEndDate);
$graphExpenseStmt->execute();
$graphExpenseResult = $graphExpenseStmt->get_result();
if ($graphExpenseResult) {
    while ($row = $graphExpenseResult->fetch_assoc()) {
        $expenseByDate[(string) $row['day_key']] = (float) $row['amount'];
    }
}
$graphExpenseStmt->close();

$bookingsByDate = [];
$graphBookingStmt = $mysqli->prepare(
    "SELECT booking_date AS day_key,
            COUNT(*) AS booking_count
     FROM bookings
     WHERE booking_date BETWEEN ? AND ?
     GROUP BY booking_date"
);
$graphBookingStmt->bind_param('ss', $graphStartDate, $graphEndDate);
$graphBookingStmt->execute();
$graphBookingResult = $graphBookingStmt->get_result();
if ($graphBookingResult) {
    while ($row = $graphBookingResult->fetch_assoc()) {
        $bookingsByDate[(string) $row['day_key']] = (int) $row['booking_count'];
    }
}
$graphBookingStmt->close();

$graphLabels = [];
$graphIncomeSeries = [];
$graphExpenseSeries = [];
$graphBookingSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime($reportDate . ' -' . $i . ' days'));
    $graphLabels[] = date('d M', strtotime($day));
    $graphIncomeSeries[] = isset($incomeByDate[$day]) ? round((float) $incomeByDate[$day], 2) : 0;
    $graphExpenseSeries[] = isset($expenseByDate[$day]) ? round((float) $expenseByDate[$day], 2) : 0;
    $graphBookingSeries[] = isset($bookingsByDate[$day]) ? (int) $bookingsByDate[$day] : 0;
}

$chartLabelsJson = json_encode($graphLabels);
$chartIncomeJson = json_encode($graphIncomeSeries);
$chartExpenseJson = json_encode($graphExpenseSeries);
$chartBookingJson = json_encode($graphBookingSeries);

if ($chartLabelsJson === false) {
    $chartLabelsJson = '[]';
}
if ($chartIncomeJson === false) {
    $chartIncomeJson = '[]';
}
if ($chartExpenseJson === false) {
    $chartExpenseJson = '[]';
}
if ($chartBookingJson === false) {
    $chartBookingJson = '[]';
}

$sectionUrls = [
    'overview' => buildOwnerSectionUrl('overview', $reportDate, $scheduleSportId, $bookingStatus),
    'sports' => buildOwnerSectionUrl('sports', $reportDate, $scheduleSportId, $bookingStatus),
    'schedule' => buildOwnerSectionUrl('schedule', $reportDate, $scheduleSportId, $bookingStatus),
    'bookings' => buildOwnerSectionUrl('bookings', $reportDate, $scheduleSportId, $bookingStatus),
    'pos' => buildOwnerSectionUrl('pos', $reportDate, $scheduleSportId, $bookingStatus),
    'graphs' => buildOwnerSectionUrl('graphs', $reportDate, $scheduleSportId, $bookingStatus),
];

$sectionMeta = [
    'overview' => [
        'title' => 'Dashboard Overview',
        'subtitle' => 'Daily owner summary with quick access to bookings, POS, and charts.',
        'icon' => 'bi-grid-1x2-fill',
    ],
    'sports' => [
        'title' => 'Sports Management',
        'subtitle' => 'Create and update sport details with branch-wise pricing.',
        'icon' => 'bi-dribbble',
    ],
    'schedule' => [
        'title' => 'Time Schedule Management',
        'subtitle' => 'Maintain slot windows and keep the schedule clean for each sport.',
        'icon' => 'bi-clock-history',
    ],
    'bookings' => [
        'title' => 'Booking Details',
        'subtitle' => 'Monitor customer bookings with filters and payment status.',
        'icon' => 'bi-journal-check',
    ],
    'pos' => [
        'title' => 'POS and Expenses',
        'subtitle' => 'Track day collection, transactions, and operating expenses.',
        'icon' => 'bi-cash-coin',
    ],
    'graphs' => [
        'title' => 'Income and Expense Graphs',
        'subtitle' => 'Visualize collection trends and booking volume for better decisions.',
        'icon' => 'bi-bar-chart-line-fill',
    ],
];
$currentSectionMeta = $sectionMeta[$activeSection] ?? $sectionMeta['overview'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard | ArenaHub Stadium</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css?v=owner_20260426_01" rel="stylesheet">
    <style>
        /* Fallback overrides so owner menu/nav changes are always visible even with stale cache */
        body.owner-shell .owner-navbar {
            border-bottom: 1px solid rgba(var(--owner-accent-rgb), 0.28);
            background: linear-gradient(130deg, rgba(255, 255, 255, 0.95), rgba(var(--owner-accent-rgb), 0.09));
            box-shadow: 0 18px 36px rgba(var(--owner-accent-rgb), 0.15);
        }

        body.owner-shell .owner-sidebar {
            border: 1px solid rgba(var(--owner-accent-rgb), 0.24);
            border-radius: 26px;
            padding: 1.1rem !important;
        }

        body.owner-shell .owner-nav-link {
            font-size: 1.05rem;
            padding: 1rem 1.04rem;
            border-radius: 18px;
        }

        body.owner-shell .owner-nav-icon {
            width: 42px;
            height: 42px;
            flex-basis: 42px;
            font-size: 1.12rem;
        }
    </style>
</head>
<body class="app-shell owner-shell owner-theme-<?= h($activeSection) ?>">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="index.php">
                <span class="owner-brand-mark"><i class="bi bi-building-fill-gear"></i></span>
                <span>ArenaHub Owner Panel</span>
            </a>
            <div class="ms-auto d-flex gap-2">
                <a href="branches.php" class="btn btn-outline-secondary owner-top-btn">View Branches</a>
                <a href="logout.php" class="btn btn-main owner-top-btn">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5">
        <div class="container">
            <div class="card dashboard-hero owner-hero p-4 p-lg-5 mb-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-start gap-4">
                    <div class="dashboard-hero-copy">
                        <span class="section-kicker text-white-50">Vertical Owner Dashboard</span>
                        <h1 class="h2 fw-bold mb-2">Welcome, <?= h((string) $_SESSION['user_name']) ?></h1>
                        <p class="mb-0">POS, booking details, sports, schedules, and income/expense graphs are now organized into sections in a vertical menu.</p>
                    </div>
                    <div class="dashboard-stat-grid">
                        <div class="dashboard-stat">
                            <span>Branches</span>
                            <strong><?= count($branches) ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span>Sports</span>
                            <strong><?= count($sports) ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span>Total Slots</span>
                            <strong><?= $totalSlotsAcrossSports ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span>Daily Net</span>
                            <strong>LKR <?= moneyFormat($netIncome) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
                <div class="alert alert-<?= h((string) $flash['type']) ?> mb-4"><?= h((string) $flash['message']) ?></div>
            <?php endif; ?>

            <div class="row g-4 align-items-start">
                <div class="col-xl-4 col-xxl-3">
                    <aside class="card branch-card owner-card owner-sidebar p-3">
                        <div class="owner-sidebar-head mb-2">
                            <p class="owner-sidebar-title mb-1">Owner Menu</p>
                            <p class="owner-sidebar-sub mb-0">Navigate modules quickly</p>
                        </div>
                        <a href="<?= h($sectionUrls['overview']) ?>" class="owner-nav-link nav-overview <?= $activeSection === 'overview' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-grid-1x2-fill"></i></span>
                            <span class="owner-nav-label">Overview</span>
                        </a>
                        <a href="<?= h($sectionUrls['sports']) ?>" class="owner-nav-link nav-sports <?= $activeSection === 'sports' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-dribbble"></i></span>
                            <span class="owner-nav-label">Sports</span>
                        </a>
                        <a href="<?= h($sectionUrls['schedule']) ?>" class="owner-nav-link nav-schedule <?= $activeSection === 'schedule' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-clock-history"></i></span>
                            <span class="owner-nav-label">Time Schedule</span>
                        </a>
                        <a href="<?= h($sectionUrls['bookings']) ?>" class="owner-nav-link nav-bookings <?= $activeSection === 'bookings' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-journal-check"></i></span>
                            <span class="owner-nav-label">Booking Details</span>
                        </a>
                        <a href="<?= h($sectionUrls['pos']) ?>" class="owner-nav-link nav-pos <?= $activeSection === 'pos' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-cash-coin"></i></span>
                            <span class="owner-nav-label">POS & Expenses</span>
                        </a>
                        <a href="<?= h($sectionUrls['graphs']) ?>" class="owner-nav-link nav-graphs <?= $activeSection === 'graphs' ? 'active' : '' ?>">
                            <span class="owner-nav-icon"><i class="bi bi-bar-chart-line-fill"></i></span>
                            <span class="owner-nav-label">Income/Expense Graphs</span>
                        </a>
                        <a href="owner_payment_queue.php" class="owner-nav-link nav-approvals">
                            <span class="owner-nav-icon"><i class="bi bi-check2-square"></i></span>
                            <span class="owner-nav-label">Payment Approvals</span>
                        </a>
                        <a href="owner_block_manager.php" class="owner-nav-link nav-blocks">
                            <span class="owner-nav-icon"><i class="bi bi-calendar-x"></i></span>
                            <span class="owner-nav-label">Slot Block/Holiday</span>
                        </a>
                        <a href="owner_policy_rules.php" class="owner-nav-link nav-policy">
                            <span class="owner-nav-icon"><i class="bi bi-cash-stack"></i></span>
                            <span class="owner-nav-label">Cancellation & Refund Rules</span>
                        </a>
                        <a href="owner_customer_insights.php" class="owner-nav-link nav-customers">
                            <span class="owner-nav-icon"><i class="bi bi-people-fill"></i></span>
                            <span class="owner-nav-label">Customer Risk Flags</span>
                        </a>
                        <a href="owner_export_reports.php" class="owner-nav-link nav-reports">
                            <span class="owner-nav-icon"><i class="bi bi-download"></i></span>
                            <span class="owner-nav-label">Export Reports</span>
                        </a>
                    </aside>
                </div>

                <div class="col-xl-8 col-xxl-9">
                    <div class="card owner-card owner-active-banner p-3 p-md-4 mb-4">
                        <div class="owner-active-icon">
                            <i class="bi <?= h((string) $currentSectionMeta['icon']) ?>"></i>
                        </div>
                        <div>
                            <span class="owner-active-kicker">Current Module</span>
                            <h2 class="h5 fw-bold mb-1"><?= h((string) $currentSectionMeta['title']) ?></h2>
                            <p class="owner-active-sub mb-0"><?= h((string) $currentSectionMeta['subtitle']) ?></p>
                        </div>
                    </div>

                    <div class="owner-content-shell">
                    <?php if ($activeSection === 'overview'): ?>
                        <div class="card branch-card owner-card p-4 mb-4">
                            <span class="section-kicker">Overview</span>
                            <h2 class="h4 fw-bold mb-3">Quick Owner Snapshot</h2>
                            <div class="owner-finance-grid mb-3">
                                <div class="owner-metric">
                                    <span>Bookings (Day)</span>
                                    <strong><?= $bookingSummary['total'] ?></strong>
                                </div>
                                <div class="owner-metric owner-metric-income">
                                    <span>Income (Day)</span>
                                    <strong>LKR <?= moneyFormat($incomeTotal) ?></strong>
                                </div>
                                <div class="owner-metric owner-metric-expense">
                                    <span>Expenses (Day)</span>
                                    <strong>LKR <?= moneyFormat($expenseTotal) ?></strong>
                                </div>
                                <div class="owner-metric owner-metric-net">
                                    <span>Net (Day)</span>
                                    <strong>LKR <?= moneyFormat($netIncome) ?></strong>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?= h($sectionUrls['pos']) ?>" class="btn btn-main btn-sm">Open POS</a>
                                <a href="<?= h($sectionUrls['bookings']) ?>" class="btn btn-outline-secondary btn-sm">Open Bookings</a>
                                <a href="<?= h($sectionUrls['graphs']) ?>" class="btn btn-outline-secondary btn-sm">Open Graphs</a>
                            </div>
                        </div>

                        <div class="card branch-card owner-card p-4">
                            <span class="section-kicker">Recent Bookings</span>
                            <h3 class="h5 fw-bold mb-3">Latest Entries for <?= h(date('d M Y', strtotime($reportDate))) ?></h3>
                            <?php if (count($bookings) === 0): ?>
                                <div class="alert alert-info mb-0">No bookings available for this date.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle booking-table owner-table">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Customer</th>
                                                <th>Sport</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($bookings, 0, 8) as $booking): ?>
                                                <tr>
                                                    <td><?= h(date('h:i A', strtotime((string) $booking['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $booking['end_time']))) ?></td>
                                                    <td><?= h((string) $booking['customer_name']) ?></td>
                                                    <td><?= h((string) $booking['sport_name']) ?> <small class="text-secondary">(<?= h((string) $booking['branch_name']) ?>)</small></td>
                                                    <td><span class="badge text-bg-secondary"><?= h(ucfirst((string) $booking['status'])) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeSection === 'sports'): ?>
                        <div class="card branch-card owner-card p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <span class="section-kicker">Sports</span>
                                    <h2 class="h4 fw-bold mb-1">Add and Update Sports</h2>
                                    <p class="text-secondary mb-0">You can create new sports and update branch and rate details.</p>
                                </div>
                            </div>

                            <?php if (count($branches) === 0): ?>
                                <div class="alert alert-info mb-0">No branches found. Add branches to the database before creating sports.</div>
                            <?php else: ?>
                                <form method="POST" class="row g-3 align-items-end mb-4 owner-form-shell">
                                    <input type="hidden" name="action" value="add_sport">
                                    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                    <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold" for="branch_id_add">Branch</label>
                                        <select class="form-select" id="branch_id_add" name="branch_id" required>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?= (int) $branch['id'] ?>">
                                                    <?= h((string) $branch['name']) ?> (<?= h((string) $branch['location']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold" for="sport_name_add">Sport Name</label>
                                        <input class="form-control" type="text" id="sport_name_add" name="sport_name" placeholder="Ex: Basketball" maxlength="100" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold" for="hourly_rate_add">Hourly Rate (LKR)</label>
                                        <input class="form-control" type="number" min="1" step="0.01" id="hourly_rate_add" name="hourly_rate" value="3000" required>
                                    </div>
                                    <div class="col-md-1 d-grid">
                                        <button type="submit" class="btn btn-main">Add</button>
                                    </div>
                                </form>

                                <?php if (count($sports) === 0): ?>
                                    <div class="alert alert-info mb-0">No sports yet. Add your first sport above.</div>
                                <?php else: ?>
                                    <div class="d-grid gap-3">
                                        <?php foreach ($sports as $sport): ?>
                                            <form method="POST" class="row g-2 align-items-end owner-form-shell">
                                                <input type="hidden" name="action" value="update_sport">
                                                <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                                <input type="hidden" name="sport_id" value="<?= (int) $sport['id'] ?>">
                                                <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                                <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                                <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold mb-1">Sport</label>
                                                    <input class="form-control form-control-sm owner-input-sm" type="text" name="sport_name" maxlength="100" value="<?= h((string) $sport['name']) ?>" required>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label fw-semibold mb-1">Branch</label>
                                                    <select class="form-select form-select-sm owner-input-sm" name="branch_id" required>
                                                        <?php foreach ($branches as $branch): ?>
                                                            <option value="<?= (int) $branch['id'] ?>" <?= (int) $branch['id'] === (int) $sport['branch_id'] ? 'selected' : '' ?>>
                                                                <?= h((string) $branch['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label fw-semibold mb-1">Hourly Rate</label>
                                                    <input class="form-control form-control-sm owner-input-sm" type="number" min="1" step="0.01" name="hourly_rate" value="<?= h(moneyFormat((float) $sport['hourly_rate'])) ?>" required>
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label fw-semibold mb-1">Slots</label>
                                                    <div class="location-badge owner-pill w-100 justify-content-center"><?= (int) $sport['slot_count'] ?></div>
                                                </div>
                                                <div class="col-md-1 d-grid">
                                                    <button type="submit" class="btn btn-sm btn-main">Save</button>
                                                </div>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeSection === 'schedule'): ?>
                        <div class="card branch-card owner-card p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <span class="section-kicker">Time Schedule</span>
                                    <h2 class="h4 fw-bold mb-1">Manage Time Slots</h2>
                                    <p class="text-secondary mb-0">Edit and add slots for each sport. Slots with booking history are locked for safety.</p>
                                </div>
                            </div>

                            <?php if (count($sports) === 0): ?>
                                <div class="alert alert-info mb-0">Add a sport first to manage schedules.</div>
                            <?php else: ?>
                                <form method="GET" class="row g-2 align-items-end mb-3">
                                    <input type="hidden" name="section" value="schedule">
                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                    <div class="col-sm-9">
                                        <label class="form-label fw-semibold" for="schedule_sport_id">Sport</label>
                                        <select class="form-select" id="schedule_sport_id" name="schedule_sport_id">
                                            <?php foreach ($sports as $sport): ?>
                                                <option value="<?= (int) $sport['id'] ?>" <?= (int) $sport['id'] === $scheduleSportId ? 'selected' : '' ?>>
                                                    <?= h((string) $sport['name']) ?> - <?= h((string) $sport['branch_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-3 d-grid">
                                        <button class="btn btn-outline-secondary" type="submit">Load</button>
                                    </div>
                                </form>

                                <?php if ($selectedScheduleSport !== null): ?>
                                    <div class="owner-selected-sport mb-3">
                                        <strong><?= h((string) $selectedScheduleSport['name']) ?></strong>
                                        <span class="text-secondary d-block"><?= h((string) $selectedScheduleSport['branch_name']) ?> | LKR <?= moneyFormat((float) $selectedScheduleSport['hourly_rate']) ?>/hr</span>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" class="row g-2 align-items-end mb-3 owner-form-shell">
                                    <input type="hidden" name="action" value="add_slot">
                                    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                    <input type="hidden" name="sport_id" value="<?= $scheduleSportId ?>">
                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                    <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                    <div class="col-sm-5">
                                        <label class="form-label fw-semibold" for="slot_start_add">Start</label>
                                        <input class="form-control" type="time" id="slot_start_add" name="start_time" required>
                                    </div>
                                    <div class="col-sm-5">
                                        <label class="form-label fw-semibold" for="slot_end_add">End</label>
                                        <input class="form-control" type="time" id="slot_end_add" name="end_time" required>
                                    </div>
                                    <div class="col-sm-2 d-grid">
                                        <button type="submit" class="btn btn-main">Add</button>
                                    </div>
                                </form>

                                <?php if (count($scheduleSlots) === 0): ?>
                                    <div class="alert alert-info mb-0">No slots for this sport yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table align-middle booking-table owner-table">
                                            <thead>
                                                <tr>
                                                    <th>Start</th>
                                                    <th>End</th>
                                                    <th>Usage</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($scheduleSlots as $slot): ?>
                                                    <?php $locked = (int) $slot['booking_count'] > 0; ?>
                                                    <tr>
                                                        <td><strong><?= h(date('h:i A', strtotime((string) $slot['start_time']))) ?></strong></td>
                                                        <td><strong><?= h(date('h:i A', strtotime((string) $slot['end_time']))) ?></strong></td>
                                                        <td>
                                                            <?php if ($locked): ?>
                                                                <span class="slot-legend slot-legend-booked"><?= (int) $slot['booking_count'] ?> bookings</span>
                                                            <?php else: ?>
                                                                <span class="slot-legend slot-legend-available">No bookings</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($locked): ?>
                                                                <span class="text-secondary small">Locked</span>
                                                            <?php else: ?>
                                                                <form method="POST" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                                                    <input type="hidden" name="action" value="update_slot">
                                                                    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                                                    <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                                                                    <input type="hidden" name="sport_id" value="<?= $scheduleSportId ?>">
                                                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                                                    <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                                                    <input class="form-control form-control-sm owner-input-sm owner-time" type="time" name="start_time" value="<?= h(timeToInputValue((string) $slot['start_time'])) ?>" required>
                                                                    <input class="form-control form-control-sm owner-input-sm owner-time" type="time" name="end_time" value="<?= h(timeToInputValue((string) $slot['end_time'])) ?>" required>
                                                                    <button type="submit" class="btn btn-sm btn-main">Save</button>
                                                                </form>
                                                                <form method="POST" onsubmit="return confirm('Delete this slot?');">
                                                                    <input type="hidden" name="action" value="delete_slot">
                                                                    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                                                    <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                                                                    <input type="hidden" name="sport_id" value="<?= $scheduleSportId ?>">
                                                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                                                    <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Delete</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeSection === 'bookings'): ?>
                        <div class="card branch-card owner-card p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <span class="section-kicker">Bookings</span>
                                    <h2 class="h4 fw-bold mb-1">Customer Booking Details</h2>
                                    <p class="text-secondary mb-0">Track daily bookings and status updates from all branches.</p>
                                </div>
                                <div class="owner-status-strip">
                                    <span class="badge text-bg-primary">Total: <?= $bookingSummary['total'] ?></span>
                                    <span class="badge text-bg-success">Confirmed: <?= $bookingSummary['confirmed_count'] ?></span>
                                    <span class="badge text-bg-warning">Pending: <?= $bookingSummary['pending_count'] ?></span>
                                    <span class="badge text-bg-danger">Cancelled: <?= $bookingSummary['cancelled_count'] ?></span>
                                </div>
                            </div>

                            <form method="GET" class="row g-3 align-items-end mb-3">
                                <input type="hidden" name="section" value="bookings">
                                <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" for="report_date_filter">Date</label>
                                    <input class="form-control" type="date" id="report_date_filter" name="report_date" value="<?= h($reportDate) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" for="booking_status_filter">Status</label>
                                    <select class="form-select" id="booking_status_filter" name="booking_status">
                                        <option value="all" <?= $bookingStatus === 'all' ? 'selected' : '' ?>>All</option>
                                        <option value="confirmed" <?= $bookingStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                        <option value="pending" <?= $bookingStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="cancelled" <?= $bookingStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-grid">
                                    <button type="submit" class="btn btn-main">Refresh Bookings</button>
                                </div>
                            </form>

                            <?php if (count($bookings) === 0): ?>
                                <div class="alert alert-info mb-0">No bookings found for <?= h(date('d M Y', strtotime($reportDate))) ?>.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle booking-table owner-table">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Customer</th>
                                                <th>Branch / Sport</th>
                                                <th>Status</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bookings as $booking): ?>
                                                <?php
                                                $status = (string) $booking['status'];
                                                $statusClass = 'secondary';
                                                if ($status === 'confirmed') {
                                                    $statusClass = 'success';
                                                } elseif ($status === 'pending') {
                                                    $statusClass = 'warning';
                                                } elseif ($status === 'cancelled') {
                                                    $statusClass = 'danger';
                                                }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= h(date('h:i A', strtotime((string) $booking['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $booking['end_time']))) ?></strong><br>
                                                        <small class="text-secondary">Booked at <?= h(date('h:i A', strtotime((string) $booking['created_at']))) ?></small>
                                                    </td>
                                                    <td>
                                                        <strong><?= h((string) $booking['customer_name']) ?></strong><br>
                                                        <small class="text-secondary"><?= h((string) $booking['customer_email']) ?></small>
                                                    </td>
                                                    <td>
                                                        <strong><?= h((string) $booking['branch_name']) ?></strong><br>
                                                        <small class="text-secondary"><?= h((string) $booking['sport_name']) ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge text-bg-<?= h($statusClass) ?>"><?= h(ucfirst($status)) ?></span>
                                                        <div><small class="text-secondary"><?= h(ucfirst((string) $booking['payment_status'])) ?></small></div>
                                                    </td>
                                                    <td>
                                                        <small class="d-block">Paid: LKR <?= moneyFormat((float) $booking['paid_amount']) ?></small>
                                                        <small class="d-block">Total: LKR <?= moneyFormat((float) $booking['total_amount']) ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeSection === 'pos'): ?>
                        <div class="row g-4">
                            <div class="col-xl-5">
                                <div class="card branch-card owner-card p-4 h-100">
                                    <span class="section-kicker">POS</span>
                                    <h2 class="h4 fw-bold mb-3">Day Collection Summary</h2>
                                    <form method="GET" class="row g-2 align-items-end mb-3">
                                        <input type="hidden" name="section" value="pos">
                                        <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                        <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                        <div class="col-8">
                                            <label class="form-label fw-semibold" for="pos_report_date">Date</label>
                                            <input class="form-control" id="pos_report_date" type="date" name="report_date" value="<?= h($reportDate) ?>" required>
                                        </div>
                                        <div class="col-4 d-grid">
                                            <button class="btn btn-outline-secondary" type="submit">Load</button>
                                        </div>
                                    </form>

                                    <div class="owner-finance-grid mb-3">
                                        <div class="owner-metric owner-metric-income">
                                            <span>Income</span>
                                            <strong>LKR <?= moneyFormat($incomeTotal) ?></strong>
                                        </div>
                                        <div class="owner-metric owner-metric-expense">
                                            <span>Expenses</span>
                                            <strong>LKR <?= moneyFormat($expenseTotal) ?></strong>
                                        </div>
                                        <div class="owner-metric owner-metric-net">
                                            <span>Net</span>
                                            <strong>LKR <?= moneyFormat($netIncome) ?></strong>
                                        </div>
                                    </div>

                                    <?php if (count($paymentBreakdown) > 0): ?>
                                        <h3 class="h6 fw-bold mb-2">Payment Method Breakdown</h3>
                                        <div class="owner-breakdown-list">
                                            <?php foreach ($paymentBreakdown as $item): ?>
                                                <div class="owner-breakdown-item">
                                                    <span><?= h((string) $item['label']) ?></span>
                                                    <strong>LKR <?= moneyFormat((float) $item['amount']) ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mb-0">No payment collections for <?= h(date('d M Y', strtotime($reportDate))) ?>.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-xl-7">
                                <div class="card branch-card owner-card p-4 h-100">
                                    <span class="section-kicker">Expenses</span>
                                    <h2 class="h4 fw-bold mb-3">Enter Daily Expenses</h2>

                                    <form method="POST" class="row g-3 align-items-end mb-3 owner-form-shell">
                                        <input type="hidden" name="action" value="add_expense">
                                        <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                        <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                        <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                        <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold" for="expense_date">Date</label>
                                            <input class="form-control" type="date" id="expense_date" name="expense_date" value="<?= h($reportDate) ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold" for="expense_category">Category</label>
                                            <input class="form-control" type="text" id="expense_category" name="expense_category" maxlength="80" placeholder="Ex: Electricity" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold" for="expense_description">Description</label>
                                            <input class="form-control" type="text" id="expense_description" name="expense_description" maxlength="255" placeholder="Optional note">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold" for="expense_amount">Amount</label>
                                            <input class="form-control" type="number" id="expense_amount" name="expense_amount" min="1" step="0.01" required>
                                        </div>
                                        <div class="col-12 d-grid">
                                            <button type="submit" class="btn btn-main">Save Expense</button>
                                        </div>
                                    </form>

                                    <?php if (count($expenses) === 0): ?>
                                        <div class="alert alert-info mb-0">No expenses entered for <?= h(date('d M Y', strtotime($reportDate))) ?>.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table align-middle booking-table owner-table">
                                                <thead>
                                                    <tr>
                                                        <th>Category</th>
                                                        <th>Description</th>
                                                        <th>Amount</th>
                                                        <th>By</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($expenses as $expense): ?>
                                                        <tr>
                                                            <td><strong><?= h((string) $expense['category']) ?></strong></td>
                                                            <td><?= h((string) ($expense['description'] ?? '-')) ?></td>
                                                            <td>LKR <?= moneyFormat((float) $expense['amount']) ?></td>
                                                            <td><small class="text-secondary"><?= h((string) ($expense['created_by_name'] ?? 'Owner')) ?></small></td>
                                                            <td>
                                                                <form method="POST" onsubmit="return confirm('Delete this expense entry?');">
                                                                    <input type="hidden" name="action" value="delete_expense">
                                                                    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                                                                    <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
                                                                    <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
                                                                    <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                                                    <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Delete</button>
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
                        </div>

                        <div class="card branch-card owner-card p-4 mt-4">
                            <span class="section-kicker">POS Transactions</span>
                            <h3 class="h5 fw-bold mb-3">Payment Entries for <?= h(date('d M Y', strtotime($reportDate))) ?></h3>
                            <?php if (count($posTransactions) === 0): ?>
                                <div class="alert alert-info mb-0">No POS or payment records for the selected day.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle booking-table owner-table">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Customer</th>
                                                <th>Booking</th>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Ref</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($posTransactions as $row): ?>
                                                <tr>
                                                    <td><?= h(date('h:i A', strtotime((string) $row['payment_date']))) ?></td>
                                                    <td><?= h((string) $row['customer_name']) ?></td>
                                                    <td>
                                                        <strong>#<?= (int) $row['booking_id'] ?></strong><br>
                                                        <small class="text-secondary"><?= h((string) $row['branch_name']) ?> - <?= h((string) $row['sport_name']) ?></small>
                                                    </td>
                                                    <td><?= h(ucwords(str_replace('_', ' ', (string) $row['payment_method']))) ?> / <?= h(ucfirst((string) $row['payment_type'])) ?></td>
                                                    <td>LKR <?= moneyFormat((float) $row['amount']) ?></td>
                                                    <td><small class="text-secondary"><?= h((string) ($row['transaction_reference'] ?? '-')) ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeSection === 'graphs'): ?>
                        <div class="card branch-card owner-card p-4 mb-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <span class="section-kicker">Graphs</span>
                                    <h2 class="h4 fw-bold mb-1">Income vs Expenses and Bookings</h2>
                                    <p class="text-secondary mb-0">Seven-day trend ending on the selected date.</p>
                                </div>
                            </div>

                            <form method="GET" class="row g-3 align-items-end">
                                <input type="hidden" name="section" value="graphs">
                                <input type="hidden" name="schedule_sport_id" value="<?= $scheduleSportId ?>">
                                <input type="hidden" name="booking_status" value="<?= h($bookingStatus) ?>">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" for="graph_report_date">Trend End Date</label>
                                    <input class="form-control" id="graph_report_date" type="date" name="report_date" value="<?= h($reportDate) ?>" required>
                                </div>
                                <div class="col-md-3 d-grid">
                                    <button class="btn btn-main" type="submit">Refresh Graphs</button>
                                </div>
                            </form>
                        </div>

                        <div class="row g-4">
                            <div class="col-12">
                                <div class="card branch-card owner-card p-4">
                                    <h3 class="h5 fw-bold mb-3">Income vs Expenses (LKR)</h3>
                                    <div class="owner-chart-wrap">
                                        <canvas id="incomeOutcomeChart" height="110"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="card branch-card owner-card p-4">
                                    <h3 class="h5 fw-bold mb-3">Booking Volume (Last 7 Days)</h3>
                                    <div class="owner-chart-wrap">
                                        <canvas id="bookingTrendChart" height="95"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        const scheduleSportSelect = document.getElementById('schedule_sport_id');
        if (scheduleSportSelect) {
            scheduleSportSelect.addEventListener('change', function () {
                scheduleSportSelect.form.submit();
            });
        }

        const chartLabels = <?= $chartLabelsJson ?>;
        const incomeSeries = <?= $chartIncomeJson ?>;
        const expenseSeries = <?= $chartExpenseJson ?>;
        const bookingSeries = <?= $chartBookingJson ?>;

        const incomeOutcomeCanvas = document.getElementById('incomeOutcomeChart');
        if (incomeOutcomeCanvas && typeof Chart !== 'undefined') {
            new Chart(incomeOutcomeCanvas, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Income',
                            data: incomeSeries,
                            backgroundColor: 'rgba(22, 163, 74, 0.62)',
                            borderColor: 'rgba(22, 163, 74, 1)',
                            borderWidth: 1.5,
                            borderRadius: 8
                        },
                        {
                            label: 'Expenses',
                            data: expenseSeries,
                            backgroundColor: 'rgba(220, 38, 38, 0.58)',
                            borderColor: 'rgba(220, 38, 38, 1)',
                            borderWidth: 1.5,
                            borderRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        const bookingTrendCanvas = document.getElementById('bookingTrendChart');
        if (bookingTrendCanvas && typeof Chart !== 'undefined') {
            new Chart(bookingTrendCanvas, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Bookings',
                            data: bookingSeries,
                            borderColor: 'rgba(14, 116, 144, 1)',
                            backgroundColor: 'rgba(14, 116, 144, 0.18)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3,
                            pointRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            precision: 0
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
