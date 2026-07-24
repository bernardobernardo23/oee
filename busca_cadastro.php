<?php
session_start();
require 'conexao.php';

header('Content-Type: application/json');

$tipo_acesso = $_SESSION['tipo_acesso'] ?? null;
$eh_admin = $tipo_acesso === 'usuario' && ($_SESSION['setor'] ?? '') === 'ADMIN';
$eh_linha = $tipo_acesso === 'linha';

if (!$eh_admin && !$eh_linha) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$termo = trim($_GET['termo'] ?? '');

// Operador de linha só pode buscar insumos (pra registrar perda) --
// não tem motivo pra expor o cadastro de produtos acabados pra ele.
if ($eh_linha && $tipo !== 'componente') {
    echo json_encode(['ok' => true, 'itens' => []]);
    exit;
}

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