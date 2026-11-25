<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ESTADO_AULA } from '../Support/frequencia';

/** Uma aula do dia do professor: tempo, turma, disciplina e o estado da chamada, com a acção de a fazer ou ver. */
const props = defineProps({
    aula: { type: Object, required: true },
    futura: { type: Boolean, default: false }, // dia depois de hoje: ainda não se faz a chamada
});

const estado = computed(() => ESTADO_AULA[props.aula.estado]);
const tempo = computed(() => (props.aula.horario_nome ? `${props.aula.horario_nome} · ${props.aula.horario_inicio}–${props.aula.horario_fim}` : 'Chamada do dia'));
const href = computed(() => {
    const q = new URLSearchParams({ turma_id: props.aula.turma_id, data: props.aula.data });
    if (props.aula.horario_id) q.set('horario_id', props.aula.horario_id);
    return `/frequencia/chamada?${q.toString()}`;
});
</script>

<template>
    <div class="card mb-4">
        <div class="card-body py-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-muted fs-8 fw-semibold text-uppercase">{{ tempo }}</div>
                <div class="fw-bold fs-5">{{ aula.disciplina_nome ?? aula.turma_rotulo }}</div>
                <div v-if="aula.disciplina_nome" class="text-gray-700 fs-7">{{ aula.turma_rotulo }}</div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge" :class="estado.classe">{{ estado.texto }}</span>
                <span v-if="futura" class="text-muted fs-8">Ainda não é possível</span>
                <Link v-else :href="href" class="btn btn-sm" :class="aula.estado === 'pendente' ? 'btn-success' : 'btn-light-primary'">
                    {{ aula.estado === 'pendente' ? 'Fazer chamada' : 'Ver chamada' }}
                </Link>
            </div>
        </div>
    </div>
</template>
