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
if ($status !== 'approved') {
    JewelerSession::redirect(match ($status) {
        'rejected' => '/jeweler/rejected.php',
        'pending' => '/jeweler/pending',
        default => '/jeweler/application',
    });
}

$name = (string) ($application['fields']['fullName']['stringValue'] ?? 'Jeweler');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Approved Jeweler | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Congratulations, <?= JewelerSession::escape($name) ?>!</h1>
    <p class="muted">Your application has been approved.</p>
    <div class="status status-approved"><span class="status-dot"></span> Approved</div>
    <p class="muted">Your jeweler account is now active and ready for the next stage of your KHAN journey.</p>
    <section class="placeholder"><h2>Your Jeweler Dashboard is Coming Soon</h2><p class="muted">We are preparing the tools you will use to manage your jeweler account.</p></section>
    <a class="button" href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
</main>
</body>
</html>
