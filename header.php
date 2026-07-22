<?php
// Estado inicial do sininho renderizado no servidor (evita o badge
// "piscar" de 0 pra N assim que o JS termina de buscar). Se a página
// que incluiu esse header não carregou notificacoes.php/$pdo (ex: uma
// tela que não precisa disso), o sino simplesmente não aparece.
$sino_disponivel = function_exists('buscar_notificacoes_sessao') && isset($pdo) && isset($_SESSION['tipo_acesso']);
$notif_inicial = ['itens' => [], 'nao_lidas' => 0];
if ($sino_disponivel) {
    try {
        $notif_inicial = buscar_notificacoes_sessao($pdo, 8);
    } catch (Throwable $e) {
        // Se a tabela `notificacoes` ainda não existir (migração não
        // rodada) ou qualquer outro erro de banco acontecer aqui, o
        // sino simplesmente não aparece -- nunca deve travar a página
        // inteira por causa disso.
        $sino_disponivel = false;
    }
}
// PCP e Admin são os únicos que recebem os 3 tipos de evento do ciclo
// inteiro da OP (separada/formulada/produzida) -- só eles ganham as
// abas no sino. Almoxarifado e Formulação continuam com lista única.
$sino_com_abas = $sino_disponivel && ($_SESSION['tipo_acesso'] ?? '') === 'usuario' && in_array($_SESSION['setor'] ?? '', ['PCP', 'ADMIN'], true);
$contadores_categoria_inicial = ['separadas' => 0, 'formuladas' => 0, 'produzidas' => 0, 'outras' => 0];
if ($sino_com_abas) {
    try {
        foreach (array_keys($contadores_categoria_inicial) as $cat) {
            $contadores_categoria_inicial[$cat] = contar_nao_lidas_categoria($pdo, $cat);
        }
    } catch (Throwable $e) {
        // Mesma regra do sino geral: erro aqui nunca deve travar a página,
        // só faz as bolinhas das abas não aparecerem até o próximo poll.
    }
}
?>
<header class="bg-white border-b border-gray-200 shadow-sm px-4 md:px-8 py-3 flex justify-between items-center sticky top-0 z-50 font-sans">
    
    <div class="flex items-center gap-3 md:gap-5">
        
        <button onclick="window.history.back()" title="Voltar para a página anterior" class="group flex items-center justify-center w-10 h-10 rounded-full bg-gray-50 hover:bg-gray-100 border border-transparent hover:border-gray-300 transition-all duration-300 shadow-sm hover:shadow shrink-0">
            <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-800 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
        </button>

        <div class="w-10 h-10 md:w-12 md:h-12 bg-gray-50 rounded-lg flex items-center justify-center overflow-hidden border border-gray-100 shrink-0 shadow-inner">
            <img src="logo.png" alt="Logo ChesiQuímica" class="w-full h-full object-contain p-1.5">
        </div>
        
        <div class="h-8 w-[2px] bg-gray-200 hidden md:block rounded-full"></div>
        
        <div class="flex flex-col justify-center">
            <h1 class="text-lg md:text-xl font-black text-gray-900 tracking-tighter uppercase leading-none mb-1">
                ChesiQuímica
            </h1>
            
            <div class="flex items-center gap-2">
                <?php if (isset($_SESSION['login']) && isset($_SESSION['fabrica']) && $_SESSION['fabrica'] > 0 && $_SESSION['fabrica'] != 99): ?>
                    <span class="text-[10px] md:text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Linha: <span class="text-blue-600"><?= htmlspecialchars($_SESSION['login']) ?></span>
                    </span>
                <?php endif; ?>

                <?php if (isset($_SESSION['fabrica']) && $_SESSION['fabrica'] == 99): ?>
                    <span class="text-[10px] md:text-xs font-bold text-pink-600 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-pink-500"></span>
                        PCP
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4 md:gap-6">

        <?php if ($sino_disponivel): ?>
            <div class="relative">
                <button id="btn_sino_notificacoes" onclick="toggleSinoNotificacoes()" title="Notificações" type="button" class="group relative flex items-center justify-center w-10 h-10 rounded-full bg-gray-50 hover:bg-gray-100 border border-transparent hover:border-gray-300 transition-all duration-300 shadow-sm hover:shadow">
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-800 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                    <span id="badge_notificacoes" class="<?= $notif_inicial['nao_lidas'] > 0 ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center border-2 border-white">
                        <?= $notif_inicial['nao_lidas'] > 99 ? '99+' : $notif_inicial['nao_lidas'] ?>
                    </span>
                </button>

                <div id="painel_notificacoes" class="hidden absolute right-0 mt-2 w-80 max-w-[90vw] bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden z-50">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50">
                        <span class="text-sm font-black text-gray-800">Notificações</span>
                        <button type="button" onclick="marcarTodasLidas()" class="text-[11px] font-bold text-blue-600 hover:text-blue-700">Marcar todas como lidas</button>
                    </div>
                    <?php if ($sino_com_abas): ?>
                        <div class="flex border-b border-gray-100 bg-white">
                            <button type="button" onclick="mudarCategoriaNotificacao('separadas', this)" data-categoria="separadas" class="tab-categoria-notif relative flex-1 py-2 text-[10px] font-black uppercase tracking-wide text-blue-600 border-b-2 border-blue-600">
                                Separadas
                                <span class="dot-categoria-notif <?= $contadores_categoria_inicial['separadas'] > 0 ? '' : 'hidden' ?> absolute top-1 right-2 w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            </button>
                            <button type="button" onclick="mudarCategoriaNotificacao('formuladas', this)" data-categoria="formuladas" class="tab-categoria-notif relative flex-1 py-2 text-[10px] font-black uppercase tracking-wide text-gray-400 border-b-2 border-transparent hover:text-gray-600">
                                Formuladas
                                <span class="dot-categoria-notif <?= $contadores_categoria_inicial['formuladas'] > 0 ? '' : 'hidden' ?> absolute top-1 right-2 w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            </button>
                            <button type="button" onclick="mudarCategoriaNotificacao('produzidas', this)" data-categoria="produzidas" class="tab-categoria-notif relative flex-1 py-2 text-[10px] font-black uppercase tracking-wide text-gray-400 border-b-2 border-transparent hover:text-gray-600">
                                Produzidas
                                <span class="dot-categoria-notif <?= $contadores_categoria_inicial['produzidas'] > 0 ? '' : 'hidden' ?> absolute top-1 right-2 w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            </button>
                            <button type="button" onclick="mudarCategoriaNotificacao('outras', this)" data-categoria="outras" class="tab-categoria-notif relative flex-1 py-2 text-[10px] font-black uppercase tracking-wide text-gray-400 border-b-2 border-transparent hover:text-gray-600">
                                Outras
                                <span class="dot-categoria-notif <?= $contadores_categoria_inicial['outras'] > 0 ? '' : 'hidden' ?> absolute top-1 right-2 w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            </button>
                        </div>
                    <?php endif; ?>
                    <div id="lista_notificacoes" class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                        <div class="p-6 text-center text-xs text-gray-400 font-medium">Carregando...</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <a href="logout.php" title="Sair do Sistema" class="group flex items-center justify-center w-10 h-10 rounded-full bg-gray-50 hover:bg-red-50 border border-transparent hover:border-red-200 transition-all duration-300 shadow-sm hover:shadow">
            <svg class="w-5 h-5 text-gray-400 group-hover:text-red-600 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
        </a>
    </div>
    
</header>

<?php if ($sino_disponivel): ?>
<script>
    // ==========================================
    // SININHO DE NOTIFICAÇÕES
    // ==========================================
    let sinoAberto = false;
    let categoriaNotifAtiva = <?= $sino_com_abas ? "'separadas'" : 'null' ?>;
    // Só o PCP é redirecionado pro filtro da OP ao clicar numa notificação
    // -- os outros setores continuam só marcando como lida, como sempre.
    const notifAbreNaOP = <?= (($_SESSION['tipo_acesso'] ?? '') === 'usuario' && ($_SESSION['setor'] ?? '') === 'PCP') ? 'true' : 'false' ?>;

    const iconesPorTipoNotificacao = {
        'OP_NOVA': '🆕',
        'OP_LIBERADA': '✅',
        'OP_CANCELADA': '🚫',
        'OP_REPROGRAMADA': '🔄',
        'PENDENCIA_ALMOXARIFADO': '⚠️',
        'PENDENCIA_FORMULACAO': '⚠️',
        'OP_SEPARADA': '📦',
        'OP_FORMULADA': '🧪',
        'OP_PRODUCAO_INICIADA': '▶️',
        'OP_PAUSADA': '⏸️',
        'OP_RETOMADA': '🔁',
        'OP_PRODUZIDA': '🏁',
    };

    function mudarCategoriaNotificacao(categoria, botao) {
        categoriaNotifAtiva = categoria;
        document.querySelectorAll('.tab-categoria-notif').forEach(b => {
            b.classList.remove('text-blue-600', 'border-blue-600');
            b.classList.add('text-gray-400', 'border-transparent');
        });
        botao.classList.remove('text-gray-400', 'border-transparent');
        botao.classList.add('text-blue-600', 'border-blue-600');
        carregarNotificacoes();
    }

    function toggleSinoNotificacoes() {
        sinoAberto = !sinoAberto;
        document.getElementById('painel_notificacoes').classList.toggle('hidden', !sinoAberto);
        if (sinoAberto) carregarNotificacoes();
    }

    // Fecha o painel se clicar fora dele
    document.addEventListener('click', function (e) {
        const painel = document.getElementById('painel_notificacoes');
        const btn = document.getElementById('btn_sino_notificacoes');
        if (painel && !painel.classList.contains('hidden') && !painel.contains(e.target) && !btn.contains(e.target)) {
            painel.classList.add('hidden');
            sinoAberto = false;
        }
    });

    function tempoRelativoNotificacao(dataStr) {
        const data = new Date(dataStr.replace(' ', 'T'));
        const diffMin = Math.floor((new Date() - data) / 60000);
        if (diffMin < 1) return 'agora';
        if (diffMin < 60) return diffMin + 'min';
        const diffH = Math.floor(diffMin / 60);
        if (diffH < 24) return diffH + 'h';
        return Math.floor(diffH / 24) + 'd';
    }

    function atualizarBadgeNotificacoes(qtd) {
        const badge = document.getElementById('badge_notificacoes');
        if (!badge) return;
        if (qtd > 0) {
            badge.textContent = qtd > 99 ? '99+' : qtd;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    async function carregarNotificacoes() {
        const lista = document.getElementById('lista_notificacoes');

        // O badge do sino sempre reflete o TOTAL não lido, independente
        // da aba aberta -- senão trocar de aba faria o número "sumir"
        // mesmo com notificações não lidas em outra categoria.
        atualizarContadorTotalSino();

        // Bolinhas de cada aba -- só existe quando há abas (PCP).
        if (categoriaNotifAtiva !== null) atualizarPontosCategorias();

        try {
            const url = categoriaNotifAtiva
                ? `notificacoes_buscar.php?categoria=${categoriaNotifAtiva}`
                : 'notificacoes_buscar.php';
            const resp = await fetch(url);
            const dados = await resp.json();
            if (!dados.ok) throw new Error(dados.erro || 'Falha ao carregar');

            if (dados.itens.length === 0) {
                lista.innerHTML = '<div class="p-6 text-center text-xs text-gray-400 font-medium">Nenhuma notificação por aqui.</div>';
                return;
            }

            lista.innerHTML = dados.itens.map(n => {
                const podeAbrirOP = notifAbreNaOP && n.op_sistema;
                const opEscapada = podeAbrirOP ? String(n.op_sistema).replace(/'/g, "\\'") : '';
                const onclickAttr = podeAbrirOP
                    ? `abrirNotificacaoComOP(${n.id}, this, '${opEscapada}')`
                    : `marcarNotificacaoLida(${n.id}, this)`;
                return `
                <div onclick="${onclickAttr}" data-lida="${n.lida}" title="${podeAbrirOP ? 'Clique para ver a OP ' + n.op_sistema : ''}" class="px-4 py-3 flex gap-3 cursor-pointer hover:bg-gray-50 transition-colors ${n.lida == 0 ? 'bg-blue-50/50' : ''}">
                    <span class="text-lg shrink-0">${iconesPorTipoNotificacao[n.tipo_evento] || '🔔'}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-gray-700 leading-snug ${n.lida == 0 ? 'font-bold' : 'font-medium'}">${n.mensagem}</p>
                        <span class="text-[10px] text-gray-400 font-semibold">${tempoRelativoNotificacao(n.created_at)}</span>
                    </div>
                    ${n.lida == 0 ? '<span class="w-2 h-2 rounded-full bg-blue-500 shrink-0 mt-1"></span>' : ''}
                    ${podeAbrirOP ? '<svg class="w-3.5 h-3.5 text-gray-300 shrink-0 mt-1" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path></svg>' : ''}
                </div>
            `;
            }).join('');
        } catch (e) {
            lista.innerHTML = '<div class="p-6 text-center text-xs text-rose-500 font-bold">Erro ao carregar notificações.</div>';
        }
    }

    async function atualizarContadorTotalSino() {
        try {
            const resp = await fetch('notificacoes_buscar.php'); // sem categoria = total geral
            const dados = await resp.json();
            if (dados.ok) atualizarBadgeNotificacoes(dados.nao_lidas);
        } catch (e) { /* silencioso -- o badge só fica desatualizado até a próxima tentativa */ }
    }

    // Bolinha em cada aba (Separadas/Formuladas/Produzidas/Outras) --
    // pra dar pra ver de relance onde tem notificação nova sem precisar
    // clicar nas 4 uma por uma.
    async function atualizarPontosCategorias() {
        try {
            const resp = await fetch('notificacoes_contadores.php');
            const dados = await resp.json();
            if (!dados.ok) return;
            document.querySelectorAll('.tab-categoria-notif').forEach(botao => {
                const cat = botao.dataset.categoria;
                const ponto = botao.querySelector('.dot-categoria-notif');
                if (!ponto) return;
                ponto.classList.toggle('hidden', !(dados.contadores[cat] > 0));
            });
        } catch (e) { /* silencioso */ }
    }

    async function marcarNotificacaoLida(id, elemento) {
        if (elemento.dataset.lida == '1') return;
        try {
            await fetch('notificacoes_marcar_lida.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            carregarNotificacoes();
        } catch (e) { /* silencioso -- não é crítico */ }
    }

    // Clique numa notificação com OP vinculada (só PCP): marca como lida
    // e já leva pra Visão Global do PCP com o filtro dessa OP aplicado.
    async function abrirNotificacaoComOP(id, elemento, opSistema) {
        if (elemento.dataset.lida != '1') {
            try {
                await fetch('notificacoes_marcar_lida.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
            } catch (e) { /* segue o baile mesmo se isso falhar */ }
        }
        window.location.href = 'programacao_pcp.php?buscar_op=' + encodeURIComponent(opSistema);
    }

    async function marcarTodasLidas() {
        try {
            await fetch('notificacoes_marcar_lida.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ todas: true, categoria: categoriaNotifAtiva })
            });
            carregarNotificacoes();
        } catch (e) { /* silencioso */ }
    }

    // Revalida a cada 30s, sem precisar dar F5 pra ver notificação nova
    document.addEventListener('DOMContentLoaded', () => {
        setInterval(carregarNotificacoes, 30000);
    });
</script>
<?php endif; ?>