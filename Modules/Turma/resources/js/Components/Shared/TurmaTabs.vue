<script setup>
defineProps({
    atual: { type: String, required: true }, // 'turmas' | 'niveis-academicos' | 'turnos'
});
</script>

<template>
    <ul class="nav nav-line-tabs nav-line-tabs-2x fs-6 mb-8">
        <li class="nav-item">
            <a class="nav-link" :class="{ active: atual === 'turmas' }" href="/turmas">Turmas</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" :class="{ active: atual === 'niveis-academicos' }" href="/niveis-academicos">Níveis Académicos</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" :class="{ active: atual === 'turnos' }" href="/turnos">Turnos</a>
        </li>
    </ul>
</template>
