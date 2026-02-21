<?php
require __DIR__ . '/db.php';

$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS transactions (
  id TEXT PRIMARY KEY,
  date TEXT NOT NULL,                 -- YYYY-MM-DD
  description TEXT NOT NULL,
  payerName TEXT NOT NULL,
  beneficiaryName TEXT NOT NULL,
  amount REAL NOT NULL,
  type TEXT NOT NULL,                 -- INCOME | EXPENSE
  category TEXT NOT NULL,
  method TEXT NOT NULL,               -- PIX | TRANSFER | CASH | CARD (você pode ampliar)
  status TEXT NOT NULL,               -- PAID | PENDING | OVERDUE
  receiptStatus TEXT,                 -- PENDING | ISSUED (apenas INCOME)
  payerCpf TEXT,
  beneficiaryCpf TEXT,
  observation TEXT,
  tags TEXT                           -- JSON array (string)
);
");

header('Content-Type: text/plain; charset=utf-8');
echo "OK: banco migrado/criado.\n";

