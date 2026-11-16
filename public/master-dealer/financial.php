<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerDashboardController.php';

$controller = new MasterDealerDashboardController();
$finance = $controller->financial()->summary();
$transactions = $controller->transactions()->history();
$purchaseTotals = 0;
$buybackTotals = 0;
foreach ($transactions as $transaction) {
    if (($transaction['type'] ?? '') === 'customer_purchase') {
        $purchaseTotals += max(0, (int) ($transaction['amountMinorUnits'] ?? 0));
    } elseif (($transaction['type'] ?? '') === 'customer_buyback') {
        $buybackTotals += (int) ($transaction['payoutAmountMinorUnits'] ?? 0);
    }
}
$financialTransactions = array_values(array_filter(
    $transactions,
    static fn (array $transaction): bool => in_array($transaction['type'] ?? '', ['customer_purchase', 'customer_buyback'], true)
));

ob_start();
?>
<div class="page-grid">
    <div class="callout">This is KHAN's internal recorded ledger balance. It is not a real bank balance and does not confirm that an InstaPay transfer occurred. Buybacks are only recorded as payouts after the Master Dealer confirms the manual transfer.</div>
    <section class="stats-grid">
        <article class="stat-card"><span class="stat-label">Recorded balance</span><strong class="stat-value"><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money($finance['balanceMinorUnits'])) ?></strong><span class="stat-note">Last updated <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($finance['updatedAt'])) ?></span></article>
        <article class="stat-card"><span class="stat-label">Successful purchase inflows</span><strong class="stat-value"><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money($purchaseTotals)) ?></strong><span class="stat-note">Paymob-verified settlements</span></article>
        <article class="stat-card"><span class="stat-label">Recorded buyback payouts</span><strong class="stat-value"><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money($buybackTotals)) ?></strong><span class="stat-note">Manual payouts marked complete</span></article>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Financial ledger</h2><p>Purchase amounts increase the recorded balance; completed buybacks decrease it.</p></div></div>
        <?php if ($financialTransactions === []): ?>
            <div class="empty-state">No financial movements have been recorded.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Transaction</th><th>Type</th><th>Source</th><th>Purchase / payout amount</th><th>Balance movement</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($financialTransactions as $transaction): ?>
                <tr>
                    <td><?= MasterDealerDashboardController::escape((string) ($transaction['id'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(str_replace('_', ' ', (string) ($transaction['type'] ?? '—'))) ?></td>
                    <td><?= MasterDealerDashboardController::escape((string) ($transaction['source'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($transaction['payoutAmountMinorUnits'] ?? abs((int) ($transaction['amountMinorUnits'] ?? 0))))) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($transaction['amountMinorUnits'] ?? 0))) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($transaction['createdAt'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Financial overview';
$activePage = 'financial.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
