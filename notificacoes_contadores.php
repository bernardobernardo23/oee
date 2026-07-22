<?php
session_start();
require 'conexao.php';
require 'notificacoes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

try {
    $contadores = [
        'separadas'  => contar_nao_lidas_categoria($pdo, 'separadas'),
        'formuladas' => contar_nao_lidas_categoria($pdo, 'formuladas'),
        'produzidas' => contar_nao_lidas_categoria($pdo, 'produzidas'),
        'outras'     => contar_nao_lidas_categoria($pdo, 'outras'),
    ];
    echo json_encode(['ok' => true, 'contadores' => $contadores]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}