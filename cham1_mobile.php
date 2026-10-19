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
        case '00': return 'ABERTURA OS INTERNA';
        case '01': return 'ABERTURA DE OS WEB';
        case '03': return 'ENCAMINHADO TECNICO';
        case '04': return 'EM ATENDIMENTO';
        case '05': return 'FINALIZADO TECNICO';
        case '06': return 'OS CONCLUIDA';
        case '08': return 'CANCELADA MOBILE';
        default: return 'STATUS ' . $codigo;
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <meta http-equiv="refresh" content="60">
  <title>Chamados - Mil Cópias</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
    body { background-color: #f1f5f9; color: #0f172a; font-size: 13px; line-height: 1.25; min-height: 100vh; }

    /* Barra Superior */
    .topo {
      position: sticky; top: 0; background: #0f172a; color: #fff;
      padding: 8px 12px; display: flex; justify-content: space-between; align-items: center;
      z-index: 100;
    }
    .topo-titulo { font-size: 14.5px; font-weight: 700; }
    .topo-timer { font-size: 11px; color: #94a3b8; }

    /* Abas Principais */
    .tabs {
      display: flex; background: #fff; border-bottom: 1px solid #cbd5e1;
      position: sticky; top: 37px; z-index: 99;
    }
    .tab-btn {
      flex: 1; padding: 9px 4px; border: none; background: none; font-size: 13px; font-weight: 700;
      color: #64748b; text-align: center; cursor: pointer; border-bottom: 3px solid transparent;
    }
    .tab-btn.active { color: #0284c7; border-bottom-color: #0284c7; background: #f8fafc; }
    .badge-count {
      display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 11px;
      font-weight: 800; margin-left: 3px;
    }
    .badge-laranja { background: #ea580c; color: #fff; }
    .badge-azul { background: #0284c7; color: #fff; }

    /* Sub-filtros por Nome Real do Status */
    .sub-filtros {
      display: flex; gap: 6px; padding: 5px 8px; background: #e2e8f0; border-bottom: 1px solid #cbd5e1;
      align-items: center; overflow-x: auto; white-space: nowrap;
    }
    .sub-btn {
      padding: 4px 9px; border: 1px solid #cbd5e1; background: #fff; border-radius: 12px;
      font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; flex-shrink: 0;
    }
    .sub-btn.active { background: #0284c7; color: #fff; border-color: #0284c7; }

    /* Busca */
    .busca { padding: 5px 8px; background: #fff; border-bottom: 1px solid #e2e8f0; }
    .busca input {
      width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
      font-size: 12.5px; outline: none; background: #f8fafc;
    }
    .busca input:focus { border-color: #0284c7; background: #fff; }

    /* Lista Compacta */
    .lista { padding: 5px; max-width: 900px; margin: 0 auto; }
    .tab-conteudo { display: none; }
    .tab-conteudo.active { display: block; }

    /* CARD DE OS ULTRA-RESUMIDO (CLICÁVEL PARA EXPANDIR) */
    .item-os {
      background: #fff; border-radius: 6px; padding: 7px 9px; margin-bottom: 5px;
      border: 1px solid #e2e8f0; cursor: pointer; transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.02); user-select: none; -webkit-tap-highlight-color: transparent;
    }
    .item-os:active { background: #f8fafc; }
    .item-os.atrasado { border-left: 4px solid #ef4444 !important; }
    .item-os.expandido { border-color: #0284c7; box-shadow: 0 2px 8px rgba(2, 132, 199, 0.12); }

    /* VISÃO RESUMIDA */
    .card-resumo { display: flex; flex-direction: column; gap: 3px; }
    .resumo-topo { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
    .topo-esquerda { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
    .os-num { font-family: monospace; font-weight: 800; font-size: 13.5px; color: #0284c7; }

    /* Cidade BEM CHAMATIVA */
    .badge-cidade {
      font-size: 10.5px; font-weight: 800; color: #1e40af; background: #dbeafe;
      padding: 1px 6px; border-radius: 4px; border: 1px solid #bfdbfe;
      display: inline-flex; align-items: center; gap: 2px;
    }

    /* Badges com Nome Oficial do Status do Databit */
    .tag-status {
      font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 4px;
      letter-spacing: 0.2px; text-transform: uppercase;
    }
    .tag-status-00 { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    .tag-status-01 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .tag-status-03 { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .tag-status-04 { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }

    .topo-direita { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .tag-sla { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; }
    .sla-atraso { background: #fee2e2; color: #dc2626; }
    .sla-normal { background: #f1f5f9; color: #475569; }
    .tag-tecnico {
      font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 4px;
      text-transform: uppercase; color: #0f172a;
    }
    .icone-expandir { font-size: 9px; color: #94a3b8; transition: transform 0.2s ease; margin-left: 2px; }
    .item-os.expandido .icone-expandir { transform: rotate(180deg); color: #0284c7; }

    /* Linha 2: Cliente + Modelo Compacto */
    .cliente-linha { display: flex; align-items: baseline; gap: 4px; flex-wrap: wrap; line-height: 1.3; }
    .cliente-nome { font-weight: 700; font-size: 12.5px; color: #0f172a; }
    .separador-dot { color: #94a3b8; font-size: 11px; }
    .modelo-compacto {
      font-size: 11px; font-weight: 700; color: #0369a1; background: #f0f9ff;
      padding: 1px 5px; border-radius: 3px; border: 1px solid #e0f2fe;
    }

    /* Linha 3: O Defeito Chamativo e Direto */
    .resumo-defeito {
      background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 3px;
      padding: 3px 6px; display: flex; align-items: flex-start; gap: 4px; margin-top: 1px;
    }
    .defeito-icone { color: #d97706; font-size: 11px; flex-shrink: 0; margin-top: 1px; }
    .defeito-texto { font-weight: 700; font-size: 11.5px; color: #92400e; line-height: 1.3; word-break: break-word; }

    /* DETALHES EXPANSÍVEIS (MOSTRADOS AO TOCAR NA OS) */
    .card-detalhes {
      margin-top: 7px; padding-top: 7px; border-top: 1px dashed #cbd5e1;
      display: flex; flex-direction: column; gap: 6px; animation: fadeInDet 0.2s ease-out;
    }
    @keyframes fadeInDet {
      from { opacity: 0; transform: translateY(-3px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .detalhes-grid {
      font-size: 11.5px; color: #334155; background: #f8fafc; padding: 6px 8px;
      border-radius: 5px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 3px;
    }
    .equip-serie { font-family: monospace; font-weight: 700; color: #475569; }
    .linha-acoes-expandida { display: flex; justify-content: flex-end; padding-top: 2px; }
    .btn-definir-tecnico {
      width: 100%; background: #0284c7; color: #fff; border: none; border-radius: 6px;
      font-weight: 700; font-size: 12.5px; padding: 8px 12px; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 6px;
      box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25);
    }
    .btn-definir-tecnico:active { background: #0369a1; transform: scale(0.99); }

    /* MODAL */
    .modal-overlay {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px);
      z-index: 200; display: flex; align-items: flex-end; justify-content: center;
    }
    @media (min-width: 600px) { .modal-overlay { align-items: center; padding: 20px; } }
    .modal-card {
      background: #fff; width: 100%; max-width: 500px; border-radius: 14px 14px 0 0;
      padding: 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 12px;
    }
    @media (min-width: 600px) { .modal-card { border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.25); } }
    .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
    .modal-header h3 { font-size: 15px; font-weight: 800; color: #0f172a; }
    .modal-fechar { background: none; border: none; font-size: 18px; font-weight: bold; color: #64748b; cursor: pointer; }
    .modal-os-info { background: #f8fafc; padding: 9px 11px; border-radius: 7px; border: 1px solid #e2e8f0; font-size: 12.5px; display: flex; flex-direction: column; gap: 3px; }
    .destaque-os { font-family: monospace; font-weight: 800; color: #0284c7; font-size: 14.5px; }
    .modal-aviso { font-size: 11.5px; color: #0369a1; background: #e0f2fe; padding: 7px 9px; border-radius: 6px; border-left: 3px solid #0284c7; line-height: 1.35; }
    .modal-campo { display: flex; flex-direction: column; gap: 5px; }
    .modal-campo label { font-weight: 700; font-size: 12.5px; color: #334155; }
    .select-tecnico { width: 100%; padding: 9px 11px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 13.5px; font-weight: 600; color: #0f172a; outline: none; background: #fff; }
    .modal-footer { display: flex; gap: 8px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #e2e8f0; }
    .btn-cancelar { flex: 1; padding: 10px; border: 1px solid #cbd5e1; background: #f1f5f9; color: #475569; border-radius: 6px; font-weight: 700; font-size: 12.5px; cursor: pointer; }
    .btn-confirmar { flex: 2; padding: 10px; border: none; background: #0284c7; color: #fff; border-radius: 6px; font-weight: 800; font-size: 13px; cursor: pointer; }

    /* Toast */
    .toast-aviso {
      position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
      background: #0f172a; color: #fff; padding: 9px 15px; border-radius: 20px;
      font-size: 12.5px; font-weight: 700; box-shadow: 0 4px 15px rgba(0,0,0,0.3); z-index: 300;
      max-width: 90%; text-align: center;
    }

    .vazio { text-align: center; padding: 30px 10px; color: #94a3b8; font-size: 13px; }
  </style>
</head>
<body>

  <!-- Topo -->
  <div class="topo">
    <span class="topo-titulo">🖨️ Mil Cópias</span>
    <span class="topo-timer"><span id="contador">60</span>s</span>
  </div>

  <!-- Abas -->
  <div class="tabs">
    <button class="tab-btn active" id="tabBtnSem" onclick="trocarAba('sem-triagem')">
      Sem Triagem <span class="badge-count badge-laranja"><?= count($semTriagem) ?></span>
    </button>
    <button class="tab-btn" id="tabBtnCom" onclick="trocarAba('com-triagem')">
      Com Triagem <span class="badge-count badge-azul"><?= count($comTriagem) ?></span>
    </button>
  </div>

  <!-- Sub-filtros por Nomes Oficiais do Databit ERP -->
  <div class="sub-filtros" id="subFiltrosBar">
    <button class="sub-btn active" id="btnSubTodas" onclick="filtrarSubStatus('')">Todas (<?= count($semTriagem) ?>)</button>
    <button class="sub-btn" id="btnSub00" onclick="filtrarSubStatus('00')">Abertura Interna (<?= $qtdStatus00 ?>)</button>
    <button class="sub-btn" id="btnSub01" onclick="filtrarSubStatus('01')">Abertura Web (<?= $qtdStatus01 ?>)</button>
  </div>

  <!-- Busca -->
  <div class="busca">
    <input type="text" id="campoBusca" placeholder="🔍 Buscar OS, modelo, cliente, defeito, cidade, status..." onkeyup="filtrar()">
  </div>

  <!-- Lista Compacta -->
  <div class="lista">

    <!-- Aba 1: Sem Triagem -->
    <div id="aba-sem-triagem" class="tab-conteudo active">
      <?php if (empty($semTriagem)): ?>
        <div class="vazio">Nenhum chamado sem triagem.</div>
      <?php else: ?>
        <?php foreach ($semTriagem as $c): ?>
          <?php $nomeSt = obterNomeStatus($c['status']); $modLimpo = simplificarModelo($c['modelo']); ?>
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" id="card-<?= htmlspecialchars($c['os']) ?>" onclick="toggleExpandirOS('<?= htmlspecialchars($c['os']) ?>', event)" data-status="<?= htmlspecialchars($c['status']) ?>" data-texto="<?= strtolower("{$c['os']} {$nomeSt} {$c['cliente']} {$c['cidade']} {$c['modelo']} {$c['numserie']} {$c['defeito']}") ?>">
            <!-- 1. RESUMO COMPACTO -->
            <div class="card-resumo">
              <div class="resumo-topo">
                <div class="topo-esquerda">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
                  <span class="badge-cidade">📍 <?= htmlspecialchars($c['cidade']) ?></span>
                  <span class="tag-status tag-status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($nomeSt) ?></span>
                </div>
                <div class="topo-direita">
                  <span class="tag-sla <?= (int)$c['sla'] < 0 ? 'sla-atraso' : 'sla-normal' ?>"><?= $c['sla'] ?>h</span>
                  <span class="icone-expandir">▼</span>
                </div>
              </div>

              <div class="cliente-linha">
                <span class="cliente-nome"><?= htmlspecialchars($c['cliente']) ?></span>
                <span class="separador-dot">•</span>
                <span class="modelo-compacto">🖨️ <?= htmlspecialchars($modLimpo) ?></span>
              </div>

              <div class="resumo-defeito">
                <span class="defeito-icone">🔧</span>
                <span class="defeito-texto"><?= htmlspecialchars($c['defeito']) ?></span>
              </div>
            </div>

            <!-- 2. DETALHES EXPANSÍVEIS (AO TOCAR NA OS) -->
            <div class="card-detalhes" id="detalhes-<?= htmlspecialchars($c['os']) ?>" style="display: none;">
              <div class="detalhes-grid">
                <div><strong>Status Databit:</strong> <?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)</div>
                <div><strong>Modelo Completo:</strong> <?= htmlspecialchars($c['modelo']) ?></div>
                <?php if (!empty($c['numserie'])): ?>
                  <div><strong>Nº de Série:</strong> <span class="equip-serie"><?= htmlspecialchars($c['numserie']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($c['local'])): ?>
                  <div><strong>Local/Setor:</strong> <?= htmlspecialchars($c['local']) ?></div>
                <?php endif; ?>
              </div>
              <div class="linha-acoes-expandida">
                <button class="btn-definir-tecnico" onclick="event.stopPropagation(); abrirModalTecnico('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['codtec']) ?>')">
                  👤 Definir / Transferir Técnico
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Aba 2: Com Triagem -->
    <div id="aba-com-triagem" class="tab-conteudo">
      <?php if (empty($comTriagem)): ?>
        <div class="vazio">Nenhum chamado com triagem.</div>
      <?php else: ?>
        <?php foreach ($comTriagem as $c): ?>
          <?php $nomeSt = obterNomeStatus($c['status']); $modLimpo = simplificarModelo($c['modelo']); ?>
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" id="card-<?= htmlspecialchars($c['os']) ?>" onclick="toggleExpandirOS('<?= htmlspecialchars($c['os']) ?>', event)" data-status="<?= htmlspecialchars($c['status']) ?>" data-texto="<?= strtolower("{$c['os']} {$nomeSt} {$c['cliente']} {$c['cidade']} {$c['modelo']} {$c['numserie']} {$c['tecnico']} {$c['defeito']}") ?>">
            <!-- 1. RESUMO COMPACTO -->
            <div class="card-resumo">
              <div class="resumo-topo">
                <div class="topo-esquerda">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
                  <span class="badge-cidade">📍 <?= htmlspecialchars($c['cidade']) ?></span>
                  <span class="tag-status tag-status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($nomeSt) ?></span>
                </div>
                <div class="topo-direita">
                  <span class="tag-tecnico" style="background-color: <?= htmlspecialchars($c['corTecnico']) ?>">
                    <?= htmlspecialchars($c['tecnico']) ?>
                  </span>
                  <span class="icone-expandir">▼</span>
                </div>
              </div>

              <div class="cliente-linha">
                <span class="cliente-nome"><?= htmlspecialchars($c['cliente']) ?></span>
                <span class="separador-dot">•</span>
                <span class="modelo-compacto">🖨️ <?= htmlspecialchars($modLimpo) ?></span>
              </div>

              <div class="resumo-defeito">
                <span class="defeito-icone">🔧</span>
                <span class="defeito-texto"><?= htmlspecialchars($c['defeito']) ?></span>
              </div>
            </div>

            <!-- 2. DETALHES EXPANSÍVEIS (AO TOCAR NA OS) -->
            <div class="card-detalhes" id="detalhes-<?= htmlspecialchars($c['os']) ?>" style="display: none;">
              <div class="detalhes-grid">
                <div><strong>Status Databit:</strong> <?= htmlspecialchars($nomeSt) ?> (<?= htmlspecialchars($c['status']) ?>)</div>
                <div><strong>Modelo Completo:</strong> <?= htmlspecialchars($c['modelo']) ?></div>
                <?php if (!empty($c['numserie'])): ?>
                  <div><strong>Nº de Série:</strong> <span class="equip-serie"><?= htmlspecialchars($c['numserie']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($c['local'])): ?>
                  <div><strong>Local/Setor:</strong> <?= htmlspecialchars($c['local']) ?></div>
                <?php endif; ?>
                <?php if (!empty($c['tecnico']) && $c['tecnico'] !== 'A Definir'): ?>
                  <div><strong>Técnico Atual:</strong> <?= htmlspecialchars($c['tecnico']) ?></div>
                <?php endif; ?>
              </div>
              <div class="linha-acoes-expandida">
                <button class="btn-definir-tecnico" onclick="event.stopPropagation(); abrirModalTecnico('<?= htmlspecialchars($c['os']) ?>', '<?= addslashes($c['cliente']) ?>', '<?= htmlspecialchars($c['codtec']) ?>')">
                  👤 Alterar Técnico
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <!-- Modal Definir Técnico (Mobile Friendly) -->
  <div id="modalTecnico" class="modal-overlay" style="display: none;" onclick="fecharModalSeFora(event)">
    <div class="modal-card">
      <div class="modal-header">
        <h3>👤 Definir Técnico</h3>
        <button class="modal-fechar" onclick="fecharModalTecnico()">✕</button>
      </div>
      <div class="modal-body">
        <div class="modal-os-info">
          <div><strong>OS:</strong> <span id="modalOsNum" class="destaque-os"></span></div>
          <div><strong>Cliente:</strong> <span id="modalCliente"></span></div>
        </div>
        <p class="modal-aviso">
          ⚡ Ao definir o técnico, a OS será atualizada no Databit para o status <strong>ENCAMINHADO TECNICO (03)</strong> e aparecerá diretamente no sistema do técnico.
        </p>
        <div class="modal-campo">
          <label for="selectTecnico">Selecione o Técnico:</label>
          <select id="selectTecnico" class="select-tecnico">
            <option value="">-- Escolha o técnico --</option>
            <?php foreach ($listaTecnicosFixa as $t): ?>
              <option value="<?= $t['codigo'] ?>"><?= $t['nome'] ?> (<?= $t['codigo'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-cancelar" onclick="fecharModalTecnico()">Cancelar</button>
        <button class="btn-confirmar" id="btnSalvarTecnico" onclick="confirmarDefinicaoTecnico()">Confirmar (Status 03)</button>
      </div>
    </div>
  </div>

  <!-- Toast de Notificação -->
  <div id="toastNotificacao" class="toast-aviso" style="display: none;"></div>

  <script>
    let subStatusAtivo = '';
    let osSelecionadaModal = null;
    let osExpandidas = new Set();

    function trocarAba(aba) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-conteudo').forEach(c => c.classList.remove('active'));
      const subBar = document.getElementById('subFiltrosBar');

      if (aba === 'sem-triagem') {
        document.getElementById('tabBtnSem').classList.add('active');
        document.getElementById('aba-sem-triagem').classList.add('active');
        if (subBar) subBar.style.display = 'flex';
      } else {
        document.getElementById('tabBtnCom').classList.add('active');
        document.getElementById('aba-com-triagem').classList.add('active');
        if (subBar) subBar.style.display = 'none';
      }
      filtrar();
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
      filtrar();
    }

    function toggleExpandirOS(os, event) {
      if (event && event.target && event.target.closest('.btn-definir-tecnico')) {
        return;
      }
      const elCard = document.getElementById('card-' + os);
      const elDet = document.getElementById('detalhes-' + os);
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

    function filtrar() {
      const termo = document.getElementById('campoBusca').value.toLowerCase().trim();
      document.querySelectorAll('.item-os').forEach(el => {
        const texto = el.getAttribute('data-texto') || '';
        const st = el.getAttribute('data-status') || '';
        
        let bateTexto = !termo || texto.includes(termo);
        let bateSub = !subStatusAtivo || st === subStatusAtivo;

        el.style.display = (bateTexto && bateSub) ? 'block' : 'none';
      });
    }

    function abrirModalTecnico(os, cliente, codtecAtual) {
      osSelecionadaModal = os;
      document.getElementById('modalOsNum').textContent = os;
      document.getElementById('modalCliente').textContent = cliente;
      const select = document.getElementById('selectTecnico');
      if (select && codtecAtual) {
        select.value = codtecAtual;
      }
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

      const formData = new FormData();
      formData.append('acao', 'definir_tecnico');
      formData.append('os', osSelecionadaModal);
      formData.append('codtec', codtec);
      formData.append('nomeTecnico', nomeTecnico);

      try {
        const res = await fetch(window.location.pathname, {
          method: 'POST',
          body: formData
        });
        const json = await res.json();
        if (json.sucesso) {
          mostrarToast(`✅ OS ${osSelecionadaModal} atribuída com sucesso! Atualizada para ENCAMINHADO TECNICO.`);
          fecharModalTecnico();
          setTimeout(() => { window.location.reload(); }, 1200);
        } else {
          alert('Erro ao atribuir técnico: ' + (json.erro || 'Falha'));
          btn.disabled = false;
          btn.textContent = 'Confirmar (Status 03)';
        }
      } catch (err) {
        alert('Erro de comunicação: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Confirmar (Status 03)';
      }
    }

    function mostrarToast(msg, duracao = 4000) {
      const t = document.getElementById('toastNotificacao');
      if (!t) return;
      t.textContent = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, duracao);
    }

    let t = 60;
    setInterval(() => {
      t--;
      if (t < 0) t = 60;
      document.getElementById('contador').textContent = t;
    }, 1000);
  </script>

</body>
</html>
