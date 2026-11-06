<?php

declare(strict_types=1);

final class JewelerSession
{
    /**
     * @return array<string, mixed>
     */
    public static function requireAuthenticated(): array
    {
        $token = $_SESSION['firebase_id_token'] ?? null;
        if (!is_string($token) || $token === '') {
            self::redirect('/login');
        }

        try {
            $timeout = filter_var(getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);
            if ($timeout === false) {
                throw new InvalidArgumentException('Firebase authentication timeout is invalid.');
            }

            $verifier = new FirebaseIdTokenVerifier(
                (string) (getenv('FIREBASE_PROJECT_ID') ?: ''),
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'firebase-public-keys.json',
                $timeout
            );

            return $verifier->verify($token);
        } catch (Throwable $exception) {
            error_log('Jeweler session verification failed: ' . get_class($exception) . '.');
            $_SESSION = [];
            self::redirect('/login');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function uid(array $claims): string
    {
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            self::redirect('/login');
        }

        return $claims['sub'];
    }

    public static function redirect(string $route): never
    {
        header('Location: ' . self::url($route), true, 303);
        exit;
    }

    public static function url(string $route): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $publicPath = dirname(dirname($scriptName));
        $basePath = $publicPath === '/' || $publicPath === '.' ? '' : rtrim($publicPath, '/');

        return $basePath . '/' . ltrim($route, '/');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function styles(): string
    {
        return ':root{color-scheme:dark;font-family:Inter,Segoe UI,sans-serif;--bg:#111618;--panel:#2b3539;--gold:#e5c158;--light-gold:#fbf2b5;--muted:#aebabc}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#2b3539,#111618 62%);color:#f8f2e7}.card{width:min(100%,760px);padding:clamp(30px,6vw,64px);background:rgba(43,53,57,.94);border:1px solid rgba(229,193,88,.35);border-radius:20px;box-shadow:0 28px 80px rgba(0,0,0,.4)}.eyebrow{color:var(--gold);letter-spacing:.2em;font-size:.75rem;font-weight:700}h1{font-family:Georgia,serif;font-weight:500;font-size:clamp(2rem,6vw,3.8rem);line-height:1.08;margin:.6rem 0 1rem;color:#fff}.muted{color:var(--muted);line-height:1.7}.status{display:inline-flex;align-items:center;gap:8px;margin:18px 0;padding:8px 14px;border-radius:999px;font-weight:700;letter-spacing:.04em}.status-approved{background:#1d3821;color:#d9f3dc}.status-rejected{background:#4a2523;color:#ffd9d4}.status-dot{width:8px;height:8px;border-radius:50%;background:currentColor}.placeholder{margin:30px 0;padding:24px;border:1px solid rgba(229,193,88,.28);border-radius:14px;background:rgba(17,22,24,.55)}.placeholder h2{margin:0 0 8px;color:var(--light-gold);font:500 1.4rem Georgia,serif}.button{display:inline-block;margin-top:12px;padding:14px 22px;border-radius:9px;background:var(--gold);color:#1a140b;font-weight:700;text-decoration:none}.button.secondary{margin-left:10px;background:transparent;color:var(--light-gold);border:1px solid rgba(229,193,88,.55)}@media(max-width:560px){.button{display:block;text-align:center}.button.secondary{margin:12px 0 0}}';
    }
}
