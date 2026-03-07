<?php
function db() {
  static $pdo = null;
  if ($pdo) return $pdo;

  // Em produção (HostGator),dirname(__DIR__) sobe um nível acima da pasta pública
  // No localhost, sobe um nível acima da pasta atual
  $path = dirname(__DIR__) . '/database_psicogestao.sqlite';
  
  // Fallback se não existir no nível superior (para o primeiro acesso local)
  if (!file_exists($path)) {
      $path = __DIR__ . '/database.sqlite';
  }

  $pdo = new PDO('sqlite:' . $path);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA foreign_keys = ON;");
  return $pdo;
}