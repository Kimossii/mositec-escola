<script setup>
defineProps({
    usuario: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
        <a href="#">
            <div v-if="usuario.avatar" class="symbol-label">
                <img :src="usuario.avatar" :alt="usuario.name" class="w-100" />
            </div>
            <div v-else class="symbol-label fs-3" :class="`bg-light-${usuario.avatarColor} text-${usuario.avatarColor}`">
                {{ usuario.name.charAt(0) }}
            </div>
        </a>
    </div>
</template>
