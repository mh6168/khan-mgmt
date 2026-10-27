<?php

declare(strict_types=1);

$bootstrap = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Application bootstrap is unavailable.';
    exit;
}

require $bootstrap;

$firebaseAuthClientFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseAuthClient.php';

if (!is_file($firebaseAuthClientFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication service is unavailable.';
    exit;
}

require_once $firebaseAuthClientFile;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

if ($path === '/auth/signup' || $path === '/auth/login') {
    handleAuthenticationRequest($path);
    exit;
}

if ($path === '/auth/me') {
    handleCurrentUserRequest();
    exit;
}

header('Content-Type: text/html; charset=utf-8');

if ($path === '/' || $path === '/health') {
    http_response_code(200);
    $title = $path === '/health' ? 'KHAN Management Health' : 'KHAN Management';
    $message = $path === '/health'
        ? 'Application is running.'
        : 'KHAN Management bootstrap is running.';

    echo '<!doctype html>';
    echo '<html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>';
    echo '<body><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

http_response_code(404);
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Not Found</title></head>';
echo '<body><h1>Not Found</h1><p>The requested page was not found.</p></body></html>';

/**
 * @return never
 */
function handleAuthenticationRequest(string $path): never
{
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false) {
        sendAuthenticationError('Invalid request body.', 400);
    }

    try {
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        sendAuthenticationError('Request body must be valid JSON.', 400);
    }

    if (!is_array($body)) {
        sendAuthenticationError('Request body must be a JSON object.', 400);
    }

    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;

    if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendAuthenticationError('A valid email is required.', 422);
    }

    if (!is_string($password) || strlen($password) < 6 || strlen($password) > 4096) {
        sendAuthenticationError('Password must be between 6 and 4096 characters.', 422);
    }

    $timeout = getenv('FIREBASE_AUTH_TIMEOUT_SECONDS');
    $timeoutSeconds = $timeout === false || $timeout === '' ? 10 : filter_var($timeout, FILTER_VALIDATE_INT);

    if ($timeoutSeconds === false) {
        error_log('FIREBASE_AUTH_TIMEOUT_SECONDS must be an integer.');
        sendAuthenticationError('Authentication service is not configured correctly.', 503);
    }

    try {
        $client = new FirebaseAuthClient((string) (getenv('FIREBASE_WEB_API_KEY') ?: ''), $timeoutSeconds);
        $result = $path === '/auth/signup'
            ? $client->signUp($email, $password)
            : $client->signIn($email, $password);
    } catch (FirebaseAuthException $exception) {
        sendAuthenticationError($exception->getMessage(), $exception->httpStatus());
    } catch (JsonException $exception) {
        error_log('Unable to encode Firebase Authentication request: ' . $exception->getMessage());
        sendAuthenticationError('Authentication service is temporarily unavailable.', 502);
    } catch (InvalidArgumentException $exception) {
        error_log($exception->getMessage());
        sendAuthenticationError('Authentication service is not configured correctly.', 503);
    }

    http_response_code($path === '/auth/signup' ? 201 : 200);
    echo json_encode([
        'idToken' => $result['idToken'] ?? null,
        'refreshToken' => $result['refreshToken'] ?? null,
        'expiresIn' => $result['expiresIn'] ?? null,
        'localId' => $result['localId'] ?? null,
        'email' => $result['email'] ?? $email,
    ]);
    exit;
}

/**
 * @return never
 */
function sendAuthenticationError(string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * @return never
 */
function handleCurrentUserRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');

    $firebaseTokenVerifierFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';

    if (!is_file($firebaseTokenVerifierFile)) {
        error_log('Firebase token verifier file is unavailable.');
        sendAuthenticationError('Authentication service is temporarily unavailable.', 503);
    }

    require_once $firebaseTokenVerifierFile;

    $firestoreClientFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';

    if (!is_file($firestoreClientFile)) {
        error_log('Firestore client file is unavailable.');
        sendAuthenticationError('Profile service is temporarily unavailable.', 503);
    }

    require_once $firestoreClientFile;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $projectId = getenv('FIREBASE_PROJECT_ID') ?: '';
    $timeout = getenv('FIREBASE_AUTH_TIMEOUT_SECONDS');
    $timeoutSeconds = $timeout === false || $timeout === '' ? 10 : filter_var($timeout, FILTER_VALIDATE_INT);

    if ($timeoutSeconds === false || $projectId === '') {
        error_log('Firebase token verification is not configured.');
        sendAuthenticationError('Authentication service is not configured.', 503);
    }

    try {
        $verifier = new FirebaseIdTokenVerifier(
            $projectId,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'firebase-public-keys.json',
            $timeoutSeconds
        );
        $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $claims = $verifier->verifyAuthorizationHeader($authorizationHeader);
    } catch (FirebaseTokenVerificationException) {
        sendAuthenticationError('Authentication required.', 401);
    } catch (FirebaseKeyFetchException) {
        error_log('Firebase token verification service is unavailable.');
        sendAuthenticationError('Authentication service is temporarily unavailable.', 503);
    } catch (InvalidArgumentException) {
        error_log('Firebase token verification configuration is invalid.');
        sendAuthenticationError('Authentication service is not configured.', 503);
    } catch (Throwable $exception) {
        error_log('Unexpected Firebase token verification failure: ' . get_class($exception) . '.');
        sendAuthenticationError('Authentication service is temporarily unavailable.', 503);
    }

    $uid = $claims['sub'] ?? null;

    if (!is_string($uid) || $uid === '') {
        error_log('Firebase verifier returned an invalid subject claim.');
        sendAuthenticationError('Authentication service is temporarily unavailable.', 503);
    }

    if (!is_string($authorizationHeader) ||
        !preg_match('/^Bearer[ \t]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', $authorizationHeader, $tokenMatches)
    ) {
        error_log('Firebase token authorization header was unavailable after verification.');
        sendAuthenticationError('Authentication service is temporarily unavailable.', 503);
    }

    try {
        $firestore = new FirestoreClient($projectId, $timeoutSeconds);
        $profileDocument = $firestore->getUserProfile($uid, $tokenMatches[1]);
    } catch (FirestoreException $exception) {
        sendAuthenticationError($exception->getMessage(), $exception->httpStatus());
    } catch (InvalidArgumentException $exception) {
        error_log('Firestore profile configuration is invalid.');
        sendAuthenticationError('Profile service is not configured.', 503);
    } catch (Throwable $exception) {
        error_log('Unexpected Firestore profile failure: ' . get_class($exception) . '.');
        sendAuthenticationError('Profile service is temporarily unavailable.', 503);
    }

    if ($profileDocument === null) {
        sendAuthenticationError('User profile was not found.', 404);
    }

    $response = ['uid' => $uid];

    if (is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
        $response['email'] = $claims['email'];
    }

    $fields = $profileDocument['fields'] ?? [];

    if (!is_array($fields)) {
        error_log('Firestore profile response contained invalid fields.');
        sendAuthenticationError('Profile service returned an invalid response.', 503);
    }

    foreach (['displayName', 'photoUrl'] as $fieldName) {
        $fieldValue = $fields[$fieldName]['stringValue'] ?? null;

        if (is_string($fieldValue)) {
            $response[$fieldName] = $fieldValue;
        }
    }

    http_response_code(200);
    echo json_encode($response);
    exit;
}
