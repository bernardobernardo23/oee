<?php
session_start();

// 1. Esvazia todas as variáveis da sessão atual
$_SESSION = array();

// 2. Destrói o cookie de sessão (garante que a sessão morre completamente no navegador)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destrói a sessão no servidor
session_destroy();

// 4. Redireciona de volta para a página inicial (Login)
header("Location: index.php");
exit;
?>