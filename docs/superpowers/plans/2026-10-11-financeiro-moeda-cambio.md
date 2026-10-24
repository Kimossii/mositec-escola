# Financeiro — Moeda e Câmbio — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar o Kwanza e as "2 casas decimais" do código: a moeda passa a ser uma configuração da escola (registo ISO 4217 com casas decimais por moeda), com câmbio de referência em USD (padrão da plataforma, com modo manual por escola), guardado para snapshot futuro. Página própria `Configurações → Financeiro → Moeda e Câmbio`.

**Architecture:** `Moeda` é um registo imutável em código (código, nome, símbolo, casas). `Dinheiro` passa a ser um valor em **unidades menores** sem moeda embutida; parsing e formatação recebem a `Moeda`. A moeda da escola vive em `configuracoes_monetarias` (1:1 com o tenant) e é lida por `MoedaDoTenant`; só pode mudar se não houver preços (contrato `FonteDePrecos`) nem registos (contrato `ReferenciaFinanceira`). O câmbio tem duas fontes: `cambios_plataforma` (global, padrão) e `cambios` (por escola, só no modo manual); `CambioDoDia` devolve o último câmbio **até à data**, ou nulo (nunca bloqueia).

**Tech Stack:** Laravel + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11 com SQLite (produção pgsql), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (decisão 2.6, secção "Moeda e Câmbio", contrato 5.1, secção 6).

## Global Constraints

- **Nenhuma moeda, símbolo nem número de casas decimais fixos no código fora do registo `Moeda`** (e do seeder do câmbio padrão). Texto de UI, mensagens e comentários em PT-PT com acentos correctos; identificadores, rotas, tabelas e variáveis sem acento.
- Dinheiro: `bigInteger` em **unidades menores**, nunca float. Nomes: `Dinheiro::deUnidadesMenores(int)`, `unidadesMenores()`, `deDecimal(int|string, Moeda)`, `formatar(Moeda)`. Os nomes antigos (`deCentimos`, `centimos()`, `deKwanzas`, `ValorEmKwanzas`, `formatKz`, `centimosParaKz`) **deixam de existir** (não deixar aliases).
- `deDecimal` aceita inteiro ou texto `^\d{1,12}([.,]\d{1,N})?$` com `N` = casas da moeda (sem parte decimal se `N = 0`), sem separador de milhares, sem `\n` final (modificador `D`).
- Formatação: milhares com ".", decimais com ",", símbolo depois de um espaço (`25.000,00 Kz`, `25.000 ¥`, `25,125 KD`).
- Moeda: código ISO 4217 do registo `Moeda`; default `AOA`; **bloqueada** enquanto existirem preços de configuração (`FonteDePrecos`) ou registos financeiros (`ReferenciaFinanceira`); mudar para a mesma moeda é sempre permitido.
- Câmbio: moeda **base** (USD) e moeda **cotada** (a da escola), sentido **1 USD = X unidades da moeda cotada**; colunas `moeda_base` e `moeda_cotada`; inteiro escalado a 6 casas (`taxa × 1.000.000`), nunca float; `> 0`; uma linha por dia por par (upsert); moeda da escola = USD → taxa 1; **modo manual é estrito** (só a tabela da escola); sem câmbio → `null` e o sistema não bloqueia; data de registo não pode ser futura.
- Estado/tenancy: `PertenceAoTenant` nos models por escola; `cambios_plataforma` é global (sem tenant). Hooks `saving` null-safe. Unicidade por tenant.
- Permissões: `moeda-cambio.{ver,editar,criar,eliminar}` (`editar` = moeda e modo manual; `criar`/`eliminar` = linhas de câmbio).
- PHP: `use` no topo, nunca FQN inline. Controllers finos: leitura via Service, escrita via Action, com DTO e FormRequest.
- Testes em SQLite (sem JSON, sem CHECK por SQL cru). Produção é pgsql.
- **Menus: acrescentar SEMPRE nos dois sítios** — `resources/js/Composables/useConfiguracoesMenu.js` (`links/label`) e `resources/js/Components/Layout/SidebarMenuWrapper.vue` (`items/title`, array `configuracoesMenu`).
- A suite completa (`php artisan test`) é o critério de aceitação final.
- Aplicar migrations e seeds em bases existentes é do dono (`migrate`, `db:seed --force`, `financeiro:sincronizar --todos`).
- **Git: só `git add` (stage). Nunca `git commit` sem o dono pedir.**
- Fora deste plano: conversão entre moedas, facturação em várias moedas, API de câmbio (só o comando manual), internacionalização do idioma, fiscalidade, interface de câmbio da plataforma (só comando).

## Efeitos no plano 3 (aplicados pelo controlador depois de este plano acabar)

Planos de Propina passa a usar `ValorMonetario(false)` e `Dinheiro::deDecimal(..., moeda)`, `deUnidadesMenores`/`unidadesMenores()` nos testes e no `ConsultaService`, a prop `moeda` e os helpers `formatarDinheiro`/`unidadesMenoresParaDecimal` no frontend, e regista `PrecosDosPlanos` como `FonteDePrecos`. A Task 1 do plano 3 (`ValorEmKwanzas`) é absorvida pela Task 3 deste plano.

## Review Focus

1. Ida e volta exacta (`paraDecimal` ↔ `deDecimal`) em **todas** as moedas do registo, incluindo valores de 12 dígitos; sem float.
2. Zero `Kz`, `100`, `centimos` ou `kwanza` fixos fora do registo `Moeda`/seeder: `grep -rniE "kz|kwanza|centimo|\* 100|% 100" Modules/Financeiro/app Modules/Financeiro/resources` não devolve nada relevante — Task 3, 7.
3. Moedas de 0 e 3 casas: JPY rejeita `25000,5` e aceita `25000`; KWD aceita `25000,125` (= 25.000.125 unidades) e rejeita 4 casas; a formatação não inventa decimais — Tasks 1, 3.
4. Moeda bloqueada com produto, serviço, `FonteDePrecos` ou `ReferenciaFinanceira`; a mesma moeda é aceite com o bloqueio activo — Tasks 2, 6.
5. Câmbio (`moeda_base`/`moeda_cotada`): a restrição na BD impede duplicados por moeda e dia (violação provada), a unicidade da escola é por tenant; "último até à data" (uma taxa posterior não vale para trás), upsert por dia, modo manual estrito, USD = 1, moeda diferente ignorada, câmbios de outro tenant ignorados, sem câmbio = nulo — Task 5.
6. DELETE de câmbio de outro tenant dá 404 e nada muda; data futura e taxa inválida rejeitadas — Task 6.

## Mapa de ficheiros

```
Modules/Financeiro/
  app/Support/{Moeda,TaxaCambio,CambioResolvido,FontesDePrecos,PrecosDoCatalogo}.php   (T1,T2,T5)
  app/Support/Dinheiro.php, app/Casts/DinheiroCast.php                                 (T3, modificados)
  app/Rules/ValorMonetario.php (substitui ValorEmKwanzas), app/Rules/TaxaDeCambio.php   (T3, T5)
  app/Contracts/FonteDePrecos.php                                                      (T2)
  app/Models/{ConfiguracaoMonetaria,CambioPlataforma,Cambio}.php                       (T2, T5)
  app/Provisioning/ProvisionarConfiguracaoMonetaria.php                                (T2)
  app/Services/{MoedaDoTenant,CambioDoDia,GestaoMoedaCambioService,MoedaCambioConsultaService}.php (T2,T5,T6)
  app/Console/CambioPlataformaCommand.php                                              (T5)
  app/DTO/{ConfiguracaoMonetariaDTO,CambioDTO}.php                                     (T6)
  app/Http/Requests/{AtualizarConfiguracaoMonetaria,RegistarCambio}Request.php          (T6)
  app/Actions/{AtualizarConfiguracaoMonetaria,RegistarCambio,EliminarCambio}Action.php  (T6)
  app/Http/Controllers/MoedaCambioController.php                                       (T6)
  database/migrations/2026_10_11_{100000_create_configuracoes_monetarias,100100_create_cambios_plataforma,100200_create_cambios}_table.php
  database/seeders/CambioPlataformaSeeder.php                                          (T5)
  resources/js/Support/dinheiro.js (reescrito), Pages/MoedaCambio/Edit.vue              (T7)
Modificados: DTO/{Produto,Servico}DTO, Http/Requests/{Criar,Atualizar}{Produto,Servico}Request,
  Services/CatalogoConsultaService, Http/Controllers/CatalogoFinanceiroController,
  Providers/FinanceiroServiceProvider, Console/SincronizarFinanceiroCommand, routes/web.php,
  resources/js/{Pages/ProdutosServicos/Index.vue,Components/ProdutosServicos/CatalogoItemFormModal.vue},
  Modules/Permissao/{app/Enums/Modulo.php, database/seeders/ModuloSeeder.php, app/Actions/SincronizarPerfisDeSistemaAction.php, tests/Feature/ModuloSeederTest.php},
  database/seeders/DatabaseSeeder.php, tests/Feature/Provisioning/*,
  resources/js/Composables/useConfiguracoesMenu.js, resources/js/Components/Layout/SidebarMenuWrapper.vue
```

---

### Task 1: Registo `Moeda` (lógica pura)

**Files:**
- Create: `Modules/Financeiro/app/Support/Moeda.php`
- Test: `Modules/Financeiro/tests/Unit/MoedaTest.php`

**Interfaces:**
- Produces: `Moeda` (final, imutável) com `codigo`, `nome`, `simbolo`, `decimais` (públicos, readonly); `Moeda::de(string $codigo): self` (aceita minúsculas e espaços; lança `InvalidArgumentException` se desconhecida); `Moeda::existe(string $codigo): bool`; `Moeda::todas(): list<self>` (por código); `Moeda::opcoes(): list<array{value:string,label:string}>` (por código; label `AOA — Kwanza angolano (Kz)`); `fator(): int` (`10 ** decimais`); `paraFrontend(): array{codigo,nome,simbolo,decimais}`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Moeda;
use PHPUnit\Framework\TestCase;

class MoedaTest extends TestCase
{
    public function test_de_devolve_a_moeda_do_registo(): void
    {
        $moeda = Moeda::de('AOA');

        $this->assertSame('AOA', $moeda->codigo);
        $this->assertSame('Kwanza angolano', $moeda->nome);
        $this->assertSame('Kz', $moeda->simbolo);
        $this->assertSame(2, $moeda->decimais);
    }

    public function test_codigo_e_normalizado(): void
    {
        $this->assertSame('AOA', Moeda::de(' aoa ')->codigo);
        $this->assertTrue(Moeda::existe('usd'));
    }

    public function test_as_casas_decimais_variam_por_moeda(): void
    {
        $this->assertSame(2, Moeda::de('EUR')->decimais);
        $this->assertSame(2, Moeda::de('USD')->decimais);
        $this->assertSame(0, Moeda::de('XOF')->decimais);
        $this->assertSame(0, Moeda::de('JPY')->decimais);
        $this->assertSame(3, Moeda::de('KWD')->decimais);
        $this->assertSame(3, Moeda::de('BHD')->decimais);
    }

    public function test_fator(): void
    {
        $this->assertSame(1, Moeda::de('JPY')->fator());
        $this->assertSame(100, Moeda::de('AOA')->fator());
        $this->assertSame(1000, Moeda::de('KWD')->fator());
    }

    public function test_moeda_desconhecida_e_rejeitada(): void
    {
        $this->assertFalse(Moeda::existe('XXX'));
        $this->assertFalse(Moeda::existe(''));

        $this->expectException(InvalidArgumentException::class);

        Moeda::de('XXX');
    }

    public function test_o_registo_e_coerente(): void
    {
        $codigos = [];

        foreach (Moeda::todas() as $moeda) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $moeda->codigo);
            $this->assertGreaterThanOrEqual(0, $moeda->decimais);
            $this->assertLessThanOrEqual(3, $moeda->decimais);
            $this->assertNotSame('', $moeda->simbolo);
            $this->assertNotSame('', $moeda->nome);
            $codigos[] = $moeda->codigo;
        }

        $this->assertSame($codigos, array_values(array_unique($codigos)));
        $ordenados = $codigos;
        sort($ordenados);
        $this->assertSame($ordenados, $codigos);
        $this->assertContains('AOA', $codigos);
        $this->assertContains('MZN', $codigos);
        $this->assertContains('USD', $codigos);
        $this->assertContains('EUR', $codigos);
    }

    public function test_opcoes_e_para_frontend(): void
    {
        $opcoes = Moeda::opcoes();

        $this->assertContains(['value' => 'AOA', 'label' => 'AOA — Kwanza angolano (Kz)'], $opcoes);
        $this->assertSame(array_column($opcoes, 'value'), array_map(fn (Moeda $m) => $m->codigo, Moeda::todas()));
        $this->assertSame(
            ['codigo' => 'EUR', 'nome' => 'Euro', 'simbolo' => '€', 'decimais' => 2],
            Moeda::de('EUR')->paraFrontend(),
        );
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/MoedaTest.php`
Expected: FAIL (classe inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/Moeda.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Registo de moedas (ISO 4217). Ponto único onde se decide símbolo e número de casas decimais:
 * nada mais no sistema assume uma moeda. Para suportar outra moeda, acrescenta-se uma linha.
 */
final class Moeda
{
    /** código => [nome, símbolo, casas decimais] */
    private const REGISTO = [
        'AED' => ['Dirham dos Emirados', 'AED', 2],
        'AOA' => ['Kwanza angolano', 'Kz', 2],
        'ARS' => ['Peso argentino', 'AR$', 2],
        'AUD' => ['Dólar australiano', 'A$', 2],
        'BHD' => ['Dinar do Bahrein', 'BD', 3],
        'BRL' => ['Real brasileiro', 'R$', 2],
        'BWP' => ['Pula do Botsuana', 'P', 2],
        'CAD' => ['Dólar canadiano', 'CA$', 2],
        'CHF' => ['Franco suíço', 'CHF', 2],
        'CLP' => ['Peso chileno', 'CLP', 0],
        'CNY' => ['Yuan chinês', 'CN¥', 2],
        'CVE' => ['Escudo cabo-verdiano', 'Esc.', 2],
        'EGP' => ['Libra egípcia', 'E£', 2],
        'EUR' => ['Euro', '€', 2],
        'GBP' => ['Libra esterlina', '£', 2],
        'GHS' => ['Cedi ganês', 'GH₵', 2],
        'INR' => ['Rupia indiana', '₹', 2],
        'JPY' => ['Iene japonês', '¥', 0],
        'KES' => ['Xelim queniano', 'KSh', 2],
        'KWD' => ['Dinar do Kuwait', 'KD', 3],
        'MAD' => ['Dirham marroquino', 'DH', 2],
        'MXN' => ['Peso mexicano', 'MX$', 2],
        'MZN' => ['Metical moçambicano', 'MT', 2],
        'NAD' => ['Dólar namibiano', 'N$', 2],
        'NGN' => ['Naira nigeriana', '₦', 2],
        'OMR' => ['Rial de Omã', 'OMR', 3],
        'RUB' => ['Rublo russo', '₽', 2],
        'RWF' => ['Franco ruandês', 'FRw', 0],
        'SAR' => ['Rial saudita', 'SAR', 2],
        'STN' => ['Dobra de São Tomé e Príncipe', 'Db', 2],
        'TND' => ['Dinar tunisino', 'DT', 3],
        'TRY' => ['Lira turca', '₺', 2],
        'TZS' => ['Xelim tanzaniano', 'TSh', 2],
        'UGX' => ['Xelim ugandês', 'USh', 0],
        'USD' => ['Dólar americano', 'US$', 2],
        'XAF' => ['Franco CFA da África Central', 'F CFA', 0],
        'XOF' => ['Franco CFA da África Ocidental', 'F CFA', 0],
        'ZAR' => ['Rand sul-africano', 'R', 2],
    ];

    private function __construct(
        public readonly string $codigo,
        public readonly string $nome,
        public readonly string $simbolo,
        public readonly int $decimais,
    ) {
    }

    public static function de(string $codigo): self
    {
        $codigo = strtoupper(trim($codigo));

        if (! isset(self::REGISTO[$codigo])) {
            throw new InvalidArgumentException("Moeda desconhecida: {$codigo}.");
        }

        [$nome, $simbolo, $decimais] = self::REGISTO[$codigo];

        return new self($codigo, $nome, $simbolo, $decimais);
    }

    public static function existe(string $codigo): bool
    {
        return isset(self::REGISTO[strtoupper(trim($codigo))]);
    }

    /**
     * @return list<self>
     */
    public static function todas(): array
    {
        return array_map(fn (string $codigo) => self::de($codigo), array_keys(self::REGISTO));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $moeda) => ['value' => $moeda->codigo, 'label' => "{$moeda->codigo} — {$moeda->nome} ({$moeda->simbolo})"],
            self::todas(),
        );
    }

    /**
     * Unidades menores por unidade da moeda: 10 ** decimais.
     */
    public function fator(): int
    {
        return 10 ** $this->decimais;
    }

    /**
     * @return array{codigo: string, nome: string, simbolo: string, decimais: int}
     */
    public function paraFrontend(): array
    {
        return ['codigo' => $this->codigo, 'nome' => $this->nome, 'simbolo' => $this->simbolo, 'decimais' => $this->decimais];
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/MoedaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/Moeda.php Modules/Financeiro/tests/Unit/MoedaTest.php
```

---

### Task 2: Configuração monetária da escola, `MoedaDoTenant` e `FonteDePrecos`

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_11_100000_create_configuracoes_monetarias_table.php`
- Create: `Modules/Financeiro/app/Models/ConfiguracaoMonetaria.php`
- Create: `Modules/Financeiro/app/Provisioning/ProvisionarConfiguracaoMonetaria.php`
- Create: `Modules/Financeiro/app/Contracts/FonteDePrecos.php`, `Modules/Financeiro/app/Support/FontesDePrecos.php`, `Modules/Financeiro/app/Support/PrecosDoCatalogo.php`
- Create: `Modules/Financeiro/app/Services/MoedaDoTenant.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`, `Modules/Financeiro/app/Console/SincronizarFinanceiroCommand.php`
- Modify (testes existentes, só contagens/ordens): `tests/Feature/Provisioning/ProvisioningArquitecturaTest.php`, `tests/Feature/Provisioning/ProvisionadoresTest.php`, `tests/Feature/Provisioning/CriarTenantActionTest.php`
- Test: `Modules/Financeiro/tests/Feature/{ConfiguracaoMonetariaTest,MoedaDoTenantTest,FontesDePrecosTest}.php`

**Interfaces:**
- Consumes: `Moeda` (T1), `ReferenciasFinanceiras`, `Produto`, `Servico`, `ProvisionaTenant`.
- Produces:
  - `ConfiguracaoMonetaria` (`moeda` string, `cambio_manual` bool) com `static doTenant(): self` e `DEFAULTS = ['moeda' => 'AOA', 'cambio_manual' => false]`;
  - provisionador de ordem 60 e criação no comando `financeiro:sincronizar`;
  - `interface FonteDePrecos { public function existemPrecos(): bool; }`; `FontesDePrecos` (constante `ETIQUETA = 'financeiro.fontes-de-precos'`) com `existem(): bool`; `PrecosDoCatalogo` (verdadeiro se existir algum `Produto` ou `Servico`), registado com a etiqueta;
  - `MoedaDoTenant` com `configuracao(): ConfiguracaoMonetaria`, `atual(): Moeda`, `podeAlterar(): bool` (falso se `FontesDePrecos::existem()` ou `ReferenciasFinanceiras::existeReferenciaA($configuracao)`).

Nota: os testes desta Task usam a API actual de `Dinheiro` (`Dinheiro::deCentimos`); a Task 3 renomeia-a em todo o módulo, incluindo estes testes.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Feature/ConfiguracaoMonetariaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Tests\TestCase;

class ConfiguracaoMonetariaTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_tenant_cria_com_os_defaults(): void
    {
        $configuracao = ConfiguracaoMonetaria::doTenant();

        $this->assertSame($this->tenant->id, $configuracao->tenant_id);
        $this->assertSame('AOA', $configuracao->moeda);
        $this->assertFalse($configuracao->cambio_manual);
    }

    public function test_do_tenant_e_idempotente(): void
    {
        $primeira = ConfiguracaoMonetaria::doTenant();
        $segunda = ConfiguracaoMonetaria::doTenant();

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, ConfiguracaoMonetaria::count());
    }

    public function test_a_bd_impede_uma_segunda_configuracao_no_mesmo_tenant(): void
    {
        ConfiguracaoMonetaria::doTenant();

        $this->expectException(QueryException::class);

        ConfiguracaoMonetaria::create(ConfiguracaoMonetaria::DEFAULTS);
    }

    public function test_cada_tenant_tem_a_sua_moeda(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'MZN']);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $doOutro = $this->noTenant($outro, fn () => ConfiguracaoMonetaria::doTenant());

        $this->assertSame('AOA', $doOutro->moeda);
        $this->assertSame($outro->id, $doOutro->tenant_id);
        $this->assertSame('MZN', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_tenant_id_nao_e_exposto(): void
    {
        $this->assertArrayNotHasKey('tenant_id', ConfiguracaoMonetaria::doTenant()->toArray());
    }
}
```

`Modules/Financeiro/tests/Feature/FontesDePrecosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Support\FontesDePrecos;
use Tests\TestCase;

class FontesDePrecosTest extends TestCase
{
    use RefreshDatabase;

    private function fonte(bool $resposta): FonteDePrecos
    {
        return new class($resposta) implements FonteDePrecos {
            public function __construct(private bool $resposta)
            {
            }

            public function existemPrecos(): bool
            {
                return $this->resposta;
            }
        };
    }

    private function registar(string $nome, FonteDePrecos $fonte): void
    {
        $this->app->instance($nome, $fonte);
        $this->app->tag([$nome], FontesDePrecos::ETIQUETA);
    }

    public function test_sem_fontes_nao_ha_precos(): void
    {
        $this->assertFalse(app(FontesDePrecos::class)->existem());
    }

    public function test_todas_negam_nao_ha_precos(): void
    {
        $this->registar('fonte.a', $this->fonte(false));
        $this->registar('fonte.b', $this->fonte(false));

        $this->assertFalse(app(FontesDePrecos::class)->existem());
    }

    public function test_basta_uma_afirmar_para_haver_precos(): void
    {
        $this->registar('fonte.a', $this->fonte(false));
        $this->registar('fonte.b', $this->fonte(true));

        $this->assertTrue(app(FontesDePrecos::class)->existem());
    }
}
```
`Modules/Financeiro/tests/Feature/MoedaDoTenantTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Tests\TestCase;

class MoedaDoTenantTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): MoedaDoTenant
    {
        return app(MoedaDoTenant::class);
    }

    public function test_a_moeda_por_defeito_e_o_kwanza_com_duas_casas(): void
    {
        $moeda = $this->servico()->atual();

        $this->assertSame('AOA', $moeda->codigo);
        $this->assertSame(2, $moeda->decimais);
    }

    public function test_acompanha_a_configuracao(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->assertSame(0, $this->servico()->atual()->decimais);
    }

    public function test_pode_alterar_quando_nao_ha_nada_configurado(): void
    {
        $this->assertTrue($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_produto(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deCentimos(100)]);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_servico(): void
    {
        Servico::create(['nome' => 'S', 'preco' => Dinheiro::deCentimos(100)]);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_outra_fonte_de_precos(): void
    {
        $this->app->instance('fonte.fake', new class implements FonteDePrecos {
            public function existemPrecos(): bool
            {
                return true;
            }
        });
        $this->app->tag(['fonte.fake'], FontesDePrecos::ETIQUETA);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_referencia_financeira(): void
    {
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return $configuracao instanceof ConfiguracaoMonetaria;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_produtos_de_outro_tenant_nao_bloqueiam(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => Produto::create(['nome' => 'P', 'preco' => Dinheiro::deCentimos(100)]));

        $this->assertTrue($this->servico()->podeAlterar());
    }
}
```

`Modules/Financeiro/tests/Feature/ConfiguracaoMonetariaProvisioningTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Financeiro\Provisioning\ProvisionarConfiguracaoMonetaria;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class ConfiguracaoMonetariaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisionador_esta_registado_com_a_ordem_60(): void
    {
        $provisionadores = collect(app()->tagged(ProvisionaTenant::ETIQUETA));
        $ours = $provisionadores->first(fn ($p) => $p instanceof ProvisionarConfiguracaoMonetaria);

        $this->assertNotNull($ours);
        $this->assertSame(60, $ours->ordem());
        $this->assertSame(
            $provisionadores->count(),
            $provisionadores->map(fn (ProvisionaTenant $p) => $p->ordem())->unique()->count(),
        );
    }

    public function test_provisionar_duas_vezes_nao_duplica(): void
    {
        $dados = new DadosProvisionamento('Escola', 'Admin', 'admin@example.com');
        $provisionador = app(ProvisionarConfiguracaoMonetaria::class);

        $provisionador->provisionar($this->tenant->paraTenantAtual(), $dados);
        $provisionador->provisionar($this->tenant->paraTenantAtual(), $dados);

        $this->assertSame(1, DB::table('configuracoes_monetarias')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_comando_cria_a_configuracao_em_falta_de_forma_idempotente(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->assertSame(0, DB::table('configuracoes_monetarias')->count());

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(1, DB::table('configuracoes_monetarias')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('AOA', DB::table('configuracoes_monetarias')->value('moeda'));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ConfiguracaoMonetariaTest.php Modules/Financeiro/tests/Feature/MoedaDoTenantTest.php Modules/Financeiro/tests/Feature/FontesDePrecosTest.php Modules/Financeiro/tests/Feature/ConfiguracaoMonetariaProvisioningTest.php`
Expected: FAIL (tabela, models, contratos e serviços inexistentes).

- [ ] **Step 3: Implementar**

Migration `2026_10_11_100000_create_configuracoes_monetarias_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_monetarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('moeda', 3)->default('AOA');
            $table->boolean('cambio_manual')->default(false);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_monetarias');
    }
};
```

`Modules/Financeiro/app/Models/ConfiguracaoMonetaria.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/**
 * Configuração monetária da escola (1:1 com o tenant): a moeda em que opera e se usa câmbio
 * próprio. A moeda só pode mudar enquanto não houver preços nem registos (ver MoedaDoTenant).
 */
class ConfiguracaoMonetaria extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    public const DEFAULTS = [
        'moeda' => 'AOA',
        'cambio_manual' => false,
    ];

    protected $table = 'configuracoes_monetarias';

    protected $fillable = [
        'moeda',
        'cambio_manual',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'cambio_manual' => 'boolean',
    ];

    /**
     * A configuração do tenant corrente; cria-a com os defaults se ainda não existir.
     */
    public static function doTenant(): self
    {
        return static::firstOrCreate([], static::DEFAULTS);
    }
}
```

`Modules/Financeiro/app/Provisioning/ProvisionarConfiguracaoMonetaria.php`:
```php
<?php

namespace Modules\Financeiro\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;

/**
 * Ordem 60: configuração monetária com os defaults. Idempotente.
 */
class ProvisionarConfiguracaoMonetaria implements ProvisionaTenant
{
    public function ordem(): int
    {
        return 60;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        ConfiguracaoMonetaria::doTenant();
    }
}
```

`Modules/Financeiro/app/Contracts/FonteDePrecos.php`:
```php
<?php

namespace Modules\Financeiro\Contracts;

/**
 * Implementada por quem guarda PREÇOS de configuração (catálogo, planos de propina…). Enquanto
 * alguma devolver true, a moeda da escola não pode mudar: os valores seriam reinterpretados sem
 * conversão. Registo no container: $this->app->tag([Classe::class], FontesDePrecos::ETIQUETA).
 */
interface FonteDePrecos
{
    public function existemPrecos(): bool;
}
```

`Modules/Financeiro/app/Support/FontesDePrecos.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Financeiro\Contracts\FonteDePrecos;

class FontesDePrecos
{
    public const ETIQUETA = 'financeiro.fontes-de-precos';

    public function __construct(private Container $container)
    {
    }

    public function existem(): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            /** @var FonteDePrecos $fonte */
            if ($fonte->existemPrecos()) {
                return true;
            }
        }

        return false;
    }
}
```

`Modules/Financeiro/app/Support/PrecosDoCatalogo.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;

class PrecosDoCatalogo implements FonteDePrecos
{
    public function existemPrecos(): bool
    {
        return Produto::query()->exists() || Servico::query()->exists();
    }
}
```

`Modules/Financeiro/app/Services/MoedaDoTenant.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

/**
 * A moeda da escola corrente. Ponto único para saber em que moeda se interpretam, validam e
 * formatam os valores.
 */
class MoedaDoTenant
{
    public function __construct(
        private FontesDePrecos $fontesDePrecos,
        private ReferenciasFinanceiras $referencias,
    ) {
    }

    public function configuracao(): ConfiguracaoMonetaria
    {
        return ConfiguracaoMonetaria::doTenant();
    }

    public function atual(): Moeda
    {
        return Moeda::de($this->configuracao()->moeda);
    }

    /**
     * A moeda só pode mudar se não existirem preços de configuração nem registos financeiros.
     */
    public function podeAlterar(): bool
    {
        return ! $this->fontesDePrecos->existem()
            && ! $this->referencias->existeReferenciaA($this->configuracao());
    }
}
```

`FinanceiroServiceProvider::register()`: trocar o `tag` do provisionador por dois (mantendo `parent::register()`) e registar a fonte de preços do catálogo; acrescentar os `use`:
```php
use Modules\Financeiro\Provisioning\ProvisionarConfiguracaoMonetaria;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PrecosDoCatalogo;
```
```php
        $this->app->tag([
            ProvisionarRegrasCobranca::class,
            ProvisionarConfiguracaoMonetaria::class,
        ], ProvisionaTenant::ETIQUETA);
        $this->app->tag([PrecosDoCatalogo::class], FontesDePrecos::ETIQUETA);
```

`SincronizarFinanceiroCommand::handle`: dentro do closure, depois de `RegraCobranca::doTenant();`, acrescentar `ConfiguracaoMonetaria::doTenant();` (com `use Modules\Financeiro\Models\ConfiguracaoMonetaria;`) e actualizar a descrição para "…as regras de cobrança, a configuração monetária e as permissões…".

Testes existentes a ajustar apenas nas contagens/ordens que o novo provisionador altera (documentar no relatório quais): `ProvisioningArquitecturaTest` (nº de provisionadores 5 → 6), `ProvisionadoresTest` (acrescentar a ordem 60 à asserção e ao nome do teste) e `CriarTenantActionTest::contagens()` (acrescentar `'configuracoes_monetarias' => DB::table('configuracoes_monetarias')->count()`).

- [ ] **Step 4: Correr e ver passar**

Run: `composer dump-autoload && php artisan test Modules/Financeiro tests/Feature/Provisioning tests/Feature/Tenancy`
Expected: PASS (inclui a matriz de isolamento, que passa a exercitar `ConfiguracaoMonetaria` "em cru").

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro tests/Feature/Provisioning
```

---

### Task 3: `Dinheiro` generalizado, renomeação e regra `ValorMonetario`

**Files:**
- Modify: `Modules/Financeiro/app/Support/Dinheiro.php`, `Modules/Financeiro/app/Casts/DinheiroCast.php`
- Create (por `git mv` + edição): `Modules/Financeiro/app/Rules/ValorMonetario.php` (de `ValorEmKwanzas.php`), `Modules/Financeiro/tests/Feature/ValorMonetarioTest.php` (de `ValorEmKwanzasTest.php`)
- Modify: `Modules/Financeiro/app/DTO/{ProdutoDTO,ServicoDTO}.php`, `Modules/Financeiro/app/Http/Requests/{CriarProduto,AtualizarProduto,CriarServico,AtualizarServico}Request.php`, `Modules/Financeiro/app/Services/CatalogoConsultaService.php`
- Modify (renomeação mecânica nos testes): `Modules/Financeiro/tests/Unit/DinheiroCastTest.php`, `Modules/Financeiro/tests/Feature/{ProdutoTest,ServicoTest,CatalogoFinanceiroTest}.php` e todos os testes do módulo que usem os nomes antigos
- Rewrite: `Modules/Financeiro/tests/Unit/DinheiroTest.php`

**Interfaces:**
- Consumes: `Moeda` (T1), `MoedaDoTenant` (T2).
- Produces: `Dinheiro::deUnidadesMenores(int): self`, `Dinheiro::deDecimal(int|string, Moeda): self`, `unidadesMenores(): int`, `somar`, `subtrair`, `percentagem`, `dividir`, `formatar(Moeda): string`; `DinheiroCast` (`get` → `Dinheiro`, `set` aceita `Dinheiro`, `int` ≥ 0 ou `null`, `serialize` → `?int` em unidades menores); `new ValorMonetario(bool $permiteZero = true)` (valida com a moeda da escola e mensagens que dizem quantas casas a moeda tem).

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Unit/DinheiroTest.php` (substituir o ficheiro inteiro):
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_rejeita_valor_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(-1);
    }

    public function test_somar_e_subtrair(): void
    {
        $a = Dinheiro::deUnidadesMenores(2_500_000);
        $b = Dinheiro::deUnidadesMenores(100);

        $this->assertSame(2_500_100, $a->somar($b)->unidadesMenores());
        $this->assertSame(2_499_900, $a->subtrair($b)->unidadesMenores());
    }

    public function test_subtrair_nunca_fica_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->subtrair(Dinheiro::deUnidadesMenores(101));
    }

    public function test_percentagem_arredonda_para_baixo(): void
    {
        // 10% de 25.333,33 = 2.533,333 -> 2.533,33
        $this->assertSame(253_333, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(10)->unidadesMenores());
        $this->assertSame(0, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(0)->unidadesMenores());
        $this->assertSame(2_533_333, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(100)->unidadesMenores());
    }

    public function test_percentagem_fora_da_faixa_e_rejeitada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->percentagem(101);
    }

    public function test_dividir_a_ultima_parcela_absorve_o_resto(): void
    {
        $partes = Dinheiro::deUnidadesMenores(2_500_000)->dividir(3);

        $this->assertSame([833_333, 833_333, 833_334], array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $partes));
        $this->assertSame(2_500_000, array_sum(array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $partes)));
    }

    public function test_dividir_por_menos_de_uma_parte_e_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->dividir(0);
    }

    public function test_formatar_usa_as_casas_e_o_simbolo_da_moeda(): void
    {
        $this->assertSame('25.000,00 Kz', Dinheiro::deUnidadesMenores(2_500_000)->formatar(Moeda::de('AOA')));
        $this->assertSame('0,05 Kz', Dinheiro::deUnidadesMenores(5)->formatar(Moeda::de('AOA')));
        $this->assertSame('1.234.567,89 Kz', Dinheiro::deUnidadesMenores(123_456_789)->formatar(Moeda::de('AOA')));
        $this->assertSame('1.234,56 €', Dinheiro::deUnidadesMenores(123_456)->formatar(Moeda::de('EUR')));
        $this->assertSame('25.000 ¥', Dinheiro::deUnidadesMenores(25_000)->formatar(Moeda::de('JPY')));
        $this->assertSame('0 ¥', Dinheiro::deUnidadesMenores(0)->formatar(Moeda::de('JPY')));
        $this->assertSame('25,125 KD', Dinheiro::deUnidadesMenores(25_125)->formatar(Moeda::de('KWD')));
        $this->assertSame('0,005 KD', Dinheiro::deUnidadesMenores(5)->formatar(Moeda::de('KWD')));
    }

    public function test_para_decimal_e_o_inverso_exacto_de_de_decimal_em_todas_as_moedas(): void
    {
        $valores = [0, 1, 5, 9, 10, 99, 100, 101, 999, 1_000, 123_456, 999_999, 1_000_000_007, 999_999_999_999];

        foreach (Moeda::todas() as $moeda) {
            foreach ($valores as $unidades) {
                $texto = Dinheiro::deUnidadesMenores($unidades)->paraDecimal($moeda);

                $this->assertSame(
                    $unidades,
                    Dinheiro::deDecimal($texto, $moeda)->unidadesMenores(),
                    "{$moeda->codigo}: {$unidades} -> '{$texto}'",
                );
            }
        }
    }

    public function test_para_decimal_tem_exactamente_as_casas_da_moeda(): void
    {
        $this->assertSame('25000.50', Dinheiro::deUnidadesMenores(2_500_050)->paraDecimal(Moeda::de('AOA')));
        $this->assertSame('0.05', Dinheiro::deUnidadesMenores(5)->paraDecimal(Moeda::de('AOA')));
        $this->assertSame('25000', Dinheiro::deUnidadesMenores(25_000)->paraDecimal(Moeda::de('JPY')));
        $this->assertSame('25.125', Dinheiro::deUnidadesMenores(25_125)->paraDecimal(Moeda::de('KWD')));
        $this->assertSame('0.005', Dinheiro::deUnidadesMenores(5)->paraDecimal(Moeda::de('KWD')));
    }

    public function test_formatar_respeita_as_casas_de_cada_moeda_do_registo(): void
    {
        foreach (Moeda::todas() as $moeda) {
            $formatado = Dinheiro::deUnidadesMenores(1_234_567)->formatar($moeda);
            $casas = $moeda->decimais === 0 ? '' : ',\d{' . $moeda->decimais . '}';

            $this->assertMatchesRegularExpression('/^\d{1,3}(\.\d{3})*' . $casas . ' \S+/u', $formatado, "{$moeda->codigo}: {$formatado}");
        }
    }

    public function test_percentagem_e_divisao_continuam_exactas_com_valores_grandes(): void
    {
        $grande = Dinheiro::deUnidadesMenores(999_999_999_999);

        $this->assertSame(999_999_999_999, $grande->percentagem(100)->unidadesMenores());
        $this->assertSame(499_999_999_999, $grande->percentagem(50)->unidadesMenores());
        $this->assertSame(999_999_999_999, array_sum(array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $grande->dividir(7))));
    }

    #[DataProvider('conversoesValidas')]
    public function test_de_decimal_converte_conforme_a_moeda(string $moeda, int|string $valor, int $esperado): void
    {
        $this->assertSame($esperado, Dinheiro::deDecimal($valor, Moeda::de($moeda))->unidadesMenores());
    }

    public static function conversoesValidas(): array
    {
        return [
            'AOA inteiro' => ['AOA', 25_000, 2_500_000],
            'AOA texto inteiro' => ['AOA', '25000', 2_500_000],
            'AOA uma casa' => ['AOA', '25000.5', 2_500_050],
            'AOA virgula' => ['AOA', '25000,50', 2_500_050],
            'AOA cêntimos' => ['AOA', '0.05', 5],
            'AOA zero' => ['AOA', '0', 0],
            'JPY inteiro' => ['JPY', 25_000, 25_000],
            'JPY texto' => ['JPY', '25000', 25_000],
            'KWD três casas' => ['KWD', '25000,125', 25_000_125],
            'KWD uma casa' => ['KWD', '1,5', 1_500],
        ];
    }

    #[DataProvider('conversoesInvalidas')]
    public function test_de_decimal_rejeita_valores_invalidos(string $moeda, int|string $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deDecimal($valor, Moeda::de($moeda));
    }

    public static function conversoesInvalidas(): array
    {
        return [
            'AOA vazio' => ['AOA', ''],
            'AOA negativo texto' => ['AOA', '-1'],
            'AOA negativo inteiro' => ['AOA', -1],
            'AOA letras' => ['AOA', 'abc'],
            'AOA três casas' => ['AOA', '12.345'],
            'AOA milhares' => ['AOA', '25.000,50'],
            'AOA notação científica' => ['AOA', '1e3'],
            'AOA espaços' => ['AOA', '25 000'],
            'AOA quebra de linha final' => ['AOA', "25000\n"],
            'AOA demasiado grande' => ['AOA', '1234567890123'],
            'JPY com decimais' => ['JPY', '25000,5'],
            'JPY ponto decimal' => ['JPY', '25000.0'],
            'KWD quatro casas' => ['KWD', '12,3456'],
        ];
    }
}
```

`Modules/Financeiro/tests/Feature/ValorMonetarioTest.php` (substitui `ValorEmKwanzasTest.php`):
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Rules\ValorMonetario;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValorMonetarioTest extends TestCase
{
    use RefreshDatabase;

    private function passa(mixed $valor, bool $permiteZero = true): bool
    {
        return Validator::make(['v' => $valor], ['v' => ['required', new ValorMonetario($permiteZero)]])->passes();
    }

    private function mensagem(mixed $valor, bool $permiteZero = true): string
    {
        return Validator::make(['v' => $valor], ['v' => [new ValorMonetario($permiteZero)]])->errors()->first('v');
    }

    #[DataProvider('aceitesEmAoa')]
    public function test_aoa_aceita_valores_validos(mixed $valor): void
    {
        $this->assertTrue($this->passa($valor));
    }

    public static function aceitesEmAoa(): array
    {
        return [
            'inteiro' => [25000],
            'texto inteiro' => ['25000'],
            'uma casa' => ['25000.5'],
            'virgula' => ['25000,50'],
            'float' => [25000.5],
            'zero texto' => ['0'],
            'zero inteiro' => [0],
        ];
    }

    #[DataProvider('rejeitadosEmAoa')]
    public function test_aoa_rejeita_valores_invalidos(mixed $valor): void
    {
        $this->assertFalse($this->passa($valor));
    }

    public static function rejeitadosEmAoa(): array
    {
        return [
            'negativo' => ['-1'],
            'negativo inteiro' => [-1],
            'letras' => ['abc'],
            'três casas' => ['12.345'],
            'milhares' => ['25.000,50'],
            'notação científica' => ['1e3'],
            'espaços' => ['25 000'],
            'quebra de linha final' => ["25000\n"],
            'lista' => [[1]],
            'demasiado grande' => ['1234567890123'],
        ];
    }

    public function test_sem_zero_rejeita_apenas_o_zero(): void
    {
        $this->assertFalse($this->passa('0', false));
        $this->assertFalse($this->passa(0, false));
        $this->assertFalse($this->passa('0,00', false));
        $this->assertTrue($this->passa('0,01', false));
        $this->assertTrue($this->passa('25000', false));
    }

    public function test_moeda_sem_casas_decimais_so_aceita_inteiros(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->assertTrue($this->passa('25000'));
        $this->assertTrue($this->passa(25000));
        $this->assertFalse($this->passa('25000,5'));
        $this->assertFalse($this->passa('25000.0'));
    }

    public function test_moeda_de_tres_casas_aceita_ate_tres(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'KWD']);

        $this->assertTrue($this->passa('25000,125'));
        $this->assertFalse($this->passa('25000,1255'));
    }

    public function test_mensagens_dizem_quantas_casas_a_moeda_tem(): void
    {
        $this->assertSame('O valor é inválido: use dígitos com até 2 casas decimais (ex.: 25000 ou 25000,50).', $this->mensagem('abc'));
        $this->assertSame('O valor tem de ser superior a zero.', $this->mensagem('0', false));

        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);
        $this->assertSame('O valor é inválido: use apenas dígitos inteiros (ex.: 25000).', $this->mensagem('25000,5'));

        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'KWD']);
        $this->assertSame('O valor é inválido: use dígitos com até 3 casas decimais (ex.: 25000 ou 25000,500).', $this->mensagem('abc'));
    }
}
```
- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DinheiroTest.php Modules/Financeiro/tests/Feature/ValorMonetarioTest.php`
Expected: FAIL (API nova inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/Dinheiro.php` (substituir o ficheiro inteiro):
```php
<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Valor monetário em UNIDADES MENORES da moeda (cêntimos, ou a própria unidade em moedas sem
 * casas decimais). Não conhece a moeda: parsing e formatação recebem-na. Nunca usa float.
 * Regra de arredondamento única do sistema: percentagens arredondam para baixo e, na divisão
 * por N partes, a última absorve o resto (a soma é sempre exacta).
 */
final class Dinheiro
{
    private function __construct(private readonly int $unidadesMenores)
    {
    }

    public static function deUnidadesMenores(int $unidadesMenores): self
    {
        if ($unidadesMenores < 0) {
            throw new InvalidArgumentException('O valor monetário não pode ser negativo.');
        }

        return new self($unidadesMenores);
    }

    /**
     * Converte um valor decimal da moeda (inteiro, ou texto com "." ou "," e até N casas, N = casas
     * da moeda; sem separador de milhares) em unidades menores, sem passar por float.
     */
    public static function deDecimal(int|string $valor, Moeda $moeda): self
    {
        if (is_int($valor)) {
            return self::deUnidadesMenores($valor * $moeda->fator());
        }

        $padrao = $moeda->decimais === 0
            ? '/^(\d{1,12})$/D'
            : '/^(\d{1,12})(?:[.,](\d{1,' . $moeda->decimais . '}))?$/D';

        if (! preg_match($padrao, $valor, $partes)) {
            throw new InvalidArgumentException("Valor monetário inválido para {$moeda->codigo}.");
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], $moeda->decimais, '0') : 0;

        return self::deUnidadesMenores(((int) $partes[1]) * $moeda->fator() + $fraccao);
    }

    public function unidadesMenores(): int
    {
        return $this->unidadesMenores;
    }

    public function somar(self $outro): self
    {
        return self::deUnidadesMenores($this->unidadesMenores + $outro->unidadesMenores);
    }

    public function subtrair(self $outro): self
    {
        return self::deUnidadesMenores($this->unidadesMenores - $outro->unidadesMenores);
    }

    public function percentagem(int $percentagem): self
    {
        if ($percentagem < 0 || $percentagem > 100) {
            throw new InvalidArgumentException('A percentagem tem de estar entre 0 e 100.');
        }

        return new self(intdiv($this->unidadesMenores * $percentagem, 100));
    }

    /**
     * @return list<self>
     */
    public function dividir(int $partes): array
    {
        if ($partes < 1) {
            throw new InvalidArgumentException('É preciso dividir por pelo menos uma parte.');
        }

        $base = intdiv($this->unidadesMenores, $partes);
        $resultado = array_fill(0, $partes - 1, new self($base));
        $resultado[] = new self($this->unidadesMenores - $base * ($partes - 1));

        return $resultado;
    }

    /**
     * Texto canónico para <input> e exportações: ponto decimal, sem milhares ("25000.50", "25000").
     * É o inverso exacto de deDecimal().
     */
    public function paraDecimal(Moeda $moeda): string
    {
        $fator = $moeda->fator();
        $inteira = intdiv($this->unidadesMenores, $fator);

        if ($moeda->decimais === 0) {
            return (string) $inteira;
        }

        return $inteira . '.' . str_pad((string) ($this->unidadesMenores % $fator), $moeda->decimais, '0', STR_PAD_LEFT);
    }

    /**
     * Milhares com ".", decimais com "," e o símbolo da moeda depois de um espaço.
     */
    public function formatar(Moeda $moeda): string
    {
        $fator = $moeda->fator();
        $milhares = number_format(intdiv($this->unidadesMenores, $fator), 0, ',', '.');

        if ($moeda->decimais === 0) {
            return "{$milhares} {$moeda->simbolo}";
        }

        $fraccao = str_pad((string) ($this->unidadesMenores % $fator), $moeda->decimais, '0', STR_PAD_LEFT);

        return "{$milhares},{$fraccao} {$moeda->simbolo}";
    }
}
```

`Modules/Financeiro/app/Casts/DinheiroCast.php`: aplicar `deCentimos` → `deUnidadesMenores` e `->centimos()` → `->unidadesMenores()` (em `get`, `set` e `serialize`); o resto fica igual.

Regra: `git mv Modules/Financeiro/app/Rules/ValorEmKwanzas.php Modules/Financeiro/app/Rules/ValorMonetario.php` e substituir o conteúdo por:
```php
<?php

namespace Modules\Financeiro\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Valida um montante vindo de um formulário na MOEDA DA ESCOLA (casas decimais incluídas).
 * Ponto único da regra: Planos, Produtos e Serviços usam-na em vez de repetir o regex.
 */
class ValorMonetario implements ValidationRule
{
    public function __construct(private bool $permiteZero = true)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            $fail($this->mensagemInvalido($moeda->decimais));

            return;
        }

        try {
            $dinheiro = Dinheiro::deDecimal(is_int($value) ? $value : (string) $value, $moeda);
        } catch (InvalidArgumentException) {
            $fail($this->mensagemInvalido($moeda->decimais));

            return;
        }

        if (! $this->permiteZero && $dinheiro->unidadesMenores() === 0) {
            $fail('O valor tem de ser superior a zero.');
        }
    }

    private function mensagemInvalido(int $decimais): string
    {
        if ($decimais === 0) {
            return 'O valor é inválido: use apenas dígitos inteiros (ex.: 25000).';
        }

        $exemplo = '25000,' . str_pad('5', $decimais, '0');

        return "O valor é inválido: use dígitos com até {$decimais} casas decimais (ex.: 25000 ou {$exemplo}).";
    }
}
```
`git mv Modules/Financeiro/tests/Feature/ValorEmKwanzasTest.php Modules/Financeiro/tests/Feature/ValorMonetarioTest.php` e substituir o conteúdo pelo do Step 1.

DTOs (`ProdutoDTO`, `ServicoDTO`): `preco: Dinheiro::deDecimal((string) $dados['preco'], app(MoedaDoTenant::class)->atual())` (com `use Modules\Financeiro\Services\MoedaDoTenant;`).

Os 4 Requests de Produto e Serviço: `new ValorEmKwanzas()` → `new ValorMonetario()`, e o `use`.

`CatalogoConsultaService`: `'preco' => $item->preco->unidadesMenores()`.

Renomeação mecânica (todos os ficheiros PHP em `Modules/Financeiro` e em `tests/`): `deCentimos(` → `deUnidadesMenores(` e `->centimos()` → `->unidadesMenores()`. Comando sugerido (verificar o resultado com `git diff`):
```bash
grep -rlE "deCentimos|centimos\(\)" Modules tests --include='*.php' | xargs sed -i 's/deCentimos(/deUnidadesMenores(/g; s/->centimos()/->unidadesMenores()/g'
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro tests/Feature/Tenancy`
Expected: PASS. Em seguida, **verificação de limpeza** (tem de não devolver nada):
```bash
grep -rnE "deCentimos|centimos\(\)|deKwanzas|ValorEmKwanzas" Modules tests --include='*.php'
```
e `grep -rn "Kz" Modules/Financeiro/app --include='*.php'` só pode devolver a linha da tabela de `Moeda`.

- [ ] **Step 5: Stage**

```bash
git add -A Modules/Financeiro tests
```
(`-A` restrito a estes dois caminhos, para incluir os `git mv`; não fazer stage de mais nada.)

---

### Task 4: Permissão `moeda-cambio`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (`MOEDA_CAMBIO = 21`), `Modules/Permissao/database/seeders/ModuloSeeder.php`, `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`, `Modules/Permissao/tests/Feature/ModuloSeederTest.php` (`21` passa a `22`)
- Test: `Modules/Financeiro/tests/Feature/PermissoesMoedaCambioTest.php`

**Interfaces:**
- Produces: abilities `moeda-cambio.{ver,criar,editar,eliminar}` concedidas só ao `ADMIN_ESCOLA`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesMoedaCambioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_e_label(): void
    {
        $this->assertSame('moeda-cambio', Modulo::MOEDA_CAMBIO->slug());
        $this->assertSame(Modulo::MOEDA_CAMBIO, Modulo::fromSlug('moeda-cambio'));
        $this->assertSame('Moeda e Câmbio', Modulo::MOEDA_CAMBIO->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("moeda-cambio.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem_a_nenhuma_accao(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
                $this->assertFalse(Gate::forUser($user)->allows("moeda-cambio.{$acao}"), "{$perfil->name} {$acao}");
            }
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesMoedaCambioTest.php`
Expected: FAIL (`Modulo::MOEDA_CAMBIO` inexistente).

- [ ] **Step 3: Implementar**

Em `Modules/Permissao/app/Enums/Modulo.php`: depois de `case PLANO_PROPINA = 20;` acrescentar `case MOEDA_CAMBIO = 21;`; em `slug()`: `self::MOEDA_CAMBIO => 'moeda-cambio',`; em `label()`: `self::MOEDA_CAMBIO => 'Moeda e Câmbio',`.
Em `ModuloSeeder.php`, depois da linha do `20`: `['nome' => 21, 'descricao' => 'Moeda e Cambio'],`.
Em `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL`, bloco ADMIN_ESCOLA, depois de `Modulo::PLANO_PROPINA->value => [...]`: `Modulo::MOEDA_CAMBIO->value => ['ver', 'criar', 'editar', 'eliminar'],`.
Em `ModuloSeederTest.php` trocar `assertSame(21,` por `assertSame(22,`.

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesMoedaCambioTest.php Modules/Permissao`
Expected: PASS. Se outro teste existente falhar só por assumir 21 módulos, corrigi-lo minimamente e dizê-lo no relatório.

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao Modules/Financeiro/tests/Feature/PermissoesMoedaCambioTest.php
```

---

### Task 5: Câmbio — taxa, tabelas, `CambioDoDia`, comando e valor padrão

**Files:**
- Create: `Modules/Financeiro/app/Support/{TaxaCambio,CambioResolvido}.php`, `Modules/Financeiro/app/Rules/TaxaDeCambio.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_11_100100_create_cambios_plataforma_table.php`, `Modules/Financeiro/database/migrations/2026_10_11_100200_create_cambios_table.php`
- Create: `Modules/Financeiro/app/Models/{CambioPlataforma,Cambio}.php`
- Create: `Modules/Financeiro/app/Services/CambioDoDia.php`
- Create: `Modules/Financeiro/app/Console/CambioPlataformaCommand.php`
- Create: `Modules/Financeiro/database/seeders/CambioPlataformaSeeder.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (`$commands`), `database/seeders/DatabaseSeeder.php`
- Test: `Modules/Financeiro/tests/Unit/TaxaCambioTest.php`, `Modules/Financeiro/tests/Feature/{CambioDoDiaTest,CambioPlataformaCommandTest,CambioPlataformaSeederTest,TaxaDeCambioTest}.php`

**Interfaces:**
- Consumes: `ConfiguracaoMonetaria`, `Moeda` (T1–T2).
- Produces:
  - `TaxaCambio` (final): `const ESCALA = 1_000_000`; `deMicros(int): self` (> 0), `deDecimal(int|string): self` (`^\d{1,9}([.,]\d{1,6})?$`, > 0), `um(): self`, `micros(): int`, `formatar(): string` (mín. 2 casas, sem zeros a mais: `910,00`, `910,50`, `910,123456`), `paraInput(): string` (`910.00`); lançam `InvalidArgumentException`;
  - `CambioResolvido` (final readonly): `TaxaCambio $taxa`, `?string $data`, `string $origem` (`usd` | `escola` | `plataforma`);
  - `CambioDoDia::resolver(CarbonInterface $data): ?CambioResolvido` e `para(CarbonInterface $data): ?TaxaCambio`; constante `REFERENCIA = 'USD'`;
  - `CambioPlataforma` (global): `moeda_cotada, moeda_base, data, taxa, fonte`; `Cambio` (por escola): `moeda_cotada, moeda_base, data, taxa`; ambos com `taxaCambio(): TaxaCambio`;
  - comando `financeiro:cambio-plataforma {moeda} {taxa} {--data=} {--fonte=manual}` (upsert por moeda/USD/data; `fonte` ∈ `padrao|manual|api`);
  - regra `new TaxaDeCambio()`;
  - seeder `CambioPlataformaSeeder` (AOA, 910, `padrao`, data `2000-01-01`) chamado pelo `DatabaseSeeder` (também em produção).

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/TaxaCambioTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\TaxaCambio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxaCambioTest extends TestCase
{
    #[DataProvider('validas')]
    public function test_converte_texto_para_micros(int|string $entrada, int $micros): void
    {
        $this->assertSame($micros, TaxaCambio::deDecimal($entrada)->micros());
    }

    public static function validas(): array
    {
        return [
            'inteiro' => [910, 910_000_000],
            'texto inteiro' => ['910', 910_000_000],
            'virgula' => ['910,5', 910_500_000],
            'ponto' => ['910.50', 910_500_000],
            'seis casas' => ['910,123456', 910_123_456],
            'menor que um' => ['0,000001', 1],
            'um' => ['1', 1_000_000],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_rejeita_valores_invalidos(int|string $entrada): void
    {
        $this->expectException(InvalidArgumentException::class);

        TaxaCambio::deDecimal($entrada);
    }

    public static function invalidas(): array
    {
        return [
            'zero' => ['0'],
            'zero com casas' => ['0,000000'],
            'negativo' => ['-1'],
            'zero inteiro' => [0],
            'sete casas' => ['910,1234567'],
            'letras' => ['abc'],
            'milhares' => ['1.000,50'],
            'notação científica' => ['1e3'],
            'quebra de linha final' => ["910\n"],
            'demasiado grande' => ['1234567890'],
            'vazio' => [''],
        ];
    }

    public function test_de_micros_exige_positivo(): void
    {
        $this->assertSame(910_000_000, TaxaCambio::deMicros(910_000_000)->micros());
        $this->expectException(InvalidArgumentException::class);

        TaxaCambio::deMicros(0);
    }

    public function test_um(): void
    {
        $this->assertSame(1_000_000, TaxaCambio::um()->micros());
        $this->assertSame('1,00', TaxaCambio::um()->formatar());
    }

    public function test_formatar_tem_no_minimo_duas_casas_e_sem_zeros_a_mais(): void
    {
        $this->assertSame('910,00', TaxaCambio::deMicros(910_000_000)->formatar());
        $this->assertSame('910,50', TaxaCambio::deMicros(910_500_000)->formatar());
        $this->assertSame('910,123456', TaxaCambio::deMicros(910_123_456)->formatar());
        $this->assertSame('1.234,50', TaxaCambio::deMicros(1_234_500_000)->formatar());
        $this->assertSame('0,000001', TaxaCambio::deMicros(1)->formatar());
    }

    public function test_para_input_usa_ponto_e_nao_tem_milhares(): void
    {
        $this->assertSame('910.00', TaxaCambio::deMicros(910_000_000)->paraInput());
        $this->assertSame('1234.50', TaxaCambio::deMicros(1_234_500_000)->paraInput());
    }
}
```

`Modules/Financeiro/tests/Feature/TaxaDeCambioTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Modules\Financeiro\Rules\TaxaDeCambio;
use Tests\TestCase;

class TaxaDeCambioTest extends TestCase
{
    private function passa(mixed $valor): bool
    {
        return Validator::make(['t' => $valor], ['t' => ['required', new TaxaDeCambio()]])->passes();
    }

    public function test_aceita_taxas_validas(): void
    {
        foreach (['910', '910,5', '910.123456', 910, 1] as $valor) {
            $this->assertTrue($this->passa($valor), var_export($valor, true));
        }
    }

    public function test_rejeita_taxas_invalidas_com_mensagem_em_portugues(): void
    {
        foreach (['0', '-1', 'abc', '910,1234567', "910\n", [1]] as $valor) {
            $this->assertFalse($this->passa($valor), var_export($valor, true));
        }

        $erros = Validator::make(['t' => '0'], ['t' => [new TaxaDeCambio()]])->errors();
        $this->assertSame('A taxa de câmbio é inválida: use um número maior que zero, com até 6 casas decimais (ex.: 910 ou 910,50).', $erros->first('t'));
    }
}
```

`Modules/Financeiro/tests/Feature/CambioDoDiaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Support\TaxaCambio;
use Tests\TestCase;

class CambioDoDiaTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): CambioDoDia
    {
        return app(CambioDoDia::class);
    }

    private function dia(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data);
    }

    private function plataforma(string $data, string $taxa, string $moeda = 'AOA'): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(), 'fonte' => 'manual',
        ]);
    }

    private function daEscola(string $data, string $taxa, string $moeda = 'AOA'): void
    {
        Cambio::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(),
        ]);
    }

    public function test_sem_nenhum_cambio_devolve_nulo_sem_erro(): void
    {
        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
        $this->assertNull($this->servico()->resolver($this->dia('2026-10-05')));
    }

    public function test_moeda_da_escola_em_usd_vale_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));

        $this->assertSame(1_000_000, $resolvido->taxa->micros());
        $this->assertSame('usd', $resolvido->origem);
        $this->assertNull($resolvido->data);
    }

    public function test_usa_o_padrao_da_plataforma_para_qualquer_data(): void
    {
        $this->seed(CambioPlataformaSeeder::class);

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));

        $this->assertSame('910,00', $resolvido->taxa->formatar());
        $this->assertSame('plataforma', $resolvido->origem);
    }

    public function test_uma_taxa_posterior_nao_vale_para_tras(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        $this->plataforma('2026-10-01', '920');

        $this->assertSame('920,00', $this->servico()->para($this->dia('2026-10-05'))->formatar());
        $this->assertSame('920,00', $this->servico()->para($this->dia('2026-10-01'))->formatar());
        $this->assertSame('910,00', $this->servico()->para($this->dia('2026-09-30'))->formatar());
    }

    public function test_a_plataforma_so_conta_para_a_moeda_da_escola(): void
    {
        $this->plataforma('2026-10-01', '920', 'MZN');

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_modo_manual_usa_so_a_tabela_da_escola(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);

        $this->assertNull($this->servico()->para($this->dia('2026-10-05'))); // estrito: não cai na plataforma

        $this->daEscola('2026-10-02', '930');

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));
        $this->assertSame('930,00', $resolvido->taxa->formatar());
        $this->assertSame('escola', $resolvido->origem);
        $this->assertSame('2026-10-02', $resolvido->data);
        $this->assertNull($this->servico()->para($this->dia('2026-10-01'))); // antes da primeira linha
    }

    public function test_modo_manual_ignora_linhas_de_outra_moeda(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $this->daEscola('2026-10-02', '930', 'EUR');

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_cambios_de_outra_escola_nao_sao_usados(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->daEscola('2026-10-02', '930'));

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_a_bd_impede_dois_cambios_da_plataforma_no_mesmo_dia_para_a_mesma_moeda(): void
    {
        $this->plataforma('2026-10-01', '910');

        $this->expectException(QueryException::class);

        $this->plataforma('2026-10-01', '920');
    }

    public function test_plataforma_permite_o_mesmo_dia_noutra_moeda_e_outro_dia_na_mesma_moeda(): void
    {
        $this->plataforma('2026-10-01', '910');
        $this->plataforma('2026-10-01', '17', 'MZN');
        $this->plataforma('2026-10-02', '915');

        $this->assertSame(3, CambioPlataforma::count());
    }

    public function test_a_bd_impede_dois_cambios_da_escola_no_mesmo_dia_para_a_mesma_moeda(): void
    {
        $this->daEscola('2026-10-01', '910');

        $this->expectException(QueryException::class);

        $this->daEscola('2026-10-01', '920');
    }

    public function test_a_unicidade_do_cambio_da_escola_e_por_tenant(): void
    {
        $this->daEscola('2026-10-01', '910');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, fn () => $this->daEscola('2026-10-01', '930'));

        $this->assertSame(1, Cambio::count());
        $this->assertSame(1, $this->noTenant($outro, fn () => Cambio::count()));
    }

    public function test_o_modo_manual_nao_altera_o_que_a_plataforma_diz_a_outras_escolas(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $doOutro = $this->noTenant($outro, fn () => $this->servico()->para($this->dia('2026-10-05')));

        $this->assertSame('910,00', $doOutro->formatar());
    }
}
```

`Modules/Financeiro/tests/Feature/CambioPlataformaCommandTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CambioPlataformaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_regista_o_cambio_do_dia_com_a_data_indicada(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '915,50', '--data' => '2026-10-10'])
            ->assertSuccessful();

        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame('AOA', $linha->moeda_cotada);
        $this->assertSame('USD', $linha->moeda_base);
        $this->assertSame('2026-10-10', substr((string) $linha->data, 0, 10));
        $this->assertSame(915_500_000, (int) $linha->taxa);
        $this->assertSame('manual', $linha->fonte);
    }

    public function test_sem_data_usa_hoje_e_actualiza_a_linha_do_mesmo_dia(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910'])->assertSuccessful();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '920', '--fonte' => 'api'])->assertSuccessful();

        $this->assertSame(1, DB::table('cambios_plataforma')->count());
        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame(920_000_000, (int) $linha->taxa);
        $this->assertSame('api', $linha->fonte);
        $this->assertSame(now()->toDateString(), substr((string) $linha->data, 0, 10));
    }

    public function test_rejeita_entradas_invalidas_sem_gravar(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'XXX', 'taxa' => '910'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '0'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910', '--data' => 'abc'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910', '--fonte' => 'xyz'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'USD', 'taxa' => '1'])->assertFailed();

        $this->assertSame(0, DB::table('cambios_plataforma')->count());
    }
}
```

`Modules/Financeiro/tests/Feature/CambioPlataformaSeederTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Tests\TestCase;

class CambioPlataformaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_o_cambio_padrao_do_kwanza_e_e_idempotente(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        $this->seed(CambioPlataformaSeeder::class);

        $this->assertSame(1, DB::table('cambios_plataforma')->count());
        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame('AOA', $linha->moeda_cotada);
        $this->assertSame('USD', $linha->moeda_base);
        $this->assertSame(910_000_000, (int) $linha->taxa);
        $this->assertSame('padrao', $linha->fonte);
        $this->assertSame('2000-01-01', substr((string) $linha->data, 0, 10));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/TaxaCambioTest.php Modules/Financeiro/tests/Feature/TaxaDeCambioTest.php Modules/Financeiro/tests/Feature/CambioDoDiaTest.php Modules/Financeiro/tests/Feature/CambioPlataformaCommandTest.php Modules/Financeiro/tests/Feature/CambioPlataformaSeederTest.php`
Expected: FAIL (classes, tabelas, comando e seeder inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/TaxaCambio.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Taxa de câmbio de referência no sentido "1 USD = X unidades da moeda da escola", guardada como
 * inteiro escalado a 6 casas (taxa × 1.000.000). Nunca usa float.
 */
final class TaxaCambio
{
    public const ESCALA = 1_000_000;

    private function __construct(private readonly int $micros)
    {
    }

    public static function deMicros(int $micros): self
    {
        if ($micros <= 0) {
            throw new InvalidArgumentException('A taxa de câmbio tem de ser maior que zero.');
        }

        return new self($micros);
    }

    public static function deDecimal(int|string $valor): self
    {
        if (is_int($valor)) {
            return self::deMicros($valor * self::ESCALA);
        }

        if (! preg_match('/^(\d{1,9})(?:[.,](\d{1,6}))?$/D', $valor, $partes)) {
            throw new InvalidArgumentException('Taxa de câmbio inválida.');
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], 6, '0') : 0;

        return self::deMicros(((int) $partes[1]) * self::ESCALA + $fraccao);
    }

    public static function um(): self
    {
        return new self(self::ESCALA);
    }

    public function micros(): int
    {
        return $this->micros;
    }

    /**
     * "910,00", "910,50", "910,123456": no mínimo 2 casas, sem zeros a mais; milhares com ".".
     */
    public function formatar(): string
    {
        return number_format(intdiv($this->micros, self::ESCALA), 0, ',', '.') . ',' . $this->fraccao();
    }

    /**
     * Para preencher um <input>: ponto decimal e sem separador de milhares.
     */
    public function paraInput(): string
    {
        return intdiv($this->micros, self::ESCALA) . '.' . $this->fraccao();
    }

    private function fraccao(): string
    {
        $fraccao = rtrim(str_pad((string) ($this->micros % self::ESCALA), 6, '0', STR_PAD_LEFT), '0');

        return str_pad($fraccao, 2, '0');
    }
}
```

`Modules/Financeiro/app/Support/CambioResolvido.php`:
```php
<?php

namespace Modules\Financeiro\Support;

/**
 * O câmbio escolhido para uma data: a taxa, a data da linha usada (null quando a moeda é o
 * próprio USD) e a origem: 'usd', 'escola' (modo manual) ou 'plataforma' (padrão).
 */
final class CambioResolvido
{
    public function __construct(
        public readonly TaxaCambio $taxa,
        public readonly ?string $data,
        public readonly string $origem,
    ) {
    }
}
```

`Modules/Financeiro/app/Rules/TaxaDeCambio.php`:
```php
<?php

namespace Modules\Financeiro\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Modules\Financeiro\Support\TaxaCambio;

class TaxaDeCambio implements ValidationRule
{
    private const MENSAGEM = 'A taxa de câmbio é inválida: use um número maior que zero, com até 6 casas decimais (ex.: 910 ou 910,50).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            $fail(self::MENSAGEM);

            return;
        }

        try {
            TaxaCambio::deDecimal(is_int($value) ? $value : (string) $value);
        } catch (InvalidArgumentException) {
            $fail(self::MENSAGEM);
        }
    }
}
```

Migration `2026_10_11_100100_create_cambios_plataforma_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('moeda_cotada', 3);
            $table->string('moeda_base', 3)->default('USD');
            $table->date('data');
            $table->unsignedBigInteger('taxa');
            $table->string('fonte', 20)->default('manual');
            $table->timestamps();

            $table->unique(['moeda_cotada', 'moeda_base', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_plataforma');
    }
};
```

Migration `2026_10_11_100200_create_cambios_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('moeda_cotada', 3);
            $table->string('moeda_base', 3)->default('USD');
            $table->date('data');
            $table->unsignedBigInteger('taxa');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'moeda_cotada', 'moeda_base', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios');
    }
};
```

`Modules/Financeiro/app/Models/CambioPlataforma.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio por defeito, igual para todas as escolas (sem tenant): 1 USD = taxa unidades da moeda.
 * No máximo uma linha por dia por moeda; alimentado por comando e, no futuro, por uma API.
 */
class CambioPlataforma extends Model
{
    protected $table = 'cambios_plataforma';

    protected $fillable = [
        'moeda_cotada',
        'moeda_base',
        'data',
        'taxa',
        'fonte',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'taxa' => 'integer',
    ];

    public function taxaCambio(): TaxaCambio
    {
        return TaxaCambio::deMicros($this->taxa);
    }
}
```

`Modules/Financeiro/app/Models/Cambio.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio próprio da escola (modo manual): 1 USD = taxa unidades da moeda da escola nessa data.
 */
class Cambio extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'cambios';

    protected $fillable = [
        'moeda_cotada',
        'moeda_base',
        'data',
        'taxa',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'taxa' => 'integer',
    ];

    public function taxaCambio(): TaxaCambio
    {
        return TaxaCambio::deMicros($this->taxa);
    }
}
```

`Modules/Financeiro/app/Services/CambioDoDia.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Support\CambioResolvido;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * O câmbio (1 USD = X unidades da moeda da escola) à data dada: o ÚLTIMO câmbio até essa data.
 * Moeda da escola = USD -> 1. Modo manual -> só a tabela da escola. Caso contrário, a da
 * plataforma. Sem câmbio -> null: nunca bloqueia.
 */
class CambioDoDia
{
    public const REFERENCIA = 'USD';

    public function para(CarbonInterface $data): ?TaxaCambio
    {
        return $this->resolver($data)?->taxa;
    }

    public function resolver(CarbonInterface $data): ?CambioResolvido
    {
        $configuracao = ConfiguracaoMonetaria::doTenant();

        if ($configuracao->moeda === self::REFERENCIA) {
            return new CambioResolvido(TaxaCambio::um(), null, 'usd');
        }

        $consulta = $configuracao->cambio_manual ? Cambio::query() : CambioPlataforma::query();

        $linha = $consulta
            ->where('moeda_cotada', $configuracao->moeda)
            ->where('moeda_base', self::REFERENCIA)
            ->whereDate('data', '<=', $data->toDateString())
            ->orderByDesc('data')
            ->first();

        if ($linha === null) {
            return null;
        }

        return new CambioResolvido(
            $linha->taxaCambio(),
            $linha->data->toDateString(),
            $configuracao->cambio_manual ? 'escola' : 'plataforma',
        );
    }
}
```

`Modules/Financeiro/app/Console/CambioPlataformaCommand.php`:
```php
<?php

namespace Modules\Financeiro\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\TaxaCambio;
use Throwable;

/**
 * Regista o câmbio da plataforma (1 USD = taxa unidades da moeda) para uma data. Uma linha por
 * dia por moeda: repetir o dia actualiza a linha. No futuro, uma API agendada chama o mesmo caminho.
 */
class CambioPlataformaCommand extends Command
{
    private const FONTES = ['padrao', 'manual', 'api'];

    protected $signature = 'financeiro:cambio-plataforma {moeda : Código ISO da moeda (ex.: AOA)} {taxa : 1 USD = taxa unidades da moeda (ex.: 910,50)} {--data= : AAAA-MM-DD (por omissão, hoje)} {--fonte=manual : padrao, manual ou api}';

    protected $description = 'Regista o câmbio da plataforma (1 USD = X unidades da moeda) para um dia';

    public function handle(): int
    {
        $moeda = strtoupper(trim((string) $this->argument('moeda')));

        if (! Moeda::existe($moeda) || $moeda === CambioDoDia::REFERENCIA) {
            $this->error("Moeda inválida: {$moeda}. Use um código do registo, diferente de USD.");

            return self::FAILURE;
        }

        try {
            $taxa = TaxaCambio::deDecimal((string) $this->argument('taxa'));
        } catch (InvalidArgumentException) {
            $this->error('Taxa inválida: use um número maior que zero, com até 6 casas decimais.');

            return self::FAILURE;
        }

        $fonte = (string) $this->option('fonte');

        if (! in_array($fonte, self::FONTES, true)) {
            $this->error('Fonte inválida: use padrao, manual ou api.');

            return self::FAILURE;
        }

        $data = $this->option('data') !== null && $this->option('data') !== ''
            ? (string) $this->option('data')
            : now()->toDateString();

        if (! $this->dataValida($data)) {
            $this->error('Data inválida: use o formato AAAA-MM-DD.');

            return self::FAILURE;
        }

        CambioPlataforma::updateOrCreate(
            ['moeda_cotada' => $moeda, 'moeda_base' => CambioDoDia::REFERENCIA, 'data' => $data],
            ['taxa' => $taxa->micros(), 'fonte' => $fonte],
        );

        $this->info("Câmbio registado: 1 USD = {$taxa->formatar()} {$moeda} em {$data}.");

        return self::SUCCESS;
    }

    private function dataValida(string $data): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $data)->toDateString() === $data;
        } catch (Throwable) {
            return false;
        }
    }
}
```
`Modules/Financeiro/database/seeders/CambioPlataformaSeeder.php`:
```php
<?php

namespace Modules\Financeiro\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio por defeito da plataforma: 1 USD = 910,00 AOA, com uma data antiga para valer em
 * qualquer data até a plataforma registar um câmbio real (comando financeiro:cambio-plataforma,
 * e no futuro uma API). É um valor configurável, não um câmbio de mercado. Idempotente.
 */
class CambioPlataformaSeeder extends Seeder
{
    public function run(): void
    {
        CambioPlataforma::updateOrCreate(
            ['moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2000-01-01'],
            ['taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'padrao'],
        );
    }
}
```

`database/seeders/DatabaseSeeder.php`: no primeiro `$this->call([ModuloSeeder::class, AcaoSeeder::class])` (passo que corre também em produção) acrescentar `CambioPlataformaSeeder::class` com o `use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;`.

`FinanceiroServiceProvider`: acrescentar `CambioPlataformaCommand::class` a `$commands` (com `use Modules\Financeiro\Console\CambioPlataformaCommand;`).

- [ ] **Step 4: Correr e ver passar**

Run: `composer dump-autoload && php artisan test Modules/Financeiro tests/Feature/Tenancy`
Expected: PASS (a matriz de isolamento passa a exercitar `Cambio`; `CambioPlataforma` é global e não entra nela).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro database/seeders/DatabaseSeeder.php
```

---

### Task 6: Moeda e Câmbio (backend da página)

**Files:**
- Create: `Modules/Financeiro/app/DTO/{ConfiguracaoMonetariaDTO,CambioDTO}.php`
- Create: `Modules/Financeiro/app/Http/Requests/{AtualizarConfiguracaoMonetariaRequest,RegistarCambioRequest}.php`
- Create: `Modules/Financeiro/app/Actions/{AtualizarConfiguracaoMonetariaAction,RegistarCambioAction,EliminarCambioAction}.php`
- Create: `Modules/Financeiro/app/Services/{GestaoMoedaCambioService,MoedaCambioConsultaService}.php`
- Create: `Modules/Financeiro/app/Http/Controllers/MoedaCambioController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/MoedaCambioTest.php`

**Interfaces:**
- Consumes: `ConfiguracaoMonetaria`, `MoedaDoTenant` (T2), `CambioDoDia`, `Cambio`, `TaxaCambio`, `TaxaDeCambio` (T5), abilities `moeda-cambio.*` (T4), `ComUtilizadoresFinanceiro`.
- Produces:
  - rotas (`financeiro.configuracao.moeda-cambio.*`): `GET /financeiro/configuracao/moeda-cambio` (`show`), `PUT` (`atualizar`), `POST /cambios` (`cambios.store`), `DELETE /cambios/{cambio}` (`cambios.destroy`);
  - componente Inertia `Financeiro/MoedaCambio/Edit` com props `configuracao` (`moeda`, `cambio_manual`, `pode_alterar_moeda`), `moeda` (`paraFrontend`), `moedas` (`Moeda::opcoes()`), `cambioVigente` (`{taxa, data, origem}` ou `null`; `taxa` formatada), `historico` (paginador de `{id, data, taxa (micros), taxa_formatada}` só da moeda actual);
  - payloads: `PUT {moeda, cambio_manual}`; `POST {data, taxa}`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class MoedaCambioTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.moeda-cambio.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function cambio(string $data, string $taxa, string $moeda = 'AOA'): Cambio
    {
        return Cambio::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(),
        ]);
    }

    public function test_show_mostra_a_configuracao_e_o_cambio_padrao(): void
    {
        $this->seed(CambioPlataformaSeeder::class);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/MoedaCambio/Edit')
                ->where('configuracao.moeda', 'AOA')
                ->where('configuracao.cambio_manual', false)
                ->where('configuracao.pode_alterar_moeda', true)
                ->where('moeda.simbolo', 'Kz')
                ->where('moeda.decimais', 2)
                ->has('moedas')
                ->where('cambioVigente.taxa', '910,00')
                ->where('cambioVigente.origem', 'plataforma')
                ->has('historico.data', 0)
                ->missing('configuracao.tenant_id'));
    }

    public function test_show_sem_cambio_devolve_nulo(): void
    {
        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page->where('cambioVigente', null));
    }

    public function test_show_com_usd_vale_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cambioVigente.taxa', '1,00')
                ->where('cambioVigente.origem', 'usd'));
    }

    public function test_show_indica_que_a_moeda_esta_bloqueada_com_preco_configurado(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page->where('configuracao.pode_alterar_moeda', false));
    }

    public function test_historico_so_mostra_a_moeda_actual_do_mais_recente_para_o_mais_antigo(): void
    {
        $this->cambio('2026-10-01', '910');
        $this->cambio('2026-10-03', '915,5');
        $this->cambio('2026-10-02', '930', 'EUR');
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('historico.data', 2)
                ->where('historico.data.0.taxa_formatada', '915,50')
                ->where('historico.data.0.taxa', 915_500_000)
                ->where('historico.data.1.taxa_formatada', '910,00')
                ->where('cambioVigente.origem', 'escola'));
    }

    public function test_altera_a_moeda_e_o_modo_manual_quando_nada_a_bloqueia(): void
    {
        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'atualizar'), ['moeda' => 'MZN', 'cambio_manual' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $configuracao = ConfiguracaoMonetaria::doTenant();
        $this->assertSame('MZN', $configuracao->moeda);
        $this->assertTrue($configuracao->cambio_manual);
    }

    public function test_moeda_bloqueada_nao_muda_mas_o_modo_manual_sim(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]);
        $this->actingAs($this->adminEscola())->from('/x');

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'EUR', 'cambio_manual' => false])
            ->assertSessionHasErrors(['moeda' => 'Não é possível alterar a moeda: já existem preços configurados ou registos financeiros.']);
        $this->assertSame('AOA', ConfiguracaoMonetaria::doTenant()->moeda);

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(ConfiguracaoMonetaria::doTenant()->cambio_manual);
    }

    public function test_moeda_invalida_e_campos_em_falta_sao_rejeitados(): void
    {
        $this->actingAs($this->adminEscola())->from('/x');

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'XXX', 'cambio_manual' => false])->assertSessionHasErrors('moeda');
        $this->put(route(self::BASE . 'atualizar'), [])->assertSessionHasErrors(['moeda', 'cambio_manual']);
        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => 'talvez'])->assertSessionHasErrors('cambio_manual');

        $this->assertSame('AOA', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_regista_um_cambio_e_o_mesmo_dia_actualiza_a_linha(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '915,5'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Cambio::count());
        $cambio = Cambio::first();
        $this->assertSame('AOA', $cambio->moeda_cotada);
        $this->assertSame('USD', $cambio->moeda_base);
        $this->assertSame(915_500_000, $cambio->taxa);
        $this->assertSame($this->tenant->id, $cambio->tenant_id);
        $this->assertSame($admin->id, $cambio->criado_por);
    }

    public function test_cambio_com_data_futura_taxa_invalida_ou_em_falta_e_rejeitado(): void
    {
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'cambios.store'), ['data' => now()->addDay()->toDateString(), 'taxa' => '910'])->assertSessionHasErrors('data');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '0'])->assertSessionHasErrors('taxa');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910,1234567'])->assertSessionHasErrors('taxa');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => 'abc', 'taxa' => '910'])->assertSessionHasErrors('data');
        $this->post(route(self::BASE . 'cambios.store'), [])->assertSessionHasErrors(['data', 'taxa']);

        $this->assertSame(0, Cambio::count());
    }

    public function test_com_usd_como_moeda_nao_se_regista_cambio(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHasErrors(['taxa' => 'Com USD como moeda da escola não é preciso registar câmbio: vale sempre 1.']);

        $this->assertSame(0, Cambio::count());
    }

    public function test_elimina_um_cambio(): void
    {
        $cambio = $this->cambio('2026-10-01', '910');

        $this->actingAs($this->adminEscola())
            ->delete(route(self::BASE . 'cambios.destroy', $cambio))
            ->assertSessionHasNoErrors();

        $this->assertNull(Cambio::find($cambio->id));
    }

    public function test_cambio_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $doOutro = $this->noTenant($outro, fn () => $this->cambio('2026-10-01', '910'));
        $this->actingAs($this->adminEscola());

        $this->delete(route(self::BASE . 'cambios.destroy', $doOutro->id))->assertNotFound();

        $this->assertSame(910_000_000, (int) DB::table('cambios')->where('id', $doOutro->id)->value('taxa'));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $cambio = $this->cambio('2026-10-01', '910');
        $this->actingAs($this->professor());

        $this->get(route(self::BASE . 'show'))->assertForbidden();
        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => false])->assertForbidden();
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])->assertForbidden();
        $this->delete(route(self::BASE . 'cambios.destroy', $cambio))->assertForbidden();
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910', 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, Cambio::first()->tenant_id);
    }

    public function test_mensagens_ao_utilizador_em_portugues(): void
    {
        $this->actingAs($this->adminEscola());

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => false])
            ->assertSessionHas('success', 'Moeda e câmbio atualizados com sucesso.');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHas('success', 'Câmbio registado com sucesso.');
        $this->delete(route(self::BASE . 'cambios.destroy', Cambio::first()))
            ->assertSessionHas('success', 'Câmbio eliminado com sucesso.');
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/MoedaCambioTest.php`
Expected: FAIL (rotas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/DTO/ConfiguracaoMonetariaDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;

class ConfiguracaoMonetariaDTO
{
    public function __construct(
        public string $moeda,
        public bool $cambioManual,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            moeda: strtoupper(trim((string) $dados['moeda'])),
            cambioManual: (bool) $dados['cambio_manual'],
        );
    }
}
```

`Modules/Financeiro/app/DTO/CambioDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Support\TaxaCambio;

class CambioDTO
{
    public function __construct(
        public string $data,
        public TaxaCambio $taxa,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            data: $dados['data'],
            taxa: TaxaCambio::deDecimal(is_int($dados['taxa']) ? $dados['taxa'] : (string) $dados['taxa']),
        );
    }
}
```

`Modules/Financeiro/app/Http/Requests/AtualizarConfiguracaoMonetariaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Financeiro\Support\Moeda;

class AtualizarConfiguracaoMonetariaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moeda-cambio.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'moeda' => ['required', 'string', Rule::in(array_column(Moeda::opcoes(), 'value'))],
            'cambio_manual' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'moeda.required' => 'A moeda é obrigatória.',
            'moeda.in' => 'A moeda escolhida não existe no registo de moedas.',
            'cambio_manual.required' => 'Indique se usa câmbio próprio.',
            'cambio_manual.boolean' => 'O modo de câmbio é inválido.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/RegistarCambioRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Financeiro\Rules\TaxaDeCambio;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Services\MoedaDoTenant;

class RegistarCambioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moeda-cambio.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'taxa' => ['required', new TaxaDeCambio()],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required' => 'A data do câmbio é obrigatória.',
            'data.date_format' => 'A data do câmbio é inválida: use o formato AAAA-MM-DD.',
            'data.before_or_equal' => 'A data do câmbio não pode ser futura.',
            'taxa.required' => 'A taxa de câmbio é obrigatória.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (app(MoedaDoTenant::class)->atual()->codigo === CambioDoDia::REFERENCIA) {
                $validator->errors()->add('taxa', 'Com USD como moeda da escola não é preciso registar câmbio: vale sempre 1.');
            }
        });
    }
}
```

Actions (`Modules/Financeiro/app/Actions/`):
```php
// AtualizarConfiguracaoMonetariaAction.php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\MoedaDoTenant;

class AtualizarConfiguracaoMonetariaAction
{
    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    public function executar(ConfiguracaoMonetariaDTO $dto): ConfiguracaoMonetaria
    {
        return DB::transaction(function () use ($dto) {
            $configuracao = $this->moedaDoTenant->configuracao();

            if ($configuracao->moeda !== $dto->moeda && ! $this->moedaDoTenant->podeAlterar()) {
                throw ValidationException::withMessages([
                    'moeda' => 'Não é possível alterar a moeda: já existem preços configurados ou registos financeiros.',
                ]);
            }

            $configuracao->update(['moeda' => $dto->moeda, 'cambio_manual' => $dto->cambioManual]);

            return $configuracao->fresh();
        });
    }
}
```
```php
// RegistarCambioAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\CambioDTO;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Services\MoedaDoTenant;

class RegistarCambioAction
{
    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    /**
     * Uma linha por dia: repetir o dia actualiza a taxa.
     */
    public function executar(CambioDTO $dto): Cambio
    {
        return Cambio::updateOrCreate(
            [
                'moeda_cotada' => $this->moedaDoTenant->atual()->codigo,
                'moeda_base' => CambioDoDia::REFERENCIA,
                'data' => $dto->data,
            ],
            ['taxa' => $dto->taxa->micros()],
        );
    }
}
```
```php
// EliminarCambioAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\Models\Cambio;

class EliminarCambioAction
{
    public function executar(Cambio $cambio): void
    {
        $cambio->delete();
    }
}
```

`Modules/Financeiro/app/Services/GestaoMoedaCambioService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AtualizarConfiguracaoMonetariaAction;
use Modules\Financeiro\Actions\EliminarCambioAction;
use Modules\Financeiro\Actions\RegistarCambioAction;
use Modules\Financeiro\DTO\CambioDTO;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Http\Requests\AtualizarConfiguracaoMonetariaRequest;
use Modules\Financeiro\Http\Requests\RegistarCambioRequest;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;

class GestaoMoedaCambioService
{
    public function __construct(
        private AtualizarConfiguracaoMonetariaAction $atualizarAction,
        private RegistarCambioAction $registarAction,
        private EliminarCambioAction $eliminarAction,
    ) {
    }

    public function atualizar(AtualizarConfiguracaoMonetariaRequest $request): ConfiguracaoMonetaria
    {
        return $this->atualizarAction->executar(ConfiguracaoMonetariaDTO::fromRequest($request));
    }

    public function registarCambio(RegistarCambioRequest $request): Cambio
    {
        return $this->registarAction->executar(CambioDTO::fromRequest($request));
    }

    public function eliminarCambio(Cambio $cambio): void
    {
        $this->eliminarAction->executar($cambio);
    }
}
```

`Modules/Financeiro/app/Services/MoedaCambioConsultaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Support\Moeda;

class MoedaCambioConsultaService
{
    public function __construct(
        private MoedaDoTenant $moedaDoTenant,
        private CambioDoDia $cambioDoDia,
    ) {
    }

    /**
     * @return array{moeda: string, cambio_manual: bool, pode_alterar_moeda: bool}
     */
    public function configuracao(): array
    {
        $configuracao = $this->moedaDoTenant->configuracao();

        return [
            'moeda' => $configuracao->moeda,
            'cambio_manual' => $configuracao->cambio_manual,
            'pode_alterar_moeda' => $this->moedaDoTenant->podeAlterar(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moeda(): array
    {
        return $this->moedaDoTenant->atual()->paraFrontend();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function moedas(): array
    {
        return Moeda::opcoes();
    }

    /**
     * @return array{taxa: string, data: ?string, origem: string}|null
     */
    public function cambioVigente(): ?array
    {
        $resolvido = $this->cambioDoDia->resolver(now());

        if ($resolvido === null) {
            return null;
        }

        return ['taxa' => $resolvido->taxa->formatar(), 'data' => $resolvido->data, 'origem' => $resolvido->origem];
    }

    /**
     * Câmbios da escola na moeda actual, do mais recente para o mais antigo.
     */
    public function historico(int $porPagina = 10): LengthAwarePaginator
    {
        return Cambio::query()
            ->where('moeda_cotada', $this->moedaDoTenant->atual()->codigo)
            ->orderByDesc('data')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Cambio $cambio) => [
                'id' => $cambio->id,
                'data' => $cambio->data->toDateString(),
                'taxa' => $cambio->taxa,
                'taxa_formatada' => $cambio->taxaCambio()->formatar(),
            ]);
    }
}
```

`Modules/Financeiro/app/Http/Controllers/MoedaCambioController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Financeiro\Http\Requests\AtualizarConfiguracaoMonetariaRequest;
use Modules\Financeiro\Http\Requests\RegistarCambioRequest;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Services\GestaoMoedaCambioService;
use Modules\Financeiro\Services\MoedaCambioConsultaService;

class MoedaCambioController extends Controller
{
    public function __construct(
        private GestaoMoedaCambioService $service,
        private MoedaCambioConsultaService $consulta,
    ) {
    }

    public function show()
    {
        $this->authorize('moeda-cambio.ver');

        return Inertia::render('Financeiro/MoedaCambio/Edit', [
            'configuracao' => $this->consulta->configuracao(),
            'moeda' => $this->consulta->moeda(),
            'moedas' => $this->consulta->moedas(),
            'cambioVigente' => $this->consulta->cambioVigente(),
            'historico' => $this->consulta->historico(),
        ]);
    }

    public function atualizar(AtualizarConfiguracaoMonetariaRequest $request)
    {
        $this->authorize('moeda-cambio.editar');

        $this->service->atualizar($request);

        return redirect()->back()->with('success', 'Moeda e câmbio atualizados com sucesso.');
    }

    public function registarCambio(RegistarCambioRequest $request)
    {
        $this->authorize('moeda-cambio.criar');

        $this->service->registarCambio($request);

        return redirect()->back()->with('success', 'Câmbio registado com sucesso.');
    }

    public function eliminarCambio(Cambio $cambio)
    {
        $this->authorize('moeda-cambio.eliminar');

        $this->service->eliminarCambio($cambio);

        return redirect()->back()->with('success', 'Câmbio eliminado com sucesso.');
    }
}
```

`Modules/Financeiro/routes/web.php`: acrescentar `use Modules\Financeiro\Http\Controllers\MoedaCambioController;` e, dentro do grupo `financeiro/configuracao`, mantendo todas as rotas existentes:
```php
    Route::prefix('moeda-cambio')->name('moeda-cambio.')->group(function () {
        Route::get('/', [MoedaCambioController::class, 'show'])->middleware('can:moeda-cambio.ver')->name('show');
        Route::put('/', [MoedaCambioController::class, 'atualizar'])->middleware('can:moeda-cambio.editar')->name('atualizar');
        Route::post('/cambios', [MoedaCambioController::class, 'registarCambio'])->middleware('can:moeda-cambio.criar')->name('cambios.store');
        Route::delete('/cambios/{cambio}', [MoedaCambioController::class, 'eliminarCambio'])->middleware('can:moeda-cambio.eliminar')->name('cambios.destroy');
    });
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 7: Frontend — helpers de moeda, Produtos/Serviços sem Kz fixo e página Moeda e Câmbio (+ menus)

**Files:**
- Rewrite: `Modules/Financeiro/resources/js/Support/dinheiro.js`
- Modify: `Modules/Financeiro/app/Http/Controllers/CatalogoFinanceiroController.php` (prop `moeda`), `Modules/Financeiro/resources/js/Pages/ProdutosServicos/Index.vue`, `Modules/Financeiro/resources/js/Components/ProdutosServicos/CatalogoItemFormModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/MoedaCambio/Edit.vue`
- Modify: `resources/js/Composables/useConfiguracoesMenu.js`, `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Test: `Modules/Financeiro/tests/Feature/CatalogoFinanceiroTest.php` (um teste novo para a prop `moeda`)

**Interfaces:**
- Consumes: componente `Financeiro/MoedaCambio/Edit` e as props da Task 6; prop `moeda` (`{codigo, nome, simbolo, decimais}`) que o catálogo passa a receber; componentes partilhados `AppLayout`, `AcaoIcone`, `ConfirmModal`, `SelectSolid`, `Pagination`; `can()`.
- Produces: `formatarDinheiro(unidadesMenores, moeda): string` e `unidadesMenoresParaDecimal(unidadesMenores, moeda): string` em `Support/dinheiro.js`.

- [ ] **Step 1: Teste da prop `moeda` no catálogo (falha primeiro)**

Acrescentar a `CatalogoFinanceiroTest`, antes do `}` final:
```php
    public function test_expoe_a_moeda_da_escola(): void
    {
        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->where('moeda.codigo', 'AOA')
                ->where('moeda.simbolo', 'Kz')
                ->where('moeda.decimais', 2));

        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('moeda.codigo', 'JPY')->where('moeda.decimais', 0));
    }
```
Acrescentar `use Modules\Financeiro\Models\ConfiguracaoMonetaria;` ao topo do ficheiro de teste.

Run: `php artisan test Modules/Financeiro/tests/Feature/CatalogoFinanceiroTest.php`
Expected: FAIL (prop inexistente).

- [ ] **Step 2: Backend da prop**

`CatalogoFinanceiroController`: injectar `MoedaDoTenant` no construtor (`private MoedaDoTenant $moedaDoTenant`) e acrescentar ao `Inertia::render` a prop `'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),` (com o `use Modules\Financeiro\Services\MoedaDoTenant;`).

Run: `php artisan test Modules/Financeiro` — Expected: PASS.

- [ ] **Step 3: Helpers JS**

`Modules/Financeiro/resources/js/Support/dinheiro.js` (substituir o ficheiro inteiro):
```js
/**
 * Valores monetários chegam do backend em unidades menores da moeda (inteiro). `moeda` é o objeto
 * que o backend envia: { codigo, nome, simbolo, decimais }. Estes helpers só apresentam ou
 * preenchem inputs: a conversão autoritativa é Dinheiro::deDecimal no backend.
 */
export function formatarDinheiro(unidadesMenores, moeda) {
    const fator = 10 ** moeda.decimais;
    const valor = Math.abs(Number(unidadesMenores ?? 0));
    const inteira = Math.trunc(valor / fator);
    const milhares = String(inteira).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    if (moeda.decimais === 0) {
        return `${milhares} ${moeda.simbolo}`;
    }

    const fraccao = String(valor % fator).padStart(moeda.decimais, '0');

    return `${milhares},${fraccao} ${moeda.simbolo}`;
}

export function unidadesMenoresParaDecimal(unidadesMenores, moeda) {
    const fator = 10 ** moeda.decimais;
    const valor = Math.abs(Number(unidadesMenores ?? 0));
    const inteira = Math.trunc(valor / fator);

    if (moeda.decimais === 0) {
        return String(inteira);
    }

    return `${inteira}.${String(valor % fator).padStart(moeda.decimais, '0')}`;
}
```

- [ ] **Step 4: Produtos / Serviços sem Kz fixo**

Em `Pages/ProdutosServicos/Index.vue`: declarar a prop `moeda: { type: Object, required: true }`, trocar o import por `import { formatarDinheiro } from '../../Support/dinheiro';`, substituir `formatKz(item.preco)` por `formatarDinheiro(item.preco, moeda)` e passar `:moeda="moeda"` ao `<CatalogoItemFormModal>`.
Em `Components/ProdutosServicos/CatalogoItemFormModal.vue`: declarar a prop `moeda` (`{ type: Object, required: true }`), trocar o import por `unidadesMenoresParaDecimal`, usar `unidadesMenoresParaDecimal(props.item.preco, props.moeda)` no preenchimento, a legenda `Preço ({{ moeda.simbolo }})` e o `placeholder` calculado: `moeda.decimais === 0 ? 'ex: 25000' : 'ex: 25000 ou 25000,' + '50'.padEnd(moeda.decimais, '0').slice(0, moeda.decimais)`.

- [ ] **Step 5: Página Moeda e Câmbio**

`Modules/Financeiro/resources/js/Pages/MoedaCambio/Edit.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const BASE = '/financeiro/configuracao/moeda-cambio';

const ORIGENS = {
    usd: 'Moeda da escola é o USD (vale sempre 1)',
    escola: 'Câmbio próprio da escola',
    plataforma: 'Câmbio da plataforma (padrão)',
};

const props = defineProps({
    configuracao: { type: Object, required: true },
    moeda: { type: Object, required: true },
    moedas: { type: Array, required: true },
    cambioVigente: { type: Object, default: null },
    historico: { type: Object, required: true }, // paginador: { data, links, ... }
});
defineOptions({ layout: AppLayout });

const podeEditar = computed(() => can('moeda-cambio.editar'));
const podeCriar = computed(() => can('moeda-cambio.criar'));
const moedaEUsd = computed(() => props.configuracao.moeda === 'USD');

const form = reactive({
    moeda: props.configuracao.moeda,
    cambio_manual: props.configuracao.cambio_manual,
});
watch(() => props.configuracao, (nova) => {
    form.moeda = nova.moeda;
    form.cambio_manual = nova.cambio_manual;
});

const errosConfiguracao = ref({});
const aGuardar = ref(false);

function guardarConfiguracao() {
    aGuardar.value = true;
    errosConfiguracao.value = {};

    router.put(BASE, { moeda: form.moeda, cambio_manual: form.cambio_manual }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Moeda e câmbio atualizados com sucesso.'),
        onError: (erros) => {
            errosConfiguracao.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            aGuardar.value = false;
        },
    });
}

const hoje = new Date().toISOString().slice(0, 10);
const novo = reactive({ data: hoje, taxa: '' });
const errosCambio = ref({});
const aRegistar = ref(false);

function registarCambio() {
    aRegistar.value = true;
    errosCambio.value = {};

    router.post(`${BASE}/cambios`, { data: novo.data, taxa: novo.taxa }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Câmbio registado com sucesso.');
            novo.taxa = '';
        },
        onError: (erros) => {
            errosCambio.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            aRegistar.value = false;
        },
    });
}

const paraEliminar = ref(null);
const aEliminar = ref(false);

function confirmarEliminacao() {
    aEliminar.value = true;
    router.delete(`${BASE}/cambios/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Câmbio eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aEliminar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Moeda e Câmbio</h1>
            <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                A moeda em que a escola opera e o câmbio de referência em USD (1 USD = X unidades da moeda da escola).
                O câmbio é guardado em cada registo no momento em que é criado.
            </p>
        </div>

        <form class="card mb-6" @submit.prevent="guardarConfiguracao">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Moeda da escola</h3>
            </div>
            <div class="card-body pt-0">
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="fw-semibold fs-6 mb-2">Moeda</label>
                        <SelectSolid v-if="configuracao.pode_alterar_moeda && podeEditar" v-model="form.moeda" :options="moedas" />
                        <input v-else type="text" class="form-control form-control-solid" :value="`${moeda.codigo} — ${moeda.nome} (${moeda.simbolo})`" disabled />
                        <div class="text-danger fs-7 mt-1" v-if="errosConfiguracao.moeda">{{ errosConfiguracao.moeda }}</div>
                        <div class="form-text" v-if="!configuracao.pode_alterar_moeda">
                            A moeda não pode ser alterada porque já existem preços configurados ou registos financeiros.
                        </div>
                        <div class="form-text" v-else>
                            Só pode mudar enquanto não existirem produtos, serviços, planos de propina ou registos financeiros.
                        </div>
                    </div>
                </div>

                <div class="form-check form-switch form-check-custom form-check-solid mt-2" v-if="!moedaEUsd">
                    <input id="cambio_manual" v-model="form.cambio_manual" type="checkbox" class="form-check-input" :disabled="!podeEditar" />
                    <label for="cambio_manual" class="form-check-label fw-semibold">Usar câmbio próprio da escola (em vez do da plataforma)</label>
                </div>
                <div class="text-danger fs-7 mt-1" v-if="errosConfiguracao.cambio_manual">{{ errosConfiguracao.cambio_manual }}</div>
            </div>
            <div v-if="podeEditar" class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" :disabled="aGuardar">{{ aGuardar ? 'A guardar…' : 'Guardar' }}</button>
            </div>
        </form>

        <div class="card mb-6">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Câmbio em vigor</h3>
            </div>
            <div class="card-body pt-0">
                <template v-if="cambioVigente">
                    <div class="fs-2 fw-bold">1 USD = {{ cambioVigente.taxa }} {{ moeda.codigo }}</div>
                    <div class="text-muted fs-7">
                        {{ ORIGENS[cambioVigente.origem] }}<span v-if="cambioVigente.data"> · desde {{ cambioVigente.data }}</span>
                    </div>
                </template>
                <div v-else class="text-muted">
                    Sem câmbio configurado para {{ moeda.codigo }}. Os registos novos ficarão sem câmbio; nada fica bloqueado.
                </div>
            </div>
        </div>

        <div v-if="!moedaEUsd && configuracao.cambio_manual" class="card">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Câmbios da escola</h3>
            </div>
            <div class="card-body">
                <form v-if="podeCriar" class="row g-4 align-items-end mb-6" @submit.prevent="registarCambio">
                    <div class="col-md-3">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data</label>
                        <input v-model="novo.data" type="date" :max="hoje" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errosCambio.data">{{ errosCambio.data }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">1 USD = (em {{ moeda.codigo }})</label>
                        <input v-model="novo.taxa" type="text" inputmode="decimal" class="form-control form-control-solid" placeholder="ex: 910 ou 910,50" />
                        <div class="text-danger fs-7 mt-1" v-if="errosCambio.taxa">{{ errosCambio.taxa }}</div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary" :disabled="aRegistar">{{ aRegistar ? 'A registar…' : 'Registar câmbio' }}</button>
                    </div>
                </form>
                <div class="form-text mb-4" v-if="podeCriar">Registar no mesmo dia actualiza o câmbio desse dia.</div>

                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th>Data</th>
                            <th class="text-end">1 USD =</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="historico.data.length === 0">
                            <td colspan="3" class="text-center text-muted py-5">Ainda não registou nenhum câmbio.</td>
                        </tr>
                        <tr v-for="linha in historico.data" :key="linha.id">
                            <td>{{ linha.data }}</td>
                            <td class="text-end">{{ linha.taxa_formatada }} {{ moeda.codigo }}</td>
                            <td class="text-end">
                                <button v-if="can('moeda-cambio.eliminar')" type="button" class="btn btn-sm btn-light-danger" @click="paraEliminar = linha">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="historico.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="historico.links" />
            </div>
        </div>

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar câmbio"
            :mensagem="`Eliminar o câmbio de ${paraEliminar?.data}? Os registos já criados mantêm o câmbio que guardaram.`"
            :processando="aEliminar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
```

- [ ] **Step 6: Menus — os DOIS sítios**

Em `resources/js/Composables/useConfiguracoesMenu.js`, no grupo `Financeiro`, como **primeira** entrada do grupo:
```js
            { href: '/financeiro/configuracao/moeda-cambio', label: 'Moeda e Câmbio', permissao: 'moeda-cambio.ver' },
```
Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, array `configuracoesMenu`, grupo `Financeiro`, como **primeira** entrada:
```js
            { href: '/financeiro/configuracao/moeda-cambio', title: 'Moeda e Câmbio', permissao: 'moeda-cambio.ver' },
```

- [ ] **Step 7: Build, verificação de limpeza e stage**

Run: `npm run build` (sem erros) e
```bash
grep -rniE "formatKz|centimosParaKz|kwanza|\bKz\b" Modules/Financeiro/resources
```
(não pode devolver nada). Nada gerado (`public/build`) em stage.

```bash
git add Modules/Financeiro resources/js/Composables/useConfiguracoesMenu.js resources/js/Components/Layout/SidebarMenuWrapper.vue
```

(Verificação visual no browser: do controlador, fora da dispatch.)

---

### Task 8: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~150 s)
Expected: 0 falhas. Falhas fora do módulo são regressões deste plano (em especial a matriz de isolamento, contagens de provisionadores e de módulos de permissão): corrigir seguindo a convenção, sem enfraquecer nenhum teste, e documentar quais e porquê.

- [ ] **Step 2: Limpeza**

Run:
```bash
grep -rnE "deCentimos|centimos\(\)|deKwanzas|ValorEmKwanzas" Modules tests --include='*.php'
grep -rniE "formatKz|centimosParaKz|kwanza" Modules/Financeiro/resources Modules/Financeiro/app --include='*.php' --include='*.vue' --include='*.js'
```
Expected: o primeiro não devolve nada; o segundo só pode devolver o nome "Kwanza angolano" no registo `Moeda` e o comentário do seeder do câmbio padrão.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 4: Estado do git**

Run: `git status --short`
Expected: só ficheiros deste plano em stage, nada gerado, nenhum commit. Informar o dono de que falta (a) `php artisan migrate`, (b) `php artisan db:seed --force` (módulo 21 e câmbio padrão da plataforma), (c) `php artisan financeiro:sincronizar --todos` (configuração monetária e permissões), (d) a verificação visual no browser, e (e) que o câmbio padrão AOA = 910,00 é um valor configurável, não um câmbio de mercado: para o actualizar, `php artisan financeiro:cambio-plataforma AOA 925,50`.
