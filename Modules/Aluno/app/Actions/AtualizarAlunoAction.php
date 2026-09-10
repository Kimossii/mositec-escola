<?php

namespace Modules\Aluno\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;

class AtualizarAlunoAction
{
    public function executar(Aluno $aluno, AlunoDTO $dto): Aluno
    {
        return DB::transaction(function () use ($aluno, $dto) {
            $aluno->dadosPessoa->fill([
                'nome_completo' => $dto->nomeCompleto,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'data_nascimento' => $dto->dataNascimento,
                'sexo' => $dto->sexo,
            ])->save();

            return $aluno->fresh();
        });
    }
}
