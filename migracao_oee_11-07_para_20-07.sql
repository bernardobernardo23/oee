-- ============================================================
-- MIGRAÇÃO: banco `oee` (versão 11/07/2026 -> versão 20/07/2026)
-- Baseado no diff real entre oee (2).sql e oee (3).sql.
-- Testado para MariaDB 10.4.32.
--
-- Faça backup antes de rodar (mysqldump).
-- ============================================================

START TRANSACTION;

-- ============================================================
-- 1) TABELA `usuarios`
--    Adiciona o setor FORMULACAO ao ENUM.
-- ============================================================
ALTER TABLE `usuarios`
  MODIFY COLUMN `setor` enum(
    'ADMIN',
    'PCP',
    'ALMOXARIFADO',
    'FORMULACAO',
    'QUALIDADE',
    'DIRETORIA'
  ) NOT NULL;


-- ============================================================
-- 2) TABELA `ordens_producao`
-- ============================================================

-- 2.1) Novas colunas do estágio de formulação
ALTER TABLE `ordens_producao`
  ADD COLUMN IF NOT EXISTS `data_formulacao` DATETIME DEFAULT NULL AFTER `data_separacao`,
  ADD COLUMN IF NOT EXISTS `formulador_id` INT(11) DEFAULT NULL AFTER `separador_id`,
  ADD COLUMN IF NOT EXISTS `auxiliares_formulacao` VARCHAR(255) DEFAULT NULL AFTER `auxiliares_separacao`,
  ADD COLUMN IF NOT EXISTS `observacao_formulacao` TEXT DEFAULT NULL AFTER `observacao_almoxarifado`;

-- 2.2) O ENUM de status vai perder o valor 'PENDENCIA'.
--      Antes de alterar o ENUM, qualquer OP que ainda esteja com
--      status = 'PENDENCIA' precisa ser migrada para um dos dois
--      novos status específicos, senão o MariaDB zera o valor
--      (ou dá erro em modo estrito).
--
--      Como no schema antigo só existia a etapa de separação
--      (a tabela `formulacoes` não existia ainda), o PENDENCIA
--      generico só podia ter vindo de um bloqueio de estoque na
--      separação -- por isso o mapeamento default abaixo manda
--      tudo para AGUARDANDO ALMOXARIFADO.
--      -> Se souber que algum caso específico era pendência de
--         formulação, ajuste o WHERE antes de rodar.
UPDATE `ordens_producao`
SET `status` = 'AGUARDANDO ALMOXARIFADO'
WHERE `status` = 'PENDENCIA';

ALTER TABLE `ordens_producao`
  MODIFY COLUMN `status` enum(
    'PROGRAMADO',
    'AGUARDANDO FORMULACAO',
    'AGUARDANDO ALMOXARIFADO',
    'AGUARDANDO INICIO',
    'PRODUCAO INICIADA',
    'PRODUCAO FINALIZADA',
    'PAUSADO',
    'CANCELADO'
  ) DEFAULT 'PROGRAMADO';


-- ============================================================
-- 3) TABELA `formulacoes` (nova)
-- ============================================================
CREATE TABLE IF NOT EXISTS `formulacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `op_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL COMMENT 'Quem registrou a tentativa',
  `status` enum('FORMULADO','PENDENCIA') NOT NULL,
  `motivo_pendencia` enum('MATERIA_PRIMA_INSUFICIENTE','AGUARDANDO_LABORATORIO') DEFAULT NULL COMMENT 'Preenchido apenas quando status = PENDENCIA',
  `auxiliares_formulacao` varchar(255) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_form_op` (`op_id`),
  KEY `fk_form_usuario` (`usuario_id`),
  CONSTRAINT `fk_form_op` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


COMMIT;

-- ============================================================
-- Fim da migração.
-- Nenhuma outra tabela mudou entre as duas versões:
-- apontamentos, apontamento_paradas, apontamento_perdas,
-- apontamento_producao, itens_componentes, linhas,
-- motivos_parada, op_insumos, op_produtos, produtos,
-- separacoes_almoxarifado
-- ============================================================
