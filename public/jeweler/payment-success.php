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

$transactionId = trim((string) ($_GET['id'] ?? $_GET['transaction_id'] ?? ''));
$storedTransactionId = (string) ($application['fields']['paymentTransactionId']['stringValue'] ?? '');
$subscriptionStatus = (string) ($application['fields']['subscriptionStatus']['stringValue'] ?? 'unpaid');

try {
    if ($transactionId === '') {
        throw new InvalidArgumentException('The payment transaction was not provided.');
    }

    if ($subscriptionStatus === 'active') {
        if ($storedTransactionId === $transactionId) {
            JewelerSession::redirect('/jeweler/dashboard.php');
        }

        throw new PaymobException('This jeweler already has an active subscription.');
    }

    $transaction = PaymobClient::fromEnvironment()->verifyTransaction($transactionId);
    if (!is_bool($transaction['success'] ?? null) || $transaction['success'] !== true ||
        ($transaction['is_captured'] ?? true) !== true
    ) {
        throw new PaymobException('Paymob did not confirm this payment as successful.');
    }

    if ((int) ($transaction['amount_cents'] ?? 0) !== PaymobClient::SUBSCRIPTION_AMOUNT_CENTS ||
        strtoupper((string) ($transaction['currency'] ?? '')) !== 'EGP'
    ) {
        throw new PaymobException('The verified payment amount is invalid.');
    }

    $verifiedTransactionId = (string) ($transaction['id'] ?? '');
    if ($verifiedTransactionId !== $transactionId) {
        throw new PaymobException('Paymob returned a different transaction.');
    }

    $merchantOrderId = (string) ($transaction['order']['merchant_order_id'] ?? '');
    if ($merchantOrderId === '' || !str_starts_with($merchantOrderId, 'khan-' . $uid . '-')) {
        throw new PaymobException('The payment does not belong to this jeweler.');
    }

    $paidAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $firestore->updateSubscription($uid, $token, [
        'subscriptionStatus' => 'active',
        'subscriptionAmount' => 1000,
        'subscriptionPaidAt' => $paidAt->format(DateTimeInterface::ATOM),
        'subscriptionExpiresAt' => $paidAt->modify('+30 days')->format(DateTimeInterface::ATOM),
        'paymentTransactionId' => $transactionId,
    ]);

    JewelerSession::redirect('/jeweler/dashboard.php');
} catch (Throwable $exception) {
    error_log('Paymob payment verification failure: ' . get_class($exception) . '.');
    JewelerSession::redirect('/jeweler/payment-failed.php');
}
