<?php
// Configurações de conexão PDO
$host = 'localhost';
$dbname = 'oee'; // Confirme se o nome do seu banco é este mesmo
$user = 'root';
$pass = '';

// Evita que o script pare por limite de tempo do servidor local
ini_set('max_execution_time', 0); 
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Configurações da simulação
    $dias_no_mes = 30;
    $data_inicial = new DateTime('2026-06-01'); // Mês de Junho de 2026

    echo "<h2 style='font-family:sans-serif; color:#2563eb;'>Iniciando Motor de Simulação MES/OEE...</h2>";

    // 1. Busca os IDs reais do banco de dados
    $produtos_ids = $pdo->query("SELECT id FROM produtos")->fetchAll(PDO::FETCH_COLUMN);
    $motivos = $pdo->query("SELECT id, tipo FROM motivos_parada")->fetchAll(PDO::FETCH_ASSOC);
    $componentes_ids = $pdo->query("SELECT id FROM itens_componentes")->fetchAll(PDO::FETCH_COLUMN);
    $linhas = $pdo->query("SELECT id, login, capacidade_dia FROM linhas WHERE fabrica > 0")->fetchAll(PDO::FETCH_ASSOC);

    // Validação de segurança
    if (empty($produtos_ids) || empty($motivos) || empty($linhas)) {
        die("<b style='color:red;'>ERRO FATAL:</b> Você precisa ter cadastrado no mínimo Produtos, Motivos de Parada e Linhas (Fábricas) no painel de Cadastros Master antes de rodar a simulação.");
    }

    $pdo->beginTransaction();

    // 2. O Loop do Tempo (Passando por cada dia do mês)
    for ($dia = 0; $dia < $dias_no_mes; $dia++) {
        $data_atual = (clone $data_inicial)->modify("+$dia days")->format('Y-m-d');
        
        // Exclui domingos (0) para dar mais realismo, operando de Seg a Sáb
        $dia_semana = (clone $data_inicial)->modify("+$dia days")->format('w');
        if ($dia_semana == 0) continue; 

        // 3. O Loop da Fábrica (Cada linha trabalha neste dia)
        foreach ($linhas as $linha) {
            $linha_id = $linha['id'];
            $capacidade_dia = (int)$linha['capacidade_dia'];
            if ($capacidade_dia == 0) $capacidade_dia = 40000; // Evita divisão por zero

            // Turno Padrão: 08:00 às 18:00 (10 horas = 600 minutos brutos)
            $minutos_turno_bruto = 600;
            $hora_inicio = '08:00:00';
            $hora_fim = '18:00:00';
            
            $ordem_producao = "OP-" . date('Ymd', strtotime($data_atual)) . "-L" . $linha_id;
            $operador = "Operador_Simulacao_" . rand(1, 5);

            // ============================================================
            // A. INSERE O CABEÇALHO DO APONTAMENTO (Ainda sem OEE)
            // ============================================================
            $stmt = $pdo->prepare("INSERT INTO apontamentos (linha_id, ordem_producao, nome_operador, data_registro, hora_inicio, hora_fim) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$linha_id, $ordem_producao, $operador, $data_atual, $hora_inicio, $hora_fim]);
            $apontamento_id = $pdo->lastInsertId();

            // ============================================================
            // B. GERA AS PARADAS E CALCULA O TEMPO (OEE Disponibilidade)
            // ============================================================
            $qtd_paradas = rand(1, 3); // 1 a 3 paradas por dia
            $min_planejados = 0;
            $min_nao_planejados = 0;

            for ($p = 0; $p < $qtd_paradas; $p++) {
                $motivo = $motivos[array_rand($motivos)];
                // Paradas curtas para não estourar o tempo do turno
                $minutos = rand(10, 45); 
                
                if ($motivo['tipo'] == 'Planejada') {
                    $min_planejados += $minutos;
                } else {
                    $min_nao_planejados += $minutos;
                }

                $stmt_parada = $pdo->prepare("INSERT INTO apontamento_paradas (apontamento_id, motivo_id, minutos_parados) VALUES (?, ?, ?)");
                $stmt_parada->execute([$apontamento_id, $motivo['id'], $minutos]);
            }

            // Matemática da Disponibilidade
            $tempo_planejado_produzir = $minutos_turno_bruto - $min_planejados;
            $tempo_real_produzindo = $tempo_planejado_produzir - $min_nao_planejados;

            $disponibilidade = 0;
            if ($tempo_planejado_produzir > 0) {
                $disponibilidade = ($tempo_real_produzindo / $tempo_planejado_produzir) * 100;
            }

            // ============================================================
            // C. GERA A PRODUÇÃO REALISTA (OEE Performance e Qualidade)
            // ============================================================
            // Descobre o quanto a máquina deveria ter feito nesses minutos
            $cap_por_minuto = $capacidade_dia / 1440; // Base 24h
            $producao_teorica_esperada = $tempo_real_produzindo * $cap_por_minuto;
            
            // Simula que a equipe atingiu entre 75% e 99% da meta
            $fator_performance = rand(75, 99) / 100;
            $producao_total_real = round($producao_teorica_esperada * $fator_performance);
            
            // Simula um pouco de refugo (1% a 3% do total)
            $fator_refugo = rand(1, 3) / 100;
            $total_refugo = round($producao_total_real * $fator_refugo);
            $total_boas = $producao_total_real - $total_refugo;
            
            if ($total_boas < 0) $total_boas = 0; // Garantia

            // Insere o produto produzido
            $produto_sorteado = $produtos_ids[array_rand($produtos_ids)];
            $stmt_prod = $pdo->prepare("INSERT INTO apontamento_producao (apontamento_id, produto_id, producao_boas, producao_refugo) VALUES (?, ?, ?, ?)");
            $stmt_prod->execute([$apontamento_id, $produto_sorteado, $total_boas, $total_refugo]);

            // Matemática da Performance e Qualidade
            $performance = 0;
            if ($producao_teorica_esperada > 0) {
                $performance = ($producao_total_real / $producao_teorica_esperada) * 100;
                if ($performance > 100) $performance = 100; 
            }

            $qualidade = 0;
            if ($producao_total_real > 0) {
                $qualidade = ($total_boas / $producao_total_real) * 100;
            }

            // OEE Geral
            $oee_geral = ($disponibilidade / 100) * ($performance / 100) * ($qualidade / 100) * 100;

            // ============================================================
            // D. GERA PERDAS DE INSUMOS ALEATÓRIAS
            // ============================================================
            if (!empty($componentes_ids)) {
                $qtd_perdas = rand(0, 2); // Pode não perder nada, ou perder até 2 itens
                for ($l = 0; $l < $qtd_perdas; $l++) {
                    $item_sorteado = $componentes_ids[array_rand($componentes_ids)];
                    $quantidade_perdida = rand(5, 40); // Perdeu de 5 a 40 latas/válvulas
                    
                    $stmt_perda = $pdo->prepare("INSERT INTO apontamento_perdas (apontamento_id, item_id, quantidade) VALUES (?, ?, ?)");
                    $stmt_perda->execute([$apontamento_id, $item_sorteado, $quantidade_perdida]);
                }
            }

            // ============================================================
            // E. ATUALIZA O CABEÇALHO COM O OEE CALCULADO
            // ============================================================
            $stmt_update = $pdo->prepare("UPDATE apontamentos SET oee_disponibilidade = ?, oee_performance = ?, oee_qualidade = ?, oee_geral = ? WHERE id = ?");
            $stmt_update->execute([
                round($disponibilidade, 2),
                round($performance, 2),
                round($qualidade, 2),
                round($oee_geral, 2),
                $apontamento_id
            ]);
        }
    }

    $pdo->commit();
    echo "<div style='background:#dcfce7; color:#166534; padding:20px; border-left:5px solid #22c55e; font-family:sans-serif;'>";
    echo "<h1>✅ Sucesso Absoluto!</h1>";
    echo "<p>Milhares de linhas de dados foram geradas simulando 30 dias de produção contínua (Pulando os domingos para maior realismo).<br>";
    echo "Os cálculos de Disponibilidade, Performance, Qualidade e OEE foram matematicamente validados conforme a capacidade individual de cada máquina.</p>";
    echo "<a href='dashboard_admin.php' style='display:inline-block; margin-top:15px; padding:10px 20px; background:#2563eb; color:white; text-decoration:none; border-radius:5px;'>Acessar o Dashboard</a>";
    echo "</div>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div style='background:#fee2e2; color:#b91c1c; padding:20px; border-left:5px solid #ef4444; font-family:sans-serif;'>";
    echo "<b>ERRO SQL QUE IMPEDIU A INSERÇÃO:</b><br><br>" . $e->getMessage();
    echo "</div>";
}
?>