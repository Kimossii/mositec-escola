<?php

namespace Modules\Financeiro\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\TaxaCambio;
use Throwable;

/**
 * Regista o câmbio da plataforma (1 USD = taxa unidades da moeda) para uma data. Uma linha por
 * dia por moeda: repetir o dia actualiza a linha. No futuro, uma API agendada chama o mesmo caminho.
 */
class CambioPlataformaCommand extends Command
{
    private const FONTES = ['padrao', 'manual', 'api'];

    protected $signature = 'financeiro:cambio-plataforma {moeda : Código ISO da moeda (ex.: AOA)} {taxa : 1 USD = taxa unidades da moeda (ex.: 910,50)} {--data= : AAAA-MM-DD (por omissão, hoje)} {--fonte=manual : padrao, manual ou api}';

    protected $description = 'Regista o câmbio da plataforma (1 USD = X unidades da moeda) para um dia';

    public function handle(): int
    {
        $moeda = strtoupper(trim((string) $this->argument('moeda')));

        if (! Moeda::existe($moeda) || $moeda === CambioDoDia::REFERENCIA) {
            $this->error("Moeda inválida: {$moeda}. Use um código do registo, diferente de USD.");

            return self::FAILURE;
        }

        try {
            $taxa = TaxaCambio::deDecimal((string) $this->argument('taxa'));
        } catch (InvalidArgumentException) {
            $this->error('Taxa inválida: use um número maior que zero, com até 6 casas decimais.');

            return self::FAILURE;
        }

        $fonte = (string) $this->option('fonte');

        if (! in_array($fonte, self::FONTES, true)) {
            $this->error('Fonte inválida: use padrao, manual ou api.');

            return self::FAILURE;
        }

        $data = $this->option('data') !== null && $this->option('data') !== ''
            ? (string) $this->option('data')
            : now()->toDateString();

        if (! $this->dataValida($data)) {
            $this->error('Data inválida: use o formato AAAA-MM-DD.');

            return self::FAILURE;
        }

        CambioPlataforma::updateOrCreate(
            ['moeda_cotada' => $moeda, 'moeda_base' => CambioDoDia::REFERENCIA, 'data' => $data],
            ['taxa' => $taxa->micros(), 'fonte' => $fonte],
        );

        $this->info("Câmbio registado: 1 USD = {$taxa->formatar()} {$moeda} em {$data}.");

        return self::SUCCESS;
    }

    private function dataValida(string $data): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $data)->toDateString() === $data;
        } catch (Throwable) {
            return false;
        }
    }
}
