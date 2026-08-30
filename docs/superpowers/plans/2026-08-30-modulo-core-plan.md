# Módulo Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extrair o conceito genérico Ativo/Inativo de `Modules\Usuario\Enums\EstadoUsuario` para um novo módulo transversal `Modules\Core`, eliminando a dependência artificial `Permissao ─► Usuario`, sem alterar comportamento, API, base de dados ou frontend.

**Architecture:** Novo módulo nwidart/laravel-modules mínimo (`Modules/Core`) contendo apenas um enum (`Estado`) e um trait de toggle (`AlternaEstado`). `Usuario` e `Permissao` passam a importar de `Core` em vez de `Usuario` definir o conceito. As Actions de toggle existentes (`AlternarEstadoUsuarioAction`, `AlternarEstadoPerfilAction`) continuam a existir e passam a usar o trait partilhado.

**Tech Stack:** Laravel, nwidart/laravel-modules, PHPUnit.

**Spec:** [docs/superpowers/specs/2026-08-30-modulo-core-design.md](../specs/2026-08-30-modulo-core-design.md)

## Global Constraints

- Não alterar valores persistidos: `INATIVO = 0`, `ATIVO = 1` (exatamente como em `EstadoUsuario`).
- Não alterar nomes de colunas, tabelas, rotas, API ou frontend/Vue.
- Não criar wrapper/alias (`class EstadoUsuario extends Estado {}` é proibido).
- Não criar Services, Models, Actions, DTOs, Controllers, Routes ou Views no Core.
- `CoreServiceProvider` fica com a implementação por omissão de `ModuleServiceProvider` (sem overrides) — sem rotas/views/migrations a carregar.
- As Actions `AlternarEstadoUsuarioAction` e `AlternarEstadoPerfilAction` continuam a existir (não substituídas por uma Action genérica no Core).

---

## File Structure

```
Modules/Core/
  module.json                              (novo)
  composer.json                            (novo)
  config/config.php                        (novo)
  app/
    Enums/Estado.php                       (novo)
    Traits/AlternaEstado.php               (novo)
    Providers/CoreServiceProvider.php      (novo)
  tests/Unit/
    EstadoTest.php                         (novo)
    AlternaEstadoTest.php                  (novo)

modules_statuses.json                      (modificado: + "Core": true)

Modules/Usuario/app/Models/User.php                        (modificado)
Modules/Usuario/app/DTO/UsuarioDTO.php                      (modificado)
Modules/Usuario/app/Actions/AlternarEstadoUsuarioAction.php (modificado)
Modules/Usuario/app/Enums/EstadoUsuario.php                 (removido, Task 5)

Modules/Permissao/app/Models/Role.php                       (modificado)
Modules/Permissao/app/Models/Modulo.php                     (modificado)
Modules/Permissao/app/Models/Acao.php                       (modificado)
Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php (modificado)
```

---

### Task 1: Scaffold do módulo Core + Enum `Estado`

**Files:**
- Create: `Modules/Core/module.json`
- Create: `Modules/Core/composer.json`
- Create: `Modules/Core/config/config.php`
- Create: `Modules/Core/app/Providers/CoreServiceProvider.php`
- Create: `Modules/Core/app/Enums/Estado.php`
- Create: `Modules/Core/tests/Unit/EstadoTest.php`
- Modify: `modules_statuses.json`

**Interfaces:**
- Produces: `Modules\Core\Enums\Estado` — enum `int` backed com `case INATIVO = 0`, `case ATIVO = 1`, método `public function label(): string`. Usado por todas as tasks seguintes.

- [ ] **Step 1: Criar `module.json`**

```json
{
    "name": "Core",
    "alias": "core",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Core\\Providers\\CoreServiceProvider"
    ],
    "files": []
}
```

- [ ] **Step 2: Criar `composer.json` do módulo**

```json
{
    "name": "nwidart/core",
    "autoload": {
        "psr-4": {
            "Modules\\Core\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Core\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Criar `config/config.php`**

```php
<?php

return [
    'name' => 'Core',
];
```

- [ ] **Step 4: Criar `CoreServiceProvider`**

```php
<?php

namespace Modules\Core\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';
}
```

Sem overrides de `boot()`/`register()` — a implementação por omissão do
pacote já trata de config/translations/views/migrations em falta sem
erro (verificado em `vendor/nwidart/laravel-modules/src/Support/ModuleServiceProvider.php`).

- [ ] **Step 5: Ativar o módulo em `modules_statuses.json`**

Adicionar `"Core": true` ao objeto existente (manter as outras entradas):

```json
{
    "Usuario": true,
    "Autenticacao": true,
    "Permissao": true,
    "Estabelecimento": true,
    "Core": true
}
```

- [ ] **Step 6: Criar o enum `Estado`**

```php
<?php

namespace Modules\Core\Enums;

enum Estado: int
{
    case INATIVO = 0;
    case ATIVO = 1;

    public function label(): string
    {
        return match ($this) {
            self::INATIVO => 'Inativo',
            self::ATIVO => 'Ativo',
        };
    }
}
```

- [ ] **Step 7: Escrever o teste unitário do enum**

```php
<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Enums\Estado;
use PHPUnit\Framework\TestCase;

class EstadoTest extends TestCase
{
    public function test_valores_sao_os_persistidos_atualmente(): void
    {
        $this->assertSame(0, Estado::INATIVO->value);
        $this->assertSame(1, Estado::ATIVO->value);
    }

    public function test_label_devolve_texto_legivel(): void
    {
        $this->assertSame('Inativo', Estado::INATIVO->label());
        $this->assertSame('Ativo', Estado::ATIVO->label());
    }

    public function test_from_resolve_a_partir_do_inteiro_persistido(): void
    {
        $this->assertSame(Estado::ATIVO, Estado::from(1));
        $this->assertSame(Estado::INATIVO, Estado::from(0));
    }
}
```

- [ ] **Step 8: Atualizar o autoloader e correr o teste**

Run: `composer dump-autoload && php artisan module:list`
Expected: tabela de módulos lista `Core` como `Enabled`.

Run: `php artisan test Modules/Core/tests/Unit/EstadoTest.php`
Expected: 3 testes, todos PASS.

- [ ] **Step 9: Commit**

```bash
git add Modules/Core/module.json Modules/Core/composer.json Modules/Core/config/config.php \
        Modules/Core/app/Providers/CoreServiceProvider.php Modules/Core/app/Enums/Estado.php \
        Modules/Core/tests/Unit/EstadoTest.php modules_statuses.json composer.lock
git commit -m "feat(core): criar módulo Core com enum Estado"
```

---

### Task 2: Trait `AlternaEstado`

**Files:**
- Create: `Modules/Core/app/Traits/AlternaEstado.php`
- Create: `Modules/Core/tests/Unit/AlternaEstadoTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Enums\Estado` (Task 1).
- Produces: `Modules\Core\Traits\AlternaEstado` com método protegido
  `alternarEstado(\Illuminate\Database\Eloquent\Model $model): \Illuminate\Database\Eloquent\Model`
  — usado pelas Actions nas Tasks 3 e 4.

- [ ] **Step 1: Escrever o teste (falha primeiro — a trait ainda não existe)**

```php
<?php

namespace Modules\Core\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\AlternaEstado;
use PHPUnit\Framework\TestCase;

class ModeloComEstadoFake extends Model
{
    protected $fillable = ['estado'];
    public array $atributosAtualizados = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->atributosAtualizados = $attributes;
        $this->estado = $attributes['estado'];

        return true;
    }
}

class AlternaEstadoConsumidorFake
{
    use AlternaEstado;

    public function chamar(Model $model): Model
    {
        return $this->alternarEstado($model);
    }
}

class AlternaEstadoTest extends TestCase
{
    public function test_alterna_de_ativo_para_inativo(): void
    {
        $model = new ModeloComEstadoFake(['estado' => Estado::ATIVO->value]);

        $resultado = (new AlternaEstadoConsumidorFake())->chamar($model);

        $this->assertSame(Estado::INATIVO->value, $resultado->estado);
        $this->assertSame(['estado' => Estado::INATIVO->value], $model->atributosAtualizados);
    }

    public function test_alterna_de_inativo_para_ativo(): void
    {
        $model = new ModeloComEstadoFake(['estado' => Estado::INATIVO->value]);

        $resultado = (new AlternaEstadoConsumidorFake())->chamar($model);

        $this->assertSame(Estado::ATIVO->value, $resultado->estado);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Core/tests/Unit/AlternaEstadoTest.php`
Expected: FAIL — `Trait "Modules\Core\Traits\AlternaEstado" not found`.

- [ ] **Step 3: Implementar a trait**

```php
<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;

trait AlternaEstado
{
    protected function alternarEstado(Model $model): Model
    {
        $novoEstado = $model->estado === Estado::ATIVO->value
            ? Estado::INATIVO
            : Estado::ATIVO;

        $model->update(['estado' => $novoEstado->value]);

        return $model;
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Core/tests/Unit/AlternaEstadoTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Core/app/Traits/AlternaEstado.php Modules/Core/tests/Unit/AlternaEstadoTest.php
git commit -m "feat(core): criar trait AlternaEstado para toggle genérico"
```

---

### Task 3: Migrar `Modules/Usuario` para `Estado`

**Files:**
- Modify: `Modules/Usuario/app/Models/User.php`
- Modify: `Modules/Usuario/app/DTO/UsuarioDTO.php`
- Modify: `Modules/Usuario/app/Actions/AlternarEstadoUsuarioAction.php`
- Test (existentes, correr sem alterar): `Modules/Usuario/tests/Feature/CriarUsuarioTest.php`, `AtualizarUsuarioTest.php`, `EliminarUsuarioTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Enums\Estado` (Task 1), `Modules\Core\Traits\AlternaEstado` (Task 2).

- [ ] **Step 1: `User.php` — trocar o import e a chamada no hook `saving`**

Em `Modules/Usuario/app/Models/User.php`, substituir:

```php
use Modules\Usuario\Enums\EstadoUsuario;
```

por:

```php
use Modules\Core\Enums\Estado;
```

e no hook `saving`, substituir:

```php
$user->estado_descricao = EstadoUsuario::from($user->estado ?? 1)->label();
```

por:

```php
$user->estado_descricao = Estado::from($user->estado ?? 1)->label();
```

- [ ] **Step 2: `UsuarioDTO.php` — trocar tipo e defaults**

Substituir `use Modules\Usuario\Enums\EstadoUsuario;` por
`use Modules\Core\Enums\Estado;`; a propriedade do construtor
`public EstadoUsuario $estado = EstadoUsuario::ATIVO,` passa a
`public Estado $estado = Estado::ATIVO,`; em `fromArray()`,
`EstadoUsuario::from($data['estado'])` passa a `Estado::from($data['estado'])`
e `: EstadoUsuario::ATIVO` passa a `: Estado::ATIVO`.

- [ ] **Step 3: `AlternarEstadoUsuarioAction.php` — usar a trait do Core**

```php
<?php

namespace Modules\Usuario\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Usuario\Models\User;

class AlternarEstadoUsuarioAction
{
    use AlternaEstado;

    public function executar(User $user): User
    {
        return $this->alternarEstado($user);
    }
}
```

- [ ] **Step 4: Correr a suite de testes de Usuario**

Run: `php artisan test Modules/Usuario/tests/Feature/CriarUsuarioTest.php Modules/Usuario/tests/Feature/AtualizarUsuarioTest.php Modules/Usuario/tests/Feature/EliminarUsuarioTest.php`
Expected: todos os testes continuam PASS (nenhuma alteração de comportamento esperada).

- [ ] **Step 5: Commit**

```bash
git add Modules/Usuario/app/Models/User.php Modules/Usuario/app/DTO/UsuarioDTO.php \
        Modules/Usuario/app/Actions/AlternarEstadoUsuarioAction.php
git commit -m "refactor(usuario): usar Modules\\Core\\Enums\\Estado em vez de EstadoUsuario"
```

---

### Task 4: Migrar `Modules/Permissao` para `Estado` (remove a dependência de `Usuario`)

**Files:**
- Modify: `Modules/Permissao/app/Models/Role.php`
- Modify: `Modules/Permissao/app/Models/Modulo.php`
- Modify: `Modules/Permissao/app/Models/Acao.php`
- Modify: `Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php`
- Test (existentes, correr sem alterar): `Modules/Permissao/tests/Feature/GestaoPerfisTest.php`, `SincronizarPermissoesPerfilActionTest.php`, `SincronizarPermissoesUtilizadorActionTest.php`, `PermissoesUtilizadorTest.php`, `PermissaoConsultaServiceTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Enums\Estado` (Task 1), `Modules\Core\Traits\AlternaEstado` (Task 2).

- [ ] **Step 1: `Role.php` — trocar apenas o import/uso do estado**

Substituir `use Modules\Usuario\Enums\EstadoUsuario;` por
`use Modules\Core\Enums\Estado;` (manter `use Modules\Usuario\Models\User;`
— essa dependência é legítima, é para a relação `belongsTo`/`belongsToMany`,
não faz parte deste refactor). No hook `saving`:

```php
$role->estado_descricao = Estado::from($role->estado ?? 1)->label();
```

- [ ] **Step 2: `Modulo.php` — trocar o import/uso do estado**

Substituir `use Modules\Usuario\Enums\EstadoUsuario;` por
`use Modules\Core\Enums\Estado;`. No hook `saving`:

```php
$modulo->estado_descricao = Estado::from($modulo->estado ?? 1)->label();
```

Este ficheiro deixa de ter qualquer `use Modules\Usuario\...`.

- [ ] **Step 3: `Acao.php` — trocar o import/uso do estado**

Substituir `use Modules\Usuario\Enums\EstadoUsuario;` por
`use Modules\Core\Enums\Estado;`. No hook `saving`:

```php
$acao->estado_descricao = Estado::from($acao->estado ?? 1)->label();
```

Este ficheiro deixa de ter qualquer `use Modules\Usuario\...`.

- [ ] **Step 4: `AlternarEstadoPerfilAction.php` — usar a trait do Core**

```php
<?php

namespace Modules\Permissao\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;

class AlternarEstadoPerfilAction
{
    use AlternaEstado;

    public function executar(Role $role): Role
    {
        return $this->alternarEstado($role);
    }
}
```

Este ficheiro deixa de ter qualquer `use Modules\Usuario\...`.

- [ ] **Step 5: Confirmar que `Permissao` só depende de `Usuario` onde é legítimo**

Run: `grep -rn "Modules\\\\Usuario" Modules/Permissao/app`
Expected: única ocorrência remanescente é `Role.php` a importar
`Modules\Usuario\Models\User` (relação de negócio real, fora de escopo).
Nenhuma ocorrência de `EstadoUsuario`.

- [ ] **Step 6: Correr a suite de testes de Permissao**

Run: `php artisan test Modules/Permissao/tests/Feature/GestaoPerfisTest.php Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php Modules/Permissao/tests/Feature/PermissoesUtilizadorTest.php Modules/Permissao/tests/Feature/PermissaoConsultaServiceTest.php`
Expected: todos os testes continuam PASS.

- [ ] **Step 7: Commit**

```bash
git add Modules/Permissao/app/Models/Role.php Modules/Permissao/app/Models/Modulo.php \
        Modules/Permissao/app/Models/Acao.php Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php
git commit -m "refactor(permissao): depender de Modules\\Core\\Enums\\Estado em vez de Modules\\Usuario"
```

---

### Task 5: Remover `EstadoUsuario` e validar globalmente

**Files:**
- Delete: `Modules/Usuario/app/Enums/EstadoUsuario.php`

- [ ] **Step 1: Confirmar que não sobra nenhuma referência**

Run: `grep -rn "EstadoUsuario" --include="*.php" Modules app database routes config`
Expected: nenhum resultado (fora dos ficheiros de `docs/superpowers/plans` e
`docs/superpowers/specs` já existentes, que são histórico e não código).

- [ ] **Step 2: Remover o ficheiro**

```bash
git rm Modules/Usuario/app/Enums/EstadoUsuario.php
```

- [ ] **Step 3: Correr a suite completa de Usuario, Permissao e Core**

Run: `php artisan test --testsuite=Feature --filter=Usuario` e
`php artisan test --testsuite=Feature --filter=Permissao` (ou os ficheiros
específicos listados nas Tasks 3 e 4) mais
`php artisan test Modules/Core/tests/Unit`
Expected: tudo PASS, nenhuma falha por classe em falta.

- [ ] **Step 4: Correr a suite completa do projeto**

Run: `php artisan test`
Expected: nenhuma regressão introduzida por este refactor (falhas
pré-existentes e não relacionadas, se as houver, devem ser identificadas e
reportadas, não escondidas).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(usuario): remover EstadoUsuario — Modules\\Core\\Enums\\Estado é a única fonte de verdade"
```

---

## Validation Checklist (pós-execução, ver spec seção 15)

- [ ] `grep -rn "EstadoUsuario"` no código não devolve nada.
- [ ] `Modules\Core\Enums\Estado` carrega corretamente (`php artisan module:list` mostra `Core: Enabled`; `composer dump-autoload` sem erros).
- [ ] `Usuario` funciona com `Estado` (testes Task 3 verdes).
- [ ] `Permissao` funciona com `Estado` (testes Task 4 verdes).
- [ ] `Permissao` não importa mais `Modules\Usuario\Enums\EstadoUsuario` em nenhum ficheiro.
- [ ] Suite completa (`php artisan test`) sem regressões.
