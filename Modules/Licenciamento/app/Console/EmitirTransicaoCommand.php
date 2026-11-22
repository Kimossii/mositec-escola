<?php

namespace Modules\Licenciamento\Console;

use Illuminate\Console\Command;
use Modules\Licenciamento\Actions\EmitirLicencasDeTransicaoAction;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Services\EmissorDeLicencas;

/** Recolhe as opções e chama EmitirLicencasDeTransicaoAction: licença online a cada escola sem licença válida. Sem lógica de negócio. */
class EmitirTransicaoCommand extends Command
{
    protected $signature = 'licenciamento:emitir-transicao
        {--plano= : Id do plano a usar}
        {--dias= : Dias de validade das licenças}
        {--escola=* : Código(s) da escola; sem isto, todas as que não têm licença válida}
        {--dry-run : Só lista, não emite}';

    protected $description = 'Emite uma licença online às escolas sem licença válida (transição para o licenciamento)';

    public function handle(EmitirLicencasDeTransicaoAction $acao, EmissorDeLicencas $emissor): int
    {
        $plano = $this->option('plano');
        $dias = $this->option('dias');
        if (! ctype_digit((string) $plano) || ! ctype_digit((string) $dias)) {
            $this->error('Indique --plano=ID e --dias=N (números inteiros).');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $codigos = array_values(array_filter((array) $this->option('escola'), fn ($c) => trim((string) $c) !== ''));

        try {
            $resultados = $acao->executar((int) $plano, (int) $dias, $codigos === [] ? null : $codigos, $simular);
        } catch (EmissorIndisponivel) {
            $this->error('Este servidor não pode emitir licenças: ' . ($emissor->motivoIndisponivel() ?? 'emissor indisponível') . ' Nada foi emitido.');

            return self::FAILURE;
        } catch (DadosDeLicencaInvalidos $e) {
            foreach ($e->erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        if ($resultados === []) {
            $this->info('Nenhuma escola sem licença válida' . ($codigos === [] ? '.' : ' entre as indicadas.'));

            return self::SUCCESS;
        }

        $this->table(['Escola', 'Nome', 'Resultado', 'Erro'], array_map(fn (array $r) => [$r['codigo'], $r['nome'], $simular ? 'seria emitida' : $r['resultado'], $r['erro'] ?? ''], $resultados));

        $falhas = count(array_filter($resultados, fn (array $r) => $r['resultado'] === 'falhou'));
        if ($falhas > 0) {
            $this->error("{$falhas} licença(s) não foram emitidas.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
