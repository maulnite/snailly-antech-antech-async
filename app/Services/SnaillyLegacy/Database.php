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

        // Jangan jalankan migrasi penuh di setiap request.
        // Default-nya hanya migrate jika tabel utama belum ada; ini jauh lebih ringan
        // untuk dashboard dan extension yang sering memanggil API.
        if ($this->shouldMigrate()) {
            $this->migrate();
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function loadSnapshot(bool $includeLogs = true): array
    {
        return [
            'users' => $this->loadUsers(),
            'tokens' => $this->loadTokens(),
            'children' => $this->loadChildren(),
            'logs' => $includeLogs ? $this->loadLogs() : [],
            'rules' => $this->loadRules(),
            'accessRequests' => $this->loadAccessRequests(),
            'trackerStatus' => $this->loadTrackerStatus(),
            '_partialLogs' => !$includeLogs,
        ];
    }

    public function saveSnapshot(array $db): void
    {
        $db = array_merge($this->emptySnapshot(), $db);
        $partialLogs = !empty($db['_partialLogs']);
        $this->pdo->beginTransaction();
        try {
            // Upsert hanya baris yang berubah. Ini mengganti pola lama yang menghapus
            // semua tabel lalu insert ulang, yang sangat berat saat log sudah banyak.
            $this->saveUsers($db['users']);
            $this->saveChildren($db['children']);
            $this->saveTokens($db['tokens']);
            $this->saveRules($db['rules']);
            if (!$partialLogs) {
                $this->saveLogs($db['logs']);
            }
            $this->saveAccessRequests($db['accessRequests']);
            $this->saveTrackerStatus($db['trackerStatus'] ?? []);

            $this->deleteMissing('tokens', 'token', array_keys($db['tokens']));
            $this->deleteMissing('rules', 'id', array_map(fn($r) => (string)($r['id'] ?? ''), $db['rules']));
            $this->deleteMissing('access_requests', 'id', array_map(fn($r) => (string)($r['id'] ?? ''), $db['accessRequests']));
            $this->deleteMissing('tracker_status', 'child_id', array_map(fn($s, $k) => (string)($s['childId'] ?? $k), $db['trackerStatus'] ?? [], array_keys($db['trackerStatus'] ?? [])));
            if (!$partialLogs) {
                $this->deleteMissing('activity_logs', 'log_id', array_map(fn($l) => (string)($l['log_id'] ?? ''), $db['logs']));
            }
            $this->deleteMissing('children', 'id', array_map(fn($c) => (string)($c['id'] ?? ''), $db['children']));

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function emptySnapshot(): array
    {
        return ['users' => [], 'tokens' => [], 'children' => [], 'logs' => [], 'rules' => [], 'accessRequests' => [], 'trackerStatus' => [], '_partialLogs' => false];
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

    private function shouldMigrate(): bool
    {
        $mode = 'missing_only';
        if (function_exists('env')) {
            $mode = strtolower((string) env('SNAILLY_AUTO_MIGRATE', 'missing_only'));
        }

        if (in_array($mode, ['1', 'true', 'yes', 'always'], true)) {
            return true;
        }

        if (in_array($mode, ['0', 'false', 'no', 'never', 'off'], true)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'parents'");
            $stmt->execute();
            return $stmt->fetchColumn() === false;
        } catch (Throwable $e) {
            return true;
        }
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
        $stmt = $this->pdo->prepare('INSERT INTO parents (id, name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
        foreach ($users as $u) {
            $stmt->execute([
                (string)$u['id'], (string)$u['name'], (string)$u['email'], (string)$u['passwordHash'],
                $this->sqlDate($u['createdAt'] ?? null), $this->sqlDate($u['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveChildren(array $children): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO children (id, parent_id, name, username, password_hash, schedule_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), name = VALUES(name), username = VALUES(username), password_hash = VALUES(password_hash), schedule_json = VALUES(schedule_json), updated_at = VALUES(updated_at)');
        foreach ($children as $c) {
            $stmt->execute([
                (string)$c['id'], (string)$c['parentId'], (string)$c['name'], (string)($c['username'] ?? ''), (string)($c['passwordHash'] ?? ''),
                $this->jsonEncode($c['schedule'] ?? null), $this->sqlDate($c['createdAt'] ?? null), $this->sqlDate($c['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveTokens(array $tokens): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tokens (token, user_id, child_id, role, created_at, expires_at, last_used_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), child_id = VALUES(child_id), role = VALUES(role), expires_at = VALUES(expires_at), last_used_at = VALUES(last_used_at), revoked_at = VALUES(revoked_at)');
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
        $stmt = $this->pdo->prepare('INSERT INTO rules (id, parent_id, child_id, type, match_type, pattern, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), child_id = VALUES(child_id), type = VALUES(type), match_type = VALUES(match_type), pattern = VALUES(pattern), category = VALUES(category), updated_at = VALUES(updated_at)');
        foreach ($rules as $r) {
            $stmt->execute([
                (string)$r['id'], (string)$r['parentId'], (string)($r['childId'] ?? 'ALL'), (string)$r['type'], (string)($r['matchType'] ?? 'domain'),
                (string)$r['pattern'], (string)($r['category'] ?? ''), $this->sqlDate($r['createdAt'] ?? null), $this->sqlDate($r['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveLogs(array $logs): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO activity_logs (log_id, child_id, parent_id, url, web_title, web_description, detail_url, grant_access, classified_url_json, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE child_id = VALUES(child_id), parent_id = VALUES(parent_id), url = VALUES(url), web_title = VALUES(web_title), web_description = VALUES(web_description), detail_url = VALUES(detail_url), grant_access = VALUES(grant_access), classified_url_json = VALUES(classified_url_json), source = VALUES(source), updated_at = VALUES(updated_at)');
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
        $stmt = $this->pdo->prepare('INSERT INTO access_requests (id, parent_id, child_id, url, host, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), child_id = VALUES(child_id), url = VALUES(url), host = VALUES(host), reason = VALUES(reason), status = VALUES(status), updated_at = VALUES(updated_at)');
        foreach ($requests as $r) {
            $stmt->execute([
                (string)$r['id'], (string)$r['parentId'], (string)$r['childId'], (string)$r['url'], (string)($r['host'] ?? ''),
                (string)($r['reason'] ?? ''), (string)($r['status'] ?? 'pending'), $this->sqlDate($r['createdAt'] ?? null), $this->sqlDate($r['updatedAt'] ?? null),
            ]);
        }
    }

    private function saveTrackerStatus(array $items): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tracker_status (child_id, parent_id, enabled, block_dangerous, last_seen_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), enabled = VALUES(enabled), block_dangerous = VALUES(block_dangerous), last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)');
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

    private function deleteMissing(string $table, string $pk, array $ids): void
    {
        $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), fn($id) => $id !== ''));
        if (!$ids) {
            $this->pdo->exec("DELETE FROM {$table}");
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE {$pk} NOT IN ({$placeholders})");
        $stmt->execute($ids);
    }

    public function insertLog(array $log): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO activity_logs (log_id, child_id, parent_id, url, web_title, web_description, detail_url, grant_access, classified_url_json, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE child_id = VALUES(child_id), parent_id = VALUES(parent_id), url = VALUES(url), web_title = VALUES(web_title), web_description = VALUES(web_description), detail_url = VALUES(detail_url), grant_access = VALUES(grant_access), classified_url_json = VALUES(classified_url_json), source = VALUES(source), updated_at = VALUES(updated_at)');
        $stmt->execute([
            (string)$log['log_id'],
            (string)$log['child_id'],
            (string)$log['parentId'],
            (string)$log['url'],
            (string)($log['web_title'] ?? ''),
            (string)($log['web_description'] ?? ''),
            (string)($log['detail_url'] ?? $log['url'] ?? ''),
            $this->boolToDb($log['grant_access'] ?? null),
            $this->jsonEncode($log['classified_url'] ?? []),
            (string)($log['source'] ?? 'extension'),
            $this->sqlDate($log['createdAt'] ?? null),
            $this->sqlDate($log['updatedAt'] ?? null),
        ]);
    }

    public function recentDuplicateLog(string $parentId, string $childId, string $url, int $seconds = 12): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM activity_logs WHERE parent_id = ? AND child_id = ? AND url = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$parentId, $childId, $url, max(1, $seconds)]);
        $row = $stmt->fetch();
        return $row ? $this->mapLogRow($row) : null;
    }

    public function updateLogClassification(string $logId, bool $grantAccess, array $classifiedUrl): void
    {
        $stmt = $this->pdo->prepare('UPDATE activity_logs SET grant_access = ?, classified_url_json = ?, updated_at = NOW() WHERE log_id = ?');
        $stmt->execute([$grantAccess ? 1 : 0, $this->jsonEncode($classifiedUrl), $logId]);
    }

    public function logSummary(string $parentId, string $childId): array
    {
        [$where, $params] = $this->logWhere($parentId, $childId);
        $sql = "SELECT grant_access, classified_url_json, COUNT(*) AS total FROM activity_logs {$where} GROUP BY grant_access, classified_url_json";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $safe = 0;
        $danger = 0;
        $categories = [];
        foreach ($stmt->fetchAll() as $row) {
            $count = (int)($row['total'] ?? 0);
            $classification = $this->firstClassification($row['classified_url_json'] ?? null);
            $category = (string)($classification['category'] ?? (((int)($row['grant_access'] ?? 1) === 1) ? 'Safe' : 'Risky'));
            $categories[$category] = ($categories[$category] ?? 0) + $count;
            if ($this->isLogRowSafe($row)) $safe += $count; else $danger += $count;
        }
        return ['totalSafeWebsites' => $safe, 'totalDangerousWebsites' => $danger, 'categories' => $categories];
    }

    public function statisticYear(string $parentId, string $childId, int $year): array
    {
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $stats = [];
        foreach ($months as $i => $name) $stats[$i + 1] = ['month' => $name, 'Good' => 0, 'Bad' => 0];
        [$where, $params] = $this->logWhere($parentId, $childId, 'YEAR(created_at) = ?');
        $params[] = $year;
        $sql = "SELECT MONTH(created_at) AS period_key, grant_access, classified_url_json, COUNT(*) AS total FROM activity_logs {$where} GROUP BY MONTH(created_at), grant_access, classified_url_json";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $m = (int)($row['period_key'] ?? 0);
            if (!isset($stats[$m])) continue;
            if ($this->isLogRowSafe($row)) $stats[$m]['Good'] += (int)$row['total']; else $stats[$m]['Bad'] += (int)$row['total'];
        }
        return array_values($stats);
    }

    public function statisticMonth(string $parentId, string $childId, string $date): array
    {
        $parts = explode('-', $date);
        $year = (int)($parts[0] ?? date('Y')) ?: (int)date('Y');
        $month = (int)($parts[1] ?? date('n')) ?: (int)date('n');
        $days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $stats = [];
        for ($d = 1; $d <= $days; $d++) $stats[$d] = ['month' => (string)$d, 'Good' => 0, 'Bad' => 0];
        [$where, $params] = $this->logWhere($parentId, $childId, 'YEAR(created_at) = ? AND MONTH(created_at) = ?');
        $params[] = $year;
        $params[] = $month;
        $sql = "SELECT DAY(created_at) AS period_key, grant_access, classified_url_json, COUNT(*) AS total FROM activity_logs {$where} GROUP BY DAY(created_at), grant_access, classified_url_json";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $d = (int)($row['period_key'] ?? 0);
            if (!isset($stats[$d])) continue;
            if ($this->isLogRowSafe($row)) $stats[$d]['Good'] += (int)$row['total']; else $stats[$d]['Bad'] += (int)$row['total'];
        }
        return array_values($stats);
    }

    public function listLogs(string $parentId, string $childId, array $query): array
    {
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(500, (int)($query['limit'] ?? 10)));
        [$where, $params] = $this->buildLogFilterWhere($parentId, $childId, $query);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs l {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT l.*, c.name AS child_name FROM activity_logs l LEFT JOIN children c ON c.id = l.child_id {$where} ORDER BY l.created_at DESC LIMIT {$limit} OFFSET " . (($page - 1) * $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) $items[] = $this->decorateLogRow($row);
        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'totalPage' => max(1, (int)ceil($total / $limit))];
    }

    public function clearLogs(string $parentId, string $childId, array $query): int
    {
        [$where, $params] = $this->buildLogFilterWhere($parentId, $childId, $query);
        $select = $this->pdo->prepare("SELECT l.log_id FROM activity_logs l {$where}");
        $select->execute($params);
        $ids = array_map('strval', $select->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM activity_logs WHERE log_id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public function report(string $parentId, string $childId, array $query): array
    {
        // Do not call listLogs() for report totals. listLogs() intentionally caps
        // page size to 100 for UI pagination, while report cards must count the
        // whole filtered dataset.
        [$where, $params] = $this->buildLogFilterWhere($parentId, $childId, $query);

        $stmt = $this->pdo->prepare("SELECT l.grant_access, l.classified_url_json, l.url FROM activity_logs l {$where} ORDER BY l.created_at DESC");
        $stmt->execute($params);

        $total = 0;
        $safe = 0;
        $danger = 0;
        $categories = [];
        $hosts = [];
        $riskyHosts = [];

        foreach ($stmt->fetchAll() as $row) {
            $total++;
            $isSafe = $this->isLogRowSafe($row);
            if ($isSafe) $safe++; else $danger++;

            $classification = $this->firstClassification($row['classified_url_json'] ?? null);
            $cat = (string)($classification['category'] ?? ($isSafe ? 'Safe' : 'Risky'));
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;

            $host = strtolower((string)(parse_url((string)($row['url'] ?? ''), PHP_URL_HOST) ?: ''));
            if ($host !== '') {
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
                if (!$isSafe) $riskyHosts[$host] = ($riskyHosts[$host] ?? 0) + 1;
            }
        }

        arsort($categories);
        arsort($hosts);
        arsort($riskyHosts);

        $recentLimit = max(1, min(500, (int)($query['logLimit'] ?? $query['recentLimit'] ?? 50)));

        $recentQuery = $query;
        $recentQuery['page'] = 1;
        $recentQuery['limit'] = $recentLimit;

        $recent = $this->listLogs($parentId, $childId, $recentQuery)['items'];

        return [
            'period' => (string)($query['period'] ?? 'daily'),
            'total' => $total,
            'safe' => $safe,
            'danger' => $danger,
            'safePercent' => $total ? round(($safe / $total) * 100) : 100,
            'categories' => $categories,
            'topHosts' => array_slice($hosts, 0, 8, true),
            'topRiskyHosts' => array_slice($riskyHosts, 0, 8, true),
            'recent' => $recent,
        ];
    }

    public function cleanupOldLogs(int $days = 30): int
    {
        $days = max(1, min(365, $days));
        $stmt = $this->pdo->prepare('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    private function buildLogFilterWhere(string $parentId, string $childId, array $query): array
    {
        [$where, $params] = $this->logWhere($parentId, $childId, '', 'l');
        $clauses = [];

        $period = (string)($query['period'] ?? 'all');
        if ($period === 'daily') {
            $year = (int)($query['year'] ?? date('Y'));
            $month = (int)($query['month'] ?? date('n'));
            $date = (int)($query['date'] ?? date('j'));
            $clauses[] = 'YEAR(l.created_at) = ? AND MONTH(l.created_at) = ? AND DAY(l.created_at) = ?';
            array_push($params, $year, $month, $date);
        } elseif ($period === 'monthly') {
            $year = (int)($query['year'] ?? date('Y'));
            $month = (int)($query['month'] ?? date('n'));
            $clauses[] = 'YEAR(l.created_at) = ? AND MONTH(l.created_at) = ?';
            array_push($params, $year, $month);
        } elseif ($period === 'range') {
            $start = (string)($query['start'] ?? '');
            $end = (string)($query['end'] ?? '');
            if ($start !== '') { $clauses[] = 'l.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($start) ?: time()); }
            if ($end !== '') { $clauses[] = 'l.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($end) ?: time()); }
        }

        $status = strtolower((string)($query['status'] ?? 'all'));
        if (in_array($status, ['positive', 'safe', 'allowed'], true)) {
            $clauses[] = 'l.grant_access = 1';
        } elseif (in_array($status, ['negative', 'danger', 'blocked', 'bad'], true)) {
            $clauses[] = '(l.grant_access = 0 OR l.grant_access IS NULL)';
        } elseif (in_array($status, ['pending', 'warning'], true)) {
            $clauses[] = 'l.grant_access IS NULL';
        }

        $category = trim((string)($query['category'] ?? ''));
        if ($category !== '') {
            $clauses[] = 'LOWER(l.classified_url_json) LIKE ?';
            $params[] = '%' . strtolower($category) . '%';
        }

        $q = trim((string)($query['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(l.url LIKE ? OR l.web_title LIKE ? OR l.web_description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        if ($clauses) {
            $where .= ' AND ' . implode(' AND ', $clauses);
        }
        return [$where, $params];
    }

    private function logWhere(string $parentId, string $childId, string $extra = '', string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $where = "WHERE {$prefix}parent_id = ?";
        $params = [$parentId];
        if ($childId !== 'ALL' && $childId !== '') {
            $where .= " AND {$prefix}child_id = ?";
            $params[] = $childId;
        }
        if ($extra !== '') {
            $where .= ' AND ' . $extra;
        }
        return [$where, $params];
    }

    private function mapLogRow(array $r): array
    {
        return [
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
        ];
    }

    private function decorateLogRow(array $row): array
    {
        $log = $this->mapLogRow($row);
        $log['child'] = ['id' => $log['child_id'], 'name' => (string)($row['child_name'] ?? '-')];
        $classification = $log['classified_url'][0] ?? [];
        $log['risk_category'] = (string)($classification['category'] ?? ($this->isDecoratedLogSafe($log) ? 'Safe' : 'Risky'));
        $log['risk_reason'] = (string)($classification['reason'] ?? '');
        return $log;
    }

    private function firstClassification(mixed $json): array
    {
        $items = $this->jsonDecode($json, []);
        return is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : [];
    }

    private function isLogRowSafe(array $row): bool
    {
        if ($row['grant_access'] !== null) return (int)$row['grant_access'] === 1;
        $classification = $this->firstClassification($row['classified_url_json'] ?? null);
        return (string)($classification['FINAL_label'] ?? 'aman') === 'aman';
    }

    private function isDecoratedLogSafe(array $log): bool
    {
        if (($log['grant_access'] ?? null) === true) return true;
        if (($log['grant_access'] ?? null) === false) return false;
        $classification = $log['classified_url'][0] ?? [];
        return (string)($classification['FINAL_label'] ?? 'aman') === 'aman';
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
