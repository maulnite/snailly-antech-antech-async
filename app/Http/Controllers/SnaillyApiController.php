<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SnaillyApiController extends Controller
{
    public function proxy(Request $request): JsonResponse|Response
    {
        $this->loadLegacyBackend();

        if (strtoupper($request->method()) === 'OPTIONS') {
            return response('', 204)->withHeaders($this->corsHeaders($request));
        }

        try {
            $method = strtoupper($request->method());
            $path = (string) $request->query('path', '/');
            $query = $request->query();
            unset($query['path']);

            $body = $request->json()->all();
            if (! is_array($body) || $body === []) {
                $body = $request->all();
                unset($body['path']);
            }

            $authHeader = (string) ($request->header('X-Snailly-Authorization')
                ?: $request->header('Authorization')
                ?: '');

            // Web dashboard uses Laravel session cookie. Extension can still use bearer token.
            if (trim($authHeader) === '') {
                $sessionToken = (string) session('snailly_auth.token', '');
                if ($sessionToken !== '') {
                    $authHeader = 'Bearer ' . $sessionToken;
                }
            }

            $backend = new \LocalBackend(null, $path);
            $result = $backend->handle($method, $path, $query, $body, $authHeader);

            $normPath = '/' . trim($path, '/');
            $status = (int) ($result['status'] ?? 500);
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

            if (in_array($normPath, ['/auth/register', '/auth/login', '/auth/child-login'], true)
                && $status < 400
                && ! empty($payload['data']['accessToken'])) {
                $data = $payload['data'];
                session()->regenerate();
                session(['snailly_auth' => [
                    'token' => (string) ($data['accessToken'] ?? ''),
                    'role' => (string) ($data['role'] ?? 'parent'),
                    'id' => (string) ($data['id'] ?? ''),
                    'name' => (string) ($data['name'] ?? ''),
                    'createdAt' => now()->toIso8601String(),
                ]]);
            }

            if ($normPath === '/auth/logout' && $status < 400) {
                session()->forget('snailly_auth');
                session()->invalidate();
                session()->regenerateToken();
            }

            return response()->json($payload, $status)->withHeaders($this->corsHeaders($request));
        } catch (\RuntimeException $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $status)
                ->withHeaders($this->corsHeaders($request));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Laravel backend error: ' . $e->getMessage()], 500)
                ->withHeaders($this->corsHeaders($request));
        }
    }

    public function track(Request $request): JsonResponse|Response
    {
        // The tracking endpoint is still implemented by the original local PHP tracker
        // because it contains several policy helper functions. It is wrapped here so the
        // URL becomes a Laravel route: /api/snailly/track.
        $script = app_path('Services/SnaillyLegacy/track_legacy.php');
        if (! is_file($script)) {
            return response()->json(['ok' => false, 'message' => 'track_legacy.php tidak ditemukan.'], 500);
        }

        require $script;
        return response('', 204);
    }

    public function blocklist(Request $request): JsonResponse
    {
        require_once app_path('Services/SnaillyLegacy/Blocklist.php');

        $action = (string) $request->query('action', 'check');

        if ($action === 'write') {
            $websites = $request->json('websites', []);
            if (! is_array($websites)) {
                return response()->json(['ok' => false, 'message' => 'websites must be an array'], 400);
            }

            $clean = [];
            foreach ($websites as $website) {
                $normalized = \snailly_normalize_url((string) $website);
                if ($normalized !== '') {
                    $clean[$normalized] = true;
                }
            }

            file_put_contents(base_path('data/list_website.txt'), implode(PHP_EOL, array_keys($clean)) . PHP_EOL);
            return response()->json(['ok' => true, 'message' => 'Blocklist updated', 'count' => count($clean)]);
        }

        if ($action === 'check') {
            $url = (string) $request->query('url', '');
            if ($url === '') {
                return response()->json(['ok' => false, 'message' => 'Missing url parameter'], 400);
            }

            return response()->json([
                'ok' => true,
                'url' => $url,
                'normalized' => \snailly_normalize_url($url),
                'blocked' => \snailly_is_blocked($url),
            ]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown action'], 400);
    }

    private function loadLegacyBackend(): void
    {
        require_once app_path('Services/SnaillyLegacy/Database.php');
        require_once app_path('Services/SnaillyLegacy/PolicyEngine.php');
        require_once app_path('Services/SnaillyLegacy/LocalBackend.php');
    }

    private function corsHeaders(Request $request): array
    {
        $origin = (string) $request->headers->get('Origin', '');
        $headers = [
            'Access-Control-Allow-Headers' => 'Content-Type, X-Snailly-Authorization, Authorization, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        ];

        if ($origin !== '' && $this->originAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    private function originAllowed(string $origin): bool
    {
        if (str_starts_with($origin, 'chrome-extension://')) {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        $host = strtolower((string) $host);
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || preg_match('/^10\./', $host)
            || preg_match('/^192\.168\./', $host)
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host);
    }
}
