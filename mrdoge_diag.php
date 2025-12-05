<?php



header('Content-Type: application/json');

$API_KEY = 'sk_live_PUT_API_KEY_HERE';
$BASE    = 'https://api.mrdoge.co/v2';

function http_get_json_mrdoge($url, $apiKey, $timeout = 15) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => "curl_error: $err", 'url' => $url];
    }
    $info = curl_getinfo($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    if (!is_array($data)) {
        return [
            'error'   => 'json_decode_failed',
            'url'     => $url,
            'http'    => $info['http_code'] ?? null,
            'snippet' => substr($res, 0, 400),
        ];
    }
    return $data;
}

$out = [];


$compsUrl = $BASE . '/competitions';
$compsRaw = http_get_json_mrdoge($compsUrl, $API_KEY);

$out['competitions_sample_raw'] = array_slice($compsRaw['data'] ?? [], 0, 10);

$comps = $compsRaw['data'] ?? $compsRaw;
$compSummary = [];

if (is_array($comps)) {
    foreach ($comps as $c) {
        $id     = $c['id']     ?? null;
        $name   = $c['name']   ?? null;
        $sport  = $c['sport']  ?? null;
        $region = $c['region']['caption'] ?? ($c['region'] ?? null);

        if ($sport !== 'soccer') {
            continue; // I'm only interested in football here
        }

        $compSummary[] = [
            'id'     => $id,
            'name'   => $name,
            'region' => $region,
        ];
    }
}

$out['soccer_competitions'] = $compSummary;


$matchesUrl = $BASE . '/matches?' . http_build_query([
    'sport'  => 'soccer',
    'locale' => 'en',
    'limit'  => 200,
]) . '&status=upcoming&status=live';

$matchesRaw = http_get_json_mrdoge($matchesUrl, $API_KEY);
$out['matches_raw_keys'] = is_array($matchesRaw) ? array_keys($matchesRaw) : null;

$matches = $matchesRaw['data'] ?? $matchesRaw;

$matchSummary = [];
$byCompetition = [];

if (is_array($matches)) {
    foreach ($matches as $m) {
        $id       = $m['id']             ?? null;
        $home     = $m['homeTeam']['name'] ?? null;
        $away     = $m['awayTeam']['name'] ?? null;
        $time     = $m['startDateTime']  ?? ($m['startDate'] ?? null);
        $status   = $m['status']         ?? null;
        $compId   = $m['competition']['id']       ?? null;
        $compName = $m['competition']['name']     ?? null;
        $region   = $m['region']['caption']       ?? ($m['region'] ?? null);

        if (!$id || !$home || !$away) continue;

        $matchSummary[] = [
            'id'         => $id,
            'home'       => $home,
            'away'       => $away,
            'time'       => $time,
            'status'     => $status,
            'competition_id'   => $compId,
            'competition_name' => $compName,
            'region'     => $region,
        ];

        $key = ($sport ?? 'soccer') . '|' . ($compName ?? 'unknown');
        $key = $compName ?: 'Unknown competition';

        if (!isset($byCompetition[$key])) {
            $byCompetition[$key] = [
                'competition' => $compName,
                'region'      => $region,
                'count'       => 0,
            ];
        }
        $byCompetition[$key]['count']++;
    }
}

$out['matches_count']         = is_array($matches) ? count($matches) : 0;
$out['matches_by_competition'] = array_values($byCompetition);
$out['sample_matches']         = array_slice($matchSummary, 0, 15);


$sampleOdds = null;
if (!empty($matchSummary)) {
    $firstId = $matchSummary[0]['id'];
    $oddsUrl = $BASE . '/matches/' . urlencode($firstId) . '/live-odds';
    $oddsRaw = http_get_json_mrdoge($oddsUrl, $API_KEY);

    $sampleOdds = [
        'match_id'  => $firstId,
        'odds_keys' => is_array($oddsRaw) ? array_keys($oddsRaw) : null,
        'snippet'   => $oddsRaw, // you can trim this later if too big
    ];
}
$out['sample_live_odds'] = $sampleOdds;

$aiPicksUrl = $BASE . '/ai/mr-doge-picks';
$aiPicksRaw = http_get_json_mrdoge($aiPicksUrl, $API_KEY);
$out['ai_mr_doge_picks_keys'] = is_array($aiPicksRaw) ? array_keys($aiPicksRaw) : null;
$out['ai_mr_doge_picks_sample'] = is_array($aiPicksRaw)
    ? array_slice($aiPicksRaw['data'] ?? $aiPicksRaw, 0, 10)
    : $aiPicksRaw;

$aiRecsUrl = $BASE . '/ai/betting-recommendations';
$aiRecsRaw = http_get_json_mrdoge($aiRecsUrl, $API_KEY);
$out['ai_betting_recommendations_keys'] = is_array($aiRecsRaw) ? array_keys($aiRecsRaw) : null;
$out['ai_betting_recommendations_sample'] = is_array($aiRecsRaw)
    ? array_slice($aiRecsRaw['data'] ?? $aiRecsRaw, 0, 10)
    : $aiRecsRaw;


echo json_encode($out, JSON_PRETTY_PRINT);

