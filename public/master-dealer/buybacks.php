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
        try {
            $id = (string) ($_POST['request_id'] ?? '');
            $action = (string) ($_POST['action'] ?? '');
            $changed = match ($action) {
                'money_transferred' => $controller->buybacks()->complete($id),
                default => throw new InvalidArgumentException('Choose a valid buyback action.'),
            };
            $notice = $changed
                ? 'Money transfer confirmed. Customer holdings, inventory, and transaction history were updated once.'
                : 'This request was already updated; no duplicate inventory or ledger change was made.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}
$requests = $controller->buybacks()->requests();
$selectedId = (string) ($_GET['request_id'] ?? $_POST['request_id'] ?? '');
$selected = $selectedId !== '' ? $controller->buybacks()->request($selectedId) : null;
$csrfToken = MasterDealerAuth::csrfToken();

ob_start();
?>
<div class="page-grid">
    <?php if ($notice !== ''): ?><div class="notice" role="status"><?= MasterDealerDashboardController::escape($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= MasterDealerDashboardController::escape($error) ?></div><?php endif; ?>
    <div class="callout">Verify the manual InstaPay payout before confirming it. Processing requests are settled atomically: customer holdings and reservations are released, inventory is increased, and one completed buyback transaction is recorded.</div>
    <?php if (is_array($selected)): ?>
        <section class="panel">
            <div class="panel-heading"><div><h2>Buyback request details</h2><p>Request <?= MasterDealerDashboardController::escape((string) $selected['id']) ?></p></div><span class="status <?= MasterDealerDashboardController::escape((string) ($selected['status'] ?? 'processing')) ?>"><?= MasterDealerDashboardController::escape((string) ($selected['status'] ?? 'processing')) ?></span></div>
            <div class="detail-grid">
                <div class="detail"><span>Customer name</span><strong><?= MasterDealerDashboardController::escape((string) ($selected['customerName'] ?? '—')) ?></strong></div>
                <div class="detail"><span>Customer email</span><strong><?= MasterDealerDashboardController::escape((string) ($selected['customerEmail'] ?? 'Not provided')) ?></strong></div>
                <div class="detail"><span>Customer phone</span><strong><?= MasterDealerDashboardController::escape((string) ($selected['customerPhone'] ?? '—')) ?></strong></div>
                <div class="detail"><span>Customer UID</span><strong><?= MasterDealerDashboardController::escape((string) ($selected['customerUid'] ?? '—')) ?></strong></div>
                <div class="detail"><span>InstaPay phone</span><strong><?= MasterDealerDashboardController::escape((string) ($selected['instapayPhone'] ?? '—')) ?></strong></div>
                <div class="detail"><span>Submitted</span><strong><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($selected['createdAt'] ?? null)) ?></strong></div>
                <div class="detail"><span>Liras</span><strong><?= MasterDealerDashboardController::quantity($selected['liraQuantity'] ?? 0) ?> × <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($selected['liraUnitPriceMinor'] ?? 0))) ?></strong></div>
                <div class="detail"><span>Paras</span><strong><?= MasterDealerDashboardController::quantity($selected['paraQuantity'] ?? 0) ?> × <?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($selected['paraUnitPriceMinor'] ?? 0))) ?></strong></div>
                <div class="detail"><span>Locked total payout</span><strong><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($selected['totalPayoutMinor'] ?? 0))) ?></strong></div>
                <div class="detail"><span>Transferred</span><strong><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($selected['transferredAt'] ?? null)) ?></strong></div>
            </div>
            <?php if (($selected['status'] ?? '') === 'processing'): ?>
                <form method="post" class="form-actions" data-confirm="Only continue after checking the InstaPay transfer recipient and amount.">
                    <input type="hidden" name="csrf_token" value="<?= MasterDealerAuth::escape($csrfToken) ?>">
                    <input type="hidden" name="request_id" value="<?= MasterDealerAuth::escape((string) $selected['id']) ?>">
                    <button type="submit" name="action" value="money_transferred">Mark as Money Transferred</button>
                </form>
            <?php endif; ?>
        </section>
    <?php elseif ($selectedId !== ''): ?>
        <div class="error">That buyback request was not found.</div>
    <?php endif; ?>
    <section class="panel">
        <div class="panel-heading"><div><h2>Customer buyback requests</h2><p>Prices and totals are locked at customer submission time.</p></div></div>
        <?php if ($requests === []): ?>
            <div class="empty-state">No customer buyback requests have been submitted.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Request</th><th>Customer</th><th>Liras</th><th>Paras</th><th>InstaPay phone</th><th>Total payout</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($requests as $request): $status = (string) ($request['status'] ?? 'processing'); ?>
                <tr<?= $status === 'processing' ? ' class="processing-request"' : '' ?>>
                    <td><?= MasterDealerDashboardController::escape(substr((string) ($request['id'] ?? ''), 0, 10)) ?></td>
                    <td><strong><?= MasterDealerDashboardController::escape((string) ($request['customerName'] ?? '—')) ?></strong><br><span><?= MasterDealerDashboardController::escape((string) ($request['customerEmail'] ?? $request['customerUid'] ?? '—')) ?></span></td>
                    <td><?= MasterDealerDashboardController::quantity($request['liraQuantity'] ?? 0) ?></td>
                    <td><?= MasterDealerDashboardController::quantity($request['paraQuantity'] ?? 0) ?></td>
                    <td><?= MasterDealerDashboardController::escape((string) ($request['instapayPhone'] ?? '—')) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::money((int) ($request['totalPayoutMinor'] ?? 0))) ?></td>
                    <td><?= MasterDealerDashboardController::escape(MasterDealerDashboardController::date($request['createdAt'] ?? null)) ?></td>
                    <td><span class="status <?= MasterDealerDashboardController::escape($status) ?>"><?= MasterDealerDashboardController::escape($status) ?></span></td>
                    <td><a class="button secondary small" href="<?= MasterDealerAuth::escape(MasterDealerAuth::url('buybacks.php')) ?>?request_id=<?= rawurlencode((string) ($request['id'] ?? '')) ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
$pageTitle = 'Buyback requests';
$activePage = 'buybacks.php';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'master-dealer' . DIRECTORY_SEPARATOR . 'layout.php';
