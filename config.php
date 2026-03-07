<?php
// config.php - Configurações do Banco de Dados
// Configurações para HostGator (MySQL)

define('DB_TYPE', 'mysql'); 
define('DB_HOST', 'localhost');
define('DB_NAME', 'edua6062_psicogestao');
define('DB_USER', 'edua6062_karengomes');
define('DB_PASS', 'Bibia.0110');

// Caso use SQLite (desenvolvimento local)
define('DB_SQLITE_PATH', __DIR__ . '/database.sqlite');
