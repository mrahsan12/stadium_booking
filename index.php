<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$branchCards = [];
$sportsList = [];

$branchStmt = $mysqli->prepare(
    'SELECT b.id, b.name, b.location, COUNT(s.id) AS sport_count
     FROM branches b
     LEFT JOIN sports s ON s.branch_id = b.id
     GROUP BY b.id, b.name, b.location
     ORDER BY b.id ASC
     LIMIT 6'
);

if ($branchStmt) {
    $branchStmt->execute();
    $branchResult = $branchStmt->get_result();

    while ($branchResult && $row = $branchResult->fetch_assoc()) {
        $branchCards[] = $row;
    }

    $branchStmt->close();
}

if (count($branchCards) === 0) {
    $branchCards = [
        ['id' => 1, 'name' => 'Colombo Branch', 'location' => 'Colombo 05', 'sport_count' => 3],
        ['id' => 2, 'name' => 'Kandy Branch', 'location' => 'Kandy City', 'sport_count' => 3],
    ];
}

$sportsQuery = $mysqli->query('SELECT DISTINCT name FROM sports ORDER BY name ASC');

if ($sportsQuery) {
    while ($sportRow = $sportsQuery->fetch_assoc()) {
        $sportsList[] = $sportRow['name'];
    }
}

if (count($sportsList) === 0) {
    $sportsList = ['Futsal', 'Cricket', 'Badminton'];
}

$sportIcons = [
    'futsal' => 'bi-dribbble',
    'cricket' => 'bi-trophy',
    'badminton' => 'bi-feather',
];

$isLoggedIn = !empty($_SESSION['user_id']);
$dashboardLink = $isLoggedIn ? dashboardRoute($_SESSION['user_role']) : 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indoor Stadium Booking</title>
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
            <a class="navbar-brand fw-bold" href="#home">ArenaHub Stadium</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto me-lg-3">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#branches">Branches</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sports">Sports</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                </ul>
                <?php if ($isLoggedIn): ?>
                    <a href="<?= h($dashboardLink) ?>" class="btn btn-main">My Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-main">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <section class="hero" id="home">
        <div class="container py-5">
            <div class="row">
                <div class="col-lg-8 reveal">
                    <h1 class="fw-bold mb-3">Book Your Indoor Stadium Easily</h1>
                    <p class="hero-sub mb-4">Futsal | Cricket | Badminton. Browse branches and sports freely; you only need to log in when you book.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="<?= h($dashboardLink) ?>" class="btn btn-main">Book Now</a>
                        <a href="#branches" class="btn btn-outline-main">View Branches</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-block bg-white" id="about">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-7 reveal">
                    <h2 class="section-title fw-bold">About Our Platform</h2>
                    <p class="section-desc mb-0">
                        Our platform helps users book indoor sports easily with real-time visibility, branch-wise options,
                        and a simple booking flow. Login only when you want to confirm your slot.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="section-block" id="branches">
        <div class="container">
            <h2 class="section-title fw-bold reveal">Branch Preview</h2>
            <p class="section-desc reveal delay-1 mb-4">Choose a nearby branch and view available sports instantly.</p>
            <div class="row g-4">
                <?php foreach ($branchCards as $index => $branch): ?>
                    <div class="col-md-6 col-lg-4 reveal delay-<?= (int) (($index % 3) + 1) ?>">
                        <div class="card branch-card p-3 p-md-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h3 class="h5 mb-0 fw-bold"><?= h($branch['name']) ?></h3>
                                <span class="location-badge"><?= h((string) $branch['sport_count']) ?> <?= (int) $branch['sport_count'] === 1 ? 'Sport' : 'Sports' ?></span>
                            </div>
                            <p class="mb-4 text-secondary"><i class="bi bi-geo-alt-fill me-1"></i><?= h($branch['location'] ?: 'Sri Lanka') ?></p>
                            <a href="branches.php?branch=<?= (int) $branch['id'] ?>" class="btn btn-main w-100">View Sports</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section-block bg-white" id="sports">
        <div class="container">
            <h2 class="section-title fw-bold reveal">Popular Sports</h2>
            <p class="section-desc reveal delay-1 mb-4">Browse by sport and check branch availability quickly.</p>
            <div class="row g-4">
                <?php foreach (array_slice($sportsList, 0, 6) as $sportName): ?>
                    <?php
                    $key = strtolower(trim($sportName));
                    $iconClass = $sportIcons[$key] ?? 'bi-lightning-charge';
                    ?>
                    <div class="col-sm-6 col-lg-4 reveal">
                        <div class="card sport-card p-4 text-center h-100">
                            <i class="bi <?= h($iconClass) ?> sport-icon mb-3"></i>
                            <h3 class="h5 fw-bold mb-2"><?= h($sportName) ?></h3>
                            <p class="text-secondary mb-0">Flexible slots and quick booking confirmation.</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section-block" id="features">
        <div class="container">
            <h2 class="section-title fw-bold reveal">Why Choose Us</h2>
            <div class="row g-4 mt-1">
                <div class="col-md-6 col-lg-3 reveal">
                    <div class="card feature-card p-4 h-100">
                        <span class="feature-icon mb-3"><i class="bi bi-calendar2-check"></i></span>
                        <h3 class="h6 fw-bold">Easy Booking</h3>
                        <p class="text-secondary mb-0">Simple steps, less time, clear availability.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 reveal delay-1">
                    <div class="card feature-card p-4 h-100">
                        <span class="feature-icon mb-3"><i class="bi bi-shield-lock"></i></span>
                        <h3 class="h6 fw-bold">Secure Payment</h3>
                        <p class="text-secondary mb-0">Safe transaction handling and payment tracking.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 reveal delay-2">
                    <div class="card feature-card p-4 h-100">
                        <span class="feature-icon mb-3"><i class="bi bi-clock-history"></i></span>
                        <h3 class="h6 fw-bold">Real-time Availability</h3>
                        <p class="text-secondary mb-0">Already booked slots are shown clearly.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 reveal delay-3">
                    <div class="card feature-card p-4 h-100">
                        <span class="feature-icon mb-3"><i class="bi bi-check2-circle"></i></span>
                        <h3 class="h6 fw-bold">Instant Confirmation</h3>
                        <p class="text-secondary mb-0">Booking status updated right after payment.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-block pt-0">
        <div class="container">
            <div class="cta-box reveal">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <h2 class="h3 fw-bold mb-2">Log in to start booking now</h2>
                        <p class="mb-0 text-white-50">You can browse freely. You only need to log in to confirm bookings.</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <?php if ($isLoggedIn): ?>
                            <a href="<?= h($dashboardLink) ?>" class="btn btn-light fw-bold me-2 mb-2 mb-md-0">Dashboard</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-light fw-bold me-2 mb-2 mb-md-0">Login</a>
                            <a href="register.php" class="btn btn-outline-main">Register</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer" id="contact">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-5">
                    <h3 class="h5 fw-bold">ArenaHub Indoor Stadium</h3>
                    <p class="mb-2">Sports-focused indoor booking platform for players in Sri Lanka.</p>
                    <p class="mb-1"><strong>Email:</strong> support@arenahub.lk</p>
                    <p class="mb-0"><strong>Phone:</strong> +94 77 123 4567</p>
                </div>
                <div class="col-lg-3">
                    <h4 class="h6 fw-bold">Quick Links</h4>
                    <p class="mb-1"><a href="#home">Home</a></p>
                    <p class="mb-1"><a href="#branches">Branches</a></p>
                    <p class="mb-1"><a href="#sports">Sports</a></p>
                    <p class="mb-0"><a href="login.php">Login</a></p>
                </div>
                <div class="col-lg-4">
                    <h4 class="h6 fw-bold">Follow Us</h4>
                    <a href="#" class="social-link"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="social-link"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="social-link"><i class="bi bi-whatsapp"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
