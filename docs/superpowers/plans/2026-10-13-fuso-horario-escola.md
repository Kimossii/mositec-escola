# Fuso horário da escola

**Decisão do dono:** a escola escolhe o fuso em Configurações → Dados da Escola (por omissão `Africa/Luanda`). A zona da aplicação (UTC) e os timestamps guardados não mudam; só o "hoje" de negócio é calculado no fuso da escola. Motivo: Angola é UTC+1, por isso um pagamento datado de hoje entre 00:00 e 01:00 em Luanda era recusado como futuro e "Em Atraso" virava à 01:00 local.

## Entregue
- Migração `2026_10_13_090000_add_fuso_horario_to_estabelecimentos_table`: `estabelecimentos.fuso_horario` string(64) NOT NULL, default `Africa/Luanda` (linhas existentes herdam o default).
- `Estabelecimento`: `FUSO_HORARIO_PADRAO`, fillable, `$attributes`; o hook `saved` invalida a leitura em cache.
- `AtualizarDadosRequest`: `sometimes|required|string|max:64|timezone:all` (ausente = mantém o actual); DTO e Action levam o campo. Mesmas permissões (`estabelecimento.ver/editar`).
- Página Dados da Escola: cartão "Fuso horário" com `SelectSolid` pesquisável; opções de `DateTimeZone::listIdentifiers()` com `Africa/Luanda` no topo (prop `fusosHorarios`).
- `Modules\Estabelecimento\Services\RelogioDoTenant`: `fuso()`, `hoje()` (dia civil, 00:00), `agora()` (instante com zona aplicada), `hojeNoFusoPadrao()` (comandos de plataforma). Fuso lido uma vez por pedido na memória do `TenantContext` (novo `esquecer()`); sem tenant devolve o default; respeita `Carbon::setTestNow`.
- Financeiro: `ContribuicaoDePagamento`, `RecalcularPropina`, `RegistarCambioRequest` (`before_or_equal:<hoje local>`), `MoedaCambioConsultaService` e `CambioPlataformaCommand` (`hojeNoFusoPadrao()`) usam o relógio. `created_at`/`updated_at` intactos.
- Arquitectura: `RelogioDoTenantArquitecturaTest` proíbe `now()`, `today()`, `Carbon(Immutable)::now/today`, `new DateTime()` e regras `:today` em `Modules/Financeiro/app` (lista de excepções vazia).

## Regra para o futuro
Código de negócio de Financeiro (F2 geração, Pagamentos, multas) obtém o dia com `RelogioDoTenant::hoje()`. Quem passa `$hoje` a `EstadoCobranca::resolver()` / `Propina::scopeComEstadoResolvido()` / `CambioDoDia::para()` passa este valor.

## Testes
`RelogioDoTenantTest`, `FusoHorarioEscolaTest` (Estabelecimento), `FusoHorarioFinanceiroTest`, `RelogioDoTenantArquitecturaTest`.
