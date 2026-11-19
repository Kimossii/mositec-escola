<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Traits\LogService;

/** Corre agora as verificações de saúde e guarda o resultado (o mesmo que `php artisan health:check`, sem enviar avisos). */
class ExecutarVerificacoesDeSaudeAction
{
    use LogService;

    public function executar(): void
    {
        $this->comLog('Executar verificações de saúde', [], fn () => Artisan::call('health:check', ['--no-notification' => true]));
    }
}
