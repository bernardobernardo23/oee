<?php
// Evita redundância caso o ficheiro já tenha sido incluído na página principal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'conexao.php';

// Proteção: Se não houver utilizador logado, bloqueia a busca
if (!isset($_SESSION['linha_id']) || !isset($_SESSION['fabrica'])) {
    echo json_encode(['erro' => 'Sessão inválida']);
    exit;
}

$linha_logada_id = (int)$_SESSION['linha_id'];
$fabrica_logada = (int)$_SESSION['fabrica'];

try {
    // Base da consulta SQL com os JOINs necessários para trazer o Produto e a Linha
    $sql_base = "
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.nome_separador, op.observacao_almoxarifado,
               l.login as linha_nome, l.fabrica as linha_fabrica,
               (SELECT SUM(quantidade_planejada) FROM op_produtos WHERE op_id = op.id) as total_planejado,
               (SELECT SUM(quantidade_apontada) FROM op_produtos WHERE op_id = op.id) as total_apontado,
               (SELECT GROUP_CONCAT(CONCAT(p.codigo, ' (', op_prod.quantidade_planejada, ')') SEPARATOR ', ') 
                FROM op_produtos op_prod JOIN produtos p ON op_prod.produto_id = p.id WHERE op_prod.op_id = op.id) as lista_produtos
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
    ";

    // ========================================================================
    // APLICAÇÃO DAS REGRAS DE VISIBILIDADE DE BUSCA POR PERFIL
    // ========================================================================
    
    if ($fabrica_logada === 0) {
        // PERFIL A: ADMIN Geral - Vê absolutamente tudo na fila
        $sql_final = $sql_base . " ORDER BY op.data_planejada DESC, op.id DESC";
        $stmt = $pdo->query($sql_final);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($fabrica_logada === 99) {
        // PERFIL B: PCP MASTER - Vê todas as OPs para acompanhar o status e fazer cancelamentos
        $sql_final = $sql_base . " ORDER BY op.data_planejada DESC, op.id DESC";
        $stmt = $pdo->query($sql_final);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (isset($_SESSION['login']) && strtolower($_SESSION['login']) === 'almoxarifado') {
        // PERFIL C: ALMOXARIFADO - Vê TODAS as ordens com status 'PROGRAMADO' (Rosa)
        $sql_final = $sql_base . " WHERE op.status = 'PROGRAMADO' ORDER BY op.data_planejada ASC, op.id ASC";
        $stmt = $pdo->query($sql_final);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // PERFIL D: OPERADOR DE LINHA - Vê APENAS as OPs da SUA linha que já estão em andamento ou prontas para iniciar
        // Regra estrita: Status 'AGUARDANDO INICIO' (Amarelo) ou 'PRODUÇÃO INICIADA' (Azul) destinados ao ID da máquina dele
        $sql_final = $sql_base . " WHERE op.linha_id = ? AND op.status IN ('AGUARDANDO INICIO', 'PRODUÇÃO INICIADA', 'PAUSADO') ORDER BY op.status DESC, op.id ASC";
        $stmt = $pdo->prepare($sql_final);
        $stmt->execute([$linha_logada_id]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Se este ficheiro for chamado diretamente via URL/AJAX, devolve JSON
    if (basename($_SERVER['PHP_SELF']) === 'get_ordens_fila.php') {
        header('Content-Type: application/json');
        echo json_encode($resultados);
        exit;
    }

} catch (PDOException $e) {
    if (basename($_SERVER['PHP_SELF']) === 'get_ordens_fila.php') {
        header('Content-Type: application/json');
        echo json_encode(['erro' => $e->getMessage()]);
        exit;
    } else {
        die("Erro no motor de busca MES: " . $e->getMessage());
    }
}