<?php



header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '' || mb_strlen($q) < 3) {
    echo json_encode([]);
    exit;
}

$API_KEY = 'PUT_API_KEY_HERE';
if (!$API_KEY) {
    echo json_encode([]);
    exit;
}

$BASE_URL = 'https://api.football-data.org/v4';

$tz    = new DateTimeZone('Europe/London');
$today = new DateTime('now', $tz);
$from  = (clone $today)->modify('-1 day')->format('Y-m-d');
$to    = (clone $today)->modify('+14 days')->format('Y-m-d');

$COMPETITIONS = [
    'PL'  => 'Premier League',
    'CL'  => 'Champions League',
    'EL'  => 'Europa League',
    'SA'  => 'Serie A',
    'BL1' => 'Bundesliga',
    'PD'  => 'La Liga',
    'FL1' => 'Ligue 1',
    'ELC' => 'Championship',
    'QUFA' => 'WC Qualification UEFA',
    'PPL'  => 'Primeira Liga',
    'PD2'  => 'Primera Division',
];


function fd_get_matches($compCode, $dateFrom, $dateTo, $apiKey, $baseUrl) {
    $url = $baseUrl . '/competitions/' . urlencode($compCode) . '/matches?' .
           http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Auth-Token: ' . $apiKey],
        CURLOPT_TIMEOUT        => 6,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log("fixtures_search curl error ($compCode): " . curl_error($ch));
        curl_close($ch);
        return [];
    }
    curl_close($ch);

    $data = json_decode($res, true);
    return (is_array($data) && isset($data['matches'])) ? $data['matches'] : [];
}

$matches = [];
foreach ($COMPETITIONS as $code => $name) {
    $m = fd_get_matches($code, $from, $to, $API_KEY, $BASE_URL);
    if (!empty($m)) {
        $matches = array_merge($matches, $m);
    }
}

if (empty($matches)) {
    echo json_encode([]);
    exit;
}

$qLower = mb_strtolower($q);
$seen = [];
$out = [];

foreach ($matches as $m) {
    $id   = $m['id'] ?? null;
    $home = $m['homeTeam']['name'] ?? '';
    $away = $m['awayTeam']['name'] ?? '';
    $comp = $m['competition']['name'] ?? '';
    $date = $m['utcDate'] ?? '';

    if (!$id || $home === '' || $away === '') continue;

    $label = "$home vs $away";
    $hay   = mb_strtolower("$home $away $comp");

    if (mb_strpos($hay, $qLower) === false) continue;
    if (isset($seen[$id])) continue;
    $seen[$id] = true;

    $out[] = [
        'id'     => $id,
        'label'  => $label,
        'league' => $comp,
        'date'   => $date,
    ];

    if (count($out) >= 12) break;
}

echo json_encode($out);
