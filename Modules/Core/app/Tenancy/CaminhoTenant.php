<?php

namespace Modules\Core\Tenancy;

use InvalidArgumentException;

/**
 * Prefixo de ficheiros por tenant: todos os ficheiros de um tenant ficam debaixo
 * de `tenants/{id}/`, em qualquer disco. Sem contexto lança TenantNaoResolvido.
 * É o único caminho permitido para compor o caminho de uma gravação nos módulos.
 */
class CaminhoTenant
{
    public static function prefixo(): string
    {
        return 'tenants/' . app(TenantContext::class)->id() . '/';
    }

    /**
     * `CaminhoTenant::para('alunos/fotos')` => `tenants/{id}/alunos/fotos`.
     * Rejeita caminhos absolutos, vazios e segmentos `..`.
     */
    public static function para(string $caminho): string
    {
        if ($caminho === '' || str_starts_with($caminho, '/') || str_starts_with($caminho, '\\') || preg_match('/^[A-Za-z]:/', $caminho) === 1) {
            throw new InvalidArgumentException('O caminho de um ficheiro de tenant tem de ser relativo e não vazio.');
        }

        if (str_contains($caminho, "\0")) {
            throw new InvalidArgumentException('O caminho de um ficheiro de tenant não pode conter bytes nulos.');
        }

        $segmentos = array_values(array_filter(
            preg_split('#[/\\\\]+#', $caminho),
            fn (string $segmento) => $segmento !== '' && $segmento !== '.',
        ));

        if ($segmentos === []) {
            throw new InvalidArgumentException('O caminho de um ficheiro de tenant não pode ser vazio.');
        }

        if (in_array('..', $segmentos, true)) {
            throw new InvalidArgumentException('O caminho de um ficheiro de tenant não pode conter "..".');
        }

        return self::prefixo() . implode('/', $segmentos);
    }

    /**
     * Defesa em profundidade para caminhos guardados no model: antes de ler ou apagar
     * um ficheiro, confirma que está sob o prefixo do tenant corrente e sem `..`.
     * Devolve o caminho; lança InvalidArgumentException se não cumprir.
     */
    public static function garantir(string $caminho): string
    {
        $segmentos = preg_split('#[/\\\\]+#', $caminho);

        if ($caminho === '' || str_contains($caminho, "\0") || in_array('..', $segmentos, true) || in_array('.', $segmentos, true)) {
            throw new InvalidArgumentException('Caminho de ficheiro inválido.');
        }

        if (! str_starts_with($caminho, self::prefixo()) || strlen($caminho) === strlen(self::prefixo())) {
            throw new InvalidArgumentException('O ficheiro não pertence ao tenant corrente.');
        }

        return $caminho;
    }
}
