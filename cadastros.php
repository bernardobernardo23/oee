<?php
session_start();
require 'conexao.php';

// ========================================================================
// 1. SEGURANÇA E CONTROLE DE ACESSO
// ========================================================================
// Apenas usuários corporativos com perfil ADMIN podem aceder ao Master Data
if (!isset($_SESSION['tipo_acesso']) || $_SESSION['tipo_acesso'] !== 'usuario' || $_SESSION['setor'] !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo_msg = '';

// ========================================================================
// 2. MOTOR DE INSERÇÃO
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tipo_cadastro'])) {
    $tipo = $_POST['tipo_cadastro'];

    try {
        if ($tipo === 'produto') {
            $stmt = $pdo->prepare("INSERT INTO produtos (codigo, descricao) VALUES (?, ?)");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao'])]);
            $mensagem = "Produto cadastrado com sucesso!";
        } elseif ($tipo === 'componente') {
            $stmt = $pdo->prepare("INSERT INTO itens_componentes (codigo, descricao, tipo) VALUES (?, ?, ?)");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao']), $_POST['tipo_item']]);
            $mensagem = "Componente cadastrado com sucesso!";
        } elseif ($tipo === 'linha') {
            // REGRA: Linhas de produção usam login direto (sem hash),
            // conforme a arquitetura atual do login do chão de fábrica.
            // A tabela `linhas` não tem coluna de nome de exibição --
            // o sistema inteiro já usa strtoupper(login) pra isso, então
            // não pedimos "nome" aqui (evita quebrar o INSERT).
            $stmt = $pdo->prepare("INSERT INTO linhas (login, senha, fabrica, capacidade_dia) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                strtolower(trim($_POST['login'])),
                trim($_POST['senha']),
                (int)$_POST['fabrica'],
                (int)$_POST['capacidade_dia']
            ]);
            $mensagem = "Nova linha de produção cadastrada com sucesso!";
        } elseif ($tipo === 'usuario') {
            // Usuários corporativos usam senha com hash (diferente das
            // linhas, que usam login direto).
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome_completo, login, senha, setor) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['nome_completo']),
                strtolower(trim($_POST['login'])),
                password_hash(trim($_POST['senha']), PASSWORD_DEFAULT),
                $_POST['setor']
            ]);
            $mensagem = "Novo usuário cadastrado com sucesso!";
        } elseif ($tipo === 'parada') {
            $stmt = $pdo->prepare("INSERT INTO motivos_parada (codigo, descricao, tipo, responsabilidade) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['codigo']),
                trim($_POST['descricao']),
                $_POST['tipo_parada'],
                trim($_POST['responsabilidade'])
            ]);
            $mensagem = "Novo motivo de parada cadastrado com sucesso!";
        }

        $tipo_msg = 'sucesso';
    } catch (PDOException $e) {
        $tipo_msg = 'erro';
        // Erro 23000 = Tentativa de inserir um dado que quebra a regra UNIQUE no banco
        if ($e->getCode() == 23000) {
            $mensagem = "Erro: Já existe um registo com este código ou login no sistema.";
        } else {
            $mensagem = "Erro no servidor: " . $e->getMessage();
        }
    }
}

// ========================================================================
// 3. LISTAGENS (visibilidade do que já está cadastrado)
// ========================================================================
try {
    $linhas_lista = $pdo->query("SELECT id, login, fabrica, capacidade_dia, created_at FROM linhas ORDER BY fabrica ASC, login ASC")->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_lista = $pdo->query("SELECT id, nome_completo, login, setor, status, criado_em FROM usuarios ORDER BY setor ASC, nome_completo ASC")->fetchAll(PDO::FETCH_ASSOC);
    $paradas_lista = $pdo->query("SELECT id, codigo, descricao, tipo, responsabilidade FROM motivos_parada ORDER BY codigo ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar listagens: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data - MES/OEE</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Montserrat', 'sans-serif'], } } } }
    </script>
</head>
<body class="bg-slate-50 min-h-screen font-sans pb-12 text-slate-800">

    <?php include 'header.php'; ?>

    <div class="max-w-6xl mx-auto px-4 space-y-6 mt-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Master Data (Cadastros)</h2>
                <p class="text-sm text-slate-500 font-medium">Faça a gestão dos catálogos de fábrica, produtos e paradas do sistema OEE.</p>
            </div>
            <a href="dashboard_gerencial.php" class="bg-slate-800 hover:bg-black text-white font-bold py-2.5 px-5 rounded-lg text-sm transition-all shadow-sm flex items-center gap-2">
                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Voltar ao Painel
            </a>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-xl shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?php if ($tipo_msg == 'sucesso'): ?>
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <?php else: ?>
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?php endif; ?>
                <?= $mensagem ?>
            </div>
        <?php endif; ?>

        <!-- ABAS -->
        <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
            <button type="button" onclick="mudarAbaCadastro('linhas')" id="tab_linhas" class="tab-cadastro px-5 py-2.5 rounded-lg text-sm font-bold transition-colors bg-slate-800 text-white">Linhas</button>
            <button type="button" onclick="mudarAbaCadastro('usuarios')" id="tab_usuarios" class="tab-cadastro px-5 py-2.5 rounded-lg text-sm font-bold transition-colors bg-white text-slate-500 border border-slate-200 hover:bg-slate-100">Usuários</button>
            <button type="button" onclick="mudarAbaCadastro('paradas')" id="tab_paradas" class="tab-cadastro px-5 py-2.5 rounded-lg text-sm font-bold transition-colors bg-white text-slate-500 border border-slate-200 hover:bg-slate-100">Motivos de Parada</button>
            <button type="button" onclick="mudarAbaCadastro('produtos')" id="tab_produtos" class="tab-cadastro px-5 py-2.5 rounded-lg text-sm font-bold transition-colors bg-white text-slate-500 border border-slate-200 hover:bg-slate-100">Produtos &amp; Insumos</button>
        </div>

        <!-- ===================================================== -->
        <!-- ABA: LINHAS                                           -->
        <!-- ===================================================== -->
        <div id="aba_linhas" class="aba-cadastro space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden lg:col-span-1">
                    <div class="absolute top-0 left-0 w-full h-1 bg-purple-500"></div>
                    <input type="hidden" name="tipo_cadastro" value="linha">

                    <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                        <div class="bg-purple-100 p-2 rounded-lg text-purple-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                        <h2 class="text-lg font-black text-slate-800">Nova Linha / Máquina</h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Login da Máquina</label>
                            <input type="text" name="login" required placeholder="Ex: l1f1" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors lowercase">
                            <p class="text-[10px] text-slate-400 mt-1">O login vira o nome de exibição da linha no sistema todo (ex: L1F1).</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Senha p/ Operador</label>
                            <input type="password" name="senha" required placeholder="***" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Fábrica</label>
                                <input type="number" name="fabrica" required min="1" max="10" placeholder="Ex: 1" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors text-center">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Capacidade/Dia</label>
                                <input type="number" name="capacidade_dia" required min="1" placeholder="Ex: 42000" class="w-full px-4 py-2.5 border border-purple-200 rounded-xl text-sm focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-purple-50 focus:bg-white transition-colors text-purple-700 font-bold">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Criar Máquina</button>
                    </div>
                </form>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden lg:col-span-2">
                    <div class="p-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-sm font-bold text-slate-700">Linhas Cadastradas (<?= count($linhas_lista) ?>)</h3>
                    </div>
                    <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase tracking-wider font-bold sticky top-0">
                                <tr>
                                    <th class="p-3">Login</th>
                                    <th class="p-3 text-center">Fábrica</th>
                                    <th class="p-3 text-right">Capacidade/Dia</th>
                                    <th class="p-3">Criada em</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($linhas_lista)): ?>
                                    <tr><td colspan="4" class="p-6 text-center text-slate-400">Nenhuma linha cadastrada ainda.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($linhas_lista as $l): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800 uppercase"><?= htmlspecialchars($l['login']) ?></td>
                                        <td class="p-3 text-center font-semibold text-purple-700">F<?= $l['fabrica'] ?></td>
                                        <td class="p-3 text-right font-semibold text-slate-600"><?= number_format($l['capacidade_dia'], 0, ',', '.') ?> un</td>
                                        <td class="p-3 text-slate-400 text-xs"><?= date('d/m/Y', strtotime($l['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================== -->
        <!-- ABA: USUÁRIOS                                         -->
        <!-- ===================================================== -->
        <div id="aba_usuarios" class="aba-cadastro space-y-6 hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden lg:col-span-1">
                    <div class="absolute top-0 left-0 w-full h-1 bg-teal-500"></div>
                    <input type="hidden" name="tipo_cadastro" value="usuario">

                    <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                        <div class="bg-teal-100 p-2 rounded-lg text-teal-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                        <h2 class="text-lg font-black text-slate-800">Novo Usuário Corporativo</h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Nome Completo</label>
                            <input type="text" name="nome_completo" required placeholder="Ex: Maria Souza" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-100 focus:border-teal-400 bg-slate-50 focus:bg-white transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Login</label>
                            <input type="text" name="login" required placeholder="Ex: maria.souza" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-100 focus:border-teal-400 bg-slate-50 focus:bg-white transition-colors lowercase">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Senha</label>
                            <input type="password" name="senha" required placeholder="***" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-100 focus:border-teal-400 bg-slate-50 focus:bg-white transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Setor</label>
                            <select name="setor" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-100 focus:border-teal-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                <option value="PCP">PCP</option>
                                <option value="ALMOXARIFADO">Almoxarifado</option>
                                <option value="FORMULACAO">Formulação</option>
                                <option value="QUALIDADE">Qualidade</option>
                                <option value="DIRETORIA">Diretoria</option>
                                <option value="ADMIN">Admin</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Criar Usuário</button>
                    </div>
                </form>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden lg:col-span-2">
                    <div class="p-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-sm font-bold text-slate-700">Usuários Cadastrados (<?= count($usuarios_lista) ?>)</h3>
                    </div>
                    <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase tracking-wider font-bold sticky top-0">
                                <tr>
                                    <th class="p-3">Nome</th>
                                    <th class="p-3">Login</th>
                                    <th class="p-3">Setor</th>
                                    <th class="p-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($usuarios_lista)): ?>
                                    <tr><td colspan="4" class="p-6 text-center text-slate-400">Nenhum usuário cadastrado ainda.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($usuarios_lista as $u): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800"><?= htmlspecialchars($u['nome_completo']) ?></td>
                                        <td class="p-3 text-slate-600"><?= htmlspecialchars($u['login']) ?></td>
                                        <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-teal-100 text-teal-700"><?= htmlspecialchars($u['setor']) ?></span></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $u['status'] === 'ATIVO' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500' ?>"><?= htmlspecialchars($u['status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================== -->
        <!-- ABA: MOTIVOS DE PARADA                                -->
        <!-- ===================================================== -->
        <div id="aba_paradas" class="aba-cadastro space-y-6 hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden lg:col-span-1">
                    <div class="absolute top-0 left-0 w-full h-1 bg-rose-500"></div>
                    <input type="hidden" name="tipo_cadastro" value="parada">

                    <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                        <div class="bg-rose-100 p-2 rounded-lg text-rose-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <h2 class="text-lg font-black text-slate-800">Novo Motivo de Parada</h2>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Código Painel</label>
                                <input type="text" name="codigo" required placeholder="Ex: P01" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Impacto no OEE</label>
                                <select name="tipo_parada" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                    <option value="Nao_Planejada">🛑 Queda (Não Planejada)</option>
                                    <option value="Planejada">☕ Pausa (Planejada)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Descrição do Motivo</label>
                            <input type="text" name="descricao" required placeholder="Ex: FALHA NO ENVASE" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Responsabilidade / Setor</label>
                            <input type="text" name="responsabilidade" required placeholder="Ex: MANUTENÇÃO, PRODUÇÃO, QUALIDADE..." list="lista_responsabilidades" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                            <datalist id="lista_responsabilidades">
                                <?php foreach (array_unique(array_column($paradas_lista, 'responsabilidade')) as $resp): ?>
                                    <option value="<?= htmlspecialchars($resp) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <p class="text-[10px] text-slate-400 mt-1">Campo livre -- sugestões aparecem com base no que já existe.</p>
                        </div>
                        <button type="submit" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Salvar Motivo</button>
                    </div>
                </form>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden lg:col-span-2">
                    <div class="p-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-sm font-bold text-slate-700">Motivos Cadastrados (<?= count($paradas_lista) ?>)</h3>
                    </div>
                    <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase tracking-wider font-bold sticky top-0">
                                <tr>
                                    <th class="p-3">Código</th>
                                    <th class="p-3">Descrição</th>
                                    <th class="p-3 text-center">Impacto</th>
                                    <th class="p-3">Responsabilidade</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($paradas_lista)): ?>
                                    <tr><td colspan="4" class="p-6 text-center text-slate-400">Nenhum motivo cadastrado ainda.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($paradas_lista as $p): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800"><?= htmlspecialchars($p['codigo']) ?></td>
                                        <td class="p-3 text-slate-600"><?= htmlspecialchars($p['descricao']) ?></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $p['tipo'] === 'Planejada' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700' ?>"><?= $p['tipo'] === 'Planejada' ? 'Planejada' : 'Não Planej.' ?></span>
                                        </td>
                                        <td class="p-3 text-slate-500 text-xs uppercase"><?= htmlspecialchars($p['responsabilidade']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================== -->
        <!-- ABA: PRODUTOS & INSUMOS (inalterada por enquanto)     -->
        <!-- ===================================================== -->
        <div id="aba_produtos" class="aba-cadastro space-y-6 hidden">
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex items-start gap-2">
                <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <p class="text-xs text-amber-800 font-medium">Esta aba ainda não recebeu o cadastro único + importação por planilha (igual às OPs). Fica pra próxima etapa.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-full h-1 bg-blue-500"></div>
                    <input type="hidden" name="tipo_cadastro" value="produto">

                    <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                        <div class="bg-blue-100 p-2 rounded-lg text-blue-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        </div>
                        <h2 class="text-lg font-black text-slate-800">Produto Acabado (PA)</h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Código ERP (SKU)</label>
                            <input type="text" name="codigo" required placeholder="Ex: 5860" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Descrição Completa</label>
                            <input type="text" name="descricao" required placeholder="Ex: TINTA UG BEIGE 210G" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Salvar Produto</button>
                    </div>
                </form>

                <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-full h-1 bg-orange-500"></div>
                    <input type="hidden" name="tipo_cadastro" value="componente">

                    <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                        <div class="bg-orange-100 p-2 rounded-lg text-orange-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        </div>
                        <h2 class="text-lg font-black text-slate-800">Insumo / Componente</h2>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Código ERP</label>
                                <input type="text" name="codigo" required placeholder="Ex: 746" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-slate-50 focus:bg-white transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Classificação</label>
                                <select name="tipo_item" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                    <option value="Lata">Lata</option>
                                    <option value="Tampa">Tampa / Atuador</option>
                                    <option value="Valvula">Válvula</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Descrição do Material</label>
                            <input type="text" name="descricao" required placeholder="Ex: LATA AE 57X166" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                        </div>
                        <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Salvar Insumo</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script>
        function mudarAbaCadastro(abaId) {
            document.querySelectorAll('.aba-cadastro').forEach(a => a.classList.add('hidden'));
            document.querySelectorAll('.tab-cadastro').forEach(b => {
                b.classList.remove('bg-slate-800', 'text-white');
                b.classList.add('bg-white', 'text-slate-500', 'border', 'border-slate-200');
            });

            document.getElementById('aba_' + abaId).classList.remove('hidden');
            const btn = document.getElementById('tab_' + abaId);
            btn.classList.remove('bg-white', 'text-slate-500', 'border', 'border-slate-200');
            btn.classList.add('bg-slate-800', 'text-white');
        }
    </script>
</body>
</html>