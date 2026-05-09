<?php
declare(strict_types=1);

/**
 * SnaillyDatabase
 *
 * Storage MySQL untuk Snailly Kids. Class ini sengaja menyediakan snapshot array
 * agar backend lama tetap bisa jalan, tetapi data fisiknya sudah tersimpan dalam tabel MySQL.
 */
final class SnaillyDatabase
{
    private PDO $pdo;

    public function __construct(?array $config = null)
    {
        $config = $config ?? $this->loadConfig();
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 3306);
        $database = (string)($config['database'] ?? 'snailly_kids');
        $username = (string)($config['username'] ?? 'root');
        $password = (string)($config['password'] ?? '');
        $charset = (string)($config['charset'] ?? 'utf8mb4');

        $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
        try {
            $server = new PDO($serverDsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$this->safeIdentifier($database)}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
        } catch (PDOException $e) {
            throw new RuntimeException('Gagal connect ke MySQL. Pastikan MySQL XAMPP sudah Start dan config/database.php benar. Detail: ' . $e->getMessage(), 500);
        }

        $dbDsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        $this->pdo = new PDO($dbDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function loadSnapshot(): array
    {
        return [
            'users' => $this->loadUsers(),
            'tokens' => $this->loadTokens(),
            'children' => $this->loadChildren(),
            'logs' => $this->loadLogs(),
            'rules' => $this->loadRules(),
            'accessRequests' => $this->loadAccessRequests(),
            'trackerStatus' => $this->loadTrackerStatus(),
        ];
    }

    public function saveSnapshot(array $db): void
    {
        $db = array_merge($this->emptySnapshot(), $db);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (['tracker_status', 'access_requests', 'activity_logs', 'rules', 'tokens', 'children', 'parents'] as $table) {
                $this->pdo->exec("DELETE FROM {$table}");
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

            $this->saveUsers($db['users']);
            $this->saveChildren($db['children']);
            $this->saveTokens($db['tokens']);
            $this->saveRules($db['rules']);
            $this->saveLogs($db['logs']);
            $this->saveAccessRequests($db['accessRequests']);
            $this->saveTrackerStatus($db['trackerStatus'] ?? []);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function emptySnapshot(): array
    {
        return ['users' => [], 'tokens' => [], 'children' => [], 'logs' => [], 'rules' => [], 'accessRequests' => [], 'trackerStatus' => []];
    }

    private function loadConfig(): array
    {
        // Laravel-first configuration. The same class can still run outside Laravel
        // by falling back to the old config/database.php file when config() is absent.
        if (function_exists('config')) {
            $mysql = config('database.connections.mysql', []);
            return [
                'host' => (string)($mysql['host'] ?? env('DB_HOST', '127.0.0.1')),
                'port' => (int)($mysql['port'] ?? env('DB_PORT', 3306)),
                'database' => (string)($mysql['database'] ?? env('DB_DATABASE', 'snailly_kids')),
                'username' => (string)($mysql['username'] ?? env('DB_USERNAME', 'root')),
                'password' => (string)($mysql['password'] ?? env('DB_PASSWORD', '')),
                'charset' => (string)($mysql['charset'] ?? 'utf8mb4'),
            ];
        }

        $path = __DIR__ . '/../config/database.php';
        if (is_file($path)) {
            $config = require $path;
            if (is_array($config)) return $config;
        }
        return [];
    }

    private function safeIdentifier(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
        if ($name === '') $name = 'snailly_kids';
        return $name;
    }

    private function migrate(): void
    {
        $sql = file_get_contents(function_exists('base_path') ? base_path('database/snailly_schema.sql') : __DIR__ . '/../database/schema.sql');
        if ($sql === false) throw new RuntimeException('database/snailly_schema.sql tidak ditemukan.', 500);

        $this->pdo->exec($sql);
        $this->ensureTokensSchema();
        $this->ensureRulesSchema();
    }

    private function ensureTokensSchema(): void
    {
        // Upgrade existing local databases created by older project ZIPs.
        $columns = $this->pdo->query("SHOW COLUMNS FROM tokens")->fetchAll();
        $names = array_map(fn($c) => (string)$c['Field'], $columns);
        if (!in_array('expires_at', $names, true)) {
            $this->pdo->exec('ALTER TABLE tokens ADD expires_at DATETIME NULL AFTER created_at');
        }
        if (!in_array('last_used_at', $names, true)) {
            $this->pdo->exec('ALTER TABLE tokens ADD last_used_at DATETIME NULL AFTER expires_at');
        }
        if (!in_array('revoked_at', $names, true)) {
            $this->pdo->exec('ALTER TABLE tokens ADD revoked_at DATETIME NULL AFTER last_used_at');
        }
        try {
            $this->pdo->exec("ALTER TABLE tokens MODIFY role ENUM('parent','child','tracker') NOT NULL DEFAULT 'parent'");
        } catch (Throwable $e) {
            // If the local MySQL variant already has a compatible type, keep going.
        }
    }
    private function ensureRulesSchema(): void
    {
        try {
            $this->pdo->exec("ALTER TABLE rules MODIFY type VARCHAR(20) NOT NULL DEFAULT 'block'");
        } catch (Throwable $e) {
            // Keep going if already compatible.
        }

        try {
            $this->pdo->exec("ALTER TABLE rules MODIFY match_type VARCHAR(30) NOT NULL DEFAULT 'domain'");
        } catch (Throwable $e) {
            // Keep going if already compatible.
        }
    }
    private function loadUsers(): array
    {
        $rows = $this->pdo->query('SELECT * FROM parents ORDER BY created_at ASC')->fetchAll();
        return array_map(fn(array $r): array => [
            'id' => (string)$r['id'],
            'name' => (string)$r['name'],
            'email' => (string)$r['email'],
            'passwordHash' => (string)$r['password_hash'],
            'createdAt' => $this->dt($r['created_at'] ?? null),
            'updatedAt' => $this->dt($r['updated_at'] ?? null),
        ], $rows);
    }

    private function loadChildren(): array
    {
        $rows = $this->pdo->query('SELECT * FROM children ORDER BY created_at ASC')->fetchAll();
        return array_map(fn(array $r): array => [
            'id' => (string)$r['id'],
            'parentId' => (string)$r['parent_id'],
            'name' => (string)$r['name'],
            'username' => (string)$r['username'],
            'passwordHash' => (string)$r['password_hash'],
            'schedule' => $this->jsonDecode($r['schedule_json'] ?? null, ['enabled' => false, 'start' => '08:00', 'end' => '21:00', 'days' => ['mon','tue','wed','thu','fri','sat','sun']]),
            'createdAt' => $this->dt($r['created_at'] ?? null),
            'updatedAt' => $this->dt($r['updated_at'] ?? null),
        ], $rows);
    }

    private function loadTokens(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tokens ORDER BY created_at ASC')->fetchAll();
        $tokens = [];
        foreach ($rows as $r) {
            $token = (string)$r['token'];
            $tokens[$token] = [
                'userId' => (string)$r['user_id'],
                'role' => (string)$r['role'],
                'createdAt' => $this->dt($r['created_at'] ?? null),
                'expiresAt' => empty($r['expires_at']) ? null : $this->dt($r['expires_at']),
                'lastUsedAt' => empty($r['last_used_at']) ? null : $this->dt($r['last_used_at']),
                'revokedAt' => empty($r['revoked_at']) ? null : $this->dt($r['revoked_at']),
            ];
            if (!empty($r['child_id'])) $tokens[$token]['childId'] = (string)$r['child_id'];
        }
        return $tokens;
    }

    private function loadRules(): array
    {
        $rows = $this->pdo->query('SELECT * FROM rules ORDER BY created_at ASC')->fetchAll();
        return array_map(fn(array $r): array => [
            'id' => (string)$r['id'],
            'parentId' => (string)$r['parent_id'],
            'childId' => (string)$r['child_id'],
            'type' => (string)$r['type'],
            'matchType' => (string)$r['match_type'],
            'pattern' => (string)$r['pattern'],
            'category' => (string)$r['category'],
            'createdAt' => $this->dt($r['created_at'] ?? null),
            'updatedAt' => $this->dt($r['updated_at'] ?? null),
        ], $rows);
    }

    private function loadLogs(): array
    {
        $rows = $this->pdo->query('SELECT * FROM activity_logs ORDER BY created_at ASC')->fetchAll();
        return array_map(fn(array $r): array => [
            'log_id' => (string)$r['log_id'],
            'child_id' => (string)$r['child_id'],
            'parentId' => (string)$r['parent_id'],
            'url' => (string)$r['url'],
            'web_title' => (string)$r['web_title'],
            'web_description' => (string)$r['web_description'],
            'detail_url' => (string)$r['detail_url'],
            'grant_access' => $this->nullableBool($r['grant_access']),
            'classified_url' => $this->jsonDecode($r['classified_url_json'] ?? null, []),
            'source' => (string)$r['source'],
            'createdAt' => $this->dt($r['created_at'] ?? null),
            'updatedAt' => $this->dt($r['updated_at'] ?? null),
        ], $rows);
    }

    private function loadAccessRequests(): array
    {
        $rows = $this->pdo->query('SELECT * FROM access_requests ORDER BY created_at ASC')->fetchAll();
        return array_map(fn(array $r): array => [
            'id' => (string)$r['id'],
            'parentId' => (string)$r['parent_id'],
            'childId' => (string)$r['child_id'],
            'url' => (string)$r['url'],
            'host' => (string)$r['host'],
            'reason' => (string)$r['reason'],
            'status' => (string)$r['status'],
            'createdAt' => $this->dt($r['created_at'] ?? null),
            'updatedAt' => $this->dt($r['updated_at'] ?? null),
        ], $rows);
    }

    private function loadTrackerStatus(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tracker_status ORDER BY updated_at ASC')->fetchAll();
        $items = [];
        foreach ($rows as $r) {
            $childId = (string)$r['child_id'];
            $items[$childId] = [
                'childId' => $childId,
                'parentId' => (string)$r['parent_id'],
                'enabled' => (bool)((int)$r['enabled']),
                'blockDangerous' => (bool)((int)$r['block_dangerous']),
                'lastSeenAt' => $this->dt($r['last_seen_at'] ?? null),
                'updatedAt' => $this->dt($r['updated_at'] ?? null),
            ];
        }
        return $items;
    }

    private function saveUsers(array $users): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO parents (id, name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($users as $u) {
            $stmt->execute([
                (string)$u['id'], (string)$u['name'], (string)$u['email'], (string)$u['passwordHash'],
                $this->sqlDate($u['createdAt'] ?? null), $this->sqlDate($u['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveChildren(array $children): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO children (id, parent_id, name, username, password_hash, schedule_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($children as $c) {
            $stmt->execute([
                (string)$c['id'], (string)$c['parentId'], (string)$c['name'], (string)($c['username'] ?? ''), (string)($c['passwordHash'] ?? ''),
                $this->jsonEncode($c['schedule'] ?? null), $this->sqlDate($c['createdAt'] ?? null), $this->sqlDate($c['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveTokens(array $tokens): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tokens (token, user_id, child_id, role, created_at, expires_at, last_used_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($tokens as $token => $session) {
            $stmt->execute([
                (string)$token,
                (string)($session['userId'] ?? ''),
                (($session['childId'] ?? '') !== '') ? (string)$session['childId'] : null,
                (string)($session['role'] ?? 'parent'),
                $this->sqlDate($session['createdAt'] ?? null),
                !empty($session['expiresAt']) ? $this->sqlDate($session['expiresAt']) : null,
                !empty($session['lastUsedAt']) ? $this->sqlDate($session['lastUsedAt']) : null,
                !empty($session['revokedAt']) ? $this->sqlDate($session['revokedAt']) : null,
            ]);
        }
    }

    private function saveRules(array $rules): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rules (id, parent_id, child_id, type, match_type, pattern, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rules as $r) {
            $stmt->execute([
                (string)$r['id'], (string)$r['parentId'], (string)($r['childId'] ?? 'ALL'), (string)$r['type'], (string)($r['matchType'] ?? 'domain'),
                (string)$r['pattern'], (string)($r['category'] ?? ''), $this->sqlDate($r['createdAt'] ?? null), $this->sqlDate($r['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveLogs(array $logs): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO activity_logs (log_id, child_id, parent_id, url, web_title, web_description, detail_url, grant_access, classified_url_json, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($logs as $l) {
            $stmt->execute([
                (string)$l['log_id'], (string)$l['child_id'], (string)$l['parentId'], (string)$l['url'], (string)($l['web_title'] ?? ''),
                (string)($l['web_description'] ?? ''), (string)($l['detail_url'] ?? $l['url'] ?? ''), $this->boolToDb($l['grant_access'] ?? null),
                $this->jsonEncode($l['classified_url'] ?? []), (string)($l['source'] ?? 'extension'),
                $this->sqlDate($l['createdAt'] ?? null), $this->sqlDate($l['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveAccessRequests(array $requests): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO access_requests (id, parent_id, child_id, url, host, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($requests as $r) {
            $stmt->execute([
                (string)$r['id'], (string)$r['parentId'], (string)$r['childId'], (string)$r['url'], (string)($r['host'] ?? ''),
                (string)($r['reason'] ?? ''), (string)($r['status'] ?? 'pending'), $this->sqlDate($r['createdAt'] ?? null), $this->sqlDate($r['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveTrackerStatus(array $items): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tracker_status (child_id, parent_id, enabled, block_dangerous, last_seen_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($items as $key => $s) {
            $childId = (string)($s['childId'] ?? $key);
            if ($childId === '') continue;
            $stmt->execute([
                $childId,
                (string)($s['parentId'] ?? ''),
                !empty($s['enabled']) ? 1 : 0,
                !empty($s['blockDangerous']) ? 1 : 0,
                $this->sqlDate($s['lastSeenAt'] ?? $s['updatedAt'] ?? null),
                $this->sqlDate($s['updatedAt'] ?? null),
            ]);
        }
    }

    private function jsonDecode(mixed $value, mixed $fallback): mixed
    {
        if ($value === null || $value === '') return $fallback;
        $decoded = json_decode((string)$value, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $fallback : $decoded;
    }

    private function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) return null;
        return (int)$value === 1;
    }

    private function boolToDb(mixed $value): ?int
    {
        if ($value === null) return null;
        return $value ? 1 : 0;
    }

    private function sqlDate(mixed $value): string
    {
        $ts = strtotime((string)($value ?: 'now')) ?: time();
        return date('Y-m-d H:i:s', $ts);
    }

    private function dt(mixed $value): string
    {
        if ($value === null || $value === '') return date(DATE_ATOM);
        $ts = strtotime((string)$value) ?: time();
        return date(DATE_ATOM, $ts);
    }
}
