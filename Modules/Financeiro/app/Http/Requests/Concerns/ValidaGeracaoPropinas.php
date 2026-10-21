<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Closure;
use Modules\Matricula\Models\Matricula;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;

/**
 * Regras partilhadas pela pré-visualização e pela confirmação da geração manual (a confirmação valida
 * tudo de novo: nunca confia no que a pré-visualização mostrou).
 */
trait ValidaGeracaoPropinas
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        if ($utilizador === null || ! $utilizador->can('propina.criar')) {
            return false;
        }

        // Períodos anteriores ao mês da matrícula são excepcionais (spec §7.2, Q9): exigem `propina.ajustar`.
        return ! $this->boolean('incluir_periodos_anteriores') || $utilizador->can('propina.ajustar');
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validador) {
            if ($validador->errors()->has('ano_lectivo_id')) {
                return;
            }

            $encerrado = AnoLectivo::query()->whereKey((int) $this->input('ano_lectivo_id'))
                ->where('estado', EstadoAnoLectivo::ENCERRADO->value)->exists();

            if ($encerrado) {
                $validador->errors()->add('ano_lectivo_id', 'Não é possível gerar propinas para um ano lectivo encerrado.');
            }

            $temAno = $this->filled('ate_ano');
            $temMes = $this->filled('ate_mes');

            if ($temAno !== $temMes) {
                $validador->errors()->add('ate_mes', 'Indique o ano e o mês «até» em conjunto.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $anoId = (int) $this->input('ano_lectivo_id');
        $ambito = $this->input('ambito');
        $doTenant = fn (string $tabela) => Rule::exists($tabela, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        return [
            'ano_lectivo_id' => ['required', 'integer', $doTenant('ano_lectivos')],
            'ambito' => ['required', 'string', Rule::in(array_map(fn (AmbitoGeracaoPropinas $a) => $a->value, AmbitoGeracaoPropinas::manuais()))],
            'aluno_numero' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::ALUNO->value), 'nullable', 'string', 'max:50',
                Rule::exists('alunos', 'numero_matricula')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
                fn (string $atributo, mixed $valor, Closure $falha) => $ambito === AmbitoGeracaoPropinas::ALUNO->value
                    && ! Matricula::query()->where('ano_lectivo_id', $anoId)->whereHas('aluno', fn ($a) => $a->where('numero_matricula', $valor))->exists()
                    ? $falha('Este aluno não tem matrícula neste ano lectivo.') : null,
            ],
            'matricula_numero' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::MATRICULA->value), 'nullable', 'string', 'max:50',
                Rule::exists('matriculas', 'numero_registo_matricula')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->whereNull('deleted_at')),
            ],
            'turma_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::TURMA->value), 'nullable', 'integer',
                Rule::exists('turmas', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->whereNull('deleted_at')),
            ],
            'nivel_academico_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::NIVEL->value), 'nullable', 'integer', $doTenant('niveis_academicos'),
            ],
            'plano_propina_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::PLANO->value), 'nullable', 'integer',
                Rule::exists('planos_propina', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->where('estado', Estado::ATIVO->value)),
            ],
            'incluir_periodos_anteriores' => ['sometimes', 'boolean'],
            'motivo_geracao' => [Rule::requiredIf(fn () => $this->boolean('incluir_periodos_anteriores')), 'nullable', 'string', 'max:500'],
            'ate_ano' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'ate_mes' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo escolhido é inválido.',
            'ambito.required' => 'Escolha para quem gerar as propinas.',
            'ambito.in' => 'O âmbito escolhido é inválido.',
            'aluno_numero.required' => 'Indique o número de estudante.',
            'aluno_numero.exists' => 'Não há nenhum aluno com esse número de estudante.',
            'matricula_numero.required' => 'Indique o número de registo da matrícula.',
            'matricula_numero.exists' => 'Não há nenhuma matrícula com esse número neste ano lectivo.',
            'turma_id.required' => 'Escolha a turma.',
            'turma_id.exists' => 'A turma escolhida é inválida para este ano lectivo.',
            'nivel_academico_id.required' => 'Escolha o nível académico.',
            'nivel_academico_id.exists' => 'O nível académico escolhido é inválido.',
            'plano_propina_id.required' => 'Escolha o plano de propina.',
            'plano_propina_id.exists' => 'O plano escolhido é inválido, inactivo ou de outro ano lectivo.',
            'motivo_geracao.required' => 'Indique o motivo para gerar períodos anteriores à matrícula.',
            'motivo_geracao.max' => 'O motivo não pode ter mais de 500 caracteres.',
            'ate_ano.integer' => 'O ano «até» é inválido.',
            'ate_mes.integer' => 'O mês «até» é inválido.',
            'ate_mes.between' => 'O mês «até» tem de estar entre 1 e 12.',
        ];
    }
}
