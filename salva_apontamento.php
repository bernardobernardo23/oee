<?php
// ATIVA EXIBIÇÃO DE ERROS NO SERVIDOR (Apenas para testes)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'conexao.php';

// 1. TESTE DE SESSÃO E POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("ERRO: O formulário não foi enviado via POST. Tente clicar no botão de salvar novamente.");
}
if (!isset($_SESSION['linha_id'])) {
    die("ERRO: Sua sessão expirou ou você não está logado como uma Linha de Produção.");
}

try {
    // Inicia a transação
    $pdo->beginTransaction();

    // 2. Coleta de Dados Base
    $linha_id = $_SESSION['linha_id'];
    
    // CORREÇÃO: O formulário agora envia o 'op_id' da OP selecionada
    $op_id = $_POST['op_id'] ?? null;
    
    if (!$op_id) {
        throw new Exception("Nenhuma Ordem de Produção (OP) foi selecionada na tela.");
    }

    // Busca o nome da OP (op_sistema) no banco para salvar na tabela de apontamentos
    $stmt_op = $pdo->prepare("SELECT op_sistema FROM ordens_producao WHERE id = ?");
    $stmt_op->execute([$op_id]);
    $ordem_producao = $stmt_op->fetchColumn();

    if (!$ordem_producao) {
        throw new Exception("A OP selecionada é inválida ou não existe mais no sistema.");
    }

    $nome_operador = trim($_POST['nome_operador'] ?? '');
    $equipe_auxiliares = trim($_POST['equipe_auxiliares'] ?? '') ?: null;
    $data_registro = $_POST['data_registro'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fim = $_POST['hora_fim'];

    // 3. Inserir Cabeçalho do Apontamento
    $sql_apontamento = "INSERT INTO apontamentos 
        (linha_id, ordem_producao, nome_operador, equipe_auxiliares, data_registro, hora_inicio, hora_fim) 
        VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql_apontamento);
    $stmt->execute([
        $linha_id, $ordem_producao, $nome_operador, $equipe_auxiliares, 
        $data_registro, $hora_inicio, $hora_fim
    ]);

    $apontamento_id = $pdo->lastInsertId();

    // 4. Salvar Produtos Apontados e DAR BAIXA NO PCP
    if (isset($_POST['produto_id']) && is_array($_POST['produto_id'])) {
        
        $sql_prod = "INSERT INTO apontamento_producao (apontamento_id, produto_id, producao_boas, producao_refugo) VALUES (?, ?, ?, ?)";
        $stmt_prod = $pdo->prepare($sql_prod);

        // Prepara query para atualizar a quantidade produzida na OP do PCP
        $sql_baixa_op = "UPDATE op_produtos SET quantidade_apontada = quantidade_apontada + ? WHERE op_id = ? AND produto_id = ?";
        $stmt_baixa_op = $pdo->prepare($sql_baixa_op);

        foreach ($_POST['produto_id'] as $index => $prod_id) {
            $boas = (int)($_POST['producao_boas'][$index] ?? 0);
            $refugo = (int)($_POST['producao_refugo'][$index] ?? 0);
            
            if (!empty($prod_id)) {
                // Salva o histórico do apontamento
                $stmt_prod->execute([$apontamento_id, $prod_id, $boas, $refugo]);
                
                // Adiciona o volume de peças boas no total da Ordem de Produção
                $stmt_baixa_op->execute([$boas, $op_id, $prod_id]);
            }
        }
    }

    // 5. Salvar Paradas
    if (isset($_POST['parada_motivo']) && is_array($_POST['parada_motivo'])) {
        $sql_parada = "INSERT INTO apontamento_paradas (apontamento_id, motivo_id, minutos_parados) VALUES (?, ?, ?)";
        $stmt_parada = $pdo->prepare($sql_parada);

        foreach ($_POST['parada_motivo'] as $index => $motivo_id) {
            $minutos = (int)($_POST['parada_minutos'][$index] ?? 0);
            if (!empty($motivo_id) && $minutos > 0) {
                $stmt_parada->execute([$apontamento_id, $motivo_id, $minutos]);
            }
        }
    }

    // 6. Salvar Perdas de Insumos (Gerado pela Ficha Técnica)
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        $sql_perda = "INSERT INTO apontamento_perdas (apontamento_id, item_id, quantidade) VALUES (?, ?, ?)";
        $stmt_perda = $pdo->prepare($sql_perda);

        foreach ($_POST['item_id'] as $index => $item_id) {
            $quantidade = (int)($_POST['item_qtd'][$index] ?? 0);
            // Só salva no banco de dados os itens que o operador declarou ter perdido (Qtd > 0)
            if (!empty($item_id) && $quantidade > 0) {
                $stmt_perda->execute([$apontamento_id, $item_id, $quantidade]);
            }
        }
    }

    // ========================================================================
    // 7. ATUALIZAR STATUS DA OP PARA "PRODUÇÃO FINALIZADA" (Cor Verde no PCP)
    // ========================================================================
    $stmt_status_op = $pdo->prepare("UPDATE ordens_producao SET status = 'PRODUÇÃO FINALIZADA' WHERE id = ?");
    $stmt_status_op->execute([$op_id]);

    // ========================================================================
    // 8. CÁLCULO DE OEE
    // ========================================================================
    $inicio = new DateTime($hora_inicio);
    $fim = new DateTime($hora_fim);
    if ($fim < $inicio) {
        $fim->modify('+1 day'); 
    }
    $intervalo = $inicio->diff($fim);
    $tempoTotalTurno = ($intervalo->h * 60) + $intervalo->i;

    $stmt_paradas = $pdo->prepare("
        SELECT m.tipo, SUM(ap.minutos_parados) as total_minutos
        FROM apontamento_paradas ap
        JOIN motivos_parada m ON ap.motivo_id = m.id
        WHERE ap.apontamento_id = ?
        GROUP BY m.tipo
    ");
    $stmt_paradas->execute([$apontamento_id]);
    $paradas_agrupadas = $stmt_paradas->fetchAll(PDO::FETCH_KEY_PAIR);

    $minutosPlanejados = $paradas_agrupadas['Planejada'] ?? 0;
    $minutosNaoPlanejados = $paradas_agrupadas['Nao_Planejada'] ?? 0;

    $tempoPlanejadoParaProduzir = $tempoTotalTurno - $minutosPlanejados;
    $tempoRealProduzindo = $tempoPlanejadoParaProduzir - $minutosNaoPlanejados;

    $disponibilidade = 0;
    if ($tempoPlanejadoParaProduzir > 0) {
        $disponibilidade = ($tempoRealProduzindo / $tempoPlanejadoParaProduzir) * 100;
    }

    $stmt_prod_oee = $pdo->prepare("SELECT SUM(producao_boas) as total_boas, SUM(producao_refugo) as total_refugo FROM apontamento_producao WHERE apontamento_id = ?");
    $stmt_prod_oee->execute([$apontamento_id]);
    $res_prod = $stmt_prod_oee->fetch(PDO::FETCH_ASSOC);
    
    $totalBoas = (int)$res_prod['total_boas'];
    $totalRefugo = (int)$res_prod['total_refugo'];
    $producaoTotalReal = $totalBoas + $totalRefugo;

    $stmt_linha = $pdo->prepare("SELECT capacidade_dia FROM linhas WHERE id = ?");
    $stmt_linha->execute([$linha_id]);
    $capacidade_dia = (int)$stmt_linha->fetchColumn();

    $minutosPorDia = 528; 
    $capacidadePorMinuto = $capacidade_dia / $minutosPorDia;
    $producaoEsperada = $tempoRealProduzindo * $capacidadePorMinuto;

    $performance = 0;
    if ($producaoEsperada > 0) {
        $performance = ($producaoTotalReal / $producaoEsperada) * 100;
        if ($performance > 100) $performance = 100; 
    }

    $qualidade = 0;
    if ($producaoTotalReal > 0) {
        $qualidade = ($totalBoas / $producaoTotalReal) * 100;
    }

    $oee_geral = ($disponibilidade / 100) * ($performance / 100) * ($qualidade / 100) * 100;

    $stmt_update_oee = $pdo->prepare("
        UPDATE apontamentos 
        SET oee_disponibilidade = ?, oee_performance = ?, oee_qualidade = ?, oee_geral = ? 
        WHERE id = ?
    ");
    $stmt_update_oee->execute([
        round($disponibilidade, 2), 
        round($performance, 2), 
        round($qualidade, 2), 
        round($oee_geral, 2), 
        $apontamento_id
    ]);

    // Efetiva as alterações no banco
    $pdo->commit();
    
    // Redireciona de volta para a tela clean com o box verde de sucesso ativado
    header("Location: apontamento.php?sucesso=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    // Redireciona de volta para a tela clean com a caixa vermelha de erro
    $erro_msg = urlencode($e->getMessage());
    header("Location: apontamento.php?erro=" . $erro_msg);
    exit;
}
?>