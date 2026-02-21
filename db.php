<?php
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $path = __DIR__ . '/database.sqlite';
  $pdo = new PDO('sqlite:' . $path);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA foreign_keys = ON;");
  return $pdo;
}