<?php
declare(strict_types=1);

final class SnaillyPolicyEngine
{
    public static function classify(
        array $db,
        string $parentId,
        string $childId,
        string $url,
        bool $includeSchedule = true
    ): array {
        /*
         * Urutan prioritas:
         * 1. Schedule lock
         * 2. Rule parent terbaru
         * 3. Heuristic/keyword/category otomatis
         * 4. Default allow
         */

        if ($includeSchedule) {
            $schedule = self::scheduleDecision($db, $parentId, $childId);

            if ($schedule !== null) {
                return $schedule;
            }
        }

        $base = self::heuristicDecision($url);

        $rule = self::latestRuleDecision(
            $db,
            $parentId,
            $childId,
            $url,
            $base['category'] ?? ''
        );

        if ($rule !== null) {
            return $rule;
        }

        return $base;
    }

    public static function latestRuleDecision(
        array $db,
        string $parentId,
        string $childId,
        string $url,
        string $baseCategory = ''
    ): ?array {
        $matches = [];

        foreach ($db['rules'] ?? [] as $rule) {
            if ((string)($rule['parentId'] ?? '') !== $parentId) {
                continue;
            }

            $ruleChild = (string)($rule['childId'] ?? 'ALL');

            if ($ruleChild !== 'ALL' && $ruleChild !== '' && $ruleChild !== $childId) {
                continue;
            }

            $pattern = (string)($rule['pattern'] ?? '');
            $matchType = (string)($rule['matchType'] ?? 'domain');

            if (!self::patternMatches($url, $pattern, $matchType, $baseCategory)) {
                continue;
            }

            $matches[] = $rule;
        }

        if (!$matches) {
            return null;
        }

        /*
         * Latest rule wins.
         * Jadi kalau parent block -> allow -> block lagi,
         * keputusan terakhir yang dipakai.
         */
        usort($matches, function ($a, $b) {
            $aTime = (string)($a['updatedAt'] ?? $a['createdAt'] ?? '');
            $bTime = (string)($b['updatedAt'] ?? $b['createdAt'] ?? '');

            return strcmp($bTime, $aTime);
        });

        return self::ruleToDecision($matches[0]);
    }

    public static function patternMatches(
        string $url,
        string $pattern,
        string $matchType = 'domain',
        string $baseCategory = ''
    ): bool {
        $matchType = strtolower(trim($matchType ?: 'domain'));
        $pattern = self::normalizePattern($pattern, $matchType);

        if ($pattern === '') {
            return false;
        }

        $host = self::hostOf($url);
        $full = self::normalizeFullUrl($url);

        if ($matchType === 'category') {
            $category = strtolower(trim($baseCategory));

            return $category === $pattern || str_contains($category, $pattern);
        }

        if ($matchType === 'keyword') {
            return str_contains($full, $pattern);
        }

        if ($matchType === 'url') {
            return str_contains($full, $pattern);
        }

        /*
         * Default: domain + subdomain matching.
         * Rule youtube.com akan kena:
         * - youtube.com
         * - www.youtube.com
         * - m.youtube.com
         * - music.youtube.com
         */
        if (str_contains($pattern, '/')) {
            return str_contains($full, $pattern);
        }

        return $host === $pattern || str_ends_with($host, '.' . $pattern);
    }

    public static function heuristicDecision(string $url): array
    {
        $lower = strtolower($url);
        $host = self::hostOf($url);

        $dangerChecks = [
            'Adult Content' => [
                'risk' => 'High',
                'score' => 92,
                'words' => [
                    'porn',
                    'porno',
                    'sex',
                    'xxx',
                    'xvideos',
                    'xnxx',
                    'bokep',
                    'adult',
                    'nsfw',
                    'hentai',
                ],
            ],
            'Gambling' => [
                'risk' => 'High',
                'score' => 90,
                'words' => [
                    'judi',
                    'casino',
                    'togel',
                    'slot',
                    'betting',
                    'gambling',
                    'gacor',
                ],
            ],
            'Phishing' => [
                'risk' => 'High',
                'score' => 88,
                'words' => [
                    'phishing',
                    'verify-account',
                    'account-verify',
                    'free-login',
                    'password-reset',
                    'claim-gift',
                    'wallet-verify',
                ],
            ],
            'Malware/Piracy' => [
                'risk' => 'High',
                'score' => 86,
                'words' => [
                    'malware',
                    'trojan',
                    'virus',
                    'crack',
                    'keygen',
                    'darkweb',
                    'download-cheat',
                ],
            ],
            'Suspicious' => [
                'risk' => 'Medium',
                'score' => 58,
                'words' => [
                    'free-robux',
                    'free-gift',
                    'giveaway-login',
                    'bonus-claim',
                ],
            ],
        ];

        foreach ($dangerChecks as $category => $info) {
            foreach ($info['words'] as $word) {
                if (str_contains($lower, $word)) {
                    return [
                        'label' => 'bahaya',
                        'action' => 'block',
                        'category' => $category,
                        'risk' => $info['risk'],
                        'score' => $info['score'],
                        'reason' => 'Keyword detected: ' . $word,
                    ];
                }
            }
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
            return [
                'label' => 'peringatan',
                'action' => 'warn',
                'category' => 'Suspicious',
                'risk' => 'Medium',
                'score' => 55,
                'reason' => 'Website uses raw IP address.',
            ];
        }

        if (strlen($url) > 150 && preg_match('/(login|verify|password|claim)/i', $url)) {
            return [
                'label' => 'peringatan',
                'action' => 'warn',
                'category' => 'Suspicious',
                'risk' => 'Medium',
                'score' => 50,
                'reason' => 'Long URL with login/verify wording.',
            ];
        }

        $educationDomains = [
            'scratch.mit.edu',
            'wikipedia.org',
            'khanacademy.org',
            'code.org',
            'duolingo.com',
            'kids.nationalgeographic.com',
        ];

        foreach ($educationDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return [
                    'label' => 'aman',
                    'action' => 'allow',
                    'category' => 'Education',
                    'risk' => 'Low',
                    'score' => 5,
                    'reason' => 'Known educational website.',
                ];
            }
        }

        $knownDomains = [
            'youtube.com' => 'Entertainment',
            'chatgpt.com' => 'General/Entertainment',
            'google.com' => 'Search Engine',
            'kaggle.com' => 'Education/Technology',
            'scratch.mit.edu' => 'Education',
            'web.whatsapp.com' => 'Communication',
            'whatsapp.com' => 'Communication',
        ];

        foreach ($knownDomains as $domain => $category) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return [
                    'label' => 'aman',
                    'action' => 'allow',
                    'category' => $category,
                    'risk' => 'Low',
                    'score' => 15,
                    'reason' => 'No risky rule matched.',
                ];
            }
        }

        return [
            'label' => 'aman',
            'action' => 'allow',
            'category' => 'Safe',
            'risk' => 'Low',
            'score' => 10,
            'reason' => 'No risky rule matched.',
        ];
    }

    public static function normalizePattern(string $pattern, string $matchType = 'domain'): string
    {
        $pattern = strtolower(trim($pattern));
        $pattern = preg_replace('#^https?://#', '', $pattern) ?? $pattern;
        $pattern = preg_replace('#^www\.#', '', $pattern) ?? $pattern;

        if ($matchType === 'domain') {
            $pattern = explode('/', $pattern)[0] ?? $pattern;
            return trim($pattern, " \t\n\r\0\x0B/");
        }

        return trim($pattern, " \t\n\r\0\x0B/");
    }

    public static function normalizeFullUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://#', '', $url) ?? $url;
        $url = preg_replace('#^www\.#', '', $url) ?? $url;

        return trim($url, " \t\n\r\0\x0B/");
    }

    public static function hostOf(string $url): string
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = preg_replace('#^www\.#', '', $host) ?? $host;
        $host = explode('/', $host)[0] ?? $host;

        return trim($host, " \t\n\r\0\x0B/");
    }

    private static function ruleToDecision(array $rule): array
    {
        $type = strtolower((string)($rule['type'] ?? 'block'));
        $pattern = (string)($rule['pattern'] ?? '');
        $category = trim((string)($rule['category'] ?? ''));

        if ($type === 'allow') {
            return [
                'label' => 'aman',
                'action' => 'allow',
                'category' => $category !== '' ? $category : 'Allowed by Parent',
                'risk' => 'Low',
                'score' => 0,
                'reason' => 'Matched latest parent allow rule: ' . $pattern,
            ];
        }

        if ($type === 'warn') {
            return [
                'label' => 'peringatan',
                'action' => 'warn',
                'category' => $category !== '' ? $category : 'Warning by Parent',
                'risk' => 'Medium',
                'score' => 45,
                'reason' => 'Matched latest parent warn rule: ' . $pattern,
            ];
        }

        return [
            'label' => 'bahaya',
            'action' => 'block',
            'category' => $category !== '' ? $category : 'Blocked by Parent',
            'risk' => 'High',
            'score' => 95,
            'reason' => 'Matched latest parent block rule: ' . $pattern,
        ];
    }

    private static function scheduleDecision(array $db, string $parentId, string $childId): ?array
    {
        $child = null;

        foreach ($db['children'] ?? [] as $item) {
            if (
                (string)($item['parentId'] ?? '') === $parentId &&
                (string)($item['id'] ?? '') === $childId
            ) {
                $child = $item;
                break;
            }
        }

        if (!$child) {
            return null;
        }

        $schedule = $child['schedule'] ?? [
            'enabled' => false,
            'start' => '08:00',
            'end' => '21:00',
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        ];

        if (!($schedule['enabled'] ?? false)) {
            return null;
        }

        $days = $schedule['days'] ?? [];

        if (!is_array($days)) {
            $days = [];
        }

        $days = array_map('strtolower', $days);

        $dayMap = [
            'Mon' => 'mon',
            'Tue' => 'tue',
            'Wed' => 'wed',
            'Thu' => 'thu',
            'Fri' => 'fri',
            'Sat' => 'sat',
            'Sun' => 'sun',
        ];

        $today = $dayMap[date('D')] ?? 'mon';

        if (!in_array($today, $days, true)) {
            return [
                'label' => 'bahaya',
                'action' => 'block',
                'category' => 'Schedule Lock',
                'risk' => 'High',
                'score' => 98,
                'reason' => 'Internet access is not allowed today.',
            ];
        }

        $now = date('H:i');
        $start = (string)($schedule['start'] ?? '08:00');
        $end = (string)($schedule['end'] ?? '21:00');

        /*
         * Support jadwal normal:
         * 08:00 - 21:00
         *
         * Support jadwal lewat tengah malam:
         * 21:00 - 06:00
         */
        $inside = $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);

        if (!$inside) {
            return [
                'label' => 'bahaya',
                'action' => 'block',
                'category' => 'Schedule Lock',
                'risk' => 'High',
                'score' => 98,
                'reason' => 'Internet access outside allowed time ' . $start . '-' . $end . '.',
            ];
        }

        return null;
    }
}