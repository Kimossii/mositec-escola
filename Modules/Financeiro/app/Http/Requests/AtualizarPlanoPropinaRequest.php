<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPlanoPropina;
use Modules\Financeiro\Support\AlvosDoPlano;

class AtualizarPlanoPropinaRequest extends BaseRequest
{
    use ValidaPlanoPropina;

    private bool $todosOsAlvosEliminados = false;

    /**
     * Retira dos alvos recebidos os que já estavam gravados no plano e apontam para um
     * nível/turno/turma eliminado. Se isso esvaziar os alvos, é rejeitado em withValidator:
     * nunca se passa silenciosamente a plano geral.
     */
    protected function prepareForValidation(): void
    {
        $plano = $this->route('plano');

        if ($plano === null) {
            return;
        }

        $obsoletos = $plano->alvos()->with(['nivelAcademico', 'turno', 'turma'])->get()
            ->filter(fn ($alvo) => $alvo->obsoleto())
            ->map(fn ($alvo) => AlvosDoPlano::chave($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))
            ->all();

        if ($obsoletos === []) {
            return;
        }

        $recebidos = (array) $this->input('alvos', []);
        $mantidos = array_values(array_filter($recebidos, fn ($alvo) => ! in_array(AlvosDoPlano::chave((array) $alvo), $obsoletos, true)));

        // O plano tinha alvos obsoletos: ficar sem nenhum alvo válido (lista vazia, omitida ou só
        // com obsoletos) nunca o transforma silenciosamente em plano geral.
        $this->todosOsAlvosEliminados = AlvosDoPlano::paraGravar($mantidos) === [];
        $this->merge(['alvos' => $mantidos]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.editar') ?? false;
    }

    public function rules(): array
    {
        $plano = $this->route('plano');

        return array_merge($this->regrasComuns(), [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('planos_propina', 'nome')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->where('ano_lectivo_id', $plano?->ano_lectivo_id))
                    ->ignore($plano?->id),
            ],
        ]);
    }

    public function messages(): array
    {
        return $this->mensagensComuns();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $plano = $this->route('plano');

            if ($this->todosOsAlvosEliminados) {
                $validator->errors()->add('alvos', 'Todos os alvos do plano foram eliminados; escolha novos alvos ou desactive o plano.');

                return;
            }

            // Só pede confirmação quando o plano deixa de ter alvos; um plano já geral não pergunta de novo.
            $this->validarConfirmacaoDePlanoGeral($validator, $plano->alvos()->exists());

            if ($plano->anoLectivo !== null) {
                $this->validarCoerencia($validator, $plano->anoLectivo, (int) $plano->id);
            }
        });
    }
}
