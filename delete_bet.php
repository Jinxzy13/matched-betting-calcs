<?php
require 'db.php';
$id = intval($_GET['id'] ?? 0);
if($id>0){
  $pdo->prepare("DELETE FROM bets WHERE id=?")->execute([$id]);
}
echo json_encode(['ok'=>true]);
?>
