<?php
session_start();
require 'conexao.php';
require_once 'card_op.php';
require_once 'notificacoes.php';

// Segurança: só usuário corporativo do setor PCP ou ADMIN
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

if (isset($_SESSION['flash_mensagem'])) {
    $mensagem = $_SESSION['flash_mensagem'];
    $tipo_msg = $_SESSION['flash_tipo'] ?? '';
    unset($_SESSION['flash_mensagem'], $_SESSION['flash_tipo']);
}

// ========================================================================
// ENDPOINT AJAX: Salvar a nova ordem da esteira (chamado via fetch, sem reload)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);

    $linha_id = (int)($payload['linha_id'] ?? 0);
    $ordem    = $payload['ordem'] ?? [];

    if (!$linha_id || !is_array($ordem) || empty($ordem)) {
        echo json_encode(['ok' => false, 'erro' => 'Dados inválidos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt_check = $pdo->prepare("SELECT id FROM ordens_producao WHERE linha_id = ? AND status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO', 'AGUARDANDO ALMOXARIFADO', 'AGUARDANDO INICIO')");
        $stmt_check->execute([$linha_id]);
        $ids_validos = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

        $stmt_update = $pdo->prepare("UPDATE ordens_producao SET ordem_fila = ? WHERE id = ? AND linha_id = ?");
        $posicao = 1;
        foreach ($ordem as $op_id) {
            if (!in_array($op_id, $ids_validos)) continue;
            $stmt_update->execute([$posicao, $op_id, $linha_id]);
            $posicao++;
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ========================================================================
// CRIAÇÃO MANUAL DE OP
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_sistema'])) {
    try {
        $pdo->beginTransaction();
        $op_sistema     = trim($_POST['op_sistema']);
        $linha_id       = !empty($_POST['linha_id']) ? (int)$_POST['linha_id'] : null;
        $data_planejada = $_POST['data_planejada'];
        $observacao     = trim($_POST['observacao'] ?? '');
        $criador_id     = $_SESSION['usuario_id'];

        $stmt_prox = $pdo->prepare("SELECT COALESCE(MAX(ordem_fila), 0) + 1 FROM ordens_producao WHERE linha_id = ?");
        $stmt_prox->execute([$linha_id]);
        $proxima_ordem = $stmt_prox->fetchColumn();

        $stmt_op = $pdo->prepare("INSERT INTO ordens_producao (op_sistema, linha_id, criador_id, data_planejada, status, observacao_almoxarifado, ordem_fila) VALUES (?, ?, ?, ?, 'PROGRAMADO', ?, ?)");
        $stmt_op->execute([$op_sistema, $linha_id, $criador_id, $data_planejada, $observacao, $proxima_ordem]);
        $op_id = $pdo->lastInsertId();

        if (isset($_POST['pa_id']) && is_array($_POST['pa_id'])) {
            $stmt_pa = $pdo->prepare("INSERT INTO op_produtos (op_id, produto_id, quantidade_planejada) VALUES (?, ?, ?)");
            foreach ($_POST['pa_id'] as $indice => $produto_id) {
                if (!empty($produto_id)) {
                    $qtd_pa = (int)$_POST['pa_qtd'][$indice];
                    $stmt_pa->execute([$op_id, $produto_id, $qtd_pa]);
                }
            }
        }
        $pdo->commit();

        // Notifica os 2 setores que vão precisar agir nessa OP, mais o
        // Admin -- que acompanha tudo que acontece no sistema, igual o PCP.
        notificar_setor($pdo, 'ALMOXARIFADO', $op_id, 'OP_NOVA', "Nova OP {$op_sistema} programada, aguardando separação.");
        notificar_setor($pdo, 'FORMULACAO', $op_id, 'OP_NOVA', "Nova OP {$op_sistema} programada, aguardando formulação.");
        notificar_setor($pdo, 'ADMIN', $op_id, 'OP_NOVA', "Nova OP {$op_sistema} programada.");

        $_SESSION['flash_mensagem'] = "Ordem de Produção {$op_sistema} programada com sucesso!";
        $_SESSION['flash_tipo'] = 'sucesso';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['flash_mensagem'] = $e->getCode() == 23000 ? "Erro: A OP '{$op_sistema}' já existe." : "Erro: " . $e->getMessage();
        $_SESSION['flash_tipo'] = 'erro';
    }
    header("Location: programacao_pcp.php");
    exit;
}

// ========================================================================
// MOTOR DE EDIÇÃO E CANCELAMENTO DE OP
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    try {
        $op_id = (int)$_POST['op_id'];

        // Busca quem já mexeu nessa OP (se houver) ANTES de editar/cancelar,
        // pra poder notificá-los depois -- é exatamente "quem já mexeu",
        // não o setor inteiro.
        $stmt_envolvidos = $pdo->prepare("SELECT op_sistema, separador_id, formulador_id FROM ordens_producao WHERE id = ?");
        $stmt_envolvidos->execute([$op_id]);
        $envolvidos = $stmt_envolvidos->fetch(PDO::FETCH_ASSOC);

        if ($_POST['acao'] === 'editar_op') {
            $pdo->beginTransaction();
            $linha_id       = !empty($_POST['linha_id']) ? (int)$_POST['linha_id'] : null;
            $data_planejada = $_POST['data_planejada'];
            $observacao     = trim($_POST['observacao'] ?? '');

            $pdo->prepare("UPDATE ordens_producao SET linha_id = ?, data_planejada = ?, observacao_almoxarifado = ? WHERE id = ?")->execute([$linha_id, $data_planejada, $observacao, $op_id]);

            $ids_enviados = [];
            if (isset($_POST['op_produto_id']) && is_array($_POST['op_produto_id'])) {
                for ($i = 0; $i < count($_POST['op_produto_id']); $i++) {
                    $op_prod_id = $_POST['op_produto_id'][$i];
                    $prod_id    = $_POST['pa_id'][$i] ?? '';
                    $qtd        = (int)($_POST['pa_qtd'][$i] ?? 0);
                    if (empty($prod_id) || $qtd <= 0) continue;

                    if (!empty($op_prod_id)) {
                        $pdo->prepare("UPDATE op_produtos SET produto_id = ?, quantidade_planejada = ? WHERE id = ?")->execute([$prod_id, $qtd, $op_prod_id]);
                        $ids_enviados[] = (int)$op_prod_id;
                    } else {
                        $pdo->prepare("INSERT INTO op_produtos (op_id, produto_id, quantidade_planejada) VALUES (?, ?, ?)")->execute([$op_id, $prod_id, $qtd]);
                        $ids_enviados[] = (int)$pdo->lastInsertId();
                    }
                }
            }

            $antigos = $pdo->prepare("SELECT id FROM op_produtos WHERE op_id = ? AND quantidade_apontada = 0");
            $antigos->execute([$op_id]);
            $ids_excluir = array_diff($antigos->fetchAll(PDO::FETCH_COLUMN), $ids_enviados);
            if (!empty($ids_excluir)) {
                $placeholders = implode(',', array_fill(0, count($ids_excluir), '?'));
                $pdo->prepare("DELETE FROM op_produtos WHERE id IN ($placeholders)")->execute(array_values($ids_excluir));
            }
            $pdo->commit();

            if ($envolvidos) {
                $msg = "A OP {$envolvidos['op_sistema']} foi reprogramada pelo PCP -- confira os dados atualizados.";
                if (!empty($envolvidos['separador_id'])) notificar_usuario($pdo, (int)$envolvidos['separador_id'], $op_id, 'OP_REPROGRAMADA', $msg);
                if (!empty($envolvidos['formulador_id'])) notificar_usuario($pdo, (int)$envolvidos['formulador_id'], $op_id, 'OP_REPROGRAMADA', $msg);
                notificar_setor($pdo, 'ADMIN', $op_id, 'OP_REPROGRAMADA', $msg);
            }

            $_SESSION['flash_mensagem'] = "Ordem de Produção atualizada!";
            $_SESSION['flash_tipo'] = 'sucesso';
        } elseif ($_POST['acao'] === 'cancelar_op') {
            $pdo->prepare("UPDATE ordens_producao SET status = 'CANCELADO' WHERE id = ?")->execute([$op_id]);

            if ($envolvidos) {
                $msg = "A OP {$envolvidos['op_sistema']} foi cancelada pelo PCP.";
                if (!empty($envolvidos['separador_id'])) notificar_usuario($pdo, (int)$envolvidos['separador_id'], $op_id, 'OP_CANCELADA', $msg);
                if (!empty($envolvidos['formulador_id'])) notificar_usuario($pdo, (int)$envolvidos['formulador_id'], $op_id, 'OP_CANCELADA', $msg);
                notificar_setor($pdo, 'ADMIN', $op_id, 'OP_CANCELADA', $msg);
            }

            $_SESSION['flash_mensagem'] = "OP Cancelada com sucesso!";
            $_SESSION['flash_tipo'] = 'sucesso';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_mensagem'] = "Erro: " . $e->getMessage();
        $_SESSION['flash_tipo'] = 'erro';
    }
    header("Location: programacao_pcp.php");
    exit;
}

try {
    // Busca dados base
    $linhas_dropdown = $pdo->query("SELECT id, login, fabrica FROM linhas WHERE fabrica > 0 ORDER BY fabrica ASC, login ASC")->fetchAll(PDO::FETCH_ASSOC);
    $fabricas = array_unique(array_column($linhas_dropdown, 'fabrica'));

    $status_meta = [
        'PROGRAMADO'              => ['label' => 'Programado',            'cor' => 'pink'],
        'AGUARDANDO FORMULACAO'   => ['label' => 'Aguard. Formulação',    'cor' => 'purple'],
        'AGUARDANDO ALMOXARIFADO' => ['label' => 'Aguard. Almoxarifado',  'cor' => 'cyan'],
        'AGUARDANDO INICIO'       => ['label' => 'Aguardando Início',     'cor' => 'amber'],
        'PRODUCAO INICIADA'       => ['label' => 'Em Produção',           'cor' => 'blue'],
        'PRODUCAO FINALIZADA'     => ['label' => 'Finalizado',            'cor' => 'emerald'],
        'PAUSADO'                 => ['label' => 'Pausado',               'cor' => 'red'],
        'CANCELADO'               => ['label' => 'Cancelado',             'cor' => 'slate'],
        'PENDENCIA'               => ['label' => 'Pendência Material',    'cor' => 'rose']
    ];

    // Rótulos amigáveis dos 2 motivos de pendência da Formulação
    $motivos_pendencia_labels = [
        'MATERIA_PRIMA_INSUFICIENTE' => 'Matéria-prima insuficiente',
        'AGUARDANDO_LABORATORIO'     => 'Aguardando liberação do laboratório',
    ];

    // Contadores
    $count_status = array_fill_keys(array_keys($status_meta), 0);
    $stmt_contagem = $pdo->query("SELECT status FROM ordens_producao");
    foreach ($stmt_contagem->fetchAll(PDO::FETCH_COLUMN) as $st) {
        $st_limpo = normalizaStatus($st);
        if (isset($count_status[$st_limpo])) $count_status[$st_limpo]++;
    }

    // Busca TODAS as OPs de uma vez para permitir o SPA
    $stmt_fila = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.observacao_almoxarifado, op.ordem_fila,
               op.linha_id, l.login AS linha_nome, l.fabrica,
               op.data_separacao, op.data_formulacao,
               u.nome_completo AS nome_criador, us.nome_completo AS nome_separador, uf.nome_completo AS nome_formulador,
               (SELECT GROUP_CONCAT(CONCAT(p.codigo, ' ', p.descricao) SEPARATOR ' | ') FROM op_produtos op_prod JOIN produtos p ON op_prod.produto_id = p.id WHERE op_prod.op_id = op.id) AS busca_produtos,
               sa.status AS pendencia_almox_status, sa.observacao AS pendencia_almox_obs, sa.created_at AS pendencia_almox_data,
               sf.status AS pendencia_form_status, sf.motivo_pendencia AS pendencia_form_motivo, sf.observacao AS pendencia_form_obs, sf.created_at AS pendencia_form_data
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.criador_id = u.id
        LEFT JOIN usuarios us ON op.separador_id = us.id
        LEFT JOIN usuarios uf ON op.formulador_id = uf.id
        LEFT JOIN (
            SELECT sa1.op_id, sa1.status, sa1.observacao, sa1.created_at
            FROM separacoes_almoxarifado sa1
            INNER JOIN (SELECT op_id, MAX(id) AS max_id FROM separacoes_almoxarifado GROUP BY op_id) latest
                ON sa1.id = latest.max_id
        ) sa ON sa.op_id = op.id
        LEFT JOIN (
            SELECT sf1.op_id, sf1.status, sf1.motivo_pendencia, sf1.observacao, sf1.created_at
            FROM formulacoes sf1
            INNER JOIN (SELECT op_id, MAX(id) AS max_id FROM formulacoes GROUP BY op_id) latest
                ON sf1.id = latest.max_id
        ) sf ON sf.op_id = op.id
        ORDER BY op.data_planejada DESC, op.id DESC
    ");
    $todas_ops = $stmt_fila->fetchAll(PDO::FETCH_ASSOC);

    // Associa produtos a cada OP
    foreach ($todas_ops as &$op) {
        $stmt_prods = $pdo->prepare("SELECT op_p.id as op_produto_id, op_p.produto_id, p.codigo, p.descricao, op_p.quantidade_planejada, op_p.quantidade_apontada FROM op_produtos op_p JOIN produtos p ON op_p.produto_id = p.id WHERE op_p.op_id = ?");
        $stmt_prods->execute([$op['id']]);
        $op['produtos'] = $stmt_prods->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($op);

    // Separa os dados para a Esteira (fila ainda dentro do duplo gate)
    $ops_esteira = [];
    foreach ($todas_ops as $op) {
        if (in_array(normalizaStatus($op['status']), ['PROGRAMADO', 'AGUARDANDO FORMULACAO', 'AGUARDANDO ALMOXARIFADO', 'AGUARDANDO INICIO'])) {
            $ops_esteira[$op['linha_id']][] = $op;
        }
    }

    // Ordena a esteira pela posição e data
    foreach ($ops_esteira as &$lista_ops) {
        usort($lista_ops, function($a, $b) {
            if ($a['ordem_fila'] != $b['ordem_fila']) return $a['ordem_fila'] <=> $b['ordem_fila'];
            return $a['data_planejada'] <=> $b['data_planejada'];
        });
    }
    unset($lista_ops);

    // Bolinha vermelha: quais fábricas têm pelo menos 1 linha com OP em
    // aberto (qualquer status ainda dentro do pipeline, já calculado em
    // $ops_esteira). Reaproveita o mapa linha_id -> fabrica de $linhas_dropdown,
    // sem precisar de outra query.
    $fabricas_com_pendencia = [];
    foreach ($linhas_dropdown as $l) {
        if (!empty($ops_esteira[$l['id']])) {
            $fabricas_com_pendencia[$l['fabrica']] = true;
        }
    }

} catch (PDOException $e) {
    die("Erro ao carregar: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo PCP - Inteligência MES</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'] } } } }</script>
    <style>
        .op-card.dragging { opacity: 0.4; }
        .op-card { touch-action: none; cursor: grab; }
        .op-card:active { cursor: grabbing; }
        dialog[open] { display: flex; flex-direction: column; }
    </style>
</head>

<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <?php include 'header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 space-y-8 mt-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 tracking-tight">Programação de OPs</h2>
                <p class="text-sm text-slate-500 font-medium">Controle de fila e ordens de produção para a fábrica.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-9 gap-3">
            <?php foreach ($status_meta as $nome => $meta): $cor = $meta['cor']; ?>
                <div class="bg-<?= $cor ?>-100 rounded-lg px-4 py-4 border border-<?= $cor ?>-200 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-<?= $cor ?>-500 shrink-0"></span>
                        <span class="text-[11px] font-bold uppercase tracking-widest text-<?= $cor ?>-800 truncate"><?= $meta['label'] ?></span>
                    </div>
                    <div class="font-mono text-3xl font-extrabold text-<?= $cor ?>-900 mt-2 tabular-nums"><?= str_pad($count_status[$nome], 2, '0', STR_PAD_LEFT) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-lg shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?= $mensagem ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden">
            <div class="p-4 bg-slate-50 border-b border-slate-100 flex gap-4">
                <button onclick="toggleBox('box_manual')" class="text-sm font-bold text-slate-700 hover:text-blue-600 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg> Criar OP Manual
                </button>
                <button onclick="toggleBox('box_planilha')" class="text-sm font-bold text-slate-700 hover:text-emerald-600 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Importar Planilha
                </button>
            </div>

            <form id="box_manual" method="POST" class="p-6 space-y-6 hidden">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Número da OP</label>
                        <input type="text" name="op_sistema" required class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Linha de Destino</label>
                        <select name="linha_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                            <option value="">Selecione...</option>
                            <?php foreach ($linhas_dropdown as $l): ?>
                                <option value="<?= $l['id'] ?>">F<?= $l['fabrica'] ?> - <?= strtoupper($l['login']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Data planejada</label>
                        <input type="date" name="data_planejada" required value="<?= date('Y-m-d') ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Observações</label>
                        <input type="text" name="observacao" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-bold text-slate-800">Produtos a Produzir</h3>
                        <button type="button" onclick="adicionarPA()" class="text-xs bg-white border border-blue-300 text-blue-600 hover:bg-blue-50 font-bold px-3 py-1.5 rounded-lg flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"></path></svg> Adicionar Produto</button>
                    </div>
                    <div id="container_pas" class="space-y-3"></div>
                </div>
                <button type="submit" class="bg-slate-800 hover:bg-black text-white font-bold py-3 px-8 rounded-lg text-sm shadow-sm transition-colors">Salvar Programação</button>
            </form>

            <div id="box_planilha" class="p-6 hidden">
                <form action="importa_planilha.php" method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-4">
                    <input type="file" name="arquivo_excel" id="input_excel_file" accept=".xlsx" required class="hidden">
                    <label for="input_excel_file" id="btn_excel_label" class="flex-1 border-2 border-dashed border-slate-300 hover:border-emerald-400 bg-slate-50 rounded-xl cursor-pointer flex flex-col items-center justify-center p-6 transition-colors">
                        <svg class="w-8 h-8 text-slate-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span id="nome_arquivo_excel" class="text-sm font-bold text-slate-600">Clique para selecionar a Planilha (.XLSX)</span>
                    </label>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-8 rounded-xl shadow-sm transition-colors">Importar</button>
                </form>
            </div>
        </div>

        <div class="mt-8">
            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3 mb-4">
                <?php foreach ($fabricas as $fab): ?>
                    <button type="button" onclick="mudarAbaPrincipal('fabrica', <?= $fab ?>)" id="tab_fabrica_<?= $fab ?>" class="tab-principal relative px-5 py-2.5 rounded-t-lg text-sm font-bold transition-colors bg-white text-slate-500 border border-slate-200 border-b-0 hover:bg-slate-100">
                        Fábrica <?= $fab ?>
                        <?php if (!empty($fabricas_com_pendencia[$fab])): ?>
                            <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-rose-500 border-2 border-white" title="Há OPs em aberto nesta fábrica"></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" onclick="mudarAbaPrincipal('global')" id="tab_global" class="tab-principal px-5 py-2.5 rounded-t-lg text-sm font-bold transition-colors flex items-center gap-2 bg-white text-slate-500 border border-slate-200 border-b-0 hover:bg-slate-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    Visão Global / Filtros
                </button>
            </div>

            <div id="container_linhas" class="flex flex-wrap gap-2 mb-6 hidden">
                <?php foreach ($linhas_dropdown as $l): ?>
                    <button type="button" onclick="selecionarLinha(<?= $l['id'] ?>)" id="btn_linha_<?= $l['id'] ?>" data-fabrica="<?= $l['fabrica'] ?>" class="btn-linha relative px-4 py-2 rounded-lg text-xs font-bold uppercase transition-colors border bg-white text-slate-600 border-slate-200 hover:bg-blue-50 hidden">
                        <?= htmlspecialchars($l['login']) ?>
                        <?php if (!empty($ops_esteira[$l['id']])): ?>
                            <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-rose-500 border-2 border-white" title="<?= count($ops_esteira[$l['id']]) ?> OP(s) em aberto"></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div id="view_esteira" class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-5 hidden">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-base font-bold text-slate-800">Esteira de Produção</h3>
                    <span id="status_salvamento" class="text-xs font-semibold text-slate-400"></span>
                </div>

                <!-- FILTRO LOCAL DA LINHA SELECIONADA (não troca de aba, só filtra os cards da linha atual) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-2">
                    <input type="text" id="filtro_esteira_op" oninput="aplicarFiltroEsteira()" placeholder="Buscar por OP nesta linha..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                    <input type="text" id="filtro_esteira_produto" oninput="aplicarFiltroEsteira()" placeholder="Buscar por Produto nesta linha..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                </div>
                <p class="text-[10px] text-slate-400 font-semibold mb-5 italic" id="dica_filtro_esteira">Filtro aplicado apenas à linha selecionada acima.</p>

                <?php foreach ($linhas_dropdown as $l): $lid = $l['id']; ?>
                    <div id="bloco_esteira_<?= $lid ?>" class="bloco-esteira hidden" data-linha-id="<?= $lid ?>">
                        <?php if (empty($ops_esteira[$lid])): ?>
                            <div class="text-center p-8 border-2 border-dashed border-slate-200 rounded-xl text-slate-400 font-semibold text-sm">Fila vazia para esta máquina.</div>
                        <?php else: ?>
                            <div class="esteira space-y-3" data-linha-id="<?= $lid ?>">
                                <?php foreach ($ops_esteira[$lid] as $idx => $op): ?>
                                    <?php render_op_card($op, $idx, $status_meta, $motivos_pendencia_labels, true, true, false); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="view_global" class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-5 hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <input type="text" id="filtro_op" onkeyup="aplicarFiltrosGlobal()" placeholder="Buscar por OP..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                    <input type="text" id="filtro_produto" onkeyup="aplicarFiltrosGlobal()" placeholder="Buscar por Produto..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-100">
                </div>
                <div class="flex flex-wrap gap-3 mb-6">
                    <?php foreach ($status_meta as $nome => $meta): $cor = $meta['cor']; ?>
                        <button type="button" onclick="toggleStatusGlobal('<?= $nome ?>', this)" data-color="<?= $cor ?>" data-shade="500" class="btn-status-global group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-<?= $cor ?>-100 text-<?= $cor ?>-900 hover:bg-<?= $cor ?>-500 hover:text-white shadow-sm flex items-center gap-2">
                            <span class="dot-status w-3 h-3 rounded-full bg-<?= $cor ?>-500 group-hover:bg-white shrink-0 transition-colors"></span> <?= $meta['label'] ?> (<?= $count_status[$nome] ?? 0 ?>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="space-y-3">
                    <?php foreach ($todas_ops as $idx => $op): ?>
                        <?php render_op_card($op, $idx, $status_meta, $motivos_pendencia_labels, false, false, true); ?>
                    <?php endforeach; ?>
                    <div id="msg_vazio_global" class="hidden p-6 text-center text-sm text-slate-400 font-semibold border-2 border-dashed border-slate-200 rounded-xl">Nenhuma OP corresponde aos filtros de busca.</div>
                </div>
            </div>
        </div>

        <?php foreach ($todas_ops as $f): ?>
            <dialog id="modal_editar_op_<?= $f['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-2xl bg-white backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm m-auto overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-800 uppercase">Editar OP <?= htmlspecialchars($f['op_sistema']) ?></h3>
                    <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 rounded-lg p-1.5"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <form method="POST" class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                    <input type="hidden" name="acao" value="editar_op">
                    <input type="hidden" name="op_id" value="<?= $f['id'] ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Mudar Linha</label>
                            <select name="linha_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm bg-white">
                                <?php foreach ($linhas_dropdown as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= ($f['linha_id'] == $l['id']) ? 'selected' : '' ?>>F<?= $l['fabrica'] ?> - <?= strtoupper($l['login']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Reprogramar Data</label>
                            <input type="date" name="data_planejada" value="<?= $f['data_planejada'] ?>" required class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Observação</label>
                        <input type="text" name="observacao" value="<?= htmlspecialchars($f['observacao_almoxarifado']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    
                    <div class="pt-2 border-t border-slate-100">
                        <div class="flex justify-between items-center mb-3">
                            <label class="block text-xs font-bold text-slate-600 uppercase">Produtos Acabados</label>
                            <button type="button" onclick="adicionarPAEdicao(<?= $f['id'] ?>)" class="text-xs bg-white border border-blue-300 text-blue-600 hover:bg-blue-50 font-bold px-3 py-1.5 rounded-lg">+ Produto</button>
                        </div>
                        <div id="container_pas_edicao_<?= $f['id'] ?>" class="space-y-3">
                            <?php foreach ($f['produtos'] as $prod): ?>
                                <div class="pa-block bg-slate-50 border border-slate-200 rounded-xl p-3 flex gap-3 items-end">
                                    <input type="hidden" name="op_produto_id[]" value="<?= $prod['op_produto_id'] ?>">
                                    <input type="hidden" name="pa_id[]" value="<?= $prod['produto_id'] ?>">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Produto Fixo</label>
                                        <div class="px-3 py-2 bg-slate-100 rounded-lg text-xs font-bold text-slate-600 border border-slate-200 truncate">[<?= htmlspecialchars($prod['codigo']) ?>] <?= htmlspecialchars($prod['descricao']) ?></div>
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Qtd</label>
                                        <input type="number" name="pa_qtd[]" required min="<?= max(1, (int)$prod['quantidade_apontada']) ?>" value="<?= $prod['quantidade_planejada'] ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-blue-600">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pt-4 flex gap-3">
                        <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-3 rounded-lg text-sm">Voltar</button>
                        <button type="submit" class="flex-1 bg-slate-800 text-white font-bold py-3 rounded-lg text-sm">Salvar Alterações</button>
                    </div>
                </form>
            </dialog>

            <dialog id="modal_cancelar_op_<?= $f['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white m-auto">
                <div class="p-6 text-center">
                    <h3 class="text-base font-bold text-slate-800 mb-1.5">Cancelar esta OP?</h3>
                    <p class="text-xs font-medium text-slate-500 mb-6">Deseja cancelar a OP <strong><?= htmlspecialchars($f['op_sistema']) ?></strong>?</p>
                    <form method="POST" class="flex gap-3">
                        <input type="hidden" name="acao" value="cancelar_op">
                        <input type="hidden" name="op_id" value="<?= $f['id'] ?>">
                        <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                        <button type="submit" class="flex-1 bg-rose-600 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Cancelar</button>
                    </form>
                </div>
            </dialog>
        <?php endforeach; ?>

        <dialog id="modal_buscador_mes" class="p-0 rounded-2xl shadow-xl border border-slate-200 w-[95%] max-w-lg bg-white m-auto">
            <div class="p-5">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-bold text-slate-800">Buscar Produto</h3>
                    <button type="button" onclick="document.getElementById('modal_buscador_mes').close()" class="text-slate-400 hover:text-rose-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <input type="text" id="input_termo_busca" onkeyup="executarBuscaDinamica()" placeholder="Digite o código ou nome..." class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100">
                <div id="lista_resultados_busca" class="max-h-60 overflow-y-auto mt-3 border border-slate-200 rounded-lg divide-y divide-slate-100"></div>
            </div>
        </dialog>
    </div>

    <script>
        <?php render_op_card_scripts(); ?>

        function toggleBox(id) {
            document.getElementById('box_manual').classList.add('hidden');
            document.getElementById('box_planilha').classList.add('hidden');
            document.getElementById(id).classList.remove('hidden');
        }

        document.getElementById('input_excel_file').addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                document.getElementById('nome_arquivo_excel').textContent = e.target.files[0].name;
                document.getElementById('btn_excel_label').classList.add('border-emerald-400', 'bg-emerald-50');
            }
        });

        // ----------------------------------------------------
        // NAVEGAÇÃO FLUIDA (SPA)
        // ----------------------------------------------------
        function resetarAbas() {
            document.querySelectorAll('.tab-principal').forEach(b => {
                b.classList.remove('bg-slate-800', 'text-white');
                b.classList.add('bg-white', 'text-slate-500');
            });
            document.getElementById('container_linhas').classList.add('hidden');
            document.getElementById('view_esteira').classList.add('hidden');
            document.getElementById('view_global').classList.add('hidden');
        }

        function mudarAbaPrincipal(tipo, fabrica = null) {
            resetarAbas();
            
            if (tipo === 'global') {
                const tab = document.getElementById('tab_global');
                tab.classList.remove('bg-white', 'text-slate-500');
                tab.classList.add('bg-slate-800', 'text-white');
                document.getElementById('view_global').classList.remove('hidden');
            } else {
                const tab = document.getElementById('tab_fabrica_' + fabrica);
                tab.classList.remove('bg-white', 'text-slate-500');
                tab.classList.add('bg-slate-800', 'text-white');
                
                document.getElementById('container_linhas').classList.remove('hidden');
                document.getElementById('view_esteira').classList.remove('hidden');

                let primeiraLinha = null;
                document.querySelectorAll('.btn-linha').forEach(b => {
                    if (b.dataset.fabrica == fabrica) {
                        b.classList.remove('hidden');
                        if (!primeiraLinha) primeiraLinha = b.id.replace('btn_linha_', '');
                    } else {
                        b.classList.add('hidden');
                    }
                });
                if (primeiraLinha) selecionarLinha(primeiraLinha);
            }
        }

        function selecionarLinha(id) {
            document.querySelectorAll('.btn-linha').forEach(b => {
                b.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
                b.classList.add('bg-white', 'text-slate-600', 'border-slate-200');
            });
            document.getElementById('btn_linha_' + id).classList.add('bg-blue-600', 'text-white', 'border-blue-600');
            
            document.querySelectorAll('.bloco-esteira').forEach(b => b.classList.add('hidden'));
            document.getElementById('bloco_esteira_' + id).classList.remove('hidden');

            // Limpa o filtro local ao trocar de linha, pra não parecer que a
            // fila da nova linha está "faltando" cards por causa de um termo
            // digitado enquanto se olhava outra linha.
            document.getElementById('filtro_esteira_op').value = '';
            document.getElementById('filtro_esteira_produto').value = '';
            aplicarFiltroEsteira();
        }

        // Inicialização do SPA
        window.addEventListener('DOMContentLoaded', () => {
            // Se veio de um clique em notificação (?buscar_op=XXX), abre
            // direto na Visão Global já filtrada por essa OP, em vez do
            // comportamento padrão de abrir a primeira fábrica.
            const params = new URLSearchParams(window.location.search);
            const opBuscada = params.get('buscar_op');
            if (opBuscada) {
                mudarAbaPrincipal('global');
                const campoFiltro = document.getElementById('filtro_op');
                campoFiltro.value = opBuscada;
                aplicarFiltrosGlobal();
                // Limpa a URL (tira o ?buscar_op=) sem recarregar a página,
                // pra não ficar reaplicando o filtro se a pessoa der F5.
                window.history.replaceState({}, '', window.location.pathname);
                return;
            }

            const btnFabricas = document.querySelectorAll('.tab-principal');
            if (btnFabricas.length > 1) btnFabricas[0].click(); // Inicia na primeira fábrica
        });

        // ----------------------------------------------------
        // FILTRO LOCAL DA ESTEIRA (por linha, não sai da tela)
        // ----------------------------------------------------
        function aplicarFiltroEsteira() {
            const tOp = document.getElementById('filtro_esteira_op').value.toLowerCase();
            const tProd = document.getElementById('filtro_esteira_produto').value.toLowerCase();
            const filtroAtivo = tOp !== '' || tProd !== '';

            document.querySelectorAll('.bloco-esteira').forEach(bloco => {
                bloco.querySelectorAll('.op-card').forEach(card => {
                    const matchOp = (card.dataset.op || '').includes(tOp);
                    const matchProd = (card.dataset.prod || '').includes(tProd);
                    const match = matchOp && matchProd;
                    card.style.display = match ? '' : 'none';
                    // Enquanto o filtro estiver ativo, desativa o arraste --
                    // reordenar com a lista parcialmente escondida bagunçaria
                    // a posição real dos cards que não estão visíveis.
                    card.setAttribute('draggable', filtroAtivo ? 'false' : 'true');
                });
            });

            const dica = document.getElementById('dica_filtro_esteira');
            if (dica) {
                dica.textContent = filtroAtivo
                    ? 'Filtro ativo nesta linha: a reordenação por arraste está temporariamente desativada. Limpe os campos para voltar a arrastar.'
                    : 'Filtro aplicado apenas à linha selecionada acima.';
            }
        }

        // ----------------------------------------------------
        // ADICIONAR PRODUTOS (À PROVA DE FALHAS)
        // ----------------------------------------------------
        let blockCounter = 0;
        
        function gerarHtmlPA(idUnico) {
            return `
            <div class="pa-block bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative mb-3" id="pa_${idUnico}">
                <div class="bg-slate-50 p-4 flex flex-col md:flex-row gap-4 items-end">
                    <input type="hidden" name="pa_id[]" class="pa-id-hidden">
                    <div class="w-full md:w-1/3 relative">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Cód. Produto</label>
                        <div class="flex rounded-lg border border-slate-300 bg-white overflow-hidden focus-within:ring-2 focus-within:ring-blue-200">
                            <input type="text" onblur="buscarPA(this)" required placeholder="Ex: 9999" class="input-codigo-busca w-full px-3 py-2 text-sm font-bold bg-transparent outline-none">
                            <button type="button" onclick="abrirBuscadorGlobal(this)" class="bg-slate-100 hover:bg-slate-200 px-3 border-l border-slate-200"><svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg></button>
                        </div>
                    </div>
                    <div class="w-full md:flex-1 flex items-center mb-1"><span class="desc-pa text-xs font-bold text-slate-400 uppercase">Aguardando código...</span></div>
                    <div class="w-full md:w-1/4">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Quantidade</label>
                        <input type="number" name="pa_qtd[]" required min="1" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-blue-600 outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <button type="button" onclick="document.getElementById('pa_${idUnico}').remove()" class="absolute top-2 right-2 text-slate-400 hover:text-rose-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
            </div>`;
        }

        function adicionarPA() {
            blockCounter++;
            document.getElementById('container_pas').insertAdjacentHTML('beforeend', gerarHtmlPA('main_'+blockCounter));
        }

        function adicionarPAEdicao(opId) {
            blockCounter++;
            document.getElementById('container_pas_edicao_' + opId).insertAdjacentHTML('beforeend', gerarHtmlPA('edit_'+blockCounter));
        }

        window.addEventListener('DOMContentLoaded', () => { if(document.getElementById('container_pas')) adicionarPA(); });

        // ----------------------------------------------------
        // BUSCADOR DE PRODUTOS
        // ----------------------------------------------------
        let inputOrigemAtual = null;

        function abrirBuscadorGlobal(botaoClick) {
            inputOrigemAtual = botaoClick.closest('.pa-block').querySelector('.input-codigo-busca');
            document.getElementById('input_termo_busca').value = '';
            document.getElementById('lista_resultados_busca').innerHTML = '<div class="p-4 text-center text-xs text-slate-400">Aguardando digitação...</div>';
            document.getElementById('modal_buscador_mes').showModal();
            setTimeout(() => document.getElementById('input_termo_busca').focus(), 100);
        }

        async function executarBuscaDinamica() {
            const termo = document.getElementById('input_termo_busca').value.trim();
            const container = document.getElementById('lista_resultados_busca');
            if (termo.length < 2) return;
            try {
                const resp = await fetch(`busca_consulta.php?termo=${termo}&tabela=produto`);
                const dados = await resp.json();
                if (dados.length === 0) {
                    container.innerHTML = '<div class="p-4 text-center text-xs text-rose-500">Nenhum registro.</div>';
                    return;
                }
                container.innerHTML = '';
                dados.forEach(item => {
                    container.insertAdjacentHTML('beforeend', `<div onclick="selecionarItem('${item.codigo}')" class="p-3 hover:bg-blue-50 cursor-pointer text-sm"><strong>[${item.codigo}]</strong> ${item.descricao}</div>`);
                });
            } catch(e) {}
        }

        function selecionarItem(codigo) {
            if (inputOrigemAtual) {
                inputOrigemAtual.value = codigo;
                document.getElementById('modal_buscador_mes').close();
                inputOrigemAtual.blur();
            }
        }

        async function buscarPA(input) {
            const codigo = input.value.trim();
            const label = input.closest('.pa-block').querySelector('.desc-pa');
            const hidden = input.closest('.pa-block').querySelector('.pa-id-hidden');
            if (!codigo) { label.textContent = 'Aguardando...'; hidden.value = ''; return; }
            
            label.textContent = 'Buscando...';
            try {
                const res = await fetch(`busca_produto.php?codigo=${codigo}`);
                const dados = await res.json();
                if (dados.id) {
                    label.textContent = dados.descricao;
                    label.className = 'desc-pa text-xs font-bold text-emerald-600 uppercase';
                    hidden.value = dados.id;
                } else {
                    label.textContent = 'NÃO ENCONTRADO';
                    label.className = 'desc-pa text-xs font-bold text-rose-500 uppercase';
                    hidden.value = '';
                }
            } catch(e) { label.textContent = 'ERRO'; }
        }

        // ----------------------------------------------------
        // ARRASTAR ESTEIRA E SALVAR
        // ----------------------------------------------------
        let dragEl = null;
        document.querySelectorAll('.esteira').forEach(esteira => {
            esteira.addEventListener('dragstart', e => {
                const card = e.target.closest('.op-card');
                if(!card || card.getAttribute('draggable') === 'false') return;
                dragEl = card; card.classList.add('opacity-40');
                e.dataTransfer.effectAllowed = 'move';
            });
            esteira.addEventListener('dragend', () => {
                if(!dragEl) return;
                dragEl.classList.remove('opacity-40');
                const p = dragEl.closest('.esteira'); dragEl = null;
                renumerar(p); salvar(p);
            });
            esteira.addEventListener('dragover', e => {
                if(!dragEl) return; e.preventDefault();
                const alvo = e.target.closest('.op-card');
                if(!alvo || alvo === dragEl) return;
                const r = alvo.getBoundingClientRect();
                if((e.clientY - r.top) > (r.height / 2)) alvo.after(dragEl); else alvo.before(dragEl);
            });
        });

        function renumerar(esteira) {
            esteira.querySelectorAll('.posicao-badge').forEach((b, i) => b.textContent = i + 1);
        }

        async function salvar(esteira) {
            const linhaId = esteira.dataset.linhaId;
            const ordem = Array.from(esteira.querySelectorAll('.op-card')).map(c => c.dataset.opId);
            const statusEl = document.getElementById('status_salvamento');
            statusEl.textContent = 'Salvando...'; statusEl.className = 'text-xs font-semibold text-amber-500';
            try {
                const r = await fetch(window.location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ linha_id: linhaId, ordem: ordem }) });
                if(!(await r.json()).ok) throw new Error();
                statusEl.textContent = 'Ordem salva ✓'; statusEl.className = 'text-xs font-semibold text-emerald-500';
            } catch (e) {
                statusEl.textContent = 'Erro ao salvar'; statusEl.className = 'text-xs font-semibold text-rose-500';
            }
            setTimeout(() => { statusEl.textContent = ''; }, 2000);
        }

        // ----------------------------------------------------
        // FILTROS VISÃO GLOBAL
        // ----------------------------------------------------
        let statusGlobais = [];
        function toggleStatusGlobal(status, btnElement) {
            const idx = statusGlobais.indexOf(status);
            const cor = btnElement.dataset.color || 'slate';
            const tom = btnElement.dataset.shade || '500';
            const dot = btnElement.querySelector('.dot-status');

            if (idx > -1) {
                // Desmarca o botão
                statusGlobais.splice(idx, 1);
                btnElement.classList.remove('text-white', `bg-${cor}-${tom}`, 'shadow-lg');
                if (dot) dot.classList.remove('bg-white');
            } else {
                // Marca o botão
                statusGlobais.push(status);
                btnElement.classList.add('text-white', `bg-${cor}-${tom}`, 'shadow-lg');
                if (dot) dot.classList.add('bg-white');
            }
            aplicarFiltrosGlobal();
        }

        function aplicarFiltrosGlobal() {
            const tOp = document.getElementById('filtro_op').value.toLowerCase();
            const tProd = document.getElementById('filtro_produto').value.toLowerCase();
            let visiveis = 0;

            document.querySelectorAll('.card-global').forEach(c => {
                const matchOp = c.dataset.op.includes(tOp);
                const matchProd = c.dataset.prod.includes(tProd);
                const matchStatus = statusGlobais.length === 0 || statusGlobais.includes(c.dataset.status);

                if (matchOp && matchProd && matchStatus) {
                    c.style.display = ''; visiveis++;
                } else {
                    c.style.display = 'none';
                }
            });
            document.getElementById('msg_vazio_global').classList.toggle('hidden', visiveis > 0);
        }
    </script>
</body>
</html>