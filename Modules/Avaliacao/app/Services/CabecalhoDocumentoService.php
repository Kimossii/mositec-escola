<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Estabelecimento\Models\Estabelecimento;

/**
 * O cabeçalho oficial e os responsáveis dos documentos, tirados da escola em causa: província, município, nome, logótipo
 * e responsável do estabelecimento, mais as linhas extra, o lema e o visto configurados na avaliação.
 */
class CabecalhoDocumentoService
{
    /** @return array{linhas: list<string>, lema: ?string, logotipo: ?string, responsavel: array{nome: ?string, cargo: ?string}, visto: ?array{nome: ?string, cargo: ?string}} */
    public function dados(): array
    {
        $escola = Estabelecimento::current();
        $config = AvaliacaoDocumentoConfig::query()->first();

        $linhas = array_values(array_filter([
            'REPÚBLICA DE ANGOLA',
            'MINISTÉRIO DA EDUCAÇÃO',
            $this->maiusculas($escola->provincia, 'GOVERNO DA PROVÍNCIA DE '),
            $this->maiusculas($escola->municipio, 'ADMINISTRAÇÃO MUNICIPAL DE '),
            ...array_map(fn (string $l) => mb_strtoupper($l), array_filter(preg_split('/\R/u', (string) $config?->linhas_extra) ?: [])),
            $this->maiusculas($escola->nome),
        ]));

        $visto = $config?->visto_cargo || $config?->visto_nome ? ['nome' => $config->visto_nome, 'cargo' => $config->visto_cargo] : null;

        return [
            'linhas' => $linhas,
            'lema' => $config?->lema,
            'logotipo' => $this->logotipo($escola),
            'responsavel' => ['nome' => $escola->responsavel_nome, 'cargo' => $escola->responsavel_cargo],
            'visto' => $visto,
        ];
    }

    private function maiusculas(?string $texto, string $prefixo = ''): ?string
    {
        return $texto === null || trim($texto) === '' ? null : $prefixo.mb_strtoupper(trim($texto));
    }

    /** O logótipo como data URI (o PDF não vai buscar nada à rede); sem logótipo, ou grande demais, não se imprime. */
    private function logotipo(Estabelecimento $escola): ?string
    {
        $caminho = $escola->logotipo_path;
        $disco = Storage::disk('public');

        if (! $caminho || ! $disco->exists($caminho) || $disco->size($caminho) > 2_000_000) {
            return null;
        }

        $mime = $disco->mimeType($caminho);

        return in_array($mime, ['image/png', 'image/jpeg'], true) ? 'data:'.$mime.';base64,'.base64_encode($disco->get($caminho)) : null;
    }
}
