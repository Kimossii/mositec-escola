<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    salas: { type: Array, required: true },
    turmaSala: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const opcoesSala = () => props.salas.map((s) => ({ value: s.id, label: `${s.codigo} — ${s.nome}` }));

const form = reactive({
    sala_id: '',
    inicio: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    if (props.turmaSala) {
        form.sala_id = props.turmaSala.sala_id;
        form.inicio = props.turmaSala.inicio?.slice(0, 10) ?? '';
        return;
    }
    form.sala_id = '';
    form.inicio = new Date().toISOString().slice(0, 10);
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ turmaSala ? 'Editar Sala Associada' : 'Associar Sala à Turma' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Sala</label>
                        <SelectSolid v-model="form.sala_id" :options="opcoesSala()" placeholder="Selecione a sala" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.sala_id">{{ errors.sala_id }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Início</label>
                        <input v-model="form.inicio" type="date" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.inicio">{{ errors.inicio }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">{{ turmaSala ? 'Guardar' : 'Associar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
