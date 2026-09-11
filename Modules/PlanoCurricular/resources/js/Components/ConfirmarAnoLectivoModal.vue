<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    planoCurricular: { type: Object, required: true },
    opcoes: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const anosJaConfirmadosIds = computed(() =>
    (props.planoCurricular.anos_lectivos ?? [])
        .map((item) => item.ano_lectivo?.id)
        .filter((id) => id !== undefined && id !== null),
);

const anosLectivosDisponiveis = computed(() =>
    props.opcoes.anosLectivos.filter((ano) => !anosJaConfirmadosIds.value.includes(ano.id)),
);

const opcoesAnosLectivos = computed(() =>
    anosLectivosDisponiveis.value.map((ano) => ({ value: ano.id, label: ano.nome })),
);

// Ano Lectivo activo (estado 1) pré-seleccionado por defeito, para o
// utilizador saber sempre a que ano se está a referir sem ter de escolher
// manualmente — ele só muda se quiser mesmo confirmar para outro ano.
const anoLectivoAtivoId = computed(() =>
    anosLectivosDisponiveis.value.find((ano) => ano.estado === 1)?.id ?? '',
);

const form = reactive({
    ano_lectivo_id: '',
    observacoes: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_lectivo_id = anoLectivoAtivoId.value;
    form.observacoes = '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">Confirmar Plano para Ano Lectivo</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                        <SelectSolid v-model="form.ano_lectivo_id" :options="opcoesAnosLectivos" placeholder="Selecione o ano lectivo" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.ano_lectivo_id">{{ errors.ano_lectivo_id }}</div>
                        <div v-if="!opcoesAnosLectivos.length" class="text-muted fs-7 mt-1">
                            Não existem anos lectivos disponíveis para confirmar — todos já foram confirmados para este plano.
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Observações</label>
                        <textarea v-model="form.observacoes" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.observacoes">{{ errors.observacoes }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing || !opcoesAnosLectivos.length">Confirmar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
