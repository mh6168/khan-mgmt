<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerDashboardController.php';

$controller = new MasterDealerDashboardController();
$allTransactions = $controller->transactions()->history();
$typeFilter = (string) ($_GET['type'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$allowedTypes = ['', 'customer_purchase', 'customer_buyback', 'inventory_adjustment'];
$allowedStatuses = ['', 'completed', 'pending', 'processing', 'expired'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$transactions = array_values(array_filter(
    $allTransactions,
    static fn (array $transaction): bool => ($typeFilter === '' || ($transaction['type'] ?? '') === $typeFilter)
        && ($statusFilter === '' || ($transaction['status'] ?? '') === $statusFilter)
));

ob_start();
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Transaction history</h2><p>Customer purchases, completed buybacks, and manual inventory adjustments.</p></div></div>
    <form method="get" class="filter-row">
        <label>Transaction type<select name="type">
            <?php foreach (['' => 'All types', 'customer_purchase' => 'Customer purchase', 'customer_buyback' => 'Customer buyback', 'inventory_adjustment' => 'Inventory adjustment'] as $value => $label): ?>
                <option value="<?= MasterDealerDashboardController::escape($value) ?>"<?= $typeFilter === $value ? ' selected' : '' ?>><?= MasterDealerDashboardController::escape($label) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Status<select name="status">
            <?php foreach (['' => 'All statuses', 'completed' => 'Completed', 'pending' => 'Pending', 'processing' => 'Processing', 'expired' => 'Expired'] as $value => $label): ?>
                <option value="<?= MasterDealerDashboardController::escape($value) ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= MasterDealerDashboardController::escape($label) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button type="submit">Apply filters</button>
        <a class="button secondary" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url('transactions.php')) ?>">Clear</a>
    </form>
    <?php if ($transactions === []): ?>
        <div class="empty-state">No transactions match the selected filters.</div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Transaction ID</th><th>Type</th><th>Customer / source</th><th>Lira qty</th><th>Para qty</th><th>EGP amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($transactions as $transaction): $status = (string) ($transaction['status'] ?? ''); ?>
            <tr>
                <td><?= MasterDealerDashboardController::escape((string) ($transaction['id'] ?? '—')) ?></td>
                <td><?= MasterDealerDashboardController::escape(str_replace('_', ' ', (string) ($transaction['type'] ?? '—'))) ?></td>
                <td><?= MasterDealerDashboardController::escape((string) ($transaction['customerUid'] ?? $transaction['source'] ?? '—')) ?></td>
                <td><?= MasterDealerDashboardController::quantity($transaction['liraQuantity'] ?? 0) ?></td>
                <td><?= MasterDealerDashboardController::quantity($transaction['paraQuantity'] ?? 0) ?></td>
                <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($transaction['amountMinorUnits'] ?? 0))) ?></td>
                <td><span class="status <?= MasterDealerDashboardController::escape($status) ?>"><?= MasterDealerDashboardController::escape($status !== '' ? $status : '—') ?></span></td>
                <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($transaction['createdAt'] ?? null)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Transaction history';
$activePage = 'transactions.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
