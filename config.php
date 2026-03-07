<?php
// config.php - Configurações Dinâmicas do Banco de Dados

// Detecta se está rodando no Localhost ou na Produção (HostGator)
$is_local = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) || $_SERVER['HTTP_HOST'] === 'localhost';

if ($is_local) {
    // --- CONFIGURAÇÃO LOCAL (XAMPP / SQLITE) ---
    define('DB_TYPE', 'sqlite');
    define('DB_SQLITE_PATH', __DIR__ . '/database.sqlite');
} else {
    // --- CONFIGURAÇÃO PRODUÇÃO (HOSTGATOR / MYSQL) ---
    define('DB_TYPE', 'mysql'); 
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'edua6062_psicogestao');
    define('DB_USER', 'edua6062_karengomes');
    define('DB_PASS', 'Bibia.0110');
}

// --- CONFIGURAÇÃO GEMINI AI ---
define('GEMINI_API_KEY', 'AIzaSyBcLHc0XMaKxOQ34tIhDeALe-PNEePb9D8');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');
