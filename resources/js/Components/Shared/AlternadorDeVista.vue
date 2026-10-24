<script setup>
/** Alternador Lista ⇄ Cards (segmentado), usado por todas as listas com duas vistas. */
defineProps({ modelValue: { type: String, required: true } });
defineEmits(['update:modelValue']);

const OPCOES = [
    { valor: 'lista', texto: 'Lista', icone: 'ki-row-horizontal', paths: 2 },
    { valor: 'cards', texto: 'Cards', icone: 'ki-element-11', paths: 4 },
];
</script>

<template>
    <div class="d-inline-flex p-1 rounded bg-body-secondary" role="group" aria-label="Vista da lista">
        <button
            v-for="o in OPCOES"
            :key="o.valor"
            type="button"
            class="btn btn-sm d-flex align-items-center px-4 border-0"
            :class="modelValue === o.valor ? 'btn-success shadow-sm' : 'btn-transparent text-gray-600'"
            :aria-pressed="modelValue === o.valor"
            @click="$emit('update:modelValue', o.valor)"
        >
            <i class="ki-duotone fs-4 me-2" :class="o.icone"><span v-for="n in o.paths" :key="n" :class="`path${n}`"></span></i>{{ o.texto }}
        </button>
    </div>
</template>
