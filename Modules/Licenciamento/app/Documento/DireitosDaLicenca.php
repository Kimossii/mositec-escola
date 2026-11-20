<?php

namespace Modules\Licenciamento\Documento;

use Carbon\CarbonImmutable;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Licenciamento\Assinatura\Exceptions\DocumentoInvalido;
use Modules\Licenciamento\Enums\Modalidade;
use Throwable;

/** Direitos concedidos por uma licença (o que vai dentro do token assinado). Imutável; instantes em UTC. */
final readonly class DireitosDaLicenca
{
    public const VERSAO_FORMATO = 1;

    public const EMISSOR = 'mositec';

    /** Tectos de sanidade: evitam overflow nas datas e limites absurdos. */
    public const MAX_DIAS = 3650;

    public const MAX_LIMITE = 10_000_000;

    /** Chaves dos limites; null = sem limite. Recurso cobre alunos/utilizadores; estabelecimentos só viaja no documento. */
    private const LIMITES = ['alunos', 'utilizadores', 'estabelecimentos'];

    /**
     * @param  array<string,?int>  $limites
     * @param  list<string>  $modulos
     */
    public function __construct(
        public string $id,
        public ?string $escola,
        public ?string $instalacao,
        public CarbonImmutable $emitidaEm,
        public CarbonImmutable $inicio,
        public CarbonImmutable $expiraEm,
        public int $toleranciaDias,
        public ?int $avisoDias,
        public array $limites,
        public array $modulos,
        public Modalidade $modalidade,
        public int $versaoFormato = self::VERSAO_FORMATO,
    ) {}

    /**
     * @param  array<string,mixed>  $claims
     *
     * @throws DocumentoInvalido
     */
    public static function deClaims(array $claims): self
    {
        if (($claims['fmt'] ?? null) !== self::VERSAO_FORMATO) {
            throw new DocumentoInvalido('Versão de formato da licença desconhecida.');
        }

        $id = $claims['jti'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new DocumentoInvalido('Licença sem identificador.');
        }

        $emitidaEm = self::instante($claims, 'iat');
        $inicio = self::instante($claims, 'nbf');
        $expiraEm = self::instante($claims, 'exp');

        if ($expiraEm->lte($inicio)) {
            throw new DocumentoInvalido('A licença expira antes (ou quando) começa.');
        }

        $tolerancia = $claims['tol'] ?? null;
        if (! is_int($tolerancia) || $tolerancia < 0 || $tolerancia > self::MAX_DIAS) {
            throw new DocumentoInvalido('Tolerância inválida (inteiro entre 0 e ' . self::MAX_DIAS . ').');
        }

        $aviso = $claims['avi'] ?? null;
        if ($aviso !== null && (! is_int($aviso) || $aviso < 0 || $aviso > self::MAX_DIAS)) {
            throw new DocumentoInvalido('Dias de aviso inválidos (inteiro entre 0 e ' . self::MAX_DIAS . ').');
        }

        $modulos = $claims['mod'] ?? null;
        if (! is_array($modulos) || ! array_is_list($modulos) || array_filter($modulos, fn ($m) => ! is_string($m) || $m === '') !== []) {
            throw new DocumentoInvalido('Módulos inválidos.');
        }

        $modalidade = is_int($claims['modalidade'] ?? null) ? Modalidade::tryFrom($claims['modalidade']) : null;
        if ($modalidade === null) {
            throw new DocumentoInvalido('Modalidade desconhecida.');
        }

        foreach (['escola', 'instalacao'] as $chave) {
            if (($claims[$chave] ?? null) !== null && ! is_string($claims[$chave])) {
                throw new DocumentoInvalido("Campo {$chave} inválido.");
            }
        }

        return new self(
            id: $id,
            escola: $claims['escola'] ?? null,
            instalacao: $claims['instalacao'] ?? null,
            emitidaEm: $emitidaEm,
            inicio: $inicio,
            expiraEm: $expiraEm,
            toleranciaDias: $tolerancia,
            avisoDias: $aviso,
            limites: self::limites($claims['lim'] ?? []),
            modulos: array_values(array_unique($modulos)),
            modalidade: $modalidade,
            versaoFormato: self::VERSAO_FORMATO,
        );
    }

    /** @return array<string,mixed> */
    public function paraClaims(): array
    {
        $claims = [
            'iss' => self::EMISSOR,
            'sub' => $this->escola ?? $this->instalacao,
            'jti' => $this->id,
            'iat' => $this->emitidaEm->utc()->toIso8601String(),
            'nbf' => $this->inicio->utc()->toIso8601String(),
            'exp' => $this->expiraEm->utc()->toIso8601String(),
            'escola' => $this->escola,
            'instalacao' => $this->instalacao,
            'tol' => $this->toleranciaDias,
            'lim' => $this->limites,
            'mod' => $this->modulos,
            'modalidade' => $this->modalidade->value,
            'fmt' => $this->versaoFormato,
        ];

        if ($this->avisoDias !== null) {
            $claims['avi'] = $this->avisoDias;
        }

        return $claims;
    }

    /** Fim da tolerância: a janela conta uma só vez a partir de expira_em. */
    public function bloqueiaEm(): CarbonImmutable
    {
        return $this->expiraEm->addDays($this->toleranciaDias);
    }

    public function temModulo(string $alias): bool
    {
        return in_array($alias, $this->modulos, true);
    }

    /** Limite de um recurso; null = sem limite. */
    public function limiteDe(Recurso $recurso): ?int
    {
        return $this->limites[$recurso->value] ?? null;
    }

    /** @param array<string,mixed> $claims */
    private static function instante(array $claims, string $chave): CarbonImmutable
    {
        $valor = $claims[$chave] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw new DocumentoInvalido("Data '{$chave}' em falta ou inválida.");
        }

        try {
            return CarbonImmutable::parse($valor)->utc();
        } catch (Throwable) {
            throw new DocumentoInvalido("Data '{$chave}' em falta ou inválida.");
        }
    }

    /** @return array<string,?int> */
    private static function limites(mixed $lim): array
    {
        if (! is_array($lim)) {
            throw new DocumentoInvalido('Limites inválidos.');
        }

        $limites = [];
        foreach (self::LIMITES as $chave) {
            $valor = $lim[$chave] ?? null;
            if ($valor !== null && (! is_int($valor) || $valor < 0 || $valor > self::MAX_LIMITE)) {
                throw new DocumentoInvalido("Limite '{$chave}' inválido (inteiro entre 0 e " . self::MAX_LIMITE . ' ou nulo).');
            }
            $limites[$chave] = $valor;
        }

        return $limites;
    }
}
