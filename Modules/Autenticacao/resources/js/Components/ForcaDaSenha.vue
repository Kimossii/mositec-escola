<script setup>
import { computed } from 'vue';

/** Barra de força e lista de requisitos da nova senha. A validação final é sempre a do servidor. */
const props = defineProps({
    senha: { type: String, default: '' },
    confirmacao: { type: String, default: null }, // se dada, mostra se coincide
});

const requisitos = computed(() => [
    { texto: 'Pelo menos 8 caracteres', ok: props.senha.length >= 8 },
    { texto: 'Letras maiúsculas e minúsculas', ok: /[a-z]/.test(props.senha) && /[A-Z]/.test(props.senha) },
    { texto: 'Pelo menos um número', ok: /\d/.test(props.senha) },
    { texto: 'Pelo menos um símbolo (ex.: ! # $ %)', ok: /[^A-Za-z0-9]/.test(props.senha) },
]);

const pontos = computed(() => (props.senha ? requisitos.value.filter((r) => r.ok).length : 0));
const nivel = computed(() => [
    { texto: '', cor: 'gray-200' },
    { texto: 'Fraca', cor: 'danger' },
    { texto: 'Razoável', cor: 'warning' },
    { texto: 'Boa', cor: 'info' },
    { texto: 'Forte', cor: 'success' },
][pontos.value]);
</script>

<template>
    <div class="mb-7">
        <div class="d-flex gap-2 mb-2">
            <span v-for="n in 4" :key="n" class="flex-grow-1 rounded h-5px" :class="n <= pontos ? `bg-${nivel.cor}` : 'bg-gray-200'"></span>
        </div>
        <div class="d-flex justify-content-between fs-7 mb-3">
            <span class="text-muted">Força da senha</span>
            <span v-if="nivel.texto" class="fw-bold" :class="`text-${nivel.cor}`">{{ nivel.texto }}</span>
        </div>
        <div class="d-flex flex-column gap-1">
            <div v-for="r in requisitos" :key="r.texto" class="d-flex align-items-center fs-7" :class="r.ok ? 'text-success' : 'text-muted'">
                <i class="ki-duotone fs-5 me-2" :class="r.ok ? 'ki-check-circle' : 'ki-cross-circle'"><span class="path1"></span><span class="path2"></span></i>{{ r.texto }}
            </div>
            <div v-if="confirmacao !== null && confirmacao !== ''" class="d-flex align-items-center fs-7" :class="confirmacao === senha ? 'text-success' : 'text-danger'">
                <i class="ki-duotone fs-5 me-2" :class="confirmacao === senha ? 'ki-check-circle' : 'ki-cross-circle'"><span class="path1"></span><span class="path2"></span></i>
                {{ confirmacao === senha ? 'As senhas coincidem' : 'As senhas não coincidem' }}
            </div>
        </div>
    </div>
</template>
