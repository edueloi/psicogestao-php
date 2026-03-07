<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';

$pdo = db();

if (DB_TYPE !== 'mysql') {
    die("Erro: DB_TYPE deve ser 'mysql' no config.php para rodar este script.");
}

echo "🛠️ Iniciando Migração para MySQL...\n\n";

// Criar tabela de usuários
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(255) PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  crp VARCHAR(100),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "✅ Tabela 'users' pronta.\n";

// Criar tabela de lançamentos
$pdo->exec("
CREATE TABLE IF NOT EXISTS transactions (
  internal_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  id VARCHAR(255) UNIQUE NOT NULL,
  userId VARCHAR(255) NOT NULL,
  date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  payerName VARCHAR(255) NOT NULL,
  beneficiaryName VARCHAR(255) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  type VARCHAR(20) NOT NULL,
  category VARCHAR(255) NOT NULL,
  method VARCHAR(100) NOT NULL,
  status VARCHAR(50) NOT NULL,
  receiptStatus VARCHAR(50),
  payerCpf VARCHAR(20),
  beneficiaryCpf VARCHAR(20),
  observation TEXT,
  tags TEXT,
  INDEX idx_user (userId),
  INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "✅ Tabela 'transactions' pronta.\n";

// Criar tabela de tipos de sessão
$pdo->exec("
CREATE TABLE IF NOT EXISTS session_types (
  id VARCHAR(255) PRIMARY KEY,
  userId VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  default_value DECIMAL(15,2) NOT NULL,
  INDEX idx_user (userId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "✅ Tabela 'session_types' pronta.\n";

// Criar tabela de histórico de chat da IA
$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_chat_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  userId VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  response TEXT NOT NULL,
  date DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (userId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "✅ Tabela 'ai_chat_history' pronta.\n";

// Inserir usuário padrão (Karen)
$pdo->exec("INSERT IGNORE INTO users (id, email, password, name, crp) VALUES ('user_karen', 'karen.l.s.gomes@gmail.com', 'Bibia.0110', 'Karen Gomes', 'CRP 06/172315')");
echo "✅ Usuário padrão inserido (se não existia).\n";

echo "\n🚀 Migração MySQL concluída com sucesso!";
