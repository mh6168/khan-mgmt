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
$subscriptionExpiresAt = $application['fields']['subscriptionExpiresAt']['stringValue'] ?? '';
$subscriptionIsCurrent = false;
if ($subscriptionStatus === 'active' && is_string($subscriptionExpiresAt) && $subscriptionExpiresAt !== '') {
    try {
        $subscriptionIsCurrent = new DateTimeImmutable($subscriptionExpiresAt) > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Exception) {
        $subscriptionIsCurrent = false;
    }
}

if ($status !== 'approved' || !$subscriptionIsCurrent) {
    JewelerSession::redirect('/jeweler/approved.php');
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Jeweler Dashboard | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Jeweler Dashboard</h1>
    <p class="muted">Coming Soon</p>
    <a class="button" href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
</main>
</body>
</html>
