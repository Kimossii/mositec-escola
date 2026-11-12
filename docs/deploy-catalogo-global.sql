-- Catálogo global de módulos e acções (o que `php artisan db:seed` carrega em produção). Idempotente: não duplica linhas.
-- Corre no PostgreSQL do deploy, uma vez, antes de criar a escola.

INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 0, 'Utilizadores', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 0);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 1, 'Autorizacao', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 1);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 2, 'Ano Letivo', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 2);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 3, 'Licenca', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 3);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 4, 'Aluno', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 4);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 5, 'Professor', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 5);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 6, 'Turmas', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 6);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 7, 'Matricula', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 7);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 8, 'Disciplina', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 8);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 9, 'Nota', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 9);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 10, 'Estabelecimento', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 10);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 11, 'Horario', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 11);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 12, 'Infraestrutura', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 12);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 13, 'Curso', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 13);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 14, 'Plano Curricular', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 14);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 15, 'Documento Pessoa', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 15);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 16, 'Senha de Utilizador', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 16);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 17, 'Regra de Cobranca', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 17);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 18, 'Metodo de Pagamento', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 18);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 19, 'Catalogo Financeiro', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 19);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 20, 'Plano de Propina', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 20);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 21, 'Moeda e Cambio', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 21);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 22, 'Propina', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 22);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 23, 'Pagamento', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 23);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 24, 'Pausa de Cobranca', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 24);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 25, 'Cobranca', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 25);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 26, 'Bolsa', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 26);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 27, 'Acordo de Divida', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 27);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 28, 'Atribuicao Docente', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 28);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 29, 'Docente', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 29);
INSERT INTO modulos (nome, descricao, estado, estado_descricao, created_at, updated_at)
SELECT 30, 'Area do Professor', 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE nome = 30);

INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'ver', 0, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'ver');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'criar', 1, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'criar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'editar', 2, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'editar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'eliminar', 3, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'eliminar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'listar', 4, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'listar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'exportar', 5, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'exportar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'confirmar', 6, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'confirmar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'anular', 7, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'anular');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'cancelar', 8, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'cancelar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'ajustar', 9, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'ajustar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'negociar', 10, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'negociar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'isentar-multa', 11, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'isentar-multa');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'trancar', 12, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'trancar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'encerrar', 13, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'encerrar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'prolongar-prazo', 14, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'prolongar-prazo');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'lancar-fora-prazo', 15, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'lancar-fora-prazo');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'configurar', 16, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'configurar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'aprovar', 17, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'aprovar');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'reabrir', 18, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'reabrir');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'ver-pauta', 19, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'ver-pauta');
INSERT INTO acoes (nome, numero, estado, estado_descricao, created_at, updated_at)
SELECT 'tratar-negativa', 20, 1, 'Ativo', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM acoes WHERE nome = 'tratar-negativa');
