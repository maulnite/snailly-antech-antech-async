<?php
/**
 * Helper endpoint for converted web version.
 * - action=check&url=https://example.com  => tells whether URL is blocked
 * - action=write with JSON {"websites":[...]} => updates data/list_website.txt
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Blocklist.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'check';

if ($action === 'write') {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $websites = $payload['websites'] ?? [];
    if (!is_array($websites)) {
        respond(['ok' => false, 'message' => 'websites must be an array'], 400);
    }
    $clean = [];
    foreach ($websites as $website) {
        $normalized = snailly_normalize_url((string) $website);
        if ($normalized !== '') {
            $clean[$normalized] = true;
        }
    }
    $file = __DIR__ . '/../data/list_website.txt';
    file_put_contents($file, implode(PHP_EOL, array_keys($clean)) . PHP_EOL);
    respond(['ok' => true, 'message' => 'Blocklist updated', 'count' => count($clean)]);
}

if ($action === 'check') {
    $url = $_GET['url'] ?? '';
    if ($url === '') {
        respond(['ok' => false, 'message' => 'Missing url parameter'], 400);
    }
    respond([
        'ok' => true,
        'url' => $url,
        'normalized' => snailly_normalize_url((string) $url),
        'blocked' => snailly_is_blocked((string) $url),
    ]);
}

respond(['ok' => false, 'message' => 'Unknown action'], 400);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
