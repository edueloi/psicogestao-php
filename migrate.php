<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/db.php';

$pdo = db();

// Criar tabela de usuários
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  password TEXT NOT NULL,
  name TEXT NOT NULL,
  crp TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Criar tabelas originais se não existirem (com userId para novas instalações)
$pdo->exec("
CREATE TABLE IF NOT EXISTS transactions (
  id TEXT PRIMARY KEY,
  userId TEXT NOT NULL DEFAULT '',
  date TEXT NOT NULL,
  description TEXT NOT NULL,
  payerName TEXT NOT NULL,
  beneficiaryName TEXT NOT NULL,
  amount REAL NOT NULL,
  type TEXT NOT NULL,
  category TEXT NOT NULL,
  method TEXT NOT NULL,
  status TEXT NOT NULL,
  receiptStatus TEXT,
  payerCpf TEXT,
  beneficiaryCpf TEXT,
  observation TEXT,
  tags TEXT
);

CREATE TABLE IF NOT EXISTS session_types (
  id TEXT PRIMARY KEY,
  userId TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  default_value REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_chat_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  userId TEXT NOT NULL,
  message TEXT NOT NULL,
  response TEXT NOT NULL,
  date DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Adicionar colunas individualmente caso não existam (para atualizações)
try { @$pdo->exec("ALTER TABLE transactions ADD COLUMN userId TEXT DEFAULT 'user_karen'"); } catch(Exception $e) {}
try { @$pdo->exec("ALTER TABLE session_types ADD COLUMN userId TEXT DEFAULT 'user_karen'"); } catch(Exception $e) {}

// Inserir usuário padrão (Karen)
$pdo->exec("INSERT OR IGNORE INTO users (id, email, password, name, crp) VALUES ('user_karen', 'karen.l.s.gomes@gmail.com', 'Bibia.0110', 'Karen Gomes', 'CRP 06/172315')");

// Garantir que todos os dados tenham um userId
$pdo->exec("UPDATE transactions SET userId = 'user_karen' WHERE userId IS NULL OR userId = ''");
$pdo->exec("UPDATE session_types SET userId = 'user_karen' WHERE userId IS NULL OR userId = ''");

header('Content-Type: text/plain; charset=utf-8');
echo "OK: banco migrado/criado.\n";
