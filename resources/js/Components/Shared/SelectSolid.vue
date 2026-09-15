<script setup>
// Substitui o <select> nativo — o navegador nunca deixa estilizar a lista de
// opções dele. Reaproveita o visual do .form-select-solid no botão fechado e
// o dropdown do Bootstrap (já carregado no projeto) pra lista aberta, que aí
// sim é 100% estilizável.
import { computed, ref } from 'vue';

const modelValue = defineModel({ default: '' });

const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    placeholder: { type: String, default: 'Selecione' },
    // Acrescenta uma caixa de pesquisa no topo do dropdown, que filtra as
    // opções pelo `label` — útil quando a lista é longa (ex.: Turmas).
    searchable: { type: Boolean, default: false },
});

const termo = ref('');

// Ignora acentos na comparação (ex.: "informatica" encontra "Informática").
const normalizar = (texto) => texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const opcoesFiltradas = computed(() => {
    if (!props.searchable || !termo.value.trim()) return props.options;

    const alvo = normalizar(termo.value.trim());

    return props.options.filter((o) => normalizar(o.label).includes(alvo));
});

const rotuloSelecionado = computed(() => {
    const opcao = props.options.find((o) => o.value === modelValue.value);
    return opcao ? opcao.label : props.placeholder;
});

function selecionar(valor) {
    modelValue.value = valor;
    termo.value = '';
}
</script>

<template>
    <div class="dropdown">
        <button type="button" class="form-select form-select-solid text-start" data-bs-toggle="dropdown" aria-expanded="false">
            {{ rotuloSelecionado }}
        </button>
        <ul class="dropdown-menu w-100 p-0" style="max-height: 300px; overflow-y: auto;">
            <li v-if="searchable" class="p-2 border-bottom bg-body" style="position: sticky; top: 0;">
                <input
                    v-model="termo"
                    type="text"
                    class="form-control form-control-sm"
                    placeholder="Pesquisar..."
                    @click.stop
                    @keydown.stop
                />
            </li>
            <li v-for="opcao in opcoesFiltradas" :key="opcao.value">
                <a href="#" class="dropdown-item" :class="{ active: opcao.value === modelValue }"
                    @click.prevent="selecionar(opcao.value)">
                    {{ opcao.label }}
                </a>
            </li>
            <li v-if="searchable && opcoesFiltradas.length === 0" class="px-3 py-2 text-muted fs-7">
                Nenhum resultado encontrado.
            </li>
        </ul>
    </div>
</template>
