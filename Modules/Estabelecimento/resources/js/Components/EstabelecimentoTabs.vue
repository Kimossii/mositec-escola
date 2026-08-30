<script setup>
defineProps({
    atual: { type: String, required: true }, // 'dados' | 'aparencia'
});
</script>

<template>
    <ul class="nav nav-line-tabs nav-line-tabs-2x fs-6 mb-8">
        <li class="nav-item">
            <a class="nav-link" :class="{ active: atual === 'dados' }" href="/estabelecimento">Dados da escola</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" :class="{ active: atual === 'aparencia' }" href="/estabelecimento/aparencia">Logótipo &amp; aparência</a>
        </li>
    </ul>
</template>
