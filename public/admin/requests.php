<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminAuth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminApplicationService.php';

AdminAuth::requireAuthenticated();
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$applications = [];
$loadError = '';

try {
    $applications = AdminApplicationService::fromEnvironment()->applications();
} catch (Throwable $exception) {
    error_log('Admin application list failure: ' . get_class($exception) . '.');
    $loadError = 'Applications are unavailable. Configure Firebase admin credentials to enable live data.';
}

$applications = array_values(array_filter($applications, static function (array $application) use ($search, $statusFilter): bool {
    $haystack = strtolower(
        (string) ($application['fullName'] ?? '') . ' ' .
        (string) ($application['_email'] ?? '') . ' ' .
        (string) ($application['_accountName'] ?? '')
    );
    $status = strtolower((string) ($application['status'] ?? ''));

    return ($search === '' || str_contains($haystack, strtolower($search))) &&
        ($statusFilter === '' || $status === $statusFilter);
}));

function requestStatusClass(string $status): string
{
    return match ($status) {
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        default => 'status-pending',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jeweler Requests | KHAN</title>
    <style><?= AdminAuth::styles() ?>.filters{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.filters label{flex:1;min-width:190px}.filters select{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #55462f;border-radius:8px;background:#121110;color:#fff;font:inherit}.filters .button{width:auto;margin:0}.table-wrap{overflow-x:auto;margin-top:24px}table{width:100%;border-collapse:collapse;min-width:760px}th,td{padding:14px 12px;border-bottom:1px solid rgba(216,170,75,.18);text-align:left;vertical-align:top}th{color:#d8aa4b;font-size:.8rem;text-transform:uppercase;letter-spacing:.08em}.status{display:inline-block;padding:5px 9px;border-radius:999px;font-size:.78rem;text-transform:capitalize}.status-approved{background:#1d3821;color:#d9f3dc}.status-rejected{background:#3a1c1b;color:#ffd9d4}.status-pending{background:#3d321d;color:#fbf2b5}.details-link{color:#d8aa4b;white-space:nowrap}</style>
</head>
<body>
<?= AdminAuth::sidebar('requests.php') ?>
<main class="admin-shell">
    <div class="admin-main">
    <p class="page-kicker">Applications</p>
    <div class="topbar">
        <div><h1>Jeweler requests</h1><p class="muted">Review applications stored in Firebase.</p></div>
        <a class="logout" href="<?= AdminAuth::escape(AdminAuth::pageUrl('logout.php')) ?>">Log out</a>
    </div>
    <section class="panel">
        <?php if ($loadError !== ''): ?><div class="errors"><?= AdminAuth::escape($loadError) ?></div><?php endif; ?>
        <form class="filters" method="get">
            <label>Search by name or email<input type="search" name="search" value="<?= AdminAuth::escape($search) ?>" placeholder="Search applications"></label>
            <label>Status
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="pending"<?= $statusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
                    <option value="approved"<?= $statusFilter === 'approved' ? ' selected' : '' ?>>Approved</option>
                    <option value="rejected"<?= $statusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
                </select>
            </label>
            <button class="button" type="submit">Filter</button>
        </form>
        <?php if ($applications === []): ?>
            <p class="muted">No applications found.</p>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Legal name</th><th>Account name</th><th>Email</th><th>Phone</th><th>Store location</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($applications as $application): $status = strtolower((string) ($application['status'] ?? 'pending')); ?>
                <tr>
                    <td><?= AdminAuth::escape((string) ($application['fullName'] ?? '—')) ?></td>
                    <td><?= AdminAuth::escape((string) ($application['_accountName'] ?? '—')) ?></td>
                    <td><?= AdminAuth::escape((string) ($application['_email'] ?? '—')) ?></td>
                    <td><?= AdminAuth::escape((string) ($application['phone'] ?? '—')) ?></td>
                    <td><?= AdminAuth::escape((string) ($application['storeAddress'] ?? '—')) ?></td>
                    <td><?= AdminAuth::escape((string) ($application['submittedAt'] ?? '—')) ?></td>
                    <td><span class="status <?= requestStatusClass($status) ?>"><?= AdminAuth::escape($status) ?></span></td>
                    <td><a class="details-link" href="<?= AdminAuth::escape(AdminAuth::pageUrl('application.php')) ?>?uid=<?= rawurlencode((string) ($application['uid'] ?? $application['id'] ?? '')) ?>">View details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    </div>
</main>
</body>
</html>
