<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\ConfigDocumentosDTO;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Core\Traits\LogService;

/** Guarda o texto do cabeçalho oficial (uma linha por linha de texto), o lema e o visto das pautas da escola. */
class GuardarConfigDocumentosAction
{
    use LogService;

    private const MAXIMO_LINHAS = 6;

    public function executar(ConfigDocumentosDTO $dto): AvaliacaoDocumentoConfig
    {
        return $this->comLog('Guardar configuração dos documentos', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $dto->linhas_extra) ?: [])));

            if (count($linhas) > self::MAXIMO_LINHAS) {
                throw ValidationException::withMessages(['linhas_extra' => 'O cabeçalho aceita até '.self::MAXIMO_LINHAS.' linhas adicionais.']);
            }

            foreach ($linhas as $linha) {
                if (mb_strlen($linha) > 120) {
                    throw ValidationException::withMessages(['linhas_extra' => 'Cada linha do cabeçalho tem no máximo 120 caracteres.']);
                }
            }

            $vazio = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;

            return DB::transaction(function () use ($linhas, $dto, $vazio) {
                $campos = $this->campos($linhas, $dto, $vazio);
                $config = AvaliacaoDocumentoConfig::query()->first();

                if ($config === null) {
                    return AvaliacaoDocumentoConfig::create($campos);
                }

                $config->update($campos);

                return $config;
            });
        });
    }

    /** @param  list<string>  $linhas */
    private function campos(array $linhas, ConfigDocumentosDTO $dto, callable $vazio): array
    {
        return [
            'linhas_extra' => $linhas === [] ? null : implode("\n", $linhas),
            'lema' => $vazio($dto->lema), 'visto_cargo' => $vazio($dto->visto_cargo), 'visto_nome' => $vazio($dto->visto_nome),
        ];
    }
}
