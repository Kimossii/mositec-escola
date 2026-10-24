<?php

namespace Modules\Core\Exportacao;

enum FormatoExportacao: string
{
    case EXCEL = 'excel';
    case PDF = 'pdf';
    case CSV = 'csv';
    case ZIP = 'zip';

    public function extensao(): string
    {
        return match ($this) {
            self::EXCEL => 'xlsx',
            self::PDF => 'pdf',
            self::CSV => 'csv',
            self::ZIP => 'zip',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PDF => 'application/pdf',
            self::CSV => 'text/csv; charset=UTF-8',
            self::ZIP => 'application/zip',
        };
    }
}
