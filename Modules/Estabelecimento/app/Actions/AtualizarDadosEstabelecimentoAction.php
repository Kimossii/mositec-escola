<?php

namespace Modules\Estabelecimento\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\DTO\EstabelecimentoDTO;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Modules\Turma\Models\NivelAcademico;

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
                'tipo_ensino' => $dto->tipo_ensino,
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

            $this->sincronizarEtapasEnsino($estabelecimento, $dto->etapas_ensino);

            return $estabelecimento->fresh();
        });
    }

    /**
     * @param  EtapaEnsinoEnum[]  $etapasAlvo
     */
    private function sincronizarEtapasEnsino(Estabelecimento $estabelecimento, array $etapasAlvo): void
    {
        $valoresAlvo = array_map(fn (EtapaEnsinoEnum $etapa) => $etapa->value, $etapasAlvo);

        $etapasActuais = $estabelecimento->etapasEnsino()->pluck('etapa_ensino');
        $etapasRemovidas = $etapasActuais->filter(fn (EtapaEnsinoEnum $etapa) => !in_array($etapa->value, $valoresAlvo, true));

        foreach ($etapasRemovidas as $etapaRemovida) {
            $temNiveis = NivelAcademico::where('estabelecimento_id', $estabelecimento->id)
                ->where('etapa_ensino', $etapaRemovida)
                ->exists();

            if ($temNiveis) {
                throw ValidationException::withMessages([
                    'etapas_ensino' => "Não é possível remover a etapa \"{$etapaRemovida->label()}\" porque já existem níveis académicos associados a ela.",
                ]);
            }
        }

        $estabelecimento->etapasEnsino()->whereNotIn('etapa_ensino', $valoresAlvo)->delete();

        foreach ($etapasAlvo as $etapa) {
            EstabelecimentoEtapaEnsino::firstOrCreate([
                'estabelecimento_id' => $estabelecimento->id,
                'etapa_ensino' => $etapa->value,
            ]);
        }
    }
}
