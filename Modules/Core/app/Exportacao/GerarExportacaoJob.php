<?php

namespace Modules\Core\Exportacao;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\PedidoExportacao;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Throwable;

/** Gera em fila o ficheiro de um PedidoExportacao a partir da tabela guardada no pedido. Uma só tentativa: o utilizador pede de novo. */
class GerarExportacaoJob implements ShouldQueue
{
    use ComTenant;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $pedidoId)
    {
    }

    /** Pasta (relativa ao tenant) dos pedidos ainda por gerar. */
    public const PENDENTES = 'exportacoes/pendentes';

    public static function origem(int $pedidoId): string
    {
        return CaminhoTenant::para(self::PENDENTES."/{$pedidoId}.json");
    }

    public function handle(Exportador $exportador): void
    {
        $pedido = PedidoExportacao::find($this->pedidoId);

        if ($pedido === null || $pedido->estado !== PedidoExportacao::PENDENTE) {
            return;
        }

        $disco = Storage::disk('local');
        $d = json_decode($disco->get(self::origem($pedido->id)), true, 512, JSON_THROW_ON_ERROR);
        $formato = FormatoExportacao::from($pedido->formato);
        $temporario = $exportador->gerarFicheiro(
            new TabelaExportavel($d['titulo'], $d['colunas'], $d['linhas'], $d['subtitulo'], $d['entidade'], $d['assinaturas']),
            $formato,
            Str::slug($pedido->titulo).'-'.date('Y-m-d'),
        );

        try {
            $relativo = "exportacoes/{$pedido->id}.{$formato->extensao()}";
            $destino = CaminhoTenant::para($relativo);
            $disco->put(CaminhoTenant::para($relativo), fopen($temporario, 'rb'));
        } finally {
            @unlink($temporario);
        }

        $pedido->update(['estado' => PedidoExportacao::PRONTO, 'caminho' => $destino, 'tamanho' => $disco->size($destino), 'concluido_em' => now()]);
        $disco->delete(self::origem($pedido->id));
        $this->podarAntigos($pedido);
    }

    /** Cada utilizador guarda os últimos MAXIMO_GUARDADOS ficheiros; os mais antigos saem (ficheiro e pedido). */
    private function podarAntigos(PedidoExportacao $pedido): void
    {
        PedidoExportacao::query()
            ->where('user_id', $pedido->user_id)
            ->latest('id')
            ->skip(PedidoExportacao::MAXIMO_GUARDADOS)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (PedidoExportacao $antigo) {
                if ($antigo->caminho) {
                    Storage::disk('local')->delete($antigo->caminho);
                }
                $antigo->delete();
            });
    }

    public function failed(Throwable $excecao): void
    {
        report($excecao);
        PedidoExportacao::whereKey($this->pedidoId)->update(['estado' => PedidoExportacao::ERRO, 'erro' => 'Não foi possível gerar o ficheiro. Peça a exportação de novo.', 'concluido_em' => now()]);
        Storage::disk('local')->delete(self::origem($this->pedidoId));
    }
}
