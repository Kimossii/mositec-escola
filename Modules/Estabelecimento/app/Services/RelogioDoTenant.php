<?php

namespace Modules\Estabelecimento\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;

/**
 * O relógio de negócio da escola. Vencimentos, atrasos, pagamentos e câmbios usam o "hoje" do fuso
 * horário do tenant (Dados da Escola), não o de UTC. A zona da aplicação e os timestamps guardados
 * (created_at, etc.) não mudam: só o dia civil de negócio é calculado aqui.
 *
 * O fuso lê-se uma vez por pedido (memória do TenantContext) e a gravação do estabelecimento invalida-o.
 * Sem tenant resolvido (comandos de plataforma) usa o fuso por omissão. Respeita Carbon::setTestNow.
 */
class RelogioDoTenant
{
    public const CHAVE_FUSO = 'estabelecimento.fuso_horario';

    public function __construct(private TenantContext $contexto)
    {
    }

    public function fuso(): string
    {
        if (! $this->contexto->temTenant()) {
            return Estabelecimento::FUSO_HORARIO_PADRAO;
        }

        return $this->contexto->lembrar(
            self::CHAVE_FUSO,
            fn () => Estabelecimento::query()->value('fuso_horario') ?: Estabelecimento::FUSO_HORARIO_PADRAO,
        );
    }

    /** O dia civil de agora na zona da escola, às 00:00 (comparável com datas Y-m-d). */
    public function hoje(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->agora()->toDateString());
    }

    /** O "hoje" no fuso por omissão, para código de plataforma que corre sem tenant (comandos). */
    public function hojeNoFusoPadrao(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::now()->setTimezone(Estabelecimento::FUSO_HORARIO_PADRAO)->toDateString());
    }

    /** O instante actual, com a zona da escola aplicada. */
    public function agora(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone($this->fuso());
    }
}
