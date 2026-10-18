/**
 * Frontend Ultra-Compacto para Celular (Mil Cópias)
 * Sem '#' na frente da OS
 * Nome do cliente completo, sem cortes
 * Cidade em destaque chamativo
 * Defeito chamativo em caixa de alerta âmbar
 * Modelo do equipamento e número de série
 * Exibição de Status 00, Status 01 e filtros rápidos
 * Ação para Definir Técnico e alterar para Status 03
 */

let dados = { semTriagem: [], comTriagem: [] };
let listaTecnicos = [];
let abaAtiva = 'sem-triagem';
let subStatusAtivo = '';
let osSelecionadaModal = null;

function trocarAba(aba) {
  abaAtiva = aba;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-conteudo').forEach(c => c.classList.remove('active'));
  const subBar = document.getElementById('subFiltrosBar');

  if (aba === 'sem-triagem') {
    document.getElementById('btn-sem-triagem').classList.add('active');
    document.getElementById('aba-sem-triagem').classList.add('active');
    if (subBar) subBar.style.display = 'flex';
  } else {
    document.getElementById('btn-com-triagem').classList.add('active');
    document.getElementById('aba-com-triagem').classList.add('active');
    if (subBar) subBar.style.display = 'none';
  }
  renderizar();
}

function filtrarSubStatus(st) {
  subStatusAtivo = st;
  document.querySelectorAll('.sub-btn').forEach(b => b.classList.remove('active'));
  if (st === '00') {
    document.getElementById('btnSub00').classList.add('active');
  } else if (st === '01') {
    document.getElementById('btnSub01').classList.add('active');
  } else {
    document.getElementById('btnSubTodas').classList.add('active');
  }
  renderizar();
}

function escapeHtml(str) {
  return (str || '').replace(/[&<>'"]/g, tag => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;'
  }[tag] || tag));
}

function escapeJsParam(str) {
  return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

function criarItemHTML(item, isComTriagem) {
  const atrasadoClass = item.atrasado ? 'atrasado' : '';
  const st = item.status || (isComTriagem ? '03' : '00');
  const modelo = item.modelo || 'Equipamento não cadastrado';
  const serie = item.numserie ? `<span class="equip-serie">(${escapeHtml(item.numserie)})</span>` : '';
  
  const rightTag = isComTriagem
    ? `<span class="tag-tecnico" style="background-color: ${item.corTecnico || '#cbd5e1'}">${escapeHtml(item.tecnico)}</span>`
    : `<span class="tag-sla ${parseInt(item.sla) < 0 ? 'sla-atraso' : 'sla-normal'}">${item.sla}h</span>`;

  return `
    <div class="item-os ${atrasadoClass}" data-status="${st}" data-texto="${(item.os + ' status ' + st + ' st' + st + ' ' + item.cliente + ' ' + item.cidade + ' ' + modelo + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase()}">
      <!-- LINHA 1: OS + Status + Cidade Chamativa + SLA/Técnico -->
      <div class="linha-1">
        <div class="os-cli">
          <div class="os-topo-info">
            <span class="os-num">${escapeHtml(item.os)}</span>
            <span class="tag-status tag-status-${st}">Status ${st}</span>
            <span class="badge-cidade">📍 ${escapeHtml(item.cidade || '')}</span>
          </div>
          <div class="cliente-nome">${escapeHtml(item.cliente)}</div>
          <div class="linha-equip">
            🖨️ <span class="equip-modelo">${escapeHtml(modelo)}</span>
            ${serie}
          </div>
        </div>
        <div class="tags-status">
          ${rightTag}
        </div>
      </div>
      <!-- LINHA 2: O DEFEITO CHAMATIVO DO DATABIT -->
      <div class="caixa-defeito">
        <span class="defeito-icone">🔧</span>
        <span class="defeito-texto">${escapeHtml(item.defeito || 'Defeito não informado')}</span>
      </div>
      <!-- LINHA 3: AÇÃO DEFINIR TÉCNICO (STATUS 03) -->
      <div class="linha-acoes">
        <button class="btn-definir-tecnico" onclick="abrirModalTecnico('${item.os}', '${escapeJsParam(item.cliente)}', '${item.codtec || ''}')">
          👤 Definir Técnico
        </button>
      </div>
    </div>
  `;
}

function renderizar() {
  const termo = document.getElementById('campoBusca').value.toLowerCase().trim();

  const filtrarLista = (lista, aplicarSubFiltro) => {
    return lista.filter(item => {
      const st = item.status || '00';
      const modelo = item.modelo || '';
      const txt = (item.os + ' status ' + st + ' st' + st + ' ' + item.cliente + ' ' + item.cidade + ' ' + modelo + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase();
      
      const bateTexto = !termo || txt.includes(termo);
      const bateSub = !aplicarSubFiltro || !subStatusAtivo || st === subStatusAtivo;

      return bateTexto && bateSub;
    });
  };

  const semTriagemFiltrados = filtrarLista(dados.semTriagem, true);
  const comTriagemFiltrados = filtrarLista(dados.comTriagem, false);

  // Contagem para subfiltros
  let qtd00 = 0;
  let qtd01 = 0;
  for (const it of dados.semTriagem) {
    if (it.status === '01') qtd01++; else qtd00++;
  }

  const elSubTodas = document.getElementById('countSubTodas');
  const elSub00 = document.getElementById('countSub00');
  const elSub01 = document.getElementById('countSub01');
  if (elSubTodas) elSubTodas.textContent = dados.semTriagem.length;
  if (elSub00) elSub00.textContent = qtd00;
  if (elSub01) elSub01.textContent = qtd01;

  document.getElementById('badgeSemTriagem').textContent = dados.semTriagem.length;
  document.getElementById('badgeComTriagem').textContent = dados.comTriagem.length;

  const aba1 = document.getElementById('aba-sem-triagem');
  const aba2 = document.getElementById('aba-com-triagem');

  if (semTriagemFiltrados.length === 0) {
    aba1.innerHTML = '<div class="vazio">Nenhum chamado encontrado com esses critérios.</div>';
  } else {
    aba1.innerHTML = semTriagemFiltrados.map(i => criarItemHTML(i, false)).join('');
  }

  if (comTriagemFiltrados.length === 0) {
    aba2.innerHTML = '<div class="vazio">Nenhum chamado encontrado com esses critérios.</div>';
  } else {
    aba2.innerHTML = comTriagemFiltrados.map(i => criarItemHTML(i, true)).join('');
  }
}

function filtrar() {
  renderizar();
}

async function carregar() {
  try {
    const res = await fetch('/api/chamados');
    const json = await res.json();
    if (json.sucesso) {
      dados.semTriagem = json.semTriagem || [];
      dados.comTriagem = json.comTriagem || [];
      renderizar();
    }
  } catch (err) {
    console.error('Erro ao carregar chamados:', err);
  }
}

async function carregarTecnicos() {
  try {
    const res = await fetch('/api/tecnicos');
    const json = await res.json();
    if (json.sucesso && Array.isArray(json.tecnicos)) {
      listaTecnicos = json.tecnicos;
      popularSelectTecnicos();
    }
  } catch (err) {
    console.error('Erro ao carregar técnicos:', err);
  }
}

function popularSelectTecnicos(codtecPreselecionado = '') {
  const select = document.getElementById('selectTecnico');
  if (!select) return;

  if (listaTecnicos.length === 0) {
    select.innerHTML = '<option value="">Nenhum técnico disponível</option>';
    return;
  }

  let html = '<option value="">-- Escolha o técnico --</option>';
  for (const t of listaTecnicos) {
    const sel = t.codigo === codtecPreselecionado ? 'selected' : '';
    html += `<option value="${t.codigo}" ${sel}>${t.nome} (${t.codigo})</option>`;
  }
  select.innerHTML = html;
}

function abrirModalTecnico(os, cliente, codtecAtual = '') {
  osSelecionadaModal = os;
  document.getElementById('modalOsNum').textContent = os;
  document.getElementById('modalCliente').textContent = cliente;
  popularSelectTecnicos(codtecAtual);
  document.getElementById('modalTecnico').style.display = 'flex';
}

function fecharModalTecnico() {
  document.getElementById('modalTecnico').style.display = 'none';
  osSelecionadaModal = null;
}

function fecharModalSeFora(e) {
  if (e.target && e.target.id === 'modalTecnico') {
    fecharModalTecnico();
  }
}

async function confirmarDefinicaoTecnico() {
  const select = document.getElementById('selectTecnico');
  const codtec = select.value;
  const nomeTecnico = select.options[select.selectedIndex]?.text || '';
  const btn = document.getElementById('btnSalvarTecnico');

  if (!codtec) {
    alert('Por favor, selecione um técnico na lista.');
    return;
  }

  if (!osSelecionadaModal) {
    fecharModalTecnico();
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Gravando no Databit...';

  try {
    const res = await fetch('/api/definir-tecnico', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        os: osSelecionadaModal,
        codtec: codtec,
        nomeTecnico: nomeTecnico
      })
    });

    const json = await res.json();
    if (json.sucesso) {
      mostrarToast(`✅ OS ${osSelecionadaModal} atribuída com sucesso! Atualizada para Status 03.`);
      fecharModalTecnico();
      // Recarregar os chamados na hora
      await carregar();
    } else {
      alert(`Erro ao atribuir técnico: ${json.erro || 'Falha desconhecida.'}`);
    }
  } catch (err) {
    alert(`Erro de comunicação com o servidor: ${err.message}`);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Confirmar (Status 03)';
  }
}

function mostrarToast(msg, duracao = 4000) {
  const t = document.getElementById('toastNotificacao');
  if (!t) return;
  t.textContent = msg;
  t.style.display = 'block';
  setTimeout(() => {
    t.style.display = 'none';
  }, duracao);
}

// Timer regressivo de 60 segundos
let tempo = 60;
setInterval(() => {
  tempo--;
  if (tempo <= 0) {
    tempo = 60;
    carregar();
  }
  const el = document.getElementById('contador');
  if (el) el.textContent = tempo;
}, 1000);

carregarTecnicos();
carregar();
