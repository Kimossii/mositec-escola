<?php

namespace Modules\Estabelecimento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Estabelecimento\DTO\EstabelecimentoDTO;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarDadosEstabelecimentoAction
{
    public function executar(EstabelecimentoDTO $dto): Estabelecimento
    {
        return DB::transaction(function () use ($dto) {
            $estabelecimento = Estabelecimento::current() ?? new Estabelecimento(['is_active' => true]);

            $estabelecimento->fill([
                'nome' => $dto->nome,
                'nome_abreviado' => $dto->nome_abreviado,
                'tipo' => $dto->tipo,
                'nif' => $dto->nif,
                'codigo_mined' => $dto->codigo_mined,
                'numero_alvara' => $dto->numero_alvara,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'telefone_alternativo' => $dto->telefone_alternativo,
                'website' => $dto->website,
                'endereco' => $dto->endereco,
                'caixa_postal' => $dto->caixa_postal,
                'municipio' => $dto->municipio,
                'provincia' => $dto->provincia,
                'responsavel_nome' => $dto->responsavel_nome,
                'responsavel_cargo' => $dto->responsavel_cargo,
                'ano_fundacao' => $dto->ano_fundacao,
                'observacoes' => $dto->observacoes,
            ]);

            $estabelecimento->save();

            return $estabelecimento->fresh();
        });
    }
}
