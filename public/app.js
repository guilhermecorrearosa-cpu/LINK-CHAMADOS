/**
 * Frontend Ultra-Compacto e Resumido para Celular (Mil Cópias)
 * - Visualização super rápida de Cidade, Cliente, Modelo e Defeito
 * - Toque na OS para expandir e acessar o botão "Definir / Transferir Técnico"
 * - Nomes reais dos status (Sem Triagem, Aberto, Encaminhado, Em Atendimento)
 * - Sem '#' nas OS
 */

let dados = { semTriagem: [], comTriagem: [] };
let listaTecnicos = [];
let abaAtiva = 'sem-triagem';
let subStatusAtivo = '';
let osSelecionadaModal = null;
let osExpandidas = new Set();

function obterNomeStatus(codigo) {
  switch (String(codigo).trim()) {
    case '00': return 'Sem Triagem';
    case '01': return 'Aberto';
    case '02': return 'Agendado';
    case '03': return 'Encaminhado';
    case '04': return 'Em Atendimento';
    case '05': return 'Finalizado';
    case '06': return 'Cancelado';
    default: return 'Status ' + codigo;
  }
}

function simplificarModelo(nome) {
  if (!nome || nome === 'Equipamento não cadastrado') return 'S/ Modelo';
  return nome
    .replace(/^(MULTIFUNCIONAL|IMPRESSORA)\s+(LASER\s+)?(MONO|COLOR)\s+/i, '')
    .trim();
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

function toggleExpandirOS(os, event) {
  // Se clicou no botão interno de definir técnico, não toggleia
  if (event && event.target && event.target.closest('.btn-definir-tecnico')) {
    return;
  }

  const elCard = document.getElementById(`card-${os}`);
  const elDet = document.getElementById(`detalhes-${os}`);
  if (!elCard || !elDet) return;

  if (osExpandidas.has(os)) {
    osExpandidas.delete(os);
    elCard.classList.remove('expandido');
    elDet.style.display = 'none';
  } else {
    osExpandidas.add(os);
    elCard.classList.add('expandido');
    elDet.style.display = 'flex';
  }
}

function criarItemHTML(item, isComTriagem) {
  const atrasadoClass = item.atrasado ? 'atrasado' : '';
  const st = item.status || (isComTriagem ? '03' : '00');
  const nomeStatus = obterNomeStatus(st);
  const modeloLimpo = simplificarModelo(item.modelo);
  const isExpandido = osExpandidas.has(item.os);

  const rightTag = isComTriagem
    ? `<span class="tag-tecnico" style="background-color: ${item.corTecnico || '#cbd5e1'}">${escapeHtml(item.tecnico)}</span>`
    : `<span class="tag-sla ${parseInt(item.sla) < 0 ? 'sla-atraso' : 'sla-normal'}">${item.sla}h</span>`;

  return `
    <div class="item-os ${atrasadoClass} ${isExpandido ? 'expandido' : ''}" id="card-${item.os}" onclick="toggleExpandirOS('${item.os}', event)" data-status="${st}" data-texto="${(item.os + ' ' + nomeStatus + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.modelo || '') + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase()}">
      <!-- 1. RESUMO COMPACTO (SEMPRE VISÍVEL) -->
      <div class="card-resumo">
        <!-- LINHA 1: OS + CIDADE CHAMATIVA + STATUS REAL + SLA -->
        <div class="resumo-topo">
          <div class="topo-esquerda">
            <span class="os-num">${escapeHtml(item.os)}</span>
            <span class="badge-cidade">📍 ${escapeHtml(item.cidade || '')}</span>
            <span class="tag-status tag-status-${st}">${escapeHtml(nomeStatus)}</span>
          </div>
          <div class="topo-direita">
            ${rightTag}
            <span class="icone-expandir">▼</span>
          </div>
        </div>

        <!-- LINHA 2: CLIENTE + MODELO RESUMIDO -->
        <div class="cliente-linha">
          <span class="cliente-nome">${escapeHtml(item.cliente)}</span>
          <span class="separador-dot">•</span>
          <span class="modelo-compacto">🖨️ ${escapeHtml(modeloLimpo)}</span>
        </div>

        <!-- LINHA 3: DEFEITO DIRETO E DESTACADO -->
        <div class="resumo-defeito">
          <span class="defeito-icone">🔧</span>
          <span class="defeito-texto">${escapeHtml(item.defeito || 'Defeito não informado')}</span>
        </div>
      </div>

      <!-- 2. DETALHES EXPANSÍVEIS (MOSTRADOS AO TOCAR NA OS) -->
      <div class="card-detalhes" id="detalhes-${item.os}" style="display: ${isExpandido ? 'flex' : 'none'};">
        <div class="detalhes-grid">
          <div><strong>Modelo Completo:</strong> ${escapeHtml(item.modelo || 'Não cadastrado')}</div>
          ${item.numserie ? `<div><strong>Nº de Série:</strong> <span class="equip-serie">${escapeHtml(item.numserie)}</span></div>` : ''}
          ${item.local ? `<div><strong>Local/Setor:</strong> ${escapeHtml(item.local)}</div>` : ''}
          ${isComTriagem && item.tecnico && item.tecnico !== 'A Definir' ? `<div><strong>Técnico Atual:</strong> ${escapeHtml(item.tecnico)}</div>` : ''}
        </div>
        <div class="linha-acoes-expandida">
          <button class="btn-definir-tecnico" onclick="event.stopPropagation(); abrirModalTecnico('${item.os}', '${escapeJsParam(item.cliente)}', '${item.codtec || ''}')">
            👤 ${isComTriagem ? 'Alterar Técnico' : 'Definir / Transferir Técnico'}
          </button>
        </div>
      </div>
    </div>
  `;
}

function renderizar() {
  const termo = document.getElementById('campoBusca').value.toLowerCase().trim();

  const filtrarLista = (lista, aplicarSubFiltro) => {
    return lista.filter(item => {
      const st = item.status || '00';
      const nomeStatus = obterNomeStatus(st);
      const txt = (item.os + ' ' + nomeStatus + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.modelo || '') + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase();
      
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
