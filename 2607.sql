-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 27/07/2026 às 00:09
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `oee`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `apontamentos`
--

CREATE TABLE `apontamentos` (
  `id` int(11) NOT NULL,
  `linha_id` int(11) NOT NULL,
  `ordem_producao` varchar(50) NOT NULL,
  `nome_operador` varchar(100) NOT NULL,
  `equipe_auxiliares` text DEFAULT NULL COMMENT 'Nomes separados por vírgula',
  `data_registro` date NOT NULL,
  `hora_inicio` datetime DEFAULT NULL,
  `hora_fim` datetime DEFAULT NULL,
  `parada_inicio` datetime DEFAULT NULL,
  `motivo_parada_ativa_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Soft Delete para auditoria gerencial',
  `oee_disponibilidade` decimal(5,2) DEFAULT NULL,
  `oee_performance` decimal(5,2) DEFAULT NULL,
  `oee_qualidade` decimal(5,2) DEFAULT NULL,
  `oee_geral` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `apontamento_paradas`
--

CREATE TABLE `apontamento_paradas` (
  `id` int(11) NOT NULL,
  `apontamento_id` int(11) NOT NULL,
  `motivo_id` int(11) NOT NULL,
  `minutos_parados` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `apontamento_perdas`
--

CREATE TABLE `apontamento_perdas` (
  `id` int(11) NOT NULL,
  `apontamento_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL COMMENT 'ID da tabela itens_componentes',
  `quantidade` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `apontamento_producao`
--

CREATE TABLE `apontamento_producao` (
  `id` int(11) NOT NULL,
  `apontamento_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `producao_boas` int(11) NOT NULL DEFAULT 0,
  `producao_refugo` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `formulacoes`
--

CREATE TABLE `formulacoes` (
  `id` int(11) NOT NULL,
  `op_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL COMMENT 'Quem registrou a tentativa',
  `status` enum('FORMULADO','PENDENCIA') NOT NULL,
  `motivo_pendencia` enum('MATERIA_PRIMA_INSUFICIENTE','AGUARDANDO_LABORATORIO') DEFAULT NULL COMMENT 'Preenchido apenas quando status = PENDENCIA',
  `auxiliares_formulacao` varchar(255) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `itens_componentes`
--

CREATE TABLE `itens_componentes` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `descricao` varchar(150) NOT NULL,
  `tipo` enum('Lata','Tampa','Valvula','Atuador','Bolinha','Caixa','Granel','Outros') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `linhas`
--

CREATE TABLE `linhas` (
  `id` int(11) NOT NULL,
  `login` varchar(50) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `fabrica` int(11) NOT NULL COMMENT '0 = Admin, 1 a 4 = Fábricas',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `capacidade_dia` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `motivos_parada`
--

CREATE TABLE `motivos_parada` (
  `id` int(11) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `descricao` varchar(150) NOT NULL,
  `tipo` enum('Planejada','Nao_Planejada') NOT NULL COMMENT 'OEE: Planejada reduz Tempo Disponível, Não_Planejada reduz Disponibilidade',
  `responsabilidade` varchar(50) NOT NULL DEFAULT 'Geral'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `notificacoes`
--

CREATE TABLE `notificacoes` (
  `id` int(11) NOT NULL,
  `destino_tipo` enum('usuario','setor','linha') NOT NULL,
  `destino_usuario_id` int(11) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = usuario',
  `destino_setor` varchar(50) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = setor',
  `destino_linha_id` int(11) DEFAULT NULL COMMENT 'Preenchido só quando destino_tipo = linha',
  `op_id` int(11) DEFAULT NULL COMMENT 'OP relacionada (se houver) -- link direto pro contexto',
  `tipo_evento` varchar(50) NOT NULL COMMENT 'Ex: PENDENCIA_ALMOXARIFADO, PENDENCIA_FORMULACAO, OP_LIBERADA, OP_NOVA, OP_CANCELADA, OP_REPROGRAMADA',
  `mensagem` text NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `op_insumos`
--

CREATE TABLE `op_insumos` (
  `id` int(11) NOT NULL,
  `op_produto_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantidade_necessaria` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `op_produtos`
--

CREATE TABLE `op_produtos` (
  `id` int(11) NOT NULL,
  `op_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade_planejada` int(11) NOT NULL,
  `quantidade_apontada` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `ordens_producao`
--

CREATE TABLE `ordens_producao` (
  `id` int(11) NOT NULL,
  `op_sistema` varchar(50) NOT NULL,
  `linha_id` int(11) DEFAULT NULL,
  `criador_id` int(11) DEFAULT NULL,
  `data_planejada` date NOT NULL,
  `data_separacao` datetime DEFAULT NULL,
  `data_formulacao` datetime DEFAULT NULL,
  `status` enum('PROGRAMADO','AGUARDANDO FORMULACAO','AGUARDANDO ALMOXARIFADO','AGUARDANDO INICIO','PRODUCAO INICIADA','PRODUCAO FINALIZADA','PAUSADO','CANCELADO') DEFAULT 'PROGRAMADO',
  `ordem_fila` int(11) DEFAULT NULL,
  `nome_separador` varchar(100) DEFAULT NULL,
  `separador_id` int(11) DEFAULT NULL,
  `formulador_id` int(11) DEFAULT NULL,
  `auxiliares_separacao` varchar(255) DEFAULT NULL,
  `auxiliares_formulacao` varchar(255) DEFAULT NULL,
  `observacao_almoxarifado` text DEFAULT NULL,
  `observacao_formulacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_criado` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `descricao` varchar(150) NOT NULL,
  `peso_granel_unidade` decimal(6,4) DEFAULT 0.0000 COMMENT 'Ex: 0.1210 para 121g por lata'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `separacoes_almoxarifado`
--

CREATE TABLE `separacoes_almoxarifado` (
  `id` int(11) NOT NULL,
  `op_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL COMMENT 'Quem registrou a tentativa',
  `status` enum('SEPARADO','ESTOQUE_INSUFICIENTE') NOT NULL,
  `auxiliares_separacao` varchar(255) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome_completo` varchar(100) NOT NULL,
  `login` varchar(50) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `setor` enum('ADMIN','PCP','ALMOXARIFADO','FORMULACAO','QUALIDADE','DIRETORIA') NOT NULL,
  `status` enum('ATIVO','INATIVO') DEFAULT 'ATIVO',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `apontamentos`
--
ALTER TABLE `apontamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_apontamentos_linha` (`linha_id`);

--
-- Índices de tabela `apontamento_paradas`
--
ALTER TABLE `apontamento_paradas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_paradas_apontamento` (`apontamento_id`),
  ADD KEY `fk_paradas_motivo` (`motivo_id`);

--
-- Índices de tabela `apontamento_perdas`
--
ALTER TABLE `apontamento_perdas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_perdas_apontamento` (`apontamento_id`),
  ADD KEY `fk_perdas_item` (`item_id`);

--
-- Índices de tabela `apontamento_producao`
--
ALTER TABLE `apontamento_producao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prod_apont` (`apontamento_id`),
  ADD KEY `fk_prod_prod` (`produto_id`);

--
-- Índices de tabela `formulacoes`
--
ALTER TABLE `formulacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_form_op` (`op_id`),
  ADD KEY `fk_form_usuario` (`usuario_id`);

--
-- Índices de tabela `itens_componentes`
--
ALTER TABLE `itens_componentes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Índices de tabela `linhas`
--
ALTER TABLE `linhas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`);

--
-- Índices de tabela `motivos_parada`
--
ALTER TABLE `motivos_parada`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `notificacoes`
--
ALTER TABLE `notificacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_destino_usuario` (`destino_usuario_id`,`lida`),
  ADD KEY `idx_destino_setor` (`destino_setor`,`lida`),
  ADD KEY `idx_destino_linha` (`destino_linha_id`,`lida`),
  ADD KEY `fk_notif_op` (`op_id`);

--
-- Índices de tabela `op_insumos`
--
ALTER TABLE `op_insumos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `op_produto_id` (`op_produto_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Índices de tabela `op_produtos`
--
ALTER TABLE `op_produtos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `op_id` (`op_id`),
  ADD KEY `produto_id` (`produto_id`);

--
-- Índices de tabela `ordens_producao`
--
ALTER TABLE `ordens_producao`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `op_sistema` (`op_sistema`),
  ADD UNIQUE KEY `op_sistema_2` (`op_sistema`),
  ADD KEY `idx_linha_ordem_fila` (`linha_id`,`ordem_fila`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Índices de tabela `separacoes_almoxarifado`
--
ALTER TABLE `separacoes_almoxarifado`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sep_op` (`op_id`),
  ADD KEY `fk_sep_usuario` (`usuario_id`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login_unico` (`login`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `apontamentos`
--
ALTER TABLE `apontamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `apontamento_paradas`
--
ALTER TABLE `apontamento_paradas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `apontamento_perdas`
--
ALTER TABLE `apontamento_perdas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `apontamento_producao`
--
ALTER TABLE `apontamento_producao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `formulacoes`
--
ALTER TABLE `formulacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `itens_componentes`
--
ALTER TABLE `itens_componentes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `linhas`
--
ALTER TABLE `linhas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `motivos_parada`
--
ALTER TABLE `motivos_parada`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notificacoes`
--
ALTER TABLE `notificacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `op_insumos`
--
ALTER TABLE `op_insumos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `op_produtos`
--
ALTER TABLE `op_produtos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `ordens_producao`
--
ALTER TABLE `ordens_producao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `separacoes_almoxarifado`
--
ALTER TABLE `separacoes_almoxarifado`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `apontamentos`
--
ALTER TABLE `apontamentos`
  ADD CONSTRAINT `fk_apontamentos_linha` FOREIGN KEY (`linha_id`) REFERENCES `linhas` (`id`);

--
-- Restrições para tabelas `apontamento_paradas`
--
ALTER TABLE `apontamento_paradas`
  ADD CONSTRAINT `fk_paradas_apontamento` FOREIGN KEY (`apontamento_id`) REFERENCES `apontamentos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_paradas_motivo` FOREIGN KEY (`motivo_id`) REFERENCES `motivos_parada` (`id`);

--
-- Restrições para tabelas `apontamento_perdas`
--
ALTER TABLE `apontamento_perdas`
  ADD CONSTRAINT `fk_perdas_apontamento` FOREIGN KEY (`apontamento_id`) REFERENCES `apontamentos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_perdas_item` FOREIGN KEY (`item_id`) REFERENCES `itens_componentes` (`id`);

--
-- Restrições para tabelas `apontamento_producao`
--
ALTER TABLE `apontamento_producao`
  ADD CONSTRAINT `fk_prod_apont` FOREIGN KEY (`apontamento_id`) REFERENCES `apontamentos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prod_prod` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`);

--
-- Restrições para tabelas `formulacoes`
--
ALTER TABLE `formulacoes`
  ADD CONSTRAINT `fk_form_op` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_form_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Restrições para tabelas `notificacoes`
--
ALTER TABLE `notificacoes`
  ADD CONSTRAINT `fk_notif_linha` FOREIGN KEY (`destino_linha_id`) REFERENCES `linhas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_op` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_usuario` FOREIGN KEY (`destino_usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `op_insumos`
--
ALTER TABLE `op_insumos`
  ADD CONSTRAINT `op_insumos_ibfk_1` FOREIGN KEY (`op_produto_id`) REFERENCES `op_produtos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `op_insumos_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `itens_componentes` (`id`);

--
-- Restrições para tabelas `op_produtos`
--
ALTER TABLE `op_produtos`
  ADD CONSTRAINT `op_produtos_ibfk_1` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `op_produtos_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`);

--
-- Restrições para tabelas `separacoes_almoxarifado`
--
ALTER TABLE `separacoes_almoxarifado`
  ADD CONSTRAINT `fk_sep_op` FOREIGN KEY (`op_id`) REFERENCES `ordens_producao` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sep_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
