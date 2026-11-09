<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('KHAN_MASTER_DEALER_SESSION');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

final class MasterDealerAuth
{
    private const EMAIL = 'master@master.com';
    private const PASSWORD = 'master';

    public static function login(string $email, string $password): bool
    {
        if (!hash_equals(self::EMAIL, strtolower(trim($email))) ||
            !hash_equals(self::PASSWORD, $password)
        ) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['master_dealer_authenticated'] = true;
        $_SESSION['master_dealer_email'] = self::EMAIL;

        return true;
    }

    public static function isAuthenticated(): bool
    {
        return ($_SESSION['master_dealer_authenticated'] ?? false) === true
            && ($_SESSION['master_dealer_email'] ?? null) === self::EMAIL;
    }

    public static function requireAuthenticated(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: ' . self::url('login.php'), true, 303);
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    public static function url(string $page): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $publicPath = str_replace('\\', '/', dirname(dirname($scriptName)));
        $basePath = $publicPath === '/' || $publicPath === '.'
            ? ''
            : rtrim($publicPath, '/');

        return $basePath . '/master-dealer/' . ltrim($page, '/');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
