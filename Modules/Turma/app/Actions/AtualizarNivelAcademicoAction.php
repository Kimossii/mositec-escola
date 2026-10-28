<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;
use Modules\Core\Traits\LogService;

class AtualizarNivelAcademicoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        NivelAcademico $nivelAcademico,
        NivelAcademicoDTO $dto
    ): NivelAcademico
    {
        return $this->comLog('Atualizar nível académico', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($nivelAcademico, $dto));
    }

    private function executarSemRegisto(
        NivelAcademico $nivelAcademico,
        NivelAcademicoDTO $dto
    ): NivelAcademico {
        $nivelAcademico->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'etapa_ensino' => $dto->etapa_ensino,
        ]);

        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
