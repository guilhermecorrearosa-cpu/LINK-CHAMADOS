<?php
/**
 * Mil Cópias - Painel de Chamados Ultra-Compacto para Celular
 * Integração Direta com a API Databit ERP (http://databitbh.com:54235/databitservice/)
 * Layout: 1 a 2 linhas por OS, sem corte de nome de cliente, sem '#' e com Status (00, 01, etc.)
 */

$api_base_url = 'http://databitbh.com:54235/databitservice/';
$cham1_url = 'http://databitbh.com:51202/mc/cham1.php';

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

// 4. Buscar defeitos reais e status oficial das OS na API do Databit
$detalhesAPI = carregarDetalhesReaisEmLote($todasOS, $api_base_url);

// 5. Vincular defeito e status a cada OS
$qtdStatus00 = 0;
$qtdStatus01 = 0;

foreach ($semTriagem as &$item) {
    $info = isset($detalhesAPI[$item['os']]) ? $detalhesAPI[$item['os']] : ['defeito' => 'Não informado', 'status' => '00'];
    $item['defeito'] = $info['defeito'];
    $item['status'] = !empty($info['status']) ? $info['status'] : '00';
    if ($item['status'] === '01') {
        $qtdStatus01++;
    } else {
        $qtdStatus00++;
    }
}
unset($item);

foreach ($comTriagem as &$item) {
    $info = isset($detalhesAPI[$item['os']]) ? $detalhesAPI[$item['os']] : ['defeito' => 'Não informado', 'status' => '03'];
    $item['defeito'] = $info['defeito'];
    $item['status'] = !empty($info['status']) ? $info['status'] : '03';
}
unset($item);

/**
 * Consulta a API Databit para trazer o defeito real e o status de cada OS
 */
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

    // 4.1 Buscar OS (motivo, observação e statusos)
    $opts['http']['content'] = $filtroOS;
    $resOS = @file_get_contents($baseUrl . 'OsController/listar', false, stream_context_create($opts));
    $dadosOS = [];
    if ($resOS) {
        $jsonOS = json_decode($resOS, true);
        $itensOS = isset($jsonOS['value']) ? $jsonOS['value'] : (is_array($jsonOS) ? $jsonOS : []);
        foreach ($itensOS as $o) {
            $dadosOS[$o['codigo']] = [
                'motivo' => !empty($o['motivo']) ? trim($o['motivo']) : '',
                'obs' => !empty($o['obs']) ? trim($o['obs']) : '',
                'statusos' => !empty($o['statusos']) ? trim($o['statusos']) : '00'
            ];
        }
    }

    // 4.2 Buscar OsDefeito (defeito específico e observação da ocorrência)
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

    // 4.3 Se houver códigos numéricos de defeito (ex: 0003, 0195), traduzir via catálogo
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
                    $nomesCatalogo[$c['codigo']] = trim($c['nome']);
                }
            }
        }
    }

    // 4.4 Consolidação da melhor descrição de defeito e status para cada OS
    foreach ($listaOS as $numOS) {
        $defeitoFinal = '';
        $statusFinal = isset($dadosOS[$numOS]['statusos']) ? $dadosOS[$numOS]['statusos'] : '00';

        // 1ª Prioridade: Motivo direto da OS (o que o cliente reclamou)
        if (!empty($dadosOS[$numOS]['motivo'])) {
            $defeitoFinal = $dadosOS[$numOS]['motivo'];
        }

        // 2ª Prioridade: Se não tem motivo, verificar OsDefeitoController
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

        // 3ª Prioridade: Campo de observação da OS
        if (!$defeitoFinal && !empty($dadosOS[$numOS]['obs'])) {
            $defeitoFinal = $dadosOS[$numOS]['obs'];
        }

        // Limpeza de texto e formatação
        if ($defeitoFinal) {
            $defeitoFinal = ltrim($defeitoFinal, "- \t\n\r");
            $defeitoFinal = normalizarTexto($defeitoFinal);
        } else {
            $defeitoFinal = 'Defeito não detalhado no sistema';
        }

        $mapaResultado[$numOS] = [
            'defeito' => $defeitoFinal,
            'status' => $statusFinal
        ];
    }

    return $mapaResultado;
}

function normalizarTexto($str) {
    if (mb_check_encoding($str, 'UTF-8')) {
        return $str;
    }
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta http-equiv="refresh" content="60">
  <title>Chamados - Mil Cópias</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
    body { background-color: #f8fafc; color: #0f172a; font-size: 13px; line-height: 1.3; }

    /* Barra Superior Fixa */
    .topo {
      position: sticky; top: 0; background: #0f172a; color: #fff;
      padding: 9px 12px; display: flex; justify-content: space-between; align-items: center;
      z-index: 100;
    }
    .topo-titulo { font-size: 15px; font-weight: 700; }
    .topo-timer { font-size: 11px; color: #94a3b8; }

    /* Abas Principais */
    .tabs {
      display: flex; background: #fff; border-bottom: 1px solid #cbd5e1;
      position: sticky; top: 40px; z-index: 99;
    }
    .tab-btn {
      flex: 1; padding: 10px 4px; border: none; background: none; font-size: 13px; font-weight: 700;
      color: #64748b; text-align: center; cursor: pointer; border-bottom: 3px solid transparent;
    }
    .tab-btn.active { color: #0284c7; border-bottom-color: #0284c7; background: #f8fafc; }
    .badge-count {
      display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 11px;
      font-weight: 800; margin-left: 3px;
    }
    .badge-laranja { background: #ea580c; color: #fff; }
    .badge-azul { background: #0284c7; color: #fff; }

    /* Sub-filtros para Status 00 e Status 01 */
    .sub-filtros {
      display: flex; gap: 6px; padding: 6px 10px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0;
      align-items: center;
    }
    .sub-btn {
      padding: 4px 10px; border: 1px solid #cbd5e1; background: #fff; border-radius: 12px;
      font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer;
    }
    .sub-btn.active {
      background: #0284c7; color: #fff; border-color: #0284c7;
    }

    /* Busca */
    .busca {
      padding: 6px 10px; background: #fff; border-bottom: 1px solid #e2e8f0;
    }
    .busca input {
      width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
      font-size: 13px; outline: none; background: #f8fafc;
    }
    .busca input:focus { border-color: #0284c7; background: #fff; }

    /* Lista Compacta com Nome Completo do Cliente */
    .lista { padding: 6px; }
    .tab-conteudo { display: none; }
    .tab-conteudo.active { display: block; }

    .item-os {
      background: #fff; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px;
      border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 4px;
    }
    .item-os.atrasado {
      border-left: 4px solid #ef4444; background: #fffdfd;
    }

    /* Linha 1: OS + Status + Nome Completo do Cliente + Cidade + SLA/Técnico */
    .linha-1 {
      display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;
    }
    .os-cli {
      display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0;
    }
    .os-topo-info {
      display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    }
    .os-num {
      font-family: monospace; font-weight: 800; font-size: 14px; color: #0284c7;
    }

    /* Badges de Status (00, 01, 03, 04) */
    .tag-status {
      font-size: 10.5px; font-weight: 800; padding: 1px 6px; border-radius: 4px;
      letter-spacing: 0.2px; text-transform: uppercase;
    }
    .tag-status-00 { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    .tag-status-01 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .tag-status-03 { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .tag-status-04 { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }

    .cliente-nome {
      font-weight: 700; font-size: 13px; color: #0f172a; white-space: normal; word-break: break-word; line-height: 1.35;
    }
    .cidade-nome {
      font-size: 11px; color: #64748b; font-weight: 600;
    }

    .tags-status {
      display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0;
    }
    .tag-tecnico {
      font-size: 10.5px; font-weight: 800; padding: 2px 6px; border-radius: 4px;
      text-transform: uppercase; color: #0f172a;
    }
    .tag-sla {
      font-size: 10.5px; font-weight: 700; padding: 2px 5px; border-radius: 3px;
    }
    .sla-atraso { background: #fee2e2; color: #dc2626; }
    .sla-normal { background: #f1f5f9; color: #475569; }

    /* Linha 2: O Defeito Real do Databit */
    .linha-2 {
      display: flex; align-items: flex-start; gap: 4px; font-size: 12px;
      color: #334155; line-height: 1.3;
    }
    .defeito-icone {
      color: #d97706; font-size: 11px; flex-shrink: 0; margin-top: 1px;
    }
    .defeito-texto {
      font-weight: 500; white-space: normal; word-break: break-word;
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

  <!-- Sub-filtros para Status 00 e Status 01 (visível na aba Sem Triagem) -->
  <div class="sub-filtros" id="subFiltrosBar">
    <button class="sub-btn active" id="btnSubTodas" onclick="filtrarSubStatus('')">Todas (<?= count($semTriagem) ?>)</button>
    <button class="sub-btn" id="btnSub00" onclick="filtrarSubStatus('00')">Status 00 (<?= $qtdStatus00 ?>)</button>
    <button class="sub-btn" id="btnSub01" onclick="filtrarSubStatus('01')">Status 01 (<?= $qtdStatus01 ?>)</button>
  </div>

  <!-- Busca -->
  <div class="busca">
    <input type="text" id="campoBusca" placeholder="🔍 Buscar OS, status, cliente, defeito..." onkeyup="filtrar()">
  </div>

  <!-- Lista Compacta -->
  <div class="lista">

    <!-- Aba 1: Sem Triagem (Status 00 e 01) -->
    <div id="aba-sem-triagem" class="tab-conteudo active">
      <?php if (empty($semTriagem)): ?>
        <div class="vazio">Nenhum chamado sem triagem.</div>
      <?php else: ?>
        <?php foreach ($semTriagem as $c): ?>
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" data-status="<?= htmlspecialchars($c['status']) ?>" data-texto="<?= strtolower("{$c['os']} status {$c['status']} st{$c['status']} {$c['cliente']} {$c['cidade']} {$c['defeito']}") ?>">
            <!-- LINHA 1 -->
            <div class="linha-1">
              <div class="os-cli">
                <div class="os-topo-info">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
                  <span class="tag-status tag-status-<?= htmlspecialchars($c['status']) ?>">Status <?= htmlspecialchars($c['status']) ?></span>
                  <span class="cidade-nome">📍 <?= htmlspecialchars($c['cidade']) ?></span>
                </div>
                <div class="cliente-nome"><?= htmlspecialchars($c['cliente']) ?></div>
              </div>
              <div class="tags-status">
                <span class="tag-sla <?= (int)$c['sla'] < 0 ? 'sla-atraso' : 'sla-normal' ?>">
                  <?= $c['sla'] ?>h
                </span>
              </div>
            </div>
            <!-- LINHA 2 (O DEFEITO REAL DO DATABIT) -->
            <div class="linha-2">
              <span class="defeito-icone">🔧</span>
              <span class="defeito-texto"><?= htmlspecialchars($c['defeito']) ?></span>
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
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" data-status="<?= htmlspecialchars($c['status']) ?>" data-texto="<?= strtolower("{$c['os']} status {$c['status']} st{$c['status']} {$c['cliente']} {$c['cidade']} {$c['tecnico']} {$c['defeito']}") ?>">
            <!-- LINHA 1 -->
            <div class="linha-1">
              <div class="os-cli">
                <div class="os-topo-info">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
                  <span class="tag-status tag-status-<?= htmlspecialchars($c['status']) ?>">Status <?= htmlspecialchars($c['status']) ?></span>
                  <span class="cidade-nome">📍 <?= htmlspecialchars($c['cidade']) ?></span>
                </div>
                <div class="cliente-nome"><?= htmlspecialchars($c['cliente']) ?></div>
              </div>
              <div class="tags-status">
                <span class="tag-tecnico" style="background-color: <?= htmlspecialchars($c['corTecnico']) ?>">
                  <?= htmlspecialchars($c['tecnico']) ?>
                </span>
              </div>
            </div>
            <!-- LINHA 2 (O DEFEITO REAL DO DATABIT) -->
            <div class="linha-2">
              <span class="defeito-icone">🔧</span>
              <span class="defeito-texto"><?= htmlspecialchars($c['defeito']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <script>
    let subStatusAtivo = '';

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

    function filtrar() {
      const termo = document.getElementById('campoBusca').value.toLowerCase().trim();
      document.querySelectorAll('.item-os').forEach(el => {
        const texto = el.getAttribute('data-texto') || '';
        const st = el.getAttribute('data-status') || '';
        
        let bateTexto = !termo || texto.includes(termo);
        let bateSub = !subStatusAtivo || st === subStatusAtivo;

        el.style.display = (bateTexto && bateSub) ? 'flex' : 'none';
      });
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
