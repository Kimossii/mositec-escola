<?php

namespace Modules\Docente\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Traits\LogService;
use Modules\Docente\Actions\GarantirDocenteDeUtilizadorAction;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/** Garante o registo de docente de todos os utilizadores Professor de cada escola (decisão E7). Idempotente. */
class SincronizarDocentesCommand extends Command
{
    use LogService;
    use ParaTodosOsTenants;

    protected $signature = 'docentes:sincronizar';

    protected $description = 'Cria o registo de docente dos utilizadores com perfil Professor que ainda não o têm (--tenant=CODIGO ou --todos)';

    public function handle(GarantirDocenteDeUtilizadorAction $garantir): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($garantir): void {
            $criados = 0;

            User::query()
                ->whereHas('roles', fn ($q) => $q->where('nome', Perfil::PROFESSOR->value))
                ->chunkById(100, function ($utilizadores) use ($garantir, &$criados) {
                    foreach ($utilizadores as $utilizador) {
                        $criados += $garantir->executar($utilizador)->wasRecentlyCreated ? 1 : 0;
                    }
                });

            $this->logInfo('docentes.sincronizar', ['tenant' => $tenant->codigo, 'criados' => $criados]);
            $this->line("[{$tenant->codigo}] {$criados} docente(s) criado(s).");
        });
    }
}
