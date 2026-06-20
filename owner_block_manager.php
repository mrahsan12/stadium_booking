<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin('admin');

function isValidYmdDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function redirectBlockManager(string $type, string $message): void
{
    $_SESSION['owner_block_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: owner_block_manager.php');
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

function sportBelongsToBranch(mysqli $mysqli, int $sportId, int $branchId): bool
{
    $stmt = $mysqli->prepare('SELECT id FROM sports WHERE id = ? AND branch_id = ? LIMIT 1');
    $stmt->bind_param('ii', $sportId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function slotBelongsToSport(mysqli $mysqli, int $slotId, int $sportId): bool
{
    $stmt = $mysqli->prepare('SELECT id FROM time_slots WHERE id = ? AND sport_id = ? LIMIT 1');
    $stmt->bind_param('ii', $slotId, $sportId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $ownerId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'add_branch_holiday') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $blockDate = trim((string) ($_POST['block_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $reason = substr($reason, 0, 150);

        if ($branchId <= 0 || !branchExists($mysqli, $branchId)) {
            redirectBlockManager('danger', 'Please select a valid branch.');
        }
        if (!isValidYmdDate($blockDate)) {
            redirectBlockManager('danger', 'Please provide a valid block date.');
        }
        if ($reason === '') {
            redirectBlockManager('danger', 'Holiday reason is required.');
        }

        $dupeStmt = $mysqli->prepare(
            "SELECT id
             FROM owner_slot_blocks
             WHERE block_scope = 'branch_day'
               AND branch_id = ?
               AND block_date = ?
             LIMIT 1"
        );
        $dupeStmt->bind_param('is', $branchId, $blockDate);
        $dupeStmt->execute();
        $dupeResult = $dupeStmt->get_result();
        $exists = $dupeResult && $dupeResult->num_rows > 0;
        $dupeStmt->close();

        if ($exists) {
            redirectBlockManager('warning', 'A branch holiday block already exists for this date.');
        }

        $insertStmt = $mysqli->prepare(
            "INSERT INTO owner_slot_blocks (
                block_date, branch_id, sport_id, slot_id, block_scope, reason, created_by
            ) VALUES (?, ?, NULL, NULL, 'branch_day', ?, ?)"
        );
        $insertStmt->bind_param('sisi', $blockDate, $branchId, $reason, $ownerId);
        $saved = $insertStmt->execute();
        $insertStmt->close();

        if ($saved) {
            redirectBlockManager('success', 'Branch holiday block saved.');
        }
        redirectBlockManager('danger', 'Could not save branch holiday block.');
    }

    if ($action === 'add_slot_block') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $blockDate = trim((string) ($_POST['block_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $reason = substr($reason, 0, 150);

        if ($branchId <= 0 || !branchExists($mysqli, $branchId)) {
            redirectBlockManager('danger', 'Please select a valid branch.');
        }
        if ($sportId <= 0 || !sportBelongsToBranch($mysqli, $sportId, $branchId)) {
            redirectBlockManager('danger', 'Selected sport does not match the branch.');
        }
        if ($slotId <= 0 || !slotBelongsToSport($mysqli, $slotId, $sportId)) {
            redirectBlockManager('danger', 'Selected slot does not match the sport.');
        }
        if (!isValidYmdDate($blockDate)) {
            redirectBlockManager('danger', 'Please provide a valid block date.');
        }
        if ($reason === '') {
            redirectBlockManager('danger', 'Slot block reason is required.');
        }

        $dupeStmt = $mysqli->prepare(
            "SELECT id
             FROM owner_slot_blocks
             WHERE block_scope = 'slot'
               AND slot_id = ?
               AND block_date = ?
             LIMIT 1"
        );
        $dupeStmt->bind_param('is', $slotId, $blockDate);
        $dupeStmt->execute();
        $dupeResult = $dupeStmt->get_result();
        $exists = $dupeResult && $dupeResult->num_rows > 0;
        $dupeStmt->close();

        if ($exists) {
            redirectBlockManager('warning', 'This slot is already blocked for that date.');
        }

        $insertStmt = $mysqli->prepare(
            "INSERT INTO owner_slot_blocks (
                block_date, branch_id, sport_id, slot_id, block_scope, reason, created_by
            ) VALUES (?, ?, ?, ?, 'slot', ?, ?)"
        );
        $insertStmt->bind_param('siiisi', $blockDate, $branchId, $sportId, $slotId, $reason, $ownerId);
        $saved = $insertStmt->execute();
        $insertStmt->close();

        if ($saved) {
            redirectBlockManager('success', 'Slot block added successfully.');
        }
        redirectBlockManager('danger', 'Could not add slot block.');
    }

    if ($action === 'delete_block') {
        $blockId = (int) ($_POST['block_id'] ?? 0);
        if ($blockId <= 0) {
            redirectBlockManager('danger', 'Invalid block selected.');
        }

        $deleteStmt = $mysqli->prepare('DELETE FROM owner_slot_blocks WHERE id = ? LIMIT 1');
        $deleteStmt->bind_param('i', $blockId);
        $deleteStmt->execute();
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($affected > 0) {
            redirectBlockManager('success', 'Block removed.');
        }
        redirectBlockManager('warning', 'Block not found or already removed.');
    }

    redirectBlockManager('danger', 'Unknown action.');
}

$flash = $_SESSION['owner_block_flash'] ?? null;
unset($_SESSION['owner_block_flash']);

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
    "SELECT s.id, s.branch_id, s.name, b.name AS branch_name
     FROM sports s
     JOIN branches b ON b.id = s.branch_id
     ORDER BY b.name ASC, s.name ASC"
);
if ($sportsResult) {
    while ($row = $sportsResult->fetch_assoc()) {
        $sports[] = [
            'id' => (int) $row['id'],
            'branch_id' => (int) $row['branch_id'],
            'name' => (string) $row['name'],
            'branch_name' => (string) $row['branch_name'],
        ];
    }
    $sportsResult->free();
}

$slots = [];
$slotResult = $mysqli->query(
    "SELECT ts.id, ts.sport_id, ts.start_time, ts.end_time
     FROM time_slots ts
     ORDER BY ts.start_time ASC"
);
if ($slotResult) {
    while ($row = $slotResult->fetch_assoc()) {
        $slots[] = [
            'id' => (int) $row['id'],
            'sport_id' => (int) $row['sport_id'],
            'start_time' => (string) $row['start_time'],
            'end_time' => (string) $row['end_time'],
        ];
    }
    $slotResult->free();
}

$activeBlocks = [];
$blockStmt = $mysqli->prepare(
    "SELECT ob.id,
            ob.block_date,
            ob.block_scope,
            ob.reason,
            ob.created_at,
            br.name AS branch_name,
            s.name AS sport_name,
            ts.start_time,
            ts.end_time,
            u.name AS created_by_name
     FROM owner_slot_blocks ob
     JOIN branches br ON br.id = ob.branch_id
     LEFT JOIN sports s ON s.id = ob.sport_id
     LEFT JOIN time_slots ts ON ts.id = ob.slot_id
     LEFT JOIN users u ON u.id = ob.created_by
     WHERE ob.block_date >= CURDATE()
     ORDER BY ob.block_date ASC, ob.block_scope ASC, ts.start_time ASC, ob.id DESC
     LIMIT 500"
);
$blockStmt->execute();
$blockResult = $blockStmt->get_result();
if ($blockResult) {
    while ($row = $blockResult->fetch_assoc()) {
        $activeBlocks[] = $row;
    }
}
$blockStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Slot Block Manager | ArenaHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="app-shell owner-shell owner-theme-schedule">
    <nav class="navbar navbar-expand-lg sticky-top owner-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold owner-brand" href="admin_dashboard.php">
                <span class="owner-brand-mark"><i class="bi bi-calendar-x"></i></span>
                <span>Slot Block and Holiday Manager</span>
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
            <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
                <div class="alert alert-<?= h((string) $flash['type']) ?> mb-4"><?= h((string) $flash['message']) ?></div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-xl-6">
                    <div class="card branch-card owner-card p-4 h-100">
                        <span class="section-kicker">Holiday</span>
                        <h2 class="h4 fw-bold mb-3">Block Entire Branch Day</h2>
                        <form method="POST" class="row g-3 owner-form-shell">
                            <input type="hidden" name="action" value="add_branch_holiday">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="holiday_branch">Branch</label>
                                <select class="form-select" id="holiday_branch" name="branch_id" required>
                                    <option value="">Select branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= (int) $branch['id'] ?>"><?= h((string) $branch['name']) ?> (<?= h((string) $branch['location']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="holiday_date">Date</label>
                                <input class="form-control" type="date" id="holiday_date" name="block_date" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="holiday_reason">Reason</label>
                                <input class="form-control" type="text" id="holiday_reason" name="reason" maxlength="150" placeholder="Ex: Public holiday / maintenance" required>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-main">Save Branch Holiday</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card branch-card owner-card p-4 h-100">
                        <span class="section-kicker">Slot Block</span>
                        <h2 class="h4 fw-bold mb-3">Block Specific Slot</h2>
                        <form method="POST" class="row g-3 owner-form-shell" id="slotBlockForm">
                            <input type="hidden" name="action" value="add_slot_block">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="slot_branch">Branch</label>
                                <select class="form-select" id="slot_branch" name="branch_id" required>
                                    <option value="">Select branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= (int) $branch['id'] ?>"><?= h((string) $branch['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="slot_sport">Sport</label>
                                <select class="form-select" id="slot_sport" name="sport_id" required>
                                    <option value="">Select sport</option>
                                    <?php foreach ($sports as $sport): ?>
                                        <option value="<?= (int) $sport['id'] ?>" data-branch="<?= (int) $sport['branch_id'] ?>">
                                            <?= h((string) $sport['name']) ?> (<?= h((string) $sport['branch_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="slot_id">Time Slot</label>
                                <select class="form-select" id="slot_id" name="slot_id" required>
                                    <option value="">Select slot</option>
                                    <?php foreach ($slots as $slot): ?>
                                        <option value="<?= (int) $slot['id'] ?>" data-sport="<?= (int) $slot['sport_id'] ?>">
                                            <?= h(date('h:i A', strtotime((string) $slot['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $slot['end_time']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="slot_block_date">Date</label>
                                <input class="form-control" type="date" id="slot_block_date" name="block_date" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="slot_reason">Reason</label>
                                <input class="form-control" type="text" id="slot_reason" name="reason" maxlength="150" placeholder="Ex: Tournament setup" required>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-main">Block Slot</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="card branch-card owner-card p-4 mt-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h2 class="h5 fw-bold mb-0">Upcoming Blocks</h2>
                    <span class="badge text-bg-primary">Total: <?= count($activeBlocks) ?></span>
                </div>

                <?php if (count($activeBlocks) === 0): ?>
                    <div class="alert alert-info mb-0">No upcoming blocks configured.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle booking-table owner-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Scope</th>
                                    <th>Branch / Sport / Slot</th>
                                    <th>Reason</th>
                                    <th>Created By</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeBlocks as $block): ?>
                                    <?php $isBranch = (string) $block['block_scope'] === 'branch_day'; ?>
                                    <tr>
                                        <td><?= h(date('d M Y', strtotime((string) $block['block_date']))) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= $isBranch ? 'danger' : 'warning' ?>">
                                                <?= $isBranch ? 'Branch Holiday' : 'Slot Block' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= h((string) $block['branch_name']) ?></strong><br>
                                            <?php if ($isBranch): ?>
                                                <small class="text-secondary">All sports and all slots</small>
                                            <?php else: ?>
                                                <small class="text-secondary">
                                                    <?= h((string) ($block['sport_name'] ?? '-')) ?> |
                                                    <?= h(date('h:i A', strtotime((string) $block['start_time']))) ?> - <?= h(date('h:i A', strtotime((string) $block['end_time']))) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h((string) $block['reason']) ?></td>
                                        <td><small class="text-secondary"><?= h((string) ($block['created_by_name'] ?? 'Owner')) ?></small></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Remove this block?');">
                                                <input type="hidden" name="action" value="delete_block">
                                                <input type="hidden" name="block_id" value="<?= (int) $block['id'] ?>">
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
    </section>

    <script>
        const branchSelect = document.getElementById('slot_branch');
        const sportSelect = document.getElementById('slot_sport');
        const slotSelect = document.getElementById('slot_id');

        function filterSportsByBranch() {
            if (!branchSelect || !sportSelect) {
                return;
            }

            const branchId = branchSelect.value;
            const options = sportSelect.querySelectorAll('option[data-branch]');

            options.forEach(function (option) {
                option.hidden = branchId !== '' && option.getAttribute('data-branch') !== branchId;
            });

            if (sportSelect.selectedOptions.length > 0 && sportSelect.selectedOptions[0].hidden) {
                sportSelect.value = '';
            }
            filterSlotsBySport();
        }

        function filterSlotsBySport() {
            if (!sportSelect || !slotSelect) {
                return;
            }

            const sportId = sportSelect.value;
            const options = slotSelect.querySelectorAll('option[data-sport]');

            options.forEach(function (option) {
                option.hidden = sportId !== '' && option.getAttribute('data-sport') !== sportId;
            });

            if (slotSelect.selectedOptions.length > 0 && slotSelect.selectedOptions[0].hidden) {
                slotSelect.value = '';
            }
        }

        if (branchSelect) {
            branchSelect.addEventListener('change', filterSportsByBranch);
        }
        if (sportSelect) {
            sportSelect.addEventListener('change', filterSlotsBySport);
        }

        filterSportsByBranch();
    </script>
</body>
</html>
