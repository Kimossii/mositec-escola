# Financeiro — Fundação e Regras de Cobrança — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Financeiro` com a base partilhada (`Dinheiro`, `EstadoCobranca`, permissões) e a primeira entrega funcional: a tela `Configurações → Financeiro → Regras de Cobrança`.

**Architecture:** Novo módulo nwidart `Modules/Financeiro`, camada fina Controller → Service (leitura) / Action (escrita) com DTO e FormRequest. `RegraCobranca` é 1:1 com o tenant (`unique(tenant_id)`), criada por provisioning idempotente, por um comando de backfill para tenants existentes e, como rede de segurança, pelo `show`. O frontend é uma página Inertia/Vue num grupo novo "Financeiro" do menu de Configurações.

**Tech Stack:** Laravel + nwidart/laravel-modules ^13, Inertia + Vue 3, PHPUnit com SQLite (produção pgsql), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (secções 2, 3 `regras_cobranca`, 4, 6, 7).

## Global Constraints

- Texto de UI, mensagens e comentários em PT-PT.
- Dinheiro: `bigInteger` em **cêntimos**, nunca float. Arredondamento: percentagens para baixo (`intdiv`), divisão por N com a última parcela a absorver o resto.
- Estado de entidades: `estado` + `estado_descricao`. `regras_cobranca` **não tem** estado (é 1:1).
- Tenancy: só `tenant_id`, via `PertenceAoTenant`. O `tenant_id` nunca vem do cliente.
- PHP: importar com `use` no topo, nunca FQN inline. Controllers finos: sem query nem regra de negócio.
- Testes funcionam em SQLite (sem JSON, sem CHECK por SQL cru). Produção é pgsql.
- Aplicar migrations e seeds em bases existentes é do dono do projecto (`migrate`, `db:seed`, `financeiro:sincronizar --todos`). Os testes usam `RefreshDatabase`.
- **Git: só `git add` (stage). Nunca `git commit` sem o dono pedir.**
- Fora deste plano: Métodos de Pagamento, Produtos/Serviços, Planos de Propina, extensão do catálogo `Acao` (`confirmar`, `anular`, `cancelar`, adiada para o plano de Propinas), helper de frontend `formatKz`, enums `Periodicidade` e `TipoMetodoPagamento`.

## Review Focus

1. Tenant sem linha em `regras_cobranca` (tenant criado antes deste módulo) abre a tela sem erro — Task 5 e 6.
2. Execução repetida do provisioning ou do comando de backfill não duplica — Task 5.
3. `permite_negociacao = false` com `desconto_maximo_negociacao = 50` grava 0 — Task 6.
4. Valores fora de faixa (dia 0 ou 29, tolerância -1 ou 91, desconto 101, campos em falta) são rejeitados — Task 6.
5. `tenant_id` forjado no payload é ignorado e a regra do outro tenant não é tocada — Task 6.

## Mapa de ficheiros

```
Modules/Financeiro/
  module.json, composer.json                              (Task 1)
  routes/web.php                                          (Task 1, completado na Task 6)
  app/Providers/{FinanceiroServiceProvider,RouteServiceProvider}.php   (Task 1; Task 5 regista provisionador e comando)
  app/Support/Dinheiro.php                                (Task 2)
  app/Casts/DinheiroCast.php                              (Task 2)
  app/Enums/EstadoCobranca.php                            (Task 3)
  app/Models/RegraCobranca.php                            (Task 5)
  app/Provisioning/ProvisionarRegrasCobranca.php          (Task 5)
  app/Console/SincronizarFinanceiroCommand.php            (Task 5)
  app/DTO/RegraCobrancaDTO.php                            (Task 6)
  app/Http/Requests/AtualizarRegraCobrancaRequest.php     (Task 6)
  app/Actions/AtualizarRegraCobrancaAction.php            (Task 6)
  app/Services/GestaoRegraCobrancaService.php             (Task 6)
  app/Http/Controllers/RegraCobrancaController.php        (Task 6)
  database/migrations/2026_10_08_100000_create_regras_cobranca_table.php   (Task 5)
  resources/js/Pages/RegrasCobranca/Edit.vue              (Task 7)
  tests/Unit/{DinheiroTest,DinheiroCastTest,EstadoCobrancaTest}.php
  tests/Feature/{ModuloFinanceiroTest,PermissoesFinanceiroTest,RegraCobrancaModelTest,RegraCobrancaProvisioningTest,RegraCobrancaTest}.php
Modificados: modules_statuses.json, Modules/Permissao/app/Enums/Modulo.php,
  Modules/Permissao/database/seeders/ModuloSeeder.php,
  Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php,
  Modules/Permissao/tests/Feature/ModuloSeederTest.php,
  resources/js/Composables/useConfiguracoesMenu.js
```

---

### Task 1: Esqueleto do módulo

**Files:**
- Create: `Modules/Financeiro/module.json`, `Modules/Financeiro/composer.json`
- Create: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`, `Modules/Financeiro/app/Providers/RouteServiceProvider.php`
- Create: `Modules/Financeiro/routes/web.php`
- Modify: `modules_statuses.json`
- Test: `Modules/Financeiro/tests/Feature/ModuloFinanceiroTest.php`

**Interfaces:**
- Produces: namespace `Modules\Financeiro\`, provider `FinanceiroServiceProvider` com `$commands` e o `register()` para as Tasks seguintes, ficheiro `routes/web.php` carregado com middleware `web`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Nwidart\Modules\Facades\Module;
use Tests\TestCase;

class ModuloFinanceiroTest extends TestCase
{
    public function test_modulo_financeiro_esta_activo(): void
    {
        $this->assertTrue(Module::isEnabled('Financeiro'));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ModuloFinanceiroTest.php`
Expected: FAIL (`Financeiro` não está activo).

- [ ] **Step 3: Criar os ficheiros**

`Modules/Financeiro/module.json`:
```json
{
    "name": "Financeiro",
    "alias": "financeiro",
    "description": "Configuração e operação financeira (propinas, cobrança, pagamentos)",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Financeiro\\Providers\\FinanceiroServiceProvider"
    ],
    "files": []
}
```

`Modules/Financeiro/composer.json`:
```json
{
    "name": "nwidart/financeiro",
    "description": "",
    "authors": [
        {
            "name": "Nicolas Widart",
            "email": "n.widart@gmail.com"
        }
    ],
    "extra": {
        "laravel": {
            "providers": [],
            "aliases": {}
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\Financeiro\\": "app/",
            "Modules\\Financeiro\\Database\\Factories\\": "database/factories/",
            "Modules\\Financeiro\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Financeiro\\Tests\\": "tests/"
        }
    }
}
```

`Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`:
```php
<?php

namespace Modules\Financeiro\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class FinanceiroServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Financeiro';

    protected string $nameLower = 'financeiro';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
```

`Modules/Financeiro/app/Providers/RouteServiceProvider.php`:
```php
<?php

namespace Modules\Financeiro\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Financeiro';

    public function map(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }
}
```

`Modules/Financeiro/routes/web.php`:
```php
<?php

use Illuminate\Support\Facades\Route;

// As rotas são acrescentadas por cada entidade do módulo.
```

Em `modules_statuses.json`, acrescentar antes do `}` final (sem esquecer a vírgula na linha anterior): `"Financeiro": true`.

- [ ] **Step 4: Correr e ver passar**

Run: `composer dump-autoload && php artisan test Modules/Financeiro/tests/Feature/ModuloFinanceiroTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro modules_statuses.json
```

---

### Task 2: `Dinheiro` e `DinheiroCast`

**Files:**
- Create: `Modules/Financeiro/app/Support/Dinheiro.php`, `Modules/Financeiro/app/Casts/DinheiroCast.php`
- Test: `Modules/Financeiro/tests/Unit/DinheiroTest.php`, `Modules/Financeiro/tests/Unit/DinheiroCastTest.php`

**Interfaces:**
- Produces:
  - `Dinheiro::deCentimos(int $centimos): self` (lança `InvalidArgumentException` se negativo)
  - `centimos(): int`, `somar(self): self`, `subtrair(self): self` (lança se o resultado for negativo)
  - `percentagem(int $percentagem): self` (0–100, arredonda para baixo)
  - `dividir(int $partes): array` (lista de `Dinheiro`, a última absorve o resto)
  - `formatar(): string` (ex.: `25.000,00 Kz`)
  - `DinheiroCast` (coluna `bigInteger` ↔ `Dinheiro`; só aceita `Dinheiro`, `int` ≥ 0 ou `null`)

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/DinheiroTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_rejeita_valor_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(-1);
    }

    public function test_somar_e_subtrair(): void
    {
        $a = Dinheiro::deCentimos(2_500_000);
        $b = Dinheiro::deCentimos(100);

        $this->assertSame(2_500_100, $a->somar($b)->centimos());
        $this->assertSame(2_499_900, $a->subtrair($b)->centimos());
    }

    public function test_subtrair_nunca_fica_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->subtrair(Dinheiro::deCentimos(101));
    }

    public function test_percentagem_arredonda_para_baixo(): void
    {
        // 10% de 25.333,33 Kz = 2.533,333 Kz -> 2.533,33 Kz
        $this->assertSame(253_333, Dinheiro::deCentimos(2_533_333)->percentagem(10)->centimos());
        $this->assertSame(0, Dinheiro::deCentimos(2_533_333)->percentagem(0)->centimos());
        $this->assertSame(2_533_333, Dinheiro::deCentimos(2_533_333)->percentagem(100)->centimos());
    }

    public function test_percentagem_fora_da_faixa_e_rejeitada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->percentagem(101);
    }

    public function test_dividir_a_ultima_parcela_absorve_o_resto(): void
    {
        // 25.000,00 Kz / 3
        $partes = Dinheiro::deCentimos(2_500_000)->dividir(3);

        $this->assertSame([833_333, 833_333, 833_334], array_map(fn (Dinheiro $d) => $d->centimos(), $partes));
        $this->assertSame(2_500_000, array_sum(array_map(fn (Dinheiro $d) => $d->centimos(), $partes)));
    }

    public function test_dividir_por_menos_de_uma_parte_e_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->dividir(0);
    }

    public function test_formatar(): void
    {
        $this->assertSame('25.000,00 Kz', Dinheiro::deCentimos(2_500_000)->formatar());
        $this->assertSame('0,05 Kz', Dinheiro::deCentimos(5)->formatar());
        $this->assertSame('1.234.567,89 Kz', Dinheiro::deCentimos(123_456_789)->formatar());
    }
}
```

`Modules/Financeiro/tests/Unit/DinheiroCastTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroCastTest extends TestCase
{
    private function modelo(): Model
    {
        return new class extends Model {};
    }

    public function test_get_devolve_dinheiro(): void
    {
        $valor = (new DinheiroCast())->get($this->modelo(), 'valor', '2500000', []);

        $this->assertInstanceOf(Dinheiro::class, $valor);
        $this->assertSame(2_500_000, $valor->centimos());
    }

    public function test_get_e_set_aceitam_null(): void
    {
        $cast = new DinheiroCast();

        $this->assertNull($cast->get($this->modelo(), 'valor', null, []));
        $this->assertNull($cast->set($this->modelo(), 'valor', null, []));
    }

    public function test_set_aceita_dinheiro_e_inteiro(): void
    {
        $cast = new DinheiroCast();

        $this->assertSame(500, $cast->set($this->modelo(), 'valor', Dinheiro::deCentimos(500), []));
        $this->assertSame(500, $cast->set($this->modelo(), 'valor', 500, []));
    }

    public function test_set_rejeita_float_string_e_negativo(): void
    {
        $cast = new DinheiroCast();

        foreach ([12.5, '12', -1] as $invalido) {
            try {
                $cast->set($this->modelo(), 'valor', $invalido, []);
                $this->fail('Devia rejeitar ' . var_export($invalido, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/Dinheiro.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Valor monetário em cêntimos de Kwanza (1 Kz = 100). Nunca usa float.
 * Regra de arredondamento única do sistema: percentagens arredondam para baixo
 * e, na divisão por N partes, a última absorve o resto (a soma é sempre exacta).
 */
final class Dinheiro
{
    private function __construct(private readonly int $centimos)
    {
    }

    public static function deCentimos(int $centimos): self
    {
        if ($centimos < 0) {
            throw new InvalidArgumentException('O valor monetário não pode ser negativo.');
        }

        return new self($centimos);
    }

    public function centimos(): int
    {
        return $this->centimos;
    }

    public function somar(self $outro): self
    {
        return self::deCentimos($this->centimos + $outro->centimos);
    }

    public function subtrair(self $outro): self
    {
        return self::deCentimos($this->centimos - $outro->centimos);
    }

    public function percentagem(int $percentagem): self
    {
        if ($percentagem < 0 || $percentagem > 100) {
            throw new InvalidArgumentException('A percentagem tem de estar entre 0 e 100.');
        }

        return new self(intdiv($this->centimos * $percentagem, 100));
    }

    /**
     * @return list<self>
     */
    public function dividir(int $partes): array
    {
        if ($partes < 1) {
            throw new InvalidArgumentException('É preciso dividir por pelo menos uma parte.');
        }

        $base = intdiv($this->centimos, $partes);
        $resultado = array_fill(0, $partes - 1, new self($base));
        $resultado[] = new self($this->centimos - $base * ($partes - 1));

        return $resultado;
    }

    public function formatar(): string
    {
        $inteira = intdiv($this->centimos, 100);
        $fraccao = str_pad((string) ($this->centimos % 100), 2, '0', STR_PAD_LEFT);

        return number_format($inteira, 0, ',', '.') . ',' . $fraccao . ' Kz';
    }
}
```

`Modules/Financeiro/app/Casts/DinheiroCast.php`:
```php
<?php

namespace Modules\Financeiro\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;

class DinheiroCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Dinheiro
    {
        return $value === null ? null : Dinheiro::deCentimos((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Dinheiro => $value->centimos(),
            is_int($value) => Dinheiro::deCentimos($value)->centimos(),
            default => throw new InvalidArgumentException('O valor monetário tem de ser Dinheiro ou um inteiro em cêntimos.'),
        };
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support Modules/Financeiro/app/Casts Modules/Financeiro/tests/Unit
```

---

### Task 3: Enum `EstadoCobranca`

**Files:**
- Create: `Modules/Financeiro/app/Enums/EstadoCobranca.php`
- Test: `Modules/Financeiro/tests/Unit/EstadoCobrancaTest.php`

**Interfaces:**
- Produces: `EstadoCobranca` (backed `int`) com os casos `EM_ABERTO=1`, `PARCIALMENTE_PAGA=2`, `PAGA=3`, `CANCELADA=4`, `ANULADA=5`, `PENDENTE=6`, `EM_ATRASO=7`; `label(): string`; `persistido(): bool`; `transicoesPermitidas(): array`; `podeTransitarPara(self): bool`. `PENDENTE` e `EM_ATRASO` são derivados e nunca persistidos. A função `resolver()` é do plano de Propinas.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\EstadoCobranca;
use PHPUnit\Framework\TestCase;

class EstadoCobrancaTest extends TestCase
{
    public function test_so_cinco_estados_sao_persistidos(): void
    {
        $persistidos = array_filter(EstadoCobranca::cases(), fn (EstadoCobranca $e) => $e->persistido());

        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA],
            array_values($persistidos),
        );
    }

    public function test_pendente_e_em_atraso_sao_derivados(): void
    {
        $this->assertFalse(EstadoCobranca::PENDENTE->persistido());
        $this->assertFalse(EstadoCobranca::EM_ATRASO->persistido());
    }

    public function test_transicoes_permitidas(): void
    {
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA],
            EstadoCobranca::EM_ABERTO->transicoesPermitidas(),
        );
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PAGA],
            EstadoCobranca::PARCIALMENTE_PAGA->transicoesPermitidas(),
        );
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA],
            EstadoCobranca::PAGA->transicoesPermitidas(),
        );
    }

    public function test_cancelada_e_anulada_sao_terminais(): void
    {
        $this->assertSame([], EstadoCobranca::CANCELADA->transicoesPermitidas());
        $this->assertSame([], EstadoCobranca::ANULADA->transicoesPermitidas());
    }

    public function test_paga_nao_pode_ser_cancelada_nem_anulada_directamente(): void
    {
        $this->assertFalse(EstadoCobranca::PAGA->podeTransitarPara(EstadoCobranca::CANCELADA));
        $this->assertFalse(EstadoCobranca::PAGA->podeTransitarPara(EstadoCobranca::ANULADA));
        $this->assertFalse(EstadoCobranca::PARCIALMENTE_PAGA->podeTransitarPara(EstadoCobranca::CANCELADA));
    }

    public function test_estados_derivados_nao_tem_transicoes(): void
    {
        $this->assertSame([], EstadoCobranca::PENDENTE->transicoesPermitidas());
        $this->assertSame([], EstadoCobranca::EM_ATRASO->transicoesPermitidas());
    }

    public function test_labels(): void
    {
        $this->assertSame('Em Aberto', EstadoCobranca::EM_ABERTO->label());
        $this->assertSame('Parcialmente Paga', EstadoCobranca::PARCIALMENTE_PAGA->label());
        $this->assertSame('Em Atraso', EstadoCobranca::EM_ATRASO->label());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/EstadoCobrancaTest.php`
Expected: FAIL (enum inexistente).

- [ ] **Step 3: Implementar**

```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de uma cobrança. PENDENTE (período ainda não iniciado) e EM_ATRASO são
 * derivados pela data e nunca persistidos; os restantes cinco são a coluna `estado`.
 * As transições entre EM_ABERTO, PARCIALMENTE_PAGA e PAGA são automáticas (recálculo
 * do valor pago); só CANCELADA e ANULADA são acções manuais, ambas terminais.
 */
enum EstadoCobranca: int
{
    case EM_ABERTO = 1;
    case PARCIALMENTE_PAGA = 2;
    case PAGA = 3;
    case CANCELADA = 4;
    case ANULADA = 5;
    case PENDENTE = 6;
    case EM_ATRASO = 7;

    public function label(): string
    {
        return match ($this) {
            self::EM_ABERTO => 'Em Aberto',
            self::PARCIALMENTE_PAGA => 'Parcialmente Paga',
            self::PAGA => 'Paga',
            self::CANCELADA => 'Cancelada',
            self::ANULADA => 'Anulada',
            self::PENDENTE => 'Pendente',
            self::EM_ATRASO => 'Em Atraso',
        };
    }

    public function persistido(): bool
    {
        return ! in_array($this, [self::PENDENTE, self::EM_ATRASO], true);
    }

    /**
     * @return list<self>
     */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::EM_ABERTO => [self::PARCIALMENTE_PAGA, self::PAGA, self::CANCELADA, self::ANULADA],
            self::PARCIALMENTE_PAGA => [self::EM_ABERTO, self::PAGA],
            self::PAGA => [self::EM_ABERTO, self::PARCIALMENTE_PAGA],
            self::CANCELADA, self::ANULADA, self::PENDENTE, self::EM_ATRASO => [],
        };
    }

    public function podeTransitarPara(self $destino): bool
    {
        return in_array($destino, $this->transicoesPermitidas(), true);
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/EstadoCobrancaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Enums Modules/Financeiro/tests/Unit/EstadoCobrancaTest.php
```

---

### Task 4: Permissão `regra-cobranca`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (acrescentar `REGRA_COBRANCA = 17` no enum, em `slug()` e em `label()`)
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php` (acrescentar a linha 17)
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (ADMIN_ESCOLA recebe `ver` e `editar`)
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php:40` (`17` passa a `18`)
- Test: `Modules/Financeiro/tests/Feature/PermissoesFinanceiroTest.php`

**Interfaces:**
- Produces: abilities `regra-cobranca.ver` e `regra-cobranca.editar`, concedidas ao perfil `ADMIN_ESCOLA` (e a mais nenhum de sistema).

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

class PermissoesFinanceiroTest extends TestCase
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
        $this->assertSame('regra-cobranca', Modulo::REGRA_COBRANCA->slug());
        $this->assertSame(Modulo::REGRA_COBRANCA, Modulo::fromSlug('regra-cobranca'));
        $this->assertSame('Regra de Cobrança', Modulo::REGRA_COBRANCA->label());
    }

    public function test_admin_escola_ve_e_edita_regras_de_cobranca(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        $this->assertTrue(Gate::forUser($admin)->allows('regra-cobranca.ver'));
        $this->assertTrue(Gate::forUser($admin)->allows('regra-cobranca.editar'));
        $this->assertFalse(Gate::forUser($admin)->allows('regra-cobranca.eliminar'));
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            $this->assertFalse(Gate::forUser($user)->allows('regra-cobranca.ver'), $perfil->name);
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesFinanceiroTest.php`
Expected: FAIL (`Modulo::REGRA_COBRANCA` inexistente).

- [ ] **Step 3: Implementar**

Em `Modules/Permissao/app/Enums/Modulo.php`:
- depois de `case SENHA_UTILIZADOR = 16;` acrescentar `case REGRA_COBRANCA = 17;`
- em `slug()`, depois de `self::SENHA_UTILIZADOR => 'senha-utilizador',` acrescentar `self::REGRA_COBRANCA => 'regra-cobranca',`
- em `label()`, depois de `self::SENHA_UTILIZADOR => 'Senha de Utilizador',` acrescentar `self::REGRA_COBRANCA => 'Regra de Cobrança',`

Em `Modules/Permissao/database/seeders/ModuloSeeder.php`, depois da linha do `16`:
```php
            ['nome' => 17, 'descricao' => 'Regra de Cobranca'],
```

Em `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL`, no bloco `Perfil::ADMIN_ESCOLA->value`, depois de `Modulo::MATRICULA->value => [...]`:
```php
            Modulo::REGRA_COBRANCA->value => ['ver', 'editar'],
```

Em `Modules/Permissao/tests/Feature/ModuloSeederTest.php:40` trocar `assertSame(17,` por `assertSame(18,`.

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesFinanceiroTest.php Modules/Permissao`
Expected: PASS (inclui a suite do módulo Permissao, para apanhar contagens ou listagens que dependam dos módulos).

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao Modules/Financeiro/tests/Feature/PermissoesFinanceiroTest.php
```

---

### Task 5: Modelo `RegraCobranca`, provisioning e backfill

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_08_100000_create_regras_cobranca_table.php`
- Create: `Modules/Financeiro/app/Models/RegraCobranca.php`
- Create: `Modules/Financeiro/app/Provisioning/ProvisionarRegrasCobranca.php`
- Create: `Modules/Financeiro/app/Console/SincronizarFinanceiroCommand.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`
- Test: `Modules/Financeiro/tests/Feature/RegraCobrancaModelTest.php`, `Modules/Financeiro/tests/Feature/RegraCobrancaProvisioningTest.php`

**Interfaces:**
- Consumes: `PertenceAoTenant`, `RegistaAutoria`, `ProvisionaTenant`, `ParaTodosOsTenants`, `SincronizarPerfisDeSistemaAction::executar()`.
- Produces:
  - `RegraCobranca::doTenant(): RegraCobranca` (devolve a regra do tenant corrente, criando-a com os defaults se não existir)
  - `RegraCobranca::DEFAULTS` (array)
  - provisionador com `ordem() = 50`
  - comando `financeiro:sincronizar {--tenant=} {--todos}`

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Feature/RegraCobrancaModelTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\RegraCobranca;
use Tests\TestCase;

class RegraCobrancaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_tenant_cria_com_os_defaults(): void
    {
        $regra = RegraCobranca::doTenant();

        $this->assertSame($this->tenant->id, $regra->tenant_id);
        $this->assertSame(10, $regra->dia_vencimento);
        $this->assertSame(5, $regra->dias_tolerancia);
        $this->assertFalse($regra->permite_pagamento_parcial);
        $this->assertFalse($regra->permite_pagamento_antecipado);
        $this->assertFalse($regra->gerar_automaticamente);
        $this->assertFalse($regra->permite_negociacao);
        $this->assertSame(0, $regra->desconto_maximo_negociacao);
    }

    public function test_do_tenant_e_idempotente(): void
    {
        $primeira = RegraCobranca::doTenant();
        $segunda = RegraCobranca::doTenant();

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, RegraCobranca::count());
    }

    public function test_a_bd_impede_uma_segunda_regra_no_mesmo_tenant(): void
    {
        RegraCobranca::doTenant();

        $this->expectException(QueryException::class);

        RegraCobranca::create(RegraCobranca::DEFAULTS);
    }

    public function test_negociacao_desligada_forca_desconto_zero(): void
    {
        $regra = RegraCobranca::doTenant();

        $regra->update(['permite_negociacao' => false, 'desconto_maximo_negociacao' => 50]);

        $this->assertSame(0, $regra->fresh()->desconto_maximo_negociacao);
    }

    public function test_negociacao_ligada_mantem_o_desconto(): void
    {
        $regra = RegraCobranca::doTenant();

        $regra->update(['permite_negociacao' => true, 'desconto_maximo_negociacao' => 50]);

        $this->assertSame(50, $regra->fresh()->desconto_maximo_negociacao);
    }

    public function test_cada_tenant_tem_a_sua_regra(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        RegraCobranca::doTenant()->update(['dia_vencimento' => 20]);
        $doOutro = $this->noTenant($outro, fn () => RegraCobranca::doTenant());

        $this->assertSame(10, $doOutro->dia_vencimento);
        $this->assertSame($outro->id, $doOutro->tenant_id);
        $this->assertSame(20, RegraCobranca::doTenant()->dia_vencimento);
    }
}
```

`Modules/Financeiro/tests/Feature/RegraCobrancaProvisioningTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class RegraCobrancaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function dados(): DadosProvisionamento
    {
        return new DadosProvisionamento('Escola', 'Admin', 'admin@example.com');
    }

    public function test_provisionador_esta_registado_e_ordem_e_unica(): void
    {
        $provisionadores = collect(app()->tagged(ProvisionaTenant::ETIQUETA));

        $this->assertTrue($provisionadores->contains(fn ($p) => $p instanceof ProvisionarRegrasCobranca));
        $this->assertSame(
            $provisionadores->count(),
            $provisionadores->map(fn (ProvisionaTenant $p) => $p->ordem())->unique()->count(),
        );
    }

    public function test_provisionar_duas_vezes_nao_duplica(): void
    {
        $provisionador = app(ProvisionarRegrasCobranca::class);

        $provisionador->provisionar($this->tenant->paraTenantAtual(), $this->dados());
        $provisionador->provisionar($this->tenant->paraTenantAtual(), $this->dados());

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_comando_cria_as_regras_em_falta_e_concede_permissoes(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->assertSame(0, DB::table('regras_cobranca')->count());

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_comando_exige_tenant_ou_todos(): void
    {
        $this->artisan('financeiro:sincronizar')->assertFailed();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/RegraCobrancaModelTest.php Modules/Financeiro/tests/Feature/RegraCobrancaProvisioningTest.php`
Expected: FAIL (modelo, tabela, provisionador e comando inexistentes).

- [ ] **Step 3: Implementar**

Migration `Modules/Financeiro/database/migrations/2026_10_08_100000_create_regras_cobranca_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regras_cobranca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedTinyInteger('dia_vencimento')->default(10);
            $table->unsignedTinyInteger('dias_tolerancia')->default(5);
            $table->boolean('permite_pagamento_parcial')->default(false);
            $table->boolean('permite_pagamento_antecipado')->default(false);
            $table->boolean('gerar_automaticamente')->default(false);
            $table->boolean('permite_negociacao')->default(false);
            $table->unsignedTinyInteger('desconto_maximo_negociacao')->default(0);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regras_cobranca');
    }
};
```

`Modules/Financeiro/app/Models/RegraCobranca.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

class RegraCobranca extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    public const DEFAULTS = [
        'dia_vencimento' => 10,
        'dias_tolerancia' => 5,
        'permite_pagamento_parcial' => false,
        'permite_pagamento_antecipado' => false,
        'gerar_automaticamente' => false,
        'permite_negociacao' => false,
        'desconto_maximo_negociacao' => 0,
    ];

    protected $table = 'regras_cobranca';

    protected $fillable = [
        'dia_vencimento',
        'dias_tolerancia',
        'permite_pagamento_parcial',
        'permite_pagamento_antecipado',
        'gerar_automaticamente',
        'permite_negociacao',
        'desconto_maximo_negociacao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'dia_vencimento' => 'integer',
        'dias_tolerancia' => 'integer',
        'permite_pagamento_parcial' => 'boolean',
        'permite_pagamento_antecipado' => 'boolean',
        'gerar_automaticamente' => 'boolean',
        'permite_negociacao' => 'boolean',
        'desconto_maximo_negociacao' => 'integer',
    ];

    protected static function booted(): void
    {
        // Sem negociação não há desconto: invariante garantida em qualquer caminho de escrita.
        static::saving(function (self $regra) {
            if (! $regra->permite_negociacao) {
                $regra->desconto_maximo_negociacao = 0;
            }
        });
    }

    /**
     * A regra do tenant corrente; cria-a com os defaults se ainda não existir.
     */
    public static function doTenant(): self
    {
        try {
            return static::firstOrCreate([], static::DEFAULTS);
        } catch (UniqueConstraintViolationException) {
            // Pedido concorrente criou-a entre a leitura e a escrita.
            return static::query()->firstOrFail();
        }
    }
}
```

`Modules/Financeiro/app/Provisioning/ProvisionarRegrasCobranca.php`:
```php
<?php

namespace Modules\Financeiro\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\RegraCobranca;

/**
 * Ordem 50: regras de cobrança com os defaults. Idempotente.
 */
class ProvisionarRegrasCobranca implements ProvisionaTenant
{
    public function ordem(): int
    {
        return 50;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        RegraCobranca::doTenant();
    }
}
```

`Modules/Financeiro/app/Console/SincronizarFinanceiroCommand.php`:
```php
<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;

/**
 * Põe tenants existentes em dia com o módulo Financeiro: cria as regras de cobrança em
 * falta e concede aos perfis de sistema as permissões novas. Idempotente.
 * Pré-requisito: `php artisan db:seed --force` (catálogo global de módulos e acções).
 */
class SincronizarFinanceiroCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:sincronizar';

    protected $description = 'Cria as regras de cobrança e as permissões do Financeiro nos tenants existentes';

    public function handle(SincronizarPerfisDeSistemaAction $perfis): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($perfis): void {
            RegraCobranca::doTenant();
            $perfis->executar();
        });
    }
}
```

Em `FinanceiroServiceProvider`, acrescentar os imports e os membros:
```php
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Financeiro\Console\SincronizarFinanceiroCommand;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
```
```php
    /**
     * @var string[]
     */
    protected array $commands = [
        SincronizarFinanceiroCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->tag([ProvisionarRegrasCobranca::class], ProvisionaTenant::ETIQUETA);
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro Modules/Core Modules/Tenant`
Expected: PASS. Se algum teste existente do provisioning assumir um número fixo de provisionadores ou de tabelas, ajustar a contagem nesse teste (e dizer qual).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 6: Backend da tela (DTO, Request, Action, Service, Controller, rotas)

**Files:**
- Create: `Modules/Financeiro/app/DTO/RegraCobrancaDTO.php`, `Modules/Financeiro/app/Http/Requests/AtualizarRegraCobrancaRequest.php`, `Modules/Financeiro/app/Actions/AtualizarRegraCobrancaAction.php`, `Modules/Financeiro/app/Services/GestaoRegraCobrancaService.php`, `Modules/Financeiro/app/Http/Controllers/RegraCobrancaController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/RegraCobrancaTest.php`

**Interfaces:**
- Consumes: `RegraCobranca::doTenant()`, abilities `regra-cobranca.ver|editar`.
- Produces: rotas `GET /financeiro/configuracao/regras-cobranca` (`financeiro.configuracao.regras-cobranca.show`) e `PUT` na mesma URL (`...update`); componente Inertia `Financeiro/RegrasCobranca/Edit` com a prop `regra` (campos do formulário, sem `tenant_id`).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegraCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/financeiro/configuracao/regras-cobranca';

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

    private function payload(array $sobrepor = []): array
    {
        return array_merge([
            'dia_vencimento' => 10,
            'dias_tolerancia' => 5,
            'permite_pagamento_parcial' => true,
            'permite_pagamento_antecipado' => false,
            'gerar_automaticamente' => false,
            'permite_negociacao' => false,
            'desconto_maximo_negociacao' => 0,
        ], $sobrepor);
    }

    public function test_admin_ve_a_tela_com_os_defaults(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Financeiro/RegrasCobranca/Edit')
                ->where('regra.dia_vencimento', 10)
                ->where('regra.dias_tolerancia', 5)
                ->missing('regra.tenant_id'));
    }

    public function test_tela_abre_num_tenant_sem_regra_e_cria_os_defaults(): void
    {
        DB::table('regras_cobranca')->delete();

        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))->get(self::URL)->assertOk();

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_professor_nao_ve_nem_edita(): void
    {
        $professor = $this->utilizadorCom(Perfil::PROFESSOR);

        $this->actingAs($professor)->get(self::URL)->assertForbidden();
        $this->actingAs($professor)->put(self::URL, $this->payload())->assertForbidden();
    }

    public function test_visitante_e_redireccionado_para_o_login(): void
    {
        $this->get(self::URL)->assertRedirect();
    }

    public function test_admin_actualiza_as_regras(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload([
                'dia_vencimento' => 15,
                'dias_tolerancia' => 7,
                'permite_pagamento_parcial' => true,
                'permite_pagamento_antecipado' => true,
                'gerar_automaticamente' => true,
                'permite_negociacao' => true,
                'desconto_maximo_negociacao' => 30,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $regra = RegraCobranca::doTenant();
        $this->assertSame(15, $regra->dia_vencimento);
        $this->assertSame(7, $regra->dias_tolerancia);
        $this->assertTrue($regra->permite_pagamento_parcial);
        $this->assertTrue($regra->permite_pagamento_antecipado);
        $this->assertTrue($regra->gerar_automaticamente);
        $this->assertTrue($regra->permite_negociacao);
        $this->assertSame(30, $regra->desconto_maximo_negociacao);
    }

    public function test_pode_desligar_todas_as_opcoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        RegraCobranca::doTenant()->update(['permite_pagamento_parcial' => true]);

        $this->actingAs($admin)
            ->put(self::URL, $this->payload(['permite_pagamento_parcial' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(RegraCobranca::doTenant()->permite_pagamento_parcial);
    }

    public function test_negociacao_desligada_grava_desconto_zero(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload(['permite_negociacao' => false, 'desconto_maximo_negociacao' => 50]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, RegraCobranca::doTenant()->desconto_maximo_negociacao);
    }

    #[DataProvider('valoresInvalidos')]
    public function test_valores_invalidos_sao_rejeitados(string $campo, mixed $valor): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->from(self::URL)
            ->put(self::URL, $this->payload([$campo => $valor]))
            ->assertSessionHasErrors($campo);

        $this->assertSame(10, RegraCobranca::doTenant()->dia_vencimento);
    }

    public static function valoresInvalidos(): array
    {
        return [
            'dia 0' => ['dia_vencimento', 0],
            'dia 29' => ['dia_vencimento', 29],
            'tolerância negativa' => ['dias_tolerancia', -1],
            'tolerância 91' => ['dias_tolerancia', 91],
            'desconto 101' => ['desconto_maximo_negociacao', 101],
            'desconto negativo' => ['desconto_maximo_negociacao', -1],
            'dia não numérico' => ['dia_vencimento', 'abc'],
            'booleano inválido' => ['gerar_automaticamente', 'talvez'],
        ];
    }

    public function test_campos_em_falta_sao_rejeitados(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->from(self::URL)
            ->put(self::URL, [])
            ->assertSessionHasErrors(['dia_vencimento', 'dias_tolerancia', 'permite_pagamento_parcial']);
    }

    public function test_tenant_id_forjado_e_ignorado_e_o_outro_tenant_nao_e_tocado(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $regraDoOutro = $this->noTenant($outro, fn () => RegraCobranca::doTenant());

        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload(['dia_vencimento' => 25, 'tenant_id' => $outro->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(25, RegraCobranca::doTenant()->dia_vencimento);
        $this->assertSame($this->tenant->id, RegraCobranca::doTenant()->tenant_id);
        $this->assertSame(10, DB::table('regras_cobranca')->where('id', $regraDoOutro->id)->value('dia_vencimento'));
        $this->assertSame(2, DB::table('regras_cobranca')->count());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/RegraCobrancaTest.php`
Expected: FAIL (404: rotas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Http/Requests/AtualizarRegraCobrancaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarRegraCobrancaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('regra-cobranca.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'dia_vencimento' => 'required|integer|between:1,28',
            'dias_tolerancia' => 'required|integer|between:0,90',
            'permite_pagamento_parcial' => 'required|boolean',
            'permite_pagamento_antecipado' => 'required|boolean',
            'gerar_automaticamente' => 'required|boolean',
            'permite_negociacao' => 'required|boolean',
            'desconto_maximo_negociacao' => 'required|integer|between:0,100',
        ];
    }

    public function messages(): array
    {
        return [
            'dia_vencimento.between' => 'O dia de vencimento tem de estar entre 1 e 28.',
            'dias_tolerancia.between' => 'Os dias de tolerância têm de estar entre 0 e 90.',
            'desconto_maximo_negociacao.between' => 'O desconto máximo tem de estar entre 0% e 100%.',
        ];
    }
}
```

`Modules/Financeiro/app/DTO/RegraCobrancaDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;

class RegraCobrancaDTO
{
    public function __construct(
        public int $dia_vencimento,
        public int $dias_tolerancia,
        public bool $permite_pagamento_parcial,
        public bool $permite_pagamento_antecipado,
        public bool $gerar_automaticamente,
        public bool $permite_negociacao,
        public int $desconto_maximo_negociacao,
    ) {
    }

    public static function fromRequest(AtualizarRegraCobrancaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dia_vencimento: (int) $dados['dia_vencimento'],
            dias_tolerancia: (int) $dados['dias_tolerancia'],
            permite_pagamento_parcial: (bool) $dados['permite_pagamento_parcial'],
            permite_pagamento_antecipado: (bool) $dados['permite_pagamento_antecipado'],
            gerar_automaticamente: (bool) $dados['gerar_automaticamente'],
            permite_negociacao: (bool) $dados['permite_negociacao'],
            desconto_maximo_negociacao: (int) $dados['desconto_maximo_negociacao'],
        );
    }
}
```

`Modules/Financeiro/app/Actions/AtualizarRegraCobrancaAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Models\RegraCobranca;

class AtualizarRegraCobrancaAction
{
    public function executar(RegraCobrancaDTO $dto): RegraCobranca
    {
        return DB::transaction(function () use ($dto) {
            $regra = RegraCobranca::doTenant();

            $regra->fill([
                'dia_vencimento' => $dto->dia_vencimento,
                'dias_tolerancia' => $dto->dias_tolerancia,
                'permite_pagamento_parcial' => $dto->permite_pagamento_parcial,
                'permite_pagamento_antecipado' => $dto->permite_pagamento_antecipado,
                'gerar_automaticamente' => $dto->gerar_automaticamente,
                'permite_negociacao' => $dto->permite_negociacao,
                'desconto_maximo_negociacao' => $dto->desconto_maximo_negociacao,
            ])->save();

            return $regra->fresh();
        });
    }
}
```

`Modules/Financeiro/app/Services/GestaoRegraCobrancaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AtualizarRegraCobrancaAction;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Models\RegraCobranca;

class GestaoRegraCobrancaService
{
    public function __construct(
        private AtualizarRegraCobrancaAction $atualizarAction,
    ) {
    }

    public function obterAtual(): RegraCobranca
    {
        return RegraCobranca::doTenant();
    }

    public function atualizar(AtualizarRegraCobrancaRequest $request): RegraCobranca
    {
        return $this->atualizarAction->executar(RegraCobrancaDTO::fromRequest($request));
    }
}
```

`Modules/Financeiro/app/Http/Controllers/RegraCobrancaController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Services\GestaoRegraCobrancaService;

class RegraCobrancaController extends Controller
{
    public function __construct(
        private GestaoRegraCobrancaService $service,
    ) {
    }

    public function show()
    {
        $this->authorize('regra-cobranca.ver');

        return Inertia::render('Financeiro/RegrasCobranca/Edit', [
            'regra' => $this->service->obterAtual(),
        ]);
    }

    public function update(AtualizarRegraCobrancaRequest $request)
    {
        $this->authorize('regra-cobranca.editar');

        $this->service->atualizar($request);

        return redirect()->back()->with('success', 'Regras de cobrança atualizadas com sucesso.');
    }
}
```

`Modules/Financeiro/routes/web.php` (substitui o conteúdo):
```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\RegraCobrancaController;

Route::middleware(['auth'])->prefix('financeiro/configuracao')->name('financeiro.configuracao.')->group(function () {
    Route::get('/regras-cobranca', [RegraCobrancaController::class, 'show'])->middleware('can:regra-cobranca.ver')->name('regras-cobranca.show');
    Route::put('/regras-cobranca', [RegraCobrancaController::class, 'update'])->middleware('can:regra-cobranca.editar')->name('regras-cobranca.update');
});
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS (todo o módulo).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 7: Menu e página Vue

**Files:**
- Modify: `resources/js/Composables/useConfiguracoesMenu.js`
- Create: `Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue`

**Interfaces:**
- Consumes: rota `GET/PUT /financeiro/configuracao/regras-cobranca`; prop `regra` com `dia_vencimento`, `dias_tolerancia`, `permite_pagamento_parcial`, `permite_pagamento_antecipado`, `gerar_automaticamente`, `permite_negociacao`, `desconto_maximo_negociacao`; helper `can()` de `@/Composables/usePermissoes`.

- [ ] **Step 1: Acrescentar o grupo ao menu**

Em `useConfiguracoesMenu.js`, no array `gruposConfiguracoes`, depois do grupo `Ano Lectivo`:
```js
    {
        title: 'Financeiro',
        links: [
            { href: '/financeiro/configuracao/regras-cobranca', label: 'Regras de Cobrança', permissao: 'regra-cobranca.ver' },
        ],
    },
```

- [ ] **Step 2: Criar a página**

`Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';

const props = defineProps({
    regra: {
        type: Object,
        required: true,
    },
});
defineOptions({ layout: AppLayout });

const podeEditar = computed(() => can('regra-cobranca.editar'));

function snapshot() {
    return {
        dia_vencimento: props.regra.dia_vencimento,
        dias_tolerancia: props.regra.dias_tolerancia,
        permite_pagamento_parcial: props.regra.permite_pagamento_parcial,
        permite_pagamento_antecipado: props.regra.permite_pagamento_antecipado,
        gerar_automaticamente: props.regra.gerar_automaticamente,
        permite_negociacao: props.regra.permite_negociacao,
        desconto_maximo_negociacao: props.regra.desconto_maximo_negociacao,
    };
}

const form = reactive(snapshot());
const errors = ref({});
const processing = ref(false);

watch(() => form.permite_negociacao, (ligada) => {
    if (!ligada) form.desconto_maximo_negociacao = 0;
});

function submeter() {
    processing.value = true;
    errors.value = {};

    router.put('/financeiro/configuracao/regras-cobranca', form, {
        preserveScroll: true,
        onSuccess: () => toast.success('Regras de cobrança atualizadas com sucesso.'),
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Regras de Cobrança</h1>
            <p class="text-muted fs-6 mb-0" style="max-width: 640px">
                Define como a escola cobra: vencimento, tolerância e o que é permitido nos pagamentos.
                Alterações aplicam-se apenas a cobranças futuras.
            </p>
        </div>

        <form @submit.prevent="submeter">
            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Vencimento</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label for="dia_vencimento" class="form-label fw-semibold">Dia de vencimento</label>
                            <input
                                id="dia_vencimento" v-model.number="form.dia_vencimento" type="number" min="1" max="28"
                                class="form-control" :class="{ 'is-invalid': errors.dia_vencimento }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.dia_vencimento" class="invalid-feedback">{{ errors.dia_vencimento }}</div>
                            <div class="form-text">Entre 1 e 28, para existir em todos os meses.</div>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label for="dias_tolerancia" class="form-label fw-semibold">Dias de tolerância</label>
                            <input
                                id="dias_tolerancia" v-model.number="form.dias_tolerancia" type="number" min="0" max="90"
                                class="form-control" :class="{ 'is-invalid': errors.dias_tolerancia }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.dias_tolerancia" class="invalid-feedback">{{ errors.dias_tolerancia }}</div>
                            <div class="form-text">Dias corridos após o vencimento antes de a cobrança ficar em atraso.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamentos e geração</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_pagamento_parcial" v-model="form.permite_pagamento_parcial" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_pagamento_parcial" class="form-check-label fw-semibold">Permitir pagamento parcial</label>
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_pagamento_antecipado" v-model="form.permite_pagamento_antecipado" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_pagamento_antecipado" class="form-check-label fw-semibold">Permitir pagamento antecipado</label>
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid">
                        <input id="gerar_automaticamente" v-model="form.gerar_automaticamente" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="gerar_automaticamente" class="form-check-label fw-semibold">Gerar cobranças automaticamente</label>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Negociação de dívidas</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_negociacao" v-model="form.permite_negociacao" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_negociacao" class="form-check-label fw-semibold">Permitir negociação de dívidas</label>
                    </div>
                    <div v-if="form.permite_negociacao" class="row">
                        <div class="col-md-4">
                            <label for="desconto_maximo_negociacao" class="form-label fw-semibold">Desconto máximo (%)</label>
                            <input
                                id="desconto_maximo_negociacao" v-model.number="form.desconto_maximo_negociacao" type="number" min="0" max="100"
                                class="form-control" :class="{ 'is-invalid': errors.desconto_maximo_negociacao }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.desconto_maximo_negociacao" class="invalid-feedback">{{ errors.desconto_maximo_negociacao }}</div>
                            <div class="form-text">0 permite apenas renegociar prazos, sem desconto.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="podeEditar" class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" :disabled="processing">
                    {{ processing ? 'A guardar…' : 'Guardar alterações' }}
                </button>
            </div>
        </form>
    </div>
</template>
```

- [ ] **Step 3: Compilar**

Run: `npm run build`
Expected: build conclui sem erros e sem avisos de componente em falta.

- [ ] **Step 4: Verificação visual (com o app a correr)**

Entrar com `admin@mositec.gmail.com`, abrir Configurações → Financeiro → Regras de Cobrança e confirmar:
- o item de menu aparece e fica activo na rota;
- os switches e inputs estão alinhados com os cartões existentes, nos temas claro e escuro;
- desligar "Permitir negociação" esconde o desconto; ligar mostra-o;
- guardar mostra o toast de sucesso, e o valor 29 no dia mostra o erro inline;
- medir com `getComputedStyle` qualquer diferença de espaçamento que pareça suspeita em vez de confiar só na imagem.

Aplicação em bases existentes (acção do dono): `php artisan migrate`, `php artisan db:seed --force`, `php artisan financeiro:sincronizar --todos`.

- [ ] **Step 5: Stage**

```bash
git add resources/js/Composables/useConfiguracoesMenu.js Modules/Financeiro/resources
```

---

### Task 8: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test`
Expected: tudo verde (a base eram 1815 testes verdes; a contagem sobe com os novos). Qualquer falha em testes de outros módulos é regressão deste plano: corrigir antes de avançar.

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 3: Estado do git**

Run: `git status --short`
Expected: apenas ficheiros deste plano em stage, sem commit. Informar o dono de que está pronto para ele fazer o commit.
