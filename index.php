<?php
/**
 * Mil Cópias - Painel de Chamados Ultra-Compacto para Celular
 * Layout de 1 a 2 linhas por OS - Sem cortar nome do cliente e sem '#'
 */

// 1. Configurações da API Databit DataSnap
$databit_host = '192.168.1.206';
$databit_porta = '8099';
$databit_user = 'ADMIN';
$databit_pass = 'masterkey';

$cham1_url = 'http://databitbh.com:51202/mc/cham1.php';

// 2. Baixar dados ao vivo do cham1.php
$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'header' => "User-Agent: Mozilla/5.0 (Mobile)\r\n"
    ]
]);

$html = @file_get_contents($cham1_url, false, $ctx);

$semTriagem = [];
$comTriagem = [];

if ($html) {
    // 3. Interpretar "Sem Triagem"
    if (preg_match('/<h5 class="card-header">Sem triagem[\s\S]*?<table[\s\S]*?<\/thead>([\s\S]*?)<\/table>/i', $html, $m1)) {
        preg_match_all('/<tr[\s\S]*?<\/tr>/i', $m1[1], $rows);
        foreach ($rows[0] as $row) {
            preg_match_all('/<td[\s\S]*?>([\s\S]*?)<\/td>/i', $row, $tds);
            $cols = array_map(function($c) { return trim(strip_tags($c)); }, $tds[1]);
            if (count($cols) >= 7) {
                $isAtrasado = (strpos($row, '#FF7F50') !== false || strpos($row, 'spinner-grow') !== false);
                $semTriagem[] = [
                    'os' => $cols[0],
                    'cliente' => $cols[1],
                    'local' => $cols[2],
                    'cidade' => $cols[3],
                    'origem' => $cols[4],
                    'data' => $cols[5],
                    'sla' => $cols[6],
                    'atrasado' => $isAtrasado,
                    'defeito' => buscarDefeito($cols[0], $databit_host, $databit_porta, $databit_user, $databit_pass)
                ];
            }
        }
    }

    // 4. Interpretar "Com Triagem"
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
                $comTriagem[] = [
                    'os' => $cols[0],
                    'cliente' => $cols[1],
                    'local' => $cols[2],
                    'cidade' => $cols[3],
                    'encaminhado' => $cols[4],
                    'tecnico' => $cols[5],
                    'corTecnico' => $corTecnico,
                    'atrasado' => $isAtrasado,
                    'defeito' => buscarDefeito($cols[0], $databit_host, $databit_porta, $databit_user, $databit_pass)
                ];
            }
        }
    }
}

function buscarDefeito($os, $host, $port, $user, $pass) {
    static $cache = [];
    if (isset($cache[$os])) return $cache[$os];

    $url = "http://{$host}:{$port}/datasnap/rest/OsController/listar";
    $payload = json_encode(['filtro' => "codigo = '{$os}' or codigo = '" . ltrim($os, '0') . "'"]);

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" .
                        "Authorization: Basic " . base64_encode("{$user}:{$pass}") . "\r\n",
            'content' => $payload,
            'timeout' => 1
        ]
    ];

    $resp = @file_get_contents($url, false, stream_context_create($opts));
    if ($resp) {
        $json = json_decode($resp, true);
        $dados = isset($json['value']) ? $json['value'] : $json;
        if (is_array($dados) && !empty($dados[0])) {
            $osData = $dados[0];
            // Prioriza defeito / motivo
            $defeitoEncontrado = !empty($osData['defeitoDesc']) ? $osData['defeitoDesc'] :
                                 (!empty($osData['motivo']) ? $osData['motivo'] :
                                 (!empty($osData['obs']) ? $osData['obs'] : ''));
            if ($defeitoEncontrado) {
                $cache[$os] = trim($defeitoEncontrado);
                return $cache[$os];
            }
        }
    }

    $cache[$os] = 'Aguardando sincronização com Databit';
    return $cache[$os];
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

    /* Abas */
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

    /* Linha 1: OS + Nome Completo do Cliente + Cidade + SLA/Técnico */
    .linha-1 {
      display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;
    }
    .os-cli {
      display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0;
    }
    .os-topo-info {
      display: flex; align-items: center; gap: 6px;
    }
    .os-num {
      font-family: monospace; font-weight: 800; font-size: 13.5px; color: #0284c7;
    }
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

    /* Linha 2: O Defeito */
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
    <button class="tab-btn active" onclick="trocarAba('sem-triagem')">
      Sem Triagem <span class="badge-count badge-laranja"><?= count($semTriagem) ?></span>
    </button>
    <button class="tab-btn" onclick="trocarAba('com-triagem')">
      Com Triagem <span class="badge-count badge-azul"><?= count($comTriagem) ?></span>
    </button>
  </div>

  <!-- Busca -->
  <div class="busca">
    <input type="text" id="campoBusca" placeholder="🔍 Buscar OS, cliente, defeito, técnico..." onkeyup="filtrar()">
  </div>

  <!-- Lista Compacta -->
  <div class="lista">

    <!-- Aba 1: Sem Triagem -->
    <div id="aba-sem-triagem" class="tab-conteudo active">
      <?php if (empty($semTriagem)): ?>
        <div class="vazio">Nenhum chamado sem triagem.</div>
      <?php else: ?>
        <?php foreach ($semTriagem as $c): ?>
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" data-texto="<?= strtolower("{$c['os']} {$c['cliente']} {$c['cidade']} {$c['defeito']}") ?>">
            <!-- LINHA 1 -->
            <div class="linha-1">
              <div class="os-cli">
                <div class="os-topo-info">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
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
            <!-- LINHA 2 (O DEFEITO) -->
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
          <div class="item-os <?= $c['atrasado'] ? 'atrasado' : '' ?>" data-texto="<?= strtolower("{$c['os']} {$c['cliente']} {$c['cidade']} {$c['tecnico']} {$c['defeito']}") ?>">
            <!-- LINHA 1 -->
            <div class="linha-1">
              <div class="os-cli">
                <div class="os-topo-info">
                  <span class="os-num"><?= htmlspecialchars($c['os']) ?></span>
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
            <!-- LINHA 2 (O DEFEITO) -->
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
    function trocarAba(aba) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-conteudo').forEach(c => c.classList.remove('active'));
      if (aba === 'sem-triagem') {
        document.querySelectorAll('.tab-btn')[0].classList.add('active');
        document.getElementById('aba-sem-triagem').classList.add('active');
      } else {
        document.querySelectorAll('.tab-btn')[1].classList.add('active');
        document.getElementById('aba-com-triagem').classList.add('active');
      }
    }

    function filtrar() {
      const termo = document.getElementById('campoBusca').value.toLowerCase().trim();
      document.querySelectorAll('.item-os').forEach(el => {
        const texto = el.getAttribute('data-texto') || '';
        el.style.display = texto.includes(termo) ? 'flex' : 'none';
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
