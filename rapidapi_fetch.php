<?php

$RAPIDAPI_CONFIG = [
  'key'  => 'YOUR_RAPIDAPI_KEY_HERE',
  'host' => 'api-football-v1.p.rapidapi.com',

  'league_ids' => [39,40,41,42,140,135,78,61,94,2,3],
  'season' => 2025,
  'date'   => null,
  'bookmaker_ids' => [],
];


function rapidapi_http_get(string $path, array $query, array $cfg, int $timeout = 15): ?array
{
  $mode = $cfg['mode'] ?? 'rapidapi';

// Build URL
$url = 'https://' . $cfg['host'] . $path;
if ($query) $url .= '?' . http_build_query($query);

// Headers
$headers = ['Accept: application/json'];

if ($mode === 'direct') {
    // Direct API-Sports
    $headers[] = 'x-apisports-key: ' . $cfg['key'];

    // IMPORTANT: direct host is already v3.*, so strip "/v3" prefix if present
    if (str_starts_with($path, '/v3/')) {
        $path = substr($path, 3); // "/v3/odds" -> "/odds"
        $url = 'https://' . $cfg['host'] . $path;
        if ($query) $url .= '?' . http_build_query($query);
    }
} else {
    // RapidAPI gateway
    $headers[] = 'x-rapidapi-host: ' . $cfg['host'];
    $headers[] = 'x-rapidapi-key: ' . $cfg['key'];
}


    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        error_log("RapidAPI GET error: $err URL=$url");
        return null;
    }

    $data = json_decode($res, true);
    if (!is_array($data)) {
        error_log("RapidAPI GET non-JSON or invalid JSON; snippet=" . substr($res, 0, 200));
        return null;
    }

    return $data;
}



function rapidapi_fetch_bookmakers(array $cfg): array
{
    $data = rapidapi_http_get('/v3/odds/bookmakers', [], $cfg);
    if (!is_array($data) || !isset($data['response'])) {
        return [];
    }
    return $data['response'];  // array of bookmakers
}


function rapidapi_fetch_mapping(array $cfg): array
{
    $data = rapidapi_http_get('/v3/odds/mapping', [], $cfg);
    if (!is_array($data) || !isset($data['response'])) {
        return [];
    }
    return $data['response'];  // array of mapping entries
}



function rapidapi_fetch_back_events(array $cfg): array
{

    if (empty($cfg['key']) || empty($cfg['host'])) {
        return [];
    }

// If season not explicitly set, auto-pick current soccer season start year
$season = $cfg['season'] ?? null;
if (!$season) {
  $y = (int)date('Y');
  $m = (int)date('n');
  // Soccer seasons usually start around Aug; Jan-Jun belongs to previous season start year
  $season = ($m < 7) ? ($y - 1) : $y;
} else {
  $season = (int)$season;
}


    $leagueIds    = $cfg['league_ids']    ?? [];
    $season       = $cfg['season']        ?? null;
    $date         = $cfg['date']          ?? null;  // can be null
    $bookmakerIds = $cfg['bookmaker_ids'] ?? [];

    if (!$leagueIds || !$season) {
        return [];
    }

    $events = [];

    foreach ($leagueIds as $leagueId) {
        $query = [
            'league' => $leagueId,
            'season' => $season,
        ];
        if ($date) {
            $query['date'] = $date; // filter by day if you want
        }
        if ($bookmakerIds) {



            $query['bookmakers'] = implode(',', $bookmakerIds);
        }

        $data = rapidapi_http_get('/v3/odds', $query, $cfg);
        if (!is_array($data) || !isset($data['response'])) {
            continue;
        }

        foreach ($data['response'] as $item) {



            $fixture    = $item['fixture'] ?? [];
            $teamsObj   = $item['teams'] ?? [];
            $homeTeam   = $teamsObj['home']['name'] ?? null;
            $awayTeam   = $teamsObj['away']['name'] ?? null;
            $startTime  = $fixture['date'] ?? null;
            $bookmakers = $item['bookmakers'] ?? [];


            if (!$homeTeam || !$awayTeam) {
                continue;
            }

            $key = strtolower($homeTeam) . '|' . strtolower($awayTeam);
            if (!isset($events[$key])) {
                $events[$key] = [
                    'home'  => $homeTeam,
                    'away'  => $awayTeam,
                    'time'  => $startTime,
                    'backs' => [
                        'home' => null,
                        'draw' => null,
                        'away' => null,
                    ],
                    'lays'  => [
                        'home' => null,
                        'draw' => null,
                        'away' => null,
                    ],
                ];
            }


            foreach ($bookmakers as $bk) {
                $bkName = $bk['name'] ?? 'RapidBookie';
                foreach ($bk['bets'] ?? [] as $bet) {
                    $betName = strtolower($bet['name'] ?? '');

                    if (!(
                        str_contains($betName, 'match winner') ||
                        str_contains($betName, '1x2') ||
                        str_contains($betName, 'win-draw-win') ||
                        str_contains($betName, 'result')
                    )) {
                        continue;
                    }

                    foreach ($bet['values'] ?? [] as $val) {
                        $label = strtolower($val['value'] ?? '');
                        $odd   = isset($val['odd']) ? (float)$val['odd'] : 0.0;
                        if ($odd <= 1.0) continue;

                        $selKey = null;
                        if ($label === 'home' || $label === '1')       $selKey = 'home';
                        elseif ($label === 'away' || $label === '2')   $selKey = 'away';
                        elseif ($label === 'draw' || $label === 'x')   $selKey = 'draw';

                        if (!$selKey) continue;

                        $current = $events[$key]['backs'][$selKey]['odds'] ?? null;
                        if ($current === null || $odd > $current) {
                            $events[$key]['backs'][$selKey] = [
                                'odds' => $odd,
                                'bk'   => 'RapidAPI:' . $bkName,
                            ];
                        }
                    }
                }
            }
        }
    }

    return $events;
}

function fetch_rapidapi_back_events(): array {
  $cfgAll = @include __DIR__ . '/config/odds_creds.php';
  $cfg = is_array($cfgAll) ? ($cfgAll['rapidapi'] ?? null) : null;
  if (!is_array($cfg)) return [];
  return rapidapi_fetch_back_events($cfg);
}
