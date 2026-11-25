<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Matricula\Support\InstantaneoDeMatricula;
use Modules\Core\Traits\LogService;

class EliminarMatriculaAction
{
    use LogService;

    public function __construct(private RegistarAuditoriaTenantAction $auditoria)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula): void
    {
        $this->comLog('Eliminar matrícula', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula));
    }

    private function executarSemRegisto(Matricula $matricula): void
    {
        if ($matricula->estado !== EstadoMatriculaEnum::PENDENTE) {
            throw ValidationException::withMessages([
                'matricula' => 'Só é possível eliminar uma matrícula ainda Pendente. Para as restantes, altere o estado para Cancelada.',
            ]);
        }

        DB::transaction(function () use ($matricula) {
            $antes = InstantaneoDeMatricula::de($matricula);
            $matricula->delete();
            $this->auditoria->executar(new AuditoriaTenantDTO('matricula.eliminada', 'matriculas', (int) $matricula->id, $antes, array_fill_keys(array_keys($antes), null)));
        });
    }
}
