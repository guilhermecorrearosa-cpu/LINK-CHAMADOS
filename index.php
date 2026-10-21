<?php
/**
 * Mil Cópias - Painel de Chamados Ultra-Compacto para Celular
 * Integração Direta com a API Databit ERP (http://databitbh.com:54235/databitservice/)
 * Destaques:
 * - Nomes Oficiais dos Status do Databit ERP (StatusOSController):
 *   00 = ABERTURA OS INTERNA
 *   01 = ABERTURA DE OS WEB
 *   03 = ENCAMINHADO TECNICO
 *   04 = EM ATENDIMENTO
 * - Visualização super rápida de Cidade, Cliente, Modelo e Defeito
 * - Toque na OS para expandir e acessar o botão "Definir / Transferir Técnico"
 * - Sem '#' nas OS
 */

$api_base_url = 'http://databitbh.com:54235/databitservice/';
$cham1_url = 'http://databitbh.com:51202/mc/cham1.php';

function obterNomeStatus($codigo) {
    switch (trim((string)$codigo)) {
        case '00': return 'Abertura Interna';
        case '01': return 'Abertura Web';
        case '03': return 'Encaminhado Técnico';
        case '04': return 'Em Atendimento';
        case '05': return 'Finalizado Técnico';
        case '06': return 'Concluída';
        case '08': return 'Cancelada';
        default: return 'Status ' . $codigo;
    }
}

function simplificarModelo($nome) {
    if (!$nome || $nome === 'Equipamento não cadastrado') return 'S/ Modelo';
    return trim(preg_replace('/^(MULTIFUNCIONAL|IMPRESSORA)\s+(LASER\s+)?(MONO|COLOR)\s+/i', '', $nome));
}

function normalizarTexto($str) {
    if (mb_check_encoding($str, 'UTF-8')) {
        return $str;
    }
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

// AÇÃO: Atribuir Técnico e mudar status da OS para 03 via POST AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'definir_tecnico') {
    header('Content-Type: application/json; charset=utf-8');
    $os = isset($_POST['os']) ? trim($_POST['os']) : '';
    $codtec = isset($_POST['codtec']) ? trim($_POST['codtec']) : '';
    $nomeTecnico = isset($_POST['nomeTecnico']) ? trim($_POST['nomeTecnico']) : '';

    if (!$os || !$codtec) {
        echo json_encode(['sucesso' => false, 'erro' => 'Número da OS e código do técnico são obrigatórios.']);
        exit;
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode(['filtro' => "codigo = '{$os}'"]),
            'timeout' => 6
        ]
    ];
    $resBusca = @file_get_contents($api_base_url . 'OsController/listar', false, stream_context_create($opts));
    $jsonBusca = json_decode($resBusca, true);
    $listaOS = isset($jsonBusca['value']) ? $jsonBusca['value'] : (is_array($jsonBusca) ? $jsonBusca : []);
    
    if (empty($listaOS)) {
        echo json_encode(['sucesso' => false, 'erro' => "OS {$os} não foi encontrada no Databit."]);
        exit;
    }

    $osObj = $listaOS[0];
    $osObj['codtec'] = $codtec;
    $osObj['statusos'] = '03'; // Atualizar para Status 03 (ENCAMINHADO TECNICO)
    $osObj['alteracao'] = round(microtime(true) * 1000);
    $osObj['opaltera'] = 'DATASERVICE';

    $optsEdit = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($osObj),
            'timeout' => 8
        ]
    ];
    $resEdit = @file_get_contents($api_base_url . 'OsController/editar', false, stream_context_create($optsEdit));
    $jsonEdit = json_decode($resEdit, true);

    if (isset($jsonEdit['status']['code']) && $jsonEdit['status']['code'] !== 1) {
        echo json_encode(['sucesso' => false, 'erro' => $jsonEdit['status']['message'] ?: 'Erro ao gravar no Databit.']);
        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => "OS {$os} atribuída com sucesso! Atualizada para ENCAMINHADO TECNICO (Status 03)."
    ]);
    exit;
}

// 1. Baixar chamados ao vivo do cham1.php
$ctx = stream_context_create([
    'http' => [
        'timeout' => 6,
        'header' => "User-Agent: Mozilla/5.0 (Mobile)\r\n"
    ]
]);

$html = @file_get_contents($cham1_url, false, $ctx);

$semTriagem = [];
$comTriagem = [];
$todasOS = [];

if ($html) {
    // 2. Extrair "Sem Triagem"
    if (preg_match('/<h5 class="card-header">Sem triagem[\s\S]*?<table[\s\S]*?<\/thead>([\s\S]*?)<\/table>/i', $html, $m1)) {
        preg_match_all('/<tr[\s\S]*?<\/tr>/i', $m1[1], $rows);
        foreach ($rows[0] as $row) {
            preg_match_all('/<td[\s\S]*?>([\s\S]*?)<\/td>/i', $row, $tds);
            $cols = array_map(function($c) { return trim(strip_tags($c)); }, $tds[1]);
            if (count($cols) >= 7) {
                $isAtrasado = (strpos($row, '#FF7F50') !== false || strpos($row, 'spinner-grow') !== false);
                $osNum = $cols[0];
                $todasOS[] = $osNum;
                $semTriagem[] = [
                    'os' => $osNum,
                    'cliente' => $cols[1],
                    'local' => $cols[2],
                    'cidade' => $cols[3],
                    'origem' => $cols[4],
                    'data' => $cols[5],
                    'sla' => $cols[6],
                    'atrasado' => $isAtrasado
                ];
            }
        }
    }

    // 3. Extrair "Com Triagem"
    if (preg_match('/<h5 class="card-header">Com triagem[\s\S]*?<table[\s\S]*?<\/thead>([\s\S]*?)<\/table>/i', $html, $m2)) {
        preg_match_all('/<tr[\s\S]*?<\/tr>/i', $m2[1], $rows);
        foreach ($rows[0] as $row) {
            preg_match_all('/<td([\s\S]*?)>([\s\S]*?)<\/td>/i', $row, $tds);
            $cols = array_map(function($c) { return trim(strip_tags($c)); }, $tds[2]);
            
            $corTecnico = '#cbd5e1';
            if (isset($tds[1][5]) && preg_match('/background-color:\s*([^;\']+)/i', $tds[1][5], $bg)) {
                $corTecnico = trim($bg[1]);
            }

            if (count($cols) >= 6) {
                $isAtrasado = (strpos($row, '#FF7F50') !== false);
                $osNum = $cols[0];
                $todasOS[] = $osNum;
                $comTriagem[] = [
                    'os' => $osNum,
                    'cliente' => $cols[1],
                    'local' => $cols[2],
                    'cidade' => $cols[3],
                    'encaminhado' => $cols[4],
                    'tecnico' => $cols[5],
                    'corTecnico' => $corTecnico,
                    'atrasado' => $isAtrasado
                ];
            }
        }
    }
}

// 4. Buscar defeitos reais, modelo do equipamento e status na API do Databit
$detalhesAPI = carregarDetalhesReaisEmLote($todasOS, $api_base_url);

// 5. Vincular dados a cada OS
$qtdStatus00 = 0;
$qtdStatus01 = 0;

foreach ($semTriagem as &$item) {
    $info = isset($detalhesAPI[$item['os']]) ? $detalhesAPI[$item['os']] : [
        'defeito' => 'Não informado',
        'status' => '00',
        'modelo' => 'Equipamento não cadastrado',
        'numserie' => '',
        'codtec' => '0000'
    ];
    $item['defeito'] = $info['defeito'];
    $item['status'] = !empty($info['status']) ? $info['status'] : '00';
    $item['modelo'] = !empty($info['modelo']) ? $info['modelo'] : 'Equipamento não cadastrado';
    $item['numserie'] = !empty($info['numserie']) ? $info['numserie'] : '';
    $item['codtec'] = !empty($info['codtec']) ? $info['codtec'] : '0000';

    if ($item['status'] === '01') {
        $qtdStatus01++;
    } else {
        $qtdStatus00++;
    }
}
unset($item);

foreach ($comTriagem as &$item) {
    $info = isset($detalhesAPI[$item['os']]) ? $detalhesAPI[$item['os']] : [
        'defeito' => 'Não informado',
        'status' => '03',
        'modelo' => 'Equipamento não cadastrado',
        'numserie' => '',
        'codtec' => '0000'
    ];
    $item['defeito'] = $info['defeito'];
    $item['status'] = !empty($info['status']) ? $info['status'] : '03';
    $item['modelo'] = !empty($info['modelo']) ? $info['modelo'] : 'Equipamento não cadastrado';
    $item['numserie'] = !empty($info['numserie']) ? $info['numserie'] : '';
    $item['codtec'] = !empty($info['codtec']) ? $info['codtec'] : '0000';
}
unset($item);

function carregarDetalhesReaisEmLote($listaOS, $baseUrl) {
    if (empty($listaOS)) return [];

    $mapaResultado = [];
    $inClause = implode("','", array_unique($listaOS));
    $filtroOS = json_encode(['filtro' => "codigo in ('{$inClause}') and situacao = 'A'"]);
    $filtroDef = json_encode(['filtro' => "codigo in ('{$inClause}')"]);

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'timeout' => 6
        ]
    ];

    // 4.1 Buscar OS (motivo, observação, statusos, produto, numserie, codtec)
    $opts['http']['content'] = $filtroOS;
    $resOS = @file_get_contents($baseUrl . 'OsController/listar', false, stream_context_create($opts));
    $dadosOS = [];
    $codigosProdutos = [];
    if ($resOS) {
        $jsonOS = json_decode($resOS, true);
        $itensOS = isset($jsonOS['value']) ? $jsonOS['value'] : (is_array($jsonOS) ? $jsonOS : []);
        foreach ($itensOS as $o) {
            $codProd = !empty($o['produto']) ? trim($o['produto']) : '';
            if ($codProd) {
                $codigosProdutos[] = $codProd;
            }
            $dadosOS[$o['codigo']] = [
                'motivo' => !empty($o['motivo']) ? trim($o['motivo']) : '',
                'obs' => !empty($o['obs']) ? trim($o['obs']) : '',
                'statusos' => !empty($o['statusos']) ? trim($o['statusos']) : '00',
                'produto' => $codProd,
                'numserie' => !empty($o['numserie']) ? trim($o['numserie']) : '',
                'codtec' => !empty($o['codtec']) ? trim($o['codtec']) : '0000'
            ];
        }
    }

    // 4.2 Buscar nomes dos produtos/modelos de equipamento
    $mapaProdutos = [];
    if (!empty($codigosProdutos)) {
        $inProd = implode("','", array_unique($codigosProdutos));
        $opts['http']['content'] = json_encode(['filtro' => "codigo in ('{$inProd}')"]);
        $resProd = @file_get_contents($baseUrl . 'ProdutoController/listar', false, stream_context_create($opts));
        if ($resProd) {
            $jsonProd = json_decode($resProd, true);
            $itensProd = isset($jsonProd['value']) ? $jsonProd['value'] : (is_array($jsonProd) ? $jsonProd : []);
            foreach ($itensProd as $p) {
                if (!empty($p['codigo']) && !empty($p['nome'])) {
                    $mapaProdutos[$p['codigo']] = normalizarTexto(trim($p['nome']));
                }
            }
        }
    }

    // 4.3 Buscar OsDefeito
    $opts['http']['content'] = $filtroDef;
    $resDef = @file_get_contents($baseUrl . 'OsDefeitoController/listar', false, stream_context_create($opts));
    $dadosDef = [];
    $codigosCatalogo = [];
    if ($resDef) {
        $jsonDef = json_decode($resDef, true);
        $itensDef = isset($jsonDef['value']) ? $jsonDef['value'] : (is_array($jsonDef) ? $jsonDef : []);
        foreach ($itensDef as $d) {
            $osCod = $d['codigo'];
            $obsDef = !empty($d['obs']) ? trim($d['obs']) : '';
            $defCod = !empty($d['defeito']) ? trim($d['defeito']) : '';
            
            if ($defCod && $defCod !== '0219') {
                $codigosCatalogo[] = $defCod;
            }
            if (!isset($dadosDef[$osCod])) {
                $dadosDef[$osCod] = [];
            }
            $dadosDef[$osCod][] = ['obs' => $obsDef, 'codigo' => $defCod];
        }
    }

    // 4.4 Catálogo de defeitos
    $nomesCatalogo = [];
    if (!empty($codigosCatalogo)) {
        $inCat = implode("','", array_unique($codigosCatalogo));
        $opts['http']['content'] = json_encode(['filtro' => "codigo in ('{$inCat}')"]);
        $resCat = @file_get_contents($baseUrl . 'DefeitoController/listar', false, stream_context_create($opts));
        if ($resCat) {
            $jsonCat = json_decode($resCat, true);
            $itensCat = isset($jsonCat['value']) ? $jsonCat['value'] : (is_array($jsonCat) ? $jsonCat : []);
            foreach ($itensCat as $c) {
                if (!empty($c['codigo']) && !empty($c['nome'])) {
                    $nomesCatalogo[$c['codigo']] = normalizarTexto(trim($c['nome']));
                }
            }
        }
    }

    // 4.5 Consolidação dos dados
    foreach ($listaOS as $numOS) {
        $defeitoFinal = '';
        $statusFinal = isset($dadosOS[$numOS]['statusos']) ? $dadosOS[$numOS]['statusos'] : '00';
        $numserieFinal = isset($dadosOS[$numOS]['numserie']) ? $dadosOS[$numOS]['numserie'] : '';
        $codProd = isset($dadosOS[$numOS]['produto']) ? $dadosOS[$numOS]['produto'] : '';
        $codtecFinal = isset($dadosOS[$numOS]['codtec']) ? $dadosOS[$numOS]['codtec'] : '0000';
        $modeloFinal = isset($mapaProdutos[$codProd]) ? $mapaProdutos[$codProd] : 'Equipamento não cadastrado';

        if (!empty($dadosOS[$numOS]['motivo'])) {
            $defeitoFinal = $dadosOS[$numOS]['motivo'];
        }

        if (!$defeitoFinal && !empty($dadosDef[$numOS])) {
            foreach ($dadosDef[$numOS] as $dItem) {
                if (!empty($dItem['obs']) && stripos($dItem['obs'], 'HELPDESK') === false) {
                    $defeitoFinal = $dItem['obs'];
                    break;
                }
                if (!empty($dItem['codigo']) && isset($nomesCatalogo[$dItem['codigo']])) {
                    $defeitoFinal = $nomesCatalogo[$dItem['codigo']];
                    break;
                }
            }
        }

        if (!$defeitoFinal && !empty($dadosOS[$numOS]['obs'])) {
            $defeitoFinal = $dadosOS[$numOS]['obs'];
        }

        if ($defeitoFinal) {
            $defeitoFinal = ltrim($defeitoFinal, "- \t\n\r");
            $defeitoFinal = normalizarTexto($defeitoFinal);
        } else {
            $defeitoFinal = 'Defeito não detalhado no sistema';
        }

        $mapaResultado[$numOS] = [
            'defeito' => $defeitoFinal,
            'status' => $statusFinal,
            'modelo' => $modeloFinal,
            'numserie' => $numserieFinal,
            'codtec' => $codtecFinal
        ];
    }

    return $mapaResultado;
}

$listaTecnicosFixa = [
    ['codigo' => '0071', 'nome' => 'FABIANO'],
    ['codigo' => '0083', 'nome' => 'LEIDIMAR'],
    ['codigo' => '0081', 'nome' => 'MATHEUS'],
    ['codigo' => '0046', 'nome' => 'GUILHERME'],
    ['codigo' => '0059', 'nome' => 'SOLANO'],
    ['codigo' => '0041', 'nome' => 'FELIPE'],
    ['codigo' => '0034', 'nome' => 'BRENO'],
    ['codigo' => '0016', 'nome' => 'RILIANE'],
    ['codigo' => '0010', 'nome' => 'FABIO'],
    ['codigo' => '0085', 'nome' => 'NINIVE'],
    ['codigo' => '0074', 'nome' => 'GABRIEL'],
    ['codigo' => '0076', 'nome' => 'DARLAN'],
    ['codigo' => '0078', 'nome' => 'JEAN'],
    ['codigo' => '0045', 'nome' => 'NILTON'],
    ['codigo' => '0018', 'nome' => 'SUDESTE'],
    ['codigo' => '0064', 'nome' => 'BRENO INT'],
    ['codigo' => '0000', 'nome' => 'UNICO']
];

function obterClasseRota($cidade) {
    $cid = strtoupper(trim((string)$cidade));
    if (strpos($cid, 'LINHARES') !== false) return 'rota-linhares';
    if (strpos($cid, 'COLATINA') !== false) return 'rota-colatina';
    if (strpos($cid, 'ARACRUZ') !== false) return 'rota-aracruz';
    if (strpos($cid, 'JAGUARE') !== false || strpos($cid, 'JAGUARÉ') !== false) return 'rota-jaguare';
    return 'rota-padrao';
}

$totalAtrasadas = 0;
$totalWeb = 0;
$totalInterna = 0;
foreach ($semTriagem as $c) {
    if (!empty($c['atrasado'])) $totalAtrasadas++;
    if (($c['status'] ?? '00') === '01') $totalWeb++;
    if (($c['status'] ?? '00') === '00') $totalInterna++;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <meta http-equiv="refresh" content="60">
  <title>Painel 2.0 - Mil Cópias</title>

  <!-- PWA & Ícones Oficiais para iPhone (iOS) e Android -->
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=20261020_02">
  <link rel="icon" type="image/png" sizes="192x192" href="icon-192.png?v=20261020_02">
  <link rel="icon" type="image/png" sizes="64x64" href="favicon.png?v=20261020_02">
  <link rel="manifest" href="manifest.json?v=20261020_02">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Mil Cópias">
  <meta name="theme-color" content="#070a12">

  <style>
/* ==========================================================
   PAINEL DE CHAMADOS MIL CÓPIAS - VERSÃO 2.0 (MOBILE FIRST)
   Estilo: Dark Titanium Pro (OLED) & Clean Studio iOS
   Ergonomia: Thumb-Zone, Bottom Dock, Bottom Sheet Nativo
   ========================================================== */

:root {
  /* TEMA A: DARK TITANIUM PRO (OLED) - PADRÃO */
  --bg-app: #070a12;
  --bg-header: rgba(11, 16, 27, 0.92);
  --bg-card: #0f1626;
  --bg-card-hover: #162036;
  --border-card: #1e293b;
  --border-card-subtle: #162032;
  --text-primary: #f8fafc;
  --text-secondary: #94a3b8;
  --text-muted: #64748b;
  --accent-blue: #38bdf8;
  --accent-cyan: #0284c7;
  --accent-green: #10b981;
  --accent-orange: #f97316;
  --accent-red: #ef4444;
  --bg-chip: #131c2e;
  --border-chip: #1e2d45;
  --bg-search: #0f1626;
  --border-search: #1e293b;
  --bg-bottom-dock: rgba(11, 16, 27, 0.92);
  --border-bottom-dock: rgba(255, 255, 255, 0.12);
  --shadow-card: 0 4px 16px rgba(0, 0, 0, 0.4);
  --sheet-bg: #0f1626;
  --sheet-sub-bg: #090e18;
  --glow-accent: rgba(56, 189, 248, 0.15);
}

[data-theme="light"] {
  /* TEMA B: CLEAN STUDIO iOS (APPLE GLASS) */
  --bg-app: #f4f6fa;
  --bg-header: rgba(255, 255, 255, 0.92);
  --bg-card: #ffffff;
  --bg-card-hover: #f8fafc;
  --border-card: #e2e8f0;
  --border-card-subtle: #edf2f7;
  --text-primary: #0f172a;
  --text-secondary: #475569;
  --text-muted: #94a3b8;
  --accent-blue: #0284c7;
  --accent-cyan: #0369a1;
  --accent-green: #059669;
  --accent-orange: #ea580c;
  --accent-red: #dc2626;
  --bg-chip: #ffffff;
  --border-chip: #e2e8f0;
  --bg-search: #ffffff;
  --border-search: #e2e8f0;
  --bg-bottom-dock: rgba(255, 255, 255, 0.94);
  --border-bottom-dock: rgba(0, 0, 0, 0.08);
  --shadow-card: 0 2px 8px rgba(15, 23, 42, 0.05);
  --sheet-bg: #ffffff;
  --sheet-sub-bg: #f8fafc;
  --glow-accent: rgba(2, 132, 199, 0.1);
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  background-color: var(--bg-app);
  color: var(--text-primary);
  font-size: 13px;
  line-height: 1.35;
  min-height: 100vh;
  padding-bottom: 85px; /* Espaço para o Bottom Dock flutuante */
  -webkit-font-smoothing: antialiased;
  transition: background-color 0.25s ease, color 0.25s ease;
}

/* ==========================================================
   1. TOP BAR (FROSTED GLASS COM CONTROLES RÁPIDOS)
   ========================================================== */
.topo {
  position: sticky;
  top: 0;
  background: var(--bg-header);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  color: var(--text-primary);
  padding: 10px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 100;
  border-bottom: 1px solid var(--border-card-subtle);
  transition: all 0.2s ease;
}
.topo-esq {
  display: flex;
  align-items: center;
  gap: 10px;
}
.topo-logo {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  object-fit: cover;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
.topo-brand {
  display: flex;
  flex-direction: column;
}
.topo-titulo {
  font-size: 15px;
  font-weight: 800;
  letter-spacing: -0.3px;
  color: var(--text-primary);
  line-height: 1.1;
}
.topo-sub {
  font-size: 10px;
  font-weight: 600;
  color: var(--accent-green);
  display: flex;
  align-items: center;
  gap: 4px;
}
.pulse-dot {
  width: 6px;
  height: 6px;
  background: var(--accent-green);
  border-radius: 50%;
  box-shadow: 0 0 6px var(--accent-green);
  animation: pulseAnim 2s infinite;
}
@keyframes pulseAnim {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.85); }
}

.topo-dir {
  display: flex;
  align-items: center;
  gap: 7px;
}
.btn-topo-icon {
  background: var(--bg-chip);
  border: 1px solid var(--border-chip);
  color: var(--text-primary);
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-topo-icon:active {
  transform: scale(0.92);
}
.btn-topo-icon.ativo {
  background: var(--accent-blue);
  color: #fff;
  border-color: var(--accent-blue);
  box-shadow: 0 0 10px var(--accent-blue);
}
.topo-timer-pill {
  font-size: 11px;
  color: var(--text-secondary);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  background: var(--bg-chip);
  border: 1px solid var(--border-chip);
  padding: 4px 9px;
  border-radius: 14px;
}

/* ==========================================================
   2. QUICK TRIAGE STRIP (MINI-KPIS DE 1 TOQUE)
   ========================================================== */
.triage-strip {
  display: flex;
  gap: 7px;
  padding: 10px 14px 6px;
  overflow-x: auto;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.triage-strip::-webkit-scrollbar {
  display: none;
}
.triage-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 12px;
  background: var(--bg-chip);
  border: 1px solid var(--border-chip);
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-secondary);
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s ease;
}
.triage-chip:active {
  transform: scale(0.95);
}
.triage-chip.active {
  background: var(--accent-cyan);
  color: #ffffff;
  border-color: var(--accent-cyan);
  box-shadow: 0 2px 10px var(--glow-accent);
}
.chip-badge-num {
  font-size: 10.5px;
  font-weight: 800;
  padding: 1px 6px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.2);
}
.triage-chip.chip-urgente {
  border-color: rgba(239, 68, 68, 0.4);
  color: #f87171;
}
.triage-chip.chip-urgente.active {
  background: #dc2626;
  border-color: #dc2626;
  color: #fff;
}
.triage-chip.chip-web {
  border-color: rgba(245, 158, 11, 0.4);
  color: #fbbf24;
}
.triage-chip.chip-web.active {
  background: #d97706;
  border-color: #d97706;
  color: #fff;
}

/* ==========================================================
   3. BARRA DE BUSCA RÁPIDA (CLEAN MOBILE INPUT)
   ========================================================== */
.busca-container {
  padding: 6px 14px 10px;
}
.busca-box {
  position: relative;
  display: flex;
  align-items: center;
}
.busca-box input {
  width: 100%;
  padding: 9px 14px 9px 36px;
  border: 1px solid var(--border-search);
  border-radius: 12px;
  font-size: 13px;
  outline: none;
  background: var(--bg-search);
  color: var(--text-primary);
  transition: all 0.2s ease;
}
.busca-box input:focus {
  border-color: var(--accent-blue);
  box-shadow: 0 0 0 3px var(--glow-accent);
}
.busca-box input::placeholder {
  color: var(--text-muted);
}
.busca-icon-lupa {
  position: absolute;
  left: 12px;
  color: var(--text-muted);
  font-size: 13px;
  pointer-events: none;
}

/* ==========================================================
   4. LISTA DE TICKETS (CARDS BENTO 2.0)
   ========================================================== */
.lista-chamados {
  padding: 4px 12px;
  max-width: 800px;
  margin: 0 auto;
}
.tab-view {
  display: none;
}
.tab-view.active {
  display: block;
}

/* CARD 2.0 TICKET BENTO */
.ticket-card {
  background: var(--bg-card);
  border: 1px solid var(--border-card);
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 9px;
  box-shadow: var(--shadow-card);
  cursor: pointer;
  transition: transform 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
  position: relative;
  overflow: hidden;
}
.ticket-card:active {
  transform: scale(0.985);
  background: var(--bg-card-hover);
}

/* Faixa lateral suave de status de atraso */
.ticket-card.atrasado {
  border-left: 4px solid var(--accent-red);
}

/* Efeito de card selecionado */
.ticket-card.selecionado {
  border-color: var(--accent-blue);
  box-shadow: 0 0 15px var(--glow-accent);
}

/* LINHA 1: Topo do Ticket (OS + ROTA DA CIDADE | STATUS + SLA) */
.ticket-topo {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.ticket-origem {
  display: flex;
  align-items: center;
  gap: 6px;
}
.ticket-os-num {
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", monospace;
  font-weight: 800;
  font-size: 13px;
  color: var(--accent-blue);
  background: var(--bg-chip);
  border: 1px solid var(--border-chip);
  padding: 2px 7px;
  border-radius: 6px;
  letter-spacing: -0.2px;
}

/* Badges de Cidade com Código de Cores por Rota */
.badge-rota {
  font-size: 10px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 6px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.rota-linhares {
  background: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.3);
}
.rota-colatina {
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.3);
}
.rota-aracruz {
  background: rgba(245, 158, 11, 0.15);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.3);
}
.rota-jaguare {
  background: rgba(168, 85, 247, 0.15);
  color: #c084fc;
  border: 1px solid rgba(168, 85, 247, 0.3);
}
.rota-padrao {
  background: var(--bg-chip);
  color: var(--text-secondary);
  border: 1px solid var(--border-chip);
}

.ticket-status-sla {
  display: flex;
  align-items: center;
  gap: 5px;
}
.badge-status-databit {
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 6px;
  white-space: nowrap;
}
.status-00 { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.25); }
.status-01 { background: rgba(245, 158, 11, 0.18); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
.status-03 { background: rgba(14, 165, 233, 0.18); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.3); }
.status-04 { background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }

.badge-sla-pill {
  font-size: 10.5px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 6px;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.sla-atrasado {
  background: rgba(239, 68, 68, 0.18);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.35);
}
.sla-ok {
  background: var(--bg-chip);
  color: var(--text-secondary);
  border: 1px solid var(--border-chip);
}
.badge-tecnico-nome {
  font-size: 10.5px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 6px;
  text-transform: uppercase;
  color: #0f172a;
  white-space: nowrap;
}

/* LINHA 2: Cliente Hero (Grande e Legível) */
.ticket-cliente-nome {
  font-weight: 700;
  font-size: 14px;
  color: var(--text-primary);
  line-height: 1.35;
  margin-bottom: 6px;
  word-break: break-word;
}

/* LINHA 3: Contexto de Equipamento & Setor */
.ticket-equip-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 7px;
}
.chip-equip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
}
.chip-equip-local {
  background: var(--bg-chip);
  color: var(--text-secondary);
  border: 1px solid var(--border-chip);
}
.chip-equip-modelo {
  background: rgba(16, 185, 129, 0.12);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.25);
}

/* LINHA 4: Defeito em 1 linha limpa com botão de ação rápida */
.ticket-rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-top: 6px;
  border-top: 1px solid var(--border-card-subtle);
}
.ticket-defeito-preview {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11.5px;
  color: var(--text-muted);
  overflow: hidden;
  flex: 1;
}
.ticket-defeito-preview span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.btn-quick-assign {
  background: var(--bg-chip);
  border: 1px solid var(--border-chip);
  color: var(--accent-blue);
  font-size: 11.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-quick-assign:active {
  background: var(--accent-cyan);
  color: #fff;
}

/* ==========================================================
   5. BOTTOM SHEET (GAVETA DESLIZANTE NATIVA PARA AÇÕES)
   ========================================================== */
.sheet-backdrop {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
  z-index: 200;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.22s ease, visibility 0.22s ease;
}
.sheet-backdrop.aberto {
  opacity: 1;
  visibility: visible;
}

.bottom-sheet {
  background: var(--sheet-bg);
  width: 100%;
  max-width: 520px;
  border-radius: 20px 20px 0 0;
  padding: 16px 18px 24px;
  box-shadow: 0 -8px 35px rgba(0, 0, 0, 0.35);
  transform: translateY(100%);
  transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
  max-height: 85vh;
  overflow-y: auto;
  border-top: 1px solid var(--border-card);
}
.sheet-backdrop.aberto .bottom-sheet {
  transform: translateY(0);
}

.sheet-handle-bar {
  width: 40px;
  height: 4px;
  background: var(--text-muted);
  border-radius: 4px;
  margin: 0 auto 12px;
  opacity: 0.4;
}

.sheet-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}
.sheet-title-os {
  font-size: 16px;
  font-weight: 800;
  color: var(--accent-blue);
}
.sheet-btn-close {
  background: var(--bg-chip);
  border: none;
  font-size: 14px;
  color: var(--text-secondary);
  width: 30px;
  height: 30px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sheet-cliente {
  font-size: 15px;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 12px;
  line-height: 1.3;
}

.sheet-info-grid {
  background: var(--sheet-sub-bg);
  border: 1px solid var(--border-card);
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 12.5px;
  margin-bottom: 14px;
}
.sheet-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  line-height: 1.35;
}
.sheet-label {
  color: var(--text-muted);
  font-weight: 600;
}
.sheet-val {
  color: var(--text-primary);
  font-weight: 600;
  text-align: right;
  word-break: break-word;
}
.sheet-defeito-card {
  background: var(--bg-card);
  border: 1px solid var(--border-card);
  border-radius: 9px;
  padding: 10px;
  margin-top: 4px;
}
.sheet-defeito-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 3px;
}
.sheet-defeito-texto {
  font-size: 13px;
  color: var(--text-primary);
  line-height: 1.4;
  word-break: break-word;
}

/* Área de Ação: Seleção Rápida de Técnico */
.sheet-assign-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sheet-assign-label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--text-primary);
}
.sheet-select-tec {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--border-card);
  border-radius: 10px;
  background: var(--sheet-sub-bg);
  color: var(--text-primary);
  font-size: 13.5px;
  font-weight: 600;
  outline: none;
}
.sheet-btn-confirm {
  width: 100%;
  padding: 13px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
  box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
  margin-top: 4px;
}
.sheet-btn-confirm:active {
  transform: scale(0.985);
}
.sheet-btn-confirm:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* ==========================================================
   6. FLOATING BOTTOM DOCK (THUMB-ZONE NAVEGAÇÃO 2.0)
   ========================================================== */
.bottom-dock {
  position: fixed;
  bottom: 12px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--bg-bottom-dock);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
  border: 1px solid var(--border-bottom-dock);
  border-radius: 30px;
  padding: 5px 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  z-index: 150;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.45);
  max-width: 92%;
}
.dock-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: transparent;
  border: none;
  color: var(--text-secondary);
  font-size: 12.5px;
  font-weight: 700;
  border-radius: 20px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  white-space: nowrap;
}
.dock-item:active {
  transform: scale(0.92);
}
.dock-item.active {
  background: var(--accent-blue);
  color: #ffffff;
  box-shadow: 0 2px 12px var(--glow-accent);
}
.dock-badge {
  font-size: 10.5px;
  padding: 1px 6px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.2);
  color: #fff;
  font-weight: 800;
}
.dock-icon-only {
  padding: 8px 10px;
  font-size: 14px;
}

/* Toast */
.toast-msg {
  position: fixed;
  top: 60px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--sheet-bg);
  color: var(--text-primary);
  border: 1px solid var(--border-card);
  padding: 10px 18px;
  border-radius: 25px;
  font-size: 12.5px;
  font-weight: 700;
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
  z-index: 300;
  animation: slideDownToast 0.22s ease-out;
}
@keyframes slideDownToast {
  from { opacity: 0; transform: translate(-50%, -10px); }
  to { opacity: 1; transform: translate(-50%, 0); }
}

.vazio-msg {
  text-align: center;
  padding: 60px 16px;
  color: var(--text-muted);
  font-size: 14px;
  font-weight: 600;
}

  </style>
</head>
<body>

  <!-- 1. TOP BAR -->
  <header class="topo">
    <div class="topo-esq">
      <img src="favicon.png" alt="Mil Cópias" class="topo-logo">
      <div class="topo-brand">
        <span class="topo-titulo">Mil Cópias</span>
        <span class="topo-sub"><span class="pulse-dot"></span> Operação 2.0</span>
      </div>
    </div>
    <div class="topo-dir">
      <button id="btnToggleTheme" class="btn-topo-icon" onclick="alternarTema()" title="Alternar Modo Escuro / Claro">
        <span id="iconeTema">🌙</span>
      </button>
      <button id="btnNotificacao" class="btn-topo-icon" onclick="solicitarPermissaoNotificacao()" title="Ativar Notificações no Celular">
        <span id="iconeSino">🔔</span>
      </button>
      <span class="topo-timer-pill"><span id="contador">60</span>s</span>
    </div>
  </header>

  <!-- 2. QUICK TRIAGE STRIP (MINI-KPIS) -->
  <div class="triage-strip" id="triageStrip">
    <button class="triage-chip active" id="chipTodas" onclick="selecionarFiltroRapido('todas')">
      Todas <span class="chip-badge-num"><?= count($semTriagem) ?></span>
    </button>
    <button class="triage-chip chip-urgente" id="chipAtrasadas" onclick="selecionarFiltroRapido('atrasadas')">
      🚨 Atrasadas <span class="chip-badge-num"><?= $totalAtrasadas ?></span>
    </button>
    <button class="triage-chip chip-web" id="chipWeb" onclick="selecionarFiltroRapido('01')">
      ⚡ Abertura Web <span class="chip-badge-num"><?= $totalWeb ?></span>
    </button>
    <button class="triage-chip" id="chipInterna" onclick="selecionarFiltroRapido('00')">
      🏢 Interna <span class="chip-badge-num"><?= $totalInterna ?></span>
    </button>
  </div>

  <!-- 3. BUSCA RÁPIDA -->
  <div class="busca-container">
    <div class="busca-box">
      <span class="busca-icon-lupa">🔍</span>
      <input type="text" id="campoBusca" placeholder="Buscar por OS, cliente, modelo, defeito, cidade..." onkeyup="filtrar()" autocomplete="off">
    </div>
  </div>

  <!-- 4. LISTA DE TICKETS BENTO 2.0 -->
  <main class="lista-chamados">

    <!-- Aba Sem Triagem -->
    <div id="aba-sem-triagem" class="tab-view active">
      <?php if (empty($semTriagem)): ?>
        <div class="vazio-msg">✨ Nenhum chamado pendente sem triagem.</div>
      <?php else: ?>
        <?php foreach ($semTriagem as $c): ?>
          <?php 
            $nomeSt = obterNomeStatus($c['status']); 
            $modLimpo = simplificarModelo($c['modelo']);
            $classeRota = obterClasseRota($c['cidade']);
          ?>
          <article class="ticket-card <?= $c['atrasado'] ? 'atrasado' : '' ?>" id="card-<?= htmlspecialchars($c['os']) ?>" onclick="abrirBottomSheet('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['cidade']) ?>', '<?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)', '<?= addslashes($c['modelo']) ?>', '<?= htmlspecialchars($c['numserie']) ?>', '<?= addslashes($c['local']) ?>', '', '<?= addslashes($c['defeito'] ?: 'Não informado') ?>', '<?= htmlspecialchars($c['codtec']) ?>')" data-status="<?= htmlspecialchars($c['status']) ?>" data-atrasado="<?= $c['atrasado'] ? '1' : '0' ?>" data-texto="<?= strtolower("{$c['os']} {$nomeSt} {$c['cliente']} {$c['cidade']} {$c['local']} {$c['modelo']} {$c['numserie']} {$c['defeito']}") ?>">
            <div class="ticket-topo">
              <div class="ticket-origem">
                <span class="ticket-os-num"><?= htmlspecialchars($c['os']) ?></span>
                <span class="badge-rota <?= $classeRota ?>"><?= htmlspecialchars($c['cidade'] ?: 'GERAL') ?></span>
              </div>
              <div class="ticket-status-sla">
                <span class="badge-status-databit status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($nomeSt) ?></span>
                <span class="badge-sla-pill <?= (int)$c['sla'] < 0 ? 'sla-atrasado' : 'sla-ok' ?>"><?= $c['sla'] ?>h</span>
              </div>
            </div>

            <div class="ticket-cliente-nome"><?= htmlspecialchars($c['cliente']) ?></div>

            <div class="ticket-equip-meta">
              <?php if (!empty($c['local'])): ?>
                <span class="chip-equip chip-equip-local">🏢 <?= htmlspecialchars($c['local']) ?></span>
              <?php endif; ?>
              <span class="chip-equip chip-equip-modelo">🖨️ <?= htmlspecialchars($modLimpo) ?></span>
            </div>

            <div class="ticket-rodape">
              <div class="ticket-defeito-preview">
                <span>🔧 <?= htmlspecialchars($c['defeito'] ?: 'Sem detalhes informados') ?></span>
              </div>
              <button class="btn-quick-assign" onclick="event.stopPropagation(); abrirBottomSheet('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['cidade']) ?>', '<?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)', '<?= addslashes($c['modelo']) ?>', '<?= htmlspecialchars($c['numserie']) ?>', '<?= addslashes($c['local']) ?>', '', '<?= addslashes($c['defeito'] ?: 'Não informado') ?>', '<?= htmlspecialchars($c['codtec']) ?>')">
                👤 Atribuir
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Aba Com Triagem -->
    <div id="aba-com-triagem" class="tab-view">
      <?php if (empty($comTriagem)): ?>
        <div class="vazio-msg">Nenhum chamado atribuído no momento.</div>
      <?php else: ?>
        <?php foreach ($comTriagem as $c): ?>
          <?php 
            $nomeSt = obterNomeStatus($c['status']); 
            $modLimpo = simplificarModelo($c['modelo']);
            $classeRota = obterClasseRota($c['cidade']);
          ?>
          <article class="ticket-card <?= $c['atrasado'] ? 'atrasado' : '' ?>" id="card-<?= htmlspecialchars($c['os']) ?>" onclick="abrirBottomSheet('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['cidade']) ?>', '<?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)', '<?= addslashes($c['modelo']) ?>', '<?= htmlspecialchars($c['numserie']) ?>', '<?= addslashes($c['local']) ?>', '<?= addslashes($c['tecnico']) ?>', '<?= addslashes($c['defeito'] ?: 'Não informado') ?>', '<?= htmlspecialchars($c['codtec']) ?>')" data-status="<?= htmlspecialchars($c['status']) ?>" data-atrasado="<?= $c['atrasado'] ? '1' : '0' ?>" data-texto="<?= strtolower("{$c['os']} {$nomeSt} {$c['cliente']} {$c['cidade']} {$c['local']} {$c['modelo']} {$c['numserie']} {$c['tecnico']} {$c['defeito']}") ?>">
            <div class="ticket-topo">
              <div class="ticket-origem">
                <span class="ticket-os-num"><?= htmlspecialchars($c['os']) ?></span>
                <span class="badge-rota <?= $classeRota ?>"><?= htmlspecialchars($c['cidade'] ?: 'GERAL') ?></span>
              </div>
              <div class="ticket-status-sla">
                <span class="badge-status-databit status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($nomeSt) ?></span>
                <span class="badge-tecnico-nome" style="background-color: <?= htmlspecialchars($c['corTecnico']) ?>">
                  <?= htmlspecialchars($c['tecnico']) ?>
                </span>
              </div>
            </div>

            <div class="ticket-cliente-nome"><?= htmlspecialchars($c['cliente']) ?></div>

            <div class="ticket-equip-meta">
              <?php if (!empty($c['local'])): ?>
                <span class="chip-equip chip-equip-local">🏢 <?= htmlspecialchars($c['local']) ?></span>
              <?php endif; ?>
              <span class="chip-equip chip-equip-modelo">🖨️ <?= htmlspecialchars($modLimpo) ?></span>
            </div>

            <div class="ticket-rodape">
              <div class="ticket-defeito-preview">
                <span>🔧 <?= htmlspecialchars($c['defeito'] ?: 'Sem detalhes informados') ?></span>
              </div>
              <button class="btn-quick-assign" onclick="event.stopPropagation(); abrirBottomSheet('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['cidade']) ?>', '<?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)', '<?= addslashes($c['modelo']) ?>', '<?= htmlspecialchars($c['numserie']) ?>', '<?= addslashes($c['local']) ?>', '<?= addslashes($c['tecnico']) ?>', '<?= addslashes($c['defeito'] ?: 'Não informado') ?>', '<?= htmlspecialchars($c['codtec']) ?>')">
                👤 Alterar
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>

  <!-- 5. FLOATING BOTTOM DOCK (THUMB-ZONE NAVEGAÇÃO) -->
  <nav class="bottom-dock">
    <button class="dock-item active" id="dockSemTriagem" onclick="trocarAba('sem-triagem')">
      ⚡ Sem Triagem <span class="dock-badge"><?= count($semTriagem) ?></span>
    </button>
    <button class="dock-item" id="dockComTriagem" onclick="trocarAba('com-triagem')">
      👥 Com Triagem <span class="dock-badge"><?= count($comTriagem) ?></span>
    </button>
    <button class="dock-item dock-icon-only" onclick="focarBusca()" title="Buscar OS">
      🔍
    </button>
  </nav>

  <!-- 6. NATIVE BOTTOM SHEET (GAVETA DESLIZANTE NATIVA) -->
  <div id="sheetBackdrop" class="sheet-backdrop" onclick="fecharSheetSeFora(event)">
    <div class="bottom-sheet" id="bottomSheet">
      <div class="sheet-handle-bar"></div>
      
      <div class="sheet-header">
        <div>
          <span class="sheet-title-os" id="sheetOsNum">OS 000000</span>
          <span id="sheetBadgeRota" class="badge-rota" style="margin-left: 6px;">CIDADE</span>
        </div>
        <button class="sheet-btn-close" onclick="fecharBottomSheet()">✕</button>
      </div>

      <div class="sheet-cliente" id="sheetCliente">Nome do Cliente</div>

      <div class="sheet-info-grid">
        <div class="sheet-row">
          <span class="sheet-label">Status Databit:</span>
          <span class="sheet-val" id="sheetStatus">-</span>
        </div>
        <div class="sheet-row">
          <span class="sheet-label">Modelo Equip.:</span>
          <span class="sheet-val" id="sheetModelo">-</span>
        </div>
        <div class="sheet-row" id="sheetRowSerie" style="display: none;">
          <span class="sheet-label">Nº de Série:</span>
          <span class="sheet-val" id="sheetNumSerie">-</span>
        </div>
        <div class="sheet-row" id="sheetRowLocal" style="display: none;">
          <span class="sheet-label">Local / Setor:</span>
          <span class="sheet-val" id="sheetLocal">-</span>
        </div>
        <div class="sheet-row" id="sheetRowTecAtual" style="display: none;">
          <span class="sheet-label">Técnico Atual:</span>
          <span class="sheet-val" id="sheetTecnicoAtual">-</span>
        </div>

        <div class="sheet-defeito-card">
          <div class="sheet-defeito-label">Defeito Informado pelo Cliente</div>
          <div class="sheet-defeito-texto" id="sheetDefeito">-</div>
        </div>
      </div>

      <div class="sheet-assign-section">
        <label for="sheetSelectTecnico" class="sheet-assign-label">👤 Definir / Encaminhar para Técnico:</label>
        <select id="sheetSelectTecnico" class="sheet-select-tec">
          <option value="">-- Escolha o técnico --</option>
          <?php foreach ($listaTecnicosFixa as $t): ?>
            <option value="<?= $t['codigo'] ?>"><?= $t['nome'] ?> (<?= $t['codigo'] ?>)</option>
          <?php endforeach; ?>
        </select>
        <button id="sheetBtnConfirmar" class="sheet-btn-confirm" onclick="confirmarDefinicaoTecnicoSheet()">
          ⚡ Confirmar Despacho (Status 03)
        </button>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div id="toastMsg" class="toast-msg" style="display: none;"></div>

  <script>
    let abaAtiva = 'sem-triagem';
    let filtroRapidoAtivo = 'todas';
    let osSelecionadaSheet = null;

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
      if (icone) icone.textContent = tema === 'dark' ? '🌙' : '☀️';
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', tema === 'dark' ? '#070a12' : '#f4f6fa');
    }

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
      filtrar();
    }

    function selecionarFiltroRapido(tipo) {
      filtroRapidoAtivo = tipo;
      document.querySelectorAll('.triage-chip').forEach(c => c.classList.remove('active'));
      if (tipo === 'todas') document.getElementById('chipTodas')?.classList.add('active');
      else if (tipo === 'atrasadas') document.getElementById('chipAtrasadas')?.classList.add('active');
      else if (tipo === '01') document.getElementById('chipWeb')?.classList.add('active');
      else if (tipo === '00') document.getElementById('chipInterna')?.classList.add('active');
      filtrar();
    }

    function focarBusca() {
      const el = document.getElementById('campoBusca');
      if (el) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        el.focus();
      }
    }

    function filtrar() {
      const termo = document.getElementById('campoBusca').value.toLowerCase().trim();
      const viewAtiva = document.getElementById(abaAtiva === 'sem-triagem' ? 'aba-sem-triagem' : 'aba-com-triagem');
      if (!viewAtiva) return;

      viewAtiva.querySelectorAll('.ticket-card').forEach(card => {
        const txt = card.getAttribute('data-texto') || '';
        const st = card.getAttribute('data-status') || '';
        const atr = card.getAttribute('data-atrasado') === '1';

        const bateTexto = !termo || txt.includes(termo);
        let bateFiltro = true;

        if (abaAtiva === 'sem-triagem') {
          if (filtroRapidoAtivo === 'atrasadas') bateFiltro = atr;
          else if (filtroRapidoAtivo === '01') bateFiltro = st === '01';
          else if (filtroRapidoAtivo === '00') bateFiltro = st === '00';
        }

        card.style.display = (bateTexto && bateFiltro) ? 'block' : 'none';
      });
    }

    function abrirBottomSheet(os, cliente, cidade, status, modelo, numserie, local, tecnico, defeito, codtec) {
      osSelecionadaSheet = os;
      document.getElementById('sheetOsNum').textContent = 'OS ' + os;
      
      const bRota = document.getElementById('sheetBadgeRota');
      bRota.textContent = cidade || 'GERAL';
      
      let cls = 'rota-padrao';
      const cUpper = (cidade || '').toUpperCase();
      if (cUpper.includes('LINHARES')) cls = 'rota-linhares';
      else if (cUpper.includes('COLATINA')) cls = 'rota-colatina';
      else if (cUpper.includes('ARACRUZ')) cls = 'rota-aracruz';
      else if (cUpper.includes('JAGUAR')) cls = 'rota-jaguare';
      bRota.className = 'badge-rota ' + cls;

      document.getElementById('sheetCliente').textContent = cliente;
      document.getElementById('sheetStatus').textContent = status;
      document.getElementById('sheetModelo').textContent = modelo || 'Não informado';

      const rowSerie = document.getElementById('sheetRowSerie');
      if (numserie) {
        rowSerie.style.display = 'flex';
        document.getElementById('sheetNumSerie').textContent = numserie;
      } else { rowSerie.style.display = 'none'; }

      const rowLocal = document.getElementById('sheetRowLocal');
      if (local) {
        rowLocal.style.display = 'flex';
        document.getElementById('sheetLocal').textContent = local;
      } else { rowLocal.style.display = 'none'; }

      const rowTec = document.getElementById('sheetRowTecAtual');
      if (tecnico && tecnico !== 'A Definir') {
        rowTec.style.display = 'flex';
        document.getElementById('sheetTecnicoAtual').textContent = tecnico;
      } else { rowTec.style.display = 'none'; }

      document.getElementById('sheetDefeito').textContent = defeito;

      const sel = document.getElementById('sheetSelectTecnico');
      if (sel && codtec) sel.value = codtec;

      document.getElementById('sheetBackdrop').classList.add('aberto');
      document.body.style.overflow = 'hidden';
    }

    function fecharBottomSheet() {
      document.getElementById('sheetBackdrop').classList.remove('aberto');
      document.body.style.overflow = '';
      osSelecionadaSheet = null;
    }

    function fecharSheetSeFora(e) {
      if (e.target && e.target.id === 'sheetBackdrop') fecharBottomSheet();
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
      btn.textContent = 'Gravando no Databit...';

      const formData = new FormData();
      formData.append('acao', 'definir_tecnico');
      formData.append('os', osSelecionadaSheet);
      formData.append('codtec', codtec);
      formData.append('nomeTecnico', nomeTecnico);

      try {
        const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const json = await res.json();
        if (json.sucesso) {
          mostrarToast('✅ OS ' + osSelecionadaSheet + ' despachada com sucesso!');
          fecharBottomSheet();
          setTimeout(() => { window.location.reload(); }, 1200);
        } else {
          alert('Erro ao atribuir técnico: ' + (json.erro || 'Falha no Databit'));
          btn.disabled = false;
          btn.textContent = '⚡ Confirmar Despacho (Status 03)';
        }
      } catch (err) {
        alert('Erro de comunicação: ' + err.message);
        btn.disabled = false;
        btn.textContent = '⚡ Confirmar Despacho (Status 03)';
      }
    }

    function mostrarToast(msg, duracao = 4000) {
      const t = document.getElementById('toastMsg');
      if (!t) return;
      t.textContent = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, duracao);
    }

    function atualizarBadgeApp(qtd) {
      const num = parseInt(qtd, 10) || 0;
      if (num <= 0) {
        if ('clearAppBadge' in navigator) navigator.clearAppBadge().catch(() => {});
      } else if ('setAppBadge' in navigator) {
        navigator.setAppBadge(num).catch(() => {});
      }
      document.title = num > 0 ? '(' + num + ') Mil Cópias 2.0' : 'Mil Cópias 2.0';
    }

    function emitirSomAlerta() {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(600, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(900, ctx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.35);
      } catch (e) {}
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
          mostrarToast('🔔 Notificações ativadas no celular!');
          emitirSomAlerta();
        }
      } catch (e) {}
    }

    function atualizarBotaoNotificacao(perm) {
      const btn = document.getElementById('btnNotificacao');
      const icone = document.getElementById('iconeSino');
      if (!btn || !icone) return;
      const status = perm || (window.Notification ? Notification.permission : 'default');
      if (status === 'granted') {
        btn.classList.add('ativo');
        icone.textContent = '🔔';
      } else {
        btn.classList.remove('ativo');
        icone.textContent = '🔕';
      }
    }

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js?v=20261020_02')
        .then(reg => console.log('SW 2.0 ativo:', reg.scope))
        .catch(err => console.warn('SW:', err));
    }

    iniciarTema();
    atualizarBotaoNotificacao();

    let t = 60;
    setInterval(() => {
      t--;
      if (t < 0) t = 60;
      document.getElementById('contador').textContent = t;
    }, 1000);
  </script>

</body>
</html>

