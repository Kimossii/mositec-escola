<?php

namespace Modules\Core\Http\Controllers\Comunicacao;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Actions\Email\DesactivarConfiguracaoEmailAction;
use Modules\Core\Actions\Email\GuardarConfiguracaoEmailAction;
use Modules\Core\Actions\Email\TestarConfiguracaoEmailAction;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Http\Requests\GuardarConfiguracaoEmailRequest;
use Modules\Core\Http\Requests\TestarConfiguracaoEmailRequest;
use Modules\Core\Services\ConfiguracaoEmailConsultaService;

class ConfiguracaoEmailController extends Controller
{
    public function __construct(private ConfiguracaoEmailConsultaService $service)
    {
    }

    public function show()
    {
        return Inertia::render('Core/ConfiguracaoEmail/Index', ['configuracao' => $this->service->paraEcra()]);
    }

    public function guardar(GuardarConfiguracaoEmailRequest $request, GuardarConfiguracaoEmailAction $action)
    {
        $action->executar($request->validated());

        return to_route('configuracao-email.show')->with('success', 'Configuração guardada. Falta testar o envio para a activar.');
    }

    public function testar(TestarConfiguracaoEmailRequest $request, TestarConfiguracaoEmailAction $action)
    {
        $configuracao = $this->service->actual() ?? abort(404);

        $configuracao = $action->executar($configuracao, $request->validated('destino'));

        return to_route('configuracao-email.show')->with(
            $configuracao->estado === EstadoConfiguracaoEmail::VERIFICADO ? 'success' : 'warning',
            $configuracao->estado === EstadoConfiguracaoEmail::VERIFICADO
                ? 'Email de teste enviado. A escola passa a enviar por este servidor.'
                : 'O envio de teste falhou: '.$configuracao->ultimo_erro,
        );
    }

    public function desactivar(DesactivarConfiguracaoEmailAction $action)
    {
        $action->executar($this->service->actual() ?? abort(404));

        return to_route('configuracao-email.show')->with('success', 'A escola voltou a enviar pela central.');
    }
}
