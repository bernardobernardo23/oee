<?php
session_start();
require 'conexao.php';

if (!isset($_SESSION['linha_id'])) {
    die("Acesso negado.");
}

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
$linha_id = $_SESSION['linha_id'];

try {
    $pdo->beginTransaction();

    // =======================================================
    // AÇÃO 1: INICIAR PRODUÇÃO (PLAY)
    // =======================================================
    if ($acao === 'iniciar') {
        $op_id = $_POST['op_id'];
        $nome_operador = trim($_POST['nome_operador']);
        $equipe_auxiliares = trim($_POST['equipe_auxiliares']) ?: null;
        $data_hoje = date('Y-m-d');
        $agora = date('Y-m-d H:i:s');

        // Busca nome da OP
        $stmt_op = $pdo->prepare("SELECT op_sistema FROM ordens_producao WHERE id = ?");
        $stmt_op->execute([$op_id]);
        $op_sistema = $stmt_op->fetchColumn();

        // Insere Apontamento Aberto
        $stmt = $pdo->prepare("INSERT INTO apontamentos (linha_id, ordem_producao, nome_operador, equipe_auxiliares, data_registro, hora_inicio) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$linha_id, $op_sistema, $nome_operador, $equipe_auxiliares, $data_hoje, $agora]);

        // Atualiza status da OP
        $pdo->prepare("UPDATE ordens_producao SET status = 'PRODUÇÃO INICIADA' WHERE id = ?")->execute([$op_id]);
        
        $msg = "Produção iniciada com sucesso!";
    }

    // =======================================================
    // AÇÃO 2: PAUSAR MÁQUINA (PAUSE)
    // =======================================================
    elseif ($acao === 'pausar') {
        $apontamento_id = $_POST['apontamento_id'];
        $op_id = $_POST['op_id'];
        $motivo_id = $_POST['motivo_id'];
        $agora = date('Y-m-d H:i:s');

        // Registra o início da parada
        $pdo->prepare("UPDATE apontamentos SET parada_inicio = ?, motivo_parada_ativa_id = ? WHERE id = ?")
            ->execute([$agora, $motivo_id, $apontamento_id]);

        // Atualiza status da OP
        $pdo->prepare("UPDATE ordens_producao SET status = 'PAUSADO' WHERE id = ?")->execute([$op_id]);
        
        $msg = "Máquina pausada.";
    }

    // =======================================================
    // AÇÃO 3: RETOMAR PRODUÇÃO (RESUME)
    // =======================================================
    elseif ($acao === 'retomar') {
        $apontamento_id = $_POST['apontamento_id'];
        $op_id = $_POST['op_id'];
        
        // Pega os dados da parada atual
        $stmt = $pdo->prepare("SELECT parada_inicio, motivo_parada_ativa_id FROM apontamentos WHERE id = ?");
        $stmt->execute([$apontamento_id]);
        $parada = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($parada['parada_inicio']) {
            $inicio_parada = new DateTime($parada['parada_inicio']);
            $agora = new DateTime();
            $minutos_parados = $inicio_parada->diff($agora)->i + ($inicio_parada->diff($agora)->h * 60) + ($inicio_parada->diff($agora)->days * 24 * 60);

            // Se durou menos de 1 minuto, arredondamos para 1 para não perder o registro
            if ($minutos_parados == 0) $minutos_parados = 1;

            // Salva na tabela de histórico de paradas
            $pdo->prepare("INSERT INTO apontamento_paradas (apontamento_id, motivo_id, minutos_parados) VALUES (?, ?, ?)")
                ->execute([$apontamento_id, $parada['motivo_parada_ativa_id'], $minutos_parados]);

            // Limpa a parada ativa do apontamento
            $pdo->prepare("UPDATE apontamentos SET parada_inicio = NULL, motivo_parada_ativa_id = NULL WHERE id = ?")->execute([$apontamento_id]);
        }

        // Volta a OP para produzindo
        $pdo->prepare("UPDATE ordens_producao SET status = 'PRODUÇÃO INICIADA' WHERE id = ?")->execute([$op_id]);
        
        $msg = "Produção retomada!";
    }

    // =======================================================
    // AÇÃO 4: FINALIZAR TURNO E CALCULAR OEE (STOP)
    // =======================================================
    elseif ($acao === 'finalizar') {
        $apontamento_id = $_POST['apontamento_id'];
        $op_id = $_POST['op_id'];
        $agora_str = date('Y-m-d H:i:s');
        $agora_dt = new DateTime($agora_str);

        // 1. Fecha o apontamento
        $pdo->prepare("UPDATE apontamentos SET hora_fim = ? WHERE id = ?")->execute([$agora_str, $apontamento_id]);

        // 2. Salva Produtos e dá baixa no PCP
        if (isset($_POST['produto_id']) && is_array($_POST['produto_id'])) {
            $stmt_prod = $pdo->prepare("INSERT INTO apontamento_producao (apontamento_id, produto_id, producao_boas, producao_refugo) VALUES (?, ?, ?, ?)");
            $stmt_baixa_op = $pdo->prepare("UPDATE op_produtos SET quantidade_apontada = quantidade_apontada + ? WHERE op_id = ? AND produto_id = ?");
            foreach ($_POST['produto_id'] as $index => $prod_id) {
                $boas = (int)($_POST['producao_boas'][$index] ?? 0);
                $refugo = (int)($_POST['producao_refugo'][$index] ?? 0);
                if (!empty($prod_id)) {
                    $stmt_prod->execute([$apontamento_id, $prod_id, $boas, $refugo]);
                    $stmt_baixa_op->execute([$boas, $op_id, $prod_id]);
                }
            }
        }

        // 3. Salva Perdas de Insumos Extras
        if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
            $stmt_perda = $pdo->prepare("INSERT INTO apontamento_perdas (apontamento_id, item_id, quantidade) VALUES (?, ?, ?)");
            foreach ($_POST['item_id'] as $index => $item_id) {
                $quantidade = (int)($_POST['item_qtd'][$index] ?? 0);
                if (!empty($item_id) && $quantidade > 0) {
                    $stmt_perda->execute([$apontamento_id, $item_id, $quantidade]);
                }
            }
        }

        // 4. ATUALIZA OP PARA VERDE (FINALIZADA)
        $pdo->prepare("UPDATE ordens_producao SET status = 'PRODUÇÃO FINALIZADA' WHERE id = ?")->execute([$op_id]);

        // ================== CÁLCULO DE OEE ==================
        $stmt_ap = $pdo->prepare("SELECT hora_inicio FROM apontamentos WHERE id = ?");
        $stmt_ap->execute([$apontamento_id]);
        $inicio_dt = new DateTime($stmt_ap->fetchColumn());
        
        $intervalo = $inicio_dt->diff($agora_dt);
        $tempoTotalTurno = ($intervalo->days * 24 * 60) + ($intervalo->h * 60) + $intervalo->i;

        $stmt_paradas = $pdo->prepare("SELECT m.tipo, SUM(ap.minutos_parados) as total_minutos FROM apontamento_paradas ap JOIN motivos_parada m ON ap.motivo_id = m.id WHERE ap.apontamento_id = ? GROUP BY m.tipo");
        $stmt_paradas->execute([$apontamento_id]);
        $paradas_agrupadas = $stmt_paradas->fetchAll(PDO::FETCH_KEY_PAIR);

        $minutosPlanejados = $paradas_agrupadas['Planejada'] ?? 0;
        $minutosNaoPlanejados = $paradas_agrupadas['Nao_Planejada'] ?? 0;

        $tempoPlanejadoParaProduzir = max(0, $tempoTotalTurno - $minutosPlanejados);
        $tempoRealProduzindo = max(0, $tempoPlanejadoParaProduzir - $minutosNaoPlanejados);

        $disponibilidade = $tempoPlanejadoParaProduzir > 0 ? ($tempoRealProduzindo / $tempoPlanejadoParaProduzir) * 100 : 0;

        $stmt_prod_oee = $pdo->prepare("SELECT SUM(producao_boas) as total_boas, SUM(producao_refugo) as total_refugo FROM apontamento_producao WHERE apontamento_id = ?");
        $stmt_prod_oee->execute([$apontamento_id]);
        $res_prod = $stmt_prod_oee->fetch(PDO::FETCH_ASSOC);
        
        $totalBoas = (int)$res_prod['total_boas'];
        $producaoTotalReal = $totalBoas + (int)$res_prod['total_refugo'];

        $stmt_linha = $pdo->prepare("SELECT capacidade_dia FROM linhas WHERE id = ?");
        $stmt_linha->execute([$linha_id]);
        $capacidadePorMinuto = ((int)$stmt_linha->fetchColumn()) / 528;
        $producaoEsperada = $tempoRealProduzindo * $capacidadePorMinuto;

        $performance = $producaoEsperada > 0 ? min(100, ($producaoTotalReal / $producaoEsperada) * 100) : 0;
        $qualidade = $producaoTotalReal > 0 ? ($totalBoas / $producaoTotalReal) * 100 : 0;
        $oee_geral = ($disponibilidade / 100) * ($performance / 100) * ($qualidade / 100) * 100;

        $pdo->prepare("UPDATE apontamentos SET oee_disponibilidade=?, oee_performance=?, oee_qualidade=?, oee_geral=? WHERE id=?")
            ->execute([round($disponibilidade,2), round($performance,2), round($qualidade,2), round($oee_geral,2), $apontamento_id]);

        $msg = "Turno Finalizado! OEE processado com sucesso.";
    }

    $pdo->commit();
    header("Location: apontamento.php?sucesso=" . urlencode($msg));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header("Location: apontamento.php?erro=" . urlencode($e->getMessage()));
    exit;
}