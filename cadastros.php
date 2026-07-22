<?php
session_start();
require 'conexao.php';
require_once 'notificacoes.php';

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

// Recupera mensagem de flash vinda de um redirecionamento (ex: após
// a importação por planilha em importa_cadastros.php) e limpa da sessão.
if (isset($_SESSION['flash_mensagem'])) {
    $mensagem = $_SESSION['flash_mensagem'];
    $tipo_msg = $_SESSION['flash_tipo'] ?? '';
    unset($_SESSION['flash_mensagem'], $_SESSION['flash_tipo']);
}

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
// 2B. MOTOR DE EDIÇÃO E EXCLUSÃO (CRUD completo dos 5 cadastros)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao = $_POST['acao'];
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($acao === 'editar_linha') {
            if (!empty($_POST['senha'])) {
                $stmt = $pdo->prepare("UPDATE linhas SET login = ?, senha = ?, fabrica = ?, capacidade_dia = ? WHERE id = ?");
                $stmt->execute([strtolower(trim($_POST['login'])), trim($_POST['senha']), (int)$_POST['fabrica'], (int)$_POST['capacidade_dia'], $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE linhas SET login = ?, fabrica = ?, capacidade_dia = ? WHERE id = ?");
                $stmt->execute([strtolower(trim($_POST['login'])), (int)$_POST['fabrica'], (int)$_POST['capacidade_dia'], $id]);
            }
            $mensagem = "Linha atualizada com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'excluir_linha') {
            $pdo->prepare("DELETE FROM linhas WHERE id = ?")->execute([$id]);
            $mensagem = "Linha excluída com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'editar_usuario') {
            if (!empty($_POST['senha'])) {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome_completo = ?, login = ?, senha = ?, setor = ?, status = ? WHERE id = ?");
                $stmt->execute([trim($_POST['nome_completo']), strtolower(trim($_POST['login'])), password_hash(trim($_POST['senha']), PASSWORD_DEFAULT), $_POST['setor'], $_POST['status'], $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome_completo = ?, login = ?, setor = ?, status = ? WHERE id = ?");
                $stmt->execute([trim($_POST['nome_completo']), strtolower(trim($_POST['login'])), $_POST['setor'], $_POST['status'], $id]);
            }
            $mensagem = "Usuário atualizado com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'excluir_usuario') {
            $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
            $mensagem = "Usuário excluído com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'editar_parada') {
            $stmt = $pdo->prepare("UPDATE motivos_parada SET codigo = ?, descricao = ?, tipo = ?, responsabilidade = ? WHERE id = ?");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao']), $_POST['tipo_parada'], trim($_POST['responsabilidade']), $id]);
            $mensagem = "Motivo de parada atualizado com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'excluir_parada') {
            $pdo->prepare("DELETE FROM motivos_parada WHERE id = ?")->execute([$id]);
            $mensagem = "Motivo de parada excluído com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'editar_produto') {
            $stmt = $pdo->prepare("UPDATE produtos SET codigo = ?, descricao = ? WHERE id = ?");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao']), $id]);
            $mensagem = "Produto atualizado com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'excluir_produto') {
            $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);
            $mensagem = "Produto excluído com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'editar_componente') {
            $stmt = $pdo->prepare("UPDATE itens_componentes SET codigo = ?, descricao = ?, tipo = ? WHERE id = ?");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao']), $_POST['tipo_item'], $id]);
            $mensagem = "Componente atualizado com sucesso!";
            $tipo_msg = 'sucesso';
        } elseif ($acao === 'excluir_componente') {
            $pdo->prepare("DELETE FROM itens_componentes WHERE id = ?")->execute([$id]);
            $mensagem = "Componente excluído com sucesso!";
            $tipo_msg = 'sucesso';
        }
    } catch (PDOException $e) {
        $tipo_msg = 'erro';
        // 1451 = violação de FK ao tentar excluir algo que já está em uso
        // em outra tabela (ex: produto usado numa OP). 1062 = duplicidade.
        $codigo_erro = $e->errorInfo[1] ?? null;
        if ($codigo_erro == 1451) {
            $mensagem = "Erro: este registro já está em uso em outra parte do sistema (OPs, apontamentos, etc.) e não pode ser excluído.";
        } elseif ($codigo_erro == 1062 || $e->getCode() == 23000) {
            $mensagem = "Erro: já existe um registro com este código ou login no sistema.";
        } else {
            $mensagem = "Erro no servidor: " . $e->getMessage();
        }
    }
}

// ========================================================================
// 3. LISTAGENS
// ========================================================================
// Linhas, Usuários e Motivos de Parada continuam listados por completo
// (na prática nunca passam de algumas dezenas de registros).
// Produtos e Insumos NÃO carregam lista nenhuma no load da página --
// depois de importar a planilha geral da empresa, isso pode chegar a
// milhares de linhas. Em vez de paginar, a buscaé por AJAX
// (busca_cadastro.php): só aparece na tela o que o usuário procurar.
try {
    $linhas_lista = $pdo->query("SELECT id, login, fabrica, capacidade_dia, created_at FROM linhas ORDER BY fabrica ASC, login ASC")->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_lista = $pdo->query("SELECT id, nome_completo, login, setor, status, criado_em FROM usuarios ORDER BY setor ASC, nome_completo ASC")->fetchAll(PDO::FETCH_ASSOC);
    $paradas_lista = $pdo->query("SELECT id, codigo, descricao, tipo, responsabilidade FROM motivos_parada ORDER BY codigo ASC")->fetchAll(PDO::FETCH_ASSOC);

    $total_produtos = (int)$pdo->query("SELECT COUNT(*) FROM produtos")->fetchColumn();
    $total_componentes = (int)$pdo->query("SELECT COUNT(*) FROM itens_componentes")->fetchColumn();
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
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    }
                }
            }
        }
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
                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Voltar ao Painel
            </a>
        </div>

        <?php if ($mensagem): ?>
            <div class="px-4 py-3 rounded-xl shadow-sm text-sm font-semibold flex items-center gap-2 border <?= $tipo_msg == 'sucesso' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                <?php if ($tipo_msg == 'sucesso'): ?>
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                <?php else: ?>
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
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
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
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
                                    <th class="p-3 text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($linhas_lista)): ?>
                                    <tr>
                                        <td colspan="5" class="p-6 text-center text-slate-400">Nenhuma linha cadastrada ainda.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($linhas_lista as $l): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800 uppercase"><?= htmlspecialchars($l['login']) ?></td>
                                        <td class="p-3 text-center font-semibold text-purple-700">F<?= $l['fabrica'] ?></td>
                                        <td class="p-3 text-right font-semibold text-slate-600"><?= number_format($l['capacidade_dia'], 0, ',', '.') ?> un</td>
                                        <td class="p-3 text-slate-400 text-xs"><?= date('d/m/Y', strtotime($l['created_at'])) ?></td>
                                        <td class="p-3">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button" onclick="document.getElementById('modal_ed_linha_<?= $l['id'] ?>').showModal()" title="Editar" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg></button>
                                                <button type="button" onclick="document.getElementById('modal_ex_linha_<?= $l['id'] ?>').showModal()" title="Excluir" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg></button>
                                            </div>
                                        </td>
                                    </tr>

                                    <dialog id="modal_ed_linha_<?= $l['id'] ?>" class="p-0 rounded-[1.5rem] shadow-2xl border border-slate-100 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="p-6 pb-5 flex justify-between items-start">
                                            <div class="flex items-center gap-4">
                                                <div class="w-12 h-12 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="text-[15px] font-black text-slate-800 uppercase tracking-wide leading-tight">Editar Linha</h3>
                                                    <p class="text-sm font-bold text-slate-400 mt-0.5"><?= strtoupper(htmlspecialchars($l['login'])) ?></p>
                                                </div>
                                            </div>
                                            <button type="button" onclick="this.closest('dialog').close()" class="w-9 h-9 border-2 border-slate-800 rounded-[10px] flex items-center justify-center text-slate-700 hover:bg-slate-100 transition-colors shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <form method="POST" class="px-6 pb-6 space-y-5">
                                            <input type="hidden" name="acao" value="editar_linha">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <div>
                                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Login</label>
                                                <input type="text" name="login" required value="<?= htmlspecialchars($l['login']) ?>" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-purple-400 focus:ring-1 focus:ring-purple-400 outline-none transition-all lowercase">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Nova Senha (Opcional)</label>
                                                <input type="password" name="senha" placeholder="Deixe em branco para manter" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-purple-400 focus:ring-1 focus:ring-purple-400 outline-none transition-all">
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Fábrica</label>
                                                    <input type="number" name="fabrica" required min="1" value="<?= $l['fabrica'] ?>" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-purple-400 focus:ring-1 focus:ring-purple-400 outline-none transition-all text-center">
                                                </div>
                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Capacidade/Dia</label>
                                                    <input type="number" name="capacidade_dia" required min="1" value="<?= $l['capacidade_dia'] ?>" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-purple-700 bg-white focus:border-purple-400 focus:ring-1 focus:ring-purple-400 outline-none transition-all">
                                                </div>
                                            </div>
                                            <div class="flex gap-3 pt-2">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 font-bold py-3.5 rounded-xl transition-colors text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-3.5 rounded-xl transition-colors shadow-md text-sm">Salvar</button>
                                            </div>
                                        </form>
                                    </dialog>

                                    <dialog id="modal_ex_linha_<?= $l['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="p-6 text-center">
                                            <h3 class="text-base font-bold text-slate-800 mb-1.5">Excluir esta linha?</h3>
                                            <p class="text-xs font-medium text-slate-500 mb-6">A linha <strong><?= htmlspecialchars(strtoupper($l['login'])) ?></strong> será removida. Se ela já tiver apontamentos registrados, a exclusão será bloqueada.</p>
                                            <form method="POST" class="flex gap-3">
                                                <input type="hidden" name="acao" value="excluir_linha">
                                                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Excluir</button>
                                            </form>
                                        </div>
                                    </dialog>
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
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
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
                            <div class="relative">
                                <select name="setor" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-100 focus:border-teal-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                    <option value="PCP">PCP</option>
                                    <option value="ALMOXARIFADO">Almoxarifado</option>
                                    <option value="FORMULACAO">Formulação</option>
                                    <option value="QUALIDADE">Qualidade</option>
                                    <option value="DIRETORIA">Diretoria</option>
                                    <option value="ADMIN">Admin</option>
                                </select>
                                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
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
                                    <th class="p-3 text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($usuarios_lista)): ?>
                                    <tr>
                                        <td colspan="5" class="p-6 text-center text-slate-400">Nenhum usuário cadastrado ainda.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($usuarios_lista as $u): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800"><?= htmlspecialchars($u['nome_completo']) ?></td>
                                        <td class="p-3 text-slate-600"><?= htmlspecialchars($u['login']) ?></td>
                                        <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-teal-100 text-teal-700"><?= htmlspecialchars($u['setor']) ?></span></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $u['status'] === 'ATIVO' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500' ?>"><?= htmlspecialchars($u['status']) ?></span>
                                        </td>
                                        <td class="p-3">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button" onclick="document.getElementById('modal_ed_usuario_<?= $u['id'] ?>').showModal()" title="Editar" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg></button>
                                                <button type="button" onclick="document.getElementById('modal_ex_usuario_<?= $u['id'] ?>').showModal()" title="Excluir" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg></button>
                                            </div>
                                        </td>
                                    </tr>

                                    <dialog id="modal_ed_usuario_<?= $u['id'] ?>" class="p-0 rounded-[1.5rem] shadow-2xl border border-slate-100 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="p-6 pb-5 flex justify-between items-start">
                                            <div class="flex items-center gap-4">
                                                <div class="w-12 h-12 rounded-full bg-teal-50 text-teal-600 flex items-center justify-center shrink-0">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="text-[15px] font-black text-slate-800 uppercase tracking-wide leading-tight">Editar Usuário</h3>
                                                    <p class="text-sm font-bold text-slate-400 mt-0.5"><?= htmlspecialchars($u['nome_completo']) ?></p>
                                                </div>
                                            </div>
                                            <button type="button" onclick="this.closest('dialog').close()" class="w-9 h-9 border-2 border-slate-800 rounded-[10px] flex items-center justify-center text-slate-700 hover:bg-slate-100 transition-colors shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <form method="POST" class="px-6 pb-6 space-y-5">
                                            <input type="hidden" name="acao" value="editar_usuario">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <div>
                                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Nome Completo</label>
                                                <input type="text" name="nome_completo" required value="<?= htmlspecialchars($u['nome_completo']) ?>" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none transition-all">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Login</label>
                                                <input type="text" name="login" required value="<?= htmlspecialchars($u['login']) ?>" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none transition-all lowercase">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Nova Senha (Opcional)</label>
                                                <input type="password" name="senha" placeholder="Deixe em branco para manter" class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none transition-all">
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Setor</label>
                                                    <div class="relative">
                                                        <select name="setor" required class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none transition-all appearance-none cursor-pointer">
                                                            <?php foreach (['PCP', 'ALMOXARIFADO', 'FORMULACAO', 'QUALIDADE', 'DIRETORIA', 'ADMIN'] as $s): ?>
                                                                <option value="<?= $s ?>" <?= $u['setor'] === $s ? 'selected' : '' ?>><?= ucfirst(strtolower($s)) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <svg class="w-4 h-4 text-slate-400 absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-widest mb-2">Status</label>
                                                    <div class="relative">
                                                        <select name="status" required class="w-full px-4 py-3.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 bg-white focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none transition-all appearance-none cursor-pointer">
                                                            <option value="ATIVO" <?= $u['status'] === 'ATIVO' ? 'selected' : '' ?>>Ativo</option>
                                                            <option value="INATIVO" <?= $u['status'] === 'INATIVO' ? 'selected' : '' ?>>Inativo</option>
                                                        </select>
                                                        <svg class="w-4 h-4 text-slate-400 absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex gap-3 pt-2">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 font-bold py-3.5 rounded-xl transition-colors text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-3.5 rounded-xl transition-colors shadow-md text-sm">Salvar</button>
                                            </div>
                                        </form>
                                    </dialog>

                                    <dialog id="modal_ex_usuario_<?= $u['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="p-6 text-center">
                                            <h3 class="text-base font-bold text-slate-800 mb-1.5">Excluir este usuário?</h3>
                                            <p class="text-xs font-medium text-slate-500 mb-6"><strong><?= htmlspecialchars($u['nome_completo']) ?></strong> será removido. Se ele já tiver ações registradas no sistema (separações, formulações, OPs criadas), a exclusão será bloqueada -- nesse caso, considere só marcar como Inativo.</p>
                                            <form method="POST" class="flex gap-3">
                                                <input type="hidden" name="acao" value="excluir_usuario">
                                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Excluir</button>
                                            </form>
                                        </div>
                                    </dialog>
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
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
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
                                <div class="relative">
                                    <select name="tipo_parada" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                        <option value="Nao_Planejada">🛑 Queda (Não Planejada)</option>
                                        <option value="Planejada">☕ Pausa (Planejada)</option>
                                    </select>
                                    <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
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
                                    <th class="p-3 text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($paradas_lista)): ?>
                                    <tr>
                                        <td colspan="5" class="p-6 text-center text-slate-400">Nenhum motivo cadastrado ainda.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($paradas_lista as $p): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-bold text-slate-800"><?= htmlspecialchars($p['codigo']) ?></td>
                                        <td class="p-3 text-slate-600"><?= htmlspecialchars($p['descricao']) ?></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $p['tipo'] === 'Planejada' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700' ?>"><?= $p['tipo'] === 'Planejada' ? 'Planejada' : 'Não Planej.' ?></span>
                                        </td>
                                        <td class="p-3 text-slate-500 text-xs uppercase"><?= htmlspecialchars($p['responsabilidade']) ?></td>
                                        <td class="p-3">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button" onclick="document.getElementById('modal_ed_parada_<?= $p['id'] ?>').showModal()" title="Editar" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg></button>
                                                <button type="button" onclick="document.getElementById('modal_ex_parada_<?= $p['id'] ?>').showModal()" title="Excluir" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg></button>
                                            </div>
                                        </td>
                                    </tr>

                                    <dialog id="modal_ed_parada_<?= $p['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Editar Motivo</h3>
                                                    <p class="text-xs font-medium text-slate-400"><?= htmlspecialchars($p['codigo']) ?></p>
                                                </div>
                                            </div>
                                            <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 hover:bg-rose-50 rounded-lg p-1.5 transition-colors">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <form method="POST" class="p-6 space-y-4">
                                            <input type="hidden" name="acao" value="editar_parada">
                                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Código</label>
                                                    <input type="text" name="codigo" required value="<?= htmlspecialchars($p['codigo']) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Impacto</label>
                                                    <div class="relative">
                                                        <select name="tipo_parada" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white appearance-none">
                                                            <option value="Nao_Planejada" <?= $p['tipo'] === 'Nao_Planejada' ? 'selected' : '' ?>>🛑 Queda</option>
                                                            <option value="Planejada" <?= $p['tipo'] === 'Planejada' ? 'selected' : '' ?>>☕ Pausa</option>
                                                        </select>
                                                        <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Descrição</label>
                                                <input type="text" name="descricao" required value="<?= htmlspecialchars($p['descricao']) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white uppercase">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Responsabilidade</label>
                                                <input type="text" name="responsabilidade" required value="<?= htmlspecialchars($p['responsabilidade']) ?>" list="lista_responsabilidades" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white uppercase">
                                            </div>
                                            <div class="flex gap-3 pt-2">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-2.5 rounded-lg text-sm">Salvar</button>
                                            </div>
                                        </form>
                                    </dialog>

                                    <dialog id="modal_ex_parada_<?= $p['id'] ?>" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                                        <div class="p-6 text-center">
                                            <h3 class="text-base font-bold text-slate-800 mb-1.5">Excluir este motivo?</h3>
                                            <p class="text-xs font-medium text-slate-500 mb-6"><strong><?= htmlspecialchars($p['descricao']) ?></strong> será removido. Se ele já tiver sido usado em algum apontamento, a exclusão será bloqueada.</p>
                                            <form method="POST" class="flex gap-3">
                                                <input type="hidden" name="acao" value="excluir_parada">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                                                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Excluir</button>
                                            </form>
                                        </div>
                                    </dialog>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================== -->
        <!-- ABA: PRODUTOS & INSUMOS                               -->
        <!-- ===================================================== -->
        <div id="aba_produtos" class="aba-cadastro space-y-6 hidden">

            <!-- IMPORTAÇÃO INTELIGENTE POR PLANILHA -->
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200/60">
                <div class="flex items-center gap-3 mb-1">
                    <div class="bg-emerald-100 p-2 rounded-lg text-emerald-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                    </div>
                    <h2 class="text-lg font-black text-slate-800">Importar Planilha Geral</h2>
                </div>
                <p class="text-xs text-slate-500 font-medium mb-4 ml-11">
                    Aceita a planilha completa da empresa (com todos os tipos de item misturados). O sistema lê a coluna <strong>Tipo</strong>: linhas <strong>PA</strong> viram Produtos Acabados, linhas <strong>EM</strong> viram Insumos/Componentes (o subtipo -- Lata, Tampa, Válvula etc -- é identificado pela descrição). Qualquer outro Tipo é ignorado. Códigos que já existem no sistema são pulados automaticamente.
                </p>
                <form action="importa_cadastros.php" method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-3 ml-11">
                    <input type="file" name="arquivo_excel" id="input_excel_cadastros" accept=".xlsx" required class="hidden">
                    <label for="input_excel_cadastros" id="label_excel_cadastros" class="flex-1 border-2 border-dashed border-slate-300 hover:border-emerald-400 bg-slate-50 rounded-xl cursor-pointer flex items-center justify-center gap-2 p-4 transition-colors min-h-[52px]">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span id="nome_excel_cadastros" class="text-sm font-bold text-slate-600">Clique para selecionar a planilha (.XLSX)</span>
                    </label>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-6 rounded-xl shadow-sm transition-colors shrink-0">Importar</button>
                </form>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- ============================ -->
                <!-- PRODUTO ACABADO              -->
                <!-- ============================ -->
                <div class="space-y-6">
                    <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-full h-1 bg-blue-500"></div>
                        <input type="hidden" name="tipo_cadastro" value="produto">

                        <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                            <div class="bg-blue-100 p-2 rounded-lg text-blue-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
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

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden">
                        <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                            <h3 class="text-sm font-bold text-slate-700 mb-3">Buscar Produto (<?= number_format($total_produtos, 0, ',', '.') ?> cadastrados)</h3>
                            <div class="relative">
                                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <input type="text" id="busca_produto" oninput="buscarCadastro('produto')" placeholder="Digite o código ou a descrição..." class="w-full pl-9 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-white transition-colors">
                            </div>
                        </div>
                        <div id="resultados_produto" class="divide-y divide-slate-100 max-h-[420px] overflow-y-auto">
                            <div class="p-6 text-center text-xs text-slate-400 font-medium">Digite pelo menos 2 caracteres pra buscar.</div>
                        </div>
                    </div>
                </div>

                <!-- MODAL ÚNICA DE EDITAR PRODUTO (reaproveitada pra qualquer resultado da busca) -->
                <dialog id="modal_editar_produto" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                    <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Editar Produto</h3>
                                <p class="text-xs font-medium text-slate-400" id="editar_produto_subtitulo">&nbsp;</p>
                            </div>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 hover:bg-rose-50 rounded-lg p-1.5 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="acao" value="editar_produto">
                        <input type="hidden" name="id" id="editar_produto_id">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Código</label>
                            <input type="text" name="codigo" id="editar_produto_codigo" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Descrição</label>
                            <input type="text" name="descricao" id="editar_produto_descricao" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white uppercase">
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                            <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-2.5 rounded-lg text-sm">Salvar</button>
                        </div>
                    </form>
                </dialog>

                <!-- MODAL ÚNICA DE EXCLUIR PRODUTO -->
                <dialog id="modal_excluir_produto" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                    <div class="p-6 text-center">
                        <h3 class="text-base font-bold text-slate-800 mb-1.5">Excluir este produto?</h3>
                        <p class="text-xs font-medium text-slate-500 mb-6"><strong id="excluir_produto_nome"></strong> será removido. Se ele já tiver sido usado em alguma OP, a exclusão será bloqueada.</p>
                        <form method="POST" class="flex gap-3">
                            <input type="hidden" name="acao" value="excluir_produto">
                            <input type="hidden" name="id" id="excluir_produto_id">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                            <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Excluir</button>
                        </form>
                    </div>
                </dialog>

                <!-- ============================ -->
                <!-- INSUMO / COMPONENTE          -->
                <!-- ============================ -->
                <div class="space-y-6">
                    <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-full h-1 bg-orange-500"></div>
                        <input type="hidden" name="tipo_cadastro" value="componente">

                        <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                            <div class="bg-orange-100 p-2 rounded-lg text-orange-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
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
                                    <div class="relative">
                                        <select name="tipo_item" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                                            <option value="Lata">Lata</option>
                                            <option value="Tampa">Tampa</option>
                                            <option value="Valvula">Válvula</option>
                                            <option value="Atuador">Atuador</option>
                                            <option value="Bolinha">Bolinha</option>
                                            <option value="Caixa">Caixa</option>
                                            <option value="Granel">Granel</option>
                                            <option value="Outros">Outros</option>
                                        </select>
                                        <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Descrição do Material</label>
                                <input type="text" name="descricao" required placeholder="Ex: LATA AE 57X166" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                            </div>
                            <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Salvar Insumo</button>
                        </div>
                    </form>

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden">
                        <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                            <h3 class="text-sm font-bold text-slate-700 mb-3">Buscar Insumo (<?= number_format($total_componentes, 0, ',', '.') ?> cadastrados)</h3>
                            <div class="relative">
                                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <input type="text" id="busca_componente" oninput="buscarCadastro('componente')" placeholder="Digite o código ou a descrição..." class="w-full pl-9 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-orange-100 focus:border-orange-400 bg-white transition-colors">
                            </div>
                        </div>
                        <div id="resultados_componente" class="divide-y divide-slate-100 max-h-[420px] overflow-y-auto">
                            <div class="p-6 text-center text-xs text-slate-400 font-medium">Digite pelo menos 2 caracteres pra buscar.</div>
                        </div>
                    </div>
                </div>

                <!-- MODAL ÚNICA DE EDITAR INSUMO -->
                <dialog id="modal_editar_componente" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-md bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                    <div class="bg-slate-50 border-b border-slate-100 p-5 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Editar Insumo</h3>
                                <p class="text-xs font-medium text-slate-400" id="editar_componente_subtitulo">&nbsp;</p>
                            </div>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-rose-500 hover:bg-rose-50 rounded-lg p-1.5 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="acao" value="editar_componente">
                        <input type="hidden" name="id" id="editar_componente_id">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Código</label>
                                <input type="text" name="codigo" id="editar_componente_codigo" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Tipo</label>
                                <div class="relative">
                                    <select name="tipo_item" id="editar_componente_tipo" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white appearance-none">
                                        <?php foreach (['Lata', 'Tampa', 'Valvula', 'Atuador', 'Bolinha', 'Caixa', 'Granel', 'Outros'] as $t): ?>
                                            <option value="<?= $t ?>"><?= $t ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase">Descrição</label>
                            <input type="text" name="descricao" id="editar_componente_descricao" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold bg-slate-50 focus:bg-white uppercase">
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                            <button type="submit" class="flex-1 bg-slate-800 hover:bg-black text-white font-bold py-2.5 rounded-lg text-sm">Salvar</button>
                        </div>
                    </form>
                </dialog>

                <!-- MODAL ÚNICA DE EXCLUIR INSUMO -->
                <dialog id="modal_excluir_componente" class="p-0 rounded-2xl shadow-2xl border border-slate-200 w-[95%] max-w-sm bg-white backdrop:bg-slate-900/60 m-auto overflow-hidden">
                    <div class="p-6 text-center">
                        <h3 class="text-base font-bold text-slate-800 mb-1.5">Excluir este insumo?</h3>
                        <p class="text-xs font-medium text-slate-500 mb-6"><strong id="excluir_componente_nome"></strong> será removido. Se ele já tiver sido usado em algum apontamento de perda, a exclusão será bloqueada.</p>
                        <form method="POST" class="flex gap-3">
                            <input type="hidden" name="acao" value="excluir_componente">
                            <input type="hidden" name="id" id="excluir_componente_id">
                            <button type="button" onclick="this.closest('dialog').close()" class="flex-1 border border-slate-300 text-slate-600 font-bold py-2.5 rounded-lg text-sm">Voltar</button>
                            <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 rounded-lg text-sm">Sim, Excluir</button>
                        </form>
                    </div>
                </dialog>
            </div>
        </div>

    </div>

    <script>
        const inputExcelCadastros = document.getElementById('input_excel_cadastros');
        if (inputExcelCadastros) {
            inputExcelCadastros.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    document.getElementById('nome_excel_cadastros').textContent = this.files[0].name;
                    document.getElementById('label_excel_cadastros').classList.remove('border-dashed', 'border-slate-300');
                    document.getElementById('label_excel_cadastros').classList.add('border-solid', 'border-emerald-400', 'bg-emerald-50');
                }
            });
        }

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

        // ==========================================
        // BUSCA DE PRODUTOS/INSUMOS (sem carregar lista completa --
        // só mostra o que o usuário efetivamente procurar)
        // ==========================================
        let timeoutBusca = null;

        function buscarCadastro(tipo) {
            clearTimeout(timeoutBusca);
            timeoutBusca = setTimeout(() => executarBuscaCadastro(tipo), 300); // debounce -- não dispara 1 fetch por tecla
        }

        async function executarBuscaCadastro(tipo) {
            const termo = document.getElementById('busca_' + tipo).value.trim();
            const container = document.getElementById('resultados_' + tipo);

            if (termo.length < 2) {
                container.innerHTML = '<div class="p-6 text-center text-xs text-slate-400 font-medium">Digite pelo menos 2 caracteres pra buscar.</div>';
                return;
            }

            container.innerHTML = '<div class="p-6 text-center text-xs text-blue-500 font-medium">Buscando...</div>';

            try {
                const resp = await fetch(`busca_cadastro.php?tipo=${tipo}&termo=${encodeURIComponent(termo)}`);
                const dados = await resp.json();

                if (!dados.ok) throw new Error(dados.erro || 'Falha na busca');

                if (dados.itens.length === 0) {
                    container.innerHTML = '<div class="p-6 text-center text-xs text-rose-500 font-bold">Nenhum resultado encontrado.</div>';
                    return;
                }

                container.innerHTML = dados.itens.map(item => {
                    const nomeEscapado = item.descricao.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    if (tipo === 'produto') {
                        return `
                            <div class="p-3 flex items-center justify-between gap-3 hover:bg-slate-50">
                                <div class="min-w-0">
                                    <span class="text-xs font-black text-slate-500">[${item.codigo}]</span>
                                    <span class="text-sm text-slate-700 font-semibold ml-1">${item.descricao}</span>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button type="button" onclick="abrirEditarProduto(${item.id}, '${item.codigo}', '${nomeEscapado}')" title="Editar" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>
                                    <button type="button" onclick="abrirExcluirProduto(${item.id}, '${nomeEscapado}')" title="Excluir" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </div>
                            </div>`;
                    } else {
                        return `
                            <div class="p-3 flex items-center justify-between gap-3 hover:bg-slate-50">
                                <div class="min-w-0 flex items-center gap-2">
                                    <span class="text-xs font-black text-slate-500">[${item.codigo}]</span>
                                    <span class="text-sm text-slate-700 font-semibold">${item.descricao}</span>
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-orange-100 text-orange-700 shrink-0">${item.tipo}</span>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button type="button" onclick="abrirEditarComponente(${item.id}, '${item.codigo}', '${nomeEscapado}', '${item.tipo}')" title="Editar" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>
                                    <button type="button" onclick="abrirExcluirComponente(${item.id}, '${nomeEscapado}')" title="Excluir" class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-300 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </div>
                            </div>`;
                    }
                }).join('');
            } catch (e) {
                container.innerHTML = '<div class="p-6 text-center text-xs text-rose-500 font-bold">Erro ao buscar. Tente de novo.</div>';
            }
        }

        function abrirEditarProduto(id, codigo, descricao) {
            document.getElementById('editar_produto_id').value = id;
            document.getElementById('editar_produto_codigo').value = codigo;
            document.getElementById('editar_produto_descricao').value = descricao;
            document.getElementById('editar_produto_subtitulo').textContent = 'Cód. ' + codigo;
            document.getElementById('modal_editar_produto').showModal();
        }

        function abrirExcluirProduto(id, descricao) {
            document.getElementById('excluir_produto_id').value = id;
            document.getElementById('excluir_produto_nome').textContent = descricao;
            document.getElementById('modal_excluir_produto').showModal();
        }

        function abrirEditarComponente(id, codigo, descricao, tipo) {
            document.getElementById('editar_componente_id').value = id;
            document.getElementById('editar_componente_codigo').value = codigo;
            document.getElementById('editar_componente_descricao').value = descricao;
            document.getElementById('editar_componente_tipo').value = tipo;
            document.getElementById('editar_componente_subtitulo').textContent = 'Cód. ' + codigo;
            document.getElementById('modal_editar_componente').showModal();
        }

        function abrirExcluirComponente(id, descricao) {
            document.getElementById('excluir_componente_id').value = id;
            document.getElementById('excluir_componente_nome').textContent = descricao;
            document.getElementById('modal_excluir_componente').showModal();
        }
    </script>
</body>

</html>