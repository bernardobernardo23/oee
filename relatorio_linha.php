<?php
session_start();
require 'conexao.php';
//relatoriolinha.php
// SEGURANÇA ATUALIZADA: Permite o acesso ao Raio-X apenas para usuários corporativos (Admin, Diretoria, PCP, etc.)
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario') {
    header("Location: index.php");
    exit;
}


$linha_id = (int)($_GET['id'] ?? 0);
$data_filtro = $_GET['data'] ?? date('Y-m-d');

function formatarMinutos($minutos)
{
    $h = floor($minutos / 60);
    $m = $minutos % 60;
    if ($h > 0 && $m > 0) return "{$h}h {$m}m";
    if ($h > 0) return "{$h}h";
    return "{$m}m";
}

try {
    $stmt_linha = $pdo->prepare("SELECT login, fabrica, capacidade_dia FROM linhas WHERE id = ?");
    $stmt_linha->execute([$linha_id]);
    $linhaInfo = $stmt_linha->fetch(PDO::FETCH_ASSOC);

    if (!$linhaInfo) die("Linha não encontrada na base de dados.");

    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM apontamentos WHERE linha_id = ? AND DATE(data_registro) = ?");
    $stmt_check->execute([$linha_id, $data_filtro]);
    $tem_dados = $stmt_check->fetchColumn() > 0;

    if ($tem_dados) {
        $stmt_kpi = $pdo->prepare("SELECT 
            IFNULL(AVG(oee_disponibilidade), 0) as disp, 
            IFNULL(AVG(oee_performance), 0) as perf, 
            IFNULL(AVG(oee_qualidade), 0) as qual, 
            IFNULL(AVG(oee_geral), 0) as oee
            FROM apontamentos 
            WHERE linha_id = ? AND DATE(data_registro) = ? AND oee_geral IS NOT NULL");
        $stmt_kpi->execute([$linha_id, $data_filtro]);
        $kpis = $stmt_kpi->fetch(PDO::FETCH_ASSOC);

        // ALTERAÇÃO: Adicionado 'equipe_auxiliares' na consulta
        $stmt_timeline = $pdo->prepare("
            SELECT id, data_registro, hora_inicio, hora_fim, nome_operador, equipe_auxiliares, ordem_producao,
                   oee_disponibilidade, oee_performance, oee_qualidade, oee_geral
            FROM apontamentos
            WHERE linha_id = ? AND DATE(data_registro) = ?
            ORDER BY hora_inicio ASC
        ");
        $stmt_timeline->execute([$linha_id, $data_filtro]);
        $apontamentos_timeline = $stmt_timeline->fetchAll(PDO::FETCH_ASSOC);

        foreach ($apontamentos_timeline as &$apt) {
            // Verifica se a hora_inicio já vem com a data junto (novo padrão DATETIME)
            // ou se é apenas o tempo (apontamentos antigos antes da atualização)
            if (strlen($apt['hora_inicio']) > 8) {
                $ini = new DateTime($apt['hora_inicio']);
            } else {
                $ini = new DateTime($data_filtro . ' ' . $apt['hora_inicio']);
            }

            // A máquina pode estar com a produção em andamento agora (hora_fim vazia)
            if (empty($apt['hora_fim'])) {
                $fim = new DateTime(); // Usa a hora atual para o gráfico não quebrar
            } else {
                if (strlen($apt['hora_fim']) > 8) {
                    $fim = new DateTime($apt['hora_fim']);
                } else {
                    $fim = new DateTime($data_filtro . ' ' . $apt['hora_fim']);
                    if ($fim < $ini) $fim->modify('+1 day'); // Ajuste de madrugada (para legado)
                }
            }

            $apt['dt_inicio'] = $ini;
            $apt['dt_fim'] = $fim;
        }
        unset($apt);

        $stmt_prod = $pdo->prepare("SELECT p.descricao, SUM(ap.producao_boas) as total_boas, SUM(ap.producao_refugo) as total_refugo FROM apontamentos a JOIN apontamento_producao ap ON a.id = ap.apontamento_id JOIN produtos p ON ap.produto_id = p.id WHERE a.linha_id = ? AND DATE(a.data_registro) = ? GROUP BY p.id ORDER BY total_boas DESC");
        $stmt_prod->execute([$linha_id, $data_filtro]);
        $produtos = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

        $stmt_paradas = $pdo->prepare("SELECT m.descricao, m.tipo, SUM(ap.minutos_parados) as total_minutos FROM apontamentos a JOIN apontamento_paradas ap ON a.id = ap.apontamento_id JOIN motivos_parada m ON ap.motivo_id = m.id WHERE a.linha_id = ? AND DATE(a.data_registro) = ? GROUP BY m.id ORDER BY total_minutos DESC");
        $stmt_paradas->execute([$linha_id, $data_filtro]);
        $paradas = $stmt_paradas->fetchAll(PDO::FETCH_ASSOC);

        $stmt_perdas = $pdo->prepare("SELECT i.descricao, i.tipo, SUM(ap.quantidade) as total_perdido FROM apontamentos a JOIN apontamento_perdas ap ON a.id = ap.apontamento_id JOIN itens_componentes i ON ap.item_id = i.id WHERE a.linha_id = ? AND DATE(a.data_registro) = ? GROUP BY i.id ORDER BY total_perdido DESC");
        $stmt_perdas->execute([$linha_id, $data_filtro]);
        $perdas = $stmt_perdas->fetchAll(PDO::FETCH_ASSOC);

        $total_latas = 0;
        $total_tampas = 0;
        $total_valvulas = 0;
        foreach ($perdas as $pe) {
            $tipo_formatado = strtolower(trim($pe['tipo']));
            if ($tipo_formatado === 'lata') $total_latas += $pe['total_perdido'];
            elseif ($tipo_formatado === 'tampa' || $tipo_formatado === 'atuador') $total_tampas += $pe['total_perdido'];
            elseif ($tipo_formatado === 'valvula') $total_valvulas += $pe['total_perdido'];
        }

        $apontamento_ids = array_column($apontamentos_timeline, 'id');
        $prod_group = [];
        $parada_group = [];
        $perda_group = [];
        if (!empty($apontamento_ids)) {
            $inQuery = implode(',', array_fill(0, count($apontamento_ids), '?'));

            $stmt_tp = $pdo->prepare("SELECT apontamento_id, p.descricao, ap.producao_boas, ap.producao_refugo FROM apontamento_producao ap JOIN produtos p ON ap.produto_id = p.id WHERE apontamento_id IN ($inQuery)");
            $stmt_tp->execute($apontamento_ids);
            $prod_group = $stmt_tp->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

            $stmt_tpa = $pdo->prepare("SELECT apontamento_id, m.descricao, m.tipo, ap.minutos_parados FROM apontamento_paradas ap JOIN motivos_parada m ON ap.motivo_id = m.id WHERE apontamento_id IN ($inQuery)");
            $stmt_tpa->execute($apontamento_ids);
            $parada_group = $stmt_tpa->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

            $stmt_tpe = $pdo->prepare("SELECT apontamento_id, i.descricao, i.tipo, ap.quantidade FROM apontamento_perdas ap JOIN itens_componentes i ON ap.item_id = i.id WHERE apontamento_id IN ($inQuery)");
            $stmt_tpe->execute($apontamento_ids);
            $perda_group = $stmt_tpe->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        }

        $blocos_barra = [];
        $start_time_dt = clone $apontamentos_timeline[0]['dt_inicio'];

        $end_time_dt = clone $apontamentos_timeline[0]['dt_fim'];
        foreach ($apontamentos_timeline as $apt) {
            if ($apt['dt_fim'] > $end_time_dt) {
                $end_time_dt = clone $apt['dt_fim'];
            }
        }

        $total_span_min = ($start_time_dt->diff($end_time_dt)->h * 60) + $start_time_dt->diff($end_time_dt)->i;
        $current_time = clone $start_time_dt;

        foreach ($apontamentos_timeline as $apt) {
            $apt_ini = clone $apt['dt_inicio'];
            $apt_fim = clone $apt['dt_fim'];

            if ($apt_ini > $current_time) {
                $gap_min = ($current_time->diff($apt_ini)->h * 60) + $current_time->diff($apt_ini)->i;
                if ($gap_min > 0) {
                    $blocos_barra[] = ['tipo' => 'gap', 'minutos' => $gap_min, 'tooltip' => 'Sem Operação/Apontamento: ' . formatarMinutos($gap_min)];
                }
            }

            $apt_dur = ($apt_ini->diff($apt_fim)->h * 60) + $apt_ini->diff($apt_fim)->i;

            $min_plan = 0;
            $min_nplan = 0;
            if (!empty($parada_group[$apt['id']])) {
                foreach ($parada_group[$apt['id']] as $pa) {
                    if ($pa['tipo'] == 'Planejada') $min_plan += $pa['minutos_parados'];
                    else $min_nplan += $pa['minutos_parados'];
                }
            }

            $min_prod = $apt_dur - $min_plan - $min_nplan;
            if ($min_prod < 0) $min_prod = 0;

            if ($min_prod > 0) $blocos_barra[] = ['tipo' => 'prod', 'minutos' => $min_prod, 'tooltip' => 'Produção Ativa: ' . formatarMinutos($min_prod)];
            if ($min_plan > 0) $blocos_barra[] = ['tipo' => 'plan', 'minutos' => $min_plan, 'tooltip' => 'Parada Programada: ' . formatarMinutos($min_plan)];
            if ($min_nplan > 0) $blocos_barra[] = ['tipo' => 'nplan', 'minutos' => $min_nplan, 'tooltip' => 'Parada Não Programada: ' . formatarMinutos($min_nplan)];

            if ($apt_fim > $current_time) {
                $current_time = clone $apt_fim;
            }
        }
    }
} catch (PDOException $e) {
    die("Erro: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raio-X da Linha - MES/OEE</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
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
    <style>
        dialog::backdrop {
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(2px);
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen font-sans pb-12">

    <?php include 'header.php'; ?>


    <div class="max-w-7xl mx-auto px-4 space-y-8">

        <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200 flex flex-col md:flex-row gap-4 items-center justify-between">
            <div>
                <h3 class="font-bold text-gray-800">Filtro de Data</h3>
                <p class="text-xs text-gray-500">Selecione o dia específico para visualizar a linha do tempo.</p>
            </div>
            <form method="GET" class="flex gap-2 w-full md:w-auto">
                <input type="hidden" name="id" value="<?= $linha_id ?>">
                <input type="date" name="data" value="<?= $data_filtro ?>" class="px-4 py-2 border border-gray-300 rounded font-bold text-gray-700 focus:outline-none focus:border-blue-500 shadow-sm">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2 rounded shadow transition">Filtrar</button>
            </form>
        </div>

        <?php if (!$tem_dados): ?>
            <div class="bg-white rounded-2xl shadow border border-gray-200 p-12 text-center">
                <div class="inline-block bg-gray-100 rounded-full p-6 mb-4">
                    <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                </div>
                <h2 class="text-2xl font-black text-gray-800 mb-2">Nenhum Apontamento no Dia</h2>
                <p class="text-gray-500 max-w-md mx-auto">A Linha <strong><?= strtoupper($linhaInfo['login']) ?></strong> não possui registros para <strong><?= date('d/m/Y', strtotime($data_filtro)) ?></strong>.</p>
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl shadow-sm border p-5 border-l-4 border-blue-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Disponibilidade (Dia)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['disp'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border p-5 border-l-4 border-orange-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Performance (Dia)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['perf'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border p-5 border-l-4 border-green-500">
                    <h3 class="text-gray-500 text-xs font-bold uppercase mb-1">Qualidade (Dia)</h3>
                    <div class="text-3xl font-black text-gray-800"><?= number_format($kpis['qual'], 1, ',', '.') ?>%</div>
                </div>
                <div class="bg-blue-900 rounded-xl shadow-sm p-5 text-white">
                    <h3 class="text-blue-300 text-xs font-bold uppercase mb-1">OEE Consolidado</h3>
                    <div class="text-4xl font-black"><?= number_format($kpis['oee'], 1, ',', '.') ?>%</div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
                <h2 class="text-lg font-black text-gray-800 mb-6">Trilha de Produção Diária</h2>
                <div class="relative">
                    <div class="flex justify-between text-sm font-black text-gray-800 mb-2">
                        <span><?= $start_time_dt->format('H:i') ?></span>
                        <span><?= $end_time_dt->format('H:i') ?></span>
                    </div>

                    <div class="w-full h-10 bg-gray-200 flex rounded shadow-inner overflow-hidden border border-gray-300">
                        <?php foreach ($blocos_barra as $idx => $b):
                            $pct = ($b['minutos'] / $total_span_min) * 100;
                            $bgClass = '';
                            if ($b['tipo'] == 'prod') $bgClass = 'bg-green-500 hover:bg-green-600';
                            if ($b['tipo'] == 'plan') $bgClass = 'bg-yellow-400 hover:bg-yellow-500';
                            if ($b['tipo'] == 'nplan') $bgClass = 'bg-red-500 hover:bg-red-600';
                            if ($b['tipo'] == 'gap') $bgClass = 'bg-gray-300 hover:bg-gray-400';
                        ?>
                            <div style="width: <?= $pct ?>%" class="<?= $bgClass ?> transition cursor-help border-r border-black/5 last:border-0" title="<?= $b['tooltip'] ?>"></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex flex-wrap justify-center gap-6 mt-4 text-xs font-bold text-gray-600 uppercase tracking-wider">
                        <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500 shadow-sm"></span> Produzindo</div>
                        <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-yellow-400 shadow-sm"></span> Parada Programada</div>
                        <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-red-500 shadow-sm"></span> Falha / N. Prog.</div>
                        <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-gray-300 shadow-sm border border-gray-400"></span> Sem Registro (Vazio)</div>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-black text-gray-800 mb-4">Apontamentos Realizados (Clique para visualizar)</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">

                    <?php foreach ($apontamentos_timeline as $apt):
                        // Cálculos Resumo para a Face do Card
                        $apt_refugo = 0;
                        if (!empty($prod_group[$apt['id']])) {
                            foreach ($prod_group[$apt['id']] as $p) $apt_refugo += $p['producao_refugo'];
                        }

                        $apt_paradas = 0;
                        if (!empty($parada_group[$apt['id']])) {
                            foreach ($parada_group[$apt['id']] as $pa) $apt_paradas += $pa['minutos_parados'];
                        }

                        // Concatena Equipe de Operadores
                        $lista_operadores = htmlspecialchars($apt['nome_operador']);
                        if (!empty($apt['equipe_auxiliares'])) {
                            $lista_operadores .= ', ' . htmlspecialchars($apt['equipe_auxiliares']);
                        }
                    ?>

                        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 hover:shadow-md hover:border-blue-400 transition flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <div class="text-xs text-gray-400 font-bold uppercase tracking-wider">Turno #<?= $apt['id'] ?></div>
                                    <div class="text-xs font-black <?= $apt['oee_geral'] >= 85 ? 'text-green-600' : ($apt['oee_geral'] >= 60 ? 'text-yellow-600' : 'text-red-600') ?>"><?= number_format($apt['oee_geral'], 1, ',', '.') ?>% OEE</div>
                                </div>
                                <div class="text-lg font-black text-blue-600 mb-2"><?= $apt['dt_inicio']->format('H:i') ?> <span class="text-gray-400 text-sm mx-1">até</span> <?= $apt['dt_fim']->format('H:i') ?></div>

                                <div class="text-xs text-gray-600 mb-3 line-clamp-2" title="<?= $lista_operadores ?>">
                                    <span class="font-bold text-gray-700">Equipe:</span> <?= $lista_operadores ?>
                                </div>

                                <div class="flex justify-between border-t border-gray-100 pt-3 mb-4">
                                    <div class="text-center">
                                        <div class="text-[10px] uppercase font-bold text-gray-400">Paradas</div>
                                        <div class="text-sm font-black <?= $apt_paradas > 0 ? 'text-orange-500' : 'text-gray-500' ?>"><?= $apt_paradas > 0 ? formatarMinutos($apt_paradas) : '0m' ?></div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-[10px] uppercase font-bold text-gray-400">Refugos</div>
                                        <div class="text-sm font-black <?= $apt_refugo > 0 ? 'text-red-500' : 'text-gray-500' ?>"><?= number_format($apt_refugo, 0, ',', '.') ?> un</div>
                                    </div>
                                </div>
                            </div>
                            <button onclick="document.getElementById('modal_apt_<?= $apt['id'] ?>').showModal()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2 rounded shadow-sm transition">
                                Detalhar Turno
                            </button>
                        </div>

                        <dialog id="modal_apt_<?= $apt['id'] ?>" class="p-0 rounded-2xl shadow-2xl border-0 w-[95%] max-w-2xl bg-white m-auto">
                            <div class="p-6 max-h-[85vh] overflow-y-auto">
                                <div class="flex justify-between items-start border-b border-gray-200 pb-4 mb-4">
                                    <div>
                                        <h3 class="text-xl font-black text-gray-800">Detalhes do Turno #<?= $apt['id'] ?></h3>
                                        <p class="text-sm text-gray-500 font-medium mt-1">Horário: <?= $apt['dt_inicio']->format('H:i') ?> às <?= $apt['dt_fim']->format('H:i') ?> | OP: <?= htmlspecialchars($apt['ordem_producao']) ?></p>
                                        <p class="text-sm text-gray-500 font-medium"><span class="font-bold">Equipe:</span> <?= $lista_operadores ?></p>
                                    </div>
                                    <button onclick="this.closest('dialog').close()" class="text-gray-400 hover:text-red-600 bg-gray-100 hover:bg-red-50 rounded-full w-8 h-8 flex items-center justify-center font-bold transition">&times;</button>
                                </div>

                                <div class="mb-6">
                                    <div class="text-xs font-bold text-gray-400 uppercase mb-1">OEE Deste Turno</div>
                                    <div class="text-2xl font-black <?= $apt['oee_geral'] >= 85 ? 'text-green-600' : ($apt['oee_geral'] >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                        <?= number_format($apt['oee_geral'], 1, ',', '.') ?>%
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <?php if (!empty($prod_group[$apt['id']])): ?>
                                        <div class="bg-green-50 p-4 rounded-xl border border-green-100">
                                            <strong class="text-green-800 block mb-2 text-sm uppercase"> Produção (Aprovados e Refugos)</strong>
                                            <ul class="list-disc list-inside text-green-900 text-sm space-y-1">
                                                <?php foreach ($prod_group[$apt['id']] as $p): ?>
                                                    <li><?= htmlspecialchars($p['descricao']) ?>: <strong><?= number_format($p['producao_boas'], 0, ',', '.') ?> un</strong> boas (<?= $p['producao_refugo'] ?> refugo)</li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($parada_group[$apt['id']])): ?>
                                        <div class="bg-orange-50 p-4 rounded-xl border border-orange-100">
                                            <strong class="text-orange-800 block mb-2 text-sm uppercase"> Tempo de Máquina Parada</strong>
                                            <ul class="list-disc list-inside text-orange-900 text-sm space-y-1">
                                                <?php foreach ($parada_group[$apt['id']] as $pa): ?>
                                                    <li><?= htmlspecialchars($pa['descricao']) ?> (<?= $pa['tipo'] ?>): <strong><?= $pa['minutos_parados'] ?> min</strong></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($perda_group[$apt['id']])): ?>
                                        <div class="bg-red-50 p-4 rounded-xl border border-red-100">
                                            <strong class="text-red-800 block mb-2 text-sm uppercase"> Insumos Perdidos</strong>
                                            <ul class="list-disc list-inside text-red-900 text-sm space-y-1">
                                                <?php foreach ($perda_group[$apt['id']] as $pe): ?>
                                                    <li>[<?= $pe['tipo'] ?>] <?= htmlspecialchars($pe['descricao']) ?>: <strong><?= $pe['quantidade'] ?> un</strong></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </dialog>

                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <h2 class="text-xl font-black text-gray-800 mb-6 border-b border-gray-300 pb-2 mt-12">Consolidação do Dia Inteiro</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start mb-8">
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                        <div class="bg-green-600 p-4 text-white">
                            <h3 class="font-bold">Total de Produtos Fabricados do dia</h3>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b">
                                <tr>
                                    <th class="p-3">Descrição do Produto</th>
                                    <th class="p-3 text-right">Aprovado</th>
                                    <th class="p-3 text-right">Refugo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($produtos as $p): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="p-3 font-semibold text-gray-700"><?= htmlspecialchars($p['descricao']) ?></td>
                                        <td class="p-3 text-right font-bold text-green-600"><?= number_format($p['total_boas'], 0, ',', '.') ?></td>
                                        <td class="p-3 text-right font-bold text-red-500"><?= number_format($p['total_refugo'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                        <div class="bg-orange-600 p-4 text-white">
                            <h3 class="font-bold">Ofensores de Parada do Dia</h3>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b">
                                <tr>
                                    <th class="p-3">Motivo</th>
                                    <th class="p-3 text-right">Tempo Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($paradas as $pa): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="p-3 font-semibold text-gray-700"><?= htmlspecialchars($pa['descricao']) ?></td>
                                        <td class="p-3 text-right font-bold text-orange-600"><?= formatarMinutos($pa['total_minutos']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                    <div class="bg-red-700 p-4 text-white flex justify-between items-center">
                        <h3 class="font-bold">Perdas de Insumos do Dia</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-0 border-b border-gray-200 bg-gray-50">
                        <div class="p-4 text-center border-b md:border-b-0 md:border-r border-gray-200"><span class="block text-xs font-bold text-gray-500 uppercase">Latas</span><span class="text-2xl font-black text-red-600"><?= number_format($total_latas, 0, ',', '.') ?></span></div>
                        <div class="p-4 text-center border-b md:border-b-0 md:border-r border-gray-200"><span class="block text-xs font-bold text-gray-500 uppercase">Tampas/Atuadores</span><span class="text-2xl font-black text-red-600"><?= number_format($total_tampas, 0, ',', '.') ?></span></div>
                        <div class="p-4 text-center"><span class="block text-xs font-bold text-gray-500 uppercase">Válvulas</span><span class="text-2xl font-black text-red-600"><?= number_format($total_valvulas, 0, ',', '.') ?></span></div>
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white border-b border-gray-200">
                            <tr>
                                <th class="p-3 text-gray-600 font-bold">Descrição do Insumo</th>
                                <th class="p-3 text-right text-gray-600 font-bold">Quantidade Perdida</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($perdas as $pe): ?>
                                <tr class="hover:bg-red-50 transition">
                                    <td class="p-3 font-semibold text-gray-700"><?= htmlspecialchars($pe['descricao']) ?></td>
                                    <td class="p-3 text-right font-black text-red-600"><?= number_format($pe['total_perdido'], 0, ',', '.') ?> un</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    </div>
</body>

</html>