<?php

namespace Modules\Plataforma\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\Enums\Modalidade;

/**
 * Só o formato. Tudo o que não é o plano é sobreposição por licença: o que vier vazio herda do plano
 * (tolerância, aviso, validade); um limite vazio, presente no pedido, é «sem limite». As regras reais são da EmitirLicencaAction.
 */
class EmitirLicencaRequest extends FormRequest
{
    private const CHAVES_DE_LIMITE = ['alunos', 'utilizadores'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plano_id' => ['required', 'integer'],
            'escola' => ['nullable', 'string', 'max:60'],
            'instalacao' => ['nullable', 'string', 'max:120'],
            'modalidade' => ['nullable', 'integer', 'in:' . implode(',', array_column(Modalidade::cases(), 'value'))],
            'validade_dias' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limite_alunos' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'limite_utilizadores' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'tolerancia_dias' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'aviso_dias' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modulos' => ['nullable', 'array'],
            'modulos.*' => ['string', 'max:60'],
            'ligacao_obrigatoria' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Valor inválido.',
            'integer' => 'Indique um número inteiro.',
            'min' => 'Valor demasiado baixo.',
            'max' => 'Valor demasiado alto.',
            'in' => 'Valor inválido.',
            'array' => 'Valor inválido.',
            'boolean' => 'Valor inválido.',
        ];
    }

    public function paraDto(): EmitirLicencaDTO
    {
        $inteiro = fn (string $campo): ?int => $this->input($campo) === null || $this->input($campo) === '' ? null : (int) $this->input($campo);

        $limites = null;
        foreach (self::CHAVES_DE_LIMITE as $chave) {
            if ($this->has("limite_{$chave}")) {
                $limites[$chave] = $inteiro("limite_{$chave}");
            }
        }

        $validade = $inteiro('validade_dias');

        return new EmitirLicencaDTO(
            planoId: (int) $this->input('plano_id'),
            escola: $this->filled('escola') ? (string) $this->input('escola') : null,
            instalacao: $this->filled('instalacao') ? (string) $this->input('instalacao') : null,
            expiraEm: $validade === null ? null : CarbonImmutable::now()->utc()->startOfSecond()->addDays($validade),
            limites: $limites,
            toleranciaDias: $inteiro('tolerancia_dias'),
            avisoDias: $inteiro('aviso_dias'),
            modulos: $this->has('modulos') ? array_values((array) $this->input('modulos')) : null,
            modalidade: $this->filled('modalidade') ? Modalidade::from((int) $this->input('modalidade')) : null,
            ligacaoObrigatoria: $this->has('ligacao_obrigatoria') && $this->input('ligacao_obrigatoria') !== null ? $this->boolean('ligacao_obrigatoria') : null,
        );
    }
}
