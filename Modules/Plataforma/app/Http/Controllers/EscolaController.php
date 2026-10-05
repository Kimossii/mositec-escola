<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Requests\CriarEscolaRequest;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Services\AuditoriaConsultaService;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\ProvisionamentoIncompleto;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\TenantConsultaService;
use Throwable;

/**
 * Escolas no painel. Corre sempre sem contexto de tenant: lê só `tenants`, `domains` e a auditoria,
 * e a criação delega na CriarTenantAction (que abre o contexto dentro de si). Sem regras aqui.
 */
class EscolaController extends Controller
{
    public function index(Request $request, TenantConsultaService $consulta)
    {
        // Parâmetros em array (`?estado[]=1`) contam como ausentes, nunca como erro.
        $estado = is_string($request->query('estado')) ? $request->query('estado') : '';
        $pesquisa = is_string($request->query('pesquisa')) ? mb_substr($request->query('pesquisa'), 0, 100) : '';

        $escolas = $consulta->listar($estado, $pesquisa)->through(fn (Tenant $tenant) => [
            'codigo' => $tenant->codigo,
            'nome' => $tenant->nome,
            'estado' => $tenant->estado->value,
            'dominio_principal' => $tenant->dominioPrincipal?->dominio,
            'created_at' => $tenant->created_at,
        ]);

        return Inertia::render('Plataforma/Escolas/Index', [
            'escolas' => $escolas,
            'filtros' => ['estado' => $estado, 'pesquisa' => $pesquisa],
        ]);
    }

    public function show(Tenant $tenant, TenantConsultaService $consulta, AuditoriaConsultaService $auditoria)
    {
        $escola = $consulta->detalhe($tenant);

        return Inertia::render('Plataforma/Escolas/Show', [
            'escola' => [
                'codigo' => $escola->codigo,
                'nome' => $escola->nome,
                'estado' => $escola->estado->value,
                'motivo_suspensao' => $escola->motivo_suspensao,
                'suspenso_em' => $escola->suspenso_em,
                'encerrado_em' => $escola->encerrado_em,
                'created_at' => $escola->created_at,
                'accoes_permitidas' => $consulta->accoesPermitidas($escola),
                'dominios' => $escola->dominios->map(fn ($dominio) => [
                    'dominio' => $dominio->dominio,
                    'tipo_descricao' => $dominio->tipo_descricao,
                    'is_principal' => $dominio->is_principal,
                    // O principal nunca sai e, numa escola encerrada, nenhum domínio sai (regras da RemoverDominioAction).
                    'removivel' => ! $dominio->is_principal && $consulta->accoesPermitidas($escola)['gerir_dominios'],
                ])->values(),
            ],
            'auditoria' => $auditoria->recentesDaEscola($escola->codigo)->map(fn (RegistoDeAuditoria $registo) => [
                'id' => $registo->id,
                'accao' => $registo->accao,
                'autor' => $registo->autor?->name,
                'detalhe' => $registo->detalhe,
                'ip' => $registo->ip,
                'created_at' => $registo->created_at,
            ])->values(),
        ]);
    }

    public function nova()
    {
        return Inertia::render('Plataforma/Escolas/Nova');
    }

    public function store(CriarEscolaRequest $request, CriarTenantAction $criar, RegistarAuditoriaAction $auditoria)
    {
        $dados = $request->validated();

        try {
            $criada = $criar->executar(new CriarTenantDTO(
                nomeEstabelecimento: $dados['nome'],
                nomeAdministrador: $dados['admin_nome'],
                emailAdministrador: $dados['admin_email'],
                dominioPrincipal: $dados['dominio'],
                codigo: $dados['codigo'] ?? null,
            ));
        } catch (DadosDeTenantInvalidos $e) {
            // Os erros da Action (por campo) vão para o formulário; o input volta, não há nada sensível nele.
            return redirect()->route('plataforma.escolas.nova')->withErrors($e->erros)->withInput();
        } catch (ProvisionamentoIncompleto $e) {
            return redirect()->route('plataforma.escolas.nova')->withErrors(['geral' => $e->getMessage()])->withInput();
        }

        try {
            // Só código e domínio: a senha nunca vai para a auditoria.
            $auditoria->executar($request->user('plataforma'), 'escola.criada', $criada->tenant->codigo, [
                'dominio' => $criada->dominio->dominio,
            ]);
        } catch (Throwable $e) {
            // A escola já existe: uma falha da auditoria não pode fazer perder a senha temporária,
            // que só se mostra agora. Fica registada nos logs para ser vista.
            report($e);
        }

        $resposta = redirect()->route('plataforma.escolas.show', $criada->tenant->codigo)
            ->with('success', 'Escola criada com sucesso.');

        if ($criada->credencial !== null) {
            // Mesmo formato do Plano 3b. Cifrada: o payload da sessão nunca guarda a senha em claro.
            $resposta->with('senha_temporaria', [
                'codigo' => $criada->tenant->codigo,
                'email' => $criada->credencial->email,
                'senha' => Crypt::encryptString($criada->credencial->senha()),
            ]);
        }

        return $resposta;
    }
}
