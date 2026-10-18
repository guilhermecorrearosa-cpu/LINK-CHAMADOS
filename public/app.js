/**
 * Frontend Ultra-Compacto para Celular (Mil Cópias)
 * Sem '#' na frente da OS
 * Nome do cliente completo, sem cortes
 * Cidade em destaque chamativo
 * Defeito chamativo em caixa de alerta âmbar
 * Modelo do equipamento e número de série
 * Exibição de Status 00, Status 01 e filtros rápidos
 */

let dados = { semTriagem: [], comTriagem: [] };
let abaAtiva = 'sem-triagem';
let subStatusAtivo = '';

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

function criarItemHTML(item, isComTriagem) {
  const atrasadoClass = item.atrasado ? 'atrasado' : '';
  const st = item.status || (isComTriagem ? '03' : '00');
  const modelo = item.modelo || 'Equipamento não cadastrado';
  const serie = item.numserie ? `<span class="equip-serie">(${item.numserie})</span>` : '';
  
  const rightTag = isComTriagem
    ? `<span class="tag-tecnico" style="background-color: ${item.corTecnico || '#cbd5e1'}">${item.tecnico}</span>`
    : `<span class="tag-sla ${parseInt(item.sla) < 0 ? 'sla-atraso' : 'sla-normal'}">${item.sla}h</span>`;

  return `
    <div class="item-os ${atrasadoClass}" data-status="${st}" data-texto="${(item.os + ' status ' + st + ' st' + st + ' ' + item.cliente + ' ' + item.cidade + ' ' + modelo + ' ' + (item.numserie || '') + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase()}">
      <!-- LINHA 1: OS + Status + Cidade Chamativa + SLA/Técnico -->
      <div class="linha-1">
        <div class="os-cli">
          <div class="os-topo-info">
            <span class="os-num">${item.os}</span>
            <span class="tag-status tag-status-${st}">Status ${st}</span>
            <span class="badge-cidade">📍 ${item.cidade || ''}</span>
          </div>
          <div class="cliente-nome">${item.cliente}</div>
          <div class="linha-equip">
            🖨️ <span class="equip-modelo">${modelo}</span>
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
        <span class="defeito-texto">${item.defeito || 'Defeito não informado'}</span>
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

carregar();
