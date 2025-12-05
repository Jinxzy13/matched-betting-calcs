<?php
require 'db.php';
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) { http_response_code(400); exit('No data'); }

$stmt = $pdo->prepare("
  INSERT INTO bets
  (event,outcome,bookmaker,exchange,bet_type,no_lay,stake,back_odds,lay_odds,commission,lay_stake,back_wins,lay_wins,expected_pl)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$stmt->execute([
  $data['event'],
  $data['outcome'],
  $data['bookie'],
  $data['exchange'],
  $data['betType'],
  !empty($data['noLay']) ? 1 : 0,
  $data['stake'],
  $data['backOdds'],
  $data['layOdds'],
  $data['commissionPct'],
  $data['layStake'],
  $data['backWins'],
  $data['layWins'],
  $data['expectedPL']
]);

echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId()]);
