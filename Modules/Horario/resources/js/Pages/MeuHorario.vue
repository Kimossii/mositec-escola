<script setup>
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import GrelhaSemanal from '../Components/GrelhaSemanal.vue';
import { hojeIso } from '../Support/grelha';

/** «O meu horário» (Área do Professor): só as aulas das turmas e disciplinas que o utilizador lecciona na data. */
const props = defineProps({
    data: { type: String, required: true }, // AAAA-MM-DD
    grelha: { type: Object, required: true }, // { tempos, entradas }
});
defineOptions({ layout: AppLayout });

const estado = reactive({ data: props.data });
const hoje = hojeIso();

let debounceId = null;
watch(() => estado.data, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/professor/horario', valor ? { data: valor } : {}, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">O meu horário</h1>
            <div class="d-flex align-items-center gap-2">
                <label class="fw-semibold fs-7 text-muted mb-0" for="meu-horario-data">Em vigor a</label>
                <input id="meu-horario-data" v-model="estado.data" type="date" class="form-control form-control-solid form-control-sm w-150px" />
            </div>
        </div>

        <div v-if="!grelha.entradas.length" class="card">
            <div class="card-body">
                <EstadoVazio titulo="Sem aulas no horário" descricao="Quando a secretaria montar o horário das suas turmas, as aulas aparecem aqui." />
            </div>
        </div>

        <GrelhaSemanal v-else :tempos="grelha.tempos" :entradas="grelha.entradas" mostrar-turma :hoje="hoje" />
    </div>
</template>
