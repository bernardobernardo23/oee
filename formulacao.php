<?php
session_start();
require 'conexao.php';

// Validação de Segurança básica: Garante que o usuário está logado
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['FORMULACAO', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

// Rótulos amigáveis para os dois motivos de pendência (genéricos)
$motivos_pendencia_labels = [
    'MATERIA_PRIMA_INSUFICIENTE' => 'Matéria-prima insuficiente',
    'AGUARDANDO_LABORATORIO'     => 'Aguardando liberação do laboratório',
];

// ========================================================================
// 1. MOTOR DE ATUALIZAÇÃO: CONFIRMAÇÃO DE FORMULAÇÃO / REGISTRO DE PENDÊNCIA
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_id']) && isset($_POST['acao'])) {
    try {
        $op_id      = (int)$_POST['op_id'];
        $usuario_id = $_SESSION['usuario_id'];
        $auxiliares = trim($_POST['auxiliares_formulacao'] ?? '');
        $observacao = trim($_POST['observacao_formulacao'] ?? '');
        $acao       = $_POST['acao'];
        $motivo     = $_POST['motivo_pendencia'] ?? null;

        if ($acao === 'pendencia' && ($observacao === '' || !array_key_exists($motivo, $motivos_pendencia_labels))) {
            // Trava de segurança do lado do servidor: motivo + observação são
            // obrigatórios para registrar uma pendência.
            $mensagem = "Selecione o motivo e descreva a pendência antes de salvar.";
            $tipo_msg = 'erro';
        } else {
            $pdo->beginTransaction();

            if ($acao === 'confirmar_formulado') {
                // Atualização atômica: se o Almoxarifado JÁ tiver terminado
                // (data_separacao preenchida), libera direto pra AGUARDANDO
                // INICIO. Senão, fica só esperando o Almoxarifado.
                $stmt_update = $pdo->prepare("
                    UPDATE ordens_producao
                    SET formulador_id = ?,
                        auxiliares_formulacao = ?,
                        observacao_formulacao = ?,
                        data_formulacao = NOW(),
                        status = CASE WHEN data_separacao IS NOT NULL THEN 'AGUARDANDO INICIO' ELSE 'AGUARDANDO ALMOXARIFADO' END
                    WHERE id = ? AND status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO')
                ");
                $stmt_update->execute([$usuario_id, $auxiliares, $observacao, $op_id]);

                $stmt_log = $pdo->prepare("INSERT INTO formulacoes (op_id, usuario_id, status, auxiliares_formulacao, observacao) VALUES (?, ?, 'FORMULADO', ?, ?)");
                $stmt_log->execute([$op_id, $usuario_id, $auxiliares, $observacao]);

                $mensagem = "Formulação confirmada com sucesso!";
                $tipo_msg = 'sucesso';
            } elseif ($acao === 'pendencia') {
                // NÃO altera o status da OP -- ela continua na fila da Formulação
                // até uma nova confirmação, com a pendência registrada para
                // acompanhamento (resolvida por fora do sistema).
                $stmt_log = $pdo->prepare("INSERT INTO formulacoes (op_id, usuario_id, status, motivo_pendencia, auxiliares_formulacao, observacao) VALUES (?, ?, 'PENDENCIA', ?, ?, ?)");
                $stmt_log->execute([$op_id, $usuario_id, $motivo, $auxiliares, $observacao]);

                $mensagem = "Pendência registrada. A OP permanece na fila até nova confirmação.";
                $tipo_msg = 'erro';
            }

            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $tipo_msg = 'erro';
        $mensagem = "Erro ao registrar a operação: " . $e->getMessage();
    }
}

function buscar_produtos_op(PDO $pdo, $op_id)
{
    $stmt = $pdo->prepare("SELECT id as op_produto_id, produto_id, quantidade_planejada FROM op_produtos WHERE op_id = ?");
    $stmt->execute([$op_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($produtos as &$prod) {
        $p_info = $pdo->prepare("SELECT codigo, descricao FROM produtos WHERE id = ?");
        $p_info->execute([$prod['produto_id']]);
        $p_data = $p_info->fetch(PDO::FETCH_ASSOC);
        $prod['codigo'] = $p_data['codigo'] ?? 'N/A';
        $prod['descricao'] = $p_data['descricao'] ?? 'Produto não identificado';
    }
    unset($prod);
    return $produtos;
}

// Renderiza o par de modais (Conferir Lote + Registrar Pendência) para uma OP.
function render_modais_op(array $op, string $prefix, string $nome_usuario_logado, array $motivos_labels)
{
    $tem_pendencia = ($op['pendencia_status'] ?? null) === 'PENDENCIA';
    $id_modal_lote = 'modal_op_' . $prefix . $op['id'];
    $id_modal_pendencia = 'modal_pendencia_' . $prefix . $op['id'];
?>
    <dialog id="<?= $id_modal_lote ?>" class="p-0 rounded-lg shadow-xl border border-slate-200 w-[95%] max-w-2xl bg-white m-auto backdrop:bg-slate-900/40 backdrop:backdrop-blur-[2px] overflow-hidden">
        <div class="flex flex-col h-full w-full max-h-[85vh]">

            <div class="px-6 py-5 flex justify-between items-center shrink-0 border-b border-slate-200">
                <div>
                    <span class="text-slate-400 font-medium text-[11px] uppercase tracking-wide block mb-1">Controle de Formulação</span>
                    <h4 class="text-2xl font-semibold text-slate-900 leading-none">OP <?= htmlspecialchars($op['op_sistema']) ?></h4>
                </div>
                <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-md p-1.5 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <?php if (!empty($op['data_separacao'])): ?>
                <div class="px-6 py-3 border-b border-emerald-100 bg-emerald-50 shrink-0 flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span class="text-sm text-emerald-700 font-medium">Almoxarifado já concluiu a separação desta OP.</span>
                </div>
            <?php endif; ?>

            <?php if ($tem_pendencia): ?>
                <div class="px-6 py-3 border-b border-rose-200 bg-rose-50 shrink-0 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <div>
                        <span class="text-[11px] font-bold text-rose-600 uppercase tracking-wide block">Pendência em aberto — <?= htmlspecialchars($motivos_labels[$op['pendencia_motivo']] ?? 'Motivo não informado') ?></span>
                        <span class="text-sm text-rose-700"><?= htmlspecialchars($op['pendencia_obs'] ?? '') ?></span>
                        <span class="block text-rose-400 text-xs mt-0.5">Registrada em <?= date('d/m/Y H:i', strtotime($op['pendencia_data'])) ?></span>
                    </div>
                </div>
            <?php endif; ?>

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
                <p class="text-[11px] font-medium text-slate-400 uppercase tracking-wide mb-2">Produtos a formular</p>
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
                            <?= htmlspecialchars($nome_usuario_logado) ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Auxiliares (opcional)</label>
                        <input type="text" name="auxiliares_formulacao" placeholder="Ex: Lucas, Ana..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors placeholder:text-slate-400">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Anotações gerais (opcional)</label>
                        <input type="text" name="observacao_formulacao" placeholder="Observações do lote..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors placeholder:text-slate-400">
                    </div>
                </div>

                <div class="px-6 pb-6 flex flex-col sm:flex-row gap-3">
                    <button type="button" onclick="this.closest('dialog').close(); document.getElementById('<?= $id_modal_pendencia ?>').showModal();" class="w-full sm:w-auto sm:flex-1 order-2 sm:order-1 bg-white border border-rose-300 text-rose-600 hover:bg-rose-50 font-bold py-3 px-4 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        Pendência
                    </button>
                    <button type="submit" name="acao" value="confirmar_formulado" class="w-full sm:w-auto sm:flex-1 order-1 sm:order-2 bg-slate-900 hover:bg-slate-800 text-white font-medium py-3 px-4 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Confirmar Formulação
                    </button>
                </div>
            </form>
        </div>
    </dialog>

    <!-- MODAL DEDICADA: REGISTRAR PENDÊNCIA (motivo + observação obrigatórios) -->
    <dialog id="<?= $id_modal_pendencia ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm m-auto overflow-hidden">
        <div class="bg-rose-50 border-b border-rose-100 p-5 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Registrar Pendência</h3>
                    <p class="text-xs font-medium text-slate-400">OP <?= htmlspecialchars($op['op_sistema']) ?></p>
                </div>
            </div>
            <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 hover:bg-white/60 rounded-lg p-1.5 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-2">Motivo da pendência <span class="text-rose-500">*</span></label>
                <div class="space-y-2">
                    <?php foreach ($motivos_labels as $valor => $label): ?>
                        <label class="flex items-center gap-2.5 border border-slate-200 rounded-lg px-3.5 py-2.5 cursor-pointer hover:bg-slate-50 has-[:checked]:border-rose-300 has-[:checked]:bg-rose-50 transition-colors">
                            <input type="radio" name="motivo_pendencia" value="<?= $valor ?>" required class="accent-rose-600">
                            <span class="text-sm font-medium text-slate-700"><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5">Descreva a pendência <span class="text-rose-500">*</span></label>
                <textarea name="observacao_formulacao" required rows="4" placeholder="Ex: Falta resina X para completar o lote..." class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-rose-100 focus:border-rose-400 focus:outline-none transition-colors resize-none"></textarea>
                <p class="text-[10px] text-slate-400 mt-1">Essa observação fica visível para o PCP até a pendência ser resolvida.</p>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 hover:bg-slate-50 font-bold py-2.5 rounded-lg text-sm transition-colors">Voltar</button>
                <button type="submit" name="acao" value="pendencia" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm shadow-sm transition-colors">Salvar Pendência</button>
            </div>
        </form>
    </dialog>
<?php
}

try {
    // ---- Fábricas e linhas (para as abas) ----
    $fabricas = $pdo->query("SELECT DISTINCT fabrica FROM linhas WHERE fabrica > 0 ORDER BY fabrica ASC")->fetchAll(PDO::FETCH_COLUMN);
    $fabrica_selecionada = isset($_GET['fabrica']) ? (int)$_GET['fabrica'] : ($fabricas[0] ?? 0);

    $stmt_linhas_fab = $pdo->prepare("SELECT id, login FROM linhas WHERE fabrica = ? ORDER BY login ASC");
    $stmt_linhas_fab->execute([$fabrica_selecionada]);
    $linhas_da_fabrica = $stmt_linhas_fab->fetchAll(PDO::FETCH_ASSOC);

    $linha_selecionada_id = isset($_GET['linha_id']) ? (int)$_GET['linha_id'] : ($linhas_da_fabrica[0]['id'] ?? 0);
    $pertence = false;
    foreach ($linhas_da_fabrica as $l) if ($l['id'] == $linha_selecionada_id) $pertence = true;
    if (!$pertence) $linha_selecionada_id = $linhas_da_fabrica[0]['id'] ?? 0;

    $sql_ultima_tentativa = "
        SELECT sf1.op_id, sf1.status, sf1.motivo_pendencia, sf1.observacao, sf1.created_at
        FROM formulacoes sf1
        INNER JOIN (SELECT op_id, MAX(id) AS max_id FROM formulacoes GROUP BY op_id) latest
            ON sf1.id = latest.max_id
    ";

    // ========================================================================
    // 2. FILA DE FORMULAÇÃO (PROGRAMADO ou AGUARDANDO FORMULACAO), ordenada
    // pela prioridade que o PCP definiu (mesma ordem_fila do Almoxarifado).
    // ========================================================================
    $stmt_ops = $pdo->prepare("
        SELECT op.id, op.op_sistema, op.data_planejada, op.observacao_almoxarifado, op.ordem_fila,
               op.data_separacao, op.status,
               l.login as linha_nome, l.fabrica,
               sf.status AS pendencia_status, sf.motivo_pendencia AS pendencia_motivo, sf.observacao AS pendencia_obs, sf.created_at AS pendencia_data
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN ($sql_ultima_tentativa) sf ON sf.op_id = op.id
        WHERE op.status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO') AND op.linha_id = ?
        ORDER BY op.ordem_fila ASC, op.id ASC
    ");
    $stmt_ops->execute([$linha_selecionada_id]);
    $detalhes_ops = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);
    foreach ($detalhes_ops as &$op) $op['produtos'] = buscar_produtos_op($pdo, $op['id']);
    unset($op);

    $total_pendentes_geral = (int)$pdo->query("SELECT COUNT(*) FROM ordens_producao WHERE status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO')")->fetchColumn();

    // ========================================================================
    // 3. PENDÊNCIAS ABERTAS (global, qualquer linha)
    // ========================================================================
    $stmt_pend = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.observacao_almoxarifado, op.data_separacao,
               l.login as linha_nome, l.fabrica,
               sf.status AS pendencia_status, sf.motivo_pendencia AS pendencia_motivo, sf.observacao AS pendencia_obs, sf.created_at AS pendencia_data,
               u.nome_completo AS nome_registrou
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        INNER JOIN ($sql_ultima_tentativa) sf ON sf.op_id = op.id AND sf.status = 'PENDENCIA'
        LEFT JOIN formulacoes sf_full ON sf_full.op_id = op.id AND sf_full.created_at = sf.created_at
        LEFT JOIN usuarios u ON sf_full.usuario_id = u.id
        WHERE op.status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO')
        ORDER BY sf.created_at ASC
    ");
    $pendencias_abertas = $stmt_pend->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendencias_abertas as &$op) $op['produtos'] = buscar_produtos_op($pdo, $op['id']);
    unset($op);

    // ABA: Histórico -- OPs que a Formulação já processou, qualquer status atual.
    $stmt_hist = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.data_formulacao,
               l.login as linha_nome,
               u.nome_completo as nome_formulador
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.formulador_id = u.id
        WHERE op.formulador_id IS NOT NULL
        ORDER BY op.data_planejada DESC, op.id DESC
    ");
    $historico_ops = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar dados da formulação: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulação - Preparo de Matéria-Prima</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'] } } } }
    </script>
    <style>
        dialog[open] { display: flex; flex-direction: column; }
    </style>
</head>

<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <?php include 'header.php'; ?>

    <div class="max-w-6xl mx-auto px-4 space-y-6">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Controle de Formulação</h2>
                <p class="text-sm text-slate-500 font-medium">Consulte as ordens programadas e confirme o preparo da matéria-prima.</p>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-lg shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?php if ($tipo_msg == 'sucesso'): ?>
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                <?php else: ?>
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                <?php endif; ?>
                <?= $mensagem ?>
            </div>
        <?php endif; ?>

        <div class="flex border-b border-slate-200">
            <button onclick="mudarAba('pendentes')" id="btn_pendentes" class="px-5 py-3 border-b-2 border-purple-500 text-purple-700 font-bold text-sm transition-colors flex items-center gap-2">
                Fila de Formulação
                <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full text-[10px]"><?= $total_pendentes_geral ?></span>
            </button>
            <button onclick="mudarAba('pendencias')" id="btn_pendencias" class="px-5 py-3 border-b-2 border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm transition-colors flex items-center gap-2">
                Pendências
                <?php if (count($pendencias_abertas) > 0): ?>
                    <span class="bg-rose-100 text-rose-700 px-2 py-0.5 rounded-full text-[10px]"><?= count($pendencias_abertas) ?></span>
                <?php else: ?>
                    <span class="bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full text-[10px]">0</span>
                <?php endif; ?>
            </button>
            <button onclick="mudarAba('historico')" id="btn_historico" class="px-5 py-3 border-b-2 border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm transition-colors flex items-center gap-2">
                Histórico
                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full text-[10px]"><?= count($historico_ops) ?></span>
            </button>
        </div>

        <!-- ============================================================ -->
        <!-- ABA: FILA DE FORMULAÇÃO                                       -->
        <!-- ============================================================ -->
        <div id="aba_pendentes" class="block space-y-4">

            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3 mt-2">
                <?php foreach ($fabricas as $fab): ?>
                    <a href="?fabrica=<?= $fab ?>" class="px-4 py-2 rounded-lg text-sm font-bold transition-colors <?= $fab == $fabrica_selecionada ? 'bg-slate-800 text-white shadow-sm' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                        Fábrica <?= $fab ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="flex flex-wrap gap-2">
                <?php if (empty($linhas_da_fabrica)): ?>
                    <p class="text-sm text-slate-400 italic">Nenhuma linha cadastrada nesta fábrica.</p>
                <?php endif; ?>
                <?php foreach ($linhas_da_fabrica as $l): ?>
                    <a href="?fabrica=<?= $fabrica_selecionada ?>&linha_id=<?= $l['id'] ?>" class="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors border <?= $l['id'] == $linha_selecionada_id ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-blue-50 hover:border-blue-200' ?>">
                        <?= htmlspecialchars($l['login']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-2.5 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-xs text-blue-800 font-medium">A ordem abaixo é definida pelo PCP na tela de Programação de OPs. Aqui é só consulta — a fila mais no topo é a próxima a ser formulada. Essa OP só libera para a produção depois que Formulação <strong>e</strong> Almoxarifado confirmarem, independente da ordem entre os dois.</p>
            </div>

            <?php if (empty($detalhes_ops)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
                    <div class="inline-block bg-emerald-50 rounded-full p-4 mb-3 border border-emerald-100 text-emerald-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-1">Fila Vazia</h3>
                    <p class="text-sm text-slate-400 font-medium">Nenhuma OP pendente de formulação nesta linha no momento.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($detalhes_ops as $idx => $op):
                        $tem_pendencia = ($op['pendencia_status'] ?? null) === 'PENDENCIA';
                        $sep_ok = !empty($op['data_separacao']);
                    ?>
                        <div class="bg-white border <?= $tem_pendencia ? 'border-rose-300' : 'border-slate-200' ?> rounded-xl p-4 shadow-sm">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 font-bold text-xs shrink-0 mt-0.5">
                                    <?= $idx + 1 ?>
                                </div>

                                <div class="flex-1 min-w-0 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-slate-900 text-sm">OP <?= htmlspecialchars($op['op_sistema']) ?></span>
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border bg-slate-100 text-slate-600 border-slate-200">Fáb. <?= $op['fabrica'] ?> · <?= htmlspecialchars($op['linha_nome']) ?></span>
                                        <span class="text-[11px] text-slate-400 font-medium">Planejada <?= date('d/m/Y', strtotime($op['data_planejada'])) ?></span>
                                        <?php if ($sep_ok): ?>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border bg-emerald-100 text-emerald-700 border-emerald-200">✓ Almoxarifado OK</span>
                                        <?php endif; ?>
                                        <?php if ($tem_pendencia): ?>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border bg-rose-100 text-rose-700 border-rose-200">Pendência aberta</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($op['observacao_almoxarifado'])): ?>
                                        <div class="text-[11px] text-slate-500 bg-slate-50 border border-slate-100 rounded-lg px-3 py-1.5">
                                            <span class="font-semibold text-slate-600">Obs. PCP:</span> <?= htmlspecialchars($op['observacao_almoxarifado']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="space-y-1 pt-1">
                                        <?php foreach ($op['produtos'] as $prod): ?>
                                            <div class="flex items-center justify-between text-xs bg-slate-50 rounded-lg px-3 py-1.5 border border-slate-100">
                                                <span class="text-slate-600"><span class="font-bold text-slate-700">[<?= htmlspecialchars($prod['codigo']) ?>]</span> <?= htmlspecialchars($prod['descricao']) ?></span>
                                                <span class="font-bold text-slate-700 shrink-0 ml-2"><?= number_format($prod['quantidade_planejada'], 0, ',', '.') ?> un</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <button onclick="document.getElementById('modal_op_<?= $op['id'] ?>').showModal()" class="<?= $tem_pendencia ? 'bg-rose-100 hover:bg-rose-500 text-rose-800 border-rose-200' : 'bg-purple-100 hover:bg-purple-500 text-purple-800 border-purple-200' ?> hover:text-white border font-bold py-2 px-4 rounded-lg transition-all text-xs shadow-sm flex items-center justify-center gap-1.5 shrink-0 self-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M5 8h14M5 21h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                    Conferir Lote
                                </button>
                            </div>
                        </div>

                        <?php render_modais_op($op, '', $_SESSION['nome'], $motivos_pendencia_labels); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- ABA: PENDÊNCIAS ABERTAS (global, qualquer linha)               -->
        <!-- ============================================================ -->
        <div id="aba_pendencias" class="hidden space-y-4 mt-4">
            <?php if (empty($pendencias_abertas)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
                    <div class="inline-block bg-emerald-50 rounded-full p-4 mb-3 border border-emerald-100 text-emerald-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-1">Nenhuma Pendência em Aberto</h3>
                    <p class="text-sm text-slate-400 font-medium">Todas as OPs programadas estão liberadas para formulação normal.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendencias_abertas as $op): ?>
                    <div class="bg-white border border-rose-300 rounded-xl p-4 shadow-sm">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 flex items-center justify-center rounded-full bg-rose-100 text-rose-600 shrink-0 mt-0.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>

                            <div class="flex-1 min-w-0 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-slate-900 text-sm">OP <?= htmlspecialchars($op['op_sistema']) ?></span>
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border bg-slate-100 text-slate-600 border-slate-200">Fáb. <?= $op['fabrica'] ?> · <?= htmlspecialchars($op['linha_nome']) ?></span>
                                    <span class="text-[11px] text-slate-400 font-medium">Planejada <?= date('d/m/Y', strtotime($op['data_planejada'])) ?></span>
                                </div>

                                <div class="bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
                                    <span class="text-[10px] font-bold text-rose-600 uppercase tracking-wide block"><?= htmlspecialchars($motivos_pendencia_labels[$op['pendencia_motivo']] ?? 'Motivo não informado') ?></span>
                                    <span class="text-sm text-rose-700"><?= htmlspecialchars($op['pendencia_obs'] ?? '') ?></span>
                                    <span class="block text-rose-400 text-xs mt-1">
                                        Registrada por <?= htmlspecialchars($op['nome_registrou'] ?? 'Desconhecido') ?> em <?= date('d/m/Y H:i', strtotime($op['pendencia_data'])) ?>
                                    </span>
                                </div>
                            </div>

                            <button onclick="document.getElementById('modal_op_pend_<?= $op['id'] ?>').showModal()" class="bg-rose-100 hover:bg-rose-500 text-rose-800 hover:text-white border border-rose-200 font-bold py-2 px-4 rounded-lg transition-all text-xs shadow-sm flex items-center justify-center gap-1.5 shrink-0 self-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M5 8h14M5 21h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                Resolver
                            </button>
                        </div>
                    </div>

                    <?php render_modais_op($op, 'pend_', $_SESSION['nome'], $motivos_pendencia_labels); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- ABA: HISTÓRICO                                                -->
        <!-- ============================================================ -->
        <div id="aba_historico" class="hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-5 mt-4">
                <div class="flex items-center gap-2 mb-4">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                    </svg>
                    <h3 class="text-sm font-bold text-slate-800">Filtrar Histórico</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <input type="text" id="filtro_hist_op" onkeyup="aplicarFiltrosHistorico()" placeholder=" Buscar por OP..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                    <input type="text" id="filtro_hist_linha" onkeyup="aplicarFiltrosHistorico()" placeholder=" Buscar por Linha/Destino..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                    <input type="text" id="filtro_hist_conferente" onkeyup="aplicarFiltrosHistorico()" placeholder=" Buscar por Formulador..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden mt-4">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-700">Histórico Completo de Formulações</h3>
                    <span id="contador_hist_visivel" class="text-xs font-semibold text-slate-400"><?= count($historico_ops) ?> registro(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-white border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                            <tr>
                                <th class="p-4">Data Programado</th>
                                <th class="p-4">Data Formulação</th>
                                <th class="p-4">OP Sistema</th>
                                <th class="p-4">Linha</th>
                                <th class="p-4">Formulador</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="corpo_tabela_historico">
                            <?php if (empty($historico_ops)): ?>
                                <tr>
                                    <td colspan="5" class="p-6 text-center text-slate-400">Nenhum registro encontrado no histórico.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historico_ops as $h): ?>
                                    <tr class="linha-historico hover:bg-slate-50 transition-colors"
                                        data-op="<?= strtolower(htmlspecialchars($h['op_sistema'])) ?>"
                                        data-linha="<?= strtolower(htmlspecialchars($h['linha_nome'] ?? '')) ?>"
                                        data-conferente="<?= strtolower(htmlspecialchars($h['nome_formulador'] ?? '')) ?>">
                                        <td class="p-4 text-slate-500"><?= date('d/m/Y', strtotime($h['data_planejada'])) ?></td>
                                        <td class="p-4 text-slate-500">
                                            <div class="font-bold text-slate-800">
                                                <?= $h['data_formulacao'] ? date('d/m/Y', strtotime($h['data_formulacao'])) : '—' ?>
                                            </div>
                                            <div class="text-[13px] font-bold uppercase">
                                                <?= $h['data_formulacao'] ? date('H:i', strtotime($h['data_formulacao'])) : '' ?>
                                            </div>
                                        </td>
                                        <td class="p-4 font-bold text-slate-900"><?= htmlspecialchars($h['op_sistema']) ?></td>
                                        <td class="p-4 text-xs font-bold text-purple-600 uppercase"><?= htmlspecialchars($h['linha_nome'] ?? 'Ñ definida') ?></td>
                                        <td class="p-4 font-bold text-slate-700 capitalize"><?= htmlspecialchars($h['nome_formulador'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="msg_hist_vazio" class="hidden p-6 text-center text-slate-400 text-sm">Nenhum registro corresponde aos filtros aplicados.</div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function mudarAba(abaId) {
            ['pendentes', 'pendencias', 'historico'].forEach(a => {
                document.getElementById('aba_' + a).classList.add('hidden');
                document.getElementById('btn_' + a).classList.remove('border-purple-500', 'text-purple-700');
                document.getElementById('btn_' + a).classList.add('border-transparent', 'text-slate-500');
            });

            document.getElementById('aba_' + abaId).classList.remove('hidden');
            document.getElementById('btn_' + abaId).classList.remove('border-transparent', 'text-slate-500');
            document.getElementById('btn_' + abaId).classList.add('border-purple-500', 'text-purple-700');
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

                const match = opRow.includes(termoOp) && linhaRow.includes(termoLinha) && conferenteRow.includes(termoConferente);
                row.style.display = match ? '' : 'none';
                if (match) visiveis++;
            });

            const contador = document.getElementById('contador_hist_visivel');
            if (contador) contador.textContent = visiveis + ' registro(s)';

            const msgVazio = document.getElementById('msg_hist_vazio');
            if (msgVazio) msgVazio.classList.toggle('hidden', visiveis !== 0 || linhas.length === 0);
        }
    </script>
</body>

</html>