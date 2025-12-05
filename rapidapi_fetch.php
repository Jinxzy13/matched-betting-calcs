<?php



$RAPIDAPI_CONFIG = [
    'key'   => 'YOUR_RAPIDAPI_KEY_HERE',      // <--- PUT YOUR KEY HERE
    'host'  => 'api-football-v1.p.rapidapi.com',














    'league_ids' => [
        39,   // EPL
        40,   // Championship
        41,   // League One
        42,   // League Two
        140,  // La Liga
        135,  // Serie A
        78,   // Bundesliga
        61,   // Ligue 1
        94,   // Primeira Liga
        2,    // Champions League
        3,    // Europa League

    ],

    'season' => 2024,
    'date'   => null,  // e.g. date('Y-m-d')


    'bookmaker_ids' => [],
];


function rapidapi_http_get(string $path, array $query, array $cfg, int $timeout = 15): ?array
{
    $url = 'https://' . $cfg['host'] . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'x-rapidapi-host: ' . $cfg['host'],
        'x-rapidapi-key: ' . $cfg['key'],
        'Accept: application/json',
    ];

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



            $fixture    = $item['fixture']    ?? [];
            $teams      = $fixture['teams']   ?? [];
            $homeTeam   = $teams['home']['name'] ?? null;
            $awayTeam   = $teams['away']['name'] ?? null;
            $startTime  = $fixture['date']   ?? null;
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
