<?php

namespace Modules\Core\Importacao;

use InvalidArgumentException;

/** Singleton onde cada módulo regista os seus importadores (no boot do provider). */
class RegistoDeImportadores
{
    /** @var array<string, class-string<ImportadorDeEntidade>> */
    private array $importadores = [];

    /** @param class-string<ImportadorDeEntidade> $classe */
    public function registar(string $classe): void
    {
        $this->importadores[app($classe)->chave()] = $classe;
    }

    public function obter(string $chave): ImportadorDeEntidade
    {
        return isset($this->importadores[$chave])
            ? app($this->importadores[$chave])
            : throw new InvalidArgumentException("Entidade «{$chave}» não é importável.");
    }

    public function existe(string $chave): bool
    {
        return isset($this->importadores[$chave]);
    }
}
