-- ============================================================
-- MIGRAÇÃO: status "PENDENCIA" de verdade em ordens_producao
-- ============================================================
-- Hoje, quando o Almoxarifado ou a Formulação registram uma
-- pendência (estoque insuficiente / matéria-prima insuficiente /
-- aguardando laboratório), isso só fica gravado nas tabelas filhas
-- (separacoes_almoxarifado / formulacoes) -- o status da OP em
-- ordens_producao NUNCA muda. Por isso o filtro "Pendência Material"
-- no PCP nunca mostra nada: nenhuma OP jamais tem esse status.
--
-- Essa migração:
-- 1. Adiciona 'PENDENCIA' como valor válido do enum de status.
-- 2. Adiciona a coluna `status_anterior`, que guarda o status que a
--    OP tinha ANTES de cair em pendência -- pra referência/auditoria
--    e pra dar transparência de onde ela estava no pipeline.
-- ============================================================

ALTER TABLE `ordens_producao`
  MODIFY `status` ENUM(
    'PROGRAMADO',
    'AGUARDANDO FORMULACAO',
    'AGUARDANDO ALMOXARIFADO',
    'AGUARDANDO INICIO',
    'PRODUCAO INICIADA',
    'PRODUCAO FINALIZADA',
    'PAUSADO',
    'CANCELADO',
    'PENDENCIA'
  ) DEFAULT 'PROGRAMADO';

ALTER TABLE `ordens_producao`
  ADD COLUMN `status_anterior` VARCHAR(30) DEFAULT NULL
    COMMENT 'Status que a OP tinha antes de cair em PENDENCIA -- limpo (NULL) quando a pendência é resolvida'
    AFTER `status`;
