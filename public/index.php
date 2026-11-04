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
$entryPoint = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

if ($entryPoint === 'signup.php') {
    $path = '/signup';
} elseif ($entryPoint === 'login.php') {
    $path = '/login';
}

$basePath = applicationBasePath();

if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

if ($path === '/signup') {
    handleSignupPage();
    exit;
}

if ($path === '/login') {
    handleLoginPage();
    exit;
}

if ($path === '/auth/logout') {
    handleLogout();
    exit;
}

if ($path === '/jeweler/application') {
    handleApplicationPage();
    exit;
}

if ($path === '/jeweler/pending') {
    handleJewelerStatusPage('pending');
    exit;
}

if ($path === '/jeweler/dashboard') {
    handleJewelerStatusPage('approved');
    exit;
}

if ($path === '/jeweler/rejected') {
    handleJewelerStatusPage('rejected');
    exit;
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

function applicationBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = dirname($scriptName);

    if ($directory === '.' || $directory === '/') {
        return '';
    }

    return '/' . trim($directory, '/');
}

function applicationUrl(string $route): string
{
    $route = '/' . ltrim($route, '/');
    $basePath = applicationBasePath();

    return $basePath . $route;
}

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

    handleCurrentUserRequestVerified();
}

    function handleSignupPage(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            renderAuthPage('signup', null);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Method not allowed.';
            exit;
        }

        $input = readFormInput();
        $errors = validateSignupInput($input);

        if ($errors !== []) {
            renderAuthPage('signup', ['errors' => $errors, 'input' => $input]);
        }

        try {
            requireFirebaseConfiguration();
            requireFirebaseProfileServices();
            $timeoutSeconds = authenticationTimeout();
            $client = new FirebaseAuthClient((string) (getenv('FIREBASE_WEB_API_KEY') ?: ''), $timeoutSeconds);
            $result = $client->signUp($input['email'], $input['password']);
            $token = requireAuthResponseToken($result);
            $claims = verifierForCurrentRequest($timeoutSeconds)->verify($token);
            $uid = verifiedUid($claims);
            $verifiedEmail = verifiedEmail($claims);
            $firestore = new FirestoreClient(requireProjectId(), $timeoutSeconds);
            $firestore->createUserProfile($uid, $token, [
                'name' => $input['fullName'],
                'email' => $verifiedEmail,
                'phone' => $input['phoneNumber'],
                'uid' => $uid,
                'createdAt' => gmdate('c'),
            ]);
            header('Location: ' . applicationUrl('/login?registered=1'), true, 303);
            exit;
        } catch (FirebaseAuthException | FirebaseTokenVerificationException | FirebaseKeyFetchException | FirestoreException $exception) {
            error_log(signupFailureLog($exception));
            renderAuthPage('signup', ['errors' => [signupFailureMessage($exception)], 'input' => $input]);
        } catch (InvalidArgumentException $exception) {
            error_log(signupFailureLog($exception));
            renderAuthPage('signup', ['errors' => [isDevelopmentMode() ? signupDiagnosticMessage($exception) : 'Sign-up is not configured yet. Please contact support.'], 'input' => $input]);
        } catch (Throwable $exception) {
            error_log(signupFailureLog($exception));
            renderAuthPage('signup', ['errors' => [signupFailureMessage($exception)], 'input' => $input]);
        }
    }

    function handleLoginPage(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            renderAuthPage('login', isset($_GET['registered']) && $_GET['registered'] === '1'
                ? ['success' => 'Registration successful. You can now sign in.']
                : null);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Method not allowed.';
            exit;
        }

        $input = readFormInput();

        if (!filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) || !is_string($input['password'] ?? null)) {
            renderAuthPage('login', ['errors' => ['Enter a valid email and password.'], 'input' => $input]);
        }

        try {
            requireFirebaseConfiguration();
            requireFirebaseProfileServices();
            $timeoutSeconds = authenticationTimeout();
            $client = new FirebaseAuthClient((string) (getenv('FIREBASE_WEB_API_KEY') ?: ''), $timeoutSeconds);
            $result = $client->signIn($input['email'], $input['password']);
            $token = requireAuthResponseToken($result);
            $claims = verifierForCurrentRequest($timeoutSeconds)->verify($token);
            $uid = verifiedUid($claims);
            $firestore = new FirestoreClient(requireProjectId(), $timeoutSeconds);
            $profile = $firestore->getUserProfile($uid, $token);
            establishSession($token, $claims);
            header('Location: ' . applicationUrl(jewelerDestination(is_array($profile) ? profileStatus($profile) : 'not_started')), true, 303);
            exit;
        } catch (FirebaseAuthException $exception) {
            renderAuthPage('login', ['errors' => [$exception->getMessage()], 'input' => $input]);
        } catch (FirebaseTokenVerificationException | FirebaseKeyFetchException | FirestoreException $exception) {
            error_log('Login verification/profile failure: ' . get_class($exception) . '.');
            renderAuthPage('login', ['errors' => ['Your account could not be verified right now. Please try again.'], 'input' => $input]);
        } catch (InvalidArgumentException $exception) {
            error_log('Login configuration failure.');
            renderAuthPage('login', ['errors' => ['Sign-in is not configured yet. Please contact support.'], 'input' => $input]);
        } catch (Throwable $exception) {
            error_log('Unexpected login failure: ' . get_class($exception) . '.');
            renderAuthPage('login', ['errors' => ['Your account could not be signed in right now. Please try again.'], 'input' => $input]);
        }
    }

    function handleLogout(): never
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        header('Location: ' . applicationUrl('/login'), true, 303);
        exit;
    }

    function handleApplicationPage(): never
    {
        $claims = requireAuthenticatedSession();
        header('Content-Type: text/html; charset=utf-8');
        $email = is_string($claims['email'] ?? null) ? $claims['email'] : '';
        echo jewelerPage('KHAN Jeweler Application', 'Your application starts here.', 'Welcome' . ($email !== '' ? ', ' . escapeHtml($email) : '') . '. The jeweler application form will be added in the next stage.');
        exit;
    }

    function handleJewelerStatusPage(string $status): never
    {
        requireAuthenticatedSession();

        $pages = [
            'pending' => [
                'title' => 'Application Pending',
                'heading' => 'Your application is under review.',
                'message' => 'KHAN Jeweler Management will update your application when the review is complete.',
            ],
            'approved' => [
                'title' => 'Jeweler Dashboard',
                'heading' => 'Welcome to your jeweler dashboard.',
                'message' => 'Your jeweler application has been approved. Dashboard tools will be added here.',
            ],
            'rejected' => [
                'title' => 'Application Rejected',
                'heading' => 'Your application was not approved.',
                'message' => 'Please contact KHAN Jeweler Management if you need more information about your application.',
            ],
        ];

        $page = $pages[$status] ?? $pages['pending'];
        echo jewelerPage($page['title'], $page['heading'], $page['message']);
        exit;
    }

    function jewelerPage(string $title, string $heading, string $message): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . escapeHtml($title) . '</title><style>' . authStyles() . '</style></head><body><main class="card"><p class="eyebrow">KHAN JEWELERS</p><h1>' . escapeHtml($heading) . '</h1><p class="muted">' . $message . '</p><a class="button" href="' . escapeHtml(applicationUrl('/auth/logout')) . '">Sign out</a></main></body></html>';
    }

    /**
     * @return array<string, string>
     */
    function readFormInput(): array
    {
        return [
            'fullName' => trim((string) ($_POST['fullName'] ?? '')),
            'phoneNumber' => trim((string) ($_POST['phoneNumber'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'password' => (string) ($_POST['password'] ?? ''),
            'passwordConfirmation' => (string) ($_POST['passwordConfirmation'] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $input
     * @return list<string>
     */
    function validateSignupInput(array $input): array
    {
        $errors = [];
        if ($input['fullName'] === '' || strlen($input['fullName']) > 200) {
            $errors[] = 'Full name is required and must be 200 characters or fewer.';
        }
        if (!preg_match('/^\+?[1-9][0-9 ()-]{6,24}$/', $input['phoneNumber'])) {
            $errors[] = 'Enter a valid phone number.';
        }
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($input['password']) < 6 || strlen($input['password']) > 4096) {
            $errors[] = 'Password must be between 6 and 4096 characters.';
        }
        if ($input['password'] !== $input['passwordConfirmation']) {
            $errors[] = 'Passwords do not match.';
        }
        return $errors;
    }

    function authenticationTimeout(): int
    {
        $timeout = getenv('FIREBASE_AUTH_TIMEOUT_SECONDS');
        $value = $timeout === false || $timeout === '' ? 10 : filter_var($timeout, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidArgumentException('Authentication timeout is invalid.');
        }
        return $value;
    }

    function isDevelopmentMode(): bool
    {
        return strtolower((string) (getenv('APP_ENV') ?: 'production')) !== 'production'
            && filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    function signupFailureLog(Throwable $exception): string
    {
        $status = $exception instanceof FirebaseAuthException || $exception instanceof FirestoreException
            ? $exception->httpStatus()
            : 500;

        return 'Signup failure: category=' . signupFailureCategory($exception)
            . ', class=' . get_class($exception)
            . ', status=' . $status
            . ', message=' . signupDiagnosticMessage($exception) . '.';
    }

    function signupFailureCategory(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof FirebaseAuthException => 'firebase-auth',
            $exception instanceof FirebaseTokenVerificationException,
            $exception instanceof FirebaseKeyFetchException => 'token-verification',
            $exception instanceof FirestoreException => 'firestore',
            $exception instanceof InvalidArgumentException => 'configuration',
            default => 'runtime',
        };
    }

    function signupFailureMessage(Throwable $exception): string
    {
        if ($exception instanceof FirebaseAuthException) {
            return $exception->getMessage();
        }

        return isDevelopmentMode()
            ? signupDiagnosticMessage($exception)
            : 'The account could not be created right now. Please try again.';
    }

    function signupDiagnosticMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'No additional diagnostic message was provided.';
        }

        $message = preg_replace('/([?&]key=)[^&\s]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [redacted]', $message) ?? $message;

        return $message;
    }

    function requireProjectId(): string
    {
        $projectId = getenv('FIREBASE_PROJECT_ID') ?: '';
        if ($projectId === '') {
            throw new InvalidArgumentException('Firebase project is not configured.');
        }
        return $projectId;
    }

    function requireFirebaseConfiguration(): void
    {
        if (getenv('FIREBASE_WEB_API_KEY') === false || trim((string) getenv('FIREBASE_WEB_API_KEY')) === '') {
            throw new InvalidArgumentException('Firebase Authentication is not configured.');
        }

        requireProjectId();
    }

    /**
     * @param array<string, mixed> $result
     */
    function requireAuthResponseToken(array $result): string
    {
        if (!is_string($result['idToken'] ?? null) || $result['idToken'] === '') {
            throw new FirebaseAuthException('Authentication returned an invalid token.', 502);
        }
        return $result['idToken'];
    }

    function verifierForCurrentRequest(int $timeoutSeconds): FirebaseIdTokenVerifier
    {
        $verifierFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';

        if (!is_file($verifierFile)) {
            throw new InvalidArgumentException('Firebase token verifier is unavailable.');
        }

        require_once $verifierFile;

        return new FirebaseIdTokenVerifier(
            requireProjectId(),
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'firebase-public-keys.json',
            $timeoutSeconds
        );
    }

    function requireFirebaseProfileServices(): void
    {
        $verifierFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
        $firestoreFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';

        if (!is_file($verifierFile) || !is_file($firestoreFile)) {
            throw new InvalidArgumentException('Firebase profile services are unavailable.');
        }

        require_once $verifierFile;
        require_once $firestoreFile;
    }

    /**
     * @param array<string, mixed> $claims
     */
    function verifiedUid(array $claims): string
    {
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new FirebaseTokenVerificationException('The verified token has no subject.');
        }
        return $claims['sub'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    function verifiedEmail(array $claims): string
    {
        if (!is_string($claims['email'] ?? null) || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            throw new FirebaseTokenVerificationException('The verified token has no valid email.');
        }

        return strtolower($claims['email']);
    }

    /**
     * @param array<string, mixed> $claims
     */
    function establishSession(string $token, array $claims): void
    {
        session_regenerate_id(true);
        $_SESSION['firebase_id_token'] = $token;
        $_SESSION['firebase_claims'] = $claims;
    }

    /**
     * @return array<string, mixed>
     */
    function requireAuthenticatedSession(): array
    {
        $token = $_SESSION['firebase_id_token'] ?? null;
        if (!is_string($token) || $token === '') {
            header('Location: ' . applicationUrl('/login'), true, 303);
            exit;
        }
        try {
            $claims = verifierForCurrentRequest(authenticationTimeout())->verify($token);
        } catch (Throwable $exception) {
            error_log('Session verification failed: ' . get_class($exception) . '.');
            $_SESSION = [];
            header('Location: ' . applicationUrl('/login'), true, 303);
            exit;
        }
        return $claims;
    }

    /**
     * @param array<string, mixed>|null $state
     */
    function renderAuthPage(string $mode, ?array $state): never
    {
        $isSignup = $mode === 'signup';
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
        $success = is_string($state['success'] ?? null) ? $state['success'] : '';
        header('Content-Type: text/html; charset=utf-8');
        $title = $isSignup ? 'Create your KHAN account' : 'Welcome back to KHAN';
        $action = applicationUrl($isSignup ? '/signup' : '/login');
        $fields = $isSignup
            ? '<label>Full name<input name="fullName" required maxlength="200" autocomplete="name" value="' . escapeHtml((string) ($input['fullName'] ?? '')) . '"></label><label>Phone number<input name="phoneNumber" required autocomplete="tel" value="' . escapeHtml((string) ($input['phoneNumber'] ?? '')) . '"></label>'
            : '';
        $confirm = $isSignup ? '<label>Confirm password<input type="password" name="passwordConfirmation" required autocomplete="new-password"></label>' : '';
        $errorMarkup = '';
        if ($errors !== []) {
            $errorMarkup = '<div class="errors"><ul>';
            foreach ($errors as $error) {
                $errorMarkup .= '<li>' . escapeHtml((string) $error) . '</li>';
            }
            $errorMarkup .= '</ul></div>';
        }
        $successMarkup = $success !== '' ? '<div class="success">' . escapeHtml($success) . '</div>' : '';
        $switch = $isSignup
            ? 'Already have an account? <a href="' . escapeHtml(applicationUrl('/login')) . '">Sign in</a>'
            : 'New to KHAN? <a href="' . escapeHtml(applicationUrl('/signup')) . '">Create an account</a>';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . escapeHtml($title) . '</title><style>' . authStyles() . '</style></head><body><main class="card"><p class="eyebrow">KHAN JEWELERS</p><h1>' . escapeHtml($title) . '</h1><p class="muted">' . ($isSignup ? 'Build your profile and begin your jeweler application.' : 'Sign in securely to continue your journey.') . '</p>' . $successMarkup . $errorMarkup . '<form method="post" action="' . escapeHtml($action) . '" novalidate>' . $fields . '<label>Email address<input type="email" name="email" required autocomplete="email" value="' . escapeHtml((string) ($input['email'] ?? '')) . '"></label><label>Password<input type="password" name="password" required autocomplete="' . ($isSignup ? 'new-password' : 'current-password') . '"></label>' . $confirm . '<button class="button" type="submit">' . ($isSignup ? 'Create account' : 'Sign in') . '</button></form><p class="switch">' . $switch . '</p></main></body></html>';
        exit;
    }

    function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    function authStyles(): string
    {
        return '
    :root{color-scheme:dark;--bg:#11100f;--panel:#1b1916;--gold:#d8aa4b;--muted:#b9b1a3;font-family:Inter,Segoe UI,sans-serif}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#2b2417,#11100f 55%);color:#f8f2e7}.card{width:min(100%,560px);padding:clamp(28px,6vw,56px);background:rgba(27,25,22,.96);border:1px solid rgba(216,170,75,.35);border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.4)}.eyebrow{color:var(--gold);letter-spacing:.18em;font-size:.75rem;font-weight:700}h1{font-family:Georgia,serif;font-weight:500;font-size:clamp(2rem,6vw,3.2rem);margin:.5rem 0 1rem}.muted{color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:560px){.grid{grid-template-columns:1fr}}label{display:block;color:#e8dfd1;font-size:.9rem;margin:18px 0 0}input{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #55462f;border-radius:8px;background:#121110;color:#fff;font-size:1rem}input:focus{outline:2px solid var(--gold);outline-offset:1px}.button{display:inline-block;width:100%;margin-top:24px;padding:14px;border:0;border-radius:8px;background:var(--gold);color:#1a140b;font-weight:700;font-size:1rem;cursor:pointer}.errors{margin:18px 0;padding:12px 16px;border:1px solid #a34a42;border-radius:8px;background:#3a1c1b;color:#ffd9d4}.errors ul{margin:0;padding-left:18px}.success{margin:18px 0;padding:12px 16px;border:1px solid #5d8a62;border-radius:8px;background:#1d3821;color:#d9f3dc}.errors ul{margin:0;padding-left:18px}.switch{text-align:center;color:var(--muted);margin:24px 0 0}.switch a,.button{color:#1a140b}.switch a{color:var(--gold)}';
    }

    /**
     * @param array<string, mixed> $document
     */
    function profileStatus(array $document): string
    {
        $status = $document['fields']['onboardingStatus']['stringValue'] ?? 'not_started';
        return is_string($status) && in_array($status, ['not_started', 'draft', 'submitted', 'under_review', 'approved', 'rejected'], true)
            ? $status
            : 'not_started';
    }

    function jewelerDestination(string $status): string
    {
        return match ($status) {
            'submitted', 'under_review' => '/jeweler/pending',
            'approved' => '/jeweler/dashboard',
            'rejected' => '/jeweler/rejected',
            default => '/jeweler/application',
        };
    }

function handleCurrentUserRequestVerified(): never
{
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
