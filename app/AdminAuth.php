<?php

declare(strict_types=1);

final class AdminAuth
{
    private const DEFAULT_EMAIL = 'admin@admin.com';
    private const DEFAULT_PASSWORD_HASH = '$2y$10$b2/ZNetg3zlBcxXTXQ0bPeGumj/xZEYSzpiwR25D3kiAP1Yut0yiu';

    public function __construct(
        private readonly string $email,
        private readonly string $passwordHash
    ) {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL) ||
            password_get_info($this->passwordHash)['algo'] === 0
        ) {
            throw new InvalidArgumentException('Admin credentials are not configured correctly.');
        }
    }

    public static function fromEnvironment(): self
    {
        $email = trim((string) (getenv('ADMIN_EMAIL') ?: self::DEFAULT_EMAIL));
        $passwordHash = trim((string) (getenv('ADMIN_PASSWORD_HASH') ?: self::DEFAULT_PASSWORD_HASH));

        return new self($email, $passwordHash);
    }

    public function authenticate(string $email, string $password): bool
    {
        $emailMatches = hash_equals(strtolower($this->email), strtolower(trim($email)));

        return password_verify($password, $this->passwordHash) && $emailMatches;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function login(string $email, string $password): bool
    {
        if (!$this->authenticate($email, $password)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_email'] = $this->email;

        return true;
    }

    public static function isAuthenticated(): bool
    {
        return ($_SESSION['admin_authenticated'] ?? false) === true
            && is_string($_SESSION['admin_email'] ?? null)
            && $_SESSION['admin_email'] !== '';
    }

    public static function requireAuthenticated(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: ' . self::pageUrl('login.php'), true, 303);
            exit;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_email']);
        session_regenerate_id(true);
    }

    public static function csrfToken(): string
    {
        if (!is_string($_SESSION['admin_csrf_token'] ?? null) || $_SESSION['admin_csrf_token'] === '') {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['admin_csrf_token'];
    }

    public static function verifyCsrfToken(string $token): bool
    {
        return $token !== '' &&
            is_string($_SESSION['admin_csrf_token'] ?? null) &&
            hash_equals($_SESSION['admin_csrf_token'], $token);
    }

    public static function pageUrl(string $page): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $publicPath = dirname(dirname($scriptName));
        $basePath = $publicPath === '/' || $publicPath === '.' ? '' : rtrim($publicPath, '/');

        return $basePath . '/admin/' . ltrim($page, '/');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function styles(): string
    {
        return ':root{color-scheme:dark;font-family:Inter,Segoe UI,sans-serif;--gold:#d8aa4b;--muted:#b9b1a3}*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px;background:radial-gradient(circle at top,#2b2417,#11100f 55%);color:#f8f2e7}.admin-card,.admin-shell{width:min(100%,900px);margin:8vh auto;padding:clamp(28px,6vw,56px);background:rgba(27,25,22,.96);border:1px solid rgba(216,170,75,.35);border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.4)}.admin-card{max-width:640px}.eyebrow{color:var(--gold);letter-spacing:.18em;font-size:.75rem;font-weight:700}h1{font-family:Georgia,serif;font-weight:500;font-size:clamp(2rem,6vw,3.2rem);margin:.5rem 0 1rem}.muted{color:var(--muted);line-height:1.6}label{display:block;color:#e8dfd1;font-size:.9rem;margin:18px 0 0}input{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #55462f;border-radius:8px;background:#121110;color:#fff;font:inherit;font-size:1rem}input:focus{outline:2px solid var(--gold);outline-offset:1px}.button{display:inline-block;width:100%;margin-top:24px;padding:14px;border:0;border-radius:8px;background:var(--gold);color:#1a140b;font-weight:700;font-size:1rem;text-align:center;text-decoration:none;cursor:pointer}.errors{margin:18px 0;padding:12px 16px;border:1px solid #a34a42;border-radius:8px;background:#3a1c1b;color:#ffd9d4}.errors ul{margin:0;padding-left:18px}.switch{text-align:center;color:var(--muted);margin:24px 0 0}.switch a,.nav a,.logout{color:var(--gold)}.admin-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}.logout{white-space:nowrap}.nav{display:flex;gap:20px;flex-wrap:wrap;margin:28px 0}.panel{margin-top:24px;padding:24px;border:1px solid rgba(216,170,75,.25);border-radius:12px;background:rgba(18,17,16,.7)}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}@media(max-width:640px){.stat-grid{grid-template-columns:1fr}.admin-header{display:block}.logout{display:inline-block;margin-top:16px}}.stat{padding:18px;border:1px solid #55462f;border-radius:10px}.stat strong{display:block;font-size:2rem;color:var(--gold)}';
    }
}
