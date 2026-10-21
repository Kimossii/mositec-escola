<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Enums\Estado;
use Modules\Core\Services\GeradorSequencia;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoCobranca;
use Modules\Financeiro\Models\PagamentoSequencia;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\RecalcularCobranca;
use Modules\Financeiro\Services\SnapshotMonetario;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;

/**
 * Paga uma cobrança de produtos e serviços pelo saldo todo: regista um Pagamento Confirmado (mesma numeração,
 * método, comprovativo e anulação dos pagamentos de propinas) com uma linha em `pagamento_cobrancas`.
 * O valor, a distribuição e o crédito do DTO não se usam: o valor é sempre o saldo da cobrança.
 */
class PagarCobrancaAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private DistribuicaoDePagamentoService $distribuicao,
        private RecalcularCobranca $recalcular,
        private GeradorSequencia $sequencia,
        private SnapshotMonetario $snapshot,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(Cobranca $cobranca, RegistarPagamentoDTO $dto): Pagamento
    {
        $caminho = $dto->comprovativo?->store(CaminhoTenant::para('financeiro/comprovativos'), 'privado');

        try {
            return DB::transaction(fn () => $this->registar($cobranca, $dto, $caminho));
        } catch (QueryException $e) {
            $this->apagar($caminho);

            if (ViolacaoDeUnicidade::daReferenciaDoPagamento($e)) {
                throw ValidationException::withMessages(['referencia' => RegistarPagamentoAction::MENSAGEM_REFERENCIA]);
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->apagar($caminho);

            throw $e;
        }
    }

    private function apagar(?string $caminho): void
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

    private function registar(Cobranca $cobranca, RegistarPagamentoDTO $dto, ?string $comprovativoCaminho): Pagamento
    {
        $matricula = $this->bloqueio->matricula((int) $cobranca->matricula_id, incluirEliminadas: true)
            ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe.']);
        $bloqueada = Cobranca::query()->whereKey($cobranca->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
            throw ValidationException::withMessages(['cobranca' => 'Só se paga uma cobrança em aberto.']);
        }

        $this->distribuicao->validarData($dto->dataPagamento, $matricula);

        $metodo = MetodoPagamento::query()->find($dto->metodoPagamentoId);

        if ($metodo === null || (int) $metodo->estado !== Estado::ATIVO->value) {
            throw ValidationException::withMessages(['metodo_pagamento_id' => 'Escolha um método de pagamento activo.']);
        }

        if ($dto->referencia !== null && Pagamento::query()
            ->where('metodo_pagamento_id', $metodo->id)->where('referencia', $dto->referencia)
            ->where('estado', EstadoPagamento::CONFIRMADO->value)->exists()) {
            throw ValidationException::withMessages(['referencia' => RegistarPagamentoAction::MENSAGEM_REFERENCIA]);
        }

        $valor = Dinheiro::deUnidadesMenores($bloqueada->saldo());
        $numero = $this->sequencia->gerar(PagamentoSequencia::class, $this->relogio->hoje()->year);

        $pagamento = new Pagamento([
            'matricula_id' => $matricula->id,
            'metodo_pagamento_id' => $metodo->id,
            'valor' => $valor,
            'data_pagamento' => $dto->dataPagamento->toDateString(),
            'referencia' => $dto->referencia,
            'observacao' => $dto->observacao,
        ]);
        $atributos = ['numero' => $numero, 'aluno_id' => $matricula->aluno_id, ...$this->snapshot->em($dto->dataPagamento)];

        if ($comprovativoCaminho !== null) {
            $atributos['comprovativo_path'] = $comprovativoCaminho;
            $atributos['comprovativo_nome_original'] = $dto->comprovativo->getClientOriginalName();
            $atributos['comprovativo_mime_type'] = $dto->comprovativo->getClientMimeType();
        }

        $pagamento->forceFill($atributos)->save();

        PagamentoCobranca::create(['pagamento_id' => $pagamento->id, 'cobranca_id' => $bloqueada->id, 'valor' => $valor]);

        $this->recalcular->executarVarias([(int) $bloqueada->id]);

        return $pagamento->fresh(['cobrancas']);
    }
}
