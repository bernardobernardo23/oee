<?php
session_start();
require 'conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || $_SESSION['setor'] !== 'ADMIN') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$termo = trim($_GET['termo'] ?? '');

if (!in_array($tipo, ['produto', 'componente'], true) || strlen($termo) < 2) {
    echo json_encode(['ok' => true, 'itens' => []]);
    exit;
}

try {
    $like = '%' . $termo . '%';

    if ($tipo === 'produto') {
        $stmt = $pdo->prepare("SELECT id, codigo, descricao FROM produtos WHERE codigo LIKE ? OR descricao LIKE ? ORDER BY codigo ASC LIMIT 30");
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->prepare("SELECT id, codigo, descricao, tipo FROM itens_componentes WHERE codigo LIKE ? OR descricao LIKE ? ORDER BY codigo ASC LIMIT 30");
        $stmt->execute([$like, $like]);
    }

    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'itens' => $itens]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}