<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    titulo: { type: String, required: true },
    itens: { type: Array, required: true }, // [{ id, nome }]
    modelValue: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const pesquisa = ref('');
const visiveis = computed(() => {
    const termo = pesquisa.value.trim().toLowerCase();

    return termo === '' ? props.itens : props.itens.filter((i) => i.nome.toLowerCase().includes(termo));
});

const marcado = (id) => props.modelValue.includes(id);
function alternar(id) {
    emit('update:modelValue', marcado(id) ? props.modelValue.filter((x) => x !== id) : [...props.modelValue, id]);
}
const marcarTodos = () => emit('update:modelValue', [...new Set([...props.modelValue, ...visiveis.value.map((i) => i.id)])]);
const limpar = () => emit('update:modelValue', []);
</script>

<template>
    <div class="lista-alvos border rounded">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <div class="fw-semibold">
                {{ titulo }}
                <span class="badge ms-1" :class="modelValue.length ? 'badge-light-success' : 'badge-light'">{{ modelValue.length ? `${modelValue.length} de ${itens.length}` : 'Todos' }}</span>
            </div>
            <div class="fs-7">
                <a href="#" class="me-3" @click.prevent="marcarTodos">Marcar todos</a>
                <a href="#" @click.prevent="limpar">Limpar</a>
            </div>
        </div>
        <div v-if="itens.length > 8" class="px-3 pt-2">
            <input v-model="pesquisa" type="search" class="form-control form-control-sm form-control-solid" :placeholder="`Pesquisar ${titulo.toLowerCase()}…`" />
        </div>
        <div class="lista-alvos-corpo px-3 py-2">
            <div v-if="visiveis.length === 0" class="text-muted fs-7 py-2">Nenhum resultado.</div>
            <label v-for="i in visiveis" :key="i.id" class="form-check form-check-custom d-flex align-items-center gap-2 mb-2">
                <input type="checkbox" class="form-check-input caixa" :checked="marcado(i.id)" @change="alternar(i.id)" />
                <span class="form-check-label">{{ i.nome }}</span>
            </label>
        </div>
    </div>
</template>

<style scoped>
.lista-alvos-corpo { max-height: 190px; overflow-y: auto; columns: 2; column-gap: 1rem; }
.lista-alvos-corpo label { break-inside: avoid; }
.caixa { border: 2px solid #64748B !important; background-color: transparent !important; }
.caixa:checked { background-color: #166534 !important; border-color: #166534 !important; }
</style>
