# Avaliação — Fase 1 (motor, regras, prazos e lançamento de notas) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Módulo `Avaliacao` que permite configurar a regra de avaliação por classe, lançar avaliações contínuas e provas trimestrais e calcular `MAC → MT → MFD` (1.ª–5.ª e 7.ª–8.ª; 6.ª e 9.ª sem exames nesta fase).

**Architecture:** Módulo nwidart novo, igual ao `Docente` (Models `PertenceAoTenant` + `RegistaAutoria`, Actions com `LogService`, Services de leitura, controllers finos). Um motor de cálculo puro (`bcmath`, sem BD) é a única fonte de fórmulas; o serviço de resultados lê notas, aplica prazos e chama o motor. Nada é persistido como resultado nesta fase (instantâneo é Fase 2).

**Tech Stack:** Laravel + nwidart/laravel-modules, PostgreSQL (`PgsqlTestCase` onde preciso), Inertia + Vue 3, PHPUnit.

**Spec:** `docs/pautas_Final-por_validar/` (3 `.md`, `geral.png`) + decisões desta conversa (2026-11-02). Fora desta fase: exames próprios, recurso, decisão Transita/Apto, provisório/definitivo, pré-escolar qualitativo, boletins.

## Global Constraints

- Escalas: 1.ª–6.ª `escala_maxima = 10`; 7.ª–9.ª `escala_maxima = 20`. Mínimo de aprovação por defeito: 5 (1.ª–6.ª) e 10 (7.ª–9.ª). Configuráveis por classe e ano.
- Pesos por defeito: MAC 50 / PT 50; três trimestres com pesos iguais (colunas nulas = iguais). Pesos têm de somar 100.
- `MAC = Σ notas consideradas ÷ nº de notas consideradas` (divisor real, nunca o total configurado). Zero é nota válida; facultativa sem nota fica de fora.
- Obrigatória sem nota ou PT sem nota **depois do prazo** = 0 (calculado em leitura, nunca gravado). Antes do prazo = pendente (`null`).
- Nota anulada: valor `NULL`, fora do cálculo, salvo decisão autorizada "conta como zero".
- Sem arredondamento intermédio (MAC→MT→MFD). Arredondamento só na apresentação; por defeito provisório 2 casas, `half_up`, a confirmar com o professor.
- `MFD` só existe com os 3 `MT`; antes disso `null`.
- Prazo: excepção (turma/disciplina/docente) > prazo da regra por (trimestre, tipo) > `periodos.data_fim`. O instrumento NÃO tem prazo.
- Permissões só por `Modulo::NOTA` (slug `nota`); nenhum perfil fixo. Acções novas: `prolongar-prazo`, `lancar-fora-prazo`.
- Nomes por número de classe (`NivelAcademico.codigo`: `PRI_1`…`SEC_9`); nunca "ciclo".
- Tenancy: `tenant_id` + FKs compostas `(tenant_id, id)` como em `atribuicoes_docentes`; models com `PertenceAoTenant`.
- Nunca fazer `git commit` sem pedido do dono: só `git add`.

## Review Focus

- Aluno sem nenhuma nota no trimestre, prazo aberto → `MT = null`, não 0 (Task 7).
- Nota `0` lançada ≠ nota ausente; `0` entra no MAC (Task 2, Task 7).
- Valor `< 0` ou `> escala_maxima`, ou pesos que não somam 100, são rejeitados (Task 3, Task 6).
- Docente não atribuído, ou de outro tenant, não lança nota; depois do prazo só com `lancar-fora-prazo`, e a alteração fica no histórico (Task 6).
- Duas regras para o mesmo (ano, classe), ou prazo duplicado para (regra, período, tipo), são impedidos pela BD (Task 3, Task 4).

---

### Task 1: Módulo, enums e permissões

**Files:**
- Create: `Modules/Avaliacao/{module.json,composer.json,routes/web.php,app/Providers/AvaliacaoServiceProvider.php,app/Providers/RouteServiceProvider.php}` (copiar a forma de `Modules/Docente`)
- Create: `Modules/Avaliacao/app/Enums/{TipoInstrumento,EstadoNota,ModoArredondamento}.php`
- Modify: `modules_statuses.json`, `Modules/Permissao/app/Enums/Modulo.php` (NOTA em `acoesAplicaveis`), `Modules/Permissao/database/seeders/AcaoSeeder.php`, `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Test: `Modules/Avaliacao/tests/Feature/AvaliacaoPermissoesTest.php`

**Interfaces:**
- Produces: `TipoInstrumento` (int-backed: `MAC=0`, `PT=1`; `label()`), `EstadoNota` (`LANCADA=0`, `ANULADA=1`, `ANULADA_CONTA_ZERO=2`; `label()`), `ModoArredondamento` (`HALF_UP=0`, `HALF_EVEN=1`, `TRUNCAR=2`; `label()`); acções `prolongar-prazo` (14) e `lancar-fora-prazo` (15).
- `Modulo::NOTA->acoesAplicaveis()` = `['ver','listar','criar','editar','anular','prolongar-prazo','lancar-fora-prazo','exportar']`.

- [ ] **Step 1:** Escrever `AvaliacaoPermissoesTest`: `test_nota_declara_as_accoes_de_avaliacao` (asserta a lista exacta acima), `test_admin_escola_tem_todas_as_accoes_de_nota`, `test_professor_pode_ver_listar_criar_editar_nota_mas_nao_prolongar_prazo`.
- [ ] **Step 2:** Correr `php artisan test Modules/Avaliacao/tests/Feature/AvaliacaoPermissoesTest.php` → FAIL.
- [ ] **Step 3:** Criar o módulo (provider estende `BaseModuleServiceProvider`, `$name='Avaliacao'`), registar `"Avaliacao": true` em `modules_statuses.json`, criar os 3 enums com `label()` em português, acrescentar as 2 acções ao `AcaoSeeder` e a `Modulo::NOTA`, e a grelha de `NOTA` ao `RolePermissaoSeeder` seguindo as linhas de `ATRIBUICAO_DOCENTE` (ADMIN_ESCOLA todas; PROFESSOR só ver/listar/criar/editar).
- [ ] **Step 4:** Correr o teste → PASS. Correr `php artisan module:list` e confirmar `Avaliacao` activo.
- [ ] **Step 5:** `git add` dos ficheiros (sem commit).

### Task 2: Motor de cálculo (puro)

**Files:**
- Create: `Modules/Avaliacao/app/Support/Calculo/CalculadoraQuantitativa.php`
- Test: `Modules/Avaliacao/tests/Unit/CalculadoraQuantitativaTest.php`

**Interfaces:**
- Consumes: `ModoArredondamento` (Task 1).
- Produces (valores decimais como `string`, `bcmath`, escala interna 10; `null` = indisponível):
  - `media(array $valores): ?string` — `null` se vazio.
  - `mt(?string $mac, ?string $pt, string $pesoMac, string $pesoPt): ?string` — `null` se `$mac` ou `$pt` for `null`; pesos em percentagem, têm de somar 100 (senão `InvalidArgumentException`).
  - `mfd(array $mts, ?array $pesos = null): ?string` — `$mts` com 3 posições; `null` se alguma for `null`; `$pesos null` = iguais.
  - `arredondar(string $valor, int $casas, ModoArredondamento $modo): string`.
  - `aprovadoNaDisciplina(string $valorArredondado, string $notaMinima): bool` (≥).

- [ ] **Step 1:** Escrever os testes com os exemplos do doc: `test_mac_ana_7_50` (`[7,8,6,9]` → `7.5000000000`), `test_mac_joao_conta_o_zero_e_divide_por_3` (`[5,4,0]` → 3), `test_mac_de_lista_vazia_e_null`, `test_mt_6_165` (`mac 5.33.. pt 7` → exemplo do doc: `mt('5.33','7','50','50') === '6.1650000000'`), `test_mt_com_pesos_60_40`, `test_mt_rejeita_pesos_que_nao_somam_100`, `test_mfd_7_055` (`['6.165','6.5','8.5']` → `7.0550000000`), `test_mfd_sem_tres_trimestres_e_null`, `test_mfd_nao_arredonda_antes` (valor com muitas casas), `test_arredondar_half_up_4_5_com_0_casas_da_5`, `test_aprovado_exige_arredondado_maior_ou_igual_ao_minimo` (`'5'` vs `'5'` true; `'4.99'` false).
- [ ] **Step 2:** Correr → FAIL (classe inexistente).
- [ ] **Step 3:** Implementar a classe só com `bcadd/bcdiv/bcmul/bccomp`; sem acesso a BD, sem models, sem `float`.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5:** `git add`.

### Task 3: Regra de avaliação (por ano e classe) + cópia

**Files:**
- Create: migration `…create_avaliacao_regras_table.php`, `Modules/Avaliacao/app/Models/AvaliacaoRegra.php`, `app/DTO/RegraAvaliacaoDTO.php`, `app/Actions/{GuardarRegraAvaliacaoAction,CopiarRegrasDoAnoAnteriorAction}.php`, `app/Services/RegraPadraoService.php`
- Test: `Modules/Avaliacao/tests/Feature/{AvaliacaoRegraModelTest,GuardarRegraAvaliacaoActionTest,CopiarRegrasDoAnoAnteriorActionTest,RegraPadraoServiceTest}.php`

**Interfaces:**
- Consumes: `ModoArredondamento` (Task 1); `NivelAcademico` (`codigo`, `estabelecimento_id`), `AnoLectivo`.
- Produces: tabela `avaliacao_regras` (`tenant_id`, `ano_lectivo_id`, `nivel_academico_id`, `escala_maxima` tinyint, `nota_minima` decimal(5,2), `peso_mac`/`peso_pt` decimal(5,2) def. 50, `peso_mt1/2/3` decimal(5,2) nullable, `casas_decimais` tinyint def. 2, `modo_arredondamento` tinyint + `modo_arredondamento_descricao`, `reprova_por_aproveitamento` bool, auditoria, `unique(tenant_id,ano_lectivo_id,nivel_academico_id)`); `AvaliacaoRegra` com relações `anoLectivo()`, `nivelAcademico()`.
  - `RegraPadraoService::paraNivel(string $codigoNivel): array` — `PRI_1,PRI_2` → `{escala:10, minima:5, reprova:false}`; `PRI_3…PRI_6` → `{10, 5, true}`; `SEC_7…SEC_9` → `{20, 10, true}`; outros códigos → `null` (sem regra quantitativa; pré-escolar é Fase 4).
  - `GuardarRegraAvaliacaoAction::executar(RegraAvaliacaoDTO $dto): AvaliacaoRegra` (cria ou actualiza; valida pesos = 100 e `nota_minima ≤ escala_maxima`).
  - `CopiarRegrasDoAnoAnteriorAction::executar(int $anoDestinoId): int` (nº de regras copiadas; não sobrescreve existentes).

- [ ] **Step 1:** Testes: `test_regra_padrao_por_codigo_de_nivel` (as 4 faixas + `PRE_I` → null), `test_guardar_rejeita_pesos_que_nao_somam_100`, `test_guardar_rejeita_minima_acima_da_escala`, `test_segunda_regra_para_o_mesmo_ano_e_nivel_e_rejeitada_pela_bd`, `test_copiar_traz_as_regras_do_ano_anterior_e_nao_sobrescreve`, `test_regra_de_outro_tenant_nao_aparece`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Migration com FKs compostas como em `atribuicoes_docentes`; model com `PertenceAoTenant`, `RegistaAutoria` e hook `saving` que preenche `modo_arredondamento_descricao`; actions com `LogService::comLog` (o `ActionsComRegistoTest` da arquitectura passa a cobri-las).
- [ ] **Step 4:** Correr os testes do módulo e `php artisan test tests/Feature/Arquitectura` → PASS.
- [ ] **Step 5:** `git add`.

### Task 4: Prazos e excepções

**Files:**
- Create: migration `…create_avaliacao_prazos_tables.php` (duas tabelas), `app/Models/{AvaliacaoPrazo,AvaliacaoPrazoExcepcao}.php`, `app/Actions/{DefinirPrazoAction,ProlongarPrazoAction}.php`, `app/Services/PrazoService.php`
- Test: `Modules/Avaliacao/tests/Feature/{PrazoServiceTest,DefinirPrazoActionTest,ProlongarPrazoActionTest}.php`

**Interfaces:**
- Consumes: `AvaliacaoRegra` (Task 3), `TipoInstrumento`, `Periodo`.
- Produces: `avaliacao_prazos` (`regra_id`, `periodo_id`, `tipo`, `data_limite`, `unique(regra_id,periodo_id,tipo)`); `avaliacao_prazo_excepcoes` (`prazo_id`, `turma_id?`, `disciplina_id?`, `docente_id?`, `data_limite`, `motivo` obrigatório, auditoria).
  - `PrazoService::dataLimite(AvaliacaoRegra $regra, int $periodoId, TipoInstrumento $tipo, int $turmaId, int $disciplinaId, ?int $docenteId = null): CarbonImmutable` — ordem: excepção mais específica > prazo (regra, período, tipo) > `periodos.data_fim`.
  - `PrazoService::prazoEncerrado(…mesmos args, CarbonInterface $agora): bool` — usa `RelogioDoTenant` para "hoje"; fim do dia inclusivo.
  - `DefinirPrazoAction::executar(int $regraId, int $periodoId, TipoInstrumento $tipo, string $dataLimite): AvaliacaoPrazo`.
  - `ProlongarPrazoAction::executar(int $prazoId, string $novaData, string $motivo, ?int $turmaId, ?int $disciplinaId, ?int $docenteId): AvaliacaoPrazoExcepcao` (exige `Gate::allows('nota.prolongar-prazo')`).

- [ ] **Step 1:** Testes: `test_sem_prazo_nem_excepcao_vale_o_fim_do_periodo`, `test_prazo_da_regra_vence_o_fim_do_periodo`, `test_excepcao_por_turma_vence_o_prazo_da_regra`, `test_excepcao_por_docente_mais_especifica_vence_a_de_turma`, `test_prazo_no_proprio_dia_ainda_nao_esta_encerrado`, `test_prolongar_exige_permissao_e_motivo`, `test_prazo_duplicado_para_regra_periodo_tipo_e_rejeitado`.
- [ ] **Step 2:** Correr → FAIL. **Step 3:** Implementar (precedência da excepção: docente > disciplina+turma > turma; documentar no docblock). **Step 4:** Correr → PASS. **Step 5:** `git add`.

### Task 5: Instrumentos de avaliação

**Files:**
- Create: migration `…create_avaliacao_instrumentos_table.php`, `app/Models/AvaliacaoInstrumento.php`, `app/DTO/InstrumentoDTO.php`, `app/Actions/{CriarInstrumentoAction,AtualizarInstrumentoAction,EliminarInstrumentoAction}.php`
- Test: `Modules/Avaliacao/tests/Feature/InstrumentoActionsTest.php`

**Interfaces:**
- Produces: `avaliacao_instrumentos` (`turma_id`, `disciplina_id`, `periodo_id`, `tipo`, `tipo_descricao`, `nome`, `obrigatorio` bool, auditoria; **sem prazo**). Relações `turma()`, `disciplina()`, `periodo()`, `notas()`. `CriarInstrumentoAction::executar(InstrumentoDTO $dto): AvaliacaoInstrumento` valida que o período pertence ao ano da turma e que só existe **uma** `PT` por (turma, disciplina, período). `EliminarInstrumentoAction` recusa se já houver notas.

- [ ] **Step 1:** Testes: `test_cria_quatro_continuas_e_uma_pt`, `test_segunda_pt_no_mesmo_periodo_e_rejeitada`, `test_periodo_de_outro_ano_lectivo_e_rejeitado`, `test_eliminar_com_notas_e_rejeitado`, `test_instrumento_nao_tem_coluna_de_prazo` (asserta `Schema::hasColumn(...) === false`).
- [ ] **Step 2–5:** FAIL → implementar (migration com FKs compostas, `tipo_descricao` via hook) → PASS → `git add`.

### Task 6: Notas, histórico e lançamento

**Files:**
- Create: migration `…create_avaliacao_notas_tables.php` (notas + histórico), `app/Models/{AvaliacaoNota,AvaliacaoNotaHistorico}.php`, `app/DTO/LancarNotaDTO.php`, `app/Actions/{LancarNotaAction,AnularNotaAction}.php`
- Test: `Modules/Avaliacao/tests/Feature/{LancarNotaActionTest,AnularNotaActionTest}.php`

**Interfaces:**
- Consumes: `PrazoService::prazoEncerrado`, `Core\Contracts\VerificaAtribuicaoDocente::podeGerir(int $userId, int $turmaId, int $disciplinaId, ?CarbonInterface)`, `AvaliacaoRegra->escala_maxima`.
- Produces: `avaliacao_notas` (`instrumento_id`, `inscricao_disciplina_id`, `valor decimal(5,2) null`, `estado`, `estado_descricao`, auditoria, `unique(instrumento_id,inscricao_disciplina_id)`); `avaliacao_notas_historico` (`nota_id`, `valor_anterior`, `valor_novo`, `estado_anterior`, `estado_novo`, `fora_do_prazo` bool, `motivo` nullable, `criado_por`).
  - `LancarNotaAction::executar(LancarNotaDTO $dto): AvaliacaoNota` — DTO: `instrumento_id`, `inscricao_disciplina_id`, `valor` (string decimal), `motivo?`. Regras: valor `0 ≤ v ≤ escala_maxima`; inscrição tem de ser da turma do instrumento e do mesmo tenant; dentro do prazo exige `podeGerir(auth user)` e `can:nota.criar`; depois do prazo exige `can:nota.lancar-fora-prazo` (sem exigir atribuição) e `motivo` obrigatório. Cada alteração grava histórico.
  - `AnularNotaAction::executar(int $notaId, bool $contaComoZero, string $motivo): AvaliacaoNota` — exige `can:nota.anular`; põe `valor = null`, estado `ANULADA` ou `ANULADA_CONTA_ZERO`.

- [ ] **Step 1:** Testes: `test_docente_atribuido_lanca_dentro_do_prazo`, `test_docente_nao_atribuido_nao_lanca`, `test_nota_zero_e_valida`, `test_valor_acima_da_escala_e_rejeitado`, `test_valor_negativo_e_rejeitado`, `test_depois_do_prazo_sem_permissao_e_recusado`, `test_depois_do_prazo_com_permissao_grava_historico_fora_do_prazo`, `test_alterar_nota_grava_valor_anterior_no_historico`, `test_anular_poe_valor_null_e_exige_permissao`, `test_anulada_conta_zero_distingue_se_de_anulada`, `test_inscricao_de_outra_turma_e_rejeitada`.
- [ ] **Step 2–5:** FAIL → implementar (transacção + `lockForUpdate` na nota, como `AtribuirDocenteAction`) → PASS → `git add`.

### Task 7: Resultado por disciplina (leitura)

**Files:**
- Create: `app/Services/ResultadoDisciplinaService.php`, `app/DTO/ResultadoDisciplina.php`
- Test: `Modules/Avaliacao/tests/Feature/ResultadoDisciplinaServiceTest.php`

**Interfaces:**
- Consumes: `CalculadoraQuantitativa` (Task 2), `PrazoService` (Task 4), notas e instrumentos (Tasks 5–6).
- Produces: `ResultadoDisciplinaService::calcular(int $inscricaoDisciplinaId): ResultadoDisciplina` com `mac`, `pt`, `mt` (arrays indexados por trimestre 1–3, `?string`), `mfd` (`?string`), `mfdArredondada` (`?string`), `pendente` (bool). Regras: nota `LANCADA` entra; `ANULADA` fora; `ANULADA_CONTA_ZERO` = 0; obrigatória sem nota → 0 só se prazo encerrado, senão pendente; facultativa sem nota fora; PT sem nota → 0 só se prazo encerrado; MAC sem nenhuma nota considerada → `null`.

- [ ] **Step 1:** Testes (cada um monta o cenário com notas e prazo): `test_exemplo_ana_mac_7_50`, `test_exemplo_joao_mac_3_com_facultativa_vazia_excluida`, `test_antes_do_prazo_obrigatoria_em_falta_deixa_mt_null`, `test_depois_do_prazo_obrigatoria_em_falta_vale_zero`, `test_pt_em_falta_depois_do_prazo_vale_zero`, `test_nota_anulada_nao_entra_e_anulada_conta_zero_entra`, `test_mfd_so_com_tres_mt`, `test_mfd_7_055_com_mt_6_165_6_5_8_5`, `test_resultado_nao_altera_a_bd` (conta queries de escrita = 0).
- [ ] **Step 2–5:** FAIL → implementar (único sítio que chama o motor; sem fórmulas fora de `CalculadoraQuantitativa`) → PASS → `git add`.

### Task 8: HTTP, mini-pauta e menu

**Files:**
- Create: `app/Http/Controllers/{ConfiguracaoAvaliacaoController,MiniPautaController}.php`, `app/Http/Requests/*Request.php`, `app/Services/MiniPautaService.php`; Vue: `resources/js/Pages/Avaliacao/{Configuracao,MiniPauta}.vue` (+ `Components/Avaliacao/`), `Models/` JS onde o padrão do projecto o pedir
- Modify: `Modules/Avaliacao/routes/web.php`, `resources/js/Components/Layout/SidebarMenuWrapper.vue:495`, `resources/js/Composables/usePedagogicoMenu.js:15` (descomentar "Avaliações / Notas" e apontar para a rota)
- Test: `Modules/Avaliacao/tests/Feature/{ConfiguracaoAvaliacaoHttpTest,MiniPautaHttpTest,MenuAvaliacaoTest}.php`

**Interfaces:**
- Consumes: todas as Actions e Services anteriores.
- Produces: rotas `avaliacao.configuracao.*` (regras e prazos, `can:nota.editar`/`nota.prolongar-prazo`) e `avaliacao.mini-pauta.index|lancar|anular`; `MiniPautaService::paraTurmaDisciplina(int $turmaId, int $disciplinaId, int $periodoId): array` devolve alunos × instrumentos com notas, MAC, PT e MT do trimestre (via `ResultadoDisciplinaService`), sem fórmulas próprias. Controllers só chamam Service (leitura) ou Action (escrita). Reaproveitar classes/ícones já existentes nas páginas de Docente/Turma; seguir as convenções do projecto (`bg-body-secondary` em cartões, `Loader.vue` só pontual).

- [ ] **Step 1:** Testes HTTP: `test_mini_pauta_mostra_so_alunos_da_turma_e_disciplina`, `test_docente_nao_atribuido_recebe_403_na_mini_pauta`, `test_lancar_nota_via_http_respeita_prazo`, `test_configuracao_sem_permissao_recebe_403`, `test_menu_avaliacoes_notas_aparece_com_nota_ver`.
- [ ] **Step 2–4:** FAIL → implementar → `php artisan test Modules/Avaliacao` e `npm run build` sem erros; abrir a mini-pauta no browser (login em `reference_dev_login_credentials`) e confirmar lançamento e cálculo com os exemplos Ana/João.
- [ ] **Step 5:** `git add`; correr a suite completa e reportar o resultado.

## Self-review

- **Cobertura:** regra por classe/ano (T3), prazos e excepções por permissão (T4), instrumentos sem prazo (T5), lançamento/anulação/histórico (T6), `MAC→MT→MFD` e `null` sem 3 MT (T2, T7), mini-pauta e menu (T8). Fora de fase, conscientemente: exames, recurso, decisão, provisório/definitivo, pré-escolar, boletins.
- **Consistência:** `TipoInstrumento`, `EstadoNota`, `ModoArredondamento` (T1) e as assinaturas de `PrazoService` (T4) e `CalculadoraQuantitativa` (T2) são as usadas em T6–T8.
- **Pendentes que não bloqueiam:** casas decimais/modo de arredondamento por defeito (provisório 2 / `half_up`); regra "decide o valor arredondado" só é usada na Fase 2.
