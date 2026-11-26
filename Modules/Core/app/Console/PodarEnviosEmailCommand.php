<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Comunicacao\Email\PodarEnviosEmailAction;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;

/** Apaga o histórico de envios de email antigo (por omissão, mais de 180 dias). Sem lógica: delega na Action. */
class PodarEnviosEmailCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'mosi:tenant:envios-email:prune
        {--days=180 : Dias a reter o histórico}';

    protected $description = 'Apaga o histórico de envios de email antigo (--tenant=CODIGO ou --todos)';

    public function handle(PodarEnviosEmailAction $action): int
    {
        $dias = max(1, (int) $this->option('days'));

        return $this->paraTenants(fn () => $this->line($action->executar($dias).' registo(s) de envio apagado(s).'));
    }
}
