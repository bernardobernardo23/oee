<?php
session_start();
require 'conexao.php';

// Segurança: só ADMIN baixa logs de importação
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || $_SESSION['setor'] !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$log_id = (int)($_GET['id'] ?? 0);
if (!$log_id) {
    die("ID de log inválido.");
}

try {
    $stmt_cabecalho = $pdo->prepare("
        SELECT il.id, il.nome_arquivo, il.total_produtos_inseridos, il.total_componentes_inseridos, il.total_ignorados, il.criado_em, u.nome_completo
        FROM importacoes_log il
        JOIN usuarios u ON il.usuario_id = u.id
        WHERE il.id = ?
    ");
    $stmt_cabecalho->execute([$log_id]);
    $cabecalho = $stmt_cabecalho->fetch(PDO::FETCH_ASSOC);

    if (!$cabecalho) {
        die("Log de importação não encontrado.");
    }

    $stmt_itens = $pdo->prepare("
        SELECT linha_planilha, codigo, descricao, tipo_planilha, categoria, motivo
        FROM importacoes_log_itens
        WHERE importacao_id = ?
        ORDER BY linha_planilha ASC
    ");
    $stmt_itens->execute([$log_id]);
    $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao buscar o log: " . $e->getMessage());
}

$categorias_label = [
    'DUPLICADO_PRODUTO' => 'Produto já existia',
    'DUPLICADO_COMPONENTE' => 'Insumo já existia',
    'TIPO_NAO_TRATADO' => 'Tipo não reconhecido',
    'LINHA_INVALIDA' => 'Linha inválida',
];

$nome_arquivo_download = 'log_importacao_' . $log_id . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nome_arquivo_download . '"');

$out = fopen('php://output', 'w');

// BOM UTF-8 -- sem isso o Excel abre acentuação quebrada em CSV
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ["Log de Importação #{$cabecalho['id']}"], ';');
fputcsv($out, ["Arquivo:", $cabecalho['nome_arquivo']], ';');
fputcsv($out, ["Importado por:", $cabecalho['nome_completo']], ';');
fputcsv($out, ["Data/Hora:", date('d/m/Y H:i', strtotime($cabecalho['criado_em']))], ';');
fputcsv($out, ["Produtos inseridos:", $cabecalho['total_produtos_inseridos']], ';');
fputcsv($out, ["Insumos inseridos:", $cabecalho['total_componentes_inseridos']], ';');
fputcsv($out, ["Total ignorado:", $cabecalho['total_ignorados']], ';');
fputcsv($out, [], ';');

if (empty($itens)) {
    fputcsv($out, ["Nenhuma linha foi ignorada -- tudo que estava na planilha foi processado com sucesso."], ';');
} else {
    fputcsv($out, ['Linha na Planilha', 'Código', 'Descrição', 'Tipo na Planilha', 'Categoria', 'Motivo'], ';');
    foreach ($itens as $item) {
        fputcsv($out, [
            $item['linha_planilha'],
            $item['codigo'] ?? '(vazio)',
            $item['descricao'] ?? '(vazio)',
            $item['tipo_planilha'] ?? '(vazio)',
            $categorias_label[$item['categoria']] ?? $item['categoria'],
            $item['motivo'],
        ], ';');
    }
}

fclose($out);
exit;