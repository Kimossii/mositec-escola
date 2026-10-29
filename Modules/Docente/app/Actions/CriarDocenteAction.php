<?php

namespace Modules\Docente\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Docente\DTO\DocenteDTO;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;

/** Cadastra um docente: a pessoa (tipo professor) e o registo de docente, sem conta de acesso. */
class CriarDocenteAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(DocenteDTO $dto): Docente
    {
        return $this->comLog('Criar docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(DocenteDTO $dto): Docente
    {
        return DB::transaction(function () use ($dto) {
            $pessoa = DadosPessoa::create([
                'nome_completo' => $dto->nomeCompleto,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'telefone_alternativo' => $dto->telefoneAlternativo,
                'data_nascimento' => $dto->dataNascimento,
                'sexo' => $dto->sexo,
                'numero_identificacao' => $dto->numeroIdentificacao,
                'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR,
            ]);

            return Docente::create([
                'estabelecimento_id' => Estabelecimento::current()?->id,
                'dados_pessoa_id' => $pessoa->id,
            ]);
        });
    }
}
