<?php

namespace Modules\Core\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Código QR em SVG (sem extensão GD): nítido em qualquer tamanho e na impressão. */
class GeradorDeQr
{
    public static function svg(string $conteudo, int $tamanho = 160): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($tamanho, 0), new SvgImageBackEnd)))->writeString($conteudo);

        // Sem o prólogo XML, para o SVG poder ir dentro de uma página HTML.
        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg);
    }
}
