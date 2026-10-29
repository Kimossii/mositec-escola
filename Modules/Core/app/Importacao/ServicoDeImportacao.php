<?php

namespace Modules\Core\Importacao;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Core\Importacao\Jobs\ImportarImportacaoJob;
use Modules\Core\Importacao\Jobs\ValidarImportacaoJob;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\ImportacaoLinha;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

/** Casos de uso do motor: o modelo, carregar o ficheiro, confirmar e cancelar. O trabalho pesado está nos jobs. */
class ServicoDeImportacao
{
    /** Sem fila a sério (sync) o trabalho corre dentro do pedido: só se aceitam importações pequenas. */
    public const MAXIMO_SYNC_BYTES = 2 * 1024 * 1024;

    public const MAXIMO_SYNC_LINHAS = 3000;

    public const MAXIMO_BYTES = 50 * 1024 * 1024;

    public function modelo(ImportadorDeEntidade $importador): TabelaExportavel
    {
        $colunas = [];
        $exemplo = [];
        foreach ($importador->colunas() as $chave => $c) {
            $colunas[$chave] = $c['rotulo'].($c['obrigatoria'] ? '*' : '');
            $exemplo[$chave] = $c['exemplo'];
        }
        $primeira = array_key_first($exemplo);
        $exemplo[$primeira] = '(exemplo) '.$exemplo[$primeira];

        return new TabelaExportavel(
            titulo: 'Modelo de importação — '.$importador->titulo(),
            colunas: $colunas,
            linhas: [$exemplo],
            subtitulo: 'Preencha a partir da linha abaixo do cabeçalho. Os campos com * são obrigatórios. A linha de exemplo é ignorada.',
            entidade: app(TenantContext::class)->atual()->nome,
            assinaturas: [],
        );
    }

    public function iniciar(Authenticatable $utilizador, ImportadorDeEntidade $importador, UploadedFile $ficheiro): Importacao
    {
        if ($this->semFilaReal() && $ficheiro->getSize() > config('importacao.maximo_sync_bytes', self::MAXIMO_SYNC_BYTES)) {
            throw ValidationException::withMessages(['ficheiro' => 'Ficheiros grandes precisam do processamento em segundo plano (worker de filas) activo. Peça ao administrador do sistema ou divida o ficheiro.']);
        }

        $extensao = strtolower($ficheiro->getClientOriginalExtension());
        $caminho = Storage::disk('local')->putFileAs(CaminhoTenant::para('importacoes'), $ficheiro, Str::uuid().'.'.$extensao);

        $importacao = Importacao::create([
            'user_id' => $utilizador->id,
            'entidade' => $importador->chave(),
            'nome_ficheiro' => Str::limit($ficheiro->getClientOriginalName(), 200, ''),
            'caminho' => $caminho,
            'estado' => Importacao::CARREGADA,
        ]);

        dispatch(new ValidarImportacaoJob($importacao->id));

        return $importacao;
    }

    /** Passa de «validada» (ou «falhada» com linhas por importar) a «a importar», uma só vez, e arranca a cadeia de jobs. */
    public function confirmar(Importacao $importacao): void
    {
        $retoma = $importacao->estado === Importacao::FALHADA
            && ImportacaoLinha::where('importacao_id', $importacao->id)->where('estado', ImportacaoLinha::VALIDA)->exists();

        if ($importacao->estado !== Importacao::VALIDADA && ! $retoma) {
            throw ValidationException::withMessages(['importacao' => 'Esta importação já não pode ser confirmada.']);
        }

        if ($importacao->validas === 0 && ! $retoma) {
            throw ValidationException::withMessages(['importacao' => 'Não há linhas válidas para importar.']);
        }

        if ($this->semFilaReal() && $importacao->validas > config('importacao.maximo_sync_linhas', self::MAXIMO_SYNC_LINHAS)) {
            throw ValidationException::withMessages(['importacao' => 'Importações grandes precisam do processamento em segundo plano (worker de filas) activo.']);
        }

        $actualizadas = Importacao::whereKey($importacao->id)->whereIn('estado', [Importacao::VALIDADA, Importacao::FALHADA])
            ->update(['estado' => Importacao::A_IMPORTAR, 'erro_geral' => null, 'concluido_em' => null]);

        if ($actualizadas === 1) {
            dispatch(new ImportarImportacaoJob($importacao->id));
        }
    }

    public function cancelar(Importacao $importacao): void
    {
        if (! in_array($importacao->estado, [Importacao::VALIDADA, Importacao::FALHADA], true)) {
            throw ValidationException::withMessages(['importacao' => 'Só se cancela uma importação ainda não iniciada.']);
        }

        $importacao->update(['estado' => Importacao::CANCELADA, 'concluido_em' => now()]);
    }

    private function semFilaReal(): bool
    {
        return config('queue.default') === 'sync';
    }
}
