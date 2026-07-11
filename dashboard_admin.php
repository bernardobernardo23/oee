<?php
session_start();
require 'conexao.php';

// Segurança Rigorosa
// Acesso restrito a usuários corporativos com setor Admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['tipo_acesso'] ?? null) !== 'usuario' || $_SESSION['setor'] !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

// Configuração do Filtro de Mês e Ano (Para a secção 2 do Dashboard)
$mes_filtro = $_GET['mes'] ?? date('m');
$ano_filtro = $_GET['ano'] ?? date('Y');

$meses_nomes = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

try {
    // ========================================================================
    // BLOCO 1: DADOS GERAIS (HISTÓRICO DA EMPRESA TODA - IGNORA O FILTRO DE MÊS)
    // ========================================================================
    $stmt_oee = $pdo->query("SELECT AVG(oee_disponibilidade) as avg_disp, AVG(oee_performance) as avg_perf, AVG(oee_qualidade) as avg_qual, AVG(oee_geral) as avg_geral FROM apontamentos WHERE oee_geral > 0");
    $mediaOEE = $stmt_oee->fetch(PDO::FETCH_ASSOC);

    $stmt_totais = $pdo->query("SELECT SUM(producao_boas) as total_boas, SUM(producao_refugo) as total_refugo FROM apontamento_producao");
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
    $totalBoas = (int)$totais['total_boas'];
    $totalRefugo = (int)$totais['total_refugo'];
    $porcentagemPerda = (($totalBoas + $totalRefugo) > 0) ? ($totalRefugo / ($totalBoas + $totalRefugo)) * 100 : 0;

    // CÁLCULO CORRIGIDO: Usando Subqueries para evitar distorção matemática de apontamentos com múltiplos produtos
    $stmt_linhas = $pdo->query("
        SELECT 
            l.id as linha_id, 
            l.fabrica, 
            l.login as nome_linha,
            IFNULL(kpis.oee_geral, 0) as oee_geral, 
            IFNULL(kpis.oee_perf, 0) as oee_perf, 
            IFNULL(prods.total_produzido, 0) as total_produzido
        FROM linhas l
        LEFT JOIN (
            SELECT linha_id, AVG(oee_geral) as oee_geral, AVG(oee_performance) as oee_perf
            FROM apontamentos 
            WHERE oee_geral > 0
            GROUP BY linha_id
        ) kpis ON l.id = kpis.linha_id
        LEFT JOIN (
            SELECT a.linha_id, SUM(ap.producao_boas) as total_produzido
            FROM apontamentos a
            JOIN apontamento_producao ap ON a.id = ap.apontamento_id
            GROUP BY a.linha_id
        ) prods ON l.id = prods.linha_id
        WHERE l.fabrica > 0 
        ORDER BY l.fabrica ASC, l.login ASC
    ");
    $dados_linhas = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

    $labels_linhas = []; $data_oee_linhas = []; $data_perf_linhas = []; $cores_fabricas = [];
    foreach ($dados_linhas as $linha) {
        $labels_linhas[] = "F" . $linha['fabrica'] . " - " . strtoupper($linha['nome_linha']);
        $data_oee_linhas[] = round($linha['oee_geral'], 1);
        $data_perf_linhas[] = round($linha['oee_perf'], 1);
        if($linha['fabrica'] == 1) $cores_fabricas[] = 'rgba(59, 130, 246, 0.7)';
        if($linha['fabrica'] == 2) $cores_fabricas[] = 'rgba(16, 185, 129, 0.7)';
        if($linha['fabrica'] == 3) $cores_fabricas[] = 'rgba(245, 158, 11, 0.7)';
        if($linha['fabrica'] == 4) $cores_fabricas[] = 'rgba(139, 92, 246, 0.7)';
    }

    $stmt_produtos = $pdo->query("SELECT p.descricao, SUM(ap.producao_boas) as qtd_total FROM apontamento_producao ap JOIN produtos p ON ap.produto_id = p.id GROUP BY p.id ORDER BY qtd_total DESC LIMIT 10");
    $ranking_produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
    $labels_produtos = []; $data_produtos = [];
    foreach ($ranking_produtos as $prod) {
        $labels_produtos[] = mb_strimwidth($prod['descricao'], 0, 35, '...');
        $data_produtos[] = (int)$prod['qtd_total'];
    }

    // ========================================================================
    // BLOCO 2: DADOS MENSAIS (FILTRADOS PELO MÊS/ANO SELECIONADO)
    // ========================================================================
    $stmt_kpi = $pdo->prepare("SELECT IFNULL(AVG(oee_disponibilidade), 0) as disp, IFNULL(AVG(oee_performance), 0) as perf, IFNULL(AVG(oee_qualidade), 0) as qual, IFNULL(AVG(oee_geral), 0) as oee FROM apontamentos WHERE MONTH(data_registro) = ? AND YEAR(data_registro) = ? AND oee_geral > 0");
    $stmt_kpi->execute([$mes_filtro, $ano_filtro]);
    $kpis = $stmt_kpi->fetch(PDO::FETCH_ASSOC);

    $stmt_prod = $pdo->prepare("SELECT DAY(a.data_registro) as dia, SUM(ap.producao_boas) as producao_dia FROM apontamentos a JOIN apontamento_producao ap ON a.id = ap.apontamento_id WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? GROUP BY DAY(a.data_registro) ORDER BY dia ASC");
    $stmt_prod->execute([$mes_filtro, $ano_filtro]);
    $dados_producao = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

    $labels_prod = []; $data_prod_dia = []; $data_prod_acumulada = []; $acumulado = 0;
    foreach ($dados_producao as $row) {
        $labels_prod[] = "Dia " . str_pad($row['dia'], 2, '0', STR_PAD_LEFT);
        $data_prod_dia[] = (int)$row['producao_dia'];
        $acumulado += (int)$row['producao_dia'];
        $data_prod_acumulada[] = $acumulado;
    }

    $stmt_parada_linha = $pdo->prepare("SELECT l.id as linha_id, l.login as linha, SUM(ap.minutos_parados) as total_minutos FROM apontamento_paradas ap JOIN apontamentos a ON ap.apontamento_id = a.id JOIN linhas l ON a.linha_id = l.id WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? GROUP BY l.id, l.login ORDER BY total_minutos DESC");
    $stmt_parada_linha->execute([$mes_filtro, $ano_filtro]);
    $dados_parada_linha = $stmt_parada_linha->fetchAll(PDO::FETCH_ASSOC);
    
    $labels_parada_linha = []; $data_parada_linha = []; $ids_parada_linha = [];
    foreach ($dados_parada_linha as $row) {
        $labels_parada_linha[] = strtoupper($row['linha']);
        $data_parada_linha[] = (int)$row['total_minutos'];
        $ids_parada_linha[] = $row['linha_id']; 
    }

    $stmt_pareto = $pdo->prepare("SELECT m.descricao as motivo, SUM(ap.minutos_parados) as total_minutos FROM apontamento_paradas ap JOIN apontamentos a ON ap.apontamento_id = a.id JOIN motivos_parada m ON ap.motivo_id = m.id WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? GROUP BY m.descricao ORDER BY total_minutos DESC");
    $stmt_pareto->execute([$mes_filtro, $ano_filtro]);
    $dados_pareto_bruto = $stmt_pareto->fetchAll(PDO::FETCH_ASSOC);

    $total_minutos_geral = array_sum(array_column($dados_pareto_bruto, 'total_minutos'));
    $labels_pareto = []; $data_pareto_minutos = []; $data_pareto_porcentagem = []; $acumulado_pareto = 0;
    foreach ($dados_pareto_bruto as $row) {
        $labels_pareto[] = $row['motivo'];
        $data_pareto_minutos[] = (int)$row['total_minutos'];
        $acumulado_pareto += (int)$row['total_minutos'];
        $porcentagem = $total_minutos_geral > 0 ? ($acumulado_pareto / $total_minutos_geral) * 100 : 0;
        $data_pareto_porcentagem[] = round($porcentagem, 1);
    }

    $stmt_matriz = $pdo->prepare("SELECT l.login as linha, m.descricao as motivo, m.tipo, m.responsabilidade, SUM(ap.minutos_parados) as total_minutos FROM apontamento_paradas ap JOIN apontamentos a ON ap.apontamento_id = a.id JOIN linhas l ON a.linha_id = l.id JOIN motivos_parada m ON ap.motivo_id = m.id WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? GROUP BY l.login, m.descricao, m.tipo, m.responsabilidade ORDER BY l.login ASC, total_minutos DESC");
    $stmt_matriz->execute([$mes_filtro, $ano_filtro]);
    $matriz_ofensores = $stmt_matriz->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao gerar relatórios: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Gerencial - MES/OEE</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'], } } } }
    </script>
</head>
<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <!-- CABEÇALHO IMPORTADO -->
    <?php include 'header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 space-y-12 mt-8">
        
        <div>
            <h2 class="text-lg font-black text-gray-800 mb-4 border-b border-gray-300 pb-2">Panorama Operacional Global (Acumulado Histórico)</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow border border-gray-200 p-6 border-b-4 border-blue-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase mb-1">OEE Global da Planta</h3>
                    <div class="text-4xl font-black text-gray-800"><?= number_format($mediaOEE['avg_geral'] ?? 0, 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-white rounded-xl shadow border border-gray-200 p-6 border-b-4 border-green-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase mb-1">Total Produzido</h3>
                    <div class="text-4xl font-black text-green-600"><?= number_format($totalBoas, 0, ',', '.') ?></div>
                </div>
                <div class="bg-white rounded-xl shadow border border-gray-200 p-6 border-b-4 border-red-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase mb-1">Total Refugado</h3>
                    <div class="text-4xl font-black text-red-600"><?= number_format($totalRefugo, 0, ',', '.') ?></div>
                </div>
                <div class="bg-white rounded-xl shadow border border-gray-200 p-6 border-b-4 border-purple-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase mb-1">Linhas Mapeadas</h3>
                    <div class="text-4xl font-black text-purple-600"><?= count($dados_linhas) ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">OEE Geral e Performance por Linha</h3>
                    <div class="relative h-80 w-full"><canvas id="graficoOEE"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Top 10 Produtos Fabricados (Volumetria)</h3>
                    <div class="relative h-80 w-full"><canvas id="graficoProdutos"></canvas></div>
                </div>
            </div>
        </div>

        <div class="bg-gray-200 p-6 rounded-2xl shadow-inner border border-gray-300">
            
            <div class="flex flex-col md:flex-row justify-between items-center mb-6 pb-4 border-b border-gray-300">
                <div>
                    <h2 class="text-xl font-black text-gray-800">Recorte Mensal de Performance</h2>
                    <p class="text-sm text-gray-600">Selecione o período abaixo para recalcular os KPIs, Ofensores e Gráficos.</p>
                </div>
                <form method="GET" class="flex gap-2 w-full md:w-auto mt-4 md:mt-0">
                    <select name="mes" class="px-3 py-2 border border-gray-300 rounded font-semibold text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <?php foreach ($meses_nomes as $num => $nome): ?>
                            <option value="<?= $num ?>" <?= $mes_filtro == $num ? 'selected' : '' ?>><?= $nome ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ano" class="px-3 py-2 border border-gray-300 rounded font-semibold text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <?php for($i = date('Y'); $i >= 2024; $i--): ?>
                            <option value="<?= $i ?>" <?= $ano_filtro == $i ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded shadow transition">Filtrar Período</button>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 border-l-4 border-blue-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Disponibilidade (Mês)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['disp'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 border-l-4 border-orange-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Performance (Mês)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['perf'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 border-l-4 border-green-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Qualidade (Mês)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['qual'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-gray-900 rounded-xl shadow-sm border border-gray-800 p-5">
                    <h3 class="text-gray-400 text-xs font-bold uppercase mb-1">OEE Global (Mês)</h3>
                    <div class="text-4xl font-black text-yellow-400"><?= number_format($kpis['oee'], 1, ',', '.') ?>%</div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow border border-gray-200 mb-8">
                <h2 class="text-lg font-black text-gray-800 mb-4 border-b border-gray-200 pb-2">Produção Diária x Acumulado</h2>
                <div class="relative h-80 w-full"><canvas id="graficoProducao"></canvas></div>
            </div>

            <h2 class="text-lg font-black text-gray-800 mb-4 border-b border-gray-300 pb-2">Gestão de Ofensores e Paradas</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                    <h3 class="text-sm font-bold text-gray-600 mb-2">Minutos Parados por Linha</h3>
                    <p class="text-xs text-blue-500 font-semibold mb-4 italic">(Clique numa barra para ver o relatório da máquina)</p>
                    <div class="relative h-72 w-full"><canvas id="graficoParadasLinha"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                    <h3 class="text-sm font-bold text-gray-600 mb-4">Gráfico de Pareto (Causa x Frequência)</h3>
                    <div class="relative h-72 w-full"><canvas id="graficoPareto"></canvas></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                <div class="p-4 border-b bg-gray-50"><h3 class="font-bold text-gray-800">Detalhamento Operacional (Clique na linha para Raio-X)</h3></div>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 bg-black text-white text-xs uppercase tracking-wider">
                            <tr>
                                <th class="p-3 font-bold">Fábrica</th>
                                <th class="p-3 font-bold">Linha</th>
                                <th class="p-3 font-bold text-center">Produção</th>
                                <th class="p-3 font-bold text-center">OEE</th>
                                <th class="p-3 font-bold text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-200">
                            <?php foreach ($dados_linhas as $linha): ?>
                                <tr class="hover:bg-blue-50 cursor-pointer transition" onclick="window.location.href='relatorio_linha.php?id=<?= $linha['linha_id'] ?>&mes=<?= $mes_filtro ?>&ano=<?= $ano_filtro ?>'">
                                    <td class="p-3 font-black text-gray-600">F<?= $linha['fabrica'] ?></td>
                                    <td class="p-3 font-bold text-blue-600 uppercase underline decoration-blue-300"><?= htmlspecialchars($linha['nome_linha']) ?></td>
                                    
                                    <td class="p-3 text-center font-bold text-gray-700"><?= number_format($linha['total_produzido'], 0, ',', '.') ?> un</td>

                                    <td class="p-3 text-center font-black <?= $linha['oee_geral'] >= 85 ? 'text-green-600' : ($linha['oee_geral'] >= 60 ? 'text-yellow-600' : 'text-red-600') ?>"><?= number_format($linha['oee_geral'], 1, ',', '.') ?>%</td>
                                    <td class="p-3 text-center">
                                        <?php if($linha['oee_geral'] >= 85): ?> <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-bold">EXCELENTE</span>
                                        <?php elseif($linha['oee_geral'] >= 60): ?> <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs font-bold">ATENÇÃO</span>
                                        <?php elseif($linha['oee_geral'] > 0): ?> <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-bold">CRÍTICO</span>
                                        <?php else: ?> <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded text-xs font-bold">S/ DADOS OEE</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                <div class="p-4 border-b bg-gray-50 flex justify-between items-center"><h3 class="font-bold text-gray-800">Matriz de Ofensores: Linha x Motivo x Minutos</h3></div>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 bg-white shadow-sm z-10 border-b">
                            <tr class="text-xs uppercase tracking-wider text-gray-500"><th class="p-3 font-bold">Linha</th><th class="p-3 font-bold">Motivo</th><th class="p-3 font-bold text-right">Perdido</th></tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <?php foreach ($matriz_ofensores as $row): ?>
                                <tr class="hover:bg-red-50">
                                    <td class="p-3 font-black text-blue-600 uppercase"><?= htmlspecialchars($row['linha']) ?></td>
                                    <td class="p-3 font-bold text-gray-700 text-xs"><?= htmlspecialchars($row['motivo']) ?></td>
                                    <td class="p-3 text-right font-black text-red-600"><?= number_format($row['total_minutos'], 0, ',', '.') ?>m</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Graficos Gerais (Mundo)
        new Chart(document.getElementById('graficoOEE').getContext('2d'), { type: 'bar', data: { labels: <?= json_encode($labels_linhas) ?>, datasets: [ { label: 'OEE (%)', data: <?= json_encode($data_oee_linhas) ?>, backgroundColor: <?= json_encode($cores_fabricas) ?>, borderRadius: 4, order: 2 }, { label: 'Meta Mundial (85%)', data: Array(<?= count($labels_linhas) ?>).fill(85), type: 'line', borderColor: 'rgba(239, 68, 68, 0.8)', borderWidth: 2, borderDash: [5, 5], fill: false, pointRadius: 0, order: 1 } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } } });
        new Chart(document.getElementById('graficoProdutos').getContext('2d'), { type: 'doughnut', data: { labels: <?= json_encode($labels_produtos) ?>, datasets: [{ data: <?= json_encode($data_produtos) ?>, backgroundColor: ['#1f2937', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#64748b'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } } });

        // Graficos Filtrados (Mês)
        new Chart(document.getElementById('graficoProducao').getContext('2d'), { type: 'bar', data: { labels: <?= json_encode($labels_prod) ?>, datasets: [ { type: 'line', label: 'Acumulado Mês', data: <?= json_encode($data_prod_acumulada) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', borderWidth: 3, fill: true, tension: 0.3, yAxisID: 'yAcumulado' }, { type: 'bar', label: 'Produção Diária', data: <?= json_encode($data_prod_dia) ?>, backgroundColor: '#3b82f6', borderRadius: 4, yAxisID: 'yDiario' } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { yDiario: { type: 'linear', position: 'left'}, yAcumulado: { type: 'linear', position: 'right', grid: {drawOnChartArea: false} } } } });
        
        new Chart(document.getElementById('graficoPareto').getContext('2d'), { type: 'bar', data: { labels: <?= json_encode($labels_pareto) ?>, datasets: [ { type: 'line', label: '% Acumulada', data: <?= json_encode($data_pareto_porcentagem) ?>, borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 3, yAxisID: 'yPorcentagem' }, { type: 'bar', label: 'Minutos Perdidos', data: <?= json_encode($data_pareto_minutos) ?>, backgroundColor: '#475569', borderRadius: 2, yAxisID: 'yMinutos' } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { yMinutos: { type: 'linear', position: 'left' }, yPorcentagem: { type: 'linear', position: 'right', max: 100, grid: {drawOnChartArea: false} } } } });

        // Grafico de Paradas (COM REDIRECIONAMENTO CLICÁVEL)
        const idsParadasLinha = <?= json_encode($ids_parada_linha) ?>;
        const mesSelecionado = '<?= $mes_filtro ?>';
        const anoSelecionado = '<?= $ano_filtro ?>';

        new Chart(document.getElementById('graficoParadasLinha').getContext('2d'), {
            type: 'bar',
            data: { labels: <?= json_encode($labels_parada_linha) ?>, datasets: [{ label: 'Minutos Parados', data: <?= json_encode($data_parada_linha) ?>, backgroundColor: '#ef4444', borderRadius: 4, hoverBackgroundColor: '#b91c1c' }] },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const linhaId = idsParadasLinha[index];
                        window.location.href = `relatorio_linha.php?id=${linhaId}&mes=${mesSelecionado}&ano=${anoSelecionado}`;
                    }
                },
                onHover: (event, chartElement) => {
                    event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                }
            }
        });
    </script>
</body>
</html>