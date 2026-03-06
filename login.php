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
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

  <div class="w-full max-w-md bg-white rounded-[2.5rem] border border-slate-200 shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-500">
    <div class="p-10 text-center bg-gradient-to-br from-indigo-50 to-white border-b border-slate-100">
      <div class="w-20 h-20 bg-indigo-600 rounded-[1.5rem] flex items-center justify-center text-white font-black text-4xl shadow-2xl shadow-indigo-200 mx-auto mb-8">P+</div>
      <h1 class="text-3xl font-black text-slate-900 leading-tight">PsicoGestão</h1>
      <p class="text-xs font-bold text-slate-400 mt-2 uppercase tracking-[0.2em]">Painel de Controle Profissional</p>
    </div>
    
    <form action="login.php" method="POST" class="p-10 space-y-6">
      <?php if ($error): ?>
        <div class="bg-rose-50 border border-rose-100 p-4 rounded-2xl text-[12px] text-rose-700 font-bold flex items-center gap-3 animate-pulse">
          <span>❌</span> <span><?= $error ?></span>
        </div>
      <?php endif; ?>

      <div class="space-y-2">
        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">E-mail Profissional</label>
        <div class="relative group">
          <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 text-sm group-focus-within:text-indigo-600 transition-colors">@</span>
          <input name="username" type="email" placeholder="karen.gomes@gmail.com" class="w-full pl-12 pr-5 py-4 border border-slate-100 rounded-2xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition-all text-sm outline-none" required>
        </div>
      </div>

      <div class="space-y-2">
        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Senha de Acesso</label>
        <div class="relative group">
          <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 text-sm group-focus-within:text-indigo-600 transition-colors">🔒</span>
          <input name="password" type="password" placeholder="••••••••" class="w-full pl-12 pr-5 py-4 border border-slate-100 rounded-2xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition-all text-sm outline-none" required>
        </div>
      </div>

      <div class="flex items-center justify-between px-1">
        <label class="flex items-center gap-2 cursor-pointer group">
          <input type="checkbox" class="w-4 h-4 rounded border-slate-200 text-indigo-600 focus:ring-indigo-500">
          <span class="text-[10px] text-slate-400 font-bold group-hover:text-slate-600 transition-colors">Lembrar-me</span>
        </label>
        <a href="#" class="text-[10px] text-indigo-600 font-bold hover:underline">Esqueci a senha</a>
      </div>

      <button type="submit" class="w-full py-5 rounded-3xl bg-indigo-600 text-white font-black text-sm hover:bg-indigo-700 shadow-2xl shadow-indigo-100 transition-all active:scale-[0.97] mt-4 flex items-center justify-center gap-2 group">
        Entrar no Sistema
        <span class="group-hover:translate-x-1 transition-transform">→</span>
      </button>

      <div class="pt-6 border-t border-slate-50 flex items-center justify-center gap-4">
        <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 text-xs font-bold">SSL</div>
        <p class="text-[9px] text-slate-400 font-medium leading-tight">Acesso criptografado e seguro.<br>Dados protegidos por criptografia de ponta.</p>
      </div>
    </form>
  </div>

</body>
</html>
