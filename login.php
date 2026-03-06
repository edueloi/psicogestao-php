<?php
// login.php
require __DIR__ . '/db.php';
session_start();

const AUTH_USER = 'karen.l.s.gomes@gmail.com';

// Se já estiver autenticado, redireciona para a home
if (!empty($_SESSION['psicogestao_auth']) && $_SESSION['psicogestao_auth'] === true) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim($_POST['username'] ?? '');
  $pass = trim($_POST['password'] ?? '');

  // Mesma lógica do api.php
  if ($user === 'karen.l.s.gomes@gmail.com' && $pass === 'Bibia.0110') {
    session_regenerate_id(true);
    $_SESSION['psicogestao_auth'] = true;
    $_SESSION['psicogestao_user'] = 'karen.l.s.gomes@gmail.com';
    header('Location: index.php');
    exit;
  } else {
    $error = 'E-mail ou senha incorretos.';
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - PsicoGestão</title>
  <link rel="icon" type="image/png" href="favicon_psicogestao_logo_1772840774779.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    .glass-card { 
      background: rgba(255, 255, 255, 0.8); 
      backdrop-filter: blur(12px); 
      border: 1px solid rgba(255, 255, 255, 0.4); 
    }
    .animate-float {
        animation: float 6s ease-in-out infinite;
    }
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-20px); }
        100% { transform: translateY(0px); }
    }
  </style>
</head>
<body class="bg-white min-h-screen flex overflow-hidden">

  <!-- LADO ESQUERDO: LOGIN (40%) -->
  <div class="w-full lg:w-[40%] flex flex-col justify-center px-8 md:px-16 lg:px-24 bg-white z-10 relative">
    <div class="max-w-md w-full mx-auto">
        <!-- Logo -->
        <div class="flex items-center gap-3 mb-12">
            <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-2xl font-black shadow-xl shadow-indigo-100 ring-4 ring-indigo-50">Ψ</div>
            <div>
                <h1 class="font-extrabold text-xl leading-none tracking-tight">Psico<span class="text-indigo-600">Gestão</span></h1>
                <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-1">Gestão Individualizada</p>
            </div>
        </div>

        <div class="mb-10">
            <h2 class="text-3xl font-black text-slate-900 tracking-tight mb-3">Bem-vinda, Karen.</h2>
            <p class="text-slate-500 font-medium italic">"A organização é a base para o cuidado de excelência."</p>
        </div>

        <form action="login.php" method="POST" class="space-y-6">
            <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-100 p-4 rounded-2xl text-[12px] text-rose-700 font-bold flex items-center gap-3 animate-pulse">
                    <i class="fa-solid fa-circle-xmark"></i> <span><?= $error ?></span>
                </div>
            <?php endif; ?>

            <div class="space-y-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">E-mail Profissional</label>
                <div class="relative group">
                    <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 text-sm group-focus-within:text-indigo-600 transition-colors italic">@</span>
                    <input name="username" type="email" value="karen.l.s.gomes@gmail.com" class="w-full pl-12 pr-5 py-4 border border-slate-100 rounded-2xl bg-slate-50 focus:bg-white focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all text-sm font-bold outline-none" required>
                </div>
            </div>

            <div class="space-y-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Senha de Acesso</label>
                <div class="relative group">
                    <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 text-sm group-focus-within:text-indigo-600 transition-colors"><i class="fa-solid fa-lock"></i></span>
                    <input id="password-field" name="password" type="password" placeholder="••••••••" class="w-full pl-12 pr-14 py-4 border border-slate-100 rounded-2xl bg-slate-50 focus:bg-white focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all text-sm font-bold outline-none" required>
                    <button type="button" onclick="togglePassword()" class="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-indigo-600 transition-colors">
                        <i id="eye-icon" class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between px-1">
                <label class="flex items-center gap-2 cursor-pointer group">
                    <input type="checkbox" checked class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 transition-all">
                    <span class="text-[11px] text-slate-400 font-bold group-hover:text-slate-600 transition-colors">Manter conectado</span>
                </label>
            </div>

            <button type="submit" class="w-full py-5 rounded-2xl bg-indigo-600 text-white font-black text-xs uppercase tracking-[0.2em] hover:bg-slate-900 shadow-2xl shadow-indigo-100 transition-all active:scale-[0.97] flex items-center justify-center gap-3 group">
                Acessar seu Painel
                <span class="text-xl group-hover:translate-x-1 transition-transform">→</span>
            </button>
        </form>

        <p class="mt-12 text-center text-slate-300 text-[10px] font-bold uppercase tracking-widest">
            Exclusivo para Karen Gomes • Versão Profissional
        </p>
    </div>
  </div>

  <!-- LADO DIREITO: DASHBOARD PREVIEW / PERSONAL BRANDING (60%) -->
  <div class="hidden lg:flex w-[60%] relative bg-indigo-600 items-center justify-center overflow-hidden">
    <!-- Imagem de Fundo Gerada -->
    <img src="login_background_artwork_1772840596105.png" class="absolute inset-0 w-full h-full object-cover mix-blend-overlay opacity-50 scale-110 animate-pulse duration-[10s]" alt="Artwork">
    
    <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/40 via-transparent to-slate-900/60"></div>
    
    <div class="relative z-10 w-full max-w-2xl px-12">
        <div class="mb-12">
            <span class="inline-block px-4 py-2 bg-white/10 backdrop-blur-md rounded-full text-white text-[10px] font-black uppercase tracking-[0.3em] mb-6">Área Restrita</span>
            <h2 class="text-6xl font-black text-white leading-tight tracking-tighter mb-6">Gestão Clínica <br><span class="text-indigo-300">Inteligente.</span></h2>
            <p class="text-xl text-indigo-100/80 font-medium leading-relaxed max-w-lg">Plataforma personalizada para centralização de fluxo financeiro, relatórios de performance e gestão estratégica de pacientes.</p>
        </div>

        <!-- Perfil da Karen -->
        <div class="glass-card mb-8 p-8 rounded-[2.5rem] flex items-center gap-6 border border-white/20">
            <div class="w-20 h-20 rounded-2xl bg-slate-900 flex items-center justify-center text-white text-3xl shadow-xl font-black ring-4 ring-white/10">KG</div>
            <div>
                <h3 class="text-2xl font-black text-slate-900">Karen Gomes</h3>
                <p class="text-indigo-600 font-extrabold text-sm uppercase tracking-widest">Psicóloga Clínica e TCC</p>
                <p class="text-slate-500 font-bold text-xs mt-1">CRP 06/172315</p>
            </div>
        </div>

        <!-- Cards Flutuantes de Features -->
        <div class="grid grid-cols-2 gap-6">
            <div class="glass-card p-8 rounded-[2.5rem] shadow-2l animate-float" style="animation-delay: 0s;">
                <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-xl mb-4"><i class="fa-solid fa-chart-line"></i></div>
                <h4 class="font-black text-slate-900 text-sm mb-2 uppercase tracking-tight">Performance</h4>
                <p class="text-slate-500 text-xs leading-relaxed font-medium">Dashboard consolidado com métricas reais do seu faturamento.</p>
            </div>
            
            <div class="glass-card p-8 rounded-[2.5rem] shadow-2xl animate-float" style="animation-delay: 1.5s;">
                <div class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center text-white text-xl mb-4"><i class="fa-solid fa-file-import"></i></div>
                <h4 class="font-black text-slate-900 text-sm mb-2 uppercase tracking-tight">Importação</h4>
                <p class="text-slate-500 text-xs leading-relaxed font-medium">Sincronização otimizada de sessões e pagamentos em lote.</p>
            </div>
        </div>
    </div>
  </div>

  <script>
    function togglePassword() {
        const field = document.getElementById('password-field');
        const icon = document.getElementById('eye-icon');
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
  </script>

</body>
</html>
