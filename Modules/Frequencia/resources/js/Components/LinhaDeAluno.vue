<script setup>
import { can } from '@/Composables/usePermissoes';
import { ESTADOS, PRESENCA } from '../Support/frequencia';

/** Uma linha da chamada: o aluno, os três estados e a observação quando não está presente. */
const props = defineProps({
    registo: { type: Object, required: true }, // o estado guardado (para justificar)
    modelValue: { type: Object, required: true }, // { estado, observacao } em edição
    editavel: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'justificar', 'retirar']);

// Presente não tem observação: ao voltar a presente, apaga-se.
const mudar = (campo, valor) => emit('update:modelValue', {
    ...props.modelValue,
    [campo]: valor,
    ...(campo === 'estado' && valor === PRESENCA.PRESENTE ? { observacao: null } : {}),
});
// Só se justifica o que já está guardado como falta.
const podeJustificar = () => can('frequencia.justificar') && props.registo.estado === PRESENCA.FALTA && props.modelValue.estado === PRESENCA.FALTA;
</script>

<template>
    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 py-3 border-bottom border-gray-200">
        <div class="flex-grow-1 fw-semibold fs-6">{{ registo.nome }}</div>
        <div class="d-flex gap-2">
            <button
                v-for="e in ESTADOS"
                :key="e.valor"
                type="button"
                class="btn btn-sm flex-fill"
                :class="modelValue.estado === e.valor ? e.activo : e.classe"
                :disabled="!editavel"
                :aria-pressed="modelValue.estado === e.valor"
                @click="mudar('estado', e.valor)"
            >{{ e.texto }}</button>
        </div>
        <div class="w-md-250px">
            <input
                v-if="modelValue.estado !== PRESENCA.PRESENTE"
                :value="modelValue.observacao ?? ''"
                type="text"
                maxlength="500"
                class="form-control form-control-solid form-control-sm"
                placeholder="Observação (opcional)"
                :disabled="!editavel"
                @input="mudar('observacao', $event.target.value)"
            />
        </div>
        <div class="min-w-125px text-md-end">
            <template v-if="registo.justificada">
                <span class="badge badge-light-success me-2" :title="registo.motivo_justificacao">Justificada</span>
                <button v-if="can('frequencia.justificar')" type="button" class="btn btn-sm btn-link text-danger p-0" @click="emit('retirar', registo)">Retirar</button>
            </template>
            <button v-else-if="podeJustificar()" type="button" class="btn btn-sm btn-light-primary" @click="emit('justificar', registo)">Justificar</button>
        </div>
    </div>
</template>
