<?php

namespace Modules\Autenticacao\Console;

use Illuminate\Console\Command;
use Modules\Autenticacao\Actions\PodarTokensExpiradosAction;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;

/**
 * Equivalente por tenant de `sanctum:prune-expired` (que, sem contexto, falharia: o model de
 * token tem scope). Apaga os tokens expirados do tenant há mais de --hours horas.
 * Sem lógica: delega na Action.
 */
class PodarTokensCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'mosi:tenant:tokens:prune
        {--hours=24 : Horas a reter os tokens expirados}';

    protected $description = 'Apaga tokens Sanctum expirados (--tenant=CODIGO ou --todos)';

    public function handle(PodarTokensExpiradosAction $action): int
    {
        $horas = (int) $this->option('hours');

        return $this->paraTenants(function () use ($action, $horas) {
            $apagados = $action->executar($horas);

            $this->line("{$apagados} token(s) expirado(s) apagado(s).");
        });
    }
}
