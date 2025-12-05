<?php
header('Content-Type: text/plain');
require __DIR__.'/mb_config.php';

$MB_LOGIN_URL = 'https://api.matchbook.com/bpapi/rest/security/session';
$USER = 'MATCHBOOK_USERNAME';
$PASS = 'MATCHBOOK_PASSWORD';

echo "Server time: " . date('c') . "\n";
echo "PHP cURL: " . (function_exists('curl_version') ? 'yes' : 'no') . "\n";

$ch = curl_init();
$payload = json_encode(['username'=>$USER,'password'=>$PASS]);

curl_setopt_array($ch, [
  CURLOPT_URL            => $MB_LOGIN_URL,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER         => true,              // include headers in output
  CURLOPT_TIMEOUT        => 20,
  CURLOPT_HTTPHEADER     => [
    'Accept: application/json',
    'Content-Type: application/json',

    'User-Agent: OddsMatcher/1.0 (+https://YOURDOMAIN)'
  ],
]);

$raw = curl_exec($ch);
$info = curl_getinfo($ch);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP code: " . ($info['http_code'] ?? 'n/a') . "\n";
echo "Primary IP: " . ($info['primary_ip'] ?? 'n/a') . "\n";
echo "Content-Type: " . ($info['content_type'] ?? 'n/a') . "\n";
echo "Total time: " . ($info['total_time'] ?? 'n/a') . "\n\n";

list($hdrs, $body) = explode("\r\n\r\n", $raw, 2);
echo "=== Response headers ===\n$hdrs\n\n";
echo "=== First 600 bytes of body ===\n" . substr($body, 0, 600) . "\n";

$decoded = json_decode($body, true);
echo "\n=== Decoded JSON ===\n";
var_dump($decoded);
