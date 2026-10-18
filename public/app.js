/**
 * Frontend Ultra-Compacto para Celular (Mil Cópias)
 * Sem '#' na frente da OS
 * Nome do cliente completo, sem cortes
 * Defeito real puxado da API do Databit
 */

let dados = { semTriagem: [], comTriagem: [] };
let abaAtiva = 'sem-triagem';

function trocarAba(aba) {
  abaAtiva = aba;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-conteudo').forEach(c => c.classList.remove('active'));

  if (aba === 'sem-triagem') {
    document.getElementById('btn-sem-triagem').classList.add('active');
    document.getElementById('aba-sem-triagem').classList.add('active');
  } else {
    document.getElementById('btn-com-triagem').classList.add('active');
    document.getElementById('aba-com-triagem').classList.add('active');
  }
}

function criarItemHTML(item, isComTriagem) {
  const atrasadoClass = item.atrasado ? 'atrasado' : '';
  const rightTag = isComTriagem
    ? `<span class="tag-tecnico" style="background-color: ${item.corTecnico || '#cbd5e1'}">${item.tecnico}</span>`
    : `<span class="tag-sla ${parseInt(item.sla) < 0 ? 'sla-atraso' : 'sla-normal'}">${item.sla}h</span>`;

  return `
    <div class="item-os ${atrasadoClass}" data-texto="${(item.os + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase()}">
      <!-- LINHA 1: OS + Cliente Completo + Cidade + SLA/Técnico -->
      <div class="linha-1">
        <div class="os-cli">
          <div class="os-topo-info">
            <span class="os-num">${item.os}</span>
            <span class="cidade-nome">📍 ${item.cidade || ''}</span>
          </div>
          <div class="cliente-nome">${item.cliente}</div>
        </div>
        <div class="tags-status">
          ${rightTag}
        </div>
      </div>
      <!-- LINHA 2: O DEFEITO REAL DO DATABIT -->
      <div class="linha-2">
        <span class="defeito-icone">🔧</span>
        <span class="defeito-texto">${item.defeito || 'Defeito não informado'}</span>
      </div>
    </div>
  `;
}

function renderizar() {
  const termo = document.getElementById('campoBusca').value.toLowerCase().trim();
  const filtrarLista = (lista) => {
    if (!termo) return lista;
    return lista.filter(item => {
      const txt = (item.os + ' ' + item.cliente + ' ' + item.cidade + ' ' + (item.tecnico || '') + ' ' + (item.defeito || '')).toLowerCase();
      return txt.includes(termo);
    });
  };

  const semTriagemFiltrados = filtrarLista(dados.semTriagem);
  const comTriagemFiltrados = filtrarLista(dados.comTriagem);

  document.getElementById('badgeSemTriagem').textContent = semTriagemFiltrados.length;
  document.getElementById('badgeComTriagem').textContent = comTriagemFiltrados.length;

  const aba1 = document.getElementById('aba-sem-triagem');
  const aba2 = document.getElementById('aba-com-triagem');

  if (semTriagemFiltrados.length === 0) {
    aba1.innerHTML = '<div class="vazio">Nenhum chamado sem triagem.</div>';
  } else {
    aba1.innerHTML = semTriagemFiltrados.map(i => criarItemHTML(i, false)).join('');
  }

  if (comTriagemFiltrados.length === 0) {
    aba2.innerHTML = '<div class="vazio">Nenhum chamado com triagem.</div>';
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
