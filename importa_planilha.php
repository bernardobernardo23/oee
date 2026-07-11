<?php
session_start();
require 'conexao.php';

// É OBRIGATÓRIO ter rodado "composer require phpoffice/phpspreadsheet" antes
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Segurança de acesso
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_excel'])) {
    $file = $_FILES['arquivo_excel'];

    // Valida extensão
    $extensao = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($extensao) !== 'xlsx') {
        $_SESSION['flash_mensagem'] = "Por favor, envie apenas planilhas no formato .XLSX (Excel).";
        $_SESSION['flash_tipo'] = 'erro';
        header("Location: programacao_pcp.php");
        exit;
    }

    try {
        // Carrega o arquivo Excel
        $spreadsheet = IOFactory::load($file['tmp_name']);

        // Extrai todas as abas presentes no arquivo
        $sheetNames = $spreadsheet->getSheetNames();

        $pdo->beginTransaction();

        $ops_criadas_no_lote = [];  // Cache local: op_sistema (do arquivo) => op_id, evita reconsultar o banco a cada linha
        $proxima_ordem_por_linha = [];
        $ops_ja_existentes   = [];  // op_sistema que já existiam no banco ANTES desta importação (duplicatas)
        $criador_id = $_SESSION['usuario_id'];
        $ops_inseridas = 0;

        // Preparação das Queries de inserção
        $stmt_busca_prod  = $pdo->prepare("SELECT id FROM produtos WHERE codigo = ? LIMIT 1");
        $stmt_busca_linha = $pdo->prepare("SELECT id FROM linhas WHERE login = ? LIMIT 1");
        $stmt_insere_op   = $pdo->prepare("INSERT INTO ordens_producao (op_sistema, linha_id, criador_id, data_planejada, status, observacao_almoxarifado, ordem_fila) VALUES (?, ?, ?, ?, 'PROGRAMADO', ?,?)");
        $stmt_insere_pa   = $pdo->prepare("INSERT INTO op_produtos (op_id, produto_id, quantidade_planejada) VALUES (?, ?, ?)");

        // Percorre aba por aba do arquivo
        foreach ($sheetNames as $nomeAba) {

            // O nome da aba TEM que bater com o login da linha (ex: "l1f1")
            $stmt_busca_linha->execute([strtolower(trim($nomeAba))]);
            $linha_id = $stmt_busca_linha->fetchColumn();

            // Se a aba for genérica (ex: "Instruções", "Planilha1"), o sistema ignora e passa para a próxima
            if (!$linha_id) continue;

            // Calcula a próxima posição livre na fila desta linha, e vai
            // incrementando conforme insere OPs novas nesta mesma aba.
            if (!isset($proxima_ordem_por_linha[$linha_id])) {
                $stmt_prox = $pdo->prepare("SELECT COALESCE(MAX(ordem_fila), 0) FROM ordens_producao WHERE linha_id = ?");
                $stmt_prox->execute([$linha_id]);
                $proxima_ordem_por_linha[$linha_id] = (int)$stmt_prox->fetchColumn();
            }
            $sheet = $spreadsheet->getSheetByName($nomeAba);
            $linhasExcel = $sheet->toArray();

            // Remove o cabeçalho (Primeira linha)
            array_shift($linhasExcel);

            $numero_linha_arquivo = 1;

            foreach ($linhasExcel as $dados) {
                $numero_linha_arquivo++;

                // Ignora linhas totalmente vazias no meio da aba
                if (empty(array_filter($dados))) continue;

                // Layout Excel: OP(0), Data(1), Cód_Produto(2), Qtd(3), Obs(4)
                $op_sistema  = trim($dados[0] ?? '');
                $data_excel  = trim($dados[1] ?? '');
                $codigo_prod = trim($dados[2] ?? '');
                $quantidade  = (int)($dados[3] ?? 0);
                $observacao  = trim($dados[4] ?? '');

                if (empty($op_sistema) || empty($codigo_prod) || $quantidade <= 0) continue;

                // Converte a data do formato bizarro do Excel para o formato do banco (Y-m-d)
                $data_planejada = date('Y-m-d');
                if (is_numeric($data_excel)) {
                    $data_planejada = Date::excelToDateTimeObject($data_excel)->format('Y-m-d');
                } else {
                    $dt = DateTime::createFromFormat('d/m/Y', $data_excel);
                    if ($dt) $data_planejada = $dt->format('Y-m-d');
                }

                // Valida Produto
                $stmt_busca_prod->execute([$codigo_prod]);
                $produto_id = $stmt_busca_prod->fetchColumn();

                if (!$produto_id) {
                    throw new Exception("Erro na Aba '{$nomeAba}', linha {$numero_linha_arquivo}: Produto '{$codigo_prod}' não cadastrado no sistema.");
                }

                // Sistema de Lote: Evita recriar a mesma OP se ela tiver 2 produtos nas linhas seguintes
                $op_ja_existia = false;

                if (!isset($ops_criadas_no_lote[$op_sistema])) {

                    $chk = $pdo->prepare("SELECT id FROM ordens_producao WHERE op_sistema = ?");
                    $chk->execute([$op_sistema]);
                    $id_existente = $chk->fetchColumn();

                    if ($id_existente) {
                        // OP já existia no banco ANTES desta importação -> registra como duplicata
                        $op_id = $id_existente;
                        $op_ja_existia = true;

                        if (!in_array($op_sistema, $ops_ja_existentes)) {
                            $ops_ja_existentes[] = $op_sistema;
                        }
                    } else {
                        $proxima_ordem_por_linha[$linha_id]++;
                        $stmt_insere_op->execute([$op_sistema, $linha_id, $criador_id, $data_planejada, $observacao, $proxima_ordem_por_linha[$linha_id]]);
                        $op_id = $pdo->lastInsertId();
                        $ops_inseridas++;
                    }

                    // Guarda no cache tanto o id quanto se já existia, pra próximas linhas da mesma OP no arquivo
                    $ops_criadas_no_lote[$op_sistema] = ['id' => $op_id, 'ja_existia' => $op_ja_existia];
                } else {
                    $op_id = $ops_criadas_no_lote[$op_sistema]['id'];
                    $op_ja_existia = $ops_criadas_no_lote[$op_sistema]['ja_existia'];
                }

                // Se a OP já existia antes da importação, NÃO insere produtos de novo nela.
                // Isso evita duplicar linhas em op_produtos a cada vez que a mesma planilha é reenviada.
                if ($op_ja_existia) continue;

                // Insere os produtos daquela OP (somente para OPs novas, criadas nesta importação)
                $stmt_insere_pa->execute([$op_id, $produto_id, $quantidade]);
            }
        }

        $pdo->commit();

        // Monta a mensagem final, avisando também sobre duplicatas ignoradas
        $mensagem = "Planilha de lote processada! {$ops_inseridas} nova(s) OP(s) distribuída(s) entre as linhas de produção.";

        if (!empty($ops_ja_existentes)) {
            $qtd_duplicadas = count($ops_ja_existentes);
            $lista_duplicadas = implode(', ', $ops_ja_existentes);
            $mensagem .= " Atenção: {$qtd_duplicadas} OP(s) já existiam no sistema e foram ignoradas (nenhum produto foi duplicado): {$lista_duplicadas}.";
        }

        $_SESSION['flash_mensagem'] = $mensagem;
        $_SESSION['flash_tipo'] = !empty($ops_ja_existentes) && $ops_inseridas === 0 ? 'erro' : 'sucesso';
        header("Location: programacao_pcp.php");
        exit;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_mensagem'] = $e->getMessage();
        $_SESSION['flash_tipo'] = 'erro';
        header("Location: programacao_pcp.php");
        exit;
    }
}
