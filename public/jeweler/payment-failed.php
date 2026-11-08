<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerSession.php';

JewelerSession::requireAuthenticated();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment Not Completed | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Payment was not completed</h1>
    <p class="muted">Your payment was cancelled or unsuccessful. Your jeweler approval remains active, and no subscription was activated.</p>
    <a class="button" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/approved.php')) ?>">Return to approved page</a>
</main>
</body>
</html>
