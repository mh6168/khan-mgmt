<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerSession.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'PaymobClient.php';

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
if ($status !== 'approved') {
    JewelerSession::redirect(match ($status) {
        'rejected' => '/jeweler/rejected.php',
        'pending' => '/jeweler/pending',
        default => '/jeweler/application',
    });
}

$subscriptionStatus = $application['fields']['subscriptionStatus']['stringValue'] ?? 'unpaid';
if ($subscriptionStatus === 'active') {
    JewelerSession::redirect('/jeweler/approved.php');
}

$profile = $firestore->getUserProfile($uid, $token);
$email = is_string($claims['email'] ?? null) ? $claims['email'] : '';
if (is_array($profile)) {
    $email = (string) ($profile['fields']['email']['stringValue'] ?? $email);
}

$fullName = (string) ($application['fields']['fullName']['stringValue'] ?? 'Jeweler');
$nameParts = preg_split('/\s+/', trim($fullName), 2) ?: ['Jeweler'];
$firstName = $nameParts[0] !== '' ? $nameParts[0] : 'Jeweler';
$lastName = $nameParts[1] ?? 'Jeweler';
$phone = (string) ($application['fields']['phone']['stringValue'] ?? '');
$error = '';

try {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
        throw new InvalidArgumentException('A valid jeweler email and phone number are required for payment.');
    }

    $checkoutUrl = PaymobClient::fromEnvironment()->createSubscriptionCheckout($uid, [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone_number' => $phone,
    ]);
    header('Location: ' . $checkoutUrl, true, 303);
    exit;
} catch (Throwable $exception) {
    error_log('Paymob subscription initiation failure: ' . get_class($exception) . '.');
    $error = isDevelopmentModeForPayment() ? $exception->getMessage() : 'The payment page could not be opened right now. Please try again.';
}

?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Subscription Payment | KHAN</title><style><?= JewelerSession::styles() ?></style></head>
<body>
<main class="card">
    <p class="eyebrow">KHAN JEWELERS</p>
    <h1>Subscription payment</h1>
    <p class="muted">The monthly subscription fee is 1,000 EGP.</p>
    <div class="errors"><?= JewelerSession::escape($error) ?></div>
    <a class="button" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/approved.php')) ?>">Return to approval page</a>
</main>
</body>
</html>
<?php

function isDevelopmentModeForPayment(): bool
{
    return strtolower((string) (getenv('APP_ENV') ?: 'production')) !== 'production'
        && filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
}
