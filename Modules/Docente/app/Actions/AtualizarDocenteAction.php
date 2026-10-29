<?php

namespace Modules\Docente\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Docente\DTO\DocenteDTO;
use Modules\Docente\Models\Docente;

class AtualizarDocenteAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Docente $docente, DocenteDTO $dto): Docente
    {
        return $this->comLog('Atualizar docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($docente, $dto));
    }

    private function executarSemRegisto(Docente $docente, DocenteDTO $dto): Docente
    {
        return DB::transaction(function () use ($docente, $dto) {
            $docente->dadosPessoa->fill([
                'nome_completo' => $dto->nomeCompleto,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'telefone_alternativo' => $dto->telefoneAlternativo,
                'data_nascimento' => $dto->dataNascimento,
                'sexo' => $dto->sexo,
                'numero_identificacao' => $dto->numeroIdentificacao,
            ])->save();

            $docente->touch();

            return $docente->fresh(['dadosPessoa']);
        });
    }
}
