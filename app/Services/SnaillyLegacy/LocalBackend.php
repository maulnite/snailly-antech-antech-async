<?php
/**
 * Snailly Local Backend
 * Backend mandiri berbasis PHP + MySQL/MariaDB agar data bisa dibuka lewat phpMyAdmin.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PolicyEngine.php';

final class LocalBackend
{
    private SnaillyDatabase $store;
    private array $db;
    private string $requestPath;

    public function __construct(?string $unusedLegacyDbFile = null, ?string $requestPath = null)
    {
        // Parameter lama dipertahankan supaya api/proxy.php tetap kompatibel.
        // Data sekarang disimpan di MySQL, bukan data/app_db.json.
        $this->store = new SnaillyDatabase();
        $this->requestPath = '/' . trim((string)($requestPath ?? '/'), '/');
        $this->db = $this->loadDatabase(!$this->canUseLiteSnapshot($this->requestPath));
    }

    public function handle(string $method, string $path, array $query, array $body, string $authHeader): array
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') return $this->json(['message' => 'Snailly local backend is running.']);

        // Public auth endpoints.
        if ($method === 'POST' && $path === '/auth/register') return $this->register($body);
        if ($method === 'POST' && $path === '/auth/login') return $this->login($body);
        if ($method === 'POST' && $path === '/auth/child-login') return $this->childLogin($body);
        if ($method === 'POST' && $path === '/auth/tracker-login') return $this->trackerLogin($body);

        // All endpoints below must be authenticated either by PHP session cookie
        // (web dashboard) or a scoped bearer token (extension/tracker).
        if ($method === 'POST' && $path === '/auth/logout') {
            return $this->logout($authHeader, []);
        }
        if ($method === 'GET' && $path === '/auth/me') {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            if (($user['_sessionRole'] ?? 'parent') === 'child') {
                $child = $this->childForUser($user['id'], (string)($user['_sessionChildId'] ?? ''));
                if (!$child) throw new RuntimeException('Unauthorized', 401);
                $publicChild = $this->publicChild($child);
                $publicChild['role'] = 'child';
                return $this->json(['data' => $publicChild]);
            }
            return $this->json(['data' => $this->publicUser($user)]);
        }

        if ($path === '/policy-check' && $method === 'POST') {
            return $this->policyCheck($this->requireUser($authHeader, ['parent', 'tracker']), $body);
        }

        if (preg_match('#^/dashboard/overview/([^/]+)$#', $path, $m) && $method === 'GET') {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            $this->assertChildScope($user, $m[1], true);
            return $this->dashboardOverview($user, $m[1], $query);
        }

        if (($path === '/children/overview' || $path === '/children/overview/') && $method === 'GET') {
            return $this->childrenOverview($this->requireUser($authHeader, ['parent']), $query);
        }

        if ($path === '/child' || $path === '/child/') {
            if ($method === 'GET') return $this->listChildren($this->requireUser($authHeader, ['parent', 'tracker']));
            if ($method === 'POST') return $this->createChild($this->requireUser($authHeader, ['parent']), $body);
        }
        if (preg_match('#^/child/([^/]+)$#', $path, $m)) {
            $user = $this->requireUser($authHeader, ['parent']);
            if ($method === 'PUT') return $this->updateChild($user, $m[1], $body);
            if ($method === 'DELETE') return $this->deleteChild($user, $m[1]);
        }

        if ($path === '/rules' || $path === '/rules/') {
            $user = $this->requireUser($authHeader, ['parent']);
            if ($method === 'GET') return $this->listRules($user, $query);
            if ($method === 'POST') return $this->createRule($user, $body);
        }
        if (preg_match('#^/rules/([^/]+)$#', $path, $m) && $method === 'DELETE') {
            return $this->deleteRule($this->requireUser($authHeader, ['parent']), $m[1]);
        }

        if ($path === '/schedules' || $path === '/schedules/') {
            if ($method === 'GET') return $this->listSchedules($this->requireUser($authHeader, ['parent']));
        }
        if (preg_match('#^/schedule/([^/]+)$#', $path, $m)) {
            $user = $this->requireUser($authHeader, ['parent']);
            if ($method === 'GET') return $this->getSchedule($user, $m[1]);
            if ($method === 'PUT') return $this->updateSchedule($user, $m[1], $body);
        }

        if (preg_match('#^/tracker-status/([^/]+)$#', $path, $m)) {
            if ($method === 'GET') {
                $user = $this->requireUser($authHeader, ['parent', 'child', 'tracker']);
                $this->assertChildScope($user, $m[1], false);
                return $this->getTrackerStatus($user, $m[1]);
            }
            if ($method === 'PUT') {
                $user = $this->requireUser($authHeader, ['parent', 'tracker']);
                $this->assertChildScope($user, $m[1], false);
                return $this->updateTrackerStatus($user, $m[1], $body);
            }
        }

        if ($path === '/access-requests' || $path === '/access-requests/') {
            if ($method === 'GET') return $this->listAccessRequests($this->requireUser($authHeader, ['parent']), $query);
            if ($method === 'POST') return $this->createAccessRequest($this->requireUser($authHeader, ['parent', 'child', 'tracker']), $body);
        }
        if (preg_match('#^/access-requests/([^/]+)$#', $path, $m) && $method === 'PUT') {
            return $this->decideAccessRequest($this->requireUser($authHeader, ['parent']), $m[1], $body);
        }

        if (preg_match('#^/report/([^/]+)$#', $path, $m) && $method === 'GET') {
            $user = $this->requireUser($authHeader, ['parent']);
            return $this->report($user, $m[1], $query);
        }

        if (preg_match('#^/classified-url/dangerous-website/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->dangerousWebsites($this->requireUser($authHeader, ['parent']));
        }

        if (preg_match('#^/log/summary/([^/]+)$#', $path, $m) && $method === 'GET') {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            $this->assertChildScope($user, $m[1], true);
            return $this->logSummary($user, $m[1]);
        }
        if (preg_match('#^/log/statistic-year/([^/]+)$#', $path, $m) && $method === 'GET') {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            $this->assertChildScope($user, $m[1], true);
            return $this->statisticYear($user, $m[1], (int)($query['year'] ?? date('Y')));
        }
        if (preg_match('#^/log/statistic-month/([^/]+)$#', $path, $m) && $method === 'GET') {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            $this->assertChildScope($user, $m[1], true);
            return $this->statisticMonth($user, $m[1], (string)($query['date'] ?? date('Y-m')));
        }
        if (preg_match('#^/log/grant-access/([^/]+)$#', $path, $m) && $method === 'PUT') {
            return $this->grantAccess($this->requireUser($authHeader, ['parent']), $m[1], $body);
        }
        if (preg_match('#^/log/([^/]+)$#', $path, $m)) {
            $user = $this->requireUser($authHeader, ['parent', 'child']);
            $this->assertChildScope($user, $m[1], true);
            if ($method === 'GET') return $this->listLogs($user, $m[1], $query);
            if ($method === 'DELETE') {
                if (($user['_sessionRole'] ?? 'parent') !== 'parent') throw new RuntimeException('Forbidden', 403);
                return $this->clearLogs($user, $m[1], $query);
            }
        }

        if (preg_match('#^/profile/([^/]+)$#', $path, $m) && $method === 'PUT') {
            return $this->updateProfile($this->requireUser($authHeader, ['parent']), $m[1], $body);
        }

        return $this->json(['ok' => false, 'message' => 'Endpoint not found: ' . $method . ' ' . $path], 404);
    }

    private function register(array $body): array
    {
        $name = trim((string)($body['name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $confirm = (string)($body['confirmPassword'] ?? $password);

        if ($name === '' || $email === '' || $password === '') return $this->json(['ok' => false, 'message' => 'Name, email, and password are required.'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->json(['ok' => false, 'message' => 'Email format is invalid.'], 422);
        if ($password !== $confirm) return $this->json(['ok' => false, 'message' => 'Password confirmation does not match.'], 422);
        if (!$this->validPassword($password)) return $this->json(['ok' => false, 'message' => $this->passwordMessage()], 422);
        foreach ($this->db['users'] as $existing) {
            if (strtolower((string)$existing['email']) === $email) return $this->json(['ok' => false, 'message' => 'Email already registered.'], 409);
        }

        $user = [
            'id' => $this->id('usr'),
            'name' => $name,
            'email' => $email,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];
        $this->db['users'][] = $user;
        $token = $this->createToken($user['id'], 'parent');
        $this->save();

        $public = $this->publicUser($user);
        $public['accessToken'] = $token;
        $public['role'] = 'parent';
        $public['tokenExpiresAt'] = $this->db['tokens'][$token]['expiresAt'] ?? null;
        return $this->json(['message' => 'Registration success. Kamu otomatis masuk ke dashboard.', 'data' => $public], 201);
    }

    private function login(array $body): array
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $idx = $this->findUserIndexByEmail($email);
        if ($idx === null || !password_verify($password, (string)$this->db['users'][$idx]['passwordHash'])) {
            return $this->json(['ok' => false, 'message' => 'Email atau password salah.'], 401);
        }
        $token = $this->createToken($this->db['users'][$idx]['id'], 'parent');
        $this->save();

        $public = $this->publicUser($this->db['users'][$idx]);
        $public['accessToken'] = $token;
        $public['role'] = 'parent';
        $public['tokenExpiresAt'] = $this->db['tokens'][$token]['expiresAt'] ?? null;
        return $this->json(['message' => 'Login success.', 'data' => $public]);
    }

    private function childLogin(array $body): array
    {
        $username = $this->normalizeUsername((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($username === '' || $password === '') return $this->json(['ok' => false, 'message' => 'Username dan password anak wajib diisi.'], 422);

        foreach ($this->db['children'] as $child) {
            if ($this->normalizeUsername((string)($child['username'] ?? '')) !== $username) continue;
            $hash = (string)($child['passwordHash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) return $this->json(['ok' => false, 'message' => 'Username atau password anak salah.'], 401);
            $token = $this->createToken($child['parentId'], 'child', (string)$child['id']);
            $this->save();
            $public = $this->publicChild($child);
            $public['accessToken'] = $token;
            $public['role'] = 'child';
            $public['tokenExpiresAt'] = $this->db['tokens'][$token]['expiresAt'] ?? null;
            return $this->json(['message' => 'Login anak berhasil.', 'data' => $public]);
        }
        return $this->json(['ok' => false, 'message' => 'Username atau password anak salah.'], 401);
    }

    private function trackerLogin(array $body): array
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $idx = $this->findUserIndexByEmail($email);
        if ($idx === null || !password_verify($password, (string)$this->db['users'][$idx]['passwordHash'])) {
            return $this->json(['ok' => false, 'message' => 'Email atau password salah.'], 401);
        }
        $userId = (string)$this->db['users'][$idx]['id'];
        $token = $this->createToken($userId, 'tracker');
        $this->save();

        $public = $this->publicUser($this->db['users'][$idx]);
        $public['accessToken'] = $token;
        $public['role'] = 'tracker';
        $public['tokenExpiresAt'] = $this->db['tokens'][$token]['expiresAt'] ?? null;
        return $this->json(['message' => 'Tracker login success.', 'data' => $public]);
    }

    private function logout(string $authHeader, array $user): array
    {
        $token = $this->tokenFromHeader($authHeader);
        if ($token !== '' && isset($this->db['tokens'][$token])) {
            $this->db['tokens'][$token]['revokedAt'] = $this->now();
            $this->db['tokens'][$token]['updatedAt'] = $this->now();
            $this->save();
        }
        return $this->json(['message' => 'Logged out successfully.']);
    }

    private function listChildren(array $user): array
    {
        $children = array_values(array_filter($this->db['children'], fn($c) => (string)$c['parentId'] === (string)$user['id']));
        return $this->json(['data' => array_map(fn($c) => $this->publicChild($c), $children)]);
    }

    private function createChild(array $user, array $body): array
    {
        $name = trim((string)($body['name'] ?? ''));
        $username = $this->normalizeUsername((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $confirm = (string)($body['confirmPassword'] ?? $password);
        if ($name === '') return $this->json(['ok' => false, 'message' => 'Child name is required.'], 422);
        if ($username === '') return $this->json(['ok' => false, 'message' => 'Username anak wajib diisi.'], 422);
        if (!$this->isValidUsername($username)) return $this->json(['ok' => false, 'message' => 'Username anak hanya boleh huruf, angka, titik, underscore, atau strip. Minimal 3 karakter.'], 422);
        if ($this->childUsernameExists($username)) return $this->json(['ok' => false, 'message' => 'Username anak sudah dipakai.'], 409);
        if ($password === '' || !$this->validPassword($password)) return $this->json(['ok' => false, 'message' => 'Password anak ' . $this->passwordMessage()], 422);
        if ($password !== $confirm) return $this->json(['ok' => false, 'message' => 'Konfirmasi password anak tidak sama.'], 422);

        $child = [
            'id' => $this->id('chd'),
            'parentId' => $user['id'],
            'name' => $name,
            'username' => $username,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'schedule' => $this->defaultSchedule(),
            'createdAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];
        $this->db['children'][] = $child;
        // Tidak ada dummy initial link lagi. Log baru hanya muncul dari extension tracking.
        $this->save();
        return $this->json(['message' => 'Child account created.', 'data' => $this->publicChild($child)], 201);
    }

    private function updateChild(array $user, string $childId, array $body): array
    {
        $idx = $this->findChildIndex($user['id'], $childId);
        if ($idx === null) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        $name = trim((string)($body['name'] ?? ''));
        $username = $this->normalizeUsername((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $confirm = (string)($body['confirmPassword'] ?? $password);
        if ($name === '') return $this->json(['ok' => false, 'message' => 'Child name is required.'], 422);
        if ($username === '') return $this->json(['ok' => false, 'message' => 'Username anak wajib diisi.'], 422);
        if (!$this->isValidUsername($username)) return $this->json(['ok' => false, 'message' => 'Username anak hanya boleh huruf, angka, titik, underscore, atau strip. Minimal 3 karakter.'], 422);
        if ($this->childUsernameExists($username, $childId)) return $this->json(['ok' => false, 'message' => 'Username anak sudah dipakai.'], 409);

        $this->db['children'][$idx]['name'] = $name;
        $this->db['children'][$idx]['username'] = $username;
        if (!isset($this->db['children'][$idx]['schedule'])) $this->db['children'][$idx]['schedule'] = $this->defaultSchedule();
        if ($password !== '') {
            if (!$this->validPassword($password)) return $this->json(['ok' => false, 'message' => 'Password anak ' . $this->passwordMessage()], 422);
            if ($password !== $confirm) return $this->json(['ok' => false, 'message' => 'Konfirmasi password anak tidak sama.'], 422);
            $this->db['children'][$idx]['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db['children'][$idx]['updatedAt'] = $this->now();
        $this->save();
        return $this->json(['message' => 'Child account updated.', 'data' => $this->publicChild($this->db['children'][$idx])]);
    }

    private function deleteChild(array $user, string $childId): array
    {
        $idx = $this->findChildIndex($user['id'], $childId);
        if ($idx === null) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        array_splice($this->db['children'], $idx, 1);
        $this->db['logs'] = array_values(array_filter($this->db['logs'], fn($l) => (string)($l['child_id'] ?? '') !== $childId));
        $this->db['rules'] = array_values(array_filter($this->db['rules'], fn($r) => !in_array((string)($r['childId'] ?? ''), [$childId], true)));
        $this->db['accessRequests'] = array_values(array_filter($this->db['accessRequests'], fn($r) => (string)($r['childId'] ?? '') !== $childId));
        foreach ($this->db['tokens'] as $token => $session) {
            if (($session['role'] ?? '') === 'child' && ($session['childId'] ?? '') === $childId) unset($this->db['tokens'][$token]);
        }
        $this->save();
        return $this->json(['message' => 'Child deleted.']);
    }

    private function listRules(array $user, array $query): array
    {
        $childId = (string)($query['childId'] ?? 'ALL');
        $type = strtolower((string)($query['type'] ?? 'all'));
        $rules = array_values(array_filter($this->db['rules'], function ($r) use ($user, $childId, $type) {
            if ((string)($r['parentId'] ?? '') !== (string)$user['id']) return false;
            if ($childId !== 'ALL' && $childId !== '' && (string)($r['childId'] ?? 'ALL') !== 'ALL' && (string)($r['childId'] ?? '') !== $childId) return false;
            if (in_array($type, ['allow','block'], true) && (string)($r['type'] ?? '') !== $type) return false;
            return true;
        }));
        usort($rules, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
        return $this->json(['data' => $rules]);
    }

    private function createRule(array $user, array $body): array
    {
        $type = strtolower(trim((string)($body['type'] ?? 'block')));
        $matchType = strtolower(trim((string)($body['matchType'] ?? 'domain')));
        $childId = (string)($body['childId'] ?? 'ALL');

        if (!in_array($type, ['allow', 'block', 'warn'], true)) {
            return $this->json([
                'ok' => false,
                'message' => 'Rule type tidak valid.'
            ], 422);
        }

        if (!in_array($matchType, ['domain', 'url', 'keyword', 'category'], true)) {
            return $this->json([
                'ok' => false,
                'message' => 'Match type tidak valid.'
            ], 422);
        }

        $pattern = $this->normalizeRulePattern((string)($body['pattern'] ?? ''), $matchType);

        if ($pattern === '') {
            return $this->json([
                'ok' => false,
                'message' => 'Pattern/domain wajib diisi.'
            ], 422);
        }

        if ($childId !== 'ALL' && $this->findChildIndex($user['id'], $childId) === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Child tidak ditemukan.'
            ], 404);
        }

        $defaultCategory = $type === 'allow'
            ? 'Allowed by Parent'
            : ($type === 'warn' ? 'Warning by Parent' : 'Blocked by Parent');

        $category = trim((string)($body['category'] ?? $defaultCategory));

        $rule = [
            'id' => $this->id('rul'),
            'parentId' => $user['id'],
            'childId' => $childId ?: 'ALL',
            'type' => $type,
            'matchType' => $matchType,
            'pattern' => $pattern,
            'category' => $category ?: $defaultCategory,
            'createdAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];

        $this->addPolicyRule($rule);
        $this->reclassifyLogsForRule($user['id'], $rule['childId'], $rule['pattern'], $rule['matchType']);
        $this->save();

        return $this->json([
            'message' => 'Rule saved.',
            'data' => $rule
        ], 201);
    }

    private function deleteRule(array $user, string $ruleId): array
    {
        foreach ($this->db['rules'] as $i => $rule) {
            if ((string)$rule['parentId'] === (string)$user['id'] && (string)$rule['id'] === $ruleId) {
                $deletedRule = $rule;
                array_splice($this->db['rules'], $i, 1);
                $this->reclassifyLogsForRule($user['id'], (string)($deletedRule['childId'] ?? 'ALL'), (string)($deletedRule['pattern'] ?? ''), (string)($deletedRule['matchType'] ?? 'domain'));
                $this->save();
                return $this->json(['message' => 'Rule deleted and matching logs refreshed.']);
            }
        }
        return $this->json(['ok' => false, 'message' => 'Rule not found.'], 404);
    }

    private function getSchedule(array $user, string $childId): array
    {
        $child = $this->childForUser($user['id'], $childId);
        if (!$child) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        return $this->json(['data' => $child['schedule'] ?? $this->defaultSchedule()]);
    }

    private function updateSchedule(array $user, string $childId, array $body): array
    {
        $idx = $this->findChildIndex($user['id'], $childId);

        if ($idx === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Child not found.'
            ], 404);
        }

        $schedule = $this->sanitizeSchedule($body);

        $this->db['children'][$idx]['schedule'] = $schedule;
        $this->db['children'][$idx]['updatedAt'] = $this->now();

        $this->reclassifyLogsForChild((string)$user['id'], (string)$childId);

        $this->save();

        return $this->json([
            'message' => 'Internet schedule saved.',
            'data' => $schedule
        ]);
    }

    private function listSchedules(array $user): array
    {
        $items = [];
        foreach ($this->db['children'] as $child) {
            if ((string)($child['parentId'] ?? '') !== (string)$user['id']) continue;
            $items[] = [
                'child' => $this->publicChild($child),
                'schedule' => $child['schedule'] ?? $this->defaultSchedule(),
            ];
        }
        return $this->json(['data' => $items]);
    }

    private function defaultTrackerStatus(array $user, string $childId): array
    {
        return [
            'childId' => $childId,
            'parentId' => (string)$user['id'],
            'enabled' => false,
            'blockDangerous' => false,
            'lastSeenAt' => null,
            'updatedAt' => null,
        ];
    }

    private function getTrackerStatus(array $user, string $childId): array
    {
        $child = $this->childForUser($user['id'], $childId);
        if (!$child) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        $status = $this->db['trackerStatus'][$childId] ?? $this->defaultTrackerStatus($user, $childId);
        return $this->json(['data' => $status]);
    }

    private function updateTrackerStatus(array $user, string $childId, array $body): array
    {
        $child = $this->childForUser($user['id'], $childId);
        if (!$child) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        $status = [
            'childId' => $childId,
            'parentId' => (string)$user['id'],
            'enabled' => (bool)($body['enabled'] ?? false),
            'blockDangerous' => (bool)($body['blockDangerous'] ?? false),
            'lastSeenAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];
        $this->db['trackerStatus'][$childId] = $status;
        $this->save();
        return $this->json(['message' => 'Tracker status updated.', 'data' => $status]);
    }

    private function listAccessRequests(array $user, array $query): array
    {
        $status = strtolower((string)($query['status'] ?? 'all'));
        $items = array_values(array_filter($this->db['accessRequests'], function ($r) use ($user, $status) {
            if ((string)($r['parentId'] ?? '') !== (string)$user['id']) return false;
            if (in_array($status, ['pending','approved','denied'], true) && (string)($r['status'] ?? 'pending') !== $status) return false;
            return true;
        }));
        usort($items, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
        $items = array_map(function ($r) {
            $child = $this->findChildById((string)($r['childId'] ?? ''));
            $r['child'] = $child ? ['id' => $child['id'], 'name' => $child['name']] : null;
            return $r;
        }, $items);
        return $this->json(['data' => $items]);
    }

    private function createAccessRequest(array $user, array $body): array
    {
        $childId = (string)($body['childId'] ?? $user['_sessionChildId'] ?? '');
        $url = trim((string)($body['url'] ?? ''));
        $reason = trim((string)($body['reason'] ?? 'Child requested access from blocked page.'));
        if ($childId === '' || !$this->childForUser($user['id'], $childId)) return $this->json(['ok' => false, 'message' => 'Child not found.'], 404);
        if ($url === '' || !preg_match('#^https?://#i', $url)) return $this->json(['ok' => false, 'message' => 'URL request tidak valid.'], 422);

        foreach ($this->db['accessRequests'] as $req) {
            if ((string)($req['parentId'] ?? '') === (string)$user['id'] && (string)($req['childId'] ?? '') === $childId && (string)($req['url'] ?? '') === $url && (string)($req['status'] ?? '') === 'pending') {
                return $this->json(['message' => 'Request already pending.', 'data' => $req]);
            }
        }
        $request = [
            'id' => $this->id('req'),
            'parentId' => $user['id'],
            'childId' => $childId,
            'url' => $url,
            'host' => $this->hostOf($url),
            'reason' => $reason,
            'status' => 'pending',
            'createdAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];
        $this->db['accessRequests'][] = $request;
        $this->save();
        return $this->json(['message' => 'Access request sent to parent.', 'data' => $request], 201);
    }

    private function decideAccessRequest(array $user, string $requestId, array $body): array
    {
        $decision = strtolower((string)($body['decision'] ?? ''));
        if (!in_array($decision, ['approve','deny'], true)) return $this->json(['ok' => false, 'message' => 'Decision harus approve atau deny.'], 422);
        foreach ($this->db['accessRequests'] as $i => $req) {
            if ((string)($req['parentId'] ?? '') !== (string)$user['id'] || (string)($req['id'] ?? '') !== $requestId) continue;
            $this->db['accessRequests'][$i]['status'] = $decision === 'approve' ? 'approved' : 'denied';
            $this->db['accessRequests'][$i]['updatedAt'] = $this->now();
            $host = $this->normalizePattern((string)($req['host'] ?? $this->hostOf((string)$req['url'])));
            $rule = [
                'id' => $this->id('rul'),
                'parentId' => $user['id'],
                'childId' => (string)$req['childId'],
                'type' => $decision === 'approve' ? 'allow' : 'block',
                'matchType' => 'domain',
                'pattern' => $host,
                'category' => $decision === 'approve' ? 'Approved Request' : 'Denied Request',
                'createdAt' => $this->now(),
                'updatedAt' => $this->now(),
            ];
            $this->addPolicyRule($rule);
            $this->reclassifyLogsForRule($user['id'], $rule['childId'], $rule['pattern'], $rule['matchType']);
            $this->save();
            return $this->json(['message' => $decision === 'approve' ? 'Request approved and whitelisted.' : 'Request denied and blocked.', 'data' => $this->db['accessRequests'][$i]]);
        }
        return $this->json(['ok' => false, 'message' => 'Request not found.'], 404);
    }

    private function dangerousWebsites(array $user): array
    {
        // Only current parent block rules are exported. Old negative logs are history, not live blocking policy.
        $rules = array_values(array_filter($this->db['rules'], fn($r) => (string)($r['parentId'] ?? '') === (string)$user['id'] && (string)($r['type'] ?? '') === 'block'));
        return $this->json(['data' => array_values(array_unique(array_map(fn($r) => $r['pattern'], $rules)))]);
    }

    private function logSummary(array $user, string $childId): array
    {
        return $this->json(['data' => $this->store->logSummary((string)$user['id'], $childId)]);
    }

    private function listLogs(array $user, string $childId, array $query): array
    {
        return $this->json(['data' => $this->store->listLogs((string)$user['id'], $childId, $query)]);
    }

    private function statisticYear(array $user, string $childId, int $year): array
    {
        return $this->json(['data' => $this->store->statisticYear((string)$user['id'], $childId, $year)]);
    }

    private function statisticMonth(array $user, string $childId, string $date): array
    {
        return $this->json(['data' => $this->store->statisticMonth((string)$user['id'], $childId, $date)]);
    }

    private function policyCheck(array $user, array $body): array
    {
        $childId = trim((string)($body['childId'] ?? $user['_sessionChildId'] ?? ''));
        $url = trim((string)($body['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $this->json(['ok' => false, 'message' => 'A valid http/https URL is required.'], 422);
        }
        $this->assertChildScope($user, $childId, false);

        $classification = SnaillyPolicyEngine::classify($this->db, (string)$user['id'], $childId, $url, true);
        $action = (string)($classification['action'] ?? (($classification['label'] ?? 'aman') === 'bahaya' ? 'block' : 'allow'));
        if (!in_array($action, ['allow', 'warn', 'block'], true)) $action = 'allow';

        return $this->json([
            'ok' => true,
            'message' => 'Policy checked without writing a log.',
            'blocked' => $action === 'block',
            'grant_access' => $action !== 'block',
            'label' => (string)($classification['label'] ?? 'aman'),
            'action' => $action,
            'category' => (string)($classification['category'] ?? 'Safe'),
            'risk' => (string)($classification['risk'] ?? 'Low'),
            'reason' => (string)($classification['reason'] ?? 'No risky rule matched.'),
            'score' => (int)($classification['score'] ?? 0),
        ]);
    }

    private function dashboardOverview(array $user, string $childId, array $query): array
    {
        $year = (int)($query['year'] ?? date('Y'));
        $monthDate = (string)($query['date'] ?? date('Y-m'));
        return $this->json(['data' => [
            'summary' => $this->store->logSummary((string)$user['id'], $childId),
            'logs' => $this->store->listLogs((string)$user['id'], $childId, ['page' => 1, 'limit' => (int)($query['limit'] ?? 5), 'period' => 'all']),
            'yearStats' => $this->store->statisticYear((string)$user['id'], $childId, $year),
            'monthStats' => $this->store->statisticMonth((string)$user['id'], $childId, $monthDate),
            'report' => $this->store->report((string)$user['id'], $childId, ['period' => 'all']),
        ]]);
    }

    private function childrenOverview(array $user, array $query): array
    {
        $monthDate = (string)($query['date'] ?? date('Y-m'));
        $children = array_values(array_filter($this->db['children'], fn($c) => (string)$c['parentId'] === (string)$user['id']));
        $summaries = [];
        foreach ($children as $child) {
            $childId = (string)$child['id'];
            $summaries[$childId] = [
                'summary' => $this->store->logSummary((string)$user['id'], $childId),
                'monthStats' => $this->store->statisticMonth((string)$user['id'], $childId, $monthDate),
            ];
        }
        return $this->json(['data' => ['children' => array_map(fn($c) => $this->publicChild($c), $children), 'summaryMap' => $summaries]]);
    }

    private function grantAccess(array $user, string $logId, array $body): array
    {
        foreach ($this->db['logs'] as $i => $log) {
            if ((string)($log['parentId'] ?? '') === (string)$user['id'] && (string)($log['log_id'] ?? '') === $logId) {
                $raw = $body['grantAccess'] ?? false;
                $grant = $raw === true || $raw === 'true' || $raw === '1' || $raw === 1;
                $this->db['logs'][$i]['grant_access'] = $grant;
                $this->db['logs'][$i]['updatedAt'] = $this->now();
                $host = $this->normalizePattern($this->hostOf((string)($log['url'] ?? '')));
                if ($host !== '') {
                    $rule = [
                        'id' => $this->id('rul'),
                        'parentId' => $user['id'],
                        'childId' => (string)($log['child_id'] ?? 'ALL'),
                        'type' => $grant ? 'allow' : 'block',
                        'matchType' => 'domain',
                        'pattern' => $host,
                        'category' => $grant ? 'Allowed by Parent' : 'Blocked by Parent',
                        'createdAt' => $this->now(),
                        'updatedAt' => $this->now(),
                    ];
                    $this->addPolicyRule($rule);
                    $this->reclassifyLogsForRule($user['id'], $rule['childId'], $rule['pattern'], $rule['matchType']);
                }
                $this->save();
                return $this->json(['message' => 'Access status updated.', 'data' => $this->decorateLog($this->db['logs'][$i])]);
            }
        }
        return $this->json(['ok' => false, 'message' => 'Log not found.'], 404);
    }


    private function clearLogs(array $user, string $childId, array $query): array
    {
        $deleted = $this->store->clearLogs((string)$user['id'], $childId, $query);
        if ($deleted <= 0) return $this->json(['message' => 'No logs matched the selected filter.', 'deleted' => 0]);
        return $this->json(['message' => $deleted . ' log(s) deleted.', 'deleted' => $deleted]);
    }

    private function report(array $user, string $childId, array $query): array
    {
        return $this->json(['data' => $this->store->report((string)$user['id'], $childId, $query)]);
    }

    private function updateProfile(array $user, string $id, array $body): array
    {
        if ($id !== $user['id']) return $this->json(['ok' => false, 'message' => 'You can only update your own profile.'], 403);
        $idx = $this->findUserIndexById($user['id']);
        if ($idx === null) return $this->json(['ok' => false, 'message' => 'User not found.'], 404);

        $name = trim((string)($body['name'] ?? $this->db['users'][$idx]['name']));
        $email = strtolower(trim((string)($body['email'] ?? $this->db['users'][$idx]['email'])));
        $old = (string)($body['oldPassword'] ?? '');
        $new = (string)($body['newPassword'] ?? '');
        $confirm = (string)($body['confirmPassword'] ?? $new);

        if ($name === '') return $this->json(['ok' => false, 'message' => 'Name is required.'], 422);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->json(['ok' => false, 'message' => 'Email format is invalid.'], 422);
        foreach ($this->db['users'] as $i => $existing) {
            if ($i !== $idx && strtolower((string)$existing['email']) === $email) return $this->json(['ok' => false, 'message' => 'Email already used by another account.'], 409);
        }

        $this->db['users'][$idx]['name'] = $name;
        $this->db['users'][$idx]['email'] = $email;

        if ($new !== '' || $confirm !== '' || $old !== '') {
            if (!password_verify($old, (string)$this->db['users'][$idx]['passwordHash'])) return $this->json(['ok' => false, 'message' => 'Old password is incorrect.'], 422);
            if ($new === '' || !$this->validPassword($new)) return $this->json(['ok' => false, 'message' => 'New password ' . $this->passwordMessage()], 422);
            if ($new !== $confirm) return $this->json(['ok' => false, 'message' => 'New password confirmation does not match.'], 422);
            $this->db['users'][$idx]['passwordHash'] = password_hash($new, PASSWORD_DEFAULT);
        }

        $this->db['users'][$idx]['updatedAt'] = $this->now();
        $this->save();
        return $this->json(['message' => 'Profile updated.', 'data' => $this->publicUser($this->db['users'][$idx])]);
    }

    private function requireUser(string $authHeader, array $allowedRoles = ['parent', 'child', 'tracker']): array
    {
        $token = $this->tokenFromHeader($authHeader);
        if ($token === '' || !isset($this->db['tokens'][$token])) throw new RuntimeException('Unauthorized', 401);
        $session = $this->db['tokens'][$token];
        $role = (string)($session['role'] ?? 'parent');
        if (!in_array($role, $allowedRoles, true)) throw new RuntimeException('Forbidden: role ' . $role . ' cannot access this endpoint.', 403);
        if (!empty($session['revokedAt'])) throw new RuntimeException('Session expired. Please login again.', 401);
        $expiresAt = (string)($session['expiresAt'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && time() > (int)strtotime($expiresAt)) {
            $this->db['tokens'][$token]['revokedAt'] = $this->now();
            $this->save();
            throw new RuntimeException('Session expired. Please login again.', 401);
        }
        $idx = $this->findUserIndexById((string)($session['userId'] ?? ''));
        if ($idx === null) throw new RuntimeException('Unauthorized', 401);
        $user = $this->db['users'][$idx];
        $user['_sessionRole'] = $role;
        $user['_sessionChildId'] = (string)($session['childId'] ?? '');
        $user['_sessionToken'] = $token;
        return $user;
    }

    private function tokenFromHeader(string $authHeader): string
    {
        return trim(preg_replace('/^Bearer\s+/i', '', trim($authHeader)) ?? '');
    }

    private function assertChildScope(array $user, string $childId, bool $allowAllForParent = false): void
    {
        $role = (string)($user['_sessionRole'] ?? 'parent');
        if ($role === 'child') {
            $ownChildId = (string)($user['_sessionChildId'] ?? '');
            if ($ownChildId === '' || $childId === 'ALL' || $childId === '' || $childId !== $ownChildId) {
                throw new RuntimeException('Forbidden: child account can only access its own data.', 403);
            }
            return;
        }
        if ($childId === 'ALL' || $childId === '') {
            if ($allowAllForParent && $role === 'parent') return;
            throw new RuntimeException('Child id is required.', 422);
        }
        if (!$this->childForUser($user['id'], $childId)) throw new RuntimeException('Child not found.', 404);
    }

    private function createToken(string $userId, string $role, string $childId = ''): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl = $role === 'tracker' ? '+30 days' : '+12 hours';
        $this->db['tokens'][$token] = [
            'userId' => $userId,
            'role' => $role,
            'createdAt' => $this->now(),
            'expiresAt' => date(DATE_ATOM, strtotime($ttl) ?: (time() + 43200)),
            'lastUsedAt' => null,
            'revokedAt' => null,
        ];
        if ($childId !== '') $this->db['tokens'][$token]['childId'] = $childId;
        return $token;
    }

    private function validPassword(string $password): bool
    {
        return strlen($password) >= 8 && (bool)preg_match('/[A-Za-z]/', $password) && (bool)preg_match('/\d/', $password);
    }

    private function passwordMessage(): string
    {
        return 'minimal 8 karakter dan wajib mengandung huruf serta angka.';
    }

    private function logsFor(string $parentId, string $childId): array
    {
        $childIds = array_map(fn($c) => (string)$c['id'], array_filter($this->db['children'], fn($c) => (string)$c['parentId'] === (string)$parentId));
        return array_values(array_filter($this->db['logs'], function ($log) use ($parentId, $childId, $childIds) {
            if ((string)($log['parentId'] ?? '') !== (string)$parentId) return false;
            if (!in_array((string)($log['child_id'] ?? ''), $childIds, true)) return false;
            return $childId === 'ALL' || $childId === '' || (string)($log['child_id'] ?? '') === $childId;
        }));
    }

    private function applyLogSearch(array $logs, string $query): array
    {
        $query = strtolower(trim($query));
        if ($query === '') return $logs;
        return array_values(array_filter($logs, function ($log) use ($query) {
            $child = $this->findChildById((string)($log['child_id'] ?? ''));
            $haystack = strtolower(implode(' ', [
                (string)($log['url'] ?? ''),
                $this->hostOf((string)($log['url'] ?? '')),
                (string)($log['web_title'] ?? ''),
                $child['name'] ?? '',
                $this->categoryOf($log),
                (string)($this->classificationOf($log)['reason'] ?? ''),
            ]));
            return str_contains($haystack, $query);
        }));
    }

    private function applyLogStatusFilter(array $logs, string $status): array
    {
        $status = strtolower($status);

        if (!in_array($status, ['positive', 'negative', 'warning', 'pending'], true)) {
            return $logs;
        }

        return array_values(array_filter($logs, function ($log) use ($status) {
            $label = (string)($this->classificationOf($log)['FINAL_label'] ?? '');
            $action = (string)($this->classificationOf($log)['action'] ?? '');
            $safe = $this->isSafe($log);
            $hasGrant = array_key_exists('grant_access', $log) && is_bool($log['grant_access']);

            if ($status === 'positive') {
                return $safe && $label !== 'peringatan' && $action !== 'warn';
            }

            if ($status === 'negative') {
                return !$safe;
            }

            if ($status === 'warning') {
                return $label === 'peringatan' || $action === 'warn';
            }

            return !$hasGrant;
        }));
    }

    private function filterByPeriod(array $logs, string $period, array $query): array
    {
        if ($period === 'daily') {
            $year = (int)($query['year'] ?? date('Y'));
            $month = (int)($query['month'] ?? date('n'));
            $date = (int)($query['date'] ?? date('j'));
            $logs = array_values(array_filter($logs, function ($log) use ($year, $month, $date) {
                $ts = strtotime((string)($log['createdAt'] ?? '')) ?: 0;
                return (int)date('Y', $ts) === $year && (int)date('n', $ts) === $month && (int)date('j', $ts) === $date;
            }));
        } elseif ($period === 'monthly') {
            $year = (int)($query['year'] ?? date('Y'));
            $month = (int)($query['month'] ?? date('n'));
            $logs = array_values(array_filter($logs, function ($log) use ($year, $month) {
                $ts = strtotime((string)($log['createdAt'] ?? '')) ?: 0;
                return (int)date('Y', $ts) === $year && (int)date('n', $ts) === $month;
            }));
        } elseif ($period === 'weekly') {
            $date = (string)($query['selectedDate'] ?? $query['date'] ?? date('Y-m-d'));
            $anchor = strtotime($date) ?: time();
            $start = strtotime('monday this week', $anchor);
            $end = $start + 7 * 86400;
            $logs = array_values(array_filter($logs, function ($log) use ($start, $end) {
                $ts = strtotime((string)($log['createdAt'] ?? '')) ?: 0;
                return $ts >= $start && $ts < $end;
            }));
        }
        usort($logs, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
        return $logs;
    }

    private function decorateLog(array $log): array
    {
        $child = $this->findChildById((string)($log['child_id'] ?? ''));
        $log['child'] = $child ? ['id' => $child['id'], 'name' => $child['name']] : null;
        $label = $this->classificationOf($log);
        $log['risk_category'] = (string)($label['category'] ?? 'Safe');
        $log['risk_level'] = (string)($label['risk'] ?? ($this->isSafe($log) ? 'Low' : 'High'));
        $log['risk_reason'] = (string)($label['reason'] ?? '');
        return $log;
    }

    private function classificationOf(array $log): array
    {
        $labels = $log['classified_url'] ?? [];
        return is_array($labels) && isset($labels[0]) && is_array($labels[0]) ? $labels[0] : [];
    }

    private function isSafe(array $log): bool
    {
        if (($log['grant_access'] ?? null) === true) return true;
        if (($log['grant_access'] ?? null) === false) return false;

        $classification = $this->classificationOf($log);
        $label = (string)($classification['FINAL_label'] ?? 'aman');
        $action = (string)($classification['action'] ?? '');

        return $label === 'aman' || $label === 'peringatan' || $action === 'warn';
    }

    private function isDanger(array $log): bool { return !$this->isSafe($log); }
    private function categoryOf(array $log): string { return (string)($this->classificationOf($log)['category'] ?? ($this->isSafe($log) ? 'Safe' : 'Risky')); }

    private function addPolicyRule(array $newRule): void
    {
        $matchType = (string)($newRule['matchType'] ?? 'domain');
        $pattern = $this->normalizeRulePattern((string)($newRule['pattern'] ?? ''), $matchType);
        $childId = (string)($newRule['childId'] ?? 'ALL');
        $parentId = (string)($newRule['parentId'] ?? '');
        $this->db['rules'] = array_values(array_filter($this->db['rules'], function ($rule) use ($parentId, $childId, $matchType, $pattern) {
            return !(
                (string)($rule['parentId'] ?? '') === $parentId &&
                (string)($rule['childId'] ?? 'ALL') === $childId &&
                (string)($rule['matchType'] ?? 'domain') === $matchType &&
                $this->normalizeRulePattern((string)($rule['pattern'] ?? ''), $matchType) === $pattern
            );
        }));
        $newRule['pattern'] = $pattern;
        $newRule['updatedAt'] = $this->now();
        if (empty($newRule['createdAt'])) $newRule['createdAt'] = $this->now();
        $this->db['rules'][] = $newRule;
    }

    private function reclassifyLogsForRule(string $parentId, string $ruleChildId, string $pattern, string $matchType): void
    {
        foreach ($this->db['logs'] as $i => $log) {
            if ((string)($log['parentId'] ?? '') !== $parentId) {
                continue;
            }

            $logChildId = (string)($log['child_id'] ?? '');

            if ($ruleChildId !== 'ALL' && $ruleChildId !== '' && $logChildId !== $ruleChildId) {
                continue;
            }

            $url = (string)($log['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $base = SnaillyPolicyEngine::heuristicDecision($url);

            if (!SnaillyPolicyEngine::patternMatches($url, $pattern, $matchType, $base['category'] ?? '')) {
                continue;
            }

            $classification = SnaillyPolicyEngine::classify(
                $this->db,
                $parentId,
                $logChildId,
                $url,
                false
            );

            $action = $classification['action'] ?? (
                ($classification['label'] ?? 'aman') === 'bahaya' ? 'block' : 'allow'
            );

            $blocked = $action === 'block';

            $this->db['logs'][$i]['grant_access'] = !$blocked;
            $this->db['logs'][$i]['classified_url'] = [[
                'FINAL_label' => $classification['label'] ?? 'aman',
                'action' => $action,
                'category' => $classification['category'] ?? 'Safe',
                'risk' => $classification['risk'] ?? 'Low',
                'reason' => $classification['reason'] ?? '',
                'score' => $classification['score'] ?? 0,
            ]];
            $this->db['logs'][$i]['updatedAt'] = $this->now();
        }
    }
    private function reclassifyLogsForChild(string $parentId, string $childId): void
    {
        foreach ($this->db['logs'] as $i => $log) {
            if ((string)($log['parentId'] ?? '') !== $parentId) {
                continue;
            }

            if ((string)($log['child_id'] ?? '') !== $childId) {
                continue;
            }

            $url = (string)($log['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $classification = SnaillyPolicyEngine::classify(
                $this->db,
                $parentId,
                $childId,
                $url,
                true
            );

            $action = $classification['action'] ?? (
                ($classification['label'] ?? 'aman') === 'bahaya' ? 'block' : 'allow'
            );

            $blocked = $action === 'block';

            $this->db['logs'][$i]['grant_access'] = !$blocked;
            $this->db['logs'][$i]['classified_url'] = [[
                'FINAL_label' => $classification['label'] ?? 'aman',
                'action' => $action,
                'category' => $classification['category'] ?? 'Safe',
                'risk' => $classification['risk'] ?? 'Low',
                'reason' => $classification['reason'] ?? '',
                'score' => $classification['score'] ?? 0,
            ]];

            $this->db['logs'][$i]['updatedAt'] = $this->now();
        }
    }
    private function classifyHistoricalUrl(string $parentId, string $childId, string $url): array
    {
        return SnaillyPolicyEngine::classify(
            $this->db,
            $parentId,
            $childId,
            $url,
            false
        );
    }

    private function latestRuleDecision(string $parentId, string $childId, string $url): ?array
    {
        $matches = [];
        foreach ($this->db['rules'] as $rule) {
            if ((string)($rule['parentId'] ?? '') !== $parentId) continue;
            $ruleChild = (string)($rule['childId'] ?? 'ALL');
            if ($ruleChild !== 'ALL' && $ruleChild !== '' && $ruleChild !== $childId) continue;
            if (!$this->patternMatches($url, (string)($rule['pattern'] ?? ''), (string)($rule['matchType'] ?? 'domain'))) continue;
            $matches[] = $rule;
        }
        if (!$matches) return null;
        usort($matches, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? $b['createdAt'] ?? ''), (string)($a['updatedAt'] ?? $a['createdAt'] ?? '')));
        $rule = $matches[0];
        if ((string)($rule['type'] ?? '') === 'allow') return ['label'=>'aman','category'=>(string)($rule['category'] ?? 'Whitelist'),'risk'=>'Low','score'=>0,'reason'=>'Matched latest parent allow rule: '.(string)$rule['pattern']];
        return ['label'=>'bahaya','category'=>(string)($rule['category'] ?? 'Blocked by Parent'),'risk'=>'High','score'=>95,'reason'=>'Matched latest parent block rule: '.(string)$rule['pattern']];
    }

    private function heuristicDecision(string $url): array
    {
        $lower = strtolower($url);
        $host = $this->hostOf($url);
        $checks = [
            'Adult Content' => ['risk'=>'High','words'=>['porn','porno','sex','xxx','xvideos','xnxx','bokep','adult','nsfw','hentai']],
            'Gambling' => ['risk'=>'High','words'=>['judi','casino','togel','slot','betting','gambling','gacor']],
            'Phishing' => ['risk'=>'High','words'=>['phishing','verify-account','account-verify','free-login','password-reset','claim-gift','wallet-verify']],
            'Malware/Piracy' => ['risk'=>'High','words'=>['malware','trojan','virus','crack','keygen','darkweb','download-cheat']],
            'Suspicious' => ['risk'=>'Medium','words'=>['free-robux','free-gift','giveaway-login','bonus-claim']],
        ];
        foreach ($checks as $category => $info) {
            foreach ($info['words'] as $word) {
                if (str_contains($lower, $word)) return ['label'=>'bahaya','category'=>$category,'risk'=>$info['risk'],'score'=>$info['risk']==='High'?90:60,'reason'=>'Keyword detected: '.$word];
            }
        }
        if (preg_match('/\d+\.\d+\.\d+\.\d+/', $host)) return ['label'=>'bahaya','category'=>'Suspicious','risk'=>'Medium','score'=>60,'reason'=>'Website uses raw IP address.'];
        if (strlen($url) > 150 && preg_match('/(login|verify|password|claim)/i', $url)) return ['label'=>'bahaya','category'=>'Suspicious','risk'=>'Medium','score'=>55,'reason'=>'Long URL with login/verify wording.'];
        $education = ['scratch.mit.edu','wikipedia.org','khanacademy.org','code.org','duolingo.com','kids.nationalgeographic.com'];
        foreach ($education as $domain) if ($host === $domain || str_ends_with($host, '.'.$domain)) return ['label'=>'aman','category'=>'Education','risk'=>'Low','score'=>5,'reason'=>'Known educational website.'];
        $social = ['youtube.com','chatgpt.com','google.com','kaggle.com'];
        foreach ($social as $domain) if ($host === $domain || str_ends_with($host, '.'.$domain)) return ['label'=>'aman','category'=>'General/Entertainment','risk'=>'Low','score'=>15,'reason'=>'No risky rule matched.'];
        return ['label'=>'aman','category'=>'Safe','risk'=>'Low','score'=>10,'reason'=>'No risky rule matched.'];
    }

    private function patternMatches(string $url, string $pattern, string $matchType): bool
    {
        $matchType = strtolower(trim($matchType ?: 'domain'));
        $pattern = $this->normalizeRulePattern($pattern, $matchType);

        if ($pattern === '') {
            return false;
        }

        $fullUrl = $this->normalizeRulePattern($url, 'url');
        $host = $this->hostOf($url);

        if ($matchType === 'keyword') {
            return str_contains($fullUrl, $pattern);
        }

        if ($matchType === 'url') {
            return str_contains($fullUrl, $pattern);
        }

        if ($matchType === 'category') {
            $base = $this->heuristicDecision($url);
            $category = strtolower((string)($base['category'] ?? ''));
            return $category === $pattern || str_contains($category, $pattern);
        }

        return $host === $pattern || str_ends_with($host, '.' . $pattern);
    }

    private function defaultSchedule(): array
    {
        return ['enabled' => false, 'start' => '08:00', 'end' => '21:00', 'days' => ['mon','tue','wed','thu','fri','sat','sun']];
    }

    private function sanitizeSchedule(array $body): array
    {
        $days = $body['days'] ?? ['mon','tue','wed','thu','fri','sat','sun'];
        if (!is_array($days)) $days = [];
        $allowed = ['mon','tue','wed','thu','fri','sat','sun'];
        $days = array_values(array_intersect(array_map('strtolower', $days), $allowed));
        $start = preg_match('/^\d{2}:\d{2}$/', (string)($body['start'] ?? '08:00')) ? (string)$body['start'] : '08:00';
        $end = preg_match('/^\d{2}:\d{2}$/', (string)($body['end'] ?? '21:00')) ? (string)$body['end'] : '21:00';
        return ['enabled' => (bool)($body['enabled'] ?? false), 'start' => $start, 'end' => $end, 'days' => $days];
    }

    private function loadDatabase(bool $includeLogs = true): array
    {
        return $this->store->loadSnapshot($includeLogs);
    }

    private function canUseLiteSnapshot(string $path): bool
    {
        if ($path === '/policy-check') return true;
        if ($path === '/children/overview' || str_starts_with($path, '/dashboard/overview/')) return true;
        if (preg_match('#^/log/(summary|statistic-year|statistic-month)/#', $path)) return true;
        if (preg_match('#^/log/[^/]+$#', $path)) return true;
        if (preg_match('#^/report/[^/]+$#', $path)) return true;
        if (preg_match('#^/tracker-status/[^/]+$#', $path)) return true;
        if ($path === '/child' || $path === '/child/') return true;
        return false;
    }

    private function emptyDb(): array
    {
        return $this->store->emptySnapshot();
    }

    private function save(): void
    {
        $this->store->saveSnapshot($this->db);
    }

    private function publicUser(array $user): array
    {
        return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'createdAt' => $user['createdAt'] ?? null, 'updatedAt' => $user['updatedAt'] ?? null];
    }

    private function publicChild(array $child): array
    {
        return ['id' => $child['id'], 'parentId' => $child['parentId'], 'name' => $child['name'], 'username' => $child['username'] ?? '', 'schedule' => $child['schedule'] ?? $this->defaultSchedule(), 'createdAt' => $child['createdAt'] ?? null, 'updatedAt' => $child['updatedAt'] ?? null];
    }

    private function normalizeUsername(string $username): string { return strtolower(trim($username)); }
    private function isValidUsername(string $username): bool { return (bool)preg_match('/^[a-z0-9._-]{3,40}$/', $username); }
    private function childUsernameExists(string $username, string $exceptChildId = ''): bool
    {
        $username = $this->normalizeUsername($username);
        foreach ($this->db['children'] as $child) {
            if ($exceptChildId !== '' && (string)($child['id'] ?? '') === $exceptChildId) continue;
            if ($this->normalizeUsername((string)($child['username'] ?? '')) === $username) return true;
        }
        return false;
    }
    private function findUserIndexByEmail(string $email): ?int
    {
        foreach ($this->db['users'] as $i => $user) if (strtolower((string)$user['email']) === $email) return $i;
        return null;
    }
    private function findUserIndexById(string $id): ?int
    {
        foreach ($this->db['users'] as $i => $user) if ((string)$user['id'] === $id) return $i;
        return null;
    }
    private function findChildIndex(string $parentId, string $childId): ?int
    {
        foreach ($this->db['children'] as $i => $child) if ((string)$child['parentId'] === (string)$parentId && (string)$child['id'] === $childId) return $i;
        return null;
    }
    private function findChildById(string $childId): ?array
    {
        foreach ($this->db['children'] as $child) if ((string)$child['id'] === $childId) return $child;
        return null;
    }
    private function childForUser(string $parentId, string $childId): ?array
    {
        foreach ($this->db['children'] as $child) if ((string)$child['parentId'] === (string)$parentId && (string)$child['id'] === $childId) return $child;
        return null;
    }
    private function normalizePattern(string $pattern): string
    {
        $pattern = strtolower(trim($pattern));
        $pattern = preg_replace('#^https?://#', '', $pattern) ?? $pattern;
        $pattern = preg_replace('#^www\.#', '', $pattern) ?? $pattern;
        return trim($pattern, "/ \t\n\r\0\x0B");
    }
    private function normalizeRulePattern(string $pattern, string $matchType): string
    {
        $pattern = strtolower(trim($pattern));
        $pattern = preg_replace('#^https?://#', '', $pattern) ?? $pattern;
        $pattern = preg_replace('#^www\.#', '', $pattern) ?? $pattern;

        if ($matchType === 'domain') {
            $pattern = explode('/', $pattern)[0] ?? $pattern;
            return trim($pattern, "/ \t\n\r\0\x0B");
        }

        return trim($pattern, "/ \t\n\r\0\x0B");
    }
    private function hostOf(string $url): string
    {
        return $this->normalizePattern((string)(parse_url($url, PHP_URL_HOST) ?: $url));
    }
    private function id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(6)); }
    private function now(): string { return date(DATE_ATOM); }
    private function json(array $payload, int $status = 200): array
    {
        if (!array_key_exists('ok', $payload)) $payload = ['ok' => true] + $payload;
        return ['status' => $status, 'payload' => $payload];
    }
}
