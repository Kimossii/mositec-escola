# Financeiro: configuração de multas por atraso (escalões)

**Objectivo:** configurar `multa_activa` e até 3 `escaloes_multa` na página existente Financeiro → Configurações → Regras de Cobrança, num só "Guardar". Fonte: spec de Configurações (`regras_cobranca` / `escaloes_multa`) e Propinas §15.

## Decisões
- Sem menu nem rotas novas: reutiliza `show`/`update` e a permissão `regra-cobranca.editar`.
- `Support/Multa`: `MAX_ESCALOES = 3` (único local), `MAX_DIAS_ATRASO = 3650` (cabe no smallint), conversão percentagem <-> pontos-base (100 = 1%, "2,5" -> 250, 0 < p <= 100, até 2 casas).
- `ordem` é a posição na lista, atribuída no servidor; `dias_atraso` estritamente crescentes (validação `after()` do FormRequest, erro em `escaloes.N.dias_atraso`).
- VALOR_FIXO: `ValorMonetario(false)` + `Dinheiro::deDecimal` na moeda do tenant. Valores enormes dão 422.
- Escrita: na mesma `DB::transaction` (com `lockForUpdate` na regra, sem apanhar excepções) a Action guarda a regra e, só se o pedido trouxer `multa_activa`, a flag. Os escalões só são **substituídos** (delete + create) quando `multa_activa` = true e `escaloes` vem validado (1..3; `[]` falha). Com `multa_activa` = false, ou sem as chaves, os escalões gravados ficam intactos e `escaloes.*` nem é validado. A UI não envia `escaloes` com o interruptor desligado.
- Leitura: props `regra.multa_activa` (sem `regra.escaloes`), `escaloes[{ordem, dias_atraso, tipo, valor_input}]` (percentagem "2,5"; fixo "1500.50"), `moeda`, `tiposMulta`, `maxEscaloes`.
- `PrecosDasMultas` (FonteDePrecos, etiquetada no provider): existe escalão VALOR_FIXO => a moeda não muda. Percentagens não bloqueiam.
- `EscalaoMulta`: `PertenceAoTenant`, hook `saving` null-safe para `tipo_descricao`, sem `RegistaAutoria` (config substituída por inteiro).

## Ficheiros
Migrations `2026_10_12_100000_add_multa_activa_...` e `2026_10_12_100100_create_escaloes_multa_table`; `Enums/TipoMulta`, `Models/EscalaoMulta`, `Support/{Multa,PrecosDasMultas}`, `DTO/EscalaoMultaDTO`; alterados `RegraCobranca`, `RegraCobrancaDTO`, `AtualizarRegraCobrancaRequest`, `AtualizarRegraCobrancaAction`, `GestaoRegraCobrancaService`, `RegraCobrancaController`, `FinanceiroServiceProvider`, `Pages/RegrasCobranca/Edit.vue`. Testes: `EscaloesMultaTest`, `FinanceiroTenancyTest`.

## Adiado
Aplicação das multas, `propina_multas` e revisões, imputação de pagamentos, comando `financeiro:aplicar-multas`, permissão `propina.isentar-multa`.
