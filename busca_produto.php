<?php
require 'conexao.php';

$codigo = $_GET['codigo'] ?? '';

if (empty($codigo)) {
    echo json_encode(['erro' => 'vazio']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, descricao FROM produtos WHERE codigo = :codigo");
    $stmt->execute(['codigo' => $codigo]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($produto) {
        // Devolve JSON com ID e Descrição se encontrar
        echo json_encode(['id' => $produto['id'], 'descricao' => $produto['descricao']]);
    } else {
        echo json_encode(['erro' => 'nao_encontrado']);
    }
} catch (Exception $e) {
    echo json_encode(['erro' => 'erro_banco']);
}
?>