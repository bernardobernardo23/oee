<?php
session_start();
require 'conexao.php';
require 'notificacoes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tipo_acesso']) || !in_array($_SESSION['tipo_acesso'], ['usuario', 'linha'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

try {
    $categoria = $_GET['categoria'] ?? null;
    $categoria = in_array($categoria, ['separadas', 'formuladas', 'produzidas', 'outras'], true) ? $categoria : null;
    $dados = buscar_notificacoes_sessao($pdo, 20, $categoria);
    echo json_encode(['ok' => true] + $dados);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}