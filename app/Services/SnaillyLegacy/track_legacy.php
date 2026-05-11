<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PolicyEngine.php';
header('Content-Type: application/json; charset=utf-8');
if (!function_exists('snailly_apply_cors')) {
    function snailly_apply_cors(): void {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && (str_starts_with($origin, 'chrome-extension://') || in_array(parse_url($origin, PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Headers: Content-Type, X-Snailly-Authorization, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }
}
snailly_apply_cors();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'message' => 'Only POST is allowed.'], 405);

$dbStore = new \SnaillyDatabase();
$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) respond(['ok' => false, 'message' => 'Invalid JSON body.'], 400);

$url = trim((string)($body['url'] ?? ''));
if (strlen($url) > 2000) {
    $url = substr($url, 0, 2000);
}
$childId = trim((string)($body['childId'] ?? ''));
$title = trim((string)($body['title'] ?? ''));
$source = trim((string)($body['source'] ?? 'extension')) ?: 'extension';
if ($url === '' || !isHttpUrl($url)) respond(['ok' => false, 'message' => 'A valid http/https URL is required.'], 422);
if (isSnaillyInternalUrl($url)) {
    respond(['ok' => true, 'skipped' => true, 'message' => 'Internal Snailly URL ignored.', 'blocked' => false, 'grant_access' => true], 200);
}
$token = bearerToken();
if ($token === '') respond(['ok' => false, 'message' => 'Missing extension auth token. Please login from the extension popup.'], 401);

[$db, $fp] = loadDbLocked($dbStore);
try {
    $session = tokenSession($db, $token);
    if ($session === null) respondAndUnlock($fp, ['ok' => false, 'message' => 'Invalid or expired token. Please login again from the extension popup.'], 401);
    $userId = (string)$session['userId'];
    if ($childId === '' && !empty($session['childId'])) $childId = (string)$session['childId'];
    $child = findChild($db, $userId, $childId);
    if ($child === null) respondAndUnlock($fp, ['ok' => false, 'message' => 'Child not found. Create/select a child first.'], 404);

    $now = date(DATE_ATOM);
    $duplicate = $dbStore->recentDuplicateLog($userId, (string)$child['id'], $url, 12);
    if ($duplicate !== null) {
        // Duplicate logs are not saved again, but the policy is still rechecked.
        // This fixes cases where parent just deleted/changed a rule while the same tab is still open.
        $classification = classifyUrl($db, $userId, (string)$child['id'], $url);

        $action = $classification['action'];
        $blocked = $action === 'block';

        $duplicate['grant_access'] = !$blocked;
        $duplicate['classified_url'] = [[
            'FINAL_label' => $classification['label'],
            'action' => $action,
            'category' => $classification['category'],
            'risk' => $classification['risk'],
            'reason' => $classification['reason'],
            'score' => $classification['score'],
        ]];
        $dbStore->updateLogClassification((string)$duplicate['log_id'], !$blocked, $duplicate['classified_url']);

        respondAndUnlock($fp, [
            'ok' => true,
            'message' => 'Duplicate URL policy rechecked.',
            'duplicate' => true,
            'data' => $duplicate,
            'blocked' => $blocked,
            'grant_access' => !$blocked,
            'label' => $classification['label'],
            'action' => $action,
            'category' => $classification['category'],
            'risk' => $classification['risk'],
            'reason' => $classification['reason'],
            'score' => $classification['score'],
        ]);
    }

    $classification = classifyUrl($db, $userId, (string)$child['id'], $url);

    $action = $classification['action'];
    $blocked = $action === 'block';
    $log = [
        'log_id' => id('log'),
        'child_id' => (string)$child['id'],
        'parentId' => $userId,
        'url' => $url,
        'web_title' => $title !== '' ? substr($title, 0, 180) : hostOf($url),
        'web_description' => 'Captured by Snailly browser extension (' . $source . ').',
        'detail_url' => $url,
        'grant_access' => !$blocked,
        'classified_url' => [[
            'FINAL_label' => $classification['label'],
            'action' => $action,
            'category' => $classification['category'],
            'risk' => $classification['risk'],
            'reason' => $classification['reason'],
            'score' => $classification['score'],
        ]],
        'source' => $source,
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    $dbStore->insertLog($log);
    maybeCleanupOldLogs($dbStore);

    respond([
        'ok' => true,
        'message' => 'URL tracked successfully.',
        'data' => $log,
        'blocked' => $blocked,
        'grant_access' => !$blocked,
        'label' => $classification['label'],
        'action' => $action,
        'category' => $classification['category'],
        'risk' => $classification['risk'],
        'reason' => $classification['reason'],
        'score' => $classification['score'],
    ], 201);
} catch (Throwable $e) {
    // MySQL storage does not need manual file unlock.
    respond(['ok' => false, 'message' => $e->getMessage()], 500);
}

function emptyDb(): array { return ['users'=>[], 'tokens'=>[], 'children'=>[], 'logs'=>[], 'rules'=>[], 'accessRequests'=>[], 'trackerStatus'=>[]]; }
function loadDbLocked(SnaillyDatabase $store): array { return [$store->loadSnapshot(false), $store]; }
function saveDbLocked(SnaillyDatabase $store, array $db, $fp): void { $store->saveSnapshot($db); }
function respondAndUnlock($fp, array $payload, int $status=200): void { respond($payload,$status); }
function respond(array $payload, int $status=200): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function bearerToken(): string { $h=$_SERVER['HTTP_X_SNAILLY_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''; $h=trim((string)$h); $h=preg_replace('/^Bearer\s+/i','',$h) ?? $h; return trim($h); }
function tokenSession(array $db, string $token): ?array {
    if (!isset($db['tokens'][$token]['userId'])) return null;
    $session = $db['tokens'][$token];
    $role = (string)($session['role'] ?? '');
    if (!in_array($role, ['tracker', 'parent'], true)) return null;
    if (!empty($session['revokedAt'])) return null;
    $expiresAt = (string)($session['expiresAt'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) !== false && time() > (int)strtotime($expiresAt)) return null;
    return $session;
}
function findChild(array $db, string $userId, string $childId): ?array { $children=array_values(array_filter($db['children']??[], fn($c)=>(string)($c['parentId']??'')===$userId)); if($childId!==''){ foreach($children as $c){ if((string)($c['id']??'')===$childId) return $c; } return null; } return count($children)===1 ? $children[0] : null; }
function recentDuplicate(array $db, string $parentId, string $childId, string $url, int $seconds): ?array { $logs=array_reverse($db['logs']??[]); $now=time(); foreach($logs as $log){ if((string)($log['parentId']??'')!==$parentId) continue; if((string)($log['child_id']??'')!==$childId) continue; if((string)($log['url']??'')!==$url) continue; $ts=strtotime((string)($log['createdAt']??'')) ?: 0; if($now-$ts <= $seconds) return $log; return null; } return null; }
function isHttpUrl(string $url): bool { $p=parse_url($url); $s=strtolower((string)($p['scheme']??'')); return in_array($s,['http','https'],true) && !empty($p['host']); }
function normalizeUrl(string $url, bool $hostOnly=false): string { $url=trim(strtolower($url)); $url=preg_replace('#^https?://#','',$url) ?? $url; $url=preg_replace('#^www\.#','',$url) ?? $url; $url=rtrim($url,"/\r\n\t "); if($hostOnly) $url=explode('/',$url)[0]; return $url; }
function hostOf(string $url): string { return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: normalizeUrl($url,true))); }
function isPrivateOrLocalHost(string $host): bool { $host=strtolower($host); if(in_array($host,['localhost','127.0.0.1','::1'],true)) return true; if(preg_match('/^192\.168\./',$host)) return true; if(preg_match('/^10\./',$host)) return true; if(preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./',$host)) return true; return false; }
function isSnaillyInternalUrl(string $url): bool { $p=parse_url($url); $host=strtolower((string)($p['host']??'')); $path=strtolower((string)($p['path']??'')); if($host==='' || $path==='') return false; $isSnaillyPath=str_contains($path,'/snailly') || str_contains($path,'/api/snailly') || str_contains($path,'/blocked'); return $isSnaillyPath && isPrivateOrLocalHost($host); }
function maybeCleanupOldLogs(SnaillyDatabase $store): void { $days=(int)(getenv('SNAILLY_LOG_RETENTION_DAYS') ?: 30); $days=max(1,min(365,$days)); $marker=function_exists('storage_path') ? storage_path('framework/cache/snailly_last_cleanup.txt') : sys_get_temp_dir().'/snailly_last_cleanup.txt'; $last=is_file($marker) ? (int)file_get_contents($marker) : 0; if(time()-$last < 86400) return; try { $store->cleanupOldLogs($days); @file_put_contents($marker,(string)time()); } catch(Throwable $e) {} }
function id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(6)); }
function classificationOf(array $log): array { $labels=$log['classified_url'] ?? []; return is_array($labels) && isset($labels[0]) && is_array($labels[0]) ? $labels[0] : []; }
function isDangerLog(array $log): bool { if(($log['grant_access'] ?? null) === true) return false; if(($log['grant_access'] ?? null) === false) return true; return (string)(classificationOf($log)['FINAL_label'] ?? 'aman') !== 'aman'; }
function normalizeClassificationResult(array $classification): array
{
    $label = (string)($classification['label'] ?? 'aman');
    $action = (string)($classification['action'] ?? '');

    if (!in_array($action, ['allow', 'warn', 'block'], true)) {
        if ($label === 'bahaya') {
            $action = 'block';
        } elseif ($label === 'peringatan') {
            $action = 'warn';
        } else {
            $action = 'allow';
        }
    }

    return [
        'label' => $label,
        'action' => $action,
        'category' => (string)($classification['category'] ?? 'Safe'),
        'risk' => (string)($classification['risk'] ?? 'Low'),
        'score' => (int)($classification['score'] ?? 0),
        'reason' => (string)($classification['reason'] ?? 'No risky rule matched.'),
    ];
}
function classifyUrl(array $db, string $parentId, string $childId, string $url): array
{
    return normalizeClassificationResult(
        \SnaillyPolicyEngine::classify($db, $parentId, $childId, $url, true)
    );
}

function manualAccessDecision(array $db, string $parentId, string $childId, string $url): ?array
{
    $targetHost=hostOf($url); $targetNorm=normalizeUrl($url); $logs=array_reverse($db['logs'] ?? []);
    foreach($logs as $log){
        if((string)($log['parentId']??'')!==$parentId) continue;
        if((string)($log['child_id']??'')!==$childId) continue;
        if(!array_key_exists('grant_access',$log) || !is_bool($log['grant_access'])) continue;
        $logUrl=(string)($log['url']??''); if($logUrl==='') continue;
        $logHost=hostOf($logUrl); $logNorm=normalizeUrl($logUrl);
        $match = ($targetNorm!=='' && $targetNorm===$logNorm) || ($targetHost!=='' && $targetHost===$logHost) || ($targetHost!=='' && $logHost!=='' && (str_ends_with($targetHost,'.'.$logHost) || str_ends_with($logHost,'.'.$targetHost)));
        if(!$match) continue;
        if($log['grant_access']===true) return ['label'=>'aman','category'=>'Allowed by Parent','risk'=>'Low','score'=>0,'reason'=>'Allowed manually by parent dashboard'];
        return ['label'=>'bahaya','category'=>'Blocked by Parent','risk'=>'High','score'=>95,'reason'=>'Locked manually by parent dashboard'];
    }
    return null;
}

function latestRuleDecision(array $db, string $parentId, string $childId, string $url): ?array
{
    $matches = [];
    foreach ($db['rules'] ?? [] as $rule) {
        if ((string)($rule['parentId'] ?? '') !== $parentId) continue;
        $ruleChild = (string)($rule['childId'] ?? 'ALL');
        if ($ruleChild !== 'ALL' && $ruleChild !== '' && $ruleChild !== $childId) continue;
        if (!patternMatches($url, (string)($rule['pattern'] ?? ''), (string)($rule['matchType'] ?? 'domain'))) continue;
        $matches[] = $rule;
    }
    if (!$matches) return null;
    usort($matches, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? $b['createdAt'] ?? ''), (string)($a['updatedAt'] ?? $a['createdAt'] ?? '')));
    $rule = $matches[0];
    if ((string)($rule['type'] ?? '') === 'allow') return ['label'=>'aman','category'=>(string)($rule['category'] ?? 'Whitelist'),'risk'=>'Low','score'=>0,'reason'=>'Matched latest parent allow rule: '.(string)$rule['pattern']];
    return ['label'=>'bahaya','category'=>(string)($rule['category'] ?? 'Blocked by Parent'),'risk'=>'High','score'=>95,'reason'=>'Matched latest parent block rule: '.(string)$rule['pattern']];
}

function patternMatches(string $url, string $pattern, string $matchType): bool
{
    $pattern = normalizeUrl($pattern, $matchType === 'domain');
    if ($pattern === '') return false;
    $target = strtolower($url);
    $host = hostOf($url);
    if ($matchType === 'keyword') return str_contains($target, $pattern);
    if ($host === $pattern || str_ends_with($host, '.'.$pattern)) return true;
    return str_contains(normalizeUrl($url), $pattern);
}

function scheduleDecision(array $db, string $parentId, string $childId): ?array
{
    $child = findChild($db, $parentId, $childId);
    $schedule = $child['schedule'] ?? ['enabled'=>false];
    if (!($schedule['enabled'] ?? false)) return null;
    $days = array_map('strtolower', is_array($schedule['days'] ?? null) ? $schedule['days'] : []);
    $dayMap = ['Mon'=>'mon','Tue'=>'tue','Wed'=>'wed','Thu'=>'thu','Fri'=>'fri','Sat'=>'sat','Sun'=>'sun'];
    $today = $dayMap[date('D')] ?? 'mon';
    if (!in_array($today, $days, true)) return ['label'=>'bahaya','category'=>'Schedule Lock','risk'=>'High','score'=>98,'reason'=>'Internet access is not allowed today.'];
    $now = date('H:i'); $start=(string)($schedule['start'] ?? '08:00'); $end=(string)($schedule['end'] ?? '21:00');
    $inside = $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
    if (!$inside) return ['label'=>'bahaya','category'=>'Schedule Lock','risk'=>'High','score'=>98,'reason'=>'Internet access outside allowed time '.$start.'-'.$end.'.'];
    return null;
}
