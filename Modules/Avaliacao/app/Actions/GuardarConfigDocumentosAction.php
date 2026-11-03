<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Core\Traits\LogService;

/** Guarda o texto do cabeçalho oficial (uma linha por linha de texto), o lema e o visto das pautas da escola. */
class GuardarConfigDocumentosAction
{
    use LogService;

    private const MAXIMO_LINHAS = 6;

    public function executar(?string $linhasExtra, ?string $lema, ?string $vistoCargo, ?string $vistoNome): AvaliacaoDocumentoConfig
    {
        return $this->comLog('Guardar configuração dos documentos', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($linhasExtra, $lema, $vistoCargo, $vistoNome) {
            $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $linhasExtra) ?: [])));

            if (count($linhas) > self::MAXIMO_LINHAS) {
                throw ValidationException::withMessages(['linhas_extra' => 'O cabeçalho aceita até '.self::MAXIMO_LINHAS.' linhas adicionais.']);
            }

            foreach ($linhas as $linha) {
                if (mb_strlen($linha) > 120) {
                    throw ValidationException::withMessages(['linhas_extra' => 'Cada linha do cabeçalho tem no máximo 120 caracteres.']);
                }
            }

            $vazio = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;

            return DB::transaction(function () use ($linhas, $lema, $vistoCargo, $vistoNome, $vazio) {
                $campos = $this->campos($linhas, $lema, $vistoCargo, $vistoNome, $vazio);
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
    private function campos(array $linhas, ?string $lema, ?string $vistoCargo, ?string $vistoNome, callable $vazio): array
    {
        return [
            'linhas_extra' => $linhas === [] ? null : implode("\n", $linhas),
            'lema' => $vazio($lema), 'visto_cargo' => $vazio($vistoCargo), 'visto_nome' => $vazio($vistoNome),
        ];
    }
}
