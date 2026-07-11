<?php
require 'conexao.php';

$op_id = (int)($_GET['op_id'] ?? 0);

if ($op_id > 0) {
    // 1. Busca os Produtos Acabados da OP e a sua respectiva Meta (quantidade_planejada)
    $stmt_prod = $pdo->prepare("
        SELECT op_p.produto_id, op_p.quantidade_planejada, p.codigo, p.descricao 
        FROM op_produtos op_p
        JOIN produtos p ON op_p.produto_id = p.id
        WHERE op_p.op_id = ?
    ");
    $stmt_prod->execute([$op_id]);
    $produtos = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

    // 2. Busca todos os Insumos Planejados
    // (Como a gerência decidiu não engessar a lista, o PCP não lança mais insumos. 
    // Logo, isto retornará um array vazio, acionando a "Ficha Técnica Livre" no Frontend).
    $stmt_ins = $pdo->prepare("
        SELECT DISTINCT i.id as item_id, i.codigo, i.descricao, i.tipo
        FROM op_produtos op_p
        JOIN op_insumos op_i ON op_p.id = op_i.op_produto_id
        JOIN itens_componentes i ON op_i.item_id = i.id
        WHERE op_p.op_id = ?
        ORDER BY i.tipo ASC, i.descricao ASC
    ");
    $stmt_ins->execute([$op_id]);
    $insumos = $stmt_ins->fetchAll(PDO::FETCH_ASSOC);

    // Retorna ambos no formato JSON
    echo json_encode([
        'produtos' => $produtos,
        'insumos' => $insumos
    ]);
} else {
    echo json_encode(['produtos' => [], 'insumos' => []]);
}
?>