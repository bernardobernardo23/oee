<?php
session_start();
require 'conexao.php';
require_once 'notificacoes.php';
if (!isset($_SESSION['linha_id']) || ($_SESSION['tipo_acesso'] ?? null) !== 'linha') {
    header("Location: index.php");
    exit;
}
$linha_logada_id = (int)$_SESSION['linha_id'];

try {
    $motivos = $pdo->query("SELECT id, codigo, descricao FROM motivos_parada ORDER BY descricao")->fetchAll(PDO::FETCH_ASSOC);

    // 1. Busca a soma total de produtos fabricados HOJE por esta linha
    $stmt_total = $pdo->prepare("
        SELECT IFNULL(SUM(ap.producao_boas), 0) 
        FROM apontamento_producao ap 
        JOIN apontamentos a ON ap.apontamento_id = a.id 
        WHERE a.linha_id = ? AND DATE(a.data_registro) = CURDATE()
    ");
    $stmt_total->execute([$linha_logada_id]);
    $total_hoje = $stmt_total->fetchColumn();

    // 2. Verifica se existe algum apontamento EM ANDAMENTO (hora_fim nula)
    $stmt_ativo = $pdo->prepare("
        SELECT a.*, o.id as op_id, o.status as op_status 
        FROM apontamentos a 
        JOIN ordens_producao o ON a.ordem_producao = o.op_sistema 
        WHERE a.linha_id = ? AND a.hora_fim IS NULL 
        ORDER BY a.id DESC LIMIT 1
    ");
    $stmt_ativo->execute([$linha_logada_id]);
    $apontamento_ativo = $stmt_ativo->fetch(PDO::FETCH_ASSOC);

    // 3. Busca a Fila de OPs -- inclui os status intermediários do duplo gate
    // (Separação/Formulação) pra não sumir da tela quando falta só um dos dois.
    // Ordenada por ordem_fila: é a prioridade que o PCP define na esteira,
    // espelhada aqui pro operador.
    $ops_disponiveis = [];
    if (!$apontamento_ativo) {
        $stmt_ops = $pdo->prepare("
            SELECT id, op_sistema, data_planejada, status, ordem_fila 
            FROM ordens_producao 
            WHERE linha_id = ? AND status IN ('PROGRAMADO', 'AGUARDANDO FORMULACAO', 'AGUARDANDO ALMOXARIFADO', 'AGUARDANDO INICIO') 
            ORDER BY ordem_fila ASC, data_planejada ASC, id ASC
        ");
        $stmt_ops->execute([$linha_logada_id]);
        $ops_disponiveis = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Erro ao carregar dados: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Máquina - IHM</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'], } } } }</script>
    <style> dialog[open] { display: flex; flex-direction: column; } </style>
</head>
<body class="bg-slate-100 min-h-screen font-sans pb-12">

    <?php include 'header.php'; ?>

    <div class="max-w-5xl mx-auto px-4 mt-8">
        
        <?php if (isset($_GET['sucesso'])): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 mb-6 rounded-lg font-bold flex gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> <?= htmlspecialchars($_GET['sucesso']) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['erro'])): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 mb-6 rounded-lg font-bold flex gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Erro: <?= htmlspecialchars($_GET['erro']) ?></div>
        <?php endif; ?>

        <?php if (!$apontamento_ativo): ?>
            
            <div class="bg-white p-8 rounded-2xl shadow border border-slate-200 text-center max-w-2xl mx-auto relative overflow-hidden">
                <div class="absolute top-0 right-0 bg-emerald-500 text-white px-5 py-3 rounded-bl-2xl shadow-sm border-b border-l border-emerald-600">
                    <span class="block text-[9px] font-bold uppercase tracking-widest opacity-90">Produzido Hoje</span>
                    <span class="text-2xl font-black leading-none"><?= number_format($total_hoje, 0, ',', '.') ?> <span class="text-xs font-semibold opacity-80">un</span></span>
                </div>

                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 mt-4">
                    <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                </div>
                <h2 class="text-2xl font-black text-slate-800 mb-2">Máquina Parada</h2>
                <p class="text-slate-500 font-medium mb-8">Verifique a fila de produção abaixo</p>
                
                <form action="acao_apontamento.php" method="POST" class="space-y-5 text-left">
                    <input type="hidden" name="acao" value="iniciar">
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-2 uppercase tracking-wide">1. Fila de Produção da Linha (ordem definida pelo PCP)</label>
                        <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
                            <?php if (empty($ops_disponiveis)): ?>
                                <div class="text-center p-6 border-2 border-dashed border-slate-200 rounded-xl text-slate-400 font-semibold text-sm">Nenhuma OP na fila desta linha.</div>
                            <?php endif; ?>
                            <?php 
                            $hoje = date('Y-m-d');
                            foreach ($ops_disponiveis as $idx => $op): 
                                $is_liberada = ($op['status'] === 'AGUARDANDO INICIO');

                                $data_plan = $op['data_planejada'];
                                if ($data_plan < $hoje) {
                                    $tag_data = 'ATRASADA';
                                    $cor_data = 'text-rose-600';
                                } elseif ($data_plan == $hoje) {
                                    $tag_data = 'HOJE';
                                    $cor_data = 'text-blue-600';
                                } else {
                                    $tag_data = 'FUTURA (' . date('d/m', strtotime($data_plan)) . ')';
                                    $cor_data = 'text-slate-400';
                                }

                                // Reflete qual das 2 verificações (Separação/Formulação) ainda
                                // falta, em vez de assumir que é sempre só o Almoxarifado.
                                switch ($op['status']) {
                                    case 'PROGRAMADO':
                                        $tag_status = 'Pendente Separação e Formulação';
                                        $cor_status = 'bg-slate-100 text-slate-600 border-slate-200';
                                        break;
                                    case 'AGUARDANDO FORMULACAO':
                                        $tag_status = 'Pendente Formulação';
                                        $cor_status = 'bg-purple-100 text-purple-700 border-purple-200';
                                        break;
                                    case 'AGUARDANDO ALMOXARIFADO':
                                        $tag_status = 'Pendente Separação';
                                        $cor_status = 'bg-cyan-100 text-cyan-700 border-cyan-200';
                                        break;
                                    default:
                                        $tag_status = 'Liberada para Início';
                                        $cor_status = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                                }
                            ?>
                                <label class="flex items-center gap-3 p-3 rounded-xl border-2 transition-colors <?= $is_liberada ? 'border-slate-200 hover:border-blue-400 cursor-pointer has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50' : 'border-slate-100 bg-slate-50 opacity-70 cursor-not-allowed' ?>">
                                    <input type="radio" name="op_id" value="<?= $op['id'] ?>" <?= $is_liberada ? 'required' : 'disabled' ?> class="w-5 h-5 text-blue-600 shrink-0">
                                    <div class="w-7 h-7 flex items-center justify-center rounded-full bg-slate-200 text-slate-600 font-black text-xs shrink-0"><?= $idx + 1 ?></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-black text-slate-800 text-sm">OP <?= htmlspecialchars($op['op_sistema']) ?></span>
                                            <span class="text-[10px] font-bold uppercase <?= $cor_data ?>"><?= $tag_data ?></span>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 rounded text-[9px] font-bold uppercase border shrink-0 <?= $cor_status ?>"><?= $tag_status ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-2 uppercase tracking-wide">2. Operador Principal</label>
                            <input type="text" name="nome_operador" required placeholder="Seu nome" class="w-full px-4 py-3 border border-slate-300 rounded-xl font-bold text-slate-800 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-400">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-2 uppercase tracking-wide">3. Auxiliares</label>
                            <input type="text" name="equipe_auxiliares" placeholder="Ex: João, Maria" class="w-full px-4 py-3 border border-slate-300 rounded-xl font-bold text-slate-800 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-400">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black text-lg py-4 rounded-xl shadow-lg hover:shadow-blue-500/30 transition-all flex items-center justify-center gap-3 mt-4">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"></path></svg>
                        INICIAR PRODUÇÃO
                    </button>
                </form>
            </div>

        <?php else: ?>
            <?php 
                $is_paused = !empty($apontamento_ativo['parada_inicio']);
                $cor_bg = $is_paused ? 'bg-rose-50 border-rose-200' : 'bg-white border-blue-200 shadow-blue-900/5';
                $cor_texto = $is_paused ? 'text-rose-900' : 'text-slate-800';
                $cor_badge = $is_paused ? 'bg-rose-500' : 'bg-emerald-500';
                $status_texto = $is_paused ? 'MÁQUINA PAUSADA' : 'MÁQUINA RODANDO';
                
                $hora_inicio = new DateTime($apontamento_ativo['hora_inicio']);
                $agora = new DateTime();
                $diff_minutos = $hora_inicio->diff($agora)->i + ($hora_inicio->diff($agora)->h * 60) + ($hora_inicio->diff($agora)->days * 24 * 60);
            ?>

            <div class="<?= $cor_bg ?> p-6 md:p-10 rounded-3xl shadow-xl border-2 transition-colors relative overflow-hidden">
                
                <div class="absolute top-0 right-0 bg-emerald-500 text-white px-5 py-3 rounded-bl-3xl shadow-sm border-b border-l border-emerald-600 z-10">
                    <span class="block text-[9px] font-bold uppercase tracking-widest opacity-90">Produzido Hoje (Linha)</span>
                    <span class="text-2xl font-black leading-none"><?= number_format($total_hoje, 0, ',', '.') ?> <span class="text-xs font-semibold opacity-80">un</span></span>
                </div>

                <div class="flex justify-between items-start mb-8 mt-2">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="relative flex h-4 w-4">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full <?= $cor_badge ?> opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-4 w-4 <?= $cor_badge ?>"></span>
                            </span>
                            <h2 class="text-xl font-black <?= $cor_texto ?> tracking-widest"><?= $status_texto ?></h2>
                        </div>
                        <h1 class="text-4xl md:text-5xl font-black text-slate-900 uppercase">OP: <?= htmlspecialchars($apontamento_ativo['ordem_producao']) ?></h1>
                        <p class="text-sm font-bold text-slate-500 mt-2">Operador: <?= htmlspecialchars($apontamento_ativo['nome_operador']) ?></p>
                    </div>
                    
                    <div class="text-right">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-1">Tempo Total</span>
                        <div class="text-3xl md:text-4xl font-mono font-black <?= $cor_texto ?>"><?= str_pad(floor($diff_minutos / 60), 2, '0', STR_PAD_LEFT) ?>:<?= str_pad($diff_minutos % 60, 2, '0', STR_PAD_LEFT) ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-8">
                    
                    <?php if (!$is_paused): ?>
                        <button onclick="document.getElementById('modal_pausar').showModal()" class="w-full bg-amber-400 hover:bg-amber-500 text-amber-950 font-black text-xl py-5 rounded-2xl shadow-lg transition-all flex items-center justify-center gap-3">
                            <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                            PAUSAR MÁQUINA
                        </button>
                    <?php else: ?>
                        <form action="acao_apontamento.php" method="POST">
                            <input type="hidden" name="acao" value="retomar">
                            <input type="hidden" name="apontamento_id" value="<?= $apontamento_ativo['id'] ?>">
                            <input type="hidden" name="op_id" value="<?= $apontamento_ativo['op_id'] ?>">
                            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xl py-5 rounded-2xl shadow-lg hover:shadow-emerald-500/40 transition-all flex items-center justify-center gap-3">
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path></svg>
                                RETOMAR PRODUÇÃO
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($is_paused): ?>
                        <button disabled class="w-full bg-slate-300 text-slate-500 font-black text-xl py-5 rounded-2xl cursor-not-allowed flex items-center justify-center gap-3 opacity-60">
                            Retome a produção para finalizar
                        </button>
                    <?php else: ?>
                        <button onclick="abrirModalFinalizar()" class="w-full bg-slate-900 hover:bg-black text-white font-black text-xl py-5 rounded-2xl shadow-lg transition-all flex items-center justify-center gap-3">
                            <svg class="w-8 h-8 text-rose-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z" clip-rule="evenodd"></path></svg>
                            FINALIZAR TURNO
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- MODAL DE PAUSA -->
            <dialog id="modal_pausar" class="p-0 rounded-3xl shadow-2xl border-0 w-[95%] max-w-lg bg-white m-auto backdrop:bg-slate-900/80 backdrop:backdrop-blur-sm">
                <form action="acao_apontamento.php" method="POST" class="flex flex-col">
                    <div class="bg-amber-400 p-6 flex justify-between items-center rounded-t-3xl">
                        <h3 class="text-xl font-black text-amber-950">Por que a máquina parou?</h3>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-amber-900 hover:bg-amber-300 rounded-full p-2"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                    </div>
                    <div class="p-8">
                        <input type="hidden" name="acao" value="pausar">
                        <input type="hidden" name="apontamento_id" value="<?= $apontamento_ativo['id'] ?>">
                        <input type="hidden" name="op_id" value="<?= $apontamento_ativo['op_id'] ?>">
                        
                        <label class="block text-sm font-bold text-slate-600 mb-3 uppercase tracking-wide">Selecione o Motivo da Parada:</label>
                        <select name="motivo_id" required class="w-full px-4 py-4 border-2 border-slate-200 rounded-xl font-bold text-slate-800 focus:border-amber-400 focus:ring-0 appearance-none text-lg">
                            <option value="">-- Escolha --</option>
                            <?php foreach ($motivos as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= $m['codigo'] ?> - <?= htmlspecialchars($m['descricao']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="p-6 border-t border-slate-100 bg-slate-50 rounded-b-3xl">
                        <button type="submit" class="w-full bg-slate-900 text-white font-black py-4 rounded-xl text-lg hover:bg-black transition-colors">CONFIRMAR E PARAR MÁQUINA</button>
                    </div>
                </form>
            </dialog>

            <!-- MODAL DE FINALIZAÇÃO PRINCIPAL -->
            <dialog id="modal_finalizar" class="p-0 rounded-3xl shadow-2xl border-0 w-[95%] max-w-4xl bg-white m-auto backdrop:bg-slate-900/80 backdrop:backdrop-blur-sm max-h-[90vh]">
                <form id="form_finalizar" action="acao_apontamento.php" method="POST" class="flex flex-col h-full w-full" onsubmit="return validarFinalizacao(event)">
                    
                    <div class="bg-slate-900 p-6 flex justify-between items-center rounded-t-3xl shrink-0">
                        <div>
                            <span class="text-blue-400 font-bold text-xs uppercase tracking-widest block mb-1">Passo Final</span>
                            <h3 class="text-2xl font-black text-white">Declarar Produção e Encerrar</h3>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-white rounded-full p-2"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                    </div>

                    <div class="p-6 md:p-8 overflow-y-auto grow bg-slate-50 space-y-8">
                        <input type="hidden" name="acao" value="finalizar">
                        <input type="hidden" name="apontamento_id" value="<?= $apontamento_ativo['id'] ?>">
                        <input type="hidden" name="op_id" value="<?= $apontamento_ativo['op_id'] ?>">

                        <!-- SEÇÃO 1: PRODUÇÃO (Sem limite máximo) -->
                        <div>
                            <h4 class="font-bold text-slate-800 mb-3 border-b border-slate-200 pb-2">1. Produção Entregue</h4>
                            <div id="container_produtos_dinamicos" class="space-y-3">Carregando...</div>
                        </div>

                        <!-- SEÇÃO 2: PERDAS OBRIGATÓRIAS -->
                        <div>
                            <h4 class="font-bold text-slate-800 mb-3 border-b border-slate-200 pb-2">2. Controle de Perdas (Obrigatório)</h4>
                            
                            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-4">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4">Houve perda ou destruição de materiais neste turno?</p>
                                <div class="flex flex-col md:flex-row gap-6">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" name="confirma_perda" value="nao" required onclick="esconderBlocoPerdas()" class="w-5 h-5 text-emerald-500 border-slate-300 focus:ring-emerald-500">
                                        <span class="font-bold text-slate-700 group-hover:text-emerald-600 transition-colors">Não, zero perdas</span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" name="confirma_perda" value="sim" required onclick="abrirBlocoPerdas()" class="w-5 h-5 text-rose-500 border-slate-300 focus:ring-rose-500">
                                        <span class="font-bold text-slate-700 group-hover:text-rose-600 transition-colors">Sim, registrar perdas</span>
                                    </label>
                                </div>
                            </div>

                            <div id="bloco_perdas" class="hidden bg-rose-50/50 p-4 rounded-xl border border-rose-100">
                                <div class="flex justify-between items-center mb-4">
                                    <p class="text-xs font-bold text-rose-800 uppercase tracking-widest">Lista de Materiais Perdidos</p>
                                    <button type="button" onclick="adicionarInsumoAvulso()" class="text-xs bg-white border border-rose-300 text-rose-600 hover:bg-rose-50 font-bold px-3 py-1.5 rounded transition-colors shadow-sm">+ Adicionar Insumo</button>
                                </div>
                                <div id="container_perdas_dinamicas" class="space-y-3">
                                    <!-- Campos de perda caem aqui via JS -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 border-t border-slate-200 bg-white rounded-b-3xl shrink-0">
                        <button type="submit" class="w-full bg-emerald-500 text-white font-black py-4 rounded-xl text-lg hover:bg-emerald-600 shadow-lg transition-all flex items-center justify-center gap-2">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                            SALVAR DADOS E CALCULAR OEE
                        </button>
                    </div>
                </form>
            </dialog>

            <!-- MODAL DE ALERTA DE EXCEDENTE (Bonita, substitui o "confirm" do navegador) -->
            <dialog id="modal_alerta_excedente" class="p-0 rounded-3xl shadow-2xl border-0 w-[95%] max-w-md bg-white m-auto backdrop:bg-slate-900/80 backdrop:backdrop-blur-sm">
                <div class="p-8 text-center">
                    <div class="w-16 h-16 bg-amber-100 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <h3 class="text-2xl font-black text-slate-800 mb-2">Produção Excedente!</h3>
                    <p class="text-sm font-semibold text-slate-600 mb-8 leading-relaxed">Você está a lançar <span id="qtd_excedente_texto" class="text-amber-600 font-black text-lg mx-1"></span> unidade(s) a mais que a meta estipulada para esta OP.<br>Deseja realmente confirmar este excedente?</p>
                    
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal_alerta_excedente').close()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3.5 rounded-xl transition-colors">Voltar e Revisar</button>
                        <button type="button" onclick="confirmarExcedenteForcado()" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-black py-3.5 rounded-xl shadow-lg hover:shadow-amber-500/30 transition-all">Sim, Confirmar</button>
                    </div>
                </div>
            </dialog>

            <!-- TEMPLATE DO INSUMO COM BUSCA DINÂMICA -->
            <template id="tpl_insumo_avulso">
                <div class="flex flex-col md:flex-row gap-3 bg-white p-3 rounded-lg border border-rose-200 shadow-sm items-center">
                    <div class="w-full md:w-32 relative shrink-0">
                        <!-- Campo onde o operador digita o código -->
                        <input type="text" placeholder="Código" required class="input-codigo-insumo w-full px-3 py-2 text-sm font-bold border border-slate-300 rounded-md focus:border-rose-400 focus:outline-none focus:ring-1 focus:ring-rose-400 transition-colors uppercase" onblur="buscarInsumo(this)">
                        <!-- Input oculto onde gravamos o ID real do componente no banco -->
                        <input type="hidden" name="item_id[]" class="hidden-item-id" required>
                    </div>
                    <div class="w-full flex-1">
                        <!-- Área onde o nome do insumo aparece após digitar o código -->
                        <div class="display-desc-insumo text-xs font-bold text-slate-500 bg-slate-50 px-3 py-2 rounded-md border border-slate-100 h-[38px] flex items-center overflow-hidden whitespace-nowrap">
                            Aguardando código...
                        </div>
                    </div>
                    <div class="w-full md:w-32 relative shrink-0">
                        <input type="number" name="item_qtd[]" placeholder="Qtd" required min="1" class="w-full px-3 py-2 text-sm font-bold text-rose-600 border border-slate-300 rounded-md focus:border-rose-400 focus:outline-none focus:ring-1 focus:ring-rose-400 pr-8">
                        <span class="absolute right-3 top-2 text-xs font-bold text-slate-400">un</span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-rose-600 bg-slate-50 hover:bg-rose-50 p-2 rounded-md transition-colors shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
            </template>

            <script>
                // Controle da modal de finalização
                let forcarEnvioModal = false;

                async function abrirModalFinalizar() {
                    const opId = <?= $apontamento_ativo['op_id'] ?? 0 ?>;
                    document.getElementById('modal_finalizar').showModal();
                    
                    const containerProds = document.getElementById('container_produtos_dinamicos');
                    containerProds.innerHTML = '<div class="text-sm font-medium text-blue-500 py-4">Carregando estrutura...</div>';
                    
                    try {
                        const resposta = await fetch(`get_detalhes_op.php?op_id=${opId}`);
                        const dados = await resposta.json();
                        
                        containerProds.innerHTML = '';
                        dados.produtos.forEach(p => {
                            const htmlProd = `
                                <div class="flex flex-col md:flex-row gap-3 bg-white p-4 rounded-lg border border-slate-200 shadow-sm items-center">
                                    <input type="hidden" name="produto_id[]" value="${p.produto_id}">
                                    <input type="hidden" class="meta-produto" value="${p.quantidade_planejada}">
                                    <div class="w-full md:flex-1">
                                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">CÓD: ${p.codigo} <span class="ml-2 text-blue-500">Meta/Programado: ${p.quantidade_planejada}</span></div>
                                        <div class="text-sm font-bold text-slate-700">${p.descricao}</div>
                                    </div>
                                    <div class="w-full md:w-32">
                                        <label class="block text-[10px] font-bold text-emerald-600 uppercase mb-1">Aprovadas</label>
                                        <input type="number" name="producao_boas[]" required min="0" placeholder="0" class="input-boas w-full px-3 py-2 border border-slate-300 rounded-md text-emerald-700 font-bold focus:border-emerald-400 focus:outline-none bg-emerald-50/30">
                                    </div>
                                    <div class="w-full md:w-32">
                                        <label class="block text-[10px] font-bold text-rose-600 uppercase mb-1">Refugo</label>
                                        <input type="number" name="producao_refugo[]" required min="0" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-md text-rose-700 font-bold focus:border-rose-400 focus:outline-none bg-rose-50/30">
                                    </div>
                                </div>
                            `;
                            containerProds.insertAdjacentHTML('beforeend', htmlProd);
                        });
                    } catch (e) {
                        containerProds.innerHTML = '<div class="text-red-500 font-bold py-4">Erro ao carregar os produtos.</div>';
                    }
                }

                function validarFinalizacao(event) {
                    if (forcarEnvioModal) return true; // Se a pessoa já confirmou na segunda modal, deixa passar

                    const inputsBoas = document.querySelectorAll('.input-boas');
                    const inputsMeta = document.querySelectorAll('.meta-produto');
                    let totalAMais = 0;

                    for (let i = 0; i < inputsBoas.length; i++) {
                        let boas = parseInt(inputsBoas[i].value) || 0;
                        let meta = parseInt(inputsMeta[i].value) || 0;

                        if (boas > meta) {
                            totalAMais += (boas - meta);
                        }
                    }

                    if (totalAMais > 0) {
                        event.preventDefault(); // Impede o envio do formulário principal
                        document.getElementById('qtd_excedente_texto').innerText = totalAMais;
                        document.getElementById('modal_alerta_excedente').showModal(); // Abre nossa modal customizada
                        return false;
                    }
                    return true; 
                }

                function confirmarExcedenteForcado() {
                    document.getElementById('modal_alerta_excedente').close();
                    forcarEnvioModal = true;
                    document.getElementById('form_finalizar').submit(); // Força o envio do formulário
                }

                // Controle das Perdas Dinâmicas
                function abrirBlocoPerdas() {
                    document.getElementById('bloco_perdas').classList.remove('hidden');
                    if(document.getElementById('container_perdas_dinamicas').children.length === 0) {
                        adicionarInsumoAvulso();
                    }
                }

                function esconderBlocoPerdas() {
                    document.getElementById('bloco_perdas').classList.add('hidden');
                    // Limpa os campos se o usuário desistir de lançar perda
                    document.getElementById('container_perdas_dinamicas').innerHTML = '';
                }

                function adicionarInsumoAvulso() {
                    const template = document.getElementById('tpl_insumo_avulso').content.cloneNode(true);
                    document.getElementById('container_perdas_dinamicas').appendChild(template);
                }

                // Função assíncrona que vai no PHP buscar o nome do Insumo
                async function buscarInsumo(inputElement) {
                    const codigo = inputElement.value.trim();
                    const container = inputElement.closest('.flex');
                    const descDiv = container.querySelector('.display-desc-insumo');
                    const hiddenInput = container.querySelector('.hidden-item-id');

                    if (!codigo) {
                        descDiv.innerHTML = 'Aguardando código...';
                        hiddenInput.value = '';
                        return;
                    }

                    descDiv.innerHTML = '<span class="text-blue-500 animate-pulse">Buscando...</span>';

                    try {
                        const res = await fetch(`buscar_insumo.php?codigo=${codigo}`);
                        const dados = await res.json();

                        if (dados.sucesso) {
                            descDiv.innerHTML = `<span class="text-emerald-700 font-black">[${dados.tipo}]</span> <span class="text-slate-800 ml-1">${dados.descricao}</span>`;
                            hiddenInput.value = dados.id; // Salva o ID real para o banco!
                        } else {
                            descDiv.innerHTML = '<span class="text-rose-500 font-bold">Insumo não encontrado!</span>';
                            hiddenInput.value = '';
                            inputElement.value = ''; // Limpa o campo para o operador tentar de novo
                        }
                    } catch (e) {
                        descDiv.innerHTML = '<span class="text-rose-500 font-bold">Erro de conexão.</span>';
                    }
                }
            </script>

        <?php endif; ?>
    </div>
</body>
</html>