<?php
/**
 * Shared security helpers for Snailly local APIs.
 */
declare(strict_types=1);

function snailly_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('SNAILLYSESSID');
    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => true,       // local XAMPP/LAN uses HTTP; switch to true when using HTTPS.
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function snailly_apply_cors(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && snailly_origin_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } elseif ($origin === '') {
        // Non-browser tools / same-origin form loads do not need CORS.
    } else {
        header('Access-Control-Allow-Origin: null');
    }
    header('Access-Control-Allow-Headers: Content-Type, X-Snailly-Authorization, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}

function snailly_origin_allowed(string $origin): bool
{
    if (str_starts_with($origin, 'chrome-extension://')) return true;
    $host = parse_url($origin, PHP_URL_HOST);
    if (!$host) return false;
    $host = strtolower((string)$host);
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
    if (preg_match('/^10\./', $host)) return true;
    if (preg_match('/^192\.168\./', $host)) return true;
    if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) return true;
    return false;
}

function snailly_session_token(): string
{
    return (string)($_SESSION['snailly_auth']['token'] ?? '');
}

function snailly_set_session(array $data): void
{
    snailly_start_session();
    session_regenerate_id(true);
    $_SESSION['snailly_auth'] = [
        'token' => (string)($data['accessToken'] ?? ''),
        'role' => (string)($data['role'] ?? 'parent'),
        'id' => (string)($data['id'] ?? ''),
        'name' => (string)($data['name'] ?? ''),
        'createdAt' => date(DATE_ATOM),
    ];
}

function snailly_destroy_session(): void
{
    snailly_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
