<script setup>
import { computed, ref, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    disciplina: { type: Object, default: null },
    anosLectivos: { type: Array, default: () => [] },
    aplicacaoPadraoId: { type: [Number, String], default: '' },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const jaTemAlgumPeriodoDefinido = computed(() => (props.disciplina?.periodos_por_aplicacao ?? []).length > 0);

const aplicacaoId = ref('');
const periodoIdsSelecionados = ref([]);

const opcoesAplicacao = computed(() =>
    props.anosLectivos.map((aplicacao) => ({ value: aplicacao.id, label: aplicacao.ano_lectivo?.nome ?? '—' })),
);

const periodosDaAplicacao = computed(() => {
    const aplicacao = props.anosLectivos.find((item) => item.id === aplicacaoId.value);
    return aplicacao?.ano_lectivo?.periodos ?? [];
});

function periodosJaMapeados(idDaAplicacao) {
    return (props.disciplina?.periodos_por_aplicacao ?? [])
        .filter((item) => item.plano_curricular_ano_lectivo_id === idDaAplicacao)
        .map((item) => item.periodo_id);
}

watch(() => props.show, (show) => {
    if (!show) return;
    aplicacaoId.value = props.aplicacaoPadraoId || props.anosLectivos[0]?.id || '';
    periodoIdsSelecionados.value = periodosJaMapeados(aplicacaoId.value);
});

watch(aplicacaoId, (novoId) => {
    periodoIdsSelecionados.value = periodosJaMapeados(novoId);
});

function alternarPeriodo(periodoId) {
    const indice = periodoIdsSelecionados.value.indexOf(periodoId);
    if (indice === -1) {
        periodoIdsSelecionados.value.push(periodoId);
    } else {
        periodoIdsSelecionados.value.splice(indice, 1);
    }
}

function submeter() {
    emit('submit', { aplicacaoId: aplicacaoId.value, periodoIds: [...periodoIdsSelecionados.value] });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">{{ jaTemAlgumPeriodoDefinido ? 'Editar Períodos' : 'Definir Períodos' }}</h3>
                <div class="text-muted fs-7 mb-5">{{ disciplina?.disciplina?.nome }}</div>

                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                        <SelectSolid v-model="aplicacaoId" :options="opcoesAplicacao" placeholder="Selecione o ano lectivo" />
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Períodos</label>
                        <div v-if="!periodosDaAplicacao.length" class="text-muted fs-7">
                            Este ano lectivo ainda não tem períodos configurados.
                        </div>
                        <div v-for="periodo in periodosDaAplicacao" :key="periodo.id" class="form-check form-check-custom form-check-solid mb-2">
                            <input
                                :id="`periodo-${periodo.id}`"
                                type="checkbox"
                                class="form-check-input"
                                :checked="periodoIdsSelecionados.includes(periodo.id)"
                                @change="alternarPeriodo(periodo.id)"
                            />
                            <label class="form-check-label fw-semibold fs-6" :for="`periodo-${periodo.id}`">{{ periodo.nome }}</label>
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.periodo_ids">{{ errors.periodo_ids }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing || !aplicacaoId">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
