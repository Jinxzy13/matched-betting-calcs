<?php





$MRDOGE_AUTH = 'Bearer sk_live_PUT_API_KEY_HERE';

$MRDOGE_BASE = 'https://api.mrdoge.co/v2';

function out_json($data, int $status_code = 200) {
    if (PHP_SAPI !== 'cli') {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function mrdoge_get(string $url, string $auth = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($auth) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $auth,
            'Accept: application/json',
        ]);
    }

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err) {
        return [null, "cURL error: $err"];
    }

    $data = json_decode($body, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [null, "JSON decode error: " . json_last_error_msg()];
    }

    return [$data, null];
}




function is_important_competition($region, ?string $caption): bool {

    if (is_array($region)) {

        $region = $region['caption'] ?? 'Unknown';
    } elseif ($region === null) {
        $region = 'Unknown';
    }

    $region_l  = strtolower((string)$region);
    $caption_l = strtolower($caption ?? '');

    $important_exact = [
        'premier league',
        'championship',
        'league one',
        'league two',
        'laliga',
        'la liga',        // just in case
        'serie a',
        'bundesliga',
        'ligue 1',
        'primeira liga',
    ];

    $important_exact_extra = [
        'liga dos campeões',  // Champions League
        'liga europa',        // Europa League
        'liga conferência',   // Conference League
    ];

    $important_substrings = [
        'world cup',
        'copa do mundo',          // PT style
        'qualifier',              // qualifiers
        'qualificações',
        'euro ',                  // "Euro 2024", "Euro Cup", etc.
        'european championship',
    ];

    $important_regions = [
        'england',
        'champions league',
        'europa league',
        'conference league',

        'spain',
        'italy',
        'germany',
        'france',
        'portugal',
        'world',   // for WC / WCQ / Euros that are global-tagged
        'europe',  // for Euro comps
    ];

    if (in_array($region_l, $important_regions, true)) {

        foreach (array_merge($important_exact, $important_exact_extra) as $name) {
            if ($caption_l === $name) {
                return true;
            }
        }

        foreach ($important_substrings as $needle) {
            if ($caption_l !== '' && str_contains($caption_l, $needle)) {
                return true;
            }
        }
    }

    if (in_array($caption_l, array_merge($important_exact, $important_exact_extra), true)) {
        return true;
    }

    foreach (['laliga', 'la liga', 'serie a', 'bundesliga', 'ligue 1', 'primeira liga'] as $needle) {
        if ($caption_l !== '' && str_contains($caption_l, $needle)) {
            return true;
        }
    }

    return false;
}




$meta = [
    'generated_at_utc' => gmdate('c'),
];

$errors = [];

list($competitions_json, $err) = mrdoge_get($MRDOGE_BASE . '/competitions', $MRDOGE_AUTH);
if ($err) {
    $errors['competitions'] = $err;
    $competitions_json = ['data' => []];
}

$competitions = $competitions_json['data'] ?? [];
$competition_lookup = [];
$important_competitions = [];

$competition_name_by_id = [];
foreach ($competitions as $c) {
    if (!isset($c['id'])) {
        continue;
    }
    $competition_name_by_id[(int)$c['id']] = $c['caption'] ?? null;
}


foreach ($competitions as $c) {
    $id      = $c['id']        ?? null;
    $caption = $c['caption']   ?? null;
    $region  = $c['region']['caption'] ?? ($c['region'] ?? 'Unknown');

    if ($id !== null) {
        $competition_lookup[$id] = [
            'id'      => $id,
            'caption' => $caption,
            'region'  => $region,
        ];
    }

    if ($id !== null && is_important_competition($region, $caption)) {
        $important_competitions[] = [
            'id'      => $id,
            'caption' => $caption,
            'region'  => $region,
            '_events' => $c['_count']['events'] ?? null,
        ];
    }
}

$comps_by_region = [];
foreach ($important_competitions as $c) {
    $region = $c['region'] ?? 'Unknown';
    if (!isset($comps_by_region[$region])) {
        $comps_by_region[$region] = [];
    }
    $comps_by_region[$region][] = $c;
}

$competitions_by_region = [];
foreach ($comps_by_region as $region => $list) {
    $competitions_by_region[] = [
        'region'              => $region,
        'competition_count'   => count($list),
        'sample_competitions' => array_slice($list, 0, 5),
    ];
}


list($matches_json, $err) = mrdoge_get($MRDOGE_BASE . '/matches?perPage=200', $MRDOGE_AUTH);

$matches_debug_raw = $matches_json; // keep a copy of whatever came back

if ($err) {
    $errors['matches'] = $err;
    $matches_json = ['data' => [], 'count' => 0];
}

if (!isset($matches_json['data']) || !is_array($matches_json['data'])) {
    $errors['matches'] = $errors['matches'] ?? 'Unexpected matches response shape';
    $matches_json = ['data' => [], 'count' => 0];
}

$matches = $matches_json['data'] ?? [];
$matches_count = $matches_json['count'] ?? count($matches);


$matches_by_competition = [];
$sample_matches = [];

foreach ($matches as $m) {
    $cid     = $m['competitionId'] ?? $m['competition_id'] ?? null;
    $region  = $m['region'] ?? 'Unknown';
    $home    = $m['home'] ?? ($m['homeTeam'] ?? null);
    $away    = $m['away'] ?? ($m['awayTeam'] ?? null);
    $time    = $m['time'] ?? ($m['commenceTime'] ?? null);

    $comp_info = $competition_lookup[$cid] ?? null;
    $caption   = $comp_info['caption'] ?? null;
    $region2   = $comp_info['region']  ?? $region;

    if (!is_important_competition($region2, $caption)) {
        continue;
    }

    $comp_key = $cid ?? ('unknown_' . $region2);

    if (!isset($matches_by_competition[$comp_key])) {
        $matches_by_competition[$comp_key] = [
            'competition_id'   => $cid,
            'competition_name' => $caption ?? 'Unknown',
            'region'           => $region2,
            'match_count'      => 0,
        ];
    }

    $matches_by_competition[$comp_key]['match_count']++;

    if (count($sample_matches) < 25) {
        $sample_matches[] = [
            'id'               => (string)($m['id'] ?? ''),
            'home'             => $home,
            'away'             => $away,
            'time'             => $time,
            'status'           => $m['status'] ?? null,
            'competition_id'   => $cid,
            'competition_name' => $caption ?? 'Unknown',
            'region'           => $region2,
        ];
    }
}

$matches_by_competition_arr = array_values($matches_by_competition);

list($picks_json, $err) = mrdoge_get($MRDOGE_BASE . '/ai/mr-doge-picks', $MRDOGE_AUTH);
$ai_picks_raw = $picks_json;

if ($err) {
    $errors['ai_mr_doge_picks'] = $err;
    $picks_json = ['data' => []];
}

if (!isset($picks_json['data']) || !is_array($picks_json['data'])) {
    $errors['ai_mr_doge_picks'] = $errors['ai_mr_doge_picks'] ?? 'Unexpected AI picks response shape';
    $picks_json = ['data' => []];
}

$ai_picks = $picks_json['data'] ?? [];

list($recs_json, $err) = mrdoge_get($MRDOGE_BASE . '/ai/betting-recommendations', $MRDOGE_AUTH);
$ai_recs_raw = $recs_json;

if ($err) {
    $errors['ai_betting_recommendations'] = $err;
    $recs_json = ['data' => []];
}

if (!isset($recs_json['data']) || !is_array($recs_json['data'])) {
    $errors['ai_betting_recommendations'] = $errors['ai_betting_recommendations'] ?? 'Unexpected AI recommendations response shape';
    $recs_json = ['data' => []];
}

$ai_recs = $recs_json['data'] ?? [];

function ai_event_is_important(array $event): bool {
    $league = strtolower($event['league'] ?? '');
    $home   = strtolower($event['homeTeam'] ?? ($event['home'] ?? ''));
    $away   = strtolower($event['awayTeam'] ?? ($event['away'] ?? ''));

    $needles = [
        'premier league',
        'championship',
        'league one',
        'league two',
        'laliga',
        'la liga',
        'serie a',
        'bundesliga',
        'ligue 1',
        'primeira liga',
        'liga dos campeões',   // UCL
        'liga europa',         // UEL
        'world cup',
        'copa do mundo',
        'qualifier',
        'qualificações',
        'euro',
        'european championship',
    ];

    foreach ($needles as $needle) {
        if ($league !== '' && str_contains($league, $needle)) {
            return true;
        }
    }

    $big_club_needles = ['arsenal','chelsea','liverpool','manchester','bayern','dortmund','psg','real madrid','barcelona','atletico','juventus','inter','milan','benfica','porto','sporting'];
    foreach ($big_club_needles as $needle) {
        if (($home !== '' && str_contains($home, $needle)) ||
            ($away !== '' && str_contains($away, $needle))) {
            return true;
        }
    }

    return false;
}

$ai_picks_filtered = [];
$ai_bookmakers_seen = [];

foreach ($ai_picks as $pick) {
    $event = $pick['event'] ?? [];
    if (!ai_event_is_important($event)) {
        continue;
    }

    $firstRec = $pick['recommendations'][0] ?? null;

    $bk = $pick['bookmakerSource'] ?? ($pick['dataSourceId'] ?? null);
    if ($bk) {
        $ai_bookmakers_seen[$bk] = true;
    }

    $ai_picks_filtered[] = [
        'id'             => $pick['id'] ?? null,
        'pickType'       => $pick['pickType'] ?? null,
        'totalOdds'      => $pick['totalOdds'] ?? null,
        'edgePercentage' => $pick['edgePercentage'] ?? null,
        'confidence'     => $pick['confidence'] ?? null,
        'event'          => [
            'id'            => $event['id'] ?? null,
            'homeTeam'      => $event['homeTeam']['name'] ?? ($event['homeTeam'] ?? null),
            'awayTeam'      => $event['awayTeam']['name'] ?? ($event['awayTeam'] ?? null),
            'startDateTime' => $event['startDateTime'] ?? ($event['commenceTime'] ?? null),
            'league'        => $event['league'] ?? null,
            'competitionId' => $event['competitionId'] ?? null,
            'competition'   => $event['competition']['caption'] ?? null,
        ],
        'firstRecommendation' => $firstRec ? [
            'market'         => $firstRec['market'] ?? null,
            'outcome'        => $firstRec['outcome'] ?? null,
            'odds'           => $firstRec['odds'] ?? null,
            'edgePercentage' => $firstRec['edgePercentage'] ?? null,
            'confidence'     => $firstRec['confidence'] ?? null,
        ] : null,
        'bookmakerSource' => $pick['bookmakerSource'] ?? null,
        'dataSourceId'    => $pick['dataSourceId'] ?? null,
    ];
}

$ai_recs_filtered = [];
foreach ($ai_recs as $rec) {
    $event = $rec['event'] ?? [];
    if (!ai_event_is_important($event)) {
        continue;
    }

    $bk = $rec['bookmakerSource'] ?? ($rec['dataSourceId'] ?? null);
    if ($bk) {
        $ai_bookmakers_seen[$bk] = true;
    }

    $ai_recs_filtered[] = [
        'id'             => $rec['id'] ?? null,
        'eventId'        => $rec['eventId'] ?? null,
        'market'         => $rec['market'] ?? null,
        'outcome'        => $rec['outcome'] ?? null,
        'bookmakerSource'=> $rec['bookmakerSource'] ?? null,
        'dataSourceId'   => $rec['dataSourceId'] ?? null,
        'odds'           => $rec['odds'] ?? null,
        'point'          => $rec['point'] ?? null,
        'edgePercentage' => $rec['edgePercentage'] ?? null,
        'confidence'     => $rec['confidence'] ?? null,
        'kellyFraction'  => $rec['kellyFraction'] ?? null,
        'event'          => [
            'homeTeam'     => $event['homeTeam'] ?? null,
            'awayTeam'     => $event['awayTeam'] ?? null,
            'commenceTime' => $event['commenceTime'] ?? null,
            'league'       => $event['league'] ?? null,
        ],
        'analysisTimestamp' => $rec['analysisTimestamp'] ?? null,
    ];
}


$all_bookmakers = array_keys($ai_bookmakers_seen);
sort($all_bookmakers);

$important_league_names = [

    'Premier League',
    'Championship',
    'League One',
    'League Two',

    'LaLiga',
    'Serie A',
    'Bundesliga',
    'Ligue 1',
    'Primeira Liga',

    'Liga dos Campeões',   // Champions League
    'Liga Europa',         // Europa League
    'Liga Conferência',    // Conference League

    'World Cup',
    'World Cup qualifiers',
    'Euro Cup',
    'European Championship'
];

$soccer_competitions = [];
foreach ($competitions as $c) {
    $name = $c['caption'] ?? '';
    if (in_array($name, $important_league_names, true)) {
        $soccer_competitions[] = $c;
    }
}




$result = [
    'meta' => $meta,
    'errors' => (object)$errors,

    'important_league_spec' => [
        'domestic_england' => [
            'Premier League',
            'Championship',
            'League One',
            'League Two',
        ],
        'big_five' => [
            'LaLiga',
            'Serie A',
            'Bundesliga',
            'Ligue 1',
            'Primeira Liga',
        ],
        'european' => [
            'Champions League',
            'Europa League',
            'Conference League',
        ],
        'international' => [
            'World Cup',
            'World Cup qualifiers',
            'Euro Cup / European Championship',
        ],
    ],

    'competitions_raw_keys' => array_keys($competitions_json ?? []),
    'competitions_by_region' => $competitions_by_region,
    'soccer_competitions' => $soccer_competitions,

    'matches_raw_keys' => array_keys($matches_debug_raw ?? []),

    'matches_count' => $matches_count,
    'matches_by_competition' => $matches_by_competition_arr,
    'sample_matches' => $sample_matches,

    'ai_mr_doge_picks_count' => count($ai_picks_filtered),
    'ai_mr_doge_picks_sample' => array_slice($ai_picks_filtered, 0, 10),

    'ai_betting_recommendations_count' => count($ai_recs_filtered),
    'ai_betting_recommendations_sample' => array_slice($ai_recs_filtered, 0, 10),

    'bookmakers_seen_in_ai' => $all_bookmakers,



];

out_json($result);
