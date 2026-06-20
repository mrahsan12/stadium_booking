<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function isValidYmdDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function sanitizeBookingStatus(string $value): string
{
    $allowed = ['all', 'pending', 'confirmed', 'cancelled'];
    return in_array($value, $allowed, true) ? $value : 'all';
}

function sendCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit('Could not open output stream.');
    }

    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$reportDate = trim((string) ($_GET['report_date'] ?? date('Y-m-d')));
if (!isValidYmdDate($reportDate)) {
    $reportDate = date('Y-m-d');
}
$bookingStatus = sanitizeBookingStatus(trim((string) ($_GET['booking_status'] ?? 'all')));
$exportType = trim((string) ($_GET['export'] ?? ''));

if ($exportType !== '') {
    if ($exportType === 'bookings') {
        $rows = [];
        $stmt = $mysqli->prepare(
            "SELECT b.id,
                    b.booking_date,
                    b.status,
                    b.payment_status,
                    b.payment_plan,
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
             ORDER BY ts.start_time ASC, b.id ASC"
        );
        $stmt->bind_param('sss', $reportDate, $bookingStatus, $bookingStatus);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'],
                    $r['booking_date'],
                    $r['status'],
                    $r['payment_status'],
                    $r['payment_plan'],
                    $r['customer_name'],
                    $r['customer_email'],
                    $r['branch_name'],
                    $r['sport_name'],
                    $r['start_time'] . ' - ' . $r['end_time'],
                    $r['total_amount'],
                    $r['paid_amount'],
                    $r['created_at'],
                ];
            }
        }
        $stmt->close();

        sendCsv(
            'bookings_' . $reportDate . '.csv',
            ['Booking ID', 'Booking Date', 'Status', 'Payment Status', 'Plan', 'Customer', 'Email', 'Branch', 'Sport', 'Slot', 'Total Amount', 'Paid Amount', 'Created At'],
            $rows
        );
    }

    if ($exportType === 'payments') {
        $rows = [];
        $stmt = $mysqli->prepare(
            "SELECT p.id,
                    p.booking_id,
                    p.payment_date,
                    p.payment_method,
                    p.payment_type,
                    p.approval_status,
                    p.amount,
                    p.transaction_reference,
                    u.name AS customer_name,
                    br.name AS branch_name,
                    s.name AS sport_name
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN users u ON u.id = b.user_id
             JOIN branches br ON br.id = b.branch_id
             JOIN sports s ON s.id = b.sport_id
             WHERE DATE(p.payment_date) = ?
             ORDER BY p.payment_date DESC, p.id DESC"
        );
        $stmt->bind_param('s', $reportDate);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'],
                    $r['booking_id'],
                    $r['payment_date'],
                    $r['payment_method'],
                    $r['payment_type'],
                    $r['approval_status'],
                    $r['amount'],
                    $r['transaction_reference'],
                    $r['customer_name'],
                    $r['branch_name'],
                    $r['sport_name'],
                ];
            }
        }
        $stmt->close();

        sendCsv(
            'payments_' . $reportDate . '.csv',
            ['Payment ID', 'Booking ID', 'Payment Date', 'Method', 'Type', 'Approval Status', 'Amount', 'Reference', 'Customer', 'Branch', 'Sport'],
            $rows
        );
    }

    if ($exportType === 'expenses') {
        $rows = [];
        $stmt = $mysqli->prepare(
            "SELECT oe.id,
                    oe.expense_date,
                    oe.category,
                    oe.description,
                    oe.amount,
                    oe.created_at,
                    u.name AS created_by
             FROM owner_expenses oe
             LEFT JOIN users u ON u.id = oe.created_by
             WHERE oe.expense_date = ?
             ORDER BY oe.created_at DESC, oe.id DESC"
        );
        $stmt->bind_param('s', $reportDate);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'],
                    $r['expense_date'],
                    $r['category'],
                    $r['description'],
                    $r['amount'],
                    $r['created_by'],
                    $r['created_at'],
                ];
            }
        }
        $stmt->close();

        sendCsv(
            'expenses_' . $reportDate . '.csv',
            ['Expense ID', 'Date', 'Category', 'Description', 'Amount', 'Created By', 'Created At'],
            $rows
        );
    }

    if ($exportType === 'customers') {
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
             ORDER BY last_booking_at DESC, total_bookings DESC, u.name ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $total = (int) ($r['total_bookings'] ?? 0);
                $cancelled = (int) ($r['cancelled_count'] ?? 0);
                $cancelRate = $total > 0 ? round(($cancelled / $total) * 100, 2) : 0;
                $rows[] = [
                    $r['id'],
                    $r['name'],
                    $r['email'],
                    $r['total_bookings'],
                    $r['confirmed_count'],
                    $r['pending_count'],
                    $r['cancelled_count'],
                    $r['expired_count'],
                    $cancelRate,
                    $r['lifetime_booking_value'],
                    $r['last_booking_at'],
                ];
            }
        }
        $stmt->close();

        sendCsv(
            'customer_insights_' . date('Ymd_His') . '.csv',
            ['Customer ID', 'Name', 'Email', 'Total Bookings', 'Confirmed', 'Pending', 'Cancelled', 'Expired', 'Cancel Rate %', 'Lifetime Booking Value', 'Last Booking At'],
            $rows
        );
    }

    if ($exportType === 'pending_approvals') {
        $rows = [];
        $stmt = $mysqli->prepare(
            "SELECT p.id,
                    p.booking_id,
                    p.payment_date,
                    p.amount,
                    p.payment_method,
                    p.payment_type,
                    p.transaction_reference,
                    u.name AS customer_name,
                    u.email AS customer_email,
                    br.name AS branch_name,
                    s.name AS sport_name,
                    b.booking_date
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN users u ON u.id = b.user_id
             JOIN branches br ON br.id = b.branch_id
             JOIN sports s ON s.id = b.sport_id
             WHERE p.approval_status = 'pending'
             ORDER BY p.payment_date ASC, p.id ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'],
                    $r['booking_id'],
                    $r['booking_date'],
                    $r['payment_date'],
                    $r['customer_name'],
                    $r['customer_email'],
                    $r['branch_name'],
                    $r['sport_name'],
                    $r['amount'],
                    $r['payment_method'],
                    $r['payment_type'],
                    $r['transaction_reference'],
                ];
            }
        }
        $stmt->close();

        sendCsv(
            'pending_approvals_' . date('Ymd_His') . '.csv',
            ['Payment ID', 'Booking ID', 'Booking Date', 'Payment Date', 'Customer', 'Email', 'Branch', 'Sport', 'Amount', 'Method', 'Type', 'Reference'],
            $rows
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Export Reports | ArenaHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell owner-shell owner-theme-graphs">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="admin_dashboard.php">
                <span class="owner-brand-mark"><i class="bi bi-download"></i></span>
                <span>Export Reports</span>
            </a>
            <div class="ms-auto d-flex gap-2">
                <a href="admin_dashboard.php" class="btn btn-outline-secondary owner-top-btn">Owner Dashboard</a>
                <a href="owner_payment_queue.php" class="btn btn-outline-secondary owner-top-btn">Payment Queue</a>
                <a href="logout.php" class="btn btn-main owner-top-btn">Logout</a>
            </div>
        </div>
    </nav>

    <section class="section-block py-5">
        <div class="container">
            <div class="card branch-card owner-card p-4 mb-4">
                <span class="section-kicker">Filters</span>
                <h1 class="h4 fw-bold mb-3">Choose a Date and Status</h1>
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="report_date">Report Date</label>
                        <input class="form-control" type="date" id="report_date" name="report_date" value="<?= h($reportDate) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="booking_status">Booking Status</label>
                        <select class="form-select" id="booking_status" name="booking_status">
                            <option value="all" <?= $bookingStatus === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="confirmed" <?= $bookingStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="pending" <?= $bookingStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="cancelled" <?= $bookingStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-grid">
                        <button class="btn btn-outline-secondary" type="submit">Refresh Filters</button>
                    </div>
                </form>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-xl-4">
                    <div class="card branch-card owner-card p-4 h-100">
                        <h2 class="h5 fw-bold mb-2">Bookings CSV</h2>
                        <p class="text-secondary">Export booking details for the selected date and status.</p>
                        <a class="btn btn-main mt-auto" href="owner_export_reports.php?<?= h(http_build_query(['report_date' => $reportDate, 'booking_status' => $bookingStatus, 'export' => 'bookings'])) ?>">Download</a>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <div class="card branch-card owner-card p-4 h-100">
                        <h2 class="h5 fw-bold mb-2">Payments CSV</h2>
                        <p class="text-secondary">Export all payments for selected date.</p>
                        <a class="btn btn-main mt-auto" href="owner_export_reports.php?<?= h(http_build_query(['report_date' => $reportDate, 'export' => 'payments'])) ?>">Download</a>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <div class="card branch-card owner-card p-4 h-100">
                        <h2 class="h5 fw-bold mb-2">Expenses CSV</h2>
                        <p class="text-secondary">Export owner expenses for selected date.</p>
                        <a class="btn btn-main mt-auto" href="owner_export_reports.php?<?= h(http_build_query(['report_date' => $reportDate, 'export' => 'expenses'])) ?>">Download</a>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <div class="card branch-card owner-card p-4 h-100">
                        <h2 class="h5 fw-bold mb-2">Customer Insights CSV</h2>
                        <p class="text-secondary">Export history and risk indicators for all customers.</p>
                        <a class="btn btn-main mt-auto" href="owner_export_reports.php?<?= h(http_build_query(['export' => 'customers'])) ?>">Download</a>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <div class="card branch-card owner-card p-4 h-100">
                        <h2 class="h5 fw-bold mb-2">Pending Approvals CSV</h2>
                        <p class="text-secondary">Export the current pending payment approval queue.</p>
                        <a class="btn btn-main mt-auto" href="owner_export_reports.php?<?= h(http_build_query(['export' => 'pending_approvals'])) ?>">Download</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</body>
</html>
