<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * PsicoGestão - Sistema Unificado em PHP
 * Versão Refatorada - 100% Server-Side
 */
require_once __DIR__ . '/db.php';
session_start();

$is_mysql = (DB_TYPE === 'mysql');

// 1. Segurança e Autenticação
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['psicogestao_auth']) || $_SESSION['psicogestao_auth'] !== true) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$user_id = $_SESSION['psicogestao_id'] ?? 'user_karen';
$user_email = $_SESSION['psicogestao_user'] ?? 'karen.l.s.gomes@gmail.com';
$user_name = $_SESSION['psicogestao_name'] ?? 'Usuário';

// Gerar iniciais dinâmicas
$name_parts = explode(' ', $user_name);
$user_initials = mb_substr($name_parts[0], 0, 1);
if (count($name_parts) > 1) {
    $user_initials .= mb_substr(end($name_parts), 0, 1);
}
$user_initials = mb_strtoupper($user_initials);

// Carregar Configurações de Sessão filtradas por usuário
$stmt = $pdo->prepare('SELECT * FROM session_types WHERE userId = ? ORDER BY name ASC');
$stmt->execute([$user_id]);
$session_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Configurações e Regras de Negócio
$TAB_SLUGS = [
    'dashboard' => 'Dashboard',
    'cashbook' => 'Livro Caixa',
    'pacientes' => 'Pacientes',
    'reports' => 'AI Insights',
    'provisions' => 'Fiscal',
    'settings' => 'Configurações',
];

$active_tab = $_GET['tab'] ?? 'dashboard';

$PROVISION_RULES = [
    ['name' => 'INSS Autônomo', 'percentage' => 20, 'base' => 'GROSS'],
    ['name' => 'IRPF Estimado', 'percentage' => 15, 'base' => 'NET'],
    ['name' => 'Reserva de Férias', 'percentage' => 8.33, 'base' => 'NET'],
    ['name' => 'Reserva 13º/Emergência', 'percentage' => 10, 'base' => 'NET']
];

// 4. Helpers e Funções de Cálculo
function fmtBRL($val) { return 'R$ ' . number_format($val, 2, ',', '.'); }
function fmtDateBR($iso) { return date('d/m/Y', strtotime($iso)); }

function calcTotals($list) {
    global $PROVISION_RULES;
    $income = 0; $expense = 0;
    foreach($list as $t) {
        if ($t['type'] === 'INCOME') $income += (float)$t['amount'];
        else $expense += (float)$t['amount'];
    }
    $net = $income - $expense;
    $provisions = [];
    foreach($PROVISION_RULES as $rule) {
        $base = ($rule['base'] === 'GROSS') ? $income : max(0, $net);
        $amount = $base * ($rule['percentage'] / 100);
        $provisions[] = ['name' => $rule['name'], 'amount' => $amount];
    }
    $total_prov = array_sum(array_column($provisions, 'amount'));
    return [
        'income' => $income,
        'expense' => $expense,
        'net' => $net,
        'liquid' => $net - $total_prov,
        'provisions' => $provisions,
        'total_prov' => $total_prov
    ];
}

// 5. Carregamento de Dados Base (Independente de Aba)
$sql_all = $is_mysql ? "SELECT * FROM transactions WHERE userId = ? ORDER BY date DESC, internal_id DESC" : "SELECT * FROM transactions WHERE userId = ? ORDER BY date DESC, rowid DESC";
$stmt_all = $pdo->prepare($sql_all);
$stmt_all->execute([$user_id]);
$all_tx = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

$totals_all = calcTotals($all_tx);

// Processamento de Pacientes (Necessário para a IA e Dash)
$pacientes = [];
foreach($all_tx as $tx) {
    if ($tx['type'] === 'INCOME' && !empty($tx['beneficiaryName'])) {
        $key = trim($tx['beneficiaryName']) . '|' . trim($tx['beneficiaryCpf']);
        if (!isset($pacientes[$key])) {
            $pacientes[$key] = [
                'nome' => trim($tx['beneficiaryName']),
                'cpf' => trim($tx['beneficiaryCpf']),
                'total_gasto' => 0,
                'ult_sessao' => $tx['date'],
                'sessões' => 0
            ];
        }
        $pacientes[$key]['total_gasto'] += (float)$tx['amount'];
        $pacientes[$key]['sessões']++;
        if (strtotime($tx['date']) > strtotime($pacientes[$key]['ult_sessao'])) {
            $pacientes[$key]['ult_sessao'] = $tx['date'];
        }
    }
}
$top_p_data = ['Nenhum', 0];
foreach($pacientes as $p) {
    if($p['total_gasto'] > $top_p_data[1]) $top_p_data = [$p['nome'], $p['total_gasto']];
}
$top_paciente = $top_p_data;

// 6. Processamento de Ações (POST)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Ação AJAX para o Chat IA
    if ($action === 'ask_ai') {
        header('Content-Type: application/json');
        $question = trim($_POST['question'] ?? '');
        if (empty($question)) {
            echo json_encode(['error' => 'Pergunta vazia']); exit;
        }

        // Tenta buscar histórico de chat
        $chat_history_text = '';
        try {
            $stmt_hist = $pdo->prepare('SELECT * FROM ai_chat_history WHERE userId = ? ORDER BY id DESC LIMIT 5');
            $stmt_hist->execute([$user_id]);
            $hist = array_reverse($stmt_hist->fetchAll(PDO::FETCH_ASSOC));
            if (!empty($hist)) {
                $chat_history_text = "HISTÓRICO RECENTE DE CONVERSAS:\n";
                foreach($hist as $h) {
                    $chat_history_text .= "Usuário: {$h['message']}\n";
                    $chat_history_text .= "Consultora: {$h['response']}\n\n";
                }
            }
        } catch(Exception $e) {
            // Ignorar se a tabela não foi criada ainda
        }

        // Contexto completo e dinâmico para o Gemini com as novas orientações de comportamento
        $ctx_prompt = "Você é uma Assistente de Consultor Financeiro de elite trabalhando no PsicoGestão para o(a) profissional {$user_name}. 
        INSTRUÇÕES DE COMPORTAMENTO:
        - Você deve ser sempre muito respeitosa, educada e com profissionalismo de alto nível.
        - Diminua MUITO a quantidade de informações na resposta: vá direto ao ponto, seja sempre excessivamente BREVE e OBJETIVA. Não se estenda.
        - Faça avaliações financeiras de alta qualidade voltadas ao sistema PsicoGestão.
        - Se o usuário falar sobre temas aleatórios (ex: 'Sou o presidente do Brasil'), informe com extrema educação que você é a Assistente de Consultoria Financeira e retorne o foco educadamente.
        - Se o usuário pedir ajuda sobre fontes de renda, planos, etc., dê sugestões incríveis ('tops') e você DEVE sugerir/encaminhar para sites confiáveis relacionados.
        - Caso o usuário peça, você pode e deve consultar as conversas anteriores inclusas no histórico abaixo.
        
        DADOS ATUAIS DE {$user_name}:
        - Faturamento Total (Histórico): R$ " . number_format($totals_all['income'], 2, ',', '.') . "
        - Gasto Total (Histórico): R$ " . number_format($totals_all['expense'], 2, ',', '.') . "
        - Saldo Líquido: R$ " . number_format($totals_all['liquid'], 2, ',', '.') . "
        - Total Pacientes Ativos: " . count($pacientes) . "
        - Melhor Paciente: " . $top_paciente[0] . "
        
        {$chat_history_text}
        
        PERGUNTA DE {$user_name}: \"{$question}\"";

        $answer = gemini_query($ctx_prompt);

        // Salvar log no banco de dados para construir histórico
        try {
            $stmt_ins = $pdo->prepare('INSERT INTO ai_chat_history (userId, message, response) VALUES (?, ?, ?)');
            $stmt_ins->execute([$user_id, $question, $answer]);
        } catch(Exception $e) {
            // Ignorar erro se tabela ainda não tiver sido criada
        }

        echo json_encode(['answer' => $answer, 'initials' => $user_initials]);
        exit;
    }

    if ($action === 'save') {
        $id = $_POST['id'] ?? uniqid('tx_', true);
        $type = ($_POST['type'] === 'EXPENSE') ? 'EXPENSE' : 'INCOME';
        $data = [
            'id' => $id,
            'userId' => $user_id,
            'date' => $_POST['date'] ?? date('Y-m-d'),
            'description' => trim($_POST['description'] ?? 'Sem descrição'),
            'payerName' => trim($_POST['payerName'] ?? ''),
            'beneficiaryName' => trim($_POST['beneficiaryName'] ?? ''),
            'amount' => (float) ($_POST['amount'] ?? 0),
            'type' => $type,
            'category' => $_POST['category'] ?? ($type === 'INCOME' ? 'Sessão Individual' : 'Geral'),
            'method' => $_POST['method'] ?? 'PIX',
            'status' => $_POST['status'] ?? 'PAID',
            'receiptStatus' => ($type === 'INCOME') ? ($_POST['receiptStatus'] ?? 'PENDING') : null,
            'payerCpf' => trim($_POST['payerCpf'] ?? ''),
            'beneficiaryCpf' => trim($_POST['beneficiaryCpf'] ?? ''),
            'observation' => trim($_POST['observation'] ?? ''),
            'tags' => json_encode($_POST['tags'] ?? [])
        ];

        if (!empty($_POST['is_edit'])) {
            $stmt = $pdo->prepare('UPDATE transactions SET date=:date, description=:description, payerName=:payerName, payerCpf=:payerCpf, beneficiaryName=:beneficiaryName, beneficiaryCpf=:beneficiaryCpf, amount=:amount, type=:type, category=:category, method=:method, status=:status, receiptStatus=:receiptStatus, observation=:observation, tags=:tags WHERE id=:id AND userId=:userId');
            $stmt->execute($data);
            $message = "✅ Lançamento atualizado!";
        } else {
            $stmt = $pdo->prepare('INSERT INTO transactions (id, userId, date, description, payerName, payerCpf, beneficiaryName, beneficiaryCpf, amount, type, category, method, status, receiptStatus, observation, tags) VALUES (:id, :userId, :date, :description, :payerName, :payerCpf, :beneficiaryName, :beneficiaryCpf, :amount, :type, :category, :method, :status, :receiptStatus, :observation, :tags)');
            $stmt->execute($data);
            $message = "✅ Novo lançamento gravado!";
        }
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $user_id]);
        $message = "🗑️ Lançamento excluído!";
    }

    if ($action === 'toggle-status') {
        $id = $_POST['id'] ?? '';
        $new_status = $_POST['new_status'] ?? 'PAID';
        $stmt = $pdo->prepare('UPDATE transactions SET status = ? WHERE id = ? AND userId = ?');
        $stmt->execute([$new_status, $id, $user_id]);
    }

    if ($action === 'repeat') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $dt = new DateTime($row['date']);
            $dt->modify('+1 month');
            $row['id'] = uniqid('tx_', true);
            $row['date'] = $dt->format('Y-m-d');
            $row['status'] = 'PENDING';
            $row['receiptStatus'] = ($row['type'] === 'INCOME') ? 'PENDING' : null;
            
            $keys = array_keys($row);
            $cols = implode(',', $keys);
            $placeholders = implode(',', array_map(function($k) { return ":$k"; }, $keys));
            $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($placeholders)");
            $stmt->execute($row);
            $message = "🔁 Lançamento repetido para " . $dt->format('d/m/Y');
        }
    }

    if ($action === 'save_session_type') {
        $id = $_POST['id'] ?: uniqid('st_', true);
        $name = trim($_POST['name']);
        $value = (float)str_replace(',', '.', $_POST['default_value']);
        
        $stmt = $pdo->prepare('REPLACE INTO session_types (id, userId, name, default_value) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $user_id, $name, $value]);
        $_SESSION['message'] = "⚙️ Tipo de sessão '$name' salvo!";
        header('Location: index.php?tab=settings'); exit;
    }

    if ($action === 'save_user') {
        $id = $_POST['id'] ?: uniqid('user_', true);
        $email = trim($_POST['email']);
        $name = trim($_POST['name']);
        $pass = trim($_POST['password']);
        $crp = trim($_POST['crp']);
        
        $stmt = $pdo->prepare('REPLACE INTO users (id, email, password, name, crp) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $email, $pass, $name, $crp]);
        $_SESSION['message'] = "👤 Usuário '$name' salvo!";
        header('Location: index.php?tab=settings'); exit;
    }

    if ($action === 'delete_user') {
        $id = $_POST['id'];
        // Apenas o usuário Karen ou o próprio usuário (com restrições) poderiam deletar, 
        // mas por enquanto vamos restringir para que um usuário não delete outros se não for admin.
        // Como não temos ROLE, vamos permitir apenas que não delete a si mesmo por engano aqui.
        if ($id === $user_id) {
            $_SESSION['message'] = "❌ Você não pode excluir a si mesmo!";
        } else {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $_SESSION['message'] = "🗑️ Usuário removido!";
        }
        header('Location: index.php?tab=settings'); exit;
    }

    if ($action === 'delete_session_type') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare('DELETE FROM session_types WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $user_id]);
        $_SESSION['message'] = "🗑️ Tipo de sessão removido!";
        header('Location: index.php?tab=settings'); exit;
    }

    if ($action === 'receipt') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('UPDATE transactions SET receiptStatus = "ISSUED" WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $user_id]);
        $message = "🧾 Recibo emitido com sucesso!";
    }

    if ($action === 'import') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($handle); // Pular cabeçalho
            $count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) continue;
                $date = $row[0];
                $desc = $row[1];
                $amount = (float)str_replace(',', '.', $row[2]);
                $type = (strtoupper($row[3]) === 'RECEITA') ? 'INCOME' : 'EXPENSE';
                
                $id = uniqid('tx_', true);
                $stmt = $pdo->prepare('INSERT INTO transactions (id, userId, date, description, amount, type, status, category, method) VALUES (?, ?, ?, ?, ?, ?, "PAID", "Importado", "Outros")');
                $stmt->execute([$id, $user_id, $date, $desc, $amount, $type]);
                $count++;
            }
            fclose($handle);
            $_SESSION['message'] = "📥 $count lançamentos importados!";
            header("Location: index.php?tab=cashbook"); exit;
        }
    }

    if ($action === 'import_paste') {
        $text = $_POST['paste_data'] ?? '';
        $lines = explode("\n", trim($text));
        $count = 0;
        
        // Helper para limpar valores monetários
        $cleanMoney = function($val) {
            $val = trim(str_replace(['R$', ' '], ['', ''], ($val ?? '')));
            if (!$val) return 0;
            
            // Se houver vírgula, tratamos como padrão BR (1.234,56)
            if (strpos($val, ',') !== false) {
                $val = str_replace('.', '', $val);   // Remove pontos de milhar
                $val = str_replace(',', '.', $val);   // Troca a vírgula decimal por ponto
            }
            // Se NÃO houver vírgula, mas houver ponto (ex: 360.00), o PHP já entende como float.
            // Se houver múltiplos pontos e nenhuma vírgula (ex: 1.000.000), o float() vai pegar só o primeiro.
            // Mas no seu caso (R$ 360.00), apenas removemos o R$ e o float faz o resto.

            return (float)$val;
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, 'Data') === 0) continue;
            
            $cols = array_map('trim', explode("\t", $line));
            if (count($cols) < 5) continue;
            
            // 0. Data (DD/MM/YYYY)
            $date_raw = $cols[0];
            $date_parts = explode('/', $date_raw);
            if (count($date_parts) === 3) {
                $date = "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}";
            } else {
                continue; 
            }

            // 1. Método/Formato
            $method = $cols[1] ?: 'PIX';
            
            // 2. Pagador
            $payer = $cols[2] ?: '';
            
            // 3. CPF Pagador
            $payerCpf = $cols[3] ?: '';

            // 4 & 6. Valores (Entrada / Saída)
            $income_val = $cleanMoney($cols[4] ?? '');
            $expense_val = $cleanMoney($cols[6] ?? '');
            
            $amount = $income_val > 0 ? $income_val : $expense_val;
            $type = $income_val > 0 ? 'INCOME' : ($expense_val > 0 ? 'EXPENSE' : 'INCOME');

            // 8. Observação e Beneficiário
            $obs_base = $cols[8] ?? '';
            $extra_ent = $cols[5] ?? '';
            $extra_saude = $cols[7] ?? '';
            
            $full_obs = $obs_base;
            if ($extra_ent) $full_obs .= ($full_obs ? " | " : "") . "Entradas/mês: " . $extra_ent;
            if ($extra_saude) $full_obs .= ($full_obs ? " | " : "") . "Saúde: " . $extra_saude;

            // Tentar extrair beneficiário da observação
            $beneficiary = ''; $beneficiaryCpf = '';
            if (preg_match('/Beneficiario:\s*([^0-9C|]+)(?:CPF\s*([\d.-]+))?/i', $obs_base, $matches)) {
                $beneficiary = trim($matches[1]);
                if (isset($matches[2])) $beneficiaryCpf = trim($matches[2]);
            }

            // Se for receita e o beneficiário estiver vazio, assume que o pagador é o beneficiário
            if ($type === 'INCOME') {
                if (!$beneficiary) $beneficiary = $payer;
                if (!$beneficiaryCpf) $beneficiaryCpf = $payerCpf;
            }

            // Lógica de Categorização Inteligente baseada no Valor (usando as Configurações)
            $category = ($type === 'INCOME') ? 'Geral' : 'Despesa Geral';
            if ($type === 'INCOME' && !empty($session_types)) {
                foreach ($session_types as $st) {
                    // Se o valor bater exatamente ou for muito próximo (0.01 de diferença)
                    if (abs($amount - $st['default_value']) < 0.01) {
                        $category = $st['name'];
                        break;
                    }
                }
                
                // Fallback se não encontrar correspondência exata mas for um valor comum
                if ($category === 'Geral') {
                    if ($amount >= 360) $category = 'Pacote Semanal';
                    else if ($amount >= 200) $category = 'Sessão Individual';
                }
            }

            // Descrição Padrão baseada na categoria
            $description = $category;
            if ($obs_base && strlen($obs_base) < 60) $description = $obs_base;

            $id = uniqid('tx_', true);
            $stmt = $pdo->prepare('INSERT INTO transactions (id, userId, date, description, payerName, payerCpf, beneficiaryName, beneficiaryCpf, amount, type, status, category, method, observation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PAID", ?, ?, ?)');
            $stmt->execute([
                $id, 
                $user_id,
                $date, 
                $description, 
                $payer, 
                $payerCpf, 
                $beneficiary, 
                $beneficiaryCpf, 
                $amount, 
                $type, 
                $category,
                $method, 
                $full_obs
            ]);
            $count++;
        }
        $_SESSION['message'] = "📥 $count lançamentos processados e organizados!";
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'clear_all') {
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE userId = ?');
        $stmt->execute([$user_id]);
        $_SESSION['message'] = "🗑️ Seus lançamentos foram resetados!";
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'close_month') {
        $_SESSION['message'] = "✅ Período encerrado e consolidado com sucesso!";
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'delete_month') {
        $m = $_POST['month_val'] ?? ''; // YYYY-MM
        if ($m) {
            $sql_del = $is_mysql 
                ? "DELETE FROM transactions WHERE DATE_FORMAT(date, '%Y-%m') = ? AND userId = ?"
                : "DELETE FROM transactions WHERE strftime('%Y-%m', date) = ? AND userId = ?";
            $stmt = $pdo->prepare($sql_del);
            $stmt->execute([$m, $user_id]);
            $_SESSION['message'] = "🗑️ Todos os lançamentos de " . getMonthName($m) . " foram excluídos!";
        }
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'duplicate_month') {
        $source_m = $_POST['source_month'] ?? ''; // YYYY-MM
        $target_m = $_POST['target_month'] ?? ''; // YYYY-MM
        
        if ($source_m && $target_m) {
            $sql_dup = $is_mysql 
                ? 'SELECT * FROM transactions WHERE DATE_FORMAT(date, "%Y-%m") = ?' 
                : 'SELECT * FROM transactions WHERE strftime("%Y-%m", date) = ?';
            $stmt = $pdo->prepare($sql_dup);
            $stmt->execute([$source_m]);
            $to_dup = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $count = 0;
            
            // Calculate day difference or just target same day in new month
            foreach($to_dup as $row) {
                $old_dt = new DateTime($row['date']);
                $day = $old_dt->format('d');
                
                // Create new date: Target Year-Month + Original Day
                // Handle months with fewer days (e.g., Feb 30th)
                $new_date_str = $target_m . '-' . $day;
                $new_dt = new DateTime($new_date_str);
                
                // Safety check for month overflow (e.g., Feb 31 -> Mar 02)
                if ($new_dt->format('Y-m') !== $target_m) {
                    $new_dt = new DateTime($target_m . '-01');
                    $new_dt->modify('last day of this month');
                }

                $row['id'] = uniqid('tx_', true);
                $row['date'] = $new_dt->format('Y-m-d');
                $row['status'] = 'PENDING';
                $row['receiptStatus'] = ($row['type'] === 'INCOME') ? 'PENDING' : null;
                
                $keys = array_keys($row);
                foreach($keys as $ki => $kv) if(is_numeric($kv)) unset($keys[$ki]);

                $clean_row = [];
                foreach($keys as $key) $clean_row[$key] = $row[$key];

                $cols = implode(',', $keys);
                $placeholders = implode(',', array_map(function($k) { return ":$k"; }, $keys));
                $stmt_ins = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($placeholders)");
                $stmt_ins->execute($clean_row);
                $count++;
            }
            $_SESSION['message'] = "🔁 $count lançamentos duplicados de " . getMonthName($source_m) . " para " . getMonthName($target_m);
        }
        header("Location: index.php?tab=cashbook&month=$target_m"); exit;
    }
}

// 7. Filtros e Estados de Interface (GET)
$active_tab = $_GET['tab'] ?? 'dashboard';
$filter_year = $_GET['year'] ?? date('Y');
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_type = $_GET['type'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// Filtros de Dashboard específicos
$dash_month = $_GET['dash_month'] ?? date('m');
$dash_year = $_GET['dash_year'] ?? date('Y');

// Filtros específicos da aba Pacientes
$p_month = $_GET['p_month'] ?? 'all';
$p_year = $_GET['p_year'] ?? 'all';
$p_status = $_GET['p_status'] ?? 'all';
$p_search = $_GET['p_search'] ?? '';
$p_sort = $_GET['p_sort'] ?? 'nome';

// 8. Cálculos Específicos por Contexto
// Dashboard Totals (Filtrado por Mês/Ano do Dash)
$where_dash = []; $params_dash = [];
if ($dash_month !== 'all') {
    if ($is_mysql) {
        $where_dash[] = "MONTH(date) = ? AND YEAR(date) = ?";
    } else {
        $where_dash[] = "strftime('%m', date) = ? AND strftime('%Y', date) = ?";
    }
    $params_dash[] = str_pad($dash_month, 2, '0', STR_PAD_LEFT);
    $params_dash[] = $dash_year;
} else {
    $where_dash[] = ($is_mysql ? "YEAR(date) = ?" : "strftime('%Y', date) = ?");
    $params_dash[] = $dash_year;
}
$sql_dash = "SELECT * FROM transactions WHERE userId = ?";
if ($where_dash) $sql_dash .= " AND " . implode(" AND ", $where_dash);
$stmt_dash = $pdo->prepare($sql_dash);
$stmt_dash->execute(array_merge([$user_id], $params_dash));
$totals_dashboard = calcTotals($stmt_dash->fetchAll(PDO::FETCH_ASSOC));

// Comparativo de Crescimento (Dashboard)
$prev_month_ts = strtotime(($dash_month === 'all' ? $dash_year : "$dash_year-$dash_month-01") . " -1 month");
$pm = date('m', $prev_month_ts);
$py = date('Y', $prev_month_ts);
$sql_prev = $is_mysql 
    ? "SELECT * FROM transactions WHERE MONTH(date) = ? AND YEAR(date) = ? AND userId = ?"
    : "SELECT * FROM transactions WHERE strftime('%m', date) = ? AND strftime('%Y', date) = ? AND userId = ?";
$stmt_prev = $pdo->prepare($sql_prev);
$stmt_prev->execute([$pm, $py, $user_id]);
$totals_prev = calcTotals($stmt_prev->fetchAll(PDO::FETCH_ASSOC));

// Filtragem para o Livro Caixa
$where = []; $params = [];
if ($filter_month !== 'all' && $filter_month !== 'archive') {
    $where[] = $is_mysql ? "DATE_FORMAT(date, '%Y-%m') = ?" : "strftime('%Y-%m', date) = ?";
    $params[] = $filter_month;
}
if ($filter_type !== 'all') {
    $where[] = "type = ?";
    $params[] = $filter_type;
}
if ($filter_search !== '') {
    $where[] = "(description LIKE ? OR payerName LIKE ? OR beneficiaryName LIKE ?)";
    $like = "%$filter_search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql_filtered = "SELECT * FROM transactions WHERE userId = ?";
if ($where) $sql_filtered .= " AND " . implode(" AND ", $where);
$sql_filtered .= ($is_mysql ? " ORDER BY date DESC, internal_id DESC" : " ORDER BY date DESC, rowid DESC");
$stmt_f = $pdo->prepare($sql_filtered);
$stmt_f->execute(array_merge([$user_id], $params));
$filtered_tx = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

$totals_filtered = calcTotals($filtered_tx);

// Agrupamento para View Archive
$sql_years = $is_mysql ? 'SELECT DISTINCT YEAR(date) as y FROM transactions WHERE userId = ? ORDER BY y DESC' : 'SELECT DISTINCT strftime("%Y", date) as y FROM transactions WHERE userId = ? ORDER BY y DESC';
$stmt_y = $pdo->prepare($sql_years);
$stmt_y->execute([$user_id]);
$available_years = $stmt_y->fetchAll(PDO::FETCH_COLUMN);
if(empty($available_years)) $available_years[] = date('Y');

$sql_months = $is_mysql 
    ? 'SELECT DISTINCT DATE_FORMAT(date, "%Y-%m") as month FROM transactions WHERE YEAR(date) = ? AND userId = ? ORDER BY month DESC'
    : 'SELECT DISTINCT strftime("%Y-%m", date) as month FROM transactions WHERE strftime("%Y", date) = ? AND userId = ? ORDER BY month DESC';
$stmt_m = $pdo->prepare($sql_months);
$stmt_m->execute([$filter_year, $user_id]);
$available_months = $stmt_m->fetchAll(PDO::FETCH_COLUMN);

$monthly_summaries = [];
foreach($available_months as $m) {
    $sql_sum = $is_mysql ? 'SELECT * FROM transactions WHERE DATE_FORMAT(date, "%Y-%m") = ? AND userId = ?' : 'SELECT * FROM transactions WHERE strftime("%Y-%m", date) = ? AND userId = ?';
    $stmt_s = $pdo->prepare($sql_sum);
    $stmt_s->execute([$m, $user_id]);
    $monthly_summaries[$m] = calcTotals($stmt_s->fetchAll(PDO::FETCH_ASSOC));
}

// Iniciais e Helpers Finais
$mon_names = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
function getMonthName($m) {
    global $mon_names;
    if ($m === 'all' || $m === 'archive') return 'Todos os Lançamentos';
    if ($m === 'current') return 'Mês Atual (' . date('m/Y') . ')';
    if ($m === 'last') return 'Mês Anterior (' . date('m/Y', strtotime('-1 month')) . ')';
    
    $pts = explode('-', $m);
    if (count($pts) >= 2) {
        $idx = (int)$pts[1] - 1;
        $name = (isset($mon_names[$idx])) ? $mon_names[$idx] : 'Mês ' . $pts[1];
        return $name . ' ' . $pts[0];
    }
    return $m;
}

$view_mode = $_GET['month'] ?? 'archive'; // 'archive' ou YYYY-MM
if ($view_mode !== 'archive' && $view_mode !== 'all' && $view_mode !== 'current' && $view_mode !== 'last') {
    $filter_month_val = $view_mode;
} else {
    $filter_month_val = 'archive';
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PsicoGestão - <?= $user_name ?></title>
    <link rel="icon" type="image/png" href="favicon_psicogestao_logo_1772840774779.png">
    <!-- Tailwind CSS & Icon Library -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        .sidebar-item-active { background: #4f46e5; color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.2); }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .animate-fade-in { animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        @media (max-width: 1024px) {
            .sidebar-closed { transform: translateX(-100%); }
            .sidebar-open { transform: translateX(0); }
        }
    </style>
</head>
<body class="text-slate-900 overflow-x-hidden">
    
    <div class="flex h-screen overflow-hidden relative">
        
        <!-- Sidebar -->
        <aside id="mainSidebar" class="w-72 bg-white border-r border-slate-200 flex flex-col shrink-0 z-[60] fixed lg:relative h-full transition-transform duration-300 sidebar-closed lg:translate-x-0">
            <button onclick="toggleSidebar()" class="lg:hidden absolute top-6 right-[-50px] w-10 h-10 bg-indigo-600 text-white rounded-xl shadow-xl flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="p-8 border-b border-slate-100 flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white text-xl shadow-lg ring-4 ring-indigo-50">Ψ</div>
                <div>
                    <h1 class="font-extrabold text-lg leading-none tracking-tight">Psico<span class="text-indigo-600">Gestão</span></h1>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-1"><?= $user_name ?> • <?= $_SESSION['psicogestao_crp'] ?? '' ?></p>
                </div>
            </div>

            <nav class="flex-1 p-6 space-y-2 overflow-y-auto scrollbar-hide">
                <p class="px-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">Módulos</p>
                
                <a href="?tab=dashboard" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='dashboard' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="w-5 text-center relative z-10"><i class="fa-solid fa-chart-line"></i></span>
                    <span class="text-[13px] font-bold relative z-10">Dashboard</span>
                    <?php if($active_tab=='dashboard'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <a href="?tab=cashbook" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='cashbook' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="w-5 text-center relative z-10"><i class="fa-solid fa-book-medical"></i></span>
                    <span class="text-[13px] font-bold relative z-10">Livro Caixa</span>
                    <?php if($active_tab=='cashbook'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <a href="?tab=pacientes" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='pacientes' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="w-5 text-center relative z-10"><i class="fa-solid fa-user-group"></i></span>
                    <span class="text-[13px] font-bold relative z-10">Pacientes</span>
                    <?php if($active_tab=='pacientes'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <a href="?tab=reports" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='reports' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="w-5 text-center relative z-10"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                    <span class="text-[13px] font-bold relative z-10">AI Insights</span>
                    <?php if($active_tab=='reports'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <div class="pt-6 mt-4 border-t border-slate-50">
                    <p class="px-4 text-[9px] font-black text-slate-300 uppercase tracking-[0.2em] mb-3">Administração</p>
                    <a href="?tab=provisions" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='provisions' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50' ?>">
                        <span class="w-5 text-center relative z-10"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                        <span class="text-[13px] font-bold relative z-10">Fiscal & Tributário</span>
                        <?php if($active_tab=='provisions'): ?> <div class="absolute inset-0 bg-gradient-to-r from-slate-800 to-slate-900 opacity-100"></div> <?php endif; ?>
                    </a>
                    <a href="?tab=settings" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='settings' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50' ?>">
                        <span class="w-5 text-center relative z-10"><i class="fa-solid fa-gear"></i></span>
                        <span class="text-[13px] font-bold relative z-10">Configurações</span>
                        <?php if($active_tab=='settings'): ?> <div class="absolute inset-0 bg-gradient-to-r from-slate-800 to-slate-900 opacity-100"></div> <?php endif; ?>
                    </a>
                </div>

                <div class="pt-6 mt-4 border-t border-slate-50">
                    <p class="px-4 text-[9px] font-black text-slate-300 uppercase tracking-[0.2em] mb-3">Links Externos</p>
                    <a href="https://karengomes.com.br/" target="_blank" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all text-slate-500 hover:bg-slate-50 hover:text-indigo-600">
                        <span class="w-5 text-center"><i class="fa-solid fa-globe"></i></span>
                        <span class="text-[13px] font-bold">Site Profissional</span>
                    </a>
                    <a href="https://melodias.karengomes.com.br/" target="_blank" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all text-slate-500 hover:bg-slate-50 hover:text-indigo-600">
                        <span class="w-5 text-center"><i class="fa-solid fa-music"></i></span>
                        <span class="text-[13px] font-bold">Rede Melodias</span>
                    </a>
                    <a href="https://www.instagram.com/psi.karengomes" target="_blank" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all text-slate-500 hover:bg-slate-50 hover:text-pink-600">
                        <span class="w-5 text-center"><i class="fa-brands fa-instagram"></i></span>
                        <span class="text-[13px] font-bold">Instagram</span>
                    </a>
                </div>
            </nav>

            <div class="p-6 border-t border-slate-100">
                <?php 
                $sidebar_status_label = 'Status Geral';
                if ($active_tab === 'dashboard' && $dash_month !== 'all') {
                    $m_idx = (int)$dash_month - 1;
                    $m_name = (isset($mon_names[$m_idx])) ? $mon_names[$m_idx] : $dash_month;
                    $sidebar_status_label = 'Status de ' . $m_name;
                } elseif ($active_tab === 'cashbook' && preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
                    $sidebar_status_label = 'Status de ' . getMonthName($filter_month);
                } elseif ($active_tab === 'cashbook' && ($filter_month === 'current' || $view_mode === 'current')) {
                    $sidebar_status_label = 'Status do Mês';
                }
                
                $liquid_val = $totals_dashboard['liquid'];
                $progress_pct = min(100, max(0, ($liquid_val / 10000) * 100)); // Usando 10k como meta base exemplo
                ?>
                <div class="bg-indigo-600 rounded-3xl p-5 text-white shadow-xl relative overflow-hidden group">
                    <div class="relative z-10">
                        <p class="text-[9px] font-black text-indigo-200 uppercase tracking-widest mb-1"><?= $sidebar_status_label ?></p>
                        <p class="text-sm font-bold"><?= fmtBRL($liquid_val) ?> Líquido</p>
                        <div class="w-full bg-white/20 h-1.5 rounded-full mt-3 overflow-hidden">
                            <div class="bg-white h-full transition-all duration-1000" style="width: <?= $progress_pct ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col min-w-0">
            
            <!-- Top Bar -->
            <header class="h-20 bg-white/80 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-10 sticky top-0 z-40">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-500">
                        <i class="fa-solid fa-bars-staggered"></i>
                    </button>
                    <h2 class="text-lg font-extrabold text-slate-800 tracking-tight"><?= $TAB_SLUGS[$active_tab] ?></h2>
                </div>

                <div class="flex items-center gap-6">
                    <div class="h-6 w-px bg-slate-200"></div>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <button onclick="toggleProfileMenu(event)" class="flex items-center gap-3 px-3 py-2 rounded-2xl hover:bg-slate-50 transition-all group">
                                <div class="text-right hidden sm:block">
                                    <p class="text-[11px] font-black text-slate-900 leading-none"><?= htmlspecialchars($user_name) ?></p>
                                    <p class="text-[9px] text-emerald-500 font-bold uppercase mt-1 tracking-widest text-right">Acesso Profissional</p>
                                </div>
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($user_name) ?>&background=4f46e5&color=fff" class="w-9 h-9 rounded-xl ring-2 ring-indigo-50 group-hover:ring-indigo-100 transition-all shadow-sm">
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div id="profileDropdown" class="absolute right-0 mt-2 w-56 bg-white rounded-[2rem] shadow-2xl border border-slate-100 py-3 hidden z-50 animate-fade-in origin-top-right">
                                <div class="px-6 py-4 border-b border-slate-50">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Usuário logado</p>
                                    <p class="text-[9px] font-bold text-slate-800 truncate"><?= htmlspecialchars($user_email) ?></p>
                                </div>
                                
                                <div class="p-2">
                                    <a href="?logout=1" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[11px] font-black text-rose-500 hover:bg-rose-50 transition-all uppercase tracking-widest">
                                        <i class="fa-solid fa-right-from-bracket"></i> Sair do Sistema
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Container -->
            <div class="flex-1 overflow-y-auto p-4 lg:p-8 animate-fade-in custom-scrollbar">
                
                <?php if ($active_tab === 'dashboard'): ?>
                    <div class="max-w-7xl mx-auto space-y-8 pb-12">
                        
                        <!-- Dashboard Header & Filters -->
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-4">
                            <div>
                                <h3 class="text-3xl font-black text-slate-900 tracking-tight">Financial Overview</h3>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">Análise inteligente de performance</p>
                            </div>
                            
                            <form method="GET" class="flex items-center bg-white p-2 rounded-[2rem] shadow-xl border border-slate-100 gap-2">
                                <input type="hidden" name="tab" value="dashboard">
                                <select name="dash_month" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-[10px] font-black uppercase tracking-widest outline-none cursor-pointer hover:bg-slate-100 transition-all">
                                    <option value="all" <?= $dash_month == 'all' ? 'selected' : '' ?>>Todos os Meses</option>
                                    <?php 
                                    $mon_short = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
                                    foreach($mon_short as $idx => $name): ?>
                                        <option value="<?= $idx+1 ?>" <?= (int)$dash_month == $idx+1 ? 'selected' : '' ?>><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="dash_year" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-[10px] font-black uppercase tracking-widest outline-none cursor-pointer hover:bg-slate-100 transition-all">
                                    <?php foreach($available_years as $y): ?>
                                        <option value="<?= $y ?>" <?= $dash_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="w-10 h-10 bg-indigo-600 text-white rounded-xl flex items-center justify-center shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all"><i class="fa-solid fa-magnifying-glass"></i></button>
                            </form>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                            <?php 
                            $diff = $totals_dashboard['liquid'] - $totals_prev['liquid'];
                            $diff_pct = $totals_prev['liquid'] > 0 ? ($diff / $totals_prev['liquid']) * 100 : 0;

                            $cards = [
                                ['Faturamento Bruto', $totals_dashboard['income'], 'Entradas', 'indigo', 'fa-solid fa-bullseye'],
                                ['Despesas Operacionais', $totals_dashboard['expense'], 'Saídas', 'rose', 'fa-solid fa-chart-line-down'],
                                ['Provisões Fiscais', $totals_dashboard['total_prov'], 'Reservas', 'amber', 'fa-solid fa-scale-balanced'],
                                ['Lucro Líquido Real', $totals_dashboard['liquid'], 'Disponível', 'emerald', 'fa-solid fa-gem', true]
                            ];
                            foreach($cards as $c): ?>
                                <div class="p-8 rounded-[3rem] border shadow-sm relative overflow-hidden group hover:-translate-y-1 transition-all <?= $c[5] ?? false ? 'bg-slate-900 text-white shadow-2xl scale-[1.05] z-10' : 'bg-white text-slate-900 border-slate-100' ?>">
                                    <div class="flex justify-between items-start mb-6">
                                        <div class="w-12 h-12 rounded-2xl <?= $c[5] ?? false ? 'bg-white/10' : 'bg-slate-50 text-slate-400' ?> flex items-center justify-center text-xl">
                                            <i class="<?= $c[4] ?>"></i>
                                        </div>
                                        <p class="text-[10px] font-black uppercase tracking-widest opacity-40"><?= $c[2] ?></p>
                                    </div>
                                    <p class="text-[11px] font-black uppercase tracking-[0.2em] mb-2 opacity-60"><?= $c[0] ?></p>
                                    <h3 class="text-3xl font-black tracking-tighter mb-4"><?= fmtBRL($c[1]) ?></h3>
                                    
                                    <?php if($c[5] ?? false): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-black px-3 py-1 rounded-full <?= $diff >= 0 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
                                                <?= $diff >= 0 ? '↑' : '↓' ?> <?= number_format(abs($diff_pct), 1) ?>%
                                            </span>
                                            <span class="text-[9px] font-bold text-white/30 uppercase tracking-widest">vs mês ant.</span>
                                        </div>
                                        <div class="absolute -right-8 -bottom-8 w-32 h-32 bg-indigo-500/10 rounded-full blur-3xl"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            <div class="lg:col-span-2 bg-white rounded-[3rem] border border-slate-100 p-10 shadow-sm relative overflow-hidden">
                                <div class="relative z-10">
                                    <div class="flex justify-between items-center mb-10">
                                        <h3 class="text-xl font-black text-slate-900 flex items-center gap-3">
                                            <i class="fa-solid fa-chart-column text-indigo-500"></i> Performance
                                        </h3>
                                        <span class="text-[10px] font-black text-indigo-500 bg-indigo-50 px-4 py-2 rounded-full uppercase tracking-widest">Realizado em <?= $dash_year ?></span>
                                    </div>
                                    <div class="h-80 flex flex-col items-center justify-center border-2 border-dashed border-slate-50 rounded-[2.5rem] text-slate-300 italic group hover:border-indigo-100 transition-all cursor-pointer">
                                        <p class="text-3xl mb-4 group-hover:scale-125 transition-transform duration-500">
                                            <i class="fa-solid fa-arrow-trend-up"></i>
                                        </p>
                                        <p class="font-black text-[12px] uppercase tracking-widest group-hover:text-indigo-600 transition-all">Análise Gráfica em Breve</p>
                                        <p class="text-[10px] mt-2 opacity-60">Insights profundos e projeções em tempo real</p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-[3rem] border border-slate-100 p-10 shadow-sm relative overflow-hidden">
                                <h3 class="text-xl font-black text-slate-900 mb-8 flex items-center gap-3">
                                    <i class="fa-solid fa-scale-balanced text-amber-500"></i> Fiscal & Prov
                                </h3>
                                <div class="space-y-4">
                                    <?php foreach($totals_dashboard['provisions'] as $p): ?>
                                        <div class="p-6 bg-slate-50 border border-slate-100 rounded-[2rem] flex justify-between items-center group hover:bg-white hover:shadow-xl hover:border-indigo-50 transition-all">
                                            <div>
                                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-tighter mb-1"><?= $p['name'] ?></p>
                                                <p class="text-xl font-black text-slate-900"><?= fmtBRL($p['amount']) ?></p>
                                            </div>
                                            <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-all shadow-sm">💰</div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="mt-8 pt-6 border-t border-slate-50 text-center">
                                        <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.2em] mb-4">Meta Mensal</p>
                                        <div class="w-full bg-slate-50 h-3 rounded-full overflow-hidden mb-2">
                                            <div class="bg-indigo-600 h-full" style="width: 75%"></div>
                                        </div>
                                        <div class="flex justify-between text-[10px] font-black text-slate-400">
                                            <span>75% ALCANÇADO</span>
                                            <span class="text-indigo-600">FALTA R$ 2.400</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($active_tab === 'cashbook'): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <?php if ($view_mode === 'archive'): ?>
                            <!-- Mês Archive View (Mosaico de Meses) -->
                            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                                <div>
                                    <h3 class="text-3xl font-black text-slate-900 tracking-tight">Arquivo Financeiro</h3>
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">Gestão de períodos consolidados</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="GET" class="flex bg-white p-1 rounded-2xl shadow-sm border border-slate-200">
                                        <input type="hidden" name="tab" value="cashbook">
                                        <?php foreach($available_years as $y): ?>
                                            <button type="submit" name="year" value="<?= $y ?>" class="px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all <?= $filter_year == $y ? 'bg-slate-900 text-white shadow-lg' : 'text-slate-400 hover:text-slate-600' ?>">
                                                <?= $y ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </form>
                                    <div class="h-6 w-px bg-slate-200 mx-2"></div>
                                    <button onclick="openModal('importModal')" class="h-11 w-11 flex items-center justify-center bg-indigo-50 text-indigo-600 rounded-2xl border border-indigo-100 hover:bg-indigo-100 transition-all text-lg shadow-sm" title="Importar XLS/CSV">📥</button>
                                    <button onclick="openModal('txModal')" class="h-11 px-6 bg-indigo-600 text-white rounded-2xl text-[12px] font-black uppercase tracking-widest shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition-all flex items-center gap-2">
                                        <span>+</span> Novo Lançamento
                                    </button>
                                </div>
                            </div>

                            <?php if (empty($monthly_summaries)): ?>
                                <div class="bg-white rounded-[3rem] border border-slate-100 p-20 text-center shadow-sm">
                                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-3xl">🗂️</div>
                                    <h4 class="text-xl font-black text-slate-900 mb-2">Nenhum registro em <?= $filter_year ?></h4>
                                    <p class="text-sm text-slate-400 font-medium mb-8">Inicie o ano importando dados ou criando um novo lançamento.</p>
                                    <button onclick="openModal('txModal')" class="px-8 py-3 bg-indigo-600 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest shadow-lg shadow-indigo-100">Criar Primeiro Lançamento</button>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                                    <?php foreach($monthly_summaries as $m => $summary): ?>
                                        <div class="bg-white rounded-[3rem] border border-slate-200 p-10 shadow-sm hover:shadow-2xl transition-all group overflow-hidden relative active:scale-[0.98]">
                                        <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-50 rounded-full -mr-16 -mt-16 blur-3xl opacity-50 group-hover:bg-indigo-100 transition-all"></div>
                                        
                                        <div class="relative z-10">
                                            <div class="flex justify-between items-start mb-3">
                                                <p class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.2em]">CONSOLIDADO</p>
                                                <div class="flex gap-2">
                                                    <button onclick="openDuplicateModal('<?= $m ?>', '<?= getMonthName($m) ?>')" class="w-8 h-8 flex items-center justify-center bg-indigo-50 text-indigo-600 rounded-full opacity-0 group-hover:opacity-100 transition-all hover:bg-indigo-600 hover:text-white" title="Duplicar registros para outro mês">
                                                        <span class="text-xs">🔁</span>
                                                    </button>
                                                    <button onclick="triggerConfirm('Excluir Mês', 'Deseja apagar TODOS os lançamentos de <?= getMonthName($m) ?>? Esta ação é irreversível.', 'delete_month', '', '<?= $m ?>')" class="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-full opacity-0 group-hover:opacity-100 transition-all hover:bg-rose-500 hover:text-white" title="Excluir Mês Inteiro">
                                                        <span class="text-xs">🗑️</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <h4 class="text-2xl font-black text-slate-900 mb-8"><?= getMonthName($m) ?></h4>
                                            
                                            <div class="space-y-4 mb-10">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-xs font-bold text-slate-400">Receitas</span>
                                                    <span class="text-sm font-black text-emerald-500"><?= fmtBRL($summary['income']) ?></span>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-xs font-bold text-slate-400">Despesas</span>
                                                    <span class="text-sm font-black text-rose-500"><?= fmtBRL($summary['expense']) ?></span>
                                                </div>
                                                <div class="pt-4 border-t border-slate-50 flex justify-between items-center">
                                                    <span class="text-xs font-black text-slate-900 uppercase">Saldo Final</span>
                                                    <span class="text-lg font-black text-slate-900"><?= fmtBRL($summary['net']) ?></span>
                                                </div>
                                            </div>

                                            <a href="?tab=cashbook&month=<?= $m ?>" class="block w-full py-4 bg-slate-50 text-slate-900 rounded-2xl text-[11px] font-black uppercase tracking-widest text-center border border-slate-100 hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                                                Abrir Livro Caixa
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- Detailed View (List/Grid of Transactions for a Month) -->
                            <div class="flex items-center justify-between mb-8">
                                <div class="flex items-center gap-4">
                                    <a href="?tab=cashbook&month=archive" class="w-10 h-10 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-50 transition-all shadow-sm">←</a>
                                    <div>
                                        <h3 class="text-2xl font-black text-slate-900 tracking-tight"><?= getMonthName($_GET['month']) ?></h3>
                                        <div class="flex items-center gap-2 mt-1">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest whitespace-nowrap">Painel de Detalhamento</p>
                                            <?php if($filter_search): ?>
                                                <span class="px-2.5 py-1 bg-indigo-50 text-indigo-600 rounded-lg text-[9px] font-black uppercase tracking-widest border border-indigo-100 flex items-center gap-1.5 shadow-sm">
                                                    <i class="fa-solid fa-user-check text-[8px]"></i>
                                                    <?= count($filtered_tx) ?> Sessões / Registros (<?= fmtBRL($totals_filtered['income']) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="bg-white p-1 rounded-xl flex shadow-sm border border-slate-200">
                                        <button type="button" onclick="setView('table')" id="btnViewTable" class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all bg-indigo-600 text-white shadow-md">Lista</button>
                                        <button type="button" onclick="setView('grid')" id="btnViewGrid" class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all text-slate-400 hover:text-slate-600">Cards</button>
                                    </div>
                                </div>
                            </div>

                            <!-- KPIs Topo -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
                                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-200 shadow-sm flex items-center gap-5 group hover:shadow-lg transition-all">
                                    <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform"><i class="fa-solid fa-arrow-trend-up"></i></div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.1em] mb-1">Entradas Totais</p>
                                        <p class="text-xl font-black text-slate-900"><?= fmtBRL($totals_filtered['income']) ?></p>
                                    </div>
                                </div>
                                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-200 shadow-sm flex items-center gap-5 group hover:shadow-lg transition-all">
                                    <div class="w-12 h-12 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform"><i class="fa-solid fa-arrow-trend-down"></i></div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.1em] mb-1">Saídas Totais</p>
                                        <p class="text-xl font-black text-slate-900"><?= fmtBRL($totals_filtered['expense']) ?></p>
                                    </div>
                                </div>
                                <div class="bg-slate-900 p-6 rounded-[2.5rem] shadow-xl shadow-slate-200 flex items-center gap-5 group border border-slate-800">
                                    <div class="w-12 h-12 bg-white/10 text-white rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform"><i class="fa-solid fa-wallet"></i></div>
                                    <div>
                                        <p class="text-[10px] font-black text-white/40 uppercase tracking-[0.1em] mb-1">Saldo Líquido</p>
                                        <p class="text-xl font-black text-white"><?= fmtBRL($totals_filtered['net']) ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Barra de Ações Central -->
                            <div class="bg-white p-4 rounded-[2.5rem] border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                                <div class="flex items-center gap-3 w-full sm:w-auto">
                                    <button onclick="triggerConfirm('Fechar Mês', 'Fechar lançamentos deste mês?', 'close_month')" class="w-full sm:w-auto h-11 px-5 bg-emerald-500 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-md shadow-emerald-50">Encerrar Período</button>
                                </div>
                                <div class="flex flex-col sm:flex-row items-center gap-2 w-full sm:w-auto">
                                    <button onclick="openModal('importModal')" class="w-full sm:w-auto h-11 px-5 bg-indigo-50 text-indigo-600 rounded-2xl border border-indigo-100 hover:bg-indigo-100 transition-all text-[11px] font-black uppercase tracking-widest flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-file-import"></i> Importar
                                    </button>
                                    <button onclick="openModal('txModal')" class="w-full sm:w-auto h-11 px-8 bg-indigo-600 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-plus"></i> Novo
                                    </button>
                                </div>
                            </div>

                            <!-- Filtros e Busca -->
                            <form class="bg-slate-50/50 p-3 rounded-[2rem] border border-slate-200/60 flex flex-col md:flex-row gap-3 items-center">
                                <input type="hidden" name="tab" value="cashbook">
                                <input type="hidden" name="month" value="<?= htmlspecialchars($_GET['month']) ?>">
                                <div class="flex-1 relative w-full">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <input type="text" name="search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Buscar nos lançamentos..." class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:ring-2 ring-indigo-50 transition-all">
                                </div>
                                <div class="flex gap-2 w-full md:w-auto">
                                    <div class="relative flex-1 md:w-48">
                                        <select name="type" class="w-full bg-white border border-slate-200 rounded-2xl pl-4 pr-10 py-3 text-xs font-black uppercase tracking-wider outline-none appearance-none cursor-pointer hover:border-indigo-200 transition-all">
                                            <option value="all" <?= $filter_type=='all' ? 'selected' : '' ?>>Todos os Fluxos</option>
                                            <option value="INCOME" <?= $filter_type=='INCOME' ? 'selected' : '' ?>>📈 Entradas</option>
                                            <option value="EXPENSE" <?= $filter_type=='EXPENSE' ? 'selected' : '' ?>>📉 Saídas</option>
                                        </select>
                                        <span class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">▼</span>
                                    </div>
                                    <button type="submit" class="px-8 py-3 bg-indigo-600 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-indigo-700 transition-all active:scale-95 shadow-lg shadow-indigo-100 italic">Filtrar</button>
                                </div>
                            </form>

                            <!-- List View (Table) -->
                            <div id="viewTable" class="bg-white rounded-[2.5rem] border border-slate-200 shadow-xl overflow-hidden animate-fade-in pointer-events-auto">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead>
                                            <tr class="bg-slate-50 border-b border-slate-100">
                                                <th class="px-4 lg:px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Data</th>
                                                <th class="px-4 lg:px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Descrição</th>
                                                <th class="hidden lg:table-cell px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Paciente / Pagador</th>
                                                <th class="px-4 lg:px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right whitespace-nowrap">Valor</th>
                                                <th class="hidden md:table-cell px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center whitespace-nowrap">Status</th>
                                                <th class="px-4 lg:px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right whitespace-nowrap">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            <?php if(!$filtered_tx): ?>
                                                <tr><td colspan="6" class="px-8 py-20 text-center text-slate-400 italic font-medium">Nenhum lançamento encontrado para este período.</td></tr>
                                            <?php endif; ?>
                                            <?php foreach($filtered_tx as $t): 
                                                $is_income = $t['type'] === 'INCOME';
                                                $amt_color = $is_income ? 'text-emerald-600' : 'text-rose-600';
                                                ?>
                                                <tr class="hover:bg-slate-50/80 transition-all border-b border-slate-50 last:border-0 group">
                                                    <td class="px-4 lg:px-8 py-6">
                                                        <div class="flex flex-col">
                                                            <span class="text-sm font-black text-slate-900"><?= fmtDateBR($t['date']) ?></span>
                                                            <span class="text-[10px] text-slate-400 font-bold uppercase tracking-tight mt-0.5"><?= date('l', strtotime($t['date'])) ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 lg:px-8 py-6">
                                                        <p class="text-[13px] font-black text-slate-800 group-hover:text-indigo-600 transition-colors"><?= htmlspecialchars($t['description']) ?></p>
                                                        <div class="flex flex-wrap gap-1.5 mt-2">
                                                            <span class="text-[8px] font-black px-2 py-0.5 rounded-lg bg-slate-100 text-slate-500 uppercase border border-slate-200/50"><?= htmlspecialchars($t['method']) ?></span>
                                                            <span class="text-[8px] font-black px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-500 uppercase border border-indigo-100/50"><?= htmlspecialchars($t['category']) ?></span>
                                                        </div>
                                                        <?php if(!empty($t['observation'])): ?>
                                                            <p class="mt-2 text-[10px] text-slate-400 font-medium italic line-clamp-1 group-hover:line-clamp-none transition-all leading-tight border-l-2 border-slate-100 pl-2"><?= htmlspecialchars($t['observation']) ?></p>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="hidden lg:table-cell px-8 py-6">
                                                        <div class="flex flex-col gap-2.5">
                                                            <?php if($t['payerName']): ?>
                                                            <div class="flex items-center gap-2.5">
                                                                <div class="w-7 h-7 bg-slate-100 rounded-lg flex items-center justify-center text-slate-400 text-[10px] shadow-inner"><i class="fa-solid fa-wallet"></i></div>
                                                                <div>
                                                                    <div class="flex items-center gap-2 mb-1">
                                                                        <p class="text-[10px] font-black text-slate-400 uppercase leading-none">Pagador</p>
                                                                        <?php if($t['payerCpf']): ?>
                                                                            <span class="text-[9px] font-bold text-slate-300 bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100"><?= htmlspecialchars($t['payerCpf']) ?></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <p class="text-[11px] font-bold text-slate-700 leading-none"><?= htmlspecialchars($t['payerName']) ?></p>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <?php if($t['beneficiaryName']): ?>
                                                            <div class="flex items-center gap-2.5">
                                                                <div class="w-7 h-7 bg-indigo-50 rounded-lg flex items-center justify-center text-indigo-400 text-[10px] shadow-inner"><i class="fa-solid fa-person-rays"></i></div>
                                                                <div>
                                                                    <div class="flex items-center gap-2 mb-1">
                                                                        <p class="text-[10px] font-black text-indigo-300 uppercase leading-none">Paciente</p>
                                                                        <?php if($t['beneficiaryCpf']): ?>
                                                                            <span class="text-[9px] font-bold text-indigo-200 bg-indigo-50/50 px-1.5 py-0.5 rounded border border-indigo-100/30"><?= htmlspecialchars($t['beneficiaryCpf']) ?></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <p class="text-[11px] font-bold text-indigo-600 leading-none"><?= htmlspecialchars($t['beneficiaryName']) ?></p>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 lg:px-8 py-6 text-right">
                                                        <p class="text-lg font-black <?= $amt_color ?> tracking-tight"><?= $is_income ? '+' : '-' ?> <?= fmtBRL($t['amount']) ?></p>
                                                    </td>
                                                    <td class="hidden md:table-cell px-8 py-6">
                                                        <div class="flex justify-center">
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="toggle-status">
                                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $t['status']=='PAID' ? 'PENDING' : 'PAID' ?>">
                                                                <button type="submit" class="text-[9px] font-black uppercase tracking-widest px-4 py-1.5 rounded-xl border-2 transition-all hover:scale-105 <?= $t['status']=='PAID' ? 'bg-emerald-50 text-emerald-600 border-emerald-100/50 shadow-sm' : 'bg-amber-50 text-amber-600 border-amber-100/50 shadow-sm' ?>">
                                                                    <i class="fa-solid <?= $t['status']=='PAID' ? 'fa-circle-check' : 'fa-clock' ?> mr-1.5"></i> <?= $t['status'] == 'PAID' ? 'Pago' : 'Pendente' ?>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 lg:px-8 py-6 text-right">
                                                        <div class="flex justify-end gap-2 opacity-40 group-hover:opacity-100 transition-opacity">
                                                            <button onclick="editTx(<?= htmlspecialchars(json_encode($t)) ?>)" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-slate-900 hover:text-white hover:border-slate-900 text-slate-400 transition-all shadow-sm"><i class="fa-solid fa-pen-to-square text-xs"></i></button>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="action" value="repeat">
                                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                                <button type="submit" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-emerald-500 hover:text-white hover:border-emerald-500 text-slate-400 transition-all shadow-sm"><i class="fa-solid fa-rotate-right text-xs"></i></button>
                                                            </form>
                                                            <button onclick="triggerConfirm('Excluir?', 'Este registro será removido.', 'delete', '<?= $t['id'] ?>')" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-rose-500 hover:text-white hover:border-rose-500 text-slate-400 transition-all shadow-sm"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Grid View (Cards) -->
                            <div id="viewGrid" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 animate-fade-in">
                                <?php if(!$filtered_tx): ?>
                                    <div class="col-span-full py-20 bg-white rounded-[2.5rem] border border-slate-200 text-center text-slate-400 italic font-medium">Nenhum lançamento encontrado em cards.</div>
                                <?php endif; ?>
                                <?php foreach($filtered_tx as $t): 
                                    $is_income = $t['type'] === 'INCOME';
                                    ?>
                                    <div class="group bg-white rounded-[2.5rem] border border-slate-200 p-8 shadow-sm hover:shadow-xl transition-all relative overflow-hidden flex flex-col justify-between h-full">
                                        <div class="absolute top-0 right-0 w-24 h-24 <?= $is_income ? 'bg-emerald-500/5' : 'bg-rose-500/5' ?> rounded-full -mr-12 -mt-12 blur-2xl"></div>
                                        
                                        <div>
                                            <div class="flex justify-between items-start mb-6">
                                                <div class="w-12 h-12 <?= $is_income ? 'bg-emerald-50 text-emerald-600 shadow-emerald-100' : 'bg-rose-50 text-rose-600 shadow-rose-100' ?> rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fa-solid <?= $is_income ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' ?>"></i></div>
                                                <div class="text-right">
                                                    <p class="text-[10px] font-black <?= $is_income ? 'text-emerald-500' : 'text-rose-500' ?> uppercase tracking-widest mb-1"><?= $is_income ? 'Entrada' : 'Saída' ?></p>
                                                    <h4 class="text-xl font-black text-slate-900"><?= fmtBRL($t['amount']) ?></h4>
                                                </div>
                                            </div>

                                            <div class="space-y-4 mb-8">
                                                <div>
                                                    <p class="text-sm font-black text-slate-800 leading-tight mb-1"><?= htmlspecialchars($t['description']) ?></p>
                                                    <div class="flex gap-2">
                                                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?= fmtDateBR($t['date']) ?></span>
                                                        <span class="text-[9px] font-black text-indigo-500/60 uppercase tracking-widest">• <?= htmlspecialchars($t['category']) ?></span>
                                                    </div>
                                                </div>

                                                <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-100/50">
                                                    <div class="flex flex-col gap-2">
                                                        <div class="flex justify-between items-start">
                                                            <div class="flex items-center gap-2">
                                                                <div class="w-1.5 h-1.5 rounded-full bg-slate-400"></div>
                                                                <p class="text-[10px] font-bold text-slate-700 truncate"><?= htmlspecialchars($t['payerName']) ?: 'N/A' ?></p>
                                                            </div>
                                                            <span class="text-[9px] font-black text-slate-300"><?= htmlspecialchars($t['payerCpf']) ?: '' ?></span>
                                                        </div>
                                                        <div class="flex justify-between items-start border-t border-slate-100 pt-2">
                                                            <div class="flex items-center gap-2">
                                                                <div class="w-1.5 h-1.5 rounded-full bg-indigo-500"></div>
                                                                <p class="text-[10px] font-black text-indigo-600 truncate"><?= htmlspecialchars($t['beneficiaryName']) ?: 'N/A' ?></p>
                                                            </div>
                                                            <span class="text-[9px] font-black text-indigo-300"><?= htmlspecialchars($t['beneficiaryCpf']) ?: '' ?></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if(!empty($t['observation'])): ?>
                                                    <div class="bg-slate-50/40 p-3 rounded-xl border border-dashed border-slate-200">
                                                        <p class="text-[10px] text-slate-400 italic font-medium leading-tight"><?= htmlspecialchars($t['observation']) ?></p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div>
                                            <!-- Actions Footer -->
                                            <div class="flex justify-between items-center bg-slate-50 -mx-8 -mb-8 px-8 py-5 border-t border-slate-100 rounded-b-[2.5rem]">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="toggle-status">
                                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= $t['status']=='PAID' ? 'PENDING' : 'PAID' ?>">
                                                    <button type="submit" class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border transition-all <?= $t['status']=='PAID' ? 'bg-emerald-500 text-white border-emerald-600' : 'bg-white text-amber-600 border-amber-200' ?>">
                                                        <i class="fa-solid <?= $t['status'] == 'PAID' ? 'fa-check' : 'fa-clock' ?> mr-1"></i> <?= $t['status'] == 'PAID' ? 'Pago' : 'Pendente' ?>
                                                    </button>
                                                </form>

                                                <div class="flex gap-2">
                                                    <button onclick="editTx(<?= htmlspecialchars(json_encode($t)) ?>)" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-indigo-600 hover:text-indigo-600 transition-all text-xs shadow-sm"><i class="fa-solid fa-pen-to-square"></i></button>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="repeat">
                                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-emerald-500 hover:text-emerald-500 transition-all text-xs shadow-sm"><i class="fa-solid fa-rotate-right"></i></button>
                                                    </form>
                                                    <button onclick="triggerConfirm('Excluir?', 'Este registro será removido.', 'delete', '<?= $t['id'] ?>')" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-rose-500 hover:text-rose-500 transition-all text-xs shadow-sm"><i class="fa-solid fa-trash-can"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab === 'pacientes'): ?>
                    <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-8">
                            <div>
                                <h3 class="text-3xl font-black text-slate-900 tracking-tight">Gestão de Pacientes</h3>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">Base de clientes e histórico de faturamento</p>
                            </div>
                            <div class="bg-indigo-600 px-6 py-4 rounded-[2rem] shadow-xl shadow-indigo-100 w-full md:w-auto flex items-center gap-4">
                                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white"><i class="fa-solid fa-users"></i></div>
                                <div>
                                    <p class="text-[10px] font-black text-indigo-100 uppercase tracking-widest leading-none mb-1">Total de Pacientes</p>
                                    <p class="text-xl font-black text-white leading-none"><?= count($pacientes) ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Filtros de Pacientes -->
                        <div class="bg-white p-6 rounded-[2.5rem] border border-slate-200 shadow-sm">
                            <form class="flex flex-col gap-4">
                                <input type="hidden" name="tab" value="pacientes">
                                
                                <div class="flex flex-col md:flex-row gap-4">
                                    <div class="relative flex-1">
                                        <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid fa-magnifying-glass"></i></span>
                                        <input type="text" name="p_search" value="<?= $p_search ?>" placeholder="Buscar por nome ou CPF..." class="w-full pl-12 pr-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-50 transition-all">
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-2">
                                        <select name="p_month" onchange="this.form.submit()" class="px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-[11px] font-black uppercase tracking-widest outline-none focus:ring-4 focus:ring-indigo-50 transition-all">
                                            <option value="all">Mês: Todos</option>
                                            <?php foreach([1,2,3,4,5,6,7,8,9,10,11,12] as $m_num): ?>
                                                <option value="<?= $m_num ?>" <?= $p_month == $m_num ? 'selected' : '' ?>><?= $mon_names[$m_num-1] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <select name="p_year" onchange="this.form.submit()" class="px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-[11px] font-black uppercase tracking-widest outline-none focus:ring-4 focus:ring-indigo-50 transition-all">
                                            <option value="all">Ano: Todos</option>
                                            <?php foreach($available_years as $y): ?>
                                                <option value="<?= $y ?>" <?= $p_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <select name="p_status" onchange="this.form.submit()" class="px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-[11px] font-black uppercase tracking-widest outline-none focus:ring-4 focus:ring-indigo-50 transition-all">
                                            <option value="all">Status: Todos</option>
                                            <option value="PAID" <?= $p_status == 'PAID' ? 'selected' : '' ?>>Somente Pagos</option>
                                            <option value="PENDING" <?= $p_status == 'PENDING' ? 'selected' : '' ?>>Somente Pendentes</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-2 border-t border-slate-50">
                                    <div class="flex items-center gap-3">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Ordenar por:</p>
                                        <div class="flex gap-1">
                                            <?php 
                                            $sort_opts = [
                                                'nome' => 'Nome',
                                                'valor' => 'Faturamento',
                                                'sessoes' => 'Sessões',
                                                'recente' => 'Recente'
                                            ];
                                            foreach($sort_opts as $val => $label): 
                                            ?>
                                                <button type="submit" name="p_sort" value="<?= $val ?>" class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all <?= $p_sort == $val ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-100' : 'bg-slate-50 text-slate-400 hover:bg-slate-100' ?>">
                                                    <?= $label ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="flex gap-2">
                                        <?php if($p_search || $p_month != 'all' || $p_year != 'all' || $p_status != 'all'): ?>
                                            <a href="?tab=pacientes" class="px-6 py-3 bg-rose-50 text-rose-500 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-100 transition-all flex items-center gap-2">
                                                <i class="fa-solid fa-xmark"></i> Limpar Filtros
                                            </a>
                                        <?php endif; ?>
                                        <button type="submit" class="px-8 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-lg italic">Aplicar Filtros</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <?php if(empty($pacientes)): ?>
                            <div class="bg-white rounded-[3rem] border border-slate-100 p-20 text-center shadow-sm">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-3xl">👤</div>
                                <h4 class="text-xl font-black text-slate-900 mb-2">Nenhum paciente encontrado</h4>
                                <p class="text-sm text-slate-400 font-medium">Os pacientes aparecem aqui automaticamente ao realizar lançamentos no Livro Caixa.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php foreach($pacientes as $p): ?>
                                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm hover:shadow-2xl transition-all group relative overflow-hidden active:scale-95">
                                        <div class="absolute top-0 right-0 w-24 h-24 bg-indigo-50 rounded-full -mr-12 -mt-12 group-hover:bg-indigo-100 transition-all opacity-40"></div>
                                        
                                        <div class="relative z-10 text-left">
                                            <div class="flex items-center gap-4 mb-6 text-left">
                                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($p['nome']) ?>&background=f1f5f9&color=6366f1" class="w-14 h-14 rounded-2xl shadow-inner border border-slate-100">
                                                <div class="text-left">
                                                    <h4 class="text-lg font-black text-slate-900 leading-tight"><?= $p['nome'] ?></h4>
                                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5 truncate max-w-[150px]"><?= $p['cpf'] ?: 'CPF não informado' ?></p>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 bg-slate-50/80 rounded-2xl p-4 gap-4 mb-6">
                                                <div class="text-left">
                                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Faturamento</p>
                                                    <p class="text-sm font-black text-indigo-600"><?= fmtBRL($p['total_gasto']) ?></p>
                                                </div>
                                                <div class="text-right">
                                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Sessões</p>
                                                    <p class="text-sm font-black text-slate-800"><?= $p['sessões'] ?></p>
                                                </div>
                                            </div>

                                            <div class="flex items-center justify-between">
                                                <div class="text-left">
                                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Última Sessão</p>
                                                    <p class="text-[11px] font-bold text-slate-600"><?= fmtDateBR($p['ult_sessao']) ?></p>
                                                </div>
                                                <a href="?tab=cashbook&month=all&search=<?= urlencode($p['nome']) ?>" class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center hover:bg-indigo-600 hover:text-white transition-all shadow-sm" title="Ver Histórico">
                                                    <i class="fa-solid fa-list-ul"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab === 'reports'): 
                    // Lógica de Inteligência Financeira (USANDO GEMINI 1.5 FLASH)
                    $current_month = date('Y-m');
                    $last_month = date('Y-m', strtotime('-1 month'));
                    $income_curr = 0; $income_last = 0;
                    foreach($all_tx as $t) {
                        $m = substr($t['date'], 0, 7);
                        if ($t['type'] === 'INCOME') {
                            if ($m === $current_month) $income_curr += $t['amount'];
                            if ($m === $last_month) $income_last += $t['amount'];
                        }
                    }
                    $growth = ($income_last > 0) ? (($income_curr - $income_last) / $income_last) * 100 : 0;
                    $profit_margin = ($totals_all['income'] > 0) ? ($totals_all['net'] / $totals_all['income']) * 100 : 0;

                    // CONSTRUIR PROMPT PARA O GEMINI
                    $prompt = "Você é uma Assistente de Consultor Financeiro de elite trabalhando no PsicoGestão para o(a) psicólogo(a) {$user_name}. 
                    Analise os dados e dê 3 dicas práticas incríveis focadas em saúde financeira e crescimento (seja EXTREMAMENTE BREVE e OBJETIVA, vá direto ao ponto e não gaste cota). 
                    Use um tom encorajador, extremante educado e profissional de altíssimo nível. Indique e encaminhe para sites/ferramentas úteis ('top') se for pertinente.
                    DADOS:
                    - Faturamento Mês Atual: R$ " . number_format($income_curr, 2, ',', '.') . "
                    - Crescimento: " . number_format($growth, 1) . "%
                    - Margem de Lucro: " . number_format($profit_margin, 1) . "%
                    - Total de Pacientes: " . count($pacientes) . "
                    - Paciente Top (maior faturamento): " . $top_paciente[0] . "
                    - Reserva para Impostos necessária: R$ " . number_format($totals_all['total_prov'], 2, ',', '.') . "
                    
                    RESPONDA APENAS AS 3 DICAS EM FORMATO DE LISTA.";

                    $ai_analysis = gemini_query($prompt);
                ?>
                    <div class="max-w-7xl mx-auto space-y-8 md:space-y-12 pb-24 px-2 sm:px-4">
                        <!-- Premium AI Hero -->
                        <div class="relative bg-slate-900 rounded-[2.5rem] md:rounded-[3.5rem] p-6 sm:p-8 md:p-12 overflow-hidden shadow-2xl hover:shadow-[0_20px_60px_-15px_rgba(99,102,241,0.5)] transition-shadow duration-500 border border-white/5">
                            <!-- Animated Background Blobs -->
                            <div class="absolute -top-32 -right-32 w-[30rem] h-[30rem] bg-indigo-600/30 rounded-full blur-[120px] animate-pulse"></div>
                            <div class="absolute -bottom-32 -left-32 w-[25rem] h-[25rem] bg-emerald-500/20 rounded-full blur-[100px] animate-pulse" style="animation-delay: 2s;"></div>
                            
                            <div class="relative z-10 flex flex-col xl:flex-row justify-between items-start xl:items-center gap-8 xl:gap-12">
                                <div class="w-full xl:max-w-2xl space-y-6 md:space-y-8">
                                    <div class="inline-flex items-center gap-3 px-4 py-2 md:px-5 md:py-2.5 rounded-full bg-white/5 backdrop-blur-md border border-white/10 text-indigo-300 text-[9px] md:text-[10px] font-black uppercase tracking-[0.3em] shadow-lg">
                                        <span class="flex h-2.5 w-2.5">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-500 shadow-[0_0_12px_rgba(99,102,241,0.9)]"></span>
                                        </span>
                                        Consultoria Financeira AI
                                    </div>
                                    <h2 class="text-4xl sm:text-5xl md:text-6xl font-black text-white leading-[1.1] md:leading-[1.15] tracking-tight">
                                        Insights de <br class="hidden sm:block"> <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 via-purple-400 to-emerald-400 animate-gradient-x">Performance Clínica</span>
                                    </h2>
                                    <p class="text-slate-300 text-base md:text-lg font-medium leading-relaxed max-w-xl">
                                        Olá, <span class="text-white font-bold"><?= $user_name ?></span>. Analisamos seu fluxo atual: seu faturamento cresceu <span class="text-emerald-400 font-black tracking-wide"><?= number_format($growth, 1) ?>%</span> e sua margem líquida é de <span class="text-indigo-400 font-black tracking-wide"><?= number_format($profit_margin, 1) ?>%</span>.
                                    </p>
                                    <div class="pt-2 md:pt-4 flex flex-col sm:flex-row flex-wrap gap-4">
                                        <button onclick="openModal('aiChatModal')" class="w-full sm:w-auto px-6 py-4 md:px-8 md:py-5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white rounded-2xl md:rounded-[1.25rem] text-[10px] md:text-[11px] font-black uppercase tracking-[0.2em] shadow-xl shadow-indigo-600/30 transition-all active:scale-95 flex items-center justify-center gap-3">
                                            <i class="fa-solid fa-sparkles text-lg group-hover:rotate-12 transition-transform"></i>
                                            Falar com a IA
                                        </button>
                                        <button class="w-full sm:w-auto px-6 py-4 md:px-8 md:py-5 bg-white/5 backdrop-blur-sm hover:bg-white/10 text-white/80 border border-white/10 rounded-2xl md:rounded-[1.25rem] text-[10px] md:text-[11px] font-black uppercase tracking-[0.2em] shadow-lg transition-all flex items-center justify-center gap-3">
                                            <i class="fa-solid fa-file-pdf text-lg"></i>
                                            Baixar Relatório
                                        </button>
                                    </div>
                                </div>

                                <!-- Health Score Hexagon or Card -->
                                <div class="relative group w-full sm:max-w-xs xl:w-72 mx-auto xl:mx-0 mt-8 xl:mt-0">
                                    <div class="absolute inset-0 bg-indigo-500/20 blur-[50px] rounded-full group-hover:bg-indigo-500/40 transition-all duration-700"></div>
                                    <div class="relative bg-white/5 backdrop-blur-xl border border-white/10 p-8 md:p-10 rounded-[2.5rem] md:rounded-[3rem] text-center w-full transform group-hover:-translate-y-2 transition-transform duration-500 shadow-2xl">
                                        <p class="text-[9px] md:text-[10px] font-black uppercase tracking-[0.3em] text-indigo-200/70 mb-3 md:mb-4">Score de Saúde</p>
                                        <div class="text-6xl md:text-7xl font-black text-emerald-400 tracking-tighter shadow-emerald-400/20 drop-shadow-2xl">9.4</div>
                                        <div class="mt-4 md:mt-5 px-4 py-2 bg-emerald-500/10 border border-emerald-500/20 rounded-full text-[9px] md:text-[10px] font-black text-emerald-400 uppercase tracking-widest inline-block shadow-inner">Nível Excelente</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Main Insights Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8 cursor-default">
                            <!-- Card 1: Ponto de Equilíbrio -->
                            <div class="bg-white p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all duration-500 group overflow-hidden relative">
                                <div class="absolute -right-10 -top-10 w-24 sm:w-32 h-24 sm:h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 opacity-50"></div>
                                <div class="relative z-10">
                                    <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-br from-emerald-50 to-emerald-100 text-emerald-600 rounded-[1.25rem] sm:rounded-3xl flex items-center justify-center text-xl sm:text-2xl mb-6 sm:mb-8 group-hover:scale-110 transition-transform shadow-inner ring-1 ring-emerald-200">
                                        <i class="fa-solid fa-scale-balanced"></i>
                                    </div>
                                    <h4 class="text-xl sm:text-2xl font-black text-slate-800 mb-3 sm:mb-4">Ponto de Equilíbrio</h4>
                                    <p class="text-xs sm:text-sm text-slate-500 leading-relaxed font-medium">
                                        Você já cobriu <span class="text-emerald-600 font-black">100%</span> das suas despesas fixas utilizando apenas <span class="bg-emerald-50 px-2 py-0.5 rounded-lg text-emerald-700 font-bold"><?= number_format(($totals_all['expense'] / max(1, $totals_all['income'])) * 100, 0) ?>%</span> do seu faturamento bruto.
                                    </p>
                                    <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-slate-100/60">
                                        <div class="flex justify-between items-center text-[9px] sm:text-[10px] font-black uppercase tracking-widest text-slate-400">
                                            <span>Cobertura</span>
                                            <span class="text-emerald-600">Completa</span>
                                        </div>
                                        <div class="mt-3 h-2 sm:h-2.5 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                            <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-500 rounded-full" style="width: 100%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card 2: Top Performance -->
                            <div class="bg-white p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all duration-500 group overflow-hidden relative">
                                <div class="absolute -right-10 -top-10 w-24 sm:w-32 h-24 sm:h-32 bg-indigo-50 rounded-full group-hover:scale-150 transition-transform duration-700 opacity-50"></div>
                                <div class="relative z-10">
                                    <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-br from-indigo-50 to-indigo-100 text-indigo-600 rounded-[1.25rem] sm:rounded-3xl flex items-center justify-center text-xl sm:text-2xl mb-6 sm:mb-8 group-hover:scale-110 transition-transform shadow-inner ring-1 ring-indigo-200">
                                        <i class="fa-solid fa-crown"></i>
                                    </div>
                                    <h4 class="text-xl sm:text-2xl font-black text-slate-800 mb-3 sm:mb-4">Top Performance</h4>
                                    <p class="text-xs sm:text-sm text-slate-500 leading-relaxed font-medium">
                                        Seu paciente com maior faturamento acumulado é <span class="text-indigo-600 font-black"><?= $top_paciente[0] ?></span>, representando um faturamento de <span class="bg-indigo-50 px-2 py-0.5 rounded-lg text-indigo-700 font-bold"><?= fmtBRL($top_paciente[1]) ?></span>.
                                    </p>
                                    <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-slate-100/60 flex items-center justify-between gap-4">
                                        <div class="flex-1">
                                            <p class="text-[8px] sm:text-[9px] font-black text-slate-400 uppercase tracking-widest leading-none">Status</p>
                                            <p class="text-[11px] sm:text-xs font-bold text-slate-700 mt-1">Alta de Retenção</p>
                                        </div>
                                        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full border-2 border-indigo-200 flex items-center justify-center text-[10px] sm:text-xs font-black text-indigo-600 text-center shadow-sm">92%</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card 3: Reserva Fiscal -->
                            <div class="bg-white p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all duration-500 group overflow-hidden relative">
                                <div class="absolute -right-10 -top-10 w-24 sm:w-32 h-24 sm:h-32 bg-amber-50 rounded-full group-hover:scale-150 transition-transform duration-700 opacity-50"></div>
                                <div class="relative z-10">
                                    <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-br from-amber-50 to-amber-100 text-amber-600 rounded-[1.25rem] sm:rounded-3xl flex items-center justify-center text-xl sm:text-2xl mb-6 sm:mb-8 group-hover:scale-110 transition-transform shadow-inner ring-1 ring-amber-200">
                                        <i class="fa-solid fa-piggy-bank"></i>
                                    </div>
                                    <h4 class="text-xl sm:text-2xl font-black text-slate-800 mb-3 sm:mb-4">Reserva Fiscal</h4>
                                    <p class="text-xs sm:text-sm text-slate-500 leading-relaxed font-medium">
                                        Recomendamos reservar <span class="text-amber-600 font-black"><?= fmtBRL($totals_all['total_prov']) ?></span> para obrigações deste período, garantindo zero surpresas.
                                    </p>
                                    <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-slate-100/60">
                                        <div class="flex justify-between items-center text-[9px] sm:text-[10px] font-black uppercase tracking-widest text-slate-400">
                                            <span>Nível de Risco</span>
                                            <span class="text-amber-600">Controlado</span>
                                        </div>
                                        <div class="mt-3 h-2 sm:h-2.5 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                            <div class="h-full bg-gradient-to-r from-amber-400 to-amber-500 rounded-full" style="width: 25%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Strategic AI Analysis Center -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 sm:gap-8 lg:gap-10">
                            <!-- Left: Detailed Margins -->
                            <div class="lg:col-span-2 bg-white rounded-[2rem] sm:rounded-[3rem] md:rounded-[4rem] border border-slate-100 p-6 sm:p-8 md:p-12 shadow-sm hover:shadow-xl transition-shadow duration-500">
                                <div class="flex justify-between items-start mb-8 md:mb-12">
                                    <div>
                                        <h3 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Análise Estrutural do Caixa</h3>
                                        <p class="text-[9px] sm:text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-1 sm:mt-2">Eficiência Operacional Detalhada</p>
                                    </div>
                                    <button class="w-10 h-10 sm:w-14 sm:h-14 bg-slate-50 text-slate-400 rounded-xl sm:rounded-2xl flex items-center justify-center hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                                        <i class="fa-solid fa-ellipsis"></i>
                                    </button>
                                </div>

                                <div class="space-y-8 sm:space-y-10">
                                    <div class="bg-slate-50/50 p-6 sm:p-8 rounded-[1.5rem] sm:rounded-[2.5rem] border border-slate-50">
                                        <div class="flex justify-between items-end mb-3 sm:mb-4">
                                            <span class="text-[10px] sm:text-[12px] font-black text-slate-800 uppercase tracking-widest">Margem de Lucro Bruta</span>
                                            <span class="text-3xl sm:text-4xl font-black text-indigo-600 tracking-tighter"><?= number_format($profit_margin, 1) ?>%</span>
                                        </div>
                                        <div class="h-3 sm:h-4 bg-slate-100/50 rounded-full overflow-hidden p-0.5 sm:p-1 shadow-inner border border-slate-200">
                                            <div class="h-full bg-gradient-to-r from-indigo-500 via-purple-500 to-indigo-400 rounded-full shadow-lg" style="width: <?= max(5, min(100, $profit_margin)) ?>%"></div>
                                        </div>
                                        <p class="text-[10px] sm:text-xs text-slate-500 font-medium mt-3 sm:mt-4 italic">Sua margem está saudável. Consultórios de alto padrão costumam operar entre 65% e 80% de lucro líquido.</p>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 md:gap-8 pt-2 sm:pt-6">
                                        <div class="p-6 sm:p-8 bg-gradient-to-br from-slate-50 to-white rounded-[1.5rem] sm:rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-md transition-shadow">
                                            <p class="text-[9px] sm:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 sm:mb-2">Ticket Médio / Sessão</p>
                                            <p class="text-xl sm:text-2xl md:text-3xl font-black text-slate-900">
                                                <?php 
                                                    $total_sessoes = 0;
                                                    foreach($pacientes as $p) $total_sessoes += $p['sessões'];
                                                    echo fmtBRL($totals_all['income'] / max(1, $total_sessoes));
                                                ?>
                                            </p>
                                        </div>
                                        <div class="p-6 sm:p-8 bg-gradient-to-br from-slate-50 to-white rounded-[1.5rem] sm:rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-md transition-shadow">
                                            <p class="text-[9px] sm:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 sm:mb-2">Custo Fixo Mensal</p>
                                            <p class="text-xl sm:text-2xl md:text-3xl font-black text-slate-900"><?= fmtBRL($totals_all['expense']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: AI Brain Suggestions -->
                            <div class="flex flex-col gap-6 sm:gap-8">
                                <div class="bg-gradient-to-br from-indigo-600 to-purple-700 rounded-[2rem] sm:rounded-[3rem] md:rounded-[4rem] p-6 sm:p-8 lg:p-10 text-white shadow-2xl shadow-indigo-600/30 relative overflow-hidden group">
                                    <div class="absolute -top-10 -right-10 w-40 h-40 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-all duration-1000"></div>
                                    <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-purple-500/20 rounded-full blur-2xl group-hover:scale-150 transition-all duration-1000 delay-100"></div>
                                    
                                    <h5 class="flex items-center gap-2 text-[10px] sm:text-sm font-black text-indigo-100 mb-6 sm:mb-8 uppercase tracking-[0.2em]">
                                        <i class="fa-solid fa-brain opacity-70"></i> IA Brain: Estratégia
                                    </h5>
                                    
                                    <div class="space-y-4 sm:space-y-6 relative z-10">
                                        <div class="text-xs sm:text-[13px] font-medium text-indigo-50 leading-relaxed whitespace-pre-wrap">
                                            <?= $ai_analysis ?>
                                        </div>
                                    </div>

                                    <div class="mt-8 sm:mt-10 pt-6 sm:pt-10 border-t border-white/20 flex items-center justify-between">
                                        <div class="flex -space-x-2 sm:-space-x-3">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/20 backdrop-blur-md border border-indigo-400 shadow-lg flex items-center justify-center text-[8px] sm:text-[10px] font-black">AI</div>
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-gradient-to-br from-indigo-400 to-purple-500 border border-indigo-300 shadow-lg flex items-center justify-center text-[8px] sm:text-[10px] font-black"><?= $user_initials ?></div>
                                        </div>
                                        <button onclick="openModal('aiChatModal')" class="text-[9px] sm:text-[10px] font-black uppercase tracking-widest text-indigo-200 hover:text-white transition-colors group-hover:translate-x-1 duration-300">Consultar Histórico →</button>
                                    </div>
                                </div>

                                <div class="bg-gradient-to-br from-white to-slate-50 rounded-[2rem] sm:rounded-[3rem] p-6 sm:p-8 lg:p-10 border border-slate-100 shadow-sm hover:shadow-lg transition-shadow duration-300 flex-1 flex flex-col justify-center items-center text-center cursor-pointer group" onclick="openModal('aiChatModal')">
                                    <div class="w-16 h-16 sm:w-20 sm:h-20 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-2xl sm:text-3xl mb-4 sm:mb-6 shadow-inner ring-1 ring-emerald-100 group-hover:bg-emerald-500 group-hover:text-white transition-all duration-300">
                                        <i class="fa-solid fa-robot group-hover:animate-bounce"></i>
                                    </div>
                                    <h5 class="text-lg sm:text-xl font-black text-slate-900 mb-2 group-hover:text-emerald-600 transition-colors">Dúvida Pontual?</h5>
                                    <p class="text-xs sm:text-sm text-slate-500 font-medium px-2 sm:px-4">A Assistente Top IA está pronta para responder perguntas sobre o sistema, suas metas e dar dicas geniais.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab === 'settings'): 
                    $stmt_u = $pdo->query('SELECT * FROM users ORDER BY name ASC');
                    $all_users = $stmt_u->fetchAll(PDO::FETCH_ASSOC);
                ?>
                    <div class="max-w-4xl mx-auto space-y-8 pb-20">
                        <div class="flex flex-col sm:flex-row justify-between items-center bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm gap-4">
                            <div class="text-center sm:text-left">
                                <h3 class="text-2xl font-black text-slate-900 tracking-tighter">Painel de Controle</h3>
                                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Gestão de Serviços e Usuários</p>
                            </div>
                            <div class="flex gap-2">
                                <button onclick="resetStForm(); document.getElementById('session_section').scrollIntoView({behavior:'smooth'})" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-[8px] font-black uppercase tracking-widest hover:bg-slate-900 transition-all shadow-md shadow-indigo-100">Novo Serviço</button>
                                <button onclick="resetUserForm(); document.getElementById('user_section').scrollIntoView({behavior:'smooth'})" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-[8px] font-black uppercase tracking-widest hover:bg-indigo-600 transition-all shadow-md shadow-slate-100">Novo Usuário</button>
                            </div>
                        </div>

                        <!-- SEÇÃO: SERVIÇOS -->
                        <div id="session_section" class="scroll-mt-24 bg-white p-6 rounded-[3rem] border border-slate-200 shadow-sm">
                            <div class="flex items-center gap-2 mb-6 ml-2">
                                <div class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-500 flex items-center justify-center text-[10px]"><i class="fa-solid fa-tag"></i></div>
                                <h4 class="text-sm font-black text-slate-800 tracking-tight">Serviços e Valores</h4>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                                <div class="lg:col-span-2">
                                    <div class="bg-slate-50 p-6 rounded-[2rem] border border-slate-100">
                                        <h5 id="st_title" class="text-[8px] font-black text-slate-400 mb-4 uppercase tracking-widest">Configurar Item</h5>
                                        <form method="POST" class="space-y-3">
                                            <input type="hidden" name="action" value="save_session_type">
                                            <input type="hidden" name="id" id="st_id" value="">
                                            
                                            <div class="space-y-1">
                                                <input type="text" name="name" id="st_name" required placeholder="Nome do Serviço" class="w-full px-4 py-2.5 bg-white border border-slate-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-indigo-100 transition-all">
                                            </div>
                                            <div class="space-y-1">
                                                <input type="text" name="default_value" id="st_value" required placeholder="Valor (R$)" class="w-full px-4 py-2.5 bg-white border border-slate-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-indigo-100 transition-all">
                                            </div>
                                            <div class="pt-1 flex gap-2">
                                                <button type="submit" class="flex-1 py-3 bg-indigo-600 text-white rounded-xl text-[8px] font-black uppercase tracking-widest hover:bg-indigo-700 transition-all shadow-sm italic">Salvar</button>
                                                <button type="button" onclick="resetStForm()" class="px-3 py-3 bg-white text-slate-300 rounded-xl hover:bg-slate-100 transition-all border border-slate-100"><i class="fa-solid fa-xmark"></i></button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="lg:col-span-3">
                                    <?php if(empty($session_types)): ?>
                                        <div class="h-full bg-slate-50 rounded-[2rem] border border-dashed border-slate-200 flex flex-col items-center justify-center p-6 text-center">
                                            <p class="text-[10px] text-slate-300 font-bold uppercase tracking-widest">Nenhum serviço salvo</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="space-y-2">
                                            <?php foreach($session_types as $st): ?>
                                                <div class="bg-white p-3 px-4 rounded-2xl border border-slate-100 flex items-center justify-between group hover:border-indigo-200 transition-all shadow-sm">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-8 h-8 bg-indigo-50 rounded-lg flex items-center justify-center text-indigo-400 transition-all text-xs"><i class="fa-solid fa-fingerprint"></i></div>
                                                        <div class="min-w-0">
                                                            <h5 class="font-black text-slate-800 truncate text-[11px]"><?= htmlspecialchars($st['name']) ?></h5>
                                                            <p class="text-emerald-500 font-black text-[9px]"><?= fmtBRL($st['default_value']) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex gap-1.5 opacity-0 group-hover:opacity-100 transition-all">
                                                        <button onclick='editSt(<?= json_encode($st) ?>)' class="w-7 h-7 flex items-center justify-center bg-slate-50 text-slate-400 rounded-lg hover:bg-slate-900 hover:text-white transition-all text-[10px]"><i class="fa-solid fa-pen"></i></button>
                                                        <form method="POST" onsubmit="return confirm('Excluir?')" class="flex-shrink-0">
                                                            <input type="hidden" name="action" value="delete_session_type">
                                                            <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                                            <button type="submit" class="w-7 h-7 flex items-center justify-center bg-slate-50 text-rose-300 rounded-lg hover:bg-rose-500 hover:text-white transition-all text-[10px]"><i class="fa-solid fa-trash-can"></i></button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- SEÇÃO: USUÁRIOS -->
                        <div id="user_section" class="scroll-mt-24 bg-white p-6 rounded-[3rem] border border-slate-200 shadow-sm">
                            <div class="flex items-center gap-2 mb-6 ml-2">
                                <div class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-[10px]"><i class="fa-solid fa-user-plus"></i></div>
                                <h4 class="text-sm font-black text-slate-800 tracking-tight">Equipe e Acessos</h4>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                                <div class="lg:col-span-2">
                                    <div class="bg-indigo-900 p-6 rounded-[2rem] text-white shadow-xl relative overflow-hidden">
                                        <h5 id="user_title" class="text-[8px] font-black text-indigo-300 mb-4 uppercase tracking-widest">Criar Usuário</h5>
                                        <form method="POST" class="space-y-3">
                                            <input type="hidden" name="action" value="save_user">
                                            <input type="hidden" name="id" id="u_id" value="">
                                            
                                            <input type="text" name="name" id="u_name" required placeholder="Nome Completo" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-xs font-bold outline-none focus:bg-white focus:text-slate-900 transition-all">
                                            <input type="email" name="email" id="u_email" required placeholder="E-mail" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-xs font-bold outline-none focus:bg-white focus:text-slate-900 transition-all">
                                            <input type="password" name="password" id="u_pass" required placeholder="Senha" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-xs font-bold outline-none focus:bg-white focus:text-slate-900 transition-all">
                                            <input type="text" name="crp" id="u_crp" placeholder="CRP (Opcional)" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-xs font-bold outline-none focus:bg-white focus:text-slate-900 transition-all">
                                            
                                            <div class="pt-1 flex gap-2">
                                                <button type="submit" class="flex-1 py-3 bg-white text-indigo-900 rounded-xl text-[8px] font-black uppercase tracking-widest hover:scale-[1.02] active:scale-95 transition-all shadow-md italic">Confirmar</button>
                                                <button type="button" onclick="resetUserForm()" class="px-3 py-3 bg-white/10 text-white rounded-xl hover:bg-white/20 transition-all"><i class="fa-solid fa-xmark"></i></button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="lg:col-span-3">
                                    <div class="space-y-2">
                                        <?php foreach($all_users as $u): ?>
                                            <div class="bg-white p-3 px-4 rounded-2xl border border-slate-100 flex items-center justify-between group hover:border-emerald-200 transition-all shadow-sm">
                                                <div class="flex items-center gap-3">
                                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($u['name']) ?>&background=f1f5f9&color=6366f1" class="w-8 h-8 rounded-lg shadow-sm">
                                                    <div class="min-w-0">
                                                        <h5 class="font-black text-slate-800 truncate text-[11px]"><?= htmlspecialchars($u['name']) ?></h5>
                                                        <p class="text-[8px] font-bold text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($u['email']) ?></p>
                                                    </div>
                                                </div>
                                                <div class="flex gap-1.5 opacity-0 group-hover:opacity-100 transition-all">
                                                    <button onclick='editUser(<?= json_encode($u) ?>)' class="w-7 h-7 flex items-center justify-center bg-slate-50 text-slate-400 rounded-lg hover:bg-slate-900 hover:text-white transition-all text-[10px]"><i class="fa-solid fa-pen"></i></button>
                                                    <?php if($u['id'] !== $user_id): ?>
                                                        <form method="POST" onsubmit="return confirm('Excluir?')" class="flex-shrink-0">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="w-7 h-7 bg-rose-50 text-rose-400 rounded-lg hover:bg-rose-500 hover:text-white transition-all text-[10px]"><i class="fa-solid fa-trash-can"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center opacity-40 py-20">
                        <span class="text-6xl mb-6">🚧</span>
                        <h2 class="text-xl font-black uppercase tracking-widest text-slate-400">Módulo em Desenvolvimento</h2>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="txModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md hidden z-[100] items-center justify-center p-0 lg:p-4">
        <div class="bg-white w-full max-w-2xl h-full lg:h-auto lg:rounded-[3rem] shadow-2xl overflow-y-auto lg:overflow-hidden animate-fade-in border border-slate-200">
            <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 sticky top-0 z-10 backdrop-blur-sm">
                <div>
                    <h3 id="modalTitle" class="text-2xl font-black text-slate-900 tracking-tight">Novo Lançamento</h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Gestão de Fluxo Profissional</p>
                </div>
                <button onclick="closeModal('txModal')" class="w-10 h-10 bg-slate-200 rounded-2xl flex items-center justify-center hover:bg-slate-300 transition-all">✕</button>
            </div>
            
            <form method="POST" id="mainForm" class="p-8 space-y-6">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="field_id">
                <input type="hidden" name="is_edit" id="field_is_edit" value="">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Tipo de Movimentação</label>
                        <div class="grid grid-cols-2 gap-2 bg-slate-50 p-1.5 rounded-2xl border border-slate-100">
                            <label class="cursor-pointer">
                                <input type="radio" name="type" value="INCOME" checked class="hidden peer">
                                <div class="py-2.5 text-center rounded-xl text-sm font-bold text-slate-400 peer-checked:bg-indigo-600 peer-checked:text-white transition-all">Receita</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="type" value="EXPENSE" class="hidden peer">
                                <div class="py-2.5 text-center rounded-xl text-sm font-bold text-slate-400 peer-checked:bg-rose-600 peer-checked:text-white transition-all">Despesa</div>
                            </label>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Data Competência</label>
                        <input type="date" name="date" id="field_date" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold outline-none" required>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Descrição Detalhada</label>
                    <input type="text" name="description" id="field_description" placeholder="Ex: Pacote Mensal - Paciente João Silva" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold outline-none" required>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Valor do Repasse (R$)</label>
                        <input type="number" step="0.01" name="amount" id="field_amount" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-xl font-black text-indigo-600 outline-none" placeholder="0,00" required>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Meio de Recebimento</label>
                        <select name="method" id="field_method" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold outline-none font-sans">
                            <option value="Pix">PIX (Mais comum)</option>
                            <option value="Cartão">Cartão de Débito/Crédito</option>
                            <option value="Dinheiro">Dinheiro Espécie</option>
                            <option value="TED">Transferência Bancária</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2">
                     <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Categoria / Serviço</label>
                     <select name="category" id="field_category" onchange="updateValueFromCategory()" class="w-full px-5 py-3.5 bg-indigo-50/50 border border-indigo-100 rounded-2xl text-sm font-black outline-none appearance-none focus:ring-4 focus:ring-indigo-100 transition-all font-sans">
                        <option value="Geral">📦 Geral / Outros</option>
                        <optgroup label="Seções e Pacotes">
                             <?php foreach($session_types as $st): ?>
                                <option value="<?= htmlspecialchars($st['name']) ?>" data-value="<?= $st['default_value'] ?>"><?= htmlspecialchars($st['name']) ?> (<?= fmtBRL($st['default_value']) ?>)</option>
                             <?php endforeach; ?>
                        </optgroup>
                     </select>
                </div>

                <!-- Compact Patient/Payer Section -->
                <div class="p-6 bg-slate-50 rounded-[2.5rem] border border-slate-100 space-y-5">
                    <div class="flex items-center justify-between px-2">
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-[0.2em]">Identificação do Atendimento</p>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="payerIsSame" checked onchange="togglePayerFields()" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                            <label for="payerIsSame" class="text-[10px] font-bold text-slate-500 uppercase cursor-pointer">Pagador é o Paciente</label>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2 relative">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Nome do Paciente</label>
                            <input type="text" name="beneficiaryName" id="field_beneficiaryName" autocomplete="off" oninput="handlePatientInput(this)" class="w-full px-5 py-3.5 bg-white border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                            
                            <!-- Autocomplete Dropdown -->
                            <div id="patientSuggestions" class="absolute left-0 right-0 top-full mt-2 bg-white border border-slate-200 rounded-2xl shadow-2xl z-[110] hidden overflow-hidden">
                                <ul id="patientList" class="max-h-60 overflow-y-auto divide-y divide-slate-50"></ul>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">CPF Paciente</label>
                            <input type="text" name="beneficiaryCpf" id="field_beneficiaryCpf" oninput="syncPayer()" class="w-full px-5 py-3.5 bg-white border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                        </div>
                    </div>

                    <div id="payerSection" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden border-t border-slate-100 pt-5 animate-fade-in">
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Nome do Pagador</label>
                            <input type="text" name="payerName" id="field_payerName" class="w-full px-5 py-3.5 bg-white border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">CPF Pagador</label>
                            <input type="text" name="payerCpf" id="field_payerCpf" class="w-full px-5 py-3.5 bg-white border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Observações Internas</label>
                    <textarea name="observation" id="field_observation" rows="2" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium outline-none resize-none"></textarea>
                </div>

                <div class="pt-6 border-t border-slate-50 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('txModal')" class="px-6 py-3 rounded-xl text-xs font-bold text-slate-400 hover:bg-slate-50 transition-all">Cancelar</button>
                    <button type="submit" class="px-10 py-3 bg-indigo-600 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all active:scale-95">Salvar Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- --- DUPLICATE MONTH MODAL --- -->
    <div id="duplicateModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[200] hidden items-center justify-center p-4 animate-fade-in">
        <div class="bg-white w-full max-w-md rounded-[3rem] shadow-2xl overflow-hidden animate-slide-up">
            <div class="bg-indigo-600 p-8 text-white relative">
                <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-16 -mt-16 blur-2xl"></div>
                <h3 class="text-2xl font-black tracking-tight mb-2">Duplicar Período</h3>
                <p class="text-indigo-100 text-xs font-bold uppercase tracking-widest opacity-80">Planejamento Financeiro</p>
            </div>
            <form method="POST" class="p-8 space-y-6">
                <input type="hidden" name="action" value="duplicate_month">
                <input type="hidden" name="source_month" id="dup_source_month">
                
                <div class="space-y-4">
                    <div class="p-4 bg-indigo-50 rounded-2xl border border-indigo-100">
                        <p class="text-[10px] font-black text-indigo-400 uppercase tracking-widest mb-1">Copiando de:</p>
                        <p id="dup_source_label" class="text-lg font-black text-indigo-900">Mês Selecionado</p>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Para o Mês de Destino:</label>
                        <input type="month" name="target_month" id="dup_target_month" required class="w-full px-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                    </div>

                    <div class="p-4 bg-amber-50 rounded-2xl border border-amber-100 flex gap-3">
                        <div class="text-amber-500">⚠️</div>
                        <p class="text-[11px] font-medium text-amber-700 leading-tight">Todos os lançamentos serão criados como <b>Pendente</b> no mês de destino.</p>
                    </div>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeModal('duplicateModal')" class="flex-1 py-4 bg-slate-100 text-slate-500 rounded-2xl text-[11px] font-black uppercase tracking-widest hover:bg-slate-200 transition-all">Cancelar</button>
                    <button type="submit" class="flex-[2] py-4 bg-slate-900 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-xl italic">Confirmar Duplicação</button>
                </div>
            </form>
        </div>
    </div>

    <!-- --- AI CHAT MODAL --- -->
    <div id="aiChatModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[200] hidden items-center justify-center p-4 animate-fade-in">
        <div class="bg-white w-full max-w-2xl rounded-[3rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="bg-slate-900 p-8 text-white flex justify-between items-center shrink-0">
                <div>
                    <h3 class="text-2xl font-black tracking-tight">Consultoria IA Ativa</h3>
                    <p class="text-indigo-400 text-[10px] font-black uppercase tracking-[0.3em]">PsicoGestão AI - Gemini 1.5 Flash</p>
                </div>
                <button onclick="closeModal('aiChatModal')" class="w-10 h-10 bg-white/10 rounded-2xl flex items-center justify-center hover:bg-white/20 transition-all font-sans">✕</button>
            </div>
            
            <div id="aiChatContent" class="flex-1 overflow-y-auto p-8 space-y-6 bg-slate-50/50">
                <div class="flex gap-4">
                    <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shrink-0 shadow-lg shadow-indigo-100">AI</div>
                    <div class="bg-white p-5 rounded-3xl rounded-tl-none border border-slate-100 shadow-sm text-sm font-medium text-slate-700 leading-relaxed max-w-[85%]">
                        Olá, <?= $user_name ?>! Estou pronta para analisar seu consultório...
                        <ul class="mt-3 space-y-2 text-[11px] font-black text-indigo-600 uppercase tracking-widest list-disc ml-4">
                            <li>"Como posso aumentar minha margem de lucro?"</li>
                            <li>"Qual a minha situação fiscal hoje?"</li>
                            <li>"Resuma meu desempenho desse mês."</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="p-8 bg-white border-t border-slate-100 shrink-0">
                <div class="flex gap-3">
                    <input type="text" id="aiQuestion" placeholder="Faça uma pergunta para a IA..." class="flex-1 px-6 py-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all">
                    <button onclick="askAI()" id="btnAskAI" class="w-14 h-14 bg-indigo-600 text-white rounded-2xl flex items-center justify-center shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition-all active:scale-95">
                        <i class="fa-solid fa-paper-plane text-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="importModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md hidden z-[100] items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-[3rem] shadow-2xl overflow-hidden border border-slate-200 animate-fade-in">
            <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight">Importar Lançamentos</h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Sincronização em Lote</p>
                </div>
                <button onclick="closeModal('importModal')" class="w-10 h-10 bg-slate-200 rounded-2xl flex items-center justify-center hover:bg-slate-300 transition-all">✕</button>
            </div>

            <div class="p-8">
                <!-- Tabs para Importação -->
                <div class="flex gap-2 p-1.5 bg-slate-100 rounded-2xl mb-8">
                    <button onclick="switchImportTab('tabFile')" id="btnTabFile" class="flex-1 py-3 text-xs font-bold rounded-xl transition-all bg-white shadow-sm text-indigo-600">Arquivo CSV</button>
                    <button onclick="switchImportTab('tabPaste')" id="btnTabPaste" class="flex-1 py-3 text-xs font-bold rounded-xl transition-all text-slate-500 hover:text-slate-700">Colar Dados (Excel)</button>
                </div>

                <div id="tabFile" class="space-y-6">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import">
                        <div class="border-4 border-dashed border-slate-100 rounded-[2.5rem] p-12 text-center hover:bg-indigo-50/30 hover:border-indigo-100 transition-all cursor-pointer group relative">
                            <input type="file" name="csv_file" class="absolute inset-0 opacity-0 cursor-pointer" onchange="this.form.submit()">
                            <span class="text-4xl block mb-4">📄</span>
                            <p class="text-sm font-bold text-slate-700">Clique para selecionar seu CSV</p>
                            <p class="text-[10px] text-slate-400 mt-2 uppercase">Data, Descrição, Valor, Tipo</p>
                        </div>
                    </form>
                </div>

                <div id="tabPaste" class="hidden space-y-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="import_paste">
                        <p class="text-[11px] text-slate-500 mb-2 leading-relaxed">Copies as linhas da sua planilha (incluindo Data, Formato, Pagador, CPF e Valor) e cole abaixo:</p>
                        <textarea name="paste_data" rows="8" class="w-full p-6 bg-slate-50 border border-slate-100 rounded-[2rem] text-xs font-mono outline-none focus:ring-4 focus:ring-indigo-50 transition-all placeholder:text-slate-300" placeholder="03/01/2026	Pix recebido	Camila..."></textarea>
                        <div class="pt-4 flex justify-end gap-3">
                            <button type="button" onclick="closeModal('importModal')" class="px-6 py-3 rounded-xl text-xs font-bold text-slate-400 hover:bg-slate-50">Cancelar</button>
                            <button type="submit" class="px-8 py-3 bg-slate-900 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-lg shadow-slate-200">Importar Dados</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Modal (React Style) -->
    <div id="confirmModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden z-[200] items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl p-8 border border-slate-100 animate-fade-in text-center">
            <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-6 text-2xl">⚠️</div>
            <h3 id="confirmTitle" class="text-xl font-black text-slate-900 mb-2">Excluir?</h3>
            <p id="confirmDesc" class="text-slate-500 text-sm mb-8">Esta ação não pode ser desfeita.</p>
            
            <form id="confirmForm" method="POST" style="display:none;">
                <input type="hidden" name="action" id="actionField">
                <input type="hidden" name="id" id="idField">
                <input type="hidden" name="month_val" id="monthValField">
            </form>
            <button type="submit" onclick="document.getElementById('confirmForm').submit();" class="w-full py-4 bg-rose-600 text-white rounded-2xl text-sm font-extrabold shadow-xl shadow-rose-100 hover:bg-rose-700 active:scale-95 transition-all">Sim, Confirmar</button>
            <button type="button" onclick="closeModal('confirmModal')" class="w-full py-3 text-slate-400 text-xs font-bold hover:text-slate-600 transition-all">Cancelar</button>
        </div>
    </div>

    <!-- Toast System -->
    <div id="toastContainer" class="fixed bottom-8 right-8 z-[300] pointer-events-none flex flex-col gap-3"></div>

    <script>
        const INITIAL_MESSAGE = '<?= $message ?>';
        const BRAIN_PATIENTS = <?= json_encode(array_values($pacientes)) ?>;
        
        function showToast(text, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `p-4 pr-10 rounded-2xl shadow-2xl border animate-fade-in pointer-events-auto flex items-center gap-3 min-w-[300px] transform transition-all duration-500 translate-y-0`;
            if(type === 'success') {
                toast.classList.add('bg-white', 'border-emerald-100', 'text-emerald-700');
                toast.innerHTML = `<span>✅</span> <p class="text-[11px] font-black uppercase tracking-wider">${text}</p>`;
            } else {
                toast.classList.add('bg-rose-600', 'border-rose-400', 'text-white');
                toast.innerHTML = `<span>⚠️</span> <p class="text-[11px] font-black uppercase tracking-wider">${text}</p>`;
            }
            
            document.getElementById('toastContainer').appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-4');
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        if(INITIAL_MESSAGE) showToast(INITIAL_MESSAGE);

        function triggerConfirm(title, desc, action, id = '', month_val = '') {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmDesc').textContent = desc;
            document.getElementById('actionField').value = action;
            document.getElementById('idField').value = id;
            document.getElementById('monthValField').value = month_val; // Set month_val for delete_month action
            openModal('confirmModal');
        }

        function openModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
            document.body.style.overflow = 'hidden';
            
            if(id === 'txModal') {
                const form = document.getElementById('mainForm');
                form.reset();
                document.getElementById('field_id').value = '';
                document.getElementById('field_is_edit').value = '';
                document.getElementById('field_date').value = new Date().toISOString().split('T')[0];
                document.getElementById('modalTitle').textContent = 'Novo Lançamento';
            }
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if(m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
                document.body.style.overflow = '';
            }
        }

        function setView(view) {
            const table = document.getElementById('viewTable');
            const grid = document.getElementById('viewGrid');
            const btnT = document.getElementById('btnViewTable');
            const btnG = document.getElementById('btnViewGrid');

            if (!table || !grid || !btnT || !btnG) return;

            if(view === 'table') {
                table.classList.remove('hidden');
                grid.classList.add('hidden');
                btnT.className = "px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all bg-white shadow-sm text-indigo-600";
                btnG.className = "px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-slate-500 hover:text-slate-700";
            } else {
                table.classList.add('hidden');
                grid.classList.remove('hidden');
                btnG.className = "px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all bg-white shadow-sm text-indigo-600";
                btnT.className = "px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-slate-500 hover:text-slate-700";
            }
            localStorage.setItem('psicogestao_view', view);
        }

        // Recuperar vista preferida
        const savedView = localStorage.getItem('psicogestao_view');
        if(savedView) setView(savedView);

        function editTx(t) {
            openModal('txModal');
            document.getElementById('modalTitle').textContent = 'Editar Lançamento';
            document.getElementById('field_is_edit').value = '1';
            document.getElementById('field_id').value = t.id;
            
            const form = document.getElementById('mainForm');
            form.elements['type'].value = t.type;
            form.elements['date'].value = t.date;
            form.elements['description'].value = t.description;
            form.elements['amount'].value = t.amount;
            form.elements['category'].value = t.category;
            form.elements['method'].value = t.method;
            form.elements['payerName'].value = t.payerName;
            form.elements['payerCpf'].value = t.payerCpf || '';
            form.elements['beneficiaryName'].value = t.beneficiaryName;
            form.elements['beneficiaryCpf'].value = t.beneficiaryCpf || '';
            form.elements['observation'].value = t.observation || '';

            // Lógica de Sincronização de Pagador
            const isSame = (t.beneficiaryName === t.payerName && (t.beneficiaryCpf || '') === (t.payerCpf || ''));
            document.getElementById('payerIsSame').checked = isSame;
            togglePayerFields();
        }

        function switchImportTab(tabId) {
            document.getElementById('tabFile').classList.add('hidden');
            document.getElementById('tabPaste').classList.add('hidden');
            document.getElementById(tabId).classList.remove('hidden');

            const btnFile = document.getElementById('btnTabFile');
            const btnPaste = document.getElementById('btnTabPaste');

            if(tabId === 'tabFile') {
                btnFile.className = "flex-1 py-3 text-xs font-bold rounded-xl transition-all bg-white shadow-sm text-indigo-600";
                btnPaste.className = "flex-1 py-3 text-xs font-bold rounded-xl transition-all text-slate-500 hover:text-slate-700";
            } else {
                btnPaste.className = "flex-1 py-3 text-xs font-bold rounded-xl transition-all bg-white shadow-sm text-indigo-600";
                btnFile.className = "flex-1 py-3 text-xs font-bold rounded-xl transition-all text-slate-500 hover:text-slate-700";
            }
        }

        function toggleSidebar() {
            const s = document.getElementById('mainSidebar');
            s.classList.toggle('sidebar-closed');
            s.classList.toggle('sidebar-open');
        }

        function togglePayerFields() {
            const isSame = document.getElementById('payerIsSame').checked;
            const section = document.getElementById('payerSection');
            if (isSame) {
                section.classList.add('hidden');
                syncPayer();
            } else {
                section.classList.remove('hidden');
            }
        }

        function syncPayer() {
            if (document.getElementById('payerIsSame').checked) {
                document.getElementById('field_payerName').value = document.getElementById('field_beneficiaryName').value;
                document.getElementById('field_payerCpf').value = document.getElementById('field_beneficiaryCpf').value;
            }
        }

        function updateValueFromCategory() {
            const select = document.getElementById('field_category');
            const selectedOption = select.options[select.selectedIndex];
            const defaultValue = selectedOption.getAttribute('data-value');
            
            if (defaultValue) {
                document.getElementById('field_amount').value = defaultValue;
            }
        }

        function resetStForm() {
            document.getElementById('st_id').value = '';
            document.getElementById('st_name').value = '';
            document.getElementById('st_value').value = '';
            document.getElementById('st_title').textContent = 'Configurar Serviço';
        }

        function editSt(st) {
            document.getElementById('st_id').value = st.id;
            document.getElementById('st_name').value = st.name;
            document.getElementById('st_value').value = st.default_value;
            document.getElementById('st_title').textContent = 'Editar Serviço';
            window.scrollTo({ top: document.getElementById('session_section').offsetTop - 100, behavior: 'smooth' });
        }

        function resetUserForm() {
            document.getElementById('u_id').value = '';
            document.getElementById('u_name').value = '';
            document.getElementById('u_email').value = '';
            document.getElementById('u_pass').value = '';
            document.getElementById('u_crp').value = '';
            document.getElementById('user_title').textContent = 'Criar Novo Usuário';
        }

        function editUser(u) {
            document.getElementById('u_id').value = u.id;
            document.getElementById('u_name').value = u.name;
            document.getElementById('u_email').value = u.email;
            document.getElementById('u_pass').value = u.password;
            document.getElementById('u_crp').value = u.crp || '';
            document.getElementById('user_title').textContent = 'Editar Usuário';
            window.scrollTo({ top: document.getElementById('user_section').offsetTop - 100, behavior: 'smooth' });
        }

        function openDuplicateModal(m, mName) {
            document.getElementById('dup_source_month').value = m;
            document.getElementById('dup_source_label').textContent = mName;
            
            // Sugerir o próximo mês como default no input date
            const parts = m.split('-');
            let year = parseInt(parts[0]);
            let month = parseInt(parts[1]);
            month++;
            if (month > 12) { month = 1; year++; }
            document.getElementById('dup_target_month').value = `${year}-${String(month).padStart(2, '0')}`;
            
            openModal('duplicateModal');
        }

        // --- Autocomplete Logic ---
        function handlePatientInput(input) {
            const query = input.value.toLowerCase().trim();
            const list = document.getElementById('patientList');
            const wrapper = document.getElementById('patientSuggestions');
            
            if (query.length < 2) {
                wrapper.classList.add('hidden');
                syncPayer();
                return;
            }

            const matches = BRAIN_PATIENTS.filter(p => p.nome.toLowerCase().includes(query));
            
            if (matches.length === 0) {
                wrapper.classList.add('hidden');
                syncPayer();
                return;
            }

            list.innerHTML = matches.map(p => `
                <li onclick='selectPatient(${JSON.stringify(p).replace(/'/g, "&apos;")})' class="px-5 py-3 hover:bg-slate-50 cursor-pointer flex items-center gap-3 transition-colors">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(p.nome)}&background=f1f5f9&color=6366f1" class="w-8 h-8 rounded-lg">
                    <div>
                        <p class="text-[13px] font-bold text-slate-900 leading-none">${p.nome}</p>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">${p.cpf || 'Sem CPF'}</p>
                    </div>
                </li>
            `).join('');
            
            wrapper.classList.remove('hidden');
            syncPayer();
        }

        function selectPatient(p) {
            document.getElementById('field_beneficiaryName').value = p.nome;
            document.getElementById('field_beneficiaryCpf').value = p.cpf || '';
            
            // Lógica inteligente de Pagador
            const hasDifferentPayer = (p.payerName && p.payerName !== p.nome) || (p.payerCpf && p.payerCpf !== p.cpf);
            
            if (hasDifferentPayer) {
                document.getElementById('payerIsSame').checked = false;
                document.getElementById('field_payerName').value = p.payerName;
                document.getElementById('field_payerCpf').value = p.payerCpf || '';
            } else {
                document.getElementById('payerIsSame').checked = true;
                document.getElementById('field_payerName').value = p.nome;
                document.getElementById('field_payerCpf').value = p.cpf || '';
            }
            
            togglePayerFields();
            document.getElementById('patientSuggestions').classList.add('hidden');
        }

        function toggleProfileMenu(e) {
            e.stopPropagation();
            const d = document.getElementById('profileDropdown');
            d.classList.toggle('hidden');
        }

        // Close Popups and Dropdowns on click outside
        window.addEventListener('click', (e) => {
            // Dropdown Autocomplete
            const suggestions = document.getElementById('patientSuggestions');
            if (suggestions && !suggestions.classList.contains('hidden') && !e.target.closest('#field_beneficiaryName')) {
                suggestions.classList.add('hidden');
            }

            // Modals
            const modals = ['txModal', 'importModal', 'confirmModal'];
            modals.forEach(id => {
                const m = document.getElementById(id);
                if (m && e.target === m) closeModal(id);
            });

            // Profile Dropdown
            const dropdown = document.getElementById('profileDropdown');
            if (dropdown && !dropdown.classList.contains('hidden') && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }

            // Mobile Sidebar
            const sidebar = document.getElementById('mainSidebar');
            if (window.innerWidth < 1024 && sidebar.classList.contains('sidebar-open') && !sidebar.contains(e.target) && !e.target.closest('button[onclick="toggleSidebar()"]')) {
                toggleSidebar();
            }
        });

        async function askAI() {
            const input = document.getElementById('aiQuestion');
            const btn = document.getElementById('btnAskAI');
            const chat = document.getElementById('aiChatContent');
            const question = input.value.trim();
            
            if(!question) return;

            // Add user message
            const userMsg = document.createElement('div');
            userMsg.className = "flex gap-4 justify-end";
            userMsg.innerHTML = `
                <div class="bg-slate-900 text-white p-5 rounded-3xl rounded-tr-none shadow-xl text-sm font-medium leading-relaxed max-w-[80%]">
                    ${question}
                </div>
                <div class="w-10 h-10 bg-slate-200 rounded-xl flex items-center justify-center text-slate-500 shrink-0 font-black">${'<?= $user_initials ?>'}</div>
            `;
            chat.appendChild(userMsg);
            input.value = '';
            chat.scrollTop = chat.scrollHeight;

            // Loading state
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch animate-spin"></i>';
            
            try {
                const formData = new FormData();
                formData.append('action', 'ask_ai');
                formData.append('question', question);

                const response = await fetch('index.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();

                const aiMsg = document.createElement('div');
                aiMsg.className = "flex gap-4 animate-fade-in";
                aiMsg.innerHTML = `
                    <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shrink-0 shadow-lg">AI</div>
                    <div class="bg-white p-5 rounded-3xl rounded-tl-none border border-slate-100 shadow-sm text-sm font-medium text-slate-700 leading-relaxed max-w-[80%] whitespace-pre-wrap">${data.answer || data.error}</div>
                `;
                chat.appendChild(aiMsg);
                chat.scrollTop = chat.scrollHeight;

            } catch (e) {
                showToast('Erro ao consultar IA', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane text-lg"></i>';
            }
        }

        // Permitir Enter para enviar
        document.getElementById('aiQuestion')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') askAI();
        });
    </script>
</body>
</html>
