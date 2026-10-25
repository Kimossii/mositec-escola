<?php

namespace Modules\Aluno\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Services\GeradorMatriculaService;
use Modules\Core\Traits\LogService;

class CriarAlunoAction
{
    use LogService;

    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AlunoDTO $dto, ?UploadedFile $foto = null): Aluno
    {
        return $this->comLog('Criar aluno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto, $foto));
    }

    private function executarSemRegisto(AlunoDTO $dto, ?UploadedFile $foto = null): Aluno
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
                    'provincia_nascimento' => $dto->provinciaNascimento,
                    'municipio_nascimento' => $dto->municipioNascimento,
                    'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
                ]);

            $aluno = Aluno::create([
                'estabelecimento_id' => Estabelecimento::current()?->id,
                'dados_pessoa_id' => $dadosPessoa->id,
                'numero_matricula' => $this->geradorMatricula->gerar(),
            ]);

            if ($foto) {
                $aluno->update(['foto_path' => $foto->store(CaminhoTenant::para('alunos/fotos'), 'privado')]);
            }

            return $aluno;
        });
    }
}
