<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    anosLectivos: { type: Array, required: true },
    resumo: { type: Object, default: null }, // { copiados: [{id, nome}], ignorados: [{nome, motivo}] }
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({ ano_origem_id: '', ano_destino_id: '' });

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_origem_id = props.anosLectivos[1]?.id ?? '';
    form.ano_destino_id = props.anosLectivos[0]?.id ?? '';
});

function submeter() {
    emit('submit', { ano_origem_id: form.ano_origem_id, ano_destino_id: form.ano_destino_id });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <h3 class="mb-5">Copiar planos de outro ano lectivo</h3>

                <template v-if="resumo">
                    <div class="alert alert-warning mb-5">
                        Os planos copiados ficam <strong>inactivos</strong>. Reveja o valor e o período de cada um e active-os para entrarem em vigor.
                    </div>

                    <div class="card bg-body-secondary mb-5">
                        <div class="card-body py-4">
                            <h5 class="mb-3">Copiados ({{ resumo.copiados.length }})</h5>
                            <p v-if="resumo.copiados.length === 0" class="text-muted mb-0">Nenhum plano foi copiado.</p>
                            <ul v-else class="mb-0">
                                <li v-for="plano in resumo.copiados" :key="plano.id">{{ plano.nome }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card bg-body-secondary mb-5">
                        <div class="card-body py-4">
                            <h5 class="mb-3">Ignorados ({{ resumo.ignorados.length }})</h5>
                            <p v-if="resumo.ignorados.length === 0" class="text-muted mb-0">Nenhum plano foi ignorado.</p>
                            <ul v-else class="mb-0">
                                <li v-for="(plano, indice) in resumo.ignorados" :key="indice">
                                    <strong>{{ plano.nome }}</strong>
                                    <span class="text-muted"> — {{ plano.motivo }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-primary" @click="emit('cancelar')">Fechar</button>
                    </div>
                </template>

                <form v-else @submit.prevent="submeter">
                    <p class="text-muted fs-6">
                        Copia os planos <strong>activos</strong> do ano de origem para o de destino, com os alvos de nível, curso e turno.
                        Planos que já existam, colidam ou dependam de turmas são ignorados e indicados no fim.
                    </p>
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Copiar de (origem)</label>
                            <SelectSolid v-model="form.ano_origem_id" :options="opcoesAno" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.ano_origem_id">{{ errors.ano_origem_id }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Para (destino)</label>
                            <SelectSolid v-model="form.ano_destino_id" :options="opcoesAno" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.ano_destino_id">{{ errors.ano_destino_id }}</div>
                        </div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Copiar planos</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
