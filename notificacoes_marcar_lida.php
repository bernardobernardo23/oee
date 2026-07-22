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

$payload = json_decode(file_get_contents('php://input'), true);
$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$marcar_todas = !empty($payload['todas']);
$categoria = $payload['categoria'] ?? null;
$categoria = in_array($categoria, ['separadas', 'formuladas', 'produzidas', 'outras'], true) ? $categoria : null;

try {
    // Trava de segurança: só marca como lida notificação que realmente
    // pertence a quem está pedindo (usuário/setor/linha da sessão atual),
    // pra ninguém conseguir marcar notificação alheia manipulando o ID.
    if ($_SESSION['tipo_acesso'] === 'usuario') {
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
        $setor = $_SESSION['setor'] ?? '';
        $condicao = "(destino_tipo = 'usuario' AND destino_usuario_id = ?) OR (destino_tipo = 'setor' AND destino_setor = ?)";
        $params_condicao = [$usuario_id, $setor];
    } else {
        $linha_id = $_SESSION['linha_id'] ?? 0;
        $condicao = "destino_tipo = 'linha' AND destino_linha_id = ?";
        $params_condicao = [$linha_id];
    }

    // "Marcar todas" respeita a aba ativa quando o PCP estiver com um
    // filtro de categoria selecionado -- senão marcaria como lidas
    // notificações de abas que a pessoa nunca chegou a abrir/ver.
    $filtro_categoria_sql = '';
    $params_categoria = [];
    if ($categoria) {
        $mapa = tipos_evento_por_categoria();
        if ($categoria === 'outras') {
            $todos_conhecidos = array_merge(...array_values($mapa));
            $placeholders = implode(',', array_fill(0, count($todos_conhecidos), '?'));
            $filtro_categoria_sql = " AND tipo_evento NOT IN ($placeholders)";
            $params_categoria = $todos_conhecidos;
        } elseif (isset($mapa[$categoria])) {
            $placeholders = implode(',', array_fill(0, count($mapa[$categoria]), '?'));
            $filtro_categoria_sql = " AND tipo_evento IN ($placeholders)";
            $params_categoria = $mapa[$categoria];
        }
    }

    if ($marcar_todas) {
        $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE ($condicao)$filtro_categoria_sql AND lida = 0");
        $stmt->execute(array_merge($params_condicao, $params_categoria));
    } elseif ($id > 0) {
        $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND ($condicao)");
        $stmt->execute(array_merge([$id], $params_condicao));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Informe id ou todas.']);
        exit;
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}