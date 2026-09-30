<?php

namespace Modules\Aluno\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;

class AtualizarAlunoAction
{
    public function executar(Aluno $aluno, AlunoDTO $dto, ?UploadedFile $foto = null): Aluno
    {
        return DB::transaction(function () use ($aluno, $dto, $foto) {
            $aluno->dadosPessoa->fill([
                'nome_completo' => $dto->nomeCompleto,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'telefone_alternativo' => $dto->telefoneAlternativo,
                'data_nascimento' => $dto->dataNascimento,
                'sexo' => $dto->sexo,
                'numero_identificacao' => $dto->numeroIdentificacao,
            ])->save();

            if ($foto) {
                if ($aluno->foto_path) {
                    Storage::disk('public')->delete($aluno->foto_path);
                }
                $aluno->update(['foto_path' => $foto->store('alunos/fotos', 'public')]);
            }

            return $aluno->fresh();
        });
    }
}
