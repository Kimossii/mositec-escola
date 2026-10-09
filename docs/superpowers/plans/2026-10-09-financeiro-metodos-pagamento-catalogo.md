# Financeiro — Métodos de Pagamento e Catálogo (Produtos / Serviços) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar as telas `Configurações → Financeiro → Métodos de Pagamento` e `Configurações → Financeiro → Produtos / Serviços`, com o contrato de eliminação `ReferenciaFinanceira` e a conversão Kz → cêntimos.

**Architecture:** Continua o módulo `Modules/Financeiro` do plano 1. Três entidades de configuração tenant-aware (`MetodoPagamento`, `Produto`, `Servico`), cada uma com Request/DTO/Action/Service finos, activar/desactivar e eliminar (bloqueada por `ReferenciaFinanceira`). Produto e Serviço mantêm-se entidades distintas; só a listagem e a página são unificadas (`CatalogoConsultaService`, tabs Todos/Produtos/Serviços).

**Tech Stack:** Laravel + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit com SQLite (produção pgsql), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (secções 2, 3 `metodos_pagamento`, `produtos` e `servicos`, 5 contratos 5 e 7, 6, 7, 8) e `docs/superpowers/specs/2026-10-08-configuracao-produtos-servicos.md` (domínio Produto/Serviço).

## Global Constraints

- Texto de UI, mensagens e comentários em PT-PT.
- Dinheiro: `bigInteger` em **cêntimos**, nunca float. Os formulários recebem Kz (`25000`, `25000.5`, `25000,50`) e convertem com `Dinheiro::deKwanzas`.
- Estado: `estado` + `estado_descricao` (`SincronizaEstadoDescricao`, enum `Modules\Core\Enums\Estado`), autoria com `RegistaAutoria`. Enums inteiros têm a coluna irmã `*_descricao`.
- Tenancy: só `tenant_id`, via `PertenceAoTenant`; nunca vem do cliente. Unicidade sempre **por tenant**.
- Permissões por recurso: `metodo-pagamento.*` e `catalogo-financeiro.*` (ver, criar, editar, eliminar). Sem mecanismo paralelo de autorização.
- Produto e Serviço são entidades distintas: nenhuma tabela `produtos_servicos`. Menu: **uma só** entrada "Produtos / Serviços".
- Preço ≥ 0 (zero permitido). `codigo` opcional e único por tenant. Itens inactivos nunca desaparecem da listagem; só ficam fora de `Produto::activos()` / `Servico::activos()`.
- PHP: `use` no topo, nunca FQN inline. Controllers finos (sem query nem regra de negócio): leitura via Service, escrita via Action, com DTO e FormRequest.
- Testes funcionam em SQLite (sem JSON, sem CHECK por SQL cru). Produção é pgsql.
- **Menus: acrescentar SEMPRE nos dois sítios** — `resources/js/Composables/useConfiguracoesMenu.js` (cabeçalho, chaves `links/label`) e `resources/js/Components/Layout/SidebarMenuWrapper.vue` (lateral, chaves `items/title`, array `configuracoesMenu`).
- Módulo novo com models de tenant tem de estar declarado em `TESTE_DE_TENANCY_POR_MODULO` (já está: `Financeiro`). A suite completa (`php artisan test`) é o critério de aceitação final.
- Aplicar migrations e seeds em bases existentes é do dono do projecto (`migrate`, `db:seed --force`, `financeiro:sincronizar --todos`). Os testes usam `RefreshDatabase`.
- **Git: só `git add` (stage). Nunca `git commit` sem o dono pedir.**
- Fora deste plano: Planos de Propina, Propinas, Pagamentos, stock, vendas, fiscalidade, relação de Produtos/Serviços com matrículas ou propinas, grelha de permissões sem acções sem sentido (decisão do plano de Propinas).

## Review Focus

1. Nome (métodos) ou código (produtos/serviços) igual noutro tenant é permitido; no mesmo tenant é rejeitado. Vários itens sem código no mesmo tenant são permitidos — Tasks 4, 5, 6.
2. Preço inválido (`-1`, `abc`, `12.345`, vazio, `1e3`) é rejeitado; `0`, `25000`, `25000.5` e `25000,50` são aceites e gravados em cêntimos exactos — Tasks 2, 5, 6.
3. PUT, PATCH e DELETE com o id de um registo de outro tenant dão 404 e nada é alterado; `tenant_id` forjado no payload é ignorado — Tasks 4, 5, 6.
4. Eliminar com uma referência financeira registada é bloqueado sem apagar nada; sem referências apaga — Tasks 1, 4, 5, 6.
5. Desactivar um item mantém-no na listagem com `estado_descricao` correcto e fora de `activos()`; o filtro por tipo e a paginação do catálogo (11 itens → página 2) funcionam — Tasks 5, 6, 7.

## Mapa de ficheiros

```
Modules/Financeiro/
  app/Contracts/ReferenciaFinanceira.php                         (T1)
  app/Support/ReferenciasFinanceiras.php                         (T1)
  app/Support/Dinheiro.php                                       (T2, modifica)
  app/Enums/TipoMetodoPagamento.php                              (T4)
  app/Models/{MetodoPagamento,Produto,Servico}.php               (T4,T5,T6)
  app/DTO/{MetodoPagamentoDTO,ProdutoDTO,ServicoDTO}.php         (T4,T5,T6)
  app/Http/Requests/{Criar,Atualizar,AlterarEstado}{MetodoPagamento,Produto,Servico}Request.php
  app/Actions/{Criar,Atualizar,AlterarEstado,Eliminar}{MetodoPagamento,Produto,Servico}Action.php
  app/Services/{GestaoMetodoPagamento,MetodoPagamentoConsulta,GestaoProduto,GestaoServico,CatalogoConsulta}Service.php
  app/Http/Controllers/{MetodoPagamento,Produto,Servico,CatalogoFinanceiro}Controller.php
  database/migrations/2026_10_09_{110000_create_metodos_pagamento,120000_create_produtos,130000_create_servicos}_table.php
  routes/web.php                                                 (T4–T7, modifica)
  resources/js/Support/dinheiro.js                               (T2)
  resources/js/Models/Estado.js, Components/Shared/EstadoBadge.vue                 (T8)
  resources/js/Components/MetodosPagamento/MetodoPagamentoFormModal.vue            (T8)
  resources/js/Pages/MetodosPagamento/Index.vue                                    (T8)
  resources/js/Components/ProdutosServicos/CatalogoItemFormModal.vue               (T9)
  resources/js/Pages/ProdutosServicos/Index.vue                                    (T9)
  tests/Concerns/ComUtilizadoresFinanceiro.php, tests/Feature/*, tests/Unit/*
Modificados: Modules/Permissao/{app/Enums/Modulo.php, database/seeders/ModuloSeeder.php,
  app/Actions/SincronizarPerfisDeSistemaAction.php, tests/Feature/ModuloSeederTest.php},
  resources/js/Composables/useConfiguracoesMenu.js, resources/js/Components/Layout/SidebarMenuWrapper.vue
```

---

### Task 1: Contrato `ReferenciaFinanceira`

**Files:**
- Create: `Modules/Financeiro/app/Contracts/ReferenciaFinanceira.php`, `Modules/Financeiro/app/Support/ReferenciasFinanceiras.php`
- Test: `Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php`

**Interfaces:**
- Produces:
  - `interface ReferenciaFinanceira { public function existeReferenciaA(Model $configuracao): bool; }` — implementada pelos módulos operacionais futuros;
  - `ReferenciasFinanceiras` (constante `ETIQUETA = 'financeiro.referencias'`) com `existeReferenciaA(Model $configuracao): bool`, que consulta todas as implementações registadas com essa etiqueta no container. `false` quando não há nenhuma.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Tests\TestCase;

class ReferenciasFinanceirasTest extends TestCase
{
    private function referencia(bool $resposta): ReferenciaFinanceira
    {
        return new class($resposta) implements ReferenciaFinanceira {
            public ?Model $recebido = null;

            public function __construct(private bool $resposta)
            {
            }

            public function existeReferenciaA(Model $configuracao): bool
            {
                $this->recebido = $configuracao;

                return $this->resposta;
            }
        };
    }

    private function registar(string $nome, ReferenciaFinanceira $referencia): void
    {
        $this->app->instance($nome, $referencia);
        $this->app->tag([$nome], ReferenciasFinanceiras::ETIQUETA);
    }

    public function test_sem_implementacoes_nao_ha_referencia(): void
    {
        $this->assertFalse(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_todas_negam_nao_ha_referencia(): void
    {
        $this->registar('ref.a', $this->referencia(false));
        $this->registar('ref.b', $this->referencia(false));

        $this->assertFalse(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_basta_uma_afirmar_para_haver_referencia(): void
    {
        $this->registar('ref.a', $this->referencia(false));
        $this->registar('ref.b', $this->referencia(true));

        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_a_implementacao_recebe_o_modelo_consultado(): void
    {
        $referencia = $this->referencia(false);
        $this->registar('ref.a', $referencia);
        $modelo = new class extends Model {};

        app(ReferenciasFinanceiras::class)->existeReferenciaA($modelo);

        $this->assertSame($modelo, $referencia->recebido);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Contracts/ReferenciaFinanceira.php`:
```php
<?php

namespace Modules\Financeiro\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implementada pelos módulos operacionais (propinas, pagamentos, vendas...) para dizerem
 * se algum registo seu usa uma configuração financeira (método de pagamento, produto,
 * serviço, plano). Impede eliminar configuração com histórico: desactiva-se em vez disso.
 * Registo no container: $this->app->tag([Classe::class], ReferenciasFinanceiras::ETIQUETA).
 */
interface ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool;
}
```

`Modules/Financeiro/app/Support/ReferenciasFinanceiras.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;

class ReferenciasFinanceiras
{
    public const ETIQUETA = 'financeiro.referencias';

    public function __construct(private Container $container)
    {
    }

    public function existeReferenciaA(Model $configuracao): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $referencia) {
            /** @var ReferenciaFinanceira $referencia */
            if ($referencia->existeReferenciaA($configuracao)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php`
Expected: PASS (4 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Contracts Modules/Financeiro/app/Support/ReferenciasFinanceiras.php Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php
```

---

### Task 2: `Dinheiro::deKwanzas` e helper de frontend

**Files:**
- Modify: `Modules/Financeiro/app/Support/Dinheiro.php`
- Modify: `Modules/Financeiro/tests/Unit/DinheiroTest.php`
- Create: `Modules/Financeiro/resources/js/Support/dinheiro.js`

**Interfaces:**
- Produces:
  - `Dinheiro::deKwanzas(int|string $kwanzas): self` — aceita inteiro (Kz) ou texto `^\d{1,12}([.,]\d{1,2})?$` (ponto ou vírgula decimal, 1 ou 2 casas, sem separador de milhares); lança `InvalidArgumentException` caso contrário; nunca usa float;
  - JS: `formatKz(centimos): string` (`25.000,00 Kz`) e `centimosParaKz(centimos): string` (`'25000.50'`, para preencher `<input>`).

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `Modules/Financeiro/tests/Unit/DinheiroTest.php`, antes do `}` final da classe:
```php
    public function test_de_kwanzas_converte_inteiros_e_texto_para_centimos(): void
    {
        $this->assertSame(2_500_000, Dinheiro::deKwanzas(25_000)->centimos());
        $this->assertSame(2_500_000, Dinheiro::deKwanzas('25000')->centimos());
        $this->assertSame(2_500_050, Dinheiro::deKwanzas('25000.5')->centimos());
        $this->assertSame(2_500_050, Dinheiro::deKwanzas('25000,50')->centimos());
        $this->assertSame(5, Dinheiro::deKwanzas('0.05')->centimos());
        $this->assertSame(0, Dinheiro::deKwanzas('0')->centimos());
        $this->assertSame(0, Dinheiro::deKwanzas(0)->centimos());
    }

    #[DataProvider('kwanzasInvalidos')]
    public function test_de_kwanzas_rejeita_valores_invalidos(int|string $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deKwanzas($valor);
    }

    public static function kwanzasInvalidos(): array
    {
        return [
            'vazio' => [''],
            'negativo texto' => ['-1'],
            'negativo inteiro' => [-1],
            'letras' => ['abc'],
            'três casas decimais' => ['12.345'],
            'milhares com ponto' => ['25.000,50'],
            'notação científica' => ['1e3'],
            'espaços' => ['25 000'],
            'quebra de linha final' => ["25000\n"],
            'demasiado grande' => ['1234567890123'],
        ];
    }
```
Acrescentar ao topo do ficheiro `use PHPUnit\Framework\Attributes\DataProvider;` (o projecto usa PHPUnit 11 e atributos, não anotações).

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DinheiroTest.php`
Expected: FAIL (`deKwanzas` inexistente).

- [ ] **Step 3: Implementar**

Em `Dinheiro.php`, depois de `deCentimos()`:
```php
    /**
     * Converte kwanzas (inteiro ou texto com até 2 casas decimais, "." ou ",") em cêntimos,
     * sem passar por float. Sem separador de milhares.
     */
    public static function deKwanzas(int|string $kwanzas): self
    {
        if (is_int($kwanzas)) {
            return self::deCentimos($kwanzas * 100);
        }

        if (! preg_match('/^(\d{1,12})(?:[.,](\d{1,2}))?$/D', $kwanzas, $partes)) {
            throw new InvalidArgumentException('Valor em kwanzas inválido: use dígitos com até duas casas decimais.');
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], 2, '0') : 0;

        return self::deCentimos(((int) $partes[1]) * 100 + $fraccao);
    }
```

`Modules/Financeiro/resources/js/Support/dinheiro.js`:
```js
/**
 * Valores monetários chegam do backend em cêntimos (inteiro). Estes helpers só apresentam
 * ou preenchem inputs: a conversão autoritativa é Dinheiro::deKwanzas no backend.
 */
export function formatKz(centimos) {
    const valor = Math.abs(Number(centimos ?? 0));
    const inteira = Math.trunc(valor / 100);
    const fraccao = String(valor % 100).padStart(2, '0');
    const milhares = String(inteira).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${milhares},${fraccao} Kz`;
}

export function centimosParaKz(centimos) {
    const valor = Math.abs(Number(centimos ?? 0));

    return `${Math.trunc(valor / 100)}.${String(valor % 100).padStart(2, '0')}`;
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DinheiroTest.php`
Expected: PASS. (O JS é verificado pelo build na Task 10 e usado nas Tasks 8–9.)

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/Dinheiro.php Modules/Financeiro/tests/Unit/DinheiroTest.php Modules/Financeiro/resources/js/Support/dinheiro.js
```

---

### Task 3: Permissões `metodo-pagamento` e `catalogo-financeiro`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (casos `METODO_PAGAMENTO = 18`, `CATALOGO_FINANCEIRO = 19`, em `case`, `slug()` e `label()`)
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (ADMIN_ESCOLA)
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php` (`18` passa a `20`)
- Test: `Modules/Financeiro/tests/Feature/PermissoesCatalogoTest.php`

**Interfaces:**
- Produces: abilities `metodo-pagamento.{ver,criar,editar,eliminar}` e `catalogo-financeiro.{ver,criar,editar,eliminar}`, concedidas só ao `ADMIN_ESCOLA`.

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

class PermissoesCatalogoTest extends TestCase
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

    public function test_modulos_tem_slug_e_label(): void
    {
        $this->assertSame('metodo-pagamento', Modulo::METODO_PAGAMENTO->slug());
        $this->assertSame('catalogo-financeiro', Modulo::CATALOGO_FINANCEIRO->slug());
        $this->assertSame(Modulo::METODO_PAGAMENTO, Modulo::fromSlug('metodo-pagamento'));
        $this->assertSame(Modulo::CATALOGO_FINANCEIRO, Modulo::fromSlug('catalogo-financeiro'));
        $this->assertSame('Método de Pagamento', Modulo::METODO_PAGAMENTO->label());
        $this->assertSame('Catálogo Financeiro', Modulo::CATALOGO_FINANCEIRO->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes_nos_dois_modulos(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['metodo-pagamento', 'catalogo-financeiro'] as $modulo) {
            foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
                $this->assertTrue(Gate::forUser($admin)->allows("{$modulo}.{$acao}"), "{$modulo}.{$acao}");
            }
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            $this->assertFalse(Gate::forUser($user)->allows('metodo-pagamento.ver'), $perfil->name);
            $this->assertFalse(Gate::forUser($user)->allows('catalogo-financeiro.ver'), $perfil->name);
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesCatalogoTest.php`
Expected: FAIL (casos inexistentes).

- [ ] **Step 3: Implementar**

Em `Modules/Permissao/app/Enums/Modulo.php`, depois de `case REGRA_COBRANCA = 17;`:
```php
    case METODO_PAGAMENTO = 18;
    case CATALOGO_FINANCEIRO = 19;
```
em `slug()`, depois de `self::REGRA_COBRANCA => 'regra-cobranca',`:
```php
            self::METODO_PAGAMENTO => 'metodo-pagamento',
            self::CATALOGO_FINANCEIRO => 'catalogo-financeiro',
```
em `label()`, depois de `self::REGRA_COBRANCA => 'Regra de Cobrança',`:
```php
            self::METODO_PAGAMENTO => 'Método de Pagamento',
            self::CATALOGO_FINANCEIRO => 'Catálogo Financeiro',
```

Em `ModuloSeeder.php`, depois da linha do `17`:
```php
            ['nome' => 18, 'descricao' => 'Metodo de Pagamento'],
            ['nome' => 19, 'descricao' => 'Catalogo Financeiro'],
```

Em `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL`, bloco `Perfil::ADMIN_ESCOLA->value`, depois de `Modulo::REGRA_COBRANCA->value => ['ver', 'editar'],`:
```php
            Modulo::METODO_PAGAMENTO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::CATALOGO_FINANCEIRO->value => ['ver', 'criar', 'editar', 'eliminar'],
```

Em `Modules/Permissao/tests/Feature/ModuloSeederTest.php` trocar `assertSame(18,` por `assertSame(20,`.

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesCatalogoTest.php Modules/Permissao`
Expected: PASS. Se outro teste existente falhar apenas por assumir 18 módulos, corrigi-lo minimamente e dizê-lo no relatório.

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao Modules/Financeiro/tests/Feature/PermissoesCatalogoTest.php
```

---

### Task 4: Métodos de Pagamento (backend)

**Files:**
- Create: `Modules/Financeiro/app/Enums/TipoMetodoPagamento.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_09_110000_create_metodos_pagamento_table.php`
- Create: `Modules/Financeiro/app/Models/MetodoPagamento.php`
- Create: `Modules/Financeiro/app/DTO/MetodoPagamentoDTO.php`
- Create: `Modules/Financeiro/app/Http/Requests/{CriarMetodoPagamentoRequest,AtualizarMetodoPagamentoRequest,AlterarEstadoMetodoPagamentoRequest}.php`
- Create: `Modules/Financeiro/app/Actions/{CriarMetodoPagamentoAction,AtualizarMetodoPagamentoAction,AlterarEstadoMetodoPagamentoAction,EliminarMetodoPagamentoAction}.php`
- Create: `Modules/Financeiro/app/Services/{GestaoMetodoPagamentoService,MetodoPagamentoConsultaService}.php`
- Create: `Modules/Financeiro/app/Http/Controllers/MetodoPagamentoController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Create: `Modules/Financeiro/tests/Concerns/ComUtilizadoresFinanceiro.php`
- Test: `Modules/Financeiro/tests/Feature/MetodoPagamentoTest.php`

**Interfaces:**
- Consumes: `ReferenciasFinanceiras` (T1), abilities `metodo-pagamento.*` (T3), `Estado`, `PertenceAoTenant`, `RegistaAutoria`, `SincronizaEstadoDescricao`.
- Produces:
  - rotas (`financeiro.configuracao.metodos-pagamento.*`): `GET /financeiro/configuracao/metodos-pagamento` (`index`), `POST` (`store`), `PUT /{metodo}` (`update`), `PATCH /{metodo}/estado` (`alterar-estado`), `DELETE /{metodo}` (`destroy`);
  - componente Inertia `Financeiro/MetodosPagamento/Index` com props `metodos` (paginador: `id, nome, tipo, tipo_descricao, estado, estado_descricao`), `filtros`, `tipos` (`[{value,label}]`);
  - `TipoMetodoPagamento::opcoes(): array`;
  - trait de teste `ComUtilizadoresFinanceiro` com `adminEscola(): User` e `professor(): User`.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Concerns/ComUtilizadoresFinanceiro.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

trait ComUtilizadoresFinanceiro
{
    protected function adminEscola(): User
    {
        return $this->utilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin-fin@example.com');
    }

    protected function professor(): User
    {
        return $this->utilizadorComPerfil(Perfil::PROFESSOR, 'professor-fin@example.com');
    }

    private function utilizadorComPerfil(Perfil $perfil, string $email): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => 'Teste', 'password' => Hash::make('segredo123')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->firstOrFail()->id]);

        return $user;
    }
}
```

`Modules/Financeiro/tests/Feature/MetodoPagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class MetodoPagamentoTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function metodo(string $nome = 'Numerário', TipoMetodoPagamento $tipo = TipoMetodoPagamento::NUMERARIO): MetodoPagamento
    {
        return MetodoPagamento::create(['nome' => $nome, 'tipo' => $tipo]);
    }

    private function metodoNoutroTenant(string $nome = 'Numerário'): MetodoPagamento
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        return $this->noTenant($outro, fn () => MetodoPagamento::create(['nome' => $nome, 'tipo' => TipoMetodoPagamento::NUMERARIO]));
    }

    public function test_index_lista_so_os_metodos_do_tenant_e_expoe_os_tipos(): void
    {
        $this->metodo('Numerário');
        $this->metodoNoutroTenant('Metodo Do Outro');

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.metodos-pagamento.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/MetodosPagamento/Index')
                ->has('metodos.data', 1)
                ->where('metodos.data.0.nome', 'Numerário')
                ->where('metodos.data.0.tipo_descricao', 'Numerário')
                ->missing('metodos.data.0.tenant_id')
                ->has('tipos', 5));
    }

    public function test_index_pesquisa_por_nome_e_filtra_por_estado(): void
    {
        $this->metodo('Numerário');
        $this->metodo('Multicaixa Express', TipoMetodoPagamento::MULTICAIXA)->update(['estado' => Estado::INATIVO->value]);

        $this->actingAs($this->adminEscola());

        $this->get(route('financeiro.configuracao.metodos-pagamento.index', ['pesquisa' => 'multi']))
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 1)->where('metodos.data.0.nome', 'Multicaixa Express'));

        $this->get(route('financeiro.configuracao.metodos-pagamento.index', ['estado' => '0']))
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 1)->where('metodos.data.0.estado_descricao', 'Inativo'));
    }

    public function test_cria_metodo_com_tipo_descricao_estado_activo_e_autoria(): void
    {
        $admin = $this->adminEscola();

        $this->actingAs($admin)
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'BAI Transferência', 'tipo' => TipoMetodoPagamento::TRANSFERENCIA_BANCARIA->value])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $metodo = MetodoPagamento::firstWhere('nome', 'BAI Transferência');
        $this->assertNotNull($metodo);
        $this->assertSame(TipoMetodoPagamento::TRANSFERENCIA_BANCARIA, $metodo->tipo);
        $this->assertSame('Transferência Bancária', $metodo->tipo_descricao);
        $this->assertSame(1, $metodo->estado);
        $this->assertSame('Ativo', $metodo->estado_descricao);
        $this->assertSame($this->tenant->id, $metodo->tenant_id);
        $this->assertSame($admin->id, $metodo->criado_por);
    }

    public function test_nome_e_tipo_sao_obrigatorios_e_tipo_tem_de_existir(): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), [])
            ->assertSessionHasErrors(['nome', 'tipo']);

        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'X', 'tipo' => 99])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, MetodoPagamento::count());
    }

    public function test_nome_unico_por_tenant_mas_repetivel_noutro_tenant(): void
    {
        $this->metodoNoutroTenant('Numerário');
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Numerário', 'tipo' => 1])
            ->assertSessionHasNoErrors();

        $this->from('/x')->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Numerário', 'tipo' => 1])
            ->assertSessionHasErrors('nome');

        $this->assertSame(1, MetodoPagamento::count());
    }

    public function test_actualiza_mantendo_o_proprio_nome_sem_falhar(): void
    {
        $metodo = $this->metodo('TPA Loja', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())
            ->put(route('financeiro.configuracao.metodos-pagamento.update', $metodo), ['nome' => 'TPA Loja', 'tipo' => TipoMetodoPagamento::OUTRO->value])
            ->assertSessionHasNoErrors();

        $metodo->refresh();
        $this->assertSame(TipoMetodoPagamento::OUTRO, $metodo->tipo);
        $this->assertSame('Outro', $metodo->tipo_descricao);
    }

    public function test_actualizar_com_nome_de_outro_metodo_do_tenant_falha(): void
    {
        $this->metodo('Numerário');
        $outro = $this->metodo('TPA', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route('financeiro.configuracao.metodos-pagamento.update', $outro), ['nome' => 'Numerário', 'tipo' => 3])
            ->assertSessionHasErrors('nome');
    }

    public function test_desactiva_e_reactiva_mantendo_a_descricao_do_estado(): void
    {
        $metodo = $this->metodo();
        $this->actingAs($this->adminEscola());

        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Inativo', $metodo->fresh()->estado_descricao);

        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Ativo', $metodo->fresh()->estado_descricao);
    }

    public function test_elimina_quando_nao_ha_referencias(): void
    {
        $metodo = $this->metodo();

        $this->actingAs($this->adminEscola())
            ->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))
            ->assertSessionHasNoErrors();

        $this->assertNull(MetodoPagamento::find($metodo->id));
    }

    public function test_eliminar_com_referencia_financeira_e_bloqueado(): void
    {
        $metodo = $this->metodo();
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))
            ->assertSessionHasErrors('eliminar');

        $this->assertNotNull(MetodoPagamento::find($metodo->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $metodo = $this->metodo();
        $this->actingAs($this->professor());

        $this->get(route('financeiro.configuracao.metodos-pagamento.index'))->assertForbidden();
        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'X', 'tipo' => 1])->assertForbidden();
        $this->put(route('financeiro.configuracao.metodos-pagamento.update', $metodo), ['nome' => 'X', 'tipo' => 1])->assertForbidden();
        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 0])->assertForbidden();
        $this->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))->assertForbidden();
    }

    public function test_registo_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $doOutro = $this->metodoNoutroTenant('Do Outro');
        $this->actingAs($this->adminEscola());

        $this->put(route('financeiro.configuracao.metodos-pagamento.update', $doOutro->id), ['nome' => 'Alterado', 'tipo' => 1])->assertNotFound();
        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('metodos_pagamento')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Forjado', 'tipo' => 1, 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, MetodoPagamento::firstWhere('nome', 'Forjado')->tenant_id);
    }
}
```
- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/MetodoPagamentoTest.php`
Expected: FAIL (tabela, modelo, rotas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Enums/TipoMetodoPagamento.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

enum TipoMetodoPagamento: int
{
    case NUMERARIO = 1;
    case TRANSFERENCIA_BANCARIA = 2;
    case TPA = 3;
    case MULTICAIXA = 4;
    case OUTRO = 5;

    public function label(): string
    {
        return match ($this) {
            self::NUMERARIO => 'Numerário',
            self::TRANSFERENCIA_BANCARIA => 'Transferência Bancária',
            self::TPA => 'TPA',
            self::MULTICAIXA => 'Multicaixa',
            self::OUTRO => 'Outro',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $tipo) => ['value' => $tipo->value, 'label' => $tipo->label()], self::cases());
    }
}
```

Migration `2026_10_09_110000_create_metodos_pagamento_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome', 100);
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pagamento');
    }
};
```

`Modules/Financeiro/app/Models/MetodoPagamento.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamento extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'metodos_pagamento';

    protected $fillable = [
        'nome',
        'tipo',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'tipo' => TipoMetodoPagamento::class,
        'estado' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $metodo) {
            $metodo->tipo_descricao = $metodo->tipo->label();
        });
    }
}
```

`Modules/Financeiro/app/DTO/MetodoPagamentoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamentoDTO
{
    public function __construct(
        public string $nome,
        public TipoMetodoPagamento $tipo,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoMetodoPagamento::from((int) $dados['tipo']),
        );
    }
}
```

`Modules/Financeiro/app/Http/Requests/CriarMetodoPagamentoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class CriarMetodoPagamentoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('metodo-pagamento.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('metodos_pagamento', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id())),
            ],
            'tipo' => ['required', new Enum(TipoMetodoPagamento::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do método de pagamento é obrigatório.',
            'nome.unique' => 'Já existe um método de pagamento com este nome.',
            'nome.max' => 'O nome do método de pagamento não pode ultrapassar 100 caracteres.',
            'tipo.required' => 'O tipo do método de pagamento é obrigatório.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/AtualizarMetodoPagamentoRequest.php`: igual ao Criar, com `authorize` → `metodo-pagamento.editar` e a regra de nome a ignorar o próprio registo:
```php
                Rule::unique('metodos_pagamento', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id()))
                    ->ignore($this->route('metodo')?->id),
```
(restantes regras e mensagens idênticas ao `CriarMetodoPagamentoRequest`; escrever a classe completa, sem herança).

`Modules/Financeiro/app/Http/Requests/AlterarEstadoMetodoPagamentoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoMetodoPagamentoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('metodo-pagamento.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(Estado::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
```

Actions (`Modules/Financeiro/app/Actions/`):
```php
// CriarMetodoPagamentoAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;

class CriarMetodoPagamentoAction
{
    public function executar(MetodoPagamentoDTO $dto): MetodoPagamento
    {
        return MetodoPagamento::create([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
        ]);
    }
}
```
```php
// AtualizarMetodoPagamentoAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;

class AtualizarMetodoPagamentoAction
{
    public function executar(MetodoPagamento $metodo, MetodoPagamentoDTO $dto): MetodoPagamento
    {
        $metodo->update([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
        ]);

        return $metodo->fresh();
    }
}
```
```php
// AlterarEstadoMetodoPagamentoAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\MetodoPagamento;

class AlterarEstadoMetodoPagamentoAction
{
    public function executar(MetodoPagamento $metodo, Estado $novoEstado): MetodoPagamento
    {
        $metodo->estado = $novoEstado->value;
        $metodo->save();

        return $metodo->fresh();
    }
}
```
```php
// EliminarMetodoPagamentoAction.php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

class EliminarMetodoPagamentoAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(MetodoPagamento $metodo): void
    {
        if ($this->referencias->existeReferenciaA($metodo)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este método de pagamento: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        $metodo->delete();
    }
}
```

Services:
```php
// GestaoMetodoPagamentoService.php
<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoMetodoPagamentoAction;
use Modules\Financeiro\Actions\AtualizarMetodoPagamentoAction;
use Modules\Financeiro\Actions\CriarMetodoPagamentoAction;
use Modules\Financeiro\Actions\EliminarMetodoPagamentoAction;
use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Http\Requests\AtualizarMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\CriarMetodoPagamentoRequest;
use Modules\Financeiro\Models\MetodoPagamento;

class GestaoMetodoPagamentoService
{
    public function __construct(
        private CriarMetodoPagamentoAction $criarAction,
        private AtualizarMetodoPagamentoAction $atualizarAction,
        private AlterarEstadoMetodoPagamentoAction $alterarEstadoAction,
        private EliminarMetodoPagamentoAction $eliminarAction,
    ) {
    }

    public function criar(CriarMetodoPagamentoRequest $request): MetodoPagamento
    {
        return $this->criarAction->executar(MetodoPagamentoDTO::fromRequest($request));
    }

    public function atualizar(MetodoPagamento $metodo, AtualizarMetodoPagamentoRequest $request): MetodoPagamento
    {
        return $this->atualizarAction->executar($metodo, MetodoPagamentoDTO::fromRequest($request));
    }

    public function alterarEstado(MetodoPagamento $metodo, Estado $estado): MetodoPagamento
    {
        return $this->alterarEstadoAction->executar($metodo, $estado);
    }

    public function eliminar(MetodoPagamento $metodo): void
    {
        $this->eliminarAction->executar($metodo);
    }
}
```
```php
// MetodoPagamentoConsultaService.php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Models\MetodoPagamento;

class MetodoPagamentoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return MetodoPagamento::query()
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, fn ($query, $pesquisa) => $query->whereContem('nome', $pesquisa))
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString();
    }
}
```

`Modules/Financeiro/app/Http/Controllers/MetodoPagamentoController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Http\Requests\AlterarEstadoMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\AtualizarMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\CriarMetodoPagamentoRequest;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Services\GestaoMetodoPagamentoService;
use Modules\Financeiro\Services\MetodoPagamentoConsultaService;

class MetodoPagamentoController extends Controller
{
    public function __construct(
        private GestaoMetodoPagamentoService $service,
        private MetodoPagamentoConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('metodo-pagamento.ver');

        $filtros = $request->only(['pesquisa', 'estado']);

        return Inertia::render('Financeiro/MetodosPagamento/Index', [
            'metodos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'tipos' => TipoMetodoPagamento::opcoes(),
        ]);
    }

    public function store(CriarMetodoPagamentoRequest $request)
    {
        $this->authorize('metodo-pagamento.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Método de pagamento criado com sucesso.');
    }

    public function update(AtualizarMetodoPagamentoRequest $request, MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.editar');

        $this->service->atualizar($metodo, $request);

        return redirect()->back()->with('success', 'Método de pagamento atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoMetodoPagamentoRequest $request, MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.editar');

        $this->service->alterarEstado($metodo, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do método de pagamento atualizado com sucesso.');
    }

    public function destroy(MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.eliminar');

        $this->service->eliminar($metodo);

        return redirect()->back()->with('success', 'Método de pagamento eliminado com sucesso.');
    }
}
```

`Modules/Financeiro/routes/web.php` (conteúdo completo):
```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\MetodoPagamentoController;
use Modules\Financeiro\Http\Controllers\RegraCobrancaController;

Route::middleware(['auth'])->prefix('financeiro/configuracao')->name('financeiro.configuracao.')->group(function () {
    Route::get('/regras-cobranca', [RegraCobrancaController::class, 'show'])->middleware('can:regra-cobranca.ver')->name('regras-cobranca.show');
    Route::put('/regras-cobranca', [RegraCobrancaController::class, 'update'])->middleware('can:regra-cobranca.editar')->name('regras-cobranca.update');

    Route::prefix('metodos-pagamento')->name('metodos-pagamento.')->group(function () {
        Route::get('/', [MetodoPagamentoController::class, 'index'])->middleware('can:metodo-pagamento.ver')->name('index');
        Route::post('/', [MetodoPagamentoController::class, 'store'])->middleware('can:metodo-pagamento.criar')->name('store');
        Route::put('/{metodo}', [MetodoPagamentoController::class, 'update'])->middleware('can:metodo-pagamento.editar')->name('update');
        Route::patch('/{metodo}/estado', [MetodoPagamentoController::class, 'alterarEstado'])->middleware('can:metodo-pagamento.editar')->name('alterar-estado');
        Route::delete('/{metodo}', [MetodoPagamentoController::class, 'destroy'])->middleware('can:metodo-pagamento.eliminar')->name('destroy');
    });
});
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS (inclui os testes dos planos anteriores).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 5: Produtos (backend)

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_09_120000_create_produtos_table.php`
- Create: `Modules/Financeiro/app/Models/Produto.php`
- Create: `Modules/Financeiro/app/DTO/ProdutoDTO.php`
- Create: `Modules/Financeiro/app/Http/Requests/{CriarProdutoRequest,AtualizarProdutoRequest,AlterarEstadoProdutoRequest}.php`
- Create: `Modules/Financeiro/app/Actions/{CriarProdutoAction,AtualizarProdutoAction,AlterarEstadoProdutoAction,EliminarProdutoAction}.php`
- Create: `Modules/Financeiro/app/Services/GestaoProdutoService.php`
- Create: `Modules/Financeiro/app/Http/Controllers/ProdutoController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/ProdutoTest.php`

**Interfaces:**
- Consumes: `Dinheiro::deKwanzas` (T2), `DinheiroCast`, `ReferenciasFinanceiras` (T1), abilities `catalogo-financeiro.*` (T3), `ComUtilizadoresFinanceiro` (T4).
- Produces:
  - rotas (`financeiro.configuracao.produtos.*`): `POST /financeiro/configuracao/produtos` (`store`), `PUT /{produto}` (`update`), `PATCH /{produto}/estado` (`alterar-estado`), `DELETE /{produto}` (`destroy`) (sem `index`: a listagem é do catálogo, Task 7);
  - `Produto` com `preco` (`Dinheiro`), `scopeActivos()` (usado como `Produto::activos()`), campos `nome, descricao, codigo, preco, estado, estado_descricao`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/ProdutoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProdutoTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function produto(string $nome = 'Uniforme Escolar', ?string $codigo = 'UNI-001', int $centimos = 2_500_000): Produto
    {
        return Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function produtoNoutroTenant(string $nome = 'Do Outro', ?string $codigo = 'UNI-001'): Produto
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        return $this->noTenant($outro, fn () => Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos(100)]));
    }

    public function test_cria_produto_com_preco_em_centimos_estado_activo_e_autoria(): void
    {
        $admin = $this->adminEscola();

        $this->actingAs($admin)
            ->post(route('financeiro.configuracao.produtos.store'), [
                'nome' => 'Uniforme Escolar', 'descricao' => 'Camisa e calças', 'codigo' => 'UNI-001', 'preco' => '25000',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $produto = Produto::firstWhere('codigo', 'UNI-001');
        $this->assertNotNull($produto);
        $this->assertSame(2_500_000, $produto->preco->centimos());
        $this->assertSame(2_500_000, (int) DB::table('produtos')->where('id', $produto->id)->value('preco'));
        $this->assertSame('Camisa e calças', $produto->descricao);
        $this->assertSame('Ativo', $produto->estado_descricao);
        $this->assertSame($this->tenant->id, $produto->tenant_id);
        $this->assertSame($admin->id, $produto->criado_por);
    }

    public function test_codigo_e_descricao_sao_opcionais(): void
    {
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Caderno A', 'preco' => '500'])->assertSessionHasNoErrors();
        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Caderno B', 'preco' => '600'])->assertSessionHasNoErrors();

        $this->assertSame(2, Produto::whereNull('codigo')->count());
    }

    #[DataProvider('precosAceites')]
    public function test_precos_validos_sao_gravados_em_centimos_exactos(string $entrada, int $centimos): void
    {
        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasNoErrors();

        $this->assertSame($centimos, Produto::firstWhere('nome', 'Item')->preco->centimos());
    }

    public static function precosAceites(): array
    {
        return [
            'zero' => ['0', 0],
            'inteiro' => ['25000', 2_500_000],
            'uma casa' => ['25000.5', 2_500_050],
            'virgula' => ['25000,50', 2_500_050],
            'cêntimos' => ['0.05', 5],
        ];
    }

    #[DataProvider('precosRejeitados')]
    public function test_precos_invalidos_sao_rejeitados(string $entrada): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasErrors('preco');

        $this->assertSame(0, Produto::count());
    }

    public static function precosRejeitados(): array
    {
        return [
            'negativo' => ['-1'],
            'letras' => ['abc'],
            'três casas' => ['12.345'],
            'notação científica' => ['1e3'],
            'vazio' => [''],
            'milhares' => ['25.000,50'],
        ];
    }

    public function test_nome_e_preco_sao_obrigatorios(): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.produtos.store'), [])
            ->assertSessionHasErrors(['nome', 'preco']);
    }

    public function test_codigo_unico_por_tenant_mas_repetivel_noutro_tenant(): void
    {
        $this->produtoNoutroTenant('Do Outro', 'UNI-001');
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'A', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasNoErrors();

        $this->from('/x')->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'B', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Produto::count());
    }

    public function test_actualiza_mantendo_o_proprio_codigo_e_altera_o_preco(): void
    {
        $produto = $this->produto();

        $this->actingAs($this->adminEscola())
            ->put(route('financeiro.configuracao.produtos.update', $produto), [
                'nome' => 'Uniforme Novo', 'codigo' => 'UNI-001', 'preco' => '30000',
            ])
            ->assertSessionHasNoErrors();

        $produto->refresh();
        $this->assertSame('Uniforme Novo', $produto->nome);
        $this->assertSame(3_000_000, $produto->preco->centimos());
    }

    public function test_actualizar_com_codigo_de_outro_produto_do_tenant_falha(): void
    {
        $this->produto('A', 'UNI-001');
        $outro = $this->produto('B', 'UNI-002');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route('financeiro.configuracao.produtos.update', $outro), ['nome' => 'B', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');
    }

    public function test_desactivar_mantem_o_registo_e_tira_o_produto_de_activos(): void
    {
        $produto = $this->produto();
        $this->produto('Outro', 'UNI-002');

        $this->actingAs($this->adminEscola())
            ->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 0])
            ->assertSessionHasNoErrors();

        $produto->refresh();
        $this->assertSame('Inativo', $produto->estado_descricao);
        $this->assertSame(2, Produto::count());
        $this->assertSame(['Outro'], Produto::activos()->pluck('nome')->all());

        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 1]);
        $this->assertSame(2, Produto::activos()->count());
    }

    public function test_elimina_sem_referencias_e_bloqueia_com_referencia(): void
    {
        $produto = $this->produto();
        $this->actingAs($this->adminEscola());

        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->from('/x')->delete(route('financeiro.configuracao.produtos.destroy', $produto))->assertSessionHasErrors('eliminar');
        $this->assertNotNull(Produto::find($produto->id));
    }

    public function test_elimina_quando_nao_ha_referencias(): void
    {
        $produto = $this->produto();

        $this->actingAs($this->adminEscola())
            ->delete(route('financeiro.configuracao.produtos.destroy', $produto))
            ->assertSessionHasNoErrors();

        $this->assertNull(Produto::find($produto->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $produto = $this->produto();
        $this->actingAs($this->professor());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->put(route('financeiro.configuracao.produtos.update', $produto), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 0])->assertForbidden();
        $this->delete(route('financeiro.configuracao.produtos.destroy', $produto))->assertForbidden();
    }

    public function test_registo_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $doOutro = $this->produtoNoutroTenant();
        $this->actingAs($this->adminEscola());

        $this->put(route('financeiro.configuracao.produtos.update', $doOutro->id), ['nome' => 'Alterado', 'preco' => '1'])->assertNotFound();
        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('financeiro.configuracao.produtos.destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('produtos')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Forjado', 'preco' => '1', 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, Produto::firstWhere('nome', 'Forjado')->tenant_id);
    }

    public function test_preco_serializa_como_inteiro_em_centimos(): void
    {
        $this->assertSame(2_500_000, $this->produto()->toArray()['preco']);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ProdutoTest.php`
Expected: FAIL (tabela, modelo, rotas inexistentes).

- [ ] **Step 3: Implementar**

Migration `2026_10_09_120000_create_produtos_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->string('codigo', 50)->nullable();
            $table->unsignedBigInteger('preco');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
```

`Modules/Financeiro/app/Models/Produto.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Casts\DinheiroCast;

/**
 * Bem físico do catálogo da escola. Entidade distinta de Servico (sem tabela genérica).
 * O preço é o preço ACTUAL do catálogo: operações futuras copiam-no (snapshot).
 */
class Produto extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'produtos';

    protected $fillable = [
        'nome',
        'descricao',
        'codigo',
        'preco',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'preco' => DinheiroCast::class,
        'estado' => 'integer',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }
}
```

`Modules/Financeiro/app/DTO/ProdutoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Support\Dinheiro;

class ProdutoDTO
{
    public function __construct(
        public string $nome,
        public Dinheiro $preco,
        public ?string $codigo = null,
        public ?string $descricao = null,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            preco: Dinheiro::deKwanzas((string) $dados['preco']),
            codigo: ($dados['codigo'] ?? null) !== '' ? ($dados['codigo'] ?? null) : null,
            descricao: ($dados['descricao'] ?? null) !== '' ? ($dados['descricao'] ?? null) : null,
        );
    }
}
```

`Modules/Financeiro/app/Http/Requests/CriarProdutoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class CriarProdutoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogo-financeiro.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'codigo' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('produtos', 'codigo')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id())),
            ],
            'preco' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do produto é obrigatório.',
            'nome.max' => 'O nome do produto não pode ultrapassar 255 caracteres.',
            'codigo.unique' => 'Já existe um produto com este código.',
            'codigo.max' => 'O código do produto não pode ultrapassar 50 caracteres.',
            'preco.required' => 'O preço do produto é obrigatório.',
            'preco.regex' => 'O preço é inválido: use dígitos com até duas casas decimais (ex.: 25000 ou 25000,50).',
        ];
    }
}
```
`AtualizarProdutoRequest`: igual, `authorize` → `catalogo-financeiro.editar`, e a regra do código passa a `->ignore($this->route('produto')?->id)` (classe completa, sem herança).

`AlterarEstadoProdutoRequest`: igual ao `AlterarEstadoMetodoPagamentoRequest` da Task 4, com `authorize` → `catalogo-financeiro.editar`.

Actions (`Modules/Financeiro/app/Actions/`), mesma forma das de `MetodoPagamento`:
```php
// CriarProdutoAction::executar(ProdutoDTO $dto): Produto
return Produto::create([
    'nome' => $dto->nome,
    'descricao' => $dto->descricao,
    'codigo' => $dto->codigo,
    'preco' => $dto->preco,
]);

// AtualizarProdutoAction::executar(Produto $produto, ProdutoDTO $dto): Produto
$produto->update([ /* os mesmos 4 campos */ ]);
return $produto->fresh();

// AlterarEstadoProdutoAction::executar(Produto $produto, Estado $novoEstado): Produto
$produto->estado = $novoEstado->value;
$produto->save();
return $produto->fresh();

// EliminarProdutoAction (construtor com ReferenciasFinanceiras), executar(Produto $produto): void
if ($this->referencias->existeReferenciaA($produto)) {
    throw ValidationException::withMessages([
        'eliminar' => 'Não é possível eliminar este produto: já existem registos financeiros que o utilizam. Desative-o.',
    ]);
}
$produto->delete();
```
(Cada uma é uma classe completa em `Modules\Financeiro\Actions`, com os `use` correspondentes, exactamente como as da Task 4.)

`GestaoProdutoService`: igual ao `GestaoMetodoPagamentoService` (Task 4), com `Produto`, `ProdutoDTO::fromRequest`, `CriarProdutoRequest`, `AtualizarProdutoRequest` e as quatro Actions de produto.

`Modules/Financeiro/app/Http/Controllers/ProdutoController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Http\Requests\AlterarEstadoProdutoRequest;
use Modules\Financeiro\Http\Requests\AtualizarProdutoRequest;
use Modules\Financeiro\Http\Requests\CriarProdutoRequest;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Services\GestaoProdutoService;

class ProdutoController extends Controller
{
    public function __construct(
        private GestaoProdutoService $service,
    ) {
    }

    public function store(CriarProdutoRequest $request)
    {
        $this->authorize('catalogo-financeiro.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Produto criado com sucesso.');
    }

    public function update(AtualizarProdutoRequest $request, Produto $produto)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->atualizar($produto, $request);

        return redirect()->back()->with('success', 'Produto atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoProdutoRequest $request, Produto $produto)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->alterarEstado($produto, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do produto atualizado com sucesso.');
    }

    public function destroy(Produto $produto)
    {
        $this->authorize('catalogo-financeiro.eliminar');

        $this->service->eliminar($produto);

        return redirect()->back()->with('success', 'Produto eliminado com sucesso.');
    }
}
```

`routes/web.php`: acrescentar o import `use Modules\Financeiro\Http\Controllers\ProdutoController;` e, dentro do grupo `financeiro/configuracao`, depois do bloco `metodos-pagamento`:
```php
    Route::prefix('produtos')->name('produtos.')->group(function () {
        Route::post('/', [ProdutoController::class, 'store'])->middleware('can:catalogo-financeiro.criar')->name('store');
        Route::put('/{produto}', [ProdutoController::class, 'update'])->middleware('can:catalogo-financeiro.editar')->name('update');
        Route::patch('/{produto}/estado', [ProdutoController::class, 'alterarEstado'])->middleware('can:catalogo-financeiro.editar')->name('alterar-estado');
        Route::delete('/{produto}', [ProdutoController::class, 'destroy'])->middleware('can:catalogo-financeiro.eliminar')->name('destroy');
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

### Task 6: Serviços (backend) — lote da Task 5

Mesma forma da Task 5, com a tabela de substituições **exaustiva** abaixo (Serviço é uma entidade distinta: tabela, model, rotas e classes próprias; só a forma do código é a mesma). Cada ficheiro é criado por inteiro, aplicando as substituições ao código da Task 5, incluindo os testes.

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_09_130000_create_servicos_table.php`, `app/Models/Servico.php`, `app/DTO/ServicoDTO.php`, `app/Http/Requests/{CriarServicoRequest,AtualizarServicoRequest,AlterarEstadoServicoRequest}.php`, `app/Actions/{CriarServicoAction,AtualizarServicoAction,AlterarEstadoServicoAction,EliminarServicoAction}.php`, `app/Services/GestaoServicoService.php`, `app/Http/Controllers/ServicoController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/ServicoTest.php`

**Substituições (Produto → Serviço):**

| Task 5 | Task 6 |
|---|---|
| `Produto` / `produto` (classe, variável, parâmetro de rota) | `Servico` / `servico` |
| tabela `produtos` | tabela `servicos` |
| `ProdutoDTO`, `CriarProdutoRequest`, `AtualizarProdutoRequest`, `AlterarEstadoProdutoRequest`, `*ProdutoAction`, `GestaoProdutoService`, `ProdutoController`, `ProdutoTest` | os equivalentes com `Servico` |
| rotas `financeiro.configuracao.produtos.*` e URL `/produtos` | `financeiro.configuracao.servicos.*` e `/servicos` |
| texto "produto" / "Produto" / "produtos" nas mensagens, flashes e comentários | "serviço" / "Serviço" / "serviços" (concordar o género: "O nome do serviço", "Já existe um serviço com este código", "Serviço criado com sucesso.", "Estado do serviço atualizado com sucesso.", "Não é possível eliminar este serviço: …") |
| docblock do model: "Bem físico do catálogo…" | "Prestação do catálogo da escola (sem stock). Entidade distinta de Produto (sem tabela genérica). O preço é o preço ACTUAL do catálogo: operações futuras copiam-no (snapshot)." |
| dados de teste: `Uniforme Escolar`, `UNI-001`, `UNI-002`, `Caderno A/B` | `Emissão de Certificado`, `SER-001`, `SER-002`, `Declaração A/B` |
| ability, módulo, permissões | **iguais** (`catalogo-financeiro.*`) |
| `scopeActivos()` | igual (`Servico::activos()`) |

Mantêm-se tal e qual: `preco` com `DinheiroCast`, regex do preço, `deKwanzas`, `unique(tenant_id, codigo)` e índice `(tenant_id, estado)`, `ReferenciasFinanceiras` e o erro `eliminar`, `$hidden`, autoria e `estado`.

- [ ] **Step 1: Escrever `ServicoTest`** — a classe da Task 5 com as substituições acima (mesmos 17 testes: criação com preço em cêntimos, código/descrição opcionais, `precosAceites`, `precosRejeitados`, obrigatórios, código único por tenant, actualizar mantendo o código, código de outro serviço do tenant, desactivar/`activos()`, eliminar com e sem referência, 403 do professor, 404 de outro tenant, `tenant_id` forjado, serialização do preço).
- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ServicoTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar** os ficheiros listados, a partir do código da Task 5 com as substituições. Em `routes/web.php` acrescentar `use Modules\Financeiro\Http\Controllers\ServicoController;` e, depois do bloco `produtos`, o bloco `prefix('servicos')->name('servicos.')` com as quatro rotas (`store`, `update`, `alterar-estado`, `destroy`) e os mesmos middlewares `can:catalogo-financeiro.*`.
- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 7: Catálogo unificado (listagem de Produtos / Serviços)

**Files:**
- Create: `Modules/Financeiro/app/Services/CatalogoConsultaService.php`
- Create: `Modules/Financeiro/app/Http/Controllers/CatalogoFinanceiroController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/CatalogoFinanceiroTest.php`

**Interfaces:**
- Consumes: `Produto`, `Servico` (T5, T6), ability `catalogo-financeiro.ver`.
- Produces: `GET /financeiro/configuracao/produtos-servicos` (`financeiro.configuracao.produtos-servicos.index`), filtros `pesquisa`, `estado`, `tipo` (`''` | `produto` | `servico`); componente `Financeiro/ProdutosServicos/Index` com `itens` (paginador de linhas `{id, tipo, codigo, nome, descricao, preco, estado, estado_descricao}`, `preco` em cêntimos, ordenadas por nome) e `filtros`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CatalogoFinanceiroTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function produto(string $nome, int $centimos = 100, ?string $codigo = null): Produto
    {
        return Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function servico(string $nome, int $centimos = 100, ?string $codigo = null): Servico
    {
        return Servico::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function url(array $filtros = []): string
    {
        return route('financeiro.configuracao.produtos-servicos.index', $filtros);
    }

    public function test_junta_produtos_e_servicos_ordenados_por_nome_com_o_tipo(): void
    {
        $this->produto('Uniforme Escolar', 2_500_000, 'UNI-001');
        $this->servico('Emissão de Certificado', 500_000, 'SER-001');
        $this->produto('Caderno', 50_000);

        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/ProdutosServicos/Index')
                ->has('itens.data', 3)
                ->where('itens.data.0.nome', 'Caderno')
                ->where('itens.data.0.tipo', 'produto')
                ->where('itens.data.1.nome', 'Emissão de Certificado')
                ->where('itens.data.1.tipo', 'servico')
                ->where('itens.data.1.preco', 500_000)
                ->where('itens.data.2.nome', 'Uniforme Escolar')
                ->missing('itens.data.0.tenant_id'));
    }

    public function test_filtra_por_tipo(): void
    {
        $this->produto('P1');
        $this->servico('S1');
        $this->actingAs($this->adminEscola());

        $this->get($this->url(['tipo' => 'produto']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.tipo', 'produto'));
        $this->get($this->url(['tipo' => 'servico']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.tipo', 'servico'));
    }

    public function test_pesquisa_por_nome_ou_codigo_e_filtra_por_estado_sem_esconder_inactivos(): void
    {
        $this->produto('Uniforme', 100, 'UNI-001');
        $this->servico('Certificado', 100, 'SER-001')->update(['estado' => 0]);
        $this->actingAs($this->adminEscola());

        $this->get($this->url(['pesquisa' => 'uni-0']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.nome', 'Uniforme'));

        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 2));

        $this->get($this->url(['estado' => '0']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.estado_descricao', 'Inativo'));
    }

    public function test_isola_por_tenant(): void
    {
        $this->produto('Meu');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $this->produto('Do Outro');
            $this->servico('Serviço Do Outro');
        });

        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.nome', 'Meu'));
    }

    public function test_pagina_os_resultados_do_catalogo_unificado(): void
    {
        foreach (range(1, 6) as $n) {
            $this->produto(sprintf('Produto %02d', $n));
        }
        foreach (range(1, 5) as $n) {
            $this->servico(sprintf('Servico %02d', $n));
        }
        $this->actingAs($this->adminEscola());

        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 10)->where('itens.total', 11));

        $this->get($this->url(['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.current_page', 2));
    }

    public function test_professor_recebe_403(): void
    {
        $this->actingAs($this->professor())->get($this->url())->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CatalogoFinanceiroTest.php`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/CatalogoConsultaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Support\Collection;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;

/**
 * Listagem unificada do catálogo. Produto e Servico continuam entidades distintas: aqui só
 * se juntam, para a interface, duas consultas filtradas e paginadas em memória (um catálogo
 * escolar tem dezenas de itens, não milhares).
 */
class CatalogoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        $tipo = $filtros['tipo'] ?? '';
        $linhas = collect();

        if ($tipo === '' || $tipo === 'produto') {
            $linhas = $linhas->concat($this->linhas(Produto::query(), 'produto', $filtros));
        }

        if ($tipo === '' || $tipo === 'servico') {
            $linhas = $linhas->concat($this->linhas(Servico::query(), 'servico', $filtros));
        }

        $ordenadas = $linhas->sortBy(fn (array $linha) => mb_strtolower($linha['nome']))->values();
        $pagina = Paginador::resolveCurrentPage();

        return (new Paginador(
            $ordenadas->forPage($pagina, $porPagina)->values(),
            $ordenadas->count(),
            $porPagina,
            $pagina,
            ['path' => Paginador::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function linhas(Builder $consulta, string $tipo, array $filtros): Collection
    {
        return $consulta
            ->when(($filtros['estado'] ?? '') !== '', fn (Builder $query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, function (Builder $query, string $pesquisa) {
                $query->where(function (Builder $query) use ($pesquisa) {
                    $query->whereContem('nome', $pesquisa)->orWhereContem('codigo', $pesquisa);
                });
            })
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'tipo' => $tipo,
                'codigo' => $item->codigo,
                'nome' => $item->nome,
                'descricao' => $item->descricao,
                'preco' => $item->preco->centimos(),
                'estado' => $item->estado,
                'estado_descricao' => $item->estado_descricao,
            ]);
    }
}
```

`Modules/Financeiro/app/Http/Controllers/CatalogoFinanceiroController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\CatalogoConsultaService;

class CatalogoFinanceiroController extends Controller
{
    public function __construct(
        private CatalogoConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('catalogo-financeiro.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'tipo']);

        return Inertia::render('Financeiro/ProdutosServicos/Index', [
            'itens' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
        ]);
    }
}
```

`routes/web.php`: `use Modules\Financeiro\Http\Controllers\CatalogoFinanceiroController;` e, dentro do grupo `financeiro/configuracao`, depois do bloco `servicos`:
```php
    Route::get('/produtos-servicos', [CatalogoFinanceiroController::class, 'index'])->middleware('can:catalogo-financeiro.ver')->name('produtos-servicos.index');
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 8: Frontend — Métodos de Pagamento (+ menus)

**Files:**
- Create: `Modules/Financeiro/resources/js/Models/Estado.js`, `Modules/Financeiro/resources/js/Components/Shared/EstadoBadge.vue`
- Create: `Modules/Financeiro/resources/js/Components/MetodosPagamento/MetodoPagamentoFormModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/MetodosPagamento/Index.vue`
- Modify: `resources/js/Composables/useConfiguracoesMenu.js`, `resources/js/Components/Layout/SidebarMenuWrapper.vue`

**Interfaces:**
- Consumes: componente `Financeiro/MetodosPagamento/Index` com props `metodos` (paginador), `filtros`, `tipos`; rotas da Task 4; componentes partilhados `AppLayout`, `AcaoIcone` (acções `editar`, `ativar`, `desativar`, `eliminar`), `ConfirmModal`, `SelectSolid`, `Pagination`; `can()` de `@/Composables/usePermissoes`.

- [ ] **Step 1: Ficheiros partilhados do módulo** (padrão do módulo Curso, que mantém a sua cópia)

`Modules/Financeiro/resources/js/Models/Estado.js`:
```js
/**
 * Espelha Modules/Core/app/Enums/Estado.php (Ativo/Inativo). Só para apresentação;
 * a autoridade é o backend.
 */
export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO.ATIVO: return 'badge-light-success';
        case ESTADO.INATIVO: return 'badge-inativo';
        default: return 'badge-light-secondary';
    }
};
```

`Modules/Financeiro/resources/js/Components/Shared/EstadoBadge.vue`:
```vue
<script setup>
import { estadoBadgeClass } from '../../Models/Estado';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
```

- [ ] **Step 2: Modal do formulário**

`Modules/Financeiro/resources/js/Components/MetodosPagamento/MetodoPagamentoFormModal.vue`:
```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    metodo: { type: Object, default: null },
    tipos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({ nome: '', tipo: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.metodo?.nome ?? '';
    form.tipo = props.metodo?.tipo ?? props.tipos[0]?.value ?? '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ metodo ? 'Editar Método de Pagamento' : 'Novo Método de Pagamento' }}</h3>
                <form @submit.prevent="emit('submit', { ...form })">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Transferência BAI" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                        <SelectSolid v-model="form.tipo" :options="tipos" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Página**

`Modules/Financeiro/resources/js/Pages/MetodosPagamento/Index.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import MetodoPagamentoFormModal from '../../Components/MetodosPagamento/MetodoPagamentoFormModal.vue';
import { ESTADO } from '../../Models/Estado';

const BASE = '/financeiro/configuracao/metodos-pagamento';

const props = defineProps({
    metodos: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    tipos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado ?? '',
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const modalAberto = ref(false);
const metodoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    metodoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(metodo) {
    metodoEmEdicao.value = metodo;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = metodoEmEdicao.value ? `${BASE}/${metodoEmEdicao.value.id}` : BASE;
    const metodo = metodoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(metodoEmEdicao.value ? 'Método de pagamento atualizado com sucesso.' : 'Método de pagamento criado com sucesso.');
            modalAberto.value = false;
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const paraEstado = ref(null);
const novoEstado = ref(null);
const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEstado() {
    aProcessar.value = true;
    router.patch(`${BASE}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do método de pagamento atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEstado.value = null;
            novoEstado.value = null;
        },
    });
}

function confirmarEliminacao() {
    aProcessar.value = true;
    router.delete(`${BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Método de pagamento eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Métodos de Pagamento</h1>
                <p class="text-muted fs-6 mb-0">Os métodos que a escola aceita receber.</p>
            </div>
            <button v-if="can('metodo-pagamento.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Método</button>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Nome" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Tipo</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="metodos.data.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum método de pagamento encontrado.</td>
                        </tr>
                        <tr v-for="metodo in metodos.data" :key="metodo.id">
                            <td class="text-gray-800">{{ metodo.nome }}</td>
                            <td>{{ metodo.tipo_descricao }}</td>
                            <td><EstadoBadge :estado="metodo.estado" :estado-descricao="metodo.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('metodo-pagamento.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(metodo)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.editar') && metodo.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = metodo; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.editar') && metodo.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = metodo; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = metodo">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="metodos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="metodos.links" />
            </div>
        </div>

        <MetodoPagamentoFormModal
            :show="modalAberto"
            :metodo="metodoEmEdicao"
            :tipos="tipos"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
        />

        <ConfirmModal
            :show="!!paraEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado de ${paraEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="aProcessar"
            @confirmar="confirmarEstado"
            @cancelar="paraEstado = null; novoEstado = null"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar método de pagamento"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
```

- [ ] **Step 4: Menus — os DOIS sítios**

Em `resources/js/Composables/useConfiguracoesMenu.js`, no grupo `Financeiro` (criado no plano 1), acrescentar depois de *Regras de Cobrança*:
```js
            { href: '/financeiro/configuracao/metodos-pagamento', label: 'Métodos de Pagamento', permissao: 'metodo-pagamento.ver' },
```
Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, no array `configuracoesMenu`, grupo `Financeiro`, depois de *Regras de Cobrança*:
```js
            { href: '/financeiro/configuracao/metodos-pagamento', title: 'Métodos de Pagamento', permissao: 'metodo-pagamento.ver' },
```

- [ ] **Step 5: Build e stage**

Run: `npm run build`
Expected: sem erros. Confirmar com `git status --short` que nada gerado (public/build) foi para stage.

```bash
git add Modules/Financeiro/resources resources/js/Composables/useConfiguracoesMenu.js resources/js/Components/Layout/SidebarMenuWrapper.vue
```

(Verificação visual no browser: do controlador, fora da dispatch.)

---

### Task 9: Frontend — Produtos / Serviços (+ menus)

**Files:**
- Create: `Modules/Financeiro/resources/js/Components/ProdutosServicos/CatalogoItemFormModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/ProdutosServicos/Index.vue`
- Modify: `resources/js/Composables/useConfiguracoesMenu.js`, `resources/js/Components/Layout/SidebarMenuWrapper.vue`

**Interfaces:**
- Consumes: componente `Financeiro/ProdutosServicos/Index` com props `itens` (paginador de `{id, tipo, codigo, nome, descricao, preco, estado, estado_descricao}`) e `filtros` (`pesquisa`, `estado`, `tipo`); rotas `/financeiro/configuracao/{produtos|servicos}` (POST, PUT `/{id}`, PATCH `/{id}/estado`, DELETE `/{id}`) das Tasks 5–6; `formatKz` e `centimosParaKz` (T2); `EstadoBadge` e `Estado.js` (T8).

- [ ] **Step 1: Modal do item (produto ou serviço)**

`Modules/Financeiro/resources/js/Components/ProdutosServicos/CatalogoItemFormModal.vue`:
```vue
<script setup>
import { computed, reactive, watch } from 'vue';
import { centimosParaKz } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    tipo: { type: String, required: true }, // 'produto' | 'servico'
    item: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const rotulo = computed(() => (props.tipo === 'produto' ? 'Produto' : 'Serviço'));
const form = reactive({ nome: '', codigo: '', descricao: '', preco: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.item?.nome ?? '';
    form.codigo = props.item?.codigo ?? '';
    form.descricao = props.item?.descricao ?? '';
    form.preco = props.item ? centimosParaKz(props.item.preco) : '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ item ? `Editar ${rotulo}` : `Novo ${rotulo}` }}</h3>
                <form @submit.prevent="emit('submit', { ...form })">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" :placeholder="tipo === 'produto' ? 'ex: UNI-001' : 'ex: SER-001'" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Preço (Kz)</label>
                            <input v-model="form.preco" type="text" inputmode="decimal" class="form-control form-control-solid" placeholder="ex: 25000 ou 25000,50" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.preco">{{ errors.preco }}</div>
                        </div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Página com tabs Todos / Produtos / Serviços**

`Modules/Financeiro/resources/js/Pages/ProdutosServicos/Index.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import CatalogoItemFormModal from '../../Components/ProdutosServicos/CatalogoItemFormModal.vue';
import { ESTADO } from '../../Models/Estado';
import { formatKz } from '../../Support/dinheiro';

const LISTA = '/financeiro/configuracao/produtos-servicos';
const BASE = { produto: '/financeiro/configuracao/produtos', servico: '/financeiro/configuracao/servicos' };
const ROTULO = { produto: 'Produto', servico: 'Serviço' };
const MENSAGEM_GUARDAR = {
    produto: { criado: 'Produto criado com sucesso.', atualizado: 'Produto atualizado com sucesso.' },
    servico: { criado: 'Serviço criado com sucesso.', atualizado: 'Serviço atualizado com sucesso.' },
};

const props = defineProps({
    itens: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const separadores = [
    { valor: '', texto: 'Todos' },
    { valor: 'produto', texto: 'Produtos' },
    { valor: 'servico', texto: 'Serviços' },
];

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado ?? '',
    tipo: props.filtros.tipo ?? '',
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(LISTA, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const modalAberto = ref(false);
const tipoDoModal = ref('produto');
const itemEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao(tipo) {
    tipoDoModal.value = tipo;
    itemEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(item) {
    tipoDoModal.value = item.tipo;
    itemEmEdicao.value = item;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const tipo = tipoDoModal.value;
    const edicao = itemEmEdicao.value;
    const url = edicao ? `${BASE[tipo]}/${edicao.id}` : BASE[tipo];

    router[edicao ? 'put' : 'post'](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(MENSAGEM_GUARDAR[tipo][edicao ? 'atualizado' : 'criado']);
            modalAberto.value = false;
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const paraEstado = ref(null);
const novoEstado = ref(null);
const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEstado() {
    aProcessar.value = true;
    router.patch(`${BASE[paraEstado.value.tipo]}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEstado.value = null;
            novoEstado.value = null;
        },
    });
}

function confirmarEliminacao() {
    aProcessar.value = true;
    router.delete(`${BASE[paraEliminar.value.tipo]}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Item eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Produtos / Serviços</h1>
                <p class="text-muted fs-6 mb-0">O catálogo de itens que a escola disponibiliza. Cadastrar um item não cria cobranças.</p>
            </div>
            <div v-if="can('catalogo-financeiro.criar')" class="d-flex gap-2">
                <button class="btn btn-primary" @click="abrirCriacao('produto')">Novo Produto</button>
                <button class="btn btn-light-primary" @click="abrirCriacao('servico')">Novo Serviço</button>
            </div>
        </div>

        <ul class="nav nav-tabs nav-line-tabs fs-6 mb-6">
            <li v-for="separador in separadores" :key="separador.valor" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: filtros.tipo === separador.valor }" @click.prevent="filtros.tipo = separador.valor">
                    {{ separador.texto }}
                </a>
            </li>
        </ul>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Código ou nome" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="text-end min-w-125px">Preço</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="itens.data.length === 0">
                            <td colspan="6" class="text-center text-muted py-6">Nenhum item encontrado.</td>
                        </tr>
                        <tr v-for="item in itens.data" :key="`${item.tipo}-${item.id}`">
                            <td>{{ item.codigo ?? '—' }}</td>
                            <td class="text-gray-800">{{ item.nome }}</td>
                            <td>
                                <span class="badge" :class="item.tipo === 'produto' ? 'badge-light-primary' : 'badge-light-info'">{{ ROTULO[item.tipo] }}</span>
                            </td>
                            <td class="text-end">{{ formatKz(item.preco) }}</td>
                            <td><EstadoBadge :estado="item.estado" :estado-descricao="item.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('catalogo-financeiro.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(item)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.editar') && item.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = item; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.editar') && item.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = item; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = item">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="itens.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="itens.links" />
            </div>
        </div>

        <CatalogoItemFormModal
            :show="modalAberto"
            :tipo="tipoDoModal"
            :item="itemEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
        />

        <ConfirmModal
            :show="!!paraEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado de ${paraEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="aProcessar"
            @confirmar="confirmarEstado"
            @cancelar="paraEstado = null; novoEstado = null"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar item"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
```
- [ ] **Step 3: Menus — os DOIS sítios**

Em `resources/js/Composables/useConfiguracoesMenu.js`, no grupo `Financeiro`, depois de *Métodos de Pagamento*:
```js
            { href: '/financeiro/configuracao/produtos-servicos', label: 'Produtos / Serviços', permissao: 'catalogo-financeiro.ver' },
```
Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, array `configuracoesMenu`, grupo `Financeiro`, depois de *Métodos de Pagamento*:
```js
            { href: '/financeiro/configuracao/produtos-servicos', title: 'Produtos / Serviços', permissao: 'catalogo-financeiro.ver' },
```
Uma só entrada para os dois tipos (não criar "Produtos" e "Serviços" separados).

- [ ] **Step 4: Build e stage**

Run: `npm run build`
Expected: sem erros; nada gerado em stage.

```bash
git add Modules/Financeiro/resources resources/js/Composables/useConfiguracoesMenu.js resources/js/Components/Layout/SidebarMenuWrapper.vue
```

---

### Task 10: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~150 s)
Expected: 0 falhas. Falhas em testes de outros módulos são regressões deste plano (por exemplo, a matriz de isolamento ou contagens de módulos de permissão): corrigir seguindo a convenção do projecto, sem enfraquecer nenhum teste, e documentar quais e porquê.

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 3: Estado do git**

Run: `git status --short`
Expected: só ficheiros deste plano em stage, nada gerado, nenhum commit. Informar o dono de que falta (a) `php artisan migrate`, (b) `php artisan db:seed --force`, (c) `php artisan financeiro:sincronizar --todos` (módulos 18 e 19 e as permissões do `ADMIN_ESCOLA`), e (d) a verificação visual no browser.
