<?php

namespace Modules\Core\Comunicacao\Email;

use Modules\Core\Models\EnvioEmail;
use Modules\Core\Traits\LogService;

/** Apaga do tenant corrente os registos de envio com mais de `$dias` dias (o histórico não cresce sem fim). */
class PodarEnviosEmailAction
{
    use LogService;

    public function executar(int $dias): int
    {
        return $this->comLog('Podar envios de email', ['dias' => $dias], fn () => EnvioEmail::query()->where('created_at', '<', now()->subDays($dias))->delete());
    }
}
