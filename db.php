<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    try {
        if (DB_TYPE === 'mysql') {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
        } else {
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
            $pdo->exec("PRAGMA foreign_keys = ON;");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Removi o fallback silencioso para SQLite em caso de erro no MySQL.
        // Assim, se o MySQL falhar na HostGator, veremos o erro real de conexão.
        header('Content-Type: text/plain; charset=utf-8');
        die("❌ Erro Crítico de Conexão: " . $e->getMessage() . "\n\nVerifique se o usuário do banco tem permissão de acesso e se a senha está correta no cPanel.");
    }
    
    return $pdo;
}