<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerAuth.php';

if (MasterDealerAuth::isAuthenticated()) {
    header('Location: ' . MasterDealerAuth::url('dashboard.php'), true, 303);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$email = trim((string) ($_POST['email'] ?? ''));
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if (MasterDealerAuth::login($email, $password)) {
        header('Location: ' . MasterDealerAuth::url('dashboard.php'), true, 303);
        exit;
    }

    $error = 'The email or password is incorrect.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Dealer Sign In | KHAN</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,Segoe UI,sans-serif;--gold:#e5c158;--muted:#aebabc}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#2b3539,#111618 62%);color:#f8f2e7}
        main{width:min(100%,520px);padding:clamp(28px,6vw,52px);background:#202a2d;border:1px solid rgba(229,193,88,.35);border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.4)}
        .eyebrow{color:var(--gold);letter-spacing:.18em;font-size:.75rem;font-weight:700}
        h1{font-family:Georgia,serif;font-weight:500;font-size:clamp(2rem,6vw,3rem);margin:.5rem 0 1rem}
        p{color:var(--muted);line-height:1.6}
        label{display:block;margin-top:18px;color:#e8dfd1;font-size:.9rem}
        input{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #405055;border-radius:8px;background:#11181a;color:#fff;font:inherit}
        input:focus{outline:2px solid var(--gold);outline-offset:1px}
        button{width:100%;margin-top:24px;padding:14px;border:0;border-radius:8px;background:var(--gold);color:#1a140b;font-weight:700;font-size:1rem;cursor:pointer}
        .error{margin:18px 0;padding:12px 16px;border:1px solid #a34a42;border-radius:8px;background:#3a1c1b;color:#ffd9d4}
    </style>
</head>
<body>
<main>
    <p class="eyebrow">KHAN MANAGEMENT</p>
    <h1>Master Dealer sign in</h1>
    <p>Sign in to access the Master Dealer workspace.</p>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= MasterDealerAuth::escape($error) ?></div><?php endif; ?>
    <form method="post" action="<?= MasterDealerAuth::escape(MasterDealerAuth::url('login.php')) ?>">
        <label>Email address
            <input type="email" name="email" required autocomplete="username" value="<?= MasterDealerAuth::escape($email) ?>">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
