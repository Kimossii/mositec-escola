<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Rules\ValorMonetario;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;

/**
 * Regras e validação cruzada partilhadas por Criar e Atualizar um plano de propina.
 */
trait ValidaPlanoPropina
{
    /**
     * @return array<string, mixed>
     */
    protected function regrasComuns(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $existeNoTenant = fn (string $tabela, bool $comSoftDelete) => Rule::exists($tabela, 'id')
            ->where(function ($query) use ($tenantId, $comSoftDelete) {
                $query->where('tenant_id', $tenantId);

                if ($comSoftDelete) {
                    $query->whereNull('deleted_at');
                }
            });

        return [
            'descricao' => ['nullable', 'string'],
            'valor' => ['required', new ValorMonetario(false)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'confirmar_plano_geral' => ['nullable', 'boolean'],
            'alvos' => ['nullable', 'array'],
            'alvos.*.nivel_academico_id' => ['nullable', 'integer', $existeNoTenant('niveis_academicos', true)],
            'alvos.*.curso_id' => ['nullable', 'integer', $existeNoTenant('cursos', false)],
            'alvos.*.turno_id' => ['nullable', 'integer', $existeNoTenant('turnos', true)],
            'alvos.*.turma_id' => ['nullable', 'integer', $existeNoTenant('turmas', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensComuns(): array
    {
        return [
            'nome.required' => 'O nome do plano de propina é obrigatório.',
            'nome.max' => 'O nome do plano de propina não pode ultrapassar 100 caracteres.',
            'nome.unique' => 'Já existe um plano de propina com este nome neste ano lectivo.',
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo escolhido é inválido.',
            'valor.required' => 'O valor do plano é obrigatório.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_inicio.date' => 'A data de início é inválida.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.date' => 'A data de fim é inválida.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'confirmar_plano_geral.boolean' => 'A confirmação do plano geral é inválida.',
            'alvos.*.nivel_academico_id.exists' => 'O nível académico escolhido é inválido.',
            'alvos.*.curso_id.exists' => 'O curso escolhido é inválido.',
            'alvos.*.turno_id.exists' => 'O turno escolhido é inválido.',
            'alvos.*.turma_id.exists' => 'A turma escolhida é inválida.',
        ];
    }

    /**
     * Gravar um plano que fique sem alvos (plano geral) exige confirmação explícita.
     */
    protected function validarConfirmacaoDePlanoGeral(Validator $validator, bool $exigir): void
    {
        $erros = $validator->errors();

        if (! $exigir || $erros->has('confirmar_plano_geral')) {
            return;
        }

        $alvos = $this->input('alvos', []);

        if (! is_array($alvos) || AlvosDoPlano::paraGravar($alvos) !== []) {
            return;
        }

        if (! $this->boolean('confirmar_plano_geral')) {
            $erros->add('confirmar_plano_geral', 'Este plano não tem alvos definidos e será aplicado a todas as turmas do ano lectivo. Confirme que pretende criar um plano geral.');
        }
    }

    protected function validarCoerencia(Validator $validator, AnoLectivo $ano, ?int $ignorarPlanoId): void
    {
        $erros = $validator->errors();

        $temErroEmAlvos = collect($erros->keys())->contains(fn (string $chave) => str_starts_with($chave, 'alvos'));

        if ($temErroEmAlvos) {
            return;
        }

        $alvos = (array) $this->input('alvos', []);
        $consulta = app(PlanoPropinaConsultaService::class);

        if (AlvosDoPlano::temRepetidos($alvos)) {
            $validator->errors()->add('alvos', 'Há alvos repetidos no plano.');

            return;
        }

        if (AlvosDoPlano::violaExclusividadeDaTurma($alvos)) {
            $validator->errors()->add('alvos', 'Uma turma específica não se combina com nível, curso ou turno.');

            return;
        }

        if ($consulta->turmasForaDoAno($ano->id, AlvosDoPlano::idsDeTurma($alvos))) {
            $validator->errors()->add('alvos', 'A turma escolhida não pertence ao ano lectivo do plano.');

            return;
        }

        if ($erros->hasAny(['data_inicio', 'data_fim'])) {
            return;
        }

        $dataInicio = CarbonImmutable::parse((string) $this->input('data_inicio'));
        $dataFim = CarbonImmutable::parse((string) $this->input('data_fim'));

        if ($dataInicio < $ano->data_inicio->startOfDay() || $dataFim > $ano->data_fim->endOfDay()) {
            $validator->errors()->add('data_inicio', 'As datas do plano têm de estar dentro do ano lectivo.');

            return;
        }

        $competencias = CalendarioDePlano::competencias($dataInicio, $dataFim);

        if ($consulta->colisaoDeAlvos($ano->id, $alvos, $competencias, $ignorarPlanoId)) {
            $validator->errors()->add('alvos', 'Já existe outro plano deste ano lectivo, com período sobreposto, aplicável aos mesmos alvos.');
        }
    }
}
