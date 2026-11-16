<?php

declare(strict_types=1);

$navigation = [
    'dashboard.php' => ['Overview', '⌂'],
    'pricing.php' => ['Live prices', '◈'],
    'inventory.php' => ['Inventory', '▤'],
    'buybacks.php' => ['Buyback requests', '↩'],
    'financial.php' => ['Financial overview', '◉'],
    'transactions.php' => ['Transactions', '⇄'],
];
$title = is_string($pageTitle ?? null) ? $pageTitle : 'Master Dealer';
$active = is_string($activePage ?? null) ? $activePage : 'dashboard.php';
$content = is_string($content ?? null) ? $content : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= MasterDealerDashboardController::escape($title) ?> | KHAN</title>
    <link rel="stylesheet" href="<?= MasterDealerAuth::escape(MasterDealerAuth::assetUrl('css/master-dealer-dashboard.css')) ?>">
    <script defer src="<?= MasterDealerAuth::escape(MasterDealerAuth::assetUrl('js/master-dealer-dashboard.js')) ?>"></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url('dashboard.php')) ?>">
        <span class="brand-mark">K</span>
        <span><strong>KHAN</strong><small>Master Dealer</small></span>
    </a>
    <p class="nav-label">Workspace</p>
    <nav class="sidebar-nav" aria-label="Master Dealer">
        <?php foreach ($navigation as $page => [$label, $icon]): ?>
            <a class="<?= $active === $page ? 'active' : '' ?>" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url($page)) ?>">
                <span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= MasterDealerDashboardController::escape($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span class="online-dot"></span> Operator session
        <form method="post" action="<?= MasterDealerAuth::escape(MasterDealerAuth::url('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= MasterDealerAuth::escape(MasterDealerAuth::csrfToken()) ?>">
            <button type="submit">Log out</button>
        </form>
    </div>
</aside>
<main class="main-shell">
    <header class="topbar">
        <div>
            <p class="page-kicker">KHAN / MASTER DEALER</p>
            <h1><?= MasterDealerDashboardController::escape($title) ?></h1>
        </div>
        <div class="user-chip"><span class="online-dot"></span><?= MasterDealerDashboardController::escape((string) ($_SESSION['master_dealer_email'] ?? '')) ?></div>
    </header>
    <?= $content ?>
</main>
</body>
</html>
