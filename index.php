<?php
session_start();
require 'conexao.php';

// O redirect de erro (mais abaixo) manda "?erro=..." na URL, mas isso
// nunca era lido de volta em $erro -- por isso o aviso nunca aparecia,
// mesmo com login/senha errados.
$erro = $_GET['erro'] ?? null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_digitado = trim($_POST['login']);
    $senha_digitada = $_POST['senha'];

    // 1. TENTA AUTENTICAR COMO USUÁRIO CORPORATIVO (PCP, Almox, Admin)
    $stmt_user = $pdo->prepare("SELECT * FROM usuarios WHERE login = ? AND status = 'ATIVO'");
    $stmt_user->execute([$login_digitado]);
    $usuario = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($senha_digitada, $usuario['senha'])) {
        // Usuário corporativo encontrado e senha correta!
        $_SESSION['tipo_acesso'] = 'usuario';
        $_SESSION['usuario_id']  = $usuario['id'];
        $_SESSION['nome']        = $usuario['nome_completo'];
        $_SESSION['setor']       = $usuario['setor'];

        // Redirecionamento Inteligente baseado no Setor
        if ($usuario['setor'] == 'PCP') {
            header("Location: programacao_pcp.php");
        } elseif ($usuario['setor'] == 'ALMOXARIFADO') {
            header("Location: separacao_almoxarifado.php");
        } elseif($usuario['setor'] == 'FORMULACAO'){
            header("Location: formulacao.php");
        }else {
            header("Location: dashboard_admin.php"); // Admin, Qualidade, Diretoria
        }
        exit;
    }

    // 2. SE NÃO ACHOU NOS USUÁRIOS, TENTA AUTENTICAR COMO LINHA DE PRODUÇÃO (Chão de Fábrica)
    // A senha na tabela "linhas" agora é armazenada com hash, então buscamos só pelo login
    // e validamos com password_verify (mesmo padrão usado para "usuarios").
    $stmt_linha = $pdo->prepare("SELECT * FROM linhas WHERE login = ?");
    $stmt_linha->execute([$login_digitado]);
    $linha = $stmt_linha->fetch(PDO::FETCH_ASSOC);

    if ($linha && password_verify($senha_digitada, $linha['senha'])) {
        // Máquina do Chão de Fábrica logada com sucesso!
        $_SESSION['tipo_acesso'] = 'linha';
        $_SESSION['linha_id']    = $linha['id'];
        $_SESSION['nome']        = $linha['login'];
        $_SESSION['fabrica']     = $linha['fabrica'];

        header("Location: apontamento.php");
        exit;
    }

    // Se chegou até aqui, errou o login ou a senha em ambos
    header("Location: index.php?erro=" . urlencode("Login ou senha incorretos."));
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OEE Chesiquimica</title>
    
    <link rel="icon" type="image/png" href="logo.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        /* Leve "balanço" no card inteiro quando o login falha -- reforça
           visualmente o erro além do texto, sem ser agressivo demais. */
        @keyframes shake-erro {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }
        .shake-erro {
            animation: shake-erro 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans flex items-center justify-center p-4">

    <div class="bg-white p-8 md:p-12 rounded-2xl shadow-xl w-full max-w-md border <?= $erro ? 'border-red-300 shake-erro' : 'border-gray-200' ?>">
        
        <div class="text-center mb-10">
            <img src="logo.png" alt="Chesiquimica" class="h-16 w-auto mx-auto object-contain mb-4">
            <h1 class="text-xs uppercase font-bold text-gray-500 tracking-widest">Acesso ao Sistema</h1>
            <h2 class="text-3xl font-black text-gray-900 tracking-tighter mt-1 uppercase">
                OEE
            </h2>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl mb-6 text-sm font-bold flex items-center gap-3">
                <svg class="w-6 h-6 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?= htmlspecialchars($erro) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-6">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Identificação da Linha</label>
                <input type="text" name="login" required placeholder="Ex: f1l1" autocomplete="off"
                    class="w-full px-4 py-3 border rounded-lg shadow-sm
                           focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400
                           transition duration-150 <?= $erro ? 'border-red-300 bg-red-50/30' : 'border-gray-300' ?>">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Senha</label>
                <input type="password" name="senha" required placeholder="••••••••"
                    class="w-full px-4 py-3 border rounded-lg shadow-sm
                           focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400
                           transition duration-150 <?= $erro ? 'border-red-300 bg-red-50/30' : 'border-gray-300' ?>">
            </div>

            <button type="submit"
                class="w-full bg-black hover:bg-blue-700 text-white font-bold py-3.5 rounded-lg 
                       shadow-md hover:shadow-lg transition duration-150 transform hover:scale-[1.02]">
                Entrar no Sistema
            </button>
        </form>

        <div class="mt-10 pt-6 border-t border-gray-200 text-center text-xs text-gray-400 font-medium">
            © <?= date('Y') ?> Bernardo de Lima da Silva - Chesiquimica
        </div>
    </div>

</body>
</html>