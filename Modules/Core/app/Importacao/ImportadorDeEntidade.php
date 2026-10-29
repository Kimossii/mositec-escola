<?php

namespace Modules\Core\Importacao;

use Illuminate\Support\Str;

/**
 * Contrato de uma entidade importável: as colunas do modelo, as regras de validação, a chave natural (para não duplicar)
 * e a forma de criar um registo (sempre por uma Action do módulo, para manter regras, autoria e logs).
 * O motor (Validar/Importar) trata do resto e não sabe nada de cursos, disciplinas ou alunos.
 */
abstract class ImportadorDeEntidade
{
    /** Identificador no URL, ex.: «cursos». */
    abstract public function chave(): string;

    abstract public function titulo(): string;

    /** Permissão (gate) de quem pode importar, ex.: «curso.criar». */
    abstract public function permissao(): string;

    /** @return array<string, array{rotulo: string, obrigatoria: bool, exemplo: string, ajuda: string}> */
    abstract public function colunas(): array;

    /** @return array<string, list<mixed>> regras do Laravel por coluna */
    abstract public function regras(): array;

    /** Coluna que identifica o registo (ex.: «codigo»): se já existe na escola, a linha é ignorada. */
    abstract public function colunaChave(): string;

    /** Chave natural da linha, para detectar duplicados. Por omissão é a coluna-chave; uma entidade com chave composta redefine-a. */
    public function chaveDe(array $dados): ?string
    {
        $valor = $dados[$this->colunaChave()] ?? null;

        return $valor === null ? null : (string) $valor;
    }

    /** Como se chama a chave nas mensagens de repetição. */
    public function rotuloDaChave(): string
    {
        return $this->colunas()[$this->colunaChave()]['rotulo'] ?? $this->colunaChave();
    }

    /**
     * Das linhas do bloco, as chaves que já existem na escola.
     *
     * @param  array<int, array<string, mixed>>  $bloco
     * @return list<string>
     */
    public function chavesExistentes(array $bloco): array
    {
        $coluna = $this->colunaChave();
        $valores = array_values(array_unique(array_filter(array_map(fn ($d) => isset($d[$coluna]) ? (string) $d[$coluna] : null, $bloco))));

        return $this->existentes($coluna, $valores);
    }

    /** Validação extra depois das regras (ex.: referências a outras entidades). @return list<string> mensagens de erro */
    public function validarLinha(array $dados): array
    {
        return [];
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function modelo(): string;

    /** Cria o registo e devolve o id. Lança excepção se não puder. */
    abstract public function criar(array $dados): int;

    /** Outras colunas que não podem repetir (ex.: «nome»); repetição no ficheiro ou na escola é erro. @return list<string> */
    public function colunasUnicas(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function mensagens(): array
    {
        return [];
    }

    /** Valores já existentes na escola (o tenant é aplicado pelo modelo). @param list<string> $valores @return list<string> */
    public function existentes(string $coluna, array $valores): array
    {
        return $this->modelo()::query()->whereIn($coluna, $valores)->pluck($coluna)->map(fn ($v) => (string) $v)->all();
    }

    /** Aparar espaços e vazios para null. */
    public function normalizar(array $dados): array
    {
        return array_map(function ($v) {
            $v = is_string($v) ? trim($v) : $v;

            return $v === '' ? null : $v;
        }, $dados);
    }

    /** Texto comparável de um cabeçalho: sem acentos, maiúsculas, asteriscos nem espaços. */
    public static function simplificar(?string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $texto))) ?? '';
    }

    /**
     * Se esta linha for o cabeçalho (contém todas as colunas obrigatórias), devolve índice da coluna → chave; senão null.
     *
     * @param  list<?string>  $celulas
     * @return array<int, string>|null
     */
    public function mapearCabecalho(array $celulas): ?array
    {
        $porNome = [];
        foreach ($this->colunas() as $chave => $c) {
            $porNome[self::simplificar($chave)] = $chave;
            $porNome[self::simplificar($c['rotulo'])] = $chave;
        }

        $mapa = [];
        foreach ($celulas as $i => $celula) {
            $chave = $porNome[self::simplificar($celula)] ?? null;
            if ($chave !== null && ! in_array($chave, $mapa, true)) {
                $mapa[$i] = $chave;
            }
        }

        foreach ($this->colunas() as $chave => $c) {
            if ($c['obrigatoria'] && ! in_array($chave, $mapa, true)) {
                return null;
            }
        }

        return $mapa;
    }
}
