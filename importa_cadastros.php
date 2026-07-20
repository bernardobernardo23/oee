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
        if (in_array('codigo', $primeira) || in_array('tipo', $primeira)) {
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

        foreach ($linhas as $linha) {
            // Layout esperado: Codigo(A) | Descricao(B) | Tipo(C)
            $codigo = trim((string)($linha[0] ?? ''));
            $descricao = trim((string)($linha[1] ?? ''));
            $tipo_planilha = strtoupper(trim((string)($linha[2] ?? '')));

            if ($codigo === '' || $descricao === '') {
                $linhas_invalidas++;
                continue;
            }

            if ($tipo_planilha === 'PA') {
                if (isset($produtos_existentes[$codigo])) {
                    $produtos_ignorados_duplicados++;
                    continue;
                }
                $stmt_insere_produto->execute([$codigo, $descricao]);
                $produtos_existentes[$codigo] = true; // evita duplicar se o código repetir dentro da própria planilha
                $produtos_inseridos++;
            } elseif ($tipo_planilha === 'EM') {
                if (isset($componentes_existentes[$codigo])) {
                    $componentes_ignorados_duplicados++;
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

        $_SESSION['flash_mensagem'] = $mensagem;
        $_SESSION['flash_tipo'] = 'sucesso';
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