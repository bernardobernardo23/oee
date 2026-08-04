-- ============================================================
-- SUPORTE A "TROCAR DE OP" (Caso 1: OP reprovada em produção)
-- ============================================================
-- Adiciona a apontamentos um jeito de fechar o turno SEM finalizar
-- de verdade (sem contar pro OEE, sem declarar produção/perdas) --
-- pra quando uma OP é reprovada no meio da produção e o operador
-- precisa trocar pra outra sem que isso vire uma parada nem um turno
-- "de verdade" nas médias.
--
-- Caso 2 (corte de expediente) NÃO precisa de nada disso -- já se
-- resolve só com a lógica condicional no acao_apontamento.php.
-- ============================================================

ALTER TABLE `apontamentos`
  ADD COLUMN `situacao` ENUM('NORMAL', 'INTERROMPIDO') NOT NULL DEFAULT 'NORMAL'
    COMMENT 'INTERROMPIDO = troca de OP no meio do turno, não conta pro OEE'
    AFTER `hora_fim`,
  ADD COLUMN `motivo_interrupcao` TEXT DEFAULT NULL
    COMMENT 'Observação obrigatória (min. 10 caracteres) de por que a OP foi trocada'
    AFTER `situacao`;