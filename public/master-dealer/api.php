<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerTransactionService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerBuybackService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerPaymentService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? '0', FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength > 16384) {
    sendMasterDealerApiError('Request body is too large.', 413);
}

$bodyText = file_get_contents('php://input');
if ($bodyText === false) {
    sendMasterDealerApiError('Request body could not be read.', 400);
}

try {
    $body = json_decode($bodyText, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendMasterDealerApiError('Request body must be valid JSON.', 400);
}
if (!is_array($body)) {
    sendMasterDealerApiError('Request body must be a JSON object.', 400);
}

$action = (string) ($_GET['action'] ?? '');

try {
    $claims = in_array($action, ['purchase-order', 'buyback'], true)
        ? requireMasterDealerCustomerClaims()
        : null;
    try {
        $store = MasterDealerStore::fromEnvironment();
    } catch (InvalidArgumentException $exception) {
        error_log('Master Dealer API Firestore configuration failure: ' . $exception->getMessage());
        sendMasterDealerApiError('The transaction service is not configured.', 503);
    }
    $pricing = new MasterDealerPricingService();
    $transactions = new MasterDealerTransactionService($store, $pricing);

    if ($action === 'purchase-order' || $action === 'buyback') {
        $uid = (string) ($claims['sub'] ?? '');

        if ($action === 'purchase-order') {
            $order = $transactions->createPurchaseOrder(
                $uid,
                requireNonNegativeInteger($body['liraQuantity'] ?? null, 'Lira quantity'),
                requireNonNegativeInteger($body['paraQuantity'] ?? null, 'Para quantity'),
                requireIdempotencyKey()
            );
            http_response_code(201);
            echo json_encode([
                'purchaseOrderId' => $order['id'],
                'merchantOrderId' => $order['merchantOrderId'],
                'amountPiasters' => $order['amountPiasters'],
                'currency' => $order['currency'],
                'expiresAt' => $order['expiresAt'],
                'status' => $order['status'],
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $buybackService = new MasterDealerBuybackService($store, $pricing);
        $request = $buybackService->submit(
            $uid,
            is_string($claims['email'] ?? null) ? $claims['email'] : '',
            requireNonNegativeInteger($body['liraQuantity'] ?? null, 'Lira quantity'),
            requireNonNegativeInteger($body['paraQuantity'] ?? null, 'Para quantity'),
            is_string($body['instaPayPhone'] ?? null) ? $body['instaPayPhone'] : '',
            requireIdempotencyKey()
        );
        http_response_code(201);
        echo json_encode([
            'requestId' => $request['id'],
            'status' => $request['status'],
            'liraQuantity' => $request['liraQuantity'],
            'paraQuantity' => $request['paraQuantity'],
            'liraUnitPriceMinor' => $request['liraUnitPriceMinor'],
            'paraUnitPriceMinor' => $request['paraUnitPriceMinor'],
            'totalAmountMinorUnits' => $request['totalAmountMinorUnits'],
            'currency' => $request['currency'],
            'submittedAt' => $request['submittedAt'],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'confirm-payment') {
        $orderId = is_string($body['purchaseOrderId'] ?? null) ? $body['purchaseOrderId'] : '';
        $transactionId = is_string($body['paymobTransactionId'] ?? null) ? $body['paymobTransactionId'] : '';
        $payment = MasterDealerPaymentService::fromEnvironment($transactions);
        $processed = $payment->confirm($orderId, $transactionId);
        echo json_encode(['verified' => true, 'processed' => $processed], JSON_THROW_ON_ERROR);
        exit;
    }

    sendMasterDealerApiError('Unknown action.', 404);
} catch (InvalidArgumentException $exception) {
    error_log('Master Dealer API validation/configuration failure: ' . get_class($exception) . '.');
    $status = str_contains(strtolower($exception->getMessage()), 'configured') ? 503 : 422;
    sendMasterDealerApiError($status === 503 ? 'A required service is not configured.' : $exception->getMessage(), $status);
} catch (FirebaseTokenVerificationException) {
    sendMasterDealerApiError('A valid Firebase bearer token is required.', 401);
} catch (FirebaseKeyFetchException) {
    error_log('Master Dealer API Firebase key service is unavailable.');
    sendMasterDealerApiError('Customer authentication is temporarily unavailable.', 503);
} catch (FirestoreException $exception) {
    error_log('Master Dealer API Firestore failure: ' . $exception->httpStatus() . '.');
    sendMasterDealerApiError('The transaction service is temporarily unavailable.', 503);
} catch (PaymobException $exception) {
    error_log('Master Dealer API Paymob verification failure: ' . get_class($exception) . '.');
    sendMasterDealerApiError('Payment could not be verified.', 422);
} catch (RuntimeException $exception) {
    error_log('Master Dealer API operation failure: ' . get_class($exception) . '.');
    sendMasterDealerApiError('The requested operation is temporarily unavailable.', 503);
} catch (Throwable $exception) {
    error_log('Unexpected Master Dealer API failure: ' . get_class($exception) . '.');
    sendMasterDealerApiError('The requested operation is temporarily unavailable.', 503);
}

/**
 * @return array<string, mixed>
 */
function requireMasterDealerCustomerClaims(): array
{
    $projectId = trim((string) (getenv('FIREBASE_PROJECT_ID') ?: ''));
    $timeout = filter_var(getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);
    if ($projectId === '' || $timeout === false) {
        throw new InvalidArgumentException('Firebase authentication is not configured.');
    }

    $verifier = new FirebaseIdTokenVerifier(
        $projectId,
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'firebase-public-keys.json',
        $timeout
    );
    $claims = $verifier->verifyAuthorizationHeader($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
        throw new InvalidArgumentException('Customer authentication is invalid.');
    }

    return $claims;
}

function requireNonNegativeInteger(mixed $value, string $label): int
{
    if (!is_int($value) && (!is_string($value) || preg_match('/^\d{1,5}$/', $value) !== 1)) {
        throw new InvalidArgumentException($label . ' must be a whole number.');
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
        throw new InvalidArgumentException($label . ' must be a non-negative whole number.');
    }

    return (int) $value;
}

function requireIdempotencyKey(): string
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if (!is_string($key) || preg_match('/^[A-Za-z0-9._-]{16,128}$/', $key) !== 1) {
        throw new InvalidArgumentException('A valid Idempotency-Key header is required.');
    }

    return $key;
}

function sendMasterDealerApiError(string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
