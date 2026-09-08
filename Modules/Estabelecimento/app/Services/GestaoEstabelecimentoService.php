<?php

namespace Modules\Estabelecimento\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Actions\AtualizarDadosEstabelecimentoAction;
use Modules\Estabelecimento\Actions\AtualizarLogotipoEstabelecimentoAction;
use Modules\Estabelecimento\DTO\EstabelecimentoDTO;
use Modules\Estabelecimento\Http\Requests\AtualizarDadosRequest;
use Modules\Estabelecimento\Models\Estabelecimento;

class GestaoEstabelecimentoService
{
    public function __construct(
        private AtualizarDadosEstabelecimentoAction $atualizarDadosAction,
        private AtualizarLogotipoEstabelecimentoAction $atualizarLogotipoAction,
    ) {
    }

    public function obterAtual(): ?Estabelecimento
    {
        return Estabelecimento::current();
    }

    public function atualizarDados(AtualizarDadosRequest $request): Estabelecimento
    {
        $dto = EstabelecimentoDTO::fromRequest($request);

        return $this->atualizarDadosAction->executar($dto);
    }

    public function atualizarLogotipo(UploadedFile $logotipo): Estabelecimento
    {
        $estabelecimento = Estabelecimento::current();

        if (!$estabelecimento) {
            throw ValidationException::withMessages([
                'estabelecimento' => 'É necessário cadastrar os dados do estabelecimento antes de definir o logótipo.',
            ]);
        }

        return $this->atualizarLogotipoAction->executar($estabelecimento, $logotipo);
    }
}
