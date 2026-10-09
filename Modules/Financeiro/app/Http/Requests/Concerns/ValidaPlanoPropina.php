<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\Periodicidade;
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
            'periodicidade' => ['required', new Enum(Periodicidade::class)],
            'intervalo_meses' => [
                Rule::requiredIf(fn () => (int) $this->input('periodicidade') === Periodicidade::OUTRA->value),
                'nullable',
                'integer',
                'between:1,12',
            ],
            'valor' => ['required', new ValorMonetario(false)],
            'mes_inicio' => ['required', 'integer', 'between:1,12'],
            'mes_fim' => ['required', 'integer', 'between:1,12'],
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
            'periodicidade.required' => 'A periodicidade é obrigatória.',
            'intervalo_meses.required' => 'Indique o intervalo, em meses, da periodicidade.',
            'intervalo_meses.between' => 'O intervalo tem de estar entre 1 e 12 meses.',
            'valor.required' => 'O valor do plano é obrigatório.',
            'mes_inicio.required' => 'O mês de início é obrigatório.',
            'mes_inicio.between' => 'O mês de início tem de estar entre 1 e 12.',
            'mes_fim.required' => 'O mês de fim é obrigatório.',
            'mes_fim.between' => 'O mês de fim tem de estar entre 1 e 12.',
            'alvos.*.nivel_academico_id.exists' => 'O nível académico escolhido é inválido.',
            'alvos.*.curso_id.exists' => 'O curso escolhido é inválido.',
            'alvos.*.turno_id.exists' => 'O turno escolhido é inválido.',
            'alvos.*.turma_id.exists' => 'A turma escolhida é inválida.',
        ];
    }

    protected function validarCoerencia(Validator $validator, AnoLectivo $ano, ?int $ignorarPlanoId): void
    {
        $erros = $validator->errors();

        if (! $erros->hasAny(['periodicidade', 'intervalo_meses', 'mes_inicio', 'mes_fim'])) {
            $periodicidade = Periodicidade::from((int) $this->input('periodicidade'));
            $intervalo = $periodicidade->meses() ?? (int) $this->input('intervalo_meses');
            $meses = CalendarioDePlano::meses((int) $this->input('mes_inicio'), (int) $this->input('mes_fim'));

            if ($intervalo > $meses) {
                $validator->errors()->add('periodicidade', 'A periodicidade excede a duração do período de cobrança.');
            }
        }

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

        if ($erros->hasAny(['mes_inicio', 'mes_fim'])) {
            return;
        }

        $competencias = CalendarioDePlano::competencias((int) $this->input('mes_inicio'), (int) $this->input('mes_fim'), $ano->data_inicio);

        if ($consulta->colisaoDeAlvos($ano->id, $alvos, $competencias, $ignorarPlanoId)) {
            $validator->errors()->add('alvos', 'Já existe outro plano deste ano lectivo, com período sobreposto, aplicável aos mesmos alvos.');
        }
    }
}
