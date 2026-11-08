    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">KHAN</div><small>Jeweler workspace</small></div>
        <div class="mobile-brand">KHAN</div>
        <nav class="nav-list" aria-label="Jeweler dashboard">
            <?php foreach (['overview' => ['Overview', '⌂'], 'products' => ['My Products', '◇'], 'reservations' => ['Reservations', '▤'], 'analytics' => ['Sales & Analytics', '▥'], 'account' => ['Account', '○']] as $navView => [$label, $icon]): ?>
                <a href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=' . $navView)) ?>" <?= $view === $navView ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= JewelerSession::escape($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <p><?= JewelerSession::escape($applicationValue('storeName', $applicationValue('fullName', 'Jeweler account'))) ?><br>Subscription <?= JewelerSession::escape(strtolower($subscriptionStatusLabel)) ?></p>
            <a href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
        </div>
    </aside>
