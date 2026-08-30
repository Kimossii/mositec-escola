# Módulo Core — extração de `Estado` genérico

## Contexto

`Modules/Usuario/app/Enums/EstadoUsuario.php` (Ativo/Inativo) é usado por
`Modules/Permissao` só para ter acesso ao conceito genérico de estado. Isso
cria uma dependência artificial `Permissao ─► Usuario`. O conceito não
pertence ao domínio de utilizadores — deve migrar para um novo módulo
transversal `Modules\Core`.

## Princípio arquitetural

```
Core → pode ser utilizado por qualquer módulo.
Módulos de domínio → continuam responsáveis pelas suas próprias regras.
Um módulo não deve depender de outro só para reutilizar um conceito do Core.
Core não deve conter regras específicas de domínio.
```

Resultado desejado:

```
Usuario ───────────────► Core
Permissao ─────────────► Core
Estabelecimento ───────► Core   (quando precisar do conceito)
```

## Estado atual (confirmado por leitura do código)

`Modules/Usuario/app/Enums/EstadoUsuario.php`:

```php
enum EstadoUsuario: int
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

Referências (grep exaustivo, ver plano para detalhe file:line):

- `Modules/Usuario/app/Models/User.php` — import + hook `saving` (`estado_descricao`)
- `Modules/Usuario/app/DTO/UsuarioDTO.php` — tipo de propriedade + `fromArray()`
- `Modules/Usuario/app/Actions/AlternarEstadoUsuarioAction.php` — toggle
- `Modules/Permissao/app/Models/Role.php` — import + hook `saving` (única razão para depender de `EstadoUsuario`; `Role` também importa `Modules\Usuario\Models\User` para relações — isso é legítimo e fica fora do escopo)
- `Modules/Permissao/app/Models/Modulo.php` — import + hook `saving` (única dependência de `Modules\Usuario` neste ficheiro)
- `Modules/Permissao/app/Models/Acao.php` — import + hook `saving` (idem)
- `Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php` — toggle (única dependência de `Modules\Usuario` neste ficheiro)

Nenhum teste referencia `EstadoUsuario` diretamente (apenas o valor inteiro
`'estado' => 1|0` via payload HTTP / `assertDatabaseHas`). Não há Casts
custom. `Estabelecimento` usa um booleano `is_active`, não este enum — fora
de escopo mudar isso agora.

`AlternarEstadoUsuarioAction` e `AlternarEstadoPerfilAction` têm lógica de
toggle idêntica (duplicação real):

```php
$novoEstado = $model->estado === EstadoUsuario::ATIVO->value
    ? EstadoUsuario::INATIVO
    : EstadoUsuario::ATIVO;
$model->update(['estado' => $novoEstado->value]);
return $model;
```

→ candidata a `Modules\Core\Traits\AlternaEstado`, consumida pelas duas
Actions (que continuam a existir — são o ponto de extensão para regras
próprias futuras).

## O que construir

1. `Modules/Core` — módulo nwidart mínimo (module.json, composer.json,
   config/config.php, `CoreServiceProvider` sem overrides, sem rotas/views/
   controllers/migrations).
2. `Modules\Core\Enums\Estado` — enum `int` backed, mesmos valores/labels de
   `EstadoUsuario`.
3. `Modules\Core\Traits\AlternaEstado` — trait com toggle genérico, usada
   pelas duas Actions existentes (que não são substituídas).
4. Migrar `Usuario` e `Permissao` para `Estado` (imports + hooks + DTO +
   Actions).
5. Remover `Modules/Usuario/app/Enums/EstadoUsuario.php` — sem alias, sem
   wrapper, sem `extends`.

## Fora de escopo

Regras de negócio, API, rotas, base de dados, nomes de tabelas/colunas,
valores persistidos, permissões, frontend/Vue, `Estabelecimento`'s
`is_active` (booleano), qualquer Service/Action/Model/DTO específico de
domínio dentro do Core.

## Critério de sucesso

`Permissao` deixa de importar `Modules\Usuario\Enums\EstadoUsuario`.
`Modules\Core\Enums\Estado` é a única fonte de verdade para Ativo/Inativo.
Testes de `Usuario` e `Permissao` continuam a passar sem alteração de
comportamento.
