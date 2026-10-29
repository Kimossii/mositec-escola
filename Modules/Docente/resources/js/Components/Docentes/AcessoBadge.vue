<script setup>
/** Se o docente já tem conta de utilizador (pode entrar no sistema, p. ex. para lançar notas). */
defineProps({
    temAcesso: { type: Boolean, required: true },
});
</script>

<template>
    <span class="badge fw-bold" :class="temAcesso ? 'badge-light-primary' : 'badge-light-warning'">
        {{ temAcesso ? 'Com acesso' : 'Sem acesso' }}
    </span>
</template>
