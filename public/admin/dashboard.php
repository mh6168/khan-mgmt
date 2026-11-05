<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminAuth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminApplicationService.php';

AdminAuth::requireAuthenticated();
$email = AdminAuth::escape((string) $_SESSION['admin_email']);
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$loadError = '';

try {
    $stats = AdminApplicationService::fromEnvironment()->statistics();
} catch (Throwable $exception) {
    error_log('Admin dashboard statistics failure: ' . get_class($exception) . '.');
    $loadError = 'Application statistics are unavailable. Configure Firebase admin credentials to enable live data.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | KHAN</title>
    <style><?= AdminAuth::styles() ?></style>
</head>
<body>
<main class="admin-shell">
    <p class="eyebrow">KHAN MANAGEMENT</p>
    <div class="admin-header">
        <div>
            <h1>Admin Dashboard</h1>
            <p class="muted">Welcome back. Your administration workspace is ready.</p>
        </div>
        <a class="logout" href="<?= AdminAuth::escape(AdminAuth::pageUrl('logout.php')) ?>">Log out</a>
    </div>
    <nav class="nav">
        <a href="<?= AdminAuth::escape(AdminAuth::pageUrl('dashboard.php')) ?>">Overview</a>
        <a href="<?= AdminAuth::escape(AdminAuth::pageUrl('requests.php')) ?>">Jeweler requests</a>
    </nav>
    <section class="panel">
        <p class="muted">Signed in as <?= $email ?></p>
        <?php if ($loadError !== ''): ?><div class="errors"><?= AdminAuth::escape($loadError) ?></div><?php endif; ?>
        <div class="stat-grid">
            <div class="stat"><strong><?= $stats['total'] ?></strong><span>Total applications</span></div>
            <div class="stat"><strong><?= $stats['pending'] ?></strong><span>Pending requests</span></div>
            <div class="stat"><strong><?= $stats['approved'] ?></strong><span>Approved jewelers</span></div>
            <div class="stat"><strong><?= $stats['rejected'] ?></strong><span>Rejected requests</span></div>
        </div>
        <p class="muted">Application statistics are read from the existing Firebase applications collection.</p>
    </section>
</main>
</body>
</html>
