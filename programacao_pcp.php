<?php
session_start();
require 'conexao.php';

// 1. CHAMA A BIBLIOTECA DO EXCEL (Instalada via Composer)
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// SEGURANÇA: Só entra Usuário Corporativo (PCP ou ADMIN)
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

// ========================================================================
// IMPORTAÇÃO VIA PLANILHA EXCEL MULTI-ABAS (.XLSX)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['planilha_importacao']) && $_FILES['planilha_importacao']['error'] === UPLOAD_ERR_OK) {
    $arquivo = $_FILES['planilha_importacao']['tmp_name'];
    $criador_id = $_SESSION['usuario_id'];

    try {
        $spreadsheet = IOFactory::load($arquivo);
        $sheetNames = $spreadsheet->getSheetNames();
        $pdo->beginTransaction();

        $ops_inseridas = 0;

        foreach ($sheetNames as $nomeAba) {
            // Tenta achar a linha pelo nome da aba (Ex: "l1f1")
            $stmt_linha = $pdo->prepare("SELECT id FROM linhas WHERE login = ?");
            $stmt_linha->execute([strtolower(trim($nomeAba))]);
            $linha_id = $stmt_linha->fetchColumn();

            if (!$linha_id) continue; // Se a aba se chamar "Planilha3", ele apenas ignora

            $sheet = $spreadsheet->getSheetByName($nomeAba);
            $linhasExcel = $sheet->toArray();
            array_shift($linhasExcel); // Remove o cabeçalho (Linha 1)

            // Agrupa os dados pela OP (caso tenham múltiplos produtos na mesma OP)
            $opsAgrupadas = [];
            foreach ($linhasExcel as $row) {
                $op_sistema = trim($row[0] ?? '');
                $data_excel = $row[1] ?? '';
                $codigo_produto = trim($row[2] ?? '');
                $quantidade = (int)($row[3] ?? 0);
                $obs = trim($row[4] ?? '');

                if (empty($op_sistema) || empty($codigo_produto) || $quantidade <= 0) continue;

                // Formatação blindada da data (Excel converte datas em números internamente)
                $data_planejada = date('Y-m-d');
                if (is_numeric($data_excel)) {
                    $data_planejada = Date::excelToDateTimeObject($data_excel)->format('Y-m-d');
                } else {
                    $d = DateTime::createFromFormat('d/m/Y', $data_excel);
                    if ($d) $data_planejada = $d->format('Y-m-d');
                }

                // Busca o ID real do produto no banco através do Código
                $stmt_prod = $pdo->prepare("SELECT id FROM produtos WHERE codigo = ?");
                $stmt_prod->execute([$codigo_produto]);
                $produto_id = $stmt_prod->fetchColumn();

                if (!$produto_id) continue;

                if (!isset($opsAgrupadas[$op_sistema])) {
                    $opsAgrupadas[$op_sistema] = ['data' => $data_planejada, 'obs' => $obs, 'produtos' => []];
                }
                $opsAgrupadas[$op_sistema]['produtos'][] = ['id' => $produto_id, 'qtd' => $quantidade];
            }

            // Insere as OPs e Produtos no banco
            foreach ($opsAgrupadas as $op_sistema => $dadosOp) {
                // Checa se a OP já existe para evitar duplicatas e quebrar o sistema
                $stmt_check = $pdo->prepare("SELECT id FROM ordens_producao WHERE op_sistema = ?");
                $stmt_check->execute([$op_sistema]);
                if ($stmt_check->rowCount() > 0) continue;

                $stmt_op = $pdo->prepare("INSERT INTO ordens_producao (op_sistema, linha_id, criador_id, data_planejada, status, observacao_almoxarifado) VALUES (?, ?, ?, ?, 'PROGRAMADO', ?)");
                $stmt_op->execute([$op_sistema, $linha_id, $criador_id, $dadosOp['data'], $dadosOp['obs']]);
                $novo_op_id = $pdo->lastInsertId();

                $stmt_pa = $pdo->prepare("INSERT INTO op_produtos (op_id, produto_id, quantidade_planejada) VALUES (?, ?, ?)");
                foreach ($dadosOp['produtos'] as $prod) {
                    $stmt_pa->execute([$novo_op_id, $prod['id'], $prod['qtd']]);
                }
                $ops_inseridas++;
            }
        }

        $pdo->commit();
        $mensagem = "Importação de Planilha concluída: {$ops_inseridas} nova(s) OP(s) inserida(s)!";
        $tipo_msg = 'sucesso';
    } catch (Exception $e) {
        $pdo->rollBack();
        $tipo_msg = 'erro';
        $mensagem = "Erro ao processar a planilha: " . $e->getMessage();
    }
}


// Só entra se for um Usuário, e o setor for PCP ou ADMIN
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}


$mensagem = '';
$tipo_msg = '';
$linhas_dropdown = [];
$fila_producao = [];

// Recupera mensagem de flash (vinda de um redirecionamento pós-POST) e limpa da sessão
if (isset($_SESSION['flash_mensagem'])) {
    $mensagem = $_SESSION['flash_mensagem'];
    $tipo_msg = $_SESSION['flash_tipo'] ?? '';
    unset($_SESSION['flash_mensagem'], $_SESSION['flash_tipo']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_sistema'])) {
    try {
        $pdo->beginTransaction();

        $op_sistema     = trim($_POST['op_sistema']);
        $linha_id       = !empty($_POST['linha_id']) ? (int)$_POST['linha_id'] : null;
        $data_planejada = $_POST['data_planejada'];
        $observacao     = trim($_POST['observacao'] ?? '');
        $criador_id     = $_SESSION['usuario_id'];

        $stmt_op = $pdo->prepare("INSERT INTO ordens_producao (op_sistema, linha_id, criador_id, data_planejada, status, observacao_almoxarifado) VALUES (?, ?, ?, ?, 'PROGRAMADO', ?)");
        $stmt_op->execute([$op_sistema, $linha_id, $criador_id, $data_planejada, $observacao]);
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

        $_SESSION['flash_mensagem'] = "Ordem de Produção {$op_sistema} programada com sucesso!";
        $_SESSION['flash_tipo'] = 'sucesso';
        header("Location: programacao_pcp.php");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();

        if ($e->getCode() == 23000) {
            $_SESSION['flash_mensagem'] = "Erro: A OP '{$op_sistema}' já existe no sistema.";
        } else {
            $_SESSION['flash_mensagem'] = "Erro ao programar OP: " . $e->getMessage();
        }
        $_SESSION['flash_tipo'] = 'erro';
        header("Location: programacao_pcp.php");
        exit;
    }
}

// ========================================================================
// MOTOR DE EDIÇÃO E CANCELAMENTO DE OP (roda ANTES da consulta que monta a
// tabela, pra garantir que o que aparece na tela já reflita a alteração)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    try {
        $op_id = (int)$_POST['op_id'];

        if ($_POST['acao'] === 'editar_op') {
            $pdo->beginTransaction();

            $linha_id       = !empty($_POST['linha_id']) ? (int)$_POST['linha_id'] : null;
            $data_planejada = $_POST['data_planejada'];
            $observacao     = trim($_POST['observacao'] ?? '');

            $stmt = $pdo->prepare("UPDATE ordens_producao SET linha_id = ?, data_planejada = ?, observacao_almoxarifado = ? WHERE id = ?");
            $stmt->execute([$linha_id, $data_planejada, $observacao, $op_id]);

            // --- Sincroniza os produtos (op_produtos) ---
            $ids_enviados = [];
            if (isset($_POST['op_produto_id']) && is_array($_POST['op_produto_id'])) {
                $qtd_produtos = count($_POST['op_produto_id']);
                for ($i = 0; $i < $qtd_produtos; $i++) {
                    $op_produto_id_atual = $_POST['op_produto_id'][$i];
                    $produto_id_atual    = $_POST['pa_id'][$i] ?? '';
                    $qtd_atual           = (int)($_POST['pa_qtd'][$i] ?? 0);

                    if (empty($produto_id_atual) || $qtd_atual <= 0) continue;

                    if (!empty($op_produto_id_atual)) {
                        // Produto já existia -> atualiza, preservando a quantidade_apontada
                        $stmt_upd = $pdo->prepare("UPDATE op_produtos SET produto_id = ?, quantidade_planejada = ? WHERE id = ? AND op_id = ?");
                        $stmt_upd->execute([$produto_id_atual, $qtd_atual, $op_produto_id_atual, $op_id]);
                        $ids_enviados[] = (int)$op_produto_id_atual;
                    } else {
                        // Produto novo -> insere
                        $stmt_ins = $pdo->prepare("INSERT INTO op_produtos (op_id, produto_id, quantidade_planejada) VALUES (?, ?, ?)");
                        $stmt_ins->execute([$op_id, $produto_id_atual, $qtd_atual]);
                        $ids_enviados[] = (int)$pdo->lastInsertId();
                    }
                }
            }

            // Remove produtos que existiam antes mas não vieram no envio (usuário excluiu).
            // Segurança: só remove quem NÃO tem apontamento registrado ainda.
            $stmt_antigos = $pdo->prepare("SELECT id FROM op_produtos WHERE op_id = ? AND quantidade_apontada = 0");
            $stmt_antigos->execute([$op_id]);
            $antigos_sem_apontamento = $stmt_antigos->fetchAll(PDO::FETCH_COLUMN);

            $ids_para_excluir = array_diff($antigos_sem_apontamento, $ids_enviados);
            if (!empty($ids_para_excluir)) {
                $placeholders = implode(',', array_fill(0, count($ids_para_excluir), '?'));
                $stmt_del = $pdo->prepare("DELETE FROM op_produtos WHERE id IN ($placeholders)");
                $stmt_del->execute(array_values($ids_para_excluir));
            }

            $pdo->commit();

            $_SESSION['flash_mensagem'] = "Ordem de Produção atualizada com sucesso!";
            $_SESSION['flash_tipo'] = 'sucesso';
        } elseif ($_POST['acao'] === 'cancelar_op') {
            $stmt = $pdo->prepare("UPDATE ordens_producao SET status = 'CANCELADO' WHERE id = ?");
            $stmt->execute([$op_id]);

            $_SESSION['flash_mensagem'] = "Ordem de Produção CANCELADA com sucesso!";
            $_SESSION['flash_tipo'] = 'sucesso';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_mensagem'] = "Erro na operação: " . $e->getMessage();
        $_SESSION['flash_tipo'] = 'erro';
    }

    header("Location: programacao_pcp.php");
    exit;
}

try {
    $linhas_dropdown = $pdo->query("SELECT id, login FROM linhas WHERE fabrica > 0 ORDER BY login")->fetchAll(PDO::FETCH_ASSOC);

    // Busca as OPs e faz o JOIN com a tabela de usuários para saber quem criou
    $stmt_ops = $pdo->query("
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.observacao_almoxarifado, op.nome_separador,
               l.login as linha_nome,
               u.nome_completo as nome_criador,
               (SELECT SUM(quantidade_planejada) FROM op_produtos WHERE op_id = op.id) as total_planejado,
               (SELECT SUM(quantidade_apontada) FROM op_produtos WHERE op_id = op.id) as total_apontado,
               (SELECT COUNT(id) FROM op_produtos WHERE op_id = op.id) as qtd_diferentes_pas,
               (SELECT GROUP_CONCAT(CONCAT(p.codigo, ' (', op_prod.quantidade_planejada, ')') SEPARATOR ', ') FROM op_produtos op_prod JOIN produtos p ON op_prod.produto_id = p.id WHERE op_prod.op_id = op.id) as lista_produtos
        FROM ordens_producao op 
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN usuarios u ON op.criador_id = u.id
        ORDER BY op.data_planejada DESC, op.id DESC
    ");
    $fila_producao = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);

    // Busca os produtos de cada OP (agora incluindo os IDs necessários pra edição)
    foreach ($fila_producao as &$f) {
        $stmt_prods = $pdo->prepare("SELECT op_p.id as op_produto_id, op_p.produto_id, p.codigo, p.descricao, op_p.quantidade_planejada, op_p.quantidade_apontada FROM op_produtos op_p JOIN produtos p ON op_p.produto_id = p.id WHERE op_p.op_id = ?");
        $stmt_prods->execute([$f['id']]);
        $f['produtos'] = $stmt_prods->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($f);
} catch (PDOException $e) {
    die("Erro ao carregar dados do PCP: " . $e->getMessage());
}

$status_meta = [
    'PROGRAMADO'           => ['key' => 'queued',  'label' => 'Programado',        'cor' => 'pink'],
    'AGUARDANDO INICIO'    => ['key' => 'waiting',  'label' => 'Aguardando Início', 'cor' => 'amber'],
    'PRODUÇÃO INICIADA'    => ['key' => 'running',  'label' => 'Em Produção',       'cor' => 'blue'],
    'PRODUÇÃO FINALIZADA'  => ['key' => 'done',     'label' => 'Finalizado',        'cor' => 'emerald'],
    'PAUSADO'              => ['key' => 'paused',   'label' => 'Pausado',           'cor' => 'red'],
    'CANCELADO'            => ['key' => 'off',      'label' => 'Cancelado',         'cor' => 'slate'],
];

$contagem_status = [];
foreach ($status_meta as $nome => $meta) {
    $contagem_status[$nome] = 0;
}
foreach ($fila_producao as $f) {
    if (isset($contagem_status[$f['status']])) {
        $contagem_status[$f['status']]++;
    }
}

function normalizaStatus($str)
{
    $str = strtoupper(trim($str));
    $str = str_replace(
        ['Ç', 'Ã', 'Á', 'À', 'É', 'Í', 'Ó', 'Ú', 'Â', 'Ê'],
        ['C', 'A', 'A', 'A', 'E', 'I', 'O', 'U', 'A', 'E'],
        $str
    );
    return $str;
}

// 1. Contador Dinâmico Protegido
$count_status = [
    'PROGRAMADO' => 0,
    'AGUARDANDO INICIO' => 0,
    'PRODUCAO INICIADA' => 0,
    'PRODUCAO FINALIZADA' => 0,
    'PAUSADO' => 0,
    'CANCELADO' => 0
];

foreach ($fila_producao as $f) {
    $status_limpo = normalizaStatus($f['status']);
    if (isset($count_status[$status_limpo])) {
        $count_status[$status_limpo]++;
    } else {
        // Caso apareça um status completamente alienígena no futuro
        $count_status[$status_limpo] = 1;
    }
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

    <?php include 'header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 space-y-8">

        <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Programação de OPs</h2>
                <p class="text-sm text-slate-500 font-medium">Controle de fila e ordens de produção para a fábrica.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <?php foreach ($status_meta as $nome => $meta): $cor = $meta['cor']; ?>
                <div class="bg-<?= $cor ?>-100 rounded-lg px-4 py-4 border border-<?= $cor ?>-200 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-<?= $cor ?>-500 shrink-0"></span>
                        <span class="text-[11px] font-bold uppercase tracking-widest text-<?= $cor ?>-800 truncate"><?= $meta['label'] ?></span>
                    </div>
                    <div class="font-mono text-3xl font-extrabold text-<?= $cor ?>-900 mt-2 tabular-nums"><?= str_pad($contagem_status[$nome], 2, '0', STR_PAD_LEFT) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-lg shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?= $mensagem ?>
            </div>
        <?php endif; ?>

        <div class="bg-white p-4 md:px-6 md:py-4 rounded-xl shadow-sm border border-slate-200/60 flex flex-col md:flex-row gap-4 md:gap-6 items-center mb-6">

            <div class="w-full md:w-1/3 flex flex-col justify-center shrink-0">
                <div class="flex items-center gap-2 mb-0.5">
                    <h3 class="text-base font-bold text-slate-800 tracking-tight">Importar via Planilha</h3>

                    <div class="relative group flex items-center">
                        <a href="modelo_importacao_pcp.xlsx" download title="Baixar modelo da planilha" class="w-7 h-7 flex items-center justify-center rounded-full bg-slate-100 hover:bg-blue-100 text-slate-400 hover:text-blue-600 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                        </a>

                        <div class="absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 w-52 p-2.5 bg-blue-50 border border-blue-100 rounded-lg shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 flex gap-2 items-start pointer-events-none">
                            <svg class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-[14px] text-blue-800 font-medium leading-relaxed">
                                Baixar modelo de planilha.
                            </p>
                            <div class="absolute w-2 h-2 bg-blue-50 border-b border-r border-blue-100 transform rotate-45 left-1/2 -translate-x-1/2 -bottom-1"></div>
                        </div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 font-medium leading-tight">Programe múltiplas linhas e OPs em lote.</p>
            </div>

            <div class="w-full md:w-2/3">
                <form action="importa_planilha.php" method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-3 items-stretch w-full">

                    <input type="file" name="arquivo_excel" id="input_excel_file" accept=".xlsx" required class="hidden">

                    <div class="flex-1 relative border-2 border-dashed border-slate-300 hover:border-emerald-300 bg-slate-50 rounded-lg transition-all flex items-center justify-center min-h-[48px]" id="container_dropzone">

                        <label for="input_excel_file" id="upload_default_state" class="flex flex-row items-center justify-center gap-2 px-4 cursor-pointer hover:bg-slate-100 transition-colors w-full h-full rounded-lg group">
                            <svg class="w-5 h-5 text-slate-400 group-hover:text-emerald-500 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="text-xs font-semibold text-slate-500 group-hover:text-emerald-600 transition-colors">Clique para anexar arquivo .XLSX (Excel multi-abas)</span>
                        </label>

                        <div id="upload_selected_state" class="hidden flex items-center justify-between px-3 w-full h-full bg-emerald-50 rounded-lg border border-emerald-200">
                            <div class="flex items-center gap-2 overflow-hidden pr-2">
                                <div class="bg-emerald-100 p-1.5 rounded shrink-0">
                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </div>
                                <div class="flex flex-col truncate leading-none justify-center">
                                    <span class="text-[9px] font-bold text-emerald-600 uppercase tracking-widest mb-0.5">Anexado</span>
                                    <span id="file_name_display" class="text-xs font-bold text-slate-800 truncate">planilha.xlsx</span>
                                </div>
                            </div>
                            <button type="button" id="btn_remove_file" title="Remover arquivo" class="bg-white hover:bg-rose-500 border border-slate-200 hover:border-rose-500 text-slate-400 hover:text-white rounded-md p-1 transition-colors shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="btn_submit_excel" class="w-full sm:w-auto bg-slate-800 hover:bg-black text-white font-bold px-6 rounded-lg text-xs transition-all shrink-0 shadow-sm flex flex-row items-center justify-center gap-2 min-h-[48px]">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                        Importar Excel
                    </button>
                </form>
            </div>
        </div>

        <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 space-y-6">

            <div>

                <div class="flex items-center gap-2 mb-4 border-b border-slate-100 pb-2">
                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-600 text-xs font-bold">1</span>
                    <h3 class="text-base font-bold text-slate-800">Dados da Ordem de Produção</h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Número da OP</label>
                        <input type="text" name="op_sistema" required placeholder="Ex: OP-2026-04" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Destinar à Linha</label>
                        <select name="linha_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors appearance-none bg-white">
                            <option value="">Selecione...</option>
                            <?php foreach ($linhas_dropdown as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= strtoupper($l['login']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Data planejada de produção</label>
                        <input type="date" name="data_planejada" required value="<?= date('Y-m-d') ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors text-slate-600">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1.5">Observações (PCP)</label>
                        <input type="text" name="observacao" placeholder="Ex: Urgência, fracionar lote..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors">
                    </div>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-4 border-b border-slate-100 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-600 text-xs font-bold">2</span>
                        <h3 class="text-base font-bold text-slate-800">Produtos a Produzir</h3>
                    </div>
                    <button type="button" onclick="adicionarPA()" class="text-xs bg-white border border-blue-300 text-blue-600 hover:bg-blue-50 font-bold px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1 shadow-sm">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Adicionar Produto (PA)
                    </button>
                </div>

                <div id="container_pas" class="space-y-4"></div>
            </div>

            <div class="pt-2 pb-2 border-t border-slate-100">
                <button type="submit" class="w-full md:w-auto bg-slate-800 hover:bg-black text-white font-bold py-3.5 px-8 rounded-lg shadow-sm transition-all text-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Salvar Programação
                </button>
            </div>
        </form>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-5 mb-6">
            <div class="flex items-center gap-2 mb-4 border-b border-slate-100 pb-3">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                </svg>
                <h3 class="text-base font-bold text-slate-800">Filtros e Status</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <input type="text" id="filtro_op" onkeyup="aplicarFiltros()" placeholder=" Buscar por OP..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                <input type="text" id="filtro_linha" onkeyup="aplicarFiltros()" placeholder=" Buscar por Linha..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                <input type="text" id="filtro_produto" onkeyup="aplicarFiltros()" placeholder=" Buscar por Produto/Cód..." class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
            </div>

            <div class="flex flex-wrap gap-3">
                <button onclick="toggleStatusFilter('PROGRAMADO', this)" data-color="pink" data-shade="500" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-pink-100 text-pink-900 hover:bg-pink-500 hover:text-white shadow-sm  flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-pink-500 group-hover:bg-white shrink-0 transition-colors"></span> Programado (<?= $count_status['PROGRAMADO'] ?? 0 ?>)
                </button>
                <button onclick="toggleStatusFilter('AGUARDANDO INICIO', this)" data-color="amber" data-shade="500" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-amber-100 text-amber-900 hover:bg-amber-500 hover:text-white shadow-sm  flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-amber-500 group-hover:bg-white shrink-0 transition-colors"></span> Aguardando Início (<?= $count_status['AGUARDANDO INICIO'] ?? 0 ?>)
                </button>
                <button onclick="toggleStatusFilter('PRODUCAO INICIADA', this)" data-color="blue" data-shade="600" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-blue-100 text-blue-900 hover:bg-blue-600 hover:text-white shadow-sm  flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-blue-600 group-hover:bg-white shrink-0 transition-colors"></span> Produção Iniciada (<?= $count_status['PRODUCAO INICIADA'] ?? 0 ?>)
                </button>
                <button onclick="toggleStatusFilter('PAUSADO', this)" data-color="red" data-shade="500" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-red-100 text-red-900 hover:bg-red-500 hover:text-white shadow-sm hover:shadow-lg  flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-red-500 group-hover:bg-white shrink-0 transition-colors"></span> Pausada (<?= $count_status['PAUSADO'] ?? 0 ?>)
                </button>
                <button onclick="toggleStatusFilter('PRODUCAO FINALIZADA', this)" data-color="emerald" data-shade="500" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-emerald-100 text-emerald-900 hover:bg-emerald-500 hover:text-white shadow-sm  flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-emerald-500 group-hover:bg-white shrink-0 transition-colors"></span> Finalizada (<?= $count_status['PRODUCAO FINALIZADA'] ?? 0 ?>)
                </button>
                <button onclick="toggleStatusFilter('CANCELADO', this)" data-color="slate" data-shade="700" class="btn-filtro-status group px-4 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 bg-slate-200 text-slate-900 hover:bg-slate-700 hover:text-white shadow-sm hover:shadow-lg hover:shadow-slate-400/50 flex items-center gap-2">
                    <span class="dot-status w-3 h-3 rounded-full bg-slate-700 group-hover:bg-white shrink-0 transition-colors"></span> Cancelada (<?= $count_status['CANCELADO'] ?? 0 ?>)
                </button>
            </div>
            <p class="text-[10px] text-slate-400 font-semibold mt-3 italic">Clique nos botões coloridos para filtrar as OPs pelo seu status. Pode selecionar mais que um.</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="text-base font-bold text-slate-800">Painel Geral de Ordens Cadastradas</h3>
                <span class="text-xs font-medium text-slate-500 italic">Clique numa linha para expandir detalhes</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                        <tr>
                            <th class="p-4 w-10"></th>
                            <th class="p-4">Data Prog.</th>
                            <th class="p-4">OP Sistema</th>
                            <th class="p-4">Linha Destino</th>
                            <th class="p-4">Programado por</th>
                            <th class="p-4 text-center">Volume Total</th>
                            <th class="p-4 text-center">Apontado</th>
                            <th class="p-4">Status Atual</th>
                            <th class="p-4 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="corpo_tabela_ops">
                        <?php foreach ($fila_producao as $f):

                            $status_limpo = normalizaStatus($f['status']); // Aplica a faxina antes de usar

                            // Cores alinhadas com o status normalizado
                            $bg_badge = 'bg-slate-100 text-slate-600 border-slate-300';
                            if ($status_limpo == 'PROGRAMADO') $bg_badge = 'bg-pink-100 text-pink-900 border-pink-300';
                            if ($status_limpo == 'AGUARDANDO INICIO') $bg_badge = 'bg-amber-100 text-amber-900 border-amber-300';
                            if ($status_limpo == 'PRODUCAO INICIADA') $bg_badge = 'bg-blue-100 text-blue-900 border-blue-300';
                            if ($status_limpo == 'PRODUCAO FINALIZADA') $bg_badge = 'bg-emerald-100 text-emerald-900 border-emerald-300';
                            if ($status_limpo == 'PAUSADO') $bg_badge = 'bg-red-100 text-red-900 border-red-300';

                            // Apenas para mostrar visualmente com acento na tela (pro usuário ler bonito)
                            $status_display = str_replace('PRODUCAO', 'PRODUÇÃO', $status_limpo);

                            // Corrigido: compara com o status normalizado (sem acento), não o valor cru do banco
                            $op_editavel = !in_array($status_limpo, ['PRODUCAO FINALIZADA', 'CANCELADO']);
                        ?>
                            <tr class="linha-op-principal hover:bg-blue-50/50 cursor-pointer transition-colors group"
                                data-status="<?= $status_limpo ?>"
                                data-op="<?= strtolower(htmlspecialchars($f['op_sistema'])) ?>"
                                data-linha="<?= strtolower(htmlspecialchars($f['linha_nome'] ?? '')) ?>"
                                data-produtos="<?= strtolower(htmlspecialchars($f['lista_produtos'])) ?>"
                                onclick="toggleDetalhesOP('detalhe_op_<?= $f['id'] ?>', 'icone_op_<?= $f['id'] ?>')">

                                <td class="p-4 text-center">
                                    <div class="w-6 h-6 rounded-full bg-slate-100 group-hover:bg-blue-100 flex items-center justify-center transition-colors">
                                        <svg id="icone_op_<?= $f['id'] ?>" class="w-4 h-4 text-slate-400 group-hover:text-blue-600 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </td>
                                <td class="p-4 text-slate-500"><?= date('d/m/Y', strtotime($f['data_planejada'])) ?></td>
                                <td class="p-4 font-bold text-slate-900"><?= htmlspecialchars($f['op_sistema']) ?></td>
                                <td class="p-4 uppercase font-bold text-blue-600 text-xs"><?= htmlspecialchars($f['linha_nome'] ?? 'Ñ definida') ?></td>
                                <td class="p-4 text-xs font-semibold text-slate-600"><?= htmlspecialchars($f['nome_criador'] ?? 'Desconhecido') ?></td>
                                <td class="p-4 text-center font-bold text-slate-800"><?= number_format($f['total_planejado'], 0, ',', '.') ?> <span class="text-[10px] font-normal text-slate-400 block mt-0.5"><?= $f['qtd_diferentes_pas'] ?> produtos</span></td>
                                <td class="p-4 text-center font-bold text-slate-500"><?= number_format($f['total_apontado'], 0, ',', '.') ?></td>
                                <td class="p-4">
                                    <span class="px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest border <?= $bg_badge ?> shadow-sm">
                                        <?= $status_display ?>
                                    </span>
                                </td>
                                <td class="p-4" onclick="event.stopPropagation()">
                                    <?php if ($op_editavel): ?>
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button" onclick="document.getElementById('modal_editar_op_<?= $f['id'] ?>').showModal()" title="Editar OP" class="w-8 h-8 flex items-center justify-center rounded-lg bg-white border border-slate-200 text-slate-400 hover:bg-blue-50 hover:border-blue-200 hover:text-blue-600 transition-colors shadow-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                </svg>
                                            </button>
                                            <button type="button" onclick="document.getElementById('modal_cancelar_op_<?= $f['id'] ?>').showModal()" title="Cancelar OP" class="w-8 h-8 flex items-center justify-center rounded-lg bg-white border border-slate-200 text-slate-400 hover:bg-rose-50 hover:border-rose-200 hover:text-rose-600 transition-colors shadow-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center">
                                            <span class="text-[10px] text-slate-300 font-semibold uppercase">—</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr id="detalhe_op_<?= $f['id'] ?>" class="linha-op-detalhe hidden bg-slate-50/80 border-b-2 border-slate-200">
                                <td colspan="9" class="p-0">
                                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8 shadow-inner">
                                        <div>
                                            <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                </svg>
                                                Produtos Acabados
                                            </h4>
                                            <div class="space-y-2">
                                                <?php foreach ($f['produtos'] as $prod):
                                                    $pct = $prod['quantidade_planejada'] > 0 ? ($prod['quantidade_apontada'] / $prod['quantidade_planejada']) * 100 : 0;
                                                    $corBarra = $pct >= 100 ? 'bg-emerald-500' : 'bg-blue-500';
                                                ?>
                                                    <div class="bg-white p-3 rounded-lg border border-slate-200/60 shadow-sm">
                                                        <div class="flex justify-between items-start mb-2">
                                                            <div class="flex-1 pr-2">
                                                                <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded mr-1">CÓD: <?= $prod['codigo'] ?></span>
                                                                <span class="text-xs font-bold text-slate-700 uppercase"><?= htmlspecialchars($prod['descricao']) ?></span>
                                                            </div>
                                                            <div class="text-right shrink-0">
                                                                <div class="text-[10px] font-bold text-slate-400 uppercase">Produzido</div>
                                                                <div class="text-sm font-black <?= $pct >= 100 ? 'text-emerald-600' : 'text-blue-600' ?>"><?= number_format($prod['quantidade_apontada'], 0, ',', '.') ?> <span class="text-xs text-slate-400 font-medium">/ <?= number_format($prod['quantidade_planejada'], 0, ',', '.') ?></span></div>
                                                            </div>
                                                        </div>
                                                        <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                                            <div class="h-full <?= $corBarra ?>" style="width: <?= min(100, $pct) ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="space-y-4">
                                            <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17l6-6-6-6"></path>
                                                </svg>
                                                Informações Logísticas e PCP
                                            </h4>

                                            <div class="bg-white p-4 rounded-lg border border-slate-200/60 shadow-sm flex flex-col gap-1">
                                                <span class="text-[10px] font-bold text-pink-500 uppercase tracking-wider">Anotações do PCP:</span>
                                                <span class="text-sm font-medium text-slate-700"><?= !empty($f['observacao_almoxarifado']) ? htmlspecialchars($f['observacao_almoxarifado']) : '<em class="text-slate-400">Nenhuma observação registada.</em>' ?></span>
                                            </div>

                                            <div class="bg-white p-4 rounded-lg border border-slate-200/60 shadow-sm">
                                                <span class="text-[10px] font-bold text-amber-500 uppercase tracking-wider block mb-2">Retorno do Almoxarifado:</span>
                                                <ul class="text-xs font-medium text-slate-600 space-y-1.5">
                                                    <li class="flex justify-between">
                                                        <span class="text-slate-400">Responsável Separação:</span>
                                                        <strong class="text-slate-800"><?= !empty($f['nome_separador']) ? htmlspecialchars($f['nome_separador']) : 'Aguardando' ?></strong>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php foreach ($fila_producao as $f): $status_limpo = normalizaStatus($f['status']);
            if (!in_array($status_limpo, ['PRODUCAO FINALIZADA', 'CANCELADO'])): ?>
                <!-- MODAL: EDITAR OP -->
                <dialog id="modal_editar_op_<?= $f['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-2xl bg-white backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm m-auto overflow-hidden">
                    <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Editar Ordem de Produção</h3>
                                <p class="text-xs font-medium text-slate-400"><?= htmlspecialchars($f['op_sistema']) ?></p>
                            </div>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 hover:bg-rose-50 rounded-lg p-1.5 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <form method="POST" class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                        <input type="hidden" name="acao" value="editar_op">
                        <input type="hidden" name="op_id" value="<?= $f['id'] ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5">Mudar Linha de Destino</label>
                                <select name="linha_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors bg-white">
                                    <option value="">Selecione a linha...</option>
                                    <?php foreach ($linhas_dropdown as $l): ?>
                                        <option value="<?= $l['id'] ?>" <?= ($f['linha_nome'] == $l['login']) ? 'selected' : '' ?>><?= strtoupper($l['login']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5">Reprogramar Data</label>
                                <input type="date" name="data_planejada" value="<?= $f['data_planejada'] ?>" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Atualizar Observação</label>
                            <input type="text" name="observacao" value="<?= htmlspecialchars($f['observacao_almoxarifado']) ?>" placeholder="Instruções para fábrica/almoxarifado..." class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-medium focus:ring-2 focus:ring-blue-100 focus:border-blue-400 focus:outline-none transition-colors">
                        </div>

                        <div class="pt-2 border-t border-slate-100">
                            <div class="flex justify-between items-center mb-3">
                                <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide">Produtos Acabados</label>
                                <button type="button" onclick="adicionarPAEdicao(<?= $f['id'] ?>)" class="text-xs bg-white border border-blue-300 text-blue-600 hover:bg-blue-50 font-bold px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1 shadow-sm">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    Adicionar Produto
                                </button>
                            </div>

                            <div id="container_pas_edicao_<?= $f['id'] ?>" class="space-y-3">
                                <?php foreach ($f['produtos'] as $prod): ?>
                                    <div class="pa-block bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-col md:flex-row gap-4 items-end relative" data-apontado="<?= (int)$prod['quantidade_apontada'] ?>">
                                        <input type="hidden" name="op_produto_id[]" value="<?= $prod['op_produto_id'] ?>">
                                        <input type="hidden" name="pa_id[]" class="pa-id-hidden" value="<?= $prod['produto_id'] ?>">

                                        <div class="w-full md:w-1/4">
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Código</label>
                                            <div class="flex rounded-lg overflow-hidden border border-slate-300 bg-white">
                                                <input type="text" onblur="buscarPA(this)" value="<?= htmlspecialchars($prod['codigo']) ?>" class="input-codigo-busca w-full px-3 py-2 text-sm font-bold focus:outline-none text-slate-700 bg-transparent">
                                                <button type="button" onclick="abrirBuscadorGlobal(this, 'produto')" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-3 border-l border-slate-200 flex items-center justify-center transition-colors" title="Consultar Produto">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="w-full md:flex-1">
                                            <span class="desc-pa text-xs font-bold text-emerald-600 uppercase"><?= htmlspecialchars($prod['descricao']) ?></span>
                                            <?php if ($prod['quantidade_apontada'] > 0): ?>
                                                <span class="block text-[10px] font-semibold text-amber-600 mt-0.5">Já produzido: <?= number_format($prod['quantidade_apontada'], 0, ',', '.') ?> un. — não pode ser removido</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="w-full md:w-1/4">
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Quantidade</label>
                                            <input type="number" name="pa_qtd[]" required min="<?= max(1, (int)$prod['quantidade_apontada']) ?>" value="<?= $prod['quantidade_planejada'] ?>" class="pa-qtd-input w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-white">
                                        </div>

                                        <button type="button" onclick="removerPAEdicao(this)" class="absolute top-2 right-2 text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 rounded-md p-1.5 shadow-sm border border-slate-200 transition-colors" title="Remover Produto">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pt-2 flex gap-3">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 hover:bg-slate-50 font-bold py-3 rounded-lg text-sm transition-colors">Voltar</button>
                            <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-3 rounded-lg text-sm shadow-sm transition-colors flex items-center justify-center gap-2">
                                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Salvar Alterações
                            </button>
                        </div>
                    </form>
                </dialog>

                <!-- MODAL: CANCELAR OP -->
                <dialog id="modal_cancelar_op_<?= $f['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm m-auto overflow-hidden">
                    <div class="p-6 text-center">
                        <div class="w-14 h-14 bg-rose-50 border border-rose-100 text-rose-500 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-slate-800 mb-1.5">Cancelar esta OP?</h3>
                        <p class="text-xs font-medium text-slate-500 mb-6 leading-relaxed">Tem certeza que deseja cancelar a OP <span class="font-bold text-slate-700"><?= htmlspecialchars($f['op_sistema']) ?></span>? Essa ação não pode ser desfeita.</p>

                        <form method="POST" class="flex gap-3">
                            <input type="hidden" name="acao" value="cancelar_op">
                            <input type="hidden" name="op_id" value="<?= $f['id'] ?>">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 hover:bg-slate-50 font-bold py-2.5 rounded-lg text-sm transition-colors">Voltar</button>
                            <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm shadow-sm transition-colors">Sim, Cancelar</button>
                        </form>
                    </div>
                </dialog>
        <?php endif;
        endforeach; ?>
    </div>

    <dialog id="modal_buscador_mes" class="p-0 rounded-2xl shadow-2xl border-0 w-[95%] max-w-xl bg-white m-auto backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm">
        <div class="p-6 space-y-4">
            <div class="flex justify-between items-center border-b border-slate-100 pb-4">
                <h3 id="titulo_buscador" class="text-sm font-bold text-slate-800 uppercase tracking-wide">Consultar Cadastro</h3>
                <button type="button" onclick="document.getElementById('modal_buscador_mes').close()" class="text-slate-400 hover:text-rose-500 hover:bg-rose-50 rounded-lg p-1.5 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input type="text" id="input_termo_busca" onkeyup="executarBuscaDinamica()" placeholder="Digite o código ou nome..." class="w-full pl-10 pr-4 py-3 border border-slate-300 rounded-xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-colors">
            </div>
            <div id="lista_resultados_busca" class="max-h-64 overflow-y-auto divide-y divide-slate-100 border border-slate-200 rounded-xl bg-slate-50 overflow-hidden">
                <div class="p-4 text-center text-xs text-slate-400 font-medium">Aguardando digitação...</div>
            </div>
        </div>
    </dialog>

    <template id="tpl_pa">
        <div class="pa-block bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative transition-all">
            <div class="bg-slate-50 p-5 flex flex-col md:flex-row gap-5 items-end">
                <input type="hidden" name="pa_id[__INDEX__]" class="pa-id-hidden">
                <div class="w-full md:w-1/4 relative">
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Cód. Produto (PA)</label>
                    <div class="flex rounded-lg overflow-hidden border border-slate-300 focus-within:ring-2 focus-within:ring-blue-100 focus-within:border-blue-400 transition-all bg-white">
                        <input type="text" onblur="buscarPA(this)" required placeholder="Ex: 9999" class="input-codigo-busca w-full px-3 py-2 text-sm font-bold focus:outline-none text-slate-700 bg-transparent">
                        <button type="button" onclick="abrirBuscadorGlobal(this, 'produto')" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-3 border-l border-slate-200 flex items-center justify-center transition-colors" title="Consultar Produto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="w-full md:flex-1 flex items-center mb-2">
                    <span class="desc-pa text-xs font-bold text-slate-400 uppercase">Aguardando código...</span>
                </div>
                <div class="w-full md:w-1/4">
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Quantidade Programada</label>
                    <input type="number" name="pa_qtd[__INDEX__]" required min="1" placeholder="Ex: 5000" class="pa-qtd-input w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all bg-white">
                </div>
                <button type="button" onclick="this.closest('.pa-block').remove()" class="absolute top-3 right-3 text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 rounded-md p-1.5 shadow-sm border border-slate-200 transition-colors" title="Remover Produto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </template>

    <script>
        let blockCounter = 0;
        window.onload = () => {
            adicionarPA();
        }

        function adicionarPA() {
            blockCounter++;
            const tpl = document.getElementById('tpl_pa').innerHTML.replace(/__INDEX__/g, blockCounter);
            const div = document.createElement('div');
            div.innerHTML = tpl;
            const block = div.firstElementChild;
            document.getElementById('container_pas').appendChild(block);
        }

        // ==========================================
        // LÓGICA DO ACORDEÃO E BUSCADOR
        // ==========================================
        function toggleDetalhesOP(rowId, iconId) {
            const detalheRow = document.getElementById(rowId);
            const icone = document.getElementById(iconId);

            if (detalheRow.classList.contains('hidden')) {
                detalheRow.classList.remove('hidden');
                icone.style.transform = 'rotate(180deg)';
                icone.classList.replace('text-slate-400', 'text-blue-600');
            } else {
                detalheRow.classList.add('hidden');
                icone.style.transform = 'rotate(0deg)';
                icone.classList.replace('text-blue-600', 'text-slate-400');
            }
        }

        let inputOrigemElement = null;
        let tabelaAlvoBusca = '';

        function abrirBuscadorGlobal(botaoClick, tabelaAlvo) {
            const rowTarget = botaoClick.closest('.pa-block');
            inputOrigemElement = rowTarget.querySelector('.input-codigo-busca');
            tabelaAlvoBusca = tabelaAlvo;
            document.getElementById('titulo_buscador').textContent = `Consultar Cadastro de Produtos Acabados`;
            document.getElementById('input_termo_busca').value = '';
            document.getElementById('lista_resultados_busca').innerHTML = `<div class="p-6 text-center text-sm text-slate-400 font-medium">Digite algo acima para iniciar a varredura...</div>`;
            document.getElementById('modal_buscador_mes').showModal();
            setTimeout(() => document.getElementById('input_termo_busca').focus(), 100);
        }

        async function executarBuscaDinamica() {
            const termo = document.getElementById('input_termo_busca').value.trim();
            const containerResultados = document.getElementById('lista_resultados_busca');
            if (termo.length < 2) {
                containerResultados.innerHTML = `<div class="p-6 text-center text-sm text-slate-400 font-medium">Digite pelo menos 2 caracteres...</div>`;
                return;
            }
            try {
                const resposta = await fetch(`busca_consulta.php?termo=${termo}&tabela=${tabelaAlvoBusca}`);
                const dados = await resposta.json();
                if (dados.length === 0) {
                    containerResultados.innerHTML = `<div class="p-6 text-center text-sm text-rose-500 font-bold bg-rose-50">Nenhum registro encontrado.</div>`;
                    return;
                }
                containerResultados.innerHTML = '';
                dados.forEach(item => {
                    const divOpcao = `
                        <div onclick="selecionarItemBuscador('${item.codigo}')" class="p-4 hover:bg-blue-50 cursor-pointer transition-colors flex justify-between items-center text-sm font-medium text-slate-700 group">
                            <div><strong class="font-bold text-slate-900 group-hover:text-blue-700">[${item.codigo}]</strong> ${item.descricao}</div>
                            <span class="text-xs text-blue-600 font-bold opacity-0 group-hover:opacity-100 transition-opacity">Selecionar &rarr;</span>
                        </div>
                    `;
                    containerResultados.insertAdjacentHTML('beforeend', divOpcao);
                });
            } catch (e) {
                containerResultados.innerHTML = `<div class="p-6 text-center text-sm text-rose-500 font-bold bg-rose-50">Erro na comunicação com o servidor.</div>`;
            }
        }

        function selecionarItemBuscador(codigoSelecionado) {
            if (inputOrigemElement) {
                inputOrigemElement.value = codigoSelecionado;
                document.getElementById('modal_buscador_mes').close();
                inputOrigemElement.focus();
                inputOrigemElement.blur();
            }
        }

        async function buscarPA(inputElement) {
            const codigo = inputElement.value.trim();
            const label = inputElement.closest('.pa-block').querySelector('.desc-pa');
            const hidden = inputElement.closest('.pa-block').querySelector('.pa-id-hidden');
            if (codigo === '') {
                label.textContent = 'Aguardando código...';
                label.className = 'desc-pa text-xs font-bold text-slate-400 uppercase';
                hidden.value = '';
                return;
            }
            label.textContent = 'Buscando PA...';
            label.className = 'desc-pa text-xs font-bold text-blue-500 uppercase';
            try {
                const res = await fetch(`busca_produto.php?codigo=${codigo}`);
                const dados = await res.json();
                if (dados.id) {
                    label.textContent = dados.descricao;
                    label.className = 'desc-pa text-xs font-bold text-emerald-600 uppercase';
                    hidden.value = dados.id;
                } else {
                    label.textContent = 'PRODUTO NÃO ENCONTRADO';
                    label.className = 'desc-pa text-xs font-bold text-rose-500 uppercase';
                    hidden.value = '';
                }
            } catch (e) {
                label.textContent = 'ERRO';
            }
        }

        function adicionarPAEdicao(opId) {
            const container = document.getElementById('container_pas_edicao_' + opId);
            const div = document.createElement('div');
            div.className = 'pa-block bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-col md:flex-row gap-4 items-end relative';
            div.dataset.apontado = '0';
            div.innerHTML = `
        <input type="hidden" name="op_produto_id[]" value="">
        <input type="hidden" name="pa_id[]" class="pa-id-hidden" value="">
        <div class="w-full md:w-1/4">
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Código</label>
            <div class="flex rounded-lg overflow-hidden border border-slate-300 bg-white">
                <input type="text" onblur="buscarPA(this)" placeholder="Ex: 9999" class="input-codigo-busca w-full px-3 py-2 text-sm font-bold focus:outline-none text-slate-700 bg-transparent">
                <button type="button" onclick="abrirBuscadorGlobal(this, 'produto')" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-3 border-l border-slate-200 flex items-center justify-center transition-colors" title="Consultar Produto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>
            </div>
        </div>
        <div class="w-full md:flex-1"><span class="desc-pa text-xs font-bold text-slate-400 uppercase">Aguardando código...</span></div>
        <div class="w-full md:w-1/4">
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Quantidade</label>
            <input type="number" name="pa_qtd[]" min="1" required placeholder="Ex: 5000" class="pa-qtd-input w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-white">
        </div>
        <button type="button" onclick="removerPAEdicao(this)" class="absolute top-2 right-2 text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 rounded-md p-1.5 shadow-sm border border-slate-200 transition-colors" title="Remover Produto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    `;
            container.appendChild(div);
        }

        function removerPAEdicao(botao) {
            const block = botao.closest('.pa-block');
            const apontado = parseInt(block.dataset.apontado || '0');
            if (apontado > 0) {
                alert('Não é possível remover este produto: ele já possui quantidade apontada (produção já iniciada nele).');
                return;
            }
            block.remove();
        }
        // ==========================================
        // LÓGICA DE UPLOAD VISUAL DE CSV
        // ==========================================
        const inputExcelFile = document.getElementById('input_excel_file');
        const uploadDefaultState = document.getElementById('upload_default_state');
        const uploadSelectedState = document.getElementById('upload_selected_state');
        const fileNameDisplay = document.getElementById('file_name_display');
        const btnRemoveFile = document.getElementById('btn_remove_file');
        const btnSubmitCsv = document.getElementById('btn_submit_csv');
        const containerDropzone = document.getElementById('container_dropzone');

        // Quando o usuário seleciona um arquivo
        inputExcelFile.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                const arquivo = this.files[0];
                fileNameDisplay.textContent = arquivo.name;

                uploadDefaultState.classList.add('hidden');
                uploadSelectedState.classList.remove('hidden');

                containerDropzone.classList.remove('border-dashed', 'border-slate-300');
                containerDropzone.classList.add('border-solid', 'border-emerald-300');
            }
        });

        // Quando o usuário clica no "X" para remover o arquivo
        btnRemoveFile.addEventListener('click', function() {
            // Limpa o input file
            inputExcelFile.value = '';

            // Retorna a div ao estado original
            uploadSelectedState.classList.add('hidden');
            uploadDefaultState.classList.remove('hidden');

            // Devolve a borda tracejada
            containerDropzone.classList.remove('border-solid', 'border-emerald-300');
            containerDropzone.classList.add('border-dashed', 'border-slate-300');

            // Desabilita o botão de submit novamente
            btnSubmitCsv.disabled = true;
            btnSubmitCsv.classList.add('opacity-50', 'cursor-not-allowed');
            btnSubmitCsv.classList.remove('hover:-translate-y-0.5');
        });

        let statusAtivos = [];

        // Função acionada ao clicar nos botões de Status
        function toggleStatusFilter(status, btnElement) {
            const index = statusAtivos.indexOf(status);
            const cor = btnElement.dataset.color || 'slate';
            const tom = btnElement.dataset.shade || '500';
            const dot = btnElement.querySelector('.dot-status');

            if (index > -1) {
                // Desativa o filtro
                statusAtivos.splice(index, 1);
                btnElement.classList.remove('text-white', `bg-${cor}-${tom}`, 'shadow-lg', `shadow-${cor}-300/50`, 'ring-2', 'ring-offset-2', `ring-${cor}-300`);
                if (dot) dot.classList.remove('bg-white');
            } else {
                // Ativa o filtro
                statusAtivos.push(status);
                btnElement.classList.add('text-white', `bg-${cor}-${tom}`, 'shadow-lg', `shadow-${cor}-300/50`, 'ring-2', 'ring-offset-2', `ring-${cor}-300`);
                if (dot) dot.classList.add('bg-white');
            }

            aplicarFiltros();
        }

        // Função Central de Filtragem (Texto + Status)
        function aplicarFiltros() {
            const termoOp = document.getElementById('filtro_op').value.toLowerCase();
            const termoLinha = document.getElementById('filtro_linha').value.toLowerCase();
            const termoProduto = document.getElementById('filtro_produto').value.toLowerCase();

            const linhasPrincipais = document.querySelectorAll('.linha-op-principal');

            linhasPrincipais.forEach(row => {
                // Pega os dados ocultos no HTML da linha
                const statusRow = row.getAttribute('data-status');
                const opRow = row.getAttribute('data-op');
                const linhaRow = row.getAttribute('data-linha');
                const prodRow = row.getAttribute('data-produtos');

                // Lógica de Correspondência
                const matchStatus = (statusAtivos.length === 0) || statusAtivos.includes(statusRow);
                const matchOp = opRow.includes(termoOp);
                const matchLinha = linhaRow.includes(termoLinha);
                const matchProd = prodRow.includes(termoProduto);

                // Pega a linha de detalhe que está abaixo para ocultá-la caso a principal suma
                const detalheRowId = row.getAttribute('onclick').match(/'(.*?)'/)[1]; // Puxa o ID do onclick
                const detalheRow = document.getElementById(detalheRowId);

                // Se tudo bater, mostra. Senão, esconde.
                if (matchStatus && matchOp && matchLinha && matchProd) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    if (detalheRow) {
                        detalheRow.classList.add('hidden'); // Força esconder o detalhe
                        const icone = row.querySelector('svg');
                        if (icone) {
                            icone.style.transform = 'rotate(0deg)';
                            icone.classList.replace('text-blue-600', 'text-slate-400');
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>