<?php
session_start();
require 'conexao.php';
require_once 'card_op.php';
require_once 'notificacoes.php';

// Validação de Segurança básica: Garante que o usuário está logado
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['ALMOXARIFADO', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

// Rótulos dos motivos de pendência da Formulação -- não são usados nas
// modais deste arquivo (Almoxarifado só tem 1 motivo, texto livre), mas
// render_op_card() precisa disso pra rotular a pendência do OUTRO time
// quando ela aparece no card aqui.
$motivos_pendencia_labels = [
    'MATERIA_PRIMA_INSUFICIENTE' => 'Matéria-prima insuficiente',
    'AGUARDANDO_LABORATORIO'     => 'Aguardando liberação do laboratório',
];

// ========================================================================
// 1. MOTOR DE ATUALIZAÇÃO: CONFIRMAÇÃO DE SEPARAÇÃO / REGISTRO DE PENDÊNCIA
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_id']) && isset($_POST['acao'])) {
    try {
        $op_id      = (int)$_POST['op_id'];
        $usuario_id = $_SESSION['usuario_id'];
        $auxiliares = trim($_POST['auxiliares_separacao'] ?? '');
        $observacao = trim($_POST['observacao_almoxarifado'] ?? '');
        $acao       = $_POST['acao'];

        if ($acao === 'estoque_insuficiente' && $observacao === '') {
            // Trava de segurança do lado do servidor: a observação é obrigatória
            // para registrar uma pendência (o "required" do textarea cobre o
            // caso normal, isso aqui cobre quem tentar contornar via JS/DevTools).
            $mensagem = "Não é possível registrar uma pendência sem descrever o motivo.";
            $tipo_msg = 'erro';
        } else {
            // Dados da OP pra decidir quem notificar e com qual mensagem --
            // buscados ANTES de mexer no status, pra saber se a Formulação
            // já tinha terminado (decide se libera pra AGUARDANDO INICIO).
            $stmt_op = $pdo->prepare("SELECT op_sistema, linha_id, criador_id, data_formulacao FROM ordens_producao WHERE id = ?");
            $stmt_op->execute([$op_id]);
            $dados_op = $stmt_op->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            if ($acao === 'confirmar_separado') {
                // Atualização atômica: se a Formulação JÁ tiver terminado
                // (data_formulacao preenchida), libera direto pra AGUARDANDO
                // INICIO. Senão, fica só esperando a Formulação.
                $stmt_update = $pdo->prepare("
                    UPDATE ordens_producao 
                    SET separador_id = ?, 
                        auxiliares_separacao = ?, 
                        observacao_almoxarifado = ?,
                        data_separacao = NOW(),
                        status = CASE WHEN data_formulacao IS NOT NULL THEN 'AGUARDANDO INICIO' ELSE 'AGUARDANDO FORMULACAO' END
                    WHERE id = ? AND status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO')
                ");
                $stmt_update->execute([$usuario_id, $auxiliares, $observacao, $op_id]);

                $stmt_log = $pdo->prepare("INSERT INTO separacoes_almoxarifado (op_id, usuario_id, status, auxiliares_separacao, observacao) VALUES (?, ?, 'SEPARADO', ?, ?)");
                $stmt_log->execute([$op_id, $usuario_id, $auxiliares, $observacao]);

                // Avisa a Formulação (setor inteiro), o PCP (só quem criou
                // essa OP) e o Admin (tudo) que a separação foi concluída
                // -- independente de já liberar pra produção ou não.
                if ($dados_op) {
                    notificar_setor($pdo, 'FORMULACAO', $op_id, 'OP_SEPARADA', "OP {$dados_op['op_sistema']} separada pelo Almoxarifado.");
                    notificar_setor($pdo, 'ADMIN', $op_id, 'OP_SEPARADA', "OP {$dados_op['op_sistema']} separada pelo Almoxarifado.");
                    if (!empty($dados_op['criador_id'])) {
                        notificar_usuario($pdo, (int)$dados_op['criador_id'], $op_id, 'OP_SEPARADA', "OP {$dados_op['op_sistema']} separada pelo Almoxarifado.");
                    }
                }

                // Se a Formulação já tinha terminado, essa confirmação
                // acabou de liberar a OP -- avisa a linha que já pode
                // iniciar, e o Admin que está acompanhando tudo.
                if ($dados_op && !empty($dados_op['data_formulacao']) && !empty($dados_op['linha_id'])) {
                    notificar_linha($pdo, (int)$dados_op['linha_id'], $op_id, 'OP_LIBERADA', "A OP {$dados_op['op_sistema']} foi liberada e já pode ser iniciada.");
                    notificar_setor($pdo, 'ADMIN', $op_id, 'OP_LIBERADA', "OP {$dados_op['op_sistema']} liberada pra produção.");
                }

                $mensagem = "Separação confirmada com sucesso!";
                $tipo_msg = 'sucesso';
            } elseif ($acao === 'estoque_insuficiente') {
                // NÃO altera o status da OP -- ela continua na fila do Almoxarifado
                // até nova confirmação, com a pendência registrada para
                // acompanhamento (resolvida por fora do sistema).
                $stmt_log = $pdo->prepare("INSERT INTO separacoes_almoxarifado (op_id, usuario_id, status, auxiliares_separacao, observacao) VALUES (?, ?, 'ESTOQUE_INSUFICIENTE', ?, ?)");
                $stmt_log->execute([$op_id, $usuario_id, $auxiliares, $observacao]);

                if ($dados_op && !empty($dados_op['criador_id'])) {
                    notificar_usuario($pdo, (int)$dados_op['criador_id'], $op_id, 'PENDENCIA_ALMOXARIFADO', "Estoque insuficiente pra separar a OP {$dados_op['op_sistema']}: {$observacao}");
                    notificar_setor($pdo, 'ADMIN', $op_id, 'PENDENCIA_ALMOXARIFADO', "Estoque insuficiente pra separar a OP {$dados_op['op_sistema']}: {$observacao}");
                }

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


// Renderiza o par de modais (Conferir Lote + Registrar Pendência) para uma OP.
function render_modais_op(array $op, string $prefix, string $nome_usuario_logado)
{
    $tem_pendencia = ($op['pendencia_almox_status'] ?? null) === 'ESTOQUE_INSUFICIENTE';
    $id_modal_lote = 'modal_op_' . $prefix . $op['id'];
    $id_modal_pendencia = 'modal_pendencia_' . $prefix . $op['id'];
?>
    <dialog id="<?= $id_modal_lote ?>" class="p-0 rounded-lg shadow-xl border border-slate-200 w-[95%] max-w-2xl bg-white m-auto backdrop:bg-slate-900/40 backdrop:backdrop-blur-[2px] overflow-hidden">
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

            <?php if (!empty($op['data_formulacao'])): ?>
                <div class="px-6 py-3 border-b border-emerald-100 bg-emerald-50 shrink-0 flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span class="text-sm text-emerald-700 font-medium">Formulação já concluiu esta OP.</span>
                </div>
            <?php endif; ?>

            <?php if ($tem_pendencia): ?>
                <div class="px-6 py-3 border-b border-rose-200 bg-rose-50 shrink-0 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <div>
                        <span class="text-[11px] font-bold text-rose-600 uppercase tracking-wide block">Pendência em aberto</span>
                        <span class="text-sm text-rose-700"><?= htmlspecialchars($op['pendencia_almox_obs'] ?? '') ?></span>
                        <span class="block text-rose-400 text-xs mt-0.5">Registrada em <?= date('d/m/Y H:i', strtotime($op['pendencia_almox_data'])) ?></span>
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
                            <?= htmlspecialchars($nome_usuario_logado) ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Auxiliares (opcional)</label>
                        <input type="text" name="auxiliares_separacao" placeholder="Ex: Lucas, Ana..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors placeholder:text-slate-400">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Anotações gerais (opcional)</label>
                        <input type="text" name="observacao_almoxarifado" placeholder="Observações do lote..." class="w-full text-sm px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-400 focus:border-slate-400 bg-white transition-colors placeholder:text-slate-400">
                    </div>
                </div>

                <div class="px-6 pb-6 flex flex-col sm:flex-row gap-3">
                    <button type="button" onclick="this.closest('dialog').close(); document.getElementById('<?= $id_modal_pendencia ?>').showModal();" class="w-full sm:w-auto sm:flex-1 order-2 sm:order-1 bg-white border border-rose-300 text-rose-600 hover:bg-rose-50 font-bold py-3 px-4 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        Pendência
                    </button>
                    <button type="submit" name="acao" value="confirmar_separado" class="w-full sm:w-auto sm:flex-1 order-1 sm:order-2 bg-slate-900 hover:bg-slate-800 text-white font-medium py-3 px-4 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Confirmar Separação
                    </button>
                </div>
            </form>
        </div>
    </dialog>

    <!-- MODAL DEDICADA: REGISTRAR PENDÊNCIA (observação obrigatória) -->
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
                <label class="block text-xs font-bold text-slate-600 mb-1.5">Descreva a pendência <span class="text-rose-500">*</span></label>
                <textarea name="observacao_almoxarifado" required rows="4" placeholder="Ex: Faltam 200 tampas modelo X no estoque..." class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-rose-100 focus:border-rose-400 focus:outline-none transition-colors resize-none"></textarea>
                <p class="text-[10px] text-slate-400 mt-1">Essa observação fica visível para o PCP até a pendência ser resolvida.</p>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 hover:bg-slate-50 font-bold py-2.5 rounded-lg text-sm transition-colors">Voltar</button>
                <button type="submit" name="acao" value="estoque_insuficiente" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm shadow-sm transition-colors">Salvar Pendência</button>
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

    // Bolinha vermelha: quantas OPs aguardam especificamente o Almoxarifado
    // (PROGRAMADO ou AGUARDANDO ALMOXARIFADO), por linha e por fábrica --
    // pra sinalizar nas abas quem realmente tem trabalho pendente aqui.
    $linhas_pendentes_almox = array_column(
        $pdo->query("SELECT linha_id, COUNT(*) as qtd FROM ordens_producao WHERE status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO') AND linha_id IS NOT NULL GROUP BY linha_id")->fetchAll(PDO::FETCH_ASSOC),
        'qtd', 'linha_id'
    );
    $fabricas_pendentes_almox = array_column(
        $pdo->query("
            SELECT l.fabrica, COUNT(op.id) as qtd
            FROM ordens_producao op
            JOIN linhas l ON op.linha_id = l.id
            WHERE op.status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO')
            GROUP BY l.fabrica
        ")->fetchAll(PDO::FETCH_ASSOC),
        'qtd', 'fabrica'
    );

    // Mesma paleta de status usada em programacao_pcp.php -- render_op_card()
    // busca cor/label aqui pelo status normalizado da OP.
    $status_meta = [
        'PROGRAMADO'              => ['label' => 'Programado',            'cor' => 'pink'],
        'AGUARDANDO FORMULACAO'   => ['label' => 'Aguard. Formulação',    'cor' => 'purple'],
        'AGUARDANDO ALMOXARIFADO' => ['label' => 'Aguard. Almoxarifado',  'cor' => 'cyan'],
        'AGUARDANDO INICIO'       => ['label' => 'Aguardando Início',     'cor' => 'amber'],
    ];

    // ========================================================================
    // 2. FILA DE SEPARAÇÃO (PROGRAMADO ou AGUARDANDO ALMOXARIFADO), ordenada
    // pela prioridade que o PCP definiu. Traz também a última tentativa da
    // Formulação (sf) -- o card mostra os selos dos 2 setores em qualquer tela.
    // ========================================================================
    $stmt_ops = $pdo->prepare("
        SELECT op.id, op.op_sistema, op.data_planejada, op.observacao_almoxarifado, op.ordem_fila,
               op.data_separacao, op.data_formulacao, op.status,
               l.login as linha_nome, l.fabrica,
               sa.status AS pendencia_almox_status, sa.observacao AS pendencia_almox_obs, sa.created_at AS pendencia_almox_data,
               sf.status AS pendencia_form_status, sf.motivo_pendencia AS pendencia_form_motivo, sf.observacao AS pendencia_form_obs, sf.created_at AS pendencia_form_data
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN (" . sql_ultima_tentativa_almoxarifado() . ") sa ON sa.op_id = op.id
        LEFT JOIN (" . sql_ultima_tentativa_formulacao() . ") sf ON sf.op_id = op.id
        WHERE op.status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO') AND op.linha_id = ?
        ORDER BY op.ordem_fila ASC, op.id ASC
    ");
    $stmt_ops->execute([$linha_selecionada_id]);
    $detalhes_ops = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);
    foreach ($detalhes_ops as &$op) $op['produtos'] = buscar_produtos_op($pdo, $op['id']);
    unset($op);

    $total_pendentes_geral = (int)$pdo->query("SELECT COUNT(*) FROM ordens_producao WHERE status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO')")->fetchColumn();

    // ========================================================================
    // 3. PENDÊNCIAS ABERTAS (global, qualquer linha)
    // ========================================================================
    $stmt_pend = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.observacao_almoxarifado, op.data_separacao, op.data_formulacao, op.status,
               l.login as linha_nome, l.fabrica,
               sa.status AS pendencia_almox_status, sa.observacao AS pendencia_almox_obs, sa.created_at AS pendencia_almox_data,
               sf.status AS pendencia_form_status, sf.motivo_pendencia AS pendencia_form_motivo, sf.observacao AS pendencia_form_obs, sf.created_at AS pendencia_form_data,
               u.nome_completo AS nome_registrou
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        INNER JOIN (" . sql_ultima_tentativa_almoxarifado() . ") sa ON sa.op_id = op.id AND sa.status = 'ESTOQUE_INSUFICIENTE'
        LEFT JOIN (" . sql_ultima_tentativa_formulacao() . ") sf ON sf.op_id = op.id
        LEFT JOIN separacoes_almoxarifado sa_full ON sa_full.op_id = op.id AND sa_full.created_at = sa.created_at
        LEFT JOIN usuarios u ON sa_full.usuario_id = u.id
        WHERE op.status IN ('PROGRAMADO', 'AGUARDANDO ALMOXARIFADO')
        ORDER BY sa.created_at ASC
    ");
    $pendencias_abertas = $stmt_pend->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendencias_abertas as &$op) $op['produtos'] = buscar_produtos_op($pdo, $op['id']);
    unset($op);

    // ABA: Histórico -- OPs que o Almoxarifado já processou.
    $stmt_hist = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.data_separacao,
               l.login as linha_nome,
               u.nome_completo as nome_separador
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.separador_id = u.id
        WHERE op.separador_id IS NOT NULL
        ORDER BY op.data_planejada DESC, op.id DESC
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
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Controle de Separação Física</h2>
                <p class="text-sm text-slate-500 font-medium">Consulte as ordens programadas e confirme a disponibilização dos kits de separação.</p>
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
            <button onclick="mudarAba('pendentes')" id="btn_pendentes" class="px-5 py-3 border-b-2 border-amber-500 text-amber-700 font-bold text-sm transition-colors flex items-center gap-2">
                Fila de Separação
                <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-[10px]"><?= $total_pendentes_geral ?></span>
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
                Histórico de Separação
                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full text-[10px]"><?= count($historico_ops) ?></span>
            </button>
        </div>

        <!-- ============================================================ -->
        <!-- ABA: FILA DE SEPARAÇÃO                                        -->
        <!-- ============================================================ -->
        <div id="aba_pendentes" class="block space-y-4">

            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3 mt-2">
                <?php foreach ($fabricas as $fab): ?>
                    <a href="?fabrica=<?= $fab ?>" class="relative px-4 py-2 rounded-lg text-sm font-bold transition-colors <?= $fab == $fabrica_selecionada ? 'bg-slate-800 text-white shadow-sm' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                        Fábrica <?= $fab ?>
                        <?php if (!empty($fabricas_pendentes_almox[$fab])): ?>
                            <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-rose-500 border-2 border-white" title="<?= $fabricas_pendentes_almox[$fab] ?> OP(s) aguardando o Almoxarifado"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="flex flex-wrap gap-2">
                <?php if (empty($linhas_da_fabrica)): ?>
                    <p class="text-sm text-slate-400 italic">Nenhuma linha cadastrada nesta fábrica.</p>
                <?php endif; ?>
                <?php foreach ($linhas_da_fabrica as $l): ?>
                    <a href="?fabrica=<?= $fabrica_selecionada ?>&linha_id=<?= $l['id'] ?>" class="relative px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors border <?= $l['id'] == $linha_selecionada_id ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-blue-50 hover:border-blue-200' ?>">
                        <?= htmlspecialchars($l['login']) ?>
                        <?php if (!empty($linhas_pendentes_almox[$l['id']])): ?>
                            <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-rose-500 border-2 border-white" title="<?= $linhas_pendentes_almox[$l['id']] ?> OP(s) aguardando o Almoxarifado"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-2.5 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-xs text-blue-800 font-medium">A ordem abaixo é definida pelo PCP na tela de Programação de OPs. Aqui é só consulta — a fila mais no topo é a próxima a ser separada. Essa OP só libera para a produção depois que Almoxarifado <strong>e</strong> Formulação confirmarem, independente da ordem entre os dois.</p>
            </div>

            <?php if (empty($detalhes_ops)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
                    <div class="inline-block bg-emerald-50 rounded-full p-4 mb-3 border border-emerald-100 text-emerald-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-1">Fila Vazia</h3>
                    <p class="text-sm text-slate-400 font-medium">Nenhum kit pendente de separação nesta linha no momento.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($detalhes_ops as $idx => $op): ?>
                        <?php render_op_card($op, $idx, $status_meta, $motivos_pendencia_labels, false, true, false, 'operacional', '', 'Conferir Lote'); ?>
                        <?php render_modais_op($op, '', $_SESSION['nome']); ?>
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
                    <p class="text-sm text-slate-400 font-medium">Todas as OPs programadas estão liberadas para separação normal.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendencias_abertas as $idx => $op): ?>
                    <?php render_op_card($op, $idx, $status_meta, $motivos_pendencia_labels, false, false, true, 'operacional', 'pend_', 'Resolver'); ?>
                    <?php render_modais_op($op, 'pend_', $_SESSION['nome']); ?>
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
                    <input type="text" id="filtro_hist_conferente" onkeyup="aplicarFiltrosHistorico()" placeholder=" Buscar por Conferente..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden mt-4">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-700">Histórico Completo de Liberações</h3>
                    <span id="contador_hist_visivel" class="text-xs font-semibold text-slate-400"><?= count($historico_ops) ?> registro(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-white border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                            <tr>
                                <th class="p-4">Data Programado</th>
                                <th class="p-4">Data Separação</th>
                                <th class="p-4">OP Sistema</th>
                                <th class="p-4">Destino</th>
                                <th class="p-4">Conferente</th>
                                <th class="p-4">Status Geral</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="corpo_tabela_historico">
                            <?php if (empty($historico_ops)): ?>
                                <tr>
                                    <td colspan="6" class="p-6 text-center text-slate-400">Nenhum registro encontrado no histórico.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historico_ops as $h):
                                    $status_limpo = normalizaStatus($h['status']);

                                    $bg_badge = 'bg-slate-100 text-slate-600 border-slate-200';
                                    if ($status_limpo == 'AGUARDANDO FORMULACAO')   $bg_badge = 'bg-purple-50 text-purple-700 border-purple-200';
                                    if ($status_limpo == 'AGUARDANDO ALMOXARIFADO') $bg_badge = 'bg-cyan-50 text-cyan-700 border-cyan-200';
                                    if ($status_limpo == 'AGUARDANDO INICIO')       $bg_badge = 'bg-amber-50 text-amber-700 border-amber-200';
                                    if ($status_limpo == 'PRODUCAO INICIADA')       $bg_badge = 'bg-blue-50 text-blue-700 border-blue-200';
                                    if ($status_limpo == 'PRODUCAO FINALIZADA')     $bg_badge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                    if ($status_limpo == 'PAUSADO')                 $bg_badge = 'bg-red-50 text-red-700 border-red-200';
                                    if ($status_limpo == 'CANCELADO')               $bg_badge = 'bg-slate-200 text-slate-700 border-slate-300';

                                    $status_display = str_replace(['PRODUCAO', 'FORMULACAO'], ['PRODUÇÃO', 'FORMULAÇÃO'], $status_limpo);
                                ?>
                                    <tr class="linha-historico hover:bg-slate-50 transition-colors"
                                        data-op="<?= strtolower(htmlspecialchars($h['op_sistema'])) ?>"
                                        data-linha="<?= strtolower(htmlspecialchars($h['linha_nome'] ?? '')) ?>"
                                        data-conferente="<?= strtolower(htmlspecialchars($h['nome_separador'] ?? '')) ?>">
                                        <td class="p-4 text-slate-500"><?= date('d/m/Y', strtotime($h['data_planejada'])) ?></td>
                                        <td class="p-4 text-slate-500">
                                            <div class="font-bold text-slate-800">
                                                <?= $h['data_separacao'] ? date('d/m/Y', strtotime($h['data_separacao'])) : date('d/m/Y', strtotime($h['data_planejada'])) ?>
                                            </div>
                                            <div class="text-[15px] font-bold uppercase flex items-center gap-1">
                                                <?= $h['data_separacao'] ? date('H:i', strtotime($h['data_separacao'])) : '--:--' ?>
                                            </div>
                                        </td>
                                        <td class="p-4 font-bold text-slate-900"><?= htmlspecialchars($h['op_sistema']) ?></td>
                                        <td class="p-4 text-xs font-bold text-purple-600 uppercase"><?= htmlspecialchars($h['linha_nome'] ?? 'Ñ definida') ?></td>
                                        <td class="p-4 font-bold text-slate-700 capitalize"><?= htmlspecialchars($h['nome_separador'] ?? '—') ?></td>
                                        <td class="p-4">
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest border <?= $bg_badge ?> shadow-sm"><?= $status_display ?></span>
                                        </td>
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
        <?php render_op_card_scripts(); ?>

        function mudarAba(abaId) {
            ['pendentes', 'pendencias', 'historico'].forEach(a => {
                document.getElementById('aba_' + a).classList.add('hidden');
                document.getElementById('btn_' + a).classList.remove('border-amber-500', 'text-amber-700');
                document.getElementById('btn_' + a).classList.add('border-transparent', 'text-slate-500');
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