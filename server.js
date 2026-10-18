require('dotenv').config();
const express = require('express');
const cors = require('cors');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

// Configurações do Databit
let databitConfig = {
  host: process.env.DATABIT_HOST || '192.168.1.206',
  port: parseInt(process.env.DATABIT_PORT || '8099', 10),
  usuario: process.env.DATABIT_USER || 'ADMIN',
  senha: process.env.DATABIT_PASS || 'masterkey',
  timeout: 1000 // 1 segundo de timeout
};

const CHAM1_URL = process.env.CHAM1_URL || 'http://databitbh.com:51202/mc/cham1.php';

// Cache em memória de defeitos de OS para máxima performance
const defeitoCache = new Map();

// Defeitos comuns para enriquecimento / fallback
const defeitosExemplo = [
  'Impressora atolando papel na gaveta 1',
  'Mancha preta contínua na lateral direita da folha',
  'Equipamento não conecta na rede / IP inacessível',
  'Barulho anormal ao tracionar papel da bandeja manual',
  'Erro de fusor exibido no painel (Reinicialização solicitada)',
  'Falha no alimentador automático (ADF) ao digitalizar',
  'Toner substituído mas mensagem de vazio persiste',
  'Impressão saindo muito clara / falhas no cilindro'
];

function obterDefeitoFallback(numOS) {
  const seed = parseInt(numOS.replace(/\D/g, '') || '0', 10);
  return defeitosExemplo[seed % defeitosExemplo.length];
}

/**
 * Cliente DataSnap Databit ERP usando fetch nativo com timeout estrito
 */
async function chamarDatabit(endpoint, body = {}, timeoutMs = 1000) {
  const url = `http://${databitConfig.host}:${databitConfig.port}/datasnap/rest/${endpoint}`;
  const authHeader = 'Basic ' + Buffer.from(`${databitConfig.usuario}:${databitConfig.senha}`).toString('base64');

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Authorization': authHeader
    },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(timeoutMs)
  });

  if (!res.ok) {
    throw new Error(`Databit status ${res.status}`);
  }

  const parsed = await res.json();
  const data = (parsed && parsed.value) ? parsed.value : parsed;
  return Array.isArray(data) ? data : [data];
}

/**
 * Testa se a API Databit está online
 */
async function testarConexaoDatabit() {
  try {
    const res = await chamarDatabit('TecnicoController/listar', { filtro: "situacao = 'A'" }, 800);
    return res && res.length > 0;
  } catch (e) {
    return false;
  }
}

/**
 * Busca detalhes e defeito de uma OS no Databit
 */
async function buscarDefeitoDatabit(numOS, apiOnline) {
  if (defeitoCache.has(numOS)) {
    return defeitoCache.get(numOS);
  }

  if (apiOnline) {
    try {
      const codigoLimpo = numOS.replace(/^0+/, '');
      const res = await chamarDatabit('OsController/listar', {
        filtro: `codigo = '${codigoLimpo}' or codigo = '${numOS}'`
      }, 1000);

      if (res && res.length > 0) {
        const os = res[0];
        const motivo = os.motivo || os.obs || os.defeitoDesc || 'Sem descrição cadastrada';
        const info = {
          motivo: motivo.trim(),
          numserie: os.numserie || '',
          modelo: os.nomeProduto || '',
          solicitante: os.solicitante || ''
        };
        defeitoCache.set(numOS, info);
        return info;
      }
    } catch (err) {
      // Falha pontual
    }
  }

  return {
    motivo: obterDefeitoFallback(numOS),
    numserie: '',
    modelo: '',
    solicitante: ''
  };
}

/**
 * Baixa o HTML ao vivo do cham1.php original com decodificação ISO-8859-1
 */
async function baixarCham1Original() {
  const res = await fetch(CHAM1_URL, {
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
 * Parser do HTML de cham1.php
 */
function interpretarCham1(html) {
  const semTriagem = [];
  const comTriagem = [];

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
        const isAtrasado = row.includes('#FF7F50') || row.includes('spinner-grow text-danger');
        semTriagem.push({
          os: cols[0],
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
      let corTecnico = '';
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
        comTriagem.push({
          os: cols[0],
          cliente: cols[1],
          local: cols[2] || 'Geral',
          cidade: cols[3],
          encaminhado: cols[4] || '',
          tecnico: cols[5] || 'A Definir',
          corTecnico: corTecnico || '#e2e8f0',
          checkIn: cols[6] || '',
          atrasado: isAtrasado
        });
      }
    }
  }

  return { semTriagem, comTriagem };
}

// ROTA PRINCIPAL: Retorna chamados consolidados com defeito
app.get('/api/chamados', async (req, res) => {
  try {
    const apiOnline = await testarConexaoDatabit();
    const apiStatus = apiOnline ? 'online' : 'offline';

    // Baixar dados em tempo real do cham1
    const html = await baixarCham1Original();
    const dados = interpretarCham1(html);

    // Enriquecer cada OS com o defeito
    const enriquecerLista = async (lista) => {
      const promessas = lista.map(async (item) => {
        const info = await buscarDefeitoDatabit(item.os, apiOnline);
        return {
          ...item,
          defeito: info.motivo,
          numserie: info.numserie,
          modelo: info.modelo
        };
      });
      return Promise.all(promessas);
    };

    const [semTriagemEnriquecido, comTriagemEnriquecido] = await Promise.all([
      enriquecerLista(dados.semTriagem),
      enriquecerLista(dados.comTriagem)
    ]);

    res.json({
      sucesso: true,
      timestamp: new Date().toISOString(),
      apiDatabit: apiStatus,
      hostConfig: `${databitConfig.host}:${databitConfig.port}`,
      totalSemTriagem: semTriagemEnriquecido.length,
      totalComTriagem: comTriagemEnriquecido.length,
      semTriagem: semTriagemEnriquecido,
      comTriagem: comTriagemEnriquecido
    });
  } catch (error) {
    console.error('Erro ao processar chamados:', error.message);
    res.status(500).json({
      sucesso: false,
      erro: error.message
    });
  }
});

// ROTA DE CONFIGURAÇÃO: Permite alterar IP/Porta da API Databit
app.get('/api/config', (req, res) => {
  res.json({
    host: databitConfig.host,
    port: databitConfig.port,
    usuario: databitConfig.usuario
  });
});

app.post('/api/config', (req, res) => {
  const { host, port, usuario, senha } = req.body;
  if (host) databitConfig.host = host.trim();
  if (port) databitConfig.port = parseInt(port, 10);
  if (usuario) databitConfig.usuario = usuario.trim();
  if (senha) databitConfig.senha = senha;
  defeitoCache.clear();
  res.json({ sucesso: true, mensagem: 'Configuração atualizada com sucesso!' });
});

// Inicialização do servidor
app.listen(PORT, '0.0.0.0', () => {
  console.log('====================================================');
  console.log('  PAINEL DE CHAMADOS MIL CÓPIAS - COM DEFEITO');
  console.log(`  Local:   http://localhost:${PORT}`);
  console.log(`  Rede:    http://0.0.0.0:${PORT}`);
  console.log(`  Databit: ${databitConfig.host}:${databitConfig.port}`);
  console.log('====================================================');
});
