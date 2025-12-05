<?php




$MRDOGE_API_KEY = 'sk_live_PUT_API_KEY_HERE';
$MRDOGE_BASE    = 'https://api.mrdoge.co/v2';


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
    ];

    $url = $MRDOGE_BASE . '/matches'
         . '?sport=soccer'
         . '&status=upcoming'
         . '&status=live'
         . '&locale=en'
         . '&limit=500';

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
    $res = curl_exec($ch);
    if ($res === false) {
        curl_close($ch);
        return [];
    }
    curl_close($ch);

    $json = json_decode($res, true);
    if (!is_array($json)) {
        return [];
    }

    $events = [];

    foreach (($json['data'] ?? []) as $m) {
        $compId = $m['competitionId']
            ?? ($m['competition']['id'] ?? null);

        if (!$compId || !in_array((int)$compId, $allowedCompetitions, true)) {
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
