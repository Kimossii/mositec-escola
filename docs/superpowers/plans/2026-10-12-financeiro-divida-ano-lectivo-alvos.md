# Financeiro: dívida do ano lectivo e alvos obsoletos

## Objectivo
Fechar duas lacunas dos planos de propina: (1) o ano lectivo podia mudar de mês de início ou ser eliminado com planos ancorados nele; (2) alvos que apontam para nível/turno/turma eliminados (soft delete) ficavam num estado indefinido.

## Decisões
- Competências ancoram-se no mês de `ano_lectivo.data_inicio`: com planos (qualquer estado, só do tenant), mudar mês/ano de `data_inicio` é erro em `data_inicio`; mudar o dia no mesmo mês, e `data_fim`, são livres.
- Eliminar ano lectivo com planos é erro em `ano_lectivo` (soft delete torna a FK inútil).
- AnoLectivo não importa o Financeiro: contrato `AnoLectivo\Contracts\DependenciasDoAnoLectivo` (`bloqueiaAlteracaoDeInicio`, `bloqueiaEliminacao`, devolvem motivo PT-PT ou null), registo por etiqueta `anolectivo.dependencias` (`DependenciasRegistadasDoAnoLectivo`), mesmo padrão de `ReferenciasFinanceiras`.
- Alvo obsoleto = nível/turno/turma eliminado (curso não tem soft delete). `PlanoPropinaAlvo::obsoleto()`; relações com `withTrashed()`.
- Resolvedor ignora alvos obsoletos (nunca degrada para geral). Listagem devolve `eliminado` e nomes. Actualizar retira alvos obsoletos já gravados; se isso esvaziar os alvos, erro em `alvos`. Criar com id eliminado continua rejeitado por `exists` + `deleted_at`.

- Plano com alvos obsoletos nunca fica geral: lista vazia, omitida ou só de obsoletos é rejeitada. Data de início compara o dia civil como escrito (sem conversão de fuso). `DependenciasRegistadasDoAnoLectivo` é resolvido com `app()` nas Actions (testes existentes usam `new Action()`; injecção por construtor ficou de fora). Fronteira garantida por `tests/Feature/Arquitectura/FronteiraAnoLectivoFinanceiroTest`.

## Ficheiros
- AnoLectivo: `Contracts/DependenciasDoAnoLectivo.php`, `Support/DependenciasRegistadasDoAnoLectivo.php`, `Actions/{Atualizar,Eliminar}AnoLectivoAction.php`.
- Financeiro: `Support/DependenciasDePlanosPropina.php`, `Providers/FinanceiroServiceProvider.php`, `Models/PlanoPropinaAlvo.php`, `Services/{ResolvePlanoAplicavel,PlanoPropinaConsultaService}.php`, `Http/Requests/AtualizarPlanoPropinaRequest.php`.
- Frontend: `Pages/PlanosPropina/Index.vue`, `Components/PlanosPropina/PlanoPropinaFormModal.vue`.
- Testes: `Financeiro/tests/Feature/{DividaAnoLectivoTest,AlvosObsoletosTest}.php`.
