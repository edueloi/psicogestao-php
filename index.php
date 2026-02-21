<?php
// index.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PsicoGestão - Gestão Financeira</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style> body { font-family: 'Inter', sans-serif; } </style>

  <link rel="stylesheet" href="./assets/index.css">
</head>

<body class="bg-slate-50 text-slate-900">
  <div class="flex min-h-screen bg-slate-50">

    <!-- Sidebar (igual) -->
    <aside class="w-64 bg-white border-r border-slate-200 h-screen sticky top-0 flex flex-col shrink-0">
      <div class="p-6 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-indigo-200">P</div>
          <div>
            <h1 class="font-bold text-slate-900 leading-tight">PsicoGestão</h1>
            <span class="text-[10px] text-slate-400 font-medium uppercase tracking-widest">Financeiro & Fiscal</span>
          </div>
        </div>
      </div>

      <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
        <p class="px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Menu Principal</p>

        <button data-tab="dashboard" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group bg-indigo-50 text-indigo-700 font-semibold">
          <span class="w-5 text-indigo-600">&#128202;</span><span class="text-sm">Dashboard</span>
        </button>

        <button data-tab="cashbook" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group text-slate-600 hover:bg-slate-50">
          <span class="w-5 text-slate-400 group-hover:text-slate-600">&#128210;</span><span class="text-sm">Livro Caixa</span>
        </button>

        <button data-tab="reports" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group text-slate-600 hover:bg-slate-50">
          <span class="w-5 text-slate-400 group-hover:text-slate-600">&#128196;</span><span class="text-sm">Relatórios & DRE</span>
        </button>

        <button data-tab="bi" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group text-slate-600 hover:bg-slate-50">
          <span class="w-5 text-slate-400 group-hover:text-slate-600">&#129504;</span><span class="text-sm">Centro de Análise</span>
        </button>

        <button data-tab="provisions" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group text-slate-600 hover:bg-slate-50">
          <span class="w-5 text-slate-400 group-hover:text-slate-600">&#129514;</span><span class="text-sm">Fisco & Tributos</span>
        </button>

        <div class="pt-6">
          <p class="px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Sistema</p>
          <button class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-50">
            <span class="w-5 text-slate-400">&#9881;&#65039;</span><span class="text-sm">Configurações</span>
          </button>
          <button class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-50">
            <span class="w-5 text-slate-400">?</span><span class="text-sm">Ajuda</span>
          </button>
        </div>
      </nav>

      <div class="p-4 border-t border-slate-100">
        <div class="bg-slate-50 p-4 rounded-2xl">
          <div class="flex items-center gap-3 mb-2">
            <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center">
              <span class="text-emerald-600">&#128197;</span>
            </div>
            <div class="text-xs">
              <p class="font-bold text-slate-700">Fechamento Mensal</p>
              <p class="text-slate-500">Faltam 5 dias</p>
            </div>
          </div>
          <div class="w-full bg-slate-200 rounded-full h-1.5">
            <div class="bg-emerald-500 h-1.5 rounded-full" style="width: 80%"></div>
          </div>
        </div>

        <button class="w-full flex items-center gap-3 px-4 py-3 mt-2 text-rose-600 font-medium text-sm hover:bg-rose-50 rounded-xl transition-colors">
          <span class="w-5">&#128682;</span> Sair do Sistema
        </button>
      </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden">

      <!-- Header (igual) -->
      <header class="h-16 border-b border-slate-200 bg-white flex items-center justify-between px-8 shrink-0 relative z-40">
        <div class="flex items-center gap-4 flex-1">
          <div class="relative w-96 max-w-full">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">&#128269;</span>
            <input type="text" placeholder="Pesquisar..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-indigo-500" />
          </div>
        </div>

        <div class="flex items-center gap-4">
          <button class="relative p-2 text-slate-500 hover:bg-slate-100 rounded-full transition-colors">&#128276;<span class="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 rounded-full border-2 border-white"></span>
          </button>

          <div class="relative" id="profileWrap">
            <button id="profileBtn" class="flex items-center gap-3 pl-2 py-1.5 pr-2 rounded-xl hover:bg-slate-50 transition-colors group">
              <div class="text-right hidden sm:block">
                <p class="text-sm font-semibold text-slate-800">Psicóloga Karen Gomes</p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">CRP 06/172315</p>
              </div>
              <div class="w-10 h-10 rounded-full bg-indigo-100 border border-indigo-200 flex items-center justify-center text-indigo-700 font-bold overflow-hidden shadow-inner">&#128100;</div>
              <span id="profileChevron" class="text-slate-400 transition-transform">&#8964;</span>
            </button>

            <div id="profileMenu" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-2xl border border-slate-200 py-2 overflow-hidden animate-in fade-in zoom-in duration-150 origin-top-right">
              <div class="px-4 py-3 border-b border-slate-100 bg-slate-50/50">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Perfil Profissional</p>
                <p class="text-sm font-bold text-slate-900">Karen Lais da Silva Gomes</p>
              </div>

              <div class="py-1">
                <button id="resetDb" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 font-semibold hover:bg-rose-50 transition-colors">&#128260; Resetar Banco de Dados
                </button>
              </div>

              <div class="pt-1 mt-1 border-t border-slate-100">
                <button onclick="location.reload()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 transition-colors">&#128682; Sair / Logout
                </button>
              </div>
            </div>
          </div>

        </div>
      </header>

      <!-- Pages -->
      <div class="flex-1 overflow-y-auto p-8">

        <!-- Dashboard -->
        <section id="page-dashboard" class="page">
          <div id="dashboardEmpty" class="hidden h-full flex flex-col items-center justify-center py-20 text-center">
            <div class="w-20 h-20 bg-indigo-50 text-indigo-400 rounded-full flex items-center justify-center mb-6">&#11014;&#65039;</div>
            <h2 class="text-2xl font-bold text-slate-800 mb-2">Seu Dashboard está vazio</h2>
            <p class="text-slate-500 max-w-md mb-8">
              Vá para a aba <strong>Livro Caixa</strong> e use o botão <strong>Importar Planilha</strong>.
            </p>
          </div>

          <div id="dashboardContent" class="space-y-6">
            <div class="flex justify-between items-center">
              <h2 class="text-2xl font-bold text-slate-800">Visão Executiva</h2>
              <div id="currentMonthLabel" class="text-sm text-slate-500 bg-white px-3 py-1 rounded-full border border-slate-200 shadow-sm flex items-center gap-2 capitalize">&#128197; <span></span>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" id="kpis"></div>

            <!-- Mantive os containers (no React tinha charts Recharts).
                 Aqui você pode plugar Chart.js depois sem mexer no layout -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div class="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <h3 class="font-semibold text-lg flex items-center gap-2">&#128200; <span class="text-indigo-600">&#128196;</span>
                </h3>
                <div class="h-[300px] flex items-center justify-center text-slate-400 italic">
                  Gráfico (opcional) - pronto para Chart.js
                </div>
              </div>

              <div class="space-y-6">
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                  <h3 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">&#129514; Receita Saúde</h3>
                  <div id="pendingBox" class="flex items-center justify-between p-4 rounded-xl border">
                    <div>
                      <p id="pendingLabel" class="text-sm font-medium">Recibos Pendentes</p>
                      <p id="pendingCount" class="text-2xl font-bold">0</p>
                    </div>
                    <div id="pendingIcon" class="text-2xl">&#9989;</div>
                  </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                  <h3 class="font-semibold text-slate-800 mb-4">Meios de Recebimento</h3>
                  <div class="h-[200px] flex items-center justify-center text-slate-400 italic">
                    Gráfico (opcional) - pronto para Chart.js
                  </div>
                </div>
              </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
              <h3 class="font-semibold text-slate-800 mb-4">Provisões Detalhadas</h3>
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" id="provisionsGrid"></div>
            </div>
          </div>
        </section>

        <!-- Livro Caixa -->
        <section id="page-cashbook" class="page hidden">
          <div class="space-y-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div>
                <h2 class="text-2xl font-bold text-slate-800">Livro Caixa</h2>
                <p class="text-sm text-slate-500 italic">Gestão completa de pagadores, pacientes e CPFs fiscais.</p>
              </div>
              <div class="flex items-center gap-2">
                <button id="btnImport" class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 border border-indigo-100 rounded-lg hover:bg-indigo-100 transition-colors">&#11014;&#65039; Importar Planilha
                </button>
                <button id="btnExport" class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">&#11015;&#65039; Exportar
                </button>
                <button id="btnNew" class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200">&#10133; Novo Lançamento
                </button>
              </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden min-h-[400px]">
              <div class="p-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center gap-4">
                <div class="relative flex-1">
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">&#128269;</span>
                  <input id="filterInput" type="text" placeholder="Buscar por nome, CPF ou serviço..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 transition-all" />
                </div>
              </div>

              <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                  <thead class="bg-slate-50">
                    <tr>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Data</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Serviço / Meio</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Pessoas & CPFs</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest text-right">Valor</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Status / Recibo</th>
                      <th class="px-6 py-4"></th>
                    </tr>
                  </thead>
                  <tbody id="txTable" class="divide-y divide-slate-100">
                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400 italic">Carregando...</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Modal Novo/Editar -->
          <div id="txModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[60] items-center justify-center p-4">
            <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden animate-in fade-in zoom-in duration-200">
              <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 id="txModalTitle" class="text-xl font-bold text-slate-800">Novo Lançamento</h3>
                <button id="txModalClose" class="p-2 hover:bg-white rounded-full text-slate-400 shadow-sm transition-colors">&#10006;</button>
              </div>

              <form id="txForm" class="p-6 space-y-5 overflow-y-auto max-h-[80vh]">
                <div class="flex p-1 bg-slate-100 rounded-xl">
                  <button type="button" id="btnTypeIncome" class="flex-1 py-2 text-xs font-bold rounded-lg transition-all bg-white text-emerald-600 shadow-sm">Receita</button>
                  <button type="button" id="btnTypeExpense" class="flex-1 py-2 text-xs font-bold rounded-lg transition-all text-slate-500">Despesa</button>
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div class="col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Serviço / Item</label>
                    <input name="description" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div class="col-span-1">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Pagador</label>
                    <input name="payerName" required placeholder="Nome de quem pagou" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div class="col-span-1">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">CPF do Pagador</label>
                    <input name="payerCpf" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div class="col-span-1">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Paciente (Beneficiário)</label>
                    <input name="beneficiaryName" required placeholder="Nome do paciente" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div class="col-span-1">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">CPF do Paciente</label>
                    <input name="beneficiaryCpf" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Valor (R$)</label>
                    <input name="amount" type="number" step="0.01" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-black text-indigo-600 focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Data</label>
                    <input name="date" type="date" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500" />
                  </div>

                  <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Categoria</label>
                    <select name="category" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-indigo-500">
                      <!-- preenchido via JS -->
                    </select>
                  </div>

                  <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Meio</label>
                    <select name="method" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-indigo-500">
                      <option value="PIX">PIX</option>
                      <option value="TRANSFER">TRANSFER</option>
                      <option value="CASH">CASH</option>
                      <option value="CARD">CARD</option>
                    </select>
                  </div>

                  <div class="col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Observações</label>
                    <textarea name="observation" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500" rows="2"></textarea>
                  </div>
                </div>

                <input type="hidden" name="id" />
                <input type="hidden" name="type" value="INCOME" />
                <input type="hidden" name="status" value="PAID" />
                <input type="hidden" name="receiptStatus" value="PENDING" />

                <button type="submit" class="w-full py-3 bg-indigo-600 text-white font-black rounded-2xl shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition-all">
                  Salvar Lançamento
                </button>
              </form>
            </div>
          </div>

          <!-- Modal Import -->
          <div id="importModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[60] items-center justify-center p-4">
            <div class="bg-white rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden">
              <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                <div>
                  <h3 class="text-xl font-bold text-slate-800 flex items-center gap-2">&#11014;&#65039; Importador Inteligente v3</h3>
                  <p class="text-sm text-slate-500">Extração completa de CPFs do Pagador e Beneficiário.</p>
                </div>
                <button id="importClose" class="p-2 hover:bg-slate-100 rounded-full text-slate-400 transition-colors">&#10006;</button>
              </div>

              <div class="flex-1 overflow-y-auto p-6 space-y-6">
                <div id="importStepPaste" class="space-y-4">
                  <div class="bg-indigo-50 border border-indigo-100 p-4 rounded-2xl flex gap-3 text-sm text-indigo-800">
                    <div>&#8505;&#65039;</div><div><p class="font-bold">Dados detectados:</p>
                      <ul class="list-disc list-inside text-xs mt-1 space-y-1">
                        <li>Extração de <strong>Nome e CPF</strong> do Paciente nas observações.</li>
                        <li>Sincronização de <strong>CPF do Pagador</strong> via coluna dedicada.</li>
                      </ul>
                    </div>
                  </div>

                  <textarea id="importText" class="w-full h-64 p-4 bg-slate-50 border-2 border-dashed border-slate-200 rounded-2xl text-xs font-mono focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Cole as colunas da planilha aqui..."></textarea>

                  <button id="importAnalyze" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all">
                    Analisar Dados da Planilha
                  </button>
                </div>

                <div id="importStepPreview" class="hidden space-y-4">
                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                      <h4 class="font-bold text-slate-800" id="importValidCount">0 Lançamentos Válidos</h4>
                      <span id="importIgnored" class="hidden text-[10px] bg-amber-50 text-amber-600 px-2 py-1 rounded font-bold"></span>
                    </div>
                    <button id="importRestart" class="text-sm text-indigo-600 font-semibold hover:underline">Reiniciar Importação</button>
                  </div>

                  <div class="border border-slate-200 rounded-2xl overflow-hidden shadow-sm overflow-x-auto">
                    <table class="w-full text-left text-[10px]">
                      <thead class="bg-slate-50">
                        <tr>
                          <th class="px-4 py-3 font-bold text-slate-500 uppercase">Data</th>
                          <th class="px-4 py-3 font-bold text-slate-500 uppercase">Pagador / CPF</th>
                          <th class="px-4 py-3 font-bold text-indigo-600 uppercase">Paciente / CPF</th>
                          <th class="px-4 py-3 font-bold text-slate-500 uppercase">Categoria</th>
                          <th class="px-4 py-3 font-bold text-slate-500 uppercase text-right">Valor</th>
                        </tr>
                      </thead>
                      <tbody id="importPreviewTable" class="divide-y divide-slate-100"></tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="p-6 border-t border-slate-100 bg-slate-50 flex justify-end gap-3">
                <button id="importCancel" class="px-6 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200 rounded-xl transition-colors">Cancelar</button>
                <button id="importConfirm" class="px-8 py-2 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all">
                  Gravar Dados Permanentemente
                </button>
              </div>
            </div>
          </div>

        </section>

        <!-- Reports -->
        <section id="page-reports" class="page hidden">
          <div id="reportsEmpty" class="hidden h-full flex flex-col items-center justify-center py-20 text-center">
            <div class="w-20 h-20 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mb-6">&#128196;</div>
            <h2 class="text-2xl font-bold text-slate-800 mb-2">Relatórios Indisponíveis</h2>
            <p class="text-slate-500 max-w-md">A DRE será gerada assim que houverem lançamentos no Livro Caixa.</p>
          </div>

          <div id="reportsContent" class="space-y-8 max-w-5xl mx-auto">
            <div class="flex justify-between items-end">
              <div>
                <h2 class="text-2xl font-bold text-slate-800">Relatórios & DRE</h2>
                <p class="text-slate-500">Demonstrativo de Resultados do Exercício e Fluxo de Caixa.</p>
              </div>
              <div class="flex gap-2">
                <button class="flex items-center gap-2 px-4 py-2 text-sm font-medium bg-white border border-slate-200 rounded-lg text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">&#11015;&#65039; PDF</button>
                <button class="flex items-center gap-2 px-4 py-2 text-sm font-medium bg-white border border-slate-200 rounded-lg text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">&#11015;&#65039; CSV</button>
              </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="bg-slate-50 p-4 border-b border-slate-200 flex items-center gap-2">
                <span class="text-indigo-600">&#128196;</span>
                <h3 class="font-bold text-slate-800 capitalize" id="dreTitle">DRE Simplificada</h3>
              </div>
              <div class="p-6 space-y-4" id="dreBody"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="reportCards"></div>
          </div>
        </section>

        <!-- BI -->
        <section id="page-bi" class="page hidden">
          <div id="biEmpty" class="hidden h-full flex flex-col items-center justify-center py-20 text-center">
            <div class="w-20 h-20 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mb-6">&#128196;</div>
            <h2 class="text-2xl font-bold text-slate-800 mb-2">Sem dados para análise</h2>
            <p class="text-slate-500 max-w-md">O BI precisa de lançamentos no Livro Caixa para gerar insights.</p>
          </div>

          <div id="biContent" class="space-y-8 max-w-6xl mx-auto">
            <div>
              <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">&#129504; Centro de Análise BI</h2>
              <p class="text-slate-500">Inteligência de dados para o crescimento do seu consultório.</p>
            </div>

            <div class="relative group">
              <div class="absolute -inset-1 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-3xl blur opacity-25 group-hover:opacity-40 transition duration-1000 group-hover:duration-200"></div>
              <div class="relative bg-white p-8 rounded-3xl border border-slate-200 shadow-lg">
                <div class="flex items-center justify-between mb-6">
                  <div class="flex items-center gap-3">
                    <div class="p-3 bg-indigo-600 text-white rounded-2xl shadow-indigo-200 shadow-lg">&#10024;</div>
                    <div>
                      <h3 class="font-bold text-xl text-slate-900">Consultor IA: PsicoInsight</h3>
                      <p class="text-xs font-medium text-slate-400 uppercase tracking-widest">Powered by Gemini 3 Flash</p>
                    </div>
                  </div>
                </div>
                <div class="prose prose-slate max-w-none text-slate-400 italic">
                  Insight de IA removido (como no seu protótipo atual).
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" id="biCards"></div>

            <div class="bg-slate-900 rounded-3xl p-8 text-white overflow-hidden relative">
              <div class="relative z-10">
                <div class="flex items-center justify-between mb-8">
                  <div>
                    <h3 class="text-xl font-bold flex items-center gap-2">&#128202; BI Customizado</h3>
                    <p class="text-slate-400 text-sm">Monte suas próprias métricas cruzando dados de tags e categorias.</p>
                  </div>
                  <button class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-xl shadow-indigo-900/40 hover:bg-indigo-500 transition-all">
                    Novo Filtro BI
                  </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 opacity-50 select-none pointer-events-none">
                  <div class="p-4 border border-slate-800 rounded-2xl flex items-center gap-3"><div class="w-2 h-2 rounded-full bg-indigo-400"></div><span class="text-sm font-medium">Métrica: Receita</span></div>
                  <div class="p-4 border border-slate-800 rounded-2xl flex items-center gap-3"><div class="w-2 h-2 rounded-full bg-emerald-400"></div><span class="text-sm font-medium">Agrupar: Tags</span></div>
                  <div class="p-4 border border-slate-800 rounded-2xl flex items-center gap-3"><div class="w-2 h-2 rounded-full bg-amber-400"></div><span class="text-sm font-medium">Filtro: Liquidado</span></div>
                  <div class="p-4 border border-slate-800 rounded-2xl flex items-center gap-3"><div class="w-2 h-2 rounded-full bg-rose-400"></div><span class="text-sm font-medium">Formato: Pizza</span></div>
                </div>

                <div class="mt-8 py-10 text-center border-2 border-dashed border-slate-800 rounded-2xl">
                  <p class="text-slate-500 font-medium italic">Construtor de BI avançado em desenvolvimento</p>
                </div>
              </div>
              <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-600/10 rounded-full blur-3xl -mr-32 -mt-32"></div>
            </div>
          </div>
        </section>

        <!-- Provisions -->
        <section id="page-provisions" class="page hidden">
          <div class="max-w-4xl mx-auto py-12 text-center">
            <div class="mx-auto text-slate-300 mb-4 text-5xl">&#9881;&#65039;</div>
            <h2 class="text-xl font-bold text-slate-800">Módulo Fiscal</h2>
            <p class="text-slate-500">Configuração de alíquotas automáticas em breve.</p>
          </div>
        </section>

      </div>
    </main>
  </div>

  <script>
    // base da API
    window.API_BASE_URL = '/api';
  </script>
  <script src="./assets/app.js"></script>
</body>
</html>



