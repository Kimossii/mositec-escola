# S4 — Documentos pessoais: âmbito, registo de acessos e limite de taxa

Aprovado pelo dono em 2026-11-22. Nada fica para depois: as quatro peças entram juntas.

## Intenção
`documento-pessoa.ver` dá hoje acesso aos documentos (BI, certidões, fotos; incluindo menores) de qualquer pessoa da escola, sem registo. Objectivo: limitar o âmbito, saber quem abriu o quê e travar a recolha em massa.

## Não-objectivos
- Alterar o armazenamento dos ficheiros (`CaminhoTenant`) ou as permissões de criar/editar/eliminar.
- Registar leituras de outras entidades.

## Decisões
1. **Policy `DocumentoPessoaPolicy::ver(User, DadosPessoa)`**, aplicada em `index`, `inativos`, `download`, `visualizar` (além do `can:documento-pessoa.ver`):
   - a pessoa vê sempre os seus próprios documentos;
   - utilizador com `Docente` ligado vê só documentos de alunos com matrícula numa turma onde tem `AtribuicaoDocente` em vigor;
   - aluno vê só os seus;
   - administrador e perfis de gestão (secretaria, etc.) vêem tudo;
   - quem acumula professor + gestão fica com o âmbito mais largo;
   - falha → 403 e registo `negado`.
   - "Professor" = ter `Docente` ligado, não o perfil em si (pressuposto confirmado).
2. **Tabela `acessos_documentos_pessoa`** (por tenant, uma migração): utilizador, documento (nullable p/ listagens), dados_pessoa dona, acção (`listar|visualizar|download|negado`), IP, user-agent, data. Só inserção pela app. Escrita via Action; leitura via Service.
3. **Limite de taxa**: o `visualizar`, `index` e `inativos` ganham o mesmo `throttle` do download; mais um limite por utilizador para aberturas de pessoas distintas (anti-varrimento de IDs).
4. **Ecrã "Acessos a documentos"** (só administrador): filtros por pessoa, utilizador e data.

## Abordagens consideradas
- Log em ficheiro via `LogService`: rápido, mas não consultável nem durável → rejeitada.
- Tabela dedicada + ecrã: escolhida (consultável, sobrevive à rotação de logs).

## Restrições do projecto
Controllers finos (Service p/ leitura, Action p/ escrita); uma tabela por migração; testar o fluxo HTTP e não só Actions; stage sem commit; sem edição directa da BD.

## Deixado ao implementador
Valores exactos dos limites de taxa; nome da permissão do ecrã de acessos (slug real + enum `Modulo` + seed em `RolePermissaoSeeder`); ligação do item de menu.
