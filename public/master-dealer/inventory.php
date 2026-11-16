<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerDashboardController.php';

$controller = new MasterDealerDashboardController();
$error = '';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!MasterDealerAuth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $error = 'Your session could not be verified. Reload the page and try again.';
    } else {
        $deltaValue = filter_var($_POST['delta'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($deltaValue === false) {
                throw new InvalidArgumentException('Inventory change must be a whole number.');
            }
            $changed = $controller->inventory()->adjust(
                (string) ($_POST['asset'] ?? ''),
                $deltaValue,
                (string) ($_POST['note'] ?? ''),
                (string) ($_POST['operation_key'] ?? '')
            );
            MasterDealerDashboardController::rotateOperationKey('inventory');
            $notice = $changed
                ? 'Inventory was adjusted and the movement recorded.'
                : 'This adjustment was already applied; inventory was not changed again.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$controller->transactions()->expirePendingOrders();
$inventory = $controller->inventory()->summary();
$movements = $controller->inventory()->movements();
$tokens = MasterDealerDashboardController::formTokens('inventory');

ob_start();
?>
<div class="page-grid">
    <?php if ($notice !== ''): ?><div class="notice" role="status"><?= MasterDealerDashboardController::escape($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= MasterDealerDashboardController::escape($error) ?></div><?php endif; ?>
    <section class="stats-grid">
        <?php foreach (['lira' => 'Liras', 'para' => 'Paras'] as $asset => $label): ?>
            <article class="stat-card"><span class="stat-label"><?= $label ?> on hand</span><strong class="stat-value"><?= MasterDealerDashboardController::quantity($inventory[$asset]['quantity']) ?></strong><span class="stat-note"><?= MasterDealerDashboardController::quantity($inventory[$asset]['available']) ?> available · <?= MasterDealerDashboardController::quantity($inventory[$asset]['reserved']) ?> reserved</span></article>
        <?php endforeach; ?>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Record an inventory adjustment</h2><p>Positive values add stock; negative values reduce unreserved stock. Every adjustment creates a ledger movement.</p></div></div>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= MasterDealerAuth::escape($tokens['csrfToken']) ?>">
            <input type="hidden" name="operation_key" value="<?= MasterDealerAuth::escape($tokens['operationKey']) ?>">
            <label>Asset<select name="asset" required><option value="lira">Liras</option><option value="para">Paras</option></select></label>
            <label>Quantity change<input type="number" name="delta" step="1" min="-1000000" max="1000000" required placeholder="e.g. 25 or -2"></label>
            <label class="wide">Reason<input type="text" name="note" maxlength="300" required placeholder="e.g. Opening stock count correction"></label>
            <div class="form-actions wide"><button type="submit">Save adjustment</button></div>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Inventory movement history</h2><p>Manual changes, purchase settlements, and completed buybacks are recorded in the transaction ledger.</p></div></div>
        <?php if ($movements === []): ?>
            <div class="empty-state">No manual inventory adjustments have been recorded.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Transaction</th><th>Type</th><th>Lira change</th><th>Para change</th><th>Note / source</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><?= MasterDealerDashboardController::escape((string) ($movement['id'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(str_replace('_', ' ', (string) ($movement['type'] ?? '—'))) ?></td>
                    <?php foreach (['liraQuantity', 'paraQuantity'] as $quantityField): $change = (int) ($movement[$quantityField] ?? 0); ?>
                        <td><?= $change > 0 ? '+' : '' ?><?= MasterDealerDashboardController::quantity($change) ?></td>
                    <?php endforeach; ?>
                    <td><?= MasterDealerDashboardController::escape((string) ($movement['note'] ?? $movement['source'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($movement['createdAt'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Inventory management';
$activePage = 'inventory.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
