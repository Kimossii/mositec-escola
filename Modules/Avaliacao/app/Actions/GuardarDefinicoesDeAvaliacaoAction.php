<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\DefinicoesDeAvaliacaoDTO;
use Modules\Avaliacao\Models\AvaliacaoDefinicoes;
use Modules\Core\Traits\LogService;

/** Guarda as definições da avaliação que valem para toda a escola. O mínimo com nota não pode passar do máximo. */
class GuardarDefinicoesDeAvaliacaoAction
{
    use LogService;

    public function executar(DefinicoesDeAvaliacaoDTO $dto): AvaliacaoDefinicoes
    {
        return $this->comLog('Guardar definições da avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            if ($dto->min_avaliacoes > $dto->max_avaliacoes) {
                throw ValidationException::withMessages(['min_avaliacoes' => 'O mínimo esperado de avaliações não pode ser maior que o máximo.']);
            }

            $campos = ['max_avaliacoes' => $dto->max_avaliacoes, 'min_avaliacoes' => $dto->min_avaliacoes];
            $definicoes = AvaliacaoDefinicoes::query()->first();

            if ($definicoes === null) {
                return AvaliacaoDefinicoes::create($campos);
            }

            $definicoes->update($campos);

            return $definicoes;
        });
    }
}
