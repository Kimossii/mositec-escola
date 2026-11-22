<?php

namespace Modules\Usuario\Enums;

enum AcaoAcessoDocumento: string
{
    case Listar = 'listar';
    case Visualizar = 'visualizar';
    case Download = 'download';
    case Negado = 'negado';

    public function label(): string
    {
        return match ($this) {
            self::Listar => 'Listagem',
            self::Visualizar => 'Visualização',
            self::Download => 'Download',
            self::Negado => 'Acesso negado',
        };
    }
}
