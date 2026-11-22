<?php

namespace Modules\Licenciamento\Actions;

use Modules\Core\Traits\LogService;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Exceptions\DadosDePlanoInvalidos;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\ValidaDireitosComerciais;

class CriarPlanoAction
{
    use LogService;

    public function __construct(private readonly ValidaDireitosComerciais $validador) {}

    public function executar(PlanoDTO $dto): Plano
    {
        return $this->comLog('Criar plano de licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    /** @throws DadosDePlanoInvalidos */
    private function executarSemRegisto(PlanoDTO $dto): Plano
    {
        $plano = new Plano;
        AtualizarPlanoAction::preencher($plano, $dto, $this->validador);
        $plano->save();

        return $plano;
    }
}
