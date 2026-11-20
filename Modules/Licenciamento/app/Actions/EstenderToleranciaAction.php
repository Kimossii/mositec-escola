<?php

namespace Modules\Licenciamento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Services\CicloDeVidaDaLicenca;

/**
 * Extensão de cortesia: emite uma licença NOVA, igual em direitos e validade, só com `tolerancia_dias` maior. Nunca é
 * automática (o avaliador não alarga a tolerância; a regra anti-"mais 30 dias" só admite uma licença nova do emissor),
 * fica no histórico com motivo, autor e as tolerâncias antiga/nova. A anterior passa a `substituida`.
 * Só a partir de uma licença activa. Precisa da chave.
 */
class EstenderToleranciaAction
{
    use LogService;

    public function __construct(
        private readonly CicloDeVidaDaLicenca $ciclo,
        private readonly EmitirLicencaAction $emitir,
    ) {}

    /**
     * @throws EmissorIndisponivel
     * @throws DadosDeLicencaInvalidos
     */
    public function executar(LicencaEmitida $licenca, int $novaToleranciaDias, string $motivo, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Estender tolerância da licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($licenca, $novaToleranciaDias, $motivo, $autorId));
    }

    private function executarSemRegisto(LicencaEmitida $licenca, int $novaToleranciaDias, string $motivo, ?int $autorId): LicencaEmitida
    {
        $motivo = $this->ciclo->motivo($motivo);

        return DB::transaction(function () use ($licenca, $novaToleranciaDias, $motivo, $autorId) {
            $actual = $this->ciclo->bloquear($licenca);
            $this->ciclo->exigirEstado($actual, [EstadoComercial::ACTIVA], 'estender');

            if ($novaToleranciaDias <= $actual->tolerancia_dias) {
                throw new DadosDeLicencaInvalidos(['tolerancia_dias' => "A nova tolerância tem de ser superior à actual ({$actual->tolerancia_dias} dias)."]);
            }

            if ($novaToleranciaDias > DireitosDaLicenca::MAX_DIAS) {
                throw new DadosDeLicencaInvalidos(['tolerancia_dias' => 'A tolerância não pode exceder ' . DireitosDaLicenca::MAX_DIAS . ' dias.']);
            }

            $nova = $this->emitir->executar($this->ciclo->dtoDe($actual, ['toleranciaDias' => $novaToleranciaDias]), $autorId);

            $actual->forceFill(['estado_comercial' => EstadoComercial::SUBSTITUIDA])->save();
            $this->ciclo->registar($actual, 'estendida', $motivo, $autorId, [
                'tolerancia_anterior' => $actual->tolerancia_dias,
                'tolerancia_nova' => $novaToleranciaDias,
                'nova_licenca_id' => $nova->id,
            ]);

            $licenca->setRawAttributes($actual->getAttributes(), true);

            return $nova;
        });
    }
}
