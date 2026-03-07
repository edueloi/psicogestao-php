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

/**
 * Consulta a API do Gemini 1.5 Flash
 */
function gemini_query($prompt) {
    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
        return "Configure sua API Key no config.php";
    }

    $url = GEMINI_API_URL;

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-goog-api-key: ' . GEMINI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Importante para HostGator se não tiver cert root
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return "Erro de Conexão (Rede): " . $err;
    }

    if ($httpCode !== 200) {
        // Se a cota acabar (429) ou erro 500
        return "A API do Gemini está momentaneamente indisponível ou sua cota gratuita acabou (Erro $httpCode).";
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? "O Gemini não conseguiu processar sua análise.";
}