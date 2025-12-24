<?php
// matchbook_place_offer.php
header('Content-Type: application/json');

// 1) Read request
$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!$in) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad JSON']); exit; }

$runnerId = (int)($in['runner_id'] ?? 0);
$odds     = (float)($in['odds'] ?? 0);
$stake    = (float)($in['stake'] ?? 0);
$side     = strtolower(trim($in['side'] ?? 'lay'));

if ($runnerId <= 0 || $odds < 1.01 || $stake <= 0 || !in_array($side, ['lay','back'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid runner_id/odds/stake/side']);
  exit;
}

// 2) Get session-token (cache it ~6 hours)
require_once __DIR__ . '/mb_config.php'; // store MATCHBOOK_USER/PASS safely (NOT in git)
$sessionToken = matchbook_get_session_token(); // you implement with caching

// 3) Submit offer
$payload = [[
  'runner-id' => $runnerId,
  'side'      => $side,
  'odds'      => $odds,
  'stake'     => $stake,
  'keep-in-play' => false,
]];

$ch = curl_init('https://api.matchbook.com/edge/rest/v2/offers');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'accept: application/json',
    'content-type: application/json',
    'session-token: ' . $sessionToken,
  ],
  CURLOPT_POSTFIELDS => json_encode($payload),
]);
$out = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($out === false) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'cURL error: '.$err]);
  exit;
}

echo json_encode([
  'ok' => ($http >= 200 && $http < 300),
  'http' => $http,
  'resp' => json_decode($out, true),
]);
