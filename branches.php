<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$branchMap = [];
$selectedBranch = isset($_GET['branch']) ? (int) $_GET['branch'] : 0;

$sql = 'SELECT b.id AS branch_id, b.name AS branch_name, b.location, s.id AS sport_id, s.name AS sport_name
        FROM branches b
        LEFT JOIN sports s ON s.branch_id = b.id
        ORDER BY b.id ASC, s.name ASC';
$result = $mysqli->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $branchId = (int) $row['branch_id'];

        if (!isset($branchMap[$branchId])) {
            $branchMap[$branchId] = [
                'id' => $branchId,
                'name' => $row['branch_name'],
                'location' => $row['location'] ?: 'Sri Lanka',
                'sports' => [],
            ];
        }

        if (!empty($row['sport_name'])) {
            $branchMap[$branchId]['sports'][] = $row['sport_name'];
        }
    }
}

if (count($branchMap) === 0) {
    $branchMap = [
        1 => [
            'id' => 1,
            'name' => 'Colombo Branch',
            'location' => 'Colombo 05',
            'sports' => ['Futsal', 'Cricket', 'Badminton'],
        ],
        2 => [
            'id' => 2,
            'name' => 'Kandy Branch',
            'location' => 'Kandy City',
            'sports' => ['Futsal', 'Cricket', 'Badminton'],
        ],
    ];
}

$isLoggedIn = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branches | Indoor Stadium Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">ArenaHub Stadium</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#branchNavMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="branchNavMenu">
                <ul class="navbar-nav ms-auto me-lg-3">
                    <li class="nav-item"><a class="nav-link" href="index.php#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="branches.php">Branches</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#sports">Sports</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#contact">Contact</a></li>
                </ul>
                <?php if ($isLoggedIn): ?>
                    <a class="btn btn-main" href="<?= h(dashboardRoute($_SESSION['user_role'])) ?>">My Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-main" href="login.php">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <section class="section-block">
        <div class="container">
            <h1 class="section-title fw-bold mb-2">Available Branches</h1>
            <p class="section-desc mb-4">Select a branch to view the available sports. You need to log in to confirm a booking.</p>

            <div class="row g-4">
                <?php foreach ($branchMap as $branch): ?>
                    <?php
                    $branchId = (int) $branch['id'];
                    $highlightClass = $selectedBranch === $branchId ? 'border border-2 border-info-subtle' : '';
                    ?>
                    <div class="col-lg-6" id="branch-<?= $branchId ?>">
                        <div class="card branch-card p-4 h-100 <?= $highlightClass ?>">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <h2 class="h4 fw-bold mb-1"><?= h($branch['name']) ?></h2>
                                    <p class="mb-3 text-secondary"><i class="bi bi-geo-alt-fill me-1"></i><?= h($branch['location']) ?></p>
                                </div>
                                <?php $sportCount = count($branch['sports']); ?>
                                <span class="location-badge"><?= $sportCount ?> <?= $sportCount === 1 ? 'Sport' : 'Sports' ?></span>
                            </div>

                            <?php if (count($branch['sports']) > 0): ?>
                                <div class="mb-4">
                                    <?php foreach ($branch['sports'] as $sportName): ?>
                                        <span class="badge text-bg-light border me-2 mb-2 py-2 px-3"><?= h($sportName) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-secondary mb-4">Sports will be added to this branch soon.</p>
                            <?php endif; ?>

                            <?php if ($isLoggedIn): ?>
                                <a href="customer_dashboard.php?branch_id=<?= $branchId ?>" class="btn btn-main">Go to the Booking Dashboard</a>
                            <?php else: ?>
                                <a href="login.php" class="btn btn-main">Log in to Book</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
