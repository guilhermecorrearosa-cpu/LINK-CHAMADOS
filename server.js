require('dotenv').config();
const express = require('express');
const cors = require('cors');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

const API_BASE_URL = process.env.DATABIT_API_URL || 'http://databitbh.com:54235/databitservice/';
const CHAM1_URL = process.env.CHAM1_URL || 'http://databitbh.com:51202/mc/cham1.php';

// Mapeamento padrão dos nomes oficiais do Databit (StatusOSController)
const NOMES_STATUS_PADRAO = {
  '00': 'ABERTURA OS INTERNA',
  '01': 'ABERTURA DE OS WEB',
  '03': 'ENCAMINHADO TECNICO',
  '04': 'EM ATENDIMENTO',
  '05': 'FINALIZADO TECNICO',
  '06': 'OS CONCLUIDA',
  '08': 'OS NAO ATENDIDA - CANCELADA MOBILE',
  '10': '(CONFERENCIA) RETORNO EQUIPAMENTO',
  '11': 'ESTOQUE REVISÃO',
  '14': 'PENDENTE ENTREGA',
  '16': 'ATENDIMENTO INTERNO'
};

const TECNICOS_FALLBACK = [
  { codigo: '0071', nome: 'FABIANO' },
  { codigo: '0083', nome: 'LEIDIMAR' },
  { codigo: '0081', nome: 'MATHEUS' },
  { codigo: '0046', nome: 'GUILHERME' },
  { codigo: '0059', nome: 'SOLANO' },
  { codigo: '0041', nome: 'FELIPE' },
  { codigo: '0034', nome: 'BRENO' },
  { codigo: '0016', nome: 'RILIANE' },
  { codigo: '0010', nome: 'FABIO' },
  { codigo: '0085', nome: 'NINIVE' },
  { codigo: '0074', nome: 'GABRIEL' },
  { codigo: '0076', nome: 'DARLAN' },
  { codigo: '0078', nome: 'JEAN' },
  { codigo: '0045', nome: 'NILTON' },
  { codigo: '0018', nome: 'SUDESTE' },
  { codigo: '0064', nome: 'BRENO INT' },
  { codigo: '0000', nome: 'UNICO' }
];

const CORES_TECNICOS = {
  '0071': '#deb887', // FABIANO
  '0083': '#42b3f5', // LEIDIMAR
  '0081': '#10b981', // MATHEUS
  '0046': '#f0e68c', // GUILHERME
  '0059': '#40e0d0', // SOLANO
  '0041': '#ff8c00', // FELIPE
  '0034': '#a78bfa', // BRENO
  '0016': '#dda0dd', // RILIANE
  '0010': '#7b68ee', // FABIO
  '0085': '#ec4899', // NINIVE
  '0074': '#38bdf8', // GABRIEL
  '0076': '#fb923c', // DARLAN
  '0078': '#f43f5e', // JEAN
  '0045': '#84cc16', // NILTON
  '0018': '#a9a9a9', // SUDESTE
  '0064': '#64748b', // BRENO INT
  '0000': '#94a3b8'  // UNICO
};

function formatarTimestamp(ms) {
  if (!ms) return '';
  const d = new Date(Number(ms));
  if (isNaN(d.getTime())) return '';
  const dia = String(d.getDate()).padStart(2, '0');
  const mes = String(d.getMonth() + 1).padStart(2, '0');
  const ano = d.getFullYear();
  const hor = String(d.getHours()).padStart(2, '0');
  const min = String(d.getMinutes()).padStart(2, '0');
  return `${dia}/${mes}/${ano} ${hor}:${min}`;
}

/**
 * Consulta a API do Databit ERP em lote para obter motivo/defeito real, status oficial e modelo
 */
async function carregarDetalhesReais(listaOS) {
  if (!listaOS || listaOS.length === 0) return {};

  const mapaResultado = {};
  const osUnicas = [...new Set(listaOS)];
  const inClause = osUnicas.join("','");

  try {
    // 1. Buscar status oficiais no StatusOSController do Databit
    let mapaNomesStatus = { ...NOMES_STATUS_PADRAO };
    try {
      const resStatus = await fetch(`${API_BASE_URL}StatusOSController/listar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filtro: "situacao = 'A'" }),
        signal: AbortSignal.timeout(4000)
      });
      if (resStatus.ok) {
        const jsonStatus = await resStatus.json();
        const itensStatus = jsonStatus.value || (Array.isArray(jsonStatus) ? jsonStatus : []);
        for (const s of itensStatus) {
          if (s.codigo && s.nome) {
            mapaNomesStatus[s.codigo.trim()] = s.nome.trim();
          }
        }
      }
    } catch (e) {
      // Usa fallback
    }

    // 2. Buscar dados principais das OS em lote (motivo, observação, statusos, produto, numserie, codtec)
    const resOS = await fetch(`${API_BASE_URL}OsController/listar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ filtro: `codigo in ('${inClause}') and situacao = 'A'` }),
      signal: AbortSignal.timeout(6000)
    });

    const dadosOS = {};
    const codigosProdutos = [];
    if (resOS.ok) {
      const jsonOS = await resOS.json();
      const itensOS = jsonOS.value || (Array.isArray(jsonOS) ? jsonOS : []);
      for (const o of itensOS) {
        const codProd = (o.produto || '').trim();
        if (codProd) {
          codigosProdutos.push(codProd);
        }
        dadosOS[o.codigo] = {
          motivo: (o.motivo || '').trim(),
          obs: (o.obs || '').trim(),
          statusos: (o.statusos || '00').trim(),
          produto: codProd,
          numserie: (o.numserie || '').trim(),
          codtec: (o.codtec || '0000').trim()
        };
      }
    }

    // 3. Buscar nomes dos produtos / modelos de equipamento
    let mapaProdutos = {};
    if (codigosProdutos.length > 0) {
      const prodsUnicos = [...new Set(codigosProdutos)];
      const inProd = prodsUnicos.join("','");
      try {
        const resProd = await fetch(`${API_BASE_URL}ProdutoController/listar`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ filtro: `codigo in ('${inProd}')` }),
          signal: AbortSignal.timeout(5000)
        });
        if (resProd.ok) {
          const jsonProd = await resProd.json();
          const itensProd = jsonProd.value || (Array.isArray(jsonProd) ? jsonProd : []);
          for (const p of itensProd) {
            if (p.codigo && p.nome) {
              mapaProdutos[p.codigo] = p.nome.trim();
            }
          }
        }
      } catch (e) {
        // Ignora falha de busca de produto
      }
    }

    // 4. Buscar ocorrências de defeitos (OsDefeitoController)
    const resDef = await fetch(`${API_BASE_URL}OsDefeitoController/listar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ filtro: `codigo in ('${inClause}')` }),
      signal: AbortSignal.timeout(6000)
    });

    const dadosDef = {};
    const codigosCatalogo = [];
    if (resDef.ok) {
      const jsonDef = await resDef.json();
      const itensDef = jsonDef.value || (Array.isArray(jsonDef) ? jsonDef : []);
      for (const d of itensDef) {
        const codOS = d.codigo;
        const obsDef = (d.obs || '').trim();
        const defCod = (d.defeito || '').trim();

        if (defCod && defCod !== '0219') {
          codigosCatalogo.push(defCod);
        }
        if (!dadosDef[codOS]) {
          dadosDef[codOS] = [];
        }
        dadosDef[codOS].push({ obs: obsDef, codigo: defCod });
      }
    }

    // 5. Carregar catálogo de defeitos se houver códigos numéricos
    let mapaCatalogo = {};
    if (codigosCatalogo.length > 0) {
      const catUnicos = [...new Set(codigosCatalogo)];
      const inCat = catUnicos.join("','");
      try {
        const resCat = await fetch(`${API_BASE_URL}DefeitoController/listar`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ filtro: `codigo in ('${inCat}')` }),
          signal: AbortSignal.timeout(5000)
        });
        if (resCat.ok) {
          const jsonCat = await resCat.json();
          const itensCat = jsonCat.value || (Array.isArray(jsonCat) ? jsonCat : []);
          for (const c of itensCat) {
            if (c.codigo && c.nome) {
              mapaCatalogo[c.codigo] = c.nome.trim();
            }
          }
        }
      } catch (e) {
        // Ignora falha de catálogo
      }
    }

    // 6. Consolidar os dados de cada OS
    for (const numOS of osUnicas) {
      let defeitoFinal = '';
      const statusFinal = (dadosOS[numOS] && dadosOS[numOS].statusos) ? dadosOS[numOS].statusos : '00';
      const numserieFinal = (dadosOS[numOS] && dadosOS[numOS].numserie) ? dadosOS[numOS].numserie : '';
      const codProd = (dadosOS[numOS] && dadosOS[numOS].produto) ? dadosOS[numOS].produto : '';
      const codtecFinal = (dadosOS[numOS] && dadosOS[numOS].codtec) ? dadosOS[numOS].codtec : '0000';
      const modeloFinal = mapaProdutos[codProd] || 'Equipamento não cadastrado';
      const nomeStatusOficial = mapaNomesStatus[statusFinal] || `STATUS ${statusFinal}`;

      // 1ª Prioridade: Motivo direto da OS
      if (dadosOS[numOS] && dadosOS[numOS].motivo) {
        defeitoFinal = dadosOS[numOS].motivo;
      }

      // 2ª Prioridade: Observação do defeito ou nome do catálogo
      if (!defeitoFinal && dadosDef[numOS]) {
        for (const itemD of dadosDef[numOS]) {
          if (itemD.obs && !itemD.obs.toUpperCase().includes('HELPDESK')) {
            defeitoFinal = itemD.obs;
            break;
          }
          if (itemD.codigo && mapaCatalogo[itemD.codigo]) {
            defeitoFinal = mapaCatalogo[itemD.codigo];
            break;
          }
        }
      }

      // 3ª Prioridade: Observação geral da OS
      if (!defeitoFinal && dadosOS[numOS] && dadosOS[numOS].obs) {
        defeitoFinal = dadosOS[numOS].obs;
      }

      if (defeitoFinal) {
        defeitoFinal = defeitoFinal.replace(/^[- \t\n\r]+/, '');
      } else {
        defeitoFinal = 'Defeito não detalhado no sistema';
      }

      mapaResultado[numOS] = {
        defeito: defeitoFinal,
        status: statusFinal,
        nomeStatus: nomeStatusOficial,
        modelo: modeloFinal,
        numserie: numserieFinal,
        codtec: codtecFinal
      };
    }
  } catch (err) {
    console.error('Erro ao consultar API Databit:', err.message);
  }

  return mapaResultado;
}

/**
 * Baixa o HTML ao vivo do cham1.php original (timeout curto de 2.5s)
 */
async function baixarCham1Original() {
  const res = await fetch(CHAM1_URL, {
    headers: { 'User-Agent': 'Mozilla/5.0 (Mobile)' },
    signal: AbortSignal.timeout(2500)
  });
  if (!res.ok) {
    throw new Error(`Erro ao buscar cham1.php: HTTP ${res.status}`);
  }
  const buffer = await res.arrayBuffer();
  const decoder = new TextDecoder('utf-8');
  return decoder.decode(buffer);
}

/**
 * Consulta direta e resiliente à API oficial do Databit ERP
 * Usada automaticamente sempre que cham1.php estiver offline ou instável
 */
async function buscarChamadosDiretoDatabit() {
  console.log('[DATABIT DIRETO] Consultando OSs ativas diretamente na API Databit...');
  
  // Buscar todas as OSs ativas recentes (status 00, 01, 03, 04)
  const resOS = await fetch(`${API_BASE_URL}OsController/listar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({ filtro: "codigo >= '052000' and statusos in ('00', '01', '03', '04') and situacao = 'A'" }),
    signal: AbortSignal.timeout(8000)
  });

  if (!resOS.ok) {
    throw new Error(`Erro ao consultar OsController no Databit: HTTP ${resOS.status}`);
  }

  const jsonOS = await resOS.json();
  const listaOS = jsonOS.value || (Array.isArray(jsonOS) ? jsonOS : []);

  // 1. Mapear clientes em lote
  const codsCli = [...new Set(listaOS.map(x => (x.codcli || '').trim()).filter(Boolean))];
  const mapaCli = {};
  if (codsCli.length > 0) {
    try {
      const inCli = codsCli.join("','");
      const resCli = await fetch(`${API_BASE_URL}ClienteController/listar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filtro: `codigo in ('${inCli}')` }),
        signal: AbortSignal.timeout(5000)
      });
      if (resCli.ok) {
        const jsonCli = await resCli.json();
        const itensCli = jsonCli.value || (Array.isArray(jsonCli) ? jsonCli : []);
        itensCli.forEach(c => {
          if (c.codigo) {
            mapaCli[c.codigo.trim()] = (c.nome || c.fantasia || '').trim();
          }
        });
      }
    } catch (e) {
      console.warn('[DATABIT DIRETO] Falha ao carregar nomes de clientes:', e.message);
    }
  }

  // 2. Mapear técnicos
  const mapaTec = {};
  TECNICOS_FALLBACK.forEach(t => { mapaTec[t.codigo] = t.nome; });
  try {
    const resTec = await fetch(`${API_BASE_URL}TecnicoController/listar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filtro: "codigo is not null" }),
      signal: AbortSignal.timeout(4000)
    });
    if (resTec.ok) {
      const jsonTec = await resTec.json();
      const itensTec = jsonTec.value || (Array.isArray(jsonTec) ? jsonTec : []);
      itensTec.forEach(t => {
        if (t.codigo && t.nome) mapaTec[t.codigo.trim()] = t.nome.trim();
      });
    }
  } catch (e) {}

  // 3. Buscar defeitos reais, produtos e modelos
  const codigosOS = listaOS.map(x => x.codigo);
  const detalhes = await carregarDetalhesReais(codigosOS);

  const semTriagem = [];
  const comTriagem = [];
  const agora = Date.now();

  for (const item of listaOS) {
    const osNum = item.codigo;
    const codtec = (item.codtec || '').trim();
    const statusos = (item.statusos || '00').trim();
    const nomeTec = mapaTec[codtec] || (codtec && codtec !== '0000' ? `TÉCNICO ${codtec}` : 'A Definir');
    const corTec = CORES_TECNICOS[codtec] || '#cbd5e1';
    const cliente = mapaCli[item.codcli] || item.solicitante || 'Cliente Databit';
    const local = (item.local || '').trim() || 'Geral';
    const cidade = (item.cidade || '').trim() || 'LINHARES';
    const dataFormatada = formatarTimestamp(item.data);

    const horasAberto = item.data ? Math.round((agora - Number(item.data)) / (1000 * 60 * 60)) : 0;
    const isAtrasado = horasAberto > 24;

    const info = detalhes[osNum] || {
      defeito: item.motivo || item.obs || 'Defeito em aberto',
      status: statusos,
      nomeStatus: NOMES_STATUS_PADRAO[statusos] || `STATUS ${statusos}`,
      modelo: 'Equipamento não cadastrado',
      numserie: item.numserie || '',
      codtec: codtec
    };

    const osObj = {
      os: osNum,
      cliente: cliente,
      local: local,
      cidade: cidade,
      origem: item.origem === 'W' || statusos === '01' ? 'Web' : 'Interno',
      data: dataFormatada,
      sla: horasAberto ? `-${horasAberto}h` : '0h',
      atrasado: isAtrasado,
      defeito: info.defeito || item.motivo || item.obs || 'Defeito em aberto',
      status: statusos,
      nomeStatus: info.nomeStatus || NOMES_STATUS_PADRAO[statusos] || `Status ${statusos}`,
      modelo: info.modelo || 'Equipamento não cadastrado',
      numserie: item.numserie || info.numserie || '',
      codtec: codtec,
      tecnico: nomeTec,
      corTecnico: corTec,
      encaminhado: dataFormatada,
      checkIn: ''
    };

    // Classificação
    if (!codtec || codtec === '0000' || ['00', '01'].includes(statusos)) {
      if (codtec && codtec !== '0000' && statusos === '03') {
        comTriagem.push(osObj);
      } else {
        semTriagem.push(osObj);
      }
    } else {
      comTriagem.push(osObj);
    }
  }

  // Ordenar as OSs das mais recentes para as mais antigas
  semTriagem.sort((a, b) => parseInt(b.os, 10) - parseInt(a.os, 10));
  comTriagem.sort((a, b) => parseInt(b.os, 10) - parseInt(a.os, 10));

  return { semTriagem, comTriagem, fonte: 'API Oficial Databit (Conexão Direta)' };
}

/**
 * Parser do HTML de cham1.php (apenas Sem Triagem e Com Triagem)
 */
function interpretarCham1(html) {
  const semTriagem = [];
  const comTriagem = [];
  const todasOS = [];

  // 1. Bloco "Sem triagem"
  const semTriagemMatch = html.match(/<h5 class="card-header">Sem triagem[\s\S]*?<table[\s\S]*?<\/thead>([\s\S]*?)<\/table>/i);
  if (semTriagemMatch) {
    const rowsHtml = semTriagemMatch[1];
    const rowMatches = rowsHtml.match(/<tr[\s\S]*?<\/tr>/gi) || [];

    for (const row of rowMatches) {
      const cols = [];
      const colRegex = /<td[\s\S]*?>([\s\S]*?)<\/td>/gi;
      let m;
      while ((m = colRegex.exec(row)) !== null) {
        cols.push(m[1].replace(/<[^>]+>/g, '').trim());
      }

      if (cols.length >= 7) {
        const isAtrasado = row.includes('#FF7F50') || row.includes('spinner-grow');
        const osNum = cols[0];
        todasOS.push(osNum);
        semTriagem.push({
          os: osNum,
          cliente: cols[1],
          local: cols[2] || 'Geral',
          cidade: cols[3],
          origem: cols[4],
          data: cols[5],
          sla: cols[6],
          atrasado: isAtrasado
        });
      }
    }
  }

  // 2. Bloco "Com triagem"
  const comTriagemMatch = html.match(/<h5 class="card-header">Com triagem[\s\S]*?<table[\s\S]*?<\/thead>([\s\S]*?)<\/table>/i);
  if (comTriagemMatch) {
    const rowsHtml = comTriagemMatch[1];
    const rowMatches = rowsHtml.match(/<tr[\s\S]*?<\/tr>/gi) || [];

    for (const row of rowMatches) {
      const cols = [];
      const colRegex = /<td([\s\S]*?)>([\s\S]*?)<\/td>/gi;
      let m;
      let corTecnico = '#cbd5e1';
      let isAtrasado = row.includes('#FF7F50');

      while ((m = colRegex.exec(row)) !== null) {
        const attrs = m[1];
        const text = m[2].replace(/<[^>]+>/g, '').trim();
        cols.push(text);

        if (attrs.includes('background-color:')) {
          const bgMatch = attrs.match(/background-color:\s*([^;']+)/i);
          if (bgMatch && bgMatch[1] && bgMatch[1].trim() !== '#FF7F50') {
            corTecnico = bgMatch[1].trim();
          }
        }
      }

      if (cols.length >= 6) {
        const osNum = cols[0];
        todasOS.push(osNum);
        comTriagem.push({
          os: osNum,
          cliente: cols[1],
          local: cols[2] || 'Geral',
          cidade: cols[3],
          encaminhado: cols[4] || '',
          tecnico: cols[5] || 'A Definir',
          corTecnico: corTecnico,
          checkIn: cols[6] || '',
          atrasado: isAtrasado
        });
      }
    }
  }

  return { semTriagem, comTriagem, todasOS };
}

// ROTA: Listar técnicos ativos para seleção
app.get('/api/tecnicos', async (req, res) => {
  try {
    const r = await fetch(`${API_BASE_URL}TecnicoController/listar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filtro: "situacao = 'A'" }),
      signal: AbortSignal.timeout(4000)
    });
    if (r.ok) {
      const d = await r.json();
      const list = d.value || (Array.isArray(d) ? d : []);
      if (list.length > 0) {
        const tecnicos = list
          .filter(t => t.codigo && t.nome && t.codigo !== '0001')
          .map(t => ({ codigo: t.codigo.trim(), nome: t.nome.trim() }));
        return res.json({ sucesso: true, tecnicos });
      }
    }
  } catch (err) {
    console.error('Falha ao listar técnicos na API:', err.message);
  }
  res.json({ sucesso: true, tecnicos: TECNICOS_FALLBACK });
});

// ROTA: Atribuir Técnico e alterar status da OS para 03 (Encaminhado Técnico)
app.post('/api/definir-tecnico', async (req, res) => {
  const { os, codtec, nomeTecnico } = req.body;

  if (!os || !codtec) {
    return res.status(400).json({ sucesso: false, erro: 'Número da OS e código do técnico são obrigatórios.' });
  }

  try {
    console.log(`[DEFINIR TECNICO] Atribuindo OS ${os} ao técnico ${codtec} (${nomeTecnico || ''}) e mudando status para 03...`);

    const rBusca = await fetch(`${API_BASE_URL}OsController/listar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filtro: `codigo = '${os}'` }),
      signal: AbortSignal.timeout(6000)
    });

    if (!rBusca.ok) {
      throw new Error(`Erro ao buscar OS ${os} no Databit (HTTP ${rBusca.status})`);
    }

    const dBusca = await rBusca.json();
    const listaOS = dBusca.value || (Array.isArray(dBusca) ? dBusca : []);
    if (listaOS.length === 0) {
      return res.status(404).json({ sucesso: false, erro: `OS ${os} não foi encontrada no Databit.` });
    }

    const osOriginal = listaOS[0];

    const osAtualizada = {
      ...osOriginal,
      codtec: codtec.trim(),
      statusos: '03', // 03 = ENCAMINHADO TECNICO
      alteracao: Date.now(),
      opaltera: 'DATASERVICE'
    };

    const rEdit = await fetch(`${API_BASE_URL}OsController/editar`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(osAtualizada),
      signal: AbortSignal.timeout(8000)
    });

    if (!rEdit.ok) {
      throw new Error(`Databit recusou alteração da OS (HTTP ${rEdit.status})`);
    }

    const dEdit = await rEdit.json();
    console.log(`[DEFINIR TECNICO] Resposta da API Databit para OS ${os}:`, dEdit);

    if (dEdit && dEdit.status && dEdit.status.code !== 1) {
      throw new Error(dEdit.status.message || 'Erro desconhecido ao salvar no Databit.');
    }

    res.json({
      sucesso: true,
      os: os,
      codtec: codtec,
      nomeTecnico: nomeTecnico,
      novoStatus: '03',
      nomeNovoStatus: 'ENCAMINHADO TECNICO',
      mensagem: `OS ${os} foi atribuída ao técnico com sucesso e atualizada para ENCAMINHADO TECNICO!`
    });
  } catch (err) {
    console.error(`[DEFINIR TECNICO ERRO] Falha ao atribuir OS ${os}:`, err);
    res.status(500).json({ sucesso: false, erro: err.message });
  }
});

// ROTA PRINCIPAL: Retorna chamados consolidados com defeito real, modelo e status oficial do Databit
app.get('/api/chamados', async (req, res) => {
  try {
    let semTriagemEnriquecido = [];
    let comTriagemEnriquecido = [];
    let fonteDados = 'API Databit';

    // 1. Tentar buscar pelo cham1.php (com timeout curto de 2.5s)
    let html = null;
    try {
      html = await baixarCham1Original();
    } catch (errCham1) {
      console.warn(`[CHAM1 INDISPONÍVEL] ${errCham1.message}. Alternando para consulta direta na API Databit ERP...`);
    }

    if (html) {
      fonteDados = 'cham1.php + API Databit';
      const dados = interpretarCham1(html);
      const detalhes = await carregarDetalhesReais(dados.todasOS);

      semTriagemEnriquecido = dados.semTriagem.map(item => {
        const info = detalhes[item.os] || {
          defeito: 'Não informado',
          status: '00',
          nomeStatus: NOMES_STATUS_PADRAO['00'],
          modelo: 'Equipamento não cadastrado',
          numserie: '',
          codtec: '0000'
        };
        const st = info.status || '00';
        return {
          ...item,
          defeito: info.defeito,
          status: st,
          nomeStatus: info.nomeStatus || NOMES_STATUS_PADRAO[st] || `Status ${st}`,
          modelo: info.modelo,
          numserie: info.numserie,
          codtec: info.codtec
        };
      });

      comTriagemEnriquecido = dados.comTriagem.map(item => {
        const info = detalhes[item.os] || {
          defeito: 'Não informado',
          status: '03',
          nomeStatus: NOMES_STATUS_PADRAO['03'],
          modelo: 'Equipamento não cadastrado',
          numserie: '',
          codtec: '0000'
        };
        const st = info.status || '03';
        return {
          ...item,
          defeito: info.defeito,
          status: st,
          nomeStatus: info.nomeStatus || NOMES_STATUS_PADRAO[st] || `Status ${st}`,
          modelo: info.modelo,
          numserie: info.numserie,
          codtec: info.codtec
        };
      });
    } else {
      // 2. Fallback resiliente: Consulta direta à API oficial do Databit ERP
      const dadosDiretos = await buscarChamadosDiretoDatabit();
      semTriagemEnriquecido = dadosDiretos.semTriagem;
      comTriagemEnriquecido = dadosDiretos.comTriagem;
      fonteDados = dadosDiretos.fonte;
    }

    let qtdStatus00 = 0;
    let qtdStatus01 = 0;
    semTriagemEnriquecido.forEach(item => {
      if (item.status === '01') qtdStatus01++; else qtdStatus00++;
    });

    res.json({
      sucesso: true,
      timestamp: new Date().toISOString(),
      fonte: fonteDados,
      apiOrigem: 'http://databitbh.com:54235/databitservice/',
      totais: {
        semTriagem: semTriagemEnriquecido.length,
        status00: qtdStatus00,
        nomeStatus00: NOMES_STATUS_PADRAO['00'],
        status01: qtdStatus01,
        nomeStatus01: NOMES_STATUS_PADRAO['01'],
        comTriagem: comTriagemEnriquecido.length,
        total: semTriagemEnriquecido.length + comTriagemEnriquecido.length
      },
      semTriagem: semTriagemEnriquecido,
      comTriagem: comTriagemEnriquecido
    });
  } catch (err) {
    console.error('Erro ao processar chamados:', err);
    res.status(500).json({
      sucesso: false,
      erro: err.message
    });
  }
});

// Redirecionamento da raiz para a interface
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

app.listen(PORT, () => {
  console.log(`[PAINEL MIL COPIAS] Servidor ativo em http://localhost:${PORT}`);
  console.log(`[API INTEGRADA] Databit ERP: ${API_BASE_URL}`);
});
