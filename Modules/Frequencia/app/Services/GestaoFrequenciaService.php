<?php

namespace Modules\Frequencia\Services;

use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\GuardarDefinicoesAction;
use Modules\Frequencia\Actions\JustificarFaltaAction;
use Modules\Frequencia\Actions\MarcarNaoLeccionadaAction;
use Modules\Frequencia\Actions\ReabrirAulaAction;
use Modules\Frequencia\Actions\RegistarPresencasAction;
use Modules\Frequencia\Actions\RemoverJustificacaoAction;
use Modules\Frequencia\DTO\PresencaDTO;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\FrequenciaDefinicoes;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Models\Registo;
use Modules\Usuario\Models\User;

/** Escrita da frequência: traduz os dados validados do pedido e delega nas Actions. */
class GestaoFrequenciaService
{
    public function __construct(
        private AbrirChamadaAction $abrir,
        private RegistarPresencasAction $registar,
        private MarcarNaoLeccionadaAction $naoLeccionada,
        private ReabrirAulaAction $reabrir,
        private JustificarFaltaAction $justificar,
        private RemoverJustificacaoAction $removerJustificacao,
        private GuardarDefinicoesAction $definicoes,
    ) {
    }

    /** @param array{turma_id: int|string, data: string, horario_id?: int|string|null} $dados */
    public function abrir(User $utilizador, array $dados): Aula
    {
        return $this->abrir->executar($utilizador, (int) $dados['turma_id'], $dados['data'], isset($dados['horario_id']) ? (int) $dados['horario_id'] : null);
    }

    /** @param array{presencas: list<array{matricula_id: int|string, estado: int|string, observacao?: ?string}>, motivo?: ?string} $dados */
    public function registar(User $utilizador, Aula $aula, array $dados): Aula
    {
        $presencas = array_map(
            fn (array $p) => new PresencaDTO((int) $p['matricula_id'], (int) $p['estado'], $p['observacao'] ?? null),
            $dados['presencas'],
        );

        return $this->registar->executar($utilizador, $aula, $presencas, $dados['motivo'] ?? null);
    }

    public function naoLeccionada(User $utilizador, Aula $aula, string $motivo): Aula
    {
        return $this->naoLeccionada->executar($utilizador, $aula, $motivo);
    }

    public function reabrir(User $utilizador, Aula $aula): Aula
    {
        return $this->reabrir->executar($utilizador, $aula);
    }

    /** @param array{motivo: string, documento_referencia?: ?string} $dados */
    public function justificar(User $utilizador, Registo $registo, array $dados): Justificacao
    {
        return $this->justificar->executar($utilizador, $registo, $dados['motivo'], $dados['documento_referencia'] ?? null);
    }

    public function removerJustificacao(User $utilizador, Registo $registo, string $motivo): void
    {
        $this->removerJustificacao->executar($utilizador, $registo, $motivo);
    }

    public function guardarDefinicoes(int $janelaDias): FrequenciaDefinicoes
    {
        return $this->definicoes->executar($janelaDias);
    }
}
