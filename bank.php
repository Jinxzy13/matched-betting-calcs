<?php

require 'db.php'; // make sure this creates a PDO instance in $pdo and uses exceptions


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_transaction') {
        $from   = !empty($_POST['from_account_id']) ? (int)$_POST['from_account_id'] : null;
        $to     = !empty($_POST['to_account_id'])   ? (int)$_POST['to_account_id']   : null;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $desc   = trim($_POST['description'] ?? '');

        $dateInput = $_POST['txn_time'] ?? '';
        $date = $dateInput !== '' ? $dateInput : date('Y-m-d H:i:s');

        if ($amount > 0 && ($from || $to)) {
            $ins = $pdo->prepare("
              INSERT INTO transactions (txn_time, from_account_id, to_account_id, amount, description)
              VALUES (:txn_time, :from, :to, :amount, :description)
            ");
            $ins->execute([
                ':txn_time'     => $date,
                ':from'         => $from,
                ':to'           => $to,
                ':amount'       => $amount,
                ':description'  => $desc,
            ]);
        }
        header('Location: bank.php');
        exit;
    }

    if ($action === 'add_account') {
        $name = trim($_POST['account_name'] ?? '');
        $code = trim($_POST['account_code'] ?? '');
        $type = $_POST['account_type'] ?? 'bookmaker';

        if ($name !== '' && $code !== '') {
            $ins = $pdo->prepare("
              INSERT INTO accounts (name, code, type, starting_balance)
              VALUES (:name, :code, :type, 0.00)
            ");
            $ins->execute([
                ':name' => $name,
                ':code' => strtolower($code),
                ':type' => $type,
            ]);
        }
        header('Location: bank.php');
        exit;
    }

    if ($action === 'delete_transaction') {
        $id = isset($_POST['txn_id']) ? (int)$_POST['txn_id'] : 0;
        if ($id > 0) {
            $del = $pdo->prepare("DELETE FROM transactions WHERE id = :id LIMIT 1");
            $del->execute([':id' => $id]);
        }
        header('Location: bank.php');
        exit;
    }
}


$stmt = $pdo->query("
  SELECT a.id, a.name, a.code, a.type,
         a.starting_balance
         + IFNULL(SUM(CASE WHEN t.to_account_id   = a.id THEN t.amount ELSE 0 END),0)
         - IFNULL(SUM(CASE WHEN t.from_account_id = a.id THEN t.amount ELSE 0 END),0)
         AS balance
  FROM accounts a
  LEFT JOIN transactions t
    ON t.to_account_id = a.id OR t.from_account_id = a.id
  GROUP BY a.id, a.name, a.code, a.type, a.starting_balance
  ORDER BY a.type, a.name
");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$accountMap = [];
foreach ($accounts as $acc) {
    $accountMap[$acc['id']] = $acc['name'];
}

$tstmt = $pdo->query("
  SELECT t.*, 
         fa.name AS from_name,
         ta.name AS to_name
  FROM transactions t
  LEFT JOIN accounts fa ON fa.id = t.from_account_id
  LEFT JOIN accounts ta ON ta.id = t.to_account_id
  ORDER BY t.txn_time DESC, t.id DESC
");
$transactions = $tstmt->fetchAll(PDO::FETCH_ASSOC);

$defaultDateTime = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Matched Betting Bank</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Ubuntu',sans-serif; background:#021e28; color:#e6faff; margin:0; padding:20px; }
    h1,h2 { margin-top:0; }
    .balances-wrapper { overflow-x:auto; margin-bottom:20px; }
    table { border-collapse:collapse; width:100%; font-size:0.9rem; }
    th, td { padding:6px 8px; border-bottom:1px solid #0e586d; text-align:left; }
    th { background:#04394a; position:sticky; top:0; }
    tr:nth-child(even) { background:#032634; }
    .pos { color:#2ed47a; }
    .neg { color:#ff6b6b; }
    .forms { display:flex; flex-wrap:wrap; gap:16px; margin:20px 0; }
    form.box {
      background:#002d3c; padding:12px; border-radius:10px;
      box-shadow:0 0 8px rgba(0,0,0,0.4); flex:1 1 280px;
    }
    label { font-size:0.8rem; display:block; margin-bottom:2px; }
    input, select {
      width:100%; padding:4px 6px; border-radius:4px;
      border:1px solid #0e586d; background:#021e28; color:#e6faff;
    }
    button {
      margin-top:6px; padding:6px 10px; border:none; border-radius:6px;
      background:#16b7c5; color:#021e28; font-weight:600; cursor:pointer;
    }
    .row { display:flex; gap:8px; margin-bottom:6px; }
    .row > div { flex:1 1 0; }

    .delete-btn {
  padding:2px 6px;
  border-radius:4px;
  border:none;
  background:#ff6b6b;
  color:#021e28;
  font-size:0.8rem;
  cursor:pointer;
}


  </style>
</head>
<body>
  <h1>Bank & Balances</h1>

  
  <div class="balances-wrapper">
    <table>
      <thead>
        <tr>
          <?php foreach ($accounts as $acc): ?>
            <th><?= htmlspecialchars($acc['name']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr>
          <?php foreach ($accounts as $acc): ?>
            <?php
              $bal = (float)$acc['balance'];
              $cls = $bal >= 0 ? 'pos' : 'neg';
            ?>
            <td class="<?= $cls ?>">£<?= number_format($bal, 2) ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
  </div>

  
  <div class="forms">
    
    <form class="box" method="post">
      <input type="hidden" name="action" value="add_transaction">
      <h2>Add transaction</h2>
      <div class="row">
        <div>
          <label for="txn_time">Date / Time</label>
          
          <input type="datetime-local" name="txn_time" id="txn_time"
                 value="<?= htmlspecialchars($defaultDateTime) ?>">
        </div>
        <div>
          <label for="amount">Amount (£)</label>
          <input type="number" step="0.01" name="amount" id="amount" required>
        </div>
      </div>
      <div class="row">
        <div>
          <label for="from_account_id">From</label>
          <select name="from_account_id" id="from_account_id">
            <option value="">(none)</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="to_account_id">To</label>
          <select name="to_account_id" id="to_account_id">
            <option value="">(none)</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div>
        <label for="description">Description</label>
        <input type="text" name="description" id="description"
               placeholder="Deposit to Matchbook, Withdrawal from Bet365, etc.">
      </div>
      <button type="submit">Save transaction</button>
    </form>

    
    <form class="box" method="post">
      <input type="hidden" name="action" value="add_account">
      <h2>Add bookmaker / exchange</h2>
      <div class="row">
        <div>
          <label for="account_name">Name</label>
          <input type="text" name="account_name" id="account_name" placeholder="e.g. Betano" required>
        </div>
        <div>
          <label for="account_code">Code</label>
          <input type="text" name="account_code" id="account_code" placeholder="e.g. betano" required>
        </div>
      </div>
      <div class="row">
        <div>
          <label for="account_type">Type</label>
          <select name="account_type" id="account_type">
            <option value="bookmaker">Bookmaker</option>
            <option value="exchange">Exchange</option>
            <option value="bank">Bank</option>
            <option value="wallet">Wallet</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <button type="submit">Add account</button>
      <p style="font-size:0.8rem; opacity:0.8; margin-top:6px;">
        After saving, the page refreshes and the new name appears in the From/To dropdowns.
      </p>
    </form>
  </div>

  
  <h2>Transactions</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>From</th>
        <th>To</th>
        <th>Amount</th>
        <th>Description</th>
        <th></th> 
      </tr>
    </thead>
    <tbody>
      <?php foreach ($transactions as $t): ?>
        <tr>
  <td><?= htmlspecialchars($t['txn_time']) ?></td>
  <td><?= htmlspecialchars($t['from_name'] ?? '') ?></td>
  <td><?= htmlspecialchars($t['to_name'] ?? '') ?></td>
  <td>£<?= number_format($t['amount'], 2) ?></td>
  <td><?= htmlspecialchars($t['description']) ?></td>
  <td>
    <form method="post" style="margin:0;" 
          onsubmit="return confirm('Delete this transaction?');">
      <input type="hidden" name="action" value="delete_transaction">
      <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
      <button type="submit" class="delete-btn" title="Delete">✕</button>
    </form>
  </td>
</tr>

      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
