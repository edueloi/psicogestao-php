<?php
/**
 * PsicoGestão - Sistema Unificado em PHP
 * Versão Refatorada - 100% Server-Side
 */
require_once __DIR__ . '/db.php';
session_start();

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
$user_email = $_SESSION['psicogestao_user'] ?? 'karen.l.s.gomes@gmail.com';

// 2. Configurações e Regras de Negócio
$TAB_SLUGS = [
    'dashboard' => 'Dashboard',
    'cashbook' => 'Livro Caixa',
    'reports' => 'Relatórios',
    'provisions' => 'Fiscal',
    'bi' => 'Análise BI'
];

$active_tab = $_GET['tab'] ?? 'dashboard';

$PROVISION_RULES = [
    ['name' => 'INSS Autônomo', 'percentage' => 20, 'base' => 'GROSS'],
    ['name' => 'IRPF Estimado', 'percentage' => 15, 'base' => 'NET'],
    ['name' => 'Reserva de Férias', 'percentage' => 8.33, 'base' => 'NET'],
    ['name' => 'Reserva 13º/Emergência', 'percentage' => 10, 'base' => 'NET']
];

// 3. Processamento de Ações (POST)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['id'] ?? uniqid('tx_', true);
        $type = ($_POST['type'] === 'EXPENSE') ? 'EXPENSE' : 'INCOME';
        
        $data = [
            'id' => $id,
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
            $stmt = $pdo->prepare('UPDATE transactions SET date=:date, description=:description, payerName=:payerName, payerCpf=:payerCpf, beneficiaryName=:beneficiaryName, beneficiaryCpf=:beneficiaryCpf, amount=:amount, type=:type, category=:category, method=:method, status=:status, receiptStatus=:receiptStatus, observation=:observation, tags=:tags WHERE id=:id');
            $stmt->execute($data);
            $message = "✅ Lançamento atualizado!";
        } else {
            $stmt = $pdo->prepare('INSERT INTO transactions (id, date, description, payerName, payerCpf, beneficiaryName, beneficiaryCpf, amount, type, category, method, status, receiptStatus, observation, tags) VALUES (:id, :date, :description, :payerName, :payerCpf, :beneficiaryName, :beneficiaryCpf, :amount, :type, :category, :method, :status, :receiptStatus, :observation, :tags)');
            $stmt->execute($data);
            $message = "✅ Novo lançamento gravado!";
        }
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $message = "🗑️ Lançamento excluído!";
    }

    if ($action === 'toggle-status') {
        $id = $_POST['id'] ?? '';
        $new_status = $_POST['new_status'] ?? 'PAID';
        $stmt = $pdo->prepare('UPDATE transactions SET status = ? WHERE id = ?');
        $stmt->execute([$new_status, $id]);
    }

    if ($action === 'repeat') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
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
            $placeholders = implode(',', array_map(fn($k) => ":$k", $keys));
            $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($placeholders)");
            $stmt->execute($row);
            $message = "🔁 Lançamento repetido para " . $dt->format('d/m/Y');
        }
    }

    if ($action === 'receipt') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare('UPDATE transactions SET receiptStatus = "ISSUED" WHERE id = ?');
        $stmt->execute([$id]);
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
                $stmt = $pdo->prepare('INSERT INTO transactions (id, date, description, amount, type, status, category, method) VALUES (?, ?, ?, ?, ?, "PAID", "Importado", "Outros")');
                $stmt->execute([$id, $date, $desc, $amount, $type]);
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

            // Lógica de Categorização Inteligente baseada no Valor
            $category = ($type === 'INCOME') ? 'Sessão Individual' : 'Despesa Geral';
            if ($type === 'INCOME') {
                if ($amount >= 360) $category = 'Pacote Semanal';
                else if ($amount >= 200) $category = 'Sessão Individual';
            }

            // Descrição Padrão baseada na categoria
            $description = $category;
            if ($obs_base && strlen($obs_base) < 60) $description = $obs_base;

            $id = uniqid('tx_', true);
            $stmt = $pdo->prepare('INSERT INTO transactions (id, date, description, payerName, payerCpf, beneficiaryName, beneficiaryCpf, amount, type, status, category, method, observation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "PAID", ?, ?, ?)');
            $stmt->execute([
                $id, 
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
        $stmt = $pdo->prepare('DELETE FROM transactions');
        $stmt->execute();
        $_SESSION['message'] = "🗑️ Banco de dados resetado!";
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'close_month') {
        $_SESSION['message'] = "✅ Período encerrado e consolidado com sucesso!";
        header("Location: index.php?tab=cashbook"); exit;
    }

    if ($action === 'delete_month') {
        $m = $_POST['month_val'] ?? ''; // YYYY-MM
        if ($m) {
            $stmt = $pdo->prepare('DELETE FROM transactions WHERE strftime("%Y-%m", date) = ?');
            $stmt->execute([$m]);
            $_SESSION['message'] = "🗑️ Todos os lançamentos de " . getMonthName($m) . " foram excluídos!";
        }
        header("Location: index.php?tab=cashbook"); exit;
    }
}

// 4. Mensagens Flash
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// 4. Busca de Dados e Filtros (LIVRO CAIXA)
$filter_month = $_GET['month'] ?? 'archive';
$filter_year = $_GET['year'] ?? date('Y');
$filter_type = $_GET['type'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// Filtros específicos para DASHBOARD
$dash_month = $_GET['dash_month'] ?? date('m');
$dash_year = $_GET['dash_year'] ?? date('Y');

$where = []; $params = [];
$where_dash = []; $params_dash = [];

// Dashboard Totals
if ($dash_month !== 'all') {
    $where_dash[] = "strftime('%m', date) = ? AND strftime('%Y', date) = ?";
    $params_dash[] = str_pad($dash_month, 2, '0', STR_PAD_LEFT);
    $params_dash[] = $dash_year;
} else {
    $where_dash[] = "strftime('%Y', date) = ?";
    $params_dash[] = $dash_year;
}

$sql_dash = "SELECT * FROM transactions";
if ($where_dash) $sql_dash .= " WHERE " . implode(" AND ", $where_dash);
$stmt = $pdo->prepare($sql_dash);
$stmt->execute($params_dash);
$totals_dashboard = calcTotals($stmt->fetchAll(PDO::FETCH_ASSOC));

// Previous Month for comparison
$prev_month_ts = strtotime(($dash_month === 'all' ? $dash_year : "$dash_year-$dash_month-01") . " -1 month");
$pm = date('m', $prev_month_ts);
$py = date('Y', $prev_month_ts);
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE strftime('%m', date) = ? AND strftime('%Y', date) = ?");
$stmt->execute([$pm, $py]);
$totals_prev = calcTotals($stmt->fetchAll(PDO::FETCH_ASSOC));

// Cashbook Filters
if ($filter_month === 'current') {
    $where[] = "strftime('%Y-%m', date) = ?";
    $params[] = date('Y-m');
} elseif ($filter_month === 'last') {
    $where[] = "strftime('%Y-%m', date) = ?";
    $params[] = date('Y-m', strtotime('-1 month'));
} elseif (preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $where[] = "strftime('%Y-%m', date) = ?";
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

$sql_all = "SELECT * FROM transactions ORDER BY date DESC, rowid DESC";
$all_tx = $pdo->query($sql_all)->fetchAll(PDO::FETCH_ASSOC);

$sql_filtered = "SELECT * FROM transactions";
if ($where) $sql_filtered .= " WHERE " . implode(" AND ", $where);
$sql_filtered .= " ORDER BY date DESC, rowid DESC";
$stmt = $pdo->prepare($sql_filtered);
$stmt->execute($params);
$filtered_tx = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Cálculos do Dashboard/Resumo
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

// 5. Agrupamento por Anos e Meses para a Vista de Archive
$stmt = $pdo->query('SELECT DISTINCT strftime("%Y", date) as y FROM transactions ORDER BY y DESC');
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);
if(empty($available_years)) $available_years[] = date('Y');

$stmt = $pdo->prepare('SELECT DISTINCT strftime("%Y-%m", date) as month FROM transactions WHERE strftime("%Y", date) = ? ORDER BY month DESC');
$stmt->execute([$filter_year]);
$available_months = $stmt->fetchAll(PDO::FETCH_COLUMN);
if(empty($available_months) && $filter_year == date('Y')) $available_months[] = date('Y-m');

$monthly_summaries = [];
foreach($available_months as $m) {
    if (!$m) continue;
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE strftime("%Y-%m", date) = ?');
    $stmt->execute([$m]);
    $monthly_summaries[$m] = calcTotals($stmt->fetchAll(PDO::FETCH_ASSOC));
}

$totals_all = calcTotals($all_tx);
$totals_filtered = calcTotals($filtered_tx);

// Helpers de formatação
function fmtBRL($val) { return 'R$ ' . number_format($val, 2, ',', '.'); }
function fmtDateBR($iso) { return date('d/m/Y', strtotime($iso)); }

function getMonthName($m) {
    $parts = explode('-', $m);
    $months = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    return $months[(int)$parts[1]-1] . ' ' . $parts[0];
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
    <title>PsicoGestão - Dra. Karen Gomes</title>
    <!-- Tailwind CSS para Estética Premium -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        .sidebar-item-active { background: #4f46e5; color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.2); }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .animate-fade-in { animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="text-slate-900 overflow-hidden">
    
    <div class="flex h-screen overflow-hidden">
        
        <!-- Sidebar -->
        <aside class="w-72 bg-white border-r border-slate-200 flex flex-col shrink-0 z-50">
            <div class="p-8 border-b border-slate-100 flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white text-xl shadow-lg ring-4 ring-indigo-50">Ψ</div>
                <div>
                    <h1 class="font-extrabold text-lg leading-none tracking-tight">Psico<span class="text-indigo-600">Gestão</span></h1>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-1">Dra. Karen Gomes</p>
                </div>
            </div>

            <nav class="flex-1 p-6 space-y-2 overflow-y-auto scrollbar-hide">
                <p class="px-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">Módulos</p>
                
                <a href="?tab=dashboard" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='dashboard' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="text-lg relative z-10">📊</span>
                    <span class="text-[13px] font-bold relative z-10">Dashboard</span>
                    <?php if($active_tab=='dashboard'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <a href="?tab=cashbook" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='cashbook' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="text-lg relative z-10">📒</span>
                    <span class="text-[13px] font-bold relative z-10">Livro Caixa</span>
                    <?php if($active_tab=='cashbook'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <a href="?tab=reports" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='reports' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600' ?>">
                    <span class="text-lg relative z-10">🧠</span>
                    <span class="text-[13px] font-bold relative z-10">AI Insights</span>
                    <?php if($active_tab=='reports'): ?> <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-indigo-700 opacity-100"></div> <?php endif; ?>
                </a>

                <div class="pt-6 mt-4 border-t border-slate-50">
                    <p class="px-4 text-[9px] font-black text-slate-300 uppercase tracking-[0.2em] mb-3">Administração</p>
                    <a href="?tab=provisions" class="flex items-center gap-3.5 px-5 py-3 rounded-2xl transition-all group relative overflow-hidden <?= $active_tab=='provisions' ? 'sidebar-item-active text-white' : 'text-slate-500 hover:bg-slate-50' ?>">
                        <span class="text-lg relative z-10">⚖️</span>
                        <span class="text-[13px] font-bold relative z-10">Fiscal & Tributário</span>
                        <?php if($active_tab=='provisions'): ?> <div class="absolute inset-0 bg-gradient-to-r from-slate-800 to-slate-900 opacity-100"></div> <?php endif; ?>
                    </a>
                </div>
            </nav>

            <div class="p-6 border-t border-slate-100">
                <div class="bg-indigo-600 rounded-3xl p-5 text-white shadow-xl relative overflow-hidden group">
                    <div class="relative z-10">
                        <p class="text-[9px] font-black text-indigo-200 uppercase tracking-widest mb-1">Status do Mês</p>
                        <p class="text-sm font-bold"><?= fmtBRL($totals_all['liquid']) ?> Líquido</p>
                        <div class="w-full bg-white/20 h-1.5 rounded-full mt-3 overflow-hidden">
                            <div class="bg-white h-full" style="width: 75%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col min-w-0">
            
            <!-- Top Bar -->
            <header class="h-20 bg-white/80 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-10 sticky top-0 z-40">
                <div class="flex items-center gap-4">
                    <h2 class="text-lg font-extrabold text-slate-800 tracking-tight"><?= $TAB_SLUGS[$active_tab] ?></h2>
                </div>

                <div class="flex items-center gap-6">
                    <div class="h-6 w-px bg-slate-200"></div>
                    <div class="flex items-center gap-3">
                        <div class="relative group">
                            <button class="flex items-center gap-3 px-3 py-2 rounded-2xl hover:bg-slate-50 transition-all group">
                                <div class="text-right hidden sm:block">
                                    <p class="text-[11px] font-black text-slate-900 leading-none">Dra. Karen Lais</p>
                                    <p class="text-[9px] text-emerald-500 font-bold uppercase mt-1 tracking-widest">Online</p>
                                </div>
                                <img src="https://ui-avatars.com/api/?name=Karen+Gomes&background=4f46e5&color=fff" class="w-9 h-9 rounded-xl ring-2 ring-indigo-50 group-hover:ring-indigo-100 transition-all shadow-sm">
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div class="absolute right-0 mt-2 w-64 bg-white rounded-[2rem] shadow-2xl border border-slate-100 py-3 hidden group-hover:block z-50 animate-fade-in origin-top-right">
                                <div class="px-6 py-4 border-b border-slate-50">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Conta Ativa</p>
                                    <p class="text-xs font-bold text-slate-800 truncate"><?= $user_email ?></p>
                                </div>
                                
                                <div class="py-2">
                                    <a href="#" class="flex items-center gap-3 px-6 py-3 text-[11px] font-bold text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-all uppercase tracking-wider">
                                        <span class="text-sm">👤</span> Meu Perfil
                                    </a>
                                    <a href="#" class="flex items-center gap-3 px-6 py-3 text-[11px] font-bold text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-all uppercase tracking-wider">
                                        <span class="text-sm">⚙️</span> Configurações
                                    </a>
                                    <a href="#" class="flex items-center gap-3 px-6 py-3 text-[11px] font-bold text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-all uppercase tracking-wider">
                                        <span class="text-sm">🎧</span> Suporte VIP
                                    </a>
                                </div>

                                <div class="mt-2 pt-2 border-t border-slate-50">
                                    <a href="?logout=1" class="flex items-center gap-3 px-6 py-3 text-[11px] font-black text-rose-500 hover:bg-rose-50 transition-all uppercase tracking-widest">
                                        <span class="text-sm">🚪</span> Sair do Sistema
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Container -->
            <div class="flex-1 overflow-y-auto p-8 animate-fade-in custom-scrollbar">
                
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
                                    $mon_names = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
                                    foreach($mon_names as $idx => $name): ?>
                                        <option value="<?= $idx+1 ?>" <?= (int)$dash_month == $idx+1 ? 'selected' : '' ?>><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="dash_year" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-[10px] font-black uppercase tracking-widest outline-none cursor-pointer hover:bg-slate-100 transition-all">
                                    <?php foreach($available_years as $y): ?>
                                        <option value="<?= $y ?>" <?= $dash_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="w-10 h-10 bg-indigo-600 text-white rounded-xl flex items-center justify-center shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all">🔍</button>
                            </form>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <?php 
                            $diff = $totals_dashboard['liquid'] - $totals_prev['liquid'];
                            $diff_pct = $totals_prev['liquid'] > 0 ? ($diff / $totals_prev['liquid']) * 100 : 0;

                            $cards = [
                                ['Faturamento Bruto', $totals_dashboard['income'], 'Entradas', 'indigo', '🎯'],
                                ['Despesas Operacionais', $totals_dashboard['expense'], 'Saídas', 'rose', '📉'],
                                ['Provisões Fiscais', $totals_dashboard['total_prov'], 'Reservas', 'amber', '⚖️'],
                                ['Lucro Líquido Real', $totals_dashboard['liquid'], 'Disponível', 'emerald', '💎', true]
                            ];
                            foreach($cards as $c): ?>
                                <div class="<?= $c[5] ?? false ? 'bg-slate-900 text-white shadow-2xl scale-[1.05] z-10' : 'bg-white text-slate-900 border-slate-100' ?> p-8 rounded-[3rem] border shadow-sm relative overflow-hidden group hover:-translate-y-1 transition-all">
                                    <div class="flex justify-between items-start mb-6">
                                        <div class="w-12 h-12 rounded-2xl <?= $c[5] ?? false ? 'bg-white/10' : 'bg-slate-50 text-slate-400' ?> flex items-center justify-center text-xl"><?= $c[4] ?></div>
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
                                        <h3 class="text-xl font-black text-slate-900 flex items-center gap-3"><span>📊</span> Gráfico de Performance</h3>
                                        <span class="text-[10px] font-black text-indigo-500 bg-indigo-50 px-4 py-2 rounded-full uppercase tracking-widest">Realizado em <?= $dash_year ?></span>
                                    </div>
                                    <div class="h-80 flex flex-col items-center justify-center border-2 border-dashed border-slate-50 rounded-[2.5rem] text-slate-300 italic group hover:border-indigo-100 transition-all cursor-pointer">
                                        <p class="text-3xl mb-4 group-hover:scale-125 transition-transform duration-500">📈</p>
                                        <p class="font-black text-[12px] uppercase tracking-widest group-hover:text-indigo-600 transition-all">Visualização Avançada em breve</p>
                                        <p class="text-[10px] mt-2 opacity-60">Prepare-se para insights profundos em tempo real</p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-[3rem] border border-slate-100 p-10 shadow-sm relative overflow-hidden">
                                <h3 class="text-xl font-black text-slate-900 mb-8 flex items-center gap-3"><span>⚖️</span> Impostos & Prov</h3>
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
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                                    <?php foreach($monthly_summaries as $m => $summary): ?>
                                        <div class="bg-white rounded-[3rem] border border-slate-200 p-10 shadow-sm hover:shadow-2xl transition-all group overflow-hidden relative active:scale-[0.98]">
                                        <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-50 rounded-full -mr-16 -mt-16 blur-3xl opacity-50 group-hover:bg-indigo-100 transition-all"></div>
                                        
                                        <div class="relative z-10">
                                            <div class="flex justify-between items-start mb-3">
                                                <p class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.2em]">CONSOLIDADO</p>
                                                <button onclick="triggerConfirm('Excluir Mês', 'Deseja apagar TODOS os lançamentos de <?= getMonthName($m) ?>? Esta ação é irreversível.', 'delete_month', '', '<?= $m ?>')" class="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-full opacity-0 group-hover:opacity-100 transition-all hover:bg-rose-500 hover:text-white" title="Excluir Mês Inteiro">
                                                    <span class="text-xs">🗑️</span>
                                                </button>
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
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Painel de Detalhamento</p>
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
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-200 shadow-sm flex items-center gap-5 group hover:shadow-lg transition-all">
                                    <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform">📈</div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.1em] mb-1">Entradas Totais</p>
                                        <p class="text-xl font-black text-slate-900"><?= fmtBRL($totals_filtered['income']) ?></p>
                                    </div>
                                </div>
                                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-200 shadow-sm flex items-center gap-5 group hover:shadow-lg transition-all">
                                    <div class="w-12 h-12 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform">📉</div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.1em] mb-1">Saídas Totais</p>
                                        <p class="text-xl font-black text-slate-900"><?= fmtBRL($totals_filtered['expense']) ?></p>
                                    </div>
                                </div>
                                <div class="bg-slate-900 p-6 rounded-[2.5rem] shadow-xl shadow-slate-200 flex items-center gap-5 group border border-slate-800">
                                    <div class="w-12 h-12 bg-white/10 text-white rounded-2xl flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform">💰</div>
                                    <div>
                                        <p class="text-[10px] font-black text-white/40 uppercase tracking-[0.1em] mb-1">Saldo Líquido</p>
                                        <p class="text-xl font-black text-white"><?= fmtBRL($totals_filtered['net']) ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Barra de Ações Central -->
                            <div class="bg-white p-4 rounded-[2.5rem] border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <button onclick="triggerConfirm('Fechar Mês', 'Fechar lançamentos deste mês?', 'close_month')" class="h-11 px-5 bg-emerald-500 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-md shadow-emerald-50">Encerrar Período</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button onclick="openModal('importModal')" class="h-11 px-5 bg-indigo-50 text-indigo-600 rounded-2xl border border-indigo-100 hover:bg-indigo-100 transition-all text-[11px] font-black uppercase tracking-widest flex items-center gap-2">
                                        <span>📥</span> Importar Dados
                                    </button>
                                    <button onclick="openModal('txModal')" class="h-11 px-6 bg-slate-900 text-white rounded-2xl text-[11px] font-black uppercase tracking-widest shadow-lg hover:bg-slate-800 transition-all flex items-center gap-2">
                                        <span>+</span> Novo Lançamento
                                    </button>
                                </div>
                            </div>

                            <!-- Filtros e Busca -->
                            <form class="bg-slate-50/50 p-3 rounded-[2rem] border border-slate-200/60 flex flex-col md:flex-row gap-3 items-center">
                                <input type="hidden" name="tab" value="cashbook">
                                <input type="hidden" name="month" value="<?= htmlspecialchars($_GET['month']) ?>">
                                <div class="flex-1 relative w-full">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
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
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Data</th>
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Descrição & Categoria</th>
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Paciente / Pagador</th>
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Valor</th>
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                                                <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Ações</th>
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
                                                <tr class="hover:bg-slate-50/50 transition-all">
                                                    <td class="px-8 py-6 text-sm text-slate-500 font-bold"><?= fmtDateBR($t['date']) ?></td>
                                                    <td class="px-8 py-6">
                                                        <p class="text-sm font-black text-slate-900"><?= htmlspecialchars($t['description']) ?></p>
                                                        <div class="flex gap-2 mt-1.5">
                                                            <span class="text-[9px] font-black px-2 py-0.5 rounded bg-slate-100 text-slate-500 uppercase"><?= htmlspecialchars($t['method']) ?></span>
                                                            <span class="text-[9px] font-black px-2 py-0.5 rounded bg-indigo-50 text-indigo-500 uppercase"><?= htmlspecialchars($t['category']) ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="px-8 py-6">
                                                        <div class="space-y-1">
                                                            <div class="flex items-center gap-2">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                                                                <p class="text-[11px] font-bold text-slate-700">Pág: <span class="font-medium text-slate-500"><?= htmlspecialchars($t['payerName']) ?: 'N/A' ?></span></p>
                                                            </div>
                                                            <?php if($t['payerCpf']): ?>
                                                                <p class="text-[9px] font-bold text-slate-400 ml-3.5 italic"><?= htmlspecialchars($t['payerCpf']) ?></p>
                                                            <?php endif; ?>
                                                            
                                                            <div class="flex items-center gap-2 mt-1">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-400"></span>
                                                                <p class="text-[11px] font-bold text-indigo-600">Pac: <span class="font-medium text-indigo-400"><?= htmlspecialchars($t['beneficiaryName']) ?: 'N/A' ?></span></p>
                                                            </div>
                                                            <?php if($t['beneficiaryCpf']): ?>
                                                                <p class="text-[9px] font-bold text-indigo-300 ml-3.5 italic"><?= htmlspecialchars($t['beneficiaryCpf']) ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-8 py-6 text-right whitespace-nowrap">
                                                        <p class="text-lg font-black <?= $amt_color ?>"><?= $is_income ? '+' : '-' ?> <?= fmtBRL($t['amount']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-6">
                                                        <div class="flex flex-col gap-2 scale-90">
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="toggle-status">
                                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $t['status']=='PAID' ? 'PENDING' : 'PAID' ?>">
                                                                <button type="submit" class="w-full text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-xl border <?= $t['status']=='PAID' ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-amber-50 text-amber-600 border-amber-100' ?>">
                                                                    <?= $t['status'] == 'PAID' ? '✅ Pago' : '⏳ Pendente' ?>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                    <td class="px-8 py-6 text-right whitespace-nowrap">
                                                        <div class="flex justify-end gap-2 text-lg">
                                                            <button onclick="editTx(<?= htmlspecialchars(json_encode($t)) ?>)" class="w-8 h-8 flex items-center justify-center bg-slate-50 rounded-xl hover:bg-indigo-600 hover:text-white text-slate-400 transition-all shadow-sm">✏️</button>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="action" value="repeat">
                                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                                <button type="submit" class="w-8 h-8 flex items-center justify-center bg-slate-50 rounded-xl hover:bg-emerald-500 hover:text-white text-slate-400 transition-all shadow-sm">🔁</button>
                                                            </form>
                                                            <button onclick="triggerConfirm('Excluir?', 'Este registro será removido.', 'delete', '<?= $t['id'] ?>')" class="w-8 h-8 flex items-center justify-center bg-slate-50 rounded-xl hover:bg-rose-500 hover:text-white text-slate-400 transition-all shadow-sm">🗑️</button>
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
                                                <div class="w-12 h-12 <?= $is_income ? 'bg-emerald-50 text-emerald-600 shadow-emerald-100' : 'bg-rose-50 text-rose-600 shadow-rose-100' ?> rounded-2xl flex items-center justify-center text-xl shadow-inner"><?= $is_income ? '📈' : '📉' ?></div>
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
                                                            <span class="text-[9px] font-black text-slate-300"><?= $t['payerCpf'] ?: '' ?></span>
                                                        </div>
                                                        <div class="flex justify-between items-start border-t border-slate-100 pt-2">
                                                            <div class="flex items-center gap-2">
                                                                <div class="w-1.5 h-1.5 rounded-full bg-indigo-500"></div>
                                                                <p class="text-[10px] font-black text-indigo-600 truncate"><?= htmlspecialchars($t['beneficiaryName']) ?: 'N/A' ?></p>
                                                            </div>
                                                            <span class="text-[9px] font-black text-indigo-300"><?= $t['beneficiaryCpf'] ?: '' ?></span>
                                                        </div>
                                                    </div>
                                                </div>
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
                                                        <?= $t['status'] == 'PAID' ? '✓ Pago' : '⏳ Pendente' ?>
                                                    </button>
                                                </form>

                                                <div class="flex gap-2">
                                                    <button onclick="editTx(<?= htmlspecialchars(json_encode($t)) ?>)" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-indigo-600 hover:text-indigo-600 transition-all text-xs shadow-sm">✏️</button>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="repeat">
                                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-emerald-500 hover:text-emerald-500 transition-all text-xs shadow-sm">🔁</button>
                                                    </form>
                                                    <button onclick="triggerConfirm('Excluir?', 'Este registro será removido.', 'delete', '<?= $t['id'] ?>')" class="w-8 h-8 flex items-center justify-center bg-white rounded-lg border border-slate-200 text-slate-400 hover:border-rose-500 hover:text-rose-500 transition-all text-xs shadow-sm">🗑️</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($active_tab === 'reports'): 
                    // Lógica de Inteligência Financeira (SIMULAÇÃO DE IA COM DADOS REAIS)
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
                    
                    // Top Paciente
                    $pacientes = [];
                    foreach($all_tx as $t) {
                        if($t['type'] === 'INCOME' && $t['beneficiaryName']) {
                            $pacientes[$t['beneficiaryName']] = ($pacientes[$t['beneficiaryName']] ?? 0) + $t['amount'];
                        }
                    }
                    arsort($pacientes);
                    $top_paciente = !empty($pacientes) ? [key($pacientes), current($pacientes)] : ['Nenhum', 0];
                ?>
                    <div class="max-w-7xl mx-auto space-y-8 pb-20">
                        <!-- AI Header -->
                        <div class="bg-slate-900 rounded-[3rem] p-10 text-white relative overflow-hidden shadow-2xl shadow-slate-200">
                            <div class="absolute top-0 right-0 w-96 h-96 bg-indigo-600 rounded-full -mr-32 -mt-32 blur-[100px] opacity-40"></div>
                            <div class="absolute bottom-0 left-0 w-64 h-64 bg-violet-600 rounded-full -ml-32 -mb-32 blur-[80px] opacity-20"></div>
                            
                            <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-8">
                                <div class="max-w-xl">
                                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-indigo-500/20 border border-indigo-500/30 text-indigo-400 text-[10px] font-black uppercase tracking-widest mb-6">
                                        <span class="relative flex h-2 w-2">
                                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                          <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                                        </span>
                                        AI Financial Brain Ativo
                                    </div>
                                    <h2 class="text-4xl font-black mb-4 leading-tight">Olá, Dra. Karen. <br> Analisei seus números hoje.</h2>
                                    <p class="text-slate-400 text-sm leading-relaxed font-medium">Com base nos seus lançamentos recentes, identifiquei um crescimento de <span class="text-emerald-400 font-bold"><?= number_format($growth, 1) ?>%</span> em relação ao mês anterior. Sua lucratividade líquida está em <span class="text-indigo-400 font-bold"><?= number_format($profit_margin, 1) ?>%</span>.</p>
                                </div>
                                <div class="flex gap-4">
                                    <div class="bg-white/5 border border-white/10 p-6 rounded-[2.5rem] backdrop-blur-md text-center">
                                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-2">Score de Saúde</p>
                                        <div class="text-5xl font-black text-emerald-400">9.4</div>
                                        <p class="text-[9px] font-bold text-slate-400 mt-2">NÍVEL EXCELENTE</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- AI Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <!-- Card Insight 1 -->
                            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
                                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-xl mb-6 shadow-inner ring-1 ring-emerald-100">💰</div>
                                <h4 class="text-lg font-black text-slate-800 mb-2">Ponto de Equilíbrio</h4>
                                <p class="text-xs text-slate-500 leading-relaxed font-medium italic">"Dra, você já cobriu 100% das suas despesas fixas com <?= number_format(($totals_all['expense'] / max(1, $totals_all['income'])) * 100, 0) ?>% do seu faturamento."</p>
                            </div>

                            <!-- Card Insight 2 -->
                            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
                                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center text-xl mb-6 shadow-inner ring-1 ring-indigo-100">🌟</div>
                                <h4 class="text-lg font-black text-slate-800 mb-2">Top Performance</h4>
                                <p class="text-xs text-slate-500 leading-relaxed font-medium">Seu paciente com maior faturamento acumulado é <span class="font-black text-slate-800"><?= $top_paciente[0] ?></span>, totalizando <?= fmtBRL($top_paciente[1]) ?>.</p>
                            </div>

                            <!-- Card Insight 3 -->
                            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
                                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-xl mb-6 shadow-inner ring-1 ring-amber-100">⚖️</div>
                                <h4 class="text-lg font-black text-slate-800 mb-2">Retenção Fiscal</h4>
                                <p class="text-xs text-slate-500 leading-relaxed font-medium">Recomendamos reservar <span class="font-black text-amber-600"><?= fmtBRL($totals_all['total_prov']) ?></span> para obrigações fiscais deste período para evitar surpresas no IRPF.</p>
                            </div>
                        </div>

                        <!-- Data Deep Dive -->
                        <div class="bg-white rounded-[3rem] border border-slate-200 overflow-hidden shadow-sm">
                            <div class="p-8 border-b border-slate-100 bg-slate-50/50 flex flex-col md:flex-row justify-between items-center gap-4">
                                <div>
                                    <h3 class="text-xl font-black text-slate-900 tracking-tight">Análise Estrutural do Caixa</h3>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Comparativo de Eficiência Operacional</p>
                                </div>
                                <button class="px-6 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-black uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all flex items-center gap-2">
                                    <span>📄</span> Exportar PDF Inteligente
                                </button>
                            </div>
                            <div class="p-10">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                                    <div class="space-y-6">
                                        <div class="flex justify-between items-end mb-2">
                                            <p class="text-xs font-black text-slate-900 uppercase tracking-widest">Margem de Lucro Bruta</p>
                                            <p class="text-xl font-black text-indigo-600"><?= number_format($profit_margin, 1) ?>%</p>
                                        </div>
                                        <div class="h-4 bg-slate-100 rounded-full overflow-hidden flex">
                                            <div class="bg-indigo-600 h-full transition-all duration-1000" style="width: <?= $profit_margin ?>%"></div>
                                        </div>
                                        <p class="text-[10px] text-slate-400 font-bold leading-relaxed italic">Sua margem está saudável. Consultórios de alto padrão costumam operar entre 65% e 80% de lucro líquido após impostos.</p>
                                    </div>
                                    <div class="bg-slate-50 rounded-[2rem] p-8 border border-slate-100">
                                        <h5 class="text-sm font-black text-slate-800 mb-6 uppercase tracking-[0.2em] opacity-40">IA Brain: Sugestões de Otimização</h5>
                                        <ul class="space-y-4">
                                            <li class="flex items-start gap-4">
                                                <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-[10px]">✓</span>
                                                <p class="text-[11px] font-bold text-slate-600 leading-relaxed">Você possui <?= count($pacientes) ?> pacientes ativos. Tente diversificar as fontes caso 50% da renda venha de apenas 3 pessoas.</p>
                                            </li>
                                            <li class="flex items-start gap-4">
                                                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-[10px]">!</span>
                                                <p class="text-[11px] font-bold text-slate-600 leading-relaxed">Identificamos que <?= number_format(($totals_all['total_prov'] / max(1, $totals_all['income'])) * 100, 0) ?>% sairá para impostos. Considere investir em previdência privada para dedução fiscal.</p>
                                            </li>
                                        </ul>
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
    <div id="txModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md hidden z-[100] items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-[3rem] shadow-2xl overflow-hidden animate-fade-in border border-slate-200">
            <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
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
                        <select name="method" id="field_method" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold outline-none">
                            <option value="Pix">PIX (Mais comum)</option>
                            <option value="Cartão">Cartão de Débito/Crédito</option>
                            <option value="Dinheiro">Dinheiro Espécie</option>
                            <option value="TED">Transferência Bancária</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Nome do Paciente</label>
                        <input type="text" name="beneficiaryName" id="field_beneficiaryName" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">CPF Paciente</label>
                        <input type="text" name="beneficiaryCpf" id="field_beneficiaryCpf" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Pagador (Responsável)</label>
                        <input type="text" name="payerName" id="field_payerName" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">CPF Pagador</label>
                        <input type="text" name="payerCpf" id="field_payerCpf" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium outline-none">
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
            form.elements['method'].value = t.method;
            form.elements['payerName'].value = t.payerName;
            form.elements['payerCpf'].value = t.payerCpf || '';
            form.elements['beneficiaryName'].value = t.beneficiaryName;
            form.elements['beneficiaryCpf'].value = t.beneficiaryCpf || '';
            form.elements['observation'].value = t.observation || '';
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

        // Close Popups on click outside
        window.addEventListener('click', (e) => {
            const modals = ['txModal', 'importModal'];
            modals.forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) closeModal(id);
            });
        });
    </script>
</body>
</html>
