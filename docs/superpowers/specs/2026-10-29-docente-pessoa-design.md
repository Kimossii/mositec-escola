# Docente como pessoa, com acesso opcional — desenho

**Data:** 2026-10-29
**Estado:** implementado (em stage); aprovado em 2026-10-29
**Altera:** `docs/superpowers/specs/2026-10-28-atribuicao-docente-design.md` (decisão D3 «o professor é sempre utilizador» deixa de valer). Tudo o resto desse desenho mantém-se.

## 1. Intenção

Um professor lecciona mesmo sem ter conta no sistema (tempo parcial, recém-contratado, escola que regista a equipa antes de dar acessos). Hoje cadastrar um professor exige email e senha, e a atribuição aponta para `users`. Passa a haver o **docente** como pessoa da escola (menu **Pessoal → Docentes**), e a **conta é opcional**, ligada depois quando o docente precisar de entrar no sistema (lançar notas).

## 2. Decisões

| # | Decisão | Origem |
|---|---|---|
| E1 | O docente é uma entidade própria (`docentes`) no módulo `Docente`, ligada a `DadosPessoa`, sem conta obrigatória | Pedido do dono (2026-10-29) + menu «Pessoal → Docentes» |
| E2 | Mesmo padrão do módulo Aluno: `docentes.dados_pessoa_id` único; a conta, quando existe, é o `User` com o mesmo `dados_pessoa_id`. Sem FK directa entre `docentes` e `users` | Padrão já existente (`alunos`, `CriarUtilizadorAlunoAction`) |
| E3 | Cadastro e gestão em **Pessoal → Docentes** (`/docentes`); **Configurações → Utilizadores → Professores** continua a listar só utilizadores com perfil Professor | Menu do dono |
| E4 | A atribuição passa a apontar para `docente_id` (em vez de `professor_id` → `users`) | Consequência de E1 |
| E5 | O contrato `VerificaAtribuicaoDocente` **mantém as assinaturas**: recebe o id do **utilizador** autenticado e resolve utilizador → pessoa → docente. Um docente sem conta nunca chama o contrato | **Minha decisão** (evita mexer nos consumidores futuros) |
| E6 | «Criar acesso» existe em dois sítios, com a mesma Action: botão no docente (Pessoal → Docentes) e, em Utilizadores → Professores, escolher um docente sem conta (como a pesquisa do aluno). Cria o `User` com perfil Professor sobre a mesma `DadosPessoa`, o servidor usa os dados do docente (ignora nome/email do cliente), reutilizando o padrão de `CriarUtilizadorAlunoAction` | Aprovado pelo dono (2026-10-29) |
| E7 | Quando alguém cria, pelo fluxo actual de utilizadores, um utilizador com perfil Professor, o sistema garante o registo de docente (idempotente), para as duas listas não divergirem. Um comando `docentes:sincronizar` faz o mesmo para os professores já existentes | **Minha decisão** |
| E8 | A migração `create_atribuicoes_docentes_table` JÁ corre na BD de desenvolvimento (batch 7): **não se altera**. Nova migração troca `professor_id` por `docente_id`, com preenchimento dos docentes a partir dos utilizadores já atribuídos (idempotente), e recria a constraint de exclusão do «mesmo professor» | Verificado com `migrate:status` |
| E9 | Deixam de ser necessários o contrato `DependenciasDoUtilizador` do Core, a sua agregadora, a implementação do Docente e o gancho em `EliminarUsuarioAction`: a atribuição já não prende `users`. Removem-se (código morto). O `unique(tenant_id, id)` de `users` já foi aplicado e fica (inofensivo) | **Minha decisão** |
| E10 | Eliminar um docente com qualquer atribuição é bloqueado (mensagem em português); sem atribuições pode-se eliminar; o dia-a-dia é activar/desactivar. Desactivar não encerra atribuições em vigor, mas o docente deixa de aparecer para novas | **Minha decisão** |
| E11 | Permissões: módulo novo `docente` (slug `docente`) com `ver, listar, criar, editar, eliminar, exportar`; admin_escola todas, funcionário `ver, listar, criar, editar`. «Criar acesso» exige `docente.editar` e `usuario.criar` | **Minha decisão** |
| E12 | Menu **Pessoal**: «Docentes» (`/docentes`) e «Atribuições docentes» (`/docentes/atribuicoes`), ambos por permissão | **Minha decisão**; a visão do dono só lista «Docentes» sob Pessoal |

## 3. Modelo de dados

Tabela `docentes` (padrão de `alunos`): `tenant_id`, `estabelecimento_id`, `dados_pessoa_id` (único), `estado` + `estado_descricao` (1 activo), autoria, timestamps. Dados pessoais (nome, BI, telefone, email de contacto, sexo, nascimento) ficam em `DadosPessoa` com `tipo_pessoa = TIPO_PROFESSOR`; documentos já existem em `DocumentoPessoa`. FKs compostas `(tenant_id, …)` como no resto.

`atribuicoes_docentes`: `professor_id` → **`docente_id`** (FK composta `(tenant_id, docente_id)` → `docentes (tenant_id, id)`); exclusão de sobreposição «mesmo professor» passa a usar `docente_id`; resto igual.

## 4. Regras que mudam na atribuição

1. O docente tem de existir no tenant e estar activo (`estado = 1`). Deixa de exigir perfil Professor.
2. O contrato resolve o docente a partir do utilizador (`users.dados_pessoa_id` → `docentes.dados_pessoa_id`); utilizador sem docente ligado → `false` / lista vazia.
3. Cadastrar docente sem email é permitido. Não pode haver dois docentes com a mesma `DadosPessoa`.

## 5. Experiência

- **Pessoal → Docentes:** lista (pesquisa, estado, «Com acesso / Sem acesso»), criar e editar docente (nome, BI, telefone, email, sexo, nascimento), activar/desactivar, eliminar (bloqueado se tem atribuições), exportar, e a acção **Criar acesso** (define email e senha provisória; só para docentes sem conta).
- **Atribuições docentes:** o selector de professor lista docentes activos, com ou sem conta. A coluna/linha mostra se o docente tem acesso (para a secretaria saber quem poderá lançar notas).
- Mesmo padrão visual das listas do projecto.

## 6. Não-objectivos

Habilitações, contratos, carga horária, disponibilidade, foto do docente, importação em massa, ficha completa do funcionário (Funcionários são outro módulo futuro com a mesma ideia).

## 7. Fatias de implementação

1. Entidade `Docente` (módulo, migração, model, Actions criar/editar/estado/eliminar, Service de consulta, permissões, sincronização E7).
2. Atribuição passa a `docente_id` (migração no sítio, validador, Actions, contrato com resolução utilizador→docente, consulta, exportação, remoção do código morto E9, testes ajustados).
3. Criar acesso (Action reutilizando a de utilizadores) e página Pessoal → Docentes + menus + ajuste do selector das atribuições.

## 8. Questões abertas

Nenhuma.
