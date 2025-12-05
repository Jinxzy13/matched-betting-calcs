<?php
require 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || empty($data['id'])) { http_response_code(400); exit('Missing id'); }

$id = (int)$data['id'];
$settled = !empty($data['settled']) ? 1 : 0;
$settled_result = $data['settled_result'] ?? null;
$settled_pl = isset($data['settled_pl']) ? (float)$data['settled_pl'] : null;

if ($settled) {
  $stmt = $pdo->prepare("
    UPDATE bets
    SET settled = 1,
        settled_result = ?,
        settled_pl = ?,
        settled_at = NOW()
    WHERE id = ?
  ");
  $stmt->execute([$settled_result, $settled_pl, $id]);
} else {

  $stmt = $pdo->prepare("
    UPDATE bets
    SET settled = 0,
        settled_result = NULL,
        settled_pl = NULL,
        settled_at = NULL
    WHERE id = ?
  ");
  $stmt->execute([$id]);
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
