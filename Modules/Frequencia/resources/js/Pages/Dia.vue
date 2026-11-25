<script setup>
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import AulaDoDiaCard from '../Components/AulaDoDiaCard.vue';
import { formatarDia } from '../Support/frequencia';

/** «As minhas chamadas»: as aulas do professor num dia (por omissão hoje) e o estado da chamada de cada uma. */
const props = defineProps({
    data: { type: String, required: true },
    hoje: { type: String, required: true },
    aulas: { type: Array, required: true },
    filtros: { type: Object, default: () => ({}) },
    opcoes: { type: Object, default: () => ({ turmas: [] }) }, // turmas só para a secretaria
});
defineOptions({ layout: AppLayout });

const estado = reactive({ data: props.data, turma_id: props.filtros.turma_id ?? '' });
const opcoesTurma = computed(() => props.opcoes.turmas.map((t) => ({ value: t.id, label: t.rotulo })));
const params = () => Object.fromEntries(Object.entries(estado).filter(([, v]) => v !== '' && v !== null));

let debounceId = null;
watch(estado, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/frequencia', params(), { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">As minhas chamadas</h1>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-light" :disabled="estado.data === hoje" @click="estado.data = hoje">Hoje</button>
                <input v-model="estado.data" type="date" aria-label="Dia" class="form-control form-control-solid form-control-sm w-150px" />
            </div>
        </div>

        <div v-if="opcoes.turmas.length" class="card mb-6">
            <div class="card-body py-4">
                <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                <div class="w-md-350px"><SelectSolid v-model="estado.turma_id" :options="opcoesTurma" placeholder="Seleccione a turma" searchable /></div>
            </div>
        </div>

        <div v-if="!aulas.length" class="card">
            <div class="card-body">
                <EstadoVazio titulo="Sem aulas neste dia" :descricao="`Não há aulas previstas para ${formatarDia(data)}. Aos fins-de-semana, feriados e férias não há chamada.`" />
            </div>
        </div>

        <template v-else>
            <AulaDoDiaCard v-for="a in aulas" :key="`${a.turma_id}-${a.horario_id ?? 'dia'}`" :aula="a" :futura="data > hoje" />
        </template>
    </div>
</template>
