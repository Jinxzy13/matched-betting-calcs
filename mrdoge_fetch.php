<?php
// mrdoge_fetch.php

$cfg = @include __DIR__ . '/config/odds_creds.php';
if (is_array($cfg) && isset($cfg['mrdoge'])) {
  $MRDOGE_API_KEY = $cfg['mrdoge']['api_key'] ?? '';
  $MRDOGE_BASE    = $cfg['mrdoge']['base'] ?? 'https://api.mrdoge.co/v2';
} else {
  $MRDOGE_API_KEY = '';
  $MRDOGE_BASE    = 'https://api.mrdoge.co/v2';
}


function fetch_mrdoge_back_events(): array
{
    global $MRDOGE_API_KEY, $MRDOGE_BASE;

    if (!$MRDOGE_API_KEY) {
        return [];
    }

    $allowedCompetitions = [

        847,      // Premier League
        78,       // Championship
        81,       // League One
        82,       // League Two

        29,       // LaLiga
        25,       // Serie A (Italy)
        79,       // Bundesliga (Germany)
        35,       // Ligue 1
        103,      // Primeira Liga

        33333,    // Liga dos Campeões (UCL)
        33619,    // Liga Europa (UEL)
        33620,    // Liga Conferência (UECL)

        65,       // Sample I think
    ];

   $url = $MRDOGE_BASE . '/matches?sport=soccer&status=upcoming&locale=en&limit=500';


    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $MRDOGE_API_KEY,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
   $res  = curl_exec($ch);
$err  = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

if ($res === false) {
    error_log("MRDOGE curl error: $err");
    return [];
}

if (($info['http_code'] ?? 0) !== 200) {
    error_log("MRDOGE HTTP " . ($info['http_code'] ?? 0) . " url=$url body_snip=" . substr($res, 0, 250));
    return [];
}

$json = json_decode($res, true);
if (!is_array($json)) {
    error_log("MRDOGE non-JSON body_snip=" . substr($res, 0, 250));
    return [];
}

error_log("MRDOGE ok: keys=" . implode(',', array_keys($json)) . " data_count=" . count($json['data'] ?? []));


    $events = [];

    foreach (($json['data'] ?? []) as $m) {
        $compId = $m['competitionId']
            ?? ($m['competition']['id'] ?? null);

       if (!$compId) {
    continue;
}


        $home = $m['homeTeam']['name'] ?? null;
        $away = $m['awayTeam']['name'] ?? null;
        if (!$home || !$away) continue;

        $markets = $m['markets'] ?? [];
        $odds    = ['home' => null, 'draw' => null, 'away' => null];

        foreach ($markets as $mar) {
            if (($mar['betTypeSysname'] ?? '') !== 'SOCCER_MATCH_RESULT') continue;

            foreach ($mar['betItems'] ?? [] as $item) {
                $code  = $item['code']  ?? null;
                $price = $item['price'] ?? null;
                if (!$code || !$price) continue;

                if ($code === '1') $odds['home'] = $price;
                if ($code === 'X') $odds['draw'] = $price;
                if ($code === '2') $odds['away'] = $price;
            }
        }

        if ($odds['home'] || $odds['draw'] || $odds['away']) {
            $key = strtolower($home) . '|' . strtolower($away);

            $events[$key] = [
                'home'  => $home,
                'away'  => $away,
                'time'  => $m['startDateTime'] ?? ($m['commenceTime'] ?? null),
                'backs' => [
                    'home' => $odds['home'] ? ['odds' => (float)$odds['home'], 'bk' => 'MrDoge'] : null,
                    'draw' => $odds['draw'] ? ['odds' => (float)$odds['draw'], 'bk' => 'MrDoge'] : null,
                    'away' => $odds['away'] ? ['odds' => (float)$odds['away'], 'bk' => 'MrDoge'] : null,
                ],
                'lays'  => ['home' => null, 'draw' => null, 'away' => null],
                'meta'  => [
                    'provider'       => 'mrdoge',
                    'competition_id' => (int)$compId,
                ],
            ];
        }
    }

    return $events;
}
