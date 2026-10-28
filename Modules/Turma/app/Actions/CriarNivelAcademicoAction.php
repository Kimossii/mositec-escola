<?php

namespace Modules\Turma\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;
use Modules\Core\Traits\LogService;

class CriarNivelAcademicoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(NivelAcademicoDTO $dto): NivelAcademico
    {
        return $this->comLog('Criar nível académico', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(NivelAcademicoDTO $dto): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'etapa_ensino' => $dto->etapa_ensino,
        ]);
    }
}
