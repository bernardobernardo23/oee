-- ================================================================
-- MIGRAÇÃO: Sistema de Notificações
-- ================================================================
-- Suporta 3 tipos de destino, porque o sistema tem 2 tipos de login
-- diferentes (usuarios corporativos e linhas) e 2 formas de alcance
-- (pessoa específica vs setor inteiro):
--   - 'usuario' -> destino_usuario_id (ex: PCP só vê OPs que criou)
--   - 'setor'   -> destino_setor (ex: qualquer um do Almoxarifado vê)
--   - 'linha'   -> destino_linha_id (ex: o operador daquela máquina)
-- ================================================================

CREATE TABLE `notificacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destino_tipo` enum('usuario','setor','linha') NOT NULL,
  `destino_usuario_id` int(11) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = usuario',
  `destino_setor` varchar(50) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = setor',
  `destino_linha_id` int(11) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = linha',
  `op_id` int(11) DEFAULT NULL COMMENT 'OP relacionada (se houver) -- link direto pro contexto',
  `tipo_evento` varchar(50) NOT NULL COMMENT 'Ex: PENDENCIA_ALMOXARIFADO, PENDENCIA_FORMULACAO, OP_LIBERADA, OP_NOVA, OP_CANCELADA, OP_REPROGRAMADA',
  `mensagem` text NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_destino_usuario` (`destino_usuario_id`, `lida`),
  KEY `idx_destino_setor` (`destino_setor`, `lida`),
  KEY `idx_destino_linha` (`destino_linha_id`, `lida`),
  KEY `fk_notif_op` (`op_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `notificacoes`
  ADD CONSTRAINT `fk_notif_usuario` FOREIGN KEY (`destino_usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_linha` FOREIGN KEY (`destino_linha_id`) REFERENCES `linhas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_op` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE;
