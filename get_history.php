<?php
require 'db.php';
$res = $pdo->query("SELECT * FROM bets ORDER BY created_at DESC LIMIT 100")->fetchAll();
echo json_encode($res);
?>
