# Dívida técnica: catálogo de acções por módulo e violação de FK ao eliminar

## Objectivo

- **A.** Acrescentar ao catálogo as acções `confirmar`, `anular`, `cancelar`, `ajustar`, `negociar`, `isentar-multa` sem que apareçam (sem sentido) na grelha de todos os módulos.
- **B.** Converter a violação de chave estrangeira (PostgreSQL SQLSTATE 23503 / SQLite "FOREIGN KEY constraint failed") ao eliminar configuração financeira num erro de validação PT-PT, em vez de um 500.

## Decisões

### A. Acções aplicáveis por módulo

- Catálogo global (`acoes`): `AcaoSeeder` passa a 12 linhas (números 6 a 11 para as novas). Idempotente (`updateOrCreate`); o dono corre `db:seed --force`.
- Fonte única de aplicabilidade: `Modulo::acoesAplicaveis()` (enum `Modules\Permissao\Enums\Modulo`). Por omissão `Modulo::ACOES_BASE` = `ver, criar, editar, eliminar, listar, exportar`, ou seja, exactamente o que a grelha já mostrava; nenhum módulo existente muda.
- `Support\AcoesAplicaveis` aplica o mapa a linhas da BD (`modulos.nome` = valor do enum): `doModulo`, `doRegistro`, `emUso`, `validarCelulas`. Resolvida pelo container (substituível em testes, usado como fixture de módulo futuro).
- Grelha (`PermissaoConsultaService`): `acoes` (colunas) = acções aplicáveis a pelo menos um módulo (hoje 6); cada módulo traz `acoes` = ids aplicáveis. Vue (`PerfilPermissoes.vue`, `UtilizadorPermissoes.vue`) só renderiza células aplicáveis (traço nas restantes) e os totais de linha/coluna ignoram as inaplicáveis.
- Servidor: `SincronizarPermissoesPerfilAction` e `SincronizarPermissoesUtilizadorAction` validam as células antes da transacção, mas só rejeitam NOVAS concessões inaplicáveis (`permitido=true` num par módulo×acção ainda não gravado). Negações e o reenvio de pares já gravados nunca são rejeitados, para um par antigo não impedir guardar. Os Vue não reenviam pares inaplicáveis (ocultos na grelha): como o servidor substitui tudo ao guardar, o admin limpa-os simplesmente guardando. Erro `celulas`: "A acção «x» não é aplicável ao módulo «Y».". Nada é alterado em caso de erro. A invalidação de cache (`PermissaoCache`) fica como estava.
- `PermissionResolver` ignora (não concede) pares gravados cuja acção não é aplicável ao módulo (defesa em profundidade); sem efeito hoje, pois nada estreita `ACOES_BASE`.
- Não se criaram os módulos PROPINA/PAGAMENTO.

### Acções que cada módulo futuro vai declarar

Conforme `specs/2026-10-08-financeiro-propinas-design.md` e `...pagamentos-design.md`. Ao criar o módulo, acrescentar o `case` ao enum, o `slug`/`label`, a linha no `ModuloSeeder` e o ramo no `match` de `acoesAplicaveis()`:

| Módulo | Acções |
|---|---|
| PROPINA | ver, listar, criar, cancelar, anular, exportar, ajustar, negociar, isentar-multa |
| PAGAMENTO | ver, listar, criar, anular, exportar |

(`confirmar` fica no catálogo mas nenhum destes dois módulos a declara; reservada.) Nota: PROPINA/PAGAMENTO não incluem `editar`/`eliminar`; ao declarar o ramo do `match` listar explicitamente estas listas em vez de `ACOES_BASE`.

### B. Violação de FK ao eliminar

- `Modules\Financeiro\Support\ViolacaoDeChave`: `e(Throwable)` detecta SQLSTATE 23503 (pgsql) ou mensagem "FOREIGN KEY constraint failed" / "violates foreign key constraint" (SQLite, onde o SQLSTATE 23000 é genérico e partilhado com UNIQUE); `comoValidacao(Closure)` converte em `ValidationException` (`eliminar`: "Não é possível eliminar: existem registos que dependem deste.").
- Usado à volta do `delete()` inteiro nas cinco `Eliminar*Action` (Cambio, MetodoPagamento, Produto, Servico, PlanoPropina). Nunca dentro de `DB::transaction` com continuação (PostgreSQL aborta a transacção).
- `ReferenciasFinanceiras` continua a ser a primeira linha de defesa (mensagem específica com sugestão de desactivar).

## Ficheiros

- Permissao: `Enums/Modulo.php`, `Support/AcoesAplicaveis.php`, `database/seeders/AcaoSeeder.php`, `Services/PermissaoConsultaService.php`, `Actions/SincronizarPermissoes{Perfil,Utilizador}Action.php`, `resources/js/Pages/{PerfilPermissoes,UtilizadorPermissoes}.vue`.
- Permissao testes: `AcoesAplicaveisTest` (novo), `AcaoSeederTest` (contagem 12).
- Financeiro: `Support/ViolacaoDeChave.php`, `Actions/Eliminar*Action.php`, testes `Unit/ViolacaoDeChaveTest`, `Feature/EliminarComChaveEstrangeiraTest` (FK real em SQLite).
