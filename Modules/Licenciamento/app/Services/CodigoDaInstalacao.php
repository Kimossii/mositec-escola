<?php

namespace Modules\Licenciamento\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Licenciamento\Models\Instalacao;

/** Código desta instalação: gerado e guardado na 1.ª chamada; imutável depois disso. */
class CodigoDaInstalacao
{
    public function obter(): string
    {
        $existente = Instalacao::query()->orderBy('id')->value('codigo');

        if (is_string($existente)) {
            return $existente;
        }

        try {
            return Instalacao::query()->create(['codigo' => 'INST-'.strtoupper(Str::random(16))])->codigo;
        } catch (QueryException) {
            // Corrida: outro pedido criou o código entre a leitura e a escrita.
            return (string) Instalacao::query()->orderBy('id')->value('codigo');
        }
    }
}
