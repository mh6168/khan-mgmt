<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerDashboardController.php';

$controller = new MasterDealerDashboardController();
$controller->transactions()->expirePendingOrders();
$inventory = $controller->inventory()->summary();
$prices = $controller->pricing()->latest();
$finance = $controller->financial()->summary();
$buybacks = $controller->buybacks()->requests();
$transactions = $controller->transactions()->history();
$pendingBuybacks = count(array_filter(
    $buybacks,
    static fn (array $request): bool => in_array($request['status'] ?? '', ['pending', 'processing'], true)
));
$completedPurchases = count(array_filter(
    $transactions,
    static fn (array $transaction): bool => ($transaction['type'] ?? '') === 'customer_purchase'
        && ($transaction['status'] ?? '') === 'completed'
));
$completedBuybacks = count(array_filter(
    $transactions,
    static fn (array $transaction): bool => ($transaction['type'] ?? '') === 'customer_buyback'
        && ($transaction['status'] ?? '') === 'completed'
));
$priceText = static fn (?float $value): string => $value === null
    ? 'Unavailable'
    : MasterDealerDashboardController::money((int) round($value * 100));
$recentTransactions = array_slice($transactions, 0, 8);

ob_start();
?>
<div class="page-grid">
    <section class="stats-grid" aria-label="Master Dealer overview">
        <article class="stat-card"><span class="stat-label">Available Liras</span><strong class="stat-value"><?= MasterDealerDashboardController::quantity($inventory['lira']['available']) ?></strong><span class="stat-note"><?= MasterDealerDashboardController::quantity($inventory['lira']['reserved']) ?> reserved for active orders</span></article>
        <article class="stat-card"><span class="stat-label">Available Paras</span><strong class="stat-value"><?= MasterDealerDashboardController::quantity($inventory['para']['available']) ?></strong><span class="stat-note"><?= MasterDealerDashboardController::quantity($inventory['para']['reserved']) ?> reserved for active orders</span></article>
        <article class="stat-card"><span class="stat-label">Recorded account balance</span><strong class="stat-value"><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money($finance['balanceMinorUnits'])) ?></strong><span class="stat-note">Internal KHAN ledger balance, not a bank balance</span></article>
        <article class="stat-card"><span class="stat-label">Pending buybacks</span><strong class="stat-value"><?= $pendingBuybacks ?></strong><span class="stat-note">Requests awaiting payout/completion</span></article>
        <article class="stat-card"><span class="stat-label">Completed purchases</span><strong class="stat-value"><?= $completedPurchases ?></strong><span class="stat-note">Verified customer payments</span></article>
        <article class="stat-card"><span class="stat-label">Completed buybacks</span><strong class="stat-value"><?= $completedBuybacks ?></strong><span class="stat-note">Recorded InstaPay payouts</span></article>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2>Live customer prices</h2><p>Price quotes use the latest available GoldAPI values. Unavailable inputs are not estimated.</p></div><a class="button secondary small" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url('pricing.php')) ?>">View pricing</a></div>
        <div class="price-grid">
            <?php foreach (['lira' => 'Lira · 0.01 g 24K gold', 'para' => 'Para · 1 g 925 silver'] as $asset => $label): ?>
                <article class="price-card">
                    <h2><?= MasterDealerDashboardController::escape($label) ?></h2>
                    <div class="price-row"><span>Spot value</span><strong><?= MasterDealerDashboardController::escape($priceText($prices[$asset]['spot'])) ?></strong></div>
                    <div class="price-row"><span>Customer selling price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices[$asset]['selling'])) ?></strong></div>
                    <div class="price-row"><span>Customer buyback price</span><strong><?= MasterDealerDashboardController::escape($priceText($prices[$asset]['buyback'])) ?></strong></div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="muted">Gold updated: <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($prices['goldUpdatedAt'])) ?> · Silver updated: <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($prices['silverUpdatedAt'])) ?> · Source: <?= MasterDealerDashboardController::escape($prices['source']) ?></p>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2>Recent activity</h2><p>Purchases, buybacks, and inventory movements recorded in the ledger.</p></div><a class="button secondary small" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url('transactions.php')) ?>">All transactions</a></div>
        <?php if ($recentTransactions === []): ?>
            <div class="empty-state">No Master Dealer transactions have been recorded yet.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Transaction</th><th>Type</th><th>Customer / source</th><th>Liras</th><th>Paras</th><th>EGP movement</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($recentTransactions as $item): ?>
                <tr>
                    <td><?= MasterDealerDashboardController::escape((string) ($item['id'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(str_replace('_', ' ', (string) ($item['type'] ?? '—'))) ?></td>
                    <td><?= MasterDealerDashboardController::escape((string) ($item['customerUid'] ?? $item['source'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::quantity($item['liraQuantity'] ?? 0) ?></td>
                    <td><?= MasterDealerDashboardController::quantity($item['paraQuantity'] ?? 0) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($item['amountMinorUnits'] ?? 0))) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($item['createdAt'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Overview';
$activePage = 'dashboard.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
