<?php

namespace Modules\Aluno\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Services\GeradorMatriculaService;

class CriarAlunoAction
{
    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
    ) {
    }

    public function executar(AlunoDTO $dto, ?UploadedFile $foto = null): Aluno
    {
        return DB::transaction(function () use ($dto, $foto) {
            $dadosPessoa = $dto->dadosPessoaId
                ? DadosPessoa::findOrFail($dto->dadosPessoaId)
                : DadosPessoa::create([
                    'nome_completo' => $dto->nomeCompleto,
                    'email' => $dto->email,
                    'telefone' => $dto->telefone,
                    'telefone_alternativo' => $dto->telefoneAlternativo,
                    'data_nascimento' => $dto->dataNascimento,
                    'sexo' => $dto->sexo,
                    'numero_identificacao' => $dto->numeroIdentificacao,
                    'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
                ]);

            $aluno = Aluno::create([
                'estabelecimento_id' => Estabelecimento::current()?->id,
                'dados_pessoa_id' => $dadosPessoa->id,
                'numero_matricula' => $this->geradorMatricula->gerar(),
            ]);

            if ($foto) {
                $aluno->update(['foto_path' => $foto->store('alunos/fotos', 'public')]);
            }

            return $aluno;
        });
    }
}
