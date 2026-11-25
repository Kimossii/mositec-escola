<script setup>
import { computed } from 'vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { formatarDiaCurto } from '../Support/grelha';

/** Uma aula dentro da célula da grelha: disciplina, docente (ou «Sem docente»), sala e, se aplicável, a vigência. */
const props = defineProps({
    entrada: { type: Object, required: true },
    mostrarTurma: { type: Boolean, default: false },
    editavel: { type: Boolean, default: false },
    hoje: { type: String, required: true }, // AAAA-MM-DD
});
const emit = defineEmits(['editar', 'encerrar', 'eliminar']);

const agendada = computed(() => props.entrada.vigencia_inicio > props.hoje);
const terminaEm = computed(() => props.entrada.vigencia_fim);
// Só se elimina o que ainda não começou; o resto encerra-se (o servidor impõe a mesma regra).
// Só a versão em vigor (sem fim) se altera; as que já têm fim foram alteradas ou encerradas.
const podeEditar = computed(() => !props.entrada.vigencia_fim && can('grelha-horaria.editar'));
const podeEliminar = computed(() => agendada.value && can('grelha-horaria.eliminar'));
const podeEncerrar = computed(() => !agendada.value && can('grelha-horaria.encerrar'));
</script>

<template>
    <div class="rounded bg-body-secondary p-2 mb-1 position-relative">
        <div class="fw-bold fs-7 lh-sm">{{ entrada.disciplina_nome }}</div>
        <div v-if="mostrarTurma" class="fs-8 text-gray-700">{{ entrada.turma_rotulo }}</div>
        <div class="fs-8" :class="entrada.sem_docente ? 'text-danger fw-semibold' : 'text-muted'">
            {{ entrada.docente?.nome ?? 'Sem docente' }}
        </div>
        <div v-if="entrada.sala_nome" class="fs-8 text-muted">{{ entrada.sala_nome }}</div>
        <div v-if="agendada || terminaEm" class="mt-1">
            <span v-if="agendada" class="badge badge-light-info me-1">a partir de {{ formatarDiaCurto(entrada.vigencia_inicio) }}</span>
            <span v-if="terminaEm" class="badge badge-light-warning">até {{ formatarDiaCurto(terminaEm) }}</span>
        </div>
        <div v-if="editavel" class="d-flex gap-1 mt-1">
            <button v-if="podeEditar" type="button" class="btn btn-icon btn-sm btn-light w-25px h-25px" title="Alterar (nova versão)" @click="emit('editar', entrada)">
                <AcaoIcone acao="editar" tamanho="fs-6" />
            </button>
            <button v-if="podeEncerrar" type="button" class="btn btn-icon btn-sm btn-light w-25px h-25px" title="Encerrar" @click="emit('encerrar', entrada)">
                <AcaoIcone acao="encerrar" tamanho="fs-6" />
            </button>
            <button v-if="podeEliminar" type="button" class="btn btn-icon btn-sm btn-light w-25px h-25px" title="Eliminar" @click="emit('eliminar', entrada)">
                <AcaoIcone acao="eliminar" tamanho="fs-6" />
            </button>
        </div>
    </div>
</template>
