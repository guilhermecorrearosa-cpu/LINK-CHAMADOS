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

/**
 * Consulta a API do Databit ERP em lote para obter motivo/defeito real, status e modelo do equipamento
 */
async function carregarDetalhesReais(listaOS) {
  if (!listaOS || listaOS.length === 0) return {};

  const mapaResultado = {};
  const osUnicas = [...new Set(listaOS)];
  const inClause = osUnicas.join("','");

  try {
    // 1. Buscar dados principais das OS em lote (motivo, observação, statusos, produto, numserie)
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
          numserie: (o.numserie || '').trim()
        };
      }
    }

    // 2. Buscar nomes dos produtos / modelos de equipamento
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

    // 3. Buscar ocorrências de defeitos (OsDefeitoController)
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

    // 4. Carregar catálogo de defeitos se houver códigos numéricos
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

    // 5. Consolidar os dados de cada OS
    for (const numOS of osUnicas) {
      let defeitoFinal = '';
      const statusFinal = (dadosOS[numOS] && dadosOS[numOS].statusos) ? dadosOS[numOS].statusos : '00';
      const numserieFinal = (dadosOS[numOS] && dadosOS[numOS].numserie) ? dadosOS[numOS].numserie : '';
      const codProd = (dadosOS[numOS] && dadosOS[numOS].produto) ? dadosOS[numOS].produto : '';
      const modeloFinal = mapaProdutos[codProd] || 'Equipamento não cadastrado';

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
        modelo: modeloFinal,
        numserie: numserieFinal
      };
    }
  } catch (err) {
    console.error('Erro ao consultar API Databit:', err.message);
  }

  return mapaResultado;
}

/**
 * Baixa o HTML ao vivo do cham1.php original
 */
async function baixarCham1Original() {
  const res = await fetch(CHAM1_URL, {
    headers: { 'User-Agent': 'Mozilla/5.0 (Mobile)' },
    signal: AbortSignal.timeout(6000)
  });
  if (!res.ok) {
    throw new Error(`Erro ao buscar cham1.php: HTTP ${res.status}`);
  }
  const buffer = await res.arrayBuffer();
  const decoder = new TextDecoder('utf-8');
  return decoder.decode(buffer);
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

// ROTA PRINCIPAL: Retorna chamados consolidados com defeito real, modelo e status
app.get('/api/chamados', async (req, res) => {
  try {
    const html = await baixarCham1Original();
    const dados = interpretarCham1(html);

    // Buscar defeitos, modelo e status na API oficial do Databit
    const detalhes = await carregarDetalhesReais(dados.todasOS);

    let qtdStatus00 = 0;
    let qtdStatus01 = 0;

    // Inserir defeito, modelo e status em cada chamado
    const semTriagemEnriquecido = dados.semTriagem.map(item => {
      const info = detalhes[item.os] || {
        defeito: 'Não informado',
        status: '00',
        modelo: 'Equipamento não cadastrado',
        numserie: ''
      };
      const st = info.status || '00';
      if (st === '01') qtdStatus01++; else qtdStatus00++;
      return {
        ...item,
        defeito: info.defeito,
        status: st,
        modelo: info.modelo,
        numserie: info.numserie
      };
    });

    const comTriagemEnriquecido = dados.comTriagem.map(item => {
      const info = detalhes[item.os] || {
        defeito: 'Não informado',
        status: '03',
        modelo: 'Equipamento não cadastrado',
        numserie: ''
      };
      return {
        ...item,
        defeito: info.defeito,
        status: info.status || '03',
        modelo: info.modelo,
        numserie: info.numserie
      };
    });

    res.json({
      sucesso: true,
      timestamp: new Date().toISOString(),
      apiOrigem: 'http://databitbh.com:54235/databitservice/',
      totais: {
        semTriagem: semTriagemEnriquecido.length,
        status00: qtdStatus00,
        status01: qtdStatus01,
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
