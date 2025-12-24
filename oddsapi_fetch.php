<?php
// oddsapi_fetch.php
// The Odds API v4 integration (soccer 1X2 via markets=h2h)

function oddsapi_http_get(string $url, int $timeout = 15): array {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $raw = curl_exec($ch);
  $info = curl_getinfo($ch);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    return ['ok'=>false, 'code'=>0, 'err'=>$err, 'headers'=>[], 'body'=>null];
  }

  $hsz = $info['header_size'] ?? 0;
  $hdr = substr($raw, 0, $hsz);
  $body= substr($raw, $hsz);

  $headers = [];
  foreach (preg_split("/\r?\n/", $hdr) as $line) {
    if (strpos($line, ':') !== false) {
      [$k,$v] = array_map('trim', explode(':', $line, 2));
      $headers[strtolower($k)] = $v;
    }
  }

  $json = json_decode($body, true);

  return [
    'ok' => is_array($json),
    'code' => (int)($info['http_code'] ?? 0),
    'err' => null,
    'headers' => $headers,
    'body' => $json,
  ];
}

function oddsapi_pick_best_h2h(array $event): ?array {
  // returns: ['home'=>['odds'=>..,'bk'=>..], 'draw'=>..., 'away'=>...]
  $home = $event['home_team'] ?? null;
  $away = $event['away_team'] ?? null;
  if (!$home || !$away) return null;

  $best = ['home'=>null,'draw'=>null,'away'=>null];

  foreach (($event['bookmakers'] ?? []) as $bk) {
    $bkName = $bk['title'] ?? ($bk['key'] ?? 'OddsAPI');
    foreach (($bk['markets'] ?? []) as $mkt) {
      if (($mkt['key'] ?? '') !== 'h2h') continue;

      foreach (($mkt['outcomes'] ?? []) as $out) {
        $name  = $out['name'] ?? '';
        $price = $out['price'] ?? null;
        if (!is_numeric($price)) continue;
        $price = (float)$price;

        if (strcasecmp($name, $home) === 0) {
          if (!$best['home'] || $price > $best['home']['odds']) $best['home']=['odds'=>$price,'bk'=>"OddsAPI:$bkName"];
        } elseif (strcasecmp($name, $away) === 0) {
          if (!$best['away'] || $price > $best['away']['odds']) $best['away']=['odds'=>$price,'bk'=>"OddsAPI:$bkName"];
        } elseif (strcasecmp($name, 'draw') === 0) {
          if (!$best['draw'] || $price > $best['draw']['odds']) $best['draw']=['odds'=>$price,'bk'=>"OddsAPI:$bkName"];
        }
      }
    }
  }

  // require at least home+away (draw optional in some markets, but usually present)
  if (!$best['home'] || !$best['away']) return null;
  return $best;
}

/**
 * Fetch back odds from The Odds API.
 * Normalises into the same event shape as MrDoge / RapidAPI.
 */
function fetch_oddsapi_back_events(): array {
  $cfgAll = @include __DIR__ . '/config/odds_creds.php';
  $cfg = is_array($cfgAll) ? ($cfgAll['oddsapi'] ?? null) : null;
  if (!is_array($cfg)) return [];

  $keys = array_values(array_filter($cfg['keys'] ?? [], fn($k)=>is_string($k) && $k !== ''));
  if (!$keys) return [];

  $regions    = $cfg['regions'] ?? 'uk';
  $markets    = $cfg['markets'] ?? 'h2h';
  $oddsFormat = $cfg['oddsFormat'] ?? 'decimal';
  $dateFormat = $cfg['dateFormat'] ?? 'iso';
  $sports     = $cfg['sports'] ?? ['soccer_epl'];

  $events = [];

  foreach ($sports as $sportKey) {
    $baseParams = [
      'regions'    => $regions,
      'markets'    => $markets,
      'oddsFormat' => $oddsFormat,
      'dateFormat' => $dateFormat,
    ];

    // Try keys in order (key #2 is fallback)
    $resp = null;
    foreach ($keys as $apiKey) {
      $url = 'https://api.the-odds-api.com/v4/sports/' . rawurlencode($sportKey) . '/odds?'
           . http_build_query($baseParams + ['apiKey' => $apiKey]);

      $try = oddsapi_http_get($url, 20);

      // 200 OK -> use it
      if ($try['ok'] && $try['code'] === 200) { $resp = $try; break; }

      // 429 rate limited -> try next key
      if ($try['code'] === 429) { continue; }

      // other errors -> don’t keep hammering keys
      $resp = $try;
      break;
    }

    if (!$resp || !$resp['ok'] || $resp['code'] !== 200) {
      continue;
    }

    foreach (($resp['body'] ?? []) as $ev) {
      $home = $ev['home_team'] ?? null;
      $away = $ev['away_team'] ?? null;
      $time = $ev['commence_time'] ?? null;
      if (!$home || !$away) continue;

      $best = oddsapi_pick_best_h2h($ev);
      if (!$best) continue;

      $key = strtolower($home) . '|' . strtolower($away);

      $events[$key] = [
        'home'  => $home,
        'away'  => $away,
        'time'  => $time,
        'backs' => [
          'home' => $best['home'],
          'draw' => $best['draw'], // may be null
          'away' => $best['away'],
        ],
        'lays'  => ['home'=>null,'draw'=>null,'away'=>null],
        'meta'  => ['provider' => 'oddsapi', 'sport_key' => $sportKey],
      ];
    }
  }

  return $events;
}

