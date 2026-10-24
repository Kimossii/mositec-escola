<script setup>
import { ref } from 'vue';

/**
 * Barra de pesquisa sempre à vista + botão «Filtros» que recolhe os filtros avançados (o conteúdo do slot, em colunas).
 * Abre sozinha se houver filtros activos; mostra quantos e um botão para os limpar.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Pesquisar…' },
    ativos: { type: Number, default: 0 },
});
const emit = defineEmits(['update:modelValue', 'limpar']);

const aberto = ref(props.ativos > 0);
</script>

<template>
    <div class="card mb-6">
        <div class="card-body py-4">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <input
                    :value="modelValue" type="text" class="form-control form-control-solid flex-grow-1 mw-md-450px" :placeholder="placeholder"
                    @input="emit('update:modelValue', $event.target.value)"
                />
                <button v-if="$slots.default" type="button" class="btn btn-light" :aria-expanded="aberto" @click="aberto = !aberto">
                    Filtros<span v-if="ativos" class="badge badge-circle badge-primary ms-2">{{ ativos }}</span>
                    <span class="ms-1">{{ aberto ? '▴' : '▾' }}</span>
                </button>
                <button v-if="ativos" type="button" class="btn btn-light-danger" @click="emit('limpar')">Limpar filtros</button>
            </div>
            <div v-if="$slots.default" v-show="aberto" class="row g-4 mt-1"><slot /></div>
        </div>
    </div>
</template>
