<script setup>
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';
import { ORIGEM_GERACAO } from '../../Models/Propina';

defineProps({
    // ResultadoGeracaoPropinasDTO::toArray(): { previsualizacao, totais, matriculas: [...], detalhe_truncado }
    resultado: { type: Object, required: true },
    moeda: { type: Object, required: true },
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <span class="badge badge-light-primary fs-7">{{ resultado.totais.matriculas }} matrícula(s)</span>
            <span class="badge badge-light-success fs-7">
                {{ resultado.totais.criadas }} {{ resultado.previsualizacao ? 'a criar' : 'criada(s)' }}
            </span>
            <span class="badge badge-light-secondary fs-7">{{ resultado.totais.ignoradas }} ignorada(s)</span>
            <span v-if="resultado.totais.conflitos" class="badge badge-light-warning fs-7">{{ resultado.totais.conflitos }} conflito(s)</span>
            <span v-if="resultado.totais.sem_plano" class="badge badge-light-info fs-7">{{ resultado.totais.sem_plano }} mês(es) sem plano</span>
            <span v-if="resultado.totais.erros" class="badge badge-light-danger fs-7">{{ resultado.totais.erros }} erro(s)</span>
        </div>

        <div v-if="resultado.detalhe_truncado" class="alert alert-info py-3 fs-7 mb-4">
            O detalhe mostra só parte das matrículas (primeiro as com erros e conflitos). Os totais acima incluem todas.
        </div>

        <p v-if="resultado.matriculas.length === 0" class="text-muted mb-0">Nenhuma matrícula no âmbito escolhido.</p>

        <div class="overflow-auto" style="max-height: 420px">
            <div v-for="matricula in resultado.matriculas" :key="matricula.matricula_id" class="card bg-body-secondary mb-3">
                <div class="card-body py-3">
                    <div class="fw-bold text-gray-800">
                        {{ matricula.aluno_nome ?? `Matrícula #${matricula.matricula_id}` }}
                        <span class="text-muted fw-normal fs-7">
                            · nº {{ matricula.aluno_numero ?? '—' }} · matrícula {{ matricula.numero_registo ?? '—' }} · {{ matricula.turma_nome ?? 'sem turma' }}
                        </span>
                    </div>

                    <ul v-if="matricula.criadas.length" class="mt-2 mb-1 fs-7">
                        <li v-for="propina in matricula.criadas" :key="propina.periodo_inicio">
                            <strong>{{ propina.periodo }}</strong>
                            — {{ propina.plano }} · {{ formatarDinheiro(propina.valor, moeda) }} · vence {{ dataCurta(propina.data_vencimento) }}
                            <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning ms-1">Retroactiva</span>
                            <span v-else-if="propina.tardia" class="badge badge-light-warning ms-1" title="Gerada depois da data de vencimento. Só passa a Em Atraso depois da data limite (vencimento mais tolerância).">Geração tardia</span>
                        </li>
                    </ul>

                    <ul v-if="matricula.ignoradas.length" class="mt-2 mb-1 fs-7 text-muted">
                        <li v-for="(ignorada, indice) in matricula.ignoradas" :key="`i${indice}`">
                            <strong v-if="ignorada.periodo">{{ ignorada.periodo }}:</strong> {{ ignorada.motivo }}
                        </li>
                    </ul>

                    <ul v-if="matricula.conflitos.length" class="mt-2 mb-1 fs-7 text-warning">
                        <li v-for="(conflito, indice) in matricula.conflitos" :key="`c${indice}`">{{ conflito }}</li>
                    </ul>

                    <div v-if="matricula.sem_plano.length" class="mt-2 fs-7 text-muted">
                        Meses sem plano aplicável: {{ matricula.sem_plano.join(', ') }}
                    </div>

                    <ul v-if="matricula.erros.length" class="mt-2 mb-0 fs-7 text-danger">
                        <li v-for="(erro, indice) in matricula.erros" :key="`e${indice}`">{{ erro }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
