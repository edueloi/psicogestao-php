<?php
ob_start();
require __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$is_mysql = (defined('DB_TYPE') && DB_TYPE === 'mysql');
$user_id = $_SESSION['psicogestao_id'] ?? null;

const AUTH_USER = 'karen.l.s.gomes@gmail.com';
const AUTH_PASS = 'Bibia.0110';

function sendJson($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input') ?: '';
  if ($raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function isAuthenticated(): bool {
  return !empty($_SESSION['psicogestao_auth']) && $_SESSION['psicogestao_auth'] === true;
}

function requireAuth(): void {
  if (!isAuthenticated()) {
    sendJson(['error' => 'Nao autenticado'], 401);
  }
}

function decodeTags($value): array {
  if ($value === null || $value === '') return [];
  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : [];
}

function mapTxRow(array $row): array {
  return [
    'id' => (string) $row['id'],
    'date' => (string) $row['date'],
    'description' => (string) $row['description'],
    'payerName' => (string) $row['payerName'],
    'beneficiaryName' => (string) $row['beneficiaryName'],
    'amount' => (float) $row['amount'],
    'type' => (string) $row['type'],
    'category' => (string) $row['category'],
    'method' => (string) $row['method'],
    'status' => (string) $row['status'],
    'receiptStatus' => $row['receiptStatus'] !== null ? (string) $row['receiptStatus'] : null,
    'payerCpf' => $row['payerCpf'] !== null ? (string) $row['payerCpf'] : null,
    'beneficiaryCpf' => $row['beneficiaryCpf'] !== null ? (string) $row['beneficiaryCpf'] : null,
    'observation' => $row['observation'] !== null ? (string) $row['observation'] : null,
    'tags' => decodeTags($row['tags'])
  ];
}

function normalizeTxInput(array $src): array {
  $type = (($src['type'] ?? 'INCOME') === 'EXPENSE') ? 'EXPENSE' : 'INCOME';
  $status = in_array(($src['status'] ?? 'PAID'), ['PAID', 'PENDING', 'OVERDUE'], true) ? $src['status'] : 'PAID';

  $tags = [];
  if (isset($src['tags'])) {
    if (is_array($src['tags'])) $tags = $src['tags'];
    elseif (is_string($src['tags']) && $src['tags'] !== '') {
      $decoded = json_decode($src['tags'], true);
      if (is_array($decoded)) $tags = $decoded;
    }
  }

  return [
    'id' => (string) ($src['id'] ?? uniqid('tx_', true)),
    'date' => (string) ($src['date'] ?? date('Y-m-d')),
    'description' => trim((string) ($src['description'] ?? 'Sem descricao')),
    'payerName' => trim((string) ($src['payerName'] ?? 'Desconhecido')),
    'beneficiaryName' => trim((string) ($src['beneficiaryName'] ?? 'Desconhecido')),
    'amount' => (float) ($src['amount'] ?? 0),
    'type' => $type,
    'category' => trim((string) ($src['category'] ?? ($type === 'INCOME' ? 'Sessao Individual' : 'Despesa'))),
    'method' => trim((string) ($src['method'] ?? 'PIX')),
    'status' => $status,
    'receiptStatus' => $type === 'INCOME' ? (string) ($src['receiptStatus'] ?? 'PENDING') : null,
    'payerCpf' => isset($src['payerCpf']) && $src['payerCpf'] !== '' ? (string) $src['payerCpf'] : null,
    'beneficiaryCpf' => isset($src['beneficiaryCpf']) && $src['beneficiaryCpf'] !== '' ? (string) $src['beneficiaryCpf'] : null,
    'observation' => isset($src['observation']) && $src['observation'] !== '' ? (string) $src['observation'] : null,
    'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  ];
}

try {
  $pdo = db();

  // Verifica permissão de escrita em produção
  $dbFile = __DIR__ . '/database.sqlite';
  if (!is_writable($dbFile) || !is_writable(__DIR__)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
      sendJson(['error' => 'Permissao negada no disco. O servidor nao pode gravar no banco SQLite.', 'detail' => 'Verifique as permissoes da pasta e do arquivo database.sqlite no htdocs de producao.'], 500);
    }
  }

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $path = $_GET['path'] ?? '';
  $id = $_GET['id'] ?? null;

  if ($path === 'auth/me' && $method === 'GET') {
    if (isAuthenticated()) sendJson(['authenticated' => true, 'username' => AUTH_USER]);
    sendJson(['authenticated' => false]);
  }

  if ($path === 'auth/login' && $method === 'POST') {
    $body = readJsonBody();
    $username = trim((string)($body['username'] ?? ''));
    $password = trim((string)($body['password'] ?? ''));

    if ($username === AUTH_USER && $password === AUTH_PASS) {
      session_regenerate_id(true);
      $_SESSION['psicogestao_auth'] = true;
      $_SESSION['psicogestao_user'] = AUTH_USER;
      sendJson(['ok' => true, 'username' => AUTH_USER]);
    }

    sendJson(['error' => 'Credenciais invalidas'], 401);
  }

  if ($path === 'auth/logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
  }

  requireAuth();

  if ($path === 'transactions' && $method === 'GET') {
    $sql_sort = $is_mysql ? "ORDER BY date DESC, internal_id DESC" : "ORDER BY date DESC, rowid DESC";
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE userId = ? $sql_sort");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendJson(array_map('mapTxRow', $rows));
  }

  if ($path === 'transactions' && $method === 'POST') {
    $payload = normalizeTxInput(readJsonBody());
    $payload['userId'] = $user_id;

    $stmt = $pdo->prepare('INSERT INTO transactions (id, userId, date, description, payerName, beneficiaryName, amount, type, category, method, status, receiptStatus, payerCpf, beneficiaryCpf, observation, tags) VALUES (:id, :userId, :date, :description, :payerName, :beneficiaryName, :amount, :type, :category, :method, :status, :receiptStatus, :payerCpf, :beneficiaryCpf, :observation, :tags)');
    $stmt->execute($payload);

    sendJson(['ok' => true, 'id' => $payload['id']], 201);
  }

  if ($path === 'transactions' && $method === 'PUT') {
    if (!$id) sendJson(['error' => 'ID obrigatorio'], 400);

    $payload = normalizeTxInput(readJsonBody());
    $payload['id'] = (string) $id;
    $payload['userId'] = $user_id;

    $stmt = $pdo->prepare('UPDATE transactions SET date=:date, description=:description, payerName=:payerName, beneficiaryName=:beneficiaryName, amount=:amount, type=:type, category=:category, method=:method, status=:status, receiptStatus=:receiptStatus, payerCpf=:payerCpf, beneficiaryCpf=:beneficiaryCpf, observation=:observation, tags=:tags WHERE id=:id AND userId=:userId');
    $stmt->execute($payload);

    sendJson(['ok' => true]);
  }

  if ($path === 'transactions' && $method === 'DELETE') {
    if (!$id) sendJson(['error' => 'ID obrigatorio'], 400);
    $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND userId = :userId');
    $stmt->execute(['id' => (string) $id, 'userId' => $user_id]);
    sendJson(['ok' => true]);
  }

  if ($path === 'transactions/repeat' && $method === 'POST') {
    if (!$id) sendJson(['error' => 'ID obrigatorio'], 400);

    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (string) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendJson(['error' => 'Lancamento nao encontrado'], 404);

    $dt = DateTime::createFromFormat('Y-m-d', (string) $row['date']) ?: new DateTime();
    $dt->modify('+1 month');

    $new = mapTxRow($row);
    $new['id'] = uniqid('tx_', true);
    $new['date'] = $dt->format('Y-m-d');

    $insert = $pdo->prepare('INSERT INTO transactions (id, date, description, payerName, beneficiaryName, amount, type, category, method, status, receiptStatus, payerCpf, beneficiaryCpf, observation, tags) VALUES (:id, :date, :description, :payerName, :beneficiaryName, :amount, :type, :category, :method, :status, :receiptStatus, :payerCpf, :beneficiaryCpf, :observation, :tags)');
    $insert->execute([
      'id' => $new['id'],
      'date' => $new['date'],
      'description' => $new['description'],
      'payerName' => $new['payerName'],
      'beneficiaryName' => $new['beneficiaryName'],
      'amount' => $new['amount'],
      'type' => $new['type'],
      'category' => $new['category'],
      'method' => $new['method'],
      'status' => $new['status'],
      'receiptStatus' => $new['receiptStatus'],
      'payerCpf' => $new['payerCpf'],
      'beneficiaryCpf' => $new['beneficiaryCpf'],
      'observation' => $new['observation'],
      'tags' => json_encode($new['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);

    sendJson(['ok' => true, 'id' => $new['id']], 201);
  }

  if ($path === 'transactions/receipt' && $method === 'POST') {
    if (!$id) sendJson(['error' => 'ID obrigatorio'], 400);

    $stmt = $pdo->prepare('UPDATE transactions SET receiptStatus = :receiptStatus WHERE id = :id AND userId = :userId');
    $stmt->execute(['receiptStatus' => 'ISSUED', 'id' => (string) $id, 'userId' => $user_id]);
    sendJson(['ok' => true]);
  }

  if ($path === 'transactions/import' && $method === 'POST') {
    $body = readJsonBody();
    $list = $body['transactions'] ?? [];
    if (!is_array($list)) sendJson(['error' => 'Formato invalido'], 400);

    $stmt = $pdo->prepare('INSERT OR REPLACE INTO transactions (id, date, description, payerName, beneficiaryName, amount, type, category, method, status, receiptStatus, payerCpf, beneficiaryCpf, observation, tags) VALUES (:id, :date, :description, :payerName, :beneficiaryName, :amount, :type, :category, :method, :status, :receiptStatus, :payerCpf, :beneficiaryCpf, :observation, :tags)');

    $count = 0;
    foreach ($list as $item) {
      if (!is_array($item)) continue;
      $payload = normalizeTxInput($item);
      $stmt->execute($payload);
      $count++;
    }

    sendJson(['ok' => true, 'imported' => $count], 201);
  }

  sendJson(['error' => 'Rota nao encontrada', 'path' => $path, 'method' => $method], 404);
} catch (Throwable $e) {
  sendJson(['error' => 'Erro interno', 'detail' => $e->getMessage()], 500);
}
