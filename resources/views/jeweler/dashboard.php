<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= JewelerSession::escape($viewTitle) ?> | KHAN Jeweler</title>
    <style><?= JewelerSession::styles() ?></style>
    <link rel="stylesheet" href="<?= JewelerSession::escape(JewelerSession::url('/assets/css/jeweler-dashboard.css?v=' . (string) filemtime(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'jeweler-dashboard.css'))) ?>">
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="main-area">
<?php require __DIR__ . '/partials/topbar.php'; ?>
        <div class="content">
<?php require __DIR__ . '/partials/notices.php'; ?>
<?php if ($view === 'overview'): ?>
<?php require __DIR__ . '/partials/overview.php'; ?>
<?php elseif ($view === 'products'): ?>
<?php require __DIR__ . '/partials/products.php'; ?>
<?php elseif ($view === 'reservations'): ?>
<?php require __DIR__ . '/partials/reservations.php'; ?>
<?php elseif ($view === 'analytics'): ?>
<?php require __DIR__ . '/partials/analytics.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/partials/account.php'; ?>
<?php endif; ?>
        </div>
    </main>
</div>
<?php require __DIR__ . '/partials/product-form.php'; ?>
<script>window.khanGoldPrices = <?= json_encode($goldPrices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="<?= JewelerSession::escape(JewelerSession::url('/assets/js/jeweler-dashboard.js')) ?>"></script>
<?php if (($editProduct !== null || $errorMessage !== null) && $view === 'products'): ?>
<script>document.getElementById('product-dialog')?.showModal();</script>
<?php endif; ?>
</body>
</html>
