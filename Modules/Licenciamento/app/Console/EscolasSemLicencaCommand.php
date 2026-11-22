<?php

namespace Modules\Licenciamento\Console;

use Illuminate\Console\Command;
use Modules\Licenciamento\Services\EscolasSemLicenca;

/** Verificação prévia (só leitura) à activação: lista as escolas que ficariam bloqueadas. Saída 0 se nenhuma, 1 caso contrário. */
class EscolasSemLicencaCommand extends Command
{
    protected $signature = 'licenciamento:escolas-sem-licenca';

    protected $description = 'Lista as escolas que ficariam bloqueadas se o licenciamento estivesse activo (só leitura)';

    public function handle(EscolasSemLicenca $escolas): int
    {
        $lista = $escolas->listar();

        if ($lista === []) {
            $this->info('Todas as escolas têm licença válida: é seguro activar o licenciamento.');

            return self::SUCCESS;
        }

        $this->table(['Escola', 'Nome', 'Motivo'], array_map(fn (array $e) => [$e['tenant']->codigo, $e['tenant']->nome, $e['motivo']], $lista));
        $this->error(count($lista) . ' escola(s) ficariam bloqueadas.');

        return self::FAILURE;
    }
}
