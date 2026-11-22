<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Modules\Licenciamento\Actions\CancelarLicencaAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\Actions\EstenderToleranciaAction;
use Modules\Licenciamento\Actions\ReactivarLicencaAction;
use Modules\Licenciamento\Actions\RenovarLicencaAction;
use Modules\Licenciamento\Actions\SuspenderLicencaAction;
use Modules\Licenciamento\DTOs\RenovarLicencaDTO;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Exceptions\LicencaRecusada;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\EmissorDeLicencas;
use Modules\Licenciamento\Services\LicencasConsultaService;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\EmitirLicencaRequest;
use Modules\Plataforma\Http\Requests\EstenderLicencaRequest;
use Modules\Plataforma\Http\Requests\MotivoDeLicencaRequest;
use Modules\Plataforma\Services\AuditoriaConsultaService;
use Modules\Plataforma\Support\ApresentadorDeLicencas;

/**
 * Licenças emitidas no painel. Cada pedido chama a Action do Licenciamento e audita o sucesso; as recusas mostram a
 * mensagem da Action. O token só sai na página de detalhe e na descarga, e NUNCA entra na auditoria. Sem contexto de tenant.
 */
class LicencaController extends Controller
{
    use AuditaSemFalhar;

    public const EMISSOR_INDISPONIVEL = 'Este servidor não tem chave de emissão configurada: só a instalação central da MosiTec emite licenças. Pode consultar e gerir (suspender, cancelar) as já emitidas.';

    public function index(LicencasConsultaService $consulta, ApresentadorDeLicencas $apresentador, EmissorDeLicencas $emissor)
    {
        $nomes = $consulta->nomesDosPlanos();

        return Inertia::render('Plataforma/Licencas/Index', [
            'licencas' => $consulta->listar()->through(fn (LicencaEmitida $l) => $apresentador->resumo($l, $nomes)),
            'proximas_expiracoes' => $consulta->proximasExpiracoes()->map(fn (LicencaEmitida $l) => $apresentador->resumo($l, $nomes))->values(),
            'emissor' => $this->estadoDoEmissor($emissor),
        ]);
    }

    public function emitir(LicencasConsultaService $consulta, EmissorDeLicencas $emissor)
    {
        return Inertia::render('Plataforma/Licencas/Emitir', [
            'emissor' => $this->estadoDoEmissor($emissor),
            'planos' => $consulta->planos()->map(fn (Plano $plano) => [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'modalidade' => $plano->modalidade->value,
                'validade_dias' => $plano->validade_dias,
                'limite_alunos' => $plano->limite_alunos,
                'limite_utilizadores' => $plano->limite_utilizadores,
                'tolerancia_dias' => $plano->tolerancia_dias,
                'aviso_dias' => $plano->aviso_dias,
                'modulos' => $plano->modulos,
            ])->values(),
            'escolas' => $consulta->escolasDestaInstalacao(),
            'modalidades' => array_map(fn (Modalidade $m) => ['value' => $m->value, 'label' => $m->label()], Modalidade::cases()),
            'modulosDisponiveis' => array_values((array) config('licenciamento.modulos_licenciaveis', [])),
            'padroes' => [
                'tolerancia_dias' => (int) config('licenciamento.tolerancia_dias_padrao'),
                'aviso_dias' => (int) config('licenciamento.aviso_dias_padrao'),
            ],
        ]);
    }

    public function store(EmitirLicencaRequest $request, EmissorDeLicencas $emissor, EmitirLicencaAction $emitir, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        if (! $emissor->disponivel()) {
            return $this->voltarAoFormulario()->withErrors(['geral' => self::EMISSOR_INDISPONIVEL])->withInput();
        }

        try {
            $licenca = $emitir->executar($request->paraDto(), $request->user('plataforma')->id);
        } catch (EmissorIndisponivel) {
            return $this->voltarAoFormulario()->withErrors(['geral' => self::EMISSOR_INDISPONIVEL])->withInput();
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->voltarAoFormulario()->withErrors($e->erros)->withInput();
        } catch (LicencaRecusada $e) {
            return $this->voltarAoFormulario()->withErrors(['geral' => $e->getMessage()])->withInput();
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.emitida', $licenca, [
            'plano_id' => $licenca->plano_id,
            'escola' => $licenca->escola,
            'instalacao' => $licenca->instalacao,
            'modalidade' => $licenca->modalidade->slug(),
            'expira_em' => $licenca->expira_em->toIso8601String(),
        ]);

        return $this->irParaLicenca($licenca)->with('success', 'Licença emitida.');
    }

    public function show(LicencaEmitida $licenca, LicencasConsultaService $consulta, ApresentadorDeLicencas $apresentador, EmissorDeLicencas $emissor, AuditoriaConsultaService $auditoria)
    {
        $eventos = $licenca->eventos()->get();
        $autores = $auditoria->nomesDosAutores($eventos->pluck('super_admin_id'));
        $emiteNovas = $emissor->disponivel();
        $accoes = $consulta->accoesPermitidas($licenca);

        return Inertia::render('Plataforma/Licencas/Show', [
            'licenca' => [
                ...$apresentador->resumo($licenca, $consulta->nomesDosPlanos()),
                // O token é para o Super Admin copiar/descarregar; só vai nesta página.
                'token' => $licenca->token,
            ],
            'eventos' => $eventos->map(fn (EventoDeLicenca $evento) => [
                'id' => $evento->id,
                'tipo' => $evento->tipo,
                'motivo' => $evento->motivo,
                'autor' => $autores[$evento->super_admin_id] ?? null,
                'detalhe' => $evento->detalhe,
                'created_at' => $evento->created_at,
            ])->values(),
            'accoes_permitidas' => [
                ...$accoes,
                'renovar' => $accoes['renovar'] && $emiteNovas,
                'estender' => $accoes['estender'] && $emiteNovas,
            ],
            'emissor' => $this->estadoDoEmissor($emissor),
        ]);
    }

    /** O token como ficheiro de texto. Nunca em cache, e o nome não revela mais do que a escola/instalação e o dia da emissão. */
    public function descarregar(LicencaEmitida $licenca): Response
    {
        $alvo = Str::slug((string) ($licenca->escola ?? $licenca->instalacao ?? 'licenca'));
        $nome = "licenca-{$alvo}-{$licenca->emitida_em->format('Ymd')}.licenca";

        return response($licenca->token, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$nome}",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function renovar(MotivoDeLicencaRequest $request, LicencaEmitida $licenca, EmissorDeLicencas $emissor, RenovarLicencaAction $renovar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        if (! $emissor->disponivel()) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => self::EMISSOR_INDISPONIVEL]);
        }

        try {
            $nova = $renovar->executar($licenca, new RenovarLicencaDTO, $request->validated('motivo'), $request->user('plataforma')->id);
        } catch (EmissorIndisponivel) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => self::EMISSOR_INDISPONIVEL]);
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->irParaLicenca($licenca)->withErrors($e->erros);
        } catch (LicencaRecusada $e) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => $e->getMessage()]);
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.renovada', $nova, [
            'motivo' => $request->validated('motivo'),
            'licenca_anterior_id' => $licenca->id,
            'nova_licenca_id' => $nova->id,
            'expira_em_anterior' => $licenca->expira_em->toIso8601String(),
            'expira_em_nova' => $nova->expira_em->toIso8601String(),
        ]);

        return $this->irParaLicenca($nova)->with('success', 'Licença renovada. Foi emitida uma licença nova, que substitui a anterior.');
    }

    public function estender(EstenderLicencaRequest $request, LicencaEmitida $licenca, EmissorDeLicencas $emissor, EstenderToleranciaAction $estender, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        if (! $emissor->disponivel()) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => self::EMISSOR_INDISPONIVEL]);
        }

        $anterior = $licenca->tolerancia_dias;
        $nova = (int) $request->validated('tolerancia_dias');

        try {
            $emitida = $estender->executar($licenca, $nova, $request->validated('motivo'), $request->user('plataforma')->id);
        } catch (EmissorIndisponivel) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => self::EMISSOR_INDISPONIVEL]);
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->irParaLicenca($licenca)->withErrors($e->erros);
        } catch (LicencaRecusada $e) {
            return $this->irParaLicenca($licenca)->withErrors(['geral' => $e->getMessage()]);
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.estendida', $emitida, [
            'motivo' => $request->validated('motivo'),
            'licenca_anterior_id' => $licenca->id,
            'nova_licenca_id' => $emitida->id,
            'tolerancia_anterior' => $anterior,
            'tolerancia_nova' => $nova,
        ]);

        return $this->irParaLicenca($emitida)->with('success', 'Tolerância estendida. Foi emitida uma licença nova, que substitui a anterior.');
    }

    public function suspender(MotivoDeLicencaRequest $request, LicencaEmitida $licenca, SuspenderLicencaAction $suspender, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $suspender->executar($licenca, $request->validated('motivo'), $request->user('plataforma')->id);
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->irParaLicenca($licenca)->withErrors($e->erros);
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.suspensa', $licenca, ['motivo' => $request->validated('motivo')]);

        return $this->irParaLicenca($licenca)->with('success', 'Licença suspensa.');
    }

    public function reactivar(MotivoDeLicencaRequest $request, LicencaEmitida $licenca, ReactivarLicencaAction $reactivar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $reactivar->executar($licenca, $request->validated('motivo'), $request->user('plataforma')->id);
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->irParaLicenca($licenca)->withErrors($e->erros);
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.reactivada', $licenca, ['motivo' => $request->validated('motivo')]);

        return $this->irParaLicenca($licenca)->with('success', 'Licença reactivada.');
    }

    public function cancelar(MotivoDeLicencaRequest $request, LicencaEmitida $licenca, CancelarLicencaAction $cancelar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $cancelar->executar($licenca, $request->validated('motivo'), $request->user('plataforma')->id);
        } catch (DadosDeLicencaInvalidos $e) {
            return $this->irParaLicenca($licenca)->withErrors($e->erros);
        }

        $this->auditarLicenca($auditoria, $request, 'licenca.cancelada', $licenca, ['motivo' => $request->validated('motivo')]);

        return $this->irParaLicenca($licenca)->with('success', 'Licença cancelada.');
    }

    /**
     * Auditoria de uma licença: leva sempre o id (nunca o token); a escola só entra como `codigo_tenant`
     * quando é uma escola desta instalação (as licenças de instalações externas não são de nenhum tenant daqui).
     *
     * @param  array<string, mixed>  $detalhe
     */
    private function auditarLicenca(RegistarAuditoriaAction $auditoria, Request $request, string $accao, LicencaEmitida $licenca, array $detalhe): void
    {
        $this->auditar($auditoria, $request, $accao, $licenca->tenant_local_id !== null ? $licenca->escola : null, ['licenca_id' => $licenca->id, ...$detalhe]);
    }

    /** @return array{disponivel: bool, mensagem: string} */
    private function estadoDoEmissor(EmissorDeLicencas $emissor): array
    {
        return ['disponivel' => $emissor->disponivel(), 'mensagem' => self::EMISSOR_INDISPONIVEL, 'motivo' => $emissor->motivoIndisponivel()];
    }

    private function voltarAoFormulario(): RedirectResponse
    {
        return redirect()->route('plataforma.licencas.emitir');
    }

    private function irParaLicenca(LicencaEmitida $licenca): RedirectResponse
    {
        return redirect()->route('plataforma.licencas.show', $licenca);
    }
}
