<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Frequencia\Models\FrequenciaDefinicoes;

/** Define a janela de correcção do professor (1 a 60 dias). Uma só linha por escola. */
class GuardarDefinicoesAction
{
    use LogService;

    public function executar(int $janelaDias): FrequenciaDefinicoes
    {
        return $this->comLog('Guardar definições da frequência', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($janelaDias));
    }

    private function executarSemRegisto(int $janelaDias): FrequenciaDefinicoes
    {
        if ($janelaDias < 1 || $janelaDias > 60) {
            throw ValidationException::withMessages(['janela_correccao_dias' => 'A janela de correcção tem de ter entre 1 e 60 dias.']);
        }

        $definicoes = FrequenciaDefinicoes::query()->first() ?? new FrequenciaDefinicoes();
        $definicoes->janela_correccao_dias = $janelaDias;
        $definicoes->save();

        return $definicoes;
    }
}
