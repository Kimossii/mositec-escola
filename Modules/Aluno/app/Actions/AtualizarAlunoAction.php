<?php

namespace Modules\Aluno\Actions;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\CaminhoTenant;

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
                    $this->apagarFotoAnterior($aluno->foto_path);
                }
                $aluno->update(['foto_path' => $foto->store(CaminhoTenant::para('alunos/fotos'), 'privado')]);
            }

            return $aluno->fresh();
        });
    }

    /** Só apaga se o caminho guardado pertencer mesmo ao tenant corrente. */
    private function apagarFotoAnterior(string $caminho): void
    {
        try {
            Storage::disk('privado')->delete(CaminhoTenant::garantir($caminho));
        } catch (InvalidArgumentException) {
            // Caminho fora do tenant ou adulterado: não se apaga nada.
        }
    }
}
