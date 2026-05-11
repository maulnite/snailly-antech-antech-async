<?php
/**
 * PHP replacement for the Python mitmproxy filtering logic.
 * It does not intercept OS/browser traffic by itself. It provides reusable
 * functions to normalize URLs, load blocklists, and decide whether a URL should
 * be blocked when a PHP route/API calls it.
 */
declare(strict_types=1);

function snailly_normalize_url(string $url, bool $hostOnly = false): string
{
    $url = trim(strtolower($url));
    $url = preg_replace('#^https?://#', '', $url) ?? $url;
    $url = preg_replace('#^www\.#', '', $url) ?? $url;
    $url = rtrim($url, "/\r\n\t ");
    if ($hostOnly) {
        $url = explode('/', $url)[0];
    }
    return $url;
}

function snailly_load_list(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $items = [];
    foreach ($lines as $line) {
        $value = snailly_normalize_url($line);
        if ($value !== '') {
            $items[$value] = true;
        }
    }
    return array_keys($items);
}

function snailly_combined_blocklist(): array
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }

    $base = function_exists('base_path')
        ? base_path('data') . DIRECTORY_SEPARATOR
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;

    $cacheFile = function_exists('storage_path')
        ? storage_path('framework/cache/snailly_blocklist_lookup.php')
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snailly_blocklist_lookup.php';

    $sources = [$base . 'list_website.txt', $base . 'trust_positif.txt'];
    $latestSourceMtime = 0;
    foreach ($sources as $source) {
        if (is_file($source)) $latestSourceMtime = max($latestSourceMtime, (int)filemtime($source));
    }

    if (is_file($cacheFile) && (int)filemtime($cacheFile) >= $latestSourceMtime) {
        $cached = require $cacheFile;
        if (is_array($cached)) {
            return $items = $cached;
        }
    }

    $items = array_values(array_unique(array_merge(
        snailly_load_list($sources[0]),
        snailly_load_list($sources[1])
    )));

    $dir = dirname($cacheFile);
    if (is_dir($dir) && is_writable($dir)) {
        file_put_contents($cacheFile, '<?php return ' . var_export($items, true) . ';');
    }

    return $items;
}

function snailly_is_blocked(string $url): bool
{
    static $lookup = null;
    if ($lookup === null) {
        $lookup = array_fill_keys(snailly_combined_blocklist(), true);
    }
    $normalized = snailly_normalize_url($url);
    $host = snailly_normalize_url($url, true);
    return isset($lookup[$normalized]) || isset($lookup[$host]);
}

function snailly_create_log_payload(string $childId, string $parentId, string $url): array
{
    $host = snailly_normalize_url($url, true);
    $title = explode('.', $host)[0] ?? $host;
    return [
        'childId' => $childId,
        'parentId' => $parentId,
        'url' => $url,
        'web_title' => $title,
        'web_description' => '',
        'detail_url' => '',
    ];
}
