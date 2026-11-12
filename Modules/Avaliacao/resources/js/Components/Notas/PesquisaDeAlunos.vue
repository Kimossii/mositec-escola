<script setup>
// Campo de pesquisa de alunos para a mini-pauta e a pauta final.
const termo = defineModel({ type: String, default: '' });
defineProps({
    total: { type: Number, default: 0 },
    visiveis: { type: Number, default: 0 },
});
</script>

<template>
    <div class="d-flex align-items-center gap-3 d-print-none">
        <div class="position-relative">
            <input v-model="termo" type="search" class="form-control form-control-sm w-250px" placeholder="Procurar aluno ou n.º de matrícula" aria-label="Procurar aluno">
        </div>
        <span v-if="termo.trim() !== ''" class="fs-7 text-body-secondary">{{ visiveis }} de {{ total }} alunos</span>
    </div>
</template>
