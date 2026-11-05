<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminAuth.php';

if (AdminAuth::isAuthenticated()) {
    header('Location: ' . AdminAuth::pageUrl('dashboard.php'), true, 303);
    exit;
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid admin email address.';
    }

    if ($password === '') {
        $errors[] = 'Enter your admin password.';
    }

    if ($errors === []) {
        try {
            $adminAuth = AdminAuth::fromEnvironment();
            if ($adminAuth->login($email, $password)) {
                header('Location: ' . AdminAuth::pageUrl('dashboard.php'), true, 303);
                exit;
            }

            $errors[] = 'The admin email or password is incorrect.';
        } catch (InvalidArgumentException $exception) {
            error_log('Admin authentication configuration failure: ' . $exception->getMessage());
            $errors[] = 'Admin sign-in is not configured correctly.';
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$errorMarkup = '';
if ($errors !== []) {
    $errorMarkup = '<div class="errors"><ul>';
    foreach ($errors as $error) {
        $errorMarkup .= '<li>' . AdminAuth::escape($error) . '</li>';
    }
    $errorMarkup .= '</ul></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Sign In | KHAN</title>
    <style><?= AdminAuth::styles() ?></style>
</head>
<body>
<main class="admin-card">
    <p class="eyebrow">KHAN MANAGEMENT</p>
    <h1>Admin sign in</h1>
    <p class="muted">Sign in to manage jeweler applications securely.</p>
    <?= $errorMarkup ?>
    <form method="post" action="<?= AdminAuth::escape(AdminAuth::pageUrl('login.php')) ?>">
        <label>Email address
            <input type="email" name="email" required autocomplete="username" value="<?= AdminAuth::escape($email) ?>">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="button" type="submit">Sign in to dashboard</button>
    </form>
    <p class="switch"><a href="../../login.php">Jeweler sign in</a></p>
</main>
</body>
</html>
