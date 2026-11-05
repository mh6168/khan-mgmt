<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminAuth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminApplicationService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';

AdminAuth::requireAuthenticated();
$uid = trim((string) ($_GET['uid'] ?? $_POST['uid'] ?? ''));
$message = '';
$error = '';
$application = null;

try {
    $service = AdminApplicationService::fromEnvironment();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!AdminAuth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new InvalidArgumentException('The security token is invalid. Refresh and try again.');
        }
        $requestedStatus = (string) ($_POST['status'] ?? '');
        if (!in_array($requestedStatus, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('The requested application status is invalid.');
        }
        $service->updateStatus($uid, $requestedStatus);
        $message = 'Application status updated to ' . $requestedStatus . '.';
    }
    $application = $service->application($uid);
    if ($application === null) {
        $error = 'The requested application was not found.';
    }
} catch (Throwable $exception) {
    error_log('Admin application details failure: ' . get_class($exception) . '.');
    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Application details are unavailable.';
}

$documents = [
    'nationalIdPath' => 'بطاقة الرقم القومي',
    'commercialRegisterPath' => 'السجل التجاري',
    'taxCardPath' => 'البطاقة الضريبية',
    'goldLicensePath' => 'ترخيص مزاولة مهنة تجارة الذهب والمعادن الثمينة',
];
$documentLinks = [];
if (is_array($application)) {
    try {
        $storage = new SupabaseStorageClient(
            (string) (getenv('SUPABASE_URL') ?: ''),
            (string) (getenv('SUPABASE_STORAGE_BUCKET') ?: ''),
            (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ''),
            (int) (getenv('SUPABASE_TIMEOUT_SECONDS') ?: 10)
        );
        foreach ($documents as $field => $label) {
            $path = (string) ($application[$field] ?? '');
            if ($path !== '') {
                $documentLinks[$field] = ['label' => $label, 'url' => $storage->createSignedUrl($path, 600)];
            }
        }
    } catch (Throwable $exception) {
        error_log('Admin document link failure: ' . get_class($exception) . '.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application | KHAN</title>
    <style><?= AdminAuth::styles() ?>.notice{padding:12px 16px;border:1px solid #5d8a62;border-radius:8px;background:#1d3821;color:#d9f3dc}.detail-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.detail{padding:14px;border:1px solid #55462f;border-radius:8px}.detail strong{display:block;color:#d8aa4b;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.documents{display:grid;gap:12px}.document{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px;border:1px solid #55462f;border-radius:8px}.document a{color:#d8aa4b}.actions{display:flex;gap:12px;flex-wrap:wrap}.actions button{width:auto;margin:0}.reject{background:#7d3b36;color:#fff}@media(max-width:640px){.detail-grid{grid-template-columns:1fr}.document{display:block}.document a{display:inline-block;margin-top:8px}}</style>
</head>
<body>
<?= AdminAuth::sidebar('requests.php') ?>
<main class="admin-shell">
    <div class="admin-main">
    <p class="page-kicker">Applications / Details</p>
    <div class="topbar">
        <div><h1>Jeweler application</h1><p class="muted">Review application information and submitted legal documents.</p></div>
        <a class="logout" href="<?= AdminAuth::escape(AdminAuth::pageUrl('logout.php')) ?>">Log out</a>
    </div>
    <section class="panel">
        <?php if ($message !== ''): ?><p class="notice"><?= AdminAuth::escape($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><div class="errors"><?= AdminAuth::escape($error) ?></div><?php endif; ?>
        <?php if (is_array($application)): ?>
            <div class="detail-grid">
                <?php foreach (['fullName' => 'Legal name', '_accountName' => 'Account name', '_email' => 'Email', 'phone' => 'Phone', 'storeName' => 'Store name', 'storeAddress' => 'Store location', 'submittedAt' => 'Submitted', 'status' => 'Status'] as $field => $label): ?>
                    <div class="detail"><strong><?= AdminAuth::escape($label) ?></strong><?= AdminAuth::escape((string) ($application[$field] ?? '—')) ?></div>
                <?php endforeach; ?>
            </div>
            <h2>Submitted documents</h2>
            <div class="documents">
                <?php foreach ($documents as $field => $label): ?>
                    <div class="document"><span><?= AdminAuth::escape($label) ?> — <?= isset($documentLinks[$field]) ? 'Available' : 'Not available' ?></span>
                    <?php if (isset($documentLinks[$field])): ?><span><a target="_blank" rel="noopener" href="<?= AdminAuth::escape($documentLinks[$field]['url']) ?>">Preview</a> · <a download href="<?= AdminAuth::escape($documentLinks[$field]['url']) ?>">Download</a></span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
            <h2>Application action</h2>
            <form class="actions" method="post" onsubmit="return confirm('Confirm this application status change?');">
                <input type="hidden" name="uid" value="<?= AdminAuth::escape($uid) ?>">
                <input type="hidden" name="csrf_token" value="<?= AdminAuth::escape(AdminAuth::csrfToken()) ?>">
                <button class="button" type="submit" name="status" value="approved">Approve application</button>
                <button class="button reject" type="submit" name="status" value="rejected">Reject application</button>
            </form>
        <?php endif; ?>
    </section>
    </div>
</main>
</body>
</html>
