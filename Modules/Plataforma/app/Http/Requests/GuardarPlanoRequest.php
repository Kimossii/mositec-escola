<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\Modalidade;

/**
 * Só o formato: limites, tolerância, aviso e módulos são validados pelas Actions de plano (regras do Licenciamento).
 * Campo de limite vazio = sem limite.
 */
class GuardarPlanoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'validade_dias' => ['required', 'integer', 'min:1', 'max:100000'],
            'modalidade' => ['required', 'integer', 'in:' . implode(',', array_column(Modalidade::cases(), 'value'))],
            'limite_alunos' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'limite_utilizadores' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'limite_emails_mes' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'emails_aviso_pct' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'emails_bloqueia_ao_exceder' => ['nullable', 'boolean'],
            'tolerancia_dias' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'aviso_dias' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modulos' => ['nullable', 'array'],
            'modulos.*' => ['string', 'max:60'],
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
        ];
    }

    public function paraDto(): PlanoDTO
    {
        $inteiro = fn (string $campo): ?int => $this->input($campo) === null || $this->input($campo) === '' ? null : (int) $this->input($campo);

        return new PlanoDTO(
            nome: (string) $this->input('nome'),
            modulos: array_values((array) $this->input('modulos', [])),
            validadeDias: (int) $this->input('validade_dias'),
            modalidade: Modalidade::from((int) $this->input('modalidade')),
            limites: [
                'alunos' => $inteiro('limite_alunos'),
                'utilizadores' => $inteiro('limite_utilizadores'),
                'emails_mes' => $inteiro('limite_emails_mes'),
            ],
            toleranciaDias: $inteiro('tolerancia_dias'),
            avisoDias: $inteiro('aviso_dias'),
            descricao: $this->filled('descricao') ? (string) $this->input('descricao') : null,
            emailsAvisoPct: $inteiro('emails_aviso_pct') ?? 80,
            emailsBloqueia: $this->boolean('emails_bloqueia_ao_exceder', true),
        );
    }
}
