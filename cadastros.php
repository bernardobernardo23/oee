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
// 2. MOTOR DE INSERÇÃO (Processa os 4 formulários)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tipo_cadastro'])) {
    $tipo = $_POST['tipo_cadastro'];

    try {
        if ($tipo === 'produto') {
            $stmt = $pdo->prepare("INSERT INTO produtos (codigo, descricao) VALUES (?, ?)");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao'])]);
            $mensagem = "Produto cadastrado com sucesso!";
        } 
        
        elseif ($tipo === 'componente') {
            $stmt = $pdo->prepare("INSERT INTO itens_componentes (codigo, descricao, tipo) VALUES (?, ?, ?)");
            $stmt->execute([trim($_POST['codigo']), trim($_POST['descricao']), $_POST['tipo_item']]);
            $mensagem = "Componente cadastrado com sucesso!";
        } 
        
        elseif ($tipo === 'linha') {
            // REGRA: Linhas de produção usam login direto (sem hash) 
            // conforme nova arquitetura para facilitar o chão de fábrica
            $stmt = $pdo->prepare("INSERT INTO linhas (login, senha, nome, fabrica, capacidade_dia) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                strtolower(trim($_POST['login'])), // Força minúscula no login da linha
                trim($_POST['senha']), 
                strtoupper(trim($_POST['nome'])), // Força maiúscula no nome
                (int)$_POST['fabrica'], 
                (int)$_POST['capacidade_dia']
            ]);
            $mensagem = "Nova linha de produção cadastrada com sucesso!";
        } 
        
        elseif ($tipo === 'parada') {
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

    <!-- CABEÇALHO PADRÃO -->
    <?php include 'header.php'; ?>

    <div class="max-w-6xl mx-auto px-4 space-y-6 mt-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 mt-2 tracking-tight">Master Data (Cadastros)</h2>
                <p class="text-sm text-slate-500 font-medium">Faça a gestão dos catálogos de fábrica, produtos e paradas do sistema OEE.</p>
            </div>
            <!-- Botão de Voltar para Gestão -->
            <a href="dashboard_gerencial.php" class="bg-slate-800 hover:bg-black text-white font-bold py-2.5 px-5 rounded-lg text-sm transition-all shadow-sm flex items-center gap-2">
                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Voltar ao Painel
            </a>
        </div>

        <!-- ALERTAS -->
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <!-- ===================================================== -->
            <!-- 1. PRODUTOS ACABADOS                                  -->
            <!-- ===================================================== -->
            <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-blue-500"></div>
                <input type="hidden" name="tipo_cadastro" value="produto">
                
                <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                    <div class="bg-blue-100 p-2 rounded-lg text-blue-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    </div>
                    <h2 class="text-lg font-black text-slate-800">1. Produto Acabado (PA)</h2>
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

            <!-- ===================================================== -->
            <!-- 2. INSUMOS / COMPONENTES                              -->
            <!-- ===================================================== -->
            <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-orange-500"></div>
                <input type="hidden" name="tipo_cadastro" value="componente">
                
                <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                    <div class="bg-orange-100 p-2 rounded-lg text-orange-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                    <h2 class="text-lg font-black text-slate-800">2. Insumo / Componente</h2>
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

            <!-- ===================================================== -->
            <!-- 3. LINHAS DE PRODUÇÃO                                 -->
            <!-- ===================================================== -->
            <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-purple-500"></div>
                <input type="hidden" name="tipo_cadastro" value="linha">
                
                <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                    <div class="bg-purple-100 p-2 rounded-lg text-purple-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </div>
                    <h2 class="text-lg font-black text-slate-800">3. Linha / Máquina</h2>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Login da Máquina</label>
                            <input type="text" name="login" required placeholder="Ex: l1f1" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors lowercase">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Senha p/ Operador</label>
                            <input type="password" name="senha" required placeholder="***" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Nome Exibição</label>
                            <input type="text" name="nome" required placeholder="Ex: LINHA 1" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors uppercase">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Fábrica</label>
                            <input type="number" name="fabrica" required min="1" max="10" placeholder="Ex: 1" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-slate-50 focus:bg-white transition-colors text-center">
                        </div>
                    </div>
                    <div>
                        <!-- TRAVA DE NEGÓCIO: Capacidade_dia com min="1" -->
                        <label class="block text-xs font-bold text-slate-600 mb-1.5 uppercase tracking-wide">Capacidade Diária (Meta 100%)</label>
                        <input type="number" name="capacidade_dia" required min="1" placeholder="Ex: 42000" class="w-full px-4 py-2.5 border border-purple-200 rounded-xl text-sm focus:ring-2 focus:ring-purple-100 focus:border-purple-400 bg-purple-50 focus:bg-white transition-colors text-purple-700 font-bold">
                    </div>
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Criar Máquina</button>
                </div>
            </form>

            <!-- ===================================================== -->
            <!-- 4. MOTIVOS DE PARADA                                  -->
            <!-- ===================================================== -->
            <form method="POST" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200/60 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-rose-500"></div>
                <input type="hidden" name="tipo_cadastro" value="parada">
                
                <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-3">
                    <div class="bg-rose-100 p-2 rounded-lg text-rose-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h2 class="text-lg font-black text-slate-800">4. Motivo de Parada</h2>
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
                        <select name="responsabilidade" required class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-rose-100 focus:border-rose-400 bg-slate-50 focus:bg-white transition-colors appearance-none">
                            <option value="Geral">Produção / Chão de Fábrica</option>
                            <option value="Manutencao">Manutenção</option>
                            <option value="Qualidade">Qualidade</option>
                            <option value="Logistica">Almoxarifado / Logística</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold py-3 rounded-xl transition-all shadow-sm mt-2">Salvar Motivo</button>
                </div>
            </form>

        </div>
    </div>
</body>
</html>