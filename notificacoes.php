<?php
// ================================================================
// NOTIFICAÇÕES -- funções compartilhadas
// ================================================================
// Usado por: programacao_pcp.php, separacao_almoxarifado.php,
// formulacao.php (onde as ações acontecem) e pelos endpoints do
// sininho (notificacoes_buscar.php, notificacoes_marcar_lida.php).
// ================================================================

if (!function_exists('notificar_usuario')) {
    function notificar_usuario(PDO $pdo, int $usuario_id, ?int $op_id, string $tipo_evento, string $mensagem): void
    {
        $stmt = $pdo->prepare("INSERT INTO notificacoes (destino_tipo, destino_usuario_id, op_id, tipo_evento, mensagem) VALUES ('usuario', ?, ?, ?, ?)");
        $stmt->execute([$usuario_id, $op_id, $tipo_evento, $mensagem]);
    }
}

if (!function_exists('notificar_setor')) {
    function notificar_setor(PDO $pdo, string $setor, ?int $op_id, string $tipo_evento, string $mensagem): void
    {
        $stmt = $pdo->prepare("INSERT INTO notificacoes (destino_tipo, destino_setor, op_id, tipo_evento, mensagem) VALUES ('setor', ?, ?, ?, ?)");
        $stmt->execute([$setor, $op_id, $tipo_evento, $mensagem]);
    }
}

if (!function_exists('notificar_linha')) {
    function notificar_linha(PDO $pdo, int $linha_id, ?int $op_id, string $tipo_evento, string $mensagem): void
    {
        $stmt = $pdo->prepare("INSERT INTO notificacoes (destino_tipo, destino_linha_id, op_id, tipo_evento, mensagem) VALUES ('linha', ?, ?, ?, ?)");
        $stmt->execute([$linha_id, $op_id, $tipo_evento, $mensagem]);
    }
}

// ----------------------------------------------------------------
// CATEGORIAS -- usadas pelas abas do sino do PCP (Separadas /
// Formuladas / Produzidas / Outras). PCP é o único setor que recebe
// os 3 tipos de evento do ciclo inteiro da OP, por isso é o único
// que precisa dessa divisão; Almoxarifado e Formulação continuam
// vendo uma lista só.
// ----------------------------------------------------------------
if (!function_exists('tipos_evento_por_categoria')) {
    function tipos_evento_por_categoria(): array
    {
        return [
            'separadas'  => ['OP_SEPARADA'],
            'formuladas' => ['OP_FORMULADA'],
            // OP_PRODUCAO_INICIADA e OP_PRODUZIDA ainda não têm gatilho
            // plugado (dependem de acao_apontamento.php, que ainda não
            // foi integrado) -- a categoria já existe pronta pra quando
            // isso for ligado.
            'produzidas' => ['OP_PRODUCAO_INICIADA', 'OP_PAUSADA', 'OP_RETOMADA', 'OP_PRODUZIDA'],
        ];
    }
}

if (!function_exists('categoria_notificacao')) {
    function categoria_notificacao(string $tipo_evento): string
    {
        foreach (tipos_evento_por_categoria() as $categoria => $tipos) {
            if (in_array($tipo_evento, $tipos, true)) return $categoria;
        }
        return 'outras';
    }
}

// Busca as notificações relevantes pro contexto de sessão atual
// (usuário corporativo -> pessoais + as do seu setor; linha -> só as
// da própria linha). Devolve as N mais recentes e a contagem de não lidas.
// $categoria (opcional): filtra só pelos tipos daquela categoria
// ('separadas', 'formuladas', 'produzidas') ou 'outras' pra tudo que
// não se encaixa nas 3 acima. null/vazio = sem filtro (tudo).
if (!function_exists('buscar_notificacoes_sessao')) {
    function buscar_notificacoes_sessao(PDO $pdo, int $limite = 20, ?string $categoria = null): array
    {
        $tipo_acesso = $_SESSION['tipo_acesso'] ?? null;

        $filtro_categoria_sql = '';
        $tipos_filtro = [];
        if ($categoria) {
            $mapa = tipos_evento_por_categoria();
            if ($categoria === 'outras') {
                $todos_conhecidos = array_merge(...array_values($mapa));
                $placeholders = implode(',', array_fill(0, count($todos_conhecidos), '?'));
                $filtro_categoria_sql = " AND n.tipo_evento NOT IN ($placeholders)";
                $tipos_filtro = $todos_conhecidos;
            } elseif (isset($mapa[$categoria])) {
                $tipos_filtro = $mapa[$categoria];
                $placeholders = implode(',', array_fill(0, count($tipos_filtro), '?'));
                $filtro_categoria_sql = " AND n.tipo_evento IN ($placeholders)";
            }
        }

        if ($tipo_acesso === 'usuario') {
            $usuario_id = $_SESSION['usuario_id'] ?? 0;
            $setor = $_SESSION['setor'] ?? '';
            $stmt = $pdo->prepare("
                SELECT n.id, n.tipo_evento, n.mensagem, n.lida, n.created_at, n.op_id, op.op_sistema
                FROM notificacoes n
                LEFT JOIN ordens_producao op ON op.id = n.op_id
                WHERE ((n.destino_tipo = 'usuario' AND n.destino_usuario_id = ?)
                   OR (n.destino_tipo = 'setor' AND n.destino_setor = ?))
                   $filtro_categoria_sql
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
            $pos = 1;
            $stmt->bindValue($pos++, $usuario_id, PDO::PARAM_INT);
            $stmt->bindValue($pos++, $setor, PDO::PARAM_STR);
            foreach ($tipos_filtro as $t) $stmt->bindValue($pos++, $t, PDO::PARAM_STR);
            $stmt->bindValue($pos++, $limite, PDO::PARAM_INT);
            $stmt->execute();
        } elseif ($tipo_acesso === 'linha') {
            $linha_id = $_SESSION['linha_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT n.id, n.tipo_evento, n.mensagem, n.lida, n.created_at, n.op_id, op.op_sistema
                FROM notificacoes n
                LEFT JOIN ordens_producao op ON op.id = n.op_id
                WHERE n.destino_tipo = 'linha' AND n.destino_linha_id = ?
                   $filtro_categoria_sql
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
            $pos = 1;
            $stmt->bindValue($pos++, $linha_id, PDO::PARAM_INT);
            foreach ($tipos_filtro as $t) $stmt->bindValue($pos++, $t, PDO::PARAM_STR);
            $stmt->bindValue($pos++, $limite, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            return ['itens' => [], 'nao_lidas' => 0];
        }

        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nao_lidas = 0;
        foreach ($itens as $it) {
            if (!$it['lida']) $nao_lidas++;
        }

        return ['itens' => $itens, 'nao_lidas' => $nao_lidas];
    }
}

// Conta não lidas de UMA categoria específica -- usado pra mostrar a
// bolinha em cada aba (Separadas/Formuladas/Produzidas/Outras) sem
// precisar buscar a lista inteira de cada uma.
if (!function_exists('contar_nao_lidas_categoria')) {
    function contar_nao_lidas_categoria(PDO $pdo, string $categoria): int
    {
        $tipo_acesso = $_SESSION['tipo_acesso'] ?? null;
        if ($tipo_acesso !== 'usuario') return 0;

        $mapa = tipos_evento_por_categoria();
        if ($categoria === 'outras') {
            $tipos = array_merge(...array_values($mapa));
            $operador = 'NOT IN';
        } elseif (isset($mapa[$categoria])) {
            $tipos = $mapa[$categoria];
            $operador = 'IN';
        } else {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));

        $usuario_id = $_SESSION['usuario_id'] ?? 0;
        $setor = $_SESSION['setor'] ?? '';

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notificacoes n
            WHERE ((n.destino_tipo = 'usuario' AND n.destino_usuario_id = ?)
               OR (n.destino_tipo = 'setor' AND n.destino_setor = ?))
               AND n.lida = 0
               AND n.tipo_evento $operador ($placeholders)
        ");
        $pos = 1;
        $stmt->bindValue($pos++, $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue($pos++, $setor, PDO::PARAM_STR);
        foreach ($tipos as $t) $stmt->bindValue($pos++, $t, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }
}