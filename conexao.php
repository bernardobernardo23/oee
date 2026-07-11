<?php

$host = 'localhost';
$dbname = 'oee'; 
$user = 'root'; 
$pass = '';
try {
    // Cria a conexão PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    
    // Configura o PDO para relatar erros de forma clara (modo de exceção)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    // Interrompe a execução e mostra o erro caso falhe
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}
?>