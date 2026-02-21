// assets/app.js
(() => {
  const API = window.API_BASE_URL || '/api';

  // ===== Constantes =====
  const CATEGORIES = {
    INCOME: [
      'Sessão Individual',
      'Pacote Semanal',
      'Pacote Quinzenal',
      'Pacote Mensal',
      'Sessão Mensal',
      'Avaliação Psicológica',
      'Supervisão',
      'Palestra/Curso'
    ],
    EXPENSE: [
      'Aluguel',
      'Plataformas (Doctoralia, etc)',
      'Contador',
      'Marketing',
      'Internet/Energia',
      'Materiais',
      'Educação Continuada'
    ],
  };

  const DEFAULT_PROVISION_RULES = [
    { name: 'INSS Autônomo', percentage: 20, base: 'GROSS' },
    { name: 'IRPF Estimado', percentage: 15, base: 'NET' },
    { name: 'Reserva de Férias', percentage: 8.33, base: 'NET' },
    { name: 'Reserva 13º/Emergência', percentage: 10, base: 'NET' }
  ];

  // ===== Estado =====
  let activeTab = 'dashboard';
  let transactions = [];
  let editingId = null;
  let importPreview = [];
  let importErrors = 0;

  // ===== Helpers =====
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  const fmtBRL = (n) => `R$ ${Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
  const fmtDateBR = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('pt-BR');

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function calcSummary(list) {
    const income = list.filter(t => t.type === 'INCOME').reduce((a, c) => a + (Number(c.amount) || 0), 0);
    const expense = list.filter(t => t.type === 'EXPENSE').reduce((a, c) => a + (Number(c.amount) || 0), 0);
    const net = income - expense;

    const provisions = DEFAULT_PROVISION_RULES.map(rule => {
      const base = rule.base === 'GROSS' ? income : Math.max(0, net);
      return { name: rule.name, amount: base * (rule.percentage / 100) };
    });

    const totalProvisions = provisions.reduce((a, p) => a + p.amount, 0);

    return {
      totalIncome: income,
      totalExpense: expense,
      netResult: net,
      provisions,
      liquidResult: net - totalProvisions
    };
  }

  async function apiGet(path) {
    const res = await fetch(`${API}?path=${encodeURIComponent(path)}`);
    return res.json();
  }
  async function apiPost(path, body) {
    const res = await fetch(`${API}?path=${encodeURIComponent(path)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
    return res.json();
  }
  async function apiPut(path, id, body) {
    const res = await fetch(`${API}?path=${encodeURIComponent(path)}&id=${encodeURIComponent(id)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
    return res.json();
  }
  async function apiDelete(path, id) {
    const res = await fetch(`${API}?path=${encodeURIComponent(path)}&id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    return res.json();
  }
  async function apiPostWithId(path, id) {
    const res = await fetch(`${API}?path=${encodeURIComponent(path)}&id=${encodeURIComponent(id)}`, { method: 'POST' });
    return res.json();
  }

  // ===== Navegação =====
  function setTab(tab) {
    activeTab = tab;
    $$('.page').forEach(p => p.classList.add('hidden'));
    $(`#page-${tab}`)?.classList.remove('hidden');

    $$('.tab-btn').forEach(btn => {
      const isActive = btn.dataset.tab === tab;
      btn.className = `tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group ${
        isActive ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50'
      }`;
    });

    renderAll();
  }

  function wireTabs() {
    $$('.tab-btn').forEach(btn => btn.addEventListener('click', () => setTab(btn.dataset.tab)));
  }

  // ===== Perfil dropdown =====
  function wireProfile() {
    const btn = $('#profileBtn');
    const menu = $('#profileMenu');
    const chev = $('#profileChevron');
    const wrap = $('#profileWrap');

    if (!btn || !menu || !wrap) return;

    btn.addEventListener('click', () => {
      menu.classList.toggle('hidden');
      if (chev) chev.style.transform = menu.classList.contains('hidden') ? 'rotate(0deg)' : 'rotate(180deg)';
    });

    document.addEventListener('mousedown', (e) => {
      if (!wrap.contains(e.target)) {
        menu.classList.add('hidden');
        if (chev) chev.style.transform = 'rotate(0deg)';
      }
    });

    const reset = $('#resetDb');
    if (reset) {
      reset.addEventListener('click', async () => {
        if (!confirm('Isso apagará TODOS os dados do SQLite?')) return;
        const list = await apiGet('transactions');
        for (const t of list) await apiDelete('transactions', t.id);
        await reloadTransactions();
      });
    }
  }

  // ===== Carregar dados =====
  async function reloadTransactions() {
    transactions = await apiGet('transactions');
    renderAll();
  }

  // ===== Dashboard =====
  function kpiCard(title, value, trend, isMain) {
    return `
      <div class="p-6 rounded-2xl border ${isMain ? 'bg-indigo-600 border-indigo-700 text-white' : 'bg-white border-slate-200 text-slate-800'} shadow-sm relative overflow-hidden">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 rounded-xl ${isMain ? 'bg-white/20' : 'bg-slate-100'}">R$</div>
          <span class="text-[10px] font-bold px-2 py-1 rounded-full ${isMain ? 'bg-indigo-500/50 text-white' : 'bg-slate-100 text-slate-600'}">
            ${trend}
          </span>
        </div>
        <div>
          <p class="text-sm font-medium ${isMain ? 'text-indigo-100' : 'text-slate-500'}">${title}</p>
          <h4 class="text-xl font-bold mt-1">${fmtBRL(value)}</h4>
        </div>
        ${isMain ? '<div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/5 rounded-full blur-2xl"></div>' : ''}
      </div>
    `;
  }

  function renderDashboard() {
    const empty = $('#dashboardEmpty');
    const content = $('#dashboardContent');
    if (!empty || !content) return;

    if (!transactions.length) {
      empty.classList.remove('hidden');
      content.classList.add('hidden');
      return;
    }
    empty.classList.add('hidden');
    content.classList.remove('hidden');

    const label = new Date().toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    const monthEl = $('#currentMonthLabel span');
    if (monthEl) monthEl.textContent = label;

    const summary = calcSummary(transactions);

    const kpis = $('#kpis');
    if (kpis) {
      const provTotal = summary.provisions.reduce((a, p) => a + p.amount, 0);
      kpis.innerHTML = `
        ${kpiCard('Receita', summary.totalIncome, 'Atual', false)}
        ${kpiCard('Despesas', summary.totalExpense, 'Atual', false)}
        ${kpiCard('Obrigações', provTotal, 'Estimativa', false)}
        ${kpiCard('Líquido Disponível', summary.liquidResult, 'Final', true)}
      `;
    }

    const pending = transactions.filter(t => t.type === 'INCOME' && t.receiptStatus === 'PENDING').length;

    const box = $('#pendingBox');
    const lbl = $('#pendingLabel');
    const cnt = $('#pendingCount');
    const ico = $('#pendingIcon');

    if (cnt) cnt.textContent = pending;

    if (pending > 0) {
      if (box) box.className = "flex items-center justify-between p-4 rounded-xl border bg-amber-50 border-amber-100";
      if (lbl) lbl.className = "text-sm font-medium text-amber-700";
      if (cnt) cnt.className = "text-2xl font-bold text-amber-900";
      if (ico) ico.textContent = "ALERTA";
    } else {
      if (box) box.className = "flex items-center justify-between p-4 rounded-xl border bg-emerald-50 border-emerald-100";
      if (lbl) lbl.className = "text-sm font-medium text-emerald-700";
      if (cnt) cnt.className = "text-2xl font-bold text-emerald-900";
      if (ico) ico.textContent = "OK";
    }

    const prov = $('#provisionsGrid');
    if (prov) {
      prov.innerHTML = summary.provisions.map(p => `
        <div class="p-4 border border-slate-100 rounded-xl bg-slate-50">
          <p class="text-xs text-slate-500 uppercase tracking-wider font-bold">${escapeHtml(p.name)}</p>
          <p class="text-lg font-bold text-slate-800 mt-1">${fmtBRL(p.amount)}</p>
        </div>
      `).join('');
    }
  }

  // ===== Livro Caixa =====
  function fillCategories(selectEl, type) {
    const list = type === 'INCOME' ? CATEGORIES.INCOME : CATEGORIES.EXPENSE;
    selectEl.innerHTML = list.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
  }

  function syncTypeButtons(type) {
    const incomeBtn = $('#btnTypeIncome');
    const expenseBtn = $('#btnTypeExpense');
    const form = $('#txForm');
    if (!form) return;

    const categorySel = form.querySelector('select[name="category"]');

    if (type === 'INCOME') {
      if (incomeBtn) incomeBtn.className = "flex-1 py-2 text-xs font-bold rounded-lg transition-all bg-white text-emerald-600 shadow-sm";
      if (expenseBtn) expenseBtn.className = "flex-1 py-2 text-xs font-bold rounded-lg transition-all text-slate-500";
      form.querySelector('input[name="type"]').value = 'INCOME';
      form.querySelector('input[name="receiptStatus"]').value = 'PENDING';
      fillCategories(categorySel, 'INCOME');
    } else {
      if (expenseBtn) expenseBtn.className = "flex-1 py-2 text-xs font-bold rounded-lg transition-all bg-white text-rose-600 shadow-sm";
      if (incomeBtn) incomeBtn.className = "flex-1 py-2 text-xs font-bold rounded-lg transition-all text-slate-500";
      form.querySelector('input[name="type"]').value = 'EXPENSE';
      form.querySelector('input[name="receiptStatus"]').value = '';
      fillCategories(categorySel, 'EXPENSE');
    }
  }

  function openTxModal(idOrNull) {
    editingId = idOrNull || null;

    const modal = $('#txModal');
    const title = $('#txModalTitle');
    const form = $('#txForm');
    if (!modal || !form || !title) return;

    const categorySel = form.querySelector('select[name="category"]');
    const now = new Date().toISOString().split('T')[0];

    if (!editingId) {
      title.textContent = 'Novo Lançamento';
      form.reset();
      form.querySelector('input[name="date"]').value = now;
      form.querySelector('input[name="type"]').value = 'INCOME';
      form.querySelector('input[name="status"]').value = 'PAID';
      form.querySelector('input[name="receiptStatus"]').value = 'PENDING';
      form.querySelector('input[name="description"]').value = 'Psicoterapia Individual';
      fillCategories(categorySel, 'INCOME');
      categorySel.value = CATEGORIES.INCOME[0];
      syncTypeButtons('INCOME');
    } else {
      title.textContent = 'Editar Lançamento';
      const t = transactions.find(x => x.id === editingId);
      if (!t) return;

      form.querySelector('input[name="id"]').value = t.id;
      form.querySelector('input[name="date"]').value = t.date;
      form.querySelector('input[name="description"]').value = t.description;
      form.querySelector('input[name="payerName"]').value = t.payerName || '';
      form.querySelector('input[name="payerCpf"]').value = t.payerCpf || '';
      form.querySelector('input[name="beneficiaryName"]').value = t.beneficiaryName || '';
      form.querySelector('input[name="beneficiaryCpf"]').value = t.beneficiaryCpf || '';
      form.querySelector('input[name="amount"]').value = t.amount;
      form.querySelector('input[name="type"]').value = t.type;
      form.querySelector('input[name="status"]').value = t.status || 'PAID';
      form.querySelector('input[name="receiptStatus"]').value = t.receiptStatus || (t.type === 'INCOME' ? 'PENDING' : '');
      form.querySelector('select[name="method"]').value = t.method || 'PIX';
      fillCategories(categorySel, t.type);
      categorySel.value = t.category || (t.type === 'INCOME' ? CATEGORIES.INCOME[0] : CATEGORIES.EXPENSE[0]);
      form.querySelector('textarea[name="observation"]').value = t.observation || '';
      syncTypeButtons(t.type);
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeTxModal() {
    const modal = $('#txModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  function renderCashBook() {
    const tbody = $('#txTable');
    const filter = ($('#filterInput')?.value || '').toLowerCase();
    if (!tbody) return;

    const filtered = transactions.filter(t => {
      const f = (s) => (s || '').toLowerCase().includes(filter);
      return f(t.description) || f(t.category) || f(t.payerName) || f(t.beneficiaryName) || f(t.payerCpf) || f(t.beneficiaryCpf);
    });

    if (!transactions.length) {
      tbody.innerHTML = `<tr><td colSpan="6" class="px-6 py-12 text-center text-slate-400 italic">Nenhuma transação encontrada.</td></tr>`;
      return;
    }
    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colSpan="6" class="px-6 py-12 text-center text-slate-400 italic">Nenhuma transação encontrada.</td></tr>`;
      return;
    }

    tbody.innerHTML = filtered.map(t => {
      const amountClass = t.type === 'INCOME' ? 'text-emerald-600' : 'text-rose-600';
      const sign = t.type === 'INCOME' ? '+' : '-';

      const statusPill = t.status === 'PAID'
        ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase bg-emerald-100 text-emerald-800 border border-emerald-200 w-fit">Pago</span>`
        : `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase bg-amber-100 text-amber-800 border border-amber-200 w-fit">Pendente</span>`;

      const receipt = t.type === 'INCOME'
        ? (t.receiptStatus === 'ISSUED'
          ? `<span class="text-[9px] text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded border border-emerald-100 w-fit">Recibo Emitido</span>`
          : `<button class="text-[9px] text-indigo-600 font-bold hover:underline w-fit" data-action="receipt" data-id="${t.id}">Emitir Recibo</button>`)
        : '';

      return `
        <tr class="hover:bg-slate-50/50 transition-colors group">
          <td class="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">${fmtDateBR(t.date)}</td>
          <td class="px-6 py-4">
            <p class="text-sm font-bold text-slate-800 leading-tight">${escapeHtml(t.description)}</p>
            <div class="flex items-center gap-1.5 mt-1">
              <span class="text-[9px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-black uppercase tracking-tighter border border-slate-200">${escapeHtml(t.method)}</span>
              <span class="text-[9px] bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded font-bold uppercase tracking-tighter border border-indigo-100">${escapeHtml(t.category)}</span>
            </div>
          </td>
          <td class="px-6 py-4">
            <div class="flex flex-col space-y-1">
              <p class="text-[11px] font-bold text-slate-700">Pagador: ${escapeHtml(t.payerName)}</p>
              ${t.payerCpf ? `<p class="text-[10px] text-slate-400 font-mono">CPF: ${escapeHtml(t.payerCpf)}</p>` : ''}
              <p class="text-[11px] font-black text-indigo-700">Paciente: ${escapeHtml(t.beneficiaryName)}</p>
              ${t.beneficiaryCpf ? `<p class="text-[10px] text-indigo-400 font-mono">CPF: ${escapeHtml(t.beneficiaryCpf)}</p>` : ''}
            </div>
          </td>
          <td class="px-6 py-4 text-sm font-black text-right whitespace-nowrap ${amountClass}">
            ${sign} ${fmtBRL(t.amount)}
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex flex-col gap-1.5">
              ${statusPill}
              ${receipt}
            </div>
          </td>
          <td class="px-6 py-4 text-right">
            <div class="flex justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              <button class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg" data-action="edit" data-id="${t.id}">Editar</button>
              <button class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg" data-action="repeat" data-id="${t.id}">Repetir</button>
              <button class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg" data-action="delete" data-id="${t.id}">Excluir</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    // wire actions
    tbody.querySelectorAll('button[data-action]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const action = btn.dataset.action;
        const id = btn.dataset.id;

        if (action === 'edit') openTxModal(id);

        if (action === 'delete') {
          if (!confirm('Excluir este lançamento?')) return;
          await apiDelete('transactions', id);
          await reloadTransactions();
        }

        if (action === 'repeat') {
          await apiPostWithId('transactions/repeat', id);
          alert('Lançamento repetido para o próximo mês!');
          await reloadTransactions();
        }

        if (action === 'receipt') {
          await apiPostWithId('transactions/receipt', id);
          await reloadTransactions();
        }
      });
    });
  }

  // ===== Import =====
  function parseCurrency(val) {
    if (!val || val.trim() === '' || val === '-') return 0;
    let clean = val.replace(/R\$/g, '').replace(/\$/g, '').replace(/\s/g, '').trim();
    if (clean.includes(',') && clean.includes('.')) clean = clean.replace(/\./g, '').replace(',', '.');
    else if (clean.includes(',')) clean = clean.replace(',', '.');
    const r = parseFloat(clean);
    return isNaN(r) ? 0 : r;
  }
  function parseDate(val) {
    if (!val) return '';
    const sanitized = val.trim().replace(/\/\//g, '/');
    const parts = sanitized.split('/');
    if (parts.length !== 3) return '';
    let day = parts[0].padStart(2, '0');
    let month = parts[1].padStart(2, '0');
    let year = parts[2];
    if (year.length === 2) year = '20' + year;
    return `${year}-${month}-${day}`;
  }
  function extractBeneficiaryInfo(observation, payerName, payerCpf) {
    if (!observation) return { name: payerName, cpf: payerCpf };
    const regex = /(?:Beneficiario|Beneficiário|Paciente):\s*([^C\d\n\r,]+)(?:\s*CPF\s*([\d\.\-]+))?/i;
    const match = observation.match(regex);
    if (match) {
      return { name: (match[1] || payerName).trim(), cpf: (match[2] || payerCpf).trim() };
    }
    return { name: payerName, cpf: payerCpf };
  }

  function setImportStep(step) {
    const paste = $('#importStepPaste');
    const prev = $('#importStepPreview');
    if (!paste || !prev) return;
    if (step === 'paste') {
      paste.classList.remove('hidden');
      prev.classList.add('hidden');
    } else {
      paste.classList.add('hidden');
      prev.classList.remove('hidden');
    }
  }

  function openImport() {
    const m = $('#importModal');
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('flex');
    $('#importText').value = '';
    importPreview = [];
    importErrors = 0;
    setImportStep('paste');
  }

  function closeImport() {
    const m = $('#importModal');
    if (!m) return;
    m.classList.add('hidden');
    m.classList.remove('flex');
  }

  function analyzeImport() {
    const data = $('#importText')?.value || '';
    const lines = data.split('\n').filter(l => l.trim() !== '');
    const newTx = [];
    let errors = 0;

    lines.forEach((line, index) => {
      const cols = line.split('\t');
      if (cols.length < 3 || (cols[0] || '').toLowerCase().includes('data')) { errors++; return; }

      const date = parseDate(cols[0]);
      if (!date) { errors++; return; }

      const format = (cols[1] || '').toLowerCase();
      const payerName = (cols[2] || '').trim() || 'Desconhecido';
      const payerCpf = (cols[3] || '').trim() || '';

      const incomeVal = parseCurrency(cols[4]);
      const expenseVal = cols[6] ? parseCurrency(cols[6]) : 0;

      const isIncome = incomeVal > 0 || format.includes('recebido');
      const amount = isIncome ? incomeVal : expenseVal;

      if (amount === 0) { errors++; return; }

      const observation = cols[8] || '';
      const { name: beneficiaryName, cpf: beneficiaryCpf } = extractBeneficiaryInfo(observation, payerName, payerCpf);

      let category = isIncome ? 'Sessão Individual' : 'Outros';
      if (isIncome) {
        if (amount >= 360) category = 'Pacote Semanal';
        else if (amount >= 200) category = 'Sessão Individual';
        else if (amount <= 100) category = 'Sessão Mensal';
      }

      newTx.push({
        id: `import-${Date.now()}-${index}-${Math.random().toString(36).slice(2, 6)}`,
        date,
        description: isIncome ? 'Psicoterapia Individual' : 'Despesa',
        payerName,
        beneficiaryName,
        amount,
        type: isIncome ? 'INCOME' : 'EXPENSE',
        category,
        method: format.includes('pix') ? 'PIX' : 'TRANSFER',
        status: 'PAID',
        receiptStatus: isIncome ? 'PENDING' : null,
        payerCpf,
        beneficiaryCpf,
        observation,
        tags: isIncome ? ['Particular'] : []
      });
    });

    importPreview = newTx;
    importErrors = errors;

    $('#importValidCount').textContent = `${importPreview.length} Lançamentos Válidos`;

    const ignored = $('#importIgnored');
    if (ignored) {
      if (importErrors > 0) {
        ignored.classList.remove('hidden');
        ignored.textContent = `${importErrors} linhas ignoradas`;
      } else {
        ignored.classList.add('hidden');
      }
    }

    const tbody = $('#importPreviewTable');
    if (tbody) {
      tbody.innerHTML = importPreview.map(t => `
        <tr class="hover:bg-slate-50 transition-colors">
          <td class="px-4 py-3 text-slate-600 whitespace-nowrap">${t.date}</td>
          <td class="px-4 py-3">
            <p class="font-medium text-slate-800">${escapeHtml(t.payerName)}</p>
            <p class="text-slate-400 font-mono text-[9px]">${escapeHtml(t.payerCpf || '')}</p>
          </td>
          <td class="px-4 py-3">
            <p class="font-black text-indigo-600">${escapeHtml(t.beneficiaryName)}</p>
            <p class="text-indigo-400 font-mono text-[9px]">${escapeHtml(t.beneficiaryCpf || '')}</p>
          </td>
          <td class="px-4 py-3 whitespace-nowrap">
            <span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-bold border border-slate-200 uppercase">${escapeHtml(t.category)}</span>
          </td>
          <td class="px-4 py-3 text-right font-black whitespace-nowrap text-emerald-600">${fmtBRL(t.amount)}</td>
        </tr>
      `).join('');
    }

    setImportStep('preview');
  }

  async function confirmImport() {
    if (!importPreview.length) return;
    await apiPost('transactions/import', { transactions: importPreview });
    closeImport();
    await reloadTransactions();
    setTab('dashboard');
  }

  // ===== Render geral =====
  function renderAll() {
    if (activeTab === 'dashboard') renderDashboard();
    if (activeTab === 'cashbook') renderCashBook();
  }

  // ===== Wire Livro Caixa / Modais =====
  function wireCashbook() {
    $('#filterInput')?.addEventListener('input', renderCashBook);

    $('#btnNew')?.addEventListener('click', () => openTxModal(null));
    $('#txModalClose')?.addEventListener('click', closeTxModal);

    $('#btnTypeIncome')?.addEventListener('click', () => {
      syncTypeButtons('INCOME');
      const form = $('#txForm');
      form.querySelector('input[name="description"]').value = 'Psicoterapia Individual';
      form.querySelector('select[name="category"]').value = CATEGORIES.INCOME[0];
    });

    $('#btnTypeExpense')?.addEventListener('click', () => {
      syncTypeButtons('EXPENSE');
      const form = $('#txForm');
      form.querySelector('input[name="description"]').value = 'Despesa';
      form.querySelector('select[name="category"]').value = CATEGORIES.EXPENSE[0];
    });

    $('#txForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const data = Object.fromEntries(new FormData(form).entries());

      const payload = {
        id: data.id || undefined,
        date: data.date,
        description: data.description,
        payerName: data.payerName,
        beneficiaryName: data.beneficiaryName,
        amount: Number(data.amount || 0),
        type: data.type,
        category: data.category,
        method: data.method,
        payerCpf: data.payerCpf || null,
        beneficiaryCpf: data.beneficiaryCpf || null,
        observation: data.observation || null,
        status: data.status || 'PAID',
        receiptStatus: data.type === 'INCOME' ? (data.receiptStatus || 'PENDING') : null,
        tags: []
      };

      if (editingId) await apiPut('transactions', editingId, payload);
      else await apiPost('transactions', payload);

      closeTxModal();
      await reloadTransactions();
    });

    // Import
    $('#btnImport')?.addEventListener('click', openImport);
    $('#importClose')?.addEventListener('click', closeImport);
    $('#importCancel')?.addEventListener('click', closeImport);
    $('#importAnalyze')?.addEventListener('click', analyzeImport);
    $('#importRestart')?.addEventListener('click', () => setImportStep('paste'));
    $('#importConfirm')?.addEventListener('click', confirmImport);
  }

  // ===== Boot =====
  function boot() {
    // sanity log (você vê no console se carregou)
    console.log('[PsicoGestão] app.js carregou [OK]');

    wireTabs();
    wireProfile();
    wireCashbook();

    setTab('dashboard');
    reloadTransactions();
  }

  document.addEventListener('DOMContentLoaded', boot);
})();




