<?php
// ================================================================
// CARD COMPARTILHADO DE ORDEM DE PRODUÇÃO (Modelo C)
// ================================================================
// Usado por: programacao_pcp.php, separacao_almoxarifado.php, formulacao.php
// Qualquer ajuste visual (cor, espaçamento, tipografia) feito neste
// arquivo reflete nas 3 telas. Não duplique esse card em nenhum
// arquivo novo -- dê require_once 'card_op.php' e chame
// render_op_card() a partir daqui.
// ================================================================

if (!function_exists('normalizaStatus')) {
    function normalizaStatus($str)
    {
        $str = strtoupper(trim($str));
        return str_replace(
            ['Ç', 'Ã', 'Á', 'À', 'É', 'Í', 'Ó', 'Ú', 'Â', 'Ê'],
            ['C', 'A', 'A', 'A', 'E', 'I', 'O', 'U', 'A', 'E'],
            $str
        );
    }
}

if (!function_exists('buscar_produtos_op')) {
    function buscar_produtos_op(PDO $pdo, $op_id)
    {
        $stmt = $pdo->prepare("SELECT op_p.id as op_produto_id, op_p.produto_id, p.codigo, p.descricao, op_p.quantidade_planejada, op_p.quantidade_apontada FROM op_produtos op_p JOIN produtos p ON op_p.produto_id = p.id WHERE op_p.op_id = ?");
        $stmt->execute([$op_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ----------------------------------------------------------------
// Subqueries reaproveitáveis: "última tentativa registrada de cada
// setor". Retornam só o SELECT interno -- quem chama decide se usa
// LEFT JOIN (pra só enriquecer com o status do outro time) ou INNER
// JOIN com filtro de status (pra listar só pendências abertas).
// Uso típico:
//   LEFT JOIN (" . sql_ultima_tentativa_almoxarifado() . ") sa ON sa.op_id = op.id
//   LEFT JOIN (" . sql_ultima_tentativa_formulacao() . ") sf ON sf.op_id = op.id
// Os aliases de coluna (pendencia_almox_*, pendencia_form_*) são o
// contrato que render_op_card() espera -- sempre nomeie assim na
// query que chama essas subqueries.
// ----------------------------------------------------------------

if (!function_exists('sql_ultima_tentativa_almoxarifado')) {
    function sql_ultima_tentativa_almoxarifado()
    {
        return "
            SELECT sa1.op_id, sa1.status, sa1.observacao, sa1.created_at
            FROM separacoes_almoxarifado sa1
            INNER JOIN (SELECT op_id, MAX(id) AS max_id FROM separacoes_almoxarifado GROUP BY op_id) latest_sa
                ON sa1.id = latest_sa.max_id
        ";
    }
}

if (!function_exists('sql_ultima_tentativa_formulacao')) {
    function sql_ultima_tentativa_formulacao()
    {
        return "
            SELECT sf1.op_id, sf1.status, sf1.motivo_pendencia, sf1.observacao, sf1.created_at
            FROM formulacoes sf1
            INNER JOIN (SELECT op_id, MAX(id) AS max_id FROM formulacoes GROUP BY op_id) latest_sf
                ON sf1.id = latest_sf.max_id
        ";
    }
}


// ----------------------------------------------------------------
// SCRIPT COMPARTILHADO (mantido por compatibilidade -- os 3 arquivos
// chamam essa função dentro do próprio <script>; hoje ela não emite
// nada porque voltamos pro card sem painel expansível).
// ----------------------------------------------------------------
if (!function_exists('render_op_card_scripts')) {
    function render_op_card_scripts()
    {
        // Sem JS necessário na versão atual do card.
    }
}

// ----------------------------------------------------------------
// O CARD EM SI: estrutura "clássica" (ícone de posição à esquerda,
// conteúdo fluindo ao lado, ações à direita) -- igual ao formato
// original, só que com a OP, a data e a quantidade de cada produto
// em destaque tipográfico bem maior/mais forte.
// ----------------------------------------------------------------
// $op              : linha do array de OPs (precisa ter 'produtos' já carregado)
// $idx             : índice do loop, usado pro número de posição
// $status_meta     : mapa status => ['label' => ..., 'cor' => ...]
// $motivos_labels  : mapa motivo_pendencia (Formulação) => texto amigável
// $draggable       : true só na Esteira do PCP (permite arrastar)
// $mostrar_posicao : true quando a posição numérica É real (fila de
//                    uma única linha); false quando os cards vêm
//                    misturados de várias linhas (Visão Global,
//                    abas de Pendências) -- nesse caso mostra um
//                    ícone neutro em vez de um número que mentiria
// $show_linha_badge: mostra o badge "Fáb X · linha" (telas cross-linha)
// $contexto        : 'pcp' (botões editar/cancelar) ou 'operacional'
//                    (botão único, ex: "Conferir Lote"/"Resolver")
// $modal_prefix    : prefixo do ID da modal a abrir no contexto
//                    operacional (mesma OP pode ter modal_op_ID na
//                    Fila e modal_op_pend_ID nas Pendências)
// $botao_texto     : texto do botão único no contexto operacional
// ----------------------------------------------------------------
// ----------------------------------------------------------------
// O CARD EM SI: estrutura visual mais leve, com melhor hierarquia
// tipográfica (menos font-black, mais font-medium/semibold).
// ----------------------------------------------------------------
if (!function_exists('render_op_card')) {
    function render_op_card(
        array $op,
        int $idx,
        array $status_meta,
        array $motivos_labels,
        bool $draggable,
        bool $mostrar_posicao,
        bool $show_linha_badge,
        string $contexto = 'pcp',
        string $modal_prefix = '',
        string $botao_texto = 'Conferir Lote'
    ) {
        $st_norm = normalizaStatus($op['status']);
        $cor_st = $status_meta[$st_norm]['cor'] ?? 'slate';
        $label_st = $status_meta[$st_norm]['label'] ?? $st_norm;

        $sep_ok = !empty($op['data_separacao']);
        $form_ok = !empty($op['data_formulacao']);
        $tem_pend_almox = ($op['pendencia_almox_status'] ?? null) === 'ESTOQUE_INSUFICIENTE';
        $tem_pend_form  = ($op['pendencia_form_status'] ?? null) === 'PENDENCIA';
        $tem_pendencia  = $tem_pend_almox || $tem_pend_form;
        $mostra_acoes = $contexto !== 'pcp' || !in_array($st_norm, ['PRODUCAO FINALIZADA', 'CANCELADO']);
?>
    <div class="op-card group <?= $show_linha_badge ? 'card-global' : '' ?> bg-white border <?= $tem_pendencia ? 'border-rose-300' : 'border-slate-200' ?> rounded-xl p-4 shadow-sm hover:border-blue-300 transition-colors"
        draggable="<?= $draggable ? 'true' : 'false' ?>"
        data-op-id="<?= $op['id'] ?>"
        data-op="<?= strtolower(htmlspecialchars($op['op_sistema'])) ?>"
        data-prod="<?= strtolower(htmlspecialchars($op['busca_produtos'] ?? '')) ?>"
        data-status="<?= htmlspecialchars($op['status']) ?>">

        <div class="flex items-start gap-3">
            <!-- posição / ícone neutro -->
            <div class="w-9 h-9 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0 mt-0.5 <?= $draggable ? 'cursor-grab active:cursor-grabbing' : '' ?>">
                <?php if ($mostrar_posicao): ?>
                    <span class="text-sm font-semibold text-slate-500 posicao-badge"><?= $idx + 1 ?></span>
                <?php else: ?>
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"></path></svg>
                <?php endif; ?>
            </div>

            <div class="flex-1 min-w-0">
                <!-- OP e data -->
                <div class="flex flex-wrap items-center justify-between gap-2 mb-1.5">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xl font-bold text-slate-800 tracking-tight">OP <?= htmlspecialchars($op['op_sistema']) ?></span>
                        <span class="bg-<?= $cor_st ?>-50 text-<?= $cor_st ?>-700 border border-<?= $cor_st ?>-200 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded"><?= $label_st ?></span>
                        <?php if ($show_linha_badge): ?>
                            <span class="bg-slate-50 text-slate-500 text-[10px] font-medium uppercase tracking-wide px-2 py-0.5 rounded border border-slate-200">Fáb <?= $op['fabrica'] ?> · <?= htmlspecialchars($op['linha_nome']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-sm font-medium text-slate-500 shrink-0 flex items-center gap-1">
                        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <?= date('d/m/Y', strtotime($op['data_planejada'])) ?>
                    </span>
                </div>

                <!-- selos de progresso -->
                <div class="flex items-center gap-2 flex-wrap mb-3">
                    <span class="text-[11px] font-semibold uppercase tracking-wide <?= $sep_ok ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $sep_ok ? '✓' : '···' ?> Separação</span>
                    <span class="text-[11px] text-slate-300">|</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wide <?= $form_ok ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $form_ok ? '✓' : '···' ?> Formulação</span>
                </div>

                <?php if ($tem_pend_almox): ?>
                    <div class="bg-rose-50/50 border border-rose-200 rounded-lg px-3 py-2 mb-2">
                        <p class="text-xs text-rose-700"><span class="font-semibold uppercase tracking-wide">Pendência Almoxarifado:</span> Estoque insuficiente<?= !empty($op['pendencia_almox_obs']) ? ' — <span class="font-medium">' . htmlspecialchars($op['pendencia_almox_obs']) . '</span>' : '' ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($tem_pend_form): ?>
                    <div class="bg-rose-50/50 border border-rose-200 rounded-lg px-3 py-2 mb-2">
                        <p class="text-xs text-rose-700"><span class="font-semibold uppercase tracking-wide">Pendência Formulação:</span> <?= htmlspecialchars($motivos_labels[$op['pendencia_form_motivo']] ?? 'Motivo não informado') ?><?= !empty($op['pendencia_form_obs']) ? ' — <span class="font-medium">' . htmlspecialchars($op['pendencia_form_obs']) . '</span>' : '' ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($op['observacao_almoxarifado'])): ?>
                    <p class="text-xs text-slate-600 mb-2"><span class="font-semibold text-slate-700">Obs. PCP:</span> <?= htmlspecialchars($op['observacao_almoxarifado']) ?></p>
                <?php endif; ?>

                <!-- produtos -->
                <div class="space-y-1.5 mt-1">
                    <?php foreach ($op['produtos'] as $prod): ?>
                        <div class="flex items-center justify-between gap-3 bg-slate-50/50 rounded-lg px-3 py-2 border border-slate-100/80">
                            <span class="text-sm text-slate-700 min-w-0 truncate"><span class="font-medium text-slate-400">#<?= htmlspecialchars($prod['codigo']) ?></span> <?= htmlspecialchars($prod['descricao']) ?></span>
                            <span class="text-base font-bold text-slate-800 shrink-0"><?= number_format($prod['quantidade_planejada'], 0, ',', '.') ?> <span class="text-xs font-medium text-slate-400">un</span></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($mostra_acoes): ?>
                <div class="shrink-0 flex flex-col gap-2" draggable="false">
                    <?php if ($contexto === 'pcp'): ?>
                        <button type="button" onclick="document.getElementById('modal_editar_op_<?= $op['id'] ?>').showModal()" title="Editar" class="w-9 h-9 rounded-lg border border-slate-200 text-slate-400 hover:bg-slate-50 hover:text-blue-600 hover:border-blue-300 transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                        </button>
                        <button type="button" onclick="document.getElementById('modal_cancelar_op_<?= $op['id'] ?>').showModal()" title="Cancelar" class="w-9 h-9 rounded-lg border border-slate-200 text-slate-400 hover:bg-slate-50 hover:text-rose-600 hover:border-rose-300 transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    <?php else: ?>
                        <button type="button" onclick="document.getElementById('modal_op_<?= $modal_prefix . $op['id'] ?>').showModal()" title="<?= htmlspecialchars($botao_texto) ?>" class="bg-slate-800 hover:bg-slate-900 transition-colors text-white font-semibold py-2 px-3 rounded-lg text-xs uppercase tracking-wide flex items-center gap-1.5 shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                            <?= htmlspecialchars($botao_texto) ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
    }
}
