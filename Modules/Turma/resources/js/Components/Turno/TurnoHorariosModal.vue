<script setup>
import { reactive } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    turno: { type: Object, default: null },
    horariosDisponiveis: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['adicionar', 'fechar']);

const form = reactive({
    horario_id: '',
    ordem: 1,
});

const opcoesHorario = () =>
    props.horariosDisponiveis.map((h) => ({ value: h.id, label: `${h.nome} (${h.hora_inicio} - ${h.hora_fim})` }));

function submeter() {
    if (!form.horario_id) return;
    emit('adicionar', { horario_id: form.horario_id, ordem: form.ordem });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">Horários do Turno — {{ turno?.nome }}</h3>

                <table v-if="turno?.turno_horarios?.length" class="table table-row-dashed fs-6 gy-3 mb-6">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-50px">Ordem</th>
                            <th>Horário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="th in turno.turno_horarios" :key="th.id">
                            <td>{{ th.ordem }}</td>
                            <td>{{ th.horario?.nome }} ({{ th.horario?.hora_inicio }} - {{ th.horario?.hora_fim }})</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-muted mb-6">Nenhum horário associado a este turno.</p>

                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-8 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Horário</label>
                            <SelectSolid v-model="form.horario_id" :options="opcoesHorario()" placeholder="Selecione um horário" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.horario_id">{{ errors.horario_id }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ordem</label>
                            <input v-model="form.ordem" type="number" min="1" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.ordem">{{ errors.ordem }}</div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('fechar')">
                            Fechar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Adicionar Horário</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
