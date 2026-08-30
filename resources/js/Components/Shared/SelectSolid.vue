<script setup>
// Substitui o <select> nativo — o navegador nunca deixa estilizar a lista de
// opções dele. Reaproveita o visual do .form-select-solid no botão fechado e
// o dropdown do Bootstrap (já carregado no projeto) pra lista aberta, que aí
// sim é 100% estilizável.
import { computed } from 'vue';

const modelValue = defineModel({ default: '' });

const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    placeholder: { type: String, default: 'Selecione' },
});

const rotuloSelecionado = computed(() => {
    const opcao = props.options.find((o) => o.value === modelValue.value);
    return opcao ? opcao.label : props.placeholder;
});
</script>

<template>
    <div class="dropdown">
        <button type="button" class="form-select form-select-solid text-start" data-bs-toggle="dropdown" aria-expanded="false">
            {{ rotuloSelecionado }}
        </button>
        <ul class="dropdown-menu w-100">
            <li v-for="opcao in options" :key="opcao.value">
                <a href="#" class="dropdown-item" :class="{ active: opcao.value === modelValue }"
                    @click.prevent="modelValue = opcao.value">
                    {{ opcao.label }}
                </a>
            </li>
        </ul>
    </div>
</template>
