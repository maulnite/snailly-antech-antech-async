<?php
/**
 * Local API entry point.
 * File ini menggantikan proxy ke backend asli dan menjalankan backend sendiri berbasis MySQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LocalBackend.php';

snailly_start_session();
header('Content-Type: application/json; charset=utf-8');
snailly_apply_cors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = (string)($_GET['path'] ?? '/');
    $query = $_GET;
    unset($query['path']);

    $rawBody = file_get_contents('php://input') ?: '';
    $body = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) $body = $decoded;
    }

    $authHeader = (string)(
        $_SERVER['HTTP_X_SNAILLY_AUTHORIZATION']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    );

    // Web dashboard uses HttpOnly PHP session cookie.
    // Extension still uses explicit scoped bearer token.
    if (trim($authHeader) === '') {
        $sessionToken = snailly_session_token();
        if ($sessionToken !== '') $authHeader = 'Bearer ' . $sessionToken;
    }

    $backend = new LocalBackend(__DIR__ . '/../data/app_db.json');
    $result = $backend->handle($method, $path, $query, $body, $authHeader);

    $normPath = '/' . trim($path, '/');
    if (in_array($normPath, ['/auth/register', '/auth/login', '/auth/child-login'], true)
        && ($result['status'] ?? 500) < 400
        && !empty($result['payload']['data']['accessToken'])) {
        snailly_set_session($result['payload']['data']);
    }
    if ($normPath === '/auth/logout' && ($result['status'] ?? 500) < 400) {
        snailly_destroy_session();
    }

    respond_json($result['payload'], $result['status']);
} catch (RuntimeException $e) {
    $status = $e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    respond_json(['ok' => false, 'message' => $e->getMessage()], $status);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => 'Local backend error: ' . $e->getMessage()], 500);
}
