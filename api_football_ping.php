<?php
header('Content-Type: text/plain');

$MODE    = 'direct';        // 'direct' if you signed up on api-football.com

$API_KEY = '';

if (!$API_KEY || $API_KEY === 'No API Key') {
    echo "No API key set.\n";
    exit;
}

$BASE_URL = 'https://v3.football.api-sports.io';

function do_call($endpoint, $params, $mode, $key, $base){
    $url = $base . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $headers = [];
    if ($mode === 'direct') {
        $headers[] = 'x-apisports-key: ' . $key;
    } else {
        $headers[] = 'X-RapidAPI-Key: ' . $key;
        $headers[] = 'X-RapidAPI-Host: v3.football.api-sports.io';
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        echo "cURL error for $url: " . curl_error($ch) . "\n";
        curl_close($ch);
        return;
    }
    curl_close($ch);

    echo "URL: $url\n";
    echo "Response:\n$res\n\n";
}

do_call('/status', [], $MODE, $API_KEY, $BASE_URL);

do_call('/teams', ['search' => 'Liverpool'], $MODE, $API_KEY, $BASE_URL);

$season = date('Y');
do_call('/fixtures', ['search' => 'Liverpool', 'season' => $season, 'next' => 10], $MODE, $API_KEY, $BASE_URL);
