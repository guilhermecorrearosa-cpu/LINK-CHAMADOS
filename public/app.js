/**
 * Painel de Chamados Mil Cópias - Versão 2.0 (Mobile First)
 * Estilo: Dark Titanium Pro (OLED) & Clean Studio iOS
 * Inovações:
 * 1. Thumb-Zone Floating Bottom Dock (alcance fácil do polegar)
 * 2. Bottom Sheet Nativa para Ações e Detalhes
 * 3. Cards Bento 2.0 com Código de Rotas por Cidade
 * 4. Quick Triage Strip (Mini-KPIs com 1 toque)
 * 5. Alternador de Tema Instantâneo (🌙 Dark OLED / ☀️ Clean iOS Glass)
 */

let dados = { semTriagem: [], comTriagem: [] };
let listaTecnicos = [];
let abaAtiva = 'sem-triagem';
let filtroRapidoAtivo = 'todas';
let osSelecionadaSheet = null;
let osConhecidas = null;

const MAPA_STATUS_DATABIT = {
  '00': 'Abertura Interna',
  '01': 'Abertura Web',
  '03': 'Encaminhado Técnico',
  '04': 'Em Atendimento',
  '05': 'Finalizado Técnico',
  '06': 'Concluída',
  '08': 'Cancelada'
};

/* ==========================================================
   1. GERENCIAMENTO DE TEMA (DARK TITANIUM / LIGHT GLASS)
   ========================================================== */
function iniciarTema() {
  const temaSalvo = localStorage.getItem('milcopias_theme_2') || 'dark';
  aplicarTema(temaSalvo);
}

function alternarTema() {
  const temaAtual = document.documentElement.getAttribute('data-theme') || 'dark';
  const novoTema = temaAtual === 'dark' ? 'light' : 'dark';
  aplicarTema(novoTema);
  localStorage.setItem('milcopias_theme_2', novoTema);
}

function aplicarTema(tema) {
  document.documentElement.setAttribute('data-theme', tema);
  const icone = document.getElementById('iconeTema');
  if (icone) {
    icone.textContent = tema === 'dark' ? '🌙' : '☀️';
  }
  const metaTheme = document.querySelector('meta[name="theme-color"]');
  if (metaTheme) {
    metaTheme.setAttribute('content', tema === 'dark' ? '#070a12' : '#f4f6fa');
  }
}

/* ==========================================================
   2. CONTROLE DE ROTAS POR CIDADE (CÓDIGO DE CORES)
   ========================================================== */
function obterClasseRota(cidade) {
  const cid = (cidade || '').toUpperCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  if (cid.includes('LINHARES')) return 'rota-linhares';
  if (cid.includes('COLATINA')) return 'rota-colatina';
  if (cid.includes('ARACRUZ')) return 'rota-aracruz';
  if (cid.includes('JAGUARE')) return 'rota-jaguare';
  return 'rota-padrao';
}

function obterNomeStatus(codigo, item) {
  if (item && item.nomeStatus) {
    return item.nomeStatus;
  }
  const cod = String(codigo || '').trim();
  return MAPA_STATUS_DATABIT[cod] || ('Status ' + cod);
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

/* ==========================================================
   3. NAVEGAÇÃO THUMB-ZONE & FILTROS RÁPIDOS
   ========================================================== */
function trocarAba(aba) {
  abaAtiva = aba;
  
  const dockSem = document.getElementById('dockSemTriagem');
  const dockCom = document.getElementById('dockComTriagem');
  const viewSem = document.getElementById('aba-sem-triagem');
  const viewCom = document.getElementById('aba-com-triagem');
  const strip = document.getElementById('triageStrip');

  if (aba === 'sem-triagem') {
    dockSem.classList.add('active');
    dockCom.classList.remove('active');
    viewSem.classList.add('active');
    viewCom.classList.remove('active');
    if (strip) strip.style.display = 'flex';
  } else {
    dockSem.classList.remove('active');
    dockCom.classList.add('active');
    viewSem.classList.remove('active');
    viewCom.classList.add('active');
    if (strip) strip.style.display = 'none';
  }

  renderizar();
}

function selecionarFiltroRapido(tipo) {
  filtroRapidoAtivo = tipo;
  
  document.querySelectorAll('.triage-chip').forEach(c => c.classList.remove('active'));
  if (tipo === 'todas') {
    document.getElementById('chipTodas')?.classList.add('active');
  } else if (tipo === 'atrasadas') {
    document.getElementById('chipAtrasadas')?.classList.add('active');
  } else if (tipo === '01') {
    document.getElementById('chipWeb')?.classList.add('active');
  } else if (tipo === '00') {
    document.getElementById('chipInterna')?.classList.add('active');
  }

  renderizar();
}

function focarBusca() {
  const input = document.getElementById('campoBusca');
  if (input) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    input.focus();
  }
}

function filtrar() {
  renderizar();
}

/* ==========================================================
   4. RENDERIZAÇÃO DOS CARDS BENTO 2.0
   ========================================================== */
function criarCardHTML(item, isComTriagem) {
  const atrasadoClass = item.atrasado ? 'atrasado' : '';
  const st = item.status || (isComTriagem ? '03' : '00');
  const modeloLimpo = simplificarModelo(item.modelo);
  const classeRota = obterClasseRota(item.cidade);

  const rightTag = isComTriagem
    ? `<span class="badge-tecnico-nome" style="background-color: ${item.corTecnico || '#38bdf8'}">${escapeHtml(item.tecnico)}</span>`
    : `<span class="ticket-chevron">›</span>`;

  return `
    <article class="ticket-card ${atrasadoClass}" id="card-${item.os}" onclick="abrirBottomSheetPorId('${item.os}', ${isComTriagem})" data-status="${st}">
      <!-- LINHA 1: OS + CIDADE | TÉCNICO OU CHEVRON -->
      <div class="ticket-topo">
        <div class="ticket-origem">
          <span class="ticket-os-num">${escapeHtml(item.os)}</span>
          <span class="badge-rota ${classeRota}">${escapeHtml(item.cidade || 'GERAL')}</span>
        </div>
        <div class="ticket-acoes-dir">
          ${rightTag}
        </div>
      </div>

      <!-- LINHA 2: CLIENTE (DESTAQUE MÁXIMO) -->
      <div class="ticket-cliente-nome">
        ${escapeHtml(item.cliente)}
      </div>

      <!-- LINHA 3: CONTEXTO TÉCNICO & DEFEITO (1 LINHA RESUMIDA) -->
      <div class="ticket-linha-resumo">
        <span class="resumo-chip-mod">🖨️ ${escapeHtml(modeloLimpo)}</span>
        ${item.local ? `<span class="dot-sep-inline">·</span><span class="resumo-chip-loc">🏢 ${escapeHtml(item.local)}</span>` : ''}
        <span class="dot-sep-inline">·</span>
        <span class="resumo-chip-def">🔧 ${escapeHtml(item.defeito || 'Sem defeito')}</span>
      </div>
    </article>
  `;
}

function renderizar() {
  const termo = document.getElementById('campoBusca').value.toLowerCase().trim();

  const filtrarLista = (lista, aplicarFiltrosRapidos) => {
    return lista.filter(item => {
      const st = item.status || '00';
      const nomeStatus = obterNomeStatus(st, item);
      const txt = (item.os + ' ' + nomeStatus + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.local || '') + ' ' + (item.modelo || '') + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase();
      
      const bateTexto = !termo || txt.includes(termo);
      
      if (!bateTexto) return false;
      if (!aplicarFiltrosRapidos) return true;

      if (filtroRapidoAtivo === 'atrasadas') {
        return !!item.atrasado;
      } else if (filtroRapidoAtivo === '01') {
        return st === '01';
      } else if (filtroRapidoAtivo === '00') {
        return st === '00';
      }
      return true;
    });
  };

  const semTriagemFiltrados = filtrarLista(dados.semTriagem, true);
  const comTriagemFiltrados = filtrarLista(dados.comTriagem, false);

  // Atualizar Contadores da Top Strip e Bottom Dock
  const totalSem = dados.semTriagem.length;
  const totalCom = dados.comTriagem.length;
  const totalAtrasadas = dados.semTriagem.filter(x => x.atrasado).length;
  const totalWeb = dados.semTriagem.filter(x => (x.status || '00') === '01').length;
  const totalInterna = dados.semTriagem.filter(x => (x.status || '00') === '00').length;

  const elTotalGeral = document.getElementById('badgeTotalGeral');
  if (elTotalGeral) elTotalGeral.textContent = totalSem;

  const elTotalAtrasadas = document.getElementById('badgeTotalAtrasadas');
  if (elTotalAtrasadas) elTotalAtrasadas.textContent = totalAtrasadas;

  const elTotalWeb = document.getElementById('badgeTotalWeb');
  if (elTotalWeb) elTotalWeb.textContent = totalWeb;

  const elTotalInterna = document.getElementById('badgeTotalInterna');
  if (elTotalInterna) elTotalInterna.textContent = totalInterna;

  const elDockBadgeSem = document.getElementById('dockBadgeSem');
  if (elDockBadgeSem) elDockBadgeSem.textContent = totalSem;

  const elDockBadgeCom = document.getElementById('dockBadgeCom');
  if (elDockBadgeCom) elDockBadgeCom.textContent = totalCom;

  // Renderizar Container Sem Triagem
  const cSem = document.getElementById('aba-sem-triagem');
  if (cSem) {
    if (semTriagemFiltrados.length === 0) {
      cSem.innerHTML = '<div class="vazio-msg">✨ Nenhum chamado encontrado nesta categoria.</div>';
    } else {
      cSem.innerHTML = semTriagemFiltrados.map(item => criarCardHTML(item, false)).join('');
    }
  }

  // Renderizar Container Com Triagem
  const cCom = document.getElementById('aba-com-triagem');
  if (cCom) {
    if (comTriagemFiltrados.length === 0) {
      cCom.innerHTML = '<div class="vazio-msg">Nenhum chamado atribuído no momento.</div>';
    } else {
      cCom.innerHTML = comTriagemFiltrados.map(item => criarCardHTML(item, true)).join('');
    }
  }
}

/* ==========================================================
   5. NATIVE BOTTOM SHEET (AÇÕES & DETALHES RÁPIDOS)
   ========================================================== */
function abrirBottomSheetPorId(osNum, isComTriagem) {
  const lista = isComTriagem ? dados.comTriagem : dados.semTriagem;
  const item = lista.find(x => x.os === osNum) || (dados.semTriagem.concat(dados.comTriagem)).find(x => x.os === osNum);
  if (!item) return;

  osSelecionadaSheet = item;

  document.getElementById('sheetOsNum').textContent = `OS ${item.os}`;
  
  const bRota = document.getElementById('sheetBadgeRota');
  bRota.textContent = item.cidade || 'GERAL';
  bRota.className = `badge-rota ${obterClasseRota(item.cidade)}`;

  document.getElementById('sheetCliente').textContent = item.cliente || '';
  document.getElementById('sheetStatus').textContent = `${obterNomeStatus(item.status, item)} (${item.status || '00'})`;
  document.getElementById('sheetModelo').textContent = item.modelo || 'Equipamento não cadastrado';

  const rowSerie = document.getElementById('sheetRowSerie');
  if (item.numserie) {
    rowSerie.style.display = 'flex';
    document.getElementById('sheetNumSerie').textContent = item.numserie;
  } else {
    rowSerie.style.display = 'none';
  }

  const rowLocal = document.getElementById('sheetRowLocal');
  if (item.local) {
    rowLocal.style.display = 'flex';
    document.getElementById('sheetLocal').textContent = item.local;
  } else {
    rowLocal.style.display = 'none';
  }

  const rowTec = document.getElementById('sheetRowTecAtual');
  if (isComTriagem && item.tecnico && item.tecnico !== 'A Definir') {
    rowTec.style.display = 'flex';
    document.getElementById('sheetTecnicoAtual').textContent = item.tecnico;
  } else {
    rowTec.style.display = 'none';
  }

  document.getElementById('sheetDefeito').textContent = item.defeito || 'Sem detalhes informados';

  // Popular Select de Técnicos
  popularSelectTecnicosSheet(item.codtec || '');

  // Abrir Sheet com Animação Nativa
  const backdrop = document.getElementById('sheetBackdrop');
  backdrop.classList.add('aberto');
  document.body.style.overflow = 'hidden';
}

function fecharBottomSheet() {
  const backdrop = document.getElementById('sheetBackdrop');
  if (backdrop) backdrop.classList.remove('aberto');
  document.body.style.overflow = '';
  osSelecionadaSheet = null;
}

function fecharSheetSeFora(e) {
  if (e.target && e.target.id === 'sheetBackdrop') {
    fecharBottomSheet();
  }
}

function popularSelectTecnicosSheet(codtecPreselecionado = '') {
  const select = document.getElementById('sheetSelectTecnico');
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

async function confirmarDefinicaoTecnicoSheet() {
  if (!osSelecionadaSheet) return;

  const select = document.getElementById('sheetSelectTecnico');
  const codtec = select.value;
  const nomeTecnico = select.options[select.selectedIndex]?.text || '';
  const btn = document.getElementById('sheetBtnConfirmar');

  if (!codtec) {
    alert('Por favor, selecione um técnico na lista.');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Gravando no Databit ERP...';

  try {
    const res = await fetch('/api/definir-tecnico', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        os: osSelecionadaSheet.os,
        codtec: codtec,
        nomeTecnico: nomeTecnico
      })
    });

    const json = await res.json();
    if (json.sucesso) {
      mostrarToast(`✅ OS ${osSelecionadaSheet.os} encaminhada com sucesso!`);
      fecharBottomSheet();
      await carregar();
    } else {
      alert(`Erro ao atribuir técnico: ${json.erro || 'Falha no Databit.'}`);
    }
  } catch (err) {
    alert(`Erro de comunicação com o servidor: ${err.message}`);
  } finally {
    btn.disabled = false;
    btn.textContent = '⚡ Confirmar Despacho (Status 03)';
  }
}

/* ==========================================================
   6. NOTIFICAÇÕES & ÁUDIO DE ALERTA NO CELULAR
   ========================================================== */
function atualizarBadgeApp(quantidade) {
  try {
    if ('setAppBadge' in navigator) {
      if (quantidade > 0) {
        navigator.setAppBadge(quantidade);
      } else {
        navigator.clearAppBadge();
      }
    }
  } catch (e) {
    console.warn('Erro ao atualizar badge:', e);
  }
}

function emitirSomAlerta() {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;
    const ctx = new AudioContext();

    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.3);

    gain.gain.setValueAtTime(0.3, ctx.currentTime);
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

  mostrarToast(`🔔 ${titulo}`, 5000);

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
      mostrarToast('🔔 Notificações ativadas com sucesso!');
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

/* ==========================================================
   7. CARREGAMENTO DE DADOS & API DATABIT
   ========================================================== */
async function carregar() {
  try {
    const res = await fetch('/api/chamados');
    const json = await res.json();
    if (json.sucesso) {
      const novosSemTriagem = json.semTriagem || [];
      const novosComTriagem = json.comTriagem || [];

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

      if (document.visibilityState === 'visible') {
        localStorage.setItem('milcopias_os_vistas', JSON.stringify(idsAtuais));
        atualizarBadgeApp(0);
      } else {
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
    }
  } catch (err) {
    console.error('Erro ao carregar técnicos:', err);
  }
}

function mostrarToast(msg, duracao = 4000) {
  const t = document.getElementById('toastMsg');
  if (!t) return;
  t.textContent = msg;
  t.style.display = 'block';
  setTimeout(() => {
    t.style.display = 'none';
  }, duracao);
}

// Timer de Auto-Refresh (60s)
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
  navigator.serviceWorker.register('sw.js?v=20261020_02')
    .then(reg => console.log('Service Worker ativo:', reg.scope))
    .catch(err => console.warn('SW:', err));
}

// Inicialização
iniciarTema();
atualizarBotaoNotificacao();
carregarTecnicos();
carregar();
