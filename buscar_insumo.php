<?php
// buscar_insumo.php - Retorna os dados do insumo em JSON para o Javascript
session_start();
require 'conexao.php';

header('Content-Type: application/json');

$codigo = $_GET['codigo'] ?? '';

if (empty($codigo)) {
    echo json_encode(['sucesso' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, descricao, tipo FROM itens_componentes WHERE codigo = ?");
    $stmt->execute([trim($codigo)]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        echo json_encode([
            'sucesso' => true,
            'id' => $item['id'],
            'descricao' => htmlspecialchars($item['descricao']),
            'tipo' => htmlspecialchars($item['tipo'])
        ]);
    } else {
        echo json_encode(['sucesso' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['sucesso' => false]);
}
?>