<?php

namespace Modules\Core\Http\Controllers\Comunicacao;

use App\Http\Controllers\Controller;
use Modules\Core\Actions\Email\RegistarDominioDeEmailAction;
use Modules\Core\Actions\Email\RemoverDominioDeEmailAction;
use Modules\Core\Actions\Email\VerificarDominioDeEmailAction;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Http\Requests\RegistarDominioEmailRequest;
use Modules\Core\Services\DominioEmailConsultaService;

class DominioEmailController extends Controller
{
    public function __construct(private DominioEmailConsultaService $service) {}

    public function registar(RegistarDominioEmailRequest $request, RegistarDominioDeEmailAction $action)
    {
        $action->executar($request->validated('dominio'), $request->validated('remetente_nome'), (string) ($request->validated('remetente_local') ?? 'noreply'));

        return to_route('configuracao-email.show')->with('success', 'Domínio registado. Crie os registos DNS indicados e depois clique em «Verificar».');
    }

    public function verificar(VerificarDominioDeEmailAction $action)
    {
        $dominio = $action->executar($this->service->actual() ?? abort(404));

        return to_route('configuracao-email.show')->with(
            $dominio->estado === EstadoConfiguracaoEmail::VERIFICADO ? 'success' : 'warning',
            $dominio->estado === EstadoConfiguracaoEmail::VERIFICADO ? 'Domínio verificado. A escola passa a enviar por ele.' : (string) $dominio->ultimo_erro,
        );
    }

    public function remover(RemoverDominioDeEmailAction $action)
    {
        $action->executar($this->service->actual() ?? abort(404));

        return to_route('configuracao-email.show')->with('success', 'A escola voltou a enviar pela central.');
    }
}
