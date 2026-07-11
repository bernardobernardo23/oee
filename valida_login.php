<?php
// Inicia a sessão para podermos guardar os dados do usuário logado
session_start();

// Puxa o arquivo de conexão que criamos anteriormente
require_once 'conexao.php';

// Verifica se os dados realmente vieram do formulário
if (isset($_POST['login']) && isset($_POST['senha'])) {
    
    $login_digitado = trim($_POST['login']);
    $senha_digitada = trim($_POST['senha']);

    try {
        // Prepara a query para buscar a linha/usuário pelo login
        $stmt = $pdo->prepare("SELECT id, login, senha, fabrica FROM linhas WHERE login = :login");
        $stmt->bindParam(':login', $login_digitado, PDO::PARAM_STR);
        $stmt->execute();

        // Busca o registro no banco
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica se a linha existe E se a senha confere
        // Nota: password_verify exige que as senhas no banco tenham sido cadastradas com password_hash()
        if ($linha && password_verify($senha_digitada, $linha['senha'])) {
            
            // Login e senha corretos! Guarda os dados na sessão
            $_SESSION['linha_id'] = $linha['id'];
            $_SESSION['login']    = $linha['login'];
            $_SESSION['fabrica']  = $linha['fabrica'];
            
            // Validação de Role (Regra de Negócio)
            if ($linha['fabrica'] == 0) {
                // É o Admin/Gestão
                header("Location: dashboard_admin.php");
                exit;
            } else {
                // É uma Linha de Produção (Fábricas 1, 2, 3 ou 4)
                header("Location: apontamento.php");
                exit;
            }

        } else {
            // Senha errada ou usuário não existe, devolve pro index com erro
            header("Location: index.php?erro=1");
            exit;
        }

    } catch (PDOException $e) {
        // Em caso de erro no banco de dados, interrompe e mostra o erro
        die("Erro ao consultar o banco de dados: " . $e->getMessage());
    }

} else {
    // Se tentarem acessar esse arquivo direto pela URL, devolve pro index
    header("Location: index.php");
    exit;
}
?>