<?php

require __DIR__ . '/mb_config.php';      // defines $MB_USERNAME, $MB_PASSWORD
require __DIR__ . '/mrdoge_fetch.php';   // MrDoge back odds
require __DIR__ . '/oddsapi_fetch.php';  // OddsAPI back odds (stub for now)
require __DIR__ . '/rapidapi_fetch.php'; // RapidAPI / API-Football back odds (stub for now)

header('Content-Type: application/json');

$DEBUG = isset($_GET['debug']) ? (int)$_GET['debug'] : 1;

$STAKE      = 10.0;  // reference stake
$COMMISSION = 0.0;   // Matchbook commission (0% on your account)



function normalise_team_name(string $name): string {
    $name = strtolower($name);
    $name = preg_replace('/\b(fc|cf|afc|sc|u\d+|[0-9]+)\b/', '', $name);
    $name = preg_replace('/[^a-z0-9]+/', '', $name);
    return $name;
}

function classify_mb_runner(string $runnerName, string $homeName, string $awayName, ?int $idx = null): ?string {
    $norm = function ($s) {
        return strtolower(preg_replace('/[^a-z0-9]+/', '', $s ?? ''));
    };

    $r = $norm($runnerName);
    $h = $norm($homeName);
    $a = $norm($awayName);

    if ($r === 'draw' || $r === 'thedraw' || $r === 'x' || $r === 'tie') {
        return 'draw';
    }

    if ($h && str_contains($r, $h)) return 'home';
    if ($a && str_contains($r, $a)) return 'away';

    if ($idx !== null) {
        if ($idx === 0) return 'home';
        if ($idx === 1) return 'away';
        if ($idx === 2) return 'draw';
    }

    return null;
}

function mb_get_json(string $url, array $headers, int $timeout = 15, string $label = ''): ?array {
    global $DEBUG;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADER         => true,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);

    if ($raw === false) {
        if ($DEBUG) error_log("MB GET {$label} CURL error: $err URL=$url");
        curl_close($ch);
        return null;
    }

    $headerSize = $info['header_size'] ?? 0;
    $hdr        = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);
    curl_close($ch);

    $data = json_decode($body, true);

    if ($DEBUG) {
        error_log("MB GET {$label} HTTP {$info['http_code']} CT=" . ($info['content_type'] ?? 'n/a') . " URL=$url");
        if (!is_array($data)) {
            error_log("MB GET {$label} body snippet: " . substr($body, 0, 200));
        }
    }

    return is_array($data) ? $data : null;
}

function get_matchbook_session_token(string $loginUrl, string $cacheFile, int $ttl, ?string $user, ?string $pass): ?string {
    global $DEBUG;

    if (is_file($cacheFile)) {
        $raw    = @file_get_contents($cacheFile);
        $cached = $raw ? json_decode($raw, true) : null;
        if (is_array($cached)
            && !empty($cached['token'])
            && !empty($cached['time'])
            && (time() - (int)$cached['time'] < $ttl)
        ) {
            return $cached['token'];
        }
    }

    if (!$user || !$pass || $user === 'YOUR_MATCHBOOK_USERNAME') {
        if ($DEBUG) error_log('MB login skipped: missing/placeholder creds');
        return null;
    }

    $payload = json_encode(['username' => $user, 'password' => $pass]);
    $ch      = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $loginUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: OddsMatcher/1.0 (+https://YOURDOMAIN)'
        ],
    ]);

    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);

    if ($raw === false) {
        if ($DEBUG) error_log("MB login CURL error: $err");
        curl_close($ch);
        return null;
    }

    $headerSize = $info['header_size'] ?? curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $hdr  = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    if ($DEBUG) {
        error_log("MB login HTTP {$info['http_code']} CT=" . ($info['content_type'] ?? 'n/a'));
        error_log("MB login header snippet: " . substr($hdr, 0, 200));
        error_log("MB login body snippet:   " . substr($body, 0, 200));
    }

    if (($info['http_code'] ?? 0) !== 200) {
        return null;
    }

    $token = null;
    foreach (preg_split("/\r?\n/", $hdr) as $line) {
        if (stripos($line, 'session-token:') === 0) {
            $token = trim(substr($line, strlen('session-token:')));
            break;
        }
    }

    if (!$token) {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $token = $json['session-token']
                ?? ($json['session_token'] ?? ($json['token'] ?? null));
        }
    }

    if ($token) {
        @file_put_contents($cacheFile, json_encode([
            'token' => $token,
            'time'  => time(),
        ]));
        return $token;
    }

    return null;
}




















$events = [];




$oddsApiEvents = fetch_oddsapi_back_events();
$events        = array_merge($events, $oddsApiEvents);

$rapidApiEvents = fetch_rapidapi_back_events();
$events         = array_merge($events, $rapidApiEvents);

if (!is_array($events)) {
    $events = [];
}



$MB_LOGIN_URL     = 'https://api.matchbook.com/bpapi/rest/security/session';
$MB_SESSION_CACHE = __DIR__ . '/cache/mb_session_token.json';
$MB_SESSION_TTL   = 60;

$mb_status = [
    'login_ok'     => false,
    'token_len'    => 0,
    'events'       => 0,
    'soccer_id'    => null,
    'sports_count' => 0,
    'base_used'    => null,
];

$mb_debug = null;

$sessionToken = get_matchbook_session_token(
    $MB_LOGIN_URL,
    $MB_SESSION_CACHE,
    $MB_SESSION_TTL,
    $MB_USERNAME ?? null,
    $MB_PASSWORD ?? null
);

if ($sessionToken && $events) {
    $mb_status['login_ok']  = true;
    $mb_status['token_len'] = strlen($sessionToken);

    $headers = [
        'session-token: ' . $sessionToken,
        'Cookie: session-token=' . $sessionToken,
        'Accept: application/json',
        'User-Agent: OddsMatcher/1.0 (+https://YOURDOMAIN)',
    ];

    $soccerId   = 15;
    $whichBase  = 'https://api.matchbook.com/edge/rest';
    $sports_cnt = 0;

    $mb_status['soccer_id']    = $soccerId;
    $mb_status['sports_count'] = $sports_cnt;
    $mb_status['base_used']    = $whichBase;

    if ($soccerId && $whichBase) {
        $paramsBase = [
            'sport-ids'     => $soccerId,
            'states'        => 'open',
            'exchange-type' => 'back-lay',
            'odds-type'     => 'DECIMAL',
            'price-depth'   => 3,
            'per-page'      => 400,
        ];

        $eventsData      = null;
        $eventsUrl_final = null;

        foreach (['/events', '/catalogue/events'] as $path) {
            $url        = $whichBase . $path . '?' . http_build_query($paramsBase);
            $eventsData = mb_get_json($url, $headers, 20, "events($path)");

            if (isset($eventsData['events']) && is_array($eventsData['events']) && count($eventsData['events'])) {
                $eventsUrl_final = $url;
                break;
            }
        }

        if (isset($eventsData['events']) && is_array($eventsData['events']) && count($eventsData['events'])) {
            $mb_status['events'] = count($eventsData['events']);

            if (!empty($eventsData['events'])) {
                $ev0 = $eventsData['events'][0];
                $mb_debug = [
                    'events_url' => $eventsUrl_final,
                    'name'       => $ev0['name'] ?? null,
                    'start'      => $ev0['start-time'] ?? ($ev0['start_time'] ?? null),
                    'markets'    => array_map(function ($mm) {
                        return [
                            'name'         => $mm['name'] ?? null,
                            'type'         => $mm['market-type'] ?? ($mm['market_type'] ?? null),
                            'runner_names' => array_map(
                                fn($rr) => ($rr['name'] ?? null),
                                array_slice($mm['runners'] ?? [], 0, 5)
                            ),
                            'has_prices'   => isset($mm['runners'][0]['prices']),
                            'has_avl'      => isset($mm['runners'][0]['available-to-lay']) ||
                                              isset($mm['runners'][0]['available_to_lay']),
                        ];
                    }, array_slice($ev0['markets'] ?? [], 0, 3)),
                ];
            }

            $join_stats = [
                'total_events'  => count($eventsData['events']),
                'has_v_pattern' => 0,
                'name_matches'  => 0,
                'time_matches'  => 0,
                'lays_set'      => 0,
            ];

            foreach ($eventsData['events'] as $ev) {
                $name  = $ev['name'] ?? '';
                $start = $ev['start-time'] ?? ($ev['start_time'] ?? '');

                if ($name === '') continue;

                if (!preg_match('/\s+v(?:s)?\s+/i', $name)) {
                    continue;
                }
                $join_stats['has_v_pattern']++;

                $parts = preg_split('/\s+v(?:s)?\s+/i', $name);
                if (count($parts) !== 2) continue;
                [$homeName, $awayName] = $parts;

                $k_home_away = normalise_team_name($homeName) . '|' . normalise_team_name($awayName);
                $k_away_home = normalise_team_name($awayName) . '|' . normalise_team_name($homeName);

                $candidates = [];
                foreach ($events as $ekey => $E) {
                    $k_e = normalise_team_name($E['home']) . '|' . normalise_team_name($E['away']);
                    if ($k_e === $k_home_away || $k_e === $k_away_home) {
                        $candidates[] = $ekey;
                    }
                }
                if (!$candidates) continue;
                $join_stats['name_matches']++;

                $bestKey  = null;
                $bestDiff = null;

                foreach ($candidates as $ekey) {
                    $oaTs = strtotime($events[$ekey]['time'] ?? '');
                    $mbTs = strtotime($start ?: '');

                    if ($oaTs && $mbTs) {
                        $diff = abs($mbTs - $oaTs);
                        if ($diff <= 7 * 24 * 3600 && ($bestDiff === null || $diff < $bestDiff)) {
                            $bestDiff = $diff;
                            $bestKey  = $ekey;
                        }
                    } else {
                        $bestKey  = $ekey;
                        $bestDiff = 0;
                        break;
                    }
                }

                if ($bestKey === null) continue;
                if ($bestDiff === 0 || $bestDiff <= 7 * 24 * 3600) {
                    $join_stats['time_matches']++;
                }

                foreach ($ev['markets'] ?? [] as $m) {
                    $mName = strtolower($m['name'] ?? '');
                    $mType = strtolower($m['market-type'] ?? ($m['market_type'] ?? ''));

                    $okMarket =
                        str_contains($mName, 'match odds') ||
                        str_contains($mName, '1x2') ||
                        str_contains($mName, 'match betting') ||
                        str_contains($mName, 'full time result') ||
                        str_contains($mName, '90 minutes') ||
                        in_array($mType, [
                            'match-odds',
                            'one_x_two',
                            '1x2',
                            'match-betting',
                            'full-time-result',
                            '90-minutes'
                        ], true);

                    if (!$okMarket) continue;

                    foreach (($m['runners'] ?? []) as $ri => $r) {
                        $selKey = classify_mb_runner($r['name'] ?? '', $homeName, $awayName, $ri);
                        if (!$selKey) continue;

                        $bestLay = $events[$bestKey]['lays'][$selKey] ?? null;

                        foreach (($r['prices'] ?? []) as $p) {
                            if (strtolower($p['side'] ?? '') !== 'lay') continue;
                            $price = (float)($p['price'] ?? $p['odds'] ?? 0);
                            if ($price > 1 && ($bestLay === null || $price < $bestLay)) {
                                $bestLay = $price;
                            }
                        }

                        foreach (($r['available-to-lay'] ?? $r['available_to_lay'] ?? []) as $p) {
                            $price = (float)($p['price'] ?? $p['odds'] ?? 0);
                            if ($price > 1 && ($bestLay === null || $price < $bestLay)) {
                                $bestLay = $price;
                            }
                        }

                        if ($bestLay !== null) {
                            $events[$bestKey]['lays'][$selKey] = $bestLay;
                            $join_stats['lays_set']++;
                        }
                    }
                }
            }

            $mb_status['join_stats'] = $join_stats;
        } else {
            if ($DEBUG) {
                error_log("MB events: no soccer events found for sport-id=15 on base={$whichBase}");
            }
        }
    }
}



$rows            = [];
$allBackBookies  = [];

foreach ($events as $e) {
    foreach (['home', 'draw', 'away'] as $sel) {
        $b  = $e['backs'][$sel]['odds'] ?? null;
        $bk = $e['backs'][$sel]['bk']   ?? null;
        $l  = $e['lays'][$sel]          ?? null;

        if ($bk) {
            $allBackBookies[$bk] = true;
        }

        if ($b === null || $l === null || $l <= $COMMISSION) {
            continue;
        }

        $L    = ($STAKE * $b) / ($l - $COMMISSION);
        $liab = ($l - 1.0) * $L;

        $bw = $STAKE * ($b - 1.0) - $liab;                  // Back win
        $lw = $L * (1.0 - $COMMISSION) - $STAKE;            // Lay win
        $worst  = min($bw, $lw);
        $rating = 100 * (1.0 + $worst / $STAKE);

        $rows[] = [
            'event'      => $e['home'] . ' vs ' . $e['away'],
            'time'       => $e['time'],
            'selection'  => strtoupper($sel[0]) . substr($sel, 1),
            'back_bk'    => $bk,
            'back_odds'  => round($b, 2),
            'lay_odds'   => round($l, 2),
            'lay_liab'   => round($liab, 2),
            'bw'         => round($bw, 2),
            'lw'         => round($lw, 2),
            'rating'     => round($rating, 2),
        ];
    }
}

usort($rows, fn($a, $b) => $b['rating'] <=> $a['rating']);

$allBackBookies = array_keys($allBackBookies);
sort($allBackBookies);

if ($DEBUG) {
    echo json_encode([
        'api_sources'       => [
            'mrdoge'   => count($mrdogeEvents),
            'oddsapi'  => count($oddsApiEvents),
            'rapidapi' => count($rapidApiEvents),
        ],
        'event_count'       => count($events),
        'row_count'         => count($rows),
        'mb_status'         => $mb_status,
        'mb_sample'         => $mb_debug,
        'sample_events'     => array_slice($events, 0, 5, true),
        'rows'              => array_slice($rows, 0, 30),
        'all_back_bookies'  => $allBackBookies,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
