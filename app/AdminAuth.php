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
        $publicPath = str_replace('\\', '/', dirname(dirname($scriptName)));
        $basePath = $publicPath === '/' || $publicPath === '.' ? '' : rtrim($publicPath, '/');

        return $basePath . '/admin/' . ltrim($page, '/');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function sidebar(string $active): string
    {
        $items = [
            'dashboard.php' => ['Overview', '⌂'],
            'requests.php' => ['Jeweler applications', '◇'],
        ];
        $markup = '<aside class="sidebar"><div class="brand"><span class="brand-mark">K</span><div><strong>KHAN</strong><small>Management</small></div></div><p class="sidebar-label">Workspace</p><nav class="sidebar-nav">';

        foreach ($items as $page => [$label, $icon]) {
            $class = $active === $page ? ' class="active"' : '';
            $markup .= '<a' . $class . ' href="' . self::escape(self::pageUrl($page)) . '"><span class="nav-icon">' . $icon . '</span>' . self::escape($label) . '</a>';
        }

        $markup .= '</nav><div class="sidebar-footer"><span class="online-dot"></span> Admin workspace<a class="sidebar-logout" href="' . self::escape(self::pageUrl('logout.php')) . '">Sign out</a></div></aside>';

        return $markup;
    }

    public static function styles(): string
    {
        return ':root{color-scheme:dark;font-family:Inter,Segoe UI,sans-serif;--bg:#111618;--panel:#192124;--panel-2:#202c30;--gold:#e5c158;--light-gold:#fbf2b5;--muted:#9eabad;--border:rgba(229,193,88,.18)}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:#f8f2e7}.admin-card{width:min(100% - 48px,640px);margin:8vh auto;padding:clamp(28px,6vw,56px);background:linear-gradient(145deg,#202b2e,#182124);border:1px solid var(--border);border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.35)}.admin-shell{min-height:100vh;width:100%;padding:48px clamp(28px,5vw,72px) 64px 320px;background:radial-gradient(circle at 80% 0,rgba(229,193,88,.08),transparent 35%)}.sidebar{position:fixed;inset:0 auto 0 0;width:272px;padding:32px 20px;display:flex;flex-direction:column;background:#192124;border-right:1px solid var(--border);z-index:5}.brand{display:flex;align-items:center;gap:12px;padding:4px 12px 42px}.brand-mark{display:grid;place-items:center;width:38px;height:38px;border:1px solid var(--gold);border-radius:10px;color:var(--gold);font:700 1.25rem Georgia,serif}.brand strong{display:block;letter-spacing:.2em;color:var(--light-gold);font-size:.95rem}.brand small{display:block;margin-top:3px;color:var(--muted);font-size:.72rem}.sidebar-label{padding:0 14px;color:#657477;text-transform:uppercase;letter-spacing:.12em;font-size:.68rem;font-weight:700}.sidebar-nav{display:grid;gap:8px}.sidebar-nav a{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:9px;color:#aebabc;text-decoration:none;font-size:.9rem;transition:background .15s,color .15s}.sidebar-nav a:hover,.sidebar-nav a.active{background:rgba(229,193,88,.12);color:var(--light-gold)}.sidebar-nav a.active{box-shadow:inset 3px 0 var(--gold)}.nav-icon{width:20px;color:var(--gold);font-size:1.15rem;text-align:center}.sidebar-footer{margin-top:auto;padding:16px 14px;border-top:1px solid var(--border);color:#7f8e91;font-size:.78rem}.online-dot{display:inline-block;width:7px;height:7px;margin-right:7px;border-radius:50%;background:#79b88a}.sidebar-logout{display:block;margin-top:14px;color:var(--gold);text-decoration:none}.eyebrow{color:var(--gold);letter-spacing:.18em;font-size:.75rem;font-weight:700}h1{font-family:Georgia,serif;font-weight:500;font-size:clamp(2rem,5vw,3.5rem);margin:.5rem 0 1rem;color:#fff}h2{font:500 1.35rem Georgia,serif;color:var(--light-gold);margin:30px 0 16px}.muted{color:var(--muted);line-height:1.6}label{display:block;color:#e8dfd1;font-size:.9rem;margin:18px 0 0}input,select{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #405055;border-radius:8px;background:#11181a;color:#fff;font:inherit;font-size:1rem}input:focus,select:focus{outline:2px solid var(--gold);outline-offset:1px}.button{display:inline-block;width:100%;margin-top:24px;padding:14px;border:0;border-radius:8px;background:var(--gold);color:#1a140b;font-weight:700;font-size:1rem;text-align:center;text-decoration:none;cursor:pointer}.errors{margin:18px 0;padding:12px 16px;border:1px solid #a34a42;border-radius:8px;background:#3a1c1b;color:#ffd9d4}.errors ul{margin:0;padding-left:18px}.switch{text-align:center;color:var(--muted);margin:24px 0 0}.switch a,.nav a,.logout{color:var(--gold)}.admin-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}.logout{white-space:nowrap}.nav{display:flex;gap:20px;flex-wrap:wrap;margin:28px 0}.panel{margin-top:24px;padding:28px;border:1px solid var(--border);border-radius:14px;background:rgba(25,33,36,.82);box-shadow:0 14px 40px rgba(0,0,0,.14)}.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.stat{padding:22px;border:1px solid #35464a;border-radius:12px;background:linear-gradient(145deg,#223034,#1b2528)}.stat strong{display:block;font-size:2.2rem;color:var(--gold);margin-bottom:8px}.admin-main{max-width:1400px}.page-kicker{color:#718184;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase}.topbar{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:36px}@media(max-width:900px){.admin-shell{padding:32px 24px 48px 24px}.sidebar{position:static;width:auto;min-height:auto;padding:18px 16px;flex-direction:row;align-items:center;gap:20px;border-right:0;border-bottom:1px solid var(--border)}.brand{padding:0}.brand small,.sidebar-label,.sidebar-footer{display:none}.sidebar-nav{display:flex;overflow:auto}.sidebar-nav a{white-space:nowrap}.admin-shell:has(.sidebar){padding-top:36px}.stat-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.stat-grid{grid-template-columns:1fr}.admin-header,.topbar{display:block}.logout{display:inline-block;margin-top:16px}.admin-card{width:min(100% - 28px,640px)}}';
    }
}
