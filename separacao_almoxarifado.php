<?php
session_start();
require 'conexao.php';

// Validação de Segurança básica: Garante que o usuário está logado
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['ALMOXARIFADO', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

// ========================================================================
// 1. MOTOR DE ATUALIZAÇÃO: CONFIRMAÇÃO INICIAL DE SEPARAÇÃO
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_id']) && !isset($_POST['resolver_op_id'])) {
    try {
        $pdo->beginTransaction();

        $op_id = (int)$_POST['op_id'];
        $separador_id = $_SESSION['usuario_id']; // Pega o ID de quem logou no sistema
        $auxiliares = trim($_POST['auxiliares_separacao'] ?? '');
        $observacao = trim($_POST['observacao_almoxarifado'] ?? '');
        
        // Recebe a decisão do Almoxarifado: 'separado' ou 'pendencia'
        $decisao_separacao = $_POST['status_separacao'];
        
        // Define o status real da OP garantindo a nomenclatura exata 'PENDENCIA'
        $novo_status = ($decisao_separacao === 'pendencia') ? 'PENDENCIA' : 'AGUARDANDO INICIO';

        // Atualiza a OP com o novo schema
        $stmt_update = $pdo->prepare("
            UPDATE ordens_producao 
            SET status = ?, 
                separador_id = ?, 
                auxiliares_separacao = ?, 
                observacao_almoxarifado = ?,
                data_separacao = NOW() 
            WHERE id = ? AND status = 'PROGRAMADO'
        ");
        $stmt_update->execute([$novo_status, $separador_id, $auxiliares, $observacao, $op_id]);

        $pdo->commit();
        if ($novo_status === 'AGUARDANDO INICIO') {
            $mensagem = "Separação confirmada com sucesso! A OP foi liberada para a fábrica.";
        } else {
            $mensagem = "OP enviada com PENDÊNCIA. Ela ficará na aba 'Pendências Ativas' até a resolução.";
        }
        $tipo_msg = 'sucesso';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $tipo_msg = 'erro';
        $mensagem = "Erro ao confirmar separação: " . $e->getMessage();
    }
}

// ========================================================================
// 1.1 MOTOR DE ATUALIZAÇÃO: RESOLVER PENDÊNCIA
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolver_op_id'])) {
    try {
        $pdo->beginTransaction();
        $op_id = (int)$_POST['resolver_op_id'];
        $obs_resolucao = trim($_POST['obs_resolucao'] ?? '');

        // Pega a observação antiga para concatenar a nova
        $stmt_old = $pdo->prepare("SELECT observacao_almoxarifado FROM ordens_producao WHERE id = ?");
        $stmt_old->execute([$op_id]);
        $obs_antiga = $stmt_old->fetchColumn();

        $nova_obs = $obs_antiga;
        if (!empty($obs_resolucao)) {
            $nome_usuario = $_SESSION['nome'];
            $nova_obs .= "\n[Resolvido por {$nome_usuario}]: " . $obs_resolucao;
        }

        // Muda o status da OP para 'AGUARDANDO INICIO' (liberando para a linha)
        $stmt_res = $pdo->prepare("UPDATE ordens_producao SET status = 'AGUARDANDO INICIO', observacao_almoxarifado = ? WHERE id = ?");
        $stmt_res->execute([$nova_obs, $op_id]);

        $pdo->commit();
        $mensagem = "Pendência resolvida! A OP agora está com o material 100% separado e liberada para a fábrica.";
        $tipo_msg = 'sucesso';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $tipo_msg = 'erro';
        $mensagem = "Erro ao resolver pendência: " . $e->getMessage();
    }
}

// Normaliza o status para comparações seguras
function normalizaStatus($str) {
    $str = strtoupper(trim($str));
    $str = str_replace(
        ['Ç', 'Ã', 'Á', 'À', 'É', 'Í', 'Ó', 'Ú', 'Â', 'Ê'],
        ['C', 'A', 'A', 'A', 'E', 'I', 'O', 'U', 'A', 'E'],
        $str
    );
    return $str;
}

try {
    // ========================================================================
    // 2. CONSULTAS DE DADOS (FILA, PENDÊNCIAS E HISTÓRICO)
    // ========================================================================

    // ABA 1: OPs Pendentes de Separação Inicial (STATUS: PROGRAMADO)
    $stmt_ops = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.observacao_almoxarifado,
               l.login as linha_nome, l.fabrica,
               (SELECT COUNT(id) FROM op_produtos WHERE op_id = op.id) as qtd_pas,
               (SELECT SUM(quantidade_planejada) FROM op_produtos WHERE op_id = op.id) as volume_total
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        WHERE op.status = 'PROGRAMADO'
        ORDER BY op.data_planejada ASC, volume_total DESC
    ");
    $ops_pendentes = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);

    $detalhes_ops = [];
    foreach ($ops_pendentes as $op) {
        $stmt_prods = $pdo->prepare("SELECT id as op_produto_id, produto_id, quantidade_planejada FROM op_produtos WHERE op_id = ?");
        $stmt_prods->execute([$op['id']]);
        $produtos_da_op = $stmt_prods->fetchAll(PDO::FETCH_ASSOC);

        foreach ($produtos_da_op as &$prod) {
            $p_info = $pdo->prepare("SELECT codigo, descricao FROM produtos WHERE id = ?");
            $p_info->execute([$prod['produto_id']]);
            $p_data = $p_info->fetch(PDO::FETCH_ASSOC);
            $prod['codigo'] = $p_data['codigo'] ?? 'N/A';
            $prod['descricao'] = $p_data['descricao'] ?? 'Produto não identificado';
        }
        unset($prod);
        $op['produtos'] = $produtos_da_op;
        $detalhes_ops[] = $op;
    }

    // ABA 2: Pendências Ativas (OPs com STATUS = 'PENDENCIA')
    $stmt_pendencias = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.data_separacao, op.observacao_almoxarifado,
               l.login as linha_nome, l.fabrica,
               u.nome_completo as nome_separador
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.separador_id = u.id
        WHERE op.status = 'PENDENCIA'
        ORDER BY op.data_separacao DESC, op.id DESC
    ");
    $ops_pendencias = $stmt_pendencias->fetchAll(PDO::FETCH_ASSOC);

    // ABA 3: Histórico de Separação Geral (Qualquer status diferente de PROGRAMADO)
    $stmt_hist = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.data_separacao,
               l.login as linha_nome, l.fabrica,
               u.nome_completo as nome_separador
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.separador_id = u.id
        WHERE op.status != 'PROGRAMADO' AND op.separador_id IS NOT NULL
        ORDER BY op.data_separacao DESC, op.id DESC
    ");
    $historico_ops = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao carregar dados do almoxarifado: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almoxarifado - Separação de Insumos</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'], } } } }
    </script>
    <style> dialog[open] { display: flex; flex-direction: column; } </style>
</head>

<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <?php include 'header.php'; ?>

    <div class="max-w-6xl mx-auto px-4 space-y-6">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Controle de Separação Física</h2>
                <p class="text-sm text-slate-500 font-medium">Consulte as ordens programadas, resolva pendências e acesse o histórico.</p>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-lg shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?= $mensagem ?>
            </div>
        <?php endif; ?>

        <!-- NAVEGAÇÃO ENTRE AS 3 ABAS -->
        <div class="flex border-b border-slate-200 overflow-x-auto no-scrollbar">
            <button onclick="mudarAba('pendentes')" id="btn_pendentes" class="px-5 py-3 border-b-2 border-amber-500 text-amber-700 font-bold text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
                Fila de Separação
                <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-[10px]"><?= count($detalhes_ops) ?></span>
            </button>
            <button onclick="mudarAba('pendencias')" id="btn_pendencias" class="px-5 py-3 border-b-2 border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
                Pendências Ativas
                <span class="<?= count($ops_pendencias) > 0 ? 'bg-rose-500 text-white' : 'bg-slate-100 text-slate-600' ?> px-2 py-0.5 rounded-full text-[10px] shadow-sm"><?= count($ops_pendencias) ?></span>
            </button>
            <button onclick="mudarAba('historico')" id="btn_historico" class="px-5 py-3 border-b-2 border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
                Histórico Geral
                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full text-[10px]"><?= count($historico_ops) ?></span>
            </button>
        </div>

        <!-- ========================================== -->
        <!-- ABA 1: FILA DE SEPARAÇÃO (PROGRAMADO)      -->
        <!-- ========================================== -->
        <div id="aba_pendentes" class="block">
            <?php if (empty($detalhes_ops)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center mt-4">
                    <div class="inline-block bg-emerald-50 rounded-full p-4 mb-3 border border-emerald-100 text-emerald-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-1">Armazém Atualizado</h3>
                    <p class="text-sm text-slate-400 font-medium">Nenhum kit novo pendente de separação no momento.</p>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden mt-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                                <tr>
                                    <th class="p-4">Data Planejada</th>
                                    <th class="p-4">OP Sistema</th>
                                    <th class="p-4">Linha Destino</th>
                                    <th class="p-4 text-center">Lotes de Produtos</th>
                                    <th class="p-4 text-center">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                <?php foreach ($detalhes_ops as $op): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="p-4 text-slate-500"><?= date('d/m/Y', strtotime($op['data_planejada'])) ?></td>
                                        <td class="p-4 font-bold text-slate-900"><?= htmlspecialchars($op['op_sistema']) ?></td>
                                        <td class="p-4 uppercase font-bold text-blue-600 text-xs">Fábrica <?= $op['fabrica'] ?> - <?= htmlspecialchars($op['linha_nome'] ?? 'Ñ def') ?></td>
                                        <td class="p-4 text-center text-slate-500 font-bold">
                                            <span class="bg-slate-100 border border-slate-200 px-2 py-1 rounded-md text-xs"><?= $op['qtd_pas'] ?> item(ns)</span>
                                        </td>
                                        <td class="p-4 text-center">
                                            <button onclick="document.getElementById('modal_op_<?= $op['id'] ?>').showModal()" class="bg-amber-100 hover:bg-amber-500 text-amber-800 hover:text-white border border-amber-200 font-bold py-2 px-4 rounded-lg transition-all text-xs shadow-sm flex items-center justify-center gap-1.5 mx-auto">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                                                Conferir Lote
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- MODAL DE SEPARAÇÃO -->
                                    <dialog id="modal_op_<?= $op['id'] ?>" class="p-0 rounded-lg shadow-xl border border-slate-200 w-[95%] max-w-2xl bg-white m-auto backdrop:bg-slate-900/40 backdrop:backdrop-blur-[2px] overflow-hidden">
                                        <div class="flex flex-col h-full w-full max-h-[85vh]">

                                            <div class="px-6 py-5 flex justify-between items-center shrink-0 border-b border-slate-200">
                                                <div>
                                                    <span class="text-slate-400 font-medium text-[11px] uppercase tracking-wide block mb-1">Controle de Almoxarifado</span>
                                                    <h4 class="text-2xl font-semibold text-slate-900 leading-none">OP <?= htmlspecialchars($op['op_sistema']) ?></h4>
                                                </div>
                                                <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-md p-1.5 transition-colors">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                </button>
                                            </div>

                                            <?php if (!empty($op['observacao_almoxarifado'])): ?>
                                                <div class="px-6 py-3 border-b border-slate-200 bg-slate-50 shrink-0 flex items-start gap-2.5">
                                                    <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                                    </svg>
                                                    <div>
                                                        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wide block">Nota do PCP</span>
                                                        <span class="text-sm text-slate-700"><?= htmlspecialchars($op['observacao_almoxarifado']) ?></span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="px-6 py-5 space-y-2 overflow-y-auto grow bg-white">
                                                <p class="text-[11px] font-medium text-slate-400 uppercase tracking-wide mb-2">Produtos para separação</p>
                                                <?php foreach ($op['produtos'] as $p): ?>
                                                    <div class="p-3.5 rounded-lg border border-slate-200 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                                                        <div>
                                                            <div class="text-[11px] text-slate-400">Cód. <?= $p['codigo'] ?></div>
                                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($p['descricao']) ?></div>
                                                        </div>
                                                        <div class="sm:text-right shrink-0">
                                                            <span class="text-[11px] text-slate-400 block">Qtd. solicitada</span>
                                                            <span class="text-base font-semibold text-slate-900"><?= number_format($p['quantidade_planejada'], 0, ',', '.') ?> <span class="text-xs font-normal text-slate-400">un</span></span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <form method="POST" class="bg-white border-t border-slate-200 shrink-0">
                                                <input type="hidden" name="op_id" value="<?= $op['id'] ?>">

                                                <div class="px-6 py-5 grid grid-cols-1 md:grid-cols-2 gap-4">

                                                    <div>
                                                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Responsável logado</label>
                                                        <div class="w-full text-sm px-3.5 py-2.5 border border-slate-200 bg-slate-50 text-slate-500 rounded-lg">
                                                            <?= htmlspecialchars($_SESSION['nome']) ?>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Situação da Separação</label>
                                                        <select name="status_separacao" required class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors text-slate-700 cursor-pointer">
                                                            <option value="separado">Separado</option>
                                                            <option value="pendencia">Pendência</option>
                                                        </select>
                                                    </div>

                                                    <div class="md:col-span-2">
                                                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Anotações (Obrigatório se houver pendência)</label>
                                                        <input type="text" name="observacao_almoxarifado" placeholder="Observações do lote..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors placeholder:text-slate-400">
                                                    </div>
                                                </div>

                                                <div class="px-6 pb-6">
                                                    <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-medium py-3 px-4 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                                        </svg>
                                                        Confirmar e liberar para fábrica
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </dialog>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- ABA 2: PENDÊNCIAS ATIVAS (STATUS: PENDENTE) -->
        <!-- ========================================== -->

            <?php if (empty($ops_pendencias)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
                    <h3 class="text-lg font-bold text-slate-800 mb-1">Tudo certo!</h3>
                    <p class="text-sm text-slate-400 font-medium">Nenhuma OP bloqueada por falta de material no momento.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($ops_pendencias as $pend): ?>
                        <div class="bg-white p-5 rounded-xl shadow-sm border border-rose-200 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <span class="bg-rose-100 text-rose-700 text-[10px] font-bold px-2 py-1 rounded uppercase tracking-widest border border-rose-200">
                                        Falta Material
                                    </span>
                                    <span class="text-xs font-bold text-slate-400"><?= date('d/m', strtotime($pend['data_separacao'])) ?></span>
                                </div>
                                <h4 class="text-xl font-black text-slate-800">OP <?= htmlspecialchars($pend['op_sistema']) ?></h4>
                                <p class="text-xs font-bold text-blue-600 uppercase mb-3">Fábrica <?= $pend['fabrica'] ?> - <?= htmlspecialchars($pend['linha_nome']) ?></p>
                                
                                <div class="bg-slate-50 p-3 rounded border border-slate-100 mb-4 h-20 overflow-y-auto">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Anotações do Problema:</span>
                                    <span class="text-xs font-medium text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($pend['observacao_almoxarifado'] ?: 'Sem anotações detalhadas.')) ?></span>
                                </div>
                            </div>
                            
                            <button onclick="document.getElementById('modal_resolver_<?= $pend['id'] ?>').showModal()" class="w-full bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white border border-rose-200 font-bold py-2.5 rounded-lg text-xs transition-colors flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Resolver Pendência
                            </button>
                        </div>

                        <!-- MODAL DE RESOLUÇÃO -->
                        <dialog id="modal_resolver_<?= $pend['id'] ?>" class="p-0 rounded-2xl shadow-2xl border-0 w-[95%] max-w-md bg-white m-auto backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm">
                            <form method="POST" class="p-6">
                                <input type="hidden" name="resolver_op_id" value="<?= $pend['id'] ?>">
                                <div class="text-center mb-6">
                                    <div class="w-16 h-16 bg-emerald-100 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                    </div>
                                    <h3 class="text-xl font-black text-slate-800">Resolver OP <?= htmlspecialchars($pend['op_sistema']) ?></h3>
                                    <p class="text-xs font-semibold text-slate-500 mt-2">Ao confirmar, o status da OP passará para Liberada e irá para a fábrica.</p>
                                </div>
                                <div class="mb-6">
                                    <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1.5">Nota de Resolução (Opcional)</label>
                                    <textarea name="obs_resolucao" rows="2" placeholder="Ex: Material X chegou e foi entregue..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" onclick="this.closest('dialog').close()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 rounded-lg transition-colors text-sm">Cancelar</button>
                                    <button type="submit" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3 rounded-lg shadow-lg hover:shadow-emerald-500/30 transition-all text-sm">Confirmar Resolução</button>
                                </div>
                            </form>
                        </dialog>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- ABA 3: HISTÓRICO GERAL                     -->
        <!-- ========================================== -->
        <div id="aba_historico" class="hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-5 mt-4">
                <div class="flex items-center gap-2 mb-4">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    <h3 class="text-sm font-bold text-slate-800">Filtrar Histórico</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <input type="text" id="filtro_hist_op" onkeyup="aplicarFiltrosHistorico()" placeholder="Buscar por OP..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                    <input type="text" id="filtro_hist_linha" onkeyup="aplicarFiltrosHistorico()" placeholder="Buscar por Linha..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                    <input type="text" id="filtro_hist_conferente" onkeyup="aplicarFiltrosHistorico()" placeholder="Buscar por Conferente..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden mt-4">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-700">Histórico Completo de Movimentação</h3>
                    <span id="contador_hist_visivel" class="text-xs font-semibold text-slate-400"><?= count($historico_ops) ?> registro(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-white border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                            <tr>
                                <th class="p-4">Data Plan.</th>
                                <th class="p-4">Data Ação Almox.</th>
                                <th class="p-4">OP Sistema</th>
                                <th class="p-4">Destino</th>
                                <th class="p-4">Conferente</th>
                                <th class="p-4">Situação Material</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="corpo_tabela_historico">
                            <?php if (empty($historico_ops)): ?>
                                <tr><td colspan="6" class="p-6 text-center text-slate-400">Nenhum registro encontrado no histórico.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historico_ops as $h):
                                    $status_limpo = normalizaStatus($h['status']);
                                    
                                    // Cores dinâmicas para o Histórico com base no STATUS
                                    if ($status_limpo === 'PENDENTE') {
                                        $bg_badge = 'bg-rose-50 text-rose-700 border-rose-200';
                                        $texto_badge = 'Falta Material';
                                    } elseif ($status_limpo === 'CANCELADO') {
                                        $bg_badge = 'bg-slate-200 text-slate-700 border-slate-300';
                                        $texto_badge = 'Cancelado';
                                    } else {
                                        $bg_badge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                        $texto_badge = 'Material Separado';
                                    }
                                ?>
                                    <tr class="linha-historico hover:bg-slate-50 transition-colors"
                                        data-op="<?= strtolower(htmlspecialchars($h['op_sistema'])) ?>"
                                        data-linha="<?= strtolower(htmlspecialchars($h['linha_nome'] ?? '')) ?>"
                                        data-conferente="<?= strtolower(htmlspecialchars($h['nome_separador'] ?? '')) ?>">
                                        <td class="p-4 text-slate-500 text-xs"><?= date('d/m/Y', strtotime($h['data_planejada'])) ?></td>
                                        <td class="p-4 text-slate-500">
                                            <div class="font-bold text-slate-800"><?= $h['data_separacao'] ? date('d/m/Y', strtotime($h['data_separacao'])) : '-' ?></div>
                                            <div class="text-[12px] font-bold flex items-center gap-1 text-slate-400"><?= $h['data_separacao'] ? date('H:i', strtotime($h['data_separacao'])) : '' ?></div>
                                        </td>
                                        <td class="p-4 font-black text-slate-900"><?= htmlspecialchars($h['op_sistema']) ?></td>
                                        <td class="p-4 text-[11px] font-bold text-blue-600 uppercase">F<?= $h['fabrica'] ?> - <?= htmlspecialchars($h['linha_nome'] ?? 'Ñ def') ?></td>
                                        <td class="p-4 font-bold text-slate-700 text-xs"><?= htmlspecialchars($h['nome_separador'] ?? '—') ?></td>
                                        <td class="p-4">
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest border <?= $bg_badge ?> shadow-sm"><?= $texto_badge ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="msg_hist_vazio" class="hidden p-6 text-center text-slate-400 text-sm">Nenhum registro corresponde aos filtros.</div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function mudarAba(abaId) {
            const abas = ['pendentes', 'pendencias', 'historico'];
            
            abas.forEach(aba => {
                document.getElementById('aba_' + aba).classList.add('hidden');
                document.getElementById('btn_' + aba).classList.remove('border-amber-500', 'text-amber-700');
                document.getElementById('btn_' + aba).classList.add('border-transparent', 'text-slate-500');
            });

            document.getElementById('aba_' + abaId).classList.remove('hidden');
            document.getElementById('btn_' + abaId).classList.remove('border-transparent', 'text-slate-500');
            document.getElementById('btn_' + abaId).classList.add('border-amber-500', 'text-amber-700');
        }

        function aplicarFiltrosHistorico() {
            const termoOp = document.getElementById('filtro_hist_op').value.toLowerCase();
            const termoLinha = document.getElementById('filtro_hist_linha').value.toLowerCase();
            const termoConferente = document.getElementById('filtro_hist_conferente').value.toLowerCase();

            const linhas = document.querySelectorAll('.linha-historico');
            let visiveis = 0;

            linhas.forEach(row => {
                const opRow = row.getAttribute('data-op') || '';
                const linhaRow = row.getAttribute('data-linha') || '';
                const conferenteRow = row.getAttribute('data-conferente') || '';

                if (opRow.includes(termoOp) && linhaRow.includes(termoLinha) && conferenteRow.includes(termoConferente)) {
                    row.style.display = '';
                    visiveis++;
                } else {
                    row.style.display = 'none';
                }
            });

            const contador = document.getElementById('contador_hist_visivel');
            if (contador) contador.textContent = visiveis + ' registro(s)';

            const msgVazio = document.getElementById('msg_hist_vazio');
            if (msgVazio) msgVazio.classList.toggle('hidden', visiveis !== 0 || linhas.length === 0);
        }
    </script>
</body>
</html>