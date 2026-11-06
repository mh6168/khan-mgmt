<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerSession.php';

$claims = JewelerSession::requireAuthenticated();
$uid = JewelerSession::uid($claims);
$token = (string) $_SESSION['firebase_id_token'];
$firestore = new FirestoreClient((string) (getenv('FIREBASE_PROJECT_ID') ?: ''), (int) (getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: 10));
$application = $firestore->getApplication($uid, $token);

if ($application === null) {
    JewelerSession::redirect('/jeweler/application');
}

$status = $application['fields']['status']['stringValue'] ?? 'pending';
if ($status !== 'rejected') {
    JewelerSession::redirect(match ($status) {
        'approved' => '/jeweler/approved.php',
        'pending' => '/jeweler/pending',
        default => '/jeweler/application',
    });
}

$reason = (string) ($application['fields']['rejectionReason']['stringValue'] ?? '');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Status | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Application status</h1>
    <div class="status status-rejected"><span class="status-dot"></span> Rejected</div>
    <p class="muted">Unfortunately, your application has been rejected.</p>
    <p class="muted">Your application was not approved at this time. Please contact the site administrators for more information.</p>
    <?php if ($reason !== ''): ?><p class="muted"><strong>Reason:</strong> <?= JewelerSession::escape($reason) ?></p><?php endif; ?>
    <p class="muted">Resubmitting an application is not available. Please contact the KHAN site administrators if you need assistance.</p>
    <a class="button secondary" href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
</main>
</body>
</html>
