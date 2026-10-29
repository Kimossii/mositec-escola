<?php

namespace Modules\Docente\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;

/** Leitura dos docentes: lista paginada (com «tem acesso» numa só consulta), indicadores e linhas para exportação. */
class DocenteConsultaService
{
    /**
     * @param  array{pesquisa?: ?string, estado?: ?string, acesso?: ?string}  $filtros
     */
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        return $this->consultaFiltrada($filtros)
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Docente $d) => $this->linha($d));
    }

    /**
     * Números para os cartões da lista (sem filtros).
     *
     * @return array{total: int, activos: int, com_acesso: int, sem_acesso: int}
     */
    public function indicadores(): array
    {
        $total = $this->base()->count();
        $comAcesso = $this->base()->whereHas('conta')->count();

        return [
            'total' => $total,
            'activos' => $this->base()->where('estado', 1)->count(),
            'com_acesso' => $comAcesso,
            'sem_acesso' => $total - $comAcesso,
        ];
    }

    /** @param  array<string, mixed>  $filtros  @return list<array<string, scalar|null>> */
    public function paraExportacao(array $filtros, int $limite): array
    {
        return $this->consultaFiltrada($filtros)
            ->limit($limite)
            ->get()
            ->map(fn (Docente $d) => [
                'nome' => (string) $d->dadosPessoa?->nome_completo,
                'bi' => (string) $d->dadosPessoa?->numero_identificacao,
                'telefone' => (string) $d->dadosPessoa?->telefone,
                'email' => (string) $d->dadosPessoa?->email,
                'estado' => $d->estado === 1 ? 'Ativo' : 'Inativo',
                'acesso' => $d->tem_acesso ? 'Sim' : 'Não',
            ])->all();
    }

    private function base(): Builder
    {
        return Docente::query()->where('estabelecimento_id', Estabelecimento::current()?->id);
    }

    /** @param  array<string, mixed>  $filtros */
    private function consultaFiltrada(array $filtros): Builder
    {
        $pesquisa = trim((string) ($filtros['pesquisa'] ?? ''));
        $estado = (string) ($filtros['estado'] ?? '');
        $acesso = (string) ($filtros['acesso'] ?? '');

        return $this->base()
            ->with('dadosPessoa')
            ->withExists('conta as tem_acesso')
            ->when($pesquisa !== '', fn (Builder $q) => $q->whereHas('dadosPessoa', fn ($p) => $p
                ->whereContem('nome_completo', $pesquisa)
                ->orWhereContem('numero_identificacao', $pesquisa)))
            ->when(in_array($estado, ['0', '1'], true), fn (Builder $q) => $q->where('estado', (int) $estado))
            ->when($acesso === 'com', fn (Builder $q) => $q->whereHas('conta'))
            ->when($acesso === 'sem', fn (Builder $q) => $q->whereDoesntHave('conta'))
            ->orderBy(DadosPessoa::query()->select('nome_completo')->whereColumn('dados_pessoas.id', 'docentes.dados_pessoa_id'))
            ->orderBy('docentes.id');
    }

    /** @return array<string, scalar|null> */
    private function linha(Docente $d): array
    {
        return [
            'id' => $d->id,
            'nome' => $d->dadosPessoa?->nome_completo,
            'bi' => $d->dadosPessoa?->numero_identificacao,
            'telefone' => $d->dadosPessoa?->telefone,
            'email' => $d->dadosPessoa?->email,
            'estado' => $d->estado,
            'tem_acesso' => (bool) $d->tem_acesso,
            // Para o formulário de edição.
            'sexo' => (int) ($d->dadosPessoa?->sexo ?? 0),
            'data_nascimento' => $d->dadosPessoa?->data_nascimento?->toDateString(),
            'telefone_alternativo' => $d->dadosPessoa?->telefone_alternativo,
        ];
    }
}
