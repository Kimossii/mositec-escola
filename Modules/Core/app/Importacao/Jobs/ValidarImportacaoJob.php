<?php

namespace Modules\Core\Importacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Core\Importacao\LeitorDeFicheiro;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\ImportacaoLinha;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Tenancy\Jobs\UnicoPorTenant;
use Modules\Core\Traits\LogService;
use RuntimeException;
use Throwable;

/**
 * Lê o ficheiro em fluxo, guarda cada linha na tabela intermédia e marca-a como válida, com erro ou ignorada (já existe).
 * Não escreve nas tabelas finais. Idempotente: recomeça sempre do zero.
 */
class ValidarImportacaoJob implements ShouldBeUnique, ShouldQueue
{
    use ComTenant;
    use Dispatchable;
    use InteractsWithQueue;
    use LogService;
    use Queueable;
    use UnicoPorTenant;

    public const MAXIMO_LINHAS = 200000;

    private const BLOCO = 1000;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $importacaoId)
    {
    }

    public function identificadorUnico(): string
    {
        return "validar-{$this->importacaoId}";
    }

    public function handle(LeitorDeFicheiro $leitor, RegistoDeImportadores $registo): void
    {
        try {
            $this->validar($leitor, $registo);
        } catch (RuntimeException $e) {
            // Ficheiro inutilizável (formato, cabeçalho, tamanho): é um resultado para o utilizador, não um erro do sistema.
            $this->falhar($e->getMessage());
        }
    }

    private function validar(LeitorDeFicheiro $leitor, RegistoDeImportadores $registo): void
    {
        $importacao = Importacao::findOrFail($this->importacaoId);
        $importador = $registo->obter($importacao->entidade);
        $importacao->update(['estado' => Importacao::A_VALIDAR, 'erro_geral' => null]);
        ImportacaoLinha::where('importacao_id', $importacao->id)->delete();

        $caminho = Storage::disk('local')->path($importacao->caminho);
        $contagem = ['total' => 0, 'validas' => 0, 'ignoradas' => 0, 'com_erro' => 0];
        $vistos = [];
        $bloco = [];
        $mapa = null;

        foreach ($leitor->linhas($caminho, pathinfo($importacao->nome_ficheiro, PATHINFO_EXTENSION)) as $numero => $celulas) {
            if ($mapa === null) {
                // O cabeçalho pode ter título por cima (modelo do sistema): procura nas primeiras 15 linhas.
                $mapa = $numero <= 15 ? $importador->mapearCabecalho($celulas) : throw new RuntimeException('Não encontrei o cabeçalho do modelo. Use o modelo descarregado do sistema.');

                continue;
            }

            if ($this->vazia($celulas) || str_starts_with((string) ($celulas[array_key_first($mapa)] ?? ''), '(exemplo)')) {
                continue;
            }

            $dados = [];
            foreach ($mapa as $indice => $chave) {
                $dados[$chave] = $celulas[$indice] ?? null;
            }

            $bloco[$numero] = $importador->normalizar($dados);
            $contagem['total']++;

            if ($contagem['total'] > self::MAXIMO_LINHAS) {
                throw new RuntimeException('O ficheiro tem mais de '.self::MAXIMO_LINHAS.' linhas. Divida-o em partes.');
            }

            if (count($bloco) >= self::BLOCO) {
                $this->gravarBloco($importacao, $importador, $bloco, $vistos, $contagem);
                $bloco = [];
            }
        }

        if ($mapa === null) {
            throw new RuntimeException('Não encontrei o cabeçalho do modelo. Use o modelo descarregado do sistema.');
        }

        if ($bloco !== []) {
            $this->gravarBloco($importacao, $importador, $bloco, $vistos, $contagem);
        }

        if ($contagem['total'] === 0) {
            throw new RuntimeException('O ficheiro não tem linhas de dados.');
        }

        $importacao->update($contagem + ['estado' => Importacao::VALIDADA]);
        $this->logInfo('Importação validada', ['importacao' => $importacao->id, 'entidade' => $importacao->entidade] + $contagem);
    }

    public function failed(Throwable $excecao): void
    {
        report($excecao);
        $this->falhar('Não foi possível ler o ficheiro. Confirme o modelo e tente de novo.');
    }

    private function falhar(string $mensagem): void
    {
        Importacao::whereKey($this->importacaoId)->update(['estado' => Importacao::FALHADA, 'erro_geral' => mb_substr($mensagem, 0, 250), 'concluido_em' => now()]);
    }

    /** @param list<?string> $celulas */
    private function vazia(array $celulas): bool
    {
        return count(array_filter($celulas, fn ($c) => $c !== null)) === 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bloco  número da linha → dados
     * @param  array<string, array<string, int>>  $vistos  coluna → valor → primeira linha em que apareceu (todo o ficheiro)
     * @param  array<string, int>  $contagem
     */
    private function gravarBloco(Importacao $importacao, ImportadorDeEntidade $importador, array $bloco, array &$vistos, array &$contagem): void
    {
        $colunaChave = $importador->colunaChave();
        $existentes = [$colunaChave => array_flip($importador->existentes($colunaChave, $this->valores($bloco, $colunaChave)))];
        foreach ($importador->colunasUnicas() as $coluna) {
            $existentes[$coluna] = array_flip($importador->existentes($coluna, $this->valores($bloco, $coluna)));
        }

        $registos = [];
        foreach ($bloco as $numero => $dados) {
            $erros = [];
            $validador = Validator::make($dados, $importador->regras(), $importador->mensagens(), array_map(fn ($c) => $c['rotulo'], $importador->colunas()));

            if ($validador->fails()) {
                $erros = $validador->errors()->all();
            }

            $estado = ImportacaoLinha::VALIDA;
            $valorChave = $dados[$colunaChave] ?? null;

            if ($erros === [] && $valorChave !== null && isset($existentes[$colunaChave][(string) $valorChave])) {
                $estado = ImportacaoLinha::IGNORADA;
                $erros[] = 'Já existe: não foi importado.';
            }

            if ($estado === ImportacaoLinha::VALIDA && $erros === []) {
                foreach ([$colunaChave, ...$importador->colunasUnicas()] as $coluna) {
                    $valor = $dados[$coluna] ?? null;
                    if ($valor === null) {
                        continue;
                    }
                    $rotulo = $importador->colunas()[$coluna]['rotulo'] ?? $coluna;

                    if (isset($vistos[$coluna][(string) $valor])) {
                        $erros[] = "{$rotulo} «{$valor}» repetido: já aparece na linha {$vistos[$coluna][(string) $valor]}.";
                    } elseif ($coluna !== $colunaChave && isset($existentes[$coluna][(string) $valor])) {
                        $erros[] = "Já existe um registo com {$rotulo} «{$valor}».";
                    }
                }
            }

            if ($erros !== [] && $estado === ImportacaoLinha::VALIDA) {
                $estado = ImportacaoLinha::ERRO;
            }

            // Só linhas que vão entrar reservam o valor (uma linha com erro não impede a seguinte, igual, de entrar).
            if ($estado === ImportacaoLinha::VALIDA) {
                foreach ([$colunaChave, ...$importador->colunasUnicas()] as $coluna) {
                    if (($dados[$coluna] ?? null) !== null) {
                        $vistos[$coluna][(string) $dados[$coluna]] = $numero;
                    }
                }
            }

            $contagem[match ($estado) {
                ImportacaoLinha::VALIDA => 'validas',
                ImportacaoLinha::IGNORADA => 'ignoradas',
                default => 'com_erro',
            }]++;

            $registos[] = [
                'importacao_id' => $importacao->id,
                'linha' => $numero,
                'dados' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'estado' => $estado,
                'erros' => $erros === [] ? null : json_encode($erros, JSON_UNESCAPED_UNICODE),
            ];
        }

        DB::table('importacao_linhas')->insert($registos);
        $importacao->update(['total' => $contagem['total']]);
    }

    /** @return list<string> */
    private function valores(array $bloco, string $coluna): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($d) => isset($d[$coluna]) ? (string) $d[$coluna] : null, $bloco))));
    }
}
