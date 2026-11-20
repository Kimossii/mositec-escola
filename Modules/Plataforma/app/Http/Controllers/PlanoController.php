<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Modules\Licenciamento\Actions\AtualizarPlanoAction;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDePlanoInvalidos;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\LicencasConsultaService;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\GuardarPlanoRequest;

/**
 * Planos comerciais no painel. Sem regras aqui: o pedido só tem formato, as Actions do Licenciamento validam e gravam,
 * e o sucesso fica na auditoria. Corre sem contexto de tenant (os planos são globais).
 */
class PlanoController extends Controller
{
    use AuditaSemFalhar;

    public function index(LicencasConsultaService $consulta)
    {
        return Inertia::render('Plataforma/Planos/Index', [
            'planos' => $consulta->planos()->map(fn (Plano $plano) => $this->paraPagina($plano))->values(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Plataforma/Planos/Form', ['plano' => null, ...$this->opcoes()]);
    }

    public function store(GuardarPlanoRequest $request, CriarPlanoAction $criar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $plano = $criar->executar($request->paraDto());
        } catch (DadosDePlanoInvalidos $e) {
            return redirect()->route('plataforma.planos.create')->withErrors($e->erros)->withInput();
        }

        $this->auditar($auditoria, $request, 'plano.criado', null, ['plano_id' => $plano->id, 'nome' => $plano->nome]);

        return redirect()->route('plataforma.planos.index')->with('success', 'Plano criado.');
    }

    public function edit(Plano $plano)
    {
        return Inertia::render('Plataforma/Planos/Form', ['plano' => $this->paraPagina($plano), ...$this->opcoes()]);
    }

    public function update(GuardarPlanoRequest $request, Plano $plano, AtualizarPlanoAction $atualizar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $atualizar->executar($plano, $request->paraDto());
        } catch (DadosDePlanoInvalidos $e) {
            return redirect()->route('plataforma.planos.edit', $plano)->withErrors($e->erros)->withInput();
        }

        $this->auditar($auditoria, $request, 'plano.actualizado', null, ['plano_id' => $plano->id, 'nome' => $plano->nome]);

        return redirect()->route('plataforma.planos.index')->with('success', 'Plano actualizado. As licenças já emitidas não mudam.');
    }

    /** @return array<string, mixed> */
    private function opcoes(): array
    {
        return [
            'modulosDisponiveis' => array_values((array) config('licenciamento.modulos_licenciaveis', [])),
            'modalidades' => array_map(fn (Modalidade $m) => ['value' => $m->value, 'label' => $m->label()], Modalidade::cases()),
            'padroes' => [
                'tolerancia_dias' => (int) config('licenciamento.tolerancia_dias_padrao'),
                'aviso_dias' => (int) config('licenciamento.aviso_dias_padrao'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paraPagina(Plano $plano): array
    {
        return [
            'id' => $plano->id,
            'nome' => $plano->nome,
            'descricao' => $plano->descricao,
            'modalidade' => $plano->modalidade->value,
            'modalidade_descricao' => $plano->modalidade_descricao,
            'validade_dias' => $plano->validade_dias,
            'limite_alunos' => $plano->limite_alunos,
            'limite_utilizadores' => $plano->limite_utilizadores,
            'limite_estabelecimentos' => $plano->limite_estabelecimentos,
            'tolerancia_dias' => $plano->tolerancia_dias,
            'aviso_dias' => $plano->aviso_dias,
            'modulos' => $plano->modulos,
        ];
    }
}
