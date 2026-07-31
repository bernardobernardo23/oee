<?php
session_start();
require 'conexao.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Segurança: só ADMIN importa cadastros em massa
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || $_SESSION['setor'] !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

// Infere o subtipo de itens_componentes (Lata/Tampa/Valvula/Atuador/
// Bolinha/Caixa/Granel/Outros) a partir de palavras-chave na descrição.
// A planilha só diz "EM" (Embalagem) -- não diz qual subtipo é.
function inferir_subtipo_componente(string $descricao): string
{
    $d = strtoupper($descricao);
    if (str_contains($d, 'LATA')) return 'Lata';
    if (str_contains($d, 'TAMPA')) return 'Tampa';
    if (str_contains($d, 'VALVULA') || str_contains($d, 'VÁLVULA')) return 'Valvula';
    if (str_contains($d, 'ATUADOR')) return 'Atuador';
    if (str_contains($d, 'BOLINHA')) return 'Bolinha';
    if (str_contains($d, 'CAIXA')) return 'Caixa';
    if (str_contains($d, 'GRANEL')) return 'Granel';
    return 'Outros';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_excel'])) {
    $file = $_FILES['arquivo_excel'];

    $extensao = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($extensao) !== 'xlsx') {
        $_SESSION['flash_mensagem'] = "Por favor, envie apenas planilhas no formato .XLSX (Excel).";
        $_SESSION['flash_tipo'] = 'erro';
        header("Location: cadastros.php");
        exit;
    }

    try {
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $linhas = $sheet->toArray();

        // Detecta se a primeira linha é cabeçalho (contém "codigo" ou
        // "tipo" em qualquer capitalização) e pula ela nesse caso.
        $primeira = array_map(fn($v) => strtolower(trim((string)$v)), $linhas[0] ?? []);
        $cabecalho_removido = in_array('codigo', $primeira) || in_array('tipo', $primeira);
        if ($cabecalho_removido) {
            array_shift($linhas);
        }

        $pdo->beginTransaction();

        // Cache dos códigos já existentes -- consultamos uma vez só em
        // vez de rodar um SELECT por linha da planilha (que pode ter
        // milhares de linhas).
        $produtos_existentes = array_flip($pdo->query("SELECT codigo FROM produtos")->fetchAll(PDO::FETCH_COLUMN));
        $componentes_existentes = array_flip($pdo->query("SELECT codigo FROM itens_componentes")->fetchAll(PDO::FETCH_COLUMN));

        $stmt_insere_produto = $pdo->prepare("INSERT INTO produtos (codigo, descricao) VALUES (?, ?)");
        $stmt_insere_componente = $pdo->prepare("INSERT INTO itens_componentes (codigo, descricao, tipo) VALUES (?, ?, ?)");

        $produtos_inseridos = 0;
        $produtos_ignorados_duplicados = 0;
        $componentes_inseridos = 0;
        $componentes_ignorados_duplicados = 0;
        $linhas_tipo_nao_tratado = 0;
        $linhas_invalidas = 0;

        // Log detalhado -- uma entrada por linha ignorada/inválida, com
        // o motivo exato. É o que permite responder "por que meu produto
        // X não entrou?" sem precisar adivinhar a partir de um número
        // agregado. $numero_linha acompanha a posição REAL na planilha
        // (conta o cabeçalho removido, se houve, pra bater com o Excel).
        $log_itens = [];
        $numero_linha = $cabecalho_removido ? 1 : 0;

        foreach ($linhas as $linha) {
            $numero_linha++;

            // Layout esperado: Codigo(A) | Descricao(B) | Tipo(C)
            $codigo = trim((string)($linha[0] ?? ''));
            $descricao = trim((string)($linha[1] ?? ''));
            $tipo_planilha = strtoupper(trim((string)($linha[2] ?? '')));

            if ($codigo === '' || $descricao === '') {
                $linhas_invalidas++;
                $motivo_invalida = $codigo === '' ? 'Linha sem código' : 'Linha sem descrição';
                $log_itens[] = [$numero_linha, $codigo ?: null, $descricao ?: null, $tipo_planilha ?: null, 'LINHA_INVALIDA', $motivo_invalida];
                continue;
            }

            if ($tipo_planilha === 'PA') {
                if (isset($produtos_existentes[$codigo])) {
                    $produtos_ignorados_duplicados++;
                    $log_itens[] = [$numero_linha, $codigo, $descricao, $tipo_planilha, 'DUPLICADO_PRODUTO', "Código {$codigo} já existe em Produtos -- não foi sobrescrito"];
                    continue;
                }
                $stmt_insere_produto->execute([$codigo, $descricao]);
                $produtos_existentes[$codigo] = true; // evita duplicar se o código repetir dentro da própria planilha
                $produtos_inseridos++;
            } elseif ($tipo_planilha === 'EM') {
                if (isset($componentes_existentes[$codigo])) {
                    $componentes_ignorados_duplicados++;
                    $log_itens[] = [$numero_linha, $codigo, $descricao, $tipo_planilha, 'DUPLICADO_COMPONENTE', "Código {$codigo} já existe em Insumos -- não foi sobrescrito"];
                    continue;
                }
                $subtipo = inferir_subtipo_componente($descricao);
                $stmt_insere_componente->execute([$codigo, $descricao, $subtipo]);
                $componentes_existentes[$codigo] = true;
                $componentes_inseridos++;
            } else {
                // Qualquer outro Tipo (MP, PI, OI, MC, AI, SV, ME, GN, GG, KT...)
                // é ignorado -- o sistema hoje só sabe tratar PA e EM.
                $linhas_tipo_nao_tratado++;
                $tipo_exibido = $tipo_planilha !== '' ? $tipo_planilha : '(vazio)';
                $log_itens[] = [$numero_linha, $codigo, $descricao, $tipo_planilha, 'TIPO_NAO_TRATADO', "Tipo '{$tipo_exibido}' não é reconhecido pelo sistema (só PA e EM)"];
            }
        }

        // Grava o log permanente da importação -- resumo + cada item
        // ignorado com motivo. $log_importacao_id fica na sessão pra
        // cadastros.php oferecer o link de download logo em seguida.
        $stmt_log_cabecalho = $pdo->prepare("
            INSERT INTO importacoes_log (usuario_id, nome_arquivo, total_produtos_inseridos, total_componentes_inseridos, total_ignorados)
            VALUES (?, ?, ?, ?, ?)
        ");
        $total_ignorados_geral = $produtos_ignorados_duplicados + $componentes_ignorados_duplicados + $linhas_tipo_nao_tratado + $linhas_invalidas;
        $stmt_log_cabecalho->execute([
            $_SESSION['usuario_id'],
            $file['name'],
            $produtos_inseridos,
            $componentes_inseridos,
            $total_ignorados_geral,
        ]);
        $log_importacao_id = $pdo->lastInsertId();

        if (!empty($log_itens)) {
            $stmt_log_item = $pdo->prepare("
                INSERT INTO importacoes_log_itens (importacao_id, linha_planilha, codigo, descricao, tipo_planilha, categoria, motivo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($log_itens as $item) {
                $stmt_log_item->execute([$log_importacao_id, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
            }
        }

        $pdo->commit();

        $partes = [];
        $partes[] = "{$produtos_inseridos} produto(s) acabado(s) novo(s)";
        $partes[] = "{$componentes_inseridos} insumo(s)/componente(s) novo(s)";
        $mensagem = "Importação concluída! " . implode(', ', $partes) . ".";

        $detalhes = [];
        if ($produtos_ignorados_duplicados > 0) $detalhes[] = "{$produtos_ignorados_duplicados} produto(s) já existiam (ignorados)";
        if ($componentes_ignorados_duplicados > 0) $detalhes[] = "{$componentes_ignorados_duplicados} insumo(s) já existiam (ignorados)";
        if ($linhas_tipo_nao_tratado > 0) $detalhes[] = "{$linhas_tipo_nao_tratado} linha(s) com Tipo não tratado pelo sistema (ignoradas)";
        if ($linhas_invalidas > 0) $detalhes[] = "{$linhas_invalidas} linha(s) sem código ou descrição (ignoradas)";

        if (!empty($detalhes)) {
            $mensagem .= " Também foram ignoradas: " . implode(', ', $detalhes) . ".";
        }
        if ($total_ignorados_geral > 0) {
            $mensagem .= " Baixe o log detalhado pra ver linha por linha o que foi ignorado e o motivo.";
        }

        $_SESSION['flash_mensagem'] = $mensagem;
        $_SESSION['flash_tipo'] = 'sucesso';
        $_SESSION['flash_log_importacao_id'] = $log_importacao_id;
        header("Location: cadastros.php");
        exit;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_mensagem'] = "Erro ao processar a planilha: " . $e->getMessage();
        $_SESSION['flash_tipo'] = 'erro';
        header("Location: cadastros.php");
        exit;
    }
}

header("Location: cadastros.php");
exit;