<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Support\NiveisAcademicosPadrao;

/**
 * Quando a escola acrescenta uma etapa de ensino, recebe as classes padrão dessa etapa. Idempotente e prudente: uma classe
 * que a escola já tenha (com esse código ou esse nome) não se duplica, e uma que a escola retirou antes volta a activar-se
 * (o código e o nome são únicos, mesmo nas retiradas).
 */
class CriarNiveisPadraoDaEtapaAction
{
    use LogService;

    /** @return int quantas classes ficaram criadas ou reactivadas */
    public function executar(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa): int
    {
        return $this->comLog('Criar classes padrão da etapa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($estabelecimento, $etapa));
    }

    private function executarSemRegisto(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa): int
    {
        return DB::transaction(function () use ($estabelecimento, $etapa) {
            $feitas = 0;

            foreach (NiveisAcademicosPadrao::daEtapa($etapa) as $padrao) {
                $porCodigo = NivelAcademico::withTrashed()->where('estabelecimento_id', $estabelecimento->id)->where('codigo', $padrao['codigo'])->first();

                if ($porCodigo !== null) {
                    if ($porCodigo->trashed()) {
                        $porCodigo->restore();
                        $porCodigo->update(['etapa_ensino' => $etapa, 'estado' => 1]);
                        $feitas++;
                    }

                    continue;
                }

                // Uma classe da escola com o mesmo nome (código diferente) já cobre esta: não se duplica.
                if (NivelAcademico::withTrashed()->where('estabelecimento_id', $estabelecimento->id)->where('nome', $padrao['nome'])->exists()) {
                    continue;
                }

                NivelAcademico::create([
                    'estabelecimento_id' => $estabelecimento->id,
                    'codigo' => $padrao['codigo'],
                    'nome' => $padrao['nome'],
                    'ordem' => $padrao['ordem'],
                    'etapa_ensino' => $etapa,
                ]);
                $feitas++;
            }

            return $feitas;
        });
    }
}
