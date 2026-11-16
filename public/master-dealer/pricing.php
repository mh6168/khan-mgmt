<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerDashboardController.php';

$controller = new MasterDealerDashboardController();
$prices = $controller->pricing()->latest();
$priceText = static fn (?float $value): string => $value === null
    ? 'Unavailable'
    : MasterDealerDashboardController::money((int) round($value * 100));

ob_start();
?>
<div class="page-grid">
    <div class="callout">Selling prices add 5 EGP to spot; customer buyback prices add 1 EGP. Gold and silver are fetched through the existing GoldAPI service and cached independently. If a market price is unavailable, no price is invented.</div>
    <section class="price-grid">
        <article class="price-card">
            <h2>Lira · 0.01 grams of 24K gold</h2>
            <span class="price-value"><?= MasterDealerDashboardController::escape($priceText($prices['lira']['selling'])) ?></span>
            <div class="price-row"><span>24K gold spot · per gram</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['gold24kPerGram'])) ?></strong></div>
            <div class="price-row"><span>Spot value · 0.01 g</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['lira']['spot'])) ?></strong></div>
            <div class="price-row"><span>Customer selling price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['lira']['selling'])) ?></strong></div>
            <div class="price-row"><span>Customer buyback price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['lira']['buyback'])) ?></strong></div>
            <p class="muted">Last update: <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($prices['goldUpdatedAt'])) ?></p>
        </article>
        <article class="price-card">
            <h2>Para · 1 gram of 925 silver</h2>
            <span class="price-value"><?= MasterDealerDashboardController::escape($priceText($prices['para']['selling'])) ?></span>
            <div class="price-row"><span>Silver spot adjusted to 925 · per gram</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['silver925PerGram'])) ?></strong></div>
            <div class="price-row"><span>Customer selling price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['para']['selling'])) ?></strong></div>
            <div class="price-row"><span>Customer buyback price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices['para']['buyback'])) ?></strong></div>
            <p class="muted">Last update: <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($prices['silverUpdatedAt'])) ?></p>
        </article>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Price availability</h2><p>GoldAPI source: <?= MasterDealerDashboardController::escape($prices['source']) ?></p></div></div>
        <div class="detail-grid">
            <div class="detail"><span>Gold quote</span><strong><?= $prices['lira']['spot'] === null ? 'Unavailable' : 'Available' ?></strong></div>
            <div class="detail"><span>Silver quote</span><strong><?= $prices['para']['spot'] === null ? 'Unavailable' : 'Available' ?></strong></div>
        </div>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Live prices';
$activePage = 'pricing.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
