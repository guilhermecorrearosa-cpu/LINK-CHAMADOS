/**
 * Frontend Ultra-Compacto e Resumido para Celular (Mil Cópias)
 * - Nomes Oficiais dos Status vindos direto do Databit ERP (StatusOSController):
 *   00 = ABERTURA OS INTERNA
 *   01 = ABERTURA DE OS WEB
 *   03 = ENCAMINHADO TECNICO
 *   04 = EM ATENDIMENTO
 * - Visualização super rápida de Cidade, Cliente, Modelo e Defeito
 * - Toque na OS para expandir e acessar o botão "Definir / Transferir Técnico"
 * - Sem '#' nas OS
 */

let dados = { semTriagem: [], comTriagem: [] };
let listaTecnicos = [];
let abaAtiva = 'sem-triagem';
let subStatusAtivo = '';
let osSelecionadaModal = null;
let osExpandidas = new Set();

const MAPA_STATUS_DATABIT = {
  '00': 'Abertura Interna',
  '01': 'Abertura Web',
  '03': 'Encaminhado Técnico',
  '04': 'Em Atendimento',
  '05': 'Finalizado Técnico',
  '06': 'Concluída',
  '08': 'Cancelada'
};

function obterNomeStatus(codigo, item) {
  if (item && item.nomeStatus) {
    return item.nomeStatus;
  }
  const cod = String(codigo || '').trim();
  return MAPA_STATUS_DATABIT[cod] || ('STATUS ' + cod);
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
  const nomeStatus = obterNomeStatus(st, item);
  const modeloLimpo = simplificarModelo(item.modelo);
  const isExpandido = osExpandidas.has(item.os);

  const rightTag = isComTriagem
    ? `<span class="tag-tecnico" style="background-color: ${item.corTecnico || '#e2e8f0'}">${escapeHtml(item.tecnico)}</span>`
    : `<span class="tag-sla ${parseInt(item.sla) < 0 ? 'sla-atraso' : 'sla-normal'}">${item.sla}h</span>`;

  return `
    <div class="item-os ${atrasadoClass} ${isExpandido ? 'expandido' : ''}" id="card-${item.os}" onclick="toggleExpandirOS('${item.os}', event)" data-status="${st}" data-texto="${(item.os + ' ' + nomeStatus + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.local || '') + ' ' + (item.modelo || '') + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase()}">
      <!-- 1. RESUMO ELEGANTE & HIERÁRQUICO (CARD MODERNO) -->
      <div class="card-resumo">
        <!-- NÍVEL 1: META BAR (OS · CIDADE | STATUS · SLA/TÉCNICO · CHEVRON) -->
        <div class="card-linha-meta">
          <div class="meta-esq">
            <span class="os-num">${escapeHtml(item.os)}</span>
            <span class="dot-sep">·</span>
            <span class="badge-cidade">${escapeHtml(item.cidade || '')}</span>
          </div>
          <div class="meta-dir">
            <span class="tag-status tag-status-${st}">${escapeHtml(nomeStatus)}</span>
            ${rightTag}
            <span class="icone-expandir">›</span>
          </div>
        </div>

        <!-- NÍVEL 2: CLIENTE EM DESTAQUE (LINHA PRÓPRIA, 100% LEGÍVEL) -->
        <div class="card-linha-cliente">
          <span class="cliente-nome">${escapeHtml(item.cliente)}</span>
        </div>

        <!-- NÍVEL 3: CONTEXTO TÉCNICO HARMONIOSO (LOCAL + MODELO) -->
        <div class="card-linha-tecnica">
          ${item.local ? `<span class="chip-tecnico chip-local"><span class="chip-icon">🏢</span>${escapeHtml(item.local)}</span>` : ''}
          <span class="chip-tecnico chip-modelo"><span class="chip-icon">🖨️</span>${escapeHtml(modeloLimpo)}</span>
        </div>

        <!-- NÍVEL 4: PREVIEW DISCRETO DO DEFEITO (1 LINHA COM RETICÊNCIAS) -->
        <div class="card-linha-defeito">
          <span class="icone-defeito">🔧</span>
          <span class="texto-defeito">${escapeHtml(item.defeito || 'Não informado')}</span>
        </div>
      </div>

      <!-- 2. DETALHES EXPANSÍVEIS (MOSTRADOS AO TOCAR NA OS) -->
      <div class="card-detalhes" id="detalhes-${item.os}" style="display: ${isExpandido ? 'flex' : 'none'};">
        <div class="detalhes-grid">
          <div class="detalhe-item"><strong>Status Databit:</strong> ${escapeHtml(nomeStatus)} (${st})</div>
          <div class="detalhe-item"><strong>Modelo:</strong> ${escapeHtml(item.modelo || 'Não cadastrado')}</div>
          ${item.numserie ? `<div class="detalhe-item"><strong>Nº de Série:</strong> <span class="equip-serie">${escapeHtml(item.numserie)}</span></div>` : ''}
          ${item.local ? `<div class="detalhe-item"><strong>Local / Setor:</strong> ${escapeHtml(item.local)}</div>` : ''}
          ${isComTriagem && item.tecnico && item.tecnico !== 'A Definir' ? `<div class="detalhe-item"><strong>Técnico Atual:</strong> ${escapeHtml(item.tecnico)}</div>` : ''}
          <div class="detalhe-item detalhe-defeito-box">
            <strong>Defeito Completo:</strong>
            <div class="texto-defeito-completo">${escapeHtml(item.defeito || 'Não informado')}</div>
          </div>
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
      const nomeStatus = obterNomeStatus(st, item);
      const txt = (item.os + ' ' + nomeStatus + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.local || '') + ' ' + (item.modelo || '') + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase();
      
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

let osConhecidas = null;

function atualizarBadgeApp(qtd) {
  const num = parseInt(qtd, 10) || 0;

  // 1. App Badging API oficial (iOS 16.4+ na Tela de Início e Android PWA)
  if (num <= 0) {
    if ('clearAppBadge' in navigator) {
      navigator.clearAppBadge().catch(() => {});
    } else if ('setAppBadge' in navigator) {
      navigator.setAppBadge(0).catch(() => {});
    }
  } else if ('setAppBadge' in navigator) {
    navigator.setAppBadge(num).catch(() => {});
  }

  // 2. Enviar para Service Worker
  if (navigator.serviceWorker && navigator.serviceWorker.controller) {
    navigator.serviceWorker.controller.postMessage({
      type: 'SET_BADGE',
      count: num
    });
  }

  // 3. Atualizar Título da Aba: só mostra número se houver novas não vistas
  document.title = num > 0 ? `(${num}) Mil Cópias` : 'Mil Cópias';
}

function marcarTodasComoVistas() {
  if (dados.semTriagem) {
    const idsAtuais = dados.semTriagem.map(x => x.os);
    localStorage.setItem('milcopias_os_vistas', JSON.stringify(idsAtuais));
  }
  atualizarBadgeApp(0);
}

// Quando o usuário abre, foca ou toca no aplicativo, limpa o badge e marca como vistas
window.addEventListener('focus', marcarTodasComoVistas);
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    marcarTodasComoVistas();
  }
});
window.addEventListener('click', marcarTodasComoVistas, { passive: true });
window.addEventListener('touchstart', marcarTodasComoVistas, { passive: true });

function emitirSomAlerta() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    // Tom agradável de sino duplo
    osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
    osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12); // A5
    
    gain.gain.setValueAtTime(0.25, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start();
    osc.stop(ctx.currentTime + 0.35);
  } catch (e) {
    // Silencioso se navegador bloquear áudio pré-interação
  }
}

function notificarNovasOS(novasOS) {
  if (!novasOS || novasOS.length === 0) return;

  emitirSomAlerta();

  const qtd = novasOS.length;
  const primeiraOSNum = novasOS[0];
  const itemOS = (dados.semTriagem || []).find(x => x.os === primeiraOSNum);
  
  const titulo = qtd === 1 ? `🚨 Nova OS: ${primeiraOSNum}` : `🚨 ${qtd} Novas OS Recebidas!`;
  const corpo = itemOS 
    ? `${itemOS.cidade} - ${itemOS.cliente}\nDefeito: ${itemOS.defeito}`
    : `Você tem ${qtd} nova(s) Ordem(ns) de Serviço pendente(s).`;

  mostrarToast(`🔔 ${titulo} - ${itemOS ? itemOS.cliente : ''}`, 6000);

  // Disparar Notificação Nativa no celular/sistema
  if ('Notification' in window && Notification.permission === 'granted') {
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.ready) {
        navigator.serviceWorker.ready.then(reg => {
          reg.showNotification(titulo, {
            body: corpo,
            icon: 'apple-touch-icon.png',
            badge: 'apple-touch-icon.png',
            vibrate: [200, 100, 200],
            tag: `os-${primeiraOSNum}`,
            renotify: true
          });
        });
      } else {
        new Notification(titulo, {
          body: corpo,
          icon: 'apple-touch-icon.png'
        });
      }
    } catch (err) {
      console.warn('Erro ao emitir notificação nativa:', err);
    }
  }
}

async function solicitarPermissaoNotificacao() {
  if (!('Notification' in window)) {
    alert('Notificações não são suportadas neste navegador.');
    return;
  }

  try {
    const perm = await Notification.requestPermission();
    atualizarBotaoNotificacao(perm);

    if (perm === 'granted') {
      mostrarToast('🔔 Notificações ativadas! Você será alertado quando novas OS chegarem.');
      emitirSomAlerta();
      atualizarBadgeApp(dados.semTriagem.length);
    } else if (perm === 'denied') {
      alert('Para receber alertas no celular, permita as notificações nas configurações do navegador/Safari.');
    }
  } catch (err) {
    console.warn('Erro ao solicitar permissão:', err);
  }
}

function atualizarBotaoNotificacao(perm) {
  const btn = document.getElementById('btnNotificacao');
  const icone = document.getElementById('iconeSino');
  if (!btn || !icone) return;

  const status = perm || (window.Notification ? Notification.permission : 'default');
  if (status === 'granted') {
    btn.classList.add('ativo');
    icone.textContent = '🔔';
    btn.title = 'Notificações Ativas';
  } else {
    btn.classList.remove('ativo');
    icone.textContent = '🔕';
    btn.title = 'Toque para ativar notificações no celular';
  }
}

async function carregar() {
  try {
    const res = await fetch('/api/chamados');
    const json = await res.json();
    if (json.sucesso) {
      const novosSemTriagem = json.semTriagem || [];
      const novosComTriagem = json.comTriagem || [];

      // Detectar OS não vistas e novas
      const idsAtuais = novosSemTriagem.map(x => x.os);
      let vistas = [];
      try {
        vistas = JSON.parse(localStorage.getItem('milcopias_os_vistas') || '[]');
      } catch (e) {
        vistas = [];
      }

      const naoVistas = idsAtuais.filter(id => !vistas.includes(id));

      if (osConhecidas !== null) {
        const chegaram = idsAtuais.filter(id => !osConhecidas.has(id));
        if (chegaram.length > 0) {
          notificarNovasOS(chegaram);
        }
      }
      osConhecidas = new Set(idsAtuais);

      dados.semTriagem = novosSemTriagem;
      dados.comTriagem = novosComTriagem;
      
      // Se o usuário está com o aplicativo aberto, todas foram visualizadas: zera o badge!
      if (document.visibilityState === 'visible') {
        localStorage.setItem('milcopias_os_vistas', JSON.stringify(idsAtuais));
        atualizarBadgeApp(0);
      } else {
        // Se estiver em segundo plano, o badge mostra apenas as novas que ainda não foram vistas
        atualizarBadgeApp(naoVistas.length);
      }

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
      mostrarToast(`✅ OS ${osSelecionadaModal} atribuída com sucesso! Atualizada para ENCAMINHADO TECNICO.`);
      fecharModalTecnico();
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

// Registrar Service Worker para PWA, Notificações e Badging
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js?v=20261019_03')
    .then(reg => console.log('Service Worker ativo:', reg.scope))
    .catch(err => console.warn('SW:', err));
}

atualizarBotaoNotificacao();
carregarTecnicos();
carregar();
