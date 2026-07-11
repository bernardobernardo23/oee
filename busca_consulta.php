<?php
require 'conexao.php';

$termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';
$tabela = $_GET['tabela'] ?? ''; // 'produto' ou 'insumo'

if ($termo === '' || !in_array($tabela, ['produto', 'insumo'])) {
    echo json_encode([]);
    exit;
}

$busca = "%$termo%";

try {
    if ($tabela === 'produto') {
        // Busca na tabela de Produtos Acabados
        $stmt = $pdo->prepare("SELECT id, codigo, descricao FROM produtos WHERE codigo LIKE ? OR descricao LIKE ? LIMIT 15");
        $stmt->execute([$busca, $busca]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        // Busca na tabela de Componentes/Insumos
        $stmt = $pdo->prepare("SELECT id, codigo, descricao, tipo FROM itens_componentes WHERE codigo LIKE ? OR descricao LIKE ? LIMIT 15");
        $stmt->execute([$busca, $busca]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (PDOException $e) {
    echo json_encode([]);
}