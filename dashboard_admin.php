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
    '01' => 'Janeiro',
    '02' => 'Fevereiro',
    '03' => 'Março',
    '04' => 'Abril',
    '05' => 'Maio',
    '06' => 'Junho',
    '07' => 'Julho',
    '08' => 'Agosto',
    '09' => 'Setembro',
    '10' => 'Outubro',
    '11' => 'Novembro',
    '12' => 'Dezembro'
];

// 1. Busca todas as fábricas ativas no sistema para criar os botões dinamicamente
$stmt_fabricas = $pdo->query("SELECT DISTINCT fabrica FROM linhas WHERE fabrica > 0 ORDER BY fabrica ASC");
$lista_fabricas = $stmt_fabricas->fetchAll(PDO::FETCH_COLUMN);

// 1b. Busca TODAS as linhas (id, login, fabrica) -- usado pro drill-down
// de fábrica -> linha no seletor. Só uma query, filtragem por fábrica é
// feita em PHP na hora de renderizar (dataset pequeno).
$todas_linhas_seletor = $pdo->query("SELECT id, login, fabrica FROM linhas WHERE fabrica > 0 ORDER BY fabrica ASC, login ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Captura os filtros da URL. `linha` é mais específico que `fabrica` --
// quando presente, filtra só aquela linha; senão, filtra pela fábrica
// inteira; senão, mostra tudo (Visão Global).
$fabrica_filtro = isset($_GET['fabrica']) && is_numeric($_GET['fabrica']) ? (int)$_GET['fabrica'] : null;
$linha_filtro = isset($_GET['linha']) && is_numeric($_GET['linha']) ? (int)$_GET['linha'] : null;

// Nome da linha selecionada (só pra exibir no título/breadcrumb, se houver)
$nome_linha_filtro = null;
if ($linha_filtro) {
    foreach ($todas_linhas_seletor as $l) {
        if ($l['id'] == $linha_filtro) {
            $nome_linha_filtro = $l['login'];
            break;
        }
    }
}

// 3. Monta a "peça de Lego" do SQL que será injetada em TODAS as queries.
// Uma linha específica tem prioridade sobre o filtro de fábrica.
$sql_fabrica = "";
$param_fabrica = [];
if ($linha_filtro) {
    $sql_fabrica = " AND l.id = ? ";
    $param_fabrica[] = $linha_filtro;
} elseif (!empty($fabrica_filtro)) {
    $sql_fabrica = " AND l.fabrica = ? ";
    $param_fabrica[] = $fabrica_filtro;
}

// 3b. Escopo de período do "Panorama Operacional Global" (bloco 1) --
// antes era sempre acumulado histórico fixo; agora dá pra escolher:
// total | o mesmo mês/ano do filtro mensal | um período personalizado.
$escopo_geral = $_GET['escopo_geral'] ?? 'total';
if (!in_array($escopo_geral, ['total', 'mes', 'personalizado'], true)) {
    $escopo_geral = 'total';
}
$data_inicio_geral = $_GET['data_inicio'] ?? '';
$data_fim_geral = $_GET['data_fim'] ?? '';

// Período personalizado só é válido se as duas datas vierem preenchidas
// e em ordem certa -- senão, cai de volta pro comportamento "total"
// silenciosamente (evita erro de SQL com datas inválidas).
if ($escopo_geral === 'personalizado' && (!$data_inicio_geral || !$data_fim_geral || $data_inicio_geral > $data_fim_geral)) {
    $escopo_geral = 'total';
}

$sql_periodo_geral = "";
$param_periodo_geral = [];
if ($escopo_geral === 'mes') {
    $sql_periodo_geral = " AND MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? ";
    $param_periodo_geral = [$mes_filtro, $ano_filtro];
} elseif ($escopo_geral === 'personalizado') {
    $sql_periodo_geral = " AND a.data_registro BETWEEN ? AND ? ";
    $param_periodo_geral = [$data_inicio_geral, $data_fim_geral];
}

// Texto amigável do escopo atual, pro título da seção
if ($escopo_geral === 'mes') {
    $label_escopo_geral = $meses_nomes[$mes_filtro] . '/' . $ano_filtro;
} elseif ($escopo_geral === 'personalizado') {
    $label_escopo_geral = date('d/m/Y', strtotime($data_inicio_geral)) . ' a ' . date('d/m/Y', strtotime($data_fim_geral));
} else {
    $label_escopo_geral = 'Acumulado Histórico';
}

// Helper pra montar os links do seletor sempre preservando mês/ano
// filtrados -- sem isso, trocar de fábrica/linha resetava o período
// escolhido de volta pro mês atual.
function url_dashboard_admin($extras, $mes_filtro, $ano_filtro)
{
    $base = ['mes' => $mes_filtro, 'ano' => $ano_filtro];
    return '?' . http_build_query(array_merge($base, $extras));
}

try {
    // ========================================================================
    // BLOCO 1: DADOS GERAIS (HISTÓRICO, MÊS OU PERÍODO -- FÁBRICA OU LINHA)
    // ========================================================================

    // 1. OEE Geral
    $stmt_oee = $pdo->prepare("
        SELECT AVG(a.oee_disponibilidade) as avg_disp, AVG(a.oee_performance) as avg_perf, AVG(a.oee_qualidade) as avg_qual, AVG(a.oee_geral) as avg_geral 
        FROM apontamentos a 
        JOIN linhas l ON a.linha_id = l.id 
        WHERE a.oee_geral > 0 {$sql_fabrica} {$sql_periodo_geral}
    ");
    $stmt_oee->execute(array_merge($param_fabrica, $param_periodo_geral));
    $mediaOEE = $stmt_oee->fetch(PDO::FETCH_ASSOC);

    // 2. Totais de Produção
    $stmt_totais = $pdo->prepare("
        SELECT SUM(ap.producao_boas) as total_boas, SUM(ap.producao_refugo) as total_refugo 
        FROM apontamento_producao ap 
        JOIN apontamentos a ON ap.apontamento_id = a.id 
        JOIN linhas l ON a.linha_id = l.id 
        WHERE 1=1 {$sql_fabrica} {$sql_periodo_geral}
    ");
    $stmt_totais->execute(array_merge($param_fabrica, $param_periodo_geral));
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
    $totalBoas = (int)$totais['total_boas'];
    $totalRefugo = (int)$totais['total_refugo'];
    $porcentagemPerda = (($totalBoas + $totalRefugo) > 0) ? ($totalRefugo / ($totalBoas + $totalRefugo)) * 100 : 0;

    // 3. OEE e Produção por Linha -- o filtro de período entra DENTRO de
    // cada subconsulta (kpis e prods), porque cada uma tem seu próprio
    // alias `a` de apontamentos. A ordem dos parâmetros segue a ordem em
    // que os `?` aparecem no texto final: kpis primeiro, prods depois,
    // fábrica/linha por último (é onde ficam no SQL).
    $stmt_linhas = $pdo->prepare("
        SELECT 
            l.id as linha_id, 
            l.fabrica, 
            l.login as nome_linha,
            IFNULL(kpis.oee_geral, 0) as oee_geral, 
            IFNULL(kpis.oee_perf, 0) as oee_perf, 
            IFNULL(prods.total_produzido, 0) as total_produzido
        FROM linhas l
        LEFT JOIN (
            SELECT a.linha_id, AVG(a.oee_geral) as oee_geral, AVG(a.oee_performance) as oee_perf
            FROM apontamentos a
            WHERE a.oee_geral > 0 {$sql_periodo_geral}
            GROUP BY a.linha_id
        ) kpis ON l.id = kpis.linha_id
        LEFT JOIN (
            SELECT a.linha_id, SUM(ap.producao_boas) as total_produzido
            FROM apontamentos a
            JOIN apontamento_producao ap ON a.id = ap.apontamento_id
            WHERE 1=1 {$sql_periodo_geral}
            GROUP BY a.linha_id
        ) prods ON l.id = prods.linha_id
        WHERE l.fabrica > 0 {$sql_fabrica}
        ORDER BY l.fabrica ASC, l.login ASC
    ");
    $stmt_linhas->execute(array_merge($param_periodo_geral, $param_periodo_geral, $param_fabrica));
    $dados_linhas = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

    $labels_linhas = [];
    $data_oee_linhas = []; // Pode manter se usar noutro lugar
    $data_perf_linhas = []; // Pode manter se usar noutro lugar
    $cores_fabricas = [];
    $data_prod_linhas = []; // ADICIONE ESTA LINHA

    foreach ($dados_linhas as $linha) {
        $labels_linhas[] = "F" . $linha['fabrica'] . " - " . strtoupper($linha['nome_linha']);
        $data_oee_linhas[] = round($linha['oee_geral'], 1);
        $data_perf_linhas[] = round($linha['oee_perf'], 1);

        // ADICIONE ESTA LINHA PARA PEGAR O VOLUME BRUTO:
        $data_prod_linhas[] = (int)$linha['total_produzido'];

        if ($linha['fabrica'] == 1) $cores_fabricas[] = 'rgba(59, 130, 246, 0.7)';
        if ($linha['fabrica'] == 2) $cores_fabricas[] = 'rgba(16, 185, 129, 0.7)';
        if ($linha['fabrica'] == 3) $cores_fabricas[] = 'rgba(245, 158, 11, 0.7)';
        if ($linha['fabrica'] == 4) $cores_fabricas[] = 'rgba(139, 92, 246, 0.7)';
    }

    // 4. Ranking de Produtos (Busca TODOS para a lista, corta 10 para o gráfico)
    $stmt_produtos = $pdo->prepare("
        SELECT p.codigo, p.descricao, SUM(ap.producao_boas) as qtd_total 
        FROM apontamento_producao ap 
        JOIN produtos p ON ap.produto_id = p.id 
        JOIN apontamentos a ON ap.apontamento_id = a.id
        JOIN linhas l ON a.linha_id = l.id
        WHERE 1=1 {$sql_fabrica} {$sql_periodo_geral}
        GROUP BY p.id 
        ORDER BY qtd_total DESC
    ");
    $stmt_produtos->execute(array_merge($param_fabrica, $param_periodo_geral));
    $todos_produtos_ranking = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

    // Pega só os 10 primeiros para alimentar o Gráfico
    $ranking_produtos = array_slice($todos_produtos_ranking, 0, 10);

    $labels_produtos = [];
    $data_produtos = [];
    foreach ($ranking_produtos as $prod) {
        $labels_produtos[] = mb_strimwidth($prod['descricao'], 0, 35, '...');
        $data_produtos[] = (int)$prod['qtd_total'];
    }

    // ========================================================================
    // BLOCO 2: DADOS MENSAIS (FILTRADOS POR MÊS, ANO E FÁBRICA/LINHA)
    // ========================================================================

    // Mescla os parâmetros de mês/ano com o filtro de fábrica/linha, se existir
    $params_mensal = [$mes_filtro, $ano_filtro];
    if (!empty($param_fabrica)) {
        $params_mensal = array_merge($params_mensal, $param_fabrica);
    }

    // 5. KPIs Mensais
    $stmt_kpi = $pdo->prepare("
        SELECT IFNULL(AVG(a.oee_disponibilidade), 0) as disp, IFNULL(AVG(a.oee_performance), 0) as perf, IFNULL(AVG(a.oee_qualidade), 0) as qual, IFNULL(AVG(a.oee_geral), 0) as oee 
        FROM apontamentos a 
        JOIN linhas l ON a.linha_id = l.id
        WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? AND a.oee_geral > 0 {$sql_fabrica}
    ");
    $stmt_kpi->execute($params_mensal);
    $kpis = $stmt_kpi->fetch(PDO::FETCH_ASSOC);

    // 6. Produção Diária Mensal
    $stmt_prod = $pdo->prepare("
        SELECT DAY(a.data_registro) as dia, SUM(ap.producao_boas) as producao_dia 
        FROM apontamentos a 
        JOIN apontamento_producao ap ON a.id = ap.apontamento_id 
        JOIN linhas l ON a.linha_id = l.id
        WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? {$sql_fabrica}
        GROUP BY DAY(a.data_registro) ORDER BY dia ASC
    ");
    $stmt_prod->execute($params_mensal);
    $dados_producao = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

    $labels_prod = [];
    $data_prod_dia = [];
    $data_prod_acumulada = [];
    $acumulado = 0;
    foreach ($dados_producao as $row) {
        $labels_prod[] = "Dia " . str_pad($row['dia'], 2, '0', STR_PAD_LEFT);
        $data_prod_dia[] = (int)$row['producao_dia'];
        $acumulado += (int)$row['producao_dia'];
        $data_prod_acumulada[] = $acumulado;
    }

    // 7. Paradas por Linha
    $stmt_parada_linha = $pdo->prepare("
        SELECT l.id as linha_id, l.login as linha, SUM(ap.minutos_parados) as total_minutos 
        FROM apontamento_paradas ap 
        JOIN apontamentos a ON ap.apontamento_id = a.id 
        JOIN linhas l ON a.linha_id = l.id 
        WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? {$sql_fabrica}
        GROUP BY l.id, l.login ORDER BY total_minutos DESC
    ");
    $stmt_parada_linha->execute($params_mensal);
    $dados_parada_linha = $stmt_parada_linha->fetchAll(PDO::FETCH_ASSOC);

    $labels_parada_linha = [];
    $data_parada_linha = [];
    $ids_parada_linha = [];
    foreach ($dados_parada_linha as $row) {
        $labels_parada_linha[] = strtoupper($row['linha']);
        $data_parada_linha[] = (int)$row['total_minutos'];
        $ids_parada_linha[] = $row['linha_id'];
    }

    // 8. Pareto de Motivos de Parada
    $stmt_pareto = $pdo->prepare("
        SELECT m.descricao as motivo, SUM(ap.minutos_parados) as total_minutos 
        FROM apontamento_paradas ap 
        JOIN apontamentos a ON ap.apontamento_id = a.id 
        JOIN motivos_parada m ON ap.motivo_id = m.id 
        JOIN linhas l ON a.linha_id = l.id
        WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? {$sql_fabrica}
        GROUP BY m.descricao ORDER BY total_minutos DESC
    ");
    $stmt_pareto->execute($params_mensal);
    $dados_pareto_bruto = $stmt_pareto->fetchAll(PDO::FETCH_ASSOC);

    $total_minutos_geral = array_sum(array_column($dados_pareto_bruto, 'total_minutos'));
    $labels_pareto = [];
    $data_pareto_minutos = [];
    $data_pareto_porcentagem = [];
    $acumulado_pareto = 0;
    foreach ($dados_pareto_bruto as $row) {
        $labels_pareto[] = $row['motivo'];
        $data_pareto_minutos[] = (int)$row['total_minutos'];
        $acumulado_pareto += (int)$row['total_minutos'];
        $porcentagem = $total_minutos_geral > 0 ? ($acumulado_pareto / $total_minutos_geral) * 100 : 0;
        $data_pareto_porcentagem[] = round($porcentagem, 1);
    }

    // 9. Matriz de Ofensores
    $stmt_matriz = $pdo->prepare("
        SELECT l.login as linha, m.descricao as motivo, m.tipo, m.responsabilidade, SUM(ap.minutos_parados) as total_minutos 
        FROM apontamento_paradas ap 
        JOIN apontamentos a ON ap.apontamento_id = a.id 
        JOIN linhas l ON a.linha_id = l.id 
        JOIN motivos_parada m ON ap.motivo_id = m.id 
        WHERE MONTH(a.data_registro) = ? AND YEAR(a.data_registro) = ? {$sql_fabrica}
        GROUP BY l.login, m.descricao, m.tipo, m.responsabilidade 
        ORDER BY l.login ASC, total_minutos DESC
    ");
    $stmt_matriz->execute($params_mensal);
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
        <link rel="icon" type="image/png" href="logo.png">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <!-- CABEÇALHO IMPORTADO -->
    <?php include 'header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 mt-6">
        <!-- ACESSO RÁPIDO AOS MÓDULOS -->
        <div class="mb-6">
            <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Acesso Rápido</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

                <!-- Botão PCP -->
                <a href="programacao_pcp.php" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-400 hover:ring-2 hover:ring-blue-100 transition-all group flex items-center gap-4 cursor-pointer">
                    <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-800 leading-none mb-1">Módulo PCP</h3>
                    </div>
                </a>

                <!-- Botão Almoxarifado -->
                <a href="separacao_almoxarifado.php" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-cyan-400 hover:ring-2 hover:ring-cyan-100 transition-all group flex items-center gap-4 cursor-pointer">
                    <div class="w-12 h-12 bg-cyan-50 text-cyan-600 rounded-xl flex items-center justify-center group-hover:bg-cyan-600 group-hover:text-white transition-colors shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-800 leading-none mb-1">Almoxarifado</h3>
                    </div>
                </a>

                <!-- Botão Formulação -->
                <a href="formulacao.php" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-purple-400 hover:ring-2 hover:ring-purple-100 transition-all group flex items-center gap-4 cursor-pointer">
                    <div class="w-12 h-12 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center group-hover:bg-purple-600 group-hover:text-white transition-colors shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-800 leading-none mb-1">Formulação</h3>
                    </div>
                </a>

                <!-- Botão Cadastros / Master Data -->
                <a href="cadastros.php" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-slate-800 hover:ring-2 hover:ring-slate-100 transition-all group flex items-center gap-4 cursor-pointer">
                    <div class="w-12 h-12 bg-slate-100 text-slate-600 rounded-xl flex items-center justify-center group-hover:bg-slate-800 group-hover:text-white transition-colors shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-800 leading-none mb-1">Cadastros</h3>
                    </div>
                </a>

            </div>
        </div>
        <!-- SELETOR DE FÁBRICA / LINHA -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
            <div class="flex items-center justify-between mb-3">
                <?php if ($fabrica_filtro || $linha_filtro): ?>
                    <span class="text-xs font-bold text-slate-500">
                        <?php if ($linha_filtro && $nome_linha_filtro): ?>
                            Fábrica <?= $fabrica_filtro ?> <span class="text-slate-300">/</span> Linha <span class="text-slate-800 uppercase"><?= htmlspecialchars($nome_linha_filtro) ?></span>
                        <?php else: ?>
                            Fábrica <?= $fabrica_filtro ?> <span class="text-slate-300">/</span> <span class="text-slate-800">todas as linhas</span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="<?= url_dashboard_admin([], $mes_filtro, $ano_filtro) ?>" class="px-5 py-2.5 rounded-xl text-sm font-bold transition-colors <?= !$fabrica_filtro ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-50 text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                    Visão Global (Todas)
                </a>
                <?php foreach ($lista_fabricas as $fab): ?>
                    <a href="<?= url_dashboard_admin(['fabrica' => $fab], $mes_filtro, $ano_filtro) ?>" class="px-5 py-2.5 rounded-xl text-sm font-bold transition-colors <?= ($fabrica_filtro === $fab) ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-50 text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                        Fábrica <?= $fab ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($fabrica_filtro): ?>
                <?php
                $linhas_da_fabrica = array_values(array_filter($todas_linhas_seletor, fn($l) => $l['fabrica'] == $fabrica_filtro));
                ?>
                <?php if (!empty($linhas_da_fabrica)): ?>
                    <div class="flex flex-wrap gap-1.5 mt-3 pt-3 border-t border-slate-100">
                        <span class="text-[10px] font-bold text-slate-400 uppercase self-center mr-1">Linha:</span>
                        <?php foreach ($linhas_da_fabrica as $l): ?>
                            <a href="<?= url_dashboard_admin(['fabrica' => $fabrica_filtro, 'linha' => $l['id']], $mes_filtro, $ano_filtro) ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold uppercase transition-colors <?= ($linha_filtro === $l['id']) ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                                <?= htmlspecialchars($l['login']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($linha_filtro): ?>
                            <a href="<?= url_dashboard_admin(['fabrica' => $fabrica_filtro], $mes_filtro, $ano_filtro) ?>" title="Voltar pra ver todas as linhas da fábrica" class="px-3 py-1.5 rounded-lg text-xs font-bold text-rose-500 hover:bg-rose-50 flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Limpar linha
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

    <div class="max-w-7xl mx-auto px-4 space-y-12 mt-8">

        <div>
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4 border-b border-gray-300 pb-2">
                <h2 class="text-lg font-black text-gray-800">Panorama Operacional Global <span class="text-slate-400 font-bold">(<?= htmlspecialchars($label_escopo_geral) ?>)</span></h2>

                <!-- ESCOPO DE PERÍODO DO PANORAMA: total / mês / personalizado -->
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= url_dashboard_admin(['escopo_geral' => 'total'] + ($fabrica_filtro ? ['fabrica' => $fabrica_filtro] : []) + ($linha_filtro ? ['linha' => $linha_filtro] : []), $mes_filtro, $ano_filtro) ?>"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors <?= $escopo_geral === 'total' ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                        Total Histórico
                    </a>
                    <a href="<?= url_dashboard_admin(['escopo_geral' => 'mes'] + ($fabrica_filtro ? ['fabrica' => $fabrica_filtro] : []) + ($linha_filtro ? ['linha' => $linha_filtro] : []), $mes_filtro, $ano_filtro) ?>"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors <?= $escopo_geral === 'mes' ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>"
                        title="Usa o mesmo mês/ano selecionado no Recorte Mensal, mais abaixo">
                        Mês Selecionado (<?= $meses_nomes[$mes_filtro] ?>/<?= $ano_filtro ?>)
                    </a>
                    <button type="button" onclick="document.getElementById('form_periodo_personalizado').classList.toggle('hidden')"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors <?= $escopo_geral === 'personalizado' ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                        Período Personalizado
                    </button>
                </div>
            </div>

            <!-- Form do período personalizado -- escondido até clicar no botão acima
                 (ou já visível se esse já for o escopo ativo, pra facilitar reajustar as datas) -->
            <form method="GET" id="form_periodo_personalizado" class="<?= $escopo_geral === 'personalizado' ? '' : 'hidden' ?> flex flex-wrap items-end gap-3 bg-white border border-slate-200 rounded-xl p-4 mb-4 shadow-sm">
                <input type="hidden" name="escopo_geral" value="personalizado">
                <input type="hidden" name="mes" value="<?= htmlspecialchars($mes_filtro) ?>">
                <input type="hidden" name="ano" value="<?= htmlspecialchars($ano_filtro) ?>">
                <?php if ($fabrica_filtro): ?><input type="hidden" name="fabrica" value="<?= $fabrica_filtro ?>"><?php endif; ?>
                <?php if ($linha_filtro): ?><input type="hidden" name="linha" value="<?= $linha_filtro ?>"><?php endif; ?>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">De</label>
                    <input type="date" name="data_inicio" value="<?= htmlspecialchars($data_inicio_geral) ?>" required class="px-3 py-2 border border-slate-300 rounded-lg text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Até</label>
                    <input type="date" name="data_fim" value="<?= htmlspecialchars($data_fim_geral) ?>" required class="px-3 py-2 border border-slate-300 rounded-lg text-sm font-semibold">
                </div>
                <button type="submit" class="bg-slate-800 hover:bg-black text-white font-bold px-4 py-2 rounded-lg text-sm shadow-sm transition-colors">Aplicar Período</button>
            </form>

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
                <div class="flex justify-between items-center border-b pb-2 mb-4">
                    <h3 class="text-lg font-bold text-gray-800">Top 10 Produtos Fabricados</h3>
                    <button type="button" onclick="document.getElementById('modal_todos_produtos').showModal()" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-1.5 rounded-lg transition-colors border border-blue-200 shadow-sm flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                        </svg>
                        Ver Todos
                    </button>
                </div>
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
                    <?php if ($fabrica_filtro): ?><input type="hidden" name="fabrica" value="<?= $fabrica_filtro ?>"><?php endif; ?>
                    <?php if ($linha_filtro): ?><input type="hidden" name="linha" value="<?= $linha_filtro ?>"><?php endif; ?>
                    <?php if ($escopo_geral !== 'total'): ?><input type="hidden" name="escopo_geral" value="<?= htmlspecialchars($escopo_geral) ?>"><?php endif; ?>
                    <?php if ($escopo_geral === 'personalizado'): ?>
                        <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($data_inicio_geral) ?>">
                        <input type="hidden" name="data_fim" value="<?= htmlspecialchars($data_fim_geral) ?>">
                    <?php endif; ?>
                    <select name="mes" class="px-3 py-2 border border-gray-300 rounded font-semibold text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <?php foreach ($meses_nomes as $num => $nome): ?>
                            <option value="<?= $num ?>" <?= $mes_filtro == $num ? 'selected' : '' ?>><?= $nome ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ano" class="px-3 py-2 border border-gray-300 rounded font-semibold text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <?php for ($i = date('Y'); $i >= 2024; $i--): ?>
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
                    <div class="relative h-72 w-full"><canvas id="graficoParadasLinha"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                    <h3 class="text-sm font-bold text-gray-600 mb-4">Causa x Frequência</h3>
                    <div class="relative h-72 w-full"><canvas id="graficoPareto"></canvas></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                <div class="p-4 border-b bg-gray-50">
                    <h3 class="font-bold text-gray-800">Detalhamento Operacional (Clique na linha para Raio-X)</h3>
                </div>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 bg-white text-white text-xs uppercase tracking-wider">
                            <tr class="text-xs uppercase tracking-wider text-gray-500">
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
                                        <?php if ($linha['oee_geral'] >= 85): ?> <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-bold">EXCELENTE</span>
                                        <?php elseif ($linha['oee_geral'] >= 60): ?> <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs font-bold">ATENÇÃO</span>
                                        <?php elseif ($linha['oee_geral'] > 0): ?> <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-bold">CRÍTICO</span>
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
                <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                    <h3 class="font-bold text-gray-800">Matriz de Ofensores: Linha x Motivo x Minutos</h3>
                </div>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 bg-white text-white text-xs uppercase tracking-wider">
                            <tr class="text-xs uppercase tracking-wider text-gray-500">
                                <th class="p-3 font-bold">Linha</th>
                                <th class="p-3 font-bold">Motivo</th>
                                <th class="p-3 font-bold text-right">Perdido</th>
                            </tr>
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
    <dialog id="modal_todos_produtos" class="p-0 rounded-[1.5rem] shadow-2xl border border-slate-100 w-[95%] max-w-3xl bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
            <div>
                <h3 class="text-base font-black text-slate-800 uppercase tracking-wide">Volume Total de Produção</h3>
                <p class="text-xs font-bold text-slate-400 mt-0.5">Lista completa de produtos fabricados (<?= count($todos_produtos_ranking) ?> itens encontrados)</p>
            </div>
            <button type="button" onclick="this.closest('dialog').close()" class="w-9 h-9 border-2 border-slate-800 rounded-[10px] flex items-center justify-center text-slate-700 hover:bg-slate-100 transition-colors shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="p-5 border-b border-slate-100 bg-white">
            <div class="relative">
                <svg class="w-5 h-5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="filtro_modal_produtos" onkeyup="filtrarListaProdutos()" placeholder="Buscar por código ou nome do produto..." class="w-full pl-10 pr-4 py-3 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-200 outline-none transition-all">
            </div>
        </div>

        <div class="overflow-y-auto max-h-[50vh] bg-white">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-slate-50 text-slate-500 text-[10px] uppercase tracking-wider font-bold sticky top-0 border-b border-slate-200 shadow-sm z-10">
                    <tr>
                        <th class="p-4 w-20">Rank</th>
                        <th class="p-4">Código / Descrição</th>
                        <th class="p-4 text-right">Volume Produzido</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($todos_produtos_ranking)): ?>
                        <tr>
                            <td colspan="3" class="p-8 text-center text-slate-400 font-bold">Nenhum dado de produção encontrado.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($todos_produtos_ranking as $index => $prod): ?>
                        <tr class="hover:bg-blue-50 transition-colors linha-modal-produto" data-busca="<?= strtolower(htmlspecialchars($prod['codigo'] . ' ' . $prod['descricao'])) ?>">
                            <td class="p-4 font-black text-slate-400">#<?= $index + 1 ?></td>
                            <td class="p-4">
                                <span class="font-black text-slate-500 mr-1">[<?= htmlspecialchars($prod['codigo']) ?>]</span>
                                <span class="font-bold text-slate-800"><?= htmlspecialchars($prod['descricao']) ?></span>
                            </td>
                            <td class="p-4 text-right font-black text-emerald-600 text-base">
                                <?= number_format($prod['qtd_total'], 0, ',', '.') ?> <span class="text-[10px] text-slate-400 uppercase tracking-widest ml-1">un</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Mensagem para quando a busca não acha nada -->
            <div id="msg_vazio_produtos" class="hidden p-8 text-center text-rose-500 font-bold bg-rose-50 m-4 rounded-xl border border-rose-200">
                Nenhum produto corresponde à sua pesquisa.
            </div>
        </div>
    </dialog>
    <script>
        // Graficos Gerais (Mundo)
        new Chart(document.getElementById('graficoOEE').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_linhas) ?>,
                datasets: [{
                    label: 'Total Produzido (un)',
                    data: <?= json_encode($data_prod_linhas) ?>,
                    backgroundColor: <?= json_encode($cores_fabricas) ?>,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false // Esconde a legenda pois só temos uma métrica
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                        // Removido o max: 100 para permitir volumes altos
                    }
                }
            }
        });
        new Chart(document.getElementById('graficoProdutos').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($labels_produtos) ?>,
                datasets: [{
                    data: <?= json_encode($data_produtos) ?>,
                    backgroundColor: ['#1f2937', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#64748b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Graficos Filtrados (Mês)
        new Chart(document.getElementById('graficoProducao').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_prod) ?>,
                datasets: [{
                    type: 'line',
                    label: 'Acumulado Mês',
                    data: <?= json_encode($data_prod_acumulada) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'yAcumulado'
                }, {
                    type: 'bar',
                    label: 'Produção Diária',
                    data: <?= json_encode($data_prod_dia) ?>,
                    backgroundColor: '#3b82f6',
                    borderRadius: 4,
                    yAxisID: 'yDiario'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yDiario: {
                        type: 'linear',
                        position: 'left'
                    },
                    yAcumulado: {
                        type: 'linear',
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('graficoPareto').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_pareto) ?>,
                datasets: [{
                    type: 'line',
                    label: '% Acumulada',
                    data: <?= json_encode($data_pareto_porcentagem) ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: '#f59e0b',
                    borderWidth: 3,
                    yAxisID: 'yPorcentagem'
                }, {
                    type: 'bar',
                    label: 'Minutos Perdidos',
                    data: <?= json_encode($data_pareto_minutos) ?>,
                    backgroundColor: '#475569',
                    borderRadius: 2,
                    yAxisID: 'yMinutos'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yMinutos: {
                        type: 'linear',
                        position: 'left'
                    },
                    yPorcentagem: {
                        type: 'linear',
                        position: 'right',
                        max: 100,
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Grafico de Paradas (COM REDIRECIONAMENTO CLICÁVEL)
        const idsParadasLinha = <?= json_encode($ids_parada_linha) ?>;
        const mesSelecionado = '<?= $mes_filtro ?>';
        const anoSelecionado = '<?= $ano_filtro ?>';

        new Chart(document.getElementById('graficoParadasLinha').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_parada_linha) ?>,
                datasets: [{
                    label: 'Minutos Parados',
                    data: <?= json_encode($data_parada_linha) ?>,
                    backgroundColor: '#ef4444',
                    borderRadius: 4,
                    hoverBackgroundColor: '#b91c1c'
                }]
            },
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

        function filtrarListaProdutos() {
            const termo = document.getElementById('filtro_modal_produtos').value.toLowerCase();
            let visiveis = 0;

            document.querySelectorAll('.linha-modal-produto').forEach(linha => {
                const texto = linha.dataset.busca;
                if (texto.includes(termo)) {
                    linha.style.display = '';
                    visiveis++;
                } else {
                    linha.style.display = 'none';
                }
            });

            document.getElementById('msg_vazio_produtos').classList.toggle('hidden', visiveis > 0);
        }
    </script>

</body>

</html>