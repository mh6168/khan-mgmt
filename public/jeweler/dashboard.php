<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerSession.php';

$claims = JewelerSession::requireAuthenticated();
$uid = JewelerSession::uid($claims);
$token = (string) $_SESSION['firebase_id_token'];
$firestore = new FirestoreClient(
    (string) (getenv('FIREBASE_PROJECT_ID') ?: ''),
    (int) (getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: 10)
);
$application = $firestore->getApplication($uid, $token);

if ($application === null) {
    JewelerSession::redirect('/jeweler/application');
}

$status = $application['fields']['status']['stringValue'] ?? 'pending';
$subscriptionStatus = $application['fields']['subscriptionStatus']['stringValue'] ?? 'unpaid';
if ($status !== 'approved' || $subscriptionStatus !== 'active') {
    JewelerSession::redirect('/jeweler/approved.php');
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Jeweler Dashboard | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Coming Soon</h1>
    <p class="muted">Your jeweler dashboard is being prepared.</p>
    <a class="button" href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
</main>
</body>
</html>
