<?php
session_start();
require 'conexao.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Segurança: só usuário corporativo do setor PCP ou ADMIN
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

function normalizaStatusExport($str) {
    $str = strtoupper(trim($str));
    return str_replace(['Ç', 'Ã', 'Á', 'À', 'É', 'Í', 'Ó', 'Ú', 'Â', 'Ê'], ['C', 'A', 'A', 'A', 'E', 'I', 'O', 'U', 'A', 'E'], $str);
}

function situacaoEspecialTexto(array $op): string {
    $partes = [];
    $st_norm = normalizaStatusExport($op['status']);
    $ja_terminou = in_array($st_norm, ['PRODUCAO FINALIZADA', 'CANCELADO'], true);
    if (!$ja_terminou && (int)($op['qtd_produtos_parcial'] ?? 0) > 0) $partes[] = 'Retomada';
    if (!empty($op['motivo_interrupcao_recente'])) $partes[] = 'Interrompida';
    return empty($partes) ? '-' : implode(' + ', $partes);
}

$status_meta = [
    'PROGRAMADO'              => 'Programado',
    'AGUARDANDO FORMULACAO'   => 'Aguard. Formulação',
    'AGUARDANDO ALMOXARIFADO' => 'Aguard. Almoxarifado',
    'AGUARDANDO INICIO'       => 'Aguardando Início',
    'PRODUCAO INICIADA'       => 'Em Produção',
    'PRODUCAO FINALIZADA'     => 'Finalizado',
    'PAUSADO'                 => 'Pausado',
    'CANCELADO'               => 'Cancelado',
    'PENDENCIA'               => 'Pendência Material',
];

// --------------------------------------------------------------
// Reconstrói os MESMOS filtros aplicados no navegador (Visão Global):
// texto de OP, texto de produto, e a lista de status marcados.
// --------------------------------------------------------------
$formato = $_GET['formato'] ?? 'xlsx';
$termo_op = trim($_GET['op'] ?? '');
$termo_produto = trim($_GET['produto'] ?? '');
$status_filtrados = $_GET['status'] ?? [];
$status_filtrados = is_array($status_filtrados) ? array_intersect($status_filtrados, array_keys($status_meta)) : [];
$fabrica_filtro = trim($_GET['fabrica'] ?? '');
$linha_id_filtro = trim($_GET['linha_id'] ?? '');
$data_de_filtro = trim($_GET['data_de'] ?? '');
$data_ate_filtro = trim($_GET['data_ate'] ?? '');
$situacao_filtrada = $_GET['situacao'] ?? [];
$situacao_filtrada = is_array($situacao_filtrada) ? array_intersect($situacao_filtrada, ['retomada', 'interrompida']) : [];

try {
    $sql = "
        SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.linha_id,
               l.login AS linha_nome, l.fabrica,
               (SELECT GROUP_CONCAT(CONCAT(p.codigo, ' - ', p.descricao, ' (', op_p.quantidade_planejada, ' un)') SEPARATOR '; ')
                    FROM op_produtos op_p JOIN produtos p ON op_p.produto_id = p.id WHERE op_p.op_id = op.id) AS produtos_resumo,
               (SELECT GROUP_CONCAT(CONCAT(p.codigo, ' ', p.descricao) SEPARATOR ' | ')
                    FROM op_produtos op_p JOIN produtos p ON op_p.produto_id = p.id WHERE op_p.op_id = op.id) AS busca_produtos,
               (SELECT COUNT(*) FROM op_produtos op_p WHERE op_p.op_id = op.id AND op_p.quantidade_apontada > 0 AND op_p.quantidade_apontada < op_p.quantidade_planejada) AS qtd_produtos_parcial,
               interr.motivo_interrupcao AS motivo_interrupcao_recente
        FROM ordens_producao op
        LEFT JOIN linhas l ON op.linha_id = l.id
        LEFT JOIN (
            SELECT a1.ordem_producao, a1.motivo_interrupcao
            FROM apontamentos a1
            INNER JOIN (
                SELECT ordem_producao, MAX(id) AS max_id FROM apontamentos
                WHERE situacao = 'INTERROMPIDO' AND motivo_interrupcao IS NOT NULL
                GROUP BY ordem_producao
            ) latest_interr ON a1.id = latest_interr.max_id
        ) interr ON interr.ordem_producao = op.op_sistema
        WHERE 1=1
    ";
    $params = [];

    if ($termo_op !== '') {
        $sql .= " AND op.op_sistema LIKE ?";
        $params[] = '%' . $termo_op . '%';
    }
    if ($fabrica_filtro !== '') {
        $sql .= " AND l.fabrica = ?";
        $params[] = $fabrica_filtro;
    }
    if ($linha_id_filtro !== '') {
        $sql .= " AND op.linha_id = ?";
        $params[] = $linha_id_filtro;
    }
    if ($data_de_filtro !== '') {
        $sql .= " AND op.data_planejada >= ?";
        $params[] = $data_de_filtro;
    }
    if ($data_ate_filtro !== '') {
        $sql .= " AND op.data_planejada <= ?";
        $params[] = $data_ate_filtro;
    }

    $stmt = $pdo->prepare($sql . " ORDER BY op.data_planejada DESC, op.id DESC");
    $stmt->execute($params);
    $linhas_ops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtros que dependem de valor calculado (status normalizado, busca
    // por produto, situação especial) são aplicados em PHP -- mais simples
    // que replicar a lógica de normalização inteira em SQL.
    if ($termo_produto !== '' || !empty($status_filtrados) || !empty($situacao_filtrada)) {
        $termo_produto_lower = mb_strtolower($termo_produto);
        $linhas_ops = array_values(array_filter($linhas_ops, function ($op) use ($termo_produto_lower, $status_filtrados, $situacao_filtrada) {
            $st_norm = normalizaStatusExport($op['status']);
            if (!empty($status_filtrados) && !in_array($st_norm, $status_filtrados, true)) return false;
            if ($termo_produto_lower !== '' && mb_strpos(mb_strtolower($op['busca_produtos'] ?? ''), $termo_produto_lower) === false) return false;
            $ja_terminou = in_array($st_norm, ['PRODUCAO FINALIZADA', 'CANCELADO'], true);
            if (in_array('retomada', $situacao_filtrada, true) && ($ja_terminou || (int)$op['qtd_produtos_parcial'] === 0)) return false;
            if (in_array('interrompida', $situacao_filtrada, true) && empty($op['motivo_interrupcao_recente'])) return false;
            return true;
        }));
    }
} catch (PDOException $e) {
    die("Erro ao exportar: " . $e->getMessage());
}

$nome_arquivo = 'programacao_pcp_' . date('Y-m-d_His');

// ================================================================
// EXPORTAÇÃO EM PLANILHA (.xlsx)
// ================================================================
if ($formato === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Programação PCP');

    $cabecalho = ['OP', 'Fábrica', 'Linha', 'Data Planejada', 'Status', 'Situação', 'Produtos'];
    $sheet->fromArray($cabecalho, null, 'A1');

    $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
    $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $linha_atual = 2;
    foreach ($linhas_ops as $op) {
        $st_norm = normalizaStatusExport($op['status']);
        $sheet->fromArray([
            $op['op_sistema'],
            $op['fabrica'] ? 'Fábrica ' . $op['fabrica'] : '-',
            strtoupper($op['linha_nome'] ?? '-'),
            date('d/m/Y', strtotime($op['data_planejada'])),
            $status_meta[$st_norm] ?? $op['status'],
            situacaoEspecialTexto($op),
            $op['produtos_resumo'] ?? '',
        ], null, 'A' . $linha_atual);
        $linha_atual++;
    }

    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet->getColumnDimension('G')->setWidth(60);
    $sheet->getStyle('G2:G' . ($linha_atual - 1))->getAlignment()->setWrapText(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $nome_arquivo . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
// ================================================================
// EXPORTAÇÃO EM PDF
// ================================================================
if ($formato === 'pdf') {
    $filtros_aplicados = [];
    if ($termo_op !== '') $filtros_aplicados[] = "OP contém \"{$termo_op}\"";
    if ($termo_produto !== '') $filtros_aplicados[] = "Produto contém \"{$termo_produto}\"";
    if (!empty($status_filtrados)) $filtros_aplicados[] = "Status: " . implode(', ', array_map(fn($s) => $status_meta[$s] ?? $s, $status_filtrados));
    if ($fabrica_filtro !== '') $filtros_aplicados[] = "Fábrica {$fabrica_filtro}";
    if ($linha_id_filtro !== '') {
        $stmt_nome_linha = $pdo->prepare("SELECT login FROM linhas WHERE id = ?");
        $stmt_nome_linha->execute([$linha_id_filtro]);
        $nome_linha_filtro = $stmt_nome_linha->fetchColumn();
        $filtros_aplicados[] = "Linha " . strtoupper($nome_linha_filtro ?: $linha_id_filtro);
    }
    if ($data_de_filtro !== '') $filtros_aplicados[] = "De " . date('d/m/Y', strtotime($data_de_filtro));
    if ($data_ate_filtro !== '') $filtros_aplicados[] = "Até " . date('d/m/Y', strtotime($data_ate_filtro));
    if (!empty($situacao_filtrada)) $filtros_aplicados[] = "Situação: " . implode(' + ', array_map(fn($s) => $s === 'retomada' ? 'Retomadas' : 'Com Interrupção', $situacao_filtrada));
    $resumo_filtros = empty($filtros_aplicados) ? 'Sem filtro -- todas as OPs' : implode(' · ', $filtros_aplicados);

    // Converte a logo para Base64 para garantir a renderização no Dompdf
    $caminho_logo = 'logo.png';
    $base64_logo = '';
    if (file_exists($caminho_logo)) {
        $tipo_img = pathinfo($caminho_logo, PATHINFO_EXTENSION);
        $dados_img = file_get_contents($caminho_logo);
        $base64_logo = 'data:image/' . $tipo_img . ';base64,' . base64_encode($dados_img);
    }

    // O import da fonte Montserrat no CSS
    $html = '<html><head><meta charset="UTF-8"><style>
        @import url("https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;900&display=swap");
        
        @page { margin: 110px 30px 60px 30px; }
        body { font-family: "Montserrat", Arial, sans-serif; font-size: 10px; color: #1e293b; }
        
        /* CABEÇALHO ESTILIZADO */
        .cabecalho { position: fixed; top: -90px; left: 0; right: 0; height: 75px; border-bottom: 3px solid #42f54b; padding-bottom: 10px; background: #fff; }
        .tabela-cabecalho { width: 100%; border: none; border-collapse: collapse; }
        .tabela-cabecalho td { border: none; padding: 0; vertical-align: middle; }
        
        /* Alinhamento da Logo e Texto */
        .logo-img { max-height: 40px; width: auto; vertical-align: middle; }
        .marca-texto { font-family: "Montserrat", sans-serif; font-size: 18px; font-weight: 900; color: #1e293b; margin-left: 10px; vertical-align: middle; letter-spacing: 0.5px; }
        
        .titulo-relatorio { font-size: 16px; font-weight: bold; color: #1e293b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta { color: #64748b; font-size: 9px; line-height: 1.4; }
        
        .rodape { position: fixed; bottom: -50px; left: 0; right: 0; text-align: center; color: #94a3b8; font-size: 8px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
        
        /* TABELA DE DADOS */
        table.dados { width: 100%; border-collapse: collapse; margin-top: 5px; }
        table.dados th { background: #1e293b; color: #fff; text-align: left; padding: 8px; font-size: 9px; text-transform: uppercase; }
        table.dados td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; font-size: 9px; vertical-align: top; }
        table.dados tr:nth-child(even) td { background: #f8fafc; }
    </style></head><body>';

    // Injeta a tabela do cabeçalho com a imagem + texto lado a lado
    $html .= '<div class="cabecalho">
        <table class="tabela-cabecalho">
            <tr>
                <td style="width: 50%; text-align: left; white-space: nowrap;">';
                if ($base64_logo) {
                    $html .= '<img src="' . $base64_logo . '" class="logo-img" alt="Logo">';
                    $html .= '<span class="marca-texto">CHESIQUÍMICA</span>';
                } else {
                    $html .= '<span class="marca-texto" style="margin-left: 0;">CHESIQUÍMICA</span>';
                }
    $html .= '  </td>
                <td style="width: 50%; text-align: right;">
                    <div class="titulo-relatorio">Programação de OPs</div>
                    <div class="meta">
                        Gerado em ' . date('d/m/Y \à\s H:i') . ' &nbsp;|&nbsp; ' . count($linhas_ops) . ' OP(s) listada(s)<br>
                        <strong>Filtro:</strong> ' . htmlspecialchars($resumo_filtros) . '
                    </div>
                </td>
            </tr>
        </table>
    </div>';

    $html .= '<div class="rodape">Chesiquímica &mdash; Documento gerado automaticamente pelo sistema, uso interno.</div>';

    $html .= '<table class="dados"><thead><tr><th style="width:10%">OP</th><th style="width:8%">Fábrica</th><th style="width:10%">Linha</th><th style="width:9%">Data Plan.</th><th style="width:14%">Status</th><th style="width:12%">Situação</th><th style="width:37%">Produtos</th></tr></thead><tbody>';

    foreach ($linhas_ops as $op) {
        $st_norm = normalizaStatusExport($op['status']);
        $html .= '<tr>'
            . '<td><strong>' . htmlspecialchars($op['op_sistema']) . '</strong></td>'
            . '<td>' . ($op['fabrica'] ? 'F' . htmlspecialchars($op['fabrica']) : '-') . '</td>'
            . '<td>' . htmlspecialchars(strtoupper($op['linha_nome'] ?? '-')) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($op['data_planejada'])) . '</td>'
            . '<td>' . htmlspecialchars($status_meta[$st_norm] ?? $op['status']) . '</td>'
            . '<td>' . htmlspecialchars(situacaoEspecialTexto($op)) . '</td>'
            . '<td>' . htmlspecialchars($op['produtos_resumo'] ?? '') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    // Para a fonte remota (Google Fonts) funcionar no Dompdf, precisamos ativar o isRemoteEnabled
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html);
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $largura_pagina = $canvas->get_width();
    $altura_pagina = $canvas->get_height();
    $canvas->page_text($largura_pagina - 110, $altura_pagina - 32, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 8, [0.58, 0.64, 0.72]);

    $dompdf->stream($nome_arquivo . '.pdf', ['Attachment' => true]);
    exit;
}

// Formato desconhecido
header("Location: programacao_pcp.php");
exit;