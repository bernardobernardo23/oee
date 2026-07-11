<?php
session_start();
require 'conexao.php';

// Segurança: só PCP/ADMIN reordenam a fila de prioridade
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || !in_array($_SESSION['setor'], ['PCP', 'ADMIN'])) {
    header("Location: index.php");
    exit;
}

// ========================================================================
// ENDPOINT AJAX: Salvar a nova ordem da esteira (chamado via fetch, sem reload)
// Espera um POST JSON: { "linha_id": 2, "ordem": [39, 35, 31, ...] }
// onde "ordem" é a lista de IDs de ordens_producao na nova sequência.
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);

    $linha_id = (int)($payload['linha_id'] ?? 0);
    $ordem    = $payload['ordem'] ?? [];

    if (!$linha_id || !is_array($ordem) || empty($ordem)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Dados inválidos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Trava de segurança: só atualiza OPs que de fato pertencem a essa
        // linha e que estão em status de fila (evita alguém injetar um ID
        // de outra linha e reordenar coisa que não devia).
        $stmt_check = $pdo->prepare("SELECT id FROM ordens_producao WHERE linha_id = ? AND status IN ('PROGRAMADO', 'AGUARDANDO INICIO')");
        $stmt_check->execute([$linha_id]);
        $ids_validos = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

        $stmt_update = $pdo->prepare("UPDATE ordens_producao SET ordem_fila = ? WHERE id = ? AND linha_id = ?");
        $posicao = 1;
        foreach ($ordem as $op_id) {
            $op_id = (int)$op_id;
            if (!in_array($op_id, $ids_validos)) continue; // ignora IDs que não são desta linha/fila
            $stmt_update->execute([$posicao, $op_id, $linha_id]);
            $posicao++;
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ========================================================================
// CARREGAMENTO DA TELA (GET)
// ========================================================================
try {
    // Lista de fábricas disponíveis (fabrica > 0, ignora conta admin fabrica=0)
    $fabricas = $pdo->query("SELECT DISTINCT fabrica FROM linhas WHERE fabrica > 0 ORDER BY fabrica ASC")->fetchAll(PDO::FETCH_COLUMN);

    $fabrica_selecionada = isset($_GET['fabrica']) ? (int)$_GET['fabrica'] : ($fabricas[0] ?? 0);

    $stmt_linhas = $pdo->prepare("SELECT id, login FROM linhas WHERE fabrica = ? ORDER BY login ASC");
    $stmt_linhas->execute([$fabrica_selecionada]);
    $linhas_da_fabrica = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

    $linha_selecionada_id = isset($_GET['linha_id']) ? (int)$_GET['linha_id'] : ($linhas_da_fabrica[0]['id'] ?? 0);

    // Confirma que a linha selecionada realmente pertence à fábrica selecionada
    // (proteção contra troca de fábrica mantendo um linha_id antigo na URL)
    $pertence = false;
    foreach ($linhas_da_fabrica as $l) {
        if ($l['id'] == $linha_selecionada_id) $pertence = true;
    }
    if (!$pertence) $linha_selecionada_id = $linhas_da_fabrica[0]['id'] ?? 0;

    $fila_linha = [];
    if ($linha_selecionada_id) {
        $stmt_fila = $pdo->prepare("
            SELECT op.id, op.op_sistema, op.data_planejada, op.status, op.observacao_almoxarifado, op.ordem_fila,
                   (SELECT SUM(quantidade_planejada) FROM op_produtos WHERE op_id = op.id) as total_planejado,
                   (SELECT GROUP_CONCAT(p.codigo SEPARATOR ', ') FROM op_produtos op_prod JOIN produtos p ON op_prod.produto_id = p.id WHERE op_prod.op_id = op.id) as lista_produtos
            FROM ordens_producao op
            WHERE op.linha_id = ? AND op.status IN ('PROGRAMADO', 'AGUARDANDO INICIO')
            ORDER BY op.ordem_fila ASC, op.id ASC
        ");
        $stmt_fila->execute([$linha_selecionada_id]);
        $fila_linha = $stmt_fila->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Erro ao carregar dados de prioridade: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prioridade de Fila - MES/OEE</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'] } } } }
    </script>
    <style>
        .op-card.dragging { opacity: 0.4; }
        .op-card { touch-action: none; }
        .drop-indicator {
            height: 3px;
            background: #3b82f6;
            border-radius: 2px;
            margin: 2px 0;
        }
    </style>
</head>

<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <?php include 'header.php'; ?>

    <div class="max-w-5xl mx-auto px-4 space-y-6">

        <div>
            <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Prioridade de Fila por Linha</h2>
            <p class="text-sm text-slate-500 font-medium">Arraste as OPs para definir a ordem de produção de cada linha.</p>
        </div>

        <!-- ABAS DE FÁBRICA -->
        <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
            <?php foreach ($fabricas as $fab): ?>
                <a href="?fabrica=<?= $fab ?>" class="px-4 py-2 rounded-lg text-sm font-bold transition-colors <?= $fab == $fabrica_selecionada ? 'bg-slate-800 text-white shadow-sm' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-100' ?>">
                    Fábrica <?= $fab ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- BOTÕES DE LINHA DENTRO DA FÁBRICA SELECIONADA -->
        <div class="flex flex-wrap gap-2">
            <?php if (empty($linhas_da_fabrica)): ?>
                <p class="text-sm text-slate-400 italic">Nenhuma linha cadastrada nesta fábrica.</p>
            <?php endif; ?>
            <?php foreach ($linhas_da_fabrica as $l): ?>
                <a href="?fabrica=<?= $fabrica_selecionada ?>&linha_id=<?= $l['id'] ?>" class="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors border <?= $l['id'] == $linha_selecionada_id ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-blue-50 hover:border-blue-200' ?>">
                    <?= htmlspecialchars($l['login']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ESTEIRA DE PRIORIDADE -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div>
                    <h3 class="text-base font-bold text-slate-800">Esteira de Produção</h3>
                    <p class="text-xs text-slate-500">A OP do topo é a próxima a ser produzida nesta linha.</p>
                </div>
                <span id="status_salvamento" class="text-xs font-semibold text-slate-400"></span>
            </div>

            <?php if (empty($fila_linha)): ?>
                <div class="p-12 text-center">
                    <p class="text-sm text-slate-400 font-medium">Nenhuma OP na fila desta linha no momento.</p>
                </div>
            <?php else: ?>
                <div id="esteira" class="p-5 space-y-2" data-linha-id="<?= $linha_selecionada_id ?>">
                    <?php foreach ($fila_linha as $idx => $op):
                        $status_display = str_replace('PRODUCAO', 'PRODUÇÃO', strtoupper($op['status']));
                        $cor_status = $op['status'] === 'PROGRAMADO' ? 'bg-pink-100 text-pink-800 border-pink-200' : 'bg-amber-100 text-amber-800 border-amber-200';
                    ?>
                        <div class="op-card group flex items-center gap-3 bg-white border border-slate-200 rounded-xl p-4 shadow-sm cursor-grab active:cursor-grabbing hover:border-blue-300 transition-colors" draggable="true" data-op-id="<?= $op['id'] ?>">
                            <div class="w-7 h-7 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 font-bold text-xs shrink-0 posicao-badge">
                                <?= $idx + 1 ?>
                            </div>
                            <svg class="w-4 h-4 text-slate-300 group-hover:text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16"></path>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 text-sm">OP <?= htmlspecialchars($op['op_sistema']) ?></span>
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border <?= $cor_status ?>"><?= $status_display ?></span>
                                </div>
                                <p class="text-xs text-slate-500 truncate mt-0.5">
                                    <?= htmlspecialchars($op['lista_produtos'] ?? 'Sem produtos') ?>
                                    · <?= number_format($op['total_planejado'] ?? 0, 0, ',', '.') ?> un
                                    · Planejada <?= date('d/m/Y', strtotime($op['data_planejada'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const esteira = document.getElementById('esteira');
        const statusEl = document.getElementById('status_salvamento');
        let dragEl = null;

        if (esteira) {
            esteira.addEventListener('dragstart', (e) => {
                dragEl = e.target.closest('.op-card');
                if (!dragEl) return;
                dragEl.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            esteira.addEventListener('dragend', () => {
                if (dragEl) dragEl.classList.remove('dragging');
                dragEl = null;
                renumerarBadges();
                salvarOrdem();
            });

            esteira.addEventListener('dragover', (e) => {
                e.preventDefault();
                const alvo = e.target.closest('.op-card');
                if (!alvo || alvo === dragEl) return;

                const rect = alvo.getBoundingClientRect();
                const depoisDoAlvo = (e.clientY - rect.top) > (rect.height / 2);

                if (depoisDoAlvo) {
                    alvo.after(dragEl);
                } else {
                    alvo.before(dragEl);
                }
            });

            // Suporte básico a touch (tablets de chão de fábrica / PCP em notebook touch)
            let touchDragEl = null;
            esteira.addEventListener('touchstart', (e) => {
                touchDragEl = e.target.closest('.op-card');
            }, { passive: true });

            esteira.addEventListener('touchmove', (e) => {
                if (!touchDragEl) return;
                const touch = e.touches[0];
                const elemAbaixo = document.elementFromPoint(touch.clientX, touch.clientY);
                const alvo = elemAbaixo ? elemAbaixo.closest('.op-card') : null;
                if (!alvo || alvo === touchDragEl) return;

                const rect = alvo.getBoundingClientRect();
                const depoisDoAlvo = (touch.clientY - rect.top) > (rect.height / 2);
                if (depoisDoAlvo) {
                    alvo.after(touchDragEl);
                } else {
                    alvo.before(touchDragEl);
                }
            }, { passive: true });

            esteira.addEventListener('touchend', () => {
                if (touchDragEl) {
                    renumerarBadges();
                    salvarOrdem();
                }
                touchDragEl = null;
            });
        }

        function renumerarBadges() {
            document.querySelectorAll('.op-card').forEach((card, idx) => {
                const badge = card.querySelector('.posicao-badge');
                if (badge) badge.textContent = idx + 1;
            });
        }

        async function salvarOrdem() {
            const linhaId = esteira.dataset.linhaId;
            const ordem = Array.from(document.querySelectorAll('.op-card')).map(c => c.dataset.opId);

            statusEl.textContent = 'Salvando...';
            statusEl.className = 'text-xs font-semibold text-amber-500';

            try {
                const resp = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ linha_id: linhaId, ordem: ordem })
                });
                const dados = await resp.json();
                if (dados.ok) {
                    statusEl.textContent = 'Ordem salva ✓';
                    statusEl.className = 'text-xs font-semibold text-emerald-500';
                } else {
                    throw new Error(dados.erro || 'Falha ao salvar');
                }
            } catch (e) {
                statusEl.textContent = 'Erro ao salvar — tente novamente';
                statusEl.className = 'text-xs font-semibold text-rose-500';
            }

            setTimeout(() => { statusEl.textContent = ''; }, 2500);
        }
    </script>
</body>

</html>
