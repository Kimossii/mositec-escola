<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Enums\Estado;
use Modules\Core\Services\GeradorSequencia;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\PagamentoSequencia;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Services\SnapshotMonetario;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\RegrasDoPagamento;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

/**
 * Regista um pagamento (spec Pagamentos §4). UMA transacção, sem try/catch lá dentro:
 *   1. bloquear pela ordem global (D13): matrícula → propinas pagáveis (id crescente) → créditos do aluno (só se usar);
 *   2. validar a data (nem futura nem anterior à matrícula bloqueada, O3), o método activo e a referência; calcular a divisão sobre o estado bloqueado (o mesmo motor da pré-visualização);
 *   3. número (último bloqueio: só depois de tudo validado, um rollback devolve-o);
 *   4. criar pagamento, distribuições, utilizações e o crédito do excedente;
 *   5. RecalcularPropina (todas as tocadas) e RecalcularCredito (os usados).
 * A violação do índice da referência (corrida entre sessões) converte-se em 422 FORA da transacção.
 */
class RegistarPagamentoAction
{
    use LogService;

    public const MENSAGEM_REFERENCIA = 'Já existe um pagamento confirmado com esta referência neste método de pagamento.';

    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private DistribuicaoDePagamentoService $distribuicao,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private GeradorSequencia $sequencia,
        private SnapshotMonetario $snapshot,
        private RelogioDoTenant $relogio,
        private GerarPropinasService $geracao,
        private AplicarMultasService $multas,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, RegistarPagamentoDTO $dto): Pagamento
    {
        return $this->comLog('Registar pagamento', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $dto));
    }

    private function executarSemRegisto(Matricula $matricula, RegistarPagamentoDTO $dto): Pagamento
    {
        // O ficheiro grava-se no disco privado ANTES da transacção; se o registo falhar, apaga-se (sem órfãos).
        $caminho = $dto->comprovativo?->store(CaminhoTenant::para('financeiro/comprovativos'), 'privado');

        try {
            return DB::transaction(fn () => $this->registar($matricula, $dto, $caminho));
        } catch (QueryException $e) {
            $this->apagarComprovativo($caminho);

            if (ViolacaoDeUnicidade::daReferenciaDoPagamento($e)) {
                throw ValidationException::withMessages(['referencia' => self::MENSAGEM_REFERENCIA]);
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->apagarComprovativo($caminho);

            throw $e;
        }
    }

    private function apagarComprovativo(?string $caminho): void
    {
        if ($caminho === null) {
            return;
        }

        try {
            Storage::disk('privado')->delete(CaminhoTenant::garantir($caminho));
        } catch (InvalidArgumentException) {
            // Caminho fora do tenant ou adulterado: não se apaga nada.
        }
    }

    private function registar(Matricula $matricula, RegistarPagamentoDTO $dto, ?string $comprovativoCaminho): Pagamento
    {
        // 1. Bloqueios (D13). A matrícula primeiro: a geração e a alteração de preço também começam por ela.
        $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey())
            ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe ou foi eliminada.']);

        // O3: nem futura nem anterior à matrícula, com a data_matricula RELIDA sob o lock (a do pedido pode estar obsoleta).
        $this->distribuicao->validarData($dto->dataPagamento, $bloqueada);

        // O6/decision: «Pagar até» um mês futuro é recusado sem «pagar antecipado».
        $this->distribuicao->validarAntecipado($dto, $bloqueada);

        // 2. «Pagar até [mês]»: gera as propinas em falta (idempotente, da mais antiga para a mais recente, ao preço
        //    actual do plano), na MESMA transacção — se o pagamento falhar, as propinas geradas revertem com ele.
        if ($dto->ate_ano !== null) {
            $this->geracao->gerarParaMatriculaNaTransaccao($bloqueada, new PedidoGeracaoPropinasDTO(
                ano_lectivo_id: (int) $bloqueada->ano_lectivo_id,
                ambito: AmbitoGeracaoPropinas::MATRICULA,
                origem: OrigemGeracaoPropina::MANUAL,
                matricula_numero: $bloqueada->numero_registo_matricula,
                ate_ano: $dto->ate_ano,
                ate_mes: $dto->ate_mes,
            ));
        }

        // 3. Multa automática no pagamento (só actua com multa_activa ligado), ainda antes de calcular a distribuição.
        $this->multas->executarParaMatricula((int) $bloqueada->id);

        // 4. Em Aberto e Parcialmente Paga todas; Paga só as que têm multa pendente (valor > valor_pago). Com «Pagar
        //    até [mês]» ficam de fora as propinas de meses posteriores.
        $ids = Propina::query()
            ->where('matricula_id', $bloqueada->id)
            ->where(function (Builder $q) {
                $q->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
                    ->orWhere(function (Builder $sub) {
                        $sub->where('estado', EstadoCobranca::PAGA->value)
                            ->whereHas('multa', fn (Builder $m) => $m
                                ->where('estado', '!=', EstadoMulta::ANULADA->value)
                                ->whereColumn('propina_multas.valor', '>', 'propina_multas.valor_pago'));
                    });
            })
            ->when($dto->ate_ano !== null, fn (Builder $q) => $q->where('periodo_inicio', '<=', sprintf('%04d-%02d-01', $dto->ate_ano, $dto->ate_mes)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $propinas = $this->bloqueioDePropinas->bloquear($ids); // relidas: o calcular volta a filtrar o estado

        $creditos = new Collection();

        if ($dto->valorCredito->unidadesMenores() > 0) {
            $creditos = $this->bloqueio->creditos(
                Credito::query()->where('aluno_id', $bloqueada->aluno_id)->where('estado', EstadoCredito::DISPONIVEL->value)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        // 5. Validações de negócio sobre o estado bloqueado.
        $metodo = MetodoPagamento::query()->find($dto->metodoPagamentoId);

        if ($metodo === null || (int) $metodo->estado !== Estado::ATIVO->value) {
            throw ValidationException::withMessages(['metodo_pagamento_id' => 'Escolha um método de pagamento activo.']);
        }

        if ($dto->referencia !== null && Pagamento::query()
            ->where('metodo_pagamento_id', $metodo->id)
            ->where('referencia', $dto->referencia)
            ->where('estado', EstadoPagamento::CONFIRMADO->value)
            ->exists()) {
            throw ValidationException::withMessages(['referencia' => self::MENSAGEM_REFERENCIA]);
        }

        $plano = $this->distribuicao->calcular(
            $dto->valor->unidadesMenores(),
            $dto->valorCredito->unidadesMenores(),
            $dto->dataPagamento,
            // «Pagar até [mês]» é sempre automático: as propinas a gerar ainda não têm id para uma distribuição explícita.
            $dto->ate_ano !== null ? null : $dto->distribuicao,
            $propinas,
            $creditos,
            RegrasDoPagamento::paraMatricula((int) $bloqueada->id),
        );

        // 6. Número: último bloqueio (a linha da sequência fica presa até ao commit; um rollback devolve-o).
        $numero = $this->sequencia->gerar(PagamentoSequencia::class, $this->relogio->hoje()->year);

        // 7. Escritas (só criações).
        $pagamento = new Pagamento([
            'matricula_id' => $bloqueada->id,
            'metodo_pagamento_id' => $metodo->id,
            'valor' => $dto->valor,
            'data_pagamento' => $dto->dataPagamento->toDateString(),
            'referencia' => $dto->referencia,
            'observacao' => $dto->observacao,
        ]);

        $atributos = ['numero' => $numero, 'aluno_id' => $bloqueada->aluno_id, ...$this->snapshot->em($dto->dataPagamento)];

        if ($comprovativoCaminho !== null) {
            $atributos['comprovativo_path'] = $comprovativoCaminho;
            $atributos['comprovativo_nome_original'] = $dto->comprovativo->getClientOriginalName();
            $atributos['comprovativo_mime_type'] = $dto->comprovativo->getClientMimeType();
        }

        $pagamento->forceFill($atributos)->save();

        foreach ($plano->distribuicoes() as $distribuicao) {
            PagamentoPropina::create([
                'pagamento_id' => $pagamento->id,
                'propina_id' => $distribuicao['propina_id'],
                'valor' => Dinheiro::deUnidadesMenores($distribuicao['valor']),
            ]);
        }

        foreach ($plano->distribuicoesMulta() as $distribuicao) {
            PagamentoMulta::create([
                'pagamento_id' => $pagamento->id,
                'propina_multa_id' => $distribuicao['propina_multa_id'],
                'valor' => Dinheiro::deUnidadesMenores($distribuicao['valor']),
            ]);
        }

        $hoje = $this->relogio->hoje()->toDateString();

        foreach ($plano->utilizacoes as $utilizacao) {
            CreditoUtilizacao::create([
                'credito_id' => $utilizacao['credito_id'],
                'propina_id' => $utilizacao['propina_id'],
                'propina_multa_id' => $utilizacao['propina_multa_id'],
                'valor' => Dinheiro::deUnidadesMenores($utilizacao['valor']),
                'data_utilizacao' => $hoje,
                'pagamento_id' => $pagamento->id, // O8: rastreia o acto em que foi combinada
            ]);
        }

        if ($plano->excedente > 0) {
            Credito::create([
                'aluno_id' => $bloqueada->aluno_id,
                'origem_pagamento_id' => $pagamento->id,
                'valor' => Dinheiro::deUnidadesMenores($plano->excedente),
            ]);
        }

        // 8. Caches (pontos únicos).
        $this->recalcularPropina->executarVarias($plano->propinasTocadas());

        foreach ($creditos->whereIn('id', $plano->creditosUsados()) as $credito) {
            $this->recalcularCredito->executar($credito);
        }

        return $pagamento->fresh(['distribuicoes', 'credito']);
    }
}
