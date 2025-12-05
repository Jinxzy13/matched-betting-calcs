<?php
require 'db.php';

$pdo->exec("TRUNCATE TABLE bets");

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
?>
