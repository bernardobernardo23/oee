-- ============================================================
-- LOG DETALHADO DE IMPORTAÇÃO DE CADASTROS (Produtos/Insumos)
-- ============================================================
-- Guarda permanentemente cada importação feita (quem, quando, arquivo,
-- resumo dos números) e o detalhe de CADA linha ignorada/inválida
-- (com o motivo exato), pra quem fez a importação conseguir entender
-- depois o que não entrou e por quê -- sem isso, só se sabia "2
-- produtos ignorados" sem saber quais nem o porquê.
-- ============================================================

CREATE TABLE IF NOT EXISTS `importacoes_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL COMMENT 'Quem fez a importação',
  `nome_arquivo` varchar(255) NOT NULL,
  `total_produtos_inseridos` int(11) NOT NULL DEFAULT 0,
  `total_componentes_inseridos` int(11) NOT NULL DEFAULT 0,
  `total_ignorados` int(11) NOT NULL DEFAULT 0 COMMENT 'Soma de duplicados + tipo não tratado + inválidos',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `fk_importlog_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `importacoes_log_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `importacao_id` int(11) NOT NULL,
  `linha_planilha` int(11) NOT NULL COMMENT 'Número da linha na planilha (considerando o cabeçalho, se houver)',
  `codigo` varchar(50) DEFAULT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `tipo_planilha` varchar(20) DEFAULT NULL,
  `categoria` enum('DUPLICADO_PRODUTO','DUPLICADO_COMPONENTE','TIPO_NAO_TRATADO','LINHA_INVALIDA') NOT NULL,
  `motivo` varchar(255) NOT NULL COMMENT 'Texto pronto explicando o motivo, pra exibir direto',
  PRIMARY KEY (`id`),
  KEY `idx_importacao` (`importacao_id`),
  CONSTRAINT `fk_importitens_log` FOREIGN KEY (`importacao_id`) REFERENCES `importacoes_log` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
