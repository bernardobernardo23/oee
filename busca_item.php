<?php
require 'conexao.php';

$codigo = $_GET['codigo'] ?? '';
$tipo_esperado = $_GET['tipo'] ?? ''; // Pode vir vazio agora

if ($codigo !== '') {
    $stmt = $pdo->prepare("SELECT id, descricao, tipo FROM itens_componentes WHERE codigo = ?");
    $stmt->execute([$codigo]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        // Se o operador escolheu um tipo no select, e ele for diferente do tipo real do banco, avisa do erro
        if ($tipo_esperado !== '' && $item['tipo'] !== $tipo_esperado) {
            echo json_encode(['erro' => 'tipo_invalido', 'tipo_real' => $item['tipo']]);
        } else {
            // Se bateu tudo certo (ou se o operador não escolheu tipo nenhum), devolve os dados
            echo json_encode([
                'id' => $item['id'], 
                'descricao' => $item['descricao'], 
                'tipo_real' => $item['tipo'] // Devolvemos o tipo real para o JS preencher o select
            ]);
        }
    } else {
        echo json_encode(['erro' => 'nao_encontrado']);
    }
}
?>