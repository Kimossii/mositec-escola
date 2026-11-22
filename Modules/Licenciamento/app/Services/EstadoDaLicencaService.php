<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Enums\ModoDeOperacao;

/**
 * Leitura do estado da licença para o ecrã do administrador. Devolve só dados seguros para mostrar: nunca o token
 * nem outros claims. Funciona também em modo BLOQUEADO (a prop partilhada `licenca` só existe em AVISO).
 */
class EstadoDaLicencaService
{
    /**
     * Exportações do administrador que continuam disponíveis com a licença bloqueada (são rotas de `rotas_permitidas_ao_admin`).
     * Só as que se descarregam com o formato e sem escolher mais nada; as de turma (pautas, notas) pedem a turma e ficam nos ecrãs próprios.
     * `modulo`: alias licenciável de que dependem (null = base).
     */
    private const EXPORTACOES = [
        ['rota' => 'usuario.exportar', 'nome' => 'Utilizadores', 'modulo' => null, 'parametros' => ['formato' => 'excel']],
        ['rota' => 'docentes.exportar', 'nome' => 'Docentes', 'modulo' => null, 'parametros' => ['formato' => 'excel']],
        ['rota' => 'atribuicoes-docentes.exportar', 'nome' => 'Atribuições de docentes', 'modulo' => null, 'parametros' => ['formato' => 'excel']],
        ['rota' => 'financeiro.dividas.exportar', 'nome' => 'Dívidas', 'modulo' => 'financeiro', 'parametros' => ['formato' => 'excel']],
        ['rota' => 'financeiro.relatorios.recebimentos', 'nome' => 'Recebimentos', 'modulo' => 'financeiro', 'parametros' => ['formato' => 'excel']],
    ];

    public function __construct(
        private readonly DireitosService $direitos,
        private readonly LimitesDeLicenca $limites,
        private readonly CodigoDaInstalacao $instalacao,
        private readonly TenantContext $contexto,
    ) {}

    /**
     * @return array{
     *     modo: string, tem_licenca: bool, revogada: bool, codigo_instalacao: string, escola: string|null,
     *     modalidade: string|null, inicio: string|null, expira_em: string|null, bloqueia_em: string|null,
     *     dias_restantes: int|null, tolerancia_dias: int|null,
     *     limites: list<array{recurso: string, nome: string, limite: int|null, utilizacao: int}>,
     *     modulos: list<array{alias: string, nome: string}>,
     *     exportacoes: list<array{nome: string, url: string}>,
     *     ficheiros_url: string|null
     * }
     */
    public function obter(): array
    {
        $direitos = $this->direitos->doTenantActual();
        $agora = CarbonImmutable::now('UTC');

        return [
            'modo' => $this->direitos->modo()->value,
            'tem_licenca' => $direitos !== null,
            'revogada' => $this->direitos->revogadaDoTenantActual(),
            'codigo_instalacao' => $this->instalacao->obter(),
            'escola' => $this->contexto->temTenant() ? $this->contexto->atual()->codigo : null,
            'modalidade' => $direitos?->modalidade->label(),
            'inicio' => $direitos?->inicio->toIso8601String(),
            'expira_em' => $direitos?->expiraEm->toIso8601String(),
            'bloqueia_em' => $direitos?->bloqueiaEm()->toIso8601String(),
            'dias_restantes' => $direitos === null ? null : max(0, (int) floor($agora->diffInDays($direitos->expiraEm, false))),
            'tolerancia_dias' => $direitos?->toleranciaDias,
            'limites' => array_map(fn (Recurso $recurso) => [
                'recurso' => $recurso->value,
                'nome' => $recurso === Recurso::ALUNOS ? 'Alunos' : 'Utilizadores',
                'limite' => $direitos?->limiteDe($recurso),
                'utilizacao' => $this->limites->utilizacao($recurso),
            ], Recurso::cases()),
            'exportacoes' => $this->exportacoes(),
            'ficheiros_url' => Route::has('ficheiros.index') ? route('ficheiros.index') : null,
            'modulos' => array_map(fn (string $alias) => [
                'alias' => $alias,
                'nome' => (string) config("licenciamento.modulos_nomes.{$alias}", Str::headline($alias)),
            ], $direitos?->modulos ?? []),
        ];
    }

    /**
     * Ligações prontas das exportações permitidas, construídas a partir dos nomes de rota: uma rota renomeada/removida
     * simplesmente não aparece (nunca uma ligação morta). As de módulo só aparecem se o módulo está contratado ou a licença está bloqueada
     * (nesse caso a excepção do administrador deixa-as passar).
     *
     * @return list<array{nome: string, url: string}>
     */
    private function exportacoes(): array
    {
        $bloqueado = $this->direitos->modo() === ModoDeOperacao::BLOQUEADO;
        $contratados = $this->direitos->modulosContratados();
        $permitidas = (array) config('licenciamento.rotas_permitidas_ao_admin', []);
        $lista = [];

        foreach (self::EXPORTACOES as $exportacao) {
            if (! Route::has($exportacao['rota']) || ! Str::is($permitidas, $exportacao['rota'])) {
                continue;
            }
            if ($exportacao['modulo'] !== null && ! $bloqueado && ! in_array($exportacao['modulo'], $contratados, true)) {
                continue;
            }
            $lista[] = ['nome' => $exportacao['nome'], 'url' => route($exportacao['rota'], $exportacao['parametros'])];
        }

        return $lista;
    }
}
