<?php

namespace Modules\Estabelecimento\Services;

use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Modules\Estabelecimento\Actions\AtualizarDadosEstabelecimentoAction;
use Modules\Estabelecimento\Actions\AtualizarLogotipoEstabelecimentoAction;
use Modules\Estabelecimento\DTO\EstabelecimentoDTO;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Http\Requests\AtualizarDadosRequest;
use Modules\Estabelecimento\Models\Estabelecimento;

class GestaoEstabelecimentoService
{
    public function __construct(
        private AtualizarDadosEstabelecimentoAction $atualizarDadosAction,
        private AtualizarLogotipoEstabelecimentoAction $atualizarLogotipoAction,
    ) {
    }

    public function obterAtual(): Estabelecimento
    {
        return Estabelecimento::current();
    }

    public function etapasEnsinoConfiguradas(): array
    {
        return Estabelecimento::current()->etapasEnsino()
            ->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
    }

    /**
     * Identificadores de fuso horário para o select, com o fuso por omissão no topo.
     *
     * @return list<string>
     */
    public function fusosHorarios(): array
    {
        $padrao = Estabelecimento::FUSO_HORARIO_PADRAO;

        return [$padrao, ...array_values(array_diff(DateTimeZone::listIdentifiers(), [$padrao]))];
    }

    public function atualizarDados(AtualizarDadosRequest $request): Estabelecimento
    {
        $dto = EstabelecimentoDTO::fromRequest($request);

        return $this->atualizarDadosAction->executar($dto);
    }

    public function atualizarLogotipo(UploadedFile $logotipo): Estabelecimento
    {
        return $this->atualizarLogotipoAction->executar(Estabelecimento::current(), $logotipo);
    }
}
